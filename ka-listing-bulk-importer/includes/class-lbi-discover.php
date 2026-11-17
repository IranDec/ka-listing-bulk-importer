<?php
/**
 * Discover: search cities x categories with one source. Small runs end in the preview,
 * large runs become a background job that only adds clearly new listings as Pending.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Discover {

	const MAX_INTERACTIVE = 24;  // city x category searches shown in one preview
	const MAX_COMBOS      = 2000;
	const MAX_RESULTS     = 60;
	const OPTION_LOG      = 'ka_lbi_discover_log';

	/** Parse the form into a run definition, or fail back to the form. */
	private static function read_form() {
		$lang     = KA_LBI_Lang::import_language( isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification
		$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! KA_LBI_Providers::exists( $provider ) || '' === KA_LBI_Providers::key( $provider ) ) {
			KA_LBI_Plugin::fail( __( 'Please choose a data source that has a key saved.', 'ka-listing-bulk-importer' ), 'ka-lbi-discover' );
		}

		$cities = array();
		foreach ( isset( $_POST['locations'] ) ? array_map( 'absint', (array) $_POST['locations'] ) : array() as $tid ) { // phpcs:ignore WordPress.Security.NonceVerification
			$t = get_term( KA_LBI_Lang::term_in( $tid, $lang ), KA_LBI_Plugin::TAX_LOCATION );
			if ( $t && ! is_wp_error( $t ) ) {
				$cities[ 't' . $t->term_id ] = array( 'location' => $t->name, 'search' => KA_LBI_Lang::search_name( $t ) );
			}
		}
		$typed = isset( $_POST['new_places'] ) ? sanitize_textarea_field( wp_unslash( $_POST['new_places'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		foreach ( array_filter( array_map( 'trim', preg_split( '/[\r\n,;]+/', $typed ) ) ) as $name ) {
			$t = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_LOCATION, $name, $lang );
			$cities[ $t ? 't' . $t->term_id : 'n' . KA_LBI_Lang::norm( $name ) ] = $t ? array( 'location' => $t->name, 'search' => KA_LBI_Lang::search_name( $t ) ) : array( 'location' => $name, 'search' => $name );
		}
		if ( ! $cities ) {
			KA_LBI_Plugin::fail( __( 'Please pick at least one city.', 'ka-listing-bulk-importer' ), 'ka-lbi-discover' );
		}

		$slugs = isset( $_POST['categories'] ) ? array_filter( array_map( 'sanitize_title', (array) wp_unslash( $_POST['categories'] ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$cats  = array();
		if ( $slugs ) {
			foreach ( $slugs as $slug ) {
				$t = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_CATEGORY, $slug, $lang );
				if ( $t ) {
					$cats[ $t->slug ] = $t;
				}
			}
		} else {
			foreach ( KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_CATEGORY, $lang ) as $t ) {
				$cats[ $t->slug ] = $t;
			}
		}
		if ( ! $cats ) {
			KA_LBI_Plugin::fail( __( 'No categories found for this language.', 'ka-listing-bulk-importer' ), 'ka-lbi-discover' );
		}

		$max    = isset( $_POST['max'] ) ? absint( $_POST['max'] ) : 20; // phpcs:ignore WordPress.Security.NonceVerification
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'pending'; // phpcs:ignore WordPress.Security.NonceVerification
		$mode   = isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification
		return array(
			'lang'     => $lang,
			'provider' => $provider,
			'cities'   => array_values( $cities ),
			'cats'     => array_values( $cats ),
			'all_cats' => ! $slugs,
			'max'      => max( 1, min( self::MAX_RESULTS, $max ) ),
			'status'   => in_array( $status, array( 'pending', 'draft', 'publish' ), true ) ? $status : 'pending',
			'mode'     => in_array( $mode, array( 'all', 'new_only', 'update_existing' ), true ) ? $mode : 'all',
		);
	}

	private static function label( array $run ) {
		$city = count( $run['cities'] ) > 1
			/* translators: 1: first city, 2: number of further cities */
			? sprintf( __( '%1$s + %2$d more', 'ka-listing-bulk-importer' ), $run['cities'][0]['location'], count( $run['cities'] ) - 1 )
			: $run['cities'][0]['location'];
		$cat = $run['all_cats'] ? __( 'all categories', 'ka-listing-bulk-importer' )
			: ( count( $run['cats'] ) > 1
				/* translators: %d: number of categories */
				? sprintf( __( '%d categories', 'ka-listing-bulk-importer' ), count( $run['cats'] ) )
				: $run['cats'][0]->name );
		return $city . ' / ' . $cat . ' · ' . KA_LBI_Providers::label( $run['provider'] );
	}

	public static function handle() {
		KA_LBI_Plugin::guard();
		$run    = self::read_form();
		$combos = count( $run['cities'] ) * count( $run['cats'] );
		if ( $combos > self::MAX_COMBOS ) {
			/* translators: %d: maximum number of searches */
			KA_LBI_Plugin::fail( sprintf( __( 'That would be more than %d searches. Please pick fewer cities or categories.', 'ka-listing-bulk-importer' ), self::MAX_COMBOS ), 'ka-lbi-discover' );
		}
		if ( $combos > self::MAX_INTERACTIVE ) {
			self::start_job( $run );
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( min( 600, 60 + 20 * $combos ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$rows   = array();
		$errors = array();
		$found  = 0;
		try {
			foreach ( $run['cities'] as $city ) {
				foreach ( $run['cats'] as $cat ) {
					$res = KA_LBI_Providers::discover( $run['provider'], $city['search'], $cat, $run['max'] );
					if ( is_wp_error( $res ) ) {
						$errors[] = $res->get_error_message();
						continue;
					}
					$found += count( $res );
					foreach ( $res as $f ) {
						$f['location'] = $city['location'];
						$rows[]        = $f;
					}
					self::log( $city['location'], $cat, $run['provider'], count( $res ) );
				}
			}
		} catch ( \Throwable $e ) {
			KA_LBI_Plugin::fail( sprintf(
				/* translators: %s: error message */
				__( 'Something went wrong while searching: %s', 'ka-listing-bulk-importer' ),
				$e->getMessage()
			), 'ka-lbi-discover' );
		}
		KA_LBI_Providers::add_spend( $run['provider'], $found );

		if ( ! $rows ) {
			KA_LBI_Plugin::fail( $errors ? implode( ' ', array_unique( $errors ) ) : __( 'No results. Try another city, category or source.', 'ka-listing-bulk-importer' ), 'ka-lbi-discover' );
		}

		$is_ai = KA_LBI_Providers::is_ai( $run['provider'] );
		/* translators: 1: number of results, 2: what was searched */
		$note = sprintf( __( '%1$d results for %2$s.', 'ka-listing-bulk-importer' ), count( $rows ), self::label( $run ) );
		if ( $is_ai ) {
			$note .= ' ' . __( 'These come from the model\'s knowledge, not a live search - verify address and phone before publishing.', 'ka-listing-bulk-importer' );
		}
		if ( $errors ) {
			/* translators: %s: error message */
			$note .= ' ' . sprintf( __( 'Some searches failed: %s', 'ka-listing-bulk-importer' ), implode( ' ', array_unique( $errors ) ) );
		}
		$batch = KA_LBI_Batch::create( $rows, array(
			'source' => $run['provider'],
			'status' => $run['status'],
			'mode'   => $run['mode'],
			'lang'   => $run['lang'],
			'note'   => $note,
			'warn'   => $is_ai || $errors ? 1 : 0,
		) );
		if ( 'new_only' === $run['mode'] ) {
			$kept = array_values( array_filter( $batch['records'], function ( $r ) {
				return ! $r['duplicates'];
			} ) );
			if ( ! $kept ) {
				KA_LBI_Batch::clear();
				KA_LBI_Plugin::fail( __( 'Everything found already exists on the site - nothing new to review.', 'ka-listing-bulk-importer' ), 'ka-lbi-discover' );
			}
			$batch['records'] = $kept;
			set_transient( 'ka_lbi_' . get_current_user_id() . '_batch', $batch, KA_LBI_Batch::TTL );
		}
		wp_safe_redirect( KA_LBI_Plugin::url( 'ka-lbi-import', array( 'step' => 'preview' ) ) );
		exit;
	}

	private static function start_job( array $run ) {
		$queue = array();
		foreach ( $run['cities'] as $city ) {
			foreach ( $run['cats'] as $cat ) {
				$queue[] = array( 'location' => $city['location'], 'search' => $city['search'], 'category' => $cat->slug );
			}
		}
		$job = KA_LBI_Jobs::start( 'discover', $queue, array(
			'provider' => $run['provider'],
			'max'      => min( $run['max'], (int) KA_LBI_Settings::get( 'job_results' ) ),
			'lang'     => $run['lang'],
			'status'   => 'pending', // unattended runs never publish
			'label'    => self::label( $run ),
		) );
		if ( is_wp_error( $job ) ) {
			KA_LBI_Plugin::fail( $job->get_error_message(), 'ka-lbi-discover' );
		}
		KA_LBI_Plugin::back( 'ka-lbi-discover', __( 'Background search started. This page shows the progress; it also continues when you close it.', 'ka-listing-bulk-importer' ) );
	}

	/** One city x category inside a background job or a schedule. */
	public static function job_item( array $job, array $item ) {
		$meta = $job['meta'];
		$cat  = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_CATEGORY, $item['category'], $meta['lang'] );
		if ( ! $cat ) {
			return array( 'counts' => array( 'failed' => 1 ), 'error' => __( 'Category no longer exists.', 'ka-listing-bulk-importer' ) );
		}
		if ( '' === KA_LBI_Providers::key( $meta['provider'] ) ) {
			return array( 'stop' => __( 'The API key was removed while the job was running.', 'ka-listing-bulk-importer' ) );
		}
		$search = ! empty( $item['search'] ) ? $item['search'] : $item['location'];
		$found  = KA_LBI_Providers::discover( $meta['provider'], $search, $cat, (int) $meta['max'] );
		if ( is_wp_error( $found ) ) {
			return array( 'counts' => array( 'failed' => 1 ), 'error' => $found->get_error_message() );
		}
		KA_LBI_Providers::add_spend( $meta['provider'], count( $found ) );
		self::log( $item['location'], $cat, $meta['provider'], count( $found ) );
		if ( ! $found ) {
			return array( 'counts' => array( 'empty' => 1 ) );
		}
		$counts  = array( 'found' => count( $found ), 'created' => 0, 'skipped' => 0 );
		$created = array();
		foreach ( $found as $f ) {
			$f['location'] = $item['location'];
			$r             = KA_LBI_Batch::make_record( 0, $f );
			if ( $r['errors'] || $r['duplicates'] ) {
				$counts['skipped']++; // unattended runs only add clearly new businesses
				continue;
			}
			$w = KA_LBI_Store::write( $r['fields'], isset( $meta['status'] ) ? $meta['status'] : 'pending', 0, array(
				'lang'             => $meta['lang'],
				'source'           => $meta['provider'],
				'batch'            => $job['id'],
				'create_locations' => (bool) KA_LBI_Settings::get( 'create_locations' ),
			) );
			if ( is_wp_error( $w['post_id'] ) ) {
				$counts['skipped']++;
			} else {
				$counts['created']++;
				$created[] = $w['post_id'];
			}
		}
		return array(
			'counts'      => $counts,
			'created_ids' => $created,
			/* translators: 1: city, 2: category, 3: new listings */
			'message'     => sprintf( __( '%1$s / %2$s: %3$d new', 'ka-listing-bulk-importer' ), $item['location'], $cat->name, $counts['created'] ),
		);
	}

	/** Rolling log so the same search is not repeated (and paid for) by accident. */
	public static function log( $location, $cat, $provider, $found ) {
		$log   = get_option( self::OPTION_LOG, array() );
		$log   = is_array( $log ) ? $log : array();
		$log[] = array(
			'time'          => current_time( 'mysql' ),
			'location'      => (string) $location,
			'category'      => $cat->name,
			'category_slug' => $cat->slug,
			'provider'      => KA_LBI_Providers::label( $provider ),
			'provider_slug' => $provider,
			'found'         => (int) $found,
			'kept'          => (int) $found,
		);
		update_option( self::OPTION_LOG, array_slice( $log, -300 ), false );
	}

	public static function recent( $limit = 15 ) {
		$log = get_option( self::OPTION_LOG, array() );
		return array_slice( array_reverse( is_array( $log ) ? $log : array() ), 0, $limit );
	}

	/**
	 * For the selected cities: listings per category and categories searched in the
	 * last 30 days. Powers the badges on the category chips.
	 */
	public static function ajax_coverage() {
		if ( ! current_user_can( KA_LBI_Plugin::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		check_ajax_referer( KA_LBI_Plugin::NONCE, 'nonce' );
		$lang = KA_LBI_Lang::import_language( isset( $_POST['lang'] ) ? sanitize_key( wp_unslash( $_POST['lang'] ) ) : '' );
		$ids  = isset( $_POST['locations'] ) ? array_slice( array_map( 'absint', (array) $_POST['locations'] ), 0, 50 ) : array();
		$out  = array( 'counts' => array(), 'recent' => array() );
		if ( ! $ids ) {
			wp_send_json_success( $out );
		}
		global $wpdb;
		$ids = array_map( function ( $id ) use ( $lang ) {
			return KA_LBI_Lang::term_in( $id, $lang );
		}, $ids );
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results( $wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT ct.slug, COUNT(DISTINCT p.ID) n
			FROM {$wpdb->posts} p
			JOIN {$wpdb->term_relationships} rl ON rl.object_id = p.ID
			JOIN {$wpdb->term_taxonomy} tl ON tl.term_taxonomy_id = rl.term_taxonomy_id AND tl.taxonomy = %s AND tl.term_id IN ($in)
			JOIN {$wpdb->term_relationships} rc ON rc.object_id = p.ID
			JOIN {$wpdb->term_taxonomy} tc ON tc.term_taxonomy_id = rc.term_taxonomy_id AND tc.taxonomy = %s
			JOIN {$wpdb->terms} ct ON ct.term_id = tc.term_id
			WHERE p.post_type = %s AND p.post_status IN ('publish','pending','draft')
			GROUP BY ct.slug",
			KA_LBI_Plugin::TAX_LOCATION,
			KA_LBI_Plugin::TAX_CATEGORY,
			KA_LBI_Plugin::POST_TYPE
		) );
		foreach ( (array) $rows as $r ) {
			$out['counts'][ $r->slug ] = (int) $r->n;
		}
		$names = array();
		foreach ( $ids as $id ) {
			$t = get_term( $id );
			if ( $t && ! is_wp_error( $t ) ) {
				$names[] = KA_LBI_Lang::norm( $t->name );
			}
		}
		$limit = strtotime( '-30 days', current_time( 'timestamp' ) );
		foreach ( self::recent( 300 ) as $e ) {
			if ( ! empty( $e['category_slug'] ) && strtotime( $e['time'] ) >= $limit && in_array( KA_LBI_Lang::norm( $e['location'] ), $names, true ) ) {
				$out['recent'][ $e['category_slug'] ] = $e['time'];
			}
		}
		wp_send_json_success( $out );
	}
}
