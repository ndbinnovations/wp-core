<?php
/**
 * Per-site configuration: enable/disable features.
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

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
 * Check whether a feature is enabled (filterable per site).
 *
 * @param string $feature_slug Feature slug (e.g. 'admin_footer').
 * @return bool
 */
function ndbi_core_is_feature_enabled( $feature_slug ) {
	$defaults = ndbi_core_get_default_features();
	$enabled  = apply_filters( 'ndbi_core_features', $defaults );

	if ( ! is_array( $enabled ) ) {
		$enabled = $defaults;
	}

	return in_array( $feature_slug, $enabled, true );
}
