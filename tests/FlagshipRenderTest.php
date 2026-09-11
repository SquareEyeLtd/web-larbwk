<?php
/**
 * How the flagship conference reaches the front end (FLAGSHIP_UI.md §5): its
 * own single-page template, and its pinned block on the programme.
 *
 * The two properties worth pinning are the ones a later change could break
 * silently: the flagship must appear on the programme EXACTLY once, as its
 * block and never as an ordinary card, and it must stay there when a visitor
 * filters the list, because it is the main event of the week rather than one
 * result among many.
 */
class FlagshipRenderTest extends LAW_Test_Case {

	private int $flagship = 0;

	protected function setUp(): void {
		parent::setUp();
		// The block only renders in CPT mode, and the local site may be either.
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		law_calendar_reset_caches();
	}

	protected function tearDown(): void {
		remove_all_filters( 'law_flagship_event_id' );
		remove_all_filters( 'pre_option_law_events_source' );
		law_flagship_event_id( true );
		law_calendar_reset_caches();
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * A published flagship on a date inside the programme week, with two
	 * sessions, pinned as "the" flagship for the rest of the test.
	 */
	private function make_flagship( bool $with_sessions = true, string $status = 'publish' ): int {
		$date     = array_key_first( law_calendar_week_days() );
		$event_id = $this->make_event(
			array(
				'_law_is_flagship'   => 1,
				'_law_flagship_date' => $date,
				'_law_venue'         => 'IDRC, 70 Fleet Street, London',
			),
			$status
		);
		wp_update_post( array( 'ID' => $event_id, 'post_name' => 'flagship', 'post_title' => 'Flagship conference' ) );

		$this->flagship = $event_id;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		law_flagship_event_id( true );

		if ( $with_sessions ) {
			foreach ( array( array( 'Opening keynote', '09:30', '10:30' ), array( 'Closing panel', '14:00', '16:00' ) ) as $row ) {
				$session_id = wp_insert_post(
					array(
						'post_type'    => LAW_SESSION_CPT,
						'post_status'  => 'publish',
						'post_parent'  => $event_id,
						'post_title'   => $row[0],
						'post_content' => 'What happens.',
					)
				);
				$this->posts[] = $session_id;
				law_event_update_meta( $session_id, '_law_start_time', $row[1] );
				law_event_update_meta( $session_id, '_law_end_time', $row[2] );
			}
		}

		law_flagship_recompute( $event_id );
		law_calendar_reset_caches();

		return $event_id;
	}

	/** An ordinary confirmed event on the same day, for contrast. */
	private function make_ordinary( string $date ): int {
		$event_id = $this->make_event( array( '_law_start' => $date . ' 12:00', '_law_end' => $date . ' 13:00' ), 'publish' );
		law_calendar_reset_caches();
		return $event_id;
	}

	/** Render parts/calendar-events.php and return its markup. */
	private function render_list( bool $show_status = false ): string {
		ob_start();
		get_template_part( 'parts/calendar-events', null, array( 'show_status' => $show_status ) );
		return (string) ob_get_clean();
	}

	public function test_the_template_include_filter_routes_the_flagship_to_its_own_template(): void {
		$event_id = $this->make_flagship();
		$ordinary = $this->make_ordinary( law_flagship_date( $event_id ) );

		$before = $GLOBALS['wp_query'];
		try {
			$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $event_id, 'post_type' => LAW_EVENT_CPT ) );
			$this->assertStringEndsWith(
				'templates/flagship-event.php',
				(string) apply_filters( 'template_include', 'index.php' )
			);

			$GLOBALS['wp_query'] = new WP_Query( array( 'p' => $ordinary, 'post_type' => LAW_EVENT_CPT ) );
			$this->assertStringEndsWith(
				'templates/event-single.php',
				(string) apply_filters( 'template_include', 'index.php' ),
				'Every other event keeps the ordinary single view.'
			);
		} finally {
			$GLOBALS['wp_query'] = $before;
		}
	}

	public function test_the_flagship_is_not_an_ordinary_card_in_the_grouped_list(): void {
		$event_id = $this->make_flagship();
		$date     = law_flagship_date( $event_id );
		$ordinary = $this->make_ordinary( $date );

		$grouped = law_calendar_events_by_date();
		$ids     = wp_list_pluck( $grouped[ $date ] ?? array(), 'id' );

		$this->assertContains( $ordinary, $ids, 'Guard: the ordinary event does group under the day.' );
		$this->assertNotContains( $event_id, $ids, 'The flagship is rendered as its own block, so it must not also be a card.' );
		$this->assertNotContains( $event_id, wp_list_pluck( law_calendar_unscheduled_events(), 'id' ) );
	}

	public function test_the_block_renders_once_under_its_own_day(): void {
		$event_id = $this->make_flagship();
		$date     = law_flagship_date( $event_id );

		$html = $this->render_list();

		$this->assertStringContainsString( 'id="day-' . $date . '"', $html );
		$this->assertSame( 1, substr_count( $html, 'class="law-flagship-card"' ), 'Exactly one block, exactly once.' );
		$this->assertStringContainsString( 'Flagship event', $html, 'And it is labelled as the flagship.' );
		$this->assertStringContainsString( 'Opening keynote', $html, 'The block lists the sessions.' );
		$this->assertStringContainsString( 'Closing panel', $html );
		$this->assertStringContainsString( 'Event details', $html );

		// The block sits inside its day section, not loose above the days.
		$day_position   = strpos( $html, 'id="day-' . $date . '"' );
		$block_position = strpos( $html, 'class="law-flagship-card"' );
		$this->assertGreaterThan( $day_position, $block_position );
	}

	public function test_the_day_is_never_empty_while_the_flagship_is_published(): void {
		$event_id = $this->make_flagship();
		$date     = law_flagship_date( $event_id );

		$this->assertFalse( law_calendar_day_is_empty( $date ), 'Its own day always has the block to show.' );

		// Even when the filters exclude every ordinary event on that day, which
		// is what the day nav reads to decide whether to grey the tab out.
		$_GET['law_kw'] = 'no-such-term-anywhere';
		law_calendar_reset_caches();
		$empty_under_filter = law_calendar_day_is_empty( $date );
		unset( $_GET['law_kw'] );
		law_calendar_reset_caches();

		$this->assertFalse( $empty_under_filter, 'The flagship keeps its day and its tab alive under any filter.' );
	}

	public function test_a_filtered_list_still_carries_the_block(): void {
		$this->make_flagship();

		$_GET['law_kw'] = 'no-such-term-anywhere';
		law_calendar_reset_caches();
		$html = $this->render_list();
		unset( $_GET['law_kw'] );
		law_calendar_reset_caches();

		$this->assertStringContainsString( 'class="law-flagship-card"', $html, 'A search that matches nothing must not hide the main event of the week.' );
		$this->assertStringContainsString( 'law-cal__empty', $html, 'The "no events match" message still prints for the rest of the list.' );
	}

	public function test_an_unpublished_flagship_shows_nowhere_public(): void {
		$this->make_flagship( true, 'law-draft' );

		$this->assertNull( law_calendar_flagship_event(), 'A draft flagship is not on the public programme.' );
		$this->assertStringNotContainsString( 'law-flagship-card', $this->render_list() );
		// Not even for the committee: the committee calendar passes array() to
		// law_events_map_post(), which means "any status EXCEPT law-draft".
		$this->assertNull( law_events_map_post( $this->flagship, array() ) );
	}

	/**
	 * The draft flagship's page is not readable by a visitor.
	 *
	 * templates/flagship-event.php resolves EVERY status for someone who can
	 * edit the post, so that the committee can preview the page before ticking
	 * it live. That is only safe because WordPress refuses the request itself
	 * for everyone else, which it does because the module's custom statuses are
	 * registered `protected` and the CPT maps its own capabilities. Both of
	 * those live in other files and neither is otherwise asserted, so a change
	 * to `law_events_capability_args()` (or a plugin filtering capabilities
	 * broadly) could open the page up with no test failing. Hence this one.
	 */
	public function test_a_draft_flagships_page_is_refused_to_a_visitor(): void {
		$event_id = $this->make_flagship( true, 'law-draft' );
		wp_update_post( array( 'ID' => $event_id, 'post_content' => 'Draft agenda, not for the public.' ) );

		$before = $GLOBALS['wp_query'];
		try {
			wp_set_current_user( 0 );

			// Addressed by ID, not by the slug: the site's own committed flagship
			// holds the slug "flagship" and is published, so a name query would
			// resolve THAT post and pass for the wrong reason.
			$query = new WP_Query( array( 'p' => $event_id, 'post_type' => LAW_EVENT_CPT ) );
			$this->assertSame( 0, (int) $query->post_count, 'A visitor must not resolve a draft flagship.' );
			$this->assertNotContains( $event_id, wp_list_pluck( $query->posts, 'ID' ) );

			// And the public resolvers give a visitor nothing either.
			$this->assertNull( law_events_map_post( $event_id ) );
			law_calendar_reset_caches();
			$this->assertNull( law_calendar_event_by_id( $event_id ) );

			// While someone who can edit it does get the page, which is the
			// committee's preview.
			wp_set_current_user( $this->make_committee_user() );
			$editable = law_events_map_post( $event_id, array( '*' ) );
			$this->assertNotNull( $editable );
			$this->assertSame( $event_id, (int) $editable['id'] );
		} finally {
			$GLOBALS['wp_query'] = $before;
			wp_set_current_user( 0 );
		}
	}

	public function test_the_hero_image_prefers_the_chosen_attachment(): void {
		$event_id = $this->make_flagship();

		$this->assertSame( '', law_event_hero_image_url( $event_id ), 'With none set there is no custom image.' );

		$attachment_id = wp_insert_post(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_title'     => 'Flagship banner',
				'post_mime_type' => 'image/jpeg',
			)
		);
		$this->posts[] = $attachment_id;
		update_post_meta( $attachment_id, '_wp_attached_file', '2026/09/flagship-banner.jpg' );
		law_event_update_meta( $event_id, '_law_hero_image_id', $attachment_id );

		$this->assertStringContainsString( 'flagship-banner', law_event_hero_image_url( $event_id ) );
		$this->assertStringContainsString( 'flagship-banner', $this->render_list(), 'The block uses it too, so the block and the page match.' );

		// A deleted attachment falls back rather than printing a broken image.
		wp_delete_post( $attachment_id, true );
		$this->assertSame( '', law_event_hero_image_url( $event_id ) );
		$this->assertStringContainsString( basename( law_hero_default_image_url() ), $this->render_list() );
	}

	public function test_a_flagship_without_sessions_keeps_its_date_and_says_so(): void {
		$event_id = $this->make_flagship( false );

		$event = law_events_map_post( $event_id );
		$this->assertNotNull( $event, 'It must not drop off the programme for having no agenda yet.' );
		$this->assertSame( law_flagship_date( $event_id ), $event['date'] );
		$this->assertFalse( $event['unscheduled'] );
		$this->assertSame( 'Times to be announced', law_calendar_event_time_label( $event ) );
		$this->assertTrue( $event['is_flagship'] );

		$html = $this->render_list();
		$this->assertStringContainsString( 'Programme to be announced', $html );
	}

	public function test_the_details_box_can_drop_the_booking_control_and_places(): void {
		$event_id = $this->make_flagship();
		law_event_update_meta( $event_id, '_law_tickets_available', 100 );
		law_calendar_reset_caches();
		$event = law_calendar_event_by_id( $event_id );

		ob_start();
		get_template_part(
			'parts/calendar-event-details',
			null,
			array(
				'event'   => $event,
				'booking' => false,
				'places'  => array( 'label' => 'Places remaining', 'value' => '100' ),
				'rows'    => array(
					array( 'key' => 'date', 'label' => 'Date', 'value' => 'Wednesday 2 December' ),
					array( 'key' => 'venue', 'label' => 'Location', 'value' => 'IDRC' ),
				),
			)
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'law-event-details__item--venue', $html, 'Guard: the box still renders its rows.' );
		$this->assertStringNotContainsString( 'law-event-details__footer', $html, 'No booking control on the flagship.' );
		$this->assertStringNotContainsString( 'law-event-details__item--places', $html, 'And no places count either.' );
	}

	/**
	 * The venue section, its Google map and the details box's link down to it
	 * are the shared single-event markup in parts/calendar-body.php. What could
	 * silently break them is the flagship template's row allow-list, so that is
	 * what this pins: drop 'venue' from it and the Location fact disappears,
	 * taking the anchored link with it.
	 */
	public function test_the_venue_row_and_its_map_survive_the_trimmed_details_box(): void {
		$event_id = $this->make_flagship();
		law_calendar_reset_caches();
		$event = law_calendar_event_by_id( $event_id );

		$this->assertSame( 'IDRC, 70 Fleet Street, London', $event['venue'] );
		$this->assertTrue( law_calendar_venue_is_mappable( $event['venue'] ), 'A real address maps.' );
		$this->assertNotSame( '', law_calendar_maps_embed_url( $event['venue'] ) );
		// "tbc" and its variants are deliberately not places, for every event.
		$this->assertFalse( law_calendar_venue_is_mappable( 'tbc' ) );

		$template = file_get_contents( get_theme_file_path( 'templates/flagship-event.php' ) );
		preg_match( '/\$law_cal_details_rows\s*=\s*array\(([^)]*)\)/', $template, $m );
		$this->assertNotEmpty( $m, 'The template still declares its row allow-list.' );
		$this->assertStringContainsString( "'venue'", $m[1], 'Location stays in the details box, so its link to the map has a target.' );

		// And the box renders that row with the anchored link, booking or no.
		ob_start();
		get_template_part(
			'parts/calendar-event-details',
			null,
			array(
				'event'   => $event,
				'booking' => false,
				'rows'    => array( array( 'key' => 'venue', 'label' => 'Location', 'value' => $event['venue'] ) ),
			)
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'href="#law-cal-venue-heading"', $html );
	}

	/**
	 * The agenda is a timeline of open sessions: a day-long programme is the
	 * content, and collapsing eight sessions behind summaries hides it. The
	 * ordinary single event view renders the same timeline; the flagship differs
	 * only in calling its running order an Agenda.
	 */
	public function test_the_agenda_renders_as_a_timeline_of_open_sessions(): void {
		$event_id = $this->make_flagship();

		ob_start();
		get_template_part(
			'parts/events/session-timeline',
			null,
			array( 'sessions' => law_event_session_rows( $event_id ) )
		);
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<ol class="law-timeline">', $html, 'An ordered list: the agenda is a sequence.' );
		$this->assertSame( 2, substr_count( $html, 'class="law-timeline__item' ) );
		$this->assertStringNotContainsString( '<details', $html, 'Nothing is collapsed.' );
		// Machine-readable times, with the human 12-hour label as the text.
		$this->assertStringContainsString( '<time datetime="09:30">9:30am</time>', $html );
		$this->assertStringContainsString( '<time datetime="16:00">4:00pm</time>', $html );
		$this->assertStringContainsString( 'Opening keynote', $html );
		$this->assertStringContainsString( 'Closing panel', $html );

		// And the flagship template is what names it.
		$template = file_get_contents( get_theme_file_path( 'templates/flagship-event.php' ) );
		$this->assertStringContainsString( "\$law_cal_sessions_heading = __( 'Agenda', 'law' );", $template );
	}

	/**
	 * Every session carries the same marker and the same weight. The timeline
	 * used to guess that a session with no description and nobody speaking was a
	 * break and grey it out; Denis had that removed on 11 September 2026, because
	 * a real session whose blurb is not written yet then read as a coffee break.
	 */
	public function test_no_session_is_de_emphasised_for_want_of_a_description(): void {
		$sessions = array(
			array( 'id' => 1, 'title' => 'Keynote', 'start' => '09:30', 'end' => '10:30', 'time_label' => '09:30–10:30', 'description' => '<p>Opening remarks.</p>', 'speakers' => array() ),
			array( 'id' => 2, 'title' => 'Coffee break', 'start' => '10:30', 'end' => '11:00', 'time_label' => '10:30–11:00', 'description' => '', 'speakers' => array() ),
		);

		ob_start();
		get_template_part( 'parts/events/session-timeline', null, array( 'sessions' => $sessions ) );
		$html = (string) ob_get_clean();

		$this->assertSame( 2, substr_count( $html, '<li class="law-timeline__item">' ), 'Both items, identically classed.' );
		$this->assertStringNotContainsString( 'law-timeline__item--break', $html );
		$this->assertStringNotContainsString(
			'law-timeline__item--break',
			(string) file_get_contents( get_theme_file_path( 'assets/css/calendar.css' ) ),
			'And no styles left behind to bring it back.'
		);
	}

	public function test_the_flagship_template_trims_the_details_rows(): void {
		// The template's contract, asserted without rendering the whole page
		// (parts/calendar-body.php calls get_header()).
		$template = file_get_contents( get_theme_file_path( 'templates/flagship-event.php' ) );
		// Price and Places joined the box on 10 September 2026 (Denis): the
		// price belongs with the facts, not in a paragraph under them.
		$this->assertStringContainsString( "\$law_cal_details_rows = array( 'date', 'time', 'venue', 'price', 'places' );", $template );
		// The control is no longer suppressed: since FLAGSHIP_PAYMENTS.md the
		// details box carries the APPLICATION control, swapped in by
		// law_booking_render_action() rather than by anything in the template,
		// so $law_cal_no_booking must be gone. Leaving it would silently hide
		// the only way to apply.
		$this->assertStringNotContainsString( '$law_cal_no_booking', $template );
		// No page-template header, so it is routed in by template_include and can
		// never be picked in the editor for some other page.
		$this->assertDoesNotMatchRegularExpression( '/^\s*\*\s*Template Name:/m', $template );
	}

	/**
	 * The details box's one call site still calls law_booking_render_action(),
	 * which hands a flagship to the application control. Pinned because the
	 * alternative (branching in the template) was rejected: two call sites is
	 * how one of them ends up rendering the wrong control.
	 */
	public function test_the_booking_control_routes_the_flagship_to_the_application_control(): void {
		$source = file_get_contents( get_theme_file_path( 'functions/account-bookings.php' ) );
		$this->assertStringContainsString( 'law_flagship_render_action( $event, $preview );', $source );
		$this->assertTrue( function_exists( 'law_flagship_render_action' ) );
	}
}
