<?php
/**
 * The discount-code catalogue (functions/events/discounts.php).
 *
 * Nothing on the site honours a code yet — Denis kept the catalogue for
 * future use and ruled it out for the flagship — so these tests are the only
 * thing exercising the rules. That is exactly why they are here: the day
 * something starts charging, the arithmetic and the usage counter have to be
 * right first time, not discovered against real money.
 */
class DiscountsTest extends LAW_Test_Case {

	/** A code, tracked for teardown. */
	private function make_code( array $input = array() ) {
		$result = law_discount_save(
			array_merge(
				array(
					'id'       => 0,
					'code'     => 'TEST' . strtoupper( wp_generate_password( 5, false ) ),
					'type'     => 'percent',
					'value'    => '10',
					'starts'   => '',
					'expires'  => '',
					'max_uses' => 0,
					'events'   => array(),
					'note'     => '',
					'active'   => true,
				),
				$input
			),
			0
		);
		if ( ! is_wp_error( $result ) ) {
			$this->posts[] = (int) $result;
		}

		return $result;
	}

	/* Normalisation and lookup ______________________________________________ */

	/**
	 * A code pasted out of an email arrives with spaces and the wrong case.
	 * Stripping rather than refusing is the point: nobody loses a discount to
	 * a stray character.
	 */
	public function test_a_code_is_found_however_it_is_typed(): void {
		$id = $this->make_code( array( 'code' => 'LAW-WEEK-25' ) );
		$this->assertIsInt( $id );

		// The display form keeps the committee's hyphens...
		$this->assertSame( 'LAW-WEEK-25', law_discount_data( $id )['code'] );
		// ...while the match form ignores punctuation and case entirely,
		// because "printed with hyphens, typed with spaces" is how a code
		// actually reaches a delegate.
		$this->assertSame( 'LAWWEEK25', law_discount_match_key( 'law week 25' ) );

		foreach ( array( 'LAW-WEEK-25', 'lawweek25', ' law week 25 ', 'LaW-wEeK 25' ) as $typed ) {
			$found = law_discount_find( $typed );
			$this->assertInstanceOf( WP_Post::class, $found, "Should find the code from: {$typed}" );
			$this->assertSame( $id, (int) $found->ID );
		}
	}

	/** Two codes a human could not tell apart must not both exist. */
	public function test_codes_differing_only_by_punctuation_collide(): void {
		$this->make_code( array( 'code' => 'LAW-WEEK-25' ) );

		$this->assertWPError( $this->make_code( array( 'code' => 'LAWWEEK25' ) ), 'code' );
	}

	/** A duplicate code would make the lookup ambiguous, so it is refused. */
	public function test_a_duplicate_code_is_refused(): void {
		$this->make_code( array( 'code' => 'SAMECODE' ) );
		$second = $this->make_code( array( 'code' => 'samecode' ) );

		$this->assertWPError( $second, 'code' );
	}

	/* The arithmetic ________________________________________________________ */

	public function test_a_percentage_comes_off_the_net_price(): void {
		$id       = $this->make_code( array( 'type' => 'percent', 'value' => '10' ) );
		$discount = law_discount_data( $id );

		$applied = law_discount_apply( 55000, $discount );
		$this->assertSame( 5500, $applied['discount_pence'] );
		$this->assertSame( 49500, $applied['net_pence'] );
		$this->assertFalse( $applied['is_free'] );
	}

	/** A fixed code worth more than the place makes it free, never a credit. */
	public function test_a_fixed_code_larger_than_the_price_makes_it_free(): void {
		$id       = $this->make_code( array( 'type' => 'fixed', 'value' => '9999' ) );
		$discount = law_discount_data( $id );

		$applied = law_discount_apply( 55000, $discount );
		$this->assertSame( 55000, $applied['discount_pence'], 'Capped at the price.' );
		$this->assertSame( 0, $applied['net_pence'], 'Never negative.' );
		$this->assertTrue( $applied['is_free'] );
	}

	public function test_a_hundred_percent_code_is_free(): void {
		$id      = $this->make_code( array( 'type' => 'percent', 'value' => '100' ) );
		$applied = law_discount_apply( 55000, law_discount_data( $id ) );

		$this->assertTrue( $applied['is_free'] );
		$this->assertStringContainsString( 'Free place', law_discount_summary( law_discount_data( $id ) ) );
	}

	/* Validation ____________________________________________________________ */

	/**
	 * "No such code" and "that code is disabled" read identically on purpose,
	 * so the field cannot be used to work out which codes exist.
	 */
	public function test_an_unknown_and_a_disabled_code_answer_the_same(): void {
		$id = $this->make_code( array( 'active' => false ) );

		$unknown  = law_discount_validate( 'NOSUCHCODE', array( 'price_pence' => 55000 ) );
		$disabled = law_discount_validate( law_discount_data( $id )['code'], array( 'price_pence' => 55000 ) );

		$this->assertWPError( $unknown, 'law_discount_unknown' );
		$this->assertWPError( $disabled, 'law_discount_unknown' );
		$this->assertSame( $unknown->get_error_message(), $disabled->get_error_message() );
	}

	public function test_a_code_outside_its_window_is_refused(): void {
		$early = $this->make_code( array( 'starts' => gmdate( 'Y-m-d H:i', time() + DAY_IN_SECONDS ) ) );
		$this->assertWPError( law_discount_validate( law_discount_data( $early )['code'], array( 'price_pence' => 55000 ) ), 'law_discount_early' );

		$gone = $this->make_code( array( 'expires' => gmdate( 'Y-m-d H:i', time() - DAY_IN_SECONDS ) ) );
		$this->assertWPError( law_discount_validate( law_discount_data( $gone )['code'], array( 'price_pence' => 55000 ) ), 'law_discount_expired' );
	}

	public function test_a_scoped_code_is_refused_elsewhere(): void {
		$event_id = $this->make_event( array(), 'publish' );
		$other_id = $this->make_event( array(), 'publish' );
		$id       = $this->make_code( array( 'events' => array( $event_id ) ) );
		$code     = law_discount_data( $id )['code'];

		$this->assertIsArray( law_discount_validate( $code, array( 'event_id' => $event_id, 'price_pence' => 55000 ) ) );
		$this->assertWPError(
			law_discount_validate( $code, array( 'event_id' => $other_id, 'price_pence' => 55000 ) ),
			'law_discount_wrong_event'
		);
	}

	/* The usage counter _____________________________________________________ */

	/**
	 * The conditional UPDATE is the whole point of law_discount_claim(): a
	 * code can be shared across events, so two callers holding two DIFFERENT
	 * event locks could otherwise both read "9 of 10" and both take the tenth.
	 */
	public function test_a_limited_code_cannot_be_over_claimed(): void {
		$id = $this->make_code( array( 'max_uses' => 2 ) );

		$this->assertTrue( law_discount_claim( $id ) );
		$this->assertTrue( law_discount_claim( $id ) );
		$this->assertFalse( law_discount_claim( $id ), 'The third claim must be refused by the database, not by a re-read.' );
		$this->assertSame( 2, law_discount_data( $id )['used'] );

		$this->assertWPError(
			law_discount_validate( law_discount_data( $id )['code'], array( 'price_pence' => 55000 ) ),
			'law_discount_used_up'
		);
	}

	/** An unlimited code claims for ever. */
	public function test_an_unlimited_code_keeps_claiming(): void {
		$id = $this->make_code( array( 'max_uses' => 0 ) );

		foreach ( range( 1, 5 ) as $ignored ) {
			$this->assertTrue( law_discount_claim( $id ) );
		}
		$this->assertSame( 5, law_discount_data( $id )['used'] );
	}

	/**
	 * Releasing is floored at zero in SQL. Without that, a double release
	 * would wrap the UNSIGNED cast and hand out an effectively unlimited code.
	 */
	public function test_releasing_never_goes_below_zero(): void {
		$id = $this->make_code( array( 'max_uses' => 3 ) );
		law_discount_claim( $id );

		$this->assertTrue( law_discount_release( $id ) );
		$this->assertSame( 0, law_discount_data( $id )['used'] );
		$this->assertFalse( law_discount_release( $id ), 'Nothing left to give back.' );
		$this->assertSame( 0, law_discount_data( $id )['used'] );
	}

	/** Editing a code must never rewrite its usage history. */
	public function test_editing_a_code_keeps_its_usage_count(): void {
		$id = $this->make_code( array( 'max_uses' => 5 ) );
		law_discount_claim( $id );
		law_discount_claim( $id );

		law_discount_save(
			array(
				'id'       => $id,
				'code'     => law_discount_data( $id )['code'],
				'type'     => 'percent',
				'value'    => '25',
				'starts'   => '',
				'expires'  => '',
				'max_uses' => 5,
				'events'   => array(),
				'note'     => 'edited',
				'active'   => true,
			),
			0
		);

		$this->assertSame( 2, law_discount_data( $id )['used'] );
		$this->assertSame( 25, law_discount_data( $id )['value'] );
	}

	/* Input validation ______________________________________________________ */

	public function test_a_percentage_must_be_between_one_and_a_hundred(): void {
		$this->assertWPError( $this->make_code( array( 'type' => 'percent', 'value' => '0' ) ), 'value' );
		$this->assertWPError( $this->make_code( array( 'type' => 'percent', 'value' => '101' ) ), 'value' );
	}

	/** A typo must be refused, not silently read as "no discount". */
	public function test_a_fixed_amount_that_is_not_a_number_is_refused(): void {
		$this->assertWPError( $this->make_code( array( 'type' => 'fixed', 'value' => 'fifty' ) ), 'value' );
		$this->assertNull( law_events_pounds_to_pence( 'fifty' ) );
		$this->assertSame( 5000, law_events_pounds_to_pence( '£50.00' ) );
	}

	public function test_an_end_before_the_start_is_refused(): void {
		$refused = $this->make_code(
			array(
				'starts'  => gmdate( 'Y-m-d H:i', time() + DAY_IN_SECONDS ),
				'expires' => gmdate( 'Y-m-d H:i', time() ),
			)
		);
		$this->assertWPError( $refused, 'expires' );
	}

	/* The deliberate absence of a consumer __________________________________ */

	/**
	 * Denis, 10 September 2026: codes are kept for future use and are not
	 * used on the flagship. If this ever fails, someone has wired a code into
	 * a flow without asking, which is a pricing decision, not a refactor.
	 */
	public function test_nothing_applies_a_code_yet(): void {
		$this->assertSame( array(), law_discount_scope_events(), 'No priced flow has opted in.' );

		$engine = file_get_contents( get_theme_file_path( 'functions/events/flagship-bookings.php' ) );
		$this->assertStringNotContainsString( 'law_discount_validate', $engine );
		$this->assertStringNotContainsString( 'law_discount_claim', $engine );
	}

	/**
	 * The date fields round-trip through <input type="datetime-local">.
	 *
	 * That control accepts ONLY "YYYY-MM-DDTHH:MM" and renders empty for
	 * anything else, without complaint. Meta stores the space-separated
	 * form, so a saved window would have come back looking blank and been
	 * wiped on the next save. Both forms have to parse to the same instant.
	 */
	public function test_a_datetime_local_value_saves_to_the_same_instant_as_a_typed_one(): void {
		$typed = law_discount_stamp_ts( '2026-09-01 09:30' );
		$local = law_discount_stamp_ts( '2026-09-01T09:30' );

		$this->assertNotSame( 0, $typed, 'The typed form must still parse.' );
		$this->assertSame( $typed, $local, 'The browser control posts a T; it means the same moment.' );

		// And the sanitiser normalises the control's form back to storage.
		$this->assertSame( '2026-09-01 09:30', law_events_sanitize_value( '2026-09-01T09:30', 'datetime' ) );
	}

}
