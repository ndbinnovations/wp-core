<?php
/**
 * Plugin Name:     NDB Innovations Core
 * Plugin URI:      https://ndbinnovations.ca
 * Description:     Core plugin for NDB Innovations managed WordPress sites.
 * Author:          NDB Innovations Inc.
 * Author URI:      https://ndbinnovations.ca
 * Text Domain:     ndbi-core
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

// Plugin constants.
define( 'NDBI_CORE_VERSION', '0.1.0' );
define( 'NDBI_CORE_PATH', plugin_dir_path( __FILE__ ) );
define( 'NDBI_CORE_URL', plugin_dir_url( __FILE__ ) );
define( 'NDBI_CORE_BASENAME', plugin_basename( __FILE__ ) );

require_once NDBI_CORE_PATH . 'includes/bootstrap.php';
