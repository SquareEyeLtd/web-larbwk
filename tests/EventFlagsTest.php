<?php
/**
 * The committee's two classification switches: _law_is_law_event ("run by
 * LAW") and _law_session_agenda, which together replaced the removed
 * law_event_category taxonomy (Denis, 9 September 2026).
 *
 * The switches are quiet booleans, but the agenda one GATES a form section
 * whose saver deletes any session the posted form did not claim, so the
 * regressions worth pinning here are mostly about not losing host content.
 */
class EventFlagsTest extends LAW_Test_Case {

	/** A session row as the repeater posts it. */
	private function session_row( array $overrides = array() ): array {
		return array_merge(
			array( 'id' => '', 'title' => 'A session', 'start' => '09:00', 'end' => '10:00', 'description' => 'What happens.', 'speakers' => array() ),
			$overrides
		);
	}

	/**
	 * A complete, valid non-draft form input. Slot labels are read from the
	 * settings rather than hard-coded: law_events_sanitise_preferred_slots()
	 * drops anything that is not a configured slot.
	 */
	private function valid_input( array $overrides = array() ): array {
		$slot_labels = array_keys( law_events_slot_choices( array() ) );
		return array_merge(
			array(
				'law_form_action'     => 'update',
				'event_title'         => 'Flags test event',
				'description'         => 'Flags test description.',
				'event_type'          => 'Social event',
				'host_organisations'  => 'Flags Org LLP',
				'preferred_slots'     => $slot_labels ? array( $slot_labels[0] ) : array( 'Any slot' ),
				'sectors'             => array(),
				'venue_needed'        => 'Yes, please share our details with venue hosts',
				'fee_tier'            => 'uk',
				'invoice_name'        => 'Flags Contact',
				'invoice_email'       => 'flags-invoice@example.test',
				'invoice_line1'       => '1 Test Street',
				'invoice_city'        => 'London',
				'invoice_postal_code' => 'EC1A 1AA',
				'invoice_country'     => 'United Kingdom',
				'terms'               => '1',
			),
			$overrides
		);
	}

	/** Track this event's sessions for tearDown and return their IDs. */
	private function sessions( int $event_id ): array {
		$ids         = law_event_session_ids( $event_id );
		$this->posts = array_merge( $this->posts, array_diff( $ids, $this->posts ) );
		return $ids;
	}

	/** Run the committee dashboard handler's flag block over a $_POST shape. */
	private function committee_saves_flags( int $event_id, array $post ): void {
		$actor = get_current_user_id();
		$_POST = array_merge( array( 'law_flags_present' => '1' ), $post );

		$before = array(
			'_law_is_law_event'   => (int) law_event_meta( $event_id, '_law_is_law_event' ),
			'_law_session_agenda' => (int) law_event_meta( $event_id, '_law_session_agenda' ),
		);
		law_event_update_meta( $event_id, '_law_is_law_event', ! empty( $_POST['law_is_law_event'] ) );
		law_event_update_meta( $event_id, '_law_session_agenda', ! empty( $_POST['law_session_agenda'] ) );
		law_event_log_flag_change( $event_id, $before, $actor );

		$_POST = array();
	}

	private function log_messages( int $event_id ): array {
		return wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' );
	}

	/* The gate _______________________________________________________________ */

	public function test_the_gate_is_off_by_default_and_follows_the_switch(): void {
		$event = $this->make_event();
		$this->assertFalse( law_event_has_session_agenda( $event ), 'A fresh event has no agenda.' );

		law_event_update_meta( $event, '_law_session_agenda', 1 );
		$this->assertTrue( law_event_has_session_agenda( $event ) );
	}

	public function test_the_gate_stays_open_while_sessions_exist(): void {
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_session_agenda', 1 );
		law_events_form_save_sessions( $event, array( $this->session_row() ) );
		$this->sessions( $event );

		// Unticking the box must NOT hide a section that still has content: the
		// saver deletes rows the form did not post, so hiding it here is exactly
		// the shape that destroys a host's agenda.
		law_event_update_meta( $event, '_law_session_agenda', 0 );
		$this->assertTrue(
			law_event_has_session_agenda( $event ),
			'An event with sessions keeps its agenda section whatever the switch says.'
		);
	}

	public function test_the_gate_is_closed_for_a_new_or_unknown_event(): void {
		// A brand-new submission has no post yet, so there is no switch to read.
		$this->assertFalse( law_event_has_session_agenda( null ) );
		$this->assertFalse( law_event_has_session_agenda( 0 ) );

		// Not a law_event: a speaker or booking ID must not open the gate.
		$user = $this->make_user();
		$this->assertFalse( law_event_has_session_agenda( $user ) );
	}

	/* The save guard: the data-loss regression this exists to prevent ________ */

	public function test_a_save_without_the_section_leaves_sessions_untouched(): void {
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event( array(), 'law-proposed', $host );
		law_event_update_meta( $event, '_law_session_agenda', 1 );
		law_events_form_save_sessions( $event, array( $this->session_row( array( 'title' => 'Keep me' ) ) ) );
		$before = $this->sessions( $event );
		$this->assertCount( 1, $before );

		// The committee switches the agenda off, then any other field is saved
		// from a form that never rendered the section: no law_sessions_present,
		// no sessions key. The sessions must survive.
		law_event_update_meta( $event, '_law_session_agenda', 0 );
		$result = law_events_form_save( $this->valid_input(), array(), get_post( $event ), $host );
		$this->assertSame( $event, $result, 'The save itself should succeed.' );

		$this->assertSame( $before, law_event_session_ids( $event ), 'Sessions must not be deleted by a save from a form with no agenda section.' );
	}

	public function test_a_forged_sentinel_cannot_create_sessions_on_a_gated_event(): void {
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event( array(), 'law-proposed', $host );
		$this->assertFalse( law_event_has_session_agenda( $event ) );

		// A hand-made POST carrying the sentinel and rows, on an event the
		// committee never opted in. The gate, not the sentinel, is the
		// authorisation check.
		law_events_form_save(
			$this->valid_input(
				array(
					'law_sessions_present' => '1',
					'sessions'             => array( $this->session_row( array( 'title' => 'Forged' ) ) ),
				)
			),
			array(),
			get_post( $event ),
			$host
		);

		$this->assertSame( array(), law_event_session_ids( $event ), 'A forged sentinel must not write sessions onto an opted-out event.' );
	}

	public function test_discarded_rows_are_logged_rather_than_dropped_silently(): void {
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event( array(), 'law-proposed', $host );

		law_events_form_save(
			$this->valid_input(
				array(
					'law_sessions_present' => '1',
					'sessions'             => array( $this->session_row( array( 'title' => 'Lost row' ) ) ),
				)
			),
			array(),
			get_post( $event ),
			$host
		);

		// The host's form was rendered before the committee closed the gate, so
		// their rows go nowhere. The saver never reports a success it did not
		// achieve, so this has to leave a trail.
		$this->assertNotEmpty(
			array_filter(
				$this->log_messages( $event ),
				static function ( $line ) {
					return false !== strpos( $line, 'discarded' );
				}
			),
			'Rows dropped because the agenda is switched off must be logged.'
		);
	}

	public function test_the_section_still_saves_when_the_gate_is_open(): void {
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event( array(), 'law-proposed', $host );
		law_event_update_meta( $event, '_law_session_agenda', 1 );

		law_events_form_save(
			$this->valid_input(
				array(
					'law_sessions_present' => '1',
					'sessions'             => array( $this->session_row( array( 'title' => 'Opening remarks' ) ) ),
				)
			),
			array(),
			get_post( $event ),
			$host
		);

		$ids = $this->sessions( $event );
		$this->assertCount( 1, $ids );
		$this->assertSame( 'Opening remarks', get_post( $ids[0] )->post_title );
	}

	/* The section list _______________________________________________________ */

	public function test_the_form_sections_drop_the_agenda_when_the_gate_is_closed(): void {
		$event = $this->make_event();

		$this->assertArrayNotHasKey( 'agenda', law_events_form_sections( get_post( $event ) ) );
		$this->assertArrayNotHasKey( 'agenda', law_events_form_sections( null ), 'A new submission has no agenda section.' );

		law_event_update_meta( $event, '_law_session_agenda', 1 );
		$sections = law_events_form_sections( get_post( $event ) );
		$this->assertArrayHasKey( 'agenda', $sections );
		// Order matters: the section nav and the fieldsets have to agree.
		$this->assertSame(
			array( 'details', 'speakers', 'venue', 'owners', 'fees', 'agenda', 'finish' ),
			array_keys( $sections )
		);
	}

	/* Saving and logging _____________________________________________________ */

	public function test_the_committee_can_set_and_clear_both_flags(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event();

		$this->committee_saves_flags( $event, array( 'law_is_law_event' => '1', 'law_session_agenda' => '1' ) );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_is_law_event' ) );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_session_agenda' ) );

		// Unticked boxes post nothing, which is what the sentinel is for: absent
		// must mean "off", not "leave it alone".
		$this->committee_saves_flags( $event, array() );
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_is_law_event' ) );
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_session_agenda' ) );
	}

	public function test_a_change_writes_exactly_one_log_entry_naming_both_flags(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event();

		$before = count( $this->log_messages( $event ) );
		$this->committee_saves_flags( $event, array( 'law_is_law_event' => '1', 'law_session_agenda' => '1' ) );
		$messages = $this->log_messages( $event );

		// One entry, not one per flag: law_event_log_entries() orders by
		// comment_date_gmt with no tie-break, so two in the same second would
		// display in arbitrary order.
		$this->assertCount( $before + 1, $messages );
		$this->assertStringContainsString( 'Marked as run by LAW.', $messages[0] );
		$this->assertStringContainsString( 'Session agenda turned on.', $messages[0] );
	}

	public function test_an_unchanged_save_logs_nothing(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event();

		$this->committee_saves_flags( $event, array( 'law_is_law_event' => '1' ) );
		$after_first = count( $this->log_messages( $event ) );

		$this->committee_saves_flags( $event, array( 'law_is_law_event' => '1' ) );
		$this->assertCount( $after_first, $this->log_messages( $event ), 'Re-saving the same values must not log.' );
	}

	/* The dashboard filters __________________________________________________ */

	public function test_the_hosted_filter_matches_a_missing_key_and_a_stored_zero(): void {
		$never_saved = $this->make_event();
		$saved_off   = $this->make_event();
		$law_run     = $this->make_event();

		// Both limbs of the OR clause. law_event_update_meta() keeps a literal
		// 0 rather than deleting it ('' === 0 is false under PHP 8), so an
		// event the committee has saved with the box unticked has a row, while
		// one never opened has none. A NOT EXISTS-only query would lose the first.
		law_event_update_meta( $saved_off, '_law_is_law_event', 0 );
		law_event_update_meta( $law_run, '_law_is_law_event', 1 );
		$this->assertSame( '0', get_post_meta( $saved_off, '_law_is_law_event', true ), 'Guard: the 0 must actually be stored.' );

		$_GET['law_run_by'] = 'host';
		$ids                = wp_list_pluck( law_committee_events(), 'ID' );
		unset( $_GET['law_run_by'] );

		$this->assertContains( $never_saved, $ids, 'An event with no meta row is hosted.' );
		$this->assertContains( $saved_off, $ids, 'An event saved with the box unticked is hosted.' );
		$this->assertNotContains( $law_run, $ids );
	}

	public function test_the_run_by_law_filter_matches_only_flagged_events(): void {
		$hosted  = $this->make_event();
		$law_run = $this->make_event();
		law_event_update_meta( $law_run, '_law_is_law_event', 1 );

		$_GET['law_run_by'] = 'law';
		$ids                = wp_list_pluck( law_committee_events(), 'ID' );
		unset( $_GET['law_run_by'] );

		$this->assertContains( $law_run, $ids );
		$this->assertNotContains( $hosted, $ids );
	}

	/* The public programme's Organiser filter ________________________________ */

	/** A Confirmed, slotted event, which is what the public programme shows. */
	private function make_programme_event( bool $run_by_law ): int {
		$date     = array_key_first( law_calendar_week_days() );
		$event_id = $this->make_event(
			array(
				'_law_start' => $date . ' 10:00:00',
				'_law_end'   => $date . ' 11:00:00',
			),
			'publish'
		);
		if ( $run_by_law ) {
			law_event_update_meta( $event_id, '_law_is_law_event', 1 );
		}
		return $event_id;
	}

	/** Programme event IDs under a given ?law_run_by= value. */
	private function programme_ids( string $run_by ): array {
		add_filter( 'pre_option_law_events_source', $cpt = fn() => 'cpt' );
		if ( '' !== $run_by ) {
			$_GET['law_run_by'] = $run_by;
		}
		law_calendar_reset_caches();
		$ids = wp_list_pluck( law_calendar_events(), 'id' );
		unset( $_GET['law_run_by'] );
		remove_filter( 'pre_option_law_events_source', $cpt );
		law_calendar_reset_caches();
		return $ids;
	}

	public function test_the_organiser_filter_splits_the_programme_both_ways(): void {
		$hosted  = $this->make_programme_event( false );
		$law_run = $this->make_programme_event( true );

		$ids = $this->programme_ids( 'law' );
		$this->assertContains( $law_run, $ids );
		$this->assertNotContains( $hosted, $ids );

		$ids = $this->programme_ids( 'host' );
		$this->assertContains( $hosted, $ids, 'An event with no meta row is hosted.' );
		$this->assertNotContains( $law_run, $ids );
	}

	public function test_hosted_covers_an_event_saved_with_the_switch_off(): void {
		// The dashboard's meta_query needs NOT EXISTS *and* != '1' for this,
		// because law_event_update_meta() stores a literal 0 rather than
		// deleting the row. The programme filters mapped arrays in PHP, so the
		// bool cast in law_events_map_post() has to do the same job.
		$saved_off = $this->make_programme_event( false );
		law_event_update_meta( $saved_off, '_law_is_law_event', 0 );
		$this->assertSame( '0', get_post_meta( $saved_off, '_law_is_law_event', true ), 'Guard: the 0 must actually be stored.' );

		$this->assertContains( $saved_off, $this->programme_ids( 'host' ) );
		$this->assertNotContains( $saved_off, $this->programme_ids( 'law' ) );
	}

	public function test_a_junk_organiser_value_filters_nothing_out(): void {
		$hosted  = $this->make_programme_event( false );
		$law_run = $this->make_programme_event( true );

		// Dropped in law_calendar_filters() rather than carried through, so a
		// mistyped URL shows the whole programme instead of emptying it.
		$ids = $this->programme_ids( 'nonsense' );
		$this->assertContains( $hosted, $ids );
		$this->assertContains( $law_run, $ids );
	}

	public function test_the_organiser_filter_survives_a_link_back_from_an_event(): void {
		$_GET['law_run_by'] = 'law';
		law_calendar_reset_caches();
		$args = law_calendar_search_query_args();
		unset( $_GET['law_run_by'] );
		law_calendar_reset_caches();

		$this->assertSame( 'law', $args['law_run_by'] ?? '' );
	}

	public function test_the_organiser_select_renders_with_nothing_flagged_yet(): void {
		// Drawn regardless of the data: "LAW events" can return no cards, but
		// never an empty page, because the flagship block is pinned to its day
		// outside the filtered list.
		add_filter( 'pre_option_law_events_source', $cpt = fn() => 'cpt' );
		law_calendar_reset_caches();

		ob_start();
		get_template_part( 'parts/calendar-filters' );
		$html = (string) ob_get_clean();

		remove_filter( 'pre_option_law_events_source', $cpt );
		law_calendar_reset_caches();

		$this->assertStringContainsString( 'name="law_run_by"', $html );
		$this->assertStringContainsString( '>LAW events<', $html );
		$this->assertStringContainsString( '>Hosted events<', $html );
	}

	public function test_the_agenda_filter_matches_the_switch_in_both_directions(): void {
		$with    = $this->make_event();
		$without = $this->make_event();
		law_event_update_meta( $with, '_law_session_agenda', 1 );

		$_GET['law_agenda'] = 'yes';
		$ids                = wp_list_pluck( law_committee_events(), 'ID' );
		$this->assertContains( $with, $ids );
		$this->assertNotContains( $without, $ids );

		$_GET['law_agenda'] = 'no';
		$ids                = wp_list_pluck( law_committee_events(), 'ID' );
		unset( $_GET['law_agenda'] );
		$this->assertContains( $without, $ids );
		$this->assertNotContains( $with, $ids );
	}

	public function test_the_two_filters_compose_rather_than_overriding(): void {
		$law_with_agenda = $this->make_event();
		$law_no_agenda   = $this->make_event();
		$host_with_agenda = $this->make_event();

		law_event_update_meta( $law_with_agenda, '_law_is_law_event', 1 );
		law_event_update_meta( $law_with_agenda, '_law_session_agenda', 1 );
		law_event_update_meta( $law_no_agenda, '_law_is_law_event', 1 );
		law_event_update_meta( $host_with_agenda, '_law_session_agenda', 1 );

		// "Our own events that have an agenda" is the question a single mixed
		// select could not answer, which is why there are two.
		$_GET['law_run_by'] = 'law';
		$_GET['law_agenda'] = 'yes';
		$ids                = wp_list_pluck( law_committee_events(), 'ID' );
		unset( $_GET['law_run_by'], $_GET['law_agenda'] );

		$this->assertContains( $law_with_agenda, $ids );
		$this->assertNotContains( $law_no_agenda, $ids );
		$this->assertNotContains( $host_with_agenda, $ids );
	}

	/* The export _____________________________________________________________ */

	public function test_the_export_reports_both_flags(): void {
		$event   = $this->make_event();
		$columns = law_committee_export_columns();
		law_event_update_meta( $event, '_law_is_law_event', 1 );

		$row = law_committee_export_row( get_post( $event ) );
		$this->assertCount( count( $columns ), $row, 'The row and the header must stay the same length.' );

		$by_column = array_combine( $columns, $row );
		$this->assertSame( 'Yes', $by_column['Run by LAW'] );
		$this->assertSame( '', $by_column['Session agenda'], 'Blank, not "No", matching the Sponsored column.' );
	}

	/* The backfill ___________________________________________________________ */

	public function test_the_backfill_switches_the_agenda_on_for_events_with_sessions(): void {
		wp_set_current_user( $this->make_committee_user() );
		$with_sessions = $this->make_event();
		$bare          = $this->make_event();

		law_event_update_meta( $with_sessions, '_law_session_agenda', 1 );
		law_events_form_save_sessions( $with_sessions, array( $this->session_row() ) );
		$this->sessions( $with_sessions );
		// Back to the pre-change state: sessions, but no switch.
		law_event_update_meta( $with_sessions, '_law_session_agenda', 0 );

		$scanned = wp_list_pluck( law_events_backfill_agenda_scan(), 'event_id' );
		$this->assertContains( $with_sessions, $scanned );
		$this->assertNotContains( $bare, $scanned );

		law_events_backfill_agenda_apply( array( $with_sessions ) );
		$this->assertSame( 1, (int) law_event_meta( $with_sessions, '_law_session_agenda' ) );

		// Idempotent: a second run finds nothing.
		$this->assertNotContains( $with_sessions, wp_list_pluck( law_events_backfill_agenda_scan(), 'event_id' ) );
	}
}
