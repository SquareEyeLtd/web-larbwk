<?php
/**
 * functions/hubspot/client.php and config.php (HUBSPOT_SYNC.md §9,
 * HubSpotClientTest): the mode rail, the dry-mode write gate, and the retry
 * policy, all through pre_http_request so no byte leaves the machine.
 */

class HubSpotClientTest extends LAW_Test_Case {

	/** @var array[] Scripted responses, consumed in order. */
	private array $responses = array();
	/** @var array[] What the client actually sent: url, method, body, headers. */
	private array $requests = array();
	/** @var int[] Seconds the client asked to sleep between attempts. */
	private array $sleeps = array();
	/** @var array[] Dry writes the client declined. */
	private array $dry_writes = array();

	private string $mode = 'live';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		// The dry-write log needs its table; CREATE TABLE commits, so it goes
		// outside the per-test transaction (see LAW_Test_Case::$use_transaction).
		law_hubspot_install_tables();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->responses  = array();
		$this->requests   = array();
		$this->sleeps     = array();
		$this->dry_writes = array();
		$this->mode       = 'live';

		add_filter( 'law_hubspot_mode', array( $this, 'mode' ) );
		add_filter( 'pre_http_request', array( $this, 'answer' ), 10, 3 );
		add_filter( 'law_hubspot_sleep_seconds', array( $this, 'record_sleep' ) );
		add_action( 'law_hubspot_dry_write', array( $this, 'record_dry_write' ), 10, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'law_hubspot_mode', array( $this, 'mode' ) );
		remove_filter( 'pre_http_request', array( $this, 'answer' ), 10 );
		remove_filter( 'law_hubspot_sleep_seconds', array( $this, 'record_sleep' ) );
		remove_action( 'law_hubspot_dry_write', array( $this, 'record_dry_write' ), 10 );
		parent::tearDown();
	}

	public function mode() {
		return $this->mode;
	}

	public function answer( $preempt, $args, $url ) {
		if ( ! str_contains( (string) $url, 'api.hubapi.com' ) ) {
			return $preempt;
		}
		$this->requests[] = array(
			'url'     => (string) $url,
			'method'  => (string) ( $args['method'] ?? 'GET' ),
			'body'    => isset( $args['body'] ) ? json_decode( (string) $args['body'], true ) : null,
			'headers' => (array) ( $args['headers'] ?? array() ),
		);
		if ( ! $this->responses ) {
			$this->fail( 'The client made a request the test did not script: ' . $url );
		}
		$next = array_shift( $this->responses );
		if ( $next instanceof WP_Error ) {
			return $next;
		}
		return array(
			'response' => array( 'code' => $next['code'], 'message' => '' ),
			'headers'  => $next['headers'] ?? array(),
			'body'     => isset( $next['body'] ) ? wp_json_encode( $next['body'] ) : '',
			'cookies'  => array(),
			'filename' => null,
		);
	}

	public function record_sleep( $seconds ) {
		$this->sleeps[] = (int) $seconds;
		return 0;
	}

	public function record_dry_write( $method, $path, $body ) {
		$this->dry_writes[] = compact( 'method', 'path', 'body' );
	}

	private function script( ...$responses ) {
		$this->responses = $responses;
	}

	/* ---- the mode rail -------------------------------------------------- */

	public function test_live_is_downgraded_to_dry_on_local_and_development(): void {
		$this->assertSame( 'dry', law_hubspot_mode_for( 'live', 'local' ) );
		$this->assertSame( 'dry', law_hubspot_mode_for( 'live', 'development' ) );
		$this->assertSame( 'live', law_hubspot_mode_for( 'live', 'staging' ), 'Staging is where the dry-run review happens and its config is deliberate.' );
		$this->assertSame( 'live', law_hubspot_mode_for( 'live', 'production' ) );
	}

	public function test_dry_and_off_pass_through_everywhere_and_junk_means_off(): void {
		foreach ( array( 'local', 'development', 'staging', 'production' ) as $environment ) {
			$this->assertSame( 'dry', law_hubspot_mode_for( 'dry', $environment ) );
			$this->assertSame( 'off', law_hubspot_mode_for( 'off', $environment ) );
			$this->assertSame( 'off', law_hubspot_mode_for( 'LIVE!', $environment ) );
			$this->assertSame( 'off', law_hubspot_mode_for( '', $environment ) );
		}
	}

	/* ---- off and dry ---------------------------------------------------- */

	public function test_off_sends_nothing_and_returns_an_error(): void {
		$this->mode = 'off';
		$result     = law_hubspot_request( 'GET', '/crm/v3/properties/contacts/email' );
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_hubspot_off', $result->get_error_code() );
		$this->assertSame( array(), $this->requests );
	}

	public function test_dry_runs_reads_but_logs_writes_instead_of_sending_them(): void {
		$this->mode = 'dry';
		$this->script(
			array( 'code' => 200, 'body' => array( 'results' => array() ) ),
			array( 'code' => 200, 'body' => array( 'results' => array( array( 'id' => '1' ) ) ) )
		);

		$get = law_hubspot_request( 'GET', '/crm/v3/properties/contacts' );
		$this->assertSame( array( 'results' => array() ), $get, 'GET runs for real in dry mode.' );

		$read = law_hubspot_batch_read( array( 'a@example.test' ), array( 'email' ) );
		$this->assertSame( '1', $read['results'][0]['id'], 'POST batch/read is a read and runs for real in dry mode.' );

		$upsert = law_hubspot_batch_upsert( array( array( 'email' => 'a@example.test', 'properties' => array( 'firstname' => 'A' ) ) ) );
		$this->assertTrue( law_hubspot_result_is_dry( $upsert ) );
		$patch = law_hubspot_request( 'PATCH', '/crm/v3/properties/contacts/law_dietary', array( 'options' => array() ) );
		$this->assertTrue( law_hubspot_result_is_dry( $patch ) );

		$this->assertCount( 2, $this->requests, 'Only the two reads reached HTTP.' );
		$this->assertCount( 2, $this->dry_writes );
		$this->assertSame( '/crm/v3/objects/contacts/batch/upsert', $this->dry_writes[0]['path'] );
		$this->assertSame( 'email', $this->dry_writes[0]['body']['inputs'][0]['idProperty'] );

		// And the dry write is on the record for the admin screen.
		$log = law_hubspot_log_recent( 5 );
		$this->assertSame( 'dry-write', $log[0]->action );
		$this->assertSame( 'dry', $log[0]->result );
		$this->assertStringContainsString( 'law_dietary', $log[0]->detail );
	}

	public function test_write_detection(): void {
		$this->assertFalse( law_hubspot_request_is_write( 'GET', '/crm/v3/objects/contacts' ) );
		$this->assertFalse( law_hubspot_request_is_write( 'POST', '/crm/v3/objects/contacts/batch/read' ) );
		$this->assertFalse( law_hubspot_request_is_write( 'POST', '/crm/v3/objects/contacts/search' ) );
		$this->assertTrue( law_hubspot_request_is_write( 'POST', '/crm/v3/objects/contacts/batch/upsert' ) );
		$this->assertTrue( law_hubspot_request_is_write( 'POST', '/crm/v3/objects/contacts/batch/update' ) );
		$this->assertTrue( law_hubspot_request_is_write( 'PATCH', '/crm/v3/properties/contacts/x' ) );
		$this->assertTrue( law_hubspot_request_is_write( 'DELETE', '/crm/v3/objects/contacts/1' ) );
	}

	/* ---- live requests -------------------------------------------------- */

	public function test_a_live_request_carries_the_bearer_token_and_json(): void {
		$this->script( array( 'code' => 200, 'body' => array( 'ok' => true ) ) );
		$result = law_hubspot_request( 'POST', '/crm/v3/objects/contacts/batch/update', array( 'inputs' => array() ) );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertSame( 'https://api.hubapi.com/crm/v3/objects/contacts/batch/update', $this->requests[0]['url'] );
		$this->assertSame( 'Bearer ' . LAW_TEST_HUBSPOT_TOKEN, $this->requests[0]['headers']['Authorization'] );
		$this->assertSame( 'application/json', $this->requests[0]['headers']['Content-Type'] );
		$this->assertSame( array( 'inputs' => array() ), $this->requests[0]['body'] );
	}

	public function test_get_puts_the_body_on_the_query_string(): void {
		$this->script( array( 'code' => 200, 'body' => array() ) );
		law_hubspot_request( 'GET', '/crm/v3/properties/contacts', array( 'archived' => 'false' ) );
		$this->assertSame( 'https://api.hubapi.com/crm/v3/properties/contacts?archived=false', $this->requests[0]['url'] );
		$this->assertNull( $this->requests[0]['body'] );
	}

	public function test_an_empty_2xx_body_is_an_empty_array(): void {
		$this->script( array( 'code' => 204 ) );
		$this->assertSame( array(), law_hubspot_request( 'DELETE', '/crm/v3/objects/contacts/1' ) );
	}

	public function test_429_honours_retry_after_then_succeeds(): void {
		$this->script(
			array( 'code' => 429, 'headers' => array( 'retry-after' => '7' ), 'body' => array( 'message' => 'slow down' ) ),
			array( 'code' => 200, 'body' => array( 'ok' => true ) )
		);
		$result = law_hubspot_request( 'GET', '/crm/v3/properties/contacts' );

		$this->assertSame( array( 'ok' => true ), $result );
		$this->assertCount( 2, $this->requests );
		$this->assertSame( array( 7 ), $this->sleeps, 'The wait is what HubSpot asked for, not the generic backoff.' );
	}

	public function test_429_without_retry_after_uses_the_backoff_and_is_capped_at_three_attempts(): void {
		$this->script(
			array( 'code' => 429 ),
			array( 'code' => 429 ),
			array( 'code' => 429 )
		);
		$result = law_hubspot_request( 'GET', '/crm/v3/properties/contacts' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_hubspot_rate_limited', $result->get_error_code() );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( array( 1, 2 ), $this->sleeps, 'No sleep after the final attempt.' );
	}

	public function test_a_long_retry_after_is_capped(): void {
		$this->script(
			array( 'code' => 429, 'headers' => array( 'retry-after' => '600' ) ),
			array( 'code' => 200, 'body' => array() )
		);
		law_hubspot_request( 'GET', '/crm/v3/properties/contacts' );
		$this->assertSame( array( 30 ), $this->sleeps, 'A cron worker must not sleep ten minutes on one request.' );
	}

	public function test_5xx_and_transport_errors_back_off_and_give_up_after_three(): void {
		$this->script(
			array( 'code' => 503 ),
			new WP_Error( 'http_request_failed', 'cURL error 28: timed out' ),
			array( 'code' => 502 )
		);
		$result = law_hubspot_request( 'POST', '/crm/v3/objects/contacts/batch/update', array( 'inputs' => array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_hubspot_server_error', $result->get_error_code(), 'The last error is the one reported.' );
		$this->assertCount( 3, $this->requests );
		$this->assertSame( array( 1, 2 ), $this->sleeps );
	}

	public function test_5xx_then_success_recovers(): void {
		$this->script(
			array( 'code' => 500 ),
			array( 'code' => 200, 'body' => array( 'ok' => 1 ) )
		);
		$this->assertSame( array( 'ok' => 1 ), law_hubspot_request( 'GET', '/x' ) );
		$this->assertCount( 2, $this->requests );
	}

	public function test_other_4xx_is_not_retried_and_carries_hubspots_message(): void {
		$this->script(
			array(
				'code' => 400,
				'body' => array(
					'status'   => 'error',
					'message'  => 'Property values were not valid',
					'category' => 'VALIDATION_ERROR',
					'errors'   => array(
						array( 'message' => '2026 Sponsor was not one of the allowed options' ),
					),
				),
			)
		);
		$result = law_hubspot_request( 'POST', '/crm/v3/objects/contacts/batch/upsert', array( 'inputs' => array() ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_hubspot_api_error', $result->get_error_code() );
		$this->assertCount( 1, $this->requests, 'A validation error is a bug, not a blip: no retry.' );
		$this->assertSame( array(), $this->sleeps );
		$this->assertStringContainsString( 'Property values were not valid', $result->get_error_message() );
		$this->assertStringContainsString( '2026 Sponsor was not one of the allowed options', $result->get_error_message() );
		$this->assertSame( 400, $result->get_error_data()['status'] );
		$this->assertSame( 'VALIDATION_ERROR', $result->get_error_data()['category'] );
	}

	public function test_401_is_reported_once_for_the_token_check(): void {
		$this->script( array( 'code' => 401, 'body' => array( 'message' => 'Authentication credentials not found.' ) ) );
		$check = law_hubspot_token_check();
		$this->assertInstanceOf( WP_Error::class, $check );
		$this->assertStringContainsString( 'Authentication credentials', $check->get_error_message() );
		$this->assertCount( 1, $this->requests );

		$this->script( array( 'code' => 200, 'body' => array( 'name' => 'email' ) ) );
		$this->assertTrue( law_hubspot_token_check() );
	}

	public function test_the_mock_filter_short_circuits_everything(): void {
		$mock = fn( $mocked, $method, $path ) => '/mocked' === $path ? array( 'from' => 'mock' ) : $mocked;
		add_filter( 'law_hubspot_request_mock', $mock, 10, 3 );
		$result = law_hubspot_request( 'GET', '/mocked' );
		remove_filter( 'law_hubspot_request_mock', $mock, 10 );

		$this->assertSame( array( 'from' => 'mock' ), $result );
		$this->assertSame( array(), $this->requests );
	}

	public function test_batch_wrappers_build_the_documented_shapes(): void {
		$this->script(
			array( 'code' => 200, 'body' => array() ),
			array( 'code' => 200, 'body' => array() ),
			array( 'code' => 200, 'body' => array() )
		);

		law_hubspot_batch_read( array( 'A@Example.test', '123' ), array( 'email', 'contact_type' ) );
		law_hubspot_batch_read( array( '123' ), array( 'email' ), '' );
		law_hubspot_batch_update( array( array( 'id' => 123, 'properties' => array( 'company' => 'X' ) ) ) );

		$this->assertSame( 'email', $this->requests[0]['body']['idProperty'] );
		$this->assertSame( array( array( 'id' => 'A@Example.test' ), array( 'id' => '123' ) ), $this->requests[0]['body']['inputs'] );
		$this->assertSame( array( 'email', 'contact_type' ), $this->requests[0]['body']['properties'] );
		$this->assertArrayNotHasKey( 'idProperty', $this->requests[1]['body'], 'By record ID there is no idProperty.' );
		$this->assertSame( array( array( 'id' => '123', 'properties' => array( 'company' => 'X' ) ) ), $this->requests[2]['body']['inputs'] );

		$this->assertSame( array( array( 1, 2 ), array( 3 ) ), law_hubspot_chunk( array( 1, 2, 3 ), 2 ) );
		$this->assertSame( array(), law_hubspot_chunk( array() ) );
	}

	/* ---- config --------------------------------------------------------- */

	public function test_config_tags_and_options_carry_the_events_year(): void {
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'year' => 2031 ) );
		$config = law_hubspot_config();

		$this->assertSame( 2031, $config['year'] );
		$this->assertSame( '2031 Registered user', law_hubspot_tag( 'registered' ) );
		$this->assertSame( '2031 Event Host', $config['tags']['event_host'] );
		$this->assertSame( '2031 Monday reception', $config['attending_options']['opening-drinks'] );
		$this->assertSame( 'law_events_attending', law_hubspot_property_name( 'events_attending' ) );
		$this->assertSame( '', law_hubspot_property_name( 'nope' ) );
		$this->assertContains( 'Speaker', $config['delegate_types'] );
		$this->assertContains( 'Exhibitor', $config['delegate_types'] );
		// The registration form's own tag function must agree, or the backfill
		// and the live path would write two vocabularies.
		$this->assertSame( '2031 Registered user;2031 Sponsor;2031 Event Host', law_registration_hubspot_tags( array( 'sponsor', 'host' ) ) );
	}
}
