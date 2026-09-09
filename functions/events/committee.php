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

	// The two committee flag filters. The "off" side of each needs BOTH limbs:
	// an event the committee has never saved has no meta row at all, while one
	// saved with the box unticked carries a literal '0', because
	// law_event_update_meta() only deletes on '' and the 'flag' sanitiser
	// returns integer 0. With only NOT EXISTS, every event the committee has
	// ever opened would drop out of the "Run by a host" filter.
	$meta_query = array();
	$run_by     = sanitize_key( $_GET['law_run_by'] ?? '' );
	if ( 'law' === $run_by ) {
		$meta_query[] = array( 'key' => '_law_is_law_event', 'value' => '1' );
	} elseif ( 'host' === $run_by ) {
		$meta_query[] = array(
			'relation' => 'OR',
			array( 'key' => '_law_is_law_event', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_law_is_law_event', 'value' => '1', 'compare' => '!=' ),
		);
	}
	$agenda = sanitize_key( $_GET['law_agenda'] ?? '' );
	if ( 'yes' === $agenda ) {
		$meta_query[] = array( 'key' => '_law_session_agenda', 'value' => '1' );
	} elseif ( 'no' === $agenda ) {
		$meta_query[] = array(
			'relation' => 'OR',
			array( 'key' => '_law_session_agenda', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_law_session_agenda', 'value' => '1', 'compare' => '!=' ),
		);
	}
	if ( $meta_query ) {
		// AND so the two axes compose: "our own events that have an agenda" is
		// the question a single mixed filter could not answer.
		$query['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	// The flagship conference is not a host submission: it has no workflow, fee,
	// slot or invoice, and it is edited on its own screen (Events > Flagship),
	// so it does not belong in the committee's review queue. Excluded by ID
	// rather than a NOT EXISTS meta clause deliberately: the meta_query above is
	// replaced wholesale by a caller's own, and a second LEFT JOIN on every
	// dashboard query buys nothing over one memoised ID lookup.
	$flagship = function_exists( 'law_flagship_event_id' ) ? law_flagship_event_id() : 0;
	if ( $flagship ) {
		$query['post__not_in'] = array( $flagship );
	}

	// NB: a caller passing its own meta_query in $overrides would replace the
	// filters above, not add to them. The export (the only caller that passes
	// anything) only overrides posts_per_page.
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

	// wp_count_posts() counts every law_event, including the flagship, which
	// law_committee_events() excludes. Left alone, its status chip would be one
	// higher than the list it filters.
	$flagship = function_exists( 'law_flagship_event_id' ) ? law_flagship_event_id() : 0;
	if ( $flagship ) {
		$status = (string) get_post_status( $flagship );
		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ] = max( 0, $counts[ $status ] - 1 );
		}
	}

	return $counts;
}

/** The event opened in the dashboard detail view. */
function law_committee_requested_event() {
	$event_id = absint( $_GET['event'] ?? 0 );
	if ( ! $event_id ) {
		return null;
	}
	// The flagship is never in this list, so an ?event=<flagship id> here is a
	// stale link or a guess. Refused rather than rendered, because the detail
	// view offers workflow actions and a slot the flagship does not have; the
	// dashboard's notice points at the screen that does edit it.
	if ( function_exists( 'law_flagship_is' ) && law_flagship_is( $event_id ) ) {
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

/**
 * Refuse a dashboard post and stop. On the AJAX path the message travels in
 * the response, because the transient would only be consumed by this same
 * request's aftermath and lost; a plain submit gets it back on the detail
 * panel through law_committee_take_error(). Never returns.
 *
 * @param int    $event_id law_event post ID (the panel to land back on).
 * @param bool   $is_ajax  Whether the caller posted law_ajax.
 * @param string $message  What to tell the committee member.
 * @param int    $code     HTTP status for the JSON path.
 */
function law_committee_refuse( $event_id, $is_ajax, $message, $code = 400 ) {
	if ( $is_ajax ) {
		wp_send_json_error( array( 'message' => $message ), $code );
	}
	set_transient( 'law_dashboard_error_' . get_current_user_id(), $message, 60 );
	wp_safe_redirect(
		add_query_arg(
			array( 'event' => (int) $event_id, 'law_notice' => 'action-failed' ),
			home_url( '/account/dashboard/' )
		)
	);
	exit;
}

/**
 * Check a posted venue capacity band and places-available pair.
 *
 * Kept out of the handler so the rules can be tested (and reused) rather than
 * only exercised through a request that exits. The band ceiling is inclusive,
 * matching law_events_venue_capacity_bands(); "251+" and "TBC" map to null,
 * which means no ceiling.
 *
 * @param string $capacity Posted band, '' for "not set".
 * @param string $tickets  Posted places, '' for "no limit".
 * @return string An empty string when the pair is acceptable, else the message
 *                to refuse it with.
 */
function law_committee_venue_input_error( $capacity, $tickets ) {
	$bands    = law_events_venue_capacity_bands();
	$capacity = (string) $capacity;
	$tickets  = trim( (string) $tickets );

	// Only reachable from a tampered or stale select. Refused rather than
	// stored, because an unrecognised band is read as "no ceiling" everywhere it
	// is checked, which silently uncaps the ticket allocation.
	// array_key_exists, not isset: "251+" and "TBC" map to NULL (no ceiling),
	// and isset() reads a null value as an absent key, so isset() would refuse
	// the two perfectly valid uncapped bands.
	if ( '' !== $capacity && ! array_key_exists( $capacity, $bands ) ) {
		return 'That is not one of the venue capacity bands. Please reload the page and try again.';
	}
	if ( '' === $tickets ) {
		return '';
	}
	if ( ! ctype_digit( $tickets ) || (int) $tickets < 1 ) {
		return 'Places available must be a whole number of 1 or more, or blank for no limit.';
	}
	// Checked against the band being saved in this very post, not the stored one.
	$limit = $bands[ $capacity ] ?? null;
	if ( null !== $limit && (int) $tickets > $limit ) {
		return sprintf(
			'Places available cannot exceed the venue capacity band (%1$s allows at most %2$d).',
			$capacity,
			$limit
		);
	}
	return '';
}

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

	// The venue capacity band and places available, validated here rather than
	// at their write below for the same reason the fee override is: a refusal
	// must leave the event exactly as it was, and the writes further down would
	// already have landed. The sentinel says the control was on the form, so a
	// blank field means "cleared" rather than "not asked".
	$venue_present = ! empty( $_POST['law_venue_present'] );
	$capacity_new  = '';
	$tickets_new   = '';
	if ( $venue_present ) {
		$capacity_new = sanitize_text_field( wp_unslash( $_POST['law_venue_capacity'] ?? '' ) );
		$tickets_new  = trim( (string) wp_unslash( $_POST['law_tickets_available'] ?? '' ) );
		$venue_error  = law_committee_venue_input_error( $capacity_new, $tickets_new );
		if ( '' !== $venue_error ) {
			law_committee_refuse( $event_id, $is_ajax, $venue_error );
		}
	}

	// The host fee override is checked BEFORE any write, so a refusal leaves the
	// event exactly as it was: the other field writes below and the workflow
	// action further down never run.
	if ( isset( $_POST['law_fee_override_amount'] ) ) {
		$override_on = ! empty( $_POST['law_fee_override'] );
		$amount_raw  = trim( (string) wp_unslash( $_POST['law_fee_override_amount'] ) );

		// A ticked box with an empty amount used to sanitise to £0.00, which
		// waives the fee, skips the invoice and auto-confirms the event. Far too
		// consequential to be what an empty box means, so it is refused; a
		// deliberately typed 0 still waives the fee.
		if ( $override_on && '' === $amount_raw ) {
			law_committee_refuse(
				$event_id,
				$is_ajax,
				'Enter the new host fee in pounds. Type 0 to waive the fee entirely, or untick "Override the host fee" to charge the tier price.'
			);
		}

		// Only reachable from a hand-made request: the control renders read-only
		// once the fee is snapshotted. Logged, as the module logs every refusal.
		if ( law_event_fee_override_locked( $event_id ) ) {
			law_event_log(
				$event_id,
				'Refused a host fee override change: the fee was snapshotted at approval, so the change would reach neither the snapshot nor the invoice.',
				array( 'action' => 'refused', 'attempted' => 'fee_override', 'source' => 'ui' ),
				array( 'user_id' => $actor )
			);
			law_committee_refuse(
				$event_id,
				$is_ajax,
				'The host fee was snapshotted when this event was approved, so it can no longer be changed here. Use "Full editing in wp-admin" for a post-approval fee change.',
				403
			);
		}

		law_event_update_meta( $event_id, '_law_fee_override', $override_on );
		law_event_update_meta( $event_id, '_law_fee_override_amount', $amount_raw );
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

	// Validated above. The band and the places available are the committee's at
	// every status: on an event LAW found the venue for, the host is never shown
	// these two at all (law_events_venue_details_visible()), so this panel and
	// wp-admin are the only places they can be set.
	if ( $venue_present ) {
		$before_capacity = (string) law_event_meta( $event_id, '_law_venue_capacity' );
		$before_places   = (int) law_event_meta( $event_id, '_law_tickets_available' );
		law_event_update_meta( $event_id, '_law_venue_capacity', $capacity_new );
		law_event_update_meta( $event_id, '_law_tickets_available', $tickets_new );
		law_event_log_capacity_change( $event_id, $before_capacity, $actor );
		// Writes the "Places available changed" line, and raising them is what
		// offers the new places to anyone waiting.
		law_event_tickets_changed(
			$event_id,
			$before_places,
			(int) law_event_meta( $event_id, '_law_tickets_available' ),
			$actor,
			'committee_panel'
		);
	}

	// The sentinel says the control was on the form, so an absent input means
	// "cleared" (an empty multi-select posts nothing).
	if ( ! empty( $_POST['law_orgs_present'] ) ) {
		$before_orgs = law_event_meta( $event_id, '_law_organisation_ids' );
		law_event_update_meta( $event_id, '_law_organisation_ids', array_map( 'absint', (array) ( $_POST['law_organisation_ids'] ?? array() ) ) );
		law_event_log_organisation_change( $event_id, (array) $before_orgs, $actor );
	}

	// The committee's two classification switches. Its own sentinel, not
	// law_orgs_present above: the groups must be independently absent-safe,
	// and an unticked checkbox posts nothing, so without a sentinel a flag
	// could be switched on and then never off.
	if ( ! empty( $_POST['law_flags_present'] ) ) {
		$before_flags = array(
			'_law_is_law_event'   => (int) law_event_meta( $event_id, '_law_is_law_event' ),
			'_law_session_agenda' => (int) law_event_meta( $event_id, '_law_session_agenda' ),
		);
		law_event_update_meta( $event_id, '_law_is_law_event', ! empty( $_POST['law_is_law_event'] ) );
		law_event_update_meta( $event_id, '_law_session_agenda', ! empty( $_POST['law_session_agenda'] ) );
		law_event_log_flag_change( $event_id, $before_flags, $actor );

		// Which notice the redirect below should use. Turning the agenda on puts
		// a new section on a DIFFERENT screen (the edit form), so "Changes saved."
		// would leave the committee hunting for fields on this one; turning it
		// off while sessions exist does not remove the section, which reads as a
		// control with no effect unless we say so.
		$agenda_now = (int) law_event_meta( $event_id, '_law_session_agenda' );
		if ( $before_flags['_law_session_agenda'] !== $agenda_now ) {
			$agenda_notice = $agenda_now
				? 'agenda-on'
				: ( law_event_session_ids( $event_id ) ? 'agenda-off-kept' : 'agenda-off' );
		}
	}

	// A private note goes straight to the activity log (§3.6 manual notes).
	$private_note = trim( (string) wp_unslash( $_POST['law_private_note'] ?? '' ) );
	if ( '' !== $private_note ) {
		law_event_log( $event_id, $private_note, array( 'action' => 'note', 'source' => 'ui' ), array( 'manual' => true, 'user_id' => $actor ) );
	}

	$notice = $agenda_notice ?? 'saved';
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
			law_committee_refuse(
				$event_id,
				$is_ajax,
				'Only a cancelled or rejected event can be deleted. Cancel or reject it first.',
				403
			);
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
	// EVENTS_FUNC.md §6). An empty action is the plain "Save changes".
	$allowed = law_event_ui_actions();
	if ( '' !== $action && ! in_array( $action, $allowed, true ) ) {
		law_event_log(
			$event_id,
			sprintf( 'Refused committee action "%s": not offered by the dashboard.', $action ),
			array( 'action' => 'refused', 'attempted' => $action, 'source' => 'ui' ),
			array( 'user_id' => $actor )
		);
		law_committee_refuse( $event_id, $is_ajax, 'That action is not available from the dashboard.', 403 );
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
