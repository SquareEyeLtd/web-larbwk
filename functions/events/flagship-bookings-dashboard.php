<?php
/**
 * The committee's Flagship bookings dashboard
 * (/account/dashboard/flagship-bookings/,
 * templates/account-dashboard-flagship-bookings.php).
 *
 * A separate page from Manage bookings, on purpose (Denis, 10 September
 * 2026): hosted-event bookings and paid places are different things. A hosted
 * booking is free, instant and reversible; a flagship registration is a
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
 * @return array{kw:string,status:string,payment:string,complimentary:bool,ticket:string}
 */
function law_flagship_bookings_filters( ?array $source = null ) {
	$source = null === $source ? $_GET : $source;
	$status = sanitize_text_field( wp_unslash( (string) ( $source['law_status'] ?? '' ) ) );
	$ticket = sanitize_key( (string) ( $source['law_ticket'] ?? '' ) );

	return array(
		'kw'            => sanitize_text_field( wp_unslash( (string) ( $source['law_kw'] ?? '' ) ) ),
		'status'        => array_key_exists( $status, law_flagship_application_statuses() ) ? $status : '',
		'payment'       => sanitize_key( (string) ( $source['law_payment'] ?? '' ) ),
		'complimentary' => ! empty( $source['law_comp'] ),
		// Validated against the registry here, like status, so a hand-typed
		// query string cannot empty the table by filtering on a type that
		// does not exist.
		'ticket'        => array_key_exists( $ticket, law_booking_ticket_types() ) ? $ticket : '',
	);
}

/** The payment states the filter offers, in the order they happen. */
function law_flagship_payment_states() {
	// The shared vocabulary (law_booking_payment_states(), bookings.php), with
	// the three states a REGISTRATION reads differently laid over it: on this
	// screen a saved method means "ready for the committee", and a
	// complimentary place is a decision the committee made rather than a price
	// of zero. One map of states, two sets of words for three of them, rather
	// than two maps that could come to hold different states.
	return array_merge(
		law_booking_payment_states(),
		array(
			'ready'           => 'Payment method saved, pending approval',
			'action_required' => 'Awaiting the delegate\'s bank',
			'complimentary'   => 'No charge',
			// Free because the delegate brought a code, not because the
			// committee gave the place away, and still undecided. The two must
			// read differently on the filter and in the export, or a code
			// redemption looks like a gift LAW made.
			'no_charge'       => 'Covered by a code, pending approval',
		)
	);
}

/**
 * One flat row per registration: everything the committee needs to decide,
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
			// The committee's own classification. Both the slug and its label,
			// because the table and the dialog need the slug and the export
			// needs the words.
			'ticket_type'   => (string) law_event_meta( $booking_id, '_law_ticket_type' ),
			'ticket_label'  => law_booking_ticket_type_label( (string) law_event_meta( $booking_id, '_law_ticket_type' ) ),
			'net_pence'     => $price['net'],
			'gross_pence'   => $price['gross'],
			// What a code took off, and what the place would otherwise have
			// cost. _law_price_pence is already the DISCOUNTED net, so the
			// list price is reconstructed here rather than stored twice.
			'discount_code'  => (string) law_event_meta( $booking_id, '_law_discount_code' ),
			'discount_pence' => (int) law_event_meta( $booking_id, '_law_discount_pence' ),
			'list_pence'     => law_flagship_list_gross( $price, (int) law_event_meta( $booking_id, '_law_discount_pence' ) ),
			'payment'       => $state,
			// A place a code covered settles at 'paid' with a gross of zero,
			// which is the right state (law_flagship_confirm() explains why)
			// and the wrong word on its own: "Paid" against £0.00 reads as a
			// bug until you notice the Code column. Say what happened.
			'payment_label' => ( 'paid' === $state && $price['gross'] < 1 && '' !== (string) law_event_meta( $booking_id, '_law_discount_code' ) )
				? 'Paid in full by discount code'
				: ( law_flagship_payment_states()[ $state ] ?? 'Awaiting payment details' ),
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
			// The other end of the flow: a place that IS confirmed, which the
			// committee can release when the delegate drops out. Never offered
			// alongside Decline, because the two are the same decision taken
			// before and after the money moved.
			'cancellable'   => 'publish' === $post->post_status,
		);
	}

	return array( 'rows' => $rows, 'counts' => law_flagship_places() );
}

/**
 * What one place would have cost without its code, with VAT, in pence.
 *
 * The booking stores the discounted net; the list price is net + discount,
 * grossed up the same way. Only ever for a "was £X" line, so it is computed
 * here rather than snapshotted, which would be a second figure to drift.
 */
function law_flagship_list_gross( array $price, $discount_pence ) {
	$discount_pence = max( 0, (int) $discount_pence );
	if ( $discount_pence < 1 ) {
		return (int) $price['gross'];
	}
	$list_net = (int) $price['net'] + $discount_pence;

	// A place a code covered in full carries _law_vat = 0, because there is no
	// VAT to add to nothing — but the price it WOULD have cost still had VAT on
	// it, and that is the figure this line reports. Both priced flows are
	// VAT-liable, so a free booking that carries a code is grossed up too;
	// reading the flag alone printed "was £550.00" for a ticket whose real list
	// price is £660.00.
	$vatable = ! empty( $price['vatable'] ) || ! empty( $price['free'] );

	return $vatable ? law_events_gross_pence( $list_net ) : $list_net;
}

/** Columns, rows and title for the export trio. */
function law_flagship_bookings_export_rows( array $filters ) {
	$columns = array(
		'Registration',
		'Status',
		'First name',
		'Second name',
		'Email',
		'Organisation',
		'Job title',
		'Country',
		'Press',
		'Complimentary',
		'Ticket type',
		'List price',
		'Discount code',
		'Discount',
		'Amount charged',
		'Payment',
		'Stripe invoice',
		'Registered',
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
			$row['ticket_label'],
			law_events_format_pence( $row['list_pence'] ),
			$row['discount_code'],
			$row['discount_pence'] > 0 ? law_events_format_pence( $row['discount_pence'] ) : '',
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
			'Flagship registrations for %s, %s',
			$event_id ? get_post_field( 'post_title', $event_id ) : 'the flagship conference',
			wp_date( 'j F Y' )
		),
	);
}

/* Ticket type _________________________________________________________________ */

/** The DOM id of the shared "set the ticket type" dialog. */
function law_flagship_ticket_type_modal_id() {
	return 'law-flagship-ticket-type';
}

/**
 * One Ticket type cell's inner markup.
 *
 * ONE renderer, called by the table when the page is drawn and again by
 * law_flagship_ticket_type_handler() when the committee changes a value, so
 * the cell that replaces itself over AJAX cannot look different from the cell
 * the server would have drawn. (The same reasoning as the thread bubble in
 * comments.php: return the rendered partial, swap the node, keep the markup in
 * PHP.)
 *
 * The opener is a plain button that ships `hidden` with
 * data-law-modal-enhanced, so law-modal.js reveals it only once the dialog it
 * points at is confirmed to exist; a browser with no JavaScript is never shown
 * a control that opens nothing, and gets the <noscript> select instead.
 *
 * @param int $booking_id The flagship booking.
 */
function law_flagship_ticket_type_cell( $booking_id ) {
	$booking_id = (int) $booking_id;
	$type       = (string) law_event_meta( $booking_id, '_law_ticket_type' );
	$label      = law_booking_ticket_type_label( $type );
	$person     = law_booking_attendee( $booking_id );

	ob_start();
	?>
	<button type="button" class="law-linkish law-ticket-type__open"
		data-law-ticket-open
		data-law-refocus
		data-law-ticket-id="<?php echo esc_attr( (string) $booking_id ); ?>"
		data-law-ticket-value="<?php echo esc_attr( $type ); ?>"
		data-law-ticket-name="<?php echo esc_attr( $person['name'] ); ?>"
		data-law-modal-open="<?php echo esc_attr( law_flagship_ticket_type_modal_id() ); ?>"
		data-law-modal-enhanced hidden>
		<span class="law-ticket-type__label"><?php echo esc_html( '' !== $label ? $label : __( 'Add type', 'law' ) ); ?></span>
		<?php echo law_icon( 'pencil', 'law-ticket-type__pencil', 14 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- a fixed SVG from the theme's own table. ?>
		<span class="show-for-sr"><?php echo esc_html( sprintf( __( 'Set the ticket type for %s', 'law' ), $person['name'] ) ); ?></span>
	</button>
	<?php
	// The no-JS path. The dialog is position:fixed and stays hidden without
	// JavaScript, so without this the column would be read-only for anyone
	// with scripts off. Small on purpose: one select and one button.
	?>
	<noscript>
		<form class="law-booking-form law-flagship-bookings__inline" method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_flagship_ticket_type">
			<input type="hidden" name="booking_id" value="<?php echo esc_attr( (string) $booking_id ); ?>">
			<?php wp_nonce_field( 'law_flagship_ticket_type' ); ?>
			<?php law_events_honeypot_field(); ?>
			<label class="show-for-sr" for="law-ticket-<?php echo esc_attr( (string) $booking_id ); ?>"><?php esc_html_e( 'Ticket type', 'law' ); ?></label>
			<select id="law-ticket-<?php echo esc_attr( (string) $booking_id ); ?>" name="law_ticket_type">
				<option value=""><?php esc_html_e( 'Not set', 'law' ); ?></option>
				<?php foreach ( law_booking_ticket_types() as $law_tt_key => $law_tt_label ) : ?>
					<option value="<?php echo esc_attr( $law_tt_key ); ?>" <?php selected( $type, $law_tt_key ); ?>><?php echo esc_html( $law_tt_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="submit" class="law-linkish"><?php esc_html_e( 'Set', 'law' ); ?></button>
		</form>
	</noscript>
	<?php
	return trim( (string) ob_get_clean() );
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
	$basename = 'flagship-registrations-' . gmdate( 'Ymd-His' );

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
 * Manage bookings holds every booking EXCEPT the flagship's; the flagship has
 * its own page. Hosted events and, since 14 September 2026, the receptions.
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
		wp_enqueue_script( 'law-flagship-ticket-type', get_theme_file_uri( 'assets/js/flagship-ticket-type.js' ), array( 'law-modal', 'law-booking-form' ), $mtime( 'assets/js/flagship-ticket-type.js' ), true );
		wp_enqueue_script( 'law-pdfmake', get_theme_file_uri( 'assets/js/vendor/pdfmake.min.js' ), array(), $mtime( 'assets/js/vendor/pdfmake.min.js' ), true );
		wp_enqueue_script( 'law-pdfmake-fonts', get_theme_file_uri( 'assets/js/vendor/vfs_fonts.js' ), array( 'law-pdfmake' ), $mtime( 'assets/js/vendor/vfs_fonts.js' ), true );
		wp_enqueue_script( 'law-export-buttons', get_theme_file_uri( 'assets/js/export-buttons.js' ), array( 'law-pdfmake-fonts' ), $mtime( 'assets/js/export-buttons.js' ), true );
	}
);
