<?php
/**
 * The Event submitters table, for
 * templates/account-dashboard-submitters.php.
 *
 * A flat table in the dashboard idiom (Denis prefers proper tables to card
 * blocks), newest grant first: the committee's question after adding somebody
 * is "did that work", and a name sort buries the answer.
 *
 * The list is everybody holding the `event_submitter` ROLE. Committee members,
 * editors and administrators can submit through their own roles and are
 * deliberately absent — they are not what this screen hands out, and a Remove
 * button beside them would do nothing to their ability to submit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_sl_rows = law_submitters_rows();
?>

<?php if ( ! $law_sl_rows ) : ?>
	<p class="law-cal__empty">
		<?php esc_html_e( 'Nobody has been made an event submitter yet. Use “Add a submitter” above to give somebody the role.', 'law' ); ?>
	</p>
<?php else : ?>
	<p class="law-submitters__summary">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: number of people. */
				_n( '%s event submitter.', '%s event submitters.', count( $law_sl_rows ), 'law' ),
				number_format_i18n( count( $law_sl_rows ) )
			)
		);
		?>
	</p>

	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-booking-table law-submitters__table">
			<thead><tr>
				<th><?php esc_html_e( 'Name', 'law' ); ?></th>
				<th><?php esc_html_e( 'Email', 'law' ); ?></th>
				<th><?php esc_html_e( 'Organisation', 'law' ); ?></th>
				<th><?php esc_html_e( 'Added', 'law' ); ?></th>
				<th class="law-dashboard__row-actions"><span class="show-for-sr"><?php esc_html_e( 'Actions', 'law' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $law_sl_rows as $law_sl_row ) : ?>
				<?php $law_sl_modal = 'law-modal-submitter-' . (int) $law_sl_row['id']; ?>
				<tr>
					<td>
						<?php echo esc_html( $law_sl_row['name'] ); ?>
						<?php if ( '' !== $law_sl_row['roles'] ) : ?>
							<span class="law-booking-table__sub"><?php echo esc_html( $law_sl_row['roles'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><a href="mailto:<?php echo esc_attr( $law_sl_row['email'] ); ?>"><?php echo esc_html( $law_sl_row['email'] ); ?></a></td>
					<td><?php echo '' !== $law_sl_row['organisation'] ? esc_html( $law_sl_row['organisation'] ) : '—'; ?></td>
					<td>
						<?php
						// No grant recorded is not a gap in the data: the role
						// can be added on the wp-admin Users screen, and an
						// account that arrived that way has nothing to show.
						// Say which it is rather than printing a dash that
						// could mean either.
						if ( $law_sl_row['granted'] ) {
							echo esc_html( wp_date( 'j M Y', $law_sl_row['granted'] ) );
							if ( '' !== $law_sl_row['granted_by'] ) {
								echo '<span class="law-booking-table__sub">' . esc_html( sprintf( __( 'by %s', 'law' ), $law_sl_row['granted_by'] ) ) . '</span>';
							}
						} else {
							echo '<span class="law-booking-table__sub">' . esc_html__( 'Added outside this screen', 'law' ) . '</span>';
						}
						?>
					</td>
					<td class="law-dashboard__row-actions">
						<?php
						// A plain POST that JS upgrades to a fetch, like every
						// other row action in the module. Behind a confirm
						// dialog, unlike the discount toggle: this one takes
						// something away from a person, and the dialog is where
						// the reassurance about their existing events belongs.
						?>
						<form class="law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="law_submitter_remove">
							<input type="hidden" name="law_submitter_id" value="<?php echo esc_attr( (string) $law_sl_row['id'] ); ?>">
							<?php wp_nonce_field( 'law_submitter_remove' ); ?>
							<?php law_events_honeypot_field(); ?>
							<button type="submit" class="button alert hollow" data-law-modal-open="<?php echo esc_attr( $law_sl_modal ); ?>">
								<?php esc_html_e( 'Remove', 'law' ); ?>
							</button>
							<?php
							get_template_part(
								'parts/layout/modal',
								null,
								array(
									'id'      => $law_sl_modal,
									'title'   => __( 'Remove this submitter', 'law' ),
									'copy'    => array(
										sprintf(
											/* translators: %s: person's name. */
											__( '%s will no longer be able to propose a new event.', 'law' ),
											$law_sl_row['name']
										),
										__( 'Nothing else changes. They keep their account and their bookings, and they can still edit and withdraw every event they already run. You can add them again at any time.', 'law' ),
									),
									'confirm' => array(
										'label' => __( 'Remove submitter', 'law' ),
										'class' => 'button alert',
										'busy'  => __( 'Removing…', 'law' ),
									),
								)
							);
							?>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
