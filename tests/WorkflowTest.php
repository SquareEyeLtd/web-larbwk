<?php
/**
 * workflow.php: transition guards (who, from which status), required inputs,
 * side effects fired exactly once (Stripe mocked), co-owner creation on
 * approve, and the zero-fee publish route (EVENTS_4.1_REBUILD.md §3.11).
 */
class WorkflowTest extends LAW_Test_Case {

	public function test_illegal_transitions_are_refused(): void {
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );

		$confirmed = $this->make_event( array(), 'publish' );
		$result    = law_event_workflow_transition( $confirmed, 'approve' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_bad_transition', $result->get_error_code() );
		$this->assertSame( 'publish', get_post_status( $confirmed ) );

		$rejected = $this->make_event( array(), 'law-rejected' );
		$this->assertInstanceOf( WP_Error::class, law_event_workflow_transition( $rejected, 'send_back' ) );

		$this->assertInstanceOf( WP_Error::class, law_event_workflow_transition( $confirmed, 'nonsense_action' ) );
	}

	public function test_committee_actions_refused_for_hosts(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );
		$event  = $this->make_event( array(), 'law-proposed', $host );
		$result = law_event_workflow_transition( $event, 'approve' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_not_allowed', $result->get_error_code() );
	}

	public function test_send_back_requires_comment_and_reject_requires_reason(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event();

		$no_comment = law_event_workflow_transition( $event, 'send_back', array( 'comment' => ' ' ) );
		$this->assertSame( 'law_comment_required', $no_comment->get_error_code() );

		$no_reason = law_event_workflow_transition( $event, 'reject', array( 'reason' => '' ) );
		$this->assertSame( 'law_reason_required', $no_reason->get_error_code() );

		$ok = law_event_workflow_transition( $event, 'send_back', array( 'comment' => 'Please clarify the venue.' ) );
		$this->assertTrue( $ok );
		$this->assertSame( 'law-sent-back', get_post_status( $event ) );
		$comments = law_event_comments( $event );
		$this->assertCount( 1, $comments );
		$this->assertSame( 'Please clarify the venue.', $comments[0]->comment_content );
	}

	public function test_approve_paid_snapshots_fee_and_invoices_once(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'uk' ) );
		$this->queue_invoice_success();

		$this->assertTrue( law_event_workflow_transition( $event, 'approve' ) );

		$this->assertSame( 'law-approved', get_post_status( $event ) );
		$this->assertSame( 120000, (int) law_event_meta( $event, '_law_fee_pence' ) );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_vat' ) );
		$this->assertNotEmpty( law_event_meta( $event, '_law_approved_at' ) );
		$this->assertSame( 'in_test1', law_event_meta( $event, '_law_stripe_invoice_id' ) );
		$this->assertSame( 'https://invoice.stripe.com/i/test123', law_event_meta( $event, '_law_stripe_invoice_url' ) );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_queue'], 'Exactly the queued Stripe calls were made, no more.' );

		// The paid path does not auto-publish: it waits for the webhook.
		$this->assertSame( 'unpaid', law_event_meta( $event, '_law_payment_status' ) );
	}

	public function test_approve_zero_fee_publishes_free_with_no_stripe_calls(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'sponsor' ) );

		$this->assertTrue( law_event_workflow_transition( $event, 'approve' ) );

		$this->assertSame( 'publish', get_post_status( $event ) );
		$this->assertSame( 'free', law_event_meta( $event, '_law_payment_status' ) );
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_fee_pence' ) );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Zero-fee events never touch Stripe.' );
	}

	/**
	 * Free is a first-class payment state, not a quieter kind of unpaid.
	 *
	 * Worth pinning, because until 16 September 2026 no event in the migrated
	 * data could hold it: _law_payment_status is declared on both the event
	 * and the booking, the merged schema handed law_event_update_meta() the
	 * BOOKING vocabulary, and an event's 'free' was rewritten to 'unpaid' on
	 * the way in (law_events_meta_type() now resolves it per post type).
	 */
	public function test_a_free_event_is_bookable_and_owes_nothing(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event(
			array(
				'_law_fee_tier'          => 'sponsor',
				'_law_venue'             => 'Guildhall, EC2V 7HH',
				'_law_venue_capacity'    => '101-150',
				'_law_tickets_available' => 120,
				'_law_start'             => gmdate( 'Y-m-d H:i', strtotime( '+30 days' ) ),
			)
		);

		$this->assertTrue( law_event_workflow_transition( $event, 'approve' ) );
		$this->assertSame( 'free', law_event_meta( $event, '_law_payment_status' ) );

		// Booking is gated on publication, which approving a zero-fee event
		// does in the same breath. Nothing reads the payment status to decide
		// whether a place may be taken.
		$this->assertSame( 'publish', get_post_status( $event ) );
		$this->assertSame( '', law_event_booking_hold_reason( $event ) );
		$this->assertTrue( law_booking_guard_open( $event ) );

		// The fee snapshot stays editable: 'free' is not a settled payment the
		// way 'paid' and 'refunded' are, because no invoice was ever raised.
		$this->assertNotInstanceOf( WP_Error::class, law_event_resnapshot_fee( $event ) );

		// And a fee change now reissues rather than being refused: Free means
		// no money has moved, so the committee can still put a fee back on.
		$this->assertSame( 'reissue', law_event_fee_edit_mode( $event ) );
	}

	/** What the migration derives for a Confirmed zero-fee event survives the write. */
	public function test_a_migrated_zero_fee_event_stores_free(): void {
		$event = $this->make_event( array( '_law_fee_tier' => 'sponsor' ), 'publish' );
		law_event_update_meta( $event, '_law_payment_status', law_migration_derive_payment( 'Confirmed', 0, '' ) );
		$this->assertSame( 'free', law_event_meta( $event, '_law_payment_status' ) );
	}

	public function test_stripe_failure_holds_at_approved_with_error_flag(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'uk' ) );
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'object' => 'list', 'data' => array() ), // The customer search succeeds…
			new WP_Error( 'law_stripe_api_error', 'Simulated Stripe outage' ), // …then create fails.
		);

		$this->assertTrue( law_event_workflow_transition( $event, 'approve' ) );

		$this->assertSame( 'law-approved', get_post_status( $event ), 'No silent completion (defect 4).' );
		$error = law_event_meta( $event, '_law_stripe_error' );
		$this->assertIsArray( $error );
		$this->assertStringContainsString( 'Simulated Stripe outage', $error['message'] );
	}

	public function test_co_owner_accounts_created_on_approval_only(): void {
		wp_set_current_user( $this->make_committee_user() );
		$email = 'law-test-coowner-' . wp_generate_password( 6, false ) . '@example.test';
		$event = $this->make_event( array(
			'_law_fee_tier'      => 'sponsor',
			'_law_co_owner_rows' => array(
				array( 'name' => 'Co Owner', 'organisation' => 'Test Org', 'email' => $email ),
			),
		) );

		$this->assertFalse( (bool) get_user_by( 'email', $email ), 'No account before approval.' );

		law_event_workflow_transition( $event, 'approve' );

		$user = get_user_by( 'email', $email );
		$this->assertNotFalse( $user, 'The account is created on approval.' );
		$this->users[] = $user->ID;
		$this->assertContains( 'subscriber', (array) $user->roles, 'Accounts the module creates are plain subscribers; access comes from the co-owner meta below.' );
		$this->assertContains( (int) $user->ID, array_map( 'intval', law_event_meta( $event, '_law_co_owner_ids' ) ) );
		$this->assertTrue( law_user_can_manage_event( $user->ID, $event ) );
	}

	public function test_reject_stores_reason_and_resubmit_returns_to_proposed(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event();
		law_event_workflow_transition( $event, 'reject', array( 'reason' => 'Out of scope.' ) );
		$this->assertSame( 'law-rejected', get_post_status( $event ) );
		$this->assertSame( 'Out of scope.', law_event_meta( $event, '_law_rejection_reason' ) );

		$host  = $this->make_user();
		$event2 = $this->make_event( array(), 'law-sent-back', $host );
		wp_set_current_user( $host );
		$this->assertTrue( law_event_workflow_transition( $event2, 'resubmit' ) );
		$this->assertSame( 'law-proposed', get_post_status( $event2 ) );
	}

	public function test_every_transition_writes_an_activity_log_entry(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'sponsor' ) );
		law_event_workflow_transition( $event, 'approve' );

		$log      = law_event_log_entries( $event );
		$messages = implode( "\n", wp_list_pluck( $log, 'comment_content' ) );
		$this->assertStringContainsString( 'Proposed to Approved', $messages );
		$this->assertStringContainsString( 'Approved to Confirmed', $messages );
		$this->assertStringContainsString( 'Fee snapshot', $messages );
		$this->assertStringContainsString( 'payment status set to Free', $messages );
	}
}
