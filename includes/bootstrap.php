<?php
/**
 * Bootstrap: load feature modules and register hooks.
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run on plugin activation.
 */
function ndbi_core_activate() {
	// Reserved for future use (e.g. flush rewrite rules, set options).
}

/**
 * Run on plugin deactivation.
 */
function ndbi_core_deactivate() {
	// Reserved for future use.
}

register_activation_hook( NDBI_CORE_PATH . 'ndbi-core.php', 'ndbi_core_activate' );
register_deactivation_hook( NDBI_CORE_PATH . 'ndbi-core.php', 'ndbi_core_deactivate' );

// Config must load first so feature checks work.
require_once NDBI_CORE_PATH . 'includes/config.php';

// Feature toggles UI (always load so backup_s3 can be enabled from admin).
require_once NDBI_CORE_PATH . 'includes/admin-feature-toggles.php';

// Load feature modules.
require_once NDBI_CORE_PATH . 'includes/admin-footer.php';
require_once NDBI_CORE_PATH . 'includes/admin-plugin-links.php';
require_once NDBI_CORE_PATH . 'includes/updater.php';
require_once NDBI_CORE_PATH . 'includes/safe-defaults.php';

if ( ndbi_core_is_feature_enabled( 'backup_s3' ) ) {
	require_once NDBI_CORE_PATH . 'includes/backup-s3.php';
	require_once NDBI_CORE_PATH . 'includes/backup-s3-admin.php';
}
