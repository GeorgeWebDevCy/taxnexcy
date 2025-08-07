<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://georgenicolaou.me
 * @since      1.0.0
 *
 * @package    Taxnexcy
 * @subpackage Taxnexcy/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Taxnexcy
 * @subpackage Taxnexcy/includes
 * @author     George Nicolaou <info@georgenicolaou.me>
 */
class Taxnexcy {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Taxnexcy_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'TAXNEXCY_VERSION' ) ) {
			$this->version = TAXNEXCY_VERSION;
               } else {
                       $this->version = '1.0.2';
		}
		$this->plugin_name = 'taxnexcy';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();

	}

/**
 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Taxnexcy_Loader. Orchestrates the hooks of the plugin.
	 * - Taxnexcy_i18n. Defines internationalization functionality.
	 * - Taxnexcy_Admin. Defines all hooks for the admin area.
	 * - Taxnexcy_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-taxnexcy-loader.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-taxnexcy-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-taxnexcy-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-taxnexcy-public.php';

/**
 * Handles Fluent Forms submissions.
 */
require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-taxnexcy-fluentforms.php';

		$this->loader = new Taxnexcy_Loader();

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Taxnexcy_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Taxnexcy_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

               $plugin_admin = new Taxnexcy_Admin( $this->get_plugin_name(), $this->get_version() );

               $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
               $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );
               $this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_ff_admin_css' );
               $this->loader->add_action( 'admin_menu', $plugin_admin, 'add_admin_menu' );
               $this->loader->add_action( 'admin_post_taxnexcy_clear_log', $plugin_admin, 'handle_clear_log' );
               $this->loader->add_action( 'admin_post_taxnexcy_save_mappings', $plugin_admin, 'handle_save_mappings' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

                $plugin_public = new Taxnexcy_Public( $this->get_plugin_name(), $this->get_version() );

                $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
                $this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );
                $this->loader->add_filter( 'woocommerce_my_account_my_orders_actions', $plugin_public, 'remove_my_account_order_actions', 10, 2 );
                $this->loader->add_filter( 'woocommerce_valid_order_statuses_for_payment', $plugin_public, 'disable_order_pay_button', 10, 2 );
                $this->loader->add_filter( 'woocommerce_valid_order_statuses_for_cancel', $plugin_public, 'disable_order_cancel_button', 10, 2 );

                $fluentforms = new Taxnexcy_FluentForms( $this->get_version() );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Taxnexcy_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
