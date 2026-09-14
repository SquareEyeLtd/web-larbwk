<?php
/**
 * The account hub at /account/ (templates/account-hub.php and
 * parts/layout/account-tiles.php).
 *
 * Two things are worth pinning. First that both provisioning routes name the
 * hub template, because a page template is database state a git deploy cannot
 * carry: an environment that misses this renders the old editor-content page
 * with a body the content helper has just emptied, which is a blank screen.
 * Second that the tiles are exactly what law_header_nav() offers, because the
 * hub having a list of its own is the failure mode the header rework existed
 * to end, and it would show up here as a tile somebody should not have.
 */
class AccountHubTest extends LAW_Test_Case {

	/** The tiles markup for the current user. */
	private function render(): string {
		$nav = law_header_nav();
		ob_start();
		get_template_part( 'parts/layout/account-tiles', null, array( 'items' => (array) ( $nav['account']['items'] ?? array() ) ) );
		return (string) ob_get_clean();
	}

	public function test_both_provisioning_routes_name_the_hub_template(): void {
		$map = law_migration_page_map();
		$this->assertSame( 'templates/account-hub.php', $map['account']['template'], 'Migration step 10 must assign the hub template.' );

		$setup = file_get_contents( get_theme_file_path( '/functions/setup-account-pages.php' ) );
		$this->assertMatchesRegularExpression(
			"/'account'\s*=>\s*'templates\/account-hub\.php'/",
			$setup,
			'The ?setup-account-pages trigger must assign the hub template too, or the two routes disagree.'
		);
	}

	/** Per-user output must never be cached, and the page gates itself. */
	public function test_the_template_is_uncached_and_carries_its_own_sign_in_check(): void {
		$template = file_get_contents( get_theme_file_path( '/templates/account-hub.php' ) );

		$this->assertStringContainsString( 'Template Name: Account hub', $template );
		$this->assertStringContainsString( 'nocache_headers()', $template, 'One person\'s account must never be served to the next.' );
		// The Members plugin filters the_content(), which this template never
		// calls, so the signed-out branch is the only gate there is.
		$this->assertStringContainsString( 'is_user_logged_in()', $template );
	}

	public function test_a_user_with_no_events_gets_their_own_tiles_and_no_committee_group(): void {
		wp_set_current_user( $this->make_user() );
		law_account_events_reset_cache();

		$html = $this->render();

		$this->assertStringContainsString( esc_url( law_account_url( 'profile' ) ), $html );
		$this->assertStringContainsString( esc_url( law_account_url( 'my_bookings' ) ), $html );
		$this->assertStringContainsString( esc_url( law_account_url( 'submit' ) ), $html );
		$this->assertStringContainsString( 'Sign out', $html );
		$this->assertStringNotContainsString( 'Committee tools', $html );
		$this->assertStringNotContainsString( '>My events<', $html );
	}

	public function test_an_owner_gets_the_my_events_tile(): void {
		$user_id = $this->make_user();
		$this->make_event( array(), 'law-proposed', $user_id );
		wp_set_current_user( $user_id );
		law_account_events_reset_cache();

		$this->assertStringContainsString( 'My events', $this->render() );
	}

	public function test_a_committee_member_gets_the_committee_group(): void {
		wp_set_current_user( $this->make_user( 'events_committee' ) );
		law_account_events_reset_cache();

		$html = $this->render();

		$this->assertStringContainsString( 'Committee tools', $html );
		$this->assertStringContainsString( 'Your account', $html, 'The personal tiles stay: committee access is additive.' );
		foreach ( array( 'dashboard', 'speakers', 'flagship', 'bookings', 'flagship_bookings', 'discounts' ) as $key ) {
			$this->assertStringContainsString( esc_url( law_account_url( $key ) ), $html, "No tile for '{$key}'." );
		}
	}

	/** Every tile carries a glyph; a bare label would read as a broken tile. */
	public function test_every_tile_has_exactly_one_icon(): void {
		wp_set_current_user( $this->make_user( 'events_committee' ) );
		law_account_events_reset_cache();

		$html  = $this->render();
		$tiles = substr_count( $html, 'class="law-account-hub__tile ' );

		$this->assertGreaterThan( 0, $tiles );
		$this->assertSame( $tiles, substr_count( $html, '<svg' ), 'One icon per tile, no more and no fewer.' );
	}

	/** Nothing to show is nothing rendered, not an empty heading. */
	public function test_an_empty_item_list_renders_nothing(): void {
		ob_start();
		get_template_part( 'parts/layout/account-tiles', null, array( 'items' => array() ) );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}
}
