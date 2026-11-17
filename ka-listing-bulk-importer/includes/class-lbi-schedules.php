<?php
/**
 * Recurring Discover searches. A due schedule becomes a background Discover job, so even
 * "all categories" schedules never run as one long request. New results are added as
 * Pending, existing or unclear ones are skipped.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Schedules {

	const OPTION     = 'ka_lbi_schedules';
	const OPTION_LOG = 'ka_lbi_schedule_log';
	const LOG_MAX    = 60;

	/** All schedules, normalized (3.x entries had a single category string). */
	public static function all() {
		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? $list : array();
		foreach ( $list as &$s ) {
			if ( ! isset( $s['categories'] ) || ! is_array( $s['categories'] ) ) {
				$s['categories'] = ! empty( $s['category'] ) ? array( $s['category'] ) : array();
			}
			$s = wp_parse_args( $s, array( 'id' => wp_generate_uuid4(), 'location' => '', 'provider' => 'google', 'max' => 15, 'freq' => 'weekly', 'enabled' => true, 'last_run' => '', 'lang' => '' ) );
		}
		unset( $s );
		return $list;
	}

	private static function save_all( array $list ) {
		update_option( self::OPTION, array_values( $list ), false );
	}

	public static function find( $id ) {
		foreach ( self::all() as $s ) {
			if ( $s['id'] === $id ) {
				return $s;
			}
		}
		return null;
	}

	public static function log() {
		$l = get_option( self::OPTION_LOG, array() );
		return is_array( $l ) ? $l : array();
	}

	private static function add_log( array $s, $status, $message = '', $searches = 0 ) {
		$log = self::log();
		array_unshift( $log, array(
			'time'     => current_time( 'mysql' ),
			'sch_id'   => $s['id'],
			'location' => $s['location'],
			'category' => self::categories_label( $s ),
			'provider' => KA_LBI_Providers::label( $s['provider'] ),
			'status'   => $status, // ran | error | skipped_not_due | skipped_disabled | skipped_busy
			'found'    => (int) $searches,
			'created'  => 0,
			'skipped'  => 0,
			'message'  => $message,
		) );
		update_option( self::OPTION_LOG, array_slice( $log, 0, self::LOG_MAX ), false );
	}

	public static function categories_label( array $s ) {
		if ( ! $s['categories'] ) {
			return __( 'all categories', 'ka-listing-bulk-importer' );
		}
		$names = array();
		foreach ( $s['categories'] as $slug ) {
			$t       = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_CATEGORY, $slug, KA_LBI_Lang::import_language( $s['lang'] ) );
			$names[] = $t ? $t->name : $slug;
		}
		return implode( ', ', $names );
	}

	public static function is_due( array $s ) {
		$interval = 'daily' === $s['freq'] ? DAY_IN_SECONDS : 7 * DAY_IN_SECONDS;
		$last     = $s['last_run'] ? strtotime( $s['last_run'] ) : 0;
		return ! $last || ( current_time( 'timestamp' ) - $last ) >= ( $interval - HOUR_IN_SECONDS );
	}

	/** Queue items for one schedule. */
	private static function queue( array $s ) {
		$lang = KA_LBI_Lang::import_language( $s['lang'] );
		$loc  = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_LOCATION, $s['location'], $lang );
		$city = $loc ? array( 'location' => $loc->name, 'search' => KA_LBI_Lang::search_name( $loc ) ) : array( 'location' => $s['location'], 'search' => $s['location'] );
		$cats = array();
		if ( $s['categories'] ) {
			foreach ( $s['categories'] as $slug ) {
				$t = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_CATEGORY, $slug, $lang );
				if ( $t ) {
					$cats[] = $t;
				}
			}
		} else {
			$cats = KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_CATEGORY, $lang );
		}
		$queue = array();
		foreach ( $cats as $c ) {
			$queue[] = array( 'location' => $city['location'], 'search' => $city['search'], 'category' => $c->slug );
		}
		return $queue;
	}

	/**
	 * Start a Discover job for the given schedules (all must share one provider).
	 *
	 * @return true|WP_Error
	 */
	private static function launch( array $schedules ) {
		$queue = array();
		$max   = 1;
		foreach ( $schedules as $s ) {
			$queue = array_merge( $queue, self::queue( $s ) );
			$max   = max( $max, (int) $s['max'] );
		}
		if ( ! $queue ) {
			return new WP_Error( 'ka_lbi_schedule_empty', __( 'None of the saved categories exist anymore.', 'ka-listing-bulk-importer' ) );
		}
		$first = $schedules[0];
		$label = count( $schedules ) > 1
			/* translators: %d: number of schedules */
			? sprintf( __( '%d scheduled searches', 'ka-listing-bulk-importer' ), count( $schedules ) )
			/* translators: 1: city, 2: categories */
			: sprintf( __( 'Schedule: %1$s / %2$s', 'ka-listing-bulk-importer' ), $first['location'], self::categories_label( $first ) );
		$job = KA_LBI_Jobs::start( 'discover', $queue, array(
			'provider' => $first['provider'],
			'max'      => min( $max, (int) KA_LBI_Settings::get( 'job_results' ) ),
			'lang'     => KA_LBI_Lang::import_language( $first['lang'] ),
			'status'   => 'pending',
			'label'    => $label,
		) );
		return is_wp_error( $job ) ? $job : true;
	}

	private static function mark_run( array $ids ) {
		$list = self::all();
		foreach ( $list as &$s ) {
			if ( in_array( $s['id'], $ids, true ) ) {
				$s['last_run'] = current_time( 'mysql' );
			}
		}
		unset( $s );
		self::save_all( $list );
	}

	/** Daily cron. */
	public static function run_due() {
		$due = array();
		foreach ( self::all() as $s ) {
			if ( empty( $s['enabled'] ) ) {
				self::add_log( $s, 'skipped_disabled' );
			} elseif ( ! self::is_due( $s ) ) {
				self::add_log( $s, 'skipped_not_due' );
			} elseif ( '' === KA_LBI_Providers::key( $s['provider'] ) ) {
				self::add_log( $s, 'error', __( 'No API key saved for this source.', 'ka-listing-bulk-importer' ) );
				self::mark_run( array( $s['id'] ) );
			} else {
				$due[] = $s;
			}
		}
		if ( ! $due ) {
			return;
		}
		if ( KA_LBI_Jobs::get( 'discover' ) ) {
			foreach ( $due as $s ) {
				self::add_log( $s, 'skipped_busy', __( 'Another background search was still running - trying again in an hour.', 'ka-listing-bulk-importer' ) );
			}
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, KA_LBI_Plugin::CRON_HOOK );
			return;
		}
		// One job per provider; the rest waits for the next check.
		$provider = $due[0]['provider'];
		$batch    = array_values( array_filter( $due, function ( $s ) use ( $provider ) {
			return $s['provider'] === $provider;
		} ) );
		$res = self::launch( $batch );
		foreach ( $batch as $s ) {
			if ( is_wp_error( $res ) ) {
				self::add_log( $s, 'error', $res->get_error_message() );
			} else {
				self::add_log( $s, 'ran', '', count( self::queue( $s ) ) );
			}
		}
		self::mark_run( wp_list_pluck( $batch, 'id' ) );
		if ( count( $batch ) < count( $due ) ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, KA_LBI_Plugin::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------------ admin handlers */

	public static function handle_save() {
		KA_LBI_Plugin::guard( KA_LBI_Plugin::SETTINGS_CAP );
		$id       = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';
		$lang     = KA_LBI_Lang::import_language( isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '' );
		$location = isset( $_POST['location'] ) ? sanitize_text_field( wp_unslash( $_POST['location'] ) ) : '';
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';
		$cats     = array();
		foreach ( isset( $_POST['categories'] ) ? (array) wp_unslash( $_POST['categories'] ) : array() as $slug ) {
			$t = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_CATEGORY, sanitize_title( $slug ), $lang );
			if ( $t ) {
				$cats[] = $t->slug;
			}
		}
		if ( '' === $location || ! KA_LBI_Providers::exists( $provider ) ) {
			KA_LBI_Plugin::fail( __( 'Please fill in a city and a data source.', 'ka-listing-bulk-importer' ), 'ka-lbi-schedules' );
		}
		$data = array(
			'location'   => $location,
			'categories' => array_values( array_unique( $cats ) ),
			'category'   => '',
			'provider'   => $provider,
			'max'        => max( 1, min( KA_LBI_Discover::MAX_RESULTS, isset( $_POST['max'] ) ? absint( $_POST['max'] ) : 15 ) ),
			'freq'       => isset( $_POST['freq'] ) && 'daily' === $_POST['freq'] ? 'daily' : 'weekly',
			'enabled'    => ! empty( $_POST['enabled'] ),
			'lang'       => $lang,
		);
		$list = self::all();
		if ( $id ) {
			$hit = false;
			foreach ( $list as &$s ) {
				if ( $s['id'] === $id ) {
					$s   = array_merge( $s, $data );
					$hit = true;
				}
			}
			unset( $s );
			if ( ! $hit ) {
				KA_LBI_Plugin::fail( __( 'That schedule no longer exists.', 'ka-listing-bulk-importer' ), 'ka-lbi-schedules' );
			}
		} else {
			$list[] = array_merge( $data, array( 'id' => wp_generate_uuid4(), 'created' => current_time( 'mysql' ), 'last_run' => '' ) );
		}
		self::save_all( $list );
		KA_LBI_Plugin::back( 'ka-lbi-schedules', __( 'Schedule saved.', 'ka-listing-bulk-importer' ) );
	}

	public static function handle_toggle() {
		KA_LBI_Plugin::guard( KA_LBI_Plugin::SETTINGS_CAP );
		$id   = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		$list = self::all();
		foreach ( $list as &$s ) {
			if ( $s['id'] === $id ) {
				$s['enabled'] = empty( $s['enabled'] );
			}
		}
		unset( $s );
		self::save_all( $list );
		KA_LBI_Plugin::back( 'ka-lbi-schedules' );
	}

	public static function handle_delete() {
		KA_LBI_Plugin::guard( KA_LBI_Plugin::SETTINGS_CAP );
		$id = isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '';
		self::save_all( array_filter( self::all(), function ( $s ) use ( $id ) {
			return $s['id'] !== $id;
		} ) );
		KA_LBI_Plugin::back( 'ka-lbi-schedules', __( 'Schedule removed.', 'ka-listing-bulk-importer' ) );
	}

	/** Run one schedule now, regardless of due date or pause. */
	public static function handle_run() {
		KA_LBI_Plugin::guard( KA_LBI_Plugin::SETTINGS_CAP );
		$s = self::find( isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '' );
		if ( ! $s ) {
			KA_LBI_Plugin::fail( __( 'That schedule no longer exists.', 'ka-listing-bulk-importer' ), 'ka-lbi-schedules' );
		}
		$res = self::launch( array( $s ) );
		if ( is_wp_error( $res ) ) {
			self::add_log( $s, 'error', $res->get_error_message() );
			KA_LBI_Plugin::fail( $res->get_error_message(), 'ka-lbi-schedules' );
		}
		self::add_log( $s, 'ran', '', count( self::queue( $s ) ) );
		self::mark_run( array( $s['id'] ) );
		KA_LBI_Plugin::back( 'ka-lbi-discover', __( 'The schedule is running as a background search - progress below.', 'ka-listing-bulk-importer' ) );
	}
}
