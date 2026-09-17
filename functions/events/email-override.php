<?php
/**
 * The per-event booking confirmation.
 *
 * One event can say its own thing without touching the wording every other
 * event on the programme sends. The committee ticks "Override booking
 * confirmation" on the event, follows the link that appears, and writes that
 * event's confirmation here. The registry entry, the recipients and the .ics
 * invitation are all unchanged: only the subject and body swap, and only for
 * this event, resolved in law_events_email() (functions/events/notifications.php).
 *
 * A reception carries TWO bodies rather than one. Its confirmation is genuinely
 * two templates, user_reception_confirmed and user_reception_confirmed_free,
 * because the paid wording quotes an amount and links a VAT invoice and a place
 * with nothing to pay has neither. Collapsing them is what produced "You paid
 * £0.00" above an empty invoice link, fixed 16 September 2026, so the override
 * keeps the split. A hosted event has no such split: its one body reaches the
 * person who booked and the colleagues they brought alike, which is the whole
 * point of the feature.
 *
 * Unlike the site-wide Emails screen, which deliberately logs nothing because
 * wording belongs to no event, every save here IS logged on the event. This
 * wording belongs to exactly one.
 *
 * @package larbwk
 */

defined( 'ABSPATH' ) || exit;

/* Reading what is stored ____________________________________________________ */

/**
 * The wording this event would send, falling back to what it would send today.
 *
 * An override that has never been written shows the standard wording in the
 * editor, so the committee starts from the real thing and edits it rather than
 * facing an empty box. The fallback reads through law_events_email(), so a
 * site-wide override already in force is what they start from, not the shipped
 * text underneath it.
 *
 * @param int $event_id law_event post ID.
 * @return array which ('main'|'free') => array( slug, name, subject, body ).
 */
function law_event_override_fields( $event_id ) {
	$event_id = (int) $event_id;
	$fields   = array();

	foreach ( law_event_override_slug_map( $event_id ) as $slug => $which ) {
		if ( isset( $fields[ $which ] ) ) {
			continue;
		}
		$standard = law_events_email( $slug );
		$stored   = law_event_override_wording( $event_id, $which );
		$fields[ $which ] = array(
			'slug'    => $slug,
			'name'    => $standard ? $standard['name'] : $slug,
			'subject' => '' !== trim( $stored['subject'] ) ? $stored['subject'] : (string) ( $standard['subject'] ?? '' ),
			'body'    => '' !== trim( $stored['body'] ) ? $stored['body'] : (string) ( $standard['body'] ?? '' ),
		);
	}

	return $fields;
}

/** Has this event had wording written for it at all? */
function law_event_override_written( $event_id ) {
	foreach ( array( 'main', 'free' ) as $which ) {
		$stored = law_event_override_wording( $event_id, $which );
		if ( '' !== trim( $stored['subject'] ) || '' !== trim( $stored['body'] ) ) {
			return true;
		}
	}
	return false;
}

/* The refused-save draft ____________________________________________________ */

/*
 * A refusal must not cost somebody the email they just wrote. The same one-shot
 * transient the Emails screen uses (law_emails_dashboard_state()), keyed by
 * event instead of by slug.
 */

/** Take the stored draft for this event, clearing it. */
function law_event_override_take_state( $event_id ) {
	$key   = 'law_email_override_' . get_current_user_id();
	$state = get_transient( $key );
	delete_transient( $key );
	if ( ! is_array( $state ) || (int) ( $state['event_id'] ?? 0 ) !== (int) $event_id ) {
		return array();
	}
	return is_array( $state['values'] ?? null ) ? $state['values'] : array();
}

/** Keep what was typed across a refused save. */
function law_event_override_store_state( $event_id, array $values ) {
	set_transient(
		'law_email_override_' . get_current_user_id(),
		array( 'event_id' => (int) $event_id, 'values' => $values ),
		5 * MINUTE_IN_SECONDS
	);
}

/* Notices ___________________________________________________________________ */

/** law_notice value => array( css class, message ). */
function law_event_override_notices() {
	return array(
		'email-override-saved'   => array( 'is-success', __( 'This event\'s booking confirmation has been saved.', 'law' ) ),
		'email-override-reset'   => array( 'is-success', __( 'This event is back to the standard booking confirmation.', 'law' ) ),
		'email-override-empty'   => array( 'is-error', __( 'Please write the message before saving.', 'law' ) ),
		'email-override-denied'  => array( 'is-error', __( 'Sorry, editing an event\'s booking confirmation is for the committee.', 'law' ) ),
		'email-override-missing' => array( 'is-error', __( 'That event could not be found, or it cannot have its own confirmation.', 'law' ) ),
	);
}

/* Saving ____________________________________________________________________ */

add_action( 'admin_post_law_event_email_override', 'law_event_email_override_handler' );
add_action( 'admin_post_nopriv_law_event_email_override', 'law_events_nopriv_json' );

function law_event_email_override_handler() {
	// Its own rate surface, for the same reason the Emails screen has one: a
	// committee member working through the wording of several events must not
	// spend the event-submission budget.
	$is_ajax = law_events_guard_post(
		'law_event_email_override',
		array(
			'rate'            => array( 'email_override', 60, 600, 300 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'email-override-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, editing an event\'s booking confirmation is for the committee.', 'status' => 403 ),
			'email-override-denied'
		);
	}

	$event_id = absint( $_POST['law_event_id'] ?? 0 );
	$map      = law_event_override_slug_map( $event_id );
	if ( ! $map ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'That event could not be found, or it cannot have its own confirmation.', 'status' => 404 ),
			'email-override-missing'
		);
	}
	$back = law_event_override_url( $event_id );

	// Reset first: it discards the form's values rather than reading them. The
	// tick is left alone, because turning the override off is the committee
	// panel's switch, not this one; clearing the wording here and leaving the
	// tick on simply means the standard wording sends again.
	if ( ! empty( $_POST['law_email_override_reset'] ) ) {
		foreach ( array( '', '_free' ) as $suffix ) {
			law_event_update_meta( $event_id, '_law_email_override_subject' . $suffix, '' );
			law_event_update_meta( $event_id, '_law_email_override_body' . $suffix, '' );
		}
		law_event_log(
			$event_id,
			'Custom booking confirmation cleared; this event sends the standard wording.',
			array( 'action' => 'email_override_reset', 'source' => 'ui' ),
			array( 'user_id' => get_current_user_id() )
		);
		law_events_respond(
			$is_ajax,
			true,
			array(
				'title'    => 'Back to the standard wording',
				'message'  => 'This event is back to the standard booking confirmation.',
				'redirect' => law_committee_event_url( $event_id ),
			),
			'email-override-reset'
		);
	}

	$posted = (array) ( wp_unslash( $_POST['law_email_override'] ?? array() ) );
	$values = array();
	foreach ( array_unique( array_values( $map ) ) as $which ) {
		$row = (array) ( $posted[ $which ] ?? array() );
		$values[ $which ] = array(
			'subject' => (string) ( $row['subject'] ?? '' ),
			'body'    => (string) ( $row['body'] ?? '' ),
		);
	}

	// An empty body is refused rather than stored, exactly as on the Emails
	// screen: a saved blank would send a blank confirmation to every person who
	// books this event. Checked after the tag stripping wp_strip_all_tags()
	// does, so a body of nothing but an empty paragraph counts as empty.
	foreach ( $values as $row ) {
		if ( '' === trim( wp_strip_all_tags( $row['body'] ) ) || '' === trim( $row['subject'] ) ) {
			law_event_override_store_state( $event_id, $values );
			law_events_respond(
				$is_ajax,
				false,
				array( 'message' => 'Please write both a subject and a message before saving.', 'status' => 400, 'redirect' => $back ),
				'email-override-empty'
			);
		}
	}

	$before = array();
	foreach ( $values as $which => $row ) {
		$before[ $which ] = law_event_override_wording( $event_id, $which );
		$suffix           = 'free' === $which ? '_free' : '';
		law_event_update_meta( $event_id, '_law_email_override_subject' . $suffix, $row['subject'] );
		law_event_update_meta( $event_id, '_law_email_override_body' . $suffix, $row['body'] );
	}

	// One line naming what actually moved, the way law_reception_log_save()
	// does, rather than "saved" on every press of the button.
	$changed = array();
	foreach ( $values as $which => $row ) {
		$after = law_event_override_wording( $event_id, $which );
		$label = 'free' === $which ? 'nothing-to-pay ' : '';
		if ( $before[ $which ]['subject'] !== $after['subject'] ) {
			$changed[] = $label . 'subject';
		}
		if ( $before[ $which ]['body'] !== $after['body'] ) {
			$changed[] = $label . 'message';
		}
	}
	if ( $changed ) {
		law_event_log(
			$event_id,
			sprintf( 'Custom booking confirmation edited (%s).', implode( ', ', $changed ) ),
			array( 'action' => 'email_override_saved', 'source' => 'ui' ),
			array( 'user_id' => get_current_user_id() )
		);
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Saved',
			'message'  => 'This event\'s booking confirmation has been saved.',
			'redirect' => law_committee_event_url( $event_id ),
		),
		'email-override-saved'
	);
}
