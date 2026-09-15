<?php
/**
 * The paid waitlist (RECEPTIONS.md §6).
 *
 * The free queue promotes and emails; a priced one promotes, CHARGES, and only
 * then emails, with the place and the exclusive right to bill it taken
 * together under the lock and the card run after it. What these pin is every
 * way that can go wrong: an entry with no payment method being offered a place
 * it cannot pay for, a decline stranding the place, a crash between the unlock
 * and the charge, and the whole thing recursing on a queue of dead cards.
 */
class ReceptionWaitlistTest extends LAW_Test_Case {

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
					'_law_tickets_available'    => 1,
					'_law_attendee_price_pence' => 4500,
					'_law_registration_state'   => 'open',
				),
				$meta
			),
			'publish'
		);
	}

	private function make_delegate(): int {
		$user_id = $this->make_user();
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Jane', 'last_name' => 'Smith' . $user_id ) );

		return $user_id;
	}

	/** Fill the reception, so the waitlist is the only way in. */
	private function fill( $event_id ): int {
		$user_id    = $this->make_delegate();
		$booking_id = law_booking_insert(
			$event_id,
			$user_id,
			'publish',
			array( 'user_id' => $user_id, 'name' => 'Taken', 'email' => 'taken-' . $user_id . '@example.test' ),
			array( '_law_price_pence' => 4500, '_law_vat' => 1, '_law_payment_status' => 'paid' )
		);
		$this->posts[] = $booking_id;
		law_event_recount_attendees( $event_id );

		return (int) $booking_id;
	}

	/** Join the queue, with the Stripe setup session mocked. */
	private function join( $event_id, $user_id = 0 ) {
		$user_id = $user_id ?: $this->make_delegate();
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_test', 'object' => 'customer' ),
			array( 'id' => 'cs_setup', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_setup' ),
		);
		$result = law_reception_waitlist_join(
			$user_id,
			array( 'event_id' => $event_id, 'terms' => 1, 'consent' => 1, 'price_shown' => 5400, 'ajax' => true )
		);
		if ( ! is_wp_error( $result ) ) {
			$this->posts[] = $result['booking'];
		}

		return $result;
	}

	/** Mark a queue entry as having a saved payment method. */
	private function make_ready( $booking_id ): void {
		law_event_update_meta( $booking_id, '_law_stripe_payment_method_id', 'pm_test' );
		law_event_update_meta( $booking_id, '_law_payment_status', 'ready' );
	}

	/**
	 * The Stripe responses one off-session invoice charge needs.
	 *
	 * No customer call: joining the queue already created and cached one on
	 * the account, which is the point of law_stripe_user_customer_id().
	 */
	private function queue_charge( $status = 'paid' ): void {
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'object' => 'invoice', 'status' => 'open' ),
			array(
				'id'                 => 'in_test',
				'object'             => 'invoice',
				'status'             => $status,
				'amount_paid'        => 'paid' === $status ? 5400 : 0,
				'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
			),
		);
	}

	/** A code, tracked for teardown. */
	private function make_code( array $meta = array() ): array {
		$code    = 'WLTEST' . strtoupper( wp_generate_password( 6, false ) );
		$post_id = wp_insert_post(
			array(
				'post_type'   => LAW_DISCOUNT_CPT,
				'post_status' => 'publish',
				'post_title'  => $code,
				'post_name'   => law_discount_match_key( $code ),
			)
		);
		$this->posts[] = $post_id;
		foreach ( array_merge( array( '_law_discount_type' => 'percent', '_law_discount_value' => 100 ), $meta ) as $key => $value ) {
			law_event_update_meta( $post_id, $key, $value );
		}
		add_post_meta( $post_id, '_law_discount_used', 0, true );

		return array( 'id' => (int) $post_id, 'code' => $code );
	}

	/* Nothing to pay means no payment step (Denis, 15 September 2026) _______ */

	/**
	 * A 100% code joins the queue with no Stripe call and no payment method.
	 *
	 * The empty Stripe queue IS the assertion: an unmocked call fails loudly
	 * (tests/bootstrap.php), so opening a setup session would fail this.
	 */
	public function test_a_fully_discounted_join_never_goes_to_stripe(): void {
		$event_id = $this->make_reception();
		$this->fill( $event_id );
		$code = $this->make_code();

		$GLOBALS['law_test_stripe_queue'] = array();
		$GLOBALS['law_test_stripe_calls'] = array();

		$result = law_reception_waitlist_join(
			$this->make_delegate(),
			array( 'event_id' => $event_id, 'terms' => 1, 'consent' => 1, 'code' => $code['code'], 'applied_code' => $code['code'], 'price_shown' => 0, 'ajax' => true )
		);
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$entry         = (int) $result['booking'];
		$this->posts[] = $entry;

		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Nothing to charge means nothing to ask Stripe.' );
		$this->assertTrue( ! empty( $result['free'] ), 'The handler needs to know, so it does not promise a payment page.' );
		$this->assertSame( 'law-waitlisted', get_post_status( $entry ) );
		$this->assertSame( 'no_charge', (string) law_event_meta( $entry, '_law_payment_status' ) );
		$this->assertSame( '', (string) law_event_meta( $entry, '_law_stripe_payment_method_id' ) );
		$this->assertSame( 1, (int) get_post_meta( $code['id'], '_law_discount_used', true ) );
		// The entry is honourable straight away: the "ready" step ran without
		// a card, so it is not sitting in the queue in silence.
		$this->assertSame( '1', (string) get_post_meta( $entry, '_law_waitlist_ready', true ) );
	}

	/**
	 * And it is promotable, and is promoted without a charge.
	 *
	 * law_waitlist_check_promotable() used to demand 'ready' on any priced
	 * event, which would have queued this person for ever behind a payment
	 * method nobody was ever going to ask them for.
	 */
	public function test_a_fully_discounted_entry_is_promoted_with_no_charge(): void {
		$event_id = $this->make_reception();
		$seated   = $this->fill( $event_id );
		$code     = $this->make_code();

		$GLOBALS['law_test_stripe_queue'] = array();
		$result = law_reception_waitlist_join(
			$this->make_delegate(),
			array( 'event_id' => $event_id, 'terms' => 1, 'consent' => 1, 'code' => $code['code'], 'applied_code' => $code['code'], 'price_shown' => 0, 'ajax' => true )
		);
		$entry         = (int) $result['booking'];
		$this->posts[] = $entry;

		// A place frees up. No Stripe responses queued at all.
		$GLOBALS['law_test_stripe_queue'] = array();
		$GLOBALS['law_test_stripe_calls'] = array();
		$mail   = array();
		$filter = static function ( $atts ) use ( &$mail ) {
			$mail[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $filter );

		law_booking_cancel( $seated, 0, 'host_reject' );

		remove_filter( 'wp_mail', $filter );

		$this->assertSame( 'publish', get_post_status( $entry ), 'The place is theirs.' );
		$this->assertSame( 'paid', (string) law_event_meta( $entry, '_law_payment_status' ), 'Paid, for nothing.' );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'No invoice is raised for £0.00.' );
		$this->assertSame( '', (string) get_post_meta( $entry, '_law_charge_claim', true ), 'No charge was ever claimed for it.' );
		$this->assertSame( 1, (int) get_post_meta( $code['id'], '_law_discount_used', true ), 'A place that was taken keeps the use.' );

		// ONE confirmation, not two. law_reception_mark_paid() would otherwise
		// send the generic one as well as the promotion's own.
		$to = array_merge( ...array_map( static fn( $atts ) => (array) ( $atts['to'] ?? array() ), $mail ) );
		$delegate = (string) law_booking_attendee( $entry )['email'];
		$this->assertSame(
			1,
			count( array_filter( $to, static fn( $address ) => $address === $delegate ) ),
			'One place, one confirmation, one calendar invitation.'
		);
	}

	public function test_joining_claims_the_code_and_opens_a_setup_session(): void {
		$event_id = $this->make_reception();
		$this->fill( $event_id );

		$result = $this->join( $event_id );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );

		$booking = get_post( $result['booking'] );
		$this->assertSame( 'law-waitlisted', $booking->post_status );
		$this->assertSame( 'pending_setup', (string) law_event_meta( $booking->ID, '_law_payment_status' ) );
		$this->assertSame( 4500, (int) law_event_meta( $booking->ID, '_law_price_pence' ), 'The price is snapshotted at JOIN: that is the figure consented to.' );
		$this->assertSame( 1, (int) law_event_meta( $booking->ID, '_law_waitlist_position' ) );
		$this->assertSame( 'https://checkout.stripe.test/cs_setup', $result['redirect'] );
	}

	public function test_you_cannot_join_a_queue_on_a_reception_that_has_places(): void {
		$event_id = $this->make_reception( array( '_law_tickets_available' => 10 ) );

		$this->assertWPError( $this->join( $event_id ), 'law_waitlist_places_available' );
	}

	public function test_an_entry_with_no_payment_method_is_skipped_in_place_and_told_once(): void {
		$event_id = $this->make_reception();
		$seated   = $this->fill( $event_id );
		$entry    = $this->join( $event_id )['booking'];
		$second   = $this->join( $event_id )['booking'];
		$this->make_ready( $second );

		// A place frees. The entry behind is ready, so its card is charged.
		$this->queue_charge( 'paid' );
		law_booking_cancel( $seated, 0, 'host_reject' );

		$this->assertSame( 'law-waitlisted', get_post_status( $entry ), 'No method saved: skipped, never promoted.' );
		$this->assertSame( 'law_waitlist_no_payment_method', (string) law_event_meta( $entry, '_law_waitlist_blocked' ) );
		$this->assertSame( 1, (int) law_event_meta( $entry, '_law_waitlist_position' ), 'And it keeps its place in the queue.' );

		// The one behind it, which IS ready, was offered the place instead.
		$this->assertSame( 'publish', get_post_status( $second ) );
	}

	public function test_a_ready_entry_is_seated_as_processing_and_charged_after_the_unlock(): void {
		$event_id = $this->make_reception();
		$seated   = $this->fill( $event_id );
		$entry    = $this->join( $event_id )['booking'];
		$this->make_ready( $entry );

		$mail   = array();
		$filter = static function ( $atts ) use ( &$mail ) {
			$mail[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $filter );

		$this->queue_charge( 'paid' );
		law_booking_cancel( $seated, 0, 'host_reject' );

		remove_filter( 'wp_mail', $filter );

		$this->assertSame( 'publish', get_post_status( $entry ) );
		$this->assertSame( 'paid', (string) law_event_meta( $entry, '_law_payment_status' ) );
		$this->assertSame( 'https://invoice.stripe.test/in_test', (string) law_event_meta( $entry, '_law_stripe_invoice_url' ) );
		$this->assertSame( '', (string) get_post_meta( $entry, '_law_charge_claim', true ), 'The claim is released whatever happened.' );

		// ONE confirmation. law_reception_mark_paid() ends by sending the
		// generic user_reception_confirmed, and the promotion sends its own on
		// top, so a promoted delegate was getting two confirmations and two
		// calendar invitations for one place (found 15 September 2026).
		$to       = array_merge( ...array_map( static fn( $atts ) => (array) ( $atts['to'] ?? array() ), $mail ) );
		$delegate = (string) law_booking_attendee( $entry )['email'];
		$this->assertSame(
			1,
			count( array_filter( $to, static fn( $address ) => $address === $delegate ) ),
			'One place, one confirmation, one calendar invitation.'
		);
	}

	public function test_a_decline_frees_the_place_and_schedules_a_resume_rather_than_recursing(): void {
		$event_id = $this->make_reception();
		$seated   = $this->fill( $event_id );
		$entry    = $this->join( $event_id )['booking'];
		$this->make_ready( $entry );

		// The pay step is refused: a declined card, not a mistake of ours.
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'object' => 'invoice', 'status' => 'open' ),
			new WP_Error( 'law_stripe_api_error', 'Your card was declined.', array( 'stripe' => array( 'type' => 'card_error' ) ) ),
		);
		law_booking_cancel( $seated, 0, 'host_reject' );

		$this->assertSame( 'law-payment-failed', get_post_status( $entry ), 'The place goes to the next person.' );
		$this->assertSame( 'failed', (string) law_event_meta( $entry, '_law_payment_status' ) );
		$this->assertNotSame( '', (string) law_event_meta( $entry, '_law_payment_failed_at' ), 'And the window to fix it starts.' );
		$this->assertSame( 0, law_event_attendee_total( $event_id ), 'The place is free again.' );
		$this->assertNotFalse(
			wp_next_scheduled( 'law_waitlist_resume', array( $event_id ) ),
			'Scheduled, never recursive: a queue of dead cards would nest without bound.'
		);
		wp_clear_scheduled_hook( 'law_waitlist_resume', array( $event_id ) );
	}

	public function test_our_own_configuration_error_never_blames_the_delegate(): void {
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'tax_rate_id' => '' ) );
		$event_id = $this->make_reception();
		$seated   = $this->fill( $event_id );
		$entry    = $this->join( $event_id )['booking'];
		$this->make_ready( $entry );

		law_booking_cancel( $seated, 0, 'host_reject' );

		$this->assertSame( 'law-waitlisted', get_post_status( $entry ) );
		$this->assertSame( 'ready', (string) law_event_meta( $entry, '_law_payment_status' ) );
		$this->assertSame( 1, (int) law_event_meta( $entry, '_law_waitlist_position' ), 'Back at the FRONT of the queue.' );
		$this->assertSame( '', (string) law_event_meta( $entry, '_law_payment_failed_at' ), 'And nobody is told their card was declined.' );
	}

	public function test_a_settling_payment_holds_the_place(): void {
		$event_id = $this->make_reception();
		$seated   = $this->fill( $event_id );
		$entry    = $this->join( $event_id )['booking'];
		$this->make_ready( $entry );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'object' => 'invoice', 'status' => 'open' ),
			array(
				'id'      => 'in_test',
				'object'  => 'invoice',
				'status'  => 'open',
				'payments' => array( 'data' => array( array( 'payment' => array( 'payment_intent' => array( 'status' => 'processing' ) ) ) ) ),
			),
		);
		law_booking_cancel( $seated, 0, 'host_reject' );

		$this->assertSame( 'publish', get_post_status( $entry ) );
		$this->assertSame( 'processing', (string) law_event_meta( $entry, '_law_payment_status' ) );
	}

	/* The sweep ______________________________________________________________ */

	public function test_the_sweep_closes_an_entry_that_never_saved_a_payment_method(): void {
		$event_id = $this->make_reception();
		$this->fill( $event_id );
		$entry = $this->join( $event_id )['booking'];

		// Still pending, but not yet old enough.
		$this->assertSame( 0, law_reception_sweep()['no_card'] );

		law_event_update_meta(
			$entry,
			'_law_waitlist_joined',
			gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( LAW_FLAGSHIP_SETUP_GRACE_HOURS + 1 ) . ' hours' ) )
		);
		$this->assertSame( 1, law_reception_sweep()['no_card'] );
		$this->assertSame( 'law-cancelled', get_post_status( $entry ) );
	}

	public function test_the_sweep_releases_a_hold_past_its_expiry_but_never_one_in_flight(): void {
		$event_id = $this->make_reception( array( '_law_tickets_available' => 5 ) );
		$user_id  = $this->make_delegate();

		$hold = law_booking_insert(
			$event_id,
			$user_id,
			'law-pending-payment',
			array( 'user_id' => $user_id, 'name' => 'Jane', 'email' => 'jane-' . $user_id . '@example.test' ),
			array(
				'_law_price_pence'         => 4500,
				'_law_vat'                 => 1,
				'_law_payment_status'      => 'pending_setup',
				'_law_checkout_expires_at' => gmdate( 'Y-m-d H:i', strtotime( '-1 hour' ) ),
			)
		);
		$this->posts[] = $hold;
		law_event_update_meta( $hold, '_law_stripe_checkout_session_id', 'cs_old' );
		law_event_recount_attendees( $event_id );

		// Money in flight: skipped however old the session is.
		law_event_update_meta( $hold, '_law_payment_status', 'processing' );
		$this->assertSame( 0, law_reception_sweep()['released'] );
		$this->assertSame( 'law-pending-payment', get_post_status( $hold ) );

		law_event_update_meta( $hold, '_law_payment_status', 'pending_setup' );
		$GLOBALS['law_test_stripe_queue'] = array( array( 'id' => 'cs_old', 'object' => 'checkout.session', 'status' => 'expired' ) );
		$this->assertSame( 1, law_reception_sweep()['released'] );
		$this->assertSame( 'law-cancelled', get_post_status( $hold ) );
	}

	public function test_the_sweep_sends_a_confirmation_whose_invoice_never_came(): void {
		$event_id = $this->make_reception( array( '_law_tickets_available' => 5 ) );
		$user_id  = $this->make_delegate();

		$booking = law_booking_insert(
			$event_id,
			$user_id,
			'publish',
			array( 'user_id' => $user_id, 'name' => 'Jane', 'email' => 'jane-' . $user_id . '@example.test' ),
			array( '_law_price_pence' => 4500, '_law_vat' => 1, '_law_payment_status' => 'paid', '_law_paid_at' => gmdate( 'Y-m-d H:i' ) )
		);
		$this->posts[] = $booking;

		// Asserted on THIS booking's latch, not on the sweep's counter: the
		// sweep walks every reception on the site, so a counter assertion
		// passes or fails on whatever else happens to be waiting — which on a
		// live install is real bookings.
		//
		// Fresh: the sweep waits, in case invoice.paid is a second away.
		law_reception_sweep();
		$this->assertSame( '', (string) get_post_meta( $booking, '_law_confirmation_sent', true ) );

		law_event_update_meta( $booking, '_law_paid_at', gmdate( 'Y-m-d H:i', strtotime( '-1 hour' ) ) );
		law_reception_sweep();
		$this->assertSame(
			'1',
			(string) get_post_meta( $booking, '_law_confirmation_sent', true ),
			'A confirmation with no receipt link beats no confirmation at all.'
		);
	}

	public function test_withdrawing_from_the_queue_releases_the_code(): void {
		$event_id = $this->make_reception();
		$this->fill( $event_id );
		$entry = $this->join( $event_id )['booking'];

		// Unique per run: law_discount_find() looks a code up by slug, and a
		// fixture whose title collides with a real code in the catalogue gets
		// "-2" appended and becomes unfindable.
		$code = wp_insert_post(
			array(
				'post_type'   => LAW_DISCOUNT_CPT,
				'post_status' => 'publish',
				'post_title'  => 'QUEUE' . strtoupper( wp_generate_password( 6, false ) ),
			)
		);
		$this->posts[] = $code;
		add_post_meta( $code, '_law_discount_used', 1, true );
		law_event_update_meta( $entry, '_law_discount_id', $code );

		$this->assertTrue( law_booking_cancel( $entry, (int) get_post_field( 'post_author', $entry ), 'self' ) );
		$this->assertSame( 0, (int) get_post_meta( $code, '_law_discount_used', true ) );
		$this->assertSame( '', (string) get_post_meta( $entry, '_law_discount_id', true ), 'The key is deleted, so a second release is a no-op.' );
	}

	public function test_the_free_hosted_waitlist_is_unchanged(): void {
		$event_id = $this->make_event( array( '_law_tickets_available' => 1 ), 'publish' );
		$first    = $this->make_booking_id( $event_id, $this->make_user() );
		$waiting  = $this->make_waitlist( $event_id, $this->make_user() );
		$this->assertIsArray( $waiting );

		law_booking_cancel( $first, 0, 'host_reject' );

		$this->assertSame( 'publish', get_post_status( $waiting[0] ), 'A free queue still promotes with no payment method in sight.' );
	}
}
