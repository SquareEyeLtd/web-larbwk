<?php
/**
 * Co-owner accounts (EVENTS_4.1_REBUILD.md §3.4). Rows are stored on the
 * event at submission (_law_co_owner_rows); accounts are created or linked
 * ON APPROVAL (settled decision, September 2026), and a co-owner added by a
 * host edit after approval is processed on save.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walk the stored co-owner rows: link existing users by email, create the
 * rest as event_host, store the IDs in _law_co_owner_ids, log everything.
 *
 * Idempotent: already-linked users are kept, not duplicated.
 *
 * @param int  $event_id   law_event post ID.
 * @param int  $actor      Acting user for the log.
 * @param bool $send_email Send the new-account email (false during migration).
 * @return int[] Linked user IDs.
 */
function law_event_ensure_co_owner_users( $event_id, $actor = 0, $send_email = true ) {
	$rows     = law_event_meta( $event_id, '_law_co_owner_rows' );
	$existing = array_map( 'intval', law_event_meta( $event_id, '_law_co_owner_ids' ) );
	$ids      = $existing;

	foreach ( $rows as $row ) {
		$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			law_event_log(
				$event_id,
				sprintf( 'Co-owner "%s" skipped: no valid email address.', $row['name'] ?? '' ),
				array( 'action' => 'co_owner_skipped', 'source' => 'workflow' ),
				array( 'user_id' => (int) $actor )
			);
			continue;
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			if ( ! in_array( (int) $user->ID, $ids, true ) ) {
				$ids[] = (int) $user->ID;
				law_event_log(
					$event_id,
					sprintf( 'Co-owner linked to existing account: %s (%s).', $user->display_name, $email ),
					array( 'action' => 'co_owner_linked', 'user' => (int) $user->ID, 'source' => 'workflow' ),
					array( 'user_id' => (int) $actor )
				);
				// Tell the linked account it now has access. Linking an existing
				// user by email match otherwise grants event access silently; the
				// notice gives them a route to flag it if unexpected.
				if ( $send_email ) {
					law_event_notify_co_owner_linked( $event_id, $user );
				}
			}
			continue;
		}

		$user_id = law_events_create_host_user( $email, (string) ( $row['name'] ?? '' ), (string) ( $row['organisation'] ?? '' ), $send_email );
		if ( is_wp_error( $user_id ) ) {
			law_event_log(
				$event_id,
				sprintf( 'Co-owner account creation failed for %s: %s', $email, $user_id->get_error_message() ),
				array( 'action' => 'co_owner_error', 'source' => 'workflow' ),
				array( 'user_id' => (int) $actor )
			);
			continue;
		}

		$ids[] = (int) $user_id;
		law_event_log(
			$event_id,
			sprintf( 'Co-owner account created: %s (%s).', $row['name'] ?? $email, $email ),
			array( 'action' => 'co_owner_created', 'user' => (int) $user_id, 'source' => 'workflow' ),
			array( 'user_id' => (int) $actor )
		);
	}

	$ids = array_values( array_unique( array_filter( $ids ) ) );
	law_event_set_co_owner_ids( $event_id, $ids );
	return $ids;
}

/**
 * Notify an existing account that it has been linked as a co-owner. Kept plain
 * (not a managed template) so it always sends even outside the approval email
 * batch; the point is that the person is never linked without being told.
 *
 * @param int     $event_id law_event post ID.
 * @param WP_User $user     The newly linked account.
 */
function law_event_notify_co_owner_linked( $event_id, $user ) {
	$title   = get_the_title( $event_id );
	$subject = sprintf( 'You have been added as an owner of "%s"', $title );
	$body    = sprintf(
		"Hello %s,\n\nYou have been added as an additional owner of the London Arbitration Week event \"%s\". You can now view and manage it from your account:\n\n%s\n\nIf you were not expecting this, please reply to this email or contact the events committee so we can look into it.\n\nLondon Arbitration Week",
		$user->display_name,
		$title,
		home_url( '/account/events/' )
	);
	wp_mail( $user->user_email, $subject, $body );
}

/**
 * The single write path for co-owner IDs: the array meta (what the module
 * reads) plus one flat `_law_co_owner` row per user ID (what the dashboard
 * query matches — a REGEXP against the serialized array would confuse array
 * KEYS with user IDs).
 *
 * @param int   $event_id law_event post ID.
 * @param int[] $ids      Co-owner user IDs.
 */
function law_event_set_co_owner_ids( $event_id, array $ids ) {
	$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
	update_post_meta( $event_id, '_law_co_owner_ids', $ids );
	delete_post_meta( $event_id, '_law_co_owner' );
	foreach ( $ids as $id ) {
		add_post_meta( $event_id, '_law_co_owner', $id );
	}
}

/**
 * Create an event_host user. Username = email (matching the form 1 feed),
 * password reset via the branded flow in functions/auth.php.
 *
 * @return int|WP_Error User ID.
 */
function law_events_create_host_user( $email, $name = '', $organisation = '', $send_email = true ) {
	$name_parts = preg_split( '/\s+/', trim( $name ), 2 );
	$user_id    = wp_insert_user(
		array(
			'user_login'   => sanitize_user( $email, true ),
			'user_email'   => $email,
			'user_pass'    => wp_generate_password( 24 ),
			'first_name'   => $name_parts[0] ?? '',
			'last_name'    => $name_parts[1] ?? '',
			'display_name' => trim( $name ) ?: $email,
			'role'         => 'event_host',
		)
	);

	if ( is_wp_error( $user_id ) ) {
		return $user_id;
	}

	if ( '' !== trim( $organisation ) ) {
		// 'organisation' is the site-wide user meta key (the old UR feed's
		// mapping); phase D's profile form reads and writes the same key.
		update_user_meta( $user_id, 'organisation', sanitize_text_field( $organisation ) );
	}

	if ( $send_email ) {
		// Core new-user notification carries the password-set link, which the
		// auth module rewrites onto the branded /login/?action=reset page.
		wp_send_new_user_notifications( $user_id, 'user' );
	}

	return (int) $user_id;
}

/**
 * Events a user owns or co-owns, for the host dashboard.
 *
 * @param int $user_id User ID.
 * @return int[] law_event post IDs, newest first.
 */
function law_events_owned_event_ids( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}

	$own = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => law_event_all_status_keys(),
			'author'         => $user_id,
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'orderby'        => 'date',
			'order'          => 'DESC',
		)
	);

	global $wpdb;
	$co = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.post_id FROM {$wpdb->postmeta} pm
			 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_law_co_owner'
			   AND p.post_type = %s
			   AND pm.meta_value = %d",
			LAW_EVENT_CPT,
			$user_id
		)
	);

	$ids = array_map( 'intval', array_merge( (array) $own, (array) $co ) );
	return array_values( array_unique( $ids ) );
}
