<?php
/**
 * Remove the "View details" link from the plugin row meta (right side: author, View details, Check for updates)
 * since this plugin is not on WordPress.org and that link is useless.
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * Remove View details from the right-side plugin row meta (between author and Check for updates).
 *
 * @param string[] $plugin_meta Array of meta link HTML strings.
 * @param string   $plugin_file Plugin basename (e.g. ndbi-core/ndbi-core.php).
 * @return string[]
 */
function ndbi_core_remove_plugin_row_meta_view_details( $plugin_meta, $plugin_file ) {
	if ( $plugin_file !== NDBI_CORE_BASENAME ) {
		return $plugin_meta;
	}
	foreach ( $plugin_meta as $key => $html ) {
		if ( false !== strpos( $html, 'View details' ) ) {
			unset( $plugin_meta[ $key ] );
			break;
		}
	}
	return $plugin_meta;
}

add_filter( 'plugin_row_meta', 'ndbi_core_remove_plugin_row_meta_view_details', 10, 2 );
