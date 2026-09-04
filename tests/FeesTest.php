<?php
/**
 * fees.php: the tier matrix, the override on/off matrix, VAT from fee > 0,
 * pence conversion and the zero-fee route (EVENTS_4.1_REBUILD.md §3.11).
 */
class FeesTest extends LAW_Test_Case {

	public function test_tier_prices_match_settings(): void {
		$this->assertSame( 1200.0, law_event_tier_amount( 'uk' ) );
		$this->assertSame( 600.0, law_event_tier_amount( 'international' ) );
		$this->assertSame( 0.0, law_event_tier_amount( 'sponsor' ) );
		$this->assertSame( 0.0, law_event_tier_amount( 'nonsense' ) );
	}

	public function test_fee_pence_from_tier(): void {
		$uk = $this->make_event( array( '_law_fee_tier' => 'uk' ) );
		$this->assertSame( 120000, law_event_calculate_fee_pence( $uk ) );

		$international = $this->make_event( array( '_law_fee_tier' => 'international' ) );
		$this->assertSame( 60000, law_event_calculate_fee_pence( $international ) );

		$sponsor = $this->make_event( array( '_law_fee_tier' => 'sponsor' ) );
		$this->assertSame( 0, law_event_calculate_fee_pence( $sponsor ) );
	}

	public function test_override_matrix(): void {
		// Override ON: the override amount wins, even over the sponsor tier.
		$event = $this->make_event( array(
			'_law_fee_tier'            => 'sponsor',
			'_law_fee_override'        => 1,
			'_law_fee_override_amount' => 250.50,
		) );
		$this->assertSame( 25050, law_event_calculate_fee_pence( $event ) );

		// Override OFF: the stored amount is ignored (field 87 gates field 81).
		$event2 = $this->make_event( array(
			'_law_fee_tier'            => 'uk',
			'_law_fee_override'        => 0,
			'_law_fee_override_amount' => 250.50,
		) );
		$this->assertSame( 120000, law_event_calculate_fee_pence( $event2 ) );

		// Override ON with 0 = a committee waiver: the free route.
		$event3 = $this->make_event( array(
			'_law_fee_tier'            => 'uk',
			'_law_fee_override'        => 1,
			'_law_fee_override_amount' => 0,
		) );
		$this->assertSame( 0, law_event_calculate_fee_pence( $event3 ) );
	}

	public function test_vat_from_fee_not_price_literals(): void {
		$this->assertSame( 1, law_event_calculate_vat( 120000 ) );
		$this->assertSame( 1, law_event_calculate_vat( 1 ) ); // Any positive amount, not a price literal.
		$this->assertSame( 0, law_event_calculate_vat( 0 ) );
	}

	public function test_snapshot_writes_meta(): void {
		$event    = $this->make_event( array( '_law_fee_tier' => 'international' ) );
		$snapshot = law_event_snapshot_fee( $event );
		$this->assertSame( 60000, $snapshot['fee_pence'] );
		$this->assertSame( 1, $snapshot['vat'] );
		$this->assertSame( 60000, (int) law_event_meta( $event, '_law_fee_pence' ) );
		$this->assertSame( 1, (int) law_event_meta( $event, '_law_vat' ) );
	}
}
