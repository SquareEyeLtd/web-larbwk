<?php
/**
 * The placeholder dialog an event card's Register button opens immediately,
 * while that event's real dialog is being fetched (booking-form.js §2b).
 *
 * Cards carry no dialog of their own, so there is a network round trip between
 * the press and the form. Without this the button looked dead for it: pressing
 * Register appeared to do nothing at all (Denis, 11 September 2026). The dialog
 * opens at once and the content lands in it, which is also what makes the press
 * unambiguous -- there is no state in which pressing twice is the way to get it.
 *
 * Rendered once per page on wp_footer for signed-in viewers of any card surface,
 * not once per card: it holds no event-specific content beyond its heading.
 *
 * The three headings are rendered here rather than written in JavaScript, which
 * would put translated copy in a second place. The script picks between them
 * from the button's own URL, and each matches the heading of the dialog that is
 * about to replace it, so nothing jumps when it lands.
 *
 * The status line stays generic for the same reason it is one line: it has to
 * be true of a booking, a waitlist place and a flagship application alike.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="law-modal" id="law-booking-loading" hidden>
	<div class="law-modal__overlay" data-law-modal-close></div>
	<div class="law-modal__dialog law-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="law-booking-loading-title" tabindex="-1">
		<button type="button" class="law-modal__close" data-law-modal-close aria-label="<?php esc_attr_e( 'Close', 'law' ); ?>">&times;</button>
		<h2
			class="law-modal__title"
			id="law-booking-loading-title"
			data-law-loading-book="<?php esc_attr_e( 'Book your place', 'law' ); ?>"
			data-law-loading-waitlist="<?php esc_attr_e( 'Join the waitlist', 'law' ); ?>"
			data-law-loading-apply="<?php esc_attr_e( 'Apply to attend', 'law' ); ?>"
		><?php esc_html_e( 'Book your place', 'law' ); ?></h2>
		<?php // role=status, so a screen reader is told the wait has started without the focus moving. ?>
		<p class="law-modal__copy" role="status"><?php esc_html_e( 'Loading the form…', 'law' ); ?></p>
		<div class="law-booking-skeleton" aria-hidden="true">
			<div class="law-cal-skeleton__line law-cal-skeleton__line--title"></div>
			<div class="law-cal-skeleton__line law-cal-skeleton__line--meta"></div>
			<div class="law-cal-skeleton__bar law-cal-skeleton__bar--table-row"></div>
			<div class="law-cal-skeleton__bar law-cal-skeleton__bar--table-row"></div>
			<div class="law-cal-skeleton__line law-cal-skeleton__line--meta"></div>
		</div>
	</div>
</div>
