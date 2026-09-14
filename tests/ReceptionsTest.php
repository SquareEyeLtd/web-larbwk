<?php
/**
 * The reception engine: the guards, the quote, the checkout hold and the
 * single idempotent path to a confirmed place (RECEPTIONS.md §1-§3).
 *
 * What these pin is the machinery that is expensive to get quietly wrong,
 * because every one of them is about money or about somebody's place:
 *
 *  - a hold counts towards capacity on a priced event and NOT on a free one,
 *    which is what stops the last place selling twice;
 *  - the discount code is claimed before the booking exists and released
 *    exactly once, so a code on its last use is either yours or somebody
 *    else's and never both;
 *  - the price the form showed is the price charged, or the booking is
 *    refused rather than repriced under the delegate;
 *  - confirmation is idempotent across the session and the invoice arriving
 *    in either order, and the email goes exactly once;
 *  - a payment landing on a cancelled booking never seats anybody.
 *
 * Stripe is mocked through the law_stripe_request_mock seam in
 * stripe/client.php; an unmocked call fails loudly (tests/bootstrap.php).
 */
class ReceptionsTest extends LAW_Test_Case {

	protected function tearDown(): void {
		remove_all_filters( 'pre_option_law_events_source' );
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	/** A published, priced reception on the CPT source. */
	private function make_reception( array $meta = array(), $status = 'publish' ): int {
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );

		return $this->make_event(
			array_merge(
				array(
					'_law_is_reception'         => 1,
					'_law_is_law_event'         => 1,
					'_law_start'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:30' ) ),
					'_law_end'                  => gmdate( 'Y-m-d H:i', strtotime( '+30 days 20:30' ) ),
					'_law_tickets_available'    => 10,
					'_law_attendee_price_pence' => 4500,
					'_law_registration_state'   => 'open',
				),
				$meta
			),
			$status
		);
	}

	/** A delegate whose profile carries the name a booking snapshots. */
	private function make_delegate(): int {
		$user_id = $this->make_user();
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Jane', 'last_name' => 'Smith' ) );
		update_user_meta( $user_id, 'organisation', 'Test Chambers' );
		update_user_meta( $user_id, 'job_title', 'Arbitrator' );

		return $user_id;
	}

	/** A discount code, as the catalogue stores one. */
	private function make_code( array $meta = array(), $code = 'LAW25' ): int {
		$post_id = wp_insert_post(
			array( 'post_type' => LAW_DISCOUNT_CPT, 'post_status' => 'publish', 'post_title' => $code )
		);
		$this->posts[] = $post_id;
		foreach ( array_merge( array( '_law_discount_type' => 'percent', '_law_discount_value' => 25 ), $meta ) as $key => $value ) {
			law_event_update_meta( $post_id, $key, $value );
		}
		add_post_meta( $post_id, '_law_discount_used', 0, true );

		return (int) $post_id;
	}

	/** The Stripe responses one payment-mode Checkout session needs. */
	private function queue_checkout_session( $id = 'cs_test' ): void {
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_test', 'object' => 'customer' ),
			array(
				'id'         => $id,
				'object'     => 'checkout.session',
				'url'        => 'https://checkout.stripe.test/' . $id,
				'expires_at' => time() + 1860,
			),
		);
	}

	/* Kind and the guards ____________________________________________________ */

	public function test_a_booking_knows_what_kind_it_is(): void {
		$reception = $this->make_reception();
		$hosted    = $this->make_event( array( '_law_tickets_available' => 5 ), 'publish' );

		$booking = $this->make_booking_id( $hosted, $this->make_user() );
		$this->assertSame( 'hosted', law_booking_kind( $booking ) );

		// A reception refuses the free form, so its booking is made directly.
		$held = law_booking_insert( $reception, $this->make_user(), 'law-pending-payment', array( 'name' => 'A', 'email' => 'a@example.test' ) );
		$this->posts[] = $held;
		$this->assertSame( 'reception', law_booking_kind( $held ) );
	}

	public function test_the_form_guard_refuses_a_priced_or_invitation_only_reception(): void {
		$priced = $this->make_reception();
		$this->assertWPError( law_booking_guard_form_open( $priced ), 'law_booking_priced' );
		$this->assertTrue( law_booking_guard_open( $priced ), 'The event-live test still passes: the waitlist depends on it.' );

		// The committee may still write a complimentary place.
		$this->assertTrue( law_booking_guard_form_open( $priced, array( 'allow_priced' => true ) ) );

		$invited = $this->make_reception( array( '_law_registration_state' => 'invitation' ) );
		$this->assertWPError( law_booking_guard_form_open( $invited ), 'law_booking_invitation_only' );
		$this->assertWPError(
			law_booking_guard_form_open( $invited, array( 'allow_priced' => true ) ),
			'law_booking_invitation_only',
			'Invitation-only is not something allow_priced may waive.'
		);
		$this->assertWPError( law_reception_guard_open( $invited ), 'law_booking_invitation_only' );
	}

	public function test_the_committees_register_an_attendee_writes_a_complimentary_place(): void {
		$event_id = $this->make_reception();
		$actor    = $this->make_committee_user();

		$booking_id = law_booking_register_by_manager(
			$event_id,
			array( 'name' => 'Guest One', 'email' => 'guest-one@example.test', 'organisation' => 'Chambers', 'job_title' => 'Clerk' ),
			$actor
		);
		$this->assertIsInt( $booking_id, is_wp_error( $booking_id ) ? $booking_id->get_error_message() : '' );
		$this->posts[] = $booking_id;
		$guest = get_user_by( 'email', 'guest-one@example.test' );
		if ( $guest ) {
			$this->users[] = (int) $guest->ID;
		}

		$this->assertSame( 'publish', get_post_status( $booking_id ) );
		$this->assertSame( 0, (int) law_event_meta( $booking_id, '_law_price_pence' ) );
		$this->assertSame( 'complimentary', (string) law_event_meta( $booking_id, '_law_payment_status' ) );
		$this->assertSame( 1, (int) law_event_meta( $booking_id, '_law_is_complimentary' ) );
	}

	public function test_a_reception_is_exempt_from_the_clash_guard(): void {
		$when      = gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:30' ) );
		$reception = $this->make_reception( array( '_law_start' => $when ) );
		$other     = $this->make_event(
			array( '_law_start' => $when, '_law_end' => gmdate( 'Y-m-d H:i', strtotime( '+30 days 21:00' ) ), '_law_tickets_available' => 10 ),
			'publish'
		);

		$user_id = $this->make_delegate();
		$this->make_booking( $other, $user_id );

		$this->assertTrue(
			law_booking_guard_clash( $user_id, $reception ),
			'The week is a session and then a drink; the two must not refuse each other.'
		);
		$this->assertTrue(
			law_booking_guard_clash( $user_id, $other ),
			'And the exemption holds from the other side of the pair too.'
		);
	}

	/* Capacity and the hold __________________________________________________ */

	public function test_a_hold_counts_on_a_priced_event_and_not_on_a_free_one(): void {
		$priced = $this->make_reception( array( '_law_tickets_available' => 1 ) );
		$held   = law_booking_insert( $priced, $this->make_user(), 'law-pending-payment', array( 'name' => 'A', 'email' => 'a@example.test' ) );
		$this->posts[] = $held;

		$this->assertSame( 1, law_event_recount_attendees( $priced ) );
		$this->assertSame( 0, law_event_tickets_remaining( $priced ), 'Somebody is on Stripe with the last place.' );

		$free = $this->make_event( array( '_law_tickets_available' => 1 ), 'publish' );
		$ghost = law_booking_insert( $free, $this->make_user(), 'law-pending-payment', array( 'name' => 'B', 'email' => 'b@example.test' ) );
		$this->posts[] = $ghost;
		$this->assertSame( 0, law_event_recount_attendees( $free ), 'A free event cannot hold one, and its count is left literally unchanged.' );
	}

	/* The quote ______________________________________________________________ */

	public function test_the_quote_does_the_arithmetic_and_refuses_a_bad_code(): void {
		$event_id = $this->make_reception();

		$plain = law_reception_quote( $event_id );
		$this->assertSame( 4500, $plain['net'] );
		$this->assertSame( 900, $plain['vat'] );
		$this->assertSame( 5400, $plain['gross'] );
		$this->assertFalse( $plain['free'] );

		$this->make_code();
		$percent = law_reception_quote( $event_id, 'law25' );
		$this->assertSame( 1125, $percent['discount'] );
		$this->assertSame( 3375, $percent['net'] );
		$this->assertSame( 4050, $percent['gross'] );

		$this->make_code( array( '_law_discount_type' => 'fixed', '_law_discount_value' => 1000 ), 'TENOFF' );
		$fixed = law_reception_quote( $event_id, 'TENOFF' );
		$this->assertSame( 1000, $fixed['discount'] );
		$this->assertSame( 3500, $fixed['net'] );

		$this->make_code( array( '_law_discount_value' => 100 ), 'FREE100' );
		$whole = law_reception_quote( $event_id, 'FREE100' );
		$this->assertTrue( $whole['free'] );
		$this->assertSame( 0, $whole['gross'] );
		$this->assertSame( 0, $whole['vat'], 'Nothing to pay is nothing to add VAT to.' );

		$other = $this->make_reception();
		$this->make_code( array( '_law_discount_events' => array( $other ) ), 'ELSEWHERE' );
		$this->assertWPError( law_reception_quote( $event_id, 'ELSEWHERE' ), 'law_discount_wrong_event' );
		$this->assertWPError( law_reception_quote( $event_id, 'NOSUCHCODE' ), 'law_discount_unknown' );
	}

	/* Checkout _______________________________________________________________ */

	public function test_checkout_holds_a_place_claims_the_code_and_opens_a_session(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();
		$code     = $this->make_code();
		$this->queue_checkout_session();

		$result = law_reception_checkout(
			$user_id,
			array( 'event_id' => $event_id, 'code' => 'LAW25', 'applied_code' => 'LAW25', 'terms' => 1, 'price_shown' => 4050, 'ajax' => true )
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->posts[] = $result['booking'];

		$booking = get_post( $result['booking'] );
		$this->assertSame( 'law-pending-payment', $booking->post_status );
		$this->assertSame( 3375, (int) law_event_meta( $booking->ID, '_law_price_pence' ), 'The DISCOUNTED net is the snapshot Stripe bills from.' );
		$this->assertSame( 1125, (int) law_event_meta( $booking->ID, '_law_discount_pence' ) );
		$this->assertSame( $code, (int) law_event_meta( $booking->ID, '_law_discount_id' ) );
		$this->assertSame( 1, (int) get_post_meta( $code, '_law_discount_used', true ), 'The use is claimed before the booking exists.' );
		$this->assertSame( 'https://checkout.stripe.test/cs_test', $result['redirect'] );
		$this->assertSame( 'cs_test', (string) law_event_meta( $booking->ID, '_law_stripe_checkout_session_id' ) );
		$this->assertSame( 1, law_event_attendee_total( $event_id ), 'The hold takes a place.' );
	}

	public function test_a_typed_but_unapplied_code_asks_for_the_press_rather_than_repricing(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();
		$this->make_code();

		$result = law_reception_checkout(
			$user_id,
			array( 'event_id' => $event_id, 'code' => 'LAW25', 'applied_code' => '', 'terms' => 1, 'price_shown' => 5400, 'ajax' => true )
		);
		$this->assertWPError( $result, 'law_reception_code_unapplied' );
	}

	public function test_a_price_that_moved_is_refused_not_repriced(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();

		$result = law_reception_checkout(
			$user_id,
			array( 'event_id' => $event_id, 'terms' => 1, 'price_shown' => 3000, 'ajax' => true )
		);
		$this->assertWPError( $result, 'law_reception_price_changed' );
		$this->assertSame( 0, law_event_attendee_total( $event_id ), 'And nothing is held.' );
	}

	public function test_a_hundred_per_cent_code_confirms_with_no_stripe_call(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();
		$this->make_code( array( '_law_discount_value' => 100 ), 'FREE100' );
		// No queue at all: an unmocked Stripe call fails loudly, which is the
		// assertion.
		$GLOBALS['law_test_stripe_queue'] = array();

		$result = law_reception_checkout(
			$user_id,
			array( 'event_id' => $event_id, 'code' => 'FREE100', 'applied_code' => 'FREE100', 'terms' => 1, 'price_shown' => 0, 'ajax' => true )
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->posts[] = $result['booking'];

		$this->assertSame( 'publish', get_post_status( $result['booking'] ) );
		$this->assertSame( 'paid', (string) law_event_meta( $result['booking'], '_law_payment_status' ) );
		$this->assertStringContainsString( 'reception-free-confirmed', $result['redirect'] );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Nobody is sent to a payment page for nothing.' );
	}

	public function test_a_stripe_failure_at_creation_never_holds_a_place(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();
		$code     = $this->make_code();
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_test', 'object' => 'customer' ),
			new WP_Error( 'law_stripe_api_error', 'Stripe is having a bad day.' ),
		);

		$result = law_reception_checkout(
			$user_id,
			array( 'event_id' => $event_id, 'code' => 'LAW25', 'applied_code' => 'LAW25', 'terms' => 1, 'price_shown' => 4050, 'ajax' => true )
		);
		$this->assertWPError( $result, 'law_stripe_api_error' );
		$this->assertSame( 0, law_event_attendee_total( $event_id ), 'The hold is given back.' );
		$this->assertSame( 0, (int) get_post_meta( $code, '_law_discount_used', true ), 'And so is the code.' );
	}

	/* Releasing a hold _______________________________________________________ */

	public function test_releasing_a_hold_gives_the_place_and_the_code_back_once(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();
		$code     = $this->make_code();
		$this->queue_checkout_session();

		$result = law_reception_checkout(
			$user_id,
			array( 'event_id' => $event_id, 'code' => 'LAW25', 'applied_code' => 'LAW25', 'terms' => 1, 'price_shown' => 4050, 'ajax' => true )
		);
		$this->posts[] = $result['booking'];

		// The expire call, answering "expired".
		$GLOBALS['law_test_stripe_queue'] = array( array( 'id' => 'cs_test', 'object' => 'checkout.session', 'status' => 'expired' ) );
		$this->assertTrue( law_reception_release_hold( $result['booking'], 'expired' ) );

		$this->assertSame( 'law-cancelled', get_post_status( $result['booking'] ) );
		$this->assertSame( 0, law_event_attendee_total( $event_id ) );
		$this->assertSame( 0, (int) get_post_meta( $code, '_law_discount_used', true ) );

		// A second release is a no-op and must not take somebody else's use.
		law_discount_claim( $code );
		$this->assertFalse( law_reception_release_hold( $result['booking'], 'sweep' ) );
		$this->assertSame( 1, (int) get_post_meta( $code, '_law_discount_used', true ), "A double release must never take another buyer's claim." );
	}

	public function test_releasing_a_hold_detects_a_payment_that_landed_in_the_race(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();
		$this->queue_checkout_session();
		$result        = law_reception_checkout( $user_id, array( 'event_id' => $event_id, 'terms' => 1, 'price_shown' => 5400, 'ajax' => true ) );
		$this->posts[] = $result['booking'];

		// Stripe refuses to expire a complete session; the GET that follows
		// says what really happened.
		$GLOBALS['law_test_stripe_queue'] = array(
			new WP_Error( 'law_stripe_api_error', 'You cannot expire a completed session.' ),
			array(
				'id'             => 'cs_test',
				'object'         => 'checkout.session',
				'status'         => 'complete',
				'payment_status' => 'paid',
				'amount_total'   => 5400,
			),
		);

		$this->assertFalse( law_reception_release_hold( $result['booking'], 'cancelled' ) );
		$this->assertSame( 'publish', get_post_status( $result['booking'] ), 'The money arrived: confirm, never cancel.' );
		$this->assertSame( 'paid', (string) law_event_meta( $result['booking'], '_law_payment_status' ) );
	}

	public function test_a_stale_session_id_is_ignored(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();
		$this->queue_checkout_session( 'cs_live' );
		$result        = law_reception_checkout( $user_id, array( 'event_id' => $event_id, 'terms' => 1, 'price_shown' => 5400, 'ajax' => true ) );
		$this->posts[] = $result['booking'];

		// A LATE expiry for a session this booking has already replaced.
		$stale = array( 'id' => 'cs_old', 'object' => 'checkout.session', 'status' => 'expired' );
		$this->assertFalse( law_reception_session_matches( $result['booking'], $stale ) );
		$this->assertFalse(
			law_booking_dispatch_payment( $result['booking'], 'session_expired', $stale ),
			'A superseded session must never release a live hold.'
		);
		$this->assertSame( 'law-pending-payment', get_post_status( $result['booking'] ) );
	}

	/* Confirming _____________________________________________________________ */

	public function test_mark_paid_is_idempotent_across_session_and_invoice_in_either_order(): void {
		$event_id = $this->make_reception();
		$user_id  = $this->make_delegate();
		$this->queue_checkout_session();
		$result        = law_reception_checkout( $user_id, array( 'event_id' => $event_id, 'terms' => 1, 'price_shown' => 5400, 'ajax' => true ) );
		$booking_id    = (int) $result['booking'];
		$this->posts[] = $booking_id;

		// The session lands first, with no invoice: the place is confirmed but
		// the confirmation waits for the receipt.
		law_reception_mark_paid(
			$booking_id,
			array( 'id' => 'cs_test', 'object' => 'checkout.session', 'payment_status' => 'paid', 'amount_total' => 5400 )
		);
		$this->assertSame( 'publish', get_post_status( $booking_id ) );
		$this->assertSame( '', (string) get_post_meta( $booking_id, '_law_confirmation_sent', true ), 'No receipt yet, so no email yet.' );

		// Then the invoice.
		law_reception_mark_paid(
			$booking_id,
			array(
				'id'                 => 'in_test',
				'object'             => 'invoice',
				'amount_paid'        => 5400,
				'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
				'invoice_pdf'        => 'https://invoice.stripe.test/in_test.pdf',
			)
		);
		$this->assertSame( 'https://invoice.stripe.test/in_test', (string) law_event_meta( $booking_id, '_law_stripe_invoice_url' ) );
		$this->assertSame( '1', (string) get_post_meta( $booking_id, '_law_confirmation_sent', true ) );

		// And a third delivery changes nothing and sends nothing.
		$this->assertTrue( law_reception_mark_paid( $booking_id, array( 'id' => 'cs_test', 'object' => 'checkout.session', 'amount_total' => 5400 ) ) );
		$this->assertFalse( law_reception_maybe_send_confirmation( $booking_id ), 'The latch is one-shot.' );
		$this->assertSame( 1, law_event_attendee_total( $event_id ), 'One person, one place, however many deliveries.' );
	}

	public function test_a_payment_on_a_cancelled_booking_never_seats_anybody(): void {
		$event_id      = $this->make_reception();
		$booking_id    = law_booking_insert(
			$event_id,
			$this->make_delegate(),
			'law-cancelled',
			array( 'name' => 'Jane Smith', 'email' => 'jane@example.test' ),
			array( '_law_price_pence' => 4500, '_law_vat' => 1 )
		);
		$this->posts[] = $booking_id;

		$this->assertFalse(
			law_reception_mark_paid( $booking_id, array( 'id' => 'in_x', 'object' => 'invoice', 'amount_paid' => 5400 ) )
		);
		$this->assertSame( 'law-cancelled', get_post_status( $booking_id ) );
		$this->assertSame( 0, law_event_attendee_total( $event_id ) );
	}

	public function test_a_wrong_kind_of_booking_is_refused_by_every_handler(): void {
		$hosted  = $this->make_event( array( '_law_tickets_available' => 5 ), 'publish' );
		$booking = $this->make_booking_id( $hosted, $this->make_user() );

		$this->assertFalse( law_reception_mark_paid( $booking, array( 'object' => 'invoice', 'amount_paid' => 100 ) ) );
		$this->assertFalse( law_reception_mark_processing( $booking ) );
		$this->assertFalse( law_reception_mark_payment_failed( $booking, 'nope' ) );
		$this->assertSame( 'publish', get_post_status( $booking ), 'A free hosted booking is untouched by the reception handlers.' );
	}

	/* Cancelling _____________________________________________________________ */

	public function test_a_paid_place_cannot_be_cancelled_by_its_owner(): void {
		$event_id      = $this->make_reception();
		$user_id       = $this->make_delegate();
		$code          = $this->make_code();
		$booking_id    = law_booking_insert(
			$event_id,
			$user_id,
			'publish',
			array( 'name' => 'Jane Smith', 'email' => 'jane@example.test' ),
			array( '_law_price_pence' => 3375, '_law_vat' => 1, '_law_payment_status' => 'paid', '_law_discount_id' => $code )
		);
		$this->posts[] = $booking_id;
		law_discount_claim( $code );

		$this->assertWPError( law_booking_cancel( $booking_id, $user_id, 'self' ), 'law_booking_paid_place' );
		$this->assertWPError( law_booking_cancel( $booking_id, $user_id, 'booker' ), 'law_booking_paid_place' );
		$this->assertSame( 'publish', get_post_status( $booking_id ) );

		// The committee can, and the code's use stays spent: the refund is a
		// separate, manual decision.
		$this->assertTrue( law_booking_cancel( $booking_id, $this->make_committee_user(), 'host_reject' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $booking_id ) );
		$this->assertSame( 1, (int) get_post_meta( $code, '_law_discount_used', true ) );
	}

	public function test_an_included_place_can_be_cancelled_and_frees_the_place(): void {
		$event_id      = $this->make_reception();
		$user_id       = $this->make_delegate();
		$booking_id    = law_booking_insert(
			$event_id,
			$user_id,
			'publish',
			array( 'name' => 'Jane Smith', 'email' => 'jane@example.test' ),
			array( '_law_price_pence' => 0, '_law_payment_status' => 'included' )
		);
		$this->posts[] = $booking_id;
		law_event_recount_attendees( $event_id );

		$this->assertTrue( law_booking_cancel( $booking_id, $user_id, 'self' ) );
		$this->assertSame( 0, law_event_attendee_total( $event_id ) );
	}
}
