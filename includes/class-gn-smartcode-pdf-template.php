<?php
/**
 * Custom PDF template that ALWAYS parses Fluent Forms SmartCodes (user.*, inputs.*, dynamic.*)
 * inside header/body/footer AND inside the generated {all_data} table labels.
 *
 * Register via: add_filter( 'fluentform/pdf_templates', ... )
 */

use FluentForm\Framework\Foundation\Application;

if ( ! class_exists( 'Taxnexcy_GN_Smartcode_Pdf_Template' ) ) :

if ( class_exists( '\FluentFormPdf\Classes\Templates\TemplateManager' ) ) {
    class Taxnexcy_Pdf_Template_Base extends \FluentFormPdf\Classes\Templates\TemplateManager {}
} elseif ( class_exists( '\FluentForm\App\Services\Pdf\Templates\TemplateManager' ) ) {
    class Taxnexcy_Pdf_Template_Base extends \FluentForm\App\Services\Pdf\Templates\TemplateManager {}
} elseif ( class_exists( '\FluentForm\App\Services\PDF\Templates\TemplateManager' ) ) {
    class Taxnexcy_Pdf_Template_Base extends \FluentForm\App\Services\PDF\Templates\TemplateManager {}
} else {
    class Taxnexcy_Pdf_Template_Base {}
}

class Taxnexcy_GN_Smartcode_Pdf_Template extends Taxnexcy_Pdf_Template_Base
{
    public function __construct(Application $app) { parent::__construct($app); }

    public function getDefaultSettings() {
        return [
            'header'        => 'Taxnex TaxisNet Submission for {inputs.names.first_name} {inputs.names.last_name}',
            'body'          => '{all_data}', // we will replace with parsed entry HTML
            'footer'        => '{submission.created_at}',
            'paper_size'    => 'A4',
            'orientation'   => 'landscape',
            'primary_color' => '#078586',
            'text_color'    => '#000000'
        ];
    }

    public function getSettingsFields() {
        return [
            [
                'key'       => 'header',
                'label'     => 'Header',
                'component' => 'wp-editor'
            ],
            [
                'key'       => 'body',
                'label'     => 'Body (HTML)',
                'component' => 'wp-editor'
            ],
            [
                'key'       => 'footer',
                'label'     => 'Footer',
                'component' => 'wp-editor'
            ]
        ];
    }

    public function generatePdf($submissionId, $feed, $outPut = 'I', $fileName = 'taxnex-submission')
    {
        $submission = wpFluent()->table('fluentform_submissions')->find( (int)$submissionId );
        if ( ! $submission ) return false;

        $form = wpFluent()->table('fluentform_forms')->where('id', (int)$submission->form_id)->first();

        $parser = $this->getParser($form, (int)$submissionId, $submission);

        $settings = isset($feed['settings']) && is_array($feed['settings']) ? $feed['settings'] : $this->getDefaultSettings();

        $header = isset($settings['header']) ? $this->parseMaybeHtml($parser, $settings['header']) : '';
        $footer = isset($settings['footer']) ? $this->parseMaybeHtml($parser, $settings['footer']) : '';

        // Build body
        $body = '';
        if ( ! empty($settings['body']) && strpos($settings['body'], '{all_data}') === false ) {
            $body = $this->parseMaybeHtml($parser, $settings['body']);
        } else {
            // Get the standard entry table HTML and then parse smartcodes inside labels
            $bodyHtml = '';
            try {
                if ( class_exists('\\FluentForm\\App\\Services\\Submission\\SubmissionService') ) {
                    $service = new \FluentForm\App\Services\Submission\SubmissionService();
                    if ( method_exists($service, 'renderEntryToHtml') ) {
                        $bodyHtml = $service->renderEntryToHtml( (int)$submissionId, [ 'format' => 'table' ] );
                    } elseif ( method_exists($service, 'renderEntry') ) {
                        $bodyHtml = $service->renderEntry( (int)$submissionId, 'table' );
                    }
                }
            } catch (\Throwable $e) {}

            if ( $bodyHtml ) {
                $bodyHtml = $this->parseMaybeHtml($parser, $bodyHtml);
                // Optional: duplicate label into <th> to include value (helps readability in PDFs)
                $bodyHtml = preg_replace_callback(
                    '/<th([^>]*)>(.*?)<\/th>\s*<td([^>]*)>(.*?)<\/td>/s',
                    function ( $m ) {
                        $label = trim( $m[2] );
                        $value = trim( $m[4] );
                        return '<th' . $m[1] . '>' . $label . ': ' . $value . '</th><td' . $m[3] . '>' . $value . '</td>';
                    },
                    $bodyHtml
                );
            }
            $body = $bodyHtml;
        }

        // Minimal style to respect color settings
        $primary = esc_attr( $settings['primary_color'] ?? '#078586' );
        $text    = esc_attr( $settings['text_color'] ?? '#000000' );

        $htmlBody = '<style>:root{--ff-primary-color:'.$primary.';--ff-text-color:'.$text.'}
            h1,h2,h3{color:var(--ff-primary-color);margin:0 0 12px}
            table{width:100%;border-collapse:collapse}
            th,td{border:1px solid #ddd;padding:6px 8px;text-align:left;font-size:12px}
            th{background:#f8f8f8}
            .header{margin-bottom:14px;border-bottom:2px solid var(--ff-primary-color);padding-bottom:6px}
        </style>';

        if ( $header ) $htmlBody .= '<div class="header">'.$header.'</div>';
        $htmlBody .= $body ?: '<p>No data.</p>';

        // Force landscape orientation etc. by merging into $feed
        $feed['settings'] = wp_parse_args( $settings, [
            'orientation'   => 'landscape',
            'paper_size'    => 'A4',
            'primary_color' => $primary,
            'text_color'    => $text,
        ] );

        // Use a deterministic filename if asked to save
        if ( $outPut === 'F' ) {
            $fileName = $fileName ?: ('taxnex-submission-' . (int)$submissionId);
        }

        return $this->pdfBuilder($fileName, $feed, $htmlBody, $footer, $outPut);
    }

    private function getParser($form, $entryId, $submissionObj) {
        $parser = null;
        if ( class_exists('\\FluentForm\\App\\Services\\FormBuilder\\ShortCodeParser') ) {
            $parser = \FluentForm\App\Services\FormBuilder\ShortCodeParser::getInstance();
            if ( method_exists($parser, 'setForm') ) $parser->setForm($form);
            if ( method_exists($parser, 'setEntry') ) $parser->setEntry( (int)$entryId );
            if ( method_exists($parser, 'setdata') ) {
                $submitted = [];
                if ( ! empty($submissionObj->response) ) {
                    $decoded = json_decode( $submissionObj->response, true );
                    if ( is_array($decoded) ) $submitted = $decoded;
                }
                $parser->setdata( $submitted );
            }
        }
        return $parser;
    }

    private function parseMaybeHtml($parser, $string) {
        if ( is_string($string) && $parser && method_exists($parser, 'parseShortCodeFromString') ) {
            return $parser->parseShortCodeFromString( $string, false, true ); // isHtml = true
        }
        return $string;
    }
}

endif;
