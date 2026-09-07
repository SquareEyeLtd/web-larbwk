<?php
/**
 * The bookings engine (EVENTS_BOOKINGS.md): free attendee bookings on
 * Confirmed events. A booking is a law_booking post — event = post_parent,
 * owner = post_author, status publish (active) or law-cancelled — carrying
 * one ordered attendee rows array where ROW 0 IS THE OWNER, so seat counting,
 * the duplicate guard, host reject and owner self-removal all work on one
 * shape. All mutations run through these functions; wp-admin is read-only
 * inspection (create_posts is do_not_allow, and the status guard in
 * workflow.php covers the CPT).
 *
 * Every action and refusal logs to the parent EVENT's activity log with
 * context source => 'bookings' and booking => <id>; there is no separate
 * booking log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The additional-attendee cap per booking (the owner is on top of these). */
function law_booking_max_additional() {
	return 3;
}

/* Places _____________________________________________________________________ */

/**
 * Places remaining on an event: available minus sold, clamped at 0.
 *
 * Returns NULL when _law_tickets_available is 0/unset, which means the event
 * is NOT OPEN for booking ("Bookings open soon") — the int sanitiser floors
 * at 0, so 0 and unset are indistinguishable and neither can mean "unlimited".
 *
 * @return int|null
 */
function law_event_tickets_remaining( $event_id ) {
	$available = (int) law_event_meta( $event_id, '_law_tickets_available' );
	if ( $available < 1 ) {
		return null;
	}
	$sold = (int) law_event_meta( $event_id, '_law_tickets_sold' );
	return max( 0, $available - $sold );
}

/** Total attendees across an event's active bookings (the stored recount). */
function law_event_attendee_total( $event_id ) {
	return (int) law_event_meta( $event_id, '_law_tickets_sold' );
}

/**
 * Recalculate _law_tickets_sold from the event's active bookings. Called
 * after every engine mutation, and by the wp-admin backstop hooks below (an
 * administrator trashing or deleting a booking never touches the engine).
 * Cheap and self-healing: any drift corrects at the next mutation.
 *
 * Also re-arms the capacity-warning latch when removals lift the remaining
 * count back above the threshold, so a second approach warns again.
 *
 * @param int    $event_id   law_event post ID.
 * @param string $log_source When set and the count changed, write a log line
 *                           (the engine's own actions carry the count in their
 *                           context instead, so they pass '').
 * @return int Places sold.
 */
function law_event_recount_attendees( $event_id, $log_source = '' ) {
	$event_id = (int) $event_id;
	if ( $event_id < 1 ) {
		return 0;
	}

	$sold = 0;
	// -1, not the screen cap: a truncated recount would silently reopen a
	// sold-out event. Bounded in practice by the event's own capacity.
	foreach ( law_bookings_for_event( $event_id, 'publish', -1 ) as $booking ) {
		$sold += count( law_event_meta( $booking->ID, '_law_attendee_rows' ) );
	}

	$old = (int) law_event_meta( $event_id, '_law_tickets_sold' );
	if ( $old !== $sold ) {
		law_event_update_meta( $event_id, '_law_tickets_sold', $sold );
	}

	$available = (int) law_event_meta( $event_id, '_law_tickets_available' );
	if ( $available > 0 && ( $available - $sold ) > 5 && law_event_meta( $event_id, '_law_capacity_warned' ) ) {
		delete_post_meta( $event_id, '_law_capacity_warned' );
	}

	if ( '' !== $log_source && $old !== $sold ) {
		law_event_log(
			$event_id,
			sprintf( 'Places sold recounted: %d → %d.', $old, $sold ),
			array( 'action' => 'booking_recount', 'old' => $old, 'new' => $sold, 'source' => $log_source )
		);
	}

	return $sold;
}

/* Queries ____________________________________________________________________ */

/**
 * An event's bookings.
 *
 * @param int          $event_id law_event post ID.
 * @param string|array $status   Post status(es); default active only.
 * @param int          $limit    Explicit cap (house style: never -1 on screens).
 * @return WP_Post[]
 */
function law_bookings_for_event( $event_id, $status = 'publish', $limit = 500 ) {
	return get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_parent'    => (int) $event_id,
			'post_status'    => $status,
			'posts_per_page' => (int) $limit,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
}

/**
 * Bookings a user owns or holds a seat on (any status — "Your bookings"
 * filters to active itself). Owner via post_author; seats via the flat
 * _law_booking_attendee index rows, the _law_co_owner pattern.
 *
 * @return int[] law_booking post IDs.
 */
function law_user_booking_ids( $user_id, $limit = 200 ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}

	$own = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => array( 'publish', 'law-cancelled' ),
			'author'         => $user_id,
			'fields'         => 'ids',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	global $wpdb;
	$seated = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_law_booking_attendee'
			   AND p.post_type = %s
			   AND pm.meta_value = %d
			 LIMIT %d",
			LAW_BOOKING_CPT,
			$user_id,
			(int) $limit
		)
	);

	return array_values( array_unique( array_map( 'intval', array_merge( (array) $own, (array) $seated ) ) ) );
}

/**
 * Events a user holds a place on via an ACTIVE booking (owner or seat).
 * The clash guard's source, and the "You're booked" state's.
 *
 * @return int[] law_event post IDs.
 */
function law_user_booked_event_ids( $user_id ) {
	$events = array();
	foreach ( law_user_booking_ids( $user_id ) as $booking_id ) {
		$booking = get_post( $booking_id );
		if ( $booking && 'publish' === $booking->post_status && $booking->post_parent ) {
			// A seat is a seat: only users actually on the rows count. The
			// owner may have removed themselves, in which case they manage the
			// booking but hold no place — no clash, no "You're booked".
			foreach ( law_event_meta( $booking_id, '_law_attendee_rows' ) as $row ) {
				if ( (int) ( $row['user_id'] ?? 0 ) === (int) $user_id ) {
					$events[] = (int) $booking->post_parent;
					break;
				}
			}
		}
	}
	return array_values( array_unique( $events ) );
}

/**
 * The single write path for a booking's attendee rows: the array meta plus
 * one flat _law_booking_attendee row per linked user ID (what
 * law_user_booking_ids() joins against — never REGEXP the serialised array).
 */
function law_booking_set_attendee_rows( $booking_id, array $rows ) {
	$booking_id = (int) $booking_id;
	law_event_update_meta( $booking_id, '_law_attendee_rows', array_values( $rows ) );
	delete_post_meta( $booking_id, '_law_booking_attendee' );
	foreach ( $rows as $row ) {
		$user_id = absint( $row['user_id'] ?? 0 );
		if ( $user_id ) {
			add_post_meta( $booking_id, '_law_booking_attendee', $user_id );
		}
	}
}

/** The next booking number (Booking #N), starting at 1. */
function law_bookings_next_number() {
	return law_events_bump_counter( 'law_bookings_counter' );
}

/* Guards _____________________________________________________________________ */

/**
 * Is the event open for booking at all? Confirmed, on the CPT source, with a
 * ticket number set, and not yet started (settled: booking closes at event
 * start; an event with no start cannot close by time).
 *
 * @return true|WP_Error
 */
function law_booking_guard_open( $event_id ) {
	$post = get_post( $event_id );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type || 'publish' !== $post->post_status || 'cpt' !== law_events_source() ) {
		return new WP_Error( 'law_booking_not_bookable', 'This event is not open for booking.' );
	}
	if ( null === law_event_tickets_remaining( $event_id ) ) {
		return new WP_Error( 'law_booking_not_open', 'Booking for this event has not opened yet.' );
	}
	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
		return new WP_Error( 'law_booking_closed', 'This event has taken place, so bookings are closed.' );
	}
	return true;
}

/**
 * No email may hold a place on the event twice — across every ACTIVE booking,
 * and within the submitted set itself.
 *
 * @param int      $event_id law_event post ID.
 * @param string[] $emails   Every email the caller wants seated.
 * @return true|WP_Error
 */
function law_booking_guard_duplicates( $event_id, array $emails ) {
	$emails = array_values( array_filter( array_map( 'strtolower', array_map( 'trim', $emails ) ) ) );

	$dupes = array_diff_key( $emails, array_unique( $emails ) );
	if ( $dupes ) {
		return new WP_Error( 'law_booking_duplicate', sprintf( '%s is listed more than once.', reset( $dupes ) ) );
	}

	foreach ( law_bookings_for_event( $event_id ) as $booking ) {
		foreach ( law_event_meta( $booking->ID, '_law_attendee_rows' ) as $row ) {
			$existing = strtolower( (string) ( $row['email'] ?? '' ) );
			if ( '' !== $existing && in_array( $existing, $emails, true ) ) {
				return new WP_Error( 'law_booking_duplicate', sprintf( '%s already has a place at this event.', $existing ) );
			}
		}
	}
	return true;
}

/**
 * Enough places left for the seats requested. Run inside the event lock,
 * after a fresh recount.
 *
 * @return true|WP_Error
 */
function law_booking_guard_capacity( $event_id, $seats ) {
	$remaining = law_event_tickets_remaining( $event_id );
	if ( null === $remaining ) {
		return new WP_Error( 'law_booking_not_open', 'Booking for this event has not opened yet.' );
	}
	if ( (int) $seats > $remaining ) {
		if ( 0 === $remaining ) {
			return new WP_Error( 'law_booking_full', 'This event is fully booked.' );
		}
		return new WP_Error(
			'law_booking_full',
			sprintf(
				1 === $remaining
					? 'Only 1 place is left at this event. Please remove %2$d colleague(s) and try again.'
					: 'Only %1$d places are left at this event. Please remove %2$d colleague(s) and try again.',
				$remaining,
				(int) $seats - $remaining
			)
		);
	}
	return true;
}

/**
 * No booking onto an event that overlaps one the user already holds a place
 * on (via an active booking). Overlap: other_start < this_end AND
 * other_end > this_start. A missing end is treated as 23:59 of the start
 * date — conservative, so an "18:00 onwards" event clashes with a 19:00 one.
 * Events with no start cannot clash.
 *
 * @param int    $user_id       The attendee (0 = no account yet, no clash possible).
 * @param int    $event_id      The event being booked.
 * @param string $attendee_name For the message; '' means the booker themselves.
 * @return true|WP_Error
 */
function law_booking_guard_clash( $user_id, $event_id, $attendee_name = '' ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return true;
	}

	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' === $start ) {
		return true;
	}
	$this_start = (int) strtotime( $start );
	$this_end   = law_booking_clash_end( $start, (string) law_event_meta( $event_id, '_law_end' ) );

	foreach ( law_user_booked_event_ids( $user_id ) as $other_id ) {
		if ( (int) $other_id === (int) $event_id ) {
			continue;
		}
		$other_start = (string) law_event_meta( $other_id, '_law_start' );
		if ( '' === $other_start ) {
			continue;
		}
		$os = (int) strtotime( $other_start );
		$oe = law_booking_clash_end( $other_start, (string) law_event_meta( $other_id, '_law_end' ) );
		if ( $os < $this_end && $oe > $this_start ) {
			$who = '' !== $attendee_name ? $attendee_name . ' is' : 'You are';
			return new WP_Error(
				'law_booking_clash',
				sprintf( '%s already booked on "%s", which overlaps with this event.', $who, get_the_title( $other_id ) )
			);
		}
	}
	return true;
}

/**
 * The end timestamp the clash test uses: the stored end, unless it is missing
 * OR not after the start (a fat-fingered end date on the admin screen would
 * otherwise make the overlap test unsatisfiable and let double-bookings
 * through silently — adversarial review, 7 September 2026). Either way the
 * conservative fallback is 23:59 of the start date.
 */
function law_booking_clash_end( $start, $end ) {
	$start_ts = (int) strtotime( $start );
	$end_ts   = '' !== $end ? (int) strtotime( $end ) : 0;
	return $end_ts > $start_ts ? $end_ts : (int) strtotime( substr( $start, 0, 10 ) . ' 23:59' );
}

/**
 * Validate and normalise the submitted additional-attendee rows: cap 3, full
 * name and a valid email required, organisation and job title optional.
 * Errors carry data ['row' => index, 'field' => name] so the form can mark
 * the offending control.
 *
 * @return array|WP_Error Clean rows (is_owner 0, user_id 0 — resolved later).
 */
function law_booking_clean_additional_rows( array $rows ) {
	$clean = array();
	foreach ( array_values( $rows ) as $i => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$name         = sanitize_text_field( (string) ( $row['name'] ?? '' ) );
		$email        = sanitize_email( (string) ( $row['email'] ?? '' ) );
		$organisation = sanitize_text_field( (string) ( $row['organisation'] ?? '' ) );
		$job_title    = sanitize_text_field( (string) ( $row['job_title'] ?? '' ) );
		if ( '' === $name && '' === $email && '' === $organisation && '' === $job_title ) {
			continue; // A fully empty repeater row is just skipped.
		}
		if ( count( $clean ) >= law_booking_max_additional() ) {
			return new WP_Error(
				'law_booking_too_many',
				sprintf( 'A booking can include at most %d additional attendees.', law_booking_max_additional() )
			);
		}
		if ( '' === $name ) {
			return new WP_Error( 'law_booking_invalid_row', sprintf( 'Please give attendee %d\'s full name.', count( $clean ) + 1 ), array( 'row' => $i, 'field' => 'name' ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'law_booking_invalid_row', sprintf( 'Please give a valid email address for %s.', $name ), array( 'row' => $i, 'field' => 'email' ) );
		}
		$clean[] = array(
			'user_id'      => 0,
			'name'         => $name,
			'email'        => $email,
			'organisation' => $organisation,
			'job_title'    => $job_title,
			'is_owner'     => 0,
		);
	}
	return $clean;
}

/* The event lock _____________________________________________________________ */

/**
 * MySQL advisory lock around recount + capacity check + write, closing the
 * two-tabs-book-the-last-place race. Callers MUST release on every path.
 */
function law_booking_lock( $event_id ) {
	global $wpdb;
	return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 3)', 'law_booking_event_' . (int) $event_id ) );
}

function law_booking_unlock( $event_id ) {
	global $wpdb;
	$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'law_booking_event_' . (int) $event_id ) );
}

/* Accounts ___________________________________________________________________ */

/**
 * Resolve one additional-attendee row to a user account: link an existing
 * account by email (granting the attendee role if missing), or create a new
 * attendee account and send the set-password invite. Mirrors the co-owner
 * walk (law_event_ensure_co_owner_users()), sharing its creator and
 * password-link helpers.
 *
 * Never fatal: an account failure logs and returns the error, and the caller
 * keeps the row seated with user_id 0 (the snapshot still counts a place and
 * feeds the host list; there is just no account behind it).
 *
 * @param array $row        Clean attendee row.
 * @param int   $event_id   Parent event (for logs and email context).
 * @param int   $booking_id The booking (for log context).
 * @param int   $actor      Acting user for the log.
 * @return array|WP_Error [ 'user_id' => int, 'created' => bool ].
 */
function law_booking_ensure_attendee_user( array $row, $event_id, $booking_id, $actor ) {
	$email = (string) $row['email'];
	$user  = get_user_by( 'email', $email );

	if ( $user ) {
		if ( ! in_array( 'attendee', (array) $user->roles, true ) ) {
			$user->add_role( 'attendee' );
			law_event_log(
				$event_id,
				sprintf( 'Attendee role granted to %s (%s) for a booking seat.', $user->display_name, $email ),
				array( 'action' => 'booking_role_granted', 'booking' => (int) $booking_id, 'user' => (int) $user->ID, 'source' => 'bookings' ),
				array( 'user_id' => (int) $actor )
			);
		}
		law_event_log(
			$event_id,
			sprintf( 'Booking attendee linked to existing account: %s (%s).', $user->display_name, $email ),
			array( 'action' => 'booking_attendee_linked', 'booking' => (int) $booking_id, 'user' => (int) $user->ID, 'source' => 'bookings' ),
			array( 'user_id' => (int) $actor )
		);
		law_booking_send_with_ics(
			'user_attendee_added',
			$event_id,
			array(
				'to'           => array( $email ),
				'placeholders' => array_merge( law_booking_email_placeholders( $booking_id ), array( 'attendee_name' => $row['name'] ) ),
			)
		);
		return array( 'user_id' => (int) $user->ID, 'created' => false );
	}

	$user_id = law_events_create_host_user(
		$email,
		$row['name'],
		$row['organisation'],
		array( 'role' => 'attendee', 'job_title' => $row['job_title'] )
	);
	if ( is_wp_error( $user_id ) ) {
		law_event_log(
			$event_id,
			sprintf( 'Booking attendee account creation failed for %s: %s', $email, $user_id->get_error_message() ),
			array( 'action' => 'booking_attendee_error', 'booking' => (int) $booking_id, 'source' => 'bookings' ),
			array( 'user_id' => (int) $actor )
		);
		return $user_id;
	}

	law_event_log(
		$event_id,
		sprintf( 'Booking attendee account created: %s (%s).', $row['name'], $email ),
		array( 'action' => 'booking_attendee_account_created', 'booking' => (int) $booking_id, 'user' => (int) $user_id, 'source' => 'bookings' ),
		array( 'user_id' => (int) $actor )
	);

	$user = get_user_by( 'id', $user_id );
	law_booking_send_with_ics(
		'user_attendee_invited',
		$event_id,
		array(
			'to'           => array( $email ),
			'placeholders' => array_merge(
				law_booking_email_placeholders( $booking_id ),
				array(
					'attendee_name'     => $row['name'],
					'username'          => $user ? $user->user_login : $email,
					'set_password_link' => $user ? law_events_password_setup_link( $user, $event_id, 'booking_attendee_error' ) : '',
				)
			),
		)
	);

	return array( 'user_id' => (int) $user_id, 'created' => true );
}

/* Mutations __________________________________________________________________ */

/**
 * Create a booking: the owner plus up to 3 named colleagues.
 *
 * @param int   $event_id        law_event post ID.
 * @param int   $owner_id        The booking attendee (becomes post_author).
 * @param array $additional_rows Raw repeater rows (name/email/organisation/job_title).
 * @return int|WP_Error Booking post ID.
 */
function law_booking_create( $event_id, $owner_id, array $additional_rows ) {
	$event_id = (int) $event_id;
	$owner_id = (int) $owner_id;

	$open = law_booking_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return law_booking_log_refusal( $event_id, 0, $open, $owner_id );
	}
	$owner = get_user_by( 'id', $owner_id );
	if ( ! $owner ) {
		return new WP_Error( 'law_booking_no_user', 'You need to be signed in to book.' );
	}

	$additional = law_booking_clean_additional_rows( $additional_rows );
	if ( is_wp_error( $additional ) ) {
		return $additional;
	}

	$profile   = law_profile_values( $owner_id );
	$owner_row = array(
		'user_id'      => $owner_id,
		'name'         => trim( $owner->first_name . ' ' . $owner->last_name ) ?: $owner->display_name,
		'email'        => $owner->user_email,
		'organisation' => (string) ( $profile['organisation'] ?? '' ),
		'job_title'    => (string) ( $profile['job_title'] ?? '' ),
		'is_owner'     => 1,
	);

	// EVERY shared-state guard runs inside the event lock (security review,
	// 7 September 2026): two near-simultaneous requests must serialise before
	// the duplicate / own-booking / clash / capacity reads, or both pass the
	// pre-checks and the one-booking-per-person and seat caps are defeated.
	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', 'The event is busy taking another booking. Please try again in a moment.' );
	}
	$refuse = function ( WP_Error $error ) use ( $event_id, $owner_id ) {
		law_booking_unlock( $event_id );
		return law_booking_log_refusal( $event_id, 0, $error, $owner_id );
	};

	law_event_recount_attendees( $event_id );

	$dup = law_booking_guard_duplicates( $event_id, array_merge( array( $owner_row['email'] ), wp_list_pluck( $additional, 'email' ) ) );
	if ( is_wp_error( $dup ) ) {
		return $refuse( $dup );
	}
	// One active booking per person per event, even for an owner who removed
	// their own seat (whose email the duplicate guard above no longer sees):
	// they adjust their existing booking, never open a second one.
	foreach ( law_bookings_for_event( $event_id ) as $existing_booking ) {
		if ( (int) $existing_booking->post_author === $owner_id ) {
			return $refuse( new WP_Error( 'law_booking_duplicate', 'You already have a booking for this event. You can manage it from Your bookings.' ) );
		}
	}

	// Clash: the owner, and every colleague who already has an account. A
	// colleague with no account yet can hold no other booking.
	$clash = law_booking_guard_clash( $owner_id, $event_id );
	if ( is_wp_error( $clash ) ) {
		return $refuse( $clash );
	}
	foreach ( $additional as $row ) {
		$existing = get_user_by( 'email', $row['email'] );
		if ( $existing ) {
			$clash = law_booking_guard_clash( (int) $existing->ID, $event_id, $row['name'] );
			if ( is_wp_error( $clash ) ) {
				return $refuse( $clash );
			}
		}
	}

	$cap = law_booking_guard_capacity( $event_id, count( $additional ) + 1 );
	if ( is_wp_error( $cap ) ) {
		return $refuse( $cap );
	}

	// Any logged-in user can book: the attendee role is granted, not required
	// (settled; mirrors what invited guests get).
	if ( ! in_array( 'attendee', (array) $owner->roles, true ) ) {
		$owner->add_role( 'attendee' );
		law_event_log(
			$event_id,
			sprintf( 'Attendee role granted to %s (%s) on booking.', $owner->display_name, $owner->user_email ),
			array( 'action' => 'booking_role_granted', 'user' => $owner_id, 'source' => 'bookings' ),
			array( 'user_id' => $owner_id )
		);
	}

	$number     = law_bookings_next_number();
	$booking_id = wp_insert_post(
		array(
			'post_type'   => LAW_BOOKING_CPT,
			'post_status' => 'publish',
			'post_title'  => 'Booking #' . $number,
			'post_parent' => $event_id,
			'post_author' => $owner_id,
		),
		true
	);
	if ( is_wp_error( $booking_id ) ) {
		law_booking_unlock( $event_id );
		return $booking_id;
	}
	$booking_id = (int) $booking_id;
	law_event_update_meta( $booking_id, '_law_booking_number', $number );

	// Seats are written INSIDE the lock, with existing accounts' IDs resolved
	// cheaply; account creation and the attendee emails (password hashing plus
	// synchronous sends, seconds against the 3-second lock wait) run after the
	// unlock, so parallel bookers on a hot event are never queued behind
	// another booking's emails (performance + adversarial reviews, 7 September
	// 2026). A seat is valid with user_id 0 — the duplicate guard works on
	// email — and the resolved IDs are backfilled below.
	$rows = array( $owner_row );
	foreach ( $additional as $row ) {
		$known          = get_user_by( 'email', $row['email'] );
		$row['user_id'] = $known ? (int) $known->ID : 0;
		$rows[]         = $row;
	}
	law_booking_set_attendee_rows( $booking_id, $rows );
	$sold = law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );

	// Accounts and the invite/added emails, outside the lock; an account
	// failure keeps the seat (the snapshot counts). New IDs are backfilled.
	$backfilled = false;
	foreach ( $rows as $i => $row ) {
		if ( ! empty( $row['is_owner'] ) ) {
			continue;
		}
		$result = law_booking_ensure_attendee_user( $row, $event_id, $booking_id, $owner_id );
		if ( ! is_wp_error( $result ) && (int) $result['user_id'] !== (int) $row['user_id'] ) {
			$rows[ $i ]['user_id'] = (int) $result['user_id'];
			$backfilled            = true;
		}
	}
	if ( $backfilled ) {
		law_booking_set_attendee_rows( $booking_id, $rows );
	}

	law_event_log(
		$event_id,
		sprintf( 'Booking #%d created by %s: %d attendee%s.', $number, $owner->display_name, count( $rows ), 1 === count( $rows ) ? '' : 's' ),
		array( 'action' => 'booking_created', 'booking' => $booking_id, 'attendees' => count( $rows ), 'sold' => $sold, 'source' => 'bookings' ),
		array( 'user_id' => $owner_id )
	);

	$placeholders = law_booking_email_placeholders( $booking_id );
	law_booking_send_with_ics(
		'user_booking_confirmed',
		$event_id,
		array(
			'to'           => array( $owner->user_email ),
			'placeholders' => array_merge( $placeholders, array( 'attendee_name' => $owner_row['name'] ) ),
		)
	);
	law_events_send( 'host_booking_received', $event_id, array( 'placeholders' => $placeholders ) );
	// Committee copy goes to the event's assignee when one is set (settled),
	// falling back to the registry's committee audience.
	$assignee = get_user_by( 'id', (int) law_event_meta( $event_id, '_law_assignee' ) );
	$extra    = array( 'placeholders' => $placeholders );
	if ( $assignee && is_email( $assignee->user_email ) ) {
		$extra['to'] = array( $assignee->user_email );
	}
	law_events_send( 'committee_booking_received', $event_id, $extra );

	law_booking_maybe_capacity_warning( $event_id );

	return $booking_id;
}

/**
 * Add one attendee to an active booking (owner action; same guards as create,
 * scoped to one place).
 *
 * @return true|WP_Error
 */
function law_booking_add_attendee( $booking_id, array $raw_row, $actor_id ) {
	$booking = get_post( $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type || 'publish' !== $booking->post_status ) {
		return new WP_Error( 'law_booking_not_active', 'This booking is no longer active.' );
	}
	$event_id = (int) $booking->post_parent;

	$open = law_booking_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return law_booking_log_refusal( $event_id, $booking->ID, $open, $actor_id );
	}

	$clean = law_booking_clean_additional_rows( array( $raw_row ) );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	$row = $clean[0] ?? null;
	if ( ! $row ) {
		return new WP_Error( 'law_booking_invalid_row', 'Please complete the attendee details.' );
	}

	// Shared-state guards run inside the lock (security review, 7 September
	// 2026): the cap count, the duplicate scan and the clash read must see
	// serialised state, or two parallel adds both pass.
	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', 'The event is busy taking another booking. Please try again in a moment.' );
	}
	$refuse = function ( WP_Error $error ) use ( $event_id, $booking, $actor_id ) {
		law_booking_unlock( $event_id );
		return law_booking_log_refusal( $event_id, $booking->ID, $error, $actor_id );
	};

	law_event_recount_attendees( $event_id );

	$rows       = law_event_meta( $booking->ID, '_law_attendee_rows' );
	$additional = array_filter( $rows, fn( $r ) => empty( $r['is_owner'] ) );
	if ( count( $additional ) >= law_booking_max_additional() ) {
		law_booking_unlock( $event_id );
		return new WP_Error( 'law_booking_too_many', sprintf( 'A booking can include at most %d additional attendees.', law_booking_max_additional() ) );
	}

	$dup = law_booking_guard_duplicates( $event_id, array( $row['email'] ) );
	if ( is_wp_error( $dup ) ) {
		return $refuse( $dup );
	}
	$existing = get_user_by( 'email', $row['email'] );
	if ( $existing ) {
		$clash = law_booking_guard_clash( (int) $existing->ID, $event_id, $row['name'] );
		if ( is_wp_error( $clash ) ) {
			return $refuse( $clash );
		}
	}

	$cap = law_booking_guard_capacity( $event_id, 1 );
	if ( is_wp_error( $cap ) ) {
		return $refuse( $cap );
	}

	// Seat first (inside the lock, existing account resolved cheaply); the
	// account/email work runs after the unlock — see law_booking_create().
	$row['user_id'] = $existing ? (int) $existing->ID : 0;
	$rows[]         = $row;
	law_booking_set_attendee_rows( $booking->ID, $rows );
	$sold = law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );

	$result = law_booking_ensure_attendee_user( $row, $event_id, $booking->ID, $actor_id );
	if ( ! is_wp_error( $result ) && (int) $result['user_id'] !== (int) $row['user_id'] ) {
		$rows[ array_key_last( $rows ) ]['user_id'] = (int) $result['user_id'];
		law_booking_set_attendee_rows( $booking->ID, $rows );
	}

	law_event_log(
		$event_id,
		sprintf( 'Attendee added to Booking #%d: %s (%s).', (int) law_event_meta( $booking->ID, '_law_booking_number' ), $row['name'], $row['email'] ),
		array( 'action' => 'booking_attendee_added', 'booking' => (int) $booking->ID, 'sold' => $sold, 'source' => 'bookings' ),
		array( 'user_id' => (int) $actor_id )
	);

	law_booking_maybe_capacity_warning( $event_id );

	return true;
}

/**
 * Remove one attendee from a booking (never the WordPress user). Removing the
 * last seated attendee cancels the booking.
 *
 * @param int    $booking_id law_booking post ID.
 * @param string $email      The row to remove (emails are unique per event).
 * @param int    $actor_id   Acting user.
 * @param string $context    'owner' | 'self' | 'host_reject' — picks the
 *                           notification template and the log wording.
 * @param array  $args       Optional: reason (host_reject).
 * @return true|WP_Error
 */
function law_booking_remove_attendee( $booking_id, $email, $actor_id, $context = 'owner', array $args = array() ) {
	$booking = get_post( $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type || 'publish' !== $booking->post_status ) {
		return new WP_Error( 'law_booking_not_active', 'This booking is no longer active.' );
	}
	$event_id = (int) $booking->post_parent;
	$email    = strtolower( trim( (string) $email ) );

	// The read-modify-write on the rows array runs under the event lock
	// (security review, 7 September 2026): two concurrent removals — a host
	// reject racing a self-removal, say — would otherwise both read the same
	// starting rows and the second write would silently resurrect the first
	// removal. MySQL's GET_LOCK is re-entrant per session, so the last-row
	// path calling law_booking_cancel() (which locks too) is fine.
	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', 'The booking is busy with another change. Please try again in a moment.' );
	}

	$rows    = law_event_meta( $booking->ID, '_law_attendee_rows' );
	$removed = null;
	foreach ( $rows as $i => $row ) {
		if ( strtolower( (string) ( $row['email'] ?? '' ) ) === $email ) {
			$removed = $row;
			unset( $rows[ $i ] );
			break;
		}
	}
	if ( null === $removed ) {
		law_booking_unlock( $event_id );
		return new WP_Error( 'law_booking_no_attendee', 'That attendee is not on this booking.' );
	}

	law_booking_set_attendee_rows( $booking->ID, array_values( $rows ) );
	$sold = law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );
	$number = (int) law_event_meta( $booking->ID, '_law_booking_number' );
	$reason = trim( (string) ( $args['reason'] ?? '' ) );

	$log_action = 'host_reject' === $context ? 'booking_attendee_rejected' : 'booking_attendee_removed';
	law_event_log(
		$event_id,
		sprintf(
			'host_reject' === $context
				? 'Attendee rejected from Booking #%1$d: %2$s (%3$s).%4$s'
				: 'Attendee removed from Booking #%1$d: %2$s (%3$s).%4$s',
			$number,
			(string) $removed['name'],
			(string) $removed['email'],
			'' !== $reason ? ' Reason: ' . $reason : ''
		),
		array( 'action' => $log_action, 'booking' => (int) $booking->ID, 'context' => $context, 'sold' => $sold, 'source' => 'bookings' ),
		array( 'user_id' => (int) $actor_id )
	);

	// Every removal notifies the removed person (settled), with a template per
	// context: one generic "you have been removed" would mis-describe most of them.
	$slugs = array(
		'host_reject' => 'user_attendee_rejected',
		'self'        => 'user_attendee_removed_self',
		'owner'       => 'user_attendee_removed',
	);
	if ( isset( $slugs[ $context ] ) && is_email( $removed['email'] ) ) {
		$host = get_user_by( 'id', (int) get_post_field( 'post_author', $event_id ) );
		law_events_send(
			$slugs[ $context ],
			$event_id,
			array(
				'to'           => array( $removed['email'] ),
				'placeholders' => array_merge(
					law_booking_email_placeholders( $booking->ID ),
					array(
						'attendee_name'  => (string) $removed['name'],
						'removal_reason' => $reason,
						'host_email'     => $host ? $host->user_email : '',
					)
				),
			)
		);
	}

	if ( empty( $rows ) ) {
		law_booking_cancel( $booking->ID, $actor_id, 'last_attendee_removed' );
	}

	return true;
}

/**
 * Cancel a booking. Idempotent; there is no un-cancel.
 *
 * @param int    $booking_id law_booking post ID.
 * @param int    $actor_id   Acting user.
 * @param string $context    'owner' (owner cancelled — each seated attendee is
 *                           emailed), 'event_cancelled' (the event-cancel
 *                           sweep — different template), or
 *                           'last_attendee_removed' (nobody left to email).
 * @return true|WP_Error
 */
function law_booking_cancel( $booking_id, $actor_id, $context = 'owner' ) {
	$booking = get_post( $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return new WP_Error( 'law_booking_missing', 'Booking not found.' );
	}
	if ( 'law-cancelled' === $booking->post_status ) {
		return true;
	}
	$event_id = (int) $booking->post_parent;

	// Under the event lock like every other mutation (GET_LOCK is re-entrant
	// per session, so the remove-last-row caller holding it already is fine).
	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', 'The booking is busy with another change. Please try again in a moment.' );
	}
	$rows = law_event_meta( $booking->ID, '_law_attendee_rows' );

	// The last-row auto-cancel decision was made before this lock was taken:
	// a concurrent add may have seated someone in the gap (adversarial review,
	// 7 September 2026). Re-check under the lock and keep the booking alive —
	// cancelling here would strand an attendee who was just emailed a place.
	if ( 'last_attendee_removed' === $context && ! empty( $rows ) ) {
		law_booking_unlock( $event_id );
		return true;
	}

	// The status guard in workflow.php only lets a booking's status move
	// while this flag is up: this function is the only cancel path.
	$GLOBALS['law_booking_transitioning'] = true;
	$updated = wp_update_post( array( 'ID' => $booking->ID, 'post_status' => 'law-cancelled' ), true );
	$GLOBALS['law_booking_transitioning'] = false;
	if ( is_wp_error( $updated ) ) {
		law_booking_unlock( $event_id );
		return $updated;
	}

	$sold = law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );
	law_event_log(
		$event_id,
		sprintf( 'Booking #%d cancelled (%s).', (int) law_event_meta( $booking->ID, '_law_booking_number' ), str_replace( '_', ' ', $context ) ),
		array( 'action' => 'booking_cancelled', 'booking' => (int) $booking->ID, 'context' => $context, 'sold' => $sold, 'source' => 'bookings' ),
		array( 'user_id' => (int) $actor_id )
	);

	$slugs = array(
		'owner'           => 'user_booking_cancelled_attendee',
		'event_cancelled' => 'user_booking_event_cancelled',
	);
	if ( isset( $slugs[ $context ] ) ) {
		$placeholders = law_booking_email_placeholders( $booking->ID );
		foreach ( $rows as $row ) {
			if ( is_email( (string) ( $row['email'] ?? '' ) ) ) {
				law_events_send(
					$slugs[ $context ],
					$event_id,
					array(
						'to'           => array( $row['email'] ),
						'placeholders' => array_merge( $placeholders, array( 'attendee_name' => (string) $row['name'] ) ),
					)
				);
			}
		}
	}

	return true;
}

/**
 * The event-cancel sweep (EVENTS_BOOKINGS.md §5): when an event is cancelled
 * with active bookings, every booking is cancelled and every attendee emailed
 * (`user_booking_event_cancelled`, settled). Called directly from the
 * workflow's cancel side effects, the way every other side effect is wired.
 * Withdraw never needs it: only draft/proposed/sent-back events can withdraw,
 * and none of those can hold bookings (the open guard requires publish).
 */
function law_bookings_cancel_all_for_event( $event_id, $actor = 0, $source = 'workflow' ) {
	$event_id = (int) $event_id;
	$bookings = law_bookings_for_event( $event_id, 'publish', -1 );
	$done     = 0;
	$started  = time();

	foreach ( $bookings as $booking ) {
		law_booking_cancel( $booking->ID, (int) $actor, 'event_cancelled' );
		$done++;
		// Every cancel emails its attendees synchronously, so a big event's
		// sweep could outlive PHP's execution limit with the event already
		// Cancelled and the transition unrepeatable (adversarial review,
		// 7 September 2026). Time-box it and hand the remainder to cron —
		// the sweep is naturally resumable, since it only ever sees the
		// bookings still active.
		if ( time() - $started > 15 && $done < count( $bookings ) ) {
			wp_schedule_single_event( time() + 60, 'law_bookings_resume_cancel_sweep', array( $event_id, (int) $actor ) );
			law_event_log(
				$event_id,
				sprintf( 'Event-cancel sweep time-boxed after %d of %d bookings; the rest continue in the background within a few minutes.', $done, count( $bookings ) ),
				array( 'action' => 'booking_event_cancel_sweep', 'done' => $done, 'of' => count( $bookings ), 'source' => $source ),
				array( 'user_id' => (int) $actor )
			);
			return $done;
		}
	}

	if ( $bookings ) {
		law_event_log(
			$event_id,
			sprintf(
				1 === count( $bookings )
					? 'Event cancelled with %d active booking: cancelled, attendees emailed.'
					: 'Event cancelled with %d active bookings: all cancelled, attendees emailed.',
				count( $bookings )
			),
			array( 'action' => 'booking_event_cancel_sweep', 'bookings' => count( $bookings ), 'source' => $source ),
			array( 'user_id' => (int) $actor )
		);
	}
	return $done;
}
add_action( 'law_bookings_resume_cancel_sweep', 'law_bookings_cancel_all_for_event', 10, 2 );

/* Notifications support ______________________________________________________ */

/**
 * Send one bookings email with the event's .ics invite attached (confirmation
 * and the two attendee-added emails carry one; nothing attaches when the event
 * has no start). The temp file is deleted straight after the synchronous send.
 */
function law_booking_send_with_ics( $slug, $event_id, array $extra ) {
	$ics = law_event_ics_tempfile( $event_id );
	if ( '' !== $ics ) {
		$extra['attachments'] = array( $ics );
	}
	try {
		return law_events_send( $slug, $event_id, $extra );
	} finally {
		// finally, so a fatal inside a mail plugin cannot orphan a file of
		// attendee-visible event details in the shared temp dir.
		if ( '' !== $ics && file_exists( $ics ) ) {
			unlink( $ics );
		}
	}
}

/**
 * The booking-specific placeholder values every bookings email send merges in.
 */
function law_booking_email_placeholders( $booking_id ) {
	$booking_id = (int) $booking_id;
	$event_id   = (int) get_post_field( 'post_parent', $booking_id );

	$list = array();
	foreach ( law_event_meta( $booking_id, '_law_attendee_rows' ) as $row ) {
		$line = trim( (string) ( $row['name'] ?? '' ) ) . ' (' . (string) ( $row['email'] ?? '' ) . ')';
		$facts = array_filter( array( (string) ( $row['organisation'] ?? '' ), (string) ( $row['job_title'] ?? '' ) ) );
		if ( $facts ) {
			$line .= ' — ' . implode( ', ', $facts );
		}
		$list[] = $line;
	}

	$available = (int) law_event_meta( $event_id, '_law_tickets_available' );
	$remaining = law_event_tickets_remaining( $event_id );

	return array(
		'booking_number'    => (string) (int) law_event_meta( $booking_id, '_law_booking_number' ),
		'attendee_list'     => implode( "\n", $list ),
		'tickets_available' => $available > 0 ? (string) $available : '',
		'tickets_remaining' => null === $remaining ? '' : (string) $remaining,
		'bookings_link'     => home_url( '/account/events/' ),
		'profile_link'      => home_url( '/account/profile/' ),
	);
}

/**
 * Warn the host once when the event comes within 5 places of capacity. The
 * latch (_law_capacity_warned) is re-armed by the recount when removals lift
 * remaining back above 5.
 */
function law_booking_maybe_capacity_warning( $event_id ) {
	$remaining = law_event_tickets_remaining( $event_id );
	if ( null === $remaining || $remaining > 5 ) {
		return;
	}
	if ( law_event_meta( $event_id, '_law_capacity_warned' ) ) {
		return;
	}
	law_event_update_meta( $event_id, '_law_capacity_warned', 1 );
	law_events_send( 'host_capacity_warning', $event_id, array( 'placeholders' => array(
		'tickets_available' => (string) (int) law_event_meta( $event_id, '_law_tickets_available' ),
		'tickets_remaining' => (string) $remaining,
	) ) );
	law_event_log(
		$event_id,
		sprintf( 'Capacity warning sent to the host: %d place%s remaining.', $remaining, 1 === $remaining ? '' : 's' ),
		array( 'action' => 'booking_capacity_warning', 'remaining' => $remaining, 'source' => 'bookings' )
	);
}

/**
 * Refusals are logged too (WooCommerce-notes exhaustiveness), then passed
 * straight back to the caller.
 *
 * @return WP_Error The same error, for `return law_booking_log_refusal(...)`.
 */
function law_booking_log_refusal( $event_id, $booking_id, WP_Error $error, $actor_id ) {
	law_event_log(
		$event_id,
		sprintf( 'Booking refused: %s', $error->get_error_message() ),
		array( 'action' => 'booking_guard_refused', 'booking' => (int) $booking_id, 'code' => $error->get_error_code(), 'source' => 'bookings' ),
		array( 'user_id' => (int) $actor_id )
	);
	return $error;
}

/* Admin-post handlers ________________________________________________________ */

add_action( 'admin_post_law_booking_create', 'law_booking_create_handler' );
add_action( 'admin_post_nopriv_law_booking_create', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_add_attendee', 'law_booking_add_attendee_handler' );
add_action( 'admin_post_nopriv_law_booking_add_attendee', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_remove_attendee', 'law_booking_remove_attendee_handler' );
add_action( 'admin_post_nopriv_law_booking_remove_attendee', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_cancel', 'law_booking_cancel_handler' );
add_action( 'admin_post_nopriv_law_booking_cancel', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_reject_attendee', 'law_booking_reject_attendee_handler' );
add_action( 'admin_post_nopriv_law_booking_reject_attendee', 'law_events_nopriv_json' );

/**
 * Book now: create a booking for the signed-in user (the attendee role is
 * granted by the engine, not required).
 */
function law_booking_create_handler() {
	$event_id = absint( $_POST['event_id'] ?? 0 );
	$link     = $event_id ? get_permalink( $event_id ) : home_url( '/account/events/' );
	$is_ajax  = law_events_guard_post(
		'law_booking_create',
		array(
			'rate'            => array( 'booking', 10, 600, 100 ),
			'honeypot_json'   => array( 'title' => 'Booking confirmed', 'message' => 'Your booking has been received.', 'redirect' => $link ),
			'honeypot_notice' => 'booking-created',
		)
	);

	$rows   = wp_unslash( $_POST['law_attendees'] ?? array() );
	$result = law_booking_create( $event_id, get_current_user_id(), is_array( $rows ) ? $rows : array() );

	if ( is_wp_error( $result ) ) {
		if ( ! $is_ajax ) {
			// The inline (?law_book=1) form re-renders with the typed rows.
			law_booking_store_form_state( get_current_user_id(), $result, is_array( $rows ) ? $rows : array() );
		}
		$payload = array( 'message' => $result->get_error_message() );
		$data    = $result->get_error_data();
		if ( is_array( $data ) ) {
			$payload += array_intersect_key( $data, array( 'row' => 1, 'field' => 1 ) );
		}
		law_events_respond( $is_ajax, false, $payload, 'booking-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Booking confirmed',
			'message'  => 'A confirmation with a calendar invitation is on its way to you.',
			'redirect' => add_query_arg( 'law_notice', 'booking-created', $link ),
		),
		'booking-created'
	);
}

/** The manage view's add-attendee action (booking owner only). */
function law_booking_add_attendee_handler() {
	$is_ajax = law_events_guard_post(
		'law_booking_add_attendee',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'title' => 'Attendee added', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'attendee-added',
		)
	);

	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	$booking    = get_post( $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type || (int) $booking->post_author !== get_current_user_id() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to change this booking.', 'status' => 403 ), 'booking-failed' );
	}

	$row    = wp_unslash( $_POST['law_attendees'] ?? array() );
	$row    = is_array( $row ) ? reset( $row ) : array();
	$result = law_booking_add_attendee( $booking_id, is_array( $row ) ? $row : array(), get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		$payload = array( 'message' => $result->get_error_message() );
		$data    = $result->get_error_data();
		if ( is_array( $data ) ) {
			$payload += array_intersect_key( $data, array( 'row' => 1, 'field' => 1 ) );
		}
		law_events_respond( $is_ajax, false, $payload, 'booking-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Attendee added',
			'message'  => 'They have been emailed the event details. Reloading the page…',
			// The notice rides the redirect so the fetch flow's reload shows the
			// same confirmation banner the no-JS flow gets.
			'redirect' => add_query_arg( 'law_notice', 'attendee-added', law_booking_manage_url( $booking_id ) ),
		),
		'attendee-added'
	);
}

/**
 * Remove one attendee from a booking: the owner removing anyone on it, or a
 * seated attendee removing themselves. The context (owner/self) picks the
 * notification template.
 */
function law_booking_remove_attendee_handler() {
	$is_ajax = law_events_guard_post(
		'law_booking_remove_attendee',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'title' => 'Attendee removed', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'attendee-removed',
		)
	);

	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	$email      = sanitize_email( wp_unslash( (string) ( $_POST['attendee_email'] ?? '' ) ) );
	$booking    = get_post( $booking_id );
	$user       = wp_get_current_user();

	$is_owner = $booking && (int) $booking->post_author === (int) $user->ID;
	// Self = the posted row's linked account, with the snapshot email as the
	// fallback: an attendee who changed their account email since booking must
	// still be able to remove themselves (adversarial review, 7 September 2026).
	$is_self = false;
	if ( $booking && '' !== $email ) {
		foreach ( law_event_meta( $booking->ID, '_law_attendee_rows' ) as $seat ) {
			if ( strtolower( (string) ( $seat['email'] ?? '' ) ) === strtolower( $email ) ) {
				$is_self = ( (int) ( $seat['user_id'] ?? 0 ) === (int) $user->ID )
					|| strtolower( $email ) === strtolower( (string) $user->user_email );
				break;
			}
		}
	}
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type || ( ! $is_owner && ! $is_self ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to change this booking.', 'status' => 403 ), 'booking-failed' );
	}

	$result = law_booking_remove_attendee( $booking_id, $email, (int) $user->ID, $is_self ? 'self' : 'owner' );
	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message() ), 'booking-failed' );
	}

	$cancelled = 'law-cancelled' === get_post_status( $booking_id );
	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => $cancelled ? 'Booking cancelled' : 'Attendee removed',
			'message'  => $cancelled
				? 'That was the last person on the booking, so the whole booking has been cancelled. Reloading the page…'
				: 'Their place has been freed. Reloading the page…',
			'redirect' => $cancelled
				? add_query_arg( 'law_notice', 'booking-cancelled', home_url( '/account/events/' ) )
				: add_query_arg( 'law_notice', 'attendee-removed', law_booking_manage_url( $booking_id ) ),
		),
		$cancelled ? 'booking-cancelled' : 'attendee-removed'
	);
}

/** Cancel a whole booking (owner only). */
function law_booking_cancel_handler() {
	$is_ajax = law_events_guard_post(
		'law_booking_cancel',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'title' => 'Booking cancelled', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'booking-cancelled',
		)
	);

	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	$booking    = get_post( $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type || (int) $booking->post_author !== get_current_user_id() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to cancel this booking.', 'status' => 403 ), 'booking-failed' );
	}

	$result = law_booking_cancel( $booking_id, get_current_user_id(), 'owner' );
	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message() ), 'booking-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Booking cancelled',
			'message'  => 'Everyone on the booking has been emailed. Reloading the page…',
			'redirect' => add_query_arg( 'law_notice', 'booking-cancelled', home_url( '/account/events/' ) ),
		),
		'booking-cancelled'
	);
}

/**
 * The host/committee Reject on the bookings list. Gate:
 * law_user_can_manage_event() on the booking's parent, never a posted event ID.
 */
function law_booking_reject_attendee_handler() {
	$is_ajax = law_events_guard_post(
		'law_booking_reject_attendee',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'title' => 'Attendee rejected', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'attendee-removed',
		)
	);

	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	$booking    = get_post( $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type
		|| ! law_user_can_manage_event( get_current_user_id(), (int) $booking->post_parent ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to manage this event\'s bookings.', 'status' => 403 ), 'booking-failed' );
	}

	$result = law_booking_remove_attendee(
		$booking_id,
		sanitize_email( wp_unslash( (string) ( $_POST['attendee_email'] ?? '' ) ) ),
		get_current_user_id(),
		'host_reject',
		array( 'reason' => trim( (string) wp_unslash( $_POST['law_reject_reason'] ?? '' ) ) )
	);
	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message() ), 'booking-failed' );
	}

	$cancelled = 'law-cancelled' === get_post_status( $booking_id );
	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => $cancelled ? 'Attendee rejected, booking cancelled' : 'Attendee rejected',
			'message'  => $cancelled
				? 'They were the booking\'s last attendee, so the booking is now cancelled. Reloading the page…'
				: 'They have been emailed. Reloading the page…',
			'redirect' => add_query_arg(
				array(
					'law_event_bookings' => (int) $booking->post_parent,
					'law_notice'         => $cancelled ? 'booking-cancelled' : 'attendee-removed',
				),
				home_url( '/account/events/' )
			),
		),
		$cancelled ? 'booking-cancelled' : 'attendee-removed'
	);
}

/** The manage-booking URL (?law_booking= on My events). */
function law_booking_manage_url( $booking_id ) {
	return add_query_arg( 'law_booking', (int) $booking_id, home_url( '/account/events/' ) );
}

/** The per-event bookings list URL (?law_event_bookings= on My events). */
function law_booking_list_url( $event_id ) {
	return add_query_arg( 'law_event_bookings', (int) $event_id, home_url( '/account/events/' ) );
}

/* Export (EVENTS_BOOKINGS.md §7.5) ___________________________________________ */

/**
 * A person's dietary or accessibility requirements from their profile, joined
 * by comma, "Other" replaced by its free text. Live from the profile, never a
 * snapshot (the settled model: the booking form does not collect them).
 */
function law_booking_profile_requirements( array $profile, $key ) {
	$values = array_map( 'strval', (array) ( $profile[ $key ] ?? array() ) );
	$other  = trim( (string) ( $profile[ $key . '_other' ] ?? '' ) );
	foreach ( $values as $i => $value ) {
		if ( 'Other' === $value && '' !== $other ) {
			$values[ $i ] = 'Other: ' . $other;
		}
	}
	return implode( ', ', array_filter( $values ) );
}

/**
 * One row set for the three export formats (CSV / Excel / PDF json): every
 * attendee of every ACTIVE booking, grouped by booking number.
 *
 * @return array{title: string, columns: string[], rows: array[]}
 */
function law_booking_export_rows( $event_id ) {
	$event_id = (int) $event_id;
	$start    = (string) law_event_meta( $event_id, '_law_start' );
	$when     = '' !== $start
		? date_i18n( 'l j F Y', strtotime( $start ) ) . ' ' . substr( $start, 11, 5 )
		: 'date to be confirmed';

	// -1 like the recount: a truncated export would silently lose attendees.
	$bookings = law_bookings_for_event( $event_id, 'publish', -1 );

	// One users + one usermeta query for the whole export instead of two per
	// attendee (performance review, 7 September 2026).
	$user_ids = array();
	foreach ( $bookings as $booking ) {
		foreach ( law_event_meta( $booking->ID, '_law_attendee_rows' ) as $row ) {
			if ( ! empty( $row['user_id'] ) ) {
				$user_ids[] = (int) $row['user_id'];
			}
		}
	}
	if ( $user_ids ) {
		cache_users( array_values( array_unique( $user_ids ) ) );
	}

	$rows = array();
	foreach ( $bookings as $booking ) {
		$number = (int) law_event_meta( $booking->ID, '_law_booking_number' );
		foreach ( law_event_meta( $booking->ID, '_law_attendee_rows' ) as $row ) {
			$user    = ! empty( $row['user_id'] ) ? get_user_by( 'id', (int) $row['user_id'] ) : null;
			$profile = $user ? law_profile_values( (int) $user->ID ) : array();

			// The linked account's name wins; the snapshot splits on the first space.
			if ( $user && ( $user->first_name || $user->last_name ) ) {
				$first = $user->first_name;
				$last  = $user->last_name;
			} else {
				$parts = preg_split( '/\s+/', trim( (string) ( $row['name'] ?? '' ) ), 2 );
				$first = $parts[0] ?? '';
				$last  = $parts[1] ?? '';
			}

			$rows[] = array(
				$number,
				$first,
				$last,
				(string) ( $row['email'] ?? '' ),
				(string) ( $row['organisation'] ?? '' ),
				(string) ( $row['job_title'] ?? '' ),
				law_booking_profile_requirements( $profile, 'accessibility' ),
				law_booking_profile_requirements( $profile, 'dietary' ),
			);
		}
	}

	return array(
		'title'   => sprintf( 'Attendees for %s, %s', get_the_title( $event_id ), $when ),
		'columns' => array( 'Booking ID', 'First name', 'Second name', 'Email', 'Organisation', 'Job title', 'Accessibility', 'Dietary' ),
		'rows'    => $rows,
	);
}

/**
 * The export endpoint: GET admin-post.php?action=law_booking_export with
 * event_id and format=csv|xlsx|json (json feeds the client-side pdfmake PDF).
 * Modelled on law_committee_export_handler(), but the gate is
 * law_user_can_manage_event() — hosts and co-owners export their own event's
 * attendees, not only the committee.
 */
add_action( 'admin_post_law_booking_export', 'law_booking_export_handler' );
function law_booking_export_handler() {
	nocache_headers();
	$format = sanitize_key( $_GET['format'] ?? 'csv' );

	// check_admin_referer() would die with an HTML page the PDF fetch cannot
	// parse, so verify manually first and give it a JSON 403 instead.
	if ( 'json' === $format && ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'law_booking_export' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_booking_export' );

	$event_id = absint( $_GET['event_id'] ?? 0 );
	$event    = get_post( $event_id );
	if ( ! $event || LAW_EVENT_CPT !== $event->post_type
		|| ! law_user_can_manage_event( get_current_user_id(), $event_id ) ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Sorry, this export is for the event\'s hosts and the committee.' ), 403 );
		}
		wp_die( 'Sorry, this export is for the event\'s hosts and the committee.' );
	}

	$data     = law_booking_export_rows( $event_id );
	$basename = sanitize_file_name(
		'bookings-' . ( (string) law_event_meta( $event_id, '_law_reference' ) ?: (string) $event_id ) . '-' . gmdate( 'Ymd-His' )
	);

	if ( 'xlsx' === $format ) {
		law_events_send_xlsx( $data['columns'], $data['rows'], $basename . '.xlsx', $data['title'] );
	}
	if ( 'json' === $format ) {
		wp_send_json_success(
			array(
				'title'    => $data['title'],
				'filename' => $basename . '.pdf',
				'columns'  => $data['columns'],
				'rows'     => $data['rows'],
			)
		);
	}
	law_events_send_csv( $data['columns'], $data['rows'], $basename . '.csv', $data['title'] );
}

/* wp-admin backstops _________________________________________________________ */

/**
 * An administrator trashing, untrashing or deleting a booking from wp-admin
 * never touches the engine, so the seat counter would drift. These two hooks
 * keep _law_tickets_sold honest for every status flip and hard delete. The
 * engine's own mutations also pass through here (recount is idempotent, so a
 * doubled recount is harmless and unlogged).
 */
add_action(
	'transition_post_status',
	function ( $new_status, $old_status, $post ) {
		if ( ! $post instanceof WP_Post || LAW_BOOKING_CPT !== $post->post_type || $new_status === $old_status || ! $post->post_parent ) {
			return;
		}
		$engine = ! empty( $GLOBALS['law_booking_transitioning'] ) || 'new' === $old_status;
		law_event_recount_attendees( (int) $post->post_parent, $engine ? '' : 'wp-admin' );
	},
	10,
	3
);

add_action(
	'deleted_post',
	function ( $post_id, $post ) {
		if ( $post instanceof WP_Post && LAW_BOOKING_CPT === $post->post_type && $post->post_parent ) {
			law_event_recount_attendees( (int) $post->post_parent, 'deleted' );
		}
	},
	10,
	2
);

/**
 * An EVENT trashed or hard-deleted outside the workflow (wp-admin bulk
 * actions, or a host account deleted with "delete all content" — law_event
 * supports 'author', so core deletes their events) would otherwise orphan
 * its active bookings silently: attendees keep phantom places, nobody is
 * emailed, and the "Your bookings" card just vanishes (adversarial review,
 * 7 September 2026). Sweep-cancel the bookings first, exactly as the
 * committee's cancel does. wp_trash_post fires before the status flips and
 * before_delete_post fires while the event still exists, so the sweep's
 * emails and log lines can still resolve the event.
 */
function law_bookings_sweep_before_event_removal( $post_id ) {
	if ( LAW_EVENT_CPT === get_post_type( $post_id ) ) {
		law_bookings_cancel_all_for_event( (int) $post_id, get_current_user_id(), 'event_removed' );
	}
}
add_action( 'wp_trash_post', 'law_bookings_sweep_before_event_removal' );
add_action( 'before_delete_post', 'law_bookings_sweep_before_event_removal' );
