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

// Never send real email from tests.
add_filter( 'pre_wp_mail', '__return_true', 1000 );

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
