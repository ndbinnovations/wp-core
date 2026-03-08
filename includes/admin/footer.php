<?php
/**
 * Admin footer branding: "NDB Innovations site" message.
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Filter the admin footer text to show NDB Innovations branding and plugin version.
 *
 * @param string $text The footer text.
 * @return string
 */
function ndbi_core_admin_footer_text( $text ) {
	return sprintf(
		/* translators: 1: opening link tag, 2: closing link tag, 3: NDBI Core version number */
		__( 'This site is built and maintained by %1$sNDB Innovations%2$s. NDBI Core %3$s', 'ndbi-core' ),
		'<a href="https://ndbinnovations.ca" target="_blank" rel="noopener noreferrer">',
		'</a>',
		esc_html( NDBI_CORE_VERSION )
	);
}

if ( ndbi_core_is_feature_enabled( 'admin_footer' ) ) {
	add_filter( 'admin_footer_text', 'ndbi_core_admin_footer_text' );
}
