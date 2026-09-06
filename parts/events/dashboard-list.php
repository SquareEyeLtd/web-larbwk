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
?>

<?php if ( ! $law_events ) : ?>
	<p class="law-cal__empty"><?php esc_html_e( 'No events match.', 'law' ); ?></p>
<?php else : ?>
	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table">
			<thead><tr><th>Event</th><th>Host</th><th>Slot</th><th>Status</th><th>Payment</th><th></th></tr></thead>
			<tbody>
			<?php foreach ( $law_events as $law_row ) :
				$law_row_status = law_event_status_label( $law_row );
				$law_row_author = get_user_by( 'id', (int) $law_row->post_author );
				?>
				<tr>
					<td><strong><a href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, get_permalink() ) ); ?>"><?php echo esc_html( $law_row->post_title ); ?></a></strong><br>
						<code><?php echo esc_html( (string) law_event_meta( $law_row->ID, '_law_reference' ) ); ?></code></td>
					<td><?php echo esc_html( $law_row_author ? $law_row_author->display_name : '—' ); ?></td>
					<td><?php echo esc_html( (string) law_event_meta( $law_row->ID, '_law_slot_label' ) ?: '—' ); ?></td>
					<td><span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( law_calendar_status_slug( $law_row_status ) ); ?>"><?php echo esc_html( $law_row_status ); ?></span></td>
					<td><?php echo esc_html( ucfirst( (string) law_event_meta( $law_row->ID, '_law_payment_status' ) ) ?: '—' ); ?></td>
					<td><a class="button" href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, get_permalink() ) ); ?>">Review</a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
