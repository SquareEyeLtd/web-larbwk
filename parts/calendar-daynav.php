<?php
/**
 * The programme's day tabs: one row of days, each with its count of events
 * and, on the flagship's day, a "Flagship" pill.
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
 * With no $args it reads the public programme's own data, which is what the
 * calendar pages want. The dashboard passes its own, because the programme's
 * counts are of a different, differently filtered set of events and exclude
 * the flagship:
 *
 * get_template_part( 'parts/calendar-daynav', null, array(
 *   'days'          => array( 'Y-m-d' => 'Monday 30 November', ... ),
 *   'counts'        => array( 'Y-m-d' => 4, ... ),
 *   'flagship_date' => 'Y-m-d',   // '' for no flagship pill
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
// caller's map, or the programme's own. law_calendar_day_is_empty() knows the
// flagship keeps its day alive however the list is filtered, which a bare
// count cannot, so it stays in charge where it applies.
$law_has_counts = isset( $args['counts'] ) && is_array( $args['counts'] );
$law_counts     = $law_has_counts ? $args['counts'] : array();

if ( array_key_exists( 'flagship_date', $args ) ) {
	$law_flagship_date = (string) $args['flagship_date'];
} else {
	$law_flagship      = function_exists( 'law_calendar_flagship_event' ) ? law_calendar_flagship_event() : null;
	$law_flagship_date = $law_flagship ? (string) ( $law_flagship['date'] ?? '' ) : '';
}

if ( ! $law_has_counts ) {
	$law_by_date = law_calendar_events_by_date();
	foreach ( array_keys( $law_days ) as $law_date ) {
		$law_counts[ $law_date ] = count( $law_by_date[ $law_date ] ?? array() );
	}
}
?>
<nav
	class="law-cal-daynav"
	aria-label="<?php echo esc_attr( $law_label ); ?>"
	data-law-daynav="tabs"
	data-today="<?php echo esc_attr( law_calendar_today_key() ); ?>"
	data-flagship-day="<?php echo esc_attr( $law_flagship_date ); ?>"
>
	<?php foreach ( $law_days as $law_date => $law_heading ) : ?>
		<?php
		$law_is_flag = '' !== $law_flagship_date && $law_date === $law_flagship_date;
		// Shared with parts/calendar-events.php: on the programme the flagship's
		// day is never empty while it is published, however the list is
		// filtered. A caller supplying counts has already decided.
		$law_day_empty = $law_has_counts
			? empty( $law_counts[ $law_date ] )
			: law_calendar_day_is_empty( $law_date );
		?>
		<a
			class="law-cal-daynav__link<?php echo $law_day_empty ? ' is-empty' : ''; ?>"
			href="#day-<?php echo esc_attr( $law_date ); ?>"
			data-day="<?php echo esc_attr( $law_date ); ?>"
			<?php echo $law_day_empty ? 'aria-disabled="true" tabindex="-1"' : ''; ?>
		><?php echo esc_html( law_calendar_day_nav_label( $law_date ) ); ?><span class="law-cal-daynav__count"><?php echo esc_html( law_calendar_day_count_text( (int) ( $law_counts[ $law_date ] ?? 0 ), $law_is_flag ) ); ?></span><?php if ( $law_is_flag ) : ?><span class="law-cal-daynav__flag"><?php esc_html_e( 'Flagship', 'law' ); ?></span><?php endif; ?></a>
	<?php endforeach; ?>
</nav>
