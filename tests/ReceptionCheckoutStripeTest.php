<?php
/**
 * The payment-mode Checkout session and the webhook's handler table
 * (RECEPTIONS.md §3).
 *
 * Two things are pinned here, and both are the kind that fail silently:
 *
 *  - the session BODY. Stripe is forgiving about extra keys and unforgiving
 *    about missing ones, and the wrong unit_amount or a missing tax rate is a
 *    VAT liability rather than an error message.
 *  - the ROUTING. One endpoint now serves host fees, flagship applications and
 *    reception places, and the whole point of the handler table is that a
 *    reception's payment can never reach the flagship's idea of "confirmed".
 */
class ReceptionCheckoutStripeTest extends LAW_Test_Case {

	protected function tearDown(): void {
		remove_all_filters( 'pre_option_law_events_source' );
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		parent::tearDown();
	}

	private function make_reception( array $meta = array() ): int {
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );

		return $this->make_event(
			array_merge(
				array(
					'_law_is_reception'         => 1,
					'_law_start'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:30' ) ),
					'_law_tickets_available'    => 10,
					'_law_attendee_price_pence' => 4500,
					'_law_registration_state'   => 'open',
				),
				$meta
			),
			'publish'
		);
	}

	private function make_hold( $event_id, array $meta = array() ): int {
		$user_id = $this->make_user();
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Jane', 'last_name' => 'Smith' ) );
		$booking_id = law_booking_insert(
			$event_id,
			$user_id,
			'law-pending-payment',
			array( 'user_id' => $user_id, 'name' => 'Jane Smith', 'email' => 'jane-' . $user_id . '@example.test' ),
			array_merge( array( '_law_price_pence' => 4500, '_law_vat' => 1, '_law_payment_status' => 'pending_setup' ), $meta )
		);
		$this->posts[] = $booking_id;

		return (int) $booking_id;
	}

	/** The body of the POST to /v1/checkout/sessions, as it was sent. */
	private function session_body(): array {
		foreach ( (array) ( $GLOBALS['law_test_stripe_idem'] ?? array() ) as $call ) {
			if ( 'POST' === $call['method'] && '/v1/checkout/sessions' === $call['path'] ) {
				return (array) $call['body'];
			}
		}
		return array();
	}

	public function test_the_session_body_bills_the_net_with_the_tax_rate_and_makes_an_invoice(): void {
		$event_id = $this->make_reception();
		$booking  = $this->make_hold( $event_id );
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_test', 'object' => 'customer' ),
			array( 'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_test', 'expires_at' => time() + 1860 ),
		);

		$url = law_stripe_create_checkout_session( $booking );
		$this->assertSame( 'https://checkout.stripe.test/cs_test', $url );

		$body = $this->session_body();
		$this->assertSame( 'payment', $body['mode'] );
		$this->assertSame( 'cus_test', $body['customer'] );
		$this->assertSame( 'required', $body['billing_address_collection'] );
		$this->assertSame( array( 'address' => 'auto', 'name' => 'auto' ), $body['customer_update'], 'Without this the VAT invoice has no address on it.' );
		$this->assertArrayNotHasKey( 'payment_method_types', $body, 'Whatever the Dashboard enables is the supported set.' );

		$line = $body['line_items'][0];
		$this->assertSame( 1, $line['quantity'], 'One place per checkout; colleagues buy their own.' );
		$this->assertSame( 4500, $line['price_data']['unit_amount'], 'The NET: Stripe adds the tax rate, so sending gross would charge VAT on VAT.' );
		$this->assertSame( 'gbp', $line['price_data']['currency'] );
		$this->assertSame( array( 'txr_test_unit' ), $line['tax_rates'] );

		$this->assertSame( 'true', $body['invoice_creation']['enabled'] );
		$this->assertSame( (string) $booking, $body['invoice_creation']['invoice_data']['metadata']['law_booking_id'] );
		$this->assertSame( (string) $booking, $body['payment_intent_data']['metadata']['law_booking_id'], 'A Charge inherits this, so a refund can resolve back.' );
		$this->assertSame( (string) $booking, $body['metadata']['law_booking_id'] );

		// 1800 is Stripe's minimum and is refused under clock skew.
		$this->assertGreaterThan( time() + 1800, $body['expires_at'] );

		$this->assertStringContainsString( '{CHECKOUT_SESSION_ID}', $body['success_url'] );
		$this->assertStringContainsString( 'law_checkout=cancelled', $body['cancel_url'] );
		$this->assertSame( 'cs_test', (string) law_event_meta( $booking, '_law_stripe_checkout_session_id' ) );
		$this->assertNotSame( '', (string) law_event_meta( $booking, '_law_checkout_expires_at' ) );
	}

	public function test_a_vatable_place_with_no_tax_rate_refuses_rather_than_billing_the_net(): void {
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'tax_rate_id' => '' ) );
		$event_id = $this->make_reception();
		$booking  = $this->make_hold( $event_id );

		$result = law_stripe_create_checkout_session( $booking );
		$this->assertWPError( $result, 'law_no_tax_rate' );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Nothing reaches Stripe: LAW would owe the VAT.' );
	}

	public function test_each_attempt_gets_its_own_idempotency_key(): void {
		$event_id = $this->make_reception();
		$booking  = $this->make_hold( $event_id );
		$keys     = array();

		foreach ( array( 'cs_one', 'cs_two' ) as $id ) {
			$GLOBALS['law_test_stripe_queue'] = array(
				array( 'id' => 'cus_test', 'object' => 'customer' ),
				array( 'id' => $id, 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/' . $id, 'expires_at' => time() + 1860 ),
			);
			law_stripe_create_checkout_session( $booking );
		}
		foreach ( (array) $GLOBALS['law_test_stripe_idem'] as $call ) {
			if ( '/v1/checkout/sessions' === $call['path'] ) {
				$keys[] = $call['idem'];
			}
		}

		$this->assertCount( 2, $keys );
		$this->assertNotSame( $keys[0], $keys[1], 'A replayed key would return the first, now dead, session.' );
	}

	/* Routing ________________________________________________________________ */

	private function dispatch( string $type, array $object ): bool {
		return (bool) law_stripe_webhook_dispatch(
			array( 'id' => 'evt_' . wp_generate_password( 8, false ), 'type' => $type, 'data' => array( 'object' => $object ) )
		);
	}

	public function test_the_four_checkout_events_route_to_the_reception_handlers(): void {
		$event_id = $this->make_reception();
		$booking  = $this->make_hold( $event_id );
		law_event_update_meta( $booking, '_law_stripe_checkout_session_id', 'cs_test' );
		$metadata = array( 'law_booking_id' => (string) $booking );

		// completed, payment mode, paid → confirmed.
		$this->assertTrue(
			$this->dispatch(
				'checkout.session.completed',
				array( 'id' => 'cs_test', 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'paid', 'amount_total' => 5400, 'metadata' => $metadata )
			)
		);
		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( 'paid', (string) law_event_meta( $booking, '_law_payment_status' ) );

		// A second hold, to exercise the three other events.
		$held = $this->make_hold( $event_id );
		law_event_update_meta( $held, '_law_stripe_checkout_session_id', 'cs_async' );
		$meta2 = array( 'law_booking_id' => (string) $held );

		$this->assertTrue(
			$this->dispatch(
				'checkout.session.completed',
				array( 'id' => 'cs_async', 'object' => 'checkout.session', 'mode' => 'payment', 'payment_status' => 'unpaid', 'metadata' => $meta2 )
			)
		);
		$this->assertSame( 'processing', (string) law_event_meta( $held, '_law_payment_status' ) );
		$this->assertSame( 'law-pending-payment', get_post_status( $held ), 'Money in flight keeps the place.' );
		$this->assertSame( '', (string) law_event_meta( $held, '_law_checkout_expires_at' ), 'And the sweep must never release it.' );

		$this->assertTrue(
			$this->dispatch(
				'checkout.session.async_payment_succeeded',
				array( 'id' => 'cs_async', 'object' => 'checkout.session', 'amount_total' => 5400, 'metadata' => $meta2 )
			)
		);
		$this->assertSame( 'publish', get_post_status( $held ) );

		// expired, on a hold that is still a hold.
		$third = $this->make_hold( $event_id );
		law_event_update_meta( $third, '_law_stripe_checkout_session_id', 'cs_gone' );
		$GLOBALS['law_test_stripe_queue'] = array( array( 'id' => 'cs_gone', 'object' => 'checkout.session', 'status' => 'expired' ) );
		$this->assertTrue(
			$this->dispatch(
				'checkout.session.expired',
				array( 'id' => 'cs_gone', 'object' => 'checkout.session', 'metadata' => array( 'law_booking_id' => (string) $third ) )
			)
		);
		$this->assertSame( 'law-cancelled', get_post_status( $third ) );
	}

	public function test_invoice_paid_reaches_a_reception_and_carries_the_receipt(): void {
		$event_id = $this->make_reception();
		$booking  = $this->make_hold( $event_id );

		$this->assertTrue(
			$this->dispatch(
				'invoice.paid',
				array(
					'id'                 => 'in_test',
					'object'             => 'invoice',
					'amount_paid'        => 5400,
					'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
					'invoice_pdf'        => 'https://invoice.stripe.test/in_test.pdf',
					'metadata'           => array( 'law_booking_id' => (string) $booking ),
				)
			)
		);
		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( 'https://invoice.stripe.test/in_test', (string) law_event_meta( $booking, '_law_stripe_invoice_url' ) );
	}

	public function test_a_kind_with_no_handler_changes_nothing_and_says_so(): void {
		$hosted  = $this->make_event( array( '_law_tickets_available' => 5 ), 'publish' );
		$booking = $this->make_booking_id( $hosted, $this->make_user() );

		$this->assertFalse(
			law_booking_dispatch_payment( $booking, 'paid', array( 'object' => 'invoice', 'amount_paid' => 100 ) )
		);
		$log = law_event_log_entries( $hosted );
		$this->assertNotEmpty( $log );
		$this->assertStringContainsString(
			'no handler',
			implode( ' ', array_map( fn( $row ) => (string) $row->comment_content, $log ) ),
			'Fail closed, and say so: money must never be handled by a flow that means something else by these statuses.'
		);
	}

	public function test_the_handler_table_carries_a_row_per_priced_flow(): void {
		$this->assertNotEmpty( law_booking_payment_handlers( 'flagship' ) );
		$this->assertNotEmpty( law_booking_payment_handlers( 'reception' ) );
		$this->assertSame( array(), law_booking_payment_handlers( 'hosted' ), 'A free booking never reaches Stripe.' );

		// The outcomes the webhook can raise all exist for both priced flows,
		// except session_expired, which only a payment-mode session has.
		foreach ( array( 'card_saved', 'setup_failed', 'paid', 'processing', 'payment_failed', 'action_required', 'refunded' ) as $outcome ) {
			$this->assertArrayHasKey( $outcome, law_booking_payment_handlers( 'flagship' ), "flagship: {$outcome}" );
			$this->assertArrayHasKey( $outcome, law_booking_payment_handlers( 'reception' ), "reception: {$outcome}" );
		}
		$this->assertArrayHasKey( 'session_expired', law_booking_payment_handlers( 'reception' ) );
	}
}
