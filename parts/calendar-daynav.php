<?php
/**
 * The programme's day tabs: one row of days, each with its count of events
 * and, on the flagship's day, a "Flagship" pill -- and, on a day carrying a
 * drinks reception, a "Reception" one beside it.
 *
 * Rendered by parts/calendar-body.php between the filters and the results, as
 * a direct child of .law-cal rather than inside .law-cal-controls: the bar is
 * sticky and a sticky element only sticks within its parent's box, so it has
 * to share a parent with the results it scrolls over. The committee's timeline
 * view (templates/account-dashboard.php) renders it under the same rule.
 *
 * Server-rendered as plain jump links (#day-YYYY-MM-DD), which is what they
 * are without JavaScript, when every day renders stacked. calendar-tabs.js
 * turns them into a WAI-ARIA tablist and shows one day at a time; the data
 * attributes are what it reads. calendar-filters.js greys a day out after a
 * filter fetch leaves it empty.
 *
 * With no $args it reads the public programme's own data (its own counts
 * include the flagship on its day, while it survives the filters), which is what
 * the calendar pages want. The
 * dashboard passes its own, because its counts are of a different, differently
 * filtered set of events:
 *
 * get_template_part( 'parts/calendar-daynav', null, array(
 *   'days'          => array( 'Y-m-d' => 'Monday 30 November', ... ),
 *   'counts'        => array( 'Y-m-d' => 4, ... ),
 *   'flagship_date' => 'Y-m-d',   // '' for no flagship pill. The committee's
 *                                 // timeline view supplies its own and is NOT
 *                                 // affected by the programme's filters.
 *   'reception_dates' => array( 'Y-m-d', ... ), // Days that get a "Reception"
 *                                 // pill. Same rule as flagship_date: a caller
 *                                 // supplying its own counts supplies these too,
 *                                 // or passes an empty array for none.
 *   'label'         => 'Programme days',
 * ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_days = isset( $args['days'] ) && is_array( $args['days'] ) ? $args['days'] : law_calendar_week_days();
$law_label = (string) ( $args['label'] ?? __( 'Programme days', 'law' ) );

// Counts and emptiness come from one source or the other, never a mix: the
// caller's map, or the programme's own. law_calendar_day_is_empty() knows that a
// day holding only the flagship block still has something to show, which a bare
// count of cards cannot, so it stays in charge where it applies.
$law_has_counts = isset( $args['counts'] ) && is_array( $args['counts'] );
$law_counts     = $law_has_counts ? $args['counts'] : array();

if ( array_key_exists( 'flagship_date', $args ) ) {
	$law_flagship_date = (string) $args['flagship_date'];
} else {
	// The VISIBLE flagship (since 16 September 2026): it answers the filters now,
	// so a search it does not match takes its "Flagship" pill and its +1 with it.
	$law_flagship      = function_exists( 'law_calendar_visible_flagship_event' ) ? law_calendar_visible_flagship_event() : null;
	$law_flagship_date = $law_flagship ? (string) ( $law_flagship['date'] ?? '' ) : '';
}

if ( array_key_exists( 'reception_dates', $args ) ) {
	$law_reception_dates = array_map( 'strval', (array) $args['reception_dates'] );
} else {
	// The VISIBLE receptions, on the same rule as the flagship above: the
	// resolver reads the already-filtered day buckets, so a keyword search that
	// hides a reception takes its pill with it.
	$law_reception_dates = function_exists( 'law_calendar_reception_dates' ) ? law_calendar_reception_dates() : array();
}

if ( ! $law_has_counts ) {
	$law_by_date = law_calendar_events_by_date();
	foreach ( array_keys( $law_days ) as $law_date ) {
		// The flagship counts as one of its day's events. law_calendar_events()
		// keeps it out of the card list because it is rendered as a block rather
		// than a card, but to a reader it is still an event on that day, and a
		// day holding the conference and one reception has to read "2 events".
		// Only while it is VISIBLE: a filtered-out conference is not on the day.
		// parts/calendar-events.php adds the same 1 to the day section's
		// data-count, which is what calendar-tabs.js re-reads after a filter
		// fetch, so the two agree.
		$law_counts[ $law_date ] = count( $law_by_date[ $law_date ] ?? array() )
			+ ( '' !== $law_flagship_date && $law_date === $law_flagship_date ? 1 : 0 );
	}
}
?>
<nav
	class="law-cal-daynav"
	aria-label="<?php echo esc_attr( $law_label ); ?>"
	data-law-daynav="tabs"
	data-today="<?php echo esc_attr( law_calendar_today_key() ); ?>"
	data-flagship-day="<?php echo esc_attr( $law_flagship_date ); ?>"
	data-reception-days="<?php echo esc_attr( implode( ',', $law_reception_dates ) ); ?>"
>
	<?php foreach ( $law_days as $law_date => $law_heading ) : ?>
		<?php
		$law_is_flag = '' !== $law_flagship_date && $law_date === $law_flagship_date;
		// Shared with parts/calendar-events.php: on the programme a day is empty
		// only when it has neither cards nor a visible flagship block. A caller
		// supplying counts has already decided.
		//
		// $law_is_flag drives both the pill and law_calendar_day_count_text()'s
		// second argument, so when the filters hide the conference the pill goes
		// and the tab falls through to "No events" in the same expression.
		$law_day_empty = $law_has_counts
			? empty( $law_counts[ $law_date ] )
			: law_calendar_day_is_empty( $law_date );

		// The reception pill runs on presence, not on booking state: a
		// reception whose places are not released yet still marks its day, in
		// parity with the conference (Denis, 16 September 2026).
		$law_is_reception_day = in_array( $law_date, $law_reception_dates, true );
		?>
		<a
			class="law-cal-daynav__link<?php echo $law_day_empty ? ' is-empty' : ''; ?>"
			href="#day-<?php echo esc_attr( $law_date ); ?>"
			data-day="<?php echo esc_attr( $law_date ); ?>"
			<?php echo $law_day_empty ? 'aria-disabled="true" tabindex="-1"' : ''; ?>
		><?php echo esc_html( law_calendar_day_nav_label( $law_date ) ); ?><span class="law-cal-daynav__count"><?php echo esc_html( law_calendar_day_count_text( (int) ( $law_counts[ $law_date ] ?? 0 ), $law_is_flag ) ); ?></span><?php /* Always rendered, empty or not: calendar-tabs.js adds and removes the pills inside it after a filter fetch and needs somewhere to put them. A flex row, so Wednesday's two pills sit side by side and the bar's height does not change -- --law-cal-daynav-h is the sticky scroll offset for every anchor on the page and is a fixed value, not a measured one. */ ?><span class="law-cal-daynav__flags"><?php if ( $law_is_flag ) : ?><span class="law-cal-daynav__flag"><?php esc_html_e( 'Flagship', 'law' ); ?></span><?php endif; ?><?php if ( $law_is_reception_day ) : ?><span class="law-cal-daynav__flag law-cal-daynav__flag--reception"><?php esc_html_e( 'Reception', 'law' ); ?></span><?php endif; ?></span></a>
	<?php endforeach; ?>
</nav>
