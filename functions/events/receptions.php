<?php
/**
 * The drinks receptions (RECEPTIONS.md).
 *
 * LAW runs three receptions during the week. Monday and Wednesday are PAID and
 * pay-now, with no committee review, and both are also free with a confirmed
 * flagship place. Friday is INVITATION ONLY: the site shows it for information
 * and in the calendar, with a notice where the booking button would be.
 *
 * The shape, and why it is neither the hosted booking engine nor the flagship's:
 * a hosted place is free and instant, a flagship place is applied for and then
 * charged, and a reception place is BOUGHT — the delegate goes to Stripe's
 * hosted page, pays, and comes back with a confirmed place and a VAT invoice.
 * That is a third shape, so it has its own guards, its own meaning for the
 * statuses and its own handlers.
 *
 * Everything genuinely shared is reused rather than copied: the booking post
 * type and its numbering, the duplicate guard, the event lock, the places
 * recount, the account resolve-or-create, the activity log, the email registry,
 * the .ics generator, the whole of stripe/attendees.php, and the waitlist —
 * which is modified in place rather than forked, because the wp-admin backstops
 * call law_waitlist_process() by name and a parallel pass would never run for a
 * reception queue.
 *
 * A reception is an ordinary law_event carrying _law_is_reception, so the
 * programme, the event page, the calendar, capacity, the per-event bookings
 * list and the exports all work with no change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Reading ____________________________________________________________________ */

/** Is this event one of the receptions? */
function law_reception_is( $event_id ) {
	$event_id = (int) $event_id;
	if ( $event_id < 1 || LAW_EVENT_CPT !== get_post_type( $event_id ) ) {
		return false;
	}

	return (bool) law_event_meta( $event_id, '_law_is_reception' );
}

/** Is this booking a place at a reception? */
function law_reception_booking_is( $booking ) {
	return 'reception' === law_booking_kind( $booking );
}

/**
 * Every reception, in date order, whatever its status.
 *
 * All statuses on purpose: the committee's Manage receptions screen is where a
 * draft becomes a published one, so a list that only showed the published ones
 * would hide the thing it exists to publish.
 *
 * @param int $year Optional programme year (the law_year term).
 * @return int[] law_event post IDs, earliest first.
 */
function law_reception_ids( $year = 0 ) {
	$args = array(
		'post_type'      => LAW_EVENT_CPT,
		'post_status'    => law_event_all_status_keys(),
		'meta_key'       => '_law_start',
		'orderby'        => array( 'meta_value' => 'ASC', 'ID' => 'ASC' ),
		'fields'         => 'ids',
		'posts_per_page' => 100,
		'no_found_rows'  => true,
		'meta_query'     => array(
			array( 'key' => '_law_is_reception', 'value' => '1' ),
		),
	);
	if ( (int) $year > 0 ) {
		$args['tax_query'] = array(
			array( 'taxonomy' => 'law_year', 'field' => 'name', 'terms' => (string) (int) $year ),
		);
	}

	// A reception with no date yet has no _law_start row, and meta_key ordering
	// would drop it from the list entirely — which is exactly the reception the
	// committee has just created and needs to find. So the ordering query runs
	// first and anything missing is appended.
	$dated = get_posts( $args );

	unset( $args['meta_key'], $args['orderby'] );
	$all = get_posts( $args + array( 'orderby' => 'ID', 'order' => 'ASC' ) );

	return array_values( array_unique( array_merge( array_map( 'intval', $dated ), array_map( 'intval', $all ) ) ) );
}

/**
 * The receptions a confirmed flagship place includes: published, flagged, and
 * not yet started.
 *
 * @return int[]
 */
function law_reception_included_ids() {
	$out = array();
	foreach ( law_reception_ids() as $event_id ) {
		if ( 'publish' !== get_post_status( $event_id ) ) {
			continue;
		}
		if ( ! law_event_meta( $event_id, '_law_flagship_included' ) ) {
			continue;
		}
		$start = (string) law_event_meta( $event_id, '_law_start' );
		if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
			continue;
		}
		$out[] = (int) $event_id;
	}

	return $out;
}

/**
 * A reception's day and time as one short phrase, for a checkbox label or a
 * banner sentence: "Monday 30 November, 18:30".
 */
function law_reception_when_label( $event_id ) {
	$start = (string) law_event_meta( (int) $event_id, '_law_start' );
	if ( '' === $start ) {
		return '';
	}
	$ts = strtotime( $start );
	if ( ! $ts ) {
		return '';
	}

	return date_i18n( 'l j F', $ts ) . ', ' . substr( $start, 11, 5 );
}
