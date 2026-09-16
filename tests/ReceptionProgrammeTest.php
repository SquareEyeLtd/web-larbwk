<?php
/**
 * How a drinks reception presents itself on the programme (Denis, 16 September
 * 2026).
 *
 * A reception used to render as an ordinary pale row with one extra "Price"
 * line, and on the conference's own day it sat underneath the flagship's photo
 * block and nobody saw it. It now takes the same navy surface the conference
 * wears wherever IT appears as a row, with its own identity pill, and its day
 * carries a "Reception" pill on the day tabs.
 *
 * The properties worth pinning are the ones that break quietly:
 *
 * 1. The surface is a modifier on the shared card, so it must land on every
 *    surface parts/loop/event.php serves and on no ordinary event.
 * 2. It has to beat .law-event-card--sponsored, which it can only do on source
 *    order in calendar.css -- the two classes tie on specificity.
 * 3. The day nav is never swapped by a filter fetch, so the pill's truth has to
 *    reach calendar-tabs.js through the marker parts/calendar-events.php emits.
 *    That attribute is a contract between two files and nothing else checks it.
 */
class ReceptionProgrammeTest extends LAW_Test_Case {

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

	/** A published event on a day of the configured week. */
	private function make_listed_event( string $title, array $meta = array(), string $date = '' ): int {
		$days     = array_keys( law_calendar_week_days() );
		$date     = '' !== $date ? $date : $days[0];
		$event_id = $this->make_event(
			array_merge( array( '_law_start' => $date . ' 18:30', '_law_end' => $date . ' 20:30' ), $meta ),
			'publish'
		);
		wp_update_post( array( 'ID' => $event_id, 'post_title' => $title ) );
		law_calendar_reset_caches();
		return $event_id;
	}

	private function make_reception( string $title = 'Opening drinks', string $date = '' ): int {
		return $this->make_listed_event(
			$title,
			array( '_law_is_reception' => 1, '_law_attendee_price_pence' => 6000 ),
			$date
		);
	}

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

	private function render_nav(): string {
		ob_start();
		get_template_part( 'parts/calendar-daynav' );
		return (string) ob_get_clean();
	}

	private function render_list(): string {
		ob_start();
		get_template_part( 'parts/calendar-events' );
		return (string) ob_get_clean();
	}

	/* The card ______________________________________________________________ */

	public function test_a_reception_row_takes_the_navy_surface_and_says_which_kind_it_is(): void {
		$html = $this->render_card( $this->make_reception() );

		$this->assertStringContainsString( 'law-event-card--reception', $html );
		$this->assertStringContainsString( 'law-event-card__reception-badge', $html );
		$this->assertStringContainsString( 'Drinks reception', $html );
		// The fill is never the only thing saying what this is: the pill carries
		// the words, exactly as the conference's does.
		$this->assertStringContainsString( 'Price:', $html, 'The net price line stays.' );
	}

	public function test_an_ordinary_event_takes_neither(): void {
		$html = $this->render_card( $this->make_listed_event( 'A hosted seminar' ) );

		$this->assertStringNotContainsString( 'law-event-card--reception', $html );
		$this->assertStringNotContainsString( 'reception-badge', $html );
	}

	public function test_the_reception_surface_is_declared_after_the_sponsored_one(): void {
		// The two modifiers tie on specificity, so source order is the whole
		// mechanism by which a reception a firm has actually sponsored still
		// comes out navy rather than peach. A reordering of calendar.css would
		// otherwise break it silently.
		$css        = (string) file_get_contents( get_theme_file_path( '/assets/css/calendar.css' ) );
		$sponsored  = strpos( $css, '.law-event-card--sponsored {' );
		$reception  = strpos( $css, '.law-event-card--reception {' );

		$this->assertIsInt( $sponsored );
		$this->assertIsInt( $reception );
		$this->assertGreaterThan( $sponsored, $reception );
	}

	public function test_the_identity_pill_reuses_the_shared_shape(): void {
		// One shape for all five badges. The comment in calendar.css warns
		// against a sixth copy of the same declarations; this fails if one
		// appears.
		$css = (string) file_get_contents( get_theme_file_path( '/assets/css/calendar.css' ) );

		$this->assertStringContainsString( '.law-event-card__reception-badge,', $css );
		$this->assertSame(
			1,
			substr_count( $css, 'border-radius: 11px;' ),
			'The pill is declared once, for every surface that wears it.'
		);
	}

	/* The day tabs __________________________________________________________ */

	/**
	 * A day of the configured week that has no reception on it yet.
	 *
	 * The suite runs against the developer's own database, which already holds
	 * the seeded receptions, so a test that counted pills or expected an empty
	 * list would pass or fail on local data rather than on the code. Every
	 * assertion below is therefore about the DIFFERENCE this fixture makes.
	 */
	private function quiet_day(): string {
		$taken = law_calendar_reception_dates();
		foreach ( array_keys( law_calendar_week_days() ) as $date ) {
			if ( ! in_array( $date, $taken, true ) ) {
				return $date;
			}
		}
		$this->markTestSkipped( 'Every day of the configured week already has a reception on it.' );
	}

	public function test_a_day_with_a_reception_wears_the_pill_and_a_day_without_one_does_not(): void {
		$date   = $this->quiet_day();
		$before = substr_count( $this->render_nav(), 'law-cal-daynav__flag--reception' );

		$this->make_reception( 'Opening drinks', $date );

		$this->assertContains( $date, law_calendar_reception_dates() );
		$this->assertSame(
			$before + 1,
			substr_count( $this->render_nav(), 'law-cal-daynav__flag--reception' ),
			'Exactly one more tab wears the pill: the day the reception is on.'
		);
	}

	public function test_a_reception_whose_places_are_not_open_still_marks_its_day(): void {
		// Presence, not booking state, in parity with the conference: its pill
		// does not wait for registration to open either.
		$reception = $this->make_reception();
		law_event_update_meta( $reception, '_law_registration_state', 'closed' );
		law_calendar_reset_caches();

		$this->assertStringContainsString( 'law-cal-daynav__flag--reception', $this->render_nav() );
	}

	public function test_a_filter_that_hides_the_reception_takes_its_pill_with_it(): void {
		$this->make_reception( 'Opening drinks' );

		$_GET['law_kw'] = 'no-such-term-anywhere';
		law_calendar_reset_caches();

		$this->assertSame( array(), law_calendar_reception_dates() );
		$this->assertStringNotContainsString( 'law-cal-daynav__flag--reception', $this->render_nav() );
	}

	public function test_the_pills_wrapper_is_always_rendered(): void {
		// calendar-tabs.js puts the pills inside it after a filter fetch, so a
		// day that GAINS one needs the wrapper to be there already.
		$nav = $this->render_nav();

		$this->assertSame(
			count( law_calendar_week_days() ),
			substr_count( $nav, 'law-cal-daynav__flags' ),
			'Every tab carries the wrapper, empty or not.'
		);
	}

	/* The contract with calendar-tabs.js ____________________________________ */

	public function test_the_reception_days_reach_the_script_through_the_marker(): void {
		$date = $this->quiet_day();
		$this->make_reception( 'Opening drinks', $date );

		$this->assertMatchesRegularExpression(
			'/data-law-reception-days="[^"]*' . preg_quote( $date, '/' ) . '/',
			$this->render_list()
		);
	}

	public function test_the_marker_is_emitted_empty_rather_than_omitted(): void {
		// Same rule as the flagship's half of it: ABSENT has to keep meaning
		// "this is not the programme's markup" -- the committee's timeline view
		// swaps in parts/events/slot-chart.php, whose pills the script must
		// leave alone. A keyword nothing answers is the one state in which the
		// programme genuinely has no visible reception.
		$_GET['law_kw'] = 'no-such-term-anywhere';
		law_calendar_reset_caches();

		$this->assertStringContainsString( 'data-law-reception-days=""', $this->render_list() );
	}

	public function test_renaming_the_attribute_on_one_side_fails_a_test(): void {
		$this->assertStringContainsString(
			'data-law-reception-days',
			(string) file_get_contents( get_theme_file_path( '/assets/js/calendar-tabs.js' ) ),
			'The attribute is a contract between two files.'
		);
	}
}
