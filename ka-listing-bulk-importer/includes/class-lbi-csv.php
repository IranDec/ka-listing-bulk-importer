<?php
/**
 * CSV upload, column mapping, template and preview export.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KA_LBI_Csv {

	const MAX_BYTES = 5242880; // 5 MB
	const MAX_ROWS  = 2000;

	private static function key() {
		return 'ka_lbi_' . get_current_user_id() . '_csv';
	}

	public static function uploaded() {
		$c = get_transient( self::key() );
		return is_array( $c ) && ! empty( $c['rows'] ) ? $c : null;
	}

	public static function handle_upload() {
		KA_LBI_Plugin::guard();
		$file = isset( $_FILES['ka_lbi_file'] ) ? $_FILES['ka_lbi_file'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $file || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) || ! empty( $file['error'] ) ) {
			KA_LBI_Plugin::fail( __( 'No file was uploaded.', 'ka-listing-bulk-importer' ), 'ka-lbi-import' );
		}
		if ( $file['size'] > self::MAX_BYTES ) {
			/* translators: %d: megabytes */
			KA_LBI_Plugin::fail( sprintf( __( 'The file is too large. Maximum size is %d MB.', 'ka-listing-bulk-importer' ), self::MAX_BYTES / 1048576 ), 'ka-lbi-import' );
		}
		if ( 'csv' !== strtolower( pathinfo( sanitize_file_name( $file['name'] ), PATHINFO_EXTENSION ) ) ) {
			KA_LBI_Plugin::fail( __( 'Please upload a .csv file.', 'ka-listing-bulk-importer' ), 'ka-lbi-import' );
		}
		if ( function_exists( 'finfo_open' ) ) {
			$fi   = finfo_open( FILEINFO_MIME_TYPE );
			$mime = finfo_file( $fi, $file['tmp_name'] );
			finfo_close( $fi );
			if ( $mime && ! in_array( $mime, array( 'text/csv', 'text/plain', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'text/html' ), true ) ) {
				KA_LBI_Plugin::fail( __( 'This does not look like a CSV file.', 'ka-listing-bulk-importer' ), 'ka-lbi-import' );
			}
		}
		$parsed = self::parse( $file['tmp_name'] );
		if ( is_wp_error( $parsed ) ) {
			KA_LBI_Plugin::fail( $parsed->get_error_message(), 'ka-lbi-import' );
		}
		list( $headers, $rows ) = $parsed;
		if ( ! $rows ) {
			KA_LBI_Plugin::fail( __( 'The CSV file has no data rows.', 'ka-listing-bulk-importer' ), 'ka-lbi-import' );
		}
		if ( count( $rows ) > self::MAX_ROWS ) {
			/* translators: %d: maximum rows */
			KA_LBI_Plugin::fail( sprintf( __( 'Too many rows. Please split the file into parts of %d rows or fewer.', 'ka-listing-bulk-importer' ), self::MAX_ROWS ), 'ka-lbi-import' );
		}
		$status = isset( $_POST['ka_lbi_status'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_status'] ) ) : 'pending';
		set_transient( self::key(), array(
			'name'    => sanitize_file_name( $file['name'] ),
			'headers' => $headers,
			'rows'    => $rows,
			'status'  => in_array( $status, array( 'pending', 'draft', 'publish' ), true ) ? $status : 'pending',
			'lang'    => KA_LBI_Lang::import_language( isset( $_POST['ka_lbi_lang'] ) ? sanitize_key( wp_unslash( $_POST['ka_lbi_lang'] ) ) : '' ),
		), KA_LBI_Batch::TTL );
		wp_safe_redirect( KA_LBI_Plugin::url( 'ka-lbi-import', array( 'step' => 'map' ) ) );
		exit;
	}

	/** Comma, semicolon (German Excel) or tab separated; UTF-8 with or without BOM. */
	public static function parse( $path ) {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( false === $raw ) {
			return new WP_Error( 'ka_lbi_open', __( 'Could not open the uploaded file.', 'ka-listing-bulk-importer' ) );
		}
		$raw = preg_replace( '/^\xEF\xBB\xBF/', '', $raw );
		if ( ! preg_match( '//u', $raw ) && function_exists( 'mb_convert_encoding' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1252' ); // old Excel exports
		}
		$first = strtok( $raw, "\n" );
		$delim = ',';
		foreach ( array( ';', "\t" ) as $d ) {
			if ( substr_count( (string) $first, $d ) > substr_count( (string) $first, $delim ) ) {
				$delim = $d;
			}
		}
		$h = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $h, $raw ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		rewind( $h );
		$headers = fgetcsv( $h, 0, $delim, '"', '\\' );
		if ( ! $headers ) {
			fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return new WP_Error( 'ka_lbi_headers', __( 'Could not read a header row from the CSV.', 'ka-listing-bulk-importer' ) );
		}
		$headers = array_map( function ( $x ) {
			return trim( (string) $x );
		}, $headers );
		// Empty or repeated header names would overwrite each other.
		foreach ( $headers as $i => $name ) {
			if ( '' === $name ) {
				/* translators: %d: column number */
				$headers[ $i ] = sprintf( __( 'Column %d', 'ka-listing-bulk-importer' ), $i + 1 );
			}
		}
		$headers = self::unique( $headers );
		$rows    = array();
		while ( false !== ( $data = fgetcsv( $h, 0, $delim, '"', '\\' ) ) ) {
			if ( 1 === count( $data ) && ( null === $data[0] || '' === trim( (string) $data[0] ) ) ) {
				continue;
			}
			$row = array();
			foreach ( $headers as $i => $name ) {
				$row[ $name ] = isset( $data[ $i ] ) ? trim( (string) $data[ $i ] ) : '';
			}
			$rows[] = $row;
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return array( $headers, $rows );
	}

	private static function unique( array $names ) {
		$seen = array();
		foreach ( $names as $i => $n ) {
			$base = $n;
			$k    = 2;
			while ( isset( $seen[ $n ] ) ) {
				$n = $base . ' (' . $k++ . ')';
			}
			$seen[ $n ]  = true;
			$names[ $i ] = $n;
		}
		return $names;
	}

	/** Best guess of the field for a column name (English, German, Persian headers). */
	public static function guess( $column ) {
		$aliases = array(
			'post_title'     => array( 'title', 'name', 'business', 'business name', 'firma', 'firmenname', 'unternehmen', 'نام', 'عنوان' ),
			'post_content'   => array( 'description', 'desc', 'about', 'beschreibung', 'توضیحات' ),
			'category'       => array( 'category', 'cat', 'kategorie', 'branche', 'دسته', 'دسته‌بندی' ),
			'location'       => array( 'location', 'city', 'town', 'stadt', 'ort', 'شهر' ),
			'tags'           => array( 'tags', 'tag', 'schlagworte' ),
			'gAddress'       => array( 'address', 'street', 'adresse', 'anschrift', 'straße', 'آدرس', 'نشانی' ),
			'phone'          => array( 'phone', 'tel', 'telephone', 'mobile', 'telefon', 'handy', 'تلفن' ),
			'whatsapp'       => array( 'whatsapp', 'واتساپ' ),
			'email'          => array( 'email', 'e-mail', 'mail', 'ایمیل' ),
			'website'        => array( 'website', 'url', 'site', 'webseite', 'homepage', 'internet', 'وبسایت', 'وب‌سایت' ),
			'facebook'       => array( 'facebook' ),
			'instagram'      => array( 'instagram', 'اینستاگرام' ),
			'linkedin'       => array( 'linkedin' ),
			'twitter'        => array( 'twitter', 'x' ),
			'youtube'        => array( 'youtube channel', 'youtube' ),
			'video'          => array( 'video', 'youtube video' ),
			'tagline_text'   => array( 'tagline', 'slogan' ),
			'latitude'       => array( 'latitude', 'lat', 'breitengrad' ),
			'longitude'      => array( 'longitude', 'lng', 'long', 'lon', 'längengrad' ),
			'featured_photo' => array( 'photo', 'image', 'picture', 'featured image', 'bild', 'foto', 'عکس' ),
			'gallery_photos' => array( 'gallery', 'photos', 'images', 'gallery photos', 'bilder', 'fotos', 'گالری' ),
		);
		$norm = KA_LBI_Lang::norm( $column );
		foreach ( $aliases as $field => $names ) {
			if ( in_array( $norm, $names, true ) ) {
				return $field;
			}
		}
		return '';
	}

	/** Mapping submitted -> preview batch. */
	public static function handle_map() {
		KA_LBI_Plugin::guard();
		$csv = self::uploaded();
		if ( ! $csv ) {
			KA_LBI_Plugin::fail( __( 'The uploaded file has expired. Please upload it again.', 'ka-listing-bulk-importer' ), 'ka-lbi-import' );
		}
		// Not sanitize_key(): it would lowercase "gAddress". Values are checked against the whitelist.
		$map   = isset( $_POST['ka_lbi_map'] ) ? (array) wp_unslash( $_POST['ka_lbi_map'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$valid = array_keys( KA_LBI_Store::importable_fields() );
		$clean = array();
		foreach ( $map as $col => $field ) {
			if ( is_string( $field ) && in_array( $field, $valid, true ) && in_array( (string) $col, $csv['headers'], true ) ) {
				$clean[ (string) $col ] = $field;
			}
		}
		if ( ! in_array( 'post_title', $clean, true ) ) {
			KA_LBI_Plugin::back( 'ka-lbi-import', __( 'Please map one column to "Title".', 'ka-listing-bulk-importer' ), 'error', array( 'step' => 'map' ) );
		}
		$default_location = isset( $_POST['ka_lbi_default_location'] ) ? sanitize_text_field( wp_unslash( $_POST['ka_lbi_default_location'] ) ) : '';
		$default_category = isset( $_POST['ka_lbi_default_category'] ) ? sanitize_text_field( wp_unslash( $_POST['ka_lbi_default_category'] ) ) : '';
		$rows             = array();
		foreach ( $csv['rows'] as $row ) {
			$fields = array();
			foreach ( $clean as $col => $field ) {
				if ( isset( $row[ $col ] ) && '' !== $row[ $col ] ) {
					$fields[ $field ] = $row[ $col ];
				}
			}
			if ( empty( $fields['location'] ) && $default_location ) {
				$fields['location'] = $default_location;
			}
			if ( empty( $fields['category'] ) && $default_category ) {
				$fields['category'] = $default_category;
			}
			$rows[] = $fields;
		}
		KA_LBI_Batch::create( $rows, array(
			'source' => 'csv',
			'status' => $csv['status'],
			'lang'   => $csv['lang'],
			/* translators: %s: file name */
			'note'   => sprintf( __( 'Rows from %s.', 'ka-listing-bulk-importer' ), $csv['name'] ),
		) );
		delete_transient( self::key() );
		wp_safe_redirect( KA_LBI_Plugin::url( 'ka-lbi-import', array( 'step' => 'preview' ) ) );
		exit;
	}

	private static function output_headers( $filename ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo "\xEF\xBB\xBF"; // Excel needs the BOM for Persian/German text
	}

	/** Spreadsheet formulas in a cell would run when the file is opened in Excel. */
	private static function cell( $v ) {
		$v = is_scalar( $v ) ? (string) $v : '';
		return preg_match( '/^[=+\-@\t\r]/', $v ) ? "'" . $v : $v;
	}

	public static function handle_template() {
		KA_LBI_Plugin::guard();
		self::output_headers( 'ka-listing-import-template.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array( 'Title', 'Description', 'Category', 'Location', 'Tags', 'Address', 'Phone', 'Whatsapp', 'Email', 'Website', 'Instagram', 'Photo', 'Gallery' ), ',', '"', '\\' );
		fputcsv( $out, array( 'Restaurant Sabzi', 'Persisches Restaurant in Berlin-Mitte.', 'restaurant-food', 'berlin', '', 'Luisenstraße 15, 10117 Berlin', '030 12345678', '', 'info@example.de', 'https://example.de', '', 'sabzi-front.jpg', 'sabzi-inside.jpg|https://example.com/sabzi-dish.jpg' ), ',', '"', '\\' );
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	public static function handle_export_preview() {
		KA_LBI_Plugin::guard();
		$batch = KA_LBI_Batch::get();
		if ( ! $batch ) {
			KA_LBI_Plugin::fail( __( 'Nothing to export - the preview has expired.', 'ka-listing-bulk-importer' ), 'ka-lbi-import' );
		}
		$keys = array_keys( KA_LBI_Store::importable_fields() );
		self::output_headers( 'ka-listing-preview-' . gmdate( 'Y-m-d-His' ) . '.csv' );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fputcsv( $out, array_merge( array( 'Row', 'Possible duplicate', 'Google place id' ), $keys ), ',', '"', '\\' );
		foreach ( $batch['records'] as $r ) {
			$line = array( $r['row'] + 1, $r['duplicates'] ? $r['duplicates'][0]['title'] : '', isset( $r['fields']['place_id'] ) ? $r['fields']['place_id'] : '' );
			foreach ( $keys as $k ) {
				$v      = isset( $r['fields'][ $k ] ) ? $r['fields'][ $k ] : '';
				$line[] = ( 'featured_photo' === $k && 0 === strpos( (string) $v, 'gplace:' ) ) ? 'Google photo' : $v;
			}
			fputcsv( $out, array_map( array( __CLASS__, 'cell' ), $line ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}
}
