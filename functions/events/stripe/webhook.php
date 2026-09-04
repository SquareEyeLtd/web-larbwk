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
			$event_id = law_stripe_resolve_event_id( $object );
			if ( $event_id ) {
				// Recorded and alerted; the event is NOT auto-unpublished
				// (a human decision, EVENTS_4.1_REBUILD.md §3.7).
				law_event_set_payment_status( $event_id, 'refunded', 'stripe_webhook', 0 );
				law_events_send( 'committee_payment_received', $event_id, array(
					'placeholders' => array( 'fee' => 'REFUND recorded — please review' ),
				) );
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
