<?php
/**
 * The receptions table, for templates/account-dashboard-receptions.php.
 *
 * A flat table in the dashboard idiom (Denis prefers proper tables to card
 * blocks). Receptions are never deleted from here: a reception with bookings
 * on it is part of the record of what people paid for, so the way to take one
 * off the programme is to untick "Show on the programme".
 *
 * The places column is three figures, not one. On a priced reception a place
 * held while somebody stands on Stripe's page counts towards the total sold,
 * so "Bookings (12)" with eleven rows underneath it would otherwise read as a
 * bug rather than as a hold (RECEPTIONS.md §1.3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_rl_rows = law_receptions_dashboard_rows();
?>

<?php if ( ! $law_rl_rows ) : ?>
	<p class="law-cal__empty">
		<?php esc_html_e( 'No receptions yet.', 'law' ); ?>
		<a href="<?php echo esc_url( law_receptions_dashboard_url( 'new' ) ); ?>"><?php esc_html_e( 'Add the first one', 'law' ); ?></a>.
	</p>
<?php else : ?>
	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-booking-table law-receptions__table">
			<thead><tr>
				<th><?php esc_html_e( 'Reception', 'law' ); ?></th>
				<th><?php esc_html_e( 'Day and time', 'law' ); ?></th>
				<th><?php esc_html_e( 'Venue', 'law' ); ?></th>
				<th><?php esc_html_e( 'Places', 'law' ); ?></th>
				<th><?php esc_html_e( 'Price', 'law' ); ?></th>
				<th><?php esc_html_e( 'Included with flagship', 'law' ); ?></th>
				<th><?php esc_html_e( 'Invitation only', 'law' ); ?></th>
				<th><?php esc_html_e( 'On programme', 'law' ); ?></th>
				<th class="law-dashboard__row-actions"><span class="show-for-sr"><?php esc_html_e( 'Actions', 'law' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $law_rl_rows as $law_rl_row ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $law_rl_row['title'] ); ?></strong>
						<?php if ( $law_rl_row['shown'] && $law_rl_row['view_url'] ) : ?>
							<span class="law-booking-table__sub">
								<a href="<?php echo esc_url( $law_rl_row['view_url'] ); ?>"><?php esc_html_e( 'View the page', 'law' ); ?></a>
							</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $law_rl_row['when'] ?: __( 'Not set', 'law' ) ); ?></td>
					<td><?php echo esc_html( $law_rl_row['venue'] ?: __( 'Not set', 'law' ) ); ?></td>
					<td>
						<?php if ( $law_rl_row['available'] < 1 ) : ?>
							<?php esc_html_e( 'Not released', 'law' ); ?>
						<?php else : ?>
							<?php
							echo esc_html(
								sprintf(
									/* translators: 1: confirmed places, 2: places available. */
									__( '%1$d of %2$d confirmed', 'law' ),
									$law_rl_row['confirmed'],
									$law_rl_row['available']
								)
							);
							?>
							<?php if ( $law_rl_row['pending'] > 0 ) : ?>
								<span class="law-booking-table__sub">
									<?php
									echo esc_html(
										sprintf(
											/* translators: %d: places held while a payment finishes. */
											_n( '%d awaiting payment', '%d awaiting payment', $law_rl_row['pending'], 'law' ),
											$law_rl_row['pending']
										)
									);
									?>
								</span>
							<?php endif; ?>
							<span class="law-booking-table__sub">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: places left. */
										_n( '%d place left', '%d places left', (int) $law_rl_row['remaining'], 'law' ),
										(int) $law_rl_row['remaining']
									)
								);
								?>
							</span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $law_rl_row['price_label'] ); ?></td>
					<td><?php echo $law_rl_row['included'] ? esc_html__( 'Yes', 'law' ) : '<span aria-hidden="true">—</span><span class="show-for-sr">' . esc_html__( 'No', 'law' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></td>
					<td><?php echo $law_rl_row['invitation'] ? esc_html__( 'Yes', 'law' ) : '<span aria-hidden="true">—</span><span class="show-for-sr">' . esc_html__( 'No', 'law' ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></td>
					<td>
						<span class="law-cal-card__badge <?php echo $law_rl_row['shown'] ? 'law-cal-card__badge--confirmed' : 'law-cal-card__badge--cancelled'; ?>">
							<?php echo esc_html( $law_rl_row['shown'] ? __( 'Live', 'law' ) : __( 'Draft', 'law' ) ); ?>
						</span>
					</td>
					<td class="law-dashboard__row-actions">
						<a class="button second" href="<?php echo esc_url( $law_rl_row['edit_url'] ); ?>">
							<?php esc_html_e( 'Edit', 'law' ); ?>
							<span class="show-for-sr"> <?php echo esc_html( $law_rl_row['title'] ); ?></span>
						</a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
