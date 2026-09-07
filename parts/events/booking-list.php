<?php
/**
 * The per-event bookings list for hosts, co-owners and the committee
 * (?law_event_bookings=<event id> on /account/events/, EVENTS_BOOKINGS.md
 * §7.4). Access is law_user_can_manage_event(), re-checked here.
 *
 * A real table in the committee-dashboard idiom (Denis, 7 September 2026:
 * "more table view"), grouped by booking via full-width header rows; the
 * dietary and accessibility columns (read live from each attendee's profile)
 * wrap rather than inheriting the table's nowrap, and the wrap scrolls
 * sideways on mobile like every other dashboard table. Cancelled bookings sit
 * collapsed at the bottom, read-only, with the cancelled badge.
 *
 * Args: event_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_bl_event_id = absint( $args['event_id'] ?? 0 );
$law_bl_event    = get_post( $law_bl_event_id );

if ( ! $law_bl_event || LAW_EVENT_CPT !== $law_bl_event->post_type
	|| ! law_user_can_manage_event( get_current_user_id(), $law_bl_event_id ) ) {
	echo '<p class="law-cal__empty">' . esc_html__( 'Sorry, this event\'s bookings are not yours to view.', 'law' ) . '</p>';
	return;
}

$law_bl_active    = law_bookings_for_event( $law_bl_event_id );
$law_bl_cancelled = law_bookings_for_event( $law_bl_event_id, 'law-cancelled' );

// One users + one usermeta query for the whole table instead of two per
// attendee row (the dietary/accessibility columns read each linked profile).
$law_bl_user_ids = array();
foreach ( array_merge( $law_bl_active, $law_bl_cancelled ) as $law_bl_prime ) {
	foreach ( law_event_meta( $law_bl_prime->ID, '_law_attendee_rows' ) as $law_bl_prime_row ) {
		if ( ! empty( $law_bl_prime_row['user_id'] ) ) {
			$law_bl_user_ids[] = (int) $law_bl_prime_row['user_id'];
		}
	}
}
if ( $law_bl_user_ids ) {
	cache_users( array_values( array_unique( $law_bl_user_ids ) ) );
}
$law_bl_total     = law_event_attendee_total( $law_bl_event_id );
$law_bl_available = (int) law_event_meta( $law_bl_event_id, '_law_tickets_available' );

// Result notices (the reject redirect flow lands back here).
$law_bl_notice  = sanitize_key( (string) ( $_GET['law_notice'] ?? '' ) );
$law_bl_notices = array(
	'attendee-removed' => array( 'ok', __( 'The attendee has been rejected and emailed.', 'law' ) ),
	'booking-cancelled' => array( 'ok', __( 'That was the booking\'s last attendee, so the booking is now cancelled.', 'law' ) ),
	'booking-failed'   => array( 'error', __( 'Sorry, that change could not be made.', 'law' ) ),
	'rate-limited'     => array( 'error', __( 'Too many actions in a short time; please wait a moment and try again.', 'law' ) ),
);

$law_bl_export_base = wp_nonce_url( admin_url( 'admin-post.php?action=law_booking_export&event_id=' . $law_bl_event_id ), 'law_booking_export' );

/**
 * One flat table over a set of bookings: a leading Booking column carries the
 * number on every attendee row (the CSV's shape — Denis, 7 September 2026:
 * less height, "more tablish"), with the booker's name as a sub-line on the
 * booking's first row only.
 *
 * @param WP_Post[] $law_bl_set        Bookings to render.
 * @param bool      $law_bl_actionable Whether the Reject column is live.
 */
$law_bl_render_table = function ( array $law_bl_set, $law_bl_actionable ) {
	?>
	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-booking-table">
			<thead><tr>
				<th><?php esc_html_e( 'Booking', 'law' ); ?></th>
				<th><?php esc_html_e( 'Attendee', 'law' ); ?></th>
				<th><?php esc_html_e( 'Email', 'law' ); ?></th>
				<th><?php esc_html_e( 'Organisation', 'law' ); ?></th>
				<th><?php esc_html_e( 'Job title', 'law' ); ?></th>
				<th><?php esc_html_e( 'Accessibility', 'law' ); ?></th>
				<th><?php esc_html_e( 'Dietary', 'law' ); ?></th>
				<?php if ( $law_bl_actionable ) : ?><th></th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php foreach ( $law_bl_set as $law_bl_booking ) :
				$law_bl_number = (int) law_event_meta( $law_bl_booking->ID, '_law_booking_number' );
				$law_bl_owner  = get_user_by( 'id', (int) $law_bl_booking->post_author );
				$law_bl_rows   = law_event_meta( $law_bl_booking->ID, '_law_attendee_rows' );

				$law_bl_owner_seated = false;
				foreach ( $law_bl_rows as $law_bl_row ) {
					if ( ! empty( $law_bl_row['is_owner'] ) ) {
						$law_bl_owner_seated = true;
						break;
					}
				}
				?>
				<?php if ( ! $law_bl_rows ) : ?>
					<tr>
						<td class="law-booking-table__booking"><strong>#<?php echo esc_html( (string) $law_bl_number ); ?></strong></td>
						<td colspan="<?php echo $law_bl_actionable ? 7 : 6; ?>"><?php esc_html_e( 'No attendees.', 'law' ); ?></td>
					</tr>
				<?php endif; ?>
				<?php foreach ( array_values( $law_bl_rows ) as $law_bl_i => $law_bl_row ) :
					$law_bl_user    = ! empty( $law_bl_row['user_id'] ) ? get_user_by( 'id', (int) $law_bl_row['user_id'] ) : null;
					$law_bl_profile = $law_bl_user ? law_profile_values( (int) $law_bl_user->ID ) : array();
					$law_bl_access  = law_booking_profile_requirements( $law_bl_profile, 'accessibility' );
					$law_bl_diet    = law_booking_profile_requirements( $law_bl_profile, 'dietary' );
					$law_bl_modal   = 'law-modal-reject-' . $law_bl_booking->ID . '-' . $law_bl_i;
					?>
					<tr<?php echo 0 === $law_bl_i ? ' class="law-booking-table__first"' : ''; ?>>
						<td class="law-booking-table__booking">
							<strong>#<?php echo esc_html( (string) $law_bl_number ); ?></strong>
							<?php if ( 0 === $law_bl_i && $law_bl_owner ) : ?>
								<br><small><?php echo esc_html(
									sprintf(
										$law_bl_owner_seated ? __( 'by %s', 'law' ) : __( 'by %s (not attending)', 'law' ),
										$law_bl_owner->display_name
									)
								); ?></small>
							<?php endif; ?>
							<?php if ( 0 === $law_bl_i && ! $law_bl_actionable ) : ?>
								<br><span class="law-cal-card__badge law-cal-card__badge--cancelled"><?php esc_html_e( 'Cancelled', 'law' ); ?></span>
							<?php endif; ?>
						</td>
						<td><strong><?php echo esc_html( (string) ( $law_bl_row['name'] ?? '' ) ); ?></strong><?php
							if ( ! empty( $law_bl_row['is_owner'] ) ) {
								echo ' <span class="law-booking-manage__owner-flag">' . esc_html__( '(booker)', 'law' ) . '</span>';
							}
						?></td>
						<td><?php echo esc_html( (string) ( $law_bl_row['email'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $law_bl_row['organisation'] ?? '' ) ?: '—' ); ?></td>
						<td><?php echo esc_html( (string) ( $law_bl_row['job_title'] ?? '' ) ?: '—' ); ?></td>
						<td class="law-booking-table__req"><?php echo esc_html( $law_bl_access ?: '—' ); ?></td>
						<td class="law-booking-table__req"><?php echo esc_html( $law_bl_diet ?: '—' ); ?></td>
						<?php if ( $law_bl_actionable ) : ?>
							<td class="law-dashboard__row-actions">
								<form class="law-booking-form law-booking-manage__action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="law_booking_reject_attendee">
									<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_bl_booking->ID ); ?>">
									<input type="hidden" name="attendee_email" value="<?php echo esc_attr( (string) ( $law_bl_row['email'] ?? '' ) ); ?>">
									<?php wp_nonce_field( 'law_booking_reject_attendee' ); ?>
									<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>
									<button type="submit" class="button alert" data-law-modal-open="<?php echo esc_attr( $law_bl_modal ); ?>"><?php esc_html_e( 'Reject', 'law' ); ?></button>
									<?php
									get_template_part(
										'parts/layout/modal',
										null,
										array(
											'id'      => $law_bl_modal,
											'title'   => sprintf( __( 'Reject %s', 'law' ), (string) ( $law_bl_row['name'] ?? '' ) ),
											'copy'    => array(
												sprintf( __( 'This cancels %s\'s place and frees it for someone else. They are emailed to let them know.', 'law' ), (string) ( $law_bl_row['name'] ?? '' ) ),
												1 === count( $law_bl_rows ) ? __( 'They are the last person on the booking, so the whole booking will be cancelled.', 'law' ) : '',
											),
											'field'   => array(
												'name'     => 'law_reject_reason',
												'label'    => __( 'Why is the place being cancelled? (optional)', 'law' ),
												'help'     => __( 'Included in the email to the attendee.', 'law' ),
												'rows'     => 3,
												'required' => false,
											),
											'confirm' => array( 'label' => __( 'Reject attendee', 'law' ), 'class' => 'button alert', 'busy' => __( 'Rejecting…', 'law' ) ),
											'close'   => __( 'Keep this attendee', 'law' ),
										)
									);
									?>
								</form>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
};
?>

<div class="grid-x grid-padding-x">
	<div class="large-12 cell">

		<?php if ( isset( $law_bl_notices[ $law_bl_notice ] ) ) : ?>
			<p class="law-form-notice <?php echo 'ok' === $law_bl_notices[ $law_bl_notice ][0] ? 'is-success' : 'is-error'; ?>" role="alert"><?php echo esc_html( $law_bl_notices[ $law_bl_notice ][1] ); ?></p>
		<?php endif; ?>

		<h2 class="law-booking-manage__title"><?php echo esc_html( sprintf( __( 'Bookings: %s', 'law' ), $law_bl_event->post_title ) ); ?></h2>
		<p class="law-booking-substate">
			<?php
			// Two plurals in one sentence, so two _n() calls: one selector cannot
			// serve both "attendee(s)" and "booking(s)" when the counts diverge.
			echo esc_html( sprintf(
				/* translators: 1: "N attendee(s)", 2: "N active booking(s)". */
				__( '%1$s across %2$s.', 'law' ),
				sprintf( _n( '%s attendee', '%s attendees', $law_bl_total, 'law' ), number_format_i18n( $law_bl_total ) ),
				sprintf( _n( '%d active booking', '%d active bookings', count( $law_bl_active ), 'law' ), count( $law_bl_active ) )
			) );
			if ( $law_bl_available > 0 ) {
				echo ' ' . esc_html( sprintf( __( '%1$s of %2$s places taken.', 'law' ), number_format_i18n( $law_bl_total ), number_format_i18n( $law_bl_available ) ) );
			}
			?>
		</p>

		<div class="law-cal-export" data-law-export data-export-url="<?php echo esc_url( $law_bl_export_base ); ?>">
			<span class="law-cal-export__label"><?php esc_html_e( 'Export:', 'law' ); ?></span>
			<a class="button second" data-format="csv" href="<?php echo esc_url( add_query_arg( 'format', 'csv', $law_bl_export_base ) ); ?>">CSV</a>
			<a class="button second" data-format="xlsx" href="<?php echo esc_url( add_query_arg( 'format', 'xlsx', $law_bl_export_base ) ); ?>">Excel</a>
			<button type="button" class="button second" data-format="pdf" hidden>PDF</button>
		</div>

		<?php if ( ! $law_bl_active ) : ?>
			<p class="law-cal__empty"><?php esc_html_e( 'No active bookings yet.', 'law' ); ?></p>
		<?php else : ?>
			<?php $law_bl_render_table( $law_bl_active, true ); ?>
		<?php endif; ?>

		<?php if ( $law_bl_cancelled ) : ?>
			<details class="law-booking-cancelled">
				<summary><?php echo esc_html( sprintf( _n( '%d cancelled booking', '%d cancelled bookings', count( $law_bl_cancelled ), 'law' ), count( $law_bl_cancelled ) ) ); ?></summary>
				<?php $law_bl_render_table( $law_bl_cancelled, false ); ?>
			</details>
		<?php endif; ?>

	</div>
</div>
