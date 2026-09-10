<?php
/**
 * The Stripe webhook endpoint, replacing Make scenario B
 * (EVENTS_4.1_REBUILD.md §3.7): POST /wp-json/law/v1/stripe-webhook.
 * Signature-verified, idempotent by Stripe event ID.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route(
		'law/v1',
		'/stripe-webhook',
		array(
			'methods'             => 'POST',
			'callback'            => 'law_stripe_webhook_handler',
			// Auth is the Stripe signature, verified inside the handler
			// against the raw body before anything is trusted.
			'permission_callback' => '__return_true',
		)
	);
} );

function law_stripe_webhook_handler( WP_REST_Request $request ) {
	$payload = $request->get_body();
	$header  = (string) $request->get_header( 'stripe-signature' );

	$verified = law_stripe_verify_signature( $payload, $header );
	if ( is_wp_error( $verified ) ) {
		return new WP_REST_Response( array( 'error' => $verified->get_error_message() ), 400 );
	}

	$event = json_decode( $payload, true );
	if ( ! is_array( $event ) || empty( $event['id'] ) || empty( $event['type'] ) ) {
		return new WP_REST_Response( array( 'error' => 'Unreadable event.' ), 400 );
	}

	// Idempotency: a replayed event ID is a no-op.
	$processed = get_option( 'law_stripe_processed_events', array() );
	if ( ! is_array( $processed ) ) {
		$processed = array();
	}
	if ( in_array( $event['id'], $processed, true ) ) {
		return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
	}

	// Close the check-and-set window: two concurrent deliveries of the same
	// event could both pass the in_array() check before either records it, each
	// re-dispatching (duplicate confirm attempt and duplicate emails). add_option
	// is atomic on the options table's unique key, so only the first delivery
	// wins the lock; a stale lock (>60s, from a crashed dispatch) is taken over
	// so it can never wedge future retries.
	$lock_key = 'law_stripe_lock_' . md5( (string) $event['id'] );
	$existing = (int) get_option( $lock_key, 0 );
	if ( $existing && ( time() - $existing ) < 60 ) {
		return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
	}
	if ( $existing ) {
		update_option( $lock_key, time(), false );
	} elseif ( ! add_option( $lock_key, time(), '', false ) ) {
		return new WP_REST_Response( array( 'received' => true, 'duplicate' => true ), 200 );
	}

	$handled = law_stripe_webhook_dispatch( $event );

	$processed[] = (string) $event['id'];
	update_option( 'law_stripe_processed_events', array_slice( $processed, -500 ), false );
	delete_option( $lock_key );

	return new WP_REST_Response( array( 'received' => true, 'handled' => (bool) $handled ), 200 );
}

/**
 * @param array $event Decoded Stripe event.
 * @return bool Whether the event type was one we act on.
 */
function law_stripe_webhook_dispatch( array $event ) {
	$object = (array) ( $event['data']['object'] ?? array() );

	// Every invoice-shaped event now has two possible subjects: a HOST fee
	// raised against an event (4.1) and an ATTENDEE place raised against a
	// booking (FLAGSHIP_PAYMENTS.md §4.4). They are told apart by the
	// metadata Stripe hands back, never by the event type, so one endpoint
	// keeps serving both.
	$booking_id = law_stripe_resolve_booking_id( $object );

	switch ( (string) $event['type'] ) {
		case 'checkout.session.completed':
			// Setup mode only: this is a card being saved, not a payment.
			if ( 'setup' !== (string) ( $object['mode'] ?? '' ) || ! $booking_id ) {
				return false;
			}
			$attached = law_stripe_attach_setup_result( $booking_id, (string) ( $object['id'] ?? '' ) );
			if ( ! is_wp_error( $attached ) ) {
				law_flagship_on_card_saved( $booking_id );
			}
			return true;

		case 'setup_intent.succeeded':
			// The belt to checkout.session.completed's braces: either may
			// arrive first, and law_stripe_store_payment_method() is
			// idempotent, so whichever loses the race changes nothing.
			if ( ! $booking_id ) {
				return false;
			}
			$stored = law_stripe_store_payment_method(
				$booking_id,
				(string) ( $object['payment_method'] ?? '' ),
				(string) ( $object['id'] ?? '' )
			);
			if ( ! is_wp_error( $stored ) ) {
				law_flagship_on_card_saved( $booking_id );
			}
			return true;

		case 'setup_intent.setup_failed':
			// Without this the application sits in pending_setup, invisible to
			// the committee, until the 48-hour sweep closes it.
			if ( ! $booking_id ) {
				return false;
			}
			law_flagship_on_card_setup_failed(
				$booking_id,
				(string) ( $object['last_setup_error']['message'] ?? 'The card could not be saved.' )
			);
			return true;

		case 'invoice.paid':
			if ( $booking_id ) {
				return law_flagship_mark_paid( $booking_id, $object, (string) $event['id'] );
			}
			return law_stripe_handle_invoice_paid( $object, (string) $event['id'] );

		case 'invoice.payment_action_required':
			// The card is fine; the bank wants the delegate present. A
			// different state from a decline, and a different message.
			if ( ! $booking_id ) {
				return false;
			}
			law_flagship_mark_payment_failed(
				$booking_id,
				'Your bank needs you to confirm this payment.',
				'action_required',
				(string) ( $object['hosted_invoice_url'] ?? '' )
			);
			return true;

		case 'payment_intent.payment_failed':
			if ( ! $booking_id ) {
				return false;
			}
			law_flagship_mark_payment_failed(
				$booking_id,
				(string) ( $object['last_payment_error']['message'] ?? 'The payment was declined.' )
			);
			return true;

		case 'invoice.payment_failed':
		case 'invoice.voided':
		case 'invoice.marked_uncollectible':
			if ( $booking_id ) {
				if ( 'invoice.payment_failed' === (string) $event['type'] ) {
					law_flagship_mark_payment_failed(
						$booking_id,
						(string) ( $object['last_finalization_error']['message'] ?? 'The card was declined.' )
					);
					return true;
				}
				law_event_log(
					(int) get_post_field( 'post_parent', $booking_id ),
					sprintf( 'Stripe: %s for invoice %s on booking #%d.', $event['type'], $object['id'] ?? '?', law_event_meta( $booking_id, '_law_booking_number' ) ),
					array( 'action' => 'stripe_event', 'type' => $event['type'], 'stripe_event' => $event['id'], 'booking' => $booking_id, 'source' => 'stripe_webhook' ),
					array( 'user_id' => 0 )
				);
				return true;
			}
			$event_id = law_stripe_resolve_event_id( $object );
			if ( $event_id ) {
				law_event_log(
					$event_id,
					sprintf( 'Stripe: %s for invoice %s.', $event['type'], $object['id'] ?? '?' ),
					array( 'action' => 'stripe_event', 'type' => $event['type'], 'stripe_event' => $event['id'], 'source' => 'stripe_webhook' ),
					array( 'user_id' => 0 )
				);
			}
			return true;

		case 'charge.refunded':
			// A Charge created by an invoice payment carries NO invoice
			// metadata, so refunds resolve by the charge ID captured at
			// invoice.paid, with metadata as a best-effort fallback.
			$refunded = (int) ( $object['amount_refunded'] ?? 0 );
			$charged  = (int) ( $object['amount'] ?? 0 );
			$partial  = $charged > 0 && $refunded > 0 && $refunded < $charged;

			$booking_id = law_stripe_booking_by_charge_id( (string) ( $object['id'] ?? '' ) ) ?: $booking_id;
			if ( $booking_id ) {
				law_flagship_mark_refunded( $booking_id, $refunded, $charged, $partial );
				return true;
			}

			$event_id = law_stripe_event_by_charge_id( (string) ( $object['id'] ?? '' ) );
			if ( ! $event_id ) {
				$event_id = law_stripe_resolve_event_id( $object );
			}
			if ( $event_id ) {
				// A PART refund is not a refund: flipping the event to
				// Refunded on a goodwill £50 back would say the fee was never
				// paid. Logged and alerted either way; the status only moves
				// when the whole amount has gone back.
				if ( $partial ) {
					law_event_log(
						$event_id,
						sprintf(
							'PARTIAL REFUND: %s of %s refunded. The payment status is unchanged; review in Stripe.',
							law_events_format_pence( $refunded ),
							law_events_format_pence( $charged )
						),
						array( 'action' => 'partial_refund', 'refunded' => $refunded, 'amount' => $charged, 'source' => 'stripe_webhook' ),
						array( 'user_id' => 0 )
					);
				} else {
					// Recorded and alerted; the event is NOT auto-unpublished
					// (a human decision, EVENTS_4.1_REBUILD.md §3.7).
					law_event_set_payment_status( $event_id, 'refunded', 'stripe_webhook', 0 );
				}
				law_events_send( 'committee_refund', $event_id );
			}
			return true;
	}

	return false;
}

/** invoice.paid: mark paid and confirm/publish the event. */
function law_stripe_handle_invoice_paid( array $invoice, $stripe_event_id ) {
	$event_id = law_stripe_resolve_event_id( $invoice );
	if ( ! $event_id ) {
		return false;
	}

	$amount = isset( $invoice['amount_paid'] ) ? (int) $invoice['amount_paid'] : 0;

	// Reconcile against the approval snapshot: fee, plus VAT when applied
	// (the settings tax rate is 20%). A mismatch never blocks confirmation —
	// the money genuinely arrived — but it is logged loudly and alerted.
	$fee      = (int) law_event_meta( $event_id, '_law_fee_pence' );
	$expected = law_event_meta( $event_id, '_law_vat' ) ? (int) round( $fee * ( 1 + law_events_vat_rate() ) ) : $fee;
	if ( $fee > 0 && $amount !== $expected ) {
		law_event_log(
			$event_id,
			sprintf(
				'AMOUNT MISMATCH on invoice.paid: paid %s, expected %s (fee snapshot %s%s). Review in Stripe.',
				law_events_format_pence( $amount ),
				law_events_format_pence( $expected ),
				law_events_format_pence( $fee ),
				law_event_meta( $event_id, '_law_vat' ) ? ' + 20% VAT' : ''
			),
			array( 'action' => 'amount_mismatch', 'paid' => $amount, 'expected' => $expected, 'stripe_event' => $stripe_event_id, 'source' => 'stripe_webhook' ),
			array( 'user_id' => 0 )
		);
		law_events_send( 'admin_stripe_error', $event_id, array(
			'placeholders' => array( 'stripe_error' => 'invoice.paid amount mismatch: paid ' . law_events_format_pence( $amount ) . ', expected ' . law_events_format_pence( $expected ) ),
		) );
	}

	// Capture the paying charge so a later charge.refunded can resolve back
	// to this event (charges carry no invoice metadata). Best effort.
	law_stripe_store_charge_id( $event_id, (string) ( $invoice['id'] ?? '' ) );
	law_event_log(
		$event_id,
		sprintf(
			'Stripe: invoice %s paid (%s).',
			(string) ( $invoice['id'] ?? '?' ),
			law_events_format_pence( $amount )
		),
		array(
			'action'       => 'invoice_paid',
			'invoice_id'   => (string) ( $invoice['id'] ?? '' ),
			'amount_paid'  => $amount,
			'currency'     => (string) ( $invoice['currency'] ?? '' ),
			'stripe_event' => $stripe_event_id,
			'source'       => 'stripe_webhook',
		),
		array( 'user_id' => 0 )
	);

	law_event_set_payment_status( $event_id, 'paid', 'stripe_webhook', 0 );

	$post = get_post( $event_id );
	if ( $post && 'law-approved' === $post->post_status ) {
		law_event_workflow_transition( $event_id, 'confirm', array( 'source' => 'stripe_webhook', 'actor_id' => 0 ) );
	} elseif ( $post && 'law-cancelled' === $post->post_status ) {
		// Money arriving for a cancelled event (a failed void, or the host paid
		// in the race before the void landed) must never be silent: the invoice
		// should not have been payable, and only a human can decide the refund.
		law_event_log(
			$event_id,
			sprintf(
				'PAYMENT ON A CANCELLED EVENT: invoice %s was paid (%s) after cancellation. Review in Stripe and refund manually if appropriate.',
				(string) ( $invoice['id'] ?? '?' ),
				law_events_format_pence( $amount )
			),
			array( 'action' => 'paid_after_cancel', 'invoice_id' => (string) ( $invoice['id'] ?? '' ), 'amount_paid' => $amount, 'stripe_event' => $stripe_event_id, 'source' => 'stripe_webhook' ),
			array( 'user_id' => 0 )
		);
		law_events_send( 'committee_cancelled_paid', $event_id );
	}

	return true;
}

/**
 * Which booking, if any, a Stripe object belongs to.
 *
 * The sibling of law_stripe_resolve_event_id(): attendee objects carry
 * law_booking_id, host-fee objects do not, which is how one webhook endpoint
 * serves both without branching on the event type.
 *
 * @return int 0 when this is not an attendee object.
 */
function law_stripe_resolve_booking_id( array $object ) {
	$booking_id = (int) ( $object['metadata']['law_booking_id'] ?? 0 );
	if ( ! $booking_id ) {
		return 0;
	}
	$post = get_post( $booking_id );

	return ( $post && LAW_BOOKING_CPT === $post->post_type ) ? (int) $post->ID : 0;
}

/** A booking by the charge captured when its invoice was paid. */
function law_stripe_booking_by_charge_id( $charge_id ) {
	$charge_id = trim( (string) $charge_id );
	if ( '' === $charge_id ) {
		return 0;
	}
	$found = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => array_keys( law_booking_statuses() ),
			'meta_key'       => '_law_stripe_charge_id',
			'meta_value'     => $charge_id,
			'fields'         => 'ids',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		)
	);

	return $found ? (int) $found[0] : 0;
}

/**
 * Fetch and store the charge behind a paid invoice: invoice payments →
 * payment intent → latest charge. Best effort; failures are logged only.
 */
function law_stripe_store_charge_id( $event_id, $invoice_id ) {
	if ( '' === $invoice_id || '' !== (string) law_event_meta( $event_id, '_law_stripe_charge_id' ) ) {
		return;
	}
	$payments = law_stripe_request( 'GET', '/v1/invoice_payments', array( 'invoice' => $invoice_id, 'limit' => 1 ) );
	if ( is_wp_error( $payments ) || empty( $payments['data'][0]['payment'] ) ) {
		return;
	}
	$payment   = (array) $payments['data'][0]['payment'];
	$charge_id = (string) ( $payment['charge'] ?? '' );
	if ( '' === $charge_id && ! empty( $payment['payment_intent'] ) ) {
		$intent = law_stripe_request( 'GET', '/v1/payment_intents/' . rawurlencode( (string) $payment['payment_intent'] ), array() );
		if ( ! is_wp_error( $intent ) ) {
			$charge_id = (string) ( $intent['latest_charge'] ?? '' );
		}
	}
	if ( '' !== $charge_id ) {
		update_post_meta( $event_id, '_law_stripe_charge_id', sanitize_text_field( $charge_id ) );
		law_event_log(
			$event_id,
			sprintf( 'Payment charge %s recorded for refund traceability.', $charge_id ),
			array( 'action' => 'charge_recorded', 'charge_id' => $charge_id, 'source' => 'stripe_webhook' ),
			array( 'user_id' => 0 )
		);
	}
}

/** The event whose stored payment charge matches a charge ID, or 0. */
function law_stripe_event_by_charge_id( $charge_id ) {
	if ( '' === $charge_id ) {
		return 0;
	}
	$posts = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => law_event_all_status_keys(),
			'meta_key'       => '_law_stripe_charge_id',
			'meta_value'     => $charge_id,
			'fields'         => 'ids',
			'posts_per_page' => 1,
		)
	);
	return $posts ? (int) $posts[0] : 0;
}

/**
 * Resolve a Stripe object's metadata to a law_event post: law_event_id first,
 * then the legacy gf_entry_id through the migration map (covers invoices
 * raised by Make before cutover).
 *
 * @param array $object Stripe object carrying metadata.
 * @return int law_event post ID, 0 when unresolvable.
 */
function law_stripe_resolve_event_id( array $object ) {
	$meta = (array) ( $object['metadata'] ?? array() );

	$event_id = absint( $meta['law_event_id'] ?? 0 );
	if ( $event_id && get_post_type( $event_id ) === LAW_EVENT_CPT ) {
		return $event_id;
	}

	$gf_entry_id = absint( $meta['gf_entry_id'] ?? 0 );
	if ( $gf_entry_id ) {
		$map = get_option( 'law_events_entry_map', array() );
		$post_id = absint( $map['events'][ $gf_entry_id ] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== LAW_EVENT_CPT ) {
			// Fall back to the durable per-post meta if the map option is stale
			// or missing, so a payment never silently fails to resolve its event.
			$post_id = function_exists( 'law_events_post_by_legacy_entry' )
				? law_events_post_by_legacy_entry( $gf_entry_id, LAW_EVENT_CPT )
				: 0;
		}
		if ( $post_id && get_post_type( $post_id ) === LAW_EVENT_CPT ) {
			return $post_id;
		}
	}

	return 0;
}
