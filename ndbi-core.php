<?php
/**
 * Plugin Name:     NDB Innovations Core
 * Plugin URI:      https://ndbinnovations.ca
 * Description:     Core plugin for NDB Innovations managed WordPress sites.
 * Author:          NDB Innovations Inc.
 * Author URI:      https://ndbinnovations.ca
 * Text Domain:     ndbi-core
 * Domain Path:     /languages
 * Version:         0.2.0
 *
 * @package         Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'NDBI_CORE_VERSION', '0.2.0' );
define( 'NDBI_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NDBI_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'NDBI_CORE_BASENAME', plugin_basename( __FILE__ ) );

// Load translations at init so they are available before any feature code runs (WP 6.7+).
add_action( 'init', 'ndbi_core_load_textdomain', 0 );

/**
 * Load plugin text domain for translations.
 */
function ndbi_core_load_textdomain() {
	load_plugin_textdomain(
		'ndbi-core',
		false,
		dirname( NDBI_CORE_BASENAME ) . '/languages'
	);
}

require_once NDBI_CORE_PATH . 'includes/bootstrap.php';
