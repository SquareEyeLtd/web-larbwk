<?php
/**
 * functions/hubspot/queue.php (HUBSPOT_SYNC.md §9, HubSpotQueueTest): enqueue
 * coalesces, failures back off and park, the lock prevents overlap. The sync
 * step is stood in for by the law_hubspot_sync_batch filter, so none of this
 * depends on a rule existing.
 */

class HubSpotQueueTest extends LAW_Test_Case {

	/** @var callable|null What the fake sync step returns for a batch. */
	private $sync = null;
	/** @var array[] The batches the worker handed to the sync step. */
	private array $batches = array();

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		law_hubspot_install_tables();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->sync    = null;
		$this->batches = array();
		add_filter( 'law_hubspot_mode', array( $this, 'dry_mode' ) );
		add_filter( 'law_hubspot_sync_batch', array( $this, 'fake_sync' ), 10, 2 );
		delete_option( LAW_HUBSPOT_LOCK_OPTION );
	}

	protected function tearDown(): void {
		remove_filter( 'law_hubspot_mode', array( $this, 'dry_mode' ) );
		remove_filter( 'law_hubspot_sync_batch', array( $this, 'fake_sync' ), 10 );
		parent::tearDown();
	}

	public function dry_mode() {
		return 'dry';
	}

	public function fake_sync( $results, array $emails ) {
		$this->batches[] = $emails;
		if ( null === $this->sync ) {
			return $results;
		}
		return call_user_func( $this->sync, $emails );
	}

	private function all_ok() {
		$this->sync = fn( array $emails ) => array_fill_keys( $emails, true );
	}

	private function all_fail( $message = 'HubSpot: nope' ) {
		$this->sync = fn( array $emails ) => array_fill_keys( $emails, new WP_Error( 'law_hubspot_api_error', $message ) );
	}

	/* ---- enqueue -------------------------------------------------------- */

	public function test_enqueue_coalesces_and_normalises(): void {
		$this->assertTrue( law_hubspot_enqueue( 'Person@Example.test', 'user_register' ) );
		$this->assertTrue( law_hubspot_enqueue( '  person@example.test ', 'profile_update' ) );
		$this->assertTrue( law_hubspot_enqueue( 'PERSON@EXAMPLE.TEST', 'booking' ) );

		$stats = law_hubspot_queue_stats();
		$this->assertSame( 1, $stats['total'], 'Three triggers for one person are one row.' );
		$this->assertSame( 1, $stats['due'] );

		$row = law_hubspot_queue_row( 'person@example.test' );
		$this->assertSame( 'person@example.test', $row->email );
		$this->assertSame( 'booking', $row->reason, 'The latest reason wins.' );
		$this->assertSame( 0, (int) $row->attempts );
	}

	public function test_enqueue_ignores_what_is_not_an_address(): void {
		$this->assertFalse( law_hubspot_enqueue( '' ) );
		$this->assertFalse( law_hubspot_enqueue( 'not an email' ) );
		$this->assertFalse( law_hubspot_enqueue( 'missing@tld' ) );
		$this->assertSame( 0, law_hubspot_queue_stats()['total'] );
	}

	public function test_queue_everyone_needs_the_rules(): void {
		if ( function_exists( 'law_hubspot_people' ) ) {
			$this->markTestSkipped( 'Phase 2 installed the rules; this guard no longer applies.' );
		}
		$result = law_hubspot_queue_everyone();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_hubspot_no_rules', $result->get_error_code() );
	}

	/* ---- the worker ----------------------------------------------------- */

	public function test_successful_rows_leave_the_queue(): void {
		$this->all_ok();
		law_hubspot_enqueue( 'a@example.test', 'x' );
		law_hubspot_enqueue( 'b@example.test', 'x' );

		$summary = law_hubspot_process_queue();

		$this->assertSame( 2, $summary['processed'] );
		$this->assertSame( 2, $summary['succeeded'] );
		$this->assertSame( 0, $summary['failed'] );
		$this->assertSame( 'dry', $summary['mode'] );
		$this->assertSame( array( array( 'a@example.test', 'b@example.test' ) ), $this->batches, 'One sync call for the whole batch.' );
		$this->assertSame( 0, law_hubspot_queue_stats()['total'] );

		$last = law_hubspot_last_run();
		$this->assertSame( 2, $last['succeeded'] );
		$this->assertEqualsWithDelta( time(), $last['at'], 5 );
	}

	public function test_the_worker_respects_the_limit_and_takes_the_oldest_first(): void {
		$this->all_ok();
		global $wpdb;
		foreach ( array( 'c', 'a', 'b' ) as $i => $letter ) {
			law_hubspot_enqueue( $letter . '@example.test', 'x' );
			// Space the due times out so the order is the queue's, not the insert's.
			$wpdb->update( law_hubspot_queue_table(), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 100 + $i ) ), array( 'email' => $letter . '@example.test' ) );
		}

		$summary = law_hubspot_process_queue( 2 );
		$this->assertSame( 2, $summary['processed'] );
		$this->assertSame( array( array( 'c@example.test', 'a@example.test' ) ), $this->batches );
		$this->assertSame( 1, law_hubspot_queue_stats()['total'] );
	}

	public function test_failures_back_off_through_the_schedule_and_then_park(): void {
		$this->all_fail( 'HubSpot: 2026 Sponsor was not one of the allowed options' );
		$this->isolate_option( 'law_hubspot_last_run' );
		law_hubspot_enqueue( 'p@example.test', 'approve' );

		$backoff = law_hubspot_setting( 'backoff' );
		$this->assertCount( 4, $backoff, 'The documented schedule: 5 min, 30 min, 2 h, 12 h.' );

		global $wpdb;
		foreach ( $backoff as $i => $delay ) {
			$summary = law_hubspot_process_queue();
			$this->assertSame( 1, $summary['failed'], 'Attempt ' . ( $i + 1 ) );
			$this->assertSame( 0, $summary['parked'] );

			$row = law_hubspot_queue_row( 'p@example.test' );
			$this->assertSame( $i + 1, (int) $row->attempts );
			$this->assertSame( 0, (int) $row->parked );
			$this->assertEqualsWithDelta( time() + $delay, strtotime( $row->next_attempt_at . ' UTC' ), 5, 'Attempt ' . ( $i + 1 ) . ' waits ' . $delay . 's' );
			$this->assertStringContainsString( '2026 Sponsor', $row->last_error );

			// Not due: a run now takes nothing.
			$this->assertSame( 0, law_hubspot_process_queue()['processed'] );

			// Make it due for the next loop.
			$wpdb->update( law_hubspot_queue_table(), array( 'next_attempt_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ), array( 'email' => 'p@example.test' ) );
		}

		// The fifth failure parks it.
		$summary = law_hubspot_process_queue();
		$this->assertSame( 1, $summary['failed'] );
		$this->assertSame( 1, $summary['parked'] );
		$row = law_hubspot_queue_row( 'p@example.test' );
		$this->assertSame( 1, (int) $row->parked );
		$this->assertSame( 5, (int) $row->attempts );

		// Parked rows are not picked up, and show on the parked list.
		$this->assertSame( 0, law_hubspot_process_queue()['processed'] );
		$this->assertSame( array( 'p@example.test' ), array_map( fn( $r ) => $r->email, law_hubspot_queue_parked() ) );
		$stats = law_hubspot_queue_stats();
		$this->assertSame( 1, $stats['parked'] );
		$this->assertSame( 0, $stats['due'] );

		// Every failure was logged against the person.
		$log = law_hubspot_log_recent( 10, 'p@example.test' );
		$this->assertCount( 5, $log );
		$this->assertSame( 'error', $log[0]->result );
		$this->assertStringContainsString( '"parked":true', $log[0]->detail );
		$this->assertStringContainsString( '"reason":"approve"', $log[0]->detail );
	}

	public function test_retry_parked_gives_a_clean_slate(): void {
		$this->all_fail();
		global $wpdb;
		law_hubspot_enqueue( 'p@example.test', 'x' );
		$wpdb->update( law_hubspot_queue_table(), array( 'parked' => 1, 'attempts' => 5 ), array( 'email' => 'p@example.test' ) );

		$this->assertSame( 1, law_hubspot_queue_retry_parked() );
		$row = law_hubspot_queue_row( 'p@example.test' );
		$this->assertSame( 0, (int) $row->parked );
		$this->assertSame( 0, (int) $row->attempts );
		$this->assertSame( 1, law_hubspot_queue_stats()['due'] );
	}

	public function test_re_enqueuing_a_parked_row_unparks_it_for_one_more_try(): void {
		$this->all_fail();
		global $wpdb;
		law_hubspot_enqueue( 'p@example.test', 'x' );
		$wpdb->update( law_hubspot_queue_table(), array( 'parked' => 1, 'attempts' => 5 ), array( 'email' => 'p@example.test' ) );

		law_hubspot_enqueue( 'p@example.test', 'profile_update' );
		$row = law_hubspot_queue_row( 'p@example.test' );
		$this->assertSame( 0, (int) $row->parked, 'A new change gets another go.' );
		$this->assertSame( 5, (int) $row->attempts, 'Attempts are kept, so a still-broken contact parks again rather than cycling.' );

		$summary = law_hubspot_process_queue();
		$this->assertSame( 1, $summary['processed'] );
		$this->assertSame( 1, $summary['parked'] );
	}

	public function test_a_whole_batch_error_fails_every_row_without_parking_them_at_once(): void {
		$this->sync = fn() => new WP_Error( 'law_hubspot_unconfigured', 'HubSpot token is not configured (LAW_HUBSPOT_TOKEN).' );
		law_hubspot_enqueue( 'a@example.test', 'x' );
		law_hubspot_enqueue( 'b@example.test', 'x' );

		$summary = law_hubspot_process_queue();
		$this->assertSame( 2, $summary['failed'] );
		$this->assertSame( 0, $summary['parked'] );
		foreach ( array( 'a', 'b' ) as $letter ) {
			$row = law_hubspot_queue_row( $letter . '@example.test' );
			$this->assertSame( 1, (int) $row->attempts );
			$this->assertStringContainsString( 'LAW_HUBSPOT_TOKEN', $row->last_error );
		}
	}

	public function test_a_row_the_sync_step_forgot_is_a_failure_not_a_success(): void {
		$this->sync = fn( array $emails ) => array( 'a@example.test' => true );
		law_hubspot_enqueue( 'a@example.test', 'x' );
		law_hubspot_enqueue( 'b@example.test', 'x' );

		$summary = law_hubspot_process_queue();
		$this->assertSame( 1, $summary['succeeded'] );
		$this->assertSame( 1, $summary['failed'] );
		$this->assertNull( law_hubspot_queue_row( 'a@example.test' ) );
		$this->assertStringContainsString( 'returned nothing', law_hubspot_queue_row( 'b@example.test' )->last_error );
	}

	public function test_mixed_results_are_applied_per_address(): void {
		$this->sync = fn( array $emails ) => array(
			'ok@example.test'  => array( 'id' => '1' ),
			'bad@example.test' => new WP_Error( 'law_hubspot_api_error', 'HubSpot: invalid email' ),
		);
		law_hubspot_enqueue( 'ok@example.test', 'x' );
		law_hubspot_enqueue( 'bad@example.test', 'x' );

		$summary = law_hubspot_process_queue();
		$this->assertSame( 1, $summary['succeeded'] );
		$this->assertSame( 1, $summary['failed'] );
		$this->assertNull( law_hubspot_queue_row( 'ok@example.test' ), 'Any non-error result is success.' );
		$this->assertSame( 1, (int) law_hubspot_queue_row( 'bad@example.test' )->attempts );
	}

	public function test_without_rules_or_a_stand_in_the_worker_says_so(): void {
		if ( function_exists( 'law_hubspot_sync_batch' ) ) {
			$this->markTestSkipped( 'Phase 2 installed the sync step.' );
		}
		$this->sync = null; // the filter passes null through
		law_hubspot_enqueue( 'a@example.test', 'x' );

		$summary = law_hubspot_process_queue();
		$this->assertSame( 1, $summary['failed'] );
		$this->assertStringContainsString( 'phase 2', law_hubspot_queue_row( 'a@example.test' )->last_error );
	}

	/* ---- off, lock ------------------------------------------------------ */

	public function test_the_worker_does_nothing_when_the_mode_is_off(): void {
		remove_filter( 'law_hubspot_mode', array( $this, 'dry_mode' ) );
		add_filter( 'law_hubspot_mode', '__return_empty_string' ); // 'off' after validation
		$this->all_ok();
		law_hubspot_enqueue( 'a@example.test', 'x' );

		$result = law_hubspot_process_queue();
		remove_filter( 'law_hubspot_mode', '__return_empty_string' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_hubspot_off', $result->get_error_code() );
		$this->assertSame( array(), $this->batches );
		$this->assertSame( 1, law_hubspot_queue_stats()['total'], 'The row waits for the mode to come back.' );
	}

	public function test_the_lock_prevents_two_runs_overlapping(): void {
		$this->all_ok();
		law_hubspot_enqueue( 'a@example.test', 'x' );

		$this->assertTrue( law_hubspot_worker_lock(), 'First caller takes the lock.' );
		$result = law_hubspot_process_queue();
		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_hubspot_locked', $result->get_error_code() );
		$this->assertSame( array(), $this->batches );

		law_hubspot_worker_unlock();
		$this->assertSame( 1, law_hubspot_process_queue()['succeeded'] );
		$this->assertFalse( get_option( LAW_HUBSPOT_LOCK_OPTION ), 'The worker releases the lock when it finishes.' );
	}

	public function test_a_stale_lock_from_a_crashed_run_is_broken(): void {
		$this->all_ok();
		law_hubspot_enqueue( 'a@example.test', 'x' );
		add_option( LAW_HUBSPOT_LOCK_OPTION, time() - LAW_HUBSPOT_LOCK_TTL - 60, '', false );

		$this->assertSame( 1, law_hubspot_process_queue()['succeeded'] );
	}

	public function test_the_lock_is_released_even_when_the_sync_step_throws(): void {
		$this->sync = function () {
			throw new RuntimeException( 'boom' );
		};
		law_hubspot_enqueue( 'a@example.test', 'x' );

		try {
			law_hubspot_process_queue();
			$this->fail( 'The exception should propagate.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'boom', $e->getMessage() );
		}
		$this->assertFalse( get_option( LAW_HUBSPOT_LOCK_OPTION ) );
	}

	/* ---- cron and log --------------------------------------------------- */

	public function test_the_five_minute_schedule_exists(): void {
		$schedules = wp_get_schedules();
		$this->assertSame( 300, $schedules[ LAW_HUBSPOT_CRON_SCHEDULE ]['interval'] );
	}

	public function test_log_prune_drops_only_old_rows(): void {
		global $wpdb;
		law_hubspot_log( 'a@example.test', 'sync', 'recent', 'ok' );
		law_hubspot_log( 'a@example.test', 'sync', 'old', 'ok' );
		$wpdb->update( law_hubspot_log_table(), array( 'created_at' => gmdate( 'Y-m-d H:i:s', time() - 100 * DAY_IN_SECONDS ) ), array( 'detail' => 'old' ) );

		$this->assertGreaterThanOrEqual( 1, law_hubspot_log_prune() );
		$rows = law_hubspot_log_recent( 10, 'a@example.test' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'recent', $rows[0]->detail );
	}
}
