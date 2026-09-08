<?php
/**
 * The LAW reference generator (format, sequence continuation) and the
 * migration's merge-tag translation and payment derivation.
 */
class ReferenceAndMigrationTest extends LAW_Test_Case {

	public function test_reference_format_and_sequence_continuation(): void {
		$before = (int) get_option( 'law_events_reference_counter', 0 );
		update_option( 'law_events_reference_counter', 207, false );

		$next = law_events_next_reference();
		$this->assertSame( 'LAW26-00208', $next, 'Continues the GP Unique ID sequence in the same format.' );
		$this->assertMatchesRegularExpression( '/^LAW\d{2}-\d{5}$/', law_events_next_reference() );

		update_option( 'law_events_reference_counter', $before, false );
	}

	/**
	 * A counter that has never been used starts at 1.
	 *
	 * It used to start at whatever wp_options' AUTO_INCREMENT happened to be:
	 * the insert-and-increment was one statement, and when it genuinely
	 * inserted, MySQL's own assignment of the new row's option_id won and
	 * became LAST_INSERT_ID. On staging that made the first booking #111547.
	 */
	public function test_a_brand_new_counter_starts_at_one_and_stays_in_order(): void {
		$option = 'law_test_counter_' . wp_generate_password( 8, false );
		$this->assertFalse( get_option( $option ), 'The probe option must not exist yet.' );

		$this->assertSame( 1, law_events_bump_counter( $option ), 'The first number a counter issues is 1.' );
		$this->assertSame( 2, law_events_bump_counter( $option ) );
		$this->assertSame( 3, law_events_bump_counter( $option ) );

		// A block claims consecutive numbers and returns the last of them.
		$this->assertSame( 6, law_events_bump_counter( $option, 3 ) );
		$this->assertSame( 7, law_events_bump_counter( $option ) );

		// And the stored value agrees with what was handed out, so a later
		// request continues the sequence rather than repeating it.
		$this->assertSame( 7, (int) get_option( $option ) );

		delete_option( $option );
	}

	public function test_a_seeded_counter_continues_from_its_seed(): void {
		$option = 'law_test_counter_' . wp_generate_password( 8, false );
		update_option( $option, 40, false );
		$this->assertSame( 41, law_events_bump_counter( $option ) );
		delete_option( $option );
	}

	public function test_merge_tag_translation(): void {
		$in  = 'Dear {Name (First):3.3}, your event {Event title:17} ({Unique ID:70}) — pay at {Stripe invoice URL:83}. {latest_comment}';
		$out = law_migration_translate_tags( $in );
		$this->assertStringContainsString( '{host_name}', $out );
		$this->assertStringContainsString( '{event_title}', $out );
		$this->assertStringContainsString( '{law_reference}', $out );
		$this->assertStringContainsString( '{invoice_url}', $out );
		$this->assertStringContainsString( '{latest_comment}', $out );
		$this->assertStringNotContainsString( ':17}', $out );
	}

	public function test_unmapped_tags_are_left_in_place_for_review(): void {
		$out = law_migration_translate_tags( 'Value: {Some custom field:42}' );
		$this->assertStringContainsString( '{Some custom field:42}', $out, 'Never silently mangled.' );
	}

	public function test_payment_status_derivation(): void {
		$this->assertSame( 'paid', law_migration_derive_payment( 'Confirmed', 120000, 'https://x' ) );
		$this->assertSame( 'free', law_migration_derive_payment( 'Confirmed', 0, '' ) );
		$this->assertSame( 'unpaid', law_migration_derive_payment( 'Approved', 120000, 'https://x' ) );
		$this->assertSame( 'unpaid', law_migration_derive_payment( 'Proposed', 0, '' ) );
	}

	public function test_legacy_status_mapping(): void {
		$this->assertSame( 'law-proposed', law_event_status_from_legacy( 'Proposed' ) );
		$this->assertSame( 'law-sent-back', law_event_status_from_legacy( 'Sent back' ) );
		$this->assertSame( 'law-approved', law_event_status_from_legacy( 'Approved' ) );
		$this->assertSame( 'publish', law_event_status_from_legacy( 'Confirmed' ) );
		$this->assertSame( 'law-rejected', law_event_status_from_legacy( 'Rejected' ) );
		$this->assertSame( 'law-proposed', law_event_status_from_legacy( 'garbage' ), 'Unknown statuses land safely at Proposed.' );
	}

	public function test_speaker_dedupe_email_first_then_name(): void {
		$a = law_speaker_upsert( array( 'name' => 'Jane Testspeaker', 'email' => 'jane-test-dedupe@example.test', 'organisation' => 'Firm A' ) );
		$this->posts[] = $a;

		// Same email, different name spelling: the same person.
		$b = law_speaker_upsert( array( 'name' => 'JANE  TESTSPEAKER', 'email' => 'Jane-Test-Dedupe@example.test' ) );
		$this->assertSame( $a, $b );

		// No email, matching normalised name: the same person.
		$c = law_speaker_upsert( array( 'name' => 'jane testspeaker' ) );
		$this->assertSame( $a, $c );

		// Different name, no email: a new person.
		$d = law_speaker_upsert( array( 'name' => 'John Testspeaker' ) );
		$this->posts[] = $d;
		$this->assertNotSame( $a, $d );

		// Organisation and job title are per appearance now: the upsert never
		// writes them to the shared post (the old meta key is off the schema).
		$this->assertSame( '', (string) get_post_meta( $a, '_law_organisation', true ) );
	}

	public function test_speaker_rows_sanitiser_keeps_appearance_fields_and_reads_legacy_override(): void {
		$speaker       = law_speaker_upsert( array( 'name' => 'Row Shape Testspeaker' ) );
		$this->posts[] = $speaker;
		$event         = $this->make_event();

		law_event_update_meta(
			$event,
			'_law_speakers',
			array(
				array( 'speaker_id' => $speaker, 'organisation' => ' Firm B ', 'job_title' => 'Partner', 'photo_id' => '12', 'bio' => "  Two   spaces\nand a line break.  " ),
				array( 'speaker_id' => $speaker, 'organisation_override' => 'Legacy Firm' ),
				array( 'speaker_id' => 0, 'organisation' => 'Dropped: no speaker' ),
			)
		);
		$rows = law_event_meta( $event, '_law_speakers' );
		$this->assertCount( 2, $rows, 'Rows without a speaker are dropped.' );
		$this->assertSame( array( 'speaker_id', 'role', 'organisation', 'job_title', 'photo_id', 'bio', 'sort' ), array_keys( $rows[0] ) );
		$this->assertSame( 'Firm B', $rows[0]['organisation'] );
		$this->assertSame( 'Partner', $rows[0]['job_title'] );
		$this->assertSame( 12, $rows[0]['photo_id'] );
		$this->assertSame( "Two   spaces\nand a line break.", $rows[0]['bio'], 'The biography takes the textarea sanitiser: line breaks and inner runs of spaces survive, unlike the single-line fields.' );
		$this->assertSame( '', $rows[1]['bio'], 'A row with no biography stores an empty string, not a missing key.' );
		$this->assertSame( 'Legacy Firm', $rows[1]['organisation'], 'A pre-appearance organisation_override is read as the organisation.' );
	}

	public function test_speaker_appearances_are_per_event_and_the_archive_uses_the_first(): void {
		$speaker       = law_speaker_upsert( array( 'name' => 'Appearance Testspeaker', 'email' => 'appearance-test@example.test' ) );
		$this->posts[] = $speaker;

		// Two confirmed events: the earlier one has no photo, the later one does.
		$early = $this->make_event( array( '_law_start' => '2026-06-01 09:00:00' ), 'publish' ); // Created first, dated later.
		$late  = $this->make_event( array( '_law_start' => '2026-03-01 09:00:00' ), 'publish' ); // Created second, dated earlier.
		law_event_update_meta( $early, '_law_speakers', array( array( 'speaker_id' => $speaker, 'organisation' => 'PDLegal', 'job_title' => 'Associate', 'photo_id' => 0 ) ) );
		law_event_update_meta( $late, '_law_speakers', array( array( 'speaker_id' => $speaker, 'organisation' => 'PDLegal LLC', 'job_title' => 'Managing Partner', 'photo_id' => 4242, 'bio' => 'Wrote a different bio for the later event.' ) ) );

		// A session under the late event with a blank row: falls through to the event's row.
		$session = wp_insert_post( array( 'post_type' => LAW_SESSION_CPT, 'post_status' => 'publish', 'post_parent' => $late, 'post_title' => 'Panel' ) );
		$this->posts[] = $session;
		law_event_update_meta( $session, '_law_speakers', array( array( 'speaker_id' => $speaker ) ) );

		$this->assertSame( array( 'role' => '', 'organisation' => 'PDLegal', 'job_title' => 'Associate', 'photo_id' => 0, 'bio' => '' ), law_speaker_appearance_for_event( $speaker, $early ) );
		$this->assertSame( array( 'role' => '', 'organisation' => 'PDLegal LLC', 'job_title' => 'Managing Partner', 'photo_id' => 4242, 'bio' => 'Wrote a different bio for the later event.' ), law_speaker_appearance_for_event( $speaker, $late ) );
		$this->assertNull( law_speaker_appearance_for_event( $speaker, $this->make_event() ), 'Not on the event: null.' );

		// The archive/profile rule: first appearance, each field falling through
		// to the next appearance only when the earlier row leaves it empty.
		$this->reset_speaker_maps();
		$first = law_speaker_first_appearance( $speaker );
		$this->assertSame( $early, $first['event_id'] );
		$this->assertSame( 'PDLegal', $first['organisation'] );
		$this->assertSame( 'Associate', $first['job_title'] );
		$this->assertSame( 4242, $first['photo_id'], 'The earlier row has no photo, so the later one is used.' );
		$this->assertSame( 'Wrote a different bio for the later event.', $first['bio'], 'Same fall-through for the biography.' );

		$appearances = law_speaker_appearances( $speaker );
		$this->assertSame( array( $early, $late ), wp_list_pluck( $appearances, 'event_id' ), 'First-submitted event first, whatever its date.' );

		$profile = law_speaker_post_profile( $speaker, array( $early, $late ) );
		$this->assertSame( 'PDLegal', $profile['organisation'] );
		$this->assertSame( 'Associate', $profile['job_title'] );

		// Cards: the event's own values; a blank session row inherits the event's.
		$cards = law_event_speaker_cards( $late );
		$this->assertSame( 'PDLegal LLC', $cards[0]['organisation'] );
		$this->assertSame( 4242, $cards[0]['photo_id'] );
		$this->assertSame( 'Wrote a different bio for the later event.', $cards[0]['bio'], "The card shows the event's own biography." );
		$sessions = law_event_session_rows( $late );
		$this->assertSame( 'Managing Partner', $sessions[0]['speakers'][0]['job_title'] );
		$this->assertSame( 4242, $sessions[0]['speakers'][0]['photo_id'] );
		$this->assertSame( 'Wrote a different bio for the later event.', $sessions[0]['speakers'][0]['bio'], "A blank session row inherits the event's biography." );

		// No row biography anywhere: the speaker post's editor content stands in.
		wp_update_post( array( 'ID' => $speaker, 'post_content' => 'The shared fallback biography.' ) );
		$this->assertSame( 'The shared fallback biography.', law_event_speaker_cards( $early )[0]['bio'], "An empty row falls back to the speaker post's content." );

		// Event pages: the photo set for THIS event wins; a row without one falls
		// back to the first photo ever provided (the archive/profile rule).
		law_event_update_meta( $early, '_law_speakers', array( array( 'speaker_id' => $speaker, 'organisation' => 'PDLegal', 'job_title' => 'Associate', 'photo_id' => 1111 ) ) );
		$third = $this->make_event( array(), 'publish' );
		law_event_update_meta( $third, '_law_speakers', array( array( 'speaker_id' => $speaker, 'organisation' => 'PDLegal LLC', 'job_title' => 'Partner' ) ) );
		$this->reset_speaker_maps();
		$this->assertSame( 1111, law_speaker_first_appearance( $speaker )['photo_id'], 'Archive and profile: the first photo provided.' );
		$this->assertSame( 4242, law_event_speaker_cards( $late )[0]['photo_id'], 'An event with its own photo shows that photo.' );
		$this->assertSame( 4242, law_event_session_rows( $late )[0]['speakers'][0]['photo_id'], 'Sessions inherit the event row.' );
		$this->assertSame( 1111, law_event_speaker_cards( $third )[0]['photo_id'], 'An event without its own photo shows the first one provided.' );
	}

	/** The confirmed-event maps are memoised per request; tests that create events must rebuild them. */
	private function reset_speaker_maps(): void {
		law_speakers_confirmed_maps( true );
	}
}
