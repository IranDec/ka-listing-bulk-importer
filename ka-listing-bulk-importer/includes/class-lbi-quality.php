<?php
/**
 * Directory health: completeness numbers, coverage matrix, missing data, duplicates + merge.
 * Only source listings count - KA Translate copies (_kt_gen) are left out everywhere.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Quality {

	const OPTION_IGNORE = 'ka_lbi_dup_ignore';
	const CACHE         = 'ka_lbi_quality_stats';

	/** Fields checked for completeness. key => label */
	public static function checks() {
		return array(
			'photo'    => __( 'Photo', 'ka-listing-bulk-importer' ),
			'phone'    => __( 'Phone', 'ka-listing-bulk-importer' ),
			'email'    => __( 'E-mail', 'ka-listing-bulk-importer' ),
			'website'  => __( 'Website', 'ka-listing-bulk-importer' ),
			'gAddress' => __( 'Address', 'ka-listing-bulk-importer' ),
			'map'      => __( 'Map position', 'ka-listing-bulk-importer' ),
		);
	}

	/** One row per source listing with presence flags. */
	private static function rows() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_status,
				MAX(CASE WHEN m.meta_key = '_thumbnail_id' AND m.meta_value <> '' THEN 1 ELSE 0 END) AS photo,
				MAX(CASE WHEN m.meta_key = 'phone' AND m.meta_value <> '' THEN 1 ELSE 0 END) AS phone,
				MAX(CASE WHEN m.meta_key = 'email' AND m.meta_value <> '' THEN 1 ELSE 0 END) AS email,
				MAX(CASE WHEN m.meta_key = 'website' AND m.meta_value <> '' THEN 1 ELSE 0 END) AS website,
				MAX(CASE WHEN m.meta_key = 'gAddress' AND m.meta_value <> '' THEN 1 ELSE 0 END) AS gAddress,
				MAX(CASE WHEN m.meta_key = 'latitude' AND m.meta_value <> '' AND m.meta_value <> '0' THEN 1 ELSE 0 END) AS map,
				MAX(CASE WHEN m.meta_key = '_kt_gen' AND m.meta_value = '1' THEN 1 ELSE 0 END) AS gen
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('_thumbnail_id','phone','email','website','gAddress','latitude','_kt_gen')
			WHERE p.post_type = %s AND p.post_status IN ('publish','pending','draft')
			GROUP BY p.ID",
			KA_LBI_Plugin::POST_TYPE
		) );
	}

	/** Cached numbers for the dashboard. */
	public static function stats( $fresh = false ) {
		$cached = $fresh ? false : get_transient( self::CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$s = array( 'total' => 0, 'publish' => 0, 'pending' => 0, 'draft' => 0, 'complete' => 0, 'has' => array_fill_keys( array_keys( self::checks() ), 0 ), 'languages' => array(), 'time' => current_time( 'mysql' ) );
		foreach ( (array) self::rows() as $r ) {
			if ( (int) $r->gen ) {
				continue;
			}
			$s['total']++;
			$s[ $r->post_status ] = ( isset( $s[ $r->post_status ] ) ? $s[ $r->post_status ] : 0 ) + 1;
			$all = true;
			foreach ( array_keys( self::checks() ) as $k ) {
				if ( (int) $r->$k ) {
					$s['has'][ $k ]++;
				} elseif ( 'email' !== $k ) { // e-mail is rare and not required for "complete"
					$all = false;
				}
			}
			if ( $all ) {
				$s['complete']++;
			}
		}
		if ( KA_LBI_Lang::active() ) {
			global $wpdb;
			$langs = $wpdb->get_results( $wpdb->prepare(
				"SELECT t.slug, COUNT(DISTINCT p.ID) n FROM {$wpdb->posts} p
				JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
				JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = 'language'
				JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
				WHERE p.post_type = %s AND p.post_status = 'publish' GROUP BY t.slug",
				KA_LBI_Plugin::POST_TYPE
			) );
			foreach ( (array) $langs as $l ) {
				$s['languages'][ $l->slug ] = (int) $l->n;
			}
		}
		set_transient( self::CACHE, $s, 10 * MINUTE_IN_SECONDS );
		return $s;
	}

	public static function forget() {
		delete_transient( self::CACHE );
	}

	/**
	 * Listings per city (rows) and top-level category (columns), source language only.
	 *
	 * @return array cities[id=>name], cats[id=>term], cells[city][cat], totals
	 */
	public static function coverage( $max_cities = 14 ) {
		$lang = KA_LBI_Lang::import_language();
		$cats = KA_LBI_Lang::terms( KA_LBI_Plugin::TAX_CATEGORY, $lang );
		$top  = array();
		$root = array();
		foreach ( $cats as $c ) {
			$parent = $c;
			$guard  = 0;
			while ( $parent->parent && $guard++ < 5 ) {
				$p = get_term( $parent->parent, KA_LBI_Plugin::TAX_CATEGORY );
				if ( ! $p || is_wp_error( $p ) ) {
					break;
				}
				$parent = $p;
			}
			$root[ $c->term_id ]    = $parent->term_id;
			$top[ $parent->term_id ] = $parent;
		}
		global $wpdb;
		$pairs = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, tt.taxonomy, tt.term_id FROM {$wpdb->posts} p
			JOIN {$wpdb->term_relationships} r ON r.object_id = p.ID
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy IN (%s, %s)
			LEFT JOIN {$wpdb->postmeta} g ON g.post_id = p.ID AND g.meta_key = '_kt_gen'
			WHERE p.post_type = %s AND p.post_status = 'publish' AND (g.meta_value IS NULL OR g.meta_value <> '1')",
			KA_LBI_Plugin::TAX_LOCATION,
			KA_LBI_Plugin::TAX_CATEGORY,
			KA_LBI_Plugin::POST_TYPE
		) );
		$by_post = array();
		foreach ( (array) $pairs as $pr ) {
			$by_post[ $pr->ID ][ $pr->taxonomy ][] = (int) $pr->term_id;
		}
		$cells = array();
		$city_total = array();
		$cat_total  = array();
		foreach ( $by_post as $tax ) {
			if ( empty( $tax[ KA_LBI_Plugin::TAX_LOCATION ] ) ) {
				continue;
			}
			$roots = array();
			foreach ( isset( $tax[ KA_LBI_Plugin::TAX_CATEGORY ] ) ? $tax[ KA_LBI_Plugin::TAX_CATEGORY ] : array() as $cid ) {
				if ( isset( $root[ $cid ] ) ) {
					$roots[ $root[ $cid ] ] = true;
				}
			}
			foreach ( array_unique( $tax[ KA_LBI_Plugin::TAX_LOCATION ] ) as $loc ) {
				$city_total[ $loc ] = ( isset( $city_total[ $loc ] ) ? $city_total[ $loc ] : 0 ) + 1;
				foreach ( array_keys( $roots ) as $r ) {
					$cells[ $loc ][ $r ] = ( isset( $cells[ $loc ][ $r ] ) ? $cells[ $loc ][ $r ] : 0 ) + 1;
					$cat_total[ $r ]     = ( isset( $cat_total[ $r ] ) ? $cat_total[ $r ] : 0 ) + 1;
				}
			}
		}
		arsort( $city_total );
		$cities = array();
		foreach ( array_slice( $city_total, 0, $max_cities, true ) as $loc => $n ) {
			$t = get_term( $loc, KA_LBI_Plugin::TAX_LOCATION );
			if ( $t && ! is_wp_error( $t ) ) {
				$cities[ $loc ] = $t;
			}
		}
		uasort( $top, function ( $a, $b ) use ( $cat_total ) {
			return ( isset( $cat_total[ $b->term_id ] ) ? $cat_total[ $b->term_id ] : 0 ) - ( isset( $cat_total[ $a->term_id ] ) ? $cat_total[ $a->term_id ] : 0 );
		} );
		$max = 0;
		foreach ( $cells as $loc => $row ) {
			if ( isset( $cities[ $loc ] ) ) {
				$max = max( $max, max( $row ) );
			}
		}
		return array( 'cities' => $cities, 'cats' => $top, 'cells' => $cells, 'city_total' => $city_total, 'max' => $max );
	}

	/** Source listings missing a field, paginated. */
	public static function missing( $field, $page = 1, $per = 25, $location = 0 ) {
		$args = array(
			'post_type'      => KA_LBI_Plugin::POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'posts_per_page' => $per,
			'paged'          => max( 1, (int) $page ),
			'lang'           => '',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array( 'key' => '_kt_gen', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_kt_gen', 'value' => '1', 'compare' => '!=' ),
				),
			),
		);
		if ( 'photo' === $field ) {
			$args['meta_query'][] = array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' );
		} else {
			$key                  = 'map' === $field ? 'latitude' : $field;
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
				array( 'key' => $key, 'value' => '' ),
			);
		}
		if ( $location ) {
			$args['tax_query'] = array( array( 'taxonomy' => KA_LBI_Plugin::TAX_LOCATION, 'field' => 'term_id', 'terms' => array( (int) $location ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		return new WP_Query( $args );
	}

	/** Presence flags of one listing, for the little completeness dots. */
	public static function flags( $post_id ) {
		return array(
			'photo'    => has_post_thumbnail( $post_id ),
			'phone'    => '' !== (string) get_post_meta( $post_id, 'phone', true ),
			'email'    => '' !== (string) get_post_meta( $post_id, 'email', true ),
			'website'  => '' !== (string) get_post_meta( $post_id, 'website', true ),
			'gAddress' => '' !== (string) get_post_meta( $post_id, 'gAddress', true ),
			'map'      => (float) get_post_meta( $post_id, 'latitude', true ) !== 0.0,
		);
	}

	/* ------------------------------------------------------------------ duplicates */

	private static function pair_key( $a, $b ) {
		return min( $a, $b ) . '-' . max( $a, $b );
	}

	/**
	 * Groups of listings that share a Google place, phone number, website or name.
	 *
	 * @return array[] each: ids[], why[]
	 */
	public static function duplicate_groups( $limit = 60 ) {
		KA_LBI_Store::forget_index();
		$idx     = KA_LBI_Store::index();
		$ignore  = (array) get_option( self::OPTION_IGNORE, array() );
		$parent  = array();
		$reasons = array();
		$find    = function ( $x ) use ( &$parent ) {
			if ( ! isset( $parent[ $x ] ) ) {
				$parent[ $x ] = $x;
			}
			while ( $parent[ $x ] !== $x ) {
				$x = $parent[ $x ];
			}
			return $x;
		};
		$linked = function ( $a, $b ) {
			// Manually linked Polylang translations are not duplicates.
			return in_array( $b, KA_LBI_Lang::translations( $a ), true );
		};
		foreach ( array( 'place' => 'google', 'phone' => 'phone', 'host' => 'website', 'title' => 'title' ) as $bucket => $why ) {
			foreach ( $idx[ $bucket ] as $ids ) {
				$ids = array_values( array_unique( $ids ) );
				if ( count( $ids ) < 2 || count( $ids ) > 8 ) { // very large groups are generic names, not duplicates
					continue;
				}
				for ( $i = 1; $i < count( $ids ); $i++ ) {
					$a = $ids[0];
					$b = $ids[ $i ];
					if ( isset( $ignore[ self::pair_key( $a, $b ) ] ) || $linked( $a, $b ) || ( 'title' === $why && ! KA_LBI_Store::same_city( $a, $b ) ) ) {
						continue;
					}
					$ra = $find( $a );
					$rb = $find( $b );
					if ( $ra !== $rb ) {
						$parent[ $rb ] = $ra;
					}
					$reasons[ self::pair_key( $a, $b ) ][] = $why;
				}
			}
		}
		$groups = array();
		foreach ( array_keys( $parent ) as $id ) {
			$groups[ $find( $id ) ][] = $id;
		}
		$out = array();
		foreach ( $groups as $ids ) {
			$ids = array_values( array_unique( $ids ) );
			if ( count( $ids ) < 2 ) {
				continue;
			}
			sort( $ids );
			$why = array();
			foreach ( $reasons as $k => $w ) {
				list( $a, $b ) = array_map( 'intval', explode( '-', $k ) );
				if ( in_array( $a, $ids, true ) && in_array( $b, $ids, true ) ) {
					$why = array_merge( $why, $w );
				}
			}
			$why   = array_values( array_unique( $why ) );
			$score = ( in_array( 'google', $why, true ) ? 8 : 0 ) + ( in_array( 'phone', $why, true ) ? 4 : 0 ) + ( in_array( 'website', $why, true ) ? 2 : 0 ) + ( in_array( 'title', $why, true ) ? 1 : 0 );
			$out[] = array( 'ids' => $ids, 'why' => $why, 'score' => $score );
		}
		usort( $out, function ( $a, $b ) {
			return $b['score'] - $a['score'];
		} );
		return array_slice( $out, 0, $limit );
	}

	/** "These are different businesses" - hide the group in future. */
	public static function handle_ignore() {
		KA_LBI_Plugin::guard();
		$ids    = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$ignore = (array) get_option( self::OPTION_IGNORE, array() );
		foreach ( $ids as $a ) {
			foreach ( $ids as $b ) {
				if ( $a < $b ) {
					$ignore[ self::pair_key( $a, $b ) ] = 1;
				}
			}
		}
		update_option( self::OPTION_IGNORE, array_slice( $ignore, -5000, null, true ), false );
		KA_LBI_Plugin::back( 'ka-lbi-quality', __( 'Marked as different businesses.', 'ka-listing-bulk-importer' ), 'success', array( 'tab' => 'duplicates' ) );
	}

	/**
	 * Keep one listing, copy everything it is missing from the others, move the others
	 * (and their KA Translate copies) to the trash. Trash keeps it reversible.
	 */
	public static function handle_merge() {
		KA_LBI_Plugin::guard();
		$ids  = isset( $_POST['ids'] ) ? array_map( 'absint', (array) $_POST['ids'] ) : array();
		$keep = isset( $_POST['keep'] ) ? absint( $_POST['keep'] ) : 0;
		if ( ! $keep || ! in_array( $keep, $ids, true ) || count( $ids ) < 2 ) {
			KA_LBI_Plugin::fail( __( 'Choose which listing to keep.', 'ka-listing-bulk-importer' ), 'ka-lbi-quality' );
		}
		foreach ( $ids as $id ) {
			if ( KA_LBI_Plugin::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
				KA_LBI_Plugin::fail( __( 'One of these listings cannot be edited.', 'ka-listing-bulk-importer' ), 'ka-lbi-quality' );
			}
		}
		$merged = 0;
		foreach ( array_diff( $ids, array( $keep ) ) as $other ) {
			foreach ( array_merge( array_keys( KA_LBI_Store::meta_fields() ), array( '_ka_lbi_place_id' ) ) as $key ) {
				$v = get_post_meta( $other, $key, true );
				if ( '' !== (string) $v && '' === (string) get_post_meta( $keep, $key, true ) ) {
					update_post_meta( $keep, $key, $v );
				}
			}
			if ( ! has_post_thumbnail( $keep ) && has_post_thumbnail( $other ) ) {
				set_post_thumbnail( $keep, get_post_thumbnail_id( $other ) );
			}
			if ( '' === trim( get_post_field( 'post_content', $keep ) ) && '' !== trim( get_post_field( 'post_content', $other ) ) ) {
				wp_update_post( array( 'ID' => $keep, 'post_content' => get_post_field( 'post_content', $other ) ) );
			}
			foreach ( array( 'business_hours', 'business_logo' ) as $opt ) {
				$v = KA_LBI_Store::get_option( $other, $opt );
				if ( $v && ! KA_LBI_Store::get_option( $keep, $opt ) ) {
					$o         = get_post_meta( $keep, 'lp_listingpro_options', true );
					$o         = is_array( $o ) ? $o : array();
					$o[ $opt ] = $v;
					update_post_meta( $keep, 'lp_listingpro_options', $o );
				}
			}
			// Categories and locations of both, in the kept listing's language.
			$lang = KA_LBI_Lang::post_language( $keep );
			foreach ( array( KA_LBI_Plugin::TAX_CATEGORY, KA_LBI_Plugin::TAX_LOCATION ) as $tax ) {
				$add = array();
				foreach ( wp_get_object_terms( $other, $tax, array( 'fields' => 'ids' ) ) as $tid ) {
					$add[] = KA_LBI_Lang::term_in( $tid, $lang );
				}
				if ( $add ) {
					wp_set_object_terms( $keep, array_map( 'intval', $add ), $tax, true );
				}
			}
			foreach ( KA_LBI_Lang::translations( $other ) as $tid ) {
				if ( KA_LBI_Lang::is_generated( $tid ) ) {
					wp_trash_post( $tid );
				}
			}
			update_post_meta( $other, '_ka_lbi_merged_into', $keep );
			wp_trash_post( $other );
			$merged++;
		}
		KA_LBI_Store::ensure_plan_meta_placeholder( $keep );
		KA_LBI_Store::sync_options_meta( $keep );
		KA_LBI_Lang::propagate( $keep );
		self::forget();
		/* translators: 1: number of merged listings, 2: kept listing title */
		KA_LBI_Plugin::back( 'ka-lbi-quality', sprintf( __( '%1$d listing(s) merged into "%2$s" and moved to the trash.', 'ka-listing-bulk-importer' ), $merged, get_the_title( $keep ) ), 'success', array( 'tab' => 'duplicates' ) );
	}
}
