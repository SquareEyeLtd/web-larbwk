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
 * @param array $data name, email, organisation, job_title, website, bio,
 *                    photo_id (attachment ID).
 * @return int Post ID, 0 on failure.
 */
function law_speaker_upsert( array $data ) {
	$name = trim( (string) ( $data['name'] ?? '' ) );
	if ( '' === $name ) {
		return 0;
	}

	$post_id = law_speaker_find_existing( (string) ( $data['email'] ?? '' ), $name );

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
		}
	}

	$meta_map = array(
		'email'     => '_law_speaker_email',
		'organisation' => '_law_organisation',
		'job_title' => '_law_job_title',
		'website'   => '_law_website',
	);
	foreach ( $meta_map as $field => $key ) {
		$value = trim( (string) ( $data[ $field ] ?? '' ) );
		if ( '' !== $value && '' === (string) law_event_meta( $post_id, $key ) ) {
			law_event_update_meta( $post_id, $key, 'email' === $field ? mb_strtolower( $value ) : $value );
		}
	}

	if ( ! empty( $data['photo_id'] ) && ! has_post_thumbnail( $post_id ) ) {
		set_post_thumbnail( $post_id, (int) $data['photo_id'] );
	}

	return (int) $post_id;
}

/**
 * Public speaker profile array in the shape functions/speakers.php renders
 * (id, first/last, name, organisation, job_title, url, photo, bio, email,
 * event_ids).
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

	return array(
		'id'           => (int) $post->ID,
		'first_name'   => $first,
		'last_name'    => $last,
		'name'         => $name,
		'organisation' => (string) law_event_meta( $post->ID, '_law_organisation' ),
		'job_title'    => (string) law_event_meta( $post->ID, '_law_job_title' ),
		'url'          => (string) law_event_meta( $post->ID, '_law_website' ),
		'photo'        => (string) get_the_post_thumbnail_url( $post->ID, 'medium' ),
		'bio'          => trim( $post->post_content ),
		'email'        => (string) law_event_meta( $post->ID, '_law_speaker_email' ),
		'entry_ids'    => array( (int) $post->ID ),
		'event_ids'    => array_map( 'intval', $event_ids ),
	);
}

/**
 * Confirmed (publish) event IDs that reference a set of speakers, keyed by
 * speaker ID. One query pass over the published events' speaker rows.
 *
 * @return array<int,int[]> speaker_id => event post IDs.
 */
function law_speakers_confirmed_event_map() {
	static $map = null;
	if ( null !== $map ) {
		return $map;
	}
	$map = array();

	$events = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => 'publish',
			'fields'         => 'ids',
			'posts_per_page' => 500,
		)
	);

	foreach ( $events as $event_id ) {
		foreach ( law_event_meta( $event_id, '_law_speakers' ) as $row ) {
			$speaker_id = (int) ( $row['speaker_id'] ?? 0 );
			if ( $speaker_id ) {
				$map[ $speaker_id ][] = (int) $event_id;
			}
		}
		// Session-level speakers appear in the archive too: they speak at the event.
		foreach ( law_event_session_ids( $event_id ) as $session_id ) {
			foreach ( law_event_meta( $session_id, '_law_speakers' ) as $row ) {
				$speaker_id = (int) ( $row['speaker_id'] ?? 0 );
				if ( $speaker_id && ( empty( $map[ $speaker_id ] ) || ! in_array( (int) $event_id, $map[ $speaker_id ], true ) ) ) {
					$map[ $speaker_id ][] = (int) $event_id;
				}
			}
		}
	}

	return $map;
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
 * url (profile), photo.
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

/** One card row for a speaker post (organisation override honoured). */
function law_speaker_card( $speaker_id, array $row = array() ) {
	$post = get_post( (int) $speaker_id );
	if ( ! $post || LAW_SPEAKER_CPT !== $post->post_type ) {
		return null;
	}
	$organisation = trim( (string) ( $row['organisation_override'] ?? '' ) );
	if ( '' === $organisation ) {
		$organisation = (string) law_event_meta( $post->ID, '_law_organisation' );
	}
	return array(
		'id'           => (int) $post->ID,
		'name'         => $post->post_title,
		'organisation' => $organisation,
		'job_title'    => (string) law_event_meta( $post->ID, '_law_job_title' ),
		'url'          => function_exists( 'law_speaker_url' ) ? law_speaker_url( $post->ID ) : '',
		'photo'        => (string) get_the_post_thumbnail_url( $post->ID, 'thumbnail' ),
	);
}

/**
 * Sessions for an event in the listing shape (title, start, end, time_label,
 * description, speakers[]).
 */
function law_event_session_rows( $event_id ) {
	$sessions = array();
	foreach ( law_event_session_ids( $event_id ) as $session_id ) {
		$post  = get_post( $session_id );
		$start = (string) law_event_meta( $session_id, '_law_start_time' );
		$end   = (string) law_event_meta( $session_id, '_law_end_time' );

		$speakers = array();
		foreach ( law_event_meta( $session_id, '_law_speakers' ) as $row ) {
			$card = law_speaker_card( (int) ( $row['speaker_id'] ?? 0 ), $row );
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
