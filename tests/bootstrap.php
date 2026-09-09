<?php
/**
 * Test bootstrap: loads the real local WordPress (integration-style), with
 * Stripe and mail neutralised. Tests create their own fixtures and delete
 * them in tearDown; nothing existing is modified.
 */

// A known webhook secret for signature tests (wp-config leaves it undefined locally).
if ( ! defined( 'LAW_STRIPE_WEBHOOK_SECRET' ) ) {
	define( 'LAW_STRIPE_WEBHOOK_SECRET', 'whsec_test_secret_for_unit_tests' );
}

$wp_load = dirname( __DIR__, 4 ) . '/wp-load.php';
if ( ! file_exists( $wp_load ) ) {
	fwrite( STDERR, "Cannot find wp-load.php at {$wp_load}\n" );
	exit( 1 );
}
require_once $wp_load;

global $wpdb;

// Never send real email from tests.
add_filter( 'pre_wp_mail', '__return_true', 1000 );

// Roll each test back rather than deleting its fixtures (see LAW_Test_Case).
//
// Every committed write on the local MariaDB costs ~19ms: innodb_flush_log_at_trx_commit
// is 1 with O_DIRECT on btrfs, and with autocommit on, every single statement is
// its own transaction and so gets its own fsync. A profiled wp_insert_user was
// 45 queries, 0.314s wall, of which 0.305s was waiting on the disk and 0.009s
// was PHP. Across ~180 tests that create and then hard-delete a few hundred
// users, events and bookings, that fsync tax was essentially the whole runtime.
//
// With autocommit off, a test's writes stay in one uncommitted transaction that
// LAW_Test_Case::tearDown() rolls back: one flush per test instead of hundreds,
// and the expensive teardown deletes disappear entirely. Measured on a real
// InnoDB table, batching 100 inserts into one commit is ~97x faster.
//
// It also makes fixture cleanup unconditional. The old teardown only ran on a
// clean finish, so an interrupted or fatally-erroring run left its users and
// events behind; MariaDB rolls an open transaction back when the connection
// drops, however the run ends.
$wpdb->query( 'SET autocommit = 0' );

// Tests hash a lot of passwords: every fixture account, plus the accounts the
// booking and co-owner engines create for colleagues. PHP 8.4 raised bcrypt's
// default cost to 12 and WordPress passes no options, so each hash costs ~270ms
// here, about 0.24s of every wp_insert_user. Cost 4 is bcrypt's minimum: still a
// real hash from the code's point of view, just not a deliberately slow one.
// Nothing under test asserts anything about hash strength.
add_filter(
	'wp_hash_password_options',
	function ( $options ) {
		$options['cost'] = 4;
		return $options;
	}
);

// Never hit the Stripe network from tests: every request must be mocked by
// the test case; an unmocked call fails loudly.
add_filter(
	'law_stripe_request_mock',
	function ( $mocked, $method, $path ) {
		if ( null !== $mocked ) {
			return $mocked;
		}
		$queue = $GLOBALS['law_test_stripe_queue'] ?? array();
		if ( $queue ) {
			$next = array_shift( $GLOBALS['law_test_stripe_queue'] );
			$GLOBALS['law_test_stripe_calls'][] = array( 'method' => $method, 'path' => $path );
			return $next;
		}
		return new WP_Error( 'law_test_unmocked', "Unmocked Stripe call in tests: {$method} {$path}" );
	},
	10,
	3
);

require_once __DIR__ . '/class-law-test-case.php';
