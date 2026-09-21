<?php
/**
 * Substituting the delegate on a confirmed flagship ticket
 * (law_flagship_substitute(), 21 September 2026).
 *
 * The load-bearing promise is that the SEAT moves and the MONEY does not. A
 * firm bought a place for a partner who cannot come; a colleague goes instead;
 * the Stripe invoice, the charge and the VAT receipt stay exactly where they
 * were, and the new delegate is never shown any of them. Most of what follows
 * pins one half of that sentence or the other.
 */
class FlagshipSubstituteTest extends LAW_Test_Case {

	private int $flagship = 0;
	private array $mail = array();
	private $mail_filter = null;

	protected function setUp(): void {
		parent::setUp();
		$this->mail        = array();
		$this->mail_filter = function ( $atts ) {
			$this->mail[] = $atts;
			return $atts;
		};
		add_filter( 'wp_mail', $this->mail_filter );
	}

	protected function tearDown(): void {
		remove_filter( 'wp_mail', $this->mail_filter );
		remove_all_filters( 'law_flagship_event_id' );
		remove_all_filters( 'pre_option_law_events_source' );
		law_flagship_event_id( true );
		$_GET  = array();
		$_POST = array();
		parent::tearDown();
	}

	/* Fixtures ______________________________________________________________ */

	private function make_flagship(): int {
		$event_id = $this->make_event(
			array(
				'_law_is_flagship'          => 1,
				'_law_flagship_date'        => '2026-12-02',
				'_law_start'                => gmdate( 'Y-m-d H:i', strtotime( '+31 days 09:30' ) ),
				'_law_flagship_price_pence' => 55000,
				'_law_tickets_available'    => 50,
			),
			'publish'
		);
		$this->flagship = $event_id;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		law_flagship_event_id( true );

		return $event_id;
	}

	private function make_reception( array $meta = array() ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_is_reception'         => 1,
					'_law_flagship_included'    => 1,
					'_law_start'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:30' ) ),
					'_law_tickets_available'    => 10,
					'_law_attendee_price_pence' => 4500,
					'_law_registration_state'   => 'open',
				),
				$meta
			),
			'publish'
		);
	}

	/**
	 * A confirmed, paid flagship ticket, with the Stripe facts a real one
	 * carries so the "none of this moves" assertions have something to hold.
	 *
	 * @return array{booking:int,user:int,email:string}
	 */
	private function make_confirmed_ticket( string $status = 'publish', string $payment = 'paid' ): array {
		$user_id = $this->make_user();
		wp_update_user(
			array( 'ID' => $user_id, 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'display_name' => 'Ada Lovelace' )
		);
		$email = (string) get_userdata( $user_id )->user_email;

		$booking_id = law_booking_insert(
			$this->flagship,
			$user_id,
			$status,
			array(
				'user_id'      => $user_id,
				'name'         => 'Ada Lovelace',
				'email'        => $email,
				'organisation' => 'Analytical Chambers',
				'job_title'    => 'Counsel',
			),
			array(
				'_law_price_pence'              => 55000,
				'_law_vat'                      => 1,
				'_law_payment_status'           => $payment,
				'_law_application_at'           => gmdate( 'Y-m-d H:i' ),
				'_law_stripe_customer_id'       => 'cus_original',
				'_law_stripe_payment_method_id' => 'pm_original',
				'_law_stripe_method_label'      => 'Visa ending 4242, expires 04/2029',
				'_law_stripe_card_last4'        => '4242',
				'_law_stripe_invoice_id'        => 'in_original',
				'_law_stripe_invoice_url'       => 'https://invoice.stripe.test/in_original',
				'_law_stripe_invoice_pdf'       => 'https://invoice.stripe.test/in_original.pdf',
				'_law_stripe_charge_id'         => 'ch_original',
				'_law_discount_code'            => 'CHAMBERS10',
				'_law_discount_pence'           => 5000,
				'_law_ticket_type'              => 'sponsor',
			)
		);
		$this->posts[] = $booking_id;

		return array( 'booking' => (int) $booking_id, 'user' => $user_id, 'email' => $email );
	}

	/** The substitute's details, as the dialog would post them. */
	private function row( string $email = '', array $extra = array() ): array {
		return array_merge(
			array(
				'name'         => 'Grace Hopper',
				'email'        => '' !== $email ? $email : 'grace-' . wp_generate_password( 6, false ) . '@example.test',
				'organisation' => 'Navy Chambers',
				'job_title'    => 'Rear Admiral',
				'profile'      => law_registration_clean_attendee_profile( array( 'country' => 'United States' ) ),
			),
			$extra
		);
	}

	/** Every mail sent to one address. */
	private function mail_to( string $email ): array {
		return array_values(
			array_filter(
				$this->mail,
				static fn( $atts ) => in_array( $email, (array) $atts['to'], true )
			)
		);
	}

	/* Guards ________________________________________________________________ */

	public function test_only_a_confirmed_place_can_be_handed_on(): void {
		$this->make_flagship();

		foreach ( array( 'law-applied', 'law-payment-failed', 'law-declined', 'law-cancelled' ) as $status ) {
			$ticket = $this->make_confirmed_ticket( $status, 'ready' );
			$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

			$this->assertWPError( $result, 'law_flagship_not_confirmed' );
			// The refusal names the control that IS right, rather than only
			// saying no: Decline clears the saved card, Cancel releases a place.
			$this->assertStringContainsString( 'Decline', $result->get_error_message() );
			$this->assertStringContainsString( 'Cancel', $result->get_error_message() );
		}
	}

	public function test_a_booking_that_is_not_the_flagship_is_refused(): void {
		$this->make_flagship();
		$other   = $this->make_event( array(), 'publish' );
		$user_id = $this->make_user();
		$booking = law_booking_insert( $other, $user_id, 'publish', array( 'user_id' => $user_id, 'name' => 'Someone', 'email' => 'x@example.test' ) );
		$this->posts[] = $booking;

		$result = law_flagship_substitute( (int) $booking, $this->row(), 0 );

		$this->assertWPError( $result, 'law_flagship_not_application' );
	}

	public function test_a_blank_name_or_bad_email_refuses_and_creates_nobody(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$blank = law_flagship_substitute( $ticket['booking'], $this->row( '', array( 'name' => '  ' ) ), 0 );
		$this->assertWPError( $blank, 'law_flagship_no_name' );
		$this->assertSame( 'name', $blank->get_error_data()['field'] );

		$bad = law_flagship_substitute( $ticket['booking'], $this->row( 'not-an-email' ), 0 );
		$this->assertWPError( $bad, 'law_flagship_bad_email' );
		$this->assertSame( 'email', $bad->get_error_data()['field'] );

		$this->assertFalse( get_user_by( 'email', 'not-an-email' ), 'Nothing was minted for a refused row.' );
		// And the place did not move.
		$this->assertSame( $ticket['user'], (int) get_post_field( 'post_author', $ticket['booking'] ) );
	}

	public function test_substituting_somebody_for_themselves_is_refused(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$result = law_flagship_substitute( $ticket['booking'], $this->row( $ticket['email'] ), 0 );

		$this->assertWPError( $result, 'law_flagship_same_person' );
	}

	/* Rollback ______________________________________________________________ */

	/**
	 * The regression test for the bug this feature found: every other caller
	 * passes law_booking_delete_created_users() a map keyed by user ID, and
	 * law_flagship_add_complimentary() passed a bare list — so it deleted user
	 * 0 and left the real account behind on every refusal. Asserting the error
	 * came back would not have caught it; asserting the account is GONE does.
	 */
	public function test_a_substitute_who_already_holds_a_place_is_refused_and_their_new_account_removed(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		// Somebody else already holding a place, under an address nobody has an
		// account for, so resolving it would mint one.
		$holder = $this->make_user();
		$taken  = 'taken-' . wp_generate_password( 6, false ) . '@example.test';
		$second = law_booking_insert(
			$this->flagship,
			$holder,
			'law-applied',
			array( 'user_id' => $holder, 'name' => 'Held Already', 'email' => $taken ),
			array( '_law_payment_status' => 'ready' )
		);
		$this->posts[] = $second;

		$result = law_flagship_substitute( $ticket['booking'], $this->row( $taken ), 0 );

		$this->assertWPError( $result, 'law_flagship_duplicate' );
		$this->assertSame( $ticket['user'], (int) get_post_field( 'post_author', $ticket['booking'] ), 'The place stayed put.' );
	}

	public function test_a_refused_substitution_leaves_no_orphan_account(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		// A duplicate caught on the EMAIL rather than the user, so the account
		// really is created inside the call and then has to be removed again.
		$holder = $this->make_user();
		$email  = (string) get_userdata( $holder )->user_email;
		$second = law_booking_insert(
			$this->flagship,
			$holder,
			'publish',
			array( 'user_id' => $holder, 'name' => 'Held Already', 'email' => $email ),
			array( '_law_payment_status' => 'paid' )
		);
		$this->posts[] = $second;

		$before = count_users()['total_users'];
		$result = law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 );

		$this->assertWPError( $result, 'law_flagship_duplicate' );
		$this->assertSame( $before, count_users()['total_users'], 'No account was left behind.' );
	}

	/**
	 * Every status that holds a place blocks the transfer, and the two that do
	 * not hold one do not.
	 *
	 * The interesting half is the second: somebody the committee DECLINED, or
	 * who withdrew, has no place and no charge, so there is nothing to stop a
	 * firm sending them in someone else's stead. Blocking them would be a
	 * refusal with no fact behind it.
	 */
	public function test_every_status_that_holds_a_place_blocks_the_transfer(): void {
		$this->make_flagship();

		foreach ( law_booking_holding_statuses() as $status ) {
			$ticket = $this->make_confirmed_ticket();
			$other  = $this->make_user();
			$email  = (string) get_userdata( $other )->user_email;
			$held   = law_booking_insert(
				$this->flagship,
				$other,
				$status,
				array( 'user_id' => $other, 'name' => 'Already Here', 'email' => $email ),
				array( '_law_payment_status' => 'ready' )
			);
			$this->posts[] = $held;

			$this->assertWPError(
				law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 ),
				'law_flagship_duplicate'
			);
			$this->assertSame(
				$ticket['user'],
				(int) get_post_field( 'post_author', $ticket['booking'] ),
				$status . ' holds a place, so the ticket must not move onto it.'
			);
		}

		foreach ( array( 'law-declined', 'law-cancelled' ) as $status ) {
			$ticket = $this->make_confirmed_ticket();
			$other  = $this->make_user();
			$email  = (string) get_userdata( $other )->user_email;
			$ended  = law_booking_insert(
				$this->flagship,
				$other,
				$status,
				array( 'user_id' => $other, 'name' => 'Turned Away', 'email' => $email ),
				array( '_law_payment_status' => 'ready' )
			);
			$this->posts[] = $ended;

			$result = law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 );

			$this->assertIsArray( $result, $status . ' holds nothing, so it must not block a transfer.' );
			$this->assertSame( $other, (int) $result['user_id'] );
		}
	}

	/**
	 * The guard has to recognise a person by their ACCOUNT address as well as
	 * by the one snapshotted on their booking, or somebody who has changed
	 * their email since registering could be handed a second place.
	 */
	public function test_a_place_held_under_an_old_address_still_blocks_the_transfer(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$other = $this->make_user();
		$now   = (string) get_userdata( $other )->user_email;
		$held  = law_booking_insert(
			$this->flagship,
			$other,
			'publish',
			// The snapshot carries the address they registered under; the
			// account has since moved on.
			array( 'user_id' => $other, 'name' => 'Already Here', 'email' => 'old-address@example.test' ),
			array( '_law_payment_status' => 'paid' )
		);
		$this->posts[] = $held;

		$this->assertWPError(
			law_flagship_substitute( $ticket['booking'], $this->row( $now ), 0 ),
			'law_flagship_duplicate'
		);
		$this->assertWPError(
			law_flagship_substitute( $ticket['booking'], $this->row( 'old-address@example.test' ), 0 ),
			'law_flagship_duplicate'
		);
	}

	/* The swap ______________________________________________________________ */

	public function test_the_seat_moves_and_the_snapshot_moves_with_it(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		$row    = $this->row();

		$result = law_flagship_substitute( $ticket['booking'], $row, 0 );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['created'], 'An unknown address gets an account.' );

		$booking = get_post( $ticket['booking'] );
		$this->assertSame( $result['user_id'], (int) $booking->post_author );
		$this->assertSame( 'publish', $booking->post_status, 'Still confirmed.' );

		$person = law_booking_attendee( $ticket['booking'] );
		$this->assertSame( 'Grace Hopper', $person['name'] );
		$this->assertSame( $row['email'], $person['email'] );
		$this->assertSame( 'Navy Chambers', $person['organisation'] );
		$this->assertSame( 'Rear Admiral', $person['job_title'] );
		// Self-booked, so the ticket does not turn up in anybody's "bookings
		// you made for other people".
		$this->assertSame( $result['user_id'], (int) law_event_meta( $ticket['booking'], '_law_booked_by' ) );
	}

	public function test_the_money_does_not_move_and_stripe_is_never_called(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$before = array();
		$keys   = array(
			'_law_stripe_customer_id',
			'_law_stripe_payment_method_id',
			'_law_stripe_method_label',
			'_law_stripe_invoice_id',
			'_law_stripe_invoice_url',
			'_law_stripe_invoice_pdf',
			'_law_stripe_charge_id',
			'_law_payment_status',
			'_law_price_pence',
			'_law_vat',
			'_law_discount_code',
			'_law_discount_pence',
			'_law_booking_number',
			'_law_ticket_type',
		);
		foreach ( $keys as $key ) {
			$before[ $key ] = law_event_meta( $ticket['booking'], $key );
		}

		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		foreach ( $keys as $key ) {
			$this->assertSame(
				$before[ $key ],
				law_event_meta( $ticket['booking'], $key ),
				$key . ' must not change when a place changes hands.'
			);
		}
		// The strongest form of the promise: not one request left the site.
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'] );
	}

	public function test_the_headcount_is_unchanged(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		law_event_recount_attendees( $this->flagship, 'flagship' );
		// The STORED count, not a fresh recount. Recounting either side can
		// only differ if a status moved, so the original assertion could not
		// have failed however wrong the code was.
		$before = (int) law_event_meta( $this->flagship, '_law_tickets_sold' );

		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertSame(
			$before,
			(int) law_event_meta( $this->flagship, '_law_tickets_sold' ),
			'One seat out is one seat in, and nothing rewrote the counter.'
		);
	}

	public function test_a_press_pass_survives_the_move(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		law_event_update_meta( $ticket['booking'], '_law_is_press', 1 );

		law_flagship_substitute( $ticket['booking'], $this->row( '', array( 'press' => true ) ), 0 );

		$this->assertSame( 1, (int) law_event_meta( $ticket['booking'], '_law_is_press' ) );
	}

	public function test_the_ticket_leaves_one_account_and_arrives_in_the_other(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertNotContains( $ticket['booking'], law_user_booking_ids( $ticket['user'] ) );
		$this->assertContains( $ticket['booking'], law_user_booking_ids( (int) $result['user_id'] ) );
	}

	public function test_the_audit_meta_survives_the_original_account_being_deleted(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		$actor  = $this->make_committee_user();

		law_flagship_substitute( $ticket['booking'], $this->row(), $actor );

		$this->assertSame( $ticket['user'], (int) law_event_meta( $ticket['booking'], '_law_substituted_from' ) );
		$this->assertSame( 'Ada Lovelace', (string) law_event_meta( $ticket['booking'], '_law_substituted_from_name' ) );
		$this->assertSame( $ticket['email'], (string) law_event_meta( $ticket['booking'], '_law_substituted_from_email' ) );
		$this->assertSame( $actor, (int) law_event_meta( $ticket['booking'], '_law_substituted_by' ) );
		$this->assertNotSame( '', (string) law_event_meta( $ticket['booking'], '_law_substituted_at' ) );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $ticket['user'] );

		// The name and the address are frozen precisely for this: a bare
		// integer would render as nothing on the dashboard and tell a refund
		// query nothing at all.
		$this->assertSame( 'Ada Lovelace', (string) law_event_meta( $ticket['booking'], '_law_substituted_from_name' ) );
		$this->assertSame( $ticket['email'], (string) law_event_meta( $ticket['booking'], '_law_substituted_from_email' ) );
		$this->assertSame( 'publish', get_post_status( $ticket['booking'] ), 'And the place is not cancelled with them.' );
	}

	public function test_a_substituted_paid_place_refuses_to_be_charged_again(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		// Forced into the state retry needs, to prove the guard is the thing
		// refusing rather than the status check in front of it.
		law_booking_set_status( $ticket['booking'], 'law-payment-failed' );

		$retry = law_flagship_retry_charge( $ticket['booking'], 0 );
		$this->assertWPError( $retry, 'law_flagship_substituted' );

		$setup = law_stripe_create_setup_session( $ticket['booking'] );
		$this->assertWPError( $setup, 'law_booking_substituted_paid' );
		$this->assertSame( 'cus_original', (string) law_event_meta( $ticket['booking'], '_law_stripe_customer_id' ) );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'] );
	}

	/* Receptions ____________________________________________________________ */

	public function test_an_included_reception_place_moves_with_the_ticket(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$ticket    = $this->make_confirmed_ticket();

		$granted = law_reception_grant_included( $reception, $ticket['user'], $ticket['booking'] );
		$this->assertIsInt( $granted );
		$this->posts[] = $granted;
		$sold_before   = law_event_recount_attendees( $reception );

		$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertSame( array( $reception => get_the_title( $reception ) ), $result['receptions']['moved'] );
		$this->assertSame( (int) $result['user_id'], (int) get_post_field( 'post_author', $granted ) );
		$this->assertSame( 'publish', get_post_status( $granted ) );
		$this->assertSame(
			$ticket['booking'],
			(int) law_event_meta( $granted, '_law_included_with' ),
			'It is still the same ticket that pays for it.'
		);
		$this->assertSame( $sold_before, law_event_recount_attendees( $reception ), 'The room is no fuller and no emptier.' );
		$this->assertSame( 'Grace Hopper', law_booking_attendee( $granted )['name'] );
	}

	public function test_moving_a_reception_place_never_promotes_from_its_waitlist(): void {
		$this->make_flagship();
		$reception = $this->make_reception( array( '_law_tickets_available' => 1 ) );
		$ticket    = $this->make_confirmed_ticket();

		$granted = law_reception_grant_included( $reception, $ticket['user'], $ticket['booking'] );
		$this->posts[] = $granted;

		$waiting = $this->make_user();
		$entry   = law_booking_insert(
			$reception,
			$waiting,
			'law-waitlisted',
			array( 'user_id' => $waiting, 'name' => 'In The Queue', 'email' => 'queue@example.test' ),
			array( '_law_waitlist_position' => 1, '_law_payment_status' => 'ready' )
		);
		$this->posts[] = $entry;

		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		// The place never came free, so nothing may be seated into it. Revoke
		// and re-grant WOULD have freed it for an instant, and the grant that
		// follows has no capacity guard, so the room would have over-booked.
		$this->assertSame( 'law-waitlisted', get_post_status( $entry ) );
		$this->assertSame( 1, (int) law_event_meta( $entry, '_law_waitlist_position' ) );
	}

	public function test_a_substitute_who_already_bought_that_reception_keeps_their_own_place(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$ticket    = $this->make_confirmed_ticket();

		$granted = law_reception_grant_included( $reception, $ticket['user'], $ticket['booking'] );
		$this->posts[] = $granted;

		// The substitute, with an account and a paid place of their own.
		$substitute = $this->make_user();
		$email      = (string) get_userdata( $substitute )->user_email;
		$theirs     = law_booking_insert(
			$reception,
			$substitute,
			'publish',
			array( 'user_id' => $substitute, 'name' => 'Grace Hopper', 'email' => $email ),
			array( '_law_price_pence' => 4500, '_law_payment_status' => 'paid' )
		);
		$this->posts[] = $theirs;

		$result = law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 );

		$this->assertSame( array( $reception => get_the_title( $reception ) ), $result['receptions']['released'] );
		$this->assertSame( array(), $result['receptions']['moved'] );
		$this->assertSame( 'publish', get_post_status( $theirs ), 'The place they paid for is untouched.' );
		$this->assertSame( 'paid', (string) law_event_meta( $theirs, '_law_payment_status' ) );
		$this->assertSame( 'law-cancelled', get_post_status( $granted ), 'Nobody holds two.' );
	}

	public function test_a_reception_that_has_already_happened_still_moves(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$ticket    = $this->make_confirmed_ticket();

		$granted = law_reception_grant_included( $reception, $ticket['user'], $ticket['booking'] );
		$this->posts[] = $granted;

		// Past, so law_reception_included_ids() no longer lists it — which is
		// exactly what a revoke-and-re-grant would have silently dropped.
		law_event_update_meta( $reception, '_law_start', gmdate( 'Y-m-d H:i', strtotime( '-2 days' ) ) );

		$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertArrayHasKey( $reception, $result['receptions']['moved'] );
		$this->assertSame( (int) $result['user_id'], (int) get_post_field( 'post_author', $granted ) );
	}

	/* Emails ________________________________________________________________ */

	public function test_the_new_delegate_is_told_and_is_shown_none_of_the_payer_s_facts(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		$row    = $this->row();

		law_flagship_substitute( $ticket['booking'], $row, 0 );

		$theirs = $this->mail_to( $row['email'] );
		$this->assertCount( 1, $theirs, 'One email, not a welcome and a confirmation.' );

		$body = (string) $theirs[0]['message'];
		$this->assertStringContainsString( 'Ada Lovelace', $body, 'It names who they are replacing.' );
		$this->assertStringContainsString( 'nothing for you to pay', $body );
		$this->assertStringNotContainsString( 'invoice.stripe.test', $body, 'The payer\'s receipt is not theirs to see.' );
		$this->assertStringNotContainsString( '4242', $body, 'Nor the payer\'s card.' );
		$this->assertStringNotContainsString( '£660.00', $body, 'Nor what the payer was charged.' );
		$this->assertStringNotContainsString( 'CHAMBERS10', $body, 'Nor the code the payer used.' );
		$this->assertNotEmpty( $theirs[0]['attachments'], 'The calendar invite rides along.' );
	}

	/**
	 * One new template, not two (Denis, 21 September 2026). The person arriving
	 * is an ordinary confirmed delegate and gets the ordinary confirmation;
	 * only the person losing a place needed wording that did not exist.
	 */
	public function test_the_substitution_adds_exactly_one_template_to_the_registry(): void {
		$registry = law_events_email_registry();

		$this->assertArrayHasKey( 'user_flagship_place_transferred', $registry );
		// The count, not the absence of one invented slug: asserting a key is
		// missing passes just as happily when a SECOND template is added.
		$this->assertCount(
			79,
			$registry,
			'A template was added or removed. If that is deliberate, update this number and say why in EVENTS_FUNC.md.'
		);
	}

	/**
	 * The approval email has to keep working for the person who actually paid,
	 * now that its money paragraph is resolved rather than four tags in the
	 * body.
	 */
	public function test_an_ordinary_approval_still_says_what_was_taken_and_links_the_invoice(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket( 'law-applied', 'ready' );

		law_flagship_confirm( $ticket['booking'], 'paid', 0 );

		$body = (string) $this->mail_to( $ticket['email'] )[0]['message'];
		$this->assertStringContainsString( 'We have taken', $body );
		$this->assertStringContainsString( 'Visa ending 4242', $body );
		$this->assertStringContainsString( 'invoice.stripe.test', $body );
		$this->assertStringContainsString( 'CHAMBERS10', $body, 'And the code they used.' );
	}

	/**
	 * The bug Denis found on a real transfer: a ticket a discount code covered
	 * in full raises no Stripe invoice, so the sentence promising one ended in
	 * a colon and nothing at all.
	 */
	public function test_a_ticket_with_no_invoice_never_promises_one(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		// Paid in full by a code: no Stripe invoice exists for it.
		delete_post_meta( $ticket['booking'], '_law_stripe_invoice_url' );
		delete_post_meta( $ticket['booking'], '_law_stripe_invoice_pdf' );
		law_event_update_meta( $ticket['booking'], '_law_price_pence', 0 );

		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$body = (string) $this->mail_to( $ticket['email'] )[0]['message'];
		$this->assertStringContainsString( 'nothing to refund', $body );
		$this->assertStringNotContainsString(
			'download it here at any time',
			$body,
			'No invoice means a different sentence, not a broken one.'
		);
		$this->assertDoesNotMatchRegularExpression(
			'/:\s*(<\/p>|\n\s*\n|$)/',
			wp_strip_all_tags( $body ),
			'And no sentence left hanging on a colon.'
		);
	}

	/** Paid, but with no invoice to link: offer to send one rather than promise a link. */
	public function test_a_paid_ticket_with_no_invoice_offers_a_receipt_instead(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		delete_post_meta( $ticket['booking'], '_law_stripe_invoice_url' );

		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$body = (string) $this->mail_to( $ticket['email'] )[0]['message'];
		$this->assertStringContainsString( 'please reply to this email', $body );
		$this->assertStringNotContainsString( 'download it here at any time', $body );
	}

	public function test_a_brand_new_account_gets_its_password_link_in_that_same_email(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		$row    = $this->row();

		law_flagship_substitute( $ticket['booking'], $row, 0 );

		$theirs = $this->mail_to( $row['email'] );
		$this->assertCount( 1, $theirs );
		$this->assertStringContainsString( 'action=reset', (string) $theirs[0]['message'], 'The set-password link is in it.' );
	}

	public function test_an_existing_account_is_told_to_sign_in_as_usual(): void {
		$this->make_flagship();
		$ticket   = $this->make_confirmed_ticket();
		$existing = $this->make_user();
		$email    = (string) get_userdata( $existing )->user_email;

		$result = law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 );

		$this->assertFalse( $result['created'] );
		$this->assertSame( $existing, (int) $result['user_id'], 'Linked, never duplicated.' );

		$body = (string) $this->mail_to( $email )[0]['message'];
		$this->assertStringContainsString( 'sign in with your usual details', $body );
		$this->assertStringNotContainsString( 'action=reset', $body );
	}

	public function test_the_person_giving_it_up_keeps_a_route_to_their_receipt(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		// Sent to the address captured BEFORE the swap. Reading it back off the
		// booking afterwards would post this to the person who RECEIVED the
		// place.
		$theirs = $this->mail_to( $ticket['email'] );
		$this->assertCount( 1, $theirs );

		$body = (string) $theirs[0]['message'];
		$this->assertStringContainsString( 'Ada Lovelace', $body, 'Addressed to them, not to the substitute.' );
		$this->assertStringContainsString( 'Grace Hopper', $body, 'And it says where the place went.' );
		$this->assertStringContainsString(
			'invoice.stripe.test',
			$body,
			'The booking has left their account, so this email IS their receipt now.'
		);
		$this->assertEmpty( $theirs[0]['attachments'], 'No calendar invite for a place they no longer hold.' );
	}

	/* The profile ___________________________________________________________ */

	public function test_an_existing_account_keeps_what_it_stated_itself(): void {
		$this->make_flagship();
		$ticket   = $this->make_confirmed_ticket();
		$existing = $this->make_user();
		$email    = (string) get_userdata( $existing )->user_email;
		update_user_meta( $existing, 'country', 'Ireland' );

		law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 );

		$this->assertSame(
			'Ireland',
			(string) get_user_meta( $existing, 'country', true ),
			'A committee member typing what they remember of a phone call never overwrites what the person stated.'
		);
	}

	public function test_an_account_above_subscriber_is_not_written_to_at_all(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		$member = $this->make_committee_user();
		$email  = (string) get_userdata( $member )->user_email;

		law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 );

		// The 11 September 2026 rule: health-adjacent data must not be
		// attachable to a privileged account by anyone who knows its address.
		$this->assertSame( '', (string) get_user_meta( $member, 'country', true ) );
	}

	/* What the new delegate can see _________________________________________ */

	public function test_the_payment_facts_are_hidden_from_the_person_who_did_not_pay(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$this->assertTrue(
			law_booking_payment_facts_visible( $ticket['booking'], $ticket['user'] ),
			'Before any substitution, the holder is the payer.'
		);

		$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertFalse(
			law_booking_payment_facts_visible( $ticket['booking'], (int) $result['user_id'] ),
			'The hosted Stripe invoice carries the payer\'s name, address and card last four.'
		);
		$this->assertTrue(
			law_booking_payment_facts_visible( $ticket['booking'], $ticket['user'] ),
			'And it is still the payer\'s to see.'
		);
	}

	/* The log _______________________________________________________________ */

	public function test_the_transfer_is_logged_with_both_people_and_where_the_money_stayed(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		$actor  = $this->make_committee_user();
		$row    = $this->row();

		law_flagship_substitute( $ticket['booking'], $row, $actor );

		$found = null;
		foreach ( law_event_log_entries( $this->flagship ) as $entry ) {
			$context = law_event_log_context( $entry->comment_ID );
			if ( 'flagship_substituted' === ( $context['action'] ?? '' ) ) {
				$found = array( 'entry' => $entry, 'context' => $context );
			}
		}

		$this->assertNotNull( $found, 'The substitution is in the activity log.' );
		$this->assertSame( $ticket['booking'], (int) $found['context']['booking'] );
		$this->assertSame( $ticket['user'], (int) $found['context']['from'] );
		$this->assertStringContainsString( 'Ada Lovelace', $found['entry']->comment_content );
		$this->assertStringContainsString( 'Grace Hopper', $found['entry']->comment_content );
		$this->assertStringContainsString( 'The money has not moved', $found['entry']->comment_content );
	}

	/* The dashboard _________________________________________________________ */

	public function test_the_row_and_the_export_say_a_place_changed_hands(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$before = law_flagship_bookings_rows( array() )['rows'][0];
		$this->assertTrue( $before['substitutable'], 'A confirmed place can be handed on.' );
		$this->assertSame( '', $before['substituted_from'] );

		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$after = law_flagship_bookings_rows( array() )['rows'][0];
		$this->assertSame( 'Grace Hopper', $after['name'] );
		$this->assertSame( 'Ada Lovelace', $after['substituted_from'] );

		$export = law_flagship_bookings_export_rows( array() );
		$column = array_search( 'Substituted from', $export['columns'], true );
		$this->assertNotFalse( $column, 'The exports carry it for the on-site team.' );
		$this->assertSame( 'Ada Lovelace', $export['rows'][0][ $column ] );
	}

	public function test_only_a_confirmed_place_offers_the_action(): void {
		$this->make_flagship();
		$this->make_confirmed_ticket( 'law-applied', 'ready' );

		$row = law_flagship_bookings_rows( array() )['rows'][0];

		$this->assertFalse( $row['substitutable'] );
	}

	/* The handler ___________________________________________________________ */

	/**
	 * The check under the event lock has to see the DATABASE, not this
	 * request's post cache — which still holds the copy read at the top of the
	 * function and would have it comparing the row against itself. Simulated
	 * the only way a unit test can: write the row behind WordPress's back, the
	 * way a concurrent request would appear to us, and check the refusal.
	 */
	public function test_a_place_that_moved_while_the_dialog_was_open_is_refused(): void {
		global $wpdb;
		$this->make_flagship();
		$ticket   = $this->make_confirmed_ticket();
		$meanwhile = $this->make_user();

		// Warm the cache exactly as the real request does.
		get_post( $ticket['booking'] );

		// Somebody else's Cancel or substitution, landing between the guards
		// and the write. No clean_post_cache(), so only a real read finds it.
		$wpdb->update(
			$wpdb->posts,
			array( 'post_author' => $meanwhile ),
			array( 'ID' => $ticket['booking'] )
		);

		$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertWPError( $result, 'law_flagship_moved' );
		$this->assertSame( $meanwhile, (int) get_post_field( 'post_author', $ticket['booking'] ), 'The other change stands.' );
	}

	/* What the reviews found ________________________________________________ */

	/**
	 * A ticket can change hands twice, and the money must still point at
	 * whoever actually paid.
	 *
	 * The first cut rewrote `_law_substituted_from` on every transfer, so a
	 * second one named the FIRST substitute — who paid nothing. That inverted
	 * the rule outright: the payer lost sight of their own receipt, the person
	 * who had paid nothing gained it, and the transfer email handed them a link
	 * to the payer's hosted Stripe invoice, which carries their name, billing
	 * address and card last four.
	 */
	public function test_a_second_transfer_still_names_the_person_who_paid(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$first  = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );
		$second = law_flagship_substitute( $ticket['booking'], $this->row( '', array( 'name' => 'Alan Turing' ) ), 0 );

		$this->assertIsArray( $second, 'Re-substitution stays allowed.' );
		$this->assertSame(
			$ticket['user'],
			(int) law_event_meta( $ticket['booking'], '_law_substituted_from' ),
			'Still the payer, not the delegate who held it last.'
		);
		$this->assertSame( $ticket['email'], (string) law_event_meta( $ticket['booking'], '_law_substituted_from_email' ) );

		$this->assertTrue( law_booking_payment_facts_visible( $ticket['booking'], $ticket['user'] ) );
		$this->assertFalse( law_booking_payment_facts_visible( $ticket['booking'], (int) $first['user_id'] ) );
		$this->assertFalse( law_booking_payment_facts_visible( $ticket['booking'], (int) $second['user_id'] ) );

		// The payer's OWN first email rightly carries her invoice. What must not
		// happen is the middle holder being handed it on their way out: they
		// inherited the place and paid nothing for it.
		$middle = get_userdata( (int) $first['user_id'] )->user_email;
		foreach ( $this->mail_to( $middle ) as $sent ) {
			$this->assertStringNotContainsString(
				'invoice.stripe.test',
				(string) $sent['message'],
				'A delegate who paid nothing was handed the payer\'s hosted invoice.'
			);
		}
		$this->assertStringContainsString(
			'You were not charged for this place',
			(string) $this->mail_to( $middle )[1]['message'],
			'They are told plainly that there is nothing of theirs to refund.'
		);
	}

	/**
	 * The transfer branch must not hinge on a display string being non-empty.
	 *
	 * With both the attendee snapshot name and the account address blank, the
	 * "is this a transfer" test was false and the new delegate was sent the
	 * APPROVAL paragraph: invoice URL, card, price and "your registration has
	 * been approved", none of it theirs.
	 */
	public function test_a_nameless_previous_delegate_still_gets_the_transfer_wording(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		law_event_update_meta( $ticket['booking'], '_law_attendee_name', '' );
		law_event_update_meta( $ticket['booking'], '_law_attendee_email', '' );
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->users, array( 'user_email' => '' ), array( 'ID' => $ticket['user'] ) );
		clean_user_cache( $ticket['user'] );

		$row = $this->row();
		law_flagship_substitute( $ticket['booking'], $row, 0 );

		$body = (string) $this->mail_to( $row['email'] )[0]['message'];
		$this->assertStringContainsString( 'transferred to you', $body );
		$this->assertStringNotContainsString( 'invoice.stripe.test', $body );
		$this->assertStringNotContainsString( '4242', $body );
		$this->assertStringNotContainsString( 'approved by the committee', $body );
	}

	/**
	 * Somebody merely QUEUED for an included reception must not lose the free
	 * place the ticket carries.
	 *
	 * `law_reception_holds_place()` counts a waitlist entry as holding a place,
	 * so the included one was cancelled; that fed `law_waitlist_process()`,
	 * which promoted the substitute off the very queue they were on and charged
	 * them for a reception their transferred ticket already included.
	 */
	public function test_a_substitute_queued_for_a_reception_keeps_the_included_place(): void {
		$this->make_flagship();
		$reception = $this->make_reception( array( '_law_tickets_available' => 1 ) );
		$ticket    = $this->make_confirmed_ticket();

		$granted = law_reception_grant_included( $reception, $ticket['user'], $ticket['booking'] );
		$this->posts[] = $granted;

		$substitute = $this->make_user();
		$email      = (string) get_userdata( $substitute )->user_email;
		$queued     = law_booking_insert(
			$reception,
			$substitute,
			'law-waitlisted',
			array( 'user_id' => $substitute, 'name' => 'Grace Hopper', 'email' => $email ),
			array( '_law_waitlist_position' => 1, '_law_payment_status' => 'ready' )
		);
		$this->posts[] = $queued;

		$result = law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 );

		$this->assertArrayHasKey( $reception, $result['receptions']['moved'], 'A queue slot is not a place.' );
		$this->assertSame( array(), $result['receptions']['released'] );
		$this->assertSame( 'publish', get_post_status( $granted ) );
		$this->assertSame( (int) $result['user_id'], (int) get_post_field( 'post_author', $granted ) );
		$this->assertSame( 'law-waitlisted', get_post_status( $queued ), 'And nobody was promoted or charged.' );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'] );
	}

	/** The same for a checkout still in flight. */
	public function test_a_substitute_mid_checkout_for_a_reception_keeps_the_included_place(): void {
		$this->make_flagship();
		$reception = $this->make_reception();
		$ticket    = $this->make_confirmed_ticket();

		$granted = law_reception_grant_included( $reception, $ticket['user'], $ticket['booking'] );
		$this->posts[] = $granted;

		$substitute = $this->make_user();
		$email      = (string) get_userdata( $substitute )->user_email;
		$shell      = law_booking_insert(
			$reception,
			$substitute,
			'law-pending-payment',
			array( 'user_id' => $substitute, 'name' => 'Grace Hopper', 'email' => $email ),
			array( '_law_payment_status' => 'pending_setup' )
		);
		$this->posts[] = $shell;

		$result = law_flagship_substitute( $ticket['booking'], $this->row( $email ), 0 );

		$this->assertArrayHasKey( $reception, $result['receptions']['moved'] );
		$this->assertSame( 'publish', get_post_status( $granted ) );
	}

	/** A refunded place is a cancellation, not a transfer. */
	public function test_a_refunded_place_cannot_be_transferred(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		law_event_update_meta( $ticket['booking'], '_law_payment_status', 'refunded' );

		$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertWPError( $result, 'law_flagship_refunded' );
		$this->assertStringContainsString( 'Cancel it', $result->get_error_message() );
	}

	/** The charge path itself refuses, not just the three callers in front of it. */
	public function test_a_transferred_booking_can_never_be_charged(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertWPError( law_stripe_charge_booking( $ticket['booking'] ), 'law_booking_substituted' );
		$this->assertWPError( law_stripe_create_setup_session( $ticket['booking'] ), 'law_booking_substituted_paid' );
		$this->assertSame( 'cus_original', (string) law_event_meta( $ticket['booking'], '_law_stripe_customer_id' ) );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'] );
	}

	/** The payer's negotiated code is not the new delegate's business either. */
	public function test_the_payer_s_discount_code_is_hidden_from_the_new_delegate(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$before = law_booking_payment_facts_mask( $ticket['booking'], $ticket['user'] );
		$this->assertTrue( $before['code'] );

		$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );
		$mask   = law_booking_payment_facts_mask( $ticket['booking'], (int) $result['user_id'] );

		$this->assertFalse( $mask['code'], 'A code is reusable by whoever reads it.' );
		$this->assertFalse( $mask['invoice'] );
		$this->assertFalse( $mask['card'] );
		$this->assertTrue( $mask['price'], 'What the place cost is public; who paid and how is not.' );
	}

	/** The committee's confirmation must not claim an email that never went. */
	public function test_an_unreachable_previous_delegate_is_reported_not_assumed(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		law_event_update_meta( $ticket['booking'], '_law_attendee_email', '' );
		$GLOBALS['wpdb']->update( $GLOBALS['wpdb']->users, array( 'user_email' => '' ), array( 'ID' => $ticket['user'] ) );
		clean_user_cache( $ticket['user'] );

		$result = law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$this->assertFalse( $result['told_previous'] );
	}

	/** The transfer is in the log even if the reception move dies after it. */
	public function test_the_transfer_is_logged_before_the_best_effort_work(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		law_flagship_substitute( $ticket['booking'], $this->row(), 0 );

		$actions = array();
		foreach ( law_event_log_entries( $this->flagship ) as $entry ) {
			$context = law_event_log_context( $entry->comment_ID );
			if ( isset( $context['action'] ) ) {
				$actions[] = $context['action'];
			}
		}
		$transfer = array_search( 'flagship_substituted', $actions, true );
		$this->assertNotFalse( $transfer );
	}

	/* What actually renders ________________________________________________ */

	public function test_the_dialog_and_the_no_js_form_both_render(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		law_event_update_meta( $ticket['booking'], '_law_is_press', 1 );
		wp_set_current_user( $this->make_committee_user() );

		ob_start();
		get_template_part( 'parts/events/flagship-substitute', null, array( 'booking_id' => 0 ) );
		$dialog = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="law-flagship-substitute"', $dialog );
		$this->assertStringContainsString( 'law_flagship_substitute', $dialog, 'It posts to the right handler.' );
		$this->assertStringContainsString( 'data-law-sub-current-template', $dialog, 'The sentence is PHP\'s, not the script\'s.' );
		$this->assertStringContainsString( 'law_website_url', $dialog, 'The honeypot is on it.' );
		$this->assertStringNotContainsString( 'law_receptions', $dialog, 'Which receptions move is the ticket\'s to say, not a tick box\'s.' );

		ob_start();
		get_template_part( 'parts/events/flagship-substitute', null, array( 'booking_id' => $ticket['booking'] ) );
		$inline = (string) ob_get_clean();

		$this->assertStringContainsString( 'Ada Lovelace', $inline, 'Pre-filled with whoever holds it.' );
		$this->assertStringContainsString(
			'value="' . $ticket['booking'] . '"',
			$inline,
			'And with the place it is about, so nobody types a post ID.'
		);
		// Without JavaScript nothing carries the pass across, so the form has
		// to render it already ticked or a substitution would drop it.
		$this->assertMatchesRegularExpression( '/name="law_press"[^>]*checked/', $inline );
	}

	public function test_the_dialog_is_not_rendered_for_a_delegate(): void {
		$this->make_flagship();
		wp_set_current_user( $this->make_user() );

		ob_start();
		get_template_part( 'parts/events/flagship-substitute', null, array( 'booking_id' => 0 ) );

		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	public function test_the_no_js_view_is_committee_only_and_confirmed_only(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();

		$_GET['law_substitute'] = (string) $ticket['booking'];

		wp_set_current_user( $this->make_user() );
		$this->assertSame( 0, law_flagship_substitute_requested_id(), 'Not for a delegate.' );

		wp_set_current_user( $this->make_committee_user() );
		$this->assertSame( $ticket['booking'], law_flagship_substitute_requested_id() );

		law_booking_set_status( $ticket['booking'], 'law-cancelled' );
		$this->assertSame( 0, law_flagship_substitute_requested_id(), 'Nor for a place nobody holds.' );
	}

	/**
	 * The no-JS path posts and redirects back to the referring URL, which still
	 * carries law_substitute. Without this the page would report the transfer
	 * and then offer the same form again, pre-filled with the person who has
	 * just received the place — one press from passing it straight on.
	 */
	public function test_the_no_js_form_is_not_offered_again_after_it_worked(): void {
		$this->make_flagship();
		$ticket = $this->make_confirmed_ticket();
		wp_set_current_user( $this->make_committee_user() );

		$_GET['law_substitute'] = (string) $ticket['booking'];

		$_GET['law_notice'] = 'flagship-substituted';
		$this->assertSame( 0, law_flagship_substitute_requested_id() );

		// A refusal still renders it, so whatever was wrong can be corrected.
		$_GET['law_notice'] = 'flagship-failed';
		$this->assertSame( $ticket['booking'], law_flagship_substitute_requested_id() );
	}
}
