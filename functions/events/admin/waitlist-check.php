<?php
/**
 * /wp-admin/?check-waitlists (administrators only).
 *
 * Every waitlist on the site, and what a pass would do to it now: who would be
 * seated, who is being passed over and why, and who is simply waiting for a
 * place. Opening the URL changes nothing. The button at the foot runs the
 * passes, which seats people, emails them and, on a paid reception, charges
 * the card they saved; it is a POST with a nonce for that reason, so a link
 * alone can never do it.
 *
 * Written for the November 2026 report of people left on waitlists after their
 * events' places were raised, and kept because the same question will be asked
 * again: it answers it from the live data rather than from the activity log.
 * Idempotent: running it twice seats nobody the first run did not.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long one run may spend seating people before it stops and says so. */
const LAW_WAITLIST_CHECK_SECONDS = 25;

add_action( 'admin_init', 'law_waitlist_check_screen' );

/** The trigger: report, or run then report. */
function law_waitlist_check_screen() {
	if ( ! isset( $_GET['check-waitlists'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You need to be an administrator to run this.', 'Check waitlists', 403 );
	}

	$run = isset( $_POST['law_waitlist_check_nonce'] );
	if ( $run ) {
		check_admin_referer( 'law_waitlist_check', 'law_waitlist_check_nonce' );
	}
	$result = $run ? law_waitlist_check_run() : array( 'seated' => array(), 'stopped' => false );

	$lines = array_merge(
		array( $run ? 'Waitlists run. The list below is how they stand now.' : 'Report only: nothing has been changed.', '' ),
		law_waitlist_check_cron_lines(),
		array( '' )
	);
	if ( $result['stopped'] ) {
		$lines[] = sprintf( 'Stopped after %d seconds with more to do. Run it again for the rest.', LAW_WAITLIST_CHECK_SECONDS );
		$lines[] = '';
	}

	$to_seat = 0;
	$past    = 0;
	$events  = law_waitlist_events_with_queue();
	foreach ( $events as $event_id ) {
		$start = (string) law_event_meta( $event_id, '_law_start' );
		if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
			$past++;
			continue;
		}
		$report   = law_waitlist_check_event_lines( $event_id, $result['seated'][ $event_id ] ?? array() );
		$to_seat += $report['to_seat'];
		$lines    = array_merge( $lines, $report['lines'], array( '' ) );
	}
	if ( ! $events ) {
		$lines[] = 'Nobody is waiting on any event.';
	}
	if ( $past ) {
		$lines[] = sprintf( '%d past event(s) with people still on the waitlist are not shown.', $past );
	}

	$html = '<h1>Check waitlists</h1><pre style="white-space: pre-wrap;">' . esc_html( implode( "\n", $lines ) ) . '</pre>';
	if ( $to_seat ) {
		$html .= '<form method="post" action="' . esc_url( admin_url( '?check-waitlists' ) ) . '">'
			. wp_nonce_field( 'law_waitlist_check', 'law_waitlist_check_nonce', true, false )
			. '<p>' . esc_html(
				sprintf(
					'%d %s can be seated. Seating emails each person their confirmation and, on a paid reception, charges their saved card.',
					$to_seat,
					1 === $to_seat ? 'person' : 'people'
				)
			) . '</p>'
			. '<p><button type="submit" class="button button-primary">Seat them now</button></p>'
			. '</form>';
	}
	$html .= '<p><a href="' . esc_url( admin_url() ) . '">Back to the dashboard</a></p>';

	wp_die( $html, 'Check waitlists', array( 'response' => 200 ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts above.
}

/**
 * Offer every free place to its queue, pass after pass until nobody more can
 * be seated, rather than leaving the remainder to the resume cron: whoever
 * pressed the button is waiting for the answer.
 *
 * @param int[]|null $event_ids The events to run; every event with a queue
 *                              when null (the button's call).
 * @return array{seated:array<int,int[]>,stopped:bool}
 */
function law_waitlist_check_run( ?array $event_ids = null ) {
	$started = time();
	$seated  = array();
	foreach ( null === $event_ids ? law_waitlist_events_with_queue() : array_map( 'intval', $event_ids ) as $event_id ) {
		do {
			if ( time() - $started > LAW_WAITLIST_CHECK_SECONDS ) {
				return array( 'seated' => $seated, 'stopped' => true );
			}
			$promoted = law_waitlist_offer( $event_id, 'admin_check' );
			if ( $promoted ) {
				$seated[ $event_id ] = array_merge( $seated[ $event_id ] ?? array(), $promoted );
			}
		} while ( $promoted );
	}
	return array( 'seated' => $seated, 'stopped' => false );
}

/**
 * Is the site's scheduled-task runner keeping up? The waitlist's resume and
 * hourly sweep both depend on it, and so does every reception payment sweep.
 *
 * @return string[]
 */
function law_waitlist_check_cron_lines() {
	$lines   = array( 'Scheduled tasks' );
	$cutoff  = time() - 15 * MINUTE_IN_SECONDS;
	$overdue = 0;
	$oldest  = 0;
	foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
		if ( (int) $timestamp >= $cutoff ) {
			continue;
		}
		foreach ( (array) $hooks as $instances ) {
			$overdue += count( (array) $instances );
		}
		$oldest = $oldest ? min( $oldest, (int) $timestamp ) : (int) $timestamp;
	}
	$lines[] = $overdue
		? sprintf( '  %d overdue, the oldest by %s. They are not running reliably: the server needs a cron job calling wp-cron.php every few minutes.', $overdue, human_time_diff( $oldest ) )
		: '  None more than 15 minutes overdue.';
	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		$lines[] = '  DISABLE_WP_CRON is set, so only a server cron job runs them.';
	}
	$next    = wp_next_scheduled( 'law_waitlist_hourly' );
	$lines[] = $next
		? '  Next hourly waitlist sweep: ' . wp_date( 'j M Y, H:i', $next ) . '.'
		: '  The hourly waitlist sweep is not scheduled yet.';
	return $lines;
}

/**
 * One event's block of the report.
 *
 * @param int[] $seated Bookings this run has just seated on it.
 * @return array{lines:string[],to_seat:int}
 */
function law_waitlist_check_event_lines( $event_id, array $seated ) {
	$start   = (string) law_event_meta( $event_id, '_law_start' );
	$lines   = array(
		sprintf(
			'%s (#%d)%s',
			get_the_title( $event_id ),
			$event_id,
			'' !== $start ? ', ' . date_i18n( 'D j M Y, H:i', strtotime( $start ) ) : ''
		),
	);
	$preview = law_waitlist_preview( $event_id );
	$counts  = array_count_values( wp_list_pluck( $preview['rows'], 'state' ) );
	$who     = function ( $booking ) {
		$person = law_booking_attendee( $booking );
		return sprintf( '#%d %s (%s)', (int) law_event_meta( is_object( $booking ) ? $booking->ID : $booking, '_law_booking_number' ), $person['name'], $person['email'] );
	};

	$available = (int) law_event_meta( $event_id, '_law_tickets_available' );
	$lines[]   = sprintf(
		'  %d waiting. %s',
		count( $preview['rows'] ),
		null === $preview['remaining']
			? 'No places released.'
			: sprintf( '%d of %d places free.', $preview['remaining'], $available )
	);
	if ( is_wp_error( $preview['open'] ) ) {
		$lines[] = '  Nobody can be seated: ' . $preview['open']->get_error_message();
	}

	foreach ( $seated as $booking_id ) {
		$lines[] = '  Seated: ' . $who( $booking_id );
	}
	foreach ( $preview['rows'] as $row ) {
		if ( 'seat' === $row['state'] ) {
			$lines[] = '  Would seat: ' . $who( $row['entry'] );
		} elseif ( 'passed_over' === $row['state'] ) {
			$lines[] = '  ' . $who( $row['entry'] ) . ': ' . $row['reason'];
		}
	}
	if ( ! empty( $counts['waiting'] ) ) {
		$lines[] = sprintf( '  %d waiting for a place to come up.', $counts['waiting'] );
	}

	return array( 'lines' => $lines, 'to_seat' => (int) ( $counts['seat'] ?? 0 ) );
}
