<?php
/**
 * Deleting a speaker record (functions/events/speakers.php, "Removing a speaker
 * record"; the committee's Delete in functions/events/speakers-dashboard.php):
 * trashing the record takes it off every event and session that names it,
 * leaves everybody else's rows alone, logs on each event, and keeps what it
 * took so a restore from the trash puts it back.
 *
 * Everything goes through wp_trash_post() / wp_untrash_post() /
 * wp_delete_post(), because the work hangs off those hooks: the committee's
 * Delete and wp-admin's Trash link must do the same thing.
 */
class SpeakerDeleteTest extends LAW_Test_Case {

	private int $flagship = 0;

	protected function tearDown(): void {
		remove_all_filters( 'law_flagship_event_id' );
		if ( function_exists( 'law_flagship_event_id' ) ) {
			law_flagship_event_id( true );
		}
		$_GET = array();
		parent::tearDown();
	}

	private function make_speaker( string $name, string $email = '' ): int {
		$speaker_id = wp_insert_post(
			array(
				'post_type'   => LAW_SPEAKER_CPT,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		$this->posts[] = $speaker_id;
		if ( '' !== $email ) {
			law_event_update_meta( $speaker_id, '_law_speaker_email', $email );
		}
		return (int) $speaker_id;
	}

	private function make_session( int $event_id, array $rows ): int {
		$session_id = wp_insert_post(
			array(
				'post_type'   => LAW_SESSION_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Session ' . wp_generate_password( 6, false ),
				'post_parent' => $event_id,
			)
		);
		$this->posts[] = $session_id;
		law_event_update_meta( $session_id, '_law_speakers', $rows );
		return (int) $session_id;
	}

	private function row( int $speaker_id, string $organisation ): array {
		return array(
			'speaker_id'   => $speaker_id,
			'role'         => 'moderator',
			'organisation' => $organisation,
			'job_title'    => 'Partner',
			'bio'          => 'Biography at ' . $organisation . '.',
		);
	}

	private function speaker_ids( int $post_id ): array {
		return array_map( fn( $row ) => (int) $row['speaker_id'], law_event_meta( $post_id, '_law_speakers' ) );
	}

	/** Log entries on one event carrying one action. */
	private function log_actions( int $event_id, string $action ): array {
		return array_values(
			array_filter(
				law_event_log_entries( $event_id ),
				fn( $entry ) => $action === ( law_event_log_context( $entry->comment_ID )['action'] ?? '' )
			)
		);
	}

	/**
	 * Ada at two events (one of them in a session as well) and Bea beside her
	 * on the first, so every assertion can check the bystander too.
	 */
	private function fixture(): array {
		$ada = $this->make_speaker( 'Ada Deletespeaker', 'ada.delete@example.test' );
		$bea = $this->make_speaker( 'Bea Staysput' );

		$first  = $this->make_event( array( '_law_start' => '2026-12-01 10:00' ), 'publish' );
		$second = $this->make_event( array( '_law_start' => '2026-12-03 10:00' ), 'law-proposed' );

		law_event_update_meta( $first, '_law_speakers', array( $this->row( $bea, 'Bea LLP' ), $this->row( $ada, 'First LLP' ) ) );
		law_event_update_meta( $second, '_law_speakers', array( $this->row( $ada, 'Second Chambers' ) ) );
		$session = $this->make_session( $first, array( $this->row( $ada, 'First LLP' ), $this->row( $bea, 'Bea LLP' ) ) );

		law_speakers_flush_maps();

		return compact( 'ada', 'bea', 'first', 'second', 'session' );
	}

	public function test_trash_takes_the_speaker_off_every_event_and_session(): void {
		$f = $this->fixture();

		$this->assertNotFalse( wp_trash_post( $f['ada'] ) );

		$this->assertSame( array( $f['bea'] ), $this->speaker_ids( $f['first'] ), 'The bystander keeps her row.' );
		$this->assertSame( 'Bea LLP', law_event_meta( $f['first'], '_law_speakers' )[0]['organisation'], "And her row's details." );
		$this->assertSame( array( $f['bea'] ), $this->speaker_ids( $f['session'] ), 'Session rows hold the person too.' );
		$this->assertSame( array(), $this->speaker_ids( $f['second'] ), 'An event whose only speaker she was is left with none.' );
		$this->assertSame( '', get_post_meta( $f['second'], '_law_speakers', true ), 'The empty array is deleted, not stored.' );

		$appearances = law_speakers_dashboard_appearances( $f['ada'] );
		$this->assertSame( array(), $appearances, 'Manage Speakers no longer places her anywhere.' );

		// One line per EVENT: the first lost her from its own row and a
		// session, and says so once.
		$this->assertCount( 1, $this->log_actions( $f['first'], 'speaker_removed' ) );
		$this->assertCount( 1, $this->log_actions( $f['second'], 'speaker_removed' ) );
		$this->assertStringContainsString( 'Ada Deletespeaker', $this->log_actions( $f['first'], 'speaker_removed' )[0]->comment_content );
	}

	public function test_restore_puts_the_rows_back_and_the_record_back_on_the_list(): void {
		$f = $this->fixture();
		wp_trash_post( $f['ada'] );

		$this->assertNotFalse( wp_untrash_post( $f['ada'] ) );

		$this->assertSame( 'publish', get_post_status( $f['ada'] ), 'Not core\'s draft, which Manage Speakers and the profile never show.' );
		$this->assertContains( $f['ada'], $this->speaker_ids( $f['first'] ) );
		$this->assertContains( $f['ada'], $this->speaker_ids( $f['session'] ) );
		$this->assertSame( array( $f['ada'] ), $this->speaker_ids( $f['second'] ) );

		$second = law_event_meta( $f['second'], '_law_speakers' )[0];
		$this->assertSame( 'Second Chambers', $second['organisation'], 'The per-event details come back with the row.' );
		$this->assertSame( 'moderator', $second['role'] );
		$this->assertSame( 'Biography at Second Chambers.', $second['bio'] );

		$this->assertSame( '', get_post_meta( $f['ada'], LAW_SPEAKER_DETACHED_META, true ), 'The kept rows are spent.' );
		$this->assertCount( 1, $this->log_actions( $f['first'], 'speaker_restored' ) );

		$ids = array_map( fn( $row ) => $row['id'], law_speakers_dashboard_rows( law_speakers_dashboard_filters( array() ) )['rows'] );
		$this->assertContains( $f['ada'], $ids, 'Listed on Manage Speakers again.' );
	}

	/**
	 * While Ada sat in the trash, a host re-added her to the first event by
	 * email, which made a NEW record (a trashed one is never matched). The
	 * restore must not put the old row beside it.
	 */
	public function test_restore_skips_an_event_that_already_names_the_person_again(): void {
		$f = $this->fixture();
		wp_trash_post( $f['ada'] );

		$again = $this->make_speaker( 'Ada Deletespeaker', 'ada.delete@example.test' );
		$rows  = law_event_meta( $f['first'], '_law_speakers' );
		$rows[] = $this->row( $again, 'First LLP' );
		law_event_update_meta( $f['first'], '_law_speakers', $rows );

		wp_untrash_post( $f['ada'] );

		$this->assertSame( array( $f['bea'], $again ), $this->speaker_ids( $f['first'] ), 'Once, under the record the host made.' );
		$this->assertSame( array( $f['ada'] ), $this->speaker_ids( $f['second'] ), 'Everywhere else she comes back.' );
	}

	public function test_a_trashed_event_is_cleared_too(): void {
		$f = $this->fixture();
		wp_trash_post( $f['second'] );

		wp_trash_post( $f['ada'] );

		$this->assertSame( array(), $this->speaker_ids( $f['second'] ), 'A restored event must not come back naming a record that has gone.' );
	}

	public function test_deleting_outright_takes_the_rows_as_well(): void {
		$f = $this->fixture();

		wp_delete_post( $f['ada'], true );

		$this->assertSame( array( $f['bea'] ), $this->speaker_ids( $f['first'] ) );
		$this->assertSame( array( $f['bea'] ), $this->speaker_ids( $f['session'] ) );
		$this->assertSame( array(), $this->speaker_ids( $f['second'] ) );
	}

	/** The flagship's own rows are the union of its sessions', so it is recomputed. */
	public function test_a_flagship_drops_the_speaker_from_its_union(): void {
		$ada      = $this->make_speaker( 'Ada Flagshipspeaker' );
		$bea      = $this->make_speaker( 'Bea Flagshipstays' );
		$flagship = $this->make_event( array( '_law_is_flagship' => 1, '_law_flagship_date' => '2026-12-02' ), 'law-draft' );
		$this->flagship = $flagship;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		law_flagship_event_id( true );

		$this->make_session( $flagship, array( $this->row( $ada, 'Ada LLP' ) ) );
		$this->make_session( $flagship, array( $this->row( $bea, 'Bea LLP' ), $this->row( $ada, 'Ada LLP' ) ) );
		law_flagship_recompute( $flagship );
		$this->assertSame( array( $ada, $bea ), $this->speaker_ids( $flagship ), 'Fixture: the union names both.' );

		wp_trash_post( $ada );

		$this->assertSame( array( $bea ), $this->speaker_ids( $flagship ) );
	}

	public function test_the_dashboard_treats_a_trashed_record_as_gone(): void {
		$f = $this->fixture();

		$_GET['law_speaker'] = (string) $f['ada'];
		$this->assertSame( $f['ada'], law_speakers_dashboard_requested_speaker() );

		wp_trash_post( $f['ada'] );

		$this->assertSame( 0, law_speakers_dashboard_requested_speaker(), 'The edit view falls back to the list.' );
		$this->assertNull( law_speakers_dashboard_speaker( $f['ada'] ), 'Neither handler will write to it.' );
		$this->assertNotNull( law_speakers_dashboard_speaker( $f['bea'] ) );
	}
}
