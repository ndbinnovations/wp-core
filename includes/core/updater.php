<?php
/**
 * GitHub-based plugin updates via Plugin Update Checker.
 *
 * Requires: composer install --no-dev (vendor must be present in release zip).
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Initialize the update checker if the library is available.
 */
function ndbi_core_init_updater() {
	$loader = NDBI_CORE_PATH . 'vendor/autoload.php';
	if ( ! file_exists( $loader ) ) {
		return;
	}

	require_once $loader;

	$factory_class = 'YahnisElsts\PluginUpdateChecker\v5\PucFactory';
	if ( ! class_exists( $factory_class ) ) {
		return;
	}

	$repo_url = apply_filters( 'ndbi_core_github_repo_url', 'https://github.com/ndbinnovations/wp-core' );

	$update_checker = $factory_class::buildUpdateChecker(
		$repo_url,
		NDBI_CORE_PATH . 'ndbi-core.php',
		'ndbi-core'
	);
}

add_action( 'init', 'ndbi_core_init_updater', 0 );
