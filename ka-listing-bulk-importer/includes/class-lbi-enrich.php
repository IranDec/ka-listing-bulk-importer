<?php
/**
 * Enrichment: fill empty fields of existing listings from the business's own website
 * (home page + Impressum/contact page) and, optionally, Google Places.
 *
 * Never overwrites a value that is already set. Results are copied to the KA Translate
 * language versions of the listing.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Enrich {

	const SYNC_CAP   = 8;      // listings handled right away; more becomes a background job
	const MAX_BYTES  = 900000; // per page
	const META_DONE  = '_ka_lbi_enriched';
	const META_NOTES = '_ka_lbi_enrich_result';

	/** What can be filled. key => label */
	public static function targets() {
		return array(
			'email'   => __( 'E-mail', 'ka-listing-bulk-importer' ),
			'phone'   => __( 'Phone', 'ka-listing-bulk-importer' ),
			'socials' => __( 'Social links & WhatsApp', 'ka-listing-bulk-importer' ),
			'photo'   => __( 'Photo', 'ka-listing-bulk-importer' ),
			'logo'    => __( 'Logo', 'ka-listing-bulk-importer' ),
			'hours'   => __( 'Opening hours (Google)', 'ka-listing-bulk-importer' ),
			'address' => __( 'Address & map position (Google)', 'ka-listing-bulk-importer' ),
			'website' => __( 'Website (Google)', 'ka-listing-bulk-importer' ),
			'tagline' => __( 'Tagline from the site description', 'ka-listing-bulk-importer' ),
		);
	}

	/* ------------------------------------------------------------------ selection */

	/**
	 * Source-language listings in scope that miss at least one wanted field.
	 *
	 * @param array $scope location (term id), category (term id), missing (key), recheck (bool), sources
	 */
	public static function select( array $scope, $limit = -1 ) {
		$args = array(
			'post_type'      => KA_LBI_Plugin::POST_TYPE,
			'post_status'    => array( 'publish', 'pending', 'draft' ),
			'posts_per_page' => $limit,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'lang'           => '',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery
				'relation' => 'AND',
				array(
					'relation' => 'OR',
					array( 'key' => '_kt_gen', 'compare' => 'NOT EXISTS' ),
					array( 'key' => '_kt_gen', 'value' => '1', 'compare' => '!=' ),
				),
			),
		);
		$tax = array();
		if ( ! empty( $scope['location'] ) ) {
			$tax[] = array( 'taxonomy' => KA_LBI_Plugin::TAX_LOCATION, 'field' => 'term_id', 'terms' => array( (int) $scope['location'] ) );
		}
		if ( ! empty( $scope['category'] ) ) {
			$tax[] = array( 'taxonomy' => KA_LBI_Plugin::TAX_CATEGORY, 'field' => 'term_id', 'terms' => array( (int) $scope['category'] ), 'include_children' => true );
		}
		if ( $tax ) {
			$args['tax_query'] = count( $tax ) > 1 ? array_merge( array( 'relation' => 'AND' ), $tax ) : $tax; // phpcs:ignore WordPress.DB.SlowDBQuery
		}
		$sources = isset( $scope['sources'] ) ? (array) $scope['sources'] : array( 'website' );
		if ( ! in_array( 'google', $sources, true ) ) {
			$args['meta_query'][] = array( 'key' => 'website', 'value' => '', 'compare' => '!=' );
		}
		$missing = isset( $scope['missing'] ) ? $scope['missing'] : '';
		$empty   = function ( $key ) {
			return array(
				'relation' => 'OR',
				array( 'key' => $key, 'compare' => 'NOT EXISTS' ),
				array( 'key' => $key, 'value' => '' ),
			);
		};
		if ( 'photo' === $missing ) {
			$args['meta_query'][] = array( 'key' => '_thumbnail_id', 'compare' => 'NOT EXISTS' );
		} elseif ( in_array( $missing, array( 'email', 'phone', 'website', 'gAddress' ), true ) ) {
			$args['meta_query'][] = $empty( $missing );
		}
		if ( empty( $scope['recheck'] ) && (int) KA_LBI_Settings::get( 'enrich_days' ) > 0 ) {
			$args['meta_query'][] = array(
				'relation' => 'OR',
				array( 'key' => self::META_DONE, 'compare' => 'NOT EXISTS' ),
				array( 'key' => self::META_DONE, 'value' => time() - DAY_IN_SECONDS * (int) KA_LBI_Settings::get( 'enrich_days' ), 'compare' => '<', 'type' => 'NUMERIC' ),
			);
		}
		$q = new WP_Query( $args );
		return array_map( 'intval', $q->posts );
	}

	/* ------------------------------------------------------------------ handlers */

	private static function read_options() {
		$what    = isset( $_POST['what'] ) ? array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['what'] ) ), array_keys( self::targets() ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		$sources = isset( $_POST['sources'] ) ? array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['sources'] ) ), array( 'website', 'google' ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification
		if ( in_array( 'google', $sources, true ) && '' === KA_LBI_Google::key() ) {
			$sources = array_diff( $sources, array( 'google' ) );
		}
		return array( array_values( $what ), array_values( $sources ) );
	}

	public static function handle_start() {
		KA_LBI_Plugin::guard();
		list( $what, $sources ) = self::read_options();
		if ( ! $what || ! $sources ) {
			KA_LBI_Plugin::fail( __( 'Choose at least one thing to fill and one source.', 'ka-listing-bulk-importer' ), 'ka-lbi-photos' );
		}
		$scope = array(
			'location' => isset( $_POST['location'] ) ? absint( $_POST['location'] ) : 0,
			'category' => isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0,
			'missing'  => isset( $_POST['missing'] ) ? sanitize_key( wp_unslash( $_POST['missing'] ) ) : '',
			'recheck'  => ! empty( $_POST['recheck'] ),
			'sources'  => $sources,
		);
		if ( 'gaddress' === $scope['missing'] ) {
			$scope['missing'] = 'gAddress';
		}
		$ids = self::select( $scope );
		if ( ! $ids ) {
			KA_LBI_Plugin::back( 'ka-lbi-photos', __( 'Nothing to do: no listing in this scope needs these fields (or all were checked recently).', 'ka-listing-bulk-importer' ), 'info' );
		}
		$opts  = array( 'what' => $what, 'sources' => $sources );
		$label = self::scope_label( $scope );
		if ( count( $ids ) <= self::SYNC_CAP ) {
			$counts = array();
			foreach ( $ids as $id ) {
				$r = self::job_item( array( 'meta' => $opts ), $id );
				foreach ( $r['counts'] as $k => $n ) {
					$counts[ $k ] = ( isset( $counts[ $k ] ) ? $counts[ $k ] : 0 ) + $n;
				}
			}
			$entry = array( 'type' => 'enrich', 'done' => count( $ids ), 'total' => count( $ids ), 'counts' => $counts );
			self::history_add( $label, $entry );
			KA_LBI_Plugin::back( 'ka-lbi-photos', KA_LBI_Jobs::describe( $entry ) );
		}
		$job = KA_LBI_Jobs::start( 'enrich', $ids, array_merge( $opts, array( 'label' => $label ) ) );
		if ( is_wp_error( $job ) ) {
			KA_LBI_Plugin::fail( $job->get_error_message(), 'ka-lbi-photos' );
		}
		KA_LBI_Plugin::back( 'ka-lbi-photos', __( 'Enrichment started in the background. Progress is shown below.', 'ka-listing-bulk-importer' ) );
	}

	/** Small inline runs are recorded in the same history as background jobs. */
	private static function history_add( $label, array $entry ) {
		$history = get_option( KA_LBI_Jobs::OPTION_HISTORY, array() );
		$history = is_array( $history ) ? $history : array();
		array_unshift( $history, array_merge( array( 'id' => wp_generate_uuid4(), 'label' => $label, 'provider' => '', 'started_at' => current_time( 'mysql' ), 'time' => current_time( 'mysql' ), 'last_error' => '', 'cancelled' => '' ), $entry ) );
		update_option( KA_LBI_Jobs::OPTION_HISTORY, array_slice( $history, 0, KA_LBI_Jobs::HISTORY_MAX ), false );
	}

	private static function scope_label( array $scope ) {
		$parts = array();
		$parts[] = $scope['location'] && ( $t = get_term( $scope['location'] ) ) && ! is_wp_error( $t ) ? $t->name : __( 'all cities', 'ka-listing-bulk-importer' );
		$parts[] = $scope['category'] && ( $t = get_term( $scope['category'] ) ) && ! is_wp_error( $t ) ? $t->name : __( 'all categories', 'ka-listing-bulk-importer' );
		return implode( ' / ', $parts );
	}

	/** "Enrich now" for one listing (review queue, quality lists). */
	public static function handle_one() {
		KA_LBI_Plugin::guard();
		$id   = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$back = isset( $_GET['back'] ) ? sanitize_key( wp_unslash( $_GET['back'] ) ) : 'ka-lbi-review';
		if ( ! $id || KA_LBI_Plugin::POST_TYPE !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
			KA_LBI_Plugin::fail( __( 'Listing not found.', 'ka-listing-bulk-importer' ), $back );
		}
		$r    = self::enrich( $id, self::default_options() );
		$text = $r['filled']
			/* translators: 1: listing title, 2: list of filled fields */
			? sprintf( __( '%1$s: filled %2$s.', 'ka-listing-bulk-importer' ), get_the_title( $id ), implode( ', ', $r['filled'] ) )
			/* translators: %s: listing title */
			: sprintf( __( '%s: nothing new found.', 'ka-listing-bulk-importer' ), get_the_title( $id ) ) . ( $r['notes'] ? ' ' . implode( ' ', $r['notes'] ) : '' );
		KA_LBI_Plugin::back( $back, $text, $r['filled'] ? 'success' : 'info' );
	}

	/** Targets and sources from the settings (used by "Enrich now" and the review queue). */
	public static function default_options() {
		$s       = KA_LBI_Settings::all();
		$google  = '' !== KA_LBI_Google::key();
		$what    = array();
		foreach ( array( 'email', 'phone', 'socials', 'photo', 'logo', 'hours', 'tagline' ) as $k ) {
			if ( ! empty( $s[ 'enrich_' . $k ] ) ) {
				$what[] = $k;
			}
		}
		if ( $google ) {
			$what = array_merge( $what, array( 'address', 'website' ) );
		}
		return array( 'what' => $what, 'sources' => $google ? array( 'website', 'google' ) : array( 'website' ) );
	}

	/** Job adapter. */
	public static function job_item( array $job, $post_id ) {
		$post_id = (int) $post_id;
		if ( KA_LBI_Plugin::POST_TYPE !== get_post_type( $post_id ) ) {
			return array( 'counts' => array( 'skipped' => 1 ) );
		}
		$r      = self::enrich( $post_id, $job['meta'] );
		$counts = array( 'checked' => 1, 'fields' => count( $r['filled'] ) );
		if ( $r['filled'] ) {
			$counts['improved'] = 1;
		}
		if ( ! $r['reachable'] ) {
			$counts['unreachable'] = 1;
		}
		return array(
			'counts'  => $counts,
			'message' => get_the_title( $post_id ) . ': ' . ( $r['filled'] ? implode( ', ', $r['filled'] ) : __( 'nothing new', 'ka-listing-bulk-importer' ) ),
		);
	}

	/* ------------------------------------------------------------------ engine */

	/**
	 * @param array $opts what (target keys), sources (website, google)
	 * @return array filled (labels), reachable, notes
	 */
	public static function enrich( $post_id, array $opts ) {
		$what    = isset( $opts['what'] ) ? (array) $opts['what'] : array_keys( self::targets() );
		$sources = isset( $opts['sources'] ) ? (array) $opts['sources'] : array( 'website' );
		$labels  = self::targets();
		$filled  = array();
		$notes   = array();
		$found   = array(); // field => value
		$reach   = true;

		$need = function ( $key ) use ( $post_id ) {
			return '' === (string) get_post_meta( $post_id, $key, true );
		};

		// 1) The business's own website.
		$site = trim( (string) get_post_meta( $post_id, 'website', true ) );
		if ( in_array( 'website', $sources, true ) && '' !== $site ) {
			$scan = self::scan_site( $site, in_array( 'email', $what, true ) && $need( 'email' ), in_array( 'phone', $what, true ) && $need( 'phone' ) );
			if ( is_wp_error( $scan ) ) {
				$reach   = false;
				$notes[] = $scan->get_error_message();
			} else {
				$found = $scan;
			}
		}

		// 2) Google Places, if wanted and something Google knows is still missing.
		$google_needed = ( in_array( 'phone', $what, true ) && $need( 'phone' ) && empty( $found['phone'] ) )
			|| ( in_array( 'website', $what, true ) && $need( 'website' ) )
			|| ( in_array( 'hours', $what, true ) && ! KA_LBI_Store::get_option( $post_id, 'business_hours' ) )
			|| ( in_array( 'address', $what, true ) && ( $need( 'gAddress' ) || $need( 'latitude' ) ) )
			|| ( in_array( 'photo', $what, true ) && ! has_post_thumbnail( $post_id ) && empty( $found['photo'] ) );
		if ( in_array( 'google', $sources, true ) && $google_needed && '' !== KA_LBI_Google::key() ) {
			$g = self::google_match( $post_id );
			if ( is_wp_error( $g ) ) {
				$notes[] = $g->get_error_message();
			} elseif ( $g ) {
				foreach ( array( 'phone', 'website', 'gAddress', 'latitude', 'longitude', 'business_hours', 'place_id' ) as $k ) {
					if ( isset( $g[ $k ] ) && empty( $found[ $k ] ) ) {
						$found[ $k ] = $g[ $k ];
					}
				}
				if ( ! empty( $g['featured_photo'] ) && empty( $found['photo'] ) ) {
					$found['photo'] = $g['featured_photo'];
				}
			}
		}

		// 3) Apply - only into empty fields.
		$set = function ( $key, $value, $type ) use ( $post_id, &$filled ) {
			$v = KA_LBI_Store::sanitize( $value, $type );
			if ( null !== $v && '' !== $v && '' === (string) get_post_meta( $post_id, $key, true ) ) {
				update_post_meta( $post_id, $key, $v );
				return true;
			}
			return false;
		};
		if ( in_array( 'email', $what, true ) && ! empty( $found['email'] ) && $set( 'email', $found['email'], 'email' ) ) {
			$filled[] = $labels['email'];
		}
		if ( in_array( 'phone', $what, true ) && ! empty( $found['phone'] ) && $set( 'phone', $found['phone'], 'phone' ) ) {
			$filled[] = $labels['phone'];
		}
		if ( in_array( 'website', $what, true ) && ! empty( $found['website'] ) && $set( 'website', $found['website'], 'url' ) ) {
			$filled[] = $labels['website'];
		}
		if ( in_array( 'socials', $what, true ) ) {
			$any = false;
			foreach ( array( 'facebook', 'instagram', 'linkedin', 'twitter', 'youtube' ) as $k ) {
				if ( ! empty( $found[ $k ] ) && $set( $k, $found[ $k ], 'url' ) ) {
					$any = true;
				}
			}
			if ( ! empty( $found['whatsapp'] ) && $set( 'whatsapp', $found['whatsapp'], 'phone' ) ) {
				$any = true;
			}
			if ( $any ) {
				$filled[] = $labels['socials'];
			}
		}
		if ( in_array( 'address', $what, true ) ) {
			$a = ! empty( $found['gAddress'] ) && $set( 'gAddress', $found['gAddress'], 'text' );
			$b = isset( $found['latitude'], $found['longitude'] ) && $need( 'latitude' ) && $set( 'latitude', $found['latitude'], 'float' ) && $set( 'longitude', $found['longitude'], 'float' );
			if ( $a || $b ) {
				$filled[] = $labels['address'];
			}
		}
		if ( ! empty( $found['place_id'] ) && '' === (string) get_post_meta( $post_id, '_ka_lbi_place_id', true ) ) {
			update_post_meta( $post_id, '_ka_lbi_place_id', sanitize_text_field( $found['place_id'] ) );
		}
		if ( in_array( 'hours', $what, true ) && ! empty( $found['business_hours'] ) && ! KA_LBI_Store::get_option( $post_id, 'business_hours' ) ) {
			$opt                   = get_post_meta( $post_id, 'lp_listingpro_options', true );
			$opt                   = is_array( $opt ) ? $opt : array();
			$opt['business_hours'] = KA_LBI_Store::clean_hours( $found['business_hours'] );
			update_post_meta( $post_id, 'lp_listingpro_options', $opt );
			$filled[] = $labels['hours'];
		}
		if ( in_array( 'tagline', $what, true ) && ! empty( $found['tagline'] ) && $set( 'tagline_text', $found['tagline'], 'text' ) ) {
			$filled[] = $labels['tagline'];
		}
		if ( in_array( 'photo', $what, true ) && ! has_post_thumbnail( $post_id ) && ! empty( $found['photo'] ) ) {
			$aid = KA_LBI_Media::import( $found['photo'], $post_id );
			if ( ! is_wp_error( $aid ) ) {
				set_post_thumbnail( $post_id, $aid );
				$filled[] = $labels['photo'];
			} else {
				$notes[] = $aid->get_error_message();
			}
		}
		if ( in_array( 'logo', $what, true ) && ! empty( $found['logo'] ) && ! KA_LBI_Store::get_option( $post_id, 'business_logo' ) ) {
			$aid = KA_LBI_Media::import( $found['logo'], $post_id );
			if ( ! is_wp_error( $aid ) ) {
				$opt                  = get_post_meta( $post_id, 'lp_listingpro_options', true );
				$opt                  = is_array( $opt ) ? $opt : array();
				$opt['business_logo'] = wp_get_attachment_url( $aid );
				update_post_meta( $post_id, 'lp_listingpro_options', $opt );
				$filled[] = $labels['logo'];
			}
		}

		if ( $filled ) {
			KA_LBI_Store::ensure_plan_meta_placeholder( $post_id );
			KA_LBI_Store::sync_options_meta( $post_id );
			KA_LBI_Lang::propagate( $post_id );
		}
		update_post_meta( $post_id, self::META_DONE, time() );
		update_post_meta( $post_id, self::META_NOTES, array( 'filled' => $filled, 'notes' => array_slice( $notes, 0, 3 ), 'time' => current_time( 'mysql' ) ) );
		return array( 'filled' => $filled, 'reachable' => $reach, 'notes' => $notes );
	}

	/** Google match for an existing listing - only accepted when name, phone or website agree. */
	private static function google_match( $post_id ) {
		$city  = '';
		$terms = get_the_terms( $post_id, KA_LBI_Plugin::TAX_LOCATION );
		if ( $terms && ! is_wp_error( $terms ) ) {
			$city = KA_LBI_Lang::search_name( $terms[0] );
		}
		$title = html_entity_decode( get_the_title( $post_id ), ENT_QUOTES, 'UTF-8' );
		// Bilingual titles: the Latin half finds more on Google.
		foreach ( preg_split( '/\s[|–—-]\s/u', $title ) as $part ) {
			if ( preg_match( '/[A-Za-z]{3}/', $part ) ) {
				$title = $part;
				break;
			}
		}
		$place = KA_LBI_Google::find_one( trim( $title . ' ' . $city ) );
		if ( is_wp_error( $place ) || ! $place ) {
			return $place;
		}
		$f        = KA_LBI_Google::to_fields( $place );
		$same     = array_intersect( KA_LBI_Store::title_keys( get_the_title( $post_id ) ), KA_LBI_Store::title_keys( $f['post_title'] ) );
		$phone_ok = KA_LBI_Store::phone_key( get_post_meta( $post_id, 'phone', true ) ) && KA_LBI_Store::phone_key( get_post_meta( $post_id, 'phone', true ) ) === KA_LBI_Store::phone_key( isset( $f['phone'] ) ? $f['phone'] : '' );
		$host_ok  = KA_LBI_Store::host_key( get_post_meta( $post_id, 'website', true ) ) && KA_LBI_Store::host_key( get_post_meta( $post_id, 'website', true ) ) === KA_LBI_Store::host_key( isset( $f['website'] ) ? $f['website'] : '' );
		$similar  = 0;
		similar_text( KA_LBI_Lang::norm( $title ), KA_LBI_Lang::norm( $f['post_title'] ), $similar );
		if ( ! $same && ! $phone_ok && ! $host_ok && $similar < 70 ) {
			return null;
		}
		return $f;
	}

	/* ------------------------------------------------------------------ website scan */

	/** @return array|WP_Error */
	private static function fetch( $url ) {
		$r = wp_safe_remote_get( $url, array(
			'timeout'             => 8,
			'redirection'         => 3,
			'limit_response_size' => self::MAX_BYTES,
			'user-agent'          => 'Mozilla/5.0 (compatible; KA-Listing-Enricher/4.0; +' . home_url( '/' ) . ')',
			'headers'             => array( 'Accept' => 'text/html,application/xhtml+xml', 'Accept-Language' => 'de,en;q=0.8' ),
		) );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		if ( $code < 200 || $code >= 400 ) {
			/* translators: %d: HTTP status */
			return new WP_Error( 'ka_lbi_site', sprintf( __( 'Website answered HTTP %d.', 'ka-listing-bulk-importer' ), $code ) );
		}
		$type = (string) wp_remote_retrieve_header( $r, 'content-type' );
		if ( $type && false === stripos( $type, 'html' ) ) {
			return new WP_Error( 'ka_lbi_site', __( 'Website did not return a web page.', 'ka-listing-bulk-importer' ) );
		}
		return (string) wp_remote_retrieve_body( $r );
	}

	/**
	 * Read the home page and, when e-mail or phone are still missing, the Impressum or
	 * contact page (German sites must publish an Impressum with an e-mail address).
	 */
	public static function scan_site( $site, $want_email = true, $want_phone = true ) {
		if ( ! preg_match( '#^https?://#i', $site ) ) {
			$site = 'https://' . ltrim( $site, '/' );
		}
		$html = self::fetch( $site );
		if ( is_wp_error( $html ) && 0 === stripos( $site, 'https://' ) ) {
			$html = self::fetch( 'http://' . substr( $site, 8 ) ); // some small sites still have no TLS
		}
		if ( is_wp_error( $html ) ) {
			/* translators: %s: error */
			return new WP_Error( 'ka_lbi_site', sprintf( __( 'Website not reachable: %s', 'ka-listing-bulk-importer' ), $html->get_error_message() ) );
		}
		$host  = KA_LBI_Store::host_key( $site );
		$found = self::parse( $html, $site, $host );
		if ( ( $want_email && empty( $found['email'] ) ) || ( $want_phone && empty( $found['phone'] ) ) ) {
			foreach ( array_slice( $found['_pages'], 0, 2 ) as $page ) {
				$sub = self::fetch( $page );
				if ( is_wp_error( $sub ) ) {
					continue;
				}
				$more = self::parse( $sub, $page, $host );
				foreach ( $more as $k => $v ) {
					if ( empty( $found[ $k ] ) && $v ) {
						$found[ $k ] = $v;
					}
				}
				if ( ! empty( $found['email'] ) && ( ! $want_phone || ! empty( $found['phone'] ) ) ) {
					break;
				}
			}
		}
		unset( $found['_pages'] );
		return $found;
	}

	/** Extract contact data from one HTML page. */
	public static function parse( $html, $base, $host ) {
		$out  = array( '_pages' => array() );
		$html = (string) $html;
		$head = preg_match( '#<head[^>]*>(.*?)</head>#is', $html, $m ) ? $m[1] : $html;

		$hrefs = array();
		if ( preg_match_all( '#<a\s[^>]*href\s*=\s*["\']([^"\']+)["\'][^>]*>(.*?)</a>#is', $html, $mm, PREG_SET_ORDER ) ) {
			foreach ( $mm as $a ) {
				$hrefs[] = array( html_entity_decode( trim( $a[1] ), ENT_QUOTES, 'UTF-8' ), wp_strip_all_tags( $a[2] ) );
			}
		}

		// E-mail: mailto links first, then plain text (also "name [at] domain.de").
		$emails = array();
		foreach ( $hrefs as $a ) {
			if ( 0 === stripos( $a[0], 'mailto:' ) ) {
				$emails[] = rawurldecode( strtok( substr( $a[0], 7 ), '?' ) );
			}
		}
		$text = html_entity_decode( wp_strip_all_tags( preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $html ) ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s*(\[|\()\s*(at|ät)\s*(\]|\))\s*/i', '@', $text );
		$text = preg_replace( '/\s*(\[|\()\s*(dot|punkt)\s*(\]|\))\s*/i', '.', $text );
		if ( preg_match_all( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $em ) ) {
			$emails = array_merge( $emails, $em[0] );
		}
		$out['email'] = self::best_email( $emails, $host );

		// Phone: tel: links, then "Tel." lines of the page text.
		foreach ( $hrefs as $a ) {
			if ( 0 === stripos( $a[0], 'tel:' ) ) {
				$p = trim( rawurldecode( substr( $a[0], 4 ) ) );
				if ( strlen( preg_replace( '/\D/', '', $p ) ) >= 7 ) {
					$out['phone'] = $p;
					break;
				}
			}
		}
		if ( empty( $out['phone'] ) && preg_match( '/(?:Tel(?:efon)?|Phone|Fon|Mobil)\.?\s*:?\s*(\+?[\d][\d \/().\-]{6,20}\d)/iu', $text, $pm ) ) {
			$out['phone'] = trim( $pm[1] );
		}

		// Social profiles and WhatsApp.
		$patterns = array(
			'facebook'  => '#^https?://(?:www\.|m\.)?facebook\.com/(?!sharer|share|plugins|dialog|tr\b|profile\.php\?id=0)[^\s"\']+#i',
			'instagram' => '#^https?://(?:www\.)?instagram\.com/(?!p/|reel/|explore/|share)[A-Za-z0-9_.]+/?#i',
			'linkedin'  => '#^https?://(?:[a-z]{2,3}\.)?linkedin\.com/(?:company|in)/[^\s"\'?]+#i',
			'twitter'   => '#^https?://(?:www\.)?(?:twitter|x)\.com/(?!intent|share|home)[A-Za-z0-9_]+/?$#i',
			'youtube'   => '#^https?://(?:www\.)?youtube\.com/(?:channel/|c/|user/|@)[^\s"\'?]+#i',
		);
		foreach ( $hrefs as $a ) {
			foreach ( $patterns as $k => $re ) {
				if ( empty( $out[ $k ] ) && preg_match( $re, $a[0], $sm ) ) {
					$out[ $k ] = $sm[0];
				}
			}
			if ( empty( $out['whatsapp'] ) && preg_match( '#^https?://(?:wa\.me/|api\.whatsapp\.com/send\?phone=)\+?(\d{8,15})#i', $a[0], $wm ) ) {
				$out['whatsapp'] = '+' . $wm[1];
			}
			// Impressum / contact pages on the same site, for a second look.
			if ( preg_match( '/(impressum|imprint|kontakt|contact|about|ueber-uns|uber-uns)/i', $a[0] . ' ' . $a[1] ) ) {
				$abs = self::absolute( $a[0], $base );
				if ( $abs && KA_LBI_Store::host_key( $abs ) === $host && ! in_array( $abs, $out['_pages'], true ) ) {
					// Impressum first - it is legally required to carry the e-mail address.
					if ( preg_match( '/(impressum|imprint)/i', $a[0] . ' ' . $a[1] ) ) {
						array_unshift( $out['_pages'], $abs );
					} else {
						$out['_pages'][] = $abs;
					}
				}
			}
		}

		// Photo and logo.
		foreach ( array( 'og:image(?::secure_url)?', 'twitter:image(?::src)?' ) as $prop ) {
			if ( preg_match( '#<meta[^>]+(?:property|name)=["\']' . $prop . '["\'][^>]+content=["\']([^"\']+)["\']#i', $head, $im ) || preg_match( '#<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:property|name)=["\']' . $prop . '["\']#i', $head, $im ) ) {
				$out['photo'] = self::absolute( html_entity_decode( $im[1], ENT_QUOTES ), $base );
				break;
			}
		}
		if ( preg_match_all( '#<img\s[^>]*>#i', $html, $imgs ) ) {
			foreach ( $imgs[0] as $tag ) {
				if ( preg_match( '/(class|id|alt|src)=["\'][^"\']*logo[^"\']*["\']/i', $tag ) && preg_match( '/\ssrc=["\']([^"\']+\.(?:png|jpe?g|webp)(?:\?[^"\']*)?)["\']/i', $tag, $src ) ) {
					$out['logo'] = self::absolute( html_entity_decode( $src[1], ENT_QUOTES ), $base );
					break;
				}
			}
		}
		if ( empty( $out['logo'] ) && preg_match( '#<link[^>]+rel=["\']apple-touch-icon[^"\']*["\'][^>]+href=["\']([^"\']+)["\']#i', $head, $lm ) ) {
			$out['logo'] = self::absolute( html_entity_decode( $lm[1], ENT_QUOTES ), $base );
		}

		// Short self-description for the tagline.
		foreach ( array( 'description', 'og:description' ) as $prop ) {
			if ( preg_match( '#<meta[^>]+(?:name|property)=["\']' . $prop . '["\'][^>]+content=["\']([^"\']{20,})["\']#i', $head, $dm ) ) {
				$out['tagline'] = mb_strimwidth( trim( html_entity_decode( $dm[1], ENT_QUOTES, 'UTF-8' ) ), 0, 120, '…' );
				break;
			}
		}
		return array_filter( $out, function ( $v ) {
			return '' !== $v && null !== $v;
		} ) + array( '_pages' => array() );
	}

	/** Prefer an address on the business's own domain; drop tracking and placeholder addresses. */
	private static function best_email( array $emails, $host ) {
		$good = array();
		foreach ( array_unique( array_map( 'strtolower', array_map( 'trim', $emails ) ) ) as $e ) {
			if ( ! is_email( $e ) || preg_match( '/\.(png|jpe?g|gif|webp|svg)$/', $e ) || preg_match( '/(@(example|beispiel|domain|ihre-?domain|email|mail)\.[a-z]+$|sentry|wixpress|^(no-?reply|u00))/', $e ) ) {
				continue;
			}
			$good[] = $e;
		}
		foreach ( $good as $e ) {
			if ( $host && substr( $e, -strlen( $host ) - 1 ) === '@' . $host ) {
				return $e;
			}
		}
		foreach ( $good as $e ) {
			if ( $host && false !== strpos( substr( $e, strpos( $e, '@' ) ), $host ) ) {
				return $e;
			}
		}
		return $good ? $good[0] : '';
	}

	private static function absolute( $url, $base ) {
		$url = trim( (string) $url );
		if ( '' === $url || preg_match( '#^(javascript|data|mailto|tel):#i', $url ) ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		$p = wp_parse_url( $base );
		if ( empty( $p['host'] ) ) {
			return '';
		}
		$scheme = isset( $p['scheme'] ) ? $p['scheme'] : 'https';
		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		$root = $scheme . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
		if ( 0 === strpos( $url, '/' ) ) {
			return $root . $url;
		}
		$path = isset( $p['path'] ) ? $p['path'] : '/';
		$dir  = '/' === substr( $path, -1 ) ? $path : trailingslashit( dirname( $path ) );
		return $root . $dir . ltrim( $url, './' );
	}
}
