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
 * file is the route, the capability gate, the POST handler and the assets.
 *
 * NO export, unlike every sibling dashboard (Denis, 14 September 2026): there
 * are three receptions and every figure is on the screen already. The BOOKINGS
 * at a reception do export, from Manage bookings and from the per-event list,
 * which is where somebody wanting a spreadsheet of people actually goes.
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
	// The per-event confirmation editor returns a reception to THIS screen,
	// because law_committee_event_url() calls it a reception's home, so its
	// notices have to be readable here too (email-override.php).
	) + law_event_override_notices();
}

/* The rows the table reads ___________________________________________________ */

/**
 * One flat row per reception: what the committee needs to see at a glance.
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
			// The bare figure the committee typed, which is the NET, and the
			// same number the event page and the cards quote (Denis, 14
			// September 2026). The VAT arithmetic belongs in the checkout
			// dialog next to the consent to pay it; spelling it out in a table
			// cell made the column three lines tall and told the committee
			// nothing it had not just entered.
			'price_label' => $price > 0 ? law_events_format_pence( $price ) : __( 'Not on sale', 'law' ),
			'included'   => (bool) law_event_meta( $event_id, '_law_flagship_included' ),
			'invitation' => law_event_is_invitation_only( $event_id ),
			'edit_url'   => law_receptions_dashboard_url( $event_id ),
			'view_url'   => get_permalink( $event_id ),
		);
	}

	return $rows;
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

	// The confirmation override rides alongside the saver rather than inside it:
	// it is a plain event flag, and threading it through law_reception_save()
	// would mean widening that function's snapshot/diff contract for a field it
	// knows nothing about. Written only for an existing reception, and only when
	// the form actually carried the fieldset, so a partial POST cannot clear it.
	if ( ! is_wp_error( $result ) && ! empty( $_POST['law_reception']['email_override_present'] ) ) {
		$law_ro_event = (int) $result;
		if ( law_event_override_slug_map( $law_ro_event ) ) {
			$law_ro_before = array( '_law_email_override' => (int) law_event_meta( $law_ro_event, '_law_email_override' ) );
			law_event_update_meta( $law_ro_event, '_law_email_override', ! empty( $_POST['law_reception']['email_override'] ) );
			law_event_log_flag_change( $law_ro_event, $law_ro_before, get_current_user_id() );
		}
	}

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

		// Nothing else: the form's whole vocabulary — .law-row-grid,
		// .law-form-field, .law-form-hint, .law-form-buttons — is
		// event-form.css, which submission-form.php enqueues for this template,
		// and the list's table is calendar.css, which enqueue.php does. This
		// screen borrowed law-admin.css and flagship-dashboard.css while it
		// looked like the flagship's dashboard; it looks like the submission
		// form now (Denis, 14 September 2026) and needs neither.
		//
		// The shared fetch layer: the edit form is a .law-booking-form, so it
		// submits in the background and falls back to a plain POST without it.
		// No pdfmake and no export-buttons.js either, because this screen has
		// no export and must not serve ~3MB of PDF library to show three rows.
		law_modal_enqueue();
		wp_enqueue_script( 'law-booking-form', get_theme_file_uri( 'assets/js/booking-form.js' ), array( 'law-modal' ), $mtime( 'assets/js/booking-form.js' ), true );
	}
);
