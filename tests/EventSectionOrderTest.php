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
		$sessions    = strpos( $body, '<section class="law-cal-sessions"' );
		$venue       = strpos( $body, "'parts/events/event-venue'" );

		$this->assertNotFalse( $description, 'The description body renders.' );
		$this->assertNotFalse( $timeline, 'The timeline session style renders.' );
		$this->assertNotFalse( $sessions, 'The accordion session style renders.' );
		$this->assertNotFalse( $venue, 'The Venue section renders.' );

		$this->assertGreaterThan( $description, $timeline, 'Sessions follow the description.' );
		$this->assertGreaterThan( $timeline, $venue, 'The venue is last in the column.' );
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
	 * A session's own speakers sit beside its description rather than under it,
	 * in both session styles: the accordion's panels on an ordinary event and the
	 * timeline's items on the flagship. The split is a modifier set only when the
	 * session has both, so a session with one of the two still fills its panel.
	 */
	public function test_each_session_puts_its_speakers_beside_its_description(): void {
		$body     = $this->body();
		$timeline = (string) file_get_contents( get_theme_file_path( 'parts/events/session-timeline.php' ) );
		$css      = (string) file_get_contents( get_theme_file_path( 'assets/css/calendar.css' ) );

		$this->assertStringContainsString( 'law-cal-session__panel--split', $body );
		$this->assertStringContainsString( 'law-cal-session__speakers', $body );
		$this->assertStringContainsString( 'law-timeline__content--split', $timeline );

		foreach ( array( '.law-cal-session__panel--split', '.law-timeline__content--split' ) as $selector ) {
			$this->assertStringContainsString( $selector . ' {', $css, $selector . ' is styled.' );
		}

		// One card per row in both, because each list is now a column roughly a
		// third of the container wide.
		$this->assertMatchesRegularExpression(
			'/\.law-cal-session__speakers,\s*\n\.law-timeline__speakers \{\s*\n\s*grid-template-columns: minmax\(0, 1fr\);/',
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
