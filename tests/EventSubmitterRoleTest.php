<?php
/**
 * The `event_submitter` role (22 September 2026).
 *
 * The client stopped wanting open submissions, and the shape of the answer is
 * the thing worth pinning here: starting a NEW event needs the role, while
 * EDITING an event somebody already owns deliberately does not. Page 294
 * (Submit an event) serves both jobs at the same URL, so a change that treats
 * them as one question is the regression this file exists to catch — it would
 * either take editing away from every host or hand submission back to everybody.
 *
 * The role's own permissions live in functions/events/capabilities.php; the
 * two-job split lives in functions/events/submission-form.php and
 * templates/account-event-form.php.
 */
class EventSubmitterRoleTest extends LAW_Test_Case {

	/* The role and its capability ________________________________________ */

	public function test_the_role_exists_and_carries_the_capability(): void {
		$role = get_role( law_events_submitter_role() );

		$this->assertNotNull( $role, 'law_events_grant_capabilities() must register the role on init.' );
		$this->assertTrue( $role->has_cap( law_events_submit_capability() ) );
		$this->assertTrue( $role->has_cap( 'read' ), 'Without read, the account cannot open its own pages.' );
	}

	/**
	 * The role gates ONE thing. It is not a return to the three self-service
	 * roles retired on 14 September 2026, which gated several unrelated things
	 * at once and drifted apart, so it may not pick up the law_event
	 * capability set on the way past.
	 */
	public function test_the_role_carries_nothing_else(): void {
		$role = get_role( law_events_submitter_role() );

		foreach ( law_events_capability_names() as $cap ) {
			$this->assertFalse( $role->has_cap( $cap ), "event_submitter must not hold {$cap}." );
		}
		$this->assertFalse( $role->has_cap( 'edit_others_law_events' ), 'The role is not a committee role.' );

		wp_set_current_user( $this->make_user( law_events_submitter_role() ) );
		$this->assertFalse( law_user_is_committee(), 'A submitter is not committee.' );
	}

	/* Editing survives without the role _________________________________ */

	/**
	 * The whole point of the split. A host who submitted before the role
	 * existed keeps every right over their own event.
	 */
	public function test_an_owner_without_the_role_may_still_manage_their_event(): void {
		$host  = $this->make_user();
		$event = $this->make_event( array(), 'law-proposed', $host );
		wp_set_current_user( $host );

		$this->assertFalse( law_events_user_can_submit(), 'The premise: this host may not start a new event.' );
		$this->assertTrue( law_user_can_manage_event( $host, $event ), 'They must still be able to edit the one they have.' );
	}

	/** A co-owner reaches the event through the meta row, and is unaffected too. */
	public function test_a_co_owner_without_the_role_may_still_manage_the_event(): void {
		$host     = $this->make_user();
		$co_owner = $this->make_user();
		$event    = $this->make_event( array( '_law_co_owner_ids' => array( $co_owner ) ), 'law-proposed', $host );

		$this->assertFalse( law_events_user_can_submit( $co_owner ) );
		$this->assertTrue( law_user_can_manage_event( $co_owner, $event ) );
	}

	/**
	 * The POST handler asks the two questions separately, and which one it asks
	 * depends on whether an event ID came with the request. Asserted against
	 * the source because the handler ends in wp_die()/exit and cannot be driven
	 * from a test; what matters is that the submit check is not at the top,
	 * where it would refuse every edit.
	 */
	public function test_the_handler_asks_for_the_capability_only_when_creating(): void {
		$source = file_get_contents( get_theme_file_path( '/functions/events/submission-form.php' ) );
		$handler = substr( $source, strpos( $source, 'function law_events_form_handler()' ) );

		$this->assertStringContainsString(
			'if ( ! $event_id && ! law_events_user_can_submit( $user_id ) ) {',
			$handler,
			'The create-only gate must test $event_id, or an owner editing their event is refused.'
		);
		$this->assertStringNotContainsString(
			"if ( ! law_events_user_can_submit( \$user_id ) ) {",
			$handler,
			'An unconditional gate would close the edit form to every host without the role.'
		);
	}

	/** The form template refuses a blank form, never an owned event. */
	public function test_the_template_refuses_only_a_new_event(): void {
		$template = file_get_contents( get_theme_file_path( '/templates/account-event-form.php' ) );

		$this->assertStringContainsString(
			'elseif ( ! $law_post && ! law_events_user_can_submit() )',
			$template,
			'Without the $law_post test, an owner without the role is told they cannot edit their own event.'
		);
	}

	/* What the account surfaces offer ____________________________________ */

	/**
	 * My events builds its toolbar button and its empty-state link from one
	 * variable, so the two can never disagree about who is being invited.
	 */
	public function test_my_events_offers_the_submit_url_only_to_a_submitter(): void {
		$template = file_get_contents( get_theme_file_path( '/templates/account-events.php' ) );

		$this->assertStringContainsString(
			'$law_submit_url = law_events_user_can_submit()',
			$template
		);
		$this->assertSame(
			2,
			substr_count( $template, 'esc_url( $law_submit_url )' ),
			'Both call-to-action branches must read the same gated variable.'
		);
	}

	/**
	 * The withdraw dialog may not tell a host to submit a replacement they
	 * would then be refused: they would only find out after withdrawing, when
	 * the event has already gone.
	 */
	public function test_the_withdraw_dialog_does_not_promise_a_replacement(): void {
		$host  = $this->make_user();
		$event = $this->make_event( array(), 'law-proposed', $host );
		wp_set_current_user( $host );

		$copy = $this->withdraw_copy( $event );
		$this->assertNotNull( $copy, 'A proposed event must offer Withdraw.' );
		$this->assertStringNotContainsString( 'submit a new event', $copy );
		$this->assertStringContainsString( 'speak to the committee', $copy );
	}

	/** With the role, the original wording is right again and comes back. */
	public function test_a_submitter_is_told_they_can_raise_a_replacement(): void {
		$host = $this->make_user();
		( new WP_User( $host ) )->add_role( law_events_submitter_role() );
		$event = $this->make_event( array(), 'law-proposed', $host );
		wp_set_current_user( $host );

		$this->assertStringContainsString( 'submit a new event', (string) $this->withdraw_copy( $event ) );
	}

	/**
	 * The Members plugin refuses a page whose role rows do not name the
	 * viewer's role, and the theme cannot see that happening
	 * (law_header_nav_can_view() simply hides the item). An account whose only
	 * role is event_submitter would otherwise be offered nothing at all.
	 */
	public function test_the_account_pages_admit_the_new_role(): void {
		if ( ! function_exists( 'members_can_user_view_post' ) ) {
			$this->markTestSkipped( 'The Members plugin is not active.' );
		}
		law_setup_account_page_roles();

		// members_can_user_view_post() bails on ! is_user_logged_in(), which
		// asks about the CURRENT user rather than the one it was passed, so the
		// session has to be set even though the user ID is explicit.
		$user_id = $this->make_user( law_events_submitter_role() );
		wp_set_current_user( $user_id );

		foreach ( array( 'account', 'account/events', 'account/bookings', 'account/events/submit' ) as $path ) {
			$page = get_page_by_path( $path );
			if ( ! $page instanceof WP_Post ) {
				continue;
			}
			$this->assertTrue(
				members_can_user_view_post( $user_id, $page->ID ),
				"An event_submitter cannot open /{$path}/."
			);
		}
	}

	/* Helper _____________________________________________________________ */

	/** The withdraw dialog's first line for an event, or null if not offered. */
	private function withdraw_copy( int $event_id ): ?string {
		$event = array( 'id' => $event_id, 'status' => 'Proposed' );
		foreach ( law_account_event_actions( $event, array() ) as $action ) {
			if ( isset( $action['form']['modal']['copy'][0] ) ) {
				return (string) $action['form']['modal']['copy'][0];
			}
		}
		return null;
	}
}
