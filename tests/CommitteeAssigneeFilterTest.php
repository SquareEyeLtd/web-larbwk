<?php
/**
 * The Events dashboard Assignee filter and the Assignee line on the row
 * (Denis, 21 September 2026).
 *
 * _law_assignee had been committee-only data: set on the detail view and in
 * wp-admin, read by the assignment notification and by one export column, and
 * printed nowhere on the dashboard the committee actually works from. The list
 * now names it under the reference and the filter bar can narrow to it.
 *
 * The delicate part is the OPTIONS, not the filtering. They come from the
 * _law_assignee values in use rather than from law_events_committee_users(),
 * because the picker's helper lists the events_committee role only while
 * law_events_sanitize_assignee() accepts anyone with edit_others_law_events --
 * so an event assigned to an administrator is on the dashboard but would be
 * unreachable from a role-built select. The other trap is the literal '0'
 * law_event_update_meta() writes for "(none)", which must not become an option.
 */
class CommitteeAssigneeFilterTest extends LAW_Test_Case {

	protected function tearDown(): void {
		unset( $_GET['law_assignee'], $_GET['law_run_by'], $_GET['law_kw'] );
		parent::tearDown();
	}

	/** A committee member with a display name, so the options can be read. */
	private function make_named_committee_user( string $name ): int {
		$user_id = $this->make_user( 'events_committee' );
		wp_update_user( array( 'ID' => $user_id, 'display_name' => $name ) );
		return $user_id;
	}

	/** Event IDs the dashboard returns with the filter set to $assignee. */
	private function filtered( $assignee ): array {
		$_GET['law_assignee'] = (string) $assignee;
		$ids                  = wp_list_pluck( law_committee_events(), 'ID' );
		unset( $_GET['law_assignee'] );
		return array_map( 'intval', $ids );
	}

	/* The options ____________________________________________________________ */

	public function test_only_assignees_actually_in_use_are_offered(): void {
		$used   = $this->make_named_committee_user( 'Used Assignee' );
		$unused = $this->make_named_committee_user( 'Unused Assignee' );
		$event  = $this->make_event();
		law_event_update_meta( $event, '_law_assignee', $used );

		$options = law_committee_assignees();
		$this->assertArrayHasKey( $used, $options );
		$this->assertSame( 'Used Assignee', $options[ $used ] );
		$this->assertArrayNotHasKey(
			$unused,
			$options,
			'A name that can return no row is not worth offering.'
		);
	}

	public function test_an_administrator_assignee_is_offered_too(): void {
		// The reason the options are not law_events_committee_users(): an admin
		// passes law_events_sanitize_assignee(), so an event can carry one.
		$admin = $this->make_user( 'administrator' );
		wp_update_user( array( 'ID' => $admin, 'display_name' => 'Admin Assignee' ) );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_assignee', $admin );

		$this->assertArrayHasKey( $admin, law_committee_assignees() );
	}

	public function test_none_does_not_become_an_option(): void {
		// "(none)" stores the literal '0': law_event_update_meta() deletes only
		// on '', and the int sanitiser returns 0.
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_assignee', 0 );

		$this->assertSame( '0', get_post_meta( $event, '_law_assignee', true ), 'Guards the premise.' );
		$this->assertArrayNotHasKey( 0, law_committee_assignees() );
	}

	/* The filter _____________________________________________________________ */

	public function test_the_filter_keeps_only_that_assignees_events(): void {
		$one   = $this->make_named_committee_user( 'Filter One' );
		$two   = $this->make_named_committee_user( 'Filter Two' );
		$mine  = $this->make_event();
		$yours = $this->make_event();
		$none  = $this->make_event();
		law_event_update_meta( $mine, '_law_assignee', $one );
		law_event_update_meta( $yours, '_law_assignee', $two );

		$ids = $this->filtered( $one );
		$this->assertContains( $mine, $ids );
		$this->assertNotContains( $yours, $ids );
		$this->assertNotContains( $none, $ids );
	}

	public function test_an_id_no_event_carries_does_not_filter(): void {
		// A hand-typed ?law_assignee= is checked against the options before it
		// becomes a meta_query clause, so it cannot quietly empty the list.
		$stranger = $this->make_user( 'events_committee' );
		$event    = $this->make_event();

		$this->assertContains( $event, $this->filtered( $stranger ) );
	}

	public function test_the_assignee_and_run_by_filters_apply_together(): void {
		$member   = $this->make_named_committee_user( 'Both Filters' );
		$external = $this->make_event( array(), 'publish' );
		law_event_update_meta( $external, '_law_is_external', 1 );
		law_event_update_meta( $external, '_law_assignee', $member );
		$hosted = $this->make_event( array(), 'publish' );
		law_event_update_meta( $hosted, '_law_assignee', $member );

		$_GET['law_run_by'] = 'host';
		$ids                = $this->filtered( $member );
		unset( $_GET['law_run_by'] );

		$this->assertContains( $hosted, $ids );
		$this->assertNotContains( $external, $ids );
	}
}
