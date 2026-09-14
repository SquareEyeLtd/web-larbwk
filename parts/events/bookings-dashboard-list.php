<?php
/**
 * The Bookings dashboard's results table (or empty message) for
 * templates/account-bookings-dashboard.php. Rendered inside #law-cal-events
 * and returned on its own by the &law_partial=1 endpoint
 * (functions/events/bookings-dashboard.php), so filtering swaps it in place.
 *
 * One flat row per attendee, the per-event list's shape with the event added.
 * Read-only: Reject and Register live on the per-event list, which the
 * booking number and event title both link to.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_bd_filters = law_bookings_dashboard_filters();
$law_bd_data    = law_bookings_dashboard_rows( $law_bd_filters );
$law_bd_rows    = $law_bd_data['rows'];
?>

<?php if ( ! $law_bd_rows ) : ?>
	<p class="law-cal__empty"><?php esc_html_e( 'No bookings match.', 'law' ); ?></p>
<?php else : ?>
	<p class="law-bookings-dashboard__summary">
		<?php
		// Two plurals, two _n() calls: one selector cannot serve nouns whose
		// counts diverge. One booking is one attendee, so they are one noun.
		echo esc_html( sprintf(
			/* translators: 1: "N booking(s)", 2: "N event(s)". */
			__( '%1$s on %2$s.', 'law' ),
			sprintf( _n( '%s booking', '%s bookings', $law_bd_data['bookings'], 'law' ), number_format_i18n( $law_bd_data['bookings'] ) ),
			sprintf( _n( '%s event', '%s events', $law_bd_data['events'], 'law' ), number_format_i18n( $law_bd_data['events'] ) )
		) );
		?>
	</p>
	<?php if ( $law_bd_data['truncated'] ) : ?>
		<p class="law-bookings-dashboard__truncated" role="status"><?php echo esc_html( sprintf( __( 'Showing the most recent %s bookings. Narrow the filters, or use an export for the full set.', 'law' ), number_format_i18n( LAW_BOOKINGS_DASHBOARD_SCREEN_CAP ) ) ); ?></p>
	<?php endif; ?>

	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-booking-table law-bookings-dashboard__table">
			<thead><tr>
				<th><?php esc_html_e( 'Booking', 'law' ); ?></th>
				<th><?php esc_html_e( 'Event', 'law' ); ?></th>
				<th><?php esc_html_e( 'Attendee', 'law' ); ?></th>
				<th><?php esc_html_e( 'Email', 'law' ); ?></th>
				<th><?php esc_html_e( 'Organisation', 'law' ); ?></th>
				<th><?php esc_html_e( 'Job title', 'law' ); ?></th>
				<th><?php esc_html_e( 'Country', 'law' ); ?></th>
				<th><?php esc_html_e( 'Status', 'law' ); ?></th>
				<?php
				// The money, only where the programme has any: on a week with
				// no paid reception these would be three empty columns on an
				// already wide table (RECEPTIONS.md §8.3).
				$law_bd_priced = function_exists( 'law_bookings_dashboard_has_priced' ) && law_bookings_dashboard_has_priced();
				?>
				<?php if ( $law_bd_priced ) : ?>
					<th><?php esc_html_e( 'Payment', 'law' ); ?></th>
					<th><?php esc_html_e( 'Code', 'law' ); ?></th>
					<th><?php esc_html_e( 'Invoice', 'law' ); ?></th>
				<?php endif; ?>
				<th><?php esc_html_e( 'Booked', 'law' ); ?></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $law_bd_rows as $law_bd_row ) :
				$law_bd_list = law_booking_list_url( $law_bd_row['event_id'] );
				?>
				<tr>
					<td class="law-booking-table__booking">
						<strong><a href="<?php echo esc_url( $law_bd_list ); ?>">#<?php echo esc_html( (string) $law_bd_row['number'] ); ?></a></strong>
					</td>
					<td class="law-booking-table__event">
						<a href="<?php echo esc_url( $law_bd_list ); ?>"><?php echo esc_html( $law_bd_row['event_title'] ); ?></a>
						<?php if ( '' !== $law_bd_row['event_start'] ) : ?>
							<br><small><?php echo esc_html( date_i18n( 'D j M Y, H:i', strtotime( $law_bd_row['event_start'] ) ) ); ?></small>
						<?php endif; ?>
					</td>
					<td><strong><?php echo esc_html( $law_bd_row['name'] ); ?></strong><?php
						if ( '' !== $law_bd_row['invited_by'] ) {
							echo ' <span class="law-cal-card__badge law-booking-table__invited">' . esc_html( sprintf( __( 'Invited by %s', 'law' ), $law_bd_row['invited_by'] ) ) . '</span>';
						}
						if ( $law_bd_row['is_press'] ) {
							echo ' <span class="law-cal-card__badge law-booking-table__press">' . esc_html__( 'Press', 'law' ) . '</span>';
						}
					?></td>
					<td><?php echo esc_html( $law_bd_row['email'] ); ?></td>
					<td><?php echo esc_html( $law_bd_row['organisation'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $law_bd_row['job_title'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $law_bd_row['country'] ?: '—' ); ?></td>
					<td>
						<?php if ( 'cancelled' === $law_bd_row['status'] ) : ?>
							<span class="law-cal-card__badge law-cal-card__badge--cancelled"><?php esc_html_e( 'Cancelled', 'law' ); ?></span>
						<?php elseif ( 'waitlisted' === $law_bd_row['status'] ) : ?>
							<span class="law-cal-card__badge law-cal-card__badge--waitlisted"><?php esc_html_e( 'Waitlisted', 'law' ); ?></span>
						<?php elseif ( 'pending-payment' === $law_bd_row['status'] ) : ?>
							<span class="law-cal-card__badge law-cal-card__badge--applied"><?php esc_html_e( 'Awaiting payment', 'law' ); ?></span>
						<?php elseif ( 'payment-failed' === $law_bd_row['status'] ) : ?>
							<span class="law-cal-card__badge law-cal-card__badge--payment-failed"><?php esc_html_e( 'Payment failed', 'law' ); ?></span>
						<?php else : ?>
							<span class="law-cal-card__badge law-cal-card__badge--confirmed"><?php esc_html_e( 'Confirmed', 'law' ); ?></span>
						<?php endif; ?>
					</td>
					<?php if ( $law_bd_priced ) : ?>
						<td>
							<?php echo esc_html( $law_bd_row['payment_label'] ?: '—' ); ?>
							<?php if ( in_array( (string) $law_bd_row['payment'], array( 'paid', 'refunded' ), true ) && (int) $law_bd_row['amount'] > 0 ) : ?>
								<span class="law-booking-table__sub"><?php echo esc_html( law_events_format_pence( (int) $law_bd_row['amount'] ) ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo '' !== (string) $law_bd_row['discount_code'] ? '<code>' . esc_html( (string) $law_bd_row['discount_code'] ) . '</code>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></td>
						<td><?php if ( '' !== (string) $law_bd_row['invoice_url'] ) : ?>
							<a href="<?php echo esc_url( (string) $law_bd_row['invoice_url'] ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View', 'law' ); ?></a>
						<?php else : ?>—<?php endif; ?></td>
					<?php endif; ?>
					<td class="law-booking-table__booked"><?php echo esc_html( mysql2date( 'j M Y', $law_bd_row['booked'] ) ); ?><br><small><?php echo esc_html( mysql2date( 'H:i', $law_bd_row['booked'] ) ); ?></small></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
