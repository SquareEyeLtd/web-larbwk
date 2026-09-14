<?php
/**
 * The drinks receptions (RECEPTIONS.md).
 *
 * LAW runs three receptions during the week. Monday and Wednesday are PAID and
 * pay-now, with no committee review, and both are also free with a confirmed
 * flagship place. Friday is INVITATION ONLY: the site shows it for information
 * and in the calendar, with a notice where the booking button would be.
 *
 * The shape, and why it is neither the hosted booking engine nor the flagship's:
 * a hosted place is free and instant, a flagship place is applied for and then
 * charged, and a reception place is BOUGHT — the delegate goes to Stripe's
 * hosted page, pays, and comes back with a confirmed place and a VAT invoice.
 * That is a third shape, so it has its own guards, its own meaning for the
 * statuses and its own handlers.
 *
 * Everything genuinely shared is reused rather than copied: the booking post
 * type and its numbering, the duplicate guard, the event lock, the places
 * recount, the account resolve-or-create, the activity log, the email registry,
 * the .ics generator, the whole of stripe/attendees.php, and the waitlist —
 * which is modified in place rather than forked, because the wp-admin backstops
 * call law_waitlist_process() by name and a parallel pass would never run for a
 * reception queue.
 *
 * A reception is an ordinary law_event carrying _law_is_reception, so the
 * programme, the event page, the calendar, capacity, the per-event bookings
 * list and the exports all work with no change.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Reading ____________________________________________________________________ */

/** Is this event one of the receptions? */
function law_reception_is( $event_id ) {
	$event_id = (int) $event_id;
	if ( $event_id < 1 || LAW_EVENT_CPT !== get_post_type( $event_id ) ) {
		return false;
	}

	return (bool) law_event_meta( $event_id, '_law_is_reception' );
}

/** Is this booking a place at a reception? */
function law_reception_booking_is( $booking ) {
	return 'reception' === law_booking_kind( $booking );
}

/**
 * Every reception, in date order, whatever its status.
 *
 * All statuses on purpose: the committee's Manage receptions screen is where a
 * draft becomes a published one, so a list that only showed the published ones
 * would hide the thing it exists to publish.
 *
 * @param int $year Optional programme year (the law_year term).
 * @return int[] law_event post IDs, earliest first.
 */
function law_reception_ids( $year = 0 ) {
	$args = array(
		'post_type'      => LAW_EVENT_CPT,
		'post_status'    => law_event_all_status_keys(),
		'meta_key'       => '_law_start',
		'orderby'        => array( 'meta_value' => 'ASC', 'ID' => 'ASC' ),
		'fields'         => 'ids',
		'posts_per_page' => 100,
		'no_found_rows'  => true,
		'meta_query'     => array(
			array( 'key' => '_law_is_reception', 'value' => '1' ),
		),
	);
	if ( (int) $year > 0 ) {
		$args['tax_query'] = array(
			array( 'taxonomy' => 'law_year', 'field' => 'name', 'terms' => (string) (int) $year ),
		);
	}

	// A reception with no date yet has no _law_start row, and meta_key ordering
	// would drop it from the list entirely — which is exactly the reception the
	// committee has just created and needs to find. So the ordering query runs
	// first and anything missing is appended.
	$dated = get_posts( $args );

	unset( $args['meta_key'], $args['orderby'] );
	$all = get_posts( $args + array( 'orderby' => 'ID', 'order' => 'ASC' ) );

	return array_values( array_unique( array_merge( array_map( 'intval', $dated ), array_map( 'intval', $all ) ) ) );
}

/**
 * The receptions a confirmed flagship place includes: published, flagged, and
 * not yet started.
 *
 * @return int[]
 */
function law_reception_included_ids() {
	$out = array();
	foreach ( law_reception_ids() as $event_id ) {
		if ( 'publish' !== get_post_status( $event_id ) ) {
			continue;
		}
		if ( ! law_event_meta( $event_id, '_law_flagship_included' ) ) {
			continue;
		}
		$start = (string) law_event_meta( $event_id, '_law_start' );
		if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
			continue;
		}
		$out[] = (int) $event_id;
	}

	return $out;
}

/**
 * A reception's day and time as one short phrase, for a checkbox label or a
 * banner sentence: "Monday 30 November, 18:30".
 */
function law_reception_when_label( $event_id ) {
	$start = (string) law_event_meta( (int) $event_id, '_law_start' );
	if ( '' === $start ) {
		return '';
	}
	$ts = strtotime( $start );
	if ( ! $ts ) {
		return '';
	}

	return date_i18n( 'l j F', $ts ) . ', ' . substr( $start, 11, 5 );
}

/* Provisioning _______________________________________________________________ */

/**
 * The three receptions LAW runs, as the committee first meets them.
 *
 * Seeded as law-draft with no price (0 means "not on sale"), because the
 * prices are LAW's to confirm and a figure invented here would go live the
 * moment somebody ticked "Show on the programme". Monday and Wednesday are
 * included with a flagship place; Friday is invitation only.
 *
 * @return array<string,array> slug => title, meta, day offset within the week.
 */
function law_reception_seed_map() {
	return array(
		'opening-drinks'       => array(
			'title' => 'Opening drinks',
			'day'   => 'Monday',
			'meta'  => array(
				'_law_is_reception'      => 1,
				'_law_is_law_event'      => 1,
				'_law_flagship_included' => 1,
				'_law_registration_state' => 'free',
				'_law_attendee_price_pence' => 0,
			),
		),
		'wednesday-reception'  => array(
			'title' => 'Wednesday reception',
			'day'   => 'Wednesday',
			'meta'  => array(
				'_law_is_reception'      => 1,
				'_law_is_law_event'      => 1,
				'_law_flagship_included' => 1,
				'_law_registration_state' => 'free',
				'_law_attendee_price_pence' => 0,
			),
		),
		'friday-reception'     => array(
			'title' => 'Friday reception',
			'day'   => 'Friday',
			'meta'  => array(
				'_law_is_reception'       => 1,
				'_law_is_law_event'       => 1,
				'_law_registration_state' => 'invitation',
				'_law_attendee_price_pence' => 0,
			),
		),
	);
}

/**
 * Create the three reception posts when they are missing, so a git deploy
 * alone is enough: the code is deployed, the records are database state.
 *
 * Called from migration step 10, the ?setup-account-pages trigger and the
 * Manage receptions screen's first open, all of which may run in any order and
 * more than once. Idempotent by slug.
 *
 * @param bool $dry Report what would happen, change nothing.
 * @return array{created:int, messages:string[]}
 */
function law_reception_ensure_posts( $dry = false ) {
	$created  = 0;
	$messages = array();
	$week     = function_exists( 'law_calendar_week_days' ) ? (array) law_calendar_week_days() : array();

	foreach ( law_reception_seed_map() as $slug => $config ) {
		$meta = $config['meta'];

		// A start date, when the programme week is known, so a seeded
		// reception lands under the right day instead of floating above the
		// programme with no slot. 18:30 to 20:30 is the shape LAW runs them
		// in; the committee edits both on the dashboard.
		foreach ( $week as $date => $label ) {
			if ( 0 === strpos( (string) $label, $config['day'] ) ) {
				$meta['_law_start'] = $date . ' 18:30';
				$meta['_law_end']   = $date . ' 20:30';
				break;
			}
		}

		$result     = law_event_ensure_managed_post( $slug, $config['title'], $meta, $dry );
		$messages[] = $result['message'];
		if ( $result['created'] ) {
			$created++;
			law_event_log(
				(int) $result['id'],
				sprintf( 'Reception "%s" created as a draft. Set its date, venue, places and price on Manage receptions.', $config['title'] ),
				array( 'action' => 'reception_created', 'source' => 'setup' )
			);
		}
	}

	return array( 'created' => $created, 'messages' => $messages );
}

/* The committee's edit form __________________________________________________ */

/**
 * The values the edit form renders, for an existing reception or a blank one.
 *
 * @param int $event_id 0 for the "Add a reception" form.
 * @return array
 */
function law_reception_form_values( $event_id = 0 ) {
	$event_id = (int) $event_id;
	$post     = $event_id ? get_post( $event_id ) : null;
	if ( $post && ! law_reception_is( $event_id ) ) {
		$post = null;
	}

	if ( ! $post ) {
		return array(
			'event_id'    => 0,
			'title'       => '',
			'description' => '',
			'date'        => '',
			'start'       => '18:30',
			'end'         => '20:30',
			'venue'       => '',
			'places'      => 0,
			'price'       => '',
			'included'    => false,
			'invitation'  => false,
			'show'        => false,
		);
	}

	$start = (string) law_event_meta( $event_id, '_law_start' );
	$end   = (string) law_event_meta( $event_id, '_law_end' );
	$price = (int) law_event_meta( $event_id, '_law_attendee_price_pence' );

	return array(
		'event_id'    => $event_id,
		'title'       => $post->post_title,
		'description' => $post->post_content,
		'date'        => '' !== $start ? substr( $start, 0, 10 ) : '',
		'start'       => '' !== $start ? substr( $start, 11, 5 ) : '',
		'end'         => '' !== $end ? substr( $end, 11, 5 ) : '',
		'venue'       => (string) law_event_meta( $event_id, '_law_venue' ),
		'places'      => absint( law_event_meta( $event_id, '_law_tickets_available' ) ),
		// Pounds in the box, pence in the database. Blank when nothing has
		// ever been typed, so "not on sale" and "zero pounds" both read as a
		// figure the committee has to enter on purpose.
		'price'       => $price > 0 ? number_format( $price / 100, 2, '.', '' ) : '0.00',
		'included'    => (bool) law_event_meta( $event_id, '_law_flagship_included' ),
		'invitation'  => law_event_is_invitation_only( $event_id ),
		'show'        => 'publish' === $post->post_status,
	);
}

/**
 * Read one submitted reception from $_POST.
 *
 * Checkboxes cannot be told apart from "not on the form" by their absence, so
 * every form carries a law_reception[present] sentinel; without it a POST that
 * PHP truncated at max_input_vars would read as "the committee unticked
 * everything" and take a reception off the programme.
 */
function law_reception_input_from_post() {
	$raw = isset( $_POST['law_reception'] ) ? wp_unslash( (array) $_POST['law_reception'] ) : array();

	$input = array(
		'event_id' => absint( $raw['event_id'] ?? 0 ),
	);

	// Only keys that were actually on the form, so the wp-admin box (four
	// controls) and the dashboard (the lot) can share one saver.
	$text = array(
		'title'       => 'sanitize_text_field',
		'date'        => null,
		'start'       => null,
		'end'         => null,
		'venue'       => 'sanitize_text_field',
		// Typed in pounds and kept as the typed string, so validation can tell
		// "0" (free) from "" (leave it alone) from "abc" (a typo worth
		// refusing rather than silently zeroing a price).
		'price'       => null,
	);
	foreach ( $text as $key => $filter ) {
		if ( array_key_exists( $key, $raw ) ) {
			$value          = trim( (string) $raw[ $key ] );
			$input[ $key ]  = $filter ? call_user_func( $filter, $value ) : $value;
		}
	}
	if ( array_key_exists( 'description', $raw ) ) {
		$input['description'] = law_rich_text_sanitize( $raw['description'] );
	}
	if ( array_key_exists( 'places', $raw ) ) {
		$input['places'] = trim( (string) $raw['places'] );
	}

	// The sentinel: the checkboxes are only read when the form said it carried
	// them.
	if ( ! empty( $raw['present'] ) ) {
		$input['show']       = ! empty( $raw['show'] );
		$input['included']   = ! empty( $raw['included'] );
		$input['invitation'] = ! empty( $raw['invitation'] );
	}

	return $input;
}

/**
 * Validate a submitted reception. Judged key by key: a key the caller did not
 * send is not being edited, and a present-but-empty one means "leave the
 * stored value alone" — except the title, which a reception cannot be without.
 *
 * @param array $input    law_reception_input_from_post().
 * @param array $args     partial (bool): the wp-admin box, which renders four
 *                        controls and must not be judged on the rest.
 * @return WP_Error Empty when the input is good.
 */
function law_reception_validate( array $input, array $args = array() ) {
	$errors  = new WP_Error();
	$partial = ! empty( $args['partial'] );

	if ( ! $partial ) {
		if ( '' === trim( (string) ( $input['title'] ?? '' ) ) ) {
			$errors->add( 'title', __( 'Give the reception a title.', 'law' ) );
		}
	}

	if ( array_key_exists( 'date', $input ) && '' !== $input['date'] ) {
		if ( '' === (string) law_events_sanitize_value( $input['date'], 'date' ) ) {
			$errors->add( 'date', __( 'Enter the date as YYYY-MM-DD.', 'law' ) );
		}
	}

	$start = array_key_exists( 'start', $input ) ? (string) law_events_sanitize_value( $input['start'], 'time' ) : '';
	$end   = array_key_exists( 'end', $input ) ? (string) law_events_sanitize_value( $input['end'], 'time' ) : '';
	if ( array_key_exists( 'start', $input ) && '' !== $input['start'] && '' === $start ) {
		$errors->add( 'start', __( 'Enter the start time as HH:MM.', 'law' ) );
	}
	if ( array_key_exists( 'end', $input ) && '' !== $input['end'] && '' === $end ) {
		$errors->add( 'end', __( 'Enter the end time as HH:MM.', 'law' ) );
	}
	// Compared zero-padded, as the schema stores them: "10:30" is
	// lexicographically LESS than "9:30", so an unpadded pair would be refused
	// as ending before it starts.
	if ( '' !== $start && '' !== $end && $end <= $start ) {
		$errors->add( 'end', __( 'The reception cannot end before it starts.', 'law' ) );
	}

	if ( array_key_exists( 'price', $input ) && '' !== trim( (string) $input['price'] ) ) {
		if ( null === law_events_pounds_to_pence( $input['price'] ) ) {
			$errors->add( 'price', __( 'The price must be an amount in pounds, for example 45.00.', 'law' ) );
		}
	}

	// Places may be lowered, but not below the people already holding a place:
	// the count would read as a lie and the over-booking warning would fire on
	// every subsequent booking.
	if ( array_key_exists( 'places', $input ) && '' !== trim( (string) $input['places'] ) ) {
		$event_id = (int) ( $input['event_id'] ?? 0 );
		$places   = (int) $input['places'];
		if ( $event_id && law_reception_is( $event_id ) ) {
			$taken = law_event_attendee_total( $event_id );
			if ( $places > 0 && $places < $taken ) {
				$errors->add(
					'places',
					sprintf(
						/* translators: 1: places already taken, 2: the number typed. */
						__( 'There are already %1$d places taken, so the number available cannot be set to %2$d.', 'law' ),
						$taken,
						$places
					)
				);
			}
		}
	}

	return $errors;
}

/**
 * Save one reception: THE single write path, shared by the committee's
 * dashboard and the wp-admin meta box, so there is no second place where a
 * price or an invitation-only switch can be set.
 *
 * @param array $input law_reception_input_from_post().
 * @param int   $actor Acting user.
 * @param array $args  partial (bool): write only the keys present, and do not
 *                     touch the post itself. Used by the wp-admin box.
 * @return int|WP_Error The reception's post ID.
 */
function law_reception_save( array $input, $actor = 0, array $args = array() ) {
	$partial  = ! empty( $args['partial'] );
	$event_id = (int) ( $input['event_id'] ?? 0 );

	$errors = law_reception_validate( $input, $args );
	if ( $errors->has_errors() ) {
		return $errors;
	}

	if ( $event_id && ! law_reception_is( $event_id ) ) {
		// The wp-admin box is the one caller that may turn an ordinary event
		// INTO a reception, which is what the "Is a reception" tick means.
		if ( ! $partial || LAW_EVENT_CPT !== get_post_type( $event_id ) ) {
			return new WP_Error( 'law_reception_not_a_reception', __( 'That event is not a reception.', 'law' ) );
		}
	}

	if ( ! $event_id ) {
		if ( $partial ) {
			return new WP_Error( 'law_reception_missing', __( 'That reception could not be found.', 'law' ) );
		}
		$created = law_event_ensure_managed_post(
			sanitize_title( $input['title'] ),
			$input['title'],
			array( '_law_is_reception' => 1, '_law_is_law_event' => 1 )
		);
		$event_id = (int) $created['id'];
		if ( ! $event_id ) {
			return new WP_Error( 'law_reception_insert_failed', $created['message'] );
		}
	}

	$before = law_reception_snapshot( $event_id );

	if ( ! $partial ) {
		// The module's status guard (functions/events/workflow.php) reverts any
		// status change to an existing law_event that does not come from the
		// workflow engine, which is what stops the classic editor's Publish
		// button confirming an unapproved event. A reception has no workflow at
		// all — its two statuses mean only "on the programme" and "not yet" —
		// so its saver announces itself, exactly as the flagship's does, and
		// the guard honours the flag for those two statuses only.
		$GLOBALS['law_event_managed_saving'] = true;
		$updated                             = wp_update_post(
			wp_slash(
				array(
					'ID'           => $event_id,
					'post_title'   => $input['title'],
					'post_content' => (string) ( $input['description'] ?? '' ),
					'post_status'  => ! empty( $input['show'] ) ? 'publish' : 'law-draft',
				)
			),
			true
		);
		unset( $GLOBALS['law_event_managed_saving'] );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}

	law_event_update_meta( $event_id, '_law_is_reception', 1 );
	if ( ! $partial ) {
		// A reception is LAW's own event, never a host submission, which is
		// what keeps it out of the "hosted" filter. Asserted on a full save
		// only: the wp-admin box renders the Classification tick separately,
		// and overriding it from here would undo what somebody had just done.
		law_event_update_meta( $event_id, '_law_is_law_event', 1 );
	}

	// The date and the two times are written straight into _law_start and
	// _law_end, and _law_slot_label stays empty: a reception is not one of the
	// programme's bookable slots, exactly as the flagship is not.
	if ( array_key_exists( 'date', $input ) ) {
		$date = (string) law_events_sanitize_value( $input['date'], 'date' );
		if ( '' !== $date ) {
			$start = (string) law_events_sanitize_value( $input['start'] ?? '', 'time' );
			$end   = (string) law_events_sanitize_value( $input['end'] ?? '', 'time' );
			law_event_update_meta( $event_id, '_law_start', '' !== $start ? $date . ' ' . $start : $date . ' 00:00' );
			law_event_update_meta( $event_id, '_law_end', '' !== $end ? $date . ' ' . $end : '' );
			law_event_update_meta( $event_id, '_law_slot_label', '' );
		}
	}

	if ( array_key_exists( 'venue', $input ) ) {
		law_event_update_meta( $event_id, '_law_venue', $input['venue'] );
	}
	if ( array_key_exists( 'places', $input ) && '' !== trim( (string) $input['places'] ) ) {
		law_event_update_meta( $event_id, '_law_tickets_available', (int) $input['places'] );
	}
	// Blank leaves the stored price alone; "0" is a real answer meaning "not on
	// sale", which is why the typed string reaches here rather than an int.
	if ( array_key_exists( 'price', $input ) && '' !== trim( (string) $input['price'] ) ) {
		$pence = law_events_pounds_to_pence( $input['price'] );
		if ( null !== $pence ) {
			law_event_update_meta( $event_id, '_law_attendee_price_pence', $pence );
		}
	}
	if ( array_key_exists( 'included', $input ) ) {
		law_event_update_meta( $event_id, '_law_flagship_included', ! empty( $input['included'] ) );
	}
	if ( array_key_exists( 'invitation', $input ) ) {
		// One vocabulary for "how is this booked", so nothing has to ask two
		// keys and reconcile them: invitation-only wins, otherwise a priced
		// reception is 'open' and a free one is 'free'.
		law_event_update_meta(
			$event_id,
			'_law_registration_state',
			! empty( $input['invitation'] ) ? 'invitation' : ( law_event_is_priced( $event_id ) ? 'open' : 'free' )
		);
	} elseif ( array_key_exists( 'price', $input ) && ! law_event_is_invitation_only( $event_id ) ) {
		law_event_update_meta( $event_id, '_law_registration_state', law_event_is_priced( $event_id ) ? 'open' : 'free' );
	}

	law_reception_log_save( $event_id, $before, law_reception_snapshot( $event_id ), $actor );

	return $event_id;
}

/** Everything law_reception_log_save() compares, before and after a save. */
function law_reception_snapshot( $event_id ) {
	$post = get_post( (int) $event_id );

	return array(
		'title'      => $post ? $post->post_title : '',
		'status'     => $post ? $post->post_status : '',
		'start'      => (string) law_event_meta( $event_id, '_law_start' ),
		'end'        => (string) law_event_meta( $event_id, '_law_end' ),
		'venue'      => (string) law_event_meta( $event_id, '_law_venue' ),
		'places'     => absint( law_event_meta( $event_id, '_law_tickets_available' ) ),
		'price'      => (int) law_event_meta( $event_id, '_law_attendee_price_pence' ),
		'included'   => (int) (bool) law_event_meta( $event_id, '_law_flagship_included' ),
		'state'      => (string) law_event_meta( $event_id, '_law_registration_state' ),
		'reception'  => (int) (bool) law_event_meta( $event_id, '_law_is_reception' ),
	);
}

/**
 * One log line per save that changed something, in the plain-words style the
 * rest of the module's activity log uses. Nothing is written when a save
 * changed nothing, so the log stays a record of decisions rather than of
 * clicks. Money is spelled out in full: this is a payment path.
 */
function law_reception_log_save( $event_id, array $before, array $after, $actor = 0 ) {
	$changes = array();

	if ( $before['reception'] !== $after['reception'] ) {
		$changes[] = $after['reception'] ? 'marked as a reception' : 'no longer a reception';
	}
	if ( $before['status'] !== $after['status'] ) {
		$changes[] = 'publish' === $after['status'] ? 'shown on the programme' : 'hidden from the programme';
	}
	if ( $before['title'] !== $after['title'] ) {
		$changes[] = sprintf( 'title "%s" → "%s"', $before['title'], $after['title'] );
	}
	if ( $before['start'] !== $after['start'] ) {
		$changes[] = sprintf( 'starts %s → %s', $before['start'] ?: 'not set', $after['start'] ?: 'not set' );
	}
	if ( $before['end'] !== $after['end'] ) {
		$changes[] = sprintf( 'ends %s → %s', $before['end'] ?: 'not set', $after['end'] ?: 'not set' );
	}
	if ( $before['venue'] !== $after['venue'] ) {
		$changes[] = sprintf( 'venue "%s" → "%s"', $before['venue'] ?: 'not set', $after['venue'] ?: 'not set' );
	}
	if ( $before['places'] !== $after['places'] ) {
		$changes[] = sprintf( 'places available %d → %d', $before['places'], $after['places'] );
	}
	if ( $before['price'] !== $after['price'] ) {
		$changes[] = sprintf(
			'price %s → %s (excluding VAT)',
			$before['price'] > 0 ? law_events_format_pence( $before['price'] ) : 'not on sale',
			$after['price'] > 0 ? law_events_format_pence( $after['price'] ) : 'not on sale'
		);
	}
	if ( $before['included'] !== $after['included'] ) {
		$changes[] = $after['included']
			? 'included free with a confirmed flagship place'
			: 'no longer included with a flagship place';
	}
	if ( $before['state'] !== $after['state'] ) {
		$changes[] = 'invitation' === $after['state']
			? 'invitation only: the site takes no bookings'
			: sprintf( 'bookable on the site (%s)', 'open' === $after['state'] ? 'paid' : 'free' );
	}

	if ( ! $changes ) {
		return;
	}

	law_event_log(
		(int) $event_id,
		'Reception updated: ' . implode( '; ', $changes ) . '.',
		array( 'source' => 'receptions', 'action' => 'reception_saved' ),
		array( 'user_id' => (int) $actor )
	);
}

/* Guards _____________________________________________________________________ */

/**
 * Is this reception open to a booking at all: published, on the CPT source,
 * really a reception, not invitation-only, and not yet started?
 *
 * Capacity is deliberately NOT here. A full reception still has something to
 * offer (the waitlist), and a confirmed flagship delegate still gets their
 * included place, so "full" is a state the surfaces decide on, not a refusal
 * this guard makes.
 *
 * @return true|WP_Error
 */
function law_reception_guard_open( $event_id ) {
	$event_id = (int) $event_id;
	$post     = $event_id ? get_post( $event_id ) : null;

	if ( ! $post || LAW_EVENT_CPT !== $post->post_type || 'cpt' !== law_events_source() || ! law_reception_is( $event_id ) ) {
		return new WP_Error( 'law_reception_missing', __( 'That reception could not be found.', 'law' ) );
	}
	if ( 'publish' !== $post->post_status ) {
		return new WP_Error( 'law_reception_unpublished', __( 'This reception is not open for booking yet.', 'law' ) );
	}
	if ( law_event_is_invitation_only( $event_id ) ) {
		return new WP_Error( 'law_booking_invitation_only', __( 'Places at this reception are by invitation from LAW.', 'law' ) );
	}
	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
		return new WP_Error( 'law_booking_closed', __( 'This reception has taken place, so bookings are closed.', 'law' ) );
	}

	return true;
}

/* The quote __________________________________________________________________ */

/**
 * What one place costs this person right now, with an optional discount code
 * applied.
 *
 * PURE: it reads, it does not write and it claims nothing. That is what lets
 * the same function render the dialog, answer the live Apply endpoint AND be
 * re-run inside the checkout handler — which is the point, because it means
 * the client can never send its own price.
 *
 * @param int    $event_id law_event post ID.
 * @param string $code     As typed; '' for the list price.
 * @param int    $user_id  The delegate, for a future per-user code limit.
 * @return array{list_net:int,net:int,discount:int,vat:int,gross:int,code:string,discount_id:int,free:bool}|WP_Error
 */
function law_reception_quote( $event_id, $code = '', $user_id = 0 ) {
	$event_id = (int) $event_id;
	$list_net = law_event_price_pence( $event_id );
	$code     = trim( (string) $code );

	$quote = array(
		'list_net'    => $list_net,
		'net'         => $list_net,
		'discount'    => 0,
		'vat'         => $list_net > 0 ? law_events_vat_pence( $list_net ) : 0,
		'gross'       => $list_net > 0 ? law_events_gross_pence( $list_net ) : 0,
		'code'        => '',
		'discount_id' => 0,
		'free'        => $list_net < 1,
	);

	if ( '' === $code ) {
		return $quote;
	}

	$discount = law_discount_validate(
		$code,
		array( 'event_id' => $event_id, 'user_id' => (int) $user_id, 'price_pence' => $list_net )
	);
	if ( is_wp_error( $discount ) ) {
		return $discount;
	}

	$applied = law_discount_apply( $list_net, $discount );

	$quote['net']         = (int) $applied['net_pence'];
	$quote['discount']    = (int) $applied['discount_pence'];
	$quote['vat']         = $quote['net'] > 0 ? law_events_vat_pence( $quote['net'] ) : 0;
	$quote['gross']       = $quote['net'] > 0 ? law_events_gross_pence( $quote['net'] ) : 0;
	$quote['code']        = (string) $discount['code'];
	$quote['discount_id'] = (int) $discount['id'];
	$quote['free']        = (bool) $applied['is_free'];

	return $quote;
}

/* Checkout ___________________________________________________________________ */

/**
 * Buy one place at a reception: hold it, then send the delegate to Stripe.
 *
 * ONE PLACE PER CHECKOUT, self only (Denis, 14 September 2026). Colleagues buy
 * their own, which is why there is no repeater here and no party to unwind if
 * a payment fails half way.
 *
 * The order of operations is the whole design:
 *
 *   1. the cheap refusals, before anything is written;
 *   2. the code is CLAIMED before the booking is inserted, so a code on its
 *      last use is either ours or somebody else's, decided by one conditional
 *      UPDATE, with nothing to roll back if we lose;
 *   3. the hold is inserted inside the event lock, which is what stops two
 *      people buying the same last place;
 *   4. Stripe is called AFTER the unlock, because a 30-second network call
 *      must never queue the next buyer behind it.
 *
 * @param int   $user_id The delegate (always themselves).
 * @param array $input   event_id, code, applied_code, terms, price_shown.
 * @return array{booking:int,redirect:string}|WP_Error
 */
function law_reception_checkout( $user_id, array $input ) {
	$user_id  = (int) $user_id;
	$user     = $user_id ? get_user_by( 'id', $user_id ) : null;
	$event_id = absint( $input['event_id'] ?? 0 );

	if ( ! $user ) {
		return new WP_Error( 'law_reception_no_user', __( 'You need to be signed in to book a place.', 'law' ) );
	}
	$open = law_reception_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $open;
	}
	if ( ! law_event_is_priced( $event_id ) ) {
		return new WP_Error( 'law_reception_not_on_sale', __( 'Places at this reception are not on sale.', 'law' ) );
	}
	if ( empty( $input['terms'] ) ) {
		return new WP_Error(
			'law_reception_no_terms',
			__( 'Please accept the registration terms and conditions.', 'law' ),
			array( 'field' => 'law_terms' )
		);
	}

	$code    = trim( (string) ( $input['code'] ?? '' ) );
	$applied = trim( (string) ( $input['applied_code'] ?? '' ) );

	// A code typed but never checked would otherwise be applied here and then
	// refused as a price change, which is untrue and reads as a bug. Ask for
	// the press instead. Skipped when the request is not AJAX and no code was
	// typed, because the no-JS path has no Apply button to press.
	if ( law_discount_normalise_code( $code ) !== law_discount_normalise_code( $applied ) && ! empty( $input['ajax'] ) ) {
		return new WP_Error(
			'law_reception_code_unapplied',
			__( 'Press Apply to check your code before continuing.', 'law' ),
			array( 'field' => 'law_discount_code' )
		);
	}

	$quote = law_reception_quote( $event_id, $code, $user_id );
	if ( is_wp_error( $quote ) ) {
		return $quote;
	}

	$shown = law_booking_guard_price_shown(
		(int) ( $input['price_shown'] ?? 0 ),
		$quote['gross'],
		'law_reception_price_changed'
	);
	if ( is_wp_error( $shown ) ) {
		return $shown;
	}

	// The profile IS the booking's snapshot, so it has to carry a name: the
	// list the door staff read has nothing else to print.
	$missing = law_booking_profile_gaps( $user_id );
	if ( $missing ) {
		return new WP_Error(
			'law_reception_profile_incomplete',
			sprintf(
				/* translators: %s: a list of missing profile details. */
				__( 'Please add your %s to your profile before booking.', 'law' ),
				wp_sprintf_l( '%l', $missing )
			)
		);
	}

	$person = law_booking_person_from_profile( $user_id );

	// Fast-fail before the lock and before Stripe: a refused booking must
	// never leave a customer or half a hold behind.
	$dup = law_booking_guard_duplicates( $event_id, array( $person ), law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return $dup;
	}

	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', __( 'The reception is busy taking another booking. Please try again in a moment.', 'law' ) );
	}

	$unlock = function ( $error ) use ( $event_id ) {
		law_booking_unlock( $event_id );
		return $error;
	};

	law_event_recount_attendees( $event_id );

	// Everything re-checked under the lock: two tabs, two purchases.
	$open = law_reception_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $unlock( $open );
	}
	$capacity = law_booking_guard_capacity( $event_id, 1 );
	if ( is_wp_error( $capacity ) ) {
		return $unlock( $capacity );
	}
	$dup = law_booking_guard_duplicates( $event_id, array( $person ), law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return $unlock( $dup );
	}

	// The code is claimed BEFORE the insert. Its conditional UPDATE is what
	// makes two people redeeming the last use across two events safe, and
	// claiming first means a lost race refuses with nothing written.
	if ( $quote['discount_id'] && ! law_discount_claim( $quote['discount_id'] ) ) {
		return $unlock(
			new WP_Error(
				'law_discount_used_up',
				__( 'That discount code has already been used the maximum number of times.', 'law' ),
				array( 'field' => 'law_discount_code' )
			)
		);
	}

	$meta = array(
		'_law_price_pence'        => $quote['net'],
		// A free place has no VAT to add; anything else does.
		'_law_vat'                => $quote['net'] > 0 ? 1 : 0,
		'_law_payment_consent_at' => gmdate( 'Y-m-d H:i' ),
		'_law_payment_status'     => 'pending_setup',
	);
	if ( $quote['discount_id'] ) {
		$meta['_law_discount_id']    = $quote['discount_id'];
		$meta['_law_discount_code']  = $quote['code'];
		$meta['_law_discount_pence'] = $quote['discount'];
	}

	$booking_id = law_booking_insert( $event_id, $user_id, 'law-pending-payment', $person, $meta );
	if ( is_wp_error( $booking_id ) ) {
		if ( $quote['discount_id'] ) {
			law_discount_release( $quote['discount_id'] );
		}
		return $unlock( $booking_id );
	}
	$booking_id = (int) $booking_id;

	law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );

	// Everything slow happens after the lock: Stripe and the emails, so one
	// buyer is never queued behind another's network.
	if ( $quote['discount_id'] ) {
		law_discount_log( $quote['discount_id'], $booking_id, 'claimed' );
	}
	law_event_log(
		$event_id,
		sprintf(
			'Reception booking #%d started by %s: %s%s.',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			$person['name'],
			law_events_format_pence( $quote['gross'] ),
			$quote['discount'] > 0
				? sprintf( ' after %s off with code %s', law_events_format_pence( $quote['discount'] ), $quote['code'] )
				: ''
		),
		array(
			'source'   => 'receptions',
			'action'   => 'reception_checkout_started',
			'booking'  => $booking_id,
			'net'      => $quote['net'],
			'discount' => $quote['discount'],
			'gross'    => $quote['gross'],
			'code'     => $quote['code'],
		),
		array( 'user_id' => $user_id )
	);

	// A 100% code: there is nothing for Stripe to do, so the place is
	// confirmed here rather than sending somebody to a payment page for £0.00.
	if ( $quote['free'] ) {
		law_reception_mark_paid( $booking_id, array(), '', $user_id );

		return array(
			'booking'  => $booking_id,
			'redirect' => add_query_arg( 'law_notice', 'reception-free-confirmed', law_booking_manage_url( $booking_id ) ),
		);
	}

	$url = law_stripe_create_checkout_session( $booking_id );
	if ( is_wp_error( $url ) ) {
		// A failed attempt must never hold a place: give it back, with the
		// code, before telling the delegate what went wrong.
		law_reception_release_hold( $booking_id, 'stripe_error' );
		return $url;
	}

	return array( 'booking' => $booking_id, 'redirect' => $url );
}

/**
 * Open a fresh payment page for a hold whose session has run out, or hand back
 * the live one.
 *
 * The old session is expired through the API FIRST, so a late
 * checkout.session.expired for it can never release a hold the new session is
 * about to pay for.
 *
 * @return string|WP_Error The URL to send the delegate to.
 */
function law_reception_continue( $booking_id, $actor_id = 0 ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_reception_booking_is( $booking ) ) {
		return new WP_Error( 'law_reception_missing', __( 'That booking could not be found.', 'law' ) );
	}
	$booking_id = (int) $booking->ID;
	if ( 'law-pending-payment' !== $booking->post_status ) {
		return new WP_Error( 'law_reception_not_pending', __( 'That booking is not waiting for a payment.', 'law' ) );
	}
	if ( 'processing' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return new WP_Error( 'law_reception_payment_in_flight', __( 'Your payment is already on its way. We will email you as soon as it clears.', 'law' ) );
	}

	$expires = (string) law_event_meta( $booking_id, '_law_checkout_expires_at' );
	$session = (string) law_event_meta( $booking_id, '_law_stripe_checkout_session_id' );
	$live    = '' !== $expires && strtotime( $expires . ' UTC' ) > time();

	if ( $live && '' !== $session ) {
		$current = law_stripe_get_checkout_session( $session );
		if ( ! is_wp_error( $current ) && 'open' === (string) ( $current['status'] ?? '' ) && ! empty( $current['url'] ) ) {
			return (string) $current['url'];
		}
	}

	if ( '' !== $session ) {
		// Expire the old one before opening a new one. If Stripe answers that
		// it is already complete, the money has landed: confirm instead.
		$expired = law_stripe_expire_checkout_session( $session );
		if ( ! is_wp_error( $expired ) && 'complete' === (string) ( $expired['status'] ?? '' ) ) {
			law_reception_mark_paid( $booking_id, $expired, '', (int) $actor_id );
			return law_booking_manage_url( $booking_id );
		}
	}

	return law_stripe_create_checkout_session( $booking_id );
}

/* Outcomes: the single idempotent paths ______________________________________ */

/**
 * Fail closed on a booking that is not a reception.
 *
 * The webhook reaches these handlers for any booking whose Stripe metadata
 * names it, and they carry RECEPTION semantics — its statuses, its idea of
 * what "confirmed" means. Anything else would silently inherit them, so say so
 * in the log and change nothing.
 */
function law_reception_guard_handler( $booking, $what ) {
	$post = $booking instanceof WP_Post ? $booking : get_post( (int) $booking );
	if ( ! $post || LAW_BOOKING_CPT !== $post->post_type ) {
		return null;
	}
	if ( law_reception_booking_is( $post ) ) {
		return $post;
	}

	law_event_log(
		(int) $post->post_parent,
		sprintf(
			'A payment event (%s) reached the reception handler for booking #%d, which is not a reception place. Nothing was changed: that flow needs its own handler.',
			$what,
			(int) law_event_meta( $post->ID, '_law_booking_number' )
		),
		array( 'source' => 'stripe_webhook', 'action' => 'reception_handler_wrong_booking', 'booking' => (int) $post->ID )
	);

	return null;
}

/**
 * Is this Stripe object about the session the booking is actually waiting on?
 *
 * Every checkout.session.* handler asks, because a superseded session's late
 * `expired` event must never release a hold the delegate has just paid for
 * through its replacement (RECEPTIONS.md §3.2). An object that is not a
 * session at all (an invoice) passes: it is addressed by metadata, not by
 * session id.
 */
function law_reception_session_matches( $booking_id, array $object ) {
	if ( 'checkout.session' !== (string) ( $object['object'] ?? '' ) ) {
		return true;
	}
	$stored = (string) law_event_meta( (int) $booking_id, '_law_stripe_checkout_session_id' );
	$given  = (string) ( $object['id'] ?? '' );

	return '' === $stored || '' === $given || $stored === $given;
}

/**
 * Money has arrived (or was waived): confirm the place.
 *
 * THE single path to a confirmed reception booking, reached from the browser
 * returning from Checkout, from checkout.session.completed, from
 * checkout.session.async_payment_succeeded and from invoice.paid — in any
 * order, concurrently, and more than once. Idempotent throughout.
 *
 * @param int   $object A Checkout session or an invoice, as Stripe sent it.
 *                      Empty for a free place, which never went to Stripe.
 * @return bool Whether this call confirmed or updated the booking.
 */
function law_reception_mark_paid( $booking_id, array $object = array(), $stripe_event_id = '', $actor_id = 0 ) {
	$booking = law_reception_guard_handler( $booking_id, 'paid' );
	if ( ! $booking ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	if ( ! law_reception_session_matches( $booking_id, $object ) ) {
		return false;
	}

	// The invoice and charge references are written BEFORE the "already
	// confirmed" early return: the second caller to arrive is usually the one
	// carrying them, and the first has already published the place.
	$paid = law_reception_store_payment_result( $booking_id, $object );

	// Already CONFIRMED AND PAID; this caller only added what it knew. The
	// test is both, not the status alone: a waitlist entry is seated as
	// `publish` with `processing` while its charge runs, and returning here on
	// the status would leave it published and never marked paid — which is a
	// place given away with the money unrecorded.
	if ( 'publish' === $booking->post_status && 'paid' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		law_reception_maybe_send_confirmation( $booking_id );
		return true;
	}

	// Money landing on a place that was cancelled — an expired hold the
	// delegate paid for in the race, a committee cancellation — must NEVER
	// quietly un-cancel it. Say so loudly and leave the refund to a human.
	if ( in_array( $booking->post_status, array( 'law-cancelled', 'law-declined' ), true ) ) {
		law_event_log(
			$event_id,
			sprintf(
				'PAYMENT ON A CANCELLED BOOKING: reception place #%d was paid (%s) after it was cancelled. The place has NOT been given. Review in Stripe and refund.',
				(int) law_event_meta( $booking_id, '_law_booking_number' ),
				law_events_format_pence( $paid )
			),
			array(
				'source'  => $stripe_event_id ? 'stripe_webhook' : 'receptions',
				'action'  => 'reception_paid_after_cancel',
				'booking' => $booking_id,
			),
			array( 'user_id' => 0 )
		);
		law_events_send(
			'committee_reception_paid_cancelled',
			$event_id,
			array( 'placeholders' => law_booking_email_extra( $booking_id )['placeholders'] )
		);

		return false;
	}

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'publish' );
	law_event_update_meta( $booking_id, '_law_payment_status', 'paid' );
	law_event_update_meta( $booking_id, '_law_paid_at', gmdate( 'Y-m-d H:i' ) );
	delete_post_meta( $booking_id, '_law_checkout_expires_at' );
	delete_post_meta( $booking_id, '_law_payment_processing_at' );
	delete_post_meta( $booking_id, '_law_payment_error' );
	delete_post_meta( $booking_id, '_law_payment_failed_at' );
	delete_post_meta( $booking_id, '_law_waitlist_position' );
	delete_post_meta( $booking_id, '_law_waitlist_blocked' );
	law_event_recount_attendees( $event_id );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	$price = law_booking_price( $booking_id );
	law_event_log(
		$event_id,
		sprintf(
			'Reception place #%d paid and confirmed (%s).',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			law_events_format_pence( $price['gross'] )
		),
		array(
			'source'       => $stripe_event_id ? 'stripe_webhook' : 'receptions',
			'action'       => 'reception_paid',
			'booking'      => $booking_id,
			'amount'       => $price['gross'],
			'stripe_event' => $stripe_event_id,
		),
		array( 'user_id' => (int) $actor_id )
	);
	// A mismatch never blocks the place — the money genuinely arrived — but it
	// is the only signal that a price moved underneath a payment.
	law_booking_log_amount_mismatch( $booking_id, $paid, $price['gross'], $stripe_event_id ? 'stripe_webhook' : 'receptions' );

	law_booking_maybe_capacity_warning( $event_id );
	law_reception_maybe_send_confirmation( $booking_id );

	return true;
}

/**
 * Store what a Stripe object says about a payment, whichever kind it is.
 *
 * A SESSION carries amount_total and a payment_intent but no charge, so the
 * intent is fetched for its latest_charge; it may also carry the id of the
 * invoice Checkout generated, which is what the confirmation email waits for.
 * An INVOICE carries amount_paid and the links themselves.
 *
 * @return int What Stripe says was paid, in pence; 0 when it did not say.
 */
function law_reception_store_payment_result( $booking_id, array $object ) {
	$booking_id = (int) $booking_id;
	$kind       = (string) ( $object['object'] ?? '' );

	if ( 'invoice' === $kind ) {
		law_event_update_meta( $booking_id, '_law_stripe_invoice_id', (string) ( $object['id'] ?? '' ) );
		law_event_update_meta( $booking_id, '_law_stripe_invoice_url', (string) ( $object['hosted_invoice_url'] ?? '' ) );
		law_event_update_meta( $booking_id, '_law_stripe_invoice_pdf', (string) ( $object['invoice_pdf'] ?? '' ) );
		law_flagship_store_charge_id( $booking_id, (string) ( $object['id'] ?? '' ) );

		return (int) ( $object['amount_paid'] ?? 0 );
	}

	if ( 'checkout.session' !== $kind ) {
		return 0;
	}

	// The charge, for a later charge.refunded to resolve against. The intent
	// may already be expanded (the return fetch asks for it); otherwise it is
	// an id and one GET away.
	if ( '' === (string) law_event_meta( $booking_id, '_law_stripe_charge_id' ) ) {
		$intent = $object['payment_intent'] ?? null;
		if ( is_string( $intent ) && '' !== $intent ) {
			$fetched = law_stripe_request( 'GET', '/v1/payment_intents/' . rawurlencode( $intent ), array() );
			$intent  = is_wp_error( $fetched ) ? null : $fetched;
		}
		if ( is_array( $intent ) && ! empty( $intent['latest_charge'] ) ) {
			law_event_update_meta( $booking_id, '_law_stripe_charge_id', (string) $intent['latest_charge'] );
		}
	}

	// The invoice Checkout generated. Fetched rather than waited for, so the
	// confirmation can carry the VAT invoice even on an account where
	// invoice.paid never arrives (RECEPTIONS.md §3.1, item 2).
	$invoice_id = '';
	if ( ! empty( $object['invoice'] ) ) {
		$invoice_id = is_array( $object['invoice'] ) ? (string) ( $object['invoice']['id'] ?? '' ) : (string) $object['invoice'];
	}
	if ( '' !== $invoice_id && '' === (string) law_event_meta( $booking_id, '_law_stripe_invoice_url' ) ) {
		$invoice = is_array( $object['invoice'] ?? null )
			? (array) $object['invoice']
			: law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $invoice_id ), array() );
		if ( ! is_wp_error( $invoice ) ) {
			law_event_update_meta( $booking_id, '_law_stripe_invoice_id', (string) ( $invoice['id'] ?? $invoice_id ) );
			law_event_update_meta( $booking_id, '_law_stripe_invoice_url', (string) ( $invoice['hosted_invoice_url'] ?? '' ) );
			law_event_update_meta( $booking_id, '_law_stripe_invoice_pdf', (string) ( $invoice['invoice_pdf'] ?? '' ) );
		}
	}

	return (int) ( $object['amount_total'] ?? 0 );
}

/**
 * Send the confirmation, once, when there is an invoice link to put in it.
 *
 * The delegate's copy carries the calendar invitation and the VAT invoice, and
 * an invoice arriving a second after the place is confirmed is the normal
 * case — so the send waits for it rather than going out with a blank where the
 * receipt should be. Whichever of the session and the invoice arrives SECOND
 * therefore sends; if the invoice never comes, the hourly sweep sends without
 * a link fifteen minutes later (law_reception_sweep()).
 *
 * One-shot, claimed rather than read and written, because three requests reach
 * here within milliseconds of each other.
 *
 * @param bool $force Send even with no invoice link: the sweep's last word.
 * @return bool Whether this call sent the emails.
 */
function law_reception_maybe_send_confirmation( $booking_id, $force = false ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || 'publish' !== $booking->post_status ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	if ( 'paid' !== (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return false;
	}
	if ( ! $force && '' === (string) law_event_meta( $booking_id, '_law_stripe_invoice_url' ) ) {
		return false;
	}
	if ( ! law_booking_claim_latch( $booking_id, '_law_confirmation_sent' ) ) {
		return false;
	}

	$extra = law_booking_email_extra( $booking_id );
	law_booking_send_with_ics( 'user_reception_confirmed', $event_id, $extra );
	law_events_send( 'committee_reception_booking', $event_id, array( 'placeholders' => $extra['placeholders'] ) );

	return true;
}

/**
 * An asynchronous payment method was accepted but has not settled.
 *
 * The place STAYS held — the money is genuinely on its way — and the checkout
 * expiry is cleared, so the sweep can never release a hold whose payment is in
 * flight. A Bacs-style debit settles days later, which is exactly the case
 * this exists for.
 */
function law_reception_mark_processing( $booking_id, $stripe_event_id = '' ) {
	$booking = law_reception_guard_handler( $booking_id, 'processing' );
	if ( ! $booking ) {
		return false;
	}
	$booking_id = (int) $booking->ID;

	// A confirmed, paid place is never dragged back by a late report.
	if ( 'publish' === $booking->post_status && 'paid' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return false;
	}

	law_event_update_meta( $booking_id, '_law_payment_status', 'processing' );
	if ( '' === (string) law_event_meta( $booking_id, '_law_payment_processing_at' ) ) {
		law_event_update_meta( $booking_id, '_law_payment_processing_at', gmdate( 'Y-m-d H:i' ) );
	}
	delete_post_meta( $booking_id, '_law_checkout_expires_at' );
	delete_post_meta( $booking_id, '_law_payment_error' );

	law_event_log(
		(int) $booking->post_parent,
		sprintf(
			'Reception place #%d: the payment has been accepted but has not settled yet, so the place is held until it does.',
			(int) law_event_meta( $booking_id, '_law_booking_number' )
		),
		array(
			'source'       => $stripe_event_id ? 'stripe_webhook' : 'receptions',
			'action'       => 'reception_payment_processing',
			'booking'      => $booking_id,
			'stripe_event' => $stripe_event_id,
		),
		array( 'user_id' => 0 )
	);

	return true;
}

/**
 * A payment failed: an asynchronous method that bounced, or a promotion charge
 * the bank refused.
 *
 * A HOLD keeps its place until the session expires, so the delegate can retry
 * inside Stripe's page. A promoted waitlist entry does not: its place goes to
 * the next person, and it is moved to law-payment-failed with a window to fix
 * the method (RECEPTIONS.md §6.3).
 *
 * @param string $message Stripe's own wording, shown to the delegate verbatim.
 * @param string $status  'failed' or 'action_required'.
 */
function law_reception_mark_payment_failed( $booking_id, $message, $status = 'failed', $stripe_event_id = '' ) {
	$booking = law_reception_guard_handler( $booking_id, 'payment_failed' );
	if ( ! $booking ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;
	$message    = sanitize_text_field( (string) $message );
	$status     = 'action_required' === $status ? 'action_required' : 'failed';

	// A confirmed, paid place is never dragged back by a late report.
	if ( 'publish' === $booking->post_status && 'paid' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return false;
	}

	$was_seated = 'publish' === $booking->post_status;

	law_event_update_meta( $booking_id, '_law_payment_status', $status );
	law_event_update_meta( $booking_id, '_law_payment_error', $message );
	delete_post_meta( $booking_id, '_law_payment_processing_at' );

	// The window starts once, on the first failure: a second decline inside it
	// must not give the delegate another seven days.
	$first = '' === (string) law_event_meta( $booking_id, '_law_payment_failed_at' );
	if ( $first ) {
		law_event_update_meta( $booking_id, '_law_payment_failed_at', gmdate( 'Y-m-d H:i' ) );
	}

	if ( $was_seated ) {
		// A promoted entry whose charge was refused: the place goes back, so
		// the next person on the waitlist can have it.
		$locked = law_booking_lock( $event_id );
		law_booking_set_status( $booking_id, 'law-payment-failed' );
		law_event_recount_attendees( $event_id );
		if ( $locked ) {
			law_booking_unlock( $event_id );
		}
	}

	law_event_log(
		$event_id,
		sprintf(
			'Reception place #%d: the payment could not be taken (%s).%s',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			$message ?: 'no reason given',
			$was_seated ? ' The place has been released for the next person on the waitlist.' : ''
		),
		array(
			'source'       => $stripe_event_id ? 'stripe_webhook' : 'receptions',
			'action'       => 'reception_payment_failed',
			'booking'      => $booking_id,
			'stripe_event' => $stripe_event_id,
		),
		array( 'user_id' => 0 )
	);

	// Told once. A retry that fails again updates the reason on the manage
	// view rather than sending a second identical email.
	if ( $first ) {
		$extra = law_booking_email_extra( $booking_id );
		law_events_send( 'user_reception_payment_failed', $event_id, $extra );
		if ( $was_seated ) {
			law_events_send( 'committee_reception_payment_failed', $event_id, array( 'placeholders' => $extra['placeholders'] ) );
		}
	}

	return true;
}

/**
 * Give a held place back: the delegate left Stripe's page, the session expired,
 * or opening it failed.
 *
 * Stripe is asked to expire the session FIRST, so a page somebody still has
 * open cannot be paid for a place that no longer exists. If Stripe answers
 * that the session is already complete, the money has landed in the race and
 * this confirms instead of cancelling.
 *
 * No email: they left the page, and "your incomplete booking has been
 * cancelled" is noise for somebody who already knows.
 *
 * @param string $why expired | cancelled | stripe_error | sweep.
 * @return bool Whether the hold was released.
 */
function law_reception_release_hold( $booking_id, $why = 'expired' ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_reception_booking_is( $booking ) ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	if ( 'law-pending-payment' !== $booking->post_status ) {
		return false;
	}
	// A payment in flight is not a hold to release, however old the session is.
	if ( 'processing' === (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
		return false;
	}

	$session = (string) law_event_meta( $booking_id, '_law_stripe_checkout_session_id' );
	if ( '' !== $session && 'stripe_error' !== $why ) {
		$expired = law_stripe_expire_checkout_session( $session );
		if ( ! is_wp_error( $expired ) ) {
			$status = (string) ( $expired['status'] ?? '' );
			if ( 'complete' === $status ) {
				// The delegate paid while this was deciding to release. Confirm.
				law_reception_mark_paid( $booking_id, $expired );
				return false;
			}
		}
	}

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'law-cancelled' );
	law_event_update_meta( $booking_id, '_law_payment_status', 'pending_setup' );
	delete_post_meta( $booking_id, '_law_checkout_expires_at' );

	// The code's use goes back, once: the key is deleted, so a double release
	// (the webhook's expiry and the sweep) is a no-op rather than a theft of
	// somebody else's live claim.
	$discount_id = (int) law_event_meta( $booking_id, '_law_discount_id' );
	if ( $discount_id ) {
		law_discount_release( $discount_id, $booking_id );
		delete_post_meta( $booking_id, '_law_discount_id' );
	}

	law_event_recount_attendees( $event_id );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	law_event_log(
		$event_id,
		sprintf(
			'Reception place #%d released: %s.',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			array(
				'expired'      => 'the payment was not completed in time',
				'cancelled'    => 'the delegate cancelled on the payment page',
				'stripe_error' => 'the payment page could not be opened',
				'sweep'        => 'the payment was not completed in time',
			)[ $why ] ?? $why
		),
		array( 'source' => 'receptions', 'action' => 'reception_hold_released', 'booking' => $booking_id, 'reason' => $why ),
		array( 'user_id' => 0 )
	);

	// A place just opened: offer it to whoever is waiting.
	law_waitlist_process( $event_id, 'reception_hold_released' );

	return true;
}

/* Included with the flagship place ___________________________________________ */

/**
 * The viewer's CONFIRMED flagship place, or null.
 *
 * "Confirmed" is the whole point: an application still under review, or one
 * whose payment failed, includes nothing. Payment `paid` or `complimentary`,
 * because a committee comp place is a real ticket and carries the same
 * benefits as a bought one.
 *
 * @return WP_Post|null
 */
function law_reception_confirmed_flagship( $user_id ) {
	if ( ! function_exists( 'law_flagship_application_for_user' ) ) {
		return null;
	}
	$booking = law_flagship_application_for_user( (int) $user_id );
	if ( ! $booking || 'publish' !== $booking->post_status ) {
		return null;
	}
	$payment = (string) law_event_meta( $booking->ID, '_law_payment_status' );

	return in_array( $payment, array( 'paid', 'complimentary' ), true ) ? $booking : null;
}

/**
 * Does this person already hold a live place at this reception?
 *
 * A hold counts: somebody standing on Stripe's page has the place, and
 * granting them a free one as well would leave two bookings and one person.
 */
function law_reception_holds_place( $user_id, $event_id ) {
	$existing = law_booking_user_booking_for_event( (int) $user_id, (int) $event_id, law_booking_holding_statuses() );

	return $existing instanceof WP_Post;
}

/**
 * Grant one free reception place from a confirmed flagship ticket.
 *
 * NO CAPACITY GUARD, deliberately. The flagship ticket promised the reception;
 * the committee sizes the room. An over-booking is logged loudly and the
 * committee is emailed, exactly as law_flagship_approve() over-books the
 * conference itself (RECEPTIONS.md §0.3).
 *
 * @param int    $reception_id        The reception.
 * @param int    $user_id             The delegate.
 * @param int    $flagship_booking_id Their confirmed flagship booking.
 * @param int    $actor_id            Who acted (0 for the engine).
 * @param string $source              For the log: flagship_confirm, banner, dialog…
 * @return int|WP_Error The booking ID, or 0 when they already had a place.
 */
function law_reception_grant_included( $reception_id, $user_id, $flagship_booking_id, $actor_id = 0, $source = 'receptions' ) {
	$reception_id = (int) $reception_id;
	$user_id      = (int) $user_id;

	if ( ! in_array( $reception_id, law_reception_included_ids(), true ) ) {
		return new WP_Error( 'law_reception_not_included', __( 'That reception is not included with a flagship place.', 'law' ) );
	}
	$flagship = get_post( (int) $flagship_booking_id );
	if ( ! $flagship || (int) $flagship->post_author !== $user_id || 'flagship' !== law_booking_kind( $flagship ) ) {
		return new WP_Error( 'law_reception_no_flagship', __( 'Receptions are included only once your flagship place is confirmed.', 'law' ) );
	}
	if ( 'publish' !== $flagship->post_status
		|| ! in_array( (string) law_event_meta( $flagship->ID, '_law_payment_status' ), array( 'paid', 'complimentary' ), true ) ) {
		return new WP_Error( 'law_reception_no_flagship', __( 'Receptions are included only once your flagship place is confirmed.', 'law' ) );
	}

	// Already there: reported, never refused. A delegate who bought Monday and
	// is then approved for the conference keeps the place they paid for.
	if ( law_reception_holds_place( $user_id, $reception_id ) ) {
		return 0;
	}

	$person = law_booking_person_from_profile( $user_id );

	$locked     = law_booking_lock( $reception_id );
	$booking_id = law_booking_insert(
		$reception_id,
		$user_id,
		'publish',
		$person,
		array(
			'_law_price_pence'    => 0,
			'_law_vat'            => 0,
			'_law_payment_status' => 'included',
			'_law_included_with'  => (int) $flagship->ID,
		)
	);
	if ( is_wp_error( $booking_id ) ) {
		if ( $locked ) {
			law_booking_unlock( $reception_id );
		}
		return $booking_id;
	}
	$booking_id = (int) $booking_id;

	$sold      = law_event_recount_attendees( $reception_id );
	$available = (int) law_event_meta( $reception_id, '_law_tickets_available' );
	if ( $locked ) {
		law_booking_unlock( $reception_id );
	}

	law_event_log(
		$reception_id,
		sprintf(
			'Reception place #%d included free with flagship booking #%d for %s.',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			(int) law_event_meta( $flagship->ID, '_law_booking_number' ),
			$person['name']
		),
		array(
			'source'   => $source,
			'action'   => 'reception_included',
			'booking'  => $booking_id,
			'flagship' => (int) $flagship->ID,
		),
		array( 'user_id' => (int) $actor_id )
	);

	if ( $available > 0 && $sold > $available ) {
		law_event_log(
			$reception_id,
			sprintf(
				'This reception is now OVER-BOOKED: %d places taken of %d available. An included place was granted because a confirmed flagship ticket promises one.',
				$sold,
				$available
			),
			array( 'source' => $source, 'action' => 'reception_overbooked', 'booking' => $booking_id, 'sold' => $sold, 'available' => $available ),
			array( 'user_id' => (int) $actor_id )
		);
		law_events_send(
			'committee_reception_overbooked',
			$reception_id,
			array( 'placeholders' => law_booking_email_extra( $booking_id )['placeholders'] )
		);
	}

	law_booking_send_with_ics( 'user_reception_included', $reception_id, law_booking_email_extra( $booking_id ) );

	return $booking_id;
}

/**
 * Grant several at once, and say which were already held.
 *
 * One lock per reception, never nested: law_reception_grant_included() takes
 * the event lock itself, and GET_LOCK does not nest.
 *
 * @return array{granted:array<int,string>,skipped:array<int,string>,failed:array<int,string>}
 */
function law_reception_grant_choices( $flagship_booking_id, array $reception_ids, $actor_id = 0, $source = 'receptions' ) {
	$result   = array( 'granted' => array(), 'skipped' => array(), 'failed' => array() );
	$flagship = get_post( (int) $flagship_booking_id );
	if ( ! $flagship ) {
		return $result;
	}
	$user_id = (int) $flagship->post_author;

	foreach ( array_unique( array_map( 'absint', $reception_ids ) ) as $reception_id ) {
		if ( ! $reception_id ) {
			continue;
		}
		$granted = law_reception_grant_included( $reception_id, $user_id, (int) $flagship->ID, $actor_id, $source );
		$title   = (string) get_the_title( $reception_id );

		if ( is_wp_error( $granted ) ) {
			$result['failed'][ $reception_id ] = $title;
			continue;
		}
		if ( 0 === $granted ) {
			$result['skipped'][ $reception_id ] = $title;
			continue;
		}
		$result['granted'][ $reception_id ] = $title;
	}

	return $result;
}

/**
 * The sentence the flagship's approval email and the notices use: what was
 * added, and what the delegate already had.
 *
 * @param array $result law_reception_grant_choices().
 */
function law_reception_choices_note( array $result ) {
	$lines = array();
	if ( ! empty( $result['granted'] ) ) {
		$lines[] = sprintf(
			/* translators: %s: a list of reception names. */
			_n( '%s has been added to your bookings, at no cost.', '%s have been added to your bookings, at no cost.', count( $result['granted'] ), 'law' ),
			wp_sprintf_l( '%l', array_values( $result['granted'] ) )
		);
	}
	if ( ! empty( $result['skipped'] ) ) {
		$lines[] = sprintf(
			/* translators: %s: a list of reception names. */
			_n( 'You already had a place at %s.', 'You already had places at %s.', count( $result['skipped'] ), 'law' ),
			wp_sprintf_l( '%l', array_values( $result['skipped'] ) )
		);
	}

	return implode( ' ', $lines );
}

/**
 * Take back every reception place granted from one flagship booking.
 *
 * Only reachable through a committee refund, since a paid flagship place
 * cannot be withdrawn. The delegate is told, because a place disappearing from
 * their bookings with no explanation is worse than the news.
 *
 * @return int How many were revoked.
 */
function law_reception_revoke_included( $flagship_booking_id, $actor_id = 0 ) {
	$flagship_booking_id = (int) $flagship_booking_id;
	if ( $flagship_booking_id < 1 ) {
		return 0;
	}

	$bookings = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => law_booking_holding_statuses(),
			'meta_key'       => '_law_included_with',
			'meta_value'     => $flagship_booking_id,
			'posts_per_page' => 50,
			'no_found_rows'  => true,
		)
	);

	$revoked = 0;
	foreach ( $bookings as $booking ) {
		$event_id = (int) $booking->post_parent;
		$extra    = law_booking_email_extra( (int) $booking->ID );

		$cancelled = law_booking_cancel( (int) $booking->ID, (int) $actor_id, 'included_revoked' );
		if ( is_wp_error( $cancelled ) ) {
			continue;
		}
		$revoked++;

		law_event_log(
			$event_id,
			sprintf(
				'Included reception place #%d withdrawn: the flagship place it came with is no longer confirmed.',
				(int) law_event_meta( $booking->ID, '_law_booking_number' )
			),
			array(
				'source'   => 'receptions',
				'action'   => 'reception_included_revoked',
				'booking'  => (int) $booking->ID,
				'flagship' => $flagship_booking_id,
			),
			array( 'user_id' => (int) $actor_id )
		);
		law_events_send( 'user_reception_included_revoked', $event_id, $extra );
	}

	return $revoked;
}

/* Waitlist card outcomes and refunds _________________________________________ */

/**
 * A payment method has landed on a reception waitlist entry: the queue place
 * is now real, so the delegate hears, and the host hears the first time a
 * queue forms.
 *
 * Idempotent, because the return URL and two webhook events all reach it.
 */
function law_reception_on_card_saved( $booking_id ) {
	$booking = law_reception_guard_handler( $booking_id, 'card_saved' );
	if ( ! $booking ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	if ( 'law-waitlisted' !== $booking->post_status ) {
		return false;
	}

	// `ready` is what law_waitlist_check_promotable() looks for: until a
	// method is saved the entry is skipped in place rather than promoted.
	law_event_update_meta( $booking_id, '_law_payment_status', 'ready' );
	delete_post_meta( $booking_id, '_law_waitlist_blocked' );

	if ( ! law_booking_claim_latch( $booking_id, '_law_waitlist_ready' ) ) {
		return false;
	}

	law_event_log(
		$event_id,
		sprintf(
			'Waitlist entry #%d is ready: a payment method is saved, so a place that opens up can be charged and confirmed automatically.',
			(int) law_event_meta( $booking_id, '_law_booking_number' )
		),
		array( 'source' => 'receptions', 'action' => 'reception_waitlist_ready', 'booking' => $booking_id ),
		array( 'user_id' => 0 )
	);

	law_events_send( 'user_reception_waitlist_joined', $event_id, law_booking_email_extra( $booking_id ) );

	// The first time a queue forms on this reception, the committee hears:
	// their cue to consider releasing more places.
	if ( 1 === law_waitlist_count( $event_id ) ) {
		law_events_send(
			'host_waitlist_activated',
			$event_id,
			array( 'placeholders' => array_merge( law_booking_email_placeholders( $booking_id ), array( 'waitlist_count' => '1' ) ) )
		);
	}

	return true;
}

/** The payment method could not be saved; say so rather than leaving it silent. */
function law_reception_on_card_setup_failed( $booking_id, $message = '' ) {
	$booking = law_reception_guard_handler( $booking_id, 'setup_failed' );
	if ( ! $booking ) {
		return false;
	}

	law_event_log(
		(int) $booking->post_parent,
		sprintf(
			'Payment details could not be saved for waitlist entry #%d: %s',
			(int) law_event_meta( $booking->ID, '_law_booking_number' ),
			sanitize_text_field( (string) $message ) ?: 'no reason given'
		),
		array( 'source' => 'receptions', 'action' => 'reception_setup_failed', 'booking' => (int) $booking->ID ),
		array( 'user_id' => 0 )
	);

	return true;
}

/**
 * Money has gone back.
 *
 * The place is NOT cancelled automatically, and deliberately so: a refund is a
 * conversation somebody has already had, and the committee decides whether the
 * place goes with it. Recorded and alerted, as the host-fee path does.
 */
function law_reception_mark_refunded( $booking_id, $refunded, $charged, $partial ) {
	$booking = law_reception_guard_handler( $booking_id, 'refunded' );
	if ( ! $booking ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	if ( ! $partial ) {
		law_event_update_meta( $booking_id, '_law_payment_status', 'refunded' );
	}

	law_event_log(
		$event_id,
		sprintf(
			$partial
				? 'PARTIAL REFUND on reception place #%1$d: %2$s of %3$s refunded. The place is unchanged; review in Stripe.'
				: 'Reception place #%1$d refunded in full (%2$s of %3$s). The place is NOT cancelled automatically.',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			law_events_format_pence( (int) $refunded ),
			law_events_format_pence( (int) $charged )
		),
		array( 'source' => 'stripe_webhook', 'action' => $partial ? 'partial_refund' : 'refunded', 'booking' => $booking_id ),
		array( 'user_id' => 0 )
	);
	law_events_send( 'committee_refund', $event_id );

	return true;
}

/**
 * How a reception place answers each payment outcome
 * (law_booking_payment_handlers(), bookings.php).
 *
 * Registered through the filter rather than named in stripe/webhook.php, so
 * the webhook stays a router and this file stays the only place that knows
 * what "paid" means for a reception.
 */
add_filter(
	'law_booking_payment_handlers',
	function ( array $table ) {
		$table['reception'] = array(
			'card_saved'      => function ( $booking_id ) {
				return law_reception_on_card_saved( $booking_id );
			},
			'setup_failed'    => function ( $booking_id, array $object ) {
				return law_reception_on_card_setup_failed(
					$booking_id,
					(string) ( $object['last_setup_error']['message'] ?? 'The payment details could not be saved.' )
				);
			},
			'paid'            => function ( $booking_id, array $object, $stripe_event_id ) {
				return law_reception_mark_paid( $booking_id, $object, $stripe_event_id );
			},
			'processing'      => function ( $booking_id, array $object, $stripe_event_id ) {
				if ( ! law_reception_session_matches( $booking_id, $object ) ) {
					return false;
				}
				return law_reception_mark_processing( $booking_id, $stripe_event_id );
			},
			'payment_failed'  => function ( $booking_id, array $object, $stripe_event_id ) {
				if ( ! law_reception_session_matches( $booking_id, $object ) ) {
					return false;
				}
				return law_reception_mark_payment_failed(
					$booking_id,
					(string) (
						$object['last_payment_error']['message']
						?? $object['last_finalization_error']['message']
						?? 'The payment was declined.'
					),
					'failed',
					$stripe_event_id
				);
			},
			'action_required' => function ( $booking_id, array $object, $stripe_event_id ) {
				return law_reception_mark_payment_failed(
					$booking_id,
					'Your bank needs you to confirm this payment.',
					'action_required',
					$stripe_event_id
				);
			},
			'refunded'        => function ( $booking_id, array $object ) {
				$refunded = (int) ( $object['amount_refunded'] ?? 0 );
				$charged  = (int) ( $object['amount'] ?? 0 );

				return law_reception_mark_refunded(
					$booking_id,
					$refunded,
					$charged,
					$charged > 0 && $refunded > 0 && $refunded < $charged
				);
			},
			'session_expired' => function ( $booking_id, array $object ) {
				// Only the session the booking is actually waiting on: a
				// superseded one expiring must never release a live hold.
				if ( ! law_reception_session_matches( $booking_id, $object ) ) {
					return false;
				}
				return law_reception_release_hold( $booking_id, 'expired' );
			},
		);

		return $table;
	}
);

/* The paid waitlist __________________________________________________________ */

/**
 * Join a full reception's waitlist, saving a payment method on the way.
 *
 * The queue promotes automatically the moment a place frees, so the charge has
 * to be able to run with nobody at the keyboard — which is why joining means
 * saving a method, and why the consent tick says exactly what will be charged
 * and when. The price is snapshotted at JOIN, so the delegate is charged the
 * figure they agreed to even if the list price has moved since.
 *
 * @param int   $user_id The delegate.
 * @param array $input   event_id, code, applied_code, consent, terms, price_shown.
 * @return array{booking:int,redirect:string}|WP_Error
 */
function law_reception_waitlist_join( $user_id, array $input ) {
	$user_id  = (int) $user_id;
	$user     = $user_id ? get_user_by( 'id', $user_id ) : null;
	$event_id = absint( $input['event_id'] ?? 0 );

	if ( ! $user ) {
		return new WP_Error( 'law_reception_no_user', __( 'You need to be signed in to join the waitlist.', 'law' ) );
	}
	$open = law_reception_guard_open( $event_id );
	if ( is_wp_error( $open ) ) {
		return $open;
	}
	if ( ! law_event_is_priced( $event_id ) ) {
		return new WP_Error( 'law_reception_not_on_sale', __( 'Places at this reception are not on sale.', 'law' ) );
	}
	// You only join a waitlist when there is nothing left to buy.
	$seats = law_booking_guard_seats( $event_id, 1, 'law-waitlisted' );
	if ( is_wp_error( $seats ) ) {
		return $seats;
	}
	if ( empty( $input['consent'] ) ) {
		return new WP_Error(
			'law_reception_no_consent',
			__( 'Please confirm you agree to your payment details being saved and charged if a place opens up.', 'law' ),
			array( 'field' => 'law_consent' )
		);
	}
	if ( empty( $input['terms'] ) ) {
		return new WP_Error(
			'law_reception_no_terms',
			__( 'Please accept the registration terms and conditions.', 'law' ),
			array( 'field' => 'law_terms' )
		);
	}

	$code    = trim( (string) ( $input['code'] ?? '' ) );
	$applied = trim( (string) ( $input['applied_code'] ?? '' ) );
	if ( law_discount_normalise_code( $code ) !== law_discount_normalise_code( $applied ) && ! empty( $input['ajax'] ) ) {
		return new WP_Error(
			'law_reception_code_unapplied',
			__( 'Press Apply to check your code before continuing.', 'law' ),
			array( 'field' => 'law_discount_code' )
		);
	}

	$quote = law_reception_quote( $event_id, $code, $user_id );
	if ( is_wp_error( $quote ) ) {
		return $quote;
	}
	$shown = law_booking_guard_price_shown(
		(int) ( $input['price_shown'] ?? 0 ),
		$quote['gross'],
		'law_reception_price_changed'
	);
	if ( is_wp_error( $shown ) ) {
		return $shown;
	}

	$missing = law_booking_profile_gaps( $user_id );
	if ( $missing ) {
		return new WP_Error(
			'law_reception_profile_incomplete',
			sprintf(
				/* translators: %s: a list of missing profile details. */
				__( 'Please add your %s to your profile before joining the waitlist.', 'law' ),
				wp_sprintf_l( '%l', $missing )
			)
		);
	}

	$person = law_booking_person_from_profile( $user_id );
	$dup    = law_booking_guard_duplicates( $event_id, array( $person ), law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return $dup;
	}

	if ( ! law_booking_lock( $event_id ) ) {
		return new WP_Error( 'law_booking_busy', __( 'The reception is busy with another change. Please try again in a moment.', 'law' ) );
	}
	$unlock = function ( $error ) use ( $event_id ) {
		law_booking_unlock( $event_id );
		return $error;
	};

	law_event_recount_attendees( $event_id );
	$seats = law_booking_guard_seats( $event_id, 1, 'law-waitlisted' );
	if ( is_wp_error( $seats ) ) {
		return $unlock( $seats );
	}
	$dup = law_booking_guard_duplicates( $event_id, array( $person ), law_booking_holding_statuses() );
	if ( is_wp_error( $dup ) ) {
		return $unlock( $dup );
	}
	if ( $quote['discount_id'] && ! law_discount_claim( $quote['discount_id'] ) ) {
		return $unlock(
			new WP_Error(
				'law_discount_used_up',
				__( 'That discount code has already been used the maximum number of times.', 'law' ),
				array( 'field' => 'law_discount_code' )
			)
		);
	}

	$meta = array(
		'_law_price_pence'        => $quote['net'],
		'_law_vat'                => $quote['net'] > 0 ? 1 : 0,
		'_law_payment_consent_at' => gmdate( 'Y-m-d H:i' ),
		'_law_payment_status'     => 'pending_setup',
		'_law_waitlist_position'  => law_waitlist_next_position( $event_id ),
		'_law_waitlist_joined'    => current_time( 'mysql' ),
	);
	if ( $quote['discount_id'] ) {
		$meta['_law_discount_id']    = $quote['discount_id'];
		$meta['_law_discount_code']  = $quote['code'];
		$meta['_law_discount_pence'] = $quote['discount'];
	}

	$booking_id = law_booking_insert( $event_id, $user_id, 'law-waitlisted', $person, $meta );
	if ( is_wp_error( $booking_id ) ) {
		if ( $quote['discount_id'] ) {
			law_discount_release( $quote['discount_id'] );
		}
		return $unlock( $booking_id );
	}
	$booking_id = (int) $booking_id;

	law_event_recount_attendees( $event_id );
	law_booking_unlock( $event_id );

	if ( $quote['discount_id'] ) {
		law_discount_log( $quote['discount_id'], $booking_id, 'claimed' );
	}
	law_event_log(
		$event_id,
		sprintf(
			'Waitlist entry #%d added at position %d: %s. %s will be charged automatically if a place opens up.',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			(int) law_event_meta( $booking_id, '_law_waitlist_position' ),
			$person['name'],
			law_events_format_pence( $quote['gross'] )
		),
		array(
			'source'   => 'receptions',
			'action'   => 'reception_waitlist_joined',
			'booking'  => $booking_id,
			'position' => (int) law_event_meta( $booking_id, '_law_waitlist_position' ),
			'gross'    => $quote['gross'],
			'code'     => $quote['code'],
		),
		array( 'user_id' => $user_id )
	);

	// The emails wait for the payment method: an entry with nothing saved is
	// not yet a place in the queue that can be honoured
	// (law_reception_on_card_saved()).
	$url = law_stripe_create_setup_session( $booking_id, 'waitlist' );
	if ( is_wp_error( $url ) ) {
		// The entry survives: the delegate can add their details from My
		// bookings rather than typing everything again.
		return array( 'booking' => $booking_id, 'redirect' => law_booking_manage_url( $booking_id ) );
	}

	return array( 'booking' => $booking_id, 'redirect' => $url );
}

/**
 * Charge the entries a promotion pass has just seated.
 *
 * Runs on law_waitlist_seated_after_unlock, so the event lock is released and
 * a card taking ten seconds cannot queue the next booker behind it. Each entry
 * was seated as `processing` with its charge claim already held, so this loop
 * is the only thing that may bill it, and the claim is released in a `finally`
 * whatever happens.
 *
 * It NEVER re-enters law_waitlist_process(): a queue of declining cards would
 * nest without bound in one request. A freed place schedules a resume instead.
 */
function law_reception_charge_promoted( $event_id, array $promoted, $source = 'auto' ) {
	if ( ! $promoted || ! law_event_is_priced( $event_id ) || ! law_reception_is( $event_id ) ) {
		return;
	}
	$resume = false;

	foreach ( $promoted as $booking_id ) {
		$booking_id = (int) $booking_id;
		$booking    = get_post( $booking_id );
		if ( ! $booking || 'publish' !== $booking->post_status ) {
			continue;
		}
		if ( 'processing' !== (string) law_event_meta( $booking_id, '_law_payment_status' ) ) {
			continue;
		}
		// The claim was taken under the lock when the place was held. Without
		// it, somebody else is already charging this booking.
		if ( ! law_booking_charge_claimed_at( $booking_id ) ) {
			continue;
		}

		try {
			$invoice = law_stripe_charge_booking( $booking_id );

			if ( is_wp_error( $invoice ) ) {
				// OUR mistake, not the delegate's: stop quietly, alert an
				// admin, and put the entry back at the FRONT of the queue
				// rather than telling somebody their card was declined.
				if ( law_booking_is_configuration_error( $invoice ) ) {
					law_reception_requeue_at_front( $booking_id, $invoice->get_error_message() );
					continue;
				}
				law_reception_mark_payment_failed( $booking_id, $invoice->get_error_message() );
				$resume = true;
				continue;
			}

			$status = law_stripe_invoice_intent_status( $invoice );
			if ( 'paid' === (string) ( $invoice['status'] ?? '' ) ) {
				law_reception_mark_paid( $booking_id, $invoice, '', 0 );
				law_booking_send_with_ics( 'user_reception_promoted_paid', (int) $event_id, law_booking_email_extra( $booking_id ) );
				continue;
			}
			if ( 'processing' === $status ) {
				// Accepted but not settled: the place stays held and
				// invoice.paid finishes the job.
				law_reception_mark_processing( $booking_id );
				continue;
			}

			law_reception_mark_payment_failed(
				$booking_id,
				(string) ( $invoice['last_finalization_error']['message'] ?? __( 'The payment was declined.', 'law' ) )
			);
			$resume = true;
		} finally {
			law_booking_release_charge( $booking_id );
		}
	}

	// One summary to the committee for the pass, as the free queue sends.
	$confirmed = array_values(
		array_filter(
			array_map( 'intval', $promoted ),
			fn( $id ) => 'paid' === (string) law_event_meta( $id, '_law_payment_status' )
		)
	);
	if ( $confirmed ) {
		law_waitlist_notify_host( (int) $event_id, $confirmed );
	}

	// A declined card frees the place again. Scheduled, never recursive: a
	// queue of dead cards would otherwise nest law_waitlist_process() inside
	// itself once per entry.
	if ( $resume ) {
		law_waitlist_schedule_resume( (int) $event_id );
	}
}
add_action( 'law_waitlist_seated_after_unlock', 'law_reception_charge_promoted', 10, 3 );

/**
 * Put a seated entry back at the front of the queue, ready to be offered the
 * place again as soon as whatever went wrong is fixed.
 *
 * Used for OUR failures (a missing tax rate, an unreachable Stripe) and for a
 * delegate who fixed their payment details while the reception was full again.
 * The delegate is never blamed for either, and never emailed a decline.
 *
 * @param bool $alert Raise the ACTION NEEDED log line and the admin email.
 *                    False for the ordinary "it filled up again" case, which
 *                    is nobody's mistake.
 */
function law_reception_requeue_at_front( $booking_id, $why = '', $alert = true ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_reception_booking_is( $booking ) ) {
		return false;
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	$locked = law_booking_lock( $event_id );
	law_booking_set_status( $booking_id, 'law-waitlisted' );
	law_event_update_meta( $booking_id, '_law_payment_status', 'ready' );
	// Position 1 is the front; law_waitlist_renumber() closes the gap it makes
	// behind, and everyone else shuffles down by one.
	law_event_update_meta( $booking_id, '_law_waitlist_position', 1 );
	delete_post_meta( $booking_id, '_law_payment_processing_at' );
	delete_post_meta( $booking_id, '_law_waitlist_blocked' );
	law_waitlist_renumber( $event_id );
	law_event_recount_attendees( $event_id );
	if ( $locked ) {
		law_booking_unlock( $event_id );
	}

	$why = sanitize_text_field( (string) $why );
	law_event_log(
		$event_id,
		sprintf(
			$alert
				? 'ACTION NEEDED: waitlist entry #%1$d could not be charged and has been put back at the front of the queue. %2$s'
				: 'Waitlist entry #%1$d is back at the front of the queue. %2$s',
			(int) law_event_meta( $booking_id, '_law_booking_number' ),
			$why ?: 'No reason was given.'
		),
		array( 'source' => 'receptions', 'action' => $alert ? 'reception_charge_blocked' : 'reception_requeued', 'booking' => $booking_id ),
		array( 'user_id' => 0 )
	);
	// Only OUR failures raise an alarm. A reception that simply filled up
	// again is not a configuration problem and nobody needs waking for it.
	if ( $alert ) {
		law_events_send( 'admin_stripe_error', $event_id, array( 'placeholders' => array( 'stripe_error' => $why ) ) );
	}

	return true;
}

/**
 * A delegate whose payment failed has saved new details: charge them if a
 * place is free, and otherwise put them at the front of the queue.
 *
 * @return string A notice key for the redirect.
 */
function law_reception_retry_charge( $booking_id, $actor_id = 0 ) {
	$booking = get_post( (int) $booking_id );
	if ( ! $booking || ! law_reception_booking_is( $booking ) || 'law-payment-failed' !== $booking->post_status ) {
		return '';
	}
	$booking_id = (int) $booking->ID;
	$event_id   = (int) $booking->post_parent;

	// Past the window the entry is gone; the sweep cancels it and detaches the
	// method, so there is nothing to retry.
	$deadline = law_booking_payment_deadline_ts( $booking_id );
	if ( $deadline && time() > $deadline ) {
		return '';
	}

	if ( ! law_booking_lock( $event_id ) ) {
		return '';
	}
	law_event_recount_attendees( $event_id );
	$remaining = law_event_tickets_remaining( $event_id );
	$free      = null !== $remaining && $remaining > 0;
	if ( $free ) {
		law_booking_set_status( $booking_id, 'publish' );
		law_event_update_meta( $booking_id, '_law_payment_status', 'processing' );
		law_event_update_meta( $booking_id, '_law_payment_processing_at', gmdate( 'Y-m-d H:i' ) );
		law_booking_claim_charge( $booking_id );
		law_event_recount_attendees( $event_id );
	}
	law_booking_unlock( $event_id );

	if ( ! $free ) {
		// The reception filled up again while they were sorting it out. Front
		// of the queue, and told so, rather than silently nothing.
		law_reception_requeue_at_front(
			$booking_id,
			__( 'The reception was full again when the new payment details were saved.', 'law' ),
			false
		);
		return 'reception-requeued';
	}

	law_reception_charge_promoted( $event_id, array( $booking_id ), 'retry' );

	return 'paid' === (string) law_event_meta( $booking_id, '_law_payment_status' )
		? 'reception-paid'
		: 'reception-processing';
}

/* Handlers ___________________________________________________________________
 *
 * All on the module's standing pattern: law_events_guard_post() for the nonce,
 * the honeypot and the rate limit, then law_events_respond() for the
 * JSON-or-redirect tail, with a nopriv twin answering JSON 401. Every one works
 * without JavaScript as a plain POST.
 *
 * IDOR rule throughout: load the booking, check its kind, derive the event from
 * post_parent, and only then check who is asking. Nothing trusts an event ID or
 * a booking ID out of the request without that.
 */

/** Load a reception booking this user owns, or respond and exit. */
function law_reception_require_own_booking( $is_ajax, array $statuses = array() ) {
	$booking_id = absint( $_POST['booking_id'] ?? 0 );
	$booking    = $booking_id ? get_post( $booking_id ) : null;

	if ( ! $booking || ! law_reception_booking_is( $booking ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => __( 'That booking could not be found.', 'law' ), 'status' => 404 ), 'booking-failed' );
	}
	if ( (int) $booking->post_author !== get_current_user_id() ) {
		law_events_respond( $is_ajax, false, array( 'message' => __( 'That booking belongs to someone else.', 'law' ), 'status' => 403 ), 'booking-failed' );
	}
	if ( $statuses && ! in_array( $booking->post_status, $statuses, true ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => __( 'That booking cannot be changed now.', 'law' ), 'status' => 409 ), 'booking-failed' );
	}

	return $booking;
}

/** The reception fields one of these forms posted, cleaned. */
function law_reception_input_from_request() {
	$raw = isset( $_POST['law_reception'] ) ? wp_unslash( (array) $_POST['law_reception'] ) : array();

	return array(
		'event_id'     => absint( $_POST['event_id'] ?? 0 ),
		'code'         => sanitize_text_field( (string) ( $raw['code'] ?? '' ) ),
		'applied_code' => sanitize_text_field( (string) ( $raw['applied_code'] ?? '' ) ),
		'price_shown'  => absint( $raw['price_shown'] ?? 0 ),
		'terms'        => ! empty( $raw['terms'] ),
		'consent'      => ! empty( $raw['consent'] ),
		// Whether the delegate had an Apply button to press. Without JavaScript
		// there is none, so the server quotes what was typed and refuses once
		// on a price change rather than asking for a press that cannot happen.
		'ajax'         => ! empty( $_POST['law_ajax'] ),
	);
}

/* The live discount quote */

add_action( 'admin_post_law_reception_quote', 'law_reception_quote_handler' );
add_action( 'admin_post_nopriv_law_reception_quote', 'law_events_nopriv_json' );

/**
 * Price one place, with a code, without writing anything.
 *
 * JSON only, and never a redirect: it answers the Apply button, which has
 * nowhere to go. Its own rate surface, because a delegate trying three codes
 * must not spend the budget that lets them then book
 * (FLAGSHIP_PAYMENTS.md §12's open decision, settled here).
 */
function law_reception_quote_handler() {
	$is_ajax = law_events_guard_post(
		'law_reception_quote',
		array(
			'rate'          => array( 'discount_quote', 20, 600, 60 ),
			'honeypot_json' => array( 'message' => 'Checked.' ),
		)
	);

	if ( ! is_user_logged_in() ) {
		wp_send_json_error( array( 'message' => __( 'Please sign in to book a place.', 'law' ) ), 401 );
	}

	$input = law_reception_input_from_request();
	$open  = law_reception_guard_open( $input['event_id'] );
	if ( is_wp_error( $open ) ) {
		wp_send_json_error( array( 'message' => $open->get_error_message() ), 404 );
	}

	$quote = law_reception_quote( $input['event_id'], $input['code'], get_current_user_id() );
	if ( is_wp_error( $quote ) ) {
		$data = (array) $quote->get_error_data();
		wp_send_json_error(
			array( 'message' => $quote->get_error_message(), 'field' => (string) ( $data['field'] ?? 'law_discount_code' ) ),
			400
		);
	}

	wp_send_json_success(
		array(
			'net'      => law_events_format_pence( $quote['net'] ),
			'discount' => law_events_format_pence( $quote['discount'] ),
			'vat'      => law_events_format_pence( $quote['vat'] ),
			'gross'    => law_events_format_pence( $quote['gross'] ),
			'pence'    => $quote['gross'],
			'code'     => $quote['code'],
			'free'     => (bool) $quote['free'],
			'label'    => $quote['discount'] > 0
				? sprintf(
					/* translators: 1: the code, 2: the amount off. */
					__( 'Code %1$s applied: %2$s off.', 'law' ),
					$quote['code'],
					law_events_format_pence( $quote['discount'] )
				)
				: '',
		)
	);
}

/* Buying a place */

add_action( 'admin_post_law_reception_checkout', 'law_reception_checkout_handler' );
add_action( 'admin_post_nopriv_law_reception_checkout', 'law_events_nopriv_json' );

function law_reception_checkout_handler() {
	$is_ajax = law_events_guard_post(
		'law_reception_checkout',
		array(
			// The booking surface, shared with Register and the flagship: one
			// budget for "this person is taking places", and a larger per-IP
			// budget so a law firm behind one NAT cannot lock its own
			// colleagues out.
			'rate'            => array( 'booking', 10, 600, 100 ),
			'honeypot_json'   => array( 'message' => 'Thank you, your booking has been received.' ),
			'honeypot_notice' => 'reception-paid',
		)
	);

	if ( ! is_user_logged_in() ) {
		law_events_respond( $is_ajax, false, array( 'message' => __( 'Please sign in to book a place.', 'law' ), 'status' => 401 ), 'booking-failed' );
	}

	$input  = law_reception_input_from_request();
	$result = law_reception_checkout( get_current_user_id(), $input );

	if ( is_wp_error( $result ) ) {
		law_booking_log_refusal( $input['event_id'], 0, $result, get_current_user_id(), 'receptions' );
		// The no-JS path answers with a redirect and a notice KEY, which on its
		// own would turn "that discount code has expired" into "sorry, that
		// could not be started" — the one wording a delegate holding a real
		// code cannot act on. So the engine's own message rides a one-shot
		// transient and the notice reads it back (law_reception_form_state()).
		law_reception_store_form_state( $result );
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'reception-checkout-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => __( 'Taking you to our payment page', 'law' ),
			'message'  => __( 'Stripe will ask how you would like to pay.', 'law' ),
			'redirect' => $result['redirect'],
		),
		'reception-paid'
	);
}

/* Finishing a payment that was interrupted */

add_action( 'admin_post_law_reception_continue', 'law_reception_continue_handler' );
add_action( 'admin_post_nopriv_law_reception_continue', 'law_events_nopriv_json' );

/**
 * A POST form, never a GET link, and deliberately so: a browser's hover
 * prefetch would otherwise open Stripe sessions nobody asked for.
 */
function law_reception_continue_handler() {
	$is_ajax = law_events_guard_post(
		'law_reception_continue',
		array(
			'rate'          => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json' => array( 'message' => 'Done.' ),
		)
	);

	$booking = law_reception_require_own_booking( $is_ajax, array( 'law-pending-payment' ) );
	$url     = law_reception_continue( (int) $booking->ID, get_current_user_id() );

	if ( is_wp_error( $url ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $url->get_error_message(), 'status' => 502 ), 'reception-checkout-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => __( 'Taking you to our payment page', 'law' ),
			'message'  => __( 'Stripe will ask how you would like to pay.', 'law' ),
			'redirect' => $url,
		),
		'reception-paid'
	);
}

/* Joining the waitlist */

add_action( 'admin_post_law_reception_waitlist_join', 'law_reception_waitlist_join_handler' );
add_action( 'admin_post_nopriv_law_reception_waitlist_join', 'law_events_nopriv_json' );

function law_reception_waitlist_join_handler() {
	$is_ajax = law_events_guard_post(
		'law_reception_waitlist_join',
		array(
			'rate'            => array( 'booking', 10, 600, 100 ),
			'honeypot_json'   => array( 'message' => "You're on the waitlist." ),
			'honeypot_notice' => 'waitlist-joined',
		)
	);

	if ( ! is_user_logged_in() ) {
		law_events_respond( $is_ajax, false, array( 'message' => __( 'Please sign in to join the waitlist.', 'law' ), 'status' => 401 ), 'booking-failed' );
	}

	$input  = law_reception_input_from_request();
	$result = law_reception_waitlist_join( get_current_user_id(), $input );

	if ( is_wp_error( $result ) ) {
		law_booking_log_refusal( $input['event_id'], 0, $result, get_current_user_id(), 'receptions' );
		law_reception_store_form_state( $result );
		law_events_respond( $is_ajax, false, law_booking_error_payload( $result ), 'reception-checkout-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => __( 'Taking you to our payment page', 'law' ),
			'message'  => __( 'Stripe will ask for the payment details we would charge if a place opens up.', 'law' ),
			'redirect' => $result['redirect'],
		),
		'reception-card'
	);
}

/* Adding the receptions a confirmed flagship place includes */

add_action( 'admin_post_law_reception_add_included', 'law_reception_add_included_handler' );
add_action( 'admin_post_nopriv_law_reception_add_included', 'law_events_nopriv_json' );

/**
 * The flagship booking is resolved FROM THE CURRENT USER, never from a posted
 * id: a delegate must not be able to claim free places against somebody else's
 * confirmed ticket.
 */
function law_reception_add_included_handler() {
	$is_ajax = law_events_guard_post(
		'law_reception_add_included',
		array(
			'rate'            => array( 'booking_edit', 15, 600, 150 ),
			'honeypot_json'   => array( 'message' => 'Added.' ),
			'honeypot_notice' => 'reception-included-added',
		)
	);

	if ( ! is_user_logged_in() ) {
		law_events_respond( $is_ajax, false, array( 'message' => __( 'Please sign in.', 'law' ), 'status' => 401 ), 'booking-failed' );
	}

	$user_id  = get_current_user_id();
	$flagship = law_reception_confirmed_flagship( $user_id );
	if ( ! $flagship ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => __( 'Receptions are included only once your flagship place is confirmed.', 'law' ), 'status' => 403 ),
			'reception-included-denied'
		);
	}

	$wanted = array_values( array_filter( array_map( 'absint', (array) ( $_POST['law_receptions'] ?? array() ) ) ) );
	// Only the ones that really are included: a forged checkbox reaches nothing.
	$wanted = array_values( array_intersect( $wanted, law_reception_included_ids() ) );
	if ( ! $wanted ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => __( 'Tick at least one reception to add.', 'law' ), 'status' => 400 ),
			'reception-included-none'
		);
	}

	$result = law_reception_grant_choices( (int) $flagship->ID, $wanted, $user_id, 'my_bookings' );
	$note   = law_reception_choices_note( $result );

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => __( 'Added to your bookings', 'law' ),
			'message'  => $note ?: __( 'Your bookings are up to date.', 'law' ),
			'redirect' => add_query_arg( 'law_notice', 'reception-included-added', law_account_url( 'my_bookings' ) ),
		),
		'reception-included-added'
	);
}

/* The return from Stripe _____________________________________________________ */

add_action( 'template_redirect', 'law_reception_handle_checkout_return' );

/**
 * The delegate coming back from Stripe's hosted payment page.
 *
 * Same posture as law_flagship_handle_setup_return(): NO NONCE, because
 * nothing is decided from the URL. Everything is decided from Stripe. What the
 * URL has to survive is somebody guessing a booking id on the cancel link, and
 * that is settled by fetching the session and refusing it unless its metadata
 * names THIS booking — so a guessed id cannot release somebody else's hold.
 */
function law_reception_handle_checkout_return() {
	if ( empty( $_GET['law_checkout_session'] ) || ! is_user_logged_in() ) {
		return;
	}
	$booking_id = absint( $_GET['law_booking'] ?? 0 );
	$booking    = $booking_id ? get_post( $booking_id ) : null;
	if ( ! $booking || ! law_reception_booking_is( $booking ) ) {
		return;
	}
	if ( (int) $booking->post_author !== get_current_user_id() ) {
		return;
	}

	$session_id = sanitize_text_field( wp_unslash( (string) $_GET['law_checkout_session'] ) );
	$cancelled  = 'cancelled' === sanitize_key( (string) ( $_GET['law_checkout'] ?? '' ) );
	$listing    = law_account_url( 'my_bookings' );
	$manage     = law_booking_manage_url( $booking_id );

	$session = law_stripe_get_checkout_session( $session_id );
	if ( is_wp_error( $session ) ) {
		wp_safe_redirect( add_query_arg( 'law_notice', 'reception-return-failed', $manage ) );
		exit;
	}
	// The session must name this booking. Without this a guessable id on a
	// cancel URL would release a stranger's hold.
	if ( (int) ( $session['metadata']['law_booking_id'] ?? 0 ) !== $booking_id ) {
		return;
	}

	$status  = (string) ( $session['payment_status'] ?? '' );
	$intent  = is_array( $session['payment_intent'] ?? null ) ? (array) $session['payment_intent'] : array();
	$pending = 'processing' === (string) ( $intent['status'] ?? '' );

	if ( 'paid' === $status || 'no_payment_required' === $status ) {
		law_reception_mark_paid( $booking_id, $session, '', get_current_user_id() );
		wp_safe_redirect( add_query_arg( 'law_notice', 'reception-paid', $manage ) );
		exit;
	}
	if ( $pending ) {
		law_reception_mark_processing( $booking_id );
		wp_safe_redirect( add_query_arg( 'law_notice', 'reception-processing', $manage ) );
		exit;
	}
	if ( $cancelled ) {
		// release_hold detects a completed session itself, so a payment that
		// landed in the race confirms rather than being thrown away.
		law_reception_release_hold( $booking_id, 'cancelled' );
		// The LISTING, not the manage view: the booking is cancelled now, and
		// its manage view would read as an alarm.
		wp_safe_redirect( add_query_arg( 'law_notice', 'reception-cancelled', $listing ) );
		exit;
	}

	// An expired session, or one Stripe has not finished with. Say what is
	// true and send them somewhere useful.
	if ( 'expired' === (string) ( $session['status'] ?? '' ) ) {
		law_reception_release_hold( $booking_id, 'expired' );
		wp_safe_redirect( add_query_arg( 'law_notice', 'reception-expired', $listing ) );
		exit;
	}

	wp_safe_redirect( $manage );
	exit;
}

/* The sweep __________________________________________________________________
 *
 * One hourly pass that finishes what the webhooks and the browser could not
 * (RECEPTIONS.md §9). It is load-bearing for money, so it does NOT depend on
 * pseudo-cron alone: the webhook handler runs it opportunistically after every
 * dispatch, bounded to a handful of rows, and production needs a real cron
 * hitting wp-cron.php.
 */

/** How long after a session's own expiry a hold may still be released. */
const LAW_RECEPTION_HOLD_MARGIN = 10 * MINUTE_IN_SECONDS;

/** How long a confirmed place waits for its invoice before the email goes anyway. */
const LAW_RECEPTION_INVOICE_WAIT = 15 * MINUTE_IN_SECONDS;

add_action( 'law_reception_hourly', 'law_reception_sweep' );

/** Schedule the hourly pass. Idempotent; called on init. */
function law_reception_schedule_sweep() {
	if ( ! wp_next_scheduled( 'law_reception_hourly' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'hourly', 'law_reception_hourly' );
	}
}
add_action( 'init', 'law_reception_schedule_sweep' );

/**
 * Every reception booking that is waiting on something, in one pass.
 *
 * In order: release the holds that ran out, send the confirmations whose
 * invoice never arrived, reconcile the orphans, close the queue entries that
 * never saved a payment method, expire the retry window, and alert on a
 * payment that has been settling for too long.
 *
 * @param int $limit Rows per stage. The webhook's opportunistic run passes a
 *                   small number, because it is finishing somebody's request.
 * @return array<string,int> What each stage did.
 */
function law_reception_sweep( $limit = 100 ) {
	$limit = max( 1, (int) $limit );
	$done  = array(
		'released'   => 0,
		'confirmed'  => 0,
		'reconciled' => 0,
		'no_card'    => 0,
		'expired'    => 0,
		'alerted'    => 0,
	);

	// 1. Holds past their session's expiry, plus a margin so the webhook
	//    normally wins the race. `processing` is skipped inside
	//    law_reception_release_hold(): money in flight is not an abandoned hold.
	foreach ( law_reception_bookings( 'law-pending-payment', $limit ) as $booking ) {
		$expires = (string) law_event_meta( $booking->ID, '_law_checkout_expires_at' );
		if ( '' === $expires ) {
			continue;
		}
		$ts = strtotime( $expires . ' UTC' );
		if ( ! $ts || time() < $ts + LAW_RECEPTION_HOLD_MARGIN ) {
			continue;
		}
		if ( law_reception_release_hold( (int) $booking->ID, 'sweep' ) ) {
			$done['released']++;
		}
	}

	// 2. Confirmed places whose invoice never arrived. Fifteen minutes is long
	//    enough for invoice.paid on any normal account, and a confirmation
	//    with no receipt link beats no confirmation at all.
	foreach ( law_reception_bookings( 'publish', $limit ) as $booking ) {
		if ( 'paid' !== (string) law_event_meta( $booking->ID, '_law_payment_status' ) ) {
			continue;
		}
		if ( '' !== (string) get_post_meta( $booking->ID, '_law_confirmation_sent', true ) ) {
			continue;
		}
		$since = strtotime( (string) law_event_meta( $booking->ID, '_law_paid_at' ) . ' UTC' );
		if ( $since && time() - $since < LAW_RECEPTION_INVOICE_WAIT ) {
			continue;
		}
		if ( law_reception_maybe_send_confirmation( (int) $booking->ID, true ) ) {
			$done['confirmed']++;
		}
	}

	// 3. Orphans: seated, `processing`, and nothing has moved for fifteen
	//    minutes. Either the charge never started (PHP died between the unlock
	//    and the Stripe call) or it did and we never heard the answer.
	foreach ( law_reception_bookings( 'publish', $limit ) as $booking ) {
		if ( 'processing' !== (string) law_event_meta( $booking->ID, '_law_payment_status' ) ) {
			continue;
		}
		$since = strtotime( (string) law_event_meta( $booking->ID, '_law_payment_processing_at' ) . ' UTC' );
		if ( ! $since || time() - $since < LAW_RECEPTION_INVOICE_WAIT ) {
			continue;
		}
		if ( law_reception_reconcile_orphan( (int) $booking->ID ) ) {
			$done['reconciled']++;
		}
	}

	// 4. Queue entries that never saved a payment method. They can never be
	//    promoted, so leaving them in the queue is a place nobody can have.
	$grace = LAW_FLAGSHIP_SETUP_GRACE_HOURS * HOUR_IN_SECONDS;
	foreach ( law_reception_bookings( 'law-waitlisted', $limit ) as $booking ) {
		if ( 'pending_setup' !== (string) law_event_meta( $booking->ID, '_law_payment_status' ) ) {
			continue;
		}
		$joined = strtotime( (string) law_event_meta( $booking->ID, '_law_waitlist_joined' ) );
		if ( ! $joined || time() - $joined < $grace ) {
			continue;
		}
		$extra = law_booking_email_extra( (int) $booking->ID );
		if ( ! is_wp_error( law_booking_cancel( (int) $booking->ID, 0, 'account_deleted' ) ) ) {
			law_events_send( 'user_reception_waitlist_no_card', (int) $booking->post_parent, $extra );
			$done['no_card']++;
		}
	}

	// 5. The retry window: past it, the entry is closed, the code released
	//    (law_booking_cancel() does that) and the payment method detached.
	foreach ( law_reception_bookings( 'law-payment-failed', $limit ) as $booking ) {
		$deadline = law_booking_payment_deadline_ts( (int) $booking->ID );
		if ( ! $deadline || time() < $deadline ) {
			continue;
		}
		if ( ! is_wp_error( law_booking_cancel( (int) $booking->ID, 0, 'host_reject', array( 'reason' => __( 'The payment was not sorted out in time.', 'law' ) ) ) ) ) {
			$done['expired']++;
		}
	}

	// 6. A payment that has been settling for days. A card clears in seconds
	//    and a bank debit in working days, so this only ever fires on one that
	//    has genuinely gone nowhere.
	$stuck = LAW_FLAGSHIP_PROCESSING_ALERT_DAYS * DAY_IN_SECONDS;
	foreach ( law_reception_bookings( 'publish', $limit ) as $booking ) {
		if ( 'processing' !== (string) law_event_meta( $booking->ID, '_law_payment_status' ) ) {
			continue;
		}
		$since = strtotime( (string) law_event_meta( $booking->ID, '_law_payment_processing_at' ) . ' UTC' );
		if ( ! $since || time() - $since < $stuck ) {
			continue;
		}
		// One alert per booking, not one per hour.
		if ( ! law_booking_claim_latch( (int) $booking->ID, '_law_payment_stuck_flagged' ) ) {
			continue;
		}
		law_event_log(
			(int) $booking->post_parent,
			sprintf(
				'ACTION NEEDED: the payment for reception place #%d has been in progress since %s and has not settled. Check it in Stripe.',
				(int) law_event_meta( $booking->ID, '_law_booking_number' ),
				(string) law_event_meta( $booking->ID, '_law_payment_processing_at' )
			),
			array( 'source' => 'receptions', 'action' => 'reception_payment_stuck', 'booking' => (int) $booking->ID ),
			array( 'user_id' => 0 )
		);
		law_events_send(
			'admin_stripe_error',
			(int) $booking->post_parent,
			array( 'placeholders' => array( 'stripe_error' => 'A reception payment has been in progress for more than ' . LAW_FLAGSHIP_PROCESSING_ALERT_DAYS . ' days.' ) )
		);
		$done['alerted']++;
	}

	return $done;
}

/**
 * Reception bookings in one status, newest first.
 *
 * A meta query on the parent event rather than a post_parent list, so the pass
 * is one query however many receptions there are.
 *
 * @return WP_Post[]
 */
function law_reception_bookings( $status, $limit = 100 ) {
	$receptions = law_reception_ids();
	if ( ! $receptions ) {
		return array();
	}

	return get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => $status,
			'post_parent__in' => $receptions,
			'posts_per_page' => max( 1, (int) $limit ),
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'no_found_rows'  => true,
		)
	);
}

/**
 * One seated, `processing` entry that has stopped moving.
 *
 * NO invoice means PHP died between the unlock and the Stripe call: take the
 * claim and charge. A claim that is held and stale (older than the five-minute
 * takeover law_booking_claim_charge() already uses) means the charging request
 * died mid-flight, so the entry goes back to the front of the queue rather
 * than being billed twice.
 *
 * WITH an invoice: ask Stripe what happened to it.
 *
 * @return bool Whether anything changed.
 */
function law_reception_reconcile_orphan( $booking_id ) {
	$booking_id = (int) $booking_id;
	$event_id   = (int) get_post_field( 'post_parent', $booking_id );
	$invoice_id = (string) law_event_meta( $booking_id, '_law_stripe_invoice_id' );

	if ( '' === $invoice_id ) {
		$claimed_at = law_booking_charge_claimed_at( $booking_id );
		if ( $claimed_at && ( time() - $claimed_at ) < 5 * MINUTE_IN_SECONDS ) {
			return false; // Somebody is charging it right now.
		}
		if ( $claimed_at ) {
			// A stale claim with no invoice: the request that took it died
			// before it billed anything. Give the place back to the queue.
			law_booking_release_charge( $booking_id );
			return law_reception_requeue_at_front(
				$booking_id,
				__( 'A charge was started but never completed, so nothing was billed.', 'law' ),
				false
			);
		}
		if ( ! law_booking_claim_charge( $booking_id ) ) {
			return false;
		}
		law_booking_release_charge( $booking_id );
		law_reception_charge_promoted( $event_id, array( $booking_id ), 'sweep' );

		return true;
	}

	$invoice = law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $invoice_id ), array() );
	if ( is_wp_error( $invoice ) ) {
		return false; // Try again next hour rather than guessing.
	}

	$status = (string) ( $invoice['status'] ?? '' );
	if ( 'paid' === $status ) {
		law_reception_mark_paid( $booking_id, $invoice, '', 0 );
		law_booking_send_with_ics( 'user_reception_promoted_paid', $event_id, law_booking_email_extra( $booking_id ) );
		return true;
	}
	if ( in_array( $status, array( 'void', 'uncollectible' ), true ) ) {
		law_reception_mark_payment_failed( $booking_id, __( 'The payment was not completed.', 'law' ) );
		law_waitlist_schedule_resume( $event_id );
		return true;
	}

	// Still settling. Stage 6 alerts on one that never resolves.
	return false;
}

/**
 * Run the sweep opportunistically after a webhook, bounded to a handful of
 * rows: it is finishing somebody else's request, so it must be cheap.
 *
 * This is what makes the money paths not depend on pseudo-cron alone, which
 * only fires when somebody happens to load a page.
 */
add_action(
	'law_stripe_webhook_dispatched',
	function () {
		law_reception_sweep( 5 );
	}
);

/* Notices ____________________________________________________________________ */

/**
 * Keep a refused submission's own message for exactly one read.
 *
 * The no-JS path answers with a redirect, and a redirect carries a notice KEY,
 * not a sentence. Without this every refusal would read "sorry, that booking
 * could not be started" — including "that discount code has expired", which is
 * the one a delegate holding a real code can actually act on. The same
 * one-shot-transient pattern as law_flagship_form_state().
 */
function law_reception_store_form_state( WP_Error $error ) {
	if ( ! get_current_user_id() ) {
		return;
	}
	set_transient(
		'law_reception_state_msg_' . get_current_user_id(),
		(string) $error->get_error_message(),
		10 * MINUTE_IN_SECONDS
	);
}

/** Read it back, once. '' when there is none. */
function law_reception_form_state() {
	$key     = 'law_reception_state_msg_' . get_current_user_id();
	$message = get_transient( $key );
	if ( ! is_string( $message ) || '' === $message ) {
		return '';
	}
	delete_transient( $key );

	return $message;
}

/**
 * The outcomes only a reception can report, registered through the shared
 * notice map rather than stacked as a third renderer on My bookings
 * (RECEPTIONS.md §2.10).
 */
add_filter(
	'law_booking_notice_text',
	function ( array $map ) {
		return array_merge(
			$map,
			array(
				'reception-paid'             => array( 'ok', __( 'Thank you. Your payment has gone through and your place is confirmed. A confirmation with a calendar invitation and your VAT invoice is on its way.', 'law' ) ),
				'reception-processing'       => array( 'ok', __( 'Your payment is on its way. Your place is held and we will email you as soon as it clears.', 'law' ) ),
				'reception-free-confirmed'   => array( 'ok', __( 'Your place is confirmed. Your discount code covered the full price, so nothing was charged.', 'law' ) ),
				'reception-cancelled'        => array( 'error', __( 'No payment was taken and the place has been released. You can book again while places remain.', 'law' ) ),
				'reception-expired'          => array( 'error', __( 'Your booking was not completed in time and the place has been released. You can book again while places remain.', 'law' ) ),
				'reception-return-failed'    => array( 'error', __( 'We could not confirm your payment with Stripe. If money has left your account, contact LAW and we will sort it out.', 'law' ) ),
				'reception-card'             => array( 'ok', __( "Your payment details are saved and you're on the waitlist. If a place opens up we will charge them and confirm your place automatically.", 'law' ) ),
				'reception-card-cancelled'   => array( 'error', __( 'Your payment details were not saved, so your place in the queue is not yet secured. Add them from your booking.', 'law' ) ),
				'reception-requeued'         => array( 'ok', __( 'Your payment details are updated. The reception is full again, so you are at the front of the waitlist.', 'law' ) ),
				'reception-included-added'   => array( 'ok', __( 'Your bookings are up to date.', 'law' ) ),
				'reception-included-none'    => array( 'error', __( 'Tick at least one reception to add.', 'law' ) ),
				'reception-included-denied'  => array( 'error', __( 'Receptions are included only once your flagship place is confirmed.', 'law' ) ),
				// The engine's own words where there are any: read ONCE, and
				// only on the request that is actually showing this notice, so
				// asking the map for a different key cannot consume it.
				'reception-checkout-failed'  => array(
					'error',
					( 'reception-checkout-failed' === sanitize_key( (string) ( $_GET['law_notice'] ?? '' ) ) ? law_reception_form_state() : '' )
						?: __( 'Sorry, that booking could not be started. Please try again.', 'law' ),
				),
			)
		);
	}
);

/* The banner on My bookings and the Account hub ______________________________ */

/**
 * The included receptions this person could add but has not.
 *
 * Empty for anybody without a confirmed flagship place, and empty once they
 * hold all of them — which is what makes the banner disappear by itself rather
 * than needing a dismissal.
 *
 * A `law-pending-payment` hold counts as held: somebody part-way through
 * buying Monday should not be offered it free in the same breath.
 *
 * @return int[] law_event post IDs.
 */
function law_reception_banner_state( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 || ! law_reception_confirmed_flagship( $user_id ) ) {
		return array();
	}

	$missing = array();
	foreach ( law_reception_included_ids() as $event_id ) {
		if ( ! law_reception_holds_place( $user_id, $event_id ) ) {
			$missing[] = (int) $event_id;
		}
	}

	return $missing;
}

/**
 * The banner itself: one helper, two surfaces.
 *
 * It renders on My bookings AND on the Account hub, because the hub is where a
 * signed-in delegate now lands (ROLES_AND_ACCOUNT_HUB.md), and a free place
 * they have not claimed is exactly the thing a landing page should be telling
 * them. One function, so the copy can never differ between the two.
 *
 * @return string Markup, or '' when there is nothing to offer.
 */
function law_reception_banner( $user_id ) {
	$missing = law_reception_banner_state( $user_id );
	if ( ! $missing ) {
		return '';
	}

	$names = array();
	foreach ( $missing as $event_id ) {
		$when    = law_reception_when_label( $event_id );
		$names[] = get_the_title( $event_id ) . ( '' !== $when ? ' (' . $when . ')' : '' );
	}

	ob_start();
	?>
	<div class="law-strip law-strip--panel law-reception-strip">
		<div class="law-strip__text">
			<p class="law-strip__title"><?php esc_html_e( 'Your flagship place includes a drink with us', 'law' ); ?></p>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: a list of reception names with their days. */
						_n(
							'Your place at the conference includes %s. Add it to your bookings at no cost.',
							'Your place at the conference includes %s. Add the ones you would like to attend at no cost.',
							count( $missing ),
							'law'
						),
						wp_sprintf_l( '%l', $names )
					)
				);
				?>
			</p>
		</div>
		<form class="law-booking-form law-strip__action" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_reception_add_included">
			<?php wp_nonce_field( 'law_reception_add_included' ); ?>
			<?php law_events_honeypot_field(); ?>
			<button type="button" class="button orange law-strip__cta" data-law-modal-open="law-reception-include">
				<?php esc_html_e( 'Add receptions', 'law' ); ?>
			</button>
			<?php
			// The dialog is rendered INSIDE the form it confirms, which is the
			// module's standing pattern: the form posts whether or not the
			// script is there, and <noscript> holds the dialog open.
			get_template_part( 'parts/events/reception-include-modal', null, array( 'user_id' => (int) $user_id ) );
			?>
		</form>
	</div>
	<?php

	return (string) ob_get_clean();
}

/* The discount catalogue _____________________________________________________ */

/**
 * Every priced reception, as a scope the committee can tick when creating a
 * code (RECEPTIONS.md §8.4).
 *
 * The catalogue was built on 10 September 2026 against "a priced booking",
 * deliberately unwired because nothing charged yet. This is what wires it: the
 * checkboxes on the code editor appear, and the list's "Applies to" column
 * prints real names.
 *
 * Only PRICED ones. A free reception has nothing to discount, and offering it
 * as a scope would let the committee build a code that can never apply.
 */
add_filter(
	'law_discount_scope_events',
	function ( $events ) {
		foreach ( law_reception_ids() as $event_id ) {
			if ( ! law_event_is_priced( $event_id ) ) {
				continue;
			}
			$start = (string) law_event_meta( $event_id, '_law_start' );
			$when  = '' !== $start && strtotime( $start ) ? wp_date( 'D j M', strtotime( $start ) ) : '';

			$events[ (int) $event_id ] = trim( get_the_title( $event_id ) . ( '' !== $when ? ', ' . $when : '' ) );
		}

		return $events;
	}
);
