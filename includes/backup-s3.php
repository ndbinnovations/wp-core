<?php
/**
 * Backup to S3-compatible storage: chunked DB export, optional file ZIP, multipart upload.
 *
 * Uses Action Scheduler for chunked processing and AWS SDK for S3-compatible storage.
 *
 * @package Ndbi_Core
 */

defined( 'ABSPATH' ) || exit;

// Load Action Scheduler before plugins_loaded (required by the library).
$action_scheduler_path = NDBI_CORE_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
if ( file_exists( $action_scheduler_path ) ) {
	require_once $action_scheduler_path;
}

/**
 * Action Scheduler group for backup jobs.
 */
const NDBI_CORE_S3_GROUP = 'ndbi_core_backup_s3';

/**
 * Minimum file size (bytes) to use multipart upload. Below this use PutObject.
 */
const NDBI_CORE_S3_MULTIPART_THRESHOLD = 5 * 1024 * 1024;

/**
 * Option key for backup settings.
 */
const NDBI_CORE_S3_OPTION = 'ndbi_core_backup_s3';

/**
 * Option key for in-progress run state (run_id => array).
 */
const NDBI_CORE_S3_RUN_OPTION = 'ndbi_core_backup_s3_run';

/**
 * Temporary debug logging. Enable with: define( 'NDBI_CORE_BACKUP_DEBUG', true ); in wp-config.php
 * Logs go to wp-content/debug.log when WP_DEBUG_LOG is true, or the PHP/server error log.
 *
 * @param string $message Log message.
 * @param array  $context Optional key-value context (logged as JSON).
 */
function ndbi_core_backup_s3_log( $message, $context = array() ) {
	if ( ! defined( 'NDBI_CORE_BACKUP_DEBUG' ) || ! NDBI_CORE_BACKUP_DEBUG ) {
		return;
	}
	$prefix = '[NDBI Backup] ';
	if ( ! empty( $context ) ) {
		$message .= ' ' . wp_json_encode( $context );
	}
	error_log( $prefix . $message );
}

/**
 * Initialize backup S3: register action hooks.
 */
function ndbi_core_backup_s3_init() {
	add_action( 'ndbi_core_backup_s3_orchestrator', 'ndbi_core_backup_s3_run_orchestrator' );
	add_action( 'ndbi_core_backup_s3_cron_tick', 'ndbi_core_backup_s3_run_cron_tick' );
	add_action( 'ndbi_core_backup_s3_export_db', 'ndbi_core_backup_s3_run_export_db', 10, 2 );
	add_action( 'ndbi_core_backup_s3_finish_db', 'ndbi_core_backup_s3_run_finish_db', 10, 1 );
	add_action( 'ndbi_core_backup_s3_zip_scan', 'ndbi_core_backup_s3_run_zip_scan', 10, 1 );
	add_action( 'ndbi_core_backup_s3_zip_chunk', 'ndbi_core_backup_s3_run_zip_chunk', 10, 1 );
	add_action( 'ndbi_core_backup_s3_upload', 'ndbi_core_backup_s3_run_upload', 10, 4 );
}

add_action( 'init', 'ndbi_core_backup_s3_init', 0 );

/**
 * S3-compatible provider presets (endpoint template, default region, path-style).
 *
 * @return array<string, array{label: string, region_label: string, default_region: string, endpoint_template: string, path_style: bool}>
 */
function ndbi_core_backup_s3_get_provider_presets() {
	return array(
		'aws'   => array(
			'label'              => __( 'Amazon S3', 'ndbi-core' ),
			'region_label'       => __( 'Region (e.g. us-east-1)', 'ndbi-core' ),
			'default_region'     => 'us-east-1',
			'endpoint_template'  => '',
			'path_style'         => false,
		),
		'b2'    => array(
			'label'              => __( 'Backblaze B2', 'ndbi-core' ),
			'region_label'       => __( 'Region (e.g. us-west-002)', 'ndbi-core' ),
			'default_region'     => 'us-west-002',
			'endpoint_template'  => 'https://s3.%s.backblazeb2.com',
			'path_style'         => true,
		),
		'r2'    => array(
			'label'              => __( 'Cloudflare R2', 'ndbi-core' ),
			'region_label'       => __( 'Region (use auto for R2)', 'ndbi-core' ),
			'default_region'     => 'auto',
			'endpoint_template'  => '',
			'path_style'         => true,
		),
		'custom' => array(
			'label'              => __( 'Custom S3-compatible', 'ndbi-core' ),
			'region_label'       => __( 'Region (if required)', 'ndbi-core' ),
			'default_region'     => 'us-east-1',
			'endpoint_template'  => '',
			'path_style'         => true,
		),
	);
}

/**
 * Get backup S3 settings (sanitized).
 *
 * @return array{provider?: string, key_id?: string, app_key?: string, bucket?: string, path_prefix?: string, region?: string, endpoint?: string, include_files?: bool, schedule?: string}
 */
function ndbi_core_backup_s3_get_settings() {
	$opt = get_option( NDBI_CORE_S3_OPTION, array() );
	if ( ! is_array( $opt ) ) {
		$opt = array();
	}
	$app_key = defined( 'NDBI_CORE_BACKUP_SECRET_KEY' ) ? NDBI_CORE_BACKUP_SECRET_KEY : ( isset( $opt['app_key'] ) ? $opt['app_key'] : '' );
	$presets = ndbi_core_backup_s3_get_provider_presets();
	$provider = isset( $opt['provider'] ) ? sanitize_text_field( $opt['provider'] ) : 'b2';
	if ( ! isset( $presets[ $provider ] ) ) {
		$provider = 'b2';
	}
	$default_region = $presets[ $provider ]['default_region'];
	$schedule_time = isset( $opt['schedule_time'] ) ? sanitize_text_field( $opt['schedule_time'] ) : '00:00';
	if ( ! preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $schedule_time ) ) {
		$schedule_time = '00:00';
	}
	$schedule_day = isset( $opt['schedule_day'] ) ? (int) $opt['schedule_day'] : 0;
	if ( $schedule_day < 0 || $schedule_day > 6 ) {
		$schedule_day = 0;
	}
	$schedule_cron_expr = isset( $opt['schedule_cron_expr'] ) ? sanitize_text_field( trim( $opt['schedule_cron_expr'] ) ) : '';
	return array(
		'provider'      => $provider,
		'key_id'        => isset( $opt['key_id'] ) ? sanitize_text_field( $opt['key_id'] ) : '',
		'app_key'       => $app_key,
		'bucket'        => isset( $opt['bucket'] ) ? sanitize_text_field( $opt['bucket'] ) : '',
		'path_prefix'   => isset( $opt['path_prefix'] ) ? sanitize_text_field( $opt['path_prefix'] ) : '',
		'region'        => isset( $opt['region'] ) ? sanitize_text_field( $opt['region'] ) : $default_region,
		'endpoint'      => isset( $opt['endpoint'] ) ? esc_url_raw( trim( $opt['endpoint'] ) ) : '',
		'include_files' => ! empty( $opt['include_files'] ),
		'schedule'           => isset( $opt['schedule'] ) ? sanitize_text_field( $opt['schedule'] ) : 'off',
		'schedule_time'      => $schedule_time,
		'schedule_day'        => $schedule_day,
		'schedule_cron_expr'  => $schedule_cron_expr,
		'retain_count'       => isset( $opt['retain_count'] ) ? max( 0, (int) $opt['retain_count'] ) : 0,
	);
}

/**
 * Build S3 client for configured provider (AWS, B2, R2, or custom S3-compatible).
 *
 * @return \Aws\S3\S3Client|null Client or null if config/sdk missing.
 */
function ndbi_core_backup_s3_s3_client() {
	$settings = ndbi_core_backup_s3_get_settings();
	if ( empty( $settings['key_id'] ) || empty( $settings['app_key'] ) || empty( $settings['bucket'] ) ) {
		return null;
	}
	$provider = $settings['provider'];
	$presets  = ndbi_core_backup_s3_get_provider_presets();
	if ( ! isset( $presets[ $provider ] ) ) {
		$provider = 'b2';
	}
	$preset = $presets[ $provider ];
	$region = ! empty( $settings['region'] ) ? $settings['region'] : $preset['default_region'];
	$loader = NDBI_CORE_PATH . 'vendor/autoload.php';
	if ( ! file_exists( $loader ) ) {
		return null;
	}
	require_once $loader;

	$endpoint = '';
	if ( ! empty( $preset['endpoint_template'] ) ) {
		$endpoint = sprintf( $preset['endpoint_template'], $region );
	} elseif ( in_array( $provider, array( 'r2', 'custom' ), true ) && ! empty( $settings['endpoint'] ) ) {
		$endpoint = rtrim( $settings['endpoint'], '/' );
	}

	$config = array(
		'version'     => 'latest',
		'region'      => $region,
		'credentials' => array(
			'key'    => $settings['key_id'],
			'secret' => $settings['app_key'],
		),
		'use_path_style_endpoint' => ! empty( $preset['path_style'] ),
	);
	if ( $endpoint !== '' ) {
		$config['endpoint'] = $endpoint;
	}

	return new \Aws\S3\S3Client( $config );
}

/**
 * Get WordPress database table list (with prefix).
 *
 * @return string[]
 */
function ndbi_core_backup_s3_get_tables() {
	global $wpdb;
	$prefix = $wpdb->prefix;
	$like    = $wpdb->esc_like( $prefix ) . '%';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$rows = $wpdb->get_col( $wpdb->prepare( "SHOW TABLES LIKE %s", $like ) );
	return is_array( $rows ) ? $rows : array();
}

/**
 * Run the orchestrator: create run, schedule DB export and optionally file ZIP.
 */
function ndbi_core_backup_s3_run_orchestrator() {
	$settings = ndbi_core_backup_s3_get_settings();
	if ( empty( $settings['bucket'] ) || empty( $settings['key_id'] ) ) {
		ndbi_core_backup_s3_set_last_status( 'error', __( 'Backup settings incomplete.', 'ndbi-core' ) );
		return;
	}

	$run_id = wp_date( 'Y-m-d-His' );
	$upload_dir = wp_upload_dir();
	$base_dir   = isset( $upload_dir['basedir'] ) ? $upload_dir['basedir'] : sys_get_temp_dir();
	$temp_dir   = $base_dir . '/ndbi-backup-' . $run_id;
	if ( ! wp_mkdir_p( $temp_dir ) ) {
		ndbi_core_backup_s3_set_last_status( 'error', __( 'Could not create temp directory.', 'ndbi-core' ) );
		return;
	}

	$tables = ndbi_core_backup_s3_get_tables();
	$run_state = array(
		'run_id'        => $run_id,
		'temp_dir'      => $temp_dir,
		'tables'        => $tables,
		'include_files' => ! empty( $settings['include_files'] ),
		'db_file'       => $temp_dir . '/db.sql',
		'zip_file'      => $temp_dir . '/files.zip',
		'zip_file_list' => array(),
		'zip_index'     => 0,
		'zip_chunk_size' => 100,
	);
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	if ( ! is_array( $all_runs ) ) {
		$all_runs = array();
	}
	$all_runs[ $run_id ] = $run_state;
	update_option( NDBI_CORE_S3_RUN_OPTION, $all_runs );
	ndbi_core_backup_s3_log( 'Orchestrator started', array( 'run_id' => $run_id, 'include_files' => ! empty( $run_state['include_files'] ), 'tables_count' => count( $tables ), 'temp_dir' => $temp_dir ) );
	ndbi_core_backup_s3_set_last_status( 'running', __( 'Backup started.', 'ndbi-core' ) );
	ndbi_core_backup_s3_set_run_step( $run_id, 'exporting_db', __( 'Exporting database…', 'ndbi-core' ) );

	if ( ! empty( $tables ) ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), 'ndbi_core_backup_s3_export_db', array( $run_id, 0 ), NDBI_CORE_S3_GROUP );
		}
	} else {
		ndbi_core_backup_s3_schedule_finish_db_or_zip( $run_id );
	}
}

/**
 * Schedule finish_db (and optionally zip scan) when DB export is done; or just upload if no DB.
 *
 * @param string $run_id Run ID.
 */
function ndbi_core_backup_s3_schedule_finish_db_or_zip( $run_id ) {
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	if ( ! is_array( $all_runs ) || ! isset( $all_runs[ $run_id ] ) ) {
		ndbi_core_backup_s3_log( 'schedule_finish_db_or_zip: run not in option', array( 'run_id' => $run_id ) );
		return;
	}
	$run = $all_runs[ $run_id ];
	if ( ! empty( $run['tables'] ) ) {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), 'ndbi_core_backup_s3_finish_db', array( $run_id ), NDBI_CORE_S3_GROUP );
			ndbi_core_backup_s3_log( 'schedule_finish_db_or_zip: scheduled finish_db', array( 'run_id' => $run_id ) );
		}
	}
	if ( ! empty( $run['include_files'] ) && function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time(), 'ndbi_core_backup_s3_zip_scan', array( $run_id ), NDBI_CORE_S3_GROUP );
		ndbi_core_backup_s3_log( 'schedule_finish_db_or_zip: scheduled zip_scan (include_files)', array( 'run_id' => $run_id ) );
	} elseif ( empty( $run['include_files'] ) && empty( $run['tables'] ) ) {
		ndbi_core_backup_s3_cleanup_run( $run_id );
		ndbi_core_backup_s3_set_last_status( 'success', __( 'Backup completed.', 'ndbi-core' ) );
	}
}

/**
 * Export one table and append to run's db.sql; schedule next table or finish_db.
 *
 * @param string $run_id   Run ID.
 * @param int    $table_index Index of table in the tables list.
 */
function ndbi_core_backup_s3_run_export_db( $run_id, $table_index ) {
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	if ( ! is_array( $all_runs ) || ! isset( $all_runs[ $run_id ] ) ) {
		ndbi_core_backup_s3_log( 'export_db: run not in option', array( 'run_id' => $run_id, 'table_index' => $table_index ) );
		return;
	}
	$run   = $all_runs[ $run_id ];
	$tables = $run['tables'];
	if ( $table_index >= count( $tables ) ) {
		ndbi_core_backup_s3_log( 'export_db: all tables done, calling schedule_finish_db_or_zip', array( 'run_id' => $run_id ) );
		ndbi_core_backup_s3_schedule_finish_db_or_zip( $run_id );
		return;
	}

	global $wpdb;
	$table     = $tables[ $table_index ];
	$table_esc = str_replace( '`', '``', $table );
	$db_file   = $run['temp_dir'] . '/db.sql';
	$fp = fopen( $db_file, 'a' );
	if ( ! $fp ) {
		ndbi_core_backup_s3_set_last_status( 'error', __( 'Could not open DB file for append.', 'ndbi-core' ) );
		ndbi_core_backup_s3_set_run_step( $run_id, 'error', __( 'Could not open DB file for append.', 'ndbi-core' ) );
		return;
	}

	// Write table header.
	$create = $wpdb->get_row( "SHOW CREATE TABLE `" . $table_esc . "`", ARRAY_N );
	if ( $create ) {
		fwrite( $fp, "\n-- Table: " . $table . "\n" );
		fwrite( $fp, "DROP TABLE IF EXISTS `" . $table_esc . "`;\n" );
		fwrite( $fp, $create[1] . ";\n" );
	}
	$cols = $wpdb->get_col( "SHOW COLUMNS FROM `" . $table_esc . "`" );
		if ( $cols ) {
			$batch_size = 500;
			$offset     = 0;
			while ( true ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$rows = $wpdb->get_results( "SELECT * FROM `" . $table_esc . "` LIMIT " . (int) $offset . ',' . (int) $batch_size, ARRAY_A );
				if ( empty( $rows ) ) {
					break;
				}
				foreach ( $rows as $row ) {
					$col_names = '`' . implode( '`,`', array_map( function( $col ) { return str_replace( '`', '``', $col ); }, array_keys( $row ) ) ) . '`';
					$placeholders = implode( ',', array_fill( 0, count( $row ), '%s' ) );
					$query = $wpdb->prepare( "INSERT INTO `" . $table_esc . "` (" . $col_names . ") VALUES (" . $placeholders . ")", array_values( $row ) );
					fwrite( $fp, $query . ";\n" );
				}
				$offset += $batch_size;
			}
		}
	fclose( $fp );

	$next = $table_index + 1;
	if ( $next < count( $tables ) && function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time(), 'ndbi_core_backup_s3_export_db', array( $run_id, $next ), NDBI_CORE_S3_GROUP );
	} else {
		ndbi_core_backup_s3_schedule_finish_db_or_zip( $run_id );
	}
}

/**
 * Gzip DB file and upload to B2; then schedule zip scan if include_files, else mark done.
 *
 * @param string $run_id Run ID.
 */
function ndbi_core_backup_s3_run_finish_db( $run_id ) {
	ndbi_core_backup_s3_log( 'finish_db started', array( 'run_id' => $run_id ) );
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	if ( ! is_array( $all_runs ) || ! isset( $all_runs[ $run_id ] ) ) {
		ndbi_core_backup_s3_log( 'finish_db: run not in option, aborting', array( 'run_id' => $run_id ) );
		return;
	}
	$run     = $all_runs[ $run_id ];
	$db_file = $run['temp_dir'] . '/db.sql';
	if ( ! file_exists( $db_file ) ) {
		ndbi_core_backup_s3_log( 'finish_db: db.sql missing (no tables?), scheduling zip_scan', array( 'run_id' => $run_id, 'include_files' => ! empty( $run['include_files'] ) ) );
		if ( ! empty( $run['include_files'] ) && function_exists( 'as_schedule_single_action' ) ) {
			ndbi_core_backup_s3_set_run_step( $run_id, 'scanning_files', __( 'Scanning files…', 'ndbi-core' ) );
			as_schedule_single_action( time(), 'ndbi_core_backup_s3_zip_scan', array( $run_id ), NDBI_CORE_S3_GROUP );
		} else {
			ndbi_core_backup_s3_cleanup_run( $run_id );
			ndbi_core_backup_s3_set_last_status( 'success', __( 'Backup completed.', 'ndbi-core' ) );
		}
		return;
	}

	$site_slug = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site' );
	$gz_file   = $run['temp_dir'] . '/db.sql.gz';
	$fp_in     = fopen( $db_file, 'rb' );
	$fp_gz     = gzopen( $gz_file, 'wb9' );
	if ( ! $fp_in || ! $fp_gz ) {
		ndbi_core_backup_s3_set_last_status( 'error', __( 'Could not gzip DB file.', 'ndbi-core' ) );
		ndbi_core_backup_s3_set_run_step( $run_id, 'error', __( 'Could not gzip DB file.', 'ndbi-core' ) );
		return;
	}
	while ( ! feof( $fp_in ) ) {
		gzwrite( $fp_gz, fread( $fp_in, 8192 ) );
	}
	fclose( $fp_in );
	gzclose( $fp_gz );
	unlink( $db_file );

	ndbi_core_backup_s3_set_run_step( $run_id, 'uploading_db', __( 'Uploading database…', 'ndbi-core' ) );
	$settings = ndbi_core_backup_s3_get_settings();
	$key_suffix = $site_slug . '-db-' . $run_id . '.sql.gz';
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time(), 'ndbi_core_backup_s3_upload', array( $run_id, 'db', $gz_file, $key_suffix ), NDBI_CORE_S3_GROUP );
	}

	if ( ! empty( $run['include_files'] ) && function_exists( 'as_schedule_single_action' ) ) {
		ndbi_core_backup_s3_set_run_step( $run_id, 'scanning_files', __( 'Scanning files…', 'ndbi-core' ) );
		as_schedule_single_action( time(), 'ndbi_core_backup_s3_zip_scan', array( $run_id ), NDBI_CORE_S3_GROUP );
		ndbi_core_backup_s3_log( 'finish_db: scheduled zip_scan (include_files)', array( 'run_id' => $run_id ) );
	}
}

/**
 * Build file list for entire site root (ABSPATH) with exclusions; store in run state; schedule first zip chunk.
 * Includes wp-config.php, wp-content, wp-includes, and all other site files.
 *
 * @param string $run_id Run ID.
 */
function ndbi_core_backup_s3_run_zip_scan( $run_id ) {
	ndbi_core_backup_s3_log( 'zip_scan started', array( 'run_id' => $run_id ) );
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	if ( ! is_array( $all_runs ) || ! isset( $all_runs[ $run_id ] ) ) {
		ndbi_core_backup_s3_log( 'zip_scan: run not in option, aborting', array( 'run_id' => $run_id ) );
		return;
	}
	$run       = $all_runs[ $run_id ];
	$site_root = rtrim( ABSPATH, DIRECTORY_SEPARATOR );
	if ( ! is_dir( $site_root ) ) {
		ndbi_core_backup_s3_log( 'zip_scan: site root not a dir', array( 'run_id' => $run_id, 'path' => $site_root ) );
		ndbi_core_backup_s3_zip_done_and_cleanup( $run_id );
		return;
	}

	$exclude = array( 'cache', 'cache/*', '*.log', 'node_modules', 'node_modules/*', 'ndbi-backup-*' );
	$files   = ndbi_core_backup_s3_list_files( $site_root, $site_root, $exclude );
	$run['zip_file_list'] = array_values( $files );
	$run['zip_index']     = 0;
	$run['zip_base_path'] = $site_root; // So zip_chunk uses same base for relative paths.
	$all_runs[ $run_id ]  = $run;
	update_option( NDBI_CORE_S3_RUN_OPTION, $all_runs );

	ndbi_core_backup_s3_log( 'zip_scan: file list built', array( 'run_id' => $run_id, 'file_count' => count( $run['zip_file_list'] ) ) );

	if ( empty( $run['zip_file_list'] ) ) {
		ndbi_core_backup_s3_log( 'zip_scan: no files, finishing', array( 'run_id' => $run_id ) );
		ndbi_core_backup_s3_zip_done_and_cleanup( $run_id );
		return;
	}
	ndbi_core_backup_s3_set_run_step( $run_id, 'zipping_files', __( 'Zipping files…', 'ndbi-core' ) );
	if ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time(), 'ndbi_core_backup_s3_zip_chunk', array( $run_id ), NDBI_CORE_S3_GROUP );
		ndbi_core_backup_s3_log( 'zip_scan: scheduled first zip_chunk', array( 'run_id' => $run_id ) );
	}
}

/**
 * Recursively list files under $dir (full paths). $base is used only for exclude matching.
 * $base should have no trailing slash so relative segment is substr( path, strlen(base) + 1 ).
 *
 * @param string   $dir    Directory to scan.
 * @param string   $base   Base path (no trailing slash) for relative segment.
 * @param string[] $exclude Exclude patterns (e.g. 'cache', '*.log').
 * @return string[] Full absolute paths of files.
 */
function ndbi_core_backup_s3_list_files( $dir, $base, $exclude ) {
	$list = array();
	if ( ! is_dir( $dir ) ) {
		return $list;
	}
	$items = @scandir( $dir );
	if ( ! is_array( $items ) ) {
		return $list;
	}
	foreach ( $items as $item ) {
		if ( $item === '.' || $item === '..' ) {
			continue;
		}
		$path = $dir . '/' . $item;
		$rel  = substr( $path, strlen( $base ) + 1 );
		$skip = false;
		foreach ( $exclude as $pat ) {
			if ( $pat === $item || fnmatch( $pat, $item ) || strpos( $rel, $pat ) !== false ) {
				$skip = true;
				break;
			}
		}
		if ( $skip ) {
			continue;
		}
		if ( is_dir( $path ) ) {
			$list = array_merge( $list, ndbi_core_backup_s3_list_files( $path, $base, $exclude ) );
		} else {
			$list[] = $path;
		}
	}
	return $list;
}

/**
 * Add one chunk of files to the ZIP; schedule next chunk or upload.
 *
 * @param string $run_id Run ID.
 */
function ndbi_core_backup_s3_run_zip_chunk( $run_id ) {
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	if ( ! is_array( $all_runs ) || ! isset( $all_runs[ $run_id ] ) ) {
		ndbi_core_backup_s3_log( 'zip_chunk: run not in option', array( 'run_id' => $run_id ) );
		return;
	}
	$run   = $all_runs[ $run_id ];
	$list  = $run['zip_file_list'];
	$index = (int) $run['zip_index'];
	$chunk_size = isset( $run['zip_chunk_size'] ) ? (int) $run['zip_chunk_size'] : 100;
	$zip_path   = $run['zip_file'];
	$base_path  = isset( $run['zip_base_path'] ) ? $run['zip_base_path'] : rtrim( ABSPATH, DIRECTORY_SEPARATOR );

	ndbi_core_backup_s3_log( 'zip_chunk running', array( 'run_id' => $run_id, 'index' => $index, 'total' => count( $list ), 'zip_path' => $zip_path ) );

	$zip = new ZipArchive();
	// CREATE: first chunk creates the archive; subsequent chunks open existing for append.
	if ( $zip->open( $zip_path, ZipArchive::CREATE ) !== true ) {
		ndbi_core_backup_s3_log( 'zip_chunk: ZipArchive open failed', array( 'run_id' => $run_id, 'zip_path' => $zip_path ) );
		ndbi_core_backup_s3_set_last_status( 'error', __( 'Could not open ZIP for append.', 'ndbi-core' ) );
		ndbi_core_backup_s3_set_run_step( $run_id, 'error', __( 'Could not open ZIP for append.', 'ndbi-core' ) );
		return;
	}
	$base_len = strlen( $base_path ) + 1;
	$end = min( $index + $chunk_size, count( $list ) );
	for ( $i = $index; $i < $end; $i++ ) {
		$abs = $list[ $i ];
		if ( is_file( $abs ) ) {
			// Path inside ZIP: relative to site root (e.g. wp-config.php, wp-content/plugins/...).
			$local = ltrim( str_replace( '\\', '/', substr( $abs, $base_len ) ), '/' );
			$zip->addFile( $abs, $local );
		}
	}
	$zip->close();

	$run['zip_index'] = $end;
	$all_runs[ $run_id ] = $run;
	update_option( NDBI_CORE_S3_RUN_OPTION, $all_runs );

	if ( $end >= count( $list ) ) {
		ndbi_core_backup_s3_set_run_step( $run_id, 'uploading_files', __( 'Uploading files…', 'ndbi-core' ) );
		$site_slug   = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site' );
		$key_suffix  = $site_slug . '-files-' . $run_id . '.zip';
		ndbi_core_backup_s3_log( 'zip_chunk: all files added, scheduling upload', array( 'run_id' => $run_id, 'zip_path' => $zip_path, 'key_suffix' => $key_suffix ) );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time(), 'ndbi_core_backup_s3_upload', array( $run_id, 'files', $zip_path, $key_suffix ), NDBI_CORE_S3_GROUP );
		}
	} elseif ( function_exists( 'as_schedule_single_action' ) ) {
		as_schedule_single_action( time(), 'ndbi_core_backup_s3_zip_chunk', array( $run_id ), NDBI_CORE_S3_GROUP );
		ndbi_core_backup_s3_log( 'zip_chunk: scheduled next chunk', array( 'run_id' => $run_id, 'next_index' => $end ) );
	}
}

/**
 * When ZIP is done (no file list or upload scheduled), cleanup and set status.
 *
 * @param string $run_id Run ID.
 */
function ndbi_core_backup_s3_zip_done_and_cleanup( $run_id ) {
	ndbi_core_backup_s3_cleanup_run( $run_id );
	ndbi_core_backup_s3_set_last_status( 'success', __( 'Backup completed.', 'ndbi-core' ) );
}

/**
 * Upload a file to B2 (PutObject or multipart). Then remove temp file and optionally cleanup run.
 *
 * @param string $run_id     Run ID.
 * @param string $type      'db' or 'files'.
 * @param string $file_path Local path.
 * @param string $key_suffix Object key suffix (path_prefix is prepended from settings).
 */
function ndbi_core_backup_s3_run_upload( $run_id, $type, $file_path, $key_suffix ) {
	ndbi_core_backup_s3_log( 'upload started', array( 'run_id' => $run_id, 'type' => $type, 'file_path' => $file_path, 'key_suffix' => $key_suffix, 'file_exists' => file_exists( $file_path ) ) );
	if ( ! file_exists( $file_path ) ) {
		ndbi_core_backup_s3_log( 'upload: file missing, aborting', array( 'run_id' => $run_id, 'type' => $type, 'file_path' => $file_path ) );
		ndbi_core_backup_s3_cleanup_run( $run_id );
		return;
	}
	$client  = ndbi_core_backup_s3_s3_client();
	if ( ! $client ) {
		ndbi_core_backup_s3_log( 'upload: S3 client null', array( 'run_id' => $run_id, 'type' => $type ) );
		ndbi_core_backup_s3_set_last_status( 'error', __( 'S3 client not available.', 'ndbi-core' ) );
		ndbi_core_backup_s3_set_run_step( $run_id, 'error', __( 'S3 client not available.', 'ndbi-core' ) );
		return;
	}
	$settings   = ndbi_core_backup_s3_get_settings();
	$bucket     = $settings['bucket'];
	$path_prefix = isset( $settings['path_prefix'] ) ? $settings['path_prefix'] : '';
	$key        = $path_prefix . $key_suffix;

	$fh = fopen( $file_path, 'rb' );
	if ( $fh === false ) {
		ndbi_core_backup_s3_log( 'upload: fopen failed', array( 'run_id' => $run_id, 'type' => $type, 'file_path' => $file_path ) );
		ndbi_core_backup_s3_set_last_status( 'error', __( 'Could not open file for upload.', 'ndbi-core' ) );
		ndbi_core_backup_s3_set_run_step( $run_id, 'error', __( 'Could not open file for upload.', 'ndbi-core' ) );
		ndbi_core_backup_s3_cleanup_run( $run_id );
		return;
	}
	try {
		$size = filesize( $file_path );
		if ( $size >= NDBI_CORE_S3_MULTIPART_THRESHOLD ) {
			// Omit ACL for S3-compatible APIs (e.g. B2, R2) that may not support it.
			$client->upload( $bucket, $key, $fh, '', array(
				'mup_threshold' => NDBI_CORE_S3_MULTIPART_THRESHOLD,
				'params'        => array( 'ACL' => null ),
			) );
		} else {
			$client->putObject( array(
				'Bucket' => $bucket,
				'Key'    => $key,
				'Body'   => $fh,
			) );
		}
	} catch ( Exception $e ) {
		fclose( $fh );
		ndbi_core_backup_s3_log( 'upload: exception', array( 'run_id' => $run_id, 'type' => $type, 'message' => $e->getMessage() ) );
		ndbi_core_backup_s3_set_last_status( 'error', $e->getMessage() );
		ndbi_core_backup_s3_set_run_step( $run_id, 'error', $e->getMessage() );
		ndbi_core_backup_s3_cleanup_run( $run_id );
		return;
	}
	fclose( $fh );

	ndbi_core_backup_s3_log( 'upload success', array( 'run_id' => $run_id, 'type' => $type, 'key' => $key ) );
	// Record last successful upload time for admin bar at-a-glance.
	$success_times = get_option( 'ndbi_core_backup_s3_last_success', array() );
	if ( ! is_array( $success_times ) ) {
		$success_times = array();
	}
	$success_times[ $type ] = time();
	update_option( 'ndbi_core_backup_s3_last_success', $success_times );
	@unlink( $file_path );

	// Only finish the run when the final upload is done. If we just uploaded DB and
	// include_files is true, zip_scan/files upload will run later — do not cleanup yet.
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	$run      = is_array( $all_runs ) && isset( $all_runs[ $run_id ] ) ? $all_runs[ $run_id ] : array();
	$is_final = ( $type === 'files' ) || ( empty( $run['include_files'] ) );
	ndbi_core_backup_s3_log( 'upload: is_final check', array( 'run_id' => $run_id, 'type' => $type, 'include_files' => ! empty( $run['include_files'] ), 'is_final' => $is_final ) );
	if ( $is_final ) {
		ndbi_core_backup_s3_maybe_finish_run( $run_id );
	}
}

/**
 * If run has no more pending temp files, cleanup and set success.
 *
 * @param string $run_id Run ID.
 */
function ndbi_core_backup_s3_maybe_finish_run( $run_id ) {
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	if ( ! is_array( $all_runs ) || ! isset( $all_runs[ $run_id ] ) ) {
		return;
	}
	$run = $all_runs[ $run_id ];
	$temp_dir = $run['temp_dir'];
	$remaining = false;
	if ( is_dir( $temp_dir ) ) {
		$files = @scandir( $temp_dir );
		if ( is_array( $files ) && count( $files ) > 2 ) {
			$remaining = true;
		}
	}
	if ( ! $remaining ) {
		ndbi_core_backup_s3_set_run_step( $run_id, 'done', __( 'Backup completed.', 'ndbi-core' ) );
		ndbi_core_backup_s3_cleanup_run( $run_id );
		ndbi_core_backup_s3_set_last_status( 'success', __( 'Backup completed.', 'ndbi-core' ) );
		$settings = ndbi_core_backup_s3_get_settings();
		if ( ! empty( $settings['retain_count'] ) ) {
			ndbi_core_backup_s3_apply_retention( (int) $settings['retain_count'] );
		}
	}
}

/**
 * Delete run state and temp directory.
 *
 * @param string $run_id Run ID.
 */
function ndbi_core_backup_s3_cleanup_run( $run_id ) {
	$all_runs = get_option( NDBI_CORE_S3_RUN_OPTION, array() );
	if ( is_array( $all_runs ) && isset( $all_runs[ $run_id ] ) ) {
		$run = $all_runs[ $run_id ];
		if ( ! empty( $run['temp_dir'] ) && is_dir( $run['temp_dir'] ) ) {
			$files = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $run['temp_dir'], RecursiveDirectoryIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
			foreach ( $files as $file ) {
				if ( $file->isDir() ) {
					@rmdir( $file->getRealPath() );
				} else {
					@unlink( $file->getRealPath() );
				}
			}
			@rmdir( $run['temp_dir'] );
		}
		unset( $all_runs[ $run_id ] );
		update_option( NDBI_CORE_S3_RUN_OPTION, $all_runs );
	}
}

/**
 * Option key for run log (recent runs for status tracker).
 */
const NDBI_CORE_S3_RUN_LOG_OPTION = 'ndbi_core_backup_s3_run_log';

/**
 * Store last backup status for admin UI.
 *
 * @param string $status 'running'|'success'|'error'.
 * @param string $message Message.
 */
function ndbi_core_backup_s3_set_last_status( $status, $message ) {
	update_option( 'ndbi_core_backup_s3_last_status', array(
		'status'  => $status,
		'message' => $message,
		'time'    => time(),
	) );
}

/**
 * Update run step in the run log for status tracker.
 *
 * @param string $run_id  Run ID.
 * @param string $step    Step name (e.g. 'exporting_db', 'uploading_db', 'zipping_files', 'uploading_files', 'done', 'error').
 * @param string $message Optional message.
 */
function ndbi_core_backup_s3_set_run_step( $run_id, $step, $message = '' ) {
	$log = get_option( NDBI_CORE_S3_RUN_LOG_OPTION, array() );
	if ( ! is_array( $log ) ) {
		$log = array();
	}
	$now = time();
	if ( ! isset( $log[ $run_id ] ) ) {
		$log[ $run_id ] = array(
			'run_id'     => $run_id,
			'status'     => 'running',
			'step'       => $step,
			'started_at' => $now,
			'message'    => '',
		);
	}
	$log[ $run_id ]['step']       = $step;
	$log[ $run_id ]['updated_at'] = $now;
	$log[ $run_id ]['message']    = $message;
	if ( in_array( $step, array( 'done', 'error' ), true ) ) {
		$log[ $run_id ]['status'] = $step;
	}
	// Keep last 50 runs.
	$log = array_slice( $log, -50, 50, true );
	update_option( NDBI_CORE_S3_RUN_LOG_OPTION, $log );
}

/**
 * Remove a run from the run log (e.g. after deleting from storage).
 *
 * @param string $run_id Run ID.
 */
function ndbi_core_backup_s3_remove_run_from_log( $run_id ) {
	$log = get_option( NDBI_CORE_S3_RUN_LOG_OPTION, array() );
	if ( ! is_array( $log ) ) {
		return;
	}
	unset( $log[ $run_id ] );
	update_option( NDBI_CORE_S3_RUN_LOG_OPTION, $log );
}

/**
 * Get run log for status tracker (recent runs, newest first).
 *
 * @return array<int, array{run_id: string, status: string, step: string, started_at: int, updated_at?: int, message: string}>
 */
function ndbi_core_backup_s3_get_run_log() {
	$log = get_option( NDBI_CORE_S3_RUN_LOG_OPTION, array() );
	if ( ! is_array( $log ) ) {
		return array();
	}
	$log = array_reverse( $log );
	return array_values( array_slice( $log, 0, 20 ) );
}

/**
 * Get last backup status.
 *
 * @return array{status: string, message: string, time: int}|null
 */
function ndbi_core_backup_s3_get_last_status() {
	return get_option( 'ndbi_core_backup_s3_last_status', null );
}

/**
 * Get last successful upload times for DB and files (for admin bar tooltip).
 *
 * @return array{db: int|null, files: int|null}
 */
function ndbi_core_backup_s3_get_last_success_times() {
	$raw = get_option( 'ndbi_core_backup_s3_last_success', array() );
	if ( ! is_array( $raw ) ) {
		$raw = array();
	}
	return array(
		'db'    => isset( $raw['db'] ) && is_numeric( $raw['db'] ) ? (int) $raw['db'] : null,
		'files' => isset( $raw['files'] ) && is_numeric( $raw['files'] ) ? (int) $raw['files'] : null,
	);
}

/**
 * List backup objects in the bucket and delete oldest beyond retain_count.
 * Keeps the most recent retain_count runs (each run = 1 db file + optionally 1 files zip).
 *
 * @param int $retain_count Number of backup runs to keep (0 = keep all).
 */
function ndbi_core_backup_s3_apply_retention( $retain_count ) {
	if ( $retain_count <= 0 ) {
		return;
	}
	$client  = ndbi_core_backup_s3_s3_client();
	$settings = ndbi_core_backup_s3_get_settings();
	if ( ! $client || empty( $settings['bucket'] ) ) {
		return;
	}
	$path_prefix = isset( $settings['path_prefix'] ) ? $settings['path_prefix'] : '';
	$site_slug   = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site' );

	$objects = array();
	$params  = array( 'Bucket' => $settings['bucket'], 'Prefix' => $path_prefix . $site_slug . '-' );
	try {
		$paginator = $client->getPaginator( 'ListObjectsV2', $params );
		foreach ( $paginator as $result ) {
			if ( empty( $result['Contents'] ) ) {
				continue;
			}
			foreach ( $result['Contents'] as $obj ) {
				if ( empty( $obj['Key'] ) ) {
					continue;
				}
				$key = $obj['Key'];
				if ( preg_match( '/-db-(\d{4}-\d{2}-\d{2}-\d{6})\.sql\.gz$/', $key, $m ) || preg_match( '/-files-(\d{4}-\d{2}-\d{2}-\d{6})\.zip$/', $key, $m ) ) {
					$run_id = $m[1];
					if ( ! isset( $objects[ $run_id ] ) ) {
						$objects[ $run_id ] = array();
					}
					$objects[ $run_id ][] = array( 'Key' => $key, 'LastModified' => isset( $obj['LastModified'] ) ? $obj['LastModified'] : null );
				}
			}
		}
	} catch ( Exception $e ) {
		return;
	}
	if ( empty( $objects ) ) {
		return;
	}
	uksort( $objects, 'strcmp' );
	$run_ids = array_keys( $objects );
	$keep    = array_slice( $run_ids, -$retain_count );
	$delete  = array_diff( $run_ids, $keep );
	foreach ( $delete as $run_id ) {
		foreach ( $objects[ $run_id ] as $item ) {
			try {
				$client->deleteObject( array( 'Bucket' => $settings['bucket'], 'Key' => $item['Key'] ) );
			} catch ( Exception $e ) {
				// Continue with next.
			}
		}
		ndbi_core_backup_s3_remove_run_from_log( $run_id );
	}
}

/**
 * Get a temporary presigned URL to download a backup file (DB or files zip).
 *
 * @param string $run_id Run ID (e.g. 2025-03-07-143052).
 * @param string $type   'db' or 'files'.
 * @return string|null Presigned URL valid for 1 hour, or null on error.
 */
function ndbi_core_backup_s3_get_download_url( $run_id, $type ) {
	$run_id = sanitize_text_field( $run_id );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}-\d{6}$/', $run_id ) || ! in_array( $type, array( 'db', 'files' ), true ) ) {
		return null;
	}
	$client   = ndbi_core_backup_s3_s3_client();
	$settings = ndbi_core_backup_s3_get_settings();
	if ( ! $client || empty( $settings['bucket'] ) ) {
		return null;
	}
	$path_prefix = isset( $settings['path_prefix'] ) ? $settings['path_prefix'] : '';
	$site_slug   = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site' );
	$key         = $path_prefix . $site_slug . '-' . ( $type === 'db' ? 'db' : 'files' ) . '-' . $run_id . ( $type === 'db' ? '.sql.gz' : '.zip' );
	try {
		$cmd    = $client->getCommand( 'GetObject', array( 'Bucket' => $settings['bucket'], 'Key' => $key ) );
		$request = $client->createPresignedRequest( $cmd, '+1 hour' );
		return (string) $request->getUri();
	} catch ( Exception $e ) {
		return null;
	}
}

/**
 * Delete one backup run's objects from S3 (DB and files zip).
 *
 * @param string $run_id Run ID (e.g. 2025-03-07-143052).
 * @return bool True if delete succeeded (or objects did not exist), false on client error.
 */
function ndbi_core_backup_s3_delete_run_from_storage( $run_id ) {
	$run_id = sanitize_text_field( $run_id );
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}-\d{6}$/', $run_id ) ) {
		return false;
	}
	$client   = ndbi_core_backup_s3_s3_client();
	$settings = ndbi_core_backup_s3_get_settings();
	if ( ! $client || empty( $settings['bucket'] ) ) {
		return false;
	}
	$path_prefix = isset( $settings['path_prefix'] ) ? $settings['path_prefix'] : '';
	$site_slug   = sanitize_file_name( wp_parse_url( home_url(), PHP_URL_HOST ) ?: 'site' );
	$keys        = array(
		$path_prefix . $site_slug . '-db-' . $run_id . '.sql.gz',
		$path_prefix . $site_slug . '-files-' . $run_id . '.zip',
	);
	try {
		foreach ( $keys as $key ) {
			$client->deleteObject( array( 'Bucket' => $settings['bucket'], 'Key' => $key ) );
		}
		return true;
	} catch ( Exception $e ) {
		return false;
	}
}

/**
 * Whether the cron-expression dependency is available (Custom cron schedule can be used).
 *
 * @return bool True if dragonmantank/cron-expression is loaded.
 */
function ndbi_core_backup_s3_cron_available() {
	return class_exists( 'Cron\CronExpression' );
}

/**
 * Compute the next run timestamp from a cron expression (site timezone).
 * Uses dragonmantank/cron-expression; returns null if expr invalid or library missing.
 *
 * @param string $cron_expr Five-field cron expression (e.g. "0 2 * * *" for 02:00 daily).
 * @return int|null Unix timestamp for next run, or null.
 */
function ndbi_core_backup_s3_next_cron_timestamp( $cron_expr ) {
	if ( $cron_expr === '' || ! ndbi_core_backup_s3_cron_available() ) {
		return null;
	}
	try {
		$cron = \Cron\CronExpression::factory( $cron_expr );
		$tz   = wp_timezone();
		$now  = new \DateTimeImmutable( 'now', $tz );
		$next = $cron->getNextRunDate( $now );
		return $next->getTimestamp();
	} catch ( \Throwable $e ) {
		return null;
	}
}

/**
 * Fired by Action Scheduler when using cron mode: run backup then schedule next cron tick.
 */
function ndbi_core_backup_s3_run_cron_tick() {
	ndbi_core_backup_s3_run_orchestrator();
	ndbi_core_backup_s3_schedule_cron();
}

/**
 * Compute the next run timestamp for scheduled backup (at schedule_time, and for weekly at schedule_day).
 *
 * @param array $settings Backup settings (schedule, schedule_time, schedule_day).
 * @return int Unix timestamp for next run.
 */
function ndbi_core_backup_s3_next_scheduled_time( $settings ) {
	$time_str = isset( $settings['schedule_time'] ) ? $settings['schedule_time'] : '00:00';
	$day      = isset( $settings['schedule_day'] ) ? (int) $settings['schedule_day'] : 0;
	$tz       = wp_timezone();
	$now      = new DateTimeImmutable( 'now', $tz );
	list( $hour, $min ) = array_map( 'intval', explode( ':', $time_str, 2 ) );
	if ( $settings['schedule'] === 'weekly' ) {
		$next = $now->setTime( $hour, $min, 0 );
		$current_dow = (int) $next->format( 'w' ); // 0 = Sunday.
		$days_ahead = $day - $current_dow;
		if ( $days_ahead < 0 ) {
			$days_ahead += 7;
		} elseif ( $days_ahead === 0 && $next <= $now ) {
			$days_ahead = 7;
		}
		$next = $next->modify( "+{$days_ahead} days" );
	} else {
		$next = $now->setTime( $hour, $min, 0 );
		if ( $next <= $now ) {
			$next = $next->modify( '+1 day' );
		}
	}
	return $next->getTimestamp();
}

/**
 * Register or clear scheduled backup based on settings.
 *
 * Scheduling uses WooCommerce Action Scheduler:
 * - Daily/Weekly: one recurring action at the chosen time (site timezone).
 * - Custom (cron): one single action at the next cron run; when it fires, the backup runs
 *   and the next cron time is scheduled again.
 */
function ndbi_core_backup_s3_schedule_cron() {
	if ( ! function_exists( 'as_unschedule_all_actions' ) || ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_schedule_single_action' ) ) {
		return;
	}
	$group = NDBI_CORE_S3_GROUP;
	as_unschedule_all_actions( 'ndbi_core_backup_s3_orchestrator', array(), $group );
	as_unschedule_all_actions( 'ndbi_core_backup_s3_cron_tick', array(), $group );

	$settings = ndbi_core_backup_s3_get_settings();
	if ( empty( $settings['bucket'] ) || $settings['schedule'] === 'off' ) {
		return;
	}

	if ( $settings['schedule'] === 'cron' && ! empty( $settings['schedule_cron_expr'] ) ) {
		$next = ndbi_core_backup_s3_next_cron_timestamp( $settings['schedule_cron_expr'] );
		if ( $next !== null ) {
			as_schedule_single_action( $next, 'ndbi_core_backup_s3_cron_tick', array(), $group );
		}
		return;
	}

	if ( $settings['schedule'] !== 'daily' && $settings['schedule'] !== 'weekly' ) {
		return;
	}
	$interval  = $settings['schedule'] === 'weekly' ? WEEK_IN_SECONDS : DAY_IN_SECONDS;
	$first_run = ndbi_core_backup_s3_next_scheduled_time( $settings );
	as_schedule_recurring_action( $first_run, $interval, 'ndbi_core_backup_s3_orchestrator', array(), $group, true );
}
