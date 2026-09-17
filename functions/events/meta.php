<?php
/**
 * The meta schema (EVENTS_4.1_REBUILD.md §3.1). One sanitiser per key; the
 * admin screens and front-end forms both write through law_event_update_meta()
 * so there is a single validation path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * law_event meta schema: key => sanitiser id.
 * Sanitiser ids resolve in law_events_sanitize_value().
 */
function law_event_meta_schema() {
	return array(
		'_law_reference'            => 'text',
		'_law_start'                => 'datetime',
		'_law_end'                  => 'datetime',
		'_law_slot_label'           => 'text',      // The chosen slot label, for display parity.
		'_law_preferred_slots'      => 'text_array',
		'_law_venue'                => 'text',
		'_law_venue_needed'         => 'venue_needed',
		'_law_venue_capacity'       => 'text',
		'_law_tickets_available'    => 'int',
		'_law_tickets_sold'         => 'int',  // Recalculated by law_event_recount_attendees().
		'_law_capacity_warned'      => 'flag', // One-shot nearly-full warning latch.
		'_law_capacity_full_warned' => 'flag', // One-shot sold-out warning latch.
		'_law_host_organisations'   => 'text',
		'_law_organisation_ids'     => 'int_array',
		'_law_fee_tier'             => 'fee_tier',
		'_law_fee_pence'            => 'int',
		'_law_vat'                  => 'flag',
		'_law_fee_override'         => 'flag',
		'_law_fee_override_amount'  => 'float',
		'_law_invoice_name'         => 'text',
		'_law_invoice_email'        => 'email',
		'_law_invoice_address'      => 'address',
		'_law_country_iso'          => 'iso',
		'_law_vat_number'           => 'text',
		'_law_stripe_customer_id'   => 'text',
		'_law_stripe_invoice_id'    => 'text',
		'_law_stripe_invoice_url'   => 'url',
		'_law_stripe_error'         => 'stripe_error',
		'_law_payment_status'       => 'payment_status',
		'_law_approved_at'          => 'text',
		'_law_assignee'             => 'int',
		'_law_contacts'             => 'people_rows',
		'_law_co_owner_rows'        => 'people_rows',
		'_law_co_owner_ids'         => 'int_array',
		'_law_speakers'             => 'speaker_rows',
		'_law_registration_state'   => 'registration_state',
		// Committee-only classification switches (Denis, 9 September 2026).
		// _law_is_external: a third party runs this event and takes its
		// bookings on its own website, and the committee curates it onto the
		// programme (functions/events/external-events.php). Absent or 0 means
		// hosted, which is every host submission, so nothing is written at
		// submission time and the "hosted" filter has to treat a missing key as
		// false.
		//
		// This key was _law_is_law_event until 15 September 2026, when it meant
		// the opposite: "LAW runs this itself". Nothing distinguished LAW's own
		// events in behaviour — law_event_is_managed_by_law() reads the flagship
		// and reception flags, never this one — so the switch was only ever an
		// identity tag, and the identity the committee actually needed to tag
		// was the external one (Denis, 15 September 2026). The polarity is
		// inverted, not preserved: the three receptions that used to set the old
		// key no longer set anything here.
		//
		// _law_external_url: where an external event's Register button goes.
		// Empty is a legitimate state, not a defect — the committee lists an
		// event before its organiser opens registration — and the listing then
		// shows a disabled "Registration opening soon" button rather than
		// nothing at all.
		//
		// _law_session_agenda: this event has a session-level agenda, which is
		// what puts the Session agenda section on the event form
		// (law_event_has_session_agenda() in submission-form.php).
		//
		// _law_booking_override: the committee's hand on the booking switch
		// (client, 16 September 2026). 'auto' follows the rules -- paid, places
		// released, venue recorded -- while 'disable' shows "Open soon" however
		// complete the event is and 'enable' opens booking on an event that is
		// missing its venue or has not paid yet, for the high-profile event LAW
		// wants promoted now. Not a flag, because the three answers are not two:
		// "the committee has not touched this" has to be distinguishable from
		// "the committee decided to leave it open", or a later rule change would
		// silently reinterpret every event nobody has looked at.
		//
		// _law_disabled: the committee's off switch for a whole event (Denis,
		// 17 September 2026). It takes the event off the public programme, off
		// its own public page and out of the .ics feed whatever its status,
		// slot or booking answer says, which is what "no matter what" means:
		// it is read by law_event_is_publicly_listed(), so every surface that
		// already asks that question asks this one too. Deliberately NOT a
		// status: a disabled event is still Confirmed or Approved, keeps its
		// invoice, its bookings and its place in the committee's own lists, and
		// ticking the box back off puts it where it was. Cancelled is the
		// status for an event that is not happening.
		'_law_is_external'          => 'flag',
		'_law_external_url'         => 'url',
		'_law_session_agenda'       => 'flag',
		'_law_booking_override'     => 'booking_override',
		'_law_disabled'             => 'flag',
		// The flagship conference (functions/events/flagship.php). Exactly one
		// law_event post carries _law_is_flagship; it is edited on the Flagship
		// screen, its date is fixed (2 December by default) and its _law_start /
		// _law_end are DERIVED from its sessions, so _law_slot_label stays empty
		// and law_event_apply_slot_label() is never called for it.
		// _law_hero_image_id is the optional banner/preview photograph, used by
		// both the programme block and the hero on the flagship page.
		'_law_is_flagship'          => 'flag',
		'_law_flagship_date'        => 'date',
		// The drinks receptions (RECEPTIONS.md §1.1). A reception is an
		// ordinary law_event carrying _law_is_reception, so it gets the
		// programme, the event page, the .ics, the bookings engine, capacity,
		// the per-event list and the exports for free; these three are the
		// only things about it that are new.
		//
		// _law_attendee_price_pence is NET of VAT, in pence, and 0 means "not
		// on sale". It is named to sit beside _law_fee_pence (the HOST fee)
		// and _law_flagship_price_pence rather than reusing _law_price_pence,
		// which is the BOOKING's price snapshot and must not be shadowed by an
		// event key of the same name.
		//
		// _law_flagship_included: a confirmed flagship delegate gets a place
		// here at no cost (RECEPTIONS.md §2.6). Invitation-only is not a flag
		// of its own: it is _law_registration_state = 'invitation' below.
		'_law_is_reception'         => 'flag',
		'_law_attendee_price_pence' => 'int',
		'_law_flagship_included'    => 'flag',
		'_law_hero_image_id'        => 'int',
		// Flagship pricing (FLAGSHIP_PAYMENTS.md §2.2). Both prices are NET of
		// VAT, in pence, and the switch is a site-local 'Y-m-d H:i' compared
		// through wp_timezone() — never strtotime() on a bare string, or the
		// BST/GMT boundary moves the cutover by an hour. They live on the event
		// rather than in law_events_settings because the committee edits them
		// on the Manage flagship form, and a future reception will want its own
		// pair rather than a site-wide one.
		'_law_flagship_price_pence'      => 'int',
		'_law_flagship_price_late_pence' => 'int',
		'_law_flagship_price_switch'     => 'datetime',
		// The per-event booking confirmation (EVENTS_FUNC.md). When
		// _law_email_override is on, law_events_email() swaps this event's
		// subject and body in place of the registry's confirmation templates,
		// so ONE event can say its own thing without touching the wording every
		// other event on the programme sends.
		//
		// The _free pair is receptions only. A reception's confirmation is
		// genuinely two templates (user_reception_confirmed and
		// user_reception_confirmed_free), because the paid wording quotes an
		// amount and links a VAT invoice and a place with nothing to pay has
		// neither; collapsing them is what produced "You paid £0.00" above an
		// empty invoice link, fixed 16 September 2026. The override keeps the
		// split rather than reintroducing it. A hosted event has no such split:
		// its one body serves the booker and the colleagues alike.
		//
		// 'rich' rather than 'multiline': the body carries allowlisted markup,
		// and sanitize_textarea_field() would strip it.
		'_law_email_override'              => 'flag',
		'_law_email_override_subject'      => 'text',
		'_law_email_override_body'         => 'rich',
		'_law_email_override_subject_free' => 'text',
		'_law_email_override_body_free'    => 'rich',
		'_law_gf_entry_id'          => 'int',
		'_law_rejection_reason'     => 'multiline',
		'_law_cancellation_reason'  => 'multiline',
		'_law_sector_jurisdiction'  => 'text',
		'_law_sector_other'         => 'text',
		'_law_terms_consent'        => 'consent',
	);
}

/**
 * law_speaker meta schema: identity only. Organisation, job title, photo and
 * biography are per appearance and live on the event's _law_speakers rows
 * (the featured image and the post's editor content are only fallbacks). The
 * old _law_organisation and _law_job_title rows left in the database are
 * inert.
 */
function law_speaker_meta_schema() {
	return array(
		// The name in two parts (Denis, 9 September 2026). post_title stays the
		// display name every listing prints and every lookup matches on; these
		// are the structured form the archive sorts by and the forms edit.
		'_law_speaker_first_name' => 'text',
		'_law_speaker_last_name'  => 'text',
		'_law_speaker_email'      => 'email',
		'_law_website'            => 'url',
		'_law_organisation_ids'   => 'int_array', // Reserved for 4.2 additional organisations.
		'_law_gf_entry_id'        => 'int',
		'_law_gf_entry_ids'       => 'int_array', // All merged source child entry IDs.
	);
}

/** law_session meta schema. */
function law_session_meta_schema() {
	return array(
		'_law_start_time'  => 'time',
		'_law_end_time'    => 'time',
		'_law_speakers'    => 'speaker_rows',
		'_law_gf_entry_id' => 'int',
	);
}

/**
 * law_booking meta schema (WAITLIST.md §A7). ONE BOOKING PER ATTENDEE: the
 * post's author is the attendee and these keys are their display snapshot as
 * entered at booking, plus who booked them. Dietary, accessibility and
 * country are never snapshotted — they read live from the attendee's profile.
 *
 * _law_booked_by is always set: the booker's user ID on a colleague's
 * booking, and the attendee's own ID when they booked themselves or a manager
 * registered them (a manager's own bookings list must never fill with people
 * they registered; who acted is in the activity log).
 */
function law_booking_meta_schema() {
	return array(
		'_law_booking_number'         => 'int',
		'_law_booked_by'              => 'int',
		'_law_attendee_name'          => 'text',
		'_law_attendee_email'         => 'email',
		'_law_attendee_organisation'  => 'text',
		'_law_attendee_job_title'     => 'text',
		'_law_is_press'               => 'flag',
		// The waitlist (WAITLIST.md §B1). Position is 1-based, so 0 never
		// means "first"; it is cleared with delete_post_meta(), because the
		// int sanitiser stores 0 rather than deleting.
		'_law_waitlist_position'      => 'int',
		'_law_waitlist_joined'        => 'datetime',
		'_law_waitlist_promoted'      => 'datetime',
		'_law_waitlist_blocked'       => 'text',
		// The flagship conference's approval-gated application and payment
		// (FLAGSHIP_PAYMENTS.md §2.4). None of these is ever written on a
		// hosted-event booking.
		'_law_application_at'         => 'datetime',
		'_law_application_answers'    => 'answer_map',
		// The price is snapshotted when the delegate saves their card, not read
		// again at approval: they consented to a figure, so that is the figure
		// charged, exactly as law_event_snapshot_fee() freezes a host fee.
		'_law_price_pence'            => 'int',
		'_law_vat'                    => 'flag',
		'_law_payment_consent_at'     => 'datetime',
		'_law_stripe_customer_id'     => 'text',
		'_law_stripe_setup_intent_id' => 'text',
		'_law_stripe_payment_method_id' => 'text',
		// Checkout in setup mode offers whatever the Stripe account has
		// enabled that can be saved and re-charged off-session, which on this
		// account is card, Link and Revolut Pay. So the type and a rendered
		// label are stored alongside the card fields, and the card fields are
		// filled in only when the delegate actually saved a card.
		'_law_stripe_method_type'     => 'text',
		'_law_stripe_method_label'    => 'text',
		'_law_stripe_card_brand'      => 'text',
		'_law_stripe_card_last4'      => 'text',
		'_law_stripe_card_exp'        => 'text',
		'_law_stripe_invoice_id'      => 'text',
		'_law_stripe_invoice_url'     => 'url',
		'_law_stripe_invoice_pdf'     => 'url',
		'_law_stripe_charge_id'       => 'text',
		'_law_payment_status'         => 'booking_payment_status',
		'_law_payment_error'          => 'text',
		'_law_payment_failed_at'      => 'datetime',
		// When a charge Stripe accepted but has not settled began, so the
		// daily sweep can alert on one that never resolves.
		'_law_payment_processing_at'  => 'datetime',
		'_law_reviewed_at'            => 'datetime',
		'_law_reviewed_by'            => 'int',
		'_law_decline_reason'         => 'multiline',
		'_law_is_complimentary'       => 'flag',
		// The committee's own classification of a flagship registration
		// (Delegate, Sponsor, Speaker, Exhibitor, Committee). Back-office only:
		// nothing reads it but the committee's table, their exports and the
		// wp-admin box, and no price, capacity or guard depends on it. Unset on
		// every registration until somebody classifies it.
		'_law_ticket_type'            => 'ticket_type',
		// The receptions (RECEPTIONS.md §1.2).
		//
		// _law_discount_id is the CLAIMED code and is DELETED on release, which
		// is what makes releasing idempotent per booking: a second release
		// finds no key and takes nobody else's live claim. The code and the
		// amount stay behind afterwards, because the record of what was
		// allowed is worth keeping once the claim is gone.
		'_law_discount_id'            => 'int',
		'_law_discount_code'          => 'text',
		'_law_discount_pence'         => 'int',
		// The flagship booking a free included place was granted from.
		'_law_included_with'          => 'int',
		// On a FLAGSHIP application: the receptions ticked at apply time,
		// granted when the place is confirmed.
		'_law_reception_choices'      => 'int_array',
		// The live Checkout session in payment mode. Every checkout.session.*
		// handler ignores a session whose id differs from this one, so a late
		// event for a superseded session cannot release a live hold.
		'_law_stripe_checkout_session_id' => 'text',
		'_law_checkout_expires_at'    => 'datetime',
		// When the money actually landed. The sweep reads it to decide when a
		// confirmation has waited long enough for its invoice, and it is the
		// only honest "paid on" date: post_modified moves for any edit.
		'_law_paid_at'                => 'datetime',
		// The charge latch (_law_charge_claim) and the one-shot email latches
		// are deliberately NOT here: they are claimed with
		// add_post_meta( …, $unique = true ), which is one INSERT and therefore
		// one winner, and registering them would invite a sanitiser between the
		// claim and the row it depends on.
	);
}

/**
 * law_discount meta schema (functions/events/discounts.php).
 *
 * Written against "a priced booking", never against one event: an empty
 * `_law_discount_events` means the code works anywhere a price is charged, so
 * whatever starts charging first reuses this unchanged.
 */
function law_discount_meta_schema() {
	return array(
		'_law_discount_type'      => 'discount_type',
		// Percent (1-100) or pence, depending on the type above.
		'_law_discount_value'     => 'int',
		'_law_discount_starts'    => 'datetime',
		'_law_discount_expires'   => 'datetime',
		// 0 means unlimited, which is why the int sanitiser storing 0 rather
		// than deleting is the behaviour wanted here.
		'_law_discount_max_uses'  => 'int',
		'_law_discount_used'      => 'int',
		'_law_discount_events'    => 'int_array',
		'_law_discount_note'      => 'text',
	);
}

/**
 * Post type => its own meta schema.
 *
 * The one place the five schemas are listed together. register_post_meta()
 * and law_events_meta_type() both read it, so what a key is sanitised as on
 * the way in cannot drift from what it is registered as.
 *
 * @return array<string,array<string,string>>
 */
function law_events_post_type_meta_schemas() {
	static $types = null;
	if ( null === $types ) {
		$types = array(
			LAW_EVENT_CPT    => law_event_meta_schema(),
			LAW_SPEAKER_CPT  => law_speaker_meta_schema(),
			LAW_SESSION_CPT  => law_session_meta_schema(),
			LAW_BOOKING_CPT  => law_booking_meta_schema(),
			LAW_DISCOUNT_CPT => law_discount_meta_schema(),
		);
	}
	return $types;
}

function law_events_register_meta() {
	foreach ( law_events_post_type_meta_schemas() as $post_type => $schema ) {
		foreach ( $schema as $key => $type ) {
			register_post_meta(
				$post_type,
				$key,
				array(
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => function () {
						return current_user_can( 'edit_law_events' );
					},
					'sanitize_callback' => function ( $value ) use ( $type ) {
						return law_events_sanitize_value( $value, $type );
					},
				)
			);
		}
	}
}
add_action( 'init', 'law_events_register_meta', 7 );

/**
 * @param mixed  $value Raw value.
 * @param string $type  Sanitiser id from the schemas above.
 */
function law_events_sanitize_value( $value, $type ) {
	switch ( $type ) {
		case 'text':
			return sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
		case 'multiline':
			return sanitize_textarea_field( (string) ( is_scalar( $value ) ? $value : '' ) );
		// Allowlisted markup, for the per-event email bodies. Deliberately NOT
		// 'multiline': sanitize_textarea_field() would strip the formatting the
		// committee just wrote. law_events_email_render_body() re-applies the
		// same allowlist at output, so neither escape may be dropped on the
		// assumption the other ran.
		case 'rich':
			return law_rich_text_sanitize( (string) ( is_scalar( $value ) ? $value : '' ) );
		case 'int':
			return max( 0, (int) $value );
		case 'float':
			return round( max( 0, (float) $value ), 2 );
		case 'flag':
			return $value ? 1 : 0;
		case 'email':
			$email = sanitize_email( (string) ( is_scalar( $value ) ? $value : '' ) );
			return is_email( $email ) ? $email : '';
		case 'url':
			return esc_url_raw( (string) ( is_scalar( $value ) ? $value : '' ) );
		case 'iso':
			$value = strtoupper( sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) ) );
			return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
		case 'time':
			// Zero-padded on the way in ("9:30" becomes "09:30"), because the
			// flagship's derived start/end and law_event_session_ids() both compare
			// these strings, and "9:30" sorts after "14:00".
			$value = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
			if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ) {
				return '';
			}
			if ( (int) $m[1] > 23 || (int) $m[2] > 59 ) {
				return '';
			}
			return sprintf( '%02d:%s', (int) $m[1], $m[2] );
		case 'date':
			// A calendar date, Y-m-d, real dates only: the flagship's fixed date.
			$value = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
				return '';
			}
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
		case 'datetime':
			$value = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
			if ( '' === $value ) {
				return '';
			}
			$ts = strtotime( $value );
			return $ts ? gmdate( 'Y-m-d H:i', $ts ) : '';
		case 'fee_tier':
			$tiers = array_keys( (array) law_events_setting( 'fee_tiers', array() ) );
			return in_array( $value, $tiers, true ) ? $value : '';
		case 'venue_needed':
			// One of the two answers on the Venue needed radio, as its full
			// label. Migrated events arrived holding the Gravity Forms choice
			// VALUES ("Yes"/"No"), which matched no radio on the custom form,
			// so the answer is mapped onto its canonical label on the way in.
			return law_events_venue_needed_label( $value );
		case 'booking_override':
			// Unknown falls back to 'auto', the answer that decides nothing: a
			// forged post must not be able to force booking open on an event
			// that has not paid.
			return in_array( $value, array( 'auto', 'disable', 'enable' ), true ) ? $value : 'auto';
		case 'payment_status':
			return in_array( $value, array( 'unpaid', 'paid', 'refunded', 'free' ), true ) ? $value : 'unpaid';
		case 'discount_type':
			return in_array( $value, array( 'percent', 'fixed' ), true ) ? $value : 'percent';
		case 'booking_payment_status':
			// A separate vocabulary from the event one above: an application
			// passes through states an invoice never has (a payment method
			// saved but nothing charged yet, a charge the bank wants
			// authenticating, a charge still settling because the delegate
			// did not pay by card). Unknown values fall back to
			// pending_setup, the state that grants nothing. 'included' is the
			// receptions' free place granted with a confirmed flagship ticket:
			// a real place that was never charged, which 'complimentary'
			// (the committee gave it) would mis-describe. 'no_charge' is the
			// third flavour of free and is the same kind of distinction: a
			// flagship registration whose discount code covers the whole
			// price, waiting on the committee with no payment method and
			// nothing to charge. It is NOT complimentary (nobody gave it away)
			// and NOT pending_setup (no card is coming, and the abandonment
			// sweep would otherwise close it after 48 hours saying no card
			// details were given, which would be both false and destructive).
			return in_array(
				$value,
				array( 'pending_setup', 'ready', 'processing', 'paid', 'failed', 'action_required', 'refunded', 'complimentary', 'included', 'no_charge' ),
				true
			) ? $value : 'pending_setup';
		case 'ticket_type':
			// The committee's classification of a flagship registration. Unknown
			// values become '', which DELETES the key rather than storing a
			// default: nobody has said what this person is, and inventing
			// "Delegate" would make the column look filled in when it is not.
			return in_array( $value, array_keys( law_booking_ticket_types() ), true ) ? $value : '';
		case 'registration_state':
			$states = array( '', 'open', 'apply', 'free', 'external', 'invitation', 'closed' );
			return in_array( $value, $states, true ) ? $value : '';
		case 'text_array':
			return array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ), 'strlen' ) );
		case 'answer_map':
			// A question => answer map, for the flagship application's own
			// fields plus whatever the organisers add later. Deliberately not
			// text_array, which array_values() the keys away; the key IS the
			// question here. Values may be multi-line (access requirements),
			// so sanitize_textarea_field, and nesting is refused rather than
			// flattened.
			$answers = array();
			foreach ( (array) $value as $question => $answer ) {
				$question = sanitize_key( (string) $question );
				if ( '' === $question || ! is_scalar( $answer ) ) {
					continue;
				}
				$answer = sanitize_textarea_field( (string) $answer );
				if ( '' !== $answer ) {
					$answers[ $question ] = $answer;
				}
			}
			return $answers;
		case 'int_array':
			return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
		case 'address':
			$value = (array) $value;
			$out   = array();
			foreach ( law_events_address_parts() as $part ) {
				$out[ $part ] = sanitize_text_field( (string) ( $value[ $part ] ?? '' ) );
			}
			// The billing country as a NAME. Half the migrated events arrived
			// holding a bare ISO code, which is not what the Country select
			// offers; see law_events_country_display_name().
			$out['country'] = law_events_country_display_name( $out['country'] );
			return $out;
		case 'people_rows':
			$rows = array();
			foreach ( (array) $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = array(
					'name'         => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
					'organisation' => sanitize_text_field( (string) ( $row['organisation'] ?? '' ) ),
					'email'        => sanitize_email( (string) ( $row['email'] ?? '' ) ),
				);
				if ( '' !== $clean['name'] || '' !== $clean['email'] ) {
					$rows[] = $clean;
				}
			}
			return $rows;
		case 'speaker_rows':
			$rows = array();
			$sort = 0;
			foreach ( (array) $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = absint( $row['speaker_id'] ?? 0 );
				if ( ! $id ) {
					continue;
				}
				// The appearance: what this speaker was at THIS event. Organisation,
				// job title, photo and biography are per event, not per person (the
				// same person speaks for different firms, and writes a different
				// biography, at different events). The pre-appearance key
				// 'organisation_override' is read as 'organisation' so rows saved
				// before the change keep working; a row with no biography falls back
				// to the speaker post's editor content at read time
				// (law_speaker_card()), the way an empty photo_id falls back to the
				// featured image. 'role' is what the person was at THIS event (Speaker /
				// Host / Moderator), stored as the law_speaker_roles() key; '' means the
				// default, Speaker, and on a session row inherits the event's.
				$organisation = (string) ( $row['organisation'] ?? '' );
				if ( '' === trim( $organisation ) ) {
					$organisation = (string) ( $row['organisation_override'] ?? '' );
				}
				$rows[] = array(
					'speaker_id'   => $id,
					'role'         => law_speaker_role_key( $row['role'] ?? '' ),
					'organisation' => sanitize_text_field( $organisation ),
					'job_title'    => sanitize_text_field( (string) ( $row['job_title'] ?? '' ) ),
					'photo_id'     => absint( $row['photo_id'] ?? 0 ),
					// Rich text (functions/events/rich-text.php), not plain: the host
					// form, the committee's Manage speakers screen and wp-admin all give
					// this field a WYSIWYG editor, and sanitize_textarea_field() would
					// strip the markup back out on every save.
					'bio'          => law_rich_text_sanitize( $row['bio'] ?? '' ),
					'sort'         => isset( $row['sort'] ) ? absint( $row['sort'] ) : $sort,
				);
				$sort++;
			}
			usort( $rows, fn( $a, $b ) => $a['sort'] <=> $b['sort'] );
			return $rows;
		case 'consent':
			$value = (array) $value;
			return array(
				'accepted' => ! empty( $value['accepted'] ) ? 1 : 0,
				'at'       => sanitize_text_field( (string) ( $value['at'] ?? '' ) ),
			);
		case 'stripe_error':
			if ( empty( $value ) ) {
				return '';
			}
			$value = (array) $value;
			return array(
				'message' => sanitize_text_field( (string) ( $value['message'] ?? '' ) ),
				'at'      => sanitize_text_field( (string) ( $value['at'] ?? '' ) ),
			);
	}
	return sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
}

/**
 * Write one meta value through the schema sanitiser. The single write path
 * shared by the admin screens, the front-end forms and the migrator.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Schema key.
 * @param mixed  $value   Raw value.
 */
/**
 * The three meta schemas merged into one map, built once per request. This is
 * the hot path: law_event_meta() reads run into the hundreds on a single
 * programme render, so the merge is memoised rather than rebuilt each call.
 *
 * @return array<string,string> Meta key => type.
 */
/**
 * The ordered invoice/billing address part keys — the single source the meta
 * sanitiser, the admin screen and the migrator loop over.
 *
 * @return string[]
 */
function law_events_address_parts() {
	return array( 'line1', 'line2', 'city', 'state', 'postal_code', 'country' );
}

function law_events_all_meta_schemas() {
	static $schemas = null;
	if ( null === $schemas ) {
		$schemas = array_merge(
			law_event_meta_schema(),
			law_speaker_meta_schema(),
			law_session_meta_schema(),
			law_booking_meta_schema(),
			law_discount_meta_schema()
		);
	}
	return $schemas;
}

/**
 * The sanitiser for one meta key ON ONE POST, which is not the same question
 * as "what does this key mean somewhere in the module".
 *
 * Two post types can declare the same key with DIFFERENT vocabularies, and
 * _law_payment_status does: an event's is unpaid/paid/refunded/free, a
 * booking's is the longer pending_setup/ready/processing/… list. The merged
 * map cannot answer for both, because array_merge() keeps the last one, which
 * is the booking's. Reading the post's own schema first is what makes an
 * event's 'free' survive the write; before 16 September 2026 it was sanitised
 * against the booking vocabulary into 'pending_setup' and then, by the
 * registered per-post-type callback, into 'unpaid'. Every £0 event in the
 * database read "Unpaid" as a result, which is what
 * migration/repair-payment-status.php was written to correct.
 *
 * The merged map stays as the fallback, for a post whose type is none of the
 * five (or a post that no longer exists), where a key still has exactly one
 * possible meaning.
 *
 * @param int    $post_id Post the value is being read from or written to.
 * @param string $key     Schema key.
 * @return string|null The sanitiser id, or null when the key is in no schema.
 */
function law_events_meta_type( $post_id, $key ) {
	$post_type = get_post_type( $post_id );
	$schemas   = law_events_post_type_meta_schemas();
	if ( $post_type && isset( $schemas[ $post_type ][ $key ] ) ) {
		return $schemas[ $post_type ][ $key ];
	}
	return law_events_all_meta_schemas()[ $key ] ?? null;
}

function law_event_update_meta( $post_id, $key, $value ) {
	$type = law_events_meta_type( $post_id, $key );
	if ( null === $type ) {
		return false;
	}
	$clean = law_events_sanitize_value( $value, $type );
	if ( '' === $clean || array() === $clean ) {
		return delete_post_meta( $post_id, $key );
	}
	return update_post_meta( $post_id, $key, $clean );
}

/**
 * Read one meta value, with schema-shaped fallbacks for array types.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Schema key.
 */
function law_event_meta( $post_id, $key ) {
	$value = get_post_meta( $post_id, $key, true );
	$type  = law_events_meta_type( $post_id, $key ) ?? 'text';
	if ( in_array( $type, array( 'text_array', 'int_array', 'people_rows', 'speaker_rows', 'answer_map' ), true ) ) {
		return is_array( $value ) ? $value : array();
	}
	if ( in_array( $type, array( 'address', 'consent' ), true ) ) {
		return is_array( $value ) ? $value : array();
	}
	if ( 'stripe_error' === $type ) {
		return is_array( $value ) ? $value : '';
	}
	return $value;
}

/**
 * Bump a counter option atomically and return the new value. One
 * INSERT … ON DUPLICATE KEY UPDATE with the LAST_INSERT_ID() trick, so two
 * genuinely simultaneous callers can never mint the same number — booking
 * numbers are user-facing identifiers in emails and the host list, where the
 * old get/increment/update race would confuse (adversarial review,
 * 7 September 2026; the LAW reference counter rides the same fix).
 *
 * @param string $option Counter option name.
 * @param int    $by     How many numbers to claim at once (a party of N takes
 *                       one block, so its numbers are consecutive).
 * @return int The LAST number in the claimed block.
 */
function law_events_bump_counter( $option, $by = 1 ) {
	global $wpdb;
	// $by > 1 claims a consecutive block in ONE atomic step and returns the
	// LAST number in it, so a party booked together gets consecutive booking
	// numbers even while another event is booking concurrently (the counter is
	// global, the event lock is not).
	$by = max( 1, (int) $by );

	// Two statements, deliberately. Doing the insert and the increment in one
	// looks tidier and is WRONG: when that statement genuinely inserts, MySQL
	// assigns the new row's own AUTO_INCREMENT option_id, and THAT becomes
	// LAST_INSERT_ID, overriding the value the VALUES clause asked for. So the
	// very first number a counter ever issued was the wp_options auto-increment
	// rather than 1 — on a site with 111,546 option rows behind it, the first
	// booking came out as #111547. Only the returned value was wrong; the
	// stored counter was right, which is why it was invisible until someone
	// read a booking number.
	//
	// Step one: make sure the row exists. Whatever LAST_INSERT_ID this leaves
	// behind is discarded.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, '0', 'no')
			 ON DUPLICATE KEY UPDATE option_name = option_name",
			$option
		)
	);

	// Step two: claim the number. An UPDATE assigns no AUTO_INCREMENT, so
	// LAST_INSERT_ID() is exactly what we set and nothing else, and two
	// concurrent callers still each get their own block.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + %d) WHERE option_name = %s",
			$by,
			$option
		)
	);
	$counter = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	// The raw write bypassed the options API: drop the cached copy so a
	// later get_option() (a seeded starting value, say) reads the real row.
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return $counter;
}

/**
 * Generate the next LAW reference, continuing the GP Unique ID sequence
 * (format LAW<yy>-<5 digits>, e.g. LAW26-00207). The counter option is
 * seeded by the migrator from wp_gpui_sequence.
 *
 * SUPERSEDED, and deliberately left in place. On 14 September 2026 the client
 * settled on plain numeric references instead of the LAW<yy>-<sequence>
 * format: a migrated event's reference is its Gravity Forms entry ID and a new
 * event's is its own post ID (law_events_event_reference()). Nothing calls
 * this any more, and the counter it reads is still seeded by migration step 7,
 * so the old sequence can be resumed if that decision is ever reversed.
 */
function law_events_next_reference() {
	$counter = law_events_bump_counter( 'law_events_reference_counter' );
	$year    = (int) law_events_setting( 'year', (int) gmdate( 'Y' ) );
	return sprintf( 'LAW%02d-%05d', $year % 100, $counter );
}

/**
 * The reference a NEW event gets: its own post ID, as a string.
 *
 * The client's decision of 14 September 2026. The legacy site's references
 * came from GP Unique ID (field 70 on form 2, Event > submit an event) in the
 * form LAW26-00121, but the committee works from the Gravity Forms entry IDs
 * they see in the entries list, so those are what migration now stores
 * (law_migration_populate_event()) and the post ID is the natural continuation
 * of the same idea: one number, visible in wp-admin, that identifies the
 * event everywhere.
 *
 * @param int $event_id law_event post ID.
 * @return string
 */
function law_events_event_reference( $event_id ) {
	return (string) (int) $event_id;
}

/**
 * Give an event its reference if it has none, and return it.
 *
 * Idempotent and never overwrites: a migrated event's reference is its
 * Gravity Forms entry ID, which must survive every later save. Use the
 * Migration screen's "Reassign event references" panel to change one
 * deliberately.
 *
 * @param int $event_id law_event post ID.
 * @return string The event's reference.
 */
function law_events_ensure_reference( $event_id ) {
	$event_id = (int) $event_id;
	$existing = (string) law_event_meta( $event_id, '_law_reference' );
	if ( '' !== $existing ) {
		return $existing;
	}

	$reference = law_events_event_reference( $event_id );
	law_event_update_meta( $event_id, '_law_reference', $reference );
	return $reference;
}

/**
 * Every event carries a reference from the moment it exists.
 *
 * It used to be minted on the `submit` transition alone, so an event created
 * in wp-admin (or a reception seeded by the provisioning step) showed an empty
 * Reference on its own edit screen and in the bookings dashboard until a host
 * submitted it. With the reference now being the post ID there is nothing to
 * wait for, so it is written on the first save.
 */
add_action(
	'save_post_' . LAW_EVENT_CPT,
	function ( $post_id, $post ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		law_events_ensure_reference( $post_id );
	},
	20,
	2
);
