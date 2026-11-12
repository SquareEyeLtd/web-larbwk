<?php
/**
 * A payer's Stripe customer that Stripe no longer has
 * (law_stripe_post_for_payer(), stripe/attendees.php).
 *
 * Found on staging on 26 October 2026: its database is a copy of production,
 * so every user carried a LIVE customer ID, and once its keys were switched to
 * test every "Add payment details" failed with "No such customer". A customer
 * deleted in the Stripe dashboard on production fails the same way. Pinned:
 *
 *  - both Checkout sessions give the payer a new customer and open the page
 *    on the second send, under a fresh idempotency key each time;
 *  - only that refusal is retried, and only once;
 *  - the off-session charge, which cannot recover (the saved method went with
 *    the customer), hands the delegate the fix rather than an admin;
 *  - what the delegate is told when the failure is ours.
 */
class StripeMissingCustomerTest extends LAW_Test_Case {

	protected function tearDown(): void {
		remove_all_filters( 'pre_option_law_events_source' );
		parent::tearDown();
	}

	/** Stripe's answer for a customer it does not have. */
	private function missing_customer( string $customer_id ): WP_Error {
		$message = "No such customer: '{$customer_id}'";

		return new WP_Error(
			'law_stripe_api_error',
			$message,
			array(
				'status' => 404,
				'stripe' => array( 'code' => 'resource_missing', 'param' => 'customer', 'type' => 'invalid_request_error', 'message' => $message ),
			)
		);
	}

	/** A priced reception place whose payer already holds a customer. */
	private function make_priced_place( string $customer_id ): array {
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		$event_id = $this->make_event(
			array(
				'_law_is_reception'         => 1,
				'_law_start'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:30' ) ),
				'_law_tickets_available'    => 10,
				'_law_attendee_price_pence' => 4500,
				'_law_registration_state'   => 'open',
			),
			'publish'
		);
		$user_id = $this->make_user();
		update_user_meta( $user_id, '_law_stripe_customer_id', $customer_id );
		$booking_id = law_booking_insert(
			$event_id,
			$user_id,
			'law-pending-payment',
			array( 'user_id' => $user_id, 'name' => 'Jane Smith', 'email' => 'jane-' . $user_id . '@example.test' ),
			array( '_law_price_pence' => 4500, '_law_vat' => 1, '_law_payment_status' => 'pending_setup' )
		);
		$this->posts[] = (int) $booking_id;
		law_event_update_meta( $booking_id, '_law_stripe_customer_id', $customer_id );

		return array( (int) $booking_id, $user_id, $event_id );
	}

	/** The idempotency keys and bodies sent, in order. */
	private function sent(): array {
		return (array) ( $GLOBALS['law_test_stripe_idem'] ?? array() );
	}

	private function logged( int $event_id, string $action ): array {
		return array_values(
			array_filter(
				law_event_log_entries( $event_id ),
				fn( $entry ) => $action === ( law_event_log_context( $entry->comment_ID )['action'] ?? '' )
			)
		);
	}

	public function test_the_setup_page_opens_on_a_new_customer_when_stripe_has_lost_the_old_one(): void {
		list( $booking_id, $user_id, $event_id ) = $this->make_priced_place( 'cus_gone' );

		$GLOBALS['law_test_stripe_queue'] = array(
			$this->missing_customer( 'cus_gone' ),
			array( 'id' => 'cus_new', 'object' => 'customer' ),
			array( 'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_test' ),
		);

		$url = law_stripe_create_setup_session( $booking_id, 'waitlist' );

		$this->assertSame( 'https://checkout.stripe.test/cs_test', $url );
		$this->assertSame( 'cus_new', (string) get_user_meta( $user_id, '_law_stripe_customer_id', true ), 'The payer keeps the new customer.' );
		$this->assertSame( 'cus_new', (string) law_event_meta( $booking_id, '_law_stripe_customer_id' ) );

		$sent = $this->sent();
		$this->assertSame( array( '/v1/checkout/sessions', '/v1/customers', '/v1/checkout/sessions' ), array_column( $sent, 'path' ) );
		$this->assertSame( 'law-attendee-cust-' . $user_id . '-after-cus_gone', $sent[1]['idem'], 'The plain key would replay the customer that went missing.' );
		$this->assertSame( sprintf( 'law-setup-%d-a1', $booking_id ), $sent[0]['idem'] );
		$this->assertSame( sprintf( 'law-setup-%d-a2', $booking_id ), $sent[2]['idem'], 'Stripe refuses a key sent again with a different customer.' );
		$this->assertSame( 'cus_new', $sent[2]['body']['customer'] );
		$this->assertCount( 1, $this->logged( $event_id, 'customer_replaced' ) );
	}

	public function test_the_payment_page_recovers_the_same_way(): void {
		list( $booking_id ) = $this->make_priced_place( 'cus_gone' );

		$GLOBALS['law_test_stripe_queue'] = array(
			$this->missing_customer( 'cus_gone' ),
			array( 'id' => 'cus_new', 'object' => 'customer' ),
			array( 'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_test' ),
		);

		$this->assertSame( 'https://checkout.stripe.test/cs_test', law_stripe_create_checkout_session( $booking_id ) );

		$sent = $this->sent();
		$this->assertSame( sprintf( 'law-co-%d-a2', $booking_id ), $sent[2]['idem'] );
		$this->assertSame( 'cus_new', $sent[2]['body']['customer'] );
	}

	/** Any other refusal is somebody else's problem, and is not sent twice. */
	public function test_only_a_missing_customer_is_retried(): void {
		list( $booking_id, $user_id ) = $this->make_priced_place( 'cus_kept' );

		$GLOBALS['law_test_stripe_queue'] = array(
			new WP_Error( 'law_stripe_api_error', 'Invalid API Key provided.', array( 'status' => 401, 'stripe' => array( 'type' => 'invalid_request_error' ) ) ),
		);

		$this->assertWPError( law_stripe_create_setup_session( $booking_id, 'waitlist' ), 'law_stripe_api_error' );
		$this->assertCount( 1, $this->sent() );
		$this->assertSame( 'cus_kept', (string) get_user_meta( $user_id, '_law_stripe_customer_id', true ) );
	}

	/** A second miss on the new customer is reported, not chased round again. */
	public function test_the_retry_happens_once(): void {
		list( $booking_id ) = $this->make_priced_place( 'cus_gone' );

		$GLOBALS['law_test_stripe_queue'] = array(
			$this->missing_customer( 'cus_gone' ),
			array( 'id' => 'cus_new', 'object' => 'customer' ),
			$this->missing_customer( 'cus_new' ),
		);

		$this->assertWPError( law_stripe_create_setup_session( $booking_id, 'waitlist' ), 'law_stripe_api_error' );
		$this->assertCount( 3, $this->sent() );
	}

	/** Two requests racing: the second finds the first one's replacement. */
	public function test_a_customer_already_replaced_is_reused(): void {
		$user_id = $this->make_user();
		update_user_meta( $user_id, '_law_stripe_customer_id', 'cus_replaced' );

		$this->assertSame( 'cus_replaced', law_stripe_user_customer_id( $user_id, 'cus_gone' ) );
		$this->assertSame( array(), $this->sent() );
	}

	/**
	 * The saved method went with the customer, so the charge cannot go
	 * through. It must come back as the delegate's to fix, because they are
	 * the only one who can: a configuration error would stop with the place
	 * untouched and alert an admin who cannot put a card back.
	 */
	public function test_a_charge_on_a_lost_customer_goes_back_to_the_delegate(): void {
		list( $booking_id ) = $this->make_priced_place( 'cus_gone' );
		law_event_update_meta( $booking_id, '_law_stripe_payment_method_id', 'pm_gone' );

		$GLOBALS['law_test_stripe_queue'] = array( $this->missing_customer( 'cus_gone' ) );

		$charged = law_stripe_charge_booking( $booking_id );

		$this->assertWPError( $charged, 'law_stripe_customer_missing' );
		$this->assertFalse( law_booking_is_configuration_error( $charged ) );
		$this->assertSame( '', (string) law_event_meta( $booking_id, '_law_stripe_invoice_id' ), 'No invoice was raised.' );
	}

	/* What the delegate is told ______________________________________________ */

	public function test_our_failure_is_said_plainly_and_theirs_verbatim(): void {
		$ours = law_booking_refusal_payload( $this->missing_customer( 'cus_gone' ), 'Plain words.' );
		$this->assertSame( array( 'message' => 'Plain words.' ), $ours, 'Stripe\'s wording never reaches a delegate.' );

		$theirs = law_booking_refusal_payload( new WP_Error( 'law_booking_substituted_paid', 'This place has been transferred.' ), 'Plain words.' );
		$this->assertSame( 'This place has been transferred.', $theirs['message'] );
	}
}
