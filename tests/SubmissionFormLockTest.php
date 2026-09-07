<?php
/**
 * submission-form.php: the per-status/per-user field lock list, committee
 * lock bypass on save (everything except fees/invoice), the host locks
 * unchanged, the committee edit's no-resubmit guarantee and its logging
 * (committee front-end editing, 7 September 2026).
 */
class SubmissionFormLockTest extends LAW_Test_Case {

	private const HOST_LOCKS = array( 'title', 'type', 'preferred_slots', 'fee_tier', 'invoice', 'sectors', 'host_organisations', 'venue_capacity', 'venue_needed' );

	/** A complete, valid non-draft form input for an existing event. */
	private function valid_input( array $overrides = array() ): array {
		return array_merge(
			array(
				'law_form_action'     => 'update',
				'event_title'         => 'Edited title',
				'description'         => 'Edited description.',
				'event_type'          => 'Social event',
				'host_organisations'  => 'Edited Org LLP',
				'preferred_slots'     => array( 'Any slot' ),
				'sectors'             => array(),
				'venue_needed'        => 'Yes, please share our details with venue hosts',
				'fee_tier'            => 'uk',
				'invoice_name'        => 'Edited Contact',
				'invoice_email'       => 'edited-invoice@example.test',
				'invoice_line1'       => '1 Edited Street',
				'invoice_city'        => 'London',
				'invoice_postal_code' => 'EC1A 1AA',
				'invoice_country'     => 'United Kingdom',
			),
			$overrides
		);
	}

	public function test_locked_fields_matrix(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();

		$this->assertSame( array(), law_events_locked_fields( null, $host ) );

		foreach ( array( 'law-draft', 'law-proposed', 'law-sent-back' ) as $status ) {
			$event = get_post( $this->make_event( array(), $status, $host ) );
			$this->assertSame( array(), law_events_locked_fields( $event, $host ), "Host on $status" );
			$this->assertSame( array(), law_events_locked_fields( $event, $committee ), "Committee on $status" );
		}

		foreach ( array( 'law-approved', 'publish' ) as $status ) {
			$event = get_post( $this->make_event( array(), $status, $host ) );
			$this->assertSame( self::HOST_LOCKS, law_events_locked_fields( $event, $host ), "Host on $status" );
			$this->assertSame( array( 'fee_tier', 'invoice' ), law_events_locked_fields( $event, $committee ), "Committee on $status" );
		}

		// No explicit user: falls back to the current user.
		wp_set_current_user( $committee );
		$approved = get_post( $this->make_event( array(), 'law-approved', $host ) );
		$this->assertSame( array( 'fee_tier', 'invoice' ), law_events_locked_fields( $approved ) );
	}

	public function test_committee_save_bypasses_locks_except_fees(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );

		$event_id = $this->make_event( array( '_law_fee_tier' => 'uk' ), 'law-approved', $host );
		$post     = get_post( $event_id );
		$original_email = law_event_meta( $event_id, '_law_invoice_email' );

		$result = law_events_form_save(
			$this->valid_input( array( 'fee_tier' => 'sponsor', 'invoice_email' => 'attacker@example.test' ) ),
			array(),
			$post,
			$committee
		);
		$this->assertSame( $event_id, $result );

		// Unlocked for committee: title and type persist.
		$this->assertSame( 'Edited title', get_post( $event_id )->post_title );
		$types = wp_get_object_terms( $event_id, 'law_event_type', array( 'fields' => 'names' ) );
		$this->assertContains( 'Social event', $types );

		// Locked for everyone: the fee tier and invoice details are untouched.
		$this->assertSame( 'uk', law_event_meta( $event_id, '_law_fee_tier' ) );
		$this->assertSame( $original_email, law_event_meta( $event_id, '_law_invoice_email' ) );
	}

	public function test_host_locks_unchanged_on_approved_event(): void {
		$host = $this->make_user( 'event_host' );
		wp_set_current_user( $host );

		$event_id       = $this->make_event( array( '_law_fee_tier' => 'uk' ), 'law-approved', $host );
		$original_title = get_post( $event_id )->post_title;

		$result = law_events_form_save(
			$this->valid_input( array( 'fee_tier' => 'sponsor', 'invoice_email' => 'changed@example.test' ) ),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertSame( $event_id, $result );

		// The locked title is carried forward, never blanked or replaced.
		$this->assertSame( $original_title, get_post( $event_id )->post_title );
		$this->assertSame( 'uk', law_event_meta( $event_id, '_law_fee_tier' ) );
		// Description stays editable for hosts.
		$this->assertSame( 'Edited description.', get_post( $event_id )->post_content );
	}

	public function test_committee_update_does_not_resubmit_sent_back_event(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );

		$event_id = $this->make_event( array(), 'law-sent-back', $host );

		$url = law_events_form_result_redirect( $event_id, 'update', $committee, true );

		$this->assertSame( 'law-sent-back', get_post_status( $event_id ), 'A committee save never resubmits.' );
		$this->assertStringContainsString( '/account/dashboard/', $url );
		$this->assertStringContainsString( 'event=' . $event_id, $url );
		$this->assertStringContainsString( 'law_notice=saved', $url );

		// Even a drifted action value cannot resubmit from the committee form.
		law_events_form_result_redirect( $event_id, 'submit', $committee, true );
		$this->assertSame( 'law-sent-back', get_post_status( $event_id ) );
	}

	public function test_host_explicit_submit_still_resubmits_sent_back_event(): void {
		$host = $this->make_user( 'event_host' );
		wp_set_current_user( $host );

		$event_id = $this->make_event( array(), 'law-sent-back', $host );

		// A plain update no longer resubmits (the pre-fix behaviour resubmitted
		// on ANY save of a sent-back event)…
		$url = law_events_form_result_redirect( $event_id, 'update', $host, false );
		$this->assertSame( 'law-sent-back', get_post_status( $event_id ) );
		$this->assertStringContainsString( '/account/events/', $url );

		// …but the explicit "Save & resubmit" button does.
		law_events_form_result_redirect( $event_id, 'submit', $host, false );
		$this->assertSame( 'law-proposed', get_post_status( $event_id ) );
	}

	public function test_committee_edit_is_logged_and_host_edit_alert_suppressed(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );

		$event_id = $this->make_event( array( '_law_fee_tier' => 'uk' ), 'law-approved', $host );

		law_events_form_save( $this->valid_input(), array(), get_post( $event_id ), $committee );

		$log = implode( "\n", array_map(
			static fn( $entry ) => $entry->comment_content,
			law_event_log_entries( $event_id )
		) );
		$this->assertStringContainsString( 'Event details updated by the committee.', $log );
		$this->assertStringNotContainsString( 'Event updated by the host after approval.', $log );
		// law_events_send() logs every send; no send means no email log line.
		$this->assertStringNotContainsString( 'committee_event_updated', $log );
	}
}
