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
 * @param array $overrides Query overrides, e.g. the export passes
 *                         posts_per_page -1 to escape the 300-row screen cap.
 * @return WP_Post[]
 */
function law_committee_events( array $overrides = array() ) {
	$status = sanitize_key( $_GET['law_status'] ?? '' );
	$known  = law_event_statuses();
	// Drafts are owner-only (unsubmitted host data), so an explicit
	// ?law_status=law-draft must not select them for the committee either.
	$query  = array(
		'post_type'      => LAW_EVENT_CPT,
		'post_status'    => isset( $known[ $status ] ) && 'law-draft' !== $status ? $status : array_diff( law_event_all_status_keys(), array( 'law-draft' ) ),
		'posts_per_page' => 300,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	$keyword = sanitize_text_field( wp_unslash( $_GET['law_kw'] ?? '' ) );
	if ( '' !== $keyword ) {
		$query['s'] = $keyword;
	}
	return get_posts( array_merge( $query, $overrides ) );
}

/** Count per status for the dashboard filter chips. */
function law_committee_status_counts() {
	// One query for every status via wp_count_posts, rather than a capped
	// get_posts per status (which miscounts silently above its limit).
	$totals = wp_count_posts( LAW_EVENT_CPT );
	$counts = array();
	foreach ( array_keys( law_event_statuses() ) as $status ) {
		if ( 'law-draft' === $status ) {
			continue;
		}
		$counts[ $status ] = (int) ( $totals->{$status} ?? 0 );
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

/**
 * AJAX partial: the dashboard URL with &law_partial=1 returns only the event
 * list markup (parts/events/dashboard-list.php), so the filter bar can swap it
 * in place without a reload. Mirrors law_calendar_maybe_render_partial(); the
 * page's Members restriction and the committee check both still apply.
 */
add_action( 'template_redirect', 'law_committee_maybe_render_partial' );
function law_committee_maybe_render_partial() {
	if ( empty( $_GET['law_partial'] ) || ! is_page_template( 'templates/account-dashboard.php' ) ) {
		return;
	}

	$page_id = get_queried_object_id();
	if ( function_exists( 'members_can_current_user_view_post' ) && $page_id && ! members_can_current_user_view_post( $page_id ) ) {
		status_header( 403 );
		exit;
	}
	if ( ! law_user_is_committee() ) {
		status_header( 403 );
		exit;
	}

	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	nocache_headers();
	get_template_part( 'parts/events/dashboard-list' );
	exit;
}

/* The committee action handler ______________________________________________ */

add_action( 'admin_post_law_committee_action', 'law_committee_action_handler' );
add_action( 'admin_post_nopriv_law_committee_action', function () {
	// An AJAX post from a page whose user has since logged out lands here;
	// a redirect would be unparseable to the script, so answer JSON.
	if ( ! empty( $_POST['law_ajax'] ) ) {
		wp_send_json_error( array( 'message' => 'You have been signed out. Please reload the page and sign in again.' ), 401 );
	}
	wp_safe_redirect( wp_login_url() );
	exit;
} );

function law_committee_action_handler() {
	$is_ajax = ! empty( $_POST['law_ajax'] );

	// An AJAX caller must get JSON even on a bad nonce — check_admin_referer
	// would die with an HTML page the script cannot parse. A stale nonce here
	// usually means the session changed under the page (logged out, or
	// switched user in another tab), so "reload" is the honest advice.
	if ( $is_ajax && ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'law_committee_action' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_committee_action' );

	if ( ! law_user_is_committee() ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Sorry, this action is for the committee.' ), 403 );
		}
		wp_die( 'Sorry, this action is for the committee.' );
	}

	$event_id = absint( $_POST['event_id'] ?? 0 );
	$post     = get_post( $event_id );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Event not found. Please reload the page.' ), 404 );
		}
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
		law_event_update_meta( $event_id, '_law_assignee', law_events_sanitize_assignee( wp_unslash( $_POST['law_assignee'] ) ) );
		law_event_maybe_notify_assignee( $event_id, $before_assignee, $actor );
	}
	if ( isset( $_POST['law_slot_label'] ) ) {
		$slot_label = sanitize_text_field( wp_unslash( $_POST['law_slot_label'] ) );
		$old_label  = (string) law_event_meta( $event_id, '_law_slot_label' );
		law_event_update_meta( $event_id, '_law_slot_label', $slot_label );
		law_event_apply_slot_label( $event_id, $slot_label );
		if ( $old_label !== $slot_label ) {
			law_event_log(
				$event_id,
				sprintf( 'Confirmed slot changed to "%s".', $slot_label ?: '(none)' ),
				array( 'action' => 'slot', 'old' => $old_label, 'new' => $slot_label, 'source' => 'ui' ),
				array( 'user_id' => $actor )
			);
		}
	}

	// The sentinel says the controls were on the form, so absent inputs mean
	// "cleared" (unchecked boxes and empty multi-selects post nothing).
	if ( ! empty( $_POST['law_terms_present'] ) ) {
		law_events_set_terms_by_name( $event_id, 'law_event_category', array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['law_event_category'] ?? array() ) ) );
		law_event_update_meta( $event_id, '_law_organisation_ids', array_map( 'absint', (array) ( $_POST['law_organisation_ids'] ?? array() ) ) );
	}

	// A private note goes straight to the activity log (§3.6 manual notes).
	$private_note = trim( (string) wp_unslash( $_POST['law_private_note'] ?? '' ) );
	if ( '' !== $private_note ) {
		law_event_log( $event_id, $private_note, array( 'action' => 'note', 'source' => 'ui' ), array( 'manual' => true, 'user_id' => $actor ) );
	}

	$notice = 'saved';
	$action = sanitize_key( $_POST['law_action'] ?? '' );

	// The AJAX caller is always a modal action, so an empty action means the
	// submitter's name/value never made it into the request body. Falling
	// through to the "Save changes" path would look like success while the
	// approval never happened, so refuse loudly instead. The field writes
	// above have already run, hence the wording.
	if ( $is_ajax && '' === $action ) {
		wp_send_json_error( array( 'message' => 'Your other changes were saved, but the action itself was not received. Please reload the page and try again.' ), 400 );
	}

	// Delete (trash) is not a workflow transition, so it is handled here, before
	// the workflow whitelist below would log it as a refused action. It is only
	// offered — and only accepted — on a Cancelled or Rejected event: trashing a
	// live event would orphan an open Stripe invoice with no void and no host
	// email, so cancel/reject must come first.
	if ( 'delete' === $action ) {
		if ( ! in_array( $post->post_status, array( 'law-cancelled', 'law-rejected' ), true ) ) {
			$message = 'Only a cancelled or rejected event can be deleted. Cancel or reject it first.';
			if ( $is_ajax ) {
				wp_send_json_error( array( 'message' => $message ), 403 );
			}
			set_transient( 'law_dashboard_error_' . $actor, $message, 60 );
			wp_safe_redirect(
				add_query_arg(
					array( 'event' => $event_id, 'law_notice' => 'action-failed' ),
					home_url( '/account/dashboard/' )
				)
			);
			exit;
		}

		// The log line first: it must exist before the post leaves the dashboard.
		// Trash keeps the log (it lives in comments), so it survives a restore.
		law_event_log(
			$event_id,
			'Event moved to trash from the committee dashboard.',
			array( 'action' => 'trash', 'source' => 'ui' ),
			array( 'user_id' => $actor )
		);
		wp_trash_post( $event_id );

		// Back to the LIST view (no event= arg): the detail panel cannot load a
		// trashed post. Restoring and permanent deletion stay wp-admin jobs.
		if ( $is_ajax ) {
			wp_send_json_success(
				array(
					'title'    => 'Event deleted',
					'message'  => 'It can be restored from the wp-admin Events list. Reloading the page…',
					'redirect' => home_url( '/account/dashboard/' ),
				)
			);
		}
		wp_safe_redirect( add_query_arg( 'law_notice', 'event-deleted', home_url( '/account/dashboard/' ) ) );
		exit;
	}

	// Only the actions this screen actually offers may come from this POST.
	// Without the whitelist a hand-made request could run 'confirm' and publish
	// an approved event whose invoice is still Unpaid (open finding 3 in
	// EVENTS_4.1_FUNC_V2.md §6). An empty action is the plain "Save changes".
	$allowed = law_event_ui_actions();
	if ( '' !== $action && ! in_array( $action, $allowed, true ) ) {
		law_event_log(
			$event_id,
			sprintf( 'Refused committee action "%s": not offered by the dashboard.', $action ),
			array( 'action' => 'refused', 'attempted' => $action, 'source' => 'ui' ),
			array( 'user_id' => $actor )
		);
		// On the AJAX path the message travels in the response; the transient
		// would only be consumed by this same request's aftermath and lost.
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'That action is not available from the dashboard.' ), 403 );
		}
		set_transient( 'law_dashboard_error_' . $actor, 'That action is not available from the dashboard.', 60 );
		wp_safe_redirect(
			add_query_arg(
				array( 'event' => $event_id, 'law_notice' => 'action-failed' ),
				home_url( '/account/dashboard/' )
			)
		);
		exit;
	}
	if ( '' !== $action ) {
		$note   = trim( (string) wp_unslash( $_POST['law_note'] ?? '' ) );
		$result = law_event_workflow_transition(
			$event_id,
			$action,
			array( 'comment' => $note, 'reason' => $note, 'actor_id' => $actor )
		);
		if ( is_wp_error( $result ) ) {
			if ( $is_ajax ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			set_transient( 'law_dashboard_error_' . $actor, $result->get_error_message(), 60 );
			$notice = 'action-failed';
		} else {
			$notice = 'action-' . $action;
		}
	}

	if ( $is_ajax ) {
		$titles = array(
			'approve'   => 'Event approved',
			'send_back' => 'Event sent back to the host',
			'reject'    => 'Event rejected',
			'mark_paid' => 'Event marked as paid and confirmed',
			'cancel'    => 'Event cancelled',
		);
		wp_send_json_success(
			array(
				'title'   => $titles[ $action ] ?? 'Done',
				'message' => 'Reloading the page…',
				// No law_notice: the success dialog has already confirmed the
				// action, so the reloaded page should not banner it again.
				'redirect' => add_query_arg( 'event', $event_id, home_url( '/account/dashboard/' ) ),
			)
		);
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
