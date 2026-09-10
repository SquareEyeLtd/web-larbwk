<?php
/**
 * The flagship applications table, for
 * templates/account-dashboard-flagship-bookings.php, and the whole response
 * of the &law_partial=1 endpoint, so filtering swaps it in place.
 *
 * Every decision here moves money: Approve charges a saved payment method,
 * Decline detaches it and voids the invoice. So the markup is built to be
 * right WITHOUT JavaScript, not merely enhanced by it:
 *
 * - each row's Approve and Decline are their own small form carrying their
 *   own hidden `decision`, rather than one shared form with a hidden default
 *   that a script is trusted to flip. (It was the latter, and no such script
 *   existed, so Decline approved the applicant and charged them.)
 * - the bulk form lives OUTSIDE the table and the tick boxes join it with the
 *   HTML `form` attribute, so the per-row forms are siblings rather than
 *   illegally nested inside it;
 * - the bulk buttons carry `name="decision"`, which a native submit sends and
 *   which booking-form.js now appends to its fetch body too.
 *
 * Both actions sit behind a confirm dialog (the house rule for anything
 * destructive or expensive), and the decline reason lives IN the dialog so a
 * per-row decline cannot pick up something typed for a different row.
 *
 * NOTHING renders below the table. The list can run to hundreds of rows, so
 * anything under it is effectively invisible (Denis, 10 September 2026): the
 * bulk actions are inline text links above it, the bulk form itself is above
 * it too, and the over-booking warning was dropped outright because the lede
 * on the page above already says the same thing in the same words.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_fbl_data  = law_flagship_bookings_rows( law_flagship_bookings_filters() );
$law_fbl_rows  = $law_fbl_data['rows'];
$law_fbl_count = $law_fbl_data['counts'];
$law_fbl_full  = ! empty( $law_fbl_count['full'] );
$law_fbl_bulk  = 'law-flagship-bulk';

/** One approve/decline form for a row. */
$law_fbl_decision_form = static function ( array $row, $decision ) use ( $law_fbl_full, $law_fbl_count ) {
	$approve = 'approve' === $decision;
	$modal   = 'law-fb-' . $decision . '-' . $row['id'];
	$amount  = law_events_format_pence( $row['gross_pence'] );

	// When the conference is already full, an approval over-books it. Say so
	// on the button and in the dialog, and carry the confirmation with the
	// request, so the committee decides before clicking rather than meeting a
	// refusal they then have no way past.
	$overbooks = $approve && $law_fbl_full;

	// Future tense throughout: a confirm dialog describes what the button
	// WILL do, and the present tense reads as a statement that it already
	// has — which on a dialog offering to cancel is alarming, and on one
	// about taking £660 off somebody, worse.
	$copy = $approve
		? array(
			$row['complimentary']
				? sprintf( __( 'This will give %s a confirmed place with nothing to pay.', 'law' ), $row['name'] )
				: sprintf( __( 'This will charge %1$s to the payment method %2$s saved and confirm their place straight away.', 'law' ), $amount, $row['name'] ),
			__( 'They will be emailed a confirmation with a VAT invoice and a calendar invitation.', 'law' ),
		)
		: array(
			sprintf( __( 'This will tell %s they have not been offered a place. They will not be charged.', 'law' ), $row['name'] ),
			__( 'The payment details they saved will be deleted and any unpaid invoice voided, so it can no longer be paid.', 'law' ),
		);

	if ( $overbooks ) {
		$copy[] = sprintf(
			/* translators: 1: confirmed, 2: available. */
			__( 'The conference is already full: %1$d of %2$d places are taken, so this will over-book it by one.', 'law' ),
			$law_fbl_count['confirmed'],
			$law_fbl_count['available']
		);
	}
	?>
	<form class="law-booking-form law-flagship-bookings__inline" method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="law_flagship_review">
		<input type="hidden" name="booking_id[]" value="<?php echo esc_attr( (string) $row['id'] ); ?>">
		<input type="hidden" name="decision" value="<?php echo esc_attr( $decision ); ?>">
		<?php if ( $overbooks ) : ?>
			<input type="hidden" name="confirm_overbook" value="1">
		<?php endif; ?>
		<?php wp_nonce_field( 'law_flagship_review' ); ?>
		<?php law_events_honeypot_field(); ?>

		<?php
		// A plain submit, so the row still works with no JavaScript; the
		// dialog is an enhancement over it, not a dependency.
		?>
		<?php
		// Text links, not buttons (Denis, 10 September 2026), coloured by
		// what they do. The dialog behind each one keeps a real button,
		// because there the action IS the primary control.
		?>
		<button type="submit" class="law-linkish <?php echo $approve ? 'law-linkish--approve' : 'law-linkish--decline'; ?>"
			data-law-modal-open="<?php echo esc_attr( $modal ); ?>">
			<?php
			if ( $approve ) {
				echo $overbooks ? esc_html__( 'Approve (over-books)', 'law' ) : esc_html__( 'Approve', 'law' );
			} else {
				esc_html_e( 'Decline', 'law' );
			}
			?>
		</button>

		<?php
		get_template_part(
			'parts/layout/modal',
			null,
			array(
				'id'      => $modal,
				'title'   => $approve
					? sprintf( __( 'Approve %s?', 'law' ), $row['name'] )
					: sprintf( __( 'Decline %s?', 'law' ), $row['name'] ),
				'copy'    => $copy,
				'field'   => $approve ? array() : array(
					'name'     => 'reason',
					'label'    => __( 'Reason (optional)', 'law' ),
					'help'     => __( 'Included in the email to the applicant. Leave it empty to say nothing beyond the decision.', 'law' ),
					'rows'     => 3,
					'required' => false,
				),
				'confirm' => array(
					'label' => $approve
						? ( $row['complimentary'] ? __( 'Confirm the place', 'law' ) : sprintf( __( 'Charge %s and confirm', 'law' ), $amount ) )
						: __( 'Decline the application', 'law' ),
					'class' => $approve ? 'button orange' : 'button alert',
					'busy'  => $approve ? __( 'Charging…', 'law' ) : __( 'Declining…', 'law' ),
				),
				'close'   => $approve ? __( 'Leave it for now', 'law' ) : __( 'Keep the application', 'law' ),
			)
		);
		?>
	</form>
	<?php
};
?>

<?php if ( ! $law_fbl_rows ) : ?>
	<p class="law-cal__empty"><?php esc_html_e( 'No applications match.', 'law' ); ?></p>
<?php else : ?>

	<p class="law-flagship-bookings__summary">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: number of applications. */
				_n( '%s application.', '%s applications.', count( $law_fbl_rows ), 'law' ),
				number_format_i18n( count( $law_fbl_rows ) )
			)
		);
		?>
	</p>

	<?php
	// The bulk form: outside the TABLE, so the per-row forms are siblings
	// rather than illegally nested in it, and above it, because nothing may
	// render below a table that can run to hundreds of rows (Denis,
	// 10 September 2026). The tick boxes and the two action links join it
	// with the HTML `form` attribute, which does not care about order.
	//
	// It shows nothing itself: its buttons live in the row below and its two
	// confirm dialogs are position:fixed.
	?>
	<form class="law-booking-form law-flagship-bookings__bulk" id="<?php echo esc_attr( $law_fbl_bulk ); ?>" method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="law_flagship_review">
		<?php if ( $law_fbl_full ) : ?>
			<input type="hidden" name="confirm_overbook" value="1">
		<?php endif; ?>
		<?php wp_nonce_field( 'law_flagship_review' ); ?>
		<?php law_events_honeypot_field(); ?>

		<?php
		get_template_part(
			'parts/layout/modal',
			null,
			array(
				'id'      => 'law-fb-bulk-approve',
				'title'   => __( 'Approve the selected applications?', 'law' ),
				'copy'    => array_filter(
					array(
						__( 'Each one will have the payment method they saved charged and their place confirmed straight away. Everyone will be emailed a confirmation with a VAT invoice and a calendar invitation.', 'law' ),
						__( 'Large batches are charged ten at a time, and the rest continue in the background.', 'law' ),
						$law_fbl_full
							? sprintf(
								/* translators: 1: confirmed, 2: available. */
								__( 'The conference is already full (%1$d of %2$d places taken), so this will over-book it.', 'law' ),
								$law_fbl_count['confirmed'],
								$law_fbl_count['available']
							)
							: '',
					)
				),
				'confirm' => array(
					'label' => __( 'Charge and confirm', 'law' ),
					'name'  => 'decision',
					'value' => 'approve',
					'class' => 'button orange',
					'busy'  => __( 'Charging…', 'law' ),
				),
				'close'   => __( 'Leave them for now', 'law' ),
			)
		);

		get_template_part(
			'parts/layout/modal',
			null,
			array(
				'id'      => 'law-fb-bulk-decline',
				'title'   => __( 'Decline the selected applications?', 'law' ),
				'copy'    => array(
					__( 'Nobody will be charged. Each applicant will be emailed to say they have not been offered a place.', 'law' ),
					__( 'The payment details each of them saved will be deleted and any unpaid invoice voided, so it can no longer be paid.', 'law' ),
				),
				'field'   => array(
					'name'     => 'reason',
					'label'    => __( 'Reason (optional)', 'law' ),
					'help'     => __( 'Included in the email to everyone you decline in this batch. Leave it empty to say nothing beyond the decision.', 'law' ),
					'rows'     => 3,
					'required' => false,
				),
				'confirm' => array(
					'label' => __( 'Decline them', 'law' ),
					'name'  => 'decision',
					'value' => 'decline',
					'class' => 'button alert',
					'busy'  => __( 'Declining…', 'law' ),
				),
				'close'   => __( 'Keep the applications', 'law' ),
			)
		);
		?>
	</form>

	<?php
	// Above the table on purpose (see the file header). They are real submit
	// buttons so the no-JS path still posts, dressed as links with
	// .law-linkish; disabled until something is ticked, which booking-form.js
	// manages through data-law-bulk-decide.
	?>
	<p class="law-flagship-bookings__bulk-actions">
		<button type="submit" name="decision" value="approve" form="<?php echo esc_attr( $law_fbl_bulk ); ?>"
			class="law-linkish" data-law-bulk-decide data-law-modal-open="law-fb-bulk-approve">
			<?php esc_html_e( 'Bulk approve', 'law' ); ?>
		</button>
		<button type="submit" name="decision" value="decline" form="<?php echo esc_attr( $law_fbl_bulk ); ?>"
			class="law-linkish" data-law-bulk-decide data-law-modal-open="law-fb-bulk-decline">
			<?php esc_html_e( 'Bulk decline', 'law' ); ?>
		</button>
		<button type="button" class="law-linkish law-flagship-bookings__add-opener"
			data-law-modal-open="<?php echo esc_attr( law_flagship_add_attendee_modal_id() ); ?>"
			data-law-modal-enhanced hidden>
			<?php esc_html_e( 'Add an attendee without payment', 'law' ); ?>
		</button>
	</p>

	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-booking-table law-flagship-bookings__table">
			<thead><tr>
				<th class="law-flagship-bookings__tick">
					<label>
						<input type="checkbox" data-law-check-all aria-label="<?php esc_attr_e( 'Select every application', 'law' ); ?>">
						<span class="show-for-sr"><?php esc_html_e( 'Select', 'law' ); ?></span>
					</label>
				</th>
				<th><?php esc_html_e( 'Application', 'law' ); ?></th>
				<th class="law-flagship-bookings__applicant"><?php esc_html_e( 'Applicant', 'law' ); ?></th>
				<th><?php esc_html_e( 'Email', 'law' ); ?></th>
				<th><?php esc_html_e( 'Price', 'law' ); ?></th>
				<th class="law-flagship-bookings__payment"><?php esc_html_e( 'Payment', 'law' ); ?></th>
				<th><?php esc_html_e( 'Status', 'law' ); ?></th>
				<th class="law-dashboard__row-actions"><span class="show-for-sr"><?php esc_html_e( 'Actions', 'law' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $law_fbl_rows as $law_fbl_row ) : ?>
				<tr>
					<td class="law-flagship-bookings__tick">
						<?php if ( $law_fbl_row['decidable'] ) : ?>
							<label>
								<?php
								// The `form` attribute binds the box to the bulk
								// form above the table, so the per-row forms in
								// the last cell are siblings rather than nested.
								?>
								<input type="checkbox" name="booking_id[]" form="<?php echo esc_attr( $law_fbl_bulk ); ?>"
									value="<?php echo esc_attr( (string) $law_fbl_row['id'] ); ?>">
								<span class="show-for-sr"><?php echo esc_html( sprintf( __( 'Select %s', 'law' ), $law_fbl_row['name'] ) ); ?></span>
							</label>
						<?php endif; ?>
					</td>
					<td>#<?php echo esc_html( (string) $law_fbl_row['number'] ); ?>
						<?php if ( '' !== $law_fbl_row['applied'] ) : ?>
							<span class="law-booking-table__sub"><?php echo esc_html( mysql2date( 'j M Y', $law_fbl_row['applied'] ) ); ?></span>
						<?php endif; ?>
					</td>
					<td class="law-flagship-bookings__applicant">
						<?php echo esc_html( $law_fbl_row['name'] ); ?>
						<?php if ( $law_fbl_row['press'] ) : ?>
							<span class="law-cal-card__badge"><?php esc_html_e( 'Press', 'law' ); ?></span>
						<?php endif; ?>
						<?php if ( $law_fbl_row['complimentary'] ) : ?>
							<span class="law-cal-card__badge"><?php esc_html_e( 'No charge', 'law' ); ?></span>
						<?php endif; ?>
						<?php
						// Organisation, job title and country had columns of
						// their own and were dropped for being too wide
						// (Denis, 10 September 2026). They belong to the
						// person, so they read better under their name than
						// as three sparse columns. array_filter, because a
						// delegate may have filled in none of them and a line
						// of stray commas is worse than no line.
						$law_fbl_about = array_filter(
							array(
								$law_fbl_row['organisation'],
								$law_fbl_row['job_title'],
								$law_fbl_row['country'],
							),
							static fn( $value ) => '' !== trim( (string) $value )
						);
						?>
						<?php if ( $law_fbl_about ) : ?>
							<span class="law-booking-table__sub"><?php echo esc_html( implode( ', ', $law_fbl_about ) ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $law_fbl_row['email'] ); ?></td>
					<td><?php echo esc_html( law_events_format_pence( $law_fbl_row['gross_pence'] ) ); ?></td>
					<td class="law-flagship-bookings__payment">
						<?php echo esc_html( $law_fbl_row['payment_label'] ); ?>
						<?php if ( '' !== $law_fbl_row['payment_error'] ) : ?>
							<span class="law-booking-table__sub law-flagship-bookings__error"><?php echo esc_html( $law_fbl_row['payment_error'] ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $law_fbl_row['invoice_url'] ) : ?>
							<span class="law-booking-table__sub">
								<a href="<?php echo esc_url( $law_fbl_row['invoice_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Stripe invoice', 'law' ); ?></a>
							</span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						// The decline reason hangs off the badge rather than
						// printing under it (Denis, 10 September 2026): it is
						// free text the committee typed, so one long one set
						// the height of the row and pushed everything else
						// about the application out of view. It is still on
						// the row for anyone who wants it, and it is in full
						// in the exports and on the wp-admin booking screen.
						?>
						<span class="law-cal-card__badge <?php echo esc_attr( law_booking_status_badge_class( $law_fbl_row['status'] ) ); ?>"
							<?php if ( '' !== $law_fbl_row['reason'] ) : ?>
								title="<?php echo esc_attr( $law_fbl_row['reason'] ); ?>"
							<?php endif; ?>>
							<?php echo esc_html( $law_fbl_row['status_label'] ); ?>
						</span>
					</td>
					<td class="law-dashboard__row-actions">
						<?php
						// Approve and Decline first and together: they are a
						// pair, and the CSS keeps them on one line. The
						// failed-payment actions follow rather than sitting
						// between them, which used to split the pair.
						?>
						<?php if ( $law_fbl_row['decidable'] ) : ?>
							<?php $law_fbl_decision_form( $law_fbl_row, 'approve' ); ?>
							<?php $law_fbl_decision_form( $law_fbl_row, 'decline' ); ?>
						<?php endif; ?>

						<?php if ( $law_fbl_row['retryable'] ) : ?>
							<form class="law-booking-form law-flagship-bookings__inline" method="post"
								action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="law_flagship_retry_charge">
								<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_fbl_row['id'] ); ?>">
								<?php wp_nonce_field( 'law_flagship_retry_charge' ); ?>
								<?php law_events_honeypot_field(); ?>
								<button type="submit" class="law-linkish" data-law-modal-busy="<?php esc_attr_e( 'Trying…', 'law' ); ?>"><?php esc_html_e( 'Retry charge', 'law' ); ?></button>
							</form>
							<form class="law-booking-form law-flagship-bookings__inline" method="post"
								action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="law_flagship_resend_payment">
								<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_fbl_row['id'] ); ?>">
								<?php wp_nonce_field( 'law_flagship_resend_payment' ); ?>
								<?php law_events_honeypot_field(); ?>
								<button type="submit" class="law-linkish" data-law-modal-busy="<?php esc_attr_e( 'Sending…', 'law' ); ?>"><?php esc_html_e( 'Resend request', 'law' ); ?></button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>


<?php endif; ?>
