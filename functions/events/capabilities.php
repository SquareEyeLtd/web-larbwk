<?php
/**
 * Roles and capabilities. events_committee currently has only `read` plus the
 * (retiring) GravityView caps, so the module grants it the law_event
 * capability set so the wp-admin screens work for the whole committee
 * (EVENTS_4.1_REBUILD.md §3.4).
 *
 * Since 22 September 2026 this file also owns `event_submitter`, the role that
 * decides who may START a new event. See law_events_submitter_role() below for
 * why that is a role of its own rather than a property of an account.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The role that may submit a NEW event, and the capability behind it.
 *
 * The three self-service roles (event_host, sponsor, attendee) were retired on
 * 14 September 2026 precisely because they gated nothing, and this one is not a
 * return to that: it gates exactly one thing, creating an event, and nothing
 * else in the module may ever read it. Editing, booking, the account hub and
 * every dashboard go on asking ownership or committee capability as before.
 *
 * It exists because the client stopped wanting open submissions (Denis,
 * 22 September 2026). The first plan was to close page 294 (Submit an event)
 * with the Members plugin, which was dropped when it became clear the same page
 * is where a host edits an event they already own: closing the page would have
 * closed editing with it. A capability separates the two cleanly — the page
 * stays open to everybody, and only the "start a new one" path asks for this.
 *
 * Committee members, editors and administrators hold the capability too. They
 * already hold the whole law_event set from law_events_capability_names(), the
 * committee dashboard has no create route of its own (it only edits events that
 * exist), and page 294 is therefore their only front-end way to raise one. The
 * request was to stop members of the public submitting, not to stop LAW.
 */
function law_events_submitter_role() {
	return 'event_submitter';
}

/** The single capability `event_submitter` exists to carry. */
function law_events_submit_capability() {
	return 'law_submit_event';
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

/**
 * Grant the module caps once per version.
 *
 * Roles live in the `wp_user_roles` option, which is database state a git
 * deploy cannot carry, so this runs itself off a version stamp rather than off
 * theme activation: pushing the code is enough on every environment, with no
 * manual step and nothing to remember. Bump $version whenever the grant below
 * changes, or existing sites keep the old one.
 *
 * Version 2 (22 September 2026) adds the `event_submitter` role and the
 * law_submit_event capability.
 */
function law_events_grant_capabilities() {
	$version = '2';
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
		$role->add_cap( law_events_submit_capability() );
	}

	// Committee needs the media library for speaker photos.
	$committee = get_role( 'events_committee' );
	if ( $committee ) {
		$committee->add_cap( 'upload_files' );
	}

	// `event_submitter` carries `read` and one capability, nothing more. It is
	// added ALONGSIDE whatever role a person already holds (subscriber, in
	// practice), never instead of it, so granting it takes nothing away: the
	// Users screen's own "Add role" control is how the committee will hand it
	// out. add_role() returns null and changes nothing when the role already
	// exists, so an existing site keeps any capability somebody has added to it
	// by hand; the add_cap() after it is what guarantees the one that matters.
	add_role(
		law_events_submitter_role(),
		__( 'Event submitter', 'law' ),
		array( 'read' => true, law_events_submit_capability() => true )
	);
	$submitter = get_role( law_events_submitter_role() );
	if ( $submitter ) {
		$submitter->add_cap( law_events_submit_capability() );
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
