<?php
/**
 * Fluent Forms entry PDF attachment helper for Taxnexcy.
 *
 * - Captures the last Fluent Forms submission (form_id + entry_id) in WC session
 * - On order creation, generates a PDF using the Fluent Forms PDF add-on
 * - Saves the PDF to uploads/taxnexcy-pdfs and attaches it to selected WC emails
 * - Adds an admin meta box with a download link
 * - Verbose logging so failures are obvious
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'is_wp_error' ) ) {
    function is_wp_error( $thing ) {
        return $thing instanceof WP_Error;
    }
}

final class Taxnexcy_FF_PDF_Attach {

    const VER                 = '1.1.14';
    const SESSION_KEY         = 'taxnexcy_ff_entry_map';
    const ORDER_META_PDF_PATH = '_ff_entry_pdf';
    const LOG_FILE            = 'taxnexcy-ffpdf.log';
    const TARGET_FORM_ID      = 4;
    const PDF_FEED_ID        = 56; // Your Fluent Forms PDF feed id named "General"

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'maybe_boot' ], 20 );
    }

    public function maybe_boot() {
        $this->log( 'Booting FF PDF helper' );

        // Don’t hard fail; just log and skip if requirements aren’t met.
        if ( ! class_exists( 'WooCommerce' ) ) {
            $this->log( 'WooCommerce missing; aborting boot' );
            return;
        }
        if ( ! class_exists( '\FluentForm\App\Services\Submission\SubmissionService' ) ) {
            $this->log( 'Fluent Forms core missing; aborting boot' );
            return;
        }

        // Capture the last submission details in the WC session (belt & suspenders;
        // your FluentForms integration already does this too).
        add_action( 'fluentform_submission_inserted', [ $this, 'capture_ff_entry_in_session' ], 20, 3 );

        // When the order is created, create/gather the PDF
        add_action( 'woocommerce_checkout_create_order', [ $this, 'maybe_generate_pdf_on_order' ], 20, 2 );

        // Attach PDF to selected WooCommerce emails
        add_filter( 'woocommerce_email_attachments', [ $this, 'attach_pdf_to_emails' ], 20, 3 );

        // Show a download link in admin
        add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'admin_order_pdf_meta_box' ], 30 );

        $this->log( 'FF PDF helper booted' );
    }

    /* -----------------------------------------
     * 1) CAPTURE ENTRY IN WC SESSION
     * --------------------------------------- */

    /**
     * Save the last FF submission (form + entry) into WC session for later use.
     *
     * @param int   $entryId
     * @param array $formData
     * @param array $form
     */
    public function capture_ff_entry_in_session( $entryId, $formData, $form ) {
        try {
            if ( empty( $entryId ) || empty( $form['id'] ) ) {
                return;
            }

            $map = WC()->session->get( self::SESSION_KEY, [] );
            if ( ! is_array( $map ) ) {
                $map = [];
            }

            // Use the form id as the key
            $map[ (int) $form['id'] ] = (int) $entryId;

            WC()->session->set( self::SESSION_KEY, $map );

            $this->log( sprintf(
                'Captured submission in session => form_id=%d, entry_id=%d',
                (int) $form['id'],
                (int) $entryId
            ) );
        } catch ( \Throwable $e ) {
            $this->log( 'Error saving FF entry to session: ' . $e->getMessage() );
        }
    }

    /* -----------------------------------------
     * 2) GENERATE PDF AT ORDER TIME
     * --------------------------------------- */

    /**
     * Maybe generate the PDF and save its path on the order.
     *
     * @param WC_Order $order
     * @param array    $data
     */
    public function maybe_generate_pdf_on_order( $order, $data ) {
        try {
            $order_id = $order ? $order->get_id() : 0;
            $this->log( 'maybe_generate_pdf_on_order for order #' . $order_id );

            $entry_id = $this->get_entry_id_for_form( self::TARGET_FORM_ID );
            if ( ! $entry_id ) {
                $this->log( 'No entry found in session for form_id=' . self::TARGET_FORM_ID );
                return;
            }

            // Generate the PDF using Fluent Forms PDF add-on
            $pdf_path = $this->generate_pdf_for_entry( self::TARGET_FORM_ID, $entry_id, self::PDF_FEED_ID );
            if ( ! $pdf_path || ! file_exists( $pdf_path ) ) {
                $this->log( 'PDF generation failed or file missing: ' . ( $pdf_path ?: '(empty path)' ) );
                return;
            }

            // Save to order meta
            $order->update_meta_data( self::ORDER_META_PDF_PATH, $pdf_path );
            $order->save();

            $this->log( 'PDF generated and saved to order: ' . $pdf_path );
        } catch ( \Throwable $e ) {
            $this->log( 'Error in maybe_generate_pdf_on_order: ' . $e->getMessage() );
        }
    }

    /**
     * Get entry id from WC session for a given form id.
     *
     * @param int $form_id
     * @return int|false
     */
    private function get_entry_id_for_form( $form_id ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return false;
        }
        $map = WC()->session->get( self::SESSION_KEY, [] );
        if ( is_array( $map ) && ! empty( $map[ (int) $form_id ] ) ) {
            return (int) $map[ (int) $form_id ];
        }
        return false;
    }

    /**
     * Generate the PDF using Fluent Forms PDF Feed.
     *
     * @param int $form_id
     * @param int $entry_id
     * @param int $feed_id
     * @return string|false Absolute path to generated PDF
     */
    private function generate_pdf_for_entry( $form_id, $entry_id, $feed_id ) {
        try {
            if ( ! class_exists( '\FluentFormPro\Pdf' ) && ! class_exists( '\FluentFormPro\Pdf\Pdf' ) ) {
                $this->log( 'PDF addon class not found' );
                return false;
            }

            if ( ! function_exists( 'wp_upload_dir' ) ) {
                $this->log( 'wp_upload_dir() not available' );
                return false;
            }

            $upload_dir = wp_upload_dir();
            $pdf_dir    = trailingslashit( $upload_dir['basedir'] ) . 'taxnexcy-pdfs';
            if ( ! file_exists( $pdf_dir ) ) {
                wp_mkdir_p( $pdf_dir );
            }

            // Build filename
            $filename = sprintf( 'ff_form_%d_entry_%d.pdf', $form_id, $entry_id );
            $dest     = trailingslashit( $pdf_dir ) . $filename;

            // Generate via PDF feed manager if available
            if ( class_exists( '\FluentFormPro\Pdf\Classes\PdfBuilder' ) ) {
                $this->log( "Attempting PdfBuilder for feed_id={$feed_id}" );

                // Newer API
                $builder = new \FluentFormPro\Pdf\Classes\PdfBuilder( $form_id, $entry_id, $feed_id );
                $pdf     = $builder->generate();
                if ( is_array( $pdf ) && ! empty( $pdf['path'] ) && file_exists( $pdf['path'] ) ) {
                    // Copy to our folder
                    copy( $pdf['path'], $dest );
                    $this->log( 'PdfBuilder generated to: ' . $pdf['path'] );
                    return $dest;
                }
                $this->log( 'PdfBuilder: response unexpected: ' . print_r( $pdf, true ) );
            }

            // Fallback: if template manager exists, try to render manually
            if ( class_exists( '\FluentFormPro\Pdf\Classes\TemplateManager' ) ) {
                $this->log( "Attempting TemplateManager fallback for feed_id={$feed_id}" );
                $tm = new \FluentFormPro\Pdf\Classes\TemplateManager( $form_id, $entry_id, $feed_id );
                $pdf = $tm->generate();
                if ( is_array( $pdf ) && ! empty( $pdf['path'] ) && file_exists( $pdf['path'] ) ) {
                    copy( $pdf['path'], $dest );
                    $this->log( 'TemplateManager generated to: ' . $pdf['path'] );
                    return $dest;
                }
                $this->log( 'TemplateManager: response unexpected: ' . print_r( $pdf, true ) );
            }

            $this->log( 'No compatible PDF generator found' );
            return false;
        } catch ( \Throwable $e ) {
            $this->log( 'Exception in generate_pdf_for_entry: ' . $e->getMessage() );
            return false;
        }
    }

    /* -----------------------------------------
     * 3) ATTACH TO EMAILS + ADMIN UI
     * --------------------------------------- */

    /**
     * Attach generated PDF to selected WooCommerce emails.
     */
    public function attach_pdf_to_emails( $attachments, $email_id, $order ) {
        try {
            if ( ! $order instanceof \WC_Order ) {
                return $attachments;
            }
            $path = $order->get_meta( self::ORDER_META_PDF_PATH );
            if ( ! $path || ! file_exists( $path ) ) {
                return $attachments;
            }

            // Attach to processing + completed
            $target_emails = [
                'customer_processing_order',
                'customer_completed_order',
                // add more ids if you want
            ];

            if ( in_array( $email_id, $target_emails, true ) ) {
                $attachments[] = $path;
                $this->log( "Attached PDF to {$email_id}: {$path}" );
            }

            return $attachments;
        } catch ( \Throwable $e ) {
            $this->log( 'attach_pdf_to_emails error: ' . $e->getMessage() );
            return $attachments;
        }
    }

    /**
     * Simple download link in admin order screen.
     */
    public function admin_order_pdf_meta_box( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $path = $order->get_meta( self::ORDER_META_PDF_PATH );
        if ( ! $path || ! file_exists( $path ) ) {
            return;
        }
        $url = $this->file_path_to_url( $path );
        if ( ! $url ) {
            return;
        }
        echo '<p style="margin-top:12px;"><strong>Form PDF:</strong> ';
        echo '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Download</a>';
        echo '</p>';
    }

    private function file_path_to_url( $path ) {
        $uploads = wp_get_upload_dir();
        if ( false === strpos( $path, $uploads['basedir'] ) ) {
            return false;
        }
        $rel = str_replace( $uploads['basedir'], '', $path );
        return $uploads['baseurl'] . $rel;
    }

    /* -----------------------------------------
     * 4) LOGGING
     * --------------------------------------- */

    private function log( $message, $context = [] ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $prefix = '[Taxnexcy PDF] ';
            if ( ! empty( $context ) ) {
                $message .= ' | ' . wp_json_encode( $context );
            }
            error_log( $prefix . $message );
        }

        // File log (rotates at ~5MB)
        $upload_dir = wp_upload_dir();
        $log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'taxnexcy-logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }
        $file = trailingslashit( $log_dir ) . self::LOG_FILE;

        // rotate
        if ( file_exists( $file ) && filesize( $file ) > 5 * 1024 * 1024 ) {
            @rename( $file, $file . '.' . time() . '.bak' );
        }

        $line = '[' . date( 'Y-m-d H:i:s' ) . '] ' . $message . PHP_EOL;
        @file_put_contents( $file, $line, FILE_APPEND );
    }
}

// Boot
new Taxnexcy_FF_PDF_Attach();

/* ----------------------------------------------------------------
 * PDF HTML REPLACERS (critical for {all_data} dynamic text)
 * ----------------------------------------------------------------
 */

// Ensure PDF body/header/footer are treated as HTML (so smartcodes render)
add_filter('fluentform/pdf_html_format', function($parts){
    if (!is_array($parts)) { $parts = array(); }
    foreach (array('header','body','footer') as $p) {
        if (!in_array($p, $parts, true)) { $parts[] = $p; }
    }
    return $parts;
}, 10, 1);

// Replace {dynamic.*}, {inputs.*}, and {user.*} placeholders anywhere in the final PDF HTML,
// including when the braces are HTML-encoded as &#123; ... &#125; or &lcub; ... &rcub; (which happens inside {all_data} labels).
add_filter('fluentform/pdf_html_output', function($html, $form, $entry) {

    // Collect responses as array
    $responses = array();
    if (isset($entry->response)) {
        $responses = is_array($entry->response) ? $entry->response : json_decode($entry->response, true);
    }
    if (!is_array($responses)) { $responses = array(); }

    // Helper to fetch a value from responses or user meta
    $lookup = function($key) use ($responses, $entry) {
        $val = '';

        // direct match (e.g., tax_year, input_radio, first_name, etc.)
        if (isset($responses[$key])) {
            $val = $responses[$key];
        } elseif ($key === 'first_name' && isset($responses['names']['first_name'])) {
            // compound "Name" field support
            $val = $responses['names']['first_name'];
        } elseif ($key === 'last_name' && isset($responses['names']['last_name'])) {
            $val = $responses['names']['last_name'];
        }

        // Fallback to WP user meta for user.first_name / user.last_name
        if (($key === 'first_name' || $key === 'last_name') && empty($val) && !empty($entry->user_id)) {
            $meta = get_user_meta($entry->user_id, $key, true);
            if ($meta) { $val = $meta; }
        }

        // Flatten arrays (checkboxes, etc.)
        if (is_array($val)) {
            $flat = array();
            $it = new RecursiveIteratorIterator(new RecursiveArrayIterator($val));
            foreach ($it as $v) {
                if ($v !== '' && $v !== null) { $flat[] = $v; }
            }
            $val = implode(', ', array_unique(array_map('strval', $flat)));
        }

        return is_scalar($val) ? (string) $val : '';
    };

    // Pass A: real-brace placeholders {dynamic.key}/{inputs.key}/{user.key}
    $html = preg_replace_callback(
        '/\{(?:dynamic|inputs|user)\.([a-zA-Z0-9_\.]+)\}/',
        function($m) use ($lookup){
            $key = $m[1];
            $value = $lookup($key);
            return $value !== '' ? esc_html($value) : $m[0]; // leave placeholder if no value
        },
        $html
    );

    // Pass B: HTML-encoded braces &#123;dynamic.key&#125; or &lcub;dynamic.key&rcub; (common inside {all_data} tables)
    $html = preg_replace_callback(
        '/(?:&#123;|&lcub;)(?:dynamic|inputs|user)\.([a-zA-Z0-9_\.]+)(?:&#125;|&rcub;)/',
        function($m) use ($lookup){
            $key = $m[1];
            $value = $lookup($key);
            return $value !== '' ? esc_html($value) : $m[0];
        },
        $html
    );

    return $html;
}, 9999, 3);
