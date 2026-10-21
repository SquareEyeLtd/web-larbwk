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
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		remove_filter( 'wp_doing_ajax', '__return_true' );
		remove_all_filters( 'wp_die_ajax_handler' );
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

	/* Edge cases _____________________________________________________________ */

	/** A form saved twice can leave the same person on an event twice: both rows go, both come back. */
	public function test_duplicate_rows_on_one_event_all_go_and_all_return(): void {
		$ada   = $this->make_speaker( 'Ada Twicelisted' );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_speakers', array( $this->row( $ada, 'One LLP' ), $this->row( $ada, 'Two LLP' ) ) );

		wp_trash_post( $ada );
		$this->assertSame( array(), $this->speaker_ids( $event ) );

		wp_untrash_post( $ada );
		$this->assertSame( array( $ada, $ada ), $this->speaker_ids( $event ) );
		$this->assertSame( array( 'One LLP', 'Two LLP' ), array_column( law_event_meta( $event, '_law_speakers' ), 'organisation' ) );
	}

	/** Drafts are appearances too, and a draft session is still the event's. */
	public function test_draft_events_and_draft_sessions_are_cleared(): void {
		$ada     = $this->make_speaker( 'Ada Draftspeaker' );
		$draft   = $this->make_event( array(), 'law-draft' );
		law_event_update_meta( $draft, '_law_speakers', array( $this->row( $ada, 'Draft LLP' ) ) );
		$session = $this->make_session( $draft, array( $this->row( $ada, 'Draft LLP' ) ) );
		wp_update_post( array( 'ID' => $session, 'post_status' => 'draft' ) );

		wp_trash_post( $ada );

		$this->assertSame( array(), $this->speaker_ids( $draft ) );
		$this->assertSame( array(), $this->speaker_ids( $session ) );
	}

	/** Emptying the trash later finds nothing left to take, and says nothing more. */
	public function test_emptying_the_trash_after_a_delete_changes_nothing_more(): void {
		$f = $this->fixture();
		wp_trash_post( $f['ada'] );
		$before = law_event_meta( $f['first'], '_law_speakers' );

		wp_delete_post( $f['ada'], true );

		$this->assertNull( get_post( $f['ada'] ) );
		$this->assertSame( $before, law_event_meta( $f['first'], '_law_speakers' ) );
		$this->assertCount( 1, $this->log_actions( $f['first'], 'speaker_removed' ), 'Still the one line from the trash.' );
	}

	/** An event deleted for good while the speaker sat in the trash is skipped, not an error. */
	public function test_restore_skips_an_event_that_has_since_gone(): void {
		$f = $this->fixture();
		wp_trash_post( $f['ada'] );
		wp_delete_post( $f['second'], true );

		$this->assertNotFalse( wp_untrash_post( $f['ada'] ) );

		$this->assertNull( get_post( $f['second'] ) );
		$this->assertContains( $f['ada'], $this->speaker_ids( $f['first'] ), 'The rest still come back.' );
	}

	/* The dialog _____________________________________________________________ */

	private function render( string $part, array $args = array() ): string {
		ob_start();
		get_template_part( $part, null, $args );
		return (string) ob_get_clean();
	}

	/**
	 * With the list filtered to one event, the row shows that event only, but
	 * a delete takes the speaker off both, so the dialog must name both.
	 */
	public function test_the_list_dialog_names_every_event_under_an_event_filter(): void {
		$f    = $this->fixture();
		$_GET = array( 'law_event' => (string) $f['second'] );

		$html = $this->render( 'parts/events/speakers-dashboard-list' );

		$this->assertStringContainsString( 'id="law-modal-speaker-delete-' . $f['ada'] . '"', $html );
		$this->assertStringContainsString( 'Ada Deletespeaker is on 2 events.', $html );
		$this->assertStringContainsString( esc_html( get_the_title( $f['first'] ) ), $html, 'The filtered-out event is still named.' );
		$this->assertStringContainsString( '(Confirmed)', $html );
		$this->assertStringContainsString( 'Events already on the website change straight away.', $html, 'The Confirmed one is public.' );
		$this->assertStringContainsString( 'which puts them back on these events too.', $html );
	}

	public function test_the_dialog_reads_right_for_one_event_and_for_none(): void {
		$ada   = $this->make_speaker( 'Ada Oneevent' );
		$event = $this->make_event( array(), 'law-proposed' );
		law_event_update_meta( $event, '_law_speakers', array( $this->row( $ada, 'One LLP' ) ) );
		law_speakers_flush_maps();

		$one = $this->render( 'parts/events/speaker-delete', array( 'speaker_id' => $ada, 'name' => 'Ada Oneevent', 'appearances' => law_speakers_dashboard_appearances( $ada ) ) );
		$this->assertStringContainsString( 'Ada Oneevent is on 1 event.', $one );
		$this->assertStringContainsString( 'which puts them back on this event too.', $one );
		$this->assertStringNotContainsString( 'website change straight away', $one, 'A Proposed event is not public.' );

		$none = $this->render( 'parts/events/speaker-delete', array( 'speaker_id' => $ada, 'name' => 'Ada Oneevent', 'appearances' => array(), 'opener' => 'text' ) );
		$this->assertStringContainsString( 'Ada Oneevent is not on any event, so deleting the record changes nothing else.', $none );
		$this->assertStringNotContainsString( '<ul class="law-modal__list">', $none );
		$this->assertStringContainsString( 'class="law-text-button"', $none, 'The edit view gets the quieter opener.' );
	}

	/** A tag typed into a wp-admin title prints as text, everywhere the dialog uses the name. */
	public function test_a_name_carrying_markup_is_printed_not_rendered(): void {
		$html = $this->render(
			'parts/events/speaker-delete',
			array( 'speaker_id' => 123456, 'name' => '<b>Bold</b> Speaker', 'appearances' => array() )
		);

		$this->assertStringNotContainsString( '<b>Bold</b>', $html );
		$this->assertStringContainsString( '&lt;b&gt;Bold&lt;/b&gt; Speaker is not on any event', $html );
	}

	/* The handler, over its AJAX branch _______________________________________ */

	/**
	 * Run the delete handler as the dashboard's fetch would, and return its
	 * JSON. wp_send_json() ends in wp_die() when the request is AJAX, so the
	 * die handler is swapped for an exception to get control back.
	 */
	private function post_delete( array $fields, string $handler = 'law_speakers_dashboard_delete_handler' ): array {
		$_POST    = $fields + array( 'action' => 'law_speaker_delete', 'law_ajax' => '1' );
		$_REQUEST = $_POST;
		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			fn() => function () {
				throw new RuntimeException( 'law-test-die' );
			}
		);

		ob_start();
		try {
			$handler();
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'law-test-die', $e->getMessage() );
		}
		$out = (string) ob_get_clean();

		$json = json_decode( $out, true );
		$this->assertIsArray( $json, 'The handler answered JSON: ' . $out );
		return $json;
	}

	public function test_the_committee_deletes_and_is_sent_back_to_the_list(): void {
		$f = $this->fixture();
		wp_set_current_user( $this->make_committee_user() );

		$json = $this->post_delete(
			array(
				'law_speaker_id'   => (string) $f['ada'],
				'_wpnonce'         => wp_create_nonce( 'law_speaker_delete' ),
				'_wp_http_referer' => '/law/account/dashboard/speakers/?law_speaker=' . $f['ada'] . '&law_kw=ada&law_partial=1',
			)
		);

		$this->assertTrue( $json['success'] );
		$this->assertSame( 'Speaker deleted', $json['data']['title'] );
		$this->assertSame( 'Ada Deletespeaker has been deleted and taken off 2 events.', $json['data']['message'] );
		$this->assertSame( '/law/account/dashboard/speakers/?law_kw=ada&law_notice=speaker-deleted', $json['data']['redirect'], 'The list, filters kept, the deleted record and the partial flag dropped.' );
		$this->assertSame( 'trash', get_post_status( $f['ada'] ) );
		$this->assertNotContains( $f['ada'], $this->speaker_ids( $f['first'] ) );
	}

	public function test_anybody_but_the_committee_is_refused(): void {
		$f = $this->fixture();
		wp_set_current_user( $this->make_user( 'subscriber' ) );

		$json = $this->post_delete( array( 'law_speaker_id' => (string) $f['ada'], '_wpnonce' => wp_create_nonce( 'law_speaker_delete' ) ) );

		$this->assertFalse( $json['success'] );
		$this->assertSame( 'Sorry, managing speakers is for the committee.', $json['data']['message'] );
		$this->assertSame( 'publish', get_post_status( $f['ada'] ) );
		$this->assertContains( $f['ada'], $this->speaker_ids( $f['first'] ) );
	}

	public function test_a_stale_page_is_refused_before_anything_happens(): void {
		$f = $this->fixture();
		wp_set_current_user( $this->make_committee_user() );

		$json = $this->post_delete( array( 'law_speaker_id' => (string) $f['ada'], '_wpnonce' => 'not-a-nonce' ) );

		$this->assertFalse( $json['success'] );
		$this->assertStringContainsString( 'reload the page', $json['data']['message'] );
		$this->assertSame( 'publish', get_post_status( $f['ada'] ) );
	}

	/** Two tabs, or two committee members: the second delete says so rather than failing oddly. */
	public function test_deleting_a_record_already_deleted_says_so(): void {
		$f = $this->fixture();
		wp_set_current_user( $this->make_committee_user() );
		wp_trash_post( $f['ada'] );

		$json = $this->post_delete( array( 'law_speaker_id' => (string) $f['ada'], '_wpnonce' => wp_create_nonce( 'law_speaker_delete' ) ) );

		$this->assertFalse( $json['success'] );
		$this->assertSame( 'That speaker could not be found. They may already have been deleted.', $json['data']['message'] );
		$this->assertCount( 1, $this->log_actions( $f['first'], 'speaker_removed' ), 'No second removal logged.' );
	}

	/** Only a speaker can be deleted here: an event ID posted in its place is refused. */
	public function test_a_post_that_is_not_a_speaker_is_refused(): void {
		$f = $this->fixture();
		wp_set_current_user( $this->make_committee_user() );

		$json = $this->post_delete( array( 'law_speaker_id' => (string) $f['first'], '_wpnonce' => wp_create_nonce( 'law_speaker_delete' ) ) );

		$this->assertFalse( $json['success'] );
		$this->assertSame( 'publish', get_post_status( $f['first'] ) );
	}

	/** A save from an edit view left open in another tab writes nothing to a deleted record. */
	public function test_a_save_aimed_at_a_deleted_record_is_refused(): void {
		$f = $this->fixture();
		wp_set_current_user( $this->make_committee_user() );
		wp_trash_post( $f['ada'] );

		$json = $this->post_delete(
			array(
				'action'         => 'law_speaker_manage',
				'law_speaker_id' => (string) $f['ada'],
				'_wpnonce'       => wp_create_nonce( 'law_speaker_manage' ),
				'first_name'     => 'Stale',
				'last_name'      => 'Edit',
			),
			'law_speakers_dashboard_save_handler'
		);

		$this->assertFalse( $json['success'] );
		$this->assertSame( 'That speaker could not be found. They may already have been deleted.', $json['data']['message'] );
		$this->assertSame( 'Ada Deletespeaker', get_post_field( 'post_title', $f['ada'] ), 'The name was not rewritten.' );
	}

	/** Both refusals and the success send the committee to the list, never back to the record. */
	public function test_the_way_back_drops_the_record_and_keeps_the_filters(): void {
		$_REQUEST['_wp_http_referer'] = '/law/account/dashboard/speakers/?law_speaker=42&law_event=7&law_notice=speaker-saved&law_speaker_error=1';

		$this->assertSame(
			'/law/account/dashboard/speakers/?law_event=7&law_notice=speaker-not-found',
			law_speakers_dashboard_list_return( 'speaker-not-found' )
		);
	}
}
