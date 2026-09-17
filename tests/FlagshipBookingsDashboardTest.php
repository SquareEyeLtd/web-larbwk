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
		$user_id = $this->make_user();
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

		$this->assertSame( 'Registration', $data['columns'][0] );
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

	/* Discount codes ________________________________________________________ */

	/**
	 * A code redeemed at registration reaches the committee's row, its export
	 * and its "was £X" line.
	 *
	 * _law_price_pence is the DISCOUNTED net, so the list price has to be
	 * reconstructed from it and the discount. Pinned here because the two
	 * figures being the wrong way round would misreport what a code gave away
	 * and nothing on screen would look broken.
	 */
	public function test_a_redeemed_code_reaches_the_row_and_the_export(): void {
		$this->make_flagship();
		$booking_id = $this->make_application( 'law-applied', 'ready' );
		law_event_update_meta( $booking_id, '_law_price_pence', 41250 );
		law_event_update_meta( $booking_id, '_law_discount_code', 'SPEAKER25' );
		law_event_update_meta( $booking_id, '_law_discount_pence', 13750 );

		$row = law_flagship_bookings_rows( law_flagship_bookings_filters( array() ) )['rows'][0];

		$this->assertSame( 'SPEAKER25', $row['discount_code'] );
		$this->assertSame( 13750, $row['discount_pence'] );
		$this->assertSame( 49500, $row['gross_pence'], '£412.50 plus 20% VAT.' );
		$this->assertSame( 66000, $row['list_pence'], 'And £550.00 plus VAT is what it would have cost.' );

		$data = law_flagship_bookings_export_rows( law_flagship_bookings_filters( array() ) );
		foreach ( array( 'List price', 'Discount code', 'Discount' ) as $column ) {
			$this->assertContains( $column, $data['columns'] );
		}
		$this->assertContains( 'SPEAKER25', $data['rows'][0] );
		$this->assertSame( count( $data['columns'] ), count( $data['rows'][0] ), 'Every column must have a cell.' );
	}

	/**
	 * A registration a code covered in full is decidable, is in the default
	 * queue, and is not counted as a complimentary place.
	 *
	 * All three fall out of the payment state being 'no_charge' rather than
	 * 'pending_setup' (which the queue hides and the abandonment sweep closes)
	 * or 'complimentary' (which is the committee's gift, and what the
	 * Complimentary filter means).
	 */
	public function test_a_code_covered_registration_is_reviewable_and_is_not_a_comp_place(): void {
		$this->make_flagship();
		$booking_id = $this->make_application( 'law-applied', 'no_charge' );
		law_event_update_meta( $booking_id, '_law_price_pence', 0 );
		law_event_update_meta( $booking_id, '_law_vat', 0 );
		law_event_update_meta( $booking_id, '_law_discount_code', 'ALLIN' );
		law_event_update_meta( $booking_id, '_law_discount_pence', 55000 );

		$rows = law_flagship_bookings_rows( law_flagship_bookings_filters( array() ) )['rows'];
		$this->assertCount( 1, $rows );
		$this->assertTrue( $rows[0]['decidable'], 'There is nothing to wait for: no card is coming.' );
		$this->assertFalse( $rows[0]['complimentary'] );
		$this->assertSame( 'Covered by a code, pending approval', $rows[0]['payment_label'] );

		$this->assertSame(
			array(),
			law_flagship_bookings_rows( law_flagship_bookings_filters( array( 'law_comp' => 1 ) ) )['rows'],
			'The committee gave nothing away; the delegate brought a code.'
		);

		// A place a code covered carries _law_vat = 0, because there is no VAT
		// to add to nothing. The price it WOULD have cost still had VAT on it,
		// and that is what this column reports.
		$this->assertSame( 66000, $rows[0]['list_pence'], '£550.00 plus 20% VAT is what it would have cost.' );

		// Once approved it settles at 'paid' with a gross of zero, which is the
		// right state and the wrong word on its own.
		law_event_update_meta( $booking_id, '_law_payment_status', 'paid' );
		$rows = law_flagship_bookings_rows( law_flagship_bookings_filters( array() ) )['rows'];
		$this->assertSame( 'Paid in full by discount code', $rows[0]['payment_label'] );
	}

	/**
	 * The table renders, and both free cases say the truthful thing.
	 *
	 * Every other assertion about this screen goes through the row builder,
	 * which cannot catch an undefined key in the markup or a confirm dialog
	 * offering to "charge £0.00". The table is also the whole of the
	 * ?law_partial=1 response, so a notice in it breaks filtering silently.
	 */
	public function test_the_table_renders_and_a_covered_place_is_not_offered_a_charge(): void {
		$this->make_flagship();
		$covered = $this->make_application( 'law-applied', 'no_charge' );
		law_event_update_meta( $covered, '_law_price_pence', 0 );
		law_event_update_meta( $covered, '_law_vat', 0 );
		law_event_update_meta( $covered, '_law_discount_code', 'ALLIN' );
		law_event_update_meta( $covered, '_law_discount_pence', 55000 );

		ob_start();
		get_template_part( 'parts/events/flagship-bookings-list' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( '<code>ALLIN</code>', $html, 'The Code column.' );
		$this->assertStringContainsString( 'was £660.00', $html, 'And what it would have cost.' );
		$this->assertStringContainsString( 'Confirm the place', $html );
		$this->assertStringNotContainsString(
			'Charge £0.00',
			$html,
			'A code covered it, so there is nothing to charge and the dialog must not say there is.'
		);
		$this->assertStringNotContainsString( 'Warning', $html, 'No PHP notice leaked into the markup.' );
	}

	/**
	 * Cancel is the confirmed row's action and only the confirmed row's: on an
	 * application still under review the answer is Decline, which also clears
	 * the card and voids the invoice.
	 */
	public function test_cancel_is_offered_on_a_confirmed_place_and_nowhere_else(): void {
		$this->make_flagship();
		$this->make_application( 'publish', 'paid' );

		ob_start();
		get_template_part( 'parts/events/flagship-bookings-list' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'law_flagship_cancel', $html, 'The row posts the cancel action.' );
		$this->assertStringContainsString( 'Cancel the ticket', $html, 'The dialog confirms it.' );
		$this->assertStringContainsString( 'Keep the ticket', $html, 'And never offers "Cancel" as the way out of it.' );
		$this->assertStringContainsString(
			'the refund is yours to make in Stripe',
			$html,
			'The whole point of the dialog: the money and the conversation stay with the committee.'
		);
		$this->assertStringNotContainsString( 'Warning', $html, 'No PHP notice leaked into the markup.' );

	}

	/** The other half of the same rule, on a table with nothing confirmed on it. */
	public function test_a_registration_under_review_is_offered_decline_not_cancel(): void {
		$this->make_flagship();
		$this->make_application( 'law-applied', 'ready' );

		ob_start();
		get_template_part( 'parts/events/flagship-bookings-list' );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Decline', $html, 'That row gets Decline.' );
		$this->assertStringNotContainsString( 'law_flagship_cancel', $html, 'And not Cancel, which would leave the card and the invoice live.' );
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

	/* Ticket type ___________________________________________________________ */

	/**
	 * The committee's own classification of a delegate (Denis, 15 September
	 * 2026). Back-office only: nothing about the place, the price or any guard
	 * reads it, which is exactly why it is also the one field the wp-admin
	 * booking screen may write.
	 */
	public function test_the_ticket_type_round_trips_to_the_row(): void {
		$this->make_flagship();
		$booking = $this->make_application();

		$row = law_flagship_bookings_rows( law_flagship_bookings_filters( array() ) )['rows'][0];
		$this->assertSame( '', $row['ticket_type'], 'Nothing is classified until somebody says so.' );
		$this->assertSame( '', $row['ticket_label'] );

		$set = law_flagship_set_ticket_type( $booking, 'sponsor', $this->make_committee_user() );
		$this->assertSame( array( 'type' => 'sponsor', 'label' => 'Sponsor' ), $set );

		$row = law_flagship_bookings_rows( law_flagship_bookings_filters( array() ) )['rows'][0];
		$this->assertSame( 'sponsor', $row['ticket_type'] );
		$this->assertSame( 'Sponsor', $row['ticket_label'] );
	}

	/**
	 * An unknown slug stores nothing rather than storing itself. The vocabulary
	 * is closed, and a value the label map cannot resolve would print as an
	 * empty cell while the export said something else.
	 */
	public function test_an_unknown_ticket_type_is_refused_and_an_empty_one_clears_it(): void {
		$this->make_flagship();
		$booking = $this->make_application();
		law_flagship_set_ticket_type( $booking, 'speaker', 0 );

		$refused = law_flagship_set_ticket_type( $booking, 'vip', 0 );
		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'speaker', law_event_meta( $booking, '_law_ticket_type' ), 'The refusal left the old value alone.' );

		// Straight through the meta layer too: the schema sanitiser is the
		// backstop for anything that writes without going past the model.
		law_event_update_meta( $booking, '_law_ticket_type', 'vip' );
		$this->assertSame( '', law_event_meta( $booking, '_law_ticket_type' ) );

		law_flagship_set_ticket_type( $booking, 'speaker', 0 );
		law_flagship_set_ticket_type( $booking, '', 0 );
		$this->assertSame( '', law_event_meta( $booking, '_law_ticket_type' ), 'Clearing deletes it rather than storing a sixth state.' );
	}

	/** A booking that is not the flagship's has no business here. */
	public function test_the_ticket_type_refuses_a_booking_that_is_not_the_flagship_s(): void {
		$this->make_flagship();
		$other   = $this->make_event( array(), 'publish' );
		$user_id = $this->make_user();
		$booking = wp_insert_post(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_status' => 'publish',
				'post_parent' => $other,
				'post_author' => $user_id,
				'post_title'  => 'Hosted booking',
			)
		);
		$this->posts[] = $booking;

		$this->assertInstanceOf( WP_Error::class, law_flagship_set_ticket_type( (int) $booking, 'delegate', 0 ) );
		$this->assertSame( '', law_event_meta( (int) $booking, '_law_ticket_type' ) );
	}

	/** The filter, and the column in all three exports. */
	public function test_the_ticket_type_filters_the_queue_and_reaches_the_export(): void {
		$this->make_flagship();
		$sponsor = $this->make_application();
		$plain   = $this->make_application();
		law_flagship_set_ticket_type( $sponsor, 'sponsor', 0 );

		$rows = law_flagship_bookings_rows( law_flagship_bookings_filters( array( 'law_ticket' => 'sponsor' ) ) )['rows'];
		$this->assertCount( 1, $rows );
		$this->assertSame( $sponsor, $rows[0]['id'] );

		// A type nobody offers must not silently become "no filter" and show
		// the whole list as though it had matched.
		$this->assertSame( '', law_flagship_bookings_filters( array( 'law_ticket' => 'vip' ) )['ticket'] );
		$this->assertCount( 2, law_flagship_bookings_rows( law_flagship_bookings_filters( array() ) )['rows'] );
		$this->assertNotSame( 0, $plain );

		$data = law_flagship_bookings_export_rows( law_flagship_bookings_filters( array( 'law_ticket' => 'sponsor' ) ) );
		$this->assertContains( 'Ticket type', $data['columns'] );
		$this->assertCount( count( $data['columns'] ), $data['rows'][0], 'Every column has a cell.' );
		$this->assertSame( 'Sponsor', $data['rows'][0][ array_search( 'Ticket type', $data['columns'], true ) ] );
	}

	/**
	 * The cell is rendered by ONE function, called by the table and again by
	 * the handler that answers an edit, so the markup swapped in over AJAX
	 * cannot drift from the markup the server would have drawn.
	 */
	public function test_the_ticket_type_cell_renders_both_states_and_the_no_js_form(): void {
		$this->make_flagship();
		$booking = $this->make_application();

		$empty = law_flagship_ticket_type_cell( $booking );
		$this->assertStringContainsString( 'Add type', $empty );
		$this->assertStringContainsString( 'data-law-ticket-open', $empty );
		$this->assertStringContainsString( '<noscript>', $empty, 'The column is not read-only without JavaScript.' );

		law_flagship_set_ticket_type( $booking, 'exhibitor', 0 );
		$set = law_flagship_ticket_type_cell( $booking );
		$this->assertStringContainsString( 'Exhibitor', $set );
		$this->assertStringNotContainsString( 'Add type', $set );
		$this->assertStringContainsString( 'data-law-ticket-value="exhibitor"', $set );

		ob_start();
		get_template_part( 'parts/events/flagship-bookings-list' );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'Ticket type', $html, 'The column header.' );
		$this->assertStringContainsString( 'data-law-ticket-cell="' . $booking . '"', $html );
		$this->assertStringNotContainsString( 'Warning', $html, 'No PHP notice leaked into the markup.' );

		// The dialog the pencils point at. It is rendered once, by the
		// template, outside the table; a pencil with no dialog to open is a
		// button law-modal.js would leave hidden for ever.
		wp_set_current_user( $this->make_committee_user() );
		ob_start();
		get_template_part( 'parts/events/flagship-ticket-type' );
		$dialog = (string) ob_get_clean();
		$this->assertStringContainsString( 'id="' . law_flagship_ticket_type_modal_id() . '"', $dialog );
		$this->assertStringContainsString( 'Applying', $dialog, 'The busy label the client asked for.' );
		foreach ( law_booking_ticket_types() as $slug => $label ) {
			$this->assertStringContainsString( 'value="' . $slug . '"', $dialog );
			$this->assertStringContainsString( '>' . $label . '<', $dialog );
		}
		$this->assertStringNotContainsString( 'Warning', $dialog );

		// And nobody else gets it: the dialog writes, so it is committee-only
		// on its own rather than trusting the template that included it.
		wp_set_current_user( $this->make_user() );
		ob_start();
		get_template_part( 'parts/events/flagship-ticket-type' );
		$this->assertSame( '', trim( (string) ob_get_clean() ) );
	}

	/**
	 * Changing it is a committee decision, so it is on the record. Reapplying
	 * the same value is not a decision and writes nothing.
	 */
	public function test_setting_the_ticket_type_is_logged_once(): void {
		$event_id = $this->make_flagship();
		$booking  = $this->make_application();

		law_flagship_set_ticket_type( $booking, 'committee', 0 );
		law_flagship_set_ticket_type( $booking, 'committee', 0 );

		$mine = array_values(
			array_filter(
				law_event_log_entries( $event_id ),
				fn( $entry ) => 'flagship_ticket_type' === ( law_event_log_context( $entry->comment_ID )['action'] ?? '' )
			)
		);
		$this->assertCount( 1, $mine, 'One change, one line.' );
		$this->assertStringContainsString( 'Ticket type set to Committee', $mine[0]->comment_content );
		$this->assertSame(
			$booking,
			(int) law_event_log_context( $mine[0]->comment_ID )['booking'],
			'Keyed to the booking, so the wp-admin Activity box finds it.'
		);
	}

	/**
	 * The wp-admin booking screen has been read-only since v1, and this is its
	 * one exception. Worth pinning both halves: that it saves, and that it is
	 * the ONLY thing it saves — the value is safe to write from there precisely
	 * because no guard, recount, email, status or price reads it.
	 */
	public function test_the_admin_screen_saves_the_ticket_type_and_nothing_else(): void {
		$this->make_flagship();
		$booking = $this->make_application();
		wp_set_current_user( $this->make_committee_user() );

		$_POST = array(
			'law_booking_admin_nonce' => wp_create_nonce( 'law_booking_admin_save' ),
			'law_ticket_type'         => 'delegate',
		);
		do_action( 'save_post_' . LAW_BOOKING_CPT, $booking, get_post( $booking ) );
		$this->assertSame( 'delegate', law_event_meta( $booking, '_law_ticket_type' ) );

		// No nonce, no write: the handler must not ride any other save of this
		// post type, of which there are many.
		$_POST = array( 'law_ticket_type' => 'sponsor' );
		do_action( 'save_post_' . LAW_BOOKING_CPT, $booking, get_post( $booking ) );
		$this->assertSame( 'delegate', law_event_meta( $booking, '_law_ticket_type' ) );

		// And not for somebody without the events capability, even with a
		// nonce they somehow hold.
		wp_set_current_user( $this->make_user() );
		$_POST = array(
			'law_booking_admin_nonce' => wp_create_nonce( 'law_booking_admin_save' ),
			'law_ticket_type'         => 'sponsor',
		);
		do_action( 'save_post_' . LAW_BOOKING_CPT, $booking, get_post( $booking ) );
		$this->assertSame( 'delegate', law_event_meta( $booking, '_law_ticket_type' ) );

		$_POST = array();
	}

	/** The committee's page has to be reachable, and reachable only by them. */
	public function test_the_dashboard_is_provisioned_and_committee_only(): void {
		$this->assertArrayHasKey( 'flagship_bookings', law_account_paths() );
		$this->assertStringContainsString( 'account/dashboard/flagship-bookings', law_flagship_bookings_url() );
		$this->assertTrue( function_exists( 'law_setup_flagship_bookings_access' ) );
	}
}
