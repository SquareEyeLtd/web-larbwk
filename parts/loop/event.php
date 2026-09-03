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
 *   'meta_lines'  => array(), // Extra meta lines below the venue/host, e.g. payment status.
 *   'actions'     => array(), // Button overrides: array of
 *                             // { label, url, arrow (bool), external (bool) }.
 *                             // Defaults to Event details + the Register placeholder.
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
$law_meta_lines   = isset( $args['meta_lines'] ) && is_array( $args['meta_lines'] ) ? array_filter( array_map( 'strval', $args['meta_lines'] ) ) : array();

$law_actions = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();
if ( ! $law_actions ) {
	$law_actions = array(
		array(
			'label' => __( 'Event details', 'law' ),
			'url'   => $law_event_url,
		),
		// Registration is not wired up yet; the button is a placeholder.
		array(
			'label'    => __( 'Register', 'law' ),
			'url'      => '#',
			'arrow'    => true,
			'disabled' => true,
		),
	);
}

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
		<?php foreach ( $law_meta_lines as $law_meta_line ) : ?>
			<p class="law-event-card__meta"><?php echo esc_html( $law_meta_line ); ?></p>
		<?php endforeach; ?>
		<?php law_calendar_sponsored_label( $event ); ?>
	</div>
	<div class="law-event-card__actions">
		<?php foreach ( $law_actions as $law_action ) : ?>
			<?php
			$law_action_url = (string) ( $law_action['url'] ?? '' );
			if ( '' === $law_action_url || '' === (string) ( $law_action['label'] ?? '' ) ) {
				continue;
			}
			$law_action_arrow = ! empty( $law_action['arrow'] );
			?>
			<a
				class="button law-event-card__button<?php echo $law_action_arrow ? ' law-event-card__button--register' : ''; ?>"
				href="<?php echo esc_url( $law_action_url ); ?>"
				<?php echo ! empty( $law_action['disabled'] ) ? ' aria-disabled="true"' : ''; ?>
				<?php echo ! empty( $law_action['external'] ) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>
			>
				<?php echo esc_html( $law_action['label'] ); ?>
				<?php if ( $law_action_arrow ) : ?>
					<svg class="law-event-card__arrow" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M14 3h7v7"/><path d="M10 14 21 3"/></svg>
				<?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>
</article>
