=== Taxnex Cyprus ===
Contributors: GeorgeWebDevCy
Donate link: https://georgenicolaou.me/
Tags: fluentforms, woocommerce, jcc
Requires at least: 5.0
Tested up to: 6.5
Stable tag: 1.8.4
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Taxnex Cyprus converts FluentForms submissions into WooCommerce customers.

== Description ==
This plugin integrates FluentForms with WooCommerce to create customers and process payments automatically.

== Installation ==
1. Upload the `taxnexcy` plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the 'Plugins' menu in WordPress.

== Automatic Updates ==
Taxnex Cyprus checks for updates on its public GitHub repository, so no authentication token is required.

== Changelog ==
= 1.8.4 =
* Bump plugin version for new release.

= 1.8.3 =
* Revert plugin to the stable 1.8.1 code base and restore the previous form mapping.

= 1.8.1 =
* Replace user and dynamic smartcodes inside generated PDF field labels.

= 1.7.99 =
* Parse custom Fluent Forms shortcodes in generated PDFs.

= 1.7.98 =
* Add comments and verbose admin logging.

= 1.7.97 =
* Revert plugin to stable 1.7.82 code base.

= 1.7.90 =
* Revert plugin to stable 1.7.82 code base.

= 1.7.82 =
* Replace smartcodes in form labels so user and dynamic values appear in PDFs.
* Append WooCommerce order IDs to generated PDF filenames.
= 1.7.80 =
* Load PDF feed settings from `fluentform_form_meta` table to avoid missing table errors.
* Bump PDF helper to version 1.1.11.
= 1.7.79 =
* Remove hardcoded PDF logo and template overrides so feed settings apply.
* Use the "General" feed when generating PDFs.
* Bump PDF helper to version 1.1.9.
= 1.7.76 =
* Ensure custom PDF template is used via TemplateManager settings.
* Bump PDF helper to version 1.1.8.

= 1.7.75 =
* Load a custom template for generated PDFs so style changes are reflected.
* Bump PDF helper to version 1.1.7.

= 1.7.74 =
* Replace user and dynamic smartcodes inside generated PDFs.
* Apply form colours and titles to PDF output.

= 1.7.73 =
* Use Fluent Forms smartcodes for PDF file names and headings.
* Bump PDF helper to version 1.1.6.

= 1.7.72 =
* Set PDF title to "Taxnex TaxisNet Submission for {user.name}".
* Use Divi theme logo and site colours in generated PDFs.

= 1.7.71 =
* Generate landscape PDFs with basic colour styling.
* Replace dynamic tags with actual submission values in generated PDFs.

= 1.7.70 =
* Store the correct Fluent Forms ID in the WooCommerce session map.

= 1.7.69 =
* Prevent duplicate plugin initialisation when the file is loaded more than once.

= 1.7.68 =
* Remove redundant checkout redirect handling now managed by Fluent Forms.

= 1.7.67 =
* Restrict PDF generation to Form ID 4 to prevent missing form errors.

= 1.7.66 =
* Generate PDFs with Fluent Forms' default template when GlobalPdfManager lacks generation methods.
= 1.7.64 =
* Add logging for WooCommerce order status changes, cart loads, and cart clearing.
* Record order action restrictions and cart resets for easier debugging.

= 1.7.63 =
* Replace Fluent Forms PDF attachment helper with enhanced version 1.1.0.

= 1.7.62 =
* Provide fallback mapping for Form 4 to product 100506.

= 1.7.61 =
* Improve checkout redirect logic and verify product is purchasable.

= 1.7.60 =
* Default to fallback product ID 100506 when mapping is missing and log the decision.

= 1.7.59 =
* Log cart contents and session data to debug checkout issues.

= 1.7.58 =
* Try multiple Fluent Forms PDF generator signatures to prevent fatal errors during checkout.

= 1.7.57 =
* Empty WooCommerce cart when accessing page ID 100507.

= 1.7.56 =
* Skip adding product to cart if it is already present and log the cart state.

= 1.7.55 =
* Avoid duplicate add-to-cart errors for individually sold products.

= 1.7.54 =
* Log WooCommerce checkout data and errors to help debug order processing failures.
= 1.7.53 =
* Prevent checkout failures by catching errors during Fluent Forms PDF generation.

= 1.7.52 =
* Handle Fluent Forms PDF container initialization more defensively.

= 1.7.51 =
* Add verbose logging around Fluent Forms PDF generation to help diagnose failures.

= 1.7.50 =
* Resolve Fluent Forms application container before instantiating the PDF manager to avoid null parameter errors.

= 1.7.49 =
* Add detailed logging for Fluent Forms PDF manager initialization to help debug container issues.

= 1.7.48 =
* Pass container to GlobalPdfManager to prevent fatal errors during PDF generation.
* Skip abstract TemplateManager classes.
= 1.7.47 =
* Log PDF generation and email attachments using the main plugin logger.

= 1.7.46 =
* Switch to session-based Fluent Forms PDF generation, attach to Woo emails and log actions.

= 1.7.45 =
* Generate a PDF of the Fluent Form entry and attach it to WooCommerce order emails.

= 1.7.44 =
* Call Fluent Forms' renderer with the correct argument order across versions and drop legacy table fallbacks.

= 1.7.43 =
* Render Fluent Forms entries using their internal renderer for full entry details.

= 1.7.42 =
* Render full Fluent Forms single entries in WooCommerce orders and emails.
* Exclude internal Fluent Forms fields from fallback tables.
= 1.7.41 =
* Remove GitHub token requirement for updates.
= 1.7.40 =
* Preserve the field order defined in field maps when rendering repeater tables.

* Avoid fatal errors when WooCommerce is inactive by checking required functions before redirecting to checkout.
= 1.7.39 =
* Prevent fatal errors when Fluent Forms' SubmissionService class is missing.

= 1.7.37 =
* Exclude additional Fluent Forms internal fields from WooCommerce order details.

= 1.7.36 =
* Display Fluent Forms repeater tables in WooCommerce emails.

= 1.7.35 =
* Render Fluent Forms repeater fields as HTML tables and store them in WooCommerce orders.

= 1.7.34 =
* Show labels for fields inside Fluent Forms Repeat Container values.

= 1.7.33 =
* Preserve labels for Fluent Forms repeater fields.
* Support the new Repeat Container field.

= 1.7.32 =
* Add fallback labels for repeater field values.

= 1.7.31 =
* Fix missing labels for Fluent Forms repeater fields in logs and WooCommerce emails.

= 1.7.30 =
* Show labels for fields inside Fluent Forms repeater values.

= 1.7.29 =
* Use admin labels when formatting repeater field values.

= 1.7.28 =
* Display values from Fluent Forms repeater fields in WooCommerce emails.

= 1.7.27 =
* Skip internal Fluent Forms fields like the nonce, embed post ID and referer.

= 1.7.26 =
* Store Fluent Forms questions and answers in WooCommerce orders and email them as a table.

= 1.7.25 =
* Remove pay and cancel order buttons from customer order views.

= 1.7.24 =
* Create customers without generating orders and redirect directly to checkout.

= 1.7.23 =
* Redirect users to checkout with `add-to-cart` and `quantity` query parameters.
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
