<?php
/**
 * The wp-admin booking screen and list columns (EVENTS_BOOKINGS.md §10).
 *
 * Read-only in v1: every mutation runs through the engine (bookings.php) so
 * the guards, seat recount and emails always fire — wp-admin's job here is
 * inspection. The CPT registers create_posts as do_not_allow, and the status
 * guard in workflow.php covers quick edit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Meta boxes _________________________________________________________________ */

add_action( 'add_meta_boxes_' . LAW_BOOKING_CPT, function () {
	// The editor and slug boxes are noise on a read-only record.
	remove_meta_box( 'slugdiv', LAW_BOOKING_CPT, 'normal' );
	add_meta_box( 'law-booking-facts', 'Booking', 'law_booking_box_facts', LAW_BOOKING_CPT, 'normal', 'high' );
	add_meta_box( 'law-booking-attendees', 'Attendees', 'law_booking_box_attendees', LAW_BOOKING_CPT, 'normal' );
	add_meta_box( 'law-booking-activity', 'Activity', 'law_booking_box_activity', LAW_BOOKING_CPT, 'normal' );
} );

/** Number, status, dates, the parent event (edit screen + front-end list). */
function law_booking_box_facts( $post ) {
	$event = get_post( (int) $post->post_parent );
	$owner = get_user_by( 'id', (int) $post->post_author );

	echo '<table class="widefat striped"><tbody>';
	$rows = array(
		'Booking number' => '#' . (int) law_event_meta( $post->ID, '_law_booking_number' ),
		'Status'         => 'law-cancelled' === $post->post_status ? 'Cancelled' : 'Active',
		'Created'        => mysql2date( 'j F Y, H:i', $post->post_date ),
		'Event'          => $event
			? '<a href="' . esc_url( get_edit_post_link( $event->ID ) ) . '">' . esc_html( $event->post_title ) . '</a>'
				. ' · <a href="' . esc_url( law_booking_list_url( $event->ID ) ) . '">' . esc_html__( 'front-end bookings list', 'law' ) . '</a>'
			: '(missing)',
		'Booked by'      => $owner
			? '<a href="' . esc_url( get_edit_user_link( $owner->ID ) ) . '">' . esc_html( $owner->display_name ) . '</a> (' . esc_html( $owner->user_email ) . ')'
			: '(missing account)',
	);
	foreach ( $rows as $label => $value ) {
		printf( '<tr><th style="width:12em">%s</th><td>%s</td></tr>', esc_html( $label ), wp_kses_post( $value ) );
	}
	echo '</tbody></table>';
	echo '<p class="description">Bookings are managed from the front end (the owner\'s manage view, and the host/committee bookings list) so the capacity, duplicate and clash guards always run; this screen is read-only.</p>';
}

/** The attendee rows: snapshot facts plus a link to each linked account. */
function law_booking_box_attendees( $post ) {
	$rows = law_event_meta( $post->ID, '_law_attendee_rows' );
	if ( ! $rows ) {
		echo '<p>No attendees (the booking was emptied and cancelled).</p>';
		return;
	}
	echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Organisation</th><th>Job title</th><th>Account</th></tr></thead><tbody>';
	foreach ( $rows as $row ) {
		$user = ! empty( $row['user_id'] ) ? get_user_by( 'id', (int) $row['user_id'] ) : null;
		printf(
			'<tr><td>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( (string) ( $row['name'] ?? '' ) ),
			! empty( $row['is_owner'] ) ? ' <em>(booker)</em>' : '',
			esc_html( (string) ( $row['email'] ?? '' ) ),
			esc_html( (string) ( $row['organisation'] ?? '' ) ),
			esc_html( (string) ( $row['job_title'] ?? '' ) ),
			$user
				? '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">#' . (int) $user->ID . '</a>'
				: '<em>none</em>'
		);
	}
	echo '</tbody></table>';
}

/**
 * The parent event's activity log filtered to this booking (context carries
 * booking => <id>; there is no separate booking log by design).
 */
function law_booking_box_activity( $post ) {
	$event_id = (int) $post->post_parent;
	$entries  = $event_id ? law_event_log_entries( $event_id ) : array();
	$shown    = 0;
	echo '<ul class="law-log">';
	foreach ( $entries as $entry ) {
		$context = law_event_log_context( $entry->comment_ID );
		if ( (int) ( $context['booking'] ?? 0 ) !== (int) $post->ID ) {
			continue;
		}
		$shown++;
		printf(
			'<li class="law-log__item is-system"><span class="law-log__date">%s</span> <strong>%s</strong><br>%s</li>',
			esc_html( mysql2date( 'j M Y, H:i', $entry->comment_date ) ),
			esc_html( $entry->comment_author ?: 'System' ),
			wp_kses_post( wpautop( $entry->comment_content ) )
		);
	}
	echo '</ul>';
	if ( ! $shown ) {
		echo '<p>No activity recorded for this booking.</p>';
	}
	if ( $event_id ) {
		echo '<p class="description">The full stream (all bookings, workflow, emails) lives on the <a href="' . esc_url( admin_url( 'post.php?post=' . $event_id . '&action=edit' ) ) . '">event</a>.</p>';
	}
}

/* List columns _______________________________________________________________ */

add_filter( 'manage_' . LAW_BOOKING_CPT . '_posts_columns', function ( $columns ) {
	return array(
		'cb'            => $columns['cb'] ?? '',
		'title'         => 'Booking',
		'law_bk_event'  => 'Event',
		'law_bk_owner'  => 'Booked by',
		'law_bk_count'  => 'Attendees',
		'law_bk_status' => 'Status',
		'date'          => 'Date',
	);
} );

add_action( 'manage_' . LAW_BOOKING_CPT . '_posts_custom_column', function ( $column, $post_id ) {
	switch ( $column ) {
		case 'law_bk_event':
			$event = get_post( (int) get_post_field( 'post_parent', $post_id ) );
			echo $event
				? '<a href="' . esc_url( get_edit_post_link( $event->ID ) ) . '">' . esc_html( $event->post_title ) . '</a>'
				: '&mdash;';
			break;
		case 'law_bk_owner':
			$owner = get_user_by( 'id', (int) get_post_field( 'post_author', $post_id ) );
			echo $owner ? esc_html( $owner->display_name ) : '&mdash;';
			break;
		case 'law_bk_count':
			echo (int) count( law_event_meta( $post_id, '_law_attendee_rows' ) );
			break;
		case 'law_bk_status':
			echo 'law-cancelled' === get_post_status( $post_id ) ? 'Cancelled' : 'Active';
			break;
	}
}, 10, 2 );

/* The events list's Booked column ____________________________________________ */

add_filter( 'manage_' . LAW_EVENT_CPT . '_posts_columns', function ( $columns ) {
	// After Payment: sold / available, red when the committee lowered the
	// ticket number below what is already sold.
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'law_payment' === $key ) {
			$out['law_booked'] = 'Booked';
		}
	}
	if ( ! isset( $out['law_booked'] ) ) {
		$out['law_booked'] = 'Booked';
	}
	return $out;
}, 20 );

add_action( 'manage_' . LAW_EVENT_CPT . '_posts_custom_column', function ( $column, $post_id ) {
	if ( 'law_booked' !== $column ) {
		return;
	}
	$available = (int) law_event_meta( $post_id, '_law_tickets_available' );
	$sold      = law_event_attendee_total( $post_id );
	if ( $available < 1 && ! $sold ) {
		echo '&mdash;';
		return;
	}
	$label = $available > 0 ? $sold . ' / ' . $available : (string) $sold;
	echo $sold > $available && $available > 0
		? '<span style="color:#b32d2e;font-weight:600">' . esc_html( $label ) . '</span>'
		: esc_html( $label );
}, 10, 2 );
