<?php
/**
 * The Manage emails table, for templates/account-dashboard-emails.php.
 *
 * A flat table in the dashboard idiom (Denis prefers proper tables to card
 * blocks). Every registered notification is listed, active or not: "which
 * emails are switched off" is a question this screen has to be able to answer,
 * and hiding the inactive ones would make it the one screen that cannot.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'law_user_is_committee' ) || ! law_user_is_committee() ) {
	echo '<p>' . esc_html__( 'This dashboard is for the LAW committee.', 'law' ) . '</p>';
	return;
}

$law_el_rows   = law_emails_dashboard_rows();
$law_el_active = count( array_filter( wp_list_pluck( $law_el_rows, 'active' ) ) );
?>

<?php if ( ! $law_el_rows ) : ?>
	<p class="law-cal__empty"><?php esc_html_e( 'No notifications are registered.', 'law' ); ?></p>
<?php else : ?>
	<p class="law-emails__summary">
		<?php
		echo esc_html(
			sprintf(
				/* translators: 1: number of notifications, 2: how many of them are switched on. */
				_n( '%1$s notification, %2$s switched on.', '%1$s notifications, %2$s switched on.', count( $law_el_rows ), 'law' ),
				number_format_i18n( count( $law_el_rows ) ),
				number_format_i18n( $law_el_active )
			)
		);
		?>
	</p>

	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-booking-table law-emails__table">
			<thead><tr>
				<th><?php esc_html_e( 'Notification', 'law' ); ?></th>
				<th><?php esc_html_e( 'Sent when', 'law' ); ?></th>
				<th><?php esc_html_e( 'Recipients', 'law' ); ?></th>
				<th><?php esc_html_e( 'Status', 'law' ); ?></th>
				<th class="law-dashboard__row-actions"><span class="show-for-sr"><?php esc_html_e( 'Actions', 'law' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $law_el_rows as $law_el_row ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( law_emails_dashboard_url( $law_el_row['slug'] ) ); ?>"><strong><?php echo esc_html( $law_el_row['name'] ); ?></strong></a>
						<?php if ( $law_el_row['customised'] ) : ?>
							<span class="law-cal-card__badge law-emails__badge"><?php esc_html_e( 'Edited', 'law' ); ?></span>
						<?php endif; ?>
						<?php
						// A migrated Gravity Forms merge tag nothing can resolve
						// would be delivered to somebody literally, so it is
						// called out on the row rather than waiting to be found.
						?>
						<?php if ( $law_el_row['unresolved'] ) : ?>
							<span class="law-cal-card__badge law-cal-card__badge--cancelled"><?php esc_html_e( 'Check the tags', 'law' ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $law_el_row['trigger'] ); ?></td>
					<td><?php echo esc_html( $law_el_row['to'] ); ?></td>
					<td>
						<span class="law-cal-card__badge <?php echo $law_el_row['active'] ? '' : 'law-cal-card__badge--cancelled'; ?>">
							<?php echo $law_el_row['active'] ? esc_html__( 'On', 'law' ) : esc_html__( 'Off', 'law' ); ?>
						</span>
					</td>
					<td class="law-dashboard__row-actions">
						<a class="button second" href="<?php echo esc_url( law_emails_dashboard_url( $law_el_row['slug'] ) ); ?>"><?php esc_html_e( 'Edit', 'law' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
