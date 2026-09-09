<?php
/**
 * Speaker names in two parts (Denis, 9 September 2026): the host form, the
 * committee's Manage Speakers screen and the migration all collect a first and
 * a last name separately. The post title stays the display name every listing
 * prints; the parts live beside it on the speaker post.
 *
 * The fallback matters as much as the split: every record created before the
 * change has no stored parts, and reading one must still give a sensible
 * first / last rather than an empty name.
 */
class SpeakerNamesTest extends LAW_Test_Case {

	public function test_split_keeps_honorific_suffixes_on_the_last_name(): void {
		$this->assertSame( array( 'first' => 'Ali', 'last' => 'Malek KC' ), law_speaker_split_name( 'Ali Malek KC' ) );
		$this->assertSame( array( 'first' => 'Mary Jane', 'last' => 'Watson' ), law_speaker_split_name( '  Mary   Jane Watson ' ), 'Middle names stay with the first name and runs of whitespace collapse.' );
		$this->assertSame( array( 'first' => 'Cher', 'last' => '' ), law_speaker_split_name( 'Cher' ), 'A single word is all first name.' );
		$this->assertSame( array( 'first' => '', 'last' => '' ), law_speaker_split_name( '   ' ) );
		$this->assertSame( 'Ali Malek KC', law_speaker_full_name( ' Ali ', ' Malek  KC ' ) );
	}

	public function test_upsert_stores_the_parts_and_titles_the_post_from_them(): void {
		$speaker       = law_speaker_upsert( array( 'first_name' => 'Grace', 'last_name' => 'Testhopper', 'email' => 'grace-parts@example.test' ) );
		$this->posts[] = $speaker;

		$this->assertSame( 'Grace Testhopper', get_the_title( $speaker ), 'The display name is the two parts joined.' );
		$this->assertSame( array( 'first' => 'Grace', 'last' => 'Testhopper' ), law_speaker_name_parts( $speaker ) );

		// A caller that still passes one 'name' (the legacy List field 48 path in
		// the migration) is split rather than dropped.
		$legacy        = law_speaker_upsert( array( 'name' => 'Ada Testlovelace KC' ) );
		$this->posts[] = $legacy;
		$this->assertSame( array( 'first' => 'Ada', 'last' => 'Testlovelace KC' ), law_speaker_name_parts( $legacy ) );
	}

	public function test_a_record_saved_before_the_split_falls_back_to_its_title(): void {
		$speaker = wp_insert_post(
			array(
				'post_type'   => LAW_SPEAKER_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Legacy Testspeaker QC',
			)
		);
		$this->posts[] = $speaker;

		$this->assertSame( array( 'first' => 'Legacy', 'last' => 'Testspeaker QC' ), law_speaker_name_parts( $speaker ), 'No stored parts: the title is split.' );

		$profile = law_speaker_post_profile( $speaker );
		$this->assertSame( 'Legacy', $profile['first_name'] );
		$this->assertSame( 'Testspeaker QC', $profile['last_name'] );
		$this->assertSame( 'Legacy Testspeaker QC', $profile['name'], 'The display name is still the post title.' );

		// The upsert gap-fills the parts on the next submission, and never
		// renames a shared record.
		law_speaker_upsert( array( 'first_name' => 'Legacy', 'last_name' => 'Testspeaker' ) );
		$this->assertSame( 'Legacy Testspeaker QC', get_the_title( $speaker ), 'The existing title is left alone.' );
	}

	public function test_host_form_save_takes_the_two_inputs_and_sessions_still_match_by_name(): void {
		$event = $this->make_event();

		law_events_form_save_speakers(
			$event,
			array(
				array( 'first_name' => 'Rosalind', 'last_name' => 'Testfranklin', 'email' => 'rosalind-form@example.test', 'organisation' => 'Firm E', 'job_title' => 'Partner' ),
			),
			array()
		);
		$speaker       = law_speaker_find_existing( 'rosalind-form@example.test', '' );
		$this->posts[] = $speaker;

		$this->assertNotSame( 0, $speaker, 'The row created a speaker post.' );
		$this->assertSame( 'Rosalind Testfranklin', get_the_title( $speaker ) );
		$this->assertSame( array( 'first' => 'Rosalind', 'last' => 'Testfranklin' ), law_speaker_name_parts( $speaker ) );

		// The edit form is prefilled from the parts, not from a re-split title.
		$values = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) );
		$this->assertSame( 'Rosalind', $values['speakers'][0]['first_name'] );
		$this->assertSame( 'Testfranklin', $values['speakers'][0]['last_name'] );
		$this->assertSame( 'Rosalind Testfranklin', $values['speakers'][0]['name'], 'The joined name is still exposed for the previews and the session picker.' );

		// The session picker posts full names, which still resolve to the event's
		// speaker rows.
		law_events_form_save_sessions(
			$event,
			array(
				array( 'title' => 'Keynote', 'start' => '09:00', 'end' => '10:00', 'description' => '', 'speakers' => array( 'Rosalind Testfranklin' ) ),
			)
		);
		$session_ids = law_event_session_ids( $event );
		$this->posts = array_merge( $this->posts, $session_ids );
		$this->assertCount( 1, $session_ids );
		$this->assertSame( $speaker, (int) law_event_meta( $session_ids[0], '_law_speakers' )[0]['speaker_id'] );
	}

	/**
	 * Editing a speaker already on the event corrects the record itself
	 * (Denis, 9 September 2026): the form shows first name, last name, email and
	 * website as editable fields, so a change to one must actually land. Before
	 * this the upsert only ever filled gaps, and the edit was silently dropped.
	 */
	public function test_editing_a_speaker_already_on_the_event_rewrites_the_record(): void {
		$event = $this->make_event();

		law_events_form_save_speakers(
			$event,
			array(
				array( 'first_name' => 'Barbara', 'last_name' => 'Mclintok', 'email' => 'barbara-edit@example.test', 'website' => 'https://old.example.test', 'organisation' => 'Firm G', 'job_title' => 'Partner' ),
			),
			array()
		);
		$speaker       = law_speaker_find_existing( 'barbara-edit@example.test', '' );
		$this->posts[] = $speaker;

		// The form round-trips the record's ID so the re-save edits it rather
		// than matching a new one on the very fields being corrected.
		$values = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) );
		$this->assertSame( $speaker, (int) $values['speakers'][0]['speaker_id'] );

		$row               = $values['speakers'][0];
		$row['last_name']  = 'Mcclintock';
		$row['website']    = 'https://new.example.test';
		law_events_form_save_speakers( $event, array( $row ), array() );

		$this->assertSame( $speaker, (int) law_event_meta( $event, '_law_speakers' )[0]['speaker_id'], 'Still the same record: a correction is not a new speaker.' );
		$this->assertSame( 'Barbara Mcclintock', get_the_title( $speaker ) );
		$this->assertSame( array( 'first' => 'Barbara', 'last' => 'Mcclintock' ), law_speaker_name_parts( $speaker ) );
		$this->assertSame( 'https://new.example.test', (string) law_event_meta( $speaker, '_law_website' ), 'The website is replaced, not gap-filled.' );

		// The profile is shared across events, so the change is on the record.
		$log = implode( "\n", wp_list_pluck( law_event_log_entries( $event ), 'comment_content' ) );
		$this->assertStringContainsString( 'Speaker profile updated from this submission', $log );
		$this->assertStringContainsString( 'Mclintok', $log, 'The log keeps the old value so a bad edit can be put back.' );
	}

	public function test_a_posted_speaker_id_the_event_does_not_hold_is_ignored(): void {
		$other         = law_speaker_upsert( array( 'first_name' => 'Somebody', 'last_name' => 'Testelse', 'email' => 'somebody-else@example.test' ) );
		$this->posts[] = $other;

		$event = $this->make_event();
		law_events_form_save_speakers(
			$event,
			array(
				array( 'speaker_id' => $other, 'first_name' => 'Forged', 'last_name' => 'Testname', 'email' => 'forged@example.test', 'organisation' => 'Firm H', 'job_title' => 'Partner' ),
			),
			array()
		);

		$this->assertSame( 'Somebody Testelse', get_the_title( $other ), 'A forged ID cannot reach a speaker this event does not hold.' );
		$created       = law_speaker_find_existing( 'forged@example.test', '' );
		$this->posts[] = $created;
		$this->assertNotSame( $other, $created, 'The row matched or created its own record instead.' );
	}

	/**
	 * An apostrophe in a name must survive being prefilled into a form field and
	 * saved back. law_speaker_name_parts() used to split get_the_title(), which
	 * runs wptexturize, so "O'Donnell" was offered to every editing screen as
	 * "O&#8217;Donnell" and written straight back into post_title on the next
	 * save, compounding on each one.
	 */
	public function test_an_apostrophe_in_a_name_survives_the_form_round_trip(): void {
		$speaker = wp_insert_post(
			array(
				'post_type'   => LAW_SPEAKER_CPT,
				'post_status' => 'publish',
				'post_title'  => "Crystal O'Testdonnell",
			)
		);
		$this->posts[] = $speaker;

		// No stored parts, so this is the fallback split every editing screen
		// prefills from: the wp-admin speaker box, Manage Speakers and the event
		// form all call it.
		$parts = law_speaker_name_parts( $speaker );
		$this->assertSame( 'Crystal', $parts['first'] );
		$this->assertSame( "O'Testdonnell", $parts['last'], 'The raw apostrophe, not &#8217;.' );
		$this->assertSame( "Crystal O'Testdonnell", law_speaker_full_name( $parts['first'], $parts['last'] ) );

		// Saving those prefilled values back must not change the stored name.
		$event = $this->make_event();
		law_events_form_save_speakers(
			$event,
			array(
				array( 'speaker_id' => $speaker, 'first_name' => $parts['first'], 'last_name' => $parts['last'], 'email' => 'crystal-apos@example.test', 'organisation' => 'Firm A', 'job_title' => 'Partner' ),
			),
			array()
		);
		$this->assertSame( "Crystal O'Testdonnell", get_post( $speaker )->post_title, 'An untouched re-save leaves the name alone.' );

		// And the two spellings still compare equal, so nothing that matches on a
		// name silently stops matching.
		$this->assertSame(
			law_speaker_normalise_name( "Crystal O'Testdonnell" ),
			law_speaker_normalise_name( 'Crystal O&#8217;Testdonnell' ),
			'Entity and character spellings normalise to the same key.'
		);
	}

	/** Renaming a speaker must not cost the sessions they are on. */
	public function test_a_session_keeps_its_speaker_when_that_speaker_is_renamed(): void {
		$event = $this->make_event();
		law_events_form_save_speakers(
			$event,
			array(
				array( 'first_name' => 'Rename', 'last_name' => 'Testbefore', 'email' => 'rename-session@example.test', 'organisation' => 'Firm B', 'job_title' => 'Partner' ),
			),
			array()
		);
		$speaker       = law_speaker_find_existing( 'rename-session@example.test', '' );
		$this->posts[] = $speaker;

		law_events_form_save_sessions(
			$event,
			array( array( 'title' => 'Keynote', 'start' => '09:00', 'end' => '10:00', 'description' => 'A talk.', 'speakers' => array( 'row:0' ) ) ),
			array( 0 => $speaker )
		);
		$session_ids = law_event_session_ids( $event );
		$this->posts = array_merge( $this->posts, $session_ids );
		$this->assertSame( $speaker, (int) law_event_meta( $session_ids[0], '_law_speakers' )[0]['speaker_id'] );

		// Now rename the speaker and re-save both halves the way the form does.
		$speaker_ids = law_events_form_save_speakers(
			$event,
			array(
				array( 'speaker_id' => $speaker, 'first_name' => 'Rename', 'last_name' => 'Testafter', 'email' => 'rename-session@example.test', 'organisation' => 'Firm B', 'job_title' => 'Partner' ),
			),
			array()
		);
		law_events_form_save_sessions(
			$event,
			array( array( 'id' => $session_ids[0], 'title' => 'Keynote', 'start' => '09:00', 'end' => '10:00', 'description' => 'A talk.', 'speakers' => array( 'row:0' ) ) ),
			$speaker_ids
		);

		$this->assertSame( 'Rename Testafter', get_post( $speaker )->post_title, 'The rename landed.' );
		$this->assertSame(
			$speaker,
			(int) law_event_meta( $session_ids[0], '_law_speakers' )[0]['speaker_id'],
			'The session still links the renamed speaker: the tick travels as a row key, not a name.'
		);
	}

	/**
	 * Two speakers on one event can share a display name. Matching by name could
	 * only ever reach the first of them, so the second could never be put on a
	 * session of its own.
	 */
	public function test_two_speakers_with_the_same_name_link_to_different_sessions(): void {
		$event       = $this->make_event();
		$speaker_ids = law_events_form_save_speakers(
			$event,
			array(
				array( 'first_name' => 'Sam', 'last_name' => 'Testtwin', 'email' => 'twin-one@example.test', 'organisation' => 'Firm C', 'job_title' => 'Partner' ),
				array( 'first_name' => 'Sam', 'last_name' => 'Testtwin', 'email' => 'twin-two@example.test', 'organisation' => 'Firm D', 'job_title' => 'Associate' ),
			),
			array()
		);
		$this->posts = array_merge( $this->posts, array_values( $speaker_ids ) );

		$this->assertCount( 2, $speaker_ids, 'Two records: the email is the dedupe key, not the name.' );
		$this->assertNotSame( $speaker_ids[0], $speaker_ids[1] );

		law_events_form_save_sessions(
			$event,
			array(
				array( 'title' => 'Morning', 'start' => '09:00', 'end' => '10:00', 'description' => 'First.', 'speakers' => array( 'row:0' ) ),
				array( 'title' => 'Afternoon', 'start' => '14:00', 'end' => '15:00', 'description' => 'Second.', 'speakers' => array( 'row:1' ) ),
			),
			$speaker_ids
		);
		$session_ids = law_event_session_ids( $event );
		$this->posts = array_merge( $this->posts, $session_ids );

		$this->assertSame( $speaker_ids[0], (int) law_event_meta( $session_ids[0], '_law_speakers' )[0]['speaker_id'] );
		$this->assertSame( $speaker_ids[1], (int) law_event_meta( $session_ids[1], '_law_speakers' )[0]['speaker_id'], 'The second same-named speaker is reachable.' );
	}

	/**
	 * A session can hold a speaker the parent event does not, because the
	 * wp-admin session screen attaches anyone in the site. The host's picker
	 * cannot offer that person, so a host save must leave them alone — wiping
	 * what the host was never shown is data loss, not a save. A speaker the host
	 * genuinely removed from the event still leaves the sessions.
	 */
	public function test_a_host_save_keeps_session_speakers_it_never_offered(): void {
		$event       = $this->make_event();
		$speaker_ids = law_events_form_save_speakers(
			$event,
			array(
				array( 'first_name' => 'Onform', 'last_name' => 'Testspeaker', 'email' => 'onform@example.test', 'organisation' => 'Firm I', 'job_title' => 'Partner' ),
				array( 'first_name' => 'Dropped', 'last_name' => 'Testspeaker', 'email' => 'dropped@example.test', 'organisation' => 'Firm J', 'job_title' => 'Partner' ),
			),
			array()
		);
		$this->posts = array_merge( $this->posts, array_values( $speaker_ids ) );

		// Somebody only wp-admin knows about, put straight onto the session.
		$admin_only    = law_speaker_upsert( array( 'first_name' => 'Adminonly', 'last_name' => 'Testspeaker', 'email' => 'admin-only@example.test' ) );
		$this->posts[] = $admin_only;

		law_events_form_save_sessions(
			$event,
			array( array( 'title' => 'Panel', 'start' => '09:00', 'end' => '10:00', 'description' => 'A panel.', 'speakers' => array( 'row:0', 'row:1' ) ) ),
			$speaker_ids
		);
		$session       = law_event_session_ids( $event )[0];
		$this->posts[] = $session;

		$rows   = law_event_meta( $session, '_law_speakers' );
		$rows[] = array( 'speaker_id' => $admin_only, 'role' => 'host', 'organisation' => 'Firm K', 'job_title' => 'Chair', 'sort' => 2 );
		law_event_update_meta( $session, '_law_speakers', $rows );
		$this->assertCount( 3, law_event_meta( $session, '_law_speakers' ) );

		// The host now re-saves with the second speaker deleted from the event.
		$before      = array_map( fn( $r ) => (int) $r['speaker_id'], law_event_meta( $event, '_law_speakers' ) );
		$speaker_ids = law_events_form_save_speakers(
			$event,
			array(
				array( 'speaker_id' => $speaker_ids[0], 'first_name' => 'Onform', 'last_name' => 'Testspeaker', 'email' => 'onform@example.test', 'organisation' => 'Firm I', 'job_title' => 'Partner' ),
			),
			array()
		);
		law_events_form_save_sessions(
			$event,
			array( array( 'id' => $session, 'title' => 'Panel', 'start' => '09:00', 'end' => '10:00', 'description' => 'A panel.', 'speakers' => array( 'row:0' ) ) ),
			$speaker_ids,
			$before
		);

		$linked = array_map( fn( $r ) => (int) $r['speaker_id'], law_event_meta( $session, '_law_speakers' ) );
		$this->assertContains( $speaker_ids[0], $linked, 'The speaker the host kept is still linked.' );
		$this->assertContains( $admin_only, $linked, 'The wp-admin-only speaker survives a host save.' );
		$this->assertNotContains( $before[1], $linked, 'A speaker the host removed from the event leaves the sessions too.' );
		$this->assertSame( 'host', law_event_meta( $session, '_law_speakers' )[1]['role'], 'The carried-over row keeps its own appearance details.' );
	}

	/** Dropping a speaker from an event must not delete them or touch their other events. */
	public function test_removing_a_speaker_from_one_event_leaves_the_record_and_its_other_events(): void {
		$shared        = law_speaker_upsert( array( 'first_name' => 'Shared', 'last_name' => 'Testspeaker', 'email' => 'shared-two-events@example.test' ) );
		$this->posts[] = $shared;

		$event_a = $this->make_event();
		$event_b = $this->make_event();
		foreach ( array( $event_a => 'Firm A', $event_b => 'Firm B' ) as $event => $firm ) {
			law_events_form_save_speakers(
				$event,
				array( array( 'speaker_id' => $shared, 'first_name' => 'Shared', 'last_name' => 'Testspeaker', 'email' => 'shared-two-events@example.test', 'organisation' => $firm, 'job_title' => 'Partner' ) ),
				array()
			);
		}
		$this->assertSame( 'Firm A', law_event_meta( $event_a, '_law_speakers' )[0]['organisation'] );
		$this->assertSame( 'Firm B', law_event_meta( $event_b, '_law_speakers' )[0]['organisation'], 'The organisation is per appearance, so the two events differ.' );

		// Event A drops them entirely.
		law_events_form_save_speakers( $event_a, array(), array() );

		$this->assertSame( array(), law_event_meta( $event_a, '_law_speakers' ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $shared ), 'The speaker post itself survives.' );
		$this->assertSame( $shared, (int) law_event_meta( $event_b, '_law_speakers' )[0]['speaker_id'], "The other event's appearance is untouched." );
		$this->assertSame( 'Firm B', law_event_meta( $event_b, '_law_speakers' )[0]['organisation'] );
	}

	/** Editing a shared speaker from one event must not disturb the other's row. */
	public function test_editing_a_shared_speaker_from_one_event_leaves_the_other_appearance_alone(): void {
		$shared        = law_speaker_upsert( array( 'first_name' => 'Crossevent', 'last_name' => 'Testbefore', 'email' => 'cross-event@example.test' ) );
		$this->posts[] = $shared;

		$event_a = $this->make_event();
		$event_b = $this->make_event();
		foreach ( array( $event_a => 'Firm A', $event_b => 'Firm B' ) as $event => $firm ) {
			law_events_form_save_speakers(
				$event,
				array( array( 'speaker_id' => $shared, 'first_name' => 'Crossevent', 'last_name' => 'Testbefore', 'email' => 'cross-event@example.test', 'organisation' => $firm, 'job_title' => 'Partner' ) ),
				array()
			);
		}

		// Event A renames them and changes ITS organisation.
		law_events_form_save_speakers(
			$event_a,
			array( array( 'speaker_id' => $shared, 'first_name' => 'Crossevent', 'last_name' => 'Testafter', 'email' => 'cross-event@example.test', 'organisation' => 'Firm A2', 'job_title' => 'Partner' ) ),
			array()
		);

		$this->assertSame( 'Crossevent Testafter', get_post( $shared )->post_title, 'Identity is shared, so the rename is global.' );
		$this->assertSame( 'Firm A2', law_event_meta( $event_a, '_law_speakers' )[0]['organisation'] );
		$this->assertSame( 'Firm B', law_event_meta( $event_b, '_law_speakers' )[0]['organisation'], 'The appearance is per event and stays put.' );
	}

	public function test_a_started_speaker_row_must_have_both_names(): void {
		$host = $this->make_user( 'event_host' );
		wp_set_current_user( $host );
		$event = get_post( $this->make_event( array( '_law_fee_tier' => 'uk' ), 'law-proposed', $host ) );

		// A real configured slot label: the save drops anything that is not one,
		// and a Proposed event does not have preferred slots locked.
		$slot_labels = array_keys( law_events_slot_choices( array() ) );

		$input = array(
			'law_form_action'     => 'update',
			'event_title'         => 'Half a speaker',
			'description'         => 'A description.',
			'event_type'          => 'Social event',
			'host_organisations'  => 'Test Org LLP',
			'preferred_slots'     => $slot_labels ? array( $slot_labels[0] ) : array( 'Any slot' ),
			'sectors'             => array(),
			'venue_needed'        => 'Yes, please share our details with venue hosts',
			'fee_tier'            => 'uk',
			'invoice_name'        => 'Test Contact',
			'invoice_email'       => 'half-invoice@example.test',
			'invoice_line1'       => '1 Test Street',
			'invoice_city'        => 'London',
			'invoice_postal_code' => 'EC1A 1AA',
			'invoice_country'     => 'United Kingdom',
			'speakers'            => array(
				array( 'first_name' => 'Onlyfirst', 'last_name' => '', 'email' => 'half@example.test', 'organisation' => 'Firm F', 'job_title' => 'Partner' ),
			),
		);

		$result = law_events_form_save( $input, array(), $event, $host );
		$this->assertInstanceOf( WP_Error::class, $result, 'A started speaker row missing its last name fails validation.' );
		$this->assertStringContainsString( 'a last name', $result->get_error_message( 'speakers' ) );

		// Both names given: the row saves.
		$input['speakers'][0]['last_name'] = 'Testperson';
		$saved = law_events_form_save( $input, array(), $event, $host );
		$this->assertSame( (int) $event->ID, $saved );
		$speaker       = law_speaker_find_existing( 'half@example.test', '' );
		$this->posts[] = $speaker;
		$this->assertSame( 'Onlyfirst Testperson', get_the_title( $speaker ) );
	}
}
