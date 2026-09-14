<?php
/**
 * Phase D registration/profile: the optional intent ticks are whitelisted and
 * grant nothing, and the HubSpot tags and welcome email follow them.
 *
 * The role-sync tests that used to open this file went with the roles on
 * 14 September 2026, along with law_registration_sync_roles() itself. What
 * replaced the escalation risk they guarded is simply that the form no longer
 * posts a role at all: law_registration_handler() hardcodes subscriber. The
 * whitelist test below still covers the input path, because law_intent is
 * user-supplied and must never become anything but a known key.
 */
class RegistrationTest extends LAW_Test_Case {

	public function test_profile_meta_whitelists_its_choices(): void {
		$user_id = $this->make_user();
		law_registration_write_profile_meta(
			$user_id,
			array(
				'organisation'  => 'Test Firm <script>x</script>',
				'job_title'     => 'Partner',
				'country'       => 'United Kingdom',
				'accessibility' => array( 'Wheelchair', 'I require wheelchair access', 'FAKE CHOICE' ),
				'dietary'       => array( 'Vegan', '<img onerror=1>' ),
			)
		);

		$this->assertSame(
			array( 'subscriber' ),
			array_values( (array) ( new WP_User( $user_id ) )->roles ),
			'Writing profile meta must never change what roles somebody holds.'
		);
		if ( function_exists( 'get_field' ) ) {
			// The canonical stored accessibility format is the short VALUE
			// ('Wheelchair'), never the display label.
			$this->assertSame( array( 'Wheelchair' ), (array) get_field( 'accessibility', 'user_' . $user_id ) );
			// The ACF law_role field is no longer written: its choices are the
			// retired role slugs, so an intent key there would be off-list.
			$this->assertEmpty( (array) get_field( 'law_role', 'user_' . $user_id ), 'law_role is no longer written.' );
		}
		// sanitize_text_field strips script tags WITH their content.
		$this->assertSame( 'Test Firm', get_user_meta( $user_id, 'organisation', true ) );
		$this->assertSame( 'United Kingdom', get_user_meta( $user_id, 'country', true ) );
	}

	/**
	 * The data-loss trap. No form collects law_intent since 14 September 2026,
	 * but migration step 11 seeded it for every account it converted: it holds
	 * the only translation of what the retired roles said about those people,
	 * and it is what the HubSpot Sponsor and Event Host tags are read from. A
	 * profile save that does not ask about it must not clear it.
	 */
	public function test_a_save_that_does_not_ask_about_intent_leaves_it_alone(): void {
		$user_id = $this->make_user();
		law_registration_write_intent( $user_id, array( 'host', 'sponsor' ) );

		$returned = law_registration_write_profile_meta( $user_id, array( 'organisation' => 'A', 'job_title' => 'B', 'country' => 'United Kingdom' ) );

		$this->assertSame( array( 'host', 'sponsor' ), law_registration_read_intent( $user_id ) );
		$this->assertSame( array( 'host', 'sponsor' ), $returned, 'The stored value is what the HubSpot tags are then derived from.' );
	}

	/**
	 * And when a form DOES offer it, the posted value wins, including an empty
	 * one: an empty array is a deliberate "none of these", not a missing field.
	 * This is what a reinstated tick would rely on.
	 */
	public function test_a_posted_intent_wins_including_an_empty_one(): void {
		$user_id = $this->make_user();
		law_registration_write_intent( $user_id, array( 'host' ) );

		$base = array( 'organisation' => 'A', 'job_title' => 'B', 'country' => 'United Kingdom' );

		// Junk and role names are dropped; a tampered request writes nothing new.
		law_registration_write_profile_meta( $user_id, $base + array( 'law_intent' => array( 'administrator', 'event_host', 'sponsor', 'FAKE' ) ) );
		$this->assertSame( array( 'sponsor' ), law_registration_read_intent( $user_id ) );

		law_registration_write_profile_meta( $user_id, $base + array( 'law_intent' => array() ) );
		$this->assertSame( array(), law_registration_read_intent( $user_id ) );
		$this->assertTrue( metadata_exists( 'user', $user_id, 'law_intent' ) );
	}

	/** Neither form asks about intent any more; nothing should render one. */
	public function test_no_form_collects_an_intent(): void {
		$part = file_get_contents( get_theme_file_path( '/parts/events/profile-fields.php' ) );

		$this->assertStringNotContainsString( 'name="law_intent', $part );
		$this->assertStringNotContainsString( 'Tell us about yourself', $part );
	}

	public function test_hubspot_tags_follow_the_intent_ticks(): void {
		$year = (string) law_events_setting( 'year', 2026 );
		$this->assertSame( "{$year} Registered user", law_registration_hubspot_tags( array() ) );
		$this->assertSame( "{$year} Registered user;{$year} Sponsor", law_registration_hubspot_tags( array( 'sponsor' ) ) );
		// The tag strings are deliberately what they always were, so whatever
		// reads law_hubspot_contact_type downstream sees no change.
		$this->assertSame(
			"{$year} Registered user;{$year} Sponsor;{$year} Event Host",
			law_registration_hubspot_tags( array( 'sponsor', 'host' ) )
		);
	}

	public function test_welcome_email_matches_the_ticks(): void {
		$this->assertSame( 'user_welcome_registered', law_registration_welcome_slug( array() ), 'No tick means the general welcome.' );
		$this->assertSame( 'user_welcome_registered_host', law_registration_welcome_slug( array( 'host' ) ) );
		$this->assertSame( 'user_welcome_registered_host', law_registration_welcome_slug( array( 'sponsor' ) ) );
		$this->assertSame(
			'user_welcome_registered_host',
			law_registration_welcome_slug( array( 'host', 'sponsor' ) ),
			'Either tick leads with submitting, rather than asking for dietary requirements up front.'
		);
	}

	/**
	 * The welcome everybody now gets has to cover the whole job. Nothing
	 * collects an intent any more, so law_registration_welcome_slug() always
	 * returns this one, and anybody signed in may book AND submit.
	 */
	public function test_the_welcome_email_offers_booking_and_submitting(): void {
		$welcome = law_events_email( 'user_welcome_registered' );

		$this->assertTrue( (bool) $welcome['active'] );
		$this->assertStringContainsString( 'browse the programme', $welcome['body'] );
		$this->assertStringContainsString( '{bookings_link}', $welcome['body'] );
		$this->assertStringContainsString( '{submit_link}', $welcome['body'], 'A new account can submit an event, and the welcome must say so (Denis, 14 September 2026).' );

		// The hosting variant stays in the registry, editable and one intent
		// away from being used again.
		$host = law_events_email( 'user_welcome_registered_host' );
		$this->assertNotNull( $host );
		$this->assertStringContainsString( '{submit_link}', $host['body'] );
	}

	/**
	 * The admin notice must not report roles. The registry default is only
	 * half of it: migration step 9 imported these two as stored overrides, and
	 * an override beats the default, so the line has to be stripped from the
	 * store as well or the committee keeps reading "Roles: None ticked".
	 */
	public function test_no_registration_email_reports_roles(): void {
		law_setup_strip_user_roles_from_emails();

		foreach ( array( 'admins_user_registered', 'squareeye_user_registered' ) as $slug ) {
			$email = law_events_email( $slug );
			$this->assertStringNotContainsString( '{user_roles}', $email['body'], "{$slug} still reports roles." );
			$this->assertStringNotContainsString( 'Roles:', $email['body'], "{$slug} still has a Roles line." );
		}

		$this->assertSame( 'ok', law_setup_strip_user_roles_from_emails(), 'The helper must be idempotent.' );
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
