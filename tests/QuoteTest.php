<?php
/**
 * The shared price quote (law_booking_quote(), functions/events/bookings.php).
 *
 * One quote answers for every flow that charges a delegate. It was the
 * receptions' own until 15 September 2026, when the flagship started taking
 * discount codes and a second copy of the same arithmetic would have been the
 * start of two that drift.
 *
 * What is worth pinning here is the seam, not the sums: that the right list
 * price is found for each flow without this function knowing what either of
 * them is, and that the guard refuses an event nobody has claimed — because
 * the endpoint is public to any signed-in member, and an unclaimed event ID
 * must never be priceable through it.
 */

require_once __DIR__ . '/class-law-test-case.php';

class QuoteTest extends LAW_Test_Case {

	private $source_before;

	protected function setUp(): void {
		parent::setUp();
		$this->source_before = get_option( 'law_events_source', null );
		update_option( 'law_events_source', 'cpt' );
	}

	protected function tearDown(): void {
		if ( null === $this->source_before ) {
			delete_option( 'law_events_source' );
		} else {
			update_option( 'law_events_source', $this->source_before );
		}
		remove_all_filters( 'law_flagship_event_id' );
		law_flagship_event_id( true );
		parent::tearDown();
	}

	/**
	 * The flagship's price is time-switched between two stored figures, the
	 * reception's is one integer, and the quote asks neither of them directly:
	 * law_event_price_pence() already routes it.
	 */
	public function test_one_quote_finds_each_flows_list_price(): void {
		$flagship = $this->make_event(
			array(
				'_law_is_flagship'               => 1,
				'_law_flagship_price_pence'      => 55000,
				'_law_flagship_price_late_pence' => 60000,
				'_law_flagship_price_switch'     => '2099-10-17 00:00',
			),
			'publish'
		);
		// law_flagship_is() answers for THE flagship, which is resolved and
		// cached; pin it rather than hoping the fixture is found.
		add_filter( 'law_flagship_event_id', static fn() => $flagship );
		law_flagship_event_id( true );

		$reception = $this->make_event(
			array( '_law_is_reception' => 1, '_law_attendee_price_pence' => 4500 ),
			'publish'
		);

		$this->assertSame( 55000, law_booking_quote( $flagship )['list_net'] );
		$this->assertSame( 66000, law_booking_quote( $flagship )['gross'], 'Plus 20% VAT.' );
		$this->assertSame( 4500, law_booking_quote( $reception )['list_net'] );
		$this->assertSame( 5400, law_booking_quote( $reception )['gross'] );
	}

	/**
	 * list_gross travels with the quote because the no-JS path compares
	 * against it: a form rendered at the list price posts the list gross, and
	 * judging that against the discounted total refuses every redemption made
	 * without JavaScript.
	 */
	public function test_the_expected_gross_depends_on_whether_the_client_re_quoted(): void {
		$quote = array( 'gross' => 4050, 'list_gross' => 5400 );

		$this->assertSame( 4050, law_booking_quote_expected_gross( $quote, true ), 'Apply was pressed, so the total moved.' );
		$this->assertSame( 5400, law_booking_quote_expected_gross( $quote, false ), 'No Apply button, so the form still shows the list price.' );
	}

	/**
	 * An event no flow has claimed is not priceable. The endpoint is open to
	 * any signed-in member, so the default has to be refusal rather than a
	 * quote for whatever post ID was posted.
	 */
	public function test_an_unclaimed_event_is_refused_by_the_guard(): void {
		$hosted = $this->make_event( array(), 'publish' );

		$this->assertWPError( law_booking_quote_guard( $hosted ), 'law_booking_not_quotable' );
		$this->assertWPError( law_booking_quote_guard( 0 ), 'law_booking_not_quotable' );

		$reception = $this->make_event(
			array(
				'_law_is_reception'         => 1,
				'_law_attendee_price_pence' => 4500,
				'_law_start'                => gmdate( 'Y-m-d H:i', time() + ( 30 * DAY_IN_SECONDS ) ),
				'_law_tickets_available'    => 10,
			),
			'publish'
		);
		$this->assertTrue( law_booking_quote_guard( $reception ) );
	}

	/** The reception's own name still answers, so no caller had to change. */
	public function test_the_reception_wrapper_agrees_with_the_shared_quote(): void {
		$reception = $this->make_event(
			array( '_law_is_reception' => 1, '_law_attendee_price_pence' => 4500 ),
			'publish'
		);

		$this->assertSame(
			law_booking_quote( $reception ),
			law_reception_quote( $reception ),
			'The wrapper must not acquire a life of its own.'
		);
	}
}
