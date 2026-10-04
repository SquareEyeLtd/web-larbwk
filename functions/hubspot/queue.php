<?php
/**
 * The dirty-email queue and its cron worker (_docs/HUBSPOT_SYNC.md §6.3).
 *
 * Hooks never talk to HubSpot. They call law_hubspot_enqueue() with an email
 * address and a reason, which is one cheap upsert, and get on with whatever
 * they were doing. Every five minutes the worker takes up to 100 due rows,
 * hands the addresses to the sync step in one go, and records per-address
 * success or failure: successes leave the queue, failures back off (5 min,
 * 30 min, 2 h, 12 h) and are then parked for a person to look at on the
 * LAW > HubSpot screen.
 *
 * The sync step itself -- compute the desired state for each address, read
 * the contact, merge, write -- lives in rules.php and sync.php. The worker
 * reaches it through law_hubspot_worker_sync(), which also gives tests a
 * filter to stand in for it, so the queue's own behaviour can be tested
 * without a single rule existing.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_HUBSPOT_CRON_HOOK     = 'law_hubspot_worker';
const LAW_HUBSPOT_CRON_SCHEDULE = 'law_five_minutes';
const LAW_HUBSPOT_LOCK_OPTION   = 'law_hubspot_worker_lock';
const LAW_HUBSPOT_LOCK_TTL      = 10 * MINUTE_IN_SECONDS;

/* -------------------------------------------------------------------------
 * Enqueue
 * ---------------------------------------------------------------------- */

/**
 * Mark one person as needing a sync. Idempotent and coalescing: a second call
 * for the same address before the worker runs updates the one row (latest
 * reason, due now, un-parked) rather than adding another.
 *
 * Attempts are deliberately NOT reset: a parked row that something re-queues
 * gets one more try, and parks again if that fails too, rather than cycling
 * for ever under a hook that keeps firing.
 *
 * @param string $email  Address; anything that is not one is ignored.
 * @param string $reason Short trigger label for the log (e.g. 'user_register').
 * @return bool Whether a row was written.
 */
function law_hubspot_enqueue( $email, $reason = '' ) {
	global $wpdb;

	$email = law_hubspot_normalise_email( $email );
	if ( '' === $email ) {
		return false;
	}
	law_hubspot_install_tables();

	$now    = current_time( 'mysql', true );
	$reason = substr( sanitize_key( $reason ), 0, 80 );
	$table  = law_hubspot_queue_table();

	$written = $wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$table} (email, reason, attempts, parked, next_attempt_at, last_error, queued_at)
			 VALUES (%s, %s, 0, 0, %s, '', %s)
			 ON DUPLICATE KEY UPDATE reason = VALUES(reason), parked = 0, next_attempt_at = VALUES(next_attempt_at)",
			$email,
			$reason,
			$now,
			$now
		)
	);

	return false !== $written;
}

/**
 * Queue every address the site knows about, for the backfill. Needs
 * law_hubspot_people() from rules.php.
 *
 * @return int|WP_Error Rows queued.
 */
function law_hubspot_queue_everyone( $reason = 'backfill' ) {
	if ( ! function_exists( 'law_hubspot_people' ) ) {
		return new WP_Error( 'law_hubspot_no_rules', 'The sync rules are not loaded, so there is nobody to queue.' );
	}
	$count = 0;
	foreach ( law_hubspot_people() as $email ) {
		if ( law_hubspot_enqueue( $email, $reason ) ) {
			$count++;
		}
	}
	return $count;
}

/* -------------------------------------------------------------------------
 * Reading the queue
 * ---------------------------------------------------------------------- */

/**
 * Rows ready to process now: not parked, due, oldest due first.
 *
 * @return object[]
 */
function law_hubspot_queue_due( $limit = 100 ) {
	global $wpdb;
	law_hubspot_install_tables();
	$table = law_hubspot_queue_table();
	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE parked = 0 AND next_attempt_at <= %s ORDER BY next_attempt_at ASC, id ASC LIMIT %d",
			current_time( 'mysql', true ),
			max( 1, (int) $limit )
		)
	);
}

/** Parked rows (attempts exhausted), most recently queued first. */
function law_hubspot_queue_parked( $limit = 100 ) {
	global $wpdb;
	law_hubspot_install_tables();
	$table = law_hubspot_queue_table();
	return (array) $wpdb->get_results(
		$wpdb->prepare( "SELECT * FROM {$table} WHERE parked = 1 ORDER BY queued_at DESC, id DESC LIMIT %d", max( 1, (int) $limit ) )
	);
}

/** One row by address, or null. */
function law_hubspot_queue_row( $email ) {
	global $wpdb;
	$email = law_hubspot_normalise_email( $email );
	if ( '' === $email ) {
		return null;
	}
	law_hubspot_install_tables();
	$table = law_hubspot_queue_table();
	$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE email = %s", $email ) );
	return $row ? $row : null;
}

/**
 * Headline numbers for the admin screen and `wp law hubspot status`.
 *
 * @return array{total:int,due:int,waiting:int,parked:int}
 */
function law_hubspot_queue_stats() {
	global $wpdb;
	law_hubspot_install_tables();
	$table = law_hubspot_queue_table();
	$now   = current_time( 'mysql', true );
	$row   = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT COUNT(*) AS total,
				SUM(parked = 0 AND next_attempt_at <= %s) AS due,
				SUM(parked = 0 AND next_attempt_at > %s) AS waiting,
				SUM(parked = 1) AS parked
			 FROM {$table}",
			$now,
			$now
		),
		ARRAY_A
	);
	return array(
		'total'   => (int) ( $row['total'] ?? 0 ),
		'due'     => (int) ( $row['due'] ?? 0 ),
		'waiting' => (int) ( $row['waiting'] ?? 0 ),
		'parked'  => (int) ( $row['parked'] ?? 0 ),
	);
}

/* -------------------------------------------------------------------------
 * Writing results back
 * ---------------------------------------------------------------------- */

/** Put every parked row back at the front of the queue with a clean slate. */
function law_hubspot_queue_retry_parked() {
	global $wpdb;
	law_hubspot_install_tables();
	$table = law_hubspot_queue_table();
	return (int) $wpdb->query(
		$wpdb->prepare( "UPDATE {$table} SET parked = 0, attempts = 0, next_attempt_at = %s WHERE parked = 1", current_time( 'mysql', true ) )
	);
}

/** Remove a row: the sync succeeded. */
function law_hubspot_queue_delete( $id ) {
	global $wpdb;
	return (bool) $wpdb->delete( law_hubspot_queue_table(), array( 'id' => (int) $id ), array( '%d' ) );
}

/**
 * Record one failed attempt: bump the count, schedule the next try from the
 * backoff table, or park when the table is exhausted.
 *
 * @param object $row     Queue row as read before the attempt.
 * @param string $message What went wrong.
 * @return array{attempts:int,parked:bool,next_attempt_at:string}
 */
function law_hubspot_queue_fail( $row, $message ) {
	global $wpdb;

	$backoff  = array_values( (array) law_hubspot_setting( 'backoff', array() ) );
	$attempts = (int) $row->attempts + 1;
	$parked   = $attempts > count( $backoff );
	$delay    = $parked ? 0 : (int) $backoff[ $attempts - 1 ];
	$next     = gmdate( 'Y-m-d H:i:s', time() + $delay );

	$wpdb->update(
		law_hubspot_queue_table(),
		array(
			'attempts'        => $attempts,
			'parked'          => $parked ? 1 : 0,
			'next_attempt_at' => $next,
			'last_error'      => substr( (string) $message, 0, 2000 ),
		),
		array( 'id' => (int) $row->id ),
		array( '%d', '%d', '%s', '%s' ),
		array( '%d' )
	);

	return array( 'attempts' => $attempts, 'parked' => $parked, 'next_attempt_at' => $next );
}

/* -------------------------------------------------------------------------
 * The worker
 * ---------------------------------------------------------------------- */

/**
 * Take the lock, or report who holds it. add_option() is the atomic primitive
 * here: the options table's unique key means only one caller can insert the
 * row, where set_transient() is a read-then-write that two cron runs a second
 * apart can both win. A lock older than LAW_HUBSPOT_LOCK_TTL is a crashed run
 * and is broken.
 */
function law_hubspot_worker_lock() {
	$existing = get_option( LAW_HUBSPOT_LOCK_OPTION, false );
	if ( false !== $existing && (int) $existing > time() - LAW_HUBSPOT_LOCK_TTL ) {
		return false;
	}
	if ( false !== $existing ) {
		delete_option( LAW_HUBSPOT_LOCK_OPTION );
	}
	return add_option( LAW_HUBSPOT_LOCK_OPTION, time(), '', false );
}

function law_hubspot_worker_unlock() {
	delete_option( LAW_HUBSPOT_LOCK_OPTION );
}

/**
 * Sync a batch of addresses. The seam between the queue and the rules.
 *
 * @param string[] $emails
 * @return array<string,true|array|WP_Error>|WP_Error Per-address results keyed by
 *         email (anything not a WP_Error is success), or one WP_Error for the
 *         whole batch (token missing, network down), which fails every row.
 */
function law_hubspot_worker_sync( array $emails ) {
	$results = apply_filters( 'law_hubspot_sync_batch', null, $emails );
	if ( null !== $results ) {
		return $results;
	}
	if ( function_exists( 'law_hubspot_sync_batch' ) ) {
		return law_hubspot_sync_batch( $emails );
	}
	return new WP_Error( 'law_hubspot_no_rules', 'The sync rules are not loaded.' );
}

/**
 * One worker run: up to $limit due rows, one sync call, results written back.
 *
 * @param int $limit Rows per run (HubSpot batches are 100; the sync step chunks).
 * @return array|WP_Error Summary: processed, succeeded, failed, parked, or
 *         WP_Error when the module is off or another run holds the lock.
 */
function law_hubspot_process_queue( $limit = null ) {
	if ( ! law_hubspot_enabled() ) {
		return new WP_Error( 'law_hubspot_off', 'HubSpot sync is off; nothing processed.' );
	}
	if ( ! law_hubspot_worker_lock() ) {
		return new WP_Error( 'law_hubspot_locked', 'Another worker run is in progress.' );
	}

	$limit   = null === $limit ? (int) law_hubspot_setting( 'batch_size', 100 ) : (int) $limit;
	$summary = array( 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'parked' => 0, 'mode' => law_hubspot_mode() );

	try {
		$rows    = law_hubspot_queue_due( $limit );
		$emails  = array_map( fn( $row ) => (string) $row->email, $rows );
		$results = $rows ? law_hubspot_worker_sync( $emails ) : array();

		foreach ( $rows as $row ) {
			$summary['processed']++;
			$result = is_wp_error( $results )
				? $results
				: ( $results[ $row->email ] ?? new WP_Error( 'law_hubspot_no_result', 'The sync step returned nothing for this address.' ) );

			if ( is_wp_error( $result ) ) {
				$outcome = law_hubspot_queue_fail( $row, $result->get_error_message() );
				$summary['failed']++;
				if ( $outcome['parked'] ) {
					$summary['parked']++;
				}
				law_hubspot_log(
					$row->email,
					'sync',
					array(
						'reason'   => $row->reason,
						'attempt'  => $outcome['attempts'],
						'parked'   => $outcome['parked'],
						'error'    => $result->get_error_message(),
						'code'     => $result->get_error_code(),
					),
					'error'
				);
				continue;
			}

			law_hubspot_queue_delete( $row->id );
			$summary['succeeded']++;
		}
	} finally {
		law_hubspot_worker_unlock();
	}

	update_option(
		'law_hubspot_last_run',
		array_merge( $summary, array( 'at' => time() ) ),
		false
	);

	return $summary;
}

/** The last worker run's summary (with 'at' timestamp), or null. */
function law_hubspot_last_run() {
	$run = get_option( 'law_hubspot_last_run', null );
	return is_array( $run ) ? $run : null;
}

/* -------------------------------------------------------------------------
 * Cron
 * ---------------------------------------------------------------------- */

add_filter(
	'cron_schedules',
	function ( $schedules ) {
		if ( ! isset( $schedules[ LAW_HUBSPOT_CRON_SCHEDULE ] ) ) {
			$schedules[ LAW_HUBSPOT_CRON_SCHEDULE ] = array(
				'interval' => 5 * MINUTE_IN_SECONDS,
				'display'  => 'Every five minutes (LAW HubSpot sync)',
			);
		}
		return $schedules;
	}
);

/**
 * Keep the schedule in step with the mode: scheduled while the module is on,
 * cleared when it is off. Checked on init so a wp-config.php change takes
 * effect on the next request without anyone visiting a screen.
 */
function law_hubspot_schedule_worker() {
	$next = wp_next_scheduled( LAW_HUBSPOT_CRON_HOOK );
	if ( law_hubspot_enabled() ) {
		if ( ! $next ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, LAW_HUBSPOT_CRON_SCHEDULE, LAW_HUBSPOT_CRON_HOOK );
		}
	} elseif ( $next ) {
		wp_clear_scheduled_hook( LAW_HUBSPOT_CRON_HOOK );
	}
}
add_action( 'init', 'law_hubspot_schedule_worker', 20 );

/** The cron callback: one run, and the log pruned once a day. */
function law_hubspot_run_worker() {
	law_hubspot_process_queue();
	if ( false === get_transient( 'law_hubspot_log_pruned' ) ) {
		law_hubspot_log_prune();
		set_transient( 'law_hubspot_log_pruned', 1, DAY_IN_SECONDS );
	}
}
add_action( LAW_HUBSPOT_CRON_HOOK, 'law_hubspot_run_worker' );
