<?php
/**
 * The /account/ page's audience gating (functions/shortcodes.php and the
 * law_setup_account_page_audience() helper in setup-account-pages.php).
 *
 * These exist because the page shipped with copy gated on role names:
 * [user-content role="attendee"] and [user-content role="event_host"]. Someone
 * who registered as "LAW sponsor" and nothing else matched neither block, and
 * the shortcode renders nothing when no audience matches, so they were served
 * a heading with no body. Nothing failed to say so.
 *
 * The audience assertions are per role and exact, because the bug class here is
 * an audience quietly falling through every block rather than an audience
 * seeing one block too many.
 */

class AccountAudienceTest extends LAW_Test_Case {

	/** Render a [user-content] block for the current user. */
	private function render( string $audience ): string {
		return do_shortcode( '[user-content role="' . $audience . '"]BODY[/user-content]' );
	}

	private function shows( string $audience ): bool {
		return false !== strpos( $this->render( $audience ), 'BODY' );
	}

	/**
	 * Who the 'host' audience covers. Sponsors are the point of the fix; the
	 * committee, editors and administrators are in because they submit and run
	 * events of their own (the additive rule the header bar follows), and
	 * attendees are out because host copy invites them to submit an event.
	 */
	public static function host_audience(): array {
		return array(
			'event host'      => array( 'event_host', true ),
			'sponsor'         => array( 'sponsor', true ),
			'events_committee' => array( 'events_committee', true ),
			'editor'          => array( 'editor', true ),
			'administrator'   => array( 'administrator', true ),
			'attendee'        => array( 'attendee', false ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'host_audience' )]
	public function test_host_audience_per_role( string $role, bool $expected ): void {
		wp_set_current_user( $this->make_user( $role ) );

		$this->assertSame( $expected, $this->shows( 'host' ), "Wrong 'host' audience result for the {$role} role." );
	}

	public function test_committee_audience_excludes_plain_hosts_and_sponsors(): void {
		wp_set_current_user( $this->make_user( 'events_committee' ) );
		$this->assertTrue( $this->shows( 'committee' ) );

		wp_set_current_user( $this->make_user( 'event_host' ) );
		$this->assertFalse( $this->shows( 'committee' ) );

		wp_set_current_user( $this->make_user( 'sponsor' ) );
		$this->assertFalse( $this->shows( 'committee' ) );
	}

	/** An audience is not a way in for a logged-out visitor. */
	public function test_audiences_are_closed_to_guests(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->shows( 'host' ) );
		$this->assertFalse( $this->shows( 'committee' ) );
		$this->assertTrue( $this->shows( 'guest' ) );
	}

	/** Role names still work: copy really aimed at one role keeps working. */
	public function test_role_names_still_match_exactly(): void {
		wp_set_current_user( $this->make_user( 'sponsor' ) );

		$this->assertTrue( $this->shows( 'sponsor' ) );
		$this->assertFalse( $this->shows( 'event_host' ), 'A sponsor is not the event_host role.' );
		$this->assertTrue( $this->shows( 'event_host,sponsor' ), 'A comma list must still match either role.' );
	}

	/**
	 * The setup helper: after it has run, no block on the /account/ page gates
	 * copy on the event_host role alone, and a sponsor sees a body.
	 */
	public function test_setup_helper_leaves_no_bare_event_host_block(): void {
		$page = get_page_by_path( 'account' );
		if ( ! $page instanceof WP_Post ) {
			$this->markTestSkipped( 'No /account/ page on this environment.' );
		}

		$this->assertContains( law_setup_account_page_audience(), array( 'ok', 'updated' ) );

		$content = get_post_field( 'post_content', $page->ID );
		$this->assertDoesNotMatchRegularExpression(
			'/\[user-content\b[^\]]*\brole=([\'"])event_host\1/',
			$content,
			'The /account/ page still gates copy on the event_host role alone, so a sponsor-only user gets no body.'
		);

		wp_set_current_user( $this->make_user( 'sponsor' ) );
		$this->assertNotSame(
			'',
			trim( wp_strip_all_tags( do_shortcode( $content ) ) ),
			'A sponsor-only user still sees an empty /account/ body.'
		);
	}

	/**
	 * The one audience the page's copy genuinely does not cover, and the
	 * reason templates/account.php keeps a fallback: page 290's Members rows
	 * admit `subscriber`, so a subscriber-only account can open /account/ and
	 * matches neither the attendee nor the host block. The template's
	 * "nothing visible came out" branch is what serves them, and this pins the
	 * condition that branch keys on. A browser pass cannot reach it, because
	 * every role with copy of its own matches a block first.
	 */
	public function test_a_subscriber_gets_no_audience_block_so_the_template_fallback_is_what_serves_them(): void {
		$page = get_page_by_path( 'account' );
		if ( ! $page instanceof WP_Post ) {
			$this->markTestSkipped( 'No /account/ page on this environment.' );
		}

		wp_set_current_user( $this->make_user( 'subscriber' ) );

		$rendered = do_shortcode( get_post_field( 'post_content', $page->ID ) );

		$this->assertSame(
			'',
			trim( wp_strip_all_tags( $rendered ) ),
			'A subscriber now matches an audience block, so templates/account.php no longer needs its fallback for them — revisit the fallback rather than deleting this test.'
		);
	}

	/** Idempotent: a second run reports nothing left to change. */
	public function test_setup_helper_is_idempotent(): void {
		if ( ! get_page_by_path( 'account' ) instanceof WP_Post ) {
			$this->markTestSkipped( 'No /account/ page on this environment.' );
		}

		law_setup_account_page_audience();

		$this->assertSame( 'ok', law_setup_account_page_audience() );
	}
}
