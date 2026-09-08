<?php
/**
 * The booking form for a single event (EVENTS_BOOKINGS.md §7.2), in two
 * contexts:
 *
 * - 'modal': the .law-modal skeleton the Register button opens (law-modal.js
 *   supplies open/close/focus-trap behaviour off the shared classes; this is
 *   deliberately NOT parts/layout/modal.php, whose args are confirm-dialog
 *   shaped, single field). Hidden without JS — the opener is then a real link
 *   to the inline context below.
 * - 'inline': the same form rendered in the page (?law_book=1 on the event
 *   permalink), the no-JS path, repopulated from law_booking_form_state()
 *   after a refused submission.
 *
 * Two states inside: logged out (sign in / create an attendee account, both
 * returning here) and the form. Any logged-in user can book; the attendee
 * role is granted by the engine.
 *
 * Args: event (the calendar-mapped array), context ('modal' | 'inline').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_bk_event = (array) ( $args['event'] ?? array() );
$law_bk_ctx   = 'inline' === ( $args['context'] ?? 'modal' ) ? 'inline' : 'modal';
$law_bk_id    = (int) ( $law_bk_event['id'] ?? 0 );
if ( ! $law_bk_id ) {
	return;
}

$law_bk_permalink = get_permalink( $law_bk_id );
$law_bk_remaining = law_event_tickets_remaining( $law_bk_id );
$law_bk_max       = min( law_booking_max_additional(), max( 0, (int) $law_bk_remaining - 1 ) );
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
	<p class="law-modal__copy"><?php esc_html_e( 'You need an account to book places at London Arbitration Week events. It only takes a minute, and you will come straight back to this event.', 'law' ); ?></p>
	<p class="law-modal__actions law-booking-auth">
		<a class="button second" href="<?php echo esc_url( wp_login_url( $law_bk_permalink ) ); ?>"><?php esc_html_e( 'Sign in', 'law' ); ?></a>
		<a class="button orange" href="<?php echo esc_url( add_query_arg( array( 'role' => 'attendee', 'redirect_to' => $law_bk_permalink ), home_url( '/register/' ) ) ); ?>"><?php esc_html_e( 'Create an account', 'law' ); ?></a>
	</p>
	<?php
else :
	?>
	<form class="law-event-form law-event-form--light law-booking-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-law-booking-success="law-booking-success">
		<input type="hidden" name="action" value="law_booking_create">
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $law_bk_id ); ?>">
		<?php wp_nonce_field( 'law_booking_create' ); ?>
		<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>

		<p class="law-booking-summary">
			<strong><?php echo esc_html( (string) $law_bk_event['title'] ); ?></strong>
			<?php if ( '' !== $law_bk_when ) : ?>
				<br><?php echo esc_html( $law_bk_when ); ?>
			<?php endif; ?>
		</p>
		<p class="law-booking-substate">
			<?php echo esc_html( sprintf( _n( '%s place left.', '%s places left.', (int) $law_bk_remaining, 'law' ), number_format_i18n( (int) $law_bk_remaining ) ) ); ?>
			<?php if ( $law_bk_max > 0 ) : ?>
				<?php echo esc_html( sprintf( _n( 'You can bring up to %d colleague.', 'You can bring up to %d colleagues.', $law_bk_max, 'law' ), $law_bk_max ) ); ?>
			<?php endif; ?>
		</p>

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

		<p class="law-booking-note"><?php esc_html_e( 'Accounts will be created for colleagues who do not already have one. Each of them is emailed an invitation with the event details and a link to set their password and add any dietary or accessibility requirements to their profile.', 'law' ); ?></p>

		<p class="law-modal__actions">
			<?php if ( 'modal' === $law_bk_ctx ) : ?>
				<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Cancel', 'law' ); ?></button>
			<?php endif; ?>
			<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Registering…', 'law' ); ?>"><?php esc_html_e( 'Register', 'law' ); ?></button>
		</p>
	</form>
	<?php
endif;
$law_bk_inner = ob_get_clean();

if ( 'modal' === $law_bk_ctx ) : ?>
	<div class="law-modal" id="law-booking-modal" hidden>
		<div class="law-modal__overlay" data-law-modal-close></div>
		<div class="law-modal__dialog law-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="law-booking-modal-title" tabindex="-1">
			<button type="button" class="law-modal__close" data-law-modal-close aria-label="Close">&times;</button>
			<h2 class="law-modal__title" id="law-booking-modal-title"><?php esc_html_e( 'Book your place', 'law' ); ?></h2>
			<?php echo $law_bk_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, fully escaped. ?>
		</div>
	</div>
<?php else : ?>
	<section class="law-booking-inline" aria-labelledby="law-booking-inline-title">
		<h2 class="law-cal-acc__heading" id="law-booking-inline-title"><?php esc_html_e( 'Book your place', 'law' ); ?></h2>
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
