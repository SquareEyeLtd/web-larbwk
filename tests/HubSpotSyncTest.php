<?php
/**
 * functions/hubspot/sync.php (HUBSPOT_SYNC.md §6.3, §8, §9 HubSpotSyncTest):
 * the plan reads what HubSpot holds and merges; the push updates by ID,
 * upserts by email, stores IDs, attributes per-item errors, and in dry mode
 * sends nothing. The portal is a fake behind law_hubspot_request_mock that
 * holds contacts in memory and records every write.
 */

class HubSpotSyncTest extends LAW_Test_Case {

	/** @var array<string,array> Contacts in the fake portal: id => array( 'email' => ..., 'properties' => ... ). */
	private array $contacts = array();
	/** @var array[] Writes attempted: path, body. */
	private array $writes = array();
	/** @var array[] Reads attempted. */
	private array $reads = array();
	private string $mode = 'live';
	private int $next_id = 1000;
	/** @var callable|null Override the write response for a test. */
	private $write_response = null;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		law_hubspot_install_tables();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->contacts       = array();
		$this->writes         = array();
		$this->reads          = array();
		$this->mode           = 'live';
		$this->write_response = null;
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'year' => 2026 ) );
		add_filter( 'law_hubspot_mode', array( $this, 'mode' ) );
		add_filter( 'law_hubspot_request_mock', array( $this, 'portal' ), 10, 4 );
	}

	protected function tearDown(): void {
		remove_filter( 'law_hubspot_mode', array( $this, 'mode' ) );
		remove_filter( 'law_hubspot_request_mock', array( $this, 'portal' ), 10 );
		parent::tearDown();
	}

	public function mode() {
		return $this->mode;
	}

	/* ---- the fake portal -------------------------------------------------- */

	private function seed_contact( string $email, array $properties = array() ): string {
		$id                    = (string) $this->next_id++;
		$this->contacts[ $id ] = array( 'email' => $email, 'properties' => array( 'email' => $email ) + $properties );
		return $id;
	}

	private function contact_by_email( string $email ): ?string {
		foreach ( $this->contacts as $id => $contact ) {
			if ( $contact['email'] === $email ) {
				return $id;
			}
		}
		return null;
	}

	public function portal( $mocked, $method, $path, $body ) {
		if ( '/crm/v3/objects/contacts/batch/read' === $path ) {
			$this->reads[] = $body;
			$results       = array();
			foreach ( $body['inputs'] as $input ) {
				$id = isset( $body['idProperty'] ) && 'email' === $body['idProperty'] ? $this->contact_by_email( $input['id'] ) : ( isset( $this->contacts[ $input['id'] ] ) ? $input['id'] : null );
				if ( null !== $id ) {
					$props     = array_intersect_key( $this->contacts[ $id ]['properties'], array_flip( $body['properties'] ) );
					$results[] = array( 'id' => $id, 'properties' => $props );
				}
			}
			return array( 'status' => 'COMPLETE', 'results' => $results );
		}

		if ( 'dry' === $this->mode ) {
			return null; // the client's own dry gate handles writes
		}
		$this->writes[] = array( 'path' => $path, 'body' => $body );
		if ( null !== $this->write_response ) {
			return call_user_func( $this->write_response, $path, $body );
		}

		$results = array();
		if ( str_ends_with( $path, '/batch/update' ) ) {
			foreach ( $body['inputs'] as $input ) {
				$id = (string) $input['id'];
				if ( ! isset( $this->contacts[ $id ] ) ) {
					return new WP_Error( 'law_hubspot_api_error', 'HubSpot: resource not found', array( 'status' => 404 ) );
				}
				$this->contacts[ $id ]['properties'] = array_merge( $this->contacts[ $id ]['properties'], $input['properties'] );
				$results[]                           = array( 'id' => $id, 'properties' => $this->contacts[ $id ]['properties'] );
			}
			return array( 'status' => 'COMPLETE', 'results' => $results );
		}
		if ( str_ends_with( $path, '/batch/upsert' ) ) {
			foreach ( $body['inputs'] as $input ) {
				$email = law_hubspot_normalise_email( $input['id'] );
				$id    = $this->contact_by_email( $email ) ?? $this->seed_contact( $email );
				$this->contacts[ $id ]['properties'] = array_merge( $this->contacts[ $id ]['properties'], $input['properties'] );
				$results[]                           = array( 'id' => $id, 'new' => true, 'properties' => $this->contacts[ $id ]['properties'] );
			}
			return array( 'status' => 'COMPLETE', 'results' => $results );
		}
		return new WP_Error( 'law_test_unexpected', 'Unexpected write to ' . $path );
	}

	private function writes_to( string $suffix ): array {
		return array_values( array_filter( $this->writes, fn( $w ) => str_ends_with( $w['path'], $suffix ) ) );
	}

	/* ---- fixtures -------------------------------------------------------- */

	private function make_person( array $profile = array() ): array {
		$user_id = $this->make_user();
		$profile = array_merge( array( 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'organisation' => 'Analytical Engines' ), $profile );
		wp_update_user( array( 'ID' => $user_id, 'first_name' => $profile['first_name'], 'last_name' => $profile['last_name'] ) );
		update_user_meta( $user_id, 'organisation', $profile['organisation'] );
		return array( $user_id, law_hubspot_normalise_email( get_user_by( 'id', $user_id )->user_email ) );
	}

	/* ---- the plan --------------------------------------------------------- */

	public function test_plan_for_a_new_contact(): void {
		list( , $email ) = $this->make_person();

		$planned = law_hubspot_plan_batch( array( $email ) );
		$plan    = $planned['plans'][ $email ];

		$this->assertNull( $planned['error'] );
		$this->assertFalse( $plan['exists'] );
		$this->assertSame( '', $plan['hubspot_id'] );
		$this->assertSame( '', $plan['before']['contact_type'] );
		$this->assertSame( '2026 Registered user', $plan['after']['contact_type'] );
		$this->assertSame( array( '2026 Registered user' ), $plan['added_tags'] );
		$this->assertSame( 'Legitimate interest - other', $plan['after']['legal_basis'] );
		$this->assertSame( 'Ada', $plan['properties']['firstname'] );
		$this->assertSame( '2026 Registered user', $plan['properties']['contact_type'] );
		$this->assertSame( 'Legitimate interest - other', $plan['properties']['hs_legal_basis'] );
		$this->assertArrayNotHasKey( 'email', $plan['properties'], 'email is the identity, not a property to write.' );
		$this->assertSame( 'email', $this->reads[0]['idProperty'] );
	}

	public function test_plan_merges_with_what_hubspot_holds(): void {
		list( , $email ) = $this->make_person();
		$this->seed_contact( $email, array( 'contact_type' => '2025 Attendee;Mailchimp', 'hs_legal_basis' => 'Legitimate interest – existing customer' ) );

		$plan = law_hubspot_plan_batch( array( $email ) )['plans'][ $email ];

		$this->assertTrue( $plan['exists'] );
		$this->assertSame( '2025 Attendee;Mailchimp;2026 Registered user', $plan['properties']['contact_type'] );
		$this->assertArrayNotHasKey( 'hs_legal_basis', $plan['properties'], 'Already existing customer: not written, never downgraded.' );
		$this->assertSame( 'Legitimate interest – existing customer', $plan['after']['legal_basis'] );
	}

	public function test_plan_writes_nothing_shared_when_nothing_changes(): void {
		list( , $email ) = $this->make_person();
		$this->seed_contact( $email, array( 'contact_type' => '2026 Registered user', 'hs_legal_basis' => 'Legitimate interest - other' ) );

		$plan = law_hubspot_plan_batch( array( $email ) )['plans'][ $email ];

		$this->assertArrayNotHasKey( 'contact_type', $plan['properties'] );
		$this->assertArrayNotHasKey( 'hs_legal_basis', $plan['properties'] );
		$this->assertSame( array(), $plan['added_tags'] );
		$this->assertSame( 'Ada', $plan['properties']['firstname'], 'Standard properties are always refreshed.' );
	}

	public function test_plan_reads_by_stored_id_first(): void {
		list( $user_id, $email ) = $this->make_person();
		$id = $this->seed_contact( 'old-address@example.test', array( 'contact_type' => 'Mailchimp' ) );
		update_user_meta( $user_id, 'law_hubspot_id', $id );

		$plan = law_hubspot_plan_batch( array( $email ) )['plans'][ $email ];

		$this->assertSame( $id, $plan['hubspot_id'], 'The contact is found by ID although the site email changed.' );
		$this->assertSame( 'Mailchimp;2026 Registered user', $plan['properties']['contact_type'] );
		$this->assertArrayNotHasKey( 'idProperty', $this->reads[0], 'First read is by record ID.' );
		$this->assertCount( 1, $this->reads, 'No email read needed.' );
	}

	public function test_plan_forgets_a_stored_id_hubspot_no_longer_knows(): void {
		list( $user_id, $email ) = $this->make_person();
		update_user_meta( $user_id, 'law_hubspot_id', '424242' );
		$real = $this->seed_contact( $email );

		$plan = law_hubspot_plan_batch( array( $email ) )['plans'][ $email ];

		$this->assertSame( '', get_user_meta( $user_id, 'law_hubspot_id', true ), 'Stale ID dropped.' );
		$this->assertSame( $real, $plan['hubspot_id'], 'Fell back to the email read and found the merged contact.' );
		$this->assertCount( 2, $this->reads );
	}

	public function test_plan_marks_unknown_addresses(): void {
		$planned = law_hubspot_plan_batch( array( 'ghost@example.test' ) );

		$this->assertNull( $planned['plans']['ghost@example.test']['state'] );
		$this->assertSame( array(), $this->reads, 'Nothing to read for someone the site does not know.' );
	}

	public function test_plan_returns_the_read_error(): void {
		list( , $email ) = $this->make_person();
		remove_filter( 'law_hubspot_request_mock', array( $this, 'portal' ), 10 );
		$down = fn() => new WP_Error( 'law_hubspot_api_error', 'HubSpot: 503', array( 'status' => 503 ) );
		add_filter( 'law_hubspot_request_mock', $down );

		$planned = law_hubspot_plan_batch( array( $email ) );
		remove_filter( 'law_hubspot_request_mock', $down );

		$this->assertInstanceOf( WP_Error::class, $planned['error'] );
		$this->assertSame( array(), $planned['plans'] );
	}

	/* ---- the push --------------------------------------------------------- */

	public function test_new_contact_is_upserted_by_email_and_its_id_stored(): void {
		list( $user_id, $email ) = $this->make_person();

		$results = law_hubspot_sync_batch( array( $email ) );

		$this->assertCount( 1, $this->writes_to( '/batch/upsert' ) );
		$this->assertCount( 0, $this->writes_to( '/batch/update' ) );
		$input = $this->writes_to( '/batch/upsert' )[0]['body']['inputs'][0];
		$this->assertSame( 'email', $input['idProperty'] );
		$this->assertSame( $email, $input['id'] );
		$this->assertSame( $email, $input['properties']['email'] );
		$this->assertSame( '2026 Registered user', $input['properties']['contact_type'] );
		$this->assertMatchesRegularExpression( '/^\d{13}$/', $input['properties']['law_sync_updated'], 'Timestamp in ms.' );

		$id = $this->contact_by_email( $email );
		$this->assertNotNull( $id );
		$this->assertSame( array( 'id' => $id, 'new' => true ), $results[ $email ] );
		$this->assertSame( $id, get_user_meta( $user_id, 'law_hubspot_id', true ) );

		$log = law_hubspot_log_recent( 1, $email )[0];
		$this->assertSame( 'ok', $log->result );
		$detail = json_decode( $log->detail, true );
		$this->assertTrue( $detail['new'] );
		$this->assertSame( '2026 Registered user', $detail['contact_type']['after'] );
	}

	public function test_known_contact_is_updated_by_id(): void {
		list( $user_id, $email ) = $this->make_person();
		$id = $this->seed_contact( $email, array( 'contact_type' => 'Mailchimp' ) );

		$results = law_hubspot_sync_batch( array( $email ) );

		$this->assertCount( 1, $this->writes_to( '/batch/update' ) );
		$this->assertCount( 0, $this->writes_to( '/batch/upsert' ) );
		$input = $this->writes_to( '/batch/update' )[0]['body']['inputs'][0];
		$this->assertSame( $id, $input['id'] );
		$this->assertSame( 'Mailchimp;2026 Registered user', $input['properties']['contact_type'] );
		$this->assertArrayNotHasKey( 'email', $input['properties'] );
		$this->assertSame( array( 'id' => $id, 'new' => false ), $results[ $email ] );
		$this->assertSame( $id, get_user_meta( $user_id, 'law_hubspot_id', true ), 'ID learned from the read is stored.' );
		$this->assertSame( 'Mailchimp;2026 Registered user', $this->contacts[ $id ]['properties']['contact_type'] );
	}

	public function test_batch_mixes_updates_and_upserts(): void {
		list( , $existing ) = $this->make_person();
		list( , $fresh )    = $this->make_person();
		$this->seed_contact( $existing );

		$results = law_hubspot_sync_batch( array( $existing, $fresh ) );

		$this->assertCount( 1, $this->writes_to( '/batch/update' ) );
		$this->assertCount( 1, $this->writes_to( '/batch/upsert' ) );
		$this->assertFalse( is_wp_error( $results[ $existing ] ) );
		$this->assertFalse( is_wp_error( $results[ $fresh ] ) );
	}

	public function test_unknown_address_is_skipped_not_failed(): void {
		$results = law_hubspot_sync_batch( array( 'ghost@example.test' ) );

		$this->assertSame( array( 'skipped' => 'unknown' ), $results['ghost@example.test'] );
		$this->assertSame( array(), $this->writes );
		$this->assertSame( 'skipped', law_hubspot_log_recent( 1, 'ghost@example.test' )[0]->result );
	}

	public function test_dry_mode_logs_and_sends_nothing(): void {
		$this->mode = 'dry';
		list( $user_id, $email ) = $this->make_person();
		$this->seed_contact( $email );

		$results = law_hubspot_sync_batch( array( $email ) );

		$this->assertSame( array(), $this->writes, 'The client\'s dry gate stopped the write before the portal.' );
		$this->assertSame( array( 'dry_run' => true ), $results[ $email ] );
		$this->assertSame( 'dry', law_hubspot_log_recent( 1, $email )[0]->result );
		$this->assertSame( '', get_user_meta( $user_id, 'law_hubspot_id', true ), 'Nothing was pushed, so no ID is recorded.' );
	}

	public function test_per_item_errors_land_on_the_right_person(): void {
		list( , $good ) = $this->make_person();
		list( , $bad )  = $this->make_person();
		$good_id        = $this->seed_contact( $good );
		$bad_id         = $this->seed_contact( $bad );

		$this->write_response = function ( $path, $body ) use ( $good_id, $bad_id ) {
			return array(
				'status'  => 'COMPLETE',
				'results' => array( array( 'id' => $good_id, 'properties' => array() ) ),
				'errors'  => array( array( 'status' => 'error', 'message' => 'Property values were not valid', 'context' => array( 'ids' => array( $bad_id ) ) ) ),
			);
		};

		$results = law_hubspot_sync_batch( array( $good, $bad ) );

		$this->assertSame( array( 'id' => $good_id, 'new' => false ), $results[ $good ] );
		$this->assertInstanceOf( WP_Error::class, $results[ $bad ] );
		$this->assertStringContainsString( 'not valid', $results[ $bad ]->get_error_message() );
	}

	public function test_whole_batch_4xx_is_retried_one_at_a_time(): void {
		list( , $good ) = $this->make_person();
		list( , $bad )  = $this->make_person();
		$this->seed_contact( $good );
		$this->seed_contact( $bad );

		$this->write_response = function ( $path, $body ) use ( $bad ) {
			$ids = array_column( $body['inputs'], 'id' );
			if ( count( $ids ) > 1 || in_array( $this->contact_by_email( $bad ), $ids, true ) ) {
				return new WP_Error( 'law_hubspot_api_error', 'HubSpot: Property values were not valid', array( 'status' => 400 ) );
			}
			return array( 'status' => 'COMPLETE', 'results' => array( array( 'id' => $ids[0], 'properties' => array() ) ) );
		};

		$results = law_hubspot_sync_batch( array( $good, $bad ) );

		$this->assertCount( 3, $this->writes_to( '/batch/update' ), 'One batch call, then one per person.' );
		$this->assertFalse( is_wp_error( $results[ $good ] ), 'The good one got through on its own.' );
		$this->assertInstanceOf( WP_Error::class, $results[ $bad ] );
	}

	public function test_whole_batch_5xx_fails_everyone_without_retrying_each(): void {
		list( , $a ) = $this->make_person();
		list( , $b ) = $this->make_person();
		$this->seed_contact( $a );
		$this->seed_contact( $b );
		$this->write_response = fn() => new WP_Error( 'law_hubspot_api_error', 'HubSpot: 503', array( 'status' => 503 ) );

		$results = law_hubspot_sync_batch( array( $a, $b ) );

		$this->assertCount( 1, $this->writes_to( '/batch/update' ) );
		$this->assertInstanceOf( WP_Error::class, $results[ $a ] );
		$this->assertInstanceOf( WP_Error::class, $results[ $b ] );
	}

	public function test_worker_runs_the_real_sync_step(): void {
		list( $user_id, $email ) = $this->make_person();
		law_hubspot_enqueue( $email, 'user_register' );

		$summary = law_hubspot_process_queue();

		$this->assertSame( 1, $summary['succeeded'] );
		$this->assertNull( law_hubspot_queue_row( $email ) );
		$this->assertNotNull( $this->contact_by_email( $email ) );
		$this->assertSame( $this->contact_by_email( $email ), get_user_meta( $user_id, 'law_hubspot_id', true ) );
	}

	/* ---- the preview ------------------------------------------------------ */

	public function test_preview_rows_describe_before_and_after(): void {
		list( , $existing ) = $this->make_person();
		list( , $fresh )    = $this->make_person( array( 'first_name' => 'Grace', 'last_name' => 'Hopper' ) );
		$id = $this->seed_contact( $existing, array( 'contact_type' => '2025 Attendee', 'hs_legal_basis' => 'Legitimate interest - other' ) );

		$preview = law_hubspot_preview( array( $existing, $fresh, 'ghost@example.test' ) );

		$this->assertNull( $preview['error'] );
		$this->assertSame( array(), $this->writes, 'A preview writes nothing.' );
		$this->assertSame( 3, $preview['totals']['people'] );
		$this->assertSame( 1, $preview['totals']['in_hubspot'] );
		$this->assertSame( 1, $preview['totals']['new_contacts'] );
		$this->assertSame( 1, $preview['totals']['unknown'] );
		$this->assertSame( 2, $preview['totals']['2026 Registered user'] );

		$rows = array_column( $preview['rows'], null, 'email' );
		$this->assertSame( 'yes', $rows[ $existing ]['exists_in_hubspot'] );
		$this->assertSame( $id, $rows[ $existing ]['hubspot_id'] );
		$this->assertSame( '2025 Attendee', $rows[ $existing ]['contact_type_before'] );
		$this->assertSame( '2025 Attendee;2026 Registered user', $rows[ $existing ]['contact_type_after'] );
		$this->assertSame( '2026 Registered user', $rows[ $existing ]['tags_added'] );
		$this->assertSame( 'Legitimate interest - other', $rows[ $existing ]['legal_basis_after'], 'An account alone does not upgrade "other".' );
		$this->assertSame( 'no', $rows[ $fresh ]['exists_in_hubspot'] );
		$this->assertSame( 'Grace', $rows[ $fresh ]['firstname'] );
		$this->assertSame( 'Legitimate interest - other', $rows[ $fresh ]['legal_basis_after'] );
		$this->assertSame( 'no', $rows['ghost@example.test']['in_wordpress'] );
	}

	public function test_preview_csv_has_a_header_and_guards_formulas(): void {
		list( , $email ) = $this->make_person( array( 'first_name' => '=HYPERLINK("x")' ) );
		$preview = law_hubspot_preview( array( $email ) );

		$handle = fopen( 'php://memory', 'w+' );
		law_hubspot_preview_to_csv( $handle, $preview['rows'] );
		rewind( $handle );
		$header = fgetcsv( $handle, null, ',', '"', '\\' );
		$row    = fgetcsv( $handle, null, ',', '"', '\\' );
		fclose( $handle );

		$this->assertSame( law_hubspot_preview_columns(), $header );
		$this->assertSame( $email, $row[0] );
		$this->assertSame( "'=HYPERLINK(\"x\")", $row[ array_search( 'firstname', $header, true ) ] );
	}
}
