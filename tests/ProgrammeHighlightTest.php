<?php
/**
 * Marking the search term in the programme's results (Denis, 16 September 2026).
 *
 * Filtering told a visitor which cards survived but never why, so a search for
 * "gar live" returned a page of cards with nothing on any of them pointing at
 * the words that matched. law_calendar_highlight() wraps the hits in <mark>.
 *
 * The properties worth pinning are the ones that break quietly:
 *
 * 1. A field with NO hit must render byte for byte what it rendered before the
 *    function existed. parts/loop/event.php is shared with the speaker profile,
 *    My events and My bookings, and a regression there would be invisible until
 *    somebody noticed a mangled ampersand.
 * 2. The offsets must survive normalisation. The filter matches against
 *    law_calendar_normalise_choice(), which decodes entities and collapses
 *    whitespace, and both change the string's length. A naive strpos() on the
 *    raw title passes every easy case here and puts the mark in the wrong place
 *    on "Banking &amp; Finance".
 * 3. Nothing the visitor types may escape the mark.
 * 4. The card must never reach for ?law_kw= itself, because four other
 *    dashboards use that query var.
 */
class ProgrammeHighlightTest extends LAW_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		law_calendar_reset_caches();
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_option_law_events_source' );
		law_calendar_reset_caches();
		$_GET = array();
		parent::tearDown();
	}

	/* The helper on its own _______________________________________________ */

	public function test_no_keyword_returns_exactly_what_esc_html_returns(): void {
		foreach ( array( 'Plain title', 'Alvarez & Marsal', 'Banking &amp; Finance', 'A "quoted" <b>thing</b>', 'Évènement spécial' ) as $text ) {
			$this->assertSame(
				esc_html( $text ),
				law_calendar_highlight( $text, '' ),
				'Every non-searching caller must render unchanged markup.'
			);
		}
	}

	public function test_a_keyword_absent_from_the_field_leaves_it_untouched(): void {
		// The card is on screen because the filter searches things the card does
		// not print (the type, a sector, the description). Accepted behaviour:
		// no mark, and the field is not even normalised on the way out.
		$this->assertSame(
			esc_html( 'Banking &amp; Finance' ),
			law_calendar_highlight( 'Banking &amp; Finance', 'arbitration' )
		);
	}

	public function test_the_match_keeps_its_original_case(): void {
		// Denis's own example.
		$this->assertSame(
			'<mark class="law-hit">GAR Live</mark>: Women in Arbitration 2026',
			law_calendar_highlight( 'GAR Live: Women in Arbitration 2026', 'Gar live' )
		);
	}

	public function test_every_occurrence_in_one_field_is_marked(): void {
		$out = law_calendar_highlight( 'Law Rocks! Law Rocks!', 'law rocks' );
		$this->assertSame( 2, substr_count( $out, '<mark' ) );
	}

	public function test_adjacent_occurrences_do_not_nest(): void {
		$out = law_calendar_highlight( 'aaaa', 'aa' );
		$this->assertSame( 2, substr_count( $out, '<mark' ) );
		$this->assertSame( 2, substr_count( $out, '</mark>' ) );
		$this->assertStringNotContainsString( '<mark class="law-hit"><mark', $out, 'Resuming at $at + 1 would nest the marks.' );
	}

	public function test_a_stored_entity_matches_the_decoded_keyword(): void {
		// THE offset trap. The filter's haystack has "&amp;" decoded to one
		// character; the raw title still has five. Any implementation that
		// searches the raw string fails here, and only here.
		$this->assertSame(
			'<mark class="law-hit">Alvarez &amp; M</mark>arsal',
			law_calendar_highlight( 'Alvarez &amp; Marsal', 'alvarez & m' )
		);
	}

	public function test_an_ampersand_is_escaped_exactly_once(): void {
		$out = law_calendar_highlight( 'Banking & Finance', 'banking' );
		$this->assertSame( 1, substr_count( $out, '&amp;' ) );
		$this->assertStringNotContainsString( '&amp;amp;', $out );
	}

	public function test_html_in_the_keyword_cannot_escape_the_mark(): void {
		// sanitize_text_field() in law_calendar_filters() strips this long before
		// the helper sees it, which is exactly why the helper is called directly
		// here: it must not depend on an upstream guard it does not own.
		$out = law_calendar_highlight( 'Mayer <script>alert(1)</script> Brown', '<script>' );
		$this->assertStringNotContainsString( '<script', $out );
		$this->assertStringContainsString( '&lt;script&gt;', $out );
	}

	public function test_regex_metacharacters_match_literally(): void {
		$this->assertStringContainsString( '<mark class="law-hit">a.b</mark>', law_calendar_highlight( 'a.b', 'a.b' ) );
		$this->assertStringNotContainsString( '<mark', law_calendar_highlight( 'axb', 'a.b' ) );
	}

	public function test_multibyte_text_is_sliced_on_characters(): void {
		$this->assertSame(
			'<mark class="law-hit">Évènement</mark> spécial',
			law_calendar_highlight( 'Évènement spécial', 'ÉVÈNEMENT' )
		);
	}

	public function test_collapsed_whitespace_matches_the_way_the_filter_does(): void {
		$this->assertStringContainsString(
			'<mark class="law-hit">Three Crowns</mark>',
			law_calendar_highlight( "Three  Crowns   LLP", 'three crowns' )
		);
	}

	/* Through the card _____________________________________________________ */

	/** parts/loop/event.php rendered for one mapped event. */
	private function render_card( int $event_id, array $args = array() ): string {
		ob_start();
		get_template_part(
			'parts/loop/event',
			null,
			array_merge( array( 'event' => law_events_map_post( get_post( $event_id ) ) ), $args )
		);
		return (string) ob_get_clean();
	}

	private function make_listed_event( string $title, array $meta = array() ): int {
		$date     = array_key_first( law_calendar_week_days() );
		$event_id = $this->make_event(
			array_merge( array( '_law_start' => $date . ' 12:00', '_law_end' => $date . ' 13:00' ), $meta ),
			'publish'
		);
		wp_update_post( array( 'ID' => $event_id, 'post_title' => $title ) );
		law_calendar_reset_caches();
		return $event_id;
	}

	public function test_the_title_the_venue_and_the_host_are_all_marked(): void {
		$event_id = $this->make_listed_event(
			'GAR Live: Women in Arbitration 2026',
			array( '_law_venue' => 'Mayer Brown, 201 Bishopsgate', '_law_host_organisations' => 'Mayer Brown' )
		);

		$html = $this->render_card( $event_id, array( 'highlight' => 'mayer brown' ) );
		// Twice: once in the venue line, once in the host line. Every field the
		// card prints that the filter also searches explains itself.
		$this->assertSame( 2, substr_count( $html, '<mark class="law-hit">' ) );

		$html = $this->render_card( $event_id, array( 'highlight' => 'gar live' ) );
		$this->assertStringContainsString( '<mark class="law-hit">GAR Live</mark>', $html );
	}

	public function test_the_shared_card_never_reaches_for_the_query_var_itself(): void {
		// The one that matters most later. parts/loop/event.php is rendered by
		// the speaker profile, My events and My bookings, and ?law_kw= is the
		// query var for four unrelated dashboards, so a card that read it would
		// highlight itself on pages nobody asked about.
		$event_id       = $this->make_listed_event( 'Arbitration in practice' );
		$_GET['law_kw'] = 'arbitration';
		law_calendar_reset_caches();

		$this->assertStringNotContainsString( '<mark', $this->render_card( $event_id ) );
	}

	public function test_a_card_matched_only_on_its_description_explains_itself_in_a_snippet(): void {
		// Until the snippet existed (16 September 2026) this card came back with
		// nothing marked at all, because the filter searches six things and the
		// card printed three. The title, the venue and the host still carry no
		// mark -- the hit is in none of them -- so the snippet is the only thing
		// on the card that can explain why it survived the filter.
		$event_id = $this->make_listed_event( 'Annual review' );
		wp_update_post( array( 'ID' => $event_id, 'post_content' => 'A morning on sanctions compliance.' ) );
		law_calendar_reset_caches();

		$html = $this->render_card( $event_id, array( 'highlight' => 'sanctions' ) );
		$this->assertStringContainsString( 'law-event-card__snippet', $html );
		$this->assertSame( 1, substr_count( $html, '<mark' ), 'Only the snippet marks anything.' );
		$this->assertStringNotContainsString( '<mark', explode( 'law-event-card__snippet', $html )[0] );
	}

	public function test_the_programme_passes_the_keyword_down_to_the_cards(): void {
		$this->make_listed_event( 'GAR Live: Women in Arbitration 2026' );
		$_GET['law_kw'] = 'gar live';
		law_calendar_reset_caches();

		ob_start();
		get_template_part( 'parts/calendar-events', null, array( 'show_status' => false ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<mark class="law-hit">GAR Live</mark>', $html );
	}

	public function test_the_highlight_class_is_styled_site_wide(): void {
		// One treatment, shared with the speakers archive's client-side
		// highlighter, declared in the one stylesheet loaded on every page.
		$css = (string) file_get_contents( get_theme_file_path( '/assets/css/app.css' ) );
		$this->assertStringContainsString( 'mark.law-hit', $css );
		$this->assertStringContainsString(
			"mark.className = 'law-hit'",
			(string) file_get_contents( get_theme_file_path( '/assets/js/speaker-search.js' ) ),
			'The speakers archive emits the same class, or the consolidation has come apart.'
		);
	}
}
