<?php
use FluentForm\App\Modules\Form\FormFieldsParser;
use FluentForm\App\Services\Submission\SubmissionService;
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
        add_action( 'woocommerce_email_order_meta', array( $this, 'display_email_entry' ), 10, 4 );
        add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'display_admin_meta_fields' ), 15 );
        add_action( 'woocommerce_checkout_create_order', array( $this, 'add_session_fields_to_order' ), 10, 2 );
        add_action( 'woocommerce_checkout_process', array( $this, 'log_checkout_request' ) );
        add_action( 'woocommerce_checkout_order_processed', array( $this, 'log_checkout_processed' ), 10, 3 );
        add_filter( 'woocommerce_add_error', array( $this, 'log_woocommerce_error' ) );
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
        Taxnexcy_Logger::log( 'Processing submission entry ' . $entry_id );

        $log_data = $form_data;
        foreach ( $log_data as $key => $value ) {
            $sanitized_key = sanitize_key( $key );
            if ( 'wp_http_referer' === $sanitized_key || strpos( $sanitized_key, 'fluentform_' ) === 0 ) {
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
            Taxnexcy_Logger::log( 'Stored fields in session: ' . wp_json_encode( $legacy_fields ) );
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
        if ( ! function_exists( 'wc_get_product' ) || ! function_exists( 'wc_get_checkout_url' ) ) {
            Taxnexcy_Logger::log( 'WooCommerce functions unavailable for redirect' );
            return $response;
        }

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

        $fields   = WC()->session->get( 'taxnexcy_fields' );
        $form_id  = WC()->session->get( '_ff_form_id' );
        $entry_id = WC()->session->get( '_ff_entry_id' );
        $html     = WC()->session->get( '_ff_entry_html' );

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

        WC()->session->set( 'taxnexcy_fields', null );
        WC()->session->set( '_ff_form_id', null );
        WC()->session->set( '_ff_entry_id', null );
        WC()->session->set( '_ff_entry_html', null );
        Taxnexcy_Logger::log( 'Added session fields to order ' . $order->get_id() );
    }

    /**
     * Log checkout request data before WooCommerce processes it.
     */
    public function log_checkout_request() {
        $posted = wc_clean( wp_unslash( $_POST ) );
        Taxnexcy_Logger::log( 'Checkout process data: ' . wp_json_encode( $posted ) );
    }

    /**
     * Log when an order is successfully processed at checkout.
     *
     * @param int      $order_id     The order ID.
     * @param array    $posted_data  Sanitized checkout data.
     * @param WC_Order $order        The order object.
     */
    public function log_checkout_processed( $order_id, $posted_data, $order ) {
        Taxnexcy_Logger::log( 'Checkout order processed. ID: ' . $order_id . ' Data: ' . wp_json_encode( $posted_data ) );
    }

    /**
     * Log any WooCommerce checkout errors.
     *
     * @param string $error Error message.
     * @return string Unmodified error message.
     */
    public function log_woocommerce_error( $error ) {
        Taxnexcy_Logger::log( 'WooCommerce error notice: ' . $error );
        return $error;
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
