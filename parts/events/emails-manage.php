<?php
/**
 * The editor for one notification, for templates/account-dashboard-emails.php.
 *
 * Args: slug (string, a law_events_email_registry() key).
 *
 * Re-checks the committee gate for itself rather than trusting its caller, as
 * parts/events/discounts-manage.php and flagship-manage.php do: the part is
 * reachable on its own the moment anything else calls get_template_part().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'law_user_is_committee' ) || ! law_user_is_committee() ) {
	echo '<p>' . esc_html__( 'This dashboard is for the LAW committee.', 'law' ) . '</p>';
	return;
}

$law_em_slug  = sanitize_key( (string) ( $args['slug'] ?? '' ) );
$law_em_email = $law_em_slug ? law_events_email( $law_em_slug ) : null;

if ( ! $law_em_email ) {
	echo '<p class="law-form-notice is-error" role="alert">' . esc_html__( 'That notification could not be found.', 'law' ) . '</p>';
	return;
}

// A test send persists nothing and a refused save stores nothing, so the draft
// either was rendered from comes back through a one-shot transient rather than
// being thrown away by the redirect. Keyed by slug, so opening the next
// notification does not inherit this one's draft.
$law_em_state  = law_emails_dashboard_state( $law_em_slug );
$law_em_draft  = $law_em_state['input'];
$law_em_errors = $law_em_state['errors'];

$law_em_values = array(
	'subject' => isset( $law_em_draft['subject'] ) ? (string) $law_em_draft['subject'] : (string) $law_em_email['subject'],
	'body'    => isset( $law_em_draft['body'] ) ? (string) $law_em_draft['body'] : (string) $law_em_email['body'],
	'active'  => array_key_exists( 'active', $law_em_draft ) ? ! empty( $law_em_draft['active'] ) : ! empty( $law_em_email['active'] ),
);

// 'to' is editable only where the registry names fixed addresses. The dynamic
// audiences are resolved per event at send time (law_events_email_recipients()),
// so there is nothing here to type into.
$law_em_fixed_to = is_array( $law_em_email['to'] );
$law_em_to       = $law_em_fixed_to
	? implode( ', ', isset( $law_em_draft['to'] ) ? (array) $law_em_draft['to'] : (array) $law_em_email['to'] )
	: '';

$law_em_customised = law_events_email_is_customised( $law_em_slug );
$law_em_tags       = array_keys( law_events_email_placeholders( 0 ) );
?>

<?php
get_template_part(
	'parts/layout/back-link',
	null,
	array(
		'url'   => law_emails_dashboard_url(),
		'label' => __( 'Back to all emails', 'law' ),
	)
);
?>

<h1 class="law-dashboard__title"><?php echo esc_html( $law_em_email['name'] ); ?></h1>

<p class="law-emails__trigger">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: what makes this notification send. */
			__( 'Sent when: %s', 'law' ),
			law_events_email_trigger_label( $law_em_email )
		)
	);
	?>
</p>

<?php if ( law_events_email_has_unresolved_tags( $law_em_email ) ) : ?>
	<p class="law-form-notice is-error" role="alert">
		<?php esc_html_e( 'This wording still contains tags carried over from the old forms that nothing can fill in, such as {Event title:12.3} or {all_fields}. Anyone receiving it would see them exactly as written. Please replace them with one of the tags listed under the body, or remove them.', 'law' ); ?>
	</p>
<?php endif; ?>

<div class="grid-x grid-padding-x">
	<div class="large-12 cell">
		<form class="law-event-form law-event-form--light law-booking-form law-emails-form" method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_email_manage">
			<input type="hidden" name="law_email_slug" value="<?php echo esc_attr( $law_em_slug ); ?>">
			<?php wp_nonce_field( 'law_email_manage' ); ?>
			<?php law_events_honeypot_field(); ?>

			<fieldset>
				<legend><?php esc_html_e( 'The message', 'law' ); ?></legend>

				<p class="law-form-field">
					<label>
						<input type="checkbox" name="law_email[active]" value="1" <?php checked( $law_em_values['active'] ); ?>>
						<?php esc_html_e( 'Send this notification', 'law' ); ?>
					</label>
					<span class="law-form-hint"><?php esc_html_e( 'Untick to stop it being sent at all.', 'law' ); ?></span>
				</p>

				<?php if ( $law_em_fixed_to ) : ?>
					<p class="law-form-field<?php echo isset( $law_em_errors['to'] ) ? ' is-invalid' : ''; ?>">
						<label for="law-em-to"><?php esc_html_e( 'Recipients', 'law' ); ?></label>
						<input type="text" id="law-em-to" name="law_email[to]"
							value="<?php echo esc_attr( $law_em_to ); ?>" autocomplete="off" inputmode="email">
						<span class="law-form-hint"><?php esc_html_e( 'One or more email addresses, separated by commas. To stop the email being sent at all, untick the box above rather than clearing this.', 'law' ); ?></span>
					</p>
				<?php else : ?>
					<p class="law-form-field">
						<span class="law-form-label"><?php esc_html_e( 'Recipients', 'law' ); ?></span>
						<span class="law-emails__recipients"><?php echo esc_html( law_events_email_recipients_label( $law_em_email ) ); ?></span>
						<span class="law-form-hint">
							<?php
							echo 'committee' === $law_em_email['to']
								? esc_html__( 'Worked out for each event as it sends. The committee address list is a site setting, so it is not editable here.', 'law' )
								: esc_html__( 'Worked out for each event as it sends, so there is nothing to type here.', 'law' );
							?>
						</span>
					</p>
				<?php endif; ?>

				<p class="law-form-field">
					<label for="law-em-subject"><?php esc_html_e( 'Subject', 'law' ); ?></label>
					<input type="text" id="law-em-subject" name="law_email[subject]"
						value="<?php echo esc_attr( $law_em_values['subject'] ); ?>">
				</p>

				<?php
				// The same editor as the event description, speaker biography
				// and session description (functions/events/rich-text.php), and
				// the same allowlist behind it. Without JavaScript it stays a
				// working plain textarea, which is what every other rich field
				// in the module falls back to.
				?>
				<div class="law-form-field">
					<label for="law-em-body"><?php esc_html_e( 'Body', 'law' ); ?></label>
					<?php
					law_rich_text_field(
						array(
							'name'  => 'law_email[body]',
							'id'    => 'law-em-body',
							'value' => $law_em_values['body'],
							'rows'  => 14,
							// Caught in the browser before the round trip, the
							// way every other rich field in the module is.
							'required' => __( 'Please write the message.', 'law' ),
						)
					);
					?>
					<span class="law-form-hint"><?php esc_html_e( 'Bold, italics, lists, headings and links are kept; the site\'s email design adds the branding around them.', 'law' ); ?></span>
				</div>

				<div class="law-form-field law-emails__tags">
					<span class="law-form-label"><?php esc_html_e( 'Tags you can use', 'law' ); ?></span>
					<span class="law-form-hint"><?php esc_html_e( 'Paste one of these into the subject or the body and it is replaced with the real value when the email is sent.', 'law' ); ?></span>
					<ul>
						<?php foreach ( $law_em_tags as $law_em_tag ) : ?>
							<li><code><?php echo esc_html( $law_em_tag ); ?></code></li>
						<?php endforeach; ?>
					</ul>
				</div>
			</fieldset>

			<p class="law-form-buttons">
				<button type="submit" class="button orange" data-law-busy="<?php esc_attr_e( 'Saving…', 'law' ); ?>">
					<?php esc_html_e( 'Save changes', 'law' ); ?>
				</button>
				<?php
				// Send test renders what is on screen and saves nothing, so the
				// wording can be read in a real inbox before anybody commits to
				// it. The draft survives the reload.
				?>
				<button type="submit" class="button second" name="law_email_test" value="1"
					data-law-busy="<?php esc_attr_e( 'Sending…', 'law' ); ?>">
					<?php esc_html_e( 'Send a test to me', 'law' ); ?>
				</button>
				<?php if ( $law_em_customised ) : ?>
					<?php
					// Behind the shared confirm dialog (parts/layout/modal.php), like
					// every other destructive action in the module. Without JS it is a
					// plain submit carrying its own name and value, which is why those
					// attributes are on the opener as well as on the dialog's button.
					?>
					<button type="submit" class="button second" name="law_email_reset" value="1"
						data-law-modal-open="law-modal-email-reset"
						data-law-busy="<?php esc_attr_e( 'Resetting…', 'law' ); ?>">
						<?php esc_html_e( 'Reset to the standard wording', 'law' ); ?>
					</button>
				<?php endif; ?>
				<a class="button second" href="<?php echo esc_url( law_emails_dashboard_url() ); ?>"><?php esc_html_e( 'Cancel', 'law' ); ?></a>
			</p>

			<?php
			// Outside the buttons paragraph, inside the form: the dialog is a <div>
			// (a <p> may not contain one) and its submit has to post the rest of
			// the form with it.
			if ( $law_em_customised ) {
				get_template_part(
					'parts/layout/modal',
					null,
					array(
						'id'      => 'law-modal-email-reset',
						'title'   => __( 'Go back to the standard wording?', 'law' ),
						'copy'    => array(
							sprintf(
								/* translators: %s: the notification's name. */
								__( 'This discards every change anyone has made to "%s" and restores the wording it ships with.', 'law' ),
								$law_em_email['name']
							),
							__( 'Emails already sent are not affected, and you can edit it again afterwards.', 'law' ),
						),
						'confirm' => array(
							'label' => __( 'Reset it', 'law' ),
							'name'  => 'law_email_reset',
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
