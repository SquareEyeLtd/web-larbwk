<?php
/**
 * The status guard: an existing event's status only moves through the
 * workflow engine — the classic editor's Publish/Save Draft (or any stray
 * wp_update_post) cannot confirm or hide an event. Plus the co-owner
 * dashboard query matching values, never serialized-array keys.
 */
class StatusGuardTest extends LAW_Test_Case {

	public function test_direct_status_change_is_reverted(): void {
		$event = $this->make_event( array(), 'law-proposed' );

		// The classic editor's Publish button does exactly this.
		wp_update_post( array( 'ID' => $event, 'post_status' => 'publish' ) );
		$this->assertSame( 'law-proposed', get_post_status( $event ), 'Publish outside the workflow is reverted.' );

		wp_update_post( array( 'ID' => $event, 'post_status' => 'draft' ) );
		$this->assertSame( 'law-proposed', get_post_status( $event ), 'Core draft parking is reverted.' );

		// Content edits still save fine.
		wp_update_post( array( 'ID' => $event, 'post_title' => 'Guarded title update' ) );
		$this->assertSame( 'Guarded title update', get_the_title( $event ) );
	}

	public function test_workflow_transitions_still_move_status(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'sponsor' ), 'law-proposed' );
		$this->assertTrue( law_event_workflow_transition( $event, 'approve' ) );
		$this->assertSame( 'publish', get_post_status( $event ), 'The engine still publishes (zero-fee path).' );
	}

	public function test_trash_is_still_allowed(): void {
		$event = $this->make_event( array(), 'law-rejected' );
		wp_trash_post( $event );
		$this->assertSame( 'trash', get_post_status( $event ) );
	}

	public function test_co_owner_query_matches_values_not_array_keys(): void {
		$low  = $this->make_user(); // Simulates a low user ID being an array KEY.
		$co_a = $this->make_user();
		$co_b = $this->make_user();

		$event = $this->make_event( array(), 'law-proposed', $this->make_user() );
		law_event_set_co_owner_ids( $event, array( $co_a, $co_b ) );

		$this->assertContains( $event, law_events_owned_event_ids( $co_a ) );
		$this->assertContains( $event, law_events_owned_event_ids( $co_b ) );
		$this->assertNotContains( $event, law_events_owned_event_ids( $low ), 'A non-co-owner never sees the event listed.' );
		// The historic bug: serialized "i:0;i:<co_a>;i:1;i:<co_b>;" matched
		// user ID 1 through the array index. The flat meta cannot.
		$this->assertNotContains( $event, law_events_owned_event_ids( 1 ) );
	}
}
