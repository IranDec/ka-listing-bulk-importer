<?php
/**
 * Plugin Name: KA Schindler - Listing Bulk Importer
 * Description: Bulk-import ListingPro business listings — either from a CSV file, or auto-discovered by place + category from Google Maps, Claude, Gemini or ChatGPT. Each provider's own live model list loads automatically once its key is saved, with a per-model cost estimate. Preview every row before anything is written, see which rows already exist, and undo a whole import in one click. Built for Klima- und Anlagentechnik Schindler GmbH.
 * Version: 3.2.1
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: Mohammad Babaei
 * Author URI: https://adschi.com
 * Text Domain: ka-listing-bulk-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_Listing_Bulk_Importer {

	const VERSION_FALLBACK   = '3.2.0'; // used only if the header comment can't be read for some reason
	const NONCE_ACTION      = 'ka_lbi_action';
	const SETTINGS_NONCE     = 'ka_lbi_settings';
	const DISCOVER_NONCE     = 'ka_lbi_discover';
	const CAP                = 'edit_others_posts'; // only users who can edit others' listings may import
	const SETTINGS_CAP       = 'manage_options'; // API keys are site-wide secrets — admins only
	const POST_TYPE          = 'listing';
	const TAX_CATEGORY       = 'listing-category';
	const TAX_LOCATION       = 'location';
	const TAX_TAGS           = 'list-tags';
	const OPTION_LOG         = 'ka_lbi_import_log';
	const OPTION_LEGACY_KEY  = 'ka_lbi_google_places_key'; // pre-2.3 single-provider key, migrated automatically
	const OPTION_KEY_PREFIX    = 'ka_lbi_key_';    // + provider => API key
	const OPTION_MODEL_PREFIX  = 'ka_lbi_model_';  // + provider => model id (AI providers only)
	const OPTION_STATUS_PREFIX = 'ka_lbi_status_'; // + provider => last test result
	const OPTION_COST_PREFIX   = 'ka_lbi_cost_';   // + provider (+ '_' + model for AI providers) => admin-entered $ estimate per result
	const OPTION_MODELLIST_PREFIX = 'ka_lbi_modellist_'; // + provider => cached list of models fetched from the provider's own API
	const OPTION_SPEND_PREFIX  = 'ka_lbi_spend_';  // + provider => running estimated total spend
	const OPTION_DISCOVER_LOG  = 'ka_lbi_discover_log'; // recent Discover searches, so the same combo isn't re-run by accident
	const OPTION_SCHEDULES     = 'ka_lbi_schedules';    // saved recurring Discover searches
	const OPTION_SCHEDULE_LOG  = 'ka_lbi_schedule_log'; // per-run history: what happened, when, and why (or why not)
	const SCHEDULE_LOG_MAX     = 60;
	const SCHEDULE_NONCE       = 'ka_lbi_schedule';
	const CRON_HOOK            = 'ka_lbi_scheduled_discover';
	const MAX_DISCOVER_COMBOS  = 24; // cities × categories cap for one *interactive* (synchronous, with a preview) run
	const OPTION_JOB           = 'ka_lbi_bulk_job';       // the one background "run everything" Discover job, if any is in progress
	const JOB_CRON_HOOK        = 'ka_lbi_process_job_batch';
	const JOB_BATCH_SIZE       = 2;  // city×category combinations processed per background tick
	const JOB_TICK_DELAY       = 25; // seconds between ticks when WP-Cron alone is driving it (no admin watching)
	const JOB_MIN_GAP          = 8;  // seconds between ticks when the admin has the status card open and it's polling live — faster, since a person is watching
	const JOB_LOCK_KEY         = 'ka_lbi_job_lock';      // short-lived lock so a live poll and a WP-Cron tick can never process the same batch twice
	const OPTION_PHOTO_JOB     = 'ka_lbi_photo_job';      // the one background "backfill every photo" job, if any is in progress
	const PHOTO_JOB_CRON_HOOK  = 'ka_lbi_process_photo_job_batch';
	const PHOTO_JOB_BATCH_SIZE = 5;  // listings checked per background tick
	const PHOTO_JOB_LOCK_KEY   = 'ka_lbi_photo_job_lock';
	const OPTION_PHOTO_LOG     = 'ka_lbi_photo_log'; // recent "backfill photos" runs, like the Discover history log
	const PHOTO_LOG_MAX        = 30;
	const PHOTO_SYNC_CAP       = 40; // at most this many listings are checked inline, in one page load; a bigger scope is decided automatically and run in the background instead
	const MAX_FILE_BYTES     = 5242880;   // 5 MB CSV
	const MAX_IMAGE_BYTES    = 10485760;  // 10 MB per photo
	const MAX_ROWS           = 2000;
	const MAX_DISCOVER_RESULTS = 60;
	const TRANSIENT_TTL      = 3600; // 1 hour
	const PHOTO_DIR_NAME     = 'ka-lbi-photos';
	const AUTHOR_NAME        = 'Mohammad Babaei';
	const AUTHOR_URL         = 'https://adschi.com';

	/** Set by search_categories_for_location() when a provider call fails, so the caller can decide how to surface it. */
