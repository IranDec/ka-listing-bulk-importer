# KA Schindler – Listing Bulk Importer 4

Grow and maintain a [ListingPro](https://themeforest.net/item/listingpro-multipurpose-directory-theme/19386460) business directory: discover businesses with **Google Places API (New)** or AI, import CSV files, approve new listings in a **review queue**, **enrich** existing listings from their own websites, find **gaps and duplicates**, and keep the **fa / de / en** versions in step with Polylang and KA Translate.

**Version:** 4.1.0 · **Requires:** WordPress 6.0+, PHP 7.4+ (tested on PHP 8.4), ListingPro · **Author:** [Mohammad Babaei](https://adschi.com)

---

## Screens (Listings → Bulk Importer)

| Tab | What it does |
| --- | --- |
| **Overview** | Listing count per language, review backlog, profile completeness (photo, phone, e-mail, website, address, map), data-source status and spend, recent imports with one-click undo. |
| **Discover** | Pick cities (chips, with a free field for new places) and categories (chips show how many listings already exist and which were searched in the last 30 days), choose a source, run. Up to 24 searches end in a preview; bigger runs become a background job with live progress. |
| **Review** | Every pending listing - imported or submitted - with source badge, completeness dots, duplicate warning. Publish, enrich or reject one by one or in bulk. |
| **CSV import** | Upload (comma/semicolon, UTF-8 or old Excel encoding), automatic column mapping (English, German, Persian headers), default city/category, the same preview as Discover. |
| **Enrich** | Fill empty fields of existing listings: e-mail, phone, social links, WhatsApp, photo, logo, tagline from the business website (home + Impressum/contact page), and address, map position, opening hours, website from Google. Never overwrites. |
| **Quality** | Coverage matrix (city × main category, click a gap to discover it), lists of listings missing a field, duplicate groups with merge, ListingPro repair tool. |
| **Schedules** | Recurring searches (daily/weekly). A due schedule starts a background search; new businesses arrive as Pending. |
| **Settings** | Keys (never shown again after saving), models, cost estimates, search phrase, search language, languages, notifications, enrichment defaults, photo optimization. |

## How it fits the multilingual site

- New listings are written in the import language (Polylang default, Persian on hamsaye.de). Categories and cities are matched in that language - a German or English name or slug in a CSV is mapped to its translation.
- Searches use the **German names** of the category and city ("آموزش موسیقی و هنر" in "هامبورگ" → "Musik- und Kunstunterricht persisch iranisch Hamburg").
- When an imported listing is published, KA Translate is asked for the other language versions.
- KA Translate copies (`_kt_gen`) are never counted, flagged as duplicates or enriched themselves; data filled into a source listing is copied to its translations.

## Data sources

| Source | Data | Cost |
| --- | --- | --- |
| Google Places API (New) | live: name, address, coordinates, phone, website, opening hours, rating, photo | one Text Search request per search (+1 per imported photo) |
| Gemini / Claude / ChatGPT | model knowledge, always flagged "verify before publishing" | one request per search |
| Business website | e-mail, phone, socials, photo, logo, tagline | free |

Google setup: Cloud Console → create project → link billing → enable **Places API (New)** → create an API key restricted to that API, with a daily quota.

## Safety

- Nothing is written before the preview; background runs and schedules only add clearly new businesses as **Pending**.
- Duplicate check by Google place id, phone (last 9 digits), website domain and name (name only within the same city).
- Undo per import (also trashes the KA Translate copies); merges move duplicates to the trash (restorable).
- Nonces and capability checks on every action, keys admin-only and never printed, SSRF-safe requests (`wp_safe_remote_get`), uploaded images verified, CSV export protected against spreadsheet formulas.
- Background jobs run in small ticks (cron + live polling) with a lock, so they never tie up the server.

## Updating from 3.x

Automatic. Keys, models, cost estimates, spend counters, schedules, the discover/photo/schedule logs and the undo history are kept. A running 3.x job continues. The Google key must work with **Places API (New)** - it is re-tested on the first save.

## Code layout

```
ka-listing-bulk-importer/
├── ka-listing-bulk-importer.php   bootstrap
├── includes/   plugin, settings, lang, store, media, google, providers, batch, csv,
│               jobs, discover, enrich, quality, review, schedules, admin
├── admin/views/ dashboard, discover, review, import, photos (enrich), quality, schedules, settings
├── assets/      admin.css, admin.js
└── languages/   ka-listing-bulk-importer.pot
```

## License

Proprietary - licensed for use by Klima- und Anlagentechnik Schindler GmbH and its authorized distribution channel(s). Not for redistribution without permission from the author.

See [CHANGELOG.md](CHANGELOG.md).
