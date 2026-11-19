<?php
/**
 * functions/hubspot/rules.php (HUBSPOT_SYNC.md §5, §9 HubSpotRulesTest and
 * HubSpotMergeTest): the desired state for one address, and the add-only /
 * upgrade-only merges with what HubSpot already holds. No API: the rules read
 * WordPress and return arrays; the merges are pure functions.
 */

class HubSpotRulesTest extends LAW_Test_Case {

	private array $config;

	protected function setUp(): void {
		parent::setUp();
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'year' => 2026 ) );
		add_filter( 'law_hubspot_mode', array( $this, 'dry_mode' ) );
		$this->config = law_hubspot_config();
		if ( function_exists( 'law_speakers_flush_maps' ) ) {
			law_speakers_flush_maps();
		}
	}

	protected function tearDown(): void {
		remove_filter( 'law_hubspot_mode', array( $this, 'dry_mode' ) );
		remove_all_filters( 'pre_option_law_events_source' );
		remove_all_filters( 'law_hubspot_config' );
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		parent::tearDown();
	}

	public function dry_mode() {
		return 'dry';
	}

	/* ---- fixtures -------------------------------------------------------- */

	/** An account with the profile fields the registration form writes. */
	private function make_person( array $profile = array() ): array {
		$user_id = $this->make_user();
		$profile = array_merge(
			array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'organisation' => 'Analytical Engines', 'job_title' => 'Arbitrator', 'country' => 'United Kingdom' ),
			$profile
		);
		wp_update_user( array( 'ID' => $user_id, 'first_name' => $profile['first_name'], 'last_name' => $profile['last_name'] ) );
		foreach ( array( 'organisation', 'job_title', 'country' ) as $key ) {
			update_user_meta( $user_id, $key, $profile[ $key ] );
		}
		$user = get_user_by( 'id', $user_id );
		return array( $user_id, law_hubspot_normalise_email( $user->user_email ) );
	}

	/** A published, bookable, free event. */
	private function make_bookable( array $meta = array(), $author = 0 ): int {
		return $this->make_event(
			array_merge(
				array(
					'_law_tickets_available'  => 10,
					'_law_start'              => gmdate( 'Y-m-d H:i', strtotime( '+30 days 18:00' ) ),
					'_law_end'                => gmdate( 'Y-m-d H:i', strtotime( '+30 days 20:00' ) ),
					'_law_registration_state' => 'open',
				),
				$meta
			),
			'publish',
			$author
		);
	}

	/**
	 * A confirmed booking written straight to the posts table: the engine
	 * routes a priced reception through Stripe, which these tests are not
	 * about. The rules read post_type, post_status, post_author, post_parent
	 * and _law_is_press, which is what this writes.
	 */
	private function insert_booking( int $event_id, int $user_id, string $status = 'publish', bool $press = false ): int {
		$booking_id = wp_insert_post(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_status' => $status,
				'post_title'  => 'Test booking',
				'post_author' => $user_id,
				'post_parent' => $event_id,
			)
		);
		$this->posts[] = $booking_id;
		if ( $press ) {
			law_event_update_meta( $booking_id, '_law_is_press', 1 );
		}
		return (int) $booking_id;
	}

	/**
	 * A published reception whose slug the config maps to a tag. The real
	 * local site already holds opening-drinks and friends, so a fixture with
	 * that slug would be renamed -2 by WordPress; instead the fixture gets a
	 * unique slug and the config filter maps it.
	 */
	private function make_reception_tagged( string $tag ): int {
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		$reception = $this->make_bookable( array( '_law_is_reception' => 1 ) );
		$slug      = 'test-reception-' . strtolower( wp_generate_password( 6, false ) );
		wp_update_post( array( 'ID' => $reception, 'post_name' => $slug ) );
		add_filter(
			'law_hubspot_config',
			function ( array $config ) use ( $slug, $tag ) {
				$config['reception_tags'][ $slug ] = $tag;
				return $config;
			}
		);
		return $reception;
	}

	private function unique_email( string $prefix ): string {
		return $prefix . '-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test';
	}

	private function tags( array $state ): array {
		$tags = $state['contact_type_add'];
		sort( $tags );
		return $tags;
	}

	/* ---- who the site knows ----------------------------------------------- */

	public function test_unknown_address_has_no_state(): void {
		$this->assertNull( law_hubspot_desired_state( 'nobody-' . wp_generate_password( 6, false ) . '@example.test' ) );
		$this->assertNull( law_hubspot_desired_state( '' ) );
	}

	public function test_people_lists_users_and_speakers_once_each(): void {
		list( , $email ) = $this->make_person();
		$speaker_only    = $this->unique_email( 'speaker-only' );
		law_speaker_upsert( array( 'name' => 'Solo Speaker', 'email' => $speaker_only ) );
		// A speaker record for someone who also has an account: one entry.
		law_speaker_upsert( array( 'name' => 'Ada Lovelace', 'email' => strtoupper( $email ) ) );

		$people = law_hubspot_people();

		$this->assertContains( $email, $people );
		$this->assertContains( $speaker_only, $people );
		$this->assertSame( count( $people ), count( array_unique( $people ) ) );
	}

	/* ---- standard properties (§5.1) -------------------------------------- */

	public function test_registered_user_gets_profile_and_registered_tag(): void {
		list( $user_id, $email ) = $this->make_person();

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( $email, $state['email'] );
		$this->assertSame(
			array( 'email' => $email, 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'jobtitle' => 'Arbitrator', 'company' => 'Analytical Engines', 'country' => 'United Kingdom' ),
			$state['properties']
		);
		$this->assertSame( array( '2026 Registered user' ), $state['contact_type_add'] );
		$this->assertSame( 'other', $state['legal_basis_min'], 'An account alone is legitimate interest – other.' );
		$this->assertSame( $user_id, $state['sources']['user'] );
		$this->assertSame( '', $state['hubspot_id'] );
	}

	public function test_empty_profile_fields_are_not_sent(): void {
		list( , $email ) = $this->make_person( array( 'job_title' => '', 'country' => '  ' ) );

		$state = law_hubspot_desired_state( $email );

		$this->assertArrayNotHasKey( 'jobtitle', $state['properties'], 'An empty field must never blank what LAW typed in HubSpot.' );
		$this->assertArrayNotHasKey( 'country', $state['properties'] );
		$this->assertSame( 'Analytical Engines', $state['properties']['company'] );
	}

	public function test_year_prefixes_every_tag(): void {
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'year' => 2027 ) );
		list( , $email ) = $this->make_person();

		$this->assertSame( array( '2027 Registered user' ), law_hubspot_desired_state( $email )['contact_type_add'] );
	}

	/* ---- Contact type tags (§5.3) ----------------------------------------- */

	public function test_confirmed_booking_makes_an_attendee_and_a_customer(): void {
		list( $user_id, $email ) = $this->make_person();
		$event      = $this->make_bookable();
		$booking_id = $this->insert_booking( $event, $user_id );

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( array( '2026 Attendee', '2026 Registered user' ), $this->tags( $state ) );
		$this->assertSame( 'customer', $state['legal_basis_min'] );
		$this->assertSame( array( $booking_id ), $state['sources']['bookings'] );
	}

	public function test_cancelled_waitlisted_and_pending_bookings_do_not_count(): void {
		list( $user_id, $email ) = $this->make_person();
		$event = $this->make_bookable();
		foreach ( array( 'law-cancelled', 'law-waitlisted', 'law-applied', 'law-pending-payment', 'law-declined' ) as $status ) {
			$this->insert_booking( $event, $user_id, $status );
		}

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( array( '2026 Registered user' ), $state['contact_type_add'] );
		$this->assertSame( 'other', $state['legal_basis_min'] );
	}

	public function test_press_pass_adds_the_press_tag(): void {
		list( $user_id, $email ) = $this->make_person();
		$this->insert_booking( $this->make_bookable(), $user_id, 'publish', true );

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( array( '2026 Attendee', '2026 Press', '2026 Registered user' ), $this->tags( $state ) );
		$this->assertTrue( $state['sources']['press'] );
	}

	public function test_reception_tags_are_keyed_by_the_seed_slugs(): void {
		$this->assertSame(
			array( 'opening-drinks' => '2026 Monday reception', 'wednesday-reception' => '2026 Wednesday reception', 'friday-reception' => '2026 Friday reception' ),
			$this->config['reception_tags'],
			'The keys are law_reception_seed_map() slugs, which is how the receptions module identifies them.'
		);
	}

	public function test_reception_place_adds_the_reception_tag(): void {
		list( $user_id, $email ) = $this->make_person();
		$this->insert_booking( $this->make_reception_tagged( '2026 Wednesday reception' ), $user_id );

		$state = law_hubspot_desired_state( $email );

		$this->assertContains( '2026 Wednesday reception', $state['contact_type_add'] );
		$this->assertContains( '2026 Attendee', $state['contact_type_add'] );
		$this->assertSame( array( '2026 Wednesday reception' ), $state['sources']['reception_tags'] );
	}

	public function test_reception_the_config_does_not_name_adds_no_reception_tag(): void {
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		list( $user_id, $email ) = $this->make_person();
		$reception = $this->make_bookable( array( '_law_is_reception' => 1 ) );
		wp_update_post( array( 'ID' => $reception, 'post_name' => 'some-other-party-' . strtolower( wp_generate_password( 6, false ) ) ) );
		$this->insert_booking( $reception, $user_id );

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( array( '2026 Attendee', '2026 Registered user' ), $this->tags( $state ) );
	}

	public function test_cancelled_reception_place_adds_no_reception_tag(): void {
		list( $user_id, $email ) = $this->make_person();
		$this->insert_booking( $this->make_reception_tagged( '2026 Friday reception' ), $user_id, 'law-cancelled' );

		$this->assertSame( array( '2026 Registered user' ), law_hubspot_desired_state( $email )['contact_type_add'] );
	}

	public function test_approved_event_author_is_an_event_host(): void {
		list( $user_id, $email ) = $this->make_person();
		$event = $this->make_event( array(), 'law-approved', $user_id );

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( array( '2026 Event Host', '2026 Registered user' ), $this->tags( $state ) );
		$this->assertSame( 'customer', $state['legal_basis_min'] );
		$this->assertSame( array( $event ), $state['sources']['hosted'] );
	}

	public function test_published_event_author_is_also_an_event_host(): void {
		list( $user_id, $email ) = $this->make_person();
		$this->make_event( array(), 'publish', $user_id );

		$this->assertContains( '2026 Event Host', law_hubspot_desired_state( $email )['contact_type_add'] );
	}

	public function test_unapproved_event_author_is_not_an_event_host(): void {
		list( $user_id, $email ) = $this->make_person();
		foreach ( array( 'law-proposed', 'law-draft', 'law-declined', 'law-withdrawn', 'draft', 'trash' ) as $status ) {
			$this->make_event( array(), $status, $user_id );
		}

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( array( '2026 Registered user' ), $state['contact_type_add'], 'Only an approved event earns the tag (§5.3).' );
		$this->assertSame( 'other', $state['legal_basis_min'] );
	}

	public function test_co_owner_of_an_approved_event_is_an_event_contact(): void {
		list( $host_id ) = $this->make_person();
		list( $co_id, $co_email ) = $this->make_person();
		$event = $this->make_event( array(), 'law-approved', $host_id );
		law_event_set_co_owner_ids( $event, array( $co_id ) );

		$state = law_hubspot_desired_state( $co_email );

		$this->assertSame( array( '2026 Event contact', '2026 Registered user' ), $this->tags( $state ), 'Lower-case c: the portal\'s existing option.' );
		$this->assertSame( 'customer', $state['legal_basis_min'] );
		$this->assertSame( array( $event ), $state['sources']['co_owned'] );
	}

	public function test_co_owner_of_a_proposed_event_is_not_yet_a_contact(): void {
		list( $host_id ) = $this->make_person();
		list( $co_id, $co_email ) = $this->make_person();
		law_event_set_co_owner_ids( $this->make_event( array(), 'law-proposed', $host_id ), array( $co_id ) );

		$this->assertSame( array( '2026 Registered user' ), law_hubspot_desired_state( $co_email )['contact_type_add'] );
	}

	public function test_sponsor_tier_event_makes_host_and_co_owner_sponsors(): void {
		list( $host_id, $host_email ) = $this->make_person();
		list( $co_id, $co_email )     = $this->make_person();
		$event = $this->make_event( array( '_law_fee_tier' => 'sponsor' ), 'law-approved', $host_id );
		law_event_set_co_owner_ids( $event, array( $co_id ) );

		$this->assertContains( '2026 Sponsor', law_hubspot_desired_state( $host_email )['contact_type_add'] );
		$this->assertContains( '2026 Sponsor', law_hubspot_desired_state( $co_email )['contact_type_add'] );
	}

	public function test_other_fee_tiers_are_not_sponsors(): void {
		list( $host_id, $host_email ) = $this->make_person();
		$this->make_event( array( '_law_fee_tier' => 'uk' ), 'law-approved', $host_id );

		$this->assertNotContains( '2026 Sponsor', law_hubspot_desired_state( $host_email )['contact_type_add'] );
	}

	public function test_speaker_at_an_approved_event_is_a_speaker(): void {
		list( , $email ) = $this->make_person();
		$speaker_id = law_speaker_upsert( array( 'name' => 'Ada Lovelace', 'email' => $email ) );
		$event      = $this->make_event( array(), 'law-approved' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker_id, 'role' => 'speaker' ) ) );
		law_speakers_flush_maps();

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( array( '2026 Registered user', '2026 Speaker' ), $this->tags( $state ) );
		$this->assertSame( 'customer', $state['legal_basis_min'] );
		$this->assertSame( $speaker_id, $state['sources']['speaker'] );
		$this->assertSame( array( $event ), $state['sources']['speaking'] );
	}

	public function test_speaker_only_at_a_proposed_event_is_not_tagged(): void {
		list( , $email ) = $this->make_person();
		$speaker_id = law_speaker_upsert( array( 'name' => 'Ada Lovelace', 'email' => $email ) );
		law_event_update_meta( $this->make_event( array(), 'law-proposed' ), '_law_speakers', array( array( 'speaker_id' => $speaker_id ) ) );
		law_speakers_flush_maps();

		$this->assertSame( array( '2026 Registered user' ), law_hubspot_desired_state( $email )['contact_type_add'] );
	}

	public function test_speaker_without_an_account_gets_name_and_appearance_details(): void {
		$email      = $this->unique_email( 'solo' );
		$speaker_id = law_speaker_upsert( array( 'first_name' => 'Grace', 'last_name' => 'Hopper', 'email' => $email ) );
		$event      = $this->make_event( array(), 'law-approved' );
		law_event_update_meta( $event, '_law_speakers', array( array( 'speaker_id' => $speaker_id, 'organisation' => 'US Navy', 'job_title' => 'Rear Admiral' ) ) );
		law_speakers_flush_maps();

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( 0, $state['sources']['user'] );
		$this->assertSame( array( 'email' => $email, 'firstname' => 'Grace', 'lastname' => 'Hopper', 'jobtitle' => 'Rear Admiral', 'company' => 'US Navy' ), $state['properties'] );
		$this->assertSame( array( '2026 Speaker' ), $state['contact_type_add'], 'No account, so no Registered user tag.' );
		$this->assertSame( 'customer', $state['legal_basis_min'] );
	}

	public function test_speaker_record_with_no_appearances_and_no_account_has_no_tags_and_no_basis(): void {
		$email = $this->unique_email( 'idle' );
		law_speaker_upsert( array( 'name' => 'Idle Speaker', 'email' => $email ) );

		$state = law_hubspot_desired_state( $email );

		$this->assertNotNull( $state, 'The site knows the address, so there is a state (name only).' );
		$this->assertSame( array(), $state['contact_type_add'] );
		$this->assertSame( '', $state['legal_basis_min'], 'Nothing to base a legal basis on: leave it alone.' );
	}

	public function test_account_profile_beats_speaker_appearance_for_shared_fields(): void {
		list( , $email ) = $this->make_person( array( 'organisation' => 'Her Own Chambers' ) );
		$speaker_id = law_speaker_upsert( array( 'name' => 'Different Name', 'email' => $email ) );
		law_event_update_meta( $this->make_event( array(), 'law-approved' ), '_law_speakers', array( array( 'speaker_id' => $speaker_id, 'organisation' => 'Appearance Org' ) ) );
		law_speakers_flush_maps();

		$state = law_hubspot_desired_state( $email );

		$this->assertSame( 'Ada', $state['properties']['firstname'] );
		$this->assertSame( 'Her Own Chambers', $state['properties']['company'] );
	}

	public function test_a_person_wearing_every_hat_gets_every_tag_once(): void {
		list( $user_id, $email ) = $this->make_person();
		$hosted = $this->make_event( array( '_law_fee_tier' => 'sponsor' ), 'law-approved', $user_id );
		$this->insert_booking( $this->make_bookable(), $user_id );
		$this->insert_booking( $this->make_bookable(), $user_id, 'publish', true );
		$this->insert_booking( $this->make_reception_tagged( '2026 Monday reception' ), $user_id );
		$speaker_id = law_speaker_upsert( array( 'name' => 'Ada Lovelace', 'email' => $email ) );
		law_event_update_meta( $hosted, '_law_speakers', array( array( 'speaker_id' => $speaker_id ) ) );
		law_speakers_flush_maps();

		$state = law_hubspot_desired_state( $email );

		$this->assertSame(
			array( '2026 Attendee', '2026 Event Host', '2026 Monday reception', '2026 Press', '2026 Registered user', '2026 Speaker', '2026 Sponsor' ),
			$this->tags( $state )
		);
		$this->assertCount( count( array_unique( $state['contact_type_add'] ) ), $state['contact_type_add'] );
	}

	/* ---- the stored HubSpot ID -------------------------------------------- */

	public function test_store_and_forget_id_cover_user_and_speaker(): void {
		list( $user_id, $email ) = $this->make_person();
		$speaker_id = law_speaker_upsert( array( 'name' => 'Ada Lovelace', 'email' => $email ) );
		$state      = law_hubspot_desired_state( $email );

		law_hubspot_store_id( $state, '12345' );
		$this->assertSame( '12345', get_user_meta( $user_id, 'law_hubspot_id', true ) );
		$this->assertSame( '12345', get_post_meta( $speaker_id, 'law_hubspot_id', true ) );
		$this->assertSame( '12345', law_hubspot_desired_state( $email )['hubspot_id'] );

		law_hubspot_forget_id( $state );
		$this->assertSame( '', get_user_meta( $user_id, 'law_hubspot_id', true ) );
		$this->assertSame( '', law_hubspot_desired_state( $email )['hubspot_id'] );
	}

	public function test_speaker_only_person_keeps_the_id_on_the_speaker_post(): void {
		$email      = $this->unique_email( 'solo' );
		$speaker_id = law_speaker_upsert( array( 'name' => 'Solo Speaker', 'email' => $email ) );

		law_hubspot_store_id( law_hubspot_desired_state( $email ), '999' );

		$this->assertSame( '999', get_post_meta( $speaker_id, 'law_hubspot_id', true ) );
		$this->assertSame( '999', law_hubspot_desired_state( $email )['hubspot_id'] );
	}

	/* ---- merges (§3.5, HubSpotMergeTest) ---------------------------------- */

	public function test_contact_type_merge_is_a_union_that_keeps_order_and_unknown_values(): void {
		$this->assertSame(
			'2025 Attendee;Mailchimp;2026 Registered user;2026 Attendee',
			law_hubspot_merge_contact_type( '2025 Attendee;Mailchimp', array( '2026 Registered user', '2026 Attendee' ) )
		);
		$this->assertSame( '2026 Attendee', law_hubspot_merge_contact_type( '', array( '2026 Attendee' ) ) );
		$this->assertSame( 'A;B', law_hubspot_merge_contact_type( 'A;B', array( 'B', 'A' ) ), 'Already present: unchanged, in HubSpot\'s order.' );
		$this->assertSame( 'A;B', law_hubspot_merge_contact_type( 'A; B ;', array( '' ) ), 'Stray spaces and empties are cleaned, nothing added.' );
		$this->assertSame( 'LAW only', law_hubspot_merge_contact_type( 'LAW only', array() ), 'The site never removes a tag it did not add.' );
	}

	public function test_legal_basis_is_set_only_when_empty(): void {
		$other    = $this->config['legal_basis_other'];
		$customer = $this->config['legal_basis_customer'];

		$this->assertSame( $other, law_hubspot_merge_legal_basis( '', 'other' ) );
		$this->assertSame( $customer, law_hubspot_merge_legal_basis( '', 'customer' ) );
		$this->assertNull( law_hubspot_merge_legal_basis( '', '' ), 'Nothing to base it on: leave empty.' );
		$this->assertNull( law_hubspot_merge_legal_basis( 'Freely given consent from contact', 'customer' ), 'A value LAW chose is never overwritten.' );
		$this->assertNull( law_hubspot_merge_legal_basis( 'Performance of a contract', 'other' ) );
	}

	public function test_legal_basis_upgrades_other_to_customer_and_never_downgrades(): void {
		$other    = $this->config['legal_basis_other'];
		$customer = $this->config['legal_basis_customer'];

		$this->assertSame( $customer, law_hubspot_merge_legal_basis( $other, 'customer' ) );
		$this->assertNull( law_hubspot_merge_legal_basis( $customer, 'other' ), 'Never downgrade.' );
		$this->assertNull( law_hubspot_merge_legal_basis( $customer, 'customer' ), 'Already there.' );
		$this->assertNull( law_hubspot_merge_legal_basis( $other, 'other' ) );
	}

	public function test_legal_basis_leaves_a_multi_valued_list_alone(): void {
		$other    = $this->config['legal_basis_other'];
		$customer = $this->config['legal_basis_customer'];

		// The portal's property is a checkbox type, so a list is possible.
		$this->assertNull( law_hubspot_merge_legal_basis( $other . ';Freely given consent from contact', 'customer' ), 'Two values: LAW has been in here, leave it.' );
		$this->assertNull( law_hubspot_merge_legal_basis( $customer . ';' . $other, 'customer' ) );
	}

	public function test_split_multi(): void {
		$this->assertSame( array( 'a', 'b' ), law_hubspot_split_multi( 'a;b' ) );
		$this->assertSame( array( 'a', 'b' ), law_hubspot_split_multi( ' a ; b ;;a' ) );
		$this->assertSame( array(), law_hubspot_split_multi( '' ) );
		$this->assertSame( array(), law_hubspot_split_multi( null ) );
	}

	/* ---- phase 3: the site-owned properties ------------------------------- */

	/** A published LAW-run event in this year: the flagship (filtered in) or a reception. */
	/**
	 * @param bool $badged Reception only: register an attending option so the
	 *                     booking counts for Evening only / LAW-run events.
	 *                     False models Evensong (Emily, 16 November 2026).
	 */
	private function make_law_run( string $kind = 'reception', int $year = 2026, bool $badged = true ): int {
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		$meta = 'flagship' === $kind
			? array( '_law_is_flagship' => 1, '_law_flagship_date' => $year . '-12-02', '_law_flagship_price_pence' => 55000 )
			: array( '_law_is_reception' => 1 );
		$event_id = $this->make_bookable( $meta );
		wp_set_object_terms( $event_id, (string) $year, 'law_year', false );
		if ( 'flagship' === $kind ) {
			remove_all_filters( 'law_flagship_event_id' );
			add_filter( 'law_flagship_event_id', fn() => $event_id );
			law_flagship_event_id( true );
		} elseif ( $badged ) {
			$slug = get_post_field( 'post_name', $event_id );
			add_filter(
				'law_hubspot_config',
				function ( array $config ) use ( $slug, $year ) {
					$config['attending_options'][ $slug ] = $year . ' Test reception';
					return $config;
				}
			);
			law_hubspot_attending_options( true );
		}
		return $event_id;
	}

	/** A hosted (not LAW-run) published event in this year, optionally with sectors. */
	private function make_hosted( int $year = 2026, array $sectors = array() ): int {
		$event_id = $this->make_bookable();
		wp_set_object_terms( $event_id, (string) $year, 'law_year', false );
		$ids = array();
		foreach ( $sectors as $name ) {
			$term  = get_term_by( 'name', $name, 'law_sector' );
			$ids[] = $term ? (int) $term->term_id : (int) wp_insert_term( $name, 'law_sector' )['term_id'];
		}
		if ( $ids ) {
			wp_set_object_terms( $event_id, $ids, 'law_sector', false );
		}
		return $event_id;
	}

	private function site( string $email ): array {
		law_hubspot_attending_options( true ); // receptions are created mid-test
		return law_hubspot_desired_state( $email )['site_properties'];
	}

	public function test_pass_type_follows_the_precedence_across_law_run_bookings(): void {
		[ $user_id, $email ] = $this->make_person();
		$flagship    = $this->make_law_run( 'flagship' );
		$reception_b = $this->make_law_run();

		$this->assertSame( '', $this->site( $email )['passtype'], 'No LAW-run booking: nothing to say.' );

		$a = $this->insert_booking( $flagship, $user_id );
		$this->assertSame( '', $this->site( $email )['passtype'], 'Unclassified Flagship: send nothing, never a blank.' );

		// Emily's order (12 November 2026): Delegate and Press at the top (they
		// never coincide), then Sponsor, Committee, Speaker, Patron. A paying
		// delegate who also speaks is badged Delegate.
		law_event_update_meta( $a, '_law_ticket_type', 'patron' );
		$this->assertSame( 'Patron', $this->site( $email )['passtype'] );

		$b = $this->insert_booking( $reception_b, $user_id );
		law_event_update_meta( $b, '_law_ticket_type', 'speaker' );
		$this->assertSame( 'Speaker', $this->site( $email )['passtype'], 'Speaker beats Patron.' );

		law_event_update_meta( $a, '_law_ticket_type', 'committee' );
		$this->assertSame( 'Committee', $this->site( $email )['passtype'], 'Committee beats Speaker.' );

		law_event_update_meta( $b, '_law_ticket_type', 'sponsor' );
		$this->assertSame( 'Sponsor', $this->site( $email )['passtype'], 'Sponsor beats Committee.' );

		law_event_update_meta( $a, '_law_ticket_type', 'delegate' );
		$this->assertSame( 'Delegate', $this->site( $email )['passtype'], 'Delegate beats everything: the paid Flagship place is the badge.' );

		// A cancelled place no longer counts (the status guard only lets the
		// engine move a booking, so the engine's own path is used).
		$GLOBALS['law_booking_transitioning'] = true;
		try {
			wp_update_post( array( 'ID' => $a, 'post_status' => 'law-cancelled' ) );
		} finally {
			$GLOBALS['law_booking_transitioning'] = false;
		}
		$this->assertSame( 'law-cancelled', get_post_status( $a ) );
		// Flagship gone: reception-only Sponsor still wins over Evening only.
		$this->assertSame( 'Sponsor', $this->site( $email )['passtype'] );
	}

	public function test_reception_only_guests_are_evening_only_unless_they_hold_a_named_role(): void {
		[ $user_id, $email ] = $this->make_person();
		$reception = $this->make_law_run();

		$booking = $this->insert_booking( $reception, $user_id );
		$this->assertSame( 'Evening only', $this->site( $email )['passtype'], 'Blank reception-only → Evening only (Milly, 18 November 2026).' );

		law_event_update_meta( $booking, '_law_ticket_type', 'delegate' );
		$this->assertSame( 'Evening only', $this->site( $email )['passtype'], 'Delegate is reserved for Flagship; reception-only stays Evening only.' );

		law_event_update_meta( $booking, '_law_ticket_type', 'speaker' );
		$this->assertSame( 'Speaker', $this->site( $email )['passtype'], 'A named role on a reception still wins.' );

		// Flagship place takes them out of Evening only, even with a blank type.
		$flagship = $this->insert_booking( $this->make_law_run( 'flagship' ), $user_id );
		$this->assertSame( 'Speaker', $this->site( $email )['passtype'], 'Flagship + Speaker reception: Speaker still wins under precedence.' );

		law_event_update_meta( $booking, '_law_ticket_type', '' );
		$this->assertSame( '', $this->site( $email )['passtype'], 'Flagship unclassified and no other role: send nothing.' );

		law_event_update_meta( $flagship, '_law_ticket_type', 'delegate' );
		law_event_update_meta( $booking, '_law_ticket_type', 'evening' );
		$this->assertSame( 'Delegate', $this->site( $email )['passtype'], 'Flagship Delegate beats an Evening only ticket on a reception.' );
	}

	public function test_pass_type_precedence_is_one_config_edit(): void {
		[ $user_id, $email ] = $this->make_person();
		$a = $this->insert_booking( $this->make_law_run(), $user_id );
		$b = $this->insert_booking( $this->make_law_run(), $user_id );
		law_event_update_meta( $a, '_law_ticket_type', 'speaker' );
		law_event_update_meta( $b, '_law_ticket_type', 'sponsor' );
		$this->assertSame( 'Sponsor', $this->site( $email )['passtype'] );

		add_filter(
			'law_hubspot_config',
			function ( array $config ) {
				$config['passtype_precedence'] = array( 'speaker', 'sponsor' );
				return $config;
			}
		);
		$this->assertSame( 'Speaker', $this->site( $email )['passtype'], 'LAW can have the order they like.' );
	}

	public function test_press_flag_and_press_type_both_read_as_press(): void {
		[ $user_id, $email ] = $this->make_person();
		$this->insert_booking( $this->make_law_run(), $user_id, 'publish', true );

		$state = law_hubspot_desired_state( $email );
		$this->assertSame( 'Press', $state['site_properties']['passtype'] );
		$this->assertContains( $this->config['tags']['press'], $state['contact_type_add'] );

		[ $user_b, $email_b ] = $this->make_person();
		$booking = $this->insert_booking( $this->make_law_run(), $user_b );
		law_event_update_meta( $booking, '_law_ticket_type', 'press' );
		$state = law_hubspot_desired_state( $email_b );
		$this->assertSame( 'Press', $state['site_properties']['passtype'] );
		$this->assertContains( $this->config['tags']['press'], $state['contact_type_add'], 'The type alone earns the tag too.' );
	}

	public function test_setting_the_ticket_type_keeps_the_press_flag_in_step(): void {
		[ $user_id ] = $this->make_person();
		$booking     = $this->insert_booking( $this->make_law_run(), $user_id );

		law_booking_set_ticket_type( $booking, 'press', 0 );
		$this->assertSame( 1, (int) law_event_meta( $booking, '_law_is_press' ), 'Press type raises the flag.' );

		law_booking_set_ticket_type( $booking, 'delegate', 0 );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_is_press' ), 'Moving off Press lowers it.' );

		// The other direction: a press pass issued by the committee classifies
		// the seat, unless the committee already said something.
		law_booking_write_attendee( $booking, law_booking_attendee( $booking ), $user_id, true );
		$this->assertSame( 'delegate', (string) law_event_meta( $booking, '_law_ticket_type' ), 'An existing type is respected.' );
		law_event_update_meta( $booking, '_law_ticket_type', '' );
		law_booking_write_attendee( $booking, law_booking_attendee( $booking ), $user_id, true );
		$this->assertSame( 'press', (string) law_event_meta( $booking, '_law_ticket_type' ) );
		law_booking_write_attendee( $booking, law_booking_attendee( $booking ), $user_id, false );
		$this->assertSame( '', (string) law_event_meta( $booking, '_law_ticket_type' ), 'Withdrawing the pass withdraws the Press type it implied.' );
	}

	public function test_a_reception_booking_can_take_a_ticket_type_but_a_hosted_one_cannot(): void {
		[ $user_id ] = $this->make_person();
		$reception   = $this->insert_booking( $this->make_law_run(), $user_id );
		$hosted      = $this->insert_booking( $this->make_hosted(), $user_id );

		$this->assertSame( 'Patron', law_booking_set_ticket_type( $reception, 'patron', 0 )['label'] );
		$this->assertInstanceOf( WP_Error::class, law_booking_set_ticket_type( $hosted, 'patron', 0 ) );
		$this->assertInstanceOf( WP_Error::class, law_booking_set_ticket_type( $reception, 'exhibitor', 0 ), 'Retired.' );
	}

	public function test_dietary_icon_folds_the_choices_and_allergy_wins(): void {
		$this->assertSame( '', law_hubspot_dietary_icon( array() ) );
		$this->assertSame( '', law_hubspot_dietary_icon( array( 'Other' ) ), 'Other earns no icon; its text has its own property.' );
		$this->assertSame( 'Vegetarian or vegan', law_hubspot_dietary_icon( array( 'Vegan' ) ) );
		$this->assertSame( 'Vegetarian or vegan', law_hubspot_dietary_icon( array( 'Vegetarian' ) ) );
		$this->assertSame( 'Pescatarian', law_hubspot_dietary_icon( array( 'Pescatarian' ) ) );
		$this->assertSame( 'Allergy or intolerance', law_hubspot_dietary_icon( array( 'Nut allergy' ) ) );
		$this->assertSame( 'Allergy or intolerance', law_hubspot_dietary_icon( array( 'Coeliac', 'Vegetarian' ) ), 'An allergy beats a preference.' );
		$this->assertSame( 'Allergy or intolerance', law_hubspot_dietary_icon( array( 'Pescatarian', 'Lactose free', 'Vegan' ) ) );
		$this->assertSame( 'Vegetarian or vegan', law_hubspot_dietary_icon( array( 'Pescatarian', 'Vegetarian' ) ), 'Then vegetarian beats pescatarian.' );

		add_filter(
			'law_hubspot_config',
			function ( array $config ) {
				$config['dietary_icon_map']['Pescatarian'] = 'Vegetarian or vegan';
				return $config;
			}
		);
		$this->assertSame( 'Vegetarian or vegan', law_hubspot_dietary_icon( array( 'Pescatarian' ) ), 'The folding is one config edit.' );
	}

	public function test_dietary_and_access_come_only_with_a_law_run_place_this_year(): void {
		[ $user_id, $email ] = $this->make_person();
		update_user_meta( $user_id, 'dietary', array( 'Vegan', 'Nut allergy', 'Other' ) );
		update_user_meta( $user_id, 'dietary_other', 'No coriander' );
		update_user_meta( $user_id, 'accessibility', array( 'Wheelchair' ) );
		update_user_meta( $user_id, 'accessibility_other', 'Step-free route please' );

		$site = $this->site( $email );
		$this->assertSame( '', $site['dietary'], 'No booking anywhere: nothing is disclosed.' );
		$this->assertSame( '', $site['dietary_icon'] );
		$this->assertSame( '', $site['access_other'] );

		$this->insert_booking( $this->make_hosted(), $user_id );
		$this->assertSame( '', $this->site( $email )['dietary'], 'A hosted booking is not the badging need (§7.3).' );

		$this->insert_booking( $this->make_law_run( 'reception', 2025 ), $user_id );
		$this->assertSame( '', $this->site( $email )['dietary'], 'Nor is last year\'s reception.' );

		$this->insert_booking( $this->make_law_run(), $user_id );
		$site = $this->site( $email );
		$this->assertSame( 'Vegan;Nut allergy', $site['dietary'], 'Other is a tick that says "see the text".' );
		$this->assertSame( 'No coriander', $site['dietary_other'] );
		$this->assertSame( 'Allergy or intolerance', $site['dietary_icon'] );
		$this->assertSame( 'Wheelchair', $site['access'] );
		$this->assertSame( 'Step-free route please', $site['access_other'] );
	}

	public function test_dietary_falls_back_to_the_answers_stored_on_the_booking(): void {
		// A partner registered by their PA against an existing account
		// (BOOK_ON_BEHALF.md A3): the answers stay on the booking.
		[ $user_id, $email ] = $this->make_person();
		$booking = $this->insert_booking( $this->make_law_run(), $user_id );
		law_event_update_meta( $booking, '_law_attendee_profile', array( 'dietary' => array( 'Pescatarian' ), 'dietary_other' => '', 'accessibility' => array( 'Captions' ), 'accessibility_other' => '' ) );

		$site = $this->site( $email );
		$this->assertSame( 'Pescatarian', $site['dietary'] );
		$this->assertSame( 'Pescatarian', $site['dietary_icon'] );
		$this->assertSame( 'Captions', $site['access'] );

		// The account's own answer, once there is one, wins over the booking's.
		update_user_meta( $user_id, 'dietary', array( 'Vegan' ) );
		$this->assertSame( 'Vegan', $this->site( $email )['dietary'] );
	}

	public function test_events_attending_hosted_counts_and_sectors(): void {
		[ $user_id, $email ] = $this->make_person();
		$flagship = $this->make_law_run( 'flagship' );
		$hosted_a = $this->make_hosted( 2026, array( 'Energy' ) );
		$hosted_b = $this->make_hosted( 2026, array( 'Energy', 'Banking &amp; Finance' ) );
		$old      = $this->make_hosted( 2025, array( 'Shipping' ) );

		$this->insert_booking( $flagship, $user_id );
		$this->insert_booking( $hosted_a, $user_id );
		$this->insert_booking( $hosted_b, $user_id );
		$this->insert_booking( $old, $user_id );
		$this->insert_booking( $this->make_hosted(), $user_id, 'law-cancelled' );

		$site = $this->site( $email );
		$this->assertSame( array( '2026 Flagship' ), $site['events_attending'] );
		$this->assertSame( '2', $site['events_registered_count'], 'This year\'s confirmed hosted places; not the flagship, not 2025, not the cancelled one.' );
		$this->assertSame( get_the_title( $hosted_a ) . "\n" . get_the_title( $hosted_b ), $site['events_registered'] );
		$this->assertSame( 'Banking & Finance;Energy;Shipping', $site['event_sectors'], 'Every year, decoded, sorted, unique.' );
	}

	public function test_events_attending_names_the_reception_by_its_slug(): void {
		[ $user_id, $email ] = $this->make_person();
		$reception = $this->make_law_run();
		add_filter(
			'law_hubspot_config',
			function ( array $config ) use ( $reception ) {
				$config['attending_options'][ get_post_field( 'post_name', $reception ) ] = '2026 Test reception';
				return $config;
			}
		);
		$this->insert_booking( $reception, $user_id );
		$this->assertSame( array( '2026 Test reception' ), $this->site( $email )['events_attending'], 'The config label wins over the title.' );
	}

	public function test_evensong_is_law_run_for_access_but_not_a_badged_attending_option(): void {
		// Emily, 16 November 2026: Evensong is not a badged event; guests are
		// covered by the opening reception. Dietary/access still flow because
		// it is LAW-run under Peter's disclosure.
		[ $user_id, $email ] = $this->make_person();
		$reception = $this->make_law_run( 'reception', 2026, false );
		wp_update_post( array( 'ID' => $reception, 'post_title' => 'Evensong at St Paul&#8217;s Cathedral' ) );
		$this->insert_booking( $reception, $user_id );
		update_user_meta( $user_id, 'accessibility', array( 'Captions' ) );

		$site = $this->site( $email );
		$this->assertSame( array(), $site['events_attending'], 'Not on the badging attending list.' );
		$this->assertSame( '', $site['passtype'], 'And not Evening only on Evensong alone.' );
		$this->assertSame( 'Captions', $site['access'], 'Access still syncs for a LAW-run place.' );

		$options = law_hubspot_attending_options();
		$this->assertSame( '2026 Flagship', $options['flagship'] );
		$this->assertSame( '2026 Monday reception', $options['opening-drinks'] );
		$this->assertArrayNotHasKey( get_post_field( 'post_name', $reception ), $options );
		$this->assertNotContains(
			'2026 Evensong at St Paul’s Cathedral',
			array_column( law_hubspot_property_definitions()['law_events_attending']['options'], 'value' )
		);
	}
}
