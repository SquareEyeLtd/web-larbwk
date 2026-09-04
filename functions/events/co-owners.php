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
	update_post_meta( $event_id, '_law_co_owner_ids', $ids );
	return $ids;
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
		update_user_meta( $user_id, 'law_organisation_name', sanitize_text_field( $organisation ) );
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
			 WHERE pm.meta_key = '_law_co_owner_ids'
			   AND p.post_type = %s
			   AND pm.meta_value REGEXP %s",
			LAW_EVENT_CPT,
			// Serialized int array containing this exact user ID.
			'i:[0-9]+;i:' . $user_id . ';'
		)
	);

	$ids = array_map( 'intval', array_merge( (array) $own, (array) $co ) );
	return array_values( array_unique( $ids ) );
}
