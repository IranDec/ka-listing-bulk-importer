<?php
/**
 * Background jobs (large Discover runs, enrichment). One job per type at a time.
 *
 * Work is done in small ticks. A tick is triggered by WP-Cron (single events, the fallback
 * when nobody watches) and by the live progress card polling admin-ajax, so a job keeps
 * moving even where the WP-Cron loopback is blocked. A short lock prevents two ticks from
 * processing the same items, and a minimum gap keeps polling from hammering providers.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Jobs {

	const OPTION_PREFIX  = 'ka_lbi_job_';
	const OPTION_HISTORY = 'ka_lbi_job_history';
	const HISTORY_MAX    = 40;

	public static function types() {
		return array( 'discover', 'enrich' );
	}

	/** Items per tick and seconds between ticks. */
	private static function pace( array $job ) {
		if ( 'enrich' === $job['type'] ) {
			return array( 2, 8 );
		}
		return KA_LBI_Providers::is_ai( isset( $job['meta']['provider'] ) ? $job['meta']['provider'] : '' ) ? array( 3, 8 ) : array( 2, 10 );
	}

	public static function get( $type ) {
		$job = get_option( self::OPTION_PREFIX . $type, null );
		return is_array( $job ) && ! empty( $job['id'] ) ? $job : null;
	}

	private static function save( array $job ) {
		update_option( self::OPTION_PREFIX . $job['type'], $job, false );
	}

	/** @return array|WP_Error */
	public static function start( $type, array $queue, array $meta ) {
		if ( self::get( $type ) ) {
			return new WP_Error( 'ka_lbi_job_running', __( 'A background run of this kind is already in progress. Wait for it or cancel it first.', 'ka-listing-bulk-importer' ) );
		}
		$job = array(
			'type'       => $type,
			'id'         => wp_generate_uuid4(),
			'queue'      => array_values( $queue ),
			'total'      => count( $queue ),
			'done'       => 0,
			'counts'     => array(),
			'messages'   => array(),
			'last_error' => '',
			'meta'       => $meta,
			'started_by' => get_current_user_id(),
			'started_at' => current_time( 'mysql' ),
			'updated_at' => 0,
		);
		self::save( $job );
		// First tick right away so the job visibly starts even if WP-Cron is unreliable here.
		self::tick( $type, true );
		if ( function_exists( 'spawn_cron' ) ) {
			spawn_cron();
		}
		return $job;
	}

	/** Convert a running 3.x job so it continues after the update. */
	public static function import_legacy( $type, array $old ) {
		$queue = array();
		if ( 'discover' === $type ) {
			foreach ( (array) $old['queue'] as $q ) {
				$queue[] = array( 'location' => isset( $q['location'] ) ? $q['location'] : '', 'category' => isset( $q['category'] ) ? $q['category'] : '' );
			}
			$meta = array( 'provider' => isset( $old['provider'] ) ? $old['provider'] : 'google', 'max' => isset( $old['max'] ) ? (int) $old['max'] : 5, 'status' => 'pending', 'lang' => KA_LBI_Lang::import_language(), 'label' => __( 'Continued from version 3', 'ka-listing-bulk-importer' ) );
		} else {
			$queue = array_map( 'intval', (array) $old['queue'] );
			$meta  = array( 'what' => array( 'photo' ), 'sources' => array( isset( $old['source'] ) && 'google' === $old['source'] ? 'google' : 'website' ), 'label' => __( 'Continued from version 3', 'ka-listing-bulk-importer' ) );
		}
		if ( $queue ) {
			update_option( self::OPTION_PREFIX . $type, array(
				'type' => $type, 'id' => wp_generate_uuid4(), 'queue' => $queue, 'total' => count( $queue ), 'done' => 0, 'counts' => array(), 'messages' => array(),
				'last_error' => '', 'meta' => $meta, 'started_by' => isset( $old['started_by'] ) ? (int) $old['started_by'] : 0, 'started_at' => current_time( 'mysql' ), 'updated_at' => 0,
			), false );
			wp_schedule_single_event( time() + 30, KA_LBI_Plugin::JOB_HOOK, array( $type ) );
		}
	}

	public static function cancel( $type, $reason = '' ) {
		$job = self::get( $type );
		if ( $job ) {
			$job['cancelled'] = $reason ? $reason : __( 'Cancelled by an administrator.', 'ka-listing-bulk-importer' );
			self::finish( $job );
		}
	}

	public static function cron_tick( $type = 'discover' ) {
		self::tick( (string) $type, true );
	}

	/**
	 * Process one small batch.
	 *
	 * @param bool $force skip the minimum gap (cron and the initial kick-off)
	 * @return array|null job state after the tick, null when finished
	 */
	public static function tick( $type, $force = false ) {
		$job = self::get( $type );
		if ( ! $job ) {
			return null;
		}
		if ( ! $job['queue'] ) {
			self::finish( $job );
			return null;
		}
		list( $size, $gap ) = self::pace( $job );
		if ( ! $force && $job['updated_at'] && ( time() - (int) $job['updated_at'] ) < $gap ) {
			return $job;
		}
		$lock = 'ka_lbi_lock_' . $type;
		if ( get_transient( $lock ) ) {
			return $job;
		}
		set_transient( $lock, 1, 90 );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}

		try {
			$items = array_splice( $job['queue'], 0, $size );
			foreach ( $items as $item ) {
				$result = 'discover' === $type ? KA_LBI_Discover::job_item( $job, $item ) : KA_LBI_Enrich::job_item( $job, $item );
				foreach ( isset( $result['counts'] ) ? $result['counts'] : array() as $k => $n ) {
					$job['counts'][ $k ] = ( isset( $job['counts'][ $k ] ) ? $job['counts'][ $k ] : 0 ) + (int) $n;
				}
				if ( ! empty( $result['error'] ) ) {
					$job['last_error'] = $result['error'];
				}
				if ( ! empty( $result['message'] ) ) {
					array_unshift( $job['messages'], $result['message'] );
					$job['messages'] = array_slice( $job['messages'], 0, 8 );
				}
				if ( ! empty( $result['created_ids'] ) ) {
					$job['created_ids'] = array_merge( isset( $job['created_ids'] ) ? $job['created_ids'] : array(), $result['created_ids'] );
				}
				if ( ! empty( $result['stop'] ) ) {
					$job['cancelled'] = $result['stop'];
					$job['queue']     = array();
				}
				$job['done']++;
			}
		} catch ( \Throwable $e ) {
			$job['last_error'] = $e->getMessage();
			$job['cancelled']  = sprintf(
				/* translators: %s: PHP error message */
				__( 'Stopped because of an unexpected error: %s', 'ka-listing-bulk-importer' ),
				$e->getMessage()
			);
			error_log( '[KA Listing Bulk Importer] ' . $type . ' job: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions
			$job['queue'] = array();
		}

		$job['updated_at'] = time();
		if ( ! $job['queue'] ) {
			self::finish( $job );
			delete_transient( $lock );
			return null;
		}
		self::save( $job );
		wp_schedule_single_event( time() + $gap, KA_LBI_Plugin::JOB_HOOK, array( $type ) );
		delete_transient( $lock );
		return $job;
	}

	/** Public shape for the progress card (no queue). */
	public static function payload( $job ) {
		if ( ! $job ) {
			return null;
		}
		return array(
			'type'       => $job['type'],
			'total'      => (int) $job['total'],
			'done'       => (int) $job['done'],
			'pct'        => $job['total'] ? (int) floor( 100 * $job['done'] / $job['total'] ) : 0,
			'counts'     => (object) $job['counts'],
			'messages'   => $job['messages'],
			'last_error' => $job['last_error'],
			'label'      => isset( $job['meta']['label'] ) ? $job['meta']['label'] : '',
		);
	}

	public static function ajax_poll() {
		if ( ! current_user_can( KA_LBI_Plugin::CAP ) ) {
			wp_send_json_error( array( 'message' => 'forbidden' ), 403 );
		}
		check_ajax_referer( KA_LBI_Plugin::NONCE, 'nonce' );
		$type = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		if ( ! in_array( $type, self::types(), true ) ) {
			wp_send_json_error( array( 'message' => 'type' ), 400 );
		}
		try {
			$job = self::tick( $type, false );
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ), 500 );
		}
		wp_send_json_success( array( 'job' => self::payload( $job ), 'summary' => $job ? null : self::last( $type ) ) );
	}

	private static function finish( array $job ) {
		delete_option( self::OPTION_PREFIX . $job['type'] );
		wp_clear_scheduled_hook( KA_LBI_Plugin::JOB_HOOK, array( $job['type'] ) );
		$entry = array(
			'type'       => $job['type'],
			'id'         => $job['id'],
			'label'      => isset( $job['meta']['label'] ) ? $job['meta']['label'] : '',
			'provider'   => isset( $job['meta']['provider'] ) ? $job['meta']['provider'] : '',
			'started_at' => $job['started_at'],
			'time'       => current_time( 'mysql' ),
			'total'      => (int) $job['total'],
			'done'       => (int) $job['done'],
			'counts'     => $job['counts'],
			'last_error' => $job['last_error'],
			'cancelled'  => isset( $job['cancelled'] ) ? $job['cancelled'] : '',
		);
		$history = get_option( self::OPTION_HISTORY, array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift( $history, $entry );
		update_option( self::OPTION_HISTORY, array_slice( $history, 0, self::HISTORY_MAX ), false );

		if ( 'discover' === $job['type'] && ! empty( $job['created_ids'] ) ) {
			KA_LBI_Batch::log( $job['created_ids'], array(), isset( $job['meta']['provider'] ) ? $job['meta']['provider'] : '', $job['id'], $job['started_by'] );
		}
		self::email( $entry );
	}

	public static function history( $type = '' ) {
		$h = get_option( self::OPTION_HISTORY, array() );
		$h = is_array( $h ) ? $h : array();
		if ( $type ) {
			$h = array_values( array_filter( $h, function ( $e ) use ( $type ) {
				return $e['type'] === $type;
			} ) );
		}
		return $h;
	}

	public static function last( $type ) {
		$h = self::history( $type );
		return $h ? $h[0] : null;
	}

	/** Human summary of a finished run. */
	public static function describe( array $e ) {
		$c = $e['counts'];
		$g = function ( $k ) use ( $c ) {
			return isset( $c[ $k ] ) ? (int) $c[ $k ] : 0;
		};
		if ( 'discover' === $e['type'] ) {
			/* translators: 1: searches done, 2: total searches, 3: added, 4: skipped as existing/unclear, 5: empty searches, 6: failed searches */
			return sprintf( __( '%1$d of %2$d searches: %3$d new listings added for review, %4$d skipped (existing or unclear), %5$d searches without results, %6$d failed.', 'ka-listing-bulk-importer' ), $e['done'], $e['total'], $g( 'created' ), $g( 'skipped' ), $g( 'empty' ), $g( 'failed' ) );
		}
		/* translators: 1: listings checked, 2: total, 3: listings improved, 4: fields filled, 5: unreachable websites */
		return sprintf( __( '%1$d of %2$d listings checked: %3$d improved, %4$d fields filled, %5$d websites not reachable.', 'ka-listing-bulk-importer' ), $e['done'], $e['total'], $g( 'improved' ), $g( 'fields' ), $g( 'unreachable' ) );
	}

	private static function email( array $e ) {
		$to = KA_LBI_Settings::notify_to();
		if ( ! $to ) {
			return;
		}
		$title = 'discover' === $e['type'] ? __( 'Background search finished', 'ka-listing-bulk-importer' ) : __( 'Enrichment finished', 'ka-listing-bulk-importer' );
		$body  = $e['label'] ? $e['label'] . "\n\n" : '';
		$body .= self::describe( $e ) . "\n";
		if ( $e['cancelled'] ) {
			$body .= "\n" . $e['cancelled'] . "\n";
		}
		if ( $e['last_error'] ) {
			/* translators: %s: error */
			$body .= "\n" . sprintf( __( 'Last error: %s', 'ka-listing-bulk-importer' ), $e['last_error'] ) . "\n";
		}
		if ( 'discover' === $e['type'] ) {
			$body .= "\n" . __( 'Review the new listings here:', 'ka-listing-bulk-importer' ) . "\n" . KA_LBI_Plugin::url( 'ka-lbi-review' ) . "\n";
		}
		wp_mail( $to, '[' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) . '] ' . $title, $body );
	}
}
