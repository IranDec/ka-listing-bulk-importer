# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## 4.4.0 - 2026-11-25

### Changed
- **Review queue redesigned**: one card per listing with a larger image (or the category image the listing will get, marked as such, or "no photo"), contact data read from ListingPro's options as well (address, phone, e-mail, website - user submissions keep them there), an excerpt of the description with links marked in red, titles in their own reading direction, and labelled buttons **Publish** / **Reject…** with Preview · Edit · Enrich below.
- Bulk bar: "Reject selected" is now "Move selected to the trash" with the note that no e-mail is sent.

## 4.3.0 - 2026-11-25

### Added
- Moderation where listings are handled every day, not only in the review queue:
  - **Listings list**: column "Review" (submitter e-mail, automatic checks, last time sent back) and the row actions **Publish** and **Reject with a reason…** for pending and draft listings;
  - **listing edit screen**: box "Review" in the sidebar with submitter, checks, history and the buttons **Publish** / **Reject with a reason…**.
- The reject dialog (`KA_LBI_Moderation::dialog()`) and the new publish action (`admin-post.php?action=ka_lbi_approve`) return to the screen they were used on; after "reject completely" from the edit screen the Listings list opens.

## 4.2.0 - 2026-11-25

### Added
- **Default image per category**: field "Default image" on the listing category screens (media library picker) and a column in the category list. A listing without photo and gallery shows the image of its category - or of the nearest parent category that has one - on the public site: lists, the listing page and social previews; without any, ListingPro's default image stays. Admin screens, the review checks, imports, cron and REST still see that the listing has no photo of its own (filter `post_thumbnail_id`, class `KA_LBI_Category_Images`).

## 4.1.0 - 2026-11-25

### Added
- **Moderation of submitted listings** in the review queue: each listing shows who submitted it (name, e-mail, registration, number of listings, link to the user) and automatic checks (no photo, short description, links in the description, no contact details, address outside Germany).
- **Reject with reasons**: dialog with predefined reasons (checks already ticked) and a note; "send back for changes" (draft, the e-mail links to the ListingPro edit page; saving puts it back into review) or "reject completely" (trash). The submitter gets an e-mail in their language (Persian translation included); every rejection is kept in the listing's history and shown in the queue.
- **No links in descriptions** (switch in the review queue, on by default): the submit / edit form shows a hint and refuses to submit while the description contains a link or web address; links are also removed when such a form is saved by a non-editor (imports, cron and translations are not affected).
- Persian translation of the new texts (`languages/ka-listing-bulk-importer-fa_IR.po/.mo`).

## 4.0.1 - 2026-11-25

- Header `Update URI` points to GitHub: updates come from the repository through KA Hub (menu "KA Modules"), and WordPress never offers an unrelated plugin of the same name from wordpress.org.

## [4.0.0] - 2026-11-17

Complete rebuild. All settings, keys, schedules, logs and the undo history are kept.

### Added
- New admin app under Listings -> Bulk Importer with tabs: Overview, Discover, Review, CSV import, Enrich, Quality, Schedules, Settings.
- Google Places API (New): one request returns address, coordinates, phone, website, opening hours, rating and photo; photos are fetched only for imported rows; closed businesses are left out.
- Polylang support: import language, categories/cities matched in that language, German names used in search queries, KA Translate asked for the other languages when an imported listing is published, enriched data copied to the translations.
- Review queue for all pending listings with bulk publish / enrich / reject, duplicate warnings and completeness dots.
- Enrichment from the business website (home + Impressum/contact page): e-mail, phone, social links, WhatsApp, photo, logo, tagline; plus Google: address, map position, opening hours, website. Only empty fields are filled.
- Quality: coverage matrix city x main category (click a gap to discover it), missing-data lists, duplicate groups with merge or "different businesses".
- Discover: city and category chips with existing-listing counts and "searched in the last 30 days" markers, live search/cost summary.
- Opening hours imported into ListingPro's business hours; Google place id stored for exact duplicate detection.
- CSV: semicolon/tab files, old Excel encoding, German and Persian header names, default city/category.

### Changed
- Background jobs run through one generic runner with live progress; schedules start a background job instead of one long request.
- Duplicate detection uses place id, phone digits, website domain and name (bilingual titles split; name-only matches only within the same city); KA Translate copies are never duplicates.
- API keys are no longer printed into the settings page.
- Missing cities can be created automatically (setting).

### Removed
- The legacy Places API (cannot be enabled for new keys) and the stale duplicate plugin file in the repository root.

## [2.8.1]

### Changed
- Discover no longer hard-rejects a multi-city/"all categories" run once it passes 24 city/category combinations. It now runs the whole batch in one go (up to a 200-combination safety ceiling) and adds a note to the results if it exceeded the normal recommended batch size, instead of stopping the search entirely.

## [2.7.1]

### Fixed
- "The link you followed has expired" error when submitting the Discover form on sites running a caching/CDN plugin — the plugin's own admin screens are now marked non-cacheable (`DONOTCACHEPAGE`, no-cache headers) so a stale page with an already-expired nonce is never served.

## [2.7.0]

### Added
- Live, per-provider model lists for AI sources (Claude, Gemini, ChatGPT), fetched from each provider's own API once a working key is saved, with a "Refresh model list" action.
- Per-model cost estimates for AI providers (cost is remembered separately for each model), and a per-provider cost estimate for Google Maps.
- Running estimated spend counter per provider, with a manual reset.
- Scheduled Discover searches (daily/weekly) via WP-Cron, with a recent-run log to avoid re-searching (and re-billing) the same city/category combo, and a "Run now" action for testing a schedule immediately.
- CSV export of the current preview batch before import.

### Changed
- Settings screen reorganized into one card per data source, showing key status, model selection, and cost/spend together.

## [2.6.0] - Previous baseline

- Multi-provider Discover: search by place + category across Google Maps, Claude, Gemini, and ChatGPT, with AI-suggested results clearly labeled as such.
- CSV bulk import with column mapping, required/optional field validation, and a preview step before writing.
- Support for core listing fields (title, description, category, location, tags), contact/meta fields (address, coordinates, phone, WhatsApp, email, website, social links), and photo fields (featured image + gallery, by URL or server-side filename).
- Existing-row detection in preview, and one-click undo of a completed import.
- Per-admin-user upload/preview isolation.
- Automatic one-time migration of the pre-2.3 single Google Places key into the multi-provider key storage.

---

Earlier history predates this changelog and is not separately documented.
