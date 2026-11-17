<?php
/**
 * The preview batch shared by CSV import and Discover: records are checked, shown,
 * corrected and only then written. One batch per admin user.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Batch {

	const TTL        = 21600; // 6 hours
	const OPTION_LOG = 'ka_lbi_import_log';
	const LOG_MAX    = 30;

	/** Fields the preview lets the admin correct before importing. */
	const EDITABLE = array( 'post_title', 'category', 'location', 'phone', 'gAddress', 'website', 'email' );

	private static function key() {
		return 'ka_lbi_' . get_current_user_id() . '_batch';
	}

	public static function get() {
		$b = get_transient( self::key() );
		return is_array( $b ) && ! empty( $b['records'] ) ? $b : null;
	}

	public static function clear() {
		delete_transient( self::key() );
	}

	/**
	 * @param array[] $rows  field arrays
	 * @param array   $meta  source, status, mode, lang, location (default), note, warn
	 */
	public static function create( array $rows, array $meta ) {
		KA_LBI_Store::forget_index();
		$records = array();
		$seen    = array();
		foreach ( array_values( $rows ) as $i => $fields ) {
			// The same business found twice in one run (e.g. under two categories): keep the first.
			$keys = KA_LBI_Store::title_keys( isset( $fields['post_title'] ) ? $fields['post_title'] : '' );
			$sig  = ! empty( $fields['place_id'] ) ? 'p:' . $fields['place_id'] : ( $keys ? 't:' . $keys[0] . '|' . ( isset( $fields['location'] ) ? $fields['location'] : '' ) : '' );
			if ( '' !== $sig && isset( $seen[ $sig ] ) ) {
				continue;
			}
			$seen[ $sig ] = true;
			$records[]    = self::make_record( count( $records ), $fields );
		}
		$batch = array(
			'id'      => wp_generate_uuid4(),
			'created' => time(),
			'records' => $records,
			'meta'    => wp_parse_args( $meta, array( 'source' => 'csv', 'status' => 'pending', 'mode' => 'all', 'lang' => KA_LBI_Lang::import_language(), 'note' => '', 'warn' => 0 ) ),
		);
		set_transient( self::key(), $batch, self::TTL );
		return $batch;
	}

	/** Validate one row and look for existing listings. */
	public static function make_record( $row, array $fields ) {
		$fields = array_map( function ( $v ) {
			return is_string( $v ) ? trim( $v ) : $v;
		}, $fields );
		$errors   = array();
		$warnings = array();
		if ( '' === ( isset( $fields['post_title'] ) ? $fields['post_title'] : '' ) ) {
			$errors[] = __( 'Missing title', 'ka-listing-bulk-importer' );
		}
		if ( ! empty( $fields['email'] ) && ! is_email( $fields['email'] ) ) {
			$errors[] = __( 'Invalid e-mail', 'ka-listing-bulk-importer' );
		}
		if ( ! empty( $fields['category'] ) && ! KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_CATEGORY, explode( ',', $fields['category'] )[0], KA_LBI_Lang::import_language() ) ) {
			$warnings[] = __( 'Unknown category - the listing will have none', 'ka-listing-bulk-importer' );
		}
		if ( ! empty( $fields['location'] ) && ! KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_LOCATION, $fields['location'], KA_LBI_Lang::import_language() ) ) {
			$warnings[] = KA_LBI_Settings::get( 'create_locations' )
				? __( 'New location - it will be created', 'ka-listing-bulk-importer' )
				: __( 'Unknown location - the listing will have none', 'ka-listing-bulk-importer' );
		}
		if ( ! empty( $fields['ai'] ) ) {
			$warnings[] = __( 'AI suggestion - check address and phone before publishing', 'ka-listing-bulk-importer' );
		}
		return array(
			'row'        => (int) $row,
			'fields'     => $fields,
			'errors'     => $errors,
			'warnings'   => $warnings,
			'duplicates' => '' !== ( isset( $fields['post_title'] ) ? $fields['post_title'] : '' ) ? KA_LBI_Store::find_duplicates( $fields ) : array(),
		);
	}

	/** Default action for a record, honouring the batch mode. */
	public static function default_action( array $r, $mode ) {
		if ( $r['errors'] ) {
			return 'skip';
		}
		if ( $r['duplicates'] ) {
			return 'update_existing' === $mode ? 'update:' . (int) $r['duplicates'][0]['id'] : 'skip';
		}
		return 'create';
	}

	/**
	 * Write the batch.
	 *
	 * @param array $actions row => create|skip|update:ID
	 * @param array $edits   row => [field => value]
	 * @return array created, updated, skipped, failed, photo_warnings
	 */
	public static function import( array $actions, array $edits, $status ) {
		$batch = self::get();
		if ( ! $batch ) {
			return null;
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 600 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		$meta   = $batch['meta'];
		$status = in_array( $status, array( 'pending', 'draft', 'publish' ), true ) ? $status : 'pending';
		$out    = array( 'created' => array(), 'updated' => array(), 'skipped' => array(), 'failed' => array(), 'photo_warnings' => array() );
		$ctx    = array(
			'lang'             => $meta['lang'],
			'source'           => $meta['source'],
			'batch'            => $batch['id'],
			'create_locations' => (bool) KA_LBI_Settings::get( 'create_locations' ),
			'keep_photo'       => true,
		);

		foreach ( $batch['records'] as $r ) {
			$row    = (int) $r['row'];
			$action = isset( $actions[ $row ] ) ? sanitize_text_field( $actions[ $row ] ) : 'skip';
			$fields = $r['fields'];
			if ( isset( $edits[ $row ] ) && is_array( $edits[ $row ] ) ) {
				foreach ( self::EDITABLE as $k ) {
					if ( isset( $edits[ $row ][ $k ] ) ) {
						$fields[ $k ] = sanitize_text_field( $edits[ $row ][ $k ] );
					}
				}
			}
			$title = isset( $fields['post_title'] ) ? $fields['post_title'] : '';
			if ( 'skip' === $action ) {
				$out['skipped'][] = array( 'row' => $row + 1, 'title' => $title );
				continue;
			}
			$target = 0;
			if ( 0 === strpos( $action, 'update:' ) ) {
				$target = absint( substr( $action, 7 ) );
				if ( ! $target || KA_LBI_Plugin::POST_TYPE !== get_post_type( $target ) || ! current_user_can( 'edit_post', $target ) ) {
					$out['failed'][] = array( 'row' => $row + 1, 'title' => $title, 'reason' => __( 'Target listing no longer exists', 'ka-listing-bulk-importer' ) );
					continue;
				}
			}
			$res = KA_LBI_Store::write( $fields, $status, $target, $ctx );
			if ( is_wp_error( $res['post_id'] ) ) {
				$out['failed'][] = array( 'row' => $row + 1, 'title' => $title, 'reason' => $res['post_id']->get_error_message() );
				continue;
			}
			$item = array( 'row' => $row + 1, 'id' => $res['post_id'], 'title' => get_the_title( $res['post_id'] ) );
			$out[ $target ? 'updated' : 'created' ][] = $item;
			if ( $res['photo_errors'] ) {
				$out['photo_warnings'][] = array( 'title' => $item['title'], 'errors' => $res['photo_errors'] );
			}
		}

		if ( $out['created'] || $out['updated'] ) {
			self::log( wp_list_pluck( $out['created'], 'id' ), wp_list_pluck( $out['updated'], 'id' ), $meta['source'], $batch['id'] );
		}
		self::clear();
		set_transient( 'ka_lbi_' . get_current_user_id() . '_results', $out, HOUR_IN_SECONDS );
		return $out;
	}

	/* ------------------------------------------------------------------ log & undo */

	public static function log( array $created, array $updated, $source, $batch_id = '', $user = null ) {
		$log = get_option( self::OPTION_LOG, array() );
		$log = is_array( $log ) ? $log : array();
		$log[] = array(
			'batch_id' => $batch_id ? $batch_id : wp_generate_uuid4(),
			'time'     => current_time( 'mysql' ),
			'user'     => null === $user ? get_current_user_id() : (int) $user,
			'post_ids' => array_map( 'intval', $created ),
			'updated'  => array_map( 'intval', $updated ),
			'source'   => (string) $source,
		);
		update_option( self::OPTION_LOG, array_slice( $log, -self::LOG_MAX ), false );
	}

	public static function history() {
		$log = get_option( self::OPTION_LOG, array() );
		return is_array( $log ) ? array_reverse( $log ) : array();
	}

	/** Move every listing of an import (and its KA Translate copies) to the trash. */
	public static function undo( $batch_id ) {
		$log   = get_option( self::OPTION_LOG, array() );
		$count = 0;
		foreach ( (array) $log as $i => $entry ) {
			if ( ! isset( $entry['batch_id'] ) || $entry['batch_id'] !== $batch_id ) {
				continue;
			}
			foreach ( (array) $entry['post_ids'] as $pid ) {
				if ( KA_LBI_Plugin::POST_TYPE !== get_post_type( $pid ) || ! current_user_can( 'delete_post', $pid ) ) {
					continue;
				}
				foreach ( KA_LBI_Lang::translations( $pid ) as $tid ) {
					if ( KA_LBI_Lang::is_generated( $tid ) ) {
						wp_trash_post( $tid );
					}
				}
				if ( wp_trash_post( $pid ) ) {
					$count++;
				}
			}
			unset( $log[ $i ] );
			update_option( self::OPTION_LOG, array_values( $log ), false );
			break;
		}
		return $count;
	}
}
