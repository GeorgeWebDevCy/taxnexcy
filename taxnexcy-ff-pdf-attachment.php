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

class Taxnexcy_FF_PDF_Attach {

    const VER                 = '1.1.15';
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
        if ( ! class_exists( '\FluentFormPdf\Classes\Controller\GlobalPdfManager' )
            && ! class_exists( '\FluentFormPdf\Classes\Templates\TemplateManager' )
            && ! class_exists( '\FluentFormPdf\Classes\Templates\GeneralTemplate' )
        ) {
            $this->log( 'Fluent Forms PDF add-on missing; aborting boot' );
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
        if ( ! function_exists( 'WC' ) ) {
            return;
        }

        $map = [
            'form_id'  => is_array( $form ) && ! empty( $form['id'] ) ? (int) $form['id'] : ( is_object( $form ) && isset( $form->id ) ? (int) $form->id : null ),
            'entry_id' => (int) $entry_id
        ];

        if ( $map['form_id'] ) {
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

        $dest = $this->resolve_target_path( $form_id, $entry_id, $order_id );

        $pdf_path = $this->try_generate_pdf( $form_id, $entry_id, $dest );

        if ( $pdf_path && file_exists( $pdf_path ) ) {
            $order->update_meta_data( self::ORDER_META_PDF_PATH, $pdf_path );
            $order->save();
            $this->log( 'Saved PDF path to order', [ 'order_id' => $order_id, 'pdf' => $pdf_path ] );
        } else {
            $this->log( 'PDF generation failed' );
        }
    }

    private function resolve_target_path( $form_id, $entry_id, $order_id ) {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'taxnexcy-pdfs';
        if ( ! file_exists( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $base = 'ff_form_' . $form_id . '_entry_' . $entry_id . '_order_' . $order_id . '.pdf';
        return trailingslashit( $dir ) . $base;
    }

    private function try_generate_pdf( $form_id, $entry_id, $dest ) {
        $this->log( 'Attempting PDF generation', [ 'form_id' => $form_id, 'entry_id' => $entry_id, 'dest' => $dest ] );

        $app = null;

        // Try to resolve the Fluent Forms app container used by the PDF add-on.
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
                        break;
                    }
                }

                if ( $method ) {
                    $args = $method->getNumberOfParameters();
                    $set  = $this->prepare_pdf_settings( $form_id, $entry_id, $dest );

                    if ( $args >= 4 ) {
                        $res = $method->invoke( null, (int) $entry_id, (int) $form_id, $dest, $set );
                    } elseif ( $args === 3 ) {
                        $res = $method->invoke( null, (int) $entry_id, $dest, $set );
                    } else {
                        // Try minimal fallback
                        $res = $method->invoke( null, (int) $entry_id, $dest );
                    }

                    if ( is_string( $res ) && file_exists( $res ) ) {
                        copy( $res, $dest );
                        $this->log( 'PDF generated via GlobalPdfManager', [ 'file' => $dest ] );
                        return $dest;
                    }
                } else {
                    $this->log( 'GlobalPdfManager method not found' );
                }
            } else {
                $this->log( 'GlobalPdfManager class missing' );
            }
        } catch ( \Throwable $e ) {
            $this->log( 'GlobalPdfManager failed', [ 'msg' => $e->getMessage() ] );
        }

        /**
         * Strategy B: TemplateManager (older add-on)
         * Some versions ship TemplateManager (abstract or concrete)
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
                                return $inst->generatePdf( (int) $entry_id, $this->prepare_pdf_settings( $form_id, $entry_id, $dest ) );
                            }
                            return false;
                        } ],
                    ];

                    foreach ( $candidates as $cand ) {
                        $name = $cand[0];
                        $cb   = $cand[1];
                        if ( $ref->hasMethod( $name ) ) {
                            $out = $cb();
                            if ( $out && is_string( $out ) && file_exists( $out ) ) {
                                copy( $out, $dest );
                                $this->log( 'PDF generated via TemplateManager::' . $name, [ 'file' => $dest ] );
                                return $dest;
                            }
                        }
                    }
                } else {
                    $this->log( 'TemplateManager is abstract; skipping' );
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
                    $tpl    = new \FluentFormPdf\Classes\Templates\GeneralTemplate( $app );

                    // Try to load the real PDF Feed (ID 56) from the Fluent Forms PDF addon
                    $feedRow   = null;
                    $dbErr     = null;

                    try {
                        if ( function_exists( 'wpFluent' ) ) {
                            $db = wpFluent();

                            // Try common table names used by the PDF addon
                            foreach (
                                [
                                    'fluentform_pdf_feeds',
                                    $db->db->prefix . 'fluentform_pdf_feeds',
                                    'ff_pdf_feeds',
                                    'fluentform_form_meta',
                                    $db->db->prefix . 'fluentform_form_meta',
                                ] as $table
                            ) {
                                try {
                                    $maybe = $db->table( $table )->where( 'id', self::PDF_FEED_ID )->first();
                                    if ( $maybe ) {
                                        $feedRow = $maybe;
                                        break;
                                    }
                                } catch ( \Throwable $e ) {
                                    $dbErr = $e->getMessage();
                                }
                            }
                        }
                    } catch ( \Throwable $e ) {
                        $dbErr = $e->getMessage();
                    }

                    if ( $feedRow && ( ! empty( $feedRow->settings ) || ! empty( $feedRow->value ) ) ) {
                        // Use the feed’s own settings from the DB (decoded JSON), then replace dynamic tags
                        $raw      = $feedRow->settings ?? $feedRow->value;
                        $decoded  = json_decode( $raw, true ) ?: [];
                        $settings = $decoded['settings'] ?? $decoded;
                        $settings = $this->replace_dynamic_tags( $settings, $form_id, $entry_id );

                        // Determine template slug from multiple possible keys and expose it consistently
                        $template_key = $feedRow->template_key ?? ( $decoded['template_key'] ?? ( $decoded['template'] ?? 'general' ) );

                        $feed = [
                            'id'           => (int) ( $feedRow->id ?? self::PDF_FEED_ID ),
                            'name'         => $feedRow->title        ?? ( $decoded['title'] ?? 'General' ),
                            'template'     => $template_key,
                            'template_key' => $template_key,
                            'settings'     => $settings,
                        ];

                        $this->log( 'Using DB PDF feed', [
                            'form_id'      => $form_id,
                            'feed_id'      => $feed['id'],
                            'template_key' => $template_key
                        ] );
                    } else {
                        // Fallback to minimal settings if DB feed was not accessible
                        $settings = $this->prepare_pdf_settings( $form_id, $entry_id, $dest );

                        $feed = [
                            'id'           => self::PDF_FEED_ID,
                            'name'         => 'General',
                            'template'     => 'general',
                            'template_key' => 'general',
                            'settings'     => $settings,
                        ];

                        $this->log( 'Using fallback PDF feed (default settings)', [
                            'form_id'  => $form_id,
                            'db_error' => $dbErr,
                        ] );
                    }

                    // Generate the PDF using the resolved $feed
                    $tmp = $tpl->outputPDF( (int) $entry_id, $feed, $base_name, true );

                    if ( $tmp && file_exists( $tmp ) ) {
                        copy( $tmp, $dest );
                        $this->log( 'PDF generated', [ 'file' => $dest ] );
                        return $dest;
                    }
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
            } elseif ( method_exists( $service, 'renderEntry' ) ) {
                $html = $service->renderEntry( (int) $entry_id, 'table' );
            }

            if ( $html ) {
                $html = $this->replace_dynamic_tags( $html, $form_id, $entry_id );

                // Save HTML as a .pdf file (debug fallback)
                file_put_contents( $dest, $html );
                if ( file_exists( $dest ) ) {
                    $this->log( 'Saved raw HTML as PDF (debug fallback)', [ 'file' => $dest ] );
                    return $dest;
                }
            }
        } catch ( \Throwable $e ) {
            $this->log( 'Fallback HTML render failed', [ 'msg' => $e->getMessage() ] );
        }

        return false;
    }

    /* -----------------------------------------
     * 3) EMAIL ATTACHMENTS
     * --------------------------------------- */

    public function attach_pdf_to_emails( $attachments, $email_id, $order ) {
        if ( ! $order instanceof \WC_Order ) {
            return $attachments;
        }
        $pdf = $order->get_meta( self::ORDER_META_PDF_PATH );
        if ( $pdf && file_exists( $pdf ) ) {
            // Attach to selected Woo emails; expand as needed.
            $attach_to = [
                'customer_completed_order',
                'new_order'
            ];
            if ( in_array( $email_id, $attach_to, true ) ) {
                $attachments[] = $pdf;
                $this->log( 'Attached PDF to email', [ 'email_id' => $email_id, 'file' => $pdf ] );
            }
        }
        return $attachments;
    }

    /* -----------------------------------------
     * 4) ADMIN ORDER META BOX
     * --------------------------------------- */

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
     * Render the form entry to HTML so we can replace smartcodes in labels.
     */
    private function get_entry_html( $form_id, $entry_id ) {
        $html = '';

        try {
            if ( class_exists( '\FluentForm\App\Services\Submission\SubmissionService' ) ) {
                $service = new \FluentForm\App\Services\Submission\SubmissionService();

                if ( method_exists( $service, 'renderEntryToHtml' ) ) {
                    $html = $service->renderEntryToHtml( (int) $entry_id, [ 'format' => 'table' ] );
                } elseif ( method_exists( $service, 'renderEntry' ) ) {
                    $html = $service->renderEntry( (int) $entry_id, 'table' );
                }
            }
        } catch ( \Throwable $e ) {
            // Ignore rendering errors.
        }

        if ( $html ) {
            $html = $this->replace_dynamic_tags( $html, $form_id, $entry_id );
        }

        return $html;
    }

    /**
     * Build the PDF settings array (used by various engines),
     * and ensure our dynamic tags are already replaced.
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

        $body = $this->get_entry_html( $form_id, $entry_id );
        if ( $body ) {
            $settings['custom_html'] = $body;
            $settings['body']        = $body;
        }

        return $this->replace_dynamic_tags( $settings, $form_id, $entry_id );
    }

    /**
     * Replace all Fluent Forms smartcodes in strings/arrays using FF ShortCodeParser.
     * Falls back to naive strtr() if the parser isn’t available.
     *
     * Ensures things like:
     *  - "Hi {user.first_name}! Please choose a tax year"
     *  - "Have you stayed in Cyprus for more than 183 days during {dynamic.input_radio}?"
     * resolve to their submitted/user values in PDFs.
     */
    function replace_dynamic_tags( $data, $form_id, $entry_id ) {
        // Prefer Fluent Forms' official ShortCodeParser so all smartcodes (user.*, inputs.*, dynamic.*, conditionals) resolve exactly like core.
        if ( class_exists( '\\FluentForm\\App\\Services\\FormBuilder\\ShortCodeParser' ) ) {
            try {
                $parser = \FluentForm\App\Services\FormBuilder\ShortCodeParser::getInstance();

                // Pass as much context as possible (form, entry, submission data).
                $formRow = null;
                if ( function_exists( 'wpFluent' ) ) {
                    try {
                        $formRow = wpFluent()->table( 'fluentform_forms' )->where( 'id', (int) $form_id )->first();
                    } catch ( \Throwable $e ) {}
                }
                if ( $formRow && method_exists( $parser, 'setForm' ) ) {
                    $parser->setForm( $formRow );
                }
                if ( method_exists( $parser, 'setEntry' ) ) {
                    $parser->setEntry( (int) $entry_id );
                }

                // Also load the raw submission response so {dynamic.field} that point to name attributes resolve.
                $submitted = [];
                try {
                    if ( function_exists( 'wpFluent' ) ) {
                        $submission = wpFluent()->table( 'fluentform_submissions' )->find( (int) $entry_id );
                        if ( $submission && ! empty( $submission->response ) ) {
                            $decoded = json_decode( $submission->response, true );
                            if ( is_array( $decoded ) ) {
                                $submitted = $decoded;
                            }
                        }
                    }
                } catch ( \Throwable $e ) {}

                if ( method_exists( $parser, 'setdata' ) ) {
                    $parser->setdata( $submitted );
                }

                $apply = function( & $item ) use ( $parser ) {
                    if ( is_string( $item ) ) {
                        // isHtml=true so parser touches placeholders inside HTML safely.
                        $item = $parser->parseShortCodeFromString( $item, false, true );
                    }
                };

                if ( is_array( $data ) ) {
                    array_walk_recursive( $data, $apply );
                } else {
                    $apply( $data );
                }

                // Additionally, fix FF table header labels so values mirror labels (nice for PDFs).
                if ( is_string( $data ) ) {
                    $data = preg_replace_callback(
                        '/<th([^>]*)>(.*?)<\/th>\s*<td([^>]*)>(.*?)<\/td>/s',
                        function ( $m ) {
                            $label = trim( $m[2] );
                            $value = trim( $m[4] );
                            return '<th' . $m[1] . '>' . $label . ': ' . $value . '</th><td' . $m[3] . '>' . $value . '</td>';
                        },
                        $data
                    );
                }

                return $data;
            } catch ( \Throwable $e ) {
                // Fall back to naive replacement below.
                $this->log( 'ShortCodeParser failed, falling back', [ 'msg' => $e->getMessage() ] );
            }
        }

        // ---- Fallback: naive replacement (kept for maximum compatibility) ----
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

        $flatten = function ( $array, $dot = '', $bracket = '' ) use ( & $flatten ) {
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
                $replacements[ '{' . $key . '}' ]           = $val;
                $replacements[ '{{' . $key . '}}' ]         = $val;
                $replacements[ '{inputs.' . $key . '}' ]    = $val;
                $replacements[ '{dynamic.' . $key . '}' ]   = $val;
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

        if ( ! isset( $replacements['{user.first_name}'] ) ) {
            $first = $flat['names.first_name'] ?? ( $flat['first_name'] ?? '' );
            if ( $first !== '' ) {
                $replacements['{user.first_name}']   = $first;
                $replacements['{{user.first_name}}'] = $first;
            }
        }
        if ( ! isset( $replacements['{user.last_name}'] ) ) {
            $last = $flat['names.last_name'] ?? ( $flat['last_name'] ?? '' );
            if ( $last !== '' ) {
                $replacements['{user.last_name}']   = $last;
                $replacements['{{user.last_name}}'] = $last;
            }
        }
        if ( ! isset( $replacements['{user.display_name}'] ) ) {
            $first = $replacements['{user.first_name}'] ?? '';
            $last  = $replacements['{user.last_name}'] ?? '';
            $disp  = trim( $first . ' ' . $last );
            if ( $disp !== '' ) {
                $replacements['{user.display_name}']   = $disp;
                $replacements['{{user.display_name}}'] = $disp;
            }
        }

        $apply = function ( & $item ) use ( $replacements ) {
            if ( is_string( $item ) ) {
                $item = strtr( $item, $replacements );
            }
        };

        if ( is_array( $data ) ) {
            array_walk_recursive( $data, $apply );
        } else {
            $apply( $data );
        }

        if ( is_string( $data ) ) {
            $data = preg_replace_callback(
                '/<th([^>]*)>(.*?)<\/th>\s*<td([^>]*)>(.*?)<\/td>/s',
                function ( $m ) {
                    $label = trim( $m[2] );
                    $value = trim( $m[4] );
                    return '<th' . $m[1] . '>' . $label . ': ' . $value . '</th><td' . $m[3] . '>' . $value . '</td>';
                },
                $data
            );
        }

        return $data;
    }

    /* ----------------
     * 5) MINI LOGGER
     * ---------------- */

    private function log( $message, $context = [] ) {
        $msg = is_scalar( $message ) ? (string) $message : wp_json_encode( $message );
        $ctx = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
        $full_message = $msg . $ctx;

        $upload = wp_upload_dir();
        $file   = trailingslashit( $upload['basedir'] ) . self::LOG_FILE;

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

