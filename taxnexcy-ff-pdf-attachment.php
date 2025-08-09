<?php
/**
 * Fluent Forms entry PDF attachment helper for Taxnexcy.
 * HARDENED: Always use our custom PDF template "gn_smartcode" so all labels/text
 * run through Fluent Forms' ShortCodeParser with the correct context.
 *
 * Drop-in replacement for your existing taxnexcy-ff-pdf-attachment.php
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'Taxnexcy_FF_PDF_Attach' ) ) :

class Taxnexcy_FF_PDF_Attach {

    const VER                 = '1.2.0';
    const SESSION_KEY         = 'taxnexcy_ff_entry_map';
    const ORDER_META_PDF_PATH = '_ff_entry_pdf';
    const LOG_FILE            = 'taxnexcy-ffpdf.log';
    const TARGET_FORM_ID      = 4;
    const PDF_FEED_ID         = 56; // your "General" feed id
    const TEMPLATE_KEY        = 'gn_smartcode'; // our custom template key

    public function __construct() {
        add_action( 'plugins_loaded', [ $this, 'maybe_boot' ], 20 );
    }

    public function maybe_boot() {
        $this->log( 'Booting FF PDF helper' );

        if ( ! class_exists( 'WooCommerce' ) ) return;
        if ( ! defined('FLUENTFORM') ) return;
        if ( ! class_exists( '\FluentFormPdf\Classes\Templates\TemplateManager' ) ) return;

        // Register our custom PDF template in the PDF add-on (if active)
        add_action('fluentform/loaded', function () {
            if ( ! defined('FLUENTFORM_PDF_VERSION') ) return;
            if ( ! class_exists( 'Taxnexcy_GN_Smartcode_Pdf_Template' ) ) {
                // include bundled class file if present in this plugin folder
                $maybe = __DIR__ . '/includes/class-gn-smartcode-pdf-template.php';
                if ( file_exists( $maybe ) ) require_once $maybe;
            }
            if ( class_exists( 'Taxnexcy_GN_Smartcode_Pdf_Template' ) ) {
                add_filter( 'fluentform/pdf_templates', function( $templates, $form ) {
                    $templates[ self::TEMPLATE_KEY ] = [
                        'name'    => 'GN Smartcode Template',
                        'class'   => '\\Taxnexcy_GN_Smartcode_Pdf_Template',
                        'key'     => self::TEMPLATE_KEY,
                        'preview' => ''
                    ];
                    return $templates;
                }, 10, 2 );
            }
        });

        add_action( 'fluentform_submission_inserted', [ $this, 'capture_ff_entry_in_session' ], 20, 3 );
        add_action( 'woocommerce_checkout_order_processed', [ $this, 'on_order_created_generate_pdf' ], 20, 3 );
        add_filter( 'woocommerce_email_attachments', [ $this, 'attach_pdf_to_emails' ], 20, 3 );
        add_action( 'woocommerce_admin_order_data_after_order_details', [ $this, 'admin_order_pdf_meta_box' ], 30 );

        $this->log( 'FF PDF helper booted' );
    }

    public function capture_ff_entry_in_session( $entry_id, $form_data, $form ) {
        if ( ! function_exists( 'WC' ) ) return;
        $map = [
            'form_id'  => is_array($form) ? intval($form['id'] ?? 0) : (is_object($form) ? intval($form->id ?? 0) : 0),
            'entry_id' => intval($entry_id)
        ];
        if ( $map['form_id'] ) {
            WC()->session->set( self::SESSION_KEY, $map );
            $this->log( 'Captured FF entry in session', $map );
        }
    }

    public function on_order_created_generate_pdf( $order_id, $posted_data, $order ) {
        $map = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::SESSION_KEY ) : null;
        if ( empty( $map['form_id'] ) || empty( $map['entry_id'] ) ) {
            $this->log( 'No entry in session; skip' ); return;
        }
        $form_id  = intval($map['form_id']);
        if ( $form_id !== self::TARGET_FORM_ID ) { $this->log('Form mismatch'); return; }
        $entry_id = intval($map['entry_id']);

        $dest = $this->resolve_target_path( $form_id, $entry_id, $order_id );

        $pdf = $this->generate_via_custom_template( $form_id, $entry_id, $dest );
        if ( $pdf && file_exists($pdf) ) {
            $order->update_meta_data( self::ORDER_META_PDF_PATH, $pdf );
            $order->save();
            $this->log('Saved PDF to order', ['file' => $pdf]);
        } else {
            $this->log('Custom template failed; trying legacy fallback');
            $pdf2 = $this->legacy_try_managers( $form_id, $entry_id, $dest );
            if ( $pdf2 && file_exists($pdf2) ) {
                $order->update_meta_data( self::ORDER_META_PDF_PATH, $pdf2 );
                $order->save();
            } else {
                $this->log('All strategies failed');
            }
        }
    }

    private function resolve_target_path( $form_id, $entry_id, $order_id ) {
        $upload = wp_upload_dir();
        $dir    = trailingslashit( $upload['basedir'] ) . 'taxnexcy-pdfs';
        if ( ! file_exists( $dir ) ) wp_mkdir_p( $dir );
        $base = 'ff_form_' . $form_id . '_entry_' . $entry_id . '_order_' . $order_id . '.pdf';
        return trailingslashit( $dir ) . $base;
    }

    private function generate_via_custom_template( $form_id, $entry_id, $dest ) {
        if ( ! class_exists('\\FluentFormPdf\\Classes\\Templates\\GeneralTemplate') || ! function_exists('wpFluent') ) {
            return false;
        }
        if ( ! class_exists('\\Taxnexcy_GN_Smartcode_Pdf_Template') ) {
            $maybe = __DIR__ . '/includes/class-gn-smartcode-pdf-template.php';
            if ( file_exists( $maybe ) ) require_once $maybe;
        }
        if ( ! class_exists('\\Taxnexcy_GN_Smartcode_Pdf_Template') ) return false;

        // Load feed 56 (if exists) to reuse its appearance settings, but force our template key
        $feed = [
            'id'           => self::PDF_FEED_ID,
            'name'         => 'General',
            'template'     => self::TEMPLATE_KEY,
            'template_key' => self::TEMPLATE_KEY,
            'settings'     => []
        ];

        try {
            $row = null;
            $db  = wpFluent();
            foreach ( [ 'fluentform_pdf_feeds', $db->db->prefix . 'fluentform_pdf_feeds', 'ff_pdf_feeds', 'fluentform_form_meta', $db->db->prefix . 'fluentform_form_meta' ] as $tbl ) {
                try {
                    $try = $db->table($tbl)->where('id', self::PDF_FEED_ID)->first();
                    if ( $try ) { $row = $try; break; }
                } catch (\Throwable $e) {}
            }
            if ( $row ) {
                $raw = $row->settings ?? $row->value ?? '';
                $decoded  = json_decode( $raw, true ) ?: [];
                $settings = $decoded['settings'] ?? $decoded;
                if ( is_array($settings) ) $feed['settings'] = $settings;
            }
        } catch (\Throwable $e) {}

        // Ensure basic appearance defaults
        $feed['settings'] = wp_parse_args( $feed['settings'], [
            'paper_size'    => 'A4',
            'orientation'   => 'landscape',
            'primary_color' => '#078586',
            'text_color'    => '#000000',
        ]);

        // Use our template directly (bypasses GlobalPdfManager ambiguity)
        try {
            $app = null;
            if ( class_exists('\\FluentFormPdf\\App\\App') && method_exists('\\FluentFormPdf\\App\\App', 'getInstance') ) {
                $app = \FluentFormPdf\App\App::getInstance();
            } elseif ( class_exists('\\FluentForm\\App\\App') && method_exists('\\FluentForm\\App\\App', 'getInstance') ) {
                $app = \FluentForm\App\App::getInstance();
            }
            if ( ! $app ) return false;

            $tpl = new \Taxnexcy_GN_Smartcode_Pdf_Template( $app );
            $file = $tpl->generatePdf( (int)$entry_id, $feed, 'F', basename($dest, '.pdf') );

            // The template writes into uploads; copy/move to our $dest if needed
            if ( is_string($file) && file_exists($file) ) {
                copy( $file, $dest );
                return $dest;
            }
            if ( file_exists($dest) ) return $dest;
        } catch (\Throwable $e) {
            $this->log('Custom template generation exception', ['msg' => $e->getMessage()]);
        }
        return false;
    }

    private function legacy_try_managers( $form_id, $entry_id, $dest ) {
        // keep minimal fallback just in case
        try {
            if ( class_exists('\\FluentFormPdf\\Classes\\Controller\\GlobalPdfManager') ) {
                $ref = new \ReflectionClass('\\FluentFormPdf\\Classes\\Controller\\GlobalPdfManager');
                if ( $ref->hasMethod('generateEntryPdf') ) {
                    $m = $ref->getMethod('generateEntryPdf');
                    $args = $m->getNumberOfParameters();
                    $settings = [
                        'file_path'   => $dest,
                        'orientation' => 'landscape'
                    ];
                    if ( $args >= 4 ) {
                        $res = $m->invoke( null, (int)$entry_id, (int)$form_id, $dest, $settings );
                    } else {
                        $res = $m->invoke( null, (int)$entry_id, $dest, $settings );
                    }
                    if ( is_string($res) && file_exists($res) ) {
                        copy($res, $dest); return $dest;
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->log('Legacy manager failed', ['msg' => $e->getMessage()]);
        }
        return false;
    }

    public function attach_pdf_to_emails( $attachments, $email_id, $order ) {
        if ( ! $order instanceof \WC_Order ) return $attachments;
        $pdf = $order->get_meta( self::ORDER_META_PDF_PATH );
        if ( $pdf && file_exists( $pdf ) ) {
            $attach_to = [ 'customer_completed_order','new_order' ];
            if ( in_array( $email_id, $attach_to, true ) ) $attachments[] = $pdf;
        }
        return $attachments;
    }

    public function admin_order_pdf_meta_box( $order ) {
        if ( ! $order instanceof \WC_Order ) return;
        $pdf = $order->get_meta( self::ORDER_META_PDF_PATH );
        if ( ! $pdf ) { echo '<p><strong>Fluent Forms PDF:</strong> Not generated.</p>'; return; }
        if ( file_exists( $pdf ) ) {
            $upload = wp_upload_dir();
            $url = '';
            if ( strpos( $pdf, trailingslashit( $upload['basedir'] ) ) === 0 ) {
                $rel = ltrim( substr( $pdf, strlen( trailingslashit( $upload['basedir'] ) ) ), '/' );
                $url = trailingslashit( $upload['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', $rel );
            }
            if ( $url ) echo '<p><strong>Fluent Forms PDF:</strong> <a href="'.esc_url($url).'" target="_blank" rel="noopener">Download PDF</a></p>';
            else echo '<p><strong>Fluent Forms PDF:</strong> '.esc_html($pdf).'</p>';
        } else {
            echo '<p><strong>Fluent Forms PDF:</strong> File not found at '.esc_html($pdf).'</p>';
        }
    }

    private function log( $message, $context = [] ) {
        if ( ! function_exists('wp_upload_dir') ) return;
        $msg = is_scalar( $message ) ? (string)$message : wp_json_encode( $message );
        $ctx = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
        $line = '['. gmdate('Y-m-d H:i:s') .'] '. $msg . $ctx . PHP_EOL;
        $upload = wp_upload_dir();
        $file   = trailingslashit( $upload['basedir'] ) . self::LOG_FILE;
        if ( file_exists( $file ) && filesize( $file ) > 5 * 1024 * 1024 ) @rename( $file, $file . '.' . time() . '.bak' );
        @file_put_contents( $file, $line, FILE_APPEND );
    }
}
endif;

new Taxnexcy_FF_PDF_Attach();
