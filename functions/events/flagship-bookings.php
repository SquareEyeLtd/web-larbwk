<?php
/**
 * The flagship conference's approval-gated application flow
 * (FLAGSHIP_PAYMENTS.md §5, EVENTS_4.2_SPECS.md §5).
 *
 * The shape, and why it is not the hosted-event booking engine with an extra
 * status: a delegate APPLIES, saves a card without being charged, and waits
 * for the committee. Approval charges the card and confirms the place;
 * declining removes the card. Nothing is automatic, which is the whole point
 * of an approval gate, and is why this deliberately does not reuse
 * waitlist.php — that machinery promotes and charges the moment a place
 * frees.
 *
 * It DOES reuse everything that is genuinely shared: the booking post type
 * and its numbering, the duplicate guard, the event lock, the places
 * recount, the account resolve-or-create, the activity log, the email
 * registry and the .ics generator. The money lives in stripe/attendees.php,
 * which does not know the flagship exists and bills any priced booking.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How long an application may sit without card details before it is closed. */
const LAW_FLAGSHIP_SETUP_GRACE_HOURS = 48;

/** Default days a delegate has to fix a failed payment. */
const LAW_FLAGSHIP_PAYMENT_WINDOW_DAYS = 7;

/**
 * Days a payment may sit "in progress" before the committee is alerted.
 *
 * Generous on purpose: a card settles in seconds, a wallet in minutes, but a
 * bank debit can legitimately take several working days. Three would cry
 * wolf over a normal Bacs collection; five means a genuinely dropped webhook
 * still surfaces inside a week.
 */
const LAW_FLAGSHIP_PROCESSING_ALERT_DAYS = 5;

/** How many applications one bulk pass will charge before handing on to cron. */
const LAW_FLAGSHIP_BULK_CAP = 10;

/** Seconds a bulk pass may run before handing on to cron. */
const LAW_FLAGSHIP_BULK_SECONDS = 15;

/* Reading ____________________________________________________________________ */

/** Is this booking an application to the flagship? */
function law_flagship_booking_is( $booking ) {
	$post = $booking instanceof WP_Post ? $booking : get_post( (int) $booking );
	if ( ! $post || LAW_BOOKING_CPT !== $post->post_type ) {
		return false;
	}

	return function_exists( 'law_flagship_is' ) && law_flagship_is( (int) $post->post_parent );
}

/** Days a delegate has to fix a failed payment. */
function law_flagship_payment_window_days() {
	$days = (int) law_events_setting( 'flagship_payment_window_days', LAW_FLAGSHIP_PAYMENT_WINDOW_DAYS );

	return $days > 0 ? $days : LAW_FLAGSHIP_PAYMENT_WINDOW_DAYS;
}

/**
 * The deadline a failed payment must be fixed by, as a timestamp; 0 when the
 * booking has not failed.
 */
function law_flagship_payment_deadline_ts( $booking_id ) {
	$failed = (string) law_event_meta( $booking_id, '_law_payment_failed_at' );
	if ( '' === $failed ) {
		return 0;
	}
	$ts = strtotime( $failed . ' UTC' );

	return $ts ? $ts + ( law_flagship_payment_window_days() * DAY_IN_SECONDS ) : 0;
}

/**
 * A person's live application to the flagship, if they have one.
 *
 * @return WP_Post|null
 */
function law_flagship_application_for_user( $user_id, $event_id = 0 ) {
	$user_id  = (int) $user_id;
	$event_id = (int) $event_id ? (int) $event_id : law_flagship_event_id();
	if ( $user_id < 1 || ! $event_id ) {
		return null;
	}

	$found = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_parent'    => $event_id,
			'author'         => $user_id,
			'post_status'    => array( 'publish', 'law-applied', 'law-payment-failed' ),
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	return $found ? $found[0] : null;
}

/**
 * Every application, newest first, for the committee's list and its exports.
 *
 * @param array $filters status, payment, kw, complimentary.
 * @return WP_Post[]
 */
function law_flagship_applications( array $filters = array(), $limit = 2000 ) {
	$event_id = law_flagship_event_id();
	if ( ! $event_id ) {
		return array();
	}

	$status = (string) ( $filters['status'] ?? '' );
	$all    = array_keys( law_flagship_application_statuses() );

	$posts = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_parent'    => $event_id,
			'post_status'    => $status && in_array( $status, $all, true ) ? array( $status ) : $all,
			'posts_per_page' => (int) $limit,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		)
	);

	// An application whose card never arrived is not yet a request the
	// committee can act on, so it stays out of the queue unless asked for by
	// name. It is still visible under the "Awaiting card" payment filter.
	$payment = (string) ( $filters['payment'] ?? '' );
	$kw      = mb_strtolower( trim( (string) ( $filters['kw'] ?? '' ) ) );
	$comp    = ! empty( $filters['complimentary'] );

	$out = array();
	foreach ( $posts as $post ) {
		$state = (string) law_event_meta( $post->ID, '_law_payment_status' );
		if ( '' === $payment && 'pending_setup' === $state ) {
			continue;
		}
		if ( '' !== $payment && $state !== $payment ) {
			continue;
		}
		if ( $comp && ! law_event_meta( $post->ID, '_law_is_complimentary' ) ) {
			continue;
		}
		if ( '' !== $kw ) {
			$person   = law_booking_attendee( $post->ID );
			$haystack = mb_strtolower(
				implode(
					' ',
					array(
						$person['name'],
						$person['email'],
						$person['organisation'],
						$person['job_title'],
						'#' . law_event_meta( $post->ID, '_law_booking_number' ),
					)
				)
			);
			if ( false === mb_strpos( $haystack, $kw ) ) {
				continue;
			}
		}
		$out[] = $post;
	}

	return $out;
}

/** How many places the flagship has confirmed, and how many it offered. */
function law_flagship_places() {
	$event_id  = law_flagship_event_id();
	$available = $event_id ? (int) law_event_meta( $event_id, '_law_tickets_available' ) : 0;
	$confirmed = $event_id ? law_event_attendee_total( $event_id ) : 0;

	return array(
		'available'  => $available,
		'confirmed'  => $confirmed,
		'remaining'  => $available > 0 ? max( 0, $available - $confirmed ) : 0,
		'full'       => $available > 0 && $confirmed >= $available,
		'overbooked' => $available > 0 && $confirmed > $available,
	);
}

/* Applying ___________________________________________________________________ */

/**
 * Submit an application.
 *
 * Returns the booking ID and where to send the delegate next: Stripe's hosted
 * card page, or straight back to their booking when a 100% code means there is
 * nothing to charge.
 *
 * @param int   $user_id The delegate (always themselves; there is no applying
 *                       on behalf of colleagues — spec §5.1).
 * @param array $input   answers, consent, terms.
 * @return array{booking:int,redirect:string}|WP_Error
 */
function law_flagship_apply( $user_id, array $input ) {
	$user_id  = (int) $user_id;
	$user     = $user_id ? get_user_by( 'id', $user_id ) : null;
	$event_id = law_flagship_event_id();

	if ( ! $user ) {
		return new WP_Error( 'law_flagship_no_user', 'You need to be signed in to apply.' );
	}
	$open = law_flagship_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $open;
	}
	if ( empty( $input['consent'] ) ) {
		return new WP_Error(
			'law_flagship_no_consent',
			'Please confirm you agree to your card being saved and charged if your application is approved.',
			array( 'field' => 'law_consent' )
		);
	}
	if ( empty( $input['terms'] ) ) {
		return new WP_Error(
			'law_flagship_no_terms',
			'Please accept the registration terms and conditions.',
			array( 'field' => 'law_terms' )
		);
	}

	$person = law_flagship_person_from_input( $user_id, $input );

	// The profile IS the application, so it has to carry what the committee
	// reviews on. The dialog checks the same list and links to the profile;
	// this is the guard that holds, including for a hand-made POST.
	$missing = law_flagship_profile_gaps( $user_id );
	if ( $missing ) {
		return new WP_Error(
			'law_flagship_profile_incomplete',
			sprintf(
				'Please add your %s to your profile before applying.',
				wp_sprintf_l( '%l', $missing )
			)
		);
	}

	// Fast-fail before the lock and before Stripe: a refused application must
	// never leave a customer or a half-written booking behind.
	$dup = law_booking_guard_duplicates( $event_id, array( $person ), law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return $dup;
	}

	// The price the delegate is consenting to, frozen now. Deliberately NOT
	// read again at approval: they agreed to this figure (FLAGSHIP_PAYMENTS.md
	// §0.2), and law_event_snapshot_fee() sets the same precedent on the host
	// side.
	$list_pence = law_flagship_price_pence( 0, $event_id );
	if ( $list_pence < 1 ) {
		return new WP_Error( 'law_flagship_not_on_sale', 'Applications are not open for this event yet.' );
	}

	// The form posts the price it displayed. If it no longer matches — the
	// delegate had the page open across the cutover, or the committee edited
	// the price while they were typing — refuse rather than silently
	// snapshotting a figure they never saw and never consented to. The whole
	// point of _law_payment_consent_at is that it evidences agreement to an
	// amount.
	$shown = isset( $input['price_shown'] ) ? (int) $input['price_shown'] : 0;
	if ( $shown > 0 && $shown !== $list_pence ) {
		return new WP_Error(
			'law_flagship_price_changed',
			sprintf(
				'The price changed to %s while you were filling this in, so nothing has been submitted. Please check the new price and apply again.',
				law_events_price_label( $list_pence )
			)
		);
	}

	$locked = law_booking_lock( $event_id );

	// Re-check under the lock: two tabs, two applications.
	$dup = law_booking_guard_duplicates( $event_id, array( $person ), law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
		return $dup;
	}

	$numbers    = law_bookings_next_numbers( 1 );
	$booking_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_status' => 'law-applied',
				'post_parent' => $event_id,
				'post_author' => $user_id,
				'post_title'  => 'Booking #' . $numbers[0],
			)
		),
		true
	);

	if ( is_wp_error( $booking_id ) || ! $booking_id ) {
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
		return is_wp_error( $booking_id ) ? $booking_id : new WP_Error( 'law_flagship_insert_failed', 'Your application could not be saved. Please try again.' );
	}
	$booking_id = (int) $booking_id;

	law_event_update_meta( $booking_id, '_law_booking_number', $numbers[0] );
	law_booking_write_attendee( $booking_id, $person, $user_id );
	law_event_update_meta( $booking_id, '_law_application_at', gmdate( 'Y-m-d H:i' ) );
	// _law_application_answers stays in the schema and stays empty: the form
	// asks nothing, and the committee's view reads the profile live. It is
	// reserved for the spec's "any other questions the organisers add"
	// (§5.1), which is the only thing that would ever have no home on a
	// profile. law_flagship_apply() still accepts an `answers` array so that
	// work needs no change here.
	if ( ! empty( $input['answers'] ) ) {
		law_event_update_meta( $booking_id, '_law_application_answers', (array) $input['answers'] );
	}
	law_event_update_meta( $booking_id, '_law_price_pence', $list_pence );
	law_event_update_meta( $booking_id, '_law_vat', 1 );
	law_event_update_meta( $booking_id, '_law_payment_consent_at', gmdate( 'Y-m-d H:i' ) );
	law_event_update_meta( $booking_id, '_law_payment_status', 'pending_setup' );

	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	// Everything slow happens after the lock: the attendee role, Stripe, and
	// the emails, so one applicant is never queued behind another's network.
	law_booking_grant_attendee_role( $user_id, $event_id, $user_id );

	law_event_log(
		$event_id,
		sprintf(
			'Flagship application #%d received from %s (%s).',
			$numbers[0],
			$person['name'],
			law_events_format_pence( law_events_gross_pence( $list_pence ) )
		),
		array(
			'source'  => 'flagship',
			'action'  => 'flagship_applied',
			'booking' => $booking_id,
			'price'   => $list_pence,
		),
		array( 'user_id' => $user_id )
	);

	$url = law_stripe_create_setup_session( $booking_id, 'apply' );
	if ( is_wp_error( $url ) ) {
		// The application survives: the delegate can add their card from My
		// bookings rather than typing everything again.
		return array( 'booking' => $booking_id, 'redirect' => law_booking_manage_url( $booking_id ) );
	}

	return array( 'booking' => $booking_id, 'redirect' => $url );
}

/** Is the flagship taking applications at all? Capacity is NOT a reason not to. */
function law_flagship_guard_open( $event_id = 0 ) {
	$event_id = (int) $event_id ? (int) $event_id : law_flagship_event_id();
	$post     = $event_id ? get_post( $event_id ) : null;

	if ( ! $post || LAW_EVENT_CPT !== $post->post_type || 'cpt' !== law_events_source() ) {
		return new WP_Error( 'law_flagship_missing', 'The flagship conference is not open for applications.' );
	}
	if ( 'publish' !== $post->post_status ) {
		return new WP_Error( 'law_flagship_unpublished', 'The flagship conference is not open for applications yet.' );
	}
	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
		return new WP_Error( 'law_flagship_started', 'This event has taken place, so applications are closed.' );
	}

	// Deliberately no capacity check (Denis, 10 September 2026): a full
	// conference still takes applications and queues them, and the page says
	// so. The committee decides who gets a place, not the counter.
	return true;
}

/**
 * What the committee needs on a profile before somebody can apply, and what
 * is missing from this one.
 *
 * The application form collects nothing (Denis, 10 September 2026), so the
 * profile IS the application. That makes an incomplete profile a real dead
 * end rather than a validation message: there is no field on the dialog to
 * fix it in, so the dialog links to the profile instead, and this is the one
 * list both of them read.
 *
 * **A name, and only a name.** Organisation, job title and country are all
 * useful — the committee filters on organisation, the badges print the job
 * title — but none of them is worth refusing an application over. Registration
 * asks for them, the PROFILE form does not, and on this site 63 of 315
 * existing accounts have no job title and 23 have no organisation (checked
 * 10 September 2026). Requiring them would have turned a fifth of the
 * membership away at the door to fill in a column. They render blank on the
 * committee's list and in the exports, exactly as they already do for a
 * hosted booking.
 *
 * A name is different: without one the committee reviews a row showing an
 * email address, and the on-site badge has nothing to print.
 *
 * @return string[] Human-readable names of what is missing; empty when ready.
 */
function law_flagship_profile_gaps( $user_id ) {
	$profile = law_profile_values( (int) $user_id );
	$needed  = array(
		'first_name' => __( 'first name', 'law' ),
		'last_name'  => __( 'surname', 'law' ),
	);

	$missing = array();
	foreach ( $needed as $field => $label ) {
		if ( '' === trim( (string) ( $profile[ $field ] ?? '' ) ) ) {
			$missing[] = $label;
		}
	}

	return $missing;
}

/** The snapshot row the booking carries, from the profile and the form. */
function law_flagship_person_from_input( $user_id, array $input ) {
	$profile = law_profile_values( $user_id );
	$answers = (array) ( $input['answers'] ?? array() );

	$first = sanitize_text_field( (string) ( $answers['first_name'] ?? $profile['first_name'] ?? '' ) );
	$last  = sanitize_text_field( (string) ( $answers['last_name'] ?? $profile['last_name'] ?? '' ) );
	$name  = trim( $first . ' ' . $last );

	return array(
		'user_id'      => (int) $user_id,
		'name'         => '' !== $name ? $name : (string) ( $profile['email'] ?? '' ),
		'email'        => (string) ( $profile['email'] ?? '' ),
		'organisation' => sanitize_text_field( (string) ( $answers['organisation'] ?? $profile['organisation'] ?? '' ) ),
		'job_title'    => sanitize_text_field( (string) ( $answers['job_title'] ?? $profile['job_title'] ?? '' ) ),
	);
}

/* Card outcomes (called by the webhook and the return URL) ___________________ */

/**
 * A card has landed: the application is now a real request, so the committee
 * hears about it and the delegate gets their acknowledgement.
 *
 * Idempotent, because the return URL and two webhook events all reach it.
 */
function law_flagship_on_card_saved( $booking_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return false;
	}
	// Flagship only. These three carry FLAGSHIP semantics — its statuses, its
	// confirm and decline meaning — and the webhook reaches them for any
	// booking whose Stripe metadata names it. Nothing else attaches that
	// metadata today, but stripe/attendees.php is deliberately generic and a
	// priced reception is the intended second caller; without this it would
	// silently inherit `law-payment-failed` and this file's idea of what
	// "confirmed" means. Fail closed and say so, rather than quietly
	// mis-handling somebody's money.
	if ( ! law_flagship_booking_is( $booking ) ) {
		law_event_log(
			(int) $booking->post_parent,
			sprintf(
				'A payment event reached the flagship handler for booking #%d, which is not a flagship application. Nothing was changed: this flow needs its own handler.',
				(int) law_event_meta( $booking->ID, '_law_booking_number' )
			),
			array( 'source' => 'stripe_webhook', 'action' => 'flagship_handler_wrong_booking', 'booking' => (int) $booking->ID )
		);
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	// A one-shot latch, not in the public schema, so the acknowledgement and
	// the committee alert go out once.
	//
	// It has to be CLAIMED, not read and then written. Three separate
	// requests reach here for a single saved payment method — the
	// checkout.session.completed webhook, the setup_intent.succeeded webhook
	// and the delegate's own browser returning from Checkout — and they
	// arrive within milliseconds of each other. A read-then-update latch
	// lets every one of them read "not sent", write it, and send: on
	// 10 September 2026 that put two of each email in the committee's inbox.
	//
	// So: the event lock serialises them (the same lock every other decision
	// in this file takes), and add_post_meta( …, $unique = true ) is the
	// claim, returning false when the row already exists. Belt and braces
	// deliberately — the lock has a 3-second acquire timeout, and if it is
	// ever not granted the unique claim still narrows the window to almost
	// nothing.
	$locked  = law_booking_lock( $event_id );
	$claimed = (bool) add_post_meta( $booking_id, '_law_application_ready', 1, true );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}
	if ( ! $claimed ) {
		return false;
	}

	law_event_log(
		$event_id,
		sprintf( 'Flagship application #%d is ready for review.', law_event_meta( $booking_id, '_law_booking_number' ) ),
		array( 'source' => 'flagship', 'action' => 'flagship_ready', 'booking' => $booking_id ),
		array( 'user_id' => 0 )
	);

	$extra = law_flagship_email_extra( $booking_id );
	law_events_send( 'user_flagship_applied', $event_id, $extra );
	law_events_send( 'committee_flagship_application', $event_id, array( 'placeholders' => $extra['placeholders'] ) );

	return true;
}

/** The card could not be saved; say so rather than leaving it silent. */
function law_flagship_on_card_setup_failed( $booking_id, $message = '' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return false;
	}
	law_event_log(
		(int) $booking->post_parent,
		sprintf(
			'Card details could not be saved for flagship application #%d: %s',
			law_event_meta( $booking->ID, '_law_booking_number' ),
			$message ?: 'no reason given'
		),
		array( 'source' => 'flagship', 'action' => 'flagship_setup_failed', 'booking' => (int) $booking->ID ),
		array( 'user_id' => 0 )
	);

	return true;
}

/* Reviewing __________________________________________________________________ */

/**
 * Approve one application: charge the saved card, confirm the place.
 *
 * @param array $args confirm_overbook (bool), source (string).
 * @return array{status:string,overbooked:int}|WP_Error
 */
function law_flagship_approve( $booking_id, $actor_id, array $args = array() ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship application.' );
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;
	$number     = (int) law_event_meta( $booking_id, '_law_booking_number' );

	// Everything that DECIDES happens under the event lock, and the slow
	// Stripe round trip happens after it. Reading the status and the places
	// count outside the lock is how two committee members approving at the
	// same second both passed the capacity test, and how one member with two
	// tabs open raised two invoices for one delegate.
	$locked = law_booking_lock( $event_id );

	// Re-read: the copy above may be seconds stale.
	$booking = get_post( $booking_id );
	$release = function () use ( $locked, $event_id ) {
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
	};

	if ( 'publish' === $booking->post_status ) {
		$release();
		return array( 'status' => 'already', 'overbooked' => 0 );
	}
	if ( ! in_array( $booking->post_status, array( 'law-applied', 'law-payment-failed' ), true ) ) {
		$release();
		return new WP_Error( 'law_flagship_not_reviewable', 'That application has already been decided.' );
	}
	$payment_state = (string) law_event_meta( $booking_id, '_law_payment_status' );
	if ( 'pending_setup' === $payment_state ) {
		$release();
		return new WP_Error( 'law_flagship_no_card', 'That applicant has not given their payment details yet, so there is nothing to charge.' );
	}
	// A charge Stripe has accepted but not settled. The booking is still
	// law-applied — it holds its place while the money travels — so without
	// this it reads as reviewable and the resume path would try to pay an
	// invoice whose payment is already in flight. The dashboard hides the
	// button, but a stale page or the no-JS form still reaches this
	// function, and hiding a control has never been a guard.
	if ( 'processing' === $payment_state ) {
		$release();
		return new WP_Error(
			'law_flagship_payment_in_flight',
			'A payment for this application is already on its way. It will confirm itself when the money lands.'
		);
	}

	// Over-booking is allowed and deliberate, but never by accident: the
	// caller has to say so, and the log says so loudly afterwards.
	$places     = law_flagship_places();
	$overbooked = 0;
	if ( $places['available'] > 0 && $places['confirmed'] >= $places['available'] ) {
		if ( empty( $args['confirm_overbook'] ) ) {
			$release();
			return new WP_Error(
				'law_flagship_full',
				sprintf(
					'The conference is full (%d of %d places taken). Approving this application over-books it.',
					$places['confirmed'],
					$places['available']
				),
				array( 'needs_confirm' => true )
			);
		}
		$overbooked = ( $places['confirmed'] + 1 ) - $places['available'];
	}

	$price = law_booking_price( $booking_id );

	if ( $price['free'] ) {
		$release();
		law_flagship_confirm( $booking_id, $actor_id, 'complimentary' );

		return array( 'status' => 'confirmed', 'overbooked' => $overbooked );
	}

	// Claim the charge before letting go of the lock. add_post_meta with
	// $unique is a single atomic INSERT, so exactly one request wins and the
	// loser is told to wait rather than raising a second invoice.
	if ( ! law_flagship_claim_charge( $booking_id ) ) {
		$release();
		return new WP_Error(
			'law_flagship_charging',
			'A payment for this application is already being taken. Give it a moment and reload before trying again.'
		);
	}
	$release();

	try {
		$invoice = law_stripe_charge_booking( $booking_id );
	} finally {
		law_flagship_release_charge( $booking_id );
	}

	if ( is_wp_error( $invoice ) ) {
		// Whose fault is it? A card decline is the delegate's to fix and they
		// are told so. A missing tax rate or an unconfigured key is OURS, and
		// telling forty applicants their card was declined because a settings
		// field is blank would be both false and alarming. Those stop here,
		// loudly, with the application untouched.
		if ( law_flagship_is_configuration_error( $invoice ) ) {
			law_event_log(
				$event_id,
				sprintf(
					'ACTION NEEDED: flagship application #%d could not be charged because of a configuration problem, so nothing was changed: %s',
					$number,
					$invoice->get_error_message()
				),
				array( 'source' => 'flagship', 'action' => 'flagship_charge_misconfigured', 'booking' => $booking_id ),
				array( 'user_id' => (int) $actor_id )
			);
			law_events_send(
				'admin_stripe_error',
				$event_id,
				array( 'placeholders' => array( 'stripe_error' => $invoice->get_error_message() ) )
			);

			return $invoice;
		}

		law_flagship_mark_payment_failed( $booking_id, $invoice->get_error_message() );
		law_event_log(
			$event_id,
			sprintf(
				'Flagship application #%d approved, but the card was declined: %s',
				$number,
				$invoice->get_error_message()
			),
			array( 'source' => 'flagship', 'action' => 'flagship_charge_failed', 'booking' => $booking_id ),
			array( 'user_id' => (int) $actor_id )
		);

		return $invoice;
	}

	$status = (string) ( $invoice['status'] ?? '' );
	if ( 'paid' !== $status ) {
		// Finalised but not settled, and the two reasons are not the same
		// thing. `requires_action` is SCA: the delegate must go and confirm,
		// and until they do nothing is happening. `processing` is a payment
		// already on its way that simply does not settle instantly, which is
		// normal for the non-card methods Stripe Checkout offers once they
		// are enabled on the account (EVENTS_4.2_SPECS.md §7.1 allows all of
		// them bar Klarna). Telling that delegate their bank needs them, and
		// flagging the row as a failure, would be false twice over.
		if ( 'processing' === law_stripe_invoice_intent_status( $invoice ) ) {
			law_flagship_mark_payment_processing( $booking_id, (string) ( $invoice['hosted_invoice_url'] ?? '' ), $actor_id );

			return array( 'status' => 'processing', 'overbooked' => $overbooked );
		}

		law_flagship_mark_payment_failed(
			$booking_id,
			'Your bank needs you to confirm this payment.',
			'action_required',
			(string) ( $invoice['hosted_invoice_url'] ?? '' )
		);

		return array( 'status' => 'action_required', 'overbooked' => $overbooked );
	}

	law_flagship_mark_paid( $booking_id, $invoice, '', $actor_id );

	if ( $overbooked > 0 ) {
		law_event_log(
			$event_id,
			sprintf(
				'OVER-BOOKED: approving application #%d takes the flagship to %d confirmed places against %d available.',
				$number,
				law_event_attendee_total( $event_id ),
				$places['available']
			),
			array( 'source' => 'flagship', 'action' => 'flagship_overbooked', 'booking' => $booking_id, 'by' => $overbooked ),
			array( 'user_id' => (int) $actor_id )
		);
	}

	return array( 'status' => 'confirmed', 'overbooked' => $overbooked );
}

/**
 * Is this failure ours rather than the delegate's?
 *
 * A blank tax rate, a missing Stripe key or an unresolvable customer is a
 * configuration problem: the card was never presented, so the application
 * must not be flipped to "payment failed", the 7-day clock must not start,
 * and above all the delegate must not be emailed to say their card was
 * declined.
 */
function law_flagship_is_configuration_error( WP_Error $error ) {
	$ours = array(
		'law_no_tax_rate',
		'law_stripe_unconfigured',
		'law_stripe_no_user',
		'law_stripe_no_customer',
		'law_stripe_no_invoice',
		'law_stripe_no_method',
		'law_booking_missing',
		'law_booking_free',
		'law_stripe_resume_unreadable',
		'law_stripe_bad_response',
	);
	if ( in_array( $error->get_error_code(), $ours, true ) ) {
		return true;
	}

	// Every Stripe API failure arrives as one code, law_stripe_api_error, so
	// the code alone cannot tell a declined card from a mistake of ours. The
	// full Stripe error object rides along in the WP_Error data, and its
	// `type` does distinguish them.
	//
	// This matters because the two are handled in opposite ways: a decline is
	// the delegate's to fix and they are told the reason verbatim, while ours
	// stops quietly with the application untouched and alerts an admin. On
	// 10 September 2026 a reused idempotency key came back as
	// invalid_request_error and was shown to a delegate as the reason their
	// payment had failed, telling them to go and sort out a key.
	//
	// Only the listed types are claimed as ours. Anything unrecognised stays
	// with the delegate, so a genuine decline in a shape not seen here is
	// never silently swallowed into an admin email nobody is waiting for.
	$data   = $error->get_error_data();
	$stripe = is_array( $data ) && is_array( $data['stripe'] ?? null ) ? $data['stripe'] : array();
	$type   = (string) ( $stripe['type'] ?? '' );

	return in_array(
		$type,
		array( 'idempotency_error', 'invalid_request_error', 'authentication_error', 'api_error', 'rate_limit_error' ),
		true
	);
}

/**
 * Take the exclusive right to charge this booking, atomically.
 *
 * The event lock cannot be held across the Stripe round trip — four calls,
 * up to 30 seconds each, would queue every other committee action behind one
 * card. So the decision is made under the lock and the charge is claimed with
 * a single `add_post_meta( ..., $unique = true )`, which is one INSERT and
 * therefore one winner.
 *
 * A claim older than five minutes is stale (a fatal mid-charge, a killed
 * request) and is taken over, so a crash cannot lock a delegate out for ever.
 */
function law_flagship_claim_charge( $booking_id ) {
	$booking_id = (int) $booking_id;
	$existing   = get_post_meta( $booking_id, '_law_charge_claim', true );

	if ( '' !== (string) $existing ) {
		if ( ( time() - (int) $existing ) < 5 * MINUTE_IN_SECONDS ) {
			return false;
		}
		delete_post_meta( $booking_id, '_law_charge_claim' );
	}

	return (bool) add_post_meta( $booking_id, '_law_charge_claim', time(), true );
}

/** Give it back, whatever happened. */
function law_flagship_release_charge( $booking_id ) {
	delete_post_meta( (int) $booking_id, '_law_charge_claim' );
}

/**
 * Money has been taken (or waived): confirm the place.
 *
 * The SINGLE path to a confirmed flagship booking, called from the
 * synchronous charge and from the invoice.paid webhook, in whichever order
 * they arrive. Idempotent.
 */
function law_flagship_mark_paid( $booking_id, array $invoice = array(), $stripe_event_id = '', $actor_id = 0 ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return false;
	}
	// Flagship only. These three carry FLAGSHIP semantics — its statuses, its
	// confirm and decline meaning — and the webhook reaches them for any
	// booking whose Stripe metadata names it. Nothing else attaches that
	// metadata today, but stripe/attendees.php is deliberately generic and a
	// priced reception is the intended second caller; without this it would
	// silently inherit `law-payment-failed` and this file's idea of what
	// "confirmed" means. Fail closed and say so, rather than quietly
	// mis-handling somebody's money.
	if ( ! law_flagship_booking_is( $booking ) ) {
		law_event_log(
			(int) $booking->post_parent,
			sprintf(
				'A payment event reached the flagship handler for booking #%d, which is not a flagship application. Nothing was changed: this flow needs its own handler.',
				(int) law_event_meta( $booking->ID, '_law_booking_number' )
			),
			array( 'source' => 'stripe_webhook', 'action' => 'flagship_handler_wrong_booking', 'booking' => (int) $booking->ID )
		);
		return false;
	}
	$booking_id = (int) $booking->ID;

	if ( $invoice ) {
		law_event_update_meta( $booking_id, '_law_stripe_invoice_id', (string) ( $invoice['id'] ?? '' ) );
		law_event_update_meta( $booking_id, '_law_stripe_invoice_url', (string) ( $invoice['hosted_invoice_url'] ?? '' ) );
		law_event_update_meta( $booking_id, '_law_stripe_invoice_pdf', (string) ( $invoice['invoice_pdf'] ?? '' ) );

		// Reconcile against the snapshot. A mismatch never blocks the place —
		// the money genuinely arrived — but it is logged loudly.
		$paid  = (int) ( $invoice['amount_paid'] ?? 0 );
		$price = law_booking_price( $booking_id );
		if ( $paid > 0 && $price['gross'] > 0 && $paid !== $price['gross'] ) {
			law_event_log(
				(int) $booking->post_parent,
				sprintf(
					'AMOUNT MISMATCH on flagship application #%d: paid %s, expected %s. Review in Stripe.',
					law_event_meta( $booking_id, '_law_booking_number' ),
					law_events_format_pence( $paid ),
					law_events_format_pence( $price['gross'] )
				),
				array( 'source' => 'stripe_webhook', 'action' => 'amount_mismatch', 'booking' => $booking_id, 'paid' => $paid, 'expected' => $price['gross'] ),
				array( 'user_id' => 0 )
			);
		}
		law_flagship_store_charge_id( $booking_id, (string) ( $invoice['id'] ?? '' ) );
	}

	if ( 'publish' === $booking->post_status ) {
		// Already confirmed; the second caller only added the invoice links.
		return true;
	}
	delete_post_meta( $booking_id, '_law_payment_processing_at' );
	delete_post_meta( $booking_id, '_law_payment_stuck_flagged' );

	// Money arriving on an application the committee already refused, or the
	// delegate already withdrew, must NEVER quietly reverse that decision.
	// It is reachable: a finalised invoice keeps a payable hosted page, and
	// the "confirm your payment" email carries the link, so a delegate can
	// pay minutes after being declined. Confirm nothing, tell the committee,
	// and leave the refund to a human — the committee_cancelled_paid
	// precedent on the host side.
	if ( in_array( $booking->post_status, array( 'law-declined', 'law-cancelled' ), true ) ) {
		$number = (int) law_event_meta( $booking_id, '_law_booking_number' );
		law_event_log(
			(int) $booking->post_parent,
			sprintf(
				'PAYMENT ON A %1$s APPLICATION: #%2$d was paid (%3$s) after it was %4$s. The place has NOT been given. Review in Stripe and refund.',
				'law-declined' === $booking->post_status ? 'DECLINED' : 'WITHDRAWN',
				$number,
				law_events_format_pence( (int) ( $invoice['amount_paid'] ?? 0 ) ),
				'law-declined' === $booking->post_status ? 'declined' : 'withdrawn'
			),
			array(
				'source'  => $stripe_event_id ? 'stripe_webhook' : 'flagship',
				'action'  => 'flagship_paid_after_decision',
				'booking' => $booking_id,
			),
			array( 'user_id' => 0 )
		);
		law_events_send( 'committee_flagship_paid', (int) $booking->post_parent, array( 'placeholders' => law_flagship_email_extra( $booking_id )['placeholders'] ) );

		return false;
	}

	law_flagship_confirm( $booking_id, $actor_id, 'paid', $stripe_event_id );

	return true;
}

/** Flip a booking to Confirmed, recount, log and send the confirmation. */
function law_flagship_confirm( $booking_id, $actor_id, $how, $stripe_event_id = '' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking ) {
		return;
	}
	$event_id = (int) $booking->post_parent;
	$number   = (int) law_event_meta( $booking_id, '_law_booking_number' );

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'publish' );
	law_event_update_meta( $booking_id, '_law_payment_status', 'complimentary' === $how ? 'complimentary' : 'paid' );
	law_event_update_meta( $booking_id, '_law_reviewed_at', gmdate( 'Y-m-d H:i' ) );
	if ( $actor_id ) {
		law_event_update_meta( $booking_id, '_law_reviewed_by', (int) $actor_id );
	}
	delete_post_meta( $booking_id, '_law_payment_error' );
	delete_post_meta( $booking_id, '_law_payment_failed_at' );
	law_event_recount_attendees( $event_id, 'flagship' );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	$price = law_booking_price( $booking_id );
	law_event_log(
		$event_id,
		'complimentary' === $how
			? sprintf( 'Flagship application #%d approved as a complimentary place.', $number )
			: sprintf( 'Flagship application #%d approved and paid (%s).', $number, law_events_format_pence( $price['gross'] ) ),
		array(
			'source'       => $stripe_event_id ? 'stripe_webhook' : 'flagship',
			'action'       => 'flagship_approved',
			'booking'      => $booking_id,
			'amount'       => $price['gross'],
			'stripe_event' => $stripe_event_id,
		),
		array( 'user_id' => (int) $actor_id )
	);

	$extra = law_flagship_email_extra( $booking_id );
	law_booking_send_with_ics(
		'complimentary' === $how ? 'user_flagship_complimentary' : 'user_flagship_approved',
		$event_id,
		$extra
	);
}

/**
 * Decline an application: no charge, and the saved card is removed.
 *
 * @return true|WP_Error
 */
function law_flagship_decline( $booking_id, $actor_id, $reason = '' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship application.' );
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	if ( 'publish' === $booking->post_status ) {
		return new WP_Error( 'law_flagship_already_paid', 'That place is confirmed and paid for. Cancelling it is a refund decision, not a decline.' );
	}
	if ( 'law-declined' === $booking->post_status ) {
		return true;
	}
	// The same refusal withdraw makes, and for the same reason: declining
	// detaches the payment method and voids the invoice, and doing that
	// underneath a payment Stripe has already accepted strands money with
	// nothing to book it against. Wait for the webhook to resolve it, then
	// decline (or refund) with the facts known.
	if ( 'processing' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return new WP_Error(
			'law_flagship_payment_in_flight',
			'A payment for this application is already on its way. Wait for it to land before deciding, or it will need refunding by hand.'
		);
	}

	$reason = sanitize_textarea_field( (string) $reason );

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'law-declined' );
	law_event_update_meta( $booking_id, '_law_reviewed_at', gmdate( 'Y-m-d H:i' ) );
	law_event_update_meta( $booking_id, '_law_reviewed_by', (int) $actor_id );
	if ( '' !== $reason ) {
		law_event_update_meta( $booking_id, '_law_decline_reason', $reason );
	}
	law_event_recount_attendees( $event_id, 'flagship' );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	// Outside the lock: network calls must not hold up other applicants.
	// BOTH are needed. Detaching the card stops us charging them; voiding the
	// invoice stops THEM paying us from the hosted page whose link is already
	// in their inbox, which would otherwise reverse this decision.
	law_stripe_detach_payment_method( $booking_id, $actor_id );
	law_stripe_void_booking_invoice( $booking_id, $actor_id );

	law_event_log(
		$event_id,
		sprintf(
			'Flagship application #%d declined%s.',
			law_event_meta( $booking_id, '_law_booking_number' ),
			'' !== $reason ? ': ' . $reason : ''
		),
		array( 'source' => 'flagship', 'action' => 'flagship_declined', 'booking' => $booking_id ),
		array( 'user_id' => (int) $actor_id )
	);

	law_events_send( 'user_flagship_declined', $event_id, law_flagship_email_extra( $booking_id ) );

	return true;
}

/**
 * The delegate withdrawing their own application before it is charged.
 *
 * @return true|WP_Error
 */
function law_flagship_withdraw( $booking_id, $actor_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship application.' );
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	if ( 'publish' === $booking->post_status ) {
		return new WP_Error(
			'law_flagship_already_paid',
			'Your place is confirmed and paid for, so it cannot be withdrawn here. Please contact the organisers.'
		);
	}
	if ( 'law-cancelled' === $booking->post_status ) {
		return true;
	}
	// A charge already accepted by Stripe but not yet settled. Detaching the
	// payment method and voiding the invoice underneath a payment in flight
	// would leave money moving with nothing to book it against, so this is
	// refused until the webhook resolves it either way (minutes for a wallet,
	// days for a bank debit).
	if ( 'processing' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return new WP_Error(
			'law_flagship_payment_in_flight',
			'Your payment is on its way to us and cannot be stopped from here. Please contact the organisers.'
		);
	}

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'law-cancelled' );
	law_event_recount_attendees( $event_id, 'flagship' );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	law_stripe_detach_payment_method( $booking_id, $actor_id );
	law_stripe_void_booking_invoice( $booking_id, $actor_id );

	law_event_log(
		$event_id,
		sprintf( 'Flagship application #%d withdrawn by the applicant.', law_event_meta( $booking_id, '_law_booking_number' ) ),
		array( 'source' => 'flagship', 'action' => 'flagship_withdrawn', 'booking' => $booking_id ),
		array( 'user_id' => (int) $actor_id )
	);

	law_events_send( 'user_flagship_withdrawn', $event_id, law_flagship_email_extra( $booking_id ) );

	return true;
}

/* Payment exceptions _________________________________________________________ */

/**
 * Record a failed (or unauthenticated) charge and tell the delegate.
 *
 * @param string $status failed | action_required.
 */
/**
 * A charge that has been accepted but has not settled yet.
 *
 * Distinct from a failure and from SCA on purpose. Card payments settle
 * inside the approval request, so for most of 2026 this never fires; a
 * delegate paying by one of the other methods the LAW Stripe account offers
 * (EVENTS_4.2_SPECS.md §7.1: everything enabled bar Klarna) can leave the
 * money in flight for anything from seconds to days.
 *
 * The application therefore STAYS where it is, holding its place, with the
 * payment marked in progress. Nothing is emailed: there is no exception for
 * the delegate to answer, and an email saying so would only worry them. The
 * invoice.paid webhook confirms it when the money lands, and
 * invoice.payment_failed turns it into a real failure if it does not.
 *
 * @return bool
 */
function law_flagship_mark_payment_processing( $booking_id, $invoice_url = '', $actor = 0 ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type || ! law_flagship_booking_is( $booking ) ) {
		return false;
	}
	$booking_id = (int) $booking->ID;

	// A confirmed, paid place is never dragged back by a late report.
	if ( 'publish' === $booking->post_status && 'paid' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return false;
	}

	law_event_update_meta( $booking_id, '_law_payment_status', 'processing' );
	// Stamped so the daily sweep can notice one that never resolves: a
	// dropped webhook, or a bank debit that goes neither way. Without a
	// timestamp a stuck booking holds its place for ever and nothing says so.
	if ( '' === (string) law_event_meta( $booking_id, '_law_payment_processing_at' ) ) {
		law_event_update_meta( $booking_id, '_law_payment_processing_at', gmdate( 'Y-m-d H:i' ) );
	}
	delete_post_meta( $booking_id, '_law_payment_error' );
	if ( '' !== $invoice_url ) {
		law_event_update_meta( $booking_id, '_law_stripe_invoice_url', $invoice_url );
	}

	law_event_log(
		(int) $booking->post_parent,
		sprintf(
			'Flagship application #%1$d approved and charged with %2$s. The payment has not settled yet, so the place is held until it does.',
			law_event_meta( $booking_id, '_law_booking_number' ),
			law_booking_payment_method_label( $booking_id ) ?: 'the saved payment method'
		),
		array( 'source' => 'flagship', 'action' => 'flagship_payment_processing', 'booking' => $booking_id ),
		array( 'user_id' => (int) $actor )
	);

	return true;
}

function law_flagship_mark_payment_failed( $booking_id, $message, $status = 'failed', $invoice_url = '' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return false;
	}
	// Flagship only. These three carry FLAGSHIP semantics — its statuses, its
	// confirm and decline meaning — and the webhook reaches them for any
	// booking whose Stripe metadata names it. Nothing else attaches that
	// metadata today, but stripe/attendees.php is deliberately generic and a
	// priced reception is the intended second caller; without this it would
	// silently inherit `law-payment-failed` and this file's idea of what
	// "confirmed" means. Fail closed and say so, rather than quietly
	// mis-handling somebody's money.
	if ( ! law_flagship_booking_is( $booking ) ) {
		law_event_log(
			(int) $booking->post_parent,
			sprintf(
				'A payment event reached the flagship handler for booking #%d, which is not a flagship application. Nothing was changed: this flow needs its own handler.',
				(int) law_event_meta( $booking->ID, '_law_booking_number' )
			),
			array( 'source' => 'stripe_webhook', 'action' => 'flagship_handler_wrong_booking', 'booking' => (int) $booking->ID )
		);
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	// A confirmed, paid place is never dragged back by a late webhook.
	if ( 'publish' === $booking->post_status && 'paid' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return false;
	}

	$already = (string) law_event_meta( $booking_id, '_law_payment_status' );

	law_booking_set_status( $booking_id, 'law-payment-failed' );
	law_event_update_meta( $booking_id, '_law_payment_status', 'action_required' === $status ? 'action_required' : 'failed' );
	law_event_update_meta( $booking_id, '_law_payment_error', mb_substr( (string) $message, 0, 300 ) );
	if ( '' === (string) law_event_meta( $booking_id, '_law_payment_failed_at' ) ) {
		law_event_update_meta( $booking_id, '_law_payment_failed_at', gmdate( 'Y-m-d H:i' ) );
	}
	if ( '' !== $invoice_url ) {
		law_event_update_meta( $booking_id, '_law_stripe_invoice_url', $invoice_url );
	}
	law_event_recount_attendees( $event_id, 'flagship' );

	law_event_log(
		$event_id,
		sprintf(
			'action_required' === $status
				? 'Flagship application #%1$d: the bank asked the delegate to confirm the payment. %2$s'
				: 'Flagship application #%1$d: the card was declined. %2$s',
			law_event_meta( $booking_id, '_law_booking_number' ),
			$message
		),
		array( 'source' => 'flagship', 'action' => 'flagship_payment_failed', 'booking' => $booking_id, 'state' => $status ),
		array( 'user_id' => 0 )
	);

	// One email per failure, not one per webhook: Stripe can report the same
	// decline through more than one event type.
	if ( $already !== ( 'action_required' === $status ? 'action_required' : 'failed' ) ) {
		law_events_send(
			'action_required' === $status ? 'user_flagship_action_required' : 'user_flagship_payment_failed',
			$event_id,
			law_flagship_email_extra( $booking_id )
		);
	}

	return true;
}

/** Charge again after the delegate has replaced their card, or fixed it at the bank. */
function law_flagship_retry_charge( $booking_id, $actor_id = 0 ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship application.' );
	}
	if ( 'law-payment-failed' !== $booking->post_status ) {
		return new WP_Error( 'law_flagship_not_failed', 'That application is not waiting on a payment.' );
	}

	return law_flagship_approve( (int) $booking->ID, $actor_id, array( 'confirm_overbook' => true ) );
}

/** A refund landed against a booking. */
function law_flagship_mark_refunded( $booking_id, $refunded, $charged, $partial ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking ) {
		return false;
	}
	$event_id = (int) $booking->post_parent;

	if ( ! $partial ) {
		law_event_update_meta( (int) $booking->ID, '_law_payment_status', 'refunded' );
	}

	law_event_log(
		$event_id,
		sprintf(
			$partial
				? 'PARTIAL REFUND on flagship application #%1$d: %2$s of %3$s refunded. The place is unchanged; review in Stripe.'
				: 'Flagship application #%1$d refunded in full (%2$s of %3$s). The place is NOT cancelled automatically.',
			law_event_meta( $booking->ID, '_law_booking_number' ),
			law_events_format_pence( (int) $refunded ),
			law_events_format_pence( (int) $charged )
		),
		array( 'source' => 'stripe_webhook', 'action' => $partial ? 'partial_refund' : 'refunded', 'booking' => (int) $booking->ID ),
		array( 'user_id' => 0 )
	);
	law_events_send( 'committee_refund', $event_id );

	return true;
}

/** Capture the charge behind a paid booking invoice, so a refund can find it. */
function law_flagship_store_charge_id( $booking_id, $invoice_id ) {
	if ( '' === $invoice_id || '' !== (string) law_event_meta( $booking_id, '_law_stripe_charge_id' ) ) {
		return;
	}
	$invoice = law_stripe_request(
		'GET',
		'/v1/invoices/' . rawurlencode( $invoice_id ),
		array( 'expand' => array( 'payments.data.payment.payment_intent' ) )
	);
	if ( is_wp_error( $invoice ) ) {
		return;
	}
	$payments = (array) ( $invoice['payments']['data'] ?? array() );
	foreach ( $payments as $payment ) {
		$intent = $payment['payment']['payment_intent'] ?? null;
		$charge = is_array( $intent ) ? (string) ( $intent['latest_charge'] ?? '' ) : '';
		if ( '' !== $charge ) {
			law_event_update_meta( $booking_id, '_law_stripe_charge_id', $charge );
			return;
		}
	}
}

/* Committee extras ___________________________________________________________ */

/**
 * Put someone on the flagship with no card and no invoice: speakers, press,
 * sponsors, VIPs (Denis, 10 September 2026).
 *
 * @param array $row name, email, organisation, job_title, press.
 * @return int|WP_Error The booking ID.
 */
function law_flagship_add_complimentary( array $row, $actor_id ) {
	$event_id = law_flagship_event_id();
	$open     = law_flagship_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $open;
	}

	$row = array(
		'name'         => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
		'email'        => sanitize_email( (string) ( $row['email'] ?? '' ) ),
		'organisation' => sanitize_text_field( (string) ( $row['organisation'] ?? '' ) ),
		'job_title'    => sanitize_text_field( (string) ( $row['job_title'] ?? '' ) ),
		'press'        => ! empty( $row['press'] ),
	);
	if ( '' === $row['name'] ) {
		return new WP_Error( 'law_flagship_no_name', 'Please give the attendee a name.', array( 'field' => 'name' ) );
	}
	if ( ! is_email( $row['email'] ) ) {
		return new WP_Error( 'law_flagship_bad_email', 'Please give a valid email address.', array( 'field' => 'email' ) );
	}

	$resolved = law_booking_resolve_attendee_user( $row, $event_id, (int) $actor_id );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	$user_id = (int) $resolved['user_id'];

	$dup = law_booking_guard_duplicates(
		$event_id,
		array( array( 'user_id' => $user_id, 'email' => $row['email'], 'name' => $row['name'] ) ),
		law_booking_holding_statuses()
	);
	if ( is_wp_error( $dup ) ) {
		if ( ! empty( $resolved['created'] ) ) {
			law_booking_delete_created_users( array( $user_id ), $event_id, (int) $actor_id );
		}
		return $dup;
	}

	$locked     = law_booking_lock( $event_id );
	$numbers    = law_bookings_next_numbers( 1 );
	$booking_id = wp_insert_post(
		wp_slash(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_status' => 'publish',
				'post_parent' => $event_id,
				'post_author' => $user_id,
				'post_title'  => 'Booking #' . $numbers[0],
			)
		),
		true
	);
	if ( is_wp_error( $booking_id ) || ! $booking_id ) {
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
		if ( ! empty( $resolved['created'] ) ) {
			law_booking_delete_created_users( array( $user_id ), $event_id, (int) $actor_id );
		}
		return is_wp_error( $booking_id ) ? $booking_id : new WP_Error( 'law_flagship_insert_failed', 'That attendee could not be added.' );
	}
	$booking_id = (int) $booking_id;

	law_event_update_meta( $booking_id, '_law_booking_number', $numbers[0] );
	law_booking_write_attendee(
		$booking_id,
		array( 'user_id' => $user_id ) + $row,
		$user_id,
		! empty( $row['press'] )
	);
	law_event_update_meta( $booking_id, '_law_application_at', gmdate( 'Y-m-d H:i' ) );
	law_event_update_meta( $booking_id, '_law_is_complimentary', 1 );
	law_event_update_meta( $booking_id, '_law_payment_status', 'complimentary' );
	law_event_update_meta( $booking_id, '_law_price_pence', 0 );
	law_event_update_meta( $booking_id, '_law_reviewed_at', gmdate( 'Y-m-d H:i' ) );
	law_event_update_meta( $booking_id, '_law_reviewed_by', (int) $actor_id );
	update_post_meta( $booking_id, '_law_application_ready', 1 );
	law_event_recount_attendees( $event_id, 'flagship' );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	law_booking_grant_attendee_role( $user_id, $event_id, (int) $actor_id );

	$actor = get_user_by( 'id', (int) $actor_id );
	law_event_log(
		$event_id,
		sprintf(
			'Complimentary flagship place #%d added for %s by %s%s.',
			$numbers[0],
			$row['name'],
			$actor ? $actor->display_name : 'the committee',
			$row['press'] ? ' (press pass)' : ''
		),
		array( 'source' => 'flagship', 'action' => 'flagship_complimentary', 'booking' => $booking_id, 'press' => $row['press'] ),
		array( 'user_id' => (int) $actor_id )
	);

	law_booking_send_with_ics( 'user_flagship_complimentary', $event_id, law_flagship_email_extra( $booking_id ) );

	return $booking_id;
}

/* Emails _____________________________________________________________________ */

/** The recipient and placeholders every flagship email needs. */
function law_flagship_email_extra( $booking_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking ) {
		return array( 'to' => '', 'placeholders' => array() );
	}
	$event_id = (int) $booking->post_parent;
	$price    = law_booking_price( (int) $booking_id );
	$person   = law_booking_attendee( (int) $booking_id );
	$deadline = law_flagship_payment_deadline_ts( (int) $booking_id );

	return array(
		'to'           => $person['email'],
		'placeholders' => array_merge(
			law_booking_email_placeholders( (int) $booking_id ),
			array(
				'attendee_name'       => $person['name'],
				'price'               => law_events_format_pence( $price['net'] ),
				'price_vat'           => law_events_format_pence( $price['vat'] ),
				'price_total'         => law_events_format_pence( $price['gross'] ),
				'invoice_link'        => (string) law_event_meta( $booking_id, '_law_stripe_invoice_url' ),
				'decline_reason'      => (string) law_event_meta( $booking_id, '_law_decline_reason' ),
				'payment_error'       => (string) law_event_meta( $booking_id, '_law_payment_error' ),
				'payment_deadline'    => $deadline ? wp_date( 'j F Y', $deadline ) : '',
				// Both names resolve to the same thing: {card_label} is the
				// original and may be sitting in an email an admin has
				// already customised, {payment_method} is the honest one now
				// that Checkout can save more than a card.
				'card_label'          => law_booking_payment_method_label( $booking_id ),
				'payment_method'      => law_booking_payment_method_label( $booking_id ),
				'update_payment_link' => law_booking_manage_url( $booking_id ),
			)
		),
	);
}

/* Handlers ___________________________________________________________________
 *
 * All on the module's standing pattern: law_events_guard_post() for the nonce,
 * the honeypot and the rate limit, then law_events_respond() for the
 * JSON-or-redirect tail, with a nopriv twin answering JSON 401. Every one
 * works without JavaScript as a plain POST.
 *
 * IDOR rule throughout: load the booking, check its post type, derive the
 * event from post_parent, and only then check who is asking. Nothing trusts an
 * event ID out of the request.
 */

/** Load a booking this user may act on as its owner, or respond and exit. */
function law_flagship_require_own_booking( $is_ajax, array $statuses = array() ) {
	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	$booking    = $booking_id ? get_post( $booking_id ) : null;

	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That application could not be found.', 'status' => 404 ), 'flagship-failed' );
	}
	if ( (int) $booking->post_author !== get_current_user_id() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That application belongs to someone else.', 'status' => 403 ), 'flagship-denied' );
	}
	if ( $statuses && ! in_array( $booking->post_status, $statuses, true ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That application cannot be changed now.', 'status' => 409 ), 'flagship-failed' );
	}

	return $booking;
}

/** Load a booking the committee may act on, or respond and exit. */
function law_flagship_require_committee_booking( $is_ajax ) {
	if ( ! law_user_is_committee() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, reviewing applications is for the committee.', 'status' => 403 ), 'flagship-denied' );
	}
	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	$booking    = $booking_id ? get_post( $booking_id ) : null;
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That application could not be found.', 'status' => 404 ), 'flagship-failed' );
	}

	return $booking;
}

/* Applying */

add_action( 'admin_post_law_flagship_apply', 'law_flagship_apply_handler' );
add_action( 'admin_post_nopriv_law_flagship_apply', 'law_events_nopriv_json' );

function law_flagship_apply_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_apply',
		array(
			// The booking surface, shared with Register: one budget for "this
			// person is taking places", and a separate, larger per-IP budget so
			// a law firm behind one NAT cannot lock its own colleagues out.
			'rate'            => array( 'booking', 10, 600, 100 ),
			'honeypot_json'   => array( 'message' => 'Thank you, your application has been received.' ),
			'honeypot_notice' => 'flagship-applied',
		)
	);

	if ( ! is_user_logged_in() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Please sign in to apply.', 'status' => 401 ), 'flagship-denied' );
	}

	$input  = law_flagship_input_from_request();
	$result = law_flagship_apply( get_current_user_id(), $input );

	if ( is_wp_error( $result ) ) {
		law_flagship_store_form_state( $result, $input );
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'flagship-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Taking you to our payment page',
			'message'  => 'Stripe will ask for your card details. Nothing is charged unless your application is approved.',
			'redirect' => $result['redirect'],
		),
		'flagship-applied'
	);
}

/** Read the application form. */
function law_flagship_input_from_request() {
	$raw = isset( $_POST['law_flagship_apply'] ) ? wp_unslash( (array) $_POST['law_flagship_apply'] ) : array();

	$answers = array();
	foreach ( (array) ( $raw['answers'] ?? array() ) as $key => $value ) {
		if ( is_scalar( $value ) ) {
			$answers[ sanitize_key( $key ) ] = sanitize_textarea_field( (string) $value );
		}
	}

	return array(
		'answers'     => $answers,
		'consent'     => ! empty( $raw['consent'] ),
		'terms'       => ! empty( $raw['terms'] ),
		// The net price the form displayed, so law_flagship_apply() can
		// refuse rather than reprice under the delegate.
		'price_shown' => absint( $raw['price_shown'] ?? 0 ),
	);
}

/** One-shot re-population of a refused application, so nothing typed is lost. */
function law_flagship_store_form_state( WP_Error $error, array $input ) {
	set_transient(
		'law_flagship_apply_state_' . get_current_user_id(),
		array( 'errors' => $error->get_error_messages(), 'input' => $input ),
		10 * MINUTE_IN_SECONDS
	);
}

/** Read and clear it. */
function law_flagship_form_state() {
	$key   = 'law_flagship_apply_state_' . get_current_user_id();
	$state = get_transient( $key );
	if ( ! is_array( $state ) ) {
		return array( 'errors' => array(), 'input' => array() );
	}
	delete_transient( $key );

	return array(
		'errors' => (array) ( $state['errors'] ?? array() ),
		'input'  => (array) ( $state['input'] ?? array() ),
	);
}

/* The delegate's own actions */

add_action( 'admin_post_law_flagship_update_card', 'law_flagship_update_card_handler' );
add_action( 'admin_post_nopriv_law_flagship_update_card', 'law_events_nopriv_json' );

function law_flagship_update_card_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_update_card',
		array(
			'rate'          => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json' => array( 'message' => 'Done.' ),
		)
	);

	$booking = law_flagship_require_own_booking( $is_ajax, array( 'law-applied', 'law-payment-failed' ) );
	$reason  = 'law-payment-failed' === $booking->post_status ? 'retry' : 'replace';
	$url     = law_stripe_create_setup_session( (int) $booking->ID, $reason );

	if ( is_wp_error( $url ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $url->get_error_message(), 'status' => 502 ), 'flagship-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Taking you to our payment page',
			'message'  => 'Stripe will ask for your card details.',
			'redirect' => $url,
		),
		'flagship-card'
	);
}

add_action( 'admin_post_law_flagship_withdraw', 'law_flagship_withdraw_handler' );
add_action( 'admin_post_nopriv_law_flagship_withdraw', 'law_events_nopriv_json' );

function law_flagship_withdraw_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_withdraw',
		array(
			'rate'          => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json' => array( 'message' => 'Withdrawn.' ),
		)
	);

	$booking = law_flagship_require_own_booking( $is_ajax, array( 'law-applied', 'law-payment-failed' ) );
	$result  = law_flagship_withdraw( (int) $booking->ID, get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'flagship-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Application withdrawn',
			'message'  => 'Your application has been withdrawn and the card details we held have been removed.',
			'redirect' => law_account_url( 'my_bookings' ),
		),
		'flagship-withdrawn'
	);
}

/* The committee's actions */

add_action( 'admin_post_law_flagship_review', 'law_flagship_review_handler' );
add_action( 'admin_post_nopriv_law_flagship_review', 'law_events_nopriv_json' );

function law_flagship_review_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_review',
		array(
			// Its own surface: a committee member working through a queue of
			// fifty applications must not spend the event-submission budget.
			'rate'          => array( 'flagship_review', 60, 600, 300 ),
			'honeypot_json' => array( 'message' => 'Done.' ),
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, reviewing applications is for the committee.', 'status' => 403 ), 'flagship-denied' );
	}

	$decision = sanitize_key( (string) ( $_POST['decision'] ?? '' ) );
	if ( ! in_array( $decision, array( 'approve', 'decline' ), true ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Choose whether to approve or decline.', 'status' => 400 ), 'flagship-failed' );
	}

	$reason  = sanitize_textarea_field( wp_unslash( (string) ( $_POST['reason'] ?? '' ) ) );
	$confirm = ! empty( $_POST['confirm_overbook'] );
	$actor   = get_current_user_id();

	// One handler for one row and for a bulk selection: the single-row form
	// posts one ID in the same field, so there is one code path and one set
	// of guards rather than two that can drift.
	$ids = array_values( array_filter( array_map( 'absint', (array) ( $_POST['booking_id'] ?? array() ) ) ) );
	if ( ! $ids ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Choose at least one application.', 'status' => 400 ), 'flagship-failed' );
	}

	$result = law_flagship_review_bulk( $ids, $decision, $actor, $reason, $confirm );

	if ( $result['needs_confirm'] ) {
		law_events_respond(
			$is_ajax,
			false,
			array(
				'message'       => $result['message'],
				'needs_confirm' => true,
				'status'        => 409,
			),
			'flagship-full'
		);
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'approve' === $decision ? 'Applications approved' : 'Applications declined',
			'message'  => $result['message'],
			'redirect' => law_flagship_bookings_url(),
		),
		$result['failed'] ? 'flagship-partly-done' : ( 'approve' === $decision ? 'flagship-approved' : 'flagship-declined' )
	);
}

/**
 * Decide a batch of applications, time-boxed and resumable.
 *
 * Approving charges a card each, so a committee member ticking forty boxes
 * would otherwise hand PHP forty round trips to Stripe inside one request and
 * time out halfway, leaving some delegates charged and unconfirmed. The pass
 * stops at LAW_FLAGSHIP_BULK_CAP or LAW_FLAGSHIP_BULK_SECONDS and hands the
 * rest to cron, the same shape as the event-cancel sweep.
 *
 * @return array{done:int,failed:int,needs_confirm:bool,message:string,remaining:int}
 */
function law_flagship_review_bulk( array $booking_ids, $decision, $actor_id, $reason = '', $confirm_overbook = false ) {
	$started   = time();
	$done      = 0;
	$failed    = 0;
	$remaining = array();
	$messages  = array();

	// Deduplicate before counting anything. A repeated id is already safe —
	// the second approve finds the booking published and reports 'already',
	// and Stripe will not pay a paid invoice twice — but it would be counted
	// twice in "done", so the committee would be told it had approved more
	// people than it had.
	$booking_ids = array_values( array_unique( array_map( 'intval', $booking_ids ) ) );

	foreach ( $booking_ids as $i => $booking_id ) {
		if ( $done >= LAW_FLAGSHIP_BULK_CAP || ( time() - $started ) >= LAW_FLAGSHIP_BULK_SECONDS ) {
			$remaining = array_slice( $booking_ids, $i );
			break;
		}

		if ( 'decline' === $decision ) {
			$result = law_flagship_decline( $booking_id, $actor_id, $reason );
		} else {
			$result = law_flagship_approve( $booking_id, $actor_id, array( 'confirm_overbook' => $confirm_overbook ) );

			// The one refusal worth stopping the whole batch for: the
			// committee has to be told it is over-booking before it happens,
			// not afterwards on row eleven.
			if ( is_wp_error( $result ) && 'law_flagship_full' === $result->get_error_code() && ! $confirm_overbook ) {
				return array(
					'done'          => $done,
					'failed'        => $failed,
					'needs_confirm' => true,
					'message'       => $result->get_error_message(),
					'remaining'     => count( $booking_ids ) - $done,
				);
			}
		}

		if ( is_wp_error( $result ) ) {
			$failed++;
			$messages[] = $result->get_error_message();
			continue;
		}
		$done++;
	}

	if ( $remaining ) {
		wp_schedule_single_event(
			time() + 60,
			'law_flagship_resume_review',
			array( $remaining, $decision, (int) $actor_id, (string) $reason, (bool) $confirm_overbook )
		);
	}

	$message = sprintf(
		/* translators: 1: number decided, 2: approved|declined */
		_n( '%1$d application %2$s.', '%1$d applications %2$s.', $done, 'law' ),
		$done,
		'approve' === $decision ? 'approved' : 'declined'
	);
	if ( $failed ) {
		$message .= ' ' . sprintf(
			_n( '%d could not be: ', '%d could not be: ', $failed, 'law' ),
			$failed
		) . implode( ' ', array_unique( $messages ) );
	}
	if ( $remaining ) {
		$message .= ' ' . sprintf( 'The remaining %d will be processed in the background.', count( $remaining ) );
	}

	return array(
		'done'          => $done,
		'failed'        => $failed,
		'needs_confirm' => false,
		'message'       => $message,
		'remaining'     => count( $remaining ),
	);
}

add_action( 'law_flagship_resume_review', 'law_flagship_resume_review', 10, 5 );
function law_flagship_resume_review( $booking_ids, $decision, $actor_id, $reason, $confirm_overbook = false ) {
	// The committee's OWN answer is carried through, not assumed. A pass
	// usually stops on the ten-at-a-time cap rather than on a full
	// conference, so hardcoding "yes, over-book" here would have given away
	// the tail of every large batch past capacity with nobody ever asked.
	law_flagship_review_bulk(
		(array) $booking_ids,
		(string) $decision,
		(int) $actor_id,
		(string) $reason,
		(bool) $confirm_overbook
	);
}

add_action( 'admin_post_law_flagship_retry_charge', 'law_flagship_retry_charge_handler' );
add_action( 'admin_post_nopriv_law_flagship_retry_charge', 'law_events_nopriv_json' );

function law_flagship_retry_charge_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_retry_charge',
		array( 'rate' => array( 'flagship_review', 60, 600, 300 ), 'honeypot_json' => array( 'message' => 'Done.' ) )
	);

	$booking = law_flagship_require_committee_booking( $is_ajax );
	$result  = law_flagship_retry_charge( (int) $booking->ID, get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message(), 'status' => 400 ), 'flagship-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'confirmed' === $result['status'] ? 'Payment taken' : 'Still waiting on the delegate',
			'message'  => 'confirmed' === $result['status']
				? 'The card was charged and the place is confirmed.'
				: 'The card still needs the delegate to act. They have been emailed again.',
			'redirect' => law_flagship_bookings_url(),
		),
		'flagship-retried'
	);
}

add_action( 'admin_post_law_flagship_resend_payment', 'law_flagship_resend_payment_handler' );
add_action( 'admin_post_nopriv_law_flagship_resend_payment', 'law_events_nopriv_json' );

function law_flagship_resend_payment_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_resend_payment',
		array( 'rate' => array( 'flagship_review', 60, 600, 300 ), 'honeypot_json' => array( 'message' => 'Sent.' ) )
	);

	$booking = law_flagship_require_committee_booking( $is_ajax );
	if ( 'law-payment-failed' !== $booking->post_status ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That application is not waiting on a payment.', 'status' => 409 ), 'flagship-failed' );
	}

	$state = (string) law_event_meta( $booking->ID, '_law_payment_status' );
	law_events_send(
		'action_required' === $state ? 'user_flagship_action_required' : 'user_flagship_payment_failed',
		(int) $booking->post_parent,
		law_flagship_email_extra( (int) $booking->ID )
	);

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Payment request sent',
			'message'  => 'The delegate has been emailed again with a link to sort out the payment.',
			'redirect' => law_flagship_bookings_url(),
		),
		'flagship-resent'
	);
}

add_action( 'admin_post_law_flagship_add_attendee', 'law_flagship_add_attendee_handler' );
add_action( 'admin_post_nopriv_law_flagship_add_attendee', 'law_events_nopriv_json' );

function law_flagship_add_attendee_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_add_attendee',
		array( 'rate' => array( 'flagship_review', 60, 600, 300 ), 'honeypot_json' => array( 'message' => 'Added.' ) )
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, adding attendees is for the committee.', 'status' => 403 ), 'flagship-denied' );
	}

	$result = law_flagship_add_complimentary(
		array(
			'name'         => wp_unslash( (string) ( $_POST['name'] ?? '' ) ),
			'email'        => wp_unslash( (string) ( $_POST['email'] ?? '' ) ),
			'organisation' => wp_unslash( (string) ( $_POST['organisation'] ?? '' ) ),
			'job_title'    => wp_unslash( (string) ( $_POST['job_title'] ?? '' ) ),
			'press'        => ! empty( $_POST['law_press'] ),
		),
		get_current_user_id()
	);

	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'flagship-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Attendee added',
			'message'  => 'They have a confirmed place with no charge, and have been emailed their confirmation.',
			'redirect' => law_flagship_bookings_url(),
		),
		'flagship-added'
	);
}

/* Coming back from Stripe ____________________________________________________ */

/**
 * The delegate's return from the hosted card page.
 *
 * A template_redirect rather than an admin-post handler, because Stripe sends
 * them here with a GET it composed itself and no nonce of ours. That is safe:
 * nothing is decided from the URL. The session id is looked up in Stripe and
 * refused unless its metadata names THIS booking, so pasting somebody else's
 * session achieves nothing, and the webhook does the same work anyway.
 */
add_action( 'template_redirect', 'law_flagship_handle_setup_return' );
function law_flagship_handle_setup_return() {
	// Abandoning Stripe's page comes back with ?law_setup=cancelled and no
	// session. Say so, rather than leaving the delegate on a page that looks
	// exactly as it did before they left it.
	if ( isset( $_GET['law_setup'] ) && 'cancelled' === $_GET['law_setup'] && is_user_logged_in() ) {
		$cancelled = absint( $_GET['law_booking'] ?? 0 );
		if ( $cancelled && law_flagship_booking_is( $cancelled )
			&& (int) get_post_field( 'post_author', $cancelled ) === get_current_user_id() ) {
			wp_safe_redirect( add_query_arg( 'law_notice', 'flagship-card-cancelled', law_booking_manage_url( $cancelled ) ) );
			exit;
		}
	}
	if ( empty( $_GET['law_setup_session'] ) || ! is_user_logged_in() ) {
		return;
	}
	$booking_id = absint( $_GET['law_booking'] ?? 0 );
	$booking    = $booking_id ? get_post( $booking_id ) : null;
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return;
	}
	if ( (int) $booking->post_author !== get_current_user_id() ) {
		return;
	}

	$session = sanitize_text_field( wp_unslash( (string) $_GET['law_setup_session'] ) );
	$stored  = law_stripe_attach_setup_result( $booking_id, $session );

	$notice = 'flagship-card';
	if ( is_wp_error( $stored ) ) {
		$notice = 'flagship-card-failed';
	} else {
		law_flagship_on_card_saved( $booking_id );
		// A payment method added after a decline retries the charge at once,
		// so one round trip fixes it rather than waiting on the committee
		// again.
		if ( 'law-payment-failed' === $booking->post_status ) {
			$retried = law_flagship_retry_charge( $booking_id, 0 );

			// Report the OUTCOME, not the attempt. "We have tried the payment
			// again" sat above the red "We could not take your payment" panel
			// and read as two contradictory answers to the same question
			// (Denis, 10 September 2026). A success gets its own notice; any
			// other result gets none, because the panel below already says
			// what happened, in more detail, and says it once.
			$notice = ( ! is_wp_error( $retried ) && 'confirmed' === ( $retried['status'] ?? '' ) )
				? 'flagship-paid'
				: '';
		}
	}

	$back = law_booking_manage_url( $booking_id );
	wp_safe_redirect( '' === $notice ? $back : add_query_arg( 'law_notice', $notice, $back ) );
	exit;
}

/* Housekeeping _______________________________________________________________ */

/**
 * Close applications whose card never arrived, and chase payments that have
 * run out of time.
 *
 * Neither is destructive of anything anyone decided: an abandoned application
 * was never in the committee's queue, and an unpaid one is escalated to a
 * human rather than declined automatically (FLAGSHIP_PAYMENTS.md §5.4).
 */
add_action( 'law_flagship_daily', 'law_flagship_run_daily' );
function law_flagship_run_daily() {
	$event_id = law_flagship_event_id();
	if ( ! $event_id ) {
		return;
	}

	$cutoff = time() - ( LAW_FLAGSHIP_SETUP_GRACE_HOURS * HOUR_IN_SECONDS );
	// Status 'law-applied' as well as payment 'pending_setup': the payment
	// state stays pending_setup after the booking is closed, so filtering on
	// it alone re-closed and re-logged every abandoned application every day
	// for ever, and wrote "no card details were given" against people who had
	// deliberately withdrawn.
	foreach ( law_flagship_applications( array( 'payment' => 'pending_setup', 'status' => 'law-applied' ) ) as $post ) {
		$applied = strtotime( (string) law_event_meta( $post->ID, '_law_application_at' ) . ' UTC' );
		if ( ! $applied || $applied > $cutoff ) {
			continue;
		}
		law_booking_set_status( (int) $post->ID, 'law-cancelled' );
		law_event_log(
			$event_id,
			sprintf(
				'Flagship application #%d closed: no card details were given within %d hours.',
				law_event_meta( $post->ID, '_law_booking_number' ),
				LAW_FLAGSHIP_SETUP_GRACE_HOURS
			),
			array( 'source' => 'flagship', 'action' => 'flagship_abandoned', 'booking' => (int) $post->ID ),
			array( 'user_id' => 0 )
		);
	}

	foreach ( law_flagship_applications( array( 'status' => 'law-payment-failed' ) ) as $post ) {
		$deadline = law_flagship_payment_deadline_ts( (int) $post->ID );
		if ( ! $deadline || $deadline > time() || law_event_meta( $post->ID, '_law_payment_chased' ) ) {
			continue;
		}
		update_post_meta( (int) $post->ID, '_law_payment_chased', 1 );
		law_event_log(
			$event_id,
			sprintf(
				'Flagship application #%d is still unpaid past its deadline. The committee has been alerted; nothing has been decided automatically.',
				law_event_meta( $post->ID, '_law_booking_number' )
			),
			array( 'source' => 'flagship', 'action' => 'flagship_payment_overdue', 'booking' => (int) $post->ID ),
			array( 'user_id' => 0 )
		);
		law_events_send( 'committee_flagship_payment_failed', $event_id, array( 'placeholders' => law_flagship_email_extra( (int) $post->ID )['placeholders'] ) );
	}

	// A payment Stripe accepted but never resolved: a dropped webhook, or a
	// slow method that goes neither way. The booking sits in 'processing'
	// holding a place, and every action on it is deliberately refused, so
	// nothing would ever surface it. ALERT only — never decide. A human
	// looks it up in Stripe and settles it either way, which is the same
	// posture as the overdue-payment sweep above.
	$stuck_before = time() - ( LAW_FLAGSHIP_PROCESSING_ALERT_DAYS * DAY_IN_SECONDS );
	foreach ( law_flagship_applications( array( 'payment' => 'processing' ) ) as $post ) {
		if ( law_event_meta( $post->ID, '_law_payment_stuck_flagged' ) ) {
			continue;
		}
		$since = strtotime( (string) law_event_meta( $post->ID, '_law_payment_processing_at' ) . ' UTC' );
		if ( ! $since || $since > $stuck_before ) {
			continue;
		}
		update_post_meta( (int) $post->ID, '_law_payment_stuck_flagged', 1 );
		law_event_log(
			$event_id,
			sprintf(
				'ACTION NEEDED: the payment for flagship application #%1$d has been in progress since %2$s and has not settled. Check it in Stripe: it needs confirming or refunding by hand. Nothing has been decided automatically.',
				law_event_meta( $post->ID, '_law_booking_number' ),
				law_event_meta( $post->ID, '_law_payment_processing_at' )
			),
			array( 'source' => 'flagship', 'action' => 'flagship_payment_stuck', 'booking' => (int) $post->ID ),
			array( 'user_id' => 0 )
		);
		law_events_send( 'committee_flagship_payment_failed', $event_id, array( 'placeholders' => law_flagship_email_extra( (int) $post->ID )['placeholders'] ) );
	}
}

add_action( 'init', 'law_flagship_schedule_daily' );
function law_flagship_schedule_daily() {
	if ( ! wp_next_scheduled( 'law_flagship_daily' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'law_flagship_daily' );
	}
}
