<?php
/**
 * Shared request plumbing for the module's admin-post handlers
 * (EVENTS_BOOKINGS.md §4): the nonce/honeypot/rate-limit guard sequence, the
 * JSON-versus-redirect responder, and the signed-out nopriv answer. Written
 * for the bookings handlers; the older handlers (comments, withdraw,
 * committee) predate it and migrate opportunistically.
 *
 * law_events_redirect_back() and law_events_rate_limit_ok() moved here from
 * comments.php (a pure move): they were always module-wide helpers, not
 * thread-specific ones.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
 * @param string $surface Surface key (comment / submit / register / booking).
 * @param int    $user_id Current user (0 for anonymous).
 * @param int    $max     Allowed actions per window (the per-user budget).
 * @param int    $window  Window in seconds.
 * @param int    $ip_max  Optional larger per-IP budget for authenticated
 *                        surfaces: a law firm's office shares one NAT egress
 *                        IP, and eleven colleagues booking within minutes of
 *                        the programme opening must not trip each other
 *                        (the registration handler set this precedent).
 *                        0 keeps the historical behaviour: the IP shares $max.
 */
function law_events_rate_limit_ok( $surface, $user_id = 0, $max = 10, $window = 300, $ip_max = 0 ) {
	// Per-IP AND per-user: many fresh accounts behind one IP share the IP
	// budget, and one account hopping IPs shares the user budget.
	$budgets = array(
		'law_rl_' . $surface . '_ip' . md5( (string) ( $_SERVER['REMOTE_ADDR'] ?? '' ) ) => $ip_max > 0 ? $ip_max : $max,
	);
	if ( $user_id ) {
		$budgets[ 'law_rl_' . $surface . '_u' . (int) $user_id ] = $max;
	}
	foreach ( $budgets as $key => $budget ) {
		if ( (int) get_transient( $key ) >= $budget ) {
			return false;
		}
	}
	foreach ( array_keys( $budgets ) as $key ) {
		set_transient( $key, (int) get_transient( $key ) + 1, $window );
	}
	return true;
}

/**
 * The nopriv answer for a handler that requires a signed-in user: an AJAX
 * post from a page whose user has since logged out must get JSON (a redirect
 * is unparseable to the script); anything else goes to the login screen.
 *
 * Register it directly: add_action( 'admin_post_nopriv_<action>',
 * 'law_events_nopriv_json' );
 */
function law_events_nopriv_json() {
	if ( ! empty( $_POST['law_ajax'] ) ) {
		wp_send_json_error( array( 'message' => 'You have been signed out. Please reload the page and sign in again.' ), 401 );
	}
	wp_safe_redirect( wp_login_url() );
	exit;
}

/**
 * The shared guard sequence every new admin-post handler starts with:
 *
 *   1. AJAX callers get JSON even on a bad nonce (check_admin_referer would
 *      die with an HTML page the script cannot parse), then the classic
 *      check_admin_referer for everyone.
 *   2. Honeypot (law_website_url filled): pretend success and stop, so a bot
 *      learns nothing. The pretend-success payload is the caller's, since it
 *      is the only part that varies between handlers.
 *   3. Rate limit on the caller's surface.
 *
 * @param string $nonce_action The nonce action string (= the admin-post action).
 * @param array  $args {
 *     @type array  $rate            [surface, max, window] for law_events_rate_limit_ok().
 *     @type array  $honeypot_json   wp_send_json_success() payload on a honeypot trip.
 *     @type string $honeypot_notice law_notice value on a no-JS honeypot trip.
 * }
 * @return bool $is_ajax — whether the caller posted law_ajax=1.
 */
function law_events_guard_post( $nonce_action, array $args = array() ) {
	$is_ajax = ! empty( $_POST['law_ajax'] );

	if ( $is_ajax && ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), $nonce_action ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( $nonce_action );

	if ( '' !== trim( (string) ( $_POST['law_website_url'] ?? '' ) ) ) {
		if ( $is_ajax ) {
			wp_send_json_success( (array) ( $args['honeypot_json'] ?? array( 'message' => 'Done.' ) ) );
		}
		law_events_redirect_back( array( 'law_notice' => (string) ( $args['honeypot_notice'] ?? 'saved' ) ) );
	}

	if ( ! empty( $args['rate'] ) ) {
		list( $surface, $max, $window, $ip_max ) = array_pad( (array) $args['rate'], 4, null );
		if ( ! law_events_rate_limit_ok( (string) $surface, get_current_user_id(), (int) ( $max ?? 10 ), (int) ( $window ?? 300 ), (int) ( $ip_max ?? 0 ) ) ) {
			law_events_respond( $is_ajax, false, array( 'message' => 'Too many actions in a short time; please wait a moment.', 'status' => 429 ), 'rate-limited' );
		}
	}

	return $is_ajax;
}

/**
 * The shared response tail: JSON for a fetch caller, redirect-with-notice for
 * the no-JS path. Never returns.
 *
 * @param bool   $is_ajax From law_events_guard_post().
 * @param bool   $ok      Success or failure.
 * @param array  $payload JSON payload — success: title/message/redirect (and
 *                        anything else the caller's script reads); failure:
 *                        message, plus an optional 'status' HTTP code.
 * @param string $notice  law_notice value for the redirect path.
 */
function law_events_respond( $is_ajax, $ok, array $payload, $notice ) {
	if ( $is_ajax ) {
		if ( $ok ) {
			wp_send_json_success( $payload );
		}
		$status = (int) ( $payload['status'] ?? 400 );
		unset( $payload['status'] );
		wp_send_json_error( $payload, $status );
	}
	law_events_redirect_back( array( 'law_notice' => $notice ) );
}
