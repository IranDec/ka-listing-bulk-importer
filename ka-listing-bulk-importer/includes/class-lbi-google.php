<?php
/**
 * Google Places API (New).
 *
 * One Text Search request returns name, address, coordinates, phone, website, opening
 * hours, rating and photo references for up to 20 places - no per-result detail calls,
 * which is what made 3.x background runs slow. Photos are resolved only for rows that
 * are actually imported.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Google {

	const ENDPOINT = 'https://places.googleapis.com/v1/';

	const SEARCH_FIELDS = 'places.id,places.displayName,places.formattedAddress,places.location,places.internationalPhoneNumber,places.nationalPhoneNumber,places.websiteUri,places.googleMapsUri,places.rating,places.userRatingCount,places.businessStatus,places.regularOpeningHours,places.photos,places.editorialSummary,nextPageToken';

	public static function key() {
		return (string) get_option( 'ka_lbi_key_google', '' );
	}

	/** POST to Places API (New); returns decoded body or WP_Error with Google's message. */
	private static function request( $path, array $body, $fields, $key = null, $timeout = 15 ) {
		$key = null === $key ? self::key() : $key;
		if ( '' === $key ) {
			return new WP_Error( 'ka_lbi_google_key', __( 'No Google Maps key saved.', 'ka-listing-bulk-importer' ) );
		}
		$response = wp_remote_post( self::ENDPOINT . $path, array(
			'timeout' => $timeout,
			'headers' => array(
				'Content-Type'     => 'application/json',
				'X-Goog-Api-Key'   => $key,
				'X-Goog-FieldMask' => $fields,
			),
			'body'    => wp_json_encode( $body ),
		) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( 200 !== $code ) {
			$msg = is_array( $data ) && ! empty( $data['error']['message'] ) ? $data['error']['message'] : 'HTTP ' . $code;
			return new WP_Error( 'ka_lbi_google', self::explain( $code, $msg ) );
		}
		return is_array( $data ) ? $data : array();
	}

	/** Plain-language hints for the usual setup mistakes. */
	private static function explain( $code, $msg ) {
		if ( 403 === $code && false !== stripos( $msg, 'not been used' ) ) {
			return __( 'Places API (New) is not enabled for this Google Cloud project. Enable "Places API (New)" in the API Library.', 'ka-listing-bulk-importer' ) . ' (' . $msg . ')';
		}
		if ( 403 === $code || 401 === $code ) {
			return __( 'Google rejected the key. Check that it is valid, billing is linked and the key restrictions allow "Places API (New)".', 'ka-listing-bulk-importer' ) . ' (' . $msg . ')';
		}
		if ( 429 === $code ) {
			return __( 'Google quota reached - wait or raise the quota in the Cloud Console.', 'ka-listing-bulk-importer' );
		}
		/* translators: 1: HTTP status, 2: message from Google */
		return sprintf( __( 'Google Places error (HTTP %1$d): %2$s', 'ka-listing-bulk-importer' ), $code, $msg );
	}

	/** @return array{ok:bool,message:string} */
	public static function test_key( $key ) {
		$r = self::request( 'places:searchText', array( 'textQuery' => 'Brandenburger Tor Berlin', 'pageSize' => 1 ), 'places.id', $key );
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'message' => $r->get_error_message() );
		}
		return array( 'ok' => true, 'message' => __( 'Places API (New) answered normally.', 'ka-listing-bulk-importer' ) );
	}

	/**
	 * Text search, following page tokens until $max results.
	 *
	 * @return array[]|WP_Error raw place arrays
	 */
	public static function search( $query, $max ) {
		$max    = max( 1, min( 60, (int) $max ) );
		$places = array();
		$token  = '';
		$pages  = 0;
		do {
			$body = array(
				'textQuery'    => $query,
				'pageSize'     => min( 20, $max - count( $places ) ),
				'languageCode' => 'de',
				'regionCode'   => 'DE',
			);
			if ( $token ) {
				$body['pageToken'] = $token;
			}
			$r = self::request( 'places:searchText', $body, self::SEARCH_FIELDS );
			if ( is_wp_error( $r ) ) {
				return $places ? $places : $r;
			}
			foreach ( isset( $r['places'] ) ? (array) $r['places'] : array() as $p ) {
				$places[] = $p;
			}
			$token = isset( $r['nextPageToken'] ) ? $r['nextPageToken'] : '';
			$pages++;
		} while ( $token && $pages < 3 && count( $places ) < $max );
		return array_slice( $places, 0, $max );
	}

	/** Map a place to importer fields. */
	public static function to_fields( array $p ) {
		$f = array(
			'post_title' => isset( $p['displayName']['text'] ) ? sanitize_text_field( $p['displayName']['text'] ) : '',
			'gAddress'   => isset( $p['formattedAddress'] ) ? sanitize_text_field( preg_replace( '/,\s*(Deutschland|Germany)$/u', '', $p['formattedAddress'] ) ) : '',
			'place_id'   => isset( $p['id'] ) ? sanitize_text_field( $p['id'] ) : '',
		);
		if ( isset( $p['location']['latitude'], $p['location']['longitude'] ) ) {
			$f['latitude']  = (float) $p['location']['latitude'];
			$f['longitude'] = (float) $p['location']['longitude'];
		}
		if ( ! empty( $p['internationalPhoneNumber'] ) ) {
			$f['phone'] = sanitize_text_field( $p['internationalPhoneNumber'] );
		} elseif ( ! empty( $p['nationalPhoneNumber'] ) ) {
			$f['phone'] = sanitize_text_field( $p['nationalPhoneNumber'] );
		}
		if ( ! empty( $p['websiteUri'] ) ) {
			$f['website'] = esc_url_raw( $p['websiteUri'] );
		}
		if ( ! empty( $p['photos'][0]['name'] ) ) {
			$f['featured_photo'] = 'gplace:' . sanitize_text_field( $p['photos'][0]['name'] );
		}
		if ( ! empty( $p['regularOpeningHours']['periods'] ) ) {
			$hours = self::hours( $p['regularOpeningHours']['periods'] );
			if ( $hours ) {
				$f['business_hours'] = $hours;
			}
		}
		if ( isset( $p['rating'] ) ) {
			$f['rating']       = (float) $p['rating'];
			$f['rating_count'] = isset( $p['userRatingCount'] ) ? (int) $p['userRatingCount'] : 0;
		}
		if ( ! empty( $p['googleMapsUri'] ) ) {
			$f['source_url'] = esc_url_raw( $p['googleMapsUri'] );
		}
		$f['business_status'] = isset( $p['businessStatus'] ) ? (string) $p['businessStatus'] : '';
		return $f;
	}

	/** Google periods (day 0 = Sunday) to ListingPro hours; first period of a day wins. */
	public static function hours( array $periods ) {
		$names = array( 'Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' );
		$out   = array();
		foreach ( $periods as $per ) {
			if ( ! isset( $per['open']['day'] ) ) {
				continue;
			}
			$day = $names[ (int) $per['open']['day'] % 7 ];
			if ( isset( $out[ $day ] ) ) {
				continue;
			}
			if ( ! isset( $per['close'] ) ) { // open 24 hours
				$out[ $day ] = array( 'open' => '12:00am', 'close' => '11:59pm' );
				continue;
			}
			$out[ $day ] = array(
				'open'  => self::clock( $per['open'] ),
				'close' => self::clock( $per['close'] ),
			);
		}
		$ordered = array();
		foreach ( array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ) as $d ) {
			if ( isset( $out[ $d ] ) ) {
				$ordered[ $d ] = $out[ $d ];
			}
		}
		return $ordered;
	}

	private static function clock( array $t ) {
		$h = isset( $t['hour'] ) ? (int) $t['hour'] : 0;
		$m = isset( $t['minute'] ) ? (int) $t['minute'] : 0;
		return sprintf( '%02d:%02d%s', ( $h % 12 ) ? $h % 12 : 12, $m, $h < 12 ? 'am' : 'pm' );
	}

	/** Resolve a photo resource name to a short-lived download URL. */
	public static function photo_url( $name ) {
		if ( ! preg_match( '#^places/[^/]+/photos/[^/]+$#', $name ) ) {
			return new WP_Error( 'ka_lbi_google_photo', __( 'Invalid Google photo reference', 'ka-listing-bulk-importer' ) );
		}
		$key = self::key();
		if ( '' === $key ) {
			return new WP_Error( 'ka_lbi_google_key', __( 'No Google Maps key saved.', 'ka-listing-bulk-importer' ) );
		}
		$response = wp_remote_get( add_query_arg( array( 'maxWidthPx' => 1600, 'skipHttpRedirect' => 'true', 'key' => $key ), self::ENDPOINT . $name . '/media' ), array( 'timeout' => 15 ) );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['photoUri'] ) ) {
			return new WP_Error( 'ka_lbi_google_photo', ! empty( $data['error']['message'] ) ? $data['error']['message'] : __( 'Google returned no photo.', 'ka-listing-bulk-importer' ) );
		}
		return $data['photoUri'];
	}

	/** Best single match for an existing listing ("name city"), for enrichment. */
	public static function find_one( $text ) {
		$r = self::request( 'places:searchText', array( 'textQuery' => $text, 'pageSize' => 1, 'languageCode' => 'de', 'regionCode' => 'DE' ), self::SEARCH_FIELDS );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		return ! empty( $r['places'][0] ) ? $r['places'][0] : null;
	}
}
