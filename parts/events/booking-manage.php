<?php
/**
 * The manage-booking view (?law_booking=<id> on /account/events/,
 * EVENTS_BOOKINGS.md §7.3). Access: the booking's owner, or anyone holding a
 * seat on it — re-checked here, not trusted from the caller (the thread
 * partial sets the precedent).
 *
 * The owner can remove any attendee (their own row reads "Remove me"), add a
 * colleague while places and the 3-guest cap allow, or cancel the whole
 * booking; a seated guest can only remove themselves. Every action is a
 * form-shaped POST behind a parts/layout/modal confirm, submitted over fetch
 * by booking-form.js with the plain-POST fallback.
 *
 * Args: booking_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_bm_id      = absint( $args['booking_id'] ?? 0 );
$law_bm_booking = get_post( $law_bm_id );
$law_bm_user    = wp_get_current_user();

$law_bm_rows     = $law_bm_booking ? law_event_meta( $law_bm_id, '_law_attendee_rows' ) : array();
$law_bm_is_owner = $law_bm_booking && (int) $law_bm_booking->post_author === (int) $law_bm_user->ID;
$law_bm_is_seated = false;
foreach ( $law_bm_rows as $law_bm_row ) {
	if ( (int) ( $law_bm_row['user_id'] ?? 0 ) === (int) $law_bm_user->ID ) {
		$law_bm_is_seated = true;
		break;
	}
}

if ( ! $law_bm_booking || LAW_BOOKING_CPT !== $law_bm_booking->post_type || ( ! $law_bm_is_owner && ! $law_bm_is_seated ) ) {
	echo '<p class="law-cal__empty">' . esc_html__( 'Sorry, this booking is not yours to view.', 'law' ) . '</p>';
	return;
}

$law_bm_event_id  = (int) $law_bm_booking->post_parent;
$law_bm_event     = get_post( $law_bm_event_id );
$law_bm_number    = (int) law_event_meta( $law_bm_id, '_law_booking_number' );
$law_bm_cancelled = 'law-cancelled' === $law_bm_booking->post_status;
$law_bm_guests    = count( array_filter( $law_bm_rows, fn( $r ) => empty( $r['is_owner'] ) ) );
$law_bm_names     = implode( ', ', array_filter( wp_list_pluck( $law_bm_rows, 'name' ) ) );

// Adding is possible while the booking is active, the guest cap has room and
// the event is still open for booking (ticket number set, not started) with
// places actually left — a full event hides the form rather than inviting a
// submission the capacity guard will refuse.
$law_bm_can_add = $law_bm_is_owner
	&& ! $law_bm_cancelled
	&& $law_bm_guests < law_booking_max_additional()
	&& true === law_booking_guard_open( $law_bm_event_id )
	&& 0 !== law_event_tickets_remaining( $law_bm_event_id );

$law_bm_start = (string) law_event_meta( $law_bm_event_id, '_law_start' );
$law_bm_when  = '' !== $law_bm_start
	? date_i18n( 'l j F Y', strtotime( $law_bm_start ) ) . ', ' . substr( $law_bm_start, 11, 5 )
	: '';
$law_bm_venue = (string) law_event_meta( $law_bm_event_id, '_law_venue' );

// Result notices (redirect flow; the fetch flow reloads onto these too).
$law_bm_notice  = sanitize_key( (string) ( $_GET['law_notice'] ?? '' ) );
$law_bm_notices = array(
	'attendee-added'    => array( 'ok', __( 'The attendee has been added and emailed the event details.', 'law' ) ),
	'attendee-removed'  => array( 'ok', __( 'The attendee has been removed and their place freed.', 'law' ) ),
	'booking-cancelled' => array( 'ok', __( 'The booking has been cancelled and everyone on it has been emailed.', 'law' ) ),
	'booking-failed'    => array( 'error', __( 'Sorry, that change could not be made.', 'law' ) ),
	'rate-limited'      => array( 'error', __( 'Too many actions in a short time; please wait a moment and try again.', 'law' ) ),
);
?>

<div class="grid-x grid-padding-x">
	<div class="large-8 cell">

		<?php if ( isset( $law_bm_notices[ $law_bm_notice ] ) ) : ?>
			<p class="law-form-notice <?php echo 'ok' === $law_bm_notices[ $law_bm_notice ][0] ? 'is-success' : 'is-error'; ?>" role="alert"><?php echo esc_html( $law_bm_notices[ $law_bm_notice ][1] ); ?></p>
		<?php endif; ?>

		<h2 class="law-booking-manage__title">
			<?php echo esc_html( sprintf( __( 'Booking #%d', 'law' ), $law_bm_number ) ); ?>
			<?php if ( $law_bm_cancelled ) : ?>
				<span class="law-cal-card__badge law-cal-card__badge--cancelled"><?php esc_html_e( 'Cancelled', 'law' ); ?></span>
			<?php endif; ?>
		</h2>

		<p class="law-booking-summary">
			<strong><?php echo $law_bm_event && 'publish' === $law_bm_event->post_status
				? '<a href="' . esc_url( get_permalink( $law_bm_event ) ) . '">' . esc_html( $law_bm_event->post_title ) . '</a>'
				: esc_html( $law_bm_event ? $law_bm_event->post_title : __( '(event unavailable)', 'law' ) );
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
			?></strong>
			<?php if ( '' !== $law_bm_when ) : ?><br><?php echo esc_html( $law_bm_when ); ?><?php endif; ?>
			<?php if ( '' !== $law_bm_venue ) : ?><br><?php echo esc_html( $law_bm_venue ); ?><?php endif; ?>
		</p>

		<h3 class="law-booking-manage__subtitle"><?php esc_html_e( 'Attendees', 'law' ); ?></h3>
		<ul class="law-dashboard__people law-booking-manage__people">
			<?php foreach ( array_values( $law_bm_rows ) as $law_bm_i => $law_bm_row ) :
				$law_bm_row_email = (string) ( $law_bm_row['email'] ?? '' );
				$law_bm_row_name  = (string) ( $law_bm_row['name'] ?? $law_bm_row_email );
				// Self = the row's linked account, with the snapshot email as
				// the fallback: someone who changed their account email since
				// booking must still be able to remove themselves.
				$law_bm_row_self = ( (int) ( $law_bm_row['user_id'] ?? 0 ) === (int) $law_bm_user->ID )
					|| ( '' !== $law_bm_row_email && strtolower( $law_bm_row_email ) === strtolower( (string) $law_bm_user->user_email ) );
				$law_bm_can_touch = ! $law_bm_cancelled && ( $law_bm_is_owner || $law_bm_row_self );
				$law_bm_facts     = array_filter( array( (string) ( $law_bm_row['organisation'] ?? '' ), (string) ( $law_bm_row['job_title'] ?? '' ) ) );
				$law_bm_last_row  = 1 === count( $law_bm_rows );
				$law_bm_modal_id  = 'law-modal-remove-' . $law_bm_id . '-' . $law_bm_i;

				// The confirm copy per context (round 6, specified up front).
				if ( $law_bm_last_row ) {
					$law_bm_copy = __( 'This is the last person on the booking, so the whole booking will be cancelled.', 'law' );
				} elseif ( $law_bm_row_self && $law_bm_is_owner ) {
					$law_bm_copy = __( 'Your place is freed for someone else, but your colleagues keep theirs and you can still manage this booking.', 'law' );
				} elseif ( $law_bm_row_self ) {
					$law_bm_copy = __( 'Your place is freed for someone else. The rest of the booking is not affected.', 'law' );
				} else {
					$law_bm_copy = sprintf( __( 'This removes %s from the booking and frees their place. They are emailed to let them know.', 'law' ), $law_bm_row_name );
				}
				?>
				<li>
					<span class="law-booking-manage__person">
						<strong><?php echo esc_html( $law_bm_row_name ); ?></strong>
						<?php if ( ! empty( $law_bm_row['is_owner'] ) ) : ?>
							<span class="law-booking-manage__owner-flag"><?php esc_html_e( '(booker)', 'law' ); ?></span>
						<?php endif; ?>
						<br><?php echo esc_html( $law_bm_row_email . ( $law_bm_facts ? ' · ' . implode( ', ', $law_bm_facts ) : '' ) ); ?>
					</span>
					<?php if ( $law_bm_can_touch ) : ?>
						<form class="law-booking-form law-booking-manage__action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="law_booking_remove_attendee">
							<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_bm_id ); ?>">
							<input type="hidden" name="attendee_email" value="<?php echo esc_attr( $law_bm_row_email ); ?>">
							<?php wp_nonce_field( 'law_booking_remove_attendee' ); ?>
							<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>
							<button type="submit" class="button alert" data-law-modal-open="<?php echo esc_attr( $law_bm_modal_id ); ?>"><?php echo esc_html( $law_bm_row_self ? __( 'Remove me', 'law' ) : __( 'Remove', 'law' ) ); ?></button>
							<?php
							get_template_part(
								'parts/layout/modal',
								null,
								array(
									'id'      => $law_bm_modal_id,
									'title'   => $law_bm_row_self ? __( 'Remove yourself from this booking', 'law' ) : sprintf( __( 'Remove %s', 'law' ), $law_bm_row_name ),
									'copy'    => $law_bm_copy,
									'confirm' => array(
										'label' => $law_bm_row_self ? __( 'Remove me', 'law' ) : __( 'Remove', 'law' ),
										'class' => 'button alert',
										'busy'  => __( 'Removing…', 'law' ),
									),
									'close'   => $law_bm_row_self ? __( 'Stay on the booking', 'law' ) : __( 'Keep them on the booking', 'law' ),
								)
							);
							?>
						</form>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php if ( $law_bm_can_add ) : ?>
			<h3 class="law-booking-manage__subtitle"><?php esc_html_e( 'Add a colleague', 'law' ); ?></h3>
			<form class="law-event-form law-event-form--light law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="law_booking_add_attendee">
				<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_bm_id ); ?>">
				<?php wp_nonce_field( 'law_booking_add_attendee' ); ?>
				<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>
				<div class="law-row">
					<div class="law-row-grid law-row-grid--attendee">
						<label><?php esc_html_e( 'Full name *', 'law' ); ?><input type="text" autocomplete="off" aria-required="true" name="law_attendees[0][name]"></label>
						<label><?php esc_html_e( 'Email *', 'law' ); ?><input type="email" autocomplete="off" aria-required="true" name="law_attendees[0][email]"></label>
						<label><?php esc_html_e( 'Organisation', 'law' ); ?><input type="text" autocomplete="off" name="law_attendees[0][organisation]"></label>
						<label><?php esc_html_e( 'Job title', 'law' ); ?><input type="text" autocomplete="off" name="law_attendees[0][job_title]"></label>
					</div>
				</div>
				<p class="law-booking-note"><?php esc_html_e( 'If they do not already have an account, one is created for them and they are emailed an invitation with the event details.', 'law' ); ?></p>
				<p class="law-modal__actions law-booking-actions-start">
					<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Adding…', 'law' ); ?>"><?php esc_html_e( 'Add attendee', 'law' ); ?></button>
				</p>
			</form>
		<?php endif; ?>

		<?php if ( $law_bm_is_owner && ! $law_bm_cancelled ) : ?>
			<h3 class="law-booking-manage__subtitle"><?php esc_html_e( 'Cancel this booking', 'law' ); ?></h3>
			<form class="law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="law_booking_cancel">
				<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_bm_id ); ?>">
				<?php wp_nonce_field( 'law_booking_cancel' ); ?>
				<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>
				<button type="submit" class="button alert" data-law-modal-open="law-modal-cancel-booking"><?php esc_html_e( 'Cancel booking', 'law' ); ?></button>
				<?php
				get_template_part(
					'parts/layout/modal',
					null,
					array(
						'id'      => 'law-modal-cancel-booking',
						'title'   => __( 'Cancel this booking', 'law' ),
						'copy'    => sprintf( __( 'This cancels the place of everyone on this booking (%s). Each person is emailed to let them know.', 'law' ), $law_bm_names ),
						'confirm' => array(
							'label' => __( 'Cancel booking', 'law' ),
							'class' => 'button alert',
							'busy'  => __( 'Cancelling…', 'law' ),
						),
						'close'   => __( 'Keep the booking', 'law' ),
					)
				);
				?>
			</form>
		<?php endif; ?>

	</div>
</div>
