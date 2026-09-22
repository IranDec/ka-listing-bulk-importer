=== KA Schindler - Listing Bulk Importer ===
Contributors: adschi
Author: Mohammad Babaei
Author URI: https://adschi.com
Tags: listingpro, listings, bulk import, csv import, google maps, business directory
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.7.1
License: Commercial (see LICENSE.txt)

Bulk-import ListingPro business listings from a CSV file, or auto-discover them by place + category using Google Maps, Claude, Gemini or ChatGPT.

== Description ==

**Listing Bulk Importer** saves hours of manual data entry for site owners running the ListingPro directory theme. Import listings in bulk two ways:

* **CSV Import** — map your spreadsheet columns to listing fields (title, description, category, location, tags, contact details, social links, photos), preview every row before anything is written, and undo a whole import in one click if needed.
* **Discover** — search for real businesses by city and category directly from the WordPress admin, using either the live Google Maps (Places API) database, or an AI provider (Claude, Gemini, ChatGPT) for AI-suggested candidates. AI-sourced results are clearly labeled so addresses and phone numbers can be verified before publishing.

= Key features =

* Column-mapped CSV import with validation and a full preview step
* Discover: search by place + category across Google Maps, Claude, Gemini, or ChatGPT
* Live model lists for AI providers, pulled from each provider's own API once a key is verified
* Per-provider / per-model cost estimates and a running estimated spend counter
* Scheduled, recurring Discover searches (daily/weekly) that add new listings as "Pending" for review
* Existing-listing detection in preview, so you never accidentally duplicate a listing
* CSV export of a preview batch
* One-click undo for any completed import
* Per-admin-user upload isolation, so multiple admins never collide

= Requirements =

* WordPress 5.8 or newer
* PHP 7.4 or newer
* The ListingPro theme (or any theme registering the `listing` post type and its taxonomies)
* Your own API key(s) for whichever data source(s) you want to use (Google Maps and/or Claude/Gemini/ChatGPT) — this plugin does not include or resell API access

== Installation ==

1. Upload the plugin ZIP via **Plugins → Add New → Upload Plugin**, or extract it to `/wp-content/plugins/ka-listing-bulk-importer/`.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Listings → Bulk Import Settings** and add an API key for at least one data source. Saving a key tests it immediately.
4. Use **Listings → Bulk Import** for CSV imports, or **Listings → Discover** to search for listings to import.

== Frequently Asked Questions ==

= Does this plugin work with any WordPress theme? =

It is built for ListingPro and expects its `listing` post type and taxonomies (`listing-category`, `location`, `list-tags`) to be registered. It will not create listings correctly on a site without them.

= Are AI-suggested results accurate? =

Claude, Gemini and ChatGPT answer from what the model already knows, not a live web search, so results can be outdated or approximate. These rows are labeled "AI-suggested" in the preview — always verify the address and phone number before publishing. Google Maps is the only source backed by a live, current database.

= Does the plugin charge me for API usage? =

No. You provide and pay for your own API keys directly with each provider. The plugin only shows an estimated cost, based on a rate you enter yourself, to help you gauge usage before running a search.

= Can I undo an import? =

Yes. Every import is logged, and the whole batch can be reverted in one click from the Bulk Import screen.

== Changelog ==

See [CHANGELOG.md](../CHANGELOG.md) for the full version history.

= 2.7.1 =
* Fixed a "The link you followed has expired" error some users hit submitting the Discover form on sites running a caching/CDN plugin, by marking this plugin's admin screens as non-cacheable.

= 2.7.0 =
* Live per-provider model lists for AI sources, with a manual refresh action.
* Per-model and per-provider cost estimates, plus a running estimated spend counter.
* Scheduled Discover searches (daily/weekly) via WP-Cron, with a recent-run log and "Run now" action.
* CSV export of the current preview batch.

= 2.6.0 =
* Multi-provider Discover (Google Maps, Claude, Gemini, ChatGPT).
* CSV bulk import with column mapping and preview.
* Existing-row detection and one-click undo.

== Support ==

For support, licensing questions, or feature requests, contact the author at https://adschi.com.
