<?php
/**
 * Plugin Name:       KA Schindler - Listing Bulk Importer
 * Plugin URI:        https://adschi.com
 * Description:       Grow a ListingPro directory: discover businesses with Google Places (New) or AI, import CSV files, review new listings in one queue, enrich existing ones from their own websites, find gaps and duplicates, and keep fa/de/en versions in step with Polylang.
 * Version:           4.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Mohammad Babaei
 * Author URI:        https://adschi.com
 * Text Domain:       ka-listing-bulk-importer
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KA_LBI_VERSION', '4.0.0' );
define( 'KA_LBI_FILE', __FILE__ );
define( 'KA_LBI_DIR', plugin_dir_path( __FILE__ ) );
define( 'KA_LBI_URL', plugin_dir_url( __FILE__ ) );

foreach ( array( 'plugin', 'settings', 'lang', 'store', 'media', 'google', 'providers', 'batch', 'csv', 'jobs', 'discover', 'enrich', 'quality', 'review', 'schedules', 'admin' ) as $ka_lbi_file ) {
	require_once KA_LBI_DIR . 'includes/class-lbi-' . $ka_lbi_file . '.php';
}
unset( $ka_lbi_file );

register_activation_hook( __FILE__, array( 'KA_LBI_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'KA_LBI_Plugin', 'deactivate' ) );
add_action( 'plugins_loaded', array( 'KA_LBI_Plugin', 'init' ) );
