<?php
/**
 * The committee's Manage time slots screen (functions/events/slots-dashboard.php)
 * and the slot write path both screens share (law_events_save_slots() in
 * functions/events/settings.php, 27 October 2026).
 *
 * The assertions that matter are about the events, not the list. An event
 * stores its slot as the label and had its datetimes written from the slot, so
 * a slot any event holds may be retired and nothing else: not deleted, not
 * renamed, not moved (Denis, 27 October 2026). Pinned here on both editors,
 * along with the sort order, the end-after-start rule and the stale-page
 * refusal that stops one committee member's save undoing another's.
 */
class SlotsDashboardTest extends LAW_Test_Case {

	/** A prefix no real event's slot label can share, so the local events never count. */
	private string $tag;

	protected function setUp(): void {
		parent::setUp();
		$this->tag = 'Zz' . wp_generate_password( 6, false ) . ' ';
	}

	private function slot( string $label, string $date, string $start, string $end = '', bool $retired = false ): array {
		return array( 'label' => $this->tag . $label, 'date' => $date, 'start' => $start, 'end' => $end, 'retired' => $retired );
	}

	/** Store a slot list directly, the way an earlier save would have. */
	private function store( array $slots ): void {
		law_events_update_settings( array( 'slots' => $slots ) );
	}

	/** The rows the screen would post for the stored list, unchanged. */
	private function posted(): array {
		$rows = array();
		foreach ( array_values( law_events_slots( true ) ) as $i => $slot ) {
			$rows[] = array_merge( $slot, array( 'index' => $i, 'original' => $slot['label'] ) );
		}
		return $rows;
	}

	private function labels(): array {
		return array_keys( law_events_slots( true ) );
	}

	/* Order _____________________________________________________________ */

	public function test_a_save_sorts_by_date_then_start_then_end_with_onwards_last(): void {
		$this->store( array() );
		$rows = law_events_slots_from_input(
			array(
				array( 'label' => $this->tag . 'D', 'date' => '2026-12-02', 'start' => '09:00', 'end' => '10:00' ),
				array( 'label' => $this->tag . 'C', 'date' => '2026-12-01', 'start' => '18:30', 'end' => '' ),
				array( 'label' => $this->tag . 'B', 'date' => '2026-12-01', 'start' => '18:30', 'end' => '21:00' ),
				array( 'label' => $this->tag . 'A', 'date' => '2026-12-01', 'start' => '08:30', 'end' => '10:00' ),
			)
		);

		$this->assertIsArray( law_events_save_slots( $rows, law_events_slots_version() ) );
		$this->assertSame(
			array( $this->tag . 'A', $this->tag . 'B', $this->tag . 'C', $this->tag . 'D' ),
			$this->labels(),
			'Date, then start, then end; an empty end means onwards, so it follows an end at the same start.'
		);
	}

	public function test_a_wholly_blank_new_row_is_dropped_not_refused(): void {
		$rows = law_events_slots_from_input(
			array(
				array( 'label' => $this->tag . 'A', 'date' => '2026-12-01', 'start' => '08:30' ),
				array( 'label' => '', 'date' => '', 'start' => '', 'end' => '', 'original' => '' ),
			)
		);
		$this->assertCount( 1, $rows );
	}

	/* Validation ________________________________________________________ */

	public function test_label_date_and_start_are_required_and_the_message_names_the_row(): void {
		$errors = law_events_validate_slots(
			law_events_slots_from_input(
				array(
					4 => array( 'label' => $this->tag . 'Fine', 'date' => '2026-12-01', 'start' => '08:30' ),
					7 => array( 'label' => '', 'date' => '', 'start' => '', 'end' => '', 'original' => 'Was a slot' ),
				)
			)
		);

		$this->assertSame( array( 'slots[7][label]', 'slots[7][date]', 'slots[7][start]' ), array_keys( $errors ), 'Keyed by the input name the screen posted, gaps in the index and all.' );
		$this->assertStringContainsString( 'Row 2', $errors['slots[7][label]'][0] );
	}

	public function test_an_end_must_be_later_than_the_start_and_both_must_be_real(): void {
		$errors = law_events_validate_slots(
			law_events_slots_from_input(
				array(
					array( 'label' => $this->tag . 'A', 'date' => '2026-12-01', 'start' => '10:00', 'end' => '09:00' ),
					array( 'label' => $this->tag . 'B', 'date' => '2026-02-30', 'start' => '25:00', 'end' => '' ),
					array( 'label' => $this->tag . 'C', 'date' => '2026-12-01', 'start' => '10:00', 'end' => '10:00' ),
					array( 'label' => $this->tag . 'D', 'date' => '2026-12-01', 'start' => '10:00', 'end' => '10:01' ),
				)
			)
		);

		$this->assertArrayHasKey( 'slots[0][end]', $errors );
		$this->assertArrayHasKey( 'slots[1][date]', $errors, '30 February is not a date, however it is typed.' );
		$this->assertArrayHasKey( 'slots[1][start]', $errors );
		$this->assertArrayHasKey( 'slots[2][end]', $errors, 'Later than the start, not equal to it.' );
		$this->assertArrayNotHasKey( 'slots[3][end]', $errors );
	}

	public function test_two_slots_may_not_share_a_label_however_it_is_punctuated(): void {
		$errors = law_events_validate_slots(
			law_events_slots_from_input(
				array(
					array( 'label' => $this->tag . 'Tue 1st Dec: 08:30–10:00', 'date' => '2026-12-01', 'start' => '08:30' ),
					array( 'label' => $this->tag . 'tue 1st dec: 08:30 - 10:00', 'date' => '2026-12-01', 'start' => '08:30' ),
				)
			)
		);

		$this->assertArrayHasKey( 'slots[1][label]', $errors, 'The label is the identity events store, so two that compare equal would collapse into one.' );
	}

	/* Deleting __________________________________________________________ */

	public function test_an_unheld_slot_can_be_deleted(): void {
		$this->store( array( $this->slot( 'Keep', '2026-12-01', '08:30', '10:00' ), $this->slot( 'Drop', '2026-12-01', '10:30', '12:00' ) ) );
		$rows = array_slice( $this->posted(), 0, 1 );

		$this->assertIsArray( law_events_save_slots( $rows, law_events_slots_version() ) );
		$this->assertSame( array( $this->tag . 'Keep' ), $this->labels() );
	}

	public function test_a_slot_an_event_holds_cannot_be_deleted_and_comes_back(): void {
		$this->store( array( $this->slot( 'Keep', '2026-12-01', '08:30', '10:00' ), $this->slot( 'Held', '2026-12-01', '10:30', '12:00' ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Held' ) );
		$before = law_events_settings()['slots'];
		$rows   = array_slice( $this->posted(), 0, 1 );

		$result = law_events_save_slots( $rows, law_events_slots_version() );

		$this->assertWPError( $result, 'law_slots_invalid' );
		$this->assertSame( $before, law_events_settings()['slots'], 'Nothing is written when anything is refused.' );
		$data = $result->get_error_data();
		$this->assertSame( $this->tag . 'Held', $data['restored'][0]['label'], 'The slot is put back so the rest of the edit survives the round trip.' );
		$this->assertSame( $this->tag . 'Held', $data['restored'][0]['original'] );
		$this->assertArrayHasKey( 'slots[1][label]', $data['errors'], 'The error points at the restored row, indexed after the posted ones.' );
	}

	public function test_a_preferred_slot_counts_as_held_too(): void {
		$this->store( array( $this->slot( 'Wanted', '2026-12-01', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_preferred_slots' => array( $this->tag . 'Wanted' ) ) );

		$this->assertWPError( law_events_save_slots( array(), law_events_slots_version() ), 'law_slots_invalid' );
	}

	public function test_an_event_in_the_bin_does_not_hold_a_slot(): void {
		$this->store( array( $this->slot( 'Binned', '2026-12-01', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Binned' ), 'trash' );

		$this->assertSame( 0, law_events_slot_usage_count( law_events_slot_usage(), $this->tag . 'Binned' ) );
		$this->assertIsArray( law_events_save_slots( array(), law_events_slots_version() ) );
	}

	public function test_a_rejected_event_still_holds_its_slot(): void {
		$this->store( array( $this->slot( 'Old', '2026-12-01', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Old' ), 'law-rejected' );

		$this->assertSame( 1, law_events_slot_usage_count( law_events_slot_usage(), $this->tag . 'Old' ) );
	}

	/* Renaming and re-timing ____________________________________________ */

	public function test_a_held_slot_cannot_be_renamed_and_is_put_back(): void {
		$this->store( array( $this->slot( 'Held', '2026-12-01', '08:30', '10:00' ), $this->slot( 'Free', '2026-12-02', '08:30', '10:00' ) ) );
		$event            = $this->make_event( array( '_law_slot_label' => $this->tag . 'Held' ) );
		$before           = law_events_settings()['slots'];
		$rows             = $this->posted();
		$rows[0]['label'] = $this->tag . 'Renamed';

		$result = law_events_save_slots( $rows, law_events_slots_version() );

		$this->assertWPError( $result, 'law_slots_invalid' );
		$this->assertArrayHasKey( 'slots[0][label]', $result->get_error_data()['errors'] );
		$this->assertSame( $before, law_events_settings()['slots'] );
		$this->assertSame( $this->tag . 'Held', law_event_meta( $event, '_law_slot_label' ), 'The event is never touched.' );
	}

	public function test_a_held_slot_cannot_be_moved_to_another_date_or_time(): void {
		$this->store( array( $this->slot( 'Held', '2026-12-01', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_preferred_slots' => array( $this->tag . 'Held' ) ) );

		foreach ( array( 'date' => '2026-12-02', 'start' => '08:45', 'end' => '' ) as $field => $value ) {
			$rows             = $this->posted();
			$rows[0][ $field ] = $value;
			$this->assertWPError( law_events_save_slots( $rows, law_events_slots_version() ), 'law_slots_invalid', "Changing the {$field} of a held slot." );
		}
	}

	public function test_a_held_slot_can_still_be_retired(): void {
		$this->store( array( $this->slot( 'Held', '2026-12-01', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Held' ) );
		$rows               = $this->posted();
		$rows[0]['retired'] = true;

		$this->assertIsArray( law_events_save_slots( $rows, law_events_slots_version() ) );
		$this->assertTrue( law_events_slots( true )[ $this->tag . 'Held' ]['retired'] );
	}

	public function test_a_free_slot_can_be_renamed_and_moved(): void {
		$this->store( array( $this->slot( 'Free', '2026-12-01', '08:30', '10:00' ) ) );
		$rows             = $this->posted();
		$rows[0]['label'] = $this->tag . 'Moved';
		$rows[0]['start'] = '09:00';

		$this->assertIsArray( law_events_save_slots( $rows, law_events_slots_version() ) );
		$this->assertSame( '09:00', law_events_slots( true )[ $this->tag . 'Moved' ]['start'] );
	}

	public function test_a_held_slot_already_stored_out_of_rule_does_not_block_every_save(): void {
		// Its fields are read-only, so refusing them would lock the list for good.
		$this->store( array( $this->slot( 'Odd', '2026-12-01', '10:00', '09:00' ), $this->slot( 'Free', '2026-12-02', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Odd' ) );
		$rows               = $this->posted();
		$rows[1]['retired'] = true;

		$this->assertIsArray( law_events_save_slots( $rows, law_events_slots_version() ) );
	}

	public function test_a_row_retyped_with_a_deleted_slots_label_is_that_slot(): void {
		$this->store( array( $this->slot( 'Same', '2026-12-01', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Same' ) );
		$rows = law_events_slots_from_input(
			array( array( 'label' => $this->tag . 'Same', 'date' => '2026-12-01', 'start' => '08:30', 'end' => '10:00', 'original' => '' ) )
		);

		$this->assertIsArray( law_events_save_slots( $rows, law_events_slots_version() ), 'The label survives unchanged, so nothing the events hold has gone.' );
	}

	/* Concurrency _______________________________________________________ */

	public function test_a_save_from_a_stale_page_is_refused(): void {
		$this->store( array( $this->slot( 'A', '2026-12-01', '08:30', '10:00' ) ) );
		$version = law_events_slots_version();
		$this->store( array( $this->slot( 'A', '2026-12-01', '08:30', '10:00' ), $this->slot( 'Added elsewhere', '2026-12-01', '10:30', '12:00' ) ) );

		$this->assertWPError( law_events_save_slots( array(), $version ), 'law_slots_stale' );
		$this->assertCount( 2, law_events_slots( true ), 'The other member\'s slot is still there.' );
	}

	/* The Settings textarea _____________________________________________ */

	public function test_the_settings_textarea_cannot_rename_or_move_a_held_slot(): void {
		$this->store( array( $this->slot( 'Held', '2026-12-01', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Held' ) );

		foreach ( array(
			array( 'label' => $this->tag . 'Renamed', 'date' => '2026-12-01', 'start' => '08:30', 'end' => '10:00' ),
			array( 'label' => $this->tag . 'Held', 'date' => '2026-12-01', 'start' => '09:00', 'end' => '10:00' ),
		) as $line ) {
			$this->assertWPError( law_events_save_slots( law_events_slots_from_input( array( $line ) ) ), 'law_slots_invalid' );
		}
		$this->assertSame( '08:30', law_events_slots( true )[ $this->tag . 'Held' ]['start'] );
	}

	/* The screen ________________________________________________________ */

	public function test_the_table_locks_a_held_row_and_keeps_the_template_submittable(): void {
		$this->store( array( $this->slot( 'Held', '2026-12-01', '08:30', '10:00' ), $this->slot( 'Free', '2026-12-01', '10:30', '' , true ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Held' ) );
		wp_set_current_user( $this->make_committee_user() );

		ob_start();
		get_template_part( 'parts/events/slots-manage' );
		$html = (string) ob_get_clean();

		$this->assertMatchesRegularExpression( '/<button type="button" class="law-row-remove" disabled\s+aria-label="' . preg_quote( esc_attr( $this->tag . 'Held' ), '/' ) . ' cannot be deleted/', $html );
		$this->assertMatchesRegularExpression( '/name="slots\[0\]\[label\]" required readonly/', $html, 'A held row\'s label is read-only.' );
		$this->assertMatchesRegularExpression( '/name="slots\[0\]\[end\]" readonly/', $html );
		$this->assertDoesNotMatchRegularExpression( '/name="slots\[1\]\[label\]" required readonly/', $html, 'A free row stays editable.' );
		$this->assertDoesNotMatchRegularExpression( '/name="slots\[0\]\[retired\]"[^>]*readonly/', $html, 'Retiring is the one change a held slot takes.' );
		$this->assertStringContainsString( 'aria-label="Delete ' . esc_attr( $this->tag . 'Free' ) . '"', $html );
		$this->assertStringContainsString( '1 confirmed', $html );
		$this->assertMatchesRegularExpression( '/data-name="slots\[__i__\]\[label\]" data-law-required/', $html, 'A hidden required control would block the whole form.' );
		$this->assertStringContainsString( 'name="slots[1][retired]" value="1"  checked', $html );
		$this->assertStringContainsString( 'name="law_slots_version" value="' . law_events_slots_version() . '"', $html );
	}

	public function test_a_refused_held_row_is_redrawn_as_stored_and_a_free_one_as_typed(): void {
		$this->store( array( $this->slot( 'Held', '2026-12-01', '08:30', '10:00' ), $this->slot( 'Free', '2026-12-02', '08:30', '10:00' ) ) );
		$this->make_event( array( '_law_slot_label' => $this->tag . 'Held' ) );
		$typed             = $this->posted();
		$typed[0]['start'] = '11:00';
		$typed[1]['start'] = '11:00';

		$rows = law_slots_dashboard_rows( $typed );

		$this->assertSame( '08:30', $rows[0]['start'], 'Read-only fields show what is stored, not what the refused save carried.' );
		$this->assertSame( 1, $rows[0]['held'] );
		$this->assertSame( '11:00', $rows[1]['start'] );
	}

	public function test_the_screen_is_the_committees_and_sits_under_manage_emails(): void {
		$this->assertSame( LAW_SLOTS_DASHBOARD_PATH, law_account_paths()['slots'] );
		$this->assertSame( LAW_SLOTS_DASHBOARD_TEMPLATE, law_migration_page_map()[ LAW_SLOTS_DASHBOARD_PATH ]['template'] );
		$setup = file_get_contents( get_theme_file_path( 'functions/setup-account-pages.php' ) );
		$this->assertStringContainsString( "\$setup['" . LAW_SLOTS_DASHBOARD_PATH . "']", $setup );
		$this->assertStringContainsString( 'law_setup_slots_dashboard_access()', $setup );

		wp_set_current_user( $this->make_user( 'subscriber' ) );
		law_account_events_reset_cache();
		$this->assertNotContains( 'slots', wp_list_pluck( law_header_nav()['account']['items'], 'key' ) );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		law_account_events_reset_cache();
		$keys = wp_list_pluck( law_header_nav()['account']['items'], 'key' );
		$this->assertSame( array_search( 'emails', $keys, true ) + 1, array_search( 'slots', $keys, true ) );
	}

	public function test_the_failed_save_dialog_can_jump_to_a_row_control(): void {
		$this->assertSame( '[name="slots[3][label]"]', law_events_form_error_selector( 'slots[3][label]' ) );
		$this->assertSame( '', law_events_form_error_selector( 'slots[3][label"]' ), 'Nothing that could break out of the attribute selector.' );
	}
}
