<?php
/**
 * The booking form for a single event (WAITLIST.md §A5, §B5), in two contexts:
 *
 * - 'modal': the .law-modal skeleton the Register button opens (law-modal.js
 *   supplies open/close/focus-trap behaviour off the shared classes; this is
 *   deliberately NOT parts/layout/modal.php, whose args are confirm-dialog
 *   shaped, single field). Hidden without JS — the opener is then a real link
 *   to the inline context below.
 * - 'inline': the same form rendered in the page (?law_book=1 or
 *   ?law_waitlist=1 on the event permalink), the no-JS path, repopulated from
 *   law_booking_form_state() after a refused submission.
 *
 * The 'waitlist' mode is the same form pointed at the waitlist: an event with
 * no places left still takes the booker plus up to three colleagues, each of
 * whom joins the queue in their own right. One partial, not two, because
 * everything but the labels, the action and the colleague cap is identical.
 *
 * Two states inside: logged out (sign in / create an attendee account, both
 * returning here) and the form. Any logged-in user can book; the attendee
 * role is granted by the engine.
 *
 * Args: event (the calendar-mapped array), context ('modal' | 'inline'),
 * mode ('book' | 'waitlist').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_bk_event = (array) ( $args['event'] ?? array() );
$law_bk_ctx   = 'inline' === ( $args['context'] ?? 'modal' ) ? 'inline' : 'modal';
$law_bk_wait  = 'waitlist' === ( $args['mode'] ?? 'book' );
$law_bk_id    = (int) ( $law_bk_event['id'] ?? 0 );
if ( ! $law_bk_id ) {
	return;
}

$law_bk_permalink = get_permalink( $law_bk_id );
$law_bk_remaining = law_event_tickets_remaining( $law_bk_id );
// Booking is capped by the places actually left; the waitlist is not, since
// nobody is taking a place yet.
// What is left of the colleague cap after any this person already brought
// here, so the form never offers a row the engine will refuse.
$law_bk_held     = is_user_logged_in() ? law_booking_colleague_count( $law_bk_id, get_current_user_id() ) : 0;
$law_bk_room     = max( 0, law_booking_max_additional() - $law_bk_held );
$law_bk_max      = $law_bk_wait
	? $law_bk_room
	: min( $law_bk_room, max( 0, (int) $law_bk_remaining - 1 ) );
$law_bk_action   = $law_bk_wait ? 'law_waitlist_join' : 'law_booking_create';
$law_bk_dialog   = $law_bk_wait ? 'law-waitlist-modal' : 'law-booking-modal';
$law_bk_success  = $law_bk_wait ? 'law-waitlist-success' : 'law-booking-success';
$law_bk_heading  = $law_bk_wait ? __( 'Join the waitlist', 'law' ) : __( 'Book your place', 'law' );
$law_bk_submit   = $law_bk_wait ? __( 'Join waitlist', 'law' ) : __( 'Register', 'law' );
$law_bk_busy     = $law_bk_wait ? __( 'Joining…', 'law' ) : __( 'Registering…', 'law' );
$law_bk_when      = trim(
	( $law_bk_event['date'] ? law_calendar_day_heading( $law_bk_event['date'] ) : '' )
	. ( $law_bk_event['time_label'] && 'Slot not confirmed' !== $law_bk_event['time_label'] ? ', ' . $law_bk_event['time_label'] : '' ),
	', '
);
$law_bk_state = 'inline' === $law_bk_ctx ? law_booking_form_state() : array( 'message' => '', 'row' => null, 'field' => '', 'rows' => array() );

// The inner content, built once and wrapped per context below.
ob_start();
if ( ! is_user_logged_in() ) :
	?>
	<p class="law-modal__copy"><?php echo esc_html(
		$law_bk_wait
			? __( 'You need an account to join the waitlist for a London Arbitration Week event. It only takes a minute, and you will come straight back to this event.', 'law' )
			: __( 'You need an account to book places at London Arbitration Week events. It only takes a minute, and you will come straight back to this event.', 'law' )
	); ?></p>
	<p class="law-modal__actions law-booking-auth">
		<a class="button second" href="<?php echo esc_url( wp_login_url( $law_bk_permalink ) ); ?>"><?php esc_html_e( 'Sign in', 'law' ); ?></a>
		<a class="button orange" href="<?php echo esc_url( add_query_arg( array( 'role' => 'attendee', 'redirect_to' => $law_bk_permalink ), home_url( '/register/' ) ) ); ?>"><?php esc_html_e( 'Create an account', 'law' ); ?></a>
	</p>
	<?php
else :
	?>
	<form class="law-event-form law-event-form--light law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-law-booking-success="<?php echo esc_attr( $law_bk_success ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $law_bk_action ); ?>">
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $law_bk_id ); ?>">
		<?php wp_nonce_field( $law_bk_action ); ?>
		<?php law_events_honeypot_field(); ?>

		<?php
		// What you are booking, as the highlighted .law-event-summary block
		// the flagship application uses, rather than two grey lines above the
		// form (Denis, 11 September 2026). The availability line is the fact
		// that governs the decision here, so it takes the ruled bottom row
		// that holds the price on the flagship: one block, two facts, read
		// separately.
		?>
		<div class="law-booking-summary law-event-summary">
			<p class="law-event-summary__title"><?php echo esc_html( (string) $law_bk_event['title'] ); ?></p>
			<?php if ( '' !== $law_bk_when ) : ?>
				<p class="law-event-summary__when"><?php echo esc_html( $law_bk_when ); ?></p>
			<?php endif; ?>
			<p class="law-event-summary__row">
				<span>
					<?php if ( $law_bk_wait ) : ?>
						<?php esc_html_e( 'This event is fully booked.', 'law' ); ?>
					<?php else : ?>
						<?php echo esc_html( sprintf( _n( '%s place left.', '%s places left.', (int) $law_bk_remaining, 'law' ), number_format_i18n( (int) $law_bk_remaining ) ) ); ?>
					<?php endif; ?>
					<?php if ( $law_bk_max > 0 ) : ?>
						<?php echo esc_html( sprintf( _n( 'You can bring up to %d colleague.', 'You can bring up to %d colleagues.', $law_bk_max, 'law' ), $law_bk_max ) ); ?>
					<?php endif; ?>
				</span>
			</p>
		</div>

		<?php if ( '' !== (string) $law_bk_state['message'] ) : ?>
			<p class="law-form-notice is-error" role="alert"><?php echo esc_html( (string) $law_bk_state['message'] ); ?></p>
		<?php endif; ?>

		<?php
		get_template_part(
			'parts/events/attendee-repeater',
			null,
			array(
				'rows'    => (array) $law_bk_state['rows'],
				'max'     => $law_bk_max,
				'invalid' => array( 'row' => $law_bk_state['row'], 'field' => $law_bk_state['field'] ),
			)
		);
		?>

		<p class="law-booking-note"><?php echo esc_html(
			$law_bk_wait
				? __( 'Everyone you add joins the waitlist in their own right, and is emailed individually the moment a place opens up. Accounts are created for colleagues who do not already have one, with a link to set their password and add any dietary or accessibility requirements to their profile.', 'law' )
				: __( 'Each colleague gets a booking of their own, with their own booking number. Accounts are created for those who do not already have one, and each is emailed an invitation with the event details and a link to set their password and add any dietary or accessibility requirements to their profile.', 'law' )
		); ?></p>

		<p class="law-modal__actions">
			<?php if ( 'modal' === $law_bk_ctx ) : ?>
				<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Close', 'law' ); ?></button>
			<?php endif; ?>
			<button type="submit" class="button orange" data-law-modal-busy="<?php echo esc_attr( $law_bk_busy ); ?>"><?php echo esc_html( $law_bk_submit ); ?></button>
		</p>
	</form>
	<?php
endif;
$law_bk_inner = ob_get_clean();

if ( 'modal' === $law_bk_ctx ) : ?>
	<div class="law-modal" id="<?php echo esc_attr( $law_bk_dialog ); ?>" hidden>
		<div class="law-modal__overlay" data-law-modal-close></div>
		<div class="law-modal__dialog law-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $law_bk_dialog ); ?>-title" tabindex="-1">
			<button type="button" class="law-modal__close" data-law-modal-close aria-label="Close">&times;</button>
			<h2 class="law-modal__title" id="<?php echo esc_attr( $law_bk_dialog ); ?>-title"><?php echo esc_html( $law_bk_heading ); ?></h2>
			<?php echo $law_bk_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, fully escaped. ?>
		</div>
	</div>
<?php else : ?>
	<section class="law-booking-inline" aria-labelledby="law-booking-inline-title">
		<h2 class="law-cal-acc__heading" id="law-booking-inline-title"><?php echo esc_html( $law_bk_heading ); ?></h2>
		<?php echo $law_bk_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, fully escaped. ?>
	</section>
<?php endif; ?>

<?php
// The post-booking confirmation dialog used to live here. It is position:fixed,
// so it now renders on wp_footer via law_booking_footer_modal() instead: this
// partial's output sits inside the hero's details box, whose .grid-container is
// a stacking context, which would clamp the dialog under the fixed header. See
// parts/events/booking-success-modal.php.
?>
