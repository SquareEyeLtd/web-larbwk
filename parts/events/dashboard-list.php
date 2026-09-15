<?php
/**
 * Committee dashboard event list: the results table (or empty message) for
 * templates/account-dashboard.php. Rendered inside #law-cal-events on the
 * dashboard and returned on its own by the &law_partial=1 AJAX endpoint
 * (functions/events/committee.php), so filtering swaps it in place.
 *
 * With no $args it runs its own query, which is what the dashboard wants. The
 * timeline view passes the events it has already grouped, for the section at
 * the foot of the chart holding every event with no confirmed slot: those have
 * no place on any day's axis, so they are shown as the list view rather than as
 * a second, thinner list invented for the purpose (Denis, 15 September 2026:
 * "the section with 'No confirmed slots' just should repeat the list view").
 *
 * get_template_part( 'parts/events/dashboard-list', null, array(
 *   'events'       => array( WP_Post, ... ),  // skips law_committee_events()
 *   'show_count'   => false,                  // the "Showing N of M" line
 *   'show_actions' => true,                   // the actions column at all
 *   'show_bookings'=> false,                  // the Bookings button within it
 *   'link_base'    => law_slotchart_url(),    // what a row's title links to
 * ) );
 *
 * The timeline turns the count line off -- it would count the unscheduled
 * handful against every event on the site -- and keeps Review while dropping
 * Bookings, which is the one button that view does without throughout (its bars
 * print the booking numbers instead of offering a way into the list). It passes
 * a link_base because law_slotchart_url() carries the view and the current
 * filters, so the detail page's back link returns to the chart rather than
 * dropping the committee on the table with their filters cleared -- the same
 * href the bars themselves carry.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_events       = isset( $args['events'] ) && is_array( $args['events'] ) ? $args['events'] : law_committee_events();
$law_show_count   = (bool) ( $args['show_count'] ?? true );
$law_show_actions  = (bool) ( $args['show_actions'] ?? true );
$law_show_bookings = (bool) ( $args['show_bookings'] ?? true );
$law_link_base    = (string) ( $args['link_base'] ?? get_permalink() );

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
	<?php if ( $law_show_count ) : ?>
	<p class="law-dashboard__result-count">
		<?php
		printf(
			esc_html( _n( 'Showing %1$d of %2$d event.', 'Showing %1$d of %2$d events.', $law_total, 'law' ) ),
			count( $law_events ),
			(int) $law_total
		);
		?>
	</p>
	<?php endif; ?>
	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table">
			<thead><tr><th>Event</th><th>Host</th><th>Slot</th><th>Status</th><th>Payment</th><th>Bookings</th><th>Places left</th><?php if ( $law_show_actions ) : ?><th></th><?php endif; ?></tr></thead>
			<tbody>
			<?php foreach ( $law_events as $law_row ) :
				$law_row_status = law_event_status_label( $law_row );
				$law_row_author = get_user_by( 'id', (int) $law_row->post_author );
				?>
				<tr>
					<td><strong><a href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, $law_link_base ) ); ?>"><?php echo esc_html( $law_row->post_title ); ?></a></strong>
						<?php law_event_external_badge( $law_row->ID ); ?><br>
						<code><?php echo esc_html( (string) law_event_meta( $law_row->ID, '_law_reference' ) ); ?></code>
						<?php $law_row_agenda = law_event_agenda_summary( $law_row->ID ); ?>
						<?php if ( '' !== $law_row_agenda ) : ?>
							<br><span class="law-dashboard__row-note"><?php echo esc_html( $law_row_agenda ); ?></span>
						<?php endif; ?></td>
					<?php // Person and firm together: the keyword box searches the firm
					// (functions/events/committee.php), so a "Mayer Brown" search whose
					// results never printed those words would read as a broken filter. ?>
					<td><?php echo esc_html( $law_row_author ? $law_row_author->display_name : '—' ); ?>
						<?php $law_row_firm = (string) law_event_meta( $law_row->ID, '_law_host_organisations' ); ?>
						<?php if ( '' !== $law_row_firm ) : ?>
							<br><span class="law-dashboard__row-note"><?php printf( esc_html__( 'Organisation: %s', 'law' ), esc_html( $law_row_firm ) ); ?></span>
						<?php endif; ?></td>
					<?php // Date on one line, time under it: the label is the widest thing
					// in the column otherwise, and the two halves read faster stacked. ?>
					<?php $law_row_slot = law_events_split_slot_label( law_event_meta( $law_row->ID, '_law_slot_label' ) ); ?>
					<td>
						<?php if ( '' === $law_row_slot['date'] ) : ?>
							—
						<?php else : ?>
							<?php echo esc_html( $law_row_slot['date'] ); ?>
							<?php if ( '' !== $law_row_slot['time'] ) : ?>
								<br><span class="law-dashboard__row-note"><?php echo esc_html( $law_row_slot['time'] ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<td><span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( law_calendar_status_slug( $law_row_status ) ); ?>"><?php echo esc_html( $law_row_status ); ?></span></td>
					<td><?php echo esc_html( ucfirst( (string) law_event_meta( $law_row->ID, '_law_payment_status' ) ) ?: '—' ); ?></td>
					<?php
					// Bookings and places left, both straight off the event's own meta
					// (_law_tickets_sold, kept current by law_event_recount_attendees(),
					// and _law_tickets_available) rather than a count query per row, so
					// the columns cost nothing on a 300-row list.
					//
					// Only a Confirmed (published) event can hold a booking, so the count
					// is a dash rather than a hollow 0 on everything else. Places left is
					// null until capacity is set at approval, which means "not open for
					// booking" -- the same reading law_booking_guard_open() takes.
					$law_row_sold      = function_exists( 'law_event_attendee_total' ) ? law_event_attendee_total( $law_row->ID ) : 0;
					$law_row_available = (int) law_event_meta( $law_row->ID, '_law_tickets_available' );
					$law_row_left      = function_exists( 'law_event_tickets_remaining' ) ? law_event_tickets_remaining( $law_row->ID ) : null;
					$law_row_waiting   = function_exists( 'law_waitlist_count' ) ? law_waitlist_count( $law_row->ID ) : 0;
					// An external event is booked on the organiser's own website, so it
					// can never hold one here. Both the count and the Bookings button
					// would otherwise offer a way into an empty list on every row
					// (Denis, 15 September 2026), and a hollow "0" reads as "nobody has
					// booked" rather than as "bookings do not happen here".
					$law_row_external  = function_exists( 'law_event_is_external' ) && law_event_is_external( $law_row->ID );
					$law_row_bookable  = 'publish' === $law_row->post_status && ! $law_row_external;
					?>
					<td>
						<?php if ( ! $law_row_bookable ) : ?>
							—
						<?php elseif ( function_exists( 'law_booking_list_url' ) ) : ?>
							<a href="<?php echo esc_url( law_booking_list_url( $law_row->ID ) ); ?>"><?php echo esc_html( number_format_i18n( $law_row_sold ) ); ?></a>
						<?php else : ?>
							<?php echo esc_html( number_format_i18n( $law_row_sold ) ); ?>
						<?php endif; ?>
						<?php if ( $law_row_waiting ) : ?>
							<br><span class="law-dashboard__row-note"><?php printf( esc_html( _n( '%s waiting', '%s waiting', $law_row_waiting, 'law' ) ), esc_html( number_format_i18n( $law_row_waiting ) ) ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						// "of 80" only once some of them have gone: on an event nobody
						// has booked yet the capacity IS the number above it, and
						// repeating it says nothing (Denis, 11 September 2026).
						?>
						<?php if ( null === $law_row_left ) : ?>
							—
						<?php else : ?>
							<?php echo esc_html( number_format_i18n( $law_row_left ) ); ?>
							<?php if ( $law_row_left !== $law_row_available ) : ?>
								<br><span class="law-dashboard__row-note"><?php printf( esc_html__( 'of %s', 'law' ), esc_html( number_format_i18n( $law_row_available ) ) ); ?></span>
							<?php endif; ?>
						<?php endif; ?>
					</td>
					<?php if ( $law_show_actions ) : ?>
					<td class="law-dashboard__row-actions"><a class="button" href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, $law_link_base ) ); ?>">Review</a>
					<?php if ( $law_show_bookings && $law_row_bookable && function_exists( 'law_booking_list_url' ) ) : ?>
						<?php // The same bookings list the host sees: one view, one gate. The
						// count lives in the Bookings column now, so the button is just a way in. ?>
						<a class="button" href="<?php echo esc_url( law_booking_list_url( $law_row->ID ) ); ?>"><?php esc_html_e( 'Bookings', 'law' ); ?></a>
					<?php endif; ?></td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
