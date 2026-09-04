<?php
/**
 * Committee dashboard actions (EVENTS_4.1_REBUILD.md §3.6, committee UI):
 * the front-end review flow replacing the Gravity Flow inbox. The template
 * is templates/account-dashboard.php; this file is the data and the handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Events for the committee list, filtered by ?law_status= and ?law_kw=.
 *
 * @return WP_Post[]
 */
function law_committee_events() {
	$status = sanitize_key( $_GET['law_status'] ?? '' );
	$known  = law_event_statuses();
	$query  = array(
		'post_type'      => LAW_EVENT_CPT,
		'post_status'    => isset( $known[ $status ] ) ? $status : array_diff( law_event_all_status_keys(), array( 'law-draft' ) ),
		'posts_per_page' => 300,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	$keyword = sanitize_text_field( wp_unslash( $_GET['law_kw'] ?? '' ) );
	if ( '' !== $keyword ) {
		$query['s'] = $keyword;
	}
	return get_posts( $query );
}

/** Count per status for the dashboard filter chips. */
function law_committee_status_counts() {
	$counts = array();
	foreach ( array_keys( law_event_statuses() ) as $status ) {
		if ( 'law-draft' === $status ) {
			continue;
		}
		$found = get_posts(
			array(
				'post_type'      => LAW_EVENT_CPT,
				'post_status'    => $status,
				'fields'         => 'ids',
				'posts_per_page' => 300,
			)
		);
		$counts[ $status ] = count( $found );
	}
	return $counts;
}

/** The event opened in the dashboard detail view. */
function law_committee_requested_event() {
	$event_id = absint( $_GET['event'] ?? 0 );
	if ( ! $event_id ) {
		return null;
	}
	$post = get_post( $event_id );
	return $post && LAW_EVENT_CPT === $post->post_type ? $post : null;
}

/* The committee action handler ______________________________________________ */

add_action( 'admin_post_law_committee_action', 'law_committee_action_handler' );
add_action( 'admin_post_nopriv_law_committee_action', function () {
	wp_safe_redirect( wp_login_url() );
	exit;
} );

function law_committee_action_handler() {
	check_admin_referer( 'law_committee_action' );

	if ( ! law_user_is_committee() ) {
		wp_die( 'Sorry, this action is for the committee.' );
	}

	$event_id = absint( $_POST['event_id'] ?? 0 );
	$post     = get_post( $event_id );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		wp_die( 'Event not found.' );
	}
	$actor = get_current_user_id();

	// Field updates land first so an approval snapshots fresh values.
	$before_override = (int) law_event_meta( $event_id, '_law_fee_override' );
	$before_amount   = (float) law_event_meta( $event_id, '_law_fee_override_amount' );
	$before_assignee = (int) law_event_meta( $event_id, '_law_assignee' );

	if ( isset( $_POST['law_fee_override_amount'] ) ) {
		law_event_update_meta( $event_id, '_law_fee_override', ! empty( $_POST['law_fee_override'] ) );
		law_event_update_meta( $event_id, '_law_fee_override_amount', wp_unslash( $_POST['law_fee_override_amount'] ) );
		law_event_log_fee_change( $event_id, $before_override, $before_amount, $actor );
	}
	if ( isset( $_POST['law_assignee'] ) ) {
		law_event_update_meta( $event_id, '_law_assignee', wp_unslash( $_POST['law_assignee'] ) );
		law_event_maybe_notify_assignee( $event_id, $before_assignee, $actor );
	}
	if ( isset( $_POST['law_slot_label'] ) ) {
		$slot_label = sanitize_text_field( wp_unslash( $_POST['law_slot_label'] ) );
		$old_label  = (string) law_event_meta( $event_id, '_law_slot_label' );
		law_event_update_meta( $event_id, '_law_slot_label', $slot_label );
		$slots = law_events_slots( true );
		if ( isset( $slots[ $slot_label ] ) && $slots[ $slot_label ]['date'] ) {
			$slot = $slots[ $slot_label ];
			law_event_update_meta( $event_id, '_law_start', $slot['date'] . ' ' . ( $slot['start'] ?: '00:00' ) );
			law_event_update_meta( $event_id, '_law_end', $slot['end'] ? $slot['date'] . ' ' . $slot['end'] : '' );
		} elseif ( '' === $slot_label ) {
			law_event_update_meta( $event_id, '_law_start', '' );
			law_event_update_meta( $event_id, '_law_end', '' );
		}
		if ( $old_label !== $slot_label ) {
			law_event_log(
				$event_id,
				sprintf( 'Confirmed slot changed to "%s".', $slot_label ?: '(none)' ),
				array( 'action' => 'slot', 'old' => $old_label, 'new' => $slot_label, 'source' => 'ui' ),
				array( 'user_id' => $actor )
			);
		}
	}

	$notice = 'saved';
	$action = sanitize_key( $_POST['law_action'] ?? '' );
	if ( '' !== $action ) {
		$note   = trim( (string) wp_unslash( $_POST['law_note'] ?? '' ) );
		$result = law_event_workflow_transition(
			$event_id,
			$action,
			array( 'comment' => $note, 'reason' => $note, 'actor_id' => $actor )
		);
		if ( is_wp_error( $result ) ) {
			set_transient( 'law_dashboard_error_' . $actor, $result->get_error_message(), 60 );
			$notice = 'action-failed';
		} else {
			$notice = 'action-' . $action;
		}
	}

	wp_safe_redirect(
		add_query_arg(
			array( 'event' => $event_id, 'law_notice' => $notice ),
			home_url( '/account/dashboard/' )
		)
	);
	exit;
}

/** One-shot dashboard error message (refused transitions). */
function law_committee_take_error() {
	$key   = 'law_dashboard_error_' . get_current_user_id();
	$error = get_transient( $key );
	if ( $error ) {
		delete_transient( $key );
	}
	return (string) $error;
}
