<?php
/**
 * The committee's Discount codes catalogue (/account/dashboard/discounts/,
 * templates/account-dashboard-discounts.php).
 *
 * Front end rather than wp-admin (Denis, 10 September 2026), for the same
 * reason the events queue, Bookings, Manage speakers and Manage flagship are:
 * a member holding only events_committee should not have to learn wp-admin.
 *
 * Nothing honours a code yet — see the note at the top of discounts.php — so
 * this screen says so rather than implying the codes are live.
 *
 * Module convention: this domain file owns its save handler, its export
 * endpoint and its asset gating. The rules live in discounts.php, which knows
 * nothing about this screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template the catalogue renders through. */
const LAW_DISCOUNTS_DASHBOARD_TEMPLATE = 'templates/account-dashboard-discounts.php';

/** The catalogue URL, optionally addressing one code (or 'new'). */
function law_discounts_dashboard_url( $discount = 0 ) {
	$url = function_exists( 'law_account_url' )
		? law_account_url( 'discounts' )
		: home_url( '/account/dashboard/discounts/' );

	if ( 'new' === $discount ) {
		return add_query_arg( 'law_discount', 'new', $url );
	}
	return $discount ? add_query_arg( 'law_discount', (int) $discount, $url ) : $url;
}

/**
 * Which view the request is asking for.
 *
 * @return int|string 0 for the list, 'new' for the create form, else the ID.
 */
function law_discounts_dashboard_requested() {
	$asked = sanitize_text_field( wp_unslash( (string) ( $_GET['law_discount'] ?? '' ) ) );
	if ( 'new' === $asked ) {
		return 'new';
	}
	$id = absint( $asked );
	if ( ! $id ) {
		return 0;
	}
	$post = get_post( $id );

	return ( $post && LAW_DISCOUNT_CPT === $post->post_type ) ? (int) $post->ID : 0;
}

/**
 * Transient-backed re-population of a refused save, the pattern the speakers
 * and flagship dashboards use: written by the handler, read ONCE by the view
 * and deleted, so a later visit is not haunted by an old failure.
 *
 * @return array{errors:array<string,string>,input:array}
 */
function law_discounts_dashboard_state() {
	$key   = 'law_discount_state_' . get_current_user_id();
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

/** Store a refused save for one read. */
function law_discounts_dashboard_store_state( array $errors, array $input ) {
	set_transient(
		'law_discount_state_' . get_current_user_id(),
		array( 'errors' => $errors, 'input' => $input ),
		5 * MINUTE_IN_SECONDS
	);
}

/** The rows the table and the exports both read. */
function law_discounts_dashboard_rows() {
	$rows  = array();
	$scope = law_discount_scope_events();

	foreach ( law_discounts_all() as $post ) {
		$data = law_discount_data( $post );
		if ( ! $data ) {
			continue;
		}
		$names = array();
		foreach ( $data['events'] as $event_id ) {
			$names[] = $scope[ $event_id ] ?? (string) get_post_field( 'post_title', $event_id );
		}
		$rows[] = $data + array(
			'summary'    => law_discount_summary( $data ),
			'scope'      => $names ? implode( ', ', array_filter( $names ) ) : 'Any priced booking',
			'uses_label' => $data['max_uses'] > 0
				? sprintf( '%d of %d', $data['used'], $data['max_uses'] )
				: sprintf( '%d (no limit)', $data['used'] ),
			'window'     => law_discounts_dashboard_window_label( $data ),
		);
	}

	return $rows;
}

/** "Always", "From 1 Oct 2026", "Until 17 Oct 2026", or a range. */
function law_discounts_dashboard_window_label( array $data ) {
	$from = law_discount_stamp_ts( $data['starts'] );
	$to   = law_discount_stamp_ts( $data['expires'] );
	if ( ! $from && ! $to ) {
		return 'Always';
	}
	if ( $from && ! $to ) {
		return 'From ' . wp_date( 'j M Y, H:i', $from );
	}
	if ( ! $from && $to ) {
		return 'Until ' . wp_date( 'j M Y, H:i', $to );
	}

	return wp_date( 'j M Y, H:i', $from ) . ' to ' . wp_date( 'j M Y, H:i', $to );
}

/** Columns, rows and title for the export trio. */
function law_discounts_dashboard_export_rows() {
	$rows = array();
	foreach ( law_discounts_dashboard_rows() as $row ) {
		$rows[] = array(
			$row['code'],
			$row['active'] ? 'Active' : 'Disabled',
			$row['summary'],
			$row['window'],
			(string) $row['used'],
			$row['max_uses'] > 0 ? (string) $row['max_uses'] : 'No limit',
			$row['scope'],
			$row['note'],
		);
	}

	return array(
		'columns' => array( 'Code', 'Status', 'Discount', 'Valid', 'Used', 'Limit', 'Applies to', 'Note' ),
		'rows'    => $rows,
		'title'   => 'Discount codes, ' . wp_date( 'j F Y' ),
	);
}

/* The save handler ___________________________________________________________ */

add_action( 'admin_post_law_discount_manage', 'law_discount_manage_handler' );
add_action( 'admin_post_nopriv_law_discount_manage', 'law_events_nopriv_json' );

function law_discount_manage_handler() {
	// Its own rate surface: a committee member setting up a batch of codes
	// must not spend the event-submission budget.
	$is_ajax = law_events_guard_post(
		'law_discount_manage',
		array(
			'rate'            => array( 'discount_manage', 60, 600, 300 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'discount-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, managing discount codes is for the committee.', 'status' => 403 ),
			'discount-denied'
		);
	}

	$input  = law_discount_input_from_post();
	$result = law_discount_save( $input, get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		$errors = array();
		foreach ( $result->get_error_codes() as $code ) {
			$errors[ $code ] = $result->get_error_message( $code );
		}
		// A redirect would throw away everything typed, so the errors and the
		// input ride a one-shot transient, as the other dashboards do.
		law_discounts_dashboard_store_state( $errors, $input );
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => implode( ' ', $errors ), 'field' => key( $errors ), 'status' => 400 ),
			'discount-failed'
		);
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Discount code saved',
			'message'  => sprintf( 'The code %s has been saved.', $input['code'] ),
			'redirect' => law_discounts_dashboard_url(),
		),
		'discount-saved'
	);
}

add_action( 'admin_post_law_discount_toggle', 'law_discount_toggle_handler' );
add_action( 'admin_post_nopriv_law_discount_toggle', 'law_events_nopriv_json' );

/**
 * Enable or disable one code from the list, without opening the editor.
 *
 * Disabling rather than deleting is the only route offered: a code that has
 * been used is part of the record of what somebody was charged, and deleting
 * it would strand every booking that names it.
 */
function law_discount_toggle_handler() {
	$is_ajax = law_events_guard_post(
		'law_discount_toggle',
		array(
			'rate'            => array( 'discount_manage', 60, 600, 300 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'discount-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, managing discount codes is for the committee.', 'status' => 403 ),
			'discount-denied'
		);
	}

	$id   = absint( $_POST['law_discount_id'] ?? 0 );
	$post = $id ? get_post( $id ) : null;
	if ( ! $post || LAW_DISCOUNT_CPT !== $post->post_type ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'That discount code could not be found.', 'status' => 404 ), 'discount-failed' );
	}

	$before = law_discount_data( $post );
	$active = 'publish' === $post->post_status;
	wp_update_post( array( 'ID' => (int) $post->ID, 'post_status' => $active ? 'draft' : 'publish' ) );
	law_discount_log_save( (int) $post->ID, $before, law_discount_data( (int) $post->ID ), get_current_user_id() );

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => $active ? 'Discount code disabled' : 'Discount code enabled',
			'message'  => $active
				? sprintf( '%s can no longer be used.', $before['code'] )
				: sprintf( '%s can be used again.', $before['code'] ),
			'redirect' => law_discounts_dashboard_url(),
		),
		$active ? 'discount-disabled' : 'discount-enabled'
	);
}

/* The export _________________________________________________________________ */

add_action( 'admin_post_law_discounts_dashboard_export', 'law_discounts_dashboard_export_handler' );
function law_discounts_dashboard_export_handler() {
	nocache_headers();
	$format = sanitize_key( $_GET['format'] ?? 'csv' );

	if ( 'json' === $format && ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'law_discounts_dashboard_export' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_discounts_dashboard_export' );

	if ( ! law_user_is_committee() ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Sorry, this export is for the committee.' ), 403 );
		}
		wp_die( 'Sorry, this export is for the committee.' );
	}

	if ( ! law_events_rate_limit_ok( 'discounts_export', get_current_user_id(), 40, 600 ) ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Too many exports in a short time; please wait a moment and try again.' ), 429 );
		}
		wp_die( 'Too many exports in a short time; please wait a moment and try again.', '', array( 'response' => 429 ) );
	}

	$data     = law_discounts_dashboard_export_rows();
	$basename = 'discount-codes-' . gmdate( 'Ymd-His' );

	if ( 'xlsx' === $format ) {
		law_events_send_xlsx( $data['columns'], $data['rows'], $basename . '.xlsx', $data['title'] );
	}
	if ( 'json' === $format ) {
		wp_send_json_success(
			array(
				'title'    => $data['title'],
				'filename' => $basename . '.pdf',
				'columns'  => $data['columns'],
				'rows'     => $data['rows'],
			)
		);
	}
	law_events_send_csv( $data['columns'], $data['rows'], $basename . '.csv', $data['title'] );
}

/* Assets _____________________________________________________________________ */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_page_template( LAW_DISCOUNTS_DASHBOARD_TEMPLATE ) || ! law_user_is_committee() ) {
			return;
		}
		$mtime = function ( $rel ) {
			return filemtime( get_theme_file_path( $rel ) );
		};
		// The shared fetch layer: the editor and the per-row enable/disable
		// buttons are .law-booking-form, so they submit in the background and
		// fall back to a plain POST without it.
		law_modal_enqueue();
		wp_enqueue_script( 'law-booking-form', get_theme_file_uri( 'assets/js/booking-form.js' ), array( 'law-modal' ), $mtime( 'assets/js/booking-form.js' ), true );
		wp_enqueue_script( 'law-pdfmake', get_theme_file_uri( 'assets/js/vendor/pdfmake.min.js' ), array(), $mtime( 'assets/js/vendor/pdfmake.min.js' ), true );
		wp_enqueue_script( 'law-pdfmake-fonts', get_theme_file_uri( 'assets/js/vendor/vfs_fonts.js' ), array( 'law-pdfmake' ), $mtime( 'assets/js/vendor/vfs_fonts.js' ), true );
		wp_enqueue_script( 'law-export-buttons', get_theme_file_uri( 'assets/js/export-buttons.js' ), array( 'law-pdfmake-fonts' ), $mtime( 'assets/js/export-buttons.js' ), true );
	}
);
