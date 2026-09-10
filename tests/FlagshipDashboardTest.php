<?php
/**
 * The committee's front-end "Manage flagship" screen
 * (functions/events/flagship-dashboard.php).
 *
 * The point of these tests is the promise that the dashboard and the wp-admin
 * screen are one feature and not two: they must share the write path, the
 * validation and the field markup, so that a change to one cannot silently
 * diverge from the other. Whatever the two screens do differently is only the
 * plumbing around that shared core, and that is what is pinned here.
 */
class FlagshipDashboardTest extends LAW_Test_Case {

	private int $flagship = 0;

	protected function tearDown(): void {
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		delete_transient( 'law_flagship_state_' . get_current_user_id() );
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	private function make_flagship(): int {
		$event_id = $this->make_event( array( '_law_is_flagship' => 1, '_law_flagship_date' => '2026-12-02' ), 'law-draft' );
		wp_update_post( array( 'ID' => $event_id, 'post_name' => 'flagship' ) );
		$this->flagship = $event_id;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		law_flagship_event_id( true );
		return $event_id;
	}

	public function test_both_screens_share_one_write_path(): void {
		// Not a style point: if either screen grew its own saver, the two would
		// drift and "the dashboard updates the same data as the admin" would
		// quietly stop being true. The data layer lives in flagship.php, which
		// neither screen owns.
		foreach ( array(
			'law_flagship_input_from_post',
			'law_flagship_validate',
			'law_flagship_save',
			'law_flagship_save_sessions',
			'law_flagship_resolve_speaker_rows',
			'law_flagship_form_values',
		) as $function ) {
			$file = ( new ReflectionFunction( $function ) )->getFileName();
			$this->assertSame(
				'flagship.php',
				basename( (string) $file ),
				$function . '() must stay in the shared data layer, not move into a screen.'
			);
		}

		// And the session agenda's fields, the complicated half of the form.
		foreach ( array( 'law_flagship_render_sessions', 'law_flagship_render_session', 'law_flagship_render_new_speaker_template' ) as $function ) {
			$file = ( new ReflectionFunction( $function ) )->getFileName();
			$this->assertSame( 'flagship-form.php', basename( (string) $file ), $function . '() must stay shared.' );
		}
	}

	public function test_the_dashboard_page_is_provisioned_with_the_others(): void {
		// The page is database state, so a deploy has to create it: both
		// provisioning routes must name it, or one environment gets the header
		// link and a 404 behind it.
		$map = law_migration_page_map();
		$this->assertArrayHasKey( LAW_FLAGSHIP_DASHBOARD_PATH, $map );
		$this->assertSame( LAW_FLAGSHIP_DASHBOARD_TEMPLATE, $map[ LAW_FLAGSHIP_DASHBOARD_PATH ]['template'] );

		$setup = file_get_contents( get_theme_file_path( 'functions/setup-account-pages.php' ) );
		$this->assertStringContainsString( LAW_FLAGSHIP_DASHBOARD_PATH, $setup, 'The ?setup-account-pages trigger must assign the template too.' );
		$this->assertStringContainsString( 'law_setup_flagship_dashboard_access', $setup, 'And copy the committee restriction from the parent dashboard.' );

		// The template exists on disk, so the map cannot point at nothing.
		$this->assertFileExists( get_theme_file_path( LAW_FLAGSHIP_DASHBOARD_TEMPLATE ) );
	}

	public function test_the_header_link_is_committee_only(): void {
		$this->assertArrayHasKey( 'flagship', law_account_paths() );
		$this->assertSame( LAW_FLAGSHIP_DASHBOARD_PATH, law_account_paths()['flagship'] );

		// Signed in, the items live in the account dropdown; signed out they are
		// the bare 'links' (law_header_nav()).
		$labels = function () {
			$nav = law_header_nav();
			return wp_list_pluck( $nav['account']['items'] ?? $nav['links'], 'label' );
		};

		wp_set_current_user( $this->make_committee_user() );
		$this->assertContains( 'Manage flagship', $labels(), 'The committee gets the link.' );

		wp_set_current_user( $this->make_user( 'event_host' ) );
		$this->assertNotContains( 'Manage flagship', $labels(), 'A host does not.' );

		wp_set_current_user( 0 );
		$this->assertNotContains( 'Manage flagship', $labels(), 'And neither does a visitor.' );
	}

	public function test_the_dashboard_url_resolves_by_path(): void {
		// Resolved by path, never by a hard-coded ID, so a page created with a
		// different ID on staging still works.
		$url = law_flagship_dashboard_url();
		$this->assertStringContainsString( LAW_FLAGSHIP_DASHBOARD_PATH, $url );
		$this->assertStringStartsWith( 'http', $url );
	}

	public function test_a_refused_save_hands_back_what_was_typed_once(): void {
		wp_set_current_user( $this->make_committee_user() );

		$errors = array( 'Give the flagship event a title.' );
		$input  = array( 'title' => '', 'venue' => 'Half-typed venue' );
		set_transient( 'law_flagship_state_' . get_current_user_id(), array( 'errors' => $errors, 'input' => $input ), 600 );

		$state = law_flagship_dashboard_state();
		$this->assertSame( $errors, $state['errors'] );
		$this->assertSame( 'Half-typed venue', $state['input']['venue'], 'A redirect must not throw away a half-typed agenda.' );

		// One shot: a reload after reading it shows the stored values again,
		// not a stale error.
		$this->assertSame( array( 'errors' => array(), 'input' => array() ), law_flagship_dashboard_state() );
	}

	public function test_the_dashboard_saves_the_same_data_as_the_admin_screen(): void {
		$event_id = $this->make_flagship();
		$actor    = $this->make_committee_user();
		wp_set_current_user( $actor );

		// Exactly what the front-end form posts, read through the shared reader
		// and written by the shared saver: the handler adds nothing but the
		// nonce, the rate limit and a redirect.
		$_POST['law_flagship'] = array(
			'title'            => 'Flagship conference',
			'description'      => '<p>Set from the committee dashboard.</p>',
			'date'             => '2026-12-02',
			'venue'            => 'IDRC, 70 Fleet Street, London',
			'hero_image_id'    => '0',
			'show'             => '1',
			'sessions_present' => '1',
			'sessions'         => array(
				array( 'id' => '0', 'title' => 'Opening keynote', 'start' => '09:30', 'end' => '10:30', 'description' => '<p>Welcome.</p>' ),
			),
		);

		$input  = law_flagship_input_from_post();
		$result = law_flagship_save( $input, $actor );
		$_POST  = array();

		$this->assertSame( $event_id, $result, 'The dashboard writes the same post.' );
		$this->sessions_tracked( $event_id );

		$this->assertSame( 'Flagship conference', get_post( $event_id )->post_title );
		$this->assertSame( 'publish', get_post_status( $event_id ), 'The tick box publishes it, past the workflow status guard.' );
		$this->assertSame( 'IDRC, 70 Fleet Street, London', law_event_meta( $event_id, '_law_venue' ) );
		$this->assertSame( '2026-12-02 09:30', law_event_meta( $event_id, '_law_start' ), 'And the derived times are recomputed.' );
		$this->assertCount( 1, law_event_session_ids( $event_id ) );
	}

	public function test_a_non_committee_user_gets_no_form(): void {
		$this->make_flagship();
		wp_set_current_user( $this->make_user( 'event_host' ) );

		ob_start();
		get_template_part( 'parts/events/flagship-manage' );
		$html = (string) ob_get_clean();

		$this->assertStringNotContainsString( 'law_flagship_manage', $html, 'No form for a host, whatever links them here.' );
		$this->assertStringContainsString( 'for the LAW committee', $html );
	}

	public function test_the_committee_form_carries_every_field_the_admin_screen_has(): void {
		$event_id = $this->make_flagship();
		wp_set_current_user( $this->make_committee_user() );

		ob_start();
		get_template_part( 'parts/events/flagship-manage' );
		$html = (string) ob_get_clean();

		foreach ( array(
			'law_flagship[title]',
			'law_flagship[description]',
			'law_flagship[date]',
			'law_flagship[venue]',
			'law_flagship[hero_image_id]',
			'law_flagship[show]',
			'law_flagship[sessions_present]',
		) as $field ) {
			$this->assertStringContainsString( $field, $html, $field . ' is missing from the committee form.' );
		}

		// The shared agenda block, with its clone template and its picker.
		$this->assertStringContainsString( 'data-law-flagship-sessions', $html );
		$this->assertStringContainsString( 'data-law-row-template', $html );
		$this->assertStringContainsString( 'data-law-rel', $html, 'The speaker search.' );
		$this->assertStringContainsString( 'law-rel-add-new', $html, 'And the "add new speaker" button.' );
		$this->assertStringContainsString( 'law-flagship-new-speaker', $html, 'And the row template the JS clones.' );
		$this->assertStringContainsString( 'action" value="law_flagship_manage', $html );
		$this->assertStringContainsString( 'law_website_url', $html, 'The honeypot every front-end form carries.' );

		unset( $event_id );
	}

	/** Track the flagship's sessions so tearDown cleans them up. */
	private function sessions_tracked( int $event_id ): void {
		$this->posts = array_merge( $this->posts, array_diff( law_event_session_ids( $event_id ), $this->posts ) );
	}

	/* Registration terms link _________________________________________________ */

	/**
	 * The committee can set the attendee terms link from Manage flagship,
	 * and it is the SAME value as the one on LAW → Events settings.
	 */
	public function test_the_terms_link_is_editable_from_the_flagship_form(): void {
		$event_id = law_flagship_ensure_post()['id'];
		$page_id  = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Registration terms' ) );

		$input = law_flagship_form_values( $event_id );
		$input['sessions_present'] = false;
		// 0 = "no number set". The suite shares a database with the site, so
		// the real flagship's places count can be below its real confirmed
		// count and would fail an unrelated rule.
		$input['places']           = 0;
		$input['attendee_terms']   = (string) $page_id;
		$saved = law_flagship_save( $input, 0 );
		$this->assertFalse(
			is_wp_error( $saved ),
			'The save must succeed: ' . ( is_wp_error( $saved ) ? implode( '; ', $saved->get_error_messages() ) : '' )
		);

		$this->assertSame( (string) $page_id, (string) law_events_setting( 'attendee_terms_page', '' ) );
		$this->assertTrue( law_events_attendee_terms_configured() );
		$this->assertSame( get_permalink( $page_id ), law_events_attendee_terms_url() );
	}

	/**
	 * Saving the wp-admin Flagship screen must not wipe it.
	 *
	 * Both screens share law_flagship_input_from_post() and
	 * law_flagship_save(), but only the front-end one renders this field.
	 * Every other field follows "absent or empty means leave it alone"; this
	 * one cannot, because empty is a real choice (fall back to the Policies
	 * index). So it is read only when the POST actually carried the key — and
	 * a save from a form without the field must leave it exactly as it was.
	 */
	public function test_a_save_from_a_form_without_the_field_leaves_the_terms_alone(): void {
		$settings = (array) get_option( 'law_events_settings', array() );
		$settings['attendee_terms_page'] = 'https://example.test/registration-terms/';
		update_option( 'law_events_settings', $settings );

		$_POST = array( 'law_flagship' => array( 'title' => 'Flagship conference' ) );
		$input = law_flagship_input_from_post();
		$_POST = array();

		$this->assertArrayNotHasKey( 'attendee_terms', $input, 'A form without the field must not speak for it.' );

		law_flagship_save( $input, 0 );

		$this->assertSame(
			'https://example.test/registration-terms/',
			(string) law_events_setting( 'attendee_terms_page', '' ),
			'The wp-admin screen must not clear a link it never showed.'
		);
	}

	/** A terms link pointing nowhere is refused, not saved. */
	public function test_a_terms_link_that_goes_nowhere_is_refused(): void {
		// A whole valid input, so only the field under test can fail.
		$base = law_flagship_form_values( law_flagship_ensure_post()['id'] );
		$base['sessions_present'] = false;
		$base['sessions']         = array();
		$base['places']           = 0;

		foreach ( array( '999999999', 'not a url', 'policies' ) as $bad ) {
			$errors = law_flagship_validate( array_merge( $base, array( 'attendee_terms' => $bad ) ) );
			$this->assertNotEmpty( (string) $errors->get_error_message( 'attendee_terms' ), '"' . $bad . '" must be refused.' );
		}

		$page_id = wp_insert_post( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Terms' ) );
		foreach ( array( (string) $page_id, 'https://example.test/terms/', '' ) as $good ) {
			$errors = law_flagship_validate( array_merge( $base, array( 'attendee_terms' => $good ) ) );
			$this->assertSame( '', (string) $errors->get_error_message( 'attendee_terms' ), '"' . $good . '" must be accepted.' );
		}
	}

}
