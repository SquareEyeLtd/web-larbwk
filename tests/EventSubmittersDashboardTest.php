<?php
/**
 * The committee's Event submitters screen
 * (functions/events/submitters-dashboard.php, 22 September 2026).
 *
 * The screen hands out `event_submitter`, so the assertions that matter are
 * about what it must NOT do as much as what it must. Granting may never cost
 * somebody a role they already hold; revoking may never leave an account with
 * no role at all, because the Members rows on /account/ name subscriber and a
 * roleless account would lose its own pages; and neither may reach anybody
 * outside the one role this screen is about.
 */
class EventSubmittersDashboardTest extends LAW_Test_Case {

	/** A subscriber with a name and an organisation, for the search tests. */
	private function make_person( string $first, string $last, string $organisation = '' ): int {
		$user_id = $this->make_user();
		wp_update_user(
			array(
				'ID'           => $user_id,
				'first_name'   => $first,
				'last_name'    => $last,
				'display_name' => trim( $first . ' ' . $last ),
			)
		);
		if ( '' !== $organisation ) {
			update_user_meta( $user_id, 'organisation', $organisation );
		}
		return $user_id;
	}

	/* Granting ___________________________________________________________ */

	public function test_granting_adds_the_role_without_replacing_subscriber(): void {
		$user_id = $this->make_user();
		$actor   = $this->make_committee_user();

		$this->assertTrue( law_submitter_grant( $user_id, $actor ) );

		$roles = (array) ( new WP_User( $user_id ) )->roles;
		$this->assertContains( law_events_submitter_role(), $roles );
		$this->assertContains( 'subscriber', $roles, 'add_role, never set_role: losing subscriber costs them the account pages.' );
		$this->assertTrue( law_events_user_can_submit( $user_id ) );
	}

	/** Who and when, so the list can say where somebody came from. */
	public function test_granting_records_who_did_it_and_when(): void {
		$user_id = $this->make_user();
		$actor   = $this->make_committee_user();
		law_submitter_grant( $user_id, $actor );

		$this->assertGreaterThan( 0, (int) get_user_meta( $user_id, LAW_SUBMITTER_GRANTED_META, true ) );
		$this->assertSame( $actor, (int) get_user_meta( $user_id, LAW_SUBMITTER_GRANTED_BY_META, true ) );
	}

	public function test_granting_refuses_somebody_who_already_holds_the_role(): void {
		$user_id = $this->make_user();
		law_submitter_grant( $user_id, $this->make_committee_user() );

		$again = law_submitter_grant( $user_id, $this->make_committee_user() );
		$this->assertInstanceOf( WP_Error::class, $again );
		$this->assertSame( 'law_submitter_not_eligible', $again->get_error_code() );
	}

	/**
	 * A committee member can already submit through their own role, so granting
	 * this one would change nothing while looking as though it had.
	 */
	public function test_granting_refuses_a_committee_account(): void {
		$result = law_submitter_grant( $this->make_committee_user(), $this->make_committee_user() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'can already submit', $result->get_error_message() );
	}

	public function test_granting_refuses_an_account_that_does_not_exist(): void {
		$this->assertInstanceOf( WP_Error::class, law_submitter_grant( 99999999 ) );
	}

	/* Revoking ___________________________________________________________ */

	public function test_revoking_removes_only_the_one_role(): void {
		$user_id = $this->make_user();
		law_submitter_grant( $user_id, $this->make_committee_user() );

		$this->assertTrue( law_submitter_revoke( $user_id ) );

		$roles = (array) ( new WP_User( $user_id ) )->roles;
		$this->assertNotContains( law_events_submitter_role(), $roles );
		$this->assertContains( 'subscriber', $roles );
		$this->assertFalse( law_events_user_can_submit( $user_id ) );
		$this->assertSame( '', (string) get_user_meta( $user_id, LAW_SUBMITTER_GRANTED_META, true ) );
	}

	/**
	 * An account whose ONLY role is event_submitter — set that way by hand on
	 * the Users screen — must not come out of this with no role at all. A
	 * roleless account cannot open /account/, because the Members rows there
	 * name subscriber, so the screen would have locked somebody out of the site
	 * as a side effect of a permission change.
	 */
	public function test_revoking_never_leaves_an_account_with_no_role(): void {
		$user_id = $this->make_user( law_events_submitter_role() );
		$this->assertSame( array( law_events_submitter_role() ), array_values( (array) ( new WP_User( $user_id ) )->roles ) );

		law_submitter_revoke( $user_id );

		$this->assertSame( array( 'subscriber' ), array_values( (array) ( new WP_User( $user_id ) )->roles ) );
	}

	public function test_revoking_refuses_somebody_who_does_not_hold_the_role(): void {
		$result = law_submitter_revoke( $this->make_user() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_submitter_not_held', $result->get_error_code() );
	}

	/**
	 * Revoking is about the role and nothing else: the person keeps every event
	 * they already run, and can still edit it.
	 */
	public function test_revoking_leaves_their_events_alone(): void {
		$user_id = $this->make_user();
		law_submitter_grant( $user_id, $this->make_committee_user() );
		$event = $this->make_event( array(), 'law-proposed', $user_id );

		law_submitter_revoke( $user_id );

		$this->assertTrue( law_user_can_manage_event( $user_id, $event ) );
		$this->assertSame( $user_id, (int) get_post_field( 'post_author', $event ) );
	}

	/* The list ___________________________________________________________ */

	public function test_the_list_holds_role_holders_and_not_the_committee(): void {
		$submitter = $this->make_person( 'Ada', 'Lovelace', 'Analytical Engines' );
		law_submitter_grant( $submitter, $this->make_committee_user() );
		$committee = $this->make_committee_user();
		$plain     = $this->make_user();

		$ids = wp_list_pluck( law_submitters_rows(), 'id' );

		$this->assertContains( $submitter, $ids );
		$this->assertNotContains( $committee, $ids, 'The committee submit through their own role; this list is the role it hands out.' );
		$this->assertNotContains( $plain, $ids );
	}

	public function test_a_row_carries_what_the_table_prints(): void {
		$user_id = $this->make_person( 'Ada', 'Lovelace', 'Analytical Engines' );
		$actor   = $this->make_committee_user();
		law_submitter_grant( $user_id, $actor );

		$row = null;
		foreach ( law_submitters_rows() as $candidate ) {
			if ( $candidate['id'] === $user_id ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row );
		$this->assertSame( 'Ada Lovelace', $row['name'] );
		$this->assertSame( 'Analytical Engines', $row['organisation'] );
		$this->assertSame( get_userdata( $actor )->display_name, $row['granted_by'] );
	}

	/* The search _________________________________________________________ */

	public function test_search_finds_somebody_by_email(): void {
		$user_id = $this->make_person( 'Grace', 'Hopper' );
		$email   = get_userdata( $user_id )->user_email;

		$this->assertContains( $user_id, wp_list_pluck( law_submitters_search( $email ), 'id' ) );
	}

	public function test_search_finds_somebody_by_display_name(): void {
		$user_id = $this->make_person( 'Grace', 'Hopper' );

		$this->assertContains( $user_id, wp_list_pluck( law_submitters_search( 'Hopper' ), 'id' ) );
	}

	/**
	 * The half of the ask WP_User_Query cannot do on its own: `search` covers
	 * the user table, and the two halves of a name live in user meta, so a
	 * surname that never reached the display name still has to be findable.
	 */
	public function test_search_finds_somebody_by_a_last_name_not_in_their_display_name(): void {
		$user_id = $this->make_user();
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Katherine', 'last_name' => 'Johnson', 'display_name' => 'KJ' ) );

		$this->assertContains( $user_id, wp_list_pluck( law_submitters_search( 'Johnson' ), 'id' ) );
	}

	public function test_search_ignores_a_term_too_short_to_mean_anything(): void {
		$this->make_person( 'Grace', 'Hopper' );

		$this->assertSame( array(), law_submitters_search( 'H' ) );
	}

	/**
	 * Somebody who cannot be added is returned WITH a reason rather than
	 * filtered out. A match that silently vanished would be indistinguishable
	 * from having no account at all, which is the question being asked.
	 */
	public function test_search_returns_the_ineligible_with_a_reason(): void {
		$already = $this->make_person( 'Ada', 'Lovelace' );
		law_submitter_grant( $already, $this->make_committee_user() );

		$row = null;
		foreach ( law_submitters_search( 'Lovelace' ) as $candidate ) {
			if ( $candidate['id'] === $already ) {
				$row = $candidate;
			}
		}

		$this->assertNotNull( $row, 'An existing submitter must still be found.' );
		$this->assertFalse( $row['can'] );
		$this->assertSame( 'Already a submitter', $row['reason'] );
	}

	public function test_an_exact_email_match_sorts_first(): void {
		$this->make_person( 'Zoe', 'Match' );
		$target = $this->make_person( 'Aaron', 'Match' );
		$email  = get_userdata( $target )->user_email;
		// The other account's email contains the target's as a fragment, so an
		// alphabetical sort would put Aaron second on name alone.
		$results = law_submitters_search( $email );

		$this->assertNotEmpty( $results );
		$this->assertSame( $target, $results[0]['id'] );
	}

	/* What the Add form resolves to _____________________________________ */

	public function test_a_posted_id_wins_over_the_typed_text(): void {
		$picked = $this->make_person( 'Ada', 'Lovelace' );
		$_POST  = array( 'law_submitter_id' => (string) $picked, 'law_submitter_email' => 'someone.else@example.test' );

		$resolved = law_submitters_resolve_posted();
		$_POST    = array();

		$this->assertInstanceOf( WP_User::class, $resolved );
		$this->assertSame( $picked, (int) $resolved->ID );
	}

	public function test_a_typed_email_resolves_without_javascript(): void {
		$user_id = $this->make_person( 'Ada', 'Lovelace' );
		$_POST   = array( 'law_submitter_email' => get_userdata( $user_id )->user_email );

		$resolved = law_submitters_resolve_posted();
		$_POST    = array();

		$this->assertInstanceOf( WP_User::class, $resolved );
		$this->assertSame( $user_id, (int) $resolved->ID );
	}

	public function test_an_email_with_no_account_says_so(): void {
		$_POST    = array( 'law_submitter_email' => 'nobody-' . wp_generate_password( 6, false ) . '@example.test' );
		$resolved = law_submitters_resolve_posted();
		$_POST    = array();

		$this->assertInstanceOf( WP_Error::class, $resolved );
		$this->assertSame( 'law_submitter_no_account', $resolved->get_error_code() );
	}

	/** A name typed and nothing picked: say what the field will take. */
	public function test_a_name_with_nobody_chosen_asks_for_a_choice(): void {
		$_POST    = array( 'law_submitter_email' => 'Ada Lovelace' );
		$resolved = law_submitters_resolve_posted();
		$_POST    = array();

		$this->assertInstanceOf( WP_Error::class, $resolved );
		$this->assertSame( 'law_submitter_unresolved', $resolved->get_error_code() );
	}

	/* The screen ________________________________________________________ */

	/**
	 * Every write surface on this screen is committee-only, and there are four:
	 * the two handlers, the search endpoint and the page itself. Asserted
	 * against the source because all four end in wp_send_json/exit and cannot
	 * be driven from a test — what is being pinned is that none of them was
	 * written without the check.
	 */
	public function test_every_surface_checks_for_the_committee(): void {
		$source = file_get_contents( get_theme_file_path( '/functions/events/submitters-dashboard.php' ) );

		// Per function rather than a count of occurrences: a count passes when
		// one check is deleted and another added somewhere harmless.
		$regions = array(
			'law_submitters_search_endpoint' => 'function law_submitters_search_endpoint()',
			'law_submitter_add_handler'      => 'function law_submitter_add_handler()',
			'law_submitter_remove_handler'   => 'function law_submitter_remove_handler()',
		);
		foreach ( $regions as $name => $signature ) {
			$at   = strpos( $source, $signature );
			$this->assertIsInt( $at, "{$name}() has been renamed; this test has to follow it." );
			$body = substr( $source, $at, 1200 );
			$this->assertStringContainsString( '! law_user_is_committee()', $body, "{$name}() must refuse anybody but the committee." );
		}

		$template = file_get_contents( get_theme_file_path( '/templates/account-dashboard-submitters.php' ) );
		$this->assertStringContainsString( 'law_user_is_committee()', $template, 'The Members restriction filters the_content(), which this template never calls.' );
		$this->assertStringContainsString( 'nocache_headers()', $template, 'One committee member\'s view must never be served to the next visitor.' );
	}

	/** Both provisioning routes must name the page, or environments disagree. */
	public function test_both_provisioning_routes_create_the_page(): void {
		$map = law_migration_page_map();
		$this->assertSame( 'templates/account-dashboard-submitters.php', $map['account/dashboard/submitters']['template'] );

		$setup = file_get_contents( get_theme_file_path( '/functions/setup-account-pages.php' ) );
		$this->assertStringContainsString( "\$setup['account/dashboard/submitters'] = 'templates/account-dashboard-submitters.php';", $setup );
		$this->assertStringContainsString( 'law_setup_submitters_dashboard_access()', $setup, 'The page needs its parent\'s committee rows copied onto it.' );
	}

	/** The screen is offered to the committee and to nobody else. */
	public function test_only_the_committee_is_offered_the_screen(): void {
		wp_set_current_user( $this->make_committee_user() );
		law_account_events_reset_cache();
		$this->assertContains( 'submitters', wp_list_pluck( law_header_nav()['account']['items'], 'key' ) );

		$submitter = $this->make_user();
		( new WP_User( $submitter ) )->add_role( law_events_submitter_role() );
		wp_set_current_user( $submitter );
		law_account_events_reset_cache();
		$this->assertNotContains( 'submitters', wp_list_pluck( law_header_nav()['account']['items'], 'key' ), 'Holding the role is not permission to hand it out.' );
	}

	/** The nav item needs an icon that exists, or the hub tile renders blank. */
	public function test_the_screen_has_an_icon(): void {
		$this->assertNotSame( '', law_icon( 'user-check' ) );
	}
}
