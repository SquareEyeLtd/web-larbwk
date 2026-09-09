<?php
/**
 * The waitlist (WAITLIST.md Part B; EVENTS_4.2_SPECS.md §4.3, §4.4, §5.4, §8).
 *
 * A waitlist entry is a law_booking post with status law-waitlisted and a
 * 1-based _law_waitlist_position. Because bookings are one per attendee, an
 * entry is exactly one place, so promotion is plain first-in-first-out: when
 * places open, the queue is walked in order and each entry that still passes
 * the duplicate and clash guards is seated, until the places run out. There is
 * no party blocking, and no need to freeze Register while people wait.
 *
 * Promotion is automatic and immediate (settled): no offer step, no expiry.
 * Hosts and the committee can reorder the queue and promote an entry by hand,
 * even onto a full event, which over-books it deliberately.
 *
 * Everything logs to the parent EVENT's activity log with source => 'waitlist'
 * and booking => <id>, like the rest of the bookings engine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How many entries one pass may promote before handing the rest to cron. */
const LAW_WAITLIST_PASS_CAP = 10;

/** How long one pass may run before handing the rest to cron, in seconds. */
const LAW_WAITLIST_PASS_SECONDS = 15;

/* Queries ____________________________________________________________________ */

/**
 * An event's waitlist, in promotion order.
 *
 * @return WP_Post[]
 */
function law_waitlist_for_event( $event_id, $limit = 500 ) {
	$entries = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_parent'    => (int) $event_id,
			'post_status'    => 'law-waitlisted',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	// Ordered in PHP, not by a meta_key orderby: that would INNER JOIN postmeta,
	// so an entry that lost its position would disappear from the queue
	// altogether rather than sort badly — invisibly stranded, since the count
	// below would still see it. Here it sorts to the BACK and the next
	// renumber heals it. get_posts has already primed the meta cache, so the
	// sort costs nothing.
	usort(
		$entries,
		function ( $a, $b ) {
			$pa = (int) law_event_meta( $a->ID, '_law_waitlist_position' ) ?: PHP_INT_MAX;
			$pb = (int) law_event_meta( $b->ID, '_law_waitlist_position' ) ?: PHP_INT_MAX;
			return $pa === $pb ? $a->ID <=> $b->ID : $pa <=> $pb;
		}
	);
	return $entries;
}

/**
 * How many people are waiting. Counted by status alone, with no meta join, so
 * an entry that somehow lost its position can never make the queue look empty.
 */
function law_waitlist_count( $event_id ) {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_parent = %d AND post_status = 'law-waitlisted'",
			LAW_BOOKING_CPT,
			(int) $event_id
		)
	);
}

/**
 * Close the gaps: renumber the queue 1..n in its current order. Run after
 * anything that removes an entry from the middle (a promotion, a cancellation)
 * or moves one, so the stored position always matches the position the host is
 * looking at — which is what the reorder controls post back as their
 * stale-click check. Callers hold the event lock.
 */
function law_waitlist_renumber( $event_id ) {
	foreach ( law_waitlist_for_event( $event_id ) as $i => $entry ) {
		if ( (int) law_event_meta( $entry->ID, '_law_waitlist_position' ) !== $i + 1 ) {
			law_event_update_meta( $entry->ID, '_law_waitlist_position', $i + 1 );
		}
	}
}

/** The next free position. Callers hold the event lock. */
function law_waitlist_next_position( $event_id ) {
	global $wpdb;
	$last = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT MAX(CAST(pm.meta_value AS UNSIGNED)) FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_law_waitlist_position'
			   AND p.post_type = %s AND p.post_parent = %d AND p.post_status = 'law-waitlisted'",
			LAW_BOOKING_CPT,
			(int) $event_id
		)
	);
	return $last + 1;
}

/* Joining ____________________________________________________________________ */

/**
 * Join the waitlist: an entry for the person submitting, plus one for each
 * colleague they named. Everything else — the guards, the accounts, the
 * numbers, the atomicity — is law_booking_create()'s, with the status and the
 * eligibility rule swapped (you join a waitlist only when the event is full).
 *
 * @return int[]|WP_Error Booking post IDs, the joiner's own first.
 */
function law_waitlist_join( $event_id, $booker_id, array $additional_rows ) {
	$ids = law_booking_create( $event_id, $booker_id, $additional_rows, array( 'status' => 'law-waitlisted' ) );
	if ( is_wp_error( $ids ) ) {
		return $ids;
	}

	$event_id  = (int) $event_id;
	$was_empty = count( $ids ) === law_waitlist_count( $event_id );
	$booker    = get_user_by( 'id', (int) $booker_id );

	foreach ( $ids as $i => $booking_id ) {
		$person = law_booking_attendee( $booking_id );
		law_event_log(
			$event_id,
			sprintf(
				'Waitlist entry #%d added at position %d: %s (%s).',
				(int) law_event_meta( $booking_id, '_law_booking_number' ),
				(int) law_event_meta( $booking_id, '_law_waitlist_position' ),
				$person['name'],
				$person['email']
			),
			array(
				'action'   => 'waitlist_joined',
				'booking'  => (int) $booking_id,
				'position' => (int) law_event_meta( $booking_id, '_law_waitlist_position' ),
				'source'   => 'waitlist',
			),
			array( 'user_id' => (int) $booker_id )
		);
	}

	$placeholders = law_booking_email_placeholders( $ids[0], $ids );

	// The host hears the first time a queue forms on their event, which is
	// their cue to consider releasing more places (spec §4.4).
	if ( $was_empty ) {
		law_events_send(
			'host_waitlist_activated',
			$event_id,
			array( 'placeholders' => array_merge( $placeholders, array( 'waitlist_count' => (string) law_waitlist_count( $event_id ) ) ) )
		);
		law_event_log(
			$event_id,
			'A waitlist has opened on this event: it is full and people are now queueing for a place.',
			array( 'action' => 'waitlist_activated', 'booking' => (int) $ids[0], 'source' => 'waitlist' ),
			array( 'user_id' => (int) $booker_id )
		);
	}

	return $ids;
}

/**
 * The emails one waitlist submission sends. Called from law_booking_create()
 * where the party and its freshly created accounts are still in hand, exactly
 * as the booking path's law_booking_send_submission_emails() is: the joiner
 * hears once with the whole party, and each colleague hears in their own
 * right, because their entry is their own.
 *
 * No .ics anywhere here: nobody has a place yet, and a calendar invitation for
 * an event you may not get into is worse than none.
 */
function law_waitlist_send_join_emails( array $ids, array $people, $booker, $event_id ) {
	$placeholders = law_booking_email_placeholders( $ids[0], $ids );

	if ( $booker && is_email( $booker->user_email ) ) {
		law_events_send(
			'user_waitlist_joined',
			$event_id,
			array(
				'to'           => array( $booker->user_email ),
				'placeholders' => array_merge(
					$placeholders,
					array( 'attendee_name' => $people[0]['name'], 'party_list' => $placeholders['attendee_list'] )
				),
			)
		);
	}

	foreach ( $ids as $i => $booking_id ) {
		if ( 0 === $i ) {
			continue;
		}
		// The booking path's notification with the waitlist's wording, and no
		// calendar invite: they hold no place yet.
		law_booking_notify_attendee(
			$booking_id,
			! empty( $people[ $i ]['created'] ),
			array( 'invited' => 'user_waitlist_attendee_invited', 'added' => 'user_waitlist_attendee_added', 'ics' => false )
		);
	}
}

/* Promotion __________________________________________________________________ */

/**
 * Offer every place that is free to the people waiting, in order.
 *
 * Plain FIFO: one entry is one place, so the queue is walked from the front
 * and each entry is seated until the places run out. An entry blocked by the
 * duplicate or clash guards (they booked an overlapping event while waiting)
 * is SKIPPED IN PLACE — it keeps its position and the walk continues, so one
 * stuck entry can never freeze the queue behind it.
 *
 * Called after anything that frees a place, never from inside the recount:
 * the recount runs inside other bookers' locks and before their guards, and
 * promotion must not email from in there.
 *
 * @param string $source For the log: cancel, wp-admin, tickets, cron…
 * @return int[] The bookings promoted.
 */
function law_waitlist_process( $event_id, $source = 'bookings' ) {
	static $processing = array();

	$event_id = (int) $event_id;
	// The event-cancel sweep frees places on its way to deleting the event;
	// nobody should be promoted onto that.
	if ( ! empty( $GLOBALS['law_waitlist_suspended'] ) || isset( $processing[ $event_id ] ) ) {
		return array();
	}
	// A closed, started, unpublished or not-yet-open event promotes nobody,
	// silently: this runs after every cancellation, and a no-op is not news.
	if ( true !== law_booking_guard_open( $event_id ) || 0 === law_waitlist_count( $event_id ) ) {
		return array();
	}

	if ( ! law_booking_lock( $event_id ) ) {
		law_event_log(
			$event_id,
			'The waitlist could not be processed: the event was busy. It will be retried shortly.',
			array( 'action' => 'waitlist_process_busy', 'source' => $source )
		);
		law_waitlist_schedule_resume( $event_id );
		return array();
	}

	$processing[ $event_id ] = true;
	$promoted                = array();
	$blocked                 = array();
	$started                 = time();
	$more                    = false;

	try {
		law_event_recount_attendees( $event_id );
		// One pass over the event's active bookings for the whole walk, rather
		// than one per candidate inside the duplicate guard.
		$taken = law_booking_taken_index( $event_id );
		// Re-read the event under the lock: a committee cancel can complete
		// while this pass waits for it, and its sweep has already passed.
		if ( true !== law_booking_guard_open( $event_id ) ) {
			return array();
		}
		foreach ( law_waitlist_for_event( $event_id, LAW_WAITLIST_PASS_CAP * 3 ) as $entry ) {
			$remaining = law_event_tickets_remaining( $event_id );
			if ( null === $remaining || $remaining < 1 ) {
				break;
			}
			// Blocked entries count too: a queue where most entries clash would
			// otherwise walk every one of them, on every cancellation, forever.
			if ( count( $promoted ) + count( $blocked ) >= LAW_WAITLIST_PASS_CAP || time() - $started > LAW_WAITLIST_PASS_SECONDS ) {
				$more = true;
				break;
			}

			$check = law_waitlist_check_promotable( $entry, $taken );
			if ( is_wp_error( $check ) ) {
				// Claim the latch here, under the lock, so two passes racing on
				// the same event cannot both decide they are the first to tell
				// this person. The email itself waits until after the unlock.
				$first = (string) law_event_meta( $entry->ID, '_law_waitlist_blocked' ) !== $check->get_error_code();
				if ( $first ) {
					law_event_update_meta( $entry->ID, '_law_waitlist_blocked', $check->get_error_code() );
				}
				$blocked[] = array( 'entry' => $entry, 'error' => $check, 'first' => $first );
				continue; // Skipped in place: not a capacity problem, so the queue moves on.
			}

			law_waitlist_seat( $entry, 0, 'auto', $source );
			$promoted[] = (int) $entry->ID;
			// The person just seated now holds a place, so the next candidate
			// must see them without the index being rebuilt from scratch.
			$taken['users'][ (int) $entry->post_author ] = true;
			$email = strtolower( law_booking_attendee( $entry )['email'] );
			if ( '' !== $email ) {
				$taken['emails'][ $email ] = true;
			}
		}
		// Always, not only after a promotion: this is also what heals an entry
		// restored from the trash with a stale or missing position.
		law_waitlist_renumber( $event_id );
	} finally {
		unset( $processing[ $event_id ] );
		law_booking_unlock( $event_id );
	}

	// Emails only once the lock is released: a promotion cascade must never
	// queue the next booker behind a mailbox.
	foreach ( $promoted as $booking_id ) {
		law_waitlist_notify_promoted( $booking_id );
	}
	if ( $promoted ) {
		law_waitlist_notify_host( $event_id, $promoted );
	}
	foreach ( $blocked as $item ) {
		law_waitlist_mark_blocked( $item['entry'], $item['error'], $source, $item['first'] );
	}
	if ( $promoted ) {
		law_booking_maybe_capacity_warning( $event_id );
	}
	if ( $more ) {
		law_waitlist_schedule_resume( $event_id );
	}

	return $promoted;
}

/**
 * Hand the rest of a queue to cron. WordPress ignores an identical hook and
 * args scheduled within ten minutes, so an already-pending resume is left
 * alone rather than silently dropped.
 */
function law_waitlist_schedule_resume( $event_id ) {
	$args = array( (int) $event_id );
	if ( ! wp_next_scheduled( 'law_waitlist_resume', $args ) ) {
		wp_schedule_single_event( time() + 60, 'law_waitlist_resume', $args );
	}
}
add_action( 'law_waitlist_resume', function ( $event_id ) {
	law_waitlist_process( (int) $event_id, 'cron' );
} );

/**
 * Can this entry take a place right now? Capacity is the caller's business;
 * this is the per-person part: they must not already hold a place here, and
 * the event must not overlap something they booked while they were waiting.
 *
 * @return true|WP_Error
 */
function law_waitlist_check_promotable( $entry, ?array $taken = null ) {
	$entry    = get_post( $entry );
	$event_id = (int) $entry->post_parent;
	$person   = law_booking_attendee( $entry );

	// Against ACTIVE bookings only: the entry being promoted is itself
	// waitlisted, and would otherwise match itself. $taken lets a promotion
	// pass build that set once instead of once per candidate.
	$dup = law_booking_guard_duplicates(
		$event_id,
		array( array( 'user_id' => (int) $entry->post_author, 'email' => $person['email'], 'name' => $person['name'] ) ),
		array( 'publish' ),
		$taken
	);
	if ( is_wp_error( $dup ) ) {
		return $dup;
	}
	// No name: the refusal is quoted straight into an email addressed TO this
	// person, and "Jane Smith is already booked on X" reads wrong to Jane.
	return law_booking_guard_clash( (int) $entry->post_author, $event_id, '' );
}

/**
 * Seat one waitlisted entry. The caller holds the event lock.
 *
 * @param string $mode 'auto' (the queue) or 'manual' (a host promoting).
 * @return true|WP_Error
 */
function law_waitlist_seat( $entry, $actor_id, $mode, $source ) {
	$entry    = get_post( $entry );
	$event_id = (int) $entry->post_parent;

	$updated = law_booking_set_status( $entry->ID, 'publish' );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}
	delete_post_meta( $entry->ID, '_law_waitlist_position' );
	delete_post_meta( $entry->ID, '_law_waitlist_blocked' );
	law_event_update_meta( $entry->ID, '_law_waitlist_promoted', current_time( 'mysql' ) );

	$sold      = law_event_recount_attendees( $event_id );
	$available = (int) law_event_meta( $event_id, '_law_tickets_available' );
	$person    = law_booking_attendee( $entry );

	law_event_log(
		$event_id,
		sprintf(
			'manual' === $mode
				? 'Booking #%1$d promoted from the waitlist by hand: %2$s (%3$s).'
				: 'Booking #%1$d promoted from the waitlist: %2$s (%3$s).',
			(int) law_event_meta( $entry->ID, '_law_booking_number' ),
			$person['name'],
			$person['email']
		),
		array( 'action' => 'waitlist_promoted', 'booking' => (int) $entry->ID, 'mode' => $mode, 'sold' => $sold, 'source' => $source ),
		array( 'user_id' => (int) $actor_id )
	);

	// Over-booking is a deliberate act, but it must be loud in the log: the
	// host has put more people in the room than the event has places for.
	if ( $available > 0 && $sold > $available ) {
		law_event_log(
			$event_id,
			sprintf( 'This event is now OVER-BOOKED: %d places taken of %d available.', $sold, $available ),
			array( 'action' => 'waitlist_overbooked', 'booking' => (int) $entry->ID, 'sold' => $sold, 'available' => $available, 'source' => $source ),
			array( 'user_id' => (int) $actor_id )
		);
	}

	return true;
}

/** Tell a promoted attendee they have a place, with the calendar invite. */
function law_waitlist_notify_promoted( $booking_id ) {
	law_booking_notify_attendee( $booking_id, false, array( 'added' => 'user_waitlist_promoted' ) );
}

/** One summary to the host per pass, however many were promoted. */
function law_waitlist_notify_host( $event_id, array $promoted ) {
	$lines = array();
	foreach ( $promoted as $booking_id ) {
		$person  = law_booking_attendee( $booking_id );
		$lines[] = trim( $person['name'] ) . ' (' . $person['email'] . '), Booking #' . (int) law_event_meta( $booking_id, '_law_booking_number' );
	}
	law_events_send(
		'host_waitlist_promoted',
		$event_id,
		array(
			'placeholders' => array_merge(
				law_booking_email_placeholders( $promoted[0] ),
				array(
					'promoted_list'  => implode( "\n", $lines ),
					'waitlist_count' => (string) law_waitlist_count( $event_id ),
				)
			),
		)
	);
}

/**
 * An entry the guards refused keeps its place in the queue, but its owner is
 * told once why it is being passed over, so they can resolve the clash rather
 * than wonder. The latch clears when the entry is seated or cancelled.
 */
function law_waitlist_mark_blocked( $entry, WP_Error $error, $source, $first = null ) {
	$entry    = get_post( $entry );
	$event_id = (int) $entry->post_parent;
	$person   = law_booking_attendee( $entry );
	$code     = $error->get_error_code();

	law_event_log(
		$event_id,
		sprintf(
			'Waitlist entry #%d passed over: %s',
			(int) law_event_meta( $entry->ID, '_law_booking_number' ),
			$error->get_error_message()
		),
		array( 'action' => 'waitlist_skipped', 'booking' => (int) $entry->ID, 'code' => $code, 'source' => $source )
	);

	// The caller claims the latch under the lock and tells us whether this was
	// the first sighting; a direct caller falls back to reading it here.
	if ( null === $first ) {
		$first = (string) law_event_meta( $entry->ID, '_law_waitlist_blocked' ) !== $code;
		if ( $first ) {
			law_event_update_meta( $entry->ID, '_law_waitlist_blocked', $code );
		}
	}
	if ( ! $first ) {
		return; // Already told them about this one.
	}

	$email = law_booking_attendee_email( $entry );
	if ( '' === $email ) {
		return;
	}
	law_events_send(
		'user_waitlist_blocked',
		$event_id,
		array(
			'to'           => array( $email ),
			'placeholders' => array_merge(
				law_booking_email_placeholders( $entry->ID ),
				array( 'attendee_name' => $person['name'], 'blocked_reason' => $error->get_error_message() )
			),
		)
	);
}

/* Host and committee actions _________________________________________________ */

/**
 * Promote one entry by hand, ahead of the queue and regardless of places: the
 * host's judgement call. The capacity guard is the only one bypassed — a
 * duplicate or a clash is still a refusal, because those would double-book a
 * real person rather than merely over-fill a room.
 *
 * @return array|WP_Error { booking: int, overbooked_by: int }
 */
function law_waitlist_promote( $booking_id, $actor_id ) {
	$entry = get_post( $booking_id );
	if ( ! $entry || LAW_BOOKING_CPT !== $entry->post_type || 'law-waitlisted' !== $entry->post_status ) {
		return new WP_Error( 'law_waitlist_not_waiting', 'That booking is not on the waitlist.' );
	}
	$event_id = (int) $entry->post_parent;

	$open = law_booking_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return law_booking_log_refusal( $event_id, (int) $entry->ID, $open, $actor_id, 'waitlist' );
	}
	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', 'The event is busy with another change. Please try again in a moment.' );
	}

	law_event_recount_attendees( $event_id );
	$check = law_waitlist_check_promotable( $entry );
	if ( is_wp_error( $check ) ) {
		law_booking_unlock( $event_id );
		return law_booking_log_refusal( $event_id, (int) $entry->ID, $check, $actor_id, 'waitlist' );
	}

	$seated = law_waitlist_seat( $entry, $actor_id, 'manual', 'manual' );
	if ( is_wp_error( $seated ) ) {
		law_booking_unlock( $event_id );
		return $seated;
	}

	law_waitlist_renumber( $event_id );
	$sold      = law_event_attendee_total( $event_id );
	$available = (int) law_event_meta( $event_id, '_law_tickets_available' );
	law_booking_unlock( $event_id );

	law_waitlist_notify_promoted( (int) $entry->ID );
	law_waitlist_notify_host( $event_id, array( (int) $entry->ID ) );

	$over = $available > 0 ? max( 0, $sold - $available ) : 0;
	if ( ! $over ) {
		// Only warn about the last few places when there are still places.
		law_booking_maybe_capacity_warning( $event_id );
	}
	// A manual promotion may have left room for the next in line.
	law_waitlist_process( $event_id, 'manual' );

	return array( 'booking' => (int) $entry->ID, 'overbooked_by' => $over );
}

/**
 * Move one entry up, down or to the top of the queue. Positions are recomputed
 * from the live order and renumbered 1..n, so nothing the client posts can set
 * a position directly, and gaps left by promotions heal on every move.
 *
 * @param string $direction        top | up | down.
 * @param int    $expected_position The position the actor was looking at; a
 *                                  stale click is a no-op, not a wrong move.
 * @return array|WP_Error { moved: bool, promoted: int[] } — the promoted IDs
 *                        because a move can seat somebody (raised places, a
 *                        smaller party now at the front), and the front end
 *                        has to reload rather than reorder in place when it
 *                        does.
 */
function law_waitlist_reorder( $booking_id, $direction, $actor_id, $expected_position = 0 ) {
	$entry = get_post( $booking_id );
	if ( ! $entry || LAW_BOOKING_CPT !== $entry->post_type || 'law-waitlisted' !== $entry->post_status ) {
		return new WP_Error( 'law_waitlist_not_waiting', 'That booking is not on the waitlist.' );
	}
	if ( ! in_array( $direction, array( 'top', 'up', 'down' ), true ) ) {
		return new WP_Error( 'law_waitlist_bad_direction', 'That is not a way to move a waitlist entry.' );
	}
	$event_id = (int) $entry->post_parent;

	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', 'The waitlist is busy with another change. Please try again in a moment.' );
	}

	$queue = law_waitlist_for_event( $event_id );
	$index = null;
	foreach ( $queue as $i => $candidate ) {
		if ( (int) $candidate->ID === (int) $entry->ID ) {
			$index = $i;
			break;
		}
	}
	if ( null === $index ) {
		law_booking_unlock( $event_id );
		return new WP_Error( 'law_waitlist_not_waiting', 'That booking is not on the waitlist.' );
	}

	// Somebody else moved the queue since this page was drawn: do nothing
	// rather than move the wrong entry, and let the reload show the truth.
	if ( $expected_position && (int) law_event_meta( $entry->ID, '_law_waitlist_position' ) !== (int) $expected_position ) {
		law_booking_unlock( $event_id );
		return array( 'moved' => false, 'promoted' => array() );
	}

	$target = 'top' === $direction ? 0 : ( 'up' === $direction ? $index - 1 : $index + 1 );
	if ( $target < 0 || $target > count( $queue ) - 1 || $target === $index ) {
		law_booking_unlock( $event_id );
		return array( 'moved' => false, 'promoted' => array() );
	}

	$moved = array_splice( $queue, $index, 1 );
	array_splice( $queue, $target, 0, $moved );
	foreach ( $queue as $i => $candidate ) {
		// Only the entries that actually moved: shifting one place in a long
		// queue should not rewrite every row to the value it already holds.
		if ( (int) law_event_meta( $candidate->ID, '_law_waitlist_position' ) !== $i + 1 ) {
			law_event_update_meta( $candidate->ID, '_law_waitlist_position', $i + 1 );
		}
	}

	law_booking_unlock( $event_id );

	$person = law_booking_attendee( $entry );
	law_event_log(
		$event_id,
		sprintf(
			'Waitlist reordered: %s moved from position %d to %d.',
			$person['name'] ?: $person['email'],
			$index + 1,
			$target + 1
		),
		array( 'action' => 'waitlist_reordered', 'booking' => (int) $entry->ID, 'from' => $index + 1, 'to' => $target + 1, 'source' => 'waitlist' ),
		array( 'user_id' => (int) $actor_id )
	);

	// A smaller wait at the front may now be seatable.
	$promoted = law_waitlist_process( $event_id, 'reorder' );

	return array( 'moved' => true, 'promoted' => array_map( 'intval', $promoted ) );
}

/* Untrash ____________________________________________________________________ */

/**
 * A waitlist entry restored from the trash goes to the BACK of the queue. Its
 * old position is meaningless by then — the people who were behind it have
 * moved up — and honouring it would jump the queue silently.
 */
add_action( 'untrashed_post', function ( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post instanceof WP_Post || LAW_BOOKING_CPT !== $post->post_type || 'law-waitlisted' !== $post->post_status ) {
		return;
	}
	$event_id = (int) $post->post_parent;
	if ( ! $event_id ) {
		return;
	}
	if ( ! law_booking_lock( $event_id ) ) {
		// Do not leave it holding a stale position that may now collide with
		// somebody else's: try again shortly rather than silently skipping.
		law_event_log(
			$event_id,
			sprintf( 'Restored waitlist entry #%d could not be requeued yet: the event was busy. It will be retried shortly.', (int) law_event_meta( $post->ID, '_law_booking_number' ) ),
			array( 'action' => 'waitlist_requeue_deferred', 'booking' => (int) $post->ID, 'source' => 'waitlist' )
		);
		law_waitlist_schedule_resume( $event_id );
		return;
	}
	delete_post_meta( $post->ID, '_law_waitlist_position' );
	law_event_update_meta( $post->ID, '_law_waitlist_position', law_waitlist_next_position( $event_id ) );
	// And close the gap the trashing left, or nobody holds position 1.
	law_waitlist_renumber( $event_id );
	law_booking_unlock( $event_id );
	law_event_log(
		$event_id,
		sprintf( 'Waitlist entry #%d restored from the trash and moved to the back of the queue.', (int) law_event_meta( $post->ID, '_law_booking_number' ) ),
		array( 'action' => 'waitlist_untrashed_to_back', 'booking' => (int) $post->ID, 'source' => 'waitlist' )
	);
} );

/* Admin-post handlers ________________________________________________________ */

add_action( 'admin_post_law_waitlist_join', 'law_waitlist_join_handler' );
add_action( 'admin_post_nopriv_law_waitlist_join', 'law_events_nopriv_json' );
add_action( 'admin_post_law_waitlist_reorder', 'law_waitlist_reorder_handler' );
add_action( 'admin_post_nopriv_law_waitlist_reorder', 'law_events_nopriv_json' );
add_action( 'admin_post_law_waitlist_promote', 'law_waitlist_promote_handler' );
add_action( 'admin_post_nopriv_law_waitlist_promote', 'law_events_nopriv_json' );

/** Join the waitlist for a full event (the sold-out control's form). */
function law_waitlist_join_handler() {
	$event_id = absint( $_POST['event_id'] ?? 0 );
	$link     = $event_id ? get_permalink( $event_id ) : home_url( '/account/events/' );
	$is_ajax  = law_events_guard_post(
		'law_waitlist_join',
		array(
			// The same budget as booking: joining a waitlist is the same act
			// from the same form, and two surfaces would double a bot's room.
			'rate'            => array( 'booking', 10, 600, 100 ),
			'honeypot_json'   => array( 'title' => "You're on the waitlist", 'message' => "We'll email you if a place becomes available.", 'redirect' => $link ),
			'honeypot_notice' => 'waitlist-joined',
		)
	);

	$rows   = wp_unslash( $_POST['law_attendees'] ?? array() );
	$result = law_waitlist_join( $event_id, get_current_user_id(), is_array( $rows ) ? $rows : array() );

	if ( is_wp_error( $result ) ) {
		if ( ! $is_ajax ) {
			law_booking_store_form_state( get_current_user_id(), $result, is_array( $rows ) ? $rows : array() );
		}
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'waitlist-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => "You're on the waitlist",
			'message'  => "We'll email you as soon as a place opens up.",
			'redirect' => add_query_arg( 'law_notice', 'waitlist-joined', $link ),
		),
		'waitlist-joined'
	);
}

/** Move one entry in the queue (host, co-owner or committee). */
function law_waitlist_reorder_handler() {
	$is_ajax = law_events_guard_post(
		'law_waitlist_reorder',
		array(
			'rate'            => array( 'waitlist_manage', 60, 600, 300 ),
			'honeypot_json'   => array( 'title' => 'Waitlist reordered', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'waitlist-reordered',
		)
	);

	$booking = law_booking_require_manageable( $is_ajax, 'waitlist-failed' );

	$result = law_waitlist_reorder(
		(int) $booking->ID,
		sanitize_key( (string) ( $_POST['direction'] ?? '' ) ),
		get_current_user_id(),
		absint( $_POST['expected_position'] ?? 0 )
	);
	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message() ), 'waitlist-failed' );
	}

	$moved  = ! empty( $result['moved'] );
	$notice = $moved ? 'waitlist-reordered' : 'waitlist-unchanged';
	/* The queue as it now stands, so booking-form.js can reorder the table in
	   place instead of reloading the whole list on every arrow click. Positions
	   are always 1..n here: law_waitlist_process() ends with a renumber. The
	   promoted IDs are the client's signal to reload instead — an entry that
	   has left the queue for the active table changes both tables and the
	   counts in their headings. */
	$order = array();
	foreach ( law_waitlist_for_event( (int) $booking->post_parent ) as $entry ) {
		$order[] = array(
			'id'       => (int) $entry->ID,
			'position' => (int) law_event_meta( $entry->ID, '_law_waitlist_position' ),
		);
	}
	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => $moved ? 'Waitlist reordered' : 'Waitlist unchanged',
			// No "Reloading the page…": whether it reloads is the client's call.
			'message'  => $moved
				? 'The waitlist order has been updated.'
				: 'The waitlist had already moved on, so nothing was changed. This is the current order.',
			'order'    => $order,
			'promoted' => array_values( (array) ( $result['promoted'] ?? array() ) ),
			'moved'    => $moved,
			'redirect' => add_query_arg( 'law_notice', $notice, law_booking_list_url( (int) $booking->post_parent ) ) . '#law-waitlist',
		),
		$notice
	);
}

/** Promote one entry by hand, even onto a full event. */
function law_waitlist_promote_handler() {
	$is_ajax = law_events_guard_post(
		'law_waitlist_promote',
		array(
			'rate'            => array( 'waitlist_manage', 60, 600, 300 ),
			'honeypot_json'   => array( 'title' => 'Attendee promoted', 'message' => 'Done.', 'redirect' => home_url( '/account/events/' ) ),
			'honeypot_notice' => 'waitlist-promoted',
		)
	);

	$booking = law_booking_require_manageable( $is_ajax, 'waitlist-failed' );

	$result = law_waitlist_promote( (int) $booking->ID, get_current_user_id() );
	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message() ), 'waitlist-failed' );
	}

	$message = 'They have been emailed their confirmation. Reloading the page…';
	if ( ! empty( $result['overbooked_by'] ) ) {
		$message = sprintf(
			_n(
				'They have been emailed their confirmation. The event is now over-booked by %d place. Reloading the page…',
				'They have been emailed their confirmation. The event is now over-booked by %d places. Reloading the page…',
				(int) $result['overbooked_by'],
				'law'
			),
			(int) $result['overbooked_by']
		);
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Attendee promoted',
			'message'  => $message,
			'redirect' => add_query_arg( 'law_notice', 'waitlist-promoted', law_booking_list_url( (int) $booking->post_parent ) ) . '#law-waitlist',
		),
		'waitlist-promoted'
	);
}

/* Places freed by a change to the event's ticket number _______________________ */

/**
 * The committee or host changing how many places an event has is the one way
 * places open without a booking being cancelled, and nothing used to fire on
 * it. Called from both save paths AFTER every other field is written, so a
 * save that moves the date and raises the places cannot email an invitation
 * with the old date on it.
 */
function law_event_tickets_changed( $event_id, $old, $new, $actor_id = 0, $source = 'admin' ) {
	$event_id = (int) $event_id;
	$old      = (int) $old;
	$new      = (int) $new;
	if ( $old === $new ) {
		return;
	}

	law_event_log(
		$event_id,
		sprintf( 'Places available changed: %d → %d.', $old, $new ),
		array( 'action' => 'tickets_changed', 'old' => $old, 'new' => $new, 'source' => $source ),
		array( 'user_id' => (int) $actor_id )
	);

	if ( $new > $old ) {
		law_waitlist_process( $event_id, 'tickets' );
	}
}
