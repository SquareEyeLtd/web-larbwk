<?php
/**
 * Buying a place at a reception, or joining its waitlist (RECEPTIONS.md §5.1),
 * in two contexts:
 *
 * - 'modal': the .law-modal skeleton the Book now button opens (law-modal.js
 *   supplies open/close/focus-trap off the shared classes; deliberately NOT
 *   parts/layout/modal.php, whose args are confirm-dialog shaped). Hidden
 *   without JS, because the opener is then a real link to the inline context.
 * - 'inline': the same form rendered in the page at ?law_reception_checkout=1
 *   or ?law_reception_waitlist=1, the no-JS path.
 *
 * One partial, two modes, because everything but the consent tick, the action
 * and three labels is identical — and the price block, which is the whole
 * point, is the same arithmetic either way.
 *
 * ONE PLACE PER CHECKOUT, self only (Denis, 14 September 2026), so there is no
 * colleague repeater: a colleague buys their own, which is also what keeps a
 * failed payment from stranding half a party.
 *
 * The money is never computed in the browser. Every figure here comes from
 * law_reception_quote() on the server, and the Apply button re-asks it; all
 * the script does is swap the four lines it is handed
 * (assets/js/booking-form.js §5).
 *
 * Args: event (the calendar-mapped array), context ('modal' | 'inline'),
 * mode ('checkout' | 'waitlist').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_rc_event = (array) ( $args['event'] ?? array() );
$law_rc_ctx   = 'inline' === ( $args['context'] ?? 'modal' ) ? 'inline' : 'modal';
$law_rc_wait  = 'waitlist' === ( $args['mode'] ?? 'checkout' );
$law_rc_id    = (int) ( $law_rc_event['id'] ?? 0 );
if ( ! $law_rc_id ) {
	return;
}

$law_rc_permalink = get_permalink( $law_rc_id );
$law_rc_remaining = law_event_tickets_remaining( $law_rc_id );
$law_rc_quote     = law_reception_quote( $law_rc_id, '', get_current_user_id() );
$law_rc_quote     = is_wp_error( $law_rc_quote ) ? law_reception_quote( $law_rc_id ) : $law_rc_quote;

$law_rc_action  = $law_rc_wait ? 'law_reception_waitlist_join' : 'law_reception_checkout';
$law_rc_dialog  = 'law-reception-modal';
$law_rc_heading = $law_rc_wait ? __( 'Join the waitlist', 'law' ) : __( 'Book your place', 'law' );
$law_rc_submit  = $law_rc_wait ? __( 'Join waitlist', 'law' ) : __( 'Continue to payment', 'law' );
$law_rc_busy    = $law_rc_wait ? __( 'Joining…', 'law' ) : __( 'Taking you to Stripe…', 'law' );
$law_rc_when    = trim(
	( ! empty( $law_rc_event['date'] ) ? law_calendar_day_heading( $law_rc_event['date'] ) : '' )
	. ( ! empty( $law_rc_event['time_label'] ) && 'Slot not confirmed' !== $law_rc_event['time_label'] ? ', ' . $law_rc_event['time_label'] : '' ),
	', '
);

// What the committee needs before a place can be taken. Checked here so the
// dialog can send them to their profile, and again in the engine, which is the
// guard that actually holds.
$law_rc_missing = is_user_logged_in() ? law_booking_profile_gaps( get_current_user_id() ) : array();

ob_start();
if ( ! is_user_logged_in() ) :
	?>
	<p class="law-modal__copy"><?php echo esc_html(
		$law_rc_wait
			? __( 'You need an account to join the waitlist for this reception. It only takes a minute, and you will come straight back here.', 'law' )
			: __( 'You need an account to book a place at this reception. It only takes a minute, and you will come straight back here.', 'law' )
	); ?></p>
	<p class="law-modal__actions law-booking-auth">
		<a class="button second" href="<?php echo esc_url( wp_login_url( $law_rc_permalink ) ); ?>"><?php esc_html_e( 'Sign in', 'law' ); ?></a>
		<a class="button orange" href="<?php echo esc_url( add_query_arg( array( 'redirect_to' => $law_rc_permalink ), home_url( '/register/' ) ) ); ?>"><?php esc_html_e( 'Create an account', 'law' ); ?></a>
	</p>
	<?php
elseif ( $law_rc_missing ) :
	// No fields here to fill the gap in, so the only honest thing is to send
	// them where it can be filled.
	?>
	<p class="law-modal__copy">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: a list of missing profile details. */
				__( 'Before you book, please add your %s to your profile. We take your details from there so you never have to type them twice.', 'law' ),
				wp_sprintf_l( '%l', $law_rc_missing )
			)
		);
		?>
	</p>
	<p class="law-modal__actions">
		<?php if ( 'modal' === $law_rc_ctx ) : ?>
			<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Close', 'law' ); ?></button>
		<?php endif; ?>
		<a class="button orange" href="<?php echo esc_url( add_query_arg( 'redirect_to', $law_rc_permalink, home_url( '/account/profile/' ) ) ); ?>"><?php esc_html_e( 'Go to my profile', 'law' ); ?></a>
	</p>
	<?php
else :
	?>
	<form class="law-event-form law-event-form--light law-booking-form law-reception-checkout" method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		data-law-reception-quote="<?php echo esc_attr( (string) $law_rc_id ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( $law_rc_action ); ?>">
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $law_rc_id ); ?>">
		<?php
		// The gross the form is SHOWING, and the code the last successful quote
		// used. law_reception_checkout() refuses rather than repricing if
		// either has moved since: a delegate must never be charged a figure
		// they did not see, and a code typed but not applied must be asked
		// for rather than silently applied and then blamed.
		?>
		<input type="hidden" name="law_reception[price_shown]" value="<?php echo esc_attr( (string) $law_rc_quote['gross'] ); ?>" data-law-price-shown>
		<input type="hidden" name="law_reception[applied_code]" value="" data-law-applied-code>
		<?php wp_nonce_field( $law_rc_action ); ?>
		<?php law_events_honeypot_field(); ?>

		<div class="law-booking-summary law-event-summary">
			<p class="law-event-summary__title"><?php echo esc_html( (string) ( $law_rc_event['title'] ?? '' ) ); ?></p>
			<?php if ( '' !== $law_rc_when ) : ?>
				<p class="law-event-summary__when"><?php echo esc_html( $law_rc_when ); ?></p>
			<?php endif; ?>
			<p class="law-event-summary__row">
				<span>
					<?php if ( $law_rc_wait ) : ?>
						<?php esc_html_e( 'This reception is fully booked.', 'law' ); ?>
					<?php else : ?>
						<?php echo esc_html( sprintf( _n( '%s place left.', '%s places left.', (int) $law_rc_remaining, 'law' ), number_format_i18n( (int) $law_rc_remaining ) ) ); ?>
					<?php endif; ?>
				</span>
			</p>
		</div>

		<?php
		// The price block. Every line carries data-law-price so the Apply
		// button can swap the four figures in place without the page moving,
		// and the Discount row starts hidden because there is nothing to say
		// about a discount nobody has claimed.
		?>
		<div class="law-reception-price">
			<p class="law-reception-price__row">
				<span><?php esc_html_e( 'Price', 'law' ); ?></span>
				<span data-law-price="net"><?php echo esc_html( law_events_format_pence( $law_rc_quote['net'] ) ); ?></span>
			</p>
			<p class="law-reception-price__row law-reception-price__row--discount" data-law-price-discount-row hidden>
				<span><?php esc_html_e( 'Discount', 'law' ); ?></span>
				<span data-law-price="discount"><?php echo esc_html( law_events_format_pence( $law_rc_quote['discount'] ) ); ?></span>
			</p>
			<p class="law-reception-price__row">
				<span><?php esc_html_e( 'VAT', 'law' ); ?></span>
				<span data-law-price="vat"><?php echo esc_html( law_events_format_pence( $law_rc_quote['vat'] ) ); ?></span>
			</p>
			<p class="law-reception-price__row law-reception-price__row--total">
				<span><?php esc_html_e( 'Total', 'law' ); ?></span>
				<strong data-law-price="gross"><?php echo esc_html( law_events_format_pence( $law_rc_quote['gross'] ) ); ?></strong>
			</p>
		</div>

		<?php
		// No native `required` on anything in here: a required control inside a
		// hidden dialog makes the whole form unsubmittable in Chrome. The
		// script checks, and the server is the guard that holds.
		?>
		<p class="law-form-field law-reception-code">
			<label for="law-rc-code-<?php echo esc_attr( (string) $law_rc_id ); ?>"><?php esc_html_e( 'Discount code', 'law' ); ?></label>
			<input type="text" id="law-rc-code-<?php echo esc_attr( (string) $law_rc_id ); ?>"
				name="law_reception[code]" autocomplete="off" spellcheck="false"
				data-law-field="law_discount_code" data-law-quote-code>
			<button type="button" class="button second" data-law-quote><?php esc_html_e( 'Apply', 'law' ); ?></button>
		</p>
		<p class="law-form-hint" role="status" data-law-quote-status></p>

		<?php if ( $law_rc_wait ) : ?>
			<p class="law-form-field">
				<label>
					<input type="checkbox" name="law_reception[consent]" value="1" data-law-field="law_consent" aria-required="true">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: the total price. */
							__( 'Save my payment details and charge %s when a place opens up. You can leave the waitlist at any time before then. *', 'law' ),
							law_events_format_pence( $law_rc_quote['gross'] )
						)
					);
					?>
				</label>
			</p>
		<?php endif; ?>

		<p class="law-form-field">
			<label>
				<input type="checkbox" name="law_reception[terms]" value="1" data-law-field="law_terms" aria-required="true">
				<?php
				printf(
					/* translators: %s: link to the terms. */
					esc_html__( 'I accept the %s. *', 'law' ),
					'<a href="' . esc_url( law_events_attendee_terms_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'registration terms and conditions', 'law' ) . '</a>'
				);
				?>
			</label>
		</p>

		<p class="law-booking-note" data-law-stripe-note<?php echo $law_rc_quote['free'] ? ' hidden' : ''; ?>>
			<?php
			echo esc_html(
				$law_rc_wait
					? __( 'You will be taken to Stripe to save your payment details. Nothing is charged unless a place opens up.', 'law' )
					: __( 'You will be taken to Stripe to pay.', 'law' )
			);
			?>
		</p>

		<p class="law-modal__actions">
			<?php if ( 'modal' === $law_rc_ctx ) : ?>
				<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Close', 'law' ); ?></button>
			<?php endif; ?>
			<button type="submit" class="button orange"
				data-law-modal-busy="<?php echo esc_attr( $law_rc_busy ); ?>"
				data-law-submit-default="<?php echo esc_attr( $law_rc_submit ); ?>"
				data-law-submit-busy-default="<?php echo esc_attr( $law_rc_busy ); ?>"
				data-law-submit-free="<?php esc_attr_e( 'Confirm my free place', 'law' ); ?>"
				data-law-submit-busy-free="<?php esc_attr_e( 'Confirming…', 'law' ); ?>">
				<?php echo esc_html( $law_rc_submit ); ?>
			</button>
		</p>
	</form>
	<?php
endif;
$law_rc_inner = ob_get_clean();

if ( 'modal' === $law_rc_ctx ) : ?>
	<div class="law-modal" id="<?php echo esc_attr( $law_rc_dialog ); ?>" hidden>
		<div class="law-modal__overlay" data-law-modal-close></div>
		<div class="law-modal__dialog law-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $law_rc_dialog ); ?>-title" tabindex="-1">
			<button type="button" class="law-modal__close" data-law-modal-close aria-label="<?php esc_attr_e( 'Close', 'law' ); ?>">&times;</button>
			<h2 class="law-modal__title" id="<?php echo esc_attr( $law_rc_dialog ); ?>-title"><?php echo esc_html( $law_rc_heading ); ?></h2>
			<?php echo $law_rc_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, fully escaped. ?>
		</div>
	</div>
<?php else : ?>
	<section class="law-booking-inline" aria-labelledby="law-reception-inline-title">
		<h2 class="law-cal-acc__heading" id="law-reception-inline-title"><?php echo esc_html( $law_rc_heading ); ?></h2>
		<?php echo $law_rc_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, fully escaped. ?>
	</section>
<?php endif; ?>
