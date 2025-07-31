<?php
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

        add_action( 'fluentform_submission_inserted', array( $this, 'create_customer_and_order' ), 10, 3 );
        add_filter( 'fluentform_submission_response', array( $this, 'maybe_redirect_to_payment' ), 10, 3 );
        add_action( 'woocommerce_email_order_meta', array( $this, 'display_email_meta_table' ), 10, 4 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_admin_meta_fields' ), 15 );
    }

    /**
     * Create WooCommerce customer and order when a form is submitted.
     *
     * @param int   $entry_id Entry ID.
     * @param array $form_data Submitted form data.
     * @param array $form Form settings.
     */
    public function create_customer_and_order( $entry_id, $form_data, $form ) {
        Taxnexcy_Logger::log( 'Processing submission entry ' . $entry_id );
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

        $product_id = apply_filters( 'taxnexcy_product_id', 0, $form, $form_data );
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            Taxnexcy_Logger::log( 'Product not found for form ' . ( $form['id'] ?? 'unknown' ) . ' (ID ' . $product_id . ')' );
            return;
        }

        $order = wc_create_order( array( 'customer_id' => $user_id ) );
        Taxnexcy_Logger::log( 'Created order ' . $order->get_id() . ' for user ' . $user_id );
        $order->add_product( $product, 1 );
        $order->set_payment_method( 'jccgateway' );
        $order->calculate_totals();

        $labels = array();
        if ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $name  = sanitize_key( $field['name'] ?? ( $field['attributes']['name'] ?? '' ) );
                $label = $field['settings']['label'] ?? ( $field['label'] ?? '' );
                if ( $name ) {
                    $labels[ $name ] = sanitize_text_field( $label );
                }
            }
        }

        foreach ( $form_data as $key => $value ) {
            if ( ! is_scalar( $value ) ) {
                continue;
            }

            $sanitized_key = sanitize_key( $key );

            // Skip internal Fluent Forms fields like nonces or referrers.
            $skip_fields = array( 'fluentform_nonce', 'fluentform_id', 'wp_http_referer' );
            if ( in_array( $sanitized_key, $skip_fields, true ) || preg_match( '/^fluentform_\d+_fluentformnonce$/', $sanitized_key ) ) {
                continue;
            }

            $order->update_meta_data( 'taxnexcy_' . $sanitized_key, sanitize_text_field( $value ) );

            if ( isset( $labels[ $sanitized_key ] ) ) {
                $order->update_meta_data( 'taxnexcy_label_' . $sanitized_key, $labels[ $sanitized_key ] );
            }
        }
        $order->save();
        Taxnexcy_Logger::log( 'Order ' . $order->get_id() . ' saved' );

        update_post_meta( $entry_id, '_taxnexcy_order_id', $order->get_id() );
        Taxnexcy_Logger::log( 'Stored order ID in entry meta' );
    }

    /**
     * Redirect users to the order payment page after submission.
     *
     * @param array $response Original response.
     * @param array $form_data Form data.
     * @param array $form Form settings.
     * @return array
     */
    public function maybe_redirect_to_payment( $response, $form_data, $form ) {
        $entry_id = $form_data['entry_id'] ?? 0;
        $order_id = $entry_id ? (int) get_post_meta( $entry_id, '_taxnexcy_order_id', true ) : 0;

        if ( $order_id ) {
            $order   = wc_get_order( $order_id );
            $url     = $order ? $order->get_checkout_payment_url() : '';
            if ( $url ) {
                $response['redirect_to'] = $url;
                Taxnexcy_Logger::log( 'Redirecting to payment page for order ' . $order_id );
            }
        } else {
            Taxnexcy_Logger::log( 'No order found for entry ' . $entry_id );
        }

        return $response;
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
            if ( in_array( $slug, array( 'wp_http_referer', 'fluentform_nonce', 'fluentform_id' ), true ) ||
                preg_match( '/^fluentform_\d+_fluentformnonce$/', $slug ) ) {
                continue;
            }

            if ( ! $label ) {
                $label = ucwords( str_replace( '_', ' ', $slug ) );
            }

            $fields[] = array(
                'label' => $label,
                'value' => $meta->value,
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
