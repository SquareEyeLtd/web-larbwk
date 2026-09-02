<?php
/**
 * Speakers archive (templates/speakers.php).
 *
 * Speakers are child entries on form 8, created by the Speakers Nested Form
 * field on the event form (form 2). The archive lists each person once,
 * deduplicated by first + last name, from Confirmed events only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_SPEAKER_FORM_ID = 8;

// Form 8 field IDs.
const LAW_SPEAKER_FIELD_FIRST = '1.3';
const LAW_SPEAKER_FIELD_LAST  = '1.6';
const LAW_SPEAKER_FIELD_ORG   = '3';
const LAW_SPEAKER_FIELD_JOB   = '4';
const LAW_SPEAKER_FIELD_URL   = '5';
const LAW_SPEAKER_FIELD_PHOTO = '6';
const LAW_SPEAKER_FIELD_BIO   = '7';
const LAW_SPEAKER_FIELD_EMAIL = '8';

function law_speakers_is_template() {
	return is_page_template( 'templates/speakers.php' );
}

/**
 * Parent event entry IDs whose speakers may be shown: Confirmed events only.
 *
 * @return array<int, true> Keyed by entry ID.
 */
function law_speakers_allowed_parents() {
	$entries = GFAPI::get_entries(
		LAW_CALENDAR_FORM_ID,
		array(
			'status'        => 'active',
			'field_filters' => array(
				array(
					'key'      => '95',
					'operator' => 'is',
					'value'    => 'Confirmed',
				),
			),
		),
		null,
		array( 'offset' => 0, 'page_size' => 500 )
	);
	if ( is_wp_error( $entries ) || ! is_array( $entries ) ) {
		return array();
	}

	$parents = array();
	foreach ( $entries as $entry ) {
		$parents[ (int) rgar( $entry, 'id' ) ] = true;
	}
	return $parents;
}

/**
 * Unique speakers, sorted by last then first name.
 *
 * @return array<int, array{id:int,first_name:string,last_name:string,name:string,organisation:string,job_title:string,url:string,photo:string,bio:string,email:string}>
 */
function law_speakers() {
	static $speakers = null;
	if ( null !== $speakers ) {
		return $speakers;
	}
	$speakers = array();

	if ( ! class_exists( 'GFAPI' ) ) {
		return $speakers;
	}

	$parents = law_speakers_allowed_parents();
	if ( ! $parents ) {
		return $speakers;
	}

	$entries = GFAPI::get_entries(
		LAW_SPEAKER_FORM_ID,
		array( 'status' => 'active' ),
		array( 'key' => 'id', 'direction' => 'ASC' ),
		array( 'offset' => 0, 'page_size' => 2000 )
	);
	if ( is_wp_error( $entries ) || ! is_array( $entries ) ) {
		return $speakers;
	}

	$speakers = law_speakers_dedupe( $entries, $parents );
	return $speakers;
}

/**
 * Map, deduplicate and sort raw form 8 entries.
 *
 * Two entries are the same person when they share an email address, or,
 * failing that, a first + last name (both case-insensitive). The oldest
 * child entry becomes the card; any field it is missing (organisation,
 * job title, URL, photo, biography, email) is filled in from the next
 * duplicate that has it. Every matched child entry ID and parent event ID
 * is kept in entry_ids / event_ids, for the single profile page.
 *
 * @param array<int, array> $entries Form 8 entries, oldest first.
 * @param array<int, true>  $parents Allowed parent entry IDs.
 * @return array<int, array>
 */
function law_speakers_dedupe( $entries, $parents ) {
	$speakers = array();
	$by_email = array();
	$by_name  = array();

	foreach ( $entries as $entry ) {
		$parent_id = (int) rgar( $entry, 'gpnf_entry_parent' );
		if ( ! isset( $parents[ $parent_id ] ) ) {
			continue;
		}

		$first = trim( (string) rgar( $entry, LAW_SPEAKER_FIELD_FIRST ) );
		$last  = trim( (string) rgar( $entry, LAW_SPEAKER_FIELD_LAST ) );
		$name  = trim( $first . ' ' . $last );
		if ( '' === $name ) {
			continue;
		}

		$speaker = array(
			'id'           => (int) rgar( $entry, 'id' ),
			'first_name'   => $first,
			'last_name'    => $last,
			'name'         => $name,
			'organisation' => trim( (string) rgar( $entry, LAW_SPEAKER_FIELD_ORG ) ),
			'job_title'    => trim( (string) rgar( $entry, LAW_SPEAKER_FIELD_JOB ) ),
			'url'          => esc_url_raw( (string) rgar( $entry, LAW_SPEAKER_FIELD_URL ) ),
			'photo'        => law_speaker_photo( rgar( $entry, LAW_SPEAKER_FIELD_PHOTO ) ),
			'bio'          => trim( (string) rgar( $entry, LAW_SPEAKER_FIELD_BIO ) ),
			'email'        => mb_strtolower( trim( (string) rgar( $entry, LAW_SPEAKER_FIELD_EMAIL ) ) ),
			'entry_ids'    => array( (int) rgar( $entry, 'id' ) ),
			'event_ids'    => array( $parent_id ),
		);

		$email_key = $speaker['email'];
		$name_key  = mb_strtolower( preg_replace( '/\s+/u', ' ', $first . '|' . $last ) );

		// Email identifies the person first; the name is the fallback.
		$index = null;
		if ( '' !== $email_key && isset( $by_email[ $email_key ] ) ) {
			$index = $by_email[ $email_key ];
		} elseif ( isset( $by_name[ $name_key ] ) ) {
			$index = $by_name[ $name_key ];
		}

		if ( null !== $index ) {
			// Duplicate person: fill in whatever the kept entry is missing.
			$kept = &$speakers[ $index ];
			foreach ( array( 'organisation', 'job_title', 'url', 'photo', 'bio', 'email' ) as $field ) {
				if ( '' === $kept[ $field ] && '' !== $speaker[ $field ] ) {
					$kept[ $field ] = $speaker[ $field ];
				}
			}
			$kept['entry_ids'][] = $speaker['entry_ids'][0];
			if ( ! in_array( $parent_id, $kept['event_ids'], true ) ) {
				$kept['event_ids'][] = $parent_id;
			}
			unset( $kept );
		} else {
			$index      = count( $speakers );
			$speakers[] = $speaker;
		}

		// Register this entry's keys too, so a later duplicate matches the
		// person through either its email or its name spelling.
		if ( '' !== $email_key && ! isset( $by_email[ $email_key ] ) ) {
			$by_email[ $email_key ] = $index;
		}
		if ( ! isset( $by_name[ $name_key ] ) ) {
			$by_name[ $name_key ] = $index;
		}
	}

	usort(
		$speakers,
		function ( $a, $b ) {
			return strcasecmp( $a['last_name'], $b['last_name'] )
				?: strcasecmp( $a['first_name'], $b['first_name'] );
		}
	);

	return $speakers;
}

/**
 * First photo URL from the multi-file upload field (a JSON array of URLs).
 */
function law_speaker_photo( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}
	if ( str_starts_with( $raw, '[' ) ) {
		$files = json_decode( $raw, true );
		$raw   = is_array( $files ) && $files ? (string) reset( $files ) : '';
	}
	return esc_url_raw( $raw );
}

/**
 * Placeholder initials for speakers without a photo.
 */
function law_speaker_initials( $speaker ) {
	$initials = '';
	foreach ( array( $speaker['first_name'], $speaker['last_name'] ) as $part ) {
		if ( '' !== $part ) {
			$initials .= mb_strtoupper( mb_substr( $part, 0, 1 ) );
		}
	}
	return $initials;
}

/**
 * Single speaker URL, /speakers/<form 8 entry ID>/.
 *
 * Always resolves from the Speakers page, not the queried object — event
 * listings live on /programme/ and must not produce /programme/<id>/.
 */
function law_speaker_url( $entry_id ) {
	static $base = null;
	if ( null === $base ) {
		$pages = get_pages(
			array(
				'meta_key'   => '_wp_page_template',
				'meta_value' => 'templates/speakers.php',
				'number'     => 1,
			)
		);
		$base = $pages ? get_permalink( $pages[0] ) : home_url( '/speakers/' );
	}
	return trailingslashit( $base ) . (int) $entry_id . '/';
}

/*
 * ---------------------------------------------------------------------------
 * Single speaker profile, /speakers/<form 8 entry ID>/.
 *
 * The rewrite routes onto the Speakers page; law_speakers_single_template()
 * swaps in templates/speaker.php. Any child entry ID of a merged person
 * resolves to the same profile.
 * ---------------------------------------------------------------------------
 */

const LAW_SPEAKER_QUERY_VAR = 'law_speaker';

add_filter(
	'query_vars',
	function ( $vars ) {
		$vars[] = LAW_SPEAKER_QUERY_VAR;
		return $vars;
	}
);

add_action(
	'init',
	function () {
		add_rewrite_rule(
			'^speakers/([0-9]+)/?$',
			'index.php?pagename=speakers&' . LAW_SPEAKER_QUERY_VAR . '=$matches[1]',
			'top'
		);
		// One-off flush when the rule set changes. Bump to reflush.
		if ( get_option( 'law_speakers_rewrite_version' ) !== '1' ) {
			flush_rewrite_rules( false );
			update_option( 'law_speakers_rewrite_version', '1' );
		}
	}
);

function law_speaker_requested_id() {
	return absint( get_query_var( LAW_SPEAKER_QUERY_VAR ) );
}

function law_speakers_is_single() {
	return law_speakers_is_template() && law_speaker_requested_id() > 0;
}

/**
 * The merged profile a child entry ID belongs to, or null. Duplicate entry
 * IDs resolve to the same person.
 *
 * @return array|null See law_speakers() for the shape.
 */
function law_speaker_profile( $entry_id ) {
	$entry_id = (int) $entry_id;
	if ( $entry_id < 1 ) {
		return null;
	}
	foreach ( law_speakers() as $speaker ) {
		if ( in_array( $entry_id, $speaker['entry_ids'], true ) ) {
			return $speaker;
		}
	}
	return null;
}

function law_speaker_current_profile() {
	return law_speakers_is_single() ? law_speaker_profile( law_speaker_requested_id() ) : null;
}

add_filter(
	'template_include',
	function ( $template ) {
		if ( ! law_speakers_is_single() ) {
			return $template;
		}
		if ( ! law_speaker_current_profile() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
			return get_404_template();
		}
		return get_theme_file_path( 'templates/speaker.php' );
	}
);

/**
 * The speaker's events, mapped like the programme calendar and in
 * programme order.
 *
 * @param array $speaker A law_speakers() profile.
 * @return array<int, array> Mapped calendar events.
 */
function law_speaker_events( $speaker ) {
	$events = array();
	foreach ( $speaker['event_ids'] as $event_id ) {
		$event = law_calendar_event_by_id( $event_id );
		if ( $event ) {
			$events[] = $event;
		}
	}
	usort(
		$events,
		function ( $a, $b ) {
			return strcmp( $a['sort'], $b['sort'] );
		}
	);
	return $events;
}

/**
 * Event detail URL on the programme page. law_calendar_url() builds from the
 * queried page, which here is the Speakers page, so resolve the programme
 * page instead.
 */
function law_speaker_event_link( $event_id ) {
	static $base = null;
	if ( null === $base ) {
		$pages = get_pages(
			array(
				'meta_key'   => '_wp_page_template',
				'meta_value' => 'templates/calendar.php',
				'number'     => 1,
			)
		);
		$base  = $pages ? get_permalink( $pages[0] ) : home_url( '/programme/' );
	}
	return add_query_arg( 'event', (int) $event_id, $base );
}

/*
 * SEO: the routed page would otherwise emit the generic Speakers page title
 * and description on every profile.
 */

function law_speaker_seo_title() {
	$speaker = law_speaker_current_profile();
	if ( ! $speaker ) {
		return '';
	}
	return $speaker['name'] . ' – Speakers – ' . get_bloginfo( 'name' );
}

function law_speaker_seo_description() {
	$speaker = law_speaker_current_profile();
	if ( ! $speaker ) {
		return '';
	}
	if ( '' !== $speaker['bio'] ) {
		return wp_trim_words( wp_strip_all_tags( $speaker['bio'] ), 24, '…' );
	}
	$who  = $speaker['name'];
	$role = array_filter( array( $speaker['job_title'], $speaker['organisation'] ) );
	if ( $role ) {
		$who .= ', ' . implode( ' at ', $role ) . ',';
	}
	return $who . ' is speaking at ' . get_bloginfo( 'name' ) . ' ' . LAW_CALENDAR_YEAR . '.';
}

add_filter(
	'document_title_parts',
	function ( $parts ) {
		$speaker = law_speaker_current_profile();
		if ( $speaker ) {
			$parts['title'] = $speaker['name'] . ' – Speakers';
		}
		return $parts;
	}
);

add_filter(
	'pre_get_document_title',
	function ( $title ) {
		$seo = law_speaker_seo_title();
		return $seo ? $seo : $title;
	},
	PHP_INT_MAX
);

add_filter(
	'seopress_titles_title',
	function ( $title ) {
		$seo = law_speaker_seo_title();
		return $seo ? $seo : $title;
	}
);

add_filter(
	'seopress_titles_desc',
	function ( $desc ) {
		$seo = law_speaker_seo_description();
		return $seo ? $seo : $desc;
	}
);

add_filter(
	'seopress_titles_canonical',
	function ( $canonical ) {
		$speaker = law_speaker_current_profile();
		if ( ! $speaker ) {
			return $canonical;
		}
		// Duplicate entry IDs canonicalise to the kept entry's URL.
		return '<link rel="canonical" href="' . esc_url( law_speaker_url( $speaker['id'] ) ) . '" />';
	}
);
