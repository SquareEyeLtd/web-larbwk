<?php
/**
 * The committee's timeline view (functions/events/slot-chart.php).
 *
 * What these pin is the geometry, because it is the part that can be quietly
 * wrong. A bar in the wrong place still looks like a chart, and the committee
 * would be reading it to decide whether two events collide:
 *
 *  - two events running at once are never on the same row, and two that merely
 *    touch always are, because that difference IS the clash;
 *  - the status bands stack and never interleave, so the top of the chart is
 *    the approved events as asked for (Denis, 15 September 2026);
 *  - an event with no recorded end is drawn at the same two hours the calendar
 *    invite already assumes, rather than a second answer to the same question;
 *  - nothing is silently dropped: no start, a date outside the programme week,
 *    or a status nobody listed all still reach the page.
 */
class SlotChartTest extends LAW_Test_Case {

	protected function tearDown(): void {
		remove_all_filters( 'pre_option_law_events_source' );
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		law_slotchart_items( true );
		$_GET = array();
		parent::tearDown();
	}

	/**
	 * An item in law_slotchart_item()'s shape, without the database round trip:
	 * the packing and axis functions are pure, and fixtures that say "09:00 to
	 * 10:00, approved" read better than a post with two meta rows.
	 */
	private function item( $start, $end, $status = 'publish', $title = 'Event' ) {
		$to_min = static function ( $hhmm ) {
			list( $h, $m ) = array_map( 'intval', explode( ':', $hhmm ) );
			return ( $h * 60 ) + $m;
		};

		return array(
			'id'           => 0,
			'title'        => $title,
			'status'       => $status,
			'status_label' => law_event_status_label( $status ),
			'status_slug'  => law_calendar_status_slug( law_event_status_label( $status ) ),
			'url'          => '',
			'date'         => '2026-12-01',
			'start'        => $to_min( $start ),
			'end'          => $to_min( $end ),
			'start_label'  => $start,
			'end_label'    => $end,
			'open_ended'   => false,
			'kind'         => 'hosted',
		);
	}

	/** Titles per lane, for readable assertions. */
	private function lane_titles( array $lanes ) {
		return array_map(
			static function ( $lane ) {
				return array_column( $lane, 'title' );
			},
			$lanes
		);
	}

	/* Packing _______________________________________________________________ */

	public function test_overlapping_events_are_put_on_separate_rows() {
		$lanes = law_slotchart_lanes(
			array(
				$this->item( '10:00', '10:30', 'publish', 'A' ),
				$this->item( '10:00', '12:00', 'publish', 'B' ),
			)
		);

		$this->assertSame( array( array( 'A' ), array( 'B' ) ), $this->lane_titles( $lanes ) );
	}

	public function test_events_that_do_not_overlap_share_a_row() {
		$lanes = law_slotchart_lanes(
			array(
				$this->item( '09:00', '10:00', 'publish', 'A' ),
				$this->item( '11:00', '12:00', 'publish', 'B' ),
			)
		);

		$this->assertSame( array( array( 'A', 'B' ) ), $this->lane_titles( $lanes ) );
	}

	/**
	 * The boundary the whole view turns on: an event ending at 10:00 and one
	 * starting at 10:00 are consecutive, not concurrent. Treating touching as
	 * overlapping would double the height of every chart and invent clashes
	 * between back-to-back slots, which is exactly what the committee is here
	 * to rule out.
	 */
	public function test_an_event_starting_when_another_ends_is_not_a_clash() {
		$lanes = law_slotchart_lanes(
			array(
				$this->item( '08:30', '10:00', 'publish', 'A' ),
				$this->item( '10:00', '11:30', 'publish', 'B' ),
			)
		);

		$this->assertSame( array( array( 'A', 'B' ) ), $this->lane_titles( $lanes ) );
	}

	public function test_a_third_concurrent_event_opens_a_third_row() {
		$lanes = law_slotchart_lanes(
			array(
				$this->item( '10:00', '11:00', 'publish', 'A' ),
				$this->item( '10:15', '11:00', 'publish', 'B' ),
				$this->item( '10:30', '11:00', 'publish', 'C' ),
			)
		);

		$this->assertCount( 3, $lanes );
	}

	/* Status bands __________________________________________________________ */

	/** Approved gets first refusal on the top rows. */
	public function test_approved_rows_come_before_confirmed_rows() {
		$lanes = law_slotchart_lanes(
			array(
				$this->item( '10:00', '11:00', 'publish', 'Confirmed one' ),
				$this->item( '10:00', '11:00', 'law-approved', 'Approved one' ),
			)
		);

		$this->assertSame(
			array( array( 'Approved one' ), array( 'Confirmed one' ) ),
			$this->lane_titles( $lanes )
		);
	}

	/**
	 * Status is a placing order, not a block of rows each. Packing each status
	 * separately was the first attempt and it left a hole the width of the
	 * morning at the top of a busy day, so a later status now backfills a gap an
	 * earlier one left (Denis, 15 September 2026).
	 */
	public function test_a_confirmed_event_fills_a_gap_in_an_approved_row() {
		$lanes = law_slotchart_lanes(
			array(
				$this->item( '09:00', '10:00', 'law-approved', 'Approved morning' ),
				$this->item( '14:00', '15:00', 'publish', 'Confirmed afternoon' ),
			)
		);

		$this->assertSame(
			array( array( 'Approved morning', 'Confirmed afternoon' ) ),
			$this->lane_titles( $lanes )
		);
	}

	/**
	 * The guarantee that makes the chart readable: it is exactly as tall as the
	 * day's worst clash and no taller. Nine events at once needs nine rows;
	 * forty-eight events that only ever run nine-deep must not need forty-eight.
	 */
	public function test_the_chart_is_no_taller_than_the_worst_clash() {
		$items = array();
		// Three concurrent, four times over, in four different statuses.
		foreach ( array( '09:00', '11:00', '13:00', '15:00' ) as $i => $hour ) {
			$status = array( 'publish', 'law-approved', 'law-proposed', 'law-sent-back' )[ $i ];
			$end    = sprintf( '%02d:00', ( (int) substr( $hour, 0, 2 ) ) + 1 );
			for ( $n = 0; $n < 3; $n++ ) {
				$items[] = $this->item( $hour, $end, $status, $hour . '-' . $n );
			}
		}

		$lanes = law_slotchart_lanes( $items );

		$this->assertCount( 12, $items );
		$this->assertCount( 3, $lanes, 'Never more than three run at once, so three rows.' );
	}

	/** A row is drawn left to right even after a later status backfilled it. */
	public function test_a_backfilled_row_is_still_in_time_order() {
		$lanes = law_slotchart_lanes(
			array(
				$this->item( '14:00', '15:00', 'law-approved', 'Approved afternoon' ),
				$this->item( '09:00', '10:00', 'publish', 'Confirmed morning' ),
			)
		);

		$this->assertSame(
			array( array( 'Confirmed morning', 'Approved afternoon' ) ),
			$this->lane_titles( $lanes )
		);
	}

	public function test_the_bands_run_approved_confirmed_sent_back_proposed_rejected() {
		$statuses = array( 'law-rejected', 'law-proposed', 'law-sent-back', 'publish', 'law-approved' );
		$items    = array();
		foreach ( $statuses as $status ) {
			$items[] = $this->item( '10:00', '11:00', $status, $status );
		}

		$this->assertSame(
			array(
				array( 'law-approved' ),
				array( 'publish' ),
				array( 'law-sent-back' ),
				array( 'law-proposed' ),
				array( 'law-rejected' ),
			),
			$this->lane_titles( law_slotchart_lanes( $items ) )
		);
	}

	/** A status no one listed is packed last, never dropped. */
	public function test_an_unlisted_status_still_gets_a_row() {
		$lanes = law_slotchart_lanes(
			array(
				$this->item( '10:00', '11:00', 'law-invented', 'Unknown' ),
				$this->item( '10:00', '11:00', 'publish', 'Confirmed' ),
			)
		);

		$this->assertSame(
			array( array( 'Confirmed' ), array( 'Unknown' ) ),
			$this->lane_titles( $lanes )
		);
	}

	/* The axis ______________________________________________________________ */

	public function test_the_axis_is_floored_and_ceiled_to_the_half_hour() {
		// 09:10 floors to 09:00, then opens half an hour earlier; 17:25 ceils
		// to 17:30.
		$axis = law_slotchart_axis( array( $this->item( '09:10', '17:25' ) ) );

		$this->assertSame( ( 8 * 60 ) + 30, $axis['from'] );
		$this->assertSame( ( 17 * 60 ) + 30, $axis['to'] );
		$this->assertSame( 540, $axis['span'] );
	}

	/**
	 * A ruler label is centred on the moment it names, so the one at the very
	 * left of the scroller is sliced in half by its own edge. The axis opens a
	 * step early and the part draws no label for that step, which is what puts
	 * the first real time fully on screen (Denis, 15 September 2026).
	 */
	public function test_the_axis_opens_half_an_hour_before_the_first_event() {
		$axis = law_slotchart_axis( array( $this->item( '10:00', '11:00' ) ) );

		$this->assertSame( ( 9 * 60 ) + 30, $axis['from'] );
	}

	/** Except at midnight, where there is no earlier step to open at. */
	public function test_the_lead_in_never_runs_before_midnight() {
		$axis = law_slotchart_axis( array( $this->item( '00:00', '01:00' ) ) );

		$this->assertSame( 0, $axis['from'] );
	}

	public function test_a_short_day_is_widened_to_a_readable_span() {
		$axis = law_slotchart_axis( array( $this->item( '18:30', '19:00' ) ) );

		$this->assertSame( 18 * 60, $axis['from'], 'Opened half an hour early.' );
		$this->assertSame( 180, $axis['span'], 'A single short event should still get a three-hour ruler.' );
	}

	public function test_the_axis_never_runs_past_midnight() {
		$axis = law_slotchart_axis( array( $this->item( '23:00', '23:30' ) ) );

		$this->assertLessThanOrEqual( 1440, $axis['to'] );
		$this->assertSame( 180, $axis['span'] );
	}

	public function test_an_empty_day_still_has_an_axis() {
		$axis = law_slotchart_axis( array() );

		$this->assertGreaterThan( 0, $axis['span'] );
	}

	/** 1440 is midnight at the END of the day and must not wrap to 00:00. */
	public function test_the_end_of_the_day_reads_as_twenty_four_hundred() {
		$this->assertSame( '24:00', law_slotchart_time_label( 1440 ) );
		$this->assertSame( '08:30', law_slotchart_time_label( 510 ) );
		$this->assertSame( '00:00', law_slotchart_time_label( 0 ) );
	}

	/* Open-ended events _____________________________________________________ */

	public function test_an_event_with_no_end_is_drawn_at_the_ics_default() {
		$event_id = $this->make_event(
			array( '_law_start' => '2026-12-01 19:45', '_law_end' => '' )
		);

		$item = law_slotchart_item( get_post( $event_id ) );

		$this->assertTrue( $item['open_ended'] );
		$this->assertSame( ( 19 * 60 ) + 45, $item['start'] );
		$this->assertSame( ( 21 * 60 ) + 45, $item['end'], 'Two hours, as law_event_ics() assumes.' );
		$this->assertSame( '', $item['end_label'], 'An assumed end must not be shown as a fact.' );
	}

	public function test_an_end_before_its_start_is_treated_as_no_end() {
		// Form 10 entry 1559, Law Rocks! LONDON 2026, was captured as 19:45 to
		// 11:30 -- a night running past midnight, which the module does not
		// store and must not guess a second date for.
		$event_id = $this->make_event(
			array( '_law_start' => '2026-12-01 19:45', '_law_end' => '2026-12-01 11:30' )
		);

		$item = law_slotchart_item( get_post( $event_id ) );

		$this->assertTrue( $item['open_ended'] );
		$this->assertSame( ( 21 * 60 ) + 45, $item['end'] );
	}

	public function test_an_open_ended_event_late_at_night_stops_at_midnight() {
		$event_id = $this->make_event(
			array( '_law_start' => '2026-12-01 23:00', '_law_end' => '' )
		);

		$item = law_slotchart_item( get_post( $event_id ) );

		$this->assertSame( 1440, $item['end'], 'A bar may not run off the end of its own day.' );
	}

	public function test_a_real_end_is_kept_and_labelled() {
		$event_id = $this->make_event(
			array( '_law_start' => '2026-12-01 08:30', '_law_end' => '2026-12-01 10:00' )
		);

		$item = law_slotchart_item( get_post( $event_id ) );

		$this->assertFalse( $item['open_ended'] );
		$this->assertSame( '08:30', $item['start_label'] );
		$this->assertSame( '10:00', $item['end_label'] );
	}

	/* Grouping ______________________________________________________________ */

	public function test_an_event_with_no_start_goes_to_the_unscheduled_bucket() {
		$grouped = law_slotchart_days(
			array(
				array_merge(
					$this->item( '10:00', '11:00', 'publish', 'Nowhere' ),
					array( 'date' => '', 'start' => null, 'end' => null )
				),
			)
		);

		$this->assertCount( 1, $grouped['unscheduled'] );
		$this->assertSame( 'Nowhere', $grouped['unscheduled'][0]['title'] );
	}

	/**
	 * law_calendar_events_by_date() drops an out-of-week event into its
	 * unscheduled bucket, which is right for a five-tab public programme and
	 * wrong here: the committee can produce one (the flagship screen warns about
	 * it), and a planning view has to account for every event.
	 */
	public function test_a_day_outside_the_programme_week_still_gets_its_own_day() {
		$grouped = law_slotchart_days(
			array(
				array_merge(
					$this->item( '10:00', '11:00', 'publish', 'Misfiled' ),
					array( 'date' => '2027-03-01' )
				),
			)
		);

		$this->assertArrayHasKey( '2027-03-01', $grouped['days'] );
		$this->assertCount( 1, $grouped['days']['2027-03-01'] );
		$this->assertSame( array(), $grouped['unscheduled'] );
	}

	public function test_every_configured_week_day_gets_a_section_even_when_empty() {
		$grouped = law_slotchart_days( array() );

		$this->assertSame( array_keys( law_calendar_week_days() ), array_keys( $grouped['days'] ) );
	}

	/* The running total _____________________________________________________ */

	public function test_the_density_counts_events_running_in_each_half_hour() {
		$items = array(
			$this->item( '09:00', '10:00', 'publish', 'A' ),
			$this->item( '09:30', '11:00', 'publish', 'B' ),
		);
		$axis    = law_slotchart_axis( $items );
		$density = law_slotchart_density( $items, $axis );

		$this->assertSame( 1, $density[ 9 * 60 ], '09:00-09:30: A alone.' );
		$this->assertSame( 2, $density[ ( 9 * 60 ) + 30 ], '09:30-10:00: both.' );
		$this->assertSame( 1, $density[ 10 * 60 ], '10:00-10:30: B alone, A has ended.' );
	}

	/** An event ending exactly on a step boundary is not counted in that step. */
	public function test_an_event_is_not_counted_in_the_step_it_ends_on() {
		$items   = array( $this->item( '09:00', '10:00', 'publish', 'A' ) );
		$density = law_slotchart_density( $items, law_slotchart_axis( $items ) );

		$this->assertSame( 0, $density[ 10 * 60 ] );
	}

	/* The view switch _______________________________________________________ */

	public function test_the_view_is_active_only_for_its_own_argument() {
		$this->assertFalse( law_slotchart_is_active() );

		$_GET['law_view'] = 'slots';
		$this->assertTrue( law_slotchart_is_active() );

		$_GET['law_view'] = 'something-else';
		$this->assertFalse( law_slotchart_is_active() );
	}

	/** Pressing the switch changes how the events are drawn, never which ones. */
	public function test_the_switch_carries_the_current_filters() {
		$_GET = array( 'law_kw' => 'arbitration', 'law_status' => 'law-approved' );

		$to_chart = law_slotchart_url( 'slots' );
		$this->assertStringContainsString( 'law_kw=arbitration', $to_chart );
		$this->assertStringContainsString( 'law_status=law-approved', $to_chart );
		$this->assertStringContainsString( 'law_view=slots', $to_chart );

		$back = law_slotchart_url( '' );
		$this->assertStringContainsString( 'law_kw=arbitration', $back );
		$this->assertStringNotContainsString( 'law_view', $back );
	}

	/**
	 * Clicking a bar and pressing back has to land where you were. The bar's
	 * href carries the view and the filters, which is what lets the detail
	 * page's back link rebuild the chart rather than dropping the committee on
	 * the table with their filters cleared.
	 */
	public function test_a_bar_links_back_to_the_view_it_was_clicked_from() {
		$_GET = array( 'law_view' => 'slots', 'law_kw' => 'arbitration' );

		$event_id = $this->make_event( array( '_law_start' => '2026-12-01 10:30' ), 'publish' );
		$item     = law_slotchart_item( get_post( $event_id ) );

		$this->assertStringContainsString( 'event=' . $event_id, $item['url'] );
		$this->assertStringContainsString( 'law_view=slots', $item['url'] );
		$this->assertStringContainsString( 'law_kw=arbitration', $item['url'] );
	}

	/* The flagship __________________________________________________________ */

	/**
	 * It is kept out of the review queue on purpose, and has to come back here:
	 * it occupies a whole day and is the biggest single cause of clashes, so a
	 * planning view without it would be worse than useless.
	 */
	public function test_the_flagship_is_excluded_from_the_table_and_included_in_the_chart() {
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		$flagship_id = $this->make_event(
			array( '_law_is_flagship' => 1, '_law_start' => '2026-12-02 09:00', '_law_end' => '2026-12-02 17:00' ),
			'publish'
		);
		add_filter( 'law_flagship_event_id', fn() => $flagship_id );
		law_flagship_event_id( true );

		wp_set_current_user( $this->make_committee_user() );

		$listed = wp_list_pluck( law_committee_events(), 'ID' );
		$this->assertNotContains( $flagship_id, $listed );

		$charted = wp_list_pluck( law_committee_events( array( 'law_include_flagship' => true ) ), 'ID' );
		$this->assertContains( $flagship_id, $charted );
	}

	/** The sentinel must never reach WP_Query as a query argument. */
	public function test_the_include_flagship_key_is_not_passed_through_to_the_query() {
		wp_set_current_user( $this->make_committee_user() );
		$this->make_event( array( '_law_start' => '2026-12-01 10:00' ), 'publish' );

		$this->assertNotEmpty(
			law_committee_events( array( 'law_include_flagship' => true ) ),
			'An unknown WP_Query argument would have been harmless; one that narrows the query would not.'
		);
	}

	/* Identity ______________________________________________________________ */

	public function test_the_kind_is_read_from_the_flags_not_the_status() {
		$reception = $this->make_event( array( '_law_is_reception' => 1, '_law_start' => '2026-11-30 18:30' ), 'publish' );
		$external  = $this->make_event( array( '_law_is_external' => 1, '_law_start' => '2026-12-04 10:00' ), 'publish' );
		$hosted    = $this->make_event( array( '_law_start' => '2026-12-01 10:30' ), 'publish' );

		$this->assertSame( 'reception', law_slotchart_kind( $reception ) );
		$this->assertSame( 'external', law_slotchart_kind( $external ) );
		$this->assertSame( 'hosted', law_slotchart_kind( $hosted ) );
	}

	public function test_the_bar_label_carries_the_true_times_and_the_status() {
		$event_id = $this->make_event(
			array( '_law_is_external' => 1, '_law_start' => '2026-12-03 17:00', '_law_end' => '2026-12-03 21:00' ),
			'publish'
		);
		wp_update_post( array( 'ID' => $event_id, 'post_title' => 'Alexander Lecture 2026' ) );

		$label = law_slotchart_item_label( law_slotchart_item( get_post( $event_id ) ) );

		$this->assertStringContainsString( 'Alexander Lecture 2026', $label );
		$this->assertStringContainsString( '17:00', $label );
		$this->assertStringContainsString( '21:00', $label );
		$this->assertStringContainsString( 'Confirmed', $label );
		$this->assertStringContainsString( 'External event', $label );
	}

	/** An assumed end is never stated; the label says "onwards" instead. */
	public function test_an_open_ended_bar_says_onwards_rather_than_a_made_up_end() {
		$event_id = $this->make_event( array( '_law_start' => '2026-12-01 19:45', '_law_end' => '' ), 'publish' );

		$label = law_slotchart_item_label( law_slotchart_item( get_post( $event_id ) ) );

		$this->assertStringContainsString( '19:45 onwards', $label );
		$this->assertStringNotContainsString( '21:45', $label );
	}

	/* Parsing _______________________________________________________________ */

	public function test_minutes_are_read_positionally_and_refuse_anything_else() {
		$this->assertSame( 510, law_slotchart_minutes( '2026-12-01 08:30' ) );
		$this->assertSame( 0, law_slotchart_minutes( '2026-12-01 00:00' ) );
		$this->assertNull( law_slotchart_minutes( '' ) );
		$this->assertNull( law_slotchart_minutes( '2026-12-01' ) );
		$this->assertNull( law_slotchart_minutes( 'next Tuesday' ) );
		// Unpadded is not a shape the schema stores (the 'time' sanitiser pads),
		// and guessing at one would put 9:30 after 14:00.
		$this->assertNull( law_slotchart_minutes( '2026-12-01 9:30' ) );
	}
}
