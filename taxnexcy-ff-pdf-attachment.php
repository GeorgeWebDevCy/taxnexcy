<?php
/**
 * Fluent Forms entry PDF attachment helper for Taxnexcy.
 *
 * Captures the most recent Fluent Forms submission, generates a PDF via the
 * Fluent Forms PDF add-on, stores it on the WooCommerce order and attaches it
 * to selected emails. Also provides an admin download link and basic logging.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! class_exists( 'Taxnexcy_FF_PDF_Attach' ) ) :

final class Taxnexcy_FF_PDF_Attach {

    const VER                 = '1.0.0';
    const SESSION_KEY         = 'taxnexcy_ff_entry_map';
    const ORDER_META_PDF_PATH = '_ff_entry_pdf';
    const LOG_FILE            = 'taxnexcy-ffpdf.log';

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'maybe_boot' ], 20 );
    }

    public function maybe_boot() {
        // Basic dependency hints (don’t hard-fail).
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-warning"><p><strong>Taxnexcy FF PDF:</strong> WooCommerce is required.</p></div>';
            } );
            return;
        }

        if ( ! defined( 'FLUENTFORM' ) ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-warning"><p><strong>Taxnexcy FF PDF:</strong> Fluent Forms is required.</p></div>';
            } );
        }

        // Capture the last inserted FF submission into the Woo session
        add_action( 'fluentform_submission_inserted', [ $this, 'capture_latest_ff_entry' ], 10, 3 );

        // On order creation, generate & save the PDF
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_order_created_generate_pdf' ], 20, 3 );

        // Attach the PDF to selected Woo emails
        add_filter( 'woocommerce_email_attachments', [ $this, 'attach_pdf_to_emails' ], 10, 3 );

        // Order admin UI: small box with a download link
        add_action( 'add_meta_boxes_shop_order', [ $this, 'add_order_pdf_metabox' ] );
        add_action( 'admin_post_taxnexcy_download_ff_pdf', [ $this, 'handle_admin_pdf_download' ] );
    }

    /* ---------------------------------------------
     * 1) CAPTURE LATEST FLUENT FORMS ENTRY (SESSION)
     * ------------------------------------------- */

    /**
     * Save {form_id, entry_id} to Woo session when a FF submission is inserted.
     *
     * @param int   $entry_id
     * @param array $form_data
     * @param object $form
     */
    public function capture_latest_ff_entry( $entry_id, $form_data, $form ) {
        if ( function_exists( 'WC' ) && WC()->session ) {
            $map = [
                'form_id'  => (int) ( $form->id ?? 0 ),
                'entry_id' => (int) $entry_id,
            ];
            WC()->session->set( self::SESSION_KEY, $map );
            $this->log( 'Captured FF entry in session', $map );
        }
    }

    /* ------------------------------------------------------
     * 2) GENERATE PDF WHEN ORDER IS CREATED (SAVE TO ORDER)
     * ---------------------------------------------------- */

    /**
     * On order creation, generate PDF for the captured FF entry and save path in order meta.
     */
    public function on_order_created_generate_pdf( $order_id, $posted_data, $order ) {
        $map = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::SESSION_KEY ) : null;

        if ( empty( $map['form_id'] ) || empty( $map['entry_id'] ) ) {
            $this->log( 'No FF map found in session; skipping PDF generation.' );
            return;
        }

        $form_id  = (int) $map['form_id'];
        $entry_id = (int) $map['entry_id'];

        $pdf_path = $this->create_pdf_for_entry( $form_id, $entry_id, $order_id );
        if ( $pdf_path ) {
            $order->update_meta_data( self::ORDER_META_PDF_PATH, $pdf_path );
            $order->save();

            $order->add_order_note( sprintf(
                'Fluent Forms PDF generated and attached: %s',
                esc_html( basename( $pdf_path ) )
            ) );

            $this->log( 'PDF generated & saved to order', [
                'order_id' => $order_id,
                'pdf_path' => $pdf_path
            ] );
        } else {
            $this->log( 'PDF generation failed', [
                'order_id' => $order_id,
                'form_id'  => $form_id,
                'entry_id' => $entry_id
            ] );
        }

        // Clear the session mapping so it doesn’t leak into next orders.
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( self::SESSION_KEY, null );
        }
    }

    /**
     * Actually ask Fluent Forms PDF add-on to render the entry PDF.
     *
     * @return string|false Absolute file path or false.
     */
    protected function create_pdf_for_entry( $form_id, $entry_id, $order_id ) {

        // Prepare destination folder
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            $this->log( 'wp_upload_dir error', $upload );
            return false;
        }

        $pdf_dir = trailingslashit( $upload['basedir'] ) . 'taxnexcy-pdfs';
        if ( ! wp_mkdir_p( $pdf_dir ) ) {
            $this->log( 'Failed to create pdf_dir', [ 'pdf_dir' => $pdf_dir ] );
            return false;
        }

        $file_name   = sprintf( 'ff-entry-%d-order-%d.pdf', $entry_id, $order_id );
        $destination = trailingslashit( $pdf_dir ) . $file_name;

        // Try GlobalPdfManager first (newer method)
        try {
            if ( class_exists( '\\FluentFormPdf\\Classes\\Controller\\GlobalPdfManager' ) ) {
                $manager  = new \FluentFormPdf\Classes\Controller\GlobalPdfManager();
                // Newer versions accept (entry_id, form_id) or (form_id, entry_id) depending on release.
                // Try both safely:
                $pdf_info = null;

                try {
                    $pdf_info = $manager->getPdf( $entry_id, $form_id );
                } catch ( \ArgumentCountError $e ) {
                    $pdf_info = $manager->getPdf( $form_id, $entry_id );
                } catch ( \Throwable $e ) {
                    // Fall through to TemplateManager
                    $this->log( 'GlobalPdfManager variant A failed', [ 'msg' => $e->getMessage() ] );
                }

                if ( is_array( $pdf_info ) && ! empty( $pdf_info['path'] ) && file_exists( $pdf_info['path'] ) ) {
                    copy( $pdf_info['path'], $destination );
                    return $destination;
                }
            }
        } catch ( \Throwable $e ) {
            $this->log( 'GlobalPdfManager failed', [ 'msg' => $e->getMessage() ] );
        }

        // Fallback: TemplateManager (older add-on)
        try {
            if ( class_exists( '\\FluentFormPdf\\Classes\\Templates\\TemplateManager' ) ) {
                $template = new \FluentFormPdf\Classes\Templates\TemplateManager( $form_id );
                $tmp      = $template->generatePdf( $entry_id ); // returns temporary file
                if ( $tmp && file_exists( $tmp ) ) {
                    copy( $tmp, $destination );
                    return $destination;
                }
            }
        } catch ( \Throwable $e ) {
            $this->log( 'TemplateManager failed', [ 'msg' => $e->getMessage() ] );
        }

        return false;
    }

    /* ---------------------------------------
     * 3) ATTACH TO WOO EMAILS AUTOMATICALLY
     * ------------------------------------- */

    public function attach_pdf_to_emails( $attachments, $email_id, $order ) {
        if ( ! $order instanceof WC_Order ) {
            return $attachments;
        }

        // Control which emails get the attachment here:
        $targets = apply_filters( 'taxnexcy_ff_pdf_email_ids', [
            'new_order',                 // admin
            'customer_processing_order', // "Order received"
            'customer_completed_order',  // "Completed"
            'customer_invoice',          // Invoice
        ] );

        if ( ! in_array( $email_id, $targets, true ) ) {
            return $attachments;
        }

        $pdf = $order->get_meta( self::ORDER_META_PDF_PATH );
        if ( $pdf && file_exists( $pdf ) && is_readable( $pdf ) ) {
            $attachments[] = $pdf;
        } else {
            $this->log( 'No readable PDF to attach', [
                'order_id' => $order->get_id(),
                'email_id' => $email_id,
                'path'     => $pdf
            ] );
        }

        return $attachments;
    }

    /* ---------------------------------------
     * 4) ORDER ADMIN BOX + DOWNLOAD HANDLER
     * ------------------------------------- */

    public function add_order_pdf_metabox() {
        add_meta_box(
            'taxnexcy_ff_pdf_box',
            __( 'Fluent Forms PDF', 'taxnexcy' ),
            [ $this, 'render_pdf_metabox' ],
            'shop_order',
            'side',
            'default'
        );
    }

    public function render_pdf_metabox( $post ) {
        $order = wc_get_order( $post->ID );
        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'taxnexcy' ) . '</p>';
            return;
        }

        $pdf = $order->get_meta( self::ORDER_META_PDF_PATH );
        if ( $pdf && file_exists( $pdf ) ) {
            $url = wp_nonce_url(
                admin_url( 'admin-post.php?action=taxnexcy_download_ff_pdf&order_id=' . $order->get_id() ),
                'taxnexcy_dl_' . $order->get_id()
            );
            echo '<p><code>' . esc_html( basename( $pdf ) ) . '</code></p>';
            echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Download PDF', 'taxnexcy' ) . '</a></p>';
        } else {
            echo '<p>' . esc_html__( 'No PDF found for this order.', 'taxnexcy' ) . '</p>';
        }
    }

    public function handle_admin_pdf_download() {
        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( esc_html__( 'Insufficient permissions.', 'taxnexcy' ) );
        }

        $order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;
        check_admin_referer( 'taxnexcy_dl_' . $order_id );

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_die( esc_html__( 'Order not found.', 'taxnexcy' ) );
        }

        $pdf = $order->get_meta( self::ORDER_META_PDF_PATH );
        if ( ! $pdf || ! file_exists( $pdf ) ) {
            wp_die( esc_html__( 'PDF not found.', 'taxnexcy' ) );
        }

        // Serve the file
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . basename( $pdf ) . '"' );
        header( 'Content-Transfer-Encoding: binary' );
        header( 'Content-Length: ' . filesize( $pdf ) );

        @readfile( $pdf );
        exit;
    }

    /* -------------
     * 5) LOGGING
     * ----------- */

    protected function log( $message, $context = [] ) {
        $enabled = apply_filters( 'taxnexcy_ff_pdf_logging', true );
        if ( ! $enabled ) return;

        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) return;

        $log_dir = trailingslashit( $upload['basedir'] ) . 'taxnexcy-logs';
        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        $file = trailingslashit( $log_dir ) . self::LOG_FILE;

        // Rotate at ~5MB
        if ( file_exists( $file ) && filesize( $file ) > 5 * 1024 * 1024 ) {
            @rename( $file, $file . '.' . time() . '.bak' );
        }

        $line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $message;
        if ( ! empty( $context ) ) {
            $line .= ' ' . wp_json_encode( $context );
        }
        $line .= PHP_EOL;

        @file_put_contents( $file, $line, FILE_APPEND );
    }
}

endif;

new Taxnexcy_FF_PDF_Attach();

