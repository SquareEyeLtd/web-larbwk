<?php
/**
 * The money on one priced booking, as a facts table (RECEPTIONS.md §7.5).
 *
 * Extracted from parts/events/flagship-manage-application.php when the
 * receptions needed the same block: what was paid, what a discount took off,
 * where the VAT invoice is, and — when there is one — what went wrong and the
 * one button that fixes it.
 *
 * Deliberately NOT the whole flagship panel. That one is written around an
 * application the committee decides on; this is written around a place that
 * was bought, so it says "paid" rather than "approved" and offers no
 * withdrawal.
 *
 * Args: booking_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_pf_id      = absint( $args['booking_id'] ?? 0 );
$law_pf_booking = $law_pf_id ? get_post( $law_pf_id ) : null;
if ( ! $law_pf_booking || LAW_BOOKING_CPT !== $law_pf_booking->post_type ) {
	return;
}

$law_pf_status   = $law_pf_booking->post_status;
$law_pf_pay      = (string) law_event_meta( $law_pf_id, '_law_payment_status' );
$law_pf_price    = law_booking_price( $law_pf_id );
$law_pf_invoice  = (string) law_event_meta( $law_pf_id, '_law_stripe_invoice_url' );
$law_pf_pdf      = (string) law_event_meta( $law_pf_id, '_law_stripe_invoice_pdf' );
$law_pf_code     = (string) law_event_meta( $law_pf_id, '_law_discount_code' );
$law_pf_off      = (int) law_event_meta( $law_pf_id, '_law_discount_pence' );
$law_pf_included = (int) law_event_meta( $law_pf_id, '_law_included_with' );
$law_pf_method   = law_booking_payment_method_label( $law_pf_id );
$law_pf_error    = (string) law_event_meta( $law_pf_id, '_law_payment_error' );
$law_pf_deadline = law_booking_payment_deadline_ts( $law_pf_id );
?>

<?php if ( 'law-payment-failed' === $law_pf_status ) : ?>
	<?php
	// The one state that should look like a problem. Everything the delegate
	// needs is in one place: what happened, which method, by when, and the one
	// button that fixes it.
	?>
	<div class="law-booking-panel law-booking-panel--full law-booking-payment-alert">
		<div class="law-booking-panel__main">
			<p class="law-booking-state">
				<?php echo esc_html( 'action_required' === $law_pf_pay
					? __( 'Your bank needs you to confirm this payment.', 'law' )
					: __( 'We could not take your payment.', 'law' ) ); ?>
			</p>
			<?php if ( '' !== $law_pf_error ) : ?>
				<p class="law-booking-substate"><?php echo esc_html( $law_pf_error ); ?></p>
			<?php endif; ?>
			<p class="law-booking-substate">
				<?php
				echo esc_html(
					$law_pf_deadline
						? sprintf(
							/* translators: %s: a date. */
							__( 'The place has gone to the next person on the waitlist for now. Update your payment details by %s and we will book you in if a place is free, or put you at the front of the waitlist if it is not.', 'law' ),
							wp_date( 'j F Y', $law_pf_deadline )
						)
						: __( 'Update your payment details and we will try again.', 'law' )
				);
				?>
			</p>
		</div>
		<div class="law-booking-panel__action">
			<?php if ( 'action_required' === $law_pf_pay && '' !== $law_pf_invoice ) : ?>
				<?php
				// A new payment method would be the wrong offer here: the one
				// on file is fine, the bank simply wants the delegate present.
				?>
				<a class="button orange" href="<?php echo esc_url( $law_pf_invoice ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Confirm the payment', 'law' ); ?>
				</a>
			<?php else : ?>
				<?php
				get_template_part(
					'parts/events/flagship-card-form',
					null,
					array( 'booking_id' => $law_pf_id, 'label' => __( 'Update payment details', 'law' ), 'class' => 'button orange' )
				);
				?>
			<?php endif; ?>
		</div>
	</div>
<?php endif; ?>

<table class="law-dashboard__table law-booking-table law-booking-payment-facts">
	<tbody>
		<tr>
			<th scope="row"><?php esc_html_e( 'Status', 'law' ); ?></th>
			<td>
				<span class="law-cal-card__badge <?php echo esc_attr( law_booking_status_badge_class( $law_pf_status ) ); ?>">
					<?php echo esc_html( law_booking_status_label( $law_pf_booking ) ); ?>
				</span>
				<?php if ( 'law-pending-payment' === $law_pf_status && 'processing' === $law_pf_pay ) : ?>
					<span class="law-booking-table__sub"><?php esc_html_e( 'Your place is held. We will email you as soon as the payment clears.', 'law' ); ?></span>
				<?php elseif ( 'law-pending-payment' === $law_pf_status ) : ?>
					<span class="law-booking-table__sub"><?php esc_html_e( 'Your place is held while you pay. If you do not finish, it goes back on sale.', 'law' ); ?></span>
				<?php elseif ( 'law-waitlisted' === $law_pf_status && 'pending_setup' === $law_pf_pay ) : ?>
					<span class="law-booking-table__sub"><?php esc_html_e( 'We cannot offer you a place until your payment details are saved.', 'law' ); ?></span>
				<?php elseif ( 'law-waitlisted' === $law_pf_status ) : ?>
					<span class="law-booking-table__sub">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: the total price. */
								__( "We'll charge %s and email you as soon as a place opens up.", 'law' ),
								law_events_format_pence( $law_pf_price['gross'] )
							)
						);
						?>
					</span>
				<?php endif; ?>
			</td>
		</tr>

		<tr>
			<th scope="row"><?php esc_html_e( 'Price', 'law' ); ?></th>
			<td>
				<?php if ( $law_pf_included ) : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: the flagship booking number. */
							__( 'Included with your flagship place (Booking #%d)', 'law' ),
							(int) law_event_meta( $law_pf_included, '_law_booking_number' )
						)
					);
					?>
				<?php elseif ( 'complimentary' === $law_pf_pay ) : ?>
					<?php esc_html_e( 'Complimentary, with our compliments. There is nothing to pay.', 'law' ); ?>
				<?php elseif ( $law_pf_price['free'] ) : ?>
					<?php esc_html_e( 'No charge', 'law' ); ?>
				<?php else : ?>
					<?php echo esc_html( law_events_price_label( $law_pf_price['net'] ) ); ?>
					<span class="law-booking-table__sub">
						<?php
						if ( 'paid' === $law_pf_pay ) {
							esc_html_e( 'Paid.', 'law' );
						} elseif ( 'processing' === $law_pf_pay ) {
							esc_html_e( 'Payment in progress.', 'law' );
						} elseif ( 'refunded' === $law_pf_pay ) {
							esc_html_e( 'Refunded.', 'law' );
						} else {
							esc_html_e( 'Not paid yet.', 'law' );
						}
						?>
					</span>
				<?php endif; ?>
			</td>
		</tr>

		<?php if ( '' !== $law_pf_code && $law_pf_off > 0 ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Discount code', 'law' ); ?></th>
				<td>
					<code><?php echo esc_html( $law_pf_code ); ?></code>
					<span class="law-booking-table__sub">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s: the amount taken off. */
								__( '%s off the list price.', 'law' ),
								law_events_format_pence( $law_pf_off )
							)
						);
						?>
					</span>
				</td>
			</tr>
		<?php endif; ?>

		<?php if ( '' !== $law_pf_method ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Payment method', 'law' ); ?></th>
				<td>
					<?php echo esc_html( $law_pf_method ); ?>
					<?php if ( 'law-waitlisted' === $law_pf_status ) : ?>
						<span class="law-booking-manage__action">
							<?php
							get_template_part(
								'parts/events/flagship-card-form',
								null,
								array( 'booking_id' => $law_pf_id, 'label' => __( 'Change payment method', 'law' ), 'class' => 'law-linkish' )
							);
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
		<?php elseif ( 'law-waitlisted' === $law_pf_status ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Payment method', 'law' ); ?></th>
				<td>
					<?php esc_html_e( 'No payment method saved yet.', 'law' ); ?>
					<span class="law-booking-manage__action">
						<?php
						get_template_part(
							'parts/events/flagship-card-form',
							null,
							array( 'booking_id' => $law_pf_id, 'label' => __( 'Add payment details', 'law' ), 'class' => 'button orange' )
						);
						?>
					</span>
				</td>
			</tr>
		<?php endif; ?>

		<?php if ( '' !== $law_pf_invoice && in_array( $law_pf_pay, array( 'paid', 'refunded' ), true ) ) : ?>
			<tr>
				<th scope="row"><?php esc_html_e( 'VAT invoice', 'law' ); ?></th>
				<td>
					<a href="<?php echo esc_url( $law_pf_invoice ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View your invoice', 'law' ); ?></a>
					<?php if ( '' !== $law_pf_pdf ) : ?>
						· <a href="<?php echo esc_url( $law_pf_pdf ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Download the PDF', 'law' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endif; ?>
	</tbody>
</table>
