<?php
/**
 * The waitlist (functions/events/waitlist.php, WAITLIST.md Part B):
 *
 * - joining: refused while places are free, allowed once full, one entry per
 *   attendee with consecutive positions, colleague accounts created, the host
 *   told once when a queue first forms;
 * - promotion: plain first-in-first-out one place at a time, after a cancel,
 *   a reject or the places being raised (never lowered); an entry the guards
 *   refuse is skipped IN PLACE and its owner told once;
 * - host controls: reorder (including the stale-click no-op) and Promote now,
 *   which over-books deliberately but still refuses a duplicate or a clash;
 * - protection: the status guard, untrash to the back of the queue, the
 *   event-cancel sweep cancelling the waitlist without promoting anyone, and
 *   waitlisted entries staying out of the clash source and the exports.
 */

require_once __DIR__ . '/class-law-test-case.php';

class WaitlistTest extends LAW_Test_Case {

	private $source_before;
	private $counter_before;
	private $mail = array();
	private $mail_filter;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before  = get_option( 'law_events_source', null );
		$this->counter_before = get_option( 'law_bookings_counter', null );
		update_option( 'law_events_source', 'cpt' );

		$this->mail        = array();
		$this->mail_filter = function ( $atts ) {
			$this->mail[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $this->mail_filter );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_mail', $this->mail_filter );
		if ( null === $this->source_before ) {
			delete_option( 'law_events_source' );
		} else {
			update_option( 'law_events_source', $this->source_before );
		}
		if ( null === $this->counter_before ) {
			delete_option( 'law_bookings_counter' );
		} else {
			update_option( 'law_bookings_counter', $this->counter_before, false );
		}
		parent::tearDown();
	}

	/* Helpers _______________________________________________________________ */

	/** A full-ish event with a REAL host account, so the host emails land. */
	private function make_bookable_event( array $meta = array() ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_tickets_available' => 2,
					'_law_start'             => '2026-12-01 10:00',
					'_law_end'               => '2026-12-01 12:00',
				),
				$meta
			),
			'publish',
			$this->make_user( 'event_host' )
		);
	}

	private function host_of( int $event_id ): WP_User {
		return get_userdata( (int) get_post_field( 'post_author', $event_id ) );
	}

	/** An event that overlaps the fixture's slot, to create a clash. */
	private function make_overlapping_event(): int {
		return $this->make_event(
			array( '_law_tickets_available' => 5, '_law_start' => '2026-12-01 11:00', '_law_end' => '2026-12-01 13:00' ),
			'publish',
			$this->make_user( 'event_host' )
		);
	}

	private function row( string $name, string $email ): array {
		return array( 'name' => $name, 'email' => $email, 'organisation' => 'Test Org', 'job_title' => 'Associate' );
	}

	private function unique_email( string $prefix ): string {
		return $prefix . '-' . wp_generate_password( 8, false ) . '@example.test';
	}

	private function log_text( int $event_id ): string {
		$lines = array();
		foreach ( law_event_log_entries( $event_id ) as $entry ) {
			$lines[] = $entry->comment_content;
		}
		return implode( "\n", $lines );
	}

	/** Emails captured for one recipient. */
	private function mail_to( string $email ): array {
		return array_values(
			array_filter(
				$this->mail,
				fn( $m ) => in_array( strtolower( $email ), array_map( 'strtolower', (array) $m['to'] ), true )
			)
		);
	}

	/** Fill an event to capacity with strangers, so the waitlist opens. */
	private function fill( int $event_id ): array {
		$booked = array();
		$places = (int) law_event_meta( $event_id, '_law_tickets_available' );
		for ( $i = 0; $i < $places; $i++ ) {
			$user     = $this->make_user( 'attendee' );
			$ids      = $this->make_booking( $event_id, $user );
			$this->assertIsArray( $ids, 'Filling the event should succeed.' );
			$booked[] = array( 'user' => $user, 'booking' => (int) $ids[0] );
		}
		$this->assertSame( 0, law_event_tickets_remaining( $event_id ) );
		return $booked;
	}

	private function position( int $booking_id ): int {
		return (int) law_event_meta( $booking_id, '_law_waitlist_position' );
	}

	/* Joining _______________________________________________________________ */

	public function test_join_refused_while_places_are_free(): void {
		$event = $this->make_bookable_event();
		$this->assertWPError(
			$this->make_waitlist( $event, $this->make_user( 'attendee' ) ),
			'law_waitlist_places_available'
		);
	}

	public function test_join_creates_one_entry_per_person_in_order(): void {
		$event = $this->make_bookable_event();
		$this->fill( $event );

		$booker = $this->make_user( 'attendee' );
		$guest  = $this->unique_email( 'guest' );
		$this->mail = array();

		$ids = $this->make_waitlist( $event, $booker, array( $this->row( 'Jane Smith', $guest ) ) );
		$this->assertIsArray( $ids );
		$this->assertCount( 2, $ids );

		foreach ( $ids as $id ) {
			$this->assertSame( 'law-waitlisted', get_post_status( $id ) );
			$this->assertNotEmpty( law_event_meta( $id, '_law_waitlist_joined' ) );
		}
		$this->assertSame( 1, $this->position( $ids[0] ) );
		$this->assertSame( 2, $this->position( $ids[1] ) );
		$this->assertSame( 2, law_waitlist_count( $event ) );

		// A waitlist entry is not a place: the event is still exactly full.
		$this->assertSame( 0, law_event_tickets_remaining( $event ) );
		$this->assertSame( 2, law_event_attendee_total( $event ) );

		// An account is created for the colleague, and they hear about it.
		$colleague = get_user_by( 'email', $guest );
		$this->assertInstanceOf( WP_User::class, $colleague );
		$this->assertNotEmpty( $this->mail_to( $guest ) );
		$this->assertStringContainsString( 'waitlist', strtolower( $this->mail_to( $guest )[0]['subject'] ) );

		// The host hears once, on the first entry only.
		$host = $this->host_of( $event );
		$this->assertNotEmpty( $this->mail_to( $host->user_email ) );
		$this->mail = array();
		$this->make_waitlist( $event, $this->make_user( 'attendee' ) );
		$activation = array_filter(
			$this->mail_to( $host->user_email ),
			fn( $m ) => false !== stripos( $m['subject'], 'waitlist has opened' )
		);
		$this->assertEmpty( $activation, 'The host is told a waitlist has opened once, not per joiner.' );
	}

	public function test_duplicate_guard_spans_bookings_and_the_waitlist(): void {
		$event  = $this->make_bookable_event();
		$booked = $this->fill( $event );

		// Someone already booked cannot also wait.
		$this->assertWPError( $this->make_waitlist( $event, $booked[0]['user'] ), 'law_booking_duplicate' );

		// Someone already waiting cannot join twice.
		$waiter = $this->make_user( 'attendee' );
		$this->make_waitlist( $event, $waiter );
		$this->assertWPError( $this->make_waitlist( $event, $waiter ), 'law_booking_duplicate' );

		// And cannot book either, once a place appears.
		law_booking_cancel( $booked[0]['booking'], $booked[0]['user'], 'self' );
		$this->assertWPError( $this->make_booking( $event, $waiter ), 'law_booking_duplicate' );
	}

	public function test_join_closed_and_not_open_events(): void {
		$started = $this->make_bookable_event( array( '_law_start' => '2020-01-01 10:00', '_law_end' => '2020-01-01 12:00' ) );
		$this->assertWPError( $this->make_waitlist( $started, $this->make_user( 'attendee' ) ), 'law_booking_closed' );

		$no_tickets = $this->make_bookable_event( array( '_law_tickets_available' => 0 ) );
		$this->assertWPError( $this->make_waitlist( $no_tickets, $this->make_user( 'attendee' ) ), 'law_booking_not_open' );
	}

	/* Promotion _____________________________________________________________ */

	public function test_one_place_promotes_exactly_one_in_order(): void {
		$event  = $this->make_bookable_event();
		$booked = $this->fill( $event );

		$first  = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$second = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$this->mail = array();

		law_booking_cancel( $booked[0]['booking'], $booked[0]['user'], 'self' );

		$this->assertSame( 'publish', get_post_status( $first ), 'The head of the queue takes the place.' );
		$this->assertSame( 'law-waitlisted', get_post_status( $second ), 'Only one place opened, so only one is promoted.' );
		$this->assertSame( '', law_event_meta( $first, '_law_waitlist_position' ), 'A promoted entry keeps no position.' );
		$this->assertNotEmpty( law_event_meta( $first, '_law_waitlist_promoted' ) );
		$this->assertSame( 0, law_event_tickets_remaining( $event ) );
		$this->assertStringContainsString( 'promoted from the waitlist', $this->log_text( $event ) );

		// The promoted attendee is emailed, with the calendar invite.
		$promoted_user = get_userdata( (int) get_post_field( 'post_author', $first ) );
		$mail          = $this->mail_to( $promoted_user->user_email );
		$this->assertNotEmpty( $mail );
		$this->assertStringContainsString( 'A place has opened up', $mail[0]['subject'] );
		$this->assertNotEmpty( $mail[0]['attachments'], 'The confirmation carries the .ics invite.' );

		// The host gets one summary.
		$host = $this->host_of( $event );
		$summaries = array_filter( $this->mail_to( $host->user_email ), fn( $m ) => false !== stripos( $m['subject'], 'Places filled from the waitlist' ) );
		$this->assertCount( 1, $summaries );
	}

	public function test_promotion_after_reject_and_after_raising_places(): void {
		$event  = $this->make_bookable_event();
		$booked = $this->fill( $event );
		$host   = (int) get_post_field( 'post_author', $event );

		$first  = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$second = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];

		// A host reject frees a place, which the queue takes.
		law_booking_cancel( $booked[0]['booking'], $host, 'host_reject', array( 'reason' => 'No show expected' ) );
		$this->assertSame( 'publish', get_post_status( $first ) );

		// Raising the places is the other way capacity opens.
		law_event_update_meta( $event, '_law_tickets_available', 3 );
		law_event_tickets_changed( $event, 2, 3, $host, 'test' );
		$this->assertSame( 'publish', get_post_status( $second ) );
		$this->assertStringContainsString( 'Places available changed: 2 → 3.', $this->log_text( $event ) );
	}

	public function test_lowering_places_promotes_nobody(): void {
		$event = $this->make_bookable_event( array( '_law_tickets_available' => 4 ) );
		$this->fill( $event );
		$entry = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];

		law_event_update_meta( $event, '_law_tickets_available', 2 );
		law_event_tickets_changed( $event, 4, 2, 0, 'test' );
		$this->assertSame( 'law-waitlisted', get_post_status( $entry ) );
		$this->assertSame( 0, law_event_tickets_remaining( $event ), 'Sold beyond capacity clamps at zero.' );
	}

	public function test_blocked_entry_is_skipped_in_place_and_told_once(): void {
		$event  = $this->make_bookable_event();
		$booked = $this->fill( $event );

		// The head of the queue books an overlapping event while waiting, so
		// their place cannot be taken up when it comes.
		$clasher = $this->make_user( 'attendee' );
		$first   = $this->make_waitlist( $event, $clasher )[0];
		$second  = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];

		$overlap = $this->make_overlapping_event();
		$this->make_booking( $overlap, $clasher );
		$this->mail = array();

		law_booking_cancel( $booked[0]['booking'], $booked[0]['user'], 'self' );

		$this->assertSame( 'law-waitlisted', get_post_status( $first ), 'A blocked entry keeps its place in the queue.' );
		$this->assertSame( 1, $this->position( $first ), 'And its position.' );
		$this->assertSame( 'publish', get_post_status( $second ), 'The queue moves past it rather than freezing.' );
		$this->assertStringContainsString( 'passed over', $this->log_text( $event ) );

		// They are told once, not on every pass.
		$clasher_email = get_userdata( $clasher )->user_email;
		$this->assertCount( 1, $this->mail_to( $clasher_email ) );
		law_booking_cancel( $booked[1]['booking'], $booked[1]['user'], 'self' );
		$this->assertCount( 1, $this->mail_to( $clasher_email ), 'The warning does not repeat on the next pass.' );
	}

	public function test_process_is_silent_when_there_is_nothing_to_do(): void {
		$event = $this->make_bookable_event();
		$before = count( law_event_log_entries( $event ) );
		$this->assertSame( array(), law_waitlist_process( $event, 'test' ) );
		$this->assertCount( $before, law_event_log_entries( $event ), 'An empty queue writes no log line.' );
	}

	/* Host controls _________________________________________________________ */

	public function test_reorder_moves_entries_and_ignores_stale_clicks(): void {
		$event = $this->make_bookable_event();
		$this->fill( $event );
		$host = (int) get_post_field( 'post_author', $event );

		$a = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$b = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$c = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];

		$result = law_waitlist_reorder( $c, 'top', $host, $this->position( $c ) );
		$this->assertTrue( $result['moved'] );
		$this->assertSame( array( $c, $a, $b ), array_map( fn( $p ) => (int) $p->ID, law_waitlist_for_event( $event ) ) );
		$this->assertSame( array( 1, 2, 3 ), array( $this->position( $c ), $this->position( $a ), $this->position( $b ) ) );

		$this->assertTrue( law_waitlist_reorder( $a, 'down', $host, $this->position( $a ) )['moved'] );
		$this->assertSame( array( $c, $b, $a ), array_map( fn( $p ) => (int) $p->ID, law_waitlist_for_event( $event ) ) );

		// The edges do nothing, and neither does a click from a stale page.
		$this->assertFalse( law_waitlist_reorder( $c, 'up', $host, $this->position( $c ) )['moved'] );
		$this->assertFalse( law_waitlist_reorder( $a, 'down', $host, $this->position( $a ) )['moved'] );
		$this->assertFalse( law_waitlist_reorder( $b, 'up', $host, 99 )['moved'], 'A stale position is a no-op.' );
		$this->assertSame( array( $c, $b, $a ), array_map( fn( $p ) => (int) $p->ID, law_waitlist_for_event( $event ) ) );

		$this->assertWPError( law_waitlist_reorder( $b, 'sideways', $host, $this->position( $b ) ), 'law_waitlist_bad_direction' );
		$this->assertStringContainsString( 'Waitlist reordered', $this->log_text( $event ) );
	}

	public function test_reorder_changes_who_takes_the_next_place(): void {
		$event  = $this->make_bookable_event();
		$booked = $this->fill( $event );
		$host   = (int) get_post_field( 'post_author', $event );

		$a = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$b = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$c = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];

		// Moving somebody up while the event is full promotes nobody yet.
		$this->assertTrue( law_waitlist_reorder( $c, 'top', $host, $this->position( $c ) )['moved'] );
		$this->assertSame( 'law-waitlisted', get_post_status( $c ) );

		// The next place goes to the new head of the queue, not the old one.
		law_booking_cancel( $booked[0]['booking'], $booked[0]['user'], 'self' );
		$this->assertSame( 'publish', get_post_status( $c ), 'The reordered entry takes the place.' );
		$this->assertSame( 'law-waitlisted', get_post_status( $a ) );
		$this->assertSame( 'law-waitlisted', get_post_status( $b ) );
		$this->assertSame( array( 1, 2 ), array( $this->position( $a ), $this->position( $b ) ), 'The queue closes up behind them.' );
	}

	public function test_manual_promote_overbooks_but_keeps_the_person_guards(): void {
		$event = $this->make_bookable_event();
		$this->fill( $event );
		$host  = (int) get_post_field( 'post_author', $event );
		$entry = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$this->mail = array();

		$result = law_waitlist_promote( $entry, $host );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['overbooked_by'] );
		$this->assertSame( 'publish', get_post_status( $entry ) );
		$this->assertSame( 3, law_event_attendee_total( $event ) );
		$this->assertSame( 0, law_event_tickets_remaining( $event ), 'Remaining clamps at zero rather than going negative.' );
		$this->assertStringContainsString( 'OVER-BOOKED', $this->log_text( $event ) );

		// The capacity warning is for a nearly full event, not an over-booked one.
		$warnings = array_filter( $this->mail_to( $this->host_of( $event )->user_email ), fn( $m ) => false !== stripos( $m['subject'], 'nearly full' ) );
		$this->assertEmpty( $warnings );

		// A clash is still a refusal, even by hand.
		$clasher = $this->make_user( 'attendee' );
		$blocked = $this->make_waitlist( $event, $clasher )[0];
		$overlap = $this->make_overlapping_event();
		$this->make_booking( $overlap, $clasher );
		$this->assertWPError( law_waitlist_promote( $blocked, $host ), 'law_booking_clash' );
		$this->assertSame( 'law-waitlisted', get_post_status( $blocked ) );
	}

	/* Leaving and protection ________________________________________________ */

	public function test_leaving_the_waitlist_emails_and_frees_the_position(): void {
		$event = $this->make_bookable_event();
		$this->fill( $event );

		$booker = $this->make_user( 'attendee' );
		$guest  = $this->unique_email( 'guest' );
		$ids    = $this->make_waitlist( $event, $booker, array( $this->row( 'Jane Smith', $guest ) ) );
		$this->mail = array();

		$this->assertTrue( law_booking_cancel( $ids[1], $booker, 'booker' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $ids[1] ) );
		$this->assertSame( '', law_event_meta( $ids[1], '_law_waitlist_position' ), 'A cancelled entry holds no position.' );
		$this->assertStringContainsString( 'taken off the waitlist', $this->mail_to( $guest )[0]['subject'] );

		$this->mail = array();
		$this->assertTrue( law_booking_cancel( $ids[0], $booker, 'self' ) );
		$this->assertStringContainsString( 'left the waitlist', $this->mail_to( get_userdata( $booker )->user_email )[0]['subject'] );
		$this->assertSame( 0, law_waitlist_count( $event ) );
	}

	public function test_status_guard_and_untrash_to_the_back(): void {
		$event = $this->make_bookable_event();
		$this->fill( $event );
		$first  = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$second = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];

		// Only the engine may seat a waitlisted booking.
		wp_update_post( array( 'ID' => $first, 'post_status' => 'publish' ) );
		$this->assertSame( 'law-waitlisted', get_post_status( $first ) );

		// A restored entry goes to the back, not back to where it was.
		wp_trash_post( $first );
		wp_untrash_post( $first );
		$this->assertSame( 'law-waitlisted', get_post_status( $first ), 'Untrash restores the waitlisted status.' );
		$this->assertGreaterThan( $this->position( $second ), $this->position( $first ) );
		$this->assertStringContainsString( 'back of the queue', $this->log_text( $event ) );
	}

	public function test_event_cancel_sweeps_the_waitlist_without_promoting(): void {
		$event = $this->make_bookable_event();
		$this->fill( $event );
		$entry = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		$waiter = get_userdata( (int) get_post_field( 'post_author', $entry ) );
		$this->mail = array();

		law_bookings_cancel_all_for_event( $event, $this->make_committee_user(), 'test' );

		$this->assertSame( 'law-cancelled', get_post_status( $entry ) );
		$this->assertStringNotContainsString( 'promoted from the waitlist', $this->log_text( $event ) );
		$mail = $this->mail_to( $waiter->user_email );
		$this->assertNotEmpty( $mail );
		$this->assertStringContainsString( 'Event cancelled', $mail[0]['subject'] );
	}

	public function test_event_trash_sweeps_the_waitlist_without_promoting(): void {
		$event = $this->make_bookable_event();
		$this->fill( $event );
		$entry = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];

		wp_trash_post( $event );
		$this->assertSame( 'law-cancelled', get_post_status( $entry ) );
		$this->assertStringNotContainsString( 'promoted from the waitlist', $this->log_text( $event ) );
	}

	public function test_waitlisted_entries_are_not_places(): void {
		$event = $this->make_bookable_event();
		$this->fill( $event );
		$waiter = $this->make_user( 'attendee' );
		$this->make_waitlist( $event, $waiter );

		// Not a clash source: waiting for one event must not block booking another.
		$this->assertNotContains( $event, law_user_booked_event_ids( $waiter ) );
		$overlap = $this->make_overlapping_event();
		$this->assertIsArray( $this->make_booking( $overlap, $waiter ) );

		// Not on the door list either.
		$emails = array_map( fn( $r ) => $r[4], law_booking_export_rows( $event )['rows'] );
		$this->assertNotContains( get_userdata( $waiter )->user_email, $emails );
	}

	public function test_pass_cap_promotes_ten_and_schedules_the_rest(): void {
		$event = $this->make_bookable_event( array( '_law_tickets_available' => 12 ) );
		$this->fill( $event );

		$entries = array();
		for ( $i = 0; $i < 12; $i++ ) {
			$entries[] = $this->make_waitlist( $event, $this->make_user( 'attendee' ) )[0];
		}

		// Free every place at once, without letting each cancel run a pass.
		$GLOBALS['law_waitlist_suspended'] = true;
		foreach ( law_bookings_for_event( $event, 'publish', -1 ) as $booking ) {
			law_booking_cancel( $booking->ID, (int) $booking->post_author, 'self' );
		}
		$GLOBALS['law_waitlist_suspended'] = false;

		$promoted = law_waitlist_process( $event, 'test' );
		$this->assertCount( LAW_WAITLIST_PASS_CAP, $promoted, 'One pass promotes at most the cap.' );
		$this->assertNotFalse( wp_next_scheduled( 'law_waitlist_resume', array( $event ) ), 'The rest is handed to cron.' );

		// Running the scheduled pass finishes the queue.
		wp_unschedule_event( wp_next_scheduled( 'law_waitlist_resume', array( $event ) ), 'law_waitlist_resume', array( $event ) );
		$rest = law_waitlist_process( $event, 'test' );
		$this->assertCount( 2, $rest );
		$this->assertSame( 0, law_waitlist_count( $event ) );
	}
}
