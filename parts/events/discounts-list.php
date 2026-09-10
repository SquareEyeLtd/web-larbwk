<?php
/**
 * The discount-code catalogue's table, for
 * templates/account-dashboard-discounts.php.
 *
 * A flat table in the dashboard idiom (Denis prefers proper tables to card
 * blocks). Codes are never deleted from here, only disabled: a code that has
 * been used is part of the record of what somebody was charged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_dl_rows = law_discounts_dashboard_rows();
?>

<?php if ( ! $law_dl_rows ) : ?>
	<p class="law-cal__empty">
		<?php esc_html_e( 'No discount codes yet.', 'law' ); ?>
		<a href="<?php echo esc_url( law_discounts_dashboard_url( 'new' ) ); ?>"><?php esc_html_e( 'Add the first one', 'law' ); ?></a>.
	</p>
<?php else : ?>
	<p class="law-discounts__summary">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: number of codes. */
				_n( '%s discount code.', '%s discount codes.', count( $law_dl_rows ), 'law' ),
				number_format_i18n( count( $law_dl_rows ) )
			)
		);
		?>
	</p>

	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-booking-table law-discounts__table">
			<thead><tr>
				<th><?php esc_html_e( 'Code', 'law' ); ?></th>
				<th><?php esc_html_e( 'Discount', 'law' ); ?></th>
				<th><?php esc_html_e( 'Valid', 'law' ); ?></th>
				<th><?php esc_html_e( 'Used', 'law' ); ?></th>
				<th><?php esc_html_e( 'Applies to', 'law' ); ?></th>
				<th><?php esc_html_e( 'Status', 'law' ); ?></th>
				<th class="law-dashboard__row-actions"><span class="show-for-sr"><?php esc_html_e( 'Actions', 'law' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $law_dl_rows as $law_dl_row ) : ?>
				<tr>
					<td><code><?php echo esc_html( $law_dl_row['code'] ); ?></code>
						<?php if ( '' !== $law_dl_row['note'] ) : ?>
							<span class="law-booking-table__sub"><?php echo esc_html( $law_dl_row['note'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $law_dl_row['summary'] ); ?></td>
					<td><?php echo esc_html( $law_dl_row['window'] ); ?></td>
					<td><?php echo esc_html( $law_dl_row['uses_label'] ); ?></td>
					<td><?php echo esc_html( $law_dl_row['scope'] ); ?></td>
					<td>
						<span class="law-cal-card__badge <?php echo $law_dl_row['active'] ? '' : 'law-cal-card__badge--cancelled'; ?>">
							<?php echo $law_dl_row['active'] ? esc_html__( 'Active', 'law' ) : esc_html__( 'Disabled', 'law' ); ?>
						</span>
					</td>
					<td class="law-dashboard__row-actions">
						<a class="button second" href="<?php echo esc_url( law_discounts_dashboard_url( $law_dl_row['id'] ) ); ?>"><?php esc_html_e( 'Edit', 'law' ); ?></a>
						<?php
						// A plain POST that JS upgrades to a fetch, like every
						// other row action in the module. No confirm dialog:
						// nothing is lost and the button turns it straight
						// back on.
						?>
						<form class="law-booking-form law-discounts__toggle" method="post"
							action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="law_discount_toggle">
							<input type="hidden" name="law_discount_id" value="<?php echo esc_attr( (string) $law_dl_row['id'] ); ?>">
							<?php wp_nonce_field( 'law_discount_toggle' ); ?>
							<?php law_events_honeypot_field(); ?>
							<button type="submit" class="button second"
								data-law-busy="<?php echo $law_dl_row['active'] ? esc_attr__( 'Disabling…', 'law' ) : esc_attr__( 'Enabling…', 'law' ); ?>">
								<?php echo $law_dl_row['active'] ? esc_html__( 'Disable', 'law' ) : esc_html__( 'Enable', 'law' ); ?>
							</button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
