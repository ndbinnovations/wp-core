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
// Backup S3 requires PHP 8.1+ (AWS SDK and dependencies). Skip loading on older PHP to avoid fatal errors.
if ( ndbi_core_is_feature_enabled( 'backup_s3' ) && version_compare( PHP_VERSION, '8.1.0', '>=' ) ) {
	require_once NDBI_CORE_PATH . 'includes/backup/backup-s3.php';
	require_once NDBI_CORE_PATH . 'includes/backup/backup-s3-admin.php';
} elseif ( ndbi_core_is_feature_enabled( 'backup_s3' ) ) {
	add_action( 'admin_notices', 'ndbi_core_backup_s3_php_version_notice' );
}

/**
 * Admin notice when Backup to S3 is enabled but PHP is below the required version.
 */
function ndbi_core_backup_s3_php_version_notice() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p>';
	echo esc_html(
		sprintf(
			/* translators: 1: required PHP version (e.g. 8.1), 2: current PHP version */
			__( 'Backup to S3 is enabled but requires PHP %1$s or later. This site is running PHP %2$s. The feature is disabled until PHP is upgraded.', 'ndbi-core' ),
			'8.1',
			PHP_VERSION
		)
	);
	echo '</p></div>';
}
