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
 * "Register" behind it.
 *
 * Args: event (the calendar-mapped array), mode ('book' | 'waitlist').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_bks_event = (array) ( $args['event'] ?? array() );
$law_bks_wait  = 'waitlist' === ( $args['mode'] ?? 'book' );
$law_bks_id    = (int) ( $law_bks_event['id'] ?? 0 );
if ( ! $law_bks_id || ! is_user_logged_in() ) {
	return;
}
$law_bks_dialog = $law_bks_wait ? 'law-waitlist-success' : 'law-booking-success';
$law_bks_title  = (string) ( $law_bks_event['title'] ?? '' );
?>
<div class="law-modal" id="<?php echo esc_attr( $law_bks_dialog ); ?>" hidden>
	<div class="law-modal__overlay"></div>
	<div class="law-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $law_bks_dialog ); ?>-title" tabindex="-1">
		<h2 class="law-modal__title" id="<?php echo esc_attr( $law_bks_dialog ); ?>-title"><?php echo esc_html( $law_bks_wait ? __( "You're on the waitlist", 'law' ) : __( 'Booking confirmed', 'law' ) ); ?></h2>
		<?php if ( $law_bks_wait ) : ?>
			<p class="law-modal__copy"><?php echo esc_html( sprintf( __( "You're on the waitlist for %s, and we've emailed you to confirm. As soon as a place opens up your booking is confirmed automatically and you're emailed a calendar invitation.", 'law' ), $law_bks_title ) ); ?></p>
			<p class="law-modal__copy"><?php esc_html_e( 'Any colleagues you added are on the waitlist in their own right, and are emailed individually when their place comes up. Those without an account are invited to set one up.', 'law' ); ?></p>
			<p class="law-modal__copy"><?php esc_html_e( 'You can leave the waitlist at any time from My bookings.', 'law' ); ?></p>
		<?php else : ?>
			<p class="law-modal__copy"><?php echo esc_html( sprintf( __( "You're booked onto %s. A confirmation email with your booking number and a calendar invitation is on its way to you.", 'law' ), $law_bks_title ) ); ?></p>
			<p class="law-modal__copy"><?php esc_html_e( 'Each colleague you added has a booking of their own, with their own booking number, and is emailed the event details. Those without an account are invited to set one up and add any dietary or accessibility requirements to their profile.', 'law' ); ?></p>
		<?php endif; ?>
		<p class="law-modal__actions">
			<a class="button second" href="<?php echo esc_url( get_permalink( $law_bks_id ) ); ?>"><?php esc_html_e( 'Close', 'law' ); ?></a>
			<a class="button orange" href="<?php echo esc_url( home_url( '/account/events/' ) ); ?>"><?php esc_html_e( 'View my bookings', 'law' ); ?></a>
		</p>
	</div>
</div>
