<?php
/**
 * The per-event booking confirmation (functions/events/email-override.php).
 *
 * One event can replace its own confirmation without touching the wording every
 * other event sends. What matters here:
 *
 * - resolution happens in law_events_email(), so passing no event keeps the
 *   site-wide view every editing screen depends on;
 * - a hosted event's ONE body reaches both confirmation templates, which is the
 *   whole point: the booker and the colleagues read the same words;
 * - a reception keeps its paid / nothing-to-pay split, because collapsing those
 *   two is what produced "You paid £0.00" above an empty invoice link;
 * - the override swaps subject and body only, never active or to;
 * - a half-written override falls back rather than sending a blank;
 * - the flagship, and anything that is not an event, cannot carry one.
 *
 * Mail is captured on the 'wp_mail' filter (which runs before the pre_wp_mail
 * short-circuit the bootstrap installs), so nothing is sent.
 */

require_once __DIR__ . '/class-law-test-case.php';

class EmailOverrideTest extends LAW_Test_Case {

	private array $mail = array();
	private $mail_filter;
	private $source_before;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before = get_option( 'law_events_source', null );
		update_option( 'law_events_source', 'cpt' );
		// In memory, so a suite run never rewrites the real site's Emails screen.
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

	private function write_override( int $event_id, string $subject, string $body, array $free = array() ): void {
		law_event_update_meta( $event_id, '_law_email_override', 1 );
		law_event_update_meta( $event_id, '_law_email_override_subject', $subject );
		law_event_update_meta( $event_id, '_law_email_override_body', $body );
		if ( $free ) {
			law_event_update_meta( $event_id, '_law_email_override_subject_free', $free[0] );
			law_event_update_meta( $event_id, '_law_email_override_body_free', $free[1] );
		}
	}

	/** A published, priced reception: the kind with two confirmation bodies. */
	private function make_bookable_reception( array $meta = array() ): int {
		return $this->make_bookable_event(
			array_merge(
				array(
					'_law_is_reception'         => 1,
					'_law_attendee_price_pence' => 4500,
					'_law_registration_state'   => 'open',
				),
				$meta
			)
		);
	}

	private function mail_to( string $email ): array {
		return array_values( array_filter( $this->mail, fn( $m ) => in_array( $email, (array) $m['to'], true ) ) );
	}

	/* The slug map ___________________________________________________________ */

	public function test_slug_map_per_event_kind(): void {
		$hosted = $this->make_bookable_event();
		$this->assertSame(
			array( 'user_booking_confirmed' => 'main', 'user_booking_registered' => 'main' ),
			law_event_override_slug_map( $hosted ),
			'A hosted event has one body serving both confirmation templates.'
		);

		$reception = $this->make_bookable_event( array( '_law_is_reception' => 1 ) );
		$this->assertSame(
			array( 'user_reception_confirmed' => 'main', 'user_reception_confirmed_free' => 'free' ),
			law_event_override_slug_map( $reception ),
			'A reception keeps its paid / nothing-to-pay split.'
		);
	}

	public function test_nothing_that_is_not_an_event_can_be_overridden(): void {
		$this->assertSame( array(), law_event_override_slug_map( 0 ) );
		$this->assertSame( array(), law_event_override_slug_map( PHP_INT_MAX ) );

		$booker  = $this->make_user();
		$event   = $this->make_bookable_event();
		$booking = $this->make_booking_id( $event, $booker );
		$this->assertSame( array(), law_event_override_slug_map( $booking ), 'A booking is not an event.' );
		$this->assertSame( '', law_event_override_url( $booking ) );
	}

	/* Resolution _____________________________________________________________ */

	public function test_no_event_keeps_the_site_wide_view(): void {
		$event = $this->make_bookable_event();
		$this->write_override( $event, 'Custom subject', '<p>Custom body.</p>' );

		$registry = law_events_email_registry();
		$site     = law_events_email( 'user_booking_confirmed' );
		$this->assertSame(
			$registry['user_booking_confirmed']['body'],
			$site['body'],
			'Every editing screen calls this with no event and must not see one event\'s wording.'
		);
		$this->assertSame( $registry['user_booking_confirmed']['subject'], $site['subject'] );
	}

	public function test_one_body_serves_both_templates_on_a_hosted_event(): void {
		$event = $this->make_bookable_event();
		$this->write_override( $event, 'Your place at the breakfast', '<p>Come to the side door.</p>' );

		foreach ( array( 'user_booking_confirmed', 'user_booking_registered' ) as $slug ) {
			$email = law_events_email( $slug, $event );
			$this->assertSame( 'Your place at the breakfast', $email['subject'], $slug );
			$this->assertSame( '<p>Come to the side door.</p>', $email['body'], $slug );
		}
	}

	public function test_the_tick_is_what_switches_it_on(): void {
		$event = $this->make_bookable_event();
		$this->write_override( $event, 'Custom subject', '<p>Custom body.</p>' );
		law_event_update_meta( $event, '_law_email_override', 0 );

		$registry = law_events_email_registry();
		$email    = law_events_email( 'user_booking_confirmed', $event );
		$this->assertSame( $registry['user_booking_confirmed']['body'], $email['body'] );
		$this->assertFalse( law_event_override_active( $event ), 'An unticked flag stores 0, not an absent row.' );
	}

	public function test_a_half_written_override_falls_back_rather_than_sending_a_blank(): void {
		$event = $this->make_bookable_event();
		law_event_update_meta( $event, '_law_email_override', 1 );
		law_event_update_meta( $event, '_law_email_override_body', '<p>Only the body was written.</p>' );

		$registry = law_events_email_registry();
		$email    = law_events_email( 'user_booking_confirmed', $event );
		$this->assertSame( '<p>Only the body was written.</p>', $email['body'] );
		$this->assertSame(
			$registry['user_booking_confirmed']['subject'],
			$email['subject'],
			'A blank subject line would be worse than the standard one.'
		);
	}

	public function test_an_override_never_changes_active_or_recipients(): void {
		$event = $this->make_bookable_event();
		$this->write_override( $event, 'Custom subject', '<p>Custom body.</p>' );

		$registry = law_events_email_registry();
		$email    = law_events_email( 'user_booking_confirmed', $event );
		$this->assertSame( $registry['user_booking_confirmed']['active'], $email['active'] );
		$this->assertSame( $registry['user_booking_confirmed']['to'], $email['to'] );
	}

	public function test_an_override_leaves_every_other_template_alone(): void {
		$event = $this->make_bookable_event();
		$this->write_override( $event, 'Custom subject', '<p>Custom body.</p>' );

		$registry = law_events_email_registry();
		foreach ( array( 'user_booking_cancelled_self', 'host_booking_received', 'user_waitlist_joined' ) as $slug ) {
			$this->assertSame(
				$registry[ $slug ]['body'],
				law_events_email( $slug, $event )['body'],
				$slug . ' is not a confirmation and must be untouched.'
			);
		}
	}

	public function test_a_reception_keeps_its_paid_and_free_split(): void {
		$reception = $this->make_bookable_event( array( '_law_is_reception' => 1 ) );
		$this->write_override(
			$reception,
			'Your reception place',
			'<p>Paid wording.</p>',
			array( 'Your reception place, with our compliments', '<p>Free wording.</p>' )
		);

		$paid = law_events_email( 'user_reception_confirmed', $reception );
		$this->assertSame( 'Your reception place', $paid['subject'] );
		$this->assertSame( '<p>Paid wording.</p>', $paid['body'] );

		$free = law_events_email( 'user_reception_confirmed_free', $reception );
		$this->assertSame( 'Your reception place, with our compliments', $free['subject'] );
		$this->assertSame( '<p>Free wording.</p>', $free['body'] );
	}

	/* The editor's starting point ____________________________________________ */

	public function test_the_editor_starts_from_what_the_event_would_send(): void {
		$event  = $this->make_bookable_event();
		$fields = law_event_override_fields( $event );

		$registry = law_events_email_registry();
		$this->assertSame( array( 'main' ), array_keys( $fields ), 'A hosted event writes one body.' );
		$this->assertSame( $registry['user_booking_confirmed']['body'], $fields['main']['body'] );
		$this->assertFalse( law_event_override_written( $event ) );

		$reception = $this->make_bookable_event( array( '_law_is_reception' => 1 ) );
		$this->assertSame(
			array( 'main', 'free' ),
			array_keys( law_event_override_fields( $reception ) ),
			'A reception writes two.'
		);
	}

	public function test_the_editor_starts_from_a_site_wide_override_when_there_is_one(): void {
		update_option(
			LAW_EVENTS_EMAIL_OVERRIDES_OPTION,
			array( 'user_booking_confirmed' => array( 'body' => '<p>The site changed this.</p>' ) ),
			false
		);
		$event  = $this->make_bookable_event();
		$fields = law_event_override_fields( $event );
		$this->assertSame(
			'<p>The site changed this.</p>',
			$fields['main']['body'],
			'Starting from the shipped text would silently undo the site\'s own edit.'
		);
	}

	/* End to end _____________________________________________________________ */

	public function test_the_booker_and_the_colleague_both_get_the_custom_wording(): void {
		$event  = $this->make_bookable_event();
		$this->write_override(
			$event,
			'You are coming to the breakfast',
			'<p>Dear {attendee_name}, please use the side door. Booking #{booking_number}.</p>'
		);

		$booker    = $this->make_user();
		$colleague = 'colleague-' . wp_generate_password( 8, false ) . '@example.test';
		$ids       = $this->make_booking(
			$event,
			$booker,
			array( array( 'name' => 'Col League', 'email' => $colleague, 'organisation' => 'Test Org', 'job_title' => 'Associate' ) )
		);
		$this->assertIsArray( $ids );

		foreach ( array( get_userdata( $booker )->user_email, $colleague ) as $i => $address ) {
			$sent = $this->mail_to( $address );
			$this->assertNotEmpty( $sent, $address );
			$this->assertSame( 'You are coming to the breakfast', $sent[0]['subject'], $address );
			$this->assertStringContainsString( 'please use the side door', $sent[0]['message'], $address );
			$this->assertStringContainsString(
				'#' . (int) law_event_meta( $ids[ $i ], '_law_booking_number' ),
				wp_strip_all_tags( $sent[0]['message'] ),
				'Each person still gets their OWN booking number.'
			);
			$this->assertNotEmpty( $sent[0]['attachments'], 'The .ics invite still rides the confirmation.' );
			$this->assertDoesNotMatchRegularExpression( '/\{[a-z_]+\}/', $sent[0]['message'] );
		}
	}

	/**
	 * A reception's PAID confirmation, sent for real.
	 *
	 * The resolution tests above pin law_events_email(); this one goes through
	 * law_reception_maybe_send_confirmation(), which is the only thing that
	 * actually posts a reception place's confirmation, so a send site that
	 * forgot to pass the event would fail here and nowhere else.
	 */
	public function test_a_paid_reception_place_reads_the_custom_wording(): void {
		$event = $this->make_bookable_reception();
		$this->write_override(
			$event,
			'Drinks on the terrace',
			'<p>Dear {attendee_name}, the terrace is on the fourth floor.</p>',
			array( 'Drinks on the terrace, with our compliments', '<p>Nothing to pay, {attendee_name}. See you on the fourth floor.</p>' )
		);

		$user          = $this->make_user();
		$email         = get_userdata( $user )->user_email;
		$booking_id    = law_booking_insert(
			$event,
			$user,
			'law-pending-payment',
			array( 'name' => 'Jane Smith', 'email' => $email ),
			array( '_law_price_pence' => 4500, '_law_vat' => 1 )
		);
		$this->posts[] = $booking_id;

		$this->assertTrue(
			law_reception_mark_paid(
				$booking_id,
				array(
					'id'                 => 'in_test',
					'object'             => 'invoice',
					'amount_paid'        => 5400,
					'hosted_invoice_url' => 'https://invoice.stripe.test/in_test',
				)
			)
		);

		$sent = $this->mail_to( $email );
		$this->assertNotEmpty( $sent, 'The paid place was confirmed, so the delegate was written to.' );
		$this->assertSame( 'Drinks on the terrace', $sent[0]['subject'] );
		$this->assertStringContainsString( 'the terrace is on the fourth floor', $sent[0]['message'] );
		$this->assertStringNotContainsString( 'with our compliments', $sent[0]['message'], 'The paid place must not read the nothing-to-pay body.' );
		$this->assertNotEmpty( $sent[0]['attachments'], 'The .ics invite still rides the confirmation.' );
		$this->assertDoesNotMatchRegularExpression( '/\{[a-z_]+\}/', $sent[0]['message'] );
	}

	/**
	 * And the nothing-to-pay one reads the SECOND body.
	 *
	 * This is the half the split exists for: flattening the two is what put
	 * "You paid £0.00" above an empty invoice link (16 September 2026).
	 */
	public function test_a_free_reception_place_reads_the_second_body(): void {
		$event = $this->make_bookable_reception( array( '_law_attendee_price_pence' => 0, '_law_registration_state' => 'free' ) );
		$this->write_override(
			$event,
			'Drinks on the terrace',
			'<p>Dear {attendee_name}, the terrace is on the fourth floor.</p>',
			array( 'Drinks on the terrace, with our compliments', '<p>Nothing to pay, {attendee_name}. See you on the fourth floor.</p>' )
		);

		$user          = $this->make_user();
		$email         = get_userdata( $user )->user_email;
		$booking_id    = law_booking_insert(
			$event,
			$user,
			'law-pending-payment',
			array( 'name' => 'Jane Smith', 'email' => $email ),
			array( '_law_price_pence' => 0 )
		);
		$this->posts[] = $booking_id;

		$this->assertTrue( law_reception_mark_paid( $booking_id, array() ) );

		$sent = $this->mail_to( $email );
		$this->assertNotEmpty( $sent, 'A free place is confirmed straight away, so the delegate was written to.' );
		$this->assertSame( 'Drinks on the terrace, with our compliments', $sent[0]['subject'] );
		$this->assertStringContainsString( 'Nothing to pay, Jane Smith', wp_strip_all_tags( $sent[0]['message'] ) );
		$this->assertStringNotContainsString( 'fourth floor.</p>\n<p>', $sent[0]['message'] );
		$this->assertDoesNotMatchRegularExpression( '/\{[a-z_]+\}/', $sent[0]['message'] );
	}

	/**
	 * A reception that has NOT ticked the box is untouched by another one that
	 * has, which is the promise the committee is making when they tick it.
	 */
	public function test_one_reception_overriding_leaves_the_others_alone(): void {
		$overridden = $this->make_bookable_reception();
		$standard   = $this->make_bookable_reception();
		$this->write_override(
			$overridden,
			'Drinks on the terrace',
			'<p>Custom.</p>',
			array( 'Drinks on the terrace, with our compliments', '<p>Custom free.</p>' )
		);

		$shipped = law_events_email( 'user_reception_confirmed' );
		$this->assertSame( $shipped['subject'], law_events_email( 'user_reception_confirmed', $standard )['subject'] );
		$this->assertSame(
			$shipped['subject'],
			law_events_email( 'user_reception_confirmed', 0 )['subject'],
			'And every editing screen still sees the site-wide wording.'
		);
	}
}
