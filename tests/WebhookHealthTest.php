<?php
/**
 * stripe/health.php: what Stripe has registered against this site, compared
 * against what law_stripe_webhook_dispatch() actually acts on.
 *
 * The failure this guards is silent in both directions. An endpoint subscribed
 * to the wrong set delivers nothing and logs nothing on either side, and the
 * first symptom is a paid event that will not take bookings.
 */

require_once __DIR__ . '/class-law-test-case.php';

class WebhookHealthTest extends LAW_Test_Case {

	private function endpoint_list( array $endpoints ): array {
		return array( 'object' => 'list', 'data' => $endpoints );
	}

	private function ours( array $overrides = array() ): array {
		return array_merge(
			array(
				'id'             => 'we_TEST',
				'url'            => law_stripe_webhook_url(),
				'status'         => 'enabled',
				'enabled_events' => law_stripe_webhook_event_types(),
			),
			$overrides
		);
	}

	public function test_the_event_list_is_sorted_and_covers_both_halves_of_the_dispatch(): void {
		$types = law_stripe_webhook_event_types();

		$sorted = $types;
		sort( $sorted );
		$this->assertSame( $sorted, $types, 'Sorted, so a comparison against Stripe\'s list is order-independent.' );

		// The host-fee half and the attendee half both have to be subscribed:
		// one endpoint serves both, told apart by metadata rather than type.
		$this->assertContains( 'invoice.paid', $types );
		$this->assertContains( 'charge.refunded', $types );
		$this->assertContains( 'checkout.session.completed', $types );
		$this->assertContains( 'setup_intent.succeeded', $types );
	}

	public function test_a_correct_endpoint_reports_ok(): void {
		$GLOBALS['law_test_stripe_queue'] = array( $this->endpoint_list( array( $this->ours() ) ) );

		$health = law_stripe_webhook_health();

		$this->assertSame( 'ok', $health['verdict'] );
		$this->assertSame( array(), $health['missing'] );
	}

	public function test_a_wildcard_subscription_counts_as_covering_everything(): void {
		$GLOBALS['law_test_stripe_queue'] = array(
			$this->endpoint_list( array( $this->ours( array( 'enabled_events' => array( '*' ) ) ) ) )
		);

		$health = law_stripe_webhook_health();

		$this->assertSame( 'ok', $health['verdict'], 'Noisy, but nothing this module acts on is being lost.' );
	}

	public function test_a_missing_event_type_is_named(): void {
		$short = array_values( array_diff( law_stripe_webhook_event_types(), array( 'invoice.paid' ) ) );
		$GLOBALS['law_test_stripe_queue'] = array(
			$this->endpoint_list( array( $this->ours( array( 'enabled_events' => $short ) ) ) )
		);

		$health = law_stripe_webhook_health();

		$this->assertSame( 'missing_events', $health['verdict'] );
		$this->assertSame( array( 'invoice.paid' ), $health['missing'] );
		$this->assertStringContainsString( 'invoice.paid', $health['detail'] );
	}

	public function test_a_disabled_endpoint_is_reported_before_its_event_list(): void {
		$GLOBALS['law_test_stripe_queue'] = array(
			$this->endpoint_list( array( $this->ours( array( 'status' => 'disabled' ) ) ) )
		);

		$health = law_stripe_webhook_health();

		$this->assertSame( 'disabled', $health['verdict'] );
	}

	public function test_no_endpoint_names_the_cli_as_the_expected_local_answer(): void {
		$GLOBALS['law_test_stripe_queue'] = array( $this->endpoint_list( array() ) );

		$health = law_stripe_webhook_health();

		$this->assertSame( 'no_endpoint', $health['verdict'] );
		$this->assertStringContainsString( 'stripe listen', $health['detail'] );
		$this->assertStringContainsString( '--events ' . implode( ',', law_stripe_webhook_event_types() ), $health['listen_command'] );
		$this->assertStringContainsString( law_stripe_webhook_url(), $health['listen_command'] );
	}

	public function test_the_right_path_on_the_wrong_host_is_called_out(): void {
		$path  = (string) wp_parse_url( law_stripe_webhook_url(), PHP_URL_PATH );
		$stray = array( 'id' => 'we_STRAY', 'url' => 'https://staging.example.test' . $path, 'status' => 'enabled', 'enabled_events' => array( '*' ) );
		$GLOBALS['law_test_stripe_queue'] = array( $this->endpoint_list( array( $stray ) ) );

		$health = law_stripe_webhook_health();

		$this->assertSame( 'no_endpoint', $health['verdict'] );
		$this->assertCount( 1, $health['near_misses'], 'A staging endpoint left pointing at production is the commonest way this breaks.' );
	}

	public function test_a_stripe_error_is_not_reported_as_a_broken_endpoint(): void {
		// The queue is empty, so the mock returns a WP_Error. A restricted key
		// that cannot read endpoints must not be announced as a missing one.
		$health = law_stripe_webhook_health();

		$this->assertSame( 'unreadable', $health['verdict'] );
		$this->assertStringContainsString( 'restricted key', $health['detail'] );
	}
}
