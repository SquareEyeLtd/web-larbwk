<?php
/**
 * The Speakers archive switch (Denis, 17 September 2026).
 *
 * The archive is off until the committee turns it on in Events -> Settings.
 * While it is off, /speakers/ redirects visitors to the home page and the
 * "Back to speakers" link is not rendered on a profile. Single profiles stay
 * reachable throughout, because every event page links straight to them: the
 * switch hides the index, not the people.
 */

require_once __DIR__ . '/class-law-test-case.php';

class SpeakersArchiveVisibilityTest extends LAW_Test_Case {

	/** The Speakers page, created once per test and rolled back with it. */
	private function speakers_page(): int {
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Speakers',
			)
		);
		$this->posts[] = $page_id;
		update_post_meta( $page_id, '_wp_page_template', 'templates/speakers.php' );
		return $page_id;
	}

	/**
	 * Run $fn with the Speakers page as the queried object, optionally with a
	 * speaker requested on it (which is what makes the request a single
	 * profile rather than the archive).
	 *
	 * is_page_template() and get_query_var() both read the global query, so
	 * swapping that is enough -- the same approach EventFlagsTest uses for the
	 * committee programme.
	 */
	private function with_speakers_page( callable $fn, int $speaker_id = 0 ) {
		$args = array( 'page_id' => $this->speakers_page() );
		if ( $speaker_id ) {
			$args[ LAW_SPEAKER_QUERY_VAR ] = $speaker_id;
		}

		$previous            = $GLOBALS['wp_query'];
		$GLOBALS['wp_query'] = new WP_Query( $args );
		try {
			return $fn();
		} finally {
			$GLOBALS['wp_query'] = $previous;
		}
	}

	private function set_archive_public( bool $public ): void {
		$this->isolate_option(
			LAW_EVENTS_SETTINGS_OPTION,
			array( 'speakers_archive_public' => $public )
		);
	}

	/* The setting ___________________________________________________________ */

	public function test_the_archive_is_private_until_it_is_switched_on(): void {
		$this->assertFalse(
			law_events_settings_defaults()['speakers_archive_public'],
			'A fresh site must not announce the line-up by accident.'
		);
	}

	public function test_the_setting_drives_the_helper(): void {
		$this->set_archive_public( false );
		$this->assertFalse( law_speakers_archive_is_public() );

		$this->set_archive_public( true );
		$this->assertTrue( law_speakers_archive_is_public() );
	}

	/* The redirect __________________________________________________________ */

	public function test_a_visitor_is_sent_home_from_the_hidden_archive(): void {
		$this->set_archive_public( false );
		wp_set_current_user( 0 );

		$this->assertTrue( $this->with_speakers_page( 'law_speakers_archive_should_redirect' ) );
	}

	public function test_a_logged_in_member_is_sent_home_too(): void {
		$this->set_archive_public( false );
		wp_set_current_user( $this->make_user( 'subscriber' ) );

		$this->assertTrue(
			$this->with_speakers_page( 'law_speakers_archive_should_redirect' ),
			'Hidden means hidden from everyone but the committee.'
		);
	}

	public function test_the_committee_may_preview_the_hidden_archive(): void {
		$this->set_archive_public( false );
		wp_set_current_user( $this->make_committee_user() );

		$this->assertFalse(
			$this->with_speakers_page( 'law_speakers_archive_should_redirect' ),
			'The committee has to be able to check the archive before announcing it.'
		);
	}

	public function test_nobody_is_redirected_once_it_is_public(): void {
		$this->set_archive_public( true );
		wp_set_current_user( 0 );

		$this->assertFalse( $this->with_speakers_page( 'law_speakers_archive_should_redirect' ) );
	}

	public function test_a_single_profile_is_never_redirected(): void {
		$this->set_archive_public( false );
		wp_set_current_user( 0 );

		$this->assertFalse(
			$this->with_speakers_page( 'law_speakers_archive_should_redirect', 123 ),
			'Event pages link straight to a profile; the switch hides the index only.'
		);
	}

	public function test_no_other_page_is_touched(): void {
		$this->set_archive_public( false );
		wp_set_current_user( 0 );

		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'About',
			)
		);
		$this->posts[]       = $page_id;
		$previous            = $GLOBALS['wp_query'];
		$GLOBALS['wp_query'] = new WP_Query( array( 'page_id' => $page_id ) );
		try {
			$this->assertFalse( law_speakers_archive_should_redirect() );
		} finally {
			$GLOBALS['wp_query'] = $previous;
		}
	}

	/* The "Back to speakers" link ___________________________________________ */

	public function test_the_profile_template_gates_its_back_link(): void {
		$source = file_get_contents( get_theme_file_path( 'templates/speaker.php' ) );

		$this->assertStringContainsString(
			'if ( law_speakers_archive_is_public() ) {',
			$source,
			'The back link must be rendered only while /speakers/ resolves.'
		);
		$this->assertMatchesRegularExpression(
			'/law_speakers_archive_is_public\(\)\s*\)\s*\{\s*get_template_part\(\s*\n\s*\'parts\/layout\/back-link\'/',
			$source,
			'The guard must wrap the back link itself, not something near it.'
		);
	}
}
