<?php
/**
 * The per-event bookings list for hosts, co-owners and the committee
 * (?law_event_bookings=<event id> on /account/events/, WAITLIST.md §A5).
 * Access is law_user_can_manage_event(), re-checked here.
 *
 * A flat table in the committee-dashboard idiom: ONE ROW PER BOOKING, which
 * since the per-attendee rebuild is one row per person, with no grouping by
 * party (Denis, 8 September 2026). A colleague someone brought is marked with
 * a small "Invited by {name}" tag on their own row, and that is all. The
 * dietary and accessibility columns (read live from each attendee's profile)
 * wrap rather than inheriting the table's nowrap, and the wrap scrolls
 * sideways on mobile like every other dashboard table. The waitlist sits in
 * its own section with the reorder and promote controls; cancelled bookings
 * sit collapsed at the bottom, read-only, with the cancelled badge.
 *
 * Cancelling somebody else's booking (and removing a waitlist entry, which is
 * the same act on a waitlisted booking) is COMMITTEE ONLY since 11 September
 * 2026, law_booking_user_can_reject(). A host manages the queue order, promotes
 * and registers; taking a place away from an attendee is LAW's call. The
 * controls are simply absent for a host, with no note in their place, so the
 * active table loses its actions column altogether and the waitlist table keeps
 * one holding Promote now.
 *
 * "Register an attendee" (a host, co-owner or committee member booking someone
 * on their behalf) is a BUTTON ABOVE THE TABLE opening a dialog, not a form
 * sitting open at the foot of the page (Denis, 11 September 2026), the way
 * "Add an attendee without payment" works on the flagship bookings dashboard
 * (parts/events/flagship-add-attendee.php). One partial serves both audiences,
 * so the host and the committee get the same dialog. Without JavaScript the
 * opener stays hidden and a <noscript> disclosure carries exactly the same
 * form, so the feature never depends on the script.
 *
 * That dialog asks for almost what the registration form asks for: the four
 * attendee fields plus country, accessibility and dietary
 * (parts/events/attendee-profile-fields.php), because the table's own last
 * three columns read those live from each attendee's profile and somebody
 * booked in by phone has nobody else to fill them in.
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
$law_bl_wait_cap  = 200;
$law_bl_waitlist  = function_exists( 'law_waitlist_for_event' ) ? law_waitlist_for_event( $law_bl_event_id, $law_bl_wait_cap ) : array();
$law_bl_cancelled = law_bookings_for_event( $law_bl_event_id, 'law-cancelled' );

// One users + one usermeta query for the whole table instead of two per row
// (the country/dietary/accessibility columns read each attendee's profile, and
// the "Invited by" tag resolves the booker).
$law_bl_user_ids = array();
foreach ( array_merge( $law_bl_active, $law_bl_waitlist, $law_bl_cancelled ) as $law_bl_prime ) {
	$law_bl_user_ids[] = (int) $law_bl_prime->post_author;
	$law_bl_user_ids[] = (int) law_event_meta( $law_bl_prime->ID, '_law_booked_by' );
}
$law_bl_user_ids = array_values( array_unique( array_filter( $law_bl_user_ids ) ) );
if ( $law_bl_user_ids ) {
	cache_users( $law_bl_user_ids );
}
$law_bl_total     = law_event_attendee_total( $law_bl_event_id );
$law_bl_available = (int) law_event_meta( $law_bl_event_id, '_law_tickets_available' );
$law_bl_over      = $law_bl_available > 0 && $law_bl_total > $law_bl_available;

$law_bl_export_base = wp_nonce_url( admin_url( 'admin-post.php?action=law_booking_export&event_id=' . $law_bl_event_id ), 'law_booking_export' );

// Registering someone on their behalf (phone/email requests, VIPs, press) is
// offered while the event is open with places left; the engine refuses
// otherwise, so a full or started event just hides the form. The press flag
// is committee-only (spec §6.4: press passes are issued by LAW admin).
$law_bl_can_register = true === law_booking_guard_open( $law_bl_event_id ) && 0 !== law_event_tickets_remaining( $law_bl_event_id );
$law_bl_is_committee = law_user_is_committee();
$law_bl_form_state   = law_booking_form_state();
$law_bl_typed        = (array) ( $law_bl_form_state['rows'][0] ?? array() );
$law_bl_register_modal = 'law-booking-register';

/**
 * The four attendee fields, printed into BOTH the dialog and the no-JS
 * disclosure below it. Labels wrap their inputs rather than using for/id: the
 * same fields appear twice on the page and ids would collide, silently
 * breaking both labels rather than one. The .law-rows wrapper is what
 * booking-form.js walks to mark a refused field in place.
 */
$law_bl_register_fields = function ( $law_bl_prefix, $law_bl_show_other = false ) use ( $law_bl_form_state, $law_bl_typed, $law_bl_is_committee ) {
	?>
	<div class="law-rows" data-law-booking-rows="law_attendees" data-law-max="1">
		<div class="law-row">
			<div class="law-row-grid law-row-grid--attendee">
				<?php
				$law_bl_fields = array(
					'name'         => array( __( 'Full name *', 'law' ), 'text' ),
					'email'        => array( __( 'Email *', 'law' ), 'email' ),
					'organisation' => array( __( 'Organisation *', 'law' ), 'text' ),
					'job_title'    => array( __( 'Job title *', 'law' ), 'text' ),
				);
				foreach ( $law_bl_fields as $law_bl_key => $law_bl_field ) :
					$law_bl_invalid = 0 === (int) $law_bl_form_state['row'] && $law_bl_form_state['field'] === $law_bl_key;
					?>
					<label<?php echo $law_bl_invalid ? ' class="is-invalid"' : ''; ?>><?php echo esc_html( $law_bl_field[0] ); ?><input type="<?php echo esc_attr( $law_bl_field[1] ); ?>" autocomplete="off" aria-required="true" name="law_attendees[0][<?php echo esc_attr( $law_bl_key ); ?>]" value="<?php echo esc_attr( (string) ( $law_bl_typed[ $law_bl_key ] ?? '' ) ); ?>"></label>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
	<?php
	// Country, accessibility and dietary, exactly as registration asks for
	// them: the table's own columns read these live from the attendee's
	// profile, and somebody booked in by phone has nobody else to fill them in.
	get_template_part(
		'parts/events/attendee-profile-fields',
		null,
		array(
			'id_prefix'        => $law_bl_prefix,
			'values'           => (array) ( $law_bl_form_state['profile'] ?? array() ),
			'show_other'       => $law_bl_show_other,
			'country_required' => true,
			'note'             => __( 'Whatever they told you. They can change any of this themselves from their profile.', 'law' ),
		)
	);
	?>
	<?php if ( $law_bl_is_committee ) : ?>
		<div class="law-form-field">
			<div class="law-choices">
				<label><input type="checkbox" name="law_press" value="1"> <?php esc_html_e( 'Press pass (marked as press on the attendee list and exports)', 'law' ); ?></label>
			</div>
		</div>
	<?php endif; ?>
	<?php
};

/** The hidden inputs every copy of the form needs. */
$law_bl_register_hidden = function () use ( $law_bl_event_id ) {
	?>
	<input type="hidden" name="action" value="law_booking_register_attendee">
	<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $law_bl_event_id ); ?>">
	<?php wp_nonce_field( 'law_booking_register_attendee' ); ?>
	<?php law_events_honeypot_field(); ?>
	<?php
};

/**
 * One flat table over a set of bookings: one row per booking, which is one row
 * per attendee (Denis, 8 September 2026 — no grouping by party; an additional
 * attendee's row simply carries an "Invited by {name}" tag).
 *
 * @param WP_Post[] $law_bl_set        Bookings to render.
 * @param bool      $law_bl_actionable Whether this set can be acted on at all
 *                                     (false for the cancelled table).
 * @param bool      $law_bl_waiting    Waitlist mode: a Position column and the
 *                                     reorder / promote controls.
 */
$law_bl_render_table = function ( array $law_bl_set, $law_bl_actionable, $law_bl_waiting = false ) use ( $law_bl_event_id, $law_bl_is_committee ) {
	$law_bl_last = count( $law_bl_set ) - 1;
	// Cancel is committee only; Promote now is not, so the waitlist keeps its
	// actions column for a host while the active table drops it entirely.
	$law_bl_can_cancel  = $law_bl_actionable && $law_bl_is_committee;
	$law_bl_has_actions = $law_bl_can_cancel || ( $law_bl_actionable && $law_bl_waiting );
	?>
	<div class="law-dashboard__table-wrap">
		<table class="law-dashboard__table law-booking-table">
			<thead><tr>
				<?php if ( $law_bl_waiting ) : ?><th><?php esc_html_e( 'Position', 'law' ); ?></th><?php endif; ?>
				<th><?php esc_html_e( 'Booking', 'law' ); ?></th>
				<th><?php esc_html_e( 'Attendee', 'law' ); ?></th>
				<th><?php esc_html_e( 'Email', 'law' ); ?></th>
				<th><?php esc_html_e( 'Organisation', 'law' ); ?></th>
				<th><?php esc_html_e( 'Job title', 'law' ); ?></th>
				<th><?php esc_html_e( 'Country', 'law' ); ?></th>
				<th><?php esc_html_e( 'Accessibility', 'law' ); ?></th>
				<th><?php esc_html_e( 'Dietary', 'law' ); ?></th>
				<?php if ( $law_bl_has_actions ) : ?><th></th><?php endif; ?>
			</tr></thead>
			<tbody<?php echo $law_bl_waiting ? ' data-law-waitlist' : ''; ?>>
			<?php foreach ( array_values( $law_bl_set ) as $law_bl_i => $law_bl_booking ) :
				$law_bl_number     = (int) law_event_meta( $law_bl_booking->ID, '_law_booking_number' );
				$law_bl_person     = law_booking_attendee( $law_bl_booking );
				$law_bl_invited_by = law_booking_invited_by_label( $law_bl_booking );
				$law_bl_profile    = law_profile_values( (int) $law_bl_booking->post_author );
				$law_bl_access     = law_booking_profile_requirements( $law_bl_profile, 'accessibility' );
				$law_bl_diet       = law_booking_profile_requirements( $law_bl_profile, 'dietary' );
				$law_bl_modal      = 'law-modal-reject-' . $law_bl_booking->ID;
				$law_bl_position   = (int) law_event_meta( $law_bl_booking->ID, '_law_waitlist_position' );
				?>
				<tr<?php echo $law_bl_waiting ? ' data-law-waitlist-row="' . esc_attr( (string) $law_bl_booking->ID ) . '"' : ''; ?>>
					<?php if ( $law_bl_waiting ) : ?>
						<td class="law-booking-table__position">
							<strong><?php echo esc_html( number_format_i18n( $law_bl_i + 1 ) ); ?></strong>
							<?php
							// Glyphs with an aria-label rather than three words of
							// button text: five controls already share this row.
							$law_bl_moves = array(
								'top'  => array( '⤒', __( 'Move %s to the top of the waitlist', 'law' ), 0 === $law_bl_i ),
								'up'   => array( '↑', __( 'Move %s up the waitlist', 'law' ), 0 === $law_bl_i ),
								'down' => array( '↓', __( 'Move %s down the waitlist', 'law' ), $law_bl_i === $law_bl_last ),
							);
							foreach ( $law_bl_moves as $law_bl_dir => $law_bl_move ) :
								// One form per direction: booking-form.js posts
								// new FormData(form), which omits the submitter,
								// so the direction has to be a hidden input.
								?>
								<form class="law-booking-form law-booking-manage__action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-law-waitlist-move="<?php echo esc_attr( $law_bl_dir ); ?>">
									<input type="hidden" name="action" value="law_waitlist_reorder">
									<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_bl_booking->ID ); ?>">
									<input type="hidden" name="direction" value="<?php echo esc_attr( $law_bl_dir ); ?>">
									<input type="hidden" name="expected_position" value="<?php echo esc_attr( (string) $law_bl_position ); ?>">
									<?php wp_nonce_field( 'law_waitlist_reorder' ); ?>
									<?php law_events_honeypot_field(); ?>
									<button type="submit" class="button second law-booking-table__move" data-law-booking-busy-quiet<?php echo $law_bl_move[2] ? ' disabled' : ''; ?> aria-label="<?php echo esc_attr( sprintf( $law_bl_move[1], $law_bl_person['name'] ) ); ?>">
										<span aria-hidden="true"><?php echo esc_html( $law_bl_move[0] ); ?></span>
									</button>
								</form>
							<?php endforeach; ?>
						</td>
					<?php endif; ?>
					<td class="law-booking-table__booking">
						<strong>#<?php echo esc_html( (string) $law_bl_number ); ?></strong>
						<?php if ( ! $law_bl_actionable && ! $law_bl_waiting ) : ?>
							<br><span class="law-cal-card__badge law-cal-card__badge--cancelled"><?php esc_html_e( 'Cancelled', 'law' ); ?></span>
						<?php endif; ?>
					</td>
					<td><strong><?php echo esc_html( $law_bl_person['name'] ); ?></strong><?php
						if ( '' !== $law_bl_invited_by ) {
							echo ' <span class="law-cal-card__badge law-booking-table__invited">' . esc_html( sprintf( __( 'Invited by %s', 'law' ), $law_bl_invited_by ) ) . '</span>';
						}
						if ( law_event_meta( $law_bl_booking->ID, '_law_is_press' ) ) {
							echo ' <span class="law-cal-card__badge law-booking-table__press">' . esc_html__( 'Press', 'law' ) . '</span>';
						}
					?></td>
					<td><?php echo esc_html( $law_bl_person['email'] ); ?></td>
					<td><?php echo esc_html( $law_bl_person['organisation'] ?: '—' ); ?></td>
					<td><?php echo esc_html( $law_bl_person['job_title'] ?: '—' ); ?></td>
					<td><?php echo esc_html( (string) ( $law_bl_profile['country'] ?? '' ) ?: '—' ); ?></td>
					<td class="law-booking-table__req"><?php echo esc_html( $law_bl_access ?: '—' ); ?></td>
					<td class="law-booking-table__req"><?php echo esc_html( $law_bl_diet ?: '—' ); ?></td>
					<?php if ( $law_bl_has_actions ) : ?>
						<td class="law-dashboard__row-actions">
							<?php if ( $law_bl_waiting ) :
								$law_bl_remaining  = law_event_tickets_remaining( $law_bl_event_id );
								$law_bl_full       = 0 === $law_bl_remaining;
								$law_bl_over_by    = $law_bl_available > 0 ? max( 0, $law_bl_total + 1 - $law_bl_available ) : 0;
								$law_bl_promote_id = 'law-modal-promote-' . $law_bl_booking->ID;
								?>
								<form class="law-booking-form law-booking-manage__action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<input type="hidden" name="action" value="law_waitlist_promote">
									<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_bl_booking->ID ); ?>">
									<?php wp_nonce_field( 'law_waitlist_promote' ); ?>
									<?php law_events_honeypot_field(); ?>
									<button type="submit" class="button orange" data-law-modal-open="<?php echo esc_attr( $law_bl_promote_id ); ?>"><?php esc_html_e( 'Promote now', 'law' ); ?></button>
									<?php
									get_template_part(
										'parts/layout/modal',
										null,
										array(
											'id'      => $law_bl_promote_id,
											'title'   => sprintf( __( 'Promote %s', 'law' ), $law_bl_person['name'] ),
											'copy'    => array(
												sprintf( __( 'This registers %s onto the event now, ahead of the waitlist order, and emails them their confirmation.', 'law' ), $law_bl_person['name'] ),
												$law_bl_full
													? sprintf(
														_n(
															'This event is already full, so promoting this entry will over-book it by %d place.',
															'This event is already full, so promoting this entry will over-book it by %d places.',
															max( 1, $law_bl_over_by ),
															'law'
														),
														max( 1, $law_bl_over_by )
													)
													: '',
											),
											'confirm' => array( 'label' => __( 'Promote now', 'law' ), 'class' => 'button orange', 'busy' => __( 'Promoting…', 'law' ) ),
											'close'   => __( 'Keep on the waitlist', 'law' ),
										)
									);
									?>
								</form>
							<?php endif; ?>
							<?php if ( $law_bl_can_cancel ) : ?>
							<form class="law-booking-form law-booking-manage__action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
								<input type="hidden" name="action" value="law_booking_reject_attendee">
								<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_bl_booking->ID ); ?>">
								<?php wp_nonce_field( 'law_booking_reject_attendee' ); ?>
								<?php law_events_honeypot_field(); ?>
								<?php // Hollow, so the destructive action does not pull as hard as Promote now. ?>
								<button type="submit" class="button alert hollow" data-law-modal-open="<?php echo esc_attr( $law_bl_modal ); ?>"><?php echo esc_html( $law_bl_waiting ? __( 'Remove', 'law' ) : __( 'Cancel', 'law' ) ); ?></button>
								<?php
								get_template_part(
									'parts/layout/modal',
									null,
									array(
										'id'      => $law_bl_modal,
										'title'   => $law_bl_waiting
											? sprintf( __( 'Remove %s from the waitlist', 'law' ), $law_bl_person['name'] )
											: sprintf( __( 'Cancel the booking for %s', 'law' ), $law_bl_person['name'] ),
										'copy'    => $law_bl_waiting
											? sprintf( __( 'This takes %s off the waitlist. They are emailed to let them know.', 'law' ), $law_bl_person['name'] )
											: sprintf( __( 'This cancels the booking for %s and frees their place. They are emailed to let them know.', 'law' ), $law_bl_person['name'] ),
										'field'   => array(
											'name'     => 'law_reject_reason',
											'label'    => $law_bl_waiting
												? __( 'Why are they being taken off the waitlist? (optional)', 'law' )
												: __( 'Why is the place being cancelled? (optional)', 'law' ),
											'help'     => __( 'Included in the email to the attendee.', 'law' ),
											'rows'     => 3,
											'required' => false,
										),
										'confirm' => array(
											'label' => $law_bl_waiting ? __( 'Remove from waitlist', 'law' ) : __( 'Cancel booking', 'law' ),
											'class' => 'button alert',
											'busy'  => $law_bl_waiting ? __( 'Removing…', 'law' ) : __( 'Cancelling…', 'law' ),
										),
										'close'   => __( 'Keep this attendee', 'law' ),
									)
								);
								?>
							</form>
							<?php endif; ?>
						</td>
					<?php endif; ?>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<?php
};
?>

<div class="grid-x grid-padding-x">
	<div class="large-12 cell">

		<?php law_booking_notice_render(); ?>

		<h2 class="law-booking-manage__title"><?php echo esc_html( sprintf( __( 'Bookings: %s', 'law' ), $law_bl_event->post_title ) ); ?></h2>
		<p class="law-booking-substate">
			<?php
			// One booking is one attendee now, so one plural selector does.
			echo esc_html( sprintf( _n( '%s attendee.', '%s attendees.', $law_bl_total, 'law' ), number_format_i18n( $law_bl_total ) ) );
			// Places left, not "N of M taken": the attendee count above already
			// states how many are in, so repeating it buys nothing (Denis,
			// 11 September 2026). Over-booked events say so instead, since
			// "0 places left" would hide the excess.
			if ( $law_bl_available > 0 ) {
				$law_bl_left = max( 0, $law_bl_available - $law_bl_total );
				printf(
					' <span class="%s">%s</span>',
					$law_bl_over ? 'law-booking-table__over' : '',
					esc_html(
						$law_bl_over
							? sprintf(
								/* translators: %s: the number of attendees over capacity. */
								__( 'Over-booked by %s.', 'law' ),
								number_format_i18n( $law_bl_total - $law_bl_available )
							)
							: sprintf( _n( '%s place left.', '%s places left.', $law_bl_left, 'law' ), number_format_i18n( $law_bl_left ) )
					)
				);
			}
			if ( $law_bl_waitlist ) {
				printf(
					' <a href="#law-waitlist">%s</a>',
					esc_html( sprintf(
						_n( '%s person on the waitlist.', '%s people on the waitlist.', count( $law_bl_waitlist ), 'law' ),
						number_format_i18n( count( $law_bl_waitlist ) )
					) )
				);
			}
			?>
		</p>

		<?php if ( $law_bl_can_register ) : ?>
			<?php
			// Above the table, as a dialog rather than a form sitting open at the
			// foot of the page (Denis, 11 September 2026), the way "Add an
			// attendee without payment" works on the flagship bookings dashboard.
			// The page's subject is the attendee list; registering somebody on
			// their behalf is an occasional, deliberate act, and the opener
			// belongs with the other things you can do TO the list.
			//
			// The opener ships hidden and law-modal.js reveals it once the dialog
			// it names is on the page, so a browser without JavaScript is never
			// shown a button that cannot open anything: it gets the <noscript>
			// disclosure below instead, which holds exactly the same form.
			?>
			<p class="law-booking-register__actions">
				<button type="button" class="button orange law-booking-register__opener"
					data-law-modal-open="<?php echo esc_attr( $law_bl_register_modal ); ?>"
					data-law-modal-enhanced hidden>
					<?php esc_html_e( 'Register an attendee', 'law' ); ?>
				</button>
			</p>

			<div class="law-modal" id="<?php echo esc_attr( $law_bl_register_modal ); ?>" hidden>
				<div class="law-modal__overlay" data-law-modal-close></div>
				<div class="law-modal__dialog law-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="law-booking-register-title" tabindex="-1">
					<button type="button" class="law-modal__close" data-law-modal-close aria-label="<?php esc_attr_e( 'Close', 'law' ); ?>">&times;</button>
					<h2 class="law-modal__title" id="law-booking-register-title"><?php esc_html_e( 'Register an attendee', 'law' ); ?></h2>

					<form class="law-event-form law-event-form--light law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php $law_bl_register_hidden(); ?>
						<p class="law-modal__copy"><?php esc_html_e( 'For requests that arrive by phone or email. The person gets a booking of their own: they are emailed a confirmation (with a link to set a password if they have no account yet) and can manage or cancel it from My bookings.', 'law' ); ?></p>
						<?php $law_bl_register_fields( 'law-bl-register' ); ?>
						<p class="law-modal__actions">
							<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Cancel', 'law' ); ?></button>
							<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Registering…', 'law' ); ?>"><?php esc_html_e( 'Register attendee', 'law' ); ?></button>
						</p>
					</form>
				</div>
			</div>

			<?php
			// The no-JS path: the same form behind a native disclosure, open
			// already when the last submission was refused so the message and the
			// typed values are not hidden a click away.
			?>
			<noscript>
				<details class="law-booking-register__fallback"<?php echo '' !== (string) $law_bl_form_state['message'] ? ' open' : ''; ?>>
					<summary><?php esc_html_e( 'Register an attendee', 'law' ); ?></summary>
					<p class="law-booking-note"><?php esc_html_e( 'For requests that arrive by phone or email. The person gets a booking of their own: they are emailed a confirmation (with a link to set a password if they have no account yet) and can manage or cancel it from My bookings.', 'law' ); ?></p>
					<?php if ( '' !== (string) $law_bl_form_state['message'] ) : ?>
						<p class="law-form-notice is-error" role="alert"><?php echo esc_html( (string) $law_bl_form_state['message'] ); ?></p>
					<?php endif; ?>
					<form class="law-event-form law-event-form--light" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php $law_bl_register_hidden(); ?>
						<?php $law_bl_register_fields( 'law-bl-register-nojs', true ); ?>
						<p class="law-modal__actions law-booking-actions-start">
							<button type="submit" class="button orange"><?php esc_html_e( 'Register attendee', 'law' ); ?></button>
						</p>
					</form>
				</details>
			</noscript>
		<?php endif; ?>

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

		<?php if ( $law_bl_waitlist ) : ?>
			<h3 class="law-booking-manage__subtitle" id="law-waitlist"><?php echo esc_html( sprintf( __( 'Waitlist (%s)', 'law' ), number_format_i18n( count( $law_bl_waitlist ) ) ) ); ?></h3>
			<p class="law-booking-substate"><?php esc_html_e( 'Entries are promoted in this order, automatically, as places open up. Move an entry to change the order, or promote it now to register it regardless of places.', 'law' ); ?></p>
			<?php if ( count( $law_bl_waitlist ) >= $law_bl_wait_cap ) : ?>
				<p class="law-bookings-dashboard__truncated" role="status"><?php echo esc_html( sprintf( __( 'Showing the first %s entries. The rest are still queued and are promoted in turn.', 'law' ), number_format_i18n( $law_bl_wait_cap ) ) ); ?></p>
			<?php endif; ?>
			<?php $law_bl_render_table( $law_bl_waitlist, true, true ); ?>
		<?php endif; ?>

		<?php if ( $law_bl_cancelled ) : ?>
			<details class="law-booking-cancelled">
				<summary><?php echo esc_html( sprintf( _n( '%d cancelled booking', '%d cancelled bookings', count( $law_bl_cancelled ), 'law' ), count( $law_bl_cancelled ) ) ); ?></summary>
				<?php $law_bl_render_table( $law_bl_cancelled, false ); ?>
			</details>
		<?php endif; ?>

	</div>
</div>
