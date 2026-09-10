<?php
/**
 * The dialog shown after an application is submitted over fetch.
 *
 * Rendered at body level by law_flagship_footer_modal(): it is
 * position:fixed, and the control that triggers it sits inside the hero's
 * .grid-container, a stacking context that would clamp it under the header.
 *
 * In practice the delegate rarely reads it, because a successful application
 * redirects them straight to Stripe. It exists for the case where the
 * redirect is slow or blocked, so the page never just sits there looking as
 * though nothing happened.
 *
 * Args: event.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_fs_event = (array) ( $args['event'] ?? array() );
$law_fs_id    = (int) ( $law_fs_event['id'] ?? 0 );
if ( ! $law_fs_id ) {
	return;
}
?>
<div class="law-modal" id="law-flagship-success" hidden>
	<div class="law-modal__overlay" data-law-modal-close></div>
	<div class="law-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="law-flagship-success-title" tabindex="-1">
		<h2 class="law-modal__title" id="law-flagship-success-title"><?php esc_html_e( 'Taking you to our payment page', 'law' ); ?></h2>
		<p class="law-modal__copy">
			<?php esc_html_e( 'Stripe will ask how you would like to pay. Your payment details are saved but not charged: we only take payment if the committee approves your application, and we email you either way.', 'law' ); ?>
		</p>
		<p class="law-modal__copy">
			<?php esc_html_e( 'If nothing happens in a moment, your application is safe. Open My bookings and add your payment details from there.', 'law' ); ?>
		</p>
		<p class="law-modal__actions">
			<a class="button second" href="<?php echo esc_url( law_account_url( 'my_bookings' ) ); ?>"><?php esc_html_e( 'My bookings', 'law' ); ?></a>
			<button type="button" class="button orange" data-law-modal-close><?php esc_html_e( 'Close', 'law' ); ?></button>
		</p>
	</div>
</div>
