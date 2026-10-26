<?php
/**
 * One delegate's view of their own flagship registration
 * (?law_booking=<id> on /account/bookings/, FLAGSHIP_PAYMENTS.md §4.4, §6).
 *
 * Reached from parts/events/booking-manage.php, which hands a flagship
 * booking straight here rather than rendering it as a hosted one: this is a
 * single person with a saved payment method and a payment that can fail,
 * not a party.
 *
 * Access is re-checked here rather than trusted from the caller, as
 * parts/events/thread.php sets the precedent. Since 26 October 2026 the
 * person who registered somebody else sees it too (BOOK_ON_BEHALF.md §3.6):
 * they manage the payment, and they see the details THEY typed, never the
 * attendee's live profile, which is the attendee's business.
 *
 * Args: booking_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_fm_id      = absint( $args['booking_id'] ?? 0 );
$law_fm_booking = $law_fm_id ? get_post( $law_fm_id ) : null;

if ( ! $law_fm_booking
	|| LAW_BOOKING_CPT !== $law_fm_booking->post_type
	|| '' === law_booking_user_role( $law_fm_booking ) ) {
	echo '<p class="law-cal__empty">' . esc_html__( 'Sorry, this registration is not yours to view.', 'law' ) . '</p>';
	return;
}

// Who is looking: the attendee, or the person who registered them. On a
// self-booked place they are one person and every branch below reads as it
// always did.
$law_fm_booker_view = 'booker' === law_booking_user_role( $law_fm_booking );
$law_fm_pays        = law_booking_user_manages_payment( $law_fm_booking );

$law_fm_event_id = (int) $law_fm_booking->post_parent;
$law_fm_event    = get_post( $law_fm_event_id );
$law_fm_number   = (int) law_event_meta( $law_fm_id, '_law_booking_number' );
$law_fm_status   = (string) $law_fm_booking->post_status;
$law_fm_pay      = (string) law_event_meta( $law_fm_id, '_law_payment_status' );
$law_fm_price    = law_booking_price( $law_fm_id );
$law_fm_card     = law_booking_payment_method_label( $law_fm_id );
$law_fm_invoice  = (string) law_event_meta( $law_fm_id, '_law_stripe_invoice_url' );
// A place this delegate was SUBSTITUTED into was paid for by somebody else,
// and the money deliberately never moved with it. The invoice, its PDF and the
// payment method all describe that other person — the hosted Stripe page
// carries their name, billing address and card last four — so they are blanked
// here rather than guarded at each of the six places below, and one line says
// why. The person who paid gets the link in their transfer email instead.
$law_fm_mask     = law_booking_payment_facts_mask( $law_fm_id );
// Somebody else paid: a transferred place, or one booked for this viewer.
$law_fm_moved    = ! $law_fm_mask['invoice'];
if ( ! $law_fm_mask['card'] ) {
	$law_fm_card = '';
}
if ( ! $law_fm_mask['invoice'] ) {
	$law_fm_invoice = '';
}
// Why the card was refused is about the card, and so the payer's business.
$law_fm_error    = $law_fm_mask['card'] ? (string) law_event_meta( $law_fm_id, '_law_payment_error' ) : '';
$law_fm_deadline = law_flagship_payment_deadline_ts( $law_fm_id );
// Read LIVE from the profile, not from the booking. The registration form
// collects nothing about a delegate registering themselves (Denis, 10
// September 2026), so the profile is the single
// copy of these details — and reading it live means a delegate who corrects
// their job title sees the correction here rather than a snapshot of what it
// said the day they registered.
//
// Not for the person who registered them, though: they see the snapshot
// they typed (law_booking_profile_values() with the account left out).
$law_fm_profile  = $law_fm_booker_view
	? law_booking_snapshot_values( $law_fm_booking )
	: law_profile_values( (int) $law_fm_booking->post_author );
$law_fm_answers  = array_filter(
	array(
		'job_title'           => (string) ( $law_fm_profile['job_title'] ?? '' ),
		'organisation'        => (string) ( $law_fm_profile['organisation'] ?? '' ),
		'country'             => (string) ( $law_fm_profile['country'] ?? '' ),
		'dietary_other'       => law_booking_profile_requirements( $law_fm_profile, 'dietary' ),
		'accessibility_other' => law_booking_profile_requirements( $law_fm_profile, 'accessibility' ),
	),
	static fn( $value ) => '' !== trim( (string) $value )
);
$law_fm_comp     = (bool) law_event_meta( $law_fm_id, '_law_is_complimentary' );
// A discount code that covered the whole price. Free like a complimentary
// place, but for a different reason and by a different hand, so the panel says
// so rather than calling it a gift.
// Blanked on a transferred place for the same reason as the invoice: the code
// is the payer's negotiated commercial term, and it is reusable by whoever
// reads it. The substitution email already withholds it.
$law_fm_code     = $law_fm_mask['code'] ? (string) law_event_meta( $law_fm_id, '_law_discount_code' ) : '';
$law_fm_off      = $law_fm_mask['code'] ? (int) law_event_meta( $law_fm_id, '_law_discount_pence' ) : 0;
$law_fm_free     = ! $law_fm_comp && '' !== $law_fm_code && $law_fm_price['free'];

// What the delegate can still do. A confirmed, paid place is not withdrawn
// from here: that is a refund, and a refund is a conversation.
$law_fm_can_withdraw = in_array( $law_fm_status, array( 'law-applied', 'law-payment-failed' ), true )
	// Not while a charge is in flight: law_flagship_withdraw() refuses it,
	// and offering a control that will be refused is worse than not offering
	// one.
	&& 'processing' !== $law_fm_pay;
// No payment method is offered where there is nothing to charge: a code that
// covers the whole price means we never asked for one and never will, so
// "Add payment details" would be an invitation to fix something that is not
// broken.
$law_fm_can_method   = $law_fm_can_withdraw && ! $law_fm_comp && ! $law_fm_free && 'processing' !== $law_fm_pay;
?>

<?php
// No back-link here: templates/account-bookings.php renders one for every
// ?law_booking view before it delegates to this part, and rendering a second
// stacked them one on top of the other.
?>
<h2 class="law-dashboard__title">
	<?php echo esc_html( $law_fm_event ? $law_fm_event->post_title : __( 'Flagship conference', 'law' ) ); ?>
</h2>
<p class="law-booking-substate">
	<?php
	// Registration until it is approved and paid for, a ticket after: the
	// same record, and this page renders both (the client, via Denis,
	// 15 September 2026). Nothing undecided is called a ticket, because the
	// committee can still decline it.
	echo esc_html(
		'publish' === $law_fm_status
			/* translators: %d: the booking number. */
			? sprintf( __( 'Ticket #%d', 'law' ), $law_fm_number )
			/* translators: %d: the booking number. */
			: sprintf( __( 'Registration #%d', 'law' ), $law_fm_number )
	);
	?>
</p>
<?php
// Whose place this is, when that is not obvious from who is reading.
$law_fm_for_line = law_booking_for_line( $law_fm_booking );
?>
<?php if ( '' !== $law_fm_for_line ) : ?>
	<p class="law-booking-substate"><?php echo esc_html( $law_fm_for_line ); ?></p>
<?php endif; ?>

<?php law_flagship_notice_render( $law_fm_id ); ?>

<?php if ( 'law-payment-failed' === $law_fm_status ) : ?>
	<?php
	// The exception panel (§4.4). Everything the delegate needs to fix it is
	// in one place: what happened, which payment method, by when, and one
	// button.
	$law_fm_sca = 'action_required' === $law_fm_pay;
	?>
	<div class="law-form-notice is-error law-flagship-exception" role="alert">
		<p><strong>
			<?php
			echo esc_html(
				$law_fm_sca
					? __( 'The bank needs this payment confirmed.', 'law' )
					: __( 'We could not take the payment.', 'law' )
			);
			?>
		</strong></p>

		<p>
			<?php
			echo esc_html(
				$law_fm_sca
					? __( 'The registration has been approved and the place is held. There is nothing wrong with the payment method saved: the bank is asking for the payment to be confirmed before it goes through.', 'law' )
					: __( 'The registration has been approved and the place is held while this is sorted out.', 'law' )
			);
			?>
		</p>

		<?php if ( ! $law_fm_sca && '' !== $law_fm_error ) : ?>
			<p><?php echo esc_html( sprintf( __( 'The reason given was: %s', 'law' ), $law_fm_error ) ); ?></p>
		<?php endif; ?>

		<?php if ( '' !== $law_fm_card ) : ?>
			<p><?php echo esc_html( sprintf( __( 'Payment method on file: %s.', 'law' ), $law_fm_card ) ); ?></p>
		<?php endif; ?>

		<?php if ( $law_fm_deadline ) : ?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: a date. */
						__( 'Please sort this out by %s. After that we may not be able to hold the place.', 'law' ),
						wp_date( 'j F Y', $law_fm_deadline )
					)
				);
				?>
			</p>
		<?php endif; ?>

		<p class="law-form-buttons">
			<?php if ( $law_fm_sca && '' !== $law_fm_invoice ) : ?>
				<?php
				// A new payment method would be the wrong offer here: the one
				// on file is fine.
				?>
				<a class="button orange" href="<?php echo esc_url( $law_fm_invoice ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'Confirm the payment', 'law' ); ?>
				</a>
			<?php else : ?>
				<?php
				get_template_part(
					'parts/events/flagship-card-form',
					null,
					array( 'booking_id' => $law_fm_id, 'label' => __( 'Update payment method', 'law' ), 'class' => 'button orange' )
				);
				?>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>

<?php if ( 'processing' === $law_fm_pay ) : ?>
	<?php
	// Approved, charged, and the money simply has not landed yet. Only
	// reachable when the delegate paid by something other than a card, since
	// cards settle inside the committee's click. Deliberately NOT the error
	// panel: there is nothing for them to do, and an alarm here would send
	// them to their bank over a payment that is working.
	?>
	<div class="law-form-notice law-flagship-processing" role="status">
		<p><strong><?php esc_html_e( 'The payment is on its way.', 'law' ); ?></strong></p>
		<p><?php esc_html_e( 'The committee has approved the registration and the place is held. Some payment methods take a little longer to clear than a card does. We will email as soon as the payment lands, and there is nothing to do in the meantime.', 'law' ); ?></p>
	</div>
<?php endif; ?>

<div class="law-dashboard__table-wrap">
	<table class="law-dashboard__table law-booking-table law-flagship-application">
		<tbody>
			<tr>
				<th scope="row"><?php esc_html_e( 'Status', 'law' ); ?></th>
				<td>
					<span class="law-cal-card__badge <?php echo esc_attr( law_booking_status_badge_class( $law_fm_status ) ); ?>">
						<?php echo esc_html( law_booking_status_label( $law_fm_booking ) ); ?>
					</span>
					<?php if ( 'law-applied' === $law_fm_status && 'pending_setup' === $law_fm_pay ) : ?>
						<span class="law-booking-table__sub"><?php esc_html_e( 'We cannot put the registration to the committee until the payment details are saved.', 'law' ); ?></span>
					<?php elseif ( 'processing' === $law_fm_pay ) : ?>
						<span class="law-booking-table__sub"><?php esc_html_e( 'Approved. The ticket is confirmed as soon as the payment clears.', 'law' ); ?></span>
					<?php elseif ( 'law-applied' === $law_fm_status ) : ?>
						<span class="law-booking-table__sub"><?php esc_html_e( 'If you register by 16 October, you’ll receive confirmation of your ticket status by 30 October. Registrations after this, will be notified within 14 days.', 'law' ); ?></span>
					<?php endif; ?>
				</td>
			</tr>

			<tr>
				<th scope="row"><?php esc_html_e( 'Price', 'law' ); ?></th>
				<td>
					<?php if ( $law_fm_comp ) : ?>
						<?php esc_html_e( 'No charge', 'law' ); ?>
					<?php elseif ( $law_fm_free ) : ?>
						<?php esc_html_e( 'Nothing to pay', 'law' ); ?>
						<span class="law-booking-table__sub">
							<?php
							echo esc_html(
								'publish' === $law_fm_status
									? __( 'The discount code covered the whole price, so nothing was charged.', 'law' )
									: __( 'The discount code covers the whole price, so there is nothing to pay and we have not asked for any payment details.', 'law' )
							);
							?>
						</span>
					<?php else : ?>
						<?php echo esc_html( law_events_price_label( $law_fm_price['net'] ) ); ?>
						<span class="law-booking-table__sub">
							<?php
							if ( 'paid' === $law_fm_pay ) {
								echo esc_html__( 'Paid.', 'law' );
							} elseif ( 'processing' === $law_fm_pay ) {
								echo esc_html__( 'Payment in progress.', 'law' );
							} else {
								echo esc_html__( 'Not charged. We only take payment if the registration is approved.', 'law' );
							}
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>

			<?php if ( '' !== $law_fm_code ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Discount code', 'law' ); ?></th>
					<td>
						<code><?php echo esc_html( $law_fm_code ); ?></code>
						<?php if ( $law_fm_off > 0 ) : ?>
							<span class="law-booking-table__sub">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: the amount taken off. */
										__( '%s off the list price.', 'law' ),
										law_events_format_pence( $law_fm_off )
									)
								);
								?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $law_fm_moved ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Payment', 'law' ); ?></th>
					<td>
						<?php echo esc_html( law_booking_payer_note( $law_fm_booking ) ); ?>
						<?php if ( $law_fm_can_method && ! $law_fm_pays ) : ?>
							<?php
							// The control, shown disabled with who can use it
							// (BOOK_ON_BEHALF.md A4): the card form part does the
							// disabling for anybody who is not paying.
							?>
							<span class="law-booking-manage__action">
								<?php
								get_template_part(
									'parts/events/flagship-card-form',
									null,
									array( 'booking_id' => $law_fm_id, 'label' => __( 'Change payment method', 'law' ), 'class' => 'law-linkish' )
								);
								?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php elseif ( $law_fm_can_method || '' !== $law_fm_card ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Payment method', 'law' ); ?></th>
					<td>
						<?php echo esc_html( '' !== $law_fm_card ? $law_fm_card : __( 'No payment method saved yet.', 'law' ) ); ?>
						<?php if ( $law_fm_can_method && 'law-payment-failed' !== $law_fm_status ) : ?>
							<span class="law-booking-manage__action">
								<?php
								get_template_part(
									'parts/events/flagship-card-form',
									null,
									array(
										'booking_id' => $law_fm_id,
										'label'      => '' !== $law_fm_card ? __( 'Change payment method', 'law' ) : __( 'Add payment details', 'law' ),
										// An inline link, not a button: it sits beside the
										// method as an aside, the way "Update my profile" does
										// on the row below. The failed-payment panel keeps a
										// real button, because there it IS the action.
										'class'      => 'law-linkish',
									)
								);
								?>
							</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( '' !== $law_fm_invoice && 'paid' === $law_fm_pay ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'VAT receipt', 'law' ); ?></th>
					<td>
						<a href="<?php echo esc_url( $law_fm_invoice ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View and download your invoice', 'law' ); ?></a>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( 'law-declined' === $law_fm_status ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Outcome', 'law' ); ?></th>
					<td>
						<?php esc_html_e( 'The committee was not able to offer a place. Nothing was charged, and the payment details have been removed.', 'law' ); ?>
						<?php if ( '' !== (string) law_event_meta( $law_fm_id, '_law_decline_reason' ) ) : ?>
							<span class="law-booking-table__sub"><?php echo esc_html( (string) law_event_meta( $law_fm_id, '_law_decline_reason' ) ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

			<?php if ( $law_fm_booker_view ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Their details', 'law' ); ?></th>
					<td>
						<?php
						$law_fm_snapshot = law_booking_attendee( $law_fm_booking );
						echo esc_html( $law_fm_snapshot['name'] . ( '' !== $law_fm_snapshot['email'] ? ' (' . $law_fm_snapshot['email'] . ')' : '' ) );
						?>
						<span class="law-booking-table__sub"><?php esc_html_e( 'As you gave them when registering. They can update their own details from their profile.', 'law' ); ?></span>
					</td>
				</tr>
			<?php else : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Your details', 'law' ); ?></th>
					<td>
						<?php esc_html_e( 'Taken from your profile, so they are always up to date.', 'law' ); ?>
						<span class="law-booking-table__sub">
							<a href="<?php echo esc_url( home_url( '/account/profile/' ) ); ?>"><?php esc_html_e( 'Update my profile', 'law' ); ?></a>
						</span>
					</td>
				</tr>
			<?php endif; ?>

			<?php foreach ( array( 'job_title' => __( 'Job title', 'law' ), 'organisation' => __( 'Organisation', 'law' ), 'country' => __( 'Country', 'law' ), 'dietary_other' => __( 'Dietary requirements', 'law' ), 'accessibility_other' => __( 'Access requirements', 'law' ) ) as $law_fm_key => $law_fm_label ) : ?>
				<?php if ( '' !== trim( (string) ( $law_fm_answers[ $law_fm_key ] ?? '' ) ) ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $law_fm_label ); ?></th>
						<td><?php echo esc_html( (string) $law_fm_answers[ $law_fm_key ] ); ?></td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>

<?php if ( $law_fm_can_withdraw ) : ?>
	<div class="law-booking-manage__foot">
		<form class="law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_flagship_withdraw">
			<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_fm_id ); ?>">
			<?php wp_nonce_field( 'law_flagship_withdraw' ); ?>
			<?php law_events_honeypot_field(); ?>
			<?php
			// The house pattern: the opener is a plain submit (so the form
			// still works without JavaScript) carrying data-law-modal-open,
			// and the confirm dialog is rendered INSIDE the form it confirms.
			// The close button is relabelled so "Cancel" never reads as the
			// destructive choice on a page about cancelling.
			?>
			<?php
			// Either of them may withdraw a registration made for someone else
			// (BOOK_ON_BEHALF.md A5), so the words follow who is reading.
			$law_fm_wd_label = $law_fm_booker_view ? __( 'Withdraw this registration', 'law' ) : __( 'Withdraw my registration', 'law' );
			?>
			<button type="submit" class="button alert" data-law-modal-open="law-flagship-withdraw-<?php echo esc_attr( (string) $law_fm_id ); ?>">
				<?php echo esc_html( $law_fm_wd_label ); ?>
			</button>
			<?php
			get_template_part(
				'parts/layout/modal',
				null,
				array(
					'id'      => 'law-flagship-withdraw-' . $law_fm_id,
					'title'   => $law_fm_booker_view ? __( 'Withdraw this registration?', 'law' ) : __( 'Withdraw your registration?', 'law' ),
					'copy'    => array(
						// Future tense throughout: this dialog describes what
						// confirming WILL do. The present tense read as a
						// statement that it had already happened, which on a
						// dialog with a Cancel button is alarming.
						$law_fm_booker_view
							? __( 'This will withdraw the registration and delete the payment details we hold. Nothing has been charged, and nothing will be. We will email the person you registered to let them know.', 'law' )
							: ( $law_fm_pays
								? __( 'This will withdraw your registration and delete the payment details we hold. You have not been charged, and you will not be.', 'law' )
								: __( 'This will withdraw your registration. Nothing has been charged, and the person who registered you will be told.', 'law' ) ),
						__( 'A new registration can be made while registration is open, but it would go to the back of the queue.', 'law' ),
					),
					'confirm' => array(
						'label' => $law_fm_wd_label,
						'class' => 'button alert',
						'busy'  => __( 'Withdrawing…', 'law' ),
					),
					'close'   => __( 'Keep the registration', 'law' ),
				)
			);
			?>
		</form>
	</div>
<?php endif; ?>
