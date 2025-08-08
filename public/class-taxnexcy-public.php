<?php

/**
 * The public-facing functionality of the plugin.
 *
 * @link       https://georgenicolaou.me
 * @since      1.0.0
 *
 * @package    Taxnexcy
 * @subpackage Taxnexcy/public
 */

/**
 * The public-facing functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the public-facing stylesheet and JavaScript.
 *
 * @package    Taxnexcy
 * @subpackage Taxnexcy/public
 * @author     George Nicolaou <info@georgenicolaou.me>
 */
class Taxnexcy_Public {

	/**
	 * The ID of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $plugin_name    The ID of this plugin.
	 */
	private $plugin_name;

	/**
	 * The version of this plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 * @var      string    $version    The current version of this plugin.
	 */
	private $version;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    1.0.0
	 * @param      string    $plugin_name       The name of the plugin.
	 * @param      string    $version    The version of this plugin.
	 */
	public function __construct( $plugin_name, $version ) {

		$this->plugin_name = $plugin_name;
		$this->version = $version;

	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Taxnexcy_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Taxnexcy_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

		wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/taxnexcy-public.css', array(), $this->version, 'all' );

	}

	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
public function enqueue_scripts() {

		/**
		 * This function is provided for demonstration purposes only.
		 *
		 * An instance of this class should be passed to the run() function
		 * defined in Taxnexcy_Loader as all of the hooks are defined
		 * in that particular class.
		 *
		 * The Taxnexcy_Loader will then create the relationship
		 * between the defined hooks and the functions defined in this
		 * class.
		 */

wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/taxnexcy-public.js', array( 'jquery' ), $this->version, false );

}

       /**
        * Remove pay and cancel buttons from the My Account orders table.
        *
        * @param array    $actions Existing order actions.
        * @param WC_Order $order   The order object.
        * @return array Modified order actions.
        */
       public function remove_my_account_order_actions( $actions, $order ) {
               unset( $actions['pay'] );
               unset( $actions['cancel'] );
               return $actions;
       }

       /**
        * Disable the pay button on the order view page.
        *
        * @param array    $statuses Allowed statuses for payment.
        * @param WC_Order $order    Order object.
        * @return array
        */
       public function disable_order_pay_button( $statuses, $order ) {
               return array();
       }

       /**
        * Disable the cancel button on the order view page.
        *
        * @param array    $statuses Allowed statuses for cancellation.
        * @param WC_Order $order    Order object.
        * @return array
        */
       public function disable_order_cancel_button( $statuses, $order ) {
               return array();
       }

       /**
        * Empty the WooCommerce cart when visiting page ID 100507.
        *
        * @since    1.7.57
        * @return void
        */
       public function empty_cart_on_page() {
               if ( function_exists( 'is_page' ) && is_page( 100507 ) && function_exists( 'WC' ) && WC()->cart ) {
                       WC()->cart->empty_cart();
               }
       }

}
