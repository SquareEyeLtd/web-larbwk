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
 *   'highlight'    => 'emma',                 // phrase to <mark>; ?law_kw= by default
 * ) );
 *
 * The timeline turns the count line off -- it would count the unscheduled
 * handful against every event on the site -- and keeps the first button
 * (Review, or Edit on a reception) while dropping Bookings, which is the one
 * button that view does without throughout (its bars print the booking numbers
 * instead of offering a way into the list). It passes
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

// Search hits, marked in the three printed fields the keyword box actually
// searches: the title (core `s`), the host's name and the firm. Filtering told
// the committee which rows survived but never why (Denis, 16 September 2026),
// which is the same thing the programme's cards were fixed for the same day.
// The KEYWORD AS TYPED, matched whole and case-insensitively. Marking its
// individual words was tried first and reversed the same day: the box is used
// with long phrases lifted off a title, and every "in" and "with" in two
// columns then came back marked. A row the search matched word by word can
// therefore show with nothing marked, which is accepted.
// Read from the query string, like the keyword itself (law_committee_events()),
// so the first load, the &law_partial=1 fetch, the no-JS GET and the timeline's
// unscheduled section all mark the same words without a caller passing anything.
$law_hl = isset( $args['highlight'] )
	? (string) $args['highlight']
	: sanitize_text_field( wp_unslash( $_GET['law_kw'] ?? '' ) );

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
		<?php // --striped: this is the one dashboard table whose events can be two
		// <tr>s, so it counts its own stripe instead of Foundation's nth-child.
		// The modifier keeps that off the eight other tables sharing the base
		// class, which have one row per thing and stripe correctly as they are. ?>
		<table class="law-dashboard__table law-dashboard__table--striped">
			<thead><tr><th>Event</th><th>Host</th><th>Slot</th><th>Status</th><th>Payment</th><th>Bookings</th><th>Places left</th><?php if ( $law_show_actions ) : ?><th></th><?php endif; ?></tr></thead>
			<tbody>
			<?php
			// Zebra striping is counted HERE, not left to Foundation's
			// tbody tr:nth-child(even): an event with a search snippet under it
			// is two <tr>s, so the alternation would stripe half an event and
			// flip every event after the first snippet. The class goes on both
			// rows of a pair, so one event is always one band of colour.
			$law_row_index = 0;
			?>
			<?php foreach ( $law_events as $law_row ) :
				$law_row_status = law_event_status_label( $law_row );
				$law_row_author = get_user_by( 'id', (int) $law_row->post_author );
				// The description, where the keyword is in it and in none of the
				// columns. Core `s` searches the body text and the table never showed
				// a word of it, so those rows named nothing the committee had typed
				// (Denis, 16 September 2026). It gets a row of its own spanning the
				// table rather than a line inside the Event cell, because prose in a
				// 12rem column is five lines of two words (Denis, same day).
				$law_row_snippet = law_calendar_search_snippet( $law_row->post_content, $law_hl, 260 );
				$law_row_class   = ( ++$law_row_index % 2 ? '' : 'is-alt' );
				?>
				<tr class="<?php echo esc_attr( trim( $law_row_class . ( '' !== $law_row_snippet ? ' has-snippet' : '' ) ) ); ?>">
					<?php // law_calendar_highlight() returns escaped HTML with only its <mark> tags
					// raw, and falls back to plain esc_html() whenever there is no keyword or
					// no hit in this field. ?>
					<td><strong><a href="<?php echo esc_url( add_query_arg( 'event', $law_row->ID, $law_link_base ) ); ?>"><?php echo law_calendar_highlight( $law_row->post_title, $law_hl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></strong>
						<?php law_event_external_badge( $law_row->ID ); ?><br>
						<code><?php echo esc_html( (string) law_event_meta( $law_row->ID, '_law_reference' ) ); ?></code>
						<?php $law_row_agenda = law_event_agenda_summary( $law_row->ID ); ?>
						<?php if ( '' !== $law_row_agenda ) : ?>
							<br><span class="law-dashboard__row-note"><?php echo esc_html( $law_row_agenda ); ?></span>
						<?php endif; ?>
						</td>
					<?php // Person and firm together: the keyword box searches the firm
					// (functions/events/committee.php), so a "Mayer Brown" search whose
					// results never printed those words would read as a broken filter. ?>
					<td><?php echo $law_row_author ? law_calendar_highlight( $law_row_author->display_name, $law_hl ) : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<?php $law_row_firm = (string) law_event_meta( $law_row->ID, '_law_host_organisations' ); ?>
						<?php if ( '' !== $law_row_firm ) : ?>
							<br><span class="law-dashboard__row-note"><?php printf( esc_html__( 'Organisation: %s', 'law' ), law_calendar_highlight( $law_row_firm, $law_hl ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
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
					// "Can this event hold a booking here?", which decides both the
					// count and the Bookings button. NOT simply `publish` since
					// 16 September 2026: the committee can force booking open on an
					// approved event that has not paid yet
					// (law_event_booking_override()), and once places are taken on
					// one, keying this on the status alone would print "—" against
					// real bookings and leave the committee no way into the list --
					// including after they set the answer back to Automatic. An
					// unpublished event with no places taken is still a dash rather
					// than a hollow 0, which is the distinction this line exists for.
					// PUBLICLY LISTED and places taken, not merely places taken: a
					// Proposed event can hold no booking at all -- the guard refuses
					// one and the forced-open answer reaches only an event the public
					// can see -- so a stray _law_tickets_sold on one is impossible
					// data, not a booking to offer a way into.
					$law_row_bookable  = ! $law_row_external
						&& ( 'publish' === $law_row->post_status
							|| ( law_event_is_publicly_listed( $law_row ) && $law_row_sold > 0 ) );

					// A RECEPTION is edited on Manage receptions, not reviewed on
					// the event detail view (Denis, 16 September 2026): it has no
					// workflow to review -- no host submitted it, nobody approves
					// it and no invoice is raised -- and every field it does have
					// (date, times, venue, places, price, the included and
					// invitation switches) lives on that screen, behind the one
					// saver law_reception_save(). Sending the committee through a
					// read-only detail view to reach an Edit button was a hop with
					// nothing on it, so the row says what it does: Edit.
					$law_row_reception = function_exists( 'law_reception_is' ) && law_reception_is( $law_row->ID );
					$law_row_review_url = $law_row_reception && function_exists( 'law_receptions_dashboard_url' )
						? law_receptions_dashboard_url( $law_row->ID )
						: add_query_arg( 'event', $law_row->ID, $law_link_base );
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
					<td class="law-dashboard__row-actions"><a class="button" href="<?php echo esc_url( $law_row_review_url ); ?>"><?php echo esc_html( $law_row_reception ? __( 'Edit', 'law' ) : __( 'Review', 'law' ) ); ?></a>
					<?php if ( $law_show_bookings && $law_row_bookable && function_exists( 'law_booking_list_url' ) ) : ?>
						<?php // The same bookings list the host sees: one view, one gate. The
						// count lives in the Bookings column now, so the button is just a way in. ?>
						<a class="button" href="<?php echo esc_url( law_booking_list_url( $law_row->ID ) ); ?>"><?php esc_html_e( 'Bookings', 'law' ); ?></a>
					<?php endif; ?></td>
					<?php endif; ?>
				</tr>
				<?php if ( '' !== $law_row_snippet ) : ?>
					<?php // A row of its own, spanning every column, so the quotation gets
					// the full width of the table instead of the Event column's 12rem
					// (Denis, 16 September 2026). The pair shares one stripe class and
					// the event row above drops its bottom border, so the two <tr>s read
					// as one event rather than as an orphaned line of prose. ?>
					<tr class="<?php echo esc_attr( trim( 'law-dashboard__snippet-row ' . $law_row_class ) ); ?>">
						<td class="law-dashboard__snippet-cell" colspan="<?php echo (int) ( $law_show_actions ? 8 : 7 ); ?>"><?php echo $law_row_snippet; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endif; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
