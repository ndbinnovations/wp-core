<?php
/**
 * Admin UI: toggle feature flags under NDB Innovations → Settings.
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

/** Parent slug for the NDB Innovations admin section. */
const NDBI_CORE_ADMIN_PARENT_SLUG = 'ndbi';

/**
 * Register NDB Innovations top-level menu and Settings (feature toggles) as first submenu.
 */
function ndbi_core_add_feature_toggles_page() {
	add_menu_page(
		__( 'NDB Innovations', 'ndbi-core' ),
		__( 'NDB Innovations', 'ndbi-core' ),
		'manage_options',
		NDBI_CORE_ADMIN_PARENT_SLUG,
		'ndbi_core_render_feature_toggles_page',
		'dashicons-admin-generic',
		80
	);
	add_submenu_page(
		NDBI_CORE_ADMIN_PARENT_SLUG,
		__( 'Settings', 'ndbi-core' ),
		__( 'Settings', 'ndbi-core' ),
		'manage_options',
		NDBI_CORE_ADMIN_PARENT_SLUG,
		'ndbi_core_render_feature_toggles_page'
	);
}

/**
 * Render the feature toggles settings page.
 */
function ndbi_core_render_feature_toggles_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$available = ndbi_core_get_available_features();
	$defaults  = ndbi_core_get_default_features();
	$toggles   = get_option( 'ndbi_core_feature_toggles', array() );
	if ( ! is_array( $toggles ) ) {
		$toggles = array();
	}

	// Message after save.
	if ( isset( $_GET['ndbi_core_saved'] ) && '1' === $_GET['ndbi_core_saved'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'Settings saved.', 'ndbi-core' );
		echo '</p></div>';
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';
	echo '<form method="post" action="' . esc_url( admin_url( 'admin.php?page=' . NDBI_CORE_ADMIN_PARENT_SLUG ) ) . '">';

	wp_nonce_field( 'ndbi_core_feature_toggles', 'ndbi_core_feature_toggles_nonce' );

	echo '<table class="form-table" role="presentation">';
	echo '<tbody>';

	foreach ( $available as $slug => $label ) {
		$default_on = in_array( $slug, $defaults, true );
		$current    = array_key_exists( $slug, $toggles ) ? (bool) $toggles[ $slug ] : $default_on;
		$id         = 'ndbi_core_toggle_' . sanitize_key( $slug );
		echo '<tr>';
		echo '<th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html__( $label, 'ndbi-core' ) . '</label></th>';
		echo '<td>';
		echo '<input name="ndbi_core_feature_toggles[' . esc_attr( $slug ) . ']" type="checkbox" id="' . esc_attr( $id ) . '" value="1" ' . checked( $current, true, false ) . ' />';
		echo '</td>';
		echo '</tr>';
	}

	echo '</tbody>';
	echo '</table>';

	submit_button( __( 'Save changes', 'ndbi-core' ) );
	echo '</form>';
	echo '</div>';
}

/**
 * Save feature toggles when form is submitted.
 */
function ndbi_core_save_feature_toggles() {
	if ( ! isset( $_POST['ndbi_core_feature_toggles_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ndbi_core_feature_toggles_nonce'] ) ), 'ndbi_core_feature_toggles' ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$available = ndbi_core_get_available_feature_slugs();
	$posted    = isset( $_POST['ndbi_core_feature_toggles'] ) && is_array( $_POST['ndbi_core_feature_toggles'] ) ? wp_unslash( $_POST['ndbi_core_feature_toggles'] ) : array();
	$toggles   = array();

	foreach ( $available as $slug ) {
		$toggles[ $slug ] = isset( $posted[ $slug ] ) && '1' === $posted[ $slug ];
	}

	update_option( 'ndbi_core_feature_toggles', $toggles );

	wp_safe_redirect( add_query_arg( 'ndbi_core_saved', '1', admin_url( 'admin.php?page=' . NDBI_CORE_ADMIN_PARENT_SLUG ) ) );
	exit;
}

add_action( 'admin_menu', 'ndbi_core_add_feature_toggles_page' );
add_action( 'admin_init', 'ndbi_core_save_feature_toggles' );
