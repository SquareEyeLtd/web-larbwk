<?php
/**
 * Post statuses for the module's own content. Events: core `publish` =
 * Confirmed (publicly listable), the moderation states are custom statuses
 * (EVENTS_4.1_REBUILD.md §3.1). Bookings have their own, smaller vocabulary
 * (WAITLIST.md §B1), deliberately kept out of the event map, which drives the
 * committee's event filters and the events admin list.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * status => [label, public?]. Order matters for the committee filter UI.
 */
function law_event_statuses() {
	return array(
		'law-draft'     => array( 'label' => 'Draft',     'public' => false ),
		'law-proposed'  => array( 'label' => 'Proposed',  'public' => false ),
		'law-sent-back' => array( 'label' => 'Sent back', 'public' => false ),
		// Approved is PUBLIC (Denis, 16 September 2026): an event the committee
		// has approved goes straight onto the programme carrying "Open soon",
		// and only pays its way to Confirmed afterwards. The flag is what
		// law_events_register_statuses() below turns into a status WordPress
		// will render a permalink for, and what law_calendar_public_statuses()
		// keys the programme's own filter on.
		'law-approved'  => array( 'label' => 'Approved',  'public' => true ),
		'publish'       => array( 'label' => 'Confirmed', 'public' => true ),
		'law-rejected'  => array( 'label' => 'Rejected',  'public' => false ),
		'law-cancelled' => array( 'label' => 'Cancelled', 'public' => false ),
	);
}

/**
 * Booking statuses. `publish` is a confirmed booking, `law-cancelled` is
 * shared with events (a post status is global, so it is registered once,
 * above), and the rest belong to bookings alone.
 *
 * The last three are the flagship conference's approval-gated application
 * flow (FLAGSHIP_PAYMENTS.md §2.1). They are deliberately NOT the waitlist:
 * `law-waitlisted` carries automatic first-in-first-out promotion the moment
 * a place frees, which is exactly what an approval gate cannot do, so an
 * application waits in `law-applied` until a human decides.
 *
 * Order matters: it drives the committee's status filters.
 *
 * @return array<string,string> status => label.
 */
function law_booking_statuses() {
	return array(
		'publish'             => 'Confirmed',
		'law-applied'         => 'Pending approval',
		// A priced reception's place while the delegate is on Stripe's hosted
		// page (RECEPTIONS.md §1.3). It HOLDS the place — it is in
		// law_booking_holding_statuses() and, on a priced event only, in the
		// recount — so two people cannot buy the last one at once. The hold is
		// released by checkout.session.expired, by the cancel return, or by the
		// hourly sweep ten minutes past the session's own expiry.
		'law-pending-payment' => 'Awaiting payment',
		'law-waitlisted'      => 'Waitlisted',
		'law-payment-failed'  => 'Payment failed',
		'law-declined'        => 'Declined',
		'law-cancelled'       => 'Cancelled',
	);
}

/**
 * The subset a flagship application can hold. Hosted-event surfaces keep the
 * smaller Confirmed / Waitlisted / Cancelled vocabulary, so only the flagship
 * views ever show "Pending approval", "Payment failed" or "Declined".
 *
 * @return array<string,string> status => label.
 */
function law_flagship_application_statuses() {
	$all = law_booking_statuses();
	return array(
		'law-applied'        => $all['law-applied'],
		'publish'            => $all['publish'],
		'law-payment-failed' => $all['law-payment-failed'],
		'law-declined'       => $all['law-declined'],
		'law-cancelled'      => $all['law-cancelled'],
	);
}

/**
 * The ticket types the committee can classify a flagship registration with
 * (the client's own list, 15 September 2026). Back-office only: it is never
 * shown to the delegate, never reaches Stripe and changes no price, capacity
 * or guard. It exists so the committee's own records and their exports can
 * say who a name in the list actually is.
 *
 * One list, read by the dashboard cell, the dialog, the filter, the exports,
 * the wp-admin box and the meta sanitiser, so the five cannot drift apart.
 * There is deliberately no default: a registration nobody has classified holds
 * nothing, and the column says "Add type" rather than claiming everyone is a
 * delegate.
 *
 * @return array<string,string> slug => label.
 */
function law_booking_ticket_types() {
	return array(
		'delegate'  => 'Delegate',
		'sponsor'   => 'Sponsor',
		'speaker'   => 'Speaker',
		'exhibitor' => 'Exhibitor',
		'committee' => 'Committee',
	);
}

/**
 * A ticket type's label, or '' when nothing is set or the slug is unknown.
 *
 * @param string $type A law_booking_ticket_types() key.
 */
function law_booking_ticket_type_label( $type ) {
	$types = law_booking_ticket_types();
	return $types[ (string) $type ] ?? '';
}

/**
 * Booking statuses that are registered by this module rather than by core.
 * One list, so registration and the untrash whitelist cannot drift.
 *
 * @return array<string,string> status => label.
 */
function law_booking_custom_statuses() {
	$statuses = law_booking_statuses();
	unset( $statuses['publish'], $statuses['law-cancelled'] );
	return $statuses;
}

/**
 * A booking's status label. Falls back to a readable form of an unknown
 * status rather than mislabelling it as active.
 *
 * @param string|WP_Post $status Post status or booking post.
 */
function law_booking_status_label( $status ) {
	if ( $status instanceof WP_Post ) {
		$status = $status->post_status;
	}
	$map = law_booking_statuses();
	return $map[ $status ] ?? ucfirst( str_replace( array( 'law-', '-' ), array( '', ' ' ), (string) $status ) );
}

function law_events_register_statuses() {
	// The booking-only statuses must be REGISTERED before anything queries
	// them: WP_Query silently drops an unknown post_status, leaving no status
	// clause at all, which would return every booking of every status.
	$statuses = law_event_statuses();
	foreach ( law_booking_custom_statuses() as $status => $label ) {
		if ( ! isset( $statuses[ $status ] ) ) {
			$statuses[ $status ] = array( 'label' => $label, 'public' => false );
		}
	}

	foreach ( $statuses as $status => $config ) {
		if ( 'publish' === $status ) {
			continue;
		}
		// A status the module calls public is one WordPress must be willing to
		// render: Approved is the only one, and three args are needed together
		// for it, not one.
		//
		//  - public: WP_Query::get_posts() empties a singular result for a
		//    logged-out visitor whenever the status object is not public, so
		//    without this the permalink is a 404 however the programme links it.
		//  - publicly_queryable + protected => false: is_post_status_viewable()
		//    refuses a protected status and, for a status that is not built in,
		//    reads publicly_queryable rather than public. It is what
		//    wp_force_plain_post_permalink() asks, so without the pair
		//    get_permalink() hands back ?post_type=law_event&p=123 instead of
		//    the pretty URL.
		//
		// exclude_from_search stays true on every status, and the CPT itself is
		// registered exclude_from_search, so none of this puts an unconfirmed
		// event into site search.
		$public = ! empty( $config['public'] );
		register_post_status(
			$status,
			array(
				'label'                     => $config['label'],
				'public'                    => $public,
				'publicly_queryable'        => $public,
				'internal'                  => false,
				'protected'                 => ! $public,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => _n_noop(
					$config['label'] . ' <span class="count">(%s)</span>',
					$config['label'] . ' <span class="count">(%s)</span>'
				),
			)
		);
	}
}
add_action( 'init', 'law_events_register_statuses', 6 );

/**
 * The committee-facing label for a status ("Confirmed" for publish),
 * matching the old field 95 (Event status) vocabulary.
 *
 * @param string|WP_Post $status Post status or event post.
 */
function law_event_status_label( $status ) {
	if ( $status instanceof WP_Post ) {
		$status = $status->post_status;
	}
	$statuses = law_event_statuses();
	return $statuses[ $status ]['label'] ?? ucfirst( (string) $status );
}

/**
 * Whether this event has been approved, whatever it has done since.
 *
 * Read from the STATUS first, not from `_law_approved_at`. The timestamp was
 * the original test, and it is wrong for every migrated event: form 2 (Event >
 * submit an event) field 78 (Approval date) is empty on all 500 production
 * entries, so the key is absent on all 90 approved and Confirmed events the
 * migration created, and anything gated on it silently came unlocked. That is
 * how the post-approval fee-override lock (now law_event_fee_edit_mode())
 * was off across the whole migrated programme (audit, 16 September 2026).
 *
 * `law-approved` and `publish` say it themselves. `law-cancelled` is the one
 * status that cannot: law_event_transitions() reaches it from BOTH `cancel`
 * (from approved or Confirmed, so it was approved) and `withdraw` (from draft,
 * proposed or sent back, so it never was). The timestamp separates those two
 * and is reliable there, because a cancellation can only have happened on this
 * site, after the workflow started writing the key. Migration never produces a
 * cancelled event at all: the legacy vocabulary had no such status.
 *
 * @param int $event_id law_event post ID.
 * @return bool
 */
function law_event_has_been_approved( $event_id ) {
	if ( in_array( get_post_status( $event_id ), array( 'law-approved', 'publish' ), true ) ) {
		return true;
	}
	return '' !== (string) law_event_meta( $event_id, '_law_approved_at' );
}

/** Old field 95 (Event status) value → new post status. */
function law_event_status_from_legacy( $legacy ) {
	$map = array(
		'Proposed'  => 'law-proposed',
		'Sent back' => 'law-sent-back',
		'Approved'  => 'law-approved',
		'Confirmed' => 'publish',
		'Rejected'  => 'law-rejected',
	);
	return $map[ trim( (string) $legacy ) ] ?? 'law-proposed';
}

/** All law_event statuses, for admin/committee queries. */
function law_event_all_status_keys() {
	return array_keys( law_event_statuses() );
}

/**
 * Status KEYS for a set of committee-facing status LABELS.
 *
 * The calendar layer speaks labels, because the legacy Gravity Forms source
 * stored field 95 (Event status) as prose and the two sources share
 * law_calendar_public_statuses(). A get_posts() call needs keys. Derived from
 * the one status table above so the two vocabularies cannot drift, and an
 * unknown label is dropped rather than guessed at.
 *
 * @param string[] $labels e.g. array( 'Confirmed', 'Approved' ).
 * @return string[] e.g. array( 'law-approved', 'publish' ).
 */
function law_event_status_keys_for_labels( array $labels ) {
	$keys = array();
	foreach ( law_event_statuses() as $key => $config ) {
		if ( in_array( $config['label'], $labels, true ) ) {
			$keys[] = $key;
		}
	}
	return $keys;
}

/**
 * Is this event on the PUBLIC programme, as opposed to visible only to the
 * committee: Confirmed, or Approved and waiting for its host to pay?
 *
 * One predicate, because three questions turn on it and each used to test
 * 'publish' for itself: which statuses the programme query asks for, whether
 * an event's link is its permalink or the committee's ?event= view, and
 * whether a committee screen offers "View event" or "Preview event".
 *
 * @param int|WP_Post $event Event ID or post.
 */
function law_event_is_publicly_listed( $event ) {
	$post = get_post( $event );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return false;
	}
	// The committee's off switch outranks the status, which is the whole point
	// of it: a disabled event is hidden "no matter what" (Denis, 17 September
	// 2026). Answered HERE rather than at each surface so that everything
	// already keyed on this predicate -- the programme card's link, the .ics
	// feed, the {event_link} tag, the forced-open booking answer -- is covered
	// by the one tick.
	if ( law_event_is_disabled( $post ) ) {
		return false;
	}
	$statuses = law_event_statuses();
	return 'publish' === $post->post_status
		|| ! empty( $statuses[ $post->post_status ]['public'] );
}

/**
 * Has the committee switched this event off altogether?
 *
 * The one checkbox at the top of the Committee controls panel (Denis,
 * 17 September 2026). It is not a status and not a booking answer: the event
 * keeps the status it had, keeps its invoice, its bookings and its rows in the
 * committee's own lists, and unticking the box puts it back exactly where it
 * was. What it does is take the event off every PUBLIC surface -- the
 * programme, its own page, the .ics feed -- and hold booking shut, whatever
 * the status, the slot or Override booking availability say.
 *
 * Cancelled remains the status for an event that is not happening; this is for
 * one that must not be seen yet, or at all, while the record stays intact.
 *
 * @param int|WP_Post $event Event ID or post.
 */
function law_event_is_disabled( $event ) {
	$post = get_post( $event );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return false;
	}
	return (bool) law_event_meta( $post->ID, '_law_disabled' );
}

/**
 * Keep custom-status events visible and editable on the admin list and edit
 * screens (WP hides unknown statuses from some queries by default).
 */
add_filter(
	'display_post_states',
	function ( $states, $post ) {
		if ( $post instanceof WP_Post && LAW_EVENT_CPT === $post->post_type ) {
			$known = law_event_statuses();
			if ( isset( $known[ $post->post_status ] ) && 'publish' !== $post->post_status ) {
				$states[ 'law_status' ] = $known[ $post->post_status ]['label'];
			}
		}
		return $states;
	},
	10,
	2
);
