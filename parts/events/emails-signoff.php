<?php
/**
 * The shared sign-off panel, for templates/account-dashboard-emails.php.
 *
 * Sits under the notifications table rather than inside any one email's
 * editor, because it is not one email's wording: it is the last thing all of
 * them say, stored once and appended by law_events_email_render_body(). The
 * wp-admin Emails screen carries the same panel in the same place
 * (law_events_emails_signoff_card()), and both write through
 * law_events_email_signoff_save().
 *
 * Re-checks the committee gate for itself rather than trusting its caller, as
 * parts/events/emails-list.php does: the part is reachable on its own the
 * moment anything else calls get_template_part().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'law_user_is_committee' ) || ! law_user_is_committee() ) {
	return;
}

$law_es_signoff = law_events_email_signoff();
?>

<div class="law-emails__signoff">
	<h2><?php esc_html_e( 'Sign-off', 'law' ); ?></h2>

	<p class="law-emails__signoff-intro">
		<?php esc_html_e( 'The closing lines added to the end of every notification above, including any you have reworded and any an event sets for its own booking confirmation. It is kept here rather than typed into each message, so it only ever has to be changed once.', 'law' ); ?>
	</p>

	<?php
	// A .law-booking-form, so it posts over fetch and falls back to a plain
	// POST without JavaScript, like every other form on the dashboards. Its own
	// admin-post action: the sign-off belongs to no single notification.
	?>
	<form class="law-event-form law-event-form--light law-booking-form law-emails-form law-emails-signoff-form" method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="law_email_signoff">
		<?php wp_nonce_field( 'law_email_signoff' ); ?>
		<?php law_events_honeypot_field(); ?>

		<p class="law-form-field">
			<label for="law-em-signoff"><?php esc_html_e( 'Sign-off', 'law' ); ?></label>
			<textarea id="law-em-signoff" name="law_email_signoff" rows="4"><?php echo esc_textarea( $law_es_signoff ); ?></textarea>
			<span class="law-form-hint">
				<?php esc_html_e( 'Added after a blank line, so it reads as its own paragraph. Tags such as {site_name} work here exactly as they do in a message. Leave it empty to send no sign-off at all.', 'law' ); ?>
			</span>
		</p>

		<p class="law-form-buttons">
			<button type="submit" class="button orange" data-law-busy="<?php esc_attr_e( 'Saving…', 'law' ); ?>">
				<?php esc_html_e( 'Save sign-off', 'law' ); ?>
			</button>
		</p>
	</form>
</div>
