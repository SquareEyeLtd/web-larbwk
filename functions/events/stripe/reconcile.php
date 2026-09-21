<?php
/**
 * Payment reconciliation: does Stripe agree with what this site believes?
 *
 * WHY THIS EXISTS. Paying is what publishes a hosted event, and the ONLY thing
 * that tells this site a host fee was paid is an incoming `invoice.paid`. That
 * is a single point of failure with no alarm on it: if the delivery never
 * arrives -- the endpoint is not subscribed to the type, the site was down for
 * the retry window, the money landed while the module was not yet live -- then
 * nothing anywhere notices. The event stays Approved, so it sits on the
 * programme reading "Open soon" and law_booking_guard_open() refuses every
 * booking, while the host is chased for money they have already sent.
 *
 * That is not hypothetical. It is what the retired Make scenario did to 33
 * live events: Gravity Flow step 20 (Waiting for payment) was released by a
 * Make call on `invoice.paid`, the release never arrived, so form 2
 * (Event > submit an event) field 95 (Event status) stayed "Approved" and
 * field 96 (Payment status) stayed blank, which is all migration had to read.
 * Every one of those 33 entries is still parked at step 20 today, while the 21
 * that did get released are complete. The truth about them lives only in
 * Stripe. See EVENTS_FUNC.md, "Payment reconciliation".
 *
 * WHAT THIS FILE IS. The comparison engine, used by two callers that differ
 * only in which events they look at and who presses the button:
 *
 * - The daily sweep below, over events the module itself invoiced (they hold
 *   `_law_stripe_invoice_id`). This is the standing guard against a missed
 *   delivery, and it self-heals, because a host whose payment was missed
 *   should end up exactly where a host whose payment was not does.
 * - migration/reconcile-payments.php, the one-off panel over the LEGACY events
 *   (a hosted invoice URL and no ID). Those need the search-based matching in
 *   repair-stripe-invoice-ids.php and a human eye, so they are deliberately
 *   out of the sweep's scope until the panel has given them an invoice ID,
 *   after which the sweep covers them like anything else.
 *
 * WHAT IT WILL AND WILL NOT DO ON ITS OWN. It settles exactly one discrepancy
 * automatically: Stripe says paid, this site does not. Everything else is
 * reported to a human and left alone. Unpublishing a live event because its
 * invoice reads `void`, or reversing a payment status, is a decision with
 * attendees and money on the other side of it, and a sweep does not get to
 * make it. The asymmetry is deliberate, not an omission.
 *
 * SCOPE. Host fees on events only. Attendee bookings (flagship applications,
 * reception places) key off their own `_law_stripe_invoice_id` on the booking
 * post and would reconcile the same way, but their payment states are a longer
 * vocabulary with their own handler table, so that is a separate job and not
 * quietly half-done here.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Events read from Stripe per daily sweep. One GET each. */
const LAW_RECONCILE_SWEEP_BATCH = 60;

/** Option holding the last sweep's summary, for the settings screen. */
const LAW_RECONCILE_LAST_RUN_OPTION = 'law_events_reconcile_last_run';

/** Option holding the sweep's on/off switch. */
const LAW_RECONCILE_ENABLED_OPTION = 'law_events_reconcile_enabled';

/**
 * Meta recording the discrepancy an event was last alerted about, so a
 * problem that needs a human is raised ONCE and not every morning until they
 * get to it. Cleared when the event and Stripe agree again.
 */
const LAW_RECONCILE_FLAG_META = '_law_reconcile_flagged';

/* Reading the invoice ________________________________________________________ */

/**
 * The Stripe invoice behind an event, however it has to be found.
 *
 * Two routes, in cost order. An event that holds its `in_…` ID is one GET.
 * A legacy event that holds only the hosted URL falls through to
 * law_events_invoice_id_lookup(), which searches on the `gf_entry_id` Make
 * stamped and on the customer behind field 73 (Invoice contact email), and
 * accepts a candidate only on an exact URL or entry-ID match. Reconciliation
 * is therefore never guessing which invoice it is reading.
 *
 * @param int $event_id law_event post ID.
 * @return array{invoice:array,invoice_id:string,customer_id:string,found_by:string,
 *               error:string,notes:string[]}
 */
function law_stripe_reconcile_read_invoice( $event_id ) {
	$event_id = (int) $event_id;
	$out      = array(
		'invoice'     => array(),
		'invoice_id'  => '',
		'customer_id' => '',
		'found_by'    => '',
		'error'       => '',
		'notes'       => array(),
	);

	if ( '' === law_stripe_secret_key() ) {
		$out['error'] = 'Stripe is not configured on this site (LAW_STRIPE_SECRET_KEY is not set), so nothing can be checked.';
		return $out;
	}

	$invoice_id = trim( (string) law_event_meta( $event_id, '_law_stripe_invoice_id' ) );
	if ( '' !== $invoice_id ) {
		$invoice = law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $invoice_id ), array() );
		if ( is_wp_error( $invoice ) ) {
			$out['error'] = 'Stripe would not return invoice ' . $invoice_id . ': ' . $invoice->get_error_message();
			return $out;
		}
		$out['invoice']     = (array) $invoice;
		$out['invoice_id']  = (string) ( $invoice['id'] ?? $invoice_id );
		$out['customer_id'] = is_array( $invoice['customer'] ?? null )
			? (string) ( $invoice['customer']['id'] ?? '' )
			: (string) ( $invoice['customer'] ?? '' );
		$out['found_by']    = 'stored invoice ID';
		return $out;
	}

	// Legacy: a hosted URL and no ID. The matching lives in the repair panel's
	// file rather than being written a second time here, so the two can never
	// disagree about what counts as proof that an invoice is this event's.
	$url = trim( (string) law_event_meta( $event_id, '_law_stripe_invoice_url' ) );
	if ( '' === $url ) {
		$out['error'] = 'This event holds no Stripe invoice, so there is nothing to reconcile against.';
		return $out;
	}
	if ( ! function_exists( 'law_events_invoice_id_lookup' ) ) {
		$out['error'] = 'The legacy invoice lookup is unavailable.';
		return $out;
	}

	$look         = law_events_invoice_id_lookup( $event_id );
	$out['notes'] = (array) $look['notes'];
	if ( '' === $look['invoice_id'] ) {
		// The ambiguous case is resolvable, just not here: the invoice ID panel
		// offers the candidates as a choice. Naming them means this row is not
		// a dead end the reader has to go and investigate blind.
		if ( ! empty( $look['ambiguous'] ) ) {
			$out['notes'][] = 'Candidates: ' . implode( ' · ', (array) $look['ambiguous'] )
				. '. Choose between them on "Repair: legacy Stripe invoices with no invoice ID", then re-check here.';
		}
		$out['error'] = $look['error'] ?: 'No invoice in Stripe could be matched to this event.';
		return $out;
	}

	// The lookup returns a summary, not the invoice object, and reconciliation
	// needs amount_paid and the rest. One more GET, now that there is an ID
	// that has been proved to belong to this event.
	$invoice = law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $look['invoice_id'] ), array() );
	if ( is_wp_error( $invoice ) ) {
		$out['error'] = 'Stripe matched invoice ' . $look['invoice_id'] . ' but would not return it: ' . $invoice->get_error_message();
		return $out;
	}

	$out['invoice']     = (array) $invoice;
	$out['invoice_id']  = $look['invoice_id'];
	$out['customer_id'] = $look['customer_id'];
	$out['found_by']    = $look['matched_on'] ?: 'legacy lookup';
	return $out;
}

/* The comparison _____________________________________________________________ */

/**
 * What this site believes about an event's fee, and what Stripe says, and
 * whether those are the same thing.
 *
 * Reads Stripe; writes nothing. Every caller re-runs it rather than acting on
 * a rendered page, because an invoice may have been paid, voided or replaced
 * between the scan and the button.
 *
 * Verdicts:
 *
 * - `agreed`                     nothing to do.
 * - `paid_not_recorded`          Stripe has the money, this site does not know.
 *                                The ONE case anything here settles by itself.
 * - `recorded_paid_not_settled`  this site says paid; the invoice is still open
 *                                or in draft.
 * - `recorded_paid_written_off`  this site says paid; the invoice was voided or
 *                                marked uncollectible.
 * - `paid_after_cancellation`    the event is cancelled and the invoice was
 *                                paid anyway. Only a human decides a refund.
 * - `no_invoice`                 nothing could be read (unmatched, or Stripe
 *                                would not answer). Not a discrepancy, a gap.
 *
 * @param int $event_id law_event post ID.
 * @return array<string,mixed>
 */
function law_stripe_reconcile_check( $event_id ) {
	$event_id = (int) $event_id;
	$post     = get_post( $event_id );

	$row = array(
		'event_id'       => $event_id,
		'title'          => $post ? $post->post_title : '',
		'post_status'    => $post ? $post->post_status : '',
		'status_label'   => $post ? law_event_status_label( $post ) : '',
		'payment'        => (string) law_event_meta( $event_id, '_law_payment_status' ),
		'fee_pence'      => (int) law_event_meta( $event_id, '_law_fee_pence' ),
		'expected_pence' => 0,
		'invoice_id'     => '',
		'customer_id'    => '',
		'invoice_status' => '',
		'amount_paid'    => 0,
		'invoice_total'  => 0,
		'found_by'       => '',
		'verdict'        => 'no_invoice',
		'settleable'     => false,
		'detail'         => '',
		'notes'          => array(),
	);

	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		$row['detail'] = 'No such event.';
		return $row;
	}

	$row['expected_pence'] = law_event_meta( $event_id, '_law_vat' )
		? (int) round( $row['fee_pence'] * ( 1 + law_events_vat_rate() ) )
		: $row['fee_pence'];

	$read          = law_stripe_reconcile_read_invoice( $event_id );
	$row['notes']  = $read['notes'];
	if ( '' !== $read['error'] ) {
		$row['detail'] = $read['error'];
		return $row;
	}

	$invoice               = $read['invoice'];
	$row['invoice_id']     = $read['invoice_id'];
	$row['customer_id']    = $read['customer_id'];
	$row['found_by']       = $read['found_by'];
	$row['invoice_status'] = (string) ( $invoice['status'] ?? '' );
	$row['amount_paid']    = (int) ( $invoice['amount_paid'] ?? 0 );
	$row['invoice_total']  = (int) ( $invoice['total'] ?? 0 );

	$stripe_paid = 'paid' === $row['invoice_status'];
	$local_paid  = in_array( $row['payment'], array( 'paid', 'refunded' ), true );

	// `refunded` counts as settled here on purpose: a refunded invoice stays
	// `paid` in Stripe (the money went out through the charge), so treating
	// refunded as "not paid" would have the sweep re-confirming and
	// re-publishing every event the committee had refunded.
	if ( $stripe_paid && ! $local_paid ) {
		if ( 'law-cancelled' === $post->post_status ) {
			$row['verdict'] = 'paid_after_cancellation';
			$row['detail']  = sprintf(
				'Invoice %s was PAID (%s) although this event is cancelled. Nothing is changed automatically: review it in Stripe and refund by hand if that is right.',
				$row['invoice_id'],
				law_events_format_pence( $row['amount_paid'] )
			);
			return $row;
		}
		$row['verdict']    = 'paid_not_recorded';
		$row['settleable'] = true;
		$row['detail']     = sprintf(
			'Stripe has been paid %s against invoice %s; this site still reads %s.',
			law_events_format_pence( $row['amount_paid'] ),
			$row['invoice_id'],
			$row['payment'] ? ucfirst( $row['payment'] ) : '(no payment status)'
		);
		return $row;
	}

	if ( ! $stripe_paid && $local_paid ) {
		if ( in_array( $row['invoice_status'], array( 'void', 'uncollectible' ), true ) ) {
			$row['verdict'] = 'recorded_paid_written_off';
			$row['detail']  = sprintf(
				'This site records the fee as %s, but invoice %s is %s in Stripe. Nothing is changed automatically.',
				ucfirst( $row['payment'] ),
				$row['invoice_id'],
				$row['invoice_status']
			);
			return $row;
		}
		$row['verdict'] = 'recorded_paid_not_settled';
		$row['detail']  = sprintf(
			'This site records the fee as %s, but invoice %s is still %s in Stripe (%s of %s received). Nothing is changed automatically.',
			ucfirst( $row['payment'] ),
			$row['invoice_id'],
			$row['invoice_status'] ?: 'in an unknown state',
			law_events_format_pence( $row['amount_paid'] ),
			law_events_format_pence( $row['invoice_total'] )
		);
		return $row;
	}

	$row['verdict'] = 'agreed';
	$row['detail']  = sprintf(
		'Invoice %s is %s in Stripe and this site reads %s.',
		$row['invoice_id'],
		$row['invoice_status'] ?: 'in an unknown state',
		$row['payment'] ? ucfirst( $row['payment'] ) : '(no payment status)'
	);
	return $row;
}

/**
 * Whether a verdict is a discrepancy a human has to look at (as opposed to
 * agreement, or something this file settles itself).
 *
 * @param string $verdict From law_stripe_reconcile_check().
 * @return bool
 */
function law_stripe_reconcile_needs_a_human( $verdict ) {
	return in_array(
		(string) $verdict,
		array( 'recorded_paid_not_settled', 'recorded_paid_written_off', 'paid_after_cancellation' ),
		true
	);
}

/* Settling ___________________________________________________________________ */

/**
 * Record a payment Stripe has and this site does not, and carry the event
 * through everything a live payment would have carried it through.
 *
 * The whole point is that this is NOT a meta write. law_stripe_handle_invoice_paid()
 * does five things on a live payment -- reconcile the amount against the
 * approval snapshot, capture the charge for later refunds, set the payment
 * status, confirm (which publishes, which is what opens booking), and email --
 * and a repair that did only the third leaves the host paid up, the Payment
 * column correct, and the event still refusing every booking. So this walks
 * the same path, in the same order, through the same functions.
 *
 * The one difference is the emails, and it is the caller's to make. A sweep
 * catching a delivery Stripe missed an hour ago SHOULD email: the host is
 * getting the confirmation they are owed, a little late. A repair settling a
 * payment banked in August should not: "thank you for your payment" weeks
 * afterwards reads as a mistake, and the committee's payment-received alert
 * for a payment they reconciled long ago is noise. Pass notify => false and
 * the sends are suppressed but still logged on the event, so whoever runs the
 * repair has the list of hosts to contact by hand.
 *
 * Re-reads Stripe rather than trusting anything passed in: between a panel
 * being rendered and its button being pressed, an invoice can be paid, voided
 * or replaced.
 *
 * @param int   $event_id law_event post ID.
 * @param array $args     source (string, for the log), actor (int), notify (bool).
 * @return array|WP_Error The check row on success.
 */
function law_stripe_reconcile_settle( $event_id, array $args = array() ) {
	$event_id = (int) $event_id;
	$source   = (string) ( $args['source'] ?? 'reconcile' );
	$actor    = (int) ( $args['actor'] ?? 0 );
	$notify   = array_key_exists( 'notify', $args ) ? (bool) $args['notify'] : true;

	$check = law_stripe_reconcile_check( $event_id );
	if ( 'paid_not_recorded' !== $check['verdict'] ) {
		return new WP_Error(
			'law_reconcile_not_settleable',
			sprintf( '#%d is not a payment waiting to be recorded: %s', $event_id, $check['detail'] ?: $check['verdict'] )
		);
	}

	// The IDs the legacy events never had. Recording them here is not a side
	// quest: they are what lets the module resume this invoice instead of
	// raising a second one, and void it if the event is later cancelled.
	if ( '' === trim( (string) law_event_meta( $event_id, '_law_stripe_invoice_id' ) ) && '' !== $check['invoice_id'] ) {
		law_event_update_meta( $event_id, '_law_stripe_invoice_id', $check['invoice_id'] );
	}
	if ( '' === trim( (string) law_event_meta( $event_id, '_law_stripe_customer_id' ) ) && '' !== $check['customer_id'] ) {
		law_event_update_meta( $event_id, '_law_stripe_customer_id', $check['customer_id'] );
	}

	// Same reconciliation the webhook does, and the same rule: a mismatch
	// never blocks the confirmation, because the money genuinely arrived. It
	// is logged loudly and alerted instead.
	if ( $check['fee_pence'] > 0 && $check['amount_paid'] !== $check['expected_pence'] ) {
		law_event_log(
			$event_id,
			sprintf(
				'AMOUNT MISMATCH found by payment reconciliation: paid %s, expected %s (fee snapshot %s%s). Review in Stripe.',
				law_events_format_pence( $check['amount_paid'] ),
				law_events_format_pence( $check['expected_pence'] ),
				law_events_format_pence( $check['fee_pence'] ),
				law_event_meta( $event_id, '_law_vat' ) ? ' + 20% VAT' : ''
			),
			array(
				'action'   => 'amount_mismatch',
				'paid'     => $check['amount_paid'],
				'expected' => $check['expected_pence'],
				'source'   => $source,
			),
			array( 'user_id' => $actor )
		);
		law_events_send( 'admin_stripe_error', $event_id, array(
			'placeholders' => array(
				'stripe_error' => 'Payment reconciliation amount mismatch: paid '
					. law_events_format_pence( $check['amount_paid'] ) . ', expected '
					. law_events_format_pence( $check['expected_pence'] ),
			),
		) );
	}

	// Capture the paying charge, so a later charge.refunded can resolve back to
	// this event: charges carry no invoice metadata. Best effort, as at the
	// webhook.
	law_stripe_store_charge_id( $event_id, $check['invoice_id'] );

	law_event_log(
		$event_id,
		sprintf(
			'Payment reconciliation: Stripe invoice %s is paid (%s), matched by %s, and this site did not know. %s The payment was not recorded here when it happened, which is why this event had not been confirmed; nothing about the money has been changed.',
			$check['invoice_id'],
			law_events_format_pence( $check['amount_paid'] ),
			$check['found_by'] ?: 'its invoice ID',
			$notify
				? 'The host and committee are being emailed as they would have been at the time.'
				: 'The confirmation emails are being suppressed because the payment is historic; contact the host by hand if they need telling.'
		),
		array(
			'action'      => 'payment_reconciled',
			'invoice_id'  => $check['invoice_id'],
			'amount_paid' => $check['amount_paid'],
			'notified'    => $notify,
			'source'      => $source,
		),
		array( 'user_id' => $actor )
	);

	$settle = function () use ( $event_id, $source, $actor ) {
		law_event_set_payment_status( $event_id, 'paid', $source, $actor );

		// Confirming is the half that matters: it is what publishes the event,
		// and publication is what law_booking_guard_open() reads.
		$post = get_post( $event_id );
		if ( $post && 'law-approved' === $post->post_status ) {
			$moved = law_event_workflow_transition(
				$event_id,
				'confirm',
				// 'system' because this IS a machine path; the descriptive
				// source is on the log lines above and below.
				array( 'source' => 'system', 'actor_id' => $actor )
			);
			if ( is_wp_error( $moved ) ) {
				law_event_log(
					$event_id,
					sprintf( 'Payment recorded, but confirming the event FAILED: %s. It is paid and still unpublished; confirm it by hand.', $moved->get_error_message() ),
					array( 'action' => 'reconcile_confirm_failed', 'source' => $source ),
					array( 'user_id' => $actor )
				);
			}
		}
	};

	if ( $notify ) {
		$settle();
	} else {
		law_events_without_emails( 'payment reconciliation', $settle );
	}

	delete_post_meta( $event_id, LAW_RECONCILE_FLAG_META );

	// Re-read, so the caller reports the state it actually left behind.
	$after                = law_stripe_reconcile_check( $event_id );
	$after['settled']     = true;
	$after['was_notified'] = $notify;
	return $after;
}

/* The daily sweep ____________________________________________________________ */

/**
 * The events the sweep watches: anything holding an invoice ID the module can
 * read in one call, at a status where the fee still means something.
 *
 * Legacy events (a hosted URL, no ID) are NOT here. Reading one costs a search
 * plus a customer's whole invoice list, the match can come back ambiguous, and
 * the answer deserves eyeballing before 33 events are published at once. They
 * belong to migration/reconcile-payments.php, and the moment that panel gives
 * one an invoice ID it appears here like anything else.
 *
 * Cancelled events are included deliberately: money arriving on a cancelled
 * event is the discrepancy most worth hearing about.
 *
 * @return int[] Event IDs.
 */
function law_stripe_reconcile_sweep_candidates() {
	return get_posts(
		array(
			'post_type'        => LAW_EVENT_CPT,
			'post_status'      => array( 'law-approved', 'publish', 'law-cancelled' ),
			'numberposts'      => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => true,
			'meta_query'       => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array( 'key' => '_law_stripe_invoice_id', 'value' => '', 'compare' => '!=' ),
			),
		)
	);
}

add_action( 'law_events_payment_reconcile', 'law_stripe_reconcile_run_sweep' );

/**
 * Compare every watched event against Stripe once a day.
 *
 * Self-heals the missed-delivery case and nothing else. A discrepancy needing
 * a human is alerted ONCE per event per verdict (LAW_RECONCILE_FLAG_META),
 * because an alert that arrives every morning until somebody has time is an
 * alert everybody learns to delete.
 *
 * Emails ON. A payment this sweep picks up is hours old at most, so the host
 * is getting the confirmation they were owed, slightly late, which is the
 * right outcome. The historic case is the panel's, and the panel suppresses.
 *
 * @return array{checked:int,settled:int,flagged:int,unreadable:int,lines:string[]}
 */
function law_stripe_reconcile_run_sweep() {
	$summary = array( 'checked' => 0, 'settled' => 0, 'flagged' => 0, 'unreadable' => 0, 'lines' => array() );

	if ( ! law_stripe_reconcile_enabled() || '' === law_stripe_secret_key() ) {
		return $summary;
	}

	foreach ( array_slice( law_stripe_reconcile_sweep_candidates(), 0, LAW_RECONCILE_SWEEP_BATCH ) as $event_id ) {
		$event_id = (int) $event_id;
		$check    = law_stripe_reconcile_check( $event_id );
		++$summary['checked'];

		if ( 'agreed' === $check['verdict'] ) {
			// Whatever was wrong here has been dealt with; let it alert again
			// if it ever comes back.
			delete_post_meta( $event_id, LAW_RECONCILE_FLAG_META );
			continue;
		}

		if ( 'no_invoice' === $check['verdict'] ) {
			// Stripe would not answer, or the invoice has gone. Not a money
			// discrepancy and not worth an email; counted so a run that could
			// read nothing at all is visible on the settings screen.
			++$summary['unreadable'];
			continue;
		}

		if ( $check['settleable'] ) {
			$settled = law_stripe_reconcile_settle(
				$event_id,
				array( 'source' => 'reconcile_sweep', 'actor' => 0, 'notify' => true )
			);
			if ( ! is_wp_error( $settled ) ) {
				++$summary['settled'];
				$summary['lines'][] = sprintf( '#%d %s — payment recorded and confirmed.', $event_id, get_the_title( $event_id ) );
			}
			continue;
		}

		if ( ! law_stripe_reconcile_needs_a_human( $check['verdict'] ) ) {
			continue;
		}

		// Already raised, and nobody has resolved it yet: log nothing, email
		// nothing, wait.
		if ( (string) get_post_meta( $event_id, LAW_RECONCILE_FLAG_META, true ) === $check['verdict'] ) {
			continue;
		}
		update_post_meta( $event_id, LAW_RECONCILE_FLAG_META, $check['verdict'] );
		++$summary['flagged'];
		$summary['lines'][] = sprintf( '#%d %s — %s', $event_id, get_the_title( $event_id ), $check['detail'] );

		law_event_log(
			$event_id,
			'PAYMENT DISCREPANCY: ' . $check['detail'],
			array( 'action' => 'payment_discrepancy', 'verdict' => $check['verdict'], 'invoice_id' => $check['invoice_id'], 'source' => 'reconcile_sweep' ),
			array( 'user_id' => 0 )
		);
		law_events_send( 'admin_stripe_error', $event_id, array(
			'placeholders' => array( 'stripe_error' => $check['detail'] ),
		) );
	}

	update_option(
		LAW_RECONCILE_LAST_RUN_OPTION,
		array( 'at' => time() ) + $summary,
		false
	);

	return $summary;
}

/**
 * Whether the daily sweep runs. On unless somebody turns it off on
 * Events → Settings.
 *
 * @return bool
 */
function law_stripe_reconcile_enabled() {
	// Never written on a site that has not visited the setting, so the default
	// is what decides: on, because a sweep nobody has opted into is exactly the
	// sweep that would have caught this.
	return (bool) get_option( LAW_RECONCILE_ENABLED_OPTION, 1 );
}

add_action( 'init', 'law_stripe_reconcile_schedule' );

/**
 * Keep the daily event scheduled, the way law_flagship_schedule_daily() does.
 */
function law_stripe_reconcile_schedule() {
	if ( ! wp_next_scheduled( 'law_events_payment_reconcile' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'law_events_payment_reconcile' );
	}
}
