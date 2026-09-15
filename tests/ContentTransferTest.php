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
		array_walk_recursive(
			$bundle,
			function ( $value, $key ) use ( &$found ) {
				if ( preg_match( '/(^|_)id$/', (string) $key ) && 'entry_id' !== $key ) {
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
		// A FREE reception, so the booking engine seats the place outright: a
		// priced one would send the booker to Stripe and this fixture would
		// quietly be a WP_Error instead of a booking.
		$event_id = $this->make_reception(
			$this->slug( 'ct-drinks' ),
			array( '_law_attendee_price_pence' => 0, '_law_registration_state' => 'free' )
		);
		$booker   = $this->make_user();
		$booking  = $this->make_booking_id( $event_id, $booker );
		$this->assertIsInt( $booking, 'The booking fixture must really be a booking.' );

		$bundle = law_content_transfer_bundle();

		// Asserted on the SHAPE rather than on a substring of the encoded file:
		// the bundle carries the whole site's receptions, so a venue or a
		// description could legitimately contain any word.
		$this->assertSame(
			array( 'format', 'version', 'generated_at', 'site', 'programme_year', 'receptions', 'flagship', 'discounts', 'emails' ),
			array_keys( $bundle ),
			'A new top-level key means a new decision about what crosses environments.'
		);

		$keys = array();
		array_walk_recursive( $bundle, function ( $value, $key ) use ( &$keys ) { $keys[ (string) $key ] = true; } );
		foreach ( array_keys( $keys ) as $key ) {
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

	/* Helpers _______________________________________________________________ */

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
