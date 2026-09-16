<?php
/**
 * Programme events grouped by day: the flagship strip, then one section per
 * day -- its heading, the flagship block on its day, slot headings, compact
 * event rows. calendar-tabs.js shows one day section at a time behind the day
 * tabs (parts/calendar-daynav.php) and reads each section's data-count for the
 * tab labels; without JavaScript every day renders stacked. Rendered inside
 * #law-cal-events on the calendar pages and returned on its own by the
 * &law_partial=1 AJAX endpoint.
 *
 * get_template_part( 'parts/calendar-events', null, array(
 *   'show_status' => false, // Committee status badges on cards.
 * ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args            = isset( $args ) && is_array( $args ) ? $args : array();
$law_show_status = ! empty( $args['show_status'] );

$law_days    = law_calendar_week_days();
$law_by_date = law_calendar_events_by_date();
$law_unsched = $law_by_date['_unscheduled'] ?? array();

// The flagship conference is pinned to its own day, above that day's slot bars,
// and law_calendar_events() excludes it from the ordinary cards so it appears
// exactly once -- here. Since 16 September 2026 it is the VISIBLE flagship: it
// answers keyword, sector and type like any other event, and a search it does
// not match drops the block and the strip together (Denis). Before that it was
// pinned whatever the filters said.
$law_flagship      = function_exists( 'law_calendar_visible_flagship_event' ) ? law_calendar_visible_flagship_event() : null;
$law_flagship_date = $law_flagship ? (string) ( $law_flagship['date'] ?? '' ) : '';

// The days carrying a visible reception, for the day tabs' "Reception" pill.
// Emitted on the marker below for the same reason the flagship's day is: the
// nav is never swapped by a filter fetch, so the script has to be told.
$law_reception_days = function_exists( 'law_calendar_reception_dates' ) ? law_calendar_reception_dates() : array();

// The keyword to mark in the cards, read HERE and passed down, never reached for
// inside the parts. parts/loop/event.php is shared with the speaker profile, My
// events and My bookings, and ?law_kw= is also the query var for four unrelated
// dashboards, so a card that read global state would highlight itself on pages
// nobody asked about (Denis, 16 September 2026).
$law_kw = function_exists( 'law_calendar_filters' ) ? (string) law_calendar_filters()['kw'] : '';

// The flagship counts towards "is there anything on this page". It is not one of
// the cards, so without it a search the CONFERENCE answers but no card does would
// print "No events match this search." directly above a block that plainly
// matches. Only reachable since the flagship started answering the filters.
$law_has_events = ! empty( $law_unsched ) || (bool) $law_flagship;
foreach ( array_keys( $law_days ) as $law_date ) {
	if ( ! empty( $law_by_date[ $law_date ] ) ) {
		$law_has_events = true;
		break;
	}
}
?>

<?php
// The flagship's day, for assets/js/calendar-tabs.js. The day nav lives OUTSIDE
// #law-cal-events and is never swapped by a filter fetch, so the script's cached
// copy of the nav's data-flagship-day goes stale the moment a filter hides the
// conference: the tab would keep its "Flagship" pill and its blank count where it
// should now read "No events".
//
// Emitted on EVERY render, empty value and all, and that is the point. The same
// script runs on the committee's timeline view, whose swapped markup
// (parts/events/slot-chart.php) knows nothing about the flagship, so "no marker
// found" has to stay distinguishable from "the marker says there is no visible
// flagship" -- otherwise filtering the dashboard would strip that view's pill.
// Absent means "not the programme's markup, leave the server's value alone".
//
// One marker, two attributes. The receptions' day list rides on the same
// element rather than a second one, so that "absent" keeps meaning the one
// thing it means today: a day nav rendered over markup that is not the
// programme's, whose pills the script must not touch.
?>
<span class="law-cal-flagship-marker" hidden data-law-flagship-day="<?php echo esc_attr( $law_flagship_date ); ?>" data-law-reception-days="<?php echo esc_attr( implode( ',', $law_reception_days ) ); ?>"></span>

<?php
// The strip: one line naming the flagship and linking to its day, above the
// days, so the main event of the week is in the first screenful whatever day
// is showing. A signpost to the block, not a second copy of it: the block
// still renders under its own day, exactly once, and the strip deliberately
// shares no class name with it (FlagshipRenderTest counts
// `class="law-flagship-card"`). calendar-tabs.js hides the strip while the
// flagship's own day is the one on screen.
if ( $law_flagship ) {
	get_template_part( 'parts/events/flagship-strip', null, array( 'event' => $law_flagship, 'highlight' => $law_kw ) );
}
?>

<?php
// A flagship whose date falls outside the configured programme week (someone
// moved the week in Events → Settings, or typed the wrong year) has no day
// section to sit under. Rendered here rather than dropped, so a configuration
// mistake is visible instead of silently costing the site its main event.
if ( $law_flagship && ! isset( $law_days[ $law_flagship_date ] ) ) {
	get_template_part(
		'parts/events/flagship-card',
		null,
		array( 'event' => $law_flagship, 'show_status' => $law_show_status, 'highlight' => $law_kw )
	);
}
?>

<?php if ( ! $law_has_events ) : ?>
	<p class="law-cal__empty"><?php echo esc_html( law_calendar_empty_message() ); ?></p>
<?php endif; ?>

<?php foreach ( $law_days as $law_date => $law_heading ) : ?>
	<?php
	// One rule, shared with the day nav in parts/calendar-filters.php: a day is
	// empty only when it has neither cards nor the flagship block.
	if ( function_exists( 'law_calendar_day_is_empty' ) ? law_calendar_day_is_empty( $law_date ) : empty( $law_by_date[ $law_date ] ) ) {
		continue;
	}
	?>
	<?php
	// The flagship is one of its day's events in the count, even though it is
	// not one of the cards: calendar-tabs.js re-reads this attribute for the tab
	// labels after a filter fetch, and parts/calendar-daynav.php adds the same 1
	// when it renders them server-side. The 1 rides on $law_flagship, which is
	// the visible one, so a filtered-out conference stops being counted at the
	// same moment it stops being drawn.
	$law_day_count = count( $law_by_date[ $law_date ] ?? array() )
		+ ( $law_flagship && $law_date === $law_flagship_date ? 1 : 0 );
	?>
	<section class="law-cal-day-section" id="day-<?php echo esc_attr( $law_date ); ?>" data-count="<?php echo (int) $law_day_count; ?>" aria-label="<?php echo esc_attr( law_calendar_day_heading( $law_date ) ); ?>">
		<h2 class="law-cal-day-bar"><?php echo esc_html( law_calendar_day_heading( $law_date ) ); ?></h2>
		<?php
		// Above the day's slot bars: the flagship is the day, not one slot in it.
		if ( $law_flagship && $law_date === $law_flagship_date ) {
			get_template_part(
				'parts/events/flagship-card',
				null,
				array( 'event' => $law_flagship, 'show_status' => $law_show_status, 'highlight' => $law_kw )
			);
		}
		$law_last_slot = null;
		foreach ( $law_by_date[ $law_date ] as $law_item ) :
			$law_slot = (string) ( $law_item['time_label'] ?? '' );
			if ( $law_slot !== $law_last_slot ) :
				?>
				<h3 class="law-cal-slot-bar"><?php echo esc_html( law_calendar_event_time_label( $law_item ) ); ?></h3>
				<?php
				$law_last_slot = $law_slot;
			endif;
			get_template_part(
				'parts/loop/event',
				null,
				array(
					'event'       => $law_item,
					'show_status' => $law_show_status,
					'highlight'   => $law_kw,
				)
			);
		endforeach;
		?>
	</section>
<?php endforeach; ?>

<?php if ( ! empty( $law_unsched ) ) : ?>
	<section class="law-cal-day-section" id="day-unscheduled" aria-label="<?php esc_attr_e( 'No confirmed slot', 'law' ); ?>">
		<h2 class="law-cal-day-bar"><?php esc_html_e( 'No confirmed slot', 'law' ); ?></h2>
		<?php
		foreach ( $law_unsched as $law_item ) {
			get_template_part(
				'parts/loop/event',
				null,
				array(
					'event'       => $law_item,
					'show_status' => $law_show_status,
					'highlight'   => $law_kw,
				)
			);
		}
		?>
	</section>
<?php endif; ?>
