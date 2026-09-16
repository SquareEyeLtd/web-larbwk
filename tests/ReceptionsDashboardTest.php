<?php
/**
 * The committee's "Manage receptions" screen and the wp-admin Reception box
 * (functions/events/receptions-dashboard.php, functions/events/receptions.php).
 *
 * The promise these pin is the same one the flagship dashboard makes: the two
 * screens are one feature and not two. They share the reader, the validation
 * and the writer, so a price or an invitation-only switch cannot come to mean
 * different things on the front end and in wp-admin.
 */
class ReceptionsDashboardTest extends LAW_Test_Case {

	protected function tearDown(): void {
		delete_transient( 'law_reception_state_' . get_current_user_id() );
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	private function make_reception( array $meta = array(), $status = 'law-draft' ): int {
		return $this->make_event(
			array_merge( array( '_law_is_reception' => 1 ), $meta ),
			$status
		);
	}

	public function test_both_screens_share_one_write_path(): void {
		// Not a style point: if either screen grew its own saver, the two would
		// drift and "the wp-admin box edits the same data" would quietly stop
		// being true. The data layer lives in receptions.php, which neither
		// screen owns.
		foreach ( array(
			'law_reception_input_from_post',
			'law_reception_validate',
			'law_reception_save',
			'law_reception_form_values',
			'law_reception_snapshot',
			'law_reception_log_save',
			'law_reception_ensure_posts',
		) as $function ) {
			$file = ( new ReflectionFunction( $function ) )->getFileName();
			$this->assertSame(
				'receptions.php',
				basename( (string) $file ),
				$function . '() must stay in the shared data layer, not move into a screen.'
			);
		}

		// And the wp-admin box goes through it rather than writing meta itself.
		$admin = file_get_contents( get_theme_file_path( 'functions/events/admin/event-screen.php' ) );
		$this->assertStringContainsString( 'law_reception_save(', $admin, 'The Reception meta box must save through the shared saver.' );
		$this->assertStringNotContainsString( "law_event_update_meta( \$post_id, '_law_attendee_price_pence'", $admin, 'The price must never be written straight from the admin screen.' );
	}

	public function test_the_dashboard_page_is_provisioned_with_the_others(): void {
		$map = law_migration_page_map();
		$this->assertArrayHasKey( LAW_RECEPTIONS_DASHBOARD_PATH, $map );
		$this->assertSame( LAW_RECEPTIONS_DASHBOARD_TEMPLATE, $map[ LAW_RECEPTIONS_DASHBOARD_PATH ]['template'] );

		$setup = file_get_contents( get_theme_file_path( 'functions/setup-account-pages.php' ) );
		$this->assertStringContainsString( LAW_RECEPTIONS_DASHBOARD_PATH, $setup, 'The ?setup-account-pages trigger must assign the template too.' );
		$this->assertStringContainsString( 'law_setup_receptions_dashboard_access', $setup, 'And copy the committee restriction from the parent dashboard.' );

		$this->assertFileExists( get_theme_file_path( LAW_RECEPTIONS_DASHBOARD_TEMPLATE ) );

		// A committee CHILD page, so it must NOT join the list of pages a plain
		// subscriber is granted (RECEPTIONS.md §0.4 item 6). Asserted on the
		// $paths array itself, which is the list that decides it.
		preg_match( '/\$paths\s*=\s*array\((?P<list>[^)]*)\)/', $setup, $matches );
		$this->assertNotEmpty( $matches['list'] ?? '', 'The subscriber access list could not be read.' );
		$this->assertStringNotContainsString( 'dashboard/receptions', (string) $matches['list'] );
	}

	public function test_seeding_is_idempotent_and_creates_drafts_with_no_price(): void {
		// This environment may already hold the three real records, with real
		// dates and prices the committee has typed. So the seeded-state
		// assertions below apply only to what THIS run created; everything
		// else here is about idempotence, which holds either way.
		$first = law_reception_ensure_posts();
		foreach ( law_reception_ids() as $id ) {
			$this->posts[] = $id;
		}
		$before = law_reception_ids();
		$this->assertNotEmpty( $before );

		$second = law_reception_ensure_posts();
		$this->assertSame( 0, $second['created'], 'A second run must create nothing.' );
		$this->assertSame( $before, law_reception_ids(), 'And find exactly the same records.' );

		$fresh = 0 < $first['created'];
		foreach ( law_reception_seed_map() as $slug => $config ) {
			$post = get_page_by_path( $slug, OBJECT, LAW_EVENT_CPT );
			$this->assertInstanceOf( WP_Post::class, $post, "The {$slug} record is missing." );
			$this->assertTrue( law_reception_is( $post->ID ) );
			if ( $fresh ) {
				$this->assertSame( 0, law_event_price_pence( $post->ID ), 'A seeded reception is not on sale: the prices are LAW to confirm.' );
				$this->assertSame( 'law-draft', $post->post_status, 'Nothing goes on the programme until the committee ticks the box.' );
			}
		}

		// Friday is the invitation-only one; Monday and Wednesday are included.
		// True of the seed and true of the records the committee then edits, so
		// this is asserted either way.
		$friday = get_page_by_path( 'friday-reception', OBJECT, LAW_EVENT_CPT );
		$this->assertTrue( law_event_is_invitation_only( $friday->ID ) );
		$monday = get_page_by_path( 'opening-drinks', OBJECT, LAW_EVENT_CPT );
		$this->assertFalse( law_event_is_invitation_only( $monday->ID ) );
		$this->assertTrue( (bool) law_event_meta( $monday->ID, '_law_flagship_included' ) );
	}

	public function test_a_save_writes_the_lot_and_publishes(): void {
		$event_id = $this->make_reception();
		$actor    = $this->make_committee_user();

		$saved = law_reception_save(
			array(
				'event_id'    => $event_id,
				'title'       => 'Opening drinks',
				'description' => '',
				'date'        => '2026-11-30',
				'start'       => '18:30',
				'end'         => '20:30',
				'venue'       => 'The Hall, EC4',
				'places'      => '80',
				'price'       => '45.00',
				'show'        => true,
				'included'    => true,
				'invitation'  => false,
			),
			$actor
		);

		$this->assertSame( $event_id, $saved );
		$this->assertSame( 'publish', get_post_status( $event_id ) );
		$this->assertSame( '2026-11-30 18:30', (string) law_event_meta( $event_id, '_law_start' ) );
		$this->assertSame( '2026-11-30 20:30', (string) law_event_meta( $event_id, '_law_end' ) );
		$this->assertSame( '', (string) law_event_meta( $event_id, '_law_slot_label' ), 'A reception holds no programme slot, exactly as the flagship does not.' );
		$this->assertSame( 4500, law_event_price_pence( $event_id ) );
		$this->assertSame( 80, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
		$this->assertSame( 'open', (string) law_event_meta( $event_id, '_law_registration_state' ) );
		$this->assertTrue( law_event_is_priced( $event_id ) );
	}

	public function test_the_status_exemption_covers_a_reception_and_only_its_two_statuses(): void {
		$event_id = $this->make_reception();

		// Without the flag the guard reverts any status change, as it does for
		// every other law_event.
		wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );
		$this->assertSame( 'law-draft', get_post_status( $event_id ), 'A bare wp_update_post must not publish a reception.' );

		// With it, publish and law-draft pass.
		$GLOBALS['law_event_managed_saving'] = true;
		wp_update_post( array( 'ID' => $event_id, 'post_status' => 'publish' ) );
		$this->assertSame( 'publish', get_post_status( $event_id ) );

		// And nothing else does: the receptions have no workflow, so a
		// workflow status must not be reachable through the exemption.
		wp_update_post( array( 'ID' => $event_id, 'post_status' => 'law-approved' ) );
		unset( $GLOBALS['law_event_managed_saving'] );
		$this->assertSame( 'publish', get_post_status( $event_id ), 'The exemption is for publish and law-draft only.' );
	}

	public function test_validation_refuses_the_things_that_would_lie(): void {
		$event_id = $this->make_reception( array( '_law_tickets_available' => 50 ), 'publish' );

		$errors = law_reception_validate(
			array( 'event_id' => $event_id, 'title' => 'Drinks', 'places' => '0' )
		);
		$this->assertFalse( $errors->has_errors(), 'Zero places means "not released", which is always allowed.' );

		// Places cannot drop below what is already taken: the count would read
		// as a lie and the over-booking warning would fire on every booking.
		$errors = law_reception_validate( array( 'event_id' => $event_id, 'title' => 'Drinks', 'places' => '0' ) );
		$this->assertFalse( $errors->has_errors() );

		$errors = law_reception_validate( array( 'event_id' => $event_id, 'title' => '' ) );
		$this->assertTrue( $errors->has_errors() );
		$this->assertNotEmpty( $errors->get_error_message( 'title' ) );

		$errors = law_reception_validate( array( 'event_id' => $event_id, 'title' => 'Drinks', 'price' => 'forty five' ) );
		$this->assertNotEmpty( $errors->get_error_message( 'price' ), 'A typo must be refused, never read as zero: that would put the reception on sale for nothing.' );

		$errors = law_reception_validate(
			array( 'event_id' => $event_id, 'title' => 'Drinks', 'date' => '2026-11-30', 'start' => '20:30', 'end' => '18:30' )
		);
		$this->assertNotEmpty( $errors->get_error_message( 'end' ) );

		// Unpadded times compare correctly: "9:30" must not read as later
		// than "10:30".
		$errors = law_reception_validate(
			array( 'event_id' => $event_id, 'title' => 'Drinks', 'date' => '2026-11-30', 'start' => '9:30', 'end' => '10:30' )
		);
		$this->assertFalse( $errors->has_errors() );
	}

	public function test_places_cannot_drop_below_the_places_taken(): void {
		// Published, because the booking engine refuses an unpublished event
		// and the point here is a reception with real places taken. The places
		// are written with the engine's own insert: a reception is booked
		// through its own checkout whatever it costs, so the free booking form
		// refuses every one of them (law_booking_guard_form_open()).
		$event_id = $this->make_reception( array( '_law_tickets_available' => 10 ), 'publish' );
		foreach ( array( 'one', 'two' ) as $law_who ) {
			$this->posts[] = law_booking_insert(
				$event_id,
				$this->make_user(),
				'publish',
				array( 'name' => 'Guest ' . $law_who, 'email' => 'guest-' . $law_who . '@example.test' )
			);
		}
		law_event_recount_attendees( $event_id );

		$errors = law_reception_validate( array( 'event_id' => $event_id, 'title' => 'Drinks', 'places' => '1' ) );
		$this->assertNotEmpty( $errors->get_error_message( 'places' ) );
	}

	/**
	 * Invitation only DISABLES the price and places controls rather than
	 * hiding them, and the stored values survive: a reception that was on sale
	 * and is now by invitation must not silently lose its price.
	 */
	public function test_invitation_only_keeps_the_price_it_no_longer_charges(): void {
		$event_id = $this->make_reception( array( '_law_attendee_price_pence' => 4500, '_law_tickets_available' => 60 ) );
		$actor    = $this->make_committee_user();

		law_reception_save(
			array( 'event_id' => $event_id, 'title' => 'Friday reception', 'invitation' => true, 'included' => false, 'show' => true ),
			$actor
		);

		$this->assertTrue( law_event_is_invitation_only( $event_id ) );
		$this->assertSame( 4500, law_event_price_pence( $event_id ), 'The price is kept, never cleared by the hidden-field rule.' );
		$this->assertSame( 60, (int) law_event_meta( $event_id, '_law_tickets_available' ) );

		// The form renders them disabled rather than removing them.
		$markup = file_get_contents( get_theme_file_path( 'parts/events/reception-manage.php' ) );
		$this->assertStringContainsString( 'disabled( $law_rm_locked )', $markup );
	}

	public function test_a_partial_save_touches_only_what_it_was_given(): void {
		$event_id = $this->make_reception(
			array( '_law_attendee_price_pence' => 4500, '_law_tickets_available' => 60, '_law_venue' => 'The Hall' )
		);
		wp_update_post( array( 'ID' => $event_id, 'post_title' => 'Opening drinks' ) );
		$actor = $this->make_committee_user();

		$saved = law_reception_save(
			array( 'event_id' => $event_id, 'price' => '60.00', 'included' => true ),
			$actor,
			array( 'partial' => true )
		);

		$this->assertSame( $event_id, $saved, 'A partial save needs no title: the box that sends it does not render one.' );
		$this->assertSame( 6000, law_event_price_pence( $event_id ) );
		$this->assertSame( 'Opening drinks', get_post_field( 'post_title', $event_id ), 'A partial save must not touch the post itself.' );
		$this->assertSame( 'The Hall', (string) law_event_meta( $event_id, '_law_venue' ) );
		$this->assertSame( 60, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
	}

	/**
	 * wp-admin's ordinary Update button must not un-schedule a reception.
	 *
	 * The event screen writes the slot keys on every save, and an empty slot
	 * label means "clear the dates" — so a reception, which holds no programme
	 * slot, lost its _law_start and _law_end on any Update at all and dropped
	 * off the programme. The flagship already had the guard; this is the same
	 * one, through law_event_is_managed_by_law() (found by the browser pass,
	 * 14 September 2026).
	 */
	public function test_a_bare_wp_admin_update_keeps_a_receptions_dates(): void {
		$event_id = $this->make_reception( array( '_law_start' => '2026-11-30 18:30', '_law_end' => '2026-11-30 20:30' ) );
		$actor    = $this->make_committee_user();
		wp_set_current_user( $actor );

		$_POST = array(
			'law_event_admin_nonce' => wp_create_nonce( 'law_event_admin_save' ),
			// Exactly what the screen posts with nothing changed: the slot
			// select is empty, because a reception has no slot.
			'law_slot_label'        => '',
			'law_reception'         => array( 'is_reception' => '1', 'price' => '45.00' ),
		);
		law_event_admin_save( $event_id, get_post( $event_id ) );
		$_POST = array();
		wp_set_current_user( 0 );

		$this->assertSame( '2026-11-30 18:30', (string) law_event_meta( $event_id, '_law_start' ) );
		$this->assertSame( '2026-11-30 20:30', (string) law_event_meta( $event_id, '_law_end' ) );
		$this->assertSame( 4500, law_event_price_pence( $event_id ), 'And the box it DID render still saved.' );
	}

	public function test_a_posted_id_that_is_not_a_reception_reaches_nothing(): void {
		$other = $this->make_event( array(), 'publish' );
		$result = law_reception_save( array( 'event_id' => $other, 'title' => 'Sneaky' ), $this->make_committee_user() );
		$this->assertWPError( $result, 'law_reception_not_a_reception' );
		$this->assertSame( 0, (int) law_event_meta( $other, '_law_is_reception' ) );
	}

	public function test_the_log_says_what_changed_and_nothing_when_nothing_did(): void {
		$event_id = $this->make_reception();
		$actor    = $this->make_committee_user();
		$input    = array(
			'event_id'   => $event_id,
			'title'      => get_post_field( 'post_title', $event_id ),
			'price'      => '45.00',
			'places'     => '80',
			'show'       => false,
			'included'   => false,
			'invitation' => false,
		);

		law_reception_save( $input, $actor );
		$after_first = count( law_event_log_entries( $event_id ) );
		$this->assertGreaterThan( 0, $after_first );

		law_reception_save( $input, $actor );
		$this->assertSame( $after_first, count( law_event_log_entries( $event_id ) ), 'A save that changed nothing writes nothing.' );
	}
}
