<?php
/**
 * The committee's cross-event Bookings dashboard (functions/events/
 * bookings-dashboard.php): the flattened row set, its filters (keyword,
 * event, status, press, year), the export column set and the event picker.
 */

class BookingsDashboardTest extends LAW_Test_Case {

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

	private function make_bookable_event( string $start, string $end, array $meta = array() ): int {
		return $this->make_event(
			array_merge( array( '_law_tickets_available' => 10, '_law_start' => $start, '_law_end' => $end ), $meta ),
			'publish'
		);
	}

	private function unique_email( string $prefix ): string {
		return $prefix . '-' . wp_generate_password( 8, false ) . '@example.test';
	}

	private function filters( array $overrides = array() ): array {
		return law_bookings_dashboard_filters( $overrides );
	}

	/** Two events, three bookings (one cancelled), one press row: the fixture every test reads. */
	private function fixture(): array {
		$event_a = $this->make_bookable_event( '2026-12-01 10:00', '2026-12-01 12:00' );
		$event_b = $this->make_bookable_event( '2026-12-02 10:00', '2026-12-02 12:00' );

		$owner_a = $this->make_user( 'attendee' );
		wp_update_user( array( 'ID' => $owner_a, 'first_name' => 'Ada', 'last_name' => 'Owner' ) );
		update_user_meta( $owner_a, 'country', 'France' );
		$guest_email = $this->unique_email( 'guest' );
		// One booking per attendee: the booker and their guest each get one.
		$party_a     = $this->make_booking( $event_a, $owner_a, array( array( 'name' => 'Grace Guest', 'email' => $guest_email, 'organisation' => 'Guest LLP', 'job_title' => 'Partner' ) ) );
		$booking_a   = $party_a[0];
		$guest_a     = $party_a[1];

		$owner_b   = $this->make_user( 'attendee' );
		$booking_b = $this->make_booking( $event_b, $owner_b, array() )[0];

		$owner_c   = $this->make_user( 'attendee' );
		$booking_c = $this->make_booking( $event_b, $owner_c, array() )[0];
		law_booking_cancel( $booking_c, $owner_c );

		// A press pass registered by the committee.
		$committee   = $this->make_committee_user();
		$press_email = $this->unique_email( 'press' );
		$booking_p   = law_booking_register_by_manager( $event_a, array( 'name' => 'Pat Press', 'email' => $press_email ), $committee, array( 'press' => true ) );
		$this->assertIsInt( $booking_p );
		$this->posts[] = $booking_p;
		$press_user    = get_user_by( 'email', $press_email );
		$this->users[] = (int) $press_user->ID;

		return compact( 'event_a', 'event_b', 'owner_a', 'guest_email', 'booking_a', 'guest_a', 'owner_b', 'booking_b', 'owner_c', 'booking_c', 'press_email', 'booking_p' );
	}

	public function test_rows_flatten_active_bookings_across_events_with_live_country(): void {
		$f = $this->fixture();

		$data = law_bookings_dashboard_rows( $this->filters( array( 'law_event' => 0 ) ) );
		// Other fixtures in the database may hold bookings too, so assert on
		// our rows rather than the totals.
		$mine = array_filter( $data['rows'], fn( $r ) => in_array( $r['booking_id'], array( $f['booking_a'], $f['guest_a'], $f['booking_b'], $f['booking_p'] ), true ) );
		$this->assertCount( 4, $mine, 'Booker + guest on A, booker on B, press on A; the cancelled booking is excluded by default.' );

		$by_email = array();
		foreach ( $mine as $row ) {
			$by_email[ $row['email'] ] = $row;
		}
		$owner_row = $by_email[ get_userdata( $f['owner_a'] )->user_email ];
		$this->assertSame( 'France', $owner_row['country'], 'Country reads live from the profile.' );
		$this->assertSame( '', $owner_row['invited_by'], 'They booked themselves, so no tag.' );
		$this->assertFalse( $owner_row['is_press'] );
		$this->assertSame( get_the_title( $f['event_a'] ), $owner_row['event_title'] );
		$this->assertSame( 'active', $owner_row['status'] );

		$this->assertTrue( $by_email[ $f['press_email'] ]['is_press'] );
		$this->assertSame( 'Guest LLP', $by_email[ $f['guest_email'] ]['organisation'] );
		$this->assertNotSame( '', $by_email[ $f['guest_email'] ]['invited_by'], 'A guest carries who invited them.' );
		$this->assertFalse( $data['truncated'] );
	}

	public function test_keyword_matches_email_name_and_booking_number(): void {
		$f = $this->fixture();

		$rows = law_bookings_dashboard_rows( $this->filters( array( 'law_kw' => strtoupper( $f['guest_email'] ) ) ) )['rows'];
		$this->assertCount( 1, $rows, 'Case-insensitive email match.' );
		$this->assertSame( 'Grace Guest', $rows[0]['name'] );

		$rows = law_bookings_dashboard_rows( $this->filters( array( 'law_kw' => 'pat press' ) ) )['rows'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $f['press_email'], $rows[0]['email'] );

		$number = (int) law_event_meta( $f['booking_b'], '_law_booking_number' );
		$rows   = law_bookings_dashboard_rows( $this->filters( array( 'law_kw' => '#' . $number ) ) )['rows'];
		$this->assertNotEmpty( $rows );
		foreach ( $rows as $row ) {
			$this->assertStringContainsString( (string) $number, (string) $row['number'], 'A #N keyword matches the booking number.' );
		}
	}

	public function test_event_status_and_press_filters(): void {
		$f = $this->fixture();

		$rows = law_bookings_dashboard_rows( $this->filters( array( 'law_event' => $f['event_b'] ) ) )['rows'];
		$this->assertCount( 1, $rows, 'Event B has one active booking (the other is cancelled).' );
		$this->assertSame( $f['booking_b'], $rows[0]['booking_id'] );

		$rows = law_bookings_dashboard_rows( $this->filters( array( 'law_event' => $f['event_b'], 'law_bstatus' => 'cancelled' ) ) )['rows'];
		$this->assertCount( 1, $rows );
		$this->assertSame( 'cancelled', $rows[0]['status'] );
		$this->assertSame( $f['booking_c'], $rows[0]['booking_id'] );

		$data = law_bookings_dashboard_rows( $this->filters( array( 'law_event' => $f['event_b'], 'law_bstatus' => 'all' ) ) );
		$this->assertCount( 2, $data['rows'] );
		$this->assertSame( 2, $data['bookings'] );
		$this->assertSame( 1, $data['events'] );

		$rows = law_bookings_dashboard_rows( $this->filters( array( 'law_event' => $f['event_a'], 'law_press' => '1' ) ) )['rows'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $f['press_email'], $rows[0]['email'] );

		$this->assertSame( array(), law_bookings_dashboard_rows( $this->filters( array( 'law_year' => 'no-such-year' ) ) )['rows'] );
	}

	public function test_filters_normalise_input(): void {
		$filters = $this->filters( array( 'law_bstatus' => 'bogus', 'law_event' => '12abc', 'law_kw' => ' <b>x</b> ', 'law_press' => '' ) );
		$this->assertSame( '', $filters['status'] );
		$this->assertSame( 12, $filters['event'] );
		$this->assertSame( 'x', $filters['kw'] );
		$this->assertFalse( $filters['press'] );
	}

	public function test_export_rows_columns_and_press_marker(): void {
		$f    = $this->fixture();
		$data = law_bookings_dashboard_export_rows( $this->filters( array( 'law_event' => $f['event_a'] ) ) );

		$this->assertSame(
			array( 'Booking ID', 'Invited by', 'Event', 'Event date', 'Reference', 'First name', 'Second name', 'Email', 'Organisation', 'Job title', 'Country', 'Press', 'Status', 'Booked on', 'Accessibility', 'Dietary' ),
			$data['columns']
		);
		$this->assertStringContainsString( get_the_title( $f['event_a'] ), $data['title'] );
		$this->assertStringContainsString( 'active bookings', $data['title'] );
		$this->assertCount( 3, $data['rows'] );

		$press = array_values( array_filter( $data['rows'], fn( $r ) => $f['press_email'] === $r[7] ) );
		$this->assertCount( 1, $press );
		$this->assertSame( 'Pat', $press[0][5] );
		$this->assertSame( 'Press', $press[0][6] );
		$this->assertSame( 'Yes', $press[0][11] );
		$this->assertSame( 'Active', $press[0][12] );
		$this->assertSame( '', $press[0][1], 'Registered on their own behalf, so no invited-by tag.' );

		$owner = array_values( array_filter( $data['rows'], fn( $r ) => get_userdata( $f['owner_a'] )->user_email === $r[7] ) );
		$this->assertSame( 'France', $owner[0][10] );
		$this->assertSame( '', $owner[0][11] );

		$guest = array_values( array_filter( $data['rows'], fn( $r ) => $f['guest_email'] === $r[7] ) );
		$this->assertNotSame( '', $guest[0][1], 'A guest row names who invited them.' );
	}

	public function test_event_picker_lists_events_with_bookings_in_date_order(): void {
		$f      = $this->fixture();
		$events = law_bookings_dashboard_events();

		$this->assertArrayHasKey( $f['event_a'], $events );
		$this->assertArrayHasKey( $f['event_b'], $events );
		$positions = array_flip( array_keys( $events ) );
		$this->assertLessThan( $positions[ $f['event_b'] ], $positions[ $f['event_a'] ], 'Ordered by start date.' );

		$empty = $this->make_bookable_event( '2026-12-03 10:00', '2026-12-03 12:00' );
		$this->assertArrayNotHasKey( $empty, law_bookings_dashboard_events(), 'Events with no bookings are not offered.' );
	}
}
