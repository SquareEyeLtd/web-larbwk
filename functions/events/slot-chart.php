<?php
/**
 * The committee's timeline view: one day at a time, every event drawn as a bar
 * at its real start and running its real length, stacked into rows so that
 * events happening at once sit one above the other.
 *
 * The problem it solves is clashes. The committee dashboard's table is sorted
 * by last modified and says nothing about two events colliding; the public
 * programme groups events under text slot headings, which is a list, not a
 * grid. The client asked for it in those words (emily O'Callaghan, 11 September
 * 2026: "it won't help us to identify clashes"), and EVENTS_4.2_SPECS.md §3.1
 * had promised "a day-and-time grid, showing overlapping and parallel sessions
 * at a glance" since the spec was written.
 *
 * Nothing here DETECTS a clash. There is no event-to-event clash rule anywhere
 * in the module and this adds none. law_booking_guard_clash() is a per-attendee
 * guard about one person being in two places, and it exempts the flagship and
 * the receptions. This file makes the overlaps visible and leaves the judgement
 * to the committee.
 *
 * Why this is geometry and not slots. Every kind of event reduces to the same
 * two meta keys: law_event_apply_slot_label() writes _law_start/_law_end from a
 * chosen slot, and law_flagship_recompute(), law_reception_save() and
 * law_external_event_apply_when() write them directly. So the chart reads the
 * datetimes and needs no per-kind branching, and it can place an event whose
 * times match no configured slot at all, which is most of the custom ones.
 *
 * This file is data and geometry only. The markup is parts/events/slot-chart.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The query arg that selects this view, and its value.
 *
 * `law_view`, not `view`: every other argument this dashboard reads is
 * law_-prefixed (law_status, law_kw, law_run_by, law_external, law_edit,
 * law_partial), and this one has to double as a form field name inside
 * #law-cal-filter-form, so it sits among them.
 */
const LAW_SLOTCHART_ARG  = 'law_view';
const LAW_SLOTCHART_VIEW = 'slots';

/**
 * Minutes a bar runs when the event records no end.
 *
 * An empty _law_end is a legitimate, common state and means "onwards": an
 * "18:30 onwards" slot writes no end (law_event_apply_slot_label()), and so
 * does an external event whose captured end was at or before its start
 * (law_external_event_apply_when(), which refuses to guess a second date for a
 * night running past midnight).
 *
 * Two hours, because that is already law_event_ics()'s answer to the same
 * question. A different number here would give the site two answers to "how
 * long is this event", one in the calendar invite and one on the chart.
 */
const LAW_SLOTCHART_OPEN_MINUTES = 120;

/** Granularity of the time ruler, in minutes. */
const LAW_SLOTCHART_STEP = 30;

/** Is the dashboard being asked for the timeline rather than the table? */
function law_slotchart_is_active() {
	return LAW_SLOTCHART_VIEW === sanitize_key( $_GET[ LAW_SLOTCHART_ARG ] ?? '' );
}

/**
 * The dashboard URL for one view or the other, carrying the current filters.
 *
 * Built on law_account_url( 'dashboard' ) as law_external_event_url() is,
 * rather than hardcoding home_url( '/account/dashboard/' ) the way
 * law_committee_action_handler() does in five places: the page is provisioned
 * by setup-account-pages.php and its path is that map's business.
 *
 * @param string $view '' for the table, 'slots' for the timeline.
 * @return string
 */
function law_slotchart_url( $view = LAW_SLOTCHART_VIEW ) {
	$base = function_exists( 'law_account_url' ) ? law_account_url( 'dashboard' ) : '';
	if ( '' === $base ) {
		$base = home_url( '/account/dashboard/' );
	}

	// The filters travel with the switch, so pressing it keeps the set of
	// events you were looking at and only changes how it is drawn.
	$args = array();
	foreach ( array( 'law_kw', 'law_status', 'law_run_by' ) as $key ) {
		$value = sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) );
		if ( '' !== $value ) {
			$args[ $key ] = $value;
		}
	}
	if ( LAW_SLOTCHART_VIEW === $view ) {
		$args[ LAW_SLOTCHART_ARG ] = LAW_SLOTCHART_VIEW;
	}

	return $args ? add_query_arg( $args, $base ) : $base;
}

/**
 * Status keys in row order, top of the chart first (Denis, 15 September 2026):
 * approved, then confirmed, then sent back, then proposed, then rejected.
 *
 * Cancelled and draft were not named and are appended, because the order has to
 * be total. Draft here can only mean an EXTERNAL draft: a host's draft is their
 * own unsubmitted data and law_committee_events() never lists it.
 *
 * This is a PLACING order, not a block of rows each: law_slotchart_lanes()
 * offers each event the first row with space for it, so an approved event gets
 * first refusal on the top rows but a confirmed one still fills a gap an
 * approved one left. Re-ordering the view is editing this array and nothing
 * else.
 *
 * @return string[] Post status keys.
 */
function law_slotchart_band_order() {
	return array(
		'law-approved',
		'publish',
		'law-sent-back',
		'law-proposed',
		'law-rejected',
		'law-cancelled',
		'law-draft',
	);
}

/**
 * Minutes from midnight for a stored 'Y-m-d H:i' datetime.
 *
 * Read positionally, never through strtotime(): the stored value is a naive
 * site-local string with no offset, and a round trip through a timestamp
 * invents a timezone for it. law_external_event_values() reads the same keys
 * the same way for the same reason.
 *
 * @param string $datetime Stored _law_start / _law_end value.
 * @return int|null Minutes from midnight, or null when unparseable.
 */
function law_slotchart_minutes( $datetime ) {
	$datetime = (string) $datetime;
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} (\d{2}):(\d{2})$/', $datetime, $m ) ) {
		return null;
	}
	$minutes = ( (int) $m[1] * 60 ) + (int) $m[2];
	return $minutes >= 0 && $minutes <= 1440 ? $minutes : null;
}

/** The date half of a stored datetime, or '': again positional, not parsed. */
function law_slotchart_date( $datetime ) {
	$datetime = (string) $datetime;
	return preg_match( '/^(\d{4}-\d{2}-\d{2}) \d{2}:\d{2}$/', $datetime, $m ) ? $m[1] : '';
}

/**
 * One event as the chart's renderer wants it.
 *
 * @param WP_Post $post law_event post.
 * @return array{
 *     id:int, title:string, status:string, status_label:string, status_slug:string,
 *     url:string, date:string, start:int|null, end:int|null, start_label:string,
 *     end_label:string, open_ended:bool, kind:string
 * }
 */
function law_slotchart_item( $post ) {
	$id    = (int) $post->ID;
	$start = (string) law_event_meta( $id, '_law_start' );
	$end   = (string) law_event_meta( $id, '_law_end' );

	$start_min = law_slotchart_minutes( $start );
	$end_min   = law_slotchart_minutes( $end );
	$date      = law_slotchart_date( $start );

	// An end on a LATER date cannot be drawn on a day chart and, per
	// law_external_event_apply_when(), is not a state the module stores; an end
	// at or before the start is the same fat-fingered case law_event_ics()
	// already treats as "no end". Both fall back to the open-ended default.
	$open_ended = null === $end_min
		|| law_slotchart_date( $end ) !== $date
		|| ( null !== $start_min && $end_min <= $start_min );

	if ( $open_ended && null !== $start_min ) {
		$end_min = min( 1440, $start_min + LAW_SLOTCHART_OPEN_MINUTES );
	}

	$status_label = law_event_status_label( (string) $post->post_status );

	return array(
		'id'           => $id,
		'title'        => (string) $post->post_title,
		'status'       => (string) $post->post_status,
		'status_label' => $status_label,
		'status_slug'  => law_calendar_status_slug( $status_label ),
		// Carries the view and the filters into the detail page, so the back
		// link there can return to the chart the bar was clicked on rather than
		// dropping the committee onto the table with their filters cleared.
		'url'          => add_query_arg( 'event', $id, law_slotchart_url() ),
		'date'         => $date,
		'start'        => $start_min,
		'end'          => $end_min,
		'start_label'  => null === $start_min ? '' : substr( $start, 11, 5 ),
		'end_label'    => $open_ended || null === $end_min ? '' : substr( $end, 11, 5 ),
		'open_ended'   => $open_ended,
		'kind'         => law_slotchart_kind( $id ),
	);
}

/**
 * Which of the four kinds an event is, for the bar's identity tag.
 *
 * Deliberately separate from its status: "external" is who runs it, not where
 * it is in the workflow, and the badge set already keeps that distinction
 * (law_event_external_badge()).
 *
 * @param int $event_id law_event post ID.
 * @return string 'flagship' | 'reception' | 'external' | 'hosted'.
 */
function law_slotchart_kind( $event_id ) {
	if ( law_event_meta( $event_id, '_law_is_flagship' ) ) {
		return 'flagship';
	}
	if ( law_event_meta( $event_id, '_law_is_reception' ) ) {
		return 'reception';
	}
	if ( law_event_meta( $event_id, '_law_is_external' ) ) {
		return 'external';
	}
	return 'hosted';
}

/**
 * Every event the current filters select, mapped for the chart.
 *
 * Goes through law_committee_events() so the keyword, status and "run by"
 * filters apply here exactly as they do to the table, with no second
 * implementation to drift. The flagship is asked for explicitly: it is kept out
 * of the review queue on purpose (no workflow, fee, slot or invoice) but it
 * occupies a whole day of the programme and is the single biggest cause of
 * clashes, so a planning view that omitted it would be worse than useless.
 *
 * Memoised: the day tabs need the per-day counts before the chart renders the
 * days themselves, and both come from here. Without the memo one page view runs
 * the query twice.
 *
 * @param bool $reset Clear the memo (tests, and anything that writes an event
 *                    and then re-reads within the same request).
 * @return array[] Items, in no particular order.
 */
function law_slotchart_items( $reset = false ) {
	static $items = null;

	if ( $reset ) {
		$items = null;
	}
	if ( null !== $items ) {
		return $items;
	}

	$posts = law_committee_events(
		array(
			'posts_per_page'       => -1,
			'law_include_flagship' => true,
		)
	);

	$items = array_map( 'law_slotchart_item', $posts );

	return $items;
}

/**
 * Items grouped by day, plus the ones with no usable start.
 *
 * The days are the configured programme week AND any other date that carries an
 * event. law_calendar_events_by_date() drops an out-of-week event into its
 * unscheduled bucket, which is right for a public programme with five day tabs
 * and wrong here: the flagship screen already warns that a date can fall
 * outside the week (admin/flagship-screen.php), so the committee can and does
 * produce such an event, and a planning view must show it rather than hide it.
 *
 * @param array[] $items From law_slotchart_items().
 * @return array{days:array<string,array[]>, unscheduled:array[]}
 */
function law_slotchart_days( array $items ) {
	$days        = array_fill_keys( array_keys( law_calendar_week_days() ), array() );
	$unscheduled = array();

	foreach ( $items as $item ) {
		if ( '' === $item['date'] || null === $item['start'] ) {
			$unscheduled[] = $item;
			continue;
		}
		if ( ! isset( $days[ $item['date'] ] ) ) {
			$days[ $item['date'] ] = array();
		}
		$days[ $item['date'] ][] = $item;
	}

	ksort( $days );

	usort(
		$unscheduled,
		static function ( $a, $b ) {
			return strcasecmp( $a['title'], $b['title'] );
		}
	);

	return array( 'days' => $days, 'unscheduled' => $unscheduled );
}

/**
 * The time axis for one day: where the ruler starts and ends, in minutes.
 *
 * Computed from the day's own events rather than fixed at, say, 08:00-23:00.
 * Only one day is on screen at a time, so a varying scale is not confusing, and
 * a Wednesday carrying nothing but an evening reception then renders 18:00 to
 * 22:30 instead of fourteen empty hours the committee has to scroll past.
 *
 * It opens half an hour before the first event, and that opening step goes
 * unlabelled: see below.
 *
 * @param array[] $day_items Items for one date.
 * @return array{from:int, to:int, span:int} Minutes from midnight, and the span.
 */
function law_slotchart_axis( array $day_items ) {
	$starts = array();
	$ends   = array();
	foreach ( $day_items as $item ) {
		if ( null !== $item['start'] ) {
			$starts[] = $item['start'];
			$ends[]   = null === $item['end'] ? $item['start'] : $item['end'];
		}
	}

	if ( ! $starts ) {
		// An empty day still needs an axis: its section renders the ruler with
		// no bars under it, which reads as free capacity rather than as a bug.
		$from = 9 * 60;
		$to   = 18 * 60;
	} else {
		$from = (int) floor( min( $starts ) / LAW_SLOTCHART_STEP ) * LAW_SLOTCHART_STEP;
		$to   = (int) ceil( max( $ends ) / LAW_SLOTCHART_STEP ) * LAW_SLOTCHART_STEP;
	}

	// One step of lead-in before the first event. Ruler labels are centred on
	// the moment they name, so the one at the very left of the scroller is cut
	// in half by its own edge: starting half an hour early gives the first real
	// label somewhere to sit. The part draws no label for this first step,
	// which is why it is not simply padding (Denis, 15 September 2026).
	$from = max( 0, $from - LAW_SLOTCHART_STEP );

	// A day holding one short event would otherwise be a chart two columns
	// wide, with no room for the ruler to say anything useful.
	$minimum = 3 * 60;
	if ( $to - $from < $minimum ) {
		$to = $from + $minimum;
	}
	if ( $to > 1440 ) {
		$to   = 1440;
		$from = max( 0, min( $from, $to - $minimum ) );
	}

	return array( 'from' => $from, 'to' => $to, 'span' => $to - $from );
}

/**
 * Pack one day's events into rows, so that two events running at once are never
 * on the same row and the chart is no taller than the day's worst clash.
 *
 * Status decides the ORDER events are placed in (law_slotchart_band_order():
 * approved first, then confirmed, and so on), so the approved ones get first
 * refusal on the top rows. It does NOT get a block of rows to itself. Packing
 * each status separately was the first attempt and it left a hole the width of
 * the morning at the top of a busy day: Tuesday's nine confirmed 08:30 events
 * sat in rows 7 to 15 while rows 1 to 6 held approved events starting at 10:30.
 * Every event now falls into the first row with space for it, whatever is
 * already in that row (Denis, 15 September 2026).
 *
 * First fit, over items sorted by start time within each status. Touching is
 * not overlapping: an event ending at 10:00 and one starting at 10:00 share a
 * row, because they do not clash.
 *
 * @param array[] $day_items Items for one date.
 * @return array[][] Rows, top first; each row a list of items in time order.
 */
function law_slotchart_lanes( array $day_items ) {
	$bands = array();
	foreach ( $day_items as $item ) {
		$bands[ $item['status'] ][] = $item;
	}

	// A status nothing in law_slotchart_band_order() names (one added later, or a
	// legacy value) is placed after the named ones rather than dropped: a bar
	// missing from a clash chart is the one failure mode worth ruling out.
	$order = law_slotchart_band_order();
	foreach ( array_keys( $bands ) as $status ) {
		if ( ! in_array( $status, $order, true ) ) {
			$order[] = $status;
		}
	}

	$lanes = array();

	foreach ( $order as $status ) {
		if ( empty( $bands[ $status ] ) ) {
			continue;
		}

		$items = $bands[ $status ];
		usort(
			$items,
			static function ( $a, $b ) {
				return $a['start'] === $b['start']
					? strcasecmp( $a['title'], $b['title'] )
					: $a['start'] <=> $b['start'];
			}
		);

		foreach ( $items as $item ) {
			$placed = false;
			foreach ( $lanes as $index => $lane ) {
				// A later status fills the gaps an earlier one left, so a row's
				// last event is no longer necessarily its latest: every event
				// already in the row has to be checked, not just the last one.
				foreach ( $lane as $seated ) {
					if ( $seated['start'] < $item['end'] && $seated['end'] > $item['start'] ) {
						continue 2;
					}
				}
				$lanes[ $index ][] = $item;
				$placed            = true;
				break;
			}
			if ( ! $placed ) {
				$lanes[] = array( $item );
			}
		}
	}

	// Rows are filled out of time order once a later status backfills a gap, so
	// each is sorted before it is drawn. Only the order within a row changes;
	// which row an event is in is already settled.
	foreach ( $lanes as $index => $lane ) {
		usort(
			$lanes[ $index ],
			static function ( $a, $b ) {
				return $a['start'] <=> $b['start'];
			}
		);
	}

	return $lanes;
}

/**
 * How many events are running in each step of the day's axis.
 *
 * The client asked for "a running total of events confirmed at a particular
 * time slot". The bars answer WHERE the clashes are without ever stating a
 * number, so this is the number, rendered as one row of figures above the bars
 * it describes. Nothing opens and nothing expands: there is no drill-down on
 * this view (Denis, 15 September 2026).
 *
 * A step counts an event when the two overlap at all, so a 19:45 start is
 * counted in the 19:30 step. Half-open on both sides, so an event ending
 * exactly at 10:00 is not counted in the step beginning 10:00.
 *
 * @param array[] $day_items Items for one date.
 * @param array   $axis      From law_slotchart_axis().
 * @return array<int,int> Step start (minutes from midnight) => count.
 */
function law_slotchart_density( array $day_items, array $axis ) {
	$counts = array();
	for ( $at = $axis['from']; $at < $axis['to']; $at += LAW_SLOTCHART_STEP ) {
		$counts[ $at ] = 0;
		foreach ( $day_items as $item ) {
			if ( null === $item['start'] || null === $item['end'] ) {
				continue;
			}
			if ( $item['start'] < $at + LAW_SLOTCHART_STEP && $item['end'] > $at ) {
				++$counts[ $at ];
			}
		}
	}
	return $counts;
}

/**
 * "08:30", from minutes past midnight. 1440 is midnight at the END of the day,
 * which has to read as 24:00 rather than wrapping to 00:00 and looking like the
 * chart starts where it finishes.
 *
 * @param int $minutes Minutes from midnight.
 */
function law_slotchart_time_label( $minutes ) {
	$minutes = (int) $minutes;
	if ( $minutes >= 1440 ) {
		return '24:00';
	}
	return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
}

/**
 * The bar's tooltip and accessible name: title, times, status, and the kind
 * when it is not an ordinary hosted event.
 *
 * It carries the TRUE times even where the bar cannot draw them (a very short
 * event is widened to stay legible, and an open-ended one is drawn at the
 * two-hour default), so the exact answer is always one hover or one screen
 * reader away from the approximate picture.
 *
 * @param array $item From law_slotchart_item().
 */
function law_slotchart_item_label( array $item ) {
	$when = $item['start_label'];
	if ( '' !== $item['end_label'] ) {
		$when .= '–' . $item['end_label'];
	} elseif ( '' !== $when ) {
		$when .= ' ' . __( 'onwards', 'law' );
	}

	$parts = array_filter( array( $item['title'], $when, $item['status_label'] ) );
	if ( 'hosted' !== $item['kind'] ) {
		$kinds = array(
			'flagship'  => __( 'Flagship conference', 'law' ),
			'reception' => __( 'Reception', 'law' ),
			'external'  => __( 'External event', 'law' ),
		);
		$parts[] = $kinds[ $item['kind'] ] ?? '';
	}

	return implode( ' · ', array_filter( $parts ) );
}
