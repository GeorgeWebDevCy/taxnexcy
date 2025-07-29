<?php
/**
 * Plugin Name: FluentForms WooCommerce JCC Integration
 * Description: Creates WooCommerce user and order from FluentForms submission and redirects to JCC payment.
 * Version: 0.1.0
 * Author: Codex
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

// Include the update checker library and configure updates from GitHub.
require_once __DIR__ . '/../../plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ff_wc_jcc_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/GeorgeWebDevCy/taxnexcy/',
    __FILE__,
    'ff-wc-jcc'
);
$ff_wc_jcc_update_checker->setBranch( 'main' );

class FF_WC_JCC_Integration {
    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'fluentform_submission_inserted', array( $this, 'handle_submission' ), 10, 2 );
    }

    /**
     * Handle form submission from FluentForms.
     *
     * @param int   $entry_id Entry ID.
     * @param array $form_data Form data.
     */
    public function handle_submission( $entry_id, $form_data ) {
        if ( empty( $form_data['email'] ) ) {
            return;
        }

        $user_email = sanitize_email( $form_data['email'] );
        $first_name = sanitize_text_field( $form_data['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $form_data['last_name'] ?? '' );

        $user = get_user_by( 'email', $user_email );
        if ( ! $user ) {
            $password = wp_generate_password();
            $user_id  = wc_create_new_customer( $user_email, $user_email, $password );
            if ( is_wp_error( $user_id ) ) {
                error_log( 'FF_WC_JCC: user creation failed - ' . $user_id->get_error_message() );
                return;
            }
            wp_update_user( array( 'ID' => $user_id, 'first_name' => $first_name, 'last_name' => $last_name ) );
            $user = get_user_by( 'ID', $user_id );
        }

        $product_id = apply_filters( 'ff_wc_jcc_product_id', null, $form_data );
        if ( ! $product_id ) {
            error_log( 'FF_WC_JCC: no product ID provided.' );
            return;
        }

        $order = wc_create_order( array( 'customer_id' => $user->ID ) );
        $order->add_product( wc_get_product( $product_id ), 1 );
        $order->calculate_totals();

        $gateway_id = apply_filters( 'ff_wc_jcc_gateway_id', 'jcc_gateway' );
        $order->set_payment_method( $gateway_id );
        $order->save();

        $payment_url = $order->get_checkout_payment_url();
        wp_safe_redirect( $payment_url );
        exit;
    }
}

new FF_WC_JCC_Integration();

