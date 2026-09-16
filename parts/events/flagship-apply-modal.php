<?php
/**
 * The flagship registration form (FLAGSHIP_PAYMENTS.md §4.2), in two contexts:
 *
 * - 'modal': the .law-modal skeleton the Register button opens (law-modal.js
 *   supplies open/close/focus-trap off the shared classes; deliberately NOT
 *   parts/layout/modal.php, whose args are confirm-dialog shaped). Hidden
 *   without JS, because the opener is then a real link to the inline context.
 * - 'inline': the same form rendered in the page at ?law_flagship_apply=1,
 *   the no-JS path, repopulated from law_flagship_form_state() after a
 *   refused submission.
 *
 * Three states inside: logged out, a profile too incomplete to register with,
 * and the consent step.
 *
 * **It collects nothing.** Denis, 10 September 2026: everything the committee
 * reviews a delegate on — name, organisation, job title, country, dietary
 * and access requirements — is already on their profile, and asking for it
 * again is friction, a second copy to drift, and a longer form between
 * someone and a decision they have already made. So the registration reads
 * from the profile, and this dialog is the consent step and nothing else.
 * If the profile is missing what the committee needs, it says so and links
 * there rather than quietly accepting a nameless registration.
 *
 * Unlike the hosted-event booking form there is no colleague repeater: one
 * registration per person (EVENTS_4.2_SPECS.md §5.1), so that the committee
 * reviews each individually and each payment method is charged separately.
 *
 * Args: event (the calendar-mapped array), context ('modal' | 'inline').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_fa_event = (array) ( $args['event'] ?? array() );
$law_fa_ctx   = 'inline' === ( $args['context'] ?? 'modal' ) ? 'inline' : 'modal';
$law_fa_id    = (int) ( $law_fa_event['id'] ?? 0 );
if ( ! $law_fa_id ) {
	return;
}

$law_fa_permalink = get_permalink( $law_fa_id );
$law_fa_price     = law_flagship_price_pence( 0, $law_fa_id );
$law_fa_places    = law_flagship_places();
$law_fa_dialog    = 'law-flagship-modal';
$law_fa_heading   = __( 'Register', 'law' );

// The money, from the server, exactly as the reception dialog does it: the
// Apply button re-asks the same function and swaps the figures in place. The
// codeless quote is what the form renders with, so the no-JS path is right on
// first paint.
$law_fa_quote = law_flagship_quote( $law_fa_id, '', get_current_user_id() );
if ( is_wp_error( $law_fa_quote ) ) {
	// Not on sale. The page's own guards say so; fall back to a quote shaped
	// like the price, so nothing below has to test for an error.
	$law_fa_quote = array(
		'list_net'    => $law_fa_price,
		'list_gross'  => law_events_gross_pence( $law_fa_price ),
		'net'         => $law_fa_price,
		'discount'    => 0,
		'vat'         => law_events_vat_pence( $law_fa_price ),
		'gross'       => law_events_gross_pence( $law_fa_price ),
		'code'        => '',
		'discount_id' => 0,
		'free'        => $law_fa_price < 1,
	);
}

$law_fa_when = trim(
	( ! empty( $law_fa_event['date'] ) ? law_calendar_day_heading( $law_fa_event['date'] ) : '' )
	. ( ! empty( $law_fa_event['time_label'] ) && 'Slot not confirmed' !== $law_fa_event['time_label'] ? ', ' . $law_fa_event['time_label'] : '' ),
	', '
);

// A refused submission comes back through the one-shot transient; only the
// inline context reads it, because the modal is opened fresh by JS and
// consuming it here would steal it from the inline form.
$law_fa_state  = 'inline' === $law_fa_ctx ? law_flagship_form_state() : array( 'errors' => array() );
$law_fa_errors = (array) ( $law_fa_state['errors'] ?? array() );

// What the committee needs in order to review somebody. Checked here so the
// dialog can send them to their profile, and again in law_flagship_apply(),
// which is the guard that actually holds.
$law_fa_missing = is_user_logged_in() ? law_flagship_profile_gaps( get_current_user_id() ) : array();

ob_start();
if ( ! is_user_logged_in() ) :
	?>
	<p class="law-modal__copy"><?php esc_html_e( 'You need an account to register for a place at the conference. It only takes a minute, and you will come straight back here.', 'law' ); ?></p>
	<p class="law-modal__actions law-booking-auth">
		<a class="button second" href="<?php echo esc_url( wp_login_url( $law_fa_permalink ) ); ?>"><?php esc_html_e( 'Sign in', 'law' ); ?></a>
		<a class="button orange" href="<?php echo esc_url( add_query_arg( array( 'redirect_to' => $law_fa_permalink ), home_url( '/register/' ) ) ); ?>"><?php esc_html_e( 'Create an account', 'law' ); ?></a>
	</p>
	<?php
elseif ( $law_fa_missing ) :
	// No fields here to fill the gap in, so the only honest thing is to send
	// them where it can be filled.
	?>
	<p class="law-modal__copy">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: a list of missing profile details. */
				__( 'Before you register, please add your %s to your profile. The committee reviews registrations on those details, and we take them from your profile so you never have to type them twice.', 'law' ),
				wp_sprintf_l( '%l', $law_fa_missing )
			)
		);
		?>
	</p>
	<p class="law-modal__actions">
		<?php if ( 'modal' === $law_fa_ctx ) : ?>
			<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Close', 'law' ); ?></button>
		<?php endif; ?>
		<a class="button orange" href="<?php echo esc_url( add_query_arg( 'redirect_to', $law_fa_permalink, home_url( '/account/profile/' ) ) ); ?>"><?php esc_html_e( 'Go to my profile', 'law' ); ?></a>
	</p>
	<?php
else :
	?>
	<form class="law-event-form law-event-form--light law-booking-form law-flagship-apply" method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-law-booking-rows
		data-law-quote-event="<?php echo esc_attr( (string) $law_fa_id ); ?>">
		<input type="hidden" name="action" value="law_flagship_apply">
		<?php
		// The GROSS this form is showing (it was the net until the code field
		// arrived, and one field can only hold one figure). law_flagship_apply()
		// refuses rather than repricing if it has moved since — a delegate who
		// had the page open across the cutover must not be charged a figure
		// they never saw and never consented to.
		?>
		<input type="hidden" name="law_flagship_apply[price_shown]" value="<?php echo esc_attr( (string) $law_fa_quote['gross'] ); ?>" data-law-price-shown>
		<?php
		// The code the last successful quote used. law_flagship_apply() asks
		// for the press rather than silently applying a code nobody checked
		// and then blaming the delegate for a price that moved.
		?>
		<input type="hidden" name="law_flagship_apply[applied_code]" value="" data-law-applied-code>
		<?php wp_nonce_field( 'law_flagship_apply' ); ?>
		<?php law_events_honeypot_field(); ?>

		<?php
		// What they are registering for, as a highlighted block rather than two
		// grey lines above a wall of prose (Denis, 10 September 2026). The
		// price is one of those facts, so it sits here as a labelled row
		// instead of in a paragraph of its own — which is the paragraph that
		// went. The block is .law-event-summary, shared with the hosted-event
		// booking dialog, which puts its places-left line in the same row
		// (Denis, 11 September 2026).
		//
		// This is the one place the full arithmetic is spelled out. Nothing
		// is said about the price rising later (the switch is silent), which
		// is safe because the figure shown is always the figure charged: it
		// is snapshotted onto the registration at this moment, and
		// law_flagship_apply() refuses outright if it has moved since.
		?>
		<div class="law-booking-summary law-event-summary">
			<p class="law-event-summary__title"><?php echo esc_html( (string) ( $law_fa_event['title'] ?? '' ) ); ?></p>
			<?php if ( '' !== $law_fa_when ) : ?>
				<p class="law-event-summary__when"><?php echo esc_html( $law_fa_when ); ?></p>
			<?php endif; ?>
		</div>


		<?php if ( $law_fa_places['full'] ) : ?>
			<p class="law-booking-substate"><?php esc_html_e( 'The conference is currently full, so your registration joins the queue for a place.', 'law' ); ?></p>
		<?php endif; ?>

		<?php if ( $law_fa_errors ) : ?>
			<div class="law-form-notice is-error" role="alert">
				<?php foreach ( $law_fa_errors as $law_fa_error ) : ?>
					<p><?php echo esc_html( $law_fa_error ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php
		// The receptions a confirmed place includes (RECEPTIONS.md §7.1). Asked
		// HERE rather than later because it is one tick at the moment somebody
		// is already deciding to come, and because the answer has somewhere to
		// live: it rides on the registration as _law_reception_choices and is
		// granted when the place is confirmed. Unticked by default — a place
		// nobody asked for is a place somebody else could have had — and the
		// line says they can add them later, so nothing is lost by leaving
		// them alone. Hidden entirely when there are no included receptions.
		$law_fa_receptions = function_exists( 'law_reception_included_ids' ) ? law_reception_included_ids() : array();
		?>
		<?php if ( $law_fa_receptions ) : ?>
			<fieldset class="law-flagship-receptions law-booking-fieldset">
				<legend><?php esc_html_e( 'Included receptions', 'law' ); ?></legend>
				<p class="law-form-hint"><?php esc_html_e( 'Included at no cost with your place. Tick the ones you would like to attend; you can add them later from My bookings.', 'law' ); ?></p>
				<?php foreach ( $law_fa_receptions as $law_fa_reception ) : ?>
					<?php
					// A reception this delegate ALREADY holds a place at is ticked and
					// DISABLED with a tag saying why, never hidden and never offered
					// again: they may well have bought Monday before deciding to register,
					// and the engine skips a place somebody already has rather than
					// granting a second (Denis, 14 September 2026).
					$law_fa_held = law_booking_user_booking_for_event( get_current_user_id(), $law_fa_reception, law_booking_holding_statuses() );
					$law_fa_tag  = '';
					if ( $law_fa_held instanceof WP_Post ) {
						// "You already have a place", not "Already booked": the
						// shorter form read as a status for the whole dialog on a
						// conference that was itself full (Denis, 14 September 2026).
						$law_fa_tag = 'law-pending-payment' === $law_fa_held->post_status
							? __( 'You are paying for this one', 'law' )
							: __( 'You already have a place', 'law' );
					}
					$law_fa_when = law_reception_when_label( $law_fa_reception );
					?>
					<p class="law-form-field">
						<label>
							<input type="checkbox" name="law_flagship_apply[receptions][]" value="<?php echo esc_attr( (string) $law_fa_reception ); ?>"
								<?php checked( $law_fa_held instanceof WP_Post ); ?>
								<?php disabled( $law_fa_held instanceof WP_Post ); ?>>
							<strong><?php echo esc_html( get_the_title( $law_fa_reception ) ); ?></strong>
							<?php if ( '' !== $law_fa_when ) : ?>
								<span class="law-form-hint"><?php echo esc_html( $law_fa_when ); ?></span>
							<?php endif; ?>
							<?php if ( '' !== $law_fa_tag ) : ?>
								<span class="law-modal__tag"><?php echo esc_html( $law_fa_tag ); ?></span>
							<?php endif; ?>
						</label>
					</p>
				<?php endforeach; ?>
			</fieldset>
		<?php endif; ?>

		<?php
		// The receipt block, shared with the reception checkout: every line
		// carries data-law-price so Apply can swap the figures without the
		// dialog moving. Price is the LIST price and Discount is a signed
		// deduction under it, so price minus discount plus VAT equals the
		// total. Showing the DISCOUNTED net here with the reduction beneath it
		// made the discount look as though it had been taken twice (browser
		// pass, 14 September 2026).
		?>
		<div class="law-booking-price">
			<p class="law-booking-price__row">
				<span><?php esc_html_e( 'Price', 'law' ); ?></span>
				<span data-law-price="net"><?php echo esc_html( law_events_format_pence( $law_fa_quote['list_net'] ) ); ?></span>
			</p>
			<p class="law-booking-price__row law-booking-price__row--discount" data-law-price-discount-row hidden>
				<span><?php esc_html_e( 'Discount', 'law' ); ?></span>
				<span data-law-price="discount">&minus;<?php echo esc_html( law_events_format_pence( $law_fa_quote['discount'] ) ); ?></span>
			</p>
			<p class="law-booking-price__row">
				<span><?php esc_html_e( 'VAT', 'law' ); ?></span>
				<span data-law-price="vat"><?php echo esc_html( law_events_format_pence( $law_fa_quote['vat'] ) ); ?></span>
			</p>
			<p class="law-booking-price__row law-booking-price__row--total">
				<span><?php esc_html_e( 'Total', 'law' ); ?></span>
				<strong data-law-price="gross"><?php echo esc_html( law_events_format_pence( $law_fa_quote['gross'] ) ); ?></strong>
			</p>
		</div>

		<?php
		// The discount code sits in the same bordered box as the included
		// receptions (Denis, 15 September 2026), so the dialog reads as a
		// short stack of one-subject groups rather than a form with one field
		// floating in it. The legend IS the field's heading, so the label is
		// there for a screen reader only: printing both would name the same
		// control twice.
		//
		// No native `required` in here: a required control inside a hidden
		// dialog makes the whole form unsubmittable in Chrome. The script
		// checks, and the server is the guard that holds.
		?>
		<fieldset class="law-booking-fieldset">
			<legend><?php esc_html_e( 'Discount code', 'law' ); ?></legend>
			<p class="law-form-field law-booking-code">
				<label class="show-for-sr" for="law-fa-code-<?php echo esc_attr( (string) $law_fa_id ); ?>"><?php esc_html_e( 'Discount code', 'law' ); ?></label>
				<input type="text" id="law-fa-code-<?php echo esc_attr( (string) $law_fa_id ); ?>"
					name="law_flagship_apply[code]" autocomplete="off" spellcheck="false"
					value="<?php echo esc_attr( (string) ( $law_fa_state['input']['code'] ?? '' ) ); ?>"
					data-law-field="law_discount_code" data-law-quote-code>
				<button type="button" class="button second" data-law-quote><?php esc_html_e( 'Apply', 'law' ); ?></button>
			</p>
			<p class="law-form-hint" role="status" data-law-quote-status></p>
		</fieldset>

		<?php
		// No "Payment" heading: the dialog is one step and one subject, so a
		// section title would be labelling the whole of itself.
		?>
		<?php
		// The amount is stated in the receipt block above and again on the
		// consent below, which is where it legally matters. Repeating it a
		// third time here made the paragraph harder to read, not clearer.
		?>
		<p class="law-booking-note" data-law-stripe-note<?php echo $law_fa_quote['free'] ? ' hidden' : ''; ?>>
			<?php esc_html_e( 'The next step is our payment provider, Stripe, where you choose how you would like to pay. Your payment details are saved but NOT charged. If the committee approves your registration we take the payment and confirm your ticket; if not, we delete your payment details and you pay nothing.', 'law' ); ?>
		</p>

		<?php
		// The consent sentence names the amount, and it is the record of what
		// the delegate agreed to be charged, so the Apply button has to
		// rewrite it along with the figures. Left alone it would still promise
		// a charge of £660 on a registration a code had taken to nothing,
		// which is worse than no consent at all.
		$law_fa_consent_default = __( 'I agree to my payment details being saved securely and charged %s if my registration is approved. *', 'law' );
		$law_fa_consent_free    = __( 'I understand my discount code covers the whole price, so there is nothing to pay and no payment details are needed. *', 'law' );
		?>
		<p class="law-form-field">
			<label>
				<input type="checkbox" name="law_flagship_apply[consent]" value="1" data-law-field="law_consent" aria-required="true">
				<span data-law-consent
					data-law-consent-default="<?php echo esc_attr( $law_fa_consent_default ); ?>"
					data-law-consent-free="<?php echo esc_attr( $law_fa_consent_free ); ?>">
					<?php
					echo esc_html(
						$law_fa_quote['free']
							? $law_fa_consent_free
							: sprintf(
								/* translators: %s: the total price. */
								$law_fa_consent_default,
								law_events_format_pence( $law_fa_quote['gross'] )
							)
					);
					?>
				</span>
			</label>
		</p>

		<p class="law-form-field">
			<label>
				<input type="checkbox" name="law_flagship_apply[terms]" value="1" data-law-field="law_terms" aria-required="true">
				<?php
				printf(
					/* translators: %s: link to the terms. */
					esc_html__( 'I accept the %s. *', 'law' ),
					'<a href="' . esc_url( law_events_attendee_terms_url() ) . '" target="_blank" rel="noopener">' . esc_html__( 'registration terms and conditions', 'law' ) . '</a>'
				);
				?>
			</label>
		</p>

		<p class="law-modal__actions">
			<?php if ( 'modal' === $law_fa_ctx ) : ?>
				<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Close', 'law' ); ?></button>
			<?php endif; ?>
			<?php
			// A free quote has nothing to pay and nowhere to send them, so the
			// button says what will actually happen.
			?>
			<button type="submit" class="button orange"
				data-law-modal-busy="<?php echo esc_attr( $law_fa_quote['free'] ? __( 'Submitting…', 'law' ) : __( 'Taking you to Stripe…', 'law' ) ); ?>"
				data-law-submit-default="<?php esc_attr_e( 'Continue to payment details', 'law' ); ?>"
				data-law-submit-busy-default="<?php esc_attr_e( 'Taking you to Stripe…', 'law' ); ?>"
				data-law-submit-free="<?php esc_attr_e( 'Submit my registration', 'law' ); ?>"
				data-law-submit-busy-free="<?php esc_attr_e( 'Submitting…', 'law' ); ?>">
				<?php echo esc_html( $law_fa_quote['free'] ? __( 'Submit my registration', 'law' ) : __( 'Continue to payment details', 'law' ) ); ?>
			</button>
		</p>
	</form>
	<?php
endif;
$law_fa_inner = ob_get_clean();

if ( 'modal' === $law_fa_ctx ) : ?>
	<div class="law-modal" id="<?php echo esc_attr( $law_fa_dialog ); ?>" hidden>
		<div class="law-modal__overlay" data-law-modal-close></div>
		<div class="law-modal__dialog law-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $law_fa_dialog ); ?>-title" tabindex="-1">
			<button type="button" class="law-modal__close" data-law-modal-close aria-label="Close">&times;</button>
			<h2 class="law-modal__title" id="<?php echo esc_attr( $law_fa_dialog ); ?>-title"><?php echo esc_html( $law_fa_heading ); ?></h2>
			<?php echo $law_fa_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, fully escaped. ?>
		</div>
	</div>
<?php else : ?>
	<section class="law-booking-inline" aria-labelledby="law-flagship-inline-title">
		<h2 class="law-cal-acc__heading" id="law-flagship-inline-title"><?php echo esc_html( $law_fa_heading ); ?></h2>
		<?php echo $law_fa_inner; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built above, fully escaped. ?>
	</section>
<?php endif; ?>
