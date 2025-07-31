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
        add_filter( 'woocommerce_email_order_meta_fields', array( $this, 'add_email_meta_fields' ), 10, 3 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_admin_meta_fields' ) );
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

        foreach ( $form_data as $key => $value ) {
            if ( is_scalar( $value ) ) {
                $order->update_meta_data( 'taxnexcy_' . sanitize_key( $key ), sanitize_text_field( $value ) );
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
     * Add stored Fluent Forms data to WooCommerce email meta fields.
     *
     * @param array     $fields Existing email meta fields.
     * @param bool      $sent_to_admin If email is sent to admin.
     * @param WC_Order  $order The order object.
     * @return array
     */
    public function add_email_meta_fields( $fields, $sent_to_admin, $order ) {
        Taxnexcy_Logger::log( 'Adding email meta fields for order ' . $order->get_id() );
        foreach ( $order->get_meta_data() as $meta ) {
            if ( strpos( $meta->key, 'taxnexcy_' ) === 0 ) {
                $label               = ucwords( str_replace( '_', ' ', substr( $meta->key, 9 ) ) );
                $fields[ $meta->key ] = array(
                    'label' => $label,
                    'value' => $meta->value,
                );
            }
        }

        return $fields;
    }

    /**
     * Display Fluent Forms data in the WooCommerce admin order screen.
     *
     * @param WC_Order $order The order object.
     */
    public function display_admin_meta_fields( $order ) {
        echo '<div class="order_data_column">';
        echo '<h4>' . esc_html__( 'Fluent Forms Answers', 'taxnexcy' ) . '</h4>';
        foreach ( $order->get_meta_data() as $meta ) {
            if ( strpos( $meta->key, 'taxnexcy_' ) === 0 ) {
                $label = ucwords( str_replace( '_', ' ', substr( $meta->key, 9 ) ) );
                printf( '<p><strong>%s:</strong> %s</p>', esc_html( $label ), esc_html( $meta->value ) );
            }
        }
        echo '</div>';
    }
}
