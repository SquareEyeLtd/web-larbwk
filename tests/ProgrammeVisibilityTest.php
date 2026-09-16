<?php
/**
 * What the public programme lists, and the three holds that keep an event's
 * booking shut (Denis, 16 September 2026).
 *
 * The settled rule: an event the committee has APPROVED is on the programme at
 * once, carrying "Open soon"; booking opens only when the event is paid for
 * (which is what publishes it) and none of the committee's three holds stands.
 *
 * Pinned here rather than in BookingCardActionTest because it is about the
 * listing and the predicate; the card's own markup is pinned there.
 */

require_once __DIR__ . '/class-law-test-case.php';

class ProgrammeVisibilityTest extends LAW_Test_Case {

	private $source_before;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before = get_option( 'law_events_source', null );
		update_option( 'law_events_source', 'cpt' );
		law_calendar_events( true );
	}

	protected function tearDown(): void {
		if ( null === $this->source_before ) {
			delete_option( 'law_events_source' );
		} else {
			update_option( 'law_events_source', $this->source_before );
		}
		law_calendar_events( true );
		parent::tearDown();
	}

	/** An event with a slot, a venue and places: everything but its status. */
	private function slotted_event( $status, array $meta = array() ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_tickets_available' => 25,
					'_law_venue'             => 'A room with a view',
					'_law_venue_needed'      => 'No, we already have a venue planned',
					'_law_start'             => gmdate( 'Y-m-d H:i', strtotime( '+30 days' ) ),
					'_law_end'               => gmdate( 'Y-m-d H:i', strtotime( '+30 days +2 hours' ) ),
				),
				$meta
			),
			$status
		);
	}

	private function programme_ids(): array {
		law_calendar_events( true );
		return array_map( 'intval', wp_list_pluck( law_calendar_events(), 'id' ) );
	}

	/* The listing ___________________________________________________________ */

	public function test_the_public_programme_lists_confirmed_and_approved(): void {
		$this->assertSame( array( 'Confirmed', 'Approved' ), law_calendar_public_statuses() );
		$this->assertSame(
			array( 'law-approved', 'publish' ),
			law_event_status_keys_for_labels( law_calendar_public_statuses() ),
			'Derived from the one status table, so labels and keys cannot drift.'
		);
	}

	/**
	 * The legacy Gravity Forms programme has no booking system at all, so an
	 * Approved entry there would sit on the page looking bookable with nothing
	 * on the row to say otherwise.
	 */
	public function test_the_legacy_source_lists_confirmed_only(): void {
		update_option( 'law_events_source', 'gf' );
		$this->assertSame( array( 'Confirmed' ), law_calendar_public_statuses() );
	}

	public function test_an_approved_event_is_on_the_programme(): void {
		$approved = $this->slotted_event( 'law-approved' );
		$this->assertContains( $approved, $this->programme_ids() );
	}

	public function test_a_proposed_event_is_not_on_the_programme(): void {
		$proposed = $this->slotted_event( 'law-proposed' );
		$this->assertNotContains( $proposed, $this->programme_ids() );
	}

	/**
	 * The no-date rule is untouched: an approved event with no slot still waits
	 * off the programme, as an unslotted confirmed one always has.
	 */
	public function test_an_approved_event_with_no_slot_still_waits(): void {
		$approved = $this->slotted_event( 'law-approved', array( '_law_start' => '', '_law_end' => '' ) );
		$this->assertNotContains( $approved, $this->programme_ids() );
	}

	/* The page and the links ________________________________________________ */

	public function test_approved_is_a_status_wordpress_will_render(): void {
		$status = get_post_status_object( 'law-approved' );
		$this->assertTrue( (bool) $status->public, 'WP_Query hides a singular result on a non-public status from a logged-out visitor.' );
		$this->assertTrue( is_post_status_viewable( $status ), 'What get_permalink() asks before it will return the pretty URL.' );

		$proposed = get_post_status_object( 'law-proposed' );
		$this->assertFalse( (bool) $proposed->public, 'Every other unconfirmed status stays committee-only.' );
		$this->assertFalse( is_post_status_viewable( $proposed ) );
	}

	public function test_an_approved_event_links_to_its_own_page(): void {
		$approved = $this->slotted_event( 'law-approved' );
		$this->assertTrue( law_event_is_publicly_listed( $approved ) );
		$this->assertSame( get_permalink( $approved ), law_events_event_url( $approved ) );
		$this->assertStringNotContainsString( '?post_type=', get_permalink( $approved ), 'A plain permalink means the status is not viewable.' );
	}

	public function test_a_proposed_event_still_links_to_the_committee_view(): void {
		$proposed = $this->slotted_event( 'law-proposed' );
		$this->assertFalse( law_event_is_publicly_listed( $proposed ) );
		$this->assertStringContainsString( 'event=' . $proposed, law_events_event_url( $proposed ) );
	}

	/* The states ____________________________________________________________ */

	public function test_an_approved_event_says_open_soon_rather_than_nothing(): void {
		$approved = $this->slotted_event( 'law-approved' );
		$state    = law_booking_state( $approved, array( 'user_id' => 0 ) );
		$this->assertSame( 'not-open', $state['state'] );
		$this->assertSame( 'Open soon', law_booking_card_inert_action( array( 'id' => $approved ) )['label'] );
	}

	/**
	 * The payment gate itself. Paying is what publishes an event, so the guard's
	 * publish test is the gate, and it must keep refusing an approved event
	 * however complete that event looks.
	 */
	public function test_no_place_can_be_held_on_an_approved_event(): void {
		$approved = $this->slotted_event( 'law-approved' );
		$this->assertSame( '', law_event_booking_hold_reason( $approved ), 'Nothing is missing on it but the money.' );
		$this->assertWPError( law_booking_guard_open( $approved ), 'law_booking_not_bookable' );
	}

	public function test_a_paid_event_with_everything_set_is_bookable(): void {
		$event = $this->slotted_event( 'publish' );
		$this->assertSame( '', law_event_booking_hold_reason( $event ) );
		$this->assertTrue( law_booking_guard_open( $event ) );
		$this->assertSame( 'bookable', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	/* The three holds _______________________________________________________ */

	public function test_no_places_released_holds_booking(): void {
		$event = $this->slotted_event( 'publish', array( '_law_tickets_available' => 0 ) );
		$this->assertSame( 'places', law_event_booking_hold_reason( $event ) );
		$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_not_open' );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	/**
	 * An attendee booking a place needs to know where to turn up, so the venue
	 * is required WHICHEVER way the host answered "Venue needed" (client,
	 * 16 September 2026). The answer is asserted on all three of its values,
	 * because the rule was asymmetric for half a day and the asymmetry is
	 * exactly the thing that must not come back.
	 */
	public function test_a_missing_venue_holds_booking_whatever_the_venue_answer_was(): void {
		$answers = array(
			'No, we already have a venue planned',
			'Yes, please share our details with venue hosts',
			'',
		);
		foreach ( $answers as $answer ) {
			$event = $this->slotted_event( 'publish', array( '_law_venue' => '', '_law_venue_needed' => $answer ) );
			$this->assertSame( 'venue', law_event_booking_hold_reason( $event ), 'Answer: ' . ( $answer ?: '(none)' ) );
			$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_not_open' );
			$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		}
	}

	/**
	 * The receptions are the client's own to manage: once one is visible on the
	 * programme with capacity and a price it is bookable at once, and its venue
	 * is not judged (Denis, 16 September 2026). The flagship rides along on the
	 * same predicate, and is refused for its own reason.
	 */
	public function test_a_reception_is_bookable_with_no_venue(): void {
		$event = $this->slotted_event(
			'publish',
			array( '_law_is_reception' => 1, '_law_attendee_price_pence' => 4500, '_law_venue' => '' )
		);

		$this->assertSame( '', law_event_booking_hold_reason( $event ) );
		$this->assertTrue( law_event_venue_gates_booking( $this->slotted_event( 'publish' ) ), 'A host submission is still judged on it.' );
		$this->assertFalse( law_event_venue_gates_booking( $event ) );
		$this->assertTrue( law_reception_guard_open( $event ) );
		$this->assertSame( 'buy', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		// And the panel does not promise a condition that does not apply to it.
		$this->assertStringNotContainsString( 'venue', law_event_booking_hold_note( $event )['text'] );
	}

	/** But its capacity still decides, because a place comes out of that. */
	public function test_a_reception_with_no_places_is_still_held(): void {
		$event = $this->slotted_event(
			'publish',
			array( '_law_is_reception' => 1, '_law_attendee_price_pence' => 4500, '_law_venue' => '', '_law_tickets_available' => 0 )
		);
		$this->assertSame( 'places', law_event_booking_hold_reason( $event ) );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	/** And Disable booking still closes one, through its own checkout guard. */
	public function test_disable_booking_closes_a_reception_checkout(): void {
		$event = $this->slotted_event(
			'publish',
			array( '_law_is_reception' => 1, '_law_attendee_price_pence' => 4500 )
		);
		law_event_update_meta( $event, '_law_booking_override', 'disable' );

		$this->assertWPError( law_reception_guard_open( $event ), 'law_reception_not_open' );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	/**
	 * A reception's row on the committee's event list goes straight to Manage
	 * receptions, labelled Edit, because that screen holds every field it has
	 * and it has no workflow to review (Denis, 16 September 2026).
	 */
	public function test_the_event_list_sends_a_reception_row_to_its_own_editor(): void {
		$reception = $this->slotted_event( 'publish', array( '_law_is_reception' => 1, '_law_attendee_price_pence' => 4500 ) );
		$hosted    = $this->slotted_event( 'publish' );
		wp_set_current_user( $this->make_committee_user() );

		ob_start();
		get_template_part( 'parts/events/dashboard-list' );
		$html = (string) ob_get_clean();

		$row = function ( $id ) use ( $html ) {
			$reference = law_event_meta( $id, '_law_reference' );
			return preg_match( '/<code>' . preg_quote( (string) $reference, '/' ) . '<\/code>(.*?)<\/tr>/s', $html, $m ) ? $m[1] : '';
		};

		$this->assertStringContainsString( '>Edit<', $row( $reception ) );
		$this->assertStringContainsString( 'law_reception=' . $reception, $row( $reception ) );
		$this->assertStringNotContainsString( '>Review<', $row( $reception ) );

		// Guard: an ordinary event still goes to the detail view to be reviewed.
		$this->assertStringContainsString( '>Review<', $row( $hosted ) );
		$this->assertStringContainsString( 'event=' . $hosted, $row( $hosted ) );
	}

	/* The override __________________________________________________________ */

	public function test_the_override_defaults_to_automatic(): void {
		$this->assertSame( 'auto', law_event_booking_override( $this->slotted_event( 'publish' ) ) );
	}

	/** A forged value decides nothing: it must never be able to force booking open. */
	public function test_an_unknown_override_reads_as_automatic(): void {
		$event = $this->slotted_event( 'publish' );
		law_event_update_meta( $event, '_law_booking_override', 'whatever' );
		$this->assertSame( 'auto', law_event_booking_override( $event ) );
	}

	public function test_disable_booking_closes_an_otherwise_open_event(): void {
		$event = $this->slotted_event( 'publish' );
		law_event_update_meta( $event, '_law_booking_override', 'disable' );

		$this->assertSame( 'disabled', law_event_booking_hold_reason( $event ) );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		$this->assertSame( 'Open soon', law_booking_card_inert_action( array( 'id' => $event ) )['label'] );
		$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_not_open' );
		$this->assertWPError( law_booking_guard_form_open( $event ), 'law_booking_not_open' );

		law_event_update_meta( $event, '_law_booking_override', 'auto' );
		$this->assertSame( '', law_event_booking_hold_reason( $event ) );
		$this->assertTrue( law_booking_guard_open( $event ) );
	}

	/**
	 * The exception the client asked for: a high-profile event LAW wants to
	 * promote before its address is settled, or before its host has paid.
	 */
	public function test_enable_booking_opens_an_event_with_no_venue(): void {
		$event = $this->slotted_event( 'publish', array( '_law_venue' => '' ) );
		law_event_update_meta( $event, '_law_booking_override', 'enable' );

		$this->assertSame( '', law_event_booking_hold_reason( $event ) );
		$this->assertTrue( law_booking_guard_open( $event ) );
		$this->assertSame( 'bookable', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	public function test_enable_booking_opens_an_approved_event_that_has_not_paid(): void {
		$event = $this->slotted_event( 'law-approved' );
		law_event_update_meta( $event, '_law_booking_override', 'enable' );

		$this->assertTrue( law_booking_guard_open( $event ), 'The one thing that lifts the payment gate.' );
		$this->assertSame( 'bookable', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		$this->assertSame( 'Register', law_booking_card_action( array( 'id' => $event, 'title' => 'x' ) )['label'] );
	}

	/**
	 * And no further than that. Enable booking is a decision about an event on
	 * the programme, not a way to sell places at a rejected or cancelled one.
	 */
	public function test_enable_booking_cannot_open_an_event_the_public_cannot_see(): void {
		foreach ( array( 'law-proposed', 'law-rejected', 'law-cancelled', 'law-draft' ) as $status ) {
			$event = $this->slotted_event( $status );
			law_event_update_meta( $event, '_law_booking_override', 'enable' );
			$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_not_bookable', $status );
			$this->assertNull( law_booking_state( $event, array( 'user_id' => 0 ) ), $status );
		}
	}

	/**
	 * Capacity is the one thing Enable booking cannot conjure: a place is
	 * allocated out of it. The committee is told so as an error rather than
	 * left with a control that did nothing.
	 */
	public function test_enable_booking_still_needs_places_and_says_so(): void {
		$event = $this->slotted_event( 'publish', array( '_law_tickets_available' => 0 ) );
		law_event_update_meta( $event, '_law_booking_override', 'enable' );

		$this->assertSame( 'places', law_event_booking_hold_reason( $event ) );
		$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_not_open' );

		$note = law_event_booking_hold_note( $event );
		$this->assertTrue( $note['error'] );
		$this->assertStringContainsString( 'Places available', $note['text'] );
	}

	/* A place already held outranks every hold ______________________________ */

	/**
	 * Closing an event stops NEW bookings. It must not tell somebody who
	 * already holds a place that the event is "Open soon", which would read as
	 * their booking having vanished and would take away the route to manage or
	 * cancel it -- the card's and the event page's only link to it.
	 */
	public function test_disable_booking_never_hides_a_place_somebody_already_holds(): void {
		$event  = $this->slotted_event( 'publish' );
		$holder = $this->make_user();
		$this->make_booking( $event, $holder );
		law_event_update_meta( $event, '_law_booking_override', 'disable' );

		$state = law_booking_state( $event, array( 'user_id' => $holder ) );
		$this->assertSame( 'booked', $state['state'] );
		$this->assertNotSame( '', $state['manage_url'], 'And the route to it survives.' );
		// The card always resolves for the CURRENT viewer, so it has to be them.
		wp_set_current_user( $holder );
		$card = law_booking_card_action( array( 'id' => $event, 'title' => 'x' ) );
		$this->assertNotNull( $card, 'The card offers the booking, not a dead button.' );
		$this->assertSame( law_booking_manage_label( $state ), $card['label'] );
		$this->assertNull( law_booking_card_inert_action( array( 'id' => $event ) ), 'And no "Open soon" beside it.' );

		// Everybody else is held shut, which is the point of the answer.
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => $this->make_user() ) )['state'] );
	}

	/**
	 * The same thing one door along, and this sequence is reachable: the
	 * committee forces a sponsor's unpaid event open, delegates book, and
	 * somebody later sets it back to Automatic before the fee is paid.
	 */
	public function test_a_place_taken_on_a_forced_open_event_survives_the_answer_changing(): void {
		$event = $this->slotted_event( 'law-approved' );
		law_event_update_meta( $event, '_law_booking_override', 'enable' );
		$holder = $this->make_user();
		$this->make_booking( $event, $holder );

		law_event_update_meta( $event, '_law_booking_override', 'auto' );

		$this->assertSame( 'booked', law_booking_state( $event, array( 'user_id' => $holder ) )['state'] );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_not_bookable', 'And no new place can be taken.' );
	}

	/** A waitlist entry is a place in a queue, and is kept for the same reason. */
	public function test_disable_booking_never_hides_a_waitlist_entry(): void {
		$event = $this->slotted_event( 'publish', array( '_law_tickets_available' => 1 ) );
		$this->make_booking( $event, $this->make_user() );
		$waiter = $this->make_user();
		$this->make_waitlist( $event, $waiter );

		law_event_update_meta( $event, '_law_booking_override', 'disable' );
		$this->assertSame( 'waitlisted', law_booking_state( $event, array( 'user_id' => $waiter ) )['state'] );
	}

	/**
	 * The committee's own columns have to report a forced-open event's bookings.
	 * Keying the Bookings count and button on `publish` alone printed a dash
	 * against real places taken, and left no way into the list.
	 */
	public function test_the_event_list_reports_bookings_on_a_forced_open_event(): void {
		$event = $this->slotted_event( 'law-approved' );
		law_event_update_meta( $event, '_law_booking_override', 'enable' );
		$this->make_booking( $event, $this->make_user() );
		$empty = $this->slotted_event( 'law-approved' );
		wp_set_current_user( $this->make_committee_user() );

		ob_start();
		get_template_part( 'parts/events/dashboard-list' );
		$html = (string) ob_get_clean();

		$row = function ( $id ) use ( $html ) {
			$reference = law_event_meta( $id, '_law_reference' );
			return preg_match( '/<code>' . preg_quote( (string) $reference, '/' ) . '<\/code>(.*?)<\/tr>/s', $html, $m ) ? $m[1] : '';
		};

		$this->assertStringContainsString( '>Bookings<', $row( $event ) );
		$this->assertStringContainsString( 'law_event_bookings=' . $event, $row( $event ) );
		// An approved event nobody has booked keeps its dash: a hollow 0 reads
		// as "nobody has booked" rather than "bookings do not happen here".
		$this->assertStringNotContainsString( '>Bookings<', $row( $empty ) );
	}

	/* External events _______________________________________________________ */

	/**
	 * An EXTERNAL event is always available on the programme: its Register
	 * button leaves the site for the organiser's own page, so its places and
	 * its venue are not facts about it. The card gets there because the state
	 * resolver answers 'external' before it reads any hold.
	 */
	public function test_an_external_event_stays_registerable_with_no_places_or_venue(): void {
		$event = $this->slotted_event(
			'publish',
			array(
				'_law_is_external'       => 1,
				'_law_external_url'      => 'https://example.test/register',
				'_law_tickets_available' => 0,
				'_law_venue'             => '',
			)
		);

		$this->assertSame( 'external', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		$this->assertSame( 'Register', law_booking_card_action( array( 'id' => $event, 'title' => 'x' ) )['label'] );
		// And the committee's panel blames neither its places nor its venue.
		$note = law_event_booking_hold_note( $event )['text'];
		$this->assertStringContainsString( 'organiser', $note );
		$this->assertStringNotContainsString( 'places have been released', $note );
	}

	/**
	 * The local guard still refuses it, which is what keeps the booking form and
	 * the waitlist off an event whose places are somebody else's to sell. Both
	 * halves are asserted: with no places released the event-state guard refuses
	 * it (the reading tests/ExternalEventsTest.php depends on), and WITH places
	 * released the form guard refuses it anyway, so the protection does not rest
	 * on an external event happening to have no capacity.
	 */
	public function test_an_external_event_still_takes_no_local_booking(): void {
		$external = array( '_law_is_external' => 1, '_law_external_url' => 'https://example.test/register' );

		$no_places = $this->slotted_event( 'publish', array_merge( $external, array( '_law_tickets_available' => 0 ) ) );
		$this->assertWPError( law_booking_guard_open( $no_places ), 'law_booking_not_open' );

		$with_places = $this->slotted_event( 'publish', $external );
		$this->assertTrue( law_booking_guard_open( $with_places ), 'Nothing about the event itself is wrong.' );
		$this->assertWPError( law_booking_guard_form_open( $with_places ), 'law_booking_external' );
	}

	public function test_disable_booking_closes_an_external_event_too(): void {
		$event = $this->slotted_event(
			'publish',
			array( '_law_is_external' => 1, '_law_external_url' => 'https://example.test/register' )
		);
		law_event_update_meta( $event, '_law_booking_override', 'disable' );

		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
		$this->assertNull( law_booking_card_action( array( 'id' => $event, 'title' => 'x' ) ), 'No link out to the organiser while the hold stands.' );
		$this->assertSame( 'Open soon', law_booking_card_inert_action( array( 'id' => $event ) )['label'] );
		$this->assertSame(
			law_event_booking_hold_label( 'disabled' ),
			law_event_booking_hold_note( $event )['text'],
			'The one hold the committee is told about on an external event.'
		);
	}

	/** And the row an approved event actually renders on the programme. */
	public function test_the_approved_card_renders_two_buttons_with_the_second_disabled(): void {
		$approved = $this->slotted_event( 'law-approved' );
		law_calendar_reset_caches();

		ob_start();
		get_template_part( 'parts/loop/event', null, array( 'event' => law_events_map_post( get_post( $approved ) ) ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Event details', $html );
		$this->assertStringContainsString( 'Open soon', $html );
		$this->assertMatchesRegularExpression( '/<button[^>]+disabled/', $html, 'A real disabled button: an anchor with aria-disabled is still followed on Enter.' );
		$this->assertStringNotContainsString( 'Register', $html );
	}

	/* The committee's own words _____________________________________________ */

	/**
	 * Before approval the thing keeping booking shut is the workflow, not the
	 * venue or the places, so the panel says nothing rather than blaming one.
	 */
	public function test_the_panel_names_no_hold_on_an_event_awaiting_approval(): void {
		$event = $this->slotted_event( 'law-proposed', array( '_law_venue' => '' ) );
		$this->assertSame( 'venue', law_event_booking_hold_reason( $event ), 'The predicate still answers.' );
		$this->assertStringContainsString( 'Automatic opens booking', law_event_booking_hold_note( $event )['text'] );
		$this->assertStringNotContainsString( 'Booking is closed', law_event_booking_hold_note( $event )['text'] );

		// Disable booking is a decision, so it is reported whatever the status.
		law_event_update_meta( $event, '_law_booking_override', 'disable' );
		$this->assertSame( law_event_booking_hold_label( 'disabled' ), law_event_booking_hold_note( $event )['text'] );
	}

	public function test_each_hold_has_a_line_for_the_committee_panel(): void {
		foreach ( array( 'disabled', 'places', 'venue' ) as $reason ) {
			$this->assertNotSame( '', law_event_booking_hold_label( $reason ) );
		}
		$this->assertSame( '', law_event_booking_hold_label( '' ), 'No hold, no line.' );
	}

	/**
	 * The public is never told WHICH hold stands: naming it would say that a
	 * host has not paid, or that their venue is unknown.
	 */
	public function test_the_public_wording_is_the_same_for_every_hold(): void {
		$labels = array();
		foreach ( array( '_law_booking_override' => 'disable', '_law_tickets_available' => 0, '_law_venue' => '' ) as $key => $value ) {
			$event    = $this->slotted_event( 'publish', array( $key => $value ) );
			$labels[] = law_booking_card_inert_action( array( 'id' => $event ) )['label'];
		}
		$this->assertSame( array( 'Open soon', 'Open soon', 'Open soon' ), $labels );
	}
}
