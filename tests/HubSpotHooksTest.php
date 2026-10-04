<?php
/**
 * functions/hubspot/hooks.php (HUBSPOT_SYNC.md §6.4, §9 HubSpotHooksTest):
 * the WordPress events that mark a person dirty. Every case asserts a queue
 * row and nothing else: the hooks never talk to HubSpot, so no request mock
 * is needed and the bootstrap backstop would fail any that slipped through.
 */

class HubSpotHooksTest extends LAW_Test_Case {

	private string $mode = 'dry';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		law_hubspot_install_tables();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->mode = 'dry';
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'year' => 2026 ) );
		add_filter( 'law_hubspot_mode', array( $this, 'mode' ) );
	}

	protected function tearDown(): void {
		remove_filter( 'law_hubspot_mode', array( $this, 'mode' ) );
		parent::tearDown();
	}

	public function mode() {
		return $this->mode;
	}

	private function email_of( int $user_id ): string {
		return law_hubspot_normalise_email( get_user_by( 'id', $user_id )->user_email );
	}

	private function clear_queue(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . law_hubspot_queue_table() );
	}

	private function assertQueued( string $email, string $reason = '', string $why = '' ): void {
		$row = law_hubspot_queue_row( $email );
		$this->assertNotNull( $row, trim( $email . ' should be queued. ' . $why ) );
		if ( '' !== $reason ) {
			$this->assertSame( $reason, $row->reason );
		}
	}

	private function assertNotQueued( string $email ): void {
		$this->assertNull( law_hubspot_queue_row( $email ), $email . ' should not be queued.' );
	}

	/**
	 * Move an event's status the way the workflow engine does: behind its
	 * flag, so the wp_insert_post_data guard lets it through. The engine's
	 * own approve action also raises a Stripe invoice, which this is not about.
	 */
	private function set_event_status( int $event_id, string $status ): void {
		$GLOBALS['law_workflow_transitioning'] = true;
		try {
			wp_update_post( array( 'ID' => $event_id, 'post_status' => $status ) );
		} finally {
			$GLOBALS['law_workflow_transitioning'] = false;
		}
	}

	private function insert_booking( int $event_id, int $user_id, string $status = 'law-applied' ): int {
		$booking_id = wp_insert_post(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_status' => $status,
				'post_title'  => 'Test booking',
				'post_author' => $user_id,
				'post_parent' => $event_id,
			)
		);
		$this->posts[] = $booking_id;
		return (int) $booking_id;
	}

	/* ---- accounts ---------------------------------------------------------- */

	public function test_registration_queues_the_new_account(): void {
		$user_id = $this->make_user();

		$this->assertQueued( $this->email_of( $user_id ), 'user_register' );
	}

	public function test_profile_update_queues(): void {
		$user_id = $this->make_user();
		$this->clear_queue();

		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Ada' ) );

		$this->assertQueued( $this->email_of( $user_id ), 'profile_update' );
	}

	public function test_profile_meta_written_directly_queues(): void {
		$user_id = $this->make_user();
		$this->clear_queue();

		update_user_meta( $user_id, 'organisation', 'Analytical Engines' );
		$this->assertQueued( $this->email_of( $user_id ), 'profile_meta' );

		$this->clear_queue();
		update_user_meta( $user_id, 'country', 'United Kingdom' );
		$this->assertQueued( $this->email_of( $user_id ) );

		$this->clear_queue();
		update_user_meta( $user_id, 'job_title', 'Arbitrator' );
		$this->assertQueued( $this->email_of( $user_id ) );
	}

	public function test_unrelated_user_meta_does_not_queue(): void {
		$user_id = $this->make_user();
		$this->clear_queue();

		update_user_meta( $user_id, 'session_tokens', array() );
		update_user_meta( $user_id, 'some_plugin_flag', 1 );

		$this->assertNotQueued( $this->email_of( $user_id ) );
	}

	public function test_deleting_a_user_does_nothing(): void {
		$user_id = $this->make_user();
		$email   = $this->email_of( $user_id );
		$this->clear_queue();

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $user_id );
		$this->users = array_diff( $this->users, array( $user_id ) );

		$this->assertNotQueued( $email );
	}

	public function test_nothing_queues_when_the_mode_is_off(): void {
		$this->mode = 'off';
		$user_id    = $this->make_user();

		$this->assertNotQueued( $this->email_of( $user_id ) );
		$this->assertSame( 0, law_hubspot_queue_stats()['total'] );
	}

	/* ---- bookings ---------------------------------------------------------- */

	public function test_booking_confirmed_queues_the_attendee(): void {
		$user_id = $this->make_user();
		$event   = $this->make_event( array( '_law_tickets_available' => 5 ), 'publish' );
		$booking = $this->insert_booking( $event, $user_id, 'law-applied' );
		$this->clear_queue();

		law_booking_set_status( $booking, 'publish' );

		$this->assertQueued( $this->email_of( $user_id ), 'booking_publish' );
	}

	public function test_booking_cancelled_queues_the_attendee(): void {
		$user_id = $this->make_user();
		$event   = $this->make_event( array( '_law_tickets_available' => 5 ), 'publish' );
		$booking = $this->insert_booking( $event, $user_id, 'publish' );
		$this->clear_queue();

		law_booking_set_status( $booking, 'law-cancelled' );

		$this->assertQueued( $this->email_of( $user_id ), 'booking_law-cancelled' );
	}

	public function test_booking_through_the_engine_queues_the_attendee(): void {
		$user_id = $this->make_user();
		$event   = $this->make_event(
			array(
				'_law_tickets_available'  => 5,
				'_law_start'              => gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:00' ) ),
				'_law_end'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 20:00' ) ),
				'_law_registration_state' => 'open',
			),
			'publish'
		);
		$this->clear_queue();

		$result = $this->make_booking_id( $event, $user_id );
		if ( is_wp_error( $result ) ) {
			$this->markTestSkipped( 'Booking engine refused the fixture: ' . $result->get_error_message() );
		}

		$this->assertQueued( $this->email_of( $user_id ) );
	}

	/* ---- events ------------------------------------------------------------ */

	public function test_event_approval_queues_host_co_owners_and_speakers(): void {
		$host  = $this->make_user();
		$co    = $this->make_user();
		$email = 'speaker-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$event = $this->make_event( array(), 'law-proposed', $host );
		law_event_set_co_owner_ids( $event, array( $co ) );
		$speaker_id = law_speaker_upsert( array( 'name' => 'Grace Hopper', 'email' => $email ) );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker_id ) ) );
		$this->clear_queue();

		$this->set_event_status( $event, 'law-approved' );

		$this->assertQueued( $this->email_of( $host ), 'event_law-approved' );
		$this->assertQueued( $this->email_of( $co ), 'event_law-approved' );
		$this->assertQueued( $email, 'event_law-approved' );
	}

	public function test_event_leaving_approved_queues_too(): void {
		$host  = $this->make_user();
		$event = $this->make_event( array(), 'law-approved', $host );
		$this->clear_queue();

		$this->set_event_status( $event, 'law-withdrawn' );

		$this->assertQueued( $this->email_of( $host ), 'event_law-withdrawn' );
	}

	public function test_event_moving_between_unapproved_statuses_does_not_queue(): void {
		$host  = $this->make_user();
		$event = $this->make_event( array(), 'law-proposed', $host );
		$this->clear_queue();

		$this->set_event_status( $event, 'law-draft' );

		$this->assertNotQueued( $this->email_of( $host ) );
	}

	public function test_setting_co_owners_queues_added_and_removed(): void {
		$host  = $this->make_user();
		$old   = $this->make_user();
		$new   = $this->make_user();
		$event = $this->make_event( array(), 'law-approved', $host );
		law_event_set_co_owner_ids( $event, array( $old ) );
		$this->clear_queue();

		law_event_set_co_owner_ids( $event, array( $new ) );

		$this->assertQueued( $this->email_of( $new ), 'co_owner' );
		$this->assertQueued( $this->email_of( $old ), 'co_owner' );
		$this->assertNotQueued( $this->email_of( $host ) );
	}

	public function test_fee_tier_change_queues_the_event_people(): void {
		$host  = $this->make_user();
		$event = $this->make_event( array(), 'law-approved', $host );
		$this->clear_queue();

		law_event_update_meta( $event, '_law_fee_tier', 'sponsor' );

		$this->assertQueued( $this->email_of( $host ), 'fee_tier' );
	}

	/* ---- speakers ---------------------------------------------------------- */

	public function test_speaker_rows_change_queues_the_speakers_named(): void {
		$email_a    = 'speaker-a-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$email_b    = 'speaker-b-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$speaker_a  = law_speaker_upsert( array( 'name' => 'Speaker A', 'email' => $email_a ) );
		$speaker_b  = law_speaker_upsert( array( 'name' => 'Speaker B', 'email' => $email_b ) );
		$event      = $this->make_event( array(), 'law-approved' );
		$this->clear_queue();

		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker_a ), array( 'speaker_id' => $speaker_b, 'role' => 'moderator' ) ) );

		$this->assertQueued( $email_a, 'speakers_changed' );
		$this->assertQueued( $email_b, 'speakers_changed' );
	}

	public function test_creating_and_saving_a_speaker_record_queues_it(): void {
		$email      = 'speaker-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$speaker_id = law_speaker_upsert( array( 'name' => 'Grace Hopper', 'email' => $email ) );
		$this->assertQueued( $email, 'speaker_email', 'The address is written after the insert, so the meta hook catches a new speaker.' );

		$this->clear_queue();
		wp_update_post( array( 'ID' => $speaker_id, 'post_title' => 'Grace B. Hopper' ) );

		$this->assertQueued( $email, 'speaker_save' );
	}

	public function test_changing_a_speakers_email_queues_the_new_address(): void {
		$old        = 'speaker-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$new        = 'speaker-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$speaker_id = law_speaker_upsert( array( 'name' => 'Grace Hopper', 'email' => $old ) );
		$this->clear_queue();

		law_event_update_meta( $speaker_id, '_law_speaker_email', $new );

		$this->assertQueued( $new, 'speaker_email' );
	}

	public function test_speaker_without_an_email_queues_nothing(): void {
		law_speaker_upsert( array( 'name' => 'Anonymous Speaker' ) );

		$this->assertSame( 0, law_hubspot_queue_stats()['total'] );
	}
}
