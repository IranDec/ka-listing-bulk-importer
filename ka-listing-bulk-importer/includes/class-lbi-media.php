<?php
/**
 * Photos: download (or read from the intake folder), verify, optimize to WebP, attach.
 * Every photo is stored in the Media Library - never hotlinked.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Media {

	const PHOTO_DIR_NAME  = 'ka-lbi-photos';
	const MAX_IMAGE_BYTES = 10485760; // 10 MB

	/** Intake folder for photos referenced by file name in a CSV. */
	public static function photo_dir() {
		$dir = trailingslashit( wp_upload_dir()['basedir'] ) . self::PHOTO_DIR_NAME;
		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $dir;
	}

	/**
	 * Turn a photo value into an attachment id.
	 * - "gplace:places/…/photos/…" : a Google Places photo, resolved only now (costs one request)
	 * - http(s) URL                 : downloaded through the WordPress HTTP API (SSRF-safe)
	 * - anything else               : a file name inside the intake folder
	 *
	 * @return int|WP_Error
	 */
	public static function import( $value, $post_id ) {
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return new WP_Error( 'ka_lbi_empty_photo', __( 'Empty photo value', 'ka-listing-bulk-importer' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( 0 === strpos( $value, 'gplace:' ) ) {
			$url = KA_LBI_Google::photo_url( substr( $value, 7 ) );
			if ( is_wp_error( $url ) ) {
				return $url;
			}
			return self::sideload_remote( $url, $post_id, sanitize_title( get_the_title( $post_id ) ) . '.jpg' );
		}
		if ( preg_match( '#^https?://#i', $value ) ) {
			return self::sideload_remote( $value, $post_id );
		}
		return self::sideload_local( $value, $post_id );
	}

	private static function sideload_remote( $url, $post_id, $name = '' ) {
		$url = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( ! $url || ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'ka_lbi_bad_url', __( 'Not a valid public URL', 'ka-listing-bulk-importer' ) );
		}
		$tmp = download_url( $url, 15 );
		if ( is_wp_error( $tmp ) ) {
			return $tmp;
		}
		if ( filesize( $tmp ) > self::MAX_IMAGE_BYTES ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ka_lbi_too_big', __( 'Image is larger than 10 MB', 'ka-listing-bulk-importer' ) );
		}
		$info = @getimagesize( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || ! in_array( $info['mime'], array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ka_lbi_not_image', __( 'The file is not a recognizable image', 'ka-listing-bulk-importer' ) );
		}
		if ( '' === $name ) {
			$name = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		}
		$ext = array( 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp' )[ $info['mime'] ];
		if ( '' === $name || ! preg_match( '/\.(jpe?g|png|gif|webp)$/i', $name ) ) {
			$name = ( $name ? preg_replace( '/\.[^.]*$/', '', $name ) : 'photo-' . wp_generate_password( 6, false ) ) . '.' . $ext;
		}

		$optimized = self::optimize( $tmp, $name );
		if ( $optimized ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$tmp  = $optimized['path'];
			$name = $optimized['name'];
		}
		$aid = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id );
		if ( is_wp_error( $aid ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $aid;
	}

	private static function sideload_local( $filename, $post_id ) {
		$base = realpath( self::photo_dir() );
		if ( ! $base ) {
			return new WP_Error( 'ka_lbi_no_dir', __( 'Photo intake folder is missing', 'ka-listing-bulk-importer' ) );
		}
		// No path traversal: the resolved file must stay inside the intake folder.
		$resolved = realpath( $base . '/' . ltrim( str_replace( '\\', '/', $filename ), '/' ) );
		if ( ! $resolved || 0 !== strpos( $resolved, $base . DIRECTORY_SEPARATOR ) || ! is_file( $resolved ) ) {
			return new WP_Error( 'ka_lbi_missing', __( 'File not found in the photo folder', 'ka-listing-bulk-importer' ) );
		}
		if ( filesize( $resolved ) > self::MAX_IMAGE_BYTES ) {
			return new WP_Error( 'ka_lbi_too_big', __( 'Image is larger than 10 MB', 'ka-listing-bulk-importer' ) );
		}
		$check = wp_check_filetype_and_ext( $resolved, basename( $resolved ) );
		if ( empty( $check['type'] ) || 0 !== strpos( $check['type'], 'image/' ) || ! @getimagesize( $resolved ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return new WP_Error( 'ka_lbi_not_image', __( 'This is not a recognizable image file', 'ka-listing-bulk-importer' ) );
		}
		// Work on a copy so the intake file stays where the admin put it.
		$tmp = wp_tempnam( basename( $resolved ) );
		if ( ! $tmp || ! copy( $resolved, $tmp ) ) {
			return new WP_Error( 'ka_lbi_read_failed', __( 'Could not read the photo file', 'ka-listing-bulk-importer' ) );
		}
		$name      = basename( $resolved );
		$optimized = self::optimize( $tmp, $name );
		if ( $optimized ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$tmp  = $optimized['path'];
			$name = $optimized['name'];
		}
		$aid = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id );
		if ( is_wp_error( $aid ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		return $aid;
	}

	/**
	 * Downscale to the configured width and re-encode as WebP with the host's own image
	 * library. Never upscales, never touches GIFs (animation), keeps the original when the
	 * WebP copy would not be smaller.
	 *
	 * @return array|false path, name
	 */
	public static function optimize( $source, $original_name ) {
		if ( '1' !== get_option( KA_LBI_Settings::OPTION_OPTIMIZE_PHOTOS, '1' ) ) {
			return false;
		}
		$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $info || 'image/gif' === $info['mime'] ) {
			return false;
		}
		$max_width = (int) get_option( KA_LBI_Settings::OPTION_OPTIMIZE_MAX_WIDTH, 1600 );
		$quality   = (int) get_option( KA_LBI_Settings::OPTION_OPTIMIZE_QUALITY, 82 );
		$editor    = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) || ! $editor->supports_mime_type( 'image/webp' ) ) {
			return false;
		}
		$size     = $editor->get_size();
		$was_wide = $size && $size['width'] > max( 200, $max_width );
		if ( $was_wide ) {
			$editor->resize( $max_width, null, false );
		}
		$editor->set_quality( ( $quality > 0 && $quality <= 100 ) ? $quality : 82 );
		$dir   = dirname( $source );
		$name  = preg_replace( '/\.[^.]+$/', '', sanitize_file_name( $original_name ) ) . '.webp';
		$saved = $editor->save( trailingslashit( $dir ) . wp_unique_filename( $dir, 'opt-' . $name ), 'image/webp' );
		if ( is_wp_error( $saved ) || empty( $saved['path'] ) || ! file_exists( $saved['path'] ) ) {
			return false;
		}
		if ( ! $was_wide && filesize( $saved['path'] ) >= filesize( $source ) ) {
			@unlink( $saved['path'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			return false;
		}
		return array( 'path' => $saved['path'], 'name' => $name );
	}
}
