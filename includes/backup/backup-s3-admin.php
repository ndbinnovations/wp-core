<?php
/**
 * Admin UI for Backup to S3-compatible storage: settings page, Run backup now, schedule.
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

const NDBI_CORE_BACKUP_S3_DASHBOARD_SLUG = 'ndbi-backup-s3';

/** Tab query arg for Backup Settings (same page, no separate sidebar item). */
const NDBI_CORE_BACKUP_S3_TAB_SETTINGS = 'settings';

/**
 * URL for the Backup Settings tab (same menu page, tab=settings).
 *
 * @return string Admin URL for Backup Settings view.
 */
function ndbi_core_backup_s3_settings_url() {
	return admin_url( 'admin.php?page=' . NDBI_CORE_BACKUP_S3_DASHBOARD_SLUG . '&tab=' . NDBI_CORE_BACKUP_S3_TAB_SETTINGS );
}

/**
 * Register single Backup menu under NDB Innovations. Dashboard and Settings are tabs (tab=settings) on the same page.
 */
function ndbi_core_backup_s3_add_admin_page() {
	$parent = defined( 'NDBI_CORE_ADMIN_PARENT_SLUG' ) ? NDBI_CORE_ADMIN_PARENT_SLUG : 'ndbi';
	add_submenu_page(
		$parent,
		__( 'Backup', 'ndbi-core' ),
		__( 'Backup', 'ndbi-core' ),
		'manage_options',
		NDBI_CORE_BACKUP_S3_DASHBOARD_SLUG,
		'ndbi_core_backup_s3_render_backup_page'
	);
}

/**
 * Single Backup page callback: show dashboard or settings based on tab.
 */
function ndbi_core_backup_s3_render_backup_page() {
	$tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : '';
	if ( $tab === NDBI_CORE_BACKUP_S3_TAB_SETTINGS ) {
		ndbi_core_backup_s3_render_settings();
	} else {
		ndbi_core_backup_s3_render_dashboard();
	}
}

/**
 * Render the backup dashboard (status, run backup now).
 */
function ndbi_core_backup_s3_render_dashboard() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$dashboard_url = admin_url( 'admin.php?page=' . NDBI_CORE_BACKUP_S3_DASHBOARD_SLUG );
	$settings_url  = ndbi_core_backup_s3_settings_url();

	if ( isset( $_GET['ndbi_backup_deleted'] ) && '1' === $_GET['ndbi_backup_deleted'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'Backup deleted from storage.', 'ndbi-core' );
		echo '</p></div>';
	}
	if ( isset( $_GET['ndbi_backup_delete_failed'] ) && '1' === $_GET['ndbi_backup_delete_failed'] ) {
		echo '<div class="notice notice-error is-dismissible"><p>';
		esc_html_e( 'Could not delete backup from storage.', 'ndbi-core' );
		echo '</p></div>';
	}
	if ( isset( $_GET['ndbi_backup_started'] ) && '1' === $_GET['ndbi_backup_started'] ) {
		echo '<div class="notice notice-info is-dismissible"><p>';
		esc_html_e( 'Backup started. It will run in the background. Check back for status.', 'ndbi-core' );
		echo '</p></div>';
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Backup', 'ndbi-core' ) . '</h1>';
	echo '<p class="ndbi-backup-nav">' . esc_html__( 'Dashboard', 'ndbi-core' ) . ' | <a href="' . esc_url( $settings_url ) . '">' . esc_html__( 'Backup Settings', 'ndbi-core' ) . '</a></p>';

	$run_log = ndbi_core_backup_s3_get_run_log();
	echo '<div class="card ndbi-backup-status-card" style="width: 100%; max-width: none; margin: 1em 0; box-sizing: border-box;">';
	echo '<h2>' . esc_html__( 'Backup status', 'ndbi-core' ) . '</h2>';
	if ( ! empty( $run_log ) ) {
		echo '<table class="widefat striped" style="margin-top: 0.5em; width: 100%;">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Run ID', 'ndbi-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'ndbi-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Step', 'ndbi-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Started', 'ndbi-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Updated', 'ndbi-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Downloads', 'ndbi-core' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'ndbi-core' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $run_log as $entry ) {
			$run_id    = isset( $entry['run_id'] ) ? $entry['run_id'] : '';
			$status    = isset( $entry['status'] ) ? $entry['status'] : 'running';
			$step      = isset( $entry['step'] ) ? $entry['step'] : '';
			$started   = isset( $entry['started_at'] ) ? $entry['started_at'] : 0;
			$updated   = isset( $entry['updated_at'] ) ? $entry['updated_at'] : $started;
			$message   = isset( $entry['message'] ) ? $entry['message'] : '';
			$step_label = $step;
			if ( $message ) {
				$step_label = $message;
			}
			$show_downloads = ( $status === 'done' );
			$db_url         = $show_downloads ? ndbi_core_backup_s3_get_download_url( $run_id, 'db' ) : null;
			$files_url      = $show_downloads ? ndbi_core_backup_s3_get_download_url( $run_id, 'files' ) : null;
			echo '<tr>';
			echo '<td><code>' . esc_html( $run_id ) . '</code></td>';
			echo '<td><span class="status-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span></td>';
			echo '<td>' . esc_html( $step_label ) . '</td>';
			echo '<td>' . esc_html( $started ? wp_date( 'Y-m-d H:i', $started ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $updated ? wp_date( 'Y-m-d H:i', $updated ) : '—' ) . '</td>';
			echo '<td>';
			if ( $db_url ) {
				echo '<a href="' . esc_url( $db_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'DB', 'ndbi-core' ) . '</a>';
			} else {
				echo '—';
			}
			if ( $db_url && $files_url ) {
				echo ' · ';
			}
			if ( $files_url ) {
				echo '<a href="' . esc_url( $files_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Files', 'ndbi-core' ) . '</a>';
			} elseif ( ! $db_url ) {
				echo '—';
			}
			echo '</td>';
			echo '<td>';
			echo '<form method="post" action="' . esc_url( $dashboard_url ) . '" class="ndbi-backup-delete-form" style="display:inline;">';
			wp_nonce_field( 'ndbi_core_backup_s3_delete_' . $run_id, 'ndbi_core_backup_s3_delete_nonce' );
			echo '<input type="hidden" name="ndbi_delete_backup_run" value="' . esc_attr( $run_id ) . '" />';
			echo '<button type="submit" class="button-link-delete ndbi-backup-delete-btn" title="' . esc_attr__( 'Delete from storage', 'ndbi-core' ) . '" aria-label="' . esc_attr__( 'Delete from storage', 'ndbi-core' ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
			echo '</form>';
			echo '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<script>document.querySelectorAll(".ndbi-backup-delete-form").forEach(function(f){f.addEventListener("submit",function(e){if(!confirm(' . wp_json_encode( __( 'Delete this backup from storage? This cannot be undone.', 'ndbi-core' ) ) . '))e.preventDefault();});});</script>';
		echo '<style>.ndbi-backup-delete-btn{border:none;background:none;padding:0;outline:none;cursor:pointer;color:#b32d2e;}.ndbi-backup-delete-btn:hover{color:#dc3232;}.ndbi-backup-delete-btn .dashicons{font-size:18px;width:18px;height:18px;display:block;}</style>';
		echo '<p class="description">' . esc_html__( 'Refreshes when you reload the page. Running backups advance through: Exporting DB → Uploading DB → Scanning/Zipping files → Uploading files → Done.', 'ndbi-core' ) . '</p>';
	} else {
		echo '<p>' . esc_html__( 'No backup runs yet. Run a backup below.', 'ndbi-core' ) . '</p>';
	}
	echo '</div>';

	echo '<div class="card" style="max-width: 600px; margin: 1em 0;">';
	echo '<h2>' . esc_html__( 'Run backup now', 'ndbi-core' ) . '</h2>';
	echo '<form method="post" action="' . esc_url( $dashboard_url ) . '">';
	wp_nonce_field( 'ndbi_core_backup_s3_run', 'ndbi_core_backup_s3_run_nonce' );
	echo '<p><input type="submit" name="ndbi_backup_run_now" class="button button-primary" value="' . esc_attr__( 'Run backup now', 'ndbi-core' ) . '" /></p>';
	echo '</form>';
	echo '</div>';

	echo '</div>';
}

/**
 * Render the backup settings page (provider, credentials, schedule, etc.).
 */
function ndbi_core_backup_s3_render_settings() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$dashboard_url = admin_url( 'admin.php?page=' . NDBI_CORE_BACKUP_S3_DASHBOARD_SLUG );
	$settings      = ndbi_core_backup_s3_get_settings();
	$use_constant  = defined( 'NDBI_CORE_BACKUP_SECRET_KEY' );
	$settings_url  = ndbi_core_backup_s3_settings_url();

	if ( isset( $_GET['ndbi_backup_saved'] ) && '1' === $_GET['ndbi_backup_saved'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'Settings saved.', 'ndbi-core' );
		echo '</p></div>';
	}
	if ( isset( $_GET['ndbi_backup_cron_unavailable'] ) && '1' === $_GET['ndbi_backup_cron_unavailable'] ) {
		echo '<div class="notice notice-error is-dismissible"><p>';
		esc_html_e( 'Custom (cron) schedule requires the cron-expression package. Run composer update in the plugin directory (e.g. wp-content/plugins/ndbi-core), or choose another schedule.', 'ndbi-core' );
		echo '</p></div>';
	}
	if ( isset( $_GET['ndbi_backup_cron_invalid'] ) && '1' === $_GET['ndbi_backup_cron_invalid'] ) {
		echo '<div class="notice notice-error is-dismissible"><p>';
		esc_html_e( 'Invalid cron expression. Use five fields: minute hour day month weekday (e.g. 0 2 * * * for 02:00 daily).', 'ndbi-core' );
		echo '</p></div>';
	}
	if ( isset( $_GET['ndbi_preset_applied'] ) && '1' === $_GET['ndbi_preset_applied'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'Preset applied. Review region/endpoint and click Save settings.', 'ndbi-core' );
		echo '</p></div>';
	}
	if ( isset( $_GET['ndbi_backup_s3_test'] ) && '1' === $_GET['ndbi_backup_s3_test'] ) {
		echo '<div class="notice notice-success is-dismissible"><p>';
		esc_html_e( 'Connection test passed. Bucket is accessible with the saved settings.', 'ndbi-core' );
		echo '</p></div>';
	}
	if ( isset( $_GET['ndbi_backup_s3_test_error'] ) && '1' === $_GET['ndbi_backup_s3_test_error'] ) {
		$msg = get_transient( 'ndbi_backup_s3_test_error' );
		delete_transient( 'ndbi_backup_s3_test_error' );
		echo '<div class="notice notice-error is-dismissible"><p>';
		echo esc_html( $msg ? $msg : __( 'Connection test failed.', 'ndbi-core' ) );
		echo '</p></div>';
	}

	echo '<div class="wrap">';
	echo '<h1>' . esc_html__( 'Backup settings', 'ndbi-core' ) . '</h1>';
	echo '<p class="ndbi-backup-nav"><a href="' . esc_url( $dashboard_url ) . '">' . esc_html__( 'Dashboard', 'ndbi-core' ) . '</a> | ' . esc_html__( 'Backup Settings', 'ndbi-core' ) . '</p>';

	$presets = ndbi_core_backup_s3_get_provider_presets();
	$provider = isset( $settings['provider'] ) ? $settings['provider'] : 'b2';
	$current_preset = isset( $presets[ $provider ] ) ? $presets[ $provider ] : $presets['b2'];

	echo '<form method="post" action="' . esc_url( $settings_url ) . '">';
	wp_nonce_field( 'ndbi_core_backup_s3_settings', 'ndbi_core_backup_s3_settings_nonce' );

	echo '<table class="form-table" role="presentation">';
	echo '<tbody>';

	// Storage provider.
	echo '<tr><th scope="row"><label for="ndbi_s3_provider">' . esc_html__( 'Storage provider', 'ndbi-core' ) . '</label></th>';
	echo '<td><select name="ndbi_core_backup_s3[provider]" id="ndbi_s3_provider" class="regular-text">';
	foreach ( $presets as $id => $p ) {
		echo '<option value="' . esc_attr( $id ) . '" ' . selected( $provider, $id, false ) . '>' . esc_html( $p['label'] ) . '</option>';
	}
	echo '</select>';
	echo '<p class="description">' . esc_html__( 'Choose your S3-compatible storage. Region and endpoint fields below depend on this.', 'ndbi-core' ) . '</p></td></tr>';

	// Preset buttons: apply default settings for the selected provider (submit with preset=provider).
	echo '<tr><th scope="row">' . esc_html__( 'Apply preset', 'ndbi-core' ) . '</th><td>';
	foreach ( $presets as $id => $p ) {
		echo ' <button type="submit" name="ndbi_apply_preset" value="' . esc_attr( $id ) . '" class="button button-secondary">' . esc_html( sprintf( __( 'Use %s defaults', 'ndbi-core' ), $p['label'] ) ) . '</button>';
	}
	echo '<p class="description">' . esc_html__( 'Set default region and endpoint for the chosen provider (then save).', 'ndbi-core' ) . '</p></td></tr>';

	// Key ID (Access Key).
	echo '<tr><th scope="row"><label for="ndbi_s3_key_id">' . esc_html__( 'Access Key ID', 'ndbi-core' ) . '</label></th>';
	echo '<td><input name="ndbi_core_backup_s3[key_id]" type="text" id="ndbi_s3_key_id" value="' . esc_attr( $settings['key_id'] ) . '" class="regular-text" /></td></tr>';

	// Secret key.
	echo '<tr><th scope="row"><label for="ndbi_s3_app_key">' . esc_html__( 'Secret Access Key', 'ndbi-core' ) . '</label></th>';
	echo '<td>';
	if ( $use_constant ) {
		echo '<p>' . esc_html__( 'Using NDBI_CORE_BACKUP_SECRET_KEY from wp-config.php.', 'ndbi-core' ) . '</p>';
		echo '<input name="ndbi_core_backup_s3[app_key]" type="hidden" value="" />';
	} else {
		echo '<input name="ndbi_core_backup_s3[app_key]" type="password" id="ndbi_s3_app_key" value="" class="regular-text" autocomplete="off" />';
		echo '<p class="description">' . esc_html__( 'Leave blank to keep existing. Or define NDBI_CORE_BACKUP_SECRET_KEY in wp-config.php.', 'ndbi-core' ) . '</p>';
	}
	echo '</td></tr>';

	// Bucket.
	echo '<tr><th scope="row"><label for="ndbi_s3_bucket">' . esc_html__( 'Bucket name', 'ndbi-core' ) . '</label></th>';
	echo '<td><input name="ndbi_core_backup_s3[bucket]" type="text" id="ndbi_s3_bucket" value="' . esc_attr( $settings['bucket'] ) . '" class="regular-text" /></td></tr>';

	// Path prefix.
	echo '<tr><th scope="row"><label for="ndbi_s3_path_prefix">' . esc_html__( 'Path prefix', 'ndbi-core' ) . '</label></th>';
	echo '<td><input name="ndbi_core_backup_s3[path_prefix]" type="text" id="ndbi_s3_path_prefix" value="' . esc_attr( $settings['path_prefix'] ) . '" class="regular-text" placeholder="backups/" />';
	echo '<p class="description">' . esc_html__( 'Optional folder path in the bucket (e.g. backups/).', 'ndbi-core' ) . '</p></td></tr>';

	// Region (for AWS, B2; optional for R2/Custom).
	echo '<tr><th scope="row"><label for="ndbi_s3_region">' . esc_html__( 'Region', 'ndbi-core' ) . '</label></th>';
	echo '<td><input name="ndbi_core_backup_s3[region]" type="text" id="ndbi_s3_region" value="' . esc_attr( $settings['region'] ) . '" class="regular-text" placeholder="' . esc_attr( $current_preset['default_region'] ) . '" />';
	echo '<p class="description">' . esc_html( $current_preset['region_label'] ) . '</p></td></tr>';

	// Endpoint (required for R2 and Custom; leave blank for AWS and B2).
	$endpoint_placeholder = 'r2' === $provider ? 'https://<account_id>.r2.cloudflarestorage.com' : ( 'custom' === $provider ? 'https://your-endpoint.example.com' : '' );
	echo '<tr id="ndbi_s3_endpoint_row"><th scope="row"><label for="ndbi_s3_endpoint">' . esc_html__( 'Endpoint URL', 'ndbi-core' ) . '</label></th>';
	echo '<td><input name="ndbi_core_backup_s3[endpoint]" type="url" id="ndbi_s3_endpoint" value="' . esc_attr( $settings['endpoint'] ) . '" class="regular-text" placeholder="' . esc_attr( $endpoint_placeholder ) . '" />';
	if ( in_array( $provider, array( 'r2', 'custom' ), true ) ) {
		echo '<p class="description">' . esc_html__( 'S3 API endpoint. For R2: use the endpoint from your R2 bucket (e.g. https://&lt;account_id&gt;.r2.cloudflarestorage.com).', 'ndbi-core' ) . '</p>';
	} else {
		echo '<p class="description">' . esc_html__( 'Leave blank for Amazon S3 and Backblaze B2.', 'ndbi-core' ) . '</p>';
	}
	echo '</td></tr>';

	// Test connection (uses saved settings).
	echo '<tr><th scope="row">' . esc_html__( 'Test connection', 'ndbi-core' ) . '</th>';
	echo '<td><button type="submit" name="ndbi_test_s3_connection" value="1" class="button button-secondary">' . esc_html__( 'Test connection', 'ndbi-core' ) . '</button>';
	echo '<p class="description">' . esc_html__( 'List one object in the bucket to verify saved settings. Save settings first if you changed them.', 'ndbi-core' ) . '</p></td></tr>';

	// Include files.
	echo '<tr><th scope="row">' . esc_html__( 'Include files', 'ndbi-core' ) . '</th>';
	echo '<td><label><input name="ndbi_core_backup_s3[include_files]" type="checkbox" value="1" ' . checked( ! empty( $settings['include_files'] ), true, false ) . ' /> ';
	echo esc_html__( 'Include full site files in backup (ZIP: site root including wp-config.php, wp-content, wp-includes)', 'ndbi-core' ) . '</label></td></tr>';

	// Schedule with inline time (daily/weekly) and day (weekly only).
	$schedule_time = isset( $settings['schedule_time'] ) ? $settings['schedule_time'] : '00:00';
	$schedule_day  = isset( $settings['schedule_day'] ) ? (int) $settings['schedule_day'] : 0;
	$weekdays = array(
		0 => __( 'Sunday', 'ndbi-core' ),
		1 => __( 'Monday', 'ndbi-core' ),
		2 => __( 'Tuesday', 'ndbi-core' ),
		3 => __( 'Wednesday', 'ndbi-core' ),
		4 => __( 'Thursday', 'ndbi-core' ),
		5 => __( 'Friday', 'ndbi-core' ),
		6 => __( 'Saturday', 'ndbi-core' ),
	);
	$schedule          = isset( $settings['schedule'] ) ? $settings['schedule'] : 'off';
	$schedule_cron_expr = isset( $settings['schedule_cron_expr'] ) ? $settings['schedule_cron_expr'] : '';
	$cron_available   = function_exists( 'ndbi_core_backup_s3_cron_available' ) && ndbi_core_backup_s3_cron_available();
	$schedule_options  = array( 'off' => __( 'Off', 'ndbi-core' ), 'daily' => __( 'Daily', 'ndbi-core' ), 'weekly' => __( 'Weekly', 'ndbi-core' ) );
	if ( $cron_available ) {
		$schedule_options['cron'] = __( 'Custom (cron)', 'ndbi-core' );
	}
	if ( ! $cron_available && $schedule === 'cron' ) {
		$schedule = 'off';
	}
	echo '<tr><th scope="row"><label for="ndbi_s3_schedule">' . esc_html__( 'Schedule', 'ndbi-core' ) . '</label></th>';
	echo '<td>';
	echo '<select name="ndbi_core_backup_s3[schedule]" id="ndbi_s3_schedule">';
	foreach ( $schedule_options as $val => $label ) {
		echo '<option value="' . esc_attr( $val ) . '" ' . selected( $schedule, $val, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo ' <span id="ndbi_s3_schedule_time_wrap" class="ndbi-schedule-inline" style="' . ( $schedule === 'off' || $schedule === 'cron' ? 'display:none;' : '' ) . '">';
	echo ' <label for="ndbi_s3_schedule_time" class="screen-reader-text">' . esc_html__( 'Backup time', 'ndbi-core' ) . '</label>';
	echo ' <input name="ndbi_core_backup_s3[schedule_time]" type="time" id="ndbi_s3_schedule_time" value="' . esc_attr( $schedule_time ) . '" />';
	echo '</span>';
	echo ' <span id="ndbi_s3_schedule_day_wrap" class="ndbi-schedule-inline" style="' . ( $schedule !== 'weekly' ? 'display:none;' : '' ) . '">';
	echo ' <label for="ndbi_s3_schedule_day" class="screen-reader-text">' . esc_html__( 'Day (weekly)', 'ndbi-core' ) . '</label>';
	echo ' <select name="ndbi_core_backup_s3[schedule_day]" id="ndbi_s3_schedule_day">';
	foreach ( $weekdays as $d => $label ) {
		echo '<option value="' . esc_attr( $d ) . '" ' . selected( $schedule_day, $d, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
	echo '</span>';
	echo ' <span id="ndbi_s3_schedule_cron_wrap" class="ndbi-schedule-inline" style="' . ( $schedule !== 'cron' ? 'display:none;' : '' ) . '">';
	echo ' <label for="ndbi_s3_schedule_cron_expr">' . esc_html__( 'Cron expression', 'ndbi-core' ) . '</label>';
	echo ' <input name="ndbi_core_backup_s3[schedule_cron_expr]" type="text" id="ndbi_s3_schedule_cron_expr" value="' . esc_attr( $schedule_cron_expr ) . '" placeholder="0 2 * * *" class="regular-text" />';
	echo '</span>';
	echo '<p class="description" id="ndbi_s3_schedule_desc">' . esc_html__( 'Time of day uses site timezone (default: midnight). For weekly, choose the day.', 'ndbi-core' ) . '</p>';
	echo '<p class="description" id="ndbi_s3_schedule_cron_desc" style="' . ( $schedule !== 'cron' ? 'display:none;' : '' ) . '">' . esc_html__( 'Five fields: minute hour day month weekday (e.g. 0 2 * * * = 02:00 daily). Uses site timezone.', 'ndbi-core' ) . '</p>';
	echo '</td></tr>';
	echo '<style type="text/css">.ndbi-schedule-inline{margin-left:0.5em;}.ndbi-schedule-inline input,.ndbi-schedule-inline select{margin-left:0.25em;}</style>';
	echo '<script type="text/javascript">';
	$has_cron_opt = $cron_available ? 'true' : 'false';
	echo "(function(){ var s=document.getElementById('ndbi_s3_schedule'); var tw=document.getElementById('ndbi_s3_schedule_time_wrap'); var dw=document.getElementById('ndbi_s3_schedule_day_wrap'); var cw=document.getElementById('ndbi_s3_schedule_cron_wrap'); var desc=document.getElementById('ndbi_s3_schedule_desc'); var cdesc=document.getElementById('ndbi_s3_schedule_cron_desc'); var hasCron=" . $has_cron_opt . "; function up(){ var v=s?s.value:''; if(tw) tw.style.display=(v==='off'||v==='cron'?'none':'inline'); if(dw) dw.style.display=(v==='weekly'?'inline':'none'); if(cw) cw.style.display=(v==='cron'&&hasCron?'inline':'none'); if(desc) desc.style.display=(v==='cron'?'none':'block'); if(cdesc) cdesc.style.display=(v==='cron'?'block':'none'); } if(s){ s.addEventListener('change',up); up(); } })();";
	echo '</script>';

	// Retention.
	$retain = isset( $settings['retain_count'] ) ? (int) $settings['retain_count'] : 0;
	echo '<tr><th scope="row"><label for="ndbi_s3_retain_count">' . esc_html__( 'Retain backups', 'ndbi-core' ) . '</label></th>';
	echo '<td><input name="ndbi_core_backup_s3[retain_count]" type="number" id="ndbi_s3_retain_count" value="' . esc_attr( $retain ) . '" min="0" max="999" step="1" class="small-text" /> ';
	echo '<span class="description">' . esc_html__( 'Keep this many backup runs (0 = keep all). Oldest are deleted after each successful backup.', 'ndbi-core' ) . '</span></td></tr>';

	echo '</tbody>';
	echo '</table>';

	submit_button( __( 'Save settings', 'ndbi-core' ) );
	echo '</form>';
	echo '</div>';
}

/**
 * Save backup settings and run backup now.
 */
function ndbi_core_backup_s3_handle_admin_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	// Delete backup from storage and remove from run log.
	if ( isset( $_POST['ndbi_delete_backup_run'] ) && isset( $_POST['ndbi_core_backup_s3_delete_nonce'] ) ) {
		$run_id = sanitize_text_field( wp_unslash( $_POST['ndbi_delete_backup_run'] ) );
		if ( $run_id && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ndbi_core_backup_s3_delete_nonce'] ) ), 'ndbi_core_backup_s3_delete_' . $run_id ) ) {
			$deleted = ndbi_core_backup_s3_delete_run_from_storage( $run_id );
			if ( $deleted ) {
				ndbi_core_backup_s3_remove_run_from_log( $run_id );
			}
			wp_safe_redirect( add_query_arg( $deleted ? 'ndbi_backup_deleted' : 'ndbi_backup_delete_failed', '1', admin_url( 'admin.php?page=' . NDBI_CORE_BACKUP_S3_DASHBOARD_SLUG ) ) );
			exit;
		}
	}

	// Run backup now: run orchestrator in this request so the run is created and first job is scheduled immediately.
	if ( isset( $_POST['ndbi_backup_run_now'] ) && isset( $_POST['ndbi_core_backup_s3_run_nonce'] ) ) {
		if ( wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ndbi_core_backup_s3_run_nonce'] ) ), 'ndbi_core_backup_s3_run' ) ) {
			ndbi_core_backup_s3_run_orchestrator();
			wp_safe_redirect( add_query_arg( 'ndbi_backup_started', '1', admin_url( 'admin.php?page=' . NDBI_CORE_BACKUP_S3_DASHBOARD_SLUG ) ) );
			exit;
		}
	}

	// Test S3 connection (uses saved settings).
	if ( isset( $_POST['ndbi_test_s3_connection'] ) && isset( $_POST['ndbi_core_backup_s3_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ndbi_core_backup_s3_settings_nonce'] ) ), 'ndbi_core_backup_s3_settings' ) ) {
		$result = ndbi_core_backup_s3_test_connection();
		$redirect = ndbi_core_backup_s3_settings_url();
		if ( ! empty( $result['success'] ) ) {
			wp_safe_redirect( add_query_arg( 'ndbi_backup_s3_test', '1', $redirect ) );
		} else {
			set_transient( 'ndbi_backup_s3_test_error', isset( $result['message'] ) ? $result['message'] : __( 'Connection test failed.', 'ndbi-core' ), 45 );
			wp_safe_redirect( add_query_arg( 'ndbi_backup_s3_test_error', '1', $redirect ) );
		}
		exit;
	}

	// Apply preset (set provider + default region/endpoint only).
	if ( isset( $_POST['ndbi_core_backup_s3_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ndbi_core_backup_s3_settings_nonce'] ) ), 'ndbi_core_backup_s3_settings' ) && isset( $_POST['ndbi_apply_preset'] ) ) {
		$preset_id = sanitize_text_field( wp_unslash( $_POST['ndbi_apply_preset'] ) );
		$presets   = ndbi_core_backup_s3_get_provider_presets();
		if ( isset( $presets[ $preset_id ] ) ) {
			$opt = get_option( NDBI_CORE_S3_OPTION, array() );
			if ( ! is_array( $opt ) ) {
				$opt = array();
			}
			$opt['provider'] = $preset_id;
			$opt['region']   = $presets[ $preset_id ]['default_region'];
			$opt['endpoint'] = in_array( $preset_id, array( 'r2', 'custom' ), true ) ? ( isset( $opt['endpoint'] ) ? $opt['endpoint'] : '' ) : '';
			update_option( NDBI_CORE_S3_OPTION, $opt );
			wp_safe_redirect( add_query_arg( 'ndbi_preset_applied', '1', ndbi_core_backup_s3_settings_url() ) );
			exit;
		}
	}

	// Save settings.
	if ( isset( $_POST['ndbi_core_backup_s3_settings_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['ndbi_core_backup_s3_settings_nonce'] ) ), 'ndbi_core_backup_s3_settings' ) && ! isset( $_POST['ndbi_apply_preset'] ) ) {
		$raw = isset( $_POST['ndbi_core_backup_s3'] ) && is_array( $_POST['ndbi_core_backup_s3'] ) ? wp_unslash( $_POST['ndbi_core_backup_s3'] ) : array();
		$opt = get_option( NDBI_CORE_S3_OPTION, array() );
		if ( ! is_array( $opt ) ) {
			$opt = array();
		}
		$requested_schedule = isset( $raw['schedule'] ) && in_array( $raw['schedule'], array( 'off', 'daily', 'weekly', 'cron' ), true ) ? $raw['schedule'] : 'off';
		$cron_available    = function_exists( 'ndbi_core_backup_s3_cron_available' ) && ndbi_core_backup_s3_cron_available();
		$schedule_cron_expr = isset( $raw['schedule_cron_expr'] ) ? sanitize_text_field( trim( $raw['schedule_cron_expr'] ) ) : ( isset( $opt['schedule_cron_expr'] ) ? $opt['schedule_cron_expr'] : '' );
		$cron_error        = null;
		if ( $requested_schedule === 'cron' && ! $cron_available ) {
			$requested_schedule = 'off';
			$cron_error         = 'ndbi_backup_cron_unavailable';
		} elseif ( $requested_schedule === 'cron' && $cron_available && function_exists( 'ndbi_core_backup_s3_next_cron_timestamp' ) && ndbi_core_backup_s3_next_cron_timestamp( $schedule_cron_expr ) === null ) {
			$cron_error = 'ndbi_backup_cron_invalid';
		}
		$presets = ndbi_core_backup_s3_get_provider_presets();
		$provider = isset( $raw['provider'] ) && isset( $presets[ $raw['provider'] ] ) ? $raw['provider'] : ( isset( $opt['provider'] ) ? $opt['provider'] : 'b2' );
		$opt['provider']    = $provider;
		$opt['key_id']      = isset( $raw['key_id'] ) ? sanitize_text_field( $raw['key_id'] ) : ( isset( $opt['key_id'] ) ? $opt['key_id'] : '' );
		$opt['bucket']     = isset( $raw['bucket'] ) ? sanitize_text_field( $raw['bucket'] ) : ( isset( $opt['bucket'] ) ? $opt['bucket'] : '' );
		$opt['path_prefix'] = isset( $raw['path_prefix'] ) ? sanitize_text_field( $raw['path_prefix'] ) : ( isset( $opt['path_prefix'] ) ? $opt['path_prefix'] : '' );
		$opt['region']     = isset( $raw['region'] ) ? sanitize_text_field( $raw['region'] ) : ( isset( $opt['region'] ) ? $opt['region'] : $presets[ $provider ]['default_region'] );
		$opt['endpoint']   = isset( $raw['endpoint'] ) ? esc_url_raw( trim( $raw['endpoint'] ) ) : ( isset( $opt['endpoint'] ) ? $opt['endpoint'] : '' );
		$opt['include_files'] = ! empty( $raw['include_files'] );
		$opt['schedule']   = $requested_schedule;
		if ( isset( $raw['schedule_time'] ) && preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $raw['schedule_time'] ) ) {
			$opt['schedule_time'] = sanitize_text_field( $raw['schedule_time'] );
		} else {
			$opt['schedule_time'] = isset( $opt['schedule_time'] ) ? $opt['schedule_time'] : '00:00';
		}
		$opt['schedule_day'] = isset( $raw['schedule_day'] ) ? max( 0, min( 6, (int) $raw['schedule_day'] ) ) : ( isset( $opt['schedule_day'] ) ? (int) $opt['schedule_day'] : 0 );
		$opt['schedule_cron_expr'] = $schedule_cron_expr;
		$opt['retain_count'] = isset( $raw['retain_count'] ) ? max( 0, (int) $raw['retain_count'] ) : 0;
		if ( ! defined( 'NDBI_CORE_BACKUP_SECRET_KEY' ) && isset( $raw['app_key'] ) && $raw['app_key'] !== '' ) {
			$opt['app_key'] = sanitize_text_field( $raw['app_key'] );
		}
		update_option( NDBI_CORE_S3_OPTION, $opt );
		if ( function_exists( 'ndbi_core_backup_s3_schedule_cron' ) ) {
			ndbi_core_backup_s3_schedule_cron();
		}
		$redirect = ndbi_core_backup_s3_settings_url();
		if ( $cron_error ) {
			$redirect = add_query_arg( $cron_error, '1', $redirect );
		} else {
			$redirect = add_query_arg( 'ndbi_backup_saved', '1', $redirect );
		}
		wp_safe_redirect( $redirect );
		exit;
	}
}

/**
 * Add backup status to the admin bar (left side): parent with status dot, dropdown with status details.
 */
function ndbi_core_backup_s3_admin_bar_menu( $wp_admin_bar ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$last   = ndbi_core_backup_s3_get_last_status();
	$times  = ndbi_core_backup_s3_get_last_success_times();
	$status = isset( $last['status'] ) ? $last['status'] : '';
	if ( $status === 'success' ) {
		$color = '#46b450';
		$label = __( 'Success', 'ndbi-core' );
	} elseif ( $status === 'error' ) {
		$color = '#dc3232';
		$label = __( 'Error', 'ndbi-core' );
	} elseif ( $status === 'running' ) {
		$color = '#ffb900';
		$label = __( 'Running', 'ndbi-core' );
	} else {
		$color = '#a0a5aa';
		$label = __( 'No backup yet', 'ndbi-core' );
	}
	$dot_style = 'display:inline-block;width:8px;height:8px;border-radius:50%;background:' . esc_attr( $color ) . ';vertical-align:middle;margin-left:6px;';
	$title     = esc_html__( 'Backup', 'ndbi-core' ) . ' <span style="' . $dot_style . '" aria-hidden="true"></span>';

	$dashboard_url = admin_url( 'admin.php?page=' . NDBI_CORE_BACKUP_S3_DASHBOARD_SLUG );
	$settings_url  = ndbi_core_backup_s3_settings_url();

	// Parent item on the left (root-default = left group, next to New etc.).
	$wp_admin_bar->add_node(
		array(
			'id'     => 'ndbi-backup-status',
			'parent' => 'root-default',
			'title'  => $title,
			'href'   => $dashboard_url,
			'meta'   => array(
				'title' => __( 'Backup status', 'ndbi-core' ),
			),
		)
	);

	// Dropdown: status line.
	$wp_admin_bar->add_node(
		array(
			'id'     => 'ndbi-backup-status-state',
			'parent' => 'ndbi-backup-status',
			'title'  => __( 'Status:', 'ndbi-core' ) . ' ' . $label,
			'meta'   => array( 'class' => 'ndbi-backup-dropdown-item' ),
		)
	);

	$date_format = __( 'M j, Y g:i a', 'ndbi-core' );
	$db_str    = $times['db'] ? wp_date( $date_format, $times['db'] ) : __( 'never', 'ndbi-core' );
	$files_str = $times['files'] ? wp_date( $date_format, $times['files'] ) : __( 'never', 'ndbi-core' );

	$wp_admin_bar->add_node(
		array(
			'id'     => 'ndbi-backup-db',
			'parent' => 'ndbi-backup-status',
			'title'  => __( 'DB:', 'ndbi-core' ) . ' ' . $db_str,
			'meta'   => array( 'class' => 'ndbi-backup-dropdown-item' ),
		)
	);

	$wp_admin_bar->add_node(
		array(
			'id'     => 'ndbi-backup-files',
			'parent' => 'ndbi-backup-status',
			'title'  => __( 'Files:', 'ndbi-core' ) . ' ' . $files_str,
			'meta'   => array( 'class' => 'ndbi-backup-dropdown-item' ),
		)
	);

	$wp_admin_bar->add_node(
		array(
			'id'     => 'ndbi-backup-settings',
			'parent' => 'ndbi-backup-status',
			'title'  => __( 'Backup settings', 'ndbi-core' ),
			'href'   => $settings_url,
		)
	);
}

add_action( 'admin_menu', 'ndbi_core_backup_s3_add_admin_page' );
add_action( 'admin_init', 'ndbi_core_backup_s3_handle_admin_post' );
// After default left-side items: New (70), Edit (80). See wp-includes/class-wp-admin-bar.php.
add_action( 'admin_bar_menu', 'ndbi_core_backup_s3_admin_bar_menu', 85 );
