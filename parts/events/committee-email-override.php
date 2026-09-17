<?php
/**
 * This event's own booking confirmation, for templates/account-dashboard.php
 * at ?event=<id>&law_email=1.
 *
 * Args: event_id (int, a law_event post ID).
 *
 * Re-checks the committee gate for itself rather than trusting its caller, as
 * parts/events/emails-manage.php and discounts-manage.php do: the part is
 * reachable on its own the moment anything else calls get_template_part().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'law_user_is_committee' ) || ! law_user_is_committee() ) {
	echo '<p>' . esc_html__( 'This dashboard is for the LAW committee.', 'law' ) . '</p>';
	return;
}

$law_eo_event = absint( $args['event_id'] ?? 0 );
$law_eo_map   = law_event_override_slug_map( $law_eo_event );

if ( ! $law_eo_map ) {
	echo '<p class="law-form-notice is-error" role="alert">' . esc_html__( 'That event could not be found, or it cannot have its own confirmation.', 'law' ) . '</p>';
	return;
}

$law_eo_title  = get_the_title( $law_eo_event );
$law_eo_on     = law_event_override_active( $law_eo_event );
$law_eo_fields = law_event_override_fields( $law_eo_event );
$law_eo_draft  = law_event_override_take_state( $law_eo_event );
$law_eo_tags   = array_keys( law_events_email_placeholders( 0 ) );
$law_eo_notice = sanitize_key( $_GET['law_notice'] ?? '' );
$law_eo_all    = law_event_override_notices();

// A refused save comes back through a one-shot transient rather than losing
// what was typed, keyed by event so opening the next one inherits nothing.
foreach ( $law_eo_draft as $law_eo_which => $law_eo_row ) {
	if ( isset( $law_eo_fields[ $law_eo_which ] ) ) {
		$law_eo_fields[ $law_eo_which ]['subject'] = (string) ( $law_eo_row['subject'] ?? '' );
		$law_eo_fields[ $law_eo_which ]['body']    = (string) ( $law_eo_row['body'] ?? '' );
	}
}

get_template_part(
	'parts/layout/back-link',
	null,
	array(
		'url'   => law_committee_event_url( $law_eo_event ),
		'label' => __( 'Back to the event', 'law' ),
	)
);
?>

<div class="grid-x grid-padding-x">
	<div class="large-12 cell">
		<h2><?php echo esc_html( sprintf( /* translators: %s: event title. */ __( 'Booking confirmation for %s', 'law' ), $law_eo_title ) ); ?></h2>

		<?php if ( isset( $law_eo_all[ $law_eo_notice ] ) ) : ?>
			<div class="law-form-notice <?php echo esc_attr( $law_eo_all[ $law_eo_notice ][0] ); ?>" role="status">
				<?php echo esc_html( $law_eo_all[ $law_eo_notice ][1] ); ?>
			</div>
		<?php endif; ?>

		<?php if ( ! $law_eo_on ) : ?>
			<?php
			// Reachable by a bookmark or a hand-typed URL with the tick off. The
			// wording is still editable, because writing it before switching it
			// on is a reasonable order to work in; it just is not being sent.
			?>
			<div class="law-form-notice is-warning" role="status">
				<?php esc_html_e( 'This event is sending the standard confirmation. Tick "Override booking confirmation" on the event to start using the wording below.', 'law' ); ?>
			</div>
		<?php endif; ?>

		<p><?php esc_html_e( 'This replaces the standard confirmation for this event only. Every other event on the programme keeps the standard wording, and editing the standard wording later will not change what you write here.', 'law' ); ?></p>
	</div>
</div>

<div class="grid-x grid-padding-x">
	<div class="large-12 cell">
		<form class="law-event-form law-event-form--light law-booking-form law-emails-form" method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_event_email_override">
			<input type="hidden" name="law_event_id" value="<?php echo esc_attr( (string) $law_eo_event ); ?>">
			<?php wp_nonce_field( 'law_event_email_override' ); ?>
			<?php law_events_honeypot_field(); ?>

			<?php foreach ( $law_eo_fields as $law_eo_which => $law_eo_field ) : ?>
				<fieldset>
					<legend>
						<?php
						echo 'free' === $law_eo_which
							? esc_html__( 'When there is nothing to pay', 'law' )
							: esc_html__( 'The message', 'law' );
						?>
					</legend>

					<?php if ( 'free' === $law_eo_which ) : ?>
						<?php
						// Receptions only. The paid wording quotes an amount and
						// links a VAT invoice, and a free place has neither, so the
						// two are genuinely separate messages rather than one with
						// a blank in it (Denis, 16 September 2026).
						?>
						<p class="law-form-hint"><?php esc_html_e( 'Sent instead of the message above when a place costs nothing, so it can leave out the amount paid and the VAT invoice.', 'law' ); ?></p>
					<?php endif; ?>

					<p class="law-form-field">
						<label for="law-eo-subject-<?php echo esc_attr( $law_eo_which ); ?>"><?php esc_html_e( 'Subject', 'law' ); ?></label>
						<input type="text" id="law-eo-subject-<?php echo esc_attr( $law_eo_which ); ?>"
							name="law_email_override[<?php echo esc_attr( $law_eo_which ); ?>][subject]"
							value="<?php echo esc_attr( $law_eo_field['subject'] ); ?>">
					</p>

					<div class="law-form-field">
						<label for="law-eo-body-<?php echo esc_attr( $law_eo_which ); ?>"><?php esc_html_e( 'Body', 'law' ); ?></label>
						<?php
						// The same editor and the same allowlist as the event
						// description and the site-wide notifications. Without
						// JavaScript it stays a working plain textarea.
						law_rich_text_field(
							array(
								'name'     => 'law_email_override[' . $law_eo_which . '][body]',
								'id'       => 'law-eo-body-' . $law_eo_which,
								'value'    => $law_eo_field['body'],
								'rows'     => 14,
								'required' => __( 'Please write the message.', 'law' ),
							)
						);
						?>
						<span class="law-form-hint"><?php esc_html_e( 'Bold, italics, lists, headings and links are kept; the site\'s email design adds the branding around them.', 'law' ); ?></span>
					</div>
				</fieldset>
			<?php endforeach; ?>

			<fieldset>
				<legend><?php esc_html_e( 'Tags you can use', 'law' ); ?></legend>
				<div class="law-form-field law-emails__tags">
					<span class="law-form-hint"><?php esc_html_e( 'Paste one of these into a subject or a message and it is replaced with the real value when the email is sent.', 'law' ); ?></span>
					<span class="law-form-hint"><?php esc_html_e( 'Two of them describe the person reading, not the whole booking: {attendee_list} and {party_note} cover that person\'s own place, and {account_note} appears only for someone a colleague booked for.', 'law' ); ?></span>
					<ul>
						<?php foreach ( $law_eo_tags as $law_eo_tag ) : ?>
							<li><code><?php echo esc_html( $law_eo_tag ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</fieldset>

			<p class="law-form-buttons">
				<button type="submit" class="button orange" data-law-busy="<?php esc_attr_e( 'Saving…', 'law' ); ?>">
					<?php esc_html_e( 'Save changes', 'law' ); ?>
				</button>
				<?php if ( law_event_override_written( $law_eo_event ) ) : ?>
					<?php
					// Behind the shared confirm dialog, like every other
					// destructive action in the module. Without JS it is a plain
					// submit carrying its own name and value, which is why those
					// attributes are on the opener as well as the dialog button.
					?>
					<button type="submit" class="button second" name="law_email_override_reset" value="1"
						data-law-modal-open="law-modal-email-override-reset"
						data-law-busy="<?php esc_attr_e( 'Resetting…', 'law' ); ?>">
						<?php esc_html_e( 'Reset to the standard wording', 'law' ); ?>
					</button>
				<?php endif; ?>
				<a class="button second" href="<?php echo esc_url( law_committee_event_url( $law_eo_event ) ); ?>"><?php esc_html_e( 'Cancel', 'law' ); ?></a>
			</p>

			<?php
			// Outside the buttons paragraph, inside the form: the dialog is a
			// <div> (a <p> may not contain one) and its submit has to post the
			// rest of the form with it.
			if ( law_event_override_written( $law_eo_event ) ) {
				get_template_part(
					'parts/layout/modal',
					null,
					array(
						'id'      => 'law-modal-email-override-reset',
						'title'   => __( 'Go back to the standard wording?', 'law' ),
						'copy'    => array(
							sprintf(
								/* translators: %s: the event title. */
								__( 'This discards the confirmation written for "%s". It will send the same confirmation as every other event again.', 'law' ),
								$law_eo_title
							),
							__( 'Emails already sent are not affected, and you can write it again afterwards.', 'law' ),
						),
						'confirm' => array(
							'label' => __( 'Reset it', 'law' ),
							'name'  => 'law_email_override_reset',
							'value' => '1',
							'class' => 'button orange',
							'busy'  => __( 'Resetting…', 'law' ),
						),
						'close'   => __( 'Keep my changes', 'law' ),
					)
				);
			}
			?>
		</form>
	</div>
</div>
