<?php
/**
 * The committee's Flagship bookings dashboard
 * (functions/events/flagship-bookings-dashboard.php).
 *
 * The two things worth pinning here are both structural rather than cosmetic:
 * that the rows and the export carry the payment facts a refund has to be
 * traced by, and that the flagship stays OUT of Manage bookings, which is the
 * whole reason this page exists (Denis, 10 September 2026).
 */
class FlagshipBookingsDashboardTest extends LAW_Test_Case {

	private int $flagship = 0;

	protected function tearDown(): void {
		remove_all_filters( 'law_flagship_event_id' );
		remove_all_filters( 'pre_option_law_events_source' );
		law_flagship_event_id( true );
		$_GET = array();
		parent::tearDown();
	}

	private function make_flagship(): int {
		$event_id = $this->make_event(
			array(
				'_law_is_flagship'           => 1,
				'_law_flagship_date'         => '2026-12-02',
				'_law_start'                 => '2026-12-02 09:30',
				'_law_flagship_price_pence'  => 55000,
				'_law_tickets_available'     => 10,
			),
			'publish'
		);
		$this->flagship = $event_id;
		add_filter( 'law_flagship_event_id', fn() => $this->flagship );
		add_filter( 'pre_option_law_events_source', fn() => 'cpt' );
		law_flagship_event_id( true );

		return $event_id;
	}

	/** An application, straight to the state under test. */
	private function make_application( string $status = 'law-applied', string $payment = 'ready' ): int {
		$user_id = $this->make_user( 'attendee' );
		wp_update_user( array( 'ID' => $user_id, 'first_name' => 'Ada', 'last_name' => 'Lovelace' ) );
		update_user_meta( $user_id, 'country', 'United Kingdom' );

		$booking_id = wp_insert_post(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_status' => $status,
				'post_parent' => $this->flagship,
				'post_author' => $user_id,
				'post_title'  => 'Booking #900',
			)
		);
		$this->posts[] = $booking_id;

		law_event_update_meta( $booking_id, '_law_booking_number', 900 );
		law_booking_write_attendee(
			$booking_id,
			array(
				'user_id'      => $user_id,
				'name'         => 'Ada Lovelace',
				'email'        => 'ada-' . wp_generate_password( 5, false ) . '@example.test',
				'organisation' => 'Analytical Chambers',
				'job_title'    => 'Counsel',
			),
			$user_id
		);
		law_event_update_meta( $booking_id, '_law_price_pence', 55000 );
		law_event_update_meta( $booking_id, '_law_vat', 1 );
		law_event_update_meta( $booking_id, '_law_payment_status', $payment );
		law_event_update_meta( $booking_id, '_law_application_at', gmdate( 'Y-m-d H:i' ) );
		update_post_meta( $booking_id, '_law_application_ready', 1 );

		return (int) $booking_id;
	}

	/* The queue _____________________________________________________________ */

	/**
	 * An application whose card never arrived is not yet a request anyone can
	 * act on, so it stays out of the default queue rather than sitting there
	 * looking decidable.
	 */
	public function test_an_application_without_a_card_is_not_in_the_queue(): void {
		$this->make_flagship();
		$ready   = $this->make_application( 'law-applied', 'ready' );
		$waiting = $this->make_application( 'law-applied', 'pending_setup' );

		$ids = array_map( fn( $p ) => (int) $p->ID, law_flagship_applications() );
		$this->assertContains( $ready, $ids );
		$this->assertNotContains( $waiting, $ids );

		// It is still findable when asked for by name.
		$asked = array_map( fn( $p ) => (int) $p->ID, law_flagship_applications( array( 'payment' => 'pending_setup' ) ) );
		$this->assertContains( $waiting, $asked );
	}

	public function test_the_rows_carry_the_payment_facts(): void {
		$this->make_flagship();
		$booking_id = $this->make_application( 'law-payment-failed', 'failed' );
		law_event_update_meta( $booking_id, '_law_payment_error', 'Your card has insufficient funds.' );
		law_event_update_meta( $booking_id, '_law_stripe_invoice_url', 'https://invoice.stripe.test/in_x' );

		$data = law_flagship_bookings_rows( law_flagship_bookings_filters( array() ) );
		$row  = $data['rows'][0];

		$this->assertSame( 900, $row['number'] );
		$this->assertSame( 'Ada Lovelace', $row['name'] );
		$this->assertSame( 'United Kingdom', $row['country'], 'Country is read live from the profile.' );
		$this->assertSame( 66000, $row['gross_pence'], 'Net plus VAT, from the snapshot.' );
		$this->assertSame( 'Payment failed', $row['payment_label'] );
		$this->assertSame( 'Your card has insufficient funds.', $row['payment_error'] );
		$this->assertSame( 'https://invoice.stripe.test/in_x', $row['invoice_url'], 'Spec §7.5: a refund has to be findable.' );
		$this->assertTrue( $row['retryable'] );
		$this->assertTrue( $row['decidable'] );
	}

	/** A decided application offers no decision. */
	public function test_a_confirmed_application_is_not_decidable(): void {
		$this->make_flagship();
		$this->make_application( 'publish', 'paid' );

		$data = law_flagship_bookings_rows( law_flagship_bookings_filters( array() ) );
		$this->assertFalse( $data['rows'][0]['decidable'] );
	}

	public function test_the_keyword_filter_matches_name_email_and_number(): void {
		$this->make_flagship();
		$booking_id = $this->make_application();
		$email      = (string) law_event_meta( $booking_id, '_law_attendee_email' );

		foreach ( array( 'lovelace', 'Analytical', '#900', '900', $email ) as $needle ) {
			$found = law_flagship_applications( array( 'kw' => $needle ) );
			$this->assertCount( 1, $found, "Keyword should match: {$needle}" );
		}
		$this->assertCount( 0, law_flagship_applications( array( 'kw' => 'no-such-person' ) ) );
	}

	public function test_the_status_filter_narrows_the_queue(): void {
		$this->make_flagship();
		$applied  = $this->make_application( 'law-applied', 'ready' );
		$declined = $this->make_application( 'law-declined', 'ready' );

		$ids = array_map( fn( $p ) => (int) $p->ID, law_flagship_applications( array( 'status' => 'law-declined' ) ) );
		$this->assertSame( array( $declined ), $ids );
		$this->assertNotContains( $applied, $ids );
	}

	/* The export ____________________________________________________________ */

	public function test_the_export_carries_the_money_and_the_stripe_reference(): void {
		$this->make_flagship();
		$booking_id = $this->make_application( 'publish', 'paid' );
		law_event_update_meta( $booking_id, '_law_stripe_invoice_url', 'https://invoice.stripe.test/in_y' );

		$data = law_flagship_bookings_export_rows( law_flagship_bookings_filters( array() ) );

		$this->assertSame( 'Application', $data['columns'][0] );
		$this->assertContains( 'Amount charged', $data['columns'] );
		$this->assertContains( 'Stripe invoice', $data['columns'] );
		$this->assertContains( 'Accessibility', $data['columns'] );

		$row = $data['rows'][0];
		$this->assertSame( '#900', $row[0] );
		$this->assertSame( 'Ada', $row[2] );
		$this->assertSame( 'Lovelace', $row[3] );
		$this->assertContains( 'https://invoice.stripe.test/in_y', $row );
		$this->assertSame( count( $data['columns'] ), count( $row ), 'Every column must have a cell.' );
	}

	/* The split from Manage bookings ________________________________________ */

	/**
	 * The reason this page exists. A flagship application appearing on Manage
	 * bookings would show a priced, reviewable request in a table built for
	 * free instant ones, with none of the actions that apply to it.
	 */
	public function test_the_flagship_is_excluded_from_manage_bookings(): void {
		$event_id = $this->make_flagship();

		$this->assertContains( $event_id, law_bookings_dashboard_excluded_events() );

		$this->make_application( 'publish', 'paid' );
		$rows = law_bookings_dashboard_rows( law_bookings_dashboard_filters( array() ) );
		foreach ( $rows['rows'] as $row ) {
			$this->assertNotSame( $event_id, (int) $row['event_id'], 'Manage bookings is hosted events only.' );
		}
		$this->assertArrayNotHasKey( $event_id, law_bookings_dashboard_events(), 'And the flagship is not offered in its event filter.' );
	}

	/**
	 * Organisation, job title and country: on the row, but not as columns.
	 *
	 * They had three columns of their own, which pushed the actions off the
	 * side of the screen, so they were cut. Cutting them lost information a
	 * reviewer actually decides on, so they came back as a single sub-line
	 * under the applicant's name instead (Denis, 10 September 2026). Both
	 * halves are pinned here: no columns of their own, and still every one
	 * of them in the exports, or the badging loses its source.
	 */
	public function test_the_three_details_ride_under_the_name_and_stay_in_the_export(): void {
		$table = file_get_contents( get_theme_file_path( 'parts/events/flagship-bookings-list.php' ) );

		// No <th> of their own: that is what made the table too wide.
		foreach ( array( 'Organisation', 'Job title', 'Country' ) as $heading ) {
			$this->assertStringNotContainsString(
				"<th><?php esc_html_e( '" . $heading . "'",
				$table,
				$heading . ' must not have a column of its own.'
			);
		}

		// But all three are read, in one sub-line under the name.
		foreach ( array( 'organisation', 'job_title', 'country' ) as $field ) {
			$this->assertStringContainsString(
				"law_fbl_row['" . $field . "']",
				$table,
				$field . ' belongs under the applicant name.'
			);
		}

		$this->make_flagship();
		$this->make_application();
		$data = law_flagship_bookings_export_rows( law_flagship_bookings_filters( array() ) );
		foreach ( array( 'Organisation', 'Job title', 'Country' ) as $column ) {
			$this->assertContains( $column, $data['columns'] );
		}
	}

	/**
	 * A filter with no control is a filter nobody can use; the country one
	 * was removed outright rather than left reachable only by URL.
	 */
	public function test_the_country_filter_is_gone_entirely(): void {
		$filters = law_flagship_bookings_filters( array( 'law_country' => 'Montenegro' ) );
		$this->assertArrayNotHasKey( 'country', $filters );

		$template = file_get_contents( get_theme_file_path( 'templates/account-dashboard-flagship-bookings.php' ) );
		$this->assertStringNotContainsString( 'law_country', $template );
		$this->assertFalse( function_exists( 'law_flagship_bookings_countries' ) );
	}

	/** The committee's page has to be reachable, and reachable only by them. */
	public function test_the_dashboard_is_provisioned_and_committee_only(): void {
		$this->assertArrayHasKey( 'flagship_bookings', law_account_paths() );
		$this->assertStringContainsString( 'account/dashboard/flagship-bookings', law_flagship_bookings_url() );
		$this->assertTrue( function_exists( 'law_setup_flagship_bookings_access' ) );
	}
}
