<?php

/**
 * The plugin bootstrap file
 *
 * This file is read by WordPress to generate the plugin information in the plugin
 * admin area. This file also includes all of the dependencies used by the plugin,
 * registers the activation and deactivation functions, and defines a function
 * that starts the plugin.
 *
 * @link              https://georgenicolaou.me
 * @since             1.0.0
 * @package           Taxnexcy
 *
 * @wordpress-plugin
 * Plugin Name:       Taxnex Cyprus
 * Plugin URI:        https://georgenicolaou.me/taxnexcy
 * Description:       Creates WooCommerce user from FluentForms submission and redirects to checkout
 * Version:           1.7.27
 * Author:            George Nicolaou
 * Author URI:        https://georgenicolaou.me/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       taxnexcy
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Currently plugin version.
 * Start at version 1.0.0 and use SemVer - https://semver.org
 * Rename this for your plugin and update it as you release new versions.
 */
define( 'TAXNEXCY_VERSION', '1.7.27' );

/**
 * Map Fluent Forms IDs to WooCommerce product IDs.
 * Example:
 * define( 'TAXNEXCY_FORM_PRODUCTS', array( 10 => 123, 20 => 456 ) );
 */
if ( ! defined( 'TAXNEXCY_FORM_PRODUCTS' ) ) {
    define( 'TAXNEXCY_FORM_PRODUCTS', array() );
}

/**
 * Option key used to store form to product mappings.
 */
define( 'TAXNEXCY_FORM_PRODUCTS_OPTION', 'taxnexcy_form_products' );

/**
 * Disable automatic redirect to the payment page after form submission.
 * Define TAXNEXCY_DISABLE_REDIRECT as true in wp-config.php to keep users on the form page.
 */
if ( ! defined( 'TAXNEXCY_DISABLE_REDIRECT' ) ) {
    define( 'TAXNEXCY_DISABLE_REDIRECT', false );
}

/**
 * The code that runs during plugin activation.
 * This action is documented in includes/class-taxnexcy-activator.php
 */
function activate_taxnexcy() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-taxnexcy-activator.php';
	Taxnexcy_Activator::activate();
}

/**
 * The code that runs during plugin deactivation.
 * This action is documented in includes/class-taxnexcy-deactivator.php
 */
function deactivate_taxnexcy() {
	require_once plugin_dir_path( __FILE__ ) . 'includes/class-taxnexcy-deactivator.php';
	Taxnexcy_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_taxnexcy' );
register_deactivation_hook( __FILE__, 'deactivate_taxnexcy' );

/**
 * The core plugin class that is used to define internationalization,
 * admin-specific hooks, and public-facing site hooks.
 */
require_once plugin_dir_path( __FILE__ ) . 'includes/class-taxnexcy-logger.php';
require plugin_dir_path( __FILE__ ) . 'includes/class-taxnexcy.php';

// Load the plugin update checker and configure updates from GitHub.
require_once plugin_dir_path( __FILE__ ) . 'plugin-update-checker/plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$taxnexcy_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/GeorgeWebDevCy/taxnexcy/',
    __FILE__,
    'taxnexcy'
);
$taxnexcy_update_checker->setBranch('main');
$taxnexcy_update_checker->getVcsApi()->enableReleaseAssets();
$token = defined('TAXNEXCY_GITHUB_TOKEN') ? TAXNEXCY_GITHUB_TOKEN : getenv('TAXNEXCY_GITHUB_TOKEN');
if ( ! empty( $token ) ) {
    $taxnexcy_update_checker->setAuthentication( $token );
}

/**
 * Resolve product ID for a given Fluent Forms submission.
 *
 * @param int   $default   Default product ID.
 * @param array $form      Form settings.
 * @param array $form_data Submitted form data.
 * @return int Product ID.
 */
function taxnexcy_product_mapping( $default, $form, $form_data ) {
    if ( is_array( $form ) && isset( $form['id'] ) ) {
        $form_id = intval( $form['id'] );
    } elseif ( is_object( $form ) && isset( $form->id ) ) {
        $form_id = intval( $form->id );
    } else {
        $form_id = 0;
    }
    $mappings = get_option( TAXNEXCY_FORM_PRODUCTS_OPTION, array() );

    if ( $form_id && isset( $mappings[ $form_id ] ) ) {
        return intval( $mappings[ $form_id ] );
    }

    if ( $form_id && isset( TAXNEXCY_FORM_PRODUCTS[ $form_id ] ) ) {
        return intval( TAXNEXCY_FORM_PRODUCTS[ $form_id ] );
    }

    return $default;
}
add_filter( 'taxnexcy_product_id', 'taxnexcy_product_mapping', 10, 3 );

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_taxnexcy() {

        $plugin = new Taxnexcy();
        Taxnexcy_Logger::log( 'Plugin initialised' );
        $plugin->run();

}
run_taxnexcy();
