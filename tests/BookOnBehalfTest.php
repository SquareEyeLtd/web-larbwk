<?php
/**
 * Booking a paid place for someone else (BOOK_ON_BEHALF.md): a PA registers a
 * partner for the flagship, or buys them a reception place, and pays.
 *
 * What these pin:
 *
 * - "Myself" behaves exactly as it always did;
 * - "Someone else" makes the attendee the booking's author and the booker its
 *   _law_booked_by, matches or creates the attendee's account, and writes
 *   their answers to that account ONLY when this booking created it;
 * - nothing is left behind by a refusal, and a rollback never deletes an
 *   account somebody has since booked onto;
 * - the money is the booker's: Stripe customer, metadata, invoice line;
 * - who may do what (booker, attendee, stranger);
 * - who is emailed what, including through a template the client has edited.
 *
 * Stripe is mocked through the law_stripe_request_mock seam; an unmocked call
 * fails loudly (tests/bootstrap.php).
 */
class BookOnBehalfTest extends LAW_Test_Case {

	private int $flagship = 0;
	private array $mail   = array();
	/** @var callable */
	private $mail_filter;

	protected function setUp(): void {
		parent::setUp();
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
		remove_all_filters( 'law_flagship_event_id' );
		remove_all_filters( 'pre_option_law_events_source' );
		law_flagship_event_id( true );
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
		parent::tearDown();
	}

	/* Fixtures _______________________________________________________________ */

	private function make_flagship( array $meta = array() ): int {
		$event_id = $this->make_event(
			array_merge(
				array(
					'_law_is_flagship'               => 1,
					'_law_flagship_date'             => '2026-12-02',
					'_law_start'                     => '2026-12-02 09:30',
					'_law_end'                       => '2026-12-02 17:00',
					'_law_flagship_price_pence'      => 55000,
					'_law_flagship_price_late_pence' => 60000,
					'_law_flagship_price_switch'     => '2099-10-17 00:00',
					'_law_tickets_available'         => 5,
				),
				$meta
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
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		return $this->make_event(
			array_merge(
				array(
					'_law_is_reception'         => 1,
					'_law_start'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:30' ) ),
					'_law_end'                  => gmdate( 'Y-m-d H:i', strtotime( '+30 days 20:30' ) ),
					'_law_tickets_available'    => 10,
					'_law_attendee_price_pence' => 4500,
					'_law_registration_state'   => 'open',
				),
				$meta
			),
			'publish'
		);
	}

	/** A signed-up member with a filled-in profile: the PA. */
	private function make_booker(): int {
		$user_id = $this->make_user();
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Pat', 'last_name' => 'Assistant', 'display_name' => 'Pat Assistant' ) );
		update_user_meta( $user_id, 'organisation', 'Booker LLP' );
		update_user_meta( $user_id, 'job_title', 'PA' );
		return $user_id;
	}

	private function country(): string {
		$choices = law_registration_country_choices();
		return in_array( 'United Kingdom', $choices, true ) || ! $choices ? 'United Kingdom' : (string) reset( $choices );
	}

	/** The request "Someone else" posts. */
	private function other( array $row = array(), array $profile = array() ): array {
		return array_merge(
			array(
				'law_for'       => 'other',
				'law_attendees' => array(
					array_merge(
						array(
							'name'         => 'Jane Partner',
							'email'        => 'jane.partner.' . strtolower( wp_generate_password( 8, false ) ) . '@example.test',
							'organisation' => 'Partner LLP',
							'job_title'    => 'Partner',
						),
						$row
					),
				),
				'country'       => $this->country(),
				'dietary'       => array( 'Vegetarian' ),
			),
			$profile
		);
	}

	private function queue( array $responses ): void {
		$GLOBALS['law_test_stripe_queue'] = $responses;
		$GLOBALS['law_test_stripe_calls'] = array();
		$GLOBALS['law_test_stripe_idem']  = array();
	}

	/**
	 * A place inserted straight through the engine's own writer, for events
	 * the hosted booking engine refuses (the flagship).
	 */
	private function insert_place( int $event_id, int $user_id, string $status = 'publish', array $meta = array() ): int {
		$id = law_booking_insert( $event_id, $user_id, $status, law_booking_person_from_profile( $user_id ), $meta );
		$this->assertIsInt( $id );
		$this->posts[] = $id;
		return $id;
	}

	/** Register through the handler's own seam, Stripe's setup leg mocked. */
	private function apply_for( int $booker, array $post ) {
		$this->queue(
			array(
				array( 'id' => 'cus_payer', 'object' => 'customer' ),
				array( 'id' => 'cs_test', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_test' ),
			)
		);
		$choice = law_booking_paid_choice_from_request( $booker, $post );
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}
		$result = law_booking_run_for_party(
			$booker,
			$this->flagship,
			$choice,
			law_booking_holding_statuses(),
			fn( $party ) => law_flagship_apply( $booker, array( 'answers' => array(), 'consent' => true, 'terms' => true, 'party' => $party ) )
		);
		if ( ! is_wp_error( $result ) ) {
			$this->posts[] = (int) $result['booking'];
			$this->track_author( (int) $result['booking'] );
		}
		return $result;
	}

	/** Buy a reception place through the seam, Stripe's checkout leg mocked. */
	private function buy_for( int $booker, int $event_id, array $post, int $shown = 5400 ) {
		$this->queue(
			array(
				array( 'id' => 'cus_payer', 'object' => 'customer' ),
				array( 'id' => 'cs_rec', 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/cs_rec', 'expires_at' => time() + 1860 ),
			)
		);
		$choice = law_booking_paid_choice_from_request( $booker, $post );
		if ( is_wp_error( $choice ) ) {
			return $choice;
		}
		$input  = array( 'event_id' => $event_id, 'terms' => 1, 'price_shown' => $shown, 'ajax' => true );
		$result = law_booking_run_for_party(
			$booker,
			$event_id,
			$choice,
			law_booking_holding_statuses(),
			fn( $party ) => law_reception_checkout( $booker, $input + array( 'party' => $party ) )
		);
		if ( ! is_wp_error( $result ) ) {
			$this->posts[] = (int) $result['booking'];
			$this->track_author( (int) $result['booking'] );
		}
		return $result;
	}

	/** A registration whose payment details have been saved: finished, as far as the one-unfinished rule is concerned. */
	private function ready( int $booking_id ): void {
		law_event_update_meta( $booking_id, '_law_payment_status', 'ready' );
	}

	private function track_author( int $booking_id ): void {
		$author = (int) get_post_field( 'post_author', $booking_id );
		if ( $author && ! in_array( $author, $this->users, true ) ) {
			$this->users[] = $author;
		}
	}

	/** The Stripe request bodies made so far, in order. */
	private function stripe_bodies(): array {
		return array_map( fn( $call ) => (array) ( $call['body'] ?? array() ), (array) ( $GLOBALS['law_test_stripe_idem'] ?? array() ) );
	}

	private function mail_to( string $email ): array {
		return array_values(
			array_filter(
				$this->mail,
				fn( $m ) => in_array( $email, (array) $m['to'], true )
			)
		);
	}

	private function log_actions( int $event_id, string $action ): array {
		return array_values(
			array_filter(
				law_event_log_entries( $event_id ),
				fn( $entry ) => $action === ( law_event_log_context( $entry->comment_ID )['action'] ?? '' )
			)
		);
	}

	/* Myself, unchanged _______________________________________________________ */

	public function test_myself_registers_exactly_as_before(): void {
		$this->make_flagship();
		$booker = $this->make_booker();

		$result = $this->apply_for( $booker, array( 'law_for' => 'self' ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$booking = get_post( (int) $result['booking'] );
		$this->assertSame( $booker, (int) $booking->post_author );
		$this->assertSame( $booker, (int) law_event_meta( $booking->ID, '_law_booked_by' ) );
		$this->assertFalse( law_booking_is_on_behalf( $booking ) );
		$this->assertSame( array(), law_event_meta( $booking->ID, '_law_attendee_profile' ) );
		$this->assertSame( array(), $this->log_actions( $this->flagship, 'booked_on_behalf' ) );
		$this->assertSame( $booker, law_booking_payer_id( $booking ) );
	}

	/* Someone else ____________________________________________________________ */

	public function test_someone_else_with_a_new_email_gets_an_account_and_the_place(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];

		$result = $this->apply_for( $booker, $post );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$attendee = get_user_by( 'email', $email );
		$this->assertInstanceOf( WP_User::class, $attendee, 'An account is created for them.' );
		$this->users[] = (int) $attendee->ID;
		$this->assertSame( array( 'subscriber' ), array_values( $attendee->roles ) );
		$this->assertSame( 'Jane', $attendee->first_name );
		$this->assertSame( 'Partner', $attendee->last_name );
		$this->assertNotEmpty( get_user_meta( $attendee->ID, LAW_INVITATION_PENDING_META, true ) );

		$booking = get_post( (int) $result['booking'] );
		$this->assertSame( (int) $attendee->ID, (int) $booking->post_author, 'The attendee holds the place.' );
		$this->assertSame( $booker, (int) law_event_meta( $booking->ID, '_law_booked_by' ), 'The PA booked it.' );
		$this->assertTrue( law_booking_is_on_behalf( $booking ) );
		$this->assertSame( 'Jane Partner', law_event_meta( $booking->ID, '_law_attendee_name' ) );
		$this->assertSame( 'Partner LLP', law_event_meta( $booking->ID, '_law_attendee_organisation' ) );

		$this->assertSame( $this->country(), (string) get_user_meta( $attendee->ID, 'country', true ), 'A new account gets the answers typed for it.' );
		$this->assertSame( $this->country(), law_event_meta( $booking->ID, '_law_attendee_profile' )['country'] ?? '', 'And the booking keeps them.' );
		$this->assertCount( 1, $this->log_actions( $this->flagship, 'booked_on_behalf' ) );
	}

	public function test_an_existing_account_is_matched_and_never_written_to(): void {
		$this->make_flagship();
		$booker   = $this->make_booker();
		$existing = $this->make_user();
		$email    = get_userdata( $existing )->user_email;

		$result = $this->apply_for( $booker, $this->other( array( 'email' => $email ) ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( $existing, (int) get_post_field( 'post_author', (int) $result['booking'] ) );
		$this->assertSame( '', (string) get_user_meta( $existing, 'country', true ), 'Typing someone\'s email never writes into their profile (A3).' );
		$this->assertEmpty( get_user_meta( $existing, LAW_INVITATION_PENDING_META, true ), 'They already have a password.' );
		$this->assertSame( $this->country(), law_event_meta( (int) $result['booking'], '_law_attendee_profile' )['country'] ?? '' );
	}

	public function test_a_committee_members_account_is_matched_without_any_write(): void {
		$this->make_flagship();
		$booker    = $this->make_booker();
		$committee = $this->make_committee_user();

		$result = $this->apply_for( $booker, $this->other( array( 'email' => get_userdata( $committee )->user_email ) ) );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->assertSame( '', (string) get_user_meta( $committee, 'country', true ) );
		$this->assertContains( 'events_committee', get_userdata( $committee )->roles, 'Their role is untouched.' );
	}

	public function test_the_booker_can_hold_their_own_place_and_register_others(): void {
		$this->make_flagship();
		$booker = $this->make_booker();

		$this->assertIsArray( $this->apply_for( $booker, array( 'law_for' => 'self' ) ) );
		$first = $this->apply_for( $booker, $this->other() );
		$this->assertIsArray( $first, 'One application per PERSON, not per booker.' );
		$this->ready( (int) $first['booking'] );
		$this->assertIsArray( $this->apply_for( $booker, $this->other( array( 'name' => 'Sam Senior' ) ) ) );
	}

	/* Refusals ________________________________________________________________ */

	public function test_the_bookers_own_email_is_refused_under_someone_else(): void {
		$this->make_flagship();
		$booker = $this->make_booker();

		$result = $this->apply_for( $booker, $this->other( array( 'email' => get_userdata( $booker )->user_email ) ) );

		$this->assertWPError( $result, 'law_booking_own_email' );
		$this->assertSame( 'email', $result->get_error_data()['field'] );
	}

	public function test_a_missing_country_is_refused_on_its_field(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$post   = $this->other( array(), array( 'country' => '' ) );
		$before = count_users()['total_users'];

		$result = $this->apply_for( $booker, $post );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'country', $result->get_error_data()['field'] );
		$this->assertSame( $before, count_users()['total_users'], 'Nothing was created.' );
	}

	public function test_someone_who_already_has_a_place_is_refused_generically_and_nothing_is_created(): void {
		$this->make_flagship();
		$post  = $this->other();
		$first = $this->apply_for( $this->make_booker(), $post );
		$this->assertIsArray( $first );
		$users = count_users()['total_users'];

		$again = $this->apply_for( $this->make_booker(), $post );

		$this->assertWPError( $again, 'law_booking_duplicate' );
		$this->assertStringContainsString( "We can't register this person", $again->get_error_message() );
		$this->assertStringNotContainsString( 'Jane Partner', $again->get_error_message(), 'It does not confirm who is registered (A8).' );
		$this->assertSame( 'email', $again->get_error_data()['field'] );
		$this->assertSame( $users + 1, count_users()['total_users'], 'Only the second booker was created; no account for Jane twice.' );
	}

	public function test_an_engine_refusal_takes_a_new_account_back_out(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];

		$choice = law_booking_paid_choice_from_request( $booker, $post );
		$result = law_booking_run_for_party(
			$booker,
			$this->flagship,
			$choice,
			law_booking_holding_statuses(),
			fn( $party ) => new WP_Error( 'law_test_refused', 'Refused after the account existed.' )
		);

		$this->assertWPError( $result, 'law_test_refused' );
		$this->assertFalse( get_user_by( 'email', $email ), 'The account it created is gone.' );
	}

	public function test_the_rollback_never_deletes_an_account_somebody_has_booked_onto(): void {
		$event  = $this->make_flagship();
		$booker = $this->make_booker();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];

		$choice = law_booking_paid_choice_from_request( $booker, $post );
		$result = law_booking_run_for_party(
			$booker,
			$event,
			$choice,
			law_booking_holding_statuses(),
			function ( $party ) use ( $event ) {
				// Another request matched the brand-new account and booked
				// onto it while this one was still running.
				$this->insert_place( $event, (int) $party['attendee_id'], 'law-applied' );
				return new WP_Error( 'law_test_refused', 'Refused.' );
			}
		);

		$this->assertWPError( $result, 'law_test_refused' );
		$user = get_user_by( 'email', $email );
		$this->assertInstanceOf( WP_User::class, $user, 'Deleting it would cancel the other booking under account_deleted.' );
		$this->users[] = (int) $user->ID;
	}

	public function test_a_party_naming_another_booker_is_refused(): void {
		$this->make_flagship();
		$booker = $this->make_booker();

		$result = law_flagship_apply(
			$booker,
			array( 'consent' => true, 'terms' => true, 'party' => array( 'booker_id' => $booker + 1000, 'attendee_id' => $booker ) )
		);

		$this->assertWPError( $result, 'law_booking_party_invalid' );
	}

	/* Money ___________________________________________________________________ */

	public function test_the_stripe_customer_and_metadata_are_the_payers(): void {
		$this->make_flagship();
		$booker = $this->make_booker();

		$result = $this->apply_for( $booker, $this->other() );

		$this->assertIsArray( $result );
		$bodies = $this->stripe_bodies();
		$this->assertSame( get_userdata( $booker )->user_email, $bodies[0]['email'] ?? '', 'The customer is created for the PA.' );
		$this->assertSame( 'cus_payer', (string) get_user_meta( $booker, '_law_stripe_customer_id', true ) );
		$meta = law_stripe_booking_metadata( (int) $result['booking'] );
		$this->assertSame( (string) $booker, $meta['law_user_id'] );
		$this->assertSame( (string) get_post_field( 'post_author', (int) $result['booking'] ), $meta['law_attendee_user_id'] );
	}

	public function test_the_invoice_line_names_the_attendee(): void {
		$this->make_flagship();
		$result = $this->apply_for( $this->make_booker(), $this->other() );

		$line = law_stripe_booking_line_description( (int) $result['booking'], array( 'net' => 55000 ) );

		$this->assertStringContainsString( ', for Jane Partner', $line );
	}

	public function test_a_reception_bought_for_someone_else_bills_the_booker(): void {
		$event  = $this->make_reception();
		$booker = $this->make_booker();

		$result = $this->buy_for( $booker, $event, $this->other() );

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$booking = get_post( (int) $result['booking'] );
		$this->assertSame( 'law-pending-payment', $booking->post_status );
		$this->assertSame( $booker, (int) law_event_meta( $booking->ID, '_law_booked_by' ) );
		$this->assertNotSame( $booker, (int) $booking->post_author );
		$bodies = $this->stripe_bodies();
		$this->assertSame( get_userdata( $booker )->user_email, $bodies[0]['email'] ?? '' );
		$this->assertSame( 'cus_payer', $bodies[1]['customer'] ?? '', 'Checkout bills the PA\'s customer.' );
	}

	public function test_a_reception_already_included_with_their_flagship_place_is_refused(): void {
		$flagship  = $this->make_flagship();
		$reception = $this->make_reception( array( '_law_flagship_included' => 1 ) );
		$partner   = $this->make_user();
		$this->insert_place( $flagship, $partner, 'publish', array( '_law_payment_status' => 'paid' ) );

		$result = $this->buy_for( $this->make_booker(), $reception, $this->other( array( 'email' => get_userdata( $partner )->user_email ) ) );

		$this->assertWPError( $result, 'law_booking_duplicate' );
	}

	/* Who may do what _________________________________________________________ */

	public function test_the_payer_rule_across_self_on_behalf_and_substituted_places(): void {
		$this->make_flagship();
		$booker = $this->make_booker();

		$self = (int) $this->apply_for( $booker, array( 'law_for' => 'self' ) )['booking'];
		$this->assertSame( $booker, law_booking_payer_id( $self ) );

		$for      = (int) $this->apply_for( $booker, $this->other() )['booking'];
		$attendee = (int) get_post_field( 'post_author', $for );
		$this->assertSame( $booker, law_booking_booker_id( $for ) );
		$this->assertSame( $booker, law_booking_payer_id( $for ) );

		// A substituted place: the money stayed with whoever paid first.
		update_post_meta( $self, '_law_substituted_from', 424242 );
		$this->assertSame( 424242, law_booking_payer_id( $self ) );

		$this->assertSame( 'attendee', law_booking_user_role( $for, $attendee ) );
		$this->assertSame( 'booker', law_booking_user_role( $for, $booker ) );
		$this->assertSame( '', law_booking_user_role( $for, $this->make_user() ) );
	}

	public function test_payment_actions_and_facts_are_the_bookers_alone(): void {
		$this->make_flagship();
		$booker   = $this->make_booker();
		$for      = (int) $this->apply_for( $booker, $this->other() )['booking'];
		$attendee = (int) get_post_field( 'post_author', $for );

		$this->assertTrue( law_booking_user_manages_payment( $for, $booker ) );
		$this->assertFalse( law_booking_user_manages_payment( $for, $attendee ) );
		$this->assertTrue( law_booking_payment_facts_visible( $for, $booker ) );
		$this->assertFalse( law_booking_payment_facts_visible( $for, $attendee ), 'The partner never sees the PA\'s card or invoice.' );
		$this->assertFalse( law_booking_payment_facts_visible( $for, $this->make_user() ) );

		wp_set_current_user( $attendee );
		$this->assertStringContainsString( 'Pat Assistant manages payment', law_booking_payment_refusal( $for ) );
	}

	/* Emails __________________________________________________________________ */

	public function test_registration_ready_emails_the_booker_and_invites_the_attendee(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];
		$for    = (int) $this->apply_for( $booker, $post )['booking'];
		$this->mail = array();

		law_flagship_mark_ready( $for, 'user_flagship_applied' );

		$to_booker = $this->mail_to( get_userdata( $booker )->user_email );
		$this->assertCount( 1, $to_booker );
		$this->assertStringContainsString( 'Dear Pat Assistant', $to_booker[0]['message'], 'Addressed to the person reading it.' );
		$this->assertStringContainsString( 'You booked this place for Jane Partner (' . $email . ')', $to_booker[0]['message'] );

		$to_attendee = $this->mail_to( $email );
		$this->assertCount( 1, $to_attendee );
		$this->assertStringStartsWith( 'About your place at', $to_attendee[0]['subject'], 'The template\'s own subject, editable on the Emails screen.' );
		$this->assertStringContainsString( 'Pat Assistant has registered you', $to_attendee[0]['message'] );
		$this->assertStringContainsString( 'Set your password', $to_attendee[0]['message'], 'The invitation.' );
		$this->assertStringNotContainsString( 'payment method', $to_attendee[0]['message'] );
	}

	public function test_the_lead_line_survives_an_edited_template_and_never_expands_a_tag(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		update_option(
			LAW_EVENTS_EMAIL_OVERRIDES_OPTION,
			array( 'user_flagship_applied' => array( 'body' => 'Dear {attendee_name}, the client wrote this. {invoice_link}' ) )
		);
		$for = (int) $this->apply_for( $booker, $this->other( array( 'name' => 'Jo {invoice_link} Smith' ) ) )['booking'];
		law_event_update_meta( $for, '_law_stripe_invoice_url', 'https://invoice.stripe.test/secret' );
		$this->mail = array();

		law_flagship_mark_ready( $for, 'user_flagship_applied' );

		$message = $this->mail_to( get_userdata( $booker )->user_email )[0]['message'];
		$this->assertStringContainsString( 'the client wrote this', $message );
		$this->assertStringContainsString( 'You booked this place for Jo {invoice_link} Smith', $message, 'Printed, not expanded.' );
	}

	public function test_approval_charges_the_booker_and_confirms_the_attendee_without_the_money(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];
		$for    = (int) $this->apply_for( $booker, $post )['booking'];
		update_user_meta( $booker, '_law_stripe_customer_id', 'cus_payer' );
		law_event_update_meta( $for, '_law_stripe_payment_method_id', 'pm_payer' );
		law_event_update_meta( $for, '_law_payment_status', 'ready' );
		update_post_meta( $for, '_law_application_ready', 1 );
		$this->queue(
			array(
				array( 'id' => 'in_test', 'object' => 'invoice' ),
				array( 'id' => 'ii_test', 'object' => 'invoiceitem' ),
				array( 'id' => 'in_test', 'status' => 'open' ),
				array( 'id' => 'in_test', 'status' => 'paid', 'amount_paid' => 66000, 'hosted_invoice_url' => 'https://invoice.stripe.test/in_test', 'invoice_pdf' => 'https://invoice.stripe.test/in_test.pdf' ),
				array( 'id' => 'in_test', 'payments' => array( 'data' => array() ) ),
			)
		);
		$this->mail = array();

		$approved = law_flagship_approve( $for, 0 );

		$this->assertIsArray( $approved, is_wp_error( $approved ) ? $approved->get_error_message() : '' );
		$this->assertSame( 'cus_payer', $this->stripe_bodies()[0]['customer'] ?? '', 'The invoice is the PA\'s.' );

		$to_booker = $this->mail_to( get_userdata( $booker )->user_email );
		$this->assertCount( 1, $to_booker );
		$this->assertSame( array(), array_filter( (array) $to_booker[0]['attachments'] ), 'No calendar file in the PA\'s diary.' );
		$this->assertStringContainsString( 'We have sent them their own confirmation', $to_booker[0]['message'] );

		$to_attendee = $this->mail_to( $email );
		$this->assertCount( 1, $to_attendee );
		$this->assertNotEmpty( array_filter( (array) $to_attendee[0]['attachments'] ), 'The attendee gets the calendar invitation.' );
		$this->assertStringContainsString( 'Pat Assistant booked and paid for this place for you', $to_attendee[0]['message'] );
		$this->assertStringNotContainsString( 'invoice.stripe.test', $to_attendee[0]['message'], 'Nothing about the PA\'s money.' );
	}

	public function test_a_reception_confirmation_reaches_both(): void {
		$event  = $this->make_reception();
		$booker = $this->make_booker();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];
		$for    = (int) $this->buy_for( $booker, $event, $post )['booking'];
		$this->mail = array();

		// The charge lookup law_flagship_store_charge_id() makes on an invoice.
		$this->queue( array( array( 'id' => 'in_rec', 'payments' => array( 'data' => array() ) ) ) );
		$this->assertTrue(
			law_reception_mark_paid(
				$for,
				array( 'id' => 'in_rec', 'object' => 'invoice', 'amount_paid' => 5400, 'hosted_invoice_url' => 'https://invoice.stripe.test/in_rec' ),
				'',
				0
			)
		);

		$this->assertCount( 1, $this->mail_to( get_userdata( $booker )->user_email ) );
		$to_attendee = $this->mail_to( $email );
		$this->assertCount( 1, $to_attendee );
		$this->assertStringStartsWith( 'About your place at', $to_attendee[0]['subject'] );
		$this->assertStringContainsString( 'booked and paid for a place for you', $to_attendee[0]['message'] );
		$this->assertStringNotContainsString( 'in_rec', $to_attendee[0]['message'] );
	}

	public function test_a_self_booking_is_emailed_exactly_as_before(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$self   = (int) $this->apply_for( $booker, array( 'law_for' => 'self' ) )['booking'];
		$this->mail = array();

		law_flagship_mark_ready( $self, 'user_flagship_applied' );

		$to_booker = $this->mail_to( get_userdata( $booker )->user_email );
		$this->assertCount( 1, $to_booker );
		$this->assertStringNotContainsString( 'You booked this place for', $to_booker[0]['message'] );
		$this->assertStringContainsString( 'Dear Pat Assistant', $to_booker[0]['message'] );
	}

	public function test_withdrawal_by_the_attendee_tells_the_booker_who_did_it(): void {
		$this->make_flagship();
		$booker   = $this->make_booker();
		$post     = $this->other();
		$email    = $post['law_attendees'][0]['email'];
		$for      = (int) $this->apply_for( $booker, $post )['booking'];
		$attendee = (int) get_post_field( 'post_author', $for );
		$this->queue( array() );
		$this->mail = array();

		$this->assertTrue( law_flagship_withdraw( $for, $attendee ) );

		$this->assertStringContainsString( 'has withdrawn the registration you made for them', $this->mail_to( get_userdata( $booker )->user_email )[0]['message'] );
		$this->assertStringContainsString( 'This confirms that you have withdrawn your registration', $this->mail_to( $email )[0]['message'] );
	}


	/* The dialogs _____________________________________________________________ */

	private function render( string $part, array $args ): string {
		ob_start();
		get_template_part( $part, null, $args );
		return (string) ob_get_clean();
	}

	private function flagship_event_array( int $event_id ): array {
		return array( 'id' => $event_id, 'title' => get_the_title( $event_id ), 'date' => '2026-12-02', 'time_label' => '' );
	}

	public function test_the_flagship_dialog_asks_who_the_place_is_for(): void {
		$event = $this->make_flagship();
		wp_set_current_user( $this->make_booker() );

		$modal  = $this->render( 'parts/events/flagship-apply-modal', array( 'event' => $this->flagship_event_array( $event ), 'context' => 'modal' ) );
		$inline = $this->render( 'parts/events/flagship-apply-modal', array( 'event' => $this->flagship_event_array( $event ), 'context' => 'inline' ) );

		$this->assertStringContainsString( 'Who is this place for?', $modal );
		$this->assertMatchesRegularExpression( '/name="law_for" value="self"\s+checked/', $modal, 'Myself by default.' );
		$this->assertStringContainsString( 'name="law_attendees[0][email]"', $modal );
		$this->assertMatchesRegularExpression( '/id="law-fa-for-modal-details" data-law-toggle-disable hidden/', $modal, 'Closed in a fetched dialog until chosen.' );
		$this->assertMatchesRegularExpression( '/id="law-fa-for-inline-details" data-law-toggle-disable>/', $inline, 'Open without a script, which closes it.' );
		$this->assertStringContainsString( 'Only needed if you chose Someone else.', $inline );
		$this->assertTrue( strpos( $modal, 'law-booking-for' ) < strpos( $modal, 'law-booking-price' ), 'Before the price.' );
	}

	public function test_a_delegate_with_their_own_registration_gets_myself_disabled(): void {
		$event  = $this->make_flagship();
		$booker = $this->make_booker();
		$this->insert_place( $event, $booker, 'law-applied' );
		wp_set_current_user( $booker );

		$html = $this->render( 'parts/events/flagship-apply-modal', array( 'event' => $this->flagship_event_array( $event ), 'context' => 'modal' ) );

		$this->assertMatchesRegularExpression( '/value="self"\s+disabled=/', $html );
		$this->assertMatchesRegularExpression( '/value="other" data-law-toggles="law-fa-for-modal-details"\s+checked=/', $html );
		$this->assertStringContainsString( 'You already have a place, so this one is for someone else.', $html );
		$this->assertDoesNotMatchRegularExpression( '/id="law-fa-for-modal-details"[^>]*hidden/', $html, 'Their details are open.' );
	}

	public function test_the_reception_dialog_asks_too(): void {
		$event = $this->make_reception();
		wp_set_current_user( $this->make_booker() );

		$html = $this->render(
			'parts/events/reception-checkout-modal',
			array( 'event' => array( 'id' => $event, 'title' => get_the_title( $event ) ), 'context' => 'modal', 'mode' => 'checkout' )
		);

		$this->assertStringContainsString( 'Who is this place for?', $html );
		$this->assertStringContainsString( 'id="law-rc-for-modal-details"', $html );
	}

	public function test_the_free_reception_dialog_asks_too(): void {
		$event = $this->make_reception( array( '_law_attendee_price_pence' => 0 ) );
		wp_set_current_user( $this->make_booker() );

		$html = $this->render(
			'parts/events/reception-checkout-modal',
			array( 'event' => array( 'id' => $event, 'title' => get_the_title( $event ) ), 'context' => 'modal', 'mode' => 'checkout' )
		);

		$this->assertStringContainsString( 'Free to attend.', $html );
		$this->assertStringContainsString( 'Who is this place for?', $html );
	}

	public function test_on_behalf_forms_read_the_accessibility_choices_neutrally(): void {
		$html = $this->render( 'parts/events/attendee-profile-fields', array( 'id_prefix' => 'law-test' ) );

		$this->assertStringContainsString( 'Wheelchair access', $html );
		$this->assertStringNotContainsString( 'I require', $html, 'Not true of the person ticking it.' );
		$this->assertSame( 'I require wheelchair access', law_registration_accessibility_choices()['Wheelchair'], 'Registration keeps its own words.' );
	}

	/* After booking ___________________________________________________________ */

	public function test_my_bookings_gives_each_place_booked_for_someone_its_own_card(): void {
		$event  = $this->make_flagship();
		$booker = $this->make_booker();
		$own    = (int) $this->apply_for( $booker, array( 'law_for' => 'self' ) )['booking'];
		$first  = (int) $this->apply_for( $booker, $this->other() )['booking'];
		$this->ready( $first );
		$second = (int) $this->apply_for( $booker, $this->other( array( 'name' => 'Sam Senior' ) ) )['booking'];
		wp_set_current_user( $booker );

		$cards = array_values( array_filter( law_account_bookings(), fn( $c ) => (int) $c['event']['id'] === $event ) );

		$this->assertEqualsCanonicalizing( array( $own, $first, $second ), array_map( fn( $c ) => (int) $c['manage_id'], $cards ), 'One card, and one Manage link, per place.' );
		$this->assertEqualsCanonicalizing( array( '', 'Jane Partner', 'Sam Senior' ), array_map( fn( $c ) => (string) $c['for'], $cards ) );
	}

	public function test_the_booker_sees_what_they_typed_and_never_the_attendees_profile(): void {
		$this->make_flagship();
		$booker   = $this->make_booker();
		$partner  = $this->make_user();
		update_user_meta( $partner, 'job_title', 'A private job title' );
		$for      = (int) $this->apply_for( $booker, $this->other( array( 'email' => get_userdata( $partner )->user_email ) ) )['booking'];

		wp_set_current_user( $booker );
		$as_booker = $this->render( 'parts/events/flagship-manage-application', array( 'booking_id' => $for ) );
		$this->assertStringContainsString( 'For Jane Partner', $as_booker );
		$this->assertStringContainsString( 'Their details', $as_booker );
		$this->assertStringNotContainsString( 'A private job title', $as_booker );
		$this->assertStringNotContainsString( 'Update my profile', $as_booker );

		wp_set_current_user( $partner );
		$as_attendee = $this->render( 'parts/events/flagship-manage-application', array( 'booking_id' => $for ) );
		$this->assertStringContainsString( 'Booked by Pat Assistant, who manages the payment.', $as_attendee, 'Not "paid for" before anyone has paid.' );
		$this->assertStringContainsString( 'Update my profile', $as_attendee );

		wp_set_current_user( $this->make_user() );
		$this->assertStringContainsString( 'not yours to view', $this->render( 'parts/events/flagship-manage-application', array( 'booking_id' => $for ) ) );
	}

	public function test_the_attendee_sees_the_payment_control_disabled_with_the_reason(): void {
		$this->make_flagship();
		$booker   = $this->make_booker();
		$for      = (int) $this->apply_for( $booker, $this->other() )['booking'];
		$attendee = (int) get_post_field( 'post_author', $for );

		wp_set_current_user( $attendee );
		$html = $this->render( 'parts/events/flagship-card-form', array( 'booking_id' => $for, 'label' => 'Update payment method' ) );

		$this->assertStringContainsString( 'disabled', $html );
		$this->assertStringContainsString( 'Pat Assistant manages payment for this place.', $html );
		$this->assertStringNotContainsString( 'law_booking_update_card', $html, 'No form to post.' );

		wp_set_current_user( $booker );
		$this->assertStringContainsString( 'law_booking_update_card', $this->render( 'parts/events/flagship-card-form', array( 'booking_id' => $for ) ) );
	}

	public function test_lists_fill_a_profiles_blanks_from_the_booking(): void {
		$this->make_flagship();
		$partner = $this->make_user();
		update_user_meta( $partner, 'country', 'France' );
		$for = (int) $this->apply_for( $this->make_booker(), $this->other( array( 'email' => get_userdata( $partner )->user_email ), array( 'dietary' => array( 'Vegan' ) ) ) )['booking'];

		$profile = law_booking_profile_values( $for );

		$this->assertSame( 'France', $profile['country'], 'Their own answer wins.' );
		$this->assertSame( array( 'Vegan' ), (array) $profile['dietary'], 'A blank is filled from what was typed for them.' );
	}

	public function test_the_flagship_export_names_who_booked(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$for    = (int) $this->apply_for( $booker, $this->other() )['booking'];
		// Out of the committee's queue until a card is saved.
		law_event_update_meta( $for, '_law_payment_status', 'ready' );

		$export = law_flagship_bookings_export_rows( array() );
		$column = array_search( 'Booked by', $export['columns'], true );

		$this->assertNotFalse( $column );
		$values = array_column( $export['rows'], $column );
		$this->assertContains( 'Pat Assistant (' . get_userdata( $booker )->user_email . ')', $values );
	}

	public function test_the_notice_after_stripe_names_the_attendee_for_the_booker_only(): void {
		$this->make_flagship();
		$booker   = $this->make_booker();
		$for      = (int) $this->apply_for( $booker, $this->other() )['booking'];
		$attendee = (int) get_post_field( 'post_author', $for );

		wp_set_current_user( $booker );
		$this->assertSame( ' This place is for Jane Partner.', law_booking_on_behalf_notice( $for ) );
		wp_set_current_user( $attendee );
		$this->assertSame( '', law_booking_on_behalf_notice( $for ) );
	}

	/* Substitution ____________________________________________________________ */

	public function test_substituting_a_place_booked_for_someone_keeps_the_booker_and_the_receipt(): void {
		$this->make_flagship();
		$booker   = $this->make_booker();
		$post     = $this->other();
		$jane     = $post['law_attendees'][0]['email'];
		$for      = (int) $this->apply_for( $booker, $post )['booking'];
		law_event_update_meta( $for, '_law_stripe_invoice_url', 'https://invoice.stripe.test/payer' );
		law_event_update_meta( $for, '_law_payment_status', 'paid' );
		law_event_update_meta( $for, '_law_price_pence', 55000 );
		law_booking_set_status( $for, 'publish' );
		$this->mail = array();

		$result = law_flagship_substitute(
			$for,
			array( 'name' => 'Max Substitute', 'email' => 'max.sub.' . strtolower( wp_generate_password( 6, false ) ) . '@example.test', 'organisation' => 'Partner LLP', 'job_title' => 'Associate', 'profile' => law_registration_clean_attendee_profile( array( 'country' => $this->country() ) ) ),
			0
		);

		$this->assertNotInstanceOf( WP_Error::class, $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
		$this->track_author( $for );
		$this->assertSame( $booker, (int) law_event_meta( $for, '_law_booked_by' ), 'The PA still books and pays for it.' );
		$this->assertSame( $booker, law_booking_payer_id( $for ) );
		$this->assertSame( '', (string) get_post_meta( $for, '_law_substituted_from', true ), 'Jane never paid, so she is not recorded as the payer.' );

		$to_jane = $this->mail_to( $jane );
		$this->assertCount( 1, $to_jane );
		$this->assertStringNotContainsString( 'invoice.stripe.test/payer', $to_jane[0]['message'], 'The receipt is not Jane\'s.' );
		$to_booker = $this->mail_to( get_userdata( $booker )->user_email );
		$this->assertCount( 1, $to_booker, 'The PA is told, with the receipt.' );
		$this->assertStringContainsString( 'The committee has transferred it to Max Substitute', $to_booker[0]['message'] );
	}

	/* A deleted booker (A7) ___________________________________________________ */

	public function test_deleting_a_booker_withdraws_their_unpaid_bookings_for_others(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];
		$for    = (int) $this->apply_for( $booker, $post )['booking'];
		law_event_update_meta( $for, '_law_payment_status', 'ready' );
		$this->queue( array() );
		$this->mail = array();

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $booker );

		$this->assertSame( 'law-cancelled', get_post_status( $for ) );
		$this->assertCount( 1, $this->log_actions( $this->flagship, 'booker_deleted' ) );
		$to_attendee = $this->mail_to( $email );
		$this->assertCount( 1, $to_attendee );
		$this->assertStringContainsString( 'account of the person who made it has been closed', $to_attendee[0]['message'] );
	}

	public function test_deleting_a_booker_leaves_a_paid_place_alone(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$for    = (int) $this->apply_for( $booker, $this->other() )['booking'];
		law_event_update_meta( $for, '_law_payment_status', 'paid' );
		law_booking_set_status( $for, 'publish' );

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $booker );

		$this->assertSame( 'publish', get_post_status( $for ) );
	}


	/* Review fixes (26 October 2026) __________________________________________ */

	public function test_one_unfinished_booking_for_someone_else_at_a_time(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$this->assertIsArray( $this->apply_for( $booker, $this->other() ), 'Waiting for its card.' );

		$second = $this->apply_for( $booker, $this->other( array( 'name' => 'Sam Senior' ) ) );

		$this->assertWPError( $second, 'law_booking_on_behalf_unfinished' );
		$this->assertIsArray( $this->apply_for( $booker, array( 'law_for' => 'self' ) ), 'Their own registration is not held up.' );
	}

	public function test_a_free_reception_can_be_booked_for_someone_else(): void {
		$event  = $this->make_reception( array( '_law_attendee_price_pence' => 0 ) );
		$booker = $this->make_booker();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];
		$this->mail = array();

		$for = (int) $this->buy_for( $booker, $event, $post, 0 )['booking'];

		$this->assertSame( 'publish', get_post_status( $for ), 'Nothing to pay, so confirmed straight away.' );
		$this->assertSame( $booker, law_booking_booker_id( $for ) );
		$this->assertNotSame( $booker, (int) get_post_field( 'post_author', $for ) );
		$this->assertCount( 1, $this->mail_to( get_userdata( $booker )->user_email ) );
		$to_attendee = $this->mail_to( $email );
		$this->assertCount( 1, $to_attendee );
		$this->assertStringContainsString( 'There is nothing to pay', $to_attendee[0]['message'] );
		$this->assertSame( 'Booked by Pat Assistant. There is nothing to pay.', law_booking_payer_note( $for ) );
		$this->assertSame( 'Booked by Pat Assistant', law_booking_booked_by_label( $for ) );
	}

	public function test_a_free_reception_caps_places_for_someone_else(): void {
		$event  = $this->make_reception( array( '_law_attendee_price_pence' => 0 ) );
		$booker = $this->make_booker();
		for ( $i = 0; $i < law_booking_max_additional(); $i++ ) {
			$this->assertIsArray( $this->buy_for( $booker, $event, $this->other(), 0 ) );
		}
		$post = $this->other();

		$this->assertWPError( $this->buy_for( $booker, $event, $post, 0 ), 'law_booking_on_behalf_cap' );
		$this->assertFalse( get_user_by( 'email', $post['law_attendees'][0]['email'] ), 'The refused person\'s account is taken back out.' );
		$this->assertIsArray( $this->buy_for( $booker, $event, array( 'law_for' => 'self' ), 0 ), 'Their own place is not held up.' );
	}

	/** A free reception where the booker has booked every place they may for others. */
	private function free_reception_at_the_cap( int $booker ): int {
		$event = $this->make_reception( array( '_law_attendee_price_pence' => 0 ) );
		for ( $i = 0; $i < law_booking_max_additional(); $i++ ) {
			$this->buy_for( $booker, $event, $this->other(), 0 );
		}
		return $event;
	}

	private function reception_dialog( int $event ): string {
		return $this->render(
			'parts/events/reception-checkout-modal',
			array( 'event' => array( 'id' => $event, 'title' => get_the_title( $event ) ), 'context' => 'modal', 'mode' => 'checkout' )
		);
	}

	public function test_the_dialog_greys_out_someone_else_once_the_cap_is_reached(): void {
		$booker = $this->make_booker();
		$event  = $this->free_reception_at_the_cap( $booker );
		wp_set_current_user( $booker );

		$html = $this->reception_dialog( $event );

		$this->assertMatchesRegularExpression( '/value="other"[^>]*disabled=/', $html, 'Shown, disabled, before anything is typed.' );
		$this->assertMatchesRegularExpression( '/value="other"[^>]*aria-describedby="law-rc-for-modal-full"/', $html );
		$this->assertStringContainsString( "You&#039;ve booked places for 3 other people here, which is the most allowed.", $html );
		$this->assertMatchesRegularExpression( '/value="self"[^>]*checked=/', $html, 'Their own place is still on offer.' );
		$this->assertDoesNotMatchRegularExpression( '/value="self"[^>]*disabled=/', $html );
		$this->assertMatchesRegularExpression( '/id="law-rc-for-modal-details"[^>]*hidden[^>]*disabled=/', $html, 'Details nobody can send stay closed.' );
		$this->assertDoesNotMatchRegularExpression( '/type="submit"[^>]*disabled=/', $html );
	}

	public function test_with_nothing_left_to_book_the_dialog_and_the_panel_say_so(): void {
		$booker = $this->make_booker();
		$event  = $this->free_reception_at_the_cap( $booker );
		$this->assertIsArray( $this->buy_for( $booker, $event, array( 'law_for' => 'self' ), 0 ) );
		wp_set_current_user( $booker );

		$html = $this->reception_dialog( $event );
		$this->assertMatchesRegularExpression( '/value="self"[^>]*disabled=/', $html );
		$this->assertMatchesRegularExpression( '/value="other"[^>]*disabled=/', $html );
		$this->assertStringContainsString( 'You already have a place here.', $html );
		$this->assertMatchesRegularExpression( '/type="submit"[^>]*disabled=/s', $html );

		ob_start();
		$parts = law_booking_render_action_body( law_booking_state( $event ), array( 'id' => $event ) );
		$words = (string) ob_get_clean();
		$this->assertStringContainsString( 'which is the most allowed', $words );
		$this->assertStringContainsString( '<button type="button" class="button orange" disabled aria-disabled="true">Register</button>', $parts['action'] );
		$this->assertStringContainsString( 'class="button second"', $parts['action'], 'Manage beside it, as the secondary button.' );
	}

	public function test_a_priced_reception_has_no_cap(): void {
		$event  = $this->make_reception();
		$booker = $this->make_booker();
		for ( $i = 0; $i <= law_booking_max_additional(); $i++ ) {
			$this->posts[] = (int) law_booking_insert( $event, $this->make_user(), 'publish', array( 'name' => 'Guest ' . $i, 'email' => 'guest' . $i . '@example.test' ), array(), $booker );
		}

		$this->assertTrue( law_reception_on_behalf_free_cap( $booker, $event ) );
	}

	public function test_deleting_a_booker_leaves_a_free_waitlist_entry_alone(): void {
		$event  = $this->make_reception( array( '_law_attendee_price_pence' => 0 ) );
		$booker = $this->make_booker();
		$entry  = (int) law_booking_insert( $event, $this->make_user(), 'law-waitlisted', array( 'name' => 'Jane Partner', 'email' => 'jane.waiting@example.test' ), array( '_law_payment_status' => 'no_charge' ), $booker );
		$this->posts[] = $entry;

		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $booker );

		$this->assertSame( 'law-waitlisted', get_post_status( $entry ), 'Nothing to pay, so not an unpaid booking (A7).' );
	}

	public function test_the_included_reception_refusal_reads_exactly_like_a_held_place(): void {
		$flagship  = $this->make_flagship();
		$reception = $this->make_reception( array( '_law_flagship_included' => 1 ) );
		$partner   = $this->make_user();
		$this->insert_place( $flagship, $partner, 'publish', array( '_law_payment_status' => 'paid' ) );

		$result = $this->buy_for( $this->make_booker(), $reception, $this->other( array( 'email' => get_userdata( $partner )->user_email ) ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( law_booking_attendee_taken_error()->get_error_message(), $result->get_error_message(), 'Nothing that says they hold a flagship place.' );
	}

	public function test_a_lapsed_hold_takes_back_the_account_it_created(): void {
		$event  = $this->make_reception();
		$post   = $this->other();
		$email  = $post['law_attendees'][0]['email'];
		$for    = (int) $this->buy_for( $this->make_booker(), $event, $post )['booking'];
		$this->assertInstanceOf( WP_User::class, get_user_by( 'email', $email ) );
		$this->queue( array( array( 'id' => 'cs_rec', 'status' => 'expired' ) ) );

		law_reception_release_hold( $for, 'expired' );

		$this->assertFalse( get_user_by( 'email', $email ), 'Never emailed, never used: the account goes with the hold.' );
	}

	public function test_a_lapsed_hold_keeps_an_account_that_was_already_there(): void {
		$event    = $this->make_reception();
		$existing = $this->make_user();
		$for      = (int) $this->buy_for( $this->make_booker(), $event, $this->other( array( 'email' => get_userdata( $existing )->user_email ) ) )['booking'];
		$this->queue( array( array( 'id' => 'cs_rec', 'status' => 'expired' ) ) );

		law_reception_release_hold( $for, 'expired' );

		$this->assertInstanceOf( WP_User::class, get_userdata( $existing ) );
	}

	public function test_typed_details_refuse_links_and_long_values(): void {
		$this->make_flagship();
		$booker = $this->make_booker();

		$link = $this->apply_for( $booker, $this->other( array( 'name' => 'https://evil.example/x' ) ) );
		$long = $this->apply_for( $booker, $this->other( array( 'organisation' => str_repeat( 'a', 101 ) ) ) );

		$this->assertWPError( $link, 'law_booking_invalid_row' );
		$this->assertSame( 'name', $link->get_error_data()['field'] );
		$this->assertSame( 'organisation', $long->get_error_data()['field'] );
	}

	public function test_a_missing_country_speaks_about_them(): void {
		$this->make_flagship();
		$result = $this->apply_for( $this->make_booker(), $this->other( array(), array( 'country' => '' ) ) );

		$this->assertSame( 'Please choose their country of residence.', $result->get_error_message() );
	}

	public function test_the_booker_hears_when_the_committee_cancels_a_place_they_paid_for(): void {
		$event  = $this->make_reception();
		$booker = $this->make_booker();
		$post   = $this->other();
		$for    = (int) $this->buy_for( $booker, $event, $post )['booking'];
		$this->queue( array( array( 'id' => 'in_rec', 'payments' => array( 'data' => array() ) ) ) );
		law_reception_mark_paid( $for, array( 'id' => 'in_rec', 'object' => 'invoice', 'amount_paid' => 5400, 'hosted_invoice_url' => 'https://invoice.stripe.test/in_rec' ), '', 0 );
		$this->mail = array();

		law_booking_cancel( $for, $this->make_committee_user(), 'host_reject' );

		$this->assertCount( 1, $this->mail_to( $post['law_attendees'][0]['email'] ) );
		$to_booker = $this->mail_to( get_userdata( $booker )->user_email );
		$this->assertCount( 1, $to_booker );
		$this->assertStringContainsString( 'This is about the place you booked for Jane Partner', $to_booker[0]['message'] );
	}

	public function test_one_label_for_who_booked(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$for    = (int) $this->apply_for( $booker, $this->other() )['booking'];
		$self   = (int) $this->apply_for( $booker, array( 'law_for' => 'self' ) )['booking'];

		$this->assertSame( 'Booked by Pat Assistant', law_booking_booked_by_label( $for ), 'Nobody has paid yet.' );
		$this->assertSame( '', law_booking_booked_by_label( $self ) );

		law_event_update_meta( $for, '_law_payment_status', 'paid' );
		$this->assertSame( 'Booked and paid for by Pat Assistant', law_booking_booked_by_label( $for ) );
	}

	public function test_a_complimentary_place_for_a_new_person_carries_the_set_password_link(): void {
		$this->make_flagship();
		$email = 'comp.' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$this->mail = array();

		$booking = law_flagship_add_complimentary(
			array( 'name' => 'Cora Comp', 'email' => $email, 'organisation' => '', 'job_title' => '' ),
			$this->make_committee_user()
		);

		$this->assertIsInt( $booking, is_wp_error( $booking ) ? $booking->get_error_message() : '' );
		$this->posts[] = $booking;
		$this->track_author( $booking );
		$this->assertStringContainsString( 'Set your password', $this->mail_to( $email )[0]['message'] ?? '' );
	}

	public function test_substitution_replaces_the_previous_attendees_answers_and_names_the_buyer(): void {
		$this->make_flagship();
		$booker = $this->make_booker();
		$for    = (int) $this->apply_for( $booker, $this->other( array(), array( 'accessibility' => array( 'Wheelchair' ) ) ) )['booking'];
		law_event_update_meta( $for, '_law_payment_status', 'paid' );
		law_event_update_meta( $for, '_law_price_pence', 55000 );
		law_booking_set_status( $for, 'publish' );
		$max = 'max.' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
		$this->mail = array();

		law_flagship_substitute(
			$for,
			array( 'name' => 'Max Substitute', 'email' => $max, 'organisation' => 'Partner LLP', 'job_title' => 'Associate', 'profile' => law_registration_clean_attendee_profile( array( 'country' => $this->country(), 'dietary' => array( 'Vegan' ) ) ) ),
			0
		);
		$this->track_author( $for );

		$stored = law_event_meta( $for, '_law_attendee_profile' );
		$this->assertSame( array(), (array) ( $stored['accessibility'] ?? array() ), 'Jane\'s answers are gone.' );
		$this->assertSame( array( 'Vegan' ), (array) ( $stored['dietary'] ?? array() ), 'Max\'s are there.' );
		$this->assertSame( 'Jane Partner', (string) get_post_meta( $for, '_law_substituted_from_name', true ), 'The committee can see whose it was.' );
		$this->assertSame( '', (string) get_post_meta( $for, '_law_substituted_from', true ), 'But not as the payer.' );
		$this->assertStringContainsString( 'Pat Assistant booked and paid for it', $this->mail_to( $max )[0]['message'] ?? '' );
	}


	public function test_a_refused_no_js_form_is_refilled_for_its_own_event_only(): void {
		$flagship  = $this->make_flagship();
		$reception = $this->make_reception();
		wp_set_current_user( $this->make_booker() );
		$_POST = $this->other( array( 'name' => 'Refill Person' ), array( 'dietary' => array( 'Vegan' ) ) );

		law_booking_store_paid_choice_state( new WP_Error( 'law_x', 'Refused.', array( 'field' => 'email' ) ), $flagship );

		$this->assertSame( array(), law_booking_paid_choice_state( $reception ), 'Never prefills another event\'s form.' );
		$state = law_booking_paid_choice_state( $flagship );
		$this->assertSame( 'other', $state['for'] );
		$this->assertSame( 'Refill Person', $state['values']['name'] );
		$this->assertSame( array( 'Vegan' ), (array) $state['profile']['dietary'] );
		$this->assertSame( 'email', $state['field'] );
		$this->assertSame( array(), law_booking_paid_choice_state( $flagship ), 'Read once.' );
		$this->assertFalse( get_transient( 'job_title' ), 'Nothing stored under a stray shared key.' );
	}
}
