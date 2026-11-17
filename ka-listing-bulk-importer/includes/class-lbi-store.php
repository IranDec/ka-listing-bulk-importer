<?php
/**
 * Writing listings the way ListingPro expects them, and finding existing ones.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Store {

	/** Importable fields. key => [label, type, required] */
	public static function core_fields() {
		return array(
			'post_title'   => array( __( 'Title', 'ka-listing-bulk-importer' ), 'text', true ),
			'post_content' => array( __( 'Description', 'ka-listing-bulk-importer' ), 'richtext', false ),
			'category'     => array( __( 'Category', 'ka-listing-bulk-importer' ), 'term', false ),
			'location'     => array( __( 'City / location', 'ka-listing-bulk-importer' ), 'term', false ),
			'tags'         => array( __( 'Tags (comma separated)', 'ka-listing-bulk-importer' ), 'term_list', false ),
		);
	}

	/** ListingPro meta keys (also mirrored into lp_listingpro_options). */
	public static function meta_fields() {
		return array(
			'gAddress'     => array( __( 'Address', 'ka-listing-bulk-importer' ), 'text' ),
			'latitude'     => array( __( 'Latitude', 'ka-listing-bulk-importer' ), 'float' ),
			'longitude'    => array( __( 'Longitude', 'ka-listing-bulk-importer' ), 'float' ),
			'phone'        => array( __( 'Phone', 'ka-listing-bulk-importer' ), 'phone' ),
			'whatsapp'     => array( __( 'WhatsApp', 'ka-listing-bulk-importer' ), 'phone' ),
			'email'        => array( __( 'E-mail', 'ka-listing-bulk-importer' ), 'email' ),
			'website'      => array( __( 'Website', 'ka-listing-bulk-importer' ), 'url' ),
			'facebook'     => array( __( 'Facebook', 'ka-listing-bulk-importer' ), 'url' ),
			'instagram'    => array( __( 'Instagram', 'ka-listing-bulk-importer' ), 'url' ),
			'linkedin'     => array( __( 'LinkedIn', 'ka-listing-bulk-importer' ), 'url' ),
			'twitter'      => array( __( 'X / Twitter', 'ka-listing-bulk-importer' ), 'url' ),
			'youtube'      => array( __( 'YouTube channel', 'ka-listing-bulk-importer' ), 'url' ),
			'video'        => array( __( 'YouTube video', 'ka-listing-bulk-importer' ), 'url' ),
			'tagline_text' => array( __( 'Tagline', 'ka-listing-bulk-importer' ), 'text' ),
		);
	}

	public static function image_fields() {
		return array(
			'featured_photo' => array( __( 'Photo (featured image)', 'ka-listing-bulk-importer' ), 'image' ),
			'gallery_photos' => array( __( 'Gallery photos (separate with |)', 'ka-listing-bulk-importer' ), 'image_list' ),
		);
	}

	public static function importable_fields() {
		return array_merge( self::core_fields(), self::meta_fields(), self::image_fields() );
	}

	/**
	 * Create or update one listing.
	 *
	 * Extra keys understood in $fields: business_hours (array day => [open, close]),
	 * place_id (Google), source_url, rating.
	 *
	 * @param array $ctx lang, source (csv|google|claude|...), batch, create_locations
	 * @return array{post_id:int|WP_Error, photo_errors:string[]}
	 */
	public static function write( array $fields, $status, $existing_id = 0, array $ctx = array() ) {
		$title = isset( $fields['post_title'] ) ? sanitize_text_field( $fields['post_title'] ) : '';
		if ( '' === $title ) {
			return array( 'post_id' => new WP_Error( 'ka_lbi_no_title', __( 'Missing title', 'ka-listing-bulk-importer' ) ), 'photo_errors' => array() );
		}
		$status = in_array( $status, array( 'pending', 'draft', 'publish' ), true ) ? $status : 'pending';
		$lang   = isset( $ctx['lang'] ) ? (string) $ctx['lang'] : KA_LBI_Lang::import_language();

		$postarr = array(
			'post_type'   => KA_LBI_Plugin::POST_TYPE,
			'post_title'  => $title,
		);
		if ( isset( $fields['post_content'] ) && '' !== trim( (string) $fields['post_content'] ) ) {
			$postarr['post_content'] = wp_kses_post( $fields['post_content'] );
		}

		if ( $existing_id ) {
			$postarr['ID'] = (int) $existing_id;
			$post_id       = wp_update_post( $postarr, true );
		} else {
			$postarr['post_status'] = $status;
			// Language must be known before the first save so terms and KA Translate see it.
			add_action( 'save_post_' . KA_LBI_Plugin::POST_TYPE, $set_lang = function ( $id ) use ( $lang ) {
				KA_LBI_Lang::set_post_language( $id, $lang );
			}, 1 );
			$post_id = wp_insert_post( $postarr, true );
			remove_action( 'save_post_' . KA_LBI_Plugin::POST_TYPE, $set_lang, 1 );
		}
		if ( is_wp_error( $post_id ) ) {
			return array( 'post_id' => $post_id, 'photo_errors' => array() );
		}

		self::ensure_plan_meta_placeholder( $post_id );

		if ( ! $existing_id ) {
			update_post_meta( $post_id, '_ka_lbi_source', sanitize_key( isset( $ctx['source'] ) ? $ctx['source'] : 'csv' ) );
			if ( ! empty( $ctx['batch'] ) ) {
				update_post_meta( $post_id, '_ka_lbi_batch', sanitize_text_field( $ctx['batch'] ) );
			}
		}
		if ( ! empty( $fields['place_id'] ) ) {
			update_post_meta( $post_id, '_ka_lbi_place_id', sanitize_text_field( $fields['place_id'] ) );
		}
		if ( isset( $fields['rating'] ) && is_numeric( $fields['rating'] ) ) {
			update_post_meta( $post_id, '_ka_lbi_google_rating', array( 'rating' => (float) $fields['rating'], 'count' => isset( $fields['rating_count'] ) ? (int) $fields['rating_count'] : 0 ) );
		}

		$post_lang = $existing_id ? KA_LBI_Lang::post_language( $post_id ) : $lang;
		$create    = ! empty( $ctx['create_locations'] );
		if ( ! empty( $fields['category'] ) ) {
			self::set_terms( $post_id, KA_LBI_Plugin::TAX_CATEGORY, $fields['category'], $post_lang, false );
		}
		if ( ! empty( $fields['location'] ) ) {
			self::set_terms( $post_id, KA_LBI_Plugin::TAX_LOCATION, $fields['location'], $post_lang, $create );
		}
		if ( ! empty( $fields['tags'] ) ) {
			self::set_terms( $post_id, KA_LBI_Plugin::TAX_TAGS, $fields['tags'], $post_lang, false );
		}

		foreach ( self::meta_fields() as $key => $def ) {
			if ( ! isset( $fields[ $key ] ) || '' === $fields[ $key ] ) {
				continue;
			}
			$value = self::sanitize( $fields[ $key ], $def[1] );
			if ( null !== $value ) {
				update_post_meta( $post_id, $key, $value );
			}
		}
		if ( ! empty( $fields['business_hours'] ) && is_array( $fields['business_hours'] ) ) {
			self::set_option( $post_id, 'business_hours', self::clean_hours( $fields['business_hours'] ) );
		}
		self::sync_options_meta( $post_id );

		$photo_errors = array();
		if ( ! empty( $fields['featured_photo'] ) && ! ( $existing_id && has_post_thumbnail( $post_id ) && ! empty( $ctx['keep_photo'] ) ) ) {
			$aid = KA_LBI_Media::import( $fields['featured_photo'], $post_id );
			if ( is_wp_error( $aid ) ) {
				$photo_errors[] = self::short( $fields['featured_photo'] ) . ': ' . $aid->get_error_message();
			} else {
				set_post_thumbnail( $post_id, $aid );
			}
		}
		if ( ! empty( $fields['gallery_photos'] ) ) {
			$ids = array();
			foreach ( array_filter( array_map( 'trim', preg_split( '/[|]/', (string) $fields['gallery_photos'] ) ) ) as $u ) {
				$aid = KA_LBI_Media::import( $u, $post_id );
				if ( is_wp_error( $aid ) ) {
					$photo_errors[] = self::short( $u ) . ': ' . $aid->get_error_message();
				} else {
					$ids[] = $aid;
				}
			}
			if ( $ids ) {
				update_post_meta( $post_id, 'gallery_image_ids', implode( ',', $ids ) );
			}
		}

		if ( $existing_id ) {
			KA_LBI_Lang::propagate( $post_id );
		} elseif ( 'publish' === $status ) {
			KA_LBI_Lang::request_translations( $post_id );
		}
		self::forget_index();

		return array( 'post_id' => (int) $post_id, 'photo_errors' => $photo_errors );
	}

	private static function short( $value ) {
		$value = (string) $value;
		if ( 0 === strpos( $value, 'gplace:' ) ) {
			return __( 'Google photo', 'ka-listing-bulk-importer' );
		}
		return mb_strimwidth( $value, 0, 70, '…' );
	}

	public static function sanitize( $value, $type ) {
		switch ( $type ) {
			case 'email':
				$value = sanitize_email( $value );
				return is_email( $value ) ? $value : null;
			case 'url':
				$value = trim( (string) $value );
				if ( '' !== $value && ! preg_match( '#^https?://#i', $value ) && preg_match( '/^[\w.-]+\.[a-z]{2,}/i', $value ) ) {
					$value = 'https://' . $value;
				}
				$value = esc_url_raw( $value, array( 'http', 'https' ) );
				return $value ? $value : null;
			case 'float':
				$value = str_replace( ',', '.', trim( (string) $value ) );
				return is_numeric( $value ) ? (float) $value : null;
			default:
				return sanitize_text_field( $value );
		}
	}

	/** Assign terms by name, slug or id in the listing's language; locations may be created. */
	private static function set_terms( $post_id, $taxonomy, $value, $lang, $create ) {
		$ids = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', (string) $value ) ) ) as $name ) {
			$term = KA_LBI_Lang::find_term( $taxonomy, $name, $lang );
			if ( ! $term && $create && ! ctype_digit( $name ) ) {
				$term = KA_LBI_Lang::create_term( $taxonomy, $name, $lang );
			}
			if ( $term ) {
				$ids[] = (int) $term->term_id;
			}
		}
		if ( $ids ) {
			wp_set_object_terms( $post_id, $ids, $taxonomy, false );
		}
	}

	/**
	 * ListingPro's single-listing template indexes lp_listingpro_options as an array without a
	 * guard; on PHP 8 an unset value (empty string) is a fatal TypeError. Seed it with an array.
	 */
	public static function ensure_plan_meta_placeholder( $post_id ) {
		if ( '' === get_post_meta( $post_id, 'lp_listingpro_options', true ) ) {
			update_post_meta( $post_id, 'lp_listingpro_options', array() );
		}
	}

	private static function set_option( $post_id, $key, $value ) {
		$options         = get_post_meta( $post_id, 'lp_listingpro_options', true );
		$options         = is_array( $options ) ? $options : array();
		$options[ $key ] = $value;
		update_post_meta( $post_id, 'lp_listingpro_options', $options );
	}

	public static function get_option( $post_id, $key ) {
		$options = get_post_meta( $post_id, 'lp_listingpro_options', true );
		return is_array( $options ) && isset( $options[ $key ] ) ? $options[ $key ] : '';
	}

	/**
	 * ListingPro's edit screen reads address, phone, website, ... from the combined
	 * lp_listingpro_options array, the frontend from the single meta keys. Keep both equal.
	 */
	public static function sync_options_meta( $post_id ) {
		$options = get_post_meta( $post_id, 'lp_listingpro_options', true );
		$options = is_array( $options ) ? $options : array();
		$changed = false;
		foreach ( array_keys( self::meta_fields() ) as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' === $value || null === $value || false === $value ) {
				continue;
			}
			if ( ! array_key_exists( $key, $options ) || $options[ $key ] !== $value ) {
				$options[ $key ] = $value;
				$changed         = true;
			}
		}
		if ( $changed ) {
			update_post_meta( $post_id, 'lp_listingpro_options', $options );
		}
		return $changed;
	}

	/** ListingPro hours: [ 'Monday' => [ 'open' => '09:00am', 'close' => '05:00pm' ] ] */
	public static function clean_hours( array $hours ) {
		$days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
		$out  = array();
		foreach ( $days as $d ) {
			if ( empty( $hours[ $d ]['open'] ) || empty( $hours[ $d ]['close'] ) ) {
				continue;
			}
			$out[ $d ] = array(
				'open'  => sanitize_text_field( $hours[ $d ]['open'] ),
				'close' => sanitize_text_field( $hours[ $d ]['close'] ),
			);
		}
		return $out;
	}

	/* ------------------------------------------------------------------ duplicates */

	private static $index = null;

	public static function forget_index() {
		self::$index = null;
	}

	/**
	 * One-pass index of every listing (except trash and KA Translate copies) by
	 * normalized title, phone digits, website host and Google place id.
	 */
	public static function index() {
		if ( null !== self::$index ) {
			return self::$index;
		}
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title, p.post_status,
				MAX(CASE WHEN m.meta_key = 'phone' THEN m.meta_value END) AS phone,
				MAX(CASE WHEN m.meta_key = 'website' THEN m.meta_value END) AS website,
				MAX(CASE WHEN m.meta_key = '_ka_lbi_place_id' THEN m.meta_value END) AS place,
				MAX(CASE WHEN m.meta_key = '_kt_gen' THEN m.meta_value END) AS gen
			FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key IN ('phone','website','_ka_lbi_place_id','_kt_gen')
			WHERE p.post_type = %s AND p.post_status NOT IN ('trash','auto-draft','inherit')
			GROUP BY p.ID",
			KA_LBI_Plugin::POST_TYPE
		) );
		$idx = array( 'title' => array(), 'phone' => array(), 'host' => array(), 'place' => array(), 'posts' => array() );
		foreach ( (array) $rows as $r ) {
			if ( '1' === (string) $r->gen ) {
				continue;
			}
			$id                  = (int) $r->ID;
			$idx['posts'][ $id ] = array( 'title' => $r->post_title, 'status' => $r->post_status, 'phone' => (string) $r->phone );
			foreach ( self::title_keys( $r->post_title ) as $k ) {
				$idx['title'][ $k ][] = $id;
			}
			$p = self::phone_key( $r->phone );
			if ( $p ) {
				$idx['phone'][ $p ][] = $id;
			}
			$h = self::host_key( $r->website );
			if ( $h ) {
				$idx['host'][ $h ][] = $id;
			}
			if ( $r->place ) {
				$idx['place'][ $r->place ][] = $id;
			}
		}
		// City of every listing, normalized to the default language so fa/de/en terms compare equal.
		$idx['locs'] = array();
		$locs        = $wpdb->get_results( $wpdb->prepare(
			"SELECT r.object_id, tt.term_id FROM {$wpdb->term_relationships} r
			JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = r.term_taxonomy_id AND tt.taxonomy = %s",
			KA_LBI_Plugin::TAX_LOCATION
		) );
		$def = KA_LBI_Lang::default_language();
		foreach ( (array) $locs as $l ) {
			if ( isset( $idx['posts'][ $l->object_id ] ) ) {
				$idx['locs'][ (int) $l->object_id ][] = KA_LBI_Lang::term_in( (int) $l->term_id, $def );
			}
		}
		self::$index = $idx;
		return $idx;
	}

	/** True when two listings share a city, or when one of them has none. */
	public static function same_city( $a, $b ) {
		$idx = self::index();
		$la  = isset( $idx['locs'][ $a ] ) ? $idx['locs'][ $a ] : array();
		$lb  = isset( $idx['locs'][ $b ] ) ? $idx['locs'][ $b ] : array();
		return ! $la || ! $lb || (bool) array_intersect( $la, $lb );
	}

	/**
	 * Comparable title keys. Bilingual titles like "آموزشگاه رانندگی | Fahrschule Persepolis"
	 * produce a key for each half, so either spelling matches.
	 */
	public static function title_keys( $title ) {
		$title = html_entity_decode( wp_strip_all_tags( (string) $title ), ENT_QUOTES, 'UTF-8' );
		$parts = array_merge( array( $title ), preg_split( '/\s[|–—-]\s/u', $title ) );
		$keys  = array();
		foreach ( $parts as $p ) {
			$k = KA_LBI_Lang::norm( $p );
			$k = preg_replace( '/[^\p{L}\p{N}]+/u', '', $k );
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $k ) >= 4 : strlen( $k ) >= 4 ) {
				$keys[ $k ] = true;
			}
		}
		return array_keys( $keys );
	}

	/** Last 9 digits of a phone number - ignores +49 / 0 prefixes and formatting. */
	public static function phone_key( $phone ) {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( $d ) >= 7 ? substr( $d, -9 ) : '';
	}

	public static function host_key( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . $url;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$host = preg_replace( '/^www\./', '', $host );
		// Shared platforms say nothing about the business itself.
		if ( '' === $host || preg_match( '/(^|\.)(facebook|instagram|google|goo\.gl|linktr\.ee|wa\.me|whatsapp|youtube|tiktok|linkedin|twitter|x\.com|t\.me|business\.site|wixsite|jimdo|blogspot)/', $host ) ) {
			return '';
		}
		return $host;
	}

	/**
	 * Existing listings that look like the same business.
	 *
	 * @return array[] each: id, title, status, edit, phone, why
	 */
	public static function find_duplicates( array $fields, $exclude_id = 0 ) {
		$idx  = self::index();
		$hits = array();
		$add  = function ( $ids, $why ) use ( &$hits, $exclude_id ) {
			foreach ( (array) $ids as $id ) {
				if ( (int) $id === (int) $exclude_id ) {
					continue;
				}
				$hits[ $id ][] = $why;
			}
		};
		if ( ! empty( $fields['place_id'] ) && isset( $idx['place'][ $fields['place_id'] ] ) ) {
			$add( $idx['place'][ $fields['place_id'] ], 'google' );
		}
		foreach ( self::title_keys( isset( $fields['post_title'] ) ? $fields['post_title'] : '' ) as $k ) {
			if ( isset( $idx['title'][ $k ] ) ) {
				$add( $idx['title'][ $k ], 'title' );
			}
		}
		$p = self::phone_key( isset( $fields['phone'] ) ? $fields['phone'] : '' );
		if ( $p && isset( $idx['phone'][ $p ] ) ) {
			$add( $idx['phone'][ $p ], 'phone' );
		}
		$h = self::host_key( isset( $fields['website'] ) ? $fields['website'] : '' );
		if ( $h && isset( $idx['host'][ $h ] ) ) {
			$add( $idx['host'][ $h ], 'website' );
		}

		// A name alone in another city is usually a branch of a chain, not the same business.
		$city = '';
		if ( ! empty( $fields['location'] ) ) {
			$t = KA_LBI_Lang::find_term( KA_LBI_Plugin::TAX_LOCATION, explode( ',', (string) $fields['location'] )[0] );
			$city = $t ? KA_LBI_Lang::term_in( $t->term_id, KA_LBI_Lang::default_language() ) : '';
		}
		$out = array();
		foreach ( $hits as $id => $why ) {
			if ( array( 'title' ) === array_values( array_unique( $why ) ) && $city && ! empty( $idx['locs'][ $id ] ) && ! in_array( $city, $idx['locs'][ $id ], true ) ) {
				continue;
			}
			$post  = $idx['posts'][ $id ];
			$out[] = array(
				'id'     => (int) $id,
				'title'  => $post['title'],
				'status' => $post['status'],
				'phone'  => $post['phone'],
				'edit'   => get_edit_post_link( $id, 'raw' ),
				'why'    => array_values( array_unique( $why ) ),
			);
		}
		// Strongest evidence first.
		usort( $out, function ( $a, $b ) {
			return count( $b['why'] ) - count( $a['why'] );
		} );
		return array_slice( $out, 0, 5 );
	}

	/** Listings already tagged with a location (and optionally a category). */
	public static function existing_for_place( $location_term, $category_term = null ) {
		$tax_query = array( array( 'taxonomy' => KA_LBI_Plugin::TAX_LOCATION, 'field' => 'term_id', 'terms' => array( $location_term->term_id ) ) );
		if ( $category_term ) {
			$tax_query['relation'] = 'AND';
			$tax_query[]           = array( 'taxonomy' => KA_LBI_Plugin::TAX_CATEGORY, 'field' => 'term_id', 'terms' => array( $category_term->term_id ) );
		}
		$q = new WP_Query( array(
			'post_type'      => KA_LBI_Plugin::POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'draft', 'private' ),
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery
			'lang'           => '',
		) );
		$rows = array();
		foreach ( $q->posts as $post ) {
			if ( KA_LBI_Lang::is_generated( $post->ID ) ) {
				continue;
			}
			$rows[] = array(
				'id'     => $post->ID,
				'title'  => get_the_title( $post ),
				'status' => $post->post_status,
				'edit'   => get_edit_post_link( $post->ID, 'raw' ),
				'phone'  => get_post_meta( $post->ID, 'phone', true ),
				'thumb'  => get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
			);
		}
		return $rows;
	}

	/** Fix every listing: plan placeholder + edit-screen field sync (3.x one-click repair). */
	public static function repair_all() {
		$ids    = get_posts( array( 'post_type' => KA_LBI_Plugin::POST_TYPE, 'post_status' => array( 'publish', 'pending', 'draft', 'private', 'future' ), 'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true, 'lang' => '' ) );
		$fixed  = 0;
		$synced = 0;
		foreach ( $ids as $id ) {
			if ( '' === get_post_meta( $id, 'lp_listingpro_options', true ) ) {
				update_post_meta( $id, 'lp_listingpro_options', array() );
				$fixed++;
			}
			if ( self::sync_options_meta( $id ) ) {
				$synced++;
			}
		}
		return array( 'total' => count( $ids ), 'fixed' => $fixed, 'synced' => $synced );
	}
}
