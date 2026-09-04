<?php
/**
 * Migration logging and reporting (EVENTS_4.1_REBUILD.md §5.2): a persistent
 * per-item log table, step summaries, and a CSV export.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function law_migration_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'law_migration_log';
}

/** Create the log table when first needed. */
function law_migration_install_log_table() {
	global $wpdb;
	$table = law_migration_log_table();
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		"CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id VARCHAR(32) NOT NULL DEFAULT '',
			step VARCHAR(40) NOT NULL DEFAULT '',
			item_ref VARCHAR(120) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'info',
			message TEXT NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY step (step),
			KEY status (status)
		) {$wpdb->get_charset_collate()};"
	);
}

/**
 * @param string $step     Step key.
 * @param string $status   created / skipped / warning / error / info / dry-run.
 * @param string $item_ref e.g. "entry 190" or "child 512".
 * @param string $message  What happened.
 */
function law_migration_log( $step, $status, $item_ref, $message ) {
	global $wpdb;
	law_migration_install_log_table();
	$wpdb->insert(
		law_migration_log_table(),
		array(
			'run_id'     => (string) get_option( 'law_migration_run_id', '' ),
			'step'       => sanitize_key( $step ),
			'item_ref'   => sanitize_text_field( $item_ref ),
			'status'     => sanitize_key( $status ),
			'message'    => sanitize_textarea_field( $message ),
			'created_at' => current_time( 'mysql' ),
		)
	);
}

/** Per-step counts by status, for the step cards. */
function law_migration_step_summary( $step ) {
	global $wpdb;
	law_migration_install_log_table();
	$table = law_migration_log_table();
	$rows  = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT status, COUNT(*) c FROM {$table} WHERE step = %s AND run_id = %s GROUP BY status",
			sanitize_key( $step ),
			(string) get_option( 'law_migration_run_id', '' )
		)
	);
	$summary = array();
	foreach ( (array) $rows as $row ) {
		$summary[ $row->status ] = (int) $row->c;
	}
	return $summary;
}

/** The most recent log lines for the live tail. */
function law_migration_log_tail( $limit = 12 ) {
	global $wpdb;
	law_migration_install_log_table();
	$table = law_migration_log_table();
	return (array) $wpdb->get_results(
		$wpdb->prepare( "SELECT step, status, item_ref, message, created_at FROM {$table} ORDER BY id DESC LIMIT %d", (int) $limit )
	);
}

/* Snapshot download (PHP-gated; the file URL is never linked directly) ______ */

add_action( 'admin_post_law_migration_snapshot_download', function () {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to download the snapshot.' );
	}
	check_admin_referer( 'law_migration_snapshot_download' );

	$snapshot = get_option( 'law_migration_snapshot' );
	if ( ! is_array( $snapshot ) || empty( $snapshot['file'] ) || ! file_exists( $snapshot['file'] ) ) {
		wp_die( 'No snapshot file exists.' );
	}

	nocache_headers();
	header( 'Content-Type: application/gzip' );
	header( 'Content-Length: ' . filesize( $snapshot['file'] ) );
	header( 'Content-Disposition: attachment; filename=' . basename( $snapshot['file'] ) );
	readfile( $snapshot['file'] );
	exit;
} );

/* CSV export ________________________________________________________________ */

add_action( 'admin_post_law_migration_export', function () {
	check_admin_referer( 'law_migration_export' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to export the migration report.' );
	}

	global $wpdb;
	law_migration_install_log_table();
	$table = law_migration_log_table();
	$rows  = $wpdb->get_results( "SELECT run_id, step, item_ref, status, message, created_at FROM {$table} ORDER BY id ASC", ARRAY_A );

	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=law-migration-report-' . gmdate( 'Ymd-His' ) . '.csv' );
	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, array( 'run_id', 'step', 'item_ref', 'status', 'message', 'created_at' ) );
	foreach ( (array) $rows as $row ) {
		fputcsv( $out, $row );
	}
	exit;
} );
