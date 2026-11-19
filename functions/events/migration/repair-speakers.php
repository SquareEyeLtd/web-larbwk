<?php
/**
 * One-off repair: speaker records a host turned into somebody else.
 *
 * Until 19 November 2026 a host who replaced a speaker by typing the new person
 * over an existing row on the event form RENAMED the shared law_speaker record
 * instead of adding a new one (law_speaker_upsert()'s overwrite mode, built on
 * 9 September 2026 for corrections). The record is shared with every event the
 * person appears at, so the rename showed on all of them; Emily reported two of
 * these on 18 November (Domitille Baizeau / Noradèle Radjai, Campbell Herbert /
 * Kathleen Donnelly KC). A scan of the prod log found 54 such replacements
 * against 26 genuine corrections. The upsert now treats a different person as a
 * replacement and leaves the old record alone; this panel cleans up what the
 * old behaviour left behind:
 *
 * 1. Known fixes: a reviewed list from the 19 November scan of the prod
 *    database, each with the state that must still hold for it to apply (a
 *    row still pointing at the wrong person, an identity field still holding
 *    the other person's value). Anything that has moved on since is skipped
 *    and shown as such, never forced.
 * 2. Profiles: every published speaker whose URL is named after somebody else
 *    (the slug is set once, from the first person the record held, and a rename
 *    leaves it alone). Each gets a URL from its current name, with NO redirect
 *    from the old one, because the old URL names a different person; a fallback
 *    photo taken from its own event row (the featured image is set once, so it
 *    was usually the previous person's face); and its website cleared when the
 *    link's path names the previous person. A same-named record that was pushed
 *    to "-2" by the squatter then gets the clean URL, keeping a redirect.
 * 3. For a look: events that hold a record somebody else's event renamed, and
 *    never renamed it themselves, so the row may still mean the earlier person.
 *
 * Dry run by default; nothing is written until "Apply" is pressed, and every
 * change is written to the activity log of each event the record appears at.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The reviewed fixes from the 19 November 2026 scan of the prod database.
 * Each one names the state it expects; law_speakers_repair_check_fix() refuses
 * to apply any whose record has changed since.
 *
 * @return array[]
 */
function law_speakers_repair_known_fixes() {
	return array(
		array(
			'key'     => 'campbell-herbert-website',
			'type'    => 'identity',
			'speaker' => 2028,
			'name_is' => 'Campbell Herbert',
			'field'   => '_law_website',
			'label'   => 'website',
			'from'    => 'https://www.hendersonchambers.co.uk/barristers/kathleen-donnelly-kc/',
			'to'      => 'https://www.mcdermottlaw.com/people/campbell-herbert/',
			'why'     => 'Event 1061\'s save of 18 November turned this record into Kathleen Donnelly KC; the name was put back on 19 November, the website was not.',
		),
		array(
			'key'     => 'lord-hamblen-email',
			'type'    => 'identity',
			'speaker' => 823,
			'name_is' => 'Lord Hamblen',
			'field'   => '_law_speaker_email',
			'label'   => 'email',
			'from'    => 'sarra@londonarbitrationweek.com',
			'to'      => '',
			'why'     => 'The record began as Sarra Kay and still holds an address of hers, which is wrong contact data for Lord Hamblen. Sarra has her own record (1386).',
		),
		array(
			'key'     => 'andy-moody-on-1073',
			'type'    => 'repoint',
			'event'   => 1073,
			'speaker' => 954,
			'name_is' => 'Ed Poulton',
			// The row must still describe Andy: if the host has since adapted it to
			// Ed Poulton, moving it to Andy would be the wrong repair.
			'row_is'  => array( 'organisation' => 'Travers Smith LLP' ),
			'person'  => array(
				'first_name' => 'Andy',
				'last_name'  => 'Moody',
				'email'      => 'andy.moody@traverssmith.com',
				'website'    => 'https://www.traverssmith.com/people/andy-moody/',
			),
			'why'     => 'Event 999\'s save of 25 September turned Andy Moody\'s record into Ed Poulton. This event\'s row (Travers Smith, Andy\'s biography and photo) still means Andy.',
		),
	);
}

/**
 * Whether a known fix still applies, and what it would do.
 *
 * @param array $fix One entry of law_speakers_repair_known_fixes().
 * @return array{ok:bool,note:string,target:int}
 */
function law_speakers_repair_check_fix( array $fix ) {
	$speaker = get_post( (int) $fix['speaker'] );
	if ( ! $speaker || LAW_SPEAKER_CPT !== $speaker->post_type || 'trash' === $speaker->post_status ) {
		return array( 'ok' => false, 'note' => 'The record no longer exists.', 'target' => 0 );
	}
	if ( ! law_speaker_same_person( law_speaker_raw_name( $speaker->ID ), $fix['name_is'] ) ) {
		return array( 'ok' => false, 'note' => sprintf( 'The record is now "%s", not "%s".', law_speaker_raw_name( $speaker->ID ), $fix['name_is'] ), 'target' => 0 );
	}

	if ( 'identity' === $fix['type'] ) {
		$now = (string) law_event_meta( $speaker->ID, $fix['field'] );
		if ( $now !== $fix['from'] ) {
			return array( 'ok' => false, 'note' => sprintf( 'Already changed: the %s is now "%s".', $fix['label'], $now ), 'target' => 0 );
		}
		return array( 'ok' => true, 'note' => sprintf( '%s "%s" → "%s"', ucfirst( $fix['label'] ), $fix['from'], '' === $fix['to'] ? '(none)' : $fix['to'] ), 'target' => 0 );
	}

	$holders = law_speakers_repair_event_posts( (int) $fix['event'] );
	if ( ! $holders ) {
		return array( 'ok' => false, 'note' => 'The event no longer exists.', 'target' => 0 );
	}
	$holds = false;
	$says  = array();
	$ids   = array();
	foreach ( $holders as $post_id ) {
		foreach ( law_event_meta( $post_id, '_law_speakers' ) as $row ) {
			$ids[] = (int) ( $row['speaker_id'] ?? 0 );
			if ( (int) ( $row['speaker_id'] ?? 0 ) === (int) $speaker->ID ) {
				$holds  = true;
				$says[] = trim( (string) ( $row['organisation'] ?? '' ) );
			}
		}
	}
	if ( ! $holds ) {
		return array( 'ok' => false, 'note' => 'The event no longer lists this record.', 'target' => 0 );
	}
	$expected = (string) ( $fix['row_is']['organisation'] ?? '' );
	if ( '' !== $expected && ! in_array( mb_strtolower( $expected ), array_map( 'mb_strtolower', $says ), true ) ) {
		return array( 'ok' => false, 'note' => sprintf( 'The row no longer says "%s" (it says "%s"), so it may now mean %s.', $expected, implode( '", "', $says ), $fix['name_is'] ), 'target' => 0 );
	}
	$name   = law_speaker_full_name( $fix['person']['first_name'], $fix['person']['last_name'] );
	$target = law_speakers_repair_find_person( $fix['person'] );
	if ( $target && in_array( $target, $ids, true ) ) {
		return array( 'ok' => false, 'note' => sprintf( 'The event already lists %s (#%d) separately.', $name, $target ), 'target' => $target );
	}
	return array(
		'ok'     => true,
		'note'   => $target
			? sprintf( 'Row moves to %s own record #%d (/speakers/%s/).', law_speakers_repair_possessive( $name ), $target, get_post_field( 'post_name', $target ) )
			: sprintf( 'Row moves to a new record for %s.', $name ),
		'target' => $target,
	);
}

/**
 * The person's own record: by email and name, then by exact name (a reviewed
 * fix may name a person whose record carries a different address).
 *
 * @param array $person first_name, last_name, email, website.
 */
function law_speakers_repair_find_person( array $person ) {
	$name = law_speaker_full_name( $person['first_name'], $person['last_name'] );
	return law_speaker_find_existing( $person['email'], $name ) ?: law_speaker_find_existing( '', $name );
}

/** An event and its sessions: every post whose speaker rows belong to it. */
function law_speakers_repair_event_posts( $event_id ) {
	$event = get_post( (int) $event_id );
	if ( ! $event || LAW_EVENT_CPT !== $event->post_type ) {
		return array();
	}
	return array_merge( array( (int) $event->ID ), array_map( 'intval', law_event_session_ids( $event->ID ) ) );
}

/** The events (never sessions) a speaker appears at, in any status. */
function law_speakers_repair_holder_events( $speaker_id ) {
	$events = array();
	foreach ( law_speaker_holder_ids( (int) $speaker_id ) as $post_id ) {
		$event = LAW_SESSION_CPT === get_post_type( $post_id ) ? (int) wp_get_post_parent_id( $post_id ) : (int) $post_id;
		if ( $event ) {
			$events[ $event ] = true;
		}
	}
	return array_keys( $events );
}

/**
 * The photo a speaker's own event row carries: the public first appearance,
 * else the first appearance in any status.
 */
function law_speakers_repair_row_photo( $speaker_id ) {
	$photo = (int) law_speaker_first_appearance( (int) $speaker_id )['photo_id'];
	if ( ! $photo ) {
		$photo = (int) law_speaker_first_appearance( (int) $speaker_id, array_keys( law_event_statuses() ) )['photo_id'];
	}
	return $photo;
}

/** The last identifying word of a name ('' for none), for telling a misspelt URL from somebody else's. */
function law_speakers_repair_surname( $name ) {
	$tokens = law_speaker_name_tokens( $name );
	return $tokens ? (string) $tokens[ count( $tokens ) - 1 ] : '';
}

/** The person a speaker slug was made from: "amy-worley-3" → "amy worley". */
function law_speakers_repair_slug_name( $slug ) {
	return str_replace( '-', ' ', preg_replace( '/-\d+$/', '', (string) $slug ) );
}

/**
 * The words of a name worth looking for in somebody's email address or web
 * page: four letters or more, accents folded, titles left out. Deliberately not
 * law_speaker_name_tokens(), which drops "judge" as a title and so would miss
 * John Judge's jjudge@ address.
 *
 * @param string $name Full name.
 * @return string[]
 */
function law_speakers_repair_name_words( $name ) {
	$words = preg_split( '/[^a-z0-9]+/', strtolower( remove_accents( law_speaker_normalise_name( $name ) ) ) );
	return array_values( array_filter( $words, fn( $w ) => strlen( $w ) >= 4 && ! in_array( $w, array( 'lord', 'lady', 'dame', 'professor', 'justice', 'honourable' ), true ) ) );
}

/**
 * Whether a website or an email address names one of the people a record used
 * to be and not the person it is now. For a website only the PATH is read, as
 * whole words (a firm's domain can carry the new person's surname, gherson.com,
 * while the page is still somebody else's); for an email the part before the @,
 * by substring, because addresses run names together (jjudge@, dbaizeau@).
 *
 * @param string   $value     The website or email address.
 * @param string   $kind      'website' or 'email'.
 * @param string[] $old_names Names the record used to carry.
 * @param string   $current   The record's name now.
 */
function law_speakers_repair_names_other( $value, $kind, array $old_names, $current ) {
	$value = strtolower( remove_accents( (string) $value ) );
	if ( 'email' === $kind ) {
		$hay   = (string) strstr( $value, '@', true );
		$found = fn( $word ) => '' !== $hay && str_contains( $hay, $word );
	} else {
		$path  = preg_split( '/[^a-z0-9]+/', (string) wp_parse_url( $value, PHP_URL_PATH ) );
		$found = fn( $word ) => in_array( $word, $path, true );
	}
	foreach ( law_speakers_repair_name_words( $current ) as $word ) {
		if ( $found( $word ) ) {
			return false;
		}
	}
	foreach ( $old_names as $old ) {
		foreach ( law_speakers_repair_name_words( $old ) as $word ) {
			if ( $found( $word ) ) {
				return true;
			}
		}
	}
	return false;
}

/**
 * The speaker renames in the activity log, oldest first. Per record: every
 * name it has carried ('names'), whether it was ever turned into a different
 * person ('swapped'), and the last name each event set on it ('last_set').
 * The old entries' context JSON lost its escaping (fixed in log.php the same
 * day), so the record ID is read by pattern and the names from the message.
 *
 * @return array<int,array{names:string[],swapped:bool,last_set:array<int,string>}>
 */
function law_speakers_repair_history() {
	global $wpdb;
	$entries = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT c.comment_post_ID AS event_id, c.comment_content AS message, m.meta_value AS context
			 FROM {$wpdb->comments} c
			 JOIN {$wpdb->commentmeta} m ON m.comment_id = c.comment_ID AND m.meta_key = '_law_log_context'
			 WHERE c.comment_type = %s AND c.comment_content LIKE %s
			 ORDER BY c.comment_date_gmt ASC, c.comment_ID ASC",
			LAW_EVENT_LOG_TYPE,
			'Speaker profile updated%'
		)
	);
	$history = array();
	foreach ( $entries as $entry ) {
		if ( ! preg_match( '/"speaker":(\d+)/', (string) $entry->context, $m )
			|| ! preg_match( '/name "(.*?)" → "(.*?)"(?:, |\.$)/u', html_entity_decode( $entry->message, ENT_QUOTES, 'UTF-8' ), $n ) ) {
			continue;
		}
		$id = (int) $m[1];
		$history[ $id ] = $history[ $id ] ?? array( 'names' => array(), 'swapped' => false, 'last_set' => array() );
		$history[ $id ]['names'][]                              = $n[1];
		$history[ $id ]['names'][]                              = $n[2];
		$history[ $id ]['last_set'][ (int) $entry->event_id ] = $n[2];
		if ( ! law_speaker_same_person( $n[1], $n[2] ) ) {
			$history[ $id ]['swapped'] = true;
		}
	}
	return $history;
}

/**
 * Everything the panel shows: the known fixes with their state, the profiles
 * to repair, the follow-on URL tidies, and the rows for a human to look at.
 *
 * A profile is listed when its URL is named after somebody else, or when the
 * log shows it was once turned into a different person, and something about it
 * still belongs to a person it used to be: the URL, the fallback photo (reset to
 * the photo on its own event row), the website or the email address (cleared).
 *
 * @return array{fixes:array[],profiles:array[],follow:array[],review:array[]}
 */
function law_speakers_repair_scan() {
	$fixes = array();
	foreach ( law_speakers_repair_known_fixes() as $fix ) {
		$fixes[] = $fix + array( 'check' => law_speakers_repair_check_fix( $fix ) );
	}

	$history  = law_speakers_repair_history();
	// A field a known fix is about to set is not also offered for clearing.
	$fixed = array();
	foreach ( $fixes as $fix ) {
		if ( 'identity' === $fix['type'] && $fix['check']['ok'] ) {
			$fixed[ $fix['speaker'] ][ $fix['field'] ] = true;
		}
	}
	$profiles = array();
	$speakers = get_posts(
		array(
			'post_type'      => LAW_SPEAKER_CPT,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	foreach ( $speakers as $speaker ) {
		$name = law_speaker_raw_name( $speaker->ID );
		if ( '' === $name ) {
			continue;
		}
		$slug_name = law_speakers_repair_slug_name( $speaker->post_name );
		// Named after somebody else, and a rename would actually improve it.
		$reslug  = ! law_speaker_same_person( $slug_name, $name ) && preg_replace( '/-\d+$/', '', $speaker->post_name ) !== sanitize_title( $name );
		$swapped = ! empty( $history[ $speaker->ID ]['swapped'] );
		if ( ! $reslug && ! $swapped ) {
			continue;
		}
		$old_names = array_values(
			array_unique(
				array_filter(
					array_merge( $reslug ? array( ucwords( $slug_name ) ) : array(), array_reverse( $history[ $speaker->ID ]['names'] ?? array() ) ),
					fn( $old ) => ! law_speaker_same_person( $old, $name ) && ! law_speaker_is_placeholder_name( $old )
				)
			)
		);
		$thumb   = (int) get_post_thumbnail_id( $speaker->ID );
		$photo   = law_speakers_repair_row_photo( $speaker->ID );
		$website = (string) law_event_meta( $speaker->ID, '_law_website' );
		$email   = (string) law_event_meta( $speaker->ID, '_law_speaker_email' );
		$profile = array(
			'id'          => (int) $speaker->ID,
			'name'        => $name,
			'old_name'    => $old_names[0] ?? ucwords( $slug_name ),
			'slug'        => $speaker->post_name,
			// The URL it will actually get: wp_unique_post_slug() is what
			// wp_update_post() runs, so a name somebody else already holds shows
			// as "-2" here too (a slug freed by this same run is not foreseen).
			'slug_new'    => $reslug ? wp_unique_post_slug( sanitize_title( $name ), $speaker->ID, 'publish', LAW_SPEAKER_CPT, 0 ) : '',
			// The same surname means the old URL is this person misspelt ("Noami"
			// for "Naomi"), not somebody else, so it keeps a redirect.
			'redirect'    => $reslug && law_speakers_repair_surname( $slug_name ) === law_speakers_repair_surname( $name ),
			'photo_from'  => $thumb,
			'photo_to'    => ( $photo && $photo !== $thumb ) ? $photo : 0,
			'website'     => $website,
			'clear_site'  => empty( $fixed[ $speaker->ID ]['_law_website'] ) && $old_names && law_speakers_repair_names_other( $website, 'website', $old_names, $name ),
			'email'       => $email,
			'clear_email' => empty( $fixed[ $speaker->ID ]['_law_speaker_email'] ) && $old_names && law_speakers_repair_names_other( $email, 'email', $old_names, $name ),
			'events'      => law_speakers_repair_holder_events( $speaker->ID ),
		);
		if ( $reslug || $profile['photo_to'] || $profile['clear_site'] || $profile['clear_email'] ) {
			$profiles[ $speaker->ID ] = $profile;
		}
	}

	// The URL each moving profile will end up on: its clean name when nobody
	// holds it, or when the holder is itself moving off it in this run (apply
	// makes a second pass for exactly that), else what wp_unique_post_slug()
	// gives. Apply resolves it again against the live state.
	$moving = array_keys( array_filter( $profiles, fn( $p ) => '' !== $p['slug_new'] ) );
	foreach ( $moving as $id ) {
		$base  = sanitize_title( $profiles[ $id ]['name'] );
		$owner = law_speakers_repair_slug_owner( $base );
		if ( ! $owner || $owner === $id || in_array( $owner, $moving, true ) ) {
			$profiles[ $id ]['slug_new'] = $base;
		}
	}

	// A same-named record pushed to "<slug>-2" by the squatter takes the clean
	// URL once it is free. Predicted here, decided again at apply time.
	$follow = array();
	foreach ( $profiles as $profile ) {
		if ( '' === $profile['slug_new'] || $profile['redirect'] ) {
			continue;
		}
		$base  = preg_replace( '/-\d+$/', '', $profile['slug'] );
		$owner = law_speakers_repair_slug_owner( $base );
		if ( $owner && ( ! isset( $profiles[ $owner ] ) || '' === $profiles[ $owner ]['slug_new'] ) ) {
			continue; // Somebody who is staying holds it.
		}
		$heir = law_speakers_repair_slug_heir( $base, $moving );
		if ( $heir ) {
			$follow[ $heir ] = array( 'id' => $heir, 'name' => law_speaker_raw_name( $heir ), 'slug' => get_post_field( 'post_name', $heir ), 'slug_new' => $base );
		}
	}

	return array(
		'fixes'    => $fixes,
		'profiles' => array_values( $profiles ),
		'follow'   => array_values( $follow ),
		'review'   => law_speakers_repair_review_rows( $fixes, $history ),
	);
}

/** The non-trashed speaker that holds a slug exactly, or 0. */
function law_speakers_repair_slug_owner( $slug ) {
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_name = %s AND post_status <> 'trash' LIMIT 1",
			LAW_SPEAKER_CPT,
			$slug
		)
	);
}

/**
 * The published speaker who should own a freed slug: their name IS the slug's
 * person and they sit on "<slug>-N". Records being repaired are excluded.
 *
 * @param string $base    The freed slug.
 * @param int[]  $exclude Speaker IDs being moved off their slugs.
 */
function law_speakers_repair_slug_heir( $base, array $exclude ) {
	global $wpdb;
	$ids = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_name REGEXP %s ORDER BY ID ASC",
			LAW_SPEAKER_CPT,
			'^' . preg_quote( $base, '/' ) . '-[0-9]+$'
		)
	);
	foreach ( array_map( 'intval', $ids ) as $id ) {
		if ( ! in_array( $id, $exclude, true ) && sanitize_title( law_speaker_raw_name( $id ) ) === $base ) {
			return $id;
		}
	}
	return 0;
}

/**
 * Events whose row may still mean somebody the record no longer is: they hold
 * a record that was once turned into a different person, and either never
 * renamed it themselves or last set it to a name it no longer carries (another
 * event's later save overrode theirs, as events 1041 and 1042 did to each other
 * in October). The row's organisation, photo file and biography are shown for
 * a human to judge; nothing here is changed by the panel.
 *
 * @param array[] $fixes   The known fixes, whose rows are already handled.
 * @param array   $history law_speakers_repair_history().
 * @return array[]
 */
function law_speakers_repair_review_rows( array $fixes, array $history ) {
	$handled = array();
	foreach ( $fixes as $fix ) {
		if ( 'repoint' === $fix['type'] ) {
			$handled[ $fix['speaker'] . ':' . $fix['event'] ] = true;
		}
	}

	$rows = array();
	foreach ( $history as $speaker_id => $record ) {
		if ( empty( $record['swapped'] ) || 'publish' !== get_post_status( $speaker_id ) ) {
			continue;
		}
		$name = law_speaker_raw_name( $speaker_id );
		foreach ( law_speakers_repair_holder_events( $speaker_id ) as $event_id ) {
			$set = $record['last_set'][ $event_id ] ?? null;
			if ( ( null !== $set && law_speaker_same_name( $set, $name ) ) || isset( $handled[ $speaker_id . ':' . $event_id ] ) ) {
				continue;
			}
			$row = law_speaker_appearance_for_event( $speaker_id, $event_id );
			if ( ! $row ) {
				continue;
			}
			$photo  = (int) ( $row['photo_id'] ?? 0 );
			$rows[] = array(
				'speaker_id'   => (int) $speaker_id,
				'name'         => $name,
				'event_id'     => (int) $event_id,
				'set'          => (string) $set,
				'organisation' => (string) ( $row['organisation'] ?? '' ),
				'photo'        => $photo ? wp_basename( (string) get_post_meta( $photo, '_wp_attached_file', true ) ) : '',
				'bio'          => wp_html_excerpt( wp_strip_all_tags( (string) ( $row['bio'] ?? '' ) ), 90, '…' ),
			);
		}
	}
	return $rows;
}

/**
 * Write one line to the activity log of every event a speaker appears at.
 *
 * @param int    $speaker_id Speaker post ID.
 * @param string $message    The line.
 * @param array  $context    Extra context.
 */
function law_speakers_repair_log( $speaker_id, $message, array $context = array() ) {
	foreach ( law_speakers_repair_holder_events( $speaker_id ) as $event_id ) {
		law_event_log( $event_id, $message, array( 'action' => 'speaker_repaired', 'speaker' => (int) $speaker_id, 'source' => 'repair' ) + $context );
	}
}

/**
 * Apply the ticked fixes and profiles. Idempotent: everything is re-checked
 * against the live state first, so a second run finds nothing to do.
 *
 * @param string[] $fix_keys    Known-fix keys to apply.
 * @param int[]    $profile_ids Speaker IDs whose profile to repair.
 * @return array{lines:string[],failed:string[]}
 */
function law_speakers_repair_apply( array $fix_keys, array $profile_ids ) {
	$lines  = array();
	$failed = array();

	// 1. Known fixes. Re-pointing first, so the photos below read the corrected rows.
	$fixes = law_speakers_repair_known_fixes();
	usort( $fixes, fn( $a, $b ) => ( 'repoint' === $b['type'] ) <=> ( 'repoint' === $a['type'] ) );
	foreach ( $fixes as $fix ) {
		if ( ! in_array( $fix['key'], $fix_keys, true ) ) {
			continue;
		}
		$check = law_speakers_repair_check_fix( $fix );
		if ( ! $check['ok'] ) {
			$failed[] = $fix['key'] . ': ' . $check['note'];
			continue;
		}

		if ( 'identity' === $fix['type'] ) {
			law_event_update_meta( (int) $fix['speaker'], $fix['field'], $fix['to'] );
			law_speakers_repair_log(
				(int) $fix['speaker'],
				sprintf( 'Speaker profile repaired: %s %s changed from "%s" to "%s". %s', law_speakers_repair_possessive( $fix['name_is'] ), $fix['label'], $fix['from'], '' === $fix['to'] ? '(none)' : $fix['to'], $fix['why'] ),
				array( 'field' => $fix['field'] )
			);
			$lines[] = sprintf( '#%d %s: %s', $fix['speaker'], $fix['name_is'], $check['note'] );
			continue;
		}

		// Re-point: the person's own record, created when they have none.
		$target = law_speakers_repair_find_person( $fix['person'] ) ?: law_speaker_upsert( $fix['person'] );
		if ( ! $target ) {
			$failed[] = $fix['key'] . ': could not create the speaker record.';
			continue;
		}
		$touched = array();
		foreach ( law_speakers_repair_event_posts( (int) $fix['event'] ) as $post_id ) {
			$rows    = law_event_meta( $post_id, '_law_speakers' );
			$changed = false;
			foreach ( $rows as &$row ) {
				if ( (int) ( $row['speaker_id'] ?? 0 ) === (int) $fix['speaker'] ) {
					$row['speaker_id'] = $target;
					$changed           = true;
				}
			}
			unset( $row );
			if ( $changed ) {
				law_event_update_meta( $post_id, '_law_speakers', $rows );
				$touched[] = $post_id;
			}
		}
		law_speaker_after_rows_changed( $touched );
		$name = law_speaker_full_name( $fix['person']['first_name'], $fix['person']['last_name'] );
		law_event_log(
			(int) $fix['event'],
			sprintf( 'Speaker repaired: this event\'s row for %s pointed at the record of %s (#%d); it now points at %s own record (#%d). %s', $name, $fix['name_is'], $fix['speaker'], law_speakers_repair_possessive( $name ), $target, $fix['why'] ),
			array( 'action' => 'speaker_repaired', 'speaker' => $target, 'replaced' => (int) $fix['speaker'], 'source' => 'repair' )
		);
		$lines[] = sprintf( 'Event #%d: %s now points at #%d instead of #%d (%s).', $fix['event'], $name, $target, $fix['speaker'], $fix['name_is'] );
	}
	law_speakers_flush_maps();

	// 2. Profiles, re-read from the live state.
	$scan  = law_speakers_repair_scan();
	$freed = array();
	foreach ( $scan['profiles'] as $profile ) {
		if ( ! in_array( $profile['id'], $profile_ids, true ) ) {
			continue;
		}
		$id    = $profile['id'];
		$notes = array();

		if ( $profile['clear_site'] ) {
			law_event_update_meta( $id, '_law_website', '' );
			$notes[] = sprintf( 'website "%s" cleared (it is %s page)', $profile['website'], law_speakers_repair_possessive( $profile['old_name'] ) );
		}
		if ( $profile['clear_email'] ) {
			law_event_update_meta( $id, '_law_speaker_email', '' );
			$notes[] = sprintf( 'email "%s" cleared (it is %s address)', $profile['email'], law_speakers_repair_possessive( $profile['old_name'] ) );
		}
		if ( $profile['photo_to'] ) {
			set_post_thumbnail( $id, $profile['photo_to'] );
			$notes[] = 'fallback photo set to the one on their event row';
		}

		if ( '' !== $profile['slug_new'] ) {
			$old = $profile['slug'];
			// The clean name, resolved now rather than at scan time: an earlier
			// profile in this same run may just have freed it (Andrew Spink KC
			// leaving lucian-ilie for Lucian Ilie).
			wp_update_post( array( 'ID' => $id, 'post_name' => sanitize_title( $profile['name'] ) ) );
			$new = (string) get_post_field( 'post_name', $id );
			if ( $profile['redirect'] ) {
				// The old URL is this person misspelt: core's redirect stays.
				if ( $new !== $old ) {
					$notes[] = sprintf( 'URL /speakers/%s/ → /speakers/%s/ (the old one redirects)', $old, $new );
				}
			} else {
				// No redirect: the old URL is named after somebody else.
				delete_post_meta( $id, '_wp_old_slug', $old );
				if ( $new !== $old ) {
					$notes[] = sprintf( 'URL /speakers/%s/ → /speakers/%s/', $old, $new );
					$freed[] = preg_replace( '/-\d+$/', '', $old );
				}
			}
		}

		if ( $notes ) {
			law_speakers_repair_log(
				$id,
				sprintf( 'Speaker profile repaired: %s (an earlier save had turned %s record into %s, and these still belonged to %s).', implode( '; ', $notes ), law_speakers_repair_possessive( $profile['old_name'] ), $profile['name'], $profile['old_name'] )
			);
			$lines[] = sprintf( '#%d %s: %s', $id, $profile['name'], implode( '; ', $notes ) );
		}
	}

	// A profile that landed on "<name>-N" only because another profile in this
	// run had not yet left "<name>" now takes it. The "-N" it held for a moment
	// was never published to anybody, so it gets no redirect.
	foreach ( $scan['profiles'] as $profile ) {
		if ( '' === $profile['slug_new'] || ! in_array( $profile['id'], $profile_ids, true ) ) {
			continue;
		}
		$id      = $profile['id'];
		$base    = sanitize_title( $profile['name'] );
		$current = (string) get_post_field( 'post_name', $id );
		if ( $current === $base || 0 !== strpos( $current, $base . '-' ) || law_speakers_repair_slug_owner( $base ) ) {
			continue;
		}
		wp_update_post( array( 'ID' => $id, 'post_name' => $base ) );
		delete_post_meta( $id, '_wp_old_slug', $current );
		law_speakers_repair_log( $id, sprintf( 'Speaker profile URL settled on /speakers/%s/ once this run freed it.', $base ) );
		$lines[] = sprintf( '#%d %s: URL settled on /speakers/%s/', $id, $profile['name'], $base );
	}

	// 3. A same-named record pushed to "-N" takes the freed clean URL, keeping
	// a redirect from its old one (it is the same person).
	foreach ( array_unique( $freed ) as $base ) {
		if ( law_speakers_repair_slug_owner( $base ) ) {
			continue;
		}
		$heir = law_speakers_repair_slug_heir( $base, array() );
		if ( ! $heir ) {
			continue;
		}
		$old = (string) get_post_field( 'post_name', $heir );
		wp_update_post( array( 'ID' => $heir, 'post_name' => $base ) );
		$new = (string) get_post_field( 'post_name', $heir );
		if ( $new !== $old ) {
			law_speakers_repair_log( $heir, sprintf( 'Speaker profile URL tidied: /speakers/%s/ → /speakers/%s/, now that the clean URL is free (the old one redirects).', $old, $new ) );
			$lines[] = sprintf( '#%d %s: URL /speakers/%s/ → /speakers/%s/ (old URL redirects)', $heir, law_speaker_raw_name( $heir ), $old, $new );
		}
	}

	law_speakers_flush_maps();

	return array( 'lines' => $lines, 'failed' => $failed );
}

/** House possessive: a bare apostrophe after a final s ("Geoff Nicholas' page"), else 's. */
function law_speakers_repair_possessive( $name ) {
	return preg_match( '/s$/i', (string) $name ) ? $name . "'" : $name . "'s";
}

/** A small attachment label for the tables. */
function law_speakers_repair_photo_label( $attachment_id ) {
	return $attachment_id ? wp_basename( (string) get_post_meta( (int) $attachment_id, '_wp_attached_file', true ) ) : 'none';
}

/**
 * The LAW → Migration card: the scan, the tick boxes and the Apply button.
 * Called from law_migration_admin_page().
 */
function law_speakers_repair_panel() {
	$notice = '';
	if ( isset( $_POST['law_repair_speakers_nonce'] ) ) {
		check_admin_referer( 'law_repair_speakers', 'law_repair_speakers_nonce' );
		$result = law_speakers_repair_apply(
			array_map( 'sanitize_key', (array) wp_unslash( $_POST['law_repair_speakers_fixes'] ?? array() ) ),
			array_map( 'absint', (array) ( $_POST['law_repair_speakers_profiles'] ?? array() ) )
		);
		$notice = sprintf(
			'<div class="notice notice-%s"><p><strong>%d change(s) applied.</strong>%s</p>%s</div>',
			$result['failed'] ? 'warning' : 'success',
			count( $result['lines'] ),
			$result['failed'] ? ' Skipped: ' . esc_html( implode( '; ', $result['failed'] ) ) : '',
			$result['lines'] ? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $result['lines'] ) ) . '</li></ul>' : ''
		);
	}

	$scan = law_speakers_repair_scan();
	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	$open = array_filter( $scan['fixes'], fn( $fix ) => $fix['check']['ok'] );
	?>
	<h2>Repair: speakers turned into somebody else</h2>
	<p class="description" style="max-width:900px">
		Until 19 November 2026, typing a new person over an existing speaker row on the event form renamed the shared
		speaker record, so the change showed on every event that person appears at. The form now adds the new person
		instead. This repairs what was left: rows still pointing at the wrong person, and profiles whose URL, fallback
		photo or website still belong to the person the record held before. Old URLs named after somebody else stop
		resolving rather than redirect. Every change is written to the activity log of each event the speaker appears at.
	</p>

	<?php if ( ! $open && ! $scan['profiles'] ) : ?>
		<p><strong>Nothing to repair.</strong></p>
	<?php else : ?>
		<form method="post">
			<?php wp_nonce_field( 'law_repair_speakers', 'law_repair_speakers_nonce' ); ?>

			<h3>Known fixes (19 November 2026 scan)</h3>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th style="width:2em"></th><th>Record</th><th>Change</th><th>Why</th></tr></thead>
				<tbody>
				<?php foreach ( $scan['fixes'] as $fix ) : ?>
					<tr>
						<td><?php if ( $fix['check']['ok'] ) : ?><input type="checkbox" name="law_repair_speakers_fixes[]" value="<?php echo esc_attr( $fix['key'] ); ?>" checked><?php endif; ?></td>
						<td>
							#<?php echo esc_html( (string) $fix['speaker'] ); ?> <?php echo esc_html( $fix['name_is'] ); ?>
							<?php if ( 'repoint' === $fix['type'] ) : ?><br><span class="description">on event #<?php echo esc_html( (string) $fix['event'] ); ?></span><?php endif; ?>
						</td>
						<td><?php echo $fix['check']['ok'] ? esc_html( $fix['check']['note'] ) : '<em>Skipped: ' . esc_html( $fix['check']['note'] ) . '</em>'; ?></td>
						<td class="description"><?php echo esc_html( $fix['why'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $scan['profiles'] ) : ?>
				<h3>Profiles still carrying somebody else's details (<?php echo esc_html( (string) count( $scan['profiles'] ) ); ?>)</h3>
				<p class="description">Untick any that is a genuine change of name rather than a replacement.</p>
				<table class="widefat striped" style="max-width:1100px">
					<thead><tr><th style="width:2em"></th><th>Speaker</th><th>URL</th><th>Fallback photo</th><th>Website</th><th>Email</th><th>Events</th></tr></thead>
					<tbody>
					<?php foreach ( $scan['profiles'] as $profile ) : ?>
						<tr>
							<td><input type="checkbox" name="law_repair_speakers_profiles[]" value="<?php echo esc_attr( (string) $profile['id'] ); ?>" checked></td>
							<td>#<?php echo esc_html( (string) $profile['id'] ); ?> <strong><?php echo esc_html( $profile['name'] ); ?></strong></td>
							<td><?php echo '' !== $profile['slug_new'] ? esc_html( '/speakers/' . $profile['slug'] . '/ → /speakers/' . $profile['slug_new'] . '/' . ( $profile['redirect'] ? ' (old URL redirects)' : '' ) ) : '<em>unchanged</em>'; ?></td>
							<td><?php echo $profile['photo_to'] ? esc_html( law_speakers_repair_photo_label( $profile['photo_from'] ) . ' → ' . law_speakers_repair_photo_label( $profile['photo_to'] ) ) : '<em>unchanged</em>'; ?></td>
							<td><?php echo $profile['clear_site'] ? esc_html( 'Clear ' . $profile['website'] ) : '<em>unchanged</em>'; ?></td>
							<td><?php echo $profile['clear_email'] ? esc_html( 'Clear ' . $profile['email'] ) : '<em>unchanged</em>'; ?></td>
							<td><?php echo esc_html( implode( ', ', array_map( fn( $id ) => '#' . $id, $profile['events'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( $scan['follow'] ) : ?>
				<h3>Then, once their clean URL is free</h3>
				<ul style="margin-left:1.5em;list-style:disc">
					<?php foreach ( $scan['follow'] as $follow ) : ?>
						<li>#<?php echo esc_html( (string) $follow['id'] ); ?> <?php echo esc_html( $follow['name'] ); ?>: /speakers/<?php echo esc_html( $follow['slug'] ); ?>/ → /speakers/<?php echo esc_html( $follow['slug_new'] ); ?>/ (the old URL redirects)</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>

			<?php submit_button( 'Apply the ticked repairs', 'primary', 'submit', true, array( 'onclick' => "return confirm('Apply the ticked speaker repairs? Each change is written to the activity log of the events involved.');" ) ); ?>
		</form>
	<?php endif; ?>

	<?php if ( $scan['review'] ) : ?>
		<h3>For a look (not changed by this panel)</h3>
		<p class="description" style="max-width:900px">These events hold a speaker record that was once turned into a different person, and either never named it themselves or last named it something it no longer is. If the row below describes somebody else, remove it on the event and add the right person.</p>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th>Event</th><th>Speaker now</th><th>This event last set</th><th>Row organisation</th><th>Row photo</th><th>Row biography</th></tr></thead>
			<tbody>
			<?php foreach ( $scan['review'] as $row ) : ?>
				<tr>
					<td><a href="<?php echo esc_url( get_edit_post_link( $row['event_id'] ) ); ?>">#<?php echo esc_html( (string) $row['event_id'] ); ?> <?php echo esc_html( get_the_title( $row['event_id'] ) ); ?></a></td>
					<td>#<?php echo esc_html( (string) $row['speaker_id'] ); ?> <?php echo esc_html( $row['name'] ); ?></td>
					<td><?php echo '' !== $row['set'] ? esc_html( $row['set'] ) : '<em>never named it</em>'; ?></td>
					<td><?php echo esc_html( $row['organisation'] ); ?></td>
					<td><?php echo esc_html( $row['photo'] ); ?></td>
					<td class="description"><?php echo esc_html( $row['bio'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>
	<?php
}
