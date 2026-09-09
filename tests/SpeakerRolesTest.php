<?php
/**
 * The per-appearance speaker role (Speaker / Host / Moderator): vocabulary,
 * the canonical-key sanitiser, the two writers (host form and session copy),
 * the read helpers and the migration's session copy.
 */
class SpeakerRolesTest extends LAW_Test_Case {

	public function test_vocabulary_normalises_keys_and_labels(): void {
		$this->assertSame( array( 'speaker', 'host', 'moderator' ), array_keys( law_speaker_roles() ), 'The order the selects offer, Speaker first as the default.' );

		$this->assertSame( 'moderator', law_speaker_role_key( 'Moderator' ), 'The label (what form 8 field 9 stores) maps to the key.' );
		$this->assertSame( 'moderator', law_speaker_role_key( 'MODERATOR' ) );
		$this->assertSame( 'moderator', law_speaker_role_key( ' moderator ' ) );
		$this->assertSame( 'host', law_speaker_role_key( 'Host' ) );
		$this->assertSame( 'speaker', law_speaker_role_key( 'speaker' ) );
		$this->assertSame( '', law_speaker_role_key( 'chair' ), 'Anything outside the vocabulary is dropped.' );
		$this->assertSame( '', law_speaker_role_key( '' ) );
		$this->assertSame( '', law_speaker_role_key( array( 'host' ) ), 'A non-scalar never reaches the meta.' );

		$this->assertSame( 'Host', law_speaker_role_label( 'host' ) );
		$this->assertSame( '', law_speaker_role_label( 'chair' ) );

		$this->assertSame( 'Speaker', law_speaker_role_display( '' ), 'An unset role reads as the default and prints on the card.' );
		$this->assertSame( 'Speaker', law_speaker_role_display( 'speaker' ) );
		$this->assertSame( 'Moderator', law_speaker_role_display( 'Moderator' ) );
	}

	public function test_sanitiser_stores_canonical_role_keys(): void {
		$speaker       = law_speaker_upsert( array( 'name' => 'Role Sanitiser Testspeaker' ) );
		$this->posts[] = $speaker;
		$event         = $this->make_event();

		law_event_update_meta(
			$event,
			'_law_speakers',
			array(
				array( 'speaker_id' => $speaker, 'role' => 'MODERATOR' ),
				array( 'speaker_id' => $speaker, 'role' => 'chair' ),
				array( 'speaker_id' => $speaker ),
			)
		);
		$rows = law_event_meta( $event, '_law_speakers' );
		$this->assertSame( array( 'speaker_id', 'role', 'organisation', 'job_title', 'photo_id', 'bio', 'sort' ), array_keys( $rows[0] ), 'The row shape is unchanged.' );
		$this->assertSame( 'moderator', $rows[0]['role'] );
		$this->assertSame( '', $rows[1]['role'], 'An unknown value stores as unset, not as free text.' );
		$this->assertSame( '', $rows[2]['role'], 'A row without the key stores an empty string.' );
	}

	public function test_host_form_save_keeps_the_posted_role_and_sessions_inherit_it(): void {
		$event = $this->make_event();

		law_events_form_save_speakers(
			$event,
			array(
				array( 'first_name' => 'Role Form', 'last_name' => 'Testspeaker', 'email' => 'role-form-test@example.test', 'organisation' => 'Firm C', 'job_title' => 'Partner', 'role' => 'Host' ),
				array( 'first_name' => 'Role Form Second', 'last_name' => 'Testspeaker', 'email' => 'role-form-test-2@example.test', 'organisation' => 'Firm D', 'job_title' => 'Counsel' ),
			),
			array()
		);
		$host          = law_speaker_find_existing( 'role-form-test@example.test', '' );
		$second        = law_speaker_find_existing( 'role-form-test-2@example.test', '' );
		$this->posts[] = $host;
		$this->posts[] = $second;

		$rows = law_event_meta( $event, '_law_speakers' );
		$this->assertSame( 'host', $rows[0]['role'], 'The posted label is stored as the key.' );
		$this->assertSame( '', $rows[1]['role'], 'No selection posted: unset, which reads as Speaker.' );

		// A re-save of the same rows keeps the role: this is the path that used
		// to blank a role set in wp-admin on every host edit.
		law_event_update_meta( $event, '_law_speakers', array( $rows[0], array_merge( $rows[1], array( 'role' => 'moderator' ) ) ) );
		$values = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) );
		$this->assertSame( 'host', $values['speakers'][0]['role'], 'The edit form is prefilled with the stored role.' );
		$this->assertSame( 'moderator', $values['speakers'][1]['role'] );

		// Sessions copy the event row's role (the host form has no per-session control).
		law_events_form_save_sessions(
			$event,
			array(
				array( 'title' => 'Panel', 'start' => '09:00', 'end' => '10:00', 'description' => '', 'speakers' => array( 'Role Form Testspeaker', 'Role Form Second Testspeaker' ) ),
			)
		);
		$session_ids = law_event_session_ids( $event );
		$this->posts = array_merge( $this->posts, $session_ids );
		$this->assertCount( 1, $session_ids );
		$session_rows = law_event_meta( $session_ids[0], '_law_speakers' );
		$by_speaker   = array_column( $session_rows, 'role', 'speaker_id' );
		$this->assertSame( 'host', $by_speaker[ $host ] );
		$this->assertSame( 'moderator', $by_speaker[ $second ] );
	}

	public function test_cards_and_appearances_expose_the_role_with_session_fall_through(): void {
		$speaker       = law_speaker_upsert( array( 'name' => 'Role Card Testspeaker', 'email' => 'role-card-test@example.test' ) );
		$this->posts[] = $speaker;
		$event         = $this->make_event( array(), 'publish' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker, 'role' => 'moderator', 'organisation' => 'Firm E', 'job_title' => 'Partner' ) ) );

		// Session A leaves the role blank (inherits); session B sets its own.
		$session_a = wp_insert_post( array( 'post_type' => LAW_SESSION_CPT, 'post_status' => 'publish', 'post_parent' => $event, 'post_title' => 'Session A' ) );
		$session_b = wp_insert_post( array( 'post_type' => LAW_SESSION_CPT, 'post_status' => 'publish', 'post_parent' => $event, 'post_title' => 'Session B' ) );
		$this->posts[] = $session_a;
		$this->posts[] = $session_b;
		law_event_update_meta( $session_a, '_law_speakers', array( array( 'speaker_id' => $speaker ) ) );
		law_event_update_meta( $session_b, '_law_speakers', array( array( 'speaker_id' => $speaker, 'role' => 'host' ) ) );
		law_event_update_meta( $session_a, '_law_start_time', '09:00' );
		law_event_update_meta( $session_b, '_law_start_time', '11:00' );

		$this->assertSame( 'moderator', law_event_speaker_cards( $event )[0]['role'] );
		$sessions = law_event_session_rows( $event );
		$this->assertSame( 'moderator', $sessions[0]['speakers'][0]['role'], "A blank session row inherits the event's role." );
		$this->assertSame( 'host', $sessions[1]['speakers'][0]['role'], 'A session row with its own role keeps it.' );

		$this->assertSame( 'moderator', law_speaker_appearance_for_event( $speaker, $event )['role'], 'The event row wins over the sessions for the profile card.' );
		law_speakers_confirmed_maps( true );
		$this->assertSame( 'moderator', law_speaker_appearances( $speaker )[0]['role'] );
		$this->assertArrayNotHasKey( 'role', law_speaker_first_appearance( $speaker ), 'The archive headline never carries a role: it is per event.' );
	}

	public function test_migration_maps_field_9_by_label_and_sessions_copy_the_event_row(): void {
		// Step 3's mapper is law_speaker_role_key( rgar( $child, '9' ) ): the
		// live drop down stores the label, a local database has no field 9 at all.
		$this->assertSame( 'moderator', law_speaker_role_key( rgar( array( '9' => 'Moderator' ), '9' ) ) );
		$this->assertSame( '', law_speaker_role_key( rgar( array( '9' => 'Chair' ), '9' ) ) );
		$this->assertSame( '', law_speaker_role_key( rgar( array( '3' => 'Firm only' ), '9' ) ), 'Field absent: unset, never an error.' );

		// Step 4: a session row copies the parent event row, role included.
		$speaker       = law_speaker_upsert( array( 'name' => 'Role Migration Testspeaker' ) );
		$this->posts[] = $speaker;
		$event         = $this->make_event();
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker, 'role' => 'host', 'organisation' => 'Firm F' ) ) );
		$rows = law_migration_session_speaker_rows( array( '6' => '[4242]' ), $event, array( 'speakers' => array( 4242 => $speaker ) ) );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'host', $rows[0]['role'] );
		$this->assertSame( 'Firm F', $rows[0]['organisation'] );
	}
}
