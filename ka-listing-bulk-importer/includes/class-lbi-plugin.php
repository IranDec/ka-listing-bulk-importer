<?php
/**
 * Bootstrap: shared constants, activation, upgrades from 3.x and hook wiring.
 *
 * Option names from 3.x are kept unchanged so keys, models, costs, spend counters,
 * schedules, logs and the undo history survive the update.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Plugin {

	const CAP          = 'edit_others_posts'; // may import and review listings
	const SETTINGS_CAP = 'manage_options';    // API keys and schedules are site-wide - admins only
	const POST_TYPE    = 'listing';
	const TAX_CATEGORY = 'listing-category';
	const TAX_LOCATION = 'location';
	const TAX_TAGS     = 'list-tags';

	const OPTION_VERSION = 'ka_lbi_version';
	const CRON_HOOK      = 'ka_lbi_scheduled_discover'; // daily schedule check (3.x name)
	const JOB_HOOK       = 'ka_lbi_job_tick';           // background job fallback tick, arg: job type
	const LEGACY_HOOKS   = array( 'ka_lbi_process_job_batch', 'ka_lbi_process_photo_job_batch' );

	const NONCE = 'ka_lbi_action';

	const AUTHOR_NAME = 'Mohammad Babaei';
	const AUTHOR_URL  = 'https://adschi.com';

	public static function init() {
		load_plugin_textdomain( 'ka-listing-bulk-importer', false, dirname( plugin_basename( KA_LBI_FILE ) ) . '/languages' );
		self::maybe_upgrade();

		add_action( self::CRON_HOOK, array( 'KA_LBI_Schedules', 'run_due' ) );
		add_action( self::JOB_HOOK, array( 'KA_LBI_Jobs', 'cron_tick' ) );
		add_action( 'transition_post_status', array( 'KA_LBI_Lang', 'on_publish' ), 20, 3 );

		if ( is_admin() ) {
			KA_LBI_Admin::init();
		}
	}

	public static function activate() {
		KA_LBI_Media::photo_dir();
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
		wp_clear_scheduled_hook( self::JOB_HOOK );
		foreach ( self::LEGACY_HOOKS as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
		// Running jobs cannot continue without their cron tick; keep their counts in the run log.
		foreach ( KA_LBI_Jobs::types() as $type ) {
			if ( KA_LBI_Jobs::get( $type ) ) {
				KA_LBI_Jobs::cancel( $type, __( 'The plugin was deactivated.', 'ka-listing-bulk-importer' ) );
			}
		}
	}

	/** One-time steps when the stored version differs from the code. */
	private static function maybe_upgrade() {
		$stored = get_option( self::OPTION_VERSION, '' );
		if ( KA_LBI_VERSION === $stored ) {
			return;
		}

		if ( '' === $stored || version_compare( $stored, '4.0.0', '<' ) ) {
			// 3.2: a single Google key option became the per-provider key.
			$legacy = get_option( 'ka_lbi_google_places_key', '' );
			if ( '' !== $legacy && '' === get_option( 'ka_lbi_key_google', '' ) ) {
				update_option( 'ka_lbi_key_google', $legacy, false );
			}
			delete_option( 'ka_lbi_google_places_key' );

			// 3.x background jobs used two dedicated cron hooks and options; 4.0 has one generic runner.
			foreach ( self::LEGACY_HOOKS as $hook ) {
				wp_clear_scheduled_hook( $hook );
			}
			foreach ( array( 'ka_lbi_bulk_job' => 'discover', 'ka_lbi_photo_job' => 'enrich' ) as $old => $type ) {
				$job = get_option( $old, null );
				if ( is_array( $job ) && ! empty( $job['queue'] ) && ! KA_LBI_Jobs::get( $type ) ) {
					KA_LBI_Jobs::import_legacy( $type, $job );
				}
				delete_option( $old );
			}
			delete_option( 'ka_lbi_last_job_error_interactive' );

			// A key saved for the legacy Places API needs a fresh test against Places API (New).
			delete_option( 'ka_lbi_status_google' );

			if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
				wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
			}
			KA_LBI_Media::photo_dir();
		}

		update_option( self::OPTION_VERSION, KA_LBI_VERSION, false );
	}

	/** Admin URL of one of the plugin screens. */
	public static function url( $page, array $args = array() ) {
		return add_query_arg( $args, admin_url( 'edit.php?post_type=' . self::POST_TYPE . '&page=' . $page ) );
	}

	/** admin-post.php URL with nonce for a GET action. */
	public static function action_url( $action, array $args = array() ) {
		return wp_nonce_url( add_query_arg( array_merge( array( 'action' => $action ), $args ), admin_url( 'admin-post.php' ) ), self::NONCE );
	}

	/** Shared guard for admin-post handlers. */
	public static function guard( $cap = self::CAP ) {
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'ka-listing-bulk-importer' ), 403 );
		}
		check_admin_referer( self::NONCE );
	}

	/** Redirect back to a plugin screen with a one-time notice. */
	public static function back( $page, $message = '', $type = 'success', array $args = array() ) {
		if ( '' !== $message ) {
			set_transient( 'ka_lbi_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => $message ), 120 );
		}
		wp_safe_redirect( self::url( $page, $args ) );
		exit;
	}

	/** Stop with a readable message instead of a white screen. */
	public static function fail( $message, $page = 'ka-lbi-dashboard' ) {
		self::back( $page, $message, 'error' );
	}
}
