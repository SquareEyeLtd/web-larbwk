<?php
/**
 * The host/committee clarification thread: WP comments of type
 * law_event_comment on the event (EVENTS_4.1_REBUILD.md §3.8). A host reply
 * on a Sent back event is what resubmits it to the committee.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Add a thread comment.
 *
 * @param int    $event_id law_event post ID.
 * @param string $text     Plain-text comment.
 * @param int    $user_id  Author user ID.
 * @param array  $args     Optional: date/date_gmt (migration), author_name,
 *                         author_email (when no user resolves).
 * @return int Comment ID.
 */
function law_event_add_comment( $event_id, $text, $user_id = 0, array $args = array() ) {
	$event_id = (int) $event_id;
	$text     = trim( (string) $text );
	if ( $event_id < 1 || '' === $text ) {
		return 0;
	}

	$user = $user_id ? get_user_by( 'id', (int) $user_id ) : null;

	return (int) wp_insert_comment(
		array(
			'comment_post_ID'      => $event_id,
			'comment_type'         => LAW_EVENT_COMMENT_TYPE,
			'comment_content'      => sanitize_textarea_field( $text ),
			'comment_approved'     => 1,
			'user_id'              => (int) $user_id,
			'comment_author'       => $user ? $user->display_name : (string) ( $args['author_name'] ?? '' ),
			'comment_author_email' => $user ? $user->user_email : (string) ( $args['author_email'] ?? '' ),
			'comment_agent'        => 'law-events',
			'comment_date'         => $args['date'] ?? current_time( 'mysql' ),
			'comment_date_gmt'     => $args['date_gmt']
				?? ( isset( $args['date'] ) ? get_gmt_from_date( $args['date'] ) : current_time( 'mysql', true ) ),
		)
	);
}

/**
 * Thread comments for an event, oldest first.
 *
 * @return WP_Comment[]
 */
function law_event_comments( $event_id ) {
	return get_comments(
		array(
			'post_id' => (int) $event_id,
			'type'    => LAW_EVENT_COMMENT_TYPE,
			'status'  => 'approve',
			'orderby' => 'comment_date_gmt',
			'order'   => 'ASC',
		)
	);
}

/** The newest thread comment, for the {latest_comment} placeholder. */
function law_event_latest_comment( $event_id ) {
	$comments = get_comments(
		array(
			'post_id' => (int) $event_id,
			'type'    => LAW_EVENT_COMMENT_TYPE,
			'status'  => 'approve',
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
			'number'  => 1,
		)
	);
	return $comments ? $comments[0] : null;
}

/** Whether a comment author is committee-level, for the role badge. */
function law_event_comment_is_committee( $comment ) {
	return $comment instanceof WP_Comment
		&& $comment->user_id
		&& user_can( (int) $comment->user_id, 'edit_others_law_events' );
}

/* Front-end reply handler ___________________________________________________ */

add_action( 'admin_post_law_event_comment_reply', 'law_event_handle_comment_reply' );
add_action( 'admin_post_nopriv_law_event_comment_reply', function () {
	wp_safe_redirect( wp_login_url() );
	exit;
} );

function law_event_handle_comment_reply() {
	check_admin_referer( 'law_event_comment_reply' );

	$event_id = absint( $_POST['event_id'] ?? 0 );
	$text     = trim( (string) wp_unslash( $_POST['comment'] ?? '' ) );
	$user_id  = get_current_user_id();

	if ( ! law_user_can_manage_event( $user_id, $event_id ) ) {
		wp_die( 'Sorry, you are not allowed to comment on this event.' );
	}
	if ( '' !== trim( (string) ( $_POST['law_website_url'] ?? '' ) ) ) {
		law_events_redirect_back( array( 'law_notice' => 'comment-added' ) ); // Honeypot: pretend success.
	}
	if ( '' === $text ) {
		law_events_redirect_back( array( 'law_notice' => 'comment-empty' ) );
	}
	if ( ! law_events_rate_limit_ok( 'comment', $user_id ) ) {
		law_events_redirect_back( array( 'law_notice' => 'rate-limited' ) );
	}

	law_event_add_comment( $event_id, $text, $user_id );

	$is_committee = law_user_is_committee( $user_id );
	$post         = get_post( $event_id );

	// A host reply on a Sent back event resubmits it to the committee.
	if ( ! $is_committee && $post && 'law-sent-back' === $post->post_status ) {
		law_event_workflow_transition( $event_id, 'resubmit', array( 'actor_id' => $user_id ) );
	} elseif ( $is_committee ) {
		law_events_send( 'user_new_comment', $event_id );
	} else {
		law_events_send( 'committee_new_comment', $event_id );
	}

	law_events_redirect_back( array( 'law_notice' => 'comment-added' ) );
}

/** Redirect back to the referring page with a query flag. */
function law_events_redirect_back( array $args = array() ) {
	$back = wp_get_referer();
	if ( ! $back ) {
		$back = home_url( '/account/events/' );
	}
	wp_safe_redirect( add_query_arg( array_map( 'rawurlencode', $args ), $back ) );
	exit;
}

/**
 * Cheap per-user/IP rate limit for public write surfaces
 * (EVENTS_4.1_REBUILD.md §3.11).
 *
 * @param string $surface Surface key (comment / submit / register).
 * @param int    $user_id Current user (0 for anonymous).
 * @param int    $max     Allowed actions per window.
 * @param int    $window  Window in seconds.
 */
function law_events_rate_limit_ok( $surface, $user_id = 0, $max = 10, $window = 300 ) {
	// Per-IP AND per-user: many fresh accounts behind one IP share the IP
	// budget, and one account hopping IPs shares the user budget.
	$keys   = array( 'law_rl_' . $surface . '_ip' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) );
	if ( $user_id ) {
		$keys[] = 'law_rl_' . $surface . '_u' . (int) $user_id;
	}
	foreach ( $keys as $key ) {
		if ( (int) get_transient( $key ) >= $max ) {
			return false;
		}
	}
	foreach ( $keys as $key ) {
		set_transient( $key, (int) get_transient( $key ) + 1, $window );
	}
	return true;
}
