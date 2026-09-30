<?php
/**
 * The committee's registration warning for one event: a yellow box at the top
 * of the Register dialog's body, above the summary of what is being booked.
 * Prints nothing when the event has none (law_event_registration_warning()).
 *
 * Rendered in every state of the dialog, signed out and profile-incomplete
 * included, because a warning such as "Black tie" or "Photo ID is checked at
 * the door" matters most before somebody goes off to create an account.
 *
 * Styled entirely in law-modal.css (.law-registration-warning) rather than on
 * .law-form-notice: a signed-out visitor's page does not load event-form.css,
 * where the notice's padding and colours live.
 *
 * Receptions only today (reception-checkout-modal.php); a hosted event's
 * booking-modal.php can call the same partial the day it gets the field.
 *
 * Args: event_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_rw_text = law_event_registration_warning( (int) ( $args['event_id'] ?? 0 ) );
if ( '' === $law_rw_text ) {
	return;
}

// The inline (no-JS) context sits on the event page, which does not always
// have the modal stylesheet; a mid-page enqueue prints it in the footer.
law_modal_enqueue();
?>
<div class="law-registration-warning" role="note">
	<?php echo law_rich_text_render( $law_rw_text ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses'd to the rich-text allowlist. ?>
</div>
