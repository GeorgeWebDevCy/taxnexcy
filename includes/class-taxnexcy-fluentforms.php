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

        add_action( 'fluentform_submission_inserted', array( $this, 'create_customer_and_order' ), 10, 3 );
        add_filter( 'fluentform_submission_response', array( $this, 'maybe_redirect_to_payment' ), 10, 3 );
    }

    /**
     * Create WooCommerce customer and order when a form is submitted.
     *
     * @param int   $entry_id Entry ID.
     * @param array $form_data Submitted form data.
     * @param array $form Form settings.
     */
    public function create_customer_and_order( $entry_id, $form_data, $form ) {
        if ( ! function_exists( 'wc_create_new_customer' ) ) {
            return;
        }

        $first_name = sanitize_text_field( $form_data['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $form_data['last_name'] ?? '' );
        $email      = sanitize_email( $form_data['email'] ?? '' );

        if ( ! $email ) {
            return;
        }

        $user_id = email_exists( $email );

        if ( ! $user_id ) {
            $password = wp_generate_password();
            $user_id  = wc_create_new_customer( $email, '', $password );

            if ( ! is_wp_error( $user_id ) ) {
                wp_update_user( array(
                    'ID'         => $user_id,
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                ) );
            } else {
                $user_id = 0;
            }
        }

        if ( ! $user_id ) {
            return;
        }

        $product_id = apply_filters( 'taxnexcy_product_id', 0 );
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            return;
        }

        $order = wc_create_order( array( 'customer_id' => $user_id ) );
        $order->add_product( $product, 1 );
        $order->set_payment_method( 'jccgateway' );
        $order->calculate_totals();

        update_post_meta( $entry_id, '_taxnexcy_order_id', $order->get_id() );
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
            }
        }

        return $response;
    }
}
