<?php
/**
 * The flagship conference (FLAGSHIP_UI.md): the one law_event post that is
 * edited on its own wp-admin screen instead of through the host form.
 *
 * What these tests pin is the machinery that is easy to get quietly wrong: the
 * derived start and end times, the deduped speakers union, the ownership check
 * on a posted session ID, the exclusions from the committee queue and hosts'
 * "My events", and the fact that a host cannot promote their own event to
 * flagship by adding a field to the POST.
 *
 * Fixture note: the real site will hold a committed flagship post, and
 * law_flagship_event_id() returns the lowest flagged ID, so every test makes
 * its own event and pins the helpers to it through the
 * 'law_flagship_event_id' filter rather than relying on the database.
 */
class FlagshipTest extends LAW_Test_Case {

	/** The pinned fixture, so the filter callback and tearDown can see it. */
	private int $flagship = 0;

	protected function tearDown(): void {
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		if ( function_exists( 'law_calendar_reset_caches' ) ) {
			law_calendar_reset_caches();
		}
		$_GET = array();
		parent::tearDown();
	}

	/** A flagged event, pinned as "the" flagship for the rest of the test. */
	private function make_flagship( string $status = 'law-draft' ): int {
		$event_id = $this->make_event( array( '_law_is_flagship' => 1, '_law_flagship_date' => '2026-12-02' ), $status );
		wp_update_post( array( 'ID' => $event_id, 'post_name' => 'flagship' ) );

		$this->flagship = $event_id;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		law_flagship_event_id( true );

		return $event_id;
	}

	/** A speaker record. */
	private function make_speaker( string $first, string $last, string $email = '' ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => LAW_SPEAKER_CPT,
				'post_status' => 'publish',
				'post_title'  => trim( $first . ' ' . $last ),
			)
		);
		$this->posts[] = $post_id;
		law_event_update_meta( $post_id, '_law_speaker_first_name', $first );
		law_event_update_meta( $post_id, '_law_speaker_last_name', $last );
		if ( '' !== $email ) {
			law_event_update_meta( $post_id, '_law_speaker_email', $email );
		}
		return (int) $post_id;
	}

	/** Track the flagship's sessions for tearDown and return them, in order. */
	private function sessions( int $event_id ): array {
		$ids         = law_event_session_ids( $event_id );
		$this->posts = array_merge( $this->posts, array_diff( $ids, $this->posts ) );
		return $ids;
	}

	/** A complete screen input. */
	private function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'title'            => 'Flagship conference',
				'description'      => '<p>The main event of the week.</p>',
				'date'             => '2026-12-02',
				'venue'            => 'IDRC, 70 Fleet Street, London',
				'hero_image_id'    => 0,
				'show'             => false,
				'sessions_present' => true,
				'sessions'         => array(),
			),
			$overrides
		);
	}

	/** A session row as the screen posts it. */
	private function session( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'          => 0,
				'title'       => 'Opening keynote',
				'start'       => '09:30',
				'end'         => '10:30',
				'description' => '<p>What happens.</p>',
				'speakers'    => array(),
			),
			$overrides
		);
	}

	/** A speaker row for an existing profile. */
	private function speaker_row( int $speaker_id, array $overrides = array() ): array {
		return array_merge(
			array(
				'speaker_id'   => $speaker_id,
				'is_new'       => false,
				'first_name'   => '',
				'last_name'    => '',
				'email'        => '',
				'website'      => '',
				'role'         => 'speaker',
				'organisation' => 'Test Chambers',
				'job_title'    => 'Arbitrator',
				'photo_id'     => 0,
				'bio'          => '<p>A biography.</p>',
			),
			$overrides
		);
	}

	private function save( array $input ) {
		$result = law_flagship_save( $input, get_current_user_id() );
		if ( ! is_wp_error( $result ) ) {
			$this->sessions( (int) $result );
		}
		return $result;
	}

	public function test_ensure_post_creates_one_record_and_is_idempotent(): void {
		// No filter here: this is the real lookup, which is what the migration
		// and the ?setup-account-pages trigger both call.
		$first = law_flagship_ensure_post();
		$this->assertGreaterThan( 0, $first['id'] );
		$this->posts[] = $first['id'];

		if ( $first['created'] ) {
			$post = get_post( $first['id'] );
			$this->assertSame( 'flagship', $post->post_name, 'The slug is what gives it /events/flagship/.' );
			$this->assertSame( 'law-draft', $post->post_status, 'It starts hidden: nothing is on the programme until someone ticks the box.' );
			$this->assertSame( 1, (int) law_event_meta( $first['id'], '_law_is_flagship' ) );
			$this->assertSame( law_flagship_default_date(), law_flagship_date( $first['id'] ) );
		}

		$second = law_flagship_ensure_post();
		$this->assertSame( $first['id'], $second['id'], 'A second run finds the same post.' );
		$this->assertFalse( $second['created'], 'And reports that it changed nothing.' );

		$flagged = get_posts(
			array(
				'post_type'      => LAW_EVENT_CPT,
				'post_status'    => law_event_all_status_keys(),
				'meta_key'       => '_law_is_flagship',
				'meta_value'     => '1',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 1, $flagged, 'There is exactly one flagship, however many times the setup runs.' );
	}

	public function test_a_dry_run_creates_nothing(): void {
		$before = get_posts(
			array(
				'post_type'      => LAW_EVENT_CPT,
				'post_status'    => law_event_all_status_keys(),
				'meta_key'       => '_law_is_flagship',
				'meta_value'     => '1',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);

		$report = law_flagship_ensure_post( true );
		$this->assertFalse( $report['created'] );
		$this->assertStringContainsString( $before ? 'exists' : 'Would create', $report['message'] );

		$after = get_posts(
			array(
				'post_type'      => LAW_EVENT_CPT,
				'post_status'    => law_event_all_status_keys(),
				'meta_key'       => '_law_is_flagship',
				'meta_value'     => '1',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$this->assertSame( $before, $after );
	}

	public function test_start_and_end_are_computed_from_the_sessions(): void {
		$event_id = $this->make_flagship();

		$this->save(
			$this->input(
				array(
					'sessions' => array(
						$this->session( array( 'title' => 'Morning', 'start' => '09:30', 'end' => '10:30' ) ),
						$this->session( array( 'title' => 'Afternoon', 'start' => '14:00', 'end' => '16:00' ) ),
					),
				)
			)
		);

		$this->assertSame( '2026-12-02 09:30', law_event_meta( $event_id, '_law_start' ), 'The earliest session start.' );
		$this->assertSame( '2026-12-02 16:00', law_event_meta( $event_id, '_law_end' ), 'The latest session end.' );
		// A flagship holds no slot, and a stale label would blank its datetimes
		// the next time the slot helper ran over it.
		$this->assertSame( '', (string) law_event_meta( $event_id, '_law_slot_label' ) );

		// Removing every session clears the derived times rather than leaving
		// yesterday's answer behind.
		$this->save( $this->input( array( 'sessions' => array() ) ) );
		$this->assertSame( '', (string) law_event_meta( $event_id, '_law_start' ) );
		$this->assertSame( '', (string) law_event_meta( $event_id, '_law_end' ) );
		$this->assertSame( array(), law_event_session_ids( $event_id ) );
	}

	public function test_a_session_time_is_zero_padded(): void {
		$event_id = $this->make_flagship();

		// "9:30" typed by hand must not sort after "14:00", which is what the
		// derived start and law_event_session_ids() both compare.
		$this->save(
			$this->input(
				array(
					'sessions' => array(
						$this->session( array( 'title' => 'Late', 'start' => '14:00', 'end' => '15:00' ) ),
						$this->session( array( 'title' => 'Early', 'start' => '9:30', 'end' => '10:30' ) ),
					),
				)
			)
		);

		$this->assertSame( '2026-12-02 09:30', law_event_meta( $event_id, '_law_start' ) );
		$this->assertSame(
			array( 'Early', 'Late' ),
			array_map( 'get_the_title', law_event_session_ids( $event_id ) ),
			'And the running order is by time, not by entry.'
		);
	}

	public function test_event_speakers_are_the_deduped_union_of_the_sessions(): void {
		$event_id = $this->make_flagship();
		$a        = $this->make_speaker( 'Ada', 'Advocate' );
		$b        = $this->make_speaker( 'Ben', 'Barrister' );

		$this->save(
			$this->input(
				array(
					'sessions' => array(
						$this->session( array( 'title' => 'One', 'start' => '09:30', 'end' => '10:30', 'speakers' => array( $this->speaker_row( $a ) ) ) ),
						$this->session(
							array(
								'title'    => 'Two',
								'start'    => '11:00',
								'end'      => '12:00',
								'speakers' => array( $this->speaker_row( $a ), $this->speaker_row( $b ) ),
							)
						),
					),
				)
			)
		);

		$rows = law_event_meta( $event_id, '_law_speakers' );
		$this->assertSame( array( $a, $b ), wp_list_pluck( $rows, 'speaker_id' ), 'Someone speaking twice appears on the event once.' );
		$this->assertSame( array( 0, 1 ), wp_list_pluck( $rows, 'sort' ), 'And the union is renumbered, not left with gaps.' );
	}

	public function test_a_published_flagships_speakers_reach_the_speaker_index(): void {
		$event_id = $this->make_flagship( 'publish' );
		$speaker  = $this->make_speaker( 'Cara', 'Counsel' );

		$this->save(
			$this->input(
				array(
					'show'     => true,
					'sessions' => array( $this->session( array( 'speakers' => array( $this->speaker_row( $speaker ) ) ) ) ),
				)
			)
		);

		// The union on the event is what the archive reads, so a flagship speaker
		// needs no special case on the read side.
		$maps = law_speakers_confirmed_maps( true );
		$this->assertContains( $event_id, $maps['events'][ $speaker ] ?? array() );
		$this->assertNotNull( law_speaker_appearance_for_event( $speaker, $event_id ) );
	}

	public function test_a_new_speaker_row_is_created_and_deduped_by_email(): void {
		$event_id = $this->make_flagship();

		$new = array(
			'speaker_id'   => 0,
			'is_new'       => true,
			'first_name'   => 'Dana',
			'last_name'    => 'Delegate',
			'email'        => 'dana.delegate@example.test',
			'website'      => '',
			'role'         => 'moderator',
			'organisation' => 'Delegate LLP',
			'job_title'    => 'Partner',
			'photo_id'     => 0,
			'bio'          => '<p>Chairs the panel.</p>',
		);

		$this->save( $this->input( array( 'sessions' => array( $this->session( array( 'speakers' => array( $new ) ) ) ) ) ) );

		$sessions = law_event_session_ids( $event_id );
		$rows     = law_event_meta( $sessions[0], '_law_speakers' );
		$this->assertCount( 1, $rows );
		$speaker_id    = (int) $rows[0]['speaker_id'];
		$this->posts[] = $speaker_id;
		$this->assertSame( LAW_SPEAKER_CPT, get_post_type( $speaker_id ) );
		$this->assertSame( 'Dana Delegate', get_the_title( $speaker_id ) );
		$this->assertSame( 'moderator', $rows[0]['role'], 'The role is per appearance, not per person.' );

		// Entering the same address again links to the same profile rather than
		// creating a second Dana Delegate.
		$this->save( $this->input( array( 'sessions' => array( $this->session( array( 'title' => 'Second', 'speakers' => array( $new ) ) ) ) ) ) );
		$sessions = law_event_session_ids( $event_id );
		$rows     = law_event_meta( $sessions[0], '_law_speakers' );
		$this->assertSame( $speaker_id, (int) $rows[0]['speaker_id'] );

		$found = get_posts(
			array(
				'post_type'      => LAW_SPEAKER_CPT,
				'post_status'    => 'publish',
				'meta_key'       => '_law_speaker_email',
				'meta_value'     => 'dana.delegate@example.test',
				'fields'         => 'ids',
				'posts_per_page' => -1,
			)
		);
		$this->assertCount( 1, $found, 'One profile, however many sessions the same person speaks in.' );
	}

	public function test_a_posted_session_id_must_already_belong_to_the_flagship(): void {
		$event_id = $this->make_flagship();
		$other    = $this->make_event();
		$stolen   = wp_insert_post(
			array(
				'post_type'   => LAW_SESSION_CPT,
				'post_status' => 'publish',
				'post_parent' => $other,
				'post_title'  => 'A session of another event',
			)
		);
		$this->posts[] = $stolen;

		$this->save( $this->input( array( 'sessions' => array( $this->session( array( 'id' => $stolen, 'title' => 'Hijacked' ) ) ) ) ) );

		$mine = law_event_session_ids( $event_id );
		$this->assertCount( 1, $mine );
		$this->assertNotSame( (int) $stolen, (int) $mine[0], 'A forged ID creates a new session instead of seizing one.' );
		$this->assertSame( $other, get_post( $stolen )->post_parent, 'And the other event keeps its own.' );
		$this->assertSame( 'A session of another event', get_the_title( $stolen ) );
	}

	public function test_an_edit_keeps_session_ids_and_deletes_only_removed_rows(): void {
		$event_id = $this->make_flagship();
		$this->save(
			$this->input(
				array(
					'sessions' => array(
						$this->session( array( 'title' => 'Keep', 'start' => '09:30', 'end' => '10:30' ) ),
						$this->session( array( 'title' => 'Drop', 'start' => '11:00', 'end' => '12:00' ) ),
					),
				)
			)
		);
		$ids = $this->sessions( $event_id );
		$this->assertCount( 2, $ids );

		$this->save(
			$this->input(
				array(
					'sessions' => array( $this->session( array( 'id' => $ids[0], 'title' => 'Keep, retitled', 'start' => '09:30', 'end' => '10:30' ) ) ),
				)
			)
		);

		$after = law_event_session_ids( $event_id );
		$this->assertSame( array( (int) $ids[0] ), $after, 'The kept session keeps its post ID.' );
		$this->assertSame( 'Keep, retitled', get_the_title( $ids[0] ) );
		$this->assertNull( get_post( $ids[1] ), 'The removed one is gone.' );
	}

	public function test_the_flagship_is_hidden_from_the_committee_queue(): void {
		$event_id = $this->make_flagship( 'publish' );
		$ordinary = $this->make_event();

		$ids = wp_list_pluck( law_committee_events(), 'ID' );
		$this->assertContains( $ordinary, $ids, 'Host submissions still list.' );
		$this->assertNotContains( $event_id, $ids, 'The flagship has no workflow, fee or slot to review.' );

		// And the detail view refuses it, so a stale ?event= link cannot open a
		// screen offering actions it does not have.
		$_GET['event'] = (string) $event_id;
		$this->assertNull( law_committee_requested_event() );
		$_GET['event'] = (string) $ordinary;
		$this->assertNotNull( law_committee_requested_event() );
	}

	public function test_the_flagship_is_hidden_from_my_events(): void {
		$host     = $this->make_user( 'event_host' );
		$ordinary = $this->make_event( array(), 'law-proposed', $host );
		$event_id = $this->make_flagship();
		wp_update_post( array( 'ID' => $event_id, 'post_author' => $host ) );

		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		wp_set_current_user( $host );
		$ids = wp_list_pluck( wp_list_pluck( law_account_events(), 'event' ), 'id' );
		remove_all_filters( 'pre_option_law_events_source' );

		$this->assertContains( $ordinary, $ids, 'Their own submission is theirs to manage.' );
		$this->assertNotContains( $event_id, $ids, 'The flagship is LAW\'s, edited in wp-admin.' );
	}

	public function test_the_host_form_cannot_flag_an_event_as_the_flagship(): void {
		$pinned = $this->make_flagship();
		$host   = $this->make_user( 'event_host' );

		$slot_labels = array_keys( law_events_slot_choices( array() ) );
		$result      = law_events_form_save(
			array(
				'law_form_action'     => 'update',
				'event_title'         => 'A host submission',
				'description'         => 'Not the flagship.',
				'event_type'          => 'Social event',
				'host_organisations'  => 'Ambitious Org LLP',
				'preferred_slots'     => $slot_labels ? array( $slot_labels[0] ) : array( 'Any slot' ),
				'sectors'             => array(),
				'venue_needed'        => 'Yes, please share our details with venue hosts',
				'fee_tier'            => 'uk',
				'invoice_name'        => 'Host Contact',
				'invoice_email'       => 'host-invoice@example.test',
				'invoice_line1'       => '1 Test Street',
				'invoice_city'        => 'London',
				'invoice_postal_code' => 'EC1A 1AA',
				'invoice_country'     => 'United Kingdom',
				'terms'               => '1',
				// The attempt: fields the form does not know, in the hope that
				// something writes them through.
				'_law_is_flagship'    => 1,
				'law_flagship'        => array( 'title' => 'Mine now', 'show' => '1' ),
			),
			array(),
			null,
			$host
		);

		$this->assertIsInt( $result, 'Guard: the submission itself is valid and saves.' );
		$this->posts[] = $result;
		$this->assertSame( '', (string) get_post_meta( $result, '_law_is_flagship', true ), 'The flag is not writable from the host form.' );
		$this->assertSame( $pinned, law_flagship_event_id(), 'And the flagship is still the flagship.' );
	}

	public function test_validation_refuses_and_writes_nothing(): void {
		$event_id = $this->make_flagship();
		$this->save( $this->input( array( 'title' => 'Before the bad save', 'sessions' => array( $this->session() ) ) ) );
		$sessions_before = law_event_session_ids( $event_id );

		$result = law_flagship_save(
			$this->input(
				array(
					'title'    => '',
					'sessions' => array(
						$this->session( array( 'title' => 'No start', 'start' => '' ) ),
						$this->session(
							array(
								'title'    => 'Bad speaker',
								'speakers' => array(
									array(
										'speaker_id'   => 0,
										'is_new'       => true,
										'first_name'   => 'Half',
										'last_name'    => '',
										'email'        => '',
										'website'      => '',
										'role'         => '',
										'organisation' => '',
										'job_title'    => '',
										'photo_id'     => 0,
										'bio'          => '',
									),
								),
							)
						),
					),
				)
			),
			get_current_user_id()
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertCount( 3, $result->get_error_messages(), 'The title, the missing start and the half-named speaker.' );
		$this->assertSame( 'Before the bad save', get_post( $event_id )->post_title, 'A refused save changes nothing at all.' );
		$this->assertSame( $sessions_before, law_event_session_ids( $event_id ) );
	}

	public function test_an_end_before_its_start_is_refused(): void {
		$this->make_flagship();
		$result = law_flagship_save(
			$this->input( array( 'sessions' => array( $this->session( array( 'start' => '14:00', 'end' => '09:00' ) ) ) ) ),
			get_current_user_id()
		);
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'ends before it starts', implode( ' ', $result->get_error_messages() ) );
	}

	public function test_saving_records_what_changed_in_the_activity_log(): void {
		$event_id = $this->make_flagship();
		$actor    = $this->make_committee_user();

		law_flagship_save( $this->input( array( 'show' => true, 'sessions' => array( $this->session( array( 'title' => 'Opening keynote' ) ) ) ) ), $actor );
		$this->sessions( $event_id );

		$lines = wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' );
		$saved = array_values( array_filter( $lines, fn( $line ) => false !== strpos( $line, 'Flagship event saved' ) ) );
		$this->assertNotEmpty( $saved, 'A save that changed something says so.' );
		$this->assertStringContainsString( 'shown on the programme', $saved[0] );
		$this->assertStringContainsString( 'Opening keynote', $saved[0] );

		// A save that changed nothing adds no line: the log is a record of
		// decisions, not of clicks.
		$before = count( law_event_log_entries( $event_id ) );
		law_flagship_save( $this->input( array( 'show' => true, 'sessions' => array( $this->session( array( 'id' => law_event_session_ids( $event_id )[0], 'title' => 'Opening keynote' ) ) ) ) ), $actor );
		$this->assertCount( $before, law_event_log_entries( $event_id ) );
	}

	public function test_the_flagship_cannot_be_booked(): void {
		$event_id = $this->make_flagship( 'publish' );
		law_event_update_meta( $event_id, '_law_tickets_available', 100 );
		$attendee = $this->make_user( 'event_attendee' );

		$result = law_booking_create( $event_id, $attendee, array() );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_booking_flagship', $result->get_error_code(), 'Hiding the button is not the control; the engine refuses it.' );
	}

	public function test_the_wp_admin_event_screen_cannot_blank_the_derived_times(): void {
		$event_id = $this->make_flagship();
		$this->save( $this->input( array( 'sessions' => array( $this->session() ) ) ) );
		$this->assertSame( '2026-12-02 09:30', law_event_meta( $event_id, '_law_start' ) );

		// The event screen's slot select is empty for the flagship (it holds no
		// slot), and an empty slot clears the datetimes for an ordinary event.
		$committee = $this->make_committee_user();
		wp_set_current_user( $committee );
		$_POST = array(
			'law_event_admin_nonce' => wp_create_nonce( 'law_event_admin_save' ),
			'law_slot_label'        => '',
			'law_start'             => '',
			'law_end'               => '',
		);
		law_event_admin_save( $event_id, get_post( $event_id ) );
		$_POST = array();

		$this->assertSame( '2026-12-02 09:30', law_event_meta( $event_id, '_law_start' ), 'The derived start survives an Update on the event screen.' );
	}
}
