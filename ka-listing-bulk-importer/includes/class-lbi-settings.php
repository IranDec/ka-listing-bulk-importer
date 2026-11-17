<?php
/**
 * General settings (new in 4.0) plus the photo options kept from 3.x.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Settings {

	const OPTION = 'ka_lbi_settings';

	// 3.x photo options, unchanged.
	const OPTION_OPTIMIZE_PHOTOS    = 'ka_lbi_optimize_photos';
	const OPTION_OPTIMIZE_MAX_WIDTH = 'ka_lbi_optimize_max_width';
	const OPTION_OPTIMIZE_QUALITY   = 'ka_lbi_optimize_quality';

	public static function defaults() {
		return array(
			'language'         => '',   // import language, '' = Polylang default
			'search_language'  => 'de', // names used in search queries (category/city translation)
			'google_query'     => '{category} persisch iranisch {city}',
			'skip_closed'      => 1,
			'job_results'      => 20,   // results per city/category inside background runs
			'create_locations' => 1,
			'translate'        => 1,    // ask KA Translate for the other languages once published
			'notify'           => 1,
			'notify_email'     => '',
			'enrich_email'     => 1,
			'enrich_phone'     => 1,
			'enrich_socials'   => 1,
			'enrich_photo'     => 1,
			'enrich_logo'      => 1,
			'enrich_hours'     => 1,
			'enrich_tagline'   => 0,
			'enrich_days'      => 30,   // do not re-check a listing's website more often than this
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/** Sanitize posted settings against the defaults' types. */
	public static function save( array $in ) {
		$out = array();
		foreach ( self::defaults() as $key => $default ) {
			if ( is_int( $default ) ) {
				$out[ $key ] = isset( $in[ $key ] ) ? absint( $in[ $key ] ) : 0;
			} else {
				$out[ $key ] = isset( $in[ $key ] ) && is_string( $in[ $key ] ) ? sanitize_text_field( $in[ $key ] ) : $default;
			}
		}
		$out['job_results'] = max( 1, min( 60, $out['job_results'] ? $out['job_results'] : 20 ) );
		$out['enrich_days'] = min( 365, $out['enrich_days'] );
		$out['notify_email'] = is_email( $out['notify_email'] ) ? $out['notify_email'] : '';
		if ( false === strpos( $out['google_query'], '{category}' ) || false === strpos( $out['google_query'], '{city}' ) ) {
			$out['google_query'] = self::defaults()['google_query'];
		}
		if ( '' !== $out['language'] && ! in_array( $out['language'], array_keys( KA_LBI_Lang::languages() ), true ) ) {
			$out['language'] = '';
		}
		update_option( self::OPTION, $out, false );

		update_option( self::OPTION_OPTIMIZE_PHOTOS, ! empty( $in['optimize_photos'] ) ? '1' : '0', false );
		$width = isset( $in['optimize_max_width'] ) ? (int) $in['optimize_max_width'] : 1600;
		update_option( self::OPTION_OPTIMIZE_MAX_WIDTH, ( $width >= 200 && $width <= 4000 ) ? $width : 1600, false );
		$quality = isset( $in['optimize_quality'] ) ? (int) $in['optimize_quality'] : 82;
		update_option( self::OPTION_OPTIMIZE_QUALITY, ( $quality > 0 && $quality <= 100 ) ? $quality : 82, false );
	}

	/** Where summaries go. */
	public static function notify_to() {
		$s = self::all();
		if ( empty( $s['notify'] ) ) {
			return '';
		}
		return $s['notify_email'] ? $s['notify_email'] : (string) get_option( 'admin_email' );
	}
}
