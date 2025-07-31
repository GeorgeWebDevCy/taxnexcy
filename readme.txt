=== Taxnex Cyprus ===
Contributors: GeorgeWebDevCy
Donate link: https://georgenicolaou.me/
Tags: fluentforms, woocommerce, jcc
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.5.0
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Taxnex Cyprus converts FluentForms submissions into WooCommerce customers and orders and redirects users to JCC for payment.

== Description ==
This plugin integrates FluentForms with WooCommerce and JCC to create orders and process payments automatically.

== Installation ==
1. Upload the `taxnexcy` plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Automatic Updates ==
Taxnex Cyprus checks for updates on its GitHub repository. If the repository
is private you must provide a GitHub token so the updater can access the
latest release information.

Define the token in your `wp-config.php` file:

`define( 'TAXNEXCY_GITHUB_TOKEN', 'your-personal-access-token' );`

Alternatively, set an environment variable named `TAXNEXCY_GITHUB_TOKEN`.

== Changelog ==
= 1.5.0 =
* Map individual FluentForms to specific WooCommerce products using the `TAXNEXCY_FORM_PRODUCTS` constant.
= 1.4.0 =
* Add admin log viewer and verbose logging throughout the plugin.
= 1.3.1 =
* Enable GitHub release assets for updates.
= 1.3.0 =
* Bump plugin version.
= 1.2.0 =
* Store Fluent Forms submission data in the WooCommerce order and show it in order emails.
= 1.1.0 =
* Create WooCommerce customers and orders from Fluent Forms submissions and redirect users to JCC for payment.
= 1.0.2 =
* Fix update checker authentication handling.

= 1.0.1 =
* Update plugin version.

= 1.0.0 =
* Initial release.
