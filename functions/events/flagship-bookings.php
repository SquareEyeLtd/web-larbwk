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

/**
 * Is this booking an application to the flagship?
 *
 * The kind question moved to law_booking_kind() (bookings.php) when the
 * receptions became a third kind; this stays as the flagship's own word for
 * it, which is what the rest of this file reads.
 */
function law_flagship_booking_is( $booking ) {
	return 'flagship' === law_booking_kind( $booking );
}

/** Days a delegate has to fix a failed payment. */
function law_flagship_payment_window_days() {
	return law_booking_payment_window_days();
}

/**
 * The deadline a failed payment must be fixed by, as a timestamp; 0 when the
 * booking has not failed.
 */
function law_flagship_payment_deadline_ts( $booking_id ) {
	return law_booking_payment_deadline_ts( $booking_id );
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
 * @param array $filters status, payment, kw, complimentary, ticket.
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
	$ticket  = (string) ( $filters['ticket'] ?? '' );

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
		if ( '' !== $ticket && (string) law_event_meta( $post->ID, '_law_ticket_type' ) !== $ticket ) {
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
		return new WP_Error( 'law_flagship_no_user', 'You need to be signed in to register.' );
	}
	$open = law_flagship_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $open;
	}
	if ( empty( $input['consent'] ) ) {
		return new WP_Error(
			'law_flagship_no_consent',
			'Please confirm you agree to your card being saved and charged if your registration is approved.',
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
				'Please add your %s to your profile before registering.',
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

	$code    = trim( (string) ( $input['code'] ?? '' ) );
	$applied = trim( (string) ( $input['applied_code'] ?? '' ) );

	// A code typed but never checked would otherwise be applied here and then
	// refused as a price change, which is untrue and reads as a bug. Ask for
	// the press instead. Skipped when the request is not AJAX, because the
	// no-JS form has no Apply button to press.
	$code_applied = law_discount_normalise_code( $code ) === law_discount_normalise_code( $applied );
	if ( ! $code_applied && ! empty( $input['ajax'] ) ) {
		return new WP_Error(
			'law_flagship_code_unapplied',
			'Press Apply to check your code before continuing.',
			array( 'field' => 'law_discount_code' )
		);
	}

	// The price the delegate is consenting to, frozen now. Deliberately NOT
	// read again at approval: they agreed to this figure (FLAGSHIP_PAYMENTS.md
	// §0.2), and law_event_snapshot_fee() sets the same precedent on the host
	// side. law_flagship_quote() refuses outright when the LIST price is 0,
	// so "not on sale" still means not on sale and only a code can make a
	// registration free.
	$quote = law_flagship_quote( $event_id, $code, $user_id );
	if ( is_wp_error( $quote ) ) {
		return $quote;
	}
	$list_pence = (int) $quote['list_net'];

	// The form posts the gross it displayed. If it no longer matches — the
	// delegate had the page open across the cutover, or the committee edited
	// the price while they were typing — refuse rather than silently
	// snapshotting a figure they never saw and never consented to. The whole
	// point of _law_payment_consent_at is that it evidences agreement to an
	// amount.
	$shown = law_booking_guard_price_shown(
		(int) ( $input['price_shown'] ?? 0 ),
		law_booking_quote_expected_gross( $quote, $code_applied ),
		'law_flagship_price_changed'
	);
	if ( is_wp_error( $shown ) ) {
		return new WP_Error(
			'law_flagship_price_changed',
			sprintf(
				'The price changed to %s while you were filling this in, so nothing has been submitted. Please check the new price and register again.',
				law_events_format_pence( law_booking_quote_expected_gross( $quote, $code_applied ) )
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

	// The code is CLAIMED before the insert, never after. Its conditional
	// UPDATE is what makes two people redeeming the last use safe, and
	// claiming first means a lost race refuses with nothing written to undo.
	if ( $quote['discount_id'] && ! law_discount_claim( $quote['discount_id'] ) ) {
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
		// Deliberately specific, unlike every refusal from
		// law_discount_validate(): by this point the code has already
		// validated, so the delegate has proved they know it and there is
		// nothing left to leak. "Not valid" here would be a lie about a code
		// that was valid a second ago.
		return new WP_Error(
			'law_discount_used_up',
			'That discount code has already been used the maximum number of times.',
			array( 'field' => 'law_discount_code' )
		);
	}

	// _law_application_answers stays in the schema and stays empty: the form
	// asks nothing, and the committee's view reads the profile live. It is
	// reserved for the spec's "any other questions the organisers add"
	// (§5.1), which is the only thing that would ever have no home on a
	// profile. law_flagship_apply() still accepts an `answers` array so that
	// work needs no change here.
	//
	// The receptions ticked on the application ride along as
	// _law_reception_choices and are granted when the place is confirmed
	// (RECEPTIONS.md §7.2). Stored, not acted on: nothing is included until
	// there is a confirmed flagship place to include it with.
	$meta = array(
		'_law_application_at'     => gmdate( 'Y-m-d H:i' ),
		// The DISCOUNTED net, which is what keeps law_stripe_charge_booking()
		// and law_flagship_mark_paid() unchanged: both read
		// law_booking_price(), which reads this, so nothing subtracts the code
		// twice. _law_discount_pence is kept for the record, not for the sum.
		'_law_price_pence'        => (int) $quote['net'],
		'_law_vat'                => $quote['net'] > 0 ? 1 : 0,
		'_law_payment_consent_at' => gmdate( 'Y-m-d H:i' ),
		// A code that covers the whole price asks for no payment method, so
		// the registration must NOT sit in pending_setup: that is the state
		// the 48-hour abandonment sweep closes, saying no card details were
		// given.
		'_law_payment_status'     => $quote['free'] ? 'no_charge' : 'pending_setup',
	);
	if ( $quote['discount_id'] ) {
		$meta['_law_discount_id']    = (int) $quote['discount_id'];
		$meta['_law_discount_code']  = (string) $quote['code'];
		$meta['_law_discount_pence'] = (int) $quote['discount'];
	}
	if ( ! empty( $input['answers'] ) ) {
		$meta['_law_application_answers'] = (array) $input['answers'];
	}
	$receptions = array_values( array_filter( array_map( 'absint', (array) ( $input['receptions'] ?? array() ) ) ) );
	if ( $receptions && function_exists( 'law_reception_included_ids' ) ) {
		// Only receptions that really are included, so a forged checkbox
		// cannot store a claim on anything else.
		$receptions = array_values( array_intersect( $receptions, law_reception_included_ids() ) );
	}
	if ( $receptions ) {
		$meta['_law_reception_choices'] = $receptions;
	}

	$booking_id = law_booking_insert( $event_id, $user_id, 'law-applied', $person, $meta );
	if ( is_wp_error( $booking_id ) ) {
		if ( $quote['discount_id'] ) {
			law_discount_release( $quote['discount_id'] );
		}
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
		return $booking_id;
	}
	$booking_id = (int) $booking_id;
	$number     = (int) law_event_meta( $booking_id, '_law_booking_number' );

	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	// Everything slow happens after the lock: Stripe and the emails, so one
	// delegate is never queued behind another's network.
	if ( $quote['discount_id'] ) {
		law_discount_log( $quote['discount_id'], $booking_id, 'claimed' );
	}
	law_event_log(
		$event_id,
		sprintf(
			'Flagship registration #%1$d received from %2$s (%3$s%4$s).',
			$number,
			$person['name'],
			law_events_format_pence( $quote['gross'] ),
			$quote['discount'] > 0
				? sprintf( ' after %s off with code %s', law_events_format_pence( $quote['discount'] ), $quote['code'] )
				: ''
		),
		array(
			'source'  => 'flagship',
			'action'  => 'flagship_applied',
			'booking' => $booking_id,
			'price'   => (int) $quote['net'],
			'list'    => $list_pence,
			'discount' => (int) $quote['discount'],
			'code'    => (string) $quote['code'],
			'receptions' => $receptions,
		),
		array( 'user_id' => $user_id )
	);
	if ( $receptions ) {
		law_event_log(
			$event_id,
			sprintf(
				'Flagship registration #%d asked for the included receptions: %s.',
				$number,
				implode( ', ', array_map( 'get_the_title', $receptions ) )
			),
			array( 'source' => 'flagship', 'action' => 'flagship_reception_choices', 'booking' => $booking_id, 'receptions' => $receptions ),
			array( 'user_id' => $user_id )
		);
	}

	// A 100% code: there is nothing for Stripe to hold, so no payment method
	// is asked for and the registration goes straight into the committee's
	// queue. The code removes the PAYMENT, not the review (Denis, 15 September
	// 2026): the flagship is approval-gated, and a code in somebody's hand is
	// not a decision to give them a place.
	if ( $quote['free'] ) {
		law_flagship_mark_ready( $booking_id, 'user_flagship_applied_free' );

		return array(
			'booking'  => $booking_id,
			'free'     => true,
			'redirect' => add_query_arg( 'law_notice', 'flagship-free-received', law_booking_manage_url( $booking_id ) ),
		);
	}

	$url = law_stripe_create_setup_session( $booking_id, 'apply' );
	if ( is_wp_error( $url ) ) {
		// The application survives: the delegate can add their card from My
		// bookings rather than typing everything again. The code stays claimed
		// with it, and the 48-hour sweep releases it if they never come back.
		return array( 'booking' => $booking_id, 'free' => false, 'redirect' => law_booking_manage_url( $booking_id ) );
	}

	return array( 'booking' => $booking_id, 'free' => false, 'redirect' => $url );
}

/** Is the flagship taking applications at all? Capacity is NOT a reason not to. */
function law_flagship_guard_open( $event_id = 0 ) {
	$event_id = (int) $event_id ? (int) $event_id : law_flagship_event_id();
	$post     = $event_id ? get_post( $event_id ) : null;

	if ( ! $post || LAW_EVENT_CPT !== $post->post_type || 'cpt' !== law_events_source() ) {
		return new WP_Error( 'law_flagship_missing', 'The flagship conference is not open for registration.' );
	}
	if ( 'publish' !== $post->post_status ) {
		return new WP_Error( 'law_flagship_unpublished', 'The flagship conference is not open for registration yet.' );
	}
	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
		return new WP_Error( 'law_flagship_started', 'This event has taken place, so registration is closed.' );
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
	return law_booking_profile_gaps( $user_id );
}

/** The snapshot row the booking carries, from the profile and the form. */
function law_flagship_person_from_input( $user_id, array $input ) {
	return law_booking_person_from_profile( $user_id, (array) ( $input['answers'] ?? array() ) );
}

/* The payment handler row ____________________________________________________ */

/**
 * How a flagship application answers each payment outcome
 * (law_booking_payment_handlers(), bookings.php).
 *
 * Registered through the filter rather than named in stripe/webhook.php, so
 * the webhook stays a router and this file stays the only place that knows
 * what an approval, a decline and a failed charge mean here.
 */
add_filter(
	'law_booking_payment_handlers',
	function ( array $table ) {
		$table['flagship'] = array(
			'card_saved'      => function ( $booking_id ) {
				return law_flagship_on_card_saved( $booking_id );
			},
			'setup_failed'    => function ( $booking_id, array $object ) {
				return law_flagship_on_card_setup_failed(
					$booking_id,
					(string) ( $object['last_setup_error']['message'] ?? 'The card could not be saved.' )
				);
			},
			'paid'            => function ( $booking_id, array $object, $stripe_event_id ) {
				return law_flagship_mark_paid( $booking_id, $object, $stripe_event_id );
			},
			'processing'      => function ( $booking_id, array $object ) {
				return law_flagship_mark_payment_processing( $booking_id, (string) ( $object['hosted_invoice_url'] ?? '' ) );
			},
			'payment_failed'  => function ( $booking_id, array $object ) {
				return law_flagship_mark_payment_failed(
					$booking_id,
					(string) (
						$object['last_payment_error']['message']
						?? $object['last_finalization_error']['message']
						?? 'The payment was declined.'
					)
				);
			},
			'action_required' => function ( $booking_id, array $object ) {
				return law_flagship_mark_payment_failed(
					$booking_id,
					'Your bank needs you to confirm this payment.',
					'action_required',
					(string) ( $object['hosted_invoice_url'] ?? '' )
				);
			},
			'refunded'        => function ( $booking_id, array $object ) {
				$refunded = (int) ( $object['amount_refunded'] ?? 0 );
				$charged  = (int) ( $object['amount'] ?? 0 );

				return law_flagship_mark_refunded(
					$booking_id,
					$refunded,
					$charged,
					$charged > 0 && $refunded > 0 && $refunded < $charged
				);
			},
			// A flagship SETUP session expiring means only that the delegate
			// did not finish saving a card; the 48-hour sweep closes the
			// application, and nothing here should pre-empt it.
		);

		return $table;
	}
);

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
				'A payment event reached the flagship handler for booking #%d, which is not a flagship registration. Nothing was changed: this flow needs its own handler.',
				(int) law_event_meta( $booking->ID, '_law_booking_number' )
			),
			array( 'source' => 'stripe_webhook', 'action' => 'flagship_handler_wrong_booking', 'booking' => (int) $booking->ID )
		);
		return false;
	}
	return law_flagship_mark_ready( (int) $booking->ID );
}

/**
 * Put a registration into the committee's queue: latch it, log it, and send
 * the two emails that say it is there.
 *
 * Its own function since 15 September 2026, when a discount code covering the
 * whole price gave the flagship a second way to become reviewable. A free
 * registration never goes to Stripe, so nothing would ever have called the
 * card-saved path, and it would have sat in the queue with neither the
 * delegate nor the committee told it existed.
 *
 * Idempotent: the one-shot latch is claimed rather than read and written
 * (law_booking_claim_latch(), bookings.php explains why), so however many of
 * the paths that report a ready registration arrive, this happens once.
 *
 * @param int    $booking_id     The registration.
 * @param string $delegate_email Which acknowledgement the delegate gets: the
 *                               standard one says their payment details are
 *                               saved and will be charged if approved, which
 *                               is false when a code covers the whole price.
 *                               Name the early-side slug; the price cutover's
 *                               twin is chosen from it by
 *                               law_flagship_applied_email().
 * @return bool Whether this call was the one that latched.
 */
function law_flagship_mark_ready( $booking_id, $delegate_email = 'user_flagship_applied' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	$claimed = law_booking_claim_latch( $booking_id, '_law_application_ready' );
	if ( ! $claimed ) {
		return false;
	}

	law_event_log(
		$event_id,
		sprintf( 'Flagship registration #%d is ready for review.', law_event_meta( $booking_id, '_law_booking_number' ) ),
		array( 'source' => 'flagship', 'action' => 'flagship_ready', 'booking' => $booking_id ),
		array( 'user_id' => 0 )
	);

	$extra = law_flagship_email_extra( $booking_id );
	law_events_send( law_flagship_applied_email( $booking, (string) $delegate_email ), $event_id, $extra );
	law_events_send( 'committee_flagship_application', $event_id, array( 'placeholders' => $extra['placeholders'] ) );

	return true;
}

/**
 * Which of an acknowledgement's two templates this registration gets: the one
 * for its side of the price cutover (Denis, 17 September 2026).
 *
 * The side is decided by WHEN THE DELEGATE REGISTERED, not by when this runs.
 * They are usually the same minute, but not always: a card setup that returns
 * from Stripe after midnight, or a free registration marked ready by a later
 * path, would otherwise be acknowledged at a rate the delegate was never
 * quoted. The booking's own creation time is the moment law_booking_quote()
 * priced it, so keying off it makes the email and the price agree by
 * construction.
 *
 * Falls back to the early template whenever the late twin is not in the
 * registry, so a caller passing an unpaired slug still sends something.
 *
 * @param int|WP_Post $booking The registration.
 * @param string      $base    The early-side slug.
 * @return string An email registry slug.
 */
function law_flagship_applied_email( $booking, $base = 'user_flagship_applied' ) {
	$booking = get_post( $booking );
	$base    = (string) $base;
	if ( ! $booking ) {
		return $base;
	}

	$at = (int) get_post_time( 'U', true, $booking );
	if ( $at < 1 ) {
		$at = (int) current_time( 'timestamp', true );
	}
	if ( ! law_flagship_price_is_late( $at, (int) $booking->post_parent ) ) {
		return $base;
	}

	$late = $base . '_late';
	return law_events_email( $late ) ? $late : $base;
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
			'Card details could not be saved for flagship registration #%d: %s',
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
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship registration.' );
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
		return new WP_Error( 'law_flagship_not_reviewable', 'That registration has already been decided.' );
	}
	$payment_state = (string) law_event_meta( $booking_id, '_law_payment_status' );
	if ( 'pending_setup' === $payment_state ) {
		$release();
		return new WP_Error( 'law_flagship_no_card', 'That delegate has not given their payment details yet, so there is nothing to charge.' );
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
			'A payment for this registration is already on its way. It will confirm itself when the money lands.'
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
					'The conference is full (%d of %d places taken). Approving this registration over-books it.',
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
		// Free for two different reasons, and they must not be told the same
		// way: the committee GAVE a complimentary place, whereas a delegate
		// BROUGHT a code that covered the price. Saying "with our compliments"
		// to the second is wrong about who did what.
		$how = ( (int) law_event_meta( $booking_id, '_law_discount_pence' ) > 0
			&& ! law_event_meta( $booking_id, '_law_is_complimentary' ) )
			? 'code'
			: 'complimentary';
		law_flagship_confirm( $booking_id, $actor_id, $how );

		return array( 'status' => 'confirmed', 'overbooked' => $overbooked );
	}

	// Claim the charge before letting go of the lock. add_post_meta with
	// $unique is a single atomic INSERT, so exactly one request wins and the
	// loser is told to wait rather than raising a second invoice.
	if ( ! law_flagship_claim_charge( $booking_id ) ) {
		$release();
		return new WP_Error(
			'law_flagship_charging',
			'A payment for this registration is already being taken. Give it a moment and reload before trying again.'
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
		// telling forty delegates their card was declined because a settings
		// field is blank would be both false and alarming. Those stop here,
		// loudly, with the application untouched.
		if ( law_flagship_is_configuration_error( $invoice ) ) {
			law_event_log(
				$event_id,
				sprintf(
					'ACTION NEEDED: flagship registration #%d could not be charged because of a configuration problem, so nothing was changed: %s',
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
				'Flagship registration #%d approved, but the card was declined: %s',
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
				'OVER-BOOKED: approving registration #%d takes the flagship to %d confirmed places against %d available.',
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
	return law_booking_is_configuration_error( $error );
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
	return law_booking_claim_charge( $booking_id );
}

/** Give it back, whatever happened. */
function law_flagship_release_charge( $booking_id ) {
	law_booking_release_charge( $booking_id );
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
				'A payment event reached the flagship handler for booking #%d, which is not a flagship registration. Nothing was changed: this flow needs its own handler.',
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
		$price = law_booking_price( $booking_id );
		law_booking_log_amount_mismatch( $booking_id, (int) ( $invoice['amount_paid'] ?? 0 ), $price['gross'], 'stripe_webhook' );
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
				'PAYMENT ON A %1$s REGISTRATION: #%2$d was paid (%3$s) after it was %4$s. The place has NOT been given. Review in Stripe and refund.',
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

/**
 * Flip a booking to Confirmed, recount, log and send the confirmation.
 *
 * @param string $how 'paid' (a charge went through), 'complimentary' (the
 *                    committee gave the place) or 'code' (a discount code
 *                    covered the whole price). The last two are both free and
 *                    are deliberately NOT interchangeable: they differ in who
 *                    did what, which is the whole of what the email says.
 */
function law_flagship_confirm( $booking_id, $actor_id, $how, $stripe_event_id = '' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking ) {
		return;
	}
	$event_id = (int) $booking->post_parent;
	$number   = (int) law_event_meta( $booking_id, '_law_booking_number' );

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'publish' );
	// 'code' settles as paid with a gross of 0, the same terminal state a
	// fully discounted reception reaches: law_reception_grant_choices() and
	// law_booking_cancel() both already understand "paid, nothing owed", and a
	// novel terminal state would have silently stopped the included receptions
	// being granted.
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
		law_flagship_confirm_log_line( $booking_id, $how, $number, $price ),
		array(
			'source'       => $stripe_event_id ? 'stripe_webhook' : 'flagship',
			'action'       => 'flagship_approved',
			'booking'      => $booking_id,
			'amount'       => $price['gross'],
			'stripe_event' => $stripe_event_id,
		),
		array( 'user_id' => (int) $actor_id )
	);

	// The receptions the delegate ticked when they applied are granted NOW,
	// because now is when the place they come with is real (RECEPTIONS.md
	// §7.2). Before the approval email, so it can say what was added.
	$receptions = array();
	if ( function_exists( 'law_reception_grant_choices' ) ) {
		$choices = array_map( 'absint', (array) law_event_meta( $booking_id, '_law_reception_choices' ) );
		if ( $choices ) {
			$receptions = law_reception_grant_choices( $booking_id, $choices, (int) $actor_id, 'flagship_confirm' );
		}
	}

	$extra = law_flagship_email_extra( $booking_id );
	$extra['placeholders']['payment_note'] = law_flagship_payment_note( $booking_id );
	if ( $receptions ) {
		$extra['placeholders']['included_receptions'] = law_reception_choices_note( $receptions );
	}
	$templates = array(
		'complimentary' => 'user_flagship_complimentary',
		'code'          => 'user_flagship_approved_free',
	);
	law_booking_send_with_ics( $templates[ $how ] ?? 'user_flagship_approved', $event_id, $extra );
}

/** The activity-log sentence for one approval, in its own words per outcome. */
function law_flagship_confirm_log_line( $booking_id, $how, $number, array $price ) {
	if ( 'complimentary' === $how ) {
		return sprintf( 'Flagship registration #%d approved as a complimentary place.', $number );
	}
	if ( 'code' === $how ) {
		return sprintf(
			'Flagship registration #%1$d approved. Discount code %2$s covered the whole price (%3$s off), so nothing was charged.',
			$number,
			law_event_meta( $booking_id, '_law_discount_code' ),
			law_events_format_pence( (int) law_event_meta( $booking_id, '_law_discount_pence' ) )
		);
	}

	return sprintf( 'Flagship registration #%d approved and paid (%s).', $number, law_events_format_pence( $price['gross'] ) );
}

/**
 * Decline an application: no charge, and the saved card is removed.
 *
 * @return true|WP_Error
 */
function law_flagship_decline( $booking_id, $actor_id, $reason = '' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship registration.' );
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
			'A payment for this registration is already on its way. Wait for it to land before deciding, or it will need refunding by hand.'
		);
	}

	$reason = sanitize_textarea_field( (string) $reason );

	$payment_state = (string) law_event_meta( $booking_id, '_law_payment_status' );

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'law-declined' );
	// The code's use goes back with the place: this delegate is not coming, so
	// somebody else may have it.
	law_booking_release_discount( $booking_id, $payment_state );
	law_event_update_meta( $booking_id, '_law_reviewed_at', gmdate( 'Y-m-d H:i' ) );
	law_event_update_meta( $booking_id, '_law_reviewed_by', (int) $actor_id );
	if ( '' !== $reason ) {
		law_event_update_meta( $booking_id, '_law_decline_reason', $reason );
	}
	law_event_recount_attendees( $event_id, 'flagship' );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	// Outside the lock: network calls must not hold up other delegates.
	// BOTH are needed. Detaching the card stops us charging them; voiding the
	// invoice stops THEM paying us from the hosted page whose link is already
	// in their inbox, which would otherwise reverse this decision.
	law_stripe_detach_payment_method( $booking_id, $actor_id );
	law_stripe_void_booking_invoice( $booking_id, $actor_id );

	law_event_log(
		$event_id,
		sprintf(
			'Flagship registration #%d declined%s.',
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
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship registration.' );
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

	$payment_state = (string) law_event_meta( $booking_id, '_law_payment_status' );

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'law-cancelled' );
	law_booking_release_discount( $booking_id, $payment_state );
	law_event_recount_attendees( $event_id, 'flagship' );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	law_stripe_detach_payment_method( $booking_id, $actor_id );
	law_stripe_void_booking_invoice( $booking_id, $actor_id );

	law_event_log(
		$event_id,
		sprintf( 'Flagship registration #%d withdrawn by the delegate.', law_event_meta( $booking_id, '_law_booking_number' ) ),
		array( 'source' => 'flagship', 'action' => 'flagship_withdrawn', 'booking' => $booking_id ),
		array( 'user_id' => (int) $actor_id )
	);

	law_events_send( 'user_flagship_withdrawn', $event_id, law_flagship_email_extra( $booking_id ) );

	return true;
}

/**
 * The committee cancelling a place that is already confirmed and paid for.
 *
 * Deliberately narrow, and deliberately silent (Denis, 17 September 2026).
 * Decline handles the end of the flow where nobody has been charged; this
 * handles the other end, where the money has already moved and the delegate
 * has to drop out anyway. It frees the place and stops there: no refund is
 * attempted, no Stripe call is made, and the delegate is NOT emailed. The
 * refund and the conversation are the committee's to have by hand, which is
 * what the confirm dialog on the Flagship bookings row says before it is
 * pressed. That is the whole point of the control: without it a delegate who
 * has paid and pulled out holds a place nobody can release, and the headcount
 * the caterers and badges run off stays wrong.
 *
 * The one thing it does beyond the status change is take back the reception
 * places the ticket paid for, because those were free only for as long as the
 * ticket stood. law_reception_revoke_included() emails about THOSE, on its own
 * long-standing rule that a place vanishing from somebody's bookings with no
 * explanation is worse than the news.
 *
 * A late invoice.paid for a booking cancelled here cannot resurrect it:
 * law_flagship_mark_paid() refuses a terminal application and raises the
 * committee_flagship_paid alert instead.
 *
 * @return true|WP_Error
 */
function law_flagship_cancel_confirmed( $booking_id, $actor_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship registration.' );
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	if ( 'law-cancelled' === $booking->post_status ) {
		return true;
	}
	// Confirmed places only. An application still under review is DECLINED,
	// not cancelled: that path detaches the saved payment method and voids the
	// invoice, and routing it through here would leave both live, so the
	// delegate could still pay from the hosted invoice link in their inbox and
	// reverse the decision.
	if ( 'publish' !== $booking->post_status ) {
		return new WP_Error(
			'law_flagship_not_confirmed',
			'That place is not confirmed, so there is nothing to cancel. Decline the registration instead, which also clears the payment details they saved.'
		);
	}

	// Read before the status moves, so the discount rule is decided on the
	// same facts as everything else here.
	$payment = (string) law_event_meta( $booking_id, '_law_payment_status' );

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'law-cancelled' );
	// A paid place KEEPS its code use — the code really was spent — and a
	// complimentary or code-covered one gives it back. law_booking_release_discount()
	// owns that rule; this call is the same one decline and withdrawal make.
	law_booking_release_discount( $booking_id, $payment );
	law_event_recount_attendees( $event_id, 'flagship' );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	// Outside the lock, as the decline path does: another delegate's booking
	// must not wait on this one's reception places being unpicked.
	if ( function_exists( 'law_reception_revoke_included' ) ) {
		law_reception_revoke_included( $booking_id, (int) $actor_id );
	}

	$price = law_booking_price( $booking_id );
	law_event_log(
		$event_id,
		sprintf(
			/* translators: 1: booking number, 2: what they paid. */
			'Flagship ticket #%1$d cancelled by the committee. %2$s The delegate has NOT been emailed and nothing has been refunded: both are for the committee to handle by hand.',
			law_event_meta( $booking_id, '_law_booking_number' ),
			'paid' === $payment && (int) $price['gross'] > 0
				? sprintf(
					'%s paid %s, so a refund may be owed.',
					// Not "They": on a transferred place the delegate in the
					// row paid nothing, and this is the line somebody reads
					// before sending money back.
					(string) law_event_meta( $booking_id, '_law_substituted_from_name' ) ?: 'The delegate',
					law_events_format_pence( (int) $price['gross'] )
				)
				: 'There was nothing to refund.'
		),
		array( 'source' => 'flagship', 'action' => 'flagship_cancelled', 'booking' => $booking_id ),
		array( 'user_id' => (int) $actor_id )
	);

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
			'Flagship registration #%1$d approved and charged with %2$s. The payment has not settled yet, so the place is held until it does.',
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
				'A payment event reached the flagship handler for booking #%d, which is not a flagship registration. Nothing was changed: this flow needs its own handler.',
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
				? 'Flagship registration #%1$d: the bank asked the delegate to confirm the payment. %2$s'
				: 'Flagship registration #%1$d: the card was declined. %2$s',
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
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship registration.' );
	}
	if ( 'law-payment-failed' !== $booking->post_status ) {
		return new WP_Error( 'law_flagship_not_failed', 'That registration is not waiting on a payment.' );
	}
	// A substituted place holds somebody else's saved payment method and
	// somebody else's invoice. Charging it would take money from a person who
	// is no longer attending, for a ticket already paid for. Unreachable while
	// a substitution requires a confirmed place, and stated anyway so it stays
	// unreachable (21 September 2026).
	if ( (int) law_event_meta( (int) $booking->ID, '_law_substituted_from' ) ) {
		return new WP_Error(
			'law_flagship_substituted',
			'This place has been transferred to a different delegate, so the payment details saved against it belong to somebody else and must not be charged.'
		);
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
		// The flagship ticket is what made the included receptions free, so a
		// full refund takes them back with it (RECEPTIONS.md §7.2). Only
		// reachable through a committee refund, since a paid flagship place
		// cannot be withdrawn. A PART refund changes nothing: the place is
		// still confirmed, so the receptions it includes still stand.
		if ( function_exists( 'law_reception_revoke_included' ) ) {
			law_reception_revoke_included( (int) $booking->ID, 0 );
		}
	}

	law_event_log(
		$event_id,
		sprintf(
			$partial
				? 'PARTIAL REFUND on flagship registration #%1$d: %2$s of %3$s refunded. The place is unchanged; review in Stripe.'
				: 'Flagship registration #%1$d refunded in full (%2$s of %3$s). The place is NOT cancelled automatically.',
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
 * @param array $row name, email, organisation, job_title, press, plus profile
 *                  (the cleaned country/accessibility/dietary set from
 *                  law_registration_clean_attendee_profile(), written onto the
 *                  attendee's account once the place is theirs).
 * @return int|WP_Error The booking ID.
 */
function law_flagship_add_complimentary( array $row, $actor_id ) {
	$event_id = law_flagship_event_id();
	$open     = law_flagship_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $open;
	}

	$profile = (array) ( $row['profile'] ?? array() );
	// Captured before $row is rewritten below into the four snapshot fields.
	$law_fc_row_receptions = (array) ( $row['receptions'] ?? array() );
	$row     = array(
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
			law_booking_delete_created_users( array( $user_id => true ), $event_id, (int) $actor_id );
		}
		return $dup;
	}

	$locked     = law_booking_lock( $event_id );
	$booking_id = law_booking_insert(
		$event_id,
		$user_id,
		'publish',
		array( 'user_id' => $user_id ) + $row,
		array(
			'_law_application_at' => gmdate( 'Y-m-d H:i' ),
			'_law_is_complimentary' => 1,
			'_law_payment_status' => 'complimentary',
			'_law_price_pence'    => 0,
			'_law_reviewed_at'    => gmdate( 'Y-m-d H:i' ),
			'_law_reviewed_by'    => (int) $actor_id,
		)
	);
	if ( is_wp_error( $booking_id ) ) {
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
		if ( ! empty( $resolved['created'] ) ) {
			law_booking_delete_created_users( array( $user_id => true ), $event_id, (int) $actor_id );
		}
		return $booking_id;
	}
	$booking_id = (int) $booking_id;
	$number     = (int) law_event_meta( $booking_id, '_law_booking_number' );

	// The press flag is the one thing law_booking_insert() cannot carry: it is
	// an argument to law_booking_write_attendee(), not a meta value.
	if ( ! empty( $row['press'] ) ) {
		law_event_update_meta( $booking_id, '_law_is_press', 1 );
	}
	update_post_meta( $booking_id, '_law_application_ready', 1 );
	law_event_recount_attendees( $event_id, 'flagship' );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	// The receptions the committee ticked on the comp form, granted at once:
	// the place is already confirmed, so there is nothing left to wait for
	// (RECEPTIONS.md §7.1).
	// Only receptions that really are included, so a forged checkbox on the
	// comp form cannot conjure a place at anything else.
	$law_fc_receptions = array_values( array_filter( array_map( 'absint', (array) ( $law_fc_row_receptions ?? array() ) ) ) );
	if ( $law_fc_receptions && function_exists( 'law_reception_included_ids' ) ) {
		$law_fc_receptions = array_values( array_intersect( $law_fc_receptions, law_reception_included_ids() ) );
	}

	// Country, accessibility and dietary, once the place is actually theirs.
	law_booking_apply_attendee_profile( $user_id, $profile, ! empty( $resolved['created'] ), $event_id, (int) $actor_id );

	$actor = get_user_by( 'id', (int) $actor_id );
	law_event_log(
		$event_id,
		sprintf(
			'Complimentary flagship place #%d added for %s by %s%s.',
			$number,
			$row['name'],
			$actor ? $actor->display_name : 'the committee',
			$row['press'] ? ' (press pass)' : ''
		),
		array( 'source' => 'flagship', 'action' => 'flagship_complimentary', 'booking' => $booking_id, 'press' => $row['press'] ),
		array( 'user_id' => (int) $actor_id )
	);

	// The receptions the committee ticked, granted at once: this place is
	// already confirmed, so there is nothing left to wait for
	// (RECEPTIONS.md §7.1).
	$law_fc_result = array();
	if ( $law_fc_receptions && function_exists( 'law_reception_grant_choices' ) ) {
		$law_fc_result = law_reception_grant_choices( $booking_id, $law_fc_receptions, (int) $actor_id, 'flagship_complimentary' );
	}

	$law_fc_extra = law_flagship_email_extra( $booking_id );
	if ( $law_fc_result ) {
		$law_fc_extra['placeholders']['included_receptions'] = law_reception_choices_note( $law_fc_result );
	}
	law_booking_send_with_ics( 'user_flagship_complimentary', $event_id, $law_fc_extra );

	return $booking_id;
}

/**
 * Does this person hold a CONFIRMED place at this reception in their own right?
 *
 * Narrower than law_reception_holds_place() on purpose. That one answers "is
 * this person already accounted for here", which rightly includes a waitlist
 * entry and a checkout in flight, and it is the correct test when deciding
 * whether to GRANT somebody a place. This one is asked before CANCELLING a
 * free place, where counting a queue entry as a place meant taking away the
 * reception a transferred ticket had paid for and then charging the delegate
 * for the same seat off the waitlist.
 */
function law_flagship_holds_reception_place( $user_id, $reception_id ) {
	$held = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => 'publish',
			'post_parent'    => (int) $reception_id,
			'author'         => (int) $user_id,
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
		)
	);

	return ! empty( $held );
}

/**
 * Move the included reception places from one holder of a flagship ticket to
 * the next, when the ticket itself is substituted.
 *
 * Deliberately NOT law_reception_revoke_included() followed by
 * law_reception_grant_choices(), which is the obvious route and is wrong three
 * ways. It emails the original that "the flagship place it came with is no
 * longer confirmed", which is untrue here — the place is fine, they are not on
 * it. It silently drops any reception that has already happened, because
 * law_reception_grant_included() refuses anything outside
 * law_reception_included_ids(). And the revoke half calls law_booking_cancel(),
 * which ends in law_waitlist_process(), so somebody queued can be seated into
 * the place between the revoke and the grant — and the grant has no capacity
 * guard by design, so the reception silently over-books.
 *
 * Moving the booking in place has none of those problems: the headcount never
 * changes, so no recount and no waitlist are involved at all.
 *
 * The one case that does release a place is a substitute who ALREADY bought
 * their own ticket to that reception. They keep the one they paid for and the
 * included one is cancelled, so nobody holds two. Here the waitlist firing is
 * correct, because a place really has been freed. Nothing is refunded: the
 * committee is told, and the conversation is theirs.
 *
 * @param int   $booking_id The flagship booking, already re-authored.
 * @param int   $to_user_id The new holder.
 * @param array $person     law_booking_attendee()-shaped row for the new holder.
 * @param int   $actor_id   The committee member.
 * @return array{moved:array<int,string>,released:array<int,string>,failed:array<int,string>}
 */
function law_flagship_move_included_receptions( $booking_id, $to_user_id, array $person, $actor_id ) {
	$result = array( 'moved' => array(), 'released' => array(), 'failed' => array() );

	$included = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => law_booking_holding_statuses(),
			'meta_key'       => '_law_included_with',
			'meta_value'     => (int) $booking_id,
			'posts_per_page' => 50,
			'no_found_rows'  => true,
		)
	);
	if ( ! $included ) {
		return $result;
	}

	foreach ( $included as $place ) {
		$reception_id = (int) $place->post_parent;
		$title        = (string) get_the_title( $reception_id );
		$number       = (int) law_event_meta( (int) $place->ID, '_law_booking_number' );

		// They bought their own place at this one already. Cancel the included
		// place rather than leaving them holding two, and say so loudly enough
		// that somebody thinks about the money they spent on it.
		//
		// Deliberately NOT law_reception_holds_place(), which tests
		// law_booking_holding_statuses() and therefore counts a WAITLIST entry
		// and an unfinished checkout as "holds a place". Both were disastrous
		// here: cancelling the included place fed law_waitlist_process(), which
		// promoted the substitute off that very queue and charged them for a
		// reception their transferred ticket already included; and the
		// pending-payment case cancelled the free place and left them with the
		// abandoned checkout shell and no reception at all. A place they really
		// have is a confirmed one (21 September 2026).
		if ( law_flagship_holds_reception_place( (int) $to_user_id, $reception_id ) ) {
			$cancelled = law_booking_cancel( (int) $place->ID, (int) $actor_id, 'included_revoked' );
			if ( is_wp_error( $cancelled ) ) {
				$result['failed'][ $reception_id ] = $title;
				continue;
			}
			$result['released'][ $reception_id ] = $title;
			law_event_log(
				$reception_id,
				sprintf(
					'Included reception place #%1$d released: the flagship ticket it came with was substituted to %2$s, who already holds a place here that they booked themselves. Nothing has been refunded to them.',
					$number,
					$person['name']
				),
				array(
					'source'   => 'flagship',
					'action'   => 'reception_included_released',
					'booking'  => (int) $place->ID,
					'flagship' => (int) $booking_id,
				),
				array( 'user_id' => (int) $actor_id )
			);
			continue;
		}

		// The ordinary case: the place moves with the ticket. One lock per
		// reception, never nested inside the flagship's — GET_LOCK does not
		// nest — and no recount, because one seat out is one seat in.
		$locked = law_booking_lock( $reception_id );
		$moved  = wp_update_post(
			array( 'ID' => (int) $place->ID, 'post_author' => (int) $to_user_id ),
			true
		);
		if ( is_wp_error( $moved ) ) {
			if ( $locked ) {
				law_booking_unlock( $reception_id );
			}
			$result['failed'][ $reception_id ] = $title;
			continue;
		}
		law_booking_write_attendee(
			(int) $place->ID,
			array( 'user_id' => (int) $to_user_id ) + $person,
			(int) $to_user_id,
			(bool) law_event_meta( (int) $place->ID, '_law_is_press' )
		);
		if ( $locked ) {
			law_booking_unlock( $reception_id );
		}

		$result['moved'][ $reception_id ] = $title;
		law_event_log(
			$reception_id,
			sprintf(
				'Included reception place #%1$d moved to %2$s with the flagship ticket it came with.',
				$number,
				$person['name']
			),
			array(
				'source'   => 'flagship',
				'action'   => 'reception_included_moved',
				'booking'  => (int) $place->ID,
				'flagship' => (int) $booking_id,
			),
			array( 'user_id' => (int) $actor_id )
		);
	}

	return $result;
}

/**
 * The sentence the substitution emails and the committee's confirmation use
 * about the drinks receptions that came with the ticket.
 *
 * Shaped like law_reception_choices_note(), and separate from it because the
 * three outcomes are different: nothing was granted here, things MOVED, and
 * the third case is a place given up rather than one already held.
 *
 * @param array $result law_flagship_move_included_receptions().
 * @return string '' when the ticket carried no reception places.
 */
function law_flagship_receptions_moved_note( array $result, $about = '' ) {
	// Second person for the delegate's own email, third for the committee's
	// confirmation and the activity log. Without the split the committee was
	// told a reception place "is now in your bookings" and that "you already
	// had your own place", about somebody else entirely, and the log kept that
	// wrong voice permanently.
	$about  = trim( (string) $about );
	$theirs = '' !== $about;
	$lines  = array();
	if ( ! empty( $result['moved'] ) ) {
		$lines[] = $theirs
			? sprintf(
				/* translators: 1: a list of reception names, 2: the delegate. */
				_n(
					'%1$s came with this ticket and is now in %2$s\'s bookings, at no cost.',
					'%1$s came with this ticket and are now in %2$s\'s bookings, at no cost.',
					count( $result['moved'] ),
					'law'
				),
				wp_sprintf_l( '%l', array_values( $result['moved'] ) ),
				$about
			)
			: sprintf(
				/* translators: %s: a list of reception names. */
				_n(
					'%s comes with this ticket and is now in your bookings, at no cost.',
					'%s come with this ticket and are now in your bookings, at no cost.',
					count( $result['moved'] ),
					'law'
				),
				wp_sprintf_l( '%l', array_values( $result['moved'] ) )
			);
	}
	if ( ! empty( $result['released'] ) ) {
		$lines[] = $theirs
			? sprintf(
				/* translators: 1: a list of reception names, 2: the delegate. */
				_n(
					'%2$s already had their own place at %1$s, so the one included with this ticket has been released. Nothing has been refunded to them.',
					'%2$s already had their own places at %1$s, so the ones included with this ticket have been released. Nothing has been refunded to them.',
					count( $result['released'] ),
					'law'
				),
				wp_sprintf_l( '%l', array_values( $result['released'] ) ),
				$about
			)
			: sprintf(
				/* translators: %s: a list of reception names. */
				_n(
					'You already had your own place at %s, so the one included with this ticket has been released.',
					'You already had your own places at %s, so the ones included with this ticket have been released.',
					count( $result['released'] ),
					'law'
				),
				wp_sprintf_l( '%l', array_values( $result['released'] ) )
			);
	}
	if ( ! empty( $result['failed'] ) ) {
		$lines[] = $theirs
			? sprintf(
				/* translators: 1: a list of reception names, 2: the delegate. */
				__( 'The place at %1$s could NOT be moved to %2$s. Please sort it out by hand.', 'law' ),
				wp_sprintf_l( '%l', array_values( $result['failed'] ) ),
				$about
			)
			: sprintf(
				/* translators: %s: a list of reception names. */
				__( 'Please get in touch about your place at %s.', 'law' ),
				wp_sprintf_l( '%l', array_values( $result['failed'] ) )
			);
	}

	return implode( ' ', $lines );
}

/**
 * Hand a confirmed flagship ticket to somebody else (the client's ask,
 * 21 September 2026).
 *
 * The case is ordinary and had no answer at all before this: a firm buys a
 * place for a named partner, the partner cannot come, and a colleague goes
 * instead. The only route the committee had was to cancel the confirmed ticket
 * and add the replacement as a complimentary place, which threw away the
 * payment trail, understated the revenue and filed a paying delegate as a
 * freebie.
 *
 * THE MONEY DOES NOT MOVE (Denis, 21 September 2026). No Stripe call is made
 * at all: the invoice, the charge and the VAT receipt stay exactly as issued
 * to whoever paid, because the receipt records who paid and re-addressing it
 * to somebody who paid nothing would mislead whoever later handles a refund.
 * Every _law_stripe_* key on the booking therefore still describes the
 * ORIGINAL delegate, deliberately, and _law_substituted_from_email is what
 * finds their Stripe customer months later. The one live consequence is that
 * the delegate's own view of the application must stop showing the payment
 * facts to the new holder, which parts/events/booking-payment-facts.php does.
 *
 * CONFIRMED PLACES ONLY. A registration still under review carries a payment
 * method the original person saved and consented to; that is theirs, not a
 * thing to pass on, so it is declined and the replacement registers afresh.
 *
 * post_author is what moves. It is the canonical attendee everywhere in this
 * module — My bookings queries by it, law_booking_attendee() reads it, the
 * duplicate guard indexes it, the clash guard reads it — so rewriting only the
 * snapshot would leave the ticket in the wrong person's account while claiming
 * to be somebody else's.
 *
 * @param int   $booking_id The confirmed flagship booking.
 * @param array $row        name, email, organisation, job_title, press, plus
 *                          profile (the cleaned country/accessibility/dietary
 *                          set, written onto the new delegate's account).
 * @param int   $actor_id   The committee member doing it.
 * @return array{user_id:int,created:bool,name:string,from_name:string,receptions:array}|WP_Error
 */
function law_flagship_substitute( $booking_id, array $row, $actor_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return new WP_Error( 'law_flagship_not_application', 'That is not a flagship registration.' );
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;
	$actor_id   = (int) $actor_id;

	// Deliberately NOT law_flagship_guard_open(), which law_flagship_apply()
	// and law_flagship_add_complimentary() both call: it refuses once _law_start
	// has passed, and a substitution routinely happens in the last week and
	// sometimes on the morning of the conference. Nobody is registering here;
	// the place already exists and is only changing hands.
	if ( 'publish' !== $booking->post_status ) {
		return new WP_Error(
			'law_flagship_not_confirmed',
			'Only a confirmed place can be handed to somebody else. Decline a registration that is still under review and let the new person register themselves, and use Cancel on a place you want to release rather than move.'
		);
	}
	// A refunded place stays on publish (law_flagship_mark_refunded() does not
	// cancel it), so it reaches here looking transferable. It is not: the money
	// has gone back to the person who paid, so there is nothing for a
	// substitute to inherit, and every sentence the emails would say about the
	// receipt is false. The committee almost certainly means to cancel it.
	if ( 'refunded' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return new WP_Error(
			'law_flagship_refunded',
			'This place has been refunded, so there is nothing to transfer. Cancel it to release the place, and let the new delegate register in their own right.'
		);
	}

	$profile = (array) ( $row['profile'] ?? array() );
	$row     = array(
		'name'         => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
		'email'        => sanitize_email( (string) ( $row['email'] ?? '' ) ),
		'organisation' => sanitize_text_field( (string) ( $row['organisation'] ?? '' ) ),
		'job_title'    => sanitize_text_field( (string) ( $row['job_title'] ?? '' ) ),
		'press'        => ! empty( $row['press'] ),
	);
	if ( '' === $row['name'] ) {
		return new WP_Error( 'law_flagship_no_name', 'Please give the new delegate a name.', array( 'field' => 'name' ) );
	}
	if ( ! is_email( $row['email'] ) ) {
		return new WP_Error( 'law_flagship_bad_email', 'Please give a valid email address.', array( 'field' => 'email' ) );
	}

	// Everything about the person losing the place, read BEFORE anything moves.
	// The address comes from law_booking_attendee_email() rather than the
	// snapshot because that helper prefers the live account address, and that
	// is where their email has to go.
	$from_user_id = (int) $booking->post_author;
	$from         = law_booking_attendee( $booking );
	$from_email   = law_booking_attendee_email( $booking );
	$from_name    = '' !== $from['name'] ? $from['name'] : $from_email;

	// Substituting somebody for themselves is a mistake, not a no-op, and it is
	// caught here rather than left to the duplicate guard: that guard would
	// answer "You already have a booking for this event", which is written for
	// a person booking themselves and reads as nonsense on a committee dialog.
	// Checked on the address before any account is resolved, so the mistake
	// never mints a user.
	$wanted = strtolower( $row['email'] );
	$held   = array_filter( array( strtolower( $from_email ), strtolower( $from['email'] ) ) );
	if ( in_array( $wanted, $held, true ) ) {
		return new WP_Error(
			'law_flagship_same_person',
			sprintf( '%s already holds this place, so there is nothing to substitute.', $from_name ),
			array( 'field' => 'email' )
		);
	}

	// Validation first, accounts second: a refusal must never leave an account
	// behind that nothing points at.
	$resolved = law_booking_resolve_attendee_user( $row, $event_id, $actor_id );
	if ( is_wp_error( $resolved ) ) {
		return $resolved;
	}
	$user_id = (int) $resolved['user_id'];
	$created = ! empty( $resolved['created'] );

	// Belt and braces on the same-person check above, for an account whose
	// address differs from both of the ones compared there.
	if ( $user_id === $from_user_id ) {
		return new WP_Error(
			'law_flagship_same_person',
			sprintf( '%s already holds this place, so there is nothing to substitute.', $from_name ),
			array( 'field' => 'email' )
		);
	}

	// Refused across every status that holds a place, so a substitute with an
	// application of their own still under review is caught too: approving that
	// afterwards would charge them for a second place.
	$dup = law_booking_guard_duplicates(
		$event_id,
		array( array( 'user_id' => $user_id, 'email' => $row['email'], 'name' => $row['name'] ) ),
		law_booking_holding_statuses()
	);
	if ( is_wp_error( $dup ) ) {
		if ( $created ) {
			law_booking_delete_created_users( array( $user_id => true ), $event_id, $actor_id );
		}
		return new WP_Error(
			'law_flagship_duplicate',
			sprintf(
				'%s already has a place at the conference, so this ticket cannot be moved to them.',
				$row['name']
			),
			array( 'field' => 'email' )
		);
	}

	$person = array( 'user_id' => $user_id ) + array(
		'name'         => $row['name'],
		'email'        => $row['email'],
		'organisation' => $row['organisation'],
		'job_title'    => $row['job_title'],
	);

	// The lock covers the swap and nothing else. Its whole job is the re-read
	// below: between the guards above and here, another committee member's
	// Cancel or another substitution could have moved this same place.
	$locked = law_booking_lock( $event_id );
	// Refused rather than proceeded when the lock is not granted, unlike the
	// older functions in this file. Everything the lock protects here is the
	// re-read below, so running without it would be doing the checks and then
	// ignoring the answer. law_booking_cancel() takes the same line.
	if ( ! $locked ) {
		if ( $created ) {
			law_booking_delete_created_users( array( $user_id => true ), $event_id, $actor_id );
		}
		return new WP_Error(
			'law_flagship_busy',
			'Somebody else is working on this conference right now. Please try again in a moment.'
		);
	}
	// clean_post_cache() before the re-read, or there is no re-read at all:
	// get_post() answers from this request's own cache, which still holds the
	// copy loaded at the top of this function, and the check below would
	// compare the row against itself. Without a persistent object cache another
	// request's write is invisible to us until we go back to the database.
	clean_post_cache( $booking_id );
	$fresh = get_post( $booking_id );
	if ( ! $fresh || 'publish' !== $fresh->post_status || (int) $fresh->post_author !== $from_user_id ) {
		law_booking_unlock( $event_id );
		if ( $created ) {
			law_booking_delete_created_users( array( $user_id => true ), $event_id, $actor_id );
		}
		return new WP_Error(
			'law_flagship_moved',
			'That place changed while you were filling this in. Please reload the page and look at it again.'
		);
	}

	// The duplicate guard AGAIN, now that nothing else can move. The one above
	// runs before the account is resolved so a refusal mints nobody, but it is
	// a read-then-act check: between it and here, another committee member's
	// substitution — or this person registering for themselves — could have
	// given them a place, and they would end up holding two.
	$dup = law_booking_guard_duplicates(
		$event_id,
		array( array( 'user_id' => $user_id, 'email' => $row['email'], 'name' => $row['name'] ) ),
		law_booking_holding_statuses()
	);
	if ( is_wp_error( $dup ) ) {
		law_booking_unlock( $event_id );
		if ( $created ) {
			law_booking_delete_created_users( array( $user_id => true ), $event_id, $actor_id );
		}
		return new WP_Error(
			'law_flagship_duplicate',
			sprintf( '%s already has a place at the conference, so this ticket cannot be moved to them.', $row['name'] ),
			array( 'field' => 'email' )
		);
	}

	// Only these two keys. post_status is unchanged, so workflow.php's
	// wp_insert_post_data guard is a no-op and transition_post_status does not
	// fire — which is why $GLOBALS['law_booking_transitioning'] is deliberately
	// NOT raised here: disarming the status guard for a change that does not
	// touch the status would only widen the window something else could slip
	// through.
	/*
	 * The audit meta goes in BEFORE post_author moves, and that order is the
	 * whole point. law_booking_payment_facts_visible() reads
	 * _law_substituted_from to decide whether to show the payer's hosted Stripe
	 * invoice — their name, billing address and card last four. Writing it
	 * afterwards leaves a window in which a fatal or a timeout inside
	 * wp_update_post()'s hooks strands the booking owned by the new delegate
	 * with no marker, and every one of those facts on their own page for good.
	 * Written first, the same crash leaves a marker on a booking that never
	 * moved, which hides a receipt from the person who paid until somebody
	 * notices. Wrong in the harmless direction.
	 */
	$law_fs_first = ! (int) law_event_meta( $booking_id, '_law_substituted_from' );
	// WHO PAID, not who held it last. Written once and never again: a second
	// transfer would otherwise name the FIRST substitute, somebody who paid
	// nothing, and everything downstream reads these as the payer. Rewriting
	// them inverted the rule outright — the invoice was hidden from the person
	// who bought it and shown to the person who did not, the wp-admin facts box
	// named the wrong person as holding the receipt, and the transfer email
	// handed that substitute a link to the payer's hosted invoice.
	// Re-substitution stays allowed (a firm's replacement drops out too); these
	// simply stop moving, and the full chain lives in the activity log.
	if ( $law_fs_first ) {
		law_event_update_meta( $booking_id, '_law_substituted_from', $from_user_id );
		law_event_update_meta( $booking_id, '_law_substituted_from_name', $from_name );
		law_event_update_meta( $booking_id, '_law_substituted_from_email', $from_email );
	}
	// These two DO move: they are about the most recent transfer, not the money.
	law_event_update_meta( $booking_id, '_law_substituted_at', gmdate( 'Y-m-d H:i' ) );
	law_event_update_meta( $booking_id, '_law_substituted_by', $actor_id );

	$updated = wp_update_post( array( 'ID' => $booking_id, 'post_author' => $user_id ), true );
	if ( is_wp_error( $updated ) ) {
		// Nothing moved, so take the marker back off — but only the part this
		// call added, or a re-substitution that fails here would erase the
		// original payer recorded by the one before it.
		if ( $law_fs_first ) {
			delete_post_meta( $booking_id, '_law_substituted_from' );
			delete_post_meta( $booking_id, '_law_substituted_from_name' );
			delete_post_meta( $booking_id, '_law_substituted_from_email' );
		}
		law_booking_unlock( $event_id );
		if ( $created ) {
			law_booking_delete_created_users( array( $user_id => true ), $event_id, $actor_id );
		}
		return $updated;
	}

	// The fourth argument matters: law_booking_write_attendee() DELETES
	// _law_is_press unless it is passed, so a press pass on the ticket would
	// vanish silently. It is a property of the seat, so it is carried over
	// unless the dialog says otherwise.
	law_booking_write_attendee( $booking_id, $person, $user_id, ! empty( $row['press'] ) );

	// NO law_event_recount_attendees(). One seat out is one seat in, so the
	// headcount is unchanged and a recount would only re-arm the capacity
	// warnings for a change that took no place.
	law_booking_unlock( $event_id );

	/*
	 * Past this point the seat has changed hands and there is nothing to roll
	 * back to. Everything below is best-effort: it is logged and reported, and
	 * it never turns a completed substitution into an error. In particular the
	 * new holder now owns a booking, so they are never passed to
	 * law_booking_delete_created_users() again.
	 */

	/*
	 * The log FIRST, before any of the best-effort work below. The activity log
	 * is the only surface the committee reads to find out a place changed
	 * hands, and writing it last meant a fatal inside the reception move — which
	 * cancels bookings and can enter the waitlist engine — left the seat
	 * transferred, the audit meta written, nobody emailed and nothing at all in
	 * the log to say so. It needs nothing from the receptions, so it does not
	 * wait for them; what they did is appended after.
	 */
	$actor   = $actor_id ? get_user_by( 'id', $actor_id ) : null;
	$price   = law_booking_price( $booking_id );
	$payment = (string) law_event_meta( $booking_id, '_law_payment_status' );
	$ticket  = (string) law_event_meta( $booking_id, '_law_ticket_type' );
	// Named against whoever actually PAID, which on a re-substitution is not
	// the person handing it on.
	$law_fs_payer_id   = (int) law_event_meta( $booking_id, '_law_substituted_from' );
	$law_fs_payer_name = ( ! $law_fs_payer_id || $law_fs_payer_id === $from_user_id )
		? $from_name
		: (string) law_event_meta( $booking_id, '_law_substituted_from_name' );
	$money_note = 'paid' === $payment && (int) $price['gross'] > 0
		? sprintf(
			'The money has not moved: the Stripe invoice, the charge and the VAT receipt for %s stay with %s, who has been emailed the link to them.',
			law_events_format_pence( (int) $price['gross'] ),
			$law_fs_payer_name
		)
		: 'There was nothing to pay on this place, so there is nothing to move.';

	law_event_log(
		$event_id,
		sprintf(
			'Flagship ticket #%1$d transferred from %2$s (%3$s) to %4$s (%5$s) by %6$s. %7$s%8$s',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			$from_name,
			$from_email ? $from_email : 'no address',
			$row['name'],
			$row['email'],
			$actor ? $actor->display_name : 'the committee',
			$money_note,
			'' !== $ticket
				? sprintf( ' The ticket type is still %s, so please check it still applies.', law_booking_ticket_type_label( $ticket ) )
				: ''
		),
		array(
			'source'   => 'flagship',
			'action'   => 'flagship_substituted',
			'booking'  => $booking_id,
			'from'     => $from_user_id,
			'to'       => $user_id,
			'payment'  => $payment,
		),
		array( 'user_id' => $actor_id )
	);
	$receptions = law_flagship_move_included_receptions( $booking_id, $user_id, $person, $actor_id );
	if ( $receptions['moved'] || $receptions['released'] || $receptions['failed'] ) {
		law_event_log(
			$event_id,
			law_flagship_receptions_moved_note( $receptions, $row['name'] ),
			array(
				'source'   => 'flagship',
				'action'   => 'flagship_substituted_receptions',
				'booking'  => $booking_id,
				'moved'    => array_keys( $receptions['moved'] ),
				'released' => array_keys( $receptions['released'] ),
				'failed'   => array_keys( $receptions['failed'] ),
			),
			array( 'user_id' => $actor_id )
		);
	}

	law_booking_apply_attendee_profile( $user_id, $profile, $created, $event_id, $actor_id );

	$law_fs_told_previous = law_flagship_send_substitution_emails(
		$booking_id,
		$event_id,
		$person,
		$created,
		array( 'user_id' => $from_user_id, 'name' => $from_name, 'email' => $from_email ),
		$receptions
	);

	return array(
		'user_id'       => $user_id,
		'created'       => $created,
		'name'          => $row['name'],
		'from_name'     => $from_name,
		'payer_name'    => $law_fs_payer_name,
		'paid'          => 'paid' === $payment && (int) $price['gross'] > 0,
		'told_previous' => $law_fs_told_previous,
		'receptions'    => $receptions,
	);
}

/**
 * The two emails a substitution sends, and the one rule that matters in them.
 *
 * ONE new template, not two (Denis, 21 September 2026). The person arriving
 * gets user_flagship_approved — the ordinary confirmation every other confirmed
 * delegate gets — because that is what they are: somebody with a confirmed
 * place. Only the person LOSING a place needed a template of its own, since
 * nothing in the registry said "you no longer have a ticket, and here is where
 * your receipt went".
 *
 * What makes one template serve both is {payment_note}: a whole resolved
 * paragraph that says "we have taken £660 from your card, here is the invoice"
 * on an approval and "somebody else paid for this, the receipt stays with
 * them" on a transfer. The payment tags are ALSO blanked on the way out, so an
 * environment whose stored override of that template still names {invoice_link}
 * or {payment_method} by hand cannot hand the payer's hosted Stripe invoice —
 * their name, billing address and card last four — to a person who paid
 * nothing. The tag carries the right sentence; the blanking is what makes the
 * wrong one impossible.
 *
 * The original's note goes to the address captured BEFORE the swap, with an
 * explicit 'to'. Reading it back out of law_flagship_email_extra() would
 * resolve to the new holder, and "your place has been passed on" would land on
 * the person who received it.
 *
 * @return bool Whether the person losing the place could be emailed.
 */
function law_flagship_send_substitution_emails( $booking_id, $event_id, array $person, $created, array $from, array $receptions ) {
	$note = law_flagship_receptions_moved_note( $receptions );

	// To the new delegate: the standard confirmation, with the calendar invite.
	$to_extra = law_flagship_email_extra( $booking_id );
	$to_extra['placeholders']['attendee_name']          = $person['name'];
	$to_extra['placeholders']['previous_attendee_name'] = $from['name'];
	$to_extra['placeholders']['included_receptions']    = $note;
	$to_extra['placeholders']['payment_note']           = law_flagship_payment_note( $booking_id, true, $from['name'] );

	// Every fact about somebody else's money, emptied. law_flagship_email_extra()
	// supplies all of them for every flagship email, and each one describes the
	// person who paid rather than the person reading.
	foreach ( array( 'invoice_link', 'invoice_url', 'price', 'price_vat', 'price_total', 'payment_method', 'card_label', 'update_payment_link', 'discount_note' ) as $law_fs_payer_tag ) {
		$to_extra['placeholders'][ $law_fs_payer_tag ] = '';
	}

	// A brand-new account gets its set-password link in THIS email rather than
	// a welcome of its own — the "one welcome email, not two" rule of
	// 17 September 2026, through the same resolved paragraph the on-behalf
	// bookings use.
	$new_user = $created ? get_user_by( 'id', (int) $person['user_id'] ) : null;
	$link     = $new_user ? law_events_password_setup_link( $new_user, $event_id, 'flagship_substitute_error' ) : '';
	$to_extra['placeholders']['set_password_link'] = $link;
	$to_extra['placeholders']['account_note']      = law_booking_account_note( $link );
	if ( '' !== $link && $new_user ) {
		$to_extra['placeholders']['username'] = $new_user->user_login;
	}

	law_booking_send_with_ics( 'user_flagship_approved', $event_id, $to_extra );

	// To the person who gave it up. {receipt_note} rather than a bare
	// {invoice_link}, because a ticket a discount code covered in full has no
	// Stripe invoice and the promise of one then ended in a colon and nothing.
	//
	// Returns whether this half actually went, so the committee's confirmation
	// can stop claiming "both of them have been emailed" when one of them was
	// not reachable.
	if ( ! is_email( (string) $from['email'] ) ) {
		return false;
	}
	$from_extra = law_flagship_email_extra( $booking_id );
	$from_extra['to'] = array( $from['email'] );
	$from_extra['placeholders']['attendee_name']   = $from['name'];
	$from_extra['placeholders']['substitute_name'] = $person['name'];
	// Only the FIRST holder paid. On a re-substitution the person handing the
	// place on inherited it, so they get no receipt wording and no invoice link.
	$law_fs_payer = (int) law_event_meta( $booking_id, '_law_substituted_from' );
	$from_extra['placeholders']['receipt_note'] = law_flagship_receipt_note(
		$booking_id,
		! $law_fs_payer || $law_fs_payer === (int) $from['user_id']
	);
	law_events_send( 'user_flagship_place_transferred', $event_id, $from_extra );

	return true;
}

/**
 * Set (or clear) the committee's ticket type on one flagship registration.
 *
 * Purely a classification the committee keeps for itself (Delegate, Sponsor,
 * Speaker, Exhibitor, Committee — the client's list, 15 September 2026). It is
 * never shown to the delegate, never sent to Stripe, and no price, capacity,
 * status or guard reads it. That is why this is the one booking value with a
 * wp-admin write path as well (admin/booking-screen.php): there is nothing for
 * the front-end engine to protect.
 *
 * An empty $type clears it. The meta sanitiser deletes the key on '', so
 * "not set" is the absence of the value rather than a sixth vocabulary item.
 *
 * @param int    $booking_id The flagship booking.
 * @param string $type       A law_booking_ticket_types() key, or '' to clear.
 * @param int    $actor_id   Who changed it.
 * @return array{type:string,label:string}|WP_Error
 */
function law_flagship_set_ticket_type( $booking_id, $type, $actor_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		return new WP_Error( 'law_flagship_not_found', 'That registration could not be found.' );
	}

	$type  = (string) $type;
	$types = law_booking_ticket_types();
	if ( '' !== $type && ! isset( $types[ $type ] ) ) {
		return new WP_Error( 'law_ticket_type_unknown', 'That is not a ticket type we offer.' );
	}

	$before = (string) law_event_meta( (int) $booking->ID, '_law_ticket_type' );
	law_event_update_meta( (int) $booking->ID, '_law_ticket_type', $type );

	// Logged even when nothing moved is noise, so say nothing when the
	// committee reopens the dialog and presses Apply on the same value.
	if ( $before !== $type ) {
		$actor  = $actor_id ? get_userdata( (int) $actor_id ) : null;
		$person = law_booking_attendee( (int) $booking->ID );
		$who    = $actor ? $actor->display_name : 'the committee';
		$said   = '' === $type
			? sprintf( 'Ticket type cleared for %s by %s.', $person['name'], $who )
			: sprintf( 'Ticket type set to %s for %s by %s.', $types[ $type ], $person['name'], $who );
		law_event_log(
			(int) $booking->post_parent,
			$said,
			array( 'source' => 'flagship', 'action' => 'flagship_ticket_type', 'booking' => (int) $booking->ID, 'ticket_type' => $type ),
			array( 'user_id' => (int) $actor_id )
		);
	}

	return array( 'type' => $type, 'label' => law_booking_ticket_type_label( $type ) );
}

/* Emails _____________________________________________________________________ */

/**
 * The recipient and placeholders every flagship email needs.
 *
 * The whole set is law_booking_email_extra()'s now (bookings.php): every one
 * of them is true of any priced booking, and the receptions send the same
 * figures. Kept as a name because this file speaks in flagship terms.
 */
function law_flagship_email_extra( $booking_id ) {
	return law_booking_email_extra( $booking_id );
}


/**
 * The money paragraph on a confirmed flagship ticket, already resolved.
 *
 * It is ONE paragraph rather than the four tags it replaces because the
 * sentence changes wholesale, not word by word: the delegate who paid is told
 * what was taken and where the receipt is, and a delegate a place was
 * TRANSFERRED to is told that somebody else paid and the receipt is theirs.
 * law_events_email_render_body() substitutes in a single strtr() pass, so a
 * {invoice_link} sitting inside another tag's value would reach the reader
 * printed literally — which is why this resolves everything itself. Same idiom
 * as law_booking_account_note().
 *
 * @param int    $booking_id  The confirmed booking.
 * @param bool   $transferred Whether this is a transfer rather than an approval.
 *                            An explicit flag, NOT "is there a name": the branch
 *                            decides whether somebody else's invoice, card and
 *                            price reach this reader, and a booking whose
 *                            attendee snapshot and account address were both
 *                            empty made that test false and sent the approval
 *                            paragraph — invoice link, card and all — to a
 *                            person who had paid nothing.
 * @param string $from_name   The delegate it was transferred from.
 */
function law_flagship_payment_note( $booking_id, $transferred = false, $from_name = '' ) {
	$booking_id = (int) $booking_id;

	// Transferred. Nothing about the payment is this person's business: the
	// invoice names somebody else, the hosted Stripe page carries that
	// person's billing address and card, and they were charged nothing.
	if ( $transferred ) {
		// Named where we have a name, and still safe where we do not.
		if ( '' === trim( (string) $from_name ) ) {
			return __( 'This place has been transferred to you. It has already been paid for, so there is nothing for you to pay, and the VAT receipt stays with the person who bought it.', 'law' );
		}
		return sprintf(
			/* translators: %s: the delegate who gave the place up. */
			__( 'This place has been transferred to you from %s, who bought it. There is nothing for you to pay, and the VAT receipt stays with them. If you need a copy for your records, please ask them for it.', 'law' ),
			$from_name
		);
	}

	$price = law_booking_price( $booking_id );
	// The approval is the news, and it moved in here when the body's opening
	// line had to serve a transfer as well. It is first because it is what the
	// delegate has been waiting to read.
	$lines = array( __( 'Your registration has been approved by the committee.', 'law' ) );

	if ( (int) $price['gross'] > 0 ) {
		$lines[] = sprintf(
			/* translators: 1: total, 2: payment method, 3: net, 4: VAT. */
			__( 'We have taken %1$s from your saved payment method (%2$s), which is %3$s plus %4$s VAT.', 'law' ),
			law_events_format_pence( (int) $price['gross'] ),
			law_booking_payment_method_label( $booking_id ) ?: __( 'the method you saved', 'law' ),
			law_events_format_pence( (int) $price['net'] ),
			law_events_format_pence( (int) $price['vat'] )
		);
	}

	$code     = (string) law_event_meta( $booking_id, '_law_discount_code' );
	$discount = (int) law_event_meta( $booking_id, '_law_discount_pence' );
	if ( '' !== $code && $discount > 0 ) {
		$lines[] = sprintf(
			/* translators: 1: the code, 2: the amount off. */
			__( 'Discount code %1$s: %2$s off.', 'law' ),
			$code,
			law_events_format_pence( $discount )
		);
	}

	// Only when there IS one. A ticket a code covered in full never raises a
	// Stripe invoice, and the sentence promising one then ended in a colon
	// and nothing at all (Denis, 21 September 2026).
	$invoice = (string) law_event_meta( $booking_id, '_law_stripe_invoice_url' );
	if ( '' !== $invoice ) {
		$lines[] = sprintf(
			/* translators: %s: the invoice URL. */
			__( 'Your VAT invoice is here, and you can download it at any time: %s', 'law' ),
			$invoice
		);
	}

	return implode( "\n\n", $lines );
}

/**
 * What the delegate who gave a place up is told about their receipt.
 *
 * Its own resolved paragraph for exactly the reason above, and for one more:
 * a ticket bought with a code that covered the whole price has no Stripe
 * invoice, so the bare {invoice_link} this replaces rendered a promise of a
 * receipt followed by a colon and nothing (Denis spotted it on a real
 * transfer, 21 September 2026). No invoice means a different sentence, not a
 * broken one.
 */
function law_flagship_receipt_note( $booking_id, $paid_by_them = true ) {
	$booking_id = (int) $booking_id;
	$price      = law_booking_price( $booking_id );
	$invoice    = (string) law_event_meta( $booking_id, '_law_stripe_invoice_url' );
	$status     = (string) law_event_meta( $booking_id, '_law_payment_status' );

	// Somebody who received this place from a previous transfer never paid for
	// it, so none of the receipt wording below is theirs. The invoice on the
	// booking belongs to whoever bought it, several hands back.
	if ( ! $paid_by_them ) {
		return __( 'You were not charged for this place, so there is nothing to refund.', 'law' );
	}

	// Only where the money is actually still with us. A refunded place is
	// refused before it reaches here, but say something true rather than
	// depend on that.
	if ( 'refunded' === $status ) {
		return __( 'This place was already refunded, so there is nothing further to refund and nothing further will be charged.', 'law' );
	}

	if ( '' !== $invoice && in_array( $status, array( 'paid', 'complimentary', 'included', 'no_charge' ), true ) ) {
		return sprintf(
			/* translators: %s: the invoice URL. */
			__( "Nothing has been refunded, and nothing further will be charged. Your VAT invoice stays with you and you can download it here at any time:\n\n%s\n\nPlease keep this email: it is your link to that receipt now that the booking has moved.", 'law' ),
			$invoice
		);
	}

	// Paid, but with no invoice we can link to. Say what is true rather than
	// promise a document that does not exist.
	if ( (int) $price['gross'] > 0 && 'paid' === $status ) {
		return __( 'Nothing has been refunded, and nothing further will be charged. If you need a receipt for what you paid, please reply to this email and we will send you one.', 'law' );
	}

	return __( 'There was nothing to pay on this place, so there is nothing to refund and nothing further will be charged.', 'law' );
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
		law_events_respond( $is_ajax, false, array( 'message' => 'That registration could not be found.', 'status' => 404 ), 'flagship-failed' );
	}
	if ( (int) $booking->post_author !== get_current_user_id() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That registration belongs to someone else.', 'status' => 403 ), 'flagship-denied' );
	}
	if ( $statuses && ! in_array( $booking->post_status, $statuses, true ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That registration cannot be changed now.', 'status' => 409 ), 'flagship-failed' );
	}

	return $booking;
}

/** Load a booking the committee may act on, or respond and exit. */
function law_flagship_require_committee_booking( $is_ajax ) {
	if ( ! law_user_is_committee() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, reviewing registrations is for the committee.', 'status' => 403 ), 'flagship-denied' );
	}
	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	$booking    = $booking_id ? get_post( $booking_id ) : null;
	if ( ! $booking || ! law_flagship_booking_is( $booking ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That registration could not be found.', 'status' => 404 ), 'flagship-failed' );
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
			'honeypot_json'   => array( 'message' => 'Thank you, your registration has been received.' ),
			'honeypot_notice' => 'flagship-applied',
		)
	);

	if ( ! is_user_logged_in() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Please sign in to register.', 'status' => 401 ), 'flagship-denied' );
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
		empty( $result['free'] )
			? array(
				'title'    => 'Taking you to our payment page',
				'message'  => 'Stripe will ask for your card details. Nothing is charged unless your registration is approved.',
				'redirect' => $result['redirect'],
			)
			// A code covered the whole price, so nobody is going to Stripe and
			// promising them a payment page would be a lie.
			: array(
				'title'    => 'Your registration is with the committee',
				'message'  => 'There is nothing to pay, so we have not asked you for any payment details. We will email you as soon as the committee has decided.',
				'redirect' => $result['redirect'],
			),
		// The no-JS tail redirects back with a notice and never sees the
		// payload, so the free path needs its own key or it is told to expect
		// a payment page it is not being sent to.
		empty( $result['free'] ) ? 'flagship-applied' : 'flagship-free-received'
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
		// The discount code as typed, and the one the last successful quote
		// actually used. law_flagship_apply() asks for the press rather than
		// applying a code nobody checked (FLAGSHIP_PAYMENTS.md §13).
		'code'         => sanitize_text_field( (string) ( $raw['code'] ?? '' ) ),
		'applied_code' => sanitize_text_field( (string) ( $raw['applied_code'] ?? '' ) ),
		// Whether this is the fetch path, which is the only one with an Apply
		// button to have pressed.
		'ajax'         => ! empty( $_POST['law_ajax'] ),
		// The GROSS the form displayed, so law_flagship_apply() can refuse
		// rather than reprice under the delegate.
		'price_shown' => absint( $raw['price_shown'] ?? 0 ),
		// The included receptions ticked on the consent step. Stored on the
		// application and granted when the place is confirmed
		// (RECEPTIONS.md §7.1); law_flagship_apply() checks each one really is
		// included, so a forged checkbox reaches nothing.
		'receptions'  => array_values( array_filter( array_map( 'absint', (array) ( $raw['receptions'] ?? array() ) ) ) ),
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
	// One handler for both priced flows (law_booking_update_card_handler(),
	// bookings.php). This action name stays registered so a page already open
	// in somebody's browser keeps working.
	law_booking_update_card_handler( 'law_flagship_update_card' );
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
			'title'    => 'Registration withdrawn',
			'message'  => 'Your registration has been withdrawn and the card details we held have been removed.',
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
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, reviewing registrations is for the committee.', 'status' => 403 ), 'flagship-denied' );
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
		law_events_respond( $is_ajax, false, array( 'message' => 'Choose at least one registration.', 'status' => 400 ), 'flagship-failed' );
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
			'title'    => 'approve' === $decision ? 'Registrations approved' : 'Registrations declined',
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
		_n( '%1$d registration %2$s.', '%1$d registrations %2$s.', $done, 'law' ),
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

add_action( 'admin_post_law_flagship_cancel', 'law_flagship_cancel_handler' );
add_action( 'admin_post_nopriv_law_flagship_cancel', 'law_events_nopriv_json' );

/**
 * Cancel one confirmed ticket from the Flagship bookings row.
 *
 * One booking at a time on purpose: there is no bulk form for it, because
 * every one of these is a conversation and a possible refund the committee
 * then has to have with a named person.
 */
function law_flagship_cancel_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_cancel',
		array( 'rate' => array( 'flagship_review', 60, 600, 300 ), 'honeypot_json' => array( 'message' => 'Done.' ) )
	);

	$booking = law_flagship_require_committee_booking( $is_ajax );
	$result  = law_flagship_cancel_confirmed( (int) $booking->ID, get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message(), 'status' => 400 ), 'flagship-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Ticket cancelled',
			'message'  => 'The place has been released. Nothing has been refunded and the delegate has not been emailed. Reloading the page…',
			'redirect' => law_flagship_bookings_url(),
		),
		'flagship-cancelled'
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
		law_events_respond( $is_ajax, false, array( 'message' => 'That registration is not waiting on a payment.', 'status' => 409 ), 'flagship-failed' );
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

add_action( 'admin_post_law_flagship_ticket_type', 'law_flagship_ticket_type_handler' );
add_action( 'admin_post_nopriv_law_flagship_ticket_type', 'law_events_nopriv_json' );

/**
 * Set the committee's ticket type on one registration.
 *
 * The only handler on this screen that does NOT end in a reload. It answers
 * with the cell's own markup in `cell`, and booking-form.js swaps that node and
 * closes the dialog, so classifying twenty delegates is twenty presses rather
 * than twenty page loads. The `redirect` is still there and still correct: it
 * is what the no-JS <noscript> form in the cell gets.
 */
function law_flagship_ticket_type_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_ticket_type',
		array( 'rate' => array( 'flagship_review', 60, 600, 300 ), 'honeypot_json' => array( 'message' => 'Done.' ) )
	);

	$booking = law_flagship_require_committee_booking( $is_ajax );
	$result  = law_flagship_set_ticket_type(
		(int) $booking->ID,
		sanitize_key( wp_unslash( (string) ( $_POST['law_ticket_type'] ?? '' ) ) ),
		get_current_user_id()
	);

	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $result->get_error_message(), 'status' => 400 ), 'flagship-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Ticket type saved',
			'message'  => '' === $result['type']
				? 'The ticket type has been cleared.'
				: sprintf( 'The ticket type is now %s.', $result['label'] ),
			// The cell renders itself, so the table and this response can never
			// disagree about what a set or unset type looks like.
			'cell'     => array(
				'target' => '[data-law-ticket-cell="' . (int) $booking->ID . '"]',
				'html'   => law_flagship_ticket_type_cell( (int) $booking->ID ),
			),
			'redirect' => law_flagship_bookings_url(),
		),
		'flagship-ticket-type'
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

	// Country, accessibility and dietary too (Denis, 11 September 2026): the
	// delegate list and the exports read those columns live from the profile,
	// and a speaker or VIP added here never filled a registration form in. Not
	// required, though — this dialog asks only for a name and an email.
	$profile = law_registration_clean_attendee_profile( wp_unslash( $_POST ) );
	$valid   = law_registration_validate_attendee_profile( $profile, false );
	if ( is_wp_error( $valid ) ) {
		law_events_respond( $is_ajax, false, law_booking_error_payload( $valid ), 'flagship-failed' );
	}

	$result = law_flagship_add_complimentary(
		array(
			'name'         => wp_unslash( (string) ( $_POST['name'] ?? '' ) ),
			'email'        => wp_unslash( (string) ( $_POST['email'] ?? '' ) ),
			'organisation' => wp_unslash( (string) ( $_POST['organisation'] ?? '' ) ),
			'job_title'    => wp_unslash( (string) ( $_POST['job_title'] ?? '' ) ),
			'press'        => ! empty( $_POST['law_press'] ),
			'profile'      => $profile,
			// The same "Included receptions" fieldset the application form
			// carries, so a comp place gets the drinks its paid equivalent
			// would (RECEPTIONS.md §0.3).
			'receptions'   => array_map( 'absint', (array) ( $_POST['law_receptions'] ?? array() ) ),
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
			// With the notice on the redirect too, so the page you land back on
			// confirms it as well; the dialog's own success text goes with the
			// reload. The no-JS path already got this from the last argument.
			'redirect' => add_query_arg( 'law_notice', 'flagship-added', law_flagship_bookings_url() ),
		),
		'flagship-added'
	);
}

add_action( 'admin_post_law_flagship_substitute', 'law_flagship_substitute_handler' );
add_action( 'admin_post_nopriv_law_flagship_substitute', 'law_events_nopriv_json' );

function law_flagship_substitute_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_substitute',
		array(
			'rate'           => array( 'flagship_review', 60, 600, 300 ),
			'honeypot_json'  => array( 'message' => 'Done.' ),
			// Without this a no-JS honeypot trip redirects with law_notice=saved,
			// which this page's notice map does not carry, so it says nothing at
			// all. The siblings all name their own.
			'honeypot_notice' => 'flagship-substituted',
		)
	);

	$booking = law_flagship_require_committee_booking( $is_ajax );

	// Country, accessibility and dietary as well, on the same reasoning as the
	// comp dialog: the delegate list and the exports read those live from the
	// profile, and somebody put on the list this way never filled a
	// registration form in. Not required, and validated BEFORE the model
	// function resolves an account, so a bad "Other" box never mints a user.
	$profile = law_registration_clean_attendee_profile( wp_unslash( $_POST ) );
	$valid   = law_registration_validate_attendee_profile( $profile, false );
	if ( is_wp_error( $valid ) ) {
		law_events_respond( $is_ajax, false, law_booking_error_payload( $valid ), 'flagship-failed' );
	}

	$result = law_flagship_substitute(
		(int) $booking->ID,
		array(
			'name'         => wp_unslash( (string) ( $_POST['name'] ?? '' ) ),
			'email'        => wp_unslash( (string) ( $_POST['email'] ?? '' ) ),
			'organisation' => wp_unslash( (string) ( $_POST['organisation'] ?? '' ) ),
			'job_title'    => wp_unslash( (string) ( $_POST['job_title'] ?? '' ) ),
			'press'        => ! empty( $_POST['law_press'] ),
			'profile'      => $profile,
		),
		get_current_user_id()
	);

	if ( is_wp_error( $result ) ) {
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'flagship-failed' );
	}

	// The reception outcome is reported rather than swallowed: a place the
	// substitute already owned has been released and nobody refunded them for
	// it, and that is a conversation the committee has to know to have.
	$note = law_flagship_receptions_moved_note( (array) $result['receptions'], (string) $result['name'] );

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Delegate substituted',
			'message'  => trim(
				sprintf(
					'%1$s now holds this ticket in place of %2$s. %3$s %4$s %5$s',
					$result['name'],
					$result['from_name'],
					// Never assert an email that was not sent: the send returns
					// early when the previous delegate's address is unusable,
					// and that is exactly the case where the committee needs to
					// know to telephone somebody.
					$result['told_previous']
						? sprintf( 'Both of them have been emailed.', $result['from_name'] )
						: sprintf( '%s has been emailed. We could NOT email %s: their account has no usable address, so please tell them yourself.', $result['name'], $result['from_name'] ),
					// And never claim a payment stayed put when there was none.
					$result['paid']
						? sprintf( 'The payment, the invoice and the VAT receipt stay with %s.', $result['payer_name'] )
						: 'There was nothing to pay on this place.',
					$note
				)
			),
			'redirect' => add_query_arg( 'law_notice', 'flagship-substituted', law_flagship_bookings_url() ),
		),
		'flagship-substituted'
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
	//
	// Both priced flows return here: this handler dispatches on
	// law_booking_kind(), because a reception waitlist entry saves a payment
	// method through the same Checkout session in setup mode, and a second
	// copy of this round trip would be a second place for the notices and the
	// idempotency to drift (RECEPTIONS.md §6.1).
	if ( isset( $_GET['law_setup'] ) && 'cancelled' === $_GET['law_setup'] && is_user_logged_in() ) {
		$cancelled = absint( $_GET['law_booking'] ?? 0 );
		$kind      = $cancelled ? law_booking_kind( $cancelled ) : 'hosted';
		if ( $cancelled && 'hosted' !== $kind
			&& (int) get_post_field( 'post_author', $cancelled ) === get_current_user_id() ) {
			wp_safe_redirect(
				add_query_arg(
					'law_notice',
					'reception' === $kind ? 'reception-card-cancelled' : 'flagship-card-cancelled',
					law_booking_manage_url( $cancelled )
				)
			);
			exit;
		}
	}
	if ( empty( $_GET['law_setup_session'] ) || ! is_user_logged_in() ) {
		return;
	}
	$booking_id = absint( $_GET['law_booking'] ?? 0 );
	$booking    = $booking_id ? get_post( $booking_id ) : null;
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return;
	}
	$kind = law_booking_kind( $booking );
	if ( 'hosted' === $kind ) {
		return;
	}
	if ( (int) $booking->post_author !== get_current_user_id() ) {
		return;
	}

	$session = sanitize_text_field( wp_unslash( (string) $_GET['law_setup_session'] ) );
	$stored  = law_stripe_attach_setup_result( $booking_id, $session );

	$notice = 'reception' === $kind ? 'reception-card' : 'flagship-card';
	if ( is_wp_error( $stored ) ) {
		$notice = 'reception' === $kind ? 'reception-card-cancelled' : 'flagship-card-failed';
	} else {
		law_booking_dispatch_payment( $booking_id, 'card_saved' );
		// A payment method added after a decline retries the charge at once,
		// so one round trip fixes it rather than waiting on the committee
		// again.
		if ( 'law-payment-failed' === $booking->post_status ) {
			if ( 'reception' === $kind ) {
				// The reception's retry can only take the place if one is
				// free; otherwise the entry goes to the front of the queue and
				// says so. Either way the notice IS the outcome.
				$notice = law_reception_retry_charge( $booking_id, get_current_user_id() ) ?: '';
			} else {
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
		// Under the event lock, like every other mutation. The release is
		// idempotent by deleting _law_discount_id, but that is a check then an
		// act, not one atomic step: two overlapping cron runs could both read
		// the key before either deleted it and both decrement the counter,
		// which would quietly hand a limited code an extra redemption.
		// (Security review, 15 September 2026.) Locked per booking rather than
		// around the whole loop, so it costs nothing under normal load and
		// serialises only the moment that needs it.
		$law_fs_locked = law_booking_lock( $event_id );
		law_booking_set_status( (int) $post->ID, 'law-cancelled' );
		// Whatever code they claimed goes back with the place they never took.
		law_booking_release_discount( (int) $post->ID );
		if ( $law_fs_locked ) {
			law_booking_unlock( $event_id );
		}
		law_event_log(
			$event_id,
			sprintf(
				'Flagship registration #%d closed: no card details were given within %d hours.',
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
				'Flagship registration #%d is still unpaid past its deadline. The committee has been alerted; nothing has been decided automatically.',
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
				'ACTION NEEDED: the payment for flagship registration #%1$d has been in progress since %2$s and has not settled. Check it in Stripe: it needs confirming or refunding by hand. Nothing has been decided automatically.',
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

/* Discount codes _____________________________________________________________
 *
 * Reversal, recorded: on 10 September 2026 Denis ruled codes out here ("we
 * still will need discount code in the future, so leave discount cpt, but we
 * just won't use it for flagship"), and tests/DiscountsTest.php grepped this
 * file to keep it that way. On 15 September 2026 he asked for them, so the
 * catalogue built then has a second consumer now. The three sub-decisions he
 * took at the same time are in FLAGSHIP_PAYMENTS.md §13: an empty "Applies to"
 * means every paid event; a code that covers the whole price removes the
 * payment but NOT the committee's review; and the code is claimed when the
 * delegate registers, not when the committee approves, so the figure they
 * consented to is guaranteed.
 */

/**
 * What a flagship place costs this person right now, with an optional code.
 *
 * law_booking_quote() does the sum — law_event_price_pence() already delegates
 * the flagship's time-switched price to law_flagship_price_pence(), so there
 * was nothing flagship-shaped left in it. This wrapper adds the one thing the
 * receptions do not need: a price of 0 here means the committee has not put
 * the conference on sale, so it is refused rather than quoted as free. Only a
 * code may make a registration free.
 *
 * @return array|WP_Error See law_booking_quote().
 */
function law_flagship_quote( $event_id = 0, $code = '', $user_id = 0 ) {
	$event_id = (int) $event_id ? (int) $event_id : law_flagship_event_id();

	if ( law_flagship_price_pence( 0, $event_id ) < 1 ) {
		return new WP_Error( 'law_flagship_not_on_sale', 'Registration is not open for this event yet.' );
	}

	return law_booking_quote( $event_id, $code, $user_id );
}

/** The flagship answers for itself when the shared quote asks (bookings.php). */
add_filter(
	'law_booking_quote_guard',
	function ( $answer, $event_id ) {
		if ( null !== $answer || ! law_flagship_is( $event_id ) ) {
			return $answer;
		}
		$open = law_flagship_guard_open( $event_id );
		if ( is_wp_error( $open ) ) {
			return $open;
		}

		return law_flagship_price_pence( 0, (int) $event_id ) > 0
			? true
			: new WP_Error( 'law_flagship_not_on_sale', 'Registration is not open for this event yet.' );
	},
	10,
	2
);

/**
 * Offer the flagship as an "Applies to" option on the discount catalogue.
 *
 * Registered here rather than in flagship.php because this is the file that
 * honours a code: a scope the committee can tick has to mean somewhere a code
 * is actually read. Only when it is published and priced, for the same reason
 * a free reception is not offered — there is nothing to discount.
 */
add_filter(
	'law_discount_scope_events',
	function ( $events ) {
		$event_id = law_flagship_event_id();
		if ( ! $event_id || law_flagship_price_pence( 0, $event_id ) < 1 ) {
			return $events;
		}
		$start = (string) law_event_meta( $event_id, '_law_start' );
		$when  = '' !== $start && strtotime( $start ) ? wp_date( 'D j M', strtotime( $start ) ) : '';

		$events[ (int) $event_id ] = trim( get_the_title( $event_id ) . ( '' !== $when ? ', ' . $when : '' ) );

		return $events;
	}
);
