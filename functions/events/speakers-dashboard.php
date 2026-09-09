<?php
/**
 * The committee's Manage Speakers dashboard (/account/dashboard/speakers/,
 * templates/account-speakers-dashboard.php): every speaker record in one
 * filterable table, and per speaker an edit view listing all of their event
 * appearances with the fields the committee can change for each one.
 *
 * Why it exists: a speaker's organisation, job title, photo and biography are
 * stored PER APPEARANCE, on the event's _law_speakers row (Denis, 8 September
 * 2026 — one person speaks for different firms at different events). That is
 * right for the listings and wrong for maintenance: correcting a name or a
 * biography meant opening every event that references the person, one at a
 * time, with nothing anywhere answering "where does this speaker appear?".
 *
 * The split also decides what "synced for each event" means on the edit form:
 * the identity fields (full name, email, website) live on the law_speaker post,
 * so one write changes every event at once; role, organisation, job title,
 * photo and biography are written to the one event whose block they sit in.
 * One "Save changes" button posts the lot.
 *
 * Module convention: this domain file owns its query, its AJAX partial, its
 * save handler, its export endpoint and its asset gating.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template the dashboard renders through. */
const LAW_SPEAKERS_DASHBOARD_TEMPLATE = 'templates/account-speakers-dashboard.php';

/** Screen cap on speakers (house style: never -1 on a screen; exports pass -1). */
const LAW_SPEAKERS_DASHBOARD_SCREEN_CAP = 1000;

/**
 * Every event status, which is what an appearance means here: a law_speaker
 * post exists from the first draft save of an event (law_speaker_upsert() runs
 * on every save, not on approval), and an appearance is most worth correcting
 * before the event is confirmed.
 *
 * @return string[]
 */
function law_speakers_dashboard_statuses() {
	return law_event_all_status_keys();
}

/**
 * The filters, read from the request (or an explicit array, for the export
 * and tests) and normalised.
 *
 * @param array|null $source Defaults to $_GET.
 * @return array{kw:string,event:int,year:string}
 */
function law_speakers_dashboard_filters( ?array $source = null ) {
	$source = null === $source ? $_GET : $source;
	return array(
		'kw'    => sanitize_text_field( wp_unslash( (string) ( $source['law_kw'] ?? '' ) ) ),
		'event' => absint( $source['law_event'] ?? 0 ),
		'year'  => sanitize_key( (string) ( $source['law_year'] ?? '' ) ),
	);
}

/**
 * Events that carry at least one speaker row (any status), for the event
 * filter: id => label, ordered by start then title. The status is appended for
 * anything but a Confirmed event, because drafts and proposals show up here and
 * two submissions of the same event share a title.
 *
 * @return array<int,string>
 */
function law_speakers_dashboard_events() {
	$maps = law_speakers_event_maps( law_speakers_dashboard_statuses(), LAW_SPEAKERS_EVENT_SCAN_CAP );

	$ids = array();
	foreach ( $maps['events'] as $event_ids ) {
		foreach ( $event_ids as $event_id ) {
			$ids[ (int) $event_id ] = true;
		}
	}
	if ( ! $ids ) {
		return array();
	}

	$events = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => law_speakers_dashboard_statuses(),
			'post__in'       => array_keys( $ids ),
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$out = array();
	foreach ( $events as $event ) {
		$label = $event->post_title;
		if ( 'publish' !== $event->post_status ) {
			$label .= ' (' . law_event_status_label( $event->post_status ) . ')';
		}
		$out[ (int) $event->ID ] = array(
			'label' => $label,
			'start' => (string) law_event_meta( $event->ID, '_law_start' ),
		);
	}
	uasort( $out, fn( $a, $b ) => strcmp( $a['start'] . $a['label'], $b['start'] . $b['label'] ) );

	return array_map( fn( $e ) => $e['label'], $out );
}

/**
 * Event IDs in one programme year, for the year filter. The law_year taxonomy
 * sits on both speakers and events; the events are what an appearance names, so
 * filtering by the EVENT's year is what "speakers at the 2026 programme" means.
 *
 * @return int[]
 */
function law_speakers_dashboard_year_event_ids( $year ) {
	if ( '' === $year ) {
		return array();
	}
	return array_map(
		'intval',
		get_posts(
			array(
				'post_type'      => LAW_EVENT_CPT,
				'post_status'    => law_speakers_dashboard_statuses(),
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'tax_query'      => array( array( 'taxonomy' => 'law_year', 'field' => 'slug', 'terms' => $year ) ),
			)
		)
	);
}

/**
 * One appearance, decorated with everything the views and the export need
 * about its event.
 *
 * @param array $appearance A law_speaker_appearances() row.
 * @return array|null Null when the event has gone.
 */
function law_speakers_dashboard_appearance( array $appearance ) {
	$event_id = (int) $appearance['event_id'];
	$event    = get_post( $event_id );
	if ( ! $event || LAW_EVENT_CPT !== $event->post_type ) {
		return null;
	}

	return $appearance + array(
		'event_title'  => $event->post_title,
		'event_status' => $event->post_status,
		'event_start'  => (string) law_event_meta( $event_id, '_law_start' ),
		'event_ref'    => (string) law_event_meta( $event_id, '_law_reference' ),
	);
}

/**
 * Every appearance of one speaker, across every event status, decorated for the
 * edit view. Shared by that view, the list and the export, so the three cannot
 * disagree about what a speaker appears at.
 *
 * @param int $speaker_id Speaker post ID.
 * @return array<int,array>
 */
function law_speakers_dashboard_appearances( $speaker_id ) {
	$out = array();
	foreach ( law_speaker_appearances( (int) $speaker_id, law_speakers_dashboard_statuses() ) as $appearance ) {
		$decorated = law_speakers_dashboard_appearance( $appearance );
		if ( $decorated ) {
			$out[] = $decorated;
		}
	}
	return $out;
}

/**
 * The speaker rows for the dashboard and its export.
 *
 * One row per law_speaker post. The headline organisation and job title are
 * those of the earliest appearance on record, the same fall-through the public
 * archive card uses, so the list reads like the archive it maintains. The
 * keyword matches (case-insensitively) the name, email, website, every
 * appearance's organisation and job title, and every event title; it is applied
 * in PHP over the fetched set rather than as a LIKE over serialised meta, which
 * the module never does.
 *
 * @param array $filters law_speakers_dashboard_filters().
 * @param int   $limit   Speaker cap (-1 for the export).
 * @return array{rows:array[],speakers:int,appearances:int,truncated:bool}
 */
function law_speakers_dashboard_rows( array $filters, $limit = LAW_SPEAKERS_DASHBOARD_SCREEN_CAP ) {
	$speakers = get_posts(
		array(
			'post_type'      => LAW_SPEAKER_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => (int) $limit,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	$truncated = $limit > 0 && count( $speakers ) >= $limit;

	$year_events = law_speakers_dashboard_year_event_ids( $filters['year'] );
	if ( '' !== $filters['year'] && ! $year_events ) {
		return array( 'rows' => array(), 'speakers' => 0, 'appearances' => 0, 'truncated' => false );
	}

	$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $filters['kw'] ) : strtolower( $filters['kw'] );
	$needle = trim( $needle );
	$rows   = array();
	$total  = 0;

	foreach ( $speakers as $speaker ) {
		$appearances = law_speakers_dashboard_appearances( (int) $speaker->ID );

		if ( $filters['event'] ) {
			$appearances = array_values( array_filter( $appearances, fn( $a ) => (int) $a['event_id'] === $filters['event'] ) );
			if ( ! $appearances ) {
				continue;
			}
		}
		if ( $year_events ) {
			$appearances = array_values( array_filter( $appearances, fn( $a ) => in_array( (int) $a['event_id'], $year_events, true ) ) );
			if ( ! $appearances ) {
				continue;
			}
		}

		$headline = law_speaker_first_appearance( (int) $speaker->ID, law_speakers_dashboard_statuses() );
		$email    = (string) law_event_meta( $speaker->ID, '_law_speaker_email' );
		$website  = (string) law_event_meta( $speaker->ID, '_law_website' );

		if ( '' !== $needle ) {
			$haystack = array( $speaker->post_title, $email, $website );
			foreach ( $appearances as $appearance ) {
				$haystack[] = $appearance['organisation'];
				$haystack[] = $appearance['job_title'];
				$haystack[] = $appearance['event_title'];
			}
			$haystack = implode( "\n", $haystack );
			$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
			if ( false === strpos( $haystack, $needle ) ) {
				continue;
			}
		}

		$photo_id = law_speaker_display_photo_id( (int) $speaker->ID, (int) $headline['photo_id'] );
		$rows[]   = array(
			'id'           => (int) $speaker->ID,
			'name'         => $speaker->post_title,
			'email'        => $email,
			'website'      => $website,
			'organisation' => $headline['organisation'],
			'job_title'    => $headline['job_title'],
			'photo_id'     => $photo_id,
			'photo'        => law_speaker_photo_url( (int) $speaker->ID, $photo_id, 'thumbnail' ),
			'appearances'  => $appearances,
		);
		$total += count( $appearances );
	}

	return array(
		'rows'        => $rows,
		'speakers'    => count( $rows ),
		'appearances' => $total,
		'truncated'   => $truncated,
	);
}

/**
 * One row set for the three export formats: one row PER APPEARANCE, uncapped,
 * which is the shape programme and badging work needs (a speaker's details are
 * per event, so a row per speaker would have to pick one event's answer). A
 * speaker with no appearance yet still gets a row, with the event columns
 * blank, so nobody is invisible in the export.
 *
 * @return array{title:string,columns:string[],rows:array[]}
 */
function law_speakers_dashboard_export_rows( array $filters ) {
	$data = law_speakers_dashboard_rows( $filters, -1 );
	$rows = array();

	foreach ( $data['rows'] as $row ) {
		if ( ! $row['appearances'] ) {
			$rows[] = array( $row['id'], $row['name'], $row['email'], $row['website'], '', '', '', '', '', '', '', '' );
			continue;
		}
		foreach ( $row['appearances'] as $appearance ) {
			$rows[] = array(
				$row['id'],
				$row['name'],
				$row['email'],
				$row['website'],
				$appearance['event_title'],
				'' !== $appearance['event_start'] ? $appearance['event_start'] : '',
				$appearance['event_ref'],
				law_event_status_label( $appearance['event_status'] ),
				law_speaker_role_display( $appearance['role'] ),
				$appearance['organisation'],
				$appearance['job_title'],
				$appearance['bio'],
			);
		}
	}

	$scope = array();
	if ( $filters['event'] ) {
		$scope[] = get_the_title( $filters['event'] );
	}
	if ( '' !== $filters['year'] ) {
		$scope[] = $filters['year'];
	}
	if ( '' !== $filters['kw'] ) {
		$scope[] = '"' . $filters['kw'] . '"';
	}
	if ( ! $scope ) {
		$scope[] = 'all speakers';
	}

	return array(
		'title'   => 'Speakers: ' . implode( ', ', $scope ),
		'columns' => array( 'Speaker ID', 'Name', 'Email', 'Website', 'Event', 'Event date', 'Reference', 'Event status', 'Role', 'Organisation', 'Job title', 'Biography' ),
		'rows'    => $rows,
	);
}

/* The edit view ______________________________________________________________ */

/** The speaker the edit view was asked for, or 0 for the list. */
function law_speakers_dashboard_requested_speaker() {
	$speaker_id = absint( $_GET['law_speaker'] ?? 0 );
	if ( ! $speaker_id ) {
		return 0;
	}
	$post = get_post( $speaker_id );

	return ( $post && LAW_SPEAKER_CPT === $post->post_type ) ? (int) $post->ID : 0;
}

/** The dashboard URL, optionally addressing one speaker. */
function law_speakers_dashboard_url( $speaker_id = 0 ) {
	$url = law_account_url( 'speakers' );

	return $speaker_id ? add_query_arg( 'law_speaker', (int) $speaker_id, $url ) : $url;
}

/**
 * Transient-backed re-population of a refused save, on the pattern
 * law_events_form_state() set: written by the handler, read ONCE by the view
 * and deleted, so a later visit is not haunted by an old failure.
 *
 * @return array{errors:array<string,string>,input:array}
 */
function law_speakers_dashboard_state() {
	$empty = array( 'errors' => array(), 'input' => array() );
	$key   = 'law_speaker_state_' . get_current_user_id();
	$state = get_transient( $key );
	if ( ! is_array( $state ) ) {
		return $empty;
	}
	delete_transient( $key );

	return array(
		'errors' => (array) ( $state['errors'] ?? array() ),
		'input'  => (array) ( $state['input'] ?? array() ),
	);
}

/* The save handler ___________________________________________________________ */

/**
 * Rewrite ONE speaker's row inside a post's _law_speakers array, leaving every
 * other row (and every row's sort position) exactly where it was. The whole
 * array goes back through law_event_update_meta(), the module's single write
 * path, so the schema sanitiser is still the only thing that cleans a row.
 *
 * @param int   $post_id    Event or session post ID.
 * @param int   $speaker_id Speaker post ID.
 * @param array $values     field => value for the row (role, organisation, job_title, photo_id, bio).
 * @return string[] The fields that actually changed; empty when the speaker is not on this post.
 */
function law_speakers_dashboard_write_row( $post_id, $speaker_id, array $values ) {
	$rows    = law_event_meta( (int) $post_id, '_law_speakers' );
	$found   = false;
	$changed = array();

	foreach ( $rows as $i => $row ) {
		if ( (int) ( $row['speaker_id'] ?? 0 ) !== (int) $speaker_id ) {
			continue;
		}
		$found = true;
		foreach ( $values as $field => $value ) {
			if ( (string) ( $row[ $field ] ?? '' ) !== (string) $value ) {
				$changed[] = $field;
			}
			$rows[ $i ][ $field ] = $value;
		}
	}

	if ( ! $found ) {
		return array();
	}
	law_event_update_meta( (int) $post_id, '_law_speakers', $rows );

	return array_values( array_unique( $changed ) );
}

/**
 * Human labels for the activity log line, so it reads like the rest of the log
 * rather than like meta keys.
 *
 * @return array<string,string>
 */
function law_speakers_dashboard_field_labels() {
	return array(
		'role'         => 'role',
		'organisation' => 'organisation',
		'job_title'    => 'job title',
		'photo_id'     => 'photo',
		'bio'          => 'biography',
	);
}

/**
 * POST admin-post.php?action=law_speaker_manage — the committee's Manage
 * Speakers edit form. One submission carries the identity fields and every
 * appearance block, because there is one "Save changes" button for the lot.
 *
 * The identity fields go on the law_speaker post, so they change on every event
 * at once; each appearance block is written only to its own event (and to that
 * event's session rows, which hold copies).
 */
add_action( 'admin_post_law_speaker_manage', 'law_speakers_dashboard_save_handler' );
add_action( 'admin_post_nopriv_law_speaker_manage', 'law_events_nopriv_json' );
function law_speakers_dashboard_save_handler() {
	// Its own rate surface: a committee member tidying a speaker who appears at
	// a dozen events must not spend the event-submission budget.
	$is_ajax = law_events_guard_post(
		'law_speaker_manage',
		array(
			'rate'            => array( 'speaker_manage', 30, 600 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'speaker-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, managing speakers is for the committee.', 'status' => 403 ), 'speaker-denied' );
	}

	$speaker_id = absint( $_POST['law_speaker_id'] ?? 0 );
	$speaker    = $speaker_id ? get_post( $speaker_id ) : null;
	if ( ! $speaker || LAW_SPEAKER_CPT !== $speaker->post_type ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That speaker could not be found.', 'status' => 404 ), 'speaker-not-found' );
	}
	$speaker_id = (int) $speaker->ID;

	$raw_email = trim( (string) wp_unslash( $_POST['email'] ?? '' ) );
	$input     = array(
		'name'        => sanitize_text_field( (string) wp_unslash( $_POST['name'] ?? '' ) ),
		'email'       => mb_strtolower( sanitize_email( $raw_email ) ),
		'website'     => esc_url_raw( trim( (string) wp_unslash( $_POST['website'] ?? '' ) ) ),
		'appearances' => (array) wp_unslash( $_POST['appearances'] ?? array() ),
	);

	/* Validation ______________________________________________________ */

	$errors = array();
	if ( '' === trim( $input['name'] ) ) {
		$errors['name'] = 'Please give the speaker a full name.';
	}
	if ( '' !== $raw_email && '' === $input['email'] ) {
		$errors['email'] = 'That does not look like an email address.';
	}
	if ( '' === $raw_email ) {
		$input['email'] = '';
	}
	// The email is the dedupe key, so it must not already belong to somebody
	// else: silently merging two speaker records is not something a save button
	// should be able to do.
	if ( '' !== $input['email'] ) {
		$clash = law_speaker_find_existing( $input['email'], '' );
		if ( $clash && $clash !== $speaker_id ) {
			$errors['email'] = sprintf( 'That email already belongs to another speaker record (%s).', get_the_title( $clash ) );
		}
	}
	$errors = array_merge( $errors, law_events_validate_photos( $_FILES ) );

	if ( $errors ) {
		set_transient(
			'law_speaker_state_' . get_current_user_id(),
			array( 'errors' => $errors, 'input' => $input ),
			10 * MINUTE_IN_SECONDS
		);
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Please fix the highlighted fields.', 'errors' => $errors ), 400 );
		}
		wp_safe_redirect( add_query_arg( 'law_speaker_error', 1, law_speakers_dashboard_url( $speaker_id ) ) );
		exit;
	}

	/* Identity: one write, every event __________________________________ */

	// Deliberately NOT law_speaker_upsert(): that helper only ever fills gaps
	// and never blanks a value, so it could not clear a wrong website. post_name
	// is left alone as well, so an existing /speakers/<slug>/ link keeps
	// resolving after a name correction.
	if ( trim( $input['name'] ) !== trim( $speaker->post_title ) ) {
		wp_update_post( array( 'ID' => $speaker_id, 'post_title' => $input['name'] ) );
	}
	law_event_update_meta( $speaker_id, '_law_speaker_email', $input['email'] );
	law_event_update_meta( $speaker_id, '_law_website', $input['website'] );

	/* Appearances: each block to its own event __________________________ */

	$appearances = law_speakers_dashboard_appearances( $speaker_id );
	$allowed     = array_map( fn( $a ) => (int) $a['event_id'], $appearances );
	$existing    = array();
	foreach ( $appearances as $appearance ) {
		$existing[ (int) $appearance['event_id'] ] = $appearance;
	}
	$labels  = law_speakers_dashboard_field_labels();
	$touched = 0;

	foreach ( $input['appearances'] as $event_id => $posted ) {
		$event_id = (int) $event_id;
		// A forged or stale event ID writes nothing: only events this speaker
		// genuinely appears at are writable from here.
		if ( ! is_array( $posted ) || ! in_array( $event_id, $allowed, true ) ) {
			continue;
		}

		// The photo: a fresh upload wins, then the tick that clears it, then
		// whatever the row already holds. The posted photo_id is a display echo
		// and is never trusted, matching law_events_form_save_speakers().
		$photo_id = (int) ( $existing[ $event_id ]['photo_id'] ?? 0 );
		// is_array() as well as the emptiness check, matching the guard in
		// law_events_validate_photos(): the parallel-array shape is what a
		// speaker_photo[<event_id>] field produces, and a differently shaped
		// payload must skip the upload rather than index into a string.
		$batch    = $_FILES['speaker_photo'] ?? null;
		if ( $batch && is_array( $batch['name'] ?? null ) && ! empty( $batch['name'][ $event_id ] ) ) {
			$uploaded = law_events_sideload_upload(
				array(
					'name'     => $batch['name'][ $event_id ],
					'type'     => $batch['type'][ $event_id ],
					'tmp_name' => $batch['tmp_name'][ $event_id ],
					'error'    => $batch['error'][ $event_id ],
					'size'     => $batch['size'][ $event_id ],
				)
			);
			if ( $uploaded ) {
				$photo_id = $uploaded;
			}
		} elseif ( ! empty( $posted['remove_photo'] ) ) {
			$photo_id = 0;
		}

		$values = array(
			'role'         => law_speaker_role_key( (string) ( $posted['role'] ?? '' ) ),
			'organisation' => sanitize_text_field( (string) ( $posted['organisation'] ?? '' ) ),
			'job_title'    => sanitize_text_field( (string) ( $posted['job_title'] ?? '' ) ),
			'photo_id'     => $photo_id,
			'bio'          => sanitize_textarea_field( (string) ( $posted['bio'] ?? '' ) ),
		);

		$changed = law_speakers_dashboard_write_row( $event_id, $speaker_id, $values );

		// Session rows hold COPIES of the event row (law_events_form_save_sessions()
		// stamps them from it), so writing only the event row would leave the
		// session panels on the event page showing the old text. Mirroring them is
		// what a host save already does; the known cost is that a per-session role
		// override set in wp-admin is overwritten.
		foreach ( law_event_session_ids( $event_id ) as $session_id ) {
			law_speakers_dashboard_write_row( $session_id, $speaker_id, $values );
		}

		if ( ! $changed ) {
			continue;
		}
		$touched++;
		$named = array_map( fn( $field ) => $labels[ $field ] ?? $field, $changed );
		law_event_log(
			$event_id,
			sprintf(
				'Speaker "%s" updated for this event by the committee: %s.',
				$input['name'],
				implode( ', ', $named )
			),
			array(
				'action'  => 'speaker_appearance_updated',
				'source'  => 'speakers_dashboard',
				'speaker' => $speaker_id,
				'fields'  => $changed,
			)
		);
	}

	// The appearance maps are memoised per request; this request just changed
	// them, so anything rendered after it must not read the old pass.
	law_speakers_flush_maps();

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Speaker updated',
			'message'  => $touched
				? sprintf( _n( 'Saved, including %s event appearance.', 'Saved, including %s event appearances.', $touched, 'law' ), number_format_i18n( $touched ) )
				: 'Saved.',
			'redirect' => add_query_arg( 'law_notice', 'speaker-saved', law_speakers_dashboard_url( $speaker_id ) ),
		),
		'speaker-saved'
	);
}

/* AJAX partial _______________________________________________________________ */

/**
 * &law_partial=1 on the dashboard returns only the table markup so the filter
 * bar (calendar-filters.js) can swap it in place; the page's Members
 * restriction and the committee check are both re-applied, as on the events and
 * bookings dashboards.
 */
add_action( 'template_redirect', 'law_speakers_dashboard_maybe_render_partial' );
function law_speakers_dashboard_maybe_render_partial() {
	if ( empty( $_GET['law_partial'] ) || ! is_page_template( LAW_SPEAKERS_DASHBOARD_TEMPLATE ) ) {
		return;
	}
	$page_id = get_queried_object_id();
	if ( function_exists( 'members_can_current_user_view_post' ) && $page_id && ! members_can_current_user_view_post( $page_id ) ) {
		status_header( 403 );
		exit;
	}
	if ( ! law_user_is_committee() ) {
		status_header( 403 );
		exit;
	}
	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	nocache_headers();
	get_template_part( 'parts/events/speakers-dashboard-list' );
	exit;
}

/* Export _____________________________________________________________________ */

/**
 * GET admin-post.php?action=law_speakers_dashboard_export&format=csv|xlsx|json
 * plus the filter params. Committee only, modelled on
 * law_bookings_dashboard_export_handler(): the json branch verifies the nonce by
 * hand so the PDF fetch gets a parseable 403.
 */
add_action( 'admin_post_law_speakers_dashboard_export', 'law_speakers_dashboard_export_handler' );
function law_speakers_dashboard_export_handler() {
	nocache_headers();
	$format = sanitize_key( $_GET['format'] ?? 'csv' );

	if ( 'json' === $format && ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'law_speakers_dashboard_export' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_speakers_dashboard_export' );

	if ( ! law_user_is_committee() ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Sorry, this export is for the committee.' ), 403 );
		}
		wp_die( 'Sorry, this export is for the committee.' );
	}

	// The export is deliberately uncapped where the screen caps at
	// LAW_SPEAKERS_DASHBOARD_SCREEN_CAP — the committee needs the whole set —
	// so it is the one place a stolen committee session could ask for an
	// unbounded pass over every speaker and every event in a loop. The budget
	// is deliberately generous: one visit legitimately spends three requests
	// (CSV, Excel, and the PDF's json fetch), and a committee member re-exports
	// as they narrow the filters.
	if ( ! law_events_rate_limit_ok( 'speakers_export', get_current_user_id(), 40, 600 ) ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Too many exports in a short time; please wait a moment and try again.' ), 429 );
		}
		wp_die( 'Too many exports in a short time; please wait a moment and try again.', '', array( 'response' => 429 ) );
	}

	$data     = law_speakers_dashboard_export_rows( law_speakers_dashboard_filters() );
	$basename = 'speakers-' . gmdate( 'Ymd-His' );

	if ( 'xlsx' === $format ) {
		law_events_send_xlsx( $data['columns'], $data['rows'], $basename . '.xlsx', $data['title'] );
	}
	if ( 'json' === $format ) {
		wp_send_json_success(
			array(
				'title'    => $data['title'],
				'filename' => $basename . '.pdf',
				'columns'  => $data['columns'],
				'rows'     => $data['rows'],
			)
		);
	}
	law_events_send_csv( $data['columns'], $data['rows'], $basename . '.csv', $data['title'] );
}

/* Assets _____________________________________________________________________ */

/**
 * The export trio's scripts, committee only (pdfmake is ~3MB, footer-loaded).
 * The filter bar's CSS/JS and the form styles ride the shared template
 * conditions in functions/enqueue.php and submission-form.php.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page_template( LAW_SPEAKERS_DASHBOARD_TEMPLATE ) || ! law_user_is_committee() ) {
		return;
	}
	$mtime = function ( $rel ) {
		return filemtime( get_theme_file_path( $rel ) );
	};
	wp_enqueue_script( 'law-pdfmake', get_theme_file_uri( 'assets/js/vendor/pdfmake.min.js' ), array(), $mtime( 'assets/js/vendor/pdfmake.min.js' ), true );
	wp_enqueue_script( 'law-pdfmake-fonts', get_theme_file_uri( 'assets/js/vendor/vfs_fonts.js' ), array( 'law-pdfmake' ), $mtime( 'assets/js/vendor/vfs_fonts.js' ), true );
	wp_enqueue_script( 'law-export-buttons', get_theme_file_uri( 'assets/js/export-buttons.js' ), array( 'law-pdfmake-fonts' ), $mtime( 'assets/js/export-buttons.js' ), true );
} );
