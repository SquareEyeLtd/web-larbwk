<?php
/**
 * Committee dashboard event list: the results table (or empty message) for
 * templates/account-dashboard.php. Rendered inside #law-cal-events on the
 * dashboard and returned on its own by the &law_partial=1 AJAX endpoint
 * (functions/events/committee.php), so filtering swaps it in place.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_events = law_committee_events();

// The status select's counts come from one wp_count_posts() call and ignore
// every filter, so with persistent filters applied "Proposed (12)" can sit
// above three rows. This line is the cheap honest answer; making the counts
// filter-aware would mean a query per status.
$law_total = 0;
foreach ( (array) wp_count_posts( LAW_EVENT_CPT ) as $law_status_key => $law_status_total ) {
	if ( 'law-draft' !== $law_status_key && 'auto-draft' !== $law_status_key && 'trash' !== $law_status_key && 'inherit' !== $law_status_key ) {
		$law_total += (int) $law_status_total;
	}
}
?>

<?php if ( ! $law_events ) : ?>
	<p class="law-cal__empty"><?php esc_html_e( 'No events match. Try clearing a filter.', 'law' ); ?></p>
<?php else : ?>
	<p class="law-dashboard__result-count">
		<?php
		printf(
			esc_html( _n( 'Showing %1$d of %2$d event.', 'Showing %1$d of %2$d events.', $law_total, 'law' ) ),
			count( $law_events ),
			(int) $law_total
		);
		?>
	</p>
	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table">
			<thead><tr><th>Event</th><th>Host</th><th>Slot</th><th>Status</th><th>Payment</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $law_events as $law_row ) :
				$law_row_status = law_event_status_label( $law_row );
				$law_row_author = get_user_by( 'id', (int) $law_row->post_author );
				?>
				<tr>
					<td><strong><a href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, get_permalink() ) ); ?>"><?php echo esc_html( $law_row->post_title ); ?></a></strong>
						<?php law_event_law_badge( $law_row->ID ); ?><br>
						<code><?php echo esc_html( (string) law_event_meta( $law_row->ID, '_law_reference' ) ); ?></code>
						<?php $law_row_agenda = law_event_agenda_summary( $law_row->ID ); ?>
						<?php if ( '' !== $law_row_agenda ) : ?>
							<br><span class="law-dashboard__row-note"><?php echo esc_html( $law_row_agenda ); ?></span>
						<?php endif; ?></td>
					<td><?php echo esc_html( $law_row_author ? $law_row_author->display_name : '—' ); ?></td>
					<td><?php echo esc_html( (string) law_event_meta( $law_row->ID, '_law_slot_label' ) ?: '—' ); ?></td>
					<td><span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( law_calendar_status_slug( $law_row_status ) ); ?>"><?php echo esc_html( $law_row_status ); ?></span></td>
					<td><?php echo esc_html( ucfirst( (string) law_event_meta( $law_row->ID, '_law_payment_status' ) ) ?: '—' ); ?></td>
					<td class="law-dashboard__row-actions"><a class="button" href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, get_permalink() ) ); ?>">Review</a>
					<?php if ( 'publish' === $law_row->post_status && function_exists( 'law_booking_list_url' ) ) : ?>
						<?php // The same bookings list the host sees: one view, one gate. ?>
						<a class="button" href="<?php echo esc_url( law_booking_list_url( $law_row->ID ) ); ?>"><?php echo esc_html( law_booking_counts_label( $law_row->ID ) ); ?></a>
					<?php endif; ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
