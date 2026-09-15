<?php
/**
 * The Events dashboard keyword box (?law_kw=).
 *
 * It was a bare WP_Query `s` until 15 September 2026, so it searched the event
 * post's title, excerpt and content and nothing else. The firm that runs an
 * event is meta, so typing a firm name returned nothing at all -- which is
 * what the client reported ("Can't Search by firm name", Emily O'Callaghan,
 * 15 September 2026). It now resolves to an explicit post__in ID set built by
 * law_committee_keyword_event_ids().
 *
 * The delicate part is not the matching, it is the two WP_Query behaviours the
 * new shape runs into: an empty post__in is IGNORED rather than matching
 * nothing, and post__not_in is ignored entirely once post__in is set. Both
 * would fail silently and wrongly -- the first by answering a typo with every
 * event on the site, the second by putting the flagship back on the committee's
 * review queue -- so both have a test here.
 */
class CommitteeSearchTest extends LAW_Test_Case {

	protected function tearDown(): void {
		unset( $_GET['law_kw'], $_GET['law_status'], $_GET['law_run_by'] );
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		parent::tearDown();
	}

	/** Point every flagship helper at a fixture, the way FlagshipTest does. */
	private function pin_flagship( int $event_id ): void {
		add_filter( 'law_flagship_event_id', fn() => $event_id );
		law_flagship_event_id( true );
	}

	/** Event IDs the dashboard returns for a keyword. */
	private function search( string $keyword, array $overrides = array() ): array {
		$_GET['law_kw'] = $keyword;
		$ids            = wp_list_pluck( law_committee_events( $overrides ), 'ID' );
		unset( $_GET['law_kw'] );
		return array_map( 'intval', $ids );
	}

	/** A tracked organisation post, for the linked-organisations limb. */
	private function make_organisation( string $title ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'organisation',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		$this->posts[] = (int) $post_id;
		return (int) $post_id;
	}

	/* The three haystacks ____________________________________________________ */

	public function test_a_firm_name_finds_the_events_that_firm_hosts(): void {
		$match = $this->make_event( array( '_law_host_organisations' => 'Mayer Brown' ) );
		$other = $this->make_event( array( '_law_host_organisations' => 'Hill Dickinson' ) );

		$ids = $this->search( 'Mayer Brown' );
		$this->assertContains( $match, $ids, 'The whole point of the change.' );
		$this->assertNotContains( $other, $ids );
	}

	public function test_a_partial_firm_name_matches_and_ignores_case(): void {
		// The live data is free text and inconsistent ("Evershed Sutherland"
		// sits next to "Eversheds Sutherland"), so a substring match is what
		// makes the box usable at all.
		$event = $this->make_event( array( '_law_host_organisations' => 'Norton Rose Fulbright LLP' ) );
		$this->assertContains( $event, $this->search( 'norton rose' ) );
	}

	public function test_one_of_several_semicolon_separated_firms_matches(): void {
		$event = $this->make_event(
			array( '_law_host_organisations' => 'Alvarez & Marsal; 39 Essex Chambers' )
		);
		$this->assertContains( $event, $this->search( '39 Essex' ) );
	}

	public function test_the_title_search_still_works(): void {
		$event = $this->make_event();
		$title = get_post( $event )->post_title;
		$this->assertContains( $event, $this->search( $title ), 'Core search must not regress.' );
	}

	public function test_a_linked_organisation_is_matched_by_its_name(): void {
		// _law_organisation_ids holds post IDs in one serialised row, so the
		// name has to be resolved before it can be matched.
		$org   = $this->make_organisation( 'Searchable Chambers' );
		$event = $this->make_event();
		law_event_update_meta( $event, '_law_organisation_ids', array( $org ) );

		$this->assertContains( $event, $this->search( 'Searchable Chambers' ) );
	}

	/* The two WP_Query traps _________________________________________________ */

	public function test_a_keyword_nothing_answers_returns_nothing_not_everything(): void {
		$event = $this->make_event( array( '_law_host_organisations' => 'Mayer Brown' ) );

		$ids = $this->search( 'Zzzz No Such Firm Anywhere Zzzz' );
		$this->assertSame( array(), $ids, 'An empty post__in is skipped by WP_Query, not applied.' );
		$this->assertNotContains( $event, $ids );
	}

	public function test_the_flagship_stays_out_of_a_keyword_search(): void {
		$flagship = $this->make_event( array( '_law_host_organisations' => 'Flagship Firm' ), 'publish' );
		$this->pin_flagship( $flagship );

		$ids = $this->search( 'Flagship Firm' );
		$this->assertNotContains(
			$flagship,
			$ids,
			'post__not_in is ignored once post__in is set, so the exclusion has to be subtracted from the ID set.'
		);
	}

	public function test_the_timeline_can_still_ask_the_flagship_back_with_a_keyword(): void {
		$flagship = $this->make_event( array( '_law_host_organisations' => 'Flagship Firm' ), 'publish' );
		$this->pin_flagship( $flagship );

		$ids = $this->search( 'Flagship Firm', array( 'law_include_flagship' => true ) );
		$this->assertContains( $flagship, $ids );
	}

	/* The status rules the search must not loosen ____________________________ */

	public function test_a_host_draft_is_not_findable_by_keyword(): void {
		// The 2026-09-07 security finding: drafts are owner-only, unsubmitted
		// host data, and the export inherits this query wholesale.
		$draft = $this->make_event( array( '_law_host_organisations' => 'Draftonly Chambers' ), 'law-draft' );

		$this->assertNotContains( $draft, $this->search( 'Draftonly Chambers' ) );

		$_GET['law_status'] = 'law-draft';
		$ids                = $this->search( 'Draftonly Chambers' );
		unset( $_GET['law_status'] );
		$this->assertNotContains( $draft, $ids, 'An explicit ?law_status=law-draft must not open them either.' );
	}

	public function test_an_external_draft_is_findable_by_keyword(): void {
		// The committee owns this one, and it is fetched by the second, merged
		// query -- which has to inherit the keyword's post__in too.
		$external = $this->make_event( array( '_law_host_organisations' => 'Externally Hosted LLP' ), 'law-draft' );
		law_event_update_meta( $external, '_law_is_external', 1 );

		$this->assertContains( $external, $this->search( 'Externally Hosted LLP' ) );
	}

	public function test_the_keyword_and_the_run_by_filter_apply_together(): void {
		$external = $this->make_event( array( '_law_host_organisations' => 'Shared Name LLP' ), 'publish' );
		law_event_update_meta( $external, '_law_is_external', 1 );
		$hosted = $this->make_event( array( '_law_host_organisations' => 'Shared Name LLP' ), 'publish' );

		$_GET['law_run_by'] = 'external';
		$ids                = $this->search( 'Shared Name LLP' );
		unset( $_GET['law_run_by'] );

		$this->assertContains( $external, $ids );
		$this->assertNotContains( $hosted, $ids );
	}

	/* The export _____________________________________________________________ */

	public function test_the_export_carries_the_host_organisations_column(): void {
		$columns = law_committee_export_columns();
		$event   = $this->make_event( array( '_law_host_organisations' => 'Exported & Partners LLP' ) );

		$row = law_committee_export_row( get_post( $event ) );
		$this->assertSameSize( $columns, $row, 'The row and the header must stay the same length.' );

		$by_column = array_combine( $columns, $row );
		$this->assertSame( 'Exported & Partners LLP', $by_column['Host organisation(s)'] );
	}
}
