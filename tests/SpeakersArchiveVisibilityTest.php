<?php
/**
 * The Speakers archive switch (Denis, 17 September 2026).
 *
 * The switch is off until the committee turns it on in Events -> Settings, and
 * while it is off a speaker profile does not render its "Back to speakers"
 * link. That is the whole of it: the archive page and every single profile
 * stay reachable either way. An earlier build of this also redirected
 * /speakers/ to the home page; that was dropped the same day, so the archive is
 * never hidden from anyone.
 */

require_once __DIR__ . '/class-law-test-case.php';

class SpeakersArchiveVisibilityTest extends LAW_Test_Case {

	private function set_archive_public( bool $public ): void {
		$this->isolate_option(
			LAW_EVENTS_SETTINGS_OPTION,
			array( 'speakers_archive_public' => $public )
		);
	}

	/* The setting ___________________________________________________________ */

	public function test_the_switch_is_off_until_it_is_turned_on(): void {
		$this->assertFalse(
			law_events_settings_defaults()['speakers_archive_public'],
			'A fresh site must not invite visitors into the archive by accident.'
		);
	}

	public function test_the_setting_drives_the_helper(): void {
		$this->set_archive_public( false );
		$this->assertFalse( law_speakers_archive_is_public() );

		$this->set_archive_public( true );
		$this->assertTrue( law_speakers_archive_is_public() );
	}

	public function test_the_archive_url_resolves_from_the_page_holding_the_template(): void {
		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'Speakers',
			)
		);
		$this->posts[] = $page_id;
		update_post_meta( $page_id, '_wp_page_template', 'templates/speakers.php' );

		$this->assertNotSame( '', law_speakers_archive_url() );
		$this->assertStringStartsWith( 'http', law_speakers_archive_url() );
	}

	/* The "Back to speakers" link ___________________________________________ */

	public function test_the_profile_template_gates_its_back_link(): void {
		$source = file_get_contents( get_theme_file_path( 'templates/speaker.php' ) );

		$this->assertStringContainsString(
			'if ( law_speakers_archive_is_public() ) {',
			$source,
			'The back link must be rendered only while the archive is announced.'
		);
		$this->assertMatchesRegularExpression(
			'/law_speakers_archive_is_public\(\)\s*\)\s*\{\s*get_template_part\(\s*\n\s*\'parts\/layout\/back-link\'/',
			$source,
			'The guard must wrap the back link itself, not something near it.'
		);
	}

	/* Nothing redirects _____________________________________________________ */

	public function test_the_archive_is_never_redirected(): void {
		$this->assertFalse(
			function_exists( 'law_speakers_archive_gate' ),
			'The /speakers/ redirect was dropped (Denis, 17 September 2026); the archive stays reachable.'
		);
		$this->assertFalse( function_exists( 'law_speakers_archive_should_redirect' ) );

		$source = file_get_contents( get_theme_file_path( 'functions/speakers.php' ) );
		$this->assertStringNotContainsString(
			'wp_safe_redirect',
			$source,
			'Nothing in the speakers front end may send a visitor away from the archive.'
		);
	}
}
