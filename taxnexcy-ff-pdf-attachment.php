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

if ( ! class_exists( 'Taxnexcy_FF_PDF_Attach' ) ) :

final class Taxnexcy_FF_PDF_Attach {

    const VER                 = '1.1.8';
    const SESSION_KEY         = 'taxnexcy_ff_entry_map';
    const ORDER_META_PDF_PATH = '_ff_entry_pdf';
    const LOG_FILE            = 'taxnexcy-ffpdf.log';
    const TARGET_FORM_ID      = 4;

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

        // After checkout creates the order, try generating the PDF and save path to order meta
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_order_created_generate_pdf' ], 20, 3 );

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
     * Save the last FF submission (form + entry) into WC session so we can
     * find it during checkout → order creation.
     */
    public function capture_ff_entry_in_session( $entry_id, $form_data, $form ) {
        $form_id = 0;

        if ( is_array( $form ) && ! empty( $form['id'] ) ) {
            $form_id = (int) $form['id'];
        } elseif ( is_object( $form ) && ! empty( $form->id ) ) {
            $form_id = (int) $form->id;
        } elseif ( is_array( $form_data ) && ! empty( $form_data['form_id'] ) ) {
            $form_id = (int) $form_data['form_id'];
        }

        if ( $form_id !== self::TARGET_FORM_ID ) {
            $this->log( 'Ignoring FF submission for non-target form', [ 'form_id' => $form_id ] );
            return;
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            $map = [
                'form_id'  => $form_id,
                'entry_id' => (int) $entry_id,
            ];
            WC()->session->set( self::SESSION_KEY, $map );
            $this->log( 'Captured FF entry in session', $map );
        } else {
            $this->log( 'WC session unavailable; cannot capture FF entry' );
        }
    }

    /* ------------------------------------------------------
     * 2) GENERATE PDF WHEN ORDER IS CREATED (SAVE TO ORDER)
     * ---------------------------------------------------- */

    /**
     * On order creation, generate PDF for the captured FF entry and save path in order meta.
     */
    public function on_order_created_generate_pdf( $order_id, $posted_data, $order ) {
        $this->log( 'Order processed, attempting PDF generation', [ 'order_id' => $order_id ] );

        $map = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::SESSION_KEY ) : null;
        $this->log( 'Session map', $map );

        if ( empty( $map['form_id'] ) || empty( $map['entry_id'] ) ) {
            $this->log( 'No FF map found in session; skipping PDF generation.' );
            return;
        }

        $form_id  = (int) $map['form_id'];
        if ( $form_id !== self::TARGET_FORM_ID ) {
            $this->log( 'Form ID mismatch; skipping PDF generation', [ 'form_id' => $form_id ] );
            return;
        }

        $entry_id = (int) $map['entry_id'];

        try {
            $pdf_path = $this->create_pdf_for_entry( $form_id, $entry_id, $order_id );
        } catch ( \Throwable $e ) {
            $this->log( 'PDF generation exception', [
                'order_id' => $order_id,
                'form_id'  => $form_id,
                'entry_id' => $entry_id,
                'message'  => $e->getMessage(),
            ] );
            $pdf_path = false;
        }

        if ( $pdf_path && file_exists( $pdf_path ) && is_readable( $pdf_path ) ) {
            $order->update_meta_data( self::ORDER_META_PDF_PATH, $pdf_path );
            $order->save();
            $this->log( 'PDF saved to order', [ 'order_id' => $order_id, 'pdf_path' => $pdf_path ] );
        } else {
            $this->log( 'PDF not generated or unreadable', [ 'order_id' => $order_id ] );
        }
    }

    /**
     * Try multiple APIs from the Fluent Forms PDF add-on to generate a PDF file.
     * Returns absolute path to the PDF on success, or false on failure.
     */
    function create_pdf_for_entry( $form_id, $entry_id, $order_id ) {
        $this->log( 'Starting PDF generation', [ 'form_id' => $form_id, 'entry_id' => $entry_id, 'order_id' => $order_id ] );

        // Destination folder
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            $this->log( 'wp_upload_dir error', $upload );
            return false;
        }
        $this->log( 'Upload directory', $upload );

        $pdf_dir = trailingslashit( $upload['basedir'] ) . 'taxnexcy-pdfs';
        if ( ! wp_mkdir_p( $pdf_dir ) ) {
            $this->log( 'Failed to create pdf_dir', [ 'pdf_dir' => $pdf_dir ] );
            return false;
        }
        $this->log( 'PDF directory ready', [ 'pdf_dir' => $pdf_dir ] );

        $base_name = 'taxnex-taxisnet-submission-{inputs.names.first_name}';
        $base_name = $this->replace_dynamic_tags( $base_name, $form_id, $entry_id );
        $base_name = sanitize_file_name( $base_name );
        if ( ! $base_name ) {
            $base_name = sprintf( 'order-%d-form-%d-entry-%d', (int) $order_id, (int) $form_id, (int) $entry_id );
        }
        $filename = $base_name . '.pdf';
        $dest     = trailingslashit( $pdf_dir ) . $filename;

        // Grab the container/app so we can resolve services in both old & new versions.
        $app = null;
        if ( class_exists( '\FluentFormPdf\App\App' ) && method_exists( '\FluentFormPdf\App\App', 'getInstance' ) ) {
            $app = \FluentFormPdf\App\App::getInstance();
            $this->log( 'Resolved app via \FluentFormPdf\App\App::getInstance()' );
        } elseif ( class_exists( '\FluentForm\App\App' ) && method_exists( '\FluentForm\App\App', 'getInstance' ) ) {
            // Some versions expose the PDF container via core FF app
            $app = \FluentForm\App\App::getInstance();
            $this->log( 'Resolved app via \FluentForm\App\App::getInstance()' );
        }

        if ( ! $app ) {
            $this->log( 'Failed to resolve Fluent Forms PDF app' );
            return false;
        }

        /**
         * Strategy A: GlobalPdfManager (newer add-on)
         * Common signatures seen in the wild:
         *   - GlobalPdfManager::generateEntryPdf( $entryId, $filePath, $settings = [] )
         *   - GlobalPdfManager::generateEntryPdf( $entryId, $formId, $filePath, $settings = [] )
         * We’ll reflect and adapt based on parameter count.
         */
        try {
            if ( class_exists( '\FluentFormPdf\Classes\Controller\GlobalPdfManager' ) ) {
                $this->log( 'Trying GlobalPdfManager' );
                $manager = \FluentFormPdf\Classes\Controller\GlobalPdfManager::class;

                $ref        = new \ReflectionClass( $manager );
                $method     = null;
                $candidates = [ 'generateEntryPdf', 'getEntryPdf', 'generatePdf', 'generate' ];

                foreach ( $candidates as $candidate ) {
                    if ( $ref->hasMethod( $candidate ) ) {
                        $method = $ref->getMethod( $candidate );
                        $this->log( 'GlobalPdfManager using method', [ 'method' => $candidate ] );
                        break;
                    }
                }

                if ( $method ) {
                    $params   = $method->getNumberOfParameters();
                    $settings = $this->prepare_pdf_settings( $form_id, $entry_id, $dest );

                    if ( $params >= 4 ) {
                        // ($entryId, $formId, $filePath, $settings)
                        $pdf_info = $method->invoke( $ref->newInstance(), (int) $entry_id, (int) $form_id, $dest, $settings );
                    } elseif ( $params === 3 ) {
                        // ($entryId, $filePath, $settings)
                        $pdf_info = $method->invoke( $ref->newInstance(), (int) $entry_id, $dest, $settings );
                    } elseif ( $params === 2 ) {
                        // ($entryId, $filePath)
                        $pdf_info = $method->invoke( $ref->newInstance(), (int) $entry_id, $dest );
                    } else {
                        // Unexpected signature, try a conservative call
                        $pdf_info = $method->invoke( $ref->newInstance(), (int) $entry_id );
                    }

                    // Normalize success
                    if ( is_array( $pdf_info ) && ! empty( $pdf_info['file_path'] ) && file_exists( $pdf_info['file_path'] ) ) {
                        $this->log( 'GlobalPdfManager generated file', [ 'file' => $pdf_info['file_path'] ] );
                        return $pdf_info['file_path'];
                    }
                    if ( file_exists( $dest ) && filesize( $dest ) > 0 ) {
                        $this->log( 'GlobalPdfManager wrote destination file', [ 'file' => $dest ] );
                        return $dest;
                    }

                    $this->log( 'GlobalPdfManager returned invalid response', [ 'pdf_info' => $pdf_info ] );
                } else {
                    $this->log( 'GlobalPdfManager missing known methods', [ 'checked' => $candidates ] );
                }
            } else {
                $this->log( 'GlobalPdfManager class missing' );
            }
        } catch ( \Throwable $e ) {
            $this->log( 'GlobalPdfManager failed', [ 'msg' => $e->getMessage() ] );
        }
        /**
         * Strategy B: TemplateManager (older add-on)
         * Some older versions have \FluentFormPdf\Classes\Templates\TemplateManager
         * with instance methods that can render an entry to PDF. We’ll attempt to
         * detect a usable method via reflection.
         */
        try {
            if ( class_exists( '\FluentFormPdf\Classes\Templates\TemplateManager' ) ) {
                $this->log( 'Trying TemplateManager' );

                $ref = new \ReflectionClass( '\FluentFormPdf\Classes\Templates\TemplateManager' );
                if ( ! $ref->isAbstract() ) {
                    $inst = $ref->newInstance();

                    // Try common method names and signatures
                    $candidates = [
                        // method, args builder callback
                        [ 'renderEntry', function() use ( $inst, $form_id, $entry_id ) {
                            if ( method_exists( $inst, 'renderEntry' ) ) {
                                $html = $inst->renderEntry( (int) $entry_id, 'table' );
                                return $this->replace_dynamic_tags( $html, $form_id, $entry_id );
                            }
                            return false;
                        } ],
                        [ 'generatePdf', function() use ( $inst, $form_id, $entry_id, $dest ) {
                            if ( method_exists( $inst, 'generatePdf' ) ) {
                                return $inst->generatePdf( (int) $form_id, (int) $entry_id, $this->prepare_pdf_settings( $form_id, $entry_id, $dest ) );
                            }
                            return false;
                        } ],
                    ];

                    foreach ( $candidates as $cand ) {
                        list( $name, $cb ) = $cand;
                        if ( method_exists( $inst, $name ) ) {
                            $this->log( 'TemplateManager attempting method ' . $name );
                            $out = $cb();
                            // If method writes to $dest, accept it
                            if ( file_exists( $dest ) && filesize( $dest ) > 0 ) {
                                $this->log( 'TemplateManager wrote destination file', [ 'file' => $dest ] );
                                return $dest;
                            }
                            // If method returns an array with file_path
                            if ( is_array( $out ) && ! empty( $out['file_path'] ) && file_exists( $out['file_path'] ) ) {
                                $this->log( 'TemplateManager generated file', [ 'file' => $out['file_path'] ] );
                                return $out['file_path'];
                            }
                        }
                    }

                    $this->log( 'TemplateManager methods did not produce a file' );
                } else {
                    $this->log( 'TemplateManager class is abstract; skipping' );
                }
            } else {
                $this->log( 'TemplateManager class missing' );
            }
        } catch ( \Throwable $e ) {
            $this->log( 'TemplateManager failed', [ 'msg' => $e->getMessage() ] );
        }

        /**
         * Strategy C: GeneralTemplate::outputPDF (latest add-on versions)
         */
        try {
            if (
                class_exists( '\FluentFormPdf\Classes\Templates\GeneralTemplate' )
                && function_exists( 'wpFluent' )
            ) {
                $this->log( 'Trying GeneralTemplate outputPDF' );

                $form = wpFluent()->table( 'fluentform_forms' )
                    ->where( 'id', (int) $form_id )
                    ->first();

                if ( $form ) {
                    $tpl      = new \FluentFormPdf\Classes\Templates\GeneralTemplate( $app );

                    $settings = $tpl->getDefaultSettings( $form );
                    $custom   = $this->prepare_pdf_settings( $form_id, $entry_id, $dest );
                    $settings = array_merge( $settings, $custom );
                    $settings = $this->replace_dynamic_tags( $settings, $form_id, $entry_id );

                    $feed = [
                        'id'           => 0,
                        'name'         => 'form-' . (int) $form_id,
                        'template_key' => 'general',
                        'settings'     => $settings,
                    ];

                    $tmp = $tpl->outputPDF( (int) $entry_id, $feed, $base_name, true );

                    if ( $tmp && file_exists( $tmp ) ) {
                        copy( $tmp, $dest );
                        $this->log( 'GeneralTemplate generated file', [ 'file' => $dest ] );
                        return $dest;
                    }

                    $this->log( 'GeneralTemplate outputPDF returned invalid path', [ 'file' => $tmp ] );
                } else {
                    $this->log( 'GeneralTemplate could not find form', [ 'form_id' => $form_id ] );
                }
            } else {
                $this->log( 'GeneralTemplate class missing or wpFluent unavailable' );
            }
        } catch ( \Throwable $e ) {
            $this->log( 'GeneralTemplate outputPDF failed', [ 'msg' => $e->getMessage() ] );
        }

        /**
         * Strategy D: As a last resort, pull the rendered HTML and store it as a .pdf.
         * (Some PDF viewers still handle it; but this is mostly for debugging.)
         */
        try {
            $this->log( 'Attempting fallback HTML render via SubmissionService' );

            $service = new \FluentForm\App\Services\Submission\SubmissionService();
            $html    = '';

            // Newer FF versions provide renderEntryToHtml
            if ( method_exists( $service, 'renderEntryToHtml' ) ) {
                $html = $service->renderEntryToHtml( (int) $entry_id, [ 'format' => 'table' ] );
            }
            // Very old (<5.0) fallback: renderEntry
            if ( ! $html && method_exists( $service, 'renderEntry' ) ) {
                $html = $service->renderEntry( (int) $entry_id, 'table' );
            }

            if ( $html ) {
                $html = $this->replace_dynamic_tags( $html, $form_id, $entry_id );
                // Basic styling so the fallback is readable and landscape.
                $html = '<style>body{font-family:Arial;color:#000;}table{width:100%;} @page {size: A4 landscape;}</style>' . $html;
                // Write HTML with .pdf extension so you still get a downloadable artifact
                file_put_contents( $dest, $html );
                if ( file_exists( $dest ) && filesize( $dest ) > 0 ) {
                    $this->log( 'Fallback wrote HTML content to file (with .pdf extension)', [ 'file' => $dest ] );
                    return $dest;
                }
            } else {
                $this->log( 'SubmissionService did not return HTML' );
            }
        } catch ( \Throwable $e ) {
            $this->log( 'Fallback HTML render failed', [ 'msg' => $e->getMessage() ] );
        }

        $this->log( 'PDF generation failed in all methods', [ 'form_id' => $form_id, 'entry_id' => $entry_id, 'order_id' => $order_id ] );
        return false;
    }

    /* ---------------------------------------
     * 3) ATTACH TO WOO EMAILS AUTOMATICALLY
     * ------------------------------------- */

    public function attach_pdf_to_emails( $attachments, $email_id, $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return $attachments;
        }

        // Which emails get the attachment:
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
            $this->log( 'Attached PDF to email', [ 'email_id' => $email_id, 'pdf' => $pdf, 'order_id' => $order->get_id() ] );
        } else {
            $this->log( 'No PDF attached; file missing/unreadable', [ 'email_id' => $email_id, 'order_id' => $order->get_id(), 'path' => $pdf ] );
        }

        return $attachments;
    }

    /* ---------------------------------------
     * 4) ADMIN: SHOW LINK IN ORDER DETAILS
     * ------------------------------------- */

    public function admin_order_pdf_meta_box( $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return;
        }
        $pdf = $order->get_meta( self::ORDER_META_PDF_PATH );
        if ( ! $pdf ) {
            echo '<p><strong>Fluent Forms PDF:</strong> Not generated.</p>';
            return;
        }
        if ( file_exists( $pdf ) ) {
            $upload = wp_upload_dir();
            $url    = '';
            if ( strpos( $pdf, trailingslashit( $upload['basedir'] ) ) === 0 ) {
                $rel = ltrim( substr( $pdf, strlen( trailingslashit( $upload['basedir'] ) ) ), '/' );
                $url = trailingslashit( $upload['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', $rel );
            }
            if ( $url ) {
                echo '<p><strong>Fluent Forms PDF:</strong> <a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Download PDF</a></p>';
            } else {
                echo '<p><strong>Fluent Forms PDF:</strong> ' . esc_html( $pdf ) . '</p>';
            }
        } else {
            echo '<p><strong>Fluent Forms PDF:</strong> File not found at ' . esc_html( $pdf ) . '</p>';
        }
    }

    /**
     * Build settings for PDF generation with landscape orientation and colours.
     */
    private function prepare_pdf_settings( $form_id, $entry_id, $dest ) {
        $settings = [
            'file_name'     => basename( $dest ),
            'file_path'     => $dest,
            'save_to'       => dirname( $dest ),
            'paper_size'    => 'A4',
            'orientation'   => 'landscape',
            'primary_color' => '#078586',
            'text_color'    => '#000000',
            'header_title'  => 'Taxnex TaxisNet Submission for {inputs.names.first_name} {inputs.names.last_name}',
            'title'         => 'Taxnex TaxisNet Submission for {inputs.names.first_name} {inputs.names.last_name}',
            'header_text'   => 'Taxnex TaxisNet Submission for {inputs.names.first_name} {inputs.names.last_name}',
            // Explicit title fields for various PDF engines
            'pdf_title'     => 'Taxnex TaxisNet Submission for {inputs.names.first_name} {inputs.names.last_name}',
            'show_title'    => true,
            // Basic CSS variables so colour settings are honoured
            'css'           => ':root{--ff-primary-color:#078586;--ff-text-color:#000000;}',
        ];

        $logo = $this->get_divi_logo_url();
        if ( $logo ) {
            $settings['logo'] = $logo;
        }
        // Load a custom template if available so styling changes take effect.
        $template_path = plugin_dir_path( __FILE__ ) . 'public/pdf-template.html';
        if ( file_exists( $template_path ) ) {
            $settings['template_key']   = 'custom';
            $settings['template']       = 'custom';
            $settings['use_custom_html'] = true;
            $settings['custom_html']    = file_get_contents( $template_path );
        }

        return $this->replace_dynamic_tags( $settings, $form_id, $entry_id );
    }

    /**
     * Retrieve the logo URL from Divi theme settings or fallback to the site logo.
     */
    private function get_divi_logo_url() {
        $logo    = '';
        $options = get_option( 'et_divi' );
        if ( is_array( $options ) && ! empty( $options['logo'] ) ) {
            $logo = esc_url( $options['logo'] );
        }

        if ( ! $logo ) {
            $logo_id = get_theme_mod( 'custom_logo' );
            if ( $logo_id && function_exists( 'wp_get_attachment_image_url' ) ) {
                $logo = wp_get_attachment_image_url( $logo_id, 'full' );
            }
        }

        return $logo;
    }

    /**
     * Replace dynamic tags and {inputs.*} smartcodes in strings or arrays using submission data.
     */
    private function replace_dynamic_tags( $data, $form_id, $entry_id ) {
        $fields   = [];
        $user     = null;
        $user_id  = 0;
        try {
            if ( function_exists( 'wpFluent' ) ) {
                $submission = wpFluent()->table( 'fluentform_submissions' )->find( (int) $entry_id );
                if ( $submission ) {
                    $user_id = ! empty( $submission->user_id ) ? (int) $submission->user_id : 0;
                    if ( ! empty( $submission->response ) ) {
                        $decoded = json_decode( $submission->response, true );
                        if ( is_array( $decoded ) ) {
                            $fields = $decoded;
                        }
                    }
                }
            }
        } catch ( \Throwable $e ) {
            // Ignore lookup failures.
        }

        if ( ! $user_id && function_exists( 'get_current_user_id' ) ) {
            $user_id = (int) get_current_user_id();
        }
        if ( $user_id && function_exists( 'get_userdata' ) ) {
            $user = get_userdata( $user_id );
        }

        $flatten = function ( $array, $dot = '', $bracket = '' ) use ( &$flatten ) {
            $out = [];
            foreach ( $array as $key => $val ) {
                $dot_key     = $dot ? $dot . '.' . $key : $key;
                $bracket_key = $bracket ? $bracket . '[' . $key . ']' : $key;
                if ( is_array( $val ) ) {
                    $out = array_merge( $out, $flatten( $val, $dot_key, $bracket_key ) );
                } else {
                    $out[ $dot_key ]     = $val;
                    $out[ $bracket_key ] = $val;
                }
            }
            return $out;
        };

        $flat = $flatten( $fields );

        $replacements = [
            '{entry_id}'   => $entry_id,
            '{form_id}'    => $form_id,
            '{{entry_id}}' => $entry_id,
            '{{form_id}}'  => $form_id,
        ];

        foreach ( $flat as $key => $val ) {
            if ( is_scalar( $val ) ) {
                $replacements[ '{' . $key . '}' ]          = $val;
                $replacements[ '{{' . $key . '}}' ]        = $val;
                $replacements[ '{inputs.' . $key . '}' ]   = $val;
                $replacements[ '{dynamic.' . $key . '}' ]  = $val;
                $replacements[ '{{dynamic.' . $key . '}}' ] = $val;
            }
        }

        if ( $user ) {
            $user_props = [
                'ID'           => $user->ID,
                'user_login'   => $user->user_login,
                'user_email'   => $user->user_email,
                'display_name' => $user->display_name,
                'first_name'   => isset( $user->first_name ) ? $user->first_name : '',
                'last_name'    => isset( $user->last_name ) ? $user->last_name : '',
            ];
            foreach ( $user_props as $key => $val ) {
                if ( $val !== '' && $val !== null ) {
                    $replacements[ '{user.' . $key . '}' ]   = $val;
                    $replacements[ '{{user.' . $key . '}}' ] = $val;
                }
            }
        }

        $apply = function ( &$item ) use ( $replacements ) {
            if ( is_string( $item ) ) {
                $item = strtr( $item, $replacements );
            }
        };

        if ( is_array( $data ) ) {
            array_walk_recursive( $data, $apply );
        } else {
            $apply( $data );
        }

        return $data;
    }

    /* ----------------
     * 5) MINI LOGGER
     * ---------------- */

    private function log( $message, $context = [] ) {
        $msg = is_scalar( $message ) ? (string) $message : wp_json_encode( $message );

        $full_message = $msg;
        if ( $context ) {
            $full_message .= ' ' . wp_json_encode( $context );
        }

        // Also mirror to Taxnexcy_Logger if available (keeps everything in one place)
        if ( class_exists( 'Taxnexcy_Logger' ) && method_exists( 'Taxnexcy_Logger', 'log' ) ) {
            \Taxnexcy_Logger::log( $full_message );
        }

        // Write to a rotating file in uploads
        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return;
        }

        $log_dir = trailingslashit( $upload['basedir'] );
        if ( ! wp_mkdir_p( $log_dir ) ) {
            return;
        }

        $file = $log_dir . self::LOG_FILE;

        // Rotate if over 5MB
        if ( file_exists( $file ) && filesize( $file ) > 5 * 1024 * 1024 ) {
            @rename( $file, $file . '.' . time() . '.bak' );
        }

        $line = '[' . gmdate( 'Y-m-d H:i:s' ) . '] ' . $full_message . PHP_EOL;
        @file_put_contents( $file, $line, FILE_APPEND );
    }
}

endif;

new Taxnexcy_FF_PDF_Attach();

