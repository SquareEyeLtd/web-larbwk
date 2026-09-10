<?php
/**
 * The "add / change / update payment method" button, as its own tiny form.
 *
 * One partial for all three, because they are one action: the handler picks
 * the Stripe Checkout reason from the booking's state, so the three journeys
 * cannot drift. Posts like every other action in the module, and works as a
 * plain POST without JavaScript.
 *
 * Args: booking_id, label, class ('button …' for a real button, or
 * 'law-linkish' where the action is an aside beside a fact).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_fc_id = absint( $args['booking_id'] ?? 0 );
if ( ! $law_fc_id ) {
	return;
}
$law_fc_label = (string) ( $args['label'] ?? __( 'Update payment method', 'law' ) );
$law_fc_class = (string) ( $args['class'] ?? 'button second' );
?>
<form class="law-booking-form law-flagship-card-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="law_flagship_update_card">
	<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_fc_id ); ?>">
	<?php wp_nonce_field( 'law_flagship_update_card' ); ?>
	<?php law_events_honeypot_field(); ?>
	<button type="submit" class="<?php echo esc_attr( $law_fc_class ); ?>" data-law-modal-busy="<?php esc_attr_e( 'Opening…', 'law' ); ?>">
		<?php echo esc_html( $law_fc_label ); ?>
	</button>
</form>
