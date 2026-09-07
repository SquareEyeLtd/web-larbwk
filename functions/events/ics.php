<?php
/**
 * .ics calendar invites for booking emails (EVENTS_BOOKINGS.md §9.3).
 *
 * Times: the module stores naive site-local 'Y-m-d H:i' strings
 * (_law_start/_law_end); they are converted from wp_timezone()
 * (Europe/London) to UTC and emitted as DTSTART:...Z — no VTIMEZONE block to
 * maintain, correct across the BST/GMT boundary, universally parsed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The VCALENDAR text for an event, '' when the event has no start (nothing
 * should attach an invite with no date).
 *
 * @param int $event_id law_event post ID.
 * @return string CRLF-terminated, 75-octet-folded iCalendar text.
 */
function law_event_ics( $event_id ) {
	$event_id = (int) $event_id;
	$post     = get_post( $event_id );
	$start    = (string) law_event_meta( $event_id, '_law_start' );
	if ( ! $post || '' === $start ) {
		return '';
	}

	$end = (string) law_event_meta( $event_id, '_law_end' );
	if ( '' === $end || strtotime( $end ) <= strtotime( $start ) ) {
		// No end recorded — or an end at/before the start (a fat-fingered end
		// date would make the invite RFC-invalid and rejected by Outlook and
		// Google): default to two hours (documented in EVENTS_BOOKINGS.md).
		$end = gmdate( 'Y-m-d H:i', (int) strtotime( $start ) + 2 * HOUR_IN_SECONDS );
	}

	$description = wp_trim_words( wp_strip_all_tags( $post->post_content ), 40, '…' );
	if ( 'publish' === $post->post_status ) {
		$description .= "\n" . get_permalink( $post );
	}

	$lines = array(
		'BEGIN:VCALENDAR',
		'VERSION:2.0',
		'PRODID:-//London Arbitration Week//Events//EN',
		'CALSCALE:GREGORIAN',
		'METHOD:PUBLISH',
		'BEGIN:VEVENT',
		'UID:law-event-' . $event_id . '@' . (string) wp_parse_url( home_url(), PHP_URL_HOST ),
		'DTSTAMP:' . gmdate( 'Ymd\THis\Z' ),
		'DTSTART:' . law_events_ics_utc( $start ),
		'DTEND:' . law_events_ics_utc( $end ),
		'SUMMARY:' . law_events_ics_escape( $post->post_title ),
		'LOCATION:' . law_events_ics_escape( (string) law_event_meta( $event_id, '_law_venue' ) ),
		'DESCRIPTION:' . law_events_ics_escape( $description ),
		'END:VEVENT',
		'END:VCALENDAR',
	);

	$out = '';
	foreach ( $lines as $line ) {
		$out .= law_events_ics_fold( $line ) . "\r\n";
	}
	return $out;
}

/**
 * Write the invite to a temp file for wp_mail()'s attachments argument. The
 * caller deletes the file after the (synchronous) send.
 *
 * @return string Path, '' when the event has no start.
 */
function law_event_ics_tempfile( $event_id ) {
	$ics = law_event_ics( $event_id );
	if ( '' === $ics ) {
		return '';
	}
	// A real .ics extension: mail clients pick the calendar handler off the
	// attachment's filename (wp_tempnam()'s .tmp would not open in one).
	$path = trailingslashit( get_temp_dir() ) . 'law-event-' . (int) $event_id . '-' . wp_generate_password( 8, false ) . '.ics';
	return false !== file_put_contents( $path, $ics ) ? $path : '';
}

/**
 * A naive site-local 'Y-m-d H:i' string as a UTC iCalendar timestamp.
 */
function law_events_ics_utc( $local ) {
	$date = date_create( (string) $local, wp_timezone() );
	if ( ! $date ) {
		return gmdate( 'Ymd\THis\Z' );
	}
	return $date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Ymd\THis\Z' );
}

/** RFC 5545 text escaping: backslash, comma, semicolon, newline. */
function law_events_ics_escape( $text ) {
	$text = str_replace( array( "\r\n", "\r" ), "\n", (string) $text );
	$text = str_replace( array( '\\', ',', ';' ), array( '\\\\', '\,', '\;' ), $text );
	return str_replace( "\n", '\n', $text );
}

/**
 * RFC 5545 line folding: content lines longer than 75 octets continue on the
 * next line after a single space. Folds on character boundaries so multi-byte
 * UTF-8 is never split.
 */
function law_events_ics_fold( $line ) {
	if ( strlen( $line ) <= 75 ) {
		return $line;
	}
	$out     = '';
	$current = '';
	$limit   = 75;
	foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char ) {
		if ( strlen( $current ) + strlen( $char ) > $limit ) {
			$out    .= $current . "\r\n ";
			$current = '';
			$limit   = 74; // Continuation lines start with a space (1 octet).
		}
		$current .= $char;
	}
	return $out . $current;
}
