<?php
/**
 * stripe/webhook.php + client.php: signature verification (valid, invalid,
 * missing, stale), idempotency by Stripe event ID, unknown event tolerance,
 * and event resolution via law_event_id and the migrated gf_entry_id map.
 */
class WebhookTest extends LAW_Test_Case {

	private function dispatch( $payload, $header ) {
		$request = new WP_REST_Request( 'POST', '/law/v1/stripe-webhook' );
		$request->set_body( $payload );
		$request->set_header( 'stripe-signature', $header );
		return law_stripe_webhook_handler( $request );
	}

	private function paid_event_body( $event_id, array $metadata ): array {
		return array(
			'id'   => 'evt_' . wp_generate_password( 12, false ),
			'type' => 'invoice.paid',
			'data' => array(
				'object' => array(
					'id'          => 'in_test_' . wp_generate_password( 6, false ),
					'object'      => 'invoice',
					'amount_paid' => 120000,
					'currency'    => 'gbp',
					'metadata'    => $metadata,
				),
			),
		);
	}

	public function test_signature_verification(): void {
		$payload = wp_json_encode( array( 'id' => 'evt_1', 'type' => 'ping' ) );

		// Valid.
		$timestamp = time();
		$valid     = hash_hmac( 'sha256', $timestamp . '.' . $payload, LAW_STRIPE_WEBHOOK_SECRET );
		$this->assertTrue( law_stripe_verify_signature( $payload, "t={$timestamp},v1={$valid}" ) );

		// Invalid signature.
		$bad = law_stripe_verify_signature( $payload, "t={$timestamp},v1=deadbeef" );
		$this->assertInstanceOf( WP_Error::class, $bad );

		// Missing header.
		$this->assertInstanceOf( WP_Error::class, law_stripe_verify_signature( $payload, '' ) );

		// Stale timestamp (replay window).
		$old       = time() - 4000;
		$old_valid = hash_hmac( 'sha256', $old . '.' . $payload, LAW_STRIPE_WEBHOOK_SECRET );
		$stale     = law_stripe_verify_signature( $payload, "t={$old},v1={$old_valid}" );
		$this->assertSame( 'law_stripe_stale_signature', $stale->get_error_code() );

		// A tampered payload fails against the original signature.
		$this->assertInstanceOf(
			WP_Error::class,
			law_stripe_verify_signature( $payload . 'x', "t={$timestamp},v1={$valid}" )
		);
	}

	public function test_rejects_bad_signature_with_400(): void {
		$response = $this->dispatch( '{"id":"evt_x","type":"invoice.paid"}', 't=1,v1=nope' );
		$this->assertSame( 400, $response->get_status() );
	}

	public function test_invoice_paid_confirms_event_by_law_event_id(): void {
		$event_id = $this->make_event( array( '_law_fee_tier' => 'uk', '_law_fee_pence' => 120000, '_law_payment_status' => 'unpaid' ), 'law-approved' );

		list( $payload, $header ) = $this->signed_webhook( $this->paid_event_body( $event_id, array( 'law_event_id' => (string) $event_id ) ) );
		$response = $this->dispatch( $payload, $header );

		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['handled'] );
		$this->assertSame( 'publish', get_post_status( $event_id ), 'Paid → Confirmed → published.' );
		$this->assertSame( 'paid', law_event_meta( $event_id, '_law_payment_status' ) );
	}

	public function test_invoice_paid_resolves_legacy_gf_entry_id_through_the_map(): void {
		$event_id = $this->make_event( array( '_law_fee_pence' => 120000, '_law_payment_status' => 'unpaid' ), 'law-approved' );

		$map = get_option( 'law_events_entry_map', array() );
		$map['events'][991234] = $event_id;
		update_option( 'law_events_entry_map', $map, false );

		list( $payload, $header ) = $this->signed_webhook( $this->paid_event_body( $event_id, array( 'gf_entry_id' => '991234' ) ) );
		$this->dispatch( $payload, $header );

		$this->assertSame( 'publish', get_post_status( $event_id ), 'A pre-cutover Make invoice still lands.' );

		unset( $map['events'][991234] );
		update_option( 'law_events_entry_map', $map, false );
	}

	public function test_replayed_event_id_is_a_no_op(): void {
		$event_id = $this->make_event( array( '_law_fee_pence' => 120000, '_law_payment_status' => 'unpaid' ), 'law-approved' );
		$body     = $this->paid_event_body( $event_id, array( 'law_event_id' => (string) $event_id ) );

		list( $payload, $header ) = $this->signed_webhook( $body );
		$this->dispatch( $payload, $header );
		$log_count_after_first = count( law_event_log_entries( $event_id ) );

		list( $payload2, $header2 ) = $this->signed_webhook( $body ); // Same event ID, fresh signature.
		$response = $this->dispatch( $payload2, $header2 );

		$this->assertTrue( $response->get_data()['duplicate'] ?? false );
		$this->assertSame( $log_count_after_first, count( law_event_log_entries( $event_id ) ), 'A replay changes nothing.' );
	}

	public function test_unknown_event_types_are_tolerated(): void {
		list( $payload, $header ) = $this->signed_webhook( array( 'id' => 'evt_unknown_' . wp_generate_password( 6, false ), 'type' => 'customer.updated', 'data' => array( 'object' => array() ) ) );
		$response = $this->dispatch( $payload, $header );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['handled'] );
	}

	public function test_charge_refunded_records_but_does_not_unpublish(): void {
		$event_id = $this->make_event( array( '_law_payment_status' => 'paid' ), 'publish' );
		list( $payload, $header ) = $this->signed_webhook( array(
			'id'   => 'evt_refund_' . wp_generate_password( 6, false ),
			'type' => 'charge.refunded',
			'data' => array( 'object' => array( 'id' => 'ch_1', 'metadata' => array( 'law_event_id' => (string) $event_id ) ) ),
		) );
		$this->dispatch( $payload, $header );

		$this->assertSame( 'refunded', law_event_meta( $event_id, '_law_payment_status' ) );
		$this->assertSame( 'publish', get_post_status( $event_id ), 'Refunds never auto-unpublish; that is a human decision.' );
	}
}
