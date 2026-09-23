<?php
/**
 * Plugin Name: KA Schindler - Listing Bulk Importer
 * Description: Bulk-import ListingPro business listings — either from a CSV file, or auto-discovered by place + category from Google Maps, Claude, Gemini or ChatGPT. Each provider's own live model list loads automatically once its key is saved, with a per-model cost estimate. Preview every row before anything is written, see which rows already exist, and undo a whole import in one click. Built for Klima- und Anlagentechnik Schindler GmbH.
 * Version: 3.5.1
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

	const VERSION_FALLBACK   = '3.5.0'; // used only if the header comment can't be read for some reason
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
	const JOB_BATCH_SIZE       = 1;  // city×category combinations processed per background tick — deliberately small; see JOB_MAX_RESULTS_CAP below for why
	const JOB_MAX_RESULTS_CAP  = 5;  // hard ceiling on results-per-combination actually used inside a background tick, REGARDLESS of what the form asked for. Google's Place Details is fetched once per result found, sequentially — a high "how many results" value (this plugin allows up to 60) turned into up to 60 sequential HTTP calls inside a single tick, which could tie up a PHP worker for many minutes and, combined with live polling, was enough to take the whole site down. The interactive/preview path (MAX_DISCOVER_COMBOS-limited) is unaffected — this cap applies only inside run_job_tick().
	const JOB_TICK_DELAY       = 25; // seconds between ticks when WP-Cron alone is driving it (no admin watching) — Google Maps only, see JOB_*_AI below
	const JOB_MIN_GAP          = 25; // seconds between ticks when the admin has the status card open and it's polling live — kept slow on purpose (see JOB_MAX_RESULTS_CAP) so live polling can never pile up faster than a tick can safely finish. Google Maps only.
	// The AI providers (Claude/Gemini/ChatGPT) make exactly ONE outbound HTTP call per city/category
	// combination — never multiplied per-result the way Google's Place Details lookups are — so the
	// worker-exhaustion risk that forced Google Maps this slow simply doesn't apply the same way.
	// Several times faster on purpose; still throttled, just not as hard.
	const JOB_BATCH_SIZE_AI    = 3;
	const JOB_TICK_DELAY_AI    = 8;
	const JOB_MIN_GAP_AI       = 8;
	const JOB_POLL_MS_AI       = 6000;
	const JOB_LOCK_KEY         = 'ka_lbi_job_lock';      // short-lived lock so a live poll and a WP-Cron tick can never process the same batch twice
	const OPTION_PHOTO_JOB     = 'ka_lbi_photo_job';      // the one background "backfill every photo" job, if any is in progress
	const PHOTO_JOB_CRON_HOOK  = 'ka_lbi_process_photo_job_batch';
	const PHOTO_JOB_BATCH_SIZE = 2;  // listings checked per background tick — small for the same reason as JOB_BATCH_SIZE (each listing is 2-3 sequential outbound HTTP calls)
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
	private $last_search_error = '';

	/** Data sources the Discover tool can search. */
	const PROVIDERS = array(
		'google' => array( 'label' => 'Google Maps (Places API)', 'is_ai' => false, 'default_model' => '' ),
		'claude' => array( 'label' => 'Claude (Anthropic)', 'is_ai' => true, 'default_model' => 'claude-sonnet-4-5' ),
		'gemini' => array( 'label' => 'Gemini (Google AI)', 'is_ai' => true, 'default_model' => 'gemini-2.0-flash' ),
		'openai' => array( 'label' => 'ChatGPT (OpenAI)', 'is_ai' => true, 'default_model' => 'gpt-4o-mini' ),
	);

	/**
	 * CSV-mappable fields. Key => [label, type, required]
	 * type is used for sanitization on write.
	 */
	const CORE_FIELDS = array(
		'post_title'   => array( 'label' => 'Title', 'type' => 'text', 'required' => true ),
		'post_content' => array( 'label' => 'Description', 'type' => 'richtext', 'required' => false ),
		'category'     => array( 'label' => 'Category (taxonomy)', 'type' => 'term', 'required' => false ),
		'location'     => array( 'label' => 'Location / City (taxonomy)', 'type' => 'term', 'required' => false ),
		'tags'         => array( 'label' => 'Tags (taxonomy, comma-separated)', 'type' => 'term_list', 'required' => false ),
	);

	const META_FIELDS = array(
		'gAddress'     => array( 'label' => 'Address', 'type' => 'text' ),
		'latitude'     => array( 'label' => 'Latitude', 'type' => 'float' ),
		'longitude'    => array( 'label' => 'Longitude', 'type' => 'float' ),
		'phone'        => array( 'label' => 'Phone', 'type' => 'phone' ),
		'whatsapp'     => array( 'label' => 'Whatsapp', 'type' => 'phone' ),
		'email'        => array( 'label' => 'Email', 'type' => 'email' ),
		'website'      => array( 'label' => 'Website', 'type' => 'url' ),
		'facebook'     => array( 'label' => 'Facebook', 'type' => 'url' ),
		'linkedin'     => array( 'label' => 'LinkedIn', 'type' => 'url' ),
		'twitter'      => array( 'label' => 'Twitter', 'type' => 'url' ),
		'youtube'      => array( 'label' => 'Youtube Channel Link', 'type' => 'url' ),
		'video'        => array( 'label' => 'Youtube Video URL', 'type' => 'url' ),
		'tagline_text' => array( 'label' => 'Business Tagline', 'type' => 'text' ),
	);

	/**
	 * Photo fields. Value can be either a full http(s) URL, or just a filename
	 * that has been placed in the plugin's photo intake folder on this same
	 * server (see ensure_photo_dir()).
	 */
	const IMAGE_FIELDS = array(
		'featured_photo' => array( 'label' => 'Photo (featured image)', 'type' => 'image' ),
		'gallery_photos'  => array( 'label' => 'Gallery photos (separate several with |)', 'type' => 'image_list' ),
	);

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_ka_lbi_upload', array( $this, 'handle_upload' ) );
		add_action( 'admin_post_ka_lbi_preview', array( $this, 'handle_preview' ) );
		add_action( 'admin_post_ka_lbi_import', array( $this, 'handle_import' ) );
		add_action( 'admin_post_ka_lbi_undo', array( $this, 'handle_undo' ) );
		add_action( 'admin_post_ka_lbi_template', array( $this, 'handle_template_download' ) );
		add_action( 'admin_post_ka_lbi_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_ka_lbi_discover', array( $this, 'handle_discover' ) );
		add_action( 'admin_post_ka_lbi_reset_spend', array( $this, 'handle_reset_spend' ) );
		add_action( 'admin_post_ka_lbi_refresh_models', array( $this, 'handle_refresh_models' ) );
		add_action( 'admin_post_ka_lbi_export_preview', array( $this, 'handle_export_preview' ) );
		add_action( 'admin_post_ka_lbi_save_schedule', array( $this, 'handle_save_schedule' ) );
		add_action( 'admin_post_ka_lbi_delete_schedule', array( $this, 'handle_delete_schedule' ) );
		add_action( 'admin_post_ka_lbi_run_schedule_now', array( $this, 'handle_run_schedule_now' ) );
		add_action( 'admin_post_ka_lbi_toggle_schedule', array( $this, 'handle_toggle_schedule' ) );
		add_action( 'admin_post_ka_lbi_backfill_photos', array( $this, 'handle_backfill_photos' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_due_schedules' ) );
		add_action( 'admin_post_ka_lbi_start_bulk_job', array( $this, 'handle_start_bulk_job' ) );
		add_action( 'admin_post_ka_lbi_cancel_bulk_job', array( $this, 'handle_cancel_bulk_job' ) );
		add_action( self::JOB_CRON_HOOK, array( $this, 'process_job_batch' ) );
		add_action( 'admin_post_ka_lbi_cancel_photo_job', array( $this, 'handle_cancel_photo_job' ) );
		add_action( self::PHOTO_JOB_CRON_HOOK, array( $this, 'process_photo_job_batch' ) );
		add_action( 'wp_ajax_ka_lbi_poll_job', array( $this, 'handle_job_poll' ) );
		add_action( 'admin_post_ka_lbi_dismiss_job_error', array( $this, 'handle_dismiss_job_error' ) );

		$this->maybe_migrate_legacy_key();
	}

	public static function activate() {
		self::ensure_photo_dir();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
		wp_clear_scheduled_hook( self::JOB_CRON_HOOK );
		delete_option( self::OPTION_JOB );
		wp_clear_scheduled_hook( self::PHOTO_JOB_CRON_HOOK );
		delete_option( self::OPTION_PHOTO_JOB );
	}

	/** One-time, cheap upgrade path: the 2.2.0 single "Google key" option becomes the 'google' provider key. */
	private function maybe_migrate_legacy_key() {
		$legacy = get_option( self::OPTION_LEGACY_KEY, '' );
		if ( '' === $legacy ) {
			return;
		}
		$new_key_option = self::OPTION_KEY_PREFIX . 'google';
		if ( '' === get_option( $new_key_option, '' ) ) {
			update_option( $new_key_option, $legacy, false );
		}
		delete_option( self::OPTION_LEGACY_KEY );
	}

	/** Reads the version straight out of this file's own header comment, so the footer can never drift out of sync with it. */
	private function get_version() {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$data = get_plugin_data( __FILE__, false, false );
		return ! empty( $data['Version'] ) ? $data['Version'] : self::VERSION_FALLBACK;
	}

	/** Create (once) the folder where the user can place source photos directly on this server. */
	private static function ensure_photo_dir() {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . self::PHOTO_DIR_NAME;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		// Keep the folder itself from being listed/browsed.
		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, "<?php\n// Silence is golden.\n" );
		}
		return $dir;
	}

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	private function importable_fields() {
		return array_merge( self::CORE_FIELDS, self::META_FIELDS, self::IMAGE_FIELDS );
	}

	private function verify_capability() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
	}

	/** User-scoped transient key so two admins never collide or read each other's uploads. */
	private function tkey( $suffix ) {
		return 'ka_lbi_' . get_current_user_id() . '_' . $suffix;
	}

	private function normalize_phone( $phone ) {
		return preg_replace( '/\D+/', '', (string) $phone );
	}

	/** Cost is tracked per model for the AI providers (pricing differs model to model), per-provider only for Google. */
	private function cost_option_key( $provider, $model = '' ) {
		return self::OPTION_COST_PREFIX . $provider . ( $model ? '_' . sanitize_key( $model ) : '' );
	}

	/* ------------------------------------------------------------------ */
	/* Menu / page                                                         */
	/* ------------------------------------------------------------------ */

	public function register_menu() {
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Bulk Import', 'ka-listing-bulk-importer' ),
			__( 'Bulk Import', 'ka-listing-bulk-importer' ),
			self::CAP,
			'ka-lbi-import',
			array( $this, 'render_page' )
		);
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Discover', 'ka-listing-bulk-importer' ),
			__( 'Discover', 'ka-listing-bulk-importer' ),
			self::CAP,
			'ka-lbi-discover',
			array( $this, 'render_discover_page' )
		);
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Backfill missing photos', 'ka-listing-bulk-importer' ),
			__( 'Photos', 'ka-listing-bulk-importer' ),
			self::CAP,
			'ka-lbi-photos',
			array( $this, 'render_photos_page' )
		);
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Scheduled Discover searches', 'ka-listing-bulk-importer' ),
			__( 'Schedules', 'ka-listing-bulk-importer' ),
			self::SETTINGS_CAP,
			'ka-lbi-schedules',
			array( $this, 'render_schedules_page' )
		);
		add_submenu_page(
			'edit.php?post_type=' . self::POST_TYPE,
			__( 'Bulk Import Settings', 'ka-listing-bulk-importer' ),
			__( 'Bulk Import Settings', 'ka-listing-bulk-importer' ),
			self::SETTINGS_CAP,
			'ka-lbi-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * One shared, self-contained stylesheet for all three of this plugin's
	 * admin pages. Printed once per page load, scoped under .ka-lbi-wrap so
	 * it can never leak into the rest of wp-admin.
	 */
	private function render_admin_css() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<style>
		.ka-lbi-wrap { max-width: 1180px; }
		.ka-lbi-wrap h1 { display:flex; align-items:center; gap:10px; }
		.ka-lbi-wrap h1 .dashicons { font-size:28px; width:28px; height:28px; color:#2271b1; }
		.ka-lbi-wrap .nav-tab-wrapper { margin-bottom: 18px; border-bottom-color:#dcdcde; }
		.ka-lbi-wrap .nav-tab { display:inline-flex; align-items:center; gap:6px; border-radius:6px 6px 0 0; font-weight:500; }
		.ka-lbi-wrap .nav-tab-active, .ka-lbi-wrap .nav-tab-active:focus, .ka-lbi-wrap .nav-tab-active:hover { background:#2271b1; color:#fff; border-color:#2271b1; }
		.ka-lbi-card { background:#fff; border:1px solid #dcdcde; border-radius:8px; padding:20px 24px; margin-bottom:20px; box-shadow:0 1px 2px rgba(0,0,0,.04); }
		.ka-lbi-card h2 { margin-top:0; display:flex; align-items:center; gap:8px; }
		.ka-lbi-card + .ka-lbi-card { margin-top:20px; }
		.ka-lbi-intro { color:#50575e; font-size:14px; max-width:820px; }
		.ka-lbi-wrap .form-table th { padding-left:0; }
		.ka-lbi-wrap table.widefat.striped thead th { background:#f6f7f7; }
		.ka-lbi-pill { display:inline-flex; align-items:center; gap:4px; padding:2px 10px; border-radius:999px; font-size:12px; font-weight:600; line-height:1.8; }
		.ka-lbi-pill-ok { background:#edfaef; color:#1a7a34; }
		.ka-lbi-pill-bad { background:#fbeaea; color:#b32d2e; }
		.ka-lbi-pill-warn { background:#fef8e7; color:#8a6d00; }
		.ka-lbi-pill-muted { background:#f0f0f1; color:#646970; }
		.ka-lbi-source-card { display:block; border:1px solid #dcdcde; border-radius:6px; padding:10px 14px; margin-bottom:8px; cursor:pointer; transition:border-color .15s,background .15s; }
		.ka-lbi-source-card:hover { border-color:#2271b1; }
		.ka-lbi-source-card input[type=radio] { margin-right:8px; }
		.ka-lbi-source-card input[type=radio]:checked ~ * { font-weight:600; }
		.ka-lbi-mode-card { display:block; border:1px solid #dcdcde; border-radius:6px; padding:10px 14px; margin-bottom:8px; }
		.ka-lbi-mode-card input[type=radio] { margin-right:8px; }
		.ka-lbi-existing-table img { border-radius:4px; }
		.ka-lbi-footer { margin-top:32px; padding:16px 22px; background:linear-gradient(135deg,#f6f9fc,#f0f4f8); border:1px solid #dcdcde; border-radius:8px; font-size:12px; color:#646970; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
		.ka-lbi-footer strong { color:#1d2327; }
		.ka-lbi-footer .ka-lbi-badge { background:#2271b1; color:#fff; border-radius:4px; padding:1px 8px; font-size:11px; font-weight:600; letter-spacing:.02em; }
		</style>
		<?php
	}

	/** Small nav so the screens are easy to switch between. */
	private function render_nav_tabs( $active ) {
		$this->render_admin_css();
		$tabs = array(
			'ka-lbi-import'     => __( 'Bulk Import', 'ka-listing-bulk-importer' ),
			'ka-lbi-discover'   => __( 'Discover', 'ka-listing-bulk-importer' ),
			'ka-lbi-photos'     => __( 'Photos', 'ka-listing-bulk-importer' ),
			'ka-lbi-schedules'  => __( 'Schedules', 'ka-listing-bulk-importer' ),
			'ka-lbi-settings'   => __( 'Settings', 'ka-listing-bulk-importer' ),
		);
		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			if ( in_array( $slug, array( 'ka-lbi-settings', 'ka-lbi-schedules' ), true ) && ! current_user_can( self::SETTINGS_CAP ) ) {
				continue;
			}
			$class = ( $slug === $active ) ? 'nav-tab nav-tab-active' : 'nav-tab';
			echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=' . $slug ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</h2>';
	}

	private function render_footer() {
		?>
		<div class="ka-lbi-footer">
			<span>
				<strong>KA Schindler Listing Bulk Importer</strong>
				&nbsp;<span class="ka-lbi-badge">v<?php echo esc_html( $this->get_version() ); ?></span>
			</span>
			<span>
				<?php
				printf(
					/* translators: 1: author name, 2: author link */
					esc_html__( 'Built by %1$s — %2$s', 'ka-listing-bulk-importer' ),
					'<strong>' . esc_html( self::AUTHOR_NAME ) . '</strong>',
					'<a href="' . esc_url( self::AUTHOR_URL ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( preg_replace( '#^https?://#', '', self::AUTHOR_URL ) ) . '</a>'
				);
				?>
			</span>
		</div>
		<?php
	}

	public function render_page() {
		$this->verify_capability();

		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : 'upload';

		echo '<div class="wrap ka-lbi-wrap"><h1><span class="dashicons dashicons-upload"></span>' . esc_html__( 'Bulk Import Listings', 'ka-listing-bulk-importer' ) . '</h1>';
		$this->render_nav_tabs( 'ka-lbi-import' );

		echo '<div class="ka-lbi-card">';
		switch ( $step ) {
			case 'map':
				$this->render_mapping_step();
				break;
			case 'preview':
				$this->render_preview_step();
				break;
			case 'done':
				$this->render_results();
				break;
			case 'upload':
			default:
				$this->render_upload_step();
				break;
		}
		echo '</div>';

		echo '<div class="ka-lbi-card">';
		$this->render_recent_imports();
		echo '</div>';
		$this->render_footer();

		echo '</div>';
	}

	/* ------------------------------------------------------------------ */
	/* Settings: Google Places API key                                    */
	/* ------------------------------------------------------------------ */

	public function render_settings_page() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}

		echo '<div class="wrap ka-lbi-wrap"><h1><span class="dashicons dashicons-admin-network"></span>' . esc_html__( 'Bulk Import Settings', 'ka-listing-bulk-importer' ) . '</h1>';
		$this->render_nav_tabs( 'ka-lbi-settings' );
		?>
		<p class="ka-lbi-intro"><?php esc_html_e( 'Each data source the Discover tool can use has its own key below. Saving tests it against the provider right away and tells you plainly if something needs fixing.', 'ka-listing-bulk-importer' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::SETTINGS_NONCE ); ?>
			<input type="hidden" name="action" value="ka_lbi_save_settings" />

			<?php foreach ( self::PROVIDERS as $slug => $def ) :
				$key        = get_option( self::OPTION_KEY_PREFIX . $slug, '' );
				$model      = get_option( self::OPTION_MODEL_PREFIX . $slug, $def['default_model'] );
				$status     = get_option( self::OPTION_STATUS_PREFIX . $slug, null );
				$model_list = $def['is_ai'] ? get_option( self::OPTION_MODELLIST_PREFIX . $slug, array() ) : array();
				$cost       = get_option( $this->cost_option_key( $slug, $def['is_ai'] ? $model : '' ), '' );
				$spend      = (float) get_option( self::OPTION_SPEND_PREFIX . $slug, 0 );
				?>
				<div class="ka-lbi-card">
				<h2>
					<?php echo esc_html( $def['label'] ); ?>
					<?php if ( is_array( $status ) ) : ?>
						<span class="ka-lbi-pill <?php echo $status['ok'] ? 'ka-lbi-pill-ok' : 'ka-lbi-pill-bad'; ?>"><?php echo $status['ok'] ? esc_html__( 'key OK', 'ka-listing-bulk-importer' ) : esc_html__( 'key not working', 'ka-listing-bulk-importer' ); ?></span>
					<?php elseif ( '' === $key ) : ?>
						<span class="ka-lbi-pill ka-lbi-pill-muted"><?php esc_html_e( 'no key saved', 'ka-listing-bulk-importer' ); ?></span>
					<?php endif; ?>
				</h2>

				<?php if ( is_array( $status ) ) : ?>
					<div class="notice notice-<?php echo $status['ok'] ? 'success' : 'error'; ?> inline" style="padding:8px 12px;">
						<p style="margin:.4em 0;">
							<strong><?php echo $status['ok'] ? esc_html__( 'This key works.', 'ka-listing-bulk-importer' ) : esc_html__( 'This key did not work.', 'ka-listing-bulk-importer' ); ?></strong>
							<?php echo esc_html( $status['message'] ); ?>
						</p>
						<?php if ( ! empty( $status['checked'] ) ) : ?>
							<p style="margin:.2em 0;color:#646970;">
								<?php printf( esc_html__( 'Checked: %s', 'ka-listing-bulk-importer' ), esc_html( $status['checked'] ) ); ?>
							</p>
						<?php endif; ?>
					</div>
				<?php endif; ?>

				<table class="form-table">
					<tr>
						<th><label for="ka_lbi_key_<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'API key', 'ka-listing-bulk-importer' ); ?></label></th>
						<td><input type="password" autocomplete="off" name="ka_lbi_key[<?php echo esc_attr( $slug ); ?>]" id="ka_lbi_key_<?php echo esc_attr( $slug ); ?>" class="regular-text" value="<?php echo esc_attr( $key ); ?>" /></td>
					</tr>
					<?php if ( $def['is_ai'] ) : ?>
						<tr>
							<th><label for="ka_lbi_model_<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Model', 'ka-listing-bulk-importer' ); ?></label></th>
							<td>
								<?php if ( ! empty( $model_list ) ) : ?>
									<select name="ka_lbi_model[<?php echo esc_attr( $slug ); ?>]" id="ka_lbi_model_<?php echo esc_attr( $slug ); ?>">
										<?php
										$seen = false;
										foreach ( $model_list as $m ) :
											$m_cost   = get_option( $this->cost_option_key( $slug, $m['id'] ), '' );
											$label    = $m['id'] . ( '' !== $m_cost ? ' — $' . number_format( (float) $m_cost, 3 ) . '/' . esc_html__( 'result', 'ka-listing-bulk-importer' ) : '' );
											$seen     = $seen || ( $m['id'] === $model );
											?>
											<option value="<?php echo esc_attr( $m['id'] ); ?>" <?php selected( $m['id'], $model ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
										<?php if ( ! $seen && $model ) : ?>
											<option value="<?php echo esc_attr( $model ); ?>" selected><?php echo esc_html( $model ); ?> (<?php esc_html_e( 'currently saved, not in the refreshed list', 'ka-listing-bulk-importer' ); ?>)</option>
										<?php endif; ?>
									</select>
								<?php else : ?>
									<input type="text" name="ka_lbi_model[<?php echo esc_attr( $slug ); ?>]" id="ka_lbi_model_<?php echo esc_attr( $slug ); ?>" class="regular-text" value="<?php echo esc_attr( $model ); ?>" />
								<?php endif; ?>
								&nbsp;
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_refresh_models&provider=' . $slug ), self::SETTINGS_NONCE ) ); ?>"><?php esc_html_e( 'Refresh model list', 'ka-listing-bulk-importer' ); ?></a>
								<p class="description">
									<?php
									echo empty( $model_list )
										? esc_html__( 'Save a working key above, then click "Refresh model list" to load the real list of models your account can use.', 'ka-listing-bulk-importer' )
										: esc_html__( 'Pulled live from the provider\'s own API. Costs shown next to a model are whatever you saved for it below — the provider does not report live pricing through this list.', 'ka-listing-bulk-importer' );
									?>
								</p>
							</td>
						</tr>
					<?php endif; ?>
					<tr>
						<th><label for="ka_lbi_cost_<?php echo esc_attr( $slug ); ?>"><?php esc_html_e( 'Estimated cost per result (USD)', 'ka-listing-bulk-importer' ); ?></label></th>
						<td>
							<input type="number" step="0.001" min="0" name="ka_lbi_cost[<?php echo esc_attr( $slug ); ?>]" id="ka_lbi_cost_<?php echo esc_attr( $slug ); ?>" class="small-text" value="<?php echo esc_attr( $cost ); ?>" />
							<p class="description">
								<?php
								echo $def['is_ai']
									? esc_html__( 'Applies to whichever model is currently selected above — pick a model, save, and its own cost is remembered separately from other models.', 'ka-listing-bulk-importer' )
									: esc_html__( 'Your own estimate (check this provider\'s pricing/billing page) — used only to show a rough cost before you run a search on the Discover tab. Leave blank to hide the estimate.', 'ka-listing-bulk-importer' );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Estimated spend so far', 'ka-listing-bulk-importer' ); ?></th>
						<td>
							$<?php echo esc_html( number_format( $spend, 2 ) ); ?>
							<?php if ( $spend > 0 ) : ?>
								&nbsp;
								<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_reset_spend&provider=' . $slug ), self::SETTINGS_NONCE ) ); ?>" class="button button-small"><?php esc_html_e( 'Reset counter', 'ka-listing-bulk-importer' ); ?></a>
							<?php endif; ?>
							<p class="description"><?php esc_html_e( 'A running total based on the estimate above, not a real invoice — check the provider\'s own billing dashboard for actual charges.', 'ka-listing-bulk-importer' ); ?></p>
						</td>
					</tr>
				</table>
				</div>
			<?php endforeach; ?>

			<?php submit_button( __( 'Save & test all keys', 'ka-listing-bulk-importer' ) ); ?>
		</form>

		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-google"></span> <?php esc_html_e( 'How to get a Google Maps (Places API) key', 'ka-listing-bulk-importer' ); ?></h2>
			<ol>
				<li><?php echo wp_kses_post( __( 'Go to <a href="https://console.cloud.google.com" target="_blank" rel="noopener noreferrer">console.cloud.google.com</a> and sign in.', 'ka-listing-bulk-importer' ) ); ?></li>
				<li><?php esc_html_e( 'Create a new project (any name, e.g. "Listings").', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( 'Go to APIs & Services → Library, find "Places API" and enable it.', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( 'Go to Billing and link a billing account (Google gives a recurring free monthly credit that comfortably covers a few hundred photo lookups).', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( 'Go to APIs & Services → Credentials → Create Credentials → API key.', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( 'Click "Restrict key": under API restrictions, select only "Places API", and set a daily quota cap so a leaked key can never run up a large bill.', 'ka-listing-bulk-importer' ); ?></li>
			</ol>
		</div>

		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e( 'How to get an AI provider key (Claude / Gemini / ChatGPT)', 'ka-listing-bulk-importer' ); ?></h2>
			<ul style="list-style:disc;margin-left:1.4em;">
				<li><?php echo wp_kses_post( __( '<strong>Claude:</strong> <a href="https://console.anthropic.com/settings/keys" target="_blank" rel="noopener noreferrer">console.anthropic.com → Settings → API Keys</a>.', 'ka-listing-bulk-importer' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>Gemini:</strong> <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener noreferrer">aistudio.google.com → Get API key</a>.', 'ka-listing-bulk-importer' ) ); ?></li>
				<li><?php echo wp_kses_post( __( '<strong>ChatGPT:</strong> <a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener noreferrer">platform.openai.com → API keys</a>.', 'ka-listing-bulk-importer' ) ); ?></li>
			</ul>
			<div class="notice notice-warning inline" style="padding:8px 12px;">
				<p style="margin:.4em 0;"><?php esc_html_e( 'Important: Claude, Gemini and ChatGPT answer from what the model already knows, not a live web search. Results from these sources are labeled "AI-suggested" in the preview — always double-check the address and phone number before publishing. Google Maps is the only source here backed by a live, current database.', 'ka-listing-bulk-importer' ); ?></p>
			</div>
		</div>

		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-clock"></span> <?php esc_html_e( 'Scheduled Discover searches', 'ka-listing-bulk-importer' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Recurring automatic searches now have their own tab, with more options and a run log.', 'ka-listing-bulk-importer' ); ?>
				&nbsp;
				<a class="button button-secondary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-schedules' ) ); ?>"><?php esc_html_e( 'Open Schedules', 'ka-listing-bulk-importer' ); ?></a>
			</p>
		</div>

		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-sos"></span> <?php esc_html_e( 'Common problems', 'ka-listing-bulk-importer' ); ?></h2>
			<ul style="list-style:disc;margin-left:1.4em;">
				<li><?php esc_html_e( '"REQUEST_DENIED" (Google) — Places API is not enabled for the project, billing is not linked, or the key\'s restrictions block this API.', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( '"OVER_QUERY_LIMIT" / rate limit — the account\'s quota was reached; raise it with the provider or wait until it resets.', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( '401 / Unauthorized — the key was mistyped, revoked, or belongs to a different product than expected.', 'ka-listing-bulk-importer' ); ?></li>
				<li><?php esc_html_e( 'Nothing happens / timeout — the provider may be temporarily unreachable from this server; try saving again in a minute.', 'ka-listing-bulk-importer' ); ?></li>
			</ul>
		</div>

		<?php
		$this->render_footer();
		echo '</div>';
	}

	/** Status pill helper for the schedule run log. */
	private function schedule_log_status_pill( $status ) {
		$map = array(
			'ran'              => array( 'ka-lbi-pill-ok', __( 'Ran', 'ka-listing-bulk-importer' ) ),
			'error'            => array( 'ka-lbi-pill-bad', __( 'Error', 'ka-listing-bulk-importer' ) ),
			'skipped_not_due'  => array( 'ka-lbi-pill-muted', __( 'Skipped — not due yet', 'ka-listing-bulk-importer' ) ),
			'skipped_disabled' => array( 'ka-lbi-pill-muted', __( 'Skipped — paused', 'ka-listing-bulk-importer' ) ),
		);
		$def = $map[ $status ] ?? array( 'ka-lbi-pill-muted', $status );
		return '<span class="ka-lbi-pill ' . esc_attr( $def[0] ) . '">' . esc_html( $def[1] ) . '</span>';
	}

	/** "Scheduled Discover searches" — its own top-level tab: manage saved recurring searches and see a run log. */
	public function render_schedules_page() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}

		$schedules  = $this->get_schedules();
		$categories = get_terms( array( 'taxonomy' => self::TAX_CATEGORY, 'hide_empty' => false ) );
		$locations  = get_terms( array( 'taxonomy' => self::TAX_LOCATION, 'hide_empty' => false ) );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$edit_id = isset( $_GET['edit'] ) ? sanitize_text_field( wp_unslash( $_GET['edit'] ) ) : '';
		$editing = null;
		if ( $edit_id ) {
			foreach ( $schedules as $s ) {
				if ( $s['id'] === $edit_id ) {
					$editing = $s;
					break;
				}
			}
		}

		echo '<div class="wrap ka-lbi-wrap"><h1><span class="dashicons dashicons-clock"></span>' . esc_html__( 'Scheduled Discover searches', 'ka-listing-bulk-importer' ) . '</h1>';
		$this->render_nav_tabs( 'ka-lbi-schedules' );
		?>
		<p class="ka-lbi-intro"><?php esc_html_e( 'Save a city + category + source combo to run automatically (daily or weekly), without you having to start it by hand. Anything clearly new is added as "Pending" and you get an email summary — duplicates and anything uncertain are always left for you to review by hand on the Discover tab.', 'ka-listing-bulk-importer' ); ?></p>

		<?php if ( isset( $_GET['ka_lbi_ran'] ) ) : ?>
			<div class="notice notice-success inline" style="padding:8px 12px;"><p style="margin:.4em 0;"><?php esc_html_e( 'Ran now — check the site admin email for the summary, and Listings → All Listings (Pending) for anything it added, and the log below for details.', 'ka-listing-bulk-importer' ); ?></p></div>
		<?php elseif ( isset( $_GET['ka_lbi_saved'] ) ) : ?>
			<div class="notice notice-success inline" style="padding:8px 12px;"><p style="margin:.4em 0;"><?php esc_html_e( 'Scheduled search saved.', 'ka-listing-bulk-importer' ); ?></p></div>
		<?php endif; ?>

		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'Saved schedules', 'ka-listing-bulk-importer' ); ?></h2>
			<?php if ( ! empty( $schedules ) ) : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'City', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Categories', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Frequency', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Status', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Last run', 'ka-listing-bulk-importer' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $schedules as $s ) :
						$cat_names = array();
						foreach ( $s['categories'] as $slug ) {
							$t = get_term_by( 'slug', $slug, self::TAX_CATEGORY );
							if ( $t ) {
								$cat_names[] = $t->name;
							}
						}
						$cat_label = empty( $cat_names ) ? __( 'All categories', 'ka-listing-bulk-importer' ) : implode( ', ', $cat_names );
						?>
						<tr>
							<td><?php echo esc_html( $s['location'] ); ?></td>
							<td><?php echo esc_html( $cat_label ); ?></td>
							<td><?php echo esc_html( self::PROVIDERS[ $s['provider'] ]['label'] ?? $s['provider'] ); ?></td>
							<td><?php echo esc_html( 'daily' === $s['freq'] ? __( 'Daily', 'ka-listing-bulk-importer' ) : __( 'Weekly', 'ka-listing-bulk-importer' ) ); ?></td>
							<td>
								<?php if ( ! empty( $s['enabled'] ) ) : ?>
									<span class="ka-lbi-pill ka-lbi-pill-ok"><?php esc_html_e( 'Active', 'ka-listing-bulk-importer' ); ?></span>
								<?php else : ?>
									<span class="ka-lbi-pill ka-lbi-pill-muted"><?php esc_html_e( 'Paused', 'ka-listing-bulk-importer' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $s['last_run'] ? $s['last_run'] : __( 'never yet', 'ka-listing-bulk-importer' ) ); ?></td>
							<td style="white-space:nowrap;">
								<a class="button button-small" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-schedules&edit=' . rawurlencode( $s['id'] ) ) ); ?>#ka-lbi-sch-form"><?php esc_html_e( 'Edit', 'ka-listing-bulk-importer' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_run_schedule_now&id=' . rawurlencode( $s['id'] ) ), self::SCHEDULE_NONCE ) ); ?>"><?php esc_html_e( 'Run now', 'ka-listing-bulk-importer' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_toggle_schedule&id=' . rawurlencode( $s['id'] ) ), self::SCHEDULE_NONCE ) ); ?>"><?php echo ! empty( $s['enabled'] ) ? esc_html__( 'Pause', 'ka-listing-bulk-importer' ) : esc_html__( 'Resume', 'ka-listing-bulk-importer' ); ?></a>
								<a class="button button-small" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_delete_schedule&id=' . rawurlencode( $s['id'] ) ), self::SCHEDULE_NONCE ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Remove this scheduled search?', 'ka-listing-bulk-importer' ) ); ?>');"><?php esc_html_e( 'Remove', 'ka-listing-bulk-importer' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><span class="ka-lbi-pill ka-lbi-pill-muted"><?php esc_html_e( 'none yet', 'ka-listing-bulk-importer' ); ?></span></p>
			<?php endif; ?>
		</div>

		<div class="ka-lbi-card" id="ka-lbi-sch-form">
			<h2><span class="dashicons dashicons-<?php echo $editing ? 'edit' : 'plus-alt'; ?>"></span> <?php echo $editing ? esc_html__( 'Edit scheduled search', 'ka-listing-bulk-importer' ) : esc_html__( 'Add a scheduled search', 'ka-listing-bulk-importer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::SCHEDULE_NONCE ); ?>
				<input type="hidden" name="action" value="ka_lbi_save_schedule" />
				<?php if ( $editing ) : ?>
					<input type="hidden" name="ka_lbi_sch_id" value="<?php echo esc_attr( $editing['id'] ); ?>" />
				<?php endif; ?>
				<table class="form-table">
					<tr>
						<th><label for="ka_lbi_sch_location"><?php esc_html_e( 'City', 'ka-listing-bulk-importer' ); ?></label></th>
						<td>
							<input type="text" name="ka_lbi_sch_location" id="ka_lbi_sch_location" class="regular-text" list="ka_lbi_sch_location_list" required placeholder="<?php esc_attr_e( 'e.g. Hamburg', 'ka-listing-bulk-importer' ); ?>" value="<?php echo esc_attr( $editing ? $editing['location'] : '' ); ?>" />
							<datalist id="ka_lbi_sch_location_list">
								<?php foreach ( $locations as $loc ) : ?>
									<option value="<?php echo esc_attr( $loc->name ); ?>"></option>
								<?php endforeach; ?>
							</datalist>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e( 'Categories', 'ka-listing-bulk-importer' ); ?></th>
						<td>
							<p>
								<a href="#" id="ka_lbi_sch_cat_all"><?php esc_html_e( 'Select all', 'ka-listing-bulk-importer' ); ?></a>
								&nbsp;|&nbsp;
								<a href="#" id="ka_lbi_sch_cat_none"><?php esc_html_e( 'Clear (= all categories)', 'ka-listing-bulk-importer' ); ?></a>
							</p>
							<div style="max-height:220px;overflow-y:auto;border:1px solid #dcdcde;border-radius:4px;padding:8px 12px;max-width:420px;background:#fff;">
								<?php foreach ( $categories as $cat ) : ?>
									<label style="display:block;margin:3px 0;">
										<input type="checkbox" class="ka-lbi-sch-cat-checkbox" name="ka_lbi_sch_category[]" value="<?php echo esc_attr( $cat->slug ); ?>" <?php checked( $editing && in_array( $cat->slug, $editing['categories'], true ) ); ?> />
										<?php echo esc_html( $cat->name ); ?>
									</label>
								<?php endforeach; ?>
							</div>
							<p class="description"><?php esc_html_e( 'Leave none checked to search all categories for this city on every run.', 'ka-listing-bulk-importer' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="ka_lbi_sch_provider"><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></label></th>
						<td>
							<select name="ka_lbi_sch_provider" id="ka_lbi_sch_provider">
								<?php foreach ( self::PROVIDERS as $slug => $def ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $editing ? $editing['provider'] : '', $slug ); ?>><?php echo esc_html( $def['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ka_lbi_sch_max"><?php esc_html_e( 'Max results per category per run', 'ka-listing-bulk-importer' ); ?></label></th>
						<td><input type="number" name="ka_lbi_sch_max" id="ka_lbi_sch_max" min="1" max="<?php echo (int) self::MAX_DISCOVER_RESULTS; ?>" value="<?php echo esc_attr( $editing ? $editing['max'] : 15 ); ?>" style="width:80px;" /></td>
					</tr>
					<tr>
						<th><label for="ka_lbi_sch_freq"><?php esc_html_e( 'Frequency', 'ka-listing-bulk-importer' ); ?></label></th>
						<td>
							<select name="ka_lbi_sch_freq" id="ka_lbi_sch_freq">
								<option value="weekly" <?php selected( $editing ? $editing['freq'] : 'weekly', 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'ka-listing-bulk-importer' ); ?></option>
								<option value="daily" <?php selected( $editing ? $editing['freq'] : '', 'daily' ); ?>><?php esc_html_e( 'Daily', 'ka-listing-bulk-importer' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ka_lbi_sch_enabled"><?php esc_html_e( 'Active', 'ka-listing-bulk-importer' ); ?></label></th>
						<td>
							<label><input type="checkbox" name="ka_lbi_sch_enabled" id="ka_lbi_sch_enabled" value="1" <?php checked( ! $editing || ! empty( $editing['enabled'] ) ); ?> /> <?php esc_html_e( 'Run this schedule automatically', 'ka-listing-bulk-importer' ); ?></label>
							<p class="description"><?php esc_html_e( 'Untick to keep the schedule saved but pause it — it will be skipped (and logged as "paused") until you turn it back on.', 'ka-listing-bulk-importer' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( $editing ? __( 'Update scheduled search', 'ka-listing-bulk-importer' ) : __( 'Add scheduled search', 'ka-listing-bulk-importer' ), 'primary', 'submit', false ); ?>
				<?php if ( $editing ) : ?>
					&nbsp;<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-schedules' ) ); ?>"><?php esc_html_e( 'Cancel edit', 'ka-listing-bulk-importer' ); ?></a>
				<?php endif; ?>
			</form>
			<script>
			(function(){
				var allLink  = document.getElementById('ka_lbi_sch_cat_all');
				var noneLink = document.getElementById('ka_lbi_sch_cat_none');
				var boxes    = document.querySelectorAll('.ka-lbi-sch-cat-checkbox');
				if ( allLink ) {
					allLink.addEventListener('click', function(e){ e.preventDefault(); boxes.forEach(function(b){ b.checked = true; }); });
				}
				if ( noneLink ) {
					noneLink.addEventListener('click', function(e){ e.preventDefault(); boxes.forEach(function(b){ b.checked = false; }); });
				}
			})();
			</script>
		</div>

		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-media-text"></span> <?php esc_html_e( 'Run log', 'ka-listing-bulk-importer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Every time the daily cron check runs, each schedule is logged here — whether it actually ran, was skipped because it was not due yet or was paused, or hit an error, plus what it found.', 'ka-listing-bulk-importer' ); ?></p>
			<?php $log = $this->get_schedule_log(); ?>
			<?php if ( ! empty( $log ) ) : ?>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'When', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'City / Category', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Outcome', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Details', 'ka-listing-bulk-importer' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( $entry['time'] ); ?></td>
							<td><?php echo esc_html( $entry['location'] . ' / ' . $entry['category'] ); ?></td>
							<td><?php echo esc_html( $entry['provider'] ); ?></td>
							<td><?php echo wp_kses_post( $this->schedule_log_status_pill( $entry['status'] ) ); ?></td>
							<td>
								<?php if ( 'error' === $entry['status'] ) : ?>
									<?php echo esc_html( $entry['message'] ); ?>
								<?php elseif ( 'ran' === $entry['status'] ) : ?>
									<?php
									printf(
										/* translators: 1: found count, 2: created count, 3: skipped count */
										esc_html__( '%1$d found, %2$d added as pending, %3$d skipped', 'ka-listing-bulk-importer' ),
										(int) $entry['found'],
										(int) $entry['created'],
										(int) $entry['skipped']
									);
									?>
								<?php else : ?>
									—
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><span class="ka-lbi-pill ka-lbi-pill-muted"><?php esc_html_e( 'no runs logged yet', 'ka-listing-bulk-importer' ); ?></span></p>
			<?php endif; ?>
		</div>

		<?php
		$this->render_footer();
		echo '</div>';
	}

	public function handle_save_settings() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		check_admin_referer( self::SETTINGS_NONCE );

		$keys   = isset( $_POST['ka_lbi_key'] ) ? (array) wp_unslash( $_POST['ka_lbi_key'] ) : array();
		$models = isset( $_POST['ka_lbi_model'] ) ? (array) wp_unslash( $_POST['ka_lbi_model'] ) : array();
		$costs  = isset( $_POST['ka_lbi_cost'] ) ? (array) wp_unslash( $_POST['ka_lbi_cost'] ) : array();

		foreach ( self::PROVIDERS as $slug => $def ) {
			$key = isset( $keys[ $slug ] ) ? trim( preg_replace( '/[^A-Za-z0-9_\-\.]/', '', $keys[ $slug ] ) ) : '';
			update_option( self::OPTION_KEY_PREFIX . $slug, $key, false );

			$model = '';
			if ( $def['is_ai'] ) {
				$model = isset( $models[ $slug ] ) ? sanitize_text_field( $models[ $slug ] ) : '';
				if ( '' === $model ) {
					$model = $def['default_model'];
				}
				update_option( self::OPTION_MODEL_PREFIX . $slug, $model, false );
			}

			$cost_key = $this->cost_option_key( $slug, $model );
			if ( isset( $costs[ $slug ] ) && '' !== trim( $costs[ $slug ] ) && is_numeric( $costs[ $slug ] ) ) {
				update_option( $cost_key, (float) $costs[ $slug ], false );
			} else {
				delete_option( $cost_key );
			}

			if ( '' === $key ) {
				delete_option( self::OPTION_STATUS_PREFIX . $slug );
				continue;
			}

			$result = $this->test_provider_key( $slug, $key, $model );
			update_option( self::OPTION_STATUS_PREFIX . $slug, array(
				'ok'      => $result['ok'],
				'message' => $result['message'],
				'checked' => current_time( 'mysql' ),
			), false );

			// A key that just proved itself working is worth refreshing the model list for.
			if ( $result['ok'] && $def['is_ai'] ) {
				$list = $this->list_models( $slug, $key );
				if ( ! is_wp_error( $list ) && ! empty( $list ) ) {
					update_option( self::OPTION_MODELLIST_PREFIX . $slug, $list, false );
				}
			}
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-settings' ) );
		exit;
	}

	public function handle_reset_spend() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		check_admin_referer( self::SETTINGS_NONCE );

		$provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		if ( isset( self::PROVIDERS[ $provider ] ) ) {
			delete_option( self::OPTION_SPEND_PREFIX . $provider );
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-settings' ) );
		exit;
	}

	/** Re-fetches a provider's model list on demand, without needing to resave/retest its key. */
	public function handle_refresh_models() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		check_admin_referer( self::SETTINGS_NONCE );

		$provider = isset( $_GET['provider'] ) ? sanitize_key( wp_unslash( $_GET['provider'] ) ) : '';
		if ( isset( self::PROVIDERS[ $provider ] ) && self::PROVIDERS[ $provider ]['is_ai'] ) {
			$key = get_option( self::OPTION_KEY_PREFIX . $provider, '' );
			if ( '' !== $key ) {
				$list = $this->list_models( $provider, $key );
				if ( ! is_wp_error( $list ) && ! empty( $list ) ) {
					update_option( self::OPTION_MODELLIST_PREFIX . $provider, $list, false );
				}
			}
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-settings' ) );
		exit;
	}

	/** Dispatches to a provider's own "list my available models" endpoint. Returns array of {id,label} or WP_Error. */
	private function list_models( $provider, $key ) {
		switch ( $provider ) {
			case 'claude':
				return $this->list_models_claude( $key );
			case 'gemini':
				return $this->list_models_gemini( $key );
			case 'openai':
				return $this->list_models_openai( $key );
			default:
				return new WP_Error( 'ka_lbi_no_models', __( 'This provider has no model list.', 'ka-listing-bulk-importer' ) );
		}
	}

	private function list_models_claude( $key ) {
		$response = wp_remote_get( 'https://api.anthropic.com/v1/models?limit=100', array(
			'timeout' => 20,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
			),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'ka_lbi_models', ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Could not read a model list from Claude.', 'ka-listing-bulk-importer' ) );
		}
		$out = array();
		foreach ( $body['data'] as $m ) {
			if ( ! empty( $m['id'] ) ) {
				$out[] = array( 'id' => $m['id'] );
			}
		}
		return $out;
	}

	private function list_models_gemini( $key ) {
		$response = wp_remote_get( 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $key ), array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['models'] ) || ! is_array( $body['models'] ) ) {
			return new WP_Error( 'ka_lbi_models', ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Could not read a model list from Gemini.', 'ka-listing-bulk-importer' ) );
		}
		$out = array();
		foreach ( $body['models'] as $m ) {
			$methods = isset( $m['supportedGenerationMethods'] ) ? (array) $m['supportedGenerationMethods'] : array();
			if ( ! in_array( 'generateContent', $methods, true ) ) {
				continue; // skip embedding-only / non-chat models
			}
			$id = isset( $m['name'] ) ? preg_replace( '#^models/#', '', $m['name'] ) : '';
			if ( $id ) {
				$out[] = array( 'id' => $id );
			}
		}
		return $out;
	}

	private function list_models_openai( $key ) {
		$response = wp_remote_get( 'https://api.openai.com/v1/models', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $body['data'] ) || ! is_array( $body['data'] ) ) {
			return new WP_Error( 'ka_lbi_models', ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Could not read a model list from OpenAI.', 'ka-listing-bulk-importer' ) );
		}
		$out = array();
		foreach ( $body['data'] as $m ) {
			$id = isset( $m['id'] ) ? $m['id'] : '';
			// Keep this to models that can hold a chat conversation; drop embeddings, audio, image and moderation models.
			if ( $id && preg_match( '/^(gpt-|o[0-9]|chatgpt)/i', $id ) && ! preg_match( '/(embedding|whisper|tts|dall-e|moderation|audio)/i', $id ) ) {
				$out[] = array( 'id' => $id );
			}
		}
		usort( $out, function ( $a, $b ) { return strcmp( $a['id'], $b['id'] ); } );
		return $out;
	}

	/** Dispatches to the right lightweight "does this key work" check for a provider. */
	private function test_provider_key( $provider, $key, $model = '' ) {
		switch ( $provider ) {
			case 'google':
				return $this->test_places_api_key( $key );
			case 'claude':
				return $this->test_claude_key( $key, $model );
			case 'gemini':
				return $this->test_gemini_key( $key, $model );
			case 'openai':
				return $this->test_openai_key( $key );
			default:
				return array( 'ok' => false, 'message' => __( 'Unknown provider', 'ka-listing-bulk-importer' ) );
		}
	}

	private function test_claude_key( $key, $model ) {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 20,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => 8,
				'messages'   => array( array( 'role' => 'user', 'content' => 'ping' ) ),
			) ),
		) );
		return $this->interpret_ai_test_response( $response );
	}

	private function test_gemini_key( $key, $model ) {
		$url      = 'https://generativelanguage.googleapis.com/v1beta/models?key=' . rawurlencode( $key );
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		return $this->interpret_ai_test_response( $response, 'models' );
	}

	private function test_openai_key( $key ) {
		$response = wp_remote_get( 'https://api.openai.com/v1/models', array(
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $key ),
		) );
		return $this->interpret_ai_test_response( $response, 'data' );
	}

	/** Shared response interpretation for the three AI provider test calls. */
	private function interpret_ai_test_response( $response, $success_field = '' ) {
		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				/* translators: %s: underlying network error */
				'message' => sprintf( __( 'Could not reach the provider: %s', 'ka-listing-bulk-importer' ), $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code ) {
			if ( '' === $success_field || ( is_array( $body ) && isset( $body[ $success_field ] ) ) || ( is_array( $body ) && isset( $body['content'] ) ) ) {
				return array( 'ok' => true, 'message' => __( 'The provider accepted the key.', 'ka-listing-bulk-importer' ) );
			}
			return array( 'ok' => true, 'message' => __( 'The provider responded with HTTP 200.', 'ka-listing-bulk-importer' ) );
		}

		$detail = '';
		if ( is_array( $body ) ) {
			if ( ! empty( $body['error']['message'] ) ) {
				$detail = ' ' . $body['error']['message'];
			} elseif ( ! empty( $body['error'] ) && is_string( $body['error'] ) ) {
				$detail = ' ' . $body['error'];
			}
		}

		if ( 401 === $code || 403 === $code ) {
			return array( 'ok' => false, 'message' => sprintf( __( 'The key was rejected (HTTP %1$d).%2$s', 'ka-listing-bulk-importer' ), $code, $detail ) );
		}
		if ( 429 === $code ) {
			return array( 'ok' => false, 'message' => __( 'Rate limited — the account has hit a quota right now.', 'ka-listing-bulk-importer' ) );
		}

		return array(
			'ok'      => false,
			/* translators: 1: HTTP status code, 2: any error detail from the provider */
			'message' => sprintf( __( 'Unexpected response (HTTP %1$d)%2$s', 'ka-listing-bulk-importer' ), $code, $detail ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Discover: find businesses automatically, then reuse the same        */
	/* preview → duplicate-check → import pipeline as the CSV flow.        */
	/* ------------------------------------------------------------------ */

	public function render_discover_page() {
		$this->verify_capability();

		echo '<div class="wrap ka-lbi-wrap"><h1><span class="dashicons dashicons-search"></span>' . esc_html__( 'Discover Listings', 'ka-listing-bulk-importer' ) . '</h1>';
		$this->render_nav_tabs( 'ka-lbi-discover' );

		echo '<p class="ka-lbi-intro">' . esc_html__( 'Pick a place and a category, choose where to search, and this pulls together candidate listings — you still review, map duplicates and confirm everything on the same preview screen as a CSV import before anything is saved.', 'ka-listing-bulk-importer' ) . '</p>';

		$categories = get_terms( array( 'taxonomy' => self::TAX_CATEGORY, 'hide_empty' => false ) );
		$locations  = get_terms( array( 'taxonomy' => self::TAX_LOCATION, 'hide_empty' => false ) );

		if ( is_wp_error( $categories ) || empty( $categories ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'No categories exist yet — add at least one under Listings → Categories first.', 'ka-listing-bulk-importer' ) . '</p></div>';
			$this->render_footer();
			echo '</div>';
			return;
		}

		$cost_data = array();
		foreach ( self::PROVIDERS as $slug => $def ) {
			$status             = get_option( self::OPTION_STATUS_PREFIX . $slug, null );
			$active_model       = $def['is_ai'] ? get_option( self::OPTION_MODEL_PREFIX . $slug, $def['default_model'] ) : '';
			$cost_data[ $slug ] = array(
				'label'   => $def['label'] . ( $active_model ? ' (' . $active_model . ')' : '' ),
				'has_key' => '' !== get_option( self::OPTION_KEY_PREFIX . $slug, '' ),
				'ok'      => is_array( $status ) ? (bool) $status['ok'] : null,
				'cost'    => (float) get_option( $this->cost_option_key( $slug, $active_model ), 0 ),
				'is_ai'   => $def['is_ai'],
			);
		}

		// Pre-fill from a "check what already exists" round trip (GET) so the form keeps the user's choices.
		$sel_location_slug  = isset( $_GET['ka_lbi_loc_slug'] ) ? sanitize_key( wp_unslash( $_GET['ka_lbi_loc_slug'] ) ) : '';
		$sel_category_slugs = isset( $_GET['ka_lbi_category'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_GET['ka_lbi_category'] ) ) : array();
		$checking_existing  = isset( $_GET['ka_lbi_check'] ) && ! empty( $locations ) && '' !== $sel_location_slug;

		if ( $checking_existing ) {
			$loc_term = get_term_by( 'slug', $sel_location_slug, self::TAX_LOCATION );
			// The "see what already exists" check only makes sense against a single category — with none or several picked, it shows everything for the city instead.
			$cat_term = ( 1 === count( $sel_category_slugs ) ) ? get_term_by( 'slug', $sel_category_slugs[0], self::TAX_CATEGORY ) : null;
			if ( $loc_term ) {
				$existing = $this->find_existing_for_place( $loc_term, $cat_term ?: null );
				?>
				<div class="ka-lbi-card">
					<h2><span class="dashicons dashicons-list-view"></span>
						<?php
						if ( $cat_term ) {
							printf(
								/* translators: 1: location name, 2: category name */
								esc_html__( 'Already on your site for %1$s / %2$s', 'ka-listing-bulk-importer' ),
								esc_html( $loc_term->name ),
								esc_html( $cat_term->name )
							);
						} else {
							printf(
								/* translators: 1: location name */
								esc_html__( 'Already on your site for %1$s (all categories)', 'ka-listing-bulk-importer' ),
								esc_html( $loc_term->name )
							);
						}
						?>
					</h2>
					<?php if ( empty( $existing ) ) : ?>
						<p><span class="ka-lbi-pill ka-lbi-pill-ok">✓ <?php esc_html_e( 'Nothing yet', 'ka-listing-bulk-importer' ); ?></span> <?php esc_html_e( 'No listings exist for this place and category — everything Discover finds here will be new.', 'ka-listing-bulk-importer' ); ?></p>
					<?php else : ?>
						<p><span class="ka-lbi-pill ka-lbi-pill-warn"><?php echo (int) count( $existing ); ?> <?php esc_html_e( 'already there', 'ka-listing-bulk-importer' ); ?></span> <?php esc_html_e( 'These already exist. Anything Discover finds that matches one of these will be flagged as a possible duplicate on the next screen.', 'ka-listing-bulk-importer' ); ?></p>
						<table class="widefat striped ka-lbi-existing-table">
							<thead><tr>
								<th><?php esc_html_e( 'Photo', 'ka-listing-bulk-importer' ); ?></th>
								<th><?php esc_html_e( 'Title', 'ka-listing-bulk-importer' ); ?></th>
								<th><?php esc_html_e( 'Status', 'ka-listing-bulk-importer' ); ?></th>
								<th><?php esc_html_e( 'Phone', 'ka-listing-bulk-importer' ); ?></th>
								<th></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $existing as $e ) : ?>
								<tr>
									<td><?php echo $e['thumb'] ? '<img src="' . esc_url( $e['thumb'] ) . '" width="40" height="40" style="object-fit:cover;" alt="" />' : '&mdash;'; ?></td>
									<td><strong><?php echo esc_html( $e['title'] ); ?></strong></td>
									<td><span class="ka-lbi-pill ka-lbi-pill-muted"><?php echo esc_html( $e['status'] ); ?></span></td>
									<td><?php echo esc_html( $e['phone'] ?: '—' ); ?></td>
									<td><a href="<?php echo esc_url( $e['edit'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Edit', 'ka-listing-bulk-importer' ); ?></a></td>
								</tr>
							<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
				<?php
			}
		}
		?>
		<div class="ka-lbi-card">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="ka_lbi_discover_form">
			<?php wp_nonce_field( self::DISCOVER_NONCE ); ?>
			<input type="hidden" name="action" id="ka_lbi_discover_action" value="ka_lbi_discover" />
			<table class="form-table">
				<tr>
					<th><label for="ka_lbi_location_select"><?php esc_html_e( 'City or village', 'ka-listing-bulk-importer' ); ?></label></th>
					<td>
						<?php if ( ! empty( $locations ) ) : ?>
							<select id="ka_lbi_location_select" style="min-width:260px;">
								<option value=""><?php esc_html_e( '— choose a place already on your site —', 'ka-listing-bulk-importer' ); ?></option>
								<?php foreach ( $locations as $loc ) : ?>
									<option value="<?php echo esc_attr( $loc->name ); ?>" data-slug="<?php echo esc_attr( $loc->slug ); ?>" <?php selected( $sel_location_slug, $loc->slug ); ?>><?php echo esc_html( $loc->name ); ?></option>
								<?php endforeach; ?>
								<option value="__new__"><?php esc_html_e( '+ Add a new place…', 'ka-listing-bulk-importer' ); ?></option>
							</select>
						<?php endif; ?>
						<input type="text" name="ka_lbi_location" id="ka_lbi_location" class="regular-text" <?php echo empty( $locations ) ? '' : 'style="display:none;margin-top:6px;"'; ?> placeholder="<?php esc_attr_e( 'Type the place name exactly as it should appear', 'ka-listing-bulk-importer' ); ?>" />
						<p class="description" id="ka_lbi_location_help">
							<?php
							echo empty( $locations )
								? esc_html__( 'No places exist yet under Listings → Locations. Type the name exactly as you want it to appear — it will be created automatically.', 'ka-listing-bulk-importer' )
								: esc_html__( 'Choosing from the list avoids typos that would create a duplicate location. Only pick "Add a new place" for somewhere genuinely not listed yet.', 'ka-listing-bulk-importer' );
							?>
						</p>
						<p>
							<a href="#" id="ka_lbi_bulk_toggle"><?php esc_html_e( 'Search several cities at once instead »', 'ka-listing-bulk-importer' ); ?></a>
						</p>
						<div id="ka_lbi_bulk_wrap" style="display:none;">
							<textarea name="ka_lbi_locations_bulk" id="ka_lbi_locations_bulk" class="large-text" rows="3" placeholder="<?php esc_attr_e( 'One city per line, or separated by commas — e.g. Hamburg, Bremen, Hannover', 'ka-listing-bulk-importer' ); ?>"></textarea>
							<p class="description"><?php esc_html_e( 'Runs the same search once per city and combines every result into one preview. When this is filled in, the single city field above is ignored.', 'ka-listing-bulk-importer' ); ?></p>
						</div>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Category', 'ka-listing-bulk-importer' ); ?></th>
					<td>
						<p>
							<a href="#" id="ka_lbi_cat_all"><?php esc_html_e( 'Select all', 'ka-listing-bulk-importer' ); ?></a>
							&nbsp;|&nbsp;
							<a href="#" id="ka_lbi_cat_none"><?php esc_html_e( 'Clear', 'ka-listing-bulk-importer' ); ?></a>
							&nbsp;—&nbsp;
							<span id="ka_lbi_cat_count" class="description"></span>
						</p>
						<div id="ka_lbi_category_box" style="max-height:220px;overflow-y:auto;border:1px solid #dcdcde;border-radius:4px;padding:8px 12px;max-width:420px;background:#fff;">
							<?php foreach ( $categories as $cat ) : ?>
								<label style="display:block;margin:3px 0;">
									<input type="checkbox" class="ka-lbi-cat-checkbox" name="ka_lbi_category[]" value="<?php echo esc_attr( $cat->slug ); ?>" <?php checked( in_array( $cat->slug, $sel_category_slugs, true ) ); ?> />
									<?php echo esc_html( $cat->name ); ?>
								</label>
							<?php endforeach; ?>
						</div>
						<p class="description"><?php esc_html_e( 'Leave none checked to search all categories — but a category runs a separate search each, so pick just the relevant ones (up to 24 city/category combinations per run) rather than truly everything at once.', 'ka-listing-bulk-importer' ); ?></p>
						<?php if ( ! empty( $locations ) ) : ?>
							<button type="submit" formmethod="get" formaction="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-discover&ka_lbi_check=1' ) ); ?>" name="ka_lbi_loc_slug" id="ka_lbi_check_btn" class="button" value="<?php echo esc_attr( $sel_location_slug ); ?>" disabled>
								<?php esc_html_e( 'See what already exists (this city, all categories)', 'ka-listing-bulk-importer' ); ?>
							</button>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Search mode', 'ka-listing-bulk-importer' ); ?></th>
					<td>
						<label class="ka-lbi-mode-card">
							<input type="radio" name="ka_lbi_mode" value="all" checked />
							<strong><?php esc_html_e( 'Find everything', 'ka-listing-bulk-importer' ); ?></strong>
							<div class="description"><?php esc_html_e( 'Show every result; you decide row by row on the next screen what to do with possible duplicates.', 'ka-listing-bulk-importer' ); ?></div>
						</label>
						<label class="ka-lbi-mode-card">
							<input type="radio" name="ka_lbi_mode" value="new_only" />
							<strong><?php esc_html_e( 'Only find new ones', 'ka-listing-bulk-importer' ); ?></strong>
							<div class="description"><?php esc_html_e( 'Automatically leave out anything that looks like it already exists on your site.', 'ka-listing-bulk-importer' ); ?></div>
						</label>
						<label class="ka-lbi-mode-card">
							<input type="radio" name="ka_lbi_mode" value="update_existing" />
							<strong><?php esc_html_e( 'Prefer updating existing listings', 'ka-listing-bulk-importer' ); ?></strong>
							<div class="description"><?php esc_html_e( 'When a result matches an existing listing, pre-select "update" for it on the next screen instead of "skip".', 'ka-listing-bulk-importer' ); ?></div>
						</label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Data source', 'ka-listing-bulk-importer' ); ?></th>
					<td>
						<?php foreach ( $cost_data as $slug => $c ) : ?>
							<label class="ka-lbi-source-card">
								<input type="radio" name="ka_lbi_provider" value="<?php echo esc_attr( $slug ); ?>" data-cost="<?php echo esc_attr( $c['cost'] ); ?>" <?php checked( 'google' === $slug ); ?> <?php disabled( ! $c['has_key'] ); ?> />
								<?php echo esc_html( $c['label'] ); ?>
								<?php if ( ! $c['has_key'] ) : ?>
									<span class="ka-lbi-pill ka-lbi-pill-bad"><?php esc_html_e( 'no key saved yet', 'ka-listing-bulk-importer' ); ?></span>
								<?php elseif ( false === $c['ok'] ) : ?>
									<span class="ka-lbi-pill ka-lbi-pill-bad"><?php esc_html_e( 'last test failed', 'ka-listing-bulk-importer' ); ?></span>
								<?php elseif ( true === $c['ok'] ) : ?>
									<span class="ka-lbi-pill ka-lbi-pill-ok"><?php esc_html_e( 'key OK', 'ka-listing-bulk-importer' ); ?></span>
								<?php endif; ?>
								<?php if ( $c['is_ai'] ) : ?>
									<span class="ka-lbi-pill ka-lbi-pill-warn"><?php esc_html_e( 'AI-suggested, verify before publishing', 'ka-listing-bulk-importer' ); ?></span>
								<?php endif; ?>
							</label>
						<?php endforeach; ?>
						<p class="description">
							<?php
							printf(
								/* translators: %s: link to settings page */
								wp_kses_post( __( 'Add or fix keys on the <a href="%s">Settings</a> tab.', 'ka-listing-bulk-importer' ) ),
								esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-settings' ) )
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="ka_lbi_max_results"><?php esc_html_e( 'How many results', 'ka-listing-bulk-importer' ); ?></label></th>
					<td>
						<input type="number" name="ka_lbi_max_results" id="ka_lbi_max_results" min="1" max="<?php echo (int) self::MAX_DISCOVER_RESULTS; ?>" value="20" style="width:80px;" />
						<span id="ka_lbi_cost_estimate" style="margin-left:10px;color:#646970;"></span>
						<p class="description">
							<?php
							printf(
								/* translators: %d: JOB_MAX_RESULTS_CAP */
								esc_html__( 'Only used as-is for a small (preview) search. A search that runs in the background automatically uses at most %d results per city/category instead, however high this is set — each result needs its own lookup to Google, and a high number here made a background run tie up the server for a very long time.', 'ka-listing-bulk-importer' ),
								(int) self::JOB_MAX_RESULTS_CAP
							);
							?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="ka_lbi_status"><?php esc_html_e( 'Status for new listings', 'ka-listing-bulk-importer' ); ?></label></th>
					<td>
						<select name="ka_lbi_status" id="ka_lbi_status">
							<option value="pending" selected><?php esc_html_e( 'Pending review (recommended)', 'ka-listing-bulk-importer' ); ?></option>
							<option value="draft"><?php esc_html_e( 'Draft', 'ka-listing-bulk-importer' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'ka-listing-bulk-importer' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Start search', 'ka-listing-bulk-importer' ), 'primary', 'submit', false ); ?>
			<p class="description" style="margin-top:8px;">
				<?php esc_html_e( 'Decided automatically: a small search (up to the combination limit above) shows you a preview to check before anything is saved. A bigger one runs in the background instead — a couple of combinations at a time over several minutes — imports anything clearly new as Pending, quietly skips anything ambiguous, and emails you a summary when it\'s done. Either way, this same page shows live progress while it runs.', 'ka-listing-bulk-importer' ); ?>
			</p>
		</form>
		</div>

		<?php $this->render_bulk_job_status(); ?>

		<script>
		(function() {
			var maxInput      = document.getElementById('ka_lbi_max_results');
			var out           = document.getElementById('ka_lbi_cost_estimate');
			var radios        = document.querySelectorAll('input[name="ka_lbi_provider"]');
			var catBoxes      = document.querySelectorAll('.ka-lbi-cat-checkbox');
			var catCountLabel = document.getElementById('ka_lbi_cat_count');
			var bulkArea      = document.getElementById('ka_lbi_locations_bulk');
			var TOTAL_CATS    = <?php echo (int) count( $categories ); ?>;
			var MAX_COMBOS    = <?php echo (int) self::MAX_DISCOVER_COMBOS; ?>;

			function checkedCatCount() {
				var n = 0;
				catBoxes.forEach(function(c){ if (c.checked) n++; });
				return n; // 0 means "all categories"
			}
			function comboCount() {
				var cities = 1;
				if (bulkArea && bulkArea.value.trim() !== '') {
					cities = bulkArea.value.split(/[\r\n,]+/).map(function(s){ return s.trim(); }).filter(Boolean).length || 1;
				}
				var checkedCount = checkedCatCount();
				var cats = checkedCount > 0 ? checkedCount : TOTAL_CATS;
				return Math.max( 1, cities * cats );
			}
			function updateCatCountLabel() {
				if (!catCountLabel) { return; }
				var n = checkedCatCount();
				catCountLabel.textContent = n > 0
					? n + ' ' + <?php echo wp_json_encode( __( 'selected', 'ka-listing-bulk-importer' ) ); ?>
					: <?php echo wp_json_encode( __( 'none selected — searches all categories', 'ka-listing-bulk-importer' ) ); ?>;
			}
			function update() {
				updateCatCountLabel();
				var cost = 0, checked = null;
				radios.forEach(function(r){ if (r.checked) checked = r; });
				if (!checked) { out.textContent = ''; return; }
				cost = parseFloat(checked.getAttribute('data-cost')) || 0;
				var n = parseInt(maxInput.value, 10) || 0;
				var combos = comboCount();
				var overCap = combos > MAX_COMBOS;
				var total = n * Math.min( combos, MAX_COMBOS );
				if (cost > 0 && n > 0) {
					var label = combos > 1 ? (<?php echo wp_json_encode( __( 'estimated for all combinations', 'ka-listing-bulk-importer' ) ); ?>) : (<?php echo wp_json_encode( __( 'estimated', 'ka-listing-bulk-importer' ) ); ?>);
					out.textContent = '≈ $' + (cost * total).toFixed(2) + ' ' + label;
				} else {
					out.textContent = <?php echo wp_json_encode( __( 'no cost estimate saved for this source — add one in Settings', 'ka-listing-bulk-importer' ) ); ?>;
				}
				if (overCap) {
					out.textContent += ' — ' + combos + ' ' + <?php echo wp_json_encode( __( 'city/category combinations, over the limit of', 'ka-listing-bulk-importer' ) ); ?> + ' ' + MAX_COMBOS + '. ' + <?php echo wp_json_encode( __( 'This will run in the background automatically instead of showing a preview.', 'ka-listing-bulk-importer' ) ); ?>;
					out.style.color = '#8a6d00';
				} else {
					out.style.color = '';
				}
			}
			maxInput.addEventListener('input', update);
			radios.forEach(function(r){ r.addEventListener('change', update); });
			catBoxes.forEach(function(c){ c.addEventListener('change', update); });
			if (bulkArea) { bulkArea.addEventListener('input', update); }
			update();

			var catAllLink  = document.getElementById('ka_lbi_cat_all');
			var catNoneLink = document.getElementById('ka_lbi_cat_none');
			if (catAllLink) {
				catAllLink.addEventListener('click', function(e) {
					e.preventDefault();
					catBoxes.forEach(function(c){ c.checked = true; });
					update();
				});
			}
			if (catNoneLink) {
				catNoneLink.addEventListener('click', function(e) {
					e.preventDefault();
					catBoxes.forEach(function(c){ c.checked = false; });
					update();
				});
			}

			// One button, decided automatically: small enough for a preview runs interactively, anything bigger runs in the background — no second button to choose between.
			var discoverForm = document.getElementById('ka_lbi_discover_form');
			var actionInput  = document.getElementById('ka_lbi_discover_action');
			if (discoverForm && actionInput) {
				discoverForm.addEventListener('submit', function() {
					actionInput.value = ( comboCount() > MAX_COMBOS ) ? 'ka_lbi_start_bulk_job' : 'ka_lbi_discover';
				});
			}

			// City select <-> free-text "add new place" field.
			var citySelect = document.getElementById('ka_lbi_location_select');
			var cityText   = document.getElementById('ka_lbi_location');
			var checkBtn   = document.getElementById('ka_lbi_check_btn');
			function syncCity() {
				if (!citySelect) { return; }
				if (citySelect.value === '__new__') {
					cityText.style.display = '';
					cityText.value = '';
					cityText.focus();
					if (checkBtn) { checkBtn.disabled = true; }
				} else if (citySelect.value === '') {
					cityText.style.display = 'none';
					cityText.value = '';
					if (checkBtn) { checkBtn.disabled = true; }
				} else {
					cityText.style.display = 'none';
					cityText.value = citySelect.value;
					if (checkBtn) {
						checkBtn.disabled = false;
						checkBtn.value = citySelect.selectedOptions[0].getAttribute('data-slug');
					}
				}
			}
			if (citySelect) {
				citySelect.addEventListener('change', syncCity);
				syncCity();
			}

			// Toggle the multi-city textarea.
			var bulkToggle = document.getElementById('ka_lbi_bulk_toggle');
			var bulkWrap   = document.getElementById('ka_lbi_bulk_wrap');
			if (bulkToggle && bulkWrap) {
				bulkToggle.addEventListener('click', function(e) {
					e.preventDefault();
					var showing = bulkWrap.style.display !== 'none';
					bulkWrap.style.display = showing ? 'none' : '';
					bulkToggle.textContent = showing
						? <?php echo wp_json_encode( __( 'Search several cities at once instead »', 'ka-listing-bulk-importer' ) ); ?>
						: <?php echo wp_json_encode( __( '« Use a single city instead', 'ka-listing-bulk-importer' ) ); ?>;
					if (!showing && bulkArea) { bulkArea.focus(); }
					update();
				});
			}
		})();
		</script>
		<?php
		$this->render_discover_history();
		$this->render_footer();
		echo '</div>';
	}

	/** Shows the currently running background job's progress, if any, plus a one-time notice right after starting one. */
	/**
	 * Printed once per page: a small reusable JS helper both the Discover and Photos status
	 * cards use to poll admin-ajax every few seconds and update their numbers live in place —
	 * no manual "Refresh" click needed, and each poll also nudges the job itself forward
	 * (see run_job_tick()/run_photo_job_tick()), so progress keeps moving as long as this tab
	 * is open even on hosts where WP-Cron's own loopback request is unreliable.
	 */
	private function render_job_poll_script() {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;
		?>
		<script>
		window.kaLbiPollJob = function( opts ) {
			var timer = null;
			function tick() {
				var body = 'action=ka_lbi_poll_job&nonce=' + encodeURIComponent( opts.nonce ) + '&job_type=' + encodeURIComponent( opts.type );
				fetch( opts.ajaxUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body } )
					.then( function( r ) { return r.json(); } )
					.then( function( res ) {
						if ( ! res || ! res.success ) { return; }
						var job = res.data && res.data.job;
						if ( ! job ) {
							if ( timer ) { clearInterval( timer ); }
							if ( opts.onDone ) { opts.onDone(); }
							return;
						}
						opts.render( job );
					} )
					.catch( function() { /* a network hiccup — the next tick just tries again */ } );
			}
			tick();
			timer = setInterval( tick, opts.intervalMs || 15000 ); // slow by default — see JOB_MAX_RESULTS_CAP in the PHP; AI-sourced jobs pass a faster interval since each combo is only one HTTP call, not several
			return { stop: function() { clearInterval( timer ); } };
		};
		</script>
		<?php
	}

	private function render_bulk_job_status() {
		if ( isset( $_GET['ka_lbi_job_started'] ) ) {
			echo '<div class="notice notice-success inline" style="padding:8px 12px;margin-top:16px;"><p style="margin:.4em 0;">' . esc_html__( 'Background search started — this page now updates live as it works, even if you never touch it. It also keeps going in the background if you close this page, and you\'ll get an email when it finishes.', 'ka-listing-bulk-importer' ) . '</p></div>';
		}

		$job = $this->get_job();
		if ( ! $job ) {
			$this->render_last_job_error_notice( 'discover' );
			$this->render_last_job_summary( 'discover' );
			return;
		}
		$this->render_job_poll_script();
		$pct = $job['total'] > 0 ? round( ( $job['done'] / $job['total'] ) * 100 ) : 0;
		?>
		<div class="ka-lbi-card" id="ka_lbi_job_card">
			<h2><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Background search in progress', 'ka-listing-bulk-importer' ); ?> <span class="ka-lbi-pill ka-lbi-pill-ok" style="font-weight:400;"><?php esc_html_e( 'live', 'ka-listing-bulk-importer' ); ?></span></h2>
			<p>
				<span id="ka_lbi_job_done"><?php echo (int) $job['done']; ?></span> <?php esc_html_e( 'of', 'ka-listing-bulk-importer' ); ?>
				<span id="ka_lbi_job_total"><?php echo (int) $job['total']; ?></span> <?php esc_html_e( 'city/category combinations done', 'ka-listing-bulk-importer' ); ?>
				(<span id="ka_lbi_job_pct"><?php echo (int) $pct; ?></span>%) —
				<span id="ka_lbi_job_created"><?php echo (int) $job['created']; ?></span> <?php esc_html_e( 'new listing(s) added as Pending so far,', 'ka-listing-bulk-importer' ); ?>
				<span id="ka_lbi_job_skipped"><?php echo (int) $job['skipped']; ?></span> <?php esc_html_e( 'skipped.', 'ka-listing-bulk-importer' ); ?>
			</p>
			<div style="background:#f0f0f1;border-radius:4px;height:10px;overflow:hidden;max-width:420px;">
				<div id="ka_lbi_job_bar" style="background:#2271b1;height:10px;width:<?php echo (int) $pct; ?>%;transition:width .4s ease;"></div>
			</div>
			<p class="description" style="margin-top:10px;" id="ka_lbi_job_detail">
				<span id="ka_lbi_job_empty"><?php echo (int) ( $job['empty_combos'] ?? 0 ); ?></span> <?php esc_html_e( 'search(es) came back with nothing (no confident match found), and', 'ka-listing-bulk-importer' ); ?>
				<span id="ka_lbi_job_errcount"><?php echo (int) ( $job['provider_errors'] ?? 0 ); ?></span> <?php esc_html_e( 'failed to even reach the source.', 'ka-listing-bulk-importer' ); ?>
				<?php if ( ! empty( $job['last_provider_error'] ) ) : ?>
					<br /><strong><?php esc_html_e( 'Last error:', 'ka-listing-bulk-importer' ); ?></strong> <span id="ka_lbi_job_lasterr"><?php echo esc_html( $job['last_provider_error'] ); ?></span>
				<?php endif; ?>
			</p>
			<p style="margin-top:12px;">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_cancel_bulk_job' ), self::DISCOVER_NONCE ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Stop the background search? Whatever it already added stays — only the rest of the queue is cancelled.', 'ka-listing-bulk-importer' ) ); ?>');"><?php esc_html_e( 'Cancel', 'ka-listing-bulk-importer' ); ?></a>
			</p>
			<p class="description" id="ka_lbi_job_hint"><?php esc_html_e( 'Updating live, every few seconds — no need to refresh the page. It also keeps going in the background if this page is closed.', 'ka-listing-bulk-importer' ); ?></p>
		</div>
		<script>
		(function() {
			var doneEl = document.getElementById( 'ka_lbi_job_done' );
			if ( ! doneEl ) { return; }
			window.kaLbiPollJob( {
				type: 'discover',
				ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				nonce: <?php echo wp_json_encode( wp_create_nonce( self::DISCOVER_NONCE ) ); ?>,
				intervalMs: <?php echo ( 'google' !== $job['provider'] ) ? (int) self::JOB_POLL_MS_AI : 15000; ?>,
				render: function( job ) {
					var pct = job.total > 0 ? Math.round( ( job.done / job.total ) * 100 ) : 0;
					document.getElementById( 'ka_lbi_job_done' ).textContent = job.done;
					document.getElementById( 'ka_lbi_job_total' ).textContent = job.total;
					document.getElementById( 'ka_lbi_job_pct' ).textContent = pct;
					document.getElementById( 'ka_lbi_job_created' ).textContent = job.created;
					document.getElementById( 'ka_lbi_job_skipped' ).textContent = job.skipped;
					document.getElementById( 'ka_lbi_job_bar' ).style.width = pct + '%';
					var emptyEl = document.getElementById( 'ka_lbi_job_empty' );
					if ( emptyEl ) { emptyEl.textContent = job.empty_combos || 0; }
					var errEl = document.getElementById( 'ka_lbi_job_errcount' );
					if ( errEl ) { errEl.textContent = job.provider_errors || 0; }
					var detailEl = document.getElementById( 'ka_lbi_job_detail' );
					if ( detailEl && job.last_provider_error ) {
						var lastErrEl = document.getElementById( 'ka_lbi_job_lasterr' );
						if ( lastErrEl ) {
							lastErrEl.textContent = job.last_provider_error;
						} else {
							detailEl.innerHTML += '<br /><strong><?php echo esc_js( __( 'Last error:', 'ka-listing-bulk-importer' ) ); ?></strong> <span id="ka_lbi_job_lasterr"></span>';
							document.getElementById( 'ka_lbi_job_lasterr' ).textContent = job.last_provider_error;
						}
					}
				},
				onDone: function() {
					var hint = document.getElementById( 'ka_lbi_job_hint' );
					if ( hint ) { hint.textContent = <?php echo wp_json_encode( __( 'Finished — reloading…', 'ka-listing-bulk-importer' ) ); ?>; }
					setTimeout( function() { window.location.reload(); }, 1500 );
				}
			} );
		})();
		</script>
		<?php
	}

	/** A card to fill in featured photos for listings that already exist but have none (e.g. anything imported from a CSV without a Photo column). Google Maps only — it's the one source here with real, licensed photos. */
	/** "Backfill missing photos" — its own top-level tab, with its own live-updating background job and run log, mirroring the Discover tab. */
	public function render_photos_page() {
		$this->verify_capability();

		$locations  = get_terms( array( 'taxonomy' => self::TAX_LOCATION, 'hide_empty' => false ) );
		$categories = get_terms( array( 'taxonomy' => self::TAX_CATEGORY, 'hide_empty' => false ) );
		if ( is_wp_error( $locations ) ) {
			$locations = array();
		}
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}
		$google_key = get_option( self::OPTION_KEY_PREFIX . 'google', '' );

		$backfill_result = get_transient( $this->tkey( 'backfill_result' ) );
		if ( $backfill_result ) {
			delete_transient( $this->tkey( 'backfill_result' ) );
		}

		echo '<div class="wrap ka-lbi-wrap"><h1><span class="dashicons dashicons-format-image"></span>' . esc_html__( 'Backfill missing photos', 'ka-listing-bulk-importer' ) . '</h1>';
		$this->render_nav_tabs( 'ka-lbi-photos' );
		?>
		<p class="ka-lbi-intro"><?php esc_html_e( 'For listings that already exist on your site but have no featured photo (e.g. anything imported from a CSV without a Photo column) — looks each one up on Google Maps by name and city, and attaches its real photo when Google has one.', 'ka-listing-bulk-importer' ); ?></p>

		<?php if ( '' === $google_key ) : ?>
			<div class="notice notice-warning inline" style="padding:8px 12px;">
				<p style="margin:.4em 0;">
					<?php
					printf(
						/* translators: %s: link to settings page */
						wp_kses_post( __( 'This needs a working Google Maps (Places API) key — add and save one on the <a href="%s">Settings</a> tab first.', 'ka-listing-bulk-importer' ) ),
						esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-settings' ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<?php if ( $backfill_result ) : ?>
			<div class="notice notice-<?php echo $backfill_result['found'] > 0 ? 'success' : 'info'; ?> inline" style="padding:8px 12px;">
				<p style="margin:.4em 0;">
					<?php
					printf(
						/* translators: 1: number of listings checked, 2: number that got a new photo, 3: number left with no match on Google */
						esc_html__( 'Checked %1$d listing(s): %2$d got a photo, %3$d had no clear match on Google Maps.', 'ka-listing-bulk-importer' ),
						(int) $backfill_result['checked'],
						(int) $backfill_result['found'],
						(int) $backfill_result['not_found']
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-search"></span> <?php esc_html_e( 'Find and attach photos', 'ka-listing-bulk-importer' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::DISCOVER_NONCE ); ?>
				<input type="hidden" name="action" value="ka_lbi_backfill_photos" />
				<table class="form-table">
					<tr>
						<th><label for="ka_lbi_bf_location"><?php esc_html_e( 'City', 'ka-listing-bulk-importer' ); ?></label></th>
						<td>
							<select name="ka_lbi_bf_location" id="ka_lbi_bf_location">
								<option value=""><?php esc_html_e( 'All cities', 'ka-listing-bulk-importer' ); ?></option>
								<?php foreach ( $locations as $loc ) : ?>
									<option value="<?php echo esc_attr( $loc->slug ); ?>"><?php echo esc_html( $loc->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="ka_lbi_bf_category"><?php esc_html_e( 'Category', 'ka-listing-bulk-importer' ); ?></label></th>
						<td>
							<select name="ka_lbi_bf_category" id="ka_lbi_bf_category">
								<option value=""><?php esc_html_e( 'All categories', 'ka-listing-bulk-importer' ); ?></option>
								<?php foreach ( $categories as $cat ) : ?>
									<option value="<?php echo esc_attr( $cat->slug ); ?>"><?php echo esc_html( $cat->name ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Find and attach photos', 'ka-listing-bulk-importer' ), 'primary', 'submit', false, ( '' === $google_key ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
				<p class="description" style="margin-top:8px;">
					<?php
					printf(
						/* translators: %d: PHOTO_SYNC_CAP */
						esc_html__( 'Decided automatically: up to %d listings missing a photo are checked right away and you see the result immediately. More than that runs in the background instead, a few at a time, with live progress right on this page.', 'ka-listing-bulk-importer' ),
						(int) self::PHOTO_SYNC_CAP
					);
					?>
				</p>
			</form>
		</div>

		<?php $this->render_photo_job_status(); ?>

		<?php $this->render_photo_history(); ?>

		<?php
		$this->render_footer();
		echo '</div>';
	}

	/** Live-updating progress card for the Photos background job — same pattern as render_bulk_job_status(). */
	private function render_photo_job_status() {
		if ( isset( $_GET['ka_lbi_job_started'] ) ) {
			echo '<div class="notice notice-success inline" style="padding:8px 12px;margin-top:16px;"><p style="margin:.4em 0;">' . esc_html__( 'Background photo search started — this page now updates live as it works, even if you never touch it. It also keeps going in the background if you close this page, and you\'ll get an email when it finishes.', 'ka-listing-bulk-importer' ) . '</p></div>';
		}

		$job = $this->get_photo_job();
		if ( ! $job ) {
			$this->render_last_job_error_notice( 'photo' );
			return;
		}
		$this->render_job_poll_script();
		$pct = $job['total'] > 0 ? round( ( $job['done'] / $job['total'] ) * 100 ) : 0;
		?>
		<div class="ka-lbi-card" id="ka_lbi_photo_job_card">
			<h2><span class="dashicons dashicons-update"></span> <?php esc_html_e( 'Background photo search in progress', 'ka-listing-bulk-importer' ); ?> <span class="ka-lbi-pill ka-lbi-pill-ok" style="font-weight:400;"><?php esc_html_e( 'live', 'ka-listing-bulk-importer' ); ?></span></h2>
			<p>
				<span id="ka_lbi_photo_job_done"><?php echo (int) $job['done']; ?></span> <?php esc_html_e( 'of', 'ka-listing-bulk-importer' ); ?>
				<span id="ka_lbi_photo_job_total"><?php echo (int) $job['total']; ?></span> <?php esc_html_e( 'listings checked', 'ka-listing-bulk-importer' ); ?>
				(<span id="ka_lbi_photo_job_pct"><?php echo (int) $pct; ?></span>%) —
				<span id="ka_lbi_photo_job_found"><?php echo (int) $job['found']; ?></span> <?php esc_html_e( 'got a photo,', 'ka-listing-bulk-importer' ); ?>
				<span id="ka_lbi_photo_job_notfound"><?php echo (int) $job['not_found']; ?></span> <?php esc_html_e( 'had no clear match.', 'ka-listing-bulk-importer' ); ?>
			</p>
			<div style="background:#f0f0f1;border-radius:4px;height:10px;overflow:hidden;max-width:420px;">
				<div id="ka_lbi_photo_job_bar" style="background:#2271b1;height:10px;width:<?php echo (int) $pct; ?>%;transition:width .4s ease;"></div>
			</div>
			<p style="margin-top:12px;">
				<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_cancel_photo_job' ), self::DISCOVER_NONCE ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Stop the background photo search? Photos already attached stay — only the rest of the queue is cancelled.', 'ka-listing-bulk-importer' ) ); ?>');"><?php esc_html_e( 'Cancel', 'ka-listing-bulk-importer' ); ?></a>
			</p>
			<p class="description" id="ka_lbi_photo_job_hint"><?php esc_html_e( 'Updating live, every few seconds — no need to refresh the page. It also keeps going in the background if this page is closed.', 'ka-listing-bulk-importer' ); ?></p>
		</div>
		<script>
		(function() {
			var doneEl = document.getElementById( 'ka_lbi_photo_job_done' );
			if ( ! doneEl ) { return; }
			window.kaLbiPollJob( {
				type: 'photo',
				ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				nonce: <?php echo wp_json_encode( wp_create_nonce( self::DISCOVER_NONCE ) ); ?>,
				render: function( job ) {
					var pct = job.total > 0 ? Math.round( ( job.done / job.total ) * 100 ) : 0;
					document.getElementById( 'ka_lbi_photo_job_done' ).textContent = job.done;
					document.getElementById( 'ka_lbi_photo_job_total' ).textContent = job.total;
					document.getElementById( 'ka_lbi_photo_job_pct' ).textContent = pct;
					document.getElementById( 'ka_lbi_photo_job_found' ).textContent = job.found;
					document.getElementById( 'ka_lbi_photo_job_notfound' ).textContent = job.not_found;
					document.getElementById( 'ka_lbi_photo_job_bar' ).style.width = pct + '%';
				},
				onDone: function() {
					var hint = document.getElementById( 'ka_lbi_photo_job_hint' );
					if ( hint ) { hint.textContent = <?php echo wp_json_encode( __( 'Finished — reloading…', 'ka-listing-bulk-importer' ) ); ?>; }
					setTimeout( function() { window.location.reload(); }, 1500 );
				}
			} );
		})();
		</script>
		<?php
	}

	/** Recent "Backfill missing photos" runs, newest first — both quick inline runs and finished background jobs. */
	private function render_photo_history() {
		$log = $this->get_photo_log();
		if ( empty( $log ) ) {
			return;
		}
		?>
		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Recent photo runs', 'ka-listing-bulk-importer' ); ?></h2>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'ka-listing-bulk-importer' ); ?></th>
					<th><?php esc_html_e( 'City / Category', 'ka-listing-bulk-importer' ); ?></th>
					<th><?php esc_html_e( 'How', 'ka-listing-bulk-importer' ); ?></th>
					<th><?php esc_html_e( 'Result', 'ka-listing-bulk-importer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( human_time_diff( strtotime( $entry['time'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'ka-listing-bulk-importer' ) ); ?></td>
						<td><?php echo esc_html( $entry['location'] . ' / ' . $entry['category'] ); ?></td>
						<td><?php echo esc_html( 'background' === $entry['mode'] ? __( 'Background job', 'ka-listing-bulk-importer' ) : __( 'Immediate', 'ka-listing-bulk-importer' ) ); ?></td>
						<td>
							<?php if ( ! empty( $entry['error'] ) ) : ?>
								<span class="ka-lbi-pill ka-lbi-pill-bad"><?php esc_html_e( 'Error', 'ka-listing-bulk-importer' ); ?></span> <?php echo esc_html( $entry['error'] ); ?>
							<?php else : ?>
								<?php printf( esc_html__( '%1$d checked, %2$d got a photo, %3$d no match', 'ka-listing-bulk-importer' ), (int) $entry['checked'], (int) $entry['found'], (int) $entry['not_found'] ); ?>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/** Recent Discover runs, newest first — a quick way to see whether a city/category combo has already been searched (and billed for) recently. */
	private function render_discover_history() {
		$log = array_reverse( (array) get_option( self::OPTION_DISCOVER_LOG, array() ) );
		if ( empty( $log ) ) {
			return;
		}
		$log = array_slice( $log, 0, 15 );
		?>
		<div class="ka-lbi-card">
			<h2><span class="dashicons dashicons-backup"></span> <?php esc_html_e( 'Recent Discover searches', 'ka-listing-bulk-importer' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Check here before re-running the same city and category — every search you run again spends the source\'s quota (and, for paid sources, money) a second time.', 'ka-listing-bulk-importer' ); ?></p>
			<table class="widefat striped">
				<thead><tr>
					<th><?php esc_html_e( 'When', 'ka-listing-bulk-importer' ); ?></th>
					<th><?php esc_html_e( 'City', 'ka-listing-bulk-importer' ); ?></th>
					<th><?php esc_html_e( 'Category', 'ka-listing-bulk-importer' ); ?></th>
					<th><?php esc_html_e( 'Source', 'ka-listing-bulk-importer' ); ?></th>
					<th><?php esc_html_e( 'Results', 'ka-listing-bulk-importer' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( human_time_diff( strtotime( $entry['time'] ), current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'ka-listing-bulk-importer' ) ); ?></td>
						<td><?php echo esc_html( $entry['location'] ); ?></td>
						<td><?php echo esc_html( $entry['category'] ); ?></td>
						<td><?php echo esc_html( $entry['provider'] ); ?></td>
						<td><?php printf( esc_html__( '%1$d found, %2$d kept', 'ka-listing-bulk-importer' ), (int) $entry['found'], (int) $entry['kept'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Existing listings already tagged with both this location and this
	 * category — used for the "see what already exists" preview on the
	 * Discover tab, before any search is even run.
	 */
	private function find_existing_for_place( $location_term, $category_term = null ) {
		$tax_query = array(
			array(
				'taxonomy' => self::TAX_LOCATION,
				'field'    => 'term_id',
				'terms'    => array( $location_term->term_id ),
			),
		);
		if ( $category_term ) {
			$tax_query['relation'] = 'AND';
			$tax_query[]           = array(
				'taxonomy' => self::TAX_CATEGORY,
				'field'    => 'term_id',
				'terms'    => array( $category_term->term_id ),
			);
		}
		$q = new WP_Query( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
			'posts_per_page' => 100,
			'no_found_rows'  => true,
			'tax_query'      => $tax_query,
		) );

		$rows = array();
		foreach ( $q->posts as $post ) {
			$rows[] = array(
				'id'     => $post->ID,
				'title'  => get_the_title( $post ),
				'status' => get_post_status( $post ),
				'edit'   => get_edit_post_link( $post->ID, 'raw' ),
				'phone'  => get_post_meta( $post->ID, 'phone', true ),
				'thumb'  => get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
			);
		}
		return $rows;
	}

	public function handle_discover() {
		$this->verify_capability();
		check_admin_referer( self::DISCOVER_NONCE );

		$location     = isset( $_POST['ka_lbi_location'] ) ? sanitize_text_field( wp_unslash( $_POST['ka_lbi_location'] ) ) : '';
		$bulk_raw     = isset( $_POST['ka_lbi_locations_bulk'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ka_lbi_locations_bulk'] ) ) : '';
		$cat_slugs    = isset( $_POST['ka_lbi_category'] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['ka_lbi_category'] ) ) ) ) ) : array();
		$provider     = isset( $_POST['ka_lbi_provider'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_provider'] ) ) : '';
		$max          = isset( $_POST['ka_lbi_max_results'] ) ? absint( $_POST['ka_lbi_max_results'] ) : 20;
		$status       = isset( $_POST['ka_lbi_status'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_status'] ) ) : 'pending';
		$mode         = isset( $_POST['ka_lbi_mode'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_mode'] ) ) : 'all';

		$max = max( 1, min( self::MAX_DISCOVER_RESULTS, $max ) );
		if ( ! in_array( $status, array( 'pending', 'draft', 'publish' ), true ) ) {
			$status = 'pending';
		}
		if ( ! in_array( $mode, array( 'all', 'new_only', 'update_existing' ), true ) ) {
			$mode = 'all';
		}
		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			$this->die_back( __( 'Please choose a data source.', 'ka-listing-bulk-importer' ) );
		}

		// One city (the normal case), or several at once separated by commas/new lines.
		if ( '' !== $bulk_raw ) {
			$location_list = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $bulk_raw ) ) ) ) );
		} elseif ( '' !== $location ) {
			$location_list = array( $location );
		} else {
			$location_list = array();
		}
		if ( empty( $location_list ) ) {
			$this->die_back( __( 'Please enter at least one city or village.', 'ka-listing-bulk-importer' ) );
		}

		$all_categories = empty( $cat_slugs );
		if ( $all_categories ) {
			$search_categories = get_terms( array( 'taxonomy' => self::TAX_CATEGORY, 'hide_empty' => false ) );
			if ( is_wp_error( $search_categories ) || empty( $search_categories ) ) {
				$this->die_back( __( 'No categories exist yet — add at least one under Listings → Categories first.', 'ka-listing-bulk-importer' ) );
			}
		} else {
			$search_categories = array();
			foreach ( $cat_slugs as $one_slug ) {
				$term = get_term_by( 'slug', $one_slug, self::TAX_CATEGORY );
				if ( $term && ! is_wp_error( $term ) ) {
					$search_categories[] = $term;
				}
			}
			if ( empty( $search_categories ) ) {
				$this->die_back( __( 'Please choose at least one valid category.', 'ka-listing-bulk-importer' ) );
			}
			$category = $search_categories[0]; // used only for the single-category label/messages below
		}

		// Decided automatically, not by asking the user to pick a button: a workload this large cannot
		// finish inside one synchronous page load with a preview, so it's handed straight to the same
		// background-job path as "Run in background" used to be — no separate button needed for this.
		$combo_count = count( $location_list ) * count( $search_categories );
		if ( $combo_count > self::MAX_DISCOVER_COMBOS ) {
			$this->handle_start_bulk_job();
			return; // handle_start_bulk_job() always redirects and exits; this return is just for clarity/safety.
		}

		$key = get_option( self::OPTION_KEY_PREFIX . $provider, '' );
		if ( '' === $key ) {
			$this->die_back( sprintf(
				/* translators: %s: provider label */
				__( 'No API key saved for %s yet. Add one on the Settings tab first.', 'ka-listing-bulk-importer' ),
				self::PROVIDERS[ $provider ]['label']
			) );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( min( 300, 60 + ( 30 * $combo_count ) ) );
		}

		// Wrapped defensively: this runs live provider calls and JSON parsing that were never
		// exercised in production until real API keys were saved, so an uncaught PHP error here
		// used to take down the whole admin-post.php request as a white-screen "critical error"
		// instead of showing a normal, readable message. Same pattern as the background job ticks.
		try {
			$found = array();
			$this->last_search_error = '';
			foreach ( $location_list as $loc ) {
				$found = array_merge( $found, $this->search_categories_for_location( $provider, $key, $loc, $search_categories, $max ) );
			}

			if ( empty( $found ) ) {
				$this->die_back( $this->last_search_error
					? $this->last_search_error
					: __( 'No results found. Try a different place, category or source.', 'ka-listing-bulk-importer' )
				);
			}

			$records = array();
			$row_i   = 0;
			$skipped_existing = 0;
			foreach ( $found as $fields ) {
				$record = $this->make_record( $row_i, $fields );
				if ( 'new_only' === $mode && ! empty( $record['duplicates'] ) ) {
					$skipped_existing++;
					continue; // Leave it out entirely — the user only wants genuinely new businesses.
				}
				$records[] = $record;
				$row_i++;
			}

			if ( empty( $records ) ) {
				$this->die_back( __( 'Every result matched something already on your site, and "Only find new ones" is on — nothing new to review.', 'ka-listing-bulk-importer' ) );
			}

			set_transient( $this->tkey( 'records' ), $records, self::TRANSIENT_TTL );
			set_transient( $this->tkey( 'status' ), $status, self::TRANSIENT_TTL );
			set_transient( $this->tkey( 'mode' ), $mode, self::TRANSIENT_TTL );

			$is_ai           = self::PROVIDERS[ $provider ]['is_ai'];
			if ( $all_categories ) {
				$category_label = __( 'all categories', 'ka-listing-bulk-importer' );
			} elseif ( count( $search_categories ) > 1 ) {
				$category_label = sprintf(
					/* translators: %d: number of categories selected */
					__( '%d selected categories', 'ka-listing-bulk-importer' ),
					count( $search_categories )
				);
			} else {
				$category_label = $category->name;
			}
			$location_label  = ( count( $location_list ) > 1 )
				? sprintf(
					/* translators: 1: first city, 2: number of additional cities */
					__( '%1$s + %2$d more cities', 'ka-listing-bulk-importer' ),
					$location_list[0],
					count( $location_list ) - 1
				)
				: $location_list[0];
			$note_text = $is_ai
				? sprintf(
					/* translators: 1: provider label, 2: result count, 3: location, 4: category */
					__( '%1$d results suggested by %2$s for "%3$s" (%4$s). These come from the model\'s own knowledge, not a live search — verify address and phone before publishing.', 'ka-listing-bulk-importer' ),
					count( $records ),
					self::PROVIDERS[ $provider ]['label'],
					$location_label,
					$category_label
				)
				: sprintf(
					/* translators: 1: result count, 2: location, 3: category */
					__( '%1$d results found on Google Maps for "%2$s" (%3$s).', 'ka-listing-bulk-importer' ),
					count( $records ),
					$location_label,
					$category_label
				);
			if ( $skipped_existing > 0 ) {
				$note_text .= ' ' . sprintf(
					/* translators: %d: number of results left out */
					__( '%d more were left out because they matched something already on your site.', 'ka-listing-bulk-importer' ),
					$skipped_existing
				);
			}
			set_transient( $this->tkey( 'source_note' ), array(
				'warn' => $is_ai,
				'text' => $note_text,
			), self::TRANSIENT_TTL );

			// Track a rough running spend estimate against this provider's own admin-entered per-result cost.
			$active_model = $is_ai ? get_option( self::OPTION_MODEL_PREFIX . $provider, self::PROVIDERS[ $provider ]['default_model'] ) : '';
			$cost_each    = (float) get_option( $this->cost_option_key( $provider, $active_model ), 0 );
			if ( $cost_each > 0 ) {
				$spend_option = self::OPTION_SPEND_PREFIX . $provider;
				$spend        = (float) get_option( $spend_option, 0 );
				update_option( $spend_option, $spend + ( $cost_each * count( $found ) ), false );
			}

			$this->log_discover_search( $location_label, $category_label, $provider, $mode, count( $found ), count( $records ) );
		} catch ( \Throwable $e ) {
			$this->log_job_error( 'interactive', $e->getMessage() . ' (' . $e->getFile() . ':' . $e->getLine() . ')' );
			$this->die_back(
				sprintf(
					/* translators: %s: the technical error message, kept on-screen instead of a white-screen crash */
					__( 'Something went wrong while searching: %s. This has been logged; try a smaller "How many results" or a different source.', 'ka-listing-bulk-importer' ),
					$e->getMessage()
				)
			);
			return;
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-import&step=preview' ) );
		exit;
	}

	/**
	 * Search one city across one or more categories with the given provider,
	 * merging the results and capping the total at $max. Never dies or throws —
	 * this runs from both an interactive request and unattended WP-Cron, so
	 * any provider error is recorded in $this->last_search_error for the
	 * caller to surface however fits its own context, and the search simply
	 * continues with whatever categories did work.
	 */
	private function search_categories_for_location( $provider, $key, $location, array $search_categories, $max ) {
		$multi_category    = count( $search_categories ) > 1;
		$per_category_max  = $multi_category ? max( 1, (int) ceil( $max / count( $search_categories ) ) ) : $max;
		$found             = array();
		foreach ( $search_categories as $cat ) {
			if ( count( $found ) >= $max ) {
				break;
			}
			$remaining = $max - count( $found );
			$take      = min( $per_category_max, $remaining );
			$batch     = ( 'google' === $provider )
				? $this->discover_google( $key, $location, $cat, $take )
				: $this->discover_ai( $provider, $key, $location, $cat, $take );

			if ( is_wp_error( $batch ) ) {
				$this->last_search_error = $batch->get_error_message();
				continue;
			}
			if ( ! empty( $batch ) ) {
				$found = array_merge( $found, array_slice( $batch, 0, $remaining ) );
			}
		}
		return $found;
	}

	/** Keep a short rolling history of Discover runs so the same city/category combo isn't re-searched (and re-billed) by accident. */
	private function log_discover_search( $location_label, $category_label, $provider, $mode, $found_count, $kept_count ) {
		$log   = get_option( self::OPTION_DISCOVER_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$log[] = array(
			'time'     => current_time( 'mysql' ),
			'location' => $location_label,
			'category' => $category_label,
			'provider' => self::PROVIDERS[ $provider ]['label'] ?? $provider,
			'mode'     => $mode,
			'found'    => (int) $found_count,
			'kept'     => (int) $kept_count,
		);
		if ( count( $log ) > 40 ) {
			$log = array_slice( $log, -40 );
		}
		update_option( self::OPTION_DISCOVER_LOG, $log, false );
	}

	/* ------------------------------------------------------------------ */
	/* Background bulk Discover job — "run every category, no combo cap"  */
	/* ------------------------------------------------------------------ */

	/**
	 * The interactive Discover form is capped at MAX_DISCOVER_COMBOS because
	 * it runs synchronously inside one page load — a hosting timeout would
	 * kill a 69-category search halfway through with nothing to show for it.
	 * This is the alternative: queue every city×category combination and
	 * work through it a couple at a time via WP-Cron, so there is no combo
	 * limit at all. It trades the interactive preview for that — anything
	 * found that is clearly new is imported straight away as "Pending";
	 * anything ambiguous (a possible duplicate, a missing title) is left out
	 * entirely rather than guessed at, and a summary is emailed when it's done.
	 */
	public function handle_start_bulk_job() {
		$this->verify_capability();
		check_admin_referer( self::DISCOVER_NONCE );

		if ( $this->get_job() ) {
			$this->die_back( __( 'A background search is already running. Wait for it to finish (or cancel it) before starting another.', 'ka-listing-bulk-importer' ) );
		}

		$location  = isset( $_POST['ka_lbi_location'] ) ? sanitize_text_field( wp_unslash( $_POST['ka_lbi_location'] ) ) : '';
		$bulk_raw  = isset( $_POST['ka_lbi_locations_bulk'] ) ? sanitize_textarea_field( wp_unslash( $_POST['ka_lbi_locations_bulk'] ) ) : '';
		$cat_slugs = isset( $_POST['ka_lbi_category'] ) ? array_values( array_unique( array_filter( array_map( 'sanitize_key', (array) wp_unslash( $_POST['ka_lbi_category'] ) ) ) ) ) : array();
		$provider  = isset( $_POST['ka_lbi_provider'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_provider'] ) ) : '';
		$max       = isset( $_POST['ka_lbi_max_results'] ) ? absint( $_POST['ka_lbi_max_results'] ) : 20;
		$max       = max( 1, min( self::MAX_DISCOVER_RESULTS, $max ) );

		if ( ! isset( self::PROVIDERS[ $provider ] ) ) {
			$this->die_back( __( 'Please choose a data source.', 'ka-listing-bulk-importer' ) );
		}
		if ( '' === get_option( self::OPTION_KEY_PREFIX . $provider, '' ) ) {
			$this->die_back( sprintf(
				/* translators: %s: provider label */
				__( 'No API key saved for %s yet. Add one on the Settings tab first.', 'ka-listing-bulk-importer' ),
				self::PROVIDERS[ $provider ]['label']
			) );
		}

		if ( '' !== $bulk_raw ) {
			$location_list = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/[\r\n,]+/', $bulk_raw ) ) ) ) );
		} elseif ( '' !== $location ) {
			$location_list = array( $location );
		} else {
			$location_list = array();
		}
		if ( empty( $location_list ) ) {
			$this->die_back( __( 'Please enter at least one city or village.', 'ka-listing-bulk-importer' ) );
		}

		if ( empty( $cat_slugs ) ) {
			$cat_terms = get_terms( array( 'taxonomy' => self::TAX_CATEGORY, 'hide_empty' => false ) );
			if ( is_wp_error( $cat_terms ) || empty( $cat_terms ) ) {
				$this->die_back( __( 'No categories exist yet — add at least one under Listings → Categories first.', 'ka-listing-bulk-importer' ) );
			}
			$cat_slugs = wp_list_pluck( $cat_terms, 'slug' );
		}

		$queue = array();
		foreach ( $location_list as $loc ) {
			foreach ( $cat_slugs as $cat_slug ) {
				$queue[] = array( 'location' => $loc, 'category' => $cat_slug );
			}
		}

		$job = array(
			'id'                => wp_generate_uuid4(),
			'queue'             => $queue,
			'total'             => count( $queue ),
			'done'              => 0,
			'created'           => 0,
			'skipped'           => 0,
			'empty_combos'      => 0, // combos that came back with zero results but no error — e.g. the AI simply doesn't know of a real match, or Google Maps genuinely has none
			'provider_errors'   => 0, // combos where the provider call itself failed (bad key, quota, network, parse failure)
			'last_provider_error' => '',
			'provider'   => $provider,
			'max'        => $max,
			'started_by' => get_current_user_id(),
			'started_at' => current_time( 'mysql' ),
			'updated_at' => 0,
		);
		update_option( self::OPTION_JOB, $job, false );
		delete_option( 'ka_lbi_last_job_error_discover' );

		// Process the first batch right now, inside this same request, instead of only relying on
		// WP-Cron/spawn_cron to pick it up — on hosts where the background loopback request doesn't
		// fire reliably (common with a firewall/WAF in front, or WP-Cron disabled in favor of a slower
		// system cron), this is what guarantees the job visibly starts moving instead of sitting at 0%.
		// run_job_tick() itself schedules the next WP-Cron tick (as a fallback for once this admin
		// closes the page), so nothing further needs scheduling here.
		$this->run_job_tick( true );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-discover&ka_lbi_job_started=1' ) );
		exit;
	}

	public function handle_cancel_bulk_job() {
		$this->verify_capability();
		check_admin_referer( self::DISCOVER_NONCE );
		delete_option( self::OPTION_JOB );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-discover' ) );
		exit;
	}

	private function get_job() {
		$job = get_option( self::OPTION_JOB, null );
		return is_array( $job ) ? $job : null;
	}

	/** Fired by WP-Cron every ~JOB_TICK_DELAY seconds while a background job is in progress — the fallback path for whenever no admin has the live status card open. Always processes, since cron itself already only fires this rarely. */
	public function process_job_batch() {
		$this->run_job_tick( true );
	}

	/**
	 * Processes one small batch of city×category combinations, imports whatever is clearly
	 * new as "Pending", and reschedules the next WP-Cron tick until the queue is empty — never
	 * all 69 at once, so no single request ever risks a hosting timeout.
	 *
	 * Called from three places: the WP-Cron tick above (always forced), the live AJAX poll used
	 * by the status card while an admin has it open (rate-limited to once per JOB_MIN_GAP so a
	 * fast poll loop doesn't hammer the provider's API), and once synchronously right when the
	 * job is created so it visibly starts moving immediately rather than waiting on WP-Cron/
	 * spawn_cron, which is not reliable on every host (WP-Cron disabled, a firewall blocking the
	 * loopback request, etc.). A short transient lock keeps two of these from ever double-processing
	 * the same batch if they land at the same moment.
	 *
	 * @param bool $force Skip the JOB_MIN_GAP throttle (used by WP-Cron and the initial kick-off).
	 * @return array|null The job's current state (after processing, if it ran), or null once finished.
	 */
	private function run_job_tick( $force = false ) {
		$job = $this->get_job();
		if ( ! $job || empty( $job['queue'] ) ) {
			if ( $job ) {
				$this->finish_job( $job );
			}
			return null;
		}

		$is_ai_job = ( 'google' !== $job['provider'] );
		$batch_size = $is_ai_job ? self::JOB_BATCH_SIZE_AI : self::JOB_BATCH_SIZE;
		$min_gap    = $is_ai_job ? self::JOB_MIN_GAP_AI : self::JOB_MIN_GAP;
		$tick_delay = $is_ai_job ? self::JOB_TICK_DELAY_AI : self::JOB_TICK_DELAY;

		if ( ! $force ) {
			$last = isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0;
			if ( $last && ( time() - $last ) < $min_gap ) {
				return $job; // too soon since the last tick — report current state without doing more work yet
			}
		}
		if ( get_transient( self::JOB_LOCK_KEY ) ) {
			return $job; // another tick (cron or another poll) is processing this exact moment
		}
		set_transient( self::JOB_LOCK_KEY, 1, 30 );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 );
		}

		$key = get_option( self::OPTION_KEY_PREFIX . $job['provider'], '' );
		if ( '' === $key ) {
			$job['error'] = __( 'API key was removed while this job was running.', 'ka-listing-bulk-importer' );
			$this->finish_job( $job );
			delete_transient( self::JOB_LOCK_KEY );
			return null;
		}

		// Everything below talks to an external provider and writes posts — wrapped so that if
		// anything throws (a bad API response, an unexpected null, etc.) the job fails loudly and
		// visibly (logged, shown on this page, emailed) instead of silently freezing forever at
		// its current percentage the way an uncaught fatal here used to.
		try {
			$batch = array_splice( $job['queue'], 0, $batch_size );
			foreach ( $batch as $pair ) {
				$cat_term = get_term_by( 'slug', $pair['category'], self::TAX_CATEGORY );
				if ( ! $cat_term ) {
					$job['done']++;
					continue;
				}
				// Hard-capped here (not just at job creation) so this protects an already-queued
				// job too — e.g. one saved with a high "how many results" before this cap existed.
				$safe_max = min( (int) $job['max'], self::JOB_MAX_RESULTS_CAP );
				$found    = ( 'google' === $job['provider'] )
					? $this->discover_google( $key, $pair['location'], $cat_term, $safe_max )
					: $this->discover_ai( $job['provider'], $key, $pair['location'], $cat_term, $safe_max );

				if ( is_wp_error( $found ) ) {
					$job['provider_errors']++;
					$job['last_provider_error'] = $found->get_error_message();
				} elseif ( empty( $found ) ) {
					$job['empty_combos']++;
				}

				if ( ! is_wp_error( $found ) ) {
					foreach ( $found as $i => $fields ) {
						$record = $this->make_record( $i, $fields );
						if ( ! empty( $record['errors'] ) || ! empty( $record['duplicates'] ) ) {
							$job['skipped']++;
							continue; // background jobs only ever add clearly-new listings — anything ambiguous needs a human, via the normal Discover preview
						}
						$write = $this->write_row( $record['fields'], 'pending', 0 );
						if ( ! is_wp_error( $write['post_id'] ) ) {
							$job['created']++;
						} else {
							$job['skipped']++;
						}
					}
					$this->log_discover_search( $pair['location'], $cat_term->name, $job['provider'], 'new_only', count( $found ), 0 );

					$is_ai        = self::PROVIDERS[ $job['provider'] ]['is_ai'];
					$active_model = $is_ai ? get_option( self::OPTION_MODEL_PREFIX . $job['provider'], self::PROVIDERS[ $job['provider'] ]['default_model'] ) : '';
					$cost_each    = (float) get_option( $this->cost_option_key( $job['provider'], $active_model ), 0 );
					if ( $cost_each > 0 && ! empty( $found ) ) {
						$spend_option = self::OPTION_SPEND_PREFIX . $job['provider'];
						update_option( $spend_option, (float) get_option( $spend_option, 0 ) + ( $cost_each * count( $found ) ), false );
					}
				}
				$job['done']++;
			}
		} catch ( \Throwable $e ) {
			$job['error'] = sprintf(
				/* translators: %s: the underlying PHP error message */
				__( 'The background search stopped because of an unexpected error: %s', 'ka-listing-bulk-importer' ),
				$e->getMessage()
			);
			$this->log_job_error( 'discover', $job['error'] . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			$this->finish_job( $job );
			delete_transient( self::JOB_LOCK_KEY );
			return null;
		}
		$job['updated_at'] = time();

		if ( empty( $job['queue'] ) ) {
			$this->finish_job( $job );
			delete_transient( self::JOB_LOCK_KEY );
			return null;
		}

		update_option( self::OPTION_JOB, $job, false );
		wp_schedule_single_event( time() + $tick_delay, self::JOB_CRON_HOOK );
		delete_transient( self::JOB_LOCK_KEY );
		return $job;
	}

	/** Records the last background-job error somewhere visible even if wp_mail() never actually gets delivered (very common on hosts with no SMTP configured) — shown as a notice on the Discover/Photos page until dismissed by starting a new run. */
	private function log_job_error( $which, $message ) {
		error_log( '[KA Listing Bulk Importer] ' . $which . ' job error: ' . $message ); // also in the normal PHP error log, for anyone with server access
		update_option( 'ka_lbi_last_job_error_' . $which, array(
			'time'    => current_time( 'mysql' ),
			'message' => $message,
		), false );
	}

	/** Shows (and offers to dismiss) the last recorded background-job error, if any and no job is currently running — the on-screen fallback for when the summary email never arrives (no SMTP configured is common on shared/managed hosting). */
	private function render_last_job_error_notice( $which ) {
		$err = get_option( 'ka_lbi_last_job_error_' . $which, null );
		if ( ! is_array( $err ) || empty( $err['message'] ) ) {
			return;
		}
		?>
		<div class="notice notice-error inline" style="padding:8px 12px;">
			<p style="margin:.4em 0;">
				<strong><?php esc_html_e( 'The last background run ended with an error:', 'ka-listing-bulk-importer' ); ?></strong>
				<?php echo esc_html( $err['message'] ); ?>
				<span style="color:#646970;">(<?php echo esc_html( $err['time'] ); ?>)</span>
			</p>
			<p style="margin:.4em 0;">
				<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_dismiss_job_error&which=' . rawurlencode( $which ) ), self::DISCOVER_NONCE ) ); ?>"><?php esc_html_e( 'Dismiss', 'ka-listing-bulk-importer' ); ?></a>
			</p>
		</div>
		<?php
	}

	/** Shows what the last finished background run actually did, since the live card disappears once it's done and the summary email can't be relied on (no SMTP configured is common on shared/managed hosting). Explains a "0 added" result instead of leaving it a mystery. */
	private function render_last_job_summary( $which ) {
		$s = get_option( 'ka_lbi_last_job_summary_' . $which, null );
		if ( ! is_array( $s ) || empty( $s['time'] ) ) {
			return;
		}
		$provider_label = isset( self::PROVIDERS[ $s['provider'] ] ) ? self::PROVIDERS[ $s['provider'] ]['label'] : $s['provider'];
		?>
		<div class="notice notice-info inline" style="padding:8px 12px;">
			<p style="margin:.4em 0;">
				<strong><?php esc_html_e( 'Last background run:', 'ka-listing-bulk-importer' ); ?></strong>
				<?php
				printf(
					/* translators: 1: source label, 2: combos processed, 3: listings added, 4: listings skipped */
					esc_html__( '%1$s — processed %2$d combination(s), added %3$d new listing(s), skipped %4$d.', 'ka-listing-bulk-importer' ),
					esc_html( $provider_label ),
					(int) $s['done'],
					(int) $s['created'],
					(int) $s['skipped']
				);
				?>
				<span style="color:#646970;">(<?php echo esc_html( $s['time'] ); ?>)</span>
			</p>
			<?php if ( (int) $s['created'] === 0 && (int) $s['done'] > 0 ) : ?>
				<p style="margin:.4em 0;">
					<?php
					if ( ! empty( $s['provider_errors'] ) ) {
						printf(
							/* translators: %d: number of failed combinations */
							esc_html__( 'Nothing was added because %d of the searches failed to even reach the source (bad key, quota, or a network problem) — see the error below.', 'ka-listing-bulk-importer' ),
							(int) $s['provider_errors']
						);
					} else {
						esc_html_e( 'Nothing was added because every search came back empty — the source had no confident match for any of these city/category combinations, rather than something breaking. Try a broader or more common category (e.g. restaurants) to confirm the search itself still works.', 'ka-listing-bulk-importer' );
					}
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $s['last_provider_error'] ) ) : ?>
				<p style="margin:.4em 0;"><strong><?php esc_html_e( 'Last error message seen:', 'ka-listing-bulk-importer' ); ?></strong> <?php echo esc_html( $s['last_provider_error'] ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handle_dismiss_job_error() {
		$this->verify_capability();
		check_admin_referer( self::DISCOVER_NONCE );
		$which = isset( $_GET['which'] ) ? sanitize_key( wp_unslash( $_GET['which'] ) ) : '';
		if ( in_array( $which, array( 'discover', 'photo' ), true ) ) {
			delete_option( 'ka_lbi_last_job_error_' . $which );
		}
		$page = ( 'photo' === $which ) ? 'ka-lbi-photos' : 'ka-lbi-discover';
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=' . $page ) );
		exit;
	}

	/**
	 * AJAX endpoint the Discover and Photos status cards poll every few seconds while a background
	 * job is visibly in progress, so the admin sees live movement instead of having to click a
	 * manual "Refresh" link — and, because it also nudges the job forward itself (see run_job_tick()/
	 * run_photo_job_tick()), it keeps the job progressing reliably as long as that tab stays open,
	 * independent of whether this host's WP-Cron/spawn_cron loopback actually works.
	 */
	public function handle_job_poll() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ) ), 403 );
		}
		check_ajax_referer( self::DISCOVER_NONCE, 'nonce' );

		$type = isset( $_POST['job_type'] ) ? sanitize_key( wp_unslash( $_POST['job_type'] ) ) : 'discover';
		try {
			$job = ( 'photo' === $type ) ? $this->run_photo_job_tick( false ) : $this->run_job_tick( false );
		} catch ( \Throwable $e ) {
			// Belt-and-suspenders: run_job_tick()/run_photo_job_tick() already catch their own
			// errors, but this outer guard makes sure a poll NEVER returns raw HTML on a fatal —
			// that would silently break the JS polling loop (it expects JSON) with nothing shown.
			$this->log_job_error( $type, $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}

		wp_send_json_success( array( 'job' => $job ) );
	}

	private function finish_job( array $job ) {
		delete_option( self::OPTION_JOB );

		// Kept even though the job option itself is gone, so the Discover page can still explain
		// what happened on the next visit — the live status card disappears once the job is done,
		// and the summary email is unreliable on hosts with no SMTP configured (common here).
		update_option( 'ka_lbi_last_job_summary_discover', array(
			'time'             => current_time( 'mysql' ),
			'done'             => (int) $job['done'],
			'created'          => (int) $job['created'],
			'skipped'          => (int) $job['skipped'],
			'empty_combos'     => (int) ( $job['empty_combos'] ?? 0 ),
			'provider_errors'  => (int) ( $job['provider_errors'] ?? 0 ),
			'last_provider_error' => $job['last_provider_error'] ?? '',
			'provider'         => $job['provider'] ?? '',
		), false );

		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$subject = sprintf(
			/* translators: 1: site name, 2: number of listings created */
			__( '[%1$s] Background Discover run finished: %2$d new listing(s) added', 'ka-listing-bulk-importer' ),
			get_bloginfo( 'name' ),
			(int) $job['created']
		);
		$body  = ! empty( $job['error'] )
			? sprintf( __( 'The background search stopped early: %s', 'ka-listing-bulk-importer' ), $job['error'] ) . "\n\n"
			: '';
		$body .= sprintf(
			/* translators: 1: combinations processed, 2: listings added, 3: listings skipped */
			__( 'Processed %1$d city/category combination(s): %2$d new listing(s) added as Pending, %3$d skipped (already existed or had a problem).', 'ka-listing-bulk-importer' ),
			(int) $job['done'],
			(int) $job['created'],
			(int) $job['skipped']
		) . "\n\n";
		if ( ! empty( $job['empty_combos'] ) || ! empty( $job['provider_errors'] ) ) {
			$body .= sprintf(
				/* translators: 1: combos with zero results, 2: combos where the provider call itself failed */
				__( 'Of those: %1$d search(es) came back with nothing (the source had no confident match), %2$d failed to even reach the source.', 'ka-listing-bulk-importer' ),
				(int) ( $job['empty_combos'] ?? 0 ),
				(int) ( $job['provider_errors'] ?? 0 )
			) . "\n";
			if ( ! empty( $job['last_provider_error'] ) ) {
				$body .= sprintf( __( 'Last error message seen: %s', 'ka-listing-bulk-importer' ), $job['last_provider_error'] ) . "\n";
			}
			$body .= "\n";
		}
		$body .= __( 'Review and publish the new ones here:', 'ka-listing-bulk-importer' ) . "\n";
		$body .= admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&post_status=pending' ) . "\n";

		wp_mail( $to, $subject, $body );
	}

	/**
	 * Search Google Places Text Search for businesses, then fetch a Place
	 * Details call per result for phone/website/photo. Every value returned
	 * comes straight from Google's own live database.
	 */
	private function discover_google( $key, $location, $category, $max ) {
		$query = sprintf( '%s persisch iranisch %s', $category->name, $location );
		$rows  = array();
		$token = '';
		$pages = 0;

		do {
			$args = array(
				'query' => $query,
				'key'   => $key,
			);
			if ( $token ) {
				$args['pagetoken'] = $token;
			}
			$url      = add_query_arg( $args, 'https://maps.googleapis.com/maps/api/place/textsearch/json' );
			$response = wp_remote_get( $url, array( 'timeout' => 6 ) );
			if ( is_wp_error( $response ) ) {
				return $response;
			}
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			if ( ! is_array( $body ) || ! in_array( $body['status'] ?? '', array( 'OK', 'ZERO_RESULTS' ), true ) ) {
				$detail = is_array( $body ) && ! empty( $body['error_message'] ) ? ': ' . $body['error_message'] : '';
				return new WP_Error( 'ka_lbi_google_search', sprintf(
					/* translators: 1: Google status string 2: detail */
					__( 'Google Places search failed (%1$s)%2$s', 'ka-listing-bulk-importer' ),
					$body['status'] ?? 'unknown',
					$detail
				) );
			}

			foreach ( (array) ( $body['results'] ?? array() ) as $place ) {
				if ( count( $rows ) >= $max ) {
					break;
				}
				$rows[] = $this->google_place_to_fields( $key, $place, $category, $location );
			}

			$token = ( count( $rows ) < $max ) ? ( $body['next_page_token'] ?? '' ) : '';
			$pages++;
			if ( $token ) {
				sleep( 2 ); // Google requires a short delay before a page token becomes valid.
			}
		} while ( $token && $pages < 3 && count( $rows ) < $max );

		return $rows;
	}

	private function google_place_to_fields( $key, $place, $category, $location ) {
		$fields = array(
			'post_title' => isset( $place['name'] ) ? sanitize_text_field( $place['name'] ) : '',
			'category'   => $category->slug,
			'location'   => $location,
			'gAddress'   => isset( $place['formatted_address'] ) ? sanitize_text_field( $place['formatted_address'] ) : '',
		);

		if ( empty( $place['place_id'] ) ) {
			return $fields;
		}

		$details_url = add_query_arg( array(
			'place_id' => $place['place_id'],
			'fields'   => 'international_phone_number,website,photo',
			'key'      => $key,
		), 'https://maps.googleapis.com/maps/api/place/details/json' );

		$response = wp_remote_get( $details_url, array( 'timeout' => 6 ) );
		if ( is_wp_error( $response ) ) {
			return $fields;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$res  = is_array( $body ) ? ( $body['result'] ?? array() ) : array();

		if ( ! empty( $res['international_phone_number'] ) ) {
			$fields['phone'] = sanitize_text_field( $res['international_phone_number'] );
		}
		if ( ! empty( $res['website'] ) ) {
			$fields['website'] = esc_url_raw( $res['website'] );
		}
		if ( ! empty( $res['photos'][0]['photo_reference'] ) ) {
			$fields['featured_photo'] = add_query_arg( array(
				'maxwidth'      => 1000,
				'photoreference' => $res['photos'][0]['photo_reference'],
				'key'           => $key,
			), 'https://maps.googleapis.com/maps/api/place/photo' );
		}

		return $fields;
	}

	/** Resolves a free-text business name (+ city) to a Google place_id, for the photo backfill tool. */
	private function google_find_place_id( $key, $text_query ) {
		$url = add_query_arg( array(
			'input'     => $text_query,
			'inputtype' => 'textquery',
			'fields'    => 'place_id',
			'key'       => $key,
		), 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json' );

		$response = wp_remote_get( $url, array( 'timeout' => 6 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || ! in_array( $body['status'] ?? '', array( 'OK', 'ZERO_RESULTS' ), true ) ) {
			$detail = is_array( $body ) && ! empty( $body['error_message'] ) ? ': ' . $body['error_message'] : '';
			return new WP_Error( 'ka_lbi_google_findplace', sprintf(
				/* translators: 1: Google status string, 2: detail */
				__( 'Google place lookup failed (%1$s)%2$s', 'ka-listing-bulk-importer' ),
				$body['status'] ?? 'unknown',
				$detail
			) );
		}
		return ! empty( $body['candidates'][0]['place_id'] ) ? $body['candidates'][0]['place_id'] : '';
	}

	/** Looks up a Google Place Photo URL for an already-resolved place_id, for the photo backfill tool. */
	private function google_photo_url_for_place( $key, $place_id ) {
		$details_url = add_query_arg( array(
			'place_id' => $place_id,
			'fields'   => 'photo',
			'key'      => $key,
		), 'https://maps.googleapis.com/maps/api/place/details/json' );

		$response = wp_remote_get( $details_url, array( 'timeout' => 6 ) );
		if ( is_wp_error( $response ) ) {
			return '';
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$res  = is_array( $body ) ? ( $body['result'] ?? array() ) : array();
		if ( empty( $res['photos'][0]['photo_reference'] ) ) {
			return '';
		}
		return add_query_arg( array(
			'maxwidth'       => 1000,
			'photoreference' => $res['photos'][0]['photo_reference'],
			'key'            => $key,
		), 'https://maps.googleapis.com/maps/api/place/photo' );
	}

	/**
	 * Ask an AI provider (Claude / Gemini / ChatGPT) to suggest real
	 * businesses. These answers come from the model's training, not a live
	 * search, so results are always labeled as needing verification.
	 */
	private function discover_ai( $provider, $key, $location, $category, $max ) {
		$model  = get_option( self::OPTION_MODEL_PREFIX . $provider, self::PROVIDERS[ $provider ]['default_model'] );
		$prompt = sprintf(
			"List up to %d real, currently operating Persian/Iranian-run businesses in the \"%s\" category located in or very near %s, Germany. " .
			"Only include businesses you are reasonably confident actually exist — it is fine to return fewer than %d, or zero, rather than invent one. " .
			"Never invent a street address or phone number; leave a field blank if you are not confident of it. " .
			'Respond with ONLY a JSON array (no prose, no markdown fences), each item shaped exactly like: ' .
			'{"name": "...", "address": "...", "phone": "...", "website": "...", "description": "one short sentence"}',
			$max,
			$category->name,
			$location,
			$max
		);

		switch ( $provider ) {
			case 'claude':
				$text = $this->call_claude( $key, $model, $prompt );
				break;
			case 'gemini':
				$text = $this->call_gemini( $key, $model, $prompt );
				break;
			case 'openai':
				$text = $this->call_openai( $key, $model, $prompt );
				break;
			default:
				return new WP_Error( 'ka_lbi_bad_provider', __( 'Unknown provider', 'ka-listing-bulk-importer' ) );
		}

		if ( is_wp_error( $text ) ) {
			return $text;
		}

		$items = $this->extract_json_array( $text );
		if ( null === $items ) {
			return new WP_Error( 'ka_lbi_ai_parse', __( 'The AI provider\'s reply could not be read as a list of businesses. Try again, or switch source.', 'ka-listing-bulk-importer' ) );
		}

		$rows = array();
		foreach ( array_slice( $items, 0, $max ) as $item ) {
			if ( empty( $item['name'] ) ) {
				continue;
			}
			$fields = array(
				'post_title' => sanitize_text_field( $item['name'] ),
				'category'   => $category->slug,
				'location'   => $location,
			);
			if ( ! empty( $item['description'] ) ) {
				$fields['post_content'] = sanitize_text_field( $item['description'] );
			}
			if ( ! empty( $item['address'] ) ) {
				$fields['gAddress'] = sanitize_text_field( $item['address'] );
			}
			if ( ! empty( $item['phone'] ) ) {
				$fields['phone'] = sanitize_text_field( $item['phone'] );
			}
			if ( ! empty( $item['website'] ) && filter_var( $item['website'], FILTER_VALIDATE_URL ) ) {
				$fields['website'] = esc_url_raw( $item['website'] );
			}
			$rows[] = $fields;
		}

		return $rows;
	}

	private function call_claude( $key, $model, $prompt ) {
		$response = wp_remote_post( 'https://api.anthropic.com/v1/messages', array(
			'timeout' => 60,
			'headers' => array(
				'x-api-key'         => $key,
				'anthropic-version' => '2023-06-01',
				'content-type'      => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'      => $model,
				'max_tokens' => 4000,
				'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['content'][0]['text'] ) ) {
			return $body['content'][0]['text'];
		}
		return new WP_Error( 'ka_lbi_claude', ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Claude returned no usable reply.', 'ka-listing-bulk-importer' ) );
	}

	private function call_gemini( $key, $model, $prompt ) {
		$url      = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent?key=' . rawurlencode( $key );
		$response = wp_remote_post( $url, array(
			'timeout' => 60,
			'headers' => array( 'content-type' => 'application/json' ),
			'body'    => wp_json_encode( array(
				'contents' => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return $body['candidates'][0]['content']['parts'][0]['text'];
		}
		return new WP_Error( 'ka_lbi_gemini', ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'Gemini returned no usable reply.', 'ka-listing-bulk-importer' ) );
	}

	private function call_openai( $key, $model, $prompt ) {
		$response = wp_remote_post( 'https://api.openai.com/v1/chat/completions', array(
			'timeout' => 60,
			'headers' => array(
				'Authorization' => 'Bearer ' . $key,
				'content-type'  => 'application/json',
			),
			'body'    => wp_json_encode( array(
				'model'       => $model,
				'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
				'temperature' => 0.2,
			) ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! empty( $body['choices'][0]['message']['content'] ) ) {
			return $body['choices'][0]['message']['content'];
		}
		return new WP_Error( 'ka_lbi_openai', ! empty( $body['error']['message'] ) ? $body['error']['message'] : __( 'ChatGPT returned no usable reply.', 'ka-listing-bulk-importer' ) );
	}

	/** Pulls the first JSON array out of a model's reply, tolerating stray prose or markdown fences around it. */
	private function extract_json_array( $text ) {
		$text = trim( (string) $text );
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return $decoded;
		}
		if ( preg_match( '/\[.*\]/s', $text, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/**
	 * Make one harmless, minimal request to the Places API to confirm the key
	 * actually works, and translate Google's status codes into a plain-language
	 * explanation of what to fix.
	 *
	 * @return array{ok: bool, message: string}
	 */
	private function test_places_api_key( $key ) {
		$url = add_query_arg(
			array(
				'input'     => 'Berlin, Germany',
				'inputtype' => 'textquery',
				'fields'    => 'place_id',
				'key'       => $key,
			),
			'https://maps.googleapis.com/maps/api/place/findplacefromtext/json'
		);

		$response = wp_remote_get( $url, array( 'timeout' => 15 ) );

		if ( is_wp_error( $response ) ) {
			return array(
				'ok'      => false,
				'message' => sprintf(
					/* translators: %s: underlying network error */
					__( 'Could not reach Google: %s', 'ka-listing-bulk-importer' ),
					$response->get_error_message()
				),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$gstatus = is_array( $body ) && isset( $body['status'] ) ? $body['status'] : '';

		if ( 200 !== (int) $code ) {
			return array(
				'ok'      => false,
				/* translators: %d: HTTP status code */
				'message' => sprintf( __( 'Google returned an unexpected HTTP status: %d', 'ka-listing-bulk-importer' ), $code ),
			);
		}

		switch ( $gstatus ) {
			case 'OK':
			case 'ZERO_RESULTS':
				return array( 'ok' => true, 'message' => __( 'Google accepted the key and the Places API responded normally.', 'ka-listing-bulk-importer' ) );

			case 'REQUEST_DENIED':
				$detail = is_array( $body ) && ! empty( $body['error_message'] ) ? ' ' . $body['error_message'] : '';
				return array(
					'ok'      => false,
					'message' => __( 'Google rejected this key (REQUEST_DENIED).', 'ka-listing-bulk-importer' ) . $detail,
				);

			case 'OVER_QUERY_LIMIT':
				return array( 'ok' => false, 'message' => __( 'This key has hit its request quota (OVER_QUERY_LIMIT).', 'ka-listing-bulk-importer' ) );

			case 'INVALID_REQUEST':
				return array( 'ok' => false, 'message' => __( 'Google reported the test request as invalid — this usually still means the key itself is fine; try a real import.', 'ka-listing-bulk-importer' ) );

			default:
				$detail = is_array( $body ) && ! empty( $body['error_message'] ) ? ': ' . $body['error_message'] : '';
				return array(
					'ok'      => false,
					/* translators: %s: Google API status string */
					'message' => sprintf( __( 'Unexpected response from Google (%s)%s', 'ka-listing-bulk-importer' ), $gstatus ?: 'unknown', $detail ),
				);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Step 1: Upload                                                      */
	/* ------------------------------------------------------------------ */

	private function render_upload_step() {
		$template_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=ka_lbi_template' ),
			self::NONCE_ACTION
		);
		$photo_dir = self::ensure_photo_dir();
		?>
		<p><?php esc_html_e( 'Upload a CSV file of listings. Nothing is written to your site yet — the next screens let you map columns, review every row (including possible duplicates) and choose exactly what gets imported.', 'ka-listing-bulk-importer' ); ?></p>
		<p><a href="<?php echo esc_url( $template_url ); ?>" class="button"><?php esc_html_e( 'Download CSV template', 'ka-listing-bulk-importer' ); ?></a></p>

		<div class="notice notice-info inline" style="padding:10px 12px;">
			<p style="margin:.4em 0;"><strong><?php esc_html_e( 'About photos:', 'ka-listing-bulk-importer' ); ?></strong></p>
			<p style="margin:.4em 0;">
				<?php esc_html_e( 'Your CSV can include a "Photo" column (featured image) and a "Gallery" column (several photos, separated by |). Each value can be either:', 'ka-listing-bulk-importer' ); ?>
			</p>
			<ol style="margin:.4em 0 .4em 1.4em;">
				<li><?php esc_html_e( 'a normal https:// web address, or', 'ka-listing-bulk-importer' ); ?></li>
				<li>
					<?php
					printf(
						/* translators: %s: server folder path */
						esc_html__( 'just a filename (e.g. sabzi.jpg) — upload that file yourself into this folder on the server first: %s', 'ka-listing-bulk-importer' ),
						'<code>' . esc_html( $photo_dir ) . '</code>'
					);
					?>
				</li>
			</ol>
			<p style="margin:.4em 0;"><?php esc_html_e( 'The importer reads the file straight from that server folder — nothing gets downloaded from the internet for those. Files must be real images (jpg, png, gif, webp) and 10 MB or smaller.', 'ka-listing-bulk-importer' ); ?></p>
		</div>

		<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="ka_lbi_upload" />
			<table class="form-table">
				<tr>
					<th><label for="ka_lbi_file"><?php esc_html_e( 'CSV file', 'ka-listing-bulk-importer' ); ?></label></th>
					<td><input type="file" name="ka_lbi_file" id="ka_lbi_file" accept=".csv,text/csv" required /></td>
				</tr>
				<tr>
					<th><label for="ka_lbi_status"><?php esc_html_e( 'Status for new listings', 'ka-listing-bulk-importer' ); ?></label></th>
					<td>
						<select name="ka_lbi_status" id="ka_lbi_status">
							<option value="pending" selected><?php esc_html_e( 'Pending review (recommended)', 'ka-listing-bulk-importer' ); ?></option>
							<option value="draft"><?php esc_html_e( 'Draft', 'ka-listing-bulk-importer' ); ?></option>
							<option value="publish"><?php esc_html_e( 'Published', 'ka-listing-bulk-importer' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Upload &amp; continue', 'ka-listing-bulk-importer' ) ); ?>
		</form>
		<?php
	}

	public function handle_upload() {
		$this->verify_capability();
		check_admin_referer( self::NONCE_ACTION );

		if ( empty( $_FILES['ka_lbi_file']['tmp_name'] ) || ! is_uploaded_file( $_FILES['ka_lbi_file']['tmp_name'] ) ) {
			$this->die_back( __( 'No file was uploaded.', 'ka-listing-bulk-importer' ) );
		}

		$file = $_FILES['ka_lbi_file'];

		if ( ! empty( $file['error'] ) ) {
			$this->die_back( __( 'Upload failed. Please try again.', 'ka-listing-bulk-importer' ) );
		}

		if ( $file['size'] > self::MAX_FILE_BYTES ) {
			$this->die_back( sprintf(
				/* translators: %d: max file size in MB */
				__( 'File is too large. Maximum size is %d MB.', 'ka-listing-bulk-importer' ),
				self::MAX_FILE_BYTES / 1048576
			) );
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		$ext  = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( 'csv' !== $ext ) {
			$this->die_back( __( 'Please upload a .csv file.', 'ka-listing-bulk-importer' ) );
		}

		// Basic MIME sniffing as a second check beyond the extension.
		$finfo = function_exists( 'finfo_open' ) ? finfo_open( FILEINFO_MIME_TYPE ) : false;
		if ( $finfo ) {
			$mime = finfo_file( $finfo, $file['tmp_name'] );
			finfo_close( $finfo );
			$allowed_mimes = array( 'text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel' );
			if ( $mime && ! in_array( $mime, $allowed_mimes, true ) ) {
				$this->die_back( __( 'This does not look like a CSV file.', 'ka-listing-bulk-importer' ) );
			}
		}

		$parsed = $this->parse_csv( $file['tmp_name'] );
		if ( is_wp_error( $parsed ) ) {
			$this->die_back( $parsed->get_error_message() );
		}

		list( $headers, $rows ) = $parsed;

		if ( count( $rows ) > self::MAX_ROWS ) {
			$this->die_back( sprintf(
				/* translators: %d: max row count */
				__( 'Too many rows. Please split your file into batches of %d or fewer.', 'ka-listing-bulk-importer' ),
				self::MAX_ROWS
			) );
		}

		if ( empty( $rows ) ) {
			$this->die_back( __( 'The CSV file has no data rows.', 'ka-listing-bulk-importer' ) );
		}

		$status = isset( $_POST['ka_lbi_status'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_status'] ) ) : 'pending';
		if ( ! in_array( $status, array( 'pending', 'draft', 'publish' ), true ) ) {
			$status = 'pending';
		}

		set_transient( $this->tkey( 'headers' ), $headers, self::TRANSIENT_TTL );
		set_transient( $this->tkey( 'rows' ), $rows, self::TRANSIENT_TTL );
		set_transient( $this->tkey( 'status' ), $status, self::TRANSIENT_TTL );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-import&step=map' ) );
		exit;
	}

	private function parse_csv( $path ) {
		$handle = fopen( $path, 'r' );
		if ( ! $handle ) {
			return new WP_Error( 'ka_lbi_open', __( 'Could not open the uploaded file.', 'ka-listing-bulk-importer' ) );
		}

		// Strip UTF-8 BOM if present so headers match cleanly.
		$bom = fread( $handle, 3 );
		if ( "\xEF\xBB\xBF" !== $bom ) {
			rewind( $handle );
		}

		$headers = fgetcsv( $handle );
		if ( ! $headers ) {
			fclose( $handle );
			return new WP_Error( 'ka_lbi_headers', __( 'Could not read a header row from the CSV.', 'ka-listing-bulk-importer' ) );
		}
		$headers = array_map( function ( $h ) {
			return trim( (string) $h );
		}, $headers );

		$rows = array();
		while ( ( $data = fgetcsv( $handle ) ) !== false ) {
			if ( 1 === count( $data ) && null === $data[0] ) {
				continue; // blank line
			}
			$row = array();
			foreach ( $headers as $i => $h ) {
				$row[ $h ] = isset( $data[ $i ] ) ? trim( (string) $data[ $i ] ) : '';
			}
			$rows[] = $row;
		}
		fclose( $handle );

		return array( $headers, $rows );
	}

	private function die_back( $message ) {
		wp_die(
			'<p>' . esc_html( $message ) . '</p><p><a href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-import' ) ) . '">' . esc_html__( 'Go back', 'ka-listing-bulk-importer' ) . '</a></p>',
			esc_html__( 'Bulk Import', 'ka-listing-bulk-importer' ),
			array( 'response' => 400 )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Step 2: Column mapping                                             */
	/* ------------------------------------------------------------------ */

	private function render_mapping_step() {
		$headers = get_transient( $this->tkey( 'headers' ) );
		$rows    = get_transient( $this->tkey( 'rows' ) );

		if ( ! $headers || ! $rows ) {
			echo '<p>' . esc_html__( 'Your uploaded file has expired. Please upload it again.', 'ka-listing-bulk-importer' ) . '</p>';
			return;
		}

		$fields  = $this->importable_fields();
		$sample  = $rows[0];

		echo '<p>' . esc_html__( 'Choose which listing field each CSV column feeds. Nothing is imported yet — you will see a full preview of every row on the next screen.', 'ka-listing-bulk-importer' ) . '</p>';
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="ka_lbi_preview" />
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'CSV column', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Sample value', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Maps to', 'ka-listing-bulk-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $headers as $h ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $h ); ?></strong></td>
						<td><?php echo esc_html( mb_strimwidth( (string) ( $sample[ $h ] ?? '' ), 0, 60, '…' ) ); ?></td>
						<td>
							<select name="ka_lbi_map[<?php echo esc_attr( $h ); ?>]">
								<option value=""><?php esc_html_e( '— ignore this column —', 'ka-listing-bulk-importer' ); ?></option>
								<?php foreach ( $fields as $key => $def ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $this->guess_match( $h, $key ) ); ?>>
										<?php echo esc_html( $def['label'] ); ?><?php echo ! empty( $def['required'] ) ? ' *' : ''; ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p><em><?php esc_html_e( '* Title is required for every row.', 'ka-listing-bulk-importer' ); ?></em></p>
			<?php submit_button( __( 'Continue to preview', 'ka-listing-bulk-importer' ) ); ?>
		</form>
		<?php
	}

	private function guess_match( $column_name, $field_key ) {
		$aliases = array(
			'post_title'      => array( 'title', 'name', 'business', 'business name' ),
			'post_content'    => array( 'description', 'desc', 'about' ),
			'category'        => array( 'category', 'cat' ),
			'location'        => array( 'location', 'city', 'town' ),
			'tags'            => array( 'tags', 'tag' ),
			'gAddress'        => array( 'address', 'street' ),
			'phone'           => array( 'phone', 'tel', 'telephone', 'mobile' ),
			'whatsapp'        => array( 'whatsapp' ),
			'email'           => array( 'email', 'e-mail' ),
			'website'         => array( 'website', 'url', 'site' ),
			'facebook'        => array( 'facebook' ),
			'linkedin'        => array( 'linkedin' ),
			'twitter'         => array( 'twitter' ),
			'youtube'         => array( 'youtube channel', 'youtube' ),
			'video'           => array( 'video' ),
			'tagline_text'    => array( 'tagline', 'slogan' ),
			'latitude'        => array( 'latitude', 'lat' ),
			'longitude'       => array( 'longitude', 'lng', 'long' ),
			'featured_photo'  => array( 'photo', 'image', 'picture', 'featured image', 'bild', 'foto' ),
			'gallery_photos'  => array( 'gallery', 'photos', 'images', 'gallery photos', 'bilder' ),
		);
		if ( empty( $aliases[ $field_key ] ) ) {
			return false;
		}
		$norm = strtolower( trim( $column_name ) );
		return in_array( $norm, $aliases[ $field_key ], true );
	}

	/* ------------------------------------------------------------------ */
	/* Step 3: Preview (with duplicate detection)                         */
	/* ------------------------------------------------------------------ */

	public function handle_preview() {
		$this->verify_capability();
		check_admin_referer( self::NONCE_ACTION );

		$headers = get_transient( $this->tkey( 'headers' ) );
		$rows    = get_transient( $this->tkey( 'rows' ) );
		if ( ! $headers || ! $rows ) {
			$this->die_back( __( 'Your uploaded file has expired. Please upload it again.', 'ka-listing-bulk-importer' ) );
		}

		// NOTE: deliberately NOT run through sanitize_key() — that lowercases everything, and the one
		// mixed-case field key here ("gAddress") would silently become "gaddress" and fail the
		// whitelist check below, dropping the Address column from every CSV import without any
		// visible error. Values are still fully safe: every one is checked against the fixed
		// $valid_keys whitelist immediately after, so nothing outside that known set can pass through.
		$map = isset( $_POST['ka_lbi_map'] ) ? (array) wp_unslash( $_POST['ka_lbi_map'] ) : array();

		$valid_keys = array_keys( $this->importable_fields() );
		foreach ( $map as $col => $key ) {
			$key = is_string( $key ) ? trim( $key ) : '';
			if ( '' === $key || ! in_array( $key, $valid_keys, true ) ) {
				unset( $map[ $col ] );
				continue;
			}
			$map[ $col ] = $key;
		}

		$records = array();
		foreach ( $rows as $i => $row ) {
			$record = array();
			foreach ( $map as $col => $field_key ) {
				if ( '' === $field_key || ! isset( $row[ $col ] ) ) {
					continue;
				}
				$record[ $field_key ] = $row[ $col ];
			}
			$records[] = $this->make_record( $i, $record );
		}

		set_transient( $this->tkey( 'records' ), $records, self::TRANSIENT_TTL );
		delete_transient( $this->tkey( 'source_note' ) );
		delete_transient( $this->tkey( 'mode' ) ); // this is a CSV batch, not a Discover one — no "prefer update" default carries over

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-import&step=preview' ) );
		exit;
	}

	/**
	 * Turn one field=>value row into the record shape the preview screen and
	 * importer expect: validated, and checked against the site for possible
	 * duplicates. Shared by the CSV mapping step and the Discover tool.
	 */
	private function make_record( $row_index, array $fields ) {
		$title = isset( $fields['post_title'] ) ? $fields['post_title'] : '';
		$phone = isset( $fields['phone'] ) ? $fields['phone'] : '';

		$errors = array();
		if ( '' === trim( $title ) ) {
			$errors[] = __( 'Missing title', 'ka-listing-bulk-importer' );
		}
		if ( ! empty( $fields['email'] ) && ! is_email( $fields['email'] ) ) {
			$errors[] = __( 'Invalid email', 'ka-listing-bulk-importer' );
		}

		$duplicates = ( '' === trim( $title ) ) ? array() : $this->find_duplicates( $title, $phone );

		return array(
			'row'        => $row_index,
			'fields'     => $fields,
			'errors'     => $errors,
			'duplicates' => $duplicates,
		);
	}

	/**
	 * Look for existing listings that look like the same business:
	 * exact (case-insensitive) title match, or matching normalized phone number.
	 */
	private function find_duplicates( $title, $phone ) {
		$matches = array();

		$by_title = get_posts( array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
			'title'          => $title,
			'posts_per_page' => 5,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		) );

		$norm_phone = $this->normalize_phone( $phone );
		$by_phone   = array();
		if ( $norm_phone ) {
			$q = new WP_Query( array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
				'posts_per_page' => 5,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					array(
						'key'     => 'phone',
						'value'   => $norm_phone,
						'compare' => 'LIKE',
					),
				),
			) );
			$by_phone = $q->posts;
		}

		$ids = array_unique( array_merge( $by_title, $by_phone ) );
		foreach ( $ids as $id ) {
			$matches[] = array(
				'id'     => $id,
				'title'  => get_the_title( $id ),
				'status' => get_post_status( $id ),
				'edit'   => get_edit_post_link( $id, 'raw' ),
				'phone'  => get_post_meta( $id, 'phone', true ),
			);
		}

		return $matches;
	}

	private function render_preview_step() {
		$records = get_transient( $this->tkey( 'records' ) );
		$status  = get_transient( $this->tkey( 'status' ) );
		$mode    = get_transient( $this->tkey( 'mode' ) ); // only set for Discover-sourced batches
		if ( ! $records ) {
			echo '<p>' . esc_html__( 'Nothing to preview. Please upload a file again.', 'ka-listing-bulk-importer' ) . '</p>';
			return;
		}

		$source_note = get_transient( $this->tkey( 'source_note' ) );
		if ( $source_note ) {
			echo '<div class="notice notice-' . ( ! empty( $source_note['warn'] ) ? 'warning' : 'info' ) . ' inline" style="padding:8px 12px;"><p style="margin:.4em 0;">' . esc_html( $source_note['text'] ) . '</p></div>';
		}

		$dup_count = 0;
		$err_count = 0;
		foreach ( $records as $r ) {
			if ( ! empty( $r['duplicates'] ) ) {
				$dup_count++;
			}
			if ( ! empty( $r['errors'] ) ) {
				$err_count++;
			}
		}
		?>
		<p>
			<?php
			printf(
				/* translators: 1: total rows 2: possible duplicates 3: rows with errors */
				esc_html__( '%1$d rows read. %2$d look like they might already exist. %3$d have a problem and will be skipped unless fixed. Fields below are editable — fix a typo right here before importing. Review each row and decide what should happen, then import.', 'ka-listing-bulk-importer' ),
				count( $records ),
				$dup_count,
				$err_count
			);
			?>
		</p>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ka_lbi_export_preview' ), self::NONCE_ACTION ) ); ?>">
				<span class="dashicons dashicons-media-spreadsheet" style="vertical-align:text-bottom;"></span>
				<?php esc_html_e( 'Export this preview as CSV', 'ka-listing-bulk-importer' ); ?>
			</a>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( self::NONCE_ACTION ); ?>
			<input type="hidden" name="action" value="ka_lbi_import" />
			<input type="hidden" name="ka_lbi_status" value="<?php echo esc_attr( $status ); ?>" />

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Row', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Title', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Category / Location', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Phone / Address', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Photo', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Possible existing match', 'ka-listing-bulk-importer' ); ?></th>
						<th><?php esc_html_e( 'Action', 'ka-listing-bulk-importer' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $records as $r ) :
					$f          = $r['fields'];
					$row        = (int) $r['row'];
					$has_error  = ! empty( $r['errors'] );
					$has_dup    = ! empty( $r['duplicates'] );
					$row_style  = $has_error ? 'background:#fbe9e7;' : ( $has_dup ? 'background:#fff8e1;' : '' );
					$edit_name  = 'ka_lbi_edit[' . $row . ']';
					?>
					<tr style="<?php echo esc_attr( $row_style ); ?>">
						<td><?php echo $row + 1; ?></td>
						<td>
							<input type="text" class="regular-text" name="<?php echo esc_attr( $edit_name ); ?>[post_title]" value="<?php echo esc_attr( $f['post_title'] ?? '' ); ?>" />
							<?php if ( $has_error ) : ?>
								<br /><span style="color:#c00;"><?php echo esc_html( implode( ', ', $r['errors'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<input type="text" class="small-text" style="width:100%;margin-bottom:3px;" name="<?php echo esc_attr( $edit_name ); ?>[category]" value="<?php echo esc_attr( $f['category'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'category', 'ka-listing-bulk-importer' ); ?>" />
							<input type="text" class="small-text" style="width:100%;" name="<?php echo esc_attr( $edit_name ); ?>[location]" value="<?php echo esc_attr( $f['location'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'location', 'ka-listing-bulk-importer' ); ?>" />
						</td>
						<td>
							<input type="text" class="small-text" style="width:100%;margin-bottom:3px;" name="<?php echo esc_attr( $edit_name ); ?>[phone]" value="<?php echo esc_attr( $f['phone'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'phone', 'ka-listing-bulk-importer' ); ?>" />
							<input type="text" class="small-text" style="width:100%;" name="<?php echo esc_attr( $edit_name ); ?>[gAddress]" value="<?php echo esc_attr( $f['gAddress'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'address', 'ka-listing-bulk-importer' ); ?>" />
						</td>
						<td>
							<?php
							$photo_val = trim( $f['featured_photo'] ?? '' );
							if ( '' !== $photo_val ) {
								if ( preg_match( '#^https?://#i', $photo_val ) ) {
									echo '<img src="' . esc_url( $photo_val ) . '" style="max-width:60px;max-height:60px;" alt="" />';
								} else {
									echo '<code>' . esc_html( $photo_val ) . '</code>';
								}
							} else {
								echo '&mdash;';
							}
							?>
						</td>
						<td>
							<?php if ( $has_dup ) : ?>
								<?php foreach ( $r['duplicates'] as $d ) : ?>
									<div>
										<a href="<?php echo esc_url( $d['edit'] ); ?>" target="_blank" rel="noopener noreferrer">
											<?php echo esc_html( $d['title'] ); ?>
										</a>
										(<?php echo esc_html( $d['status'] ); ?>)
									</div>
								<?php endforeach; ?>
							<?php else : ?>
								&mdash;
							<?php endif; ?>
						</td>
						<td>
							<?php
							$prefer_update = $has_dup && ( 'update_existing' === $mode );
							?>
							<select name="ka_lbi_action[<?php echo $row; ?>]">
								<option value="create" <?php selected( ! $has_dup && ! $has_error ); ?>><?php esc_html_e( 'Import as new listing', 'ka-listing-bulk-importer' ); ?></option>
								<option value="skip" <?php selected( $has_error || ( $has_dup && ! $prefer_update ) ); ?>><?php esc_html_e( 'Skip this row', 'ka-listing-bulk-importer' ); ?></option>
								<?php if ( $has_dup ) : ?>
									<option value="update:<?php echo (int) $r['duplicates'][0]['id']; ?>" <?php selected( $prefer_update && ! $has_error ); ?>>
										<?php
										printf(
											/* translators: %s: existing listing title */
											esc_html__( 'Update existing: %s', 'ka-listing-bulk-importer' ),
											esc_html( $r['duplicates'][0]['title'] )
										);
										?>
									</option>
								<?php endif; ?>
							</select>
							<?php if ( $has_error ) : ?>
								<p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'Currently set to skip because of the problem above — fix it in the Title field and change this to "Import as new listing" if you want it included.', 'ka-listing-bulk-importer' ); ?></p>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<?php submit_button( __( 'Import selected rows', 'ka-listing-bulk-importer' ), 'primary', 'submit', false ); ?>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-import' ) ); ?>" class="button"><?php esc_html_e( 'Cancel / start over', 'ka-listing-bulk-importer' ); ?></a>
			</p>
		</form>
		<?php
	}

	/* ------------------------------------------------------------------ */
	/* Step 4: Import                                                      */
	/* ------------------------------------------------------------------ */

	public function handle_import() {
		$this->verify_capability();
		check_admin_referer( self::NONCE_ACTION );

		$records = get_transient( $this->tkey( 'records' ) );
		if ( ! $records ) {
			$this->die_back( __( 'Nothing to import. Please upload your file again.', 'ka-listing-bulk-importer' ) );
		}

		$status  = isset( $_POST['ka_lbi_status'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_status'] ) ) : 'pending';
		if ( ! in_array( $status, array( 'pending', 'draft', 'publish' ), true ) ) {
			$status = 'pending';
		}

		$actions = isset( $_POST['ka_lbi_action'] ) ? (array) wp_unslash( $_POST['ka_lbi_action'] ) : array();
		$edits   = isset( $_POST['ka_lbi_edit'] ) ? (array) wp_unslash( $_POST['ka_lbi_edit'] ) : array();

		$created = array();
		$updated = array();
		$skipped = array();
		$failed  = array();
		$photo_warnings = array();

		// Fields the preview screen lets the admin hand-correct before import (typos, a wrong phone/address, etc).
		$editable_keys = array( 'post_title', 'category', 'location', 'phone', 'gAddress' );

		foreach ( $records as $r ) {
			$row_index = $r['row'];
			$action    = isset( $actions[ $row_index ] ) ? sanitize_text_field( $actions[ $row_index ] ) : 'skip';
			$fields    = $r['fields'];

			if ( isset( $edits[ $row_index ] ) && is_array( $edits[ $row_index ] ) ) {
				foreach ( $editable_keys as $key ) {
					if ( isset( $edits[ $row_index ][ $key ] ) ) {
						$fields[ $key ] = sanitize_text_field( $edits[ $row_index ][ $key ] );
					}
				}
			}

			if ( 'skip' === $action ) {
				$skipped[] = array( 'row' => $row_index + 1, 'title' => $fields['post_title'] ?? '' );
				continue;
			}

			$target_id = 0;
			if ( 0 === strpos( $action, 'update:' ) ) {
				$target_id = absint( substr( $action, 7 ) );
				if ( ! $target_id || self::POST_TYPE !== get_post_type( $target_id ) ) {
					$failed[] = array( 'row' => $row_index + 1, 'title' => $fields['post_title'] ?? '', 'reason' => __( 'Target listing no longer exists', 'ka-listing-bulk-importer' ) );
					continue;
				}
			}

			$result = $this->write_row( $fields, $status, $target_id );
			if ( is_wp_error( $result['post_id'] ) ) {
				$failed[] = array( 'row' => $row_index + 1, 'title' => $fields['post_title'] ?? '', 'reason' => $result['post_id']->get_error_message() );
				continue;
			}

			$post_id = $result['post_id'];
			if ( ! empty( $result['photo_errors'] ) ) {
				$photo_warnings[] = array(
					'row'    => $row_index + 1,
					'title'  => get_the_title( $post_id ),
					'errors' => $result['photo_errors'],
				);
			}

			if ( $target_id ) {
				$updated[] = array( 'row' => $row_index + 1, 'id' => $post_id, 'title' => get_the_title( $post_id ) );
			} else {
				$created[] = array( 'row' => $row_index + 1, 'id' => $post_id, 'title' => get_the_title( $post_id ) );
			}
		}

		// Log this batch for the "recent imports / undo" panel.
		if ( ! empty( $created ) ) {
			$this->log_batch( $created );
		}

		delete_transient( $this->tkey( 'headers' ) );
		delete_transient( $this->tkey( 'rows' ) );
		delete_transient( $this->tkey( 'records' ) );
		delete_transient( $this->tkey( 'status' ) );
		delete_transient( $this->tkey( 'mode' ) );
		delete_transient( $this->tkey( 'source_note' ) );

		set_transient( $this->tkey( 'results' ), compact( 'created', 'updated', 'skipped', 'failed', 'photo_warnings' ), self::TRANSIENT_TTL );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-import&step=done' ) );
		exit;
	}

	/**
	 * Create or update a single listing from a mapped, associative record.
	 * Every value is sanitized according to its declared field type before it
	 * ever reaches wp_insert_post / update_post_meta.
	 *
	 * @return array{post_id:int|WP_Error, photo_errors:string[]}
	 */
	private function write_row( array $fields, $status, $existing_id = 0 ) {
		$title = isset( $fields['post_title'] ) ? sanitize_text_field( $fields['post_title'] ) : '';
		if ( '' === $title ) {
			return array( 'post_id' => new WP_Error( 'ka_lbi_no_title', __( 'Missing title', 'ka-listing-bulk-importer' ) ), 'photo_errors' => array() );
		}

		$postarr = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => $title,
			'post_status' => $status,
		);
		if ( isset( $fields['post_content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $fields['post_content'] );
		}

		if ( $existing_id ) {
			$postarr['ID'] = $existing_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$post_id = wp_insert_post( $postarr, true );
		}

		if ( is_wp_error( $post_id ) ) {
			return array( 'post_id' => $post_id, 'photo_errors' => array() );
		}

		if ( ! empty( $fields['category'] ) ) {
			$this->set_terms_by_name( $post_id, self::TAX_CATEGORY, $fields['category'] );
		}
		if ( ! empty( $fields['location'] ) ) {
			$this->set_terms_by_name( $post_id, self::TAX_LOCATION, $fields['location'] );
		}
		if ( ! empty( $fields['tags'] ) ) {
			$this->set_terms_by_name( $post_id, self::TAX_TAGS, $fields['tags'] );
		}

		foreach ( self::META_FIELDS as $key => $def ) {
			if ( ! isset( $fields[ $key ] ) || '' === $fields[ $key ] ) {
				continue;
			}
			$value = $this->sanitize_by_type( $fields[ $key ], $def['type'] );
			if ( null !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}

		$photo_errors = array();

		if ( ! empty( $fields['featured_photo'] ) ) {
			$attachment_id = $this->import_one_image( $fields['featured_photo'], $post_id );
			if ( is_wp_error( $attachment_id ) ) {
				$photo_errors[] = $fields['featured_photo'] . ': ' . $attachment_id->get_error_message();
			} else {
				set_post_thumbnail( $post_id, $attachment_id );
			}
		}

		if ( ! empty( $fields['gallery_photos'] ) ) {
			$urls = array_filter( array_map( 'trim', preg_split( '/[|,]/', $fields['gallery_photos'] ) ) );
			$ids  = array();
			foreach ( $urls as $u ) {
				$aid = $this->import_one_image( $u, $post_id );
				if ( is_wp_error( $aid ) ) {
					$photo_errors[] = $u . ': ' . $aid->get_error_message();
				} else {
					$ids[] = $aid;
				}
			}
			if ( $ids ) {
				update_post_meta( $post_id, 'gallery_image_ids', implode( ',', $ids ) );
			}
		}

		return array( 'post_id' => $post_id, 'photo_errors' => $photo_errors );
	}

	private function sanitize_by_type( $value, $type ) {
		switch ( $type ) {
			case 'email':
				$value = sanitize_email( $value );
				return is_email( $value ) ? $value : null;
			case 'url':
				$value = esc_url_raw( trim( $value ) );
				return $value ? $value : null;
			case 'phone':
				return sanitize_text_field( $value );
			case 'float':
				return is_numeric( $value ) ? (float) $value : null;
			default:
				return sanitize_text_field( $value );
		}
	}

	/** Match by existing term name or slug only — never auto-creates taxonomy terms. */
	private function set_terms_by_name( $post_id, $taxonomy, $names_csv ) {
		$names = array_filter( array_map( 'trim', explode( ',', $names_csv ) ) );
		$ids   = array();
		foreach ( $names as $name ) {
			$term = get_term_by( 'name', $name, $taxonomy );
			if ( ! $term ) {
				$term = get_term_by( 'slug', sanitize_title( $name ), $taxonomy );
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			}
		}
		if ( $ids ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy, false );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Photos: sideload from a URL, or read a file placed on this server  */
	/* ------------------------------------------------------------------ */

	/**
	 * Turn a CSV photo value into a Media Library attachment ID.
	 *
	 * - An https?:// value is downloaded through WordPress's own HTTP API
	 *   (with a size cap) and sideloaded into the Media Library.
	 * - Anything else is treated as a filename that must already exist inside
	 *   this plugin's photo intake folder on this same server — it is copied
	 *   into the Media Library directly, no network request involved.
	 *
	 * Every file is verified to actually be an image (via getimagesize)
	 * before it is attached, regardless of its extension or claimed type.
	 *
	 * @return int|WP_Error
	 */
	private function import_one_image( $value, $post_id ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return new WP_Error( 'ka_lbi_empty_photo', __( 'Empty photo value', 'ka-listing-bulk-importer' ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( preg_match( '#^https?://#i', $value ) ) {
			return $this->sideload_remote_image( $value, $post_id );
		}

		return $this->sideload_local_image( $value, $post_id );
	}

	private function sideload_remote_image( $url, $post_id ) {
		$url = esc_url_raw( $url );
		if ( ! $url ) {
			return new WP_Error( 'ka_lbi_bad_url', __( 'Not a valid URL', 'ka-listing-bulk-importer' ) );
		}

		$tmp = download_url( $url, 10 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}

		if ( filesize( $tmp ) > self::MAX_IMAGE_BYTES ) {
			@unlink( $tmp );
			return new WP_Error( 'ka_lbi_too_big', __( 'Image is larger than 10 MB', 'ka-listing-bulk-importer' ) );
		}

		if ( ! @getimagesize( $tmp ) ) {
			@unlink( $tmp );
			return new WP_Error( 'ka_lbi_not_image', __( 'The file at this URL is not a recognizable image', 'ka-listing-bulk-importer' ) );
		}

		$name       = sanitize_file_name( wp_basename( parse_url( $url, PHP_URL_PATH ) ?: '' ) );
		if ( '' === $name || false === strpos( $name, '.' ) ) {
			$name = 'photo-' . uniqid() . '.jpg';
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$attachment_id = media_handle_sideload( $file_array, $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $tmp );
			return $attachment_id;
		}

		return $attachment_id;
	}

	private function sideload_local_image( $filename, $post_id ) {
		$base = realpath( self::ensure_photo_dir() );
		if ( ! $base ) {
			return new WP_Error( 'ka_lbi_no_dir', __( 'Photo intake folder is missing', 'ka-listing-bulk-importer' ) );
		}

		// Prevent path traversal: resolve then require the real path to stay inside $base.
		$candidate = $base . '/' . ltrim( str_replace( '\\', '/', $filename ), '/' );
		$resolved  = realpath( $candidate );
		if ( ! $resolved || 0 !== strpos( $resolved, $base . DIRECTORY_SEPARATOR ) && $resolved !== $base ) {
			return new WP_Error( 'ka_lbi_bad_path', __( 'File not found in the photo folder', 'ka-listing-bulk-importer' ) );
		}
		if ( ! is_file( $resolved ) ) {
			return new WP_Error( 'ka_lbi_missing', __( 'File not found in the photo folder', 'ka-listing-bulk-importer' ) );
		}
		if ( filesize( $resolved ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'ka_lbi_too_big', __( 'Image is larger than 10 MB', 'ka-listing-bulk-importer' ) );
		}
		if ( ! @getimagesize( $resolved ) ) {
			return new WP_Error( 'ka_lbi_not_image', __( 'This is not a recognizable image file', 'ka-listing-bulk-importer' ) );
		}

		$filetype = wp_check_filetype( basename( $resolved ) );
		if ( empty( $filetype['type'] ) || 0 !== strpos( $filetype['type'], 'image/' ) ) {
			return new WP_Error( 'ka_lbi_not_image', __( 'Unsupported image type', 'ka-listing-bulk-importer' ) );
		}

		$contents = file_get_contents( $resolved );
		if ( false === $contents ) {
			return new WP_Error( 'ka_lbi_read_failed', __( 'Could not read the photo file', 'ka-listing-bulk-importer' ) );
		}

		$upload = wp_upload_bits( basename( $resolved ), null, $contents );
		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error( 'ka_lbi_upload_failed', $upload['error'] );
		}

		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( basename( $resolved ) ),
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_parent'    => $post_id,
		);

		$attachment_id = wp_insert_attachment( $attachment, $upload['file'], $post_id );
		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $attachment_id;
	}

	/* ------------------------------------------------------------------ */
	/* Results / audit log / undo                                         */
	/* ------------------------------------------------------------------ */

	private function log_batch( array $created ) {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$log[] = array(
			'batch_id' => wp_generate_uuid4(),
			'time'     => current_time( 'mysql' ),
			'user'     => get_current_user_id(),
			'post_ids' => wp_list_pluck( $created, 'id' ),
		);

		// Keep only the 10 most recent batches.
		if ( count( $log ) > 10 ) {
			$log = array_slice( $log, -10 );
		}

		update_option( self::OPTION_LOG, $log, false );
	}

	private function render_results() {
		$results = get_transient( $this->tkey( 'results' ) );
		if ( ! $results ) {
			echo '<p>' . esc_html__( 'No results to show.', 'ka-listing-bulk-importer' ) . '</p>';
			return;
		}
		delete_transient( $this->tkey( 'results' ) );

		printf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1: created 2: updated 3: skipped 4: failed */
				esc_html__( 'Created: %1$d — Updated: %2$d — Skipped: %3$d — Failed: %4$d', 'ka-listing-bulk-importer' ),
				count( $results['created'] ),
				count( $results['updated'] ),
				count( $results['skipped'] ),
				count( $results['failed'] )
			)
		);

		foreach ( array(
			'created' => __( 'Created', 'ka-listing-bulk-importer' ),
			'updated' => __( 'Updated', 'ka-listing-bulk-importer' ),
			'skipped' => __( 'Skipped', 'ka-listing-bulk-importer' ),
			'failed'  => __( 'Failed', 'ka-listing-bulk-importer' ),
		) as $key => $label ) {
			if ( empty( $results[ $key ] ) ) {
				continue;
			}
			echo '<h2>' . esc_html( $label ) . '</h2><ul>';
			foreach ( $results[ $key ] as $item ) {
				if ( isset( $item['id'] ) ) {
					echo '<li><a href="' . esc_url( get_edit_post_link( $item['id'], 'raw' ) ) . '">' . esc_html( $item['title'] ) . '</a></li>';
				} elseif ( isset( $item['reason'] ) ) {
					echo '<li>' . esc_html( sprintf( '%s (%s)', $item['title'], $item['reason'] ) ) . '</li>';
				} else {
					echo '<li>' . esc_html( sprintf( 'Row %d: %s', $item['row'], $item['title'] ) ) . '</li>';
				}
			}
			echo '</ul>';
		}

		if ( ! empty( $results['photo_warnings'] ) ) {
			echo '<h2>' . esc_html__( 'Photo problems (listing was still created/updated)', 'ka-listing-bulk-importer' ) . '</h2><ul>';
			foreach ( $results['photo_warnings'] as $w ) {
				echo '<li><strong>' . esc_html( $w['title'] ) . '</strong>: ' . esc_html( implode( '; ', $w['errors'] ) ) . '</li>';
			}
			echo '</ul>';
		}

		echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-import' ) ) . '">' . esc_html__( 'Import another file', 'ka-listing-bulk-importer' ) . '</a></p>';
	}

	private function render_recent_imports() {
		$log = get_option( self::OPTION_LOG, array() );
		if ( empty( $log ) ) {
			return;
		}

		echo '<h2>' . esc_html__( 'Recent imports', 'ka-listing-bulk-importer' ) . '</h2>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'ka-listing-bulk-importer' ) . '</th><th>' . esc_html__( 'By', 'ka-listing-bulk-importer' ) . '</th><th>' . esc_html__( 'Listings created', 'ka-listing-bulk-importer' ) . '</th><th></th></tr></thead><tbody>';

		foreach ( array_reverse( $log ) as $batch ) {
			$user  = get_userdata( $batch['user'] );
			$count = count( $batch['post_ids'] );
			echo '<tr>';
			echo '<td>' . esc_html( $batch['time'] ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->display_name : '—' ) . '</td>';
			echo '<td>' . (int) $count . '</td>';
			echo '<td>';
			if ( $count > 0 ) {
				$undo_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=ka_lbi_undo&batch_id=' . rawurlencode( $batch['batch_id'] ) ),
					self::NONCE_ACTION
				);
				echo '<a class="button" href="' . esc_url( $undo_url ) . '" onclick="return confirm(\'' . esc_js( __( 'Move all listings from this import to the trash?', 'ka-listing-bulk-importer' ) ) . '\');">' . esc_html__( 'Undo (trash these listings)', 'ka-listing-bulk-importer' ) . '</a>';
			}
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	public function handle_undo() {
		$this->verify_capability();
		check_admin_referer( self::NONCE_ACTION );

		$batch_id = isset( $_GET['batch_id'] ) ? sanitize_text_field( wp_unslash( $_GET['batch_id'] ) ) : '';
		$log      = get_option( self::OPTION_LOG, array() );
		$found    = false;

		foreach ( $log as $i => $batch ) {
			if ( $batch['batch_id'] === $batch_id ) {
				foreach ( $batch['post_ids'] as $post_id ) {
					if ( self::POST_TYPE === get_post_type( $post_id ) ) {
						wp_trash_post( $post_id );
					}
				}
				unset( $log[ $i ] );
				$found = true;
				break;
			}
		}

		if ( $found ) {
			update_option( self::OPTION_LOG, array_values( $log ), false );
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-import' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* CSV template                                                        */
	/* ------------------------------------------------------------------ */

	public function handle_template_download() {
		$this->verify_capability();
		check_admin_referer( self::NONCE_ACTION );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ka-listing-import-template.csv"' );

		echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders Persian/German text correctly

		$out     = fopen( 'php://output', 'w' );
		$headers = array( 'Title', 'Description', 'Category', 'Location', 'Tags', 'Address', 'Phone', 'Whatsapp', 'Email', 'Website', 'Photo', 'Gallery' );
		fputcsv( $out, $headers );
		fputcsv( $out, array(
			'Restaurant Sabzi',
			'Persisches Restaurant in Berlin-Mitte.',
			'restaurant-food',
			'berlin',
			'',
			'Luisenstraße 15, 10117 Berlin',
			'030 12345678',
			'',
			'',
			'',
			'sabzi-front.jpg',
			'sabzi-inside.jpg|https://example.com/sabzi-dish.jpg',
		) );
		fclose( $out );
		exit;
	}

	/** Lets the admin download the current preview batch (before import) as a CSV, e.g. to review in Excel. */
	public function handle_export_preview() {
		$this->verify_capability();
		check_admin_referer( self::NONCE_ACTION );

		$records = get_transient( $this->tkey( 'records' ) );
		if ( ! $records ) {
			$this->die_back( __( 'Nothing to export — the preview has expired. Please run the search or upload again.', 'ka-listing-bulk-importer' ) );
		}

		$field_keys = array_keys( $this->importable_fields() );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="ka-listing-preview-' . gmdate( 'Y-m-d-His' ) . '.csv"' );

		echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel renders Persian/German text correctly

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array_merge( array( 'Row', 'Possible duplicate' ), $field_keys ) );
		foreach ( $records as $r ) {
			$row = array(
				(int) $r['row'] + 1,
				! empty( $r['duplicates'] ) ? $r['duplicates'][0]['title'] : '',
			);
			foreach ( $field_keys as $key ) {
				$row[] = $r['fields'][ $key ] ?? '';
			}
			fputcsv( $out, $row );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Fills in featured photos for listings that already exist on the site
	 * but have none — looks each one up on Google Maps by "title, city" and
	 * attaches whatever real photo Google has for it. Only ever touches the
	 * featured image; nothing else about the listing is changed.
	 */
	/** Builds the tax_query for a "find listings missing a photo" scope, shared by the count check, the synchronous run and the background job. */
	private function photo_backfill_tax_query( $loc_slug, $cat_slug ) {
		$tax_query = array();
		if ( '' !== $loc_slug ) {
			$tax_query[] = array( 'taxonomy' => self::TAX_LOCATION, 'field' => 'slug', 'terms' => array( $loc_slug ) );
		}
		if ( '' !== $cat_slug ) {
			$tax_query[] = array( 'taxonomy' => self::TAX_CATEGORY, 'field' => 'slug', 'terms' => array( $cat_slug ) );
		}
		if ( count( $tax_query ) > 1 ) {
			$tax_query['relation'] = 'AND';
		}
		return $tax_query;
	}

	/**
	 * Decided automatically, the same way as Discover: a small scope (up to PHOTO_SYNC_CAP
	 * listings missing a photo) is checked right away inside this one page load, with the
	 * result shown immediately. Anything bigger is hand off to a background job instead —
	 * no separate button to choose between, and this page then shows the same kind of live,
	 * auto-refreshing progress card the Discover background job uses.
	 */
	public function handle_backfill_photos() {
		$this->verify_capability();
		check_admin_referer( self::DISCOVER_NONCE );

		if ( $this->get_photo_job() ) {
			$this->die_back( __( 'A background photo search is already running. Wait for it to finish (or cancel it) before starting another.', 'ka-listing-bulk-importer' ) );
		}

		$loc_slug = isset( $_POST['ka_lbi_bf_location'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_bf_location'] ) ) : '';
		$cat_slug = isset( $_POST['ka_lbi_bf_category'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_bf_category'] ) ) : '';

		$key = get_option( self::OPTION_KEY_PREFIX . 'google', '' );
		if ( '' === $key ) {
			$this->die_back( __( 'No Google Maps (Places API) key saved yet. Add one on the Settings tab first.', 'ka-listing-bulk-importer' ) );
		}

		$tax_query = $this->photo_backfill_tax_query( $loc_slug, $cat_slug );
		$base_args = array(
			'post_type'      => self::POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
			'no_found_rows'  => false,
			'meta_query'     => array(
				'relation' => 'OR',
				array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_thumbnail_id', 'value' => '', 'compare' => '=' ),
			),
		);
		if ( ! empty( $tax_query ) ) {
			$base_args['tax_query'] = $tax_query;
		}

		// A cheap count-only query first, so the size of the job decides the path — not a manual choice.
		$count_query = new WP_Query( array_merge( $base_args, array( 'posts_per_page' => 1, 'fields' => 'ids' ) ) );
		$total       = (int) $count_query->found_posts;

		if ( 0 === $total ) {
			$this->die_back( __( 'Nothing to do — every listing in this scope already has a photo (or there are no matching listings).', 'ka-listing-bulk-importer' ) );
		}

		$loc_term  = ( '' !== $loc_slug ) ? get_term_by( 'slug', $loc_slug, self::TAX_LOCATION ) : null;
		$cat_term  = ( '' !== $cat_slug ) ? get_term_by( 'slug', $cat_slug, self::TAX_CATEGORY ) : null;
		$loc_label = $loc_term ? $loc_term->name : ( ( '' !== $loc_slug ) ? $loc_slug : __( 'all cities', 'ka-listing-bulk-importer' ) );
		$cat_label = $cat_term ? $cat_term->name : ( ( '' !== $cat_slug ) ? $cat_slug : __( 'all categories', 'ka-listing-bulk-importer' ) );

		if ( $total <= self::PHOTO_SYNC_CAP ) {
			$ids = get_posts( array_merge( $base_args, array( 'posts_per_page' => $total, 'fields' => 'ids' ) ) );
			$result = $this->run_photo_backfill_on_ids( $key, $ids );
			$this->log_photo_run( $loc_label, $cat_label, 'sync', $result );
			set_transient( $this->tkey( 'backfill_result' ), $result, self::TRANSIENT_TTL );
			wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-photos' ) );
			exit;
		}

		// Bigger than one page load should safely handle — queue it and run it a few at a time in the background, same pattern as the Discover job.
		$ids = get_posts( array_merge( $base_args, array( 'posts_per_page' => -1, 'fields' => 'ids' ) ) );
		$job = array(
			'id'          => wp_generate_uuid4(),
			'queue'       => $ids,
			'total'       => count( $ids ),
			'done'        => 0,
			'found'       => 0,
			'not_found'   => 0,
			'location'    => $loc_label,
			'category'    => $cat_label,
			'started_by'  => get_current_user_id(),
			'started_at'  => current_time( 'mysql' ),
			'updated_at'  => 0,
		);
		update_option( self::OPTION_PHOTO_JOB, $job, false );
		delete_option( 'ka_lbi_last_job_error_photo' );

		$this->run_photo_job_tick( true ); // process the first batch immediately, same reasoning as the Discover job
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-photos&ka_lbi_job_started=1' ) );
		exit;
	}

	/** Runs the actual Google Maps lookup + photo sideload for a fixed list of post IDs. Shared by the synchronous path and each background-job tick. */
	private function run_photo_backfill_on_ids( $key, array $post_ids ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 180 );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$checked   = 0;
		$found     = 0;
		$not_found = 0;

		foreach ( $post_ids as $post_id ) {
			if ( has_post_thumbnail( $post_id ) ) {
				continue; // belt-and-suspenders re-check, in case it got a photo some other way since the queue was built
			}
			$checked++;

			$location_terms = get_the_terms( $post_id, self::TAX_LOCATION );
			$city           = ( $location_terms && ! is_wp_error( $location_terms ) ) ? $location_terms[0]->name : '';
			$query_text     = trim( get_the_title( $post_id ) . ' ' . $city );

			$place_id = $this->google_find_place_id( $key, $query_text );
			if ( is_wp_error( $place_id ) || '' === $place_id ) {
				$not_found++;
				continue;
			}

			$photo_url = $this->google_photo_url_for_place( $key, $place_id );
			if ( '' === $photo_url ) {
				$not_found++;
				continue;
			}

			$attachment_id = $this->import_one_image( $photo_url, $post_id );
			if ( is_wp_error( $attachment_id ) ) {
				$not_found++;
				continue;
			}
			set_post_thumbnail( $post_id, $attachment_id );
			$found++;
		}

		return compact( 'checked', 'found', 'not_found' );
	}

	public function handle_cancel_photo_job() {
		$this->verify_capability();
		check_admin_referer( self::DISCOVER_NONCE );
		delete_option( self::OPTION_PHOTO_JOB );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-photos' ) );
		exit;
	}

	private function get_photo_job() {
		$job = get_option( self::OPTION_PHOTO_JOB, null );
		return is_array( $job ) ? $job : null;
	}

	/** The Photos-tab equivalent of run_job_tick() — same live-poll/cron-fallback/locking pattern, one small batch of post IDs at a time. */
	private function run_photo_job_tick( $force = false ) {
		$job = $this->get_photo_job();
		if ( ! $job || empty( $job['queue'] ) ) {
			if ( $job ) {
				$this->finish_photo_job( $job );
			}
			return null;
		}

		if ( ! $force ) {
			$last = isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0;
			if ( $last && ( time() - $last ) < self::JOB_MIN_GAP ) {
				return $job;
			}
		}
		if ( get_transient( self::PHOTO_JOB_LOCK_KEY ) ) {
			return $job;
		}
		set_transient( self::PHOTO_JOB_LOCK_KEY, 1, 30 );

		$key = get_option( self::OPTION_KEY_PREFIX . 'google', '' );
		if ( '' === $key ) {
			$job['error'] = __( 'The Google Maps (Places API) key was removed while this job was running.', 'ka-listing-bulk-importer' );
			$this->finish_photo_job( $job );
			delete_transient( self::PHOTO_JOB_LOCK_KEY );
			return null;
		}

		try {
			$batch  = array_splice( $job['queue'], 0, self::PHOTO_JOB_BATCH_SIZE );
			$result = $this->run_photo_backfill_on_ids( $key, $batch );
			$job['done']      += count( $batch );
			$job['found']     += $result['found'];
			$job['not_found'] += $result['not_found'];
		} catch ( \Throwable $e ) {
			$job['error'] = sprintf(
				/* translators: %s: the underlying PHP error message */
				__( 'The background photo search stopped because of an unexpected error: %s', 'ka-listing-bulk-importer' ),
				$e->getMessage()
			);
			$this->log_job_error( 'photo', $job['error'] . ' @ ' . $e->getFile() . ':' . $e->getLine() );
			$this->finish_photo_job( $job );
			delete_transient( self::PHOTO_JOB_LOCK_KEY );
			return null;
		}
		$job['updated_at'] = time();

		if ( empty( $job['queue'] ) ) {
			$this->finish_photo_job( $job );
			delete_transient( self::PHOTO_JOB_LOCK_KEY );
			return null;
		}

		update_option( self::OPTION_PHOTO_JOB, $job, false );
		wp_schedule_single_event( time() + self::JOB_TICK_DELAY, self::PHOTO_JOB_CRON_HOOK );
		delete_transient( self::PHOTO_JOB_LOCK_KEY );
		return $job;
	}

	public function process_photo_job_batch() {
		$this->run_photo_job_tick( true );
	}

	private function finish_photo_job( array $job ) {
		delete_option( self::OPTION_PHOTO_JOB );

		$this->log_photo_run( $job['location'], $job['category'], 'background', array(
			'checked'   => $job['done'],
			'found'     => $job['found'],
			'not_found' => $job['not_found'],
		), isset( $job['error'] ) ? $job['error'] : '' );

		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}
		$subject = sprintf(
			/* translators: 1: site name, 2: number of photos added */
			__( '[%1$s] Background photo backfill finished: %2$d photo(s) added', 'ka-listing-bulk-importer' ),
			get_bloginfo( 'name' ),
			(int) $job['found']
		);
		$body  = ! empty( $job['error'] )
			? sprintf( __( 'The background photo search stopped early: %s', 'ka-listing-bulk-importer' ), $job['error'] ) . "\n\n"
			: '';
		$body .= sprintf(
			/* translators: 1: listings checked, 2: photos added, 3: no match found */
			__( 'Checked %1$d listing(s): %2$d got a new photo, %3$d had no clear match on Google Maps.', 'ka-listing-bulk-importer' ),
			(int) $job['done'],
			(int) $job['found'],
			(int) $job['not_found']
		) . "\n";
		wp_mail( $to, $subject, $body );
	}

	/** Appends one entry to the persistent "Backfill missing photos" run log (newest first), capped at PHOTO_LOG_MAX entries. */
	private function log_photo_run( $location_label, $category_label, $mode, array $result, $error = '' ) {
		$log = get_option( self::OPTION_PHOTO_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, array(
			'time'      => current_time( 'mysql' ),
			'location'  => $location_label,
			'category'  => $category_label,
			'mode'      => $mode, // 'sync' | 'background'
			'checked'   => (int) ( $result['checked'] ?? 0 ),
			'found'     => (int) ( $result['found'] ?? 0 ),
			'not_found' => (int) ( $result['not_found'] ?? 0 ),
			'error'     => $error,
		) );
		if ( count( $log ) > self::PHOTO_LOG_MAX ) {
			$log = array_slice( $log, 0, self::PHOTO_LOG_MAX );
		}
		update_option( self::OPTION_PHOTO_LOG, $log, false );
	}

	private function get_photo_log() {
		$log = get_option( self::OPTION_PHOTO_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/* ------------------------------------------------------------------ */
	/* Scheduled (recurring) Discover searches                            */
	/* ------------------------------------------------------------------ */

	private function get_schedules() {
		$schedules = get_option( self::OPTION_SCHEDULES, array() );
		if ( ! is_array( $schedules ) ) {
			return array();
		}
		// Normalize older entries: single 'category' string => 'categories' array, and add 'enabled'.
		foreach ( $schedules as &$s ) {
			if ( ! isset( $s['categories'] ) || ! is_array( $s['categories'] ) ) {
				$s['categories'] = ( isset( $s['category'] ) && '' !== $s['category'] ) ? array( $s['category'] ) : array();
			}
			if ( ! isset( $s['enabled'] ) ) {
				$s['enabled'] = true;
			}
		}
		unset( $s );
		return $schedules;
	}

	/** Appends one entry to the persistent schedule run log (newest first), capped at SCHEDULE_LOG_MAX entries. */
	private function log_schedule_run( array $s, $status, array $result = array() ) {
		$log = get_option( self::OPTION_SCHEDULE_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$cat_label = '';
		if ( ! empty( $result['category'] ) ) {
			$cat_label = $result['category'];
		} elseif ( ! empty( $s['categories'] ) ) {
			$names = array();
			foreach ( $s['categories'] as $slug ) {
				$t = get_term_by( 'slug', $slug, self::TAX_CATEGORY );
				$names[] = $t ? $t->name : $slug;
			}
			$cat_label = implode( ', ', $names );
		} else {
			$cat_label = __( 'all categories', 'ka-listing-bulk-importer' );
		}

		array_unshift( $log, array(
			'time'     => current_time( 'mysql' ),
			'sch_id'   => $s['id'],
			'location' => $s['location'],
			'category' => $cat_label,
			'provider' => self::PROVIDERS[ $s['provider'] ]['label'] ?? $s['provider'],
			'status'   => $status, // 'ran' | 'skipped_not_due' | 'skipped_disabled' | 'error'
			'found'    => isset( $result['found'] ) ? (int) $result['found'] : 0,
			'created'  => isset( $result['created'] ) ? (int) $result['created'] : 0,
			'skipped'  => isset( $result['skipped'] ) ? (int) $result['skipped'] : 0,
			'message'  => isset( $result['error'] ) ? $result['error'] : '',
		) );

		if ( count( $log ) > self::SCHEDULE_LOG_MAX ) {
			$log = array_slice( $log, 0, self::SCHEDULE_LOG_MAX );
		}
		update_option( self::OPTION_SCHEDULE_LOG, $log, false );
	}

	private function get_schedule_log() {
		$log = get_option( self::OPTION_SCHEDULE_LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	public function handle_save_schedule() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		check_admin_referer( self::SCHEDULE_NONCE );

		$edit_id   = isset( $_POST['ka_lbi_sch_id'] ) ? sanitize_text_field( wp_unslash( $_POST['ka_lbi_sch_id'] ) ) : '';
		$location  = isset( $_POST['ka_lbi_sch_location'] ) ? sanitize_text_field( wp_unslash( $_POST['ka_lbi_sch_location'] ) ) : '';
		$raw_cats  = isset( $_POST['ka_lbi_sch_category'] ) ? (array) wp_unslash( $_POST['ka_lbi_sch_category'] ) : array();
		$cat_slugs = array();
		foreach ( $raw_cats as $c ) {
			$c = sanitize_key( $c );
			if ( '' !== $c && get_term_by( 'slug', $c, self::TAX_CATEGORY ) ) {
				$cat_slugs[] = $c;
			}
		}
		$provider = isset( $_POST['ka_lbi_sch_provider'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_sch_provider'] ) ) : '';
		$max      = isset( $_POST['ka_lbi_sch_max'] ) ? absint( $_POST['ka_lbi_sch_max'] ) : 20;
		$freq     = isset( $_POST['ka_lbi_sch_freq'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_sch_freq'] ) ) : 'weekly';
		$enabled  = ! empty( $_POST['ka_lbi_sch_enabled'] );

		$max = max( 1, min( self::MAX_DISCOVER_RESULTS, $max ) );
		if ( ! in_array( $freq, array( 'daily', 'weekly' ), true ) ) {
			$freq = 'weekly';
		}
		if ( '' === $location || ! isset( self::PROVIDERS[ $provider ] ) ) {
			$this->die_back( __( 'Please fill in a city and a data source for the schedule.', 'ka-listing-bulk-importer' ) );
		}

		$schedules = $this->get_schedules();

		if ( $edit_id ) {
			$found = false;
			foreach ( $schedules as &$s ) {
				if ( $s['id'] === $edit_id ) {
					$s['location']   = $location;
					$s['categories'] = $cat_slugs; // empty = all categories
					$s['category']   = ''; // keep the legacy key harmless/unused
					$s['provider']   = $provider;
					$s['max']        = $max;
					$s['freq']       = $freq;
					$s['enabled']    = $enabled;
					$found = true;
					break;
				}
			}
			unset( $s );
			if ( ! $found ) {
				$this->die_back( __( 'That scheduled search no longer exists — it may have been removed already.', 'ka-listing-bulk-importer' ) );
			}
		} else {
			$schedules[] = array(
				'id'         => wp_generate_uuid4(),
				'location'   => $location,
				'categories' => $cat_slugs, // empty = all categories
				'category'   => '', // legacy key, unused going forward
				'provider'   => $provider,
				'max'        => $max,
				'freq'       => $freq,
				'enabled'    => $enabled,
				'created'    => current_time( 'mysql' ),
				'last_run'   => '',
			);
		}
		update_option( self::OPTION_SCHEDULES, $schedules, false );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-schedules&ka_lbi_saved=1' ) );
		exit;
	}

	/** Enable/disable a schedule without deleting it (its due-date accounting is left untouched). */
	public function handle_toggle_schedule() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		check_admin_referer( self::SCHEDULE_NONCE );

		$id        = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$schedules = $this->get_schedules();
		foreach ( $schedules as &$s ) {
			if ( $s['id'] === $id ) {
				$s['enabled'] = empty( $s['enabled'] );
			}
		}
		unset( $s );
		update_option( self::OPTION_SCHEDULES, $schedules, false );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-schedules' ) );
		exit;
	}

	public function handle_delete_schedule() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		check_admin_referer( self::SCHEDULE_NONCE );

		$id        = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$schedules = array_values( array_filter( $this->get_schedules(), function ( $s ) use ( $id ) {
			return $s['id'] !== $id;
		} ) );
		update_option( self::OPTION_SCHEDULES, $schedules, false );

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-schedules' ) );
		exit;
	}

	/** Manual "run now" for testing a saved schedule without waiting for cron — runs regardless of its due date or enabled/disabled state. */
	public function handle_run_schedule_now() {
		if ( ! current_user_can( self::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		check_admin_referer( self::SCHEDULE_NONCE );

		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		foreach ( $this->get_schedules() as $s ) {
			if ( $s['id'] === $id ) {
				$summary = $this->execute_one_schedule( $s );
				$this->update_schedule_last_run( $id );
				$this->log_schedule_run( $s, ! empty( $summary['error'] ) ? 'error' : 'ran', $summary );
				$this->email_schedule_summary( array( $summary ) );
				break;
			}
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=ka-lbi-schedules&ka_lbi_ran=1' ) );
		exit;
	}

	private function update_schedule_last_run( $id ) {
		$schedules = $this->get_schedules();
		foreach ( $schedules as &$s ) {
			if ( $s['id'] === $id ) {
				$s['last_run'] = current_time( 'mysql' );
			}
		}
		unset( $s );
		update_option( self::OPTION_SCHEDULES, $schedules, false );
	}

	/**
	 * Fired daily by WP-Cron. Runs any saved schedule that is due (weekly ones
	 * only once every ~7 days), imports genuinely new results as "pending"
	 * automatically (never publishes, never auto-updates an existing listing —
	 * that decision always stays with a human), and emails the site admin one
	 * summary of everything that ran.
	 */
	public function run_due_schedules() {
		$schedules = $this->get_schedules();
		if ( empty( $schedules ) ) {
			return;
		}

		$summaries = array();
		foreach ( $schedules as $s ) {
			if ( empty( $s['enabled'] ) ) {
				$this->log_schedule_run( $s, 'skipped_disabled' );
				continue;
			}
			$interval_seconds = ( 'daily' === $s['freq'] ) ? DAY_IN_SECONDS : ( 7 * DAY_IN_SECONDS );
			$last_run_ts      = ! empty( $s['last_run'] ) ? strtotime( $s['last_run'] ) : 0;
			if ( $last_run_ts && ( time() - $last_run_ts ) < ( $interval_seconds - HOUR_IN_SECONDS ) ) {
				$this->log_schedule_run( $s, 'skipped_not_due' );
				continue; // not due yet
			}
			$result      = $this->execute_one_schedule( $s );
			$summaries[] = $result;
			$this->update_schedule_last_run( $s['id'] );
			$this->log_schedule_run( $s, ! empty( $result['error'] ) ? 'error' : 'ran', $result );
		}

		if ( ! empty( $summaries ) ) {
			$this->email_schedule_summary( $summaries );
		}
	}

	/** Runs one saved schedule end-to-end: search, skip anything that already exists, import the rest as pending. Never publishes or overwrites automatically. */
	private function execute_one_schedule( array $s ) {
		$result = array(
			'location' => $s['location'],
			'provider' => self::PROVIDERS[ $s['provider'] ]['label'] ?? $s['provider'],
			'found'    => 0,
			'created'  => 0,
			'skipped'  => 0,
			'error'    => '',
		);

		$key = get_option( self::OPTION_KEY_PREFIX . $s['provider'], '' );
		if ( '' === $key ) {
			$result['error'] = __( 'No API key saved for this source.', 'ka-listing-bulk-importer' );
			return $result;
		}

		$sel_cats       = ! empty( $s['categories'] ) && is_array( $s['categories'] ) ? $s['categories'] : array();
		$all_categories = empty( $sel_cats );
		if ( $all_categories ) {
			$search_categories = get_terms( array( 'taxonomy' => self::TAX_CATEGORY, 'hide_empty' => false ) );
			if ( is_wp_error( $search_categories ) || empty( $search_categories ) ) {
				$result['error'] = __( 'No categories exist on the site.', 'ka-listing-bulk-importer' );
				return $result;
			}
		} else {
			$search_categories = array();
			foreach ( $sel_cats as $slug ) {
				$t = get_term_by( 'slug', $slug, self::TAX_CATEGORY );
				if ( $t ) {
					$search_categories[] = $t;
				}
			}
			if ( empty( $search_categories ) ) {
				$result['error'] = __( 'None of the saved categories exist anymore.', 'ka-listing-bulk-importer' );
				return $result;
			}
		}
		$result['category'] = $all_categories ? __( 'all categories', 'ka-listing-bulk-importer' ) : implode( ', ', wp_list_pluck( $search_categories, 'name' ) );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 240 );
		}

		$this->last_search_error = '';
		$found              = $this->search_categories_for_location( $s['provider'], $key, $s['location'], $search_categories, (int) $s['max'] );
		$result['found']    = count( $found );
		if ( empty( $found ) && $this->last_search_error ) {
			$result['error'] = $this->last_search_error;
			return $result;
		}

		foreach ( $found as $i => $fields ) {
			$record = $this->make_record( $i, $fields );
			if ( ! empty( $record['errors'] ) || ! empty( $record['duplicates'] ) ) {
				$result['skipped']++;
				continue; // Scheduled runs only ever add clearly-new listings — anything ambiguous is left for a human via the Discover tab.
			}
			$write = $this->write_row( $record['fields'], 'pending', 0 );
			if ( ! is_wp_error( $write['post_id'] ) ) {
				$result['created']++;
			} else {
				$result['skipped']++;
			}
		}

		$is_ai        = self::PROVIDERS[ $s['provider'] ]['is_ai'];
		$active_model = $is_ai ? get_option( self::OPTION_MODEL_PREFIX . $s['provider'], self::PROVIDERS[ $s['provider'] ]['default_model'] ) : '';
		$cost_each    = (float) get_option( $this->cost_option_key( $s['provider'], $active_model ), 0 );
		if ( $cost_each > 0 && $result['found'] > 0 ) {
			$spend_option = self::OPTION_SPEND_PREFIX . $s['provider'];
			update_option( $spend_option, (float) get_option( $spend_option, 0 ) + ( $cost_each * $result['found'] ), false );
		}

		$this->log_discover_search( $s['location'], $result['category'], $s['provider'], 'new_only', $result['found'], $result['created'] );

		return $result;
	}

	private function email_schedule_summary( array $summaries ) {
		$to = get_option( 'admin_email' );
		if ( ! $to ) {
			return;
		}

		$total_created = array_sum( wp_list_pluck( $summaries, 'created' ) );
		$subject = sprintf(
			/* translators: 1: site name, 2: number of listings created */
			__( '[%1$s] Scheduled Discover run: %2$d new listing(s) added', 'ka-listing-bulk-importer' ),
			get_bloginfo( 'name' ),
			$total_created
		);

		$lines = array();
		foreach ( $summaries as $s ) {
			if ( ! empty( $s['error'] ) ) {
				$lines[] = sprintf( '- %s (%s): %s', $s['location'], $s['provider'], $s['error'] );
				continue;
			}
			$lines[] = sprintf(
				/* translators: 1: location, 2: category, 3: provider, 4: found count, 5: created count, 6: skipped count */
				__( '- %1$s / %2$s via %3$s: %4$d found, %5$d added as pending, %6$d skipped (already existed or had a problem)', 'ka-listing-bulk-importer' ),
				$s['location'],
				$s['category'] ?? '',
				$s['provider'],
				$s['found'],
				$s['created'],
				$s['skipped']
			);
		}

		$body  = __( 'The KA Schindler Listing Bulk Importer ran its scheduled Discover search(es):', 'ka-listing-bulk-importer' ) . "\n\n";
		$body .= implode( "\n", $lines ) . "\n\n";
		$body .= __( 'Anything added is set to "Pending" and needs a quick human review before it goes live.', 'ka-listing-bulk-importer' ) . "\n";
		$body .= admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&post_status=pending' ) . "\n";

		wp_mail( $to, $subject, $body );
	}
}

register_activation_hook( __FILE__, array( 'KA_Listing_Bulk_Importer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KA_Listing_Bulk_Importer', 'deactivate' ) );
new KA_Listing_Bulk_Importer();
