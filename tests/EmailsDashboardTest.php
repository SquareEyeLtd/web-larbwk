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
	}

	protected function tearDown(): void {
		delete_transient( 'law_email_state_' . get_current_user_id() );
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
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
			'law_events_email_recipients_survived',
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

	public function test_the_body_is_stored_as_plain_text(): void {
		// law_events_send() escapes the body and runs wpautop over it, so markup
		// stored here would be DELIVERED as visible angle brackets. The editor
		// on the front end is a plain textarea for exactly this reason.
		$override = law_events_email_override_from_input(
			'user_submitted',
			array( 'subject' => 'Hello', 'body' => "Line one\n\n<strong>bold</strong><script>alert(1)</script>", 'active' => true )
		);

		$this->assertStringNotContainsString( '<strong>', $override['body'] );
		$this->assertStringNotContainsString( '<script>', $override['body'] );
		$this->assertStringContainsString( 'Line one', $override['body'] );
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
}
