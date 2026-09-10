<?php
/**
 * Discount codes: the catalogue and the rules.
 *
 * **Nothing honours a code yet, on purpose.** Denis settled two things on
 * 10 September 2026: codes are wanted in future, and they are NOT wanted on
 * the flagship conference. So the catalogue is built and the committee can
 * fill it, and the first priced booking flow that should accept a code opts
 * in by calling law_discount_validate() and law_discount_claim(). Until then
 * no price anywhere is reduced by anything in here.
 *
 * Written against "a priced booking" rather than any one event: an empty
 * `_law_discount_events` means the code works wherever a price is charged.
 * A code is a law_discount post — the title is the code as typed, the slug is
 * its normalised form (which is what lookups match), publish means active and
 * draft means disabled.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Discount types, for the form and the validator. */
function law_discount_types() {
	return array(
		'percent' => 'Percentage off',
		'fixed'   => 'Fixed amount off',
	);
}

/**
 * The DISPLAY form of a code: upper case, letters, digits and hyphens.
 *
 * This is what the committee types, what the catalogue shows and what goes on
 * a poster. Anything else is stripped rather than refused.
 */
function law_discount_normalise_code( $code ) {
	$code = strtoupper( trim( (string) $code ) );
	$code = preg_replace( '/[^A-Z0-9-]/', '', $code );
	return (string) $code;
}

/**
 * The MATCH form: letters and digits only, which is what lookups compare.
 *
 * Deliberately more forgiving than the display form, because the two ways a
 * code goes wrong in real life are punctuation and case. A code printed as
 * "LAW-WEEK-25" gets typed "law week 25" often enough that treating those as
 * different codes would generate support mail and lose people their
 * discount. The consequence is that two codes differing only by a hyphen
 * collide — which is right: they would be indistinguishable to a human
 * anyway, and the duplicate check refuses the second.
 */
function law_discount_match_key( $code ) {
	return (string) preg_replace( '/[^A-Z0-9]/', '', strtoupper( trim( (string) $code ) ) );
}

/**
 * Find a code by its normalised form, whatever its status.
 *
 * Status is deliberately NOT filtered here: law_discount_validate() wants to
 * tell "no such code" from "that code is turned off", and the committee's
 * catalogue lists disabled codes too.
 *
 * @return WP_Post|null
 */
function law_discount_find( $code ) {
	$slug = law_discount_match_key( $code );
	if ( '' === $slug ) {
		return null;
	}
	$found = get_posts(
		array(
			'post_type'      => LAW_DISCOUNT_CPT,
			'post_status'    => array( 'publish', 'draft' ),
			'name'           => strtolower( $slug ),
			'posts_per_page' => 1,
			'no_found_rows'  => true,
		)
	);
	return $found ? $found[0] : null;
}

/** Every code, for the catalogue and its export. */
function law_discounts_all( $limit = 500 ) {
	return get_posts(
		array(
			'post_type'      => LAW_DISCOUNT_CPT,
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => (int) $limit,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
}

/**
 * A code as a plain array, the shape every caller downstream reads.
 *
 * @param WP_Post|int $discount
 * @return array|null
 */
function law_discount_data( $discount ) {
	$post = $discount instanceof WP_Post ? $discount : get_post( (int) $discount );
	if ( ! $post || LAW_DISCOUNT_CPT !== $post->post_type ) {
		return null;
	}
	return array(
		'id'       => (int) $post->ID,
		'code'     => law_discount_normalise_code( $post->post_title ),
		'label'    => (string) $post->post_title,
		'active'   => 'publish' === $post->post_status,
		'type'     => (string) law_event_meta( $post->ID, '_law_discount_type' ),
		'value'    => (int) law_event_meta( $post->ID, '_law_discount_value' ),
		'starts'   => (string) law_event_meta( $post->ID, '_law_discount_starts' ),
		'expires'  => (string) law_event_meta( $post->ID, '_law_discount_expires' ),
		'max_uses' => (int) law_event_meta( $post->ID, '_law_discount_max_uses' ),
		'used'     => (int) get_post_meta( $post->ID, '_law_discount_used', true ),
		'events'   => array_map( 'intval', (array) law_event_meta( $post->ID, '_law_discount_events' ) ),
		'note'     => (string) law_event_meta( $post->ID, '_law_discount_note' ),
	);
}

/** "10% off", "£50.00 off", "Free place (100% off)". */
function law_discount_summary( array $discount ) {
	if ( 'percent' === $discount['type'] ) {
		return $discount['value'] >= 100 ? 'Free place (100% off)' : sprintf( '%d%% off', $discount['value'] );
	}
	return sprintf( '%s off', law_events_format_pence( $discount['value'] ) );
}

/**
 * Events a code may be limited to.
 *
 * Empty today, because nothing charges a price that honours a code. A flow
 * that starts accepting codes adds itself here, so this file never has to
 * learn what a flagship or a reception is.
 *
 * @return array<int,string> id => label.
 */
function law_discount_scope_events() {
	$events = (array) apply_filters( 'law_discount_scope_events', array() );
	$out    = array();
	foreach ( $events as $id => $label ) {
		if ( (int) $id > 0 && '' !== trim( (string) $label ) ) {
			$out[ (int) $id ] = (string) $label;
		}
	}
	return $out;
}

/**
 * Is this code usable, here, now, by this person, against this price?
 *
 * The entry point a future priced booking flow calls. It answers only; it
 * changes nothing and claims nothing (that is law_discount_claim()).
 *
 * @param string $code    As typed.
 * @param array  $context event_id, user_id, price_pence.
 * @return array|WP_Error The discount data, or why not.
 */
function law_discount_validate( $code, array $context = array() ) {
	$field = array( 'field' => 'law_discount_code' );

	if ( '' === law_discount_normalise_code( $code ) ) {
		return new WP_Error( 'law_discount_empty', 'Enter a discount code.', $field );
	}

	$post     = law_discount_find( $code );
	$discount = $post ? law_discount_data( $post ) : null;

	// One message for "no such code" and for "disabled", so the field cannot
	// be used to work out which codes exist.
	if ( ! $discount || ! $discount['active'] ) {
		return new WP_Error( 'law_discount_unknown', 'That discount code was not recognised.', $field );
	}

	$now = (int) current_time( 'timestamp', true );
	if ( '' !== $discount['starts'] ) {
		$starts = law_discount_stamp_ts( $discount['starts'] );
		if ( $starts && $now < $starts ) {
			return new WP_Error( 'law_discount_early', 'That discount code cannot be used yet.', $field );
		}
	}
	if ( '' !== $discount['expires'] ) {
		$expires = law_discount_stamp_ts( $discount['expires'] );
		if ( $expires && $now >= $expires ) {
			return new WP_Error( 'law_discount_expired', 'That discount code has expired.', $field );
		}
	}

	if ( $discount['max_uses'] > 0 && $discount['used'] >= $discount['max_uses'] ) {
		return new WP_Error( 'law_discount_used_up', 'That discount code has already been used the maximum number of times.', $field );
	}

	$event_id = (int) ( $context['event_id'] ?? 0 );
	if ( $discount['events'] && $event_id && ! in_array( $event_id, $discount['events'], true ) ) {
		return new WP_Error( 'law_discount_wrong_event', 'That discount code cannot be used for this event.', $field );
	}

	if ( (int) ( $context['price_pence'] ?? 0 ) < 1 ) {
		return new WP_Error( 'law_discount_nothing_to_discount', 'There is nothing to discount.', $field );
	}

	return $discount;
}

/** A stored site-local 'Y-m-d H:i' as a timestamp, 0 when unreadable. */
function law_discount_stamp_ts( $stamp ) {
	$stamp = trim( (string) $stamp );
	if ( '' === $stamp ) {
		return 0;
	}
	try {
		$when = new DateTimeImmutable( $stamp, wp_timezone() );
	} catch ( Exception $e ) {
		return 0;
	}
	return $when->getTimestamp();
}

/**
 * Apply a validated code to a net price.
 *
 * @return array net_pence (what is left to pay), discount_pence, is_free.
 */
function law_discount_apply( $net_pence, array $discount ) {
	$net = max( 0, (int) $net_pence );
	if ( 'percent' === $discount['type'] ) {
		$percent = min( 100, max( 0, (int) $discount['value'] ) );
		$off     = (int) round( $net * $percent / 100 );
	} else {
		$off = max( 0, (int) $discount['value'] );
	}
	// A fixed code worth more than the place makes it free, never a credit.
	$off = min( $net, $off );

	return array(
		'net_pence'      => $net - $off,
		'discount_pence' => $off,
		'is_free'        => ( $net - $off ) < 1,
	);
}

/**
 * Claim one use, atomically.
 *
 * The conditional UPDATE is the point. A caller will hold its own event's
 * lock, but a code can be shared across events, so two people applying to two
 * different events could otherwise both read "9 of 10 used" and both take the
 * tenth. MySQL evaluates the limit and the increment in one statement, so
 * exactly one of them gets it.
 *
 * @return bool True when the use was claimed.
 */
function law_discount_claim( $discount_id, $booking_id = 0 ) {
	global $wpdb;
	$discount_id = (int) $discount_id;
	if ( $discount_id < 1 ) {
		return false;
	}

	// The counter row has to exist for a conditional UPDATE to bite; codes are
	// created with it seeded at 0, and this covers one made before that.
	if ( '' === (string) get_post_meta( $discount_id, '_law_discount_used', true ) ) {
		add_post_meta( $discount_id, '_law_discount_used', 0, true );
	}

	$max = (int) law_event_meta( $discount_id, '_law_discount_max_uses' );

	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta}
			 SET meta_value = CAST(meta_value AS UNSIGNED) + 1
			 WHERE post_id = %d AND meta_key = '_law_discount_used'
			   AND ( %d = 0 OR CAST(meta_value AS UNSIGNED) < %d )",
			$discount_id,
			$max,
			$max
		)
	);
	$claimed = $wpdb->rows_affected > 0;

	// The raw write bypassed the meta API, so the cached copy is now a lie.
	wp_cache_delete( $discount_id, 'post_meta' );

	if ( $claimed && $booking_id ) {
		law_discount_log( $discount_id, $booking_id, 'claimed' );
	}
	return $claimed;
}

/**
 * Give a use back: a refused, cancelled or rolled-back booking must not burn
 * somebody else's place on a limited code.
 */
function law_discount_release( $discount_id, $booking_id = 0 ) {
	global $wpdb;
	$discount_id = (int) $discount_id;
	if ( $discount_id < 1 ) {
		return false;
	}

	// Floored at zero in SQL: a double release would otherwise wrap the
	// UNSIGNED cast and hand out an effectively unlimited code.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$wpdb->postmeta}
			 SET meta_value = CAST(meta_value AS UNSIGNED) - 1
			 WHERE post_id = %d AND meta_key = '_law_discount_used'
			   AND CAST(meta_value AS UNSIGNED) > 0",
			$discount_id
		)
	);
	$released = $wpdb->rows_affected > 0;
	wp_cache_delete( $discount_id, 'post_meta' );

	if ( $released && $booking_id ) {
		law_discount_log( $discount_id, $booking_id, 'released' );
	}
	return $released;
}

/**
 * Log a claim or release against the booking's event, so a code shows up in
 * the same activity trail as the money it changed.
 */
function law_discount_log( $discount_id, $booking_id, $what ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || LAW_BOOKING_CPT !== $booking->post_type ) {
		return;
	}
	$discount = law_discount_data( $discount_id );
	if ( ! $discount ) {
		return;
	}
	law_event_log(
		(int) $booking->post_parent,
		sprintf(
			'claimed' === $what
				? 'Discount code %1$s (%2$s) applied to booking #%3$d.'
				: 'Discount code %1$s (%2$s) released from booking #%3$d.',
			$discount['code'],
			law_discount_summary( $discount ),
			(int) law_event_meta( $booking->ID, '_law_booking_number' )
		),
		array(
			'source'   => 'discounts',
			'action'   => 'discount_' . $what,
			'booking'  => (int) $booking->ID,
			'discount' => $discount['id'],
			'code'     => $discount['code'],
		)
	);
}

/* Writing the catalogue ______________________________________________________ */

/**
 * Validate a code as the committee typed it.
 *
 * @param array $input Shaped by law_discount_input_from_post().
 * @return WP_Error Empty when valid.
 */
function law_discount_validate_input( array $input ) {
	$errors = new WP_Error();

	$code = law_discount_normalise_code( $input['code'] ?? '' );
	if ( '' === $code ) {
		$errors->add( 'code', 'Give the code a name, using letters, numbers and hyphens.' );
	} else {
		$existing = law_discount_find( $code );
		if ( $existing && (int) $existing->ID !== (int) ( $input['id'] ?? 0 ) ) {
			$errors->add( 'code', sprintf( 'The code %s already exists.', $code ) );
		}
	}

	$type = (string) ( $input['type'] ?? '' );
	if ( ! array_key_exists( $type, law_discount_types() ) ) {
		$errors->add( 'type', 'Choose whether the code takes a percentage or a fixed amount off.' );
	}

	if ( 'percent' === $type ) {
		$value = (int) ( $input['value'] ?? 0 );
		if ( $value < 1 || $value > 100 ) {
			$errors->add( 'value', 'A percentage discount must be between 1 and 100.' );
		}
	} elseif ( 'fixed' === $type ) {
		$pence = law_events_pounds_to_pence( $input['value'] ?? '' );
		if ( null === $pence || $pence < 1 ) {
			$errors->add( 'value', 'A fixed discount must be an amount in pounds, for example 50.00.' );
		}
	}

	foreach ( array( 'starts' => 'start', 'expires' => 'end' ) as $key => $word ) {
		$typed = trim( (string) ( $input[ $key ] ?? '' ) );
		if ( '' !== $typed && ! law_discount_stamp_ts( $typed ) ) {
			$errors->add( $key, sprintf( 'Enter the %s date as YYYY-MM-DD HH:MM, or leave it empty.', $word ) );
		}
	}

	$starts  = law_discount_stamp_ts( $input['starts'] ?? '' );
	$expires = law_discount_stamp_ts( $input['expires'] ?? '' );
	if ( $starts && $expires && $expires <= $starts ) {
		$errors->add( 'expires', 'The end date must be after the start date.' );
	}

	if ( (int) ( $input['max_uses'] ?? 0 ) < 0 ) {
		$errors->add( 'max_uses', 'The usage limit cannot be negative. Use 0 for no limit.' );
	}

	return $errors;
}

/** Read the committee's form. */
function law_discount_input_from_post() {
	$raw = isset( $_POST['law_discount'] ) ? wp_unslash( (array) $_POST['law_discount'] ) : array();

	return array(
		'id'       => absint( $raw['id'] ?? 0 ),
		'code'     => law_discount_normalise_code( $raw['code'] ?? '' ),
		'type'     => in_array( (string) ( $raw['type'] ?? '' ), array_keys( law_discount_types() ), true )
			? (string) $raw['type']
			: 'percent',
		// Kept as typed: a percentage is a plain integer, a fixed amount is
		// pounds, and which one it is depends on the type above.
		'value'    => trim( (string) ( $raw['value'] ?? '' ) ),
		'starts'   => trim( (string) ( $raw['starts'] ?? '' ) ),
		'expires'  => trim( (string) ( $raw['expires'] ?? '' ) ),
		'max_uses' => absint( $raw['max_uses'] ?? 0 ),
		'events'   => array_values( array_filter( array_map( 'absint', (array) ( $raw['events'] ?? array() ) ) ) ),
		'note'     => sanitize_text_field( (string) ( $raw['note'] ?? '' ) ),
		'active'   => ! empty( $raw['active'] ),
	);
}

/**
 * Create or update a code.
 *
 * @return int|WP_Error The discount post ID.
 */
function law_discount_save( array $input, $actor = 0 ) {
	$errors = law_discount_validate_input( $input );
	if ( $errors->has_errors() ) {
		return $errors;
	}

	$code   = law_discount_normalise_code( $input['code'] );
	$status = ! empty( $input['active'] ) ? 'publish' : 'draft';
	$id     = (int) ( $input['id'] ?? 0 );
	$before = $id ? law_discount_data( $id ) : null;

	$postarr = array(
		'post_type'    => LAW_DISCOUNT_CPT,
		'post_status'  => $status,
		// The title is the code as the committee wrote it, hyphens and all;
		// the slug is the punctuation-free match key, which is what every
		// lookup compares (law_discount_match_key()).
		'post_title'   => $code,
		'post_name'    => strtolower( law_discount_match_key( $code ) ),
		'post_content' => '',
	);

	if ( $id ) {
		$existing = get_post( $id );
		if ( ! $existing || LAW_DISCOUNT_CPT !== $existing->post_type ) {
			return new WP_Error( 'law_discount_missing', 'That discount code no longer exists.' );
		}
		$postarr['ID'] = $id;
		$result        = wp_update_post( wp_slash( $postarr ), true );
	} else {
		$result = wp_insert_post( wp_slash( $postarr ), true );
	}
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$id = (int) $result;

	$value = 'percent' === $input['type']
		? min( 100, max( 1, (int) $input['value'] ) )
		: (int) law_events_pounds_to_pence( $input['value'] );

	law_event_update_meta( $id, '_law_discount_type', $input['type'] );
	law_event_update_meta( $id, '_law_discount_value', $value );
	law_event_update_meta( $id, '_law_discount_starts', law_discount_normalise_stamp( $input['starts'] ) );
	law_event_update_meta( $id, '_law_discount_expires', law_discount_normalise_stamp( $input['expires'] ) );
	law_event_update_meta( $id, '_law_discount_max_uses', (int) $input['max_uses'] );
	law_event_update_meta( $id, '_law_discount_events', $input['events'] );
	law_event_update_meta( $id, '_law_discount_note', $input['note'] );

	// Seed the counter row so law_discount_claim()'s conditional UPDATE has
	// something to bite on, and never reset it on an edit: a code's usage
	// history is not the committee's to rewrite by saving the form again.
	if ( '' === (string) get_post_meta( $id, '_law_discount_used', true ) ) {
		add_post_meta( $id, '_law_discount_used', 0, true );
	}

	law_discount_log_save( $id, $before, law_discount_data( $id ), $actor );

	return $id;
}

/**
 * Log a catalogue change against the code itself.
 *
 * The activity log is keyed on a post, and a discount code IS a post, so its
 * trail lives on it and law_event_log_entries( $discount_id ) reads it back.
 * A code is money, so who changed what and when is not optional.
 */
function law_discount_log_save( $discount_id, $before, $after, $actor = 0 ) {
	if ( ! $after ) {
		return;
	}
	if ( ! $before ) {
		law_event_log(
			$discount_id,
			sprintf(
				'Discount code %1$s created: %2$s, %3$s.',
				$after['code'],
				law_discount_summary( $after ),
				$after['active'] ? 'active' : 'disabled'
			),
			array( 'source' => 'discounts', 'action' => 'discount_created', 'code' => $after['code'] ),
			array( 'user_id' => (int) $actor )
		);
		return;
	}

	$changes = array();
	if ( $before['code'] !== $after['code'] ) {
		$changes[] = sprintf( 'code %s → %s', $before['code'], $after['code'] );
	}
	if ( $before['active'] !== $after['active'] ) {
		$changes[] = $after['active'] ? 'enabled' : 'disabled';
	}
	if ( $before['type'] !== $after['type'] || $before['value'] !== $after['value'] ) {
		$changes[] = sprintf( 'value %s → %s', law_discount_summary( $before ), law_discount_summary( $after ) );
	}
	foreach ( array( 'starts', 'expires' ) as $key ) {
		if ( $before[ $key ] !== $after[ $key ] ) {
			$changes[] = sprintf( '%s %s → %s', $key, $before[ $key ] ?: 'not set', $after[ $key ] ?: 'not set' );
		}
	}
	if ( $before['max_uses'] !== $after['max_uses'] ) {
		$changes[] = sprintf(
			'usage limit %s → %s',
			$before['max_uses'] ?: 'unlimited',
			$after['max_uses'] ?: 'unlimited'
		);
	}
	if ( $before['events'] !== $after['events'] ) {
		$changes[] = $after['events']
			? sprintf( 'limited to %d event(s)', count( $after['events'] ) )
			: 'valid for any priced booking';
	}
	if ( $before['note'] !== $after['note'] ) {
		$changes[] = 'note changed';
	}

	if ( ! $changes ) {
		return;
	}
	law_event_log(
		$discount_id,
		sprintf( 'Discount code %s updated: %s.', $after['code'], implode( '; ', $changes ) ),
		array( 'source' => 'discounts', 'action' => 'discount_updated', 'code' => $after['code'], 'changes' => $changes ),
		array( 'user_id' => (int) $actor )
	);
}

/** A typed date as the stored site-local 'Y-m-d H:i', '' when empty. */
function law_discount_normalise_stamp( $typed ) {
	$ts = law_discount_stamp_ts( $typed );
	if ( ! $ts ) {
		return '';
	}
	return wp_date( 'Y-m-d H:i', $ts );
}
