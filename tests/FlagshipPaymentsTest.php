<?php
/**
 * The flagship conference's application and payment flow
 * (FLAGSHIP_PAYMENTS.md).
 *
 * What these pin is the machinery that is expensive to get quietly wrong,
 * because every one of them is about money or about somebody's place:
 *
 * - the price boundary, on both sides of the cutover AND across the BST/GMT
 *   edge, which is the whole reason the comparison goes through wp_timezone();
 * - the snapshot surviving an approval made after the price has risen;
 * - applications still being accepted when the conference is full;
 * - over-booking needing to be asked for, not stumbled into;
 * - a declined card landing on law-payment-failed and never on publish;
 * - law_flagship_mark_paid() being idempotent, since the synchronous charge
 *   and the invoice.paid webhook both call it and either may arrive first.
 *
 * Stripe is mocked through the law_stripe_request_mock seam in
 * stripe/client.php; an unmocked call fails loudly (tests/bootstrap.php).
 */
class FlagshipPaymentsTest extends LAW_Test_Case {

	private int $flagship = 0;

	protected function tearDown(): void {
		remove_all_filters( 'law_flagship_event_id' );
		remove_all_filters( 'pre_option_law_events_source' );
		law_flagship_event_id( true );
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * A published flagship on sale, pinned as "the" flagship, with the CPT
	 * source forced (law_flagship_guard_open() refuses on the legacy source).
	 */
	private function make_flagship( array $meta = array() ): int {
		$event_id = $this->make_event(
			array_merge(
				array(
					'_law_is_flagship'                => 1,
					'_law_flagship_date'              => '2026-12-02',
					'_law_start'                      => '2026-12-02 09:30',
					'_law_end'                        => '2026-12-02 17:00',
					'_law_flagship_price_pence'       => 55000,
					'_law_flagship_price_late_pence'  => 60000,
					'_law_flagship_price_switch'      => '2026-10-17 00:00',
					'_law_tickets_available'          => 2,
				),
				$meta
			),
			'publish'
		);
		$this->flagship = $event_id;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		law_flagship_event_id( true );

		return $event_id;
	}

	/** A signed-up delegate with a filled-in profile. */
	private function make_delegate(): int {
		$user_id = $this->make_user( 'attendee' );
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Jane', 'last_name' => 'Smith' ) );
		update_user_meta( $user_id, 'organisation', 'Test Chambers' );
		update_user_meta( $user_id, 'job_title', 'Arbitrator' );
		update_user_meta( $user_id, 'country', 'United Kingdom' );

		return $user_id;
	}

	/** The Stripe responses an application's Checkout setup session needs. */
	private function queue_setup_session(): void {
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_test', 'object' => 'customer' ),
			array( 'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_test' ),
		);
	}

	/** Apply as this delegate, with the Stripe leg mocked. */
	private function apply_as( int $user_id, array $input = array() ) {
		$this->queue_setup_session();
		$result = law_flagship_apply(
			$user_id,
			array_merge( array( 'answers' => array(), 'consent' => true, 'terms' => true ), $input )
		);
		if ( ! is_wp_error( $result ) ) {
			$this->posts[] = (int) $result['booking'];
		}

		return $result;
	}

	/**
	 * Put a saved card on an application without going near Stripe.
	 *
	 * The customer goes on the USER, which is where law_stripe_user_customer_id()
	 * looks, so the charge below needs no customer round trip and the queue
	 * stays a faithful list of the calls that flow actually makes.
	 */
	private function give_card( int $booking_id ): void {
		$user_id = (int) get_post_field( 'post_author', $booking_id );
		update_user_meta( $user_id, '_law_stripe_customer_id', 'cus_test' );
		law_event_update_meta( $booking_id, '_law_stripe_payment_method_id', 'pm_test' );
		law_event_update_meta( $booking_id, '_law_stripe_customer_id', 'cus_test' );
		law_event_update_meta( $booking_id, '_law_payment_status', 'ready' );
		update_post_meta( $booking_id, '_law_application_ready', 1 );
	}

	/**
	 * The Stripe responses a successful off-session charge needs, in the order
	 * law_stripe_charge_booking() makes them: the invoice is created BEFORE
	 * its line item, because its ID is persisted first so a failure resumes
	 * the same invoice instead of billing twice.
	 */
	private function queue_successful_charge( int $amount ): void {
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),            // create
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),        // line item
			array( 'id' => 'in_test', 'status' => 'open' ),               // finalise
			array(
				'id'                 => 'in_test',
				'status'             => 'paid',
				'amount_paid'        => $amount,
				'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
				'invoice_pdf'        => 'https://invoice.stripe.test/in_test.pdf',
			),
			array( 'id' => 'in_test', 'payments' => array( 'data' => array() ) ), // charge lookup
		);
	}

	/* The price, and the cutover ____________________________________________ */

	/**
	 * The cutover is 00:00 in LONDON, not in UTC. In October London is on BST,
	 * so the two are an hour apart, and getting this wrong would sell the
	 * cheaper place for an hour into the 17th (Denis, 10 September 2026).
	 */
	public function test_the_price_switches_at_midnight_london_not_utc(): void {
		$event_id = $this->make_flagship();
		$at       = fn( string $local ) => ( new DateTimeImmutable( $local, wp_timezone() ) )->getTimestamp();

		$this->assertSame( 55000, law_flagship_price_pence( $at( '2026-10-16 23:59' ), $event_id ), 'The last minute of the 16th is still the early price.' );
		$this->assertSame( 60000, law_flagship_price_pence( $at( '2026-10-17 00:00' ), $event_id ), 'Midnight London is the switch.' );

		// The proof it is not UTC: 23:00 UTC on the 16th IS midnight London,
		// so the price has already risen at a moment UTC still calls the 16th.
		$utc_2300 = ( new DateTimeImmutable( '2026-10-16 23:00', new DateTimeZone( 'UTC' ) ) )->getTimestamp();
		$this->assertSame( 60000, law_flagship_price_pence( $utc_2300, $event_id ) );
	}

	/** VAT is added on top, and the two figures can never disagree. */
	public function test_vat_is_added_to_the_net_price(): void {
		$this->assertSame( 66000, law_events_gross_pence( 55000 ) );
		$this->assertSame( 11000, law_events_vat_pence( 55000 ) );
		$this->assertSame( 72000, law_events_gross_pence( 60000 ) );
		$this->assertSame(
			law_events_gross_pence( 55000 ),
			55000 + law_events_vat_pence( 55000 ),
			'Gross must always be net plus the VAT reported for it.'
		);
	}

	/** An unset late price falls back to its OWN default, never to the early one. */
	public function test_an_unconfigured_late_price_does_not_keep_charging_the_early_one(): void {
		$event_id = $this->make_flagship();
		delete_post_meta( $event_id, '_law_flagship_price_late_pence' );
		$after = ( new DateTimeImmutable( '2026-10-20 09:00', wp_timezone() ) )->getTimestamp();

		$this->assertSame( LAW_FLAGSHIP_PRICE_LATE_DEFAULT, law_flagship_price_pence( $after, $event_id ) );
	}

	/** A deliberate 0 means "not on sale", and is not confused with "unset". */
	public function test_a_zero_price_is_not_on_sale(): void {
		$event_id = $this->make_flagship( array( '_law_flagship_price_pence' => 0 ) );
		update_post_meta( $event_id, '_law_flagship_price_pence', 0 );

		$this->assertSame( 0, law_flagship_price_pence( 0, $event_id ) );
		$this->assertWPError( law_flagship_apply( $this->make_delegate(), array( 'consent' => true, 'terms' => true ) ), 'law_flagship_not_on_sale' );
	}

	/* Applying ______________________________________________________________ */

	public function test_applying_snapshots_the_price_and_asks_for_a_card(): void {
		$event_id = $this->make_flagship();
		$user_id  = $this->make_delegate();

		$result = $this->apply_as( $user_id );
		$this->assertIsArray( $result );

		$booking_id = (int) $result['booking'];
		$this->assertSame( 'law-applied', get_post_status( $booking_id ) );
		$this->assertSame( 'pending_setup', (string) law_event_meta( $booking_id, '_law_payment_status' ) );
		$this->assertSame( 55000, (int) law_event_meta( $booking_id, '_law_price_pence' ), 'The price is frozen onto the application.' );
		$this->assertNotSame( '', (string) law_event_meta( $booking_id, '_law_payment_consent_at' ), 'Consent is recorded, not assumed.' );
		$this->assertSame( 'https://checkout.stripe.test/cs_test', $result['redirect'] );
		// The applicant owns their own application: no "booked by" stranger.
		$this->assertSame( $user_id, (int) get_post_field( 'post_author', $booking_id ) );
	}

	/** Consent is not a formality: without it there is no application at all. */
	public function test_an_application_without_consent_is_refused(): void {
		$this->make_flagship();
		$user_id = $this->make_delegate();

		$this->assertWPError(
			law_flagship_apply( $user_id, array( 'answers' => array(), 'consent' => false, 'terms' => true ) ),
			'law_flagship_no_consent'
		);
		$this->assertWPError(
			law_flagship_apply( $user_id, array( 'answers' => array(), 'consent' => true, 'terms' => false ) ),
			'law_flagship_no_terms'
		);
	}

	/** One live application per person, however many times they submit. */
	public function test_a_second_application_is_refused(): void {
		$this->make_flagship();
		$user_id = $this->make_delegate();
		$this->apply_as( $user_id );

		$second = law_flagship_apply( $user_id, array( 'answers' => array(), 'consent' => true, 'terms' => true ) );
		$this->assertTrue( is_wp_error( $second ), 'The duplicate guard must refuse a second live application.' );
	}

	/**
	 * A full conference still takes applications (Denis, 10 September 2026):
	 * the copy changes, the door does not close.
	 */
	public function test_applications_are_accepted_when_the_conference_is_full(): void {
		$event_id = $this->make_flagship( array( '_law_tickets_available' => 1 ) );

		// Fill the one place.
		$first = $this->apply_as( $this->make_delegate() );
		$this->give_card( (int) $first['booking'] );
		$this->queue_successful_charge( 66000 );
		law_flagship_approve( (int) $first['booking'], 0 );

		$places = law_flagship_places();
		$this->assertTrue( $places['full'], 'The fixture should now be full.' );

		// And still accept the next person.
		$second = $this->apply_as( $this->make_delegate() );
		$this->assertIsArray( $second, 'A full conference must still accept an application.' );
		$this->assertSame( 'law-applied', get_post_status( (int) $second['booking'] ) );
	}

	/* Approving _____________________________________________________________ */

	public function test_approving_charges_the_card_and_confirms_the_place(): void {
		$event_id = $this->make_flagship();
		$user_id  = $this->make_delegate();
		$result   = $this->apply_as( $user_id );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		$this->queue_successful_charge( 66000 );
		$approved = law_flagship_approve( $booking, 0 );

		$this->assertIsArray( $approved );
		$this->assertSame( 'confirmed', $approved['status'] );
		$this->assertSame( 'publish', get_post_status( $booking ), 'publish is Confirmed on a booking.' );
		$this->assertSame( 'paid', (string) law_event_meta( $booking, '_law_payment_status' ) );
		$this->assertSame( 'https://invoice.stripe.test/in_test', (string) law_event_meta( $booking, '_law_stripe_invoice_url' ) );
		$this->assertSame( 1, law_event_attendee_total( $event_id ), 'A confirmed application takes a place.' );
	}

	/**
	 * The price the delegate consented to is the price charged, even when the
	 * committee gets round to them after the rise.
	 */
	public function test_the_snapshot_survives_an_approval_after_the_price_rises(): void {
		$event_id = $this->make_flagship( array( '_law_flagship_price_switch' => '2020-01-01 00:00' ) );
		$user_id  = $this->make_delegate();

		// Applied while the early price was in force.
		$result  = $this->apply_as( $user_id );
		$booking = (int) $result['booking'];
		law_event_update_meta( $booking, '_law_price_pence', 55000 );
		$this->give_card( $booking );

		// The list price is now the later one.
		$this->assertSame( 60000, law_flagship_price_pence( 0, $event_id ) );

		$this->queue_successful_charge( 66000 );
		law_flagship_approve( $booking, 0 );

		$charged = array_values(
			array_filter(
				$GLOBALS['law_test_stripe_calls'],
				fn( $call ) => '/v1/invoiceitems' === $call['path']
			)
		);
		$this->assertNotEmpty( $charged, 'An invoice item should have been created.' );
		$price = law_booking_price( $booking );
		$this->assertSame( 55000, $price['net'], 'The snapshot, not the risen list price.' );
		$this->assertSame( 66000, $price['gross'] );
	}

	/** Over-booking is deliberate, so it has to be asked for. */
	public function test_approving_past_capacity_needs_confirming(): void {
		$event_id = $this->make_flagship( array( '_law_tickets_available' => 1 ) );

		$first = $this->apply_as( $this->make_delegate() );
		$this->give_card( (int) $first['booking'] );
		$this->queue_successful_charge( 66000 );
		law_flagship_approve( (int) $first['booking'], 0 );

		$second = $this->apply_as( $this->make_delegate() );
		$this->give_card( (int) $second['booking'] );

		$refused = law_flagship_approve( (int) $second['booking'], 0 );
		$this->assertWPError( $refused, 'law_flagship_full' );
		$this->assertSame( 'law-applied', get_post_status( (int) $second['booking'] ), 'A refused approval must not charge or seat.' );

		// Said out loud, it goes through.
		$this->queue_successful_charge( 66000 );
		$forced = law_flagship_approve( (int) $second['booking'], 0, array( 'confirm_overbook' => true ) );
		$this->assertIsArray( $forced );
		$this->assertSame( 1, $forced['overbooked'] );
		$this->assertSame( 'publish', get_post_status( (int) $second['booking'] ) );
	}

	/** Nothing to charge means nothing is asked of Stripe at all. */
	public function test_a_complimentary_place_never_touches_stripe(): void {
		$event_id = $this->make_flagship();
		$actor    = $this->make_committee_user();

		$GLOBALS['law_test_stripe_queue'] = array();
		$booking_id = law_flagship_add_complimentary(
			array( 'name' => 'Guest Speaker', 'email' => 'guest-' . wp_generate_password( 6, false ) . '@example.test' ),
			$actor
		);

		$this->assertIsInt( $booking_id );
		$this->posts[] = $booking_id;
		$this->users[] = (int) get_post_field( 'post_author', $booking_id );
		$this->assertSame( 'publish', get_post_status( $booking_id ) );
		$this->assertSame( 'complimentary', (string) law_event_meta( $booking_id, '_law_payment_status' ) );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'A free place must make no Stripe calls.' );
	}

	/* When the money does not arrive ________________________________________ */

	/** A declined card must never leave somebody looking confirmed. */
	public function test_a_declined_card_lands_on_payment_failed_and_never_on_publish(): void {
		$event_id = $this->make_flagship();
		$result   = $this->apply_as( $this->make_delegate() );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			new WP_Error( 'law_stripe_error', 'Your card has insufficient funds.' ),
		);

		$approved = law_flagship_approve( $booking, 0 );

		$this->assertTrue( is_wp_error( $approved ) );
		$this->assertSame( 'law-payment-failed', get_post_status( $booking ) );
		$this->assertSame( 'failed', (string) law_event_meta( $booking, '_law_payment_status' ) );
		$this->assertStringContainsString( 'insufficient funds', (string) law_event_meta( $booking, '_law_payment_error' ) );
		$this->assertSame( 0, law_event_attendee_total( $event_id ), 'An unpaid application holds no place.' );
	}

	/** The deadline is the failure plus the configured window, not a guess. */
	public function test_the_retry_deadline_follows_the_configured_window(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];

		law_flagship_mark_payment_failed( $booking, 'Card declined.' );
		$deadline = law_flagship_payment_deadline_ts( $booking );

		$this->assertGreaterThan( time(), $deadline );
		$this->assertLessThanOrEqual(
			time() + ( law_flagship_payment_window_days() * DAY_IN_SECONDS ) + 60,
			$deadline
		);
	}

	/** SCA is not a decline, and must not be worded as one. */
	public function test_authentication_required_is_its_own_state(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];

		law_flagship_mark_payment_failed( $booking, 'Confirm with your bank.', 'action_required', 'https://invoice.stripe.test/in_test' );

		$this->assertSame( 'action_required', (string) law_event_meta( $booking, '_law_payment_status' ) );
		$this->assertSame( 'https://invoice.stripe.test/in_test', (string) law_event_meta( $booking, '_law_stripe_invoice_url' ) );
	}

	/**
	 * The synchronous charge and the invoice.paid webhook both confirm, in
	 * whichever order they arrive, so the second must change nothing.
	 */
	public function test_mark_paid_is_idempotent(): void {
		$event_id = $this->make_flagship();
		$result   = $this->apply_as( $this->make_delegate() );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		$this->queue_successful_charge( 66000 );
		law_flagship_approve( $booking, 0 );
		$this->assertSame( 1, law_event_attendee_total( $event_id ) );

		// The webhook lands afterwards with the same invoice.
		law_flagship_mark_paid(
			$booking,
			array( 'id' => 'in_test', 'status' => 'paid', 'amount_paid' => 66000, 'hosted_invoice_url' => 'https://invoice.stripe.test/in_test' ),
			'evt_test'
		);

		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( 1, law_event_attendee_total( $event_id ), 'The second call must not seat the person twice.' );
	}

	/* Declining and withdrawing _____________________________________________ */

	public function test_declining_removes_the_card_and_frees_nothing(): void {
		$event_id = $this->make_flagship();
		$result   = $this->apply_as( $this->make_delegate() );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array( array( 'id' => 'pm_test', 'object' => 'payment_method' ) );
		$declined = law_flagship_decline( $booking, $this->make_committee_user(), 'Oversubscribed this year.' );

		$this->assertTrue( $declined );
		$this->assertSame( 'law-declined', get_post_status( $booking ) );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_stripe_payment_method_id' ), 'The saved card must be forgotten.' );
		$this->assertSame( 'Oversubscribed this year.', (string) law_event_meta( $booking, '_law_decline_reason' ) );
	}

	/** A paid place is not withdrawn from the account area: that is a refund. */
	public function test_a_paid_place_cannot_be_withdrawn_or_declined(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );
		$this->queue_successful_charge( 66000 );
		law_flagship_approve( $booking, 0 );

		$this->assertWPError( law_flagship_withdraw( $booking, (int) get_post_field( 'post_author', $booking ) ), 'law_flagship_already_paid' );
		$this->assertWPError( law_flagship_decline( $booking, $this->make_committee_user() ), 'law_flagship_already_paid' );
		$this->assertSame( 'publish', get_post_status( $booking ) );
	}

	public function test_withdrawing_removes_the_card(): void {
		$this->make_flagship();
		$user_id = $this->make_delegate();
		$result  = $this->apply_as( $user_id );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array( array( 'id' => 'pm_test', 'object' => 'payment_method' ) );
		$this->assertTrue( law_flagship_withdraw( $booking, $user_id ) );
		$this->assertSame( 'law-cancelled', get_post_status( $booking ) );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_stripe_payment_method_id' ) );
	}

	/* The flagship stays out of the hosted machinery ________________________ */

	/**
	 * The booking form, "add a colleague", register-on-behalf and the whole
	 * waitlist all come through law_booking_guard_open(), which is what keeps
	 * them off an event where a place is a committee decision and a charge.
	 */
	public function test_the_hosted_booking_engine_still_refuses_the_flagship(): void {
		$event_id = $this->make_flagship();

		$this->assertWPError( law_booking_guard_open( $event_id ), 'law_booking_flagship' );
		$this->assertWPError( law_booking_create( $event_id, $this->make_delegate(), array() ), 'law_booking_flagship' );
	}

	/**
	 * Automatic first-in-first-out promotion would hand out a place, and
	 * charge a card, that nobody decided on.
	 */
	public function test_the_waitlist_never_processes_the_flagship(): void {
		$event_id = $this->make_flagship();

		$this->assertSame( array(), law_waitlist_process( $event_id, 'test' ) );
	}

	/* Regressions for the review round (10 September 2026) ________________ */

	/**
	 * A declined applicant could pay from the hosted invoice link they were
	 * already sent, and the invoice.paid webhook confirmed them — silently
	 * reversing the committee's decision and giving away a place.
	 */
	public function test_a_payment_after_a_decline_does_not_confirm_the_place(): void {
		$event_id = $this->make_flagship();
		$result   = $this->apply_as( $this->make_delegate() );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		// Declined: card detached, invoice voided.
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'pm_test', 'object' => 'payment_method' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			array( 'id' => 'in_test', 'status' => 'void' ),
		);
		law_flagship_decline( $booking, $this->make_committee_user(), 'Oversubscribed.' );
		$this->assertSame( 'law-declined', get_post_status( $booking ) );

		// The delegate pays anyway, from the link in an earlier email.
		$confirmed = law_flagship_mark_paid(
			$booking,
			array( 'id' => 'in_test', 'status' => 'paid', 'amount_paid' => 66000 ),
			'evt_late'
		);

		$this->assertFalse( $confirmed, 'A declined application must not be confirmed by a late payment.' );
		$this->assertSame( 'law-declined', get_post_status( $booking ), 'The committee decision stands.' );
		$this->assertSame( 0, law_event_attendee_total( $event_id ), 'And no place is given away.' );
	}

	/** The same, for someone who withdrew rather than being declined. */
	public function test_a_payment_after_a_withdrawal_does_not_confirm_the_place(): void {
		$event_id = $this->make_flagship();
		$user_id  = $this->make_delegate();
		$result   = $this->apply_as( $user_id );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'pm_test', 'object' => 'payment_method' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			array( 'id' => 'in_test', 'status' => 'void' ),
		);
		law_flagship_withdraw( $booking, $user_id );

		$this->assertFalse( law_flagship_mark_paid( $booking, array( 'id' => 'in_test', 'status' => 'paid', 'amount_paid' => 66000 ), 'evt_late' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $booking ) );
		$this->assertSame( 0, law_event_attendee_total( $event_id ) );
	}

	/**
	 * Declining used to detach the card and leave a FINALISED invoice live,
	 * which has a payable hosted page of its own.
	 */
	public function test_declining_voids_the_invoice_as_well_as_the_card(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );
		law_event_update_meta( $booking, '_law_stripe_invoice_id', 'in_test' );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'pm_test', 'object' => 'payment_method' ),  // detach
			array( 'id' => 'in_test', 'status' => 'open' ),            // the void's GET
			array( 'id' => 'in_test', 'status' => 'void' ),            // the void itself
		);
		law_flagship_decline( $booking, $this->make_committee_user() );

		$paths = array_column( $GLOBALS['law_test_stripe_calls'], 'path' );
		$this->assertContains( '/v1/payment_methods/pm_test/detach', $paths );
		$this->assertContains( '/v1/invoices/in_test/void', $paths, 'An unvoided invoice stays payable.' );
	}

	/**
	 * A transient Stripe error on the resume GET used to delete the stored
	 * invoice ID and then raise a SECOND invoice, billing the delegate twice.
	 */
	public function test_an_unreadable_resume_aborts_instead_of_billing_again(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );
		law_event_update_meta( $booking, '_law_stripe_invoice_id', 'in_first' );

		// The GET fails, as it would on a 429 or a timeout.
		$GLOBALS['law_test_stripe_queue'] = array( new WP_Error( 'law_stripe_error', 'Too many requests.' ) );
		$charged = law_stripe_charge_booking( $booking );

		$this->assertWPError( $charged, 'law_stripe_resume_unreadable' );
		$this->assertSame( 'in_first', (string) law_event_meta( $booking, '_law_stripe_invoice_id' ), 'The reference is the only thing preventing a double charge.' );
		$paths = array_column( $GLOBALS['law_test_stripe_calls'], 'path' );
		$this->assertNotContains( '/v1/invoices', $paths, 'No second invoice may be created.' );
	}

	/** And that abort must not be reported to the delegate as a decline. */
	public function test_a_configuration_failure_does_not_tell_the_delegate_their_card_failed(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		// VAT applies but no tax rate is configured: our problem, not theirs.
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'tax_rate_id' => '' ) );
		$GLOBALS['law_test_stripe_queue'] = array();

		$approved = law_flagship_approve( $booking, $this->make_committee_user() );

		$this->assertWPError( $approved, 'law_no_tax_rate' );
		$this->assertSame( 'law-applied', get_post_status( $booking ), 'The application is untouched.' );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_payment_failed_at' ), 'And no 7-day clock is started.' );
		$this->assertNotSame( 'failed', (string) law_event_meta( $booking, '_law_payment_status' ) );
	}

	/**
	 * Two approvals of the same booking (two tabs, two committee members)
	 * used to raise two invoices, because nothing claimed the charge.
	 */
	public function test_a_second_approval_cannot_start_a_second_charge(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		// Simulate a charge already in flight.
		$this->assertTrue( law_flagship_claim_charge( $booking ) );
		$GLOBALS['law_test_stripe_queue'] = array();
		// From here, not from the application's own setup session.
		$GLOBALS['law_test_stripe_calls'] = array();

		$second = law_flagship_approve( $booking, 0 );
		$this->assertWPError( $second, 'law_flagship_charging' );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'The loser must not reach Stripe at all.' );

		// And the claim is not a permanent lock-out.
		law_flagship_release_charge( $booking );
		$this->assertTrue( law_flagship_claim_charge( $booking ) );
	}

	/** A crashed charge must not lock the booking out for ever. */
	public function test_a_stale_charge_claim_is_taken_over(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];

		update_post_meta( $booking, '_law_charge_claim', time() - ( 10 * MINUTE_IN_SECONDS ) );
		$this->assertTrue( law_flagship_claim_charge( $booking ), 'A claim older than five minutes is stale.' );
	}

	/**
	 * The cron tail of a bulk approval used to force confirm_overbook, so a
	 * batch that merely hit the ten-at-a-time cap gave away places past
	 * capacity that nobody was asked about.
	 */
	public function test_the_bulk_resume_carries_the_committees_own_answer(): void {
		$this->make_flagship( array( '_law_tickets_available' => 1 ) );

		$first = $this->apply_as( $this->make_delegate() );
		$this->give_card( (int) $first['booking'] );
		$this->queue_successful_charge( 66000 );
		law_flagship_approve( (int) $first['booking'], 0 );

		$second = $this->apply_as( $this->make_delegate() );
		$this->give_card( (int) $second['booking'] );

		// Resuming WITHOUT the committee's confirmation must still refuse.
		$GLOBALS['law_test_stripe_queue'] = array();
		law_flagship_resume_review( array( (int) $second['booking'] ), 'approve', 0, '', false );
		$this->assertSame( 'law-applied', get_post_status( (int) $second['booking'] ), 'Cron must not invent a confirmation.' );
	}

	/**
	 * The daily sweep filtered on the payment state alone, which stays
	 * pending_setup after the booking is closed — so it re-closed and
	 * re-logged the same application every day for ever.
	 */
	public function test_the_daily_sweep_closes_an_abandoned_application_once(): void {
		$event_id = $this->make_flagship();
		$result   = $this->apply_as( $this->make_delegate() );
		$booking  = (int) $result['booking'];
		law_event_update_meta( $booking, '_law_application_at', gmdate( 'Y-m-d H:i', time() - ( 72 * HOUR_IN_SECONDS ) ) );

		law_flagship_run_daily();
		$this->assertSame( 'law-cancelled', get_post_status( $booking ) );
		$after_first = count( law_event_log_entries( $event_id ) );

		law_flagship_run_daily();
		$this->assertSame(
			$after_first,
			count( law_event_log_entries( $event_id ) ),
			'A second sweep must find nothing left to close.'
		);
	}

	/**
	 * The application form collects nothing (Denis, 10 September 2026), so
	 * the PROFILE is the application. An incomplete one must be refused with
	 * somewhere to go, not silently accepted with the name falling back to
	 * the email address — which is what the committee would then review.
	 */
	public function test_an_incomplete_profile_cannot_apply(): void {
		$this->make_flagship();
		$user_id = $this->make_user( 'attendee' );  // No profile at all.

		$this->assertSame( array( 'first name', 'surname' ), law_flagship_profile_gaps( $user_id ) );

		$refused = law_flagship_apply( $user_id, array( 'consent' => true, 'terms' => true ) );
		$this->assertWPError( $refused, 'law_flagship_profile_incomplete' );
		$this->assertStringContainsString( 'profile', $refused->get_error_message(), 'It has to say where to fix it.' );

		// A name is enough. Organisation and job title are deliberately NOT
		// required: 20% of existing accounts have no job title, and refusing
		// them over a column would be worse than an empty cell.
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Ada', 'last_name' => 'Lovelace' ) );

		$this->assertSame( array(), law_flagship_profile_gaps( $user_id ) );
		$result = $this->apply_as( $user_id );
		$this->assertIsArray( $result );
		$this->assertSame( 'Ada Lovelace', (string) law_event_meta( (int) $result['booking'], '_law_attendee_name' ) );
	}

	/**
	 * The dialog asks for nothing. If a field creeps back in, the profile
	 * stops being the single copy of these details and the two can drift.
	 */
	public function test_the_application_form_collects_no_personal_details(): void {
		$form = file_get_contents( get_theme_file_path( 'parts/events/flagship-apply-modal.php' ) );

		foreach ( array( 'first_name', 'last_name', 'job_title', 'organisation', 'country', 'dietary_other', 'accessibility_other' ) as $field ) {
			$this->assertStringNotContainsString(
				'answers][' . $field,
				$form,
				$field . ' is on the profile; the application must not ask for it again.'
			);
		}
		// The two consents are the whole of the form.
		$this->assertStringContainsString( 'law_flagship_apply[consent]', $form );
		$this->assertStringContainsString( 'law_flagship_apply[terms]', $form );
	}

	/** A price that moved under the delegate is refused, not silently applied. */
	public function test_a_price_that_changed_mid_form_is_refused(): void {
		$this->make_flagship();
		$user_id = $this->make_delegate();

		$refused = law_flagship_apply(
			$user_id,
			array( 'answers' => array(), 'consent' => true, 'terms' => true, 'price_shown' => 50000 )
		);
		$this->assertWPError( $refused, 'law_flagship_price_changed' );

		// The figure it actually showed goes through.
		$ok = $this->apply_as( $user_id, array( 'price_shown' => 55000 ) );
		$this->assertIsArray( $ok );
	}

	/** The webhook must find a booking by its own metadata, not an event. */
	public function test_the_webhook_resolves_a_booking_from_its_metadata(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];

		$this->assertSame(
			$booking,
			law_stripe_resolve_booking_id( array( 'metadata' => array( 'law_booking_id' => (string) $booking ) ) )
		);
		$this->assertSame( 0, law_stripe_resolve_booking_id( array( 'metadata' => array( 'law_event_id' => '123' ) ) ) );
		// An event ID in the booking slot must not resolve: wrong post type.
		$this->assertSame( 0, law_stripe_resolve_booking_id( array( 'metadata' => array( 'law_booking_id' => (string) law_flagship_event_id() ) ) ) );
	}

	/**
	 * An attendee must never be asked to accept the HOST terms, which are
	 * about arranging a venue and paying a £1,200 host fee. The design gate
	 * found the application form linking to exactly that.
	 */
	public function test_the_application_never_links_to_the_host_terms(): void {
		$form = file_get_contents( get_theme_file_path( 'parts/events/flagship-apply-modal.php' ) );
		$this->assertStringContainsString( 'law_events_attendee_terms_url()', $form );
		$this->assertStringNotContainsString( 'law_events_terms_url()', $form, 'Those are the host terms.' );

		// And the helper itself must not fall back to them, however tempting.
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'attendee_terms_page' => '' ) );
		$this->assertStringNotContainsString( 'event-host-terms', law_events_attendee_terms_url() );
		$this->assertFalse( law_events_attendee_terms_configured(), 'Unset must report itself, so the committee is told.' );
	}

	/**
	 * The details box quotes the price the way LAW quotes it (net + VAT); the
	 * total belongs in the form, beside the consent to be charged it. Doing
	 * the sum in two places is two places for it to disagree.
	 */
	public function test_the_details_box_quotes_the_net_price_and_the_form_does_the_sum(): void {
		$event_id = $this->make_flagship();
		$event    = law_events_map_post( $event_id, array( '*' ) );

		$this->assertSame( '£550.00 + VAT', law_flagship_details_price( $event ) );
		// The fixture offers two places and nobody has taken one.
		$this->assertSame( '2 places left', law_flagship_details_places( $event ) );
		// The full arithmetic, for the form.
		$this->assertSame( '£660.00 including VAT (£550.00 + VAT)', law_events_price_label( 55000 ) );

		// And neither row appears on an ordinary event.
		$hosted = law_events_map_post( $this->make_event( array(), 'publish' ), array( '*' ) );
		$this->assertSame( '', law_flagship_details_price( $hosted ) );
		$this->assertSame( '', law_flagship_details_places( $hosted ) );
	}

	/**
	 * The rise is silent (Denis, 10 September 2026). That is only safe
	 * because the figure shown is always the figure charged, so pin both
	 * halves: no warning anywhere, and a moved price refused rather than
	 * quietly applied.
	 */
	public function test_the_price_rise_is_never_announced(): void {
		$this->make_flagship();

		foreach ( array( 'parts/events/flagship-apply-modal.php', 'functions/account-flagship.php' ) as $file ) {
			$source = file_get_contents( get_theme_file_path( $file ) );
			$this->assertStringNotContainsString( 'rises to', $source, $file . ' must not announce the rise.' );
			$this->assertStringNotContainsString( 'law_flagship_price_deadline_note', $source );
		}
		$this->assertFalse( function_exists( 'law_flagship_price_deadline_note' ) );

		// The guard that makes silence honest.
		$refused = law_flagship_apply(
			$this->make_delegate(),
			array( 'consent' => true, 'terms' => true, 'price_shown' => 50000 )
		);
		$this->assertWPError( $refused, 'law_flagship_price_changed' );
	}

	/** An application counts as holding a place, so nobody applies twice. */
	public function test_applied_and_payment_failed_count_as_holding_a_place(): void {
		$holding = law_booking_holding_statuses();

		$this->assertContains( 'law-applied', $holding );
		$this->assertContains( 'law-payment-failed', $holding );
		$this->assertNotContains( 'law-declined', $holding, 'A declined applicant is free to apply again.' );
	}

	/* Payment methods other than a card ______________________________________ */

	/**
	 * Checkout in setup mode offers whatever the Stripe account has enabled
	 * (EVENTS_4.2_SPECS.md §7.1: all of them bar Klarna), so the label must
	 * never assume a card. It used to read only $method['card'], which made a
	 * booking that HAD a saved method show "No payment method saved yet".
	 */
	public function test_the_label_describes_every_kind_of_payment_method(): void {
		$cases = array(
			'a plain card'  => array(
				array( 'type' => 'card', 'card' => array( 'brand' => 'visa', 'last4' => '4242', 'exp_month' => 4, 'exp_year' => 2029 ) ),
				'Visa ending 4242, expires 04/2029',
			),
			'a wallet card' => array(
				array( 'type' => 'card', 'card' => array( 'brand' => 'visa', 'last4' => '4242', 'wallet' => array( 'type' => 'apple_pay' ) ) ),
				'Apple Pay (Visa ending 4242)',
			),
			'Link'          => array(
				array( 'type' => 'link', 'link' => array( 'email' => 'delegate@example.com' ) ),
				'Link (delegate@example.com)',
			),
			'Revolut Pay'   => array( array( 'type' => 'revolut_pay' ), 'Revolut Pay' ),
			'a bank debit'  => array(
				array( 'type' => 'bacs_debit', 'bacs_debit' => array( 'last4' => '2345' ) ),
				'Direct Debit ending 2345',
			),
			// The point of the fallback: a method Stripe adds after this was
			// written still reads as something, not as a blank.
			'one we have never seen' => array( array( 'type' => 'wechat_pay' ), 'Wechat Pay' ),
		);

		foreach ( $cases as $name => $case ) {
			$this->assertSame( $case[1], law_stripe_method_label( $case[0] ), $name . ' must be described.' );
		}

		$this->assertSame( '', law_stripe_method_label( array() ), 'No method at all is the only empty case.' );
	}

	/** And the stored label is what the booking then reports. */
	public function test_a_non_card_method_is_stored_and_shown_on_the_booking(): void {
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'pm_link', 'type' => 'link', 'link' => array( 'email' => 'delegate@example.com' ) ),
			array( 'id' => 'cus_test', 'object' => 'customer' ),
		);
		law_event_update_meta( $booking, '_law_stripe_customer_id', 'cus_test' );

		$this->assertTrue( law_stripe_store_payment_method( $booking, 'pm_link', 'seti_test' ) );
		$this->assertSame( 'link', (string) law_event_meta( $booking, '_law_stripe_method_type' ) );
		$this->assertSame( 'Link (delegate@example.com)', law_booking_payment_method_label( $booking ) );
		$this->assertSame( 'ready', (string) law_event_meta( $booking, '_law_payment_status' ) );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_stripe_card_last4' ), 'No card, so no card fields.' );
	}

	/** Old bookings, saved before the type was recorded, still read. */
	public function test_the_label_falls_back_to_the_card_fields_on_an_older_booking(): void {
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];

		law_event_update_meta( $booking, '_law_stripe_card_brand', 'mastercard' );
		law_event_update_meta( $booking, '_law_stripe_card_last4', '4444' );

		$this->assertSame( 'Mastercard ending 4444', law_booking_payment_method_label( $booking ) );
	}

	/**
	 * A payment that has not settled is NOT a failure and NOT SCA.
	 *
	 * Cards settle inside the approval request; the other methods Stripe
	 * offers may not. Calling that "payment failed" and emailing the delegate
	 * to go and see their bank would be wrong twice, so approve reads the
	 * PaymentIntent behind the invoice and separates the two.
	 */
	public function test_a_payment_still_settling_holds_the_place_without_alarming_anyone(): void {
		$event_id = $this->make_flagship();
		$result   = $this->apply_as( $this->make_delegate() );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			// The pay call: accepted, not settled. Invoice.payment_intent was
			// removed in 2025-03-31.basil, so the status lives three levels
			// down under payments, which is what the client expands.
			array(
				'id'                 => 'in_test',
				'status'             => 'open',
				'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
				'payments'           => array(
					'data' => array(
						array( 'payment' => array( 'payment_intent' => array( 'id' => 'pi_test', 'status' => 'processing' ) ) ),
					),
				),
			),
		);

		$approved = law_flagship_approve( $booking, $this->make_committee_user() );

		$this->assertFalse( is_wp_error( $approved ), 'A settling payment is not an error.' );
		$this->assertSame( 'processing', $approved['status'] );
		$this->assertSame( 'processing', (string) law_event_meta( $booking, '_law_payment_status' ) );
		$this->assertSame( 'law-applied', get_post_status( $booking ), 'The place is held where it was.' );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_payment_error' ), 'Nothing has gone wrong, so nothing is reported.' );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_payment_failed_at' ), 'And it is not stamped as a failure.' );

		// Then the money lands and the webhook confirms it.
		$this->assertTrue(
			law_flagship_mark_paid( $booking, array( 'id' => 'in_test', 'status' => 'paid', 'amount_paid' => 66000 ), 'evt_paid' )
		);
		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( 1, law_event_attendee_total( $event_id ) );
	}

	/** SCA still reads as SCA: the delegate has something to do. */
	public function test_a_payment_needing_authentication_is_still_an_exception(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			array(
				'id'                 => 'in_test',
				'status'             => 'open',
				'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
				'payments'           => array(
					'data' => array(
						array( 'payment' => array( 'payment_intent' => array( 'id' => 'pi_test', 'status' => 'requires_action' ) ) ),
					),
				),
			),
		);

		$approved = law_flagship_approve( $booking, $this->make_committee_user() );

		$this->assertSame( 'action_required', $approved['status'] );
		$this->assertSame( 'action_required', (string) law_event_meta( $booking, '_law_payment_status' ) );
		$this->assertSame( 'law-payment-failed', get_post_status( $booking ) );
	}

	/** A charge in flight cannot be pulled out from under Stripe. */
	public function test_a_delegate_cannot_withdraw_while_the_payment_is_settling(): void {
		$user_id = $this->make_delegate();
		$result  = $this->apply_as( $user_id );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );
		law_event_update_meta( $booking, '_law_payment_status', 'processing' );

		$GLOBALS['law_test_stripe_calls'] = array();
		$withdrawn = law_flagship_withdraw( $booking, $user_id );

		$this->assertWPError( $withdrawn, 'law_flagship_payment_in_flight' );
		$this->assertSame( 'law-applied', get_post_status( $booking ), 'The application stands.' );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'And nothing is detached or voided under a live payment.' );
	}

	/**
	 * One saved payment method, one acknowledgement and one committee alert.
	 *
	 * Three requests reach law_flagship_on_card_saved() for a single save:
	 * both setup webhooks and the delegate's own return from Checkout.
	 *
	 * Be clear about what this does and does not prove. It calls them in
	 * sequence, so it pins that the latch EXISTS and that a later report is a
	 * no-op. It does NOT reproduce the bug seen on 10 September 2026, which
	 * was two of each email in the committee's inbox: that needed the three
	 * requests to overlap, all reading the latch before any of them wrote it,
	 * and a single-threaded test cannot stage that. The fix for the race is
	 * the event lock plus the unique claim in law_flagship_on_card_saved();
	 * this test only stops the latch being removed or inverted later.
	 */
	public function test_the_application_emails_are_sent_once_however_many_paths_report_the_save(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];

		// Fresh: apply_as() leaves the application waiting for a method.
		delete_post_meta( $booking, '_law_application_ready' );
		law_event_update_meta( $booking, '_law_payment_status', 'ready' );

		$sent   = array();
		$filter = function ( $atts ) use ( &$sent ) {
			$sent[] = (string) ( $atts['subject'] ?? '' );
			return $atts;
		};
		add_filter( 'wp_mail', $filter );

		// checkout.session.completed, then setup_intent.succeeded, then the
		// browser coming back: the three real callers, in a plausible order.
		$first  = law_flagship_on_card_saved( $booking );
		$second = law_flagship_on_card_saved( $booking );
		$third  = law_flagship_on_card_saved( $booking );

		remove_filter( 'wp_mail', $filter );

		$this->assertTrue( $first, 'The first report does the work.' );
		$this->assertFalse( $second, 'The second must find the latch claimed.' );
		$this->assertFalse( $third, 'And so must the third.' );
		$this->assertCount( 2, $sent, 'Exactly two emails: one delegate, one committee. Got: ' . implode( ' | ', $sent ) );
		$this->assertCount(
			1,
			get_post_meta( $booking, '_law_application_ready', false ),
			'One latch row, so the claim cannot be satisfied twice.'
		);
	}

	/**
	 * A retry after a decline must not reuse the first attempt's key.
	 *
	 * Stripe refuses a key replayed with different parameters, and a retry
	 * always has different parameters: a new payment method. On
	 * 10 September 2026 `/pay` was keyed on the booking and invoice alone,
	 * so the retry came back with "Keys for idempotent requests can only be
	 * used with the same parameters they were first used with", and that
	 * sentence was then shown to the delegate as the reason their payment
	 * had failed.
	 */
	public function test_a_retry_pays_with_a_fresh_idempotency_key(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		// First attempt: invoice raised and finalised, then declined.
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			new WP_Error( 'law_stripe_error', 'Your card was declined.' ),
		);
		law_flagship_approve( $booking, $this->make_committee_user() );
		$this->assertSame( 'law-payment-failed', get_post_status( $booking ) );

		$first = $this->pay_key();
		$this->assertNotSame( '', $first, 'The pay call must carry a key at all.' );

		// The delegate saves a different method, and the charge is retried.
		law_event_update_meta( $booking, '_law_stripe_payment_method_id', 'pm_other' );
		$GLOBALS['law_test_stripe_idem'] = array();
		$GLOBALS['law_test_stripe_queue'] = array(
			// The resume GET finds the finalised invoice and pays THAT one.
			array( 'id' => 'in_test', 'status' => 'open' ),
			array(
				'id'                 => 'in_test',
				'status'             => 'paid',
				'amount_paid'        => 66000,
				'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
			),
			array( 'id' => 'in_test', 'payments' => array( 'data' => array() ) ),
		);
		law_flagship_retry_charge( $booking, $this->make_committee_user() );

		$second = $this->pay_key();
		$this->assertNotSame( '', $second, 'The retry must pay too.' );
		$this->assertNotSame( $first, $second, 'The retry must not replay the declined attempt\'s key.' );
		$this->assertSame( 'publish', get_post_status( $booking ), 'And the retry confirms the place.' );
	}

	/**
	 * A mistake of OURS is never dressed up as the delegate's card failing.
	 *
	 * Every Stripe API failure shares one WP_Error code, so the classifier
	 * reads the Stripe error type. An invalid_request_error (a reused
	 * idempotency key, a detached method, a malformed call) is ours: the
	 * application must be left alone, an admin alerted, and the delegate
	 * told nothing. On 10 September 2026 one was written onto the booking as
	 * the decline reason, shown in red on the committee's table, and emailed
	 * to the delegate as "The payment method we had on file was declined:
	 * Keys for idempotent requests can only be used with…".
	 */
	public function test_a_stripe_mistake_of_ours_is_not_reported_as_a_decline(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		$sent   = array();
		$filter = function ( $atts ) use ( &$sent ) {
			// wp_mail()'s `to` is a string OR an array of them.
			foreach ( (array) ( $atts['to'] ?? array() ) as $recipient ) {
				$sent[] = (string) $recipient;
			}
			return $atts;
		};
		add_filter( 'wp_mail', $filter );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			new WP_Error(
				'law_stripe_api_error',
				"Keys for idempotent requests can only be used with the same parameters they were first used with.",
				array( 'status' => 400, 'stripe' => array( 'type' => 'invalid_request_error' ) )
			),
		);

		$approved = law_flagship_approve( $booking, $this->make_committee_user() );
		remove_filter( 'wp_mail', $filter );

		$this->assertWPError( $approved, 'law_stripe_api_error' );
		$this->assertSame( 'law-applied', get_post_status( $booking ), 'The application is untouched, not failed.' );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_payment_error' ), 'Our bug is not written up as their decline.' );

		$delegate = get_userdata( (int) get_post_field( 'post_author', $booking ) )->user_email;
		$this->assertNotContains( $delegate, $sent, 'And the delegate is not emailed about it.' );
	}

	/** A real decline still is the delegate's, and still reaches them. */
	public function test_a_card_decline_is_still_reported_to_the_delegate(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			new WP_Error(
				'law_stripe_api_error',
				'Your card has insufficient funds.',
				array( 'status' => 402, 'stripe' => array( 'type' => 'card_error', 'code' => 'card_declined' ) )
			),
		);

		law_flagship_approve( $booking, $this->make_committee_user() );

		$this->assertSame( 'law-payment-failed', get_post_status( $booking ) );
		$this->assertSame( 'Your card has insufficient funds.', (string) law_event_meta( $booking, '_law_payment_error' ) );
	}

	/* Never twice ____________________________________________________________ */

	/**
	 * A booking gets ONE invoice, however many times a charge is attempted.
	 *
	 * The guard is the order of writes in law_stripe_charge_booking(): the
	 * invoice id is persisted BEFORE its line item, and every later attempt
	 * GETs that invoice first and works with it rather than raising another.
	 */
	public function test_a_second_charge_attempt_raises_no_second_invoice(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		$this->queue_successful_charge( 66000 );
		law_flagship_approve( $booking, $this->make_committee_user() );
		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( 1, $this->count_calls( 'POST', '/v1/invoices' ), 'One invoice for the first charge.' );

		// Ask again, the way a stray retry or a replayed webhook would.
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'status' => 'paid', 'amount_paid' => 66000 ), // the resume GET
		);
		$again = law_stripe_charge_booking( $booking );

		$this->assertFalse( is_wp_error( $again ) );
		$this->assertSame( 'paid', (string) ( $again['status'] ?? '' ), 'It hands back the invoice already paid.' );
		$this->assertSame( 1, $this->count_calls( 'POST', '/v1/invoices' ), 'And raises no second invoice.' );
		$this->assertSame( 1, $this->count_calls( 'POST', '/v1/invoices/in_test/pay' ), 'And does not pay again.' );
	}

	/**
	 * A finalised-but-unpaid invoice is PAID, not replaced.
	 *
	 * The dangerous shape: the first attempt gets as far as finalising and
	 * then the pay call fails. If the next attempt raised a fresh invoice,
	 * the delegate would end up with two payable invoices for one place.
	 */
	public function test_a_retry_pays_the_existing_invoice_rather_than_raising_another(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			new WP_Error( 'law_stripe_api_error', 'Your card was declined.', array( 'stripe' => array( 'type' => 'card_error' ) ) ),
		);
		law_flagship_approve( $booking, $this->make_committee_user() );

		$this->assertSame( 'law-payment-failed', get_post_status( $booking ) );
		$this->assertSame( 'in_test', (string) law_event_meta( $booking, '_law_stripe_invoice_id' ), 'The reference must survive a decline.' );
		$this->assertSame( 1, $this->count_calls( 'POST', '/v1/invoices' ) );

		// Count the RETRY's calls only, so "no second invoice" means exactly
		// that rather than "one invoice in total across both attempts".
		$GLOBALS['law_test_stripe_calls'] = array();
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'status' => 'open' ),                                   // resume GET
			array( 'id' => 'in_test', 'status' => 'paid', 'amount_paid' => 66000 ),           // pay
			array( 'id' => 'in_test', 'payments' => array( 'data' => array() ) ),             // charge lookup
		);
		law_flagship_retry_charge( $booking, $this->make_committee_user() );

		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( 0, $this->count_calls( 'POST', '/v1/invoices' ), 'The retry raises no invoice of its own.' );
		$this->assertSame( 0, $this->count_calls( 'POST', '/v1/invoiceitems' ), 'And puts no second line item on the old one.' );
		$this->assertSame( 1, $this->count_calls( 'POST', '/v1/invoices/in_test/pay' ), 'It pays the invoice that already existed.' );
	}

	/**
	 * A charge already in flight refuses a second one, and reaches Stripe
	 * not at all.
	 *
	 * Two committee members clicking Approve at the same second, or one
	 * member with two tabs. The claim is a single atomic INSERT, so exactly
	 * one wins.
	 */
	public function test_a_charge_already_running_blocks_a_second_before_it_reaches_stripe(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		$this->assertTrue( law_flagship_claim_charge( $booking ), 'The first request claims it.' );

		$GLOBALS['law_test_stripe_queue'] = array();
		$GLOBALS['law_test_stripe_calls'] = array();
		$second = law_flagship_approve( $booking, $this->make_committee_user() );

		$this->assertWPError( $second, 'law_flagship_charging' );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'The loser must not reach Stripe at all.' );
		$this->assertSame( 'law-applied', get_post_status( $booking ) );
	}

	/**
	 * A charge whose response was lost confirms on the next attempt without
	 * taking the money again.
	 *
	 * Stripe took the payment, the response never arrived, so the booking
	 * sits unconfirmed. The retry must notice the invoice is already paid.
	 */
	public function test_a_lost_response_confirms_without_charging_again(): void {
		$event_id = $this->make_flagship();
		$result   = $this->apply_as( $this->make_delegate() );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'object' => 'invoice' ),
			array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_test', 'status' => 'open' ),
			new WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' ),
		);
		law_flagship_approve( $booking, $this->make_committee_user() );
		$this->assertSame( 'law-payment-failed', get_post_status( $booking ) );

		// Stripe had in fact taken it. Count the retry's calls only.
		$GLOBALS['law_test_stripe_calls'] = array();
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_test', 'status' => 'paid', 'amount_paid' => 66000 ),  // resume GET
			array( 'id' => 'in_test', 'payments' => array( 'data' => array() ) ),    // charge lookup
		);
		law_flagship_retry_charge( $booking, $this->make_committee_user() );

		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( 1, law_event_attendee_total( $event_id ) );
		$this->assertSame( 0, $this->count_calls( 'POST', '/v1/invoices/in_test/pay' ), 'The money was already taken; do not take it again.' );
		$this->assertSame( 0, $this->count_calls( 'POST', '/v1/invoices' ), 'And no second invoice.' );
	}

	/** A duplicated id in a bulk batch is charged once and counted once. */
	public function test_a_repeated_id_in_a_bulk_batch_is_charged_once(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		$this->queue_successful_charge( 66000 );
		$bulk = law_flagship_review_bulk(
			array( $booking, $booking, $booking ),
			'approve',
			$this->make_committee_user(),
			'',
			true
		);

		$this->assertSame( 1, $bulk['done'], 'Three ticks of one person is one approval.' );
		$this->assertSame( 1, $this->count_calls( 'POST', '/v1/invoices' ) );
		$this->assertSame( 1, $this->count_calls( 'POST', '/v1/invoices/in_test/pay' ) );
	}

	/**
	 * A settling payment cannot be approved a second time.
	 *
	 * The booking stays law-applied while the money travels, which is what
	 * holds its place — and which also made it look reviewable. Approving
	 * again would resume onto the open invoice and try to pay a payment
	 * already in flight. The dashboard hides the button, but hiding a
	 * control is not a guard: a stale page or the no-JS form reaches the
	 * function directly.
	 */
	public function test_a_settling_payment_cannot_be_approved_again(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );
		law_event_update_meta( $booking, '_law_payment_status', 'processing' );
		law_event_update_meta( $booking, '_law_stripe_invoice_id', 'in_test' );

		$GLOBALS['law_test_stripe_queue'] = array();
		$GLOBALS['law_test_stripe_calls'] = array();
		$again = law_flagship_approve( $booking, $this->make_committee_user(), array( 'confirm_overbook' => true ) );

		$this->assertWPError( $again, 'law_flagship_payment_in_flight' );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'And it must not reach Stripe at all.' );
		$this->assertSame( 'law-applied', get_post_status( $booking ), 'The place is still held.' );
	}

	/** Declining under a live payment is refused, exactly as withdrawing is. */
	public function test_a_settling_payment_cannot_be_declined_either(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );
		law_event_update_meta( $booking, '_law_payment_status', 'processing' );

		$GLOBALS['law_test_stripe_calls'] = array();
		$declined = law_flagship_decline( $booking, $this->make_committee_user(), 'No room.' );

		$this->assertWPError( $declined, 'law_flagship_payment_in_flight' );
		$this->assertSame( 'law-applied', get_post_status( $booking ) );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Nothing is detached or voided under a live payment.' );
	}

	/**
	 * A payment that never settles is surfaced, not left holding a place.
	 *
	 * Every action on a processing booking is deliberately refused, so if a
	 * webhook is dropped nothing would ever mention it again. The sweep
	 * alerts; it never decides.
	 */
	public function test_the_daily_sweep_alerts_on_a_payment_stuck_in_progress(): void {
		$event_id = $this->make_flagship();
		$result   = $this->apply_as( $this->make_delegate() );
		$booking  = (int) $result['booking'];
		$this->give_card( $booking );

		law_event_update_meta( $booking, '_law_payment_status', 'processing' );
		law_event_update_meta(
			$booking,
			'_law_payment_processing_at',
			gmdate( 'Y-m-d H:i', time() - ( ( LAW_FLAGSHIP_PROCESSING_ALERT_DAYS + 1 ) * DAY_IN_SECONDS ) )
		);

		law_flagship_run_daily();

		$this->assertSame( 'law-applied', get_post_status( $booking ), 'The sweep alerts; it must never decide.' );
		$this->assertSame( 'processing', (string) law_event_meta( $booking, '_law_payment_status' ) );
		$this->assertSame( '1', (string) get_post_meta( $booking, '_law_payment_stuck_flagged', true ) );

		$this->assertSame( 1, $this->count_logs( $event_id, 'flagship_payment_stuck' ), 'The alert must actually be written.' );

		// And only once, however many days it runs.
		law_flagship_run_daily();
		$this->assertSame( 1, $this->count_logs( $event_id, 'flagship_payment_stuck' ), 'One alert, not one a day.' );
	}

	/** A payment settling normally is left alone by the sweep. */
	public function test_the_daily_sweep_leaves_a_recent_payment_in_progress_alone(): void {
		$this->make_flagship();
		$result  = $this->apply_as( $this->make_delegate() );
		$booking = (int) $result['booking'];
		$this->give_card( $booking );

		law_event_update_meta( $booking, '_law_payment_status', 'processing' );
		law_event_update_meta( $booking, '_law_payment_processing_at', gmdate( 'Y-m-d H:i' ) );

		law_flagship_run_daily();

		$this->assertSame( '', (string) get_post_meta( $booking, '_law_payment_stuck_flagged', true ), 'A bank debit takes days; do not cry wolf.' );
		$this->assertSame( 0, $this->count_logs( law_flagship_event_id(), 'flagship_payment_stuck' ) );
	}

	/**
	 * How many activity-log entries carry a given action.
	 *
	 * The action is not comment meta of its own: law_event_log() JSON-encodes
	 * the whole context into _law_log_context. Reading a bare 'action' key
	 * returns '' for every row, which counts zero and makes any assertion
	 * built on it pass whatever the code does.
	 */
	private function count_logs( int $event_id, string $action ): int {
		$n = 0;
		foreach ( law_event_log_entries( $event_id ) as $comment ) {
			$context = json_decode( (string) get_comment_meta( $comment->comment_ID, '_law_log_context', true ), true );
			if ( is_array( $context ) && $action === (string) ( $context['action'] ?? '' ) ) {
				$n++;
			}
		}

		return $n;
	}

	/** How many times a given Stripe endpoint was called this test. */
	private function count_calls( string $method, string $path ): int {
		$n = 0;
		foreach ( (array) ( $GLOBALS['law_test_stripe_calls'] ?? array() ) as $call ) {
			if ( $call['method'] === $method && $call['path'] === $path ) {
				$n++;
			}
		}

		return $n;
	}

	/** The idempotency key of the most recent invoice pay call. */
	private function pay_key(): string {
		foreach ( array_reverse( (array) ( $GLOBALS['law_test_stripe_idem'] ?? array() ) ) as $call ) {
			if ( str_contains( (string) $call['path'], '/pay' ) ) {
				return (string) $call['idem'];
			}
		}

		return '';
	}

	/** WP_Query drops an unregistered status and then returns everything. */
	public function test_the_new_statuses_are_registered(): void {
		foreach ( array( 'law-applied', 'law-declined', 'law-payment-failed' ) as $status ) {
			$this->assertNotNull( get_post_status_object( $status ), $status . ' must be a registered post status.' );
			$this->assertArrayHasKey( $status, law_booking_statuses() );
		}
		// And they stay out of the hosted-event vocabulary.
		$this->assertArrayNotHasKey( 'law-applied', law_event_statuses() );
	}
}
