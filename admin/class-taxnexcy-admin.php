<?php

/**
 * The admin-specific functionality of the plugin.
 *
 * @link       https://georgenicolaou.me
 * @since      1.0.0
 *
 * @package    Taxnexcy
 * @subpackage Taxnexcy/admin
 */

/**
 * The admin-specific functionality of the plugin.
 *
 * Defines the plugin name, version, and two examples hooks for how to
 * enqueue the admin-specific stylesheet and JavaScript.
 *
 * @package    Taxnexcy
 * @subpackage Taxnexcy/admin
 * @author     George Nicolaou <info@georgenicolaou.me>
 */
class Taxnexcy_Admin {

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
	 * @param      string    $plugin_name       The name of this plugin.
	 * @param      string    $version    The version of this plugin.
	 */
        public function __construct( $plugin_name, $version ) {

                $this->plugin_name = $plugin_name;
                $this->version = $version;
        }

	/**
	 * Register the stylesheets for the admin area.
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

                // Log that admin styles are being enqueued for easier debugging.
                Taxnexcy_Logger::log( 'Enqueuing admin styles' );

                wp_enqueue_style( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'css/taxnexcy-admin.css', array(), $this->version, 'all' );

        }

	/**
	 * Register the JavaScript for the admin area.
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

                // Log that admin scripts are being enqueued.
                Taxnexcy_Logger::log( 'Enqueuing admin scripts' );

                wp_enqueue_script( $this->plugin_name, plugin_dir_url( __FILE__ ) . 'js/taxnexcy-admin.js', array( 'jquery' ), $this->version, false );

        }

        /**
         * Enqueue Fluent Forms admin CSS on order screens.
         */
        public function enqueue_ff_admin_css() {
                // Only enqueue on WooCommerce order screens.
                if ( 'shop_order' === ( get_current_screen()->id ?? '' ) ) {
                        Taxnexcy_Logger::log( 'Enqueuing Fluent Forms admin CSS on order screen' );
                        wp_enqueue_style(
                                'fluent-forms-admin',
                                FLUENTFORM_PLUGIN_URL . 'assets/css/fluent-forms-admin.css',
                                array(),
                                FLUENTFORM_VERSION
                        );
                }
        }

        /**
         * Register Taxnexcy admin menu and subpages.
         */
        public function add_admin_menu() {
                // Register the main menu page.
                add_menu_page(
                        'Taxnexcy',
                        'Taxnexcy',
                        'manage_options',
                        'taxnexcy',
                        array( $this, 'render_settings_page' ),
                        'dashicons-cart'
                );

                // Register subpages for settings and the log viewer.
                add_submenu_page(
                        'taxnexcy',
                        'Form Product Mapping',
                        'Form Product Mapping',
                        'manage_options',
                        'taxnexcy',
                        array( $this, 'render_settings_page' )
                );

                add_submenu_page(
                        'taxnexcy',
                        'Log',
                        'Log',
                        'manage_options',
                        'taxnexcy-log',
                        array( $this, 'render_log_page' )
                );

                Taxnexcy_Logger::log( 'Admin menus registered' );
        }

        /**
         * Render the log page contents.
         */
        public function render_log_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                // Fetch the log lines and render them in a basic table.
                $logs = Taxnexcy_Logger::get_logs();
                Taxnexcy_Logger::log( 'Rendering admin log page' );
                include plugin_dir_path( __FILE__ ) . 'partials/taxnexcy-log-page.php';
        }

        /**
         * Render the settings page.
         */
        public function render_settings_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                // Retrieve any existing form to product mappings from the database.
                $mappings = get_option( TAXNEXCY_FORM_PRODUCTS_OPTION, array() );

                $forms = array();
                if ( class_exists( '\\FluentForm\\App\\Models\\Form' ) ) {
                        try {
                                $forms = \FluentForm\App\Models\Form::select( 'id', 'title' )
                                        ->orderBy( 'title', 'ASC' )
                                        ->get();
                        } catch ( Exception $e ) {
                                $forms = array();
                        }
                }

                $products = array();
                if ( function_exists( 'wc_get_products' ) ) {
                        $products = wc_get_products( array(
                                'limit'   => -1,
                                'status'  => 'publish',
                                'orderby' => 'title',
                                'order'   => 'ASC',
                        ) );
                }

                Taxnexcy_Logger::log( 'Rendering settings page' );
                include plugin_dir_path( __FILE__ ) . 'partials/taxnexcy-settings-page.php';
        }

        /**
         * Handle clearing the log.
         */
        public function handle_clear_log() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( 'Forbidden' );
                }

                // Protect against CSRF.
                check_admin_referer( 'taxnexcy_clear_log' );

                // Clear existing log entries then record the action.
                Taxnexcy_Logger::clear();
                Taxnexcy_Logger::log( 'Plugin log cleared', array( 'user_id' => get_current_user_id() ) );

                wp_redirect( admin_url( 'admin.php?page=taxnexcy-log' ) );
                exit;
        }

        /**
         * Handle saving form to product mappings.
         */
        public function handle_save_mappings() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( 'Forbidden' );
                }

                // Verify intent before processing the submitted mappings.
                check_admin_referer( 'taxnexcy_save_mappings' );

                $forms    = isset( $_POST['taxnexcy_forms'] ) ? array_map( 'intval', (array) $_POST['taxnexcy_forms'] ) : array();
                $products = isset( $_POST['taxnexcy_products'] ) ? array_map( 'intval', (array) $_POST['taxnexcy_products'] ) : array();

                // Build an associative array of form ID => product ID pairs.
                $mappings = array();
                foreach ( $forms as $index => $form_id ) {
                        $product_id = $products[ $index ] ?? 0;
                        if ( $form_id && $product_id ) {
                                $mappings[ $form_id ] = $product_id;
                        }
                }

                update_option( TAXNEXCY_FORM_PRODUCTS_OPTION, $mappings );

                Taxnexcy_Logger::log( 'Saved form/product mappings', array( 'mappings' => $mappings, 'user_id' => get_current_user_id() ) );

                wp_redirect( admin_url( 'admin.php?page=taxnexcy' ) );
                exit;
        }

}
