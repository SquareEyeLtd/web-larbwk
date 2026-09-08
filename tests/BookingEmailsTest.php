<?php
/**
 * Bookings emails and .ics invites (phase 2):
 *
 * - the .ics generator: UTC conversion across BST and GMT dates, the 2-hour
 *   default end, RFC 5545 escaping and 75-octet folding, '' with no start;
 * - attachments: the booking confirmation reaches wp_mail() with an .ics file;
 * - the removal-family templates resolve per context (reject / owner removal /
 *   self-removal / owner cancel), each to the removed person;
 * - the capacity warning mails the host once;
 * - committee_booking_received goes to the event's assignee when one is set;
 * - the registration welcome email resolves with no event.
 *
 * Mail is captured on the 'wp_mail' filter (which runs before the
 * pre_wp_mail short-circuit the bootstrap installs), so nothing is sent.
 */

require_once __DIR__ . '/class-law-test-case.php';

class BookingEmailsTest extends LAW_Test_Case {

	private $source_before;
	private $tz_before;
	private array $mail = array();
	private $mail_filter;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before = get_option( 'law_events_source', null );
		update_option( 'law_events_source', 'cpt' );
		// The .ics assertions depend on the site clock being Europe/London.
		$this->tz_before = get_option( 'timezone_string', '' );
		update_option( 'timezone_string', 'Europe/London' );

		$this->mail        = array();
		$this->mail_filter = function ( $atts ) {
			$this->mail[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $this->mail_filter );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_mail', $this->mail_filter );
		update_option( 'timezone_string', $this->tz_before );
		if ( null === $this->source_before ) {
			delete_option( 'law_events_source' );
		} else {
			update_option( 'law_events_source', $this->source_before );
		}
		parent::tearDown();
	}

	private function make_bookable_event( array $meta = array() ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_tickets_available' => 10,
					'_law_start'             => '2026-12-01 10:00',
					'_law_end'               => '2026-12-01 12:00',
					'_law_venue'             => 'Test Hall, London',
				),
				$meta
			),
			'publish'
		);
	}

	private function row( string $name, string $email ): array {
		return array( 'name' => $name, 'email' => $email, 'organisation' => 'Test Org', 'job_title' => 'Associate' );
	}

	private function unique_email( string $prefix ): string {
		return $prefix . '-' . wp_generate_password( 8, false ) . '@example.test';
	}

	/** Captured sends whose recipient list contains $email. */
	private function mail_to( string $email ): array {
		return array_values( array_filter( $this->mail, fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
	}

	/* .ics ___________________________________________________________________ */

	public function test_ics_converts_bst_and_gmt_to_utc(): void {
		// June: BST (UTC+1), so 10:00 local is 09:00Z.
		$summer = $this->make_bookable_event( array( '_law_start' => '2026-06-15 10:00', '_law_end' => '2026-06-15 12:00' ) );
		$ics    = law_event_ics( $summer );
		$this->assertStringContainsString( 'DTSTART:20260615T090000Z', $ics );
		$this->assertStringContainsString( 'DTEND:20260615T110000Z', $ics );

		// December: GMT, 10:00 local is 10:00Z.
		$winter = $this->make_bookable_event();
		$ics    = law_event_ics( $winter );
		$this->assertStringContainsString( 'DTSTART:20261201T100000Z', $ics );
		$this->assertStringContainsString( 'UID:law-event-' . $winter . '@', $ics );
	}

	public function test_ics_defaults_missing_end_escapes_and_folds(): void {
		$event = $this->make_bookable_event( array( '_law_end' => '' ) );
		wp_update_post(
			array(
				'ID'           => $event,
				'post_title'   => 'Enforcement, sanctions; and a deliberately long title to push the summary line well past the seventy-five octet folding limit of RFC 5545',
				'post_content' => 'Description.',
			)
		);

		$ics = law_event_ics( $event );
		// Missing end: start + 2 hours.
		$this->assertStringContainsString( 'DTEND:20261201T120000Z', $ics );
		// Comma and semicolon escaped.
		$this->assertStringContainsString( 'Enforcement\\, sanctions\\;', $ics );
		// Every physical line fits in 75 octets.
		foreach ( explode( "\r\n", $ics ) as $line ) {
			$this->assertLessThanOrEqual( 75, strlen( $line ), 'Folded line too long: ' . $line );
		}
		// No start = no invite.
		$unscheduled = $this->make_event( array( '_law_tickets_available' => 10 ), 'publish' );
		$this->assertSame( '', law_event_ics( $unscheduled ) );
	}

	/* Booking emails _________________________________________________________ */

	public function test_booking_confirmation_carries_ics_attachment(): void {
		$event = $this->make_bookable_event();
		$owner = $this->make_user( 'attendee' );
		$this->make_booking( $event, $owner, array() );

		$to_owner = $this->mail_to( get_userdata( $owner )->user_email );
		$this->assertNotEmpty( $to_owner, 'The booker gets a confirmation.' );
		$confirmation = $to_owner[0];
		$this->assertStringContainsString( 'Your booking is confirmed', $confirmation['subject'] );
		$this->assertNotEmpty( $confirmation['attachments'], 'The confirmation carries the .ics invite.' );
		$this->assertStringEndsWith( '.ics', $confirmation['attachments'][0] );
	}

	public function test_invite_and_added_emails_resolve_per_account_state(): void {
		$event    = $this->make_bookable_event();
		$booker   = $this->make_user( 'attendee' );
		$existing = $this->make_user( 'attendee' );
		$known    = get_userdata( $existing )->user_email;
		$fresh    = $this->unique_email( 'fresh' );
		$this->mail = array();

		$ids = $this->make_booking(
			$event,
			$booker,
			array( $this->row( 'Known Person', $known ), $this->row( 'New Person', $fresh ) )
		);
		$this->assertIsArray( $ids );
		$booker_name = get_userdata( $booker )->display_name;

		// An existing account is told it has a booking; a new one gets the
		// set-password link. Both carry THEIR OWN booking number.
		$added = $this->mail_to( $known );
		$this->assertNotEmpty( $added );
		$this->assertStringNotContainsString( 'Set your password', wp_strip_all_tags( $added[0]['message'] ) );
		$this->assertStringContainsString( '#' . (int) law_event_meta( $ids[1], '_law_booking_number' ), wp_strip_all_tags( $added[0]['message'] ) );
		$this->assertStringContainsString( $booker_name, wp_strip_all_tags( $added[0]['message'] ), 'The email names who booked them.' );

		$invited = $this->mail_to( $fresh );
		$this->assertNotEmpty( $invited );
		$this->assertStringContainsString( 'action=reset', wp_strip_all_tags( $invited[0]['message'] ) );
		$this->assertStringContainsString( '#' . (int) law_event_meta( $ids[2], '_law_booking_number' ), wp_strip_all_tags( $invited[0]['message'] ) );

		// The booker's own confirmation lists everyone with their numbers.
		$confirmation = $this->mail_to( get_userdata( $booker )->user_email );
		$this->assertNotEmpty( $confirmation );
		foreach ( $ids as $id ) {
			$this->assertStringContainsString(
				'#' . (int) law_event_meta( $id, '_law_booking_number' ),
				wp_strip_all_tags( $confirmation[0]['message'] )
			);
		}
	}

	public function test_registered_on_behalf_emails_name_the_registrar_and_link_new_accounts(): void {
		$event     = $this->make_bookable_event();
		$committee = $this->make_committee_user();
		wp_update_user( array( 'ID' => $committee, 'display_name' => 'Casey Committee' ) );

		// Existing account: the plain "registered" template, no password link.
		$existing = $this->make_user( 'attendee' );
		$email    = get_userdata( $existing )->user_email;
		$booking  = law_booking_register_by_manager( $event, array( 'name' => 'Ex Isting', 'email' => $email ), $committee );
		$this->assertIsInt( $booking );
		$this->posts[] = $booking;
		$sent = $this->mail_to( $email );
		$this->assertCount( 1, $sent, 'Exactly one email to the registered person.' );
		$this->assertStringContainsString( 'Casey Committee has registered a place for you', $sent[0]['message'] );
		$this->assertStringContainsString( 'You already have an account', $sent[0]['message'] );
		$this->assertStringNotContainsString( 'action=rp', $sent[0]['message'] );
		$this->assertNotEmpty( $sent[0]['attachments'], 'The .ics invite rides the confirmation.' );

		// New account: the "invited" variant with a set-password link, and
		// still exactly one email (no separate invite + confirmation).
		$new_email = $this->unique_email( 'new' );
		$booking2  = law_booking_register_by_manager( $event, array( 'name' => 'New Person', 'email' => $new_email ), $committee );
		$this->assertIsInt( $booking2 );
		$this->posts[] = $booking2;
		$this->users[] = (int) get_user_by( 'email', $new_email )->ID;
		$sent = $this->mail_to( $new_email );
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'Casey Committee has registered a place for you', $sent[0]['message'] );
		$this->assertStringContainsString( 'Set your password', $sent[0]['message'] );
		$this->assertMatchesRegularExpression( '/key=[A-Za-z0-9]+/', $sent[0]['message'], 'A minted set-password link.' );

		// The host and committee copies go out as for any booking.
		$host = get_userdata( (int) get_post_field( 'post_author', $event ) );
		if ( $host ) {
			$this->assertNotEmpty( $this->mail_to( $host->user_email ) );
		}
	}

	public function test_cancel_templates_per_context(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$g1     = $this->unique_email( 'g1' );
		$g2     = $this->unique_email( 'g2' );

		$ids = $this->make_booking( $event, $booker, array( $this->row( 'G One', $g1 ), $this->row( 'G Two', $g2 ) ) );
		$this->mail = array();

		// Host reject: reason + host contact.
		law_booking_cancel( $ids[1], $this->make_committee_user(), 'host_reject', array( 'reason' => 'Overbooked session' ) );
		$mail = $this->mail_to( $g1 );
		$this->assertStringContainsString( 'has been cancelled', $mail[0]['subject'] );
		$this->assertStringContainsString( 'Overbooked session', wp_strip_all_tags( $mail[0]['message'] ) );

		// The person who booked them cancels their booking: the email names them.
		law_booking_cancel( $ids[2], $booker, 'booker' );
		$mail = $this->mail_to( $g2 );
		$this->assertStringContainsString( 'has been cancelled', $mail[0]['subject'] );
		$this->assertStringContainsString( get_userdata( $booker )->display_name, wp_strip_all_tags( $mail[0]['message'] ) );

		// Their own cancellation is confirmed to them, and only them.
		$booker_email = get_userdata( $booker )->user_email;
		law_booking_cancel( $ids[0], $booker, 'self' );
		$mail = $this->mail_to( $booker_email );
		$this->assertStringContainsString( 'You have cancelled', $mail[0]['subject'] );
		$this->assertSame( 'law-cancelled', get_post_status( $ids[0] ) );
	}

	public function test_cancel_party_emails_each_person_with_their_number(): void {
		$event  = $this->make_bookable_event();
		$booker = $this->make_user( 'attendee' );
		$g1     = $this->unique_email( 'g1' );
		$ids    = $this->make_booking( $event, $booker, array( $this->row( 'G One', $g1 ) ) );
		$this->mail = array();

		law_bookings_cancel_party( $event, $booker, $booker );

		// The actor gets the self template; the colleague gets the by-booker one.
		$own = $this->mail_to( get_userdata( $booker )->user_email );
		$this->assertStringContainsString( 'You have cancelled', $own[0]['subject'] );
		$guest = $this->mail_to( $g1 );
		$this->assertStringContainsString( 'has been cancelled', $guest[0]['subject'] );
		$this->assertStringContainsString(
			'#' . (int) law_event_meta( $ids[1], '_law_booking_number' ),
			wp_strip_all_tags( $guest[0]['message'] ),
			'Each person is told their own booking number.'
		);
	}

	public function test_capacity_warning_mails_host_once(): void {
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event(
			array(
				'_law_tickets_available' => 6,
				'_law_start'             => '2026-12-01 10:00',
				'_law_end'               => '2026-12-01 12:00',
			),
			'publish',
			$host
		);

		$this->make_booking( $event, $this->make_user( 'attendee' ), array() ); // 5 remain: warn.
		$this->make_booking( $event, $this->make_user( 'attendee' ), array() ); // 4 remain: latched.

		$host_mail = $this->mail_to( get_userdata( $host )->user_email );
		$warnings  = array_filter( $host_mail, fn( $m ) => str_contains( $m['subject'], 'nearly full' ) );
		$this->assertCount( 1, $warnings );
	}

	public function test_committee_booking_email_prefers_assignee(): void {
		law_events_update_settings( array( 'committee_emails' => array( 'committee-list@example.test' ) ) );
		$assignee = $this->make_committee_user();
		$event    = $this->make_bookable_event( array( '_law_assignee' => $assignee ) );

		$this->make_booking( $event, $this->make_user( 'attendee' ), array() );

		$to_assignee = $this->mail_to( get_userdata( $assignee )->user_email );
		$this->assertNotEmpty( $to_assignee, 'The assignee gets the new-booking email.' );
		$this->assertStringContainsString( 'New booking', $to_assignee[0]['subject'] );
		$this->assertEmpty( $this->mail_to( 'committee-list@example.test' ), 'The committee list is not copied when an assignee is set.' );
	}

	public function test_welcome_email_resolves_with_no_event(): void {
		$email = $this->unique_email( 'welcome' );
		law_events_send( 'user_welcome_registered', 0, array( 'to' => array( $email ), 'placeholders' => array( 'user_name' => 'New Person' ) ) );
		$mail = $this->mail_to( $email );
		$this->assertNotEmpty( $mail );
		$this->assertStringContainsString( 'Welcome to', $mail[0]['subject'] );
		$this->assertStringContainsString( '/account/profile/', wp_strip_all_tags( $mail[0]['message'] ) );
	}
}
