<?php
/**
 * Phase D registration/profile: role handling can never escalate, choice
 * inputs are whitelisted, and the HubSpot tags mirror the old behaviour.
 */
class RegistrationTest extends LAW_Test_Case {

	public function test_role_sync_never_touches_privileged_roles(): void {
		$user_id = $this->make_user( 'events_committee' );
		( new WP_User( $user_id ) )->add_role( 'event_host' );

		// The user unticks everything: self-service roles go, committee stays.
		law_registration_sync_roles( $user_id, array() );
		$user = new WP_User( $user_id );
		$this->assertContains( 'events_committee', (array) $user->roles, 'Privileged roles are never removed by self-service.' );
		$this->assertNotContains( 'event_host', (array) $user->roles );
	}

	public function test_role_sync_floor_is_attendee(): void {
		$user_id = $this->make_user( 'attendee' );
		law_registration_sync_roles( $user_id, array() );
		$this->assertContains( 'attendee', (array) ( new WP_User( $user_id ) )->roles, 'A user can never end up role-less.' );
	}

	public function test_profile_meta_whitelists_choices_and_roles(): void {
		$user_id = $this->make_user( 'attendee' );
		$roles   = law_registration_write_profile_meta(
			$user_id,
			array(
				'organisation'  => 'Test Firm <script>x</script>',
				'job_title'     => 'Partner',
				'country'       => 'United Kingdom',
				// administrator must be silently dropped; junk choices too.
				'roles'         => array( 'administrator', 'events_committee', 'event_host' ),
				'accessibility' => array( 'Wheelchair', 'I require wheelchair access', 'FAKE CHOICE' ),
				'dietary'       => array( 'Vegan', '<img onerror=1>' ),
			)
		);

		$this->assertSame( array( 'event_host' ), $roles, 'Only self-service roles survive: no self-assigned administrator or committee.' );
		if ( function_exists( 'get_field' ) ) {
			// The canonical stored accessibility format is the short VALUE
			// ('Wheelchair'), never the display label.
			$this->assertSame( array( 'Wheelchair' ), (array) get_field( 'accessibility', 'user_' . $user_id ) );
		}
		// sanitize_text_field strips script tags WITH their content.
		$this->assertSame( 'Test Firm', get_user_meta( $user_id, 'organisation', true ) );
		$this->assertSame( 'United Kingdom', get_user_meta( $user_id, 'country', true ) );
	}

	public function test_hubspot_tags_mirror_the_old_mapping(): void {
		$year = (string) law_events_setting( 'year', 2026 );
		$this->assertSame( "{$year} Registered user", law_registration_hubspot_tags( array( 'attendee' ) ) );
		$this->assertSame( "{$year} Registered user;{$year} Sponsor", law_registration_hubspot_tags( array( 'sponsor' ) ) );
		$this->assertSame(
			"{$year} Registered user;{$year} Sponsor;{$year} Event Host",
			law_registration_hubspot_tags( array( 'sponsor', 'event_host' ) )
		);
	}

	public function test_welcome_email_matches_the_roles_ticked(): void {
		$this->assertSame( 'user_welcome_registered', law_registration_welcome_slug( array( 'attendee' ) ) );
		$this->assertSame( 'user_welcome_registered', law_registration_welcome_slug( array() ), 'No roles means the attendee floor.' );
		$this->assertSame( 'user_welcome_registered_host', law_registration_welcome_slug( array( 'event_host' ) ) );
		$this->assertSame( 'user_welcome_registered_host', law_registration_welcome_slug( array( 'sponsor' ) ) );
		$this->assertSame(
			'user_welcome_registered_host',
			law_registration_welcome_slug( array( 'event_host', 'attendee' ) ),
			'Host wins over attendee, so a host is not asked for dietary requirements up front.'
		);
	}

	public function test_the_two_welcome_templates_read_for_their_audience(): void {
		$attendee = law_events_email( 'user_welcome_registered' );
		$host     = law_events_email( 'user_welcome_registered_host' );
		$this->assertNotNull( $host, 'The host welcome is in the registry, so it is editable on the Emails screen.' );
		$this->assertTrue( (bool) $host['active'] );
		$this->assertStringContainsString( '{submit_link}', $host['body'] );
		$this->assertStringNotContainsString( '{submit_link}', $attendee['body'] );
		$this->assertStringContainsString( 'browse the programme', $attendee['body'] );
	}

	public function test_registration_rate_limit_is_per_ip_for_anonymous(): void {
		$_SERVER['REMOTE_ADDR'] = '203.0.113.77';
		$allowed = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			if ( law_events_rate_limit_ok( 'register_test_' . wp_rand(), 0, 5, 60 ) ) {
				$allowed++;
			}
		}
		$this->assertSame( 7, $allowed, 'Distinct surfaces have distinct budgets.' );

		$surface = 'register_test_fixed_' . wp_rand();
		$allowed = 0;
		for ( $i = 0; $i < 7; $i++ ) {
			if ( law_events_rate_limit_ok( $surface, 0, 5, 60 ) ) {
				$allowed++;
			}
		}
		$this->assertSame( 5, $allowed, 'The sixth anonymous attempt from one IP is refused.' );
	}
}
