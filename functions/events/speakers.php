<?php
/**
 * Speaker records (law_speaker): dedupe at save time, lookups, and the
 * relationship helpers events and sessions use (EVENTS_4.1_REBUILD.md §3.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event cap for an all-status scan of speaker rows (the Manage Speakers
 * dashboard). House style is never -1 on a screen path; the confirmed-only
 * pass keeps its own 500, which is the size of a programme year.
 */
const LAW_SPEAKERS_EVENT_SCAN_CAP = 1000;

/**
 * The role a person has at ONE event (Trevor, 3 September 2026): Speaker,
 * Host or Moderator. Per appearance like the organisation and job title,
 * since the same person hosts one event and moderates another. Stored on the
 * _law_speakers row as the key; '' means "not set", which reads as Speaker
 * and, on a session row, inherits the parent event row's role.
 *
 * @return array<string,string> key => label, in the order the selects offer.
 */
function law_speaker_roles() {
	return array(
		'speaker'   => 'Speaker',
		'host'      => 'Host',
		'moderator' => 'Moderator',
	);
}

/**
 * Normalise a key OR a label (any case, padded) to a role key. '' for
 * anything unknown, so a stray value can never reach the meta. The label
 * match is what lets the migration map form 8 field 9 (Role), whose stored
 * value is the label itself.
 *
 * @param mixed $value Posted or stored value.
 * @return string Role key, or ''.
 */
function law_speaker_role_key( $value ) {
	$value = mb_strtolower( trim( (string) ( is_scalar( $value ) ? $value : '' ) ) );
	if ( '' === $value ) {
		return '';
	}
	foreach ( law_speaker_roles() as $key => $label ) {
		if ( $value === $key || $value === mb_strtolower( $label ) ) {
			return $key;
		}
	}
	return '';
}

/** The label for a role key or label; '' when unknown or empty. */
function law_speaker_role_label( $key ) {
	$key = law_speaker_role_key( $key );
	return '' === $key ? '' : law_speaker_roles()[ $key ];
}

/**
 * What a listing prints after the name. '' (never set: every row migrated or
 * saved before roles existed) reads as Speaker, the default, and prints as
 * such (Denis, 8 September 2026: the role shows on every card). This is the
 * one line to change should the default ever be suppressed.
 *
 * @param string $role Row value (key, label or '').
 * @return string Label to print.
 */
function law_speaker_role_display( $role ) {
	$key = law_speaker_role_key( $role );
	return law_speaker_role_label( '' === $key ? 'speaker' : $key );
}

/**
 * Find an existing speaker: by email first (same email = same person), then by
 * normalised full name.
 *
 * The name fallback runs ONLY when no usable email was given. An email is an
 * identity claim, so a row carrying one that matches nothing is a new person,
 * even if somebody with the same name is already on file: two different
 * solicitors called "John Smith" at two different firms must not be collapsed
 * into one shared, publicly-displayed profile that then carries the wrong
 * email. The fallback still exists for the sources that have no email at all —
 * the legacy List field 48 rows the migration reads, which pass '' here.
 * A duplicate record is a nuisance the committee can merge on the Manage
 * Speakers screen; a wrong merge silently rewrites a real person's profile.
 *
 * @param string $email Email (may be empty).
 * @param string $name  Full display name.
 * @return int Post ID, 0 when no match.
 */
function law_speaker_find_existing( $email, $name ) {
	$email = mb_strtolower( trim( (string) $email ) );
	if ( is_email( $email ) ) {
		$by_email = get_posts(
			array(
				'post_type'      => LAW_SPEAKER_CPT,
				'post_status'    => 'any',
				'meta_key'       => '_law_speaker_email',
				'meta_value'     => $email,
				'fields'         => 'ids',
				'posts_per_page' => 1,
			)
		);
		if ( $by_email ) {
			return (int) $by_email[0];
		}
		return 0; // A real, unrecognised email: a new person, not a name match.
	}

	$normalised = law_speaker_normalise_name( $name );
	if ( '' === $normalised ) {
		return 0;
	}
	$candidates = get_posts(
		array(
			'post_type'      => LAW_SPEAKER_CPT,
			'post_status'    => 'any',
			'title'          => trim( (string) $name ),
			'fields'         => 'ids',
			'posts_per_page' => 5,
		)
	);
	foreach ( $candidates as $candidate ) {
		if ( law_speaker_normalise_name( law_speaker_raw_name( $candidate ) ) === $normalised ) {
			return (int) $candidate;
		}
	}
	return 0;
}

/**
 * Case- and whitespace-insensitive name key, mirroring the old dedupe. HTML
 * entities are decoded first, so a name that reached one side of a comparison
 * through get_the_title() (wptexturize turns an apostrophe into &#8217;) still
 * matches the same name read raw from the database. Without that, a session's
 * link to "Crystal O'Donnell" silently failed to match and was dropped on save.
 */
function law_speaker_normalise_name( $name ) {
	$name = html_entity_decode( (string) $name, ENT_QUOTES, 'UTF-8' );
	// Decoding &#8217; gives a CURLY apostrophe, so the two spellings still would
	// not match without folding the smart punctuation back to its plain form.
	$name = strtr(
		$name,
		array( "\u{2018}" => "'", "\u{2019}" => "'", "\u{201A}" => "'", "\u{201B}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{2013}" => '-', "\u{2014}" => '-' )
	);
	return preg_replace( '/\s+/', ' ', mb_strtolower( trim( $name ) ) );
}

/**
 * A speaker post's stored display name, UNFILTERED.
 *
 * get_the_title() runs the_title, and wptexturize rewrites an apostrophe as
 * &#8217;. That is right for rendering and wrong everywhere else: a form field
 * prefilled from it shows the raw entity to the host, and saving writes that
 * entity back into post_title, compounding on every save. The rule in this
 * module is therefore: get_the_title() to DISPLAY a name, this to MATCH one or
 * to put one into a form field.
 *
 * @param int $post_id Speaker post ID.
 */
function law_speaker_raw_name( $post_id ) {
	return trim( (string) get_post_field( 'post_title', (int) $post_id, 'raw' ) );
}

/**
 * Honorific suffixes that belong to the LAST name, so splitting a stored full
 * name gives "Ali" / "Malek KC" and never "Ali Malek" / "KC". The same list
 * the legacy list-field migrator uses (functions/migrate-speakers.php).
 */
const LAW_SPEAKER_NAME_SUFFIXES = array( 'KC', 'QC', 'SC', 'JP', 'OBE', 'CBE', 'MBE', 'KBE', 'CB', 'CMG', 'CVO', 'GCVO', 'PHD', 'ESQ', 'TBC' );

/**
 * Split a full name into first and last. This is the FALLBACK only: since
 * 9 September 2026 (Denis) the forms collect the two separately and store them
 * on the speaker post, and this reads a record saved before that, or a legacy
 * source that only ever had one name field. The last token is the last name,
 * with any honorific suffixes kept on it; a one-word name is all first name,
 * which is what the old law_speaker_post_profile() split did too.
 *
 * @param string $full Full name.
 * @return array{first:string,last:string}
 */
function law_speaker_split_name( $full ) {
	$full = trim( preg_replace( '/\s+/', ' ', (string) $full ) );
	if ( '' === $full ) {
		return array( 'first' => '', 'last' => '' );
	}
	$parts    = explode( ' ', $full );
	$suffixes = array();
	while ( count( $parts ) > 1 ) {
		$token = rtrim( (string) end( $parts ), '.,' );
		if ( in_array( mb_strtoupper( $token ), LAW_SPEAKER_NAME_SUFFIXES, true ) ) {
			array_unshift( $suffixes, array_pop( $parts ) );
			continue;
		}
		break;
	}
	if ( count( $parts ) < 2 ) {
		// One word, suffixes or not: it is the first name, nothing to split.
		return array( 'first' => $full, 'last' => '' );
	}
	$last = array_pop( $parts );
	return array(
		'first' => implode( ' ', $parts ),
		'last'  => trim( $last . ' ' . implode( ' ', $suffixes ) ),
	);
}

/** The display name two parts make: "First Last", collapsed and trimmed. */
function law_speaker_full_name( $first, $last ) {
	return trim( preg_replace( '/\s+/', ' ', trim( (string) $first ) . ' ' . trim( (string) $last ) ) );
}

/**
 * A speaker post's name in two parts: the stored meta when it has it, else the
 * post title split (every record saved before the fields were separate).
 *
 * @param int $post_id Speaker post ID.
 * @return array{first:string,last:string}
 */
function law_speaker_name_parts( $post_id ) {
	$first = trim( (string) law_event_meta( $post_id, '_law_speaker_first_name' ) );
	$last  = trim( (string) law_event_meta( $post_id, '_law_speaker_last_name' ) );
	if ( '' !== $first || '' !== $last ) {
		return array( 'first' => $first, 'last' => $last );
	}
	// The RAW title, not get_the_title(): these two values are prefilled into the
	// First/Last name inputs on three different editing screens, and each of
	// them rebuilds post_title from what comes back.
	return law_speaker_split_name( law_speaker_raw_name( $post_id ) );
}

/**
 * The two parts a submitted row carries. A row that only has the old single
 * 'name' key (a legacy source, or a saved payload from before the split) is
 * split, so no caller has to know which shape it holds.
 *
 * @param array $row first_name / last_name, or name.
 * @return array{first:string,last:string}
 */
function law_speaker_row_name_parts( array $row ) {
	$first = trim( (string) ( $row['first_name'] ?? '' ) );
	$last  = trim( (string) ( $row['last_name'] ?? '' ) );
	if ( '' !== $first || '' !== $last ) {
		return array( 'first' => $first, 'last' => $last );
	}
	return law_speaker_split_name( (string) ( $row['name'] ?? '' ) );
}

/**
 * Create or update a speaker from submitted row data. New data fills gaps;
 * it never blanks an existing value (matching the old backfill rule).
 *
 * Only identity fields live on the post: first name, last name, email,
 * website. Organisation, job title, photo and biography are per appearance and
 * belong on the event's _law_speakers row (law_events_form_save_speakers());
 * the featured image and the biography set here are fallbacks only, used when
 * the appearance has none of its own.
 *
 * @param array $data    first_name, last_name (a single 'name' is split when
 *                       the parts are absent), email, website, bio, photo_id
 *                       (attachment ID). organisation/job_title are accepted
 *                       and ignored.
 * @param array $log     event_id + actor, for the activity-log line.
 * @param array $options speaker_id: edit THIS record rather than matching one
 *                       (the caller must have already checked the row belongs
 *                       to it). overwrite_identity: write the identity fields
 *                       outright instead of only filling gaps — see below.
 * @return int Post ID, 0 on failure.
 */
function law_speaker_upsert( array $data, array $log = array(), array $options = array() ) {
	// The post title stays the display name every listing prints and every
	// lookup matches on; the two parts are stored beside it (Denis,
	// 9 September 2026), and a caller that still passes one 'name' is split.
	$parts = law_speaker_row_name_parts( $data );
	$name  = law_speaker_full_name( $parts['first'], $parts['last'] );
	if ( '' === $name ) {
		return 0;
	}

	// An explicit record beats matching on email/name: it is the only way a
	// caller can edit the very fields the match is made on. Callers pass one
	// only for a speaker the event already holds (law_events_form_save_speakers()).
	$post_id = 0;
	if ( ! empty( $options['speaker_id'] ) ) {
		$target  = get_post( (int) $options['speaker_id'] );
		$post_id = ( $target && LAW_SPEAKER_CPT === $target->post_type ) ? (int) $target->ID : 0;
	}
	if ( ! $post_id ) {
		$post_id = law_speaker_find_existing( (string) ( $data['email'] ?? '' ), $name );
	}
	$is_new    = ! $post_id;
	$overwrite = ! $is_new && ! empty( $options['overwrite_identity'] );
	$filled    = array(); // Fields this submission backfilled on a PRE-EXISTING record.
	$changed   = array(); // Identity fields this submission REPLACED (overwrite mode).

	if ( ! $post_id ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => LAW_SPEAKER_CPT,
				'post_status'  => 'publish', // Visibility is derived from events, not from status.
				'post_title'   => $name,
				'post_content' => law_rich_text_sanitize( $data['bio'] ?? '' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return 0;
		}
	} else {
		// Backfill an empty biography only.
		$existing = get_post( $post_id );
		if ( $existing && '' === trim( $existing->post_content ) && '' !== trim( (string) ( $data['bio'] ?? '' ) ) ) {
			wp_update_post( array( 'ID' => $post_id, 'post_content' => law_rich_text_sanitize( $data['bio'] ) ) );
			$filled[] = 'biography';
		}
	}

	$meta_map = array(
		'email'   => array( 'key' => '_law_speaker_email', 'label' => 'email address' ),
		'website' => array( 'key' => '_law_website', 'label' => 'website' ),
	);
	foreach ( $meta_map as $field => $spec ) {
		$key    = $spec['key'];
		$value  = trim( (string) ( $data[ $field ] ?? '' ) );
		$value  = 'email' === $field ? mb_strtolower( $value ) : $value;
		$stored = (string) law_event_meta( $post_id, $key );

		if ( $overwrite ) {
			if ( $value === $stored ) {
				continue;
			}
			// The email is the dedupe key, so it must never be moved onto an
			// address another record already owns: that would quietly merge two
			// people. Same guard the Manage Speakers screen applies, except that
			// here the rest of the row still saves — the host gets their name
			// change, and the clashing email is simply left alone.
			if ( 'email' === $field && '' !== $value ) {
				$clash = law_speaker_find_existing( $value, '' );
				if ( $clash && (int) $clash !== (int) $post_id ) {
					continue;
				}
			}
			law_event_update_meta( $post_id, $key, $value );
			$changed[] = sprintf( '%s "%s" → "%s"', $spec['label'], $stored, $value );
			continue;
		}

		if ( '' !== $value && '' === $stored ) {
			law_event_update_meta( $post_id, $key, $value );
			if ( ! $is_new ) {
				$filled[] = $field;
			}
		}
	}

	if ( $overwrite ) {
		// The row belongs to an event the saver is editing, so the name is
		// written outright rather than gap-filled: the host typed it into a
		// required field, and silently discarding it is worse than a logged
		// change on a shared record (Denis, 9 September 2026). post_name is left
		// alone, so an existing /speakers/<slug>/ link keeps resolving after a
		// correction — the same rule the Manage Speakers screen follows.
		$existing_name = law_speaker_raw_name( $post_id );
		if ( $existing_name !== $name ) {
			wp_update_post( wp_slash( array( 'ID' => $post_id, 'post_title' => $name ) ) );
			$changed[] = sprintf( 'name "%s" → "%s"', $existing_name, $name );
		}
		foreach ( array( 'first' => '_law_speaker_first_name', 'last' => '_law_speaker_last_name' ) as $part => $key ) {
			law_event_update_meta( $post_id, $key, $parts[ $part ] );
		}
	} else {
		// The name parts, gap-filled like everything else here: a record created
		// before the split (or by an import that only had one name field) gets
		// them on the next submission, and a submission never renames somebody
		// else's shared record. Not logged as a backfill: the displayed name does
		// not change, only its stored shape.
		foreach ( array( 'first' => '_law_speaker_first_name', 'last' => '_law_speaker_last_name' ) as $part => $key ) {
			if ( '' !== $parts[ $part ] && '' === (string) law_event_meta( $post_id, $key ) ) {
				law_event_update_meta( $post_id, $key, $parts[ $part ] );
			}
		}
	}

	if ( ! empty( $data['photo_id'] ) && ! has_post_thumbnail( $post_id ) ) {
		set_post_thumbnail( $post_id, (int) $data['photo_id'] );
		if ( ! $is_new ) {
			$filled[] = 'photo';
		}
	}

	// A rename or a contact-detail change on a shared, publicly-displayed
	// profile shows on every event that speaker appears at, so it is always
	// recorded — the committee can see who changed what, and put it back.
	if ( $changed && ! empty( $log['event_id'] ) && function_exists( 'law_event_log' ) ) {
		law_event_log(
			(int) $log['event_id'],
			sprintf( 'Speaker profile updated from this submission: %s.', implode( ', ', $changed ) ),
			array( 'action' => 'speaker_updated', 'speaker' => (int) $post_id, 'changes' => $changed, 'source' => 'submission' ),
			array( 'user_id' => (int) ( $log['actor'] ?? 0 ) )
		);
	}

	// A shared, publicly-displayed speaker profile was altered by a host other
	// than its originator; record it on the event so the committee can spot a
	// bad backfill against a real speaker's record during review.
	if ( ! $is_new && $filled && ! empty( $log['event_id'] ) && function_exists( 'law_event_log' ) ) {
		law_event_log(
			(int) $log['event_id'],
			sprintf( 'Existing speaker profile "%s" backfilled from this submission: %s.', $name, implode( ', ', $filled ) ),
			array( 'action' => 'speaker_backfilled', 'speaker' => (int) $post_id, 'fields' => $filled, 'source' => 'submission' ),
			array( 'user_id' => (int) ( $log['actor'] ?? 0 ) )
		);
	}

	return (int) $post_id;
}

/**
 * Public speaker profile array in the shape functions/speakers.php renders
 * (id, first/last, name, organisation, job_title, url, photo, bio, email,
 * event_ids, appearances).
 *
 * organisation, job_title, photo and bio are those of the speaker's FIRST
 * confirmed appearance (earliest event), the archive rule Denis settled;
 * the per-event values are in 'appearances' for the profile's event cards.
 *
 * @param int   $post_id   Speaker post ID.
 * @param int[] $event_ids Confirmed events referencing this speaker.
 */
function law_speaker_post_profile( $post_id, array $event_ids = array() ) {
	$post = get_post( $post_id );
	if ( ! $post || LAW_SPEAKER_CPT !== $post->post_type ) {
		return null;
	}

	$name  = trim( $post->post_title );
	$parts = law_speaker_name_parts( $post->ID );
	$first = $parts['first'];
	$last  = $parts['last'];
	$seen  = law_speaker_first_appearance( $post->ID );

	return array(
		'id'           => (int) $post->ID,
		'first_name'   => $first,
		'last_name'    => $last,
		'name'         => $name,
		'organisation' => $seen['organisation'],
		'job_title'    => $seen['job_title'],
		'url'          => (string) law_event_meta( $post->ID, '_law_website' ),
		'photo'        => law_speaker_photo_url( $post->ID, $seen['photo_id'], 'medium' ),
		'bio'          => '' !== $seen['bio'] ? $seen['bio'] : trim( $post->post_content ),
		'email'        => (string) law_event_meta( $post->ID, '_law_speaker_email' ),
		'entry_ids'    => array( (int) $post->ID ),
		'event_ids'    => array_map( 'intval', $event_ids ),
		'appearances'  => law_speaker_appearances( $post->ID ),
	);
}

/**
 * Confirmed (publish) event IDs that reference a set of speakers, keyed by
 * speaker ID. One query pass over the published events' speaker rows; the
 * same pass records each speaker's appearance row per event for
 * law_speaker_appearances().
 *
 * @return array<int,int[]> speaker_id => event post IDs.
 */
function law_speakers_confirmed_event_map() {
	return law_speakers_confirmed_maps()['events'];
}

/**
 * The single pass over a set of events' speaker rows, memoised per status set.
 * Returns two maps: 'events' (speaker_id => event IDs) and 'appearances'
 * (speaker_id => [event_id => the row for that event]).
 *
 * Extracted from law_speakers_confirmed_maps() so the committee's Manage
 * Speakers dashboard can walk EVERY status with the same code: a speaker post
 * exists from the first draft save onwards, and the committee edits appearances
 * long before an event is confirmed. The public archive still asks for
 * 'publish' only, through the wrapper below.
 *
 * @param string[] $statuses post_status values to walk.
 * @param int      $limit    Event cap (house style: never -1 on a screen path).
 * @param bool     $reset    Rebuild (tests create events mid-request).
 * @return array{events:array<int,int[]>,appearances:array<int,array<int,array>>}
 */
function law_speakers_event_maps( array $statuses, $limit = 500, $reset = false ) {
	$cache = &law_speakers_event_maps_cache();
	$key   = implode( ',', $statuses ) . '|' . (int) $limit;
	if ( $reset ) {
		// Every key, not just this one: whatever invalidated one status set
		// (a test creating an event, a save mid-request) invalidated the rest.
		$cache = array();
	} elseif ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}
	$maps = array( 'events' => array(), 'appearances' => array() );

	$events = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => $statuses,
			'fields'         => 'ids',
			'posts_per_page' => (int) $limit,
		)
	);

	foreach ( $events as $event_id ) {
		$event_id = (int) $event_id;
		foreach ( law_event_meta( $event_id, '_law_speakers' ) as $row ) {
			$speaker_id = (int) ( $row['speaker_id'] ?? 0 );
			if ( $speaker_id ) {
				$maps['events'][ $speaker_id ][]                 = $event_id;
				$maps['appearances'][ $speaker_id ][ $event_id ] = $row;
			}
		}
		// Session-level speakers appear in the archive too: they speak at the event.
		foreach ( law_event_session_ids( $event_id ) as $session_id ) {
			foreach ( law_event_meta( $session_id, '_law_speakers' ) as $row ) {
				$speaker_id = (int) ( $row['speaker_id'] ?? 0 );
				if ( ! $speaker_id ) {
					continue;
				}
				if ( empty( $maps['events'][ $speaker_id ] ) || ! in_array( $event_id, $maps['events'][ $speaker_id ], true ) ) {
					$maps['events'][ $speaker_id ][] = $event_id;
				}
				// The event-level row wins; a session row only stands in when
				// the speaker is not on the event itself.
				if ( ! isset( $maps['appearances'][ $speaker_id ][ $event_id ] ) ) {
					$maps['appearances'][ $speaker_id ][ $event_id ] = $row;
				}
			}
		}
	}

	$cache[ $key ] = $maps;

	return $maps;
}

/** The memo behind law_speakers_event_maps(), by reference so it can be flushed. */
function &law_speakers_event_maps_cache() {
	static $cache = array();
	return $cache;
}

/** Drop every memoised speaker map (a save, or a test creating events mid-request). */
function law_speakers_flush_maps() {
	$cache = &law_speakers_event_maps_cache();
	$cache = array();
}

/**
 * The published-events pass the public archive and profile read: a thin wrapper
 * over law_speakers_event_maps(), kept because every existing caller (and
 * tests/SpeakerRolesTest.php) names it and passes $reset.
 *
 * @param bool $reset Rebuild (tests create events mid-request).
 * @return array{events:array<int,int[]>,appearances:array<int,array<int,array>>}
 */
function law_speakers_confirmed_maps( $reset = false ) {
	return law_speakers_event_maps( array( 'publish' ), 500, $reset );
}

/**
 * A speaker's appearances, first submitted first: one row per event with the
 * role, organisation, job title, photo and biography they had at THAT event.
 *
 * Confirmed events only by default, which is what the public archive and the
 * profile mean by an appearance. The committee's Manage Speakers dashboard
 * passes every status instead, because a speaker record exists from the first
 * draft save and an appearance is most worth correcting before the event is
 * confirmed.
 *
 * @param int           $speaker_id Speaker post ID.
 * @param string[]|null $statuses   Event statuses to read; null = publish only.
 * @return array<int,array{event_id:int,role:string,organisation:string,job_title:string,photo_id:int,bio:string}>
 */
function law_speaker_appearances( $speaker_id, ?array $statuses = null ) {
	$maps = null === $statuses
		? law_speakers_confirmed_maps()
		: law_speakers_event_maps( $statuses, LAW_SPEAKERS_EVENT_SCAN_CAP );
	$rows = $maps['appearances'][ (int) $speaker_id ] ?? array();

	$appearances = array();
	foreach ( $rows as $event_id => $row ) {
		$appearances[] = array(
			'event_id'     => (int) $event_id,
			'role'         => law_speaker_role_key( $row['role'] ?? '' ),
			'organisation' => trim( (string) ( $row['organisation'] ?? '' ) ),
			'job_title'    => trim( (string) ( $row['job_title'] ?? '' ) ),
			'photo_id'     => (int) ( $row['photo_id'] ?? 0 ),
			'bio'          => trim( (string) ( $row['bio'] ?? '' ) ),
			'sort'         => law_speaker_event_sort_key( (int) $event_id ),
		);
	}
	usort( $appearances, fn( $a, $b ) => strcmp( $a['sort'], $b['sort'] ) );
	foreach ( $appearances as &$appearance ) {
		unset( $appearance['sort'] );
	}
	unset( $appearance );
	return $appearances;
}

/** Chronological key for an event: start datetime, then post date, then ID (unscheduled events last). */
function law_speaker_event_sort_key( $event_id ) {
	// Submission order (creation date, which migrated events keep from the
	// legacy entry), then ID. "First appearance" means the first time the
	// speaker was submitted to LAW, not the earliest event date: two events on
	// the same day would otherwise let a brand-new submission outrank a
	// years-old record (Denis, 8 September 2026).
	return (string) get_post_field( 'post_date_gmt', $event_id ) . ' ' . str_pad( (string) $event_id, 10, '0', STR_PAD_LEFT );
}

/**
 * The photo for a speaker on an event page (speakers section, sessions, the
 * host dashboard): the photo set specifically for THIS event, else the first
 * photo ever provided for the speaker (their first-submitted confirmed
 * appearance carrying one), else nothing, so the template shows the initials
 * placeholder (Denis, 8 September 2026). The archive and profile use the first
 * photo directly (law_speaker_first_appearance()). law_speaker_photo_url()
 * still consults the featured image last, for a photo the committee set on
 * the speaker itself in wp-admin.
 *
 * @param int $speaker_id   Speaker post ID.
 * @param int $row_photo_id The rendered row's own photo_id, 0 for none.
 */
function law_speaker_display_photo_id( $speaker_id, $row_photo_id = 0 ) {
	return (int) $row_photo_id ?: (int) law_speaker_first_appearance( $speaker_id )['photo_id'];
}

/**
 * The speaker's FIRST appearance on record: organisation, job title, photo and
 * biography from the earliest confirmed event, each field falling through to
 * the next appearance when the earlier row leaves it empty (so a row without a
 * photo does not blank the card). Used by the archive card and the profile.
 *
 * @param int           $speaker_id Speaker post ID.
 * @param string[]|null $statuses   Event statuses to read; null = publish only.
 * @return array{organisation:string,job_title:string,photo_id:int,bio:string,event_id:int}
 */
function law_speaker_first_appearance( $speaker_id, ?array $statuses = null ) {
	$first  = array( 'organisation' => '', 'job_title' => '', 'photo_id' => 0, 'bio' => '', 'event_id' => 0 );
	// No role: it is what the person was at ONE event, never a headline fact.
	$fields = array( 'organisation', 'job_title', 'photo_id', 'bio' );
	foreach ( law_speaker_appearances( $speaker_id, $statuses ) as $appearance ) {
		if ( ! $first['event_id'] ) {
			$first['event_id'] = $appearance['event_id'];
		}
		foreach ( $fields as $field ) {
			if ( empty( $first[ $field ] ) && ! empty( $appearance[ $field ] ) ) {
				$first[ $field ] = $appearance[ $field ];
			}
		}
		if ( $first['organisation'] && $first['job_title'] && $first['photo_id'] && '' !== $first['bio'] ) {
			break;
		}
	}
	return $first;
}

/**
 * The exact appearance row for one event (event row first, then its
 * sessions), regardless of the event's status. Used by the profile's
 * "Speaking at" cards and the admin "Appears at" box.
 *
 * @return array{role:string,organisation:string,job_title:string,photo_id:int,bio:string}|null Null when the speaker is not on the event.
 */
function law_speaker_appearance_for_event( $speaker_id, $event_id ) {
	$speaker_id = (int) $speaker_id;
	$event_id   = (int) $event_id;
	$sources    = array( law_event_meta( $event_id, '_law_speakers' ) );
	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		$sources[] = law_event_meta( $session_id, '_law_speakers' );
	}
	foreach ( $sources as $rows ) {
		foreach ( $rows as $row ) {
			if ( (int) ( $row['speaker_id'] ?? 0 ) === $speaker_id ) {
				return array(
					'role'         => law_speaker_role_key( $row['role'] ?? '' ),
					'organisation' => trim( (string) ( $row['organisation'] ?? '' ) ),
					'job_title'    => trim( (string) ( $row['job_title'] ?? '' ) ),
					'photo_id'     => (int) ( $row['photo_id'] ?? 0 ),
					'bio'          => trim( (string) ( $row['bio'] ?? '' ) ),
				);
			}
		}
	}
	return null;
}

/**
 * Photo URL for an appearance: the row's attachment, else the speaker post's
 * featured image (the fallback set once by law_speaker_upsert()).
 *
 * @param int    $speaker_id Speaker post ID.
 * @param int    $photo_id   Appearance attachment ID (0 for none).
 * @param string $size       Image size.
 */
function law_speaker_photo_url( $speaker_id, $photo_id, $size = 'thumbnail' ) {
	if ( $photo_id ) {
		$url = wp_get_attachment_image_url( (int) $photo_id, $size );
		if ( $url ) {
			return (string) $url;
		}
	}
	return (string) get_the_post_thumbnail_url( (int) $speaker_id, $size );
}

/**
 * Session post IDs for an event: start time first, then the host's row order
 * from the sessions repeater (menu_order), then post ID.
 *
 * The menu_order tie-break is what orders parallel tracks that start at the
 * same time. Post ID cannot do it: sessions are upserted now, so an edited
 * session keeps the ID it was first created with. Sessions saved before
 * menu_order was written all hold 0 and fall through to the ID order they
 * used to have.
 *
 * @return int[]
 */
function law_event_session_ids( $event_id ) {
	// No meta_key in the query: an INNER JOIN would silently drop sessions
	// without a start time. Sorted in PHP instead, empty times last.
	$ids = get_posts(
		array(
			'post_type'      => LAW_SESSION_CPT,
			'post_status'    => array( 'publish', 'draft', 'private' ),
			'post_parent'    => (int) $event_id,
			'fields'         => 'ids',
			'posts_per_page' => 50,
			'orderby'        => array( 'menu_order' => 'ASC', 'ID' => 'ASC' ),
		)
	);
	usort(
		$ids,
		function ( $a, $b ) {
			$time_a = (string) law_event_meta( $a, '_law_start_time' );
			$time_b = (string) law_event_meta( $b, '_law_start_time' );
			return strcmp( $time_a ?: '99:99', $time_b ?: '99:99' )
				?: ( (int) get_post_field( 'menu_order', $a ) <=> (int) get_post_field( 'menu_order', $b ) )
				?: $a <=> $b;
		}
	);
	return $ids;
}

/**
 * Card-shaped speaker rows for an event listing (the shape
 * parts/calendar-body.php renders): id, name, role, organisation, job_title,
 * url (profile), photo, photo_id, bio. Values are the event's own appearance
 * rows.
 *
 * @param int $event_id law_event post ID.
 */
function law_event_speaker_cards( $event_id ) {
	$cards = array();
	foreach ( law_event_meta( $event_id, '_law_speakers' ) as $row ) {
		$card = law_speaker_card( (int) ( $row['speaker_id'] ?? 0 ), $row );
		if ( $card ) {
			$cards[] = $card;
		}
	}
	return $cards;
}

/**
 * One card row for a speaker at one event. Role, organisation, job title,
 * photo and biography come from the appearance row; any field the row leaves empty falls
 * through to $fallback_rows (the parent event's rows, for a session row the
 * committee added in wp-admin without details), then the photo falls back to
 * the speaker post's featured image and the biography to its editor content.
 *
 * 'medium', not 'thumbnail': the single event view renders these at 5.5rem, so
 * a 150px hard-cropped thumbnail is soft on a retina screen. Note medium is
 * not cropped square, which is why the CSS uses object-fit.
 * The photo is this event's own, then the first one ever provided for the
 * speaker, then the featured image (else the template's initials placeholder).
 *
 * @param int   $speaker_id    Speaker post ID.
 * @param array $row           The _law_speakers row being rendered.
 * @param array $fallback_rows Parent event's _law_speakers rows (sessions only).
 */
function law_speaker_card( $speaker_id, array $row = array(), array $fallback_rows = array() ) {
	$post = get_post( (int) $speaker_id );
	if ( ! $post || LAW_SPEAKER_CPT !== $post->post_type ) {
		return null;
	}

	$role         = law_speaker_role_key( $row['role'] ?? '' );
	$organisation = trim( (string) ( $row['organisation'] ?? '' ) );
	$job_title    = trim( (string) ( $row['job_title'] ?? '' ) );
	$photo_id     = (int) ( $row['photo_id'] ?? 0 );
	$bio          = trim( (string) ( $row['bio'] ?? '' ) );
	if ( '' === $role || '' === $organisation || '' === $job_title || ! $photo_id || '' === $bio ) {
		foreach ( $fallback_rows as $fallback ) {
			if ( (int) ( $fallback['speaker_id'] ?? 0 ) !== (int) $post->ID ) {
				continue;
			}
			// A session row's blank role inherits the event's, like the other fields.
			$role         = '' !== $role ? $role : law_speaker_role_key( $fallback['role'] ?? '' );
			$organisation = '' !== $organisation ? $organisation : trim( (string) ( $fallback['organisation'] ?? '' ) );
			$job_title    = '' !== $job_title ? $job_title : trim( (string) ( $fallback['job_title'] ?? '' ) );
			$photo_id     = $photo_id ?: (int) ( $fallback['photo_id'] ?? 0 );
			$bio          = '' !== $bio ? $bio : trim( (string) ( $fallback['bio'] ?? '' ) );
			break;
		}
	}

	// Organisation, job title and biography are this event's; the photo is this
	// event's own, else the first ever provided (law_speaker_display_photo_id()).
	$photo_id = law_speaker_display_photo_id( $post->ID, $photo_id );

	return array(
		'id'           => (int) $post->ID,
		'name'         => $post->post_title,
		'role'         => $role, // Key, '' = Speaker; templates print law_speaker_role_display().
		'organisation' => $organisation,
		'job_title'    => $job_title,
		'url'          => function_exists( 'law_speaker_url' ) ? law_speaker_url( $post->ID ) : '',
		'photo'        => law_speaker_photo_url( $post->ID, $photo_id, 'medium' ),
		'photo_id'     => $photo_id,
		'bio'          => '' !== $bio ? $bio : trim( $post->post_content ),
	);
}

/**
 * The card's short biography: the first $words words, plain text. Used by the
 * single event view's speaker cards and by law_speaker_seo_description().
 *
 * The '…' is passed explicitly because wp_trim_words() otherwise appends the
 * HTML entity ' &hellip;', which an esc_html() caller prints literally; and
 * shortcodes are stripped because an appearance biography is stored raw and
 * never passes through the_content.
 *
 * @param string $bio   Raw biography.
 * @param int    $words Word limit.
 */
function law_speaker_bio_excerpt( $bio, $words = 24 ) {
	// law_rich_text_plain(), not wp_strip_all_tags(): a biography is rich text
	// now, and stripping tags alone would run "<li>One</li><li>Two</li>"
	// together as "OneTwo". It strips the shortcodes too.
	return wp_trim_words( law_rich_text_plain( $bio ), (int) $words, '…' );
}

/**
 * The three things a speaker card needs from a biography in one call: the
 * plain full text, the excerpt, and whether the excerpt actually left anything
 * out. The card only offers "Read full bio" when it did, so the dialog never
 * opens on the paragraph already under it.
 *
 * The word split mirrors wp_trim_words()'s own, so the count and the excerpt
 * cannot disagree about where the limit falls.
 *
 * 'full' keeps the author's markup, because the dialog and the no-JS
 * <details> block both render it through law_rich_text_render(); the excerpt
 * and the word count work off the plain text, since a card shows one line and
 * a half-open <strong> in it would leak into the rest of the page.
 *
 * @param string $bio   Raw biography.
 * @param int    $words Word limit.
 * @return array{full:string,excerpt:string,trimmed:bool}
 */
function law_speaker_bio_summary( $bio, $words = 24 ) {
	$full  = trim( (string) $bio );
	$plain = law_rich_text_plain( $bio );
	$parts = '' === $plain ? array() : (array) preg_split( '/[\n\r\t ]+/', $plain, -1, PREG_SPLIT_NO_EMPTY );
	return array(
		'full'    => '' === $plain ? '' : $full,
		'excerpt' => '' === $plain ? '' : law_speaker_bio_excerpt( $bio, $words ),
		'trimmed' => count( $parts ) > (int) $words,
	);
}

/**
 * The bio dialogs the single event view has to print. A speaker card that has
 * more biography than its excerpt shows registers here and gets an id back for
 * its "Read full bio" button; parts/calendar-body.php prints the registered
 * dialogs once, after the speaker and session sections.
 *
 * A registry rather than an index passed down the template: the cards render
 * in two different places (the event's own Speakers list and each session
 * panel) and the dialogs must all end up outside the session <details>, since
 * a closed <details> renders nothing and its dialog could never open. One
 * dialog per CARD, not per speaker: each appearance row carries its own
 * biography, so two session rows for the same person can legitimately differ.
 *
 * @param array $speaker A law_speaker_card() row.
 * @return string The dialog's element id.
 */
function law_speaker_dialog_register( array $speaker ) {
	$dialogs   = &law_speaker_dialogs_store();
	$id        = 'law-speaker-bio-' . ( count( $dialogs ) + 1 );
	$dialogs[] = array( 'id' => $id, 'speaker' => $speaker );
	return $id;
}

/** The registered dialogs, in registration order. */
function law_speaker_dialogs() {
	return law_speaker_dialogs_store();
}

/** The registry's backing store, by reference so the register can append. */
function &law_speaker_dialogs_store() {
	static $dialogs = array();
	return $dialogs;
}

/**
 * Sessions for an event in the listing shape (title, start, end, time_label,
 * description, speakers[]).
 */
function law_event_session_rows( $event_id ) {
	$sessions   = array();
	$event_rows = law_event_meta( $event_id, '_law_speakers' ); // Appearance fallback for session rows.
	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		$post  = get_post( $session_id );
		$start = (string) law_event_meta( $session_id, '_law_start_time' );
		$end   = (string) law_event_meta( $session_id, '_law_end_time' );

		$speakers = array();
		foreach ( law_event_meta( $session_id, '_law_speakers' ) as $row ) {
			$card = law_speaker_card( (int) ( $row['speaker_id'] ?? 0 ), $row, $event_rows );
			if ( $card ) {
				$speakers[] = $card;
			}
		}

		$time_label = $start && $end ? $start . '–' . $end : $start;
		$title      = $post ? trim( $post->post_title ) : '';
		$desc       = $post ? trim( $post->post_content ) : '';
		if ( '' === $title && '' === $time_label && '' === $desc && empty( $speakers ) ) {
			continue;
		}

		$sessions[] = array(
			// The post ID: the edit form round-trips it so a save updates the
			// session in place instead of deleting and re-creating it.
			'id'          => (int) $session_id,
			'title'       => $title,
			'start'       => $start,
			'end'         => $end,
			'time_label'  => $time_label,
			'description' => $desc,
			'speakers'    => $speakers,
		);
	}
	return $sessions;
}
