<?php
/**
 * The flagship conference's block on the programme.
 *
 * Pinned under its own day by parts/calendar-events.php, which since
 * 16 September 2026 renders it only while the conference answers the active
 * filters. It is not an ordinary event card and deliberately does not reuse
 * parts/loop/event.php: it is a two-column block, photograph left, and it
 * lists the day's sessions with their times rather than one time range.
 *
 * It does reuse .law-event-card__actions and .law-event-card__button for the
 * calls to action, so the buttons' mobile full-width rule and their focus styles
 * cannot drift from every other card on the page. Since 11 September 2026 that
 * is two buttons: Event details, and the registration control
 * (law_flagship_card_action()), which since 15 September 2026 carries the same
 * Register label the ordinary cards do.
 *
 * get_template_part( 'parts/events/flagship-card', null, array(
 *   'event'       => <hydrated calendar event array>, // required
 *   'show_status' => false, // Committee mode: the status pill and edit link.
 *   'highlight'   => '',    // Keyword to mark in the title and the venue.
 *                           // Passed by parts/calendar-events.php only.
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

$law_fc_hl = (string) ( $args['highlight'] ?? '' );

// Pre-escaped parts, imploded after, so the highlighter touches only the VENUE.
// The time can never match a keyword (the filter does not search it) and has no
// business going through a function that decodes entities.
$law_fc_meta = array_filter(
	array(
		esc_html( $law_fc_time ),
		'' !== $law_fc_venue ? law_calendar_highlight( $law_fc_venue, $law_fc_hl ) : '',
	),
	'strlen'
);

// Register, or whatever this viewer's own state offers instead (their ticket,
// their unpaid charge, their registration under review). The same
// decision the conference page's own control reads, so the two cannot disagree,
// and like the ordinary cards this carries the button but NO dialog:
// booking-form.js fetches the registration dialog on the press. See
// law_flagship_card_action().
$law_fc_action = function_exists( 'law_flagship_card_action' ) ? law_flagship_card_action( $law_fc_event ) : null;

// Nothing to press -- registration has not opened, or the conference has been
// and gone. The block keeps its second button and draws it disabled with the
// reason on it, exactly as the ordinary cards do (parts/loop/event.php).
if ( ! $law_fc_action && function_exists( 'law_flagship_card_inert_action' ) ) {
	$law_fc_action = law_flagship_card_inert_action( $law_fc_event );
}
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
			<a href="<?php echo esc_url( $law_fc_url ); ?>"><?php echo law_calendar_highlight( $law_fc_event['title'], $law_fc_hl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
			<?php
			if ( $law_fc_status ) {
				law_calendar_edit_link( $law_fc_event );
			}
			?>
		</h3>
		<?php if ( $law_fc_meta ) : ?>
			<p class="law-flagship-card__meta"><?php echo implode( ' · ', $law_fc_meta ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- both parts escaped above. ?></p>
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
			<?php if ( $law_fc_action && ! empty( $law_fc_action['disabled'] ) ) : ?>
				<?php /* A real disabled <button>: an anchor with aria-disabled is still followed on click and on Enter, and this one has nowhere to go. */ ?>
				<button
					type="button"
					class="button law-event-card__button <?php echo esc_attr( (string) ( $law_fc_action['class'] ?? '' ) ); ?>"
					disabled
					aria-disabled="true"
				><?php echo esc_html( (string) $law_fc_action['label'] ); ?></button>
			<?php elseif ( $law_fc_action ) : ?>
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
