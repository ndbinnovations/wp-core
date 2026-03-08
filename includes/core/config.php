<?php
/**
 * Per-site configuration: enable/disable features.
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * List of available feature slugs (no translation — safe to call before init).
 *
 * @return string[]
 */
function ndbi_core_get_available_feature_slugs() {
	return array( 'admin_footer', 'backup_s3' );
}

/**
 * Registry of all available feature slugs and their labels (for admin GUI).
 * Labels are default English; translate when displaying (e.g. esc_html__( $label, 'ndbi-core' )).
 *
 * @return array<string, string> Associative array of feature_slug => label.
 */
function ndbi_core_get_available_features() {
	return array(
		'admin_footer' => 'Admin footer branding',
		'backup_s3'    => 'Backup to S3-compatible storage',
	);
}

/**
 * Default list of enabled feature slugs.
 *
 * @return string[]
 */
function ndbi_core_get_default_features() {
	return array(
		'admin_footer',
	);
}

/**
 * Check whether a feature is enabled.
 *
 * Effective list: (1) defaults, (2) overlay saved toggles from options,
 * (3) ndbi_core_features filter so code can still override.
 *
 * @param string $feature_slug Feature slug (e.g. 'admin_footer').
 * @return bool
 */
function ndbi_core_is_feature_enabled( $feature_slug ) {
	$defaults = ndbi_core_get_default_features();
	$toggles  = get_option( 'ndbi_core_feature_toggles', array() );

	$enabled = $defaults;
	if ( is_array( $toggles ) ) {
		$available = ndbi_core_get_available_feature_slugs();
		foreach ( $toggles as $slug => $on ) {
			if ( ! in_array( $slug, $available, true ) ) {
				continue;
			}
			if ( $on ) {
				$enabled[] = $slug;
			} else {
				$enabled = array_values( array_diff( $enabled, array( $slug ) ) );
			}
		}
		$enabled = array_unique( $enabled );
	}

	$enabled = apply_filters( 'ndbi_core_features', $enabled );

	if ( ! is_array( $enabled ) ) {
		$enabled = $defaults;
	}

	return in_array( $feature_slug, $enabled, true );
}
