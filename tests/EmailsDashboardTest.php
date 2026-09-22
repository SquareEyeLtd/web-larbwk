<?php
/**
 * The committee's "Manage emails" screen (functions/events/emails-dashboard.php)
 * and the wp-admin Emails screen (functions/events/admin/emails-screen.php).
 *
 * The promise these pin is the one ReceptionsDashboardTest pins for the
 * receptions: the two screens are one feature and not two. They share the
 * reader, the sanitisation and the writer, so a notification's wording cannot
 * come to mean different things depending on which screen somebody opened.
 *
 * The second promise is the access rule. This screen edits what every host,
 * delegate and committee member receives, so exactly two audiences may write
 * to it — the committee and administrators — and test mode, which diverts
 * every email the site sends including password resets, stays an
 * administrator control and is not reachable from the front end at all.
 */
class EmailsDashboardTest extends LAW_Test_Case {

	protected function setUp(): void {
		parent::setUp();
		// Never the real option: a test that saved an override would rewrite
		// the wording the local site actually sends.
		$this->isolate_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION );
		update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array(), false );
		// The shared sign-off is appended by the renderer, so every rendering
		// assertion below would otherwise be counting the site's real sign-off
		// as well as the body it means to test. Switched off by default here;
		// the tests that are ABOUT the sign-off set their own.
		$this->set_signoff( '' );
	}

	protected function tearDown(): void {
		remove_all_filters( 'pre_option_' . LAW_EVENTS_EMAIL_SIGNOFF_OPTION );
		delete_transient( 'law_email_state_' . get_current_user_id() );
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	/**
	 * Force the shared sign-off for the length of one test.
	 *
	 * A filter rather than isolate_option(): that helper overlays an ARRAY, and
	 * this option is a string whose unset state ('never edited', which is what
	 * law_events_email_signoff() answers with the shipped default) has to stay
	 * distinguishable from an empty one ('deliberately switched off').
	 */
	private function set_signoff( string $value ): void {
		remove_all_filters( 'pre_option_' . LAW_EVENTS_EMAIL_SIGNOFF_OPTION );
		add_filter( 'pre_option_' . LAW_EVENTS_EMAIL_SIGNOFF_OPTION, static fn() => $value );
	}

	/** A slug whose registry recipients are a fixed address list. */
	private function fixed_to_slug(): string {
		foreach ( law_events_email_registry() as $slug => $definition ) {
			if ( is_array( $definition['to'] ) ) {
				return $slug;
			}
		}

		return '';
	}

	/** A slug whose recipients are a dynamic audience. */
	private function dynamic_to_slug(): string {
		foreach ( law_events_email_registry() as $slug => $definition ) {
			if ( ! is_array( $definition['to'] ) ) {
				return $slug;
			}
		}

		return '';
	}

	/* One write path _______________________________________________________ */

	public function test_both_screens_share_one_write_path(): void {
		// Not a style point: if either screen grew its own saver, the two would
		// drift and "the front-end page edits the same emails" would quietly
		// stop being true. The data layer lives in notifications.php, which
		// neither screen owns.
		foreach ( array(
			'law_events_email_registry',
			'law_events_email',
			'law_events_email_override_from_input',
			'law_events_email_save_override',
			'law_events_email_reset_override',
			'law_events_email_is_customised',
			'law_events_email_has_unresolved_tags',
			'law_events_email_recipients_label',
			'law_events_email_trigger_label',
			'law_events_email_send_test',
			'law_events_email_send_test_to',
			'law_events_email_send_test_committee',
			'law_events_email_recipients_survived',
			'law_events_email_body_survived',
			'law_events_email_body_sanitize',
			'law_events_email_render_body',
		) as $function ) {
			$file = ( new ReflectionFunction( $function ) )->getFileName();
			$this->assertSame(
				'notifications.php',
				basename( (string) $file ),
				$function . '() must stay in the shared layer, not move into a screen.'
			);
		}

		// And neither screen writes the option itself.
		foreach ( array( 'functions/events/admin/emails-screen.php', 'functions/events/emails-dashboard.php' ) as $screen ) {
			$this->assertStringNotContainsString(
				'update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION',
				file_get_contents( get_theme_file_path( $screen ) ),
				$screen . ' must save through law_events_email_save_override().'
			);
		}
	}

	public function test_an_override_changes_what_the_registry_reports(): void {
		$slug = 'user_submitted';

		law_events_email_save_override(
			$slug,
			law_events_email_override_from_input( $slug, array( 'subject' => 'Reworded', 'body' => 'New body.', 'active' => true ) )
		);

		$this->assertSame( 'Reworded', law_events_email( $slug )['subject'] );
		$this->assertTrue( law_events_email_is_customised( $slug ) );

		law_events_email_reset_override( $slug );

		$this->assertSame( law_events_email_registry()[ $slug ]['subject'], law_events_email( $slug )['subject'] );
		$this->assertFalse( law_events_email_is_customised( $slug ) );
	}

	public function test_an_unknown_slug_is_refused_rather_than_stored(): void {
		$this->assertNull( law_events_email_override_from_input( 'no_such_email', array( 'subject' => 'x' ) ) );
		$this->assertFalse( law_events_email_save_override( 'no_such_email', array( 'subject' => 'x' ) ) );
		$this->assertSame( array(), get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() ) );
	}

	/* Recipients ___________________________________________________________ */

	public function test_only_a_fixed_address_list_may_be_retyped(): void {
		// A dynamic audience is resolved per event at send time, so accepting a
		// posted 'to' for one would store a value nothing ever reads — and the
		// screen would appear to have changed who gets the email.
		$dynamic = $this->dynamic_to_slug();
		$this->assertNotSame( '', $dynamic );

		$override = law_events_email_override_from_input( $dynamic, array( 'subject' => 's', 'body' => 'b', 'to' => 'someone@example.test' ) );
		$this->assertArrayNotHasKey( 'to', $override );

		$fixed = $this->fixed_to_slug();
		$this->assertNotSame( '', $fixed );

		$override = law_events_email_override_from_input(
			$fixed,
			array( 'subject' => 's', 'body' => 'b', 'to' => 'one@example.test, not-an-address, two@example.test' )
		);
		$this->assertSame( array( 'one@example.test', 'two@example.test' ), $override['to'], 'Anything that is not an address is dropped.' );
	}

	/* Formatting (16 September 2026) ______________________________________ */

	public function test_the_body_keeps_the_formatting_the_editor_offers(): void {
		// Until 16 September 2026 this stored through sanitize_textarea_field()
		// and the wp-admin wp_editor() toolbar was decorative: everything below
		// was stripped on save.
		$override = law_events_email_override_from_input(
			'user_submitted',
			array( 'subject' => 'Hello', 'body' => '<p>Dear <strong>you</strong></p><ul><li>One</li></ul><h3>Next</h3><a href="https://law.test">Link</a>', 'active' => true )
		);

		foreach ( array( '<strong>', '<ul>', '<li>', '<h3>', '<a href="https://law.test"' ) as $kept ) {
			$this->assertStringContainsString( $kept, $override['body'], $kept . ' is on the allowlist and must survive.' );
		}
	}

	public function test_the_body_allowlist_is_the_one_the_descriptions_use(): void {
		// Not wp_kses_post(): an email body may emphasise and structure a
		// message, not embed media or layout every client renders differently.
		$override = law_events_email_override_from_input(
			'user_submitted',
			array( 'subject' => 'x', 'body' => '<p>Keep</p><table><tr><td>Drop</td></tr></table><img src="x.png"><script>alert(1)</script><style>p{}</style>', 'active' => true )
		);

		$this->assertStringContainsString( '<p>Keep</p>', $override['body'] );
		foreach ( array( '<table', '<img', '<script', '<style' ) as $dropped ) {
			$this->assertStringNotContainsString( $dropped, $override['body'], $dropped . ' must not be storable in an email body.' );
		}
		// wp_kses strips the tag but leaves the code as visible text; the
		// shared sanitiser drops the block, contents and all.
		$this->assertStringNotContainsString( 'alert(1)', $override['body'] );
	}

	public function test_one_sanitiser_serves_every_writer_of_a_body(): void {
		// Three things write this option: the two screens and the
		// content-transfer importer. If any of them kept its own
		// sanitize_textarea_field() it would flatten formatting on that path
		// alone, which is exactly how the bundle importer would have
		// silently undone every edit somebody made on either screen.
		$this->assertSame(
			'notifications.php',
			basename( (string) ( new ReflectionFunction( 'law_events_email_body_sanitize' ) )->getFileName() )
		);
		$transfer = file_get_contents( get_theme_file_path( 'functions/events/migration/content-transfer.php' ) );
		$this->assertStringContainsString( 'law_events_email_body_sanitize(', $transfer );
		$this->assertStringNotContainsString( "'body'    => sanitize_textarea_field(", $transfer );
	}

	public function test_an_emptied_editor_is_refused_not_stored(): void {
		// TinyMCE's idea of empty is "<p>&nbsp;</p>", which the sanitiser
		// correctly reduces to ''. Storing that would leave the notification
		// sending a subject line over a blank page.
		foreach ( array( '', '   ', '<p>&nbsp;</p>', '<p></p>', '<br>' ) as $empty ) {
			$override = law_events_email_override_from_input( 'user_submitted', array( 'subject' => 'x', 'body' => $empty, 'active' => true ) );
			$this->assertFalse(
				law_events_email_body_survived( $override['body'] ),
				var_export( $empty, true ) . ' must not count as a message.'
			);
		}

		$this->assertTrue(
			law_events_email_body_survived(
				law_events_email_override_from_input( 'user_submitted', array( 'subject' => 'x', 'body' => '<p>Real words</p>', 'active' => true ) )['body']
			)
		);

		// And both screens act on it.
		foreach ( array( 'functions/events/emails-dashboard.php', 'functions/events/admin/emails-screen.php' ) as $screen ) {
			$this->assertStringContainsString(
				'law_events_email_body_survived(',
				file_get_contents( get_theme_file_path( $screen ) ),
				$screen . ' must refuse an emptied body.'
			);
		}
	}

	/* The escaping rule ____________________________________________________ */

	public function test_a_placeholder_value_cannot_inject_markup(): void {
		// THE security property this change turns on. The body is trusted
		// (committee-authored, allowlisted); the VALUES are not — an event
		// title, a host's display name and a rejection reason are all typed by
		// people outside the committee. The old code escaped the whole string
		// after substitution, which was safe but made formatting impossible;
		// the escape now sits on the values instead.
		$rendered = law_events_email_render_body(
			'<p>Hello {host_name}</p><p>About {event_title}</p>',
			array(
				'{host_name}'  => '<script>alert(1)</script>',
				'{event_title}' => '<b onclick="x()">Bold title</b>',
			)
		);

		// No LIVE tag and no LIVE attribute. "onclick" still appears in the
		// output, but as the inert text onclick=&quot;x()&quot; inside an
		// escaped &lt;b&gt;, which is the whole point of escaping rather than
		// stripping: the recipient sees what the host actually typed.
		$this->assertStringNotContainsString( '<script', $rendered );
		$this->assertStringNotContainsString( '<b ', $rendered );
		$this->assertStringNotContainsString( 'onclick="', $rendered );
		$this->assertStringContainsString( '&lt;script&gt;', $rendered, 'The value is shown as text, not executed.' );
		$this->assertStringContainsString( 'onclick=&quot;x()&quot;', $rendered );
		// And the BODY'S own markup is untouched by that.
		$this->assertStringContainsString( '<p>', $rendered );
	}

	public function test_an_ampersand_in_a_value_is_not_broken_html(): void {
		$rendered = law_events_email_render_body( 'Host: {host_name}', array( '{host_name}' => 'Smith & Jones' ) );

		$this->assertStringContainsString( 'Smith &amp; Jones', $rendered );
		$this->assertStringNotContainsString( 'Smith & Jones', $rendered );
	}

	public function test_a_body_holding_a_list_is_delivered_as_a_list(): void {
		// The regression this change was really about. A body carrying real
		// <ul>/<li> was esc_html'd on the way out, so the recipient saw the
		// tags. Found live on 16 September 2026 in the stored override for
		// user_submitted ("Email to user > event submitted") — the email every
		// host receives the moment they submit an event.
		$body = "What happens next?\n<ul>\n\t<li>We review it.</li>\n\t<li>We confirm the slot.</li>\n</ul>\nReply any time.";

		$rendered = law_events_email_render_body( $body, array() );

		$this->assertStringContainsString( '<li>We review it.</li>', $rendered );
		$this->assertStringNotContainsString( '&lt;ul&gt;', $rendered, 'The recipient must not see the tags.' );
		$this->assertStringNotContainsString( '&lt;li&gt;', $rendered );
	}

	public function test_no_registry_default_changes_meaning_under_the_new_renderer(): void {
		// The whole registry, rendered the old way and the new way. They may
		// differ in entity encoding — the body's own apostrophes are no longer
		// escaped, which is the point — but not in content. This is what makes
		// the change safe to deploy against 78 live notifications.
		$placeholders = array(
			'{host_name}'    => "Pat O'Neill",
			'{event_title}'  => 'Arbitration & Co',
			'{law_reference}' => '1234',
			'{latest_comment}' => 'Please add the venue & a contact.',
		);
		$decode = static fn( $html ) => html_entity_decode( $html, ENT_QUOTES, 'UTF-8' );
		$drifted = array();

		foreach ( law_events_email_registry() as $slug => $definition ) {
			$body = (string) $definition['body'];
			$old  = make_clickable( wpautop( esc_html( strtr( $body, $placeholders ) ) ) );
			$new  = law_events_email_render_body( $body, $placeholders );
			// A default that genuinely carries markup SHOULD render differently
			// — that is the fix, not a regression — so only the plain ones are
			// held to byte-for-byte sameness.
			if ( preg_match( '#</?[a-z][^>]*>#i', $body ) ) {
				continue;
			}
			if ( $decode( $old ) !== $decode( $new ) ) {
				$drifted[] = $slug;
			}
		}

		$this->assertSame( array(), $drifted, 'Plain-text defaults must render exactly as they always did.' );
	}

	public function test_a_plain_text_body_renders_exactly_as_it_always_did(): void {
		// Every one of the 78 registry defaults, and all 15 stored overrides in
		// production, are plain text with blank lines between paragraphs. They
		// must come out of the new renderer as paragraphs, not as one run-on
		// block, or this change would have quietly reformatted every email the
		// site sends.
		$rendered = law_events_email_render_body(
			"Dear {host_name},\n\nThank you for submitting your event.\n\nRegards",
			array( '{host_name}' => 'Pat' )
		);

		$this->assertSame( 3, substr_count( $rendered, '<p>' ), 'Blank lines are still paragraph breaks.' );
		$this->assertStringContainsString( 'Dear Pat,', $rendered );
	}

	public function test_a_multi_line_placeholder_keeps_its_line_breaks(): void {
		// {event_summary} and {attendee_list} are newline-joined blocks.
		$rendered = law_events_email_render_body( '{attendee_list}', array( '{attendee_list}' => "One\nTwo\nThree" ) );

		$this->assertSame( 2, substr_count( $rendered, '<br' ), 'Single newlines stay line breaks.' );
	}

	/* The shared sign-off _________________________________________________ */

	/*
	 * The promise: every email ends the same way, and it is stored ONCE. The
	 * request that produced it was "add this ending to each email template we
	 * have, even if it is customly modified" (Denis, 17 September 2026), and
	 * the second half is the half these tests exist for. A sign-off pasted into
	 * the 78 code defaults would reach neither a body reworded on the Emails
	 * screen nor a per-event booking confirmation, so it is appended by the
	 * renderer instead — the one place all three go through.
	 */

	public function test_the_signoff_is_appended_to_a_shipped_default(): void {
		$this->set_signoff( "Best regards,\nLondon Arbitration Week" );

		$rendered = law_events_email_render_body( 'Your event is confirmed.', array() );

		$this->assertStringContainsString( 'Best regards,', $rendered );
		$this->assertStringContainsString( 'London Arbitration Week', $rendered );
	}

	public function test_the_signoff_is_a_paragraph_of_its_own(): void {
		// "Make sure to add the new Enter line before the previous content":
		// a blank line between the message and the sign-off, so it reads as a
		// closing rather than as the last sentence of the last paragraph.
		$this->set_signoff( "Best regards,\nLondon Arbitration Week" );

		$rendered = law_events_email_render_body( 'Your event is confirmed.', array() );

		$this->assertSame( 2, substr_count( $rendered, '<p>' ), 'The sign-off is its own paragraph.' );
		$this->assertStringContainsString( 'Best regards,<br', $rendered, 'Its two lines stay two lines.' );
	}

	public function test_a_customised_body_gets_the_signoff_too(): void {
		// The point of the whole design. An override saved on either screen is
		// stored without a sign-off and still sends one.
		$slug = $this->dynamic_to_slug();
		$this->set_signoff( "Best regards,\nLondon Arbitration Week" );
		law_events_email_save_override(
			$slug,
			law_events_email_override_from_input( $slug, array( 'subject' => 'Hello', 'body' => 'Wording of our own.', 'active' => true ) )
		);

		$email    = law_events_email( $slug );
		$rendered = law_events_email_render_body( $email['body'], array() );

		$this->assertStringNotContainsString( 'Best regards', (string) $email['body'], 'The stored body carries no sign-off.' );
		$this->assertStringContainsString( 'Wording of our own.', $rendered );
		$this->assertStringContainsString( 'Best regards,', $rendered, 'And the sent version still signs off.' );
	}

	public function test_an_empty_signoff_adds_nothing(): void {
		// Clearing the field is how an administrator switches the sign-off off,
		// so an empty one must not fall back to the shipped default.
		$this->set_signoff( '' );

		$rendered = law_events_email_render_body( 'Your event is confirmed.', array() );

		$this->assertSame( 1, substr_count( $rendered, '<p>' ) );
		$this->assertStringNotContainsString( 'Best regards', $rendered );
	}

	public function test_the_signoff_resolves_placeholders(): void {
		// It is appended before substitution, so it can carry a tag exactly as
		// a body does. Cheap to keep true, and surprising if it were not.
		$this->set_signoff( 'Best regards, {site_name}' );

		$rendered = law_events_email_render_body( 'Hello.', array( '{site_name}' => 'London Arbitration Week' ) );

		$this->assertStringContainsString( 'Best regards, London Arbitration Week', $rendered );
		$this->assertStringNotContainsString( '{site_name}', $rendered );
	}

	public function test_an_unedited_site_signs_off_with_the_shipped_wording(): void {
		remove_all_filters( 'pre_option_' . LAW_EVENTS_EMAIL_SIGNOFF_OPTION );
		add_filter( 'pre_option_' . LAW_EVENTS_EMAIL_SIGNOFF_OPTION, '__return_false' );

		$this->assertSame( law_events_email_signoff_default(), law_events_email_signoff() );
		$this->assertStringContainsString( 'London Arbitration Week', law_events_email_signoff_default() );
	}

	public function test_the_signoff_survives_the_bodies_sanitiser(): void {
		// Both screens write through law_events_email_signoff_save(), so the
		// line break between the two lines has to come back out of storage.
		$this->set_signoff( '' );
		remove_all_filters( 'pre_option_' . LAW_EVENTS_EMAIL_SIGNOFF_OPTION );
		$this->isolate_option( LAW_EVENTS_EMAIL_SIGNOFF_OPTION );

		$stored = law_events_email_signoff_save( "Best regards,\nLondon Arbitration Week" );

		$this->assertStringContainsString( "Best regards,\nLondon Arbitration Week", $stored );
	}

	public function test_a_body_that_already_signs_off_is_repaired_not_guessed_at(): void {
		// The migrated Gravity Forms wording ends with its own "Best, / London
		// Arbitration Week", which would now sign off twice. That is fixed ONCE
		// in the stored wording (functions/events/migration/repair-signoff.php),
		// never guessed at per send — a renderer that decided for itself which
		// closing lines to swallow would have to be right about every wording
		// anyone writes in future too.
		$strip = law_events_signoff_strip( "Thanks for your patience.\n\nBest,\n\nLondon Arbitration Week" );

		$this->assertSame( 'Thanks for your patience.', $strip['body'] );
		$this->assertSame( 'Best, / London Arbitration Week', $strip['removed'] );
	}

	public function test_the_repair_never_truncates_a_real_message(): void {
		// The guard that matters: the stripper only ever takes a short trailing
		// line that is nothing but a valediction or the organisation's name,
		// and only when a valediction is among them.
		$safe = array(
			'no sign-off at all'   => "Your event is confirmed.\n\nView it: {event_link}",
			'name inside a line'   => 'Thank you for submitting your event to London Arbitration Week',
			'name but no farewell' => "We hope to see you at other events run by\nLondon Arbitration Week",
			'a long last line'     => 'Best regards from everyone on the organising committee of London Arbitration Week',
		);

		foreach ( $safe as $label => $body ) {
			$strip = law_events_signoff_strip( $body );
			$this->assertSame( $body, $strip['body'], $label . ' must be left alone.' );
			$this->assertSame( '', $strip['removed'], $label . ' must report nothing removed.' );
		}
	}

	public function test_the_repair_reads_a_body_written_as_markup(): void {
		// Bodies have kept their formatting since 16 September 2026, so the
		// closing lines may be <p> blocks rather than plain lines.
		$strip = law_events_signoff_strip( '<p>Thanks for your patience.</p><p>Kind regards,</p><p>London Arbitration Week</p><p>&nbsp;</p>' );

		$this->assertSame( '<p>Thanks for your patience.</p>', $strip['body'] );
		$this->assertSame( 'Kind regards, / London Arbitration Week', $strip['removed'] );
	}

	public function test_a_url_is_still_linkified(): void {
		$rendered = law_events_email_render_body( 'Pay here: {invoice_url}', array( '{invoice_url}' => 'https://invoice.test/abc' ) );

		$this->assertStringContainsString( '<a href="https://invoice.test/abc"', $rendered );
	}

	public function test_a_placeholder_inside_an_attribute_resolves(): void {
		// Substitution happens before the allowlist is re-applied, so the
		// committee can write a real button-style link.
		$rendered = law_events_email_render_body(
			'<a href="{invoice_url}">Pay now</a>',
			array( '{invoice_url}' => 'https://invoice.test/abc' )
		);

		$this->assertStringContainsString( 'href="https://invoice.test/abc"', $rendered );
		$this->assertStringContainsString( '>Pay now</a>', $rendered );
	}

	public function test_the_subject_is_never_html_escaped(): void {
		// It is a mail header. esc_html there would put "&amp;" in front of
		// the reader in their inbox list.
		$file = file_get_contents( get_theme_file_path( 'functions/events/notifications.php' ) );
		$this->assertStringContainsString( "\$subject      = strtr( (string) \$definition['subject'], \$placeholders );", $file );
	}

	public function test_a_test_send_renders_through_the_same_path_as_a_real_one(): void {
		// Otherwise "Send a test to me" would be a second opinion about the
		// wording rather than a preview of it. Asserted on the mail that
		// actually leaves, not on the shape of the source: an earlier version
		// of this test counted occurrences of the renderer's name in the file
		// and broke the moment a docblock mentioned it.
		$slug = 'user_submitted';
		$user = $this->make_user( 'events_committee' );
		// An author, or the 'host' audience resolves to nobody and
		// law_events_send() logs the drop and returns without mailing.
		$event = $this->make_event( array(), 'law-proposed', $user );
		$body  = '<p>Dear {host_name}</p><ul><li>{event_title}</li></ul>';

		$captured = array();
		$capture  = static function ( $atts ) use ( &$captured ) {
			$captured[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $capture );

		try {
			wp_set_current_user( $user );
			law_events_email_send_test( array( 'subject' => 'S', 'body' => $body ), $user );

			law_events_email_save_override(
				$slug,
				law_events_email_override_from_input( $slug, array( 'subject' => 'S', 'body' => $body, 'active' => true ) )
			);
			law_events_send( $slug, $event );
		} finally {
			remove_filter( 'wp_mail', $capture );
		}

		$this->assertCount( 2, $captured, 'A test send and a real send both reached wp_mail().' );
		// The test renders against the most recent event and the real one
		// against its own, so compare the STRUCTURE the renderer produced
		// rather than the values it resolved.
		foreach ( $captured as $mail ) {
			$this->assertStringContainsString( '<ul>', $mail['message'] );
			$this->assertStringContainsString( '<li>', $mail['message'] );
			$this->assertStringContainsString( '<p>Dear ', $mail['message'] );
		}
	}

	/* The test to the committee (22 September 2026) ________________________ */

	public function test_a_committee_test_goes_to_every_committee_address(): void {
		// The point of the button: a notification the committee receives is
		// being reworded for that inbox, so the preview has to land in it.
		$this->isolate_option(
			LAW_EVENTS_SETTINGS_OPTION,
			array( 'committee_emails' => array( 'one@committee.test', 'two@committee.test' ) )
		);

		$captured = array();
		$capture  = static function ( $atts ) use ( &$captured ) {
			$captured[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $capture );

		try {
			$sent = law_events_email_send_test_committee( array( 'subject' => 'Committee subject', 'body' => '<p>Body</p>' ) );
		} finally {
			remove_filter( 'wp_mail', $capture );
		}

		$this->assertSame( array( 'one@committee.test', 'two@committee.test' ), $sent['emails'] );
		$this->assertCount( 1, $captured, 'One send addressed to the whole list, exactly as a real committee notification is addressed.' );
		$this->assertSame( array( 'one@committee.test', 'two@committee.test' ), $captured[0]['to'] );
		$this->assertStringStartsWith( '[TEST] ', $captured[0]['subject'], 'A test says so in the subject, or somebody acts on it.' );
	}

	public function test_a_committee_test_with_no_addresses_configured_sends_nothing(): void {
		// An empty list means the real notification reaches nobody either.
		// Both screens say that rather than reporting a send that went nowhere.
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'committee_emails' => array() ) );

		$captured = 0;
		$capture  = static function ( $atts ) use ( &$captured ) {
			++$captured;
			return $atts;
		};
		add_filter( 'wp_mail', $capture );

		try {
			$sent = law_events_email_send_test_committee( array( 'subject' => 's', 'body' => 'b' ) );
		} finally {
			remove_filter( 'wp_mail', $capture );
		}

		$this->assertFalse( $sent['sent'] );
		$this->assertSame( array(), $sent['emails'] );
		$this->assertSame( 0, $captured );
	}

	public function test_the_committee_test_is_offered_on_both_screens_and_only_where_it_means_something(): void {
		// The same rule on both, or the two screens drift: the button belongs
		// on the notifications whose registry audience IS the committee, and
		// nowhere else, because on a host or attendee notification the
		// committee list is not an audience the email ever reaches.
		$front = file_get_contents( get_theme_file_path( 'parts/events/emails-manage.php' ) );
		$this->assertStringContainsString( "'committee' === \$law_em_email['to']", $front );
		$this->assertStringContainsString( 'law_email_test_committee', $front );

		$admin = file_get_contents( get_theme_file_path( 'functions/events/admin/emails-screen.php' ) );
		$this->assertStringContainsString( "'committee' === \$email['to']", $admin );
		$this->assertStringContainsString( 'send_test_committee', $admin );

		// And the handler does not trust the posted button on a notification
		// that is not sent to the committee.
		$handler = file_get_contents( get_theme_file_path( 'functions/events/emails-dashboard.php' ) );
		$this->assertStringContainsString( "\$test_to_committee && 'committee' !== \$definition['to']", $handler );
	}

	public function test_a_test_to_oneself_still_answers_in_its_old_shape(): void {
		// Both screens and the wp-admin notice read $test['email'].
		$user = $this->make_user( 'events_committee' );
		wp_set_current_user( $user );

		$sent = law_events_email_send_test( array( 'subject' => 's', 'body' => '<p>b</p>' ), $user );

		$this->assertSame( get_userdata( $user )->user_email, $sent['email'] );
		$this->assertSame( array( get_userdata( $user )->user_email ), $sent['emails'] );
	}

	public function test_both_screens_offer_the_same_formatting_buttons(): void {
		// One policy (law_rich_text_settings()), so the wp-admin toolbar and
		// the front-end one cannot drift into offering different formatting
		// against one shared allowlist.
		$admin = file_get_contents( get_theme_file_path( 'functions/events/admin/emails-screen.php' ) );
		$this->assertStringContainsString( 'law_rich_text_settings()', $admin );
		$this->assertStringNotContainsString( "'teeny' => true", $admin );

		$front = file_get_contents( get_theme_file_path( 'parts/events/emails-manage.php' ) );
		$this->assertStringContainsString( 'law_rich_text_field(', $front );
	}

	/* The front-end screen _________________________________________________ */

	public function test_the_page_is_provisioned_with_the_other_dashboards(): void {
		// A git deploy carries the template but not the page, so both routes
		// that create the account pages have to know about this one or the
		// top-bar link lands on a 404.
		$map = law_migration_page_map();
		$this->assertArrayHasKey( LAW_EMAILS_DASHBOARD_PATH, $map );
		$this->assertSame( LAW_EMAILS_DASHBOARD_TEMPLATE, $map[ LAW_EMAILS_DASHBOARD_PATH ]['template'] );

		$setup = file_get_contents( get_theme_file_path( 'functions/setup-account-pages.php' ) );
		$this->assertStringContainsString( "\$setup['" . LAW_EMAILS_DASHBOARD_PATH . "']", $setup );
		$this->assertStringContainsString( 'law_setup_emails_dashboard_access()', $setup );
	}

	public function test_the_nav_key_and_the_page_path_agree(): void {
		$this->assertSame( LAW_EMAILS_DASHBOARD_PATH, law_account_paths()['emails'] );
	}

	public function test_the_screen_is_offered_to_the_committee_and_nobody_else(): void {
		wp_set_current_user( $this->make_user( 'subscriber' ) );
		law_account_events_reset_cache();
		$this->assertNotContains( 'emails', wp_list_pluck( law_header_nav()['account']['items'], 'key' ) );

		wp_set_current_user( $this->make_user( 'events_committee' ) );
		law_account_events_reset_cache();
		$this->assertContains( 'emails', wp_list_pluck( law_header_nav()['account']['items'], 'key' ) );

		wp_set_current_user( $this->make_user( 'administrator' ) );
		law_account_events_reset_cache();
		$this->assertContains( 'emails', wp_list_pluck( law_header_nav()['account']['items'], 'key' ) );
	}

	public function test_the_list_reports_every_registered_notification(): void {
		$rows = law_emails_dashboard_rows();

		$this->assertCount( count( law_events_email_registry() ), $rows, 'Inactive notifications are listed too; "which are switched off" is a question this screen has to answer.' );
		$this->assertSame( array_keys( law_events_email_registry() ), wp_list_pluck( $rows, 'slug' ) );

		foreach ( $rows as $row ) {
			$this->assertNotSame( '', $row['name'] );
			$this->assertArrayHasKey( 'customised', $row );
			$this->assertArrayHasKey( 'unresolved', $row );
		}
	}

	public function test_a_bare_workflow_keyword_is_not_shown_as_one(): void {
		// A dozen of the oldest registry entries carry the bare action name.
		// "Sent when: send_back" is not something to put in front of a legal
		// marketer, and both screens render this instead.
		$this->assertSame( 'Send back', law_events_email_trigger_label( array( 'trigger' => 'send_back' ) ) );
		$this->assertSame( 'Submit', law_events_email_trigger_label( array( 'trigger' => 'submit' ) ) );
		// A trigger that is already a sentence is left as it reads.
		$this->assertSame(
			'A place opens up, or a host promotes an entry',
			law_events_email_trigger_label( array( 'trigger' => 'a place opens up, or a host promotes an entry' ) )
		);
	}

	public function test_only_a_real_slug_opens_the_editor(): void {
		$_GET['law_email'] = 'no_such_email';
		$this->assertSame( '', law_emails_dashboard_requested() );

		$_GET['law_email'] = 'user_submitted';
		$this->assertSame( 'user_submitted', law_emails_dashboard_requested() );
	}

	public function test_a_migrated_merge_tag_is_flagged_rather_than_left_to_be_found(): void {
		// A tag the renderer cannot resolve is delivered to somebody literally.
		$this->assertTrue( law_events_email_has_unresolved_tags( array( 'subject' => 'Your entry {all_fields}', 'body' => '' ) ) );
		// The Gravity Forms shape is {Field label:<id>}; a bare {1.3} is not one,
		// and is left alone on purpose.
		$this->assertTrue( law_events_email_has_unresolved_tags( array( 'subject' => '', 'body' => 'See {Event title:12.3} above' ) ) );
		$this->assertTrue( law_events_email_has_unresolved_tags( array( 'subject' => '', 'body' => 'Entry {entry_id}' ) ) );
		$this->assertFalse( law_events_email_has_unresolved_tags( array( 'subject' => '{event_title}', 'body' => '{host_name} {dashboard_link}' ) ) );
	}

	/* Access _______________________________________________________________ */

	public function test_the_save_handler_is_committee_gated_and_guarded(): void {
		$file = file_get_contents( get_theme_file_path( 'functions/events/emails-dashboard.php' ) );

		$this->assertStringContainsString( "law_events_guard_post(\n\t\t'law_email_manage'", $file, 'The handler must start with the shared nonce/honeypot/rate guard.' );
		$this->assertStringContainsString( 'if ( ! law_user_is_committee() )', $file, 'Editing what the site emails is committee-only.' );
		$this->assertStringContainsString( "add_action( 'admin_post_nopriv_law_email_manage', 'law_events_nopriv_json' )", $file, 'A signed-out POST must be bounced, not run.' );
	}

	public function test_test_mode_stays_an_administrator_control(): void {
		// It diverts EVERY email the site sends, password resets for real
		// accounts included. The front-end screen may say that it is on; it may
		// not offer to switch it on.
		$front = file_get_contents( get_theme_file_path( 'functions/events/emails-dashboard.php' ) )
			. file_get_contents( get_theme_file_path( 'templates/account-dashboard-emails.php' ) )
			. file_get_contents( get_theme_file_path( 'parts/events/emails-list.php' ) )
			. file_get_contents( get_theme_file_path( 'parts/events/emails-manage.php' ) );

		$this->assertStringNotContainsString( 'law_events_emails_test_mode_card', $front );
		$this->assertStringNotContainsString( 'law_events_emails_handle_test_mode_post', $front );
		$this->assertStringNotContainsString( 'LAW_EVENTS_TEST_MODE_OPTION', $front );

		$admin = file_get_contents( get_theme_file_path( 'functions/events/admin/emails-screen.php' ) );
		$this->assertStringContainsString( "if ( ! current_user_can( 'manage_options' ) ) {", $admin );
	}

	public function test_a_refused_draft_survives_one_read_and_no_more(): void {
		wp_set_current_user( $this->make_user( 'events_committee' ) );

		law_emails_dashboard_store_state( 'user_submitted', array( 'subject' => 'Draft subject', 'body' => 'Draft body', 'active' => true ) );

		$first = law_emails_dashboard_state( 'user_submitted' );
		$this->assertSame( 'Draft subject', $first['input']['subject'] );

		$second = law_emails_dashboard_state( 'user_submitted' );
		$this->assertSame( array(), $second['input'], 'A later visit must not be haunted by an old draft.' );
	}

	public function test_a_draft_belongs_to_the_notification_it_was_typed_against(): void {
		// Testing the wording of one email and then opening the next must not
		// repopulate the second one's form with the first one's draft.
		wp_set_current_user( $this->make_user( 'events_committee' ) );

		law_emails_dashboard_store_state( 'user_submitted', array( 'subject' => 'Draft for the first' ) );

		$this->assertSame( array(), law_emails_dashboard_state( 'committee_submitted' )['input'] );
	}

	public function test_a_recipients_list_is_never_saved_empty(): void {
		// law_events_send() treats "no recipients" as a failure it logs and
		// drops, so a typo in the only address would silently switch the
		// notification off while the screen went on reporting it as active.
		$slug  = $this->fixed_to_slug();
		$input = array( 'subject' => 's', 'body' => 'b', 'active' => true, 'to' => 'not-an-address' );

		$override = law_events_email_override_from_input( $slug, $input );

		$this->assertArrayNotHasKey( 'to', $override, 'The code default addresses are kept rather than replaced with nothing.' );
		$this->assertFalse(
			law_events_email_recipients_survived( $slug, $input, $override ),
			'The screen has to be able to tell the editor that nothing they typed was usable.'
		);

		// Nothing typed at all is not a typo, so it is not reported as one.
		$blank = array( 'subject' => 's', 'body' => 'b', 'active' => true, 'to' => '' );
		$this->assertTrue( law_events_email_recipients_survived( $slug, $blank, law_events_email_override_from_input( $slug, $blank ) ) );
	}

	public function test_an_array_where_a_string_belongs_is_not_cast_to_the_word_array(): void {
		// A hand-made POST of law_email[subject][] arrives as an array.
		$override = law_events_email_override_from_input(
			'user_submitted',
			array( 'subject' => array( 'a', 'b' ), 'body' => array( 'x' ), 'active' => true )
		);

		$this->assertSame( '', $override['subject'] );
		$this->assertSame( '', $override['body'] );
	}

	/**
	 * A post-approval host fee change reuses the "payment due" email and fills
	 * {fee_change_note} with the invoice it has just cancelled. A stored body
	 * beats the registry default, so on every environment whose Emails screen
	 * carries one (migration step 9 imported the Gravity Forms notifications as
	 * overrides) the host would otherwise get a second "payment due" email with
	 * no hint that the first invoice is dead.
	 */
	public function test_a_stored_payment_due_body_gains_the_fee_change_note(): void {
		$this->option_overlay[ LAW_EVENTS_EMAIL_OVERRIDES_OPTION ] = array(
			'user_payment_due' => array( 'body' => "Dear {host_name},\n\nThe fee of {fee} is due: {invoice_url}" ),
		);

		$this->assertSame( 'updated', law_setup_add_fee_change_note_to_payment_due() );
		$body = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION )['user_payment_due']['body'];
		$this->assertStringContainsString( '{fee_change_note}', $body );
		$this->assertStringContainsString( 'The fee of {fee} is due', $body, 'The committee\'s own wording is kept.' );

		// Idempotent: the provisioning routes run it on every deploy.
		$this->assertSame( 'ok', law_setup_add_fee_change_note_to_payment_due() );
		$this->assertSame( $body, get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION )['user_payment_due']['body'] );
	}

	/** No stored body means the registry default is in play, which already has it. */
	public function test_no_stored_payment_due_body_is_left_alone(): void {
		$this->option_overlay[ LAW_EVENTS_EMAIL_OVERRIDES_OPTION ] = array();
		$this->assertSame( 'ok', law_setup_add_fee_change_note_to_payment_due() );
		$this->assertSame( array(), get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION ) );
		$this->assertStringContainsString( '{fee_change_note}', law_events_email( 'user_payment_due' )['body'] );
	}

	/**
	 * The {event_summary} block used to name the fee TIER and nothing else.
	 * The tier label carries a price in its own words ("UK hosts: £1200 +
	 * VAT"), so on an event with a committee override it stated a figure that
	 * was simply wrong: the "payment received" email told the committee "£1200
	 * + VAT" about an event whose fee had been changed to £600 and which had
	 * just paid £720. Found by the fee-change end-to-end test, 17 September
	 * 2026.
	 */
	public function test_the_email_summary_names_the_fee_snapshot_not_just_the_tier(): void {
		$event = $this->make_event(
			array(
				'_law_fee_tier'            => 'uk',
				'_law_fee_override'        => 1,
				'_law_fee_override_amount' => 600,
				'_law_payment_status'      => 'paid',
			),
			'publish'
		);
		law_event_snapshot_fee( $event );

		$summary = law_events_email_placeholders( $event )['{event_summary}'];
		$this->assertStringContainsString( 'Fee: £600.00 + VAT', $summary, 'The amount actually charged is stated.' );
		$this->assertStringContainsString( 'Fee tier: UK hosts', $summary, 'The tier is still there: it is a separate fact.' );
	}

	/**
	 * Before approval there is no snapshot, and every event reads £0.00. "Fee:
	 * £0.00" on a submission acknowledgement would be a promise nobody made,
	 * so the row is omitted until the fee has actually been frozen.
	 */
	public function test_the_email_summary_omits_the_fee_before_approval(): void {
		$event   = $this->make_event( array( '_law_fee_tier' => 'uk' ), 'law-proposed' );
		$summary = law_events_email_placeholders( $event )['{event_summary}'];
		$this->assertStringNotContainsString( 'Fee: ', $summary );
		$this->assertStringContainsString( 'Fee tier: UK hosts', $summary );
	}

	/** A waived fee is a real answer, and the committee should read it. */
	public function test_a_waived_fee_is_stated_as_zero(): void {
		$event = $this->make_event(
			array( '_law_fee_tier' => 'uk', '_law_fee_override' => 1, '_law_fee_override_amount' => 0 ),
			'law-approved'
		);
		law_event_snapshot_fee( $event );
		$summary = law_events_email_placeholders( $event )['{event_summary}'];
		$this->assertStringContainsString( 'Fee: £0.00', $summary );
		$this->assertStringNotContainsString( 'Fee: £0.00 + VAT', $summary, 'No VAT on a zero fee.' );
	}
}
