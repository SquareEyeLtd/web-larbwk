<?php
/**
 * Speaker identity on the event form (Denis, 19 November 2026).
 *
 * A host replacing a speaker by typing the new person over an existing row used
 * to RENAME the shared law_speaker record, so the new name showed on every other
 * event the old person appeared at (Domitille Baizeau became Noradèle Radjai on
 * two events; Campbell Herbert became Kathleen Donnelly KC). Now a different
 * person is a replacement: the old record is left alone and the row is matched
 * or created like a new one. An email alone no longer identifies a person, so a
 * clerks' inbox can serve several speakers without merging them.
 */
class SpeakerIdentityTest extends LAW_Test_Case {

	/** Corrections hosts made on the prod event form, 18 September to 19 November 2026. */
	public static function corrections(): array {
		return array(
			array( 'David Parratt KC', 'David Parratt KC (Scot)' ),
			array( 'NiaLL McCulloch KC', 'Niall McCulloch KC' ),
			array( 'Hussein.Haeri Haeri KC', 'Hussein Haeri KC' ),
			array( 'Eric Leikin Leikin', 'Eric Leikin' ),
			array( 'Dame Elizabeth Gloster', 'Lord Gloster' ),
			array( 'Lord Gloster', 'Dame Elizabeth Gloster' ),
			array( 'Kaixin DENG', 'Kaixin Deng' ),
			array( 'Zhou CHEN', 'Zhou Chen' ),
			array( 'Bin WANG', 'Bin Wang' ),
			array( 'Siqi YAN', 'Siqi Yan' ),
			array( 'Jingzhou Tao', 'Jingzhou TAO' ),
			array( 'Roberto Azevêdo', 'Roberto CARVALHO DE AZEVEDO' ),
			array( 'Kaixin Deng', 'Kaixin DENG' ),
			array( 'Zhou Chen', 'Zhou CHEN' ),
			array( 'Bin Wang', 'Bin WANG' ),
			array( 'Siqi Yan', 'Siqi YAN' ),
			array( 'Victor Steinmetz', 'Victor STEINMETZ' ),
			array( 'Lifan Zhou', 'Lifan ZHOU' ),
			array( '• Tunde Ogunseitan Lauvaux', 'Tunde Ogunseitan' ),
			array( 'Toby Landau', 'Toby Landau KC' ),
			array( 'Noradele Radjai', 'Noradèle Radjai' ),
			array( 'Kathleen Donnelly', 'Kathleen Donnelly KC' ),
			array( 'Mr Justice Stanley', 'Justice Stanley' ),
			array( 'Andrew Roberts BUT TBC', 'Andrew Roberts' ),
			array( 'Noami Tarawali', 'Naomi Tarawali' ),
			array( 'Ganesh Kirshna', 'Ganesh Krishna' )
		);
	}

	/** Replacements hosts made the same way in the same period. */
	public static function replacements(): array {
		return array(
			array( 'Sarra Kay', 'Lord Hamblen' ),
			array( 'Lord Hamblen', 'Sarra Kay' ),
			array( 'Prof. Emilia Onyema', 'James Holden' ),
			array( 'Lord Snowden', 'Mr Justice David Waksman' ),
			array( 'Mr Justice David Waksman', 'Prof. Dr. Maxi Scherer' ),
			array( 'Prof. Dr. Maxi Scherer', 'Siobán Healey KC' ),
			array( 'Siobán Healey KC', 'Lord Snowden' ),
			array( 'Lord Snowden', 'Siobán Healey KC' ),
			array( 'Mihaela Apostol', 'Sophie Nappert' ),
			array( 'Andy Moody', 'Ed Poulton' ),
			array( 'Amy Worley', 'Greg Harman' ),
			array( 'TBC', 'Nikki Coles' ),
			array( 'Paul Stothard', 'Joseph Bentley' ),
			array( 'Joseph Bentley', 'Paul Stothard' ),
			array( 'Alexander Brown KC', 'Lord Richards of Camberwell' ),
			array( 'Alexander Brown KC', 'Dame Elizabeth Gloster' ),
			array( 'Dame Elizabeth Gloster', 'Alexander Brown KC' ),
			array( 'Lord Richards of Camberwell', 'Dame Elizabeth Gloster' ),
			array( 'Dame Elizabeth Gloster', 'Lord Richards of Camberwell' ),
			array( 'Jean-François Le Gal', 'Théophile Hiom II' ),
			array( 'Théophile Hiom II', 'Mamadou Koné' ),
			array( 'Mamadou Koné', 'Jean-François Le Gal' ),
			array( 'Peter de Verneuil Smith', 'Victor Steinmetz' ),
			array( 'Mamadou Koné', 'Jean-Francois Le Gal' ),
			array( 'Jean-Francois Le Gal', 'Mamadou Koné' ),
			array( 'Mamadou Koné', 'Théophile Hiom II' ),
			array( 'Théophile Hiom II', 'Jean‑François Le Gal' ),
			array( 'Other speakers to be confirmed shortly', 'Kevin Nash' ),
			array( 'Arthur Lauvaux', '• Tunde Ogunseitan Lauvaux' ),
			array( 'Sherina Petit', 'Daniel Wilmot' ),
			array( 'Law firm counsel (TBC)', 'Daniel Martin' ),
			array( 'Emma Anderson', 'Roger Gherson' ),
			array( 'Lucian Ilie', 'Andrew Spink KC' ),
			array( 'Romilly Holland', 'Lucian Ilie' ),
			array( 'Domitille Baizeau', 'Noradele Radjai' ),
			array( 'Kenny Lau', 'Abraham Vergis S.C.' ),
			array( 'David Samuels', 'Niamh Leinwather' ),
			array( 'Niamh Leinwather', 'David Samuels' ),
			array( 'Dong-Seok (Johan) Oh', 'Shul Park' ),
			array( 'Greg Harman', 'Adrian Forster' ),
			array( 'Geoff Nicholas', 'Chris Bogart' ),
			array( 'Michael Sergeant', 'Tanya Chadha' ),
			array( 'John Judge', 'J. Brian Casey' ),
			array( 'Kevin Nash', 'Simon Dack' ),
			array( 'Simon Dack', 'Kevin Nash' ),
			array( 'Navin Waghe', 'Matt Burder' ),
			array( 'Carlos Ramos-Mrosovsky', 'Ganesh Kirshna' ),
			array( 'Amy Worley', 'David Joseph KC' ),
			array( 'Saadia Bhatty', 'Amadou Mbaye Guissé' ),
			array( 'Amadou Mbaye Guissé', 'Saadia Bhatty' ),
			array( 'Noradèle Radjai', 'Domitille BAIZEAU' ),
			array( 'Campbell Herbert', 'Kathleen Donnelly KC' ),
			array( 'Kathleen Donnelly KC', 'Campbell Herbert' ),
			array( 'Holly Stebbing', 'Amy Armitage' )
		);
	}

	/**
	 * The linking test matches a name written more or less fully, and nothing
	 * else: of the real corrections, the five that fixed a typo or turned on a
	 * lone surname are different names to it (Denis, 19 November 2026, no typo
	 * allowance), every other one is the same person.
	 */
	public function test_linking_matches_fuller_or_shorter_names_but_not_typos(): void {
		$different = array( 'Dame Elizabeth Gloster|Lord Gloster', 'Lord Gloster|Dame Elizabeth Gloster', 'Mr Justice Stanley|Justice Stanley', 'Noami Tarawali|Naomi Tarawali', 'Ganesh Kirshna|Ganesh Krishna' );
		foreach ( self::corrections() as $pair ) {
			$expected = ! in_array( $pair[0] . '|' . $pair[1], $different, true );
			$this->assertSame( $expected, law_speaker_same_person( $pair[0], $pair[1] ), sprintf( '"%s" → "%s"', $pair[0], $pair[1] ) );
		}
	}

	public function test_every_real_replacement_reads_as_a_different_person(): void {
		foreach ( self::replacements() as $pair ) {
			$this->assertFalse( law_speaker_same_person( $pair[0], $pair[1] ), sprintf( '"%s" → "%s" is a replacement.', $pair[0], $pair[1] ) );
		}
	}

	public function test_placeholders_and_thin_names_are_never_matched_loosely(): void {
		$this->assertTrue( law_speaker_is_placeholder_name( 'TBC' ) );
		$this->assertTrue( law_speaker_is_placeholder_name( 'Other speakers to be confirmed shortly' ) );
		$this->assertFalse( law_speaker_is_placeholder_name( 'Andrew Roberts BUT TBC' ), 'A real name with a note on it is still a person.' );
		$this->assertTrue( law_speaker_same_person( 'TBC', 'tbc' ), 'A placeholder is the same as itself.' );
		$this->assertFalse( law_speaker_same_person( 'Kenny', 'Kenny Lau' ), 'A lone first name is too thin to call anyone the same person.' );
		$this->assertFalse( law_speaker_same_person( 'Lord Gloster', 'Dame Elizabeth Gloster' ), 'A lone surname is too thin to call anyone the same person.' );
		$this->assertFalse( law_speaker_same_person( 'Jon Smith', 'John Smith' ), 'Short words must match exactly.' );
	}

	/**
	 * The strict rule (Denis, 19 November 2026): only capitals, accents, spaces
	 * and punctuation may change on the shared record. Of the 26 real
	 * corrections, 13 were of that kind.
	 */
	public function test_only_capitals_accents_spaces_and_punctuation_count_as_the_same_name(): void {
		$cosmetic = array_filter( self::corrections(), fn( $pair ) => law_speaker_same_name( $pair[0], $pair[1] ) );
		$this->assertCount( 13, $cosmetic );
		$this->assertTrue( law_speaker_same_name( 'NiaLL McCulloch KC', 'Niall Mc-Culloch KC' ) );
		$this->assertTrue( law_speaker_same_name( 'Noradele Radjai', 'Noradèle Radjai' ) );
		$this->assertTrue( law_speaker_same_name( 'Jean-François Le Gal', "Jean\u{2011}Francois Le Gal" ) );
		$this->assertFalse( law_speaker_same_name( 'Noami Tarawali', 'Naomi Tarawali' ), 'A fixed typo is a new name.' );
		$this->assertFalse( law_speaker_same_name( 'Toby Landau', 'Toby Landau KC' ), 'So is an added post-nominal.' );
		foreach ( self::replacements() as $pair ) {
			$this->assertFalse( law_speaker_same_name( $pair[0], $pair[1] ) );
		}
	}

	/** The linking test has no typo allowance and keeps initials and titles where they are all that tells people apart. */
	public function test_names_a_letter_apart_or_told_apart_by_initials_are_different_people(): void {
		foreach ( array( array( 'Mark Smith', 'Mary Smith' ), array( 'Jane Smith', 'Janet Smith' ), array( 'Maria Rossi', 'Mario Rossi' ), array( 'Christian Ng', 'Christina Ng' ), array( 'A. Smith', 'B. Smith' ), array( 'Mr Justice Smith', 'Mrs Justice Smith' ), array( 'Анна Иванова', 'Инна Иванова' ) ) as $pair ) {
			$this->assertFalse( law_speaker_same_person( $pair[0], $pair[1] ), implode( ' / ', $pair ) );
		}
		foreach ( array( array( 'Toby Landau', 'Toby Landau KC' ), array( 'Abraham Vergis S.C.', 'Abraham Vergis' ), array( 'J. Brian Casey', 'J Brian Casey' ), array( 'Jane M. Smith', 'Jane Smith' ), array( 'Prof. Dr. Maxi Scherer', 'Maxi Scherer' ) ) as $pair ) {
			$this->assertTrue( law_speaker_same_person( $pair[0], $pair[1] ), implode( ' / ', $pair ) );
		}
	}

	/**
	 * A host fixing a spelling on a speaker shared with another event gives
	 * their event a new profile; the other event keeps the old spelling until the
	 * committee merges the two, and the next host adding the corrected spelling
	 * links to the corrected profile. The photo on this event goes with a typo
	 * fix (no guessing that it is the same face); it stays when the new name only
	 * adds to the old one.
	 */
	public function test_fixing_a_spelling_gives_this_event_a_new_profile_and_keeps_its_photo(): void {
		$event_a = $this->make_event();
		$event_b = $this->make_event();
		$misspelt      = law_speaker_upsert( array( 'first_name' => 'Noami', 'last_name' => 'Testtarawali', 'email' => 'naomi@example.test' ) );
		$this->posts[] = $misspelt;
		law_event_update_meta( $event_a, '_law_speakers', array( array( 'speaker_id' => $misspelt, 'photo_id' => 999997 ) ) );
		law_event_update_meta( $event_b, '_law_speakers', array( array( 'speaker_id' => $misspelt ) ) );

		$fixed         = law_events_form_save_speakers(
			$event_a,
			array( array( 'speaker_id' => $misspelt, 'first_name' => 'Naomi', 'last_name' => 'Testtarawali', 'email' => 'naomi@example.test' ) ),
			array()
		)[0];
		$this->posts[] = $fixed;

		$this->assertNotSame( $misspelt, $fixed, 'The corrected spelling does not link back to the misspelt record.' );
		$this->assertSame( 'Noami Testtarawali', get_post( $misspelt )->post_title, 'The other event keeps the old spelling.' );
		$this->assertSame( $misspelt, (int) law_event_meta( $event_b, '_law_speakers' )[0]['speaker_id'] );
		$this->assertSame( 0, (int) law_event_meta( $event_a, '_law_speakers' )[0]['photo_id'], 'A typo fix is not assumed to be the same face.' );

		$this->assertSame( $fixed, law_speaker_find_existing( 'naomi@example.test', 'Naomi Testtarawali' ), 'The corrected spelling finds the corrected profile.' );
		$this->assertSame( $misspelt, law_speaker_find_existing( 'naomi@example.test', 'Noami Testtarawali' ) );

		// Adding "KC" is a new speaker too, but plainly the same face.
		law_event_update_meta( $event_a, '_law_speakers', array( array( 'speaker_id' => $fixed, 'photo_id' => 999996 ) ) );
		$silk          = law_events_form_save_speakers(
			$event_a,
			array( array( 'speaker_id' => $fixed, 'first_name' => 'Naomi', 'last_name' => 'Testtarawali KC', 'email' => 'naomi@example.test' ) ),
			array()
		)[0];
		$this->posts[] = $silk;
		$this->assertNotSame( $fixed, $silk );
		$this->assertSame( 999996, (int) law_event_meta( $event_a, '_law_speakers' )[0]['photo_id'] );
	}

	/**
	 * Gap 1 of the 19 November review: a row with no usable email (a draft, an
	 * address like "john@firm", the external-event form) used to match whoever
	 * had the same name, and the next save wrote the host's details onto them.
	 */
	public function test_a_row_without_a_usable_email_never_links_to_a_namesake(): void {
		$other_event   = $this->make_event();
		$namesake      = law_events_form_save_speakers( $other_event, array( array( 'first_name' => 'Andrew', 'last_name' => 'Testmackenzie', 'email' => 'chiefexec@example.test', 'website' => 'https://sac.example.test' ) ), array() )[0];
		$this->posts[] = $namesake;

		$event = $this->make_event();
		foreach ( array( '', 'john@firm' ) as $email ) {
			$own           = law_events_form_save_speakers( $event, array( array( 'first_name' => 'Andrew', 'last_name' => 'Testmackenzie', 'email' => $email ) ), array() )[0];
			$this->posts[] = $own;
			$this->assertNotSame( $namesake, $own, sprintf( 'Email "%s": a new speaker, not the namesake.', $email ) );
		}

		// The host's next save adds an address and a website to THEIR speaker.
		$values = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) );
		$row    = $values['speakers'][0];
		$row['email']   = 'andrew@otherfirm.example.test';
		$row['website'] = 'https://otherfirm.example.test';
		law_events_form_save_speakers( $event, array( $row ), array() );
		$this->assertSame( 'chiefexec@example.test', (string) law_event_meta( $namesake, '_law_speaker_email' ), "The namesake's address is untouched." );
		$this->assertSame( 'https://sac.example.test', (string) law_event_meta( $namesake, '_law_website' ) );
	}

	/**
	 * Gap 3: a form opened before the committee corrected the record must not
	 * undo the correction when it is saved untouched, but a field the host did
	 * change still lands.
	 */
	public function test_a_stale_form_does_not_undo_a_committee_correction(): void {
		$event         = $this->make_event();
		$speaker       = law_events_form_save_speakers( $event, array( array( 'first_name' => 'Jon', 'last_name' => 'Teststale', 'email' => 'jon-stale@example.test', 'website' => 'https://old.example.test' ) ), array() )[0];
		$this->posts[] = $speaker;
		$stale         = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) )['speakers'];

		// Meanwhile the committee corrects him on Manage Speakers.
		law_speakers_dashboard_write_identity( $speaker, array( 'name' => 'John Teststale', 'first_name' => 'John', 'last_name' => 'Teststale', 'email' => 'john-stale@example.test', 'website' => 'https://new.example.test' ) );

		// The host saves the form they had open, untouched.
		$resolved = law_events_form_save_speakers( $event, $stale, array() );
		$this->assertSame( $speaker, $resolved[0], 'No split: the row stays on its record.' );
		$this->assertSame( 'John Teststale', get_post( $speaker )->post_title, 'The correction stands.' );
		$this->assertSame( 'john-stale@example.test', (string) law_event_meta( $speaker, '_law_speaker_email' ) );
		$this->assertSame( 'https://new.example.test', (string) law_event_meta( $speaker, '_law_website' ) );

		// A field the host DID change on that stale form still lands.
		$stale[0]['website'] = 'https://host.example.test';
		law_events_form_save_speakers( $event, $stale, array() );
		$this->assertSame( 'https://host.example.test', (string) law_event_meta( $speaker, '_law_website' ) );
		$this->assertSame( 'John Teststale', get_post( $speaker )->post_title );
	}

	/** The Domitille Baizeau incident: one record on two events, replaced on one. */
	public function test_typing_a_different_person_over_a_row_leaves_the_shared_record_alone(): void {
		$event_a = $this->make_event();
		$event_b = $this->make_event();
		$ids     = array();
		foreach ( array( $event_a, $event_b ) as $event ) {
			$ids[] = law_events_form_save_speakers(
				$event,
				array( array( 'first_name' => 'Domitille', 'last_name' => 'Testbaizeau', 'email' => 'dbaizeau@example.test', 'organisation' => 'LALIVE', 'job_title' => 'Partner' ) ),
				array()
			)[0];
		}
		$shared        = $ids[0];
		$this->posts[] = $shared;
		$this->assertSame( $shared, $ids[1], 'The same email and name is one shared record.' );

		// Event B's host types the replacement over the existing row.
		$resolved      = law_events_form_save_speakers(
			$event_b,
			array( array( 'speaker_id' => $shared, 'first_name' => 'Noradele', 'last_name' => 'Testradjai', 'email' => 'nradjai@example.test', 'organisation' => 'LALIVE', 'job_title' => 'Partner' ) ),
			array()
		);
		$new           = $resolved[0];
		$this->posts[] = $new;

		$this->assertNotSame( $shared, $new, 'The row moved to a new record.' );
		$this->assertSame( 'Domitille Testbaizeau', get_post( $shared )->post_title, 'The shared record keeps its name.' );
		$this->assertSame( 'dbaizeau@example.test', (string) law_event_meta( $shared, '_law_speaker_email' ), 'And its email.' );
		$this->assertSame( $shared, (int) law_event_meta( $event_a, '_law_speakers' )[0]['speaker_id'], 'The other event still lists the original person.' );
		$this->assertSame( 'Noradele Testradjai', get_post( $new )->post_title );

		$log = implode( "\n", wp_list_pluck( law_event_log_entries( $event_b ), 'comment_content' ) );
		$this->assertStringContainsString( 'replaced by "Noradele Testradjai"', $log );
		$this->assertStringNotContainsString( 'Speaker profile updated', $log, 'Nothing was rewritten.' );
	}

	/** The Campbell Herbert incident: the replacement already has a record of their own. */
	public function test_a_replacement_who_already_has_a_record_links_to_it(): void {
		$kathleen      = law_speaker_upsert( array( 'first_name' => 'Kathleen', 'last_name' => 'Testdonnelly KC', 'email' => 'kdonnelly@example.test' ) );
		$this->posts[] = $kathleen;

		$event         = $this->make_event();
		$campbell      = law_events_form_save_speakers(
			$event,
			array( array( 'first_name' => 'Campbell', 'last_name' => 'Testherbert', 'email' => 'cherbert@example.test', 'website' => 'https://campbell.example.test', 'organisation' => 'McDermott' ) ),
			array()
		)[0];
		$this->posts[] = $campbell;

		$resolved = law_events_form_save_speakers(
			$event,
			array( array( 'speaker_id' => $campbell, 'first_name' => 'Kathleen', 'last_name' => 'Testdonnelly KC', 'email' => 'kdonnelly@example.test', 'website' => 'https://kathleen.example.test', 'organisation' => 'Henderson Chambers' ) ),
			array()
		);

		$this->assertSame( $kathleen, $resolved[0], 'The row links to her own record.' );
		$this->assertSame( 'Campbell Testherbert', get_post( $campbell )->post_title );
		$this->assertSame( 'https://campbell.example.test', (string) law_event_meta( $campbell, '_law_website' ), 'His website is untouched.' );
		$this->assertSame( 'https://kathleen.example.test', (string) law_event_meta( $kathleen, '_law_website' ), 'Hers was empty, so it was filled.' );

		// Typed without the "KC" her record carries, the row still links to her:
		// linking is forgiving because it never renames anybody.
		$campbell_again = law_events_form_save_speakers(
			$event,
			array( array( 'first_name' => 'Campbell', 'last_name' => 'Testherbert', 'email' => 'cherbert@example.test' ) ),
			array()
		)[0];
		$this->assertSame( $campbell, $campbell_again );
		$resolved = law_events_form_save_speakers(
			$event,
			array( array( 'speaker_id' => $campbell, 'first_name' => 'Kathleen', 'last_name' => 'Testdonnelly', 'email' => 'kdonnelly@example.test' ) ),
			array()
		);
		$this->assertSame( $kathleen, $resolved[0] );
		$this->assertSame( 'Kathleen Testdonnelly KC', get_post( $kathleen )->post_title, 'Her record keeps its own spelling.' );
	}

	/** A host who changes only the name keeps the old email: still a new person. */
	public function test_a_new_name_on_the_old_email_is_a_new_record_sharing_the_address(): void {
		$event         = $this->make_event();
		$old           = law_events_form_save_speakers(
			$event,
			array( array( 'first_name' => 'Kenny', 'last_name' => 'Testlau', 'email' => 'kenny@example.test' ) ),
			array()
		)[0];
		$this->posts[] = $old;

		$new           = law_events_form_save_speakers(
			$event,
			array( array( 'speaker_id' => $old, 'first_name' => 'Abraham', 'last_name' => 'Testvergis', 'email' => 'kenny@example.test' ) ),
			array()
		)[0];
		$this->posts[] = $new;

		$this->assertNotSame( $old, $new );
		$this->assertSame( 'Kenny Testlau', get_post( $old )->post_title );
		$this->assertSame( $old, law_speaker_find_existing( 'kenny@example.test', 'Kenny Testlau' ) );
		$this->assertSame( $new, law_speaker_find_existing( 'kenny@example.test', 'Abraham Testvergis' ), 'Each name finds its own record on the shared address.' );
	}

	/** The One Essex Court incident: a clerks' inbox shared by two barristers. */
	public function test_a_shared_inbox_gives_each_speaker_their_own_record_and_saves_are_stable(): void {
		$event = $this->make_event();
		$rows  = array(
			array( 'first_name' => 'David', 'last_name' => 'Testrichards', 'email' => 'teamd@example.test' ),
			array( 'first_name' => 'Elizabeth', 'last_name' => 'Testgloster', 'email' => 'teamd@example.test' ),
		);
		$ids         = law_events_form_save_speakers( $event, $rows, array() );
		$this->posts = array_merge( $this->posts, array_values( $ids ) );
		$this->assertNotSame( $ids[0], $ids[1], 'Two people, two records, one address.' );

		// Re-save the way the form does, three times: no name may move.
		for ( $i = 0; $i < 3; $i++ ) {
			$values = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) );
			law_events_form_save_speakers( $event, $values['speakers'], array() );
		}
		$this->assertSame( 'David Testrichards', get_post( $ids[0] )->post_title );
		$this->assertSame( 'Elizabeth Testgloster', get_post( $ids[1] )->post_title );
		$log = implode( "\n", wp_list_pluck( law_event_log_entries( $event ), 'comment_content' ) );
		$this->assertStringNotContainsString( 'Speaker profile updated', $log, 'No save renamed anything.' );
	}

	/** Record 745 on prod: one placeholder shared by two events. */
	public function test_naming_a_shared_placeholder_never_renames_it(): void {
		$event_a = $this->make_event();
		$event_b = $this->make_event();
		$holder  = law_speaker_upsert( array( 'name' => 'Other speakers to be confirmed' ) );
		$this->posts[] = $holder;
		foreach ( array( $event_a, $event_b ) as $event ) {
			law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $holder ) ) );
		}

		$named         = law_events_form_save_speakers(
			$event_a,
			array( array( 'speaker_id' => $holder, 'first_name' => 'Kevin', 'last_name' => 'Testnash', 'email' => 'kn@example.test' ) ),
			array()
		)[0];
		$this->posts[] = $named;

		$this->assertNotSame( $holder, $named );
		$this->assertSame( 'Other speakers to be confirmed', get_post( $holder )->post_title );
		$this->assertSame( $holder, (int) law_event_meta( $event_b, '_law_speakers' )[0]['speaker_id'] );
	}

	/** A replacement must not inherit the previous person's photo on this event. */
	public function test_a_replacement_does_not_carry_the_previous_persons_photo(): void {
		$event         = $this->make_event();
		$old           = law_speaker_upsert( array( 'first_name' => 'Holly', 'last_name' => 'Teststebbing', 'email' => 'holly@example.test' ) );
		$this->posts[] = $old;
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $old, 'photo_id' => 999999 ) ) );

		$new           = law_events_form_save_speakers(
			$event,
			array( array( 'speaker_id' => $old, 'first_name' => 'Amy', 'last_name' => 'Testarmitage', 'email' => 'amy@example.test' ) ),
			array()
		)[0];
		$this->posts[] = $new;
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_speakers' )[0]['photo_id'], 'No photo is better than the wrong face.' );

		// A correction of capitals keeps it, on the same record.
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $new, 'photo_id' => 999998 ) ) );
		law_events_form_save_speakers(
			$event,
			array( array( 'speaker_id' => $new, 'first_name' => 'Amy', 'last_name' => 'TESTARMITAGE', 'email' => 'amy@example.test' ) ),
			array()
		);
		$this->assertSame( $new, (int) law_event_meta( $event, '_law_speakers' )[0]['speaker_id'] );
		$this->assertSame( 999998, (int) law_event_meta( $event, '_law_speakers' )[0]['photo_id'] );
		$this->assertSame( 'Amy TESTARMITAGE', get_post( $new )->post_title, 'A correction of capitals still updates the record.' );
	}

	/** Session ticks travel with the row, so they follow a replacement. */
	public function test_a_session_follows_the_row_to_the_replacement(): void {
		$event         = $this->make_event();
		$speaker_ids   = law_events_form_save_speakers(
			$event,
			array( array( 'first_name' => 'Navin', 'last_name' => 'Testwaghe', 'email' => 'navin@example.test' ) ),
			array()
		);
		$this->posts[] = $speaker_ids[0];
		law_events_form_save_sessions(
			$event,
			array( array( 'title' => 'Keynote', 'start' => '09:00', 'end' => '10:00', 'description' => 'A talk.', 'speakers' => array( 'row:0' ) ) ),
			$speaker_ids
		);
		$session     = law_event_session_ids( $event )[0];
		$this->posts[] = $session;

		$before        = array( $speaker_ids[0] );
		$speaker_ids   = law_events_form_save_speakers(
			$event,
			array( array( 'speaker_id' => $before[0], 'first_name' => 'Matt', 'last_name' => 'Testburder', 'email' => 'matt@example.test' ) ),
			array()
		);
		$this->posts[] = $speaker_ids[0];
		law_events_form_save_sessions(
			$event,
			array( array( 'id' => $session, 'title' => 'Keynote', 'start' => '09:00', 'end' => '10:00', 'description' => 'A talk.', 'speakers' => array( 'row:0' ) ) ),
			$speaker_ids,
			$before
		);

		$linked = array_map( fn( $r ) => (int) $r['speaker_id'], law_event_meta( $session, '_law_speakers' ) );
		$this->assertSame( array( $speaker_ids[0] ), $linked );
	}

	public function test_the_same_person_on_two_rows_is_refused_and_the_saver_keeps_one(): void {
		$rows = array(
			array( 'first_name' => 'Twice', 'last_name' => 'Testlisted', 'email' => 'twice@example.test' ),
			array( 'first_name' => 'Another', 'last_name' => 'Testperson', 'email' => 'another@example.test' ),
			array( 'first_name' => 'twice', 'last_name' => 'Testlisted KC', 'email' => 'twice@example.test' ),
		);
		$duplicate = law_events_form_duplicate_speaker( $rows, array() );
		$this->assertSame( array( 1, 3 ), $duplicate['rows'] ?? null, 'Rows 1 and 3 are the same person on the same address.' );

		$this->assertNull( law_events_form_duplicate_speaker( array_slice( $rows, 0, 2 ), array() ) );
		$this->assertNull(
			law_events_form_duplicate_speaker(
				array(
					array( 'first_name' => 'Shared', 'last_name' => 'Testone', 'email' => 'clerks@example.test' ),
					array( 'first_name' => 'Shared', 'last_name' => 'Testtwo', 'email' => 'clerks@example.test' ),
				),
				array()
			),
			'Two people on one inbox are not a duplicate.'
		);

		// The saver's backstop, for the external-event form that skips validation.
		$event       = $this->make_event();
		$ids         = law_events_form_save_speakers( $event, $rows, array() );
		$this->posts = array_merge( $this->posts, array_values( $ids ) );
		$this->assertCount( 2, law_event_meta( $event, '_law_speakers' ), 'One row per person.' );
		$this->assertSame( $ids[0], $ids[2], 'The dropped row still resolves, for session ticks.' );
	}

	/**
	 * Second review, finding 1: a draft saved without an email makes a new,
	 * empty record; when the full submit gives the address the person's real
	 * profile holds, the row moves onto that profile. It used to drop the
	 * address silently and ask for it again on every save.
	 */
	public function test_a_returning_speakers_address_moves_the_row_to_their_profile(): void {
		$other_event   = $this->make_event();
		$real          = law_events_form_save_speakers( $other_event, array( array( 'first_name' => 'Returning', 'last_name' => 'Testspeaker', 'email' => 'returning@example.test' ) ), array() )[0];
		$this->posts[] = $real;

		$event         = $this->make_event();
		$draft         = law_events_form_save_speakers( $event, array( array( 'first_name' => 'Returning', 'last_name' => 'Testspeaker', 'email' => '', 'organisation' => 'Firm R' ) ), array() )[0];
		$this->posts[] = $draft;
		$this->assertNotSame( $real, $draft, 'No email: a new record, never a name match.' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $draft, 'organisation' => 'Firm R', 'photo_id' => 999995 ) ) );

		$row             = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) )['speakers'][0];
		$row['email']    = 'returning@example.test';
		$this->assertSame( $real, law_events_form_speaker_row_target( $row, array( $draft => true ) )['id'], 'The validator predicts the move.' );
		$resolved        = law_events_form_save_speakers( $event, array( $row ), array() );

		$this->assertSame( $real, $resolved[0], 'The row is on their profile now.' );
		$this->assertSame( 999995, (int) law_event_meta( $event, '_law_speakers' )[0]['photo_id'], 'Same person, so the photo stays.' );
		$this->assertSame( 'Firm R', law_event_meta( $event, '_law_speakers' )[0]['organisation'] );
		$this->assertStringContainsString( 'moved to their existing profile', implode( "\n", wp_list_pluck( law_event_log_entries( $event ), 'comment_content' ) ) );
	}

	/** Second review, finding 2: a replacement does not take the website the form showed for the person it replaced. */
	public function test_a_replacement_does_not_inherit_the_old_website(): void {
		$event         = $this->make_event();
		$old           = law_events_form_save_speakers( $event, array( array( 'first_name' => 'Geoffrey', 'last_name' => 'Testnicholas', 'email' => 'geoffrey@example.test', 'website' => 'https://firm.example.test/geoffrey' ) ), array() )[0];
		$this->posts[] = $old;
		$row               = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) )['speakers'][0];
		$row['first_name'] = 'Christine';
		$row['last_name']  = 'Testbogart';
		$row['email']      = 'christine@example.test';
		$new           = law_events_form_save_speakers( $event, array( $row ), array() )[0];
		$this->posts[] = $new;
		$this->assertNotSame( $old, $new );
		$this->assertSame( '', (string) law_event_meta( $new, '_law_website' ), "Geoffrey's page is not hers." );
		$this->assertSame( 'https://firm.example.test/geoffrey', (string) law_event_meta( $old, '_law_website' ) );
	}

	public function test_two_placeholders_on_one_event_are_not_listed_twice(): void {
		$rows = array(
			array( 'first_name' => 'TBC', 'last_name' => '', 'email' => 'host@example.test' ),
			array( 'first_name' => 'TBC', 'last_name' => '', 'email' => 'host@example.test' ),
		);
		$this->assertNull( law_events_form_duplicate_speaker( $rows, array() ) );
	}

	/** Gap 4: what the record-based prediction cannot see, the name and address rule does. */
	public function test_the_listed_twice_check_catches_a_changed_address_and_a_missing_one(): void {
		$event         = $this->make_event();
		$jane          = law_events_form_save_speakers( $event, array( array( 'first_name' => 'Jane', 'last_name' => 'Testtwice', 'email' => 'jane-old@example.test' ) ), array() )[0];
		$this->posts[] = $jane;
		$owned         = array( $jane => true );
		$values        = law_events_form_values( get_post( $event ), array( 'errors' => array(), 'input' => array() ) );
		$row           = $values['speakers'][0];
		$row['email']  = 'jane-new@example.test';

		$this->assertNotNull( law_events_form_duplicate_speaker( array( $row, array( 'first_name' => 'Jane', 'last_name' => 'Testtwice', 'email' => 'jane-new@example.test' ) ), $owned ), 'Her new address on a second row.' );
		$this->assertNotNull( law_events_form_duplicate_speaker( array( $row, array( 'first_name' => 'Jane', 'last_name' => 'Testtwice', 'email' => '' ) ), $owned ), 'Her name again with no address.' );
		$this->assertNull( law_events_form_duplicate_speaker( array( $row, array( 'first_name' => 'Jane', 'last_name' => 'Testtwice', 'email' => 'another-jane@example.test' ) ), $owned ), 'Two Jane Testtwices at different addresses are two people.' );
	}

	public function test_the_external_event_form_refuses_the_same_speaker_twice(): void {
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );
		$row    = array( 'first_name' => 'External', 'last_name' => 'Testtwice', 'email' => 'external-twice@example.test' );
		$result = law_external_event_save(
			array(
				'law_external_id' => 0,
				'law_form_action' => 'draft',
				'event_title'     => 'Twice listed',
				'speakers'        => array( $row, $row ),
			),
			$committee
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'is listed twice', $result->get_error_message( 'speakers' ) );
	}

	public function test_a_flagship_session_keeps_one_row_per_speaker(): void {
		$event         = $this->make_event();
		$speaker       = law_speaker_upsert( array( 'first_name' => 'Flagship', 'last_name' => 'Testtwice', 'email' => 'flagship-twice@example.test' ) );
		$this->posts[] = $speaker;
		$row           = array( 'speaker_id' => $speaker, 'role' => '', 'organisation' => '', 'job_title' => '', 'photo_id' => 0, 'bio' => '' );
		$rows          = law_flagship_resolve_speaker_rows( array( $row, $row ), $event, 0 );
		$this->assertCount( 1, $rows );
		$this->assertStringContainsString( 'listed twice', implode( "\n", wp_list_pluck( law_event_log_entries( $event ), 'comment_content' ) ) );
	}

	/**
	 * A "new speaker" typed on the flagship screens without an email is somebody
	 * new, never a namesake (Denis, 19 November 2026); the content transfer,
	 * which copies the same people between sites, still matches on the name.
	 */
	public function test_a_flagship_new_speaker_without_an_email_is_never_a_namesake(): void {
		$event         = $this->make_event();
		$namesake      = law_speaker_upsert( array( 'first_name' => 'Flagship', 'last_name' => 'Testnamesake' ) );
		$this->posts[] = $namesake;
		$row           = array( 'is_new' => true, 'speaker_id' => 0, 'first_name' => 'Flagship', 'last_name' => 'Testnamesake', 'email' => '', 'website' => '', 'role' => '', 'organisation' => '', 'job_title' => '', 'photo_id' => 0, 'bio' => '' );

		$from_form     = (int) law_flagship_resolve_speaker_rows( array( $row ), $event, 0 )[0]['speaker_id'];
		$this->posts[] = $from_form;
		$this->assertNotSame( $namesake, $from_form, 'The flagship screens make a new speaker.' );

		$from_transfer = (int) law_flagship_resolve_speaker_rows( array( $row ), $event, 0, true )[0]['speaker_id'];
		$this->assertContains( $from_transfer, array( $namesake, $from_form ), 'The transfer still matches on the name.' );
	}

	/** The repair's re-point refuses a row that no longer describes the person it would move it to. */
	public function test_the_repair_repoint_checks_the_row_still_means_that_person(): void {
		$event         = $this->make_event();
		$ed            = law_speaker_upsert( array( 'first_name' => 'Ed', 'last_name' => 'Testpoulton', 'email' => 'ed-repair@example.test' ) );
		$this->posts[] = $ed;
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $ed, 'organisation' => 'Baker McKenzie' ) ) );
		$fix = array(
			'key'     => 'test',
			'type'    => 'repoint',
			'event'   => $event,
			'speaker' => $ed,
			'name_is' => 'Ed Testpoulton',
			'row_is'  => array( 'organisation' => 'Travers Smith LLP' ),
			'person'  => array( 'first_name' => 'Andy', 'last_name' => 'Testmoody', 'email' => 'andy-repair@example.test', 'website' => '' ),
		);
		$this->assertFalse( law_speakers_repair_check_fix( $fix )['ok'], 'The row now says Baker McKenzie.' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $ed, 'organisation' => 'Travers Smith LLP' ) ) );
		$this->assertTrue( law_speakers_repair_check_fix( $fix )['ok'] );
	}

	/**
	 * The repair also cleans a profile whose URL is right but which a logged
	 * overwrite left with the previous person's photo and email (Simon Dack kept
	 * Kevin Nash's photo on prod).
	 */
	public function test_the_repair_cleans_an_overwritten_profile_whose_url_is_fine(): void {
		$event         = $this->make_event();
		$speaker       = law_speaker_upsert( array( 'first_name' => 'Simon', 'last_name' => 'Testdack', 'email' => 'kevin.testnash@example.test' ) );
		$this->posts[] = $speaker;
		$kevin_photo   = $this->fake_image( 'kevin.png' );
		$simon_photo   = $this->fake_image( 'simon.png' );
		set_post_thumbnail( $speaker, $kevin_photo );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker, 'photo_id' => $simon_photo ) ) );
		law_event_log( $event, 'Speaker profile updated from this submission: name "Kevin Testnash" → "Simon Testdack".', array( 'action' => 'speaker_updated', 'speaker' => $speaker ) );
		law_speakers_flush_maps();

		$profile = wp_list_filter( law_speakers_repair_scan()['profiles'], array( 'id' => $speaker ) );
		$this->assertCount( 1, $profile );
		$profile = array_values( $profile )[0];
		$this->assertSame( '', $profile['slug_new'], 'The URL is his own already.' );
		$this->assertTrue( $profile['clear_email'], "The address is Kevin's." );

		law_speakers_repair_apply( array(), array( $speaker ) );
		$this->assertSame( $simon_photo, (int) get_post_thumbnail_id( $speaker ) );
		$this->assertSame( '', (string) law_event_meta( $speaker, '_law_speaker_email' ) );
		$this->assertSame( 'simon-testdack', get_post( $speaker )->post_name );
	}

	public function test_manage_speakers_clash_check_allows_a_shared_inbox(): void {
		$one           = law_speaker_upsert( array( 'first_name' => 'Inbox', 'last_name' => 'Testone', 'email' => 'clerks-two@example.test' ) );
		$this->posts[] = $one;
		$this->assertSame( 0, law_speaker_find_existing( 'clerks-two@example.test', 'Inbox Testtwo' ), 'A differently named speaker may take the address.' );
		$this->assertSame( $one, law_speaker_find_existing( 'clerks-two@example.test', 'Inbox Testone' ) );
		$this->assertSame( $one, law_speaker_find_existing( 'clerks-two@example.test', '' ), 'An address lookup with no name still finds the record.' );
	}

	/**
	 * The one-off repair (migration/repair-speakers.php): a record the old
	 * overwrite turned into somebody else gets a URL from its own name with no
	 * redirect from the old one, the photo from its own event row as its
	 * fallback, and the same-named record the squatter pushed to "-2" gets the
	 * clean URL back.
	 */
	public function test_the_repair_gives_a_taken_over_profile_its_own_url_and_photo(): void {
		$speaker       = law_speaker_upsert( array( 'first_name' => 'Holly', 'last_name' => 'Testrepairold', 'email' => 'repair-old@example.test' ) );
		$heir          = law_speaker_upsert( array( 'first_name' => 'Holly', 'last_name' => 'Testrepairold', 'email' => 'repair-heir@example.test' ) );
		$this->posts[] = $speaker;
		$this->posts[] = $heir;
		$old_slug      = get_post( $speaker )->post_name;
		$this->assertSame( $old_slug . '-2', get_post( $heir )->post_name, 'The second Holly was pushed to -2.' );

		$old_photo = $this->fake_image( 'holly.png' );
		$new_photo = $this->fake_image( 'amy.png' );
		set_post_thumbnail( $speaker, $old_photo );

		// What the old overwrite did: renamed in place, slug and photo left behind.
		wp_update_post( array( 'ID' => $speaker, 'post_title' => 'Amy Testrepairnew' ) );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker, 'photo_id' => $new_photo ) ) );
		law_speakers_flush_maps();

		$listed = wp_list_pluck( law_speakers_repair_scan()['profiles'], 'id' );
		$this->assertContains( $speaker, $listed );
		$this->assertNotContains( $heir, $listed );

		law_speakers_repair_apply( array(), array( $speaker ) );

		$this->assertSame( 'amy-testrepairnew', get_post( $speaker )->post_name );
		$this->assertNotContains( $old_slug, get_post_meta( $speaker, '_wp_old_slug' ), 'No redirect from a URL named after somebody else.' );
		$this->assertSame( $new_photo, (int) get_post_thumbnail_id( $speaker ), 'The fallback photo is now the one on their own row.' );
		$this->assertSame( $old_slug, get_post( $heir )->post_name, 'The real Holly gets the clean URL back.' );
		$this->assertStringContainsString( 'Speaker profile repaired', implode( "\n", wp_list_pluck( law_event_log_entries( $event ), 'comment_content' ) ) );
		$this->assertNotContains( $speaker, wp_list_pluck( law_speakers_repair_scan()['profiles'], 'id' ), 'A second run finds nothing to do.' );
	}

	/** An image attachment without a file: enough for a featured image. */
	private function fake_image( string $name ): int {
		$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ) );
		update_post_meta( $id, '_wp_attached_file', '2026/11/' . $name );
		$this->posts[] = (int) $id;
		return (int) $id;
	}

	/**
	 * A blank biography on this event stays blank in the form (19 November
	 * 2026): it used to prefill from the speaker post's editor content, the first
	 * biography any host typed, so an untouched re-save published another
	 * event's text here.
	 */
	public function test_the_form_never_prefills_another_events_biography(): void {
		$event_a = $this->make_event();
		$event_b = $this->make_event();
		$speaker = law_events_form_save_speakers( $event_b, array( array( 'first_name' => 'Jane', 'last_name' => 'Testbiography', 'email' => 'jane-bio@example.test', 'bio' => 'Written for event B.' ) ), array() )[0];
		$this->posts[] = $speaker;
		law_events_form_save_speakers( $event_a, array( array( 'first_name' => 'Jane', 'last_name' => 'Testbiography', 'email' => 'jane-bio@example.test', 'bio' => '' ) ), array() );

		$values = law_events_form_values( get_post( $event_a ), array( 'errors' => array(), 'input' => array() ) );
		$this->assertSame( '', $values['speakers'][0]['bio'], "Event B's biography is not offered on event A." );
		law_events_form_save_speakers( $event_a, $values['speakers'], array() );
		$this->assertSame( '', (string) law_event_meta( $event_a, '_law_speakers' )[0]['bio'], 'And an untouched re-save does not publish it.' );
	}

	public function test_log_context_survives_quotes_and_non_ascii(): void {
		$event   = $this->make_event();
		$comment = law_event_log( $event, 'A line.', array( 'changes' => array( 'name "Noradèle" → "Domitille"' ) ) );
		$this->assertSame( array( 'name "Noradèle" → "Domitille"' ), law_event_log_context( $comment )['changes'] ?? null );
	}
}
