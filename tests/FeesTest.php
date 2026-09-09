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

	public function test_override_lock_follows_approval(): void {
		$pending = $this->make_event( array( '_law_fee_tier' => 'uk' ) );
		$this->assertFalse( law_event_fee_override_locked( $pending ) );

		// _law_approved_at is written by the approve transition and never
		// cleared, so it is what "the fee has been snapshotted" means.
		$approved = $this->make_event( array( '_law_fee_tier' => 'uk', '_law_approved_at' => '2026-09-09' ), 'law-approved' );
		$this->assertTrue( law_event_fee_override_locked( $approved ) );
	}

	public function test_resnapshot_refreezes_an_unpaid_approved_fee(): void {
		$event = $this->make_event(
			array( '_law_fee_tier' => 'uk', '_law_approved_at' => '2026-09-09', '_law_payment_status' => 'unpaid' ),
			'law-approved'
		);
		law_event_snapshot_fee( $event );
		$this->assertSame( 120000, (int) law_event_meta( $event, '_law_fee_pence' ) );

		// The committee agrees a discount after approval, in wp-admin.
		law_event_update_meta( $event, '_law_fee_override', 1 );
		law_event_update_meta( $event, '_law_fee_override_amount', 600 );

		$resnapshot = law_event_resnapshot_fee( $event );
		$this->assertSame( 120000, $resnapshot['was'] );
		$this->assertSame( 60000, $resnapshot['fee_pence'] );
		$this->assertSame( 60000, (int) law_event_meta( $event, '_law_fee_pence' ) );

		// A waiver takes the VAT flag down with it.
		law_event_update_meta( $event, '_law_fee_override_amount', 0 );
		$waived = law_event_resnapshot_fee( $event );
		$this->assertSame( 0, $waived['fee_pence'] );
		$this->assertSame( 0, (int) law_event_meta( $event, '_law_vat' ) );
	}

	public function test_resnapshot_refuses_once_the_fee_is_settled(): void {
		foreach ( array( 'paid', 'refunded' ) as $status ) {
			$event = $this->make_event(
				array( '_law_fee_tier' => 'uk', '_law_approved_at' => '2026-09-09', '_law_payment_status' => $status ),
				'publish'
			);
			law_event_snapshot_fee( $event );
			law_event_update_meta( $event, '_law_fee_override', 1 );
			law_event_update_meta( $event, '_law_fee_override_amount', 600 );

			$result = law_event_resnapshot_fee( $event );
			$this->assertInstanceOf( WP_Error::class, $result, $status . ' must not re-snapshot' );
			$this->assertSame( 'law_fee_settled', $result->get_error_code() );
			// The snapshot the invoice was paid against is untouched.
			$this->assertSame( 120000, (int) law_event_meta( $event, '_law_fee_pence' ) );
		}
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
