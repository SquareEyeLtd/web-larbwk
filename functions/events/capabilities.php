<?php
/**
 * Roles and capabilities. events_committee currently has only `read` plus the
 * (retiring) GravityView caps, so the module grants it the law_event
 * capability set so the wp-admin screens work for the whole committee
 * (EVENTS_4.1_REBUILD.md §3.4).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function law_events_capability_names() {
	return array(
		'edit_law_event',
		'read_law_event',
		'delete_law_event',
		'edit_law_events',
		'edit_others_law_events',
		'publish_law_events',
		'read_private_law_events',
		'delete_law_events',
		'delete_private_law_events',
		'delete_published_law_events',
		'delete_others_law_events',
		'edit_private_law_events',
		'edit_published_law_events',
	);
}

/** Grant the module caps once per version. */
function law_events_grant_capabilities() {
	$version = '1';
	if ( get_option( 'law_events_caps_version' ) === $version ) {
		return;
	}

	foreach ( array( 'administrator', 'editor', 'events_committee' ) as $role_name ) {
		$role = get_role( $role_name );
		if ( ! $role ) {
			continue;
		}
		foreach ( law_events_capability_names() as $cap ) {
			$role->add_cap( $cap );
		}
	}

	// Committee needs the media library for speaker photos.
	$committee = get_role( 'events_committee' );
	if ( $committee ) {
		$committee->add_cap( 'upload_files' );
	}

	update_option( 'law_events_caps_version', $version );
}
// On init (not admin_init): the committee acts from the front end, so the
// grant must not wait for a wp-admin visit.
add_action( 'init', 'law_events_grant_capabilities', 20 );

/**
 * Whether a user may manage (view privately, edit, comment on) an event:
 * the author, any co-owner, or committee/editor/admin.
 *
 * @param int $user_id  User ID.
 * @param int $event_id law_event post ID.
 */
function law_user_can_manage_event( $user_id, $event_id ) {
	$user_id  = (int) $user_id;
	$event_id = (int) $event_id;
	if ( $user_id < 1 || $event_id < 1 ) {
		return false;
	}

	$post = get_post( $event_id );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return false;
	}

	if ( (int) $post->post_author === $user_id ) {
		return true;
	}

	$co_owners = law_event_meta( $event_id, '_law_co_owner_ids' );
	if ( in_array( $user_id, array_map( 'intval', $co_owners ), true ) ) {
		return true;
	}

	return user_can( $user_id, 'edit_others_law_events' );
}

/** Whether the current user is committee-level (committee, editor, admin). */
function law_user_is_committee( $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	return $user_id && user_can( $user_id, 'edit_others_law_events' );
}

/**
 * Validate a posted assignee: only a user who actually holds committee-level
 * capability may be stored (a bad ID would otherwise receive committee
 * notification emails).
 *
 * @param mixed $raw Posted value.
 * @return int A committee-capable user ID, or 0.
 */
function law_events_sanitize_assignee( $raw ) {
	$user_id = absint( $raw );
	if ( ! $user_id ) {
		return 0;
	}
	return user_can( $user_id, 'edit_others_law_events' ) ? $user_id : 0;
}

/**
 * Committee members (for the assignee picker): users with the
 * events_committee role, plus admins already assigned.
 *
 * @return WP_User[]
 */
function law_events_committee_users() {
	$users = get_users(
		array(
			'role__in' => array( 'events_committee' ),
			'orderby'  => 'display_name',
			'number'   => 100,
		)
	);
	return is_array( $users ) ? $users : array();
}
