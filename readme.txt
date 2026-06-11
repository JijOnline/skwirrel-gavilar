=== Skwirrel Sync for Gavilar ===
Contributors: jijonline
Requires at least: 6.4
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.1
License: GPLv2 or later

One-way sync of products from Skwirrel PIM into a custom post type.

== Description ==

Syncs products, categories, custom features and attachments from the Skwirrel PIM (https://skwirrel.eu) into the Gavilar WordPress site. Read-only on the WP side — the PIM is the source of truth.

* Daily delta sync via WP-Cron using Skwirrel's `updated_on` filter
* Filtering by a configurable Skwirrel dynamic selection
* OAuth2 client_credentials authentication
* Multilingual via Polylang Pro (one post per locale, linked translations)
* Yoast SEO meta written per locale
* Inline image / attachment download with dedup
* Manual full resync via admin UI or WP-CLI

== Requirements ==

* WordPress 6.4+
* PHP 8.1+
* Polylang Pro (active and configured with site languages)
* Yoast SEO (for SEO meta — optional but recommended)

== Changelog ==

= 0.1.1 =
* Import product description (`product_long_description` → post content) and summary (`product_description` → post excerpt) from Skwirrel.
* Add Git Updater headers (GitHub Plugin URI, Primary Branch) so the plugin can update via Git Updater.

= 0.1.0 =
* Initial release.
