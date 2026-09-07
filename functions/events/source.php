<?php
/**
 * The CPT data source behind law_events_source() (EVENTS_4.1_REBUILD.md §3.9).
 * Produces exactly the mapped array shapes the calendar, speakers and account
 * templates already consume, so flipping the source changes nothing visible.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mapped calendar events from law_event posts, same shape as
 * law_calendar_map_entry() output.
 *
 * @param array $allowed Allowed status LABELS ('Confirmed'…); empty = all.
 * @return array[]
 */
function law_events_cpt_mapped_events( $allowed ) {
	$all      = is_array( $allowed ) && empty( $allowed );
	$statuses = $all ? law_event_all_status_keys() : array( 'publish' );

	$posts = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => $statuses,
			'posts_per_page' => 500,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	$mapped = array();
	foreach ( $posts as $post ) {
		$event = law_events_map_post( $post, $allowed );
		if ( $event ) {
			$mapped[] = $event;
		}
	}
	return $mapped;
}

/**
 * Map one law_event post to the calendar event array shape.
 *
 * @param WP_Post|int $post    Event post.
 * @param array|null  $allowed Allowed status labels; null = public; [] = all.
 * @return array|null
 */
function law_events_map_post( $post, $allowed = null ) {
	$post = get_post( $post );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return null;
	}

	if ( null === $allowed ) {
		$allowed = law_calendar_public_statuses();
	}
	$status        = law_event_status_label( $post );
	$with_drafts   = is_array( $allowed ) && in_array( '*', $allowed, true ); // Host dashboard.
	$all           = $with_drafts || ( is_array( $allowed ) && empty( $allowed ) );
	if ( ! $all && ! in_array( $status, $allowed, true ) ) {
		return null;
	}
	// Drafts appear only where explicitly asked for (the owner's dashboard).
	if ( 'law-draft' === $post->post_status && ! $with_drafts ) {
		return null;
	}

	$title = trim( $post->post_title );
	if ( '' === $title ) {
		return null;
	}

	$start = (string) law_event_meta( $post->ID, '_law_start' );
	$end   = (string) law_event_meta( $post->ID, '_law_end' );
	$slot  = array(
		'date'       => $start ? substr( $start, 0, 10 ) : '',
		'start'      => $start ? substr( $start, 11, 5 ) : '',
		'end'        => $end ? substr( $end, 11, 5 ) : '',
		'time_label' => '',
	);
	$slot['time_label'] = $slot['start']
		? ( $slot['end'] ? $slot['start'] . '-' . $slot['end'] : $slot['start'] . ' onwards' )
		: 'Slot not confirmed';

	if ( '' === $slot['date'] && ! $all ) {
		return null; // Public calendar hides unscheduled events, as before.
	}

	$type    = law_events_post_term_name( $post->ID, 'law_event_type' );
	$tickets = (int) law_event_meta( $post->ID, '_law_tickets_available' );

	// Raw content in the list shape: the excerpt strips tags and the keyword
	// filter runs wp_strip_all_tags, so neither needs the rendered pipeline.
	// the_content (wpautop, shortcodes, embeds) runs once, in the hydrate step
	// for the single view, instead of on every event on the programme.
	$description = $post->post_content;

	return array(
		'id'           => (int) $post->ID,
		'title'        => $title,
		'status'       => $status,
		'host'         => (string) law_event_meta( $post->ID, '_law_host_organisations' ),
		'venue'        => (string) law_event_meta( $post->ID, '_law_venue' ),
		'tickets'      => $tickets > 0 ? $tickets : 0,
		// The booking control reads these two, never 'tickets' (whose 0 means
		// "unset"): remaining is null when the event is not open for booking.
		'tickets_sold'      => law_event_attendee_total( $post->ID ),
		'tickets_remaining' => law_event_tickets_remaining( $post->ID ),
		'type'         => $type,
		'sectors'      => law_events_post_term_names( $post->ID, 'law_sector' ),
		'speakers'     => array(), // Hydrated on the single listing only.
		'sessions'     => array(),
		'excerpt'      => law_calendar_excerpt( $description ),
		'description'  => $description,
		'url'          => law_events_event_url( $post ),
		'is_evening'   => ( 'Social event' === $type || ( $slot['start'] && $slot['start'] >= '18:00' ) ),
		'date'         => $slot['date'],
		'start'        => $slot['start'],
		'end'          => $slot['end'],
		'time_label'   => $slot['time_label'],
		'unscheduled'  => '' === $slot['date'],
		'is_sponsored' => law_events_post_is_sponsored( $post ),
		'sort'         => ( $slot['date'] ?: '9999-99-99' ) . ' ' . ( $slot['start'] ?: '99:99' ) . ' ' . strtolower( $title ),
	);
}

/** The public URL for an event: real permalink when Confirmed, else the committee ?event= view. */
function law_events_event_url( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return '';
	}
	if ( 'publish' === $post->post_status ) {
		return get_permalink( $post );
	}
	return function_exists( 'law_calendar_url' )
		? law_calendar_url( array( 'event' => $post->ID ), false )
		: add_query_arg( 'event', $post->ID, home_url( '/committee/programme/' ) );
}

function law_events_post_term_name( $post_id, $taxonomy ) {
	$names = law_events_post_term_names( $post_id, $taxonomy );
	return $names ? $names[0] : '';
}

function law_events_post_term_names( $post_id, $taxonomy ) {
	$terms = get_the_terms( $post_id, $taxonomy );
	if ( ! is_array( $terms ) ) {
		return array();
	}
	return array_values( wp_list_pluck( $terms, 'name' ) );
}

/**
 * "Sector: note; Sector" summary. The "please specify" answers render inline
 * after their sector, mirroring the form's conditional fields.
 */
function law_event_sector_summary( $event_id ) {
	$notes = array(
		'Jurisdiction-specific'  => (string) law_event_meta( $event_id, '_law_sector_jurisdiction' ),
		'Other / sector-neutral' => (string) law_event_meta( $event_id, '_law_sector_other' ),
	);
	$sectors = array_map(
		function ( $sector ) use ( $notes ) {
			$note = $notes[ $sector ] ?? '';
			return '' !== $note ? $sector . ': ' . $note : $sector;
		},
		law_events_post_term_names( $event_id, 'law_sector' )
	);
	return implode( '; ', $sectors );
}

/**
 * Sponsored tag (parity with law_calendar_is_sponsored_event()): sponsor tier
 * or zero fee, a sponsor-category organisation, or a submitter with more than
 * one Approved/Confirmed event this year.
 */
function law_events_post_is_sponsored( $post ) {
	$post = get_post( $post );
	if ( ! $post ) {
		return false;
	}

	$tier = (string) law_event_meta( $post->ID, '_law_fee_tier' );
	if ( 'sponsor' === $tier || ( '' !== $tier && law_event_tier_amount( $tier ) <= 0 ) ) {
		return true;
	}

	$org_ids     = array_map( 'intval', law_event_meta( $post->ID, '_law_organisation_ids' ) );
	$sponsor_ids = function_exists( 'law_calendar_sponsor_organisation_ids' )
		? array_map( 'intval', law_calendar_sponsor_organisation_ids() )
		: array();
	if ( $org_ids && $sponsor_ids && array_intersect( $org_ids, $sponsor_ids ) ) {
		return true;
	}

	$counts = law_events_cpt_author_counts();
	return ( $counts[ (int) $post->post_author ] ?? 0 ) > 1;
}

/** Approved/Confirmed event counts per author (the multi-event sponsor rule). */
function law_events_cpt_author_counts() {
	static $counts = null;
	if ( null !== $counts ) {
		return $counts;
	}
	$counts = array();
	$posts  = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => array( 'law-approved', 'publish' ),
			'fields'         => 'ids',
			'posts_per_page' => 500,
			// Scoped to the programme year, like the GF version's
			// law_calendar_approved_event_counts_by_user().
			'tax_query'      => array(
				array(
					'taxonomy' => 'law_year',
					'field'    => 'name',
					'terms'    => (string) law_events_setting( 'year', 2026 ),
				),
			),
		)
	);
	foreach ( $posts as $post_id ) {
		$author = (int) get_post_field( 'post_author', $post_id );
		if ( $author ) {
			$counts[ $author ] = ( $counts[ $author ] ?? 0 ) + 1;
		}
	}
	return $counts;
}

/**
 * Hydrate speakers and sessions onto a mapped CPT event (the single listing).
 */
function law_events_cpt_hydrate( array $event ) {
	$event['speakers'] = law_event_speaker_cards( $event['id'] );
	$event['sessions'] = law_event_session_rows( $event['id'] );
	// The single view is the only place that renders the full description, so
	// the_content runs here rather than for every event in the list map.
	$event['description'] = apply_filters( 'the_content', $event['description'] );
	return $event;
}

/**
 * Filter dropdown choices in CPT mode: taxonomy term names, replacing the
 * form 2 field choice reads.
 *
 * @param int|string $field_id Legacy field ID (60 Sector, 63 Event type).
 * @return string[]
 */
function law_events_cpt_field_choices( $field_id ) {
	$taxonomy = (string) $field_id === '63' ? 'law_event_type' : 'law_sector';
	$terms    = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) ) {
		return array();
	}
	return array_values( wp_list_pluck( $terms, 'name' ) );
}

/* Speakers archive and profiles in CPT mode _________________________________ */

/**
 * The speakers archive: one profile per law_speaker referenced by at least
 * one Confirmed event. Dedupe already happened at save; visibility stays
 * derived from events (EVENTS_4.1_REBUILD.md §3.2).
 *
 * @return array[] law_speakers()-shaped profiles, sorted by surname.
 */
function law_events_cpt_speakers() {
	$map      = law_speakers_confirmed_event_map();
	$profiles = array();
	foreach ( $map as $speaker_id => $event_ids ) {
		$profile = law_speaker_post_profile( $speaker_id, $event_ids );
		if ( $profile ) {
			$profiles[] = $profile;
		}
	}

	usort(
		$profiles,
		function ( $a, $b ) {
			$last = strcasecmp( $a['last_name'] ?: $a['name'], $b['last_name'] ?: $b['name'] );
			return $last ?: strcasecmp( $a['first_name'], $b['first_name'] );
		}
	);

	return $profiles;
}

/**
 * One public speaker profile by post ID (only while a Confirmed event
 * references them). Legacy child entry IDs resolve through the migration map.
 *
 * @param int $id law_speaker post ID or legacy form 8 entry ID.
 */
function law_events_cpt_speaker_profile( $id ) {
	$id = law_events_resolve_speaker_id( $id );
	if ( ! $id ) {
		return null;
	}
	$map    = law_speakers_confirmed_event_map();
	$events = $map[ $id ] ?? array();
	if ( ! $events ) {
		return null;
	}
	return law_speaker_post_profile( $id, $events );
}

/**
 * Fallback resolver: find a migrated post by the legacy GF entry ID it stores
 * durably in meta. The entry-map option is the fast path, but it is a single
 * serialized option; every migrated post also carries `_law_gf_entry_id` (and
 * speakers the merged `_law_gf_entry_ids`), so if that option is ever lost or
 * stale, legacy URLs and Stripe webhooks keyed by entry ID still resolve
 * instead of silently failing on the money path.
 *
 * @param int    $entry_id  Legacy GF entry ID.
 * @param string $post_type Target CPT constant.
 * @return int Post ID or 0.
 */
function law_events_post_by_legacy_entry( $entry_id, $post_type ) {
	$entry_id = absint( $entry_id );
	if ( ! $entry_id ) {
		return 0;
	}
	// Primary singular key (both CPTs), then, for speakers, the serialized list
	// of merged child entry IDs (matched on the serialized-int needle `i:N;`,
	// whose trailing semicolon prevents a 12 → 123 false match).
	$queries = array(
		array( 'key' => '_law_gf_entry_id', 'value' => (string) $entry_id ),
	);
	if ( LAW_SPEAKER_CPT === $post_type ) {
		$queries[] = array( 'key' => '_law_gf_entry_ids', 'value' => 'i:' . $entry_id . ';', 'compare' => 'LIKE' );
	}
	foreach ( $queries as $meta ) {
		$found = get_posts( array(
			'post_type'   => $post_type,
			'post_status' => 'any',
			'numberposts' => 1,
			'fields'      => 'ids',
			'no_found_rows' => true,
			'meta_query'  => array( $meta ),
		) );
		if ( $found ) {
			return (int) $found[0];
		}
	}
	return 0;
}

/** Resolve a speaker reference: post ID as-is, else legacy entry ID via the map (meta fallback). */
function law_events_resolve_speaker_id( $id ) {
	$id = absint( $id );
	if ( ! $id ) {
		return 0;
	}
	if ( get_post_type( $id ) === LAW_SPEAKER_CPT ) {
		return $id;
	}
	$map = get_option( 'law_events_entry_map', array() );
	$post_id = absint( $map['speakers'][ $id ] ?? 0 );
	if ( ! $post_id || get_post_type( $post_id ) !== LAW_SPEAKER_CPT ) {
		$post_id = law_events_post_by_legacy_entry( $id, LAW_SPEAKER_CPT );
	}
	return $post_id && get_post_type( $post_id ) === LAW_SPEAKER_CPT ? $post_id : 0;
}

/** Resolve an event reference: post ID as-is, else legacy form 2 entry ID via the map (meta fallback). */
function law_events_resolve_event_post_id( $id ) {
	$id = absint( $id );
	if ( ! $id ) {
		return 0;
	}
	if ( get_post_type( $id ) === LAW_EVENT_CPT ) {
		return $id;
	}
	$map = get_option( 'law_events_entry_map', array() );
	$post_id = absint( $map['events'][ $id ] ?? 0 );
	if ( ! $post_id || get_post_type( $post_id ) !== LAW_EVENT_CPT ) {
		$post_id = law_events_post_by_legacy_entry( $id, LAW_EVENT_CPT );
	}
	return $post_id && get_post_type( $post_id ) === LAW_EVENT_CPT ? $post_id : 0;
}

/* Single event permalinks and legacy URL redirects ___________________________ */

/**
 * The single law_event page renders the same programme body (hero, facts,
 * sessions, speakers) as the ?event= view did, via templates/calendar.php.
 */
add_filter( 'template_include', function ( $template ) {
	if ( 'cpt' === law_events_source() && is_singular( LAW_EVENT_CPT ) ) {
		$single = get_theme_file_path( 'templates/event-single.php' );
		if ( file_exists( $single ) ) {
			return $single;
		}
	}
	return $template;
}, 20 );

/**
 * Legacy URL continuity (EVENTS_4.1_REBUILD.md §5.6): after cutover,
 * ?event=<GF entry ID> 301s to the event permalink, and old numeric
 * /speakers/<entry ID>/ URLs 301 to the speaker post URL.
 */
add_action( 'template_redirect', function () {
	if ( 'cpt' !== law_events_source() ) {
		return;
	}

	// ?event=<legacy entry id> → permalink, ONLY on the public programme page
	// (the one place legacy links ever pointed). The committee programme and
	// the committee dashboard both use ?event=<post ID> internally and must
	// never be intercepted: entry IDs and post IDs overlap numerically, and
	// hijacking the dashboard's detail links misdirected Confirmed events.
	if ( isset( $_GET['event'] ) && is_page_template( 'templates/calendar.php' ) ) {
		$requested = absint( $_GET['event'] );
		$map       = get_option( LAW_MIGRATION_MAP_OPTION, array() );
		$post_id   = absint( $map['events'][ $requested ] ?? 0 );
		if ( ! $post_id || get_post_type( $post_id ) !== LAW_EVENT_CPT ) {
			$post_id = law_events_resolve_event_post_id( $requested );
		}
		if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
			wp_safe_redirect( get_permalink( $post_id ), 301 );
			exit;
		}
	}

	// /speakers/<legacy numeric id>/ → speaker permalink.
	$speaker_query = get_query_var( 'law_speaker' );
	if ( $speaker_query && is_numeric( $speaker_query ) ) {
		$map     = get_option( 'law_events_entry_map', array() );
		$post_id = absint( $map['speakers'][ absint( $speaker_query ) ] ?? 0 );
		if ( $post_id && get_post_type( $post_id ) === LAW_SPEAKER_CPT ) {
			wp_safe_redirect( get_permalink( $post_id ), 301 );
			exit;
		}
	}
}, 5 );

/**
 * Gate single event/speaker pages by the same Members restriction as the
 * programme page, pre-launch: the pages are only as public as /programme/.
 */
add_action( 'template_redirect', function () {
	if ( 'cpt' !== law_events_source() || ! function_exists( 'members_can_current_user_view_post' ) ) {
		return;
	}
	if ( ! is_singular( array( LAW_EVENT_CPT, LAW_SPEAKER_CPT ) ) ) {
		return;
	}

	// An event's author and co-owners (and committee, via
	// law_user_can_manage_event()) can always view their own listing, so a
	// host's "View listing" button works while the programme is still gated.
	if ( is_singular( LAW_EVENT_CPT )
		&& law_user_can_manage_event( get_current_user_id(), get_queried_object_id() ) ) {
		return;
	}

	$gate_page = is_singular( LAW_SPEAKER_CPT )
		? ( function_exists( 'law_speakers_page_id' ) ? law_speakers_page_id() : 0 )
		: law_events_programme_page_id();

	if ( $gate_page && ! members_can_current_user_view_post( $gate_page ) ) {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( get_permalink() ), 302 );
			exit;
		}
		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
	}
}, 4 );

/** The public programme page (templates/calendar.php). */
function law_events_programme_page_id() {
	static $id = null;
	if ( null === $id ) {
		$pages = get_pages(
			array(
				'meta_key'   => '_wp_page_template',
				'meta_value' => 'templates/calendar.php',
				'number'     => 1,
			)
		);
		$id = $pages ? (int) $pages[0]->ID : 0;
	}
	return $id;
}
