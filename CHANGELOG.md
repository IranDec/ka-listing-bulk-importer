# Changelog

All notable changes to this plugin are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2.7.1] - Current release

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
