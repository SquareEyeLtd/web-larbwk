<?php
/**
 * The cancel and withdraw workflow actions and the trash/untrash round trip:
 * guards (who, from-status, required reason), the Stripe invoice-void
 * sequencing on cancel (delete a draft, void an open invoice, leave a paid
 * one alone, never block on failure), the draft-withdrawal email skip, and
 * law-cancelled surviving trash → untrash.
 */
class CancelWithdrawTest extends LAW_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		// Committee emails so committee-audience sends resolve and get logged.
		law_events_update_settings( array( 'committee_emails' => array( 'committee@example.test' ) ) );
	}

	private function log_text( int $event_id ): string {
		return implode( "\n", wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' ) );
	}

	public function test_cancel_guards(): void {
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );

		// Wrong from-status: a proposed event is rejected, not cancelled.
		$proposed = $this->make_event();
		$result   = law_event_workflow_transition( $proposed, 'cancel', array( 'reason' => 'Why.' ) );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_bad_transition', $result->get_error_code() );

		// Reason required.
		$approved  = $this->make_event( array(), 'law-approved' );
		$no_reason = law_event_workflow_transition( $approved, 'cancel', array( 'reason' => ' ' ) );
		$this->assertInstanceOf( WP_Error::class, $no_reason );
		$this->assertSame( 'law_reason_required', $no_reason->get_error_code() );

		// Committee only.
		$host = $this->make_user( 'event_host' );
		wp_set_current_user( $host );
		$their_own = $this->make_event( array(), 'publish', $host );
		$not_theirs = law_event_workflow_transition( $their_own, 'cancel', array( 'reason' => 'Why.' ) );
		$this->assertInstanceOf( WP_Error::class, $not_theirs );
		$this->assertSame( 'law_not_allowed', $not_theirs->get_error_code() );
	}

	public function test_cancel_from_approved_and_confirmed_stores_reason_and_comments(): void {
		wp_set_current_user( $this->make_committee_user() );

		foreach ( array( 'law-approved', 'publish' ) as $from ) {
			$event = $this->make_event( array(), $from );
			$this->assertTrue( law_event_workflow_transition( $event, 'cancel', array( 'reason' => 'Venue fell through.' ) ) );
			$this->assertSame( 'law-cancelled', get_post_status( $event ) );
			$this->assertSame( 'Venue fell through.', law_event_meta( $event, '_law_cancellation_reason' ) );
			$comments = law_event_comments( $event );
			$this->assertCount( 1, $comments );
			$this->assertSame( 'Venue fell through.', $comments[0]->comment_content );
			// No invoice on record: the void helper logs and makes no Stripe call.
			$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'] );
			$this->assertStringContainsString( 'no Stripe invoice on record', $this->log_text( $event ) );
		}
	}

	public function test_cancel_voids_an_open_invoice(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_stripe_invoice_id' => 'in_open1' ), 'law-approved' );
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_open1', 'status' => 'open' ),  // GET the invoice
			array( 'id' => 'in_open1', 'status' => 'void' ),  // POST /void
		);

		$this->assertTrue( law_event_workflow_transition( $event, 'cancel', array( 'reason' => 'Cancelled.' ) ) );

		$this->assertSame(
			array(
				array( 'method' => 'GET', 'path' => '/v1/invoices/in_open1' ),
				array( 'method' => 'POST', 'path' => '/v1/invoices/in_open1/void' ),
			),
			$GLOBALS['law_test_stripe_calls']
		);
		$this->assertStringContainsString( 'voided after cancellation', $this->log_text( $event ) );
		// The audit trail keeps the invoice ID.
		$this->assertSame( 'in_open1', law_event_meta( $event, '_law_stripe_invoice_id' ) );
	}

	public function test_cancel_deletes_a_draft_invoice(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_stripe_invoice_id' => 'in_draft1' ), 'law-approved' );
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_draft1', 'status' => 'draft' ),
			array( 'id' => 'in_draft1', 'deleted' => true ),
		);

		$this->assertTrue( law_event_workflow_transition( $event, 'cancel', array( 'reason' => 'Cancelled.' ) ) );

		$this->assertSame(
			array(
				array( 'method' => 'GET', 'path' => '/v1/invoices/in_draft1' ),
				array( 'method' => 'DELETE', 'path' => '/v1/invoices/in_draft1' ),
			),
			$GLOBALS['law_test_stripe_calls']
		);
	}

	public function test_cancel_leaves_a_paid_invoice_and_alerts_the_committee(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event(
			array( '_law_stripe_invoice_id' => 'in_paid1', '_law_payment_status' => 'paid' ),
			'publish'
		);
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_paid1', 'status' => 'paid' ),
		);

		$this->assertTrue( law_event_workflow_transition( $event, 'cancel', array( 'reason' => 'Cancelled.' ) ) );

		// One read, no mutation.
		$this->assertSame(
			array( array( 'method' => 'GET', 'path' => '/v1/invoices/in_paid1' ) ),
			$GLOBALS['law_test_stripe_calls']
		);
		$this->assertSame( 'paid', law_event_meta( $event, '_law_payment_status' ), 'Never auto-refunded.' );
		$log = $this->log_text( $event );
		$this->assertStringContainsString( 'left untouched', $log );
		$this->assertStringContainsString( 'cancelled event had been paid', $log, 'The manual-refund alert was sent.' );
	}

	public function test_a_failed_void_never_blocks_the_cancellation(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_stripe_invoice_id' => 'in_err1' ), 'law-approved' );
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'in_err1', 'status' => 'open' ),
			new WP_Error( 'law_stripe_api_error', 'Simulated void failure' ),
		);

		$this->assertTrue( law_event_workflow_transition( $event, 'cancel', array( 'reason' => 'Cancelled.' ) ) );

		$this->assertSame( 'law-cancelled', get_post_status( $event ) );
		$log = $this->log_text( $event );
		$this->assertStringContainsString( 'could not be voided after cancellation', $log );
		$this->assertStringContainsString( 'Simulated void failure', $log );
	}

	public function test_withdraw_owner_only_and_pre_approval_only(): void {
		$host = $this->make_user( 'event_host' );

		// The owner can withdraw from all three pre-approval statuses.
		foreach ( array( 'law-draft', 'law-proposed', 'law-sent-back' ) as $from ) {
			wp_set_current_user( $host );
			$event = $this->make_event( array(), $from, $host );
			$this->assertTrue( law_event_workflow_transition( $event, 'withdraw' ) );
			$this->assertSame( 'law-cancelled', get_post_status( $event ) );
		}

		// Not from approved or published.
		foreach ( array( 'law-approved', 'publish' ) as $from ) {
			$event  = $this->make_event( array(), $from, $host );
			$result = law_event_workflow_transition( $event, 'withdraw' );
			$this->assertInstanceOf( WP_Error::class, $result );
			$this->assertSame( 'law_bad_transition', $result->get_error_code() );
		}

		// Not someone else's event.
		$other = $this->make_user( 'event_host' );
		wp_set_current_user( $other );
		$event  = $this->make_event( array(), 'law-proposed', $host );
		$result = law_event_workflow_transition( $event, 'withdraw' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_not_allowed', $result->get_error_code() );
	}

	public function test_withdraw_emails_the_committee_except_for_drafts(): void {
		$host = $this->make_user( 'event_host' );
		wp_set_current_user( $host );

		$proposed = $this->make_event( array(), 'law-proposed', $host );
		law_event_workflow_transition( $proposed, 'withdraw', array( 'reason' => 'Speaker unavailable.' ) );
		$this->assertStringContainsString( 'event withdrawn by host', $this->log_text( $proposed ) );
		$this->assertSame( 'Speaker unavailable.', law_event_meta( $proposed, '_law_cancellation_reason' ) );
		$comments = law_event_comments( $proposed );
		$this->assertCount( 1, $comments );

		// A withdrawn draft was never submitted: the committee is not emailed.
		$draft = $this->make_event( array(), 'law-draft', $host );
		law_event_workflow_transition( $draft, 'withdraw' );
		$this->assertSame( 'law-cancelled', get_post_status( $draft ) );
		$this->assertStringNotContainsString( 'event withdrawn by host', $this->log_text( $draft ) );
	}

	public function test_available_ui_actions_offer_cancel(): void {
		$confirmed = $this->make_event( array(), 'publish' );
		$this->assertSame( array( 'cancel' ), law_event_available_ui_actions( $confirmed ) );

		$approved = $this->make_event( array(), 'law-approved' );
		$this->assertSame( array( 'mark_paid', 'cancel' ), law_event_available_ui_actions( $approved ) );

		// Terminal statuses offer nothing (Delete is not a workflow action).
		$cancelled = $this->make_event( array(), 'law-cancelled' );
		$this->assertSame( array(), law_event_available_ui_actions( $cancelled ) );
	}

	public function test_cancelled_survives_the_status_guard_and_trash_round_trip(): void {
		$event = $this->make_event( array(), 'law-cancelled' );

		// The status guard: a direct update cannot move a cancelled event.
		wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $event ) );

		// Trash → untrash restores law-cancelled, not core's draft.
		wp_trash_post( $event );
		$this->assertSame( 'trash', get_post_status( $event ) );
		wp_untrash_post( $event );
		$this->assertSame( 'law-cancelled', get_post_status( $event ) );
	}
}
