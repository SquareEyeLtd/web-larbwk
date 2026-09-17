<?php
/**
 * stripe/service.php: partial mid-flow failure followed by a retry must never
 * produce a second invoice (the double-billing guard), leftover drafts are
 * cleaned up before a fresh create, and a post-approval host fee change voids
 * the invoice raised from the old snapshot before raising its replacement.
 *
 * Mail is captured on the 'wp_mail' filter, which runs before the pre_wp_mail
 * short-circuit the bootstrap installs, so nothing is sent.
 */
class ServiceTest extends LAW_Test_Case {

	/** @var array<int,array> Captured wp_mail() arguments. */
	private array $mail = array();
	private $mail_filter;

	protected function setUp(): void {
		parent::setUp();
		// The "payment due" wording is asserted below, and this site's Emails
		// screen carries a stored override of it (migration step 9 imported the
		// Gravity Forms notifications as overrides). Blanking just that entry
		// in memory puts the registry default back for the duration of the
		// test, so what is asserted is the code rather than one environment's
		// edit — and nothing the test does reaches the real option.
		$this->isolate_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array( 'user_payment_due' => array() ) );

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

	/**
	 * An approved, unpaid event holding one open Stripe invoice, with a real
	 * host account: the "payment due" email goes to the post author, and an
	 * authorless fixture would have no recipient at all.
	 */
	private function make_invoiced_event( string $status = 'law-approved' ): int {
		$event = $this->make_event(
			array(
				'_law_fee_tier'          => 'uk',
				'_law_approved_at'       => '2026-09-16',
				'_law_payment_status'    => 'unpaid',
				'_law_stripe_customer_id' => 'cus_1',
			),
			$status,
			$this->make_user()
		);
		law_event_snapshot_fee( $event );
		update_post_meta( $event, '_law_stripe_invoice_id', 'in_OLD' );
		update_post_meta( $event, '_law_stripe_invoice_url', 'https://invoice.stripe.com/i/old' );
		return $event;
	}

	/** The subject lines of every email captured so far. */
	private function mail_subjects(): array {
		return wp_list_pluck( $this->mail, 'subject' );
	}

	public function test_retry_after_send_failure_resumes_the_same_invoice(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'uk' ) );
		law_event_snapshot_fee( $event );

		// Attempt 1: invoice created and line added, then /send times out.
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'object' => 'list', 'data' => array() ),          // customer search
			array( 'id' => 'cus_1', 'object' => 'customer' ),        // create customer
			array( 'id' => 'in_ORIGINAL', 'object' => 'invoice' ),   // create invoice
			array( 'id' => 'ii_1', 'object' => 'invoiceitem' ),      // line item
			new WP_Error( 'law_stripe_api_error', 'send timed out' ), // send fails
		);
		$first = law_stripe_create_and_send_invoice( $event );
		$this->assertInstanceOf( WP_Error::class, $first );
		$this->assertSame( 'in_ORIGINAL', law_event_meta( $event, '_law_stripe_invoice_id' ), 'The invoice ID is persisted before send, so the retry can find it.' );

		// Attempt 2 (retry): the stored invoice is now OPEN at Stripe (the
		// send actually landed despite the timeout). The retry must RESUME it,
		// making no POST /v1/invoices call at all.
		$GLOBALS['law_test_stripe_calls'] = array(); // Assert attempt 2 only.
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_1', 'object' => 'customer' ),        // customer update (stored ID)
			array( 'id' => 'in_ORIGINAL', 'object' => 'invoice', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/original' ), // GET existing
		);
		$second = law_stripe_create_and_send_invoice( $event );
		$this->assertIsArray( $second );
		$this->assertSame( 'in_ORIGINAL', $second['id'] );
		$this->assertSame( 'https://invoice.stripe.com/i/original', law_event_meta( $event, '_law_stripe_invoice_url' ) );

		$paths = wp_list_pluck( $GLOBALS['law_test_stripe_calls'], 'path' );
		$this->assertNotContains( '/v1/invoiceitems', $paths, 'No second line item.' );
		$creates = array_filter(
			$GLOBALS['law_test_stripe_calls'],
			fn( $c ) => 'POST' === $c['method'] && '/v1/invoices' === $c['path']
		);
		$this->assertCount( 0, $creates, 'The retry created NO new invoice: no double billing.' );
	}

	public function test_retry_deletes_a_leftover_draft_and_creates_fresh(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'international' ) );
		law_event_snapshot_fee( $event );
		update_post_meta( $event, '_law_stripe_invoice_id', 'in_DRAFT' );
		update_post_meta( $event, '_law_stripe_customer_id', 'cus_1' );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_1', 'object' => 'customer' ),        // customer update
			array( 'id' => 'in_DRAFT', 'object' => 'invoice', 'status' => 'draft' ), // GET existing → draft
			array( 'id' => 'in_DRAFT', 'deleted' => true ),          // DELETE draft
			array( 'id' => 'in_FRESH', 'object' => 'invoice' ),      // create fresh
			array( 'id' => 'ii_2', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_FRESH', 'object' => 'invoice' ),      // send
			array( 'id' => 'in_FRESH', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/fresh' ),
		);
		$result = law_stripe_create_and_send_invoice( $event );
		$this->assertIsArray( $result );
		$this->assertSame( 'in_FRESH', law_event_meta( $event, '_law_stripe_invoice_id' ) );

		$deletes = array_filter(
			$GLOBALS['law_test_stripe_calls'],
			fn( $c ) => 'DELETE' === $c['method'] && str_contains( $c['path'], 'in_DRAFT' )
		);
		$this->assertCount( 1, $deletes, 'The abandoned draft was deleted, not left to become a second bill.' );
	}

	/**
	 * The whole point of the change (Denis, 17 September 2026): the committee
	 * agrees a different fee after approval, and all three halves of the job
	 * happen — the old invoice is voided, the snapshot is re-frozen, and a new
	 * invoice is raised and emailed.
	 */
	public function test_a_post_approval_fee_change_voids_and_reissues(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_invoiced_event();
		$this->assertSame( 120000, (int) law_event_meta( $event, '_law_fee_pence' ) );

		// The committee agrees £600.
		law_event_update_meta( $event, '_law_fee_override', 1 );
		law_event_update_meta( $event, '_law_fee_override_amount', 600 );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_OLD', 'object' => 'invoice', 'status' => 'open' ), // void: GET
			array( 'id' => 'in_OLD', 'object' => 'invoice', 'status' => 'void' ), // void: POST /void
			array( 'id' => 'cus_1', 'object' => 'customer' ),                     // customer update
			array( 'id' => 'in_OLD', 'object' => 'invoice', 'status' => 'void' ), // resume check → void, fall through
			array( 'id' => 'in_NEW', 'object' => 'invoice' ),                     // create
			array( 'id' => 'ii_1', 'object' => 'invoiceitem' ),                   // line item
			array( 'id' => 'in_NEW', 'object' => 'invoice' ),                     // send
			array( 'id' => 'in_NEW', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/new' ),
		);

		$result = law_event_apply_fee_change( $event, get_current_user_id(), 'ui' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'reissued', $result['outcome'] );
		$this->assertSame( 120000, $result['was'] );
		$this->assertSame( 60000, $result['fee_pence'] );

		// The snapshot, which the exports, the Fee column and {fee} all read.
		$this->assertSame( 60000, (int) law_event_meta( $event, '_law_fee_pence' ) );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_vat' ) );

		// The old invoice was voided BEFORE the new one was created: the host
		// must never hold two payable invoices for the same event.
		$paths = array_map(
			fn( $c ) => $c['method'] . ' ' . $c['path'],
			$GLOBALS['law_test_stripe_calls']
		);
		$void_at   = array_search( 'POST /v1/invoices/in_OLD/void', $paths, true );
		$create_at = array_search( 'POST /v1/invoices', $paths, true );
		$this->assertIsInt( $void_at, 'The old invoice was voided.' );
		$this->assertIsInt( $create_at, 'A replacement invoice was created.' );
		$this->assertLessThan( $create_at, $void_at, 'Void first, create second.' );

		// And the event now points at the replacement.
		$this->assertSame( 'in_NEW', law_event_meta( $event, '_law_stripe_invoice_id' ) );
		$this->assertSame( 'https://invoice.stripe.com/i/new', law_event_meta( $event, '_law_stripe_invoice_url' ) );
		$this->assertSame( 'unpaid', law_event_meta( $event, '_law_payment_status' ) );

		// The host is told, by the SAME "payment due" email, carrying the line
		// that says which invoice has been cancelled.
		$due = array_values( array_filter( $this->mail, fn( $m ) => str_contains( (string) $m['subject'], 'payment due' ) ) );
		$this->assertCount( 1, $due, 'One payment-due email: ' . implode( ' | ', $this->mail_subjects() ) );
		$this->assertStringContainsString( 'replaces the earlier invoice', $due[0]['message'] );
		$this->assertStringContainsString( '£1,200.00', $due[0]['message'], 'It names the invoice it replaces.' );
		// And the NEW figure: the stored LAW body names no amount at all, so a
		// note carrying only the old one would leave the host guessing.
		$this->assertStringContainsString( 'changed to £600.00', $due[0]['message'] );
	}

	/**
	 * Waiving the fee raises nothing at all: an Approved event goes Free and is
	 * confirmed in the same breath, exactly as approving it at zero would have
	 * done. Nobody is routed through a payment step for £0.
	 */
	public function test_waiving_the_fee_voids_the_invoice_and_confirms(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_invoiced_event();

		law_event_update_meta( $event, '_law_fee_override', 1 );
		law_event_update_meta( $event, '_law_fee_override_amount', 0 );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_OLD', 'object' => 'invoice', 'status' => 'open' ),
			array( 'id' => 'in_OLD', 'object' => 'invoice', 'status' => 'void' ),
		);

		$result = law_event_apply_fee_change( $event, get_current_user_id(), 'ui' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'waived', $result['outcome'] );
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_fee_pence' ) );
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_vat' ) );
		$this->assertSame( 'free', law_event_meta( $event, '_law_payment_status' ) );
		$this->assertSame( 'publish', get_post_status( $event ), 'A free event does not wait for a payment that is not coming.' );

		$creates = array_filter(
			$GLOBALS['law_test_stripe_calls'],
			fn( $c ) => 'POST' === $c['method'] && '/v1/invoices' === $c['path']
		);
		$this->assertCount( 0, $creates, 'No invoice is raised for £0.' );
		$this->assertEmpty(
			array_filter( $this->mail_subjects(), fn( $s ) => str_contains( (string) $s, 'payment due' ) ),
			'And no "payment due" email for £0.'
		);
	}

	/**
	 * A void that fails leaves the old invoice payable, so a second one must
	 * not join it: nothing is written and the snapshot stays as it was.
	 */
	public function test_a_failed_void_abandons_the_fee_change(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_invoiced_event();

		law_event_update_meta( $event, '_law_fee_override', 1 );
		law_event_update_meta( $event, '_law_fee_override_amount', 600 );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_OLD', 'object' => 'invoice', 'status' => 'open' ),
			new WP_Error( 'law_stripe_api_error', 'void failed' ),
		);

		$result = law_event_apply_fee_change( $event, get_current_user_id(), 'ui' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_void_failed', $result->get_error_code() );
		$this->assertSame( 120000, (int) law_event_meta( $event, '_law_fee_pence' ), 'The snapshot is untouched.' );
		$this->assertSame( 'in_OLD', law_event_meta( $event, '_law_stripe_invoice_id' ), 'The original invoice is still the one to pay.' );
	}

	/** Once the money has moved the fee is a bookkeeping record. */
	public function test_a_settled_fee_is_never_reissued(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_invoiced_event( 'publish' );
		update_post_meta( $event, '_law_payment_status', 'paid' );

		law_event_update_meta( $event, '_law_fee_override', 1 );
		law_event_update_meta( $event, '_law_fee_override_amount', 600 );

		$result = law_event_apply_fee_change( $event, get_current_user_id(), 'ui' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_fee_not_reissuable', $result->get_error_code() );
		$this->assertSame( 120000, (int) law_event_meta( $event, '_law_fee_pence' ) );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Stripe is never called.' );
	}

	/**
	 * Ticking "override" at exactly the tier price moves the flag but not the
	 * money. Voiding a live invoice for a figure that has not changed would
	 * leave the host with a second invoice for the same amount.
	 */
	public function test_a_fee_that_has_not_moved_touches_nothing(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_invoiced_event();

		law_event_update_meta( $event, '_law_fee_override', 1 );
		law_event_update_meta( $event, '_law_fee_override_amount', 1200 );

		$result = law_event_apply_fee_change( $event, get_current_user_id(), 'ui' );
		$this->assertIsArray( $result );
		$this->assertSame( 'unchanged', $result['outcome'] );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Stripe is never called.' );
		$this->assertSame( 'in_OLD', law_event_meta( $event, '_law_stripe_invoice_id' ) );
	}

	/**
	 * A pre-rebuild invoice recorded as a web address only cannot be voided,
	 * and raising a replacement beside it would bill the host twice. LAW →
	 * Migration has the repair; until it has run, the change is refused.
	 */
	public function test_a_legacy_invoice_with_no_id_refuses_the_change(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_invoiced_event();
		delete_post_meta( $event, '_law_stripe_invoice_id' );

		law_event_update_meta( $event, '_law_fee_override', 1 );
		law_event_update_meta( $event, '_law_fee_override_amount', 600 );

		$result = law_event_apply_fee_change( $event, get_current_user_id(), 'ui' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_legacy_invoice', $result->get_error_code() );
		$this->assertSame( 120000, (int) law_event_meta( $event, '_law_fee_pence' ) );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'] );
	}

	/**
	 * A waiver the committee wants back: a Free event has no settled money, so
	 * a fee can be put on it, and the payment status has to leave Free or the
	 * dashboard would read Free against a live invoice.
	 */
	public function test_a_free_event_can_be_given_a_fee_again(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event(
			array(
				'_law_fee_tier'           => 'uk',
				'_law_fee_override'       => 1,
				'_law_fee_override_amount' => 0,
				'_law_payment_status'     => 'free',
				'_law_stripe_customer_id' => 'cus_1',
			),
			'publish'
		);
		law_event_snapshot_fee( $event );
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_fee_pence' ) );

		law_event_update_meta( $event, '_law_fee_override', 0 ); // Back to the UK tier.

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_1', 'object' => 'customer' ),
			array( 'id' => 'in_FIRST', 'object' => 'invoice' ),
			array( 'id' => 'ii_1', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_FIRST', 'object' => 'invoice' ),
			array( 'id' => 'in_FIRST', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/first' ),
		);

		$result = law_event_apply_fee_change( $event, get_current_user_id(), 'ui' );
		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'reissued', $result['outcome'] );
		$this->assertSame( 120000, (int) law_event_meta( $event, '_law_fee_pence' ) );
		$this->assertSame( 'unpaid', law_event_meta( $event, '_law_payment_status' ) );
		$this->assertSame( 'in_FIRST', law_event_meta( $event, '_law_stripe_invoice_id' ) );
	}
}
