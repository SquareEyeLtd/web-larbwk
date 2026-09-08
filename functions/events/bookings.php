<?php
/**
 * The bookings engine (WAITLIST.md Part A; EVENTS_BOOKINGS.md is the original
 * contract): free attendee bookings on Confirmed events.
 *
 * ONE BOOKING PER ATTENDEE. A booking is a law_booking post — event =
 * post_parent, THE ATTENDEE = post_author, status publish (active) or
 * law-cancelled — with its own booking number and a snapshot of the details
 * entered for that person. When a booker brings colleagues, each colleague
 * gets their own booking carrying _law_booked_by = the booker, so every
 * attendee has a unique reference and the lists are flat, one row per person.
 * The booker's "party" on an event is derived, never stored: the active
 * bookings there whose author or _law_booked_by is them.
 *
 * All mutations run through these functions; wp-admin is read-only inspection
 * (create_posts is do_not_allow, and the status guard in workflow.php covers
 * the CPT).
 *
 * Every action and refusal logs to the parent EVENT's activity log with
 * context source => 'bookings' and booking => <id>; there is no separate
 * booking log.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * How many colleagues one booker may hold bookings for on a single event.
 * Their own booking is not counted, so a party is at most this many plus one,
 * and a booker who cancelled their own place still holds up to this many
 * colleagues.
 */
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

	// One booking is one place, so the count IS the query — no meta reads,
	// and no cap that a truncated read could use to reopen a sold-out event.
	global $wpdb;
	$sold = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status = 'publish'",
			LAW_BOOKING_CPT,
			$event_id
		)
	);

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
 * A user's own bookings, any status ("My bookings" filters to active itself).
 * One booking per attendee, so this is a plain author query.
 *
 * @return int[] law_booking post IDs.
 */
function law_user_booking_ids( $user_id, $limit = 200 ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => LAW_BOOKING_CPT,
				'post_status'    => array_keys( law_booking_statuses() ),
				'author'         => $user_id,
				'fields'         => 'ids',
				'posts_per_page' => (int) $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		)
	);
}

/**
 * Bookings this user made FOR SOMEBODY ELSE (colleagues they brought). Their
 * own booking is excluded, so the two lists compose without de-duping.
 *
 * @return int[] law_booking post IDs.
 */
function law_user_bookings_made_ids( $user_id, $limit = 200 ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}
	$ids = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => array_keys( law_booking_statuses() ),
			'author__not_in' => array( $user_id ),
			'meta_key'       => '_law_booked_by',
			'meta_value'     => $user_id,
			'fields'         => 'ids',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);
	return array_map( 'intval', $ids );
}

/**
 * Events a user holds a place on via an ACTIVE booking of their own. The
 * clash guard's source, and the "You're booked" state's. A waitlist entry
 * holds no place, so it is deliberately not here: someone waiting on one
 * event must still be able to book an overlapping one.
 *
 * @return int[] law_event post IDs.
 */
function law_user_booked_event_ids( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}
	$map = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => 'publish',
			'author'         => $user_id,
			'fields'         => 'id=>parent',
			'posts_per_page' => 200,
		)
	);
	return array_values( array_unique( array_filter( array_map( 'intval', (array) $map ) ) ) );
}

/**
 * A booker's party on one event: their own booking plus the bookings they
 * made for colleagues. Derived, never stored — a stored group would diverge
 * the moment somebody is added or cancels.
 *
 * @param int          $event_id law_event post ID.
 * @param int          $user_id  The booker.
 * @param string|array $status   Post status(es); default active only.
 * @return WP_Post[] Ordered by booking ID.
 */
function law_booking_party( $event_id, $user_id, $status = 'publish' ) {
	$event_id = (int) $event_id;
	$user_id  = (int) $user_id;
	if ( $event_id < 1 || $user_id < 1 ) {
		return array();
	}
	$base = array(
		'post_type'      => LAW_BOOKING_CPT,
		'post_parent'    => $event_id,
		'post_status'    => $status,
		'posts_per_page' => 50,
		'orderby'        => 'ID',
		'order'          => 'ASC',
	);
	$own    = get_posts( array_merge( $base, array( 'author' => $user_id ) ) );
	$made   = get_posts( array_merge( $base, array( 'meta_key' => '_law_booked_by', 'meta_value' => $user_id ) ) );
	$party  = array();
	foreach ( array_merge( $own, $made ) as $booking ) {
		$party[ (int) $booking->ID ] = $booking;
	}
	ksort( $party );
	return array_values( $party );
}

/** Active colleague bookings this booker holds on the event (the cap count). */
function law_booking_colleague_count( $event_id, $booker_id ) {
	$booker_id = (int) $booker_id;
	$count     = 0;
	foreach ( law_booking_party( $event_id, $booker_id ) as $booking ) {
		if ( (int) $booking->post_author !== $booker_id ) {
			$count++;
		}
	}
	return $count;
}

/** Did this attendee book themselves (rather than being brought by someone)? */
function law_booking_is_self_booked( $booking ) {
	$booking = get_post( $booking );
	if ( ! $booking ) {
		return true;
	}
	return (int) law_event_meta( $booking->ID, '_law_booked_by' ) === (int) $booking->post_author;
}

/**
 * The "Invited by {name}" tag text for a booking, or '' when the person
 * booked themselves. A booker whose account has since gone still gets a
 * truthful label rather than a blank.
 */
function law_booking_invited_by_label( $booking ) {
	$booking = get_post( $booking );
	if ( ! $booking || law_booking_is_self_booked( $booking ) ) {
		return '';
	}
	$user = get_user_by( 'id', (int) law_event_meta( $booking->ID, '_law_booked_by' ) );
	return $user ? $user->display_name : 'a deleted account';
}

/**
 * The best deliverable address for a booking's attendee: their account's, or
 * the snapshot taken at booking time, or nothing.
 *
 * One helper because there were five copies of this and they had already
 * drifted — one had lost its `is_email()` on the account address, so a person
 * with a malformed account email got no mail at all, where the others fell
 * through to the snapshot and still reached them.
 *
 * @return string '' when there is no usable address.
 */
function law_booking_attendee_email( $booking ) {
	$booking = get_post( $booking );
	if ( ! $booking ) {
		return '';
	}
	$user = get_user_by( 'id', (int) $booking->post_author );
	if ( $user && is_email( $user->user_email ) ) {
		return $user->user_email;
	}
	$snapshot = (string) law_event_meta( $booking->ID, '_law_attendee_email' );
	return is_email( $snapshot ) ? $snapshot : '';
}

/**
 * The single write path for a booking's attendee snapshot and its booker.
 *
 * @param int   $person    user_id / name / email / organisation / job_title.
 * @param int   $booked_by The booker (the attendee's own ID when self-booked).
 * @param bool  $press     Committee-issued press pass.
 */
function law_booking_write_attendee( $booking_id, array $person, $booked_by, $press = false ) {
	$booking_id = (int) $booking_id;
	law_event_update_meta( $booking_id, '_law_booked_by', (int) $booked_by );
	law_event_update_meta( $booking_id, '_law_attendee_name', (string) ( $person['name'] ?? '' ) );
	law_event_update_meta( $booking_id, '_law_attendee_email', (string) ( $person['email'] ?? '' ) );
	law_event_update_meta( $booking_id, '_law_attendee_organisation', (string) ( $person['organisation'] ?? '' ) );
	law_event_update_meta( $booking_id, '_law_attendee_job_title', (string) ( $person['job_title'] ?? '' ) );
	if ( $press ) {
		law_event_update_meta( $booking_id, '_law_is_press', 1 );
	} else {
		delete_post_meta( $booking_id, '_law_is_press' );
	}
}

/**
 * A booking's attendee snapshot as an array, the shape the guards and the
 * export builders read.
 *
 * @return array{user_id:int,name:string,email:string,organisation:string,job_title:string}
 */
function law_booking_attendee( $booking ) {
	$booking = get_post( $booking );
	if ( ! $booking ) {
		return array( 'user_id' => 0, 'name' => '', 'email' => '', 'organisation' => '', 'job_title' => '' );
	}
	return array(
		'user_id'      => (int) $booking->post_author,
		'name'         => (string) law_event_meta( $booking->ID, '_law_attendee_name' ),
		'email'        => (string) law_event_meta( $booking->ID, '_law_attendee_email' ),
		'organisation' => (string) law_event_meta( $booking->ID, '_law_attendee_organisation' ),
		'job_title'    => (string) law_event_meta( $booking->ID, '_law_attendee_job_title' ),
	);
}

/** The next booking number (Booking #N), starting at 1. */
function law_bookings_next_number() {
	return law_events_bump_counter( 'law_bookings_counter' );
}

/**
 * Claim N consecutive booking numbers in one atomic step, so a party booked
 * together reads as a block even while another event is booking.
 *
 * @return int[] The numbers, in order.
 */
function law_bookings_next_numbers( $count ) {
	$count = max( 1, (int) $count );
	$last  = law_events_bump_counter( 'law_bookings_counter', $count );
	return range( $last - $count + 1, $last );
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
 * One active booking per person per event — checked within the submitted set
 * itself and against every existing booking on the event, by account and by
 * email (an attendee who has since changed their account email must still be
 * recognised).
 *
 * @param int      $event_id law_event post ID.
 * @param array[]  $people   Each: user_id (0 when no account yet), email, name.
 * @param string[] $statuses Booking statuses that count as holding a place;
 *                           the waitlist adds law-waitlisted, promotion checks
 *                           active bookings only.
 * @param array|null $taken  A prebuilt law_booking_taken_index(), for a caller
 *                           checking many people against the same event.
 * @return true|WP_Error
 */
function law_booking_guard_duplicates( $event_id, array $people, array $statuses = array( 'publish' ), ?array $taken = null ) {
	$emails = array();
	$users  = array();
	$rows   = array();
	foreach ( $people as $i => $person ) {
		$email = strtolower( trim( (string) ( $person['email'] ?? '' ) ) );
		$user  = (int) ( $person['user_id'] ?? 0 );
		$name  = (string) ( $person['name'] ?? '' ) ?: $email;
		$rows[ $name ] = $i > 0 ? $i - 1 : null; // Row 0 is the booker themselves.
		if ( '' !== $email && isset( $emails[ $email ] ) ) {
			return new WP_Error( 'law_booking_duplicate', sprintf( '%s is listed more than once.', $name ), array( 'row' => $i > 0 ? $i - 1 : null, 'field' => 'email' ) );
		}
		if ( $user && isset( $users[ $user ] ) ) {
			return new WP_Error( 'law_booking_duplicate', sprintf( '%s is listed more than once.', $name ), array( 'row' => $i > 0 ? $i - 1 : null, 'field' => 'email' ) );
		}
		if ( '' !== $email ) {
			$emails[ $email ] = $name;
		}
		if ( $user ) {
			$users[ $user ] = $name;
		}
	}

	$taken   = null === $taken ? law_booking_taken_index( $event_id, $statuses ) : $taken;
	$current = get_current_user_id();

	// $rows maps a person back to the repeater row they came from, so a
	// refusal can be marked against the offending field rather than only
	// stated at the top of the form. Row 0 is the booker, who has no row.
	foreach ( $users as $user_id => $name ) {
		if ( isset( $taken['users'][ $user_id ] ) ) {
			return law_booking_duplicate_error( $name, $user_id === $current, ! empty( $taken['waiting'][ $user_id ] ), $rows[ $name ] ?? null );
		}
	}
	foreach ( $emails as $email => $name ) {
		if ( isset( $taken['emails'][ $email ] ) ) {
			$owner = (int) $taken['emails'][ $email ];
			return law_booking_duplicate_error( $name, $owner === $current, ! empty( $taken['waiting'][ $owner ] ), $rows[ $name ] ?? null );
		}
	}
	return true;
}

/**
 * Who already holds a place (or a queue slot) on an event, as lookup maps, so
 * a caller checking many people — the waitlist walking its queue — builds this
 * once rather than reloading every booking on the event per candidate.
 *
 * @return array{users:array<int,true>,emails:array<string,int>,waiting:array<int,bool>}
 */
function law_booking_taken_index( $event_id, array $statuses = array( 'publish' ) ) {
	$index    = array( 'users' => array(), 'emails' => array(), 'waiting' => array() );
	$bookings = law_bookings_for_event( $event_id, $statuses, -1 );
	if ( ! $bookings ) {
		return $index;
	}
	cache_users( array_map( fn( $b ) => (int) $b->post_author, $bookings ) );

	foreach ( $bookings as $booking ) {
		$author  = (int) $booking->post_author;
		$waiting = 'law-waitlisted' === $booking->post_status;
		if ( $author ) {
			$index['users'][ $author ]   = true;
			$index['waiting'][ $author ] = $waiting;
		}
		// Both the snapshot taken at booking time and the account's current
		// address: someone who changed their email must still be recognised.
		$emails = array( strtolower( (string) law_event_meta( $booking->ID, '_law_attendee_email' ) ) );
		$user   = get_user_by( 'id', $author );
		if ( $user ) {
			$emails[] = strtolower( $user->user_email );
		}
		foreach ( array_filter( $emails ) as $email ) {
			$index['emails'][ $email ] = $author;
		}
	}
	return $index;
}

/** The refusal the duplicate guard returns, worded for who is being refused. */
function law_booking_duplicate_error( $name, $is_self, $waiting, $row = null ) {
	// The row lets the form mark the offending email in place; the booker's
	// own clash has no row to mark, so it stays a message.
	$data = null === $row ? array() : array( 'row' => (int) $row, 'field' => 'email' );
	if ( $is_self ) {
		return new WP_Error(
			'law_booking_duplicate',
			$waiting
				? 'You are already on the waitlist for this event. You can manage it from My bookings.'
				: 'You already have a booking for this event. You can manage it from My bookings.',
			$data
		);
	}
	return new WP_Error(
		'law_booking_duplicate',
		sprintf(
			$waiting ? '%s is already on the waitlist for this event.' : '%s already has a place at this event.',
			$name
		),
		$data
	);
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
 * The booking statuses that count as "this person already has a place here"
 * for the one-booking-per-person rule. A waitlist entry counts: you are either
 * booked or waiting, never both.
 *
 * @return string[]
 */
function law_booking_holding_statuses() {
	return array( 'publish', 'law-waitlisted' );
}

/**
 * Are there places for this submission? Active bookings need capacity;
 * waitlist entries need the opposite (you only join a waitlist when the event
 * is full, otherwise you would just register).
 *
 * @param int    $seats  People in the submission.
 * @param string $status The status the bookings would be created with.
 * @return true|WP_Error
 */
function law_booking_guard_seats( $event_id, $seats, $status = 'publish' ) {
	if ( 'law-waitlisted' === $status ) {
		$remaining = law_event_tickets_remaining( $event_id );
		if ( null === $remaining ) {
			return new WP_Error( 'law_booking_not_open', 'Booking for this event has not opened yet.' );
		}
		if ( $remaining > 0 ) {
			return new WP_Error(
				'law_waitlist_places_available',
				'Places are available at this event, so you can register straight away.'
			);
		}
		return true;
	}
	return law_booking_guard_capacity( $event_id, $seats );
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
 * @return array|WP_Error Clean rows (user_id 0 — resolved later).
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
				sprintf( 'You can bring at most %d colleagues to an event.', law_booking_max_additional() )
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
 * Resolve one attendee row to a user account: link an existing account by
 * email (granting the attendee role if missing), or create a new attendee
 * account. Sends NOTHING — the booking does not exist yet when this runs, so
 * its number is not known; law_booking_notify_attendee() does the email once
 * the booking is written.
 *
 * @param array $row      Clean attendee row.
 * @param int   $event_id Parent event (for the log).
 * @param int   $actor    Acting user for the log.
 * @return array|WP_Error [ 'user_id' => int, 'created' => bool ].
 */
function law_booking_resolve_attendee_user( array $row, $event_id, $actor ) {
	$email = (string) $row['email'];
	$user  = get_user_by( 'email', $email );

	if ( $user ) {
		law_event_log(
			$event_id,
			sprintf( 'Booking attendee linked to existing account: %s (%s).', $user->display_name, $email ),
			array( 'action' => 'booking_attendee_linked', 'user' => (int) $user->ID, 'source' => 'bookings' ),
			array( 'user_id' => (int) $actor )
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
			array( 'action' => 'booking_attendee_error', 'source' => 'bookings' ),
			array( 'user_id' => (int) $actor )
		);
		return new WP_Error(
			'law_booking_account_failed',
			sprintf( 'An account could not be created for %s: %s', $email, $user_id->get_error_message() ),
			array( 'field' => 'email' )
		);
	}

	law_event_log(
		$event_id,
		sprintf( 'Booking attendee account created: %s (%s).', $row['name'], $email ),
		array( 'action' => 'booking_attendee_account_created', 'user' => (int) $user_id, 'source' => 'bookings' ),
		array( 'user_id' => (int) $actor )
	);
	return array( 'user_id' => (int) $user_id, 'created' => true );
}

/**
 * Grant the attendee role to a user who lacks it. Any signed-in person may
 * book: the role is granted, never required (settled).
 */
function law_booking_grant_attendee_role( $user_id, $event_id, $actor_id ) {
	$user = get_user_by( 'id', (int) $user_id );
	if ( ! $user || in_array( 'attendee', (array) $user->roles, true ) ) {
		return;
	}
	$user->add_role( 'attendee' );
	law_event_log(
		$event_id,
		sprintf( 'Attendee role granted to %s (%s) on booking.', $user->display_name, $user->user_email ),
		array( 'action' => 'booking_role_granted', 'user' => (int) $user->ID, 'source' => 'bookings' ),
		array( 'user_id' => (int) $actor_id )
	);
}

/**
 * Tell a colleague about the booking someone made for them: the invite with a
 * set-password link for a brand-new account, or the "you have a place" email
 * for an existing one. Both carry THAT person's own booking number.
 *
 * @param int   $booking_id The colleague's own booking.
 * @param bool  $created    Their account was created for this booking.
 * @param array $opts       invited / added (slugs) and ics (bool) — the
 *                          waitlist sends the same shape with its own wording
 *                          and no calendar invite, since it has no place yet.
 */
function law_booking_notify_attendee( $booking_id, $created, array $opts = array() ) {
	$opts = array_merge(
		array( 'invited' => 'user_attendee_invited', 'added' => 'user_attendee_added', 'ics' => true ),
		$opts
	);
	$booking = get_post( $booking_id );
	if ( ! $booking ) {
		return;
	}
	$email = law_booking_attendee_email( $booking );
	if ( '' === $email ) {
		return;
	}

	$event_id = (int) $booking->post_parent;
	$person   = law_booking_attendee( $booking );
	$user     = get_user_by( 'id', (int) $booking->post_author );
	$extra    = array( 'attendee_name' => $person['name'] );
	if ( $created && $user ) {
		$extra['username']          = $user->user_login;
		$extra['set_password_link'] = law_events_password_setup_link( $user, $event_id, 'booking_attendee_error' );
	}

	$slug = $created ? $opts['invited'] : $opts['added'];
	$args = array(
		'to'           => array( $email ),
		'placeholders' => array_merge( law_booking_email_placeholders( $booking_id ), $extra ),
	);
	if ( $opts['ics'] ) {
		law_booking_send_with_ics( $slug, $event_id, $args );
		return;
	}
	law_events_send( $slug, $event_id, $args );
}

/**
 * The single status-write path for a booking. The wp_insert_post_data guard
 * in workflow.php reverts any status change on a law_booking that is not
 * flagged here, so every engine transition goes through this. The previous
 * flag value is restored rather than cleared, so a nested call (the waitlist
 * promoting inside a sweep, say) cannot strand its caller.
 *
 * @return true|WP_Error
 */
function law_booking_set_status( $booking_id, $status ) {
	$previous                             = $GLOBALS['law_booking_transitioning'] ?? false;
	$GLOBALS['law_booking_transitioning'] = true;
	try {
		$updated = wp_update_post( array( 'ID' => (int) $booking_id, 'post_status' => $status ), true );
	} finally {
		// finally, because a fatal in somebody else's save_post hook would
		// otherwise leave the status guard disarmed for the rest of the request.
		$GLOBALS['law_booking_transitioning'] = $previous;
	}
	return is_wp_error( $updated ) ? $updated : true;
}

/* Mutations __________________________________________________________________ */

/**
 * Create the bookings for one submission: the booker's own, plus one for each
 * colleague they brought. Every attendee gets their own law_booking post and
 * their own booking number; the whole submission succeeds or none of it does.
 *
 * @param int   $event_id        law_event post ID.
 * @param int   $booker_id       The person submitting (becomes the author of
 *                               booking [0] and the _law_booked_by of the rest).
 * @param array $additional_rows Raw repeater rows (name/email/organisation/job_title).
 * @param array $args            Optional, for the on-behalf path
 *                               (law_booking_register_by_manager()):
 *                               actor (int, the user acting — defaults to the
 *                               booker), on_behalf (bool), new_account (bool,
 *                               the person's account was just created so the
 *                               confirmation carries a set-password link),
 *                               press (bool), owner_row (organisation / job
 *                               title fallbacks when the profile lacks them),
 *                               status (post status for the new bookings —
 *                               the waitlist passes law-waitlisted).
 * @return int[]|WP_Error Booking post IDs, the booker's own first.
 */
function law_booking_create( $event_id, $booker_id, array $additional_rows, array $args = array() ) {
	$event_id  = (int) $event_id;
	$booker_id = (int) $booker_id;
	$args      = array_merge(
		array(
			'actor'       => $booker_id,
			'on_behalf'   => false,
			'new_account' => false,
			'press'       => false,
			'owner_row'   => array(),
			'status'      => 'publish',
		),
		$args
	);
	$actor_id = (int) $args['actor'] ?: $booker_id;

	$open = law_booking_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return law_booking_log_refusal( $event_id, 0, $open, $actor_id );
	}
	$booker = get_user_by( 'id', $booker_id );
	if ( ! $booker ) {
		return new WP_Error( 'law_booking_no_user', 'You need to be signed in to book.' );
	}

	$additional = law_booking_clean_additional_rows( $additional_rows );
	if ( is_wp_error( $additional ) ) {
		return $additional;
	}

	$profile     = law_profile_values( $booker_id );
	$fallback    = (array) $args['owner_row'];
	$booker_row  = array(
		'user_id'      => $booker_id,
		'name'         => trim( $booker->first_name . ' ' . $booker->last_name )
			?: (string) ( $fallback['name'] ?? '' )
			?: $booker->display_name,
		'email'        => $booker->user_email,
		'organisation' => (string) ( $profile['organisation'] ?? '' ) ?: (string) ( $fallback['organisation'] ?? '' ),
		'job_title'    => (string) ( $profile['job_title'] ?? '' ) ?: (string) ( $fallback['job_title'] ?? '' ),
	);

	// Resolve the colleagues who already have accounts, cheaply, so the
	// pre-lock guards can see their user IDs.
	foreach ( $additional as $i => $row ) {
		$known                       = get_user_by( 'email', $row['email'] );
		$additional[ $i ]['user_id'] = $known ? (int) $known->ID : 0;
	}
	$people = array_merge( array( $booker_row ), $additional );

	// Fast-fail on the common refusals BEFORE any account is created, so a
	// refused submission never leaves an orphan account behind. Everything
	// here runs again inside the lock, where it is authoritative.
	$dup = law_booking_guard_duplicates( $event_id, $people, law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return law_booking_log_refusal( $event_id, 0, $dup, $actor_id );
	}
	$cap = law_booking_guard_seats( $event_id, count( $people ), $args['status'] );
	if ( is_wp_error( $cap ) ) {
		return law_booking_log_refusal( $event_id, 0, $cap, $actor_id );
	}

	// Accounts before the lock: post_author needs a real user, and password
	// hashing is far too slow to hold the event lock for. A failure refuses
	// the whole submission (there is no seat without an account any more) and
	// takes back the accounts this request created.
	$created_ids = array();
	foreach ( $additional as $i => $row ) {
		if ( $row['user_id'] ) {
			continue;
		}
		$resolved = law_booking_resolve_attendee_user( $row, $event_id, $actor_id );
		if ( is_wp_error( $resolved ) ) {
			law_booking_delete_created_users( $created_ids, $event_id, $actor_id );
			$data          = (array) $resolved->get_error_data();
			$data['row']   = $i;
			$data['field'] = 'email';
			return new WP_Error( $resolved->get_error_code(), $resolved->get_error_message(), $data );
		}
		$additional[ $i ]['user_id'] = (int) $resolved['user_id'];
		$additional[ $i ]['created'] = (bool) $resolved['created'];
		if ( $resolved['created'] ) {
			$created_ids[ (int) $resolved['user_id'] ] = true;
		}
	}
	$people = array_merge( array( $booker_row ), $additional );

	// EVERY shared-state guard runs inside the event lock (security review,
	// 7 September 2026): two near-simultaneous requests must serialise before
	// the duplicate / colleague-cap / clash / capacity reads, or both pass the
	// pre-checks and the caps are defeated.
	if ( ! law_booking_lock( $event_id ) ) {
		law_booking_delete_created_users( $created_ids, $event_id, $actor_id );
		return new WP_Error( 'law_booking_busy', 'The event is busy taking another booking. Please try again in a moment.' );
	}
	$refuse = function ( WP_Error $error ) use ( $event_id, $actor_id, $created_ids ) {
		law_booking_unlock( $event_id );
		law_booking_delete_created_users( $created_ids, $event_id, $actor_id );
		return law_booking_log_refusal( $event_id, 0, $error, $actor_id );
	};

	law_event_recount_attendees( $event_id );

	// Re-read the event itself, not just the seats: a committee cancel can
	// complete while this request waits for the lock, and its sweep has
	// already passed, so a booking inserted now would sit on a dead event with
	// nothing left to correct it.
	$open = law_booking_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $refuse( $open );
	}

	$dup = law_booking_guard_duplicates( $event_id, $people, law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return $refuse( $dup );
	}

	// The colleague cap counts the bookings this booker already holds for
	// other people on this event, so it survives their own booking being
	// cancelled and cannot be walked around by submitting twice.
	$held = law_booking_colleague_count( $event_id, $booker_id );
	if ( $held + count( $additional ) > law_booking_max_additional() ) {
		return $refuse( new WP_Error(
			'law_booking_too_many',
			sprintf( 'You can bring at most %d colleagues to an event.', law_booking_max_additional() )
		) );
	}

	foreach ( $people as $i => $person ) {
		$clash = law_booking_guard_clash(
			(int) $person['user_id'],
			$event_id,
			( 0 === $i && ! $args['on_behalf'] ) ? '' : $person['name']
		);
		if ( is_wp_error( $clash ) ) {
			return $refuse( $clash );
		}
	}

	$cap = law_booking_guard_seats( $event_id, count( $people ), $args['status'] );
	if ( is_wp_error( $cap ) ) {
		return $refuse( $cap );
	}

	$numbers  = law_bookings_next_numbers( count( $people ) );
	$position = 'law-waitlisted' === $args['status'] ? law_waitlist_next_position( $event_id ) : 0;
	$ids      = array();
	foreach ( $people as $i => $person ) {
		$booking_id = wp_insert_post(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_status' => $args['status'],
				'post_title'  => 'Booking #' . $numbers[ $i ],
				'post_parent' => $event_id,
				'post_author' => (int) $person['user_id'],
			),
			true
		);
		if ( is_wp_error( $booking_id ) ) {
			// Nothing has been emailed yet, so the whole submission can be
			// taken back cleanly rather than leaving half a party booked.
			foreach ( $ids as $created_booking ) {
				wp_delete_post( $created_booking, true );
			}
			law_booking_unlock( $event_id );
			law_booking_delete_created_users( $created_ids, $event_id, $actor_id );
			law_event_log(
				$event_id,
				sprintf( 'Booking creation failed and was rolled back: %s', $booking_id->get_error_message() ),
				array( 'action' => 'booking_create_rolled_back', 'source' => 'bookings' ),
				array( 'user_id' => $actor_id )
			);
			return $booking_id;
		}
		$booking_id = (int) $booking_id;
		law_event_update_meta( $booking_id, '_law_booking_number', $numbers[ $i ] );
		// _law_booked_by is always the submitting user: on a colleague's
		// booking that is the person who brought them, and on the booker's own
		// (or an on-behalf registration, where the booker IS the registered
		// person) it equals the author, which is what "self-booked" means.
		law_booking_write_attendee( $booking_id, $person, $booker_id, 0 === $i && ! empty( $args['press'] ) );
		if ( $position ) {
			law_event_update_meta( $booking_id, '_law_waitlist_position', $position + $i );
			law_event_update_meta( $booking_id, '_law_waitlist_joined', current_time( 'mysql' ) );
		}
		$ids[] = $booking_id;
	}

	$sold = law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );

	foreach ( $people as $person ) {
		law_booking_grant_attendee_role( (int) $person['user_id'], $event_id, $actor_id );
	}

	$actor = $actor_id !== $booker_id ? get_user_by( 'id', $actor_id ) : null;
	foreach ( $ids as $i => $booking_id ) {
		// A colleague who already had an account: the account-creation branch
		// logs its own line, so this is the other half of the audit trail.
		if ( $i > 0 && empty( $people[ $i ]['created'] ) ) {
			$linked = get_user_by( 'id', (int) $people[ $i ]['user_id'] );
			law_event_log(
				$event_id,
				sprintf( 'Booking attendee linked to existing account: %s (%s).', $linked ? $linked->display_name : $people[ $i ]['name'], $people[ $i ]['email'] ),
				array( 'action' => 'booking_attendee_linked', 'booking' => $booking_id, 'user' => (int) $people[ $i ]['user_id'], 'source' => 'bookings' ),
				array( 'user_id' => $actor_id )
			);
		}
		law_event_log(
			$event_id,
			$args['on_behalf']
				? sprintf(
					'Booking #%d created by %s on behalf of %s (%s)%s.',
					$numbers[ $i ],
					$actor ? $actor->display_name : 'the organisers',
					$people[ $i ]['name'],
					$people[ $i ]['email'],
					! empty( $args['press'] ) ? ', press pass' : ''
				)
				: ( 0 === $i
					? sprintf( 'Booking #%d created by %s.', $numbers[ $i ], $booker->display_name )
					: sprintf( 'Booking #%d created by %s for %s (%s).', $numbers[ $i ], $booker->display_name, $people[ $i ]['name'], $people[ $i ]['email'] ) ),
			array(
				'action'    => 'booking_created',
				'booking'   => $booking_id,
				'submission' => $ids[0],
				'sold'      => $sold,
				'on_behalf' => (int) (bool) $args['on_behalf'],
				'press'     => (int) ( 0 === $i && ! empty( $args['press'] ) ),
				'source'    => 'bookings',
			),
			array( 'user_id' => $actor_id )
		);
	}

	if ( 'publish' === $args['status'] ) {
		law_booking_send_submission_emails( $ids, $people, $args, $booker, $actor, $event_id );
		law_booking_maybe_capacity_warning( $event_id );
	} elseif ( 'law-waitlisted' === $args['status'] && function_exists( 'law_waitlist_send_join_emails' ) ) {
		law_waitlist_send_join_emails( $ids, $people, $booker, $event_id );
	}

	return $ids;
}

/**
 * Delete accounts this request created after the submission was refused. The
 * people were never emailed, so nothing points at them.
 *
 * @param array $created_ids user_id => true.
 */
function law_booking_delete_created_users( array $created_ids, $event_id, $actor_id ) {
	if ( ! $created_ids ) {
		return;
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array_keys( $created_ids ) as $user_id ) {
		$user = get_user_by( 'id', (int) $user_id );
		wp_delete_user( (int) $user_id );
		law_event_log(
			$event_id,
			sprintf( 'Attendee account for %s removed again: the booking was refused.', $user ? $user->user_email : '#' . (int) $user_id ),
			array( 'action' => 'booking_attendee_account_rolled_back', 'source' => 'bookings' ),
			array( 'user_id' => (int) $actor_id )
		);
	}
}

/**
 * The emails one submission sends: the booker's confirmation listing the whole
 * party with their numbers, each colleague's own confirmation, and ONE host
 * and committee copy for the submission.
 */
function law_booking_send_submission_emails( array $ids, array $people, array $args, $booker, $actor, $event_id ) {
	$placeholders = law_booking_email_placeholders( $ids[0], $ids );

	if ( $args['on_behalf'] ) {
		// Registered by a host or the committee: the confirmation says so, and
		// a brand-new account gets its set-password link in the same email
		// (one email, not an invite plus a confirmation).
		$extra = array(
			'attendee_name' => $people[0]['name'],
			'registered_by' => $actor ? $actor->display_name : 'the organisers',
		);
		if ( ! empty( $args['new_account'] ) ) {
			$extra['username']          = $booker->user_login;
			$extra['set_password_link'] = law_events_password_setup_link( $booker, $event_id, 'booking_attendee_error' );
		}
		law_booking_send_with_ics(
			! empty( $args['new_account'] ) ? 'user_booking_registered_invited' : 'user_booking_registered',
			$event_id,
			array( 'to' => array( $booker->user_email ), 'placeholders' => array_merge( $placeholders, $extra ) )
		);
	} else {
		law_booking_send_with_ics(
			'user_booking_confirmed',
			$event_id,
			array(
				'to'           => array( $booker->user_email ),
				'placeholders' => array_merge( $placeholders, array( 'attendee_name' => $people[0]['name'] ) ),
			)
		);
		foreach ( $ids as $i => $booking_id ) {
			if ( 0 === $i ) {
				continue;
			}
			law_booking_notify_attendee( $booking_id, ! empty( $people[ $i ]['created'] ) );
		}
	}

	law_events_send( 'host_booking_received', $event_id, array( 'placeholders' => $placeholders ) );
	// Committee copy goes to the event's assignee when one is set (settled),
	// falling back to the registry's committee audience.
	$assignee = get_user_by( 'id', (int) law_event_meta( $event_id, '_law_assignee' ) );
	$extra    = array( 'placeholders' => $placeholders );
	if ( $assignee && is_email( $assignee->user_email ) ) {
		$extra['to'] = array( $assignee->user_email );
	}
	law_events_send( 'committee_booking_received', $event_id, $extra );
}

/**
 * Register someone onto an event on their behalf (a host, co-owner or the
 * committee acting from the bookings list — phone and email requests, VIPs,
 * press). The person gets a booking OF THEIR OWN (they are its author, it
 * sits under their "My bookings", they can cancel it), created through
 * law_booking_create() so every guard, the recount and the host/committee
 * emails run exactly as for a self-service booking. What differs is the
 * confirmation: `user_booking_registered` (existing account) or
 * `user_booking_registered_invited` (new account, with the set-password link),
 * both naming who registered them.
 *
 * A new account is created before the booking (post_author needs a user) and
 * deleted again if the booking is then refused, so a failed attempt leaves no
 * orphan account behind; the cheap guards run first so the common refusals
 * never create one at all.
 *
 * @param int   $event_id law_event post ID.
 * @param array $raw_row  name / email / organisation / job_title as posted.
 * @param int   $actor_id The manager acting.
 * @param array $args     press (bool): flag the booking as a press pass (the
 *                        handler only honours this for the committee).
 * @return int|WP_Error Booking post ID.
 */
function law_booking_register_by_manager( $event_id, array $raw_row, $actor_id, array $args = array() ) {
	$event_id = (int) $event_id;
	$actor_id = (int) $actor_id;
	$press    = ! empty( $args['press'] );

	$clean = law_booking_clean_additional_rows( array( $raw_row ) );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	$row = $clean[0] ?? null;
	if ( ! $row ) {
		return new WP_Error( 'law_booking_invalid_row', 'Please complete the attendee details.', array( 'row' => 0, 'field' => 'name' ) );
	}

	// Fast-fail on the common refusals before any account is created. All of
	// these run again inside the event lock in law_booking_create().
	$open = law_booking_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return law_booking_log_refusal( $event_id, 0, $open, $actor_id );
	}
	$existing = get_user_by( 'email', $row['email'] );
	$dup      = law_booking_guard_duplicates(
		$event_id,
		array( array( 'user_id' => $existing ? (int) $existing->ID : 0, 'email' => $row['email'], 'name' => $row['name'] ) ),
		law_booking_holding_statuses()
	);
	if ( is_wp_error( $dup ) ) {
		return law_booking_log_refusal( $event_id, 0, $dup, $actor_id );
	}
	$cap = law_booking_guard_capacity( $event_id, 1 );
	if ( is_wp_error( $cap ) ) {
		return law_booking_log_refusal( $event_id, 0, $cap, $actor_id );
	}

	$user    = $existing;
	$created = false;
	if ( ! $user ) {
		$resolved = law_booking_resolve_attendee_user( $row, $event_id, $actor_id );
		if ( is_wp_error( $resolved ) ) {
			return new WP_Error( $resolved->get_error_code(), $resolved->get_error_message(), array( 'row' => 0, 'field' => 'email' ) );
		}
		$user    = get_user_by( 'id', (int) $resolved['user_id'] );
		$created = true;
	}

	$ids = law_booking_create(
		$event_id,
		(int) $user->ID,
		array(),
		array(
			'actor'       => $actor_id,
			'on_behalf'   => true,
			'new_account' => $created,
			'press'       => $press,
			'owner_row'   => $row,
		)
	);

	if ( is_wp_error( $ids ) ) {
		if ( $created ) {
			// No orphan accounts: the person was never emailed, so nothing
			// points at the account and it can simply go.
			law_booking_delete_created_users( array( (int) $user->ID => true ), $event_id, $actor_id );
		}
		return $ids;
	}

	return (int) $ids[0];
}

/**
 * Book one more colleague onto an event the booker already has a party on
 * (the manage view's "Add a colleague"): a NEW booking of their own, with its
 * own number, carrying _law_booked_by = the booker.
 *
 * @return int|WP_Error The new booking's post ID.
 */
function law_booking_add_attendee( $event_id, $booker_id, array $raw_row, $actor_id = 0 ) {
	$event_id  = (int) $event_id;
	$booker_id = (int) $booker_id;
	$actor_id  = (int) $actor_id ?: $booker_id;

	$open = law_booking_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return law_booking_log_refusal( $event_id, 0, $open, $actor_id );
	}
	// An ACTIVE party, deliberately: someone whose own entry is still on the
	// waitlist must not be able to hand a colleague a confirmed place ahead of
	// the queue. They join the waitlist again instead.
	if ( ! law_booking_party( $event_id, $booker_id ) ) {
		return new WP_Error(
			'law_booking_no_party',
			law_booking_party( $event_id, $booker_id, law_booking_holding_statuses() )
				? 'You are on the waitlist for this event, so you cannot add a colleague to a booking yet. They can join the waitlist too.'
				: 'You have no booking for this event to add a colleague to.'
		);
	}

	$clean = law_booking_clean_additional_rows( array( $raw_row ) );
	if ( is_wp_error( $clean ) ) {
		return $clean;
	}
	$row = $clean[0] ?? null;
	if ( ! $row ) {
		return new WP_Error( 'law_booking_invalid_row', 'Please complete the attendee details.', array( 'row' => 0, 'field' => 'name' ) );
	}
	$known          = get_user_by( 'email', $row['email'] );
	$row['user_id'] = $known ? (int) $known->ID : 0;

	// Cheap refusals before the account is created (see law_booking_create()).
	$dup = law_booking_guard_duplicates( $event_id, array( $row ), law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return law_booking_log_refusal( $event_id, 0, $dup, $actor_id );
	}
	if ( law_booking_colleague_count( $event_id, $booker_id ) >= law_booking_max_additional() ) {
		return law_booking_log_refusal(
			$event_id,
			0,
			new WP_Error( 'law_booking_too_many', sprintf( 'You can bring at most %d colleagues to an event.', law_booking_max_additional() ) ),
			$actor_id
		);
	}
	$cap = law_booking_guard_capacity( $event_id, 1 );
	if ( is_wp_error( $cap ) ) {
		return law_booking_log_refusal( $event_id, 0, $cap, $actor_id );
	}

	$created = false;
	if ( ! $row['user_id'] ) {
		$resolved = law_booking_resolve_attendee_user( $row, $event_id, $actor_id );
		if ( is_wp_error( $resolved ) ) {
			return new WP_Error( $resolved->get_error_code(), $resolved->get_error_message(), array( 'row' => 0, 'field' => 'email' ) );
		}
		$row['user_id'] = (int) $resolved['user_id'];
		$created        = true;
	}
	$created_ids = $created ? array( (int) $row['user_id'] => true ) : array();

	// Shared-state guards run inside the lock (security review, 7 September
	// 2026): the cap count, the duplicate scan and the clash read must see
	// serialised state, or two parallel adds both pass.
	if ( ! law_booking_lock( $event_id ) ) {
		law_booking_delete_created_users( $created_ids, $event_id, $actor_id );
		return new WP_Error( 'law_booking_busy', 'The event is busy taking another booking. Please try again in a moment.' );
	}
	$refuse = function ( WP_Error $error ) use ( $event_id, $actor_id, $created_ids ) {
		law_booking_unlock( $event_id );
		law_booking_delete_created_users( $created_ids, $event_id, $actor_id );
		return law_booking_log_refusal( $event_id, 0, $error, $actor_id );
	};

	law_event_recount_attendees( $event_id );

	// See law_booking_create(): the event can stop being open while this
	// request queues for the lock.
	$open = law_booking_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $refuse( $open );
	}

	$dup = law_booking_guard_duplicates( $event_id, array( $row ), law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return $refuse( $dup );
	}
	if ( law_booking_colleague_count( $event_id, $booker_id ) >= law_booking_max_additional() ) {
		return $refuse( new WP_Error( 'law_booking_too_many', sprintf( 'You can bring at most %d colleagues to an event.', law_booking_max_additional() ) ) );
	}
	$clash = law_booking_guard_clash( (int) $row['user_id'], $event_id, $row['name'] );
	if ( is_wp_error( $clash ) ) {
		return $refuse( $clash );
	}
	$cap = law_booking_guard_capacity( $event_id, 1 );
	if ( is_wp_error( $cap ) ) {
		return $refuse( $cap );
	}

	$number     = law_bookings_next_number();
	$booking_id = wp_insert_post(
		array(
			'post_type'   => LAW_BOOKING_CPT,
			'post_status' => 'publish',
			'post_title'  => 'Booking #' . $number,
			'post_parent' => $event_id,
			'post_author' => (int) $row['user_id'],
		),
		true
	);
	if ( is_wp_error( $booking_id ) ) {
		law_booking_unlock( $event_id );
		law_booking_delete_created_users( $created_ids, $event_id, $actor_id );
		return $booking_id;
	}
	$booking_id = (int) $booking_id;
	law_event_update_meta( $booking_id, '_law_booking_number', $number );
	law_booking_write_attendee( $booking_id, $row, $booker_id );

	$sold = law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );

	law_booking_grant_attendee_role( (int) $row['user_id'], $event_id, $actor_id );

	$booker = get_user_by( 'id', $booker_id );
	law_event_log(
		$event_id,
		sprintf(
			'Booking #%d created by %s for %s (%s).',
			$number,
			$booker ? $booker->display_name : 'a booker',
			$row['name'],
			$row['email']
		),
		array( 'action' => 'booking_attendee_added', 'booking' => $booking_id, 'sold' => $sold, 'source' => 'bookings' ),
		array( 'user_id' => $actor_id )
	);

	law_booking_notify_attendee( $booking_id, $created );

	// The host and committee hear about every new booking, however it arrived
	// (WAITLIST.md §A9.12).
	$placeholders = law_booking_email_placeholders( $booking_id );
	law_events_send( 'host_booking_received', $event_id, array( 'placeholders' => $placeholders ) );
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
 * Cancel one booking — one person's place. Idempotent; there is no un-cancel.
 *
 * @param int    $booking_id law_booking post ID.
 * @param int    $actor_id   Acting user.
 * @param string $context    Who did it, which picks the attendee's email:
 *                           'self' (they cancelled their own place),
 *                           'booker' (the person who booked them cancelled it),
 *                           'host_reject' (host or committee, with a reason),
 *                           'event_cancelled' (the event-cancel sweep),
 *                           'account_deleted' (their account is gone, so there
 *                           is nobody to email).
 * @param array  $args       Optional: reason (host_reject).
 * @return true|WP_Error
 */
function law_booking_cancel( $booking_id, $actor_id, $context = 'self', array $args = array() ) {
	$booking = get_post( $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return new WP_Error( 'law_booking_missing', 'Booking not found.' );
	}
	if ( 'law-cancelled' === $booking->post_status ) {
		return true;
	}
	$event_id    = (int) $booking->post_parent;
	$waitlisted  = 'law-waitlisted' === $booking->post_status;

	// Under the event lock like every other mutation. NOTE: GET_LOCK does not
	// nest — a second acquire on a name this session already holds returns
	// immediately and a single RELEASE_LOCK frees it — so no caller may hold
	// the lock across a call to this function. None does today: every path
	// releases before calling the next locking function.
	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', 'The booking is busy with another change. Please try again in a moment.' );
	}

	$updated = law_booking_set_status( $booking->ID, 'law-cancelled' );
	if ( is_wp_error( $updated ) ) {
		law_booking_unlock( $event_id );
		return $updated;
	}
	// A cancelled entry holds no queue position; leaving one behind would let
	// an untrash or a stale read jump it back into the running order.
	delete_post_meta( $booking->ID, '_law_waitlist_position' );
	delete_post_meta( $booking->ID, '_law_waitlist_blocked' );
	if ( $waitlisted && function_exists( 'law_waitlist_renumber' ) ) {
		law_waitlist_renumber( $event_id );
	}

	$sold = law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );

	$number  = (int) law_event_meta( $booking->ID, '_law_booking_number' );
	$person  = law_booking_attendee( $booking );
	$reason  = trim( (string) ( $args['reason'] ?? '' ) );

	// The committee reads these, so say who did it in words rather than
	// printing the internal context token.
	$by = array(
		'self'            => 'cancelled by the attendee',
		'booker'          => 'cancelled by the person who booked it',
		'host_reject'     => 'cancelled by the host',
		'event_cancelled' => 'cancelled because the event was cancelled',
		'account_deleted' => 'cancelled because the account was deleted',
	);
	law_event_log(
		$event_id,
		sprintf(
			$waitlisted ? 'Waitlist entry #%1$d %2$s: %3$s.%4$s' : 'Booking #%1$d %2$s: %3$s.%4$s',
			$number,
			$by[ $context ] ?? str_replace( '_', ' ', $context ),
			$person['name'] ?: $person['email'],
			'' !== $reason ? ' Reason: ' . $reason : ''
		),
		array(
			'action'  => $waitlisted ? 'waitlist_cancelled' : ( 'host_reject' === $context ? 'booking_rejected' : 'booking_cancelled' ),
			'booking' => (int) $booking->ID,
			'context' => $context,
			'sold'    => $sold,
			'source'  => $waitlisted ? 'waitlist' : 'bookings',
		),
		array( 'user_id' => (int) $actor_id )
	);

	// The person losing the place is always told, with a template per context:
	// one generic "your booking was cancelled" would mis-describe most of them.
	$slugs = $waitlisted
		? array(
			'self'            => 'user_waitlist_left',
			'booker'          => 'user_waitlist_removed_by_booker',
			'host_reject'     => 'user_waitlist_rejected',
			'event_cancelled' => 'user_waitlist_event_cancelled',
		)
		: array(
			'self'            => 'user_booking_cancelled_self',
			'booker'          => 'user_booking_cancelled_by_booker',
			'host_reject'     => 'user_booking_rejected',
			'event_cancelled' => 'user_booking_event_cancelled',
		);
	$email = law_booking_attendee_email( $booking );
	if ( isset( $slugs[ $context ] ) && '' !== $email ) {
		$host = get_user_by( 'id', (int) get_post_field( 'post_author', $event_id ) );
		law_events_send(
			$slugs[ $context ],
			$event_id,
			array(
				'to'           => array( $email ),
				'placeholders' => array_merge(
					law_booking_email_placeholders( $booking->ID ),
					array(
						'attendee_name'  => $person['name'],
						'removal_reason' => $reason,
						'host_email'     => $host ? $host->user_email : '',
					)
				),
			)
		);
	}

	// A place just opened: offer it to the waitlist. Not during the sweep,
	// where the event itself is going away.
	if ( 'event_cancelled' !== $context && ! $waitlisted && function_exists( 'law_waitlist_process' ) ) {
		law_waitlist_process( $event_id, 'cancel' );
	}

	return true;
}

/**
 * Cancel every booking a person made for one event: their own place and the
 * colleagues they brought ("Cancel all bookings" on the manage view).
 *
 * @return int How many were cancelled.
 */
function law_bookings_cancel_party( $event_id, $booker_id, $actor_id ) {
	$booker_id = (int) $booker_id;
	$party     = law_booking_party( $event_id, $booker_id, law_booking_holding_statuses() );
	$numbers   = array();
	$done      = 0;

	// One promotion pass for the whole party, not one per booking: four
	// cancellations should not mean four passes and four lock acquisitions.
	$suspended                         = $GLOBALS['law_waitlist_suspended'] ?? false;
	$GLOBALS['law_waitlist_suspended'] = true;
	foreach ( $party as $booking ) {
		$context = (int) $booking->post_author === (int) $actor_id ? 'self' : 'booker';
		$result  = law_booking_cancel( $booking->ID, (int) $actor_id, $context );
		if ( ! is_wp_error( $result ) ) {
			$numbers[] = '#' . (int) law_event_meta( $booking->ID, '_law_booking_number' );
			$done++;
		}
	}
	$GLOBALS['law_waitlist_suspended'] = $suspended;

	if ( $done > 1 ) {
		$actor = get_user_by( 'id', (int) $actor_id );
		law_event_log(
			$event_id,
			sprintf(
				'%s cancelled the %d bookings they made: %s. Everyone has been emailed.',
				$actor ? $actor->display_name : 'A booker',
				$done,
				implode( ', ', $numbers )
			),
			array( 'action' => 'booking_party_cancelled', 'booking' => (int) $party[0]->ID, 'bookings' => $done, 'source' => 'bookings' ),
			array( 'user_id' => (int) $actor_id )
		);
	}

	if ( $done && function_exists( 'law_waitlist_process' ) ) {
		law_waitlist_process( $event_id, 'cancel_party' );
	}

	return $done;
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
	// Waitlist entries go FIRST, and promotion is suspended for the duration:
	// this sweep also runs from wp_trash_post, where the event is still
	// published, so a cancel freeing a place could otherwise promote someone
	// onto an event that is being deleted seconds later.
	$suspended                          = $GLOBALS['law_waitlist_suspended'] ?? false;
	$GLOBALS['law_waitlist_suspended'] = true;
	$bookings = array_merge(
		law_bookings_for_event( $event_id, 'law-waitlisted', -1 ),
		law_bookings_for_event( $event_id, 'publish', -1 )
	);
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
			if ( ! wp_next_scheduled( 'law_bookings_resume_cancel_sweep', array( $event_id, (int) $actor ) ) ) {
				wp_schedule_single_event( time() + 60, 'law_bookings_resume_cancel_sweep', array( $event_id, (int) $actor ) );
			}
			law_event_log(
				$event_id,
				sprintf( 'Event-cancel sweep time-boxed after %d of %d bookings; the rest continue in the background within a few minutes.', $done, count( $bookings ) ),
				array( 'action' => 'booking_event_cancel_sweep', 'done' => $done, 'of' => count( $bookings ), 'source' => $source ),
				array( 'user_id' => (int) $actor )
			);
			$GLOBALS['law_waitlist_suspended'] = $suspended;
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
	$GLOBALS['law_waitlist_suspended'] = $suspended;
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
 *
 * @param int   $booking_id The booking this email is about.
 * @param int[] $party_ids  Optional: every booking made in the same submission,
 *                          so the booker's confirmation and the host copy can
 *                          list the whole party with each person's number.
 */
function law_booking_email_placeholders( $booking_id, array $party_ids = array() ) {
	$booking_id = (int) $booking_id;
	$event_id   = (int) get_post_field( 'post_parent', $booking_id );

	$list    = array();
	$numbers = array();
	foreach ( ( $party_ids ?: array( $booking_id ) ) as $id ) {
		$person  = law_booking_attendee( $id );
		$number  = (int) law_event_meta( $id, '_law_booking_number' );
		$line    = trim( $person['name'] ) . ' (' . $person['email'] . ')';
		$facts   = array_filter( array( $person['organisation'], $person['job_title'] ) );
		if ( $facts ) {
			$line .= ', ' . implode( ', ', $facts );
		}
		$list[]    = $line . ' (Booking #' . $number . ')';
		$numbers[] = '#' . $number;
	}

	$available = (int) law_event_meta( $event_id, '_law_tickets_available' );
	$remaining = law_event_tickets_remaining( $event_id );

	// Only say anything about colleagues when there ARE colleagues: most
	// bookings are one person, and a paragraph about people who do not exist
	// reads as a mistake.
	$party_note = count( $numbers ) > 1
		? 'Each colleague has a booking of their own, with their own booking number, and has been emailed their own confirmation. Accounts are created for any who do not already have one, with a link to set their password and to add any dietary or accessibility requirements to their profile.'
		: '';
	$waitlist_note = count( $numbers ) > 1
		? 'Everyone you added is on the waitlist in their own right, so places are offered to them individually.'
		: '';

	return array(
		'booking_number'    => (string) (int) law_event_meta( $booking_id, '_law_booking_number' ),
		'booking_numbers'   => implode( ', ', $numbers ),
		'party_note'        => $party_note,
		'waitlist_note'     => $waitlist_note,
		'attendee_list'     => implode( "\n", $list ),
		'invited_by'        => law_booking_invited_by_label( $booking_id ),
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
function law_booking_log_refusal( $event_id, $booking_id, WP_Error $error, $actor_id, $source = 'bookings' ) {
	law_event_log(
		$event_id,
		sprintf( 'waitlist' === $source ? 'Waitlist refused: %s' : 'Booking refused: %s', $error->get_error_message() ),
		array( 'action' => 'booking_guard_refused', 'booking' => (int) $booking_id, 'code' => $error->get_error_code(), 'source' => $source ),
		array( 'user_id' => (int) $actor_id )
	);
	return $error;
}

/* Admin-post handlers ________________________________________________________ */

add_action( 'admin_post_law_booking_create', 'law_booking_create_handler' );
add_action( 'admin_post_nopriv_law_booking_create', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_add_attendee', 'law_booking_add_attendee_handler' );
add_action( 'admin_post_nopriv_law_booking_add_attendee', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_cancel', 'law_booking_cancel_handler' );
add_action( 'admin_post_nopriv_law_booking_cancel', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_cancel_party', 'law_booking_cancel_party_handler' );
add_action( 'admin_post_nopriv_law_booking_cancel_party', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_reject_attendee', 'law_booking_reject_attendee_handler' );
add_action( 'admin_post_nopriv_law_booking_reject_attendee', 'law_events_nopriv_json' );
add_action( 'admin_post_law_booking_register_attendee', 'law_booking_register_attendee_handler' );
add_action( 'admin_post_nopriv_law_booking_register_attendee', 'law_events_nopriv_json' );

/**
 * Register: book the signed-in user onto the event, plus any colleagues they
 * named (each of whom gets their own booking).
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
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'booking-failed' );
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

/**
 * The manage view's "Add a colleague": one more booking on an event the
 * current user already has a party on. The event is the subject, so it is
 * posted; the engine re-checks that they have a party there.
 */
function law_booking_add_attendee_handler() {
	$event_id = absint( $_POST['event_id'] ?? 0 );
	$is_ajax  = law_events_guard_post(
		'law_booking_add_attendee',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'title' => 'Colleague booked', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'attendee-added',
		)
	);

	$user  = wp_get_current_user();
	$party = law_booking_party( $event_id, (int) $user->ID );
	if ( ! $party ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to change this booking.', 'status' => 403 ), 'booking-failed' );
	}

	$row    = wp_unslash( $_POST['law_attendees'] ?? array() );
	$row    = is_array( $row ) ? reset( $row ) : array();
	$result = law_booking_add_attendee( $event_id, (int) $user->ID, is_array( $row ) ? $row : array() );

	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'booking-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Colleague booked',
			'message'  => 'They have their own booking now and have been emailed the event details. Reloading the page…',
			// The notice rides the redirect so the fetch flow's reload shows the
			// same confirmation banner the no-JS flow gets.
			'redirect' => add_query_arg( 'law_notice', 'attendee-added', law_booking_manage_url( $party[0]->ID ) ),
		),
		'attendee-added'
	);
}

/**
 * Cancel one booking: the attendee cancelling their own place, or the person
 * who booked them cancelling it. The context picks the email they get.
 */
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
	$user       = wp_get_current_user();
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to cancel this booking.', 'status' => 403 ), 'booking-failed' );
	}
	$is_self   = (int) $booking->post_author === (int) $user->ID;
	$is_booker = (int) law_event_meta( $booking->ID, '_law_booked_by' ) === (int) $user->ID;
	if ( ! $is_self && ! $is_booker ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to cancel this booking.', 'status' => 403 ), 'booking-failed' );
	}

	$waitlisted = 'law-waitlisted' === $booking->post_status;
	$event_id   = (int) $booking->post_parent;
	$result     = law_booking_cancel( $booking_id, (int) $user->ID, $is_self ? 'self' : 'booker' );
	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message() ), 'booking-failed' );
	}

	// Back to the party view while anything of theirs is left on this event,
	// otherwise to My bookings, which is where the card now lives.
	$party  = law_booking_party( $event_id, (int) $user->ID, law_booking_holding_statuses() );
	$notice = $waitlisted ? 'waitlist-left' : 'booking-cancelled';
	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => $waitlisted ? 'Waitlist entry cancelled' : 'Booking cancelled',
			'message'  => $waitlisted
				? ( $is_self ? 'You are off the waitlist. Reloading the page…' : 'They are off the waitlist and have been emailed. Reloading the page…' )
				: ( $is_self ? 'Your place has been freed. Reloading the page…' : 'They have been emailed to let them know. Reloading the page…' ),
			'redirect' => $party
				? add_query_arg( 'law_notice', $notice, law_booking_manage_url( $party[0]->ID ) )
				: add_query_arg( 'law_notice', $notice, home_url( '/account/events/' ) ),
		),
		$notice
	);
}

/** Cancel every booking the current user made for one event. */
function law_booking_cancel_party_handler() {
	$is_ajax = law_events_guard_post(
		'law_booking_cancel_party',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'title' => 'Bookings cancelled', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'party-cancelled',
		)
	);

	$event_id = absint( $_POST['event_id'] ?? 0 );
	$user     = wp_get_current_user();
	$party    = law_booking_party( $event_id, (int) $user->ID, law_booking_holding_statuses() );
	if ( ! $party ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to cancel these bookings.', 'status' => 403 ), 'booking-failed' );
	}

	law_bookings_cancel_party( $event_id, (int) $user->ID, (int) $user->ID );

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Bookings cancelled',
			'message'  => 'Your place and everyone you booked have been cancelled, and everyone has been emailed. Reloading the page…',
			'redirect' => add_query_arg( 'law_notice', 'party-cancelled', home_url( '/account/events/' ) ),
		),
		'party-cancelled'
	);
}

/**
 * The host/committee Reject on the bookings list: cancel that one person's
 * booking, with an optional reason. Gate: law_user_can_manage_event() on the
 * booking's parent, never a posted event ID.
 */
function law_booking_reject_attendee_handler() {
	$is_ajax = law_events_guard_post(
		'law_booking_reject_attendee',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'title' => 'Attendee rejected', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'booking-rejected',
		)
	);

	$booking    = law_booking_require_manageable( $is_ajax );
	$waitlisted = 'law-waitlisted' === $booking->post_status;

	$result = law_booking_cancel(
		(int) $booking->ID,
		get_current_user_id(),
		'host_reject',
		array( 'reason' => trim( (string) wp_unslash( $_POST['law_reject_reason'] ?? '' ) ) )
	);
	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message() ), 'booking-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Attendee rejected',
			'message'  => 'Their booking has been cancelled and they have been emailed. Reloading the page…',
			'redirect' => add_query_arg(
				array( 'law_event_bookings' => (int) $booking->post_parent, 'law_notice' => 'booking-rejected' ),
				home_url( '/account/events/' )
			) . ( $waitlisted ? '#law-waitlist' : '' ),
		),
		'booking-rejected'
	);
}

/**
 * The host/committee gate every booking-management handler starts with: load
 * the posted booking, prove it is one, derive the event from its parent (never
 * from a posted ID) and check the actor manages that event.
 *
 * One copy, because three copies of an authorisation check is how one of them
 * eventually gets the negation wrong. Never returns on refusal.
 *
 * @return WP_Post The booking.
 */
function law_booking_require_manageable( $is_ajax, $notice = 'booking-failed' ) {
	$booking = get_post( absint( $_POST['booking_id'] ?? 0 ) );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type
		|| ! law_user_can_manage_event( get_current_user_id(), (int) $booking->post_parent ) ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, you are not allowed to manage this event\'s bookings.', 'status' => 403 ),
			$notice
		);
	}
	return $booking;
}

/**
 * A refusal payload for the fetch layer: the message, plus the row and field
 * keys booking-form.js marks in place when the engine names them.
 */
function law_booking_error_payload( WP_Error $error ) {
	$payload = array( 'message' => $error->get_error_message() );
	$data    = $error->get_error_data();
	if ( is_array( $data ) ) {
		$payload += array_intersect_key( $data, array( 'row' => 1, 'field' => 1 ) );
	}
	return $payload;
}

/**
 * The bookings list's "Register an attendee" action: a host, co-owner or
 * committee member registering someone onto the event on their behalf. The
 * gate is law_user_can_manage_event() on the posted event (the event IS the
 * subject here — no booking exists yet). The press flag is committee-only:
 * press passes are issued administratively by LAW (spec §6.4), so a host's
 * posted flag is ignored, not refused.
 */
function law_booking_register_attendee_handler() {
	$event_id = absint( $_POST['event_id'] ?? 0 );
	$list     = $event_id ? law_booking_list_url( $event_id ) : home_url( '/account/events/' );
	$is_ajax  = law_events_guard_post(
		'law_booking_register_attendee',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'title' => 'Attendee registered', 'message' => 'Done.', 'redirect' => $list ),
			'honeypot_notice' => 'attendee-registered',
		)
	);

	$event = get_post( $event_id );
	if ( ! $event || LAW_EVENT_CPT !== $event->post_type || ! law_user_can_manage_event( get_current_user_id(), $event_id ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, you are not allowed to manage this event\'s bookings.', 'status' => 403 ), 'booking-failed' );
	}

	$row    = wp_unslash( $_POST['law_attendees'] ?? array() );
	$row    = is_array( $row ) ? reset( $row ) : array();
	$press  = ! empty( $_POST['law_press'] ) && law_user_is_committee();
	$result = law_booking_register_by_manager( $event_id, is_array( $row ) ? $row : array(), get_current_user_id(), array( 'press' => $press ) );

	if ( is_wp_error( $result ) ) {
		if ( ! $is_ajax ) {
			// The no-JS list form re-renders with the typed row and the refusal.
			law_booking_store_form_state( get_current_user_id(), $result, array( is_array( $row ) ? $row : array() ) );
		}
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'booking-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Attendee registered',
			'message'  => 'They have been emailed their confirmation. Reloading the page…',
			'redirect' => add_query_arg( 'law_notice', 'attendee-registered', $list ),
		),
		'attendee-registered'
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
 * One row set for the three export formats (CSV / Excel / PDF json): one row
 * per ACTIVE booking, which is one row per attendee.
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
		$user_ids[] = (int) $booking->post_author;
		$user_ids[] = (int) law_event_meta( $booking->ID, '_law_booked_by' );
	}
	$user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
	if ( $user_ids ) {
		cache_users( $user_ids );
	}

	$rows = array();
	foreach ( $bookings as $booking ) {
		$person  = law_booking_attendee( $booking );
		$user    = get_user_by( 'id', (int) $booking->post_author );
		$profile = $user ? law_profile_values( (int) $user->ID ) : array();

		// The linked account's name wins; the snapshot splits on the first space.
		if ( $user && ( $user->first_name || $user->last_name ) ) {
			$first = $user->first_name;
			$last  = $user->last_name;
		} else {
			$parts = preg_split( '/\s+/', trim( $person['name'] ), 2 );
			$first = $parts[0] ?? '';
			$last  = $parts[1] ?? '';
		}

		$rows[] = array(
			(int) law_event_meta( $booking->ID, '_law_booking_number' ),
			law_booking_invited_by_label( $booking ),
			$first,
			$last,
			$person['email'],
			$person['organisation'],
			$person['job_title'],
			(string) ( $profile['country'] ?? '' ),
			law_event_meta( $booking->ID, '_law_is_press' ) ? 'Yes' : '',
			law_booking_profile_requirements( $profile, 'accessibility' ),
			law_booking_profile_requirements( $profile, 'dietary' ),
		);
	}

	return array(
		'title'   => sprintf( 'Attendees for %s, %s', get_the_title( $event_id ), $when ),
		'columns' => array( 'Booking ID', 'Invited by', 'First name', 'Second name', 'Email', 'Organisation', 'Job title', 'Country', 'Press', 'Accessibility', 'Dietary' ),
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
		// An administrator trashing a live booking frees a real place, and the
		// engine never saw it. Offer it to the queue.
		if ( ! $engine && 'publish' === $old_status && function_exists( 'law_waitlist_process' ) ) {
			law_waitlist_process( (int) $post->post_parent, 'wp-admin' );
		}
	},
	10,
	3
);

add_action(
	'deleted_post',
	function ( $post_id, $post ) {
		if ( $post instanceof WP_Post && LAW_BOOKING_CPT === $post->post_type && $post->post_parent ) {
			law_event_recount_attendees( (int) $post->post_parent, 'deleted' );
			if ( function_exists( 'law_waitlist_process' ) ) {
				law_waitlist_process( (int) $post->post_parent, 'deleted' );
			}
		}
	},
	10,
	2
);

/**
 * Deleting a WordPress account releases that person's places.
 *
 * The law_booking CPT deliberately does not support 'author', so core's
 * wp_delete_user() neither deletes nor reassigns booking posts: without this
 * they would stay active with a dangling author, holding places nobody can
 * ever take up. Their own bookings are cancelled (silently: there is nobody
 * left to email) and the freed places are offered to the waitlist. Bookings
 * they made FOR OTHER PEOPLE are left alone — those places belong to the
 * colleagues, who simply lose the person who arranged them.
 */
add_action(
	'deleted_user',
	function ( $user_id ) {
		foreach ( law_user_booking_ids( (int) $user_id ) as $booking_id ) {
			$booking = get_post( $booking_id );
			if ( ! $booking || ! in_array( $booking->post_status, law_booking_holding_statuses(), true ) ) {
				continue;
			}
			law_booking_cancel( (int) $booking->ID, 0, 'account_deleted' );
		}
	}
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
