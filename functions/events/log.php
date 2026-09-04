<?php
/**
 * The activity log: WooCommerce-order-notes-style history on every event
 * (EVENTS_4.1_REBUILD.md §3.6). Append-only WP comments of type
 * law_event_log, each with a readable line plus structured context meta.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_EVENT_LOG_TYPE     = 'law_event_log';
const LAW_EVENT_COMMENT_TYPE = 'law_event_comment';

/**
 * Append a log entry to an event.
 *
 * @param int    $event_id law_event post ID.
 * @param string $message  Human-readable line.
 * @param array  $context  Structured context: action, source, old/new values,
 *                         Stripe IDs, amounts. Stored as comment meta.
 * @param array  $args     Optional: user_id (actor; 0 = system), date (mysql,
 *                         for migrated history), manual (true for hand-written notes).
 * @return int Comment ID, 0 on failure.
 */
function law_event_log( $event_id, $message, array $context = array(), array $args = array() ) {
	$event_id = (int) $event_id;
	if ( $event_id < 1 || '' === trim( (string) $message ) ) {
		return 0;
	}

	$user_id = isset( $args['user_id'] ) ? (int) $args['user_id'] : get_current_user_id();
	$user    = $user_id ? get_user_by( 'id', $user_id ) : null;

	$context = array_merge(
		array(
			'source' => 'system',
			'manual' => ! empty( $args['manual'] ),
		),
		$context
	);

	$comment_id = wp_insert_comment(
		array(
			'comment_post_ID'      => $event_id,
			'comment_type'         => LAW_EVENT_LOG_TYPE,
			'comment_content'      => wp_kses_post( $message ),
			'comment_approved'     => 1,
			'user_id'              => $user_id,
			'comment_author'       => $user ? $user->display_name : 'System',
			'comment_author_email' => $user ? $user->user_email : '',
			'comment_agent'        => 'law-events',
			'comment_date'         => isset( $args['date'] ) ? $args['date'] : current_time( 'mysql' ),
			'comment_date_gmt'     => isset( $args['date_gmt'] )
				? $args['date_gmt']
				: ( isset( $args['date'] ) ? get_gmt_from_date( $args['date'] ) : current_time( 'mysql', true ) ),
		)
	);

	if ( $comment_id ) {
		add_comment_meta( $comment_id, '_law_log_context', wp_json_encode( $context ) );
	}

	return (int) $comment_id;
}

/**
 * Log entries for an event, newest first.
 *
 * @param int $event_id law_event post ID.
 * @return WP_Comment[]
 */
function law_event_log_entries( $event_id ) {
	return get_comments(
		array(
			'post_id' => (int) $event_id,
			'type'    => LAW_EVENT_LOG_TYPE,
			'status'  => 'approve',
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		)
	);
}

/** Decoded context for one log entry. */
function law_event_log_context( $comment_id ) {
	$raw = get_comment_meta( (int) $comment_id, '_law_log_context', true );
	$ctx = json_decode( (string) $raw, true );
	return is_array( $ctx ) ? $ctx : array();
}

/* Keep module comment types out of everything public _______________________ */

/**
 * Exclude log entries and event threads from front-end comment queries,
 * feeds and counts: they are internal records, not blog comments.
 */
add_filter(
	'comments_clauses',
	function ( $clauses, $query ) {
		if ( is_admin() ) {
			return $clauses;
		}
		$type = $query->query_vars['type'] ?? '';
		if ( in_array( $type, array( LAW_EVENT_LOG_TYPE, LAW_EVENT_COMMENT_TYPE ), true ) ) {
			return $clauses; // Module's own queries.
		}
		global $wpdb;
		$clauses['where'] .= $wpdb->prepare(
			' AND comment_type NOT IN (%s, %s)',
			LAW_EVENT_LOG_TYPE,
			LAW_EVENT_COMMENT_TYPE
		);
		return $clauses;
	},
	10,
	2
);

add_filter(
	'comment_feed_where',
	function ( $where ) {
		global $wpdb;
		return $where . $wpdb->prepare(
			' AND comment_type NOT IN (%s, %s)',
			LAW_EVENT_LOG_TYPE,
			LAW_EVENT_COMMENT_TYPE
		);
	}
);
