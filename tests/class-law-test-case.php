<?php
/**
 * Shared fixture helpers: created objects are tracked and hard-deleted in
 * tearDown so the local database stays clean.
 */

use PHPUnit\Framework\TestCase;

abstract class LAW_Test_Case extends TestCase {

	protected array $posts = array();
	protected array $users = array();
	protected $settings_before = null;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['law_test_stripe_queue'] = array();
		$GLOBALS['law_test_stripe_calls'] = array();
		wp_set_current_user( 0 );
		// The invoice flow refuses VAT-liable invoices without a tax rate ID,
		// so tests run with explicit Stripe config (restored in tearDown).
		$this->settings_before = get_option( LAW_EVENTS_SETTINGS_OPTION, null );
		law_events_update_settings(
			array(
				'tax_rate_id'           => 'txr_test_unit',
				'rendering_template_id' => 'inrtem_test_unit',
			)
		);
	}

	protected function tearDown(): void {
		if ( null === $this->settings_before ) {
			delete_option( LAW_EVENTS_SETTINGS_OPTION );
		} else {
			update_option( LAW_EVENTS_SETTINGS_OPTION, $this->settings_before, false );
		}
		foreach ( $this->posts as $post_id ) {
			$comments = get_comments( array( 'post_id' => $post_id ) );
			foreach ( $comments as $comment ) {
				wp_delete_comment( $comment->comment_ID, true );
			}
			wp_delete_post( $post_id, true );
		}
		foreach ( $this->users as $user_id ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $user_id );
		}
		$this->posts = array();
		$this->users = array();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	protected function make_event( array $meta = array(), $status = 'law-proposed', $author = 0 ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'    => LAW_EVENT_CPT,
				'post_status'  => $status,
				'post_title'   => 'Test event ' . wp_generate_password( 6, false ),
				'post_content' => 'Test description.',
				'post_author'  => $author,
			)
		);
		$this->posts[] = $post_id;
		$defaults      = array(
			'_law_fee_tier'      => 'uk',
			'_law_invoice_name'  => 'Test Contact',
			'_law_invoice_email' => 'invoice-' . wp_generate_password( 6, false ) . '@example.test',
			'_law_country_iso'   => 'GB',
		);
		foreach ( array_merge( $defaults, $meta ) as $key => $value ) {
			law_event_update_meta( $post_id, $key, $value );
		}
		return $post_id;
	}

	protected function make_user( $role = 'event_host' ): int {
		$user_id = wp_insert_user(
			array(
				'user_login' => 'law-test-' . wp_generate_password( 8, false ),
				'user_email' => 'law-test-' . wp_generate_password( 8, false ) . '@example.test',
				'user_pass'  => wp_generate_password( 20 ),
				'role'       => $role,
			)
		);
		$this->users[] = $user_id;
		return (int) $user_id;
	}

	protected function make_committee_user(): int {
		return $this->make_user( 'events_committee' );
	}

	/**
	 * Create a submission's bookings through the engine, tracking every
	 * booking post AND the accounts the engine creates for colleagues —
	 * tearDown only deletes tracked entities, and the engine creates things
	 * the fixtures didn't.
	 *
	 * @return int[]|WP_Error law_booking_create()'s result: one booking per
	 *                        attendee, the booker's first.
	 */
	protected function make_booking( int $event_id, int $booker_id, array $additional = array() ) {
		$result = law_booking_create( $event_id, $booker_id, $additional );
		$this->track_booking_result( $result, $additional );
		return $result;
	}

	/** The booker's own booking ID, for the many tests that only need that. */
	protected function make_booking_id( int $event_id, int $booker_id, array $additional = array() ) {
		$result = $this->make_booking( $event_id, $booker_id, $additional );
		return is_wp_error( $result ) ? $result : (int) $result[0];
	}

	/** Join the waitlist through the engine, tracking what it creates. */
	protected function make_waitlist( int $event_id, int $booker_id, array $additional = array() ) {
		$result = law_waitlist_join( $event_id, $booker_id, $additional );
		$this->track_booking_result( $result, $additional );
		return $result;
	}

	/** Add one colleague to an existing party, tracking what it creates. */
	protected function make_colleague_booking( int $event_id, int $booker_id, array $row ) {
		$result = law_booking_add_attendee( $event_id, $booker_id, $row );
		if ( ! is_wp_error( $result ) ) {
			$this->posts[] = (int) $result;
		}
		$this->track_row_users( array( $row ) );
		return $result;
	}

	/** Track engine-created bookings and accounts for teardown. */
	private function track_booking_result( $result, array $additional ): void {
		if ( ! is_wp_error( $result ) ) {
			foreach ( (array) $result as $booking_id ) {
				$this->posts[] = (int) $booking_id;
			}
		}
		$this->track_row_users( $additional );
	}

	private function track_row_users( array $rows ): void {
		foreach ( $rows as $row ) {
			$user = get_user_by( 'email', (string) ( $row['email'] ?? '' ) );
			if ( $user && ! in_array( (int) $user->ID, $this->users, true ) ) {
				$this->users[] = (int) $user->ID;
			}
		}
	}

	/** Assert a WP_Error with the given code (shared by the booking suites). */
	protected function assertWPError( $result, string $code ): void {
		$this->assertInstanceOf( WP_Error::class, $result, 'Expected a refusal.' );
		$this->assertSame( $code, $result->get_error_code(), $result->get_error_message() );
	}

	/** Queue Stripe responses for the happy invoice path. */
	protected function queue_invoice_success( $customer = 'cus_test1', $invoice = 'in_test1' ): void {
		$GLOBALS['law_test_stripe_queue'] = array(
			array( 'object' => 'list', 'data' => array() ),                       // customers?email=
			array( 'id' => $customer, 'object' => 'customer' ),                   // create customer
			array( 'id' => $invoice, 'object' => 'invoice' ),                     // create invoice
			array( 'id' => 'ii_test1', 'object' => 'invoiceitem' ),               // line item
			array( 'id' => $invoice, 'object' => 'invoice' ),                     // send
			array( 'id' => $invoice, 'hosted_invoice_url' => 'https://invoice.stripe.com/i/test123' ), // details
		);
	}

	/** Build a signed webhook request body + header. */
	protected function signed_webhook( array $event ): array {
		$payload   = wp_json_encode( $event );
		$timestamp = time();
		$signature = hash_hmac( 'sha256', $timestamp . '.' . $payload, LAW_STRIPE_WEBHOOK_SECRET );
		return array( $payload, "t={$timestamp},v1={$signature}" );
	}
}
