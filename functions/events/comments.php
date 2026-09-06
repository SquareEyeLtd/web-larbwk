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
	// An AJAX post from a page whose user has since logged out lands here;
	// a redirect would be unparseable to the script, so answer JSON.
	if ( ! empty( $_POST['law_ajax'] ) ) {
		wp_send_json_error( array( 'message' => 'You have been signed out. Please reload the page and sign in again.' ), 401 );
	}
	wp_safe_redirect( wp_login_url() );
	exit;
} );

function law_event_handle_comment_reply() {
	// The front-end form posts via fetch with law_ajax=1 and gets JSON back;
	// without the flag (script failed, or another caller) the redirect-with-
	// notice flow below still works unchanged.
	$is_ajax = ! empty( $_POST['law_ajax'] );

	// An AJAX caller must get JSON even on a bad nonce — check_admin_referer
	// would die with an HTML page the script cannot parse. A stale nonce here
	// usually means the session changed under the page (logged out, or
	// switched user in another tab), so "reload" is the honest advice.
	if ( $is_ajax && ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'law_event_comment_reply' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_event_comment_reply' );

	$event_id = absint( $_POST['event_id'] ?? 0 );
	$text     = trim( (string) wp_unslash( $_POST['comment'] ?? '' ) );
	$user_id  = get_current_user_id();

	if ( ! law_user_can_manage_event( $user_id, $event_id ) ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Sorry, you are not allowed to comment on this event.' ), 403 );
		}
		wp_die( 'Sorry, you are not allowed to comment on this event.' );
	}
	if ( '' !== trim( (string) ( $_POST['law_website_url'] ?? '' ) ) ) {
		// Honeypot: pretend success (no bubble — nothing was saved).
		if ( $is_ajax ) {
			wp_send_json_success( array( 'message' => 'Comment sent.' ) );
		}
		law_events_redirect_back( array( 'law_notice' => 'comment-added' ) );
	}
	if ( '' === $text ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Please enter your message.' ) );
		}
		law_events_redirect_back( array( 'law_notice' => 'comment-empty' ) );
	}
	if ( ! law_events_rate_limit_ok( 'comment', $user_id ) ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Too many comments in a short time; please wait a moment.' ), 429 );
		}
		law_events_redirect_back( array( 'law_notice' => 'rate-limited' ) );
	}

	$comment_id = law_event_add_comment( $event_id, $text, $user_id );

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

	if ( $is_ajax ) {
		// Re-read: the resubmit transition above may have changed the status.
		$status = law_event_status_label( get_post( $event_id ) );
		ob_start();
		get_template_part( 'parts/events/thread-bubble', null, array( 'comment' => get_comment( $comment_id ) ) );
		wp_send_json_success(
			array(
				'message'      => 'Comment sent.',
				'bubble'       => ob_get_clean(),
				'status'       => $status,
				// Mirrors the template's button logic so the label stays right
				// after a resubmit flips Sent back → Proposed.
				'button_label' => ( ! $is_committee && 'Sent back' === $status )
					? 'Reply & resubmit to the committee'
					: 'Send reply',
			)
		);
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
