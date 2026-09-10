<?php
/**
 * The manage view (?law_booking=<id> on /account/events/, WAITLIST.md §A5).
 *
 * One booking per attendee, so the subject here is really (this user, this
 * event): the addressed booking resolves the event, and the view then shows
 * the user's whole party there — their own place plus the colleagues they
 * booked. A colleague opening their own booking sees only their own row.
 * Access is re-checked here, not trusted from the caller (the thread partial
 * sets the precedent).
 *
 * Every action is a form-shaped POST behind a parts/layout/modal confirm,
 * submitted over fetch by booking-form.js with the plain-POST fallback.
 *
 * Args: booking_id.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_bm_id      = absint( $args['booking_id'] ?? 0 );
$law_bm_booking = get_post( $law_bm_id );
$law_bm_user    = wp_get_current_user();

$law_bm_is_self   = $law_bm_booking && (int) $law_bm_booking->post_author === (int) $law_bm_user->ID;
$law_bm_is_booker = $law_bm_booking && (int) law_event_meta( $law_bm_id, '_law_booked_by' ) === (int) $law_bm_user->ID;

if ( ! $law_bm_booking || LAW_BOOKING_CPT !== $law_bm_booking->post_type || ( ! $law_bm_is_self && ! $law_bm_is_booker ) ) {
	echo '<p class="law-cal__empty">' . esc_html__( 'Sorry, this booking is not yours to view.', 'law' ) . '</p>';
	return;
}

// A flagship place is applied for, reviewed and charged, so it has its own
// view: a card on file, a payment that can fail, a withdrawal rather than a
// cancellation, and no colleagues at all. Handed off here rather than
// branched through the whole partial below, which is written around a party.
if ( function_exists( 'law_flagship_booking_is' ) && law_flagship_booking_is( $law_bm_booking ) ) {
	get_template_part( 'parts/events/flagship-manage-application', null, array( 'booking_id' => $law_bm_id ) );
	return;
}

$law_bm_event_id  = (int) $law_bm_booking->post_parent;
$law_bm_event     = get_post( $law_bm_event_id );
$law_bm_cancelled = 'law-cancelled' === $law_bm_booking->post_status;

// The user's live party on this event. A colleague viewing their own booking
// is not a booker, so their "party" is just themselves.
$law_bm_party = law_booking_party( $law_bm_event_id, (int) $law_bm_user->ID, law_booking_holding_statuses() );
if ( ! $law_bm_party && $law_bm_cancelled ) {
	$law_bm_party = array( $law_bm_booking ); // Show the cancelled row rather than an empty page.
}
$law_bm_own = null;
foreach ( $law_bm_party as $law_bm_entry ) {
	if ( (int) $law_bm_entry->post_author === (int) $law_bm_user->ID ) {
		$law_bm_own = $law_bm_entry;
		break;
	}
}
$law_bm_colleagues = count( $law_bm_party ) - ( $law_bm_own ? 1 : 0 );
$law_bm_waitlisted = $law_bm_own && 'law-waitlisted' === $law_bm_own->post_status;
$law_bm_invited_by = $law_bm_own ? law_booking_invited_by_label( $law_bm_own ) : '';

// Adding is possible while the user still has a place here, the colleague cap
// has room and the event is open for booking with places actually left — a
// full event hides the form rather than inviting a refusal. A waitlisted party
// cannot grow: joining again is the route, so everyone keeps a fair position.
$law_bm_can_add = $law_bm_party
	&& ! $law_bm_waitlisted
	&& law_booking_colleague_count( $law_bm_event_id, (int) $law_bm_user->ID ) < law_booking_max_additional()
	&& true === law_booking_guard_open( $law_bm_event_id )
	&& 0 !== law_event_tickets_remaining( $law_bm_event_id );

$law_bm_names = array();
foreach ( $law_bm_party as $law_bm_entry ) {
	$law_bm_person  = law_booking_attendee( $law_bm_entry );
	$law_bm_names[] = $law_bm_person['name'] ?: $law_bm_person['email'];
}
$law_bm_names = implode( ', ', array_filter( $law_bm_names ) );

$law_bm_start = (string) law_event_meta( $law_bm_event_id, '_law_start' );
$law_bm_when  = '' !== $law_bm_start
	? date_i18n( 'l j F Y', strtotime( $law_bm_start ) ) . ', ' . substr( $law_bm_start, 11, 5 )
	: '';
$law_bm_venue = (string) law_event_meta( $law_bm_event_id, '_law_venue' );
?>

<div class="grid-x grid-padding-x">
	<div class="large-8 cell">

		<?php law_booking_notice_render(); ?>

		<h2 class="law-booking-manage__title">
			<?php
			echo esc_html(
				$law_bm_colleagues
					? sprintf( __( 'Your bookings for %s', 'law' ), $law_bm_event ? $law_bm_event->post_title : __( 'this event', 'law' ) )
					: sprintf( __( 'Booking #%d', 'law' ), (int) law_event_meta( $law_bm_id, '_law_booking_number' ) )
			);
			?>
			<?php if ( $law_bm_cancelled && ! $law_bm_party ) : ?>
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

		<?php if ( $law_bm_waitlisted ) : ?>
			<p class="law-booking-state"><?php esc_html_e( "You're on the waitlist for this event.", 'law' ); ?></p>
			<p class="law-booking-substate"><?php esc_html_e( "We'll email you as soon as a place opens up, and your booking is confirmed automatically.", 'law' ); ?></p>
		<?php elseif ( '' !== $law_bm_invited_by ) : ?>
			<p class="law-booking-substate"><?php echo esc_html( sprintf( __( 'Invited by %s.', 'law' ), $law_bm_invited_by ) ); ?></p>
		<?php endif; ?>

		<h3 class="law-booking-manage__subtitle"><?php echo esc_html( $law_bm_colleagues ? __( 'Who is coming', 'law' ) : __( 'Your place', 'law' ) ); ?></h3>
		<ul class="law-dashboard__people law-booking-manage__people">
			<?php foreach ( $law_bm_party as $law_bm_entry ) :
				$law_bm_entry_id   = (int) $law_bm_entry->ID;
				$law_bm_person     = law_booking_attendee( $law_bm_entry );
				$law_bm_row_name   = $law_bm_person['name'] ?: $law_bm_person['email'];
				$law_bm_row_self   = (int) $law_bm_entry->post_author === (int) $law_bm_user->ID;
				$law_bm_row_wait   = 'law-waitlisted' === $law_bm_entry->post_status;
				$law_bm_row_gone   = 'law-cancelled' === $law_bm_entry->post_status;
				$law_bm_facts      = array_filter( array( $law_bm_person['organisation'], $law_bm_person['job_title'] ) );
				$law_bm_modal_id   = 'law-modal-cancel-' . $law_bm_entry_id;
				$law_bm_row_number = (int) law_event_meta( $law_bm_entry_id, '_law_booking_number' );
				// A row badge only says something when the party is mixed: on an
				// all-waiting entry the state paragraph above has said it once.
				$law_bm_show_badge = $law_bm_row_gone || ( $law_bm_row_wait && ! $law_bm_waitlisted );

				// The confirm copy per context.
				if ( $law_bm_row_wait && $law_bm_row_self ) {
					$law_bm_copy = __( 'You come off the waitlist for this event. Anyone else you added keeps their place in the queue.', 'law' );
				} elseif ( $law_bm_row_wait ) {
					$law_bm_copy = sprintf( __( 'This takes %s off the waitlist for this event. They are emailed to let them know.', 'law' ), $law_bm_row_name );
				} elseif ( $law_bm_row_self && $law_bm_colleagues ) {
					$law_bm_copy = __( 'Your place is freed for someone else. Your colleagues keep theirs and you can still manage their bookings here.', 'law' );
				} elseif ( $law_bm_row_self ) {
					$law_bm_copy = __( 'Your place is freed for someone else. You can book again later if places are still available.', 'law' );
				} else {
					$law_bm_copy = sprintf( __( 'This cancels the booking for %s and frees their place. They are emailed to let them know.', 'law' ), $law_bm_row_name );
				}
				?>
				<li>
					<span class="law-booking-manage__person">
						<strong><?php echo esc_html( $law_bm_row_name ); ?></strong>
						<?php if ( $law_bm_row_self ) : ?>
							<span class="law-booking-manage__owner-flag"><?php esc_html_e( '(you)', 'law' ); ?></span>
						<?php endif; ?>
						<span class="law-booking-manage__number"><?php echo esc_html( sprintf( __( 'Booking #%d', 'law' ), $law_bm_row_number ) ); ?></span>
						<?php if ( $law_bm_show_badge && $law_bm_row_wait ) : ?>
							<span class="law-cal-card__badge law-cal-card__badge--waitlisted"><?php esc_html_e( 'Waitlisted', 'law' ); ?></span>
						<?php elseif ( $law_bm_show_badge ) : ?>
							<span class="law-cal-card__badge law-cal-card__badge--cancelled"><?php esc_html_e( 'Cancelled', 'law' ); ?></span>
						<?php endif; ?>
						<br><?php echo esc_html( $law_bm_person['email'] . ( $law_bm_facts ? ' · ' . implode( ', ', $law_bm_facts ) : '' ) ); ?>
					</span>
					<?php if ( ! $law_bm_row_gone ) : ?>
						<form class="law-booking-form law-booking-manage__action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="law_booking_cancel">
							<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_bm_entry_id ); ?>">
							<?php wp_nonce_field( 'law_booking_cancel' ); ?>
							<?php law_events_honeypot_field(); ?>
							<?php
							$law_bm_action_label = $law_bm_row_wait
								? ( $law_bm_row_self ? __( 'Leave the waitlist', 'law' ) : __( 'Remove from the waitlist', 'law' ) )
								: ( $law_bm_row_self ? __( 'Cancel my booking', 'law' ) : __( 'Cancel this booking', 'law' ) );
							?>
							<button type="submit" class="button alert" data-law-modal-open="<?php echo esc_attr( $law_bm_modal_id ); ?>"><?php echo esc_html( $law_bm_action_label ); ?></button>
							<?php
							get_template_part(
								'parts/layout/modal',
								null,
								array(
									'id'      => $law_bm_modal_id,
									'title'   => $law_bm_action_label,
									'copy'    => $law_bm_copy,
									'confirm' => array(
										'label' => $law_bm_action_label,
										'class' => 'button alert',
										'busy'  => $law_bm_row_wait ? __( 'Leaving…', 'law' ) : __( 'Cancelling…', 'law' ),
									),
									'close'   => $law_bm_row_wait
										? ( $law_bm_row_self ? __( 'Stay on the waitlist', 'law' ) : __( 'Keep them on the waitlist', 'law' ) )
										: ( $law_bm_row_self ? __( 'Keep my booking', 'law' ) : __( 'Keep their booking', 'law' ) ),
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
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $law_bm_event_id ); ?>">
				<?php wp_nonce_field( 'law_booking_add_attendee' ); ?>
				<?php law_events_honeypot_field(); ?>
				<?php // The rows wrapper is what booking-form.js walks to mark a refused field in place. ?>
				<div class="law-rows" data-law-booking-rows="law_attendees">
					<div class="law-row">
						<div class="law-row-grid law-row-grid--attendee">
							<label><?php esc_html_e( 'Full name *', 'law' ); ?><input type="text" autocomplete="off" aria-required="true" name="law_attendees[0][name]"></label>
							<label><?php esc_html_e( 'Email *', 'law' ); ?><input type="email" autocomplete="off" aria-required="true" name="law_attendees[0][email]"></label>
							<label><?php esc_html_e( 'Organisation *', 'law' ); ?><input type="text" autocomplete="off" aria-required="true" name="law_attendees[0][organisation]"></label>
							<label><?php esc_html_e( 'Job title *', 'law' ); ?><input type="text" autocomplete="off" aria-required="true" name="law_attendees[0][job_title]"></label>
						</div>
					</div>
				</div>
				<p class="law-booking-note"><?php esc_html_e( 'They get a booking of their own, with their own booking number. If they do not already have an account, one is created for them and they are emailed an invitation with the event details.', 'law' ); ?></p>
				<p class="law-modal__actions law-booking-actions-start">
					<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Adding…', 'law' ); ?>"><?php esc_html_e( 'Add colleague', 'law' ); ?></button>
				</p>
			</form>
		<?php endif; ?>

		<?php if ( $law_bm_colleagues && count( $law_bm_party ) > 1 ) : ?>
			<h3 class="law-booking-manage__subtitle"><?php echo esc_html( $law_bm_waitlisted ? __( 'Leave the waitlist', 'law' ) : __( 'Cancel all bookings', 'law' ) ); ?></h3>
			<form class="law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="law_booking_cancel_party">
				<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $law_bm_event_id ); ?>">
				<?php wp_nonce_field( 'law_booking_cancel_party' ); ?>
				<?php law_events_honeypot_field(); ?>
				<?php
				$law_bm_all_label = $law_bm_waitlisted ? __( 'Leave the waitlist for everyone', 'law' ) : __( 'Cancel all bookings', 'law' );
				?>
				<button type="submit" class="button alert" data-law-modal-open="law-modal-cancel-party"><?php echo esc_html( $law_bm_all_label ); ?></button>
				<?php
				get_template_part(
					'parts/layout/modal',
					null,
					array(
						'id'      => 'law-modal-cancel-party',
						'title'   => $law_bm_all_label,
						'copy'    => $law_bm_waitlisted
							? sprintf( __( 'This takes you and everyone you added off the waitlist for this event (%s). Each person is emailed to let them know.', 'law' ), $law_bm_names )
							: sprintf( __( 'This cancels your own place and every booking you made for this event (%s). Each person is emailed to let them know.', 'law' ), $law_bm_names ),
						'confirm' => array(
							'label' => $law_bm_all_label,
							'class' => 'button alert',
							'busy'  => __( 'Cancelling…', 'law' ),
						),
						'close'   => $law_bm_waitlisted ? __( 'Stay on the waitlist', 'law' ) : __( 'Keep the bookings', 'law' ),
					)
				);
				?>
			</form>
		<?php endif; ?>

	</div>
</div>
