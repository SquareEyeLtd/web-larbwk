<?php
/**
 * wp law hubspot ... (_docs/HUBSPOT_SYNC.md §6.6). Loaded only under WP-CLI.
 *
 *   wp law hubspot status
 *   wp law hubspot check-properties [--create] [--contact-type]
 *   wp law hubspot process [--limit=<n>]
 *   wp law hubspot queue <email> [--reason=<reason>]
 *   wp law hubspot retry-parked
 *   wp law hubspot log [--limit=<n>] [--email=<email>]
 *   wp law hubspot person <email>
 *   wp law hubspot preview [--out=<file.csv>] [--email=<email>]
 *   wp law hubspot backfill [--limit=<n>] [--yes]
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	return;
}

/**
 * HubSpot sync: status, property checks, the queue worker.
 */
class LAW_HubSpot_CLI {

	/**
	 * Mode, token, queue and worker at a glance.
	 *
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		$stats    = law_hubspot_queue_stats();
		$last_run = law_hubspot_last_run();
		$next     = wp_next_scheduled( LAW_HUBSPOT_CRON_HOOK );

		$rows = array(
			array( 'item' => 'Mode', 'value' => law_hubspot_mode() . ( law_hubspot_mode_is_downgraded() ? ' (live requested; downgraded on a ' . wp_get_environment_type() . ' environment)' : '' ) ),
			array( 'item' => 'Environment', 'value' => wp_get_environment_type() ),
			array( 'item' => 'Token set', 'value' => '' !== law_hubspot_token() ? 'yes' : 'no' ),
			array( 'item' => 'Year', 'value' => (string) law_hubspot_year() ),
			array( 'item' => 'People known to the site', 'value' => (string) count( law_hubspot_people() ) ),
			array( 'item' => 'Queue due / waiting / parked', 'value' => sprintf( '%d / %d / %d', $stats['due'], $stats['waiting'], $stats['parked'] ) ),
			array( 'item' => 'Worker next run', 'value' => $next ? gmdate( 'Y-m-d H:i:s', $next ) . ' UTC' : 'not scheduled' ),
			array(
				'item'  => 'Worker last run',
				'value' => $last_run
					? sprintf( '%s UTC: %d processed, %d ok, %d failed, %d parked', gmdate( 'Y-m-d H:i:s', $last_run['at'] ), $last_run['processed'], $last_run['succeeded'], $last_run['failed'], $last_run['parked'] )
					: 'never',
			),
		);

		if ( law_hubspot_enabled() && '' !== law_hubspot_token() ) {
			$check  = law_hubspot_token_check();
			$rows[] = array( 'item' => 'Token check', 'value' => is_wp_error( $check ) ? 'FAILED: ' . $check->get_error_message() : 'ok' );
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'value' ) );
	}

	/**
	 * Compare the portal's properties with what the rules write.
	 *
	 * ## OPTIONS
	 *
	 * [--create]
	 * : Create the missing site-owned properties and options (dry mode logs them instead).
	 *
	 * [--contact-type]
	 * : Also add the missing Contact type option values (the shared property).
	 *
	 * [--live]
	 * : Send the --create / --contact-type writes for real even when the mode
	 * is dry (including a local site, where live is always downgraded). Only
	 * property DEFINITIONS are written by this command, never contact data,
	 * which is why this one override exists and the worker has none. Asks for
	 * confirmation; pass --yes to skip.
	 *
	 * [--yes]
	 * : Answer yes to the --live confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp law hubspot check-properties
	 *     wp law hubspot check-properties --create --live
	 *
	 * @subcommand check-properties
	 * @when after_wp_load
	 */
	public function check_properties( $args, $assoc_args ) {
		$force_live = ! empty( $assoc_args['live'] ) && ( ! empty( $assoc_args['create'] ) || ! empty( $assoc_args['contact-type'] ) );
		if ( $force_live && 'live' !== law_hubspot_mode() ) {
			if ( '' === law_hubspot_token() ) {
				WP_CLI::error( 'LAW_HUBSPOT_TOKEN is not set; nothing can be written.' );
			}
			WP_CLI::confirm(
				sprintf( 'This will write property DEFINITIONS (no contact data) to the live HubSpot portal %d from a %s site. Continue?', LAW_HUBSPOT_PORTAL_ID, wp_get_environment_type() ),
				$assoc_args
			);
			add_filter( 'law_hubspot_mode', array( $this, 'force_live_mode' ), 100 );
			WP_CLI::warning( 'Schema writes are LIVE for this command.' );
		}

		$report = law_hubspot_properties_check();
		if ( '' !== $report['error'] ) {
			WP_CLI::error( $report['error'] );
		}

		WP_CLI::log( sprintf( 'Group %s: %s', $report['group']['name'], $report['group']['exists'] ? 'exists' : 'MISSING' ) );

		$rows = array();
		foreach ( $report['properties'] as $name => $row ) {
			$rows[] = array(
				'property'        => $name,
				'exists'          => $row['exists'] ? 'yes' : 'NO',
				'type_ok'         => $row['type_ok'] ? 'yes' : 'NO',
				'missing_options' => implode( ', ', $row['missing_options'] ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'property', 'exists', 'type_ok', 'missing_options' ) );

		foreach ( array( 'contact_type', 'legal_basis' ) as $key ) {
			$shared = $report[ $key ];
			WP_CLI::log( '' );
			WP_CLI::log( sprintf( '%s (%s): %s', $key, $shared['name'], $shared['exists'] ? 'exists, ' . count( $shared['options'] ) . ' options' : 'NOT FOUND' ) );
			foreach ( $shared['needed'] as $value ) {
				$missing = in_array( $value, $shared['missing_values'], true );
				$note    = isset( $shared['label_mismatches'][ $value ] ) ? ' (label of value ' . implode( ', ', $shared['label_mismatches'][ $value ] ) . ')' : '';
				$note   .= isset( $shared['near_misses'][ $value ] ) ? ' (portal has "' . implode( '", "', $shared['near_misses'][ $value ] ) . '": case/dash difference)' : '';
				WP_CLI::log( sprintf( '  %s %s%s', $missing ? '✗' : '✓', $value, $note ) );
			}
		}

		if ( ! empty( $assoc_args['create'] ) ) {
			WP_CLI::log( '' );
			$this->print_actions( law_hubspot_properties_create( $report ) );
		}
		if ( ! empty( $assoc_args['contact-type'] ) ) {
			WP_CLI::log( '' );
			$this->print_actions( law_hubspot_contact_type_add_options( $report ) );
		}

		if ( $force_live ) {
			// Re-read so the summary describes the portal after the writes.
			$report = law_hubspot_properties_check();
			remove_filter( 'law_hubspot_mode', array( $this, 'force_live_mode' ), 100 );
		}

		if ( $report['ok'] ) {
			WP_CLI::success( 'Every property and option the rules write exists.' );
		} else {
			WP_CLI::warning( 'Differences found.' . ( empty( $assoc_args['create'] ) ? ' Re-run with --create to fix the site-owned ones.' : '' ) );
		}
	}

	/** The --live override for check-properties: live for this process only. */
	public function force_live_mode() {
		return 'live';
	}

	/**
	 * Run the queue worker once.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Rows to take (default: the configured batch size, 100).
	 *
	 * @when after_wp_load
	 */
	public function process( $args, $assoc_args ) {
		$limit   = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : null;
		$summary = law_hubspot_process_queue( $limit );
		if ( is_wp_error( $summary ) ) {
			WP_CLI::error( $summary->get_error_message() );
		}
		WP_CLI::success( sprintf( '%s: %d processed, %d succeeded, %d failed, %d parked.', $summary['mode'], $summary['processed'], $summary['succeeded'], $summary['failed'], $summary['parked'] ) );
	}

	/**
	 * Queue one address.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : The address.
	 *
	 * [--reason=<reason>]
	 * : Label for the log (default: cli).
	 *
	 * @when after_wp_load
	 */
	public function queue( $args, $assoc_args ) {
		$email = (string) ( $args[0] ?? '' );
		if ( ! law_hubspot_enqueue( $email, (string) ( $assoc_args['reason'] ?? 'cli' ) ) ) {
			WP_CLI::error( 'Not a valid email address: ' . $email );
		}
		WP_CLI::success( 'Queued ' . law_hubspot_normalise_email( $email ) . '.' );
	}

	/**
	 * Put every parked address back in the queue.
	 *
	 * @subcommand retry-parked
	 * @when after_wp_load
	 */
	public function retry_parked( $args, $assoc_args ) {
		WP_CLI::success( law_hubspot_queue_retry_parked() . ' parked address(es) re-queued.' );
	}

	/**
	 * Recent log rows.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Rows (default 50).
	 *
	 * [--email=<email>]
	 * : Only this address.
	 *
	 * @when after_wp_load
	 */
	public function log( $args, $assoc_args ) {
		$rows = array();
		foreach ( law_hubspot_log_recent( (int) ( $assoc_args['limit'] ?? 50 ), (string) ( $assoc_args['email'] ?? '' ) ) as $row ) {
			$rows[] = array(
				'when'   => $row->created_at,
				'email'  => $row->email,
				'action' => $row->action,
				'result' => $row->result,
				'detail' => mb_strimwidth( (string) $row->detail, 0, 160, '…' ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'when', 'email', 'action', 'result', 'detail' ) );
	}

	/**
	 * What the rules would write for one address, and what HubSpot holds.
	 *
	 * ## OPTIONS
	 *
	 * <email>
	 * : The address.
	 *
	 * @when after_wp_load
	 */
	public function person( $args, $assoc_args ) {
		$email = law_hubspot_normalise_email( (string) ( $args[0] ?? '' ) );
		if ( '' === $email ) {
			WP_CLI::error( 'Give an email address.' );
		}
		if ( ! law_hubspot_enabled() ) {
			WP_CLI::error( 'The HubSpot mode is off; set LAW_HUBSPOT_MODE to dry to read.' );
		}

		$planned = law_hubspot_plan_batch( array( $email ) );
		if ( $planned['error'] ) {
			WP_CLI::error( $planned['error']->get_error_message() );
		}
		$plan = $planned['plans'][ $email ] ?? array( 'state' => null );
		if ( null === $plan['state'] ) {
			WP_CLI::warning( 'The site knows nothing about ' . $email . ' (no account, no speaker record).' );
			return;
		}

		$state   = $plan['state'];
		$sources = $state['sources'];
		$rows    = array(
			array( 'item' => 'User ID', 'value' => $sources['user'] ? (string) $sources['user'] : '-' ),
			array( 'item' => 'Speaker ID', 'value' => $sources['speaker'] ? (string) $sources['speaker'] : '-' ),
			array( 'item' => 'Confirmed bookings', 'value' => implode( ', ', $sources['bookings'] ) ?: '-' ),
			array( 'item' => 'Hosted events', 'value' => implode( ', ', $sources['hosted'] ) ?: '-' ),
			array( 'item' => 'Co-owned events', 'value' => implode( ', ', $sources['co_owned'] ) ?: '-' ),
			array( 'item' => 'Speaking at', 'value' => implode( ', ', $sources['speaking'] ) ?: '-' ),
			array( 'item' => 'Press', 'value' => $sources['press'] ? 'yes' : 'no' ),
			array( 'item' => 'In HubSpot', 'value' => $plan['exists'] ? 'yes, record ' . $plan['hubspot_id'] : 'no (push would create)' ),
			array( 'item' => 'Stored HubSpot ID', 'value' => '' !== $state['hubspot_id'] ? $state['hubspot_id'] : '-' ),
			array( 'item' => 'Contact type now', 'value' => $plan['before']['contact_type'] ?: '(empty)' ),
			array( 'item' => 'Contact type after', 'value' => $plan['after']['contact_type'] ?: '(empty)' ),
			array( 'item' => 'Tags added', 'value' => implode( ', ', $plan['added_tags'] ) ?: '-' ),
			array( 'item' => 'Legal basis now', 'value' => $plan['before']['legal_basis'] ?: '(empty)' ),
			array( 'item' => 'Legal basis after', 'value' => $plan['after']['legal_basis'] ?: '(empty)' ),
		);
		foreach ( $plan['properties'] as $name => $value ) {
			$rows[] = array( 'item' => 'write ' . $name, 'value' => (string) $value );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'item', 'value' ) );
	}

	/**
	 * The backfill preview: what the push would do to everyone, as CSV.
	 *
	 * Reads only. One row per account or speaker: whether the contact exists,
	 * Contact type and legal basis before and after, the standard properties.
	 *
	 * ## OPTIONS
	 *
	 * [--out=<file>]
	 * : Write the CSV here (default: stdout).
	 *
	 * [--email=<email>]
	 * : Preview one address only (repeatable as a comma-separated list).
	 *
	 * ## EXAMPLES
	 *
	 *     wp law hubspot preview --out=~/Desktop/hubspot-preview.csv
	 *
	 * @when after_wp_load
	 */
	public function preview( $args, $assoc_args ) {
		if ( ! law_hubspot_enabled() ) {
			WP_CLI::error( 'The HubSpot mode is off; set LAW_HUBSPOT_MODE to dry to read.' );
		}
		$emails = isset( $assoc_args['email'] ) ? array_map( 'trim', explode( ',', (string) $assoc_args['email'] ) ) : null;
		$count  = null === $emails ? count( law_hubspot_people() ) : count( $emails );
		WP_CLI::log( sprintf( 'Reading %d address(es) against HubSpot…', $count ) );

		$preview = law_hubspot_preview( $emails );
		if ( $preview['error'] ) {
			WP_CLI::error( $preview['error']->get_error_message() );
		}

		$out    = (string) ( $assoc_args['out'] ?? '' );
		$handle = '' !== $out ? fopen( $out, 'w' ) : fopen( 'php://output', 'w' );
		if ( ! $handle ) {
			WP_CLI::error( 'Could not open ' . $out . ' for writing.' );
		}
		law_hubspot_preview_to_csv( $handle, $preview['rows'] );
		fclose( $handle );

		if ( '' !== $out ) {
			WP_CLI::log( '' );
			foreach ( $preview['totals'] as $key => $value ) {
				WP_CLI::log( sprintf( '  %-40s %d', $key, $value ) );
			}
			WP_CLI::success( sprintf( '%d row(s) written to %s.', count( $preview['rows'] ), $out ) );
		}
	}

	/**
	 * Queue everyone the site knows and run the worker until the queue is empty.
	 *
	 * The one-off push after go-live (HUBSPOT_SYNC.md §8). Run `preview` first.
	 * In dry mode this logs every write without sending it, which is the
	 * rehearsal; in live mode it writes to the portal.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<n>]
	 * : Queue only the first n addresses (for a trial run).
	 *
	 * [--yes]
	 * : Skip the confirmation.
	 *
	 * @when after_wp_load
	 */
	public function backfill( $args, $assoc_args ) {
		if ( ! law_hubspot_enabled() ) {
			WP_CLI::error( 'The HubSpot mode is off.' );
		}
		$mode   = law_hubspot_mode();
		$people = law_hubspot_people();
		if ( isset( $assoc_args['limit'] ) ) {
			$people = array_slice( $people, 0, max( 0, (int) $assoc_args['limit'] ) );
		}
		WP_CLI::confirm(
			sprintf( '%s %d address(es) %s. Continue?', 'live' === $mode ? 'Push' : 'Dry-run', count( $people ), 'live' === $mode ? 'to the live portal ' . LAW_HUBSPOT_PORTAL_ID : '(writes logged, not sent)' ),
			$assoc_args
		);

		foreach ( $people as $email ) {
			law_hubspot_enqueue( $email, 'backfill' );
		}
		WP_CLI::log( sprintf( '%d queued. Processing…', count( $people ) ) );

		$totals = array( 'processed' => 0, 'succeeded' => 0, 'failed' => 0, 'parked' => 0 );
		do {
			$summary = law_hubspot_process_queue();
			if ( is_wp_error( $summary ) ) {
				WP_CLI::error( $summary->get_error_message() );
			}
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] += $summary[ $key ];
			}
			WP_CLI::log( sprintf( '  batch: %d processed, %d ok, %d failed, %d parked', $summary['processed'], $summary['succeeded'], $summary['failed'], $summary['parked'] ) );
		} while ( $summary['processed'] > 0 && law_hubspot_queue_stats()['due'] > 0 );

		$stats = law_hubspot_queue_stats();
		WP_CLI::log( sprintf( 'Queue now: %d due, %d waiting to retry, %d parked.', $stats['due'], $stats['waiting'], $stats['parked'] ) );
		if ( $totals['failed'] ) {
			WP_CLI::warning( sprintf( '%d failed and will retry on the worker schedule; see `wp law hubspot log`.', $totals['failed'] ) );
		}
		WP_CLI::success( sprintf( '%s: %d processed, %d succeeded.', $mode, $totals['processed'], $totals['succeeded'] ) );
	}

	private function print_actions( array $actions ) {
		foreach ( $actions as $row ) {
			$line = sprintf( '%s %s: %s%s', $row['action'], $row['target'], $row['result'], '' !== $row['message'] ? ' – ' . $row['message'] : '' );
			if ( 'error' === $row['result'] ) {
				WP_CLI::warning( $line );
			} else {
				WP_CLI::log( $line );
			}
		}
	}
}

WP_CLI::add_command( 'law hubspot', 'LAW_HubSpot_CLI' );
