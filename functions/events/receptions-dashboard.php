<?php
/**
 * The committee's front-end "Manage receptions" screen
 * (/account/dashboard/receptions/, RECEPTIONS.md §8.1).
 *
 * The fifth committee dashboard, and it exists for the same reason the others
 * do: the committee works from the site, not from wp-admin, and a member who
 * only holds the events_committee role should not have to learn two interfaces.
 *
 * List plus edit, on the Manage speakers pattern (?law_reception=<id|new>). It
 * edits the SAME posts, through the SAME code, as the wp-admin Reception box:
 * reading a submission, validating it and writing it are
 * functions/events/receptions.php, shared with admin/event-screen.php. This
 * file is the route, the capability gate, the POST handler, the export and the
 * assets.
 *
 * Access is committee, editor or administrator (law_user_is_committee()),
 * checked in three independent places, because each can be reached on its own:
 * the header link (functions/header-nav.php), the template
 * (templates/account-dashboard-receptions.php) and this file's save handler.
 * The page also carries the Members restriction its sibling dashboards do; the
 * setup helper copies the parent's.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template, and the path the header link and the setup helpers use. */
const LAW_RECEPTIONS_DASHBOARD_TEMPLATE = 'templates/account-dashboard-receptions.php';
const LAW_RECEPTIONS_DASHBOARD_PATH     = 'account/dashboard/receptions';

/**
 * The dashboard's URL, optionally addressing one reception (or 'new').
 *
 * Resolved by path, like every other account page, so a page created with a
 * different ID on another environment still works.
 */
function law_receptions_dashboard_url( $reception = 0 ) {
	$url = function_exists( 'law_account_url' ) ? law_account_url( 'receptions' ) : '';
	if ( '' === $url ) {
		$url = home_url( '/' . LAW_RECEPTIONS_DASHBOARD_PATH . '/' );
	}

	if ( 'new' === $reception ) {
		return add_query_arg( 'law_reception', 'new', $url );
	}

	return $reception ? add_query_arg( 'law_reception', (int) $reception, $url ) : $url;
}

/** True on the dashboard page. */
function law_receptions_dashboard_is_template() {
	return is_page_template( LAW_RECEPTIONS_DASHBOARD_TEMPLATE );
}

/**
 * Which view the request is asking for.
 *
 * @return int|string 0 for the list, 'new' for the create form, else the ID.
 */
function law_receptions_dashboard_requested() {
	$asked = sanitize_text_field( wp_unslash( (string) ( $_GET['law_reception'] ?? '' ) ) );
	if ( 'new' === $asked ) {
		return 'new';
	}
	$id = absint( $asked );

	return ( $id && law_reception_is( $id ) ) ? $id : 0;
}

/**
 * Transient-backed re-population of a refused save, the pattern the speakers,
 * flagship and discounts dashboards use: written by the handler, read ONCE by
 * the view and deleted, so a later visit is not haunted by an old failure.
 *
 * @return array{errors:array<string,string>,input:array}
 */
function law_receptions_dashboard_state() {
	$key   = 'law_reception_state_' . get_current_user_id();
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
function law_receptions_dashboard_store_state( array $errors, array $input ) {
	set_transient(
		'law_reception_state_' . get_current_user_id(),
		array( 'errors' => $errors, 'input' => $input ),
		10 * MINUTE_IN_SECONDS
	);
}

/** The notices this screen can show, in the shared .law-form-notice shape. */
function law_receptions_dashboard_notices() {
	return array(
		'reception-saved'   => array( 'is-success', __( 'The reception has been saved.', 'law' ) ),
		'reception-invalid' => array( 'is-error', __( 'The reception was not saved. Please check the fields below.', 'law' ) ),
		'reception-denied'  => array( 'is-error', __( 'Sorry, managing the receptions is for the committee.', 'law' ) ),
		'rate-limited'      => array( 'is-error', __( 'Too many changes in a short time; please wait a moment and try again.', 'law' ) ),
	);
}

/* The rows the table and the exports both read ______________________________ */

/**
 * One flat row per reception: what the committee needs to see at a glance,
 * and the same figures the exports carry.
 */
function law_receptions_dashboard_rows() {
	$rows = array();
	foreach ( law_reception_ids() as $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post ) {
			continue;
		}
		$price     = (int) law_event_meta( $event_id, '_law_attendee_price_pence' );
		$available = (int) law_event_meta( $event_id, '_law_tickets_available' );
		$remaining = law_event_tickets_remaining( $event_id );

		$rows[] = array(
			'id'         => $event_id,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'shown'      => 'publish' === $post->post_status,
			'when'       => law_reception_when_label( $event_id ),
			'venue'      => (string) law_event_meta( $event_id, '_law_venue' ),
			'available'  => $available,
			'confirmed'  => law_event_attendee_total( $event_id ) - law_booking_pending_payment_count( $event_id ),
			'pending'    => law_booking_pending_payment_count( $event_id ),
			'remaining'  => null === $remaining ? null : (int) $remaining,
			'price'      => $price,
			'price_label' => $price > 0 ? law_events_price_label( $price ) : __( 'Not on sale', 'law' ),
			'included'   => (bool) law_event_meta( $event_id, '_law_flagship_included' ),
			'invitation' => law_event_is_invitation_only( $event_id ),
			'edit_url'   => law_receptions_dashboard_url( $event_id ),
			'view_url'   => get_permalink( $event_id ),
		);
	}

	return $rows;
}

/** Columns, rows and title for the export trio. */
function law_receptions_dashboard_export_rows() {
	$rows = array();
	foreach ( law_receptions_dashboard_rows() as $row ) {
		$rows[] = array(
			$row['title'],
			law_event_status_label( get_post( $row['id'] ) ),
			$row['when'],
			$row['venue'],
			$row['available'] > 0 ? (string) $row['available'] : '',
			(string) $row['confirmed'],
			(string) $row['pending'],
			null === $row['remaining'] ? '' : (string) $row['remaining'],
			$row['price'] > 0 ? law_events_format_pence( $row['price'] ) : 'Not on sale',
			$row['included'] ? 'Yes' : '',
			$row['invitation'] ? 'Yes' : '',
		);
	}

	return array(
		'columns' => array(
			'Reception',
			'Status',
			'Day and time',
			'Venue',
			'Places available',
			'Confirmed',
			'Awaiting payment',
			'Places left',
			'Price excluding VAT',
			'Included with flagship',
			'Invitation only',
		),
		'rows'    => $rows,
		'title'   => 'Receptions, ' . wp_date( 'j F Y' ),
	);
}

/* The save handler __________________________________________________________ */

add_action( 'admin_post_law_reception_manage', 'law_reception_manage_handler' );
add_action( 'admin_post_nopriv_law_reception_manage', 'law_events_nopriv_json' );

/**
 * Read the POST, hand it to the shared saver, redirect with a notice.
 *
 * Its own rate surface: editing three receptions across a committee meeting is
 * many saves, and it must not spend the event-submission budget.
 */
function law_reception_manage_handler() {
	$is_ajax = law_events_guard_post(
		'law_reception_manage',
		array(
			'rate'            => array( 'reception_manage', 30, 600 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'reception-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, managing the receptions is for the committee.', 'status' => 403 ),
			'reception-denied'
		);
	}

	$input = law_reception_input_from_post();

	// A posted event_id that is not a reception is refused rather than
	// silently creating a new one: a forged or stale ID must reach nothing.
	// A blank one means "create", which is how the Add a reception form works.
	if ( ! empty( $input['event_id'] ) && ! law_reception_is( (int) $input['event_id'] ) ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'That reception could not be found.', 'status' => 404 ),
			'reception-invalid'
		);
	}

	$result = law_reception_save( $input, get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		$errors = array();
		foreach ( $result->get_error_codes() as $code ) {
			$errors[ $code ] = $result->get_error_message( $code );
		}
		law_receptions_dashboard_store_state( $errors, $input );
		law_events_respond(
			$is_ajax,
			false,
			array(
				'message'  => implode( ' ', $errors ),
				'field'    => key( $errors ),
				'status'   => 400,
				'redirect' => law_receptions_dashboard_url( $input['event_id'] ?: 'new' ),
			),
			'reception-invalid'
		);
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Reception saved',
			'message'  => sprintf( '%s has been saved.', get_the_title( (int) $result ) ),
			'redirect' => add_query_arg( 'law_notice', 'reception-saved', law_receptions_dashboard_url() ),
		),
		'reception-saved'
	);
}

/* The export ________________________________________________________________ */

add_action( 'admin_post_law_receptions_dashboard_export', 'law_receptions_dashboard_export_handler' );
function law_receptions_dashboard_export_handler() {
	nocache_headers();
	$format = sanitize_key( $_GET['format'] ?? 'csv' );

	// check_admin_referer() would die with an HTML page the PDF fetch cannot
	// parse, so the json branch verifies the nonce itself and answers JSON.
	if ( 'json' === $format && ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'law_receptions_dashboard_export' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_receptions_dashboard_export' );

	if ( ! law_user_is_committee() ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Sorry, this export is for the committee.' ), 403 );
		}
		wp_die( 'Sorry, this export is for the committee.' );
	}

	if ( ! law_events_rate_limit_ok( 'receptions_export', get_current_user_id(), 40, 600 ) ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Too many exports in a short time; please wait a moment and try again.' ), 429 );
		}
		wp_die( 'Too many exports in a short time; please wait a moment and try again.', '', array( 'response' => 429 ) );
	}

	$data     = law_receptions_dashboard_export_rows();
	$basename = 'receptions-' . gmdate( 'Ymd-His' );

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

/* Assets ____________________________________________________________________ */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! law_receptions_dashboard_is_template() || ! law_user_is_committee() ) {
			return;
		}

		$mtime = function ( $rel ) {
			$path = get_theme_file_path( $rel );
			return file_exists( $path ) ? filemtime( $path ) : '1.0';
		};

		// The description is the same rich-text field the flagship screen uses,
		// so it comes from the same place rather than a second implementation.
		law_rich_text_enqueue();
		wp_enqueue_style( 'law-events-admin', get_theme_file_uri( 'assets/css/law-admin.css' ), array(), '1.6' );
		// The flagship dashboard's stylesheet, scoped by that screen's own
		// class, which this screen also carries: the two are the same form
		// vocabulary, and a second near-identical stylesheet would drift.
		wp_enqueue_style( 'law-flagship-dashboard', get_theme_file_uri( 'assets/css/flagship-dashboard.css' ), array( 'law-events-admin' ), $mtime( 'assets/css/flagship-dashboard.css' ) );

		// The shared fetch layer: the edit form is a .law-booking-form, so it
		// submits in the background and falls back to a plain POST without it.
		law_modal_enqueue();
		wp_enqueue_script( 'law-booking-form', get_theme_file_uri( 'assets/js/booking-form.js' ), array( 'law-modal' ), $mtime( 'assets/js/booking-form.js' ), true );

		// The export trio, list view only (pdfmake is ~3MB, footer-loaded).
		if ( law_receptions_dashboard_requested() ) {
			return;
		}
		wp_enqueue_script( 'law-pdfmake', get_theme_file_uri( 'assets/js/vendor/pdfmake.min.js' ), array(), $mtime( 'assets/js/vendor/pdfmake.min.js' ), true );
		wp_enqueue_script( 'law-pdfmake-fonts', get_theme_file_uri( 'assets/js/vendor/vfs_fonts.js' ), array( 'law-pdfmake' ), $mtime( 'assets/js/vendor/vfs_fonts.js' ), true );
		wp_enqueue_script( 'law-export-buttons', get_theme_file_uri( 'assets/js/export-buttons.js' ), array( 'law-pdfmake-fonts' ), $mtime( 'assets/js/export-buttons.js' ), true );
	}
);
