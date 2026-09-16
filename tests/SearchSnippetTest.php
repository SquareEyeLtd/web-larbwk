<?php
/**
 * The search-result snippet (law_calendar_search_snippet()).
 *
 * Both keyword boxes search the description and neither surface printed a word
 * of it, so a search for a term that lives only in the body text returned cards
 * and rows naming nothing the searcher had typed (Denis, 16 September 2026).
 * Marking the printed fields was the first half of that; this is the other.
 *
 * The rules being held down here: nothing is returned unless the keyword is
 * really in the description (so the line is an explanation, never a truncated
 * description), the window is cut on word boundaries, an ellipsis marks each
 * edge that is not the true start or end, and the marking itself is delegated
 * to law_calendar_highlight() so the snippet obeys the same exact-phrase rule
 * as every other surface.
 */
class SearchSnippetTest extends LAW_Test_Case {

	/** Long enough that a 170-character window cannot reach either end. */
	private const LONG = 'Arbitration practitioners from across the City will look at how tribunals handle disclosure in complex construction disputes, including the treatment of privileged material, the practical consequences for costs orders, and what the recent awards mean for parties funding a claim from abroad.';

	protected function tearDown(): void {
		unset( $_GET['law_kw'] );
		parent::tearDown();
	}

	/* When there is nothing to say ___________________________________________ */

	public function test_no_keyword_means_no_snippet(): void {
		$this->assertSame( '', law_calendar_search_snippet( self::LONG, '' ) );
		$this->assertSame( '', law_calendar_search_snippet( self::LONG, '   ' ) );
	}

	public function test_a_keyword_absent_from_the_description_means_no_snippet(): void {
		// The caller prints nothing at all on this return value, which is why it
		// is '' and not a truncated description: a card matched on its title
		// alone must not sprout a random opening line.
		$this->assertSame( '', law_calendar_search_snippet( self::LONG, 'insolvency' ) );
	}

	public function test_an_empty_description_means_no_snippet(): void {
		$this->assertSame( '', law_calendar_search_snippet( '', 'disclosure' ) );
		$this->assertSame( '', law_calendar_search_snippet( '<p>  </p>', 'disclosure' ) );
	}

	/* The window _____________________________________________________________ */

	public function test_the_hit_is_marked_inside_the_snippet(): void {
		$this->assertStringContainsString(
			'<mark class="law-hit">disclosure</mark>',
			law_calendar_search_snippet( self::LONG, 'DISCLOSURE' )
		);
	}

	public function test_both_edges_are_elided_when_the_text_runs_past_them(): void {
		$snippet = law_calendar_search_snippet( self::LONG, 'privileged' );
		$this->assertStringStartsWith( '… ', $snippet );
		$this->assertStringEndsWith( ' …', $snippet );
	}

	public function test_a_hit_near_the_start_gets_no_leading_ellipsis(): void {
		$snippet = law_calendar_search_snippet( self::LONG, 'Arbitration' );
		$this->assertStringStartsWith( '<mark', $snippet );
	}

	public function test_a_description_shorter_than_the_window_is_shown_whole(): void {
		$snippet = law_calendar_search_snippet( '<p>A morning on sanctions compliance.</p>', 'sanctions' );
		$this->assertSame( 'A morning on <mark class="law-hit">sanctions</mark> compliance.', $snippet );
	}

	public function test_neither_edge_lands_in_the_middle_of_a_word(): void {
		$snippet = wp_strip_all_tags( law_calendar_search_snippet( self::LONG, 'privileged' ) );
		$snippet = trim( str_replace( '…', '', $snippet ) );
		$plain   = law_rich_text_plain( self::LONG );

		// Every word of the snippet is a whole word of the description.
		foreach ( preg_split( '/\s+/', $snippet ) as $word ) {
			$this->assertStringContainsString( ' ' . $word . ' ', ' ' . $plain . ' ', "Cut mid-word: {$word}" );
		}
	}

	public function test_a_long_phrase_is_not_cut_off_inside_its_own_mark(): void {
		$phrase  = 'the practical consequences for costs orders';
		$snippet = law_calendar_search_snippet( self::LONG, $phrase );
		$this->assertStringContainsString( '<mark class="law-hit">' . $phrase . '</mark>', $snippet );
	}

	public function test_a_long_phrase_still_marks_inside_a_short_window(): void {
		// The regression that shipped for an hour: the lead-in was spent out of
		// the same budget as the phrase, so a 62-character keyword in a
		// 110-character window was cropped mid-phrase and came back with nothing
		// marked, while the programme's roomier window looked fine (Denis,
		// 16 September 2026). $chars is a floor, not a cap.
		$phrase  = 'the treatment of privileged material, the practical consequences for costs';
		$snippet = law_calendar_search_snippet( self::LONG, $phrase, 110 );
		$this->assertStringContainsString( '<mark class="law-hit">' . $phrase . '</mark>', $snippet );
	}

	/* Rich text ______________________________________________________________ */

	public function test_block_boundaries_become_spaces_rather_than_running_together(): void {
		// wp_strip_all_tags() alone turns a bulleted list into one long word.
		$snippet = law_calendar_search_snippet(
			'<ul><li>Security for costs</li><li>Funding trends</li></ul>',
			'costs'
		);
		$this->assertStringContainsString( 'costs</mark> Funding', $snippet );
	}

	public function test_markup_in_the_description_cannot_reach_the_page(): void {
		$snippet = law_calendar_search_snippet(
			'<p>A note on <em>disclosure</em> and <script>alert(1)</script> obligations.</p>',
			'disclosure'
		);
		$this->assertStringNotContainsString( '<script', $snippet );
		$this->assertStringNotContainsString( '<em>', $snippet );
	}

	public function test_an_entity_in_the_description_is_escaped_exactly_once(): void {
		$snippet = law_calendar_search_snippet( '<p>Costs &amp; funding in practice.</p>', 'funding' );
		$this->assertStringContainsString( 'Costs &amp; ', $snippet );
		$this->assertStringNotContainsString( '&amp;amp;', $snippet );
	}

	/* Through the two surfaces _______________________________________________ */

	private function make_listed_event( string $title, string $content ): int {
		$date     = array_key_first( law_calendar_week_days() );
		$event_id = $this->make_event(
			array( '_law_start' => $date . ' 12:00', '_law_end' => $date . ' 13:00' ),
			'publish'
		);
		wp_update_post( array( 'ID' => $event_id, 'post_title' => $title, 'post_content' => $content ) );
		law_calendar_reset_caches();
		return $event_id;
	}

	public function test_the_programme_card_prints_the_snippet(): void {
		$event_id = $this->make_listed_event( 'Annual review', '<p>' . self::LONG . '</p>' );

		ob_start();
		get_template_part(
			'parts/loop/event',
			null,
			array(
				'event'     => law_events_map_post( get_post( $event_id ) ),
				'highlight' => 'privileged material',
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'law-event-card__snippet', $html );
		$this->assertStringContainsString( '<mark class="law-hit">privileged material</mark>', $html );
	}

	public function test_a_card_whose_hit_is_in_the_title_grows_no_snippet(): void {
		$event_id = $this->make_listed_event( 'Disclosure in practice', '<p>' . self::LONG . '</p>' );

		ob_start();
		get_template_part(
			'parts/loop/event',
			null,
			array( 'event' => law_events_map_post( get_post( $event_id ) ), 'highlight' => 'in practice' )
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<mark class="law-hit">in practice</mark>', $html );
		$this->assertStringNotContainsString( 'law-event-card__snippet', $html );
	}

	public function test_the_committee_table_prints_the_snippet(): void {
		$event = $this->make_event();
		wp_update_post(
			array( 'ID' => $event, 'post_title' => 'Annual review', 'post_content' => '<p>' . self::LONG . '</p>' )
		);

		$_GET['law_kw'] = 'privileged material';
		ob_start();
		get_template_part( 'parts/events/dashboard-list' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'law-dashboard__snippet-row', $html );
		$this->assertStringContainsString( '<mark class="law-hit">privileged material</mark>', $html );
	}

	public function test_the_snippet_is_styled_on_both_surfaces_and_on_the_navy_card(): void {
		// The flagship's card prints white text on navy, so a grey snippet would
		// be 2.2:1 there.
		$calendar = (string) file_get_contents( get_theme_file_path( '/assets/css/calendar.css' ) );
		$this->assertStringContainsString( '.law-event-card__snippet', $calendar );
		$this->assertStringContainsString( '.law-event-card--flagship .law-event-card__snippet', $calendar );
		$form = (string) file_get_contents( get_theme_file_path( '/assets/css/event-form.css' ) );
		$this->assertStringContainsString( '.law-dashboard__snippet-cell', $form );
		// The snippet row makes an event two <tr>s, so the stripe cannot be
		// Foundation's even/odd any more.
		$this->assertStringContainsString( '.law-dashboard__table--striped tbody tr.is-alt', $form );
		// Scoped to the modifier: eight other dashboard tables share the base
		// class and must keep Foundation's own striping.
		$this->assertStringNotContainsString( '.law-dashboard__table tbody tr:nth-child(even)', $form );
	}
}
