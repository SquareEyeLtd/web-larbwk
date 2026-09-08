<?php
/**
 * The committee's cross-event Bookings dashboard (/account/dashboard/bookings/,
 * templates/account-bookings-dashboard.php; EVENTS_BOOKINGS.md §7.6): every
 * attendee row across every event in one filterable table, with the same
 * CSV / Excel / PDF trio as the events dashboard. This is the customer-service
 * and badging surface: "which events is jane@firm.com booked on?" and "give
 * me everyone attending this year" both start here. Mutations stay on the
 * per-event list (one place for Reject and Register), which every row links to.
 *
 * Module convention: this domain file owns its query, its AJAX partial, its
 * export endpoint and its asset gating.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template the dashboard renders through. */
const LAW_BOOKINGS_DASHBOARD_TEMPLATE = 'templates/account-bookings-dashboard.php';

/** Screen cap on bookings (house style: never -1 on a screen; exports pass -1). */
const LAW_BOOKINGS_DASHBOARD_SCREEN_CAP = 2000;

/**
 * The filters, read from the request (or an explicit array, for the export
 * and tests) and normalised.
 *
 * @param array|null $source Defaults to $_GET.
 * @return array{kw:string,event:int,status:string,year:string,press:bool}
 */
function law_bookings_dashboard_filters( ?array $source = null ) {
	$source = null === $source ? $_GET : $source;
	$status = sanitize_key( (string) ( $source['law_bstatus'] ?? '' ) );
	return array(
		'kw'     => sanitize_text_field( wp_unslash( (string) ( $source['law_kw'] ?? '' ) ) ),
		'event'  => absint( $source['law_event'] ?? 0 ),
		'status' => in_array( $status, array( 'cancelled', 'waitlisted', 'all' ), true ) ? $status : '',
		'year'   => sanitize_key( (string) ( $source['law_year'] ?? '' ) ),
		'press'  => ! empty( $source['law_press'] ),
	);
}

/**
 * Events that hold at least one booking (any status), for the event filter:
 * id => title, ordered by start then title.
 *
 * @return array<int,string>
 */
function law_bookings_dashboard_events() {
	global $wpdb;
	$ids = array_map(
		'intval',
		$wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = %s AND post_parent > 0", LAW_BOOKING_CPT ) )
	);
	if ( ! $ids ) {
		return array();
	}
	$events = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => law_event_all_status_keys(),
			'post__in'       => $ids,
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	$out = array();
	foreach ( $events as $event ) {
		$out[ (int) $event->ID ] = array(
			'title' => $event->post_title,
			'start' => (string) law_event_meta( $event->ID, '_law_start' ),
		);
	}
	uasort( $out, fn( $a, $b ) => strcmp( $a['start'] . $a['title'], $b['start'] . $b['title'] ) );
	return array_map( fn( $e ) => $e['title'], $out );
}

/**
 * The attendee rows for the dashboard and its export.
 *
 * One row per booking matching the filters, which since the per-attendee
 * rebuild is one row per person. The keyword
 * matches (case-insensitively) the attendee's name, email, organisation and
 * job title, the booking number ("#12" or "12") and the event title; it is
 * applied in PHP over the fetched set rather than as a LIKE over serialised
 * meta, which the module never does.
 *
 * @param array $filters law_bookings_dashboard_filters().
 * @param int   $limit   Bookings cap (-1 for the export).
 * @return array{rows:array[],bookings:int,events:int,truncated:bool}
 */
function law_bookings_dashboard_rows( array $filters, $limit = LAW_BOOKINGS_DASHBOARD_SCREEN_CAP ) {
	$empty = array( 'rows' => array(), 'bookings' => 0, 'events' => 0, 'truncated' => false );

	// Which events: one, a programme year's, or all.
	$parent_in = null;
	if ( $filters['event'] ) {
		$parent_in = array( $filters['event'] );
	} elseif ( '' !== $filters['year'] ) {
		$parent_in = array_map(
			'intval',
			get_posts(
				array(
					'post_type'      => LAW_EVENT_CPT,
					'post_status'    => law_event_all_status_keys(),
					'posts_per_page' => -1,
					'fields'         => 'ids',
					'tax_query'      => array( array( 'taxonomy' => 'law_year', 'field' => 'slug', 'terms' => $filters['year'] ) ),
				)
			)
		);
		if ( ! $parent_in ) {
			return $empty;
		}
	}

	$statuses = array(
		''           => array( 'publish' ),
		'cancelled'  => array( 'law-cancelled' ),
		'waitlisted' => array( 'law-waitlisted' ),
		'all'        => array( 'publish', 'law-waitlisted', 'law-cancelled' ),
	);
	$query    = array(
		'post_type'      => LAW_BOOKING_CPT,
		'post_status'    => $statuses[ $filters['status'] ],
		'posts_per_page' => (int) $limit,
		'orderby'        => 'date',
		'order'          => 'DESC',
	);
	if ( null !== $parent_in ) {
		$query['post_parent__in'] = $parent_in;
	}
	$bookings  = get_posts( $query );
	$truncated = $limit > 0 && count( $bookings ) >= $limit;

	// One users + one usermeta query for the whole table (the per-event list's
	// precedent): country, dietary and accessibility read from each profile,
	// and the "Invited by" tag resolves the booker.
	$user_ids = array();
	foreach ( $bookings as $booking ) {
		$user_ids[] = (int) $booking->post_author;
		$user_ids[] = (int) law_event_meta( $booking->ID, '_law_booked_by' );
	}
	$user_ids = array_values( array_unique( array_filter( $user_ids ) ) );
	if ( $user_ids ) {
		cache_users( $user_ids );
	}

	$needle = function_exists( 'mb_strtolower' ) ? mb_strtolower( $filters['kw'] ) : strtolower( $filters['kw'] );
	$needle = ltrim( trim( $needle ), '#' );
	$rows   = array();
	$seen_b = array();
	$seen_e = array();

	// One booking is one attendee, so one booking is one row.
	foreach ( $bookings as $booking ) {
		$event_id = (int) $booking->post_parent;
		$event    = get_post( $event_id );
		if ( ! $event || LAW_EVENT_CPT !== $event->post_type ) {
			continue;
		}
		if ( $filters['press'] && ! law_event_meta( $booking->ID, '_law_is_press' ) ) {
			continue;
		}

		$number     = (int) law_event_meta( $booking->ID, '_law_booking_number' );
		$person     = law_booking_attendee( $booking );
		$invited_by = law_booking_invited_by_label( $booking );
		$user       = get_user_by( 'id', (int) $booking->post_author );
		$profile    = $user ? law_profile_values( (int) $user->ID ) : array();

		if ( '' !== $needle ) {
			$haystack = implode(
				"\n",
				array(
					$person['name'],
					$person['email'],
					$person['organisation'],
					$person['job_title'],
					$invited_by,
					(string) $number,
					$event->post_title,
				)
			);
			$haystack = function_exists( 'mb_strtolower' ) ? mb_strtolower( $haystack ) : strtolower( $haystack );
			if ( false === strpos( $haystack, $needle ) ) {
				continue;
			}
		}

		$statuses = array( 'publish' => 'active', 'law-waitlisted' => 'waitlisted', 'law-cancelled' => 'cancelled' );
		$rows[]   = array(
			'booking_id'    => (int) $booking->ID,
			'number'        => $number,
			'status'        => $statuses[ $booking->post_status ] ?? 'active',
			'booked'        => (string) $booking->post_date,
			'event_id'      => $event_id,
			'event_title'   => $event->post_title,
			'event_start'   => (string) law_event_meta( $event_id, '_law_start' ),
			'event_ref'     => (string) law_event_meta( $event_id, '_law_reference' ),
			'invited_by'    => $invited_by,
			'user'          => $user,
			'name'          => $person['name'],
			'email'         => $person['email'],
			'organisation'  => $person['organisation'],
			'job_title'     => $person['job_title'],
			'country'       => (string) ( $profile['country'] ?? '' ),
			'is_press'      => (bool) law_event_meta( $booking->ID, '_law_is_press' ),
			'accessibility' => law_booking_profile_requirements( $profile, 'accessibility' ),
			'dietary'       => law_booking_profile_requirements( $profile, 'dietary' ),
		);
		$seen_b[ (int) $booking->ID ] = true;
		$seen_e[ $event_id ]          = true;
	}

	return array(
		'rows'      => $rows,
		'bookings'  => count( $seen_b ),
		'events'    => count( $seen_e ),
		'truncated' => $truncated,
	);
}

/**
 * One row set for the three export formats: the dashboard rows, uncapped,
 * in the per-event export's column idiom plus the event columns.
 *
 * @return array{title:string,columns:string[],rows:array[]}
 */
function law_bookings_dashboard_export_rows( array $filters ) {
	$data = law_bookings_dashboard_rows( $filters, -1 );
	$rows = array();
	foreach ( $data['rows'] as $row ) {
		// The linked account's name wins; the snapshot splits on the first space.
		if ( $row['user'] && ( $row['user']->first_name || $row['user']->last_name ) ) {
			$first = $row['user']->first_name;
			$last  = $row['user']->last_name;
		} else {
			$parts = preg_split( '/\s+/', trim( $row['name'] ), 2 );
			$first = $parts[0] ?? '';
			$last  = $parts[1] ?? '';
		}
		$rows[] = array(
			$row['number'],
			$row['invited_by'],
			$row['event_title'],
			'' !== $row['event_start'] ? $row['event_start'] : '',
			$row['event_ref'],
			$first,
			$last,
			$row['email'],
			$row['organisation'],
			$row['job_title'],
			$row['country'],
			$row['is_press'] ? 'Yes' : '',
			// The label the rest of the site uses, not the internal key.
			law_booking_status_label( array( 'active' => 'publish', 'waitlisted' => 'law-waitlisted', 'cancelled' => 'law-cancelled' )[ $row['status'] ] ?? 'publish' ),
			substr( $row['booked'], 0, 16 ),
			$row['accessibility'],
			$row['dietary'],
		);
	}

	$scope = array();
	if ( $filters['event'] ) {
		$scope[] = get_the_title( $filters['event'] );
	}
	if ( '' !== $filters['year'] ) {
		$scope[] = $filters['year'];
	}
	$scope[] = array( '' => 'confirmed bookings', 'cancelled' => 'cancelled bookings', 'waitlisted' => 'waitlist entries', 'all' => 'all bookings' )[ $filters['status'] ];
	if ( $filters['press'] ) {
		$scope[] = 'press only';
	}
	if ( '' !== $filters['kw'] ) {
		$scope[] = '"' . $filters['kw'] . '"';
	}

	return array(
		'title'   => 'Bookings: ' . implode( ', ', $scope ),
		'columns' => array( 'Booking ID', 'Invited by', 'Event', 'Event date', 'Reference', 'First name', 'Surname', 'Email', 'Organisation', 'Job title', 'Country', 'Press', 'Status', 'Booked on', 'Accessibility', 'Dietary' ),
		'rows'    => $rows,
	);
}

/* AJAX partial _______________________________________________________________ */

/**
 * &law_partial=1 on the dashboard returns only the table markup so the
 * filter bar (calendar-filters.js) can swap it in place; the page's Members
 * restriction and the committee check are both re-applied, as on the events
 * dashboard.
 */
add_action( 'template_redirect', 'law_bookings_dashboard_maybe_render_partial' );
function law_bookings_dashboard_maybe_render_partial() {
	if ( empty( $_GET['law_partial'] ) || ! is_page_template( LAW_BOOKINGS_DASHBOARD_TEMPLATE ) ) {
		return;
	}
	$page_id = get_queried_object_id();
	if ( function_exists( 'members_can_current_user_view_post' ) && $page_id && ! members_can_current_user_view_post( $page_id ) ) {
		status_header( 403 );
		exit;
	}
	if ( ! law_user_is_committee() ) {
		status_header( 403 );
		exit;
	}
	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	nocache_headers();
	get_template_part( 'parts/events/bookings-dashboard-list' );
	exit;
}

/* Export _____________________________________________________________________ */

/**
 * GET admin-post.php?action=law_bookings_dashboard_export&format=csv|xlsx|json
 * plus the filter params. Committee only (law_user_is_committee()), modelled
 * on law_committee_export_handler(): the json branch verifies the nonce by
 * hand so the PDF fetch gets a parseable 403.
 */
add_action( 'admin_post_law_bookings_dashboard_export', 'law_bookings_dashboard_export_handler' );
function law_bookings_dashboard_export_handler() {
	nocache_headers();
	$format = sanitize_key( $_GET['format'] ?? 'csv' );

	if ( 'json' === $format && ! wp_verify_nonce( (string) ( $_GET['_wpnonce'] ?? '' ), 'law_bookings_dashboard_export' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_bookings_dashboard_export' );

	if ( ! law_user_is_committee() ) {
		if ( 'json' === $format ) {
			wp_send_json_error( array( 'message' => 'Sorry, this export is for the committee.' ), 403 );
		}
		wp_die( 'Sorry, this export is for the committee.' );
	}

	$data     = law_bookings_dashboard_export_rows( law_bookings_dashboard_filters() );
	$basename = 'bookings-all-' . gmdate( 'Ymd-His' );

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

/**
 * The export trio's scripts, committee only (pdfmake is ~3MB, footer-loaded;
 * other logged-in visitors of the page only get its "committee only" notice).
 * The filter bar's CSS/JS and the table styles ride the shared template
 * conditions in functions/enqueue.php and submission-form.php.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! is_page_template( LAW_BOOKINGS_DASHBOARD_TEMPLATE ) || ! law_user_is_committee() ) {
		return;
	}
	$mtime = function ( $rel ) {
		return filemtime( get_theme_file_path( $rel ) );
	};
	wp_enqueue_script( 'law-pdfmake', get_theme_file_uri( 'assets/js/vendor/pdfmake.min.js' ), array(), $mtime( 'assets/js/vendor/pdfmake.min.js' ), true );
	wp_enqueue_script( 'law-pdfmake-fonts', get_theme_file_uri( 'assets/js/vendor/vfs_fonts.js' ), array( 'law-pdfmake' ), $mtime( 'assets/js/vendor/vfs_fonts.js' ), true );
	wp_enqueue_script( 'law-export-buttons', get_theme_file_uri( 'assets/js/export-buttons.js' ), array( 'law-pdfmake-fonts' ), $mtime( 'assets/js/export-buttons.js' ), true );
} );
