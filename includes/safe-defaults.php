<?php
/**
 * Optional safe defaults (e.g. disable file editing in admin).
 *
 * Enable by defining NDBI_CORE_SAFE_DEFAULTS in wp-config.php:
 * define( 'NDBI_CORE_SAFE_DEFAULTS', true );
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Apply safe defaults when enabled via constant.
 */
function ndbi_core_apply_safe_defaults() {
	if ( ! defined( 'NDBI_CORE_SAFE_DEFAULTS' ) || ! NDBI_CORE_SAFE_DEFAULTS ) {
		return;
	}

	// Disable file editing in the admin (Theme/Plugin editor).
	if ( ! defined( 'DISALLOW_FILE_EDIT' ) ) {
		define( 'DISALLOW_FILE_EDIT', true );
	}
}

add_action( 'init', 'ndbi_core_apply_safe_defaults', 0 );
