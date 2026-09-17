<?php
/**
 * The committee's off switch: _law_disabled, the checkbox at the top of the
 * Committee controls panel (Denis, 17 September 2026).
 *
 * "Disable the event at all, so it's hidden from programme no matter what."
 * The rule pinned here is that it outranks everything else on the panel: the
 * status, the slot, the places, the venue and Override booking availability.
 * A Confirmed, paid, slotted, fully bookable event with the box ticked is off
 * the public programme, off its own page and takes no bookings, while the
 * committee's own screens still list it and say so with a Disabled badge.
 *
 * It is NOT a status and not a cancellation: the event keeps the status it had,
 * keeps its invoice and its bookings, and unticking the box puts it back.
 */

require_once __DIR__ . '/class-law-test-case.php';

class EventDisabledTest extends LAW_Test_Case {

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

	/**
	 * A Confirmed event with nothing missing: a slot, a venue and places. The
	 * point of every assertion below is that this event is otherwise perfect,
	 * so anything that closes it closed it because of the switch.
	 */
	private function live_event( array $meta = array(), $status = 'publish' ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_tickets_available' => 25,
					'_law_venue'             => 'A room with a view',
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

	/** The panel's own write, as law_committee_action_handler() performs it. */
	private function committee_saves_flags( int $event_id, array $post ): void {
		$actor  = get_current_user_id();
		$_POST  = array_merge( array( 'law_flags_present' => '1' ), $post );
		$before = array(
			'_law_is_external'    => (int) law_event_meta( $event_id, '_law_is_external' ),
			'_law_session_agenda' => (int) law_event_meta( $event_id, '_law_session_agenda' ),
			'_law_disabled'       => (int) law_event_meta( $event_id, '_law_disabled' ),
		);
		law_event_update_meta( $event_id, '_law_is_external', ! empty( $_POST['law_is_external'] ) );
		law_event_update_meta( $event_id, '_law_session_agenda', ! empty( $_POST['law_session_agenda'] ) );
		law_event_update_meta( $event_id, '_law_disabled', ! empty( $_POST['law_disabled'] ) );
		law_event_log_flag_change( $event_id, $before, $actor );
		$_POST = array();
	}

	private function log_messages( int $event_id ): array {
		return wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' );
	}

	/* The predicate __________________________________________________________ */

	public function test_an_event_is_enabled_until_the_box_is_ticked(): void {
		$event = $this->live_event();
		$this->assertFalse( law_event_is_disabled( $event ), 'Absent meta means enabled, like every other flag.' );
		$this->assertTrue( law_event_is_publicly_listed( $event ) );

		law_event_update_meta( $event, '_law_disabled', 1 );
		$this->assertTrue( law_event_is_disabled( $event ) );
	}

	public function test_a_post_that_is_not_an_event_is_never_disabled(): void {
		$this->assertFalse( law_event_is_disabled( 0 ) );
		$this->assertFalse( law_event_is_disabled( PHP_INT_MAX ) );
	}

	/* The programme __________________________________________________________ */

	public function test_a_disabled_event_drops_off_the_public_programme(): void {
		$event = $this->live_event();
		$this->assertContains( $event, $this->programme_ids(), 'Confirmed and slotted, so it starts on the programme.' );

		law_event_update_meta( $event, '_law_disabled', 1 );
		$this->assertNotContains( $event, $this->programme_ids() );
	}

	/**
	 * The committee has to be able to find the event it has just switched off,
	 * and the host has to keep seeing their own. Both read the same map through
	 * a wider $allowed, which is what this pins.
	 */
	public function test_the_committee_and_the_host_still_see_a_disabled_event(): void {
		$event = $this->live_event();
		law_event_update_meta( $event, '_law_disabled', 1 );

		$this->assertNull( law_events_map_post( $event ), 'Public: gone.' );
		$this->assertIsArray( law_events_map_post( $event, array() ), 'Committee: every status, disabled included.' );
		$this->assertIsArray( law_events_map_post( $event, array( '*' ) ), 'Host dashboard: same.' );
	}

	/* The page ______________________________________________________________ */

	public function test_a_disabled_event_is_no_longer_publicly_listed(): void {
		$event = $this->live_event();
		law_event_update_meta( $event, '_law_disabled', 1 );

		$this->assertFalse(
			law_event_is_publicly_listed( $event ),
			'The one predicate the .ics feed, the {event_link} tag and the card links all ask.'
		);
		$this->assertStringContainsString(
			'event=' . $event,
			law_events_event_url( $event ),
			'Its link falls back to the committee view, as an unpublished event\'s does.'
		);
	}

	/**
	 * Hiding it from the programme is not enough on its own: the permalink is
	 * still a published URL anyone holding it can open.
	 */
	public function test_the_public_page_404s_while_the_host_and_committee_keep_it(): void {
		$host  = $this->make_user();
		$event = $this->live_event( array(), 'publish' );
		wp_update_post( array( 'ID' => $event, 'post_author' => $host ) );
		law_event_update_meta( $event, '_law_disabled', 1 );

		$this->assertTrue( $this->page_is_404( $event, 0 ), 'A visitor gets nothing.' );
		$this->assertFalse( $this->page_is_404( $event, $host ), 'The host previews their own event.' );
		$this->assertFalse( $this->page_is_404( $event, $this->make_committee_user() ), 'So does the committee.' );

		law_event_update_meta( $event, '_law_disabled', 0 );
		$this->assertFalse( $this->page_is_404( $event, 0 ), 'Untick and the page is back.' );
	}

	/** Run the guard against a single-event query as this viewer. */
	private function page_is_404( int $event_id, int $user_id ): bool {
		global $wp_query;
		$before = $wp_query;
		wp_set_current_user( $user_id );

		$wp_query = new WP_Query(
			array( 'p' => $event_id, 'post_type' => LAW_EVENT_CPT, 'post_status' => 'any' )
		);
		law_events_gate_disabled_event_page();
		$is_404 = $wp_query->is_404();

		$wp_query = $before;
		wp_set_current_user( 0 );
		return $is_404;
	}

	/* Booking _______________________________________________________________ */

	public function test_a_disabled_event_takes_no_bookings(): void {
		$event = $this->live_event();
		$this->assertTrue( law_booking_guard_open( $event ), 'Bookable before the switch.' );

		law_event_update_meta( $event, '_law_disabled', 1 );
		$this->assertSame( 'event_disabled', law_event_booking_hold_reason( $event ) );
		$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_not_open' );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	/**
	 * Enable booking is the committee's own override, and it lifts the payment
	 * gate and the venue hold. It must not lift this one: an event nobody can
	 * see cannot be sold places.
	 */
	public function test_enable_booking_does_not_lift_it(): void {
		$event = $this->live_event( array( '_law_booking_override' => 'enable', '_law_disabled' => 1 ) );

		$this->assertSame( 'event_disabled', law_event_booking_hold_reason( $event ), 'Judged before every other hold.' );
		$this->assertWPError( law_booking_guard_open( $event ), 'law_booking_not_open' );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	/**
	 * An external event is otherwise registerable whatever its places and venue
	 * say, because its Register button leaves the site. The switch closes that
	 * link too.
	 */
	public function test_an_external_event_is_closed_by_it_as_well(): void {
		$event = $this->live_event( array( '_law_is_external' => 1, '_law_external_url' => 'https://example.test/book' ) );
		$this->assertSame( 'external', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );

		law_event_update_meta( $event, '_law_disabled', 1 );
		$this->assertSame( 'not-open', law_booking_state( $event, array( 'user_id' => 0 ) )['state'] );
	}

	/* What the committee reads ______________________________________________ */

	public function test_the_panel_says_why_booking_is_shut(): void {
		$event = $this->live_event();
		law_event_update_meta( $event, '_law_disabled', 1 );

		$note = law_event_booking_hold_note( $event );
		$this->assertFalse( $note['error'], 'A decision, not a mistake the panel has to flag.' );
		$this->assertStringContainsString( 'disabled', $note['text'] );
	}

	public function test_the_badge_marks_a_disabled_event_and_nothing_else(): void {
		$event = $this->live_event();

		ob_start();
		law_event_disabled_badge( $event );
		$this->assertSame( '', ob_get_clean(), 'Nothing on an ordinary event.' );

		law_event_update_meta( $event, '_law_disabled', 1 );
		ob_start();
		law_event_disabled_badge( $event );
		$badge = ob_get_clean();

		$this->assertStringContainsString( 'law-cal-card__badge--disabled', $badge );
		$this->assertStringContainsString( 'Disabled', $badge );
	}

	public function test_the_export_reports_it_beside_the_status(): void {
		$event   = $this->live_event();
		$columns = law_committee_export_columns();

		$row = law_committee_export_row( get_post( $event ) );
		$this->assertCount( count( $columns ), $row, 'The row and the header must stay the same length.' );
		$this->assertSame( '', array_combine( $columns, $row )['Disabled'], 'Blank, not "No", matching Sponsored and External.' );

		law_event_update_meta( $event, '_law_disabled', 1 );
		$row = law_committee_export_row( get_post( $event ) );
		$this->assertSame( 'Yes', array_combine( $columns, $row )['Disabled'] );
	}

	/* Saving and logging ____________________________________________________ */

	public function test_the_committee_can_set_and_clear_the_switch(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->live_event();

		$this->committee_saves_flags( $event, array( 'law_disabled' => '1' ) );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_disabled' ) );

		// An unticked box posts nothing, which is what law_flags_present is for:
		// absent must mean "off", not "leave it alone".
		$this->committee_saves_flags( $event, array() );
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_disabled' ) );
	}

	public function test_switching_it_on_and_off_is_logged_in_plain_words(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->live_event();

		$this->committee_saves_flags( $event, array( 'law_disabled' => '1' ) );
		$this->assertStringContainsString( 'Event disabled', $this->log_messages( $event )[0] );

		$before = count( $this->log_messages( $event ) );
		$this->committee_saves_flags( $event, array( 'law_disabled' => '1' ) );
		$this->assertCount( $before, $this->log_messages( $event ), 'Re-saving the same value must not log.' );

		$this->committee_saves_flags( $event, array() );
		$this->assertStringContainsString( 'Event enabled again', $this->log_messages( $event )[0] );
	}

	/**
	 * The simulated write above can only stand in for the real handlers while
	 * both of them carry the field, so the wiring itself is pinned here rather
	 * than assumed: the panel renders the box, and each handler writes the key
	 * under the sentinel the box posts with.
	 */
	public function test_both_screens_carry_the_switch(): void {
		$theme = dirname( __DIR__ );
		$panel = (string) file_get_contents( $theme . '/templates/account-dashboard.php' );
		$this->assertStringContainsString( 'name="law_disabled"', $panel, 'The Committee controls panel.' );
		$this->assertStringContainsString( 'law_flags_present', $panel, 'Which sentinel it posts under.' );

		foreach ( array( '/functions/events/committee.php', '/functions/events/admin/event-screen.php' ) as $file ) {
			$this->assertStringContainsString(
				"law_event_update_meta( \$" . ( '/functions/events/committee.php' === $file ? 'event_id' : 'post_id' ) . ", '_law_disabled', ! empty( \$_POST['law_disabled'] ) )",
				(string) file_get_contents( $theme . $file ),
				$file . ' must write the key from its own form.'
			);
		}
	}
}
