<?php
/**
 * External events: the ones LAW neither runs nor books (EVENTS_4.2_SPECS.md).
 *
 * LCIA's Tylney Symposium, GAR Live, the CIArb Alexander Lecture, Law Rocks —
 * events a third party runs during the programme week, registers people for on
 * its own website, and which the committee curates onto the LAW programme so a
 * delegate planning their week sees the whole week. They were captured on
 * Gravity Forms form 10 (Event > external events) until this module replaced
 * it; migration step 3b brings those entries across.
 *
 * What makes one different from a host submission:
 *
 * - **No workflow.** The committee owns the post from the moment it is created,
 *   so there is nothing to approve. Its two statuses mean only "on the
 *   programme" (publish) and "not yet" (law-draft), exactly as a reception's
 *   do, and law_event_is_managed_by_law() is what tells the status guard in
 *   workflow.php to honour them.
 * - **No slot.** An external organiser picks its own hours, so the date and the
 *   two times are typed and written straight into _law_start / _law_end with
 *   _law_slot_label left empty. The programme's day grid groups by the times it
 *   finds, so an 11:15-12:45 event simply gets its own slot bar; nothing in
 *   functions/calendar.php needed changing for this.
 * - **No booking.** _law_registration_state is 'external' and the Register
 *   button links out (law_booking_external_button()). The refusal that makes
 *   that real rather than cosmetic is in law_booking_guard_form_open().
 * - **No fee, no invoice, no co-owners, no contacts, no tickets.** All four
 *   entries migrated from form 10 left every one of those fields empty, and
 *   none of them means anything for an event booked somewhere else.
 *
 * What it shares with a host submission, deliberately: the Speakers repeater
 * and the Session agenda repeater, which are the same partials and the same
 * savers (parts/events/event-form-speakers.php,
 * parts/events/event-form-agenda.php, law_events_form_save_speakers(),
 * law_events_form_save_sessions()). Two of the four migrated events carry a
 * full published running order, so the agenda is not optional decoration here.
 *
 * This file is the model: values in, validation, the saver and the log. The
 * route, the gate and the POST handler are at the bottom; the form itself is
 * parts/events/external-manage.php, rendered as a branch of the committee's
 * Manage events page rather than on a page of its own, so nothing new has to be
 * provisioned on deploy.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * True when this post is an external event.
 *
 * The post-type guard that law_flagship_is() does not need (it compares IDs)
 * but law_reception_is() does, for the same reason: a bare meta read would
 * answer for any post type at all.
 */
function law_external_event_is( $event_id ) {
	$event_id = (int) $event_id;
	if ( $event_id < 1 || LAW_EVENT_CPT !== get_post_type( $event_id ) ) {
		return false;
	}
	return (bool) law_event_meta( $event_id, '_law_is_external' );
}

/**
 * The Manage events URL addressing one external event, or the create form.
 *
 * A query argument on the committee dashboard rather than a page of its own:
 * every account page is database state that has to be provisioned by both
 * migration step 10 and ?setup-account-pages, and this screen needs neither.
 *
 * @param int|string $event 0 for the list, 'new' for the create form, else the ID.
 */
function law_external_event_url( $event = 0 ) {
	$url = function_exists( 'law_account_url' ) ? law_account_url( 'dashboard' ) : '';
	if ( '' === $url ) {
		$url = home_url( '/account/dashboard/' );
	}
	if ( 'new' === $event ) {
		return add_query_arg( 'law_external', 'new', $url );
	}
	return $event ? add_query_arg( 'law_external', (int) $event, $url ) : $url;
}

/**
 * Which view the request is asking for.
 *
 * @return int|string 0 for the list, 'new' for the create form, else the ID.
 */
function law_external_event_requested() {
	$asked = sanitize_text_field( wp_unslash( (string) ( $_GET['law_external'] ?? '' ) ) );
	if ( 'new' === $asked ) {
		return 'new';
	}
	$id = absint( $asked );
	return ( $id && law_external_event_is( $id ) ) ? $id : 0;
}

/* The form's values _________________________________________________________ */

/**
 * The values the form renders, built on law_events_form_values() so the shared
 * Speakers and Session agenda repeaters get exactly the shape they expect, plus
 * the four keys only an external event has.
 *
 * @param WP_Post|null $post  The event being edited, or null when creating.
 * @param array        $state law_external_event_state(): a refused save's input.
 */
function law_external_event_values( $post, array $state = array() ) {
	if ( ! empty( $state['input'] ) ) {
		return (array) $state['input'];
	}
	if ( ! $post ) {
		return array( 'sectors' => array(), 'speakers' => array(), 'sessions' => array() );
	}

	$values = law_events_form_values( $post, array() );

	// _law_start is a naive site-local 'Y-m-d H:i', so the split is positional
	// rather than a strtotime round trip that would invent a timezone.
	$start = (string) law_event_meta( $post->ID, '_law_start' );
	$end   = (string) law_event_meta( $post->ID, '_law_end' );

	$values['external_date']  = '' !== $start ? substr( $start, 0, 10 ) : '';
	$values['external_start'] = '' !== $start ? substr( $start, 11, 5 ) : '';
	$values['external_end']   = '' !== $end ? substr( $end, 11, 5 ) : '';
	$values['external_url']   = (string) law_event_meta( $post->ID, '_law_external_url' );

	return $values;
}

/**
 * A refused save, stored for exactly one read: the pattern the receptions,
 * speakers, flagship and discounts dashboards all use, so a later visit is not
 * haunted by an old failure.
 *
 * @return array{errors:array<string,array<int,string>>,input:array}
 */
function law_external_event_state() {
	$key   = 'law_external_state_' . get_current_user_id();
	$state = get_transient( $key );
	if ( ! is_array( $state ) ) {
		return array( 'errors' => array(), 'input' => array() );
	}
	delete_transient( $key );
	return array(
		'errors' => (array) ( $state['errors'] ?? array() ),
		'input'  => (array) ( $state['input'] ?? array() ),
	);
}

/**
 * Print one field's error message, in the same markup and the same place the
 * shared form partials use.
 *
 * A <span>, not a <p>: these render inside p.law-form-field, and a nested <p>
 * would be auto-closed by the parser, orphaning the message from its field and
 * breaking the invalid-field highlight.
 *
 * @param array  $errors law_external_event_state()['errors'].
 * @param string $field  Field key.
 */
function law_external_event_error( array $errors, $field ) {
	if ( isset( $errors[ $field ][0] ) ) {
		echo '<span class="law-form-error" role="alert">' . esc_html( $errors[ $field ][0] ) . '</span>';
	}
}

/** Store a refused save for one read. */
function law_external_event_store_state( array $errors, array $input ) {
	set_transient(
		'law_external_state_' . get_current_user_id(),
		array( 'errors' => $errors, 'input' => law_events_form_reusable_input( $input ) ),
		10 * MINUTE_IN_SECONDS
	);
}

/** The notices this screen can show, in the shared .law-form-notice shape. */
function law_external_event_notices() {
	return array(
		'external-saved'     => array( 'is-success', __( 'The external event has been saved.', 'law' ) ),
		'external-published' => array( 'is-success', __( 'The external event is now on the programme.', 'law' ) ),
		'external-invalid'   => array( 'is-error', __( 'The external event was not saved. Please check the fields below.', 'law' ) ),
		'external-denied'    => array( 'is-error', __( 'Sorry, external events are managed by the committee.', 'law' ) ),
	);
}

/* Validation ________________________________________________________________ */

/**
 * Validate one submission. Everything accumulates into a WP_Error and nothing
 * is written until it comes back empty, so a refused save leaves no half-built
 * event behind.
 *
 * A draft is held to the same standard as a publish except for the date: the
 * committee routinely starts a row from an announcement that gives a month and
 * no day, and refusing to let them park that would push the work back into a
 * notebook. Everything else is cheap to type and expensive to discover missing
 * later.
 *
 * @param array $input   wp_unslash( $_POST ).
 * @param bool  $publish Whether this save puts the event on the programme.
 * @param array $files   $_FILES, for the speaker photos.
 * @return true|WP_Error
 */
function law_external_event_validate( array $input, $publish, array $files = array() ) {
	$errors = new WP_Error();

	if ( '' === trim( (string) ( $input['event_title'] ?? '' ) ) ) {
		$errors->add( 'event_title', __( 'Please give the event a title.', 'law' ) );
	}

	$date = (string) law_events_sanitize_value( $input['external_date'] ?? '', 'date' );
	if ( $publish && '' === $date ) {
		$errors->add( 'external_date', __( 'Please give the date. An event with no date cannot go on the programme.', 'law' ) );
	}
	if ( '' !== (string) ( $input['external_date'] ?? '' ) && '' === $date ) {
		$errors->add( 'external_date', __( 'That date could not be read. Use the date picker.', 'law' ) );
	}

	$start = (string) law_events_sanitize_value( $input['external_start'] ?? '', 'time' );
	$end   = (string) law_events_sanitize_value( $input['external_end'] ?? '', 'time' );
	if ( '' !== $end && '' === $start ) {
		$errors->add( 'external_start', __( 'An end time needs a start time.', 'law' ) );
	}

	if ( $publish ) {
		if ( '' === trim( (string) ( $input['event_type'] ?? '' ) ) ) {
			$errors->add( 'event_type', __( 'Please choose the event type.', 'law' ) );
		}
		if ( '' === trim( wp_strip_all_tags( (string) ( $input['description'] ?? '' ) ) ) ) {
			$errors->add( 'description', __( 'Please describe the event.', 'law' ) );
		}
	}

	// The two "please specify" inputs, the same rule the host form applies:
	// ticking the sector without saying which one says nothing at all.
	$sectors = array_map( 'strval', (array) ( $input['sectors'] ?? array() ) );
	if ( in_array( 'Jurisdiction-specific', $sectors, true ) && '' === trim( (string) ( $input['sector_jurisdiction'] ?? '' ) ) ) {
		$errors->add( 'sector_jurisdiction', __( 'Please say which jurisdiction.', 'law' ) );
	}
	if ( in_array( 'Other / sector-neutral', $sectors, true ) && '' === trim( (string) ( $input['sector_other'] ?? '' ) ) ) {
		$errors->add( 'sector_other', __( 'Please say which other sector, or that it is sector-neutral.', 'law' ) );
	}

	// The booking URL is optional — the committee lists an event before its
	// organiser opens registration, and the listing shows a disabled
	// "Registration opening soon" button until then. What it may not be is
	// present and unusable, in either of the two ways that happens:
	// esc_url_raw() returns an EMPTY string for a scheme it refuses
	// (javascript:, data:, ftp:), which would leave the Register button
	// pointing at nothing, and it keeps a path with no host ("/tickets"),
	// which would send people back into this site. A typo in the host is not
	// caught and cannot be: "wwww.example.org" comes back as
	// "http://wwww.example.org", a well-formed address for a site that does
	// not exist, and the committee sees that on the listing.
	$url_raw = trim( (string) ( $input['external_url'] ?? '' ) );
	if ( '' !== $url_raw ) {
		$url = esc_url_raw( $url_raw, array( 'http', 'https' ) );
		if ( '' === $url || ! preg_match( '#^https?://[^/\s]+#i', $url ) ) {
			$errors->add( 'external_url', __( 'That booking link could not be read. It should start with https:// and point at the organiser\'s website.', 'law' ) );
		}
	}

	$photos = law_events_validate_photos( $files );
	if ( is_wp_error( $photos ) ) {
		foreach ( $photos->get_error_codes() as $code ) {
			$errors->add( $code, $photos->get_error_message( $code ) );
		}
	}

	return $errors->has_errors() ? $errors : true;
}

/* The saver _________________________________________________________________ */

/**
 * Create or update one external event.
 *
 * @param array $input   wp_unslash( $_POST ).
 * @param int   $actor   The committee member saving.
 * @param array $files   $_FILES, for the speaker photos.
 * @return int|WP_Error  The event post ID.
 */
function law_external_event_save( array $input, $actor, array $files = array() ) {
	$event_id = absint( $input['law_external_id'] ?? 0 );
	$publish  = 'publish' === sanitize_key( $input['law_form_action'] ?? 'draft' );

	// A posted ID that is not an external event is refused rather than silently
	// creating one: a forged or stale ID must reach nothing. A blank one means
	// "create", which is how the Create an external event form works.
	if ( $event_id && ! law_external_event_is( $event_id ) ) {
		return new WP_Error( 'law_external_missing', __( 'That external event could not be found.', 'law' ) );
	}

	$valid = law_external_event_validate( $input, $publish, $files );
	if ( is_wp_error( $valid ) ) {
		return $valid;
	}

	$title       = sanitize_text_field( (string) ( $input['event_title'] ?? '' ) );
	$description = law_rich_text_sanitize( (string) ( $input['description'] ?? '' ) );
	$status      = $publish ? 'publish' : 'law-draft';

	if ( ! $event_id ) {
		// A NEW post may be inserted at any status: the workflow status guard
		// passes new inserts straight through (workflow.php), so nothing fires
		// here — no transition, no email, no Stripe call, no approval. That is
		// the whole point of an external event: the committee is recording
		// something that already exists in the world.
		$event_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => LAW_EVENT_CPT,
					'post_status'  => $status,
					'post_title'   => $title,
					'post_content' => $description,
					'post_author'  => (int) $actor,
				)
			),
			true
		);
		if ( is_wp_error( $event_id ) ) {
			return $event_id;
		}
		$event_id = (int) $event_id;
		// Written before anything else: law_event_is_managed_by_law() reads it,
		// and every guard below this line depends on that answering yes.
		law_event_update_meta( $event_id, '_law_is_external', 1 );
		$created = true;
	} else {
		$created = false;
	}

	$before = law_external_event_snapshot( $event_id );

	if ( ! $created ) {
		// An EXISTING event's status is reverted by the guard unless the saver
		// announces itself, which is what stops the classic editor's Publish
		// button confirming an unapproved host submission. An external event has
		// no workflow at all, so it announces itself exactly as the receptions'
		// and the flagship's savers do; the guard honours the flag for publish
		// and law-draft only, which are the only two states this screen offers.
		$GLOBALS['law_event_managed_saving'] = true;
		$updated                             = wp_update_post(
			wp_slash(
				array(
					'ID'           => $event_id,
					'post_title'   => $title,
					'post_content' => $description,
					'post_status'  => $status,
				)
			),
			true
		);
		unset( $GLOBALS['law_event_managed_saving'] );
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
	}

	law_event_update_meta( $event_id, '_law_is_external', 1 );
	// One vocabulary for "how is this booked", the same key the receptions use.
	// Both are written: the flag says what kind of event this is and the state
	// says how a place is obtained, and law_event_is_external() reads the flag
	// precisely because it is the one a committee member cannot change by
	// accident from another screen.
	law_event_update_meta( $event_id, '_law_registration_state', 'external' );
	law_event_update_meta( $event_id, '_law_external_url', trim( (string) ( $input['external_url'] ?? '' ) ) );

	law_external_event_apply_when( $event_id, $input, $actor );

	law_event_update_meta( $event_id, '_law_host_organisations', (string) ( $input['host_organisations'] ?? '' ) );
	law_event_update_meta( $event_id, '_law_venue', (string) ( $input['venue'] ?? '' ) );
	law_event_update_meta( $event_id, '_law_sector_jurisdiction', (string) ( $input['sector_jurisdiction'] ?? '' ) );
	law_event_update_meta( $event_id, '_law_sector_other', (string) ( $input['sector_other'] ?? '' ) );

	law_events_set_terms_by_name( $event_id, 'law_event_type', array( (string) ( $input['event_type'] ?? '' ) ) );
	law_events_set_terms_by_name( $event_id, 'law_sector', array_map( 'strval', (array) ( $input['sectors'] ?? array() ) ) );
	$year = (string) law_events_setting( 'year', 2026 );
	if ( '' !== $year ) {
		wp_set_object_terms( $event_id, $year, 'law_year', false );
	}

	// The shared repeater savers, unchanged. Both are reconcilers: they round
	// trip each row's own ID and delete whatever the posted rows do not claim,
	// which is why the sentinel below matters — an absent section must not read
	// as "the committee cleared every session".
	$speakers_before = array_map(
		fn( $row ) => (int) $row['speaker_id'],
		law_event_meta( $event_id, '_law_speakers' )
	);
	$speaker_ids = law_events_form_save_speakers( $event_id, (array) ( $input['speakers'] ?? array() ), $files );

	if ( ! empty( $input['law_sessions_present'] ) ) {
		law_events_form_save_sessions( $event_id, (array) ( $input['sessions'] ?? array() ), $speaker_ids, $speakers_before );
	}
	// The dashboard's "With an agenda" filter reads this switch, so an external
	// event with a running order has to carry it or it would have an agenda and
	// still not show up in the filter for one. Set from the sessions that ended
	// up on the event rather than from the sentinel, so deleting the last
	// session turns it back off.
	law_event_update_meta( $event_id, '_law_session_agenda', law_event_session_ids( $event_id ) ? 1 : 0 );

	law_external_event_log_save( $event_id, $before, law_external_event_snapshot( $event_id ), $actor, $created );

	return $event_id;
}

/**
 * The date and the two times, straight into _law_start / _law_end with
 * _law_slot_label left empty: an external event is not one of the programme's
 * slots, exactly as a reception is not.
 *
 * An end earlier than its start is stored as no end at all rather than as a
 * time that would print backwards. Real data: form 10 entry 1559, Law Rocks!
 * LONDON 2026, was captured as 19:45 to 11:30 — a night that runs past
 * midnight, which this module has no way to represent and must not guess a
 * second date for. The programme then reads "7:45pm onwards", which is true,
 * and the log says so, which is how the committee finds out.
 */
function law_external_event_apply_when( $event_id, array $input, $actor ) {
	$date  = (string) law_events_sanitize_value( $input['external_date'] ?? '', 'date' );
	$start = (string) law_events_sanitize_value( $input['external_start'] ?? '', 'time' );
	$end   = (string) law_events_sanitize_value( $input['external_end'] ?? '', 'time' );

	if ( '' === $date ) {
		law_event_update_meta( $event_id, '_law_start', '' );
		law_event_update_meta( $event_id, '_law_end', '' );
		law_event_update_meta( $event_id, '_law_slot_label', '' );
		return;
	}

	$dropped_end = '' !== $end && '' !== $start && $end <= $start;
	if ( $dropped_end ) {
		law_event_log(
			$event_id,
			sprintf(
				/* translators: 1: end time, 2: start time. */
				__( 'End time %1$s is not after the start time %2$s, so it was not saved and the programme will read "%2$s onwards". Correct it if the event runs past midnight.', 'law' ),
				$end,
				$start
			),
			array( 'action' => 'external_end_dropped', 'source' => 'form', 'start' => $start, 'end' => $end ),
			array( 'user_id' => (int) $actor )
		);
		$end = '';
	}

	law_event_update_meta( $event_id, '_law_start', '' !== $start ? $date . ' ' . $start : $date . ' 00:00' );
	law_event_update_meta( $event_id, '_law_end', '' !== $end ? $date . ' ' . $end : '' );
	law_event_update_meta( $event_id, '_law_slot_label', '' );
}

/* The activity log __________________________________________________________ */

/** The fields worth naming in a log entry when they change. */
function law_external_event_snapshot( $event_id ) {
	$post = get_post( $event_id );
	return array(
		'title'  => $post ? $post->post_title : '',
		'status' => $post ? $post->post_status : '',
		'start'  => (string) law_event_meta( $event_id, '_law_start' ),
		'end'    => (string) law_event_meta( $event_id, '_law_end' ),
		'url'    => (string) law_event_meta( $event_id, '_law_external_url' ),
		'venue'  => (string) law_event_meta( $event_id, '_law_venue' ),
	);
}

/**
 * One activity log entry per save, naming what actually changed — the
 * order-notes style the rest of the module uses, so the committee can answer
 * "who moved this and when" without anybody having to remember.
 */
function law_external_event_log_save( $event_id, array $before, array $after, $actor, $created ) {
	if ( $created ) {
		law_event_log(
			$event_id,
			sprintf(
				/* translators: %s: Draft or On the programme. */
				__( 'External event created (%s).', 'law' ),
				'publish' === $after['status'] ? __( 'on the programme', 'law' ) : __( 'draft', 'law' )
			),
			array( 'action' => 'external_created', 'source' => 'form' ),
			array( 'user_id' => (int) $actor )
		);
		return;
	}

	$labels = array(
		'title' => __( 'Title', 'law' ),
		'start' => __( 'Start', 'law' ),
		'end'   => __( 'End', 'law' ),
		'url'   => __( 'Booking link', 'law' ),
		'venue' => __( 'Venue', 'law' ),
	);

	$changes = array();
	foreach ( $labels as $key => $label ) {
		if ( (string) ( $before[ $key ] ?? '' ) !== (string) ( $after[ $key ] ?? '' ) ) {
			$changes[] = sprintf(
				'%s: "%s" → "%s"',
				$label,
				(string) ( $before[ $key ] ?? '' ),
				(string) ( $after[ $key ] ?? '' )
			);
		}
	}

	if ( ( $before['status'] ?? '' ) !== ( $after['status'] ?? '' ) ) {
		$changes[] = 'publish' === $after['status']
			? __( 'Put on the programme.', 'law' )
			: __( 'Taken off the programme (draft).', 'law' );
	}

	law_event_log(
		$event_id,
		$changes
			? sprintf( __( 'External event saved. %s', 'law' ), implode( ' ', $changes ) )
			: __( 'External event saved; no listed field changed.', 'law' ),
		array( 'action' => 'external_saved', 'source' => 'form' ),
		array( 'user_id' => (int) $actor )
	);
}

/* The save handler __________________________________________________________ */

add_action( 'admin_post_law_external_event', 'law_external_event_handler' );
add_action( 'admin_post_nopriv_law_external_event', 'law_events_nopriv_json' );

/**
 * Read the POST, hand it to the saver, redirect with a notice.
 *
 * Its own rate surface rather than the event submission's: transcribing a
 * running order of thirteen sessions is a long sitting with several saves in
 * it, and it must not spend a budget sized for people submitting one event.
 */
function law_external_event_handler() {
	$is_ajax = law_events_guard_post(
		'law_external_event',
		array(
			'rate'            => array( 'external_event', 30, 600 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'external-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, external events are managed by the committee.', 'status' => 403 ),
			'external-denied'
		);
	}

	$input   = wp_unslash( $_POST );
	$publish = 'publish' === sanitize_key( $input['law_form_action'] ?? 'draft' );
	$result  = law_external_event_save( $input, get_current_user_id(), (array) $_FILES );

	if ( is_wp_error( $result ) ) {
		// The form re-renders from $errors[ field ][0], the same shape
		// law_events_form_state() hands the shared partials, so the messages
		// land next to their own fields rather than in one heap at the top.
		$errors = array();
		foreach ( $result->get_error_codes() as $code ) {
			$errors[ $code ] = $result->get_error_messages( $code );
		}
		law_external_event_store_state( $errors, $input );
		law_events_respond(
			$is_ajax,
			false,
			array(
				'message'  => implode( ' ', array_map( fn( $m ) => $m[0], $errors ) ),
				'field'    => key( $errors ),
				'status'   => 400,
				'redirect' => add_query_arg( 'law_form_error', 1, law_external_event_url( absint( $input['law_external_id'] ?? 0 ) ?: 'new' ) ),
			),
			'external-invalid'
		);
	}

	$notice = $publish ? 'external-published' : 'external-saved';
	$done   = add_query_arg( 'law_notice', $notice, law_external_event_url() );

	if ( $is_ajax ) {
		wp_send_json_success(
			array(
				'title'    => $publish ? 'On the programme' : 'Saved as a draft',
				'message'  => sprintf( '%s has been saved.', get_the_title( (int) $result ) ),
				'redirect' => $done,
			)
		);
	}

	// Explicitly to the list, NOT law_events_respond()'s redirect_back: back is
	// the form this POST came from, so a successful save would land on a blank
	// "Create an external event" with a notice query argument nothing on that
	// branch renders — which reads as the save having silently done nothing
	// (browser pass, 15 September 2026).
	wp_safe_redirect( $done );
	exit;
}

/* Assets ____________________________________________________________________ */

add_action(
	'wp_enqueue_scripts',
	function () {
		// event-form.css and event-form.js already load on this template for the
		// committee's edit view (submission-form.php), and they are what the
		// shared repeaters need. The rich-text editor is the one thing this
		// branch adds, and only on the branch that renders a form.
		if ( ! is_page_template( 'templates/account-dashboard.php' ) || ! law_user_is_committee() ) {
			return;
		}
		if ( ! law_external_event_requested() ) {
			return;
		}
		law_rich_text_enqueue();
	}
);
