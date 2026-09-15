<?php
/**
 * The single event view's layout (Denis, 9, 11 and 15 September 2026). Two
 * columns: the READING column on the left is the thread the reader follows,
 * the description and then the running order; the REFERENCE column on the
 * right holds the Speakers list and then the Venue with its map, which are
 * things to look up rather than things to read through. The speakers are not
 * part of the run either way -- for an event with sessions each speaker
 * appears beside the description of the session they speak in, and the sidebar
 * holds the venue alone.
 *
 * The venue is the one section that reads differently on the two pages. On a
 * hosted event it sits at the foot of the reading column, under the sessions,
 * where it has lived since 9 September 2026; on the flagship, whose reading
 * column holds the description alone, it sits in the sidebar beside it.
 *
 * The flagship is the one exception, and one flag carries it: its agenda is a
 * filled panel the width of the article, so it renders BELOW both columns
 * rather than inside the left one, and that page reads description (venue
 * beside it), then the day. The back / register row then sits outside
 * everything, under it all, so the way off the page is the full width of the
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

	public function test_the_reading_column_reads_description_then_sessions(): void {
		$body = $this->body();

		$description = strpos( $body, 'law-cal-detail__body' );
		$timeline    = strpos( $body, '$law_cal_render_sessions();' );
		$sidebar     = strpos( $body, 'law-cal-detail__sidebar' );

		$this->assertNotFalse( $description, 'The description body renders.' );
		$this->assertNotFalse( $timeline, 'The sessions render.' );

		$this->assertGreaterThan( $description, $timeline, 'Sessions follow the description.' );
		$this->assertGreaterThan( $timeline, $sidebar, 'And both are inside the reading column.' );
	}

	/**
	 * The flagship's agenda is a panel the width of the article, so it renders
	 * outside the two columns and below them; every other event keeps its
	 * sessions in the reading column. One flag decides, and the timeline is
	 * rendered from one closure either way, so the two placements cannot drift
	 * into showing different agendas.
	 */
	public function test_the_panelled_agenda_renders_below_both_columns(): void {
		$body = $this->body();

		$in_column  = strpos( $body, '$law_cal_render_sessions();' );
		$full_width = strrpos( $body, '$law_cal_render_sessions();' );
		$sidebar    = strpos( $body, 'law-cal-detail__sidebar' );
		$foot       = strpos( $body, 'law-cal-detail__foot' );

		$this->assertNotSame( $in_column, $full_width, 'The timeline has two possible positions.' );
		$this->assertGreaterThan( $sidebar, $full_width, 'The second is outside the grid row.' );
		$this->assertGreaterThan( $full_width, $foot, 'And still above the back / register row.' );

		$this->assertStringContainsString(
			"\$law_cal_sessions_full = \$law_cal_sessions_panel;",
			$body,
			'The panel flag is what places it, so the two cannot disagree.'
		);
		$this->assertStringContainsString(
			"! empty( \$event['sessions'] ) && ! \$law_cal_sessions_full",
			$body,
			'In the column only when it is not the panel.'
		);
		$this->assertStringContainsString(
			"! empty( \$event['sessions'] ) && \$law_cal_sessions_full",
			$body,
			'And below the columns only when it is.'
		);
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
	 * The reference column, in source order: Speakers first, then the Venue with
	 * its map, in a column beside the reading one rather than a run of sections
	 * under it. No event shows both today -- a hosted event's venue is in the
	 * reading column and the flagship lists no speakers here -- but the order is
	 * pinned so that a page that did would read the right way round.
	 *
	 * The Speakers list renders only for an event without sessions, since a
	 * session lists its own speakers beside its description and a sidebar copy
	 * would print every card (and its bio dialog) twice on the page.
	 */
	public function test_the_sidebar_holds_the_speakers_then_the_venue(): void {
		$body = $this->body();

		$main     = strpos( $body, 'law-cal-detail__main' );
		$sidebar  = strpos( $body, 'law-cal-detail__sidebar' );
		$speakers = strpos( $body, '>Speakers<' );
		$venue    = strrpos( $body, '$law_cal_render_venue();' );

		$this->assertNotFalse( $main, 'The reading column carries its own class.' );
		$this->assertNotFalse( $sidebar, 'The reference column renders.' );
		$this->assertNotFalse( $speakers, 'The Speakers heading renders.' );
		$this->assertNotFalse( $venue, 'The Venue section renders.' );

		$this->assertGreaterThan( $main, $sidebar, 'The column opens after the reading one closes.' );
		$this->assertGreaterThan( $sidebar, $speakers, 'The speakers are inside it.' );
		$this->assertGreaterThan( $speakers, $venue, 'And the venue follows them.' );
		$this->assertStringContainsString(
			'$law_cal_venue_side = $law_cal_has_venue && ! $law_cal_venue_in_main;',
			$body,
			'The sidebar copy stands down when the venue is in the reading column.'
		);
		$this->assertStringContainsString(
			"\$law_cal_speakers_aside = empty( \$event['sessions'] ) && ! empty( \$event['speakers'] );",
			$body,
			'The speakers list is gated on the event having no sessions.'
		);
		$this->assertStringContainsString(
			'$law_cal_has_aside  = $law_cal_speakers_aside || $law_cal_venue_side;',
			$body,
			'And the column itself renders for either of the two things in it.'
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
	 * The venue sits at the foot of the reading column on every hosted event,
	 * under the sessions, and moves to the sidebar on the flagship alone. The
	 * SAME flag decides that as decides the panel and its placement, so the
	 * flagship layout cannot half-apply.
	 *
	 * What it is not is a caller's choice: the flagship page used to move this
	 * section through a $law_cal_venue_last variable, and two orders a caller
	 * could pick between was a difference with no reason behind it. One part,
	 * rendered from one closure, whichever position it takes.
	 */
	public function test_the_venue_is_in_the_reading_column_except_on_the_flagship(): void {
		$body = $this->body();

		$this->assertStringContainsString(
			'$law_cal_venue_in_main = ! $law_cal_sessions_panel;',
			$body,
			'One flag carries the whole flagship layout.'
		);
		$this->assertStringContainsString(
			'$law_cal_venue_side = $law_cal_has_venue && ! $law_cal_venue_in_main;',
			$body,
			'And the two positions are exclusive.'
		);

		$in_main = strpos( $body, '$law_cal_render_venue();' );
		$sidebar = strpos( $body, 'law-cal-detail__sidebar' );
		$this->assertNotFalse( $in_main, 'The reading column renders it.' );
		$this->assertLessThan( $sidebar, $in_main, 'Before the sidebar opens, so inside that column.' );
		$this->assertStringContainsString( 'if ( $law_cal_venue_in_main ) : ?>', $body, 'Gated on that test.' );

		$this->assertSame( 1, substr_count( $body, "'parts/events/event-venue'" ), 'One part, two positions.' );
		$this->assertStringNotContainsString( 'law_cal_venue_last', $body );
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
