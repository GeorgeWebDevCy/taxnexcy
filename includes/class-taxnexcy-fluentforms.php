<?php
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Submission\SubmissionService;
/**
 * Handle Fluent Forms submissions.
 *
 * @package Taxnexcy
 */

class Taxnexcy_FluentForms {
    const FORCED_GATEWAY_ID = 'jccgateway';

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
        $this->log_debug( 'Initialising FluentForms integration' );

        add_action( 'fluentform_submission_inserted', array( $this, 'create_customer' ), 10, 3 );
        add_filter( 'fluentform/redirect_url_value', array( $this, 'maybe_adjust_redirect_url' ), 9, 4 );
        add_filter( 'fluentform/redirect_url_value', array( $this, 'log_redirect_url' ), 20, 4 );
        add_action( 'woocommerce_email_order_meta', array( $this, 'display_email_entry' ), 10, 4 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_admin_meta_fields' ), 15 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'add_session_fields_to_order' ), 10, 2 );
        add_filter( 'woocommerce_available_payment_gateways', array( $this, 'force_gateway_selection' ), 20 );
        add_action( 'woocommerce_checkout_process', array( $this, 'log_checkout_request' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'log_checkout_processed' ), 10, 3 );
        add_filter( 'woocommerce_add_error', array( $this, 'log_woocommerce_error' ) );
        add_action( 'woocommerce_order_status_changed', array( $this, 'log_order_status_change' ), 10, 4 );
        add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'log_cart_loaded_from_session' ) );
        add_action( 'woocommerce_receipt_jccgateway', array( $this, 'log_jccgateway_receipt' ), 5 );
        add_action( 'woocommerce_api_jccgateway', array( $this, 'log_jccgateway_api_request' ), 5 );
        add_action( 'woocommerce_thankyou_jccgateway', array( $this, 'log_jccgateway_thankyou' ), 10 );
    }

    /**
     * Conditionally log debug messages when the logger is available and enabled.
     *
     * @param string $message Message to record.
     * @param array  $context Optional context data.
     *
     * @return void
     */
    private function log_debug( $message, $context = array() ) {
        if ( class_exists( 'Taxnexcy_Logger' ) && Taxnexcy_Logger::is_debug_enabled() ) {
            Taxnexcy_Logger::log( $message, $context );
        }
    }

    /**
     * Mask sensitive request values before logging them.
     *
     * @param array $data Request data.
     * @return array Sanitized request data.
     */
    private function sanitize_request_data( $data ) {
        if ( ! is_array( $data ) ) {
            return array();
        }

        $data      = wp_unslash( $data );
        $sanitized = array();

        foreach ( $data as $key => $value ) {
            $key_string    = is_scalar( $key ) ? sanitize_text_field( (string) $key ) : '';
            $lower_key     = strtolower( $key_string );
            $is_sensitive  = (bool) preg_match( '/(card|pan|cvv|cvc|expiry|exp|token|password|secret|signature|hash|key)/', $lower_key );
            $cleaned_value = '';

            if ( is_array( $value ) ) {
                $cleaned_value = $this->sanitize_request_data( $value );
            } elseif ( is_scalar( $value ) || null === $value ) {
                $cleaned_value = sanitize_text_field( (string) $value );
            }

            $sanitized[ $key_string ] = $is_sensitive ? '***redacted***' : $cleaned_value;
        }

        return $sanitized;
    }

    /**
     * Resolve the JCC log file path if the gateway plugin is installed.
     *
     * @return string Log file path or empty string.
     */
    private function get_jccgateway_log_path() {
        $log_filename = 'wc_jccgateway_' . date( 'Y-m' ) . '.log';
        $candidates   = array();

        if ( class_exists( 'WC_JCCGateway__Payments' ) && method_exists( 'WC_JCCGateway__Payments', 'plugin_abspath' ) ) {
            $candidates[] = trailingslashit( WC_JCCGateway__Payments::plugin_abspath() ) . 'logs/' . $log_filename;
        }

        if ( defined( 'WP_PLUGIN_DIR' ) ) {
            $candidates[] = trailingslashit( WP_PLUGIN_DIR ) . 'jcc-payment-gateway-for-wc/logs/' . $log_filename;
            $candidates[] = trailingslashit( WP_PLUGIN_DIR ) . 'woocommerce-gateway-jccgateway/logs/' . $log_filename;
            $candidates[] = trailingslashit( WP_PLUGIN_DIR ) . 'jccgateway/logs/' . $log_filename;
        }

        foreach ( $candidates as $path ) {
            if ( file_exists( $path ) ) {
                return $path;
            }
        }

        return '';
    }

    /**
     * Log when the JCC receipt page is loaded.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function log_jccgateway_receipt( $order_id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

        $this->log_debug(
            'JCC gateway receipt page hit',
            array(
                'order_id' => (int) $order_id,
                'status'   => $order ? $order->get_status() : '',
                'total'    => $order ? $order->get_total() : 0,
                'currency' => $order ? $order->get_currency() : '',
            )
        );
    }

    /**
     * Log inbound JCC gateway API callbacks before the gateway handles them.
     *
     * @return void
     */
    public function log_jccgateway_api_request() {
        $query  = $this->sanitize_request_data( $_GET );
        $body   = $this->sanitize_request_data( $_POST );
        $action = $query['action'] ?? $body['action'] ?? '';
        $path   = $this->get_jccgateway_log_path();

        $context = array(
            'method'  => sanitize_text_field( $_SERVER['REQUEST_METHOD'] ?? '' ),
            'action'  => sanitize_text_field( (string) $action ),
            'query'   => $query,
            'body'    => $body,
        );

        if ( defined( 'JCCGATEWAY_ENABLE_LOGGING' ) ) {
            $context['jcc_logging_enabled'] = (bool) JCCGATEWAY_ENABLE_LOGGING;
        }

        if ( $path ) {
            $context['jcc_log_path'] = $path;
        }

        $this->log_debug( 'JCC gateway callback received', $context );
    }

    /**
     * Log when the JCC thankyou page is reached.
     *
     * @param int $order_id Order ID.
     * @return void
     */
    public function log_jccgateway_thankyou( $order_id ) {
        $order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

        $this->log_debug(
            'JCC gateway thankyou reached',
            array(
                'order_id'       => (int) $order_id,
                'status'         => $order ? $order->get_status() : '',
                'transaction_id' => $order ? $order->get_transaction_id() : '',
                'orderId_meta'   => $order ? $order->get_meta( 'orderId', true ) : '',
            )
        );
    }

    /**
     * Adjust the redirect URL by prefilling the cart for add-to-cart redirects.
     *
     * @param string $redirect_url Redirect URL generated by Fluent Forms.
     * @param int    $entry_id     Fluent Forms entry ID.
     * @param mixed  $form         Form settings.
     * @param array  $form_data    Submitted form data.
     * @return string Updated redirect URL.
     */
    public function maybe_adjust_redirect_url( $redirect_url, $entry_id, $form, $form_data ) {
        if ( empty( $redirect_url ) ) {
            $this->log_debug( 'Redirect adjustment skipped: empty redirect URL', array( 'entry_id' => (int) $entry_id ) );
            return $redirect_url;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            $this->log_debug( 'Redirect adjustment skipped: WooCommerce cart unavailable', array( 'entry_id' => (int) $entry_id ) );
            return $redirect_url;
        }

        $parsed = wp_parse_url( $redirect_url );
        if ( empty( $parsed['query'] ) ) {
            $this->log_debug(
                'Redirect adjustment skipped: no query string',
                array( 'entry_id' => (int) $entry_id, 'redirect_url' => $redirect_url )
            );
            return $redirect_url;
        }

        parse_str( $parsed['query'], $params );
        $product_id = absint( $params['add-to-cart'] ?? $params['add_to_cart'] ?? 0 );
        if ( ! $product_id ) {
            $this->log_debug(
                'Redirect adjustment skipped: no add-to-cart parameter',
                array( 'entry_id' => (int) $entry_id, 'query' => $parsed['query'] )
            );
            return $redirect_url;
        }

        $mapped_product_id = apply_filters( 'taxnexcy_product_id', 0, $form, $form_data );
        if ( $mapped_product_id && $mapped_product_id !== $product_id ) {
            $this->log_debug(
                'Redirect adjustment skipped: mapped product mismatch',
                array(
                    'mapped_product_id'   => $mapped_product_id,
                    'redirect_product_id' => $product_id,
                )
            );
            return $redirect_url;
        }

        $product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
        if ( ! $product ) {
            $this->log_debug( 'Redirect adjustment skipped: product not found', array( 'product_id' => $product_id ) );
            return $redirect_url;
        }

        if ( method_exists( $product, 'is_purchasable' ) && ! $product->is_purchasable() ) {
            $this->log_debug(
                'Redirect adjustment skipped: product not purchasable',
                array( 'product_id' => $product_id, 'entry_id' => (int) $entry_id )
            );
            return $redirect_url;
        }

        $quantity     = max( 1, absint( $params['quantity'] ?? 1 ) );
        $variation_id = absint( $params['variation_id'] ?? 0 );
        $variations   = array();
        foreach ( $params as $key => $value ) {
            if ( 0 === strpos( $key, 'attribute_' ) ) {
                $clean_value        = function_exists( 'wc_clean' )
                    ? wc_clean( wp_unslash( $value ) )
                    : sanitize_text_field( wp_unslash( $value ) );
                $variations[ $key ] = $clean_value;
            }
        }

        $requires_variation = $product->is_type( 'variable' ) && ! $variation_id && empty( $variations );
        if ( $requires_variation ) {
            $this->log_debug(
                'Redirect adjustment skipped: variable product missing variation data',
                array( 'product_id' => $product_id, 'entry_id' => (int) $entry_id )
            );
            return $redirect_url;
        }

        $cart_items = WC()->cart->get_cart();
        if ( ! empty( $cart_items ) ) {
            $this->log_debug(
                'Emptying cart before Taxnexcy cart prefill',
                array(
                    'product_id' => $product_id,
                    'items'      => count( $cart_items ),
                )
            );
        } else {
            $this->log_debug(
                'Cart already empty before Taxnexcy cart prefill',
                array( 'product_id' => $product_id, 'entry_id' => (int) $entry_id )
            );
        }

        WC()->cart->empty_cart( true );

        $cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations );
        if ( $cart_item_key ) {
            if ( method_exists( WC()->cart, 'set_session' ) ) {
                WC()->cart->set_session();
            }
            $this->log_debug(
                'Added product to cart before redirect',
                array(
                    'product_id'   => $product_id,
                    'entry_id'     => (int) $entry_id,
                    'quantity'     => $quantity,
                    'variation_id' => $variation_id,
                )
            );
            $checkout_url = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : $redirect_url;
            if ( $checkout_url && $checkout_url !== $redirect_url ) {
                $this->log_debug(
                    'Redirecting to checkout after Taxnexcy cart prefill',
                    array(
                        'product_id'   => $product_id,
                        'entry_id'     => (int) $entry_id,
                        'from_url'     => $redirect_url,
                        'checkout_url' => $checkout_url,
                    )
                );
                return $checkout_url;
            }
        } else {
            $notices = function_exists( 'wc_get_notices' ) ? wc_get_notices( 'error' ) : array();
            $this->log_debug(
                'Failed to add product to cart before redirect',
                array(
                    'product_id'   => $product_id,
                    'entry_id'     => (int) $entry_id,
                    'quantity'     => $quantity,
                    'variation_id' => $variation_id,
                    'notices'      => $notices,
                )
            );
        }

        return $redirect_url;
    }

    /**
     * Log the Fluent Forms redirect URL so redirect issues can be diagnosed.
     *
     * @param string $redirect_url Redirect URL generated by Fluent Forms.
     * @param int    $entry_id     Fluent Forms entry ID.
     * @param mixed  $form         Form settings.
     * @param array  $form_data    Submitted form data.
     * @return string Unmodified redirect URL.
     */
    public function log_redirect_url( $redirect_url, $entry_id, $form, $form_data ) {
        $form_id = 0;
        if ( is_array( $form ) && isset( $form['id'] ) ) {
            $form_id = (int) $form['id'];
        } elseif ( is_object( $form ) && isset( $form->id ) ) {
            $form_id = (int) $form->id;
        }

        $this->log_debug(
            'Fluent Forms redirect URL resolved',
            array(
                'redirect_url'  => $redirect_url,
                'entry_id'      => (int) $entry_id,
                'form_id'       => $form_id,
                'form_data_keys' => is_array( $form_data ) ? count( $form_data ) : 0,
            )
        );

        return $redirect_url;
    }

    /**
     * Return Fluent Forms’ native HTML for an entry.
     *
     * @param int $form_id  Form ID.
     * @param int $entry_id Entry ID.
     * @return string HTML for the rendered entry.
     */
    private function render_entry_html( $form_id, $entry_id ) {

        if ( ! class_exists( '\\FluentForm\\App\\Services\\Submission\\SubmissionService' ) ) {
            return '';
        }

        $service = new SubmissionService();

        // v6.x – first param is form_id
        if ( method_exists( $service, 'renderSubmission' ) ) {
            try {
                return $service->renderSubmission( $form_id, $entry_id, 'table' );
            } catch ( \ArgumentCountError $e ) {
                // v5.x – first param is entry_id
                return $service->renderSubmission( $entry_id, 'table' );
            }
        }

        // very old (<5.0) fallback
        if ( method_exists( $service, 'renderEntry' ) ) {
            return $service->renderEntry( $entry_id, 'table' );
        }

        return '';
    }

    /**
     * Create a WooCommerce customer when a form is submitted.
     *
     * @param int   $entry_id Entry ID.
     * @param array $form_data Submitted form data.
     * @param array $form Form settings.
     */
    public function create_customer( $entry_id, $form_data, $form ) {
        $this->log_debug( 'Processing submission entry ' . $entry_id );

        $log_data = $form_data;
        foreach ( $log_data as $key => $value ) {
            $sanitized_key = sanitize_key( $key );
            if ( 'wp_http_referer' === $sanitized_key || strpos( $sanitized_key, 'fluentform_' ) === 0 ) {
                unset( $log_data[ $key ] );
            }
        }

        $this->log_debug( 'Submission data: ' . wp_json_encode( $log_data ) );
        $this->log_debug( 'Form settings: ' . wp_json_encode( $form ) );
        if ( ! function_exists( 'wc_create_new_customer' ) ) {
            $this->log_debug( 'WooCommerce functions unavailable' );
            return;
        }

        $first_name = sanitize_text_field( $form_data['first_name'] ?? '' );
        $last_name  = sanitize_text_field( $form_data['last_name'] ?? '' );
        $email      = sanitize_email( $form_data['email'] ?? '' );

        if ( ! $email ) {
            $this->log_debug( 'No email provided, aborting' );
            return;
        }

        $user_id = email_exists( $email );
        $this->log_debug( 'Checking for existing user: ' . $email );

        if ( ! $user_id ) {
            $password = wp_generate_password();
            $this->log_debug( 'Creating new user for ' . $email );
            $user_id  = wc_create_new_customer( $email, '', $password );

            if ( ! is_wp_error( $user_id ) ) {
                wp_update_user( array(
                    'ID'         => $user_id,
                    'first_name' => $first_name,
                    'last_name'  => $last_name,
                ) );
                $this->log_debug( 'Created user ID ' . $user_id );
            } else {
                $this->log_debug( 'User creation failed: ' . $user_id->get_error_message() );
                $user_id = 0;
            }
        }

        if ( ! $user_id ) {
            $this->log_debug( 'Could not create or find user' );
            return;
        }

        // Log the user in so they can proceed to checkout immediately.
        if ( ! is_user_logged_in() ) {
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );
            if ( function_exists( 'wc_set_customer_auth_cookie' ) ) {
                wc_set_customer_auth_cookie( $user_id );
            }
            $this->log_debug( 'Logged in user ' . $user_id );
        }

        $labels = array();
        if ( class_exists( '\\FluentForm\\App\\Modules\\Form\\FormFieldsParser' ) ) {
            $form_object = (object) $form;
            $raw_labels  = FormFieldsParser::getAdminLabels( $form_object, array() );
            foreach ( $raw_labels as $key => $label ) {
                $parts = explode( '.', $key );
                $base  = array_shift( $parts );
                if ( empty( $parts ) ) {
                    if ( isset( $labels[ $base ] ) && is_array( $labels[ $base ] ) ) {
                        $labels[ $base ]['__label'] = $label;
                    } else {
                        $labels[ $base ] = $label;
                    }
                } else {
                    if ( ! isset( $labels[ $base ] ) || ! is_array( $labels[ $base ] ) ) {
                        $labels[ $base ] = array();
                    }
                    $ref =& $labels[ $base ];
                    foreach ( $parts as $i => $part ) {
                        if ( $i === count( $parts ) - 1 ) {
                            $ref[ $part ] = $label;
                        } else {
                            if ( ! isset( $ref[ $part ] ) || ! is_array( $ref[ $part ] ) ) {
                                $ref[ $part ] = array();
                            }
                            $ref =& $ref[ $part ];
                        }
                    }
                }
            }
        } elseif ( isset( $form['fields'] ) && is_array( $form['fields'] ) ) {
            foreach ( $form['fields'] as $field ) {
                $name  = sanitize_key( $field['name'] ?? ( $field['attributes']['name'] ?? '' ) );
                $label = $field['settings']['admin_field_label']
                    ?: ( $field['settings']['label'] ?? ( $field['label'] ?? '' ) );
                if ( $name ) {
                    if ( in_array( $field['element'] ?? '', array( 'input_repeat', 'repeat_container' ), true ) ) {
                        $labels[ $name ] = array( '__label' => sanitize_text_field( $label ) );

                        $children = array();
                        if ( ! empty( $field['fields'] ) && is_array( $field['fields'] ) ) {
                            $children = $field['fields'];
                        } elseif ( ! empty( $field['columns'] ) && is_array( $field['columns'] ) ) {
                            foreach ( $field['columns'] as $column ) {
                                if ( ! empty( $column['fields'] ) && is_array( $column['fields'] ) ) {
                                    $children = array_merge( $children, $column['fields'] );
                                }
                            }
                        }

                        foreach ( $children as $child ) {
                            $child_label = $child['settings']['admin_field_label']
                                ?: ( $child['settings']['label'] ?? ( $child['label'] ?? '' ) );
                            $labels[ $name ][] = sanitize_text_field( $child_label );
                        }
                    } else {
                        $labels[ $name ] = sanitize_text_field( $label );
                    }
                }
            }
        }

        $legacy_fields = array();
        foreach ( $form_data as $key => $value ) {
            $raw_key       = $key;
            $sanitized_key = sanitize_key( $raw_key );

            $base_key = strpos( $raw_key, '.' ) !== false
                ? sanitize_key( strtok( $raw_key, '.' ) )
                : $sanitized_key;

            // Skip internal Fluent Forms fields like nonces or referrers.
            if ( 'wp_http_referer' === $sanitized_key || strpos( $sanitized_key, 'fluentform_' ) === 0 ) {
                continue;
            }

            $field_labels = $labels[ $base_key ] ?? '';
            $field_label  = is_array( $field_labels ) ? ( $field_labels['__label'] ?? ucwords( str_replace( '_', ' ', $sanitized_key ) ) ) : ( $field_labels ?: ucwords( str_replace( '_', ' ', $sanitized_key ) ) );

            $legacy_fields[] = array(
                'slug'  => $sanitized_key,
                'label' => $field_label,
                'value' => is_array( $value ) ? wp_json_encode( $value ) : sanitize_text_field( $value ),
            );
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->set( 'taxnexcy_fields', $legacy_fields );
            WC()->session->set( '_ff_form_id', absint( $form['id'] ?? 0 ) );
            WC()->session->set( '_ff_entry_id', absint( $entry_id ) );
            WC()->session->set( '_ff_entry_html', $this->render_entry_html( $form['id'], $entry_id ) );
            WC()->session->set( 'taxnexcy_force_gateway', self::FORCED_GATEWAY_ID );
            WC()->session->set( 'chosen_payment_method', self::FORCED_GATEWAY_ID );
            $this->log_debug( 'Stored fields in session: ' . wp_json_encode( $legacy_fields ) );
            $this->log_debug(
                'Stored Taxnexcy session flags',
                array(
                    'entry_id'    => (int) $entry_id,
                    'form_id'     => absint( $form['id'] ?? 0 ),
                    'gateway'     => self::FORCED_GATEWAY_ID,
                    'field_count' => count( $legacy_fields ),
                )
            );
        } else {
            $this->log_debug( 'Session unavailable; unable to store Taxnexcy fields', array( 'entry_id' => (int) $entry_id ) );
        }
    }

    /**
     * Force the JCC gateway to be selected during checkout for Taxnexcy flows.
     *
     * @param array $gateways Available payment gateways.
     * @return array
     */
    public function force_gateway_selection( $gateways ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            $this->log_debug( 'Gateway forcing skipped: WooCommerce session unavailable' );
            return $gateways;
        }

        $forced = WC()->session->get( 'taxnexcy_force_gateway' );
        if ( ! $forced ) {
            return $gateways;
        }

        if ( isset( $gateways[ $forced ] ) ) {
            WC()->session->set( 'chosen_payment_method', $forced );
            $this->log_debug( 'Forced payment gateway selected', array( 'gateway' => $forced ) );
        } else {
            $this->log_debug( 'Forced payment gateway not available', array( 'gateway' => $forced ) );
            WC()->session->set( 'taxnexcy_force_gateway', null );
        }

        return $gateways;
    }
    /**
     * Add saved Fluent Forms fields from the session to WooCommerce order meta.
     *
     * @param WC_Order $order Order object.
     * @param array    $data  Posted checkout data.
     */
    public function add_session_fields_to_order( $order, $data ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            $order_id = is_object( $order ) && method_exists( $order, 'get_id' ) ? $order->get_id() : 0;
            $this->log_debug( 'Session unavailable during order creation', array( 'order_id' => $order_id ) );
            return;
        }

        $fields   = WC()->session->get( 'taxnexcy_fields' );
        $form_id  = WC()->session->get( '_ff_form_id' );
        $entry_id = WC()->session->get( '_ff_entry_id' );
        $html     = WC()->session->get( '_ff_entry_html' );
        $this->log_debug(
            'Preparing order meta from session',
            array(
                'order_id'       => $order->get_id(),
                'form_id'        => (int) $form_id,
                'entry_id'       => (int) $entry_id,
                'field_count'    => is_array( $fields ) ? count( $fields ) : 0,
                'has_entry_html' => ! empty( $html ),
            )
        );

        if ( $fields ) {
            foreach ( $fields as $field ) {
                $order->update_meta_data( 'taxnexcy_' . $field['slug'], $field['value'] );
                $order->update_meta_data( 'taxnexcy_label_' . $field['slug'], $field['label'] );
            }
        }

        if ( $form_id && $entry_id && $html ) {
            $order->update_meta_data( '_ff_form_id', (int) $form_id );
            $order->update_meta_data( '_ff_entry_id', (int) $entry_id );
            $order->update_meta_data( '_ff_entry_html', wp_kses_post( $html ) );
        }

        if ( function_exists( 'WC' ) && WC()->session ) {
            $forced = WC()->session->get( 'taxnexcy_force_gateway' );
            if ( $forced ) {
                $available = WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array();
                if ( isset( $available[ $forced ] ) ) {
                    $order->set_payment_method( $available[ $forced ] );
                    $this->log_debug( 'Applied forced payment gateway to order', array( 'order_id' => $order->get_id(), 'gateway' => $forced ) );
                } else {
                    $this->log_debug( 'Forced payment gateway missing during order creation', array( 'order_id' => $order->get_id(), 'gateway' => $forced ) );
                }
            }
        }

        WC()->session->set( 'taxnexcy_fields', null );
        WC()->session->set( '_ff_form_id', null );
        WC()->session->set( '_ff_entry_id', null );
        WC()->session->set( '_ff_entry_html', null );
        WC()->session->set( 'taxnexcy_force_gateway', null );
        $this->log_debug( 'Added session fields to order ' . $order->get_id() );
    }

    /**
     * Log checkout request data before WooCommerce processes it.
     */
    public function log_checkout_request() {
        $posted = wc_clean( wp_unslash( $_POST ) );
        $this->log_debug( 'Checkout process data: ' . wp_json_encode( $posted ) );

        if ( function_exists( 'WC' ) ) {
            if ( WC()->cart ) {
                $cart_contents = array();
                foreach ( WC()->cart->get_cart() as $item ) {
                    $cart_contents[] = array(
                        'product_id' => $item['product_id'] ?? 0,
                        'quantity'   => $item['quantity'] ?? 0,
                    );
                }
                $this->log_debug( 'Cart contents at checkout: ' . wp_json_encode( $cart_contents ) );
            } else {
                $this->log_debug( 'Cart unavailable during checkout request.' );
            }

            if ( WC()->session ) {
                $session_snapshot = array(
                    'taxnexcy_fields' => WC()->session->get( 'taxnexcy_fields' ),
                    '_ff_form_id'     => WC()->session->get( '_ff_form_id' ),
                    '_ff_entry_id'    => WC()->session->get( '_ff_entry_id' ),
                );
                $this->log_debug( 'Session snapshot before checkout: ' . wp_json_encode( $session_snapshot ) );
            } else {
                $this->log_debug( 'Session unavailable during checkout request.' );
            }
        }
    }

    /**
     * Log when an order is successfully processed at checkout.
     *
     * @param int      $order_id     The order ID.
     * @param array    $posted_data  Sanitized checkout data.
     * @param WC_Order $order        The order object.
     */
    public function log_checkout_processed( $order_id, $posted_data, $order ) {
        $this->log_debug( 'Checkout order processed. ID: ' . $order_id . ' Data: ' . wp_json_encode( $posted_data ) );
    }

    /**
     * Log any WooCommerce checkout errors.
     *
     * @param string $error Error message.
     * @return string Unmodified error message.
     */
    public function log_woocommerce_error( $error ) {
        $this->log_debug( 'WooCommerce error notice: ' . $error );
        return $error;
    }

    /**
     * Log order status transitions for debugging.
     *
     * @param int      $order_id   Order ID.
     * @param string   $old_status Previous status slug.
     * @param string   $new_status New status slug.
     * @param WC_Order $order      Order object.
     */
    public function log_order_status_change( $order_id, $old_status, $new_status, $order ) {
        $this->log_debug( 'Order ' . $order_id . ' status changed from ' . $old_status . ' to ' . $new_status );
    }

    /**
     * Log cart contents when loaded from the session.
     *
     * @param WC_Cart $cart Cart object.
     */
    public function log_cart_loaded_from_session( $cart ) {
        if ( is_object( $cart ) && method_exists( $cart, 'get_cart' ) ) {
            $this->log_debug( 'Cart loaded from session: ' . wp_json_encode( $cart->get_cart() ) );
        }
    }

    /**
     * Output Fluent Form entry in WooCommerce emails.
     *
     * @param WC_Order $order      The order object.
     * @param bool     $sent_to_admin If email is sent to admin.
     * @param bool     $plain_text Whether the email is plain text.
     * @param object   $email      Email object.
     */
    public function display_email_entry( $order, $sent_to_admin, $plain_text, $email ) {
        $html = $order->get_meta( '_ff_entry_html', true );
        if ( ! $html ) {
            $form_id  = (int) $order->get_meta( '_ff_form_id', true );
            $entry_id = (int) $order->get_meta( '_ff_entry_id', true );
            if ( $form_id && $entry_id ) {
                $html = $this->render_entry_html( $form_id, $entry_id );
                if ( $html ) {
                    $order->update_meta_data( '_ff_entry_html', wp_kses_post( $html ) );
                    $order->save();
                }
            }
        }

        if ( $html ) {
            if ( $plain_text ) {
                echo "\n" . __( 'Fluent Form Entry', 'taxnexcy' ) . ":\n";
                echo wp_strip_all_tags( $html ) . "\n";
            } else {
                echo '<h3>' . esc_html__( 'Fluent Form Entry', 'taxnexcy' ) . '</h3>';
                echo $html;
            }
            return;
        }
    }

    /**
     * Display Fluent Form entry in the WooCommerce admin order screen.
     *
     * @param WC_Order $order The order object.
     */
    public function display_admin_meta_fields( $order ) {
        $html = $order->get_meta( '_ff_entry_html', true );
        if ( ! $html ) {
            $form_id  = (int) $order->get_meta( '_ff_form_id', true );
            $entry_id = (int) $order->get_meta( '_ff_entry_id', true );
            if ( $form_id && $entry_id ) {
                $html = $this->render_entry_html( $form_id, $entry_id );
                if ( $html ) {
                    $order->update_meta_data( '_ff_entry_html', wp_kses_post( $html ) );
                    $order->save();
                }
            }
        }

        if ( $html ) {
            echo '<div class="order_data_column">';
            echo '<h4>' . esc_html__( 'Fluent Form Entry', 'taxnexcy' ) . '</h4>';
            echo $html;
            echo '</div>';
            return;
        }
    }

}
