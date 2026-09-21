<?php
/**
 * "Hand this place to somebody else", for
 * templates/account-dashboard-flagship-bookings.php (the client's ask,
 * 21 September 2026).
 *
 * A firm buys a flagship place for a named partner, the partner cannot come,
 * and a colleague goes instead. Before this the committee's only route was to
 * cancel the confirmed ticket and add the replacement as a complimentary
 * place, which threw away the payment trail and filed a paying delegate as a
 * freebie.
 *
 * ONE dialog for the whole table, rendered OUTSIDE #law-cal-events, like
 * parts/events/flagship-ticket-type.php and for the same reason: the filter bar
 * replaces that container wholesale over &law_partial=1, and a dialog living
 * inside it would be destroyed mid-use while its opener still pointed at the
 * dead id. There is a second reason here that does not apply there — this form
 * carries fifteen fields, and a copy per row on a list that runs to hundreds
 * would be most of the page. assets/js/flagship-substitute.js fills in the
 * booking and the current delegate from the row that was pressed.
 *
 * Modelled on parts/events/flagship-add-attendee.php rather than on the
 * ticket-type dialog, because parts/layout/modal.php takes ONE field and this
 * needs eight, so the .law-modal wrapper is written out here the same way.
 *
 * The no-JS path is NOT a copy of this dialog. A committee member with scripts
 * off cannot be handed one, and a form asking them to type a post ID would be
 * dishonest — they know registration numbers, not post IDs. Each row's
 * <noscript> links to ?law_substitute={id} instead and the template renders
 * this same form inline, pre-filled, above the table. $args['booking_id'] is
 * how it says so.
 *
 * Args: booking_id (0 for the dialog copy; a booking ID for the inline no-JS
 * copy).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! law_user_is_committee() ) {
	return;
}

$law_fsub_for     = absint( $args['booking_id'] ?? 0 );
$law_fsub_inline  = (bool) $law_fsub_for;
$law_fsub_current = $law_fsub_for ? law_booking_attendee( $law_fsub_for ) : array();
$law_fsub_number  = $law_fsub_for ? (int) law_event_meta( $law_fsub_for, '_law_booking_number' ) : 0;

/**
 * The sentence naming whoever holds the place now.
 *
 * Built in PHP for both copies, and handed to the script as a printf template
 * on the form (data-law-sub-current-template) rather than assembled in
 * JavaScript. Same rule as the ticket-type dialog's title: nothing here runs
 * through wp_kses_post(), so no sentence is composed client-side.
 */
$law_fsub_sentence = static function ( $number, $name, $email ) {
	return sprintf(
		/* translators: 1: registration number, 2: the delegate's name, 3: their email. */
		__( 'Registration #%1$s is held by %2$s (%3$s).', 'law' ),
		$number,
		$name,
		$email
	);
};

/**
 * The fields, printed into both the dialog and the inline no-JS copy.
 *
 * @param string $law_fsub_prefix     Unique per copy: the two "Other" boxes in the
 *                                    shared profile block are toggled by id.
 * @param bool   $law_fsub_show_other Render those boxes open (the no-JS copy,
 *                                    where no script is going to reveal them).
 * @param bool   $law_fsub_press      Whether the ticket currently carries a press
 *                                    pass. The dialog's copy is filled in by the
 *                                    script from the row that was pressed; the
 *                                    inline copy has to be given it here, or a
 *                                    substitution made without JavaScript would
 *                                    silently drop the pass.
 */
$law_fsub_fields = static function ( $law_fsub_prefix, $law_fsub_show_other = false, $law_fsub_press = false ) {
	?>
	<?php
	// Labels WRAP their inputs rather than using for/id. The same fields can be
	// printed twice on this page and ids would collide — which silently breaks
	// both labels, not one. Same vocabulary as
	// parts/events/flagship-add-attendee.php.
	?>
	<div class="law-row-grid">
		<p class="law-form-field">
			<label>
				<?php esc_html_e( 'Full name *', 'law' ); ?>
				<input type="text" name="name" data-law-field="name" aria-required="true">
			</label>
		</p>
		<p class="law-form-field">
			<label>
				<?php esc_html_e( 'Email *', 'law' ); ?>
				<input type="email" name="email" data-law-field="email" aria-required="true">
			</label>
			<span class="law-form-hint"><?php esc_html_e( 'An account is created if they do not have one, with a link to set their password in the same email as their ticket.', 'law' ); ?></span>
		</p>
	</div>

	<div class="law-row-grid">
		<p class="law-form-field">
			<label>
				<?php esc_html_e( 'Organisation', 'law' ); ?>
				<input type="text" name="organisation" data-law-field="organisation">
			</label>
		</p>
		<p class="law-form-field">
			<label>
				<?php esc_html_e( 'Job title', 'law' ); ?>
				<input type="text" name="job_title" data-law-field="job_title">
			</label>
		</p>
	</div>

	<?php
	// Country, accessibility and dietary, the same set registration collects
	// (Denis, 11 September 2026). The delegate list and the exports read these
	// live from the attendee's profile, and a substitute sent by a firm at the
	// last minute has never filled a registration form in.
	get_template_part(
		'parts/events/attendee-profile-fields',
		null,
		array(
			'id_prefix'  => $law_fsub_prefix,
			'show_other' => $law_fsub_show_other,
			'note'       => __( 'Whatever they told you. They can change any of this themselves from their profile.', 'law' ),
		)
	);
	?>

	<p class="law-form-field">
		<label>
			<input type="checkbox" name="law_press" value="1" data-law-field="press" <?php checked( (bool) $law_fsub_press ); ?>>
			<?php esc_html_e( 'Press pass', 'law' ); ?>
		</label>
		<span class="law-form-hint"><?php esc_html_e( 'Carried over from the ticket. Untick it if it does not apply to the new delegate.', 'law' ); ?></span>
	</p>
	<?php
	// Deliberately NO "Included receptions" fieldset, which the add-attendee
	// dialog does carry. Which receptions move is decided by what this ticket
	// already includes, so a tick box here could only grant one it never did.
	?>
	<?php
};

/** What the committee has to know before they press it. */
$law_fsub_warning = static function () {
	?>
	<p class="law-modal__copy">
		<?php esc_html_e( 'The place stays confirmed and simply changes hands. The new delegate is emailed their ticket and a calendar invitation, and the person giving it up is emailed to say where it went.', 'law' ); ?>
	</p>
	<p class="law-modal__copy">
		<?php esc_html_e( 'Nothing is refunded and nothing is charged. The payment, the Stripe invoice and the VAT receipt stay with whoever paid, and their email carries the link to it.', 'law' ); ?>
		<?php
		// The two things the first draft did not say, and the two that matter
		// most: a mistyped address creates an account for a stranger, hands
		// them a confirmed ticket with a calendar invitation, and tells the
		// current delegate their place has gone.
		?>
		<strong><?php esc_html_e( 'Both emails are sent as soon as you press this, and it cannot be undone, so please check the address.', 'law' ); ?></strong>
	</p>
	<p class="law-modal__copy">
		<?php esc_html_e( 'Any drinks reception places included with the ticket move across with it. The ticket type is not reviewed, so please check it still applies afterwards.', 'law' ); ?>
	</p>
	<?php
};

/** The hidden inputs every copy of the form needs. */
$law_fsub_hidden = static function ( $law_fsub_booking ) {
	?>
	<input type="hidden" name="action" value="law_flagship_substitute">
	<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $law_fsub_booking ); ?>" data-law-sub-booking>
	<?php wp_nonce_field( 'law_flagship_substitute' ); ?>
	<?php law_events_honeypot_field(); ?>
	<?php
};
?>

<?php if ( $law_fsub_inline ) : ?>

	<section class="law-flagship-bookings__substitute law-flagship-bookings__substitute--inline">
		<h2><?php esc_html_e( 'Substitute the delegate on this ticket', 'law' ); ?></h2>
		<p class="law-booking-substate">
			<?php
			echo esc_html(
				$law_fsub_sentence(
					(string) $law_fsub_number,
					(string) ( $law_fsub_current['name'] ?? '' ),
					(string) ( $law_fsub_current['email'] ?? '' )
				)
			);
			?>
		</p>
		<?php $law_fsub_warning(); ?>

		<form class="law-event-form law-event-form--light" method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $law_fsub_hidden( $law_fsub_for ); ?>
			<?php $law_fsub_fields( 'law-fsub-nojs', true, (bool) law_event_meta( $law_fsub_for, '_law_is_press' ) ); ?>
			<p class="law-form-buttons">
				<button type="submit" class="button orange"><?php esc_html_e( 'Substitute the delegate', 'law' ); ?></button>
				<a class="button second" href="<?php echo esc_url( law_flagship_bookings_url() ); ?>"><?php esc_html_e( 'Keep the current delegate', 'law' ); ?></a>
			</p>
		</form>
	</section>

<?php else : ?>

	<div class="law-modal" id="<?php echo esc_attr( law_flagship_substitute_modal_id() ); ?>" hidden>
		<div class="law-modal__overlay" data-law-modal-close></div>
		<div class="law-modal__dialog law-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="law-flagship-substitute-title" tabindex="-1">
			<button type="button" class="law-modal__close" data-law-modal-close aria-label="<?php esc_attr_e( 'Close', 'law' ); ?>">&times;</button>
			<h2 class="law-modal__title" id="law-flagship-substitute-title"><?php esc_html_e( 'Substitute the delegate on this ticket', 'law' ); ?></h2>

			<form class="law-event-form law-event-form--light law-booking-form" method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
				data-law-booking-rows
				data-law-sub-current-template="<?php echo esc_attr( $law_fsub_sentence( '%1$s', '%2$s', '%3$s' ) ); ?>">
				<?php $law_fsub_hidden( 0 ); ?>

				<p class="law-booking-substate" data-law-sub-current></p>

				<?php $law_fsub_warning(); ?>

				<?php $law_fsub_fields( 'law-fsub' ); ?>

				<p class="law-modal__actions">
					<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Keep the current delegate', 'law' ); ?></button>
					<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Substituting…', 'law' ); ?>">
						<?php esc_html_e( 'Substitute the delegate', 'law' ); ?>
					</button>
				</p>
			</form>
		</div>
	</div>

<?php endif; ?>
