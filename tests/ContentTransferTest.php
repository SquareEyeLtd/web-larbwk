<?php
/**
 * Content transfer: the receptions, flagship and discount configuration moved
 * between environments as one JSON file
 * (functions/events/migration/content-transfer.php).
 *
 * What these pin is the one property that makes the file portable at all —
 * that it contains no local post, user or attachment IDs — and the four places
 * a cross-site import quietly goes wrong: prices that are stored in pence but
 * edited in pounds, a dry run that writes something, a speaker who is
 * duplicated instead of matched, and a discount whose scope points at an event
 * that does not exist on the far side.
 *
 * Fixture note, twice over. law_flagship_event_id() returns the lowest flagged
 * ID and the real site holds a committed flagship post, so every test here
 * pins its own through the 'law_flagship_event_id' filter, exactly as
 * FlagshipTest does. And the importer logs through law_migration_log(), which
 * creates its table on first use: DDL implicitly commits in MariaDB and would
 * end the transaction LAW_Test_Case rolls back, so the table is created once
 * before the class runs rather than inside a test.
 */
class ContentTransferTest extends LAW_Test_Case {

	private int $flagship = 0;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// Outside any test's transaction. Idempotent: it returns at once when
		// the table is already there, which it will be on any site that has
		// run the migration.
		law_migration_install_log_table();
	}

	protected function setUp(): void {
		parent::setUp();
		// The local database holds 15 real overrides. Every email assertion here
		// is about what the export and import do with a KNOWN set, so the option
		// is isolated and emptied rather than tested against the site's own.
		$this->isolate_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION );
		update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array(), false );
	}

	protected function tearDown(): void {
		remove_all_filters( 'law_flagship_event_id' );
		remove_all_filters( 'law_content_transfer_max_unzipped' );
		remove_all_filters( 'upload_dir' );
		remove_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10 );
		law_flagship_event_id( true );
		$_POST  = array();
		$_FILES = array();

		// Temp files and extracted images live on the filesystem, which the
		// transaction LAW_Test_Case rolls back does not reach.
		foreach ( $this->dirs as $dir ) {
			$this->rmtree( $dir );
		}
		foreach ( $this->files as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}
		$this->files = array();
		$this->dirs  = array();

		parent::tearDown();
	}

	/* Fixtures ______________________________________________________________ */

	/**
	 * A slug no other reception on this site holds.
	 *
	 * The local database already carries the three real receptions, and both
	 * law_content_transfer_bundle() and the importer's get_page_by_path() match
	 * on the slug, so a fixture called "opening-drinks" would silently test
	 * against the site's own record rather than its own.
	 */
	private function slug( string $stem ): string {
		return $stem . '-' . strtolower( wp_generate_password( 8, false, false ) );
	}

	private function make_reception( string $slug, array $meta = array(), string $status = 'publish' ): int {
		$event_id = $this->make_event(
			array_merge(
				array(
					'_law_is_reception'         => 1,
					'_law_start'                => '2026-11-30 18:30',
					'_law_end'                  => '2026-11-30 20:30',
					'_law_venue'                => 'A hall',
					'_law_tickets_available'    => 200,
					'_law_attendee_price_pence' => 4500,
					'_law_registration_state'   => 'open',
				),
				$meta
			),
			$status
		);
		wp_update_post( array( 'ID' => $event_id, 'post_name' => $slug, 'post_title' => ucfirst( str_replace( '-', ' ', $slug ) ) ) );
		return $event_id;
	}

	private function make_flagship( string $status = 'publish' ): int {
		$event_id = $this->make_event(
			array(
				'_law_is_flagship'              => 1,
				'_law_flagship_date'            => '2026-12-02',
				'_law_venue'                    => 'The venue',
				'_law_tickets_available'        => 300,
				'_law_flagship_price_pence'     => 59500,
				'_law_flagship_price_late_pence' => 69500,
				'_law_flagship_price_switch'    => '2026-10-17 00:00',
			),
			$status
		);
		wp_update_post( array( 'ID' => $event_id, 'post_name' => 'flagship' ) );

		$this->flagship = $event_id;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		law_flagship_event_id( true );

		return $event_id;
	}

	private function make_discount( string $code, array $meta = array() ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => LAW_DISCOUNT_CPT,
				'post_status' => 'publish',
				'post_title'  => $code,
				'post_name'   => strtolower( law_discount_match_key( $code ) ),
			)
		);
		$this->posts[] = $post_id;
		foreach ( array_merge( array( '_law_discount_type' => 'percent', '_law_discount_value' => 25 ), $meta ) as $key => $value ) {
			law_event_update_meta( $post_id, $key, $value );
		}
		return (int) $post_id;
	}

	/** A minimal, valid bundle to feed the importer. */
	private function bundle( array $overrides = array() ): array {
		return array_merge(
			array(
				'format'       => LAW_CONTENT_TRANSFER_FORMAT,
				'version'      => LAW_CONTENT_TRANSFER_VERSION,
				'site'         => array( 'url' => 'https://staging.example.test/', 'uploads_baseurl' => 'https://staging.example.test/wp-content/uploads/' ),
				'receptions'   => array(),
				'flagship'     => null,
				'discounts'    => array(),
			),
			$overrides
		);
	}

	private function reception_row( string $slug, array $overrides = array() ): array {
		return array_merge(
			array(
				'slug'        => $slug,
				'title'       => 'Opening drinks',
				'description' => '<p>Drinks.</p>',
				'show'        => true,
				'date'        => '2026-11-30',
				'start'       => '18:30',
				'end'         => '20:30',
				'venue'       => 'The Hall',
				'places'      => 250,
				'price'       => '55.00',
				'included'    => true,
				'invitation'  => false,
			),
			$overrides
		);
	}

	/* The format ____________________________________________________________ */

	public function test_the_bundle_carries_no_local_ids(): void {
		// The one invariant the whole format rests on. Post, user and
		// attachment IDs mean nothing on the far site, so if any of them ever
		// reaches the file, an import will silently point something at the
		// wrong record.
		$this->make_reception( $this->slug( 'ct-drinks' ) );
		$flagship = $this->make_flagship();
		$speaker  = $this->make_speaker_post( 'Ali', 'Malek KC', 'ali@example.test' );
		law_event_update_meta(
			$flagship,
			'_law_speakers',
			array( array( 'speaker_id' => $speaker, 'role' => 'moderator', 'organisation' => '3VB', 'job_title' => 'Barrister', 'bio' => '<p>Bio.</p>' ) )
		);
		$this->make_discount( 'LAW-WEEK-25' );

		$bundle = law_content_transfer_bundle();
		$found  = array();
		// The two exemptions are the same exemption: a GRAVITY FORMS entry ID is
		// not a local ID. Both sites build their programme by migrating the same
		// Gravity Forms entries, so entry 190 on form 2 (Event > submit an
		// event) is the same event on both, which is exactly why
		// law_content_transfer_find_event() matches on it first.
		$entry_keys = array( 'entry_id', 'gf_entry_id' );
		array_walk_recursive(
			$bundle,
			function ( $value, $key ) use ( &$found, $entry_keys ) {
				if ( preg_match( '/(^|_)id$/', (string) $key ) && ! in_array( (string) $key, $entry_keys, true ) ) {
					$found[] = $key;
				}
			}
		);

		$this->assertSame( array(), $found, 'The bundle must carry no local IDs: ' . implode( ', ', array_unique( $found ) ) );
	}

	public function test_prices_travel_as_pounds_and_come_back_as_pence(): void {
		$slug = $this->slug( 'ct-drinks' );
		$this->make_reception( $slug, array( '_law_attendee_price_pence' => 4550 ) );
		$this->make_flagship();
		$code = 'FIFTY-OFF-' . strtoupper( wp_generate_password( 6, false, false ) );
		$this->make_discount( $code, array( '_law_discount_type' => 'fixed', '_law_discount_value' => 5000 ) );

		$bundle = law_content_transfer_bundle();

		$reception = $this->row_by( $bundle['receptions'], 'slug', $slug );
		$this->assertSame( '45.50', $reception['price'] );
		$this->assertSame( 4550, law_events_pounds_to_pence( $reception['price'] ) );

		$this->assertSame( '595.00', $bundle['flagship']['price'] );
		$this->assertSame( '695.00', $bundle['flagship']['price_late'] );

		$discount = $this->row_by( $bundle['discounts'], 'code', $code );
		$this->assertSame( '50.00', $discount['value'], 'A fixed code is stored in pence and edited in pounds.' );
	}

	public function test_an_unwritten_flagship_price_travels_as_empty_rather_than_as_the_default(): void {
		// law_flagship_price_pounds_field() substitutes the agreed default when
		// the key has never been written, which is right for a form field and
		// wrong for an export: it would turn "not set yet" into "set to £550"
		// and stamp that on the far site.
		$event_id = $this->make_flagship();
		delete_post_meta( $event_id, '_law_flagship_price_pence' );

		$bundle = law_content_transfer_bundle();

		$this->assertSame( '', $bundle['flagship']['price'] );
		$this->assertNotSame( '', law_flagship_price_pounds_field( $event_id, '_law_flagship_price_pence' ), 'The form field still shows the default.' );
	}

	public function test_a_bundle_of_the_wrong_format_or_a_future_version_is_refused(): void {
		$this->assertWPError( law_content_transfer_parse( 'not json at all' ), 'law_ct_bad_json' );
		$this->assertWPError( law_content_transfer_parse( wp_json_encode( array( 'format' => 'something-else' ) ) ), 'law_ct_wrong_format' );
		$this->assertWPError(
			law_content_transfer_parse( wp_json_encode( array( 'format' => LAW_CONTENT_TRANSFER_FORMAT, 'version' => LAW_CONTENT_TRANSFER_VERSION + 1 ) ) ),
			'law_ct_future_version'
		);

		$good = law_content_transfer_parse( wp_json_encode( $this->bundle() ) );
		$this->assertIsArray( $good );
	}

	/* The dry run ___________________________________________________________ */

	public function test_a_dry_run_writes_nothing(): void {
		$slug     = $this->slug( 'ct-drinks' );
		$event_id = $this->make_reception( $slug, array( '_law_attendee_price_pence' => 4500 ) );

		$before = law_reception_snapshot( $event_id );
		$counts = $this->post_counts();

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'receptions' => array( $this->reception_row( $slug, array( 'price' => '99.00', 'venue' => 'Somewhere else' ) ) ),
					'discounts'  => array( array( 'code' => 'CT-BRAND-NEW-' . strtoupper( wp_generate_password( 6, false, false ) ), 'type' => 'percent', 'value' => '10', 'active' => true, 'events' => array() ) ),
				)
			),
			true
		);

		$this->assertSame( $before, law_reception_snapshot( $event_id ), 'A dry run must not touch the reception.' );
		$this->assertSame( $counts, $this->post_counts(), 'A dry run must not create any post.' );

		$reception = $rows[0];
		$this->assertSame( 'Update', $reception['verdict'] );
		$this->assertNotEmpty( $reception['changes'] );
		$this->assertStringContainsString( '4500 → 9900', implode( ' | ', $reception['changes'] ) );

		$discount = $rows[1];
		$this->assertSame( 'Create', $discount['verdict'] );
	}

	public function test_a_dry_run_reports_no_change_when_the_bundle_already_matches(): void {
		$slug     = $this->slug( 'ct-drinks' );
		$event_id = $this->make_reception( $slug );
		$values   = law_reception_form_values( $event_id );

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'receptions' => array(
						array(
							'slug'        => $slug,
							'title'       => $values['title'],
							'description' => (string) get_post_field( 'post_content', $event_id ),
							'show'        => $values['show'],
							'date'        => $values['date'],
							'start'       => $values['start'],
							'end'         => $values['end'],
							'venue'       => $values['venue'],
							'places'      => $values['places'],
							'price'       => $values['price'],
							'included'    => $values['included'],
							'invitation'  => $values['invitation'],
						),
					),
				)
			),
			true
		);

		$this->assertSame( 'No change', $rows[0]['verdict'], implode( ' | ', $rows[0]['changes'] ) );
	}

	/* Applying ______________________________________________________________ */

	public function test_apply_updates_a_reception_matched_by_slug(): void {
		$slug     = $this->slug( 'ct-drinks' );
		$event_id = $this->make_reception( $slug, array( '_law_attendee_price_pence' => 4500 ) );

		$rows = law_content_transfer_run(
			$this->bundle( array( 'receptions' => array( $this->reception_row( $slug, array( 'price' => '99.00', 'places' => 400, 'venue' => 'Somewhere else' ) ) ) ) ),
			false
		);

		$this->assertSame( 'Update', $rows[0]['verdict'], implode( ' | ', $rows[0]['changes'] ) );
		$this->assertSame( $event_id, (int) $rows[0]['event_id'], 'Matched by slug, not by ID.' );
		$this->assertSame( 9900, (int) law_event_meta( $event_id, '_law_attendee_price_pence' ) );
		$this->assertSame( 400, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
		$this->assertSame( 'Somewhere else', (string) law_event_meta( $event_id, '_law_venue' ) );
		$this->assertSame( '2026-11-30 18:30', (string) law_event_meta( $event_id, '_law_start' ) );
	}

	public function test_apply_creates_a_reception_the_far_site_does_not_have(): void {
		$slug = $this->slug( 'ct-brand-new' );
		$rows = law_content_transfer_run(
			$this->bundle( array( 'receptions' => array( $this->reception_row( $slug ) ) ) ),
			false
		);

		$this->assertSame( 'Create', $rows[0]['verdict'], implode( ' | ', $rows[0]['changes'] ) );

		$created = get_page_by_path( $slug, OBJECT, LAW_EVENT_CPT );
		$this->assertInstanceOf( WP_Post::class, $created );
		$this->posts[] = (int) $created->ID;

		$this->assertTrue( law_reception_is( (int) $created->ID ) );
		$this->assertSame( 'publish', $created->post_status, 'The show-on-the-programme tick travels.' );
		$this->assertSame( 5500, (int) law_event_meta( $created->ID, '_law_attendee_price_pence' ) );
	}

	public function test_a_slug_already_held_by_something_that_is_not_a_reception_is_refused(): void {
		$slug     = $this->slug( 'ct-not-a-reception' );
		$event_id = $this->make_event( array(), 'publish' );
		wp_update_post( array( 'ID' => $event_id, 'post_name' => $slug ) );

		$rows = law_content_transfer_run(
			$this->bundle( array( 'receptions' => array( $this->reception_row( $slug ) ) ) ),
			false
		);

		$this->assertSame( 'Skipped', $rows[0]['verdict'] );
		$this->assertFalse( law_reception_is( $event_id ), 'An ordinary event must not be turned into a reception by an import.' );
	}

	public function test_an_existing_speaker_is_matched_by_email_rather_than_duplicated(): void {
		$this->make_flagship();
		$existing = $this->make_speaker_post( 'Ali', 'Malek KC', 'ali@example.test' );

		law_content_transfer_run(
			$this->bundle(
				array(
					'flagship' => array(
						'title'    => 'Flagship conference',
						'date'     => '2026-12-02',
						'show'     => true,
						'sessions' => array(
							array(
								'title'    => 'Opening plenary',
								'start'    => '09:30',
								'end'      => '10:45',
								'speakers' => array(
									array(
										'first_name'   => 'Ali',
										'last_name'    => 'Malek KC',
										'email'        => 'ali@example.test',
										'role'         => 'moderator',
										'organisation' => '3VB',
										'job_title'    => 'Barrister',
										'bio'          => '<p>A biography.</p>',
									),
								),
							),
						),
					),
				)
			),
			false
		);

		$all = get_posts(
			array(
				'post_type'      => LAW_SPEAKER_CPT,
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'meta_key'       => '_law_speaker_email',
				'meta_value'     => 'ali@example.test',
			)
		);
		$this->assertSame( array( $existing ), array_map( 'intval', $all ), 'The speaker must be matched, not duplicated.' );

		// And the per-appearance details land on the session row, which is where
		// they belong: they are facts about this appearance, not about the person.
		$sessions = law_event_session_ids( $this->flagship );
		$this->assertCount( 1, $sessions );
		$this->posts[] = $sessions[0];
		$rows          = law_event_meta( $sessions[0], '_law_speakers' );
		$this->assertSame( $existing, (int) $rows[0]['speaker_id'] );
		$this->assertSame( 'moderator', $rows[0]['role'] );
		$this->assertSame( '3VB', $rows[0]['organisation'] );
	}

	/* Discounts _____________________________________________________________ */

	public function test_a_discount_scope_resolves_by_slug_and_drops_what_it_cannot_find(): void {
		$slug      = $this->slug( 'ct-drinks' );
		$code      = 'CT-WEEK-' . strtoupper( wp_generate_password( 6, false, false ) );
		$reception = $this->make_reception( $slug );

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'receptions' => array( $this->reception_row( $slug ) ),
					'discounts'  => array(
						array(
							'code'     => $code,
							'type'     => 'percent',
							'value'    => '25',
							'active'   => true,
							'max_uses' => 100,
							'events'   => array( $slug, 'a-reception-that-is-not-here' ),
						),
					),
				)
			),
			false
		);

		$discount = $rows[1];
		$this->assertContains( $discount['verdict'], array( 'Create', 'Update' ), implode( ' | ', $discount['changes'] ) );
		$this->assertStringContainsString( 'a-reception-that-is-not-here', implode( ' | ', $discount['changes'] ) );

		$saved = law_discount_data( law_discount_find( $code ) );
		$this->posts[] = (int) $saved['id'];
		$this->assertSame( array( $reception ), array_map( 'intval', $saved['events'] ), 'The scope must point at this site\'s own reception.' );
	}

	public function test_a_discount_usage_count_is_never_carried_across(): void {
		// A code that has been spent on staging must not arrive on production
		// already spent, and vice versa.
		$code        = 'CT-WEEK-' . strtoupper( wp_generate_password( 6, false, false ) );
		$discount_id = $this->make_discount( $code );
		update_post_meta( $discount_id, '_law_discount_used', 7 );

		$bundle = law_content_transfer_bundle();
		$row    = $this->row_by( $bundle['discounts'], 'code', $code );

		$this->assertArrayNotHasKey( 'used', $row );
	}

	/* Images ________________________________________________________________ */

	public function test_an_image_outside_the_source_uploads_folder_is_never_fetched(): void {
		// The bundle is an uploaded file, so its URLs are untrusted. The only
		// thing this server will fetch is something under the uploads folder the
		// bundle itself declares.
		$bundle = $this->bundle();
		$result = law_content_transfer_image(
			array( 'url' => 'https://somewhere-else.example.test/evil.png', 'filename' => 'evil.png' ),
			$bundle,
			'test',
			false
		);

		$this->assertSame( 0, $result['id'] );
		$this->assertStringContainsString( 'outside the source uploads folder', $result['note'] );
	}

	public function test_an_image_that_cannot_be_fetched_leaves_the_row_imported(): void {
		// A missing headshot is never a reason to abandon an agenda import.
		$this->make_flagship();

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'flagship' => array(
						'title'    => 'Flagship conference',
						'date'     => '2026-12-02',
						'show'     => true,
						'sessions' => array(
							array(
								'title'    => 'Opening plenary',
								'start'    => '09:30',
								'end'      => '10:45',
								'speakers' => array(
									array(
										'first_name' => 'Nobody',
										'last_name'  => 'Atall',
										'email'      => 'nobody@example.test',
										'photo'      => array( 'url' => 'https://elsewhere.example.test/missing.jpg', 'filename' => 'missing.jpg' ),
									),
								),
							),
						),
					),
				)
			),
			false
		);

		$this->assertNotSame( 'Failed', $rows[0]['verdict'], implode( ' | ', $rows[0]['changes'] ) );

		$sessions = law_event_session_ids( $this->flagship );
		$this->assertCount( 1, $sessions, 'The session still imported.' );
		$this->posts[] = $sessions[0];
		$speaker_rows  = law_event_meta( $sessions[0], '_law_speakers' );
		$this->assertCount( 1, $speaker_rows );
		$this->assertSame( 0, (int) $speaker_rows[0]['photo_id'], 'And it simply has no photo.' );
	}

	public function test_a_failed_banner_fetch_does_not_blank_the_one_already_there(): void {
		$event_id = $this->make_flagship();
		law_event_update_meta( $event_id, '_law_hero_image_id', 4242 );

		law_content_transfer_run(
			$this->bundle(
				array(
					'flagship' => array(
						'title'      => 'Flagship conference',
						'date'       => '2026-12-02',
						'show'       => true,
						'sessions'   => array(),
						'hero_image' => array( 'url' => 'https://elsewhere.example.test/hero.jpg', 'filename' => 'hero.jpg' ),
					),
				)
			),
			false
		);

		$this->assertSame( 4242, (int) law_event_meta( $event_id, '_law_hero_image_id' ) );
	}

	/* Scope _________________________________________________________________ */

	public function test_bookings_are_never_part_of_a_bundle(): void {
		// Staging bookings carry Stripe customer, invoice and payment-method
		// IDs from whatever Stripe account staging points at, plus one-shot
		// latches that would suppress a real confirmation email or charge.
		// A FREE reception, so no Stripe session stands between the fixture and
		// a seated place. The place is written with the engine's own insert
		// rather than through law_booking_create(): EVERY reception, priced or
		// free, is booked one place at a time through its own checkout, and the
		// free booking form refuses all of them (law_booking_guard_form_open()).
		$event_id = $this->make_reception(
			$this->slug( 'ct-drinks' ),
			array( '_law_attendee_price_pence' => 0, '_law_registration_state' => 'free' )
		);
		$booker   = $this->make_user();
		$booking  = law_booking_insert(
			$event_id,
			$booker,
			'publish',
			array( 'name' => 'Transfer Fixture', 'email' => 'transfer-fixture@example.test' )
		);
		$this->assertIsInt( $booking, 'The booking fixture must really be a booking.' );
		$this->posts[] = $booking;

		$bundle = law_content_transfer_bundle();

		// Asserted on the SHAPE rather than on a substring of the encoded file:
		// the bundle carries the whole site's receptions, so a venue or a
		// description could legitimately contain any word.
		$this->assertSame(
			array( 'format', 'version', 'generated_at', 'site', 'programme_year', 'receptions', 'flagship', 'events', 'discounts', 'emails' ),
			array_keys( $bundle ),
			'A new top-level key means a new decision about what crosses environments.'
		);

		// One deliberate exception, named rather than pattern-matched away:
		// _law_booking_override is the committee's hand on the booking switch,
		// an event-level decision with no Stripe object and no booking behind
		// it, and carrying it is what the 16 September 2026 widening was asked
		// for. Everything else matching these three words is a record of the
		// source site's own money and must not cross.
		$allowed = array( '_law_booking_override' );
		$keys    = array();
		array_walk_recursive( $bundle, function ( $value, $key ) use ( &$keys ) { $keys[ (string) $key ] = true; } );
		foreach ( array_keys( $keys ) as $key ) {
			if ( in_array( $key, $allowed, true ) ) {
				continue;
			}
			$this->assertStringNotContainsStringIgnoringCase( 'booking', $key );
			$this->assertStringNotContainsStringIgnoringCase( 'stripe', $key );
			$this->assertStringNotContainsStringIgnoringCase( 'payment', $key );
		}

		$this->assertGreaterThan( 0, $booking, 'The booking really was created.' );
		$this->assertSame( $event_id, (int) get_post_field( 'post_parent', $booking ), 'And it hangs off the reception that is in the bundle.' );
	}

	/* The gates, and the upload itself ______________________________________ */

	public function test_both_halves_are_gated_on_manage_options_and_a_nonce(): void {
		// The panel is reached from a manage_options page and re-checks anyway,
		// which is the habit the rest of the module keeps. A refactor that drops
		// either check should fail here rather than in production.
		$file = file_get_contents( get_theme_file_path( 'functions/events/migration/content-transfer.php' ) );

		$this->assertStringContainsString( "check_admin_referer( 'law_content_transfer_export' )", $file, 'The export must verify its nonce.' );
		$this->assertStringContainsString( "check_admin_referer( 'law_content_transfer', 'law_content_transfer_nonce' )", $file, 'The import must verify its nonce.' );
		$this->assertSame( 2, substr_count( $file, "current_user_can( 'manage_options' )" ), 'The export handler and the panel each gate themselves.' );

		// And the panel renders nothing at all for somebody who is not an admin.
		wp_set_current_user( $this->make_user() );
		ob_start();
		law_content_transfer_panel();
		$this->assertSame( '', trim( (string) ob_get_clean() ), 'A non-administrator must see no panel.' );
	}

	public function test_the_upload_is_refused_when_it_is_not_an_upload_or_is_too_large(): void {
		$_FILES = array();
		$this->assertWPError( law_content_transfer_read_upload(), 'law_ct_no_file' );

		// A path that PHP did not receive as an upload. Without is_uploaded_file()
		// this would read any file the web user can reach.
		$_FILES = array( 'law_ct_file' => array( 'error' => 0, 'size' => 10, 'tmp_name' => '/etc/hostname' ) );
		$this->assertWPError( law_content_transfer_read_upload(), 'law_ct_not_an_upload' );

		$_FILES = array( 'law_ct_file' => array( 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0, 'tmp_name' => '' ) );
		$this->assertWPError( law_content_transfer_read_upload(), 'law_ct_upload_error' );

		$_FILES = array(
			'law_ct_file' => array( 'error' => 0, 'size' => LAW_CONTENT_TRANSFER_MAX_BYTES + 1, 'tmp_name' => '/tmp/whatever' ),
		);
		$this->assertWPError( law_content_transfer_read_upload(), 'law_ct_too_big' );
	}

	public function test_apply_without_a_preview_writes_nothing(): void {
		// The Apply button reads the bundle out of a one-hour per-user transient
		// so the file need not be uploaded twice. An Apply that arrives after it
		// has expired must say so rather than reach a half-remembered bundle.
		$admin = $this->make_user( 'administrator' );
		wp_set_current_user( $admin );
		delete_transient( law_content_transfer_transient_key() );

		$_POST = array(
			'law_content_transfer_nonce' => wp_create_nonce( 'law_content_transfer' ),
			'law_ct_action'              => 'apply',
		);
		$_REQUEST = $_POST;

		ob_start();
		law_content_transfer_panel();
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'The preview has expired', $html );
		$this->assertStringNotContainsString( 'Apply this import', $html, 'And it offers no Apply button to press again.' );
	}

	public function test_the_preview_transient_is_scoped_to_one_user(): void {
		$one = $this->make_user( 'administrator' );
		$two = $this->make_user( 'administrator' );

		wp_set_current_user( $one );
		$key_one = law_content_transfer_transient_key();
		wp_set_current_user( $two );

		$this->assertNotSame( $key_one, law_content_transfer_transient_key() );
		$this->assertStringContainsString( (string) $two, law_content_transfer_transient_key() );
	}

	public function test_a_fetched_file_that_is_not_an_image_is_refused(): void {
		// The bytes decide, not the extension: the URL came out of an uploaded
		// file, so something named .jpg that is really a PDF must not be stored
		// as a speaker photograph. Driven through a real file this site already
		// serves, so the fetch and wp_check_filetype_and_ext() both really run.
		$uploads = wp_upload_dir();
		$pdf     = glob( trailingslashit( $uploads['basedir'] ) . '*.pdf' );
		if ( ! $pdf ) {
			$this->markTestSkipped( 'No non-image file in the uploads folder to fetch.' );
		}

		$url    = trailingslashit( $uploads['baseurl'] ) . basename( $pdf[0] );
		$bundle = $this->bundle( array( 'site' => array( 'url' => home_url( '/' ), 'uploads_baseurl' => trailingslashit( $uploads['baseurl'] ) ) ) );
		$result = law_content_transfer_image(
			array( 'url' => $url, 'filename' => 'pretending-to-be.jpg' ),
			$bundle,
			'test',
			false
		);

		$this->assertSame( 0, $result['id'], 'Non-image bytes must never reach the media library.' );
	}

	public function test_a_flagship_scoped_discount_keeps_its_scope(): void {
		// The flagship started accepting codes on 15 September 2026. An export
		// that only carried reception scopes would have dropped those silently,
		// which is why the rule is "an event LAW manages itself", not "a
		// reception".
		$flagship = $this->make_flagship();
		$code     = 'CT-FLAG-' . strtoupper( wp_generate_password( 6, false, false ) );
		$discount = $this->make_discount( $code );
		law_event_update_meta( $discount, '_law_discount_events', array( $flagship ) );

		// The fixture's own slug, not the constant: the local database already
		// holds the real flagship, so this one is provisioned as "flagship-2".
		// On a real site the two are the same string.
		$slug = (string) get_post_field( 'post_name', $flagship );
		$row  = $this->row_by( law_content_transfer_bundle()['discounts'], 'code', $code );
		$this->assertSame( array( $slug ), $row['events'] );

		// And it resolves back to this site's own flagship on the way in.
		$rows = law_content_transfer_run(
			$this->bundle( array( 'discounts' => array( array( 'code' => $code, 'type' => 'percent', 'value' => '25', 'active' => true, 'events' => array( $slug ) ) ) ) ),
			false
		);
		$this->assertNotSame( 'Failed', $rows[0]['verdict'], implode( ' | ', $rows[0]['changes'] ) );
		$this->assertSame( array( $flagship ), array_map( 'intval', law_discount_data( $discount )['events'] ) );
	}

	/* Email wording __________________________________________________________ */

	public function test_email_wording_travels_but_recipients_never_do(): void {
		// The asymmetry that decided this: dropping a typed address list costs
		// somebody re-typing four addresses, while carrying one can point a live
		// production notification at a staging test mailbox, silently.
		$slug = $this->an_email_with_a_typed_recipient_list();

		$this->set_override(
			$slug,
			array(
				'subject' => 'Polished subject',
				'body'    => 'Polished body.',
				'active'  => true,
				'to'      => array( 'staging-inbox@example.test' ),
			)
		);

		$row = $this->row_by( law_content_transfer_bundle()['emails'], 'slug', $slug );

		$this->assertSame( 'Polished subject', $row['subject'] );
		$this->assertSame( 'Polished body.', $row['body'] );
		$this->assertTrue( $row['active'] );
		$this->assertArrayNotHasKey( 'to', $row, 'A recipient list must never leave the site it was typed on.' );
	}

	public function test_an_import_keeps_the_recipients_this_site_already_had(): void {
		$slug = $this->an_email_with_a_typed_recipient_list();
		$this->set_override( $slug, array( 'subject' => 'Old', 'body' => 'Old body.', 'active' => true, 'to' => array( 'production-team@example.test' ) ) );

		law_content_transfer_run(
			$this->bundle( array( 'emails' => array( array( 'slug' => $slug, 'subject' => 'New', 'body' => 'New body.', 'active' => true ) ) ) ),
			false
		);

		$stored = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION );
		$this->assertSame( 'New', $stored[ $slug ]['subject'], 'The wording is taken from the bundle.' );
		$this->assertSame( array( 'production-team@example.test' ), $stored[ $slug ]['to'], 'The recipients are left exactly as they were.' );
	}

	public function test_only_customised_emails_travel(): void {
		// 78 emails ship in the registry as code. Exporting all of them would
		// make every deploy's wording change look like an incoming edit.
		$slug = $this->an_email_with_a_typed_recipient_list();
		$this->set_override( $slug, array( 'subject' => 'Only this one', 'body' => 'Body.', 'active' => true ) );

		$slugs = array_column( law_content_transfer_bundle()['emails'], 'slug' );

		$this->assertSame( array( $slug ), $slugs );
		$this->assertGreaterThan( 50, count( law_events_email_registry() ), 'Precondition: the registry is much larger than the export.' );
	}

	public function test_an_email_this_site_does_not_define_is_skipped(): void {
		// A bundle from a site running different code. That is a deploy problem,
		// and writing an override nothing will ever read would only hide it.
		$rows = law_content_transfer_run(
			$this->bundle( array( 'emails' => array( array( 'slug' => 'no_such_email_anywhere', 'subject' => 'x', 'body' => 'y', 'active' => true ) ) ) ),
			false
		);

		$this->assertSame( 'Skipped', $rows[0]['verdict'] );
		$this->assertStringContainsString( 'not an email this site defines', implode( ' ', $rows[0]['changes'] ) );
		$this->assertArrayNotHasKey( 'no_such_email_anywhere', (array) get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() ) );
	}

	public function test_an_email_already_saying_the_same_thing_is_no_change(): void {
		// Diffed against the EFFECTIVE email, so wording that matches the code
		// default reports No change rather than writing a pointless override.
		$slug     = array_key_first( law_events_email_registry() );
		$registry = law_events_email_registry()[ $slug ];

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'emails' => array(
						array( 'slug' => $slug, 'subject' => $registry['subject'], 'body' => $registry['body'], 'active' => ! empty( $registry['active'] ) ),
					),
				)
			),
			false
		);

		$this->assertSame( 'No change', $rows[0]['verdict'], implode( ' | ', $rows[0]['changes'] ) );
		$this->assertArrayNotHasKey( $slug, (array) get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() ) );
	}

	public function test_a_body_rewrite_is_reported_at_the_line_that_changed(): void {
		// An email body is paragraphs, and two rewrites routinely share their
		// first 80 characters. The generic truncating diff would print the same
		// string twice and read as though nothing had changed.
		$before = "Dear {host_name},\n\nThank you for submitting your event.\n\nThe committee will be in touch.";
		$after  = "Dear {host_name},\n\nThank you for submitting your event.\n\nThe committee will respond within five working days.";

		$line = law_content_transfer_body_change( $before, $after );

		$this->assertStringContainsString( 'line 5', $line );
		$this->assertStringContainsString( 'five working days', $line );
		$this->assertStringNotContainsString( 'Dear {host_name}', $line, 'The unchanged opening is not what the operator needs to see.' );
	}

	public function test_a_dry_run_does_not_write_email_wording(): void {
		$slug   = $this->an_email_with_a_typed_recipient_list();
		$before = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );

		$rows = law_content_transfer_run(
			$this->bundle( array( 'emails' => array( array( 'slug' => $slug, 'subject' => 'Previewed only', 'body' => 'Not written.', 'active' => true ) ) ) ),
			true
		);

		$this->assertSame( 'Create', $rows[0]['verdict'] );
		$this->assertSame( $before, get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() ) );
	}

	public function test_emails_are_applied_after_everything_that_can_send_one(): void {
		// Ordering: a reception going on sale or a discount changing can fire an
		// email. Writing the wording first would send this run's own
		// notifications in words the operator had not yet approved.
		$slug = $this->an_email_with_a_typed_recipient_list();
		$this->set_override( $slug, array( 'subject' => 'x', 'body' => 'y', 'active' => true ) );

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'receptions' => array( $this->reception_row( $this->slug( 'ct-drinks' ) ) ),
					'emails'     => array( array( 'slug' => $slug, 'subject' => 'z', 'body' => 'w', 'active' => true ) ),
				)
			),
			true
		);

		$kinds = array_column( $rows, 'kind' );
		$this->assertSame( 'Email', end( $kinds ) );
		$this->assertSame( 'Reception', $kinds[0] );
	}

	/* The archive (format version 2) _________________________________________ */

	public function test_an_image_travels_inside_the_archive_and_is_imported_from_it(): void {
		// The round trip that the whole of version 2 exists for, driven end to
		// end: a real attachment is exported into a real zip, the zip is read
		// back the way an upload would be, and the photograph arrives with NO
		// network access at any point. That last part is the requirement — LAW
		// staging sits behind HTTP basic auth, where the version 1 fetch cannot
		// work — so the test blocks HTTP outright and still expects a photo.
		$this->use_temp_uploads();
		$attachment = $this->make_image_attachment( 'headshot.png' );
		$url        = wp_get_attachment_url( $attachment );

		$bundle = $this->bundle(
			array(
				'site'     => array( 'url' => home_url( '/' ), 'uploads_baseurl' => trailingslashit( wp_upload_dir()['baseurl'] ) ),
				'flagship' => null,
			)
		);
		$bundle['photo'] = law_content_transfer_attachment( $attachment );

		$this->assertArrayHasKey( 'archive', $bundle['photo'], 'An attachment with a file on disk must be packaged.' );
		$this->assertArrayNotHasKey( 'source', $bundle['photo'], 'And its local server path must never travel.' );

		$zip = law_content_transfer_zip( $bundle, 'test-bundle' );
		$this->assertIsString( $zip, 'The archive was written.' );
		$this->files[] = $zip;

		// Read it back exactly as an upload is read, then import the photo with
		// every outbound request refused.
		$read = law_content_transfer_read_archive( $zip );
		$this->assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
		$this->dirs[] = (string) $read['archive_dir'];

		add_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10, 3 );
		$result = law_content_transfer_image( $bundle['photo'], $read, 'test', false );
		remove_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10 );

		$this->assertGreaterThan( 0, $result['id'], 'The photograph must come out of the archive: ' . $result['note'] );
		$this->posts[] = $result['id'];

		// And the URL stays the identity, so a re-import reuses this attachment
		// rather than filling the media library with copies.
		$this->assertSame( $url, get_post_meta( $result['id'], LAW_CONTENT_TRANSFER_SOURCE_META, true ) );
	}

	public function test_the_bundle_carries_no_server_paths(): void {
		// The sibling of test_the_bundle_carries_no_local_ids: an absolute
		// filesystem path is as unportable as a post ID, and leaks this server's
		// layout to whoever opens the file.
		$this->make_flagship();
		$found = array();
		$this->walk(
			law_content_transfer_bundle(),
			function ( $key, $value ) use ( &$found ) {
				if ( is_string( $value ) && '' !== ABSPATH && str_contains( $value, ABSPATH ) ) {
					$found[] = $key . ' = ' . $value;
				}
			}
		);
		$this->assertSame( array(), $found, 'No value in a bundle may contain a server path.' );
	}

	/**
	 * @dataProvider hostile_entry_names
	 */
	public function test_an_archive_entry_that_escapes_the_images_folder_is_refused( string $name ): void {
		// Entry names come out of an uploaded file, so they are attacker-chosen.
		// This is why nothing in the importer calls ZipArchive::extractTo(),
		// which honours whatever names an archive carries.
		$this->assertFalse( law_content_transfer_safe_entry( $name ), sprintf( '"%s" must not be treated as a readable entry.', $name ) );
	}

	public static function hostile_entry_names(): array {
		return array(
			'traversal'             => array( '../../wp-config.php' ),
			'traversal inside'      => array( 'images/../../../wp-config.php' ),
			'absolute'              => array( '/etc/passwd' ),
			'absolute inside'       => array( 'images//etc/passwd' ),
			'parent as leaf'        => array( 'images/..' ),
			'current as leaf'       => array( 'images/.' ),
			'windows separator'     => array( 'images\\..\\..\\wp-config.php' ),
			'null byte'             => array( "images/ok.png\0.php" ),
			'nested'                => array( 'images/deeper/photo.png' ),
			'directory entry'       => array( 'images/' ),
			'outside images'        => array( 'bundle.json.bak' ),
			'prefix lookalike'      => array( 'images-evil/photo.png' ),
		);
	}

	public function test_an_ordinary_image_entry_is_accepted(): void {
		// The other half of the allowlist: it has to still let the real thing in.
		$this->assertTrue( law_content_transfer_safe_entry( 'images/41-headshot.png' ) );
	}

	public function test_an_archive_that_unpacks_too_big_is_refused_before_anything_is_written(): void {
		// A zip's compression ratio is chosen by whoever made it, so the upload
		// size cap says nothing about what unpacking costs. The declared total is
		// read from the archive's own directory first. Driven against a lowered
		// cap rather than a quarter-gigabyte fixture.
		$this->use_temp_uploads();
		$zip  = $this->write_zip(
			array(
				'bundle.json'      => (string) wp_json_encode( $this->bundle() ),
				'images/zeros.png' => str_repeat( "\0", 200000 ),
			)
		);
		$before = $this->workdir_count();

		add_filter( 'law_content_transfer_max_unzipped', fn() => 1000 );
		$result = law_content_transfer_read_archive( $zip );
		remove_all_filters( 'law_content_transfer_max_unzipped' );

		$this->assertWPError( $result, 'law_ct_zip_bomb' );
		$this->assertSame( $before, $this->workdir_count(), 'Nothing may be extracted before the archive is judged.' );
	}

	public function test_the_two_formats_are_told_apart_by_their_bytes(): void {
		// Not by the extension, which the uploader chooses. A bundle somebody
		// renamed still imports, and a .zip full of anything else still gets the
		// archive reader's refusals rather than a JSON parse error.
		$zip  = $this->write_zip( array( 'bundle.json' => '{}' ) );
		$json = wp_tempnam( 'bundle.json' );
		$this->files[] = $json;
		file_put_contents( $json, (string) wp_json_encode( $this->bundle() ) );

		$this->assertTrue( law_content_transfer_is_archive( $zip ) );
		$this->assertFalse( law_content_transfer_is_archive( $json ) );
		$this->assertFalse( law_content_transfer_is_archive( '/no/such/file' ), 'An unreadable path is not an archive.' );
	}

	public function test_an_archive_with_no_manifest_is_refused(): void {
		$result = law_content_transfer_read_archive( $this->write_zip( array( 'images/a.png' => 'x' ) ) );
		$this->assertWPError( $result, 'law_ct_no_manifest' );
	}

	public function test_a_non_image_carried_inside_the_archive_is_refused(): void {
		// The same gate the fetch route has, on the same bytes: something named
		// .png that is really PHP must not become a speaker photograph just
		// because it travelled inside the file rather than over HTTP.
		$this->use_temp_uploads();
		$uploads = trailingslashit( wp_upload_dir()['baseurl'] );
		$bundle  = $this->bundle( array( 'site' => array( 'url' => home_url( '/' ), 'uploads_baseurl' => $uploads ) ) );

		$zip  = $this->write_zip(
			array(
				'bundle.json'         => (string) wp_json_encode( $bundle ),
				'images/9-not.png' => "<?php echo 'not an image'; ?>",
			)
		);
		$read = law_content_transfer_read_archive( $zip );
		$this->assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );
		$this->dirs[] = (string) $read['archive_dir'];

		add_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10, 3 );
		$result = law_content_transfer_image(
			array( 'url' => $uploads . 'not.png', 'filename' => 'not.png', 'archive' => 'images/9-not.png' ),
			$read,
			'test',
			false
		);
		remove_filter( 'pre_http_request', array( $this, 'refuse_http' ), 10 );

		$this->assertSame( 0, $result['id'], 'Non-image bytes must never reach the media library.' );
	}

	public function test_a_version_1_bundle_still_takes_the_fetch_route(): void {
		// Bundles written before the archive existed keep working, and the dry
		// run says which route each image will take, so the difference is
		// visible before anything is written.
		$uploads = trailingslashit( wp_upload_dir()['baseurl'] );
		$bundle  = $this->bundle( array( 'site' => array( 'url' => home_url( '/' ), 'uploads_baseurl' => $uploads ) ) );

		$fetched = law_content_transfer_image( array( 'url' => $uploads . 'old.jpg', 'filename' => 'old.jpg' ), $bundle, 'test', true );
		$this->assertStringContainsString( 'fetched from the source site', $fetched['note'] );

		$bundle['archive_dir'] = sys_get_temp_dir();
		$packed = law_content_transfer_image( array( 'url' => $uploads . 'new.jpg', 'filename' => 'new.jpg', 'archive' => 'images/1-new.jpg' ), $bundle, 'test', true );
		$this->assertStringContainsString( 'fetched from the source site', $packed['note'], 'An archive entry that is not on disk falls back rather than claiming to be packaged.' );
	}

	public function test_an_image_on_a_source_site_that_dns_cannot_resolve_still_imports_from_the_archive(): void {
		// The regression this whole change is about. law_content_transfer_media_base()
		// runs wp_http_validate_url(), which refuses a private or unresolvable
		// host — right before a fetch, and fatal here if the identity check used
		// it, because a source site behind basic auth or on a private network is
		// exactly the case the archive serves.
		$base = 'https://staging.internal/wp-content/uploads/';

		$this->assertSame( '', law_content_transfer_validate_media_base( $base ), 'Precondition: that host is not fetchable.' );
		$this->assertSame(
			$base,
			law_content_transfer_identity_base( $this->bundle( array( 'site' => array( 'url' => 'https://staging.internal/', 'uploads_baseurl' => $base ) ) ) ),
			'But it is still a valid identity prefix, so its images are not thrown away.'
		);
	}

	public function test_the_working_directory_is_cleared(): void {
		$this->use_temp_uploads();
		$read = law_content_transfer_read_archive(
			$this->write_zip( array( 'bundle.json' => (string) wp_json_encode( $this->bundle() ), 'images/1-a.png' => 'x' ) )
		);
		$this->assertIsArray( $read, is_wp_error( $read ) ? $read->get_error_message() : '' );

		$dir = (string) $read['archive_dir'];
		$this->assertDirectoryExists( $dir );

		law_content_transfer_clear_workdir();
		$this->assertDirectoryDoesNotExist( $dir, 'Extracted images live exactly as long as the decision they belong to.' );
	}

	/* Hosted and external events (16 September 2026) ________________________ */

	public function test_an_event_is_matched_by_its_gravity_forms_entry_id(): void {
		// The stronger of the two keys, and the reason it is stronger: the title
		// and the slug have both moved since, and the row still finds its event.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90210, '_law_venue' => 'The old hall' ) );
		wp_update_post( array( 'ID' => $event_id, 'post_name' => $this->slug( 'ct-renamed' ), 'post_title' => 'Renamed here' ) );

		$rows = law_content_transfer_run(
			$this->bundle( array( 'events' => array( $this->event_row( array( 'gf_entry_id' => 90210, 'slug' => 'a-slug-this-site-never-had', 'title' => 'Arbitration after lunch', 'meta' => array( '_law_venue' => 'The new hall' ) ) ) ) ) ),
			false,
			0
		);

		$this->assertSame( 'Update', $rows[0]['verdict'] );
		$this->assertSame( $event_id, (int) $rows[0]['event_id'], 'The entry ID found the event whose title and slug had both changed.' );
		$this->assertSame( 'The new hall', (string) law_event_meta( $event_id, '_law_venue' ) );
		$this->assertSame( 'Arbitration after lunch', (string) get_post_field( 'post_title', $event_id ) );
	}

	public function test_the_override_booking_availability_switch_travels(): void {
		// The key this whole widening was asked for (client, 16 September 2026).
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90211, '_law_booking_override' => 'auto' ) );

		law_content_transfer_run(
			$this->bundle( array( 'events' => array( $this->event_row( array( 'gf_entry_id' => 90211, 'meta' => array( '_law_booking_override' => 'enable' ) ) ) ) ) ),
			false,
			0
		);

		$this->assertSame( 'enable', law_event_booking_override( $event_id ) );
	}

	public function test_a_hosted_events_workflow_status_is_never_imported(): void {
		// An approval is an act, not a value: law_event_workflow_side_effects()
		// snapshots the fee, creates the co-owner accounts, raises the Stripe
		// invoice and emails the host. A file upload can do none of that, so it
		// may not write the status either — it reports the disagreement instead.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90212 ), 'law-proposed' );

		$rows = law_content_transfer_run(
			$this->bundle( array( 'events' => array( $this->event_row( array( 'gf_entry_id' => 90212, 'status' => 'law-approved' ) ) ) ) ),
			false,
			0
		);

		$this->assertSame( 'law-proposed', get_post_status( $event_id ), 'The workflow status is the committee dashboard\'s to change, never an import\'s.' );
		$this->assertNotEmpty(
			preg_grep( '/Status left alone/', $rows[0]['changes'] ),
			'And the operator is told, so they can go and approve it properly: ' . implode( ' | ', $rows[0]['changes'] )
		);
	}

	public function test_an_external_events_programme_tick_does_travel(): void {
		// No workflow behind it: publish and law-draft mean "on the programme"
		// and "not yet", exactly as a reception's do.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90213, '_law_is_external' => 1 ), 'publish' );

		law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90213, 'external' => true, 'status' => 'law-draft', 'meta' => array( '_law_is_external' => 1 ) ) ),
					),
				)
			),
			false,
			0
		);

		$this->assertSame( 'law-draft', get_post_status( $event_id ) );
	}

	public function test_an_event_the_bundle_does_not_name_is_never_touched(): void {
		// Denis, 16 September 2026: the import is an overlay on top of the
		// Gravity Forms migration, not a mirror of the source site.
		$named    = $this->make_event( array( '_law_gf_entry_id' => 90214, '_law_venue' => 'Old' ) );
		$bystander = $this->make_event( array( '_law_gf_entry_id' => 90215, '_law_venue' => 'Untouched' ), 'law-approved' );

		law_content_transfer_run(
			$this->bundle( array( 'events' => array( $this->event_row( array( 'gf_entry_id' => 90214, 'meta' => array( '_law_venue' => 'New' ) ) ) ) ) ),
			false,
			0
		);

		$this->assertSame( 'New', (string) law_event_meta( $named, '_law_venue' ) );
		$this->assertSame( 'Untouched', (string) law_event_meta( $bystander, '_law_venue' ), 'An event the file does not name is left exactly where it was.' );
		$this->assertSame( 'law-approved', get_post_status( $bystander ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $bystander ), 'And nothing is ever deleted.' );
	}

	public function test_an_event_this_site_does_not_have_is_created_with_its_owner_matched_by_email(): void {
		$owner = $this->make_user();
		$email = (string) get_userdata( $owner )->user_email;

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row(
							array(
								'gf_entry_id' => 0,
								'slug'        => $this->slug( 'ct-brand-new' ),
								'title'       => 'A brand new listing',
								'owner_email' => $email,
								'status'      => 'law-draft',
							)
						),
					),
				)
			),
			false,
			0
		);

		$this->assertSame( 'Create', $rows[0]['verdict'] );
		$created = (int) $rows[0]['event_id'];
		$this->posts[] = $created;
		$this->assertGreaterThan( 0, $created );
		$this->assertSame( $owner, (int) get_post_field( 'post_author', $created ), 'People travel as email addresses, because user IDs do not survive the move.' );
		$this->assertSame( 'law-draft', get_post_status( $created ) );
	}

	public function test_an_owner_with_no_account_here_is_reported_rather_than_created(): void {
		// An import never creates a login (Denis, 16 September 2026).
		$before = count_users()['total_users'];

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90216, 'slug' => $this->slug( 'ct-orphan' ), 'owner_email' => 'nobody-here@example.test' ) ),
					),
				)
			),
			true,
			0
		);

		$this->assertNotEmpty( preg_grep( '/has no account on this site/', $rows[0]['changes'] ) );
		$this->assertSame( $before, count_users()['total_users'], 'No account was invented.' );
	}

	public function test_a_dry_run_writes_no_event(): void {
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90217, '_law_venue' => 'Unchanged' ) );
		$counts   = $this->post_counts();

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90217, 'title' => 'Would be renamed', 'meta' => array( '_law_venue' => 'Would move' ) ) ),
						$this->event_row( array( 'gf_entry_id' => 0, 'slug' => $this->slug( 'ct-would-create' ) ) ),
					),
				)
			),
			true,
			0
		);

		$this->assertSame( 'Update', $rows[0]['verdict'] );
		$this->assertSame( 'Create', $rows[1]['verdict'] );
		$this->assertSame( 'Unchanged', (string) law_event_meta( $event_id, '_law_venue' ) );
		$this->assertSame( $counts, $this->post_counts(), 'A preview writes nothing at all.' );
	}

	public function test_a_slug_held_by_a_reception_is_refused(): void {
		// The receptions and the flagship have keys and savers of their own.
		$slug = $this->slug( 'ct-drinks' );
		$this->make_reception( $slug );

		$rows = law_content_transfer_run(
			$this->bundle( array( 'events' => array( $this->event_row( array( 'gf_entry_id' => 0, 'slug' => $slug ) ) ) ) ),
			false,
			0
		);

		$this->assertSame( 'Skipped', $rows[0]['verdict'] );
	}

	public function test_a_bundle_written_before_events_travelled_leaves_them_alone(): void {
		// A version 2 file has no `events` key at all, which is not an error.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90218, '_law_venue' => 'Still here' ) );

		law_content_transfer_run( $this->bundle( array( 'version' => 2 ) ), false, 0 );

		$this->assertSame( 'Still here', (string) law_event_meta( $event_id, '_law_venue' ) );
	}

	public function test_descriptive_data_only_nothing_about_money_or_the_workflow(): void {
		// Denis, 17 September 2026: by the time a bundle is imported, production's
		// events have been approved, invoiced, paid and confirmed for real, and a
		// file made where the same events were test data may not touch any of it.
		// What an event IS travels; what it has BEEN THROUGH does not.
		$event_id = $this->make_event(
			array(
				'_law_gf_entry_id'         => 90219,
				'_law_fee_pence'           => 95000,
				'_law_payment_status'      => 'paid',
				'_law_stripe_invoice_id'   => 'in_staging_only',
				'_law_stripe_customer_id'  => 'cus_staging_only',
				'_law_fee_tier'            => 'uk',
				'_law_fee_override'        => 1,
				'_law_fee_override_amount' => 12.50,
				'_law_approved_at'         => '2026-08-01',
				'_law_invoice_name'        => 'Billing Ltd',
				'_law_vat_number'          => 'GB111111111',
			)
		);

		$row = $this->row_by( law_content_transfer_events(), 'gf_entry_id', '90219' );

		foreach (
			array(
				// Payment and Stripe.
				'_law_fee_pence', '_law_vat', '_law_payment_status',
				'_law_stripe_invoice_id', '_law_stripe_customer_id', '_law_stripe_invoice_url',
				// The fee DECISION as well as the snapshot: nothing recalculates
				// the snapshot after approval, so an imported tier would move the
				// admin Fee column and the exports while the invoice kept the old
				// figure.
				'_law_fee_tier', '_law_fee_override', '_law_fee_override_amount',
				// Who was billed.
				'_law_invoice_name', '_law_invoice_email', '_law_invoice_address',
				'_law_country_iso', '_law_vat_number',
				// Decisions that happened on a particular site.
				'_law_approved_at', '_law_rejection_reason', '_law_cancellation_reason',
				'_law_terms_consent',
				// Recounts and derived links.
				'_law_tickets_sold', '_law_co_owner_ids',
			) as $key
		) {
			$this->assertArrayNotHasKey( $key, $row['meta'], $key . ' must not cross a site boundary.' );
		}

		// And what an event IS still does.
		foreach ( array( '_law_start', '_law_venue', '_law_venue_capacity', '_law_booking_override', '_law_host_organisations' ) as $key ) {
			$this->assertArrayHasKey( $key, $row['meta'], $key . ' is descriptive and must travel.' );
		}

		$this->assertGreaterThan( 0, $event_id );
	}

	public function test_a_paid_and_confirmed_event_keeps_its_money_and_its_status(): void {
		// The whole of Denis's concern, in one assertion set.
		$event_id = $this->make_event(
			array(
				'_law_gf_entry_id'        => 90250,
				'_law_payment_status'     => 'paid',
				'_law_fee_pence'          => 95000,
				'_law_fee_tier'           => 'uk',
				'_law_stripe_invoice_id'  => 'in_production_real',
				'_law_approved_at'        => '2026-08-01',
				'_law_invoice_name'       => 'Production Billing Ltd',
			),
			'publish'
		);

		law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row(
							array(
								'gf_entry_id' => 90250,
								'status'      => 'law-proposed',
								'title'       => 'Retitled on staging',
								'meta'        => array(
									'_law_venue' => 'Staging venue',
									// Every one of these is off the allow list, so a
									// hand-edited bundle naming them reaches nothing.
									'_law_payment_status'     => 'unpaid',
									'_law_fee_pence'          => 1,
									'_law_fee_tier'           => 'sponsor',
									'_law_fee_override'       => 1,
									'_law_fee_override_amount' => 1.00,
									'_law_stripe_invoice_id'  => 'in_staging_fake',
									'_law_approved_at'        => '2026-01-01',
									'_law_invoice_name'       => 'Staging Test Co',
								),
							)
						),
					),
				)
			),
			false,
			0
		);

		$this->assertSame( 'publish', get_post_status( $event_id ), 'Confirmed stays confirmed.' );
		$this->assertSame( 'paid', (string) law_event_meta( $event_id, '_law_payment_status' ) );
		$this->assertSame( 95000, (int) law_event_meta( $event_id, '_law_fee_pence' ) );
		$this->assertSame( 'uk', (string) law_event_meta( $event_id, '_law_fee_tier' ) );
		$this->assertSame( '', (string) law_event_meta( $event_id, '_law_fee_override' ) );
		$this->assertSame( 'in_production_real', (string) law_event_meta( $event_id, '_law_stripe_invoice_id' ) );
		$this->assertSame( '2026-08-01', (string) law_event_meta( $event_id, '_law_approved_at' ) );
		$this->assertSame( 'Production Billing Ltd', (string) law_event_meta( $event_id, '_law_invoice_name' ) );

		// The descriptive half did travel, or the run would have been a no-op.
		$this->assertSame( 'Retitled on staging', (string) get_post_field( 'post_title', $event_id ) );
		$this->assertSame( 'Staging venue', (string) law_event_meta( $event_id, '_law_venue' ) );
	}

	public function test_places_available_travel_even_on_an_event_with_bookings(): void {
		// Denis, 17 September 2026: places travel, full stop. I had made the
		// number conditional on nobody having booked; he overrode that.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90251, '_law_tickets_available' => 60 ), 'publish' );
		$booking  = law_booking_insert( $event_id, $this->make_user(), 'publish', array( 'name' => 'Seated', 'email' => 'seated-ct@example.test' ) );
		$this->posts[] = $booking;
		law_event_recount_attendees( $event_id );
		$this->assertSame( 1, law_event_attendee_total( $event_id ), 'The fixture really seats somebody.' );

		law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90251, 'meta' => array( '_law_tickets_available' => 120 ) ) ),
					),
				)
			),
			false,
			0
		);

		$this->assertSame( 120, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
	}

	public function test_a_places_change_goes_through_the_modules_own_path(): void {
		// Writing the meta alone would leave an event whose places doubled still
		// holding a queue the module promises to seat automatically. The import
		// calls law_event_tickets_changed(), so it logs the change and runs the
		// waitlist exactly as a committee member typing the number would.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90253, '_law_tickets_available' => 1 ), 'publish' );
		$seated   = law_booking_insert( $event_id, $this->make_user(), 'publish', array( 'name' => 'Seated', 'email' => 'seated-wl@example.test' ) );
		$waiting  = law_booking_insert( $event_id, $this->make_user(), 'law-waitlisted', array( 'name' => 'Waiting', 'email' => 'waiting-wl@example.test' ) );
		$this->posts[] = $seated;
		$this->posts[] = $waiting;
		law_event_recount_attendees( $event_id );
		$this->assertSame( 1, law_waitlist_count( $event_id ), 'The fixture really has somebody waiting.' );

		law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90253, 'meta' => array( '_law_tickets_available' => 10 ) ) ),
					),
				)
			),
			false,
			0
		);

		$this->assertSame( 10, (int) law_event_meta( $event_id, '_law_tickets_available' ) );
		$this->assertSame( 'publish', get_post_status( $waiting ), 'The place that opened was given to the person waiting for it.' );
		$this->assertSame( 0, law_waitlist_count( $event_id ) );
	}

	public function test_the_preview_says_when_a_drop_puts_an_event_below_its_bookings(): void {
		// Places travel whatever the number, so the operator is told when the
		// number they are about to apply is smaller than the people already on it.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90255, '_law_tickets_available' => 60 ), 'publish' );
		foreach ( array( 'a', 'b' ) as $who ) {
			$booking       = law_booking_insert( $event_id, $this->make_user(), 'publish', array( 'name' => 'Seated ' . $who, 'email' => 'seated-' . $who . '-ct@example.test' ) );
			$this->posts[] = $booking;
		}
		law_event_recount_attendees( $event_id );
		$this->assertSame( 2, law_event_attendee_total( $event_id ) );

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90255, 'meta' => array( '_law_tickets_available' => 1 ) ) ),
					),
				)
			),
			true,
			0
		);

		$this->assertNotEmpty(
			preg_grep( '/below the 2 already booked/', $rows[0]['changes'] ),
			implode( ' | ', $rows[0]['changes'] )
		);
	}

	public function test_the_preview_says_a_raise_will_seat_and_email_the_waitlist(): void {
		// The one outward-facing thing an import does to people who are not the
		// operator, so it is named rather than left as a number change.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90254, '_law_tickets_available' => 1 ), 'publish' );
		$waiting  = law_booking_insert( $event_id, $this->make_user(), 'law-waitlisted', array( 'name' => 'Waiting', 'email' => 'waiting-preview@example.test' ) );
		$this->posts[] = $waiting;

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90254, 'meta' => array( '_law_tickets_available' => 10 ) ) ),
					),
				)
			),
			true,
			0
		);

		$this->assertNotEmpty(
			preg_grep( '/will seat 1 person from the waitlist/', $rows[0]['changes'] ),
			implode( ' | ', $rows[0]['changes'] )
		);
		$this->assertSame( 'law-waitlisted', get_post_status( $waiting ), 'And a preview still writes nothing.' );
	}

	public function test_a_speaker_and_an_agenda_cross_with_the_event(): void {
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90220 ) );
		$existing = $this->make_speaker_post( 'Ali', 'Malek KC', 'ali-ct@example.test' );
		$speakers = count( get_posts( array( 'post_type' => LAW_SPEAKER_CPT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) ) );

		law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row(
							array(
								'gf_entry_id' => 90220,
								'speakers'    => array(
									array( 'first_name' => 'Ali', 'last_name' => 'Malek KC', 'email' => 'ali-ct@example.test', 'website' => '', 'role' => 'moderator', 'organisation' => '3VB', 'job_title' => 'Barrister', 'bio' => '<p>Bio.</p>', 'photo' => null ),
								),
								'sessions'    => array(
									array( 'title' => 'Opening remarks', 'start' => '09:30', 'end' => '10:00', 'description' => '<p>Welcome.</p>', 'speakers' => array() ),
									array( 'title' => 'The panel', 'start' => '10:00', 'end' => '11:00', 'description' => '<p>Discussion.</p>', 'speakers' => array() ),
								),
							)
						),
					),
				)
			),
			false,
			0
		);

		$rows = law_event_meta( $event_id, '_law_speakers' );
		$this->assertCount( 1, $rows );
		$this->assertSame( $existing, (int) $rows[0]['speaker_id'], 'Matched by email, not duplicated.' );
		$this->assertSame( '3VB', (string) $rows[0]['organisation'], 'And the appearance details are this event\'s own.' );
		$this->assertCount( 2, law_event_session_ids( $event_id ) );
		$this->assertSame(
			$speakers,
			count( get_posts( array( 'post_type' => LAW_SPEAKER_CPT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => -1 ) ) ),
			'No second record for a person this site already knows.'
		);
	}

	public function test_a_bundle_silent_about_the_agenda_leaves_it_alone(): void {
		// The sentinel rule the host form and the external-events screen both
		// apply to a truncated POST. The row simply has no `sessions` key,
		// which is what a hand-edited or older file looks like.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90221 ) );
		$session  = wp_insert_post(
			array( 'post_type' => LAW_SESSION_CPT, 'post_status' => 'publish', 'post_parent' => $event_id, 'post_title' => 'Kept' )
		);
		$this->posts[] = $session;

		$row = $this->event_row( array( 'gf_entry_id' => 90221 ) );
		unset( $row['sessions'], $row['speakers'] );

		law_content_transfer_run( $this->bundle( array( 'events' => array( $row ) ) ), false, 0 );

		$this->assertSame( array( (int) $session ), array_map( 'intval', law_event_session_ids( $event_id ) ) );
	}

	public function test_an_agenda_emptied_on_the_source_really_is_cleared(): void {
		// The other half of the sentinel, and the half that makes the import an
		// overwrite rather than a merge: our own exporter always writes the key,
		// so an empty array means the client deleted the agenda.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90222 ) );
		$session  = wp_insert_post(
			array( 'post_type' => LAW_SESSION_CPT, 'post_status' => 'publish', 'post_parent' => $event_id, 'post_title' => 'Deleted on staging' )
		);
		$this->posts[] = $session;
		$speaker = $this->make_speaker_post( 'Gone', 'Fromhere', 'gone-ct@example.test' );
		law_event_update_meta( $event_id, '_law_speakers', array( array( 'speaker_id' => $speaker, 'role' => 'speaker' ) ) );

		law_content_transfer_run(
			$this->bundle( array( 'events' => array( $this->event_row( array( 'gf_entry_id' => 90222, 'sessions' => array(), 'speakers' => array() ) ) ) ) ),
			false,
			0
		);

		$this->assertSame( array(), law_event_session_ids( $event_id ) );
		$this->assertSame( array(), law_event_meta( $event_id, '_law_speakers' ) );
		$this->assertInstanceOf( WP_Post::class, get_post( $speaker ), 'The person is untouched; only this event\'s appearance row went.' );
	}

	public function test_co_owner_access_is_granted_on_a_create_and_only_there(): void {
		// A co-owner has the same rights as the owner (law_user_can_manage_event()),
		// so the link follows the owner's rule: set on an event this run creates,
		// reported on one it did not. No account is created either way.
		$existing = $this->make_user();
		$email    = (string) get_userdata( $existing )->user_email;
		$before   = count_users()['total_users'];

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row(
							array(
								'gf_entry_id' => 0,
								'slug'        => $this->slug( 'ct-coowner' ),
								'meta'        => array(
									'_law_co_owner_rows' => array(
										array( 'name' => 'Has An Account', 'organisation' => 'Firm', 'email' => $email ),
										array( 'name' => 'No Account Here', 'organisation' => 'Firm', 'email' => 'nobody-co@example.test' ),
									),
								),
							)
						),
					),
				)
			),
			false,
			0
		);

		$created       = (int) $rows[0]['event_id'];
		$this->posts[] = $created;
		$this->assertSame( array( $existing ), array_map( 'intval', law_event_meta( $created, '_law_co_owner_ids' ) ) );
		$this->assertSame(
			array( (string) $existing ),
			get_post_meta( $created, '_law_co_owner' ),
			'The flat rows the dashboard query matches are written too, through law_event_set_co_owner_ids().'
		);
		$this->assertCount( 2, law_event_meta( $created, '_law_co_owner_rows' ), 'Both rows travel; only the link is conditional.' );
		$this->assertNotEmpty( preg_grep( '/No Account Here/', $rows[0]['changes'] ) );
		$this->assertSame( $before, count_users()['total_users'], 'And no account was created.' );
	}

	public function test_an_existing_events_co_owner_access_is_reported_not_granted(): void {
		// The half the security review talked me out of. On an already-approved
		// event law_event_ensure_co_owner_users() never runs again, so linking
		// here would be the ONLY grant there ever was, with no human behind it.
		$stranger = $this->make_user();
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90243 ), 'publish' );

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row(
							array(
								'gf_entry_id' => 90243,
								'meta'        => array(
									'_law_co_owner_rows' => array(
										array( 'name' => 'Stranger', 'organisation' => 'Firm', 'email' => (string) get_userdata( $stranger )->user_email ),
									),
								),
							)
						),
					),
				)
			),
			false,
			0
		);

		$this->assertSame( array(), law_event_meta( $event_id, '_law_co_owner_ids' ), 'Nobody was handed access.' );
		$this->assertSame( array(), get_post_meta( $event_id, '_law_co_owner' ) );
		$this->assertCount( 1, law_event_meta( $event_id, '_law_co_owner_rows' ), 'The row itself still travels.' );
		$this->assertNotEmpty( preg_grep( '/Co-owner access left alone/', $rows[0]['changes'] ) );
	}

	public function test_a_recreated_session_keeps_its_gravity_forms_entry_id(): void {
		// The migration dedupes sessions with a meta query on this key, and an
		// import replaces the agenda. Losing it would make a re-run of the
		// sessions step duplicate every imported agenda.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90231 ) );
		$old      = wp_insert_post(
			array( 'post_type' => LAW_SESSION_CPT, 'post_status' => 'publish', 'post_parent' => $event_id, 'post_title' => 'Replaced' )
		);
		$this->posts[] = $old;

		law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row(
							array(
								'gf_entry_id' => 90231,
								'sessions'    => array(
									array( 'gf_entry_id' => 5501, 'title' => 'One', 'start' => '09:00', 'end' => '10:00', 'description' => '', 'speakers' => array() ),
									array( 'gf_entry_id' => 5502, 'title' => 'Two', 'start' => '10:00', 'end' => '11:00', 'description' => '', 'speakers' => array() ),
								),
							)
						),
					),
				)
			),
			false,
			0
		);

		$sessions = law_event_session_ids( $event_id );
		$this->assertCount( 2, $sessions );
		foreach ( $sessions as $session_id ) {
			$this->posts[] = $session_id;
		}
		$this->assertSame(
			array( 5501, 5502 ),
			array_map( fn( $id ) => (int) law_event_meta( $id, '_law_gf_entry_id' ), $sessions ),
			'Paired by position, which is what law_flagship_save_sessions() returns.'
		);
	}

	public function test_a_created_event_keeps_the_date_it_was_created_on(): void {
		// law_speakers.php orders "first appearance" on post_date_gmt, and that
		// is what picks the photo and organisation a speaker's archive card
		// shows. An event created here dated today would outrank an older one.
		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row(
							array(
								'gf_entry_id' => 0,
								'slug'        => $this->slug( 'ct-dated' ),
								'created'     => '2026-03-04 11:22',
							)
						),
					),
				)
			),
			false,
			0
		);

		$created = (int) $rows[0]['event_id'];
		$this->posts[] = $created;
		$this->assertSame( 'Create', $rows[0]['verdict'] );
		$this->assertSame( '2026-03-04 11:22:00', (string) get_post_field( 'post_date_gmt', $created ) );
	}

	/* What an uploaded file may not do (security review, 16 September 2026) __ */

	public function test_a_bundle_cannot_reclassify_a_hosted_event_into_a_status_bypass(): void {
		// The P0. The status guard exempts an event law_event_is_managed_by_law()
		// answers yes for, which reads the STORED _law_is_external. A bundle used
		// to be able to claim `external`, have its status write correctly
		// refused, and still write the flag — so the NEXT run found a genuinely
		// external event and put an unapproved submission on the public
		// programme. Run the same bundle twice; neither run may move the status.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90240 ), 'law-proposed' );

		$bundle = $this->bundle(
			array(
				'events' => array(
					$this->event_row(
						array(
							'gf_entry_id' => 90240,
							'external'    => true,          // The claim.
							'status'      => 'publish',     // What it wants.
							'meta'        => array( '_law_is_external' => 1 ), // The lever.
						)
					),
				),
			)
		);

		foreach ( array( 'first', 'second' ) as $run ) {
			$rows = law_content_transfer_run( $bundle, false, 0 );
			$this->assertSame( 'law-proposed', get_post_status( $event_id ), "The status moved on the {$run} run." );
			$this->assertSame( '', (string) law_event_meta( $event_id, '_law_is_external' ), "The classification was rewritten on the {$run} run." );
		}

		$this->assertNotEmpty(
			preg_grep( '/does not reclassify/', $rows['0']['changes'] ),
			'And the operator is told the file disagrees: ' . implode( ' | ', $rows[0]['changes'] )
		);
	}

	public function test_the_preview_never_promises_a_reclassification_the_apply_refuses(): void {
		// The same bug corrupted the dry run, which is the half an operator
		// actually reads.
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90241 ), 'law-proposed' );

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90241, 'external' => true, 'status' => 'publish', 'meta' => array( '_law_is_external' => 1 ) ) ),
					),
				)
			),
			true,
			0
		);

		$this->assertSame( 'Hosted event', $rows[0]['kind'], 'The row is labelled by what this site holds, not by what the file claims.' );
		$this->assertEmpty( preg_grep( '/^Status: /', $rows[0]['changes'] ), 'The preview must not show a status change: ' . implode( ' | ', $rows[0]['changes'] ) );
		$this->assertEmpty( preg_grep( '/^External event: /', $rows[0]['changes'] ), 'Nor a classification change.' );
		$this->assertSame( 'law-proposed', get_post_status( $event_id ) );
	}

	public function test_an_existing_events_owner_is_never_reassigned(): void {
		// post_author is who can open the event (law_user_can_manage_event()),
		// and the same run writes the invoicing contact and address into it. A
		// bundle naming somebody else's address may not hand it to them.
		$owner    = $this->make_user();
		$stranger = $this->make_user();
		$event_id = $this->make_event( array( '_law_gf_entry_id' => 90242 ) );
		wp_update_post( array( 'ID' => $event_id, 'post_author' => $owner ) );

		$rows = law_content_transfer_run(
			$this->bundle(
				array(
					'events' => array(
						$this->event_row( array( 'gf_entry_id' => 90242, 'owner_email' => (string) get_userdata( $stranger )->user_email ) ),
					),
				)
			),
			false,
			0
		);

		$this->assertSame( $owner, (int) get_post_field( 'post_author', $event_id ) );
		$this->assertNotEmpty( preg_grep( '/Owner left alone/', $rows[0]['changes'] ) );
	}

	public function test_a_bundle_carrying_absurdly_many_rows_is_refused(): void {
		// A run is synchronous inside one admin-post.php request, and each event
		// row costs a lookup, a snapshot, several writes and a log entry.
		$bundle           = $this->bundle();
		$bundle['events'] = array_fill( 0, LAW_CONTENT_TRANSFER_MAX_ROWS + 1, $this->event_row() );

		$parsed = law_content_transfer_parse( (string) wp_json_encode( $bundle ) );

		$this->assertInstanceOf( WP_Error::class, $parsed );
		$this->assertSame( 'law_ct_too_many_rows', $parsed->get_error_code() );
	}

	public function test_a_bundle_of_few_events_with_endless_sessions_is_refused(): void {
		// The top-level cap alone does not bound the work: a handful of events
		// each carrying tens of thousands of session rows is cheap in bytes and
		// still a very large synchronous run.
		$session          = array( 'title' => 'x', 'start' => '09:00', 'end' => '10:00', 'description' => '', 'speakers' => array() );
		$bundle           = $this->bundle();
		$bundle['events'] = array(
			$this->event_row( array( 'sessions' => array_fill( 0, LAW_CONTENT_TRANSFER_MAX_NESTED_ROWS + 1, $session ) ) ),
		);

		$parsed = law_content_transfer_parse( (string) wp_json_encode( $bundle ) );

		$this->assertInstanceOf( WP_Error::class, $parsed );
		$this->assertSame( 'law_ct_too_many_rows', $parsed->get_error_code() );
	}

	/* Helpers _______________________________________________________________ */

	/** A bundle row for one hosted event, in the shape the exporter writes. */
	private function event_row( array $overrides = array() ): array {
		$row = array_merge(
			array(
				'gf_entry_id'    => 0,
				'slug'           => 'ct-event',
				'reference'      => 'CT-TEST',
				'external'       => false,
				'title'          => 'Test transfer event',
				'description'    => '<p>Imported.</p>',
				'status'         => 'law-proposed',
				'created'        => '',
				'owner_email'    => '',
				'assignee_email' => '',
				'event_type'     => '',
				'sectors'        => array(),
				'organisations'  => array(),
				'meta'           => array(),
				'speakers'       => array(),
				'sessions'       => array(),
			),
			$overrides
		);
		// Merged rather than replaced, so a test naming one key does not have to
		// restate the rest of a realistic event.
		$row['meta'] = array_merge(
			array(
				'_law_start'             => '2026-12-01 09:00',
				'_law_end'               => '2026-12-01 11:00',
				'_law_venue'             => 'A venue',
				'_law_tickets_available' => 40,
				'_law_booking_override'  => 'auto',
			),
			(array) ( $overrides['meta'] ?? array() )
		);

		return $row;
	}

	/** @var string[] Temp files to unlink. */
	private array $files = array();

	/** @var string[] Working directories to remove. */
	private array $dirs = array();

	/** Refuse every outbound request, to prove a path needs no network. */
	public function refuse_http( $preempt, $args, $url ) {
		return new WP_Error( 'http_request_failed', 'Blocked by the test.' );
	}

	/**
	 * Point the whole uploads layer at a scratch directory for this test.
	 *
	 * Two reasons, and the second is the one that matters. The site's own
	 * uploads folder belongs to the web server and the test runner cannot write
	 * to it — but even where it could, a test that puts real files in the
	 * client's media library and real directories under law-migration/ is a test
	 * that leaves a mess behind. Everything the importer touches resolves
	 * through wp_upload_dir(), including law_content_transfer_workdir(), so one
	 * filter redirects all of it.
	 */
	private function use_temp_uploads(): string {
		$dir = rtrim( sys_get_temp_dir(), '/' ) . '/law-ct-' . wp_generate_password( 10, false, false );
		$this->assertTrue( wp_mkdir_p( $dir ), 'Could not create the scratch uploads directory.' );
		$this->dirs[] = $dir;

		add_filter(
			'upload_dir',
			function ( $uploads ) use ( $dir ) {
				// Deliberately flat, matching this site's own setting: the
				// year/month split is irrelevant to anything under test and a
				// second level would only make the fixtures harder to read.
				return array(
					'path'    => $dir,
					'url'     => 'https://staging.example.test/wp-content/uploads',
					'subdir'  => '',
					'basedir' => $dir,
					'baseurl' => 'https://staging.example.test/wp-content/uploads',
					'error'   => false,
				);
			}
		);

		return $dir;
	}

	/** A real attachment with a real file, so export and sideload both run for real. */
	private function make_image_attachment( string $name ): int {
		// A 1x1 PNG. Written through wp_upload_bits() so it lands where
		// get_attached_file() and wp_get_attachment_url() agree it is.
		$png  = base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==' );
		$file = wp_upload_bits( wp_unique_filename( wp_upload_dir()['path'], $name ), null, $png );
		$this->assertEmpty( $file['error'], (string) $file['error'] );

		$id = wp_insert_attachment(
			array(
				'post_mime_type' => 'image/png',
				'post_title'     => $name,
				'post_status'    => 'inherit',
			),
			$file['file']
		);
		$this->posts[] = (int) $id;
		update_post_meta( (int) $id, '_wp_attached_file', str_replace( trailingslashit( wp_upload_dir()['basedir'] ), '', $file['file'] ) );

		return (int) $id;
	}

	/** An archive with exactly these entries, as a temp file. */
	private function write_zip( array $entries ): string {
		$file = wp_tempnam( 'law-ct-test.zip' );
		$this->files[] = $file;

		$zip = new ZipArchive();
		$this->assertTrue( true === $zip->open( $file, ZipArchive::OVERWRITE ) );
		foreach ( $entries as $name => $bytes ) {
			$zip->addFromString( $name, $bytes );
		}
		$zip->close();

		return $file;
	}

	/**
	 * A registry slug whose recipients are a typed address list.
	 *
	 * Only those four can carry a `to` at all — the rest resolve an audience key
	 * like "committee" — so the recipient tests have to find one rather than
	 * hardcode a slug that a registry edit could retire.
	 */
	private function an_email_with_a_typed_recipient_list(): string {
		foreach ( law_events_email_registry() as $slug => $definition ) {
			if ( is_array( $definition['to'] ?? null ) ) {
				return (string) $slug;
			}
		}
		$this->fail( 'No email in the registry takes a typed recipient list.' );
	}

	/** Store one override, replacing whatever the isolated option holds. */
	private function set_override( string $slug, array $override ): void {
		update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array( $slug => $override ), false );
	}

	/** Remove a scratch tree, which unlike a working directory does nest. */
	private function rmtree( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( (array) glob( $dir . '/{,.}*', GLOB_BRACE ) as $path ) {
			if ( in_array( basename( $path ), array( '.', '..' ), true ) ) {
				continue;
			}
			is_dir( $path ) ? $this->rmtree( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}

	private function workdir_count(): int {
		return count( (array) glob( trailingslashit( wp_upload_dir()['basedir'] ) . 'law-migration/ct-*' ) );
	}

	/** Walk every scalar in a nested structure, with its key. */
	private function walk( $value, callable $visit, string $path = '' ): void {
		if ( ! is_array( $value ) ) {
			$visit( $path, $value );
			return;
		}
		foreach ( $value as $key => $item ) {
			$this->walk( $item, $visit, '' === $path ? (string) $key : $path . '.' . $key );
		}
	}

	private function make_speaker_post( string $first, string $last, string $email ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => LAW_SPEAKER_CPT,
				'post_status' => 'publish',
				'post_title'  => trim( $first . ' ' . $last ),
			)
		);
		$this->posts[] = $post_id;
		law_event_update_meta( $post_id, '_law_speaker_first_name', $first );
		law_event_update_meta( $post_id, '_law_speaker_last_name', $last );
		law_event_update_meta( $post_id, '_law_speaker_email', $email );
		return (int) $post_id;
	}

	private function row_by( array $rows, string $key, string $value ): array {
		foreach ( $rows as $row ) {
			if ( (string) ( $row[ $key ] ?? '' ) === $value ) {
				return $row;
			}
		}
		$this->fail( sprintf( 'No row with %s = %s in the bundle.', $key, $value ) );
	}

	private function post_counts(): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ( %s, %s, %s, %s )",
					LAW_EVENT_CPT,
					LAW_SESSION_CPT,
					LAW_SPEAKER_CPT,
					LAW_DISCOUNT_CPT
				)
			)
		);
	}
}
