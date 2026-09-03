<?php
/**
 * Programme event card. Used by the calendar day listings and the single
 * speaker profile ("Speaking at").
 *
 * get_template_part( 'parts/loop/event', null, array(
 *   'event'       => $event,  // Mapped event from law_calendar_map_entry(). Required.
 *   'url'         => '',      // Optional link override, e.g. law_speaker_event_link().
 *   'show_status' => false,   // Committee status badge.
 *   'show_date'   => false,   // Prefix the time with the full date, for cards shown outside the calendar.
 * ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args  = isset( $args ) && is_array( $args ) ? $args : array();
$event = isset( $args['event'] ) && is_array( $args['event'] ) ? $args['event'] : null;
if ( ! $event ) {
	return;
}

$law_event_url    = ! empty( $args['url'] ) ? (string) $args['url'] : (string) ( $event['url'] ?? '' );
$law_show_status  = ! empty( $args['show_status'] );
$law_time_label   = law_calendar_event_time_label( $event );
$law_hosted       = law_calendar_hosted_by( $event );

$law_time_parts = array();
if ( ! empty( $args['show_date'] ) && ! empty( $event['date'] ) ) {
	$law_time_parts[] = law_calendar_day_heading( $event['date'] );
}
if ( '' !== $law_time_label ) {
	$law_time_parts[] = $law_time_label;
}
?>
<article class="<?php echo esc_attr( law_calendar_card_classes( $event, 'law-event-card' ) ); ?>">
	<div class="law-event-card__body">
		<?php if ( $law_show_status ) : ?>
			<?php law_calendar_status_badge( $event ); ?>
		<?php endif; ?>
		<h4 class="law-event-card__title">
			<a href="<?php echo esc_url( $law_event_url ); ?>"><?php echo esc_html( $event['title'] ); ?></a>
			<?php law_calendar_edit_link( $event ); ?>
		</h4>
		<?php if ( $law_time_parts ) : ?>
			<p class="law-event-card__time"><?php echo esc_html( implode( ' · ', $law_time_parts ) ); ?></p>
		<?php endif; ?>
		<?php if ( ! empty( $event['venue'] ) ) : ?>
			<p class="law-event-card__meta"><?php echo esc_html( $event['venue'] ); ?></p>
		<?php endif; ?>
		<?php if ( $law_hosted ) : ?>
			<p class="law-event-card__meta"><?php echo esc_html( $law_hosted ); ?></p>
		<?php endif; ?>
		<?php law_calendar_sponsored_label( $event ); ?>
	</div>
	<div class="law-event-card__actions">
		<a class="button law-event-card__button" href="<?php echo esc_url( $law_event_url ); ?>"><?php esc_html_e( 'Event details', 'law' ); ?></a>
		<?php /* Registration is not wired up yet; the button is a placeholder. */ ?>
		<a class="button law-event-card__button law-event-card__button--register" href="#" aria-disabled="true">
			<?php esc_html_e( 'Register', 'law' ); ?>
			<svg class="law-event-card__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 3h7v7"/><path d="M10 14 21 3"/></svg>
		</a>
	</div>
</article>
