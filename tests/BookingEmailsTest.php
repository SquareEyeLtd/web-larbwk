<?php
/**
 * Bookings emails and .ics invites (phase 2):
 *
 * - the .ics generator: UTC conversion across BST and GMT dates, the 2-hour
 *   default end, RFC 5545 escaping and 75-octet folding, '' with no start;
 * - attachments: the booking confirmation reaches wp_mail() with an .ics file;
 * - the removal-family templates resolve per context (reject / owner removal /
 *   self-removal / owner cancel), each to the removed person;
 * - the two capacity stages: nearly full at the 10%/5 threshold, then fully
 *   booked, each once, and only the sold-out one when a party jumps the line;
 * - the per-booking host and committee emails are off by default, and still
 *   behave when the Emails screen turns one back on (one per submission, the
 *   committee copy to the event's assignee);
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
		// Some of these tests tick a retired email back on. Isolated in memory,
		// so a suite run never rewrites the real site's Emails-screen overrides.
		$this->isolate_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION );

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

	/**
	 * What the Emails screen's "Send this notification" box writes. The
	 * per-booking host and committee emails were retired on 10 September 2026
	 * and ship inactive, but the site can bring either back, so the rules that
	 * govern them (one email per submission, the committee copy to the event's
	 * assignee) still have to hold.
	 */
	private function activate_email( string $slug ): void {
		$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
		$overrides = is_array( $overrides ) ? $overrides : array();
		$stored    = isset( $overrides[ $slug ] ) && is_array( $overrides[ $slug ] ) ? $overrides[ $slug ] : array();
		$overrides[ $slug ] = array_merge( $stored, array( 'active' => true ) );
		update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $overrides, false );
	}

	/** Captured sends to $email whose subject contains $needle. */
	private function subjects_to( string $email, string $needle ): array {
		return array_values( array_filter( $this->mail_to( $email ), fn( $m ) => false !== stripos( $m['subject'], $needle ) ) );
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
		// A real author, so the host assertions at the end of this test actually
		// run: make_bookable_event() leaves post_author 0, and get_userdata( 0 )
		// is false, which used to skip them silently.
		$this->activate_email( 'host_booking_received' );
		$host      = $this->make_user( 'event_host' );
		$event     = $this->make_event(
			array(
				'_law_tickets_available' => 10,
				'_law_start'             => '2026-12-01 10:00',
				'_law_end'               => '2026-12-01 12:00',
				'_law_venue'             => 'Test Hall, London',
			),
			'publish',
			$host
		);
		$committee = $this->make_committee_user();
		wp_update_user( array( 'ID' => $committee, 'display_name' => 'Casey Committee' ) );

		// Existing account: the plain "registered" template, no password link.
		$existing = $this->make_user( 'attendee' );
		$email    = get_userdata( $existing )->user_email;
		$booking  = law_booking_register_by_manager( $event, array( 'name' => 'Ex Isting', 'email' => $email, 'organisation' => 'Test Org', 'job_title' => 'Associate' ), $committee );
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
		$booking2  = law_booking_register_by_manager( $event, array( 'name' => 'New Person', 'email' => $new_email, 'organisation' => 'Test Org', 'job_title' => 'Associate' ), $committee );
		$this->assertIsInt( $booking2 );
		$this->posts[] = $booking2;
		$this->users[] = (int) get_user_by( 'email', $new_email )->ID;
		$sent = $this->mail_to( $new_email );
		$this->assertCount( 1, $sent );
		$this->assertStringContainsString( 'Casey Committee has registered a place for you', $sent[0]['message'] );
		$this->assertStringContainsString( 'Set your password', $sent[0]['message'] );
		$this->assertMatchesRegularExpression( '/key=[A-Za-z0-9]+/', $sent[0]['message'], 'A minted set-password link.' );

		// The host hears ONCE per submission, not once per person. Both people
		// above were registered separately, so two submissions means two emails
		// and no more.
		$host_email = get_userdata( $host )->user_email;
		$host_mail  = array_filter( $this->mail_to( $host_email ), fn( $m ) => false !== stripos( $m['subject'], 'New booking' ) );
		$this->assertCount( 2, $host_mail, 'One host email per submission.' );
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

	/**
	 * The threshold itself, as a pure function: fewer than 10% of the places
	 * left, floored at 5. The floor means the percentage only bites from 61
	 * places upwards, which is deliberate (Denis, 10 September 2026).
	 */
	public function test_capacity_warning_threshold_is_ten_percent_with_a_floor_of_five(): void {
		$this->assertSame( 0, law_event_capacity_warning_at( 0 ), 'No capacity set: nothing to warn about.' );
		$this->assertSame( 5, law_event_capacity_warning_at( 10 ), 'The floor holds on small events.' );
		$this->assertSame( 5, law_event_capacity_warning_at( 40 ) );
		$this->assertSame( 5, law_event_capacity_warning_at( 60 ), '10% of 60 is 6, so the last event the floor covers.' );
		$this->assertSame( 6, law_event_capacity_warning_at( 61 ), 'From here the percentage takes over.' );
		$this->assertSame( 8, law_event_capacity_warning_at( 90 ), 'Exactly 10% does NOT warn: 9 left of 90 is not fewer than 10%.' );
		$this->assertSame( 9, law_event_capacity_warning_at( 91 ) );
		$this->assertSame( 9, law_event_capacity_warning_at( 100 ) );
		$this->assertSame( 19, law_event_capacity_warning_at( 200 ) );
	}

	/**
	 * The same boundary through the email itself. The seat count is set
	 * directly rather than booked: 90 places would take 23 submissions through
	 * the engine (a booker may bring at most 3 colleagues) and prove nothing
	 * extra.
	 */
	public function test_capacity_warning_fires_at_ten_percent_on_a_large_event(): void {
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event(
			array(
				'_law_tickets_available' => 100,
				'_law_start'             => '2026-12-01 10:00',
				'_law_end'               => '2026-12-01 12:00',
			),
			'publish',
			$host
		);
		$host_email = get_userdata( $host )->user_email;

		law_event_update_meta( $event, '_law_tickets_sold', 90 ); // 10 left: 10%, not fewer.
		law_booking_maybe_capacity_warning( $event );
		$this->assertEmpty( $this->subjects_to( $host_email, 'nearly full' ), 'Exactly 10% left does not warn.' );

		law_event_update_meta( $event, '_law_tickets_sold', 91 ); // 9 left.
		law_booking_maybe_capacity_warning( $event );
		$warned = $this->subjects_to( $host_email, 'nearly full' );
		$this->assertCount( 1, $warned, 'Under 10% warns.' );
		$this->assertStringContainsString( '9 of 100 places remain', $warned[0]['message'] );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_capacity_warned' ) );
	}

	/** Both stages in order, through the engine, on a two-place event. */
	public function test_full_event_emails_the_host_and_the_assignee_once(): void {
		law_events_update_settings( array( 'committee_emails' => array( 'committee-list@example.test' ) ) );
		$host     = $this->make_user( 'event_host' );
		$assignee = $this->make_committee_user();
		$event    = $this->make_event(
			array(
				'_law_tickets_available' => 2,
				'_law_assignee'          => $assignee,
				'_law_start'             => '2026-12-01 10:00',
				'_law_end'               => '2026-12-01 12:00',
			),
			'publish',
			$host
		);
		$host_email = get_userdata( $host )->user_email;

		$this->make_booking( $event, $this->make_user( 'attendee' ), array() ); // 1 left.
		$this->assertCount( 1, $this->subjects_to( $host_email, 'nearly full' ) );
		$this->assertEmpty( $this->subjects_to( $host_email, 'fully booked' ), 'Not full yet.' );

		$this->make_booking( $event, $this->make_user( 'attendee' ), array() ); // 0 left.
		$this->assertCount( 1, $this->subjects_to( $host_email, 'fully booked' ), 'The host hears the last place has gone.' );
		$this->assertCount( 1, $this->subjects_to( $host_email, 'nearly full' ), 'And not a second nearly-full copy.' );

		// The committee copy follows the assignee rule the other committee
		// booking emails use.
		$committee = $this->subjects_to( get_userdata( $assignee )->user_email, 'Fully booked' );
		$this->assertCount( 1, $committee );
		$this->assertStringContainsString( 'all 2 places have gone', $committee[0]['message'] );
		$this->assertEmpty( $this->subjects_to( 'committee-list@example.test', 'Fully booked' ), 'The list is not copied when an assignee is set.' );

		// Latched: a second pass over an already-full event sends nothing.
		law_booking_maybe_capacity_warning( $event );
		$this->assertCount( 1, $this->subjects_to( $host_email, 'fully booked' ) );
	}

	/**
	 * A pass that takes an event from above the nearly-full line straight to
	 * zero sends the sold-out email ALONE (Denis: one clear message, not two in
	 * the same second). Called directly because no single submission can jump
	 * that far, but a multi-promotion waitlist pass can.
	 */
	public function test_jump_to_zero_sends_only_the_sold_out_email(): void {
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event(
			array(
				'_law_tickets_available' => 100,
				'_law_start'             => '2026-12-01 10:00',
				'_law_end'               => '2026-12-01 12:00',
			),
			'publish',
			$host
		);
		$host_email = get_userdata( $host )->user_email;

		law_event_update_meta( $event, '_law_tickets_sold', 100 );
		law_booking_maybe_capacity_warning( $event );

		$this->assertCount( 1, $this->subjects_to( $host_email, 'fully booked' ) );
		$this->assertEmpty( $this->subjects_to( $host_email, 'nearly full' ), 'The skipped stage sends nothing.' );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_capacity_full_warned' ) );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_capacity_warned' ), 'The skipped stage is latched too.' );
	}

	/**
	 * The per-booking host and committee emails were retired on 10 September
	 * 2026 and ship inactive, so a booking on a default install mails neither.
	 */
	public function test_booking_received_emails_are_off_by_default(): void {
		law_events_update_settings( array( 'committee_emails' => array( 'committee-list@example.test' ) ) );
		$host  = $this->make_user( 'event_host' );
		$event = $this->make_event(
			array(
				'_law_tickets_available' => 10,
				'_law_start'             => '2026-12-01 10:00',
				'_law_end'               => '2026-12-01 12:00',
			),
			'publish',
			$host
		);

		$this->make_booking( $event, $this->make_user( 'attendee' ), array() );

		$this->assertEmpty( $this->subjects_to( get_userdata( $host )->user_email, 'New booking' ) );
		$this->assertEmpty( $this->mail_to( 'committee-list@example.test' ) );
	}

	public function test_committee_booking_email_prefers_assignee(): void {
		$this->activate_email( 'committee_booking_received' );
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

	public function test_host_welcome_email_leads_with_submitting_an_event(): void {
		$email = $this->unique_email( 'welcomehost' );
		law_events_send( 'user_welcome_registered_host', 0, array( 'to' => array( $email ), 'placeholders' => array( 'user_name' => 'New Host' ) ) );
		$mail = $this->mail_to( $email );
		$this->assertNotEmpty( $mail );
		$this->assertStringContainsString( 'Welcome to', $mail[0]['subject'] );
		$body = wp_strip_all_tags( $mail[0]['message'] );
		$this->assertStringContainsString( '/account/events/submit/', $body, '{submit_link} resolves with no event.' );
		$this->assertStringContainsString( 'submit an event for the programme', $body );
	}
}
