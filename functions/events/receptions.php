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
