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
		'_law_tickets_sold'         => 'int',  // Recalculated by law_event_recount_attendees().
		'_law_capacity_warned'      => 'flag', // One-shot host capacity-warning latch.
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
		// Committee-only classification switches (Denis, 9 September 2026).
		// _law_is_law_event: LAW runs this event itself rather than an external
		// host. Absent or 0 means hosted, which is every host submission, so
		// nothing is written at submission time and the "hosted" filter has to
		// treat a missing key as false.
		// _law_session_agenda: this event has a session-level agenda, which is
		// what puts the Session agenda section on the event form
		// (law_event_has_session_agenda() in submission-form.php).
		'_law_is_law_event'         => 'flag',
		'_law_session_agenda'       => 'flag',
		// The flagship conference (functions/events/flagship.php). Exactly one
		// law_event post carries _law_is_flagship; it is edited on the Flagship
		// screen, its date is fixed (2 December by default) and its _law_start /
		// _law_end are DERIVED from its sessions, so _law_slot_label stays empty
		// and law_event_apply_slot_label() is never called for it.
		// _law_hero_image_id is the optional banner/preview photograph, used by
		// both the programme block and the hero on the flagship page.
		'_law_is_flagship'          => 'flag',
		'_law_flagship_date'        => 'date',
		'_law_hero_image_id'        => 'int',
		'_law_gf_entry_id'          => 'int',
		'_law_rejection_reason'     => 'multiline',
		'_law_cancellation_reason'  => 'multiline',
		'_law_sector_jurisdiction'  => 'text',
		'_law_sector_other'         => 'text',
		'_law_terms_consent'        => 'consent',
	);
}

/**
 * law_speaker meta schema: identity only. Organisation, job title, photo and
 * biography are per appearance and live on the event's _law_speakers rows
 * (the featured image and the post's editor content are only fallbacks). The
 * old _law_organisation and _law_job_title rows left in the database are
 * inert.
 */
function law_speaker_meta_schema() {
	return array(
		// The name in two parts (Denis, 9 September 2026). post_title stays the
		// display name every listing prints and every lookup matches on; these
		// are the structured form the archive sorts by and the forms edit.
		'_law_speaker_first_name' => 'text',
		'_law_speaker_last_name'  => 'text',
		'_law_speaker_email'      => 'email',
		'_law_website'            => 'url',
		'_law_organisation_ids'   => 'int_array', // Reserved for 4.2 additional organisations.
		'_law_gf_entry_id'        => 'int',
		'_law_gf_entry_ids'       => 'int_array', // All merged source child entry IDs.
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

/**
 * law_booking meta schema (WAITLIST.md §A7). ONE BOOKING PER ATTENDEE: the
 * post's author is the attendee and these keys are their display snapshot as
 * entered at booking, plus who booked them. Dietary, accessibility and
 * country are never snapshotted — they read live from the attendee's profile.
 *
 * _law_booked_by is always set: the booker's user ID on a colleague's
 * booking, and the attendee's own ID when they booked themselves or a manager
 * registered them (a manager's own bookings list must never fill with people
 * they registered; who acted is in the activity log).
 */
function law_booking_meta_schema() {
	return array(
		'_law_booking_number'         => 'int',
		'_law_booked_by'              => 'int',
		'_law_attendee_name'          => 'text',
		'_law_attendee_email'         => 'email',
		'_law_attendee_organisation'  => 'text',
		'_law_attendee_job_title'     => 'text',
		'_law_is_press'               => 'flag',
		// The waitlist (WAITLIST.md §B1). Position is 1-based, so 0 never
		// means "first"; it is cleared with delete_post_meta(), because the
		// int sanitiser stores 0 rather than deleting.
		'_law_waitlist_position'      => 'int',
		'_law_waitlist_joined'        => 'datetime',
		'_law_waitlist_promoted'      => 'datetime',
		'_law_waitlist_blocked'       => 'text',
	);
}

function law_events_register_meta() {
	$types = array(
		LAW_EVENT_CPT   => law_event_meta_schema(),
		LAW_SPEAKER_CPT => law_speaker_meta_schema(),
		LAW_SESSION_CPT => law_session_meta_schema(),
		LAW_BOOKING_CPT => law_booking_meta_schema(),
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
			// Zero-padded on the way in ("9:30" becomes "09:30"), because the
			// flagship's derived start/end and law_event_session_ids() both compare
			// these strings, and "9:30" sorts after "14:00".
			$value = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
			if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ) {
				return '';
			}
			if ( (int) $m[1] > 23 || (int) $m[2] > 59 ) {
				return '';
			}
			return sprintf( '%02d:%s', (int) $m[1], $m[2] );
		case 'date':
			// A calendar date, Y-m-d, real dates only: the flagship's fixed date.
			$value = trim( (string) ( is_scalar( $value ) ? $value : '' ) );
			if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) ) {
				return '';
			}
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $value : '';
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
				// The appearance: what this speaker was at THIS event. Organisation,
				// job title, photo and biography are per event, not per person (the
				// same person speaks for different firms, and writes a different
				// biography, at different events). The pre-appearance key
				// 'organisation_override' is read as 'organisation' so rows saved
				// before the change keep working; a row with no biography falls back
				// to the speaker post's editor content at read time
				// (law_speaker_card()), the way an empty photo_id falls back to the
				// featured image. 'role' is what the person was at THIS event (Speaker /
				// Host / Moderator), stored as the law_speaker_roles() key; '' means the
				// default, Speaker, and on a session row inherits the event's.
				$organisation = (string) ( $row['organisation'] ?? '' );
				if ( '' === trim( $organisation ) ) {
					$organisation = (string) ( $row['organisation_override'] ?? '' );
				}
				$rows[] = array(
					'speaker_id'   => $id,
					'role'         => law_speaker_role_key( $row['role'] ?? '' ),
					'organisation' => sanitize_text_field( $organisation ),
					'job_title'    => sanitize_text_field( (string) ( $row['job_title'] ?? '' ) ),
					'photo_id'     => absint( $row['photo_id'] ?? 0 ),
					// Rich text (functions/events/rich-text.php), not plain: the host
					// form, the committee's Manage speakers screen and wp-admin all give
					// this field a WYSIWYG editor, and sanitize_textarea_field() would
					// strip the markup back out on every save.
					'bio'          => law_rich_text_sanitize( $row['bio'] ?? '' ),
					'sort'         => isset( $row['sort'] ) ? absint( $row['sort'] ) : $sort,
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
		$schemas = array_merge( law_event_meta_schema(), law_speaker_meta_schema(), law_session_meta_schema(), law_booking_meta_schema() );
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
 * Bump a counter option atomically and return the new value. One
 * INSERT … ON DUPLICATE KEY UPDATE with the LAST_INSERT_ID() trick, so two
 * genuinely simultaneous callers can never mint the same number — booking
 * numbers are user-facing identifiers in emails and the host list, where the
 * old get/increment/update race would confuse (adversarial review,
 * 7 September 2026; the LAW reference counter rides the same fix).
 *
 * @param string $option Counter option name.
 * @param int    $by     How many numbers to claim at once (a party of N takes
 *                       one block, so its numbers are consecutive).
 * @return int The LAST number in the claimed block.
 */
function law_events_bump_counter( $option, $by = 1 ) {
	global $wpdb;
	// $by > 1 claims a consecutive block in ONE atomic step and returns the
	// LAST number in it, so a party booked together gets consecutive booking
	// numbers even while another event is booking concurrently (the counter is
	// global, the event lock is not).
	$by = max( 1, (int) $by );

	// Two statements, deliberately. Doing the insert and the increment in one
	// looks tidier and is WRONG: when that statement genuinely inserts, MySQL
	// assigns the new row's own AUTO_INCREMENT option_id, and THAT becomes
	// LAST_INSERT_ID, overriding the value the VALUES clause asked for. So the
	// very first number a counter ever issued was the wp_options auto-increment
	// rather than 1 — on a site with 111,546 option rows behind it, the first
	// booking came out as #111547. Only the returned value was wrong; the
	// stored counter was right, which is why it was invisible until someone
	// read a booking number.
	//
	// Step one: make sure the row exists. Whatever LAST_INSERT_ID this leaves
	// behind is discarded.
	$wpdb->query(
		$wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
			 VALUES (%s, '0', 'no')
			 ON DUPLICATE KEY UPDATE option_name = option_name",
			$option
		)
	);

	// Step two: claim the number. An UPDATE assigns no AUTO_INCREMENT, so
	// LAST_INSERT_ID() is exactly what we set and nothing else, and two
	// concurrent callers still each get their own block.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->options} SET option_value = LAST_INSERT_ID(option_value + %d) WHERE option_name = %s",
			$by,
			$option
		)
	);
	$counter = (int) $wpdb->get_var( 'SELECT LAST_INSERT_ID()' );
	// The raw write bypassed the options API: drop the cached copy so a
	// later get_option() (a seeded starting value, say) reads the real row.
	wp_cache_delete( $option, 'options' );
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	return $counter;
}

/**
 * Generate the next LAW reference, continuing the GP Unique ID sequence
 * (format LAW<yy>-<5 digits>, e.g. LAW26-00207). The counter option is
 * seeded by the migrator from wp_gpui_sequence.
 */
function law_events_next_reference() {
	$counter = law_events_bump_counter( 'law_events_reference_counter' );
	$year    = (int) law_events_setting( 'year', (int) gmdate( 'Y' ) );
	return sprintf( 'LAW%02d-%05d', $year % 100, $counter );
}
