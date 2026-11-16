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

	private function assertNotQueued( string $email, string $why = '' ): void {
		$this->assertNull( law_hubspot_queue_row( $email ), trim( $email . ' should not be queued. ' . $why ) );
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

	/* ---- phase 3: the badging data --------------------------------------- */

	public function test_dietary_and_access_edits_queue_including_the_last_box_unticked(): void {
		$user_id = $this->make_user();
		$email   = $this->email_of( $user_id );
		$this->clear_queue();

		update_user_meta( $user_id, 'dietary', array( 'Vegan' ) );
		$this->assertQueued( $email, 'profile_meta' );

		$this->clear_queue();
		update_user_meta( $user_id, 'accessibility_other', 'Step-free route' );
		$this->assertQueued( $email, 'profile_meta' );

		$this->clear_queue();
		delete_user_meta( $user_id, 'dietary' );
		$this->assertQueued( $email, 'profile_meta', 'Unticking the last box deletes the key; the icon must still go.' );
	}

	public function test_ticket_type_press_flag_and_stored_answers_queue_the_attendee(): void {
		$user_id = $this->make_user();
		$email   = $this->email_of( $user_id );
		$event   = $this->make_event( array( '_law_tickets_available' => 5, '_law_is_reception' => 1 ), 'publish' );
		$booking = $this->insert_booking( $event, $user_id, 'publish' );
		$this->clear_queue();

		law_event_update_meta( $booking, '_law_ticket_type', 'patron' );
		$this->assertQueued( $email, 'booking_meta' );

		$this->clear_queue();
		law_event_update_meta( $booking, '_law_ticket_type', '' );
		$this->assertQueued( $email, 'booking_meta', 'Clearing deletes the key; the pass type still has to be re-evaluated.' );

		$this->clear_queue();
		law_event_update_meta( $booking, '_law_is_press', 1 );
		$this->assertQueued( $email, 'booking_meta' );

		$this->clear_queue();
		law_event_update_meta( $booking, '_law_attendee_profile', array( 'dietary' => array( 'Coeliac' ) ) );
		$this->assertQueued( $email, 'booking_meta', 'Answers typed for someone else feed the badge too.' );
	}

	public function test_the_last_speaker_row_leaving_a_post_queues_them(): void {
		$email   = 'speaker-last-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$speaker = law_speaker_upsert( array( 'name' => 'Last Speaker ' . wp_generate_password( 4, false ), 'email' => $email ) );
		$event   = $this->make_event( array(), 'law-approved' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker ) ) );
		$this->clear_queue();

		law_event_update_meta( $event, '_law_speakers', array() ); // the sanitiser deletes the key on an empty array

		$this->assertSame( array(), (array) law_event_meta( $event, '_law_speakers' ) );
		$this->assertQueued( $email, 'speakers_removed' );
	}

	public function test_permanently_deleting_a_speaker_queues_their_address_first(): void {
		$email   = 'speaker-del-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$speaker = law_speaker_upsert( array( 'name' => 'Deleted Speaker ' . wp_generate_password( 4, false ), 'email' => $email ) );
		$this->clear_queue();

		wp_delete_post( $speaker, true );

		// Queued at before_delete_post ('speaker_deleted'); the meta purge then
		// fires deleted_post_meta for the address with its old value, which
		// re-queues the same row as 'speaker_email'. Either way the address is
		// in the queue, which is what matters.
		$this->assertQueued( $email, '', 'Read before the email meta went with the post.' );
	}

	/**
	 * Trashing a speaker record (speakers.php, 21 October 2026) detaches them
	 * from every event. The trash is a wp_update_post, so save_post queues the
	 * trashed speaker; the detach rewrites the rows, so the speakers who REMAIN
	 * are queued by the meta hook. Nobody is left out.
	 */
	public function test_trashing_a_speaker_queues_them_and_the_speakers_left_behind(): void {
		$gone_email = 'speaker-gone-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$stay_email = 'speaker-stay-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$gone       = law_speaker_upsert( array( 'name' => 'Gone Speaker', 'email' => $gone_email ) );
		$stay       = law_speaker_upsert( array( 'name' => 'Stay Speaker', 'email' => $stay_email ) );
		$event      = $this->make_event( array(), 'law-approved' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $gone ), array( 'speaker_id' => $stay ) ) );
		$this->clear_queue();

		wp_trash_post( $gone );

		$this->assertSame( array( $stay ), array_map( fn( $row ) => (int) $row['speaker_id'], law_event_meta( $event, '_law_speakers' ) ), 'The detach ran.' );
		$this->assertQueued( $gone_email, 'speaker_save', 'The trash is a post update, so the trashed speaker is re-evaluated.' );
		$this->assertQueued( $stay_email, 'speakers_changed' );
	}

	/**
	 * Merging two speaker records (migration/merge-speakers.php, 26 October
	 * 2026). The kept record's email was blank, so it takes the merged
	 * record's address and HubSpot ID; both addresses are queued through
	 * law_speakers_merged, and the rows re-pointed at the kept record queue it
	 * through the meta hook as well.
	 */
	public function test_merging_speakers_queues_both_and_carries_the_identity_to_the_kept_record(): void {
		$email  = 'speaker-dupe-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$suffix = strtolower( wp_generate_password( 6, false ) );
		$main   = law_speaker_upsert( array( 'name' => 'Kept Speaker ' . $suffix ) );
		$merged = law_speaker_upsert( array( 'name' => 'Kpet Speaker ' . $suffix, 'email' => $email ) );
		$this->assertNotSame( $main, $merged, 'Different names and one address: the dedupe keeps them apart.' );
		$this->posts[] = $main;
		$this->posts[] = $merged;
		update_post_meta( $merged, law_hubspot_setting( 'id_meta_key', 'law_hubspot_id' ), '424242' );
		$event = $this->make_event( array(), 'law-approved' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $merged, 'role' => 'moderator' ) ) );
		$this->clear_queue();

		$result = law_events_merge_speakers_apply( $main, $merged );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( 'trash', get_post_status( $merged ) );
		$this->assertSame( $email, (string) law_event_meta( $main, '_law_speaker_email' ), 'A blank email on the kept record takes the merged one.' );
		$this->assertSame( '424242', (string) get_post_meta( $main, law_hubspot_setting( 'id_meta_key', 'law_hubspot_id' ), true ), 'And the HubSpot contact ID follows it.' );
		$this->assertSame( $main, law_hubspot_speaker_for_email( $email ), 'The address now resolves to the kept record.' );
		$this->assertQueued( $email, 'speaker_merged' );
	}

	public function test_merging_speakers_with_different_emails_queues_both_and_keeps_the_main_email(): void {
		$main_email   = 'speaker-main-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$merged_email = 'speaker-dupe-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$suffix       = strtolower( wp_generate_password( 6, false ) );
		$main         = law_speaker_upsert( array( 'name' => 'Kept Speaker ' . $suffix, 'email' => $main_email ) );
		$merged       = law_speaker_upsert( array( 'name' => 'Kpet Speaker ' . $suffix, 'email' => $merged_email ) );
		$this->posts[] = $main;
		$this->posts[] = $merged;
		update_post_meta( $merged, law_hubspot_setting( 'id_meta_key', 'law_hubspot_id' ), '424242' );
		$this->clear_queue();

		law_events_merge_speakers_apply( $main, $merged );

		$this->assertSame( $main_email, (string) law_event_meta( $main, '_law_speaker_email' ), 'The kept record\'s own address is never overwritten.' );
		$this->assertSame( '', (string) get_post_meta( $main, law_hubspot_setting( 'id_meta_key', 'law_hubspot_id' ), true ), 'A different address means a different HubSpot contact, so the ID stays behind.' );
		$this->assertQueued( $main_email, 'speaker_merged' );
		$this->assertQueued( $merged_email, 'speaker_merged', 'The worker will find no live speaker behind it and record the skip.' );
	}

	/* ---- bookings for someone else ---------------------------------------- */

	/**
	 * A place booked for someone else (BOOK_ON_BEHALF.md, 26 October 2026):
	 * the attendee is the booking's author, the booker is _law_booked_by. The
	 * account step is law_booking_resolve_attendee_user(), the write is
	 * law_booking_insert() with the booker; both are the seams the flagship
	 * and reception handlers go through, minus Stripe.
	 */
	public function test_booking_for_a_new_person_queues_the_new_account_not_the_booker(): void {
		$booker = $this->make_user();
		$event  = $this->make_event( array( '_law_tickets_available' => 5 ), 'publish' );
		$email  = 'jane.partner.' . strtolower( wp_generate_password( 8, false ) ) . '@example.test';
		$this->clear_queue();

		$resolved = law_booking_resolve_attendee_user(
			array( 'name' => 'Jane Partner', 'email' => $email, 'organisation' => 'Partner LLP', 'job_title' => 'Partner' ),
			$event,
			$booker
		);
		$this->assertIsArray( $resolved, is_wp_error( $resolved ) ? $resolved->get_error_message() : '' );
		$this->assertTrue( $resolved['created'] );
		$this->users[] = (int) $resolved['user_id'];
		$this->assertQueued( $email, '', 'Creating the account goes through wp_insert_user, so user_register fires.' );

		$this->clear_queue();
		$booking = law_booking_insert( $event, (int) $resolved['user_id'], 'law-applied', law_booking_person_from_profile( (int) $resolved['user_id'] ), array(), $booker );
		$this->assertIsInt( $booking );
		$this->posts[] = $booking;
		$this->assertSame( $booker, (int) law_event_meta( $booking, '_law_booked_by' ) );
		law_booking_set_status( $booking, 'publish' );

		$this->assertQueued( $email, 'booking_publish', 'The attendee holds the place.' );
		$this->assertNotQueued( $this->email_of( $booker ) );
	}

	public function test_booking_for_an_existing_account_queues_the_attendee_not_the_booker(): void {
		$booker   = $this->make_user();
		$existing = $this->make_user();
		$email    = $this->email_of( $existing );
		$event    = $this->make_event( array( '_law_tickets_available' => 5 ), 'publish' );
		$this->clear_queue();

		$resolved = law_booking_resolve_attendee_user(
			array( 'name' => 'Existing Person', 'email' => $email, 'organisation' => 'Elsewhere LLP', 'job_title' => 'Partner' ),
			$event,
			$booker
		);
		$this->assertSame( array( 'user_id' => $existing, 'created' => false ), $resolved );
		$this->assertNotQueued( $email, 'Matching an existing account writes nothing to it (A3), so nothing to sync yet.' );

		$booking = law_booking_insert( $event, $existing, 'publish', law_booking_person_from_profile( $existing ), array(), $booker );
		$this->assertIsInt( $booking );
		$this->posts[] = $booking;

		$this->assertQueued( $email, 'booking_publish' );
		$this->assertNotQueued( $this->email_of( $booker ) );
	}
}
