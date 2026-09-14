<?php
/**
 * The [user-content] audience gating (functions/shortcodes.php) and the
 * /account/ page's body helper (law_setup_account_page_content()).
 *
 * These exist because the page shipped with copy gated on role names:
 * [user-content role="attendee"] and [user-content role="event_host"]. Someone
 * who registered as "LAW sponsor" and nothing else matched neither block, and
 * the shortcode renders nothing when no audience matches, so they were served
 * a heading with no body. Nothing failed to say so.
 *
 * That whole class of bug is gone from /account/ itself since 14 September
 * 2026: the page is the Account hub, whose tiles are built in code, and the
 * helper strips the [user-content] blocks from its body. The audience
 * assertions stay because the shortcode still serves copy on OTHER pages, and
 * because 'host' now means "signed in" and that needs pinning: an audience
 * quietly covering nobody is exactly the failure this file was written for.
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
	 * Who the 'host' audience covers: everybody signed in, since anybody
	 * signed in may submit an event. The retired roles are in the table
	 * because an account that has not yet been through migration step 11 still
	 * holds one, and must see the same thing.
	 */
	public static function host_audience(): array {
		return array(
			'subscriber'       => array( 'subscriber', true ),
			'events_committee' => array( 'events_committee', true ),
			'editor'           => array( 'editor', true ),
			'administrator'    => array( 'administrator', true ),
			'legacy event host' => array( 'event_host', true ),
			'legacy attendee'  => array( 'attendee', true ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'host_audience' )]
	public function test_host_audience_per_role( string $role, bool $expected ): void {
		wp_set_current_user( $this->make_user( $role ) );

		$this->assertSame( $expected, $this->shows( 'host' ), "Wrong 'host' audience result for the {$role} role." );
	}

	public function test_committee_audience_excludes_everyone_else(): void {
		wp_set_current_user( $this->make_user( 'events_committee' ) );
		$this->assertTrue( $this->shows( 'committee' ) );

		wp_set_current_user( $this->make_user() );
		$this->assertFalse( $this->shows( 'committee' ), 'A plain subscriber is not the committee.' );
	}

	/** An audience is not a way in for a logged-out visitor. */
	public function test_audiences_are_closed_to_guests(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( $this->shows( 'host' ) );
		$this->assertFalse( $this->shows( 'committee' ) );
		$this->assertTrue( $this->shows( 'guest' ) );
	}

	/**
	 * Role names still match literally, which is what keeps copy written for a
	 * not-yet-migrated audience working during the window between the deploy
	 * and migration step 11.
	 */
	public function test_role_names_still_match_exactly(): void {
		wp_set_current_user( $this->make_user( 'events_committee' ) );

		$this->assertTrue( $this->shows( 'events_committee' ) );
		$this->assertFalse( $this->shows( 'event_host' ), 'A committee member does not hold the event_host role.' );
		$this->assertTrue( $this->shows( 'event_host,events_committee' ), 'A comma list must still match either role.' );
	}

	/**
	 * The setup helper: after it has run, the /account/ body is the action
	 * message and nothing else. The [user-content] blocks have to go rather
	 * than simply being ignored, because the hub renders its own tiles and the
	 * attendee block names a role that will shortly match nobody.
	 */
	public function test_setup_helper_leaves_only_the_action_message(): void {
		$page = get_page_by_path( 'account' );
		if ( ! $page instanceof WP_Post ) {
			$this->markTestSkipped( 'No /account/ page on this environment.' );
		}

		$this->assertContains( law_setup_account_page_content(), array( 'ok', 'updated' ) );

		$content = get_post_field( 'post_content', $page->ID );
		$this->assertStringContainsString( '[action-message]', $content, 'The registration confirmation renders from this shortcode.' );
		$this->assertDoesNotMatchRegularExpression(
			'/\[user-content\b/',
			$content,
			'The /account/ page still carries audience-gated copy, which the hub template does not render.'
		);
	}

	/** The confirmation a new registration lands on still renders. */
	public function test_the_action_message_still_renders_after_registration(): void {
		if ( ! get_page_by_path( 'account' ) instanceof WP_Post ) {
			$this->markTestSkipped( 'No /account/ page on this environment.' );
		}
		law_setup_account_page_content();

		$previous       = $_GET['action'] ?? null;
		$_GET['action'] = 'registered';
		wp_set_current_user( $this->make_user() );

		$rendered = do_shortcode( get_post_field( 'post_content', get_page_by_path( 'account' )->ID ) );

		if ( null === $previous ) {
			unset( $_GET['action'] );
		} else {
			$_GET['action'] = $previous;
		}

		$this->assertStringContainsString( 'Registration successful', $rendered );
	}

	/** Idempotent: a second run reports nothing left to change. */
	public function test_setup_helper_is_idempotent(): void {
		if ( ! get_page_by_path( 'account' ) instanceof WP_Post ) {
			$this->markTestSkipped( 'No /account/ page on this environment.' );
		}

		law_setup_account_page_content();

		$this->assertSame( 'ok', law_setup_account_page_content() );
	}
}
