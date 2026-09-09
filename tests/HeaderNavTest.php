<?php
/**
 * The header top bar's visibility rules (functions/header-nav.php).
 *
 * These are the tests the old arrangement needed and never had. Visibility
 * used to live in If Menu postmeta on the menu items while page access lived
 * in Members role meta on the pages, and the two drifted: sponsors ended up
 * with no account link at all and attendees had no route to their bookings,
 * and nothing failed to say so.
 *
 * The sets are asserted EXACTLY rather than with assertContains. A contains
 * assertion passes when an item leaks to a role that should not see it,
 * which is the whole class of bug this file exists to catch. Asserting the
 * exact set also pins the rule that access is additive: committee and
 * administrators keep the personal links alongside the dashboard, and a
 * later "simplification" into an either/or fails here.
 */

class HeaderNavTest extends LAW_Test_Case {

	/** Item keys, in order, for the current user. */
	private function keys(): array {
		$nav = law_header_nav();

		if ( $nav['account'] ) {
			return wp_list_pluck( $nav['account']['items'], 'key' );
		}

		return wp_list_pluck( $nav['links'], 'key' );
	}

	/**
	 * The resolved per-role table from the plan. Committee and admins get the
	 * dashboard IN ADDITION TO the personal links, never instead of them.
	 *
	 * 'flagship' joined the committee-only group on 9 September 2026 with the
	 * Manage flagship dashboard (functions/events/flagship-dashboard.php).
	 */
	public static function role_expectations(): array {
		return array(
			'administrator'    => array( 'administrator', array( 'dashboard', 'bookings', 'speakers', 'flagship', 'events', 'submit', 'profile', 'signout' ) ),
			'editor'           => array( 'editor', array( 'dashboard', 'bookings', 'speakers', 'flagship', 'events', 'submit', 'profile', 'signout' ) ),
			'events_committee' => array( 'events_committee', array( 'dashboard', 'bookings', 'speakers', 'flagship', 'events', 'submit', 'profile', 'signout' ) ),
			'event_host'       => array( 'event_host', array( 'events', 'submit', 'profile', 'signout' ) ),
			'sponsor'          => array( 'sponsor', array( 'events', 'submit', 'profile', 'signout' ) ),
			'attendee'         => array( 'attendee', array( 'events', 'profile', 'signout' ) ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'role_expectations' )]
	public function test_items_per_role( string $role, array $expected ): void {
		wp_set_current_user( $this->make_user( $role ) );

		$this->assertSame( $expected, $this->keys(), "Wrong top bar items for the {$role} role." );
	}

	public function test_signed_out_gets_only_the_two_auth_links(): void {
		wp_set_current_user( 0 );

		$nav = law_header_nav();

		$this->assertNull( $nav['account'], 'Signed-out visitors must not get an account dropdown.' );
		$this->assertSame( array( 'signin', 'register' ), $this->keys() );
	}

	public function test_signed_in_gets_a_named_dropdown_and_no_auth_links(): void {
		$user_id = $this->make_user( 'event_host' );
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Ada', 'display_name' => 'Ada Lovelace' ) );
		wp_set_current_user( $user_id );

		$nav = law_header_nav();

		$this->assertNotNull( $nav['account'] );
		$this->assertSame( array(), $nav['links'], 'Sign in and Register must not appear to a signed-in user.' );
		$this->assertSame( 'Ada Lovelace', $nav['account']['name'], 'The desktop trigger reads "Logged in as" plus the display name.' );
		$this->assertSame( 'Ada', $nav['account']['short_name'], 'The mobile button has room for the first name only.' );
		$this->assertNotContains( 'signin', $this->keys() );
	}

	/** A user with no first name on file still gets a one-word mobile label. */
	public function test_short_name_falls_back_to_the_first_word_of_the_display_name(): void {
		$user_id = $this->make_user( 'attendee' );
		wp_update_user( array( 'ID' => $user_id, 'first_name' => '', 'display_name' => 'Grace Brewster Hopper' ) );
		wp_set_current_user( $user_id );

		$nav = law_header_nav();

		$this->assertSame( 'Grace Brewster Hopper', $nav['account']['name'] );
		$this->assertSame( 'Grace', $nav['account']['short_name'] );
	}

	/** The committee's three management links, in the order the dropdown offers them. */
	public function test_committee_management_links_are_labelled_and_ordered(): void {
		wp_set_current_user( $this->make_committee_user() );

		$nav   = law_header_nav();
		$items = wp_list_pluck( $nav['account']['items'], 'label', 'key' );

		$this->assertSame( 'Manage events', $items['dashboard'] );
		$this->assertSame( 'Manage bookings', $items['bookings'] );
		$this->assertSame( 'Manage speakers', $items['speakers'] );
		$this->assertSame(
			array( 'dashboard', 'bookings', 'speakers' ),
			array_slice( array_keys( $items ), 0, 3 ),
			'The management links lead the dropdown, before the personal ones.'
		);
	}

	/** An attendee's route to their bookings, and the label that describes it. */
	public function test_attendee_reaches_bookings_under_a_bookings_label(): void {
		wp_set_current_user( $this->make_user( 'attendee' ) );

		$nav   = law_header_nav();
		$items = wp_list_pluck( $nav['account']['items'], 'label', 'key' );

		$this->assertArrayHasKey( 'events', $items );
		$this->assertSame( 'My bookings', $items['events'] );
	}

	/** A host sees the same page described as their events. */
	public function test_host_like_user_sees_the_events_label(): void {
		wp_set_current_user( $this->make_user( 'event_host' ) );

		$items = wp_list_pluck( law_header_nav()['account']['items'], 'label', 'key' );

		$this->assertSame( 'My events', $items['events'] );
	}

	/**
	 * The test that would have caught the sponsor gap: never link a page the
	 * user cannot open.
	 *
	 * This asserts against the real local database (the account pages and
	 * their _members_access_role meta), so it means something different after
	 * a database refresh. That coupling is deliberate. Synthetic fixtures
	 * would test the mechanism; the question worth asking is whether the live
	 * configuration and the code agree.
	 */
	public function test_every_linked_page_is_actually_viewable(): void {
		if ( ! function_exists( 'members_can_user_view_post' ) ) {
			$this->markTestSkipped( 'The Members plugin is not active.' );
		}

		foreach ( array_keys( self::role_expectations() ) as $role ) {
			$user_id = $this->make_user( $role );
			wp_set_current_user( $user_id );

			foreach ( law_header_nav()['account']['items'] as $item ) {
				if ( 'signout' === $item['key'] ) {
					continue;
				}

				$page_id = law_account_page_id( $item['key'] );
				if ( ! $page_id ) {
					continue;
				}

				$this->assertTrue(
					members_can_user_view_post( $user_id, $page_id ),
					"The {$role} role is offered '{$item['key']}' but cannot view page {$page_id}."
				);
			}
		}
	}

	/**
	 * Run $fn as if the front end were serving $path (relative to the site
	 * root, e.g. "/events/x/?tab=book"), optionally with a resolved main
	 * query, then put the globals back.
	 */
	private function on_front_end_request( string $path, ?WP_Query $query, callable $fn ) {
		$uri_before     = $_SERVER['REQUEST_URI'] ?? null;
		$request_before = $GLOBALS['wp']->request;
		$query_before   = $GLOBALS['wp_query'];

		// What the web server would hand PHP: the install's own path included.
		$_SERVER['REQUEST_URI']  = wp_parse_url( home_url( $path ), PHP_URL_PATH )
			. ( ( $q = wp_parse_url( $path, PHP_URL_QUERY ) ) ? '?' . $q : '' );
		$GLOBALS['wp']->request  = trim( wp_parse_url( $path, PHP_URL_PATH ), '/' );
		if ( $query ) {
			$GLOBALS['wp_query'] = $query;
		}

		try {
			return $fn();
		} finally {
			if ( null === $uri_before ) {
				unset( $_SERVER['REQUEST_URI'] );
			} else {
				$_SERVER['REQUEST_URI'] = $uri_before;
			}
			$GLOBALS['wp']->request = $request_before;
			$GLOBALS['wp_query']    = $query_before;
		}
	}

	/**
	 * On a subdirectory install (home_url() = http://host/law) the request
	 * URI already contains /law/. Passing it through home_url() produced
	 * /law/law/, which WordPress's 404 guesser then "corrected" to the first
	 * post whose slug starts with "law": an organisation page nobody wanted.
	 */
	public function test_sign_in_returns_to_the_current_page_without_doubling_the_install_path(): void {
		$url = $this->on_front_end_request(
			'/events/some-event/?tab=book',
			null,
			fn() => law_header_nav_current_url()
		);

		$this->assertSame( home_url( '/events/some-event/?tab=book' ), $url );

		$path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '/' !== $path ) {
			$this->assertStringNotContainsString( untrailingslashit( $path ) . $path, $url, 'The install path must appear once.' );
		}

		$link = $this->on_front_end_request(
			'/events/some-event/',
			null,
			fn() => law_header_nav()['links'][0]['url']
		);
		$this->assertStringContainsString( 'redirect_to=' . rawurlencode( home_url( '/events/some-event/' ) ), $link );
	}

	/**
	 * Nobody signs in to get back to the home page: from there the default
	 * destination applies (My events, or the dashboard for committee).
	 */
	public function test_sign_in_from_the_home_page_goes_to_the_default_destination(): void {
		$front = (int) get_option( 'page_on_front' );
		if ( ! $front || 'page' !== get_option( 'show_on_front' ) ) {
			$this->markTestSkipped( 'No static front page configured.' );
		}

		$link = $this->on_front_end_request(
			'/',
			new WP_Query( array( 'page_id' => $front ) ),
			function () {
				$this->assertTrue( is_front_page(), 'Fixture: the main query resolves to the front page.' );
				$this->assertSame( '', law_header_nav_current_url() );
				return law_header_nav()['links'][0]['url'];
			}
		);

		$this->assertStringNotContainsString( 'redirect_to', $link );
	}

	public function test_account_url_resolves_by_path_and_falls_back(): void {
		$this->assertStringContainsString( '/account/profile/', law_account_url( 'profile' ) );
		$this->assertSame( '', law_account_url( 'no-such-key' ), 'An unknown key has no URL.' );

		// A missing page must still produce an honest URL, never an empty
		// href that silently resolves to the home page.
		$this->assertNotSame( '', law_account_url( 'dashboard' ) );
	}
}
