<?php
/**
 * Where a sign-in lands (functions/auth.php).
 *
 * Nothing covered this before, which is why it is here now: the destination
 * changed on 14 September 2026 from /account/events/ to the account hub, and
 * the committee's shortcut past it is expressed as a COMPARISON against that
 * default (law_auth_committee_redirect()). Change one of the two places the
 * default is written and not the other, and the shortcut silently stops
 * firing, with no error and nothing visibly wrong until a committee member
 * mentions the extra click. law_auth_default_redirect() exists so there is
 * only one place; these tests are what keep it that way.
 */
class AuthRedirectTest extends LAW_Test_Case {

	private $redirect_before;
	private $had_redirect = false;

	protected function setUp(): void {
		parent::setUp();
		$this->had_redirect    = isset( $_REQUEST['redirect_to'] );
		$this->redirect_before = $_REQUEST['redirect_to'] ?? null;
		unset( $_REQUEST['redirect_to'] );
	}

	protected function tearDown(): void {
		if ( $this->had_redirect ) {
			$_REQUEST['redirect_to'] = $this->redirect_before;
		} else {
			unset( $_REQUEST['redirect_to'] );
		}
		parent::tearDown();
	}

	public function test_the_default_destination_is_the_account_hub(): void {
		$this->assertSame( home_url( '/account/' ), law_auth_default_redirect() );
		$this->assertSame(
			law_auth_default_redirect(),
			law_auth_redirect_to(),
			'With no redirect_to, the sign-in default and the shared helper must be the same URL.'
		);
	}

	/** Somebody bounced off a protected page goes back to it, not to the hub. */
	public function test_a_valid_redirect_to_wins_over_the_default(): void {
		$_REQUEST['redirect_to'] = home_url( '/events/some-event/' );

		$this->assertSame( home_url( '/events/some-event/' ), law_auth_redirect_to() );
	}

	public function test_an_off_site_redirect_to_is_dropped(): void {
		$_REQUEST['redirect_to'] = 'https://example.invalid/phish';

		$this->assertSame( law_auth_default_redirect(), law_auth_redirect_to(), 'wp_validate_redirect() must refuse another host.' );
	}

	/**
	 * The committee shortcut, in the two shapes core hands the filter: an
	 * empty redirect_to, and one that is exactly the default.
	 */
	public function test_committee_members_go_straight_to_their_dashboard(): void {
		$user = get_userdata( $this->make_user( 'events_committee' ) );

		$this->assertSame( home_url( '/account/dashboard/' ), law_auth_committee_redirect( '', '', $user ) );
		$this->assertSame( home_url( '/account/dashboard/' ), law_auth_committee_redirect( law_auth_default_redirect(), '', $user ) );
	}

	/** A committee member who asked for somewhere specific still gets it. */
	public function test_an_explicit_destination_survives_the_committee_filter(): void {
		$user = get_userdata( $this->make_user( 'events_committee' ) );
		$url  = home_url( '/events/some-event/' );

		$this->assertSame( $url, law_auth_committee_redirect( $url, '', $user ) );
	}

	public function test_everybody_else_keeps_the_hub(): void {
		$user = get_userdata( $this->make_user() );

		$this->assertSame( law_auth_default_redirect(), law_auth_committee_redirect( law_auth_default_redirect(), '', $user ) );
		$this->assertSame( '', law_auth_committee_redirect( '', '', $user ), 'Nothing to override: core supplies its own default.' );
	}
}
