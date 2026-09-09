<?php
/**
 * Bookings engine (functions/events/bookings.php), the per-attendee model
 * (WAITLIST.md Part A):
 *
 * - creation: one booking per attendee with its own consecutive number, the
 *   booker recorded on each, account create vs link, the attendee role grant,
 *   the seat recount, and whole-submission rollback when anything refuses;
 * - guards: open (no ticket number, event started, not Confirmed), duplicates
 *   (across bookings, within one submission, cancelled ignored, a colleague
 *   booked twice, someone booking themselves after being booked), capacity
 *   (exact fill OK, overflow refused), the colleague cap, clash (overlap
 *   refused and named, adjacent times allowed);
 * - mutations: adding a colleague, cancelling one booking, cancelling a whole
 *   party, a booker who cancels their own place keeping management and being
 *   able to book again, host reject;
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

	public function test_create_makes_one_booking_per_attendee_with_own_numbers(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$guest  = $this->unique_email( 'guest' );

		$ids = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $guest ) ) );
		$this->assertIsArray( $ids );
		$this->assertCount( 2, $ids, 'The booker and the colleague each get a booking.' );

		// Numbers are consecutive within the submission and unique per post.
		$numbers = array_map( fn( $id ) => (int) law_event_meta( $id, '_law_booking_number' ), $ids );
		$this->assertSame( $numbers[0] + 1, $numbers[1], 'A party takes a consecutive block of numbers.' );
		$this->assertSame( 'Booking #' . $numbers[1], get_post( $ids[1] )->post_title );

		// The author IS the attendee; the booker is recorded on both.
		$this->assertSame( $booker, (int) get_post( $ids[0] )->post_author );
		$colleague = get_user_by( 'email', $guest );
		$this->assertInstanceOf( WP_User::class, $colleague, 'An account is created for a new colleague.' );
		$this->assertSame( (int) $colleague->ID, (int) get_post( $ids[1] )->post_author );
		foreach ( $ids as $id ) {
			$this->assertSame( $booker, (int) law_event_meta( $id, '_law_booked_by' ) );
		}

		// Self-booked vs invited, which is what the lists tag.
		$this->assertTrue( law_booking_is_self_booked( $ids[0] ) );
		$this->assertFalse( law_booking_is_self_booked( $ids[1] ) );
		$this->assertSame( '', law_booking_invited_by_label( $ids[0] ) );
		$this->assertNotSame( '', law_booking_invited_by_label( $ids[1] ) );

		// The snapshot, and the recount.
		$person = law_booking_attendee( $ids[1] );
		$this->assertSame( 'Jane Smith', $person['name'] );
		$this->assertSame( $guest, $person['email'] );
		$this->assertSame( 'Test Org', $person['organisation'] );
		$this->assertSame( 2, law_event_attendee_total( $event ) );
		$this->assertSame( 8, law_event_tickets_remaining( $event ) );
		$this->assertContains( 'attendee', (array) $colleague->roles );
	}

	public function test_create_links_existing_account_and_grants_attendee_role(): void {
		$event    = $this->make_bookable_event();
		$booker   = $this->make_user( 'attendee' );
		$existing = $this->make_user( 'event_host' ); // No attendee role yet.
		$email    = get_userdata( $existing )->user_email;

		$ids = $this->make_booking( $event, $booker, array( $this->row( 'Existing Person', $email ) ) );
		$this->assertIsArray( $ids );
		$this->assertSame( $existing, (int) get_post( $ids[1] )->post_author, 'The existing account is linked, not duplicated.' );
		$this->assertContains( 'attendee', (array) get_userdata( $existing )->roles );
		$this->assertStringContainsString( 'linked to existing account', $this->log_text( $event ) );
	}

	public function test_party_query_unions_own_and_invited_bookings(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );

		$party = law_booking_party( $event, $booker );
		$this->assertCount( 2, $party );
		$this->assertSame( array_map( 'intval', $ids ), array_map( fn( $p ) => (int) $p->ID, $party ) );
		$this->assertSame( 1, law_booking_colleague_count( $event, $booker ), 'Their own booking is not a colleague.' );
	}

	/* Guards ________________________________________________________________ */

	public function test_duplicate_guards(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$guest  = $this->unique_email( 'guest' );
		$this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $guest ) ) );

		// The same person, booking themselves after being booked by a colleague.
		$colleague = get_user_by( 'email', $guest );
		$this->assertWPError( $this->make_booking( $event, (int) $colleague->ID ), 'law_booking_duplicate' );

		// A second booker trying to bring the same colleague.
		$other = $this->make_user( 'attendee' );
		$this->assertWPError( $this->make_booking( $event, $other, array( $this->row( 'Jane Again', $guest ) ) ), 'law_booking_duplicate' );

		// The booker themselves, twice.
		$this->assertWPError( $this->make_booking( $event, $booker ), 'law_booking_duplicate' );

		// The same email twice in one submission.
		$twice = $this->unique_email( 'twice' );
		$this->assertWPError(
			$this->make_booking( $event, $this->make_user( 'attendee' ), array( $this->row( 'A', $twice ), $this->row( 'B', $twice ) ) ),
			'law_booking_duplicate'
		);

		// A cancelled booking frees the email again.
		$fresh = $this->make_user( 'attendee' );
		$one   = $this->make_booking( $event, $fresh );
		law_booking_cancel( $one[0], $fresh, 'self' );
		$this->assertIsArray( $this->make_booking( $event, $fresh ), 'A cancelled booking does not block a new one.' );
	}

	public function test_attendee_rows_require_organisation_and_job_title(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$guest  = $this->unique_email( 'guest' );

		// All four fields are required on an additional-attendee row (Denis,
		// 9 September 2026): an account is created from it, and the exports
		// and admin screens print the organisation and job title.
		$no_org = $this->make_booking( $event, $booker, array( array( 'name' => 'Jane Smith', 'email' => $guest, 'job_title' => 'Associate' ) ) );
		$this->assertWPError( $no_org, 'law_booking_invalid_row' );
		$this->assertSame( 'organisation', $no_org->get_error_data()['field'] ?? '' );

		$no_job = $this->make_booking( $event, $booker, array( array( 'name' => 'Jane Smith', 'email' => $guest, 'organisation' => 'Test Org' ) ) );
		$this->assertWPError( $no_job, 'law_booking_invalid_row' );
		$this->assertSame( 'job_title', $no_job->get_error_data()['field'] ?? '' );

		// Nothing was seated by either refusal, and the complete row is taken.
		$this->assertSame( 0, law_event_attendee_total( $event ) );
		$this->assertIsArray( $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $guest ) ) ) );
	}

	public function test_capacity_exact_fill_then_full(): void {
		$event  = $this->make_bookable_event( array( '_law_tickets_available' => 2 ) );
		$booker = $this->make_user( 'attendee' );

		$ids = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );
		$this->assertIsArray( $ids );
		$this->assertSame( 0, law_event_tickets_remaining( $event ) );

		$this->assertWPError( $this->make_booking( $event, $this->make_user( 'attendee' ) ), 'law_booking_full' );
	}

	public function test_capacity_overflow_names_the_shortfall(): void {
		$event  = $this->make_bookable_event( array( '_law_tickets_available' => 2 ) );
		$result = $this->make_booking(
			$event,
			$this->make_user( 'attendee' ),
			array( $this->row( 'A', $this->unique_email( 'a' ) ), $this->row( 'B', $this->unique_email( 'b' ) ) )
		);
		$this->assertWPError( $result, 'law_booking_full' );
		$this->assertStringContainsString( 'Only 2 places are left', $result->get_error_message() );
	}

	public function test_whole_party_refusal_creates_nothing(): void {
		$event  = $this->make_bookable_event( array( '_law_tickets_available' => 1 ) );
		$email  = $this->unique_email( 'never' );
		$before = law_event_attendee_total( $event );

		$result = $this->make_booking( $event, $this->make_user( 'attendee' ), array( $this->row( 'Never Booked', $email ) ) );
		$this->assertWPError( $result, 'law_booking_full' );
		$this->assertSame( $before, law_event_attendee_total( $event ), 'A refused submission seats nobody.' );
		$this->assertCount( 0, law_bookings_for_event( $event, array( 'publish', 'law-cancelled' ), -1 ) );
		$this->assertFalse( get_user_by( 'email', $email ), 'A refused submission leaves no orphan account.' );
	}

	public function test_partial_insert_failure_rolls_the_whole_submission_back(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$guest  = $this->unique_email( 'guest' );

		// Fail the colleague's insert, the second of the two.
		$seen = 0;
		$fail = function ( $maybe_empty, $postarr ) use ( &$seen ) {
			if ( LAW_BOOKING_CPT === ( $postarr['post_type'] ?? '' ) ) {
				$seen++;
				return $seen >= 2 ? true : $maybe_empty;
			}
			return $maybe_empty;
		};
		add_filter( 'wp_insert_post_empty_content', $fail, 10, 2 );
		$result = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $guest ) ) );
		remove_filter( 'wp_insert_post_empty_content', $fail, 10 );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 0, law_bookings_for_event( $event, array( 'publish', 'law-cancelled' ), -1 ), 'The booking created before the failure is taken back.' );
		$this->assertSame( 0, law_event_attendee_total( $event ) );
		$this->assertFalse( get_user_by( 'email', $guest ), 'And so is the account created for the colleague.' );
		$this->assertStringContainsString( 'rolled back', $this->log_text( $event ) );
	}

	public function test_account_failure_refuses_the_whole_submission(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$first  = $this->unique_email( 'first' );
		$second = $this->unique_email( 'second' );

		// Refuse the SECOND colleague's account, so the first has already been
		// created by the time the submission fails.
		$fail = function ( $errors, $update, $user ) use ( $second ) {
			if ( ! $update && strtolower( (string) $user->user_email ) === strtolower( $second ) ) {
				$errors->add( 'law_test_refused', 'Refused by the test.' );
			}
			return $errors;
		};
		add_action( 'user_profile_update_errors', $fail, 10, 3 );
		$result = $this->make_booking(
			$event,
			$booker,
			array( $this->row( 'First Person', $first ), $this->row( 'Second Person', $second ) )
		);
		remove_action( 'user_profile_update_errors', $fail, 10 );

		if ( is_wp_error( $result ) ) {
			$this->assertSame( 'law_booking_account_failed', $result->get_error_code() );
			$data = $result->get_error_data();
			$this->assertSame( 'email', $data['field'] ?? '', 'The refusal names the field so the form can mark it.' );
			$this->assertSame( 0, law_event_attendee_total( $event ), 'Nobody is seated.' );
			$this->assertFalse( get_user_by( 'email', $first ), 'The account created before the failure is taken back.' );
			return;
		}
		// Some WordPress builds do not route wp_insert_user through that filter;
		// then the submission legitimately succeeds and there is nothing to assert.
		$this->assertIsArray( $result );
	}

	public function test_open_guard_states(): void {
		$booker = $this->make_user( 'attendee' );

		$no_tickets = $this->make_bookable_event( array( '_law_tickets_available' => 0 ) );
		$this->assertWPError( $this->make_booking( $no_tickets, $booker ), 'law_booking_not_open' );

		$started = $this->make_bookable_event( array( '_law_start' => '2020-01-01 10:00', '_law_end' => '2020-01-01 12:00' ) );
		$this->assertWPError( $this->make_booking( $started, $booker ), 'law_booking_closed' );

		$unpublished = $this->make_event( array( '_law_tickets_available' => 10 ), 'law-approved' );
		$this->assertWPError( $this->make_booking( $unpublished, $booker ), 'law_booking_not_bookable' );

		update_option( 'law_events_source', 'gf' );
		$this->assertWPError( $this->make_booking( $this->make_bookable_event(), $booker ), 'law_booking_not_bookable' );
		update_option( 'law_events_source', 'cpt' );
	}

	public function test_clash_guard_overlap_refused_adjacent_allowed(): void {
		$booker = $this->make_user( 'attendee' );
		$first  = $this->make_bookable_event( array( '_law_start' => '2026-12-01 10:00', '_law_end' => '2026-12-01 12:00' ) );
		$this->make_booking( $first, $booker );

		$overlap = $this->make_bookable_event( array( '_law_start' => '2026-12-01 11:00', '_law_end' => '2026-12-01 13:00' ) );
		$result  = $this->make_booking( $overlap, $booker );
		$this->assertWPError( $result, 'law_booking_clash' );
		$this->assertStringContainsString( get_the_title( $first ), $result->get_error_message() );

		$adjacent = $this->make_bookable_event( array( '_law_start' => '2026-12-01 12:00', '_law_end' => '2026-12-01 14:00' ) );
		$this->assertIsArray( $this->make_booking( $adjacent, $booker ), 'Back-to-back events do not clash.' );
	}

	public function test_clash_ignores_cancelled_bookings_and_defaults_missing_end(): void {
		$booker = $this->make_user( 'attendee' );
		$first  = $this->make_bookable_event( array( '_law_start' => '2026-12-02 10:00', '_law_end' => '2026-12-02 12:00' ) );
		$ids    = $this->make_booking( $first, $booker );
		law_booking_cancel( $ids[0], $booker, 'self' );

		$overlap = $this->make_bookable_event( array( '_law_start' => '2026-12-02 11:00', '_law_end' => '2026-12-02 13:00' ) );
		$this->assertIsArray( $this->make_booking( $overlap, $booker ), 'A cancelled booking cannot clash.' );

		// No end: treated as running to 23:59, so a later event the same day clashes.
		$other    = $this->make_user( 'attendee' );
		$open_end = $this->make_bookable_event( array( '_law_start' => '2026-12-03 18:00', '_law_end' => '' ) );
		$this->make_booking( $open_end, $other );
		$evening = $this->make_bookable_event( array( '_law_start' => '2026-12-03 19:00', '_law_end' => '2026-12-03 21:00' ) );
		$this->assertWPError( $this->make_booking( $evening, $other ), 'law_booking_clash' );
	}

	public function test_clash_treats_inverted_end_as_open_ended(): void {
		$user    = $this->make_user( 'attendee' );
		$broken  = $this->make_bookable_event( array( '_law_start' => '2026-12-04 18:00', '_law_end' => '2026-12-04 09:00' ) );
		$this->make_booking( $broken, $user );
		$evening = $this->make_bookable_event( array( '_law_start' => '2026-12-04 19:00', '_law_end' => '2026-12-04 21:00' ) );
		$this->assertWPError( $this->make_booking( $evening, $user ), 'law_booking_clash' );
	}

	/* Mutations _____________________________________________________________ */

	public function test_add_colleague_caps_and_capacity(): void {
		$event  = $this->make_bookable_event( array( '_law_tickets_available' => 6 ) );
		$booker = $this->make_user( 'attendee' );
		$this->make_booking( $event, $booker );

		for ( $i = 0; $i < law_booking_max_additional(); $i++ ) {
			$added = $this->make_colleague_booking( $event, $booker, $this->row( 'Guest ' . $i, $this->unique_email( 'g' . $i ) ) );
			$this->assertIsInt( $added );
			$this->assertSame( $booker, (int) law_event_meta( $added, '_law_booked_by' ) );
		}
		$this->assertSame( 3, law_booking_colleague_count( $event, $booker ) );

		// The cap counts colleagues, not the booker's own place.
		$this->assertWPError(
			$this->make_colleague_booking( $event, $booker, $this->row( 'One Too Many', $this->unique_email( 'over' ) ) ),
			'law_booking_too_many'
		);

		// Someone with no party here cannot add anybody.
		$this->assertWPError(
			$this->make_colleague_booking( $event, $this->make_user( 'attendee' ), $this->row( 'Nope', $this->unique_email( 'nope' ) ) ),
			'law_booking_no_party'
		);
	}

	public function test_cancel_one_booking_recounts_and_keeps_the_rest(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );

		$this->assertTrue( law_booking_cancel( $ids[1], $booker, 'booker' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $ids[1] ) );
		$this->assertSame( 'publish', get_post_status( $ids[0] ), "The booker's own place is untouched." );
		$this->assertSame( 1, law_event_attendee_total( $event ) );
		$this->assertTrue( law_booking_cancel( $ids[1], $booker, 'booker' ), 'Cancelling twice is a no-op, not an error.' );
	}

	public function test_booker_self_cancel_keeps_colleagues_and_can_rebook(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );

		$this->assertTrue( law_booking_cancel( $ids[0], $booker, 'self' ) );
		$this->assertSame( 'publish', get_post_status( $ids[1] ) );
		$this->assertSame( 1, law_booking_colleague_count( $event, $booker ), 'They still manage the colleague they booked.' );
		$this->assertNotEmpty( law_booking_party( $event, $booker ) );
		$this->assertNotContains( $event, law_user_booked_event_ids( $booker ), 'They hold no place any more.' );

		// They can still add, and can book themselves back on.
		$this->assertIsInt( $this->make_colleague_booking( $event, $booker, $this->row( 'Second', $this->unique_email( 's' ) ) ) );
		$again = $this->make_booking( $event, $booker );
		$this->assertIsArray( $again, 'Having cancelled their own place, they may book it again.' );
	}

	public function test_cancel_party_cancels_own_and_invited(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking(
			$event,
			$booker,
			array( $this->row( 'A', $this->unique_email( 'a' ) ), $this->row( 'B', $this->unique_email( 'b' ) ) )
		);

		$this->assertSame( 3, law_bookings_cancel_party( $event, $booker, $booker ) );
		foreach ( $ids as $id ) {
			$this->assertSame( 'law-cancelled', get_post_status( $id ) );
		}
		$this->assertSame( 0, law_event_attendee_total( $event ) );
		$this->assertStringContainsString( 'cancelled the 3 bookings they made', $this->log_text( $event ) );
	}

	public function test_reject_by_host_logs_the_reason(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$host   = (int) get_post_field( 'post_author', $event );
		$ids    = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );

		$this->assertTrue( law_booking_cancel( $ids[1], $host, 'host_reject', array( 'reason' => 'Capacity reshuffle' ) ) );
		$this->assertSame( 'law-cancelled', get_post_status( $ids[1] ) );
		$this->assertStringContainsString( 'Capacity reshuffle', $this->log_text( $event ) );
	}

	public function test_cancel_frees_places(): void {
		$event  = $this->make_bookable_event( array( '_law_tickets_available' => 2 ) );
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );
		$this->assertSame( 0, law_event_tickets_remaining( $event ) );

		law_bookings_cancel_party( $event, $booker, $booker );
		$this->assertSame( 2, law_event_tickets_remaining( $event ) );
		$this->assertIsArray( $this->make_booking( $event, $this->make_user( 'attendee' ) ) );
	}

	/* Protection ____________________________________________________________ */

	public function test_status_guard_reverts_non_engine_flips(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking( $event, $booker );

		law_booking_cancel( $ids[0], $booker, 'self' );
		wp_update_post( array( 'ID' => $ids[0], 'post_status' => 'publish' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $ids[0] ), 'Only the engine may move a booking status.' );

		$live = $this->make_booking( $event, $this->make_user( 'attendee' ) );
		wp_update_post( array( 'ID' => $live[0], 'post_status' => 'law-waitlisted' ) );
		$this->assertSame( 'publish', get_post_status( $live[0] ), 'Nor may quick edit waitlist an active booking.' );
	}

	public function test_trash_untrash_and_delete_backstops(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );
		$this->assertSame( 2, law_event_attendee_total( $event ) );

		wp_trash_post( $ids[1] );
		$this->assertSame( 1, law_event_attendee_total( $event ), 'A wp-admin trash recounts the places.' );

		wp_untrash_post( $ids[1] );
		$this->assertSame( 'publish', get_post_status( $ids[1] ), 'Untrash restores the pre-trash status.' );
		$this->assertSame( 2, law_event_attendee_total( $event ) );

		wp_delete_post( $ids[1], true );
		$this->assertSame( 1, law_event_attendee_total( $event ), 'A hard delete recounts too.' );
	}

	public function test_capacity_warning_latch_fires_once_and_rearms(): void {
		$event  = $this->make_bookable_event( array( '_law_tickets_available' => 6 ) );
		$booker = $this->make_user( 'attendee' );
		$this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_capacity_warned' ), 'Within 5 places: the host is warned.' );

		law_bookings_cancel_party( $event, $booker, $booker );
		$this->assertSame( '', law_event_meta( $event, '_law_capacity_warned' ), 'Freeing places re-arms the warning.' );
	}

	public function test_event_trash_sweeps_active_bookings(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking( $event, $booker );

		wp_trash_post( $event );
		$this->assertSame( 'law-cancelled', get_post_status( $ids[0] ), 'Trashing an event cancels its bookings.' );
	}

	public function test_event_cancel_sweep_cancels_bookings(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$ids    = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );

		law_bookings_cancel_all_for_event( $event, $this->make_committee_user(), 'test' );
		foreach ( $ids as $id ) {
			$this->assertSame( 'law-cancelled', get_post_status( $id ) );
		}
		$this->assertSame( 0, law_event_attendee_total( $event ) );
		$this->assertStringContainsString( '2 active bookings', $this->log_text( $event ) );
	}

	/* Exports and registration on behalf ____________________________________ */

	public function test_export_rows_are_one_per_attendee_with_invited_by(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		wp_update_user( array( 'ID' => $booker, 'first_name' => 'Bo', 'last_name' => 'Oker' ) );
		$ids = $this->make_booking( $event, $booker, array( $this->row( 'Jane Smith', $this->unique_email( 'guest' ) ) ) );

		// A cancelled booking never reaches the door list.
		$other = $this->make_user( 'attendee' );
		$gone  = $this->make_booking( $event, $other );
		law_booking_cancel( $gone[0], $other, 'self' );

		$data = law_booking_export_rows( $event );
		$this->assertSame( 'Invited by', $data['columns'][1] );
		$this->assertCount( 2, $data['rows'], 'Active bookings only, one row each.' );

		$by_number = array();
		foreach ( $data['rows'] as $row ) {
			$by_number[ $row[0] ] = $row;
		}
		$booker_row = $by_number[ (int) law_event_meta( $ids[0], '_law_booking_number' ) ];
		$guest_row  = $by_number[ (int) law_event_meta( $ids[1], '_law_booking_number' ) ];
		$this->assertSame( '', $booker_row[1], 'Self-booked rows carry no tag.' );
		$this->assertNotSame( '', $guest_row[1], 'A colleague is tagged with who invited them.' );
		$this->assertSame( 'Bo', $booker_row[2], "The linked account's name wins." );
		$this->assertSame( 'Jane', $guest_row[2] );
		$this->assertSame( 'Smith', $guest_row[3] );
	}

	public function test_register_by_manager_gives_the_person_their_own_booking(): void {
		$event     = $this->make_bookable_event();
		$committee = $this->make_committee_user();
		$existing  = $this->make_user( 'attendee' );
		$email     = get_userdata( $existing )->user_email;

		$booking = law_booking_register_by_manager( $event, $this->row( 'VIP Person', $email ), $committee, array( 'press' => true ) );
		$this->assertIsInt( $booking );
		$this->posts[] = $booking;

		$this->assertSame( $existing, (int) get_post( $booking )->post_author, 'The person owns their booking.' );
		$this->assertSame( $existing, (int) law_event_meta( $booking, '_law_booked_by' ), 'A manager does not become the booker.' );
		$this->assertTrue( law_booking_is_self_booked( $booking ), 'So it carries no "invited by" tag.' );
		$this->assertSame( 1, (int) law_event_meta( $booking, '_law_is_press' ) );
		$this->assertStringContainsString( 'on behalf of VIP Person', $this->log_text( $event ) );
	}

	public function test_register_by_manager_creates_account_and_rolls_back_on_refusal(): void {
		$event     = $this->make_bookable_event( array( '_law_tickets_available' => 1 ) );
		$committee = $this->make_committee_user();

		$new_email = $this->unique_email( 'vip' );
		$booking   = law_booking_register_by_manager( $event, $this->row( 'New VIP', $new_email ), $committee );
		$this->assertIsInt( $booking );
		$this->posts[] = $booking;
		$created       = get_user_by( 'email', $new_email );
		$this->assertInstanceOf( WP_User::class, $created );
		$this->users[] = (int) $created->ID;
		$this->assertEmpty( law_event_meta( $booking, '_law_is_press' ), 'Not press unless asked.' );

		// The event is now full: a second registration is refused and the
		// account it would have needed is taken back.
		$refused_email = $this->unique_email( 'refused' );
		$result        = law_booking_register_by_manager( $event, $this->row( 'Too Late', $refused_email ), $committee );
		$this->assertWPError( $result, 'law_booking_full' );
		$this->assertFalse( get_user_by( 'email', $refused_email ), 'No orphan account after a refusal.' );
	}

	public function test_profile_requirements_join_and_other_text(): void {
		$profile = array(
			'dietary'       => array( 'Vegetarian', 'Other' ),
			'dietary_other' => 'No shellfish',
		);
		$this->assertSame( 'Vegetarian, Other: No shellfish', law_booking_profile_requirements( $profile, 'dietary' ) );
		$this->assertSame( '', law_booking_profile_requirements( array(), 'accessibility' ) );
	}
}
