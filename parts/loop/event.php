<?php
/**
 * Programme event card. Used by the calendar day listings and the single
 * speaker profile's role lists ("Speaking at", "Hosting", "Moderating at").
 *
 * get_template_part( 'parts/loop/event', null, array(
 *   'event'       => $event,  // Mapped event from law_calendar_map_entry(). Required.
 *   'url'         => '',      // Optional link override, e.g. law_speaker_event_link().
 *   'show_status' => false,   // Committee status badge.
 *   'badge'       => array(), // { label, slug }: one extra badge on the card,
 *                             // e.g. Waitlisted on a My bookings card.
 *   'show_date'   => false,   // Prefix the time with the full date, for cards shown outside the calendar.
 *   'stacked'     => false,   // Put the buttons on their own line under the card's
 *                             // text instead of in the right-hand column, for
 *                             // narrow columns (the single speaker profile).
 *   'meta_lines'  => array(), // Extra meta lines below the venue/host, e.g. payment status.
 *   'booking'     => 'full',  // The booking button (law_booking_card_action()),
 *                             // appended after the actions below:
 *                             //   'full'   every state, including a link to a
 *                             //            booking the viewer already holds;
 *                             //   'action' only Register / Join waitlist, for
 *                             //            callers whose own actions already
 *                             //            link to the booking;
 *                             //   false    none.
 *   'actions'     => array(), // Button overrides: array of
 *                             // { label, url, arrow (bool), external (bool),
 *                             //   class (extra classes), sr_label (a fuller
 *                             //   accessible name), dialog (event id: fetch
 *                             //   this event's booking dialog on click) },
 *                             // or a form-shaped action for a POST behind a
 *                             // confirm modal: { label, form: { action, nonce,
 *                             // event_id, modal (parts/layout/modal.php args,
 *                             // with a page-unique id) } }.
 *                             // Defaults to an Event details link only.
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
$law_hosted       = law_calendar_host_names( $event );
$law_meta_lines   = isset( $args['meta_lines'] ) && is_array( $args['meta_lines'] ) ? array_filter( array_map( 'strval', $args['meta_lines'] ) ) : array();

$law_actions = isset( $args['actions'] ) && is_array( $args['actions'] ) ? $args['actions'] : array();
if ( ! $law_actions ) {
	$law_actions = array(
		array(
			'label' => __( 'Event details', 'law' ),
			'url'   => $law_event_url,
		),
	);
}

// The booking button, last, so the orange primary action ends the row. Appended
// rather than folded into the defaults above, because the callers that pass
// their own actions (My events, My bookings) want it too.
//
// Reverses EVENTS_BOOKINGS.md §7.1's original "booking lives on the single event
// page only": browsing the programme meant opening every event before you could
// see whether you could book it (Denis, 11 September 2026). The card carries the
// button but NO dialog -- see law_booking_card_action() for why, and for how the
// dialog is fetched instead.
$law_booking_scope = array_key_exists( 'booking', $args ) ? $args['booking'] : 'full';
if ( $law_booking_scope && function_exists( 'law_booking_card_action' ) ) {
	$law_booking_action = law_booking_card_action( $event, 'action' === $law_booking_scope ? 'action' : 'full' );
	if ( $law_booking_action ) {
		$law_actions[] = $law_booking_action;
	}
}

$law_time_parts = array();
if ( ! empty( $args['show_date'] ) && ! empty( $event['date'] ) ) {
	$law_time_parts[] = law_calendar_day_heading( $event['date'] );
}
if ( '' !== $law_time_label ) {
	$law_time_parts[] = $law_time_label;
}

// 'stacked': the buttons drop under the card's text instead of sitting in the
// right-hand column, for callers in a narrow column.
$law_card_classes = law_calendar_card_classes( $event, 'law-event-card' );
if ( ! empty( $args['stacked'] ) ) {
	$law_card_classes .= ' law-event-card--stacked';
}
?>
<article class="<?php echo esc_attr( $law_card_classes ); ?>">
	<div class="law-event-card__body">
		<?php if ( $law_show_status ) : ?>
			<?php law_calendar_status_badge( $event ); ?>
		<?php endif; ?>
		<?php if ( ! empty( $args['badge']['label'] ) ) : ?>
			<span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( (string) ( $args['badge']['slug'] ?? 'default' ) ); ?>"><?php echo esc_html( (string) $args['badge']['label'] ); ?></span>
		<?php endif; ?>
		<h4 class="law-event-card__title">
			<a href="<?php echo esc_url( $law_event_url ); ?>"><?php echo esc_html( $event['title'] ); ?></a>
			<?php law_calendar_edit_link( $event ); ?>
		</h4>
		<?php if ( $law_time_parts ) : ?>
			<p class="law-event-card__time"><?php echo esc_html( implode( ' · ', $law_time_parts ) ); ?></p>
		<?php endif; ?>
		<?php /* Both meta lines are labelled, the value bold: a bare place name next to a bare organisation name gives no clue which is which. */ ?>
		<?php if ( ! empty( $event['venue'] ) ) : ?>
			<p class="law-event-card__meta"><?php echo esc_html__( 'Venue:', 'law' ); ?> <strong><?php echo esc_html( $event['venue'] ); ?></strong></p>
		<?php endif; ?>
		<?php if ( '' !== $law_hosted ) : ?>
			<p class="law-event-card__meta"><?php echo esc_html__( 'Hosted by:', 'law' ); ?> <strong><?php echo esc_html( $law_hosted ); ?></strong></p>
		<?php endif; ?>
		<?php foreach ( $law_meta_lines as $law_meta_line ) : ?>
			<p class="law-event-card__meta"><?php echo esc_html( $law_meta_line ); ?></p>
		<?php endforeach; ?>
	</div>
	<div class="law-event-card__actions">
		<?php foreach ( $law_actions as $law_action ) : ?>
			<?php
			// A form-shaped action posts to admin-post.php behind a confirm
			// modal (rendered inside the form, so its submit carries the
			// fields). Without JS the modal stays hidden and the opener is a
			// plain submit, so the POST still works on its own.
			$law_action_form = isset( $law_action['form'] ) && is_array( $law_action['form'] ) ? $law_action['form'] : array();
			if ( $law_action_form && '' !== (string) ( $law_action['label'] ?? '' ) ) :
				$law_form_modal = isset( $law_action_form['modal'] ) && is_array( $law_action_form['modal'] ) ? $law_action_form['modal'] : array();
				?>
				<form class="law-event-card__action-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( (string) ( $law_action_form['action'] ?? '' ) ); ?>">
					<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) (int) ( $law_action_form['event_id'] ?? 0 ) ); ?>">
					<?php wp_nonce_field( (string) ( $law_action_form['nonce'] ?? '' ) ); ?>
					<?php law_events_honeypot_field(); ?>
					<button
						type="submit"
						class="button law-event-card__button"
						<?php echo ! empty( $law_form_modal['id'] ) ? ' data-law-modal-open="' . esc_attr( (string) $law_form_modal['id'] ) . '"' : ''; ?>
					><?php echo esc_html( $law_action['label'] ); ?></button>
					<?php
					if ( $law_form_modal ) {
						get_template_part( 'parts/layout/modal', null, $law_form_modal );
					}
					?>
				</form>
				<?php continue; ?>
			<?php endif; ?>
			<?php
			$law_action_url = (string) ( $law_action['url'] ?? '' );
			if ( '' === $law_action_url || '' === (string) ( $law_action['label'] ?? '' ) ) {
				continue;
			}
			$law_action_arrow = ! empty( $law_action['arrow'] );
			$law_action_class = trim( (string) ( $law_action['class'] ?? '' ) );
			// A fuller accessible name, for a label that only makes sense next
			// to its own card: fifty buttons all called "Register" is a useless
			// list to read through. The visible label stays short.
			$law_action_sr = trim( (string) ( $law_action['sr_label'] ?? '' ) );
			?>
			<a
				class="button law-event-card__button<?php echo $law_action_arrow ? ' law-event-card__button--register' : ''; ?><?php echo '' !== $law_action_class ? ' ' . esc_attr( $law_action_class ) : ''; ?>"
				href="<?php echo esc_url( $law_action_url ); ?>"
				<?php echo '' !== $law_action_sr ? ' aria-label="' . esc_attr( $law_action_sr ) . '"' : ''; ?>
				<?php echo ! empty( $law_action['dialog'] ) ? ' data-law-book="' . esc_attr( (string) (int) $law_action['dialog'] ) . '"' : ''; ?>
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
