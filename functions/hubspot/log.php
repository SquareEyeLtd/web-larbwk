<?php
/**
 * The HubSpot sync log (_docs/HUBSPOT_SYNC.md §6.7): one row per person per
 * push, plus the dry-mode record of every write the client declined to send.
 *
 * Its own table rather than law_event_log(), because most syncs have no
 * single event to hang off. Pruned to 90 days by the worker.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_HUBSPOT_DB_VERSION = '1';

function law_hubspot_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'law_hubspot_log';
}

function law_hubspot_queue_table() {
	global $wpdb;
	return $wpdb->prefix . 'law_hubspot_queue';
}

/**
 * Create or upgrade both module tables. Lazy, like the migration log table:
 * called from the first write and guarded by a version option so the normal
 * path costs one autoloaded option read, not a SHOW TABLES.
 *
 * Tests that reach these tables install them in setUpBeforeClass(), outside
 * the per-test transaction, because CREATE TABLE commits (see
 * LAW_Test_Case::$use_transaction).
 */
function law_hubspot_install_tables() {
	global $wpdb;
	static $checked = false;

	if ( $checked ) {
		return;
	}
	if ( (string) get_option( 'law_hubspot_db_version', '' ) === LAW_HUBSPOT_DB_VERSION
		&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', law_hubspot_queue_table() ) ) === law_hubspot_queue_table() ) {
		$checked = true;
		return;
	}

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset = $wpdb->get_charset_collate();
	$queue   = law_hubspot_queue_table();
	$log     = law_hubspot_log_table();

	dbDelta(
		"CREATE TABLE {$queue} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL DEFAULT '',
			reason VARCHAR(80) NOT NULL DEFAULT '',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			parked TINYINT(1) NOT NULL DEFAULT 0,
			next_attempt_at DATETIME NOT NULL,
			last_error TEXT NOT NULL,
			queued_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY due (parked,next_attempt_at)
		) {$charset};"
	);

	dbDelta(
		"CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			email VARCHAR(190) NOT NULL DEFAULT '',
			action VARCHAR(40) NOT NULL DEFAULT '',
			detail TEXT NOT NULL,
			result VARCHAR(20) NOT NULL DEFAULT 'ok',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email),
			KEY created_at (created_at)
		) {$charset};"
	);

	update_option( 'law_hubspot_db_version', LAW_HUBSPOT_DB_VERSION, false );
	$checked = true;
}

/**
 * Record what happened to one person (or, for actions with no person, what
 * the module did).
 *
 * @param string       $email  Address, or '' for module-level entries.
 * @param string       $action sync | dry-write | properties | worker | ...
 * @param string|array $detail What changed, or the payload. Arrays are JSON-encoded.
 * @param string       $result ok | dry | error | skipped.
 */
function law_hubspot_log( $email, $action, $detail, $result = 'ok' ) {
	global $wpdb;
	law_hubspot_install_tables();

	if ( is_array( $detail ) ) {
		$detail = (string) wp_json_encode( $detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	// A TEXT column holds 64 KB; a 100-contact batch payload is well under, but
	// cap it anyway so a log line can never be the thing that fails the push.
	$detail = substr( (string) $detail, 0, 60000 );

	$wpdb->insert(
		law_hubspot_log_table(),
		array(
			'email'      => law_hubspot_normalise_email( $email ),
			'action'     => sanitize_key( $action ),
			'detail'     => $detail,
			'result'     => sanitize_key( $result ),
			'created_at' => current_time( 'mysql', true ),
		),
		array( '%s', '%s', '%s', '%s', '%s' )
	);
}

/**
 * Recent log rows, newest first, optionally for one address.
 *
 * @return object[]
 */
function law_hubspot_log_recent( $limit = 50, $email = '' ) {
	global $wpdb;
	law_hubspot_install_tables();
	$table = law_hubspot_log_table();
	$limit = max( 1, (int) $limit );
	$email = law_hubspot_normalise_email( $email );

	if ( '' !== $email ) {
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s ORDER BY id DESC LIMIT %d", $email, $limit ) );
	}
	return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) );
}

/** Drop rows older than the retention window. Returns rows deleted. */
function law_hubspot_log_prune( $days = null ) {
	global $wpdb;
	law_hubspot_install_tables();
	$days   = null === $days ? (int) law_hubspot_setting( 'log_retention_days', 90 ) : (int) $days;
	$cutoff = gmdate( 'Y-m-d H:i:s', time() - max( 1, $days ) * DAY_IN_SECONDS );
	return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . law_hubspot_log_table() . ' WHERE created_at < %s', $cutoff ) );
}

/**
 * The one spelling of an email address the module uses everywhere: trimmed,
 * lower-cased, and '' when it is not an address at all. HubSpot matches
 * contacts case-insensitively, and the queue's unique key must too.
 */
function law_hubspot_normalise_email( $email ) {
	$email = strtolower( trim( (string) $email ) );
	return ( '' !== $email && is_email( $email ) ) ? $email : '';
}

/**
 * Dry mode: the client refused to send a write; keep the payload so the dry
 * run can be read back on the admin screen and with `wp law hubspot log`.
 */
add_action(
	'law_hubspot_dry_write',
	function ( $method, $path, $body ) {
		law_hubspot_log( '', 'dry-write', array( 'method' => $method, 'path' => $path, 'body' => $body ), 'dry' );
	},
	10,
	3
);
