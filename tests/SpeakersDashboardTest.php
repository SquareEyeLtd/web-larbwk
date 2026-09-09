<?php
/**
 * The committee's Manage Speakers dashboard
 * (functions/events/speakers-dashboard.php): the row set and its
 * first-appearance headline, the keyword / event / year filters, the
 * one-row-per-appearance export, the event picker, and the single-row writer
 * the save handler uses.
 *
 * The point of the screen is that speaker details are stored per appearance, so
 * every test here works with one speaker on more than one event.
 */
class SpeakersDashboardTest extends LAW_Test_Case {

	private function filters( array $overrides = array() ): array {
		return law_speakers_dashboard_filters( $overrides );
	}

	/** A speaker post, tracked for teardown. */
	private function make_speaker( string $name, array $meta = array() ): int {
		$speaker_id = wp_insert_post(
			array(
				'post_type'   => LAW_SPEAKER_CPT,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);
		$this->posts[] = $speaker_id;
		foreach ( $meta as $key => $value ) {
			law_event_update_meta( $speaker_id, $key, $value );
		}
		return (int) $speaker_id;
	}

	/**
	 * One speaker at two events: a Confirmed one submitted first and a
	 * Proposed one submitted after it, each carrying its own details. The
	 * Proposed event is the reason the dashboard walks every status — a
	 * law_speaker post exists from the first draft save, not from approval.
	 */
	private function fixture(): array {
		$speaker = $this->make_speaker(
			'Ada Dashboardspeaker',
			array( '_law_speaker_email' => 'ada.dashboard@example.test', '_law_website' => 'https://example.test/ada' )
		);

		$confirmed = $this->make_event( array( '_law_start' => '2026-12-01 10:00' ), 'publish' );
		$proposed  = $this->make_event( array( '_law_start' => '2026-12-05 10:00' ), 'law-proposed' );

		law_event_update_meta(
			$confirmed,
			'_law_speakers',
			array( array( 'speaker_id' => $speaker, 'role' => 'moderator', 'organisation' => 'First LLP', 'job_title' => 'Partner', 'bio' => 'Bio for the confirmed event.' ) )
		);
		law_event_update_meta(
			$proposed,
			'_law_speakers',
			array( array( 'speaker_id' => $speaker, 'role' => 'host', 'organisation' => 'Second Testchambers', 'job_title' => 'Testarbitrator', 'bio' => 'Bio for the proposed event.' ) )
		);

		// Events created mid-request: the appearance maps are memoised.
		law_speakers_flush_maps();

		return array( 'speaker' => $speaker, 'confirmed' => $confirmed, 'proposed' => $proposed );
	}

	public function test_rows_carry_identity_and_every_appearance(): void {
		$f    = $this->fixture();
		$rows = law_speakers_dashboard_rows( $this->filters() )['rows'];
		$row  = null;
		foreach ( $rows as $candidate ) {
			if ( $candidate['id'] === $f['speaker'] ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row, 'The speaker is listed.' );
		$this->assertSame( 'Ada Dashboardspeaker', $row['name'] );
		$this->assertSame( 'Ada', $row['first_name'], 'No stored parts on this fixture: the title is split.' );
		$this->assertSame( 'Dashboardspeaker', $row['last_name'] );
		$this->assertSame( 'ada.dashboard@example.test', $row['email'] );
		$this->assertSame( 'https://example.test/ada', $row['website'] );
		$this->assertCount( 2, $row['appearances'], 'A Proposed event counts as an appearance here, unlike on the public archive.' );

		// The headline is the earliest appearance's, the archive card's rule.
		$this->assertSame( 'First LLP', $row['organisation'] );
		$this->assertSame( 'Partner', $row['job_title'] );

		$by_event = array();
		foreach ( $row['appearances'] as $appearance ) {
			$by_event[ $appearance['event_id'] ] = $appearance;
		}
		$this->assertSame( 'moderator', $by_event[ $f['confirmed'] ]['role'] );
		$this->assertSame( 'host', $by_event[ $f['proposed'] ]['role'], 'The role is per appearance, so the two differ.' );
		$this->assertSame( 'Second Testchambers', $by_event[ $f['proposed'] ]['organisation'] );
		$this->assertSame( 'publish', $by_event[ $f['confirmed'] ]['event_status'] );
		$this->assertSame( 'law-proposed', $by_event[ $f['proposed'] ]['event_status'] );
	}

	public function test_keyword_matches_name_email_and_appearance_details(): void {
		$f  = $this->fixture();
		$id = $f['speaker'];

		$this->assertSame( array( $id ), $this->ids( 'Dashboardspeaker' ), 'By name.' );
		$this->assertSame( array( $id ), $this->ids( 'ada.dashboard@example.test' ), 'By email.' );
		$this->assertSame( array( $id ), $this->ids( 'second testchambers' ), "By an appearance's organisation, case-insensitively." );
		$this->assertSame( array( $id ), $this->ids( 'Testarbitrator' ), "By an appearance's job title." );
		$this->assertSame( array(), $this->ids( 'nobody-of-that-name' ) );
	}

	/** Matching speaker IDs for a keyword, so the assertions above read as one line each. */
	private function ids( string $keyword ): array {
		$rows = law_speakers_dashboard_rows( $this->filters( array( 'law_kw' => $keyword ) ) )['rows'];
		return array_map( fn( $row ) => $row['id'], $rows );
	}

	public function test_event_filter_narrows_to_that_events_appearance(): void {
		$f    = $this->fixture();
		$data = law_speakers_dashboard_rows( $this->filters( array( 'law_event' => $f['proposed'] ) ) );

		$row = null;
		foreach ( $data['rows'] as $candidate ) {
			if ( $candidate['id'] === $f['speaker'] ) {
				$row = $candidate;
			}
		}
		$this->assertNotNull( $row );
		$this->assertCount( 1, $row['appearances'], 'Only the filtered event is listed for the speaker.' );
		$this->assertSame( $f['proposed'], $row['appearances'][0]['event_id'] );

		// A speaker with no appearance on that event drops out entirely.
		$other = $this->make_speaker( 'Bee Notonthatevent' );
		law_speakers_flush_maps();
		$this->assertNotContains( $other, array_map( fn( $r ) => $r['id'], $data['rows'] ) );
	}

	public function test_year_filter_reads_the_events_term(): void {
		$f    = $this->fixture();
		$term = wp_insert_term( 'Speakers dashboard year ' . wp_generate_password( 6, false ), 'law_year' );
		$this->assertFalse( is_wp_error( $term ) );
		wp_set_object_terms( $f['proposed'], (int) $term['term_id'], 'law_year' );
		$slug = get_term( (int) $term['term_id'], 'law_year' )->slug;

		$data = law_speakers_dashboard_rows( $this->filters( array( 'law_year' => $slug ) ) );
		$ids  = array_map( fn( $row ) => $row['id'], $data['rows'] );
		$this->assertContains( $f['speaker'], $ids, 'The speaker appears at an event in that year.' );
		foreach ( $data['rows'] as $row ) {
			if ( $row['id'] === $f['speaker'] ) {
				$this->assertCount( 1, $row['appearances'], "Only the year's event is listed." );
				$this->assertSame( $f['proposed'], $row['appearances'][0]['event_id'] );
			}
		}

		wp_delete_term( (int) $term['term_id'], 'law_year' );
	}

	public function test_filters_normalise_request_values(): void {
		$this->assertSame(
			array( 'kw' => '', 'event' => 0, 'year' => '' ),
			$this->filters( array() ),
			'An empty request is the unfiltered default.'
		);
		$filters = $this->filters( array( 'law_kw' => '  Ada  ', 'law_event' => '-12', 'law_year' => 'Year-2026' ) );
		$this->assertSame( 'Ada', $filters['kw'] );
		$this->assertSame( 12, $filters['event'], 'absint(), so a negative ID cannot reach the query.' );
		$this->assertSame( 'year-2026', $filters['year'], 'sanitize_key(), the shape a term slug already has.' );
	}

	public function test_export_is_one_row_per_appearance(): void {
		$f      = $this->fixture();
		$export = law_speakers_dashboard_export_rows( $this->filters( array( 'law_kw' => 'Dashboardspeaker' ) ) );

		$this->assertSame(
			array( 'Speaker ID', 'First name', 'Last name', 'Email', 'Website', 'Event', 'Event date', 'Reference', 'Event status', 'Role', 'Organisation', 'Job title', 'Biography' ),
			$export['columns']
		);
		$this->assertCount( 2, $export['rows'], 'One row per appearance, not per speaker.' );
		$this->assertStringContainsString( 'Dashboardspeaker', $export['title'] );

		// The name exports in two columns; this fixture's speaker has no stored
		// parts, so they come from splitting the post title.
		$this->assertSame( 'Ada', $export['rows'][0][1] );
		$this->assertSame( 'Dashboardspeaker', $export['rows'][0][2] );

		$roles = array_map( fn( $row ) => $row[9], $export['rows'] );
		sort( $roles );
		$this->assertSame( array( 'Host', 'Moderator' ), $roles, 'The role label, not the stored key.' );

		$statuses = array_map( fn( $row ) => $row[8], $export['rows'] );
		sort( $statuses );
		$this->assertSame( array( 'Confirmed', 'Proposed' ), $statuses );
	}

	public function test_export_still_lists_a_speaker_with_no_appearance(): void {
		$speaker = $this->make_speaker( 'Cee Noappearances' );
		law_speakers_flush_maps();

		$export = law_speakers_dashboard_export_rows( $this->filters( array( 'law_kw' => 'Noappearances' ) ) );
		$this->assertCount( 1, $export['rows'] );
		$this->assertSame( $speaker, $export['rows'][0][0] );
		$this->assertSame( '', $export['rows'][0][5], 'The event columns are blank rather than the speaker missing.' );
	}

	public function test_event_picker_lists_only_events_carrying_a_speaker(): void {
		$f     = $this->fixture();
		$empty = $this->make_event( array(), 'publish' );
		law_speakers_flush_maps();

		$events = law_speakers_dashboard_events();
		$this->assertArrayHasKey( $f['confirmed'], $events );
		$this->assertArrayHasKey( $f['proposed'], $events );
		$this->assertArrayNotHasKey( $empty, $events, 'An event with no speaker row is not offered.' );
		$this->assertStringContainsString( '(Proposed)', $events[ $f['proposed'] ], 'A non-Confirmed event names its status, since drafts and proposals show up here.' );
		$this->assertStringNotContainsString( '(', $events[ $f['confirmed'] ], 'A Confirmed event is just its title.' );
	}

	public function test_write_row_touches_only_that_speakers_row(): void {
		$one   = $this->make_speaker( 'Dee Onerow' );
		$two   = $this->make_speaker( 'Eee Tworow' );
		$event = $this->make_event( array(), 'publish' );
		law_event_update_meta(
			$event,
			'_law_speakers',
			array(
				array( 'speaker_id' => $one, 'organisation' => 'One LLP', 'job_title' => 'Counsel', 'sort' => 0 ),
				array( 'speaker_id' => $two, 'organisation' => 'Two LLP', 'job_title' => 'Clerk', 'sort' => 1 ),
			)
		);

		$changed = law_speakers_dashboard_write_row(
			$event,
			$two,
			array( 'role' => 'host', 'organisation' => 'Two Chambers', 'job_title' => 'Clerk', 'photo_id' => 0, 'bio' => '' )
		);
		sort( $changed );
		$this->assertSame( array( 'organisation', 'role' ), $changed, 'Only the fields that actually differ are reported, which is what the log line names.' );

		$rows = law_event_meta( $event, '_law_speakers' );
		$this->assertSame( $one, $rows[0]['speaker_id'], 'Row order is preserved.' );
		$this->assertSame( 'One LLP', $rows[0]['organisation'], "The other speaker's row is untouched." );
		$this->assertSame( '', $rows[0]['role'] );
		$this->assertSame( 'Two Chambers', $rows[1]['organisation'] );
		$this->assertSame( 'host', $rows[1]['role'] );
	}

	public function test_write_row_refuses_a_post_the_speaker_is_not_on(): void {
		$speaker = $this->make_speaker( 'Eff Notonit' );
		$event   = $this->make_event( array(), 'publish' );
		law_event_update_meta( $event, '_law_speakers', array() );

		$this->assertSame(
			array(),
			law_speakers_dashboard_write_row( $event, $speaker, array( 'organisation' => 'Nowhere LLP' ) ),
			'A forged event ID writes nothing.'
		);
		$this->assertSame( array(), law_event_meta( $event, '_law_speakers' ) );
	}

	public function test_requested_speaker_only_resolves_a_speaker_post(): void {
		$speaker = $this->make_speaker( 'Gee Requested' );
		$event   = $this->make_event( array(), 'publish' );

		$_GET['law_speaker'] = (string) $speaker;
		$this->assertSame( $speaker, law_speakers_dashboard_requested_speaker() );

		$_GET['law_speaker'] = (string) $event;
		$this->assertSame( 0, law_speakers_dashboard_requested_speaker(), 'An event ID is not a speaker.' );

		unset( $_GET['law_speaker'] );
		$this->assertSame( 0, law_speakers_dashboard_requested_speaker() );
	}

	/**
	 * The identity write behind "Save changes". Unlike law_speaker_upsert()'s
	 * gap-fill this replaces outright, because this screen is where a name is
	 * corrected and a wrong website cleared. post_name is deliberately left
	 * alone, so an existing /speakers/<slug>/ link survives a rename.
	 */
	public function test_the_dashboard_renames_a_speaker_and_keeps_the_profile_url(): void {
		$speaker       = law_speaker_upsert( array( 'first_name' => 'Dashboard', 'last_name' => 'Testbefore', 'email' => 'dashboard-rename@example.test', 'website' => 'https://wrong.example.test' ) );
		$this->posts[] = $speaker;
		$slug          = get_post( $speaker )->post_name;

		law_speakers_dashboard_write_identity(
			$speaker,
			array(
				'name'       => 'Dashboard Testafter',
				'first_name' => 'Dashboard',
				'last_name'  => 'Testafter',
				'email'      => 'dashboard-renamed@example.test',
				'website'    => '',
			)
		);

		$this->assertSame( 'Dashboard Testafter', get_post( $speaker )->post_title );
		$this->assertSame( array( 'first' => 'Dashboard', 'last' => 'Testafter' ), law_speaker_name_parts( $speaker ) );
		$this->assertSame( 'dashboard-renamed@example.test', (string) law_event_meta( $speaker, '_law_speaker_email' ) );
		$this->assertSame( '', (string) law_event_meta( $speaker, '_law_website' ), 'A wrong website can be cleared, which the gap-filling upsert could never do.' );
		$this->assertSame( $slug, get_post( $speaker )->post_name, 'The slug is untouched, so the old profile link keeps resolving.' );
	}

	/** An apostrophe must not turn into &#8217; when the screen saves it back. */
	public function test_the_dashboard_does_not_corrupt_an_apostrophe_on_an_untouched_save(): void {
		$speaker = wp_insert_post(
			array(
				'post_type'   => LAW_SPEAKER_CPT,
				'post_status' => 'publish',
				'post_title'  => "Niamh O'Testsullivan",
			)
		);
		$this->posts[] = $speaker;

		// Exactly what the edit view prefills, saved straight back.
		$parts = law_speaker_name_parts( $speaker );
		law_speakers_dashboard_write_identity(
			$speaker,
			array(
				'name'       => law_speaker_full_name( $parts['first'], $parts['last'] ),
				'first_name' => $parts['first'],
				'last_name'  => $parts['last'],
				'email'      => '',
				'website'    => '',
			)
		);

		$this->assertSame( "Niamh O'Testsullivan", get_post( $speaker )->post_title );
		$this->assertSame( "O'Testsullivan", (string) law_event_meta( $speaker, '_law_speaker_last_name' ) );
	}
}
