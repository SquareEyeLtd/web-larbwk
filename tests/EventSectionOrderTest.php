<?php
/**
 * The single event view's section order (Denis, 9 September 2026): description,
 * sessions, speakers, venue. The address is a detail the reader needs once, so
 * it goes last, below the running order and the people.
 *
 * Asserted against the source of parts/calendar-body.php rather than a render,
 * because that partial calls get_header() and brings a whole page with it (the
 * same reason FlagshipRenderTest pins its template's contract this way).
 */
class EventSectionOrderTest extends LAW_Test_Case {

	private function body(): string {
		return (string) file_get_contents( get_theme_file_path( 'parts/calendar-body.php' ) );
	}

	public function test_the_sections_render_in_order(): void {
		$body = $this->body();

		$description = strpos( $body, 'law-cal-detail__body' );
		$timeline    = strpos( $body, "'parts/events/session-timeline'" );
		$sessions    = strpos( $body, '<section class="law-cal-sessions"' );
		$speakers    = strpos( $body, '>Speakers<' );
		$venue       = strpos( $body, "'parts/events/event-venue'" );

		$this->assertNotFalse( $description, 'The description body renders.' );
		$this->assertNotFalse( $timeline, 'The timeline session style renders.' );
		$this->assertNotFalse( $sessions, 'The accordion session style renders.' );
		$this->assertNotFalse( $speakers, 'The event-level Speakers section renders.' );
		$this->assertNotFalse( $venue, 'The Venue section renders.' );

		$this->assertGreaterThan( $description, $timeline, 'Sessions follow the description.' );
		$this->assertGreaterThan( $timeline, $speakers, 'Speakers follow the sessions.' );
		$this->assertGreaterThan( $speakers, $venue, 'The venue is last.' );
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
