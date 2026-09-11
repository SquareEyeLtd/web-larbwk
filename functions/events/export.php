<?php
/**
 * Committee dashboard exports (CSV / Excel / PDF data), replacing the legacy
 * GravityView view 419 (Events (committee - all)) DataTables export buttons.
 * CSV and XLSX are generated here; the PDF is built client-side by pdfmake
 * (assets/js/export-buttons.js) from this endpoint's format=json branch, so
 * all three formats consume the same law_committee_export_rows() output.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ordered header labels; law_committee_export_row() must match this order. */
function law_committee_export_columns() {
	return array(
		'ID',
		'Reference',
		'Title',
		'Name',
		'Email',
		'Committee assignee',
		'Preferred date & time slots',
		'Confirmed slot',
		'Event status',
		'Payment status',
		'Submitted',
		'Sector',
		'Linked organisations',
		'Sponsored',
		'Run by LAW',
		'Session agenda',
		'Event fee',
		'Discounted fee',
		'Venue capacity',
		'Tickets available',
		'Bookings',
		'Places left',
		'Venue',
	);
}

/** One flat export row for an event, in law_committee_export_columns() order. */
function law_committee_export_row( WP_Post $post ) {
	$id       = $post->ID;
	$host     = get_user_by( 'id', (int) $post->post_author );
	$assignee = get_user_by( 'id', (int) law_event_meta( $id, '_law_assignee' ) );

	// The fee is only snapshotted onto _law_fee_pence at approval; before
	// that, export the live calculation rather than a misleading £0.00.
	$fee_meta  = law_event_meta( $id, '_law_fee_pence' );
	$fee_pence = '' === (string) $fee_meta ? law_event_calculate_fee_pence( $id ) : (int) $fee_meta;

	$tickets = law_event_meta( $id, '_law_tickets_available' );

	return array(
		$id,
		(string) law_event_meta( $id, '_law_reference' ),
		$post->post_title,
		$host ? $host->display_name : '',
		$host ? $host->user_email : '',
		$assignee ? $assignee->display_name : '',
		implode( '; ', law_event_meta( $id, '_law_preferred_slots' ) ),
		(string) law_event_meta( $id, '_law_slot_label' ),
		law_event_status_label( $post ),
		ucfirst( (string) law_event_meta( $id, '_law_payment_status' ) ),
		mysql2date( 'Y-m-d H:i', $post->post_date ),
		law_event_sector_summary( $id ),
		implode( '; ', law_event_organisation_names( $id ) ),
		law_events_post_is_sponsored( $post ) ? 'Yes' : '',
		// Yes/blank, matching Sponsored above rather than Yes/No.
		law_event_meta( $id, '_law_is_law_event' ) ? 'Yes' : '',
		law_event_meta( $id, '_law_session_agenda' ) ? 'Yes' : '',
		law_events_format_pence( $fee_pence ),
		law_event_meta( $id, '_law_fee_override' ) ? '£' . number_format( (float) law_event_meta( $id, '_law_fee_override_amount' ), 2 ) : '',
		(string) law_event_meta( $id, '_law_venue_capacity' ),
		'' === (string) $tickets ? '' : (int) $tickets,
		// The same two figures the dashboard table shows: places taken, and
		// places left (blank until capacity is set, i.e. not open for booking).
		law_event_attendee_total( $id ),
		null === law_event_tickets_remaining( $id ) ? '' : law_event_tickets_remaining( $id ),
		(string) law_event_meta( $id, '_law_venue' ),
	);
}

/**
 * The full export data set, honouring the dashboard's ?law_status= and
 * ?law_kw= filters but not its 300-row screen cap.
 *
 * @return array{columns: string[], rows: array[]}
 */
function law_committee_export_rows() {
	$events = law_committee_events( array( 'posts_per_page' => -1 ) );

	// One users query instead of two per row for host + assignee lookups.
	$user_ids = wp_list_pluck( $events, 'post_author' );
	foreach ( $events as $event ) {
		$user_ids[] = law_event_meta( $event->ID, '_law_assignee' );
	}
	$user_ids = array_filter( array_unique( array_map( 'intval', $user_ids ) ) );
	if ( $user_ids ) {
		cache_users( $user_ids );
	}

	return array(
		'columns' => law_committee_export_columns(),
		'rows'    => array_map( 'law_committee_export_row', $events ),
	);
}

/**
 * The export endpoint: GET admin-post.php?action=law_committee_export with
 * format=csv|xlsx|json (json feeds the client-side pdfmake PDF). Nonce and
 * capability failures answer JSON callers with JSON, like the action handler.
 */
add_action( 'admin_post_law_committee_export', 'law_committee_export_handler' );
function law_committee_export_handler() {
	nocache_headers();
	$format = sanitize_key( $_GET['format'] ?? 'csv' );

	// check_admin_referer() would die with an HTML page the PDF fetch cannot
	// parse, so verify manually first and give it a JSON 403 instead.
	if ( 'json' === $format && ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'law_committee_export' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_committee_export' );

	if ( ! law_user_is_committee() ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Sorry, exports are for the committee.' ), 403 );
		}
		wp_die( 'Sorry, exports are for the committee.' );
	}

	$data     = law_committee_export_rows();
	$basename = sanitize_file_name( 'events-dashboard-' . gmdate( 'Ymd-His' ) );

	if ( 'xlsx' === $format ) {
		law_events_send_xlsx( $data['columns'], $data['rows'], $basename . '.xlsx' );
	}
	if ( 'json' === $format ) {
		wp_send_json_success( array(
			// Legacy parity: the old pdfmake export was named after the page,
			// "Events dashboard | London Arbitration Week.pdf".
			'title'    => 'Events dashboard | ' . get_bloginfo( 'name' ),
			'filename' => 'Events dashboard ' . get_bloginfo( 'name' ) . '.pdf',
			'columns'  => $data['columns'],
			'rows'     => $data['rows'],
		) );
	}
	law_events_send_csv( $data['columns'], $data['rows'], $basename . '.csv' );
}

/** Neutralise spreadsheet formula injection in a CSV cell (OWASP guidance). */
function law_events_csv_guard( $value ) {
	// Test past any leading whitespace too: some spreadsheet imports trim
	// the cell before deciding whether it is a formula.
	$lead = is_string( $value ) ? ltrim( $value ) : '';
	if ( '' !== $lead && false !== strpbrk( $lead[0], "=+-@\t\r" ) ) {
		return "'" . $value;
	}
	return $value;
}

/** Stream a CSV download and exit. An optional title line precedes the header. */
function law_events_send_csv( array $columns, array $rows, string $filename, string $title = '' ) {
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename=' . $filename );
	$out = fopen( 'php://output', 'w' );
	// Without a BOM, Excel guesses the encoding and renders £ as Â£.
	fwrite( $out, "\xEF\xBB\xBF" );
	if ( '' !== $title ) {
		fputcsv( $out, array( law_events_csv_guard( $title ) ) );
	}
	fputcsv( $out, $columns );
	foreach ( $rows as $row ) {
		fputcsv( $out, array_map( 'law_events_csv_guard', $row ) );
	}
	exit;
}

/**
 * Stream a minimal single-sheet .xlsx download and exit. Hand-rolled with
 * ZipArchive (five parts, inline strings) so the theme ships no spreadsheet
 * library; Excel, LibreOffice and Numbers all open it.
 */
function law_events_send_xlsx( array $columns, array $rows, string $filename, string $title = '' ) {
	$file = wp_tempnam( $filename );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $file, ZipArchive::OVERWRITE ) ) {
		unlink( $file );
		wp_die( 'Could not create the Excel file.' );
	}
	$zip->addFromString(
		'[Content_Types].xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
		. '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
		. '<Default Extension="xml" ContentType="application/xml"/>'
		. '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
		. '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
		. '</Types>'
	);
	$zip->addFromString(
		'_rels/.rels',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
		. '</Relationships>'
	);
	$zip->addFromString(
		'xl/workbook.xml',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
		. ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
		. '<sheets><sheet name="Events" sheetId="1" r:id="rId1"/></sheets></workbook>'
	);
	$zip->addFromString(
		'xl/_rels/workbook.xml.rels',
		'<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
		. '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
		. '</Relationships>'
	);
	$zip->addFromString( 'xl/worksheets/sheet1.xml', law_events_xlsx_sheet_xml( $columns, $rows, $title ) );
	$zip->close();

	header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
	header( 'Content-Disposition: attachment; filename=' . $filename );
	header( 'Content-Length: ' . filesize( $file ) );
	readfile( $file );
	unlink( $file );
	exit;
}

/**
 * The worksheet XML. Inline strings, no sharedStrings table; typed text is
 * inert in Excel, which also settles XLSX formula injection. Ints and floats
 * go out as numbers so ID and ticket columns sort numerically.
 */
function law_events_xlsx_sheet_xml( array $columns, array $rows, string $title = '' ) {
	$xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
		. '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
	array_unshift( $rows, $columns );
	if ( '' !== $title ) {
		// The optional title line sits above the header row (one cell).
		array_unshift( $rows, array( $title ) );
	}
	foreach ( $rows as $r => $row ) {
		$xml .= '<row r="' . ( $r + 1 ) . '">';
		// Explicit r= cell refs: some readers misplace ref-less cells.
		foreach ( array_values( $row ) as $c => $value ) {
			$ref = law_events_xlsx_col( $c ) . ( $r + 1 );
			if ( is_int( $value ) || is_float( $value ) ) {
				$xml .= '<c r="' . $ref . '" t="n"><v>' . $value . '</v></c>';
			} else {
				$xml .= '<c r="' . $ref . '" t="inlineStr"><is>'
					. '<t xml:space="preserve">' . law_events_xml_text( (string) $value ) . '</t>'
					. '</is></c>';
			}
		}
		$xml .= '</row>';
	}
	return $xml . '</sheetData></worksheet>';
}

/** XML-escape cell text; Excel refuses files containing C0 controls. */
function law_events_xml_text( $text ) {
	$text = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text );
	return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
}

/** 0-based column index to a spreadsheet column ref: 0 → A, 26 → AA. */
function law_events_xlsx_col( $i ) {
	$letters = '';
	for ( $i++; $i > 0; $i = intdiv( $i - 1, 26 ) ) {
		$letters = chr( 65 + ( $i - 1 ) % 26 ) . $letters;
	}
	return $letters;
}

/** Export UI assets, dashboard list view only (the detail view has no table). */
add_action( 'wp_enqueue_scripts', function () {
	// Committee check too: the page renders a "committee only" notice to other
	// logged-in users, who should not download ~3MB of pdfmake for it.
	if ( ! is_page_template( 'templates/account-dashboard.php' ) || ! empty( $_GET['event'] ) || ! law_user_is_committee() ) {
		return;
	}
	$mtime = function ( $rel ) {
		return filemtime( get_theme_file_path( $rel ) );
	};
	// pdfmake is ~3MB with its fonts, hence footer-loaded and this view only.
	wp_enqueue_script( 'law-pdfmake', get_theme_file_uri( 'assets/js/vendor/pdfmake.min.js' ), array(), $mtime( 'assets/js/vendor/pdfmake.min.js' ), true );
	wp_enqueue_script( 'law-pdfmake-fonts', get_theme_file_uri( 'assets/js/vendor/vfs_fonts.js' ), array( 'law-pdfmake' ), $mtime( 'assets/js/vendor/vfs_fonts.js' ), true );
	wp_enqueue_script( 'law-export-buttons', get_theme_file_uri( 'assets/js/export-buttons.js' ), array( 'law-pdfmake-fonts' ), $mtime( 'assets/js/export-buttons.js' ), true );
} );
