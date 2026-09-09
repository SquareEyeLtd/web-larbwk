<?php
/**
 * CPTs (law_event, law_speaker, law_session) and taxonomies.
 *
 * law_event uses its own capability set (law_event / law_events) so the
 * events_committee role can be granted admin access without touching posts
 * or pages. law_speaker and law_session share the same capability set.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_EVENT_CPT   = 'law_event';
const LAW_SPEAKER_CPT = 'law_speaker';
const LAW_SESSION_CPT = 'law_session';
const LAW_BOOKING_CPT = 'law_booking';

function law_events_capability_args() {
	return array(
		'capability_type' => array( 'law_event', 'law_events' ),
		'map_meta_cap'    => true,
		// No public query var: ?law_event= is the edit form's plain GET
		// parameter, and a registered query var would make WordPress treat
		// it as a post lookup and 404 the form page.
		'query_var'       => false,
	);
}

function law_events_register_post_types() {
	register_post_type(
		LAW_EVENT_CPT,
		array_merge(
			law_events_capability_args(),
			array(
				'labels'        => array(
					'name'          => 'Events',
					'singular_name' => 'Event',
					'add_new_item'  => 'Add new event',
					'edit_item'     => 'Edit event',
				),
				'public'              => true,
				'exclude_from_search' => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-calendar-alt',
				// Directly under Posts (5); the Emails menu takes 7.
				'menu_position'       => 6,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'events', 'with_front' => false ),
				'supports'            => array( 'title', 'editor', 'author', 'revisions' ),
				'taxonomies'          => array( 'law_event_type', 'law_sector', 'law_year' ),
			)
		)
	);

	register_post_type(
		LAW_SPEAKER_CPT,
		array_merge(
			law_events_capability_args(),
			array(
				'labels'        => array(
					'name'          => 'Speakers',
					'singular_name' => 'Speaker',
					'add_new_item'  => 'Add new speaker',
					'edit_item'     => 'Edit speaker',
				),
				'public'              => true,
				'exclude_from_search' => true,
				'publicly_queryable'  => true,
				'show_ui'             => true,
				'show_in_menu'        => 'edit.php?post_type=' . LAW_EVENT_CPT,
				'show_in_rest'        => false,
				'has_archive'         => false,
				'rewrite'             => array( 'slug' => 'speakers', 'with_front' => false ),
				'supports'            => array( 'title', 'editor', 'thumbnail' ),
				'taxonomies'          => array( 'law_year' ),
			)
		)
	);

	register_post_type(
		LAW_SESSION_CPT,
		array_merge(
			law_events_capability_args(),
			array(
				'labels'        => array(
					'name'          => 'Sessions',
					'singular_name' => 'Session',
					'add_new_item'  => 'Add new session',
					'edit_item'     => 'Edit session',
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . LAW_EVENT_CPT,
				'show_in_rest'       => false,
				'rewrite'            => false,
				'supports'           => array( 'title', 'editor' ),
			)
		)
	);

	register_post_type(
		LAW_BOOKING_CPT,
		array_merge(
			law_events_capability_args(),
			array(
				'labels'        => array(
					'name'          => 'Bookings',
					'singular_name' => 'Booking',
					'edit_item'     => 'Booking',
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . LAW_EVENT_CPT,
				'show_in_rest'       => false,
				'rewrite'            => false,
				'supports'           => array( 'title' ),
				// Bookings are only ever created by the engine
				// (law_booking_create()), so the capacity, duplicate and clash
				// guards and the seat recount can never be bypassed from
				// wp-admin. The admin screen is read-only inspection.
				'capabilities'       => array( 'create_posts' => 'do_not_allow' ),
			)
		)
	);

	law_events_register_taxonomies();
	law_events_maybe_flush_rewrites();
}
add_action( 'init', 'law_events_register_post_types', 5 );

function law_events_register_taxonomies() {
	$shared = array(
		'public'            => false,
		'show_ui'           => true,
		'show_admin_column' => true,
		'show_in_rest'      => false,
		'hierarchical'      => true, // Checkbox UI, no free-tagging.
	);

	register_taxonomy( 'law_event_type', LAW_EVENT_CPT, array_merge( $shared, array(
		'labels' => array( 'name' => 'Event types', 'singular_name' => 'Event type' ),
	) ) );
	register_taxonomy( 'law_sector', LAW_EVENT_CPT, array_merge( $shared, array(
		'labels' => array( 'name' => 'Sectors', 'singular_name' => 'Sector' ),
	) ) );
	register_taxonomy( 'law_year', array( LAW_EVENT_CPT, LAW_SPEAKER_CPT ), array_merge( $shared, array(
		'labels' => array( 'name' => 'Programme years', 'singular_name' => 'Programme year' ),
	) ) );
}

/** Flush rewrite rules once per registered structure version. */
function law_events_maybe_flush_rewrites() {
	$version = '2';
	if ( get_option( 'law_events_rewrite_version' ) !== $version ) {
		flush_rewrite_rules( false );
		update_option( 'law_events_rewrite_version', $version );
	}
}

/**
 * Seed the taxonomy terms that mirror form 2's choice lists. Idempotent;
 * runs once per version, and the migrator re-calls it before mapping.
 */
function law_events_seed_terms() {
	$seed = array(
		'law_event_type'     => array( 'Seminar / talk', 'Social event', 'Other' ),
		'law_sector'         => array(
			'Banking & Financial Services',
			'Energy, Infrastructure & Construction',
			'Insurance & Reinsurance',
			'International Arbitration',
			'Jurisdiction-specific',
			'Maritime / Shipping',
			'Public International Law',
			'Technology, Innovation, Digital Assets',
			'Trade & Commodities',
			'Other / sector-neutral',
		),
		'law_year'           => array( (string) law_events_setting( 'year', 2026 ) ),
	);

	foreach ( $seed as $taxonomy => $terms ) {
		foreach ( $terms as $term ) {
			if ( ! term_exists( $term, $taxonomy ) ) {
				wp_insert_term( $term, $taxonomy );
			}
		}
	}
}

/**
 * Map a form 2 field 60 (Sector) choice text to its law_sector term. Handles
 * the two free-text qualifier choices whose stored values differ slightly.
 */
function law_events_sector_term_name( $raw ) {
	$raw = html_entity_decode( trim( (string) $raw ), ENT_QUOTES );
	$map = array(
		'Other'          => 'Other / sector-neutral',
		'sector-neutral' => 'Other / sector-neutral',
	);
	return $map[ $raw ] ?? $raw;
}
