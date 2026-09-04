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

	$handled = law_stripe_webhook_dispatch( $event );

	$processed[] = (string) $event['id'];
	update_option( 'law_stripe_processed_events', array_slice( $processed, -500 ), false );

	return new WP_REST_Response( array( 'received' => true, 'handled' => (bool) $handled ), 200 );
}

/**
 * @param array $event Decoded Stripe event.
 * @return bool Whether the event type was one we act on.
 */
function law_stripe_webhook_dispatch( array $event ) {
	$object = (array) ( $event['data']['object'] ?? array() );

	switch ( (string) $event['type'] ) {
		case 'invoice.paid':
			return law_stripe_handle_invoice_paid( $object, (string) $event['id'] );

		case 'invoice.payment_failed':
		case 'invoice.voided':
		case 'invoice.marked_uncollectible':
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
			$event_id = law_stripe_event_by_charge_id( (string) ( $object['id'] ?? '' ) );
			if ( ! $event_id ) {
				$event_id = law_stripe_resolve_event_id( $object );
			}
			if ( $event_id ) {
				// Recorded and alerted; the event is NOT auto-unpublished
				// (a human decision, EVENTS_4.1_REBUILD.md §3.7).
				law_event_set_payment_status( $event_id, 'refunded', 'stripe_webhook', 0 );
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
	$expected = law_event_meta( $event_id, '_law_vat' ) ? (int) round( $fee * 1.2 ) : $fee;
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
	}

	return true;
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
		if ( $post_id && get_post_type( $post_id ) === LAW_EVENT_CPT ) {
			return $post_id;
		}
	}

	return 0;
}
