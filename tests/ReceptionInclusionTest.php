<?php
/**
 * Receptions included with a confirmed flagship place (RECEPTIONS.md §7).
 *
 * The promise is narrow and load-bearing: a CONFIRMED flagship ticket includes
 * the receptions it says it does, nobody else's does, and a full reception
 * still honours it — the ticket promised the place, and the committee sizes
 * the room. What these pin is the ownership (a posted booking id must reach
 * nothing), the over-booking being loud rather than silent, and the places
 * going back with the ticket if it is refunded.
 */
class ReceptionInclusionTest extends LAW_Test_Case {

	private int $flagship = 0;

	protected function tearDown(): void {
		remove_all_filters( 'pre_option_law_events_source' );
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		$_POST = array();
		parent::tearDown();
	}

	private function make_reception( array $meta = array(), $status = 'publish' ): int {
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );

		return $this->make_event(
			array_merge(
				array(
					'_law_is_reception'         => 1,
					'_law_flagship_included'    => 1,
					'_law_start'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:30' ) ),
					'_law_tickets_available'    => 10,
					'_law_attendee_price_pence' => 4500,
					'_law_registration_state'   => 'open',
				),
				$meta
			),
			$status
		);
	}

	private function make_flagship(): int {
		$event_id = $this->make_event(
			array(
				'_law_is_flagship'          => 1,
				'_law_flagship_date'        => '2026-12-02',
				'_law_start'                => gmdate( 'Y-m-d H:i', strtotime( '+31 days 09:30' ) ),
				'_law_flagship_price_pence' => 55000,
				'_law_tickets_available'    => 50,
			),
			'publish'
		);
		$this->flagship = $event_id;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		law_flagship_event_id( true );

		return $event_id;
	}

	private function make_delegate(): int {
		$user_id = $this->make_user();
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Jane', 'last_name' => 'Smith' . $user_id ) );

		return $user_id;
	}

	/** A confirmed flagship place for one delegate. */
	private function make_ticket( $user_id, $payment = 'paid' ): int {
		$booking_id = law_booking_insert(
			$this->flagship,
			$user_id,
			'publish',
			array( 'user_id' => $user_id, 'name' => 'Jane Smith', 'email' => 'jane-' . $user_id . '@example.test' ),
			array( '_law_price_pence' => 55000, '_law_vat' => 1, '_law_payment_status' => $payment )
		);
		$this->posts[] = $booking_id;

		return (int) $booking_id;
	}

	public function test_included_ids_are_published_flagged_and_still_to_come(): void {
		$this->make_flagship();
		$live    = $this->make_reception();
		// Created as a draft, not published then demoted: the status guard
		// reverts a bare wp_update_post on a law_event, which is the point of
		// it (functions/events/workflow.php).
		$draft = $this->make_reception( array(), 'law-draft' );
		$unlisted = $this->make_reception( array( '_law_flagship_included' => 0 ) );
		$past     = $this->make_reception( array( '_law_start' => gmdate( 'Y-m-d H:i', strtotime( '-2 days' ) ) ) );

		$ids = law_reception_included_ids();
		$this->assertContains( $live, $ids );
		$this->assertNotContains( $draft, $ids );
		$this->assertNotContains( $unlisted, $ids );
		$this->assertNotContains( $past, $ids );
	}

	public function test_a_confirmed_ticket_grants_a_free_place_and_an_unconfirmed_one_does_not(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$user_id   = $this->make_delegate();
		$ticket    = $this->make_ticket( $user_id );

		$granted = law_reception_grant_included( $reception, $user_id, $ticket, 0, 'test' );
		$this->assertIsInt( $granted, is_wp_error( $granted ) ? $granted->get_error_message() : '' );
		$this->posts[] = $granted;

		$this->assertSame( 'publish', get_post_status( $granted ) );
		$this->assertSame( 0, (int) law_event_meta( $granted, '_law_price_pence' ) );
		$this->assertSame( 'included', (string) law_event_meta( $granted, '_law_payment_status' ) );
		$this->assertSame( $ticket, (int) law_event_meta( $granted, '_law_included_with' ) );
		$this->assertSame( 1, law_event_attendee_total( $reception ) );

		// An application still under review includes nothing.
		$waiting = $this->make_delegate();
		$pending = law_booking_insert(
			$this->flagship,
			$waiting,
			'law-applied',
			array( 'user_id' => $waiting, 'name' => 'Not Yet', 'email' => 'notyet-' . $waiting . '@example.test' ),
			array( '_law_price_pence' => 55000, '_law_payment_status' => 'pending_setup' )
		);
		$this->posts[] = $pending;
		$this->assertWPError( law_reception_grant_included( $reception, $waiting, $pending, 0, 'test' ), 'law_reception_no_flagship' );
	}

	public function test_somebody_elses_ticket_grants_nothing(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$owner     = $this->make_delegate();
		$ticket    = $this->make_ticket( $owner );
		$stranger  = $this->make_delegate();

		$this->assertWPError(
			law_reception_grant_included( $reception, $stranger, $ticket, 0, 'test' ),
			'law_reception_no_flagship'
		);
		$this->assertSame( 0, law_event_attendee_total( $reception ) );
	}

	public function test_a_place_already_held_is_reported_not_refused(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$user_id   = $this->make_delegate();
		$ticket    = $this->make_ticket( $user_id );

		$bought = law_booking_insert(
			$reception,
			$user_id,
			'publish',
			array( 'user_id' => $user_id, 'name' => 'Jane Smith', 'email' => 'jane-' . $user_id . '@example.test' ),
			array( '_law_price_pence' => 4500, '_law_vat' => 1, '_law_payment_status' => 'paid' )
		);
		$this->posts[] = $bought;
		law_event_recount_attendees( $reception );

		$result = law_reception_grant_choices( $ticket, array( $reception ), 0, 'test' );
		$this->assertSame( array(), $result['granted'] );
		$this->assertArrayHasKey( $reception, $result['skipped'] );
		$this->assertSame( 1, law_event_attendee_total( $reception ), 'The place they paid for is left alone.' );
		$this->assertStringContainsString( 'already had', law_reception_choices_note( $result ) );
	}

	public function test_a_full_reception_is_over_booked_rather_than_refused(): void {
		$this->make_flagship();
		$reception = $this->make_reception( array( '_law_tickets_available' => 1 ) );

		$taken = $this->make_delegate();
		$sold  = law_booking_insert(
			$reception,
			$taken,
			'publish',
			array( 'user_id' => $taken, 'name' => 'Bought', 'email' => 'bought-' . $taken . '@example.test' ),
			array( '_law_price_pence' => 4500, '_law_vat' => 1, '_law_payment_status' => 'paid' )
		);
		$this->posts[] = $sold;
		law_event_recount_attendees( $reception );
		$this->assertSame( 0, law_event_tickets_remaining( $reception ) );

		$user_id = $this->make_delegate();
		$ticket  = $this->make_ticket( $user_id );
		$granted = law_reception_grant_included( $reception, $user_id, $ticket, 0, 'test' );
		$this->assertIsInt( $granted, is_wp_error( $granted ) ? $granted->get_error_message() : '' );
		$this->posts[] = $granted;

		$this->assertSame( 2, law_event_attendee_total( $reception ), 'The ticket promised the place; the committee sizes the room.' );
		$log = implode( ' ', array_map( fn( $row ) => (string) $row->comment_content, law_event_log_entries( $reception ) ) );
		$this->assertStringContainsString( 'OVER-BOOKED', $log, 'And it is loud in the log, never silent.' );
	}

	public function test_confirming_a_flagship_place_grants_the_receptions_that_were_ticked(): void {
		$this->make_flagship();
		$monday    = $this->make_reception();
		$wednesday = $this->make_reception();
		$user_id   = $this->make_delegate();

		$ticket = law_booking_insert(
			$this->flagship,
			$user_id,
			'law-applied',
			array( 'user_id' => $user_id, 'name' => 'Jane Smith', 'email' => 'jane-' . $user_id . '@example.test' ),
			array(
				'_law_price_pence'       => 55000,
				'_law_vat'               => 1,
				'_law_payment_status'    => 'ready',
				'_law_reception_choices' => array( $monday, $wednesday ),
			)
		);
		$this->posts[] = $ticket;

		law_flagship_confirm( $ticket, 0, 'complimentary' );
		foreach ( law_bookings_for_event( $monday ) as $row ) {
			$this->posts[] = (int) $row->ID;
		}
		foreach ( law_bookings_for_event( $wednesday ) as $row ) {
			$this->posts[] = (int) $row->ID;
		}

		$this->assertSame( 1, law_event_attendee_total( $monday ) );
		$this->assertSame( 1, law_event_attendee_total( $wednesday ) );
	}

	public function test_a_full_refund_takes_the_included_places_back(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$user_id   = $this->make_delegate();
		$ticket    = $this->make_ticket( $user_id );

		$granted       = law_reception_grant_included( $reception, $user_id, $ticket, 0, 'test' );
		$this->posts[] = $granted;
		$this->assertSame( 1, law_event_attendee_total( $reception ) );

		// A PART refund changes nothing: the place is still confirmed.
		law_flagship_mark_refunded( $ticket, 10000, 66000, true );
		$this->assertSame( 'publish', get_post_status( $granted ) );

		law_flagship_mark_refunded( $ticket, 66000, 66000, false );
		$this->assertSame( 'law-cancelled', get_post_status( $granted ) );
		$this->assertSame( 0, law_event_attendee_total( $reception ) );
	}

	/**
	 * The same promise from the other direction: the committee cancelling a
	 * paid ticket (FLAGSHIP_PAYMENTS.md §4.6). The receptions were free only
	 * for as long as the ticket stood, so they go back with it and the
	 * reception's own seat is genuinely released.
	 */
	public function test_cancelling_a_paid_ticket_takes_the_included_places_back(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$user_id   = $this->make_delegate();
		$ticket    = $this->make_ticket( $user_id );

		$granted       = law_reception_grant_included( $reception, $user_id, $ticket, 0, 'test' );
		$this->posts[] = $granted;
		$this->assertSame( 1, law_event_attendee_total( $reception ) );

		$this->assertTrue( law_flagship_cancel_confirmed( $ticket, $this->make_committee_user() ) );

		$this->assertSame( 'law-cancelled', get_post_status( $ticket ) );
		$this->assertSame( 'law-cancelled', get_post_status( $granted ), 'The included place goes with the ticket.' );
		$this->assertSame( 0, law_event_attendee_total( $reception ), 'And the reception seat is released.' );
	}

	/** A reception place the delegate PAID for is theirs, and is not swept up. */
	public function test_cancelling_a_ticket_leaves_a_separately_bought_reception_place_alone(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$user_id   = $this->make_delegate();
		$ticket    = $this->make_ticket( $user_id );

		$bought = law_booking_insert(
			$reception,
			$user_id,
			'publish',
			array( 'user_id' => $user_id, 'name' => 'Jane Smith', 'email' => 'jane-' . $user_id . '@example.test' ),
			array( '_law_price_pence' => 4500, '_law_vat' => 1, '_law_payment_status' => 'paid' )
		);
		$this->posts[] = $bought;

		$this->assertTrue( law_flagship_cancel_confirmed( $ticket, $this->make_committee_user() ) );

		$this->assertSame( 'publish', get_post_status( $bought ), 'They bought it, so it is theirs whatever happens to the ticket.' );
	}

	public function test_the_add_included_handler_never_trusts_a_posted_booking_id(): void {
		// The handler resolves the flagship booking FROM THE CURRENT USER, so
		// there is no posted id to forge. Pinned on the source, because the
		// handler exits rather than returning.
		$source = file_get_contents( get_theme_file_path( 'functions/events/receptions.php' ) );
		$this->assertStringContainsString( 'law_reception_confirmed_flagship( $user_id )', $source );
		$this->assertStringNotContainsString( "absint( \$_POST['flagship_booking_id']", $source );

		// And the receptions it will act on are intersected with the ones that
		// really are included, so a forged checkbox reaches nothing.
		$this->assertStringContainsString( 'array_intersect( $wanted, law_reception_included_ids() )', $source );
	}

	public function test_the_banner_appears_only_while_there_is_something_to_add(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$user_id   = $this->make_delegate();

		// No ticket: nothing to offer, whatever else is on the programme.
		$this->assertSame( array(), law_reception_banner_state( $user_id ) );

		// Containment, not equality: this environment may hold the three real
		// receptions as well as the fixture, and the banner offers all of them.
		$ticket = $this->make_ticket( $user_id );
		$this->assertContains( $reception, law_reception_banner_state( $user_id ) );
		$this->assertNotSame( '', law_reception_banner( $user_id ) );

		// Claim every one it offers; the banner goes when none is left.
		foreach ( law_reception_banner_state( $user_id ) as $offered ) {
			$granted = law_reception_grant_included( $offered, $user_id, $ticket, 0, 'test' );
			if ( ! is_wp_error( $granted ) && $granted ) {
				$this->posts[] = $granted;
			}
		}

		$this->assertSame( array(), law_reception_banner_state( $user_id ), 'The banner goes once every included reception is held.' );
		$this->assertSame( '', law_reception_banner( $user_id ) );
	}
}
