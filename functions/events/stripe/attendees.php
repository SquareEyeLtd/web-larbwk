<?php
/**
 * Attendee payments (FLAGSHIP_PAYMENTS.md §4).
 *
 * Phase 4.1 bills HOST fees: an invoice raised against a customer built from
 * the host's own submission, sent by email, paid whenever the firm gets round
 * to it. This file bills ATTENDEES, which is a different shape in three ways:
 * the customer belongs to a WordPress user rather than to an event, the card
 * is saved before anyone decides whether to charge it, and the charge then
 * runs off-session with nobody at the keyboard.
 *
 * It extends stripe/client.php rather than adding a second integration, and
 * it copies stripe/service.php's resume discipline step for step: persist the
 * invoice ID before the line item, reuse a finalised invoice, delete a
 * leftover draft, and key every write with an idempotency key, so a retry
 * after a timeout can never bill anybody twice.
 *
 * Nothing here is flagship-specific. It reads a law_booking's price snapshot
 * (law_booking_price()), so a priced reception reuses the whole file.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* The customer _______________________________________________________________ */

/**
 * The Stripe customer for a WordPress user, created if needed.
 *
 * Deliberately NOT law_stripe_upsert_customer(), which is per EVENT and will
 * adopt an unclaimed customer that merely shares an email address. That is
 * right there, because a host's invoice email may legitimately predate us in
 * Stripe from the legacy Make-based system. It is wrong here: an attendee
 * account has a verified WordPress email and no such history, so adopting a
 * stranger's record would attach a delegate's card to somebody else's
 * customer. This searches only customers we ourselves bound to a user.
 *
 * @return string|WP_Error Customer ID.
 */
function law_stripe_user_customer_id( $user_id ) {
	$user_id = (int) $user_id;
	$user    = $user_id ? get_user_by( 'id', $user_id ) : null;
	if ( ! $user ) {
		return new WP_Error( 'law_stripe_no_user', 'That account could not be found.' );
	}

	$stored = (string) get_user_meta( $user_id, '_law_stripe_customer_id', true );
	if ( '' !== $stored ) {
		return $stored;
	}

	$body = array(
		'email'    => $user->user_email,
		'name'     => $user->display_name ? $user->display_name : $user->user_email,
		'metadata' => array(
			'law_user_id' => (string) $user_id,
			'law_site'    => home_url(),
		),
	);

	$organisation = (string) get_user_meta( $user_id, 'organisation', true );
	if ( '' !== $organisation ) {
		$body['metadata']['law_organisation'] = mb_substr( $organisation, 0, 400 );
	}

	// A stable idempotency key on the user, so a double-submitted application
	// cannot leave two customers behind for the same person.
	$customer = law_stripe_request( 'POST', '/v1/customers', $body, 'law-attendee-cust-' . $user_id );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}

	$customer_id = (string) ( $customer['id'] ?? '' );
	if ( '' === $customer_id ) {
		return new WP_Error( 'law_stripe_no_customer', 'Stripe did not return a customer.' );
	}
	update_user_meta( $user_id, '_law_stripe_customer_id', $customer_id );

	return $customer_id;
}

/**
 * The metadata block on every attendee object, so a webhook can resolve it
 * back to the booking without a lookup table.
 */
function law_stripe_booking_metadata( $booking_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking ) {
		return array();
	}

	return array_filter(
		array(
			'law_booking_id'     => (string) $booking->ID,
			'law_booking_number' => (string) law_event_meta( $booking->ID, '_law_booking_number' ),
			'law_event_id'       => (string) $booking->post_parent,
			'law_user_id'        => (string) $booking->post_author,
		)
	);
}

/* Saving the payment method __________________________________________________ */

/**
 * A Stripe Checkout session in setup mode: the hosted page that saves a
 * payment method without charging it, with 3DS handled by Stripe at that
 * moment (EVENTS_4.2_SPECS.md §7.1, §7.4). PCI scope never reaches LAW.
 *
 * `payment_method_types` is deliberately NOT set, so Stripe offers whatever
 * the Dashboard has enabled that can be saved and re-charged off-session
 * (card, Link and Revolut Pay on staging today; production may differ, and
 * is meant to). Everything downstream is written against "the saved payment
 * method" rather than against a card, so enabling another one in the
 * Dashboard needs no deploy. The one rule the Dashboard must respect is that
 * every method offered here supports recurring / merchant-initiated
 * payments, because the charge runs later with nobody at the keyboard.
 *
 * One function serves all three reasons a method gets saved — applying,
 * changing it before a decision, and replacing one that was declined — so
 * those three journeys cannot drift apart.
 *
 * @param int    $booking_id law_booking post ID.
 * @param string $reason     apply | replace | retry.
 * @return string|WP_Error The URL to send the delegate to.
 */
function law_stripe_create_setup_session( $booking_id, $reason = 'apply' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return new WP_Error( 'law_booking_missing', 'That booking could not be found.' );
	}
	$booking_id = (int) $booking->ID;
	$reason     = in_array( $reason, array( 'apply', 'replace', 'retry' ), true ) ? $reason : 'apply';

	$customer_id = law_stripe_user_customer_id( (int) $booking->post_author );
	if ( is_wp_error( $customer_id ) ) {
		return $customer_id;
	}
	law_event_update_meta( $booking_id, '_law_stripe_customer_id', $customer_id );

	$metadata            = law_stripe_booking_metadata( $booking_id );
	$metadata['reason']  = $reason;
	$return              = law_stripe_setup_return_url( $booking_id );

	$body = array(
		'mode'                       => 'setup',
		'customer'                   => $customer_id,
		'currency'                   => 'gbp',
		'billing_address_collection' => 'required',
		'success_url'                => add_query_arg( 'law_setup_session', '{CHECKOUT_SESSION_ID}', $return ),
		'cancel_url'                 => add_query_arg( 'law_setup', 'cancelled', $return ),
		'metadata'                   => $metadata,
		// Repeated onto the SetupIntent: the checkout.session.completed and
		// setup_intent.succeeded webhooks read different objects, and both
		// have to be able to find the booking on their own.
		'setup_intent_data'          => array( 'metadata' => $metadata ),
	);

	// A fresh attempt number per session, so replacing a method is a new
	// idempotency key rather than a replay of the first one, which Stripe
	// would answer with the original (now used) session.
	$attempt = (int) get_post_meta( $booking_id, '_law_stripe_setup_attempt', true ) + 1;
	update_post_meta( $booking_id, '_law_stripe_setup_attempt', $attempt );

	$session = law_stripe_request(
		'POST',
		'/v1/checkout/sessions',
		$body,
		sprintf( 'law-setup-%d-a%d', $booking_id, $attempt )
	);
	if ( is_wp_error( $session ) ) {
		law_event_log(
			(int) $booking->post_parent,
			sprintf( 'Could not open a payment-details page for booking #%d: %s', law_event_meta( $booking_id, '_law_booking_number' ), $session->get_error_message() ),
			array( 'source' => 'stripe', 'action' => 'setup_session_failed', 'booking' => $booking_id, 'reason' => $reason )
		);
		return $session;
	}

	$url = (string) ( $session['url'] ?? '' );
	if ( '' === $url ) {
		return new WP_Error( 'law_stripe_no_session', 'Stripe did not return a payment page.' );
	}
	update_post_meta( $booking_id, '_law_stripe_setup_session_id', (string) ( $session['id'] ?? '' ) );

	law_event_log(
		(int) $booking->post_parent,
		sprintf(
			'Payment details page opened for booking #%d (%s).',
			law_event_meta( $booking_id, '_law_booking_number' ),
			$reason
		),
		array( 'source' => 'stripe', 'action' => 'setup_session_opened', 'booking' => $booking_id, 'reason' => $reason )
	);

	return $url;
}

/** Where Stripe sends the delegate back to: their own booking. */
function law_stripe_setup_return_url( $booking_id ) {
	if ( function_exists( 'law_booking_manage_url' ) ) {
		$url = law_booking_manage_url( (int) $booking_id );
		if ( $url ) {
			return $url;
		}
	}
	return home_url( '/account/bookings/' );
}

/**
 * Read a completed Checkout session and store what it produced.
 *
 * Called on the delegate's return so the page they land on is already right,
 * and again by the webhook, which is authoritative. Both paths are idempotent
 * and either may arrive first.
 *
 * @return true|WP_Error
 */
function law_stripe_attach_setup_result( $booking_id, $session_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return new WP_Error( 'law_booking_missing', 'That booking could not be found.' );
	}
	$session_id = trim( (string) $session_id );
	if ( '' === $session_id ) {
		return new WP_Error( 'law_stripe_no_session', 'No payment session was given.' );
	}

	$session = law_stripe_request(
		'GET',
		'/v1/checkout/sessions/' . rawurlencode( $session_id ),
		array( 'expand' => array( 'setup_intent' ) )
	);
	if ( is_wp_error( $session ) ) {
		return $session;
	}

	// The session must belong to THIS booking. Without this a delegate could
	// paste another person's session id onto their own booking's return URL
	// and adopt their card.
	$claimed = (int) ( $session['metadata']['law_booking_id'] ?? 0 );
	if ( $claimed !== (int) $booking->ID ) {
		return new WP_Error( 'law_stripe_session_mismatch', 'That payment session belongs to a different booking.' );
	}

	$intent = is_array( $session['setup_intent'] ?? null ) ? $session['setup_intent'] : array();
	$method = (string) ( $intent['payment_method'] ?? '' );
	if ( '' === $method ) {
		return new WP_Error( 'law_stripe_no_method', 'No payment method was saved.' );
	}

	return law_stripe_store_payment_method(
		(int) $booking->ID,
		$method,
		(string) ( $intent['id'] ?? '' )
	);
}

/**
 * Store a saved payment method against a booking, make it the customer's
 * default, and move the booking on from "waiting for payment details".
 *
 * Idempotent: up to three requests call it for one saved method — the
 * checkout.session.completed webhook, the setup_intent.succeeded webhook and
 * the delegate's browser returning from Checkout — in any order and at
 * effectively the same moment. The second and third are no-ops that still
 * return true.
 *
 * The "already stored" check therefore runs UNDER the event lock rather than
 * on its own. Read on its own, all three read the empty value before any of
 * them writes, and all three do the work: harmless for the meta, which is
 * written to the same values, but it wrote the activity log three times.
 *
 * @return true|WP_Error
 */
function law_stripe_store_payment_method( $booking_id, $payment_method_id, $setup_intent_id = '' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return new WP_Error( 'law_booking_missing', 'That booking could not be found.' );
	}
	$booking_id        = (int) $booking->ID;
	$event_id          = (int) $booking->post_parent;
	$payment_method_id = trim( (string) $payment_method_id );
	if ( '' === $payment_method_id ) {
		return new WP_Error( 'law_stripe_no_method', 'No payment method was saved.' );
	}

	$locked = law_booking_lock( $event_id );
	try {
		return law_stripe_store_payment_method_locked( $booking_id, $payment_method_id, $setup_intent_id, $booking );
	} finally {
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
	}
}

/** The body of law_stripe_store_payment_method(), run under the event lock. */
function law_stripe_store_payment_method_locked( $booking_id, $payment_method_id, $setup_intent_id, $booking ) {
	$already = (string) law_event_meta( $booking_id, '_law_stripe_payment_method_id' );
	if ( $already === $payment_method_id && 'pending_setup' !== (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return true;
	}

	$method = law_stripe_request( 'GET', '/v1/payment_methods/' . rawurlencode( $payment_method_id ), array() );
	if ( is_wp_error( $method ) ) {
		return $method;
	}

	law_event_update_meta( $booking_id, '_law_stripe_payment_method_id', $payment_method_id );
	if ( '' !== $setup_intent_id ) {
		law_event_update_meta( $booking_id, '_law_stripe_setup_intent_id', $setup_intent_id );
	}

	// What the delegate chose, described well enough that they recognise it,
	// without us holding anything sensitive. Not necessarily a card: setup
	// mode offers every method the account has enabled that can be saved and
	// re-charged off-session, and this one currently offers Link and Revolut
	// Pay beside it. Both are stored, so a surface can say "Link" honestly
	// rather than showing a blank where a card number would be.
	law_event_update_meta( $booking_id, '_law_stripe_method_type', (string) ( $method['type'] ?? '' ) );
	law_event_update_meta( $booking_id, '_law_stripe_method_label', law_stripe_method_label( $method ) );

	// The card fields stay card-only: exports and the wp-admin facts box read
	// them, and a brand/last4 invented for a wallet would be a lie.
	$card = is_array( $method['card'] ?? null ) ? $method['card'] : array();
	law_event_update_meta( $booking_id, '_law_stripe_card_brand', (string) ( $card['brand'] ?? '' ) );
	law_event_update_meta( $booking_id, '_law_stripe_card_last4', (string) ( $card['last4'] ?? '' ) );
	if ( ! empty( $card['exp_month'] ) && ! empty( $card['exp_year'] ) ) {
		law_event_update_meta(
			$booking_id,
			'_law_stripe_card_exp',
			sprintf( '%02d/%04d', (int) $card['exp_month'], (int) $card['exp_year'] )
		);
	}

	// Default it on the customer, so the invoice can charge it off-session.
	$customer_id = (string) law_event_meta( $booking_id, '_law_stripe_customer_id' );
	if ( '' !== $customer_id ) {
		law_stripe_request(
			'POST',
			'/v1/customers/' . rawurlencode( $customer_id ),
			array( 'invoice_settings' => array( 'default_payment_method' => $payment_method_id ) )
		);
	}

	// A payment method arriving on a failed booking clears the failure: the
	// delegate has answered the exception, and the retry runs next.
	$was = (string) law_event_meta( $booking_id, '_law_payment_status' );
	if ( in_array( $was, array( '', 'pending_setup', 'failed', 'action_required' ), true ) ) {
		law_event_update_meta( $booking_id, '_law_payment_status', 'ready' );
		delete_post_meta( $booking_id, '_law_payment_error' );
	}

	law_event_log(
		(int) $booking->post_parent,
		sprintf(
			'Payment method saved for booking #%d: %s.',
			law_event_meta( $booking_id, '_law_booking_number' ),
			law_booking_payment_method_label( $booking_id ) ?: 'a saved payment method'
		),
		array( 'source' => 'stripe', 'action' => 'card_saved', 'booking' => $booking_id )
	);

	return true;
}

/**
 * Describe a Stripe PaymentMethod in words a delegate will recognise.
 *
 * Deliberately not a fixed list of supported types. Checkout in setup mode
 * offers whatever the Stripe Dashboard has enabled that can be saved and
 * re-charged off-session, so the set changes without a deploy; an unknown
 * type therefore falls back to its own humanised name ("Amazon Pay") rather
 * than to an empty string, which is what a card-only reader produced and
 * which surfaced as "No card saved yet" on a booking that had one.
 *
 * @param array $method A Stripe PaymentMethod object.
 * @return string '' only when there is no method at all.
 */
function law_stripe_method_label( array $method ) {
	$type = (string) ( $method['type'] ?? '' );
	if ( '' === $type ) {
		return '';
	}

	$card = is_array( $method['card'] ?? null ) ? $method['card'] : array();
	if ( 'card' === $type && ! empty( $card['last4'] ) ) {
		$label = trim( ucfirst( (string) ( $card['brand'] ?? '' ) ) . ' ending ' . $card['last4'] );
		if ( ! empty( $card['exp_month'] ) && ! empty( $card['exp_year'] ) ) {
			$label .= sprintf( ', expires %02d/%04d', (int) $card['exp_month'], (int) $card['exp_year'] );
		}
		// Apple Pay and Google Pay arrive as cards with a wallet stamp. Say
		// which wallet: it is how the delegate remembers paying, and it is
		// the difference between "that's mine" and a support email.
		$wallet = (string) ( $card['wallet']['type'] ?? '' );
		if ( '' !== $wallet ) {
			$label = law_stripe_method_name( $wallet ) . ' (' . $label . ')';
		}
		return $label;
	}

	// Bank debits carry their own last four, in their own sub-object.
	foreach ( array( 'bacs_debit', 'sepa_debit', 'au_becs_debit', 'us_bank_account' ) as $bank ) {
		if ( $type === $bank && ! empty( $method[ $bank ]['last4'] ) ) {
			return law_stripe_method_name( $type ) . ' ending ' . $method[ $bank ]['last4'];
		}
	}

	// Link identifies itself by the email the delegate signed in with.
	if ( 'link' === $type && ! empty( $method['link']['email'] ) ) {
		return 'Link (' . $method['link']['email'] . ')';
	}

	return law_stripe_method_name( $type );
}

/** A Stripe payment method type as a person would write it. */
function law_stripe_method_name( $type ) {
	$known = array(
		'card'            => 'Card',
		'link'            => 'Link',
		'revolut_pay'     => 'Revolut Pay',
		'apple_pay'       => 'Apple Pay',
		'google_pay'      => 'Google Pay',
		'amazon_pay'      => 'Amazon Pay',
		'paypal'          => 'PayPal',
		'cashapp'         => 'Cash App Pay',
		'bacs_debit'      => 'Direct Debit',
		'sepa_debit'      => 'SEPA Direct Debit',
		'au_becs_debit'   => 'BECS Direct Debit',
		'us_bank_account' => 'Bank account',
	);
	$type = (string) $type;

	return $known[ $type ] ?? ucwords( str_replace( '_', ' ', $type ) );
}

/**
 * The saved payment method on a booking, in words: "Visa ending 4242,
 * expires 04/2029", "Link (a@b.com)", "Revolut Pay". '' when none is saved.
 */
function law_booking_payment_method_label( $booking_id ) {
	$label = (string) law_event_meta( $booking_id, '_law_stripe_method_label' );
	if ( '' !== $label ) {
		return $label;
	}

	// Bookings saved before the type was recorded, and the wp-admin screen
	// reading an old row: compose the card label the way it used to be.
	$last4 = (string) law_event_meta( $booking_id, '_law_stripe_card_last4' );
	if ( '' === $last4 ) {
		return '';
	}
	$out = trim( ucfirst( (string) law_event_meta( $booking_id, '_law_stripe_card_brand' ) ) . ' ending ' . $last4 );
	$exp = (string) law_event_meta( $booking_id, '_law_stripe_card_exp' );

	return '' !== $exp ? $out . ', expires ' . $exp : $out;
}

/**
 * The old name, from when a card was the only thing Checkout could save.
 * Kept so nothing calling it breaks; new code should say what it means.
 */
function law_booking_card_label( $booking_id ) {
	return law_booking_payment_method_label( $booking_id );
}

/**
 * Forget the payment method a booking saved.
 *
 * Called when an application is declined or withdrawn: the delegate consented
 * to us holding their payment details only while the application was live
 * (EVENTS_4.2_SPECS.md §5.3, §5.4), so the consent ending has to actually
 * remove them rather than merely stop using them.
 *
 * Never fatal. One Stripe will not detach is logged and alerted; refusing to
 * decline somebody because of it would be worse.
 */
function law_stripe_detach_payment_method( $booking_id, $actor = 0 ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$method     = (string) law_event_meta( $booking_id, '_law_stripe_payment_method_id' );
	if ( '' === $method ) {
		return false;
	}

	$result = law_stripe_request(
		'POST',
		'/v1/payment_methods/' . rawurlencode( $method ) . '/detach',
		array(),
		sprintf( 'law-detach-%d-%s', $booking_id, $method )
	);

	// Cleared locally either way: if Stripe already has no such card, holding
	// the reference only makes the record look like we still have one.
	delete_post_meta( $booking_id, '_law_stripe_payment_method_id' );
	delete_post_meta( $booking_id, '_law_stripe_method_type' );
	delete_post_meta( $booking_id, '_law_stripe_method_label' );
	delete_post_meta( $booking_id, '_law_stripe_card_brand' );
	delete_post_meta( $booking_id, '_law_stripe_card_last4' );
	delete_post_meta( $booking_id, '_law_stripe_card_exp' );

	law_event_log(
		(int) $booking->post_parent,
		is_wp_error( $result )
			? sprintf(
				'Could not remove the saved payment method for booking #%d from Stripe (%s). Remove it manually if it is still there.',
				law_event_meta( $booking_id, '_law_booking_number' ),
				$result->get_error_message()
			)
			: sprintf( 'Saved payment method removed for booking #%d.', law_event_meta( $booking_id, '_law_booking_number' ) ),
		array(
			'source'  => 'stripe',
			'action'  => is_wp_error( $result ) ? 'card_detach_failed' : 'card_detached',
			'booking' => $booking_id,
		),
		array( 'user_id' => (int) $actor )
	);

	return ! is_wp_error( $result );
}

/* Charging ___________________________________________________________________ */

/**
 * Charge a booking's saved payment method by raising and paying a Stripe invoice
 * off-session.
 *
 * An invoice rather than a bare PaymentIntent (Denis, 10 September 2026): it
 * carries the VAT line and LAW's VAT number, and gives the delegate a hosted
 * invoice and a PDF to keep, which a card receipt does not. It also reuses the
 * tax rate object and the webhook branches 4.1 already has.
 *
 * @return array|WP_Error The invoice as Stripe last reported it.
 */
function law_stripe_charge_booking( $booking_id ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return new WP_Error( 'law_booking_missing', 'That booking could not be found.' );
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;
	$price      = law_booking_price( $booking_id );

	if ( $price['free'] ) {
		return new WP_Error( 'law_booking_free', 'There is nothing to charge for this booking.' );
	}

	// The same loud configuration guard the host path uses: a VAT-liable
	// charge with no tax rate configured must fail rather than quietly bill
	// the net amount and leave LAW owing the VAT.
	$tax_rate = (string) law_events_setting( 'tax_rate_id', '' );
	if ( $price['vatable'] && '' === $tax_rate ) {
		return new WP_Error(
			'law_no_tax_rate',
			'VAT applies to this booking but no Stripe tax rate ID is configured in LAW → Events settings. Nothing has been charged.'
		);
	}

	$customer_id = law_stripe_user_customer_id( (int) $booking->post_author );
	if ( is_wp_error( $customer_id ) ) {
		return $customer_id;
	}
	law_event_update_meta( $booking_id, '_law_stripe_customer_id', $customer_id );

	$method = (string) law_event_meta( $booking_id, '_law_stripe_payment_method_id' );
	if ( '' === $method ) {
		return new WP_Error( 'law_stripe_no_method', 'There is no saved payment method for this booking.' );
	}

	// One number per deliberate charge attempt, counted BEFORE the resume
	// branch so the pay step gets a fresh key too. It used to be counted
	// after, which left `/pay` keyed on the booking and invoice alone: a
	// retry with a NEW payment method reused the first attempt's key with
	// different parameters, and Stripe refused the whole request with "Keys
	// for idempotent requests can only be used with the same parameters they
	// were first used with" — which then surfaced to the delegate as their
	// decline reason (10 September 2026).
	//
	// Counting per attempt does not risk a double charge. The key never was
	// the thing preventing that: law_stripe_request() has no internal retry,
	// and the guard is the resume GET immediately below, which returns a
	// paid invoice untouched however many times it is asked.
	$attempt = (int) get_post_meta( $booking_id, '_law_stripe_attempt', true ) + 1;
	update_post_meta( $booking_id, '_law_stripe_attempt', $attempt );

	// RESUME before create, exactly as law_stripe_invoice_steps() does: a
	// previous attempt that timed out after creating the invoice must never
	// produce a second one.
	$existing_id = (string) law_event_meta( $booking_id, '_law_stripe_invoice_id' );
	if ( '' !== $existing_id ) {
		$existing = law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $existing_id ), array() );

		// A GET that FAILS must abort, not fall through. The old code deleted
		// the stored invoice ID on any error and then created a second
		// invoice — so a 429 during a bulk pass, or a 30-second timeout, was
		// enough to bill the same delegate twice. law_stripe_request()
		// returns WP_Error for a timeout, an unreadable body and every HTTP
		// >= 400, none of which mean "there is no invoice". The reference is
		// the only thing standing between a retry and a double charge, so it
		// is kept and the caller is told to try again.
		if ( is_wp_error( $existing ) ) {
			return new WP_Error(
				'law_stripe_resume_unreadable',
				sprintf(
					'Stripe could not be reached to check invoice %s, so nothing was charged. Please try again in a moment.',
					$existing_id
				)
			);
		}

		$status = (string) ( $existing['status'] ?? '' );
		if ( 'paid' === $status ) {
			return $existing;
		}
		if ( in_array( $status, array( 'open', 'uncollectible' ), true ) ) {
			// Finalised but unpaid: pay THAT one rather than billing again.
			return law_stripe_pay_invoice( $booking_id, $existing_id, $method, $attempt );
		}
		if ( 'draft' === $status ) {
			$deleted = law_stripe_request( 'DELETE', '/v1/invoices/' . rawurlencode( $existing_id ), array() );
			if ( is_wp_error( $deleted ) ) {
				// Same reasoning: an undeleted draft plus a fresh invoice is
				// two invoices. Stop and let the next attempt resume.
				return new WP_Error(
					'law_stripe_resume_unreadable',
					sprintf( 'A draft invoice (%s) from an earlier attempt could not be cleared, so nothing was charged. Please try again.', $existing_id )
				);
			}
			law_event_log(
				$event_id,
				sprintf(
					'Leftover draft invoice %s on booking #%d from a failed attempt deleted.',
					$existing_id,
					law_event_meta( $booking_id, '_law_booking_number' )
				),
				array( 'source' => 'stripe', 'action' => 'invoice_draft_cleanup', 'booking' => $booking_id )
			);
		}

		// Only now: the invoice is genuinely gone (deleted draft) or dead
		// (void), so the reference is safe to drop and a fresh one is right.
		delete_post_meta( $booking_id, '_law_stripe_invoice_id' );
		delete_post_meta( $booking_id, '_law_stripe_invoice_url' );
		delete_post_meta( $booking_id, '_law_stripe_invoice_pdf' );
	}

	$idem = fn( $step ) => sprintf( 'law-bk-%s-%d-a%d', $step, $booking_id, $attempt );

	$invoice = law_stripe_request(
		'POST',
		'/v1/invoices',
		array(
			'customer'               => $customer_id,
			'collection_method'      => 'charge_automatically',
			'auto_advance'           => 'false',
			'default_payment_method' => $method,
			'metadata'               => law_stripe_booking_metadata( $booking_id ),
		),
		$idem( 'inv' )
	);
	if ( is_wp_error( $invoice ) ) {
		return $invoice;
	}
	$invoice_id = (string) ( $invoice['id'] ?? '' );
	if ( '' === $invoice_id ) {
		return new WP_Error( 'law_stripe_no_invoice', 'Stripe did not return an invoice.' );
	}
	// Persisted BEFORE the line item: a failure after this resumes the same
	// invoice instead of creating another.
	update_post_meta( $booking_id, '_law_stripe_invoice_id', $invoice_id );

	$line_body = array(
		'customer'    => $customer_id,
		'invoice'     => $invoice_id,
		'currency'    => 'gbp',
		'amount'      => $price['net'],
		'description' => law_stripe_booking_line_description( $booking_id, $price ),
	);
	if ( $price['vatable'] ) {
		$line_body['tax_rates'] = array( $tax_rate );
	}
	$line = law_stripe_request( 'POST', '/v1/invoiceitems', $line_body, $idem( 'line' ) );
	if ( is_wp_error( $line ) ) {
		return $line;
	}

	$final = law_stripe_request(
		'POST',
		'/v1/invoices/' . rawurlencode( $invoice_id ) . '/finalize',
		array( 'auto_advance' => 'false' ),
		$idem( 'final' )
	);
	if ( is_wp_error( $final ) ) {
		return $final;
	}

	return law_stripe_pay_invoice( $booking_id, $invoice_id, $method, $attempt );
}

/** The line a delegate reads on their VAT receipt. */
function law_stripe_booking_line_description( $booking_id, array $price ) {
	$booking = get_post( (int) $booking_id );
	$event   = $booking ? get_post( (int) $booking->post_parent ) : null;
	$title   = $event ? $event->post_title : 'London Arbitration Week';

	$start = $event ? (string) law_event_meta( $event->ID, '_law_start' ) : '';
	if ( '' !== $start ) {
		$ts = strtotime( $start );
		if ( $ts ) {
			$title .= ', ' . wp_date( 'j F Y', $ts );
		}
	}

	return mb_substr( $title, 0, 500 );
}

/**
 * Pay a finalised invoice with the saved payment method, off-session.
 *
 * @return array|WP_Error The invoice as Stripe last reported it. A declined
 *                        card is a WP_Error carrying Stripe's own message,
 *                        which is what the delegate is shown.
 */
function law_stripe_pay_invoice( $booking_id, $invoice_id, $payment_method, $attempt = 0 ) {
	// The attempt number belongs IN the key: a retry after a decline is a
	// genuinely different request, usually with a different payment method,
	// and Stripe rejects a key reused with changed parameters.
	$attempt = (int) $attempt ?: (int) get_post_meta( (int) $booking_id, '_law_stripe_attempt', true );

	$paid = law_stripe_request(
		'POST',
		'/v1/invoices/' . rawurlencode( $invoice_id ) . '/pay',
		array(
			'payment_method' => $payment_method,
			'off_session'    => 'true',
			// So the caller can tell a payment still settling from one the
			// bank wants the delegate to authenticate. Invoice.payment_intent
			// was REMOVED in 2025-03-31.basil and this client pins
			// 2025-09-30.clover, so the intent only exists down here.
			'expand'         => array( 'payments.data.payment.payment_intent' ),
		),
		sprintf( 'law-bk-pay-%d-%s-a%d', (int) $booking_id, $invoice_id, $attempt )
	);

	if ( is_wp_error( $paid ) ) {
		// Keep the invoice reference: the exception path needs it, and the
		// retry must pay THIS invoice rather than raise a second one.
		return $paid;
	}

	return $paid;
}

/**
 * The status of the PaymentIntent behind an invoice: 'succeeded',
 * 'processing', 'requires_action', and so on. '' when it cannot be read.
 *
 * A one-line read of a three-level path, given its own name because getting
 * it wrong is expensive: on this API version there is no invoice.payment_intent
 * to fall back to, so a caller that guesses lands on the wrong copy for the
 * delegate. Pass an invoice fetched with
 * expand[]=payments.data.payment.payment_intent.
 */
function law_stripe_invoice_intent_status( array $invoice ) {
	foreach ( (array) ( $invoice['payments']['data'] ?? array() ) as $payment ) {
		$intent = $payment['payment']['payment_intent'] ?? null;
		if ( is_array( $intent ) && ! empty( $intent['status'] ) ) {
			return (string) $intent['status'];
		}
	}

	return '';
}

/**
 * Void a booking's open invoice.
 *
 * Detaching the saved payment method is not enough to make a declined application
 * unpayable: a FINALISED invoice has a hosted page of its own, and the
 * delegate already has that link in the "confirm your payment" email. Without
 * this, someone the committee declined could pay from that page and be
 * resurrected into a confirmed place by the invoice.paid webhook.
 *
 * Modelled on law_stripe_void_invoice() for host fees: a draft is deleted, an
 * open or uncollectible invoice is voided, a PAID one is left strictly alone
 * (that is a refund, and a refund is a human decision). Never fatal — every
 * failure is logged and alerted, and the decline completes regardless.
 */
function law_stripe_void_booking_invoice( $booking_id, $actor = 0 ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;
	$invoice_id = (string) law_event_meta( $booking_id, '_law_stripe_invoice_id' );
	if ( '' === $invoice_id ) {
		return false;
	}
	$number = (int) law_event_meta( $booking_id, '_law_booking_number' );

	$invoice = law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $invoice_id ), array() );
	if ( is_wp_error( $invoice ) ) {
		law_stripe_alert_booking_invoice( $event_id, $booking_id, sprintf(
			'Could not reach Stripe to void invoice %1$s on booking #%2$d. It may still be payable: void it manually.',
			$invoice_id,
			$number
		) );
		return false;
	}

	$status = (string) ( $invoice['status'] ?? '' );
	if ( 'paid' === $status ) {
		law_stripe_alert_booking_invoice( $event_id, $booking_id, sprintf(
			'Invoice %1$s on booking #%2$d is already PAID, so it was not voided. Decide whether to refund.',
			$invoice_id,
			$number
		) );
		return false;
	}
	if ( 'void' === $status ) {
		return true;
	}

	$path   = 'draft' === $status ? '' : '/void';
	$method = 'draft' === $status ? 'DELETE' : 'POST';
	$result = law_stripe_request(
		$method,
		'/v1/invoices/' . rawurlencode( $invoice_id ) . $path,
		array(),
		sprintf( 'law-bk-void-%d-%s', $booking_id, $invoice_id )
	);

	if ( is_wp_error( $result ) ) {
		law_stripe_alert_booking_invoice( $event_id, $booking_id, sprintf(
			'Invoice %1$s on booking #%2$d could not be voided (%3$s). It may still be payable: void it manually.',
			$invoice_id,
			$number,
			$result->get_error_message()
		) );
		return false;
	}

	law_event_log(
		$event_id,
		sprintf( 'Stripe invoice %1$s voided on booking #%2$d, so it can no longer be paid.', $invoice_id, $number ),
		array( 'source' => 'stripe', 'action' => 'invoice_voided', 'booking' => $booking_id ),
		array( 'user_id' => (int) $actor )
	);

	return true;
}

/** Log loudly and tell the admins: an unvoided invoice is live money. */
function law_stripe_alert_booking_invoice( $event_id, $booking_id, $message ) {
	law_event_log(
		$event_id,
		'ACTION NEEDED: ' . $message,
		array( 'source' => 'stripe', 'action' => 'invoice_void_failed', 'booking' => (int) $booking_id )
	);
	law_events_send( 'admin_stripe_error', $event_id, array( 'placeholders' => array( 'stripe_error' => $message ) ) );
}
