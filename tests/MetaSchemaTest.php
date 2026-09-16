<?php
/**
 * The meta schema: which sanitiser a key gets on which post type, and the two
 * migrated-vocabulary mappings the 16 September 2026 audit added.
 */
class MetaSchemaTest extends LAW_Test_Case {

	/**
	 * A bare law_booking post. The booking engine is not involved on purpose:
	 * this file is about which sanitiser a post type gets, and a booking made
	 * through law_booking_create() would need a bookable event behind it.
	 */
	private function make_booking_post(): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => LAW_BOOKING_CPT,
				'post_status' => 'publish',
				'post_title'  => 'Test booking ' . wp_generate_password( 6, false ),
			)
		);
		$this->posts[] = $post_id;
		return (int) $post_id;
	}

	/**
	 * _law_payment_status is declared on BOTH the event and the booking with
	 * different vocabularies. law_events_all_meta_schemas() merges the five
	 * schemas, so the booking's wins there; the write and read helpers have to
	 * ask the post itself. Until they did, an event's 'free' was sanitised to
	 * 'pending_setup' (no such event state) and then, by the registered
	 * per-post-type callback, to 'unpaid' -- which is why no £0 event in the
	 * migrated data read Free.
	 */
	public function test_payment_status_is_sanitised_per_post_type(): void {
		$event   = $this->make_event();
		$booking = $this->make_booking_post();

		$this->assertSame( 'payment_status', law_events_meta_type( $event, '_law_payment_status' ) );
		$this->assertSame( 'booking_payment_status', law_events_meta_type( $booking, '_law_payment_status' ) );

		foreach ( array( 'unpaid', 'paid', 'refunded', 'free' ) as $status ) {
			law_event_update_meta( $event, '_law_payment_status', $status );
			$this->assertSame( $status, law_event_meta( $event, '_law_payment_status' ), 'Event payment status ' . $status );
		}

		foreach ( array( 'pending_setup', 'ready', 'no_charge', 'included', 'paid' ) as $status ) {
			law_event_update_meta( $booking, '_law_payment_status', $status );
			$this->assertSame( $status, law_event_meta( $booking, '_law_payment_status' ), 'Booking payment status ' . $status );
		}
	}

	/** Each vocabulary still refuses the other's words. */
	public function test_neither_vocabulary_leaks_into_the_other(): void {
		$event   = $this->make_event();
		$booking = $this->make_booking_post();

		law_event_update_meta( $event, '_law_payment_status', 'pending_setup' );
		$this->assertSame( 'unpaid', law_event_meta( $event, '_law_payment_status' ), 'A booking state is not an event state.' );

		law_event_update_meta( $booking, '_law_payment_status', 'free' );
		$this->assertSame( 'pending_setup', law_event_meta( $booking, '_law_payment_status' ), 'An event state is not a booking state.' );
	}

	/** An unknown key is still refused, and an unknown post falls back. */
	public function test_the_type_lookup_edges(): void {
		$event = $this->make_event();
		$this->assertNull( law_events_meta_type( $event, '_law_not_a_key' ) );
		$this->assertFalse( law_event_update_meta( $event, '_law_not_a_key', 'x' ) );
		$this->assertSame(
			law_events_all_meta_schemas()['_law_venue'],
			law_events_meta_type( 0, '_law_venue' ),
			'No post: the merged map answers, which is safe for a key with one meaning.'
		);
	}

	/**
	 * Form 2 (Event > submit an event) field 74 (Address) input 74.6 (Country)
	 * holds a bare ISO code on about half the production entries and a country
	 * name on the rest. The forms offer names.
	 */
	public function test_a_billing_country_is_stored_and_read_as_a_name(): void {
		if ( ! law_registration_country_choices() ) {
			$this->markTestSkipped( 'No country list available on this environment.' );
		}

		$this->assertSame( 'United Kingdom', law_events_country_display_name( 'GB' ) );
		$this->assertSame( 'United Kingdom', law_events_country_display_name( 'gb' ) );
		$this->assertSame( 'United Kingdom', law_events_country_display_name( 'United Kingdom' ), 'Idempotent.' );
		$this->assertSame( '', law_events_country_display_name( '' ) );
		$this->assertSame( 'Ruritania', law_events_country_display_name( 'Ruritania' ), 'An unknown name is kept, never dropped.' );
		$this->assertSame( 'ZZ', law_events_country_display_name( 'ZZ' ), 'An unmapped code is kept too.' );

		$event = $this->make_event();
		law_event_update_meta( $event, '_law_invoice_address', array( 'line1' => '1 Test Street', 'city' => 'London', 'country' => 'GB' ) );
		$this->assertSame( 'United Kingdom', law_event_meta( $event, '_law_invoice_address' )['country'] );
		$this->assertSame( 'United Kingdom', law_events_form_values( get_post( $event ), array() )['invoice_country'] );

		// And the ISO Stripe is sent still derives from either spelling.
		$this->assertSame( 'GB', law_events_country_to_iso( 'GB' ) );
		$this->assertSame( 'GB', law_events_country_to_iso( 'United Kingdom' ) );
	}

	/** The repair rewrites a row written before the sanitiser mapped it. */
	public function test_the_country_repair_rewrites_a_stored_code(): void {
		if ( ! law_registration_country_choices() ) {
			$this->markTestSkipped( 'No country list available on this environment.' );
		}
		global $wpdb;

		$event = $this->make_event();
		law_event_update_meta( $event, '_law_invoice_address', array( 'line1' => '1 Test Street', 'country' => 'United Kingdom' ) );
		// Straight into the table: the sanitiser now maps the country on the
		// way in, so a legacy row cannot be made any other way.
		$wpdb->update(
			$wpdb->postmeta,
			array( 'meta_value' => maybe_serialize( array( 'line1' => '1 Test Street', 'line2' => '', 'city' => '', 'state' => '', 'postal_code' => '', 'country' => 'GB' ) ) ),
			array( 'post_id' => $event, 'meta_key' => '_law_invoice_address' )
		);
		wp_cache_delete( $event, 'post_meta' );
		$this->assertSame( 'GB', law_event_meta( $event, '_law_invoice_address' )['country'] );

		law_setup_normalise_invoice_countries();
		$this->assertSame( 'United Kingdom', law_event_meta( $event, '_law_invoice_address' )['country'] );
	}
}
