<?php
/**
 * Programme events grouped by day: navy day bars, orange slot bars, event
 * cards. Rendered inside #law-cal-events on the calendar pages and returned
 * on its own by the &law_partial=1 AJAX endpoint.
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

$law_has_events = ! empty( $law_unsched );
foreach ( array_keys( $law_days ) as $law_date ) {
	if ( ! empty( $law_by_date[ $law_date ] ) ) {
		$law_has_events = true;
		break;
	}
}
?>

<?php if ( ! $law_has_events ) : ?>
	<p class="law-cal__empty"><?php echo esc_html( law_calendar_empty_message() ); ?></p>
<?php endif; ?>

<?php foreach ( $law_days as $law_date => $law_heading ) : ?>
	<?php
	if ( empty( $law_by_date[ $law_date ] ) ) {
		continue;
	}
	?>
	<section class="law-cal-day-section" id="day-<?php echo esc_attr( $law_date ); ?>" aria-label="<?php echo esc_attr( law_calendar_day_heading( $law_date ) ); ?>">
		<h2 class="law-cal-day-bar"><?php echo esc_html( law_calendar_day_heading( $law_date ) ); ?></h2>
		<?php
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
