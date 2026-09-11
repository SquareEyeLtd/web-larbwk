<?php
/**
 * The flagship conference's block on the programme.
 *
 * Pinned under its own day by parts/calendar-events.php whatever the filters
 * say, so it is not an ordinary event card and deliberately does not reuse
 * parts/loop/event.php: it is a two-column block, photograph left, and it
 * lists the day's sessions with their times rather than one time range.
 *
 * It does reuse .law-event-card__actions and .law-event-card__button for the
 * calls to action, so the buttons' mobile full-width rule and their focus styles
 * cannot drift from every other card on the page. Since 11 September 2026 that
 * is two buttons: Event details, and the application control
 * (law_flagship_card_action()), matching the Register button the ordinary cards
 * gained at the same time.
 *
 * get_template_part( 'parts/events/flagship-card', null, array(
 *   'event'       => <hydrated calendar event array>, // required
 *   'show_status' => false, // Committee mode: the status pill and edit link.
 * ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_fc_event  = isset( $args['event'] ) && is_array( $args['event'] ) ? $args['event'] : array();
$law_fc_status = ! empty( $args['show_status'] );

if ( empty( $law_fc_event['id'] ) || '' === trim( (string) ( $law_fc_event['title'] ?? '' ) ) ) {
	return;
}

$law_fc_url   = (string) ( $law_fc_event['url'] ?? '' );
$law_fc_venue = trim( (string) ( $law_fc_event['venue'] ?? '' ) );
$law_fc_time  = function_exists( 'law_calendar_event_time_label' ) ? law_calendar_event_time_label( $law_fc_event ) : '';

// The same photograph the flagship page's own hero uses, falling back to the
// same default, so the block and the page it links to always match.
$law_fc_image = function_exists( 'law_event_hero_image_url' )
	? law_event_hero_image_url( $law_fc_event['id'], 'medium_large' )
	: '';
$law_fc_alt   = '';
if ( '' !== $law_fc_image ) {
	$law_fc_attachment = absint( law_event_meta( $law_fc_event['id'], '_law_hero_image_id' ) );
	$law_fc_alt        = $law_fc_attachment ? (string) get_post_meta( $law_fc_attachment, '_wp_attachment_image_alt', true ) : '';
} else {
	$law_fc_image = law_hero_default_image_url();
}

$law_fc_sessions = isset( $law_fc_event['sessions'] ) && is_array( $law_fc_event['sessions'] ) ? $law_fc_event['sessions'] : array();

$law_fc_meta = array_filter( array( $law_fc_time, $law_fc_venue ), 'strlen' );

// Apply, or whatever this viewer's application state offers instead (their
// booking, their unpaid charge, their application under review). The same
// decision the conference page's own control reads, so the two cannot disagree,
// and like the ordinary cards this carries the button but NO dialog:
// booking-form.js fetches the apply dialog on the press. See
// law_flagship_card_action().
$law_fc_action = function_exists( 'law_flagship_card_action' ) ? law_flagship_card_action( $law_fc_event ) : null;
?>
<article class="law-flagship-card" aria-labelledby="law-flagship-card-title">
	<div class="law-flagship-card__media">
		<img src="<?php echo esc_url( $law_fc_image ); ?>" alt="<?php echo esc_attr( $law_fc_alt ); ?>" loading="lazy">
	</div>
	<div class="law-flagship-card__body">
		<?php if ( $law_fc_status ) : ?>
			<?php law_calendar_status_badge( $law_fc_event ); ?>
		<?php endif; ?>
		<span class="law-flagship-card__badge"><?php esc_html_e( 'Flagship event', 'law' ); ?></span>
		<h3 id="law-flagship-card-title" class="law-flagship-card__title">
			<a href="<?php echo esc_url( $law_fc_url ); ?>"><?php echo esc_html( $law_fc_event['title'] ); ?></a>
			<?php
			if ( $law_fc_status ) {
				law_calendar_edit_link( $law_fc_event );
			}
			?>
		</h3>
		<?php if ( $law_fc_meta ) : ?>
			<p class="law-flagship-card__meta"><?php echo esc_html( implode( ' · ', $law_fc_meta ) ); ?></p>
		<?php endif; ?>
		<?php if ( $law_fc_sessions ) : ?>
			<ul class="law-flagship-card__sessions">
				<?php foreach ( $law_fc_sessions as $law_fc_session ) : ?>
					<?php
					// Same fallback order as the timeline on the event page, so a
					// session with no title reads the same in both places.
					$law_fc_label = trim( (string) ( $law_fc_session['title'] ?? '' ) );
					if ( '' === $law_fc_label ) {
						$law_fc_label = (string) ( $law_fc_session['time_label'] ?? '' ) ?: __( 'Session', 'law' );
					}

					// 12-hour, the format the flagship page's own agenda and every
					// event's Time fact use. The row's stored time_label is 24-hour with
					// an en dash, which would print two different clocks for the same
					// session across two pages.
					$law_fc_session_time = '';
					if ( ! empty( $law_fc_session['start'] ) ) {
						$law_fc_session_time = law_calendar_time_12h( $law_fc_session['start'] );
						if ( ! empty( $law_fc_session['end'] ) ) {
							$law_fc_session_time .= ' – ' . law_calendar_time_12h( $law_fc_session['end'] );
						}
					}
					?>
					<li>
						<?php if ( '' !== $law_fc_session_time && $law_fc_session_time !== $law_fc_label ) : ?>
							<span class="law-flagship-card__session-time"><?php echo esc_html( $law_fc_session_time ); ?></span>
						<?php endif; ?>
						<span class="law-flagship-card__session-title"><?php echo esc_html( $law_fc_label ); ?></span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php else : ?>
			<p class="law-flagship-card__meta"><?php esc_html_e( 'Programme to be announced', 'law' ); ?></p>
		<?php endif; ?>
		<div class="law-event-card__actions law-flagship-card__actions">
			<a class="button law-event-card__button" href="<?php echo esc_url( $law_fc_url ); ?>"><?php esc_html_e( 'Event details', 'law' ); ?></a>
			<?php if ( $law_fc_action ) : ?>
				<a
					class="button law-event-card__button <?php echo esc_attr( (string) $law_fc_action['class'] ); ?>"
					href="<?php echo esc_url( (string) $law_fc_action['url'] ); ?>"
					<?php echo ! empty( $law_fc_action['sr_label'] ) ? ' aria-label="' . esc_attr( (string) $law_fc_action['sr_label'] ) . '"' : ''; ?>
					<?php echo ! empty( $law_fc_action['dialog'] ) ? ' data-law-book="' . esc_attr( (string) (int) $law_fc_action['dialog'] ) . '"' : ''; ?>
				><?php echo esc_html( (string) $law_fc_action['label'] ); ?></a>
			<?php endif; ?>
		</div>
	</div>
</article>
