<?php
/**
 * The programme's day tabs: five days in one row, each with its count of
 * events and, on the flagship's day, a "Flagship" pill.
 *
 * Rendered by parts/calendar-body.php between the filters and the results, as
 * a direct child of .law-cal rather than inside .law-cal-controls: the bar is
 * sticky and a sticky element only sticks within its parent's box, so it has
 * to share a parent with the results it scrolls over.
 *
 * Server-rendered as plain jump links (#day-YYYY-MM-DD), which is what they
 * are without JavaScript, when every day renders stacked. calendar-tabs.js
 * turns them into a WAI-ARIA tablist and shows one day at a time; the data
 * attributes are what it reads. calendar-filters.js greys a day out after a
 * filter fetch leaves it empty.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_days    = law_calendar_week_days();
$law_by_date = law_calendar_events_by_date();

$law_flagship      = function_exists( 'law_calendar_flagship_event' ) ? law_calendar_flagship_event() : null;
$law_flagship_date = $law_flagship ? (string) ( $law_flagship['date'] ?? '' ) : '';
?>
<nav
	class="law-cal-daynav"
	aria-label="<?php esc_attr_e( 'Programme days', 'law' ); ?>"
	data-law-daynav="tabs"
	data-today="<?php echo esc_attr( law_calendar_today_key() ); ?>"
	data-flagship-day="<?php echo esc_attr( $law_flagship_date ); ?>"
>
	<?php foreach ( $law_days as $law_date => $law_heading ) : ?>
		<?php
		// Shared with parts/calendar-events.php: the flagship's day is never
		// empty while it is published, however the list is filtered.
		$law_day_empty = law_calendar_day_is_empty( $law_date );
		$law_is_flag   = '' !== $law_flagship_date && $law_date === $law_flagship_date;
		?>
		<a
			class="law-cal-daynav__link<?php echo $law_day_empty ? ' is-empty' : ''; ?>"
			href="#day-<?php echo esc_attr( $law_date ); ?>"
			data-day="<?php echo esc_attr( $law_date ); ?>"
			<?php echo $law_day_empty ? 'aria-disabled="true" tabindex="-1"' : ''; ?>
		><?php echo esc_html( law_calendar_day_nav_label( $law_date ) ); ?><span class="law-cal-daynav__count"><?php echo esc_html( law_calendar_day_count_text( count( $law_by_date[ $law_date ] ?? array() ), $law_is_flag ) ); ?></span><?php if ( $law_is_flag ) : ?><span class="law-cal-daynav__flag"><?php esc_html_e( 'Flagship', 'law' ); ?></span><?php endif; ?></a>
	<?php endforeach; ?>
</nav>
