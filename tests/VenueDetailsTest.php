<?php
/**
 * The Venue details rule (9 September 2026): the venue name/address, capacity
 * band and places available are only asked of a host who already has a venue,
 * the committee always sees them, and a save that did not render them must
 * never blank the stored values.
 */
class VenueDetailsTest extends LAW_Test_Case {

	private const NEEDS_VENUE = 'Yes, please share our details with venue hosts';
	private const HAS_VENUE   = 'No, we already have a venue planned';

	/** The venue values a placed event carries, as the committee would set them. */
	private const PLACED = array(
		'_law_venue'              => 'Guildhall, EC2V 7HH',
		'_law_venue_capacity'     => '101-150',
		'_law_tickets_available'  => 120,
	);

	/** A complete, valid non-draft form input, shaped like SubmissionFormLockTest's. */
	private function valid_input( array $overrides = array() ): array {
		// Read the configured slots rather than hard-coding a label:
		// law_events_sanitise_preferred_slots() drops anything that is not one
		// of them and validation then fails. law_events_slots() is used rather
		// than law_events_slot_choices() so this file does not depend on the
		// settings work in flight.
		$slot_labels = array_keys( law_events_slots() );
		return array_merge(
			array(
				'law_form_action'     => 'update',
				'event_title'         => 'Edited title',
				'description'         => 'Edited description.',
				'event_type'          => 'Social event',
				'host_organisations'  => 'Edited Org LLP',
				'preferred_slots'     => $slot_labels ? array( $slot_labels[0] ) : array( 'Any slot' ),
				'sectors'             => array(),
				'venue_needed'        => self::NEEDS_VENUE,
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

	private function assert_venue_values( int $event_id, string $venue, string $capacity, int $tickets, string $message = '' ): void {
		$this->assertSame( $venue, (string) law_event_meta( $event_id, '_law_venue' ), $message . ' venue' );
		$this->assertSame( $capacity, (string) law_event_meta( $event_id, '_law_venue_capacity' ), $message . ' capacity' );
		$this->assertSame( $tickets, (int) law_event_meta( $event_id, '_law_tickets_available' ), $message . ' places' );
	}

	public function test_visibility_predicate(): void {
		$host      = $this->make_user();
		$committee = $this->make_committee_user();

		$this->assertFalse( law_events_venue_details_visible( self::NEEDS_VENUE, $host ) );
		$this->assertFalse( law_events_venue_details_visible( '', $host ), 'An unanswered question asks nothing further.' );
		$this->assertTrue( law_events_venue_details_visible( self::HAS_VENUE, $host ) );

		// The committee sets the venue on the events LAW places, so they always
		// see the block, whichever way the host answered.
		$this->assertTrue( law_events_venue_details_visible( self::NEEDS_VENUE, $committee ) );
		$this->assertTrue( law_events_venue_details_visible( self::HAS_VENUE, $committee ) );

		// No explicit user: falls back to the current one.
		wp_set_current_user( $committee );
		$this->assertTrue( law_events_venue_details_visible( self::NEEDS_VENUE ) );
		wp_set_current_user( $host );
		$this->assertFalse( law_events_venue_details_visible( self::NEEDS_VENUE ) );
	}

	public function test_host_save_leaves_a_placed_events_venue_alone(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event( self::PLACED, 'law-proposed', $host );

		// The fields were never on this host's form, so the post carries none of
		// them. An absent value must not be read as a cleared one.
		$result = law_events_form_save( $this->valid_input(), array(), get_post( $event_id ), $host );
		$this->assertSame( $event_id, $result );

		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', '101-150', 120, 'After a host save:' );
		// The rest of the form still saved.
		$this->assertSame( 'Edited title', get_post( $event_id )->post_title );
		$this->assertSame( self::NEEDS_VENUE, law_event_meta( $event_id, '_law_venue_needed' ) );
	}

	public function test_host_save_logs_no_places_change_when_the_fields_were_hidden(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event( self::PLACED, 'law-proposed', $host );
		law_events_form_save( $this->valid_input(), array(), get_post( $event_id ), $host );

		$messages = wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' );
		$this->assertSame(
			array(),
			array_filter(
				$messages,
				static function ( $message ) {
					return false !== strpos( (string) $message, 'Places available changed' );
				}
			),
			'A save that never asked for places must not log a change to them.'
		);
	}

	public function test_committee_save_sets_the_venue_on_an_event_that_needs_one(): void {
		$host      = $this->make_user();
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );

		$event_id = $this->make_event( array( '_law_venue_needed' => self::NEEDS_VENUE ), 'law-approved', $host );

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue'             => 'Guildhall, EC2V 7HH',
					'venue_capacity'    => '101-150',
					'tickets_available' => '120',
				)
			),
			array(),
			get_post( $event_id ),
			$committee
		);
		$this->assertSame( $event_id, $result );

		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', '101-150', 120, 'After a committee save:' );
	}

	public function test_host_with_a_venue_still_saves_all_three(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// law-draft, not law-proposed: places available lock for a host the
		// moment the event is submitted (Denis, 14 September 2026), so their
		// own unsubmitted draft is the last door they can set all three at.
		$event_id = $this->make_event( array(), 'law-draft', $host );

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'      => self::HAS_VENUE,
					'venue'             => 'Their own offices',
					'venue_capacity'    => 'Under 50',
					'tickets_available' => '40',
					// A law-draft save is the submission as far as validation
					// is concerned, so the terms tick is asked for.
					'terms'             => 1,
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertSame( $event_id, $result );

		$this->assert_venue_values( $event_id, 'Their own offices', 'Under 50', 40, 'After a host save with a venue:' );
	}

	public function test_the_band_ceiling_still_applies_to_a_host_with_a_venue(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event( array(), 'law-draft', $host );

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'      => self::HAS_VENUE,
					'venue'             => 'Their own offices',
					'venue_capacity'    => 'Under 50',
					'tickets_available' => '51',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message( 'tickets_available' ) );
	}

	public function test_a_venue_is_required_only_when_the_host_has_one(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event( array(), 'law-proposed', $host );

		// "No, we already have a venue planned" with no venue typed: an error.
		$result = law_events_form_save(
			$this->valid_input( array( 'venue_needed' => self::HAS_VENUE ) ),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertNotEmpty( $result->get_error_message( 'venue' ) );

		// Needing a venue is a complete answer on its own.
		$this->assertSame(
			$event_id,
			law_events_form_save( $this->valid_input(), array(), get_post( $event_id ), $host )
		);
	}

	public function test_all_three_venue_details_are_required_when_the_host_has_a_venue(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// On the host's own draft, where all three are still on their form.
		$event_id = $this->make_event( array(), 'law-draft', $host );

		// The venue typed, but neither the band nor the places (Denis,
		// 11 September 2026): all three go together on that answer.
		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed' => self::HAS_VENUE,
					'venue'        => 'Their own offices',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( '', $result->get_error_message( 'venue' ) );
		$this->assertNotEmpty( $result->get_error_message( 'venue_capacity' ) );
		$this->assertNotEmpty( $result->get_error_message( 'tickets_available' ) );

		// "TBC" is a real answer, so nobody is stuck for not knowing the numbers.
		$this->assertSame(
			$event_id,
			law_events_form_save(
				$this->valid_input(
					array(
						'venue_needed'      => self::HAS_VENUE,
						'venue'             => 'Their own offices',
						'venue_capacity'    => 'TBC',
						'tickets_available' => '40',
						'terms'             => 1,
					)
				),
				array(),
				get_post( $event_id ),
				$host
			)
		);
	}

	public function test_the_venue_details_are_not_required_of_a_host_who_needs_a_venue(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event( array(), 'law-proposed', $host );

		// The block is off their form entirely, so an empty band and no places
		// must not block the rest of it -- and a crafted post is ignored, not
		// judged.
		$this->assertSame(
			$event_id,
			law_events_form_save( $this->valid_input(), array(), get_post( $event_id ), $host )
		);
	}

	public function test_the_committee_is_not_blocked_on_an_event_that_needs_a_venue(): void {
		$host      = $this->make_user();
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );

		// The committee sees the three fields whichever way the host answered;
		// on "Yes" they are theirs to fill in once the event is placed, so a
		// save of anything else must not be held up by them.
		$event_id = $this->make_event( array( '_law_venue_needed' => self::NEEDS_VENUE ), 'law-approved', $host );

		$this->assertSame(
			$event_id,
			law_events_form_save( $this->valid_input(), array(), get_post( $event_id ), $committee )
		);
	}

	public function test_a_post_approval_host_edit_is_not_judged_on_the_locked_band(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// The band is locked after approval and a disabled <select> posts
		// nothing, so requiring it here would make every such edit impossible.
		$event_id = $this->make_event(
			array_merge( self::PLACED, array( '_law_venue_needed' => self::HAS_VENUE ) ),
			'law-approved',
			$host
		);

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'      => '',
					'venue'             => 'A different hall, EC1',
					'venue_capacity'    => '',
					'tickets_available' => '90',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertSame( $event_id, $result );
	}

	public function test_post_approval_host_edit_still_saves_the_venue_name(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// venue_needed is locked for a host on an approved event, so the disabled
		// radios post nothing: the stored answer is what decides.
		$event_id = $this->make_event(
			array_merge( self::PLACED, array( '_law_venue_needed' => self::HAS_VENUE ) ),
			'law-approved',
			$host
		);
		$this->assertContains( 'venue_needed', law_events_locked_fields( get_post( $event_id ), $host ) );

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'      => '',
					'venue'             => 'A different hall, EC1',
					'tickets_available' => '90',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertSame( $event_id, $result );

		// The venue name stays the host's post-approval; the band and the
		// places do not, so the stored 120 survives their posted 90.
		$this->assert_venue_values( $event_id, 'A different hall, EC1', '101-150', 120, 'After a post-approval host edit:' );
		$this->assertSame( self::HAS_VENUE, law_event_meta( $event_id, '_law_venue_needed' ) );
	}

	/* Places available, locked for a host from submission (14 September 2026) */

	public function test_places_lock_for_a_host_from_submission_onwards(): void {
		$host      = $this->make_user();
		$committee = $this->make_committee_user();

		// The host's own draft is the create form reopened, so it stays theirs.
		$draft = get_post( $this->make_event( array(), 'law-draft', $host ) );
		$this->assertNotContains( 'tickets_available', law_events_locked_fields( $draft, $host ) );

		foreach ( array( 'law-proposed', 'law-sent-back', 'law-approved', 'publish' ) as $status ) {
			$event = get_post( $this->make_event( array(), $status, $host ) );
			$this->assertContains(
				'tickets_available',
				law_events_locked_fields( $event, $host ),
				"Host on $status"
			);
			$this->assertNotContains(
				'tickets_available',
				law_events_locked_fields( $event, $committee ),
				"Committee on $status"
			);
		}

		// The band is the other half of the pair and moves the other way: still
		// the host's while the event is under review, locked at approval.
		$under_review = get_post( $this->make_event( array(), 'law-proposed', $host ) );
		$this->assertNotContains( 'venue_capacity', law_events_locked_fields( $under_review, $host ) );
		$approved = get_post( $this->make_event( array(), 'law-approved', $host ) );
		$this->assertContains( 'venue_capacity', law_events_locked_fields( $approved, $host ) );
	}

	public function test_a_host_under_review_cannot_change_the_places(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event(
			array_merge( self::PLACED, array( '_law_venue_needed' => self::HAS_VENUE ) ),
			'law-proposed',
			$host
		);

		// The disabled number input posts nothing; a crafted post is ignored,
		// and the stored places are neither cleared nor overwritten. Clearing
		// them would take the booking and waitlist capacity with them.
		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'      => self::HAS_VENUE,
					'venue'             => 'Guildhall, EC2V 7HH',
					'venue_capacity'    => '101-150',
					'tickets_available' => '900',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertSame( $event_id, $result );
		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', '101-150', 120, 'After a host save under review:' );

		// And an absent value is not read as a cleared one either.
		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'   => self::HAS_VENUE,
					'venue'          => 'Guildhall, EC2V 7HH',
					'venue_capacity' => '101-150',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertSame( $event_id, $result );
		$this->assertSame( 120, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
	}

	public function test_a_host_under_review_may_still_change_the_band(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event(
			array_merge( self::PLACED, array( '_law_venue_needed' => self::HAS_VENUE ) ),
			'law-proposed',
			$host
		);

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'   => self::HAS_VENUE,
					'venue'          => 'Guildhall, EC2V 7HH',
					'venue_capacity' => '151-250',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertSame( $event_id, $result );
		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', '151-250', 120, 'After a band change under review:' );
	}

	public function test_a_band_below_the_stored_places_is_refused_on_the_band(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event(
			array_merge( self::PLACED, array( '_law_venue_needed' => self::HAS_VENUE ) ),
			'law-proposed',
			$host
		);

		// 120 places are already released, so "Under 50" cannot be right. The
		// refusal lands on the band, the only half of the pair this host can
		// move, rather than on a field they cannot reach.
		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'   => self::HAS_VENUE,
					'venue'          => 'Guildhall, EC2V 7HH',
					'venue_capacity' => 'Under 50',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( '', $result->get_error_message( 'tickets_available' ) );
		$this->assertStringContainsString( '120 places', $result->get_error_message( 'venue_capacity' ) );

		// Nothing was written: the check runs before the writes.
		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', '101-150', 120, 'After a refused band change:' );
	}

	public function test_the_places_are_not_required_of_a_host_who_cannot_set_them(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// A host with their own venue and no places stored yet: the field is
		// locked, so requiring it would make every save impossible.
		$event_id = $this->make_event( array( '_law_venue_needed' => self::HAS_VENUE ), 'law-proposed', $host );

		$this->assertSame(
			$event_id,
			law_events_form_save(
				$this->valid_input(
					array(
						'venue_needed'   => self::HAS_VENUE,
						'venue'          => 'Their own offices',
						'venue_capacity' => 'Under 50',
					)
				),
				array(),
				get_post( $event_id ),
				$host
			)
		);
	}

	/* The committee dashboard panel ________________________________________ */

	/**
	 * The panel's own write, as law_committee_action_handler() performs it.
	 * The handler itself ends in a redirect, so its steps are replicated here
	 * the way EventFlagsTest replicates the flag save.
	 */
	private function panel_saves_venue( int $event_id, string $capacity, string $tickets ): string {
		$actor = get_current_user_id();
		$error = law_committee_venue_input_error( $capacity, $tickets );
		if ( '' !== $error ) {
			return $error;
		}

		$before_capacity = (string) law_event_meta( $event_id, '_law_venue_capacity' );
		$before_places   = (int) law_event_meta( $event_id, '_law_tickets_available' );
		law_event_update_meta( $event_id, '_law_venue_capacity', $capacity );
		law_event_update_meta( $event_id, '_law_tickets_available', $tickets );
		law_event_log_capacity_change( $event_id, $before_capacity, $actor );
		law_event_tickets_changed(
			$event_id,
			$before_places,
			(int) law_event_meta( $event_id, '_law_tickets_available' ),
			$actor,
			'committee_panel'
		);
		return '';
	}

	private function log_messages( int $event_id ): array {
		return wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' );
	}

	public function test_the_panel_accepts_a_band_and_places_within_it(): void {
		$host = $this->make_user();
		wp_set_current_user( $this->make_committee_user() );

		$event_id = $this->make_event( array( '_law_venue_needed' => self::NEEDS_VENUE ), 'law-approved', $host );

		$this->assertSame( '', $this->panel_saves_venue( $event_id, '101-150', '120' ) );
		$this->assertSame( '101-150', (string) law_event_meta( $event_id, '_law_venue_capacity' ) );
		$this->assertSame( 120, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
	}

	public function test_the_panel_refuses_places_above_the_band(): void {
		// The ceiling is inclusive, so the band's own number is allowed and one
		// more is not.
		$this->assertSame( '', law_committee_venue_input_error( 'Under 50', '50' ) );
		$this->assertStringContainsString(
			'cannot exceed the venue capacity band',
			law_committee_venue_input_error( 'Under 50', '51' )
		);

		// "251+" and "TBC" set no ceiling.
		$this->assertSame( '', law_committee_venue_input_error( '251+', '4000' ) );
		$this->assertSame( '', law_committee_venue_input_error( 'TBC', '4000' ) );

		// Blank places mean no limit; a band is optional too.
		$this->assertSame( '', law_committee_venue_input_error( '', '' ) );
		$this->assertSame( '', law_committee_venue_input_error( '', '4000' ) );
	}

	public function test_the_panel_refuses_a_band_that_is_not_one_of_the_bands(): void {
		// A free-text band is read as "no ceiling" wherever it is checked, so
		// storing a typo would silently uncap the ticket allocation.
		$this->assertStringContainsString(
			'not one of the venue capacity bands',
			law_committee_venue_input_error( '101 to 150', '120' )
		);
	}

	public function test_the_panel_refuses_places_that_are_not_a_whole_number(): void {
		foreach ( array( '0', '-5', '12.5', 'lots' ) as $bad ) {
			$this->assertStringContainsString(
				'whole number of 1 or more',
				law_committee_venue_input_error( 'TBC', $bad ),
				"Refuses places of '$bad'"
			);
		}
	}

	public function test_a_refused_panel_save_leaves_the_event_untouched(): void {
		$host = $this->make_user();
		wp_set_current_user( $this->make_committee_user() );

		$event_id = $this->make_event( self::PLACED, 'law-approved', $host );

		$this->assertNotSame( '', $this->panel_saves_venue( $event_id, 'Under 50', '900' ) );
		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', '101-150', 120, 'After a refused panel save:' );
	}

	public function test_the_panel_logs_the_band_and_the_places_separately(): void {
		$host = $this->make_user();
		wp_set_current_user( $this->make_committee_user() );

		$event_id = $this->make_event( array( '_law_venue_needed' => self::NEEDS_VENUE ), 'law-approved', $host );
		$this->panel_saves_venue( $event_id, '101-150', '120' );

		$messages = implode( "\n", $this->log_messages( $event_id ) );
		$this->assertStringContainsString( 'Venue capacity changed: (not set) → 101-150.', $messages );
		$this->assertStringContainsString( 'Places available changed: 0 → 120.', $messages );

		// An unchanged save logs neither.
		$before = count( $this->log_messages( $event_id ) );
		$this->panel_saves_venue( $event_id, '101-150', '120' );
		$this->assertCount( $before, $this->log_messages( $event_id ) );
	}

	public function test_the_panel_can_clear_the_places_back_to_no_limit(): void {
		$host = $this->make_user();
		wp_set_current_user( $this->make_committee_user() );

		$event_id = $this->make_event( self::PLACED, 'law-approved', $host );

		$this->assertSame( '', $this->panel_saves_venue( $event_id, '101-150', '' ) );
		$this->assertSame( 0, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
		$this->assertStringContainsString(
			'Places available changed: 120 → 0.',
			implode( "\n", $this->log_messages( $event_id ) )
		);
	}

	public function test_post_approval_host_edit_on_a_placed_event_changes_nothing(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		$event_id = $this->make_event(
			array_merge( self::PLACED, array( '_law_venue_needed' => self::NEEDS_VENUE ) ),
			'law-approved',
			$host
		);

		// Even a crafted post carrying the three fields is ignored: they were
		// never on this host's form.
		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'      => self::HAS_VENUE,
					'venue'             => 'Somewhere else entirely',
					'venue_capacity'    => '251+',
					'tickets_available' => '900',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);
		$this->assertSame( $event_id, $result );

		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', '101-150', 120, 'After a crafted host post:' );
		$this->assertSame( self::NEEDS_VENUE, law_event_meta( $event_id, '_law_venue_needed' ), 'The locked answer holds.' );
	}

	/* The band floor (15 September 2026) ____________________________________ */

	public function test_the_band_floors_are_derived_from_the_ceilings(): void {
		// Each band starts one above the previous band's ceiling, so the two
		// halves of a band can never drift. "TBC" follows an uncapped band and
		// so has no floor, which is the point of it.
		$this->assertSame( 1, law_events_venue_capacity_band_floor( 'Under 50' ) );
		$this->assertSame( 51, law_events_venue_capacity_band_floor( '51-100' ) );
		$this->assertSame( 101, law_events_venue_capacity_band_floor( '101-150' ) );
		$this->assertSame( 151, law_events_venue_capacity_band_floor( '151-250' ) );
		$this->assertSame( 251, law_events_venue_capacity_band_floor( '251+' ) );
		$this->assertNull( law_events_venue_capacity_band_floor( 'TBC' ) );

		// An unrecognised band has no floor: callers refuse it outright, and
		// inventing one for it would be worse than having none.
		$this->assertNull( law_events_venue_capacity_band_floor( '101 to 150' ) );
		$this->assertNull( law_events_venue_capacity_band_floor( '' ) );
	}

	public function test_the_panel_refuses_places_below_the_band(): void {
		// The floor is inclusive, so the band's own first number is allowed and
		// one fewer is not.
		$this->assertSame( '', law_committee_venue_input_error( '51-100', '51' ) );
		$this->assertStringContainsString(
			'at least 51',
			law_committee_venue_input_error( '51-100', '50' ),
			'50 places belong to "Under 50", not to "51-100".'
		);

		// "251+" is uncapped above, not unbounded: it gained a floor of 251 when
		// the rule landed, where it used to constrain nothing at all.
		$this->assertSame( '', law_committee_venue_input_error( '251+', '251' ) );
		$this->assertStringContainsString(
			'at least 251',
			law_committee_venue_input_error( '251+', '250' )
		);

		// "TBC" stays the one band that bounds nothing, and a band nobody chose
		// has nothing to measure against.
		$this->assertSame( '', law_committee_venue_input_error( 'TBC', '1' ) );
		$this->assertSame( '', law_committee_venue_input_error( '', '1' ) );
	}

	public function test_blank_places_never_meet_the_floor(): void {
		// A blank stores 0 and law_event_tickets_remaining() reads 0 as "not
		// open for booking", so there is no allocation to judge against a band.
		// Were the floor written as its own check rather than inside the chain,
		// every band would refuse an event that has released nothing.
		foreach ( array( 'Under 50', '51-100', '101-150', '151-250', '251+', 'TBC' ) as $band ) {
			$this->assertSame( '', law_committee_venue_input_error( $band, '' ), "Blank places under '$band'" );
		}
	}

	public function test_the_panel_writes_a_pair_that_sits_inside_the_band(): void {
		$host = $this->make_user();
		wp_set_current_user( $this->make_committee_user() );

		$event_id = $this->make_event( array( '_law_venue_needed' => self::NEEDS_VENUE ), 'law-approved', $host );

		$this->assertNotSame( '', $this->panel_saves_venue( $event_id, '101-150', '20' ), 'Below the floor.' );
		$this->assertNotSame( '', $this->panel_saves_venue( $event_id, '101-150', '180' ), 'Above the ceiling.' );
		$this->assertSame( '', $this->panel_saves_venue( $event_id, '101-150', '101' ) );
		$this->assertSame( 101, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
	}

	public function test_the_band_floor_applies_to_a_host_setting_their_own_places(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// law-draft is the one status where the places are still the host's, so
		// it is the one place on their form the floor can fire.
		$event_id = $this->make_event( array(), 'law-draft', $host );

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'      => self::HAS_VENUE,
					'venue'             => 'Their own offices',
					'venue_capacity'    => '51-100',
					'tickets_available' => '50',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'at least 51', $result->get_error_message( 'tickets_available' ) );
		$this->assertSame( '', $result->get_error_message( 'venue_capacity' ), 'The band is reachable, so it is not blamed.' );
	}

	public function test_the_floor_never_blocks_a_host_moving_to_a_bigger_room(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// The committee released 120 places in a 101-150 room. Under review the
		// host may still correct the room's size (Denis, 14 September 2026), and
		// the common correction is upwards -- they moved somewhere bigger. The
		// floor of "151-250" is 151, so judging it against the committee's 120
		// would take that correction away. It is judged on the places instead,
		// and the places are not the half being posted here.
		$event_id = $this->make_event(
			array_merge( self::PLACED, array( '_law_venue_needed' => self::HAS_VENUE ) ),
			'law-proposed',
			$host
		);

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'   => self::HAS_VENUE,
					'venue'          => 'Guildhall, EC2V 7HH',
					'venue_capacity' => '251+',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);

		$this->assertSame( $event_id, $result );
		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', '251+', 120, 'After a move to a bigger room:' );
	}

	public function test_a_stored_pair_outside_the_band_does_not_trap_its_host(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// Both halves are locked once the event is approved, so the host posts
		// neither and has no way to fix either. Judging the stored pair here
		// would refuse every unrelated edit they make -- description, speakers,
		// contacts -- over a control they cannot see. Migrated events carry
		// exactly this shape: 120 places in a room banded "Under 50".
		$event_id = $this->make_event(
			array(
				'_law_venue'             => 'Guildhall, EC2V 7HH',
				'_law_venue_capacity'    => 'Under 50',
				'_law_tickets_available' => 120,
				'_law_venue_needed'      => self::HAS_VENUE,
			),
			'law-approved',
			$host
		);

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed' => self::HAS_VENUE,
					'venue'        => 'Guildhall, EC2V 7HH',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);

		$this->assertSame( $event_id, $result, 'The host can still edit the rest of their event.' );
		$this->assert_venue_values( $event_id, 'Guildhall, EC2V 7HH', 'Under 50', 120, 'The breaching pair is left alone:' );
	}

	public function test_the_form_refuses_a_band_that_is_not_one_of_the_bands(): void {
		$host = $this->make_user();
		wp_set_current_user( $host );

		// The panel has refused a crafted band since 9 September 2026; this form
		// stored it and every check downstream then read it as "no ceiling",
		// quietly uncapping the allocation.
		$event_id = $this->make_event( array(), 'law-draft', $host );

		$result = law_events_form_save(
			$this->valid_input(
				array(
					'venue_needed'      => self::HAS_VENUE,
					'venue'             => 'Their own offices',
					'venue_capacity'    => '101 to 150',
					'tickets_available' => '120',
				)
			),
			array(),
			get_post( $event_id ),
			$host
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'not one of the venue capacity bands', $result->get_error_message( 'venue_capacity' ) );
	}
}
