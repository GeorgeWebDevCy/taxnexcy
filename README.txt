=== Taxnex Cyprus ===
Contributors: GeorgeWebDevCy
Donate link: https://georgenicolaou.me/
Tags: fluentforms, woocommerce, jcc
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.7.22
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
= 1.7.22 =
* Redirect logged-in users straight to checkout after form submission.
= 1.7.21 =
* Fix redirect and auto login issues on newer Fluent Forms versions.
= 1.7.20 =
* Automatically log users in after creating an order so they can pay immediately.
= 1.7.19 =
* Log the payment URL when creating an order and ensure Fluent Forms receives a redirect URL.

= 1.7.18 =
* Log when the redirect filter is registered and support alternate Fluent Forms hook.
= 1.7.17 =
* Add option to disable the automatic payment redirect with `TAXNEXCY_DISABLE_REDIRECT`.
= 1.7.16 =
* Detect available payment gateways and fall back if JCC is missing.
= 1.7.15 =
* Add detailed logging for payment redirect issues.
= 1.7.14 =
* Use admin field labels from Fluent Forms for saved question text.
= 1.7.13 =
* Document how question/answer order comes from Fluent Forms {all_data}.

= 1.7.12 =
* Clarify plugin description and bump version.

= 1.7.11 =
* Display Fluent Forms questions and answers in WooCommerce tables.
= 1.7.10 =
* Log saved order fields for debugging question order issues.
= 1.7.9 =
* Log raw Fluent Forms submission data for debugging.
= 1.7.8 =
* Rename table header to 'Element Label' for clarity.
= 1.7.7 =
* Handle array values from Fluent Forms submissions when storing order meta.

= 1.7.6 =
* Skip internal Fluent Forms fields and use stored question labels when displaying order data.
= 1.7.5 =
* Display Fluent Forms answers as a table in WooCommerce emails and the admin order screen.
= 1.7.4 =
* Fix storing form questions and answers in order meta.
= 1.7.3 =
* Display form question labels instead of field names in order meta.
= 1.7.2 =
* Store Fluent Forms answers in WooCommerce orders and show them in admin and emails.
= 1.7.1 =
* Fix form ID detection when Fluent Forms passes objects.
= 1.7.0 =
* Show product and form titles in dropdowns on the mapping page.
= 1.6.0 =
* Add admin page to map Fluent Forms to WooCommerce products.
* Move log viewer under the new Taxnexcy menu.
* Read mappings from saved options.
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
