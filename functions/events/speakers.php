<?php
/**
 * Speaker records (law_speaker): dedupe at save time, lookups, and the
 * relationship helpers events and sessions use (EVENTS_4.1_REBUILD.md §3.2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Find an existing speaker: by email first (same email = same person), then
 * by normalised full name.
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
		if ( law_speaker_normalise_name( get_the_title( $candidate ) ) === $normalised ) {
			return (int) $candidate;
		}
	}
	return 0;
}

/** Case- and whitespace-insensitive name key, mirroring the old dedupe. */
function law_speaker_normalise_name( $name ) {
	return preg_replace( '/\s+/', ' ', mb_strtolower( trim( (string) $name ) ) );
}

/**
 * Create or update a speaker from submitted row data. New data fills gaps;
 * it never blanks an existing value (matching the old backfill rule).
 *
 * Only identity fields live on the post: name, email, website. Organisation,
 * job title, photo and biography are per appearance and belong on the event's
 * _law_speakers row (law_events_form_save_speakers()); the featured image and
 * the biography set here are fallbacks only, used when the appearance has
 * none of its own.
 *
 * @param array $data name, email, website, bio, photo_id (attachment ID).
 *                    organisation/job_title are accepted and ignored.
 * @return int Post ID, 0 on failure.
 */
function law_speaker_upsert( array $data, array $log = array() ) {
	$name = trim( (string) ( $data['name'] ?? '' ) );
	if ( '' === $name ) {
		return 0;
	}

	$post_id  = law_speaker_find_existing( (string) ( $data['email'] ?? '' ), $name );
	$is_new   = ! $post_id;
	$filled   = array(); // Fields this submission backfilled on a PRE-EXISTING record.

	if ( ! $post_id ) {
		$post_id = wp_insert_post(
			array(
				'post_type'    => LAW_SPEAKER_CPT,
				'post_status'  => 'publish', // Visibility is derived from events, not from status.
				'post_title'   => $name,
				'post_content' => sanitize_textarea_field( (string) ( $data['bio'] ?? '' ) ),
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
			wp_update_post( array( 'ID' => $post_id, 'post_content' => sanitize_textarea_field( (string) $data['bio'] ) ) );
			$filled[] = 'biography';
		}
	}

	$meta_map = array(
		'email'   => '_law_speaker_email',
		'website' => '_law_website',
	);
	foreach ( $meta_map as $field => $key ) {
		$value = trim( (string) ( $data[ $field ] ?? '' ) );
		if ( '' !== $value && '' === (string) law_event_meta( $post_id, $key ) ) {
			law_event_update_meta( $post_id, $key, 'email' === $field ? mb_strtolower( $value ) : $value );
			if ( ! $is_new ) {
				$filled[] = $field;
			}
		}
	}

	if ( ! empty( $data['photo_id'] ) && ! has_post_thumbnail( $post_id ) ) {
		set_post_thumbnail( $post_id, (int) $data['photo_id'] );
		if ( ! $is_new ) {
			$filled[] = 'photo';
		}
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
	$parts = preg_split( '/\s+/', $name );
	$last  = count( $parts ) > 1 ? array_pop( $parts ) : '';
	$first = implode( ' ', $parts );
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
 * The single pass over the published events' speaker rows, memoised per
 * request. Returns two maps: 'events' (speaker_id => event IDs) and
 * 'appearances' (speaker_id => [event_id => the row for that event]).
 *
 * @param bool $reset Rebuild (tests create events mid-request).
 * @return array{events:array<int,int[]>,appearances:array<int,array<int,array>>}
 */
function law_speakers_confirmed_maps( $reset = false ) {
	static $maps = null;
	if ( null !== $maps && ! $reset ) {
		return $maps;
	}
	$maps = array( 'events' => array(), 'appearances' => array() );

	$events = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 500,
		)
	);

	foreach ( $events as $event_id ) {
		$event_id = (int) $event_id;
		foreach ( law_event_meta( $event_id, '_law_speakers' ) as $row ) {
			$speaker_id = (int) ( $row['speaker_id'] ?? 0 );
			if ( $speaker_id ) {
				$maps['events'][ $speaker_id ][]                  = $event_id;
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

	return $maps;
}

/**
 * A speaker's confirmed appearances, first submitted first: one row per event
 * with the organisation, job title, photo and biography they had at THAT
 * event.
 *
 * @param int $speaker_id Speaker post ID.
 * @return array<int,array{event_id:int,organisation:string,job_title:string,photo_id:int,bio:string}>
 */
function law_speaker_appearances( $speaker_id ) {
	$rows = law_speakers_confirmed_maps()['appearances'][ (int) $speaker_id ] ?? array();

	$appearances = array();
	foreach ( $rows as $event_id => $row ) {
		$appearances[] = array(
			'event_id'     => (int) $event_id,
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
 * @return array{organisation:string,job_title:string,photo_id:int,bio:string,event_id:int}
 */
function law_speaker_first_appearance( $speaker_id ) {
	$first  = array( 'organisation' => '', 'job_title' => '', 'photo_id' => 0, 'bio' => '', 'event_id' => 0 );
	$fields = array( 'organisation', 'job_title', 'photo_id', 'bio' );
	foreach ( law_speaker_appearances( $speaker_id ) as $appearance ) {
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
 * @return array{organisation:string,job_title:string,photo_id:int,bio:string}|null Null when the speaker is not on the event.
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
 * Session post IDs for an event, in menu order.
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
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
	usort(
		$ids,
		function ( $a, $b ) {
			$time_a = (string) law_event_meta( $a, '_law_start_time' );
			$time_b = (string) law_event_meta( $b, '_law_start_time' );
			return strcmp( $time_a ?: '99:99', $time_b ?: '99:99' ) ?: $a <=> $b;
		}
	);
	return $ids;
}

/**
 * Card-shaped speaker rows for an event listing (the shape
 * parts/calendar-body.php renders): id, name, organisation, job_title,
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
 * One card row for a speaker at one event. Organisation, job title, photo and
 * biography come from the appearance row; any field the row leaves empty falls
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

	$organisation = trim( (string) ( $row['organisation'] ?? '' ) );
	$job_title    = trim( (string) ( $row['job_title'] ?? '' ) );
	$photo_id     = (int) ( $row['photo_id'] ?? 0 );
	$bio          = trim( (string) ( $row['bio'] ?? '' ) );
	if ( '' === $organisation || '' === $job_title || ! $photo_id || '' === $bio ) {
		foreach ( $fallback_rows as $fallback ) {
			if ( (int) ( $fallback['speaker_id'] ?? 0 ) !== (int) $post->ID ) {
				continue;
			}
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
	$plain = wp_strip_all_tags( strip_shortcodes( (string) $bio ) );
	return wp_trim_words( $plain, (int) $words, '…' );
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
 * @param string $bio   Raw biography.
 * @param int    $words Word limit.
 * @return array{full:string,excerpt:string,trimmed:bool}
 */
function law_speaker_bio_summary( $bio, $words = 24 ) {
	$full  = trim( wp_strip_all_tags( strip_shortcodes( (string) $bio ) ) );
	$parts = '' === $full ? array() : (array) preg_split( '/[\n\r\t ]+/', $full, -1, PREG_SPLIT_NO_EMPTY );
	return array(
		'full'    => $full,
		'excerpt' => '' === $full ? '' : law_speaker_bio_excerpt( $bio, $words ),
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
