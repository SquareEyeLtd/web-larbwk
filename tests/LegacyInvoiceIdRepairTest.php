<?php
/**
 * migration/repair-stripe-invoice-ids.php: the events invoiced by the retired
 * Make scenario hold the hosted URL and no invoice ID, and this backfills the
 * ID from Stripe. Plus the two stopgaps that protect those events until it has
 * run: invoice creation refuses, and a cancellation says the invoice could not
 * be voided instead of saying nothing.
 */
class LegacyInvoiceIdRepairTest extends LAW_Test_Case {

	/** An event as migration leaves one that Make had invoiced. */
	private function make_legacy_event( $entry_id = 190, $url = 'https://invoice.stripe.com/i/acct_1/live_LEGACY', $status = 'law-approved' ): int {
		return $this->make_event(
			array(
				'_law_gf_entry_id'        => $entry_id,
				'_law_stripe_invoice_url' => $url,
				'_law_invoice_email'      => 'host@example.test',
				'_law_fee_pence'          => 120000,
				'_law_payment_status'     => 'unpaid',
			),
			$status
		);
	}

	private function log_text( $event_id ): string {
		return implode( "\n", wp_list_pluck( law_event_log_entries( $event_id ), 'comment_content' ) );
	}

	/* The ambiguous case: two invoices, and a person chooses ___________________ */

	/**
	 * Two invoices carrying the entry ID and neither carrying the stored URL is
	 * the only way this panel reaches "more than one matches": two invoices
	 * cannot share a hosted_invoice_url, so a URL hit would have won outright.
	 */
	private function queue_two_candidates(): void {
		$GLOBALS['law_test_stripe_queue'] = array(
			array(
				'object' => 'search_result',
				'data'   => array(
					array(
						'id'                 => 'in_VOIDED',
						'customer'           => 'cus_LEGACY',
						'status'             => 'void',
						'total'              => 144000,
						'amount_paid'        => 0,
						'created'            => 1757000000,
						'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_OLD',
						'metadata'           => array( 'gf_entry_id' => '190' ),
					),
					array(
						'id'                 => 'in_PAID',
						'customer'           => 'cus_LEGACY',
						'status'             => 'paid',
						'total'              => 144000,
						'amount_paid'        => 144000,
						'created'            => 1757600000,
						'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_NEW',
						'metadata'           => array( 'gf_entry_id' => '190' ),
					),
				),
			),
		);
	}

	public function test_two_matches_write_nothing_and_come_back_as_a_choice(): void {
		$event = $this->make_legacy_event( 190 );
		$this->queue_two_candidates();

		$look = law_events_invoice_id_lookup( $event );

		$this->assertSame( '', $look['invoice_id'], 'Guessing between two invoices is exactly what this repair must not do.' );
		$this->assertCount( 2, $look['candidates'] );
		$this->assertSame( 'in_PAID', $look['candidates'][0]['id'], 'Paid first: it is the answer in almost every real case.' );
		$this->assertSame( 144000, $look['candidates'][0]['amount_paid'] );
		$this->assertStringContainsString( 'Choose the right one', $look['error'] );
	}

	public function test_a_chosen_invoice_is_refetched_and_recorded(): void {
		$event = $this->make_legacy_event( 190 );
		$GLOBALS['law_test_stripe_queue'] = array(
			array(
				'id'                 => 'in_PAID',
				'customer'           => 'cus_LEGACY',
				'status'             => 'paid',
				'total'              => 144000,
				'amount_paid'        => 144000,
				'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_NEW',
				'metadata'           => array( 'gf_entry_id' => '190' ),
			),
		);

		$result = law_events_invoice_id_apply( array( $event ), array( $event => 'in_PAID' ) );

		$this->assertSame( 1, $result['repaired'] );
		$this->assertSame( 'in_PAID', law_event_meta( $event, '_law_stripe_invoice_id' ) );
		$this->assertSame( 'cus_LEGACY', law_event_meta( $event, '_law_stripe_customer_id' ) );
		$this->assertStringContainsString( 'chosen by hand', $this->log_text( $event ) );
		$this->assertSame(
			array( array( 'method' => 'GET', 'path' => '/v1/invoices/in_PAID' ) ),
			$GLOBALS['law_test_stripe_calls'],
			'A choice is one fetch of that invoice, not a second search.'
		);
	}

	public function test_a_chosen_invoice_belonging_to_nobody_is_refused(): void {
		// Choosing resolves an ambiguity; it does not waive the evidence. An ID
		// that carries neither this event's URL nor its entry ID is not written,
		// however deliberately it was typed.
		$event = $this->make_legacy_event( 190 );
		$GLOBALS['law_test_stripe_queue'] = array(
			array(
				'id'                 => 'in_SOMEONE_ELSE',
				'customer'           => 'cus_OTHER',
				'status'             => 'paid',
				'total'              => 60000,
				'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_OTHER',
				'metadata'           => array( 'gf_entry_id' => '999' ),
			),
		);

		$result = law_events_invoice_id_apply( array( $event ), array( $event => 'in_SOMEONE_ELSE' ) );

		$this->assertSame( 0, $result['repaired'] );
		$this->assertStringContainsString( 'neither this event', $result['skipped'][0] );
		$this->assertSame( '', (string) law_event_meta( $event, '_law_stripe_invoice_id' ) );
	}

	public function test_a_chosen_invoice_another_event_already_holds_is_refused(): void {
		$event = $this->make_legacy_event( 190 );
		$other = $this->make_legacy_event( 191, 'https://invoice.stripe.com/i/acct_1/live_OTHER' );
		law_event_update_meta( $other, '_law_stripe_invoice_id', 'in_PAID' );

		$GLOBALS['law_test_stripe_queue'] = array(
			array(
				'id'                 => 'in_PAID',
				'customer'           => 'cus_LEGACY',
				'status'             => 'paid',
				'total'              => 144000,
				'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_NEW',
				'metadata'           => array( 'gf_entry_id' => '190' ),
			),
		);

		$result = law_events_invoice_id_apply( array( $event ), array( $event => 'in_PAID' ) );

		$this->assertSame( 0, $result['repaired'] );
		$this->assertStringContainsString( 'already recorded against event', $result['skipped'][0] );
	}

	public function test_scan_lists_only_events_missing_the_invoice_id(): void {
		$legacy   = $this->make_legacy_event();
		$complete = $this->make_legacy_event( 191, 'https://invoice.stripe.com/i/acct_1/live_DONE' );
		law_event_update_meta( $complete, '_law_stripe_invoice_id', 'in_ALREADY' );
		$no_invoice = $this->make_event( array(), 'law-proposed' );

		$ids = wp_list_pluck( law_events_invoice_id_scan(), 'event_id' );

		$this->assertContains( $legacy, $ids, 'A URL with no ID is exactly what this panel is for.' );
		$this->assertNotContains( $complete, $ids, 'An event that already holds its invoice ID needs nothing.' );
		$this->assertNotContains( $no_invoice, $ids, 'An event that was never invoiced is none of this panel\'s business.' );
	}

	public function test_metadata_search_match_records_the_invoice_and_customer(): void {
		$event = $this->make_legacy_event( 190 );

		$GLOBALS['law_test_stripe_queue'] = array(
			array(
				'object' => 'search_result',
				'data'   => array(
					array(
						'id'                 => 'in_LEGACY',
						'customer'           => 'cus_LEGACY',
						'status'             => 'open',
						'total'              => 144000,
						'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_LEGACY',
						'metadata'           => array( 'gf_entry_id' => '190', 'law_reference' => 'LAW26-00121' ),
					),
				),
			),
		);

		$result = law_events_invoice_id_apply( array( $event ) );

		$this->assertSame( 1, $result['repaired'] );
		$this->assertSame( array(), $result['skipped'] );
		$this->assertSame( 'in_LEGACY', law_event_meta( $event, '_law_stripe_invoice_id' ) );
		$this->assertSame( 'cus_LEGACY', law_event_meta( $event, '_law_stripe_customer_id' ), 'The customer was never recorded either, and the invoice names it.' );
		$this->assertStringContainsString( 'in_LEGACY', $this->log_text( $event ) );

		// One search call and nothing else: the customer route is a fallback,
		// not a second opinion to pay for every time.
		$this->assertSame(
			array( '/v1/invoices/search' ),
			wp_list_pluck( $GLOBALS['law_test_stripe_calls'], 'path' )
		);
	}

	public function test_customer_route_matches_on_the_hosted_url_when_search_is_unavailable(): void {
		$event = $this->make_legacy_event( 190 );

		$GLOBALS['law_test_stripe_queue'] = array(
			new WP_Error( 'law_stripe_api_error', 'search is not enabled on this account' ),
			array( 'object' => 'list', 'data' => array( array( 'id' => 'cus_LEGACY' ) ) ),
			array(
				'object' => 'list',
				'data'   => array(
					// A different invoice on the same customer, and the real one.
					array( 'id' => 'in_OTHER', 'status' => 'paid', 'total' => 6000, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_SOMETHINGELSE', 'metadata' => array() ),
					array( 'id' => 'in_LEGACY', 'customer' => 'cus_LEGACY', 'status' => 'open', 'total' => 144000, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_LEGACY', 'metadata' => array() ),
				),
			),
		);

		$look = law_events_invoice_id_lookup( $event );

		$this->assertSame( 'in_LEGACY', $look['invoice_id'] );
		$this->assertSame( 'hosted invoice URL', $look['matched_on'] );
		$this->assertNotEmpty( $look['notes'], 'The failed search is reported rather than swallowed.' );
	}

	public function test_nothing_is_written_when_no_invoice_matches(): void {
		$event = $this->make_legacy_event( 190 );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'object' => 'search_result', 'data' => array() ),
			array( 'object' => 'list', 'data' => array( array( 'id' => 'cus_LEGACY' ) ) ),
			array(
				'object' => 'list',
				'data'   => array(
					array( 'id' => 'in_SOMEONEELSE', 'status' => 'paid', 'total' => 6000, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_NOPE', 'metadata' => array( 'gf_entry_id' => '999' ) ),
				),
			),
		);

		$result = law_events_invoice_id_apply( array( $event ) );

		$this->assertSame( 0, $result['repaired'] );
		$this->assertSame( '', (string) law_event_meta( $event, '_law_stripe_invoice_id' ) );
		$this->assertNotEmpty( $result['skipped'] );
	}

	public function test_two_matching_invoices_are_reported_and_left_alone(): void {
		$event = $this->make_legacy_event( 190, 'https://invoice.stripe.com/i/acct_1/live_GONE' );

		// Both carry the entry ID, neither carries the stored URL: Make ran
		// twice, and only a human can say which invoice the host is holding.
		$GLOBALS['law_test_stripe_queue'] = array(
			array(
				'object' => 'search_result',
				'data'   => array(
					array( 'id' => 'in_ONE', 'status' => 'void', 'total' => 144000, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_ONE', 'metadata' => array( 'gf_entry_id' => '190' ) ),
					array( 'id' => 'in_TWO', 'status' => 'open', 'total' => 144000, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_TWO', 'metadata' => array( 'gf_entry_id' => '190' ) ),
				),
			),
		);

		$look = law_events_invoice_id_lookup( $event );

		$this->assertSame( '', $look['invoice_id'] );
		$this->assertCount( 2, $look['ambiguous'] );
		$this->assertStringContainsString( 'More than one invoice', $look['error'] );
	}

	public function test_an_invoice_another_event_already_holds_is_refused(): void {
		$owner = $this->make_legacy_event( 500, 'https://invoice.stripe.com/i/acct_1/live_OWNER' );
		law_event_update_meta( $owner, '_law_stripe_invoice_id', 'in_SHARED' );
		$event = $this->make_legacy_event( 190 );

		$GLOBALS['law_test_stripe_queue'] = array(
			array(
				'object' => 'search_result',
				'data'   => array(
					array( 'id' => 'in_SHARED', 'status' => 'open', 'total' => 144000, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_LEGACY', 'metadata' => array( 'gf_entry_id' => '190' ) ),
				),
			),
		);

		$look = law_events_invoice_id_lookup( $event );

		$this->assertSame( '', $look['invoice_id'], 'One invoice belongs to one event; writing it twice would corrupt both.' );
		$this->assertStringContainsString( 'already recorded against event', $look['error'] );
	}

	public function test_invoice_creation_refuses_while_the_legacy_invoice_has_no_id(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_legacy_event( 190 );

		$result = law_stripe_create_and_send_invoice( $event );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'law_legacy_invoice', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'Refused before Stripe is touched at all.' );
		$this->assertSame( '', (string) law_event_meta( $event, '_law_stripe_error' ), 'A refusal is not a Stripe failure and must not raise the alert.' );
	}

	public function test_invoice_creation_still_works_once_the_id_is_recorded(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_legacy_event( 190 );
		law_event_update_meta( $event, '_law_stripe_invoice_id', 'in_LEGACY' );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'object' => 'list', 'data' => array( array( 'id' => 'cus_LEGACY' ) ) ), // customer search by email
			array( 'id' => 'cus_LEGACY', 'object' => 'customer' ),                          // update
			array( 'id' => 'in_LEGACY', 'object' => 'invoice', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/acct_1/live_LEGACY' ),
		);

		$result = law_stripe_create_and_send_invoice( $event );

		$this->assertIsArray( $result );
		$this->assertSame( 'in_LEGACY', $result['id'], 'The repaired ID lets the existing invoice be resumed rather than duplicated.' );
		$creates = array_filter(
			$GLOBALS['law_test_stripe_calls'],
			fn( $call ) => 'POST' === $call['method'] && '/v1/invoices' === $call['path']
		);
		$this->assertCount( 0, $creates );
	}

	public function test_cancelling_reports_a_legacy_invoice_it_cannot_void(): void {
		$event = $this->make_legacy_event( 190 );

		$outcome = law_stripe_void_invoice( $event, 0 );

		$this->assertSame( 'failed', $outcome, 'Silence would leave a payable invoice behind a "nothing is due" email.' );
		$this->assertSame( array(), $GLOBALS['law_test_stripe_calls'], 'There is no ID to void with, so Stripe is not called.' );
		$this->assertStringContainsString( 'could NOT be voided automatically', $this->log_text( $event ) );
	}

	public function test_cancelling_an_uninvoiced_event_stays_quiet(): void {
		$event = $this->make_event( array( '_law_payment_status' => 'free' ), 'law-approved' );

		$this->assertSame( 'none', law_stripe_void_invoice( $event, 0 ) );
		$this->assertStringContainsString( 'no Stripe invoice on record', $this->log_text( $event ) );
	}
}
