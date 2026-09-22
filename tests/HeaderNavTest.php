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
 * The same list now also feeds the account hub's tiles
 * (parts/layout/account-tiles.php), so what is pinned here is pinned for both
 * surfaces at once. That is the point of there being one list.
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
	 * The item table. Three things decide it now: whether the viewer is
	 * committee, whether they own or co-own an event, and nothing else.
	 *
	 * It used to be a table of ROLES, and that is the change worth pinning.
	 * Since 14 September 2026 there are no self-service roles: everybody is a
	 * subscriber and "My events" is offered to whoever HAS events. So the same
	 * role appears twice below with different expectations, which would have
	 * been impossible before.
	 *
	 * 'submit' is the one item that is still decided by a role, since
	 * 22 September 2026: it needs law_submit_event, which `event_submitter`
	 * and the committee-level roles carry and a plain subscriber does not. Both
	 * sides of that are rows below, so a change to the capability shows up here
	 * as a diff rather than as a silently missing item.
	 *
	 * Personal items come first and the committee tools after (Denis), because
	 * the account hub renders this same list as boxes and somebody arriving
	 * there wants their own account before the queue they also happen to run.
	 *
	 * The sets are asserted EXACTLY. A contains assertion passes when an item
	 * leaks to somebody who should not see it, which is the whole class of bug
	 * this file exists to catch, and the exact set also pins the additive rule:
	 * committee and administrators keep the personal links alongside the
	 * dashboard, and a later "simplification" into an either/or fails here.
	 *
	 * Note the two similar keys: 'bookings' is the COMMITTEE's cross-event
	 * dashboard, 'my_bookings' is the page anyone signed in gets.
	 */
	public static function nav_expectations(): array {
		// 'receptions' sits after 'flagship' because it is a configuration
		// screen for an event LAW runs itself, not one of the two bookings
		// views, which are kept together (RECEPTIONS.md §0.4).
		// 'emails' is last: every item before it is something that happens
		// during the week, and it is the wording the site sends about all of them.
		$committee = array( 'dashboard', 'speakers', 'flagship', 'receptions', 'bookings', 'flagship_bookings', 'discounts', 'emails' );

		return array(
			'subscriber, no events'   => array(
				'subscriber',
				false,
				array( 'profile', 'my_bookings', 'signout' ),
			),
			'subscriber, owns one'    => array(
				'subscriber',
				true,
				array( 'profile', 'my_bookings', 'events', 'signout' ),
			),
			// The same account plus the role: My events is unchanged, Submit an
			// event appears straight after it, and nothing else moves.
			'submitter, no events'    => array(
				'event_submitter',
				false,
				array( 'profile', 'my_bookings', 'submit', 'signout' ),
			),
			'submitter, owns one'     => array(
				'event_submitter',
				true,
				array( 'profile', 'my_bookings', 'events', 'submit', 'signout' ),
			),
			'committee, no events'    => array(
				'events_committee',
				false,
				array_merge( array( 'profile', 'my_bookings', 'submit' ), $committee, array( 'signout' ) ),
			),
			'committee, owns one'     => array(
				'events_committee',
				true,
				array_merge( array( 'profile', 'my_bookings', 'events', 'submit' ), $committee, array( 'signout' ) ),
			),
			'administrator'           => array(
				'administrator',
				false,
				array_merge( array( 'profile', 'my_bookings', 'submit' ), $committee, array( 'signout' ) ),
			),
			'editor'                  => array(
				'editor',
				false,
				array_merge( array( 'profile', 'my_bookings', 'submit' ), $committee, array( 'signout' ) ),
			),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'nav_expectations' )]
	public function test_items_per_situation( string $role, bool $owns_event, array $expected ): void {
		// The bar hides anything the Members plugin would refuse
		// (law_header_nav_can_view()), so the role rows on the account pages
		// have to be provisioned before the expectations below mean anything.
		// This is what catches a new role being offered a page it cannot open:
		// an account whose ONLY role is `event_submitter` sees nothing at all
		// until law_setup_account_page_roles() has run.
		if ( function_exists( 'members_can_current_user_view_post' ) ) {
			law_setup_account_page_roles();
		}

		$user_id = $this->make_user( $role );
		if ( $owns_event ) {
			$this->make_event( array(), 'law-proposed', $user_id );
		}
		wp_set_current_user( $user_id );
		law_account_events_reset_cache();

		$this->assertSame( $expected, $this->keys(), "Wrong top bar items for: {$role}, owns_event=" . ( $owns_event ? 'yes' : 'no' ) );
	}

	/**
	 * A co-owner runs the event too, and reaches it through the _law_co_owner
	 * meta row rather than through anything about their account. Ownership is
	 * the whole test now, so this is the case that would break first if
	 * somebody reached for a simpler one (post_author alone, say).
	 */
	public function test_a_co_owner_is_offered_my_events(): void {
		$owner    = $this->make_user();
		$event    = $this->make_event( array(), 'law-approved', $owner );
		$co_owner = $this->make_user();
		law_event_set_co_owner_ids( $event, array( $co_owner ) );

		wp_set_current_user( $co_owner );
		law_account_events_reset_cache();

		$this->assertContains( 'events', $this->keys(), 'A co-owner runs an event and must be offered My events.' );
	}

	/**
	 * The flagship is LAW's own event and its author is whoever ran the setup
	 * trigger, so counting it would offer that administrator a My events link
	 * to a page that filters it straight back out.
	 */
	public function test_the_flagship_does_not_count_as_an_owned_event(): void {
		if ( ! function_exists( 'law_flagship_ensure_post' ) ) {
			$this->markTestSkipped( 'The flagship module is not loaded.' );
		}
		$flagship = law_flagship_ensure_post();
		if ( empty( $flagship['id'] ) ) {
			$this->markTestSkipped( 'No flagship event on this environment.' );
		}

		$user_id = $this->make_user();
		wp_update_post( array( 'ID' => (int) $flagship['id'], 'post_author' => $user_id ) );
		wp_set_current_user( $user_id );
		law_account_events_reset_cache();

		$this->assertFalse( law_account_user_has_events( $user_id ), 'The flagship is not somebody\'s own event.' );
		$this->assertNotContains( 'events', $this->keys() );
	}

	/**
	 * Every item carries what the account hub renders it with. The hub builds
	 * its boxes from this list and nothing else, so an item added here without
	 * an icon would appear there as a bare label.
	 */
	public function test_every_item_carries_its_hub_fields(): void {
		wp_set_current_user( $this->make_user( 'events_committee' ) );

		foreach ( law_header_nav()['account']['items'] as $item ) {
			$this->assertArrayHasKey( 'group', $item );
			$this->assertContains( $item['group'], array( 'personal', 'committee', 'signout' ), "Unknown group on '{$item['key']}'." );
			$this->assertNotSame( '', (string) $item['icon'], "No icon for '{$item['key']}'." );
			$this->assertNotSame( '', law_icon( $item['icon'] ), "law_icon() has no glyph named '{$item['icon']}'." );

			if ( 'signout' === $item['key'] ) {
				$this->assertSame( 'signout', $item['group'] );
				continue;
			}
			$this->assertNotSame( '', (string) $item['description'], "No description for '{$item['key']}'." );
		}
	}

	/** The committee tools are grouped as such, so the hub can head them. */
	public function test_committee_items_are_grouped_apart(): void {
		wp_set_current_user( $this->make_user( 'events_committee' ) );
		$groups = wp_list_pluck( law_header_nav()['account']['items'], 'group', 'key' );

		$this->assertSame( 'committee', $groups['dashboard'] );
		$this->assertSame( 'personal', $groups['profile'] );
		$this->assertSame( 'personal', $groups['my_bookings'] );
	}

	public function test_signed_out_gets_only_the_two_auth_links(): void {
		wp_set_current_user( 0 );

		$nav = law_header_nav();

		$this->assertNull( $nav['account'], 'Signed-out visitors must not get an account dropdown.' );
		$this->assertSame( array( 'signin', 'register' ), $this->keys() );
	}

	public function test_signed_in_gets_a_named_dropdown_and_no_auth_links(): void {
		$user_id = $this->make_user();
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
		$user_id = $this->make_user();
		wp_update_user( array( 'ID' => $user_id, 'first_name' => '', 'display_name' => 'Grace Brewster Hopper' ) );
		wp_set_current_user( $user_id );

		$nav = law_header_nav();

		$this->assertSame( 'Grace Brewster Hopper', $nav['account']['name'] );
		$this->assertSame( 'Grace', $nav['account']['short_name'] );
	}

	/**
	 * The committee's management links, labelled and in order.
	 *
	 * The two bookings views are named for WHAT they hold, not for what you
	 * do to them, and they sit next to each other (Denis, 10 September 2026).
	 * "Manage bookings" said nothing about which bookings it meant and sat
	 * three items away from the other kind, which is how you end up looking
	 * for a flagship applicant in the hosted list.
	 *
	 * They FOLLOW the personal links now rather than leading (Denis,
	 * 14 September 2026): the account hub renders this list as boxes, and
	 * somebody opening their account wants their own profile and bookings
	 * before the queue they also happen to run. Their order among themselves
	 * is unchanged.
	 */
	public function test_committee_management_links_are_labelled_and_ordered(): void {
		wp_set_current_user( $this->make_committee_user() );
		law_account_events_reset_cache();

		$nav   = law_header_nav();
		$items = wp_list_pluck( $nav['account']['items'], 'label', 'key' );

		$this->assertSame( 'Manage events', $items['dashboard'] );
		$this->assertSame( 'Manage speakers', $items['speakers'] );
		$this->assertSame( 'Manage flagship', $items['flagship'] );
		// "Hosted bookings" until 14 September 2026, when the receptions' own
		// bookings landed in this same view (RECEPTIONS.md §8.3) and the label
		// stopped being true. The description carries which bookings now.
		$this->assertSame( 'Manage bookings', $items['bookings'] );
		$this->assertSame( 'Flagship bookings', $items['flagship_bookings'] );

		$this->assertSame( 'Manage emails', $items['emails'] );

		$keys      = array_keys( $items );
		$committee = array_values( array_intersect( $keys, array( 'dashboard', 'speakers', 'flagship', 'bookings', 'flagship_bookings', 'discounts', 'emails' ) ) );

		$this->assertSame(
			array( 'dashboard', 'speakers', 'flagship', 'bookings', 'flagship_bookings', 'discounts', 'emails' ),
			$committee,
			'The management links keep their order, with the two bookings views adjacent and Manage emails last.'
		);
		$this->assertGreaterThan(
			array_search( 'profile', $keys, true ),
			array_search( 'dashboard', $keys, true ),
			'The personal links come first.'
		);
		$this->assertSame( 'signout', end( $keys ), 'Sign out is always last.' );
	}

	/**
	 * Somebody who has never submitted anything gets their bookings, and NOT a
	 * link to an empty My events page. Nor the invitation to submit, since
	 * 22 September 2026: that needs the `event_submitter` role.
	 */
	public function test_a_user_with_no_events_is_not_offered_my_events(): void {
		wp_set_current_user( $this->make_user() );
		law_account_events_reset_cache();

		$items = wp_list_pluck( law_header_nav()['account']['items'], 'label', 'key' );

		$this->assertSame( 'My bookings', $items['my_bookings'] );
		$this->assertArrayNotHasKey( 'submit', $items, 'A plain subscriber may not start an event, so the bar may not offer the page.' );
		$this->assertArrayNotHasKey( 'events', $items );
	}

	/**
	 * The role is handed out on top of an existing account rather than
	 * replacing it, so the item has to appear for somebody who is still a
	 * subscriber underneath — and in its usual place, after My events.
	 */
	public function test_adding_the_submitter_role_restores_the_item(): void {
		$user_id = $this->make_user();
		( new WP_User( $user_id ) )->add_role( law_events_submitter_role() );
		wp_set_current_user( $user_id );
		law_account_events_reset_cache();

		$items = wp_list_pluck( law_header_nav()['account']['items'], 'label', 'key' );

		$this->assertSame( 'Submit an event', $items['submit'] );
		$this->assertSame( 'My bookings', $items['my_bookings'] );
	}

	/**
	 * Somebody who runs an event gets both, because they run events AND book
	 * places at other people's. The two used to be one item under two names.
	 */
	public function test_an_owner_sees_both_events_and_bookings(): void {
		$user_id = $this->make_user();
		$this->make_event( array(), 'law-proposed', $user_id );
		wp_set_current_user( $user_id );
		law_account_events_reset_cache();

		$items = wp_list_pluck( law_header_nav()['account']['items'], 'label', 'key' );

		$this->assertSame( 'My events', $items['events'] );
		$this->assertSame( 'My bookings', $items['my_bookings'] );
	}

	/**
	 * The two bookings keys point at two different pages. They are one letter
	 * apart in intent and easy to transpose, so pin them.
	 */
	public function test_the_two_bookings_keys_are_different_pages(): void {
		$paths = law_account_paths();

		$this->assertSame( 'account/dashboard/bookings', $paths['bookings'], 'The committee cross-event dashboard.' );
		$this->assertSame( 'account/bookings', $paths['my_bookings'], "The signed-in user's own bookings." );
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

		foreach ( array( 'subscriber', 'events_committee', 'administrator', 'editor' ) as $role ) {
			$user_id = $this->make_user( $role );
			$this->make_event( array(), 'law-proposed', $user_id ); // So the 'events' item is offered too.
			wp_set_current_user( $user_id );
			law_account_events_reset_cache();

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
	 * destination applies (the account hub, or the dashboard for committee).
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
