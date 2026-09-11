<?php
/**
 * The flagship application form (FLAGSHIP_PAYMENTS.md §4.2), in two contexts:
 *
 * - 'modal': the .law-modal skeleton the Apply button opens (law-modal.js
 *   supplies open/close/focus-trap off the shared classes; deliberately NOT
 *   parts/layout/modal.php, whose args are confirm-dialog shaped). Hidden
 *   without JS, because the opener is then a real link to the inline context.
 * - 'inline': the same form rendered in the page at ?law_flagship_apply=1,
 *   the no-JS path, repopulated from law_flagship_form_state() after a
 *   refused submission.
 *
 * Three states inside: logged out, a profile too incomplete to apply with,
 * and the consent step.
 *
 * **It collects nothing.** Denis, 10 September 2026: everything the committee
 * reviews an applicant on — name, organisation, job title, country, dietary
 * and access requirements — is already on their profile, and asking for it
 * again is friction, a second copy to drift, and a longer form between
 * someone and a decision they have already made. So the application reads
 * from the profile, and this dialog is the consent step and nothing else.
 * If the profile is missing what the committee needs, it says so and links
 * there rather than quietly accepting a nameless application.
 *
 * Unlike the hosted-event booking form there is no colleague repeater: one
 * application per person (EVENTS_4.2_SPECS.md §5.1), so that the committee
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
$law_fa_heading   = __( 'Apply to attend', 'law' );
$law_fa_when      = trim(
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
	<p class="law-modal__copy"><?php esc_html_e( 'You need an account to apply for a place at the conference. It only takes a minute, and you will come straight back here.', 'law' ); ?></p>
	<p class="law-modal__actions law-booking-auth">
		<a class="button second" href="<?php echo esc_url( wp_login_url( $law_fa_permalink ) ); ?>"><?php esc_html_e( 'Sign in', 'law' ); ?></a>
		<a class="button orange" href="<?php echo esc_url( add_query_arg( array( 'role' => 'attendee', 'redirect_to' => $law_fa_permalink ), home_url( '/register/' ) ) ); ?>"><?php esc_html_e( 'Create an account', 'law' ); ?></a>
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
				__( 'Before you apply, please add your %s to your profile. The committee reviews applications on those details, and we take them from your profile so you never have to type them twice.', 'law' ),
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
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-law-booking-rows>
		<input type="hidden" name="action" value="law_flagship_apply">
		<?php
		// The net price this form is showing. law_flagship_apply() refuses
		// rather than repricing if it has moved since — a delegate who had
		// the page open across the cutover must not be charged a figure they
		// never saw and never consented to.
		?>
		<input type="hidden" name="law_flagship_apply[price_shown]" value="<?php echo esc_attr( (string) $law_fa_price ); ?>">
		<?php wp_nonce_field( 'law_flagship_apply' ); ?>
		<?php law_events_honeypot_field(); ?>

		<?php
		// What they are applying for, as a highlighted block rather than two
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
		// is snapshotted onto the application at this moment, and
		// law_flagship_apply() refuses outright if it has moved since.
		?>
		<div class="law-booking-summary law-event-summary">
			<p class="law-event-summary__title"><?php echo esc_html( (string) ( $law_fa_event['title'] ?? '' ) ); ?></p>
			<?php if ( '' !== $law_fa_when ) : ?>
				<p class="law-event-summary__when"><?php echo esc_html( $law_fa_when ); ?></p>
			<?php endif; ?>
			<p class="law-event-summary__row">
				<span class="law-event-summary__label"><?php esc_html_e( 'Price', 'law' ); ?></span>
				<strong><?php echo esc_html( law_events_price_label( $law_fa_price ) ); ?></strong>
			</p>
		</div>

		<?php if ( $law_fa_places['full'] ) : ?>
			<p class="law-booking-substate"><?php esc_html_e( 'The conference is currently full, so your application joins the queue for a place.', 'law' ); ?></p>
		<?php endif; ?>

		<?php if ( $law_fa_errors ) : ?>
			<div class="law-form-notice is-error" role="alert">
				<?php foreach ( $law_fa_errors as $law_fa_error ) : ?>
					<p><?php echo esc_html( $law_fa_error ); ?></p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>

		<?php
		// No "Payment" heading: the dialog is one step and one subject, so a
		// section title would be labelling the whole of itself.
		?>
		<?php
		// The amount is stated in the summary above and again on the consent
		// below, which is where it legally matters. Repeating it a third time
		// here made the paragraph harder to read, not clearer.
		?>
		<p class="law-booking-note">
			<?php esc_html_e( 'The next step is our payment provider, Stripe, where you choose how you would like to pay. Your payment details are saved but NOT charged. If the committee approves your application we take the payment and confirm your place; if not, we delete your payment details and you pay nothing.', 'law' ); ?>
		</p>

		<p class="law-form-field">
			<label>
				<input type="checkbox" name="law_flagship_apply[consent]" value="1" data-law-field="law_consent" aria-required="true">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: the total price. */
						__( 'I agree to my payment details being saved securely and charged %s if my application is approved. *', 'law' ),
						law_events_format_pence( law_events_gross_pence( $law_fa_price ) )
					)
				);
				?>
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
			<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Taking you to Stripe…', 'law' ); ?>">
				<?php esc_html_e( 'Continue to payment details', 'law' ); ?>
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
