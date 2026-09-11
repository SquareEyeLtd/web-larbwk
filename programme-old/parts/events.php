<?php
/**
 * The ORIGINAL programme list, kept for reference behind ?variant=old
 * (programme-old/README.md): every day stacked, navy day bars, orange slot
 * bars, the flagship block under its day, full cards. A verbatim copy of
 * parts/calendar-events.php as it was before the day-tabs layout became the
 * default. Rendered by programme-old/template.php and returned on its own by
 * the module's &law_partial=1 endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args            = isset( $args ) && is_array( $args ) ? $args : array();
$law_show_status = ! empty( $args['show_status'] );

$law_days    = law_calendar_week_days();
$law_by_date = law_calendar_events_by_date();
$law_unsched = $law_by_date['_unscheduled'] ?? array();

// The flagship conference is pinned to its own day whatever the filters say:
// it is the main event of the week, and a delegate searching for something
// else should still see it. law_calendar_events() excludes it from the ordinary
// cards, so it appears exactly once, here.
$law_flagship      = function_exists( 'law_calendar_flagship_event' ) ? law_calendar_flagship_event() : null;
$law_flagship_date = $law_flagship ? (string) ( $law_flagship['date'] ?? '' ) : '';

$law_has_events = ! empty( $law_unsched );
foreach ( array_keys( $law_days ) as $law_date ) {
	if ( ! empty( $law_by_date[ $law_date ] ) ) {
		$law_has_events = true;
		break;
	}
}
?>

<?php
// A flagship whose date falls outside the configured programme week (someone
// moved the week in LAW → Events settings, or typed the wrong year) has no day
// section to sit under. Rendered here rather than dropped, so a configuration
// mistake is visible instead of silently costing the site its main event.
if ( $law_flagship && ! isset( $law_days[ $law_flagship_date ] ) ) {
	get_template_part(
		'parts/events/flagship-card',
		null,
		array( 'event' => $law_flagship, 'show_status' => $law_show_status )
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
	<section class="law-cal-day-section" id="day-<?php echo esc_attr( $law_date ); ?>" aria-label="<?php echo esc_attr( law_calendar_day_heading( $law_date ) ); ?>">
		<h2 class="law-cal-day-bar"><?php echo esc_html( law_calendar_day_heading( $law_date ) ); ?></h2>
		<?php
		// Above the day's slot bars: the flagship is the day, not one slot in it.
		if ( $law_flagship && $law_date === $law_flagship_date ) {
			get_template_part(
				'parts/events/flagship-card',
				null,
				array( 'event' => $law_flagship, 'show_status' => $law_show_status )
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
				)
			);
		}
		?>
	</section>
<?php endif; ?>
