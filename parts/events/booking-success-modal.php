<?php
/**
 * The post-booking confirmation dialog (EVENTS_BOOKINGS.md §7.2), opened by
 * assets/js/booking-form.js after a successful fetch submission.
 *
 * Rendered on wp_footer, not in the flow, via law_booking_footer_modal(): it is
 * position:fixed with z-index 10050 (law-modal.css), and the booking control now
 * renders inside the hero's details box, whose .grid-container is a stacking
 * context (position:relative, z-index 4, app.css). Inside that context the
 * dialog's z-index would be clamped to level 4 and it would paint underneath the
 * fixed header (.nav z-index 99, .affix z-index 9999). At body level it cannot be.
 *
 * Its only exits are the two links: the script marks it law-modal--busy, so
 * Escape and overlay clicks are inert. Closing in place would leave a stale
 * "Book now" behind it.
 *
 * Args: event (the calendar-mapped array).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_bks_event = (array) ( $args['event'] ?? array() );
$law_bks_id    = (int) ( $law_bks_event['id'] ?? 0 );
if ( ! $law_bks_id || ! is_user_logged_in() ) {
	return;
}
?>
<div class="law-modal" id="law-booking-success" hidden>
	<div class="law-modal__overlay"></div>
	<div class="law-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="law-booking-success-title" tabindex="-1">
		<h2 class="law-modal__title" id="law-booking-success-title"><?php esc_html_e( 'Booking confirmed', 'law' ); ?></h2>
		<p class="law-modal__copy"><?php echo esc_html( sprintf( __( "You're booked onto %s. A confirmation email with a calendar invitation is on its way to you.", 'law' ), (string) ( $law_bks_event['title'] ?? '' ) ) ); ?></p>
		<p class="law-modal__copy"><?php esc_html_e( 'Any colleagues you added are emailed an invitation to set up their account and add dietary or accessibility requirements to their profile.', 'law' ); ?></p>
		<p class="law-modal__actions">
			<a class="button second" href="<?php echo esc_url( get_permalink( $law_bks_id ) ); ?>"><?php esc_html_e( 'Close', 'law' ); ?></a>
			<a class="button orange" href="<?php echo esc_url( home_url( '/account/events/' ) ); ?>"><?php esc_html_e( 'View my bookings', 'law' ); ?></a>
		</p>
	</div>
</div>
