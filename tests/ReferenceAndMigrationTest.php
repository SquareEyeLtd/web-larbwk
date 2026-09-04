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

		// Backfill: the existing organisation is never blanked.
		$this->assertSame( 'Firm A', law_event_meta( $a, '_law_organisation' ) );
	}
}
