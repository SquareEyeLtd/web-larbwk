<?php
/**
 * The committee's "Manage time slots" screen (/account/dashboard/slots/,
 * templates/account-dashboard-slots.php).
 *
 * The slot list used to be editable only as a pipe-separated textarea on
 * Events → Settings, which a member holding only events_committee cannot
 * reach and nobody should have to type into (Denis, 27 October 2026). This is
 * the same list as a table: one row per slot, a label, a date, a start and an
 * optional end, and whether it is retired.
 *
 * The textarea stays (Denis, 27 October 2026), and neither screen owns the
 * data: both write through law_events_save_slots() in settings.php, which
 * sorts the list (date, then start, then end) and refuses to delete, rename or
 * re-time a slot any event holds. On this screen such a row is drawn with its
 * label, date and times read-only and its delete control disabled, so Retired
 * is the one thing that can change on it. Each row posts the label it was
 * drawn with, so a free slot's changed label is a rename rather than a
 * deletion plus an addition.
 *
 * A plain POST and redirect, like the flagship screen and the event form, not
 * the .law-booking-form fetch the single-field committee forms use: a save can
 * fail on several rows at once, and the failure has to come back with every
 * row as typed, every bad field marked and the shared failed-save dialog open
 * (law_events_form_error_modal()), which the redirect and a one-shot transient
 * already do.
 *
 * NOT LOGGED, as on Manage emails: the module's activity log is per-event
 * (law_event_log()), and a change this screen can make reaches no event, since
 * a slot an event holds can only be retired.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template the screen renders through. */
const LAW_SLOTS_DASHBOARD_TEMPLATE = 'templates/account-dashboard-slots.php';

/** Its path, resolved by path everywhere so environment IDs never matter. */
const LAW_SLOTS_DASHBOARD_PATH = 'account/dashboard/slots';

/** The screen's URL. */
function law_slots_dashboard_url() {
	if ( function_exists( 'law_account_url' ) ) {
		$url = law_account_url( 'slots' );
		if ( '' !== $url ) {
			return $url;
		}
	}
	return home_url( '/' . LAW_SLOTS_DASHBOARD_PATH . '/' );
}

/** Whether the current request is rendering this screen. */
function law_slots_dashboard_is_template() {
	return is_page_template( LAW_SLOTS_DASHBOARD_TEMPLATE );
}

/**
 * What a refused save left behind, read once: the errors and the rows as
 * typed.
 *
 * @return array{errors:array<string,string[]>,input:array}
 */
function law_slots_dashboard_state() {
	$key   = 'law_slots_state_' . get_current_user_id();
	$state = get_transient( $key );
	if ( ! is_array( $state ) ) {
		return array( 'errors' => array(), 'input' => array() );
	}
	delete_transient( $key );

	return array(
		'errors' => (array) ( $state['errors'] ?? array() ),
		'input'  => (array) ( $state['input'] ?? array() ),
	);
}

/** Store the state for law_slots_dashboard_state(). */
function law_slots_dashboard_store_state( array $state ) {
	set_transient( 'law_slots_state_' . get_current_user_id(), $state, 10 * MINUTE_IN_SECONDS );
}

/**
 * The rows the table draws: the stored list, or what a refused save typed.
 *
 * Each row carries the label it was drawn with as `original` and how many
 * events hold it, which decides whether it is locked. A held row is drawn with
 * its stored label, date and times whatever a refused save typed into it,
 * because those fields are read-only and the saver has just refused to change
 * them.
 *
 * @param array $input law_slots_dashboard_state()['input'].
 * @return array<int,array<string,mixed>>
 */
function law_slots_dashboard_rows( array $input = array() ) {
	$usage = law_events_slot_usage();
	$rows  = array();

	if ( $input ) {
		foreach ( $input as $row ) {
			$rows[] = array_merge(
				array( 'index' => count( $rows ), 'label' => '', 'date' => '', 'start' => '', 'end' => '', 'retired' => false, 'original' => '' ),
				array_intersect_key( (array) $row, array_flip( array( 'index', 'label', 'date', 'start', 'end', 'retired', 'original' ) ) )
			);
		}
	} else {
		foreach ( array_values( law_events_slots( true ) ) as $i => $slot ) {
			$rows[] = array_merge( $slot, array( 'index' => $i, 'original' => $slot['label'] ) );
		}
	}

	$stored = array();
	foreach ( law_events_slots( true ) as $slot ) {
		$stored[ law_events_slot_label_key( $slot['label'] ) ] = $slot;
	}

	foreach ( $rows as $i => $row ) {
		// Counted against the label the row was drawn with: that is the slot
		// the events hold, whatever the row has since been retyped to.
		$held = '' !== $row['original'] ? $row['original'] : $row['label'];
		$key  = law_events_slot_label_key( $held );

		$rows[ $i ]['confirmed'] = count( $usage[ $key ]['confirmed'] ?? array() );
		$rows[ $i ]['preferred'] = count( $usage[ $key ]['preferred'] ?? array() );
		$rows[ $i ]['held']      = '' !== $row['original'] && isset( $stored[ $key ] ) ? law_events_slot_usage_count( $usage, $held ) : 0;

		if ( $rows[ $i ]['held'] ) {
			foreach ( array( 'label', 'date', 'start', 'end' ) as $field ) {
				$rows[ $i ][ $field ] = $stored[ $key ][ $field ];
			}
			$rows[ $i ]['original'] = $stored[ $key ]['label'];
		}
	}

	return $rows;
}

/* The save handler __________________________________________________________ */

add_action( 'admin_post_law_slots_manage', 'law_slots_manage_handler' );
add_action( 'admin_post_nopriv_law_slots_manage', 'law_events_nopriv_json' );

function law_slots_manage_handler() {
	// Its own rate surface: working through a list of slots is several saves,
	// and must not spend the event-submission budget.
	$is_ajax = law_events_guard_post(
		'law_slots_manage',
		array(
			'rate'            => array( 'slots_manage', 30, 600 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'slots-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, managing the time slots is for the committee.', 'status' => 403 ),
			'slots-denied'
		);
	}

	$rows = law_slots_dashboard_rows_from_post();
	// Never null here: a post without the fingerprint is refused as stale
	// rather than let through unchecked.
	$version = wp_unslash( $_POST['law_slots_version'] ?? '' );
	$version = is_scalar( $version ) ? sanitize_key( (string) $version ) : '';
	$result  = law_events_save_slots( $rows, $version );

	if ( is_wp_error( $result ) ) {
		$data = (array) $result->get_error_data();
		// A stale page comes back as the CURRENT list, not as what was typed:
		// saving the typed rows again would put back whatever somebody else
		// has just changed.
		$stale = 'law_slots_stale' === $result->get_error_code();
		law_slots_dashboard_store_state(
			array(
				'errors' => $stale ? array( 'locked' => array( $result->get_error_message() ) ) : (array) ( $data['errors'] ?? array() ),
				'input'  => $stale ? array() : array_merge( $rows, (array) ( $data['restored'] ?? array() ) ),
			)
		);
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_safe_redirect( add_query_arg( 'law_notice', $stale ? 'slots-stale' : 'slots-invalid', law_slots_dashboard_url() ) );
		exit;
	}

	if ( $is_ajax ) {
		wp_send_json_success( array( 'message' => 'Time slots saved.' ) );
	}
	wp_safe_redirect( add_query_arg( 'law_notice', 'slots-saved', law_slots_dashboard_url() ) );
	exit;
}

/** The posted rows, in the order they were on screen. */
function law_slots_dashboard_rows_from_post() {
	$posted = wp_unslash( $_POST['slots'] ?? array() );
	return law_events_slots_from_input( is_array( $posted ) ? $posted : array() );
}

/* Assets ____________________________________________________________________ */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! law_slots_dashboard_is_template() || ! law_user_is_committee() ) {
			return;
		}
		// The failed-save dialog. The repeater itself is event-form.js, which
		// submission-form.php loads on every committee dashboard.
		law_modal_enqueue();
	}
);
