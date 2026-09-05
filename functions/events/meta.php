<?php
/**
 * The meta schema (EVENTS_4.1_REBUILD.md §3.1). One sanitiser per key; the
 * admin screens and front-end forms both write through law_event_update_meta()
 * so there is a single validation path.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * law_event meta schema: key => sanitiser id.
 * Sanitiser ids resolve in law_events_sanitize_value().
 */
function law_event_meta_schema() {
	return array(
		'_law_reference'            => 'text',
		'_law_start'                => 'datetime',
		'_law_end'                  => 'datetime',
		'_law_slot_label'           => 'text',      // The chosen slot label, for display parity.
		'_law_preferred_slots'      => 'text_array',
		'_law_venue'                => 'text',
		'_law_venue_needed'         => 'text',
		'_law_venue_capacity'       => 'text',
		'_law_tickets_available'    => 'int',
		'_law_host_organisations'   => 'text',
		'_law_organisation_ids'     => 'int_array',
		'_law_fee_tier'             => 'fee_tier',
		'_law_fee_pence'            => 'int',
		'_law_vat'                  => 'flag',
		'_law_fee_override'         => 'flag',
		'_law_fee_override_amount'  => 'float',
		'_law_invoice_name'         => 'text',
		'_law_invoice_email'        => 'email',
		'_law_invoice_address'      => 'address',
		'_law_country_iso'          => 'iso',
		'_law_vat_number'           => 'text',
		'_law_stripe_customer_id'   => 'text',
		'_law_stripe_invoice_id'    => 'text',
		'_law_stripe_invoice_url'   => 'url',
		'_law_stripe_error'         => 'stripe_error',
		'_law_payment_status'       => 'payment_status',
		'_law_approved_at'          => 'text',
		'_law_assignee'             => 'int',
		'_law_contacts'             => 'people_rows',
		'_law_co_owner_rows'        => 'people_rows',
		'_law_co_owner_ids'         => 'int_array',
		'_law_speakers'             => 'speaker_rows',
		'_law_registration_state'   => 'registration_state',
		'_law_gf_entry_id'          => 'int',
		'_law_rejection_reason'     => 'multiline',
		'_law_sector_jurisdiction'  => 'text',
		'_law_sector_other'         => 'text',
		'_law_terms_consent'        => 'consent',
	);
}

/** law_speaker meta schema. */
function law_speaker_meta_schema() {
	return array(
		'_law_speaker_email'    => 'email',
		'_law_organisation'     => 'text',
		'_law_job_title'        => 'text',
		'_law_website'          => 'url',
		'_law_organisation_ids' => 'int_array', // Reserved for 4.2 additional organisations.
		'_law_gf_entry_id'      => 'int',
		'_law_gf_entry_ids'     => 'int_array', // All merged source child entry IDs.
	);
}

/** law_session meta schema. */
function law_session_meta_schema() {
	return array(
		'_law_start_time'  => 'time',
		'_law_end_time'    => 'time',
		'_law_speakers'    => 'speaker_rows',
		'_law_gf_entry_id' => 'int',
	);
}

function law_events_register_meta() {
	$types = array(
		LAW_EVENT_CPT   => law_event_meta_schema(),
		LAW_SPEAKER_CPT => law_speaker_meta_schema(),
		LAW_SESSION_CPT => law_session_meta_schema(),
	);
	foreach ( $types as $post_type => $schema ) {
		foreach ( $schema as $key => $type ) {
			register_post_meta(
				$post_type,
				$key,
				array(
					'single'        => true,
					'show_in_rest'  => false,
					'auth_callback' => function () {
						return current_user_can( 'edit_law_events' );
					},
					'sanitize_callback' => function ( $value ) use ( $type ) {
						return law_events_sanitize_value( $value, $type );
					},
				)
			);
		}
	}
}
add_action( 'init', 'law_events_register_meta', 7 );

/**
 * @param mixed  $value Raw value.
 * @param string $type  Sanitiser id from the schemas above.
 */
function law_events_sanitize_value( $value, $type ) {
	switch ( $type ) {
		case 'text':
			return sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
		case 'multiline':
			return sanitize_textarea_field( (string) ( is_scalar( $value ) ? $value : '' ) );
		case 'int':
			return max( 0, (int) $value );
		case 'float':
			return round( max( 0, (float) $value ), 2 );
		case 'flag':
			return $value ? 1 : 0;
		case 'email':
			$email = sanitize_email( (string) ( is_scalar( $value ) ? $value : '' ) );
			return is_email( $email ) ? $email : '';
		case 'url':
			return esc_url_raw( (string) ( is_scalar( $value ) ? $value : '' ) );
		case 'iso':
			$value = strtoupper( sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) ) );
			return preg_match( '/^[A-Z]{2}$/', $value ) ? $value : '';
		case 'time':
			$value = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
			return preg_match( '/^\d{1,2}:\d{2}$/', $value ) ? $value : '';
		case 'datetime':
			$value = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
			if ( '' === $value ) {
				return '';
			}
			$ts = strtotime( $value );
			return $ts ? gmdate( 'Y-m-d H:i', $ts ) : '';
		case 'fee_tier':
			$tiers = array_keys( (array) law_events_setting( 'fee_tiers', array() ) );
			return in_array( $value, $tiers, true ) ? $value : '';
		case 'payment_status':
			return in_array( $value, array( 'unpaid', 'paid', 'refunded', 'free' ), true ) ? $value : 'unpaid';
		case 'registration_state':
			$states = array( '', 'open', 'apply', 'free', 'external', 'invitation', 'closed' );
			return in_array( $value, $states, true ) ? $value : '';
		case 'text_array':
			return array_values( array_filter( array_map( 'sanitize_text_field', (array) $value ), 'strlen' ) );
		case 'int_array':
			return array_values( array_filter( array_map( 'absint', (array) $value ) ) );
		case 'address':
			$value = (array) $value;
			$out   = array();
			foreach ( law_events_address_parts() as $part ) {
				$out[ $part ] = sanitize_text_field( (string) ( $value[ $part ] ?? '' ) );
			}
			return $out;
		case 'people_rows':
			$rows = array();
			foreach ( (array) $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$clean = array(
					'name'         => sanitize_text_field( (string) ( $row['name'] ?? '' ) ),
					'organisation' => sanitize_text_field( (string) ( $row['organisation'] ?? '' ) ),
					'email'        => sanitize_email( (string) ( $row['email'] ?? '' ) ),
				);
				if ( '' !== $clean['name'] || '' !== $clean['email'] ) {
					$rows[] = $clean;
				}
			}
			return $rows;
		case 'speaker_rows':
			$rows = array();
			$sort = 0;
			foreach ( (array) $value as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$id = absint( $row['speaker_id'] ?? 0 );
				if ( ! $id ) {
					continue;
				}
				$rows[] = array(
					'speaker_id'            => $id,
					'role'                  => sanitize_text_field( (string) ( $row['role'] ?? '' ) ),
					'organisation_override' => sanitize_text_field( (string) ( $row['organisation_override'] ?? '' ) ),
					'sort'                  => isset( $row['sort'] ) ? absint( $row['sort'] ) : $sort,
				);
				$sort++;
			}
			usort( $rows, fn( $a, $b ) => $a['sort'] <=> $b['sort'] );
			return $rows;
		case 'consent':
			$value = (array) $value;
			return array(
				'accepted' => ! empty( $value['accepted'] ) ? 1 : 0,
				'at'       => sanitize_text_field( (string) ( $value['at'] ?? '' ) ),
			);
		case 'stripe_error':
			if ( empty( $value ) ) {
				return '';
			}
			$value = (array) $value;
			return array(
				'message' => sanitize_text_field( (string) ( $value['message'] ?? '' ) ),
				'at'      => sanitize_text_field( (string) ( $value['at'] ?? '' ) ),
			);
	}
	return sanitize_text_field( (string) ( is_scalar( $value ) ? $value : '' ) );
}

/**
 * Write one meta value through the schema sanitiser. The single write path
 * shared by the admin screens, the front-end forms and the migrator.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Schema key.
 * @param mixed  $value   Raw value.
 */
/**
 * The three meta schemas merged into one map, built once per request. This is
 * the hot path: law_event_meta() reads run into the hundreds on a single
 * programme render, so the merge is memoised rather than rebuilt each call.
 *
 * @return array<string,string> Meta key => type.
 */
/**
 * The ordered invoice/billing address part keys — the single source the meta
 * sanitiser, the admin screen and the migrator loop over.
 *
 * @return string[]
 */
function law_events_address_parts() {
	return array( 'line1', 'line2', 'city', 'state', 'postal_code', 'country' );
}

function law_events_all_meta_schemas() {
	static $schemas = null;
	if ( null === $schemas ) {
		$schemas = array_merge( law_event_meta_schema(), law_speaker_meta_schema(), law_session_meta_schema() );
	}
	return $schemas;
}

function law_event_update_meta( $post_id, $key, $value ) {
	$schemas = law_events_all_meta_schemas();
	if ( ! isset( $schemas[ $key ] ) ) {
		return false;
	}
	$clean = law_events_sanitize_value( $value, $schemas[ $key ] );
	if ( '' === $clean || array() === $clean ) {
		return delete_post_meta( $post_id, $key );
	}
	return update_post_meta( $post_id, $key, $clean );
}

/**
 * Read one meta value, with schema-shaped fallbacks for array types.
 *
 * @param int    $post_id Post ID.
 * @param string $key     Schema key.
 */
function law_event_meta( $post_id, $key ) {
	$value   = get_post_meta( $post_id, $key, true );
	$schemas = law_events_all_meta_schemas();
	$type    = $schemas[ $key ] ?? 'text';
	if ( in_array( $type, array( 'text_array', 'int_array', 'people_rows', 'speaker_rows' ), true ) ) {
		return is_array( $value ) ? $value : array();
	}
	if ( in_array( $type, array( 'address', 'consent' ), true ) ) {
		return is_array( $value ) ? $value : array();
	}
	if ( 'stripe_error' === $type ) {
		return is_array( $value ) ? $value : '';
	}
	return $value;
}

/**
 * Generate the next LAW reference, continuing the GP Unique ID sequence
 * (format LAW<yy>-<5 digits>, e.g. LAW26-00207). The counter option is
 * seeded by the migrator from wp_gpui_sequence.
 */
function law_events_next_reference() {
	$counter = (int) get_option( 'law_events_reference_counter', 0 );
	$counter++;
	update_option( 'law_events_reference_counter', $counter, false );
	$year = (int) law_events_setting( 'year', (int) gmdate( 'Y' ) );
	return sprintf( 'LAW%02d-%05d', $year % 100, $counter );
}
