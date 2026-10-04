<?php
/**
 * The WordPress events that mark a person as needing a sync
 * (_docs/HUBSPOT_SYNC.md §6.4).
 *
 * Every callback here does one thing: law_hubspot_enqueue( $email, $reason ).
 * Nothing talks to HubSpot inline, so a registration, a booking or an approval
 * never waits on the API or fails because of it, and several changes to one
 * person in a minute coalesce into one push.
 *
 * Hooked as generically as possible (post status transitions, user meta
 * writes, the co-owner setter's own action) so a new code path cannot bypass
 * the sync without also bypassing WordPress.
 *
 * Each callback checks the mode itself rather than the file being skipped
 * when off, so flipping LAW_HUBSPOT_MODE needs no cache clear and tests can
 * turn the hooks on with the mode filter.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Enqueue a user by ID, if the module is on and the account has an address. */
function law_hubspot_enqueue_user( $user_id, $reason ) {
	if ( ! law_hubspot_enabled() ) {
		return false;
	}
	$user = get_user_by( 'id', (int) $user_id );
	return $user ? law_hubspot_enqueue( $user->user_email, $reason ) : false;
}

/** Enqueue a speaker record by post ID. */
function law_hubspot_enqueue_speaker( $speaker_id, $reason ) {
	if ( ! law_hubspot_enabled() ) {
		return false;
	}
	return law_hubspot_enqueue( (string) get_post_meta( (int) $speaker_id, '_law_speaker_email', true ), $reason );
}

/** Everyone an event's approval state touches: host, co-owners, speakers. */
function law_hubspot_enqueue_event_people( $event_id, $reason ) {
	if ( ! law_hubspot_enabled() ) {
		return;
	}
	$event_id = (int) $event_id;
	$post     = get_post( $event_id );
	if ( ! $post ) {
		return;
	}
	law_hubspot_enqueue_user( $post->post_author, $reason );
	foreach ( (array) get_post_meta( $event_id, '_law_co_owner_ids', true ) as $user_id ) {
		law_hubspot_enqueue_user( $user_id, $reason );
	}
	law_hubspot_enqueue_speaker_rows( (array) get_post_meta( $event_id, '_law_speakers', true ), $reason );
	if ( function_exists( 'law_event_session_ids' ) ) {
		foreach ( law_event_session_ids( $event_id ) as $session_id ) {
			law_hubspot_enqueue_speaker_rows( (array) get_post_meta( $session_id, '_law_speakers', true ), $reason );
		}
	}
}

/** The speakers in a set of `_law_speakers` rows. */
function law_hubspot_enqueue_speaker_rows( array $rows, $reason ) {
	foreach ( $rows as $row ) {
		if ( is_array( $row ) && ! empty( $row['speaker_id'] ) ) {
			law_hubspot_enqueue_speaker( $row['speaker_id'], $reason );
		}
	}
}

/* ---- accounts ---------------------------------------------------------- */

add_action( 'user_register', fn( $user_id ) => law_hubspot_enqueue_user( $user_id, 'user_register' ) );

// profile_update covers wp_update_user() paths. An email change arrives here
// with the NEW address on the account; the stored HubSpot ID keeps it on the
// same contact (sync.php reads by ID first).
add_action( 'profile_update', fn( $user_id ) => law_hubspot_enqueue_user( $user_id, 'profile_update' ) );

// The custom registration and profile forms write their fields with
// update_user_meta(), which fires no profile_update. Watch the keys the rules
// read instead (HUBSPOT_SYNC.md §10.9).
function law_hubspot_watched_user_meta_keys() {
	return array( 'first_name', 'last_name', 'organisation', 'job_title', 'country' );
}
function law_hubspot_on_user_meta( $meta_id, $user_id, $meta_key ) {
	if ( in_array( (string) $meta_key, law_hubspot_watched_user_meta_keys(), true ) ) {
		law_hubspot_enqueue_user( $user_id, 'profile_meta' );
	}
}
add_action( 'added_user_meta', 'law_hubspot_on_user_meta', 10, 3 );
add_action( 'updated_user_meta', 'law_hubspot_on_user_meta', 10, 3 );

// delete_user: nothing. Removing contacts is for LAW to do by hand (§6.4).

/* ---- bookings and events ---------------------------------------------- */

function law_hubspot_on_transition_post_status( $new_status, $old_status, $post ) {
	if ( ! $post instanceof WP_Post || $new_status === $old_status || ! law_hubspot_enabled() ) {
		return;
	}

	if ( LAW_BOOKING_CPT === $post->post_type ) {
		// Any change: into publish is a new attendee, out of it is a cancelled
		// place (which phase 3 removes from law_events_attending).
		if ( function_exists( 'law_booking_attendee_email' ) ) {
			law_hubspot_enqueue( law_booking_attendee_email( $post ), 'booking_' . sanitize_key( $new_status ) );
		}
		return;
	}

	if ( LAW_EVENT_CPT === $post->post_type ) {
		$approved = (array) law_hubspot_setting( 'approved_statuses', array() );
		if ( in_array( $new_status, $approved, true ) || in_array( $old_status, $approved, true ) ) {
			law_hubspot_enqueue_event_people( $post->ID, 'event_' . sanitize_key( $new_status ) );
		}
	}
}
add_action( 'transition_post_status', 'law_hubspot_on_transition_post_status', 10, 3 );

// Co-owners added or removed, from the setter's own action (co-owners.php).
add_action(
	'law_event_co_owners_set',
	function ( $event_id, array $ids, array $previous ) {
		foreach ( array_unique( array_merge( $ids, $previous ) ) as $user_id ) {
			law_hubspot_enqueue_user( $user_id, 'co_owner' );
		}
	},
	10,
	3
);

// Post meta the rules read: the fee tier (sponsor tag) and the speaker rows
// (speaker tag) on events and sessions, and a speaker record's email, which
// law_speaker_upsert() writes AFTER the post is inserted (so save_post alone
// would see a new speaker with no address) and the dashboards can change.
// New values only; a speaker taken off an event keeps the tag, which add-only
// would keep anyway.
function law_hubspot_on_post_meta( $meta_id, $post_id, $meta_key, $value ) {
	if ( ! law_hubspot_enabled() ) {
		return;
	}
	if ( '_law_speakers' === $meta_key ) {
		law_hubspot_enqueue_speaker_rows( (array) maybe_unserialize( $value ), 'speakers_changed' );
		return;
	}
	if ( '_law_speaker_email' === $meta_key ) {
		law_hubspot_enqueue( (string) $value, 'speaker_email' );
		return;
	}
	if ( '_law_fee_tier' === $meta_key && LAW_EVENT_CPT === get_post_type( $post_id ) ) {
		law_hubspot_enqueue_event_people( $post_id, 'fee_tier' );
	}
}
add_action( 'added_post_meta', 'law_hubspot_on_post_meta', 10, 4 );
add_action( 'updated_post_meta', 'law_hubspot_on_post_meta', 10, 4 );

/* ---- speakers ---------------------------------------------------------- */

add_action(
	'save_post_' . LAW_SPEAKER_CPT,
	function ( $post_id, $post ) {
		if ( $post instanceof WP_Post && 'auto-draft' !== $post->post_status ) {
			law_hubspot_enqueue_speaker( $post_id, 'speaker_save' );
		}
	},
	10,
	2
);
