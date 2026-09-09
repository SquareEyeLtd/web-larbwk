<?php
/**
 * edit-lock.php: the front-end event form's edit lock (9 September 2026).
 *
 * The theme previously took core's post lock on render and then neither
 * refreshed nor released it, which made the lock both too sticky (merely
 * opening the edit view blocked everyone else for the full 150-second window,
 * with "X is editing this event right now") and too weak (a lock expired under
 * someone who was still typing, and a second person could save over them).
 */
class EditLockTest extends LAW_Test_Case {

	private function heartbeat( $event_id, $lock = '' ): array {
		$response = apply_filters(
			'heartbeat_received',
			array(),
			array( 'law-refresh-event-lock' => array( 'event_id' => $event_id, 'lock' => $lock ) ),
			'front'
		);
		return $response['law-refresh-event-lock'] ?? array();
	}

	public function test_take_and_read_the_lock(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();
		$event_id  = $this->make_event( array(), 'law-approved', $host );

		wp_set_current_user( $committee );
		$this->assertSame( 0, law_event_lock_holder( $event_id ), 'A fresh event is free.' );

		$lock = law_event_lock_take( $event_id );
		$this->assertMatchesRegularExpression( '/^\d+:' . $committee . '$/', $lock );
		$this->assertSame( 0, law_event_lock_holder( $event_id ), 'The holder is never locked out of its own lock.' );

		wp_set_current_user( $host );
		$this->assertSame( $committee, law_event_lock_holder( $event_id ) );
	}

	public function test_release_frees_the_event_instead_of_waiting_out_the_window(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();
		$event_id  = $this->make_event( array(), 'law-approved', $host );

		wp_set_current_user( $committee );
		$lock = law_event_lock_take( $event_id );
		$this->assertTrue( law_event_lock_release( $event_id, $lock ) );

		// Core back-dates the lock to one whole window ago plus 5, so it has a
		// 5-second grace left rather than 150.
		$stored = explode( ':', (string) get_post_meta( $event_id, '_edit_lock', true ) );
		$this->assertSame( 5, law_event_lock_window() - ( time() - (int) $stored[0] ) );

		// Past the grace, the next person is free to edit.
		add_filter( 'wp_check_post_lock_window', static fn() => 145 );
		wp_set_current_user( $host );
		$this->assertSame( 0, law_event_lock_holder( $event_id ) );
		remove_all_filters( 'wp_check_post_lock_window' );
	}

	public function test_a_lock_is_only_ever_released_by_the_user_holding_it(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();
		$event_id  = $this->make_event( array(), 'law-approved', $host );

		wp_set_current_user( $committee );
		$lock = law_event_lock_take( $event_id );

		wp_set_current_user( $host );
		$this->assertFalse( law_event_lock_release( $event_id, $lock ), 'The host must not be able to drop the committee lock.' );
		$this->assertSame( $committee, law_event_lock_holder( $event_id ) );
	}

	public function test_a_stale_release_cannot_clear_a_newer_lock(): void {
		$host      = $this->make_user( 'event_host' );
		$event_id  = $this->make_event( array(), 'law-approved', $host );

		wp_set_current_user( $host );
		$old = law_event_lock_take( $event_id );
		// A second page load by the same person, a second later.
		$new = ( time() + 1 ) . ':' . $host;
		update_post_meta( $event_id, '_edit_lock', $new );

		// The first tab's unload beacon arrives late.
		$this->assertFalse( law_event_lock_release( $event_id, $old ) );
		$this->assertSame( $new, get_post_meta( $event_id, '_edit_lock', true ), 'The newer lock must survive.' );
	}

	public function test_heartbeat_refreshes_the_holders_lock(): void {
		$host     = $this->make_user( 'event_host' );
		$event_id = $this->make_event( array(), 'law-approved', $host );

		wp_set_current_user( $host );
		update_post_meta( $event_id, '_edit_lock', ( time() - 100 ) . ':' . $host );

		$send = $this->heartbeat( $event_id, ( time() - 100 ) . ':' . $host );
		$this->assertArrayHasKey( 'new_lock', $send );
		$this->assertArrayNotHasKey( 'lock_error', $send );
		// Refreshed to now, so it can never expire under someone still typing.
		$stored = explode( ':', (string) get_post_meta( $event_id, '_edit_lock', true ) );
		$this->assertLessThanOrEqual( 2, time() - (int) $stored[0] );
	}

	public function test_heartbeat_reports_a_takeover_and_then_hands_the_lock_over(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();
		$event_id  = $this->make_event( array(), 'law-approved', $host );

		wp_set_current_user( $committee );
		law_event_lock_take( $event_id );

		// The host's page is showing the notice and keeps asking with no lock.
		wp_set_current_user( $host );
		$send = $this->heartbeat( $event_id );
		$this->assertArrayHasKey( 'lock_error', $send );
		$this->assertStringContainsString( 'is editing this event right now', $send['lock_error'] );
		$this->assertStringContainsString( get_userdata( $committee )->display_name, $send['lock_error'] );

		// Once the committee stops refreshing, the waiting page picks the lock
		// up on its own heartbeat — the notice clears without a manual reload.
		update_post_meta( $event_id, '_edit_lock', ( time() - law_event_lock_window() - 5 ) . ':' . $committee );
		$send = $this->heartbeat( $event_id );
		$this->assertArrayHasKey( 'new_lock', $send );
		$this->assertArrayNotHasKey( 'lock_error', $send );
		$this->assertSame( 0, law_event_lock_holder( $event_id ) );
	}

	public function test_heartbeat_ignores_someone_with_no_access_to_the_event(): void {
		$host      = $this->make_user( 'event_host' );
		$stranger  = $this->make_user( 'event_host' );
		$event_id  = $this->make_event( array(), 'law-approved', $host );

		wp_set_current_user( $stranger );
		$this->assertFalse( law_user_can_manage_event( $stranger, $event_id ) );

		$response = apply_filters(
			'heartbeat_received',
			array(),
			array( 'law-refresh-event-lock' => array( 'event_id' => $event_id, 'lock' => '' ) ),
			'front'
		);
		$this->assertArrayNotHasKey( 'law-refresh-event-lock', $response );
	}

	public function test_lock_field_takes_the_lock_and_publishes_it_to_the_browser(): void {
		$host      = $this->make_user( 'event_host' );
		$committee = $this->make_committee_user();
		$event_id  = $this->make_event( array(), 'law-approved', $host );

		wp_set_current_user( $host );
		ob_start();
		law_event_lock_field( get_post( $event_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-law-event-lock', $html );
		$this->assertStringContainsString( 'data-law-lock-event="' . $event_id . '"', $html );
		$this->assertMatchesRegularExpression( '/data-law-lock-value="\d+:' . $host . '"/', $html );
		$this->assertStringNotContainsString( 'law-form-notice', $html, 'A free event shows no notice.' );

		// A second person gets the notice and, crucially, no lock value: their
		// page must not be able to release the holder's lock.
		wp_set_current_user( $committee );
		ob_start();
		law_event_lock_field( get_post( $event_id ) );
		$html = ob_get_clean();

		$this->assertStringContainsString( 'data-law-lock-value=""', $html );
		$this->assertStringContainsString( 'is editing this event right now', $html );
		$this->assertStringContainsString( get_userdata( $host )->display_name, $html );
	}

	/** A new submission has no event yet, so there is nothing to lock. */
	public function test_lock_field_renders_nothing_without_an_event(): void {
		ob_start();
		law_event_lock_field( null );
		$this->assertSame( '', ob_get_clean() );
	}
}
