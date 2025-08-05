<?php
use FluentForm\App\Modules\Form\FormFieldsParser;
/**
 * Handle Fluent Forms submissions.
 *
 * @package Taxnexcy
 */

class Taxnexcy_FluentForms {

    /**
     * Plugin version.
     *
     * @var string
     */
    private $version;

    /**
     * Initialize class and set hooks.
     *
     * @param string $version Plugin version.
     */
    public function __construct( $version ) {
        $this->version = $version;
        Taxnexcy_Logger::log( 'Initialising FluentForms integration' );

        add_action( 'fluentform_submission_inserted', array( $this, 'create_customer' ), 10, 3 );
        add_filter( 'fluentform_submission_response', array( $this, 'maybe_redirect_to_payment' ), 10, 3 );
        // Fallback filter name used by some Fluent Forms versions.
        add_filter( 'fluentform_submit_response', array( $this, 'maybe_redirect_to_payment' ), 10, 3 );
        Taxnexcy_Logger::log( 'Redirect filters registered' );
        add_action( 'woocommerce_email_order_meta', array( $this, 'display_email_meta_table' ), 10, 4 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_admin_meta_fields' ), 15 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'add_session_fields_to_order' ), 10, 2 );
    }

    /**
     * Sanitize a field value recursively.
     *
     * Fluent Forms repeater fields submit nested arrays which would otherwise
     * be stored as the string "Array" in order meta. This helper flattens any
     * nested values into a readable string so they can be displayed in emails
     * and the admin screens.
     *
     * @param mixed $value  Field value.
     * @param array $labels Optional field labels indexed by field slug.
     * @return string Sanitized value.
     */
    private function sanitize_field_value( $value, $labels = array() ) {
        if ( is_array( $value ) ) {
            $sanitized = array();
            foreach ( $value as $key => $sub_value ) {
                $sub_value = $this->sanitize_field_value( $sub_value, $labels );
                if ( is_string( $key ) && $key !== '' && ! is_numeric( $key ) ) {
                    $label       = isset( $labels[ $key ] ) ? $labels[ $key ] : $key;
                    $sanitized[] = sanitize_text_field( $label ) . ': ' . $sub_value;
                } else {
                    $sanitized[] = $sub_value;
                }
            }
            return implode( ' | ', array_filter( $sanitized, 'strlen' ) );
        }

        return sanitize_text_field( $value );
    }

    /**
     * Create a WooCommerce customer when a form is submitted.
     *
     * @param int   $entry_id Entry ID.
     * @param array $form_data Submitted form data.
     * @param array $form Form settings.
     */
    public function create_customer( $entry_id, $form_data, $form ) {
        Taxnexcy_Logger::log( 'Processing submission entry ' . $entry_id );

        $log_data   = $form_data;
        $skip_fields = array( 'fluentform_nonce', 'fluentform_id', 'wp_http_referer', 'fluentform_embed_post_id' );
        foreach ( $log_data as $key => $value ) {
            $sanitized_key = sanitize_key( $key );
            if ( in_array( $sanitized_key, $skip_fields, true ) || preg_match( '/^fluentform_\d+_fluentformnonce$/', $sanitized_key ) ) {
                unset( $log_data[ $key ] );
            }
        }

        Taxnexcy_Logger::log( 'Submission data: ' . wp_json_encode( $log_data ) );
        Taxnexcy_Logger::log( 'Form settings: ' . wp_json_encode( $form ) );
        if ( ! function_exists( 'wc_create_new_customer' ) ) {
            Taxnexcy_Logger::log( 'WooCommerce functions unavailable' );
            return;
        }

        $first_name = sanitize_text_field( $form_data['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $form_data['last_name'] ?? '' );
        $email      = sanitize_email( $form_data['email'] ?? '' );

        if ( ! $email ) {
            Taxnexcy_Logger::log( 'No email provided, aborting' );
            return;
        }

        $user_id = email_exists( $email );
        Taxnexcy_Logger::log( 'Checking for existing user: ' . $email );

        if ( ! $user_id ) {
            $password = wp_generate_password();
            Taxnexcy_Logger::log( 'Creating new user for ' . $email );
            $user_id  = wc_create_new_customer( $email, '', $password );

            if ( ! is_wp_error( $user_id ) ) {
                wp_update_user( array(
                    'ID'         => $user_id,
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                ) );
                Taxnexcy_Logger::log( 'Created user ID ' . $user_id );
            } else {
                Taxnexcy_Logger::log( 'User creation failed: ' . $user_id->get_error_message() );
                $user_id = 0;
            }
        }

        if ( ! $user_id ) {
            Taxnexcy_Logger::log( 'Could not create or find user' );
            return;
        }

        // Log the user in so they can proceed to checkout immediately.
        if ( ! is_user_logged_in() ) {
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );
            if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
                wc_set_customer_auth_cookie( $user_id );
            }
            Taxnexcy_Logger::log( 'Logged in user ' . $user_id );
        }

        $labels = array();
        if ( class_exists( '\\FluentForm\\App\\Modules\\Form\\FormFieldsParser' ) ) {
            $form_object = (object) $form;
            $labels      = FormFieldsParser::getAdminLabels( $form_object, array() );
        } elseif ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $name  = sanitize_key( $field['name'] ?? ( $field['attributes']['name'] ?? '' ) );
                $label = $field['settings']['admin_field_label']
                    ?: ( $field['settings']['label'] ?? ( $field['label'] ?? '' ) );
                if ( $name ) {
                    $labels[ $name ] = sanitize_text_field( $label );
                }
            }
        }

        $fields = array();
        foreach ( $form_data as $key => $value ) {
            $sanitized_key = sanitize_key( $key );

            // Skip internal Fluent Forms fields like nonces or referrers.
            $skip_fields = array( 'fluentform_nonce', 'fluentform_id', 'wp_http_referer', 'fluentform_embed_post_id' );
            if ( in_array( $sanitized_key, $skip_fields, true ) || preg_match( '/^fluentform_\d+_fluentformnonce$/', $sanitized_key ) ) {
                continue;
            }

            $value = $this->sanitize_field_value( $value, $labels );

            $fields[] = array(
                'slug'  => $sanitized_key,
                'label' => $labels[ $sanitized_key ] ?? ucwords( str_replace( '_', ' ', $sanitized_key ) ),
                'value' => $value,
            );
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'taxnexcy_fields', $fields );
            Taxnexcy_Logger::log( 'Stored fields in session: ' . wp_json_encode( $fields ) );
        }
    }

    /**
     * Redirect users to the WooCommerce checkout after submission.
     *
     * @param array $response Original response.
     * @param array $form_data Form data.
     * @param array $form Form settings.
     * @return array
     */
    public function maybe_redirect_to_payment( $response, $form_data, $form ) {
        Taxnexcy_Logger::log( 'maybe_redirect_to_payment triggered. Raw data: ' . wp_json_encode( $form_data ) );

        $product_id = apply_filters( 'taxnexcy_product_id', 0, $form, $form_data );
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            Taxnexcy_Logger::log( 'Product not found for redirect. ID: ' . $product_id );
            return $response;
        }

        $url = add_query_arg(
            array(
                'add-to-cart' => $product_id,
                'quantity'    => 1,
            ),
            wc_get_checkout_url()
        );
        Taxnexcy_Logger::log( 'Checkout URL with cart params: ' . $url );

        $should_redirect = ! ( defined( 'TAXNEXCY_DISABLE_REDIRECT' ) && TAXNEXCY_DISABLE_REDIRECT );
        $should_redirect = apply_filters( 'taxnexcy_redirect_to_payment', $should_redirect, $product_id );

        if ( $url && $should_redirect ) {
            // Provide multiple keys for compatibility with different Fluent Forms versions.
            $response['redirect_to'] = $url;
            $response['redirect_url'] = $url;
            $response['redirectTo']   = $url;
            if ( ! wp_doing_ajax() ) {
                wp_safe_redirect( $url );
                exit;
            }
            Taxnexcy_Logger::log( 'Redirecting to checkout for product ' . $product_id );
        } elseif ( ! $should_redirect ) {
            Taxnexcy_Logger::log( 'Redirect disabled for product ' . $product_id );
        } else {
            Taxnexcy_Logger::log( 'Checkout URL empty for product ' . $product_id );
        }

        Taxnexcy_Logger::log( 'Response after redirect check: ' . wp_json_encode( $response ) );

        return $response;
    }

    /**
     * Add saved Fluent Forms fields from the session to WooCommerce order meta.
     *
     * @param WC_Order $order Order object.
     * @param array    $data  Posted checkout data.
     */
    public function add_session_fields_to_order( $order, $data ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }

        $fields = WC()->session->get( 'taxnexcy_fields' );
        if ( ! $fields ) {
            return;
        }

        foreach ( $fields as $field ) {
            $order->update_meta_data( 'taxnexcy_' . $field['slug'], $field['value'] );
            $order->update_meta_data( 'taxnexcy_label_' . $field['slug'], $field['label'] );
        }

        WC()->session->set( 'taxnexcy_fields', null );
        Taxnexcy_Logger::log( 'Added session fields to order ' . $order->get_id() );
    }

    /**
     * Build an array of [label, value] pairs from Taxnexcy order meta.
     *
     * @param WC_Order $order Order object.
     * @return array
     */
    public function get_ff_fields( $order ) {
        $fields = array();
        foreach ( $order->get_meta_data() as $meta ) {
            // Only Taxnexcy fields and skip the stored labels themselves.
            if ( strpos( $meta->key, 'taxnexcy_' ) !== 0 || strpos( $meta->key, 'taxnexcy_label_' ) === 0 ) {
                continue;
            }

            $slug  = substr( $meta->key, 9 );
            $label = $order->get_meta( 'taxnexcy_label_' . $slug, true );

            // Skip common hidden Fluent Forms fields.
            if ( in_array( $slug, array( 'wp_http_referer', 'fluentform_nonce', 'fluentform_id', 'fluentform_embed_post_id' ), true ) ||
                preg_match( '/^fluentform_\d+_fluentformnonce$/', $slug ) ) {
                continue;
            }

            if ( ! $label ) {
                $label = ucwords( str_replace( '_', ' ', $slug ) );
            }

            $value = $meta->value;
            if ( is_array( $value ) ) {
                $value = $this->sanitize_field_value( $value );
            } else {
                $value = sanitize_text_field( $value );
            }

            $fields[] = array(
                'label' => $label,
                'value' => $value,
            );
        }

        return $fields;
    }

    /**
     * Output Fluent Form data as a table in WooCommerce emails.
     *
     * @param WC_Order $order      The order object.
     * @param bool     $sent_to_admin If email is sent to admin.
     * @param bool     $plain_text Whether the email is plain text.
     * @param object   $email      Email object.
     */
    public function display_email_meta_table( $order, $sent_to_admin, $plain_text, $email ) {
        $fields = $this->get_ff_fields( $order );
        if ( ! $fields ) {
            return;
        }

        if ( ! $plain_text ) {
            echo '<h3>' . esc_html__( 'Fluent Forms Answers', 'taxnexcy' ) . '</h3>';
            echo '<table cellspacing="0" cellpadding="6" style="width:100%; border:1px solid #eee;" border="1">';
            echo '<thead><tr><th style="text-align:left;">' . esc_html__( 'Question', 'taxnexcy' ) . '</th><th style="text-align:left;">' . esc_html__( 'Answer', 'taxnexcy' ) . '</th></tr></thead>';
            echo '<tbody>';
            foreach ( $fields as $field ) {
                printf( '<tr><td style="text-align:left;">%s</td><td style="text-align:left;">%s</td></tr>', esc_html( $field['label'] ), esc_html( $field['value'] ) );
            }
            echo '</tbody></table>';
        } else {
            echo "\n" . __( 'Fluent Forms Answers', 'taxnexcy' ) . ":\n";
            foreach ( $fields as $field ) {
                echo $field['label'] . ': ' . $field['value'] . "\n";
            }
        }
    }

    /**
     * Display Fluent Forms data in the WooCommerce admin order screen.
     *
     * @param WC_Order $order The order object.
     */
    public function display_admin_meta_fields( $order ) {
        $fields = $this->get_ff_fields( $order );
        if ( $fields ) {
            echo '<div class="order_data_column">';
            echo '<h4>' . esc_html__( 'Fluent Forms Answers', 'taxnexcy' ) . '</h4>';
            echo '<table class="wp-list-table widefat striped">';
            echo '<thead><tr><th>' . esc_html__( 'Question', 'taxnexcy' ) . '</th><th>' . esc_html__( 'Answer', 'taxnexcy' ) . '</th></tr></thead>';
            echo '<tbody>';
            foreach ( $fields as $field ) {
                printf( '<tr><td>%s</td><td>%s</td></tr>', esc_html( $field['label'] ), esc_html( $field['value'] ) );
            }
            echo '</tbody></table></div>';
        }
    }
}
