<?php
/**
 * The Speakers archive switch (Denis, 17 September 2026).
 *
 * The archive is off until the committee turns it on in Events -> Settings.
 * While it is off it answers 404 for the public, leaves the XML sitemap, and a
 * speaker profile does not render its "Back to speakers" link. Committee
 * members, editors and administrators still read it, so the line-up can be
 * checked before it is announced. Single profiles stay reachable to everyone
 * throughout, because every event page links straight to one.
 *
 * The 404 is deliberate and is pinned here: an earlier build redirected to the
 * home page instead, and a redirect to an unrelated destination is a soft 404
 * to Google while also dumping a real visitor somewhere they did not ask to go.
 */

require_once __DIR__ . '/class-law-test-case.php';

class SpeakersArchiveVisibilityTest extends LAW_Test_Case {

	/** The Speakers page, created per test and rolled back with it. */
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

	/* Who sees the archive __________________________________________________ */

	public function test_a_visitor_gets_nothing_from_the_hidden_archive(): void {
		$this->set_archive_public( false );
		wp_set_current_user( 0 );

		$this->assertTrue( $this->with_speakers_page( 'law_speakers_archive_is_hidden' ) );
	}

	public function test_a_logged_in_member_is_shut_out_too(): void {
		$this->set_archive_public( false );
		wp_set_current_user( $this->make_user( 'subscriber' ) );

		$this->assertTrue(
			$this->with_speakers_page( 'law_speakers_archive_is_hidden' ),
			'Hidden means hidden from everyone but committee, editors and admins.'
		);
	}

	public function test_the_committee_may_read_the_hidden_archive(): void {
		$this->set_archive_public( false );
		wp_set_current_user( $this->make_committee_user() );

		$this->assertFalse(
			$this->with_speakers_page( 'law_speakers_archive_is_hidden' ),
			'The committee has to be able to check the archive before announcing it.'
		);
	}

	public function test_an_administrator_may_read_the_hidden_archive(): void {
		$this->set_archive_public( false );
		wp_set_current_user( $this->make_user( 'administrator' ) );

		$this->assertFalse(
			$this->with_speakers_page( 'law_speakers_archive_is_hidden' ),
			'edit_others_law_events covers committee, editor and administrator alike.'
		);
	}

	public function test_nobody_is_shut_out_once_it_is_public(): void {
		$this->set_archive_public( true );
		wp_set_current_user( 0 );

		$this->assertFalse( $this->with_speakers_page( 'law_speakers_archive_is_hidden' ) );
	}

	public function test_a_single_profile_is_never_gated(): void {
		$this->set_archive_public( false );
		wp_set_current_user( 0 );

		$this->assertFalse(
			$this->with_speakers_page( 'law_speakers_archive_is_hidden', 123 ),
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
			$this->assertFalse( law_speakers_archive_is_hidden() );
		} finally {
			$GLOBALS['wp_query'] = $previous;
		}
	}

	/* 404, not a redirect ___________________________________________________ */

	public function test_the_gate_answers_404_and_never_redirects(): void {
		$source = file_get_contents( get_theme_file_path( 'functions/speakers.php' ) );

		$this->assertStringNotContainsString(
			'wp_safe_redirect',
			$source,
			'A redirect to an unrelated page is a soft 404 to Google and a dead end to a person.'
		);
		$this->assertStringContainsString( '$wp_query->set_404();', $source );
		$this->assertStringContainsString( 'status_header( 404 );', $source );
		$this->assertStringContainsString(
			'nocache_headers();',
			$source,
			'The switch gets flipped; a cached 404 would outlive it.'
		);
	}

	/* The sitemap ___________________________________________________________ */

	/**
	 * The page the exclusion should name: law_speakers_page_id() memoises the
	 * one page carrying templates/speakers.php, so these assert against it
	 * rather than against a fixture page, which is also what runs in
	 * production -- a site has exactly one Speakers page.
	 */
	private function skip_without_speakers_page(): int {
		$page_id = law_speakers_page_id();
		if ( ! $page_id ) {
			$this->markTestSkipped( 'This site has no page carrying templates/speakers.php.' );
		}
		return $page_id;
	}

	public function test_the_page_leaves_the_sitemap_while_it_is_hidden(): void {
		$page_id = $this->skip_without_speakers_page();
		$this->set_archive_public( false );

		$args = law_speakers_archive_sitemap_exclusion( array(), 'page' );

		$this->assertArrayHasKey( 'post__not_in', $args );
		$this->assertContains(
			$page_id,
			$args['post__not_in'],
			'A sitemap that advertises a 404 is a Search Console error, not a clean signal.'
		);
	}

	public function test_the_page_returns_to_the_sitemap_once_public(): void {
		$this->skip_without_speakers_page();
		$this->set_archive_public( true );

		$this->assertSame( array(), law_speakers_archive_sitemap_exclusion( array(), 'page' ) );
	}

	public function test_no_other_sitemap_is_touched(): void {
		$this->skip_without_speakers_page();
		$this->set_archive_public( false );

		$this->assertSame(
			array(),
			law_speakers_archive_sitemap_exclusion( array(), LAW_EVENT_CPT ),
			'Only the pages sitemap holds the Speakers page.'
		);
	}

	public function test_an_existing_exclusion_is_kept(): void {
		$page_id = $this->skip_without_speakers_page();
		$this->set_archive_public( false );

		$args = law_speakers_archive_sitemap_exclusion( array( 'post__not_in' => array( 999 ) ), 'page' );

		$this->assertContains( 999, $args['post__not_in'], 'Another filter had already excluded something.' );
		$this->assertContains( $page_id, $args['post__not_in'] );
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
