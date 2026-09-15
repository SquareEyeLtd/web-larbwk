<?php
/**
 * External events (functions/events/external-events.php): the third kind of
 * law_event the committee owns outright, after the flagship and the receptions.
 *
 * What is worth a test here is not the form — it is the three places where an
 * external event has to behave unlike every other event, each of which is a
 * silent failure if it regresses:
 *
 * 1. The booking engine must REFUSE it, not merely hide its button. Nobody
 *    would notice a missing refusal until a delegate held a place at an event
 *    LAW does not run.
 * 2. Its status must survive a save. The workflow guard reverts any status
 *    change it does not recognise, and an external event that silently reverted
 *    to draft would vanish from the programme with no error anywhere.
 * 3. Its draft must be visible to the committee. law_committee_events()
 *    excludes law-draft because a host's draft is private; an external draft is
 *    the committee's own, and excluding it would strand work they had just saved.
 */

class ExternalEventsTest extends LAW_Test_Case {

	private $source_before;

	protected function setUp(): void {
		parent::setUp();
		// The booking engine and the calendar map only run on the CPT source.
		$this->source_before = get_option( 'law_events_source', null );
		update_option( 'law_events_source', 'cpt' );
	}

	protected function tearDown(): void {
		if ( null === $this->source_before ) {
			delete_option( 'law_events_source' );
		} else {
			update_option( 'law_events_source', $this->source_before );
		}
		parent::tearDown();
	}

	/** A published external event with a booking link and places available. */
	private function make_external( array $meta = array(), $status = 'publish' ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_is_external'        => 1,
					'_law_registration_state' => 'external',
					'_law_external_url'       => 'https://example.org/tickets',
					'_law_start'              => gmdate( 'Y-m-d H:i', strtotime( '+30 days 10:00' ) ),
					'_law_end'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 17:00' ) ),
					// Deliberately set, so the refusals below are proved to come
					// from the external check and not from "no places released".
					'_law_tickets_available'  => 50,
				),
				$meta
			),
			$status
		);
	}

	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'law_external_id'    => 0,
				'law_form_action'    => 'publish',
				'event_title'        => 'GAR Live: Women in Arbitration',
				'event_type'         => 'Seminar / talk',
				'description'        => 'A day of panels.',
				'external_date'      => '2026-11-30',
				'external_start'     => '09:00',
				'external_end'       => '17:25',
				'external_url'       => 'https://example.org/tickets',
				'host_organisations' => 'Global Arbitration Review',
				'venue'              => 'Pan Pacific, London',
			),
			$overrides
		);
	}

	private function save( array $input, $actor = 0 ) {
		$result = law_external_event_save( $input, $actor ?: $this->make_committee_user() );
		if ( ! is_wp_error( $result ) ) {
			$this->posts[] = (int) $result;
		}
		return $result;
	}

	/* The booking refusal ____________________________________________________ */

	public function test_the_engine_refuses_a_booking_on_an_external_event(): void {
		$event_id = $this->make_external();
		$this->assertTrue( law_event_is_external( $event_id ) );

		$this->assertWPError(
			law_booking_create( $event_id, $this->make_user(), array() ),
			'law_booking_external'
		);
	}

	public function test_the_form_guard_refuses_before_the_dialog_is_even_served(): void {
		$event_id = $this->make_external();
		$this->assertWPError( law_booking_guard_form_open( $event_id ), 'law_booking_external' );
	}

	/**
	 * The waitlist reaches law_booking_guard_open(), not the form guard, so the
	 * refusal it inherits comes from the places check. Asserted rather than
	 * assumed: it holds only because an external event never has places
	 * released, and if that ever changed the queue would silently open on an
	 * event with nothing to queue for.
	 */
	public function test_the_waitlist_cannot_start_on_an_external_event(): void {
		$event_id = $this->make_external( array( '_law_tickets_available' => '' ) );
		$this->assertWPError( law_waitlist_join( $event_id, $this->make_user(), array() ), 'law_booking_not_open' );
	}

	/* The listing ____________________________________________________________ */

	public function test_the_card_links_out_in_a_new_tab_and_wires_up_no_dialog(): void {
		$event_id = $this->make_external();
		$action   = law_booking_card_action( law_events_map_post( $event_id, array() ) );

		$this->assertSame( 'Register', $action['label'] );
		$this->assertSame( 'https://example.org/tickets', $action['url'] );
		$this->assertTrue( $action['external'], 'target=_blank comes from this key.' );
		$this->assertTrue( $action['arrow'], 'The external-link glyph comes from this key.' );
		$this->assertArrayNotHasKey( 'dialog', $action, 'A dialog key would bind booking-form.js to a link that leaves the site.' );
		$this->assertStringContainsString( 'opens in a new tab', $action['sr_label'] );
	}

	public function test_without_a_link_the_button_is_disabled_rather_than_absent(): void {
		$event_id = $this->make_external( array( '_law_external_url' => '' ) );
		$action   = law_booking_card_action( law_events_map_post( $event_id, array() ) );

		$this->assertSame( 'Registration opening soon', $action['label'] );
		$this->assertTrue( $action['disabled'] );
		$this->assertArrayNotHasKey( 'url', $action, 'Nowhere to send anybody yet.' );
	}

	/**
	 * The tone is what paints the panel. Without an explicit case the function
	 * falls through to capacity arithmetic on a null remaining, which reads
	 * every external event as nearly full.
	 */
	public function test_the_state_resolves_to_external_and_paints_closed(): void {
		$state = law_booking_state( $this->make_external() );
		$this->assertSame( 'external', $state['state'] );
		$this->assertSame( 'closed', $state['tone'] );
	}

	public function test_the_panel_says_where_the_booking_happens(): void {
		$event_id = $this->make_external();
		ob_start();
		law_booking_render_action( law_events_map_post( $event_id, array() ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Registration is on the organiser', $html );
		$this->assertStringNotContainsString( 'law-booking-substate', $html, 'One line: the button already says where it goes.' );
		$this->assertStringContainsString( 'https://example.org/tickets', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
	}

	/* The saver ______________________________________________________________ */

	public function test_publishing_puts_it_straight_on_the_programme(): void {
		$event_id = $this->save( $this->input() );
		$this->assertIsInt( $event_id );

		$this->assertSame( 'publish', get_post_status( $event_id ), 'No approval step: the committee is recording an event that already exists.' );
		$this->assertTrue( law_external_event_is( $event_id ) );
		$this->assertSame( 'external', law_event_meta( $event_id, '_law_registration_state' ) );
		$this->assertSame( '2026-11-30 09:00', law_event_meta( $event_id, '_law_start' ) );
		$this->assertSame( '2026-11-30 17:25', law_event_meta( $event_id, '_law_end' ) );
		$this->assertSame( '', law_event_meta( $event_id, '_law_slot_label' ), 'An external event holds no programme slot.' );
	}

	/**
	 * The status guard reverts a status change it does not recognise. Without
	 * law_event_is_managed_by_law() knowing about external events, this save
	 * would silently leave the event on the programme.
	 */
	public function test_it_can_be_taken_back_off_the_programme(): void {
		$event_id = $this->save( $this->input() );
		$this->save( $this->input( array( 'law_external_id' => $event_id, 'law_form_action' => 'draft' ) ) );
		$this->assertSame( 'law-draft', get_post_status( $event_id ) );

		$this->save( $this->input( array( 'law_external_id' => $event_id, 'law_form_action' => 'publish' ) ) );
		$this->assertSame( 'publish', get_post_status( $event_id ) );
	}

	/**
	 * Real data: form 10 entry 1559, Law Rocks! LONDON 2026, was captured as
	 * 19:45 to 11:30. The model cannot express a night that runs past midnight
	 * and must not guess a second date, so the end is dropped and said so.
	 */
	public function test_an_end_before_its_start_is_dropped_and_logged(): void {
		$event_id = $this->save( $this->input( array( 'external_start' => '19:45', 'external_end' => '11:30' ) ) );

		$this->assertSame( '2026-11-30 19:45', law_event_meta( $event_id, '_law_start' ) );
		$this->assertSame( '', law_event_meta( $event_id, '_law_end' ), 'A backwards range would print "7:45pm - 11:30am".' );

		$messages = wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' );
		$this->assertNotEmpty(
			array_filter( $messages, fn( $m ) => false !== strpos( (string) $m, 'is not after the start time' ) ),
			'Silently dropping it would be the committee never finding out.'
		);
	}

	public function test_a_draft_needs_only_a_title_but_publishing_needs_the_rest(): void {
		$bare = array(
			'law_external_id' => 0,
			'event_title'     => 'Something announced but not yet detailed',
			'law_form_action' => 'draft',
		);
		$this->assertIsInt( $this->save( $bare ) );

		$result = law_external_event_save( array_merge( $bare, array( 'law_form_action' => 'publish' ) ), $this->make_committee_user() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertContains( 'external_date', $result->get_error_codes() );
		$this->assertContains( 'event_type', $result->get_error_codes() );
		$this->assertContains( 'description', $result->get_error_codes() );
	}

	/**
	 * The two ways a booking link can be worse than absent: a scheme esc_url_raw
	 * refuses outright (it returns an empty string, so the Register button would
	 * point at nothing), and a path with no host (which esc_url_raw keeps, and
	 * which would send people back into this site).
	 *
	 * A typo in the HOST is deliberately not caught: esc_url_raw turns
	 * "wwww.example.org" into "http://wwww.example.org", a perfectly well-formed
	 * address for a site that does not exist, and no amount of parsing tells us
	 * that. The committee sees it on the listing.
	 *
	 * @dataProvider bad_links
	 */
	public function test_a_link_that_is_not_a_web_address_is_refused( string $link ): void {
		$result = law_external_event_save( $this->input( array( 'external_url' => $link ) ), $this->make_committee_user() );
		$this->assertInstanceOf( WP_Error::class, $result, $link . ' should be refused.' );
		$this->assertContains( 'external_url', $result->get_error_codes() );
	}

	public static function bad_links(): array {
		return array(
			'javascript'    => array( 'javascript:alert(1)' ),
			'data uri'      => array( 'data:text/html;base64,PHA+' ),
			'ftp'           => array( 'ftp://files.example.org/tickets' ),
			'relative path' => array( '/tickets' ),
			'no host'       => array( 'https://' ),
		);
	}

	public function test_an_empty_link_is_a_real_answer_not_an_error(): void {
		$event_id = $this->save( $this->input( array( 'external_url' => '' ) ) );
		$this->assertIsInt( $event_id );
		$this->assertSame( '', law_event_meta( $event_id, '_law_external_url' ) );
	}

	/** A forged or stale ID must reach nothing, not quietly create an event. */
	public function test_a_posted_id_that_is_not_an_external_event_is_refused(): void {
		$ordinary = $this->make_event();
		$result   = law_external_event_save( $this->input( array( 'law_external_id' => $ordinary ) ), $this->make_committee_user() );
		$this->assertWPError( $result, 'law_external_missing' );
	}

	/* The committee's list ___________________________________________________ */

	public function test_an_external_draft_is_listed_where_a_host_draft_is_not(): void {
		$external = $this->make_external( array(), 'law-draft' );
		$host     = $this->make_event( array(), 'law-draft' );

		$ids = wp_list_pluck( law_committee_events(), 'ID' );
		$this->assertContains( $external, $ids, 'The committee owns this one; hiding it strands work they just saved.' );
		$this->assertNotContains( $host, $ids, 'A host draft is still their own private, unsubmitted data.' );
	}

	/**
	 * An external event can never hold a booking here, so the count and the
	 * Bookings button would both be a way into an empty list. A hollow "0"
	 * reads as "nobody has booked" rather than "bookings do not happen here".
	 */
	public function test_the_table_offers_no_bookings_way_in_for_an_external_event(): void {
		$external = $this->make_external();
		$hosted   = $this->make_event( array( '_law_tickets_available' => 20 ), 'publish' );
		wp_set_current_user( $this->make_committee_user() );

		ob_start();
		get_template_part( 'parts/events/dashboard-list' );
		$html = (string) ob_get_clean();

		$row = fn( $id ) => preg_match( '/<code>' . law_event_meta( $id, '_law_reference' ) . '<\/code>(.*?)<\/tr>/s', $html, $m ) ? $m[1] : '';

		$this->assertStringNotContainsString( '>Bookings<', $row( $external ) );
		$this->assertStringContainsString( '>Bookings<', $row( $hosted ), 'Guard: an ordinary confirmed event keeps its way in.' );
	}

	public function test_the_organiser_filter_separates_external_from_hosted(): void {
		$external = $this->make_external();
		$hosted   = $this->make_event( array(), 'publish' );

		$_GET['law_run_by'] = 'external';
		$ids                = wp_list_pluck( law_committee_events(), 'ID' );
		unset( $_GET['law_run_by'] );

		$this->assertContains( $external, $ids );
		$this->assertNotContains( $hosted, $ids );
	}
}
