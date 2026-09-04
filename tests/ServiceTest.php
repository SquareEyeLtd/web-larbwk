<?php
/**
 * stripe/service.php: partial mid-flow failure followed by a retry must never
 * produce a second invoice (the double-billing guard), and leftover drafts
 * are cleaned up before a fresh create.
 */
class ServiceTest extends LAW_Test_Case {

	public function test_retry_after_send_failure_resumes_the_same_invoice(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'uk' ) );
		law_event_snapshot_fee( $event );

		// Attempt 1: invoice created and line added, then /send times out.
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'object' => 'list', 'data' => array() ),          // customer search
			array( 'id' => 'cus_1', 'object' => 'customer' ),        // create customer
			array( 'id' => 'in_ORIGINAL', 'object' => 'invoice' ),   // create invoice
			array( 'id' => 'ii_1', 'object' => 'invoiceitem' ),      // line item
			new WP_Error( 'law_stripe_api_error', 'send timed out' ), // send fails
		);
		$first = law_stripe_create_and_send_invoice( $event );
		$this->assertInstanceOf( WP_Error::class, $first );
		$this->assertSame( 'in_ORIGINAL', law_event_meta( $event, '_law_stripe_invoice_id' ), 'The invoice ID is persisted before send, so the retry can find it.' );

		// Attempt 2 (retry): the stored invoice is now OPEN at Stripe (the
		// send actually landed despite the timeout). The retry must RESUME it,
		// making no POST /v1/invoices call at all.
		$GLOBALS['law_test_stripe_calls'] = array(); // Assert attempt 2 only.
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_1', 'object' => 'customer' ),        // customer update (stored ID)
			array( 'id' => 'in_ORIGINAL', 'object' => 'invoice', 'status' => 'open', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/original' ), // GET existing
		);
		$second = law_stripe_create_and_send_invoice( $event );
		$this->assertIsArray( $second );
		$this->assertSame( 'in_ORIGINAL', $second['id'] );
		$this->assertSame( 'https://invoice.stripe.com/i/original', law_event_meta( $event, '_law_stripe_invoice_url' ) );

		$paths = wp_list_pluck( $GLOBALS['law_test_stripe_calls'], 'path' );
		$this->assertNotContains( '/v1/invoiceitems', $paths, 'No second line item.' );
		$creates = array_filter(
			$GLOBALS['law_test_stripe_calls'],
			fn( $c ) => 'POST' === $c['method'] && '/v1/invoices' === $c['path']
		);
		$this->assertCount( 0, $creates, 'The retry created NO new invoice: no double billing.' );
	}

	public function test_retry_deletes_a_leftover_draft_and_creates_fresh(): void {
		wp_set_current_user( $this->make_committee_user() );
		$event = $this->make_event( array( '_law_fee_tier' => 'international' ) );
		law_event_snapshot_fee( $event );
		update_post_meta( $event, '_law_stripe_invoice_id', 'in_DRAFT' );
		update_post_meta( $event, '_law_stripe_customer_id', 'cus_1' );

		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'id' => 'cus_1', 'object' => 'customer' ),        // customer update
			array( 'id' => 'in_DRAFT', 'object' => 'invoice', 'status' => 'draft' ), // GET existing → draft
			array( 'id' => 'in_DRAFT', 'deleted' => true ),          // DELETE draft
			array( 'id' => 'in_FRESH', 'object' => 'invoice' ),      // create fresh
			array( 'id' => 'ii_2', 'object' => 'invoiceitem' ),
			array( 'id' => 'in_FRESH', 'object' => 'invoice' ),      // send
			array( 'id' => 'in_FRESH', 'hosted_invoice_url' => 'https://invoice.stripe.com/i/fresh' ),
		);
		$result = law_stripe_create_and_send_invoice( $event );
		$this->assertIsArray( $result );
		$this->assertSame( 'in_FRESH', law_event_meta( $event, '_law_stripe_invoice_id' ) );

		$deletes = array_filter(
			$GLOBALS['law_test_stripe_calls'],
			fn( $c ) => 'DELETE' === $c['method'] && str_contains( $c['path'], 'in_DRAFT' )
		);
		$this->assertCount( 1, $deletes, 'The abandoned draft was deleted, not left to become a second bill.' );
	}
}
