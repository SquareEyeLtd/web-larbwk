<?php
/**
 * The single event view's layout (Denis, 9 and 11 September 2026). The main
 * column reads description, sessions, venue: the address is a detail the reader
 * needs once, so it goes last, below the running order. The speakers are no
 * longer part of that run. They sit beside it, either in their own column to
 * the right of it (an event without sessions) or beside the description of the
 * session they speak in. The back / register row then sits outside both
 * columns, under them, so the way off the page is the full width of the
 * article.
 *
 * Asserted against the source of parts/calendar-body.php rather than a render,
 * because that partial calls get_header() and brings a whole page with it (the
 * same reason FlagshipRenderTest pins its template's contract this way).
 */
class EventSectionOrderTest extends LAW_Test_Case {

	private function body(): string {
		return (string) file_get_contents( get_theme_file_path( 'parts/calendar-body.php' ) );
	}

	public function test_the_main_column_reads_description_sessions_venue(): void {
		$body = $this->body();

		$description = strpos( $body, 'law-cal-detail__body' );
		$timeline    = strpos( $body, "'parts/events/session-timeline'" );
		$venue       = strpos( $body, "'parts/events/event-venue'" );

		$this->assertNotFalse( $description, 'The description body renders.' );
		$this->assertNotFalse( $timeline, 'The sessions render.' );
		$this->assertNotFalse( $venue, 'The Venue section renders.' );

		$this->assertGreaterThan( $description, $timeline, 'Sessions follow the description.' );
		$this->assertGreaterThan( $timeline, $venue, 'The venue is last in the column.' );
	}

	/**
	 * One session layout, so no caller can collapse a running order again: every
	 * event renders its sessions open on the timeline, and the accordion the
	 * ordinary event view used to carry is gone along with the style switch that
	 * chose between the two (Denis, 11 September 2026). Two layouts for the same
	 * four sessions was a difference with no reason behind it.
	 */
	public function test_sessions_have_exactly_one_layout(): void {
		$body = $this->body();
		$css  = (string) file_get_contents( get_theme_file_path( 'assets/css/calendar.css' ) );

		$this->assertSame( 1, substr_count( $body, "'parts/events/session-timeline'" ) );
		$this->assertStringNotContainsString( 'law_cal_sessions_style', $body );
		$this->assertStringNotContainsString( 'law-cal-session__', $body, 'The accordion markup is gone.' );
		$this->assertStringNotContainsString( 'law-cal-session__', $css, 'And so are its styles.' );
		$this->assertStringNotContainsString(
			'law_cal_sessions_style',
			(string) file_get_contents( get_theme_file_path( 'templates/flagship-event.php' ) )
		);
	}

	/**
	 * The speakers are a column beside the main one, not a section under it, and
	 * it renders only for an event without sessions: a session lists its own
	 * speakers beside its description, so a sidebar copy would print every card
	 * (and its bio dialog) twice on the page.
	 */
	public function test_the_speakers_sit_in_their_own_column(): void {
		$body = $this->body();

		$main     = strpos( $body, 'law-cal-detail__main' );
		$venue    = strpos( $body, "'parts/events/event-venue'" );
		$sidebar  = strpos( $body, 'law-cal-detail__sidebar' );
		$speakers = strpos( $body, '>Speakers<' );

		$this->assertNotFalse( $main, 'The main column carries its own class.' );
		$this->assertNotFalse( $sidebar, 'The speakers column renders.' );
		$this->assertNotFalse( $speakers, 'The Speakers heading renders.' );

		$this->assertGreaterThan( $venue, $sidebar, 'The column opens after the main one closes.' );
		$this->assertGreaterThan( $sidebar, $speakers, 'And the heading is inside it.' );
		$this->assertStringContainsString(
			"\$law_cal_speakers_aside = empty( \$event['sessions'] ) && ! empty( \$event['speakers'] );",
			$body,
			'The column is gated on the event having no sessions.'
		);
	}

	/**
	 * A session reads top to bottom at the full width of the column: title,
	 * description, then the people speaking at it as a grid of three (Denis,
	 * 14 September 2026). The speakers used to sit in a right-hand column from
	 * 64em, which narrowed the prose to about 60% of the page and stacked the
	 * cards in a single file down the rest.
	 */
	public function test_each_session_puts_its_speakers_under_its_description(): void {
		$timeline = (string) file_get_contents( get_theme_file_path( 'parts/events/session-timeline.php' ) );
		$css      = (string) file_get_contents( get_theme_file_path( 'assets/css/calendar.css' ) );

		$this->assertStringNotContainsString( 'law-timeline__content--split', $timeline, 'The two-column split is gone from the markup.' );
		$this->assertStringNotContainsString( 'law-timeline__content--split', $css, 'And from the stylesheet, so nothing can set it back.' );

		// The speakers follow the description in the source, inside the same
		// full-width content block.
		$body     = strpos( $timeline, 'law-timeline__body' );
		$speakers = strpos( $timeline, 'law-timeline__speakers' );
		$this->assertNotFalse( $body );
		$this->assertNotFalse( $speakers );
		$this->assertGreaterThan( $body, $speakers, 'The cards come after the prose.' );

		// Two abreast on a desktop, and one per row below 64em: the single file
		// overrides the two-per-row .law-cal-speakers--cards gets from 48em.
		$this->assertMatchesRegularExpression(
			'/\.law-timeline__speakers \{\s*\n\s*grid-template-columns: minmax\(0, 1fr\);/',
			$css
		);
		$this->assertMatchesRegularExpression(
			'/min-width: 64em\) \{\s*\n\s*\.law-timeline__speakers \{\s*\n\s*grid-template-columns: repeat\(2, minmax\(0, 1fr\)\);/',
			$css
		);

		// And no measure on the prose: the description fills the column.
		$this->assertDoesNotMatchRegularExpression(
			'/\.law-timeline__body \{[^}]*max-width/',
			$css
		);
	}

	/**
	 * The back / register row is outside .grid-x, under both columns.
	 */
	public function test_the_back_and_register_row_sits_under_both_columns(): void {
		$body = $this->body();

		$sidebar = strpos( $body, 'law-cal-detail__sidebar' );
		$foot    = strpos( $body, 'law-cal-detail__foot' );

		$this->assertNotFalse( $foot, 'The foot row renders.' );
		$this->assertGreaterThan( $sidebar, $foot, 'After both columns, so outside the grid.' );
		$this->assertStringContainsString(
			'.law-cal-detail__foot {',
			(string) file_get_contents( get_theme_file_path( 'assets/css/calendar.css' ) ),
			'And it carries the gutter the cells above get from .grid-padding-x.'
		);
	}

	/**
	 * One venue position, so no caller can put it back above the sessions: the
	 * flagship page used to do exactly that through $law_cal_venue_last, and two
	 * orders for the same four sections was a difference with no reason behind it.
	 */
	public function test_the_venue_has_exactly_one_position(): void {
		$this->assertSame( 1, substr_count( $this->body(), "'parts/events/event-venue'" ) );
		$this->assertStringNotContainsString( 'law_cal_venue_last', $this->body() );
		$this->assertStringNotContainsString(
			'law_cal_venue_last',
			(string) file_get_contents( get_theme_file_path( 'templates/flagship-event.php' ) )
		);
	}

	/**
	 * The facts box in the hero still links down to the Venue section, which is
	 * what makes putting the address at the foot of the page cost nothing.
	 */
	public function test_the_details_box_still_links_to_the_venue_section(): void {
		$details = (string) file_get_contents( get_theme_file_path( 'parts/calendar-event-details.php' ) );
		$this->assertStringContainsString( '#law-cal-venue-heading', $details );
		$this->assertStringContainsString(
			'law-cal-venue-heading',
			(string) file_get_contents( get_theme_file_path( 'parts/events/event-venue.php' ) ),
			'And the section carries that id as its target.'
		);
	}
}
