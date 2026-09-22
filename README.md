# KA Schindler – Listing Bulk Importer

A WordPress plugin for [ListingPro](https://wordpress.org/themes/listingpro/) that bulk-imports business listings — either from a CSV file, or auto-discovered by place + category using Google Maps, Claude, Gemini or ChatGPT.

Built by [Mohammad Babaei](https://adschi.com) for Klima- und Anlagentechnik Schindler GmbH.

## Features

- **CSV bulk import** — map columns to core fields (title, description, category, location, tags), contact/meta fields (address, phone, WhatsApp, email, website, social links), and photos (featured + gallery), with a preview step before anything is written.
- **Discover tool** — search for businesses by city + category using:
  - **Google Maps** (Places API) — live, current results.
  - **Claude** (Anthropic), **Gemini** (Google AI), **ChatGPT** (OpenAI) — AI-suggested results, clearly labeled as such since they answer from model knowledge rather than a live search.
- **Live model lists** — once an AI provider's key is saved and verified, its current list of available models is fetched from the provider's own API, with a per-model cost estimate you control.
- **Cost tracking** — set an estimated cost per result for each provider/model; the plugin keeps a running estimated spend counter per provider.
- **Scheduled Discover searches** — save a recurring city + category + source combo (daily or weekly) via WP-Cron. New results are imported as "Pending" for review; duplicates and uncertain matches are always left for manual review.
- **Preview & existing-row detection** — see exactly what will be created or skipped before committing, and export the preview batch as CSV.
- **One-click undo** — every import is logged so it can be reverted as a whole.
- **Per-user isolation** — uploads and previews are scoped per admin user so two people don't collide.

## Requirements

- WordPress 5.8+
- PHP 7.4+
- The [ListingPro](https://wordpress.org/themes/listingpro/) theme (or any theme registering the `listing`, `listing-category`, `location`, and `list-tags` taxonomies/post type used by this plugin).

## Installation

1. Upload the `ka-listing-bulk-importer` folder to `/wp-content/plugins/`, or install the plugin ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** menu in WordPress.
3. Go to **Listings → Bulk Import Settings** to add API keys for the data sources you want to use (Google Maps and/or Claude/Gemini/ChatGPT).
4. Use **Listings → Bulk Import** to import from a CSV, or **Listings → Discover** to search for businesses to import.

## Usage

### CSV import
1. Download the CSV template from the Bulk Import screen.
2. Fill in your rows (title is the only required field).
3. Upload the file, map its columns to plugin fields, preview the results, then import.

### Discover
1. Pick a data source, enter a city and category (or "all categories").
2. Review results in the preview — AI-sourced rows are flagged so addresses/phone numbers can be double-checked before publishing.
3. Import the rows you want, or set up a schedule so the same search runs automatically.

### Settings
Each data source has its own API key, live status check, and (for AI providers) a live model list and per-model cost estimate. See the in-app "How to get a key" instructions on the Settings screen for each provider.

## Support

For issues or feature requests, contact the author: [adschi.com](https://adschi.com).

## License

Proprietary — licensed for use by Klima- und Anlagentechnik Schindler GmbH and its authorized distribution channel(s). Not for redistribution without permission from the author.

See [CHANGELOG.md](CHANGELOG.md) for version history.
