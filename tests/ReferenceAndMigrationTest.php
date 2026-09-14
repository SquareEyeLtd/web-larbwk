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

	/**
	 * The 14 September 2026 rule: a new event's reference is its own post ID,
	 * written on the first save rather than waiting for the submit transition
	 * (an event created in wp-admin used to show an empty Reference until a
	 * host submitted it).
	 */
	public function test_a_new_event_takes_its_own_id_as_its_reference(): void {
		$event = $this->make_event();
		$this->assertSame( (string) $event, (string) law_event_meta( $event, '_law_reference' ) );

		// Idempotent, and never overwrites: a migrated event's entry-ID
		// reference has to survive every later save.
		law_event_update_meta( $event, '_law_reference', '190' );
		law_events_ensure_reference( $event );
		wp_update_post( array( 'ID' => $event, 'post_title' => 'Renamed' ) );
		$this->assertSame( '190', (string) law_event_meta( $event, '_law_reference' ) );
	}

	/** A migrated event's reference is the Gravity Forms entry ID it came from. */
	public function test_the_reassignment_offers_the_entry_id_for_migrated_events(): void {
		// An entry ID no real event claims: the local database already holds
		// the migrated events, and the scan (rightly) refuses to hand two
		// events the same number.
		$entry_id = 900000 + wp_rand( 1, 99999 );
		$migrated = $this->make_event();
		law_event_update_meta( $migrated, '_law_gf_entry_id', $entry_id );
		law_event_update_meta( $migrated, '_law_reference', 'LAW26-00121' );

		$native = $this->make_event();
		law_event_update_meta( $native, '_law_reference', 'LAW26-00214' );

		$expected = law_events_reference_expected( $migrated );
		$this->assertSame( (string) $entry_id, $expected['reference'] );
		$this->assertSame( 'gf_entry', $expected['basis'] );
		$this->assertSame( 'post_id', law_events_reference_expected( $native )['basis'] );

		$rows = wp_list_pluck( law_events_reference_scan()['fix'], 'reference_new', 'event_id' );
		$this->assertSame( (string) $entry_id, $rows[ $migrated ] ?? '', 'Migrated: the entry ID.' );
		$this->assertSame( (string) $native, $rows[ $native ] ?? '', 'Not migrated: the event ID.' );

		$result = law_events_reference_apply( array( $migrated, $native ) );
		$this->assertSame( 2, $result['applied'] );
		$this->assertSame( (string) $entry_id, (string) law_event_meta( $migrated, '_law_reference' ) );
		$this->assertSame( (string) $native, (string) law_event_meta( $native, '_law_reference' ) );

		// Re-running finds nothing: both are now right.
		$again = wp_list_pluck( law_events_reference_scan()['fix'], 'event_id' );
		$this->assertNotContains( $migrated, $again );
		$this->assertNotContains( $native, $again );

		// And the change is on the record, not silent.
		$messages = wp_list_pluck( law_event_log_entries( $migrated ), 'comment_content' );
		$this->assertNotEmpty( array_filter( $messages, fn( $m ) => str_contains( $m, 'Reference changed from LAW26-00121 to ' . $entry_id ) ) );
	}

	/**
	 * Two events can never be given the same reference. Impossible with the
	 * live data (entry IDs run to 1,171, new post IDs are past 260,000) but
	 * the scan refuses rather than trusting that.
	 */
	public function test_a_duplicate_reference_is_refused(): void {
		$one = $this->make_event();
		$two = $this->make_event();
		// Both claim the same entry, so both would want the same number.
		law_event_update_meta( $one, '_law_gf_entry_id', 987654 );
		law_event_update_meta( $two, '_law_gf_entry_id', 987654 );

		$scan      = law_events_reference_scan();
		$offered   = wp_list_pluck( $scan['fix'], 'event_id' );
		$conflicts = wp_list_pluck( $scan['conflicts'], 'event_id' );

		$this->assertNotContains( $one, $offered );
		$this->assertNotContains( $two, $offered );
		$this->assertContains( $one, $conflicts );
		$this->assertContains( $two, $conflicts );

		$this->assertSame( 0, law_events_reference_apply( array( $one, $two ) )['applied'] );
		$this->assertSame( (string) $one, (string) law_event_meta( $one, '_law_reference' ), 'Untouched.' );
	}

	/**
	 * The reference also lives in Stripe metadata (`law_reference`, on the
	 * customer and the invoice), written at creation and never updated since.
	 * Nothing resolves an event by it, but it is what a human reconciling a
	 * payment reads, so the reassignment re-stamps it.
	 */
	public function test_the_reassignment_restamps_the_stripe_metadata(): void {
		$entry_id = 900000 + wp_rand( 1, 99999 );
		$event    = $this->make_event();
		law_event_update_meta( $event, '_law_gf_entry_id', $entry_id );
		law_event_update_meta( $event, '_law_reference', 'LAW26-00121' );
		law_event_update_meta( $event, '_law_stripe_customer_id', 'cus_test_ref' );
		law_event_update_meta( $event, '_law_stripe_invoice_id', 'in_test_ref' );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_test_ref', 'object' => 'customer' ),
			array( 'id' => 'in_test_ref', 'object' => 'invoice' ),
		);

		$result = law_events_reference_apply( array( $event ) );
		$this->assertSame( 1, $result['applied'] );
		$this->assertSame( 2, $result['stripe'] );
		$this->assertSame( array(), $result['stripe_failed'] );
		$this->assertSame(
			array(
				array( 'method' => 'POST', 'path' => '/v1/customers/cus_test_ref' ),
				array( 'method' => 'POST', 'path' => '/v1/invoices/in_test_ref' ),
			),
			$GLOBALS['law_test_stripe_calls'],
			'A metadata patch on each object, and nothing else: no finalise, no send, no money.'
		);

		// The patch carries the WHOLE identifier block, so an invoice raised by
		// the retired Make scenario also picks up law_event_id.
		$body = $GLOBALS['law_test_stripe_idem'][1]['body'];
		$this->assertSame( (string) $entry_id, $body['metadata']['law_reference'] );
		$this->assertSame( (string) $event, $body['metadata']['law_event_id'] );
		$this->assertSame( (string) $entry_id, $body['metadata']['gf_entry_id'] );
		$this->assertSame( array( 'metadata' ), array_keys( $body ), 'Metadata only.' );

		$logged = wp_list_pluck( law_event_log_entries( $event ), 'comment_content' );
		$this->assertNotEmpty( array_filter( $logged, fn( $m ) => str_contains( $m, 'Stripe invoice in_test_ref re-stamped' ) ) );
	}

	/** Opting out of the Stripe patch still reassigns the reference. */
	public function test_the_stripe_patch_can_be_declined(): void {
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_gf_entry_id', 900000 + wp_rand( 1, 99999 ) );
		law_event_update_meta( $event, '_law_reference', 'LAW26-00121' );
		law_event_update_meta( $event, '_law_stripe_invoice_id', 'in_test_ref' );

		$result = law_events_reference_apply( array( $event ), false );
		$this->assertSame( 1, $result['applied'] );
		$this->assertSame( 0, $result['stripe'] );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Stripe is never touched.' );
	}

	/**
	 * A Stripe failure must not cost the local reference: the site's record is
	 * the one that matters, and the failure is reported and logged instead.
	 */
	public function test_a_failed_stripe_patch_does_not_hold_up_the_reference(): void {
		$entry_id = 900000 + wp_rand( 1, 99999 );
		$event    = $this->make_event();
		law_event_update_meta( $event, '_law_gf_entry_id', $entry_id );
		law_event_update_meta( $event, '_law_reference', 'LAW26-00121' );
		law_event_update_meta( $event, '_law_stripe_invoice_id', 'in_test_ref' );

		// Nothing queued: the test harness refuses the call, standing in for a
		// missing key, a deleted object or a network error.
		$result = law_events_reference_apply( array( $event ) );

		$this->assertSame( 1, $result['applied'] );
		$this->assertSame( (string) $entry_id, (string) law_event_meta( $event, '_law_reference' ), 'Written anyway.' );
		$this->assertCount( 1, $result['stripe_failed'] );

		$logged = wp_list_pluck( law_event_log_entries( $event ), 'comment_content' );
		$this->assertNotEmpty( array_filter( $logged, fn( $m ) => str_contains( $m, 'could NOT be re-stamped' ) ) );
	}

	/**
	 * The re-stamp is reachable on its own, for an event whose reference is
	 * already right but whose Stripe objects were stamped before it changed.
	 */
	public function test_the_stripe_restamp_stands_alone(): void {
		$event = $this->make_event(); // Reference already its own post ID.
		law_event_update_meta( $event, '_law_stripe_invoice_id', 'in_test_restamp' );

		$this->assertNotContains(
			$event,
			wp_list_pluck( law_events_reference_scan()['fix'], 'event_id' ),
			'Nothing to reassign, so the table above offers no row to fix it from.'
		);
		$this->assertContains( $event, wp_list_pluck( law_events_reference_stripe_rows(), 'event_id' ) );

		$GLOBALS['law_test_stripe_queue'] = array( array( 'id' => 'in_test_restamp', 'object' => 'invoice' ) );
		$result = law_events_reference_stripe_restamp( array( $event ) );

		$this->assertSame( 1, $result['patched'] );
		$this->assertSame( array(), $result['failed'] );
		$this->assertSame(
			array( array( 'method' => 'POST', 'path' => '/v1/invoices/in_test_restamp' ) ),
			$GLOBALS['law_test_stripe_calls']
		);
		$this->assertSame(
			(string) $event,
			$GLOBALS['law_test_stripe_idem'][0]['body']['metadata']['law_reference']
		);
	}

	/** Titles are compared loosely: punctuation and entities are not a mismatch. */
	public function test_title_comparison_only_flags_a_real_difference(): void {
		$this->assertFalse( law_events_reference_titles_differ( 'Arbitration &amp; Energy: Hot Topics', 'Arbitration & Energy — Hot topics' ) );
		$this->assertTrue( law_events_reference_titles_differ( 'Arbitration and Energy', 'A completely different event' ) );
		$this->assertFalse( law_events_reference_titles_differ( 'Anything', '' ), 'Nothing to compare against is not a mismatch.' );
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
		$this->assertSame( "Two   spaces\nand a line break.", $rows[0]['bio'], 'The biography takes the rich-text sanitiser: line breaks and inner runs of spaces survive, unlike the single-line fields, and markup would too.' );
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
