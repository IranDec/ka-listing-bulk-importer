<?php
/**
 * Review queue: every pending listing (imported or submitted), approve or reject in bulk.
 * Approving publishes; KA Translate then creates the other language versions.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Review {

	const PER_PAGE = 25;

	public static function count() {
		$q = new WP_Query( self::args( array( 'posts_per_page' => 1, 'fields' => 'ids' ) ) );
		return (int) $q->found_posts;
	}

	public static function args( array $extra = array() ) {
		return array_merge( array(
			'post_type'      => KA_LBI_Plugin::POST_TYPE,
			'post_status'    => 'pending',
			'lang'           => '',
			'orderby'        => 'date',
			'order'          => 'DESC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'OR',
				array( 'key' => '_kt_gen', 'compare' => 'NOT EXISTS' ),
				array( 'key' => '_kt_gen', 'value' => '1', 'compare' => '!=' ),
			),
		), $extra );
	}

	/** Filtered page of the queue. */
	public static function query( $source, $location, $page ) {
		$args = self::args( array( 'posts_per_page' => self::PER_PAGE, 'paged' => max( 1, (int) $page ) ) );
		if ( $source ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				$args['meta_query'],
				'manual' === $source
					? array( 'key' => '_ka_lbi_source', 'compare' => 'NOT EXISTS' )
					: ( 'ai' === $source
						? array( 'key' => '_ka_lbi_source', 'value' => array( 'claude', 'gemini', 'openai' ), 'compare' => 'IN' )
						: array( 'key' => '_ka_lbi_source', 'value' => $source ) ),
			);
		}
		if ( $location ) {
			$args['tax_query'] = array( array( 'taxonomy' => KA_LBI_Plugin::TAX_LOCATION, 'field' => 'term_id', 'terms' => array( (int) $location ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return new WP_Query( $args );
	}

	public static function source_label( $post_id ) {
		$src = (string) get_post_meta( $post_id, '_ka_lbi_source', true );
		if ( '' === $src ) {
			return array( __( 'Submitted', 'ka-listing-bulk-importer' ), 'muted' );
		}
		if ( 'csv' === $src ) {
			return array( 'CSV', 'info' );
		}
		return array( KA_LBI_Providers::label( $src ), KA_LBI_Providers::is_ai( $src ) ? 'warn' : 'ok' );
	}

	public static function handle() {
		KA_LBI_Plugin::guard();
		$do   = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		$ids  = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$one  = isset( $_POST['one'] ) ? absint( $_POST['one'] ) : 0;
		$args = array();
		foreach ( array( 'source', 'location', 'paged' ) as $k ) {
			if ( ! empty( $_POST[ 'f_' . $k ] ) ) {
				$args[ $k ] = sanitize_text_field( wp_unslash( $_POST[ 'f_' . $k ] ) );
			}
		}
		if ( $one ) {
			$ids = array( $one );
		}
		$ids = array_filter( $ids, function ( $id ) {
			return KA_LBI_Plugin::POST_TYPE === get_post_type( $id ) && current_user_can( 'edit_post', $id );
		} );
		if ( ! $ids ) {
			KA_LBI_Plugin::back( 'ka-lbi-review', __( 'Nothing selected.', 'ka-listing-bulk-importer' ), 'info', $args );
		}
		$n = 0;
		switch ( $do ) {
			case 'approve':
				foreach ( $ids as $id ) {
					KA_LBI_Store::ensure_plan_meta_placeholder( $id );
					if ( ! is_wp_error( wp_update_post( array( 'ID' => $id, 'post_status' => 'publish' ), true ) ) ) {
						$n++;
					}
				}
				/* translators: %d: number of listings */
				$msg = sprintf( _n( '%d listing published.', '%d listings published.', $n, 'ka-listing-bulk-importer' ), $n );
				if ( $n && KA_LBI_Lang::translator_available() && KA_LBI_Settings::get( 'translate' ) ) {
					$msg .= ' ' . __( 'KA Translate creates the other language versions in the background.', 'ka-listing-bulk-importer' );
				}
				break;
			case 'reject':
				foreach ( $ids as $id ) {
					if ( current_user_can( 'delete_post', $id ) && wp_trash_post( $id ) ) {
						$n++;
					}
				}
				/* translators: %d: number of listings */
				$msg = sprintf( _n( '%d listing moved to the trash.', '%d listings moved to the trash.', $n, 'ka-listing-bulk-importer' ), $n );
				break;
			case 'enrich':
				$job = KA_LBI_Jobs::start( 'enrich', array_values( $ids ), array_merge( KA_LBI_Enrich::default_options(), array( 'label' => __( 'Review queue selection', 'ka-listing-bulk-importer' ) ) ) );
				$msg = is_wp_error( $job ) ? $job->get_error_message() : __( 'Enrichment started - see the Enrich tab for progress.', 'ka-listing-bulk-importer' );
				break;
			default:
				$msg = __( 'Choose an action.', 'ka-listing-bulk-importer' );
		}
		KA_LBI_Quality::forget();
		KA_LBI_Plugin::back( 'ka-lbi-review', $msg, 'success', $args );
	}
}
