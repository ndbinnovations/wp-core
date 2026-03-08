<?php
/**
 * Bootstrap: load feature modules and register hooks.
 *
 * Layout:
 *   includes/core/   - Config, safe-defaults, updater (shared, no feature flag).
 *   includes/admin/   - Admin UI used by all features (toggles, footer, plugin links).
 *   includes/backup/  - Backup-to-S3 feature (loaded when backup_s3 is enabled).
 * Add new features as includes/{feature}/ and require conditionally here.
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

// Core: config first so feature checks work, then safe-defaults and updater.
require_once NDBI_CORE_PATH . 'includes/core/config.php';
require_once NDBI_CORE_PATH . 'includes/core/safe-defaults.php';
require_once NDBI_CORE_PATH . 'includes/core/updater.php';

// Admin: feature toggles (always load so backup_s3 can be enabled from admin), footer, plugin links.
require_once NDBI_CORE_PATH . 'includes/admin/feature-toggles.php';
require_once NDBI_CORE_PATH . 'includes/admin/footer.php';
require_once NDBI_CORE_PATH . 'includes/admin/plugin-links.php';

// Feature modules (conditionally loaded).
if ( ndbi_core_is_feature_enabled( 'backup_s3' ) ) {
	require_once NDBI_CORE_PATH . 'includes/backup/backup-s3.php';
	require_once NDBI_CORE_PATH . 'includes/backup/backup-s3-admin.php';
}
