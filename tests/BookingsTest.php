<?php
/**
 * Bookings engine (functions/events/bookings.php), phase 1:
 *
 * - creation: numbering, the owner as row 0, account create vs link, the
 *   attendee role grant, flat index rows, the seat recount;
 * - guards: open (no ticket number, event started, not Confirmed), duplicates
 *   (across bookings, within one submission, cancelled bookings ignored),
 *   capacity (exact fill OK, overflow refused), clash (overlap refused and
 *   named, adjacent times allowed);
 * - mutations: add at capacity refused, the additional cap, removal recounts,
 *   last-row auto-cancel, owner self-removal keeps the booking manageable,
 *   cancel frees places;
 * - protection: the status guard reverts non-engine flips, untrash restores
 *   the trashed status, and wp-admin trash/delete recount backstops.
 */

require_once __DIR__ . '/class-law-test-case.php';

class BookingsTest extends LAW_Test_Case {

	private $source_before;
	private $counter_before;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before  = get_option( 'law_events_source', null );
		$this->counter_before = get_option( 'law_bookings_counter', null );
		update_option( 'law_events_source', 'cpt' );
	}

	protected function tearDown(): void {
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

	private function make_bookable_event( array $meta = array() ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_tickets_available' => 10,
					'_law_start'             => '2026-12-01 10:00',
					'_law_end'               => '2026-12-01 12:00',
				),
				$meta
			),
			'publish'
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

	/* Creation ______________________________________________________________ */

	public function test_create_numbers_owner_row_and_flat_index(): void {
		$event = $this->make_bookable_event();
		$owner = $this->make_user( 'attendee' );
		$guest = $this->unique_email( 'guest' );

		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Jane Guest', $guest ) ) );
		$this->assertIsInt( $booking );
		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( $event, (int) get_post_field( 'post_parent', $booking ) );
		$this->assertSame( $owner, (int) get_post_field( 'post_author', $booking ) );

		$number = (int) law_event_meta( $booking, '_law_booking_number' );
		$this->assertGreaterThan( 0, $number );
		$this->assertSame( 'Booking #' . $number, get_the_title( $booking ) );

		$rows = law_event_meta( $booking, '_law_attendee_rows' );
		$this->assertCount( 2, $rows );
		$this->assertSame( 1, (int) $rows[0]['is_owner'] );
		$this->assertSame( $owner, (int) $rows[0]['user_id'] );
		$this->assertSame( get_userdata( $owner )->user_email, $rows[0]['email'] );

		$guest_user = get_user_by( 'email', $guest );
		$this->assertNotFalse( $guest_user, 'The additional attendee gets an account.' );
		$this->assertContains( 'attendee', (array) $guest_user->roles );
		$this->assertSame( 'Test Org', get_user_meta( $guest_user->ID, 'organisation', true ) );
		$this->assertSame( 'Associate', get_user_meta( $guest_user->ID, 'job_title', true ) );
		$this->assertSame( (int) $guest_user->ID, (int) $rows[1]['user_id'] );

		$flat = array_map( 'intval', get_post_meta( $booking, '_law_booking_attendee' ) );
		$this->assertContains( $owner, $flat );
		$this->assertContains( (int) $guest_user->ID, $flat );

		$this->assertSame( 2, law_event_attendee_total( $event ) );
		$this->assertSame( 8, law_event_tickets_remaining( $event ) );
		$this->assertStringContainsString( 'Booking #' . $number . ' created', $this->log_text( $event ) );
		$this->assertStringContainsString( 'account created', $this->log_text( $event ) );

		// A second booking increments the number.
		$event2   = $this->make_bookable_event();
		$owner2   = $this->make_user( 'attendee' );
		$booking2 = $this->make_booking( $event2, $owner2, array() );
		$this->assertSame( $number + 1, (int) law_event_meta( $booking2, '_law_booking_number' ) );
	}

	public function test_create_links_existing_account_and_grants_attendee_role(): void {
		$event    = $this->make_bookable_event();
		$owner    = $this->make_user( 'event_host' ); // Not an attendee yet.
		$existing = $this->make_user( 'event_host' ); // Colleague with an account.
		$email    = get_userdata( $existing )->user_email;

		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Known Colleague', $email ) ) );
		$this->assertIsInt( $booking );

		// Both the owner and the linked colleague gain the attendee role.
		$this->assertContains( 'attendee', (array) get_userdata( $owner )->roles );
		$this->assertContains( 'attendee', (array) get_userdata( $existing )->roles );

		$rows = law_event_meta( $booking, '_law_attendee_rows' );
		$this->assertSame( $existing, (int) $rows[1]['user_id'], 'The existing account is linked, not duplicated.' );
		$this->assertStringContainsString( 'linked to existing account', $this->log_text( $event ) );
	}

	/* Guards ________________________________________________________________ */

	public function test_duplicate_guards(): void {
		$event = $this->make_bookable_event();
		$owner = $this->make_user( 'attendee' );
		$email = $this->unique_email( 'dup' );

		// The same email twice in one submission.
		$result = law_booking_create( $event, $owner, array( $this->row( 'One', $email ), $this->row( 'Two', $email ) ) );
		$this->assertWPError( $result, 'law_booking_duplicate' );

		// Owner books; a second booker lists the owner's email as a colleague.
		$booking = $this->make_booking( $event, $owner, array() );
		$this->assertIsInt( $booking );
		$other  = $this->make_user( 'attendee' );
		$result = law_booking_create( $event, $other, array( $this->row( 'Sneaky', get_userdata( $owner )->user_email ) ) );
		$this->assertWPError( $result, 'law_booking_duplicate' );

		// One active booking per person per event: the owner cannot book again.
		$result = law_booking_create( $event, $owner, array() );
		$this->assertWPError( $result, 'law_booking_duplicate' );

		// A cancelled booking's emails no longer block.
		$this->assertTrue( law_booking_cancel( $booking, $owner ) );
		$again = $this->make_booking( $event, $owner, array() );
		$this->assertIsInt( $again );
	}

	public function test_capacity_exact_fill_then_full(): void {
		$event = $this->make_bookable_event( array( '_law_tickets_available' => 4 ) );
		$owner = $this->make_user( 'attendee' );

		// Exactly filling the event is allowed (1 + 3 = 4).
		$booking = $this->make_booking( $event, $owner, array(
			$this->row( 'G1', $this->unique_email( 'g1' ) ),
			$this->row( 'G2', $this->unique_email( 'g2' ) ),
			$this->row( 'G3', $this->unique_email( 'g3' ) ),
		) );
		$this->assertIsInt( $booking );
		$this->assertSame( 0, law_event_tickets_remaining( $event ) );

		// The next booking is refused, and the refusal is logged.
		$other  = $this->make_user( 'attendee' );
		$result = law_booking_create( $event, $other, array() );
		$this->assertWPError( $result, 'law_booking_full' );
		$this->assertStringContainsString( 'Booking refused', $this->log_text( $event ) );
	}

	public function test_capacity_overflow_names_the_shortfall(): void {
		$event  = $this->make_bookable_event( array( '_law_tickets_available' => 2 ) );
		$owner  = $this->make_user( 'attendee' );
		$result = law_booking_create( $event, $owner, array(
			$this->row( 'G1', $this->unique_email( 'g1' ) ),
			$this->row( 'G2', $this->unique_email( 'g2' ) ),
		) );
		$this->assertWPError( $result, 'law_booking_full' );
		$this->assertStringContainsString( '2 places are left', $result->get_error_message() );
	}

	public function test_open_guard_states(): void {
		$owner = $this->make_user( 'attendee' );

		// No ticket number = "Bookings open soon", not unlimited.
		$unset = $this->make_event( array( '_law_start' => '2026-12-01 10:00' ), 'publish' );
		$this->assertNull( law_event_tickets_remaining( $unset ) );
		$this->assertWPError( law_booking_create( $unset, $owner, array() ), 'law_booking_not_open' );

		// Booking closes at event start.
		$past = $this->make_event( array( '_law_tickets_available' => 10, '_law_start' => '2026-01-05 10:00' ), 'publish' );
		$this->assertWPError( law_booking_create( $past, $owner, array() ), 'law_booking_closed' );

		// Only Confirmed events are bookable.
		$approved = $this->make_event( array( '_law_tickets_available' => 10, '_law_start' => '2026-12-01 10:00' ), 'law-approved' );
		$this->assertWPError( law_booking_create( $approved, $owner, array() ), 'law_booking_not_bookable' );

		// The whole surface is CPT-mode only.
		update_option( 'law_events_source', 'gf' );
		$open = $this->make_bookable_event();
		$this->assertWPError( law_booking_create( $open, $owner, array() ), 'law_booking_not_bookable' );
		update_option( 'law_events_source', 'cpt' );
	}

	public function test_clash_guard_overlap_refused_adjacent_allowed(): void {
		$owner   = $this->make_user( 'attendee' );
		$event_a = $this->make_bookable_event(); // 10:00–12:00
		$this->assertIsInt( $this->make_booking( $event_a, $owner, array() ) );

		// Overlapping event: refused, naming the conflict.
		$event_b = $this->make_bookable_event( array( '_law_start' => '2026-12-01 11:00', '_law_end' => '2026-12-01 13:00' ) );
		$result  = law_booking_create( $event_b, $owner, array() );
		$this->assertWPError( $result, 'law_booking_clash' );
		$this->assertStringContainsString( get_the_title( $event_a ), $result->get_error_message() );

		// Back-to-back is allowed.
		$event_c = $this->make_bookable_event( array( '_law_start' => '2026-12-01 12:00', '_law_end' => '2026-12-01 14:00' ) );
		$this->assertIsInt( $this->make_booking( $event_c, $owner, array() ) );

		// A seated additional attendee clashes too, named in the message.
		$guest   = $this->make_user( 'attendee' );
		$other   = $this->make_user( 'attendee' );
		$event_d = $this->make_bookable_event( array( '_law_start' => '2026-12-02 10:00', '_law_end' => '2026-12-02 12:00' ) );
		$event_e = $this->make_bookable_event( array( '_law_start' => '2026-12-02 11:00', '_law_end' => '2026-12-02 13:00' ) );
		$this->assertIsInt( $this->make_booking( $event_d, $other, array( $this->row( 'Busy Guest', get_userdata( $guest )->user_email ) ) ) );
		$third  = $this->make_user( 'attendee' );
		$result = law_booking_create( $event_e, $third, array( $this->row( 'Busy Guest', get_userdata( $guest )->user_email ) ) );
		$this->assertWPError( $result, 'law_booking_clash' );
		$this->assertStringContainsString( 'Busy Guest is', $result->get_error_message() );
	}

	public function test_clash_ignores_cancelled_bookings_and_defaults_missing_end(): void {
		$owner   = $this->make_user( 'attendee' );
		$event_a = $this->make_bookable_event(); // 10:00–12:00
		$booking = $this->make_booking( $event_a, $owner, array() );

		// A cancelled booking no longer clashes.
		$this->assertTrue( law_booking_cancel( $booking, $owner ) );
		$event_b = $this->make_bookable_event( array( '_law_start' => '2026-12-01 11:00', '_law_end' => '2026-12-01 13:00' ) );
		$this->assertIsInt( $this->make_booking( $event_b, $owner, array() ) );

		// A missing end is conservative: an "18:00 onwards" event blocks the
		// whole rest of its day (treated as ending 23:59).
		$open_ended = $this->make_bookable_event( array( '_law_start' => '2026-12-05 18:00', '_law_end' => '' ) );
		$other      = $this->make_user( 'attendee' );
		$this->assertIsInt( $this->make_booking( $open_ended, $other, array() ) );
		$late   = $this->make_bookable_event( array( '_law_start' => '2026-12-05 21:00', '_law_end' => '2026-12-05 22:00' ) );
		$result = law_booking_create( $late, $other, array() );
		$this->assertWPError( $result, 'law_booking_clash' );
		$this->assertStringContainsString( get_the_title( $open_ended ), $result->get_error_message() );
	}

	/* Mutations _____________________________________________________________ */

	public function test_add_attendee_caps_and_capacity(): void {
		$event   = $this->make_bookable_event( array( '_law_tickets_available' => 2 ) );
		$owner   = $this->make_user( 'attendee' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'G1', $this->unique_email( 'g1' ) ) ) );
		$this->assertIsInt( $booking );

		// The event is full: adding is refused on capacity.
		$result = law_booking_add_attendee( $booking, $this->row( 'G2', $this->unique_email( 'g2' ) ), $owner );
		$this->assertWPError( $result, 'law_booking_full' );

		// With room, adds work and recount follows.
		$roomy    = $this->make_bookable_event( array( '_law_start' => '2026-12-03 10:00', '_law_end' => '2026-12-03 12:00' ) );
		$booking2 = $this->make_booking( $roomy, $this->make_user( 'attendee' ), array() );
		$g        = array( $this->unique_email( 'a' ), $this->unique_email( 'b' ), $this->unique_email( 'c' ), $this->unique_email( 'd' ) );
		$this->assertTrue( law_booking_add_attendee( $booking2, $this->row( 'A', $g[0] ), $owner ) );
		$this->assertTrue( law_booking_add_attendee( $booking2, $this->row( 'B', $g[1] ), $owner ) );
		$this->assertTrue( law_booking_add_attendee( $booking2, $this->row( 'C', $g[2] ), $owner ) );
		$this->assertSame( 4, law_event_attendee_total( $roomy ) );
		foreach ( $g as $email ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$this->users[] = (int) $user->ID;
			}
		}

		// The 3-additional cap holds even with places left.
		$result = law_booking_add_attendee( $booking2, $this->row( 'D', $g[3] ), $owner );
		$this->assertWPError( $result, 'law_booking_too_many' );

		// Duplicate on add is refused.
		$result = law_booking_add_attendee( $booking, $this->row( 'Again', get_userdata( $owner )->user_email ), $owner );
		$this->assertWPError( $result, 'law_booking_duplicate' );
	}

	public function test_remove_recounts_and_last_row_cancels(): void {
		$event   = $this->make_bookable_event();
		$owner   = $this->make_user( 'attendee' );
		$guest   = $this->unique_email( 'guest' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Jane Guest', $guest ) ) );
		$this->assertSame( 2, law_event_attendee_total( $event ) );

		$this->assertTrue( law_booking_remove_attendee( $booking, $guest, $owner, 'owner' ) );
		$this->assertSame( 1, law_event_attendee_total( $event ) );
		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertStringContainsString( 'Attendee removed from Booking', $this->log_text( $event ) );

		// Removing the last person cancels the whole booking.
		$this->assertTrue( law_booking_remove_attendee( $booking, get_userdata( $owner )->user_email, $owner, 'self' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $booking ) );
		$this->assertSame( 0, law_event_attendee_total( $event ) );
		$this->assertStringContainsString( 'cancelled (last attendee removed)', $this->log_text( $event ) );
	}

	public function test_owner_self_removal_keeps_booking_manageable(): void {
		$event   = $this->make_bookable_event();
		$owner   = $this->make_user( 'attendee' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Jane Guest', $this->unique_email( 'guest' ) ) ) );

		$this->assertTrue( law_booking_remove_attendee( $booking, get_userdata( $owner )->user_email, $owner, 'self' ) );
		$this->assertSame( 'publish', get_post_status( $booking ), 'Colleagues keep their places.' );
		$this->assertSame( $owner, (int) get_post_field( 'post_author', $booking ), 'The owner still manages the booking.' );
		$this->assertSame( 1, law_event_attendee_total( $event ) );
		$this->assertNotContains( $event, law_user_booked_event_ids( $owner ), 'No seat means no clash and no "You\'re booked".' );

		// One active booking per person: even seatless (their email no longer
		// on any row), the owner cannot open a second booking for the event.
		$result = law_booking_create( $event, $owner, array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_booking_duplicate', $result->get_error_code() );
	}

	public function test_reject_by_host_context_logs_reason(): void {
		$event   = $this->make_bookable_event();
		$owner   = $this->make_user( 'attendee' );
		$guest   = $this->unique_email( 'guest' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Jane Guest', $guest ) ) );
		$host    = $this->make_committee_user();

		$this->assertTrue( law_booking_remove_attendee( $booking, $guest, $host, 'host_reject', array( 'reason' => 'Capacity reshuffle' ) ) );
		$this->assertStringContainsString( 'Attendee rejected from Booking', $this->log_text( $event ) );
		$this->assertStringContainsString( 'Capacity reshuffle', $this->log_text( $event ) );
	}

	public function test_cancel_frees_places(): void {
		$event   = $this->make_bookable_event( array( '_law_tickets_available' => 2 ) );
		$owner   = $this->make_user( 'attendee' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'G1', $this->unique_email( 'g1' ) ) ) );
		$this->assertSame( 0, law_event_tickets_remaining( $event ) );

		$other = $this->make_user( 'attendee' );
		$this->assertWPError( law_booking_create( $event, $other, array() ), 'law_booking_full' );

		$this->assertTrue( law_booking_cancel( $booking, $owner ) );
		$this->assertSame( 'law-cancelled', get_post_status( $booking ) );
		$this->assertSame( 2, law_event_tickets_remaining( $event ) );
		$this->assertIsInt( $this->make_booking( $event, $other, array() ) );

		// Idempotent.
		$this->assertTrue( law_booking_cancel( $booking, $owner ) );
	}

	/* Protection ____________________________________________________________ */

	public function test_status_guard_reverts_non_engine_flips(): void {
		$event   = $this->make_bookable_event();
		$owner   = $this->make_user( 'attendee' );
		$booking = $this->make_booking( $event, $owner, array() );

		// A quick-edit-style status write outside the engine is reverted.
		wp_update_post( array( 'ID' => $booking, 'post_status' => 'draft' ) );
		$this->assertSame( 'publish', get_post_status( $booking ) );

		$this->assertTrue( law_booking_cancel( $booking, $owner ) );
		wp_update_post( array( 'ID' => $booking, 'post_status' => 'publish' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $booking ), 'A cancelled booking cannot be resurrected outside the engine.' );
	}

	public function test_trash_untrash_and_delete_backstops(): void {
		$event   = $this->make_bookable_event();
		$owner   = $this->make_user( 'attendee' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Jane Guest', $this->unique_email( 'guest' ) ) ) );
		$this->assertSame( 2, law_event_attendee_total( $event ) );

		// wp-admin trash bypasses the engine; the backstop recounts.
		wp_trash_post( $booking );
		$this->assertSame( 0, law_event_attendee_total( $event ) );
		$this->assertStringContainsString( 'Places sold recounted', $this->log_text( $event ) );

		// Untrash restores the pre-trash status (not core's draft) and recounts.
		wp_untrash_post( $booking );
		$this->assertSame( 'publish', get_post_status( $booking ) );
		$this->assertSame( 2, law_event_attendee_total( $event ) );

		// Hard delete recounts too.
		wp_delete_post( $booking, true );
		$this->assertSame( 0, law_event_attendee_total( $event ) );
	}

	public function test_capacity_warning_latch_fires_once_and_rearms(): void {
		$event   = $this->make_bookable_event( array( '_law_tickets_available' => 8 ) );
		$owner   = $this->make_user( 'attendee' );
		$g1      = $this->unique_email( 'g1' );
		$g2      = $this->unique_email( 'g2' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'G1', $g1 ), $this->row( 'G2', $g2 ) ) );
		// 8 - 3 = 5 remaining: the warning fires and latches.
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_capacity_warned' ) );
		$this->assertSame( 1, substr_count( $this->log_text( $event ), 'Capacity warning sent' ) );

		// Another booking below the threshold does not re-fire.
		$other = $this->make_user( 'attendee' );
		$this->make_booking( $event, $other, array() );
		$this->assertSame( 1, substr_count( $this->log_text( $event ), 'Capacity warning sent' ) );

		// Removals lifting remaining above 5 (sold 4 → 2) re-arm the latch.
		$this->assertTrue( law_booking_remove_attendee( $booking, $g1, $owner, 'owner' ) );
		$this->assertTrue( law_booking_remove_attendee( $booking, $g2, $owner, 'owner' ) );
		$this->assertSame( 6, law_event_tickets_remaining( $event ) );
		$this->assertEmpty( law_event_meta( $event, '_law_capacity_warned' ) );
	}

	public function test_last_row_auto_cancel_aborts_when_a_seat_appeared(): void {
		$event   = $this->make_bookable_event();
		$owner   = $this->make_user( 'attendee' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Jane Guest', $this->unique_email( 'guest' ) ) ) );

		// The race fix: a cancel with the last-row context must re-check the
		// rows under its own lock and keep a booking someone was just seated on.
		$this->assertTrue( law_booking_cancel( $booking, $owner, 'last_attendee_removed' ) );
		$this->assertSame( 'publish', get_post_status( $booking ), 'A populated booking survives a stale last-row cancel.' );

		// The genuine last-row path still cancels.
		law_booking_set_attendee_rows( $booking, array() );
		$this->assertTrue( law_booking_cancel( $booking, $owner, 'last_attendee_removed' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $booking ) );
	}

	public function test_event_trash_sweeps_active_bookings(): void {
		law_events_update_settings( array( 'committee_emails' => array( 'committee-list@example.test' ) ) );
		$event   = $this->make_bookable_event();
		$owner   = $this->make_user( 'attendee' );
		$booking = $this->make_booking( $event, $owner, array() );

		// wp-admin trash bypasses the workflow cancel entirely; the sweep hook
		// must cancel and email rather than orphan the booking silently.
		wp_trash_post( $event );
		$this->assertSame( 'law-cancelled', get_post_status( $booking ) );

		// Trashing a post trashes its comments too, so the log lines sit at
		// 'post-trashed' (they come back on untrash) — read them there.
		$log = implode( "\n", array_map(
			fn( $c ) => $c->comment_content,
			get_comments( array( 'post_id' => $event, 'status' => 'post-trashed', 'type' => LAW_EVENT_LOG_TYPE ) )
		) );
		$this->assertStringContainsString( 'active booking: cancelled, attendees emailed', $log );
		$this->assertStringContainsString( 'Email to attendee &gt; event cancelled', $log );
	}

	public function test_clash_treats_inverted_end_as_open_ended(): void {
		$owner = $this->make_user( 'attendee' );
		// End BEFORE start (a fat-fingered end date): the guard must fall back
		// to end-of-day rather than making the overlap test unsatisfiable.
		$broken = $this->make_bookable_event( array( '_law_start' => '2026-12-07 10:00', '_law_end' => '2026-12-06 12:00' ) );
		$this->assertIsInt( $this->make_booking( $broken, $owner, array() ) );

		$later  = $this->make_bookable_event( array( '_law_start' => '2026-12-07 20:00', '_law_end' => '2026-12-07 21:00' ) );
		$result = law_booking_create( $later, $owner, array() );
		$this->assertWPError( $result, 'law_booking_clash' );
	}

	public function test_event_cancel_sweep_cancels_bookings(): void {
		law_events_update_settings( array( 'committee_emails' => array( 'committee-list@example.test' ) ) );
		$event   = $this->make_bookable_event();
		$owner   = $this->make_user( 'attendee' );
		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Jane Guest', $this->unique_email( 'guest' ) ) ) );
		$this->assertSame( 2, law_event_attendee_total( $event ) );

		$committee = $this->make_committee_user();
		$result    = law_event_workflow_transition(
			$event,
			'cancel',
			array( 'reason' => 'Venue flooded', 'actor_id' => $committee )
		);
		$this->assertTrue( $result );

		$this->assertSame( 'law-cancelled', get_post_status( $booking ), 'The sweep cancels active bookings.' );
		$this->assertSame( 0, law_event_attendee_total( $event ) );
		$log = $this->log_text( $event );
		$this->assertStringContainsString( 'Event cancelled with 1 active booking', $log );
		$this->assertStringContainsString( 'Email to attendee &gt; event cancelled', $log );
	}

	/* Export ________________________________________________________________ */

	public function test_export_rows_active_only_grouped_and_split(): void {
		$event = $this->make_bookable_event( array( '_law_venue' => 'Export Hall' ) );
		$owner = $this->make_user( 'attendee' );
		wp_update_user( array( 'ID' => $owner, 'first_name' => 'Owner', 'last_name' => 'Person' ) );
		$guest = $this->unique_email( 'guest' );

		$booking = $this->make_booking( $event, $owner, array( $this->row( 'Jane Two-Names Guest', $guest ) ) );

		// A cancelled booking's attendees never export.
		$other     = $this->make_user( 'attendee' );
		$cancelled = $this->make_booking( $event, $other, array() );
		law_booking_cancel( $cancelled, $other );

		$data = law_booking_export_rows( $event );
		$this->assertStringContainsString( 'Attendees for', $data['title'] );
		$this->assertStringContainsString( get_the_title( $event ), $data['title'] );
		$this->assertSame(
			array( 'Booking ID', 'First name', 'Second name', 'Email', 'Organisation', 'Job title', 'Accessibility', 'Dietary' ),
			$data['columns']
		);
		$this->assertCount( 2, $data['rows'], 'Owner + guest; the cancelled booking is excluded.' );

		$number = (int) law_event_meta( $booking, '_law_booking_number' );
		// The owner row: linked account's first/last name wins.
		$this->assertSame( array( $number, 'Owner', 'Person' ), array_slice( $data['rows'][0], 0, 3 ) );
		// The guest: engine-created account got the split name, so it matches
		// the snapshot's first-space split either way.
		$this->assertSame( 'Jane', $data['rows'][1][1] );
		$this->assertSame( 'Two-Names Guest', $data['rows'][1][2] );
		$this->assertSame( $guest, $data['rows'][1][3] );
		$this->assertSame( 'Test Org', $data['rows'][1][4] );
		$this->assertSame( 'Associate', $data['rows'][1][5] );
	}

	public function test_profile_requirements_join_and_other_text(): void {
		$profile = array(
			'dietary'       => array( 'Vegan', 'Nut allergy', 'Other' ),
			'dietary_other' => 'No nightshades',
		);
		$this->assertSame( 'Vegan, Nut allergy, Other: No nightshades', law_booking_profile_requirements( $profile, 'dietary' ) );
		$this->assertSame( '', law_booking_profile_requirements( array(), 'accessibility' ) );
	}

	/* Helpers _______________________________________________________________ */

	private function assertWPError( $result, string $code ): void {
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( $code, $result->get_error_code() );
	}
}
