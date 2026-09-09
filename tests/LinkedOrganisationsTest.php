<?php
/**
 * Linked organisations (_law_organisation_ids): the name helper, the activity
 * log entry the committee/admin save paths write, and the export columns.
 *
 * The field is committee-only and quiet, but a sponsor-category organisation
 * is one of the three routes to the public "Sponsored" badge, so a change to
 * it has to leave a trail.
 */
class LinkedOrganisationsTest extends LAW_Test_Case {

	/** Create a tracked organisation post, optionally in a sponsor category. */
	private function make_organisation( string $title, string $category = '' ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'organisation',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		$this->posts[] = (int) $post_id;
		if ( '' !== $category ) {
			wp_set_object_terms( $post_id, $category, 'organisation_category' );
		}
		return (int) $post_id;
	}

	private function log_messages( int $event_id ): array {
		return wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' );
	}

	public function test_names_resolve_in_stored_order_with_a_fallback_for_stale_ids(): void {
		$acme  = $this->make_organisation( 'Zeta Chambers' );
		$beta  = $this->make_organisation( 'Alpha Chambers' );
		$event = $this->make_event();

		// Deliberately reverse-alphabetical, plus an ID that matches no post:
		// the stored order is what the committee chose, and a stale link must
		// stay visible rather than vanish from the summary and the export.
		law_event_update_meta( $event, '_law_organisation_ids', array( $acme, $beta, 999999 ) );

		$this->assertSame(
			array( 'Zeta Chambers', 'Alpha Chambers', '#999999' ),
			law_event_organisation_names( $event )
		);
	}

	public function test_no_linked_organisations_gives_an_empty_list(): void {
		$this->assertSame( array(), law_event_organisation_names( $this->make_event() ) );
	}

	public function test_a_change_writes_exactly_one_log_entry_naming_both_sides(): void {
		$actor = $this->make_committee_user();
		$one   = $this->make_organisation( 'First Chambers' );
		$two   = $this->make_organisation( 'Second Chambers' );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_organisation_ids', array( $one ) );

		$before = law_event_meta( $event, '_law_organisation_ids' );
		law_event_update_meta( $event, '_law_organisation_ids', array( $one, $two ) );
		law_event_log_organisation_change( $event, (array) $before, $actor );

		$messages = $this->log_messages( $event );
		$this->assertCount( 1, $messages );
		$this->assertSame(
			'Linked organisations changed from "First Chambers" to "First Chambers, Second Chambers".',
			$messages[0]
		);

		$context = json_decode( get_comment_meta( law_event_log_entries( $event )[0]->comment_ID, '_law_log_context', true ), true );
		$this->assertSame( 'organisations', $context['action'] );
		$this->assertSame( array( $one ), $context['old'] );
		$this->assertSame( array( $one, $two ), $context['new'] );
	}

	public function test_an_unchanged_save_writes_nothing(): void {
		$actor = $this->make_committee_user();
		$org   = $this->make_organisation( 'Same Chambers' );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_organisation_ids', array( $org ) );

		// The wp-admin screen writes this meta on every save, so the
		// before/after guard is the only thing keeping the log quiet.
		$before = law_event_meta( $event, '_law_organisation_ids' );
		law_event_update_meta( $event, '_law_organisation_ids', array( $org ) );
		law_event_log_organisation_change( $event, (array) $before, $actor );

		$this->assertSame( array(), $this->log_messages( $event ) );
	}

	public function test_clearing_the_links_is_logged_as_none(): void {
		$actor = $this->make_committee_user();
		$org   = $this->make_organisation( 'Departing Chambers' );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_organisation_ids', array( $org ) );

		$before = law_event_meta( $event, '_law_organisation_ids' );
		law_event_update_meta( $event, '_law_organisation_ids', array() );
		law_event_log_organisation_change( $event, (array) $before, $actor );

		$this->assertSame(
			array( 'Linked organisations changed from "Departing Chambers" to "(none)".' ),
			$this->log_messages( $event )
		);
	}

	public function test_a_sponsor_category_organisation_flags_the_event_sponsored(): void {
		// A non-sponsor tier with a real fee, so the organisation link is the
		// only thing that can set the flag.
		$event = $this->make_event( array( '_law_fee_tier' => 'uk' ) );

		$plain = $this->make_organisation( 'Unaffiliated Chambers' );
		law_event_update_meta( $event, '_law_organisation_ids', array( $plain ) );
		$this->assertFalse( law_events_post_is_sponsored( get_post( $event ) ) );

		$gold = $this->make_organisation( 'Gold Chambers', 'gold' );
		law_event_update_meta( $event, '_law_organisation_ids', array( $plain, $gold ) );
		$this->assertTrue( law_events_post_is_sponsored( get_post( $event ) ) );
	}

	public function test_export_row_matches_the_column_list_and_carries_the_new_columns(): void {
		$columns = law_committee_export_columns();
		$gold    = $this->make_organisation( 'Exported Chambers', 'gold' );
		$event   = $this->make_event( array( '_law_fee_tier' => 'uk' ) );
		law_event_update_meta( $event, '_law_organisation_ids', array( $gold ) );

		$row = law_committee_export_row( get_post( $event ) );
		$this->assertSameSize( $columns, $row, 'Every column needs a value in the same position.' );

		$by_column = array_combine( $columns, $row );
		$this->assertSame( 'Exported Chambers', $by_column['Linked organisations'] );
		$this->assertSame( 'Yes', $by_column['Sponsored'] );
	}
}
