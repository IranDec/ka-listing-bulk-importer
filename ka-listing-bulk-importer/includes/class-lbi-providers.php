<?php
/**
 * Data sources for Discover: Google Places (live data) and three AI providers
 * (model knowledge, always flagged for verification). Keys, models, cost estimates
 * and spend counters keep their 3.x option names.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Providers {

	const OPTION_KEY       = 'ka_lbi_key_';
	const OPTION_MODEL     = 'ka_lbi_model_';
	const OPTION_STATUS    = 'ka_lbi_status_';
	const OPTION_COST      = 'ka_lbi_cost_';
	const OPTION_MODELLIST = 'ka_lbi_modellist_';
	const OPTION_SPEND     = 'ka_lbi_spend_';

	public static function all() {
		return array(
			'google' => array( 'label' => 'Google Maps', 'long' => __( 'Google Places API (New) - live business data', 'ka-listing-bulk-importer' ), 'is_ai' => false, 'default_model' => '' ),
			'gemini' => array( 'label' => 'Gemini', 'long' => __( 'Gemini (Google AI) - suggestions from model knowledge', 'ka-listing-bulk-importer' ), 'is_ai' => true, 'default_model' => 'gemini-2.5-flash' ),
			'claude' => array( 'label' => 'Claude', 'long' => __( 'Claude (Anthropic) - suggestions from model knowledge', 'ka-listing-bulk-importer' ), 'is_ai' => true, 'default_model' => 'claude-sonnet-4-5' ),
			'openai' => array( 'label' => 'ChatGPT', 'long' => __( 'ChatGPT (OpenAI) - suggestions from model knowledge', 'ka-listing-bulk-importer' ), 'is_ai' => true, 'default_model' => 'gpt-4o-mini' ),
		);
	}

	public static function exists( $slug ) {
		return isset( self::all()[ $slug ] );
	}

	public static function label( $slug ) {
		$all = self::all();
		return isset( $all[ $slug ] ) ? $all[ $slug ]['label'] : (string) $slug;
	}

	public static function is_ai( $slug ) {
		$all = self::all();
		return ! empty( $all[ $slug ]['is_ai'] );
	}

	public static function key( $slug ) {
		return (string) get_option( self::OPTION_KEY . $slug, '' );
	}

	public static function model( $slug ) {
		$all = self::all();
		return self::is_ai( $slug ) ? (string) get_option( self::OPTION_MODEL . $slug, $all[ $slug ]['default_model'] ) : '';
	}

	/** Last key test: array ok, message, checked - or null. */
	public static function status( $slug ) {
		$s = get_option( self::OPTION_STATUS . $slug, null );
		return is_array( $s ) ? $s : null;
	}

	public static function cost_key( $slug, $model = '' ) {
		return self::OPTION_COST . $slug . ( $model ? '_' . sanitize_key( $model ) : '' );
	}

	/** Admin-entered USD estimate per result for the active model. */
	public static function cost( $slug ) {
		return (float) get_option( self::cost_key( $slug, self::model( $slug ) ), 0 );
	}

	public static function spend( $slug ) {
		return (float) get_option( self::OPTION_SPEND . $slug, 0 );
	}

	public static function add_spend( $slug, $results ) {
		$cost = self::cost( $slug );
		if ( $cost > 0 && $results > 0 ) {
			update_option( self::OPTION_SPEND . $slug, self::spend( $slug ) + $cost * $results, false );
		}
	}

	/** Providers that have a key and did not fail their last test. */
	public static function usable() {
		$out = array();
		foreach ( array_keys( self::all() ) as $slug ) {
			$st = self::status( $slug );
			if ( '' !== self::key( $slug ) && ( ! $st || ! empty( $st['ok'] ) ) ) {
				$out[] = $slug;
			}
		}
		return $out;
	}

	/** Save keys/models/costs from the settings form and test every key. */
	public static function save( array $keys, array $models, array $costs ) {
		foreach ( self::all() as $slug => $def ) {
			// Keys are never printed back into the form: empty means "keep the saved key".
			$posted  = isset( $keys[ $slug ] ) ? trim( preg_replace( '/[^A-Za-z0-9_\-\.]/', '', (string) $keys[ $slug ] ) ) : '';
			$old_key = self::key( $slug );
			$key     = ! empty( $keys[ 'remove_' . $slug ] ) ? '' : ( '' !== $posted ? $posted : $old_key );
			update_option( self::OPTION_KEY . $slug, $key, false );

			$model = '';
			if ( $def['is_ai'] ) {
				$model = isset( $models[ $slug ] ) ? sanitize_text_field( $models[ $slug ] ) : '';
				$model = $model ? $model : $def['default_model'];
				update_option( self::OPTION_MODEL . $slug, $model, false );
			}
			$cost_key = self::cost_key( $slug, $model );
			if ( isset( $costs[ $slug ] ) && '' !== trim( (string) $costs[ $slug ] ) && is_numeric( $costs[ $slug ] ) ) {
				update_option( $cost_key, max( 0, (float) $costs[ $slug ] ), false );
			} else {
				delete_option( $cost_key );
			}

			if ( '' === $key ) {
				delete_option( self::OPTION_STATUS . $slug );
				continue;
			}
			// Test again only when the key changed or was never tested successfully.
			$st = self::status( $slug );
			if ( $st && ! empty( $st['ok'] ) && $old_key === $key ) {
				continue;
			}
			self::test( $slug );
		}
	}

	public static function test( $slug ) {
		$key    = self::key( $slug );
		$result = 'google' === $slug ? KA_LBI_Google::test_key( $key ) : self::test_ai( $slug, $key, self::model( $slug ) );
		update_option( self::OPTION_STATUS . $slug, array( 'ok' => (bool) $result['ok'], 'message' => $result['message'], 'checked' => current_time( 'mysql' ) ), false );
		if ( $result['ok'] && self::is_ai( $slug ) ) {
			$list = self::list_models( $slug, $key );
			if ( ! is_wp_error( $list ) && $list ) {
				update_option( self::OPTION_MODELLIST . $slug, $list, false );
			}
		}
		return $result;
	}

	public static function model_list( $slug ) {
		$l = get_option( self::OPTION_MODELLIST . $slug, array() );
		return is_array( $l ) ? $l : array();
	}

	/* ------------------------------------------------------------------ AI */

	private static function http( $method, $url, array $headers, $body = null, $timeout = 60 ) {
		$args = array( 'timeout' => $timeout, 'headers' => $headers );
		if ( null !== $body ) {
			$args['body'] = wp_json_encode( $body );
		}
		$r = 'POST' === $method ? wp_remote_post( $url, $args ) : wp_remote_get( $url, $args );
		if ( is_wp_error( $r ) ) {
			return $r;
		}
		$code = (int) wp_remote_retrieve_response_code( $r );
		$data = json_decode( wp_remote_retrieve_body( $r ), true );
		if ( $code < 200 || $code >= 300 ) {
			$msg = is_array( $data ) && ! empty( $data['error']['message'] ) ? $data['error']['message'] : ( is_array( $data ) && is_string( $data['error'] ?? null ) ? $data['error'] : '' );
			if ( 401 === $code || 403 === $code ) {
				/* translators: 1: HTTP status, 2: provider message */
				return new WP_Error( 'ka_lbi_ai_key', sprintf( __( 'The key was rejected (HTTP %1$d). %2$s', 'ka-listing-bulk-importer' ), $code, $msg ) );
			}
			if ( 429 === $code ) {
				return new WP_Error( 'ka_lbi_ai_quota', __( 'Rate limited - the account has hit a quota right now.', 'ka-listing-bulk-importer' ) );
			}
			/* translators: 1: HTTP status, 2: provider message */
			return new WP_Error( 'ka_lbi_ai_http', sprintf( __( 'Unexpected response (HTTP %1$d). %2$s', 'ka-listing-bulk-importer' ), $code, $msg ) );
		}
		return is_array( $data ) ? $data : array();
	}

	private static function test_ai( $slug, $key, $model ) {
		switch ( $slug ) {
			case 'claude':
				$r = self::http( 'GET', 'https://api.anthropic.com/v1/models?limit=1', array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ), null, 20 );
				break;
			case 'gemini':
				$r = self::http( 'GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=1', array( 'x-goog-api-key' => $key ), null, 20 );
				break;
			default:
				$r = self::http( 'GET', 'https://api.openai.com/v1/models', array( 'Authorization' => 'Bearer ' . $key ), null, 20 );
		}
		if ( is_wp_error( $r ) ) {
			return array( 'ok' => false, 'message' => $r->get_error_message() );
		}
		return array( 'ok' => true, 'message' => __( 'The provider accepted the key.', 'ka-listing-bulk-importer' ) );
	}

	/** @return array[]|WP_Error list of [id => ...] */
	public static function list_models( $slug, $key ) {
		$out = array();
		switch ( $slug ) {
			case 'claude':
				$r = self::http( 'GET', 'https://api.anthropic.com/v1/models?limit=100', array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01' ), null, 20 );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				foreach ( isset( $r['data'] ) ? (array) $r['data'] : array() as $m ) {
					if ( ! empty( $m['id'] ) ) {
						$out[] = array( 'id' => $m['id'] );
					}
				}
				break;
			case 'gemini':
				$r = self::http( 'GET', 'https://generativelanguage.googleapis.com/v1beta/models?pageSize=200', array( 'x-goog-api-key' => $key ), null, 20 );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				foreach ( isset( $r['models'] ) ? (array) $r['models'] : array() as $m ) {
					if ( in_array( 'generateContent', isset( $m['supportedGenerationMethods'] ) ? (array) $m['supportedGenerationMethods'] : array(), true ) && ! empty( $m['name'] ) && ! preg_match( '/(tts|image|embedding|audio|live)/i', $m['name'] ) ) {
						$out[] = array( 'id' => preg_replace( '#^models/#', '', $m['name'] ) );
					}
				}
				break;
			case 'openai':
				$r = self::http( 'GET', 'https://api.openai.com/v1/models', array( 'Authorization' => 'Bearer ' . $key ), null, 20 );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
				foreach ( isset( $r['data'] ) ? (array) $r['data'] : array() as $m ) {
					$id = isset( $m['id'] ) ? $m['id'] : '';
					if ( $id && preg_match( '/^(gpt-|o[0-9]|chatgpt)/i', $id ) && ! preg_match( '/(embedding|whisper|tts|dall-e|moderation|audio|realtime|transcribe|image)/i', $id ) ) {
						$out[] = array( 'id' => $id );
					}
				}
				break;
		}
		usort( $out, function ( $a, $b ) {
			return strcmp( $a['id'], $b['id'] );
		} );
		return $out;
	}

	private static function ask( $slug, $prompt ) {
		$key   = self::key( $slug );
		$model = self::model( $slug );
		switch ( $slug ) {
			case 'claude':
				$r = self::http( 'POST', 'https://api.anthropic.com/v1/messages', array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' ), array(
					'model'      => $model,
					'max_tokens' => 4000,
					'messages'   => array( array( 'role' => 'user', 'content' => $prompt ) ),
				) );
				return is_wp_error( $r ) ? $r : ( isset( $r['content'][0]['text'] ) ? $r['content'][0]['text'] : new WP_Error( 'ka_lbi_ai', __( 'Claude returned no usable reply.', 'ka-listing-bulk-importer' ) ) );
			case 'gemini':
				$r = self::http( 'POST', 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent', array( 'x-goog-api-key' => $key, 'content-type' => 'application/json' ), array(
					'contents'         => array( array( 'parts' => array( array( 'text' => $prompt ) ) ) ),
					'generationConfig' => array( 'responseMimeType' => 'application/json', 'temperature' => 0.2 ),
				) );
				return is_wp_error( $r ) ? $r : ( isset( $r['candidates'][0]['content']['parts'][0]['text'] ) ? $r['candidates'][0]['content']['parts'][0]['text'] : new WP_Error( 'ka_lbi_ai', __( 'Gemini returned no usable reply.', 'ka-listing-bulk-importer' ) ) );
			default:
				$r = self::http( 'POST', 'https://api.openai.com/v1/chat/completions', array( 'Authorization' => 'Bearer ' . $key, 'content-type' => 'application/json' ), array(
					'model'       => $model,
					'messages'    => array( array( 'role' => 'user', 'content' => $prompt ) ),
					'temperature' => 0.2,
				) );
				return is_wp_error( $r ) ? $r : ( isset( $r['choices'][0]['message']['content'] ) ? $r['choices'][0]['message']['content'] : new WP_Error( 'ka_lbi_ai', __( 'ChatGPT returned no usable reply.', 'ka-listing-bulk-importer' ) ) );
		}
	}

	/** First JSON array in a reply, tolerating prose or code fences around it. */
	public static function json_array( $text ) {
		$text    = trim( (string) $text );
		$decoded = json_decode( $text, true );
		if ( is_array( $decoded ) ) {
			return isset( $decoded['businesses'] ) && is_array( $decoded['businesses'] ) ? $decoded['businesses'] : $decoded;
		}
		if ( preg_match( '/\[.*\]/s', $text, $m ) ) {
			$decoded = json_decode( $m[0], true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}
		return null;
	}

	/* ------------------------------------------------------------------ discovery */

	/**
	 * Search one city for one category.
	 *
	 * @param string       $city_search City name as used in the query (German).
	 * @param WP_Term      $category    Category term in the import language.
	 * @return array[]|WP_Error field arrays (category set to the term slug)
	 */
	public static function discover( $slug, $city_search, $category, $max ) {
		$cat_search = KA_LBI_Lang::search_name( $category );
		if ( 'google' === $slug ) {
			$query  = strtr( (string) KA_LBI_Settings::get( 'google_query' ), array( '{category}' => $cat_search, '{city}' => $city_search ) );
			$places = KA_LBI_Google::search( trim( preg_replace( '/\s+/', ' ', $query ) ), $max );
			if ( is_wp_error( $places ) ) {
				return $places;
			}
			$rows = array();
			foreach ( $places as $p ) {
				$f = KA_LBI_Google::to_fields( $p );
				if ( KA_LBI_Settings::get( 'skip_closed' ) && in_array( $f['business_status'], array( 'CLOSED_PERMANENTLY', 'CLOSED_TEMPORARILY' ), true ) ) {
					continue;
				}
				$f['category'] = $category->slug;
				$rows[]        = $f;
			}
			return $rows;
		}

		$prompt = sprintf(
			"List up to %1\$d real, currently operating Persian/Iranian-run or Persian-speaking businesses in the category \"%2\$s\" located in or very near %3\$s, Germany.\n" .
			"Only include businesses you are confident actually exist - returning fewer than %1\$d, or an empty array, is better than inventing one.\n" .
			"Never invent a street address, phone number or website; use an empty string when unsure.\n" .
			'Answer with ONLY a JSON array, no prose, items shaped exactly like {"name":"","address":"","phone":"","website":"","description":"one short sentence"}.',
			(int) $max,
			$cat_search,
			$city_search
		);
		$text = self::ask( $slug, $prompt );
		if ( is_wp_error( $text ) ) {
			return $text;
		}
		$items = self::json_array( $text );
		if ( null === $items ) {
			return new WP_Error( 'ka_lbi_ai_parse', __( 'The AI reply could not be read as a list of businesses. Try again or switch source.', 'ka-listing-bulk-importer' ) );
		}
		$rows = array();
		foreach ( array_slice( $items, 0, $max ) as $item ) {
			if ( ! is_array( $item ) || empty( $item['name'] ) ) {
				continue;
			}
			$f = array(
				'post_title' => sanitize_text_field( $item['name'] ),
				'category'   => $category->slug,
				'ai'         => 1,
			);
			if ( ! empty( $item['description'] ) ) {
				$f['post_content'] = sanitize_text_field( $item['description'] );
			}
			if ( ! empty( $item['address'] ) ) {
				$f['gAddress'] = sanitize_text_field( $item['address'] );
			}
			if ( ! empty( $item['phone'] ) ) {
				$f['phone'] = sanitize_text_field( $item['phone'] );
			}
			if ( ! empty( $item['website'] ) && filter_var( $item['website'], FILTER_VALIDATE_URL ) ) {
				$f['website'] = esc_url_raw( $item['website'] );
			}
			$rows[] = $f;
		}
		return $rows;
	}
}
