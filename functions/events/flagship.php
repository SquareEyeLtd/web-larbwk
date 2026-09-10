<?php
/**
 * The flagship conference (FLAGSHIP_UI.md).
 *
 * The flagship is ONE law_event post, flagged _law_is_flagship, whose post_name
 * is "flagship" so the CPT's own rewrite (slug "events") gives it the permalink
 * /events/flagship/. Its sessions are child law_session posts, exactly like any
 * other event with an agenda, and it is edited on one wp-admin screen
 * (admin/flagship-screen.php) rather than through the host submission form.
 *
 * Why a post and not a settings option plus a page template, which is how it
 * was first specified: a WordPress page at /events/flagship would be swallowed
 * by the law_event rewrite rule (there is no /events page; the programme is
 * /programme/), and sessions held in an option would be invisible to the
 * speaker directory, the speaker cards, the .ics feed and the bookings engine,
 * which the approval-gated application flow (EVENTS_4.2_SPECS.md §5) will need
 * to book against.
 *
 * Three values are DERIVED here and nowhere else (law_flagship_recompute()):
 * _law_start and _law_end from the fixed date plus the sessions' earliest start
 * and latest end, and the event-level _law_speakers as the deduped union of the
 * sessions' rows, which is what puts flagship speakers on the speakers archive
 * and their own profiles without any read-side change. _law_slot_label is
 * always empty and law_event_apply_slot_label() is never called for it: the
 * flagship holds no slot, and a blank label would blank its datetimes.
 *
 * Deliberately absent: fee, invoice, workflow transition, _law_approved_at,
 * payment meta and any booking control. Saving the flagship writes the post
 * directly; nothing in the module reacts to a law_event status change except
 * the nonce-gated admin save handlers and a bookings-only hook.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The flagship post's slug, hence /events/flagship/. */
const LAW_FLAGSHIP_SLUG = 'flagship';

/**
 * The flagship post ID, 0 when it has not been created yet.
 *
 * Memoised, because the programme render and the calendar helpers ask
 * repeatedly. The result goes through a filter so the tests can point every
 * helper at a fixture instead of the site's real flagship post.
 *
 * @param bool $reset Clear the memo first (after creating or saving it).
 */
function law_flagship_event_id( $reset = false ) {
	static $id = null;

	if ( $reset ) {
		$id = null;
	}
	if ( null === $id ) {
		$posts = get_posts(
			array(
				'post_type'        => LAW_EVENT_CPT,
				'post_status'      => law_event_all_status_keys(),
				'meta_key'         => '_law_is_flagship', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => '1',                // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'fields'           => 'ids',
				'posts_per_page'   => 1,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => false,
			)
		);
		$id = $posts ? (int) $posts[0] : 0;
	}

	return (int) apply_filters( 'law_flagship_event_id', $id );
}

/** True when this post is the flagship. */
function law_flagship_is( $post_id ) {
	$post_id = (int) $post_id;
	return $post_id > 0 && $post_id === law_flagship_event_id();
}

/** 2 December of the programme year: the flagship's default date. */
function law_flagship_default_date() {
	$year = (int) law_events_setting( 'year', 2026 );
	if ( $year < 2000 ) {
		$year = (int) gmdate( 'Y' );
	}
	return sprintf( '%04d-12-02', $year );
}

/**
 * The flagship's date (Y-m-d): its own meta, else the date half of a derived
 * start, else the default. Never empty, so the programme always has a day to
 * pin the block under.
 *
 * @param int $event_id Defaults to the flagship.
 */
function law_flagship_date( $event_id = 0 ) {
	$event_id = (int) $event_id ? (int) $event_id : law_flagship_event_id();
	if ( $event_id ) {
		$date = (string) law_event_meta( $event_id, '_law_flagship_date' );
		if ( '' !== $date ) {
			return $date;
		}
		$start = (string) law_event_meta( $event_id, '_law_start' );
		if ( '' !== $start ) {
			return substr( $start, 0, 10 );
		}
	}
	return law_flagship_default_date();
}

/* -------------------------------------------------------------------------
 * Pricing (FLAGSHIP_PAYMENTS.md §2.3)
 *
 * The flagship costs one price up to a cutover datetime and a higher one
 * afterwards, both stored NET of VAT in pence. This is the theme's first
 * date-based price switch, so there is no pattern to copy and the timezone
 * handling is deliberate: the comparison is built through wp_timezone(), never
 * strtotime() on a bare string, because London is on BST until late October
 * and an hour's drift would sell the cheaper place into the 17th.
 * ---------------------------------------------------------------------- */

/** Default prices in pence, net of VAT (Denis, 10 September 2026). */
const LAW_FLAGSHIP_PRICE_DEFAULT      = 55000;
const LAW_FLAGSHIP_PRICE_LATE_DEFAULT = 60000;

/** 17 October of the programme year, 00:00 site time: the default cutover. */
function law_flagship_default_price_switch() {
	$year = (int) law_events_setting( 'year', 2026 );
	if ( $year < 2000 ) {
		$year = (int) gmdate( 'Y' );
	}
	return sprintf( '%04d-10-17 00:00', $year );
}

/**
 * The cutover as a site-local timestamp, 0 when unset or unparseable.
 *
 * Stored values are naive 'Y-m-d H:i' in site time, as everywhere else in the
 * module, so they are read back with the site's own timezone attached rather
 * than the server's.
 *
 * @param int $event_id Defaults to the flagship.
 */
function law_flagship_price_switch_ts( $event_id = 0 ) {
	$event_id = (int) $event_id ? (int) $event_id : law_flagship_event_id();
	$stored   = $event_id ? (string) law_event_meta( $event_id, '_law_flagship_price_switch' ) : '';
	if ( '' === $stored ) {
		$stored = law_flagship_default_price_switch();
	}
	try {
		$when = new DateTimeImmutable( $stored, wp_timezone() );
	} catch ( Exception $e ) {
		return 0;
	}
	return $when->getTimestamp();
}

/**
 * Has the price switched yet?
 *
 * @param int $at       Unix timestamp; defaults to now.
 * @param int $event_id Defaults to the flagship.
 */
function law_flagship_price_is_late( $at = 0, $event_id = 0 ) {
	$at     = (int) $at ? (int) $at : (int) current_time( 'timestamp', true );
	$switch = law_flagship_price_switch_ts( $event_id );
	return $switch > 0 && $at >= $switch;
}

/**
 * The list price in pence, net of VAT, at a moment in time.
 *
 * Two distinct zeroes, which is why this is not a one-liner:
 *
 * - the key has NEVER been written (the flagship post predates this feature,
 *   or a fresh environment just provisioned it) — fall back to the agreed
 *   default, so a git push alone puts the right price on the page;
 * - the committee has deliberately typed 0 — the event is not on sale, and
 *   the application control renders its not-open state rather than handing
 *   out free places.
 *
 * The late price falls back to its OWN default, never to the early one: an
 * unconfigured late price must not quietly keep charging £550 after the
 * cutover.
 *
 * @param int $at       Unix timestamp; defaults to now.
 * @param int $event_id Defaults to the flagship.
 */
function law_flagship_price_pence( $at = 0, $event_id = 0 ) {
	$event_id = (int) $event_id ? (int) $event_id : law_flagship_event_id();
	if ( ! $event_id ) {
		return 0;
	}
	if ( law_flagship_price_is_late( $at, $event_id ) ) {
		$key     = '_law_flagship_price_late_pence';
		$default = LAW_FLAGSHIP_PRICE_LATE_DEFAULT;
	} else {
		$key     = '_law_flagship_price_pence';
		$default = LAW_FLAGSHIP_PRICE_DEFAULT;
	}
	$stored = get_post_meta( $event_id, $key, true );
	if ( '' === $stored || null === $stored ) {
		return $default;
	}
	return max( 0, (int) $stored );
}

/**
 * A stored price as the pounds string the form field shows: '' when the key
 * has never been written, so the field renders the default rather than a
 * misleading 0.00.
 */
function law_flagship_price_pounds_field( $event_id, $key ) {
	$event_id = (int) $event_id;
	if ( ! $event_id ) {
		$default = '_law_flagship_price_late_pence' === $key
			? LAW_FLAGSHIP_PRICE_LATE_DEFAULT
			: LAW_FLAGSHIP_PRICE_DEFAULT;
		return number_format( $default / 100, 2, '.', '' );
	}
	$stored = get_post_meta( $event_id, $key, true );
	if ( '' === $stored || null === $stored ) {
		$default = '_law_flagship_price_late_pence' === $key
			? LAW_FLAGSHIP_PRICE_LATE_DEFAULT
			: LAW_FLAGSHIP_PRICE_DEFAULT;
		return number_format( $default / 100, 2, '.', '' );
	}
	return number_format( max( 0, (int) $stored ) / 100, 2, '.', '' );
}

/**
 * A typed cutover as the normalised site-local 'Y-m-d H:i' the schema stores,
 * or null when it cannot be read as a date at all.
 *
 * Null rather than '' so a caller can tell "the committee typed nonsense",
 * which is refused, from "the field was left blank", which means leave the
 * stored value alone.
 */
function law_flagship_parse_price_switch( $typed ) {
	$typed = trim( (string) $typed );
	if ( '' === $typed ) {
		return null;
	}
	try {
		$when = new DateTimeImmutable( $typed, wp_timezone() );
	} catch ( Exception $e ) {
		return null;
	}
	return $when->format( 'Y-m-d H:i' );
}

/**
 * "Delegates pay £660.00 including VAT until 16 October, then £720.00." One
 * sentence, built once, printed by both flagship screens so the wp-admin and
 * committee forms can never explain the pricing differently.
 *
 * @param string $early_typed  Pounds as typed in the early-price field.
 * @param string $late_typed   Pounds as typed in the late-price field.
 * @param string $switch_local Site-local 'Y-m-d H:i' cutover.
 */
function law_flagship_price_preview_line( $early_typed, $late_typed, $switch_local ) {
	$early = law_events_pounds_to_pence( $early_typed );
	$late  = law_events_pounds_to_pence( $late_typed );
	if ( null === $early || null === $late ) {
		return '';
	}
	if ( $early < 1 && $late < 1 ) {
		return 'Both prices are zero, so the flagship is not on sale and the page shows no Apply button.';
	}

	// The day BEFORE the cutover is what a delegate reads as the deadline: a
	// switch at midnight on the 17th means "until the 16th", and printing the
	// 17th here would contradict the button copy.
	$last_day = '';
	try {
		$when = new DateTimeImmutable( (string) $switch_local, wp_timezone() );
		$last_day = $when->modify( '-1 day' )->format( 'j F' );
	} catch ( Exception $e ) {
		$last_day = '';
	}

	return sprintf(
		'Delegates pay %1$s including VAT (%2$s + VAT)%3$s, then %4$s including VAT (%5$s + VAT).',
		law_events_format_pence( law_events_gross_pence( $early ) ),
		law_events_format_pence( $early ),
		'' !== $last_day ? ' until the end of ' . $last_day : '',
		law_events_format_pence( law_events_gross_pence( $late ) ),
		law_events_format_pence( $late )
	);
}

/** The Flagship screen. */
function law_flagship_admin_url() {
	return admin_url( 'edit.php?post_type=' . LAW_EVENT_CPT . '&page=law-flagship' );
}

/**
 * The flagship's public URL, /events/flagship/.
 *
 * get_permalink() cannot be used on its own here: for a post that is not
 * published WordPress returns the unpretty ?post_type=law_event&p=<id> form,
 * which is what the Flagship screen was showing before it was ticked on to the
 * programme, and following that URL resolves nothing useful. The address a
 * draft flagship WILL have is knowable, so it is built from the post type's own
 * rewrite base and the post's slug instead.
 *
 * @param int $event_id Defaults to the flagship.
 */
function law_flagship_public_url( $event_id = 0 ) {
	$event_id = (int) $event_id ? (int) $event_id : law_flagship_event_id();
	$post     = $event_id ? get_post( $event_id ) : null;
	if ( ! $post ) {
		return '';
	}
	if ( 'publish' === $post->post_status ) {
		return (string) get_permalink( $post );
	}

	$object = get_post_type_object( LAW_EVENT_CPT );
	$base   = is_object( $object ) && is_array( $object->rewrite ?? null ) ? (string) ( $object->rewrite['slug'] ?? '' ) : '';
	$slug   = $post->post_name ? $post->post_name : LAW_FLAGSHIP_SLUG;
	if ( '' === $base ) {
		return (string) get_permalink( $post );
	}
	return home_url( '/' . trim( $base, '/' ) . '/' . $slug . '/' );
}

/**
 * Create the flagship post when it is missing, so a git deploy alone is enough
 * (FLAGSHIP_UI.md §4.9). Called from the migration's step 10, the
 * ?setup-account-pages trigger and the Flagship screen's first open, all of
 * which may run in any order and more than once.
 *
 * @param bool $dry Report what would happen, change nothing.
 * @return array{id:int, created:bool, message:string}
 */
function law_flagship_ensure_post( $dry = false ) {
	$id = law_flagship_event_id( true );
	if ( $id ) {
		return array(
			'id'      => $id,
			'created' => false,
			'message' => sprintf( 'Flagship event exists (post %d, %s).', $id, get_permalink( $id ) ?: '/events/flagship/' ),
		);
	}

	// A law_event already holding the slug would push the new post to
	// /events/flagship-2/, which is worth saying out loud rather than leaving
	// someone to find the odd URL later.
	$clash = get_page_by_path( LAW_FLAGSHIP_SLUG, OBJECT, LAW_EVENT_CPT );

	if ( $dry ) {
		return array(
			'id'      => 0,
			'created' => false,
			'message' => $clash
				? sprintf( 'Would create the flagship event; post %d already holds the slug, so it would land on /events/flagship-2/.', (int) $clash->ID )
				: 'Would create the flagship event "Flagship conference" (law-draft, /events/flagship/).',
		);
	}

	$id = wp_insert_post(
		wp_slash(
			array(
				'post_type'    => LAW_EVENT_CPT,
				'post_status'  => 'law-draft',
				'post_title'   => 'Flagship conference',
				'post_name'    => LAW_FLAGSHIP_SLUG,
				'post_author'  => get_current_user_id(),
				'post_content' => '',
			)
		),
		true
	);

	if ( is_wp_error( $id ) ) {
		return array(
			'id'      => 0,
			'created' => false,
			'message' => 'ERROR could not create the flagship event: ' . $id->get_error_message(),
		);
	}

	$id = (int) $id;
	law_event_update_meta( $id, '_law_is_flagship', 1 );
	law_event_update_meta( $id, '_law_flagship_date', law_flagship_default_date() );

	// The programme-year term keeps a 2026 event from re-filing into 2027 and is
	// what the admin list's year filter reads.
	$year = (string) law_events_setting( 'year', 2026 );
	if ( '' !== $year ) {
		wp_set_object_terms( $id, $year, 'law_year', false );
	}

	law_flagship_event_id( true );
	law_event_log( $id, 'Flagship event created.', array( 'action' => 'flagship_created', 'source' => 'setup' ) );

	$post    = get_post( $id );
	$message = sprintf( 'Created the flagship event (post %d, %s).', $id, get_permalink( $id ) );
	if ( $post && LAW_FLAGSHIP_SLUG !== $post->post_name ) {
		$message .= sprintf( ' Note: the slug is "%s", not "flagship", because another event already held it.', $post->post_name );
	}

	return array( 'id' => $id, 'created' => true, 'message' => $message );
}

/**
 * The event's start and end, derived from its date and its sessions.
 *
 * Both are '' when no session has a start, which is what keeps a flagship
 * nobody has written an agenda for out of the public programme's slot bars.
 * A session with a start but no end falls back to the latest start, so the
 * event can never end before it begins.
 *
 * @return array{0:string, 1:string} [ start, end ] as 'Y-m-d H:i'.
 */
function law_flagship_compute_range( $event_id, $date ) {
	$date = (string) law_events_sanitize_value( $date, 'date' );
	if ( '' === $date ) {
		return array( '', '' );
	}

	$starts = array();
	$ends   = array();
	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		$start = (string) law_event_meta( $session_id, '_law_start_time' );
		$end   = (string) law_event_meta( $session_id, '_law_end_time' );
		if ( '' !== $start ) {
			$starts[] = $start;
		}
		if ( '' !== $end ) {
			$ends[] = $end;
		}
	}

	if ( ! $starts ) {
		return array( '', '' );
	}

	sort( $starts );
	$first = $starts[0];
	$last  = end( $starts );

	if ( $ends ) {
		sort( $ends );
		$latest_end = end( $ends );
	} else {
		$latest_end = '';
	}

	// A session that runs later than the latest recorded end (a session with no
	// end time at all) must not shorten the event.
	if ( '' === $latest_end || $latest_end < $last ) {
		$latest_end = $last;
	}

	return array( $date . ' ' . $first, $latest_end === $first ? '' : $date . ' ' . $latest_end );
}

/**
 * The deduped union of the sessions' speaker rows, in session order.
 *
 * The first appearance of a speaker wins, so the organisation, job title,
 * photo and biography on the event row are the ones entered for their first
 * session. This is what law_speakers_event_maps() and the speakers archive
 * read, so a flagship speaker needs no special case anywhere on the read side.
 */
function law_flagship_union_speakers( $event_id ) {
	$rows = array();
	$seen = array();

	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		foreach ( law_event_meta( $session_id, '_law_speakers' ) as $row ) {
			$speaker_id = absint( $row['speaker_id'] ?? 0 );
			if ( ! $speaker_id || isset( $seen[ $speaker_id ] ) ) {
				continue;
			}
			$seen[ $speaker_id ] = true;
			$row['sort']         = count( $rows );
			$rows[]              = $row;
		}
	}

	return $rows;
}

/**
 * The single derived-data writer: start, end, the speakers union, and the slot
 * label cleared. Call it after ANY change to the flagship's sessions, wherever
 * that change came from.
 */
function law_flagship_recompute( $event_id ) {
	$event_id = (int) $event_id;
	if ( $event_id < 1 ) {
		return;
	}

	list( $start, $end ) = law_flagship_compute_range( $event_id, law_flagship_date( $event_id ) );

	law_event_update_meta( $event_id, '_law_start', $start );
	law_event_update_meta( $event_id, '_law_end', $end );
	// The flagship holds no slot. Written (as an empty value, which deletes the
	// row) rather than left alone, so an edit made on the wp-admin event screen
	// before the guard was in place cannot leave a stale label behind.
	law_event_update_meta( $event_id, '_law_slot_label', '' );
	law_event_update_meta( $event_id, '_law_speakers', law_flagship_union_speakers( $event_id ) );

	if ( function_exists( 'law_speakers_flush_maps' ) ) {
		law_speakers_flush_maps();
	}
}

/**
 * The hero / preview image for an event: '' when none is set or the attachment
 * has since been deleted. Only the flagship carries _law_hero_image_id today,
 * but the helper is event-shaped so the feature can be widened without a
 * second code path.
 */
function law_event_hero_image_url( $post_id, $size = 'large' ) {
	$attachment_id = absint( law_event_meta( $post_id, '_law_hero_image_id' ) );
	if ( ! $attachment_id ) {
		return '';
	}
	$url = wp_get_attachment_image_url( $attachment_id, $size );
	return $url ? $url : '';
}

/* Keeping the derived values honest when the change came from elsewhere ______ */

/**
 * The wp-admin Sessions screen can still edit a flagship session, and the
 * Sessions list can still trash or delete one, so the derived start, end and
 * speakers union are refreshed from those paths too.
 */
add_action(
	'save_post_' . LAW_SESSION_CPT,
	function ( $post_id, $post ) {
		if ( $post && $post->post_parent && law_flagship_is( $post->post_parent ) ) {
			law_flagship_recompute( $post->post_parent );
		}
	},
	20,
	2
);

foreach ( array( 'deleted_post', 'trashed_post', 'untrashed_post' ) as $law_flagship_hook ) {
	add_action(
		$law_flagship_hook,
		function ( $post_id, $post = null ) {
			$post = $post instanceof WP_Post ? $post : get_post( $post_id );
			if ( $post && LAW_SESSION_CPT === $post->post_type && $post->post_parent && law_flagship_is( $post->post_parent ) ) {
				law_flagship_recompute( $post->post_parent );
			}
		},
		10,
		2
	);
}
unset( $law_flagship_hook );

/* The data layer: reading a submission, validating it and writing it ________
 *
 * Shared by BOTH editing screens — the wp-admin one
 * (admin/flagship-screen.php) and the committee's front-end dashboard
 * (flagship-dashboard.php) — so "the dashboard updates the same data as the
 * admin screen" is true by construction rather than by two implementations
 * agreeing. Neither screen writes anything itself: each collects a POST, hands
 * it to law_flagship_save(), and renders whatever comes back.
 */

/**
 * The values array every part of this screen shares. Shape:
 *
 *  title, description (HTML), date (Y-m-d), venue, hero_image_id (int),
 *  show (bool), sessions_present (bool),
 *  sessions[] => id, title, start, end, description, speakers[] =>
 *      speaker_id, is_new, first_name, last_name, email, website,
 *      role, organisation, job_title, photo_id, bio
 */
function law_flagship_form_values( $event_id ) {
	$event_id = (int) $event_id;
	$post     = $event_id ? get_post( $event_id ) : null;

	$values = array(
		'title'            => $post ? $post->post_title : 'Flagship conference',
		'description'      => $post ? $post->post_content : '',
		'date'             => law_flagship_date( $event_id ),
		'venue'            => $event_id ? (string) law_event_meta( $event_id, '_law_venue' ) : '',
		'hero_image_id'    => $event_id ? absint( law_event_meta( $event_id, '_law_hero_image_id' ) ) : 0,
		'show'             => $post && 'publish' === $post->post_status,
		// Bookings and pricing (FLAGSHIP_PAYMENTS.md §2.2). Prices are edited
		// in pounds and stored in pence, so the form shows the pounds figure
		// and the reader multiplies. law_flagship_price_pence() supplies the
		// agreed default when a key has never been written, which is what lets
		// a git push alone put the right price on the page.
		'places'           => $event_id ? absint( law_event_meta( $event_id, '_law_tickets_available' ) ) : 0,
		'price'            => law_flagship_price_pounds_field( $event_id, '_law_flagship_price_pence' ),
		'price_late'       => law_flagship_price_pounds_field( $event_id, '_law_flagship_price_late_pence' ),
		'price_switch'     => $event_id && '' !== (string) law_event_meta( $event_id, '_law_flagship_price_switch' )
			? (string) law_event_meta( $event_id, '_law_flagship_price_switch' )
			: law_flagship_default_price_switch(),
		'sessions_present' => true,
		'sessions'         => array(),
	);

	if ( ! $event_id ) {
		return $values;
	}

	// Read the sessions as EDITABLE rows. law_event_session_rows() is the
	// display shape (its speakers are rendered cards), which cannot round-trip
	// through a form.
	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		$session = get_post( $session_id );
		if ( ! $session ) {
			continue;
		}
		$speakers = array();
		foreach ( law_event_meta( $session_id, '_law_speakers' ) as $row ) {
			$speakers[] = array(
				'speaker_id'   => absint( $row['speaker_id'] ?? 0 ),
				'is_new'       => false,
				'first_name'   => '',
				'last_name'    => '',
				'email'        => '',
				'website'      => '',
				'role'         => (string) ( $row['role'] ?? '' ),
				'organisation' => (string) ( $row['organisation'] ?? '' ),
				'job_title'    => (string) ( $row['job_title'] ?? '' ),
				'photo_id'     => absint( $row['photo_id'] ?? 0 ),
				'bio'          => (string) ( $row['bio'] ?? '' ),
			);
		}
		$values['sessions'][] = array(
			'id'          => (int) $session_id,
			'title'       => $session->post_title,
			'start'       => (string) law_event_meta( $session_id, '_law_start_time' ),
			'end'         => (string) law_event_meta( $session_id, '_law_end_time' ),
			'description' => $session->post_content,
			'speakers'    => $speakers,
		);
	}

	return $values;
}

/* Reading the POST __________________________________________________________ */

/**
 * One recursive unslash, then per-key sanitisation. Everything this screen
 * writes comes through here, so there is one cleaning path and no value is
 * unslashed twice.
 */
function law_flagship_input_from_post() {
	$raw = isset( $_POST['law_flagship'] ) ? wp_unslash( (array) $_POST['law_flagship'] ) : array();

	return array(
		'title'            => sanitize_text_field( (string) ( $raw['title'] ?? '' ) ),
		'description'      => law_rich_text_sanitize( $raw['description'] ?? '' ),
		'date'             => law_events_sanitize_value( $raw['date'] ?? '', 'date' ),
		'venue'            => sanitize_text_field( (string) ( $raw['venue'] ?? '' ) ),
		'hero_image_id'    => absint( $raw['hero_image_id'] ?? 0 ),
		'show'             => ! empty( $raw['show'] ),
		'places'           => absint( $raw['places'] ?? 0 ),
		// Typed in pounds, kept as the typed string here so validation can
		// tell "0" (deliberately not on sale) from "" (leave it alone) and
		// from "abc" (a typo worth refusing rather than silently zeroing).
		'price'            => trim( (string) ( $raw['price'] ?? '' ) ),
		'price_late'       => trim( (string) ( $raw['price_late'] ?? '' ) ),
		// Raw, like the prices: sanitising here would turn a typo into '' and
		// validation could no longer tell it from a field left blank, so the
		// mistake would be swallowed instead of reported.
		'price_switch'     => trim( (string) ( $raw['price_switch'] ?? '' ) ),
		// The sentinel: "the repeater was on the form". Without it a POST that
		// PHP truncated at max_input_vars would read as "the committee deleted
		// every session" and wipe the agenda.
		'sessions_present' => ! empty( $raw['sessions_present'] ),
		'sessions'         => law_flagship_sessions_from_post( (array) ( $raw['sessions'] ?? array() ) ),
	);
}

function law_flagship_sessions_from_post( array $raw ) {
	$sessions = array();

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$session = array(
			'id'          => absint( $row['id'] ?? 0 ),
			'title'       => sanitize_text_field( (string) ( $row['title'] ?? '' ) ),
			'start'       => law_events_sanitize_value( $row['start'] ?? '', 'time' ),
			'end'         => law_events_sanitize_value( $row['end'] ?? '', 'time' ),
			'description' => law_rich_text_sanitize( $row['description'] ?? '' ),
			'speakers'    => law_flagship_speaker_rows_from_post( (array) ( $row['speakers'] ?? array() ) ),
		);

		// A row the committee cleared to delete it: nothing typed, nobody
		// speaking. Its posted id is machinery, not content, so it does not keep
		// an emptied row alive.
		if ( '' === $session['title'] && '' === $session['start'] && '' === $session['end']
			&& law_rich_text_is_empty( $session['description'] ) && ! $session['speakers'] ) {
			continue;
		}

		$sessions[] = $session;
	}

	return array_values( $sessions );
}

function law_flagship_speaker_rows_from_post( array $raw ) {
	$rows = array();

	foreach ( $raw as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$is_new     = ! empty( $row['is_new'] );
		$speaker_id = absint( $row['speaker_id'] ?? 0 );
		if ( ! $is_new && ! $speaker_id ) {
			continue;
		}
		$rows[] = array(
			'speaker_id'   => $speaker_id,
			'is_new'       => $is_new,
			'first_name'   => sanitize_text_field( (string) ( $row['first_name'] ?? '' ) ),
			'last_name'    => sanitize_text_field( (string) ( $row['last_name'] ?? '' ) ),
			'email'        => sanitize_email( (string) ( $row['email'] ?? '' ) ),
			'website'      => esc_url_raw( (string) ( $row['website'] ?? '' ) ),
			'role'         => law_speaker_role_key( $row['role'] ?? '' ),
			'organisation' => sanitize_text_field( (string) ( $row['organisation'] ?? '' ) ),
			'job_title'    => sanitize_text_field( (string) ( $row['job_title'] ?? '' ) ),
			'photo_id'     => absint( $row['photo_id'] ?? 0 ),
			'bio'          => law_rich_text_sanitize( $row['bio'] ?? '' ),
		);
	}

	return array_values( $rows );
}

/* Validation ________________________________________________________________ */

/**
 * Everything that can be refused, refused before anything is written, so a
 * failed save changes nothing and the screen can re-render what was typed.
 *
 * @return WP_Error Without errors when the input is valid.
 */
function law_flagship_validate( array $input ) {
	$errors = new WP_Error();

	if ( '' === trim( (string) $input['title'] ) ) {
		$errors->add( 'title', 'Give the flagship event a title.' );
	}
	if ( '' === (string) $input['date'] ) {
		$errors->add( 'date', 'Enter the date as YYYY-MM-DD.' );
	}

	// Bookings and pricing (FLAGSHIP_PAYMENTS.md §2.2).
	//
	// Every field here is judged only when it was actually submitted. A key
	// missing from $input means the caller is not editing pricing at all
	// (the migration, a test, or a POST that max_input_vars truncated), and a
	// present-but-empty field means "leave the stored value alone". Only a
	// present, non-empty, unreadable value is an error, because silently
	// reading a typo as 0 would put the conference on sale for nothing.
	foreach ( array( 'price' => 'Price before the switch', 'price_late' => 'Price after the switch' ) as $key => $label ) {
		if ( ! array_key_exists( $key, $input ) ) {
			continue;
		}
		$typed = trim( (string) $input[ $key ] );
		if ( '' !== $typed && null === law_events_pounds_to_pence( $typed ) ) {
			$errors->add( $key, sprintf( '%s must be an amount in pounds, for example 550.00.', $label ) );
		}
	}
	if ( array_key_exists( 'price_switch', $input ) ) {
		$typed = trim( (string) $input['price_switch'] );
		if ( '' !== $typed && null === law_flagship_parse_price_switch( $typed ) ) {
			$errors->add( 'price_switch', 'Enter the date and time the price switches as YYYY-MM-DD HH:MM, for example 2026-10-17 00:00.' );
		}
	}
	// Places may be lowered, but not below the people already holding a
	// confirmed place: the count would read as a lie and the over-booking
	// warning would fire on every subsequent approval.
	$flagship_id = law_flagship_event_id();
	if ( $flagship_id && array_key_exists( 'places', $input ) ) {
		$places    = (int) $input['places'];
		$confirmed = law_event_attendee_total( $flagship_id );
		if ( $places > 0 && $places < $confirmed ) {
			$errors->add(
				'places',
				sprintf(
					'There are already %d confirmed places, so the number available cannot be set to %d.',
					$confirmed,
					$places
				)
			);
		}
	}

	foreach ( $input['sessions'] as $i => $session ) {
		$n = $i + 1;
		if ( '' === trim( (string) $session['title'] ) ) {
			$errors->add( 'session_title', sprintf( 'Session %d needs a title.', $n ) );
		}
		// Compared as the schema will store them, zero-padded: "10:30" is
		// lexicographically LESS than "9:30", so an unpadded pair would be
		// refused as ending before it starts. The reader pads already; this
		// keeps validation right for any caller.
		$start = (string) law_events_sanitize_value( $session['start'] ?? '', 'time' );
		$end   = (string) law_events_sanitize_value( $session['end'] ?? '', 'time' );
		if ( '' === $start ) {
			$errors->add( 'session_start', sprintf( 'Session %d needs a start time (HH:MM).', $n ) );
		}
		if ( '' !== $end && '' !== $start && $end < $start ) {
			$errors->add( 'session_end', sprintf( 'Session %d ends before it starts.', $n ) );
		}

		foreach ( $session['speakers'] as $row ) {
			if ( ! empty( $row['is_new'] ) ) {
				if ( '' === trim( (string) $row['first_name'] ) || '' === trim( (string) $row['last_name'] ) ) {
					$errors->add( 'speaker_name', sprintf( 'Session %d, new speaker: first and last name are both required.', $n ) );
				}
				// Optional here, unlike the host's own form: the committee typing a
				// conference programme often will not have an address. Validated
				// when it is given, because it is the dedupe key.
				if ( '' !== (string) $row['email'] && ! is_email( $row['email'] ) ) {
					$errors->add( 'speaker_email', sprintf( 'Session %d, new speaker: that email address is not valid.', $n ) );
				}
				continue;
			}
			// A stale or forged ID is refused, not silently dropped, so the
			// committee is told rather than quietly losing a speaker.
			if ( LAW_SPEAKER_CPT !== get_post_type( $row['speaker_id'] ) ) {
				$errors->add( 'speaker_missing', sprintf( 'Session %d: speaker #%d no longer exists.', $n, (int) $row['speaker_id'] ) );
			}
		}
	}

	return $errors;
}

/* Saving ____________________________________________________________________ */

/**
 * Write the flagship. No workflow transition, no fee, no invoice, no
 * notification: the post is written directly, and nothing in the module reacts
 * to a law_event status change except the nonce-gated admin save handlers
 * (whose nonce this form does not carry) and a bookings-only hook.
 *
 * @return int|WP_Error The flagship post ID.
 */
function law_flagship_save( array $input, $actor ) {
	$errors = law_flagship_validate( $input );
	if ( $errors->has_errors() ) {
		return $errors;
	}

	$event_id = law_flagship_event_id();
	if ( ! $event_id ) {
		$created  = law_flagship_ensure_post();
		$event_id = (int) $created['id'];
	}
	if ( ! $event_id ) {
		return new WP_Error( 'law_flagship_missing', 'The flagship event could not be created.' );
	}

	$before = law_flagship_snapshot( $event_id );

	// The module's status guard (functions/events/workflow.php) reverts any
	// status change to an existing law_event that does not come from the
	// workflow engine, which is what stops the classic editor's Publish button
	// confirming an unapproved event. The flagship has no workflow at all, so
	// its saver announces itself instead; the guard only honours this flag for
	// the flagship, and only for its two statuses.
	$GLOBALS['law_flagship_saving'] = true;
	$updated                        = wp_update_post(
		wp_slash(
			array(
				'ID'           => $event_id,
				'post_title'   => $input['title'],
				'post_content' => $input['description'],
				'post_status'  => $input['show'] ? 'publish' : 'law-draft',
				// Re-asserted on every save, so a slug edited by hand (or lost to a
				// clash that has since been cleared) comes back to /events/flagship/.
				'post_name'    => LAW_FLAGSHIP_SLUG,
			)
		),
		true
	);
	unset( $GLOBALS['law_flagship_saving'] );
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	law_event_update_meta( $event_id, '_law_is_flagship', 1 );
	// The reserved 4.2 vocabulary finally has a consumer: the flagship is the
	// one approval-gated event, and anything asking an event how it is booked
	// should get an answer rather than an empty string.
	law_event_update_meta( $event_id, '_law_registration_state', 'apply' );
	law_event_update_meta( $event_id, '_law_flagship_date', $input['date'] );
	law_event_update_meta( $event_id, '_law_venue', $input['venue'] );
	law_event_update_meta( $event_id, '_law_hero_image_id', $input['hero_image_id'] );

	// Bookings and pricing. Mirrors the validation above: a key the caller did
	// not send, or sent empty, leaves the stored value alone, so clearing a box
	// is never mistaken for "make it free" and a truncated POST cannot wipe the
	// price the way it could once have wiped the agenda.
	if ( array_key_exists( 'places', $input ) ) {
		law_event_update_meta( $event_id, '_law_tickets_available', (int) $input['places'] );
	}
	foreach ( array( 'price' => '_law_flagship_price_pence', 'price_late' => '_law_flagship_price_late_pence' ) as $field => $meta_key ) {
		if ( ! array_key_exists( $field, $input ) ) {
			continue;
		}
		$pence = law_events_pounds_to_pence( $input[ $field ] );
		if ( null !== $pence ) {
			law_event_update_meta( $event_id, $meta_key, $pence );
		}
	}
	if ( array_key_exists( 'price_switch', $input ) ) {
		$switch = law_flagship_parse_price_switch( $input['price_switch'] );
		if ( null !== $switch ) {
			law_event_update_meta( $event_id, '_law_flagship_price_switch', $switch );
		}
	}

	if ( $input['sessions_present'] ) {
		law_flagship_save_sessions( $event_id, $input['sessions'], $actor );
	}

	law_flagship_recompute( $event_id );
	law_flagship_event_id( true );

	law_flagship_log_save( $event_id, $before, law_flagship_snapshot( $event_id ), $actor );

	return $event_id;
}

/**
 * Persist the sessions as child law_session posts. Mirrors
 * law_events_form_save_sessions() (submission-form.php), including its
 * ownership rule: a posted session ID is honoured only when that session is
 * already a child of this event, so a forged value reaches nothing.
 *
 * @return int[] The session IDs that survived.
 */
function law_flagship_save_sessions( $event_id, array $sessions, $actor ) {
	$owned    = law_event_session_ids( $event_id );
	$kept     = array();
	$position = 0;

	foreach ( $sessions as $row ) {
		$posted     = absint( $row['id'] ?? 0 );
		$session_id = ( $posted && in_array( $posted, $owned, true ) && ! in_array( $posted, $kept, true ) ) ? $posted : 0;

		if ( $session_id ) {
			// Recorded as kept BEFORE the write, exactly as the host form does: a
			// failed update must not make the session look like an orphan and get
			// deleted at the end of this loop.
			$kept[] = $session_id;
			$result = wp_update_post(
				wp_slash(
					array(
						'ID'           => $session_id,
						'post_title'   => $row['title'],
						'post_content' => $row['description'],
						'menu_order'   => $position,
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				law_event_log(
					$event_id,
					sprintf( 'Could not save session "%s": %s', $row['title'], $result->get_error_message() ),
					array( 'action' => 'session_save_failed', 'source' => 'wp-admin' ),
					array( 'user_id' => $actor )
				);
				$position++;
				continue;
			}
		} else {
			$session_id = wp_insert_post(
				wp_slash(
					array(
						'post_type'    => LAW_SESSION_CPT,
						'post_status'  => 'publish',
						'post_parent'  => $event_id,
						'post_title'   => $row['title'],
						'post_content' => $row['description'],
						'menu_order'   => $position,
					)
				),
				true
			);
			if ( is_wp_error( $session_id ) ) {
				law_event_log(
					$event_id,
					sprintf( 'Could not add session "%s": %s', $row['title'], $session_id->get_error_message() ),
					array( 'action' => 'session_save_failed', 'source' => 'wp-admin' ),
					array( 'user_id' => $actor )
				);
				$position++;
				continue;
			}
			$session_id = (int) $session_id;
			$kept[]     = $session_id;
		}

		$position++;
		law_event_update_meta( $session_id, '_law_start_time', $row['start'] );
		law_event_update_meta( $session_id, '_law_end_time', $row['end'] );
		law_event_update_meta( $session_id, '_law_speakers', law_flagship_resolve_speaker_rows( $row['speakers'], $event_id, $actor ) );
	}

	foreach ( array_diff( $owned, $kept ) as $orphan ) {
		wp_delete_post( $orphan, true );
	}

	return $kept;
}

/**
 * Turn the posted speaker rows into appearance rows, creating a law_speaker
 * record for each "new speaker" row first. law_speaker_upsert() dedupes by
 * email and then by normalised name, so entering someone who already has a
 * profile links to it rather than making a second one; in gap-fill mode it
 * never blanks a field another event filled in.
 */
function law_flagship_resolve_speaker_rows( array $rows, $event_id, $actor ) {
	$out = array();

	foreach ( $rows as $row ) {
		if ( ! empty( $row['is_new'] ) ) {
			$speaker_id = law_speaker_upsert(
				array(
					'first_name' => $row['first_name'],
					'last_name'  => $row['last_name'],
					'email'      => $row['email'],
					'website'    => $row['website'],
					'bio'        => $row['bio'],
					'photo_id'   => $row['photo_id'],
				),
				array( 'event_id' => $event_id, 'actor' => $actor )
			);
			if ( ! $speaker_id ) {
				// Only an empty name gets here, which validation already refused.
				continue;
			}
		} else {
			$speaker_id = absint( $row['speaker_id'] );
		}

		$out[] = array(
			'speaker_id'   => $speaker_id,
			'role'         => $row['role'],
			'organisation' => $row['organisation'],
			'job_title'    => $row['job_title'],
			'photo_id'     => $row['photo_id'],
			'bio'          => $row['bio'],
			'sort'         => count( $out ),
		);
	}

	return $out;
}

/* The activity log __________________________________________________________ */

/** What a save might change, in one comparable array. */
function law_flagship_snapshot( $event_id ) {
	// Raw post titles, not get_the_title(): the_title runs wptexturize, which
	// turns "Coffee & Cake" into "Coffee &#038; Cake", and a log line is read as
	// text, not rendered as HTML.
	$post     = get_post( $event_id );
	$sessions = array();
	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		$sessions[ (int) $session_id ] = (string) get_post_field( 'post_title', $session_id );
	}
	$speakers = array();
	foreach ( law_event_meta( $event_id, '_law_speakers' ) as $row ) {
		$id = absint( $row['speaker_id'] ?? 0 );
		if ( $id ) {
			$speakers[ $id ] = (string) get_post_field( 'post_title', $id );
		}
	}

	return array(
		'title'        => $post ? $post->post_title : '',
		'status'       => $post ? $post->post_status : '',
		'date'         => (string) law_event_meta( $event_id, '_law_flagship_date' ),
		'venue'        => (string) law_event_meta( $event_id, '_law_venue' ),
		'hero'         => absint( law_event_meta( $event_id, '_law_hero_image_id' ) ),
		'places'       => absint( law_event_meta( $event_id, '_law_tickets_available' ) ),
		'price'        => (int) get_post_meta( $event_id, '_law_flagship_price_pence', true ),
		'price_late'   => (int) get_post_meta( $event_id, '_law_flagship_price_late_pence', true ),
		'price_switch' => (string) law_event_meta( $event_id, '_law_flagship_price_switch' ),
		'sessions'     => $sessions,
		'speakers'     => $speakers,
	);
}

/**
 * One log line per save that changed something, in the plain-words style the
 * rest of the module's activity log uses. Nothing is written when a save
 * changed nothing, so the log stays a record of decisions rather than of
 * clicks.
 */
function law_flagship_log_save( $event_id, array $before, array $after, $actor ) {
	$changes = array();

	if ( $before['status'] !== $after['status'] ) {
		$changes[] = 'publish' === $after['status'] ? 'shown on the programme' : 'hidden from the programme';
	}
	if ( $before['title'] !== $after['title'] ) {
		$changes[] = sprintf( 'title "%s" → "%s"', $before['title'], $after['title'] );
	}
	if ( $before['date'] !== $after['date'] ) {
		$changes[] = sprintf( 'date %s → %s', $before['date'] ?: 'not set', $after['date'] ?: 'not set' );
	}
	if ( $before['venue'] !== $after['venue'] ) {
		$changes[] = sprintf( 'location "%s" → "%s"', $before['venue'] ?: 'not set', $after['venue'] ?: 'not set' );
	}
	if ( $before['hero'] !== $after['hero'] ) {
		$changes[] = $after['hero'] ? 'banner image set' : 'banner image removed';
	}
	// Money changes are logged in full, per the module's standing rule that
	// every payment-machinery change reads back like a WooCommerce order note.
	if ( $before['places'] !== $after['places'] ) {
		$changes[] = sprintf( 'places available %d → %d', $before['places'], $after['places'] );
	}
	if ( $before['price'] !== $after['price'] ) {
		$changes[] = sprintf(
			'price before the switch %s → %s (excluding VAT)',
			law_events_format_pence( $before['price'] ),
			law_events_format_pence( $after['price'] )
		);
	}
	if ( $before['price_late'] !== $after['price_late'] ) {
		$changes[] = sprintf(
			'price after the switch %s → %s (excluding VAT)',
			law_events_format_pence( $before['price_late'] ),
			law_events_format_pence( $after['price_late'] )
		);
	}
	if ( $before['price_switch'] !== $after['price_switch'] ) {
		$changes[] = sprintf(
			'price switches at %s → %s',
			$before['price_switch'] ?: 'not set',
			$after['price_switch'] ?: 'not set'
		);
	}

	$added   = array_diff_key( $after['sessions'], $before['sessions'] );
	$removed = array_diff_key( $before['sessions'], $after['sessions'] );
	if ( $added ) {
		$changes[] = sprintf( '%d session(s) added (%s)', count( $added ), implode( ', ', $added ) );
	}
	if ( $removed ) {
		$changes[] = sprintf( '%d session(s) removed (%s)', count( $removed ), implode( ', ', $removed ) );
	}
	$renamed = 0;
	foreach ( array_intersect_key( $after['sessions'], $before['sessions'] ) as $id => $title ) {
		if ( $before['sessions'][ $id ] !== $title ) {
			$renamed++;
		}
	}
	if ( $renamed ) {
		$changes[] = sprintf( '%d session(s) retitled', $renamed );
	}

	$speakers_added   = array_diff_key( $after['speakers'], $before['speakers'] );
	$speakers_removed = array_diff_key( $before['speakers'], $after['speakers'] );
	if ( $speakers_added ) {
		$changes[] = sprintf( 'speaker(s) added %s', implode( ', ', $speakers_added ) );
	}
	if ( $speakers_removed ) {
		$changes[] = sprintf( 'speaker(s) removed %s', implode( ', ', $speakers_removed ) );
	}

	if ( ! $changes ) {
		return;
	}

	law_event_log(
		$event_id,
		'Flagship event saved: ' . implode( '; ', $changes ) . '.',
		array(
			'action'  => 'flagship_saved',
			'source'  => 'wp-admin',
			'changes' => $changes,
		),
		array( 'user_id' => $actor )
	);
}

