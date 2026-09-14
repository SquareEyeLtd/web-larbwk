<?php
/**
 * "Add to my bookings": the receptions a confirmed flagship place includes
 * (RECEPTIONS.md §7.4).
 *
 * Rendered INSIDE the form it confirms — the banner's on My bookings and the
 * Account hub, the control's on a reception page — which is the module's
 * standing pattern: the form posts whether or not the script is there, the
 * dialog carries the submit that actually fires it, and <noscript> holds the
 * dialog open for anybody without JavaScript.
 *
 * A reception the delegate ALREADY holds is checked and disabled with a tag
 * saying why, never hidden: the house rule is that read-only surfaces disable
 * rather than hide, and a list that silently omitted Monday would read as
 * though Monday were not included at all.
 *
 * Args: user_id, and optionally preselect (a reception id to tick).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_ri_user = (int) ( $args['user_id'] ?? get_current_user_id() );
$law_ri_pre  = (int) ( $args['preselect'] ?? 0 );
$law_ri_all  = law_reception_included_ids();
if ( ! $law_ri_user || ! $law_ri_all ) {
	return;
}

$law_ri_dialog = 'law-reception-include';

// The component's own CSS and JS, so a caller anywhere in the theme gets them.
law_modal_enqueue();
?>
<div class="law-modal" id="<?php echo esc_attr( $law_ri_dialog ); ?>" hidden>
	<div class="law-modal__overlay" data-law-modal-close></div>
	<div class="law-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $law_ri_dialog ); ?>-title" tabindex="-1">
		<button type="button" class="law-modal__close" data-law-modal-close aria-label="<?php esc_attr_e( 'Close', 'law' ); ?>">&times;</button>
		<h2 class="law-modal__title" id="<?php echo esc_attr( $law_ri_dialog ); ?>-title"><?php esc_html_e( 'Add to my bookings', 'law' ); ?></h2>
		<p class="law-modal__copy"><?php esc_html_e( 'These receptions are included with your flagship conference place. Tick the ones you would like to attend; there is nothing to pay.', 'law' ); ?></p>

		<?php foreach ( $law_ri_all as $law_ri_id ) : ?>
			<?php
			$law_ri_held = law_booking_user_booking_for_event( $law_ri_user, $law_ri_id, law_booking_holding_statuses() );
			$law_ri_when = law_reception_when_label( $law_ri_id );
			$law_ri_tag  = '';
			if ( $law_ri_held instanceof WP_Post ) {
				$law_ri_tag = 'law-pending-payment' === $law_ri_held->post_status
					? __( 'Payment in progress', 'law' )
					: __( 'Already booked', 'law' );
			}
			?>
			<?php
			// The name on one line and the day under it, not both on one:
			// "Opening drinks Monday 30 November, 18:30" reads as one long
			// string and the date is the part somebody is actually choosing
			// between. Its own class rather than .law-form-hint, which is
			// pitched for light text on the navy form and washes out to almost
			// nothing on this white dialog (Denis, 14 September 2026).
			?>
			<p class="law-reception-include__row">
				<label>
					<input type="checkbox" name="law_receptions[]" value="<?php echo esc_attr( (string) $law_ri_id ); ?>"
						<?php checked( $law_ri_held instanceof WP_Post || $law_ri_id === $law_ri_pre ); ?>
						<?php disabled( $law_ri_held instanceof WP_Post ); ?>>
					<span class="law-reception-include__text">
						<span class="law-reception-include__name"><?php echo esc_html( get_the_title( $law_ri_id ) ); ?></span>
						<?php if ( '' !== $law_ri_when ) : ?>
							<span class="law-reception-include__when"><?php echo esc_html( $law_ri_when ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $law_ri_tag ) : ?>
							<span class="law-cal-card__badge law-cal-card__badge--confirmed"><?php echo esc_html( $law_ri_tag ); ?></span>
						<?php endif; ?>
					</span>
				</label>
			</p>
		<?php endforeach; ?>

		<p class="law-modal__actions">
			<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Cancel', 'law' ); ?></button>
			<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Adding…', 'law' ); ?>">
				<?php esc_html_e( 'Add to my bookings', 'law' ); ?>
			</button>
		</p>
	</div>
</div>
<noscript>
	<?php
	// Without JavaScript the dialog above never opens, so the same choice is
	// offered in the page, exactly as the flagship's add-attendee form does.
	?>
	<p class="law-booking-note"><?php esc_html_e( 'Tick the receptions you would like to add and press Add to my bookings.', 'law' ); ?></p>
	<?php foreach ( $law_ri_all as $law_ri_id ) : ?>
		<?php $law_ri_held = law_booking_user_booking_for_event( $law_ri_user, $law_ri_id, law_booking_holding_statuses() ); ?>
		<p class="law-form-field">
			<label>
				<input type="checkbox" name="law_receptions[]" value="<?php echo esc_attr( (string) $law_ri_id ); ?>" <?php disabled( $law_ri_held instanceof WP_Post ); ?>>
				<?php echo esc_html( trim( get_the_title( $law_ri_id ) . ' ' . law_reception_when_label( $law_ri_id ) ) ); ?>
			</label>
		</p>
	<?php endforeach; ?>
	<p><button type="submit" class="button orange"><?php esc_html_e( 'Add to my bookings', 'law' ); ?></button></p>
</noscript>
