<?php
/**
 * stripe/reconcile.php and migration/reconcile-payments.php: the site and
 * Stripe are compared, a payment Stripe has and this site does not is settled
 * all the way through to publication, and every other disagreement is left for
 * a human.
 *
 * The defect behind all of it: migration could only read payment off form 2
 * (Event > submit an event) field 95 (Event status), which the retired Make
 * scenario advanced to Confirmed only when it managed to release Gravity Flow
 * step 20 (Waiting for payment). Where that release was lost the event reads
 * unpaid however long ago the host paid, so it is never published and
 * law_booking_guard_open() refuses every booking.
 *
 * Mail is captured on the 'wp_mail' filter (which runs before the pre_wp_mail
 * short-circuit the bootstrap installs), so nothing is sent.
 */

require_once __DIR__ . '/class-law-test-case.php';

class PaymentReconcileTest extends LAW_Test_Case {

	private array $mail = array();
	private $mail_filter;

	protected function setUp(): void {
		parent::setUp();
		// isolate_option() seeds an overlay from array(), so the switch has to
		// be written explicitly or every sweep test would run against "off".
		$this->isolate_option( LAW_RECONCILE_ENABLED_OPTION );
		update_option( LAW_RECONCILE_ENABLED_OPTION, 1 );
		$this->isolate_option( LAW_RECONCILE_LAST_RUN_OPTION );

		$this->mail        = array();
		$this->mail_filter = function ( $atts ) {
			$this->mail[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $this->mail_filter );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_mail', $this->mail_filter );
		parent::tearDown();
	}

	/* Fixtures ______________________________________________________________ */

	/** An event the module invoiced: it holds its invoice ID. */
	private function make_invoiced_event( $payment = 'unpaid', $status = 'law-approved', $fee = 120000 ): int {
		return $this->make_event(
			array(
				'_law_stripe_invoice_id' => 'in_TEST',
				'_law_fee_pence'         => $fee,
				'_law_vat'               => 1,
				'_law_payment_status'    => $payment,
				'_law_tickets_available' => 50,
			),
			$status
		);
	}

	/** An event as migration left one the Make scenario had invoiced. */
	private function make_legacy_event( $entry_id = 1222 ): int {
		return $this->make_event(
			array(
				'_law_gf_entry_id'        => $entry_id,
				'_law_stripe_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_LEGACY',
				'_law_invoice_email'      => 'host@example.test',
				'_law_fee_pence'          => 120000,
				'_law_vat'                => 1,
				'_law_payment_status'     => 'unpaid',
				'_law_tickets_available'  => 50,
			),
			'law-approved'
		);
	}

	/**
	 * One Stripe invoice object. £1,200 + 20% VAT is what an event snapshotted
	 * at 120000 with VAT is expected to have been billed.
	 */
	private function invoice( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'                 => 'in_TEST',
				'object'             => 'invoice',
				'customer'           => 'cus_TEST',
				'status'             => 'paid',
				'total'              => 144000,
				'amount_paid'        => 144000,
				'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_LEGACY',
				'metadata'           => array( 'gf_entry_id' => '1222' ),
			),
			$overrides
		);
	}

	private function log_text( $event_id ): string {
		return implode( "\n", wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' ) );
	}

	private function mail_subjects(): array {
		return wp_list_pluck( $this->mail, 'subject' );
	}

	/* The comparison ________________________________________________________ */

	public function test_stripe_paid_and_site_unpaid_is_the_one_settleable_verdict(): void {
		$event = $this->make_invoiced_event();
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice() );

		$check = law_stripe_reconcile_check( $event );

		$this->assertSame( 'paid_not_recorded', $check['verdict'] );
		$this->assertTrue( $check['settleable'] );
		$this->assertSame( 144000, $check['amount_paid'] );
		$this->assertSame( 144000, $check['expected_pence'], 'The fee snapshot plus 20% VAT is what should have been billed.' );
		$this->assertSame( 'stored invoice ID', $check['found_by'] );
		$this->assertSame(
			array( array( 'method' => 'GET', 'path' => '/v1/invoices/in_TEST' ) ),
			$GLOBALS['law_test_stripe_calls'],
			'An event holding its ID costs exactly one GET.'
		);
	}

	public function test_agreement_settles_nothing(): void {
		$event = $this->make_invoiced_event( 'paid', 'publish' );
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice() );

		$check = law_stripe_reconcile_check( $event );

		$this->assertSame( 'agreed', $check['verdict'] );
		$this->assertFalse( $check['settleable'] );
	}

	public function test_a_refunded_event_is_not_read_as_an_unrecorded_payment(): void {
		// A refunded invoice stays `paid` in Stripe: the money went back out
		// through the charge, not by un-paying the invoice. Reading that as
		// "Stripe paid, site not paid" would have the sweep re-confirming and
		// re-publishing every event the committee had refunded.
		$event = $this->make_invoiced_event( 'refunded', 'publish' );
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice() );

		$check = law_stripe_reconcile_check( $event );

		$this->assertSame( 'agreed', $check['verdict'] );
		$this->assertFalse( $check['settleable'] );
	}

	public function test_site_says_paid_but_the_invoice_is_still_open(): void {
		$event = $this->make_invoiced_event( 'paid', 'publish' );
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice( array( 'status' => 'open', 'amount_paid' => 0 ) ) );

		$check = law_stripe_reconcile_check( $event );

		$this->assertSame( 'recorded_paid_not_settled', $check['verdict'] );
		$this->assertFalse( $check['settleable'], 'Unpublishing a live event is never a sweep\'s decision.' );
		$this->assertTrue( law_stripe_reconcile_needs_a_human( $check['verdict'] ) );
	}

	public function test_site_says_paid_but_the_invoice_was_voided(): void {
		$event = $this->make_invoiced_event( 'paid', 'publish' );
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice( array( 'status' => 'void', 'amount_paid' => 0 ) ) );

		$check = law_stripe_reconcile_check( $event );

		$this->assertSame( 'recorded_paid_written_off', $check['verdict'] );
		$this->assertFalse( $check['settleable'] );
	}

	public function test_money_on_a_cancelled_event_is_never_settled_automatically(): void {
		$event = $this->make_invoiced_event( 'unpaid', 'law-cancelled' );
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice() );

		$check = law_stripe_reconcile_check( $event );

		$this->assertSame( 'paid_after_cancellation', $check['verdict'] );
		$this->assertFalse( $check['settleable'], 'Only a human decides whether a refund is right.' );
	}

	public function test_an_event_with_no_invoice_is_reported_not_guessed_at(): void {
		$event = $this->make_event( array( '_law_fee_pence' => 120000 ), 'law-approved' );

		$check = law_stripe_reconcile_check( $event );

		$this->assertSame( 'no_invoice', $check['verdict'] );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Nothing to look up means nothing is asked of Stripe.' );
	}

	/* Settling ______________________________________________________________ */

	public function test_settling_publishes_the_event_not_just_the_payment_column(): void {
		$event = $this->make_invoiced_event();
		$GLOBALS['law_test_stripe_queue'] = array(
			$this->invoice(),                                   // the check
			array( 'data' => array() ),                         // charge lookup: no payments
			$this->invoice(),                                   // the re-read afterwards
		);

		$result = law_stripe_reconcile_settle( $event, array( 'notify' => false ) );

		$this->assertNotInstanceOf( WP_Error::class, $result, 'Expected the payment to settle.' );
		$this->assertSame( 'paid', law_event_meta( $event, '_law_payment_status' ) );
		$this->assertSame(
			'publish',
			get_post_status( $event ),
			'Publication is what law_booking_guard_open() reads, so a repair that stopped at the meta would have fixed nothing.'
		);
	}

	public function test_suppressed_emails_are_logged_rather_than_silently_dropped(): void {
		$event = $this->make_invoiced_event();
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice(), array( 'data' => array() ), $this->invoice() );

		law_stripe_reconcile_settle( $event, array( 'notify' => false ) );

		$this->assertSame( array(), $this->mail_subjects(), 'A payment banked weeks ago must not email the host "thank you for your payment" today.' );
		$log = $this->log_text( $event );
		$this->assertStringContainsString( 'Email NOT sent (suppressed during payment reconciliation)', $log );
		$this->assertStringContainsString( 'contact the host by hand', $log );
		$this->assertSame( '', law_events_emails_muted(), 'The mute must not outlive the operation.' );
	}

	public function test_notify_sends_the_same_emails_a_live_payment_would(): void {
		$event = $this->make_invoiced_event();
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice(), array( 'data' => array() ), $this->invoice() );

		law_stripe_reconcile_settle( $event, array( 'notify' => true ) );

		$this->assertNotEmpty( $this->mail_subjects(), 'A delivery missed an hour ago owes the host their confirmation.' );
	}

	public function test_the_mute_is_lifted_even_when_the_operation_throws(): void {
		try {
			law_events_without_emails( 'a test', function () {
				throw new RuntimeException( 'boom' );
			} );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}
		$this->assertSame( '', law_events_emails_muted(), 'A fatal inside the callback must not leave the site unable to email anybody.' );
	}

	public function test_an_amount_that_disagrees_with_the_snapshot_is_logged_loudly_and_still_settled(): void {
		$event = $this->make_invoiced_event();
		$GLOBALS['law_test_stripe_queue'] = array(
			$this->invoice( array( 'amount_paid' => 100000 ) ),
			array( 'data' => array() ),
			$this->invoice( array( 'amount_paid' => 100000 ) ),
		);

		law_stripe_reconcile_settle( $event, array( 'notify' => false ) );

		$this->assertStringContainsString( 'AMOUNT MISMATCH', $this->log_text( $event ) );
		$this->assertSame( 'publish', get_post_status( $event ), 'The money genuinely arrived, so a mismatch reports; it does not block.' );
	}

	public function test_settling_refuses_anything_that_is_not_an_unrecorded_payment(): void {
		$event = $this->make_invoiced_event( 'paid', 'publish' );
		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice( array( 'status' => 'open', 'amount_paid' => 0 ) ) );

		$result = law_stripe_reconcile_settle( $event, array( 'notify' => false ) );

		$this->assertWPError( $result, 'law_reconcile_not_settleable' );
	}

	public function test_a_legacy_event_gets_its_invoice_and_customer_ids_recorded_as_it_settles(): void {
		$event = $this->make_legacy_event();
		$search = array(
			'object' => 'search_result',
			'data'   => array( $this->invoice( array( 'id' => 'in_LEGACY' ) ) ),
		);
		$GLOBALS['law_test_stripe_queue'] = array(
			$search,                                              // check: invoice search
			$this->invoice( array( 'id' => 'in_LEGACY' ) ),       // check: the invoice itself
			array( 'data' => array() ),                           // charge lookup
			$this->invoice( array( 'id' => 'in_LEGACY' ) ),       // re-read: now by stored ID
		);

		$result = law_stripe_reconcile_settle( $event, array( 'notify' => false ) );

		$this->assertNotInstanceOf( WP_Error::class, $result, 'Expected the payment to settle.' );
		$this->assertSame( 'in_LEGACY', law_event_meta( $event, '_law_stripe_invoice_id' ) );
		$this->assertSame( 'cus_TEST', law_event_meta( $event, '_law_stripe_customer_id' ) );
		$this->assertSame( 'publish', get_post_status( $event ) );
		$this->assertStringContainsString( 'Payment reconciliation', $this->log_text( $event ) );
	}

	/* The panel _____________________________________________________________ */

	public function test_the_panel_scan_lists_only_events_that_could_be_owed_money(): void {
		$unpaid    = $this->make_invoiced_event();
		$legacy    = $this->make_legacy_event( 1300 );
		$paid      = $this->make_invoiced_event( 'paid', 'publish' );
		$free      = $this->make_invoiced_event( 'free', 'publish', 0 );
		$no_invoice = $this->make_event( array( '_law_payment_status' => 'unpaid' ), 'law-approved' );

		$ids = wp_list_pluck( law_events_reconcile_scan(), 'event_id' );

		$this->assertContains( $unpaid, $ids );
		$this->assertContains( $legacy, $ids );
		$this->assertNotContains( $paid, $ids, 'An event already reading paid is the sweep\'s business, the other way round.' );
		$this->assertNotContains( $free, $ids );
		$this->assertNotContains( $no_invoice, $ids, 'No invoice, nothing to reconcile against.' );
	}

	public function test_the_panel_refuses_an_event_that_has_settled_since_the_page_rendered(): void {
		$event = $this->make_invoiced_event( 'paid', 'publish' );

		$result = law_events_reconcile_apply( array( $event ), false );

		$this->assertSame( 0, $result['settled'] );
		$this->assertStringContainsString( 'no longer qualifies', $result['skipped'][0] );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Refused before it costs a Stripe call.' );
	}

	/* The daily sweep _______________________________________________________ */

	public function test_the_sweep_watches_only_events_holding_an_invoice_id(): void {
		$module = $this->make_invoiced_event();
		$legacy = $this->make_legacy_event( 1301 );

		$ids = array_map( 'intval', law_stripe_reconcile_sweep_candidates() );

		$this->assertContains( $module, $ids );
		$this->assertNotContains(
			$legacy,
			$ids,
			'A legacy event costs a search and can come back ambiguous; it belongs to the panel until the ID repair has run.'
		);
	}

	public function test_the_sweep_settles_a_missed_delivery_and_emails(): void {
		$event = $this->make_invoiced_event();
		// Four reads: the sweep's own check, the check law_stripe_reconcile_settle()
		// deliberately repeats rather than trusting one taken a moment ago, the
		// charge lookup, and the re-read it reports from. Two of those are the
		// price of the re-check guarantee, and it is only paid on an event that
		// actually settles.
		$GLOBALS['law_test_stripe_queue'] = array(
			$this->invoice(),
			$this->invoice(),
			array( 'data' => array() ),
			$this->invoice(),
		);

		$summary = law_stripe_reconcile_run_sweep();

		$this->assertSame( 1, $summary['settled'] );
		$this->assertSame( 'publish', get_post_status( $event ) );
		$this->assertNotEmpty( $this->mail_subjects(), 'A delivery missed hours ago should still confirm the host.' );
	}

	public function test_a_discrepancy_needing_a_human_is_raised_once_not_every_morning(): void {
		$event = $this->make_invoiced_event( 'paid', 'publish' );

		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice( array( 'status' => 'void', 'amount_paid' => 0 ) ) );
		$first = law_stripe_reconcile_run_sweep();

		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice( array( 'status' => 'void', 'amount_paid' => 0 ) ) );
		$second = law_stripe_reconcile_run_sweep();

		$this->assertSame( 1, $first['flagged'] );
		$this->assertSame( 0, $second['flagged'], 'An alert that arrives every day until somebody has time is an alert everybody deletes.' );
		$this->assertSame( 'recorded_paid_written_off', get_post_meta( $event, LAW_RECONCILE_FLAG_META, true ) );
		$this->assertStringContainsString( 'PAYMENT DISCREPANCY', $this->log_text( $event ) );
	}

	public function test_a_resolved_discrepancy_can_raise_itself_again(): void {
		$event = $this->make_invoiced_event( 'paid', 'publish' );
		update_post_meta( $event, LAW_RECONCILE_FLAG_META, 'recorded_paid_written_off' );

		$GLOBALS['law_test_stripe_queue'] = array( $this->invoice() );
		law_stripe_reconcile_run_sweep();

		$this->assertSame( '', (string) get_post_meta( $event, LAW_RECONCILE_FLAG_META, true ) );
	}

	public function test_the_sweep_can_be_switched_off(): void {
		$this->make_invoiced_event();
		update_option( LAW_RECONCILE_ENABLED_OPTION, 0 );

		$summary = law_stripe_reconcile_run_sweep();

		$this->assertSame( 0, $summary['checked'] );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'] );
	}
}
