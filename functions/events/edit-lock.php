<?php
/**
 * Edit locking for the front-end event form (replacing GravityView entry
 * locking), modelled on WordPress core's post lock.
 *
 * Core's lock is a `_edit_lock` post meta of "<unix time>:<user id>" that
 * counts as live for 150 seconds. The editor takes it on load, the heartbeat
 * refreshes it every few seconds while the tab is open, and unloading the page
 * back-dates it so the next person is not made to wait out the window.
 *
 * The theme had only the first of those three. Taking the lock on render with
 * nothing to refresh or release it made the lock simultaneously too sticky and
 * too weak: merely opening the edit view (a committee member glancing at an
 * event, say) blocked everyone else for 150 seconds with "X is editing this
 * event right now", while somebody genuinely typing lost their lock after 150
 * seconds and could then be saved over. This file adds the refresh and the
 * release, so the lock means what the notice says it means.
 *
 * Core's own functions are reused for the read and the write; only the
 * permission check differs. wp_check_post_lock()/wp_set_post_lock() gate on
 * nothing and core's AJAX/heartbeat handlers gate on `edit_post`, which every
 * host would fail — host-side roles hold no law_event capabilities at all
 * (capabilities.php). Everything here gates on law_user_can_manage_event()
 * instead, the module's per-event test.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** wp_check_post_lock() and wp_set_post_lock() live in wp-admin. */
function law_event_lock_bootstrap() {
	require_once ABSPATH . 'wp-admin/includes/post.php';
}

/**
 * The lock window in seconds. Core's filtered default, read through the same
 * filter so a site-wide change applies here too.
 */
function law_event_lock_window() {
	return (int) apply_filters( 'wp_check_post_lock_window', 150 );
}

/**
 * Who holds the lock on this event, if anyone other than the current user.
 *
 * @param int $event_id law_event post ID.
 * @return int User ID, or 0 when the event is free (or locked by the caller).
 */
function law_event_lock_holder( $event_id ) {
	law_event_lock_bootstrap();
	return (int) wp_check_post_lock( (int) $event_id );
}

/**
 * Take the lock for the current user.
 *
 * @param int $event_id law_event post ID.
 * @return string "<time>:<user id>", or '' on failure.
 */
function law_event_lock_take( $event_id ) {
	law_event_lock_bootstrap();
	$lock = wp_set_post_lock( (int) $event_id );
	return is_array( $lock ) ? implode( ':', $lock ) : '';
}

/**
 * Release the lock the caller holds.
 *
 * Back-dates the lock rather than deleting the meta, exactly as core's
 * wp_ajax_wp_remove_post_lock() does: the row stays as a record of who was
 * last in the event, and it reads as expired to wp_check_post_lock().
 *
 * The write is conditional on $lock still being the stored value, so a release
 * arriving late (an unload beacon overtaken by the same user's next page load)
 * can never clear a newer lock. Passing $lock is therefore strongly preferred;
 * without it the release is unconditional and only applies to a lock the
 * current user actually holds.
 *
 * @param int    $event_id law_event post ID.
 * @param string $lock     The lock value the caller believes it holds.
 * @return bool Whether anything was written.
 */
function law_event_lock_release( $event_id, $lock = '' ) {
	$event_id = (int) $event_id;
	$user_id  = get_current_user_id();
	if ( $event_id < 1 || $user_id < 1 ) {
		return false;
	}

	$stored = (string) get_post_meta( $event_id, '_edit_lock', true );
	if ( '' === $stored ) {
		return false;
	}

	$parts = explode( ':', $stored );
	if ( (int) ( $parts[1] ?? 0 ) !== $user_id ) {
		return false; // Somebody else's lock: never ours to drop.
	}

	// Core's arithmetic verbatim (wp_ajax_wp_remove_post_lock): one whole
	// window ago, plus 5. That leaves the lock live for a final 5 seconds
	// rather than dropping it on the instant — the filter's own docblock calls
	// the default "the interval the post lock should last, plus 5 seconds" —
	// which is a grace period against a same-user reload racing its own
	// unload beacon. Five seconds instead of 150 is the whole point.
	$expired = ( time() - law_event_lock_window() + 5 ) . ':' . $user_id;

	return $lock
		? (bool) update_post_meta( $event_id, '_edit_lock', $expired, $lock )
		: (bool) update_post_meta( $event_id, '_edit_lock', $expired );
}

/**
 * The lock notice and the state the browser needs, for the top of an edit form.
 *
 * Shared by the host form (templates/account-event-form.php) and the
 * committee's edit view (parts/events/committee-event-form.php), which had
 * identical copies of this block. Read-only detail views never call it: only
 * an actual edit form takes the lock.
 *
 * When the event is free the lock is taken and its value is handed to the
 * browser so the heartbeat can refresh it and unloading can release it. When
 * somebody else holds it, no lock is taken and the notice explains why saving
 * will be refused — the handler refuses it server-side and returns the typed
 * values, so nothing is lost by letting them try.
 *
 * @param WP_Post|null $post The event being edited; null on a new submission.
 */
function law_event_lock_field( $post ) {
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$holder = law_event_lock_holder( $post->ID );
	$lock   = $holder ? '' : law_event_lock_take( $post->ID );

	printf(
		'<div class="law-event-lock" data-law-event-lock data-law-lock-event="%s" data-law-lock-value="%s" data-law-lock-endpoint="%s" data-law-lock-nonce="%s">',
		esc_attr( (string) $post->ID ),
		esc_attr( $lock ),
		esc_url( admin_url( 'admin-ajax.php' ) ),
		esc_attr( wp_create_nonce( 'law_event_lock_' . $post->ID ) )
	);
	if ( $holder ) {
		$user = get_user_by( 'id', $holder );
		printf(
			'<div class="law-form-notice is-error" role="alert">%s</div>',
			esc_html( law_event_lock_message( $user ? $user->display_name : '' ) )
		);
	}
	echo '</div>';
}

/**
 * The one wording for "someone else is in this event", so the notice the
 * browser writes on a heartbeat takeover reads identically to the one PHP
 * rendered on page load.
 *
 * @param string $name The holder's display name.
 */
function law_event_lock_message( $name = '' ) {
	return sprintf(
		'%s is editing this event right now. You can look, but saving will be refused until they finish.',
		'' !== trim( (string) $name ) ? $name : 'Another user'
	);
}

/* The heartbeat refresh ______________________________________________________ */

/**
 * Keep the lock alive while an edit form is open, and report a takeover.
 *
 * Mirrors core's wp_refresh_post_lock() (wp-admin/includes/misc.php) with the
 * module's permission check. Sending no lock is meaningful: a page that is
 * showing the "someone else is editing" notice keeps asking, and the moment
 * the other lock expires this takes it and tells the browser to clear the
 * notice — which is the whole point, since the notice used to sit there until
 * the reader thought to reload.
 */
add_filter(
	'heartbeat_received',
	function ( $response, $data ) {
		if ( ! array_key_exists( 'law-refresh-event-lock', $data ) ) {
			return $response;
		}

		$received = (array) $data['law-refresh-event-lock'];
		$event_id = absint( $received['event_id'] ?? 0 );
		if ( $event_id < 1 || ! law_user_can_manage_event( get_current_user_id(), $event_id ) ) {
			return $response;
		}

		$send   = array();
		$holder = law_event_lock_holder( $event_id );
		if ( $holder ) {
			$user               = get_user_by( 'id', $holder );
			$send['lock_error'] = law_event_lock_message( $user ? $user->display_name : '' );
		} else {
			$lock = law_event_lock_take( $event_id );
			if ( '' !== $lock ) {
				$send['new_lock'] = $lock;
			}
		}

		$response['law-refresh-event-lock'] = $send;
		return $response;
	},
	10,
	2
);

/* The unload release ________________________________________________________ */

/**
 * Release the lock when the editor closes the tab or navigates away, so the
 * next person does not wait out a window nobody is using. Sent as an unload
 * beacon, so it must stay cheap and must not care about the response.
 */
add_action(
	'wp_ajax_law_event_release_lock',
	function () {
		$event_id = absint( $_POST['event_id'] ?? 0 );
		$lock     = sanitize_text_field( wp_unslash( (string) ( $_POST['lock'] ?? '' ) ) );

		if ( $event_id < 1 || '' === $lock ) {
			wp_send_json_error( 'Missing event or lock.', 400 );
		}
		if ( ! check_ajax_referer( 'law_event_lock_' . $event_id, 'nonce', false ) ) {
			wp_send_json_error( 'Expired.', 403 );
		}
		if ( ! law_user_can_manage_event( get_current_user_id(), $event_id ) ) {
			wp_send_json_error( 'Not allowed.', 403 );
		}

		law_event_lock_release( $event_id, $lock );
		wp_send_json_success();
	}
);
