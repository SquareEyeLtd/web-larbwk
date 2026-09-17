<?php
/**
 * The Emails admin screen (top-level menu): WooCommerce-style management of the module's notifications
 * (EVENTS_4.1_REBUILD.md §3.8). Defaults live in code; edits are stored as
 * overrides and take precedence; Reset returns to the code default.
 * Scope: events-module emails only — form 7 (Contact) stays in the GF admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	// Top-level menu directly under Events (6). Same slug as the old
	// LAW > Emails submenu, so admin.php?page=law-events-emails links survive.
	add_menu_page( 'Events emails', 'Emails', 'manage_options', 'law-events-emails', 'law_events_emails_page', 'dashicons-email-alt', 7 );
}, 999 );

function law_events_emails_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to access this page.' );
	}

	$slug = sanitize_key( $_GET['email'] ?? '' );

	if ( isset( $_POST['law_test_mode_nonce'] ) ) {
		check_admin_referer( 'law_events_test_mode', 'law_test_mode_nonce' );
		law_events_emails_handle_test_mode_post();
	}

	if ( isset( $_POST['law_signoff_nonce'] ) ) {
		check_admin_referer( 'law_events_email_signoff', 'law_signoff_nonce' );
		law_events_emails_handle_signoff_post();
	}

	if ( isset( $_POST['law_email_nonce'] ) ) {
		check_admin_referer( 'law_email_edit', 'law_email_nonce' );
		law_events_emails_handle_post( $slug );
	}

	if ( $slug && law_events_email( $slug ) ) {
		law_events_emails_edit_screen( $slug );
	} else {
		law_events_emails_list_screen();
	}
}

function law_events_emails_list_screen() {
	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	echo '<div class="wrap"><h1>Events emails</h1>';
	echo '<p>Events-module notifications. The contact form\'s emails stay in the Gravity Forms notifications admin.</p>';
	law_events_emails_test_mode_card();
	echo '<table class="widefat striped"><thead><tr><th>Notification</th><th>Trigger</th><th>Recipients</th><th>Status</th><th></th></tr></thead><tbody>';
	foreach ( law_events_email_registry() as $slug => $definition ) {
		$merged     = law_events_email( $slug );
		$customised = isset( $overrides[ $slug ] );
		$to         = law_events_email_recipients_label( $merged );
		// Migrated GF merge tags the renderer cannot resolve need eyes.
		$unmapped = law_events_email_has_unresolved_tags( $merged );
		printf(
			'<tr><td><strong><a href="%s">%s</a></strong>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%1$s">Edit</a></td></tr>',
			esc_url( add_query_arg( array( 'page' => 'law-events-emails', 'email' => $slug ), admin_url( 'admin.php' ) ) ),
			esc_html( $merged['name'] ),
			$customised ? ' <span class="law-badge">customised</span>' : '',
			$unmapped ? ' <span class="law-badge" style="border-color:#b32d2e;color:#b32d2e;background:#fcf0f1">review tags</span>' : '',
			esc_html( law_events_email_trigger_label( $merged ) ),
			esc_html( $to ),
			$merged['active'] ? '<span style="color:#00a32a">Active</span>' : '<span style="color:#757575">Inactive</span>'
		);
	}
	echo '</tbody></table>';
	law_events_emails_signoff_card();
	echo '</div>';
}

function law_events_emails_edit_screen( $slug ) {
	$email     = law_events_email( $slug );
	$fixed_to  = is_array( $email['to'] );
	$back      = add_query_arg( array( 'page' => 'law-events-emails' ), admin_url( 'admin.php' ) );
	$tags      = array_keys( law_events_email_placeholders( 0 ) );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( $email['name'] ); ?></h1>
		<p><a href="<?php echo esc_url( $back ); ?>">← All emails</a> · Trigger: <strong><?php echo esc_html( law_events_email_trigger_label( $email ) ); ?></strong></p>
		<form method="post" style="max-width:760px">
			<?php wp_nonce_field( 'law_email_edit', 'law_email_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row">Active</th>
					<td><label><input type="checkbox" name="active" value="1" <?php checked( ! empty( $email['active'] ) ); ?>> Send this notification</label></td></tr>
				<?php if ( $fixed_to ) : ?>
				<tr><th scope="row"><label for="law-email-to">Recipients</label></th>
					<td><input type="text" id="law-email-to" name="to" class="large-text" value="<?php echo esc_attr( implode( ', ', $email['to'] ) ); ?>">
					<p class="description">Comma-separated addresses.</p></td></tr>
				<?php else : ?>
				<tr><th scope="row">Recipients</th>
					<td><code><?php echo esc_html( $email['to'] ); ?></code> (dynamic — resolved per event<?php echo 'committee' === $email['to'] ? '; the list lives in Events settings' : ''; ?>)</td></tr>
				<?php endif; ?>
				<tr><th scope="row"><label for="law-email-subject">Subject</label></th>
					<td><input type="text" id="law-email-subject" name="subject" class="large-text" value="<?php echo esc_attr( $email['subject'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="law-email-body">Body</label></th>
					<td>
						<?php
						// The button set and the tag allowlist come from
						// law_rich_text_settings(), the same policy the front-end
						// screen's editor and the event description run on, so
						// the two screens cannot offer different formatting. Not
						// 'teeny': its fixed button row has no headings, and the
						// allowlist does.
						$editor_settings = law_rich_text_settings();
						wp_editor(
							$email['body'],
							'law-email-body',
							array(
								'textarea_name' => 'body',
								'textarea_rows' => 14,
								'media_buttons' => false,
								'quicktags'     => false,
								'tinymce'       => array(
									'toolbar1'       => $editor_settings['toolbar'],
									'toolbar2'       => '',
									'block_formats'  => $editor_settings['blockFormats'],
									'valid_elements' => $editor_settings['validElements'],
								),
							)
						);
						?>
						<p class="description">Bold, italics, lists, headings and links are kept; anything else is dropped when you save, and the site-wide email wrapper adds the branding. Available tags:<br>
						<code><?php echo esc_html( implode( ' ', $tags ) ); ?></code></p>
					</td></tr>
			</table>
			<p>
				<?php submit_button( 'Save email', 'primary', 'save', false ); ?>
				<?php submit_button( 'Send test to me', 'secondary', 'send_test', false ); ?>
				<?php submit_button( 'Reset to default', 'delete', 'reset', false, array( 'onclick' => "return confirm('Discard the customised version and return to the code default?');" ) ); ?>
			</p>
		</form>
	</div>
	<?php
}

function law_events_emails_handle_post( $slug ) {
	// The option is never written here: both screens go through the shared
	// helpers in notifications.php, so an edit made in wp-admin and one made on
	// the committee's front-end page cannot come to mean different things.
	if ( isset( $_POST['reset'] ) ) {
		law_events_email_reset_override( $slug );
		echo '<div class="notice notice-success"><p>Reset to the code default.</p></div>';
		return;
	}

	$input = array(
		'subject' => wp_unslash( $_POST['subject'] ?? '' ),
		'body'    => wp_unslash( $_POST['body'] ?? '' ),
		'active'  => ! empty( $_POST['active'] ),
	) + ( isset( $_POST['to'] ) ? array( 'to' => wp_unslash( $_POST['to'] ) ) : array() );

	$override = law_events_email_override_from_input( $slug, $input );
	if ( null === $override ) {
		echo '<div class="notice notice-error"><p>That notification could not be found.</p></div>';
		return;
	}

	// An emptied editor, refused rather than stored: the notification would
	// otherwise send a subject line over a blank page.
	if ( ! law_events_email_body_survived( $override['body'] ) ) {
		echo '<div class="notice notice-error"><p>The message is empty, so nothing was saved. To stop this notification being sent, untick "Send this notification" instead.</p></div>';
		return;
	}

	// Nothing typed into Recipients was a usable address, so the builder kept
	// the stored ones. Saying so beats reporting "Email saved" over a screen
	// that no longer matches what was stored.
	if ( ! law_events_email_recipients_survived( $slug, $input, $override ) ) {
		echo '<div class="notice notice-error"><p>None of those recipients is a valid email address, so nothing was saved. Separate addresses with commas, or untick "Send this notification" to stop it being sent at all.</p></div>';
		return;
	}

	// Send test renders the values AS TYPED without persisting anything, so
	// admins can preview safely before deciding to save.
	if ( isset( $_POST['send_test'] ) ) {
		$test = law_events_email_send_test( $override );
		echo $test['sent']
			? '<div class="notice notice-success"><p>Test sent to ' . esc_html( $test['email'] ) . ( $test['event_id'] ? ' using event "' . esc_html( $test['event_title'] ) . '"' : ' (no events exist yet, placeholders were blank)' ) . '. Nothing was saved: use Save email to keep these values.</p></div>'
			: '<div class="notice notice-error"><p>Send failed.</p></div>';
		return;
	}

	law_events_email_save_override( $slug, $override );
	echo '<div class="notice notice-success"><p>Email saved.</p></div>';
}

/* The shared sign-off ______________________________________________________ */

/**
 * Notice for the sign-off form, held between the POST handler and the render
 * so it prints inside the page wrap rather than above the heading. The same
 * device the test-mode card uses, and for the same reason.
 *
 * @param array|null $set Message to store (['type' => ..., 'text' => ...]).
 * @return array|null
 */
function law_events_emails_signoff_notice( $set = null ) {
	static $notice = null;
	if ( null !== $set ) {
		$notice = $set;
	}
	return $notice;
}

/**
 * The sign-off panel at the foot of the emails list.
 *
 * It sits under the table rather than on each email's edit screen because it
 * is not one email's wording: it is the last thing all of them say. Editing it
 * here changes every notification at once, which is the point.
 */
function law_events_emails_signoff_card() {
	$signoff = law_events_email_signoff();
	$notice  = law_events_emails_signoff_notice();
	?>
	<h2>Sign-off</h2>
	<p>The closing lines added to the end of <strong>every</strong> notification above, including any you have reworded and any an event sets for its own booking confirmation. It is stored here rather than typed into each message, so it only ever has to be changed once.</p>
	<?php if ( $notice ) : ?>
		<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> inline"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
	<?php endif; ?>
	<form method="post" style="max-width:760px">
		<?php wp_nonce_field( 'law_events_email_signoff', 'law_signoff_nonce' ); ?>
		<p>
			<label for="law-email-signoff" class="screen-reader-text">Sign-off</label>
			<textarea id="law-email-signoff" name="signoff" rows="4" class="large-text code"><?php echo esc_textarea( $signoff ); ?></textarea>
		</p>
		<p class="description">
			Added after a blank line, so it reads as its own paragraph. Tags such as <code>{site_name}</code> work here exactly as they do in a message body. Leave it empty to send no sign-off at all.
		</p>
		<p><?php submit_button( 'Save sign-off', 'secondary', 'save_signoff', false ); ?></p>
	</form>
	<?php
}

/** Save the sign-off. Both screens write through law_events_email_signoff_save(). */
function law_events_emails_handle_signoff_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stored = law_events_email_signoff_save( wp_unslash( $_POST['signoff'] ?? '' ) );

	law_events_emails_signoff_notice(
		array(
			'type' => 'success',
			'text' => '' === $stored
				? 'Sign-off cleared. Notifications will now end with whatever their own wording ends with.'
				: 'Sign-off saved. It applies from the next email sent onwards.',
		)
	);
}

/* Test mode ________________________________________________________________ */

/**
 * Notice for the test-mode form, held between the POST handler and the render
 * so it prints inside the page wrap rather than above the heading.
 *
 * @param array|null $set Message to store (['type' => ..., 'text' => ...]).
 * @return array|null
 */
function law_events_emails_test_mode_notice( $set = null ) {
	static $notice = null;
	if ( null !== $set ) {
		$notice = $set;
	}
	return $notice;
}

/** The Enable test mode panel at the top of the emails list. */
function law_events_emails_test_mode_card() {
	$settings = law_events_test_mode();
	$active   = law_events_is_test_mode();
	$notice   = law_events_emails_test_mode_notice();
	?>
	<div class="law-test-mode <?php echo $active ? 'is-active' : ''; ?>">
		<?php if ( $notice ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> inline"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
		<?php endif; ?>
		<form method="post" data-law-test-mode>
			<?php wp_nonce_field( 'law_events_test_mode', 'law_test_mode_nonce' ); ?>
			<p class="law-test-mode__toggle">
				<label>
					<input type="checkbox" name="test_mode_enabled" value="1" data-law-test-toggle <?php checked( $settings['enabled'] ); ?>>
					<strong>Enable test mode</strong>
				</label>
				<?php if ( $active ) : ?>
					<span class="law-badge law-badge--warning">On until <?php echo esc_html( $settings['no_expiry'] ? 'switched off' : law_events_test_mode_expiry_label( $settings['expires_at'] ) ); ?>: all email goes to <?php echo esc_html( $settings['email'] ); ?></span>
				<?php endif; ?>
			</p>
			<div class="law-test-mode__fields" <?php echo $settings['enabled'] ? '' : 'hidden'; ?> data-law-test-fields>
				<p>
					<label for="law-test-email"><strong>Deliver all to email below</strong></label><br>
					<input type="email" id="law-test-email" name="test_mode_email" class="regular-text" value="<?php echo esc_attr( $settings['email'] ); ?>" autocomplete="off" data-law-test-email>
					<span class="law-test-mode__status" data-law-test-status aria-live="polite"></span>
				</p>
				<p class="description">
					While test mode is on, <strong>every</strong> email this site sends (events notifications, account and password emails, and the contact form) is delivered to this address instead of its real recipients. Subjects are prefixed <code>[TEST MODE]</code> and the intended recipients are listed at the top of the message. It switches itself off automatically after <?php echo esc_html( law_events_test_mode_duration_label() ); ?>, and a warning appears on every admin screen while it is on. Saving this card again restarts the <?php echo esc_html( law_events_test_mode_duration_label() ); ?> if you need longer.
				</p>
				<p class="law-test-mode__no-expiry">
					<label>
						<input type="checkbox" name="test_mode_no_expiry" value="1" <?php checked( $settings['no_expiry'] ); ?>>
						Keep test mode on until I switch it off (disable the <?php echo esc_html( law_events_test_mode_duration_label() ); ?> auto-expiry)
					</label><br>
					<span class="description">For a testing environment that should stay in test mode for days. Remember: password resets for <strong>every</strong> account keep landing at the address above the whole time, so leave this unticked for a live-site rehearsal.</span>
				</p>
				<?php if ( law_events_is_production() ) : ?>
					<p class="law-test-mode__confirm">
						<label>
							<input type="checkbox" name="test_mode_confirm" value="1">
							<strong>This is the live site.</strong> I understand that password reset and new-account emails for real users will be diverted to the address above.
						</label>
					</p>
				<?php endif; ?>
			</div>
			<p><?php submit_button( 'Save test mode', 'secondary', 'save_test_mode', false ); ?></p>
		</form>
	</div>
	<?php
}

/** Save the test-mode checkbox and address together. */
function law_events_emails_handle_test_mode_post() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$enabled = ! empty( $_POST['test_mode_enabled'] );
	$email   = sanitize_text_field( wp_unslash( $_POST['test_mode_email'] ?? '' ) );
	$stored  = law_events_test_mode();

	// A ticked box needs a usable address, or the setting is refused outright
	// rather than saved in a state that looks on but does nothing.
	if ( $enabled ) {
		$check = law_events_test_mode_check_email( $email );
		if ( ! $check['valid'] ) {
			law_events_emails_test_mode_notice( array( 'type' => 'error', 'text' => 'Test mode not enabled. ' . $check['message'] ) );
			return;
		}

		// On production, turning it on diverts real people's password resets,
		// so it takes a deliberate second confirmation rather than one click.
		if ( law_events_is_production() && empty( $_POST['test_mode_confirm'] ) ) {
			law_events_emails_test_mode_notice(
				array(
					'type' => 'error',
					'text' => 'Test mode not enabled. This is the live site, so please tick the confirmation box to acknowledge that real password reset and account emails will be diverted.',
				)
			);
			return;
		}

		$no_expiry = ! empty( $_POST['test_mode_no_expiry'] );
		update_option(
			LAW_EVENTS_TEST_MODE_OPTION,
			array( 'enabled' => true, 'email' => sanitize_email( $email ), 'enabled_at' => time(), 'no_expiry' => $no_expiry ),
			false
		);
		$settings = law_events_test_mode();
		law_events_emails_test_mode_notice(
			array(
				'type' => 'warning',
				'text' => sprintf(
					'Test mode is ON. Every email the site sends now goes to %s, and it %s. %s',
					sanitize_email( $email ),
					$no_expiry
						? 'stays on until you switch it off (auto-expiry disabled)'
						: 'switches itself off ' . law_events_test_mode_expiry_label( $settings['expires_at'] ),
					$check['message']
				),
			)
		);
		return;
	}

	// Keep the address on record so re-enabling does not mean retyping it.
	update_option(
		LAW_EVENTS_TEST_MODE_OPTION,
		array( 'enabled' => false, 'email' => is_email( $email ) ? sanitize_email( $email ) : $stored['email'], 'enabled_at' => 0 ),
		false
	);
	law_events_emails_test_mode_notice( array( 'type' => 'success', 'text' => 'Test mode is off. Emails go to their real recipients.' ) );
}
