<?php
/**
 * The committee's Flagship bookings dashboard
 * (/account/dashboard/flagship-bookings/,
 * templates/account-dashboard-flagship-bookings.php).
 *
 * A separate page from Manage bookings, on purpose (Denis, 10 September
 * 2026): hosted-event bookings and paid places are different things. A hosted
 * booking is free, instant and reversible; a flagship application is a
 * request with money attached, reviewed one at a time, that can be approved,
 * declined, charged, refused by a bank and chased. Putting the two in one
 * table would mean a dozen columns that are blank for most rows and two sets
 * of actions that do not apply to each other.
 *
 * Manage bookings therefore EXCLUDES the flagship, and every route into a
 * flagship booking comes here, so mutations have exactly one home.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template the dashboard renders through. */
const LAW_FLAGSHIP_BOOKINGS_TEMPLATE = 'templates/account-dashboard-flagship-bookings.php';

/** The dashboard URL. */
function law_flagship_bookings_url() {
	return function_exists( 'law_account_url' )
		? law_account_url( 'flagship_bookings' )
		: home_url( '/account/dashboard/flagship-bookings/' );
}

/**
 * The DOM id of the "add an attendee without payment" dialog.
 *
 * The opener sits in the actions row under the table
 * (parts/events/flagship-bookings-list.php) and the dialog is rendered after
 * it (parts/events/flagship-add-attendee.php), because the opener belongs
 * beside the bulk buttons and the dialog does not belong inside the bulk
 * form. Two partials, one id, so neither can drift.
 */
function law_flagship_add_attendee_modal_id() {
	return 'law-flagship-add';
}

/** Is this the dashboard? */
function law_flagship_bookings_is_template() {
	return is_page_template( LAW_FLAGSHIP_BOOKINGS_TEMPLATE );
}

/**
 * The filters, from the request or an explicit array (the export and tests
 * pass their own).
 *
 * @return array{kw:string,status:string,payment:string,complimentary:bool}
 */
function law_flagship_bookings_filters( ?array $source = null ) {
	$source = null === $source ? $_GET : $source;
	$status = sanitize_text_field( wp_unslash( (string) ( $source['law_status'] ?? '' ) ) );

	return array(
		'kw'            => sanitize_text_field( wp_unslash( (string) ( $source['law_kw'] ?? '' ) ) ),
		'status'        => array_key_exists( $status, law_flagship_application_statuses() ) ? $status : '',
		'payment'       => sanitize_key( (string) ( $source['law_payment'] ?? '' ) ),
		'complimentary' => ! empty( $source['law_comp'] ),
	);
}

/** The payment states the filter offers, in the order they happen. */
function law_flagship_payment_states() {
	return array(
		'pending_setup'   => 'Awaiting payment details',
		'ready'           => 'Payment method saved, awaiting review',
		'processing'      => 'Payment in progress',
		'paid'            => 'Paid',
		'failed'          => 'Payment failed',
		'action_required' => 'Awaiting the delegate\'s bank',
		'refunded'        => 'Refunded',
		'complimentary'   => 'No charge',
	);
}

/**
 * One flat row per application: everything the committee needs to decide,
 * plus the Stripe references that make a refund findable (spec §7.5).
 */
function law_flagship_bookings_rows( array $filters ) {
	$posts = law_flagship_applications( $filters );
	if ( ! $posts ) {
		return array( 'rows' => array(), 'counts' => law_flagship_places() );
	}

	// One pass for the profiles the rows read live (country, dietary,
	// accessibility), rather than a query per row.
	cache_users( array_map( fn( $p ) => (int) $p->post_author, $posts ) );

	$rows = array();
	foreach ( $posts as $post ) {
		$booking_id = (int) $post->ID;
		$person     = law_booking_attendee( $booking_id );
		$profile    = law_profile_values( (int) $post->post_author );
		$price      = law_booking_price( $booking_id );
		$state      = (string) law_event_meta( $booking_id, '_law_payment_status' );

		$rows[] = array(
			'id'            => $booking_id,
			'number'        => (int) law_event_meta( $booking_id, '_law_booking_number' ),
			'status'        => $post->post_status,
			'status_label'  => law_booking_status_label( $post ),
			'name'          => $person['name'],
			'email'         => $person['email'],
			'organisation'  => $person['organisation'],
			'job_title'     => $person['job_title'],
			'country'       => (string) ( $profile['country'] ?? '' ),
			'dietary'       => law_booking_profile_requirements( $profile, 'dietary' ),
			'accessibility' => law_booking_profile_requirements( $profile, 'accessibility' ),
			'press'         => (bool) law_event_meta( $booking_id, '_law_is_press' ),
			'complimentary' => (bool) law_event_meta( $booking_id, '_law_is_complimentary' ),
			'net_pence'     => $price['net'],
			'gross_pence'   => $price['gross'],
			'payment'       => $state,
			'payment_label' => law_flagship_payment_states()[ $state ] ?? 'Awaiting payment details',
			'payment_error' => (string) law_event_meta( $booking_id, '_law_payment_error' ),
			'card'          => law_booking_payment_method_label( $booking_id ),
			'invoice_url'   => (string) law_event_meta( $booking_id, '_law_stripe_invoice_url' ),
			'applied'       => (string) law_event_meta( $booking_id, '_law_application_at' ),
			'decided'       => (string) law_event_meta( $booking_id, '_law_reviewed_at' ),
			'reason'        => (string) law_event_meta( $booking_id, '_law_decline_reason' ),
			// A decision is only offered where one can actually be made:
			// not before a payment method is saved, and not while a charge
			// the committee already approved is still settling.
			'decidable'     => in_array( $post->post_status, array( 'law-applied', 'law-payment-failed' ), true )
				&& ! in_array( $state, array( 'pending_setup', 'processing' ), true ),
			'retryable'     => 'law-payment-failed' === $post->post_status,
		);
	}

	return array( 'rows' => $rows, 'counts' => law_flagship_places() );
}

/** Columns, rows and title for the export trio. */
function law_flagship_bookings_export_rows( array $filters ) {
	$columns = array(
		'Application',
		'Status',
		'First name',
		'Second name',
		'Email',
		'Organisation',
		'Job title',
		'Country',
		'Press',
		'Complimentary',
		'Amount charged',
		'Payment',
		'Stripe invoice',
		'Applied',
		'Decided',
		'Accessibility',
		'Dietary',
	);

	$data = law_flagship_bookings_rows( $filters );
	$rows = array();
	foreach ( $data['rows'] as $row ) {
		$parts = explode( ' ', trim( $row['name'] ), 2 );
		$rows[] = array(
			'#' . $row['number'],
			$row['status_label'],
			$parts[0] ?? '',
			$parts[1] ?? '',
			$row['email'],
			$row['organisation'],
			$row['job_title'],
			$row['country'],
			$row['press'] ? 'Yes' : '',
			$row['complimentary'] ? 'Yes' : '',
			law_events_format_pence( $row['gross_pence'] ),
			$row['payment_label'],
			$row['invoice_url'],
			$row['applied'],
			$row['decided'],
			$row['accessibility'],
			$row['dietary'],
		);
	}

	$event_id = law_flagship_event_id();

	return array(
		'columns' => $columns,
		'rows'    => $rows,
		'title'   => sprintf(
			'Flagship applications for %s, %s',
			$event_id ? get_post_field( 'post_title', $event_id ) : 'the flagship conference',
			wp_date( 'j F Y' )
		),
	);
}

/* The AJAX partial ___________________________________________________________ */

add_action( 'template_redirect', 'law_flagship_bookings_maybe_render_partial' );
function law_flagship_bookings_maybe_render_partial() {
	if ( empty( $_GET['law_partial'] ) || ! law_flagship_bookings_is_template() ) {
		return;
	}
	// Re-checked here rather than trusted from the template: the partial is
	// its own request and answers on its own.
	if ( ! law_user_is_committee() ) {
		status_header( 403 );
		exit;
	}
	nocache_headers();
	get_template_part( 'parts/events/flagship-bookings-list' );
	exit;
}

/* The export _________________________________________________________________ */

add_action( 'admin_post_law_flagship_export', 'law_flagship_bookings_export_handler' );
function law_flagship_bookings_export_handler() {
	nocache_headers();
	$format = sanitize_key( $_GET['format'] ?? 'csv' );

	if ( 'json' === $format && ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'law_flagship_export' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_flagship_export' );

	if ( ! law_user_is_committee() ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Sorry, this export is for the committee.' ), 403 );
		}
		wp_die( 'Sorry, this export is for the committee.' );
	}

	// This export carries names, employers and payment states for the whole
	// delegate list, so the budget is real but generous: one visit legitimately
	// spends three requests (CSV, Excel, and the PDF's json fetch).
	if ( ! law_events_rate_limit_ok( 'flagship_export', get_current_user_id(), 40, 600 ) ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Too many exports in a short time; please wait a moment and try again.' ), 429 );
		}
		wp_die( 'Too many exports in a short time; please wait a moment and try again.', '', array( 'response' => 429 ) );
	}

	$data     = law_flagship_bookings_export_rows( law_flagship_bookings_filters() );
	$basename = 'flagship-applications-' . gmdate( 'Ymd-His' );

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

/* Keeping the flagship out of the hosted surfaces ____________________________ */

/**
 * Send the hosted per-event bookings list to the flagship's own page.
 *
 * `law_user_can_manage_event()` is true for the committee on every event,
 * the flagship included, so /account/events/?law_event_bookings={flagship}
 * rendered the HOSTED table for it: hosted vocabulary, no payment context,
 * and a Reject action that cancels a paid, invoiced place without touching
 * the money. Flagship mutations have exactly one home, and this is how they
 * are kept there.
 */
add_action( 'template_redirect', 'law_flagship_redirect_hosted_list' );
function law_flagship_redirect_hosted_list() {
	$asked = absint( $_GET['law_event_bookings'] ?? 0 );
	if ( ! $asked || ! function_exists( 'law_flagship_is' ) || ! law_flagship_is( $asked ) ) {
		return;
	}
	wp_safe_redirect( law_flagship_bookings_url() );
	exit;
}

/* Keeping the flagship out of Manage bookings ________________________________ */

/**
 * Manage bookings is the HOSTED-event view; the flagship has its own page.
 *
 * Filtered rather than edited into bookings-dashboard.php's query, so that
 * file stays about hosted events and this one owns every "the flagship is
 * different" rule.
 */
add_filter( 'law_bookings_dashboard_exclude_events', 'law_flagship_bookings_exclude' );
function law_flagship_bookings_exclude( $ids ) {
	$flagship = function_exists( 'law_flagship_event_id' ) ? law_flagship_event_id() : 0;
	if ( $flagship ) {
		$ids[] = $flagship;
	}

	return $ids;
}

/* Assets _____________________________________________________________________ */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! law_flagship_bookings_is_template() || ! law_user_is_committee() ) {
			return;
		}
		$mtime = function ( $rel ) {
			return filemtime( get_theme_file_path( $rel ) );
		};
		law_modal_enqueue();
		wp_enqueue_script( 'law-booking-form', get_theme_file_uri( 'assets/js/booking-form.js' ), array( 'law-modal' ), $mtime( 'assets/js/booking-form.js' ), true );
		wp_enqueue_script( 'law-pdfmake', get_theme_file_uri( 'assets/js/vendor/pdfmake.min.js' ), array(), $mtime( 'assets/js/vendor/pdfmake.min.js' ), true );
		wp_enqueue_script( 'law-pdfmake-fonts', get_theme_file_uri( 'assets/js/vendor/vfs_fonts.js' ), array( 'law-pdfmake' ), $mtime( 'assets/js/vendor/vfs_fonts.js' ), true );
		wp_enqueue_script( 'law-export-buttons', get_theme_file_uri( 'assets/js/export-buttons.js' ), array( 'law-pdfmake-fonts' ), $mtime( 'assets/js/export-buttons.js' ), true );
	}
);
