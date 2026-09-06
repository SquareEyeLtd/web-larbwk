<?php
/**
 * LAW → Emails: WooCommerce-style management of the module's notifications
 * (EVENTS_4.1_REBUILD.md §3.8). Defaults live in code; edits are stored as
 * overrides and take precedence; Reset returns to the code default.
 * Scope: events-module emails only — form 7 (Contact) stays in the GF admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	law_events_register_law_subpage( 'law-events-emails', 'Emails', 'law_events_emails_page' );
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
		$to         = is_array( $merged['to'] ) ? implode( ', ', $merged['to'] ) : ucfirst( str_replace( '_', ' ', $merged['to'] ) );
		// Migrated GF merge tags the renderer cannot resolve need eyes.
		$unmapped = preg_match( '/\{[^}]*:[0-9.]+[^}]*\}|\{all_fields\}|\{embed_url\}|\{entry_[a-z_]+\}/', $merged['subject'] . ' ' . $merged['body'] );
		printf(
			'<tr><td><strong><a href="%s">%s</a></strong>%s%s</td><td>%s</td><td>%s</td><td>%s</td><td><a class="button button-small" href="%1$s">Edit</a></td></tr>',
			esc_url( add_query_arg( array( 'page' => 'law-events-emails', 'email' => $slug ), admin_url( 'admin.php' ) ) ),
			esc_html( $merged['name'] ),
			$customised ? ' <span class="law-badge">customised</span>' : '',
			$unmapped ? ' <span class="law-badge" style="border-color:#b32d2e;color:#b32d2e;background:#fcf0f1">review tags</span>' : '',
			esc_html( $merged['trigger'] ),
			esc_html( $to ),
			$merged['active'] ? '<span style="color:#00a32a">Active</span>' : '<span style="color:#757575">Inactive</span>'
		);
	}
	echo '</tbody></table></div>';
}

function law_events_emails_edit_screen( $slug ) {
	$email     = law_events_email( $slug );
	$fixed_to  = is_array( $email['to'] );
	$back      = add_query_arg( array( 'page' => 'law-events-emails' ), admin_url( 'admin.php' ) );
	$tags      = array_keys( law_events_email_placeholders( 0 ) );
	?>
	<div class="wrap">
		<h1><?php echo esc_html( $email['name'] ); ?></h1>
		<p><a href="<?php echo esc_url( $back ); ?>">← All emails</a> · Trigger: <strong><?php echo esc_html( $email['trigger'] ); ?></strong></p>
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
						wp_editor(
							$email['body'],
							'law-email-body',
							array( 'textarea_name' => 'body', 'textarea_rows' => 12, 'media_buttons' => false, 'teeny' => true )
						);
						?>
						<p class="description">Plain text with placeholder tags; the site-wide email wrapper adds the branding. Available tags:<br>
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
	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	if ( ! is_array( $overrides ) ) {
		$overrides = array();
	}

	if ( isset( $_POST['reset'] ) ) {
		unset( $overrides[ $slug ] );
		update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $overrides, false );
		echo '<div class="notice notice-success"><p>Reset to the code default.</p></div>';
		return;
	}

	$registry = law_events_email_registry();
	$override = array(
		'subject' => sanitize_text_field( wp_unslash( $_POST['subject'] ?? '' ) ),
		'body'    => sanitize_textarea_field( wp_unslash( $_POST['body'] ?? '' ) ),
		'active'  => ! empty( $_POST['active'] ),
	);
	if ( is_array( $registry[ $slug ]['to'] ?? null ) && isset( $_POST['to'] ) ) {
		$override['to'] = array_filter(
			array_map( 'sanitize_email', array_map( 'trim', explode( ',', (string) wp_unslash( $_POST['to'] ) ) ) ),
			'is_email'
		);
	}

	// Send test renders the values AS TYPED without persisting anything, so
	// admins can preview safely before deciding to save.
	if ( isset( $_POST['send_test'] ) ) {
		$sample       = get_posts( array( 'post_type' => LAW_EVENT_CPT, 'post_status' => law_event_all_status_keys(), 'posts_per_page' => 1, 'fields' => 'ids' ) );
		$sample_id    = $sample ? (int) $sample[0] : 0;
		$user         = wp_get_current_user();
		$placeholders = law_events_email_placeholders( $sample_id );
		$sent         = wp_mail(
			array( $user->user_email ),
			'[TEST] ' . strtr( $override['subject'], $placeholders ),
			make_clickable( wpautop( esc_html( strtr( $override['body'], $placeholders ) ) ) ),
			array( 'Content-Type: text/html; charset=UTF-8' )
		);
		echo $sent
			? '<div class="notice notice-success"><p>Test sent to ' . esc_html( $user->user_email ) . ( $sample_id ? ' using event "' . esc_html( get_the_title( $sample_id ) ) . '"' : ' (no events exist yet, placeholders were blank)' ) . '. Nothing was saved: use Save email to keep these values.</p></div>'
			: '<div class="notice notice-error"><p>Send failed.</p></div>';
		return;
	}

	$overrides[ $slug ] = $override;
	update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $overrides, false );
	echo '<div class="notice notice-success"><p>Email saved.</p></div>';
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
