<?php
/**
 * Polylang awareness. Everything degrades to "one language" when Polylang is not active.
 *
 * Listings are written in one source language (Persian on hamsaye.de). The other language
 * versions are created by KA Translate (posts marked with _kt_gen = 1). Those copies are
 * never treated as duplicates, and data filled into a source listing is copied to them.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Lang {

	/** Language-neutral meta that is copied to the translations of a listing. */
	const SHARED_META = array( 'gAddress', 'latitude', 'longitude', 'phone', 'whatsapp', 'email', 'website', 'facebook', 'linkedin', 'twitter', 'youtube', 'instagram', 'video' );

	public static function active() {
		return function_exists( 'pll_languages_list' ) && function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_is_translated_post_type' ) && pll_is_translated_post_type( KA_LBI_Plugin::POST_TYPE );
	}

	/** @return array slug => name */
	public static function languages() {
		if ( ! self::active() ) {
			return array();
		}
		$out = array();
		foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $l ) {
			if ( is_object( $l ) ) {
				$out[ $l->slug ] = $l->name;
			}
		}
		return $out;
	}

	public static function default_language() {
		return self::active() && function_exists( 'pll_default_language' ) ? (string) pll_default_language() : '';
	}

	/** Language new listings are written in. */
	public static function import_language( $requested = '' ) {
		$langs = self::languages();
		if ( ! $langs ) {
			return '';
		}
		foreach ( array( $requested, KA_LBI_Settings::get( 'language' ), self::default_language() ) as $l ) {
			if ( $l && isset( $langs[ $l ] ) ) {
				return $l;
			}
		}
		return (string) key( $langs );
	}

	public static function term_translated( $taxonomy ) {
		return self::active() && function_exists( 'pll_is_translated_taxonomy' ) && pll_is_translated_taxonomy( $taxonomy );
	}

	/** Terms of a taxonomy in one language (all terms without Polylang). */
	public static function terms( $taxonomy, $lang = '', array $extra = array() ) {
		$args = array_merge( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'name' ), $extra );
		if ( self::term_translated( $taxonomy ) ) {
			$args['lang'] = $lang ? $lang : self::import_language();
		}
		$terms = get_terms( $args );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	public static function term_language( $term_id ) {
		return self::active() && function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $term_id ) : '';
	}

	/** The same term in another language, or the term itself. */
	public static function term_in( $term_id, $lang ) {
		if ( $lang && self::active() && function_exists( 'pll_get_term' ) ) {
			$t = pll_get_term( $term_id, $lang );
			if ( $t ) {
				return (int) $t;
			}
		}
		return (int) $term_id;
	}

	/**
	 * Find a term by name or slug, preferring the given language. A term found in another
	 * language is mapped to its translation. Returns WP_Term or null.
	 */
	public static function find_term( $taxonomy, $value, $lang = '' ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return null;
		}
		if ( ctype_digit( $value ) ) {
			$t = get_term( (int) $value, $taxonomy );
			if ( $t && ! is_wp_error( $t ) ) {
				return get_term( self::term_in( $t->term_id, $lang ), $taxonomy );
			}
		}
		$translated = self::term_translated( $taxonomy );
		$norm       = self::norm( $value );
		$slug       = sanitize_title( $value );
		$passes     = $translated && $lang ? array( $lang, '' ) : array( '' );
		foreach ( $passes as $pass_lang ) {
			foreach ( self::all_terms( $taxonomy, $translated ? $pass_lang : null ) as $t ) {
				if ( self::norm( $t->name ) === $norm || $t->slug === $value || $t->slug === $slug ) {
					if ( $translated && $lang && self::term_language( $t->term_id ) !== $lang ) {
						$mapped = self::term_in( $t->term_id, $lang );
						$t      = get_term( $mapped, $taxonomy );
					}
					return ( $t && ! is_wp_error( $t ) ) ? $t : null;
				}
			}
		}
		return null;
	}

	/** Per-request cache of term lists; reset when a term is created. */
	private static $term_cache = array();

	private static function all_terms( $taxonomy, $lang ) {
		$key = $taxonomy . '|' . ( null === $lang ? '-' : $lang );
		if ( ! isset( self::$term_cache[ $key ] ) ) {
			$args = array( 'taxonomy' => $taxonomy, 'hide_empty' => false );
			if ( null !== $lang ) {
				$args['lang'] = $lang;
			}
			$terms                    = get_terms( $args );
			self::$term_cache[ $key ] = is_wp_error( $terms ) ? array() : $terms;
		}
		return self::$term_cache[ $key ];
	}

	/** Create a location term in the import language (categories are never created automatically). */
	public static function create_term( $taxonomy, $name, $lang ) {
		self::$term_cache = array();
		$r                = wp_insert_term( $name, $taxonomy );
		if ( is_wp_error( $r ) ) {
			if ( 'term_exists' === $r->get_error_code() ) {
				return get_term( (int) $r->get_error_data(), $taxonomy );
			}
			return null;
		}
		if ( $lang && self::term_translated( $taxonomy ) && function_exists( 'pll_set_term_language' ) ) {
			pll_set_term_language( (int) $r['term_id'], $lang );
		}
		return get_term( (int) $r['term_id'], $taxonomy );
	}

	/** Name of a term as used in search queries (e.g. the German name of a Persian category). */
	public static function search_name( $term ) {
		if ( ! $term ) {
			return '';
		}
		$lang = (string) KA_LBI_Settings::get( 'search_language' );
		$id   = self::term_in( $term->term_id, $lang );
		if ( $id !== (int) $term->term_id ) {
			$t = get_term( $id, $term->taxonomy );
			if ( $t && ! is_wp_error( $t ) ) {
				return self::tidy( $t->name );
			}
		}
		return self::tidy( $term->name );
	}

	/** "HAMBURG" -> "Hamburg", entities decoded. */
	private static function tidy( $name ) {
		$name = html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
		if ( function_exists( 'mb_strtoupper' ) && mb_strlen( $name ) > 3 && mb_strtoupper( $name ) === $name && preg_match( '/[A-Z]/', $name ) ) {
			$name = mb_convert_case( mb_strtolower( $name ), MB_CASE_TITLE );
		}
		return $name;
	}

	public static function norm( $s ) {
		$s = html_entity_decode( (string) $s, ENT_QUOTES, 'UTF-8' );
		$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
		return trim( preg_replace( '/\s+/u', ' ', $s ) );
	}

	public static function set_post_language( $post_id, $lang ) {
		if ( $lang && self::active() ) {
			pll_set_post_language( $post_id, $lang );
		}
	}

	public static function post_language( $post_id ) {
		return self::active() && function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $post_id ) : '';
	}

	/** True for language copies made by KA Translate. */
	public static function is_generated( $post_id ) {
		return '1' === (string) get_post_meta( $post_id, '_kt_gen', true );
	}

	/** Other language versions of a listing: lang => post id. */
	public static function translations( $post_id ) {
		if ( ! self::active() || ! function_exists( 'pll_get_post_translations' ) ) {
			return array();
		}
		$all = (array) pll_get_post_translations( $post_id );
		unset( $all[ self::post_language( $post_id ) ] );
		return array_filter( array_map( 'intval', $all ), function ( $id ) use ( $post_id ) {
			return $id && $id !== (int) $post_id;
		} );
	}

	/**
	 * Copy language-neutral data of a listing (contact meta, coordinates, hours, photo)
	 * into its translations where those are still empty.
	 */
	public static function propagate( $post_id ) {
		$targets = self::translations( $post_id );
		if ( ! $targets ) {
			return 0;
		}
		$thumb   = (int) get_post_thumbnail_id( $post_id );
		$options = get_post_meta( $post_id, 'lp_listingpro_options', true );
		$hours   = is_array( $options ) && ! empty( $options['business_hours'] ) ? $options['business_hours'] : null;
		$logo    = is_array( $options ) && ! empty( $options['business_logo'] ) ? $options['business_logo'] : '';
		$changed = 0;
		foreach ( $targets as $tid ) {
			$touched = false;
			foreach ( self::SHARED_META as $key ) {
				$v = get_post_meta( $post_id, $key, true );
				if ( '' !== $v && null !== $v && '' === (string) get_post_meta( $tid, $key, true ) ) {
					update_post_meta( $tid, $key, $v );
					$touched = true;
				}
			}
			if ( $thumb && ! has_post_thumbnail( $tid ) ) {
				set_post_thumbnail( $tid, $thumb );
				$touched = true;
			}
			if ( $hours || $logo ) {
				$t_opt = get_post_meta( $tid, 'lp_listingpro_options', true );
				$t_opt = is_array( $t_opt ) ? $t_opt : array();
				if ( $hours && empty( $t_opt['business_hours'] ) ) {
					$t_opt['business_hours'] = $hours;
					$touched                 = true;
				}
				if ( $logo && empty( $t_opt['business_logo'] ) ) {
					$t_opt['business_logo'] = $logo;
					$touched                = true;
				}
				update_post_meta( $tid, 'lp_listingpro_options', $t_opt );
			}
			if ( $touched ) {
				KA_LBI_Store::sync_options_meta( $tid );
				$changed++;
			}
		}
		return $changed;
	}

	/** KA Translate is installed and can be asked for language versions. */
	public static function translator_available() {
		return self::active() && function_exists( 'kt_job_add' );
	}

	/** Queue the missing language versions of a published source listing with KA Translate. */
	public static function request_translations( $post_id ) {
		if ( ! KA_LBI_Settings::get( 'translate' ) || ! self::translator_available() || self::is_generated( $post_id ) ) {
			return 0;
		}
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return 0;
		}
		$own = self::post_language( $post_id );
		if ( ! $own ) {
			return 0;
		}
		$n = 0;
		foreach ( array_keys( self::languages() ) as $to ) {
			if ( $to !== $own && ! pll_get_post( $post_id, $to ) ) {
				kt_job_add( 'post:' . (int) $post_id . ':' . $to );
				$n++;
			}
		}
		return $n;
	}

	/** Listings we imported get their language versions as soon as they are published (e.g. approved in the review queue). */
	public static function on_publish( $new, $old, $post ) {
		if ( 'publish' !== $new || 'publish' === $old || KA_LBI_Plugin::POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( '' === (string) get_post_meta( $post->ID, '_ka_lbi_source', true ) ) {
			return;
		}
		self::request_translations( $post->ID );
	}
}
