<?php
/**
 * The retirement of the event_host, sponsor and attendee roles
 * (ROLES_AND_ACCOUNT_HUB.md, 14 September 2026).
 *
 * Three things are covered, and each of them fails silently if it is wrong,
 * which is why they are pinned rather than left to a browser pass:
 *
 *  1. The two gates now mean "signed in". If they were to keep testing roles,
 *     nothing would error; every ordinary user would simply lose the ability
 *     to submit an event the moment migration step 11 ran.
 *  2. The guard in law_registration_apply_attendee_profile(). It reads "this
 *     account holds nothing beyond a self-service role, so a host may fill in
 *     its blanks". Leave it comparing against the old role map and it returns
 *     early for EVERY account, so people booked in by phone quietly stop
 *     getting their country and dietary requirements recorded.
 *  3. Step 11's per-user logic, which must never demote an administrator,
 *     never leave an account role-less, and never overwrite an intent somebody
 *     has since chosen for themselves.
 *
 * Step 11's own runner is deliberately NOT driven here: it writes to the
 * migration log table, and creating that table is DDL, which commits the
 * transaction this suite rolls each test back with (see tests/bootstrap.php).
 * The judgement all lives in the per-user helper, so that is what is tested.
 */
class RoleRetirementTest extends LAW_Test_Case {

	/* The two gates ______________________________________________________ */

	public function test_any_signed_in_account_may_submit_an_event(): void {
		$user_id = $this->make_user();
		wp_set_current_user( $user_id );

		$this->assertTrue( law_events_user_can_submit(), 'A plain subscriber may submit.' );
		$this->assertTrue( law_events_user_can_submit( $user_id ), 'The explicit-user form must agree with the current-user form.' );
		$this->assertTrue( law_account_user_is_host_like() );
	}

	public function test_the_gates_are_still_closed_to_a_logged_out_visitor(): void {
		wp_set_current_user( 0 );

		$this->assertFalse( law_events_user_can_submit() );
		$this->assertFalse( law_account_user_is_host_like() );
	}

	/* The on-behalf profile guard ________________________________________ */

	/** @return array{0:int,1:string} A user id and their email address. */
	private function make_account( array $roles ): array {
		$user_id = $this->make_user( array_shift( $roles ) );
		$user    = new WP_User( $user_id );
		foreach ( $roles as $role ) {
			$user->add_role( $role );
		}
		return array( $user_id, $user->user_email );
	}

	private function clean_profile(): array {
		return law_registration_clean_attendee_profile(
			array(
				'country'       => 'United Kingdom',
				'accessibility' => array(),
				'dietary'       => array(),
			)
		);
	}

	public function test_a_host_may_fill_the_blanks_on_an_ordinary_account(): void {
		list( $user_id ) = $this->make_account( array( 'subscriber' ) );

		$written = law_registration_apply_attendee_profile( $user_id, $this->clean_profile(), false );

		$this->assertNotEmpty( $written, 'A subscriber is an ordinary person being booked in.' );
		$this->assertSame( 'United Kingdom', get_user_meta( $user_id, 'country', true ) );
	}

	/**
	 * The window between the deploy and migration step 11: an account that
	 * still holds a retired role is still an ordinary person.
	 */
	public function test_a_not_yet_migrated_account_is_still_ordinary(): void {
		list( $user_id ) = $this->make_account( array( 'subscriber', 'attendee' ) );

		$this->assertNotEmpty( law_registration_apply_attendee_profile( $user_id, $this->clean_profile(), false ) );
	}

	/**
	 * The security property this guard was added for (11 September 2026):
	 * whoever fills the form chooses the email address, so a host must not be
	 * able to write health-adjacent data onto a committee member's account by
	 * knowing their address.
	 */
	public function test_privileged_accounts_are_never_written_to(): void {
		list( $committee ) = $this->make_account( array( 'events_committee' ) );
		list( $admin )     = $this->make_account( array( 'administrator', 'subscriber' ) );

		$this->assertSame( array(), law_registration_apply_attendee_profile( $committee, $this->clean_profile(), false ) );
		$this->assertSame( array(), law_registration_apply_attendee_profile( $admin, $this->clean_profile(), false ) );
		$this->assertSame( '', get_user_meta( $committee, 'country', true ) );
		$this->assertSame( '', get_user_meta( $admin, 'country', true ) );
	}

	/* Migration step 11 __________________________________________________ */

	public function test_a_dry_run_changes_nothing(): void {
		list( $user_id ) = $this->make_account( array( 'event_host', 'attendee' ) );
		$user            = new WP_User( $user_id );

		$result = law_migration_retire_user_roles( $user, true );

		$this->assertSame( 'dry-run', $result['status'] );
		$this->assertSame( array( 'event_host', 'attendee' ), $result['removed'] );
		$this->assertSame( array( 'host' ), $result['intents'] );

		$after = new WP_User( $user_id );
		$this->assertContains( 'event_host', (array) $after->roles, 'A dry run must write nothing at all.' );
		$this->assertFalse( metadata_exists( 'user', $user_id, 'law_intent' ) );
	}

	public function test_an_event_host_becomes_a_subscriber_with_the_hosting_tick(): void {
		list( $user_id ) = $this->make_account( array( 'event_host', 'attendee' ) );

		$result = law_migration_retire_user_roles( new WP_User( $user_id ), false );

		$this->assertSame( 'created', $result['status'] );
		$this->assertSame( array( 'subscriber' ), array_values( (array) ( new WP_User( $user_id ) )->roles ) );
		$this->assertSame( array( 'host' ), law_registration_read_intent( $user_id ) );
	}

	public function test_a_sponsor_keeps_the_sponsor_tick(): void {
		list( $user_id ) = $this->make_account( array( 'sponsor' ) );

		law_migration_retire_user_roles( new WP_User( $user_id ), false );

		$this->assertSame( array( 'subscriber' ), array_values( (array) ( new WP_User( $user_id ) )->roles ) );
		$this->assertSame( array( 'sponsor' ), law_registration_read_intent( $user_id ) );
	}

	/**
	 * Attendee was what everybody was, so it says nothing about anybody and
	 * seeds no tick. The row still has to exist: an empty array means "asked,
	 * ticked nothing", and only the ABSENCE of a row means "never asked".
	 */
	public function test_an_attendee_seeds_no_tick_but_still_gets_a_row(): void {
		list( $user_id ) = $this->make_account( array( 'attendee' ) );

		law_migration_retire_user_roles( new WP_User( $user_id ), false );

		$this->assertSame( array(), law_registration_read_intent( $user_id ) );
		$this->assertTrue( metadata_exists( 'user', $user_id, 'law_intent' ) );
	}

	/**
	 * Two live accounts hold administrator alongside a retired role, one of
	 * them a person who runs the site. A set_role() sweep would demote them.
	 */
	public function test_a_privileged_role_alongside_a_retired_one_survives(): void {
		list( $admin )     = $this->make_account( array( 'administrator', 'event_host' ) );
		list( $committee ) = $this->make_account( array( 'events_committee', 'attendee' ) );

		law_migration_retire_user_roles( new WP_User( $admin ), false );
		law_migration_retire_user_roles( new WP_User( $committee ), false );

		$admin_roles = (array) ( new WP_User( $admin ) )->roles;
		$this->assertContains( 'administrator', $admin_roles );
		$this->assertNotContains( 'event_host', $admin_roles );

		$committee_roles = (array) ( new WP_User( $committee ) )->roles;
		$this->assertContains( 'events_committee', $committee_roles );
		$this->assertNotContains( 'attendee', $committee_roles );
	}

	public function test_an_account_is_never_left_role_less(): void {
		list( $user_id ) = $this->make_account( array( 'attendee' ) );

		$result = law_migration_retire_user_roles( new WP_User( $user_id ), false );

		$this->assertTrue( $result['added_subscriber'] );
		$this->assertNotEmpty( (array) ( new WP_User( $user_id ) )->roles );
	}

	public function test_a_second_pass_finds_nothing_to_do(): void {
		list( $user_id ) = $this->make_account( array( 'event_host' ) );
		law_migration_retire_user_roles( new WP_User( $user_id ), false );

		$again = law_migration_retire_user_roles( new WP_User( $user_id ), false );

		$this->assertSame( 'skipped', $again['status'], 'A processed account must not be touched twice.' );
		$this->assertSame( array(), $again['removed'] );
	}

	/**
	 * Somebody who edited their profile between two runs owns their answer.
	 * The step must read the row's existence, not its contents: an empty array
	 * is a deliberate "none of these", and re-seeding it would put a tick back
	 * that the person had just taken off.
	 */
	public function test_an_intent_the_person_has_already_chosen_is_never_overwritten(): void {
		list( $user_id ) = $this->make_account( array( 'event_host' ) );
		law_registration_write_intent( $user_id, array() ); // They unticked everything.

		$result = law_migration_retire_user_roles( new WP_User( $user_id ), false );

		$this->assertFalse( $result['seeded'] );
		$this->assertSame( array(), law_registration_read_intent( $user_id ) );
	}

	/* Account creation ___________________________________________________ */

	/**
	 * law_events_create_host_user() mints accounts from an email address
	 * somebody typed into a form (a co-owner row, a colleague on a booking),
	 * so the one thing it must never do is let a caller's array decide what
	 * that account can do. No caller passes a role today; this is the guard
	 * that keeps a future one from becoming an escalation path.
	 */
	public function test_created_accounts_are_subscribers_and_the_role_arg_is_whitelisted(): void {
		$plain = law_events_create_host_user( 'law-test-' . wp_generate_password( 8, false ) . '@example.test', 'Plain Person' );
		$this->assertIsInt( $plain );
		$this->users[] = $plain;
		$this->assertSame( array( 'subscriber' ), array_values( (array) ( new WP_User( $plain ) )->roles ) );

		foreach ( array( 'administrator', 'editor', 'events_committee', 'event_host', 'nonsense' ) as $attempt ) {
			$id = law_events_create_host_user(
				'law-test-' . wp_generate_password( 8, false ) . '@example.test',
				'Sneaky Person',
				'',
				array( 'role' => $attempt )
			);
			$this->assertIsInt( $id );
			$this->users[] = $id;
			$this->assertSame(
				array( 'subscriber' ),
				array_values( (array) ( new WP_User( $id ) )->roles ),
				"A caller asking for '{$attempt}' must still get a subscriber."
			);
		}
	}

	/* The Members restriction step 11 depends on _________________________ */

	/**
	 * Without this, step 11 locks people out of their own account: the roles
	 * come off, and the Members plugin refuses a page whose restriction names
	 * only the roles they no longer hold.
	 */
	public function test_every_account_page_admits_subscribers(): void {
		if ( ! get_page_by_path( 'account' ) instanceof WP_Post ) {
			$this->markTestSkipped( 'No /account/ page on this environment.' );
		}

		$result = law_setup_account_subscriber_access();
		$this->assertTrue( 'ok' === $result || str_contains( $result, 'updated' ), "Unexpected result: {$result}" );

		foreach ( array( 'account', 'account/events', 'account/bookings', 'account/events/submit' ) as $path ) {
			$page = get_page_by_path( $path );
			if ( ! $page instanceof WP_Post ) {
				continue;
			}
			$roles = get_post_meta( $page->ID, '_members_access_role' );
			if ( ! $roles ) {
				continue; // Unrestricted pages are public; nothing to add.
			}
			$this->assertContains( 'subscriber', $roles, "/{$path}/ would refuse a subscriber." );
		}

		$this->assertSame( 'ok', law_setup_account_subscriber_access(), 'The helper must be idempotent.' );
	}
}
