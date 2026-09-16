<?php
/**
 * Search-hit highlighting on the committee's Events dashboard.
 *
 * The programme's cards learned on 16 September 2026 to show WHY a row survived
 * the filter; the committee's table and timeline were asked for the same thing
 * the same day (Denis). Not a second implementation: they call
 * law_calendar_highlight() and wear the same mark.law-hit.
 *
 * THE RULE IS THE WHOLE PHRASE, case-insensitively. Marking the keyword's
 * individual words was built first and reversed within the hour: the box is
 * used with long phrases lifted off a title, and "Collaboration with Arbitral
 * Institutions in" came back with every "in" and "with" in two columns marked.
 * The price is pinned below -- a row the SEARCH matched word by word can show
 * with nothing marked -- so it stays a decision rather than turning into a bug
 * report later.
 */
class CommitteeHighlightTest extends LAW_Test_Case {

	protected function tearDown(): void {
		unset( $_GET['law_kw'], $_GET['law_status'], $_GET['law_run_by'] );
		parent::tearDown();
	}

	/** parts/events/dashboard-list.php rendered for one keyword. */
	private function render_list( string $keyword ): string {
		$_GET['law_kw'] = $keyword;
		ob_start();
		get_template_part( 'parts/events/dashboard-list' );
		$html = (string) ob_get_clean();
		unset( $_GET['law_kw'] );
		return $html;
	}

	private function make_named_user( string $first, string $last ): int {
		$user_id = $this->make_user();
		wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => $first . ' ' . $last,
			)
		);
		return $user_id;
	}

	/* The exact-phrase rule __________________________________________________ */

	public function test_the_phrase_is_marked_whole(): void {
		$this->assertSame(
			'<mark class="law-hit">Emma Higgins</mark>',
			law_calendar_highlight( 'Emma Higgins', 'emma higgins' )
		);
	}

	public function test_the_words_of_a_phrase_are_not_marked_on_their_own(): void {
		// The reversal, pinned. Before it, this returned the title with four
		// marks in it and the committee could not read the row.
		$html = law_calendar_highlight(
			'Collaboration with Arbitral Institutions in Africa',
			'Collaboration with Arbitral Institutions in Kenya'
		);
		$this->assertStringNotContainsString( '<mark', $html );
	}

	public function test_a_two_word_keyword_marks_nothing_where_the_order_differs(): void {
		// "higgins emma" is a legitimate search -- the host-name limb requires
		// every word in any order -- and this field answers it without printing
		// it contiguously. Nothing is marked, deliberately.
		$this->assertSame(
			'Emma Higgins',
			law_calendar_highlight( 'Emma Higgins', 'higgins emma' )
		);
	}

	/* Through the dashboard table ____________________________________________ */

	public function test_the_title_the_host_and_the_firm_are_all_marked(): void {
		$host  = $this->make_named_user( 'Zephrania', 'Quillingsworth' );
		$event = $this->make_event(
			array( '_law_host_organisations' => 'Quillingsworth Chambers' ),
			'law-proposed',
			$host
		);
		wp_update_post( array( 'ID' => $event, 'post_title' => 'Quillingsworth on costs' ) );

		$html = $this->render_list( 'quillingsworth' );
		// Once in the title, once in the host's name, once in the firm: every
		// field the row prints that the keyword box also searches says so.
		$this->assertSame( 3, substr_count( $html, '<mark class="law-hit">Quillingsworth</mark>' ) );
	}

	public function test_the_mark_keeps_the_original_case(): void {
		$host  = $this->make_named_user( 'Zephrania', 'Quillingsworth' );
		$event = $this->make_event( array(), 'law-proposed', $host );
		wp_update_post( array( 'ID' => $event, 'post_title' => 'Quillingsworth on costs' ) );

		$this->assertStringContainsString(
			'<mark class="law-hit">Quillingsworth</mark>',
			$this->render_list( 'QUILLINGSWORTH' )
		);
	}

	public function test_a_phrase_spanning_the_title_is_marked_in_one_piece(): void {
		$event = $this->make_event();
		wp_update_post( array( 'ID' => $event, 'post_title' => 'Quillingsworth on costs and funding' ) );

		$html = $this->render_list( 'on costs and' );
		$this->assertStringContainsString( '<mark class="law-hit">on costs and</mark>', $html );
		$this->assertSame( 1, substr_count( $html, '<mark' ), 'One phrase, one mark.' );
	}

	public function test_no_keyword_leaves_the_table_unmarked(): void {
		$this->make_event();
		$this->assertStringNotContainsString( '<mark', $this->render_list( '' ) );
	}

	public function test_a_row_matched_on_its_description_explains_itself_in_a_snippet(): void {
		// No column prints the description, so until the snippet existed this
		// row named nothing the committee had typed. The columns still mark
		// nothing; the snippet under the reference does the explaining.
		$event = $this->make_event();
		wp_update_post(
			array(
				'ID'           => $event,
				'post_title'   => 'Annual review',
				'post_content' => 'A morning on Quillingsworth compliance.',
			)
		);

		$html = $this->render_list( 'quillingsworth' );
		$this->assertStringContainsString( 'Annual review', $html );
		$this->assertStringContainsString( 'law-dashboard__snippet-row', $html );
		$this->assertSame( 1, substr_count( $html, '<mark' ), 'Only the snippet marks anything.' );
	}

	public function test_a_row_matched_on_a_field_no_surface_prints_still_marks_nothing(): void {
		// The linked-organisation limb: matched by the name of an organisation
		// post, which appears in no column and in no description. Accepted, and
		// pinned so it is not "fixed" by widening the highlighter back to words.
		$org   = wp_insert_post(
			array( 'post_type' => 'organisation', 'post_status' => 'publish', 'post_title' => 'Quillingsworth Chambers' )
		);
		$event = $this->make_event();
		$this->posts[] = (int) $org;
		wp_update_post( array( 'ID' => $event, 'post_title' => 'Annual review', 'post_content' => 'A quiet morning.' ) );
		law_event_update_meta( $event, '_law_organisation_ids', array( (int) $org ) );

		$html = $this->render_list( 'quillingsworth chambers' );
		$this->assertStringContainsString( 'Annual review', $html );
		$this->assertStringNotContainsString( '<mark', $html );
	}
}
