<?php
/**
 * Merging two speaker records (functions/events/migration/merge-speakers.php):
 * the second speaker's rows move onto the main speaker with their per-event
 * details, the main speaker's record is untouched, legacy addresses, years and
 * the old profile address follow, and the second record goes to the trash with
 * nothing left for a restore to put back.
 */
class SpeakerMergeTest extends LAW_Test_Case {

	private int $flagship = 0;

	protected function tearDown(): void {
		remove_all_filters( 'law_flagship_event_id' );
		if ( function_exists( 'law_flagship_event_id' ) ) {
			law_flagship_event_id( true );
		}
		$_POST    = array();
		$_REQUEST = array();
		$GLOBALS['wp_query']->set( 'name', '' );
		parent::tearDown();
	}

	private function make_speaker( string $name, string $email = '' ): int {
		$speaker_id = wp_insert_post(
			array(
				'post_type'    => LAW_SPEAKER_CPT,
				'post_status'  => 'publish',
				'post_title'   => $name,
				'post_content' => 'Profile biography of ' . $name . '.',
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

	private function row( int $speaker_id, string $organisation, string $role = 'moderator' ): array {
		return array(
			'speaker_id'   => $speaker_id,
			'role'         => $role,
			'organisation' => $organisation,
			'job_title'    => 'Partner at ' . $organisation,
			'photo_id'     => 4242,
			'bio'          => 'Biography at ' . $organisation . '.',
		);
	}

	private function speaker_ids( int $post_id ): array {
		return array_map( fn( $row ) => (int) $row['speaker_id'], law_event_meta( $post_id, '_law_speakers' ) );
	}

	private function log_actions( int $event_id, string $action ): array {
		return array_values(
			array_filter(
				law_event_log_entries( $event_id ),
				fn( $entry ) => $action === ( law_event_log_context( $entry->comment_ID )['action'] ?? '' )
			)
		);
	}

	/**
	 * Sylvia under two records: the main one on the first event, the typo one
	 * on the second and on a session of the first, with Bea as a bystander.
	 */
	private function fixture(): array {
		$main   = $this->make_speaker( 'Sylvia Mergetest', 'sylvia.merge@example.test' );
		$typo   = $this->make_speaker( 'Sylvia Mergtest', 'sylvia.mrge@example.test' );
		$bea    = $this->make_speaker( 'Bea Bystander' );
		$first  = $this->make_event( array( '_law_start' => '2026-12-01 10:00' ), 'publish' );
		$second = $this->make_event( array( '_law_start' => '2026-12-03 10:00' ), 'law-proposed' );

		law_event_update_meta( $first, '_law_speakers', array( $this->row( $bea, 'Bea LLP' ), $this->row( $main, 'Main LLP' ) ) );
		law_event_update_meta( $second, '_law_speakers', array( $this->row( $typo, 'Typo Chambers', 'speaker' ), $this->row( $bea, 'Bea LLP' ) ) );
		$session = $this->make_session( $first, array( $this->row( $typo, 'Session LLP' ) ) );

		law_speakers_flush_maps();

		return compact( 'main', 'typo', 'bea', 'first', 'second', 'session' );
	}

	public function test_the_rows_move_with_their_own_per_event_details(): void {
		$f = $this->fixture();

		$result = law_events_merge_speakers_apply( $f['main'], $f['typo'] );

		$this->assertIsArray( $result );
		$this->assertSame( array( $f['main'], $f['bea'] ), $this->speaker_ids( $f['second'] ), 'In the same place in the running order.' );
		$moved = law_event_meta( $f['second'], '_law_speakers' )[0];
		$this->assertSame( 'Typo Chambers', $moved['organisation'], "The event keeps what its host typed." );
		$this->assertSame( 'Partner at Typo Chambers', $moved['job_title'] );
		$this->assertSame( 'Biography at Typo Chambers.', $moved['bio'] );
		$this->assertSame( 'speaker', $moved['role'] );
		$this->assertSame( 4242, (int) $moved['photo_id'] );
		$this->assertSame( array( $f['main'] ), $this->speaker_ids( $f['session'] ), 'Session rows move too.' );
		$this->assertSame( 'Session LLP', law_event_meta( $f['session'], '_law_speakers' )[0]['organisation'] );
		$this->assertSame( array( $f['bea'], $f['main'] ), $this->speaker_ids( $f['first'] ), 'The bystander and the main row are untouched.' );

		$this->assertSame( array(), law_speakers_dashboard_appearances( $f['typo'] ) );
		$this->assertCount( 2, law_speakers_dashboard_appearances( $f['main'] ), 'Manage Speakers now places her on both events.' );
	}

	public function test_an_event_already_naming_the_main_speaker_keeps_only_the_main_row(): void {
		$main  = $this->make_speaker( 'Ada Both', 'ada.both@example.test' );
		$typo  = $this->make_speaker( 'Ada Bothh' );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_speakers', array( $this->row( $typo, 'Typo LLP' ), $this->row( $main, 'Main LLP' ) ) );

		$plan = law_events_merge_speakers_plan( $main, $typo );
		$this->assertSame( 'drop', $plan['posts'][0]['action'] );

		law_events_merge_speakers_apply( $main, $typo );

		$rows = law_event_meta( $event, '_law_speakers' );
		$this->assertSame( array( $main ), $this->speaker_ids( $event ), 'The person once, never twice.' );
		$this->assertSame( 'Main LLP', $rows[0]['organisation'], "The main speaker's own row wins." );
		$this->assertStringContainsString( 'on this event twice, so the extra row was removed', $this->log_actions( $event, 'speaker_merged' )[0]->comment_content );
	}

	public function test_two_rows_of_the_second_speaker_on_one_event_collapse_to_one(): void {
		$main  = $this->make_speaker( 'Ada Twice' );
		$typo  = $this->make_speaker( 'Ada Twicee' );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_speakers', array( $this->row( $typo, 'One LLP' ), $this->row( $typo, 'Two LLP' ) ) );

		law_events_merge_speakers_apply( $main, $typo );

		$this->assertSame( array( $main ), $this->speaker_ids( $event ) );
		$this->assertSame( 'One LLP', law_event_meta( $event, '_law_speakers' )[0]['organisation'], 'The first row is the one kept.' );
	}

	public function test_the_main_speakers_record_is_untouched(): void {
		$f = $this->fixture();
		law_event_update_meta( $f['main'], '_law_speaker_first_name', 'Sylvia' );
		law_event_update_meta( $f['main'], '_law_speaker_last_name', 'Mergetest' );
		law_event_update_meta( $f['main'], '_law_website', 'https://main.example.test' );
		law_event_update_meta( $f['typo'], '_law_website', 'https://typo.example.test' );
		update_post_meta( $f['main'], '_thumbnail_id', 777 );
		update_post_meta( $f['typo'], '_thumbnail_id', 888 );
		clean_post_cache( $f['main'] );

		$before = array(
			'post'  => get_post( $f['main'], ARRAY_A ),
			'first' => law_event_meta( $f['main'], '_law_speaker_first_name' ),
			'last'  => law_event_meta( $f['main'], '_law_speaker_last_name' ),
			'email' => law_event_meta( $f['main'], '_law_speaker_email' ),
			'web'   => law_event_meta( $f['main'], '_law_website' ),
			'photo' => get_post_thumbnail_id( $f['main'] ),
		);

		law_events_merge_speakers_apply( $f['main'], $f['typo'] );
		clean_post_cache( $f['main'] );

		$after = get_post( $f['main'], ARRAY_A );
		foreach ( array( 'post_title', 'post_content', 'post_name', 'post_status' ) as $field ) {
			$this->assertSame( $before['post'][ $field ], $after[ $field ], $field );
		}
		$this->assertSame( $before['first'], law_event_meta( $f['main'], '_law_speaker_first_name' ) );
		$this->assertSame( $before['last'], law_event_meta( $f['main'], '_law_speaker_last_name' ) );
		$this->assertSame( $before['email'], law_event_meta( $f['main'], '_law_speaker_email' ) );
		$this->assertSame( $before['web'], law_event_meta( $f['main'], '_law_website' ) );
		$this->assertSame( $before['photo'], get_post_thumbnail_id( $f['main'] ) );
	}

	public function test_the_second_record_is_trashed_stamped_and_its_address_redirects(): void {
		$f    = $this->fixture();
		$slug = get_post_field( 'post_name', $f['typo'] );

		$result = law_events_merge_speakers_apply( $f['main'], $f['typo'] );

		$this->assertTrue( $result['trashed'] );
		$this->assertSame( 'trash', get_post_status( $f['typo'] ) );
		$this->assertSame( (string) $f['main'], (string) get_post_meta( $f['typo'], LAW_SPEAKER_MERGED_INTO_META, true ) );
		$this->assertSame( '', get_post_meta( $f['typo'], LAW_SPEAKER_DETACHED_META, true ), 'The trash found nothing left to take.' );
		$this->assertContains( $slug, get_post_meta( $f['main'], '_wp_old_slug' ) );

		// What core's wp_old_slug_redirect() asks on a 404 at /speakers/<slug>/.
		$GLOBALS['wp_query']->set( 'name', $slug );
		$this->assertSame( $f['main'], (int) _find_post_by_old_slug( LAW_SPEAKER_CPT ) );
	}

	public function test_restoring_the_merged_record_puts_no_rows_back(): void {
		$f = $this->fixture();
		law_events_merge_speakers_apply( $f['main'], $f['typo'] );

		wp_untrash_post( $f['typo'] );

		$this->assertSame( array( $f['main'], $f['bea'] ), $this->speaker_ids( $f['second'] ) );
		$this->assertSame( array( $f['main'] ), $this->speaker_ids( $f['session'] ) );
		$this->assertSame( array(), $this->log_actions( $f['second'], 'speaker_restored' ) );
	}

	public function test_legacy_entry_addresses_follow_the_main_speaker(): void {
		$f = $this->fixture();
		law_event_update_meta( $f['typo'], '_law_gf_entry_id', 991001 );
		law_event_update_meta( $f['typo'], '_law_gf_entry_ids', array( 991001, 991002 ) );
		law_event_update_meta( $f['main'], '_law_gf_entry_ids', array( 991000 ) );
		law_migration_map_set( 'speakers', 991002, $f['typo'] );
		law_migration_map_set( 'speakers', 991000, $f['main'] );

		law_events_merge_speakers_apply( $f['main'], $f['typo'] );

		$this->assertSame( $f['main'], (int) law_migration_map()['speakers'][991002] );
		$this->assertSame( $f['main'], (int) law_migration_map()['speakers'][991000] );
		$this->assertEqualsCanonicalizing( array( 991000, 991001, 991002 ), law_event_meta( $f['main'], '_law_gf_entry_ids' ) );
		$this->assertSame( $f['main'], law_events_resolve_speaker_id( 991001 ), 'Through the meta fallback.' );
		$this->assertSame( $f['main'], law_events_resolve_speaker_id( 991002 ), 'Through the map.' );
	}

	public function test_year_terms_follow_the_main_speaker(): void {
		$f = $this->fixture();
		wp_set_object_terms( $f['main'], '2026', 'law_year' );
		wp_set_object_terms( $f['typo'], 'test-merge-2025', 'law_year' );

		law_events_merge_speakers_apply( $f['main'], $f['typo'] );

		$this->assertEqualsCanonicalizing( array( '2026', 'test-merge-2025' ), wp_get_object_terms( $f['main'], 'law_year', array( 'fields' => 'slugs' ) ) );
	}

	public function test_one_log_line_per_event_with_the_session_folded_in(): void {
		$f     = $this->fixture();
		$actor = $this->make_user( 'administrator' );

		law_events_merge_speakers_apply( $f['main'], $f['typo'], $actor );

		$first = $this->log_actions( $f['first'], 'speaker_merged' );
		$this->assertCount( 1, $first, 'The session is logged on its event.' );
		$this->assertCount( 1, $this->log_actions( $f['second'], 'speaker_merged' ) );
		$this->assertStringContainsString( sprintf( 'Speaker "Sylvia Mergtest" (#%d) merged into "Sylvia Mergetest" (#%d).', $f['typo'], $f['main'] ), $first[0]->comment_content );
		$this->assertSame( $actor, (int) $first[0]->user_id );
		$context = law_event_log_context( $first[0]->comment_ID );
		$this->assertSame( 'speaker_merge', $context['source'] );
		$this->assertSame( $f['typo'], (int) $context['merged'] );
	}

	/** The flagship's own rows are the union of its sessions', so it is recomputed. */
	public function test_a_flagship_union_names_the_main_speaker(): void {
		$main     = $this->make_speaker( 'Ada Flagshipmain' );
		$typo     = $this->make_speaker( 'Ada Flagshiptypo' );
		$flagship = $this->make_event( array( '_law_is_flagship' => 1, '_law_flagship_date' => '2026-12-02' ), 'law-draft' );
		$this->flagship = $flagship;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		law_flagship_event_id( true );

		$this->make_session( $flagship, array( $this->row( $typo, 'Typo LLP' ) ) );
		law_flagship_recompute( $flagship );
		$this->assertSame( array( $typo ), $this->speaker_ids( $flagship ), 'Fixture: the union names the typo record.' );

		law_events_merge_speakers_apply( $main, $typo );

		$this->assertSame( array( $main ), $this->speaker_ids( $flagship ) );
	}

	public function test_the_plan_refuses_a_bad_pair(): void {
		$main  = $this->make_speaker( 'Ada Refused' );
		$other = $this->make_speaker( 'Ada Refusedd' );
		$event = $this->make_event();

		$this->assertSame( 'law_merge_same', law_events_merge_speakers_plan( $main, $main )->get_error_code() );
		$this->assertSame( 'law_merge_missing', law_events_merge_speakers_plan( $main, 0 )->get_error_code() );
		$this->assertSame( 'law_merge_not_speaker', law_events_merge_speakers_plan( $main, $event )->get_error_code() );

		wp_trash_post( $other );
		$this->assertSame( 'law_merge_not_speaker', law_events_merge_speakers_plan( $main, $other )->get_error_code() );
		$this->assertSame( 'law_merge_not_speaker', law_events_merge_speakers_apply( $other, $main )->get_error_code(), 'Apply refuses through the same plan.' );
		$this->assertSame( 'publish', get_post_status( $main ), 'And writes nothing.' );
	}

	/* The panel ______________________________________________________________ */

	private function render_panel( array $post ): string {
		wp_set_current_user( $this->make_user( 'administrator' ) );
		$post['law_merge_speakers_nonce'] = wp_create_nonce( 'law_merge_speakers' );
		$_POST    = $post;
		$_REQUEST = $post;
		ob_start();
		law_events_merge_speakers_panel();
		return (string) ob_get_clean();
	}

	public function test_the_preview_shows_what_would_happen_and_writes_nothing(): void {
		$f      = $this->fixture();
		$before = array( law_event_meta( $f['second'], '_law_speakers' ), law_event_meta( $f['session'], '_law_speakers' ) );

		$html = $this->render_panel( array( 'law_merge_step' => 'preview', 'law_merge_main' => $f['main'], 'law_merge_merged' => $f['typo'] ) );

		$this->assertStringContainsString( 'Moves to the main speaker', $html );
		$this->assertStringContainsString( esc_html( get_the_title( $f['second'] ) ), $html );
		$this->assertStringContainsString( 'sylvia.mrge@example.test', $html );
		$this->assertStringContainsString( sprintf( 'Merge #%d into #%d', $f['typo'], $f['main'] ), $html );
		$this->assertSame( $before, array( law_event_meta( $f['second'], '_law_speakers' ), law_event_meta( $f['session'], '_law_speakers' ) ) );
		$this->assertSame( 'publish', get_post_status( $f['typo'] ) );
	}

	public function test_the_merge_button_merges_and_says_so(): void {
		$f = $this->fixture();

		$html = $this->render_panel( array( 'law_merge_step' => 'merge', 'law_merge_main' => $f['main'], 'law_merge_merged' => $f['typo'] ) );

		$this->assertStringContainsString( 'notice-success', $html );
		$this->assertStringContainsString( 'moved to the trash', $html );
		$this->assertSame( 'trash', get_post_status( $f['typo'] ) );
		$this->assertStringNotContainsString( sprintf( 'value="%d"', $f['typo'] ), $html, 'The merged record is gone from the selects.' );
	}

	public function test_the_same_speaker_twice_is_an_error_notice(): void {
		$f = $this->fixture();

		$html = $this->render_panel( array( 'law_merge_step' => 'preview', 'law_merge_main' => $f['main'], 'law_merge_merged' => $f['main'] ) );

		$this->assertStringContainsString( 'Choose two different speakers.', $html );
		$this->assertStringNotContainsString( 'Merge #', $html );
	}
}
