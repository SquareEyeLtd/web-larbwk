<?php
/**
 * "Add an attendee without payment", for
 * templates/account-dashboard-flagship-bookings.php.
 *
 * Speakers, press, sponsors and VIPs (Denis, 10 September 2026): the person
 * gets a confirmed place with no payment method and no invoice, marked
 * complimentary so
 * the counts and exports can tell them apart from a paying delegate.
 *
 * This part is the DIALOG and the no-JS fallback. Its opener lives with the
 * bulk buttons, in the actions row under the table
 * (parts/events/flagship-bookings-list.php), because all three act on the
 * same list and the list can run to hundreds of rows. They share one id
 * through law_flagship_add_attendee_modal_id().
 *
 * A dialog rather than a form sitting open at the foot of the page (Denis,
 * 10 September 2026): the page's subject is the review queue, and giving
 * somebody a free place is an occasional, deliberate act that deserves the
 * same "are you sure" the Approve and Decline buttons get.
 *
 * It asks for country, accessibility and dietary as well (Denis, 11 September
 * 2026, parts/events/attendee-profile-fields.php): the delegate list and the
 * exports read those columns live from the attendee's profile, and a speaker or
 * VIP put on the list here never filled a registration form in.
 *
 * The whole form lives INSIDE the .law-modal, so law-modal.js supplies the
 * open, close and focus-trap behaviour, and booking-form.js submits it over
 * fetch with the plain-POST fallback. Without JavaScript the dialog stays
 * hidden and the <details> fallback below exposes exactly the same form, so
 * the feature never depends on the script.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! law_user_is_committee() ) {
	return;
}

/**
 * The fields, printed into both the dialog and the no-JS fallback.
 *
 * @param string $law_fa_prefix     Unique per copy: the two "Other" boxes in the
 *                                  shared profile block are toggled by id.
 * @param bool   $law_fa_show_other Render those boxes open (the no-JS copy, where
 *                                  no script is going to reveal them).
 */
$law_fa_fields = static function ( $law_fa_prefix, $law_fa_show_other = false ) {
	?>
	<?php
	// Labels WRAP their inputs rather than using for/id. The same fields are
	// printed twice on this page (the dialog and the no-JS fallback), and
	// ids would collide — which silently breaks both labels, not one.
	// It is also the vocabulary parts/events/event-form-fields.php uses.
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
			<span class="law-form-hint"><?php esc_html_e( 'An account is created if they do not have one, with a link to set their password.', 'law' ); ?></span>
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
	// columns live from the attendee's profile, and a speaker, sponsor or VIP
	// put on the list here never filled a registration form in.
	get_template_part(
		'parts/events/attendee-profile-fields',
		null,
		array(
			'id_prefix'  => $law_fa_prefix,
			'show_other' => $law_fa_show_other,
			'note'       => __( 'Whatever they told you. They can change any of this themselves from their profile.', 'law' ),
		)
	);
	?>

	<p class="law-form-field">
		<label>
			<input type="checkbox" name="law_press" value="1">
			<?php esc_html_e( 'Press pass', 'law' ); ?>
		</label>
		<span class="law-form-hint"><?php esc_html_e( 'Marks the row and the exports, for the on-site team.', 'law' ); ?></span>
	</p>
	<?php
};

/** The hidden inputs every copy of the form needs. */
$law_fa_hidden = static function () {
	?>
	<input type="hidden" name="action" value="law_flagship_add_attendee">
	<?php wp_nonce_field( 'law_flagship_add_attendee' ); ?>
	<?php law_events_honeypot_field(); ?>
	<?php
};
?>

<section class="law-flagship-bookings__add">
	<div class="law-modal" id="<?php echo esc_attr( law_flagship_add_attendee_modal_id() ); ?>" hidden>
		<div class="law-modal__overlay" data-law-modal-close></div>
		<div class="law-modal__dialog law-modal__dialog--wide" role="dialog" aria-modal="true" aria-labelledby="law-flagship-add-title" tabindex="-1">
			<button type="button" class="law-modal__close" data-law-modal-close aria-label="<?php esc_attr_e( 'Close', 'law' ); ?>">&times;</button>
			<h2 class="law-modal__title" id="law-flagship-add-title"><?php esc_html_e( 'Add an attendee without payment', 'law' ); ?></h2>

			<form class="law-event-form law-event-form--light law-booking-form" method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-law-booking-rows>
				<?php $law_fa_hidden(); ?>

				<p class="law-modal__copy">
					<?php esc_html_e( 'They will be given a confirmed place immediately, with nothing to pay. Nothing is charged and no payment details are asked for. If they do not already have an account, one is created and they are emailed a link to set their password.', 'law' ); ?>
				</p>

				<?php $law_fa_fields( 'law-fa' ); ?>

				<p class="law-modal__actions">
					<button type="button" class="button second" data-law-modal-close><?php esc_html_e( 'Cancel', 'law' ); ?></button>
					<button type="submit" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Adding…', 'law' ); ?>">
						<?php esc_html_e( 'Add the attendee', 'law' ); ?>
					</button>
				</p>
			</form>
		</div>
	</div>

	<?php
	// The no-JS path: the same form, behind a native disclosure so it is not
	// sitting open on the page either.
	?>
	<noscript>
		<details class="law-flagship-bookings__add-fallback">
			<summary><?php esc_html_e( 'Add an attendee without payment', 'law' ); ?></summary>
			<form class="law-event-form law-event-form--light" method="post"
				action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php $law_fa_hidden(); ?>
				<?php $law_fa_fields( 'law-fa-nojs', true ); ?>
				<p class="law-form-buttons">
					<button type="submit" class="button orange"><?php esc_html_e( 'Add the attendee', 'law' ); ?></button>
				</p>
			</form>
		</details>
	</noscript>
</section>
