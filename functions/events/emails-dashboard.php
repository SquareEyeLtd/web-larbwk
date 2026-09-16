<?php
/**
 * The committee's "Manage emails" screen (/account/dashboard/emails/,
 * templates/account-dashboard-emails.php).
 *
 * The same feature as the wp-admin Emails screen
 * (functions/events/admin/emails-screen.php), on the front end, for the same
 * reason every other management screen is there: a member holding only
 * events_committee should not have to learn wp-admin (Denis, 10 September
 * 2026). Both screens read law_events_email_registry() and write through the
 * shared helpers in notifications.php — law_events_email_override_from_input(),
 * law_events_email_save_override(), law_events_email_reset_override() and
 * law_events_email_send_test() — so neither owns the data and the two cannot
 * drift.
 *
 * Two deliberate differences from the wp-admin screen:
 *
 * 1. TEST MODE IS NOT OFFERED HERE. The card at the top of the wp-admin list
 *    is not an email-wording control: it diverts EVERY email the site sends,
 *    password resets for real accounts included, to one address. It is gated
 *    on manage_options there and stays gated on manage_options, so a committee
 *    member cannot switch it on from the front end. What they do get is the
 *    warning below, and only while it is actually on, because an editor
 *    pressing "Send test to me" needs to know why the message arrived
 *    somewhere else.
 *
 * 2. The body is a plain textarea, not a WYSIWYG. That is what an email body
 *    IS: law_events_email_override_from_input() stores it through
 *    sanitize_textarea_field() and law_events_send() renders it with
 *    esc_html() + wpautop(), so any markup typed into it would be delivered as
 *    visible angle brackets. The wp-admin screen's wp_editor() promises
 *    formatting it cannot keep.
 *
 * NOT LOGGED, on either screen. The module's activity log is per-event
 * (law_event_log() writes a comment on a law_event post and returns early
 * without one), and an email's wording belongs to no event: it changes what
 * every future send says. Recording "who reworded what, when" would need a
 * site-wide stream the module does not have, so nothing here pretends to keep
 * one.
 *
 * Module convention: this domain file owns its save handler and its asset
 * gating. The registry and the rules live in notifications.php, which knows
 * nothing about either screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template the screen renders through. */
const LAW_EMAILS_DASHBOARD_TEMPLATE = 'templates/account-dashboard-emails.php';

/** Its path, resolved by path everywhere so environment IDs never matter. */
const LAW_EMAILS_DASHBOARD_PATH = 'account/dashboard/emails';

/** The screen's URL, optionally addressing one notification. */
function law_emails_dashboard_url( $slug = '' ) {
	$url = function_exists( 'law_account_url' ) && law_account_url( 'emails' )
		? law_account_url( 'emails' )
		: home_url( '/' . LAW_EMAILS_DASHBOARD_PATH . '/' );

	return $slug ? add_query_arg( 'law_email', sanitize_key( $slug ), $url ) : $url;
}

/** Whether the current request is rendering this screen. */
function law_emails_dashboard_is_template() {
	return is_page_template( LAW_EMAILS_DASHBOARD_TEMPLATE );
}

/**
 * Which view the request is asking for.
 *
 * @return string A registry slug, or '' for the list.
 */
function law_emails_dashboard_requested() {
	// is_scalar before the cast, as the POST side does: ?law_email[]=x arrives
	// as an array, and casting one to string is an "Array to string conversion"
	// notice in the log for nothing.
	$raw   = $_GET['law_email'] ?? '';
	$asked = sanitize_key( wp_unslash( is_scalar( $raw ) ? (string) $raw : '' ) );

	return ( $asked && law_events_email( $asked ) ) ? $asked : '';
}

/**
 * The rows the list renders: one per registered notification, merged with any
 * stored override, plus the two flags the list badges.
 *
 * @return array<int,array<string,mixed>>
 */
function law_emails_dashboard_rows() {
	$rows = array();

	foreach ( array_keys( law_events_email_registry() ) as $slug ) {
		$email  = law_events_email( $slug );
		$rows[] = array(
			'slug'       => $slug,
			'name'       => (string) $email['name'],
			'trigger'    => law_events_email_trigger_label( $email ),
			'to'         => law_events_email_recipients_label( $email ),
			'active'     => ! empty( $email['active'] ),
			'customised' => law_events_email_is_customised( $slug ),
			'unresolved' => law_events_email_has_unresolved_tags( $email ),
		);
	}

	return $rows;
}

/**
 * Transient-backed re-population, the pattern the speakers, flagship and
 * discounts dashboards use: written by the handler, read ONCE by the view and
 * deleted, so a later visit is not haunted by an old edit.
 *
 * Here it does two things the wp-admin screen does not. "Send a test to me"
 * renders the values AS TYPED and saves nothing, so without this the redirect
 * back would throw away the very draft the editor was testing; and a refused
 * save comes back with the typed values rather than the stored ones.
 *
 * The draft carries the slug it was typed against and is only handed back for
 * that same notification. Without that, testing the wording of one email and
 * then opening the next would repopulate the second one's form with the
 * first one's draft.
 *
 * @param string $slug The notification the view is rendering.
 * @return array{errors:array<string,string>,input:array}
 */
function law_emails_dashboard_state( $slug ) {
	$key   = 'law_email_state_' . get_current_user_id();
	$state = get_transient( $key );
	if ( ! is_array( $state ) ) {
		return array( 'errors' => array(), 'input' => array() );
	}
	delete_transient( $key );

	if ( (string) ( $state['slug'] ?? '' ) !== (string) $slug ) {
		return array( 'errors' => array(), 'input' => array() );
	}

	return array(
		'errors' => (array) ( $state['errors'] ?? array() ),
		'input'  => (array) ( $state['input'] ?? array() ),
	);
}

/** Store one draft, against its notification, for a single read. */
function law_emails_dashboard_store_state( $slug, array $input, array $errors = array() ) {
	set_transient(
		'law_email_state_' . get_current_user_id(),
		array( 'slug' => (string) $slug, 'input' => $input, 'errors' => $errors ),
		5 * MINUTE_IN_SECONDS
	);
}

/* The save handler __________________________________________________________ */

add_action( 'admin_post_law_email_manage', 'law_email_manage_handler' );
add_action( 'admin_post_nopriv_law_email_manage', 'law_events_nopriv_json' );

function law_email_manage_handler() {
	// Its own rate surface: a committee member working through the wording of
	// a dozen notifications must not spend the event-submission budget.
	$is_ajax = law_events_guard_post(
		'law_email_manage',
		array(
			'rate'            => array( 'email_manage', 60, 600, 300 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'email-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, managing the events emails is for the committee.', 'status' => 403 ),
			'email-denied'
		);
	}

	$slug       = sanitize_key( wp_unslash( (string) ( $_POST['law_email_slug'] ?? '' ) ) );
	$definition = $slug ? law_events_email( $slug ) : null;
	if ( ! $definition ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That notification could not be found.', 'status' => 404 ), 'email-missing' );
	}

	// Reset first: it discards the form's values rather than reading them.
	if ( ! empty( $_POST['law_email_reset'] ) ) {
		law_events_email_reset_override( $slug );
		law_events_respond(
			$is_ajax,
			true,
			array(
				'title'    => 'Reset to the standard wording',
				'message'  => sprintf( '%s has been returned to its standard wording.', $definition['name'] ),
				'redirect' => law_emails_dashboard_url( $slug ),
			),
			'email-reset'
		);
	}

	// Cast before reading keys: law_email posted as a scalar (or not at all)
	// must not become a string-offset read.
	$posted = wp_unslash( $_POST['law_email'] ?? array() );
	$posted = is_array( $posted ) ? $posted : array();
	$input  = array(
		'subject' => $posted['subject'] ?? '',
		'body'    => $posted['body'] ?? '',
		'active'  => ! empty( $posted['active'] ),
	) + ( isset( $posted['to'] ) ? array( 'to' => $posted['to'] ) : array() );

	$override = law_events_email_override_from_input( $slug, $input );

	// Every address typed was rejected, so the builder kept the stored ones.
	// Saying so beats saving something that does not match the screen.
	if ( ! law_events_email_recipients_survived( $slug, $input, $override ) ) {
		law_emails_dashboard_store_state( $slug, $input, array( 'to' => 'Please check the recipients.' ) );
		law_events_respond(
			$is_ajax,
			false,
			array(
				'message' => 'None of those recipients is a valid email address, so nothing was saved. Separate addresses with commas, or untick "Send this notification" to stop it being sent at all.',
				// The input's NAME: assets/js/booking-form.js marks the offending
				// field with querySelector('[name="..."]'), so anything else
				// here would refuse the save and highlight nothing.
				'field'   => 'law_email[to]',
				'status'  => 400,
			),
			'email-recipients'
		);
	}

	// Send test renders what is on screen and persists nothing, so the wording
	// can be read in a real inbox before anybody commits to it. The draft rides
	// a one-shot transient so the redirect does not throw it away.
	if ( ! empty( $_POST['law_email_test'] ) ) {
		if ( ! law_events_rate_limit_ok( 'email_test', get_current_user_id(), 20, 600 ) ) {
			law_events_respond( $is_ajax, false, array( 'message' => 'Too many test emails in a short time; please wait a moment and try again.', 'status' => 429 ), 'rate-limited' );
		}

		law_emails_dashboard_store_state( $slug, $input );
		$test = law_events_email_send_test( $override );

		if ( ! $test['sent'] ) {
			law_events_respond( $is_ajax, false, array( 'message' => 'The test email could not be sent. Please try again.', 'status' => 500 ), 'email-test-failed' );
		}

		// Test mode diverts every send, so "sent to you" would be a lie while
		// it is on. Say where it actually went.
		$diverted = law_events_test_mode_address();

		law_events_respond(
			$is_ajax,
			true,
			array(
				'title'    => 'Test email sent',
				'message'  => '' !== $diverted
					? sprintf( 'Test mode is on, so the test went to %s rather than to you. Nothing was saved: use Save changes to keep this wording.', $diverted )
					: sprintf( 'A test has been sent to %s. Nothing was saved: use Save changes to keep this wording.', $test['email'] ),
				'redirect' => law_emails_dashboard_url( $slug ),
			),
			'' !== $diverted ? 'email-tested-diverted' : 'email-tested'
		);
	}

	law_events_email_save_override( $slug, $override );

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Notification saved',
			'message'  => sprintf( '%s has been saved.', $definition['name'] ),
			'redirect' => law_emails_dashboard_url(),
		),
		'email-saved'
	);
}

/* Assets ____________________________________________________________________ */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! law_emails_dashboard_is_template() || ! law_user_is_committee() ) {
			return;
		}
		$mtime = function ( $rel ) {
			return filemtime( get_theme_file_path( $rel ) );
		};
		// The shared fetch layer: the editor is a .law-booking-form, so it
		// submits in the background and falls back to a plain POST without it.
		law_modal_enqueue();
		wp_enqueue_script( 'law-booking-form', get_theme_file_uri( 'assets/js/booking-form.js' ), array( 'law-modal' ), $mtime( 'assets/js/booking-form.js' ), true );
	}
);
