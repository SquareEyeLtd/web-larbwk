<?php
/**
 * Event post statuses. Core `publish` = Confirmed (publicly listable);
 * the moderation states are custom statuses (EVENTS_4.1_REBUILD.md §3.1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * status => [label, public?]. Order matters for the committee filter UI.
 */
function law_event_statuses() {
	return array(
		'law-draft'     => array( 'label' => 'Draft',     'public' => false ),
		'law-proposed'  => array( 'label' => 'Proposed',  'public' => false ),
		'law-sent-back' => array( 'label' => 'Sent back', 'public' => false ),
		'law-approved'  => array( 'label' => 'Approved',  'public' => false ),
		'publish'       => array( 'label' => 'Confirmed', 'public' => true ),
		'law-rejected'  => array( 'label' => 'Rejected',  'public' => false ),
	);
}

function law_events_register_statuses() {
	foreach ( law_event_statuses() as $status => $config ) {
		if ( 'publish' === $status ) {
			continue;
		}
		register_post_status(
			$status,
			array(
				'label'                     => $config['label'],
				'public'                    => false,
				'internal'                  => false,
				'protected'                 => true,
				'exclude_from_search'       => true,
				'show_in_admin_all_list'    => true,
				'show_in_admin_status_list' => true,
				/* translators: %s: count */
				'label_count'               => _n_noop(
					$config['label'] . ' <span class="count">(%s)</span>',
					$config['label'] . ' <span class="count">(%s)</span>'
				),
			)
		);
	}
}
add_action( 'init', 'law_events_register_statuses', 6 );

/**
 * The committee-facing label for a status ("Confirmed" for publish),
 * matching the old field 95 (Event status) vocabulary.
 *
 * @param string|WP_Post $status Post status or event post.
 */
function law_event_status_label( $status ) {
	if ( $status instanceof WP_Post ) {
		$status = $status->post_status;
	}
	$statuses = law_event_statuses();
	return $statuses[ $status ]['label'] ?? ucfirst( (string) $status );
}

/** Old field 95 (Event status) value → new post status. */
function law_event_status_from_legacy( $legacy ) {
	$map = array(
		'Proposed'  => 'law-proposed',
		'Sent back' => 'law-sent-back',
		'Approved'  => 'law-approved',
		'Confirmed' => 'publish',
		'Rejected'  => 'law-rejected',
	);
	return $map[ trim( (string) $legacy ) ] ?? 'law-proposed';
}

/** All law_event statuses, for admin/committee queries. */
function law_event_all_status_keys() {
	return array_keys( law_event_statuses() );
}

/**
 * Keep custom-status events visible and editable on the admin list and edit
 * screens (WP hides unknown statuses from some queries by default).
 */
add_filter(
	'display_post_states',
	function ( $states, $post ) {
		if ( $post instanceof WP_Post && LAW_EVENT_CPT === $post->post_type ) {
			$known = law_event_statuses();
			if ( isset( $known[ $post->post_status ] ) && 'publish' !== $post->post_status ) {
				$states[ 'law_status' ] = $known[ $post->post_status ]['label'];
			}
		}
		return $states;
	},
	10,
	2
);
