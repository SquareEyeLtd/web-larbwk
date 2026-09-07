<?php
/**
 * Email test mode (the Emails screen). While it is on, every email the site tries
 * to send is delivered to one address instead of its real recipients, so the
 * committee can rehearse the whole workflow (submit → approve → pay →
 * publish) without a single message reaching a host or a committee member.
 *
 * The redirect is applied on the `wp_mail` filter at priority 99, i.e. just
 * BEFORE the Email Templates plugin wraps the body at priority 100, so the
 * "test mode" note ends up inside the normal branding.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_EVENTS_TEST_MODE_OPTION = 'law_events_test_mode';

/**
 * How long a single enabling of test mode lasts before it switches itself off.
 *
 * Test mode swallows EVERY email the site sends, password resets and new-user
 * notifications included, so leaving it on is an account-takeover route: a
 * reset link for any account, including an administrator's, is delivered to
 * the test mailbox instead of its owner. A rehearsal is a sitting-at-the-desk
 * activity measured in minutes, so the window is deliberately short: two
 * hours, after which the setting expires rather than relying on someone
 * remembering to switch it back. Saving the card again restarts the two hours,
 * so a longer session just means re-saving.
 */
const LAW_EVENTS_TEST_MODE_MAX_HOURS = 2;

/**
 * Stored test-mode settings.
 *
 * `no_expiry` is the deliberate opt-out of the auto-off window (for a staging
 * environment that should stay in test mode for days): expires_at becomes 0
 * and the mode runs until someone switches it off. The standing admin notice
 * on every screen still shows, and enabling on production still requires the
 * confirmation tick, so the guard that remains is a human one.
 *
 * @return array{enabled:bool,email:string,enabled_at:int,expires_at:int,expired:bool,no_expiry:bool}
 */
function law_events_test_mode() {
	$saved = get_option( LAW_EVENTS_TEST_MODE_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}

	$enabled    = ! empty( $saved['enabled'] );
	$no_expiry  = ! empty( $saved['no_expiry'] );
	$enabled_at = (int) ( $saved['enabled_at'] ?? 0 );
	// A row saved before the expiry existed has no timestamp: treat it as
	// starting now rather than as instantly expired, so an upgrade mid-rehearsal
	// does not silently change behaviour.
	$expires_at = ( $enabled && ! $no_expiry ) ? ( $enabled_at ?: time() ) + LAW_EVENTS_TEST_MODE_MAX_HOURS * HOUR_IN_SECONDS : 0;

	return array(
		'enabled'    => $enabled,
		'email'      => is_email( (string) ( $saved['email'] ?? '' ) ) ? (string) $saved['email'] : '',
		'enabled_at' => $enabled_at,
		'expires_at' => $expires_at,
		'expired'    => $enabled && $expires_at > 0 && time() > $expires_at,
		'no_expiry'  => $no_expiry,
	);
}

/**
 * The window as a phrase for the UI, e.g. "2 hours", so the copy and the
 * constant cannot drift apart.
 *
 * @return string
 */
function law_events_test_mode_duration_label() {
	$hours = LAW_EVENTS_TEST_MODE_MAX_HOURS;
	return 1 === $hours ? '1 hour' : $hours . ' hours';
}

/**
 * When the current window ends, phrased for a two-hour setting: "today at
 * 14:30" reads better than a full date that is almost always today.
 *
 * @param int $expires_at Unix timestamp.
 * @return string
 */
function law_events_test_mode_expiry_label( $expires_at ) {
	$expires_at = (int) $expires_at;
	if ( $expires_at <= 0 ) {
		return '';
	}

	$time = wp_date( 'H:i', $expires_at );
	$day  = wp_date( 'Y-m-d', $expires_at );

	if ( wp_date( 'Y-m-d' ) === $day ) {
		return 'today at ' . $time;
	}
	if ( wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) === $day ) {
		return 'tomorrow at ' . $time;
	}

	return wp_date( 'j F Y', $expires_at ) . ' at ' . $time;
}

/**
 * The single check every email path uses: is test mode on, unexpired, AND is a
 * usable address stored? A ticked checkbox with no valid address does nothing,
 * so a half-finished setting can never silently swallow mail.
 *
 * @return bool
 */
function law_events_is_test_mode() {
	$settings = law_events_test_mode();
	return $settings['enabled'] && ! $settings['expired'] && '' !== $settings['email'];
}

/**
 * Whether this install is the live site, for the extra confirmation step on
 * enabling test mode.
 *
 * WordPress defaults wp_get_environment_type() to 'production' when nothing is
 * set, which is the right way round: the confirmation appears unless an
 * environment has positively declared itself non-live. Set
 * `define( 'WP_ENVIRONMENT_TYPE', 'local' );` (or 'staging') in wp-config.php
 * on the local and staging installs to drop it there.
 *
 * @return bool
 */
function law_events_is_production() {
	return (bool) apply_filters( 'law_events_is_production', 'production' === wp_get_environment_type() );
}

/**
 * Turn an expired setting off for real, so the Emails screen and the admin
 * notice agree with what the mail path is actually doing. Runs on admin_init
 * rather than inside law_events_is_test_mode(), which is on the wp_mail hot
 * path and must not write.
 */
add_action( 'admin_init', 'law_events_test_mode_expire' );
function law_events_test_mode_expire() {
	$settings = law_events_test_mode();
	if ( ! $settings['expired'] ) {
		return;
	}
	update_option(
		LAW_EVENTS_TEST_MODE_OPTION,
		array( 'enabled' => false, 'email' => $settings['email'], 'enabled_at' => 0 ),
		false
	);
}

/**
 * A standing warning on EVERY admin screen while test mode is on. The card on
 * The Emails screen is only seen by someone who goes looking; this is what stops it
 * being left on unnoticed.
 */
add_action( 'admin_notices', 'law_events_test_mode_admin_notice' );
function law_events_test_mode_admin_notice() {
	if ( ! current_user_can( 'manage_options' ) || ! law_events_is_test_mode() ) {
		return;
	}
	$settings = law_events_test_mode();
	printf(
		'<div class="notice notice-warning"><p><strong>Email test mode is ON.</strong> Every email this site sends, including password resets and new-account notifications, is being delivered to <code>%1$s</code> instead of its real recipients. %2$s <a href="%3$s">Turn it off now</a>.</p></div>',
		esc_html( $settings['email'] ),
		esc_html(
			$settings['no_expiry']
				? 'It stays on until someone switches it off (the auto-expiry is disabled).'
				: 'It switches itself off ' . law_events_test_mode_expiry_label( $settings['expires_at'] ) . '.'
		),
		esc_url( add_query_arg( array( 'page' => 'law-events-emails' ), admin_url( 'admin.php' ) ) )
	);
}

/**
 * The address everything is delivered to, or '' when test mode is off.
 *
 * @return string
 */
function law_events_test_mode_address() {
	return law_events_is_test_mode() ? law_events_test_mode()['email'] : '';
}

/**
 * Validate a candidate test address: RFC-ish syntax first, then a DNS lookup
 * on the domain. DNS can only prove the domain can receive mail at all — no
 * check short of sending can prove the mailbox itself exists, so a passing
 * domain is reported as "deliverable domain", not "real mailbox".
 *
 * @param string $email Candidate address.
 * @return array{valid:bool,level:string,message:string}
 */
function law_events_test_mode_check_email( $email ) {
	$email = trim( (string) $email );

	if ( '' === $email ) {
		return array( 'valid' => false, 'level' => 'error', 'message' => 'Enter an email address.' );
	}
	if ( ! is_email( $email ) ) {
		return array( 'valid' => false, 'level' => 'error', 'message' => 'That is not a valid email address.' );
	}

	$domain = substr( $email, strrpos( $email, '@' ) + 1 );

	if ( ! function_exists( 'checkdnsrr' ) ) {
		return array(
			'valid'   => true,
			'level'   => 'warning',
			'message' => 'Address looks valid. The domain could not be checked on this server (DNS lookups are unavailable).',
		);
	}

	// Fall back to A/AAAA: a domain with no MX still accepts mail at its A record.
	$has_mx = @checkdnsrr( $domain, 'MX' );
	$has_a  = $has_mx ? true : ( @checkdnsrr( $domain, 'A' ) || @checkdnsrr( $domain, 'AAAA' ) );

	if ( ! $has_mx && ! $has_a ) {
		return array(
			'valid'   => false,
			'level'   => 'error',
			'message' => sprintf( 'The domain %s does not exist or cannot receive email.', $domain ),
		);
	}
	if ( ! $has_mx ) {
		return array(
			'valid'   => true,
			'level'   => 'warning',
			'message' => sprintf( '%s has no mail (MX) record, so delivery may fail. Check the address is right.', $domain ),
		);
	}

	return array(
		'valid'   => true,
		'level'   => 'ok',
		'message' => sprintf( 'Looks good: %s accepts email.', $domain ),
	);
}

/* Live validation behind the Enable test mode field ________________________ */

add_action( 'wp_ajax_law_events_check_email', function () {
	check_ajax_referer( 'law_events_admin' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}
	wp_send_json_success( law_events_test_mode_check_email( wp_unslash( $_GET['email'] ?? '' ) ) );
} );

/* The redirect _____________________________________________________________ */

add_filter( 'wp_mail', 'law_events_test_mode_redirect', 99 );

/**
 * Send everything to the test address instead of the real recipients.
 *
 * @param array $atts wp_mail() arguments.
 * @return array
 */
function law_events_test_mode_redirect( $atts ) {
	$address = law_events_test_mode_address();
	if ( '' === $address ) {
		return $atts;
	}

	$original = implode( ', ', array_filter( array_map( 'trim', (array) ( $atts['to'] ?? array() ) ) ) );
	$headers  = law_events_test_mode_headers( $atts['headers'] ?? '' );
	$is_html  = (bool) preg_grep( '#content-type:\s*text/html#i', $headers );

	$note = sprintf(
		'TEST MODE is on, so this email was delivered to %s instead of its real recipients (%s).',
		$address,
		'' !== $original ? $original : 'none resolved'
	);

	$atts['to']      = array( $address );
	$atts['headers'] = array_merge(
		$headers,
		array(
			'X-LAW-Test-Mode: on',
			'X-LAW-Test-Original-To: ' . ( '' !== $original ? $original : 'none' ),
		)
	);

	$subject = (string) ( $atts['subject'] ?? '' );
	if ( ! str_starts_with( $subject, '[TEST MODE]' ) ) {
		$atts['subject'] = '[TEST MODE] ' . $subject;
	}

	$atts['message'] = $is_html
		? '<p style="padding:10px;border:1px solid #dba617;background:#fcf9e8"><strong>' . esc_html( $note ) . '</strong></p>' . (string) ( $atts['message'] ?? '' )
		: $note . "\n\n" . (string) ( $atts['message'] ?? '' );

	return $atts;
}

/**
 * Normalise wp_mail headers to an array and drop Cc/Bcc, which would leak the
 * email to real people the redirect is meant to protect.
 *
 * @param string|array $headers Raw wp_mail headers.
 * @return string[]
 */
function law_events_test_mode_headers( $headers ) {
	if ( ! is_array( $headers ) ) {
		$headers = preg_split( "/\r\n|\r|\n/", (string) $headers );
	}
	$headers = array_filter( array_map( 'trim', (array) $headers ) );

	return array_values(
		array_filter(
			$headers,
			fn( $header ) => ! preg_match( '/^(cc|bcc)\s*:/i', $header )
		)
	);
}
