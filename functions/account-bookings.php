<?php
/**
 * Bookings front end (EVENTS_BOOKINGS.md §7): the five-state booking control
 * on the single event view, the booking modal/inline form plumbing, and the
 * transient form state the no-JS path repopulates from. Mirrors
 * account-events.php's role for the host dashboard. CPT-mode only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Whether the current view is a single event (permalink or ?event= on the programme). */
function law_booking_is_event_view() {
	return is_singular( LAW_EVENT_CPT )
		|| ( is_page_template( 'templates/calendar.php' ) && ! empty( $_GET['event'] ) );
}

/**
 * The user's ACTIVE booking on an event: as owner (even seatless) or holding
 * a seat. Feeds the "You're booked" state and its Manage/View link.
 *
 * @return int law_booking post ID, 0 when none.
 */
function law_booking_user_active_booking_for_event( $user_id, $event_id ) {
	foreach ( law_user_booking_ids( (int) $user_id ) as $booking_id ) {
		$booking = get_post( $booking_id );
		if ( $booking && 'publish' === $booking->post_status && (int) $booking->post_parent === (int) $event_id ) {
			return (int) $booking_id;
		}
	}
	return 0;
}

/**
 * One-shot form state for the no-JS (?law_book=1) booking form, so a refused
 * submission re-renders with the typed rows and the refusal message.
 */
function law_booking_store_form_state( $user_id, WP_Error $error, array $rows ) {
	$data = $error->get_error_data();
	set_transient(
		'law_booking_state_' . (int) $user_id,
		array(
			'message' => $error->get_error_message(),
			'row'     => is_array( $data ) && isset( $data['row'] ) ? (int) $data['row'] : null,
			'field'   => is_array( $data ) && isset( $data['field'] ) ? (string) $data['field'] : '',
			'rows'    => array_slice( array_values( array_filter( $rows, 'is_array' ) ), 0, law_booking_max_additional() + 1 ),
		),
		10 * MINUTE_IN_SECONDS
	);
}

function law_booking_form_state() {
	$key   = 'law_booking_state_' . get_current_user_id();
	$state = get_transient( $key );
	if ( $state ) {
		delete_transient( $key );
	}
	return is_array( $state ) ? $state : array( 'message' => '', 'row' => null, 'field' => '', 'rows' => array() );
}

/**
 * The current user's ACTIVE bookings for the "Your bookings" section on
 * My events: booking + the mapped parent event + the framing facts the card
 * needs (owner cards say "You + N guests"; a guest's card is framed
 * "Booked by {owner}" with a View action).
 *
 * @return array[] { booking: WP_Post, event: array, is_owner: bool,
 *                   owner_name: string, guests: int }
 */
function law_account_bookings() {
	if ( 'cpt' !== law_events_source() || ! is_user_logged_in() ) {
		return array();
	}
	$user_id = get_current_user_id();
	$items   = array();
	foreach ( law_user_booking_ids( $user_id ) as $booking_id ) {
		$booking = get_post( $booking_id );
		if ( ! $booking || 'publish' !== $booking->post_status ) {
			continue; // Active only: a cancelled booking's trail is email + log.
		}
		// '*' so the card survives an event that later left the public
		// statuses; the phase 6 sweep cancels bookings on event cancel anyway.
		$event = law_events_map_post( get_post( (int) $booking->post_parent ), array( '*' ) );
		if ( ! $event ) {
			continue;
		}
		$owner   = get_user_by( 'id', (int) $booking->post_author );
		$items[] = array(
			'booking'    => $booking,
			'event'      => $event,
			'is_owner'   => (int) $booking->post_author === $user_id,
			'owner_name' => $owner ? $owner->display_name : '',
			'guests'     => count( array_filter( law_event_meta( $booking_id, '_law_attendee_rows' ), fn( $r ) => empty( $r['is_owner'] ) ) ),
		);
	}
	usort( $items, fn( $a, $b ) => strcmp( $a['event']['sort'], $b['event']['sort'] ) );
	return $items;
}

/**
 * Whether the My events host section (cards, empty state, Submit toolbar)
 * applies to this user at all: pure attendees must not be invited to
 * "Submit an event" under their bookings.
 */
function law_account_user_is_host_like() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	if ( function_exists( 'law_user_is_committee' ) && law_user_is_committee() ) {
		return true;
	}
	$roles = (array) wp_get_current_user()->roles;
	return (bool) array_intersect( array( 'event_host', 'sponsor', 'administrator', 'editor' ), $roles );
}

/**
 * The booking control on the single event view (parts/calendar-body.php),
 * replacing the placeholder Register anchor. Five states:
 * you're booked → bookings open soon → register → sold out (disabled
 * waitlist) → the event has taken place. Renders nothing on the legacy
 * source or for a non-Confirmed event (committee previews carry no booking UI).
 *
 * @param array $event The calendar-mapped event array (law_events_map_post()).
 */
function law_booking_render_action( $event ) {
	if ( 'cpt' !== law_events_source() || empty( $event['id'] ) ) {
		return;
	}
	$event_id = (int) $event['id'];
	$post     = get_post( $event_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}

	// The redirect-with-notice results (no-JS booking, mostly).
	$notices = array(
		'booking-created' => array( 'ok', 'Your booking is confirmed. A confirmation with a calendar invitation is on its way to you.' ),
		'booking-failed'  => array( 'error', 'Sorry, that booking could not be made.' ),
		'rate-limited'    => array( 'error', 'Too many actions in a short time. Please wait a moment and try again.' ),
	);
	$notice  = sanitize_key( (string) ( $_GET['law_notice'] ?? '' ) );
	if ( isset( $notices[ $notice ] ) ) {
		printf(
			'<p class="law-form-notice %s" role="alert">%s</p>',
			'ok' === $notices[ $notice ][0] ? 'is-success' : 'is-error',
			esc_html( $notices[ $notice ][1] )
		);
	}

	// State: already booked (owner, even seatless, or holding a seat).
	$user_id = get_current_user_id();
	if ( $user_id ) {
		$booking_id = law_booking_user_active_booking_for_event( $user_id, $event_id );
		if ( $booking_id ) {
			$is_owner = (int) get_post_field( 'post_author', $booking_id ) === $user_id;
			printf(
				'<p class="law-booking-state">%s</p><a class="button orange" href="%s">%s</a>',
				esc_html__( "You're booked on this event.", 'law' ),
				esc_url( law_booking_manage_url( $booking_id ) ),
				esc_html( $is_owner ? __( 'Manage booking', 'law' ) : __( 'View booking', 'law' ) )
			);
			return;
		}
	}

	// State: no ticket number yet.
	$remaining = law_event_tickets_remaining( $event_id );
	if ( null === $remaining ) {
		echo '<p class="law-booking-state law-booking-state--soon">' . esc_html__( 'Bookings open soon', 'law' ) . '</p>';
		echo '<p class="law-booking-substate">' . esc_html__( "Booking for this event hasn't opened yet. Check back soon.", 'law' ) . '</p>';
		return;
	}

	// State: the event has started or passed.
	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
		echo '<p class="law-booking-state">' . esc_html__( 'This event has taken place, so bookings are closed.', 'law' ) . '</p>';
		return;
	}

	// State: sold out — the disabled waitlist placeholder (settled: the real
	// waitlist is a later phase; the server hard-stop protects capacity).
	if ( 0 === $remaining ) {
		echo '<p class="law-booking-substate">' . esc_html__( "This event is fully booked. The waitlist isn't open yet, so please check back.", 'law' ) . '</p>';
		echo '<a class="button orange" aria-disabled="true" role="button" tabindex="-1">' . esc_html__( 'Join waitlist', 'law' ) . '</a>';
		return;
	}

	// State: bookable. The opener is a real link to the inline no-JS form;
	// booking-form.js upgrades it to open the modal instead.
	$inline = ! empty( $_GET['law_book'] );
	printf(
		'<p class="law-booking-substate">%s</p>',
		esc_html( sprintf( _n( '%s place left', '%s places left', $remaining, 'law' ), number_format_i18n( $remaining ) ) )
	);
	// Both dialogs are position:fixed with z-index 10050 (law-modal.css). This
	// control now renders inside the hero's event details box, and the hero's
	// .grid-container is a stacking context (position:relative, z-index 4,
	// app.css), which would clamp them to level 4 and paint them underneath the
	// fixed header (.nav z-index 99, .affix z-index 9999). So they are deferred
	// to wp_footer, at body level, where no stacking context can reach them.
	law_booking_footer_modal( $event, 'success' );
	if ( $inline ) {
		get_template_part( 'parts/events/booking-modal', null, array( 'event' => $event, 'context' => 'inline' ) );
		return;
	}
	printf(
		'<a class="button orange" href="%s" data-law-modal-open="law-booking-modal">%s</a>',
		esc_url( add_query_arg( 'law_book', 1, get_permalink( $event_id ) ) ),
		esc_html__( 'Register', 'law' )
	);
	law_booking_footer_modal( $event, 'modal' );
}

/**
 * Defer one of the two booking dialogs to wp_footer, so it renders at body level
 * rather than inside whatever container the booking control sits in. See the
 * comment in law_booking_render_action() for why this matters.
 *
 * Called during template render, well before wp_footer fires.
 *
 * @param array  $event The calendar-mapped event array.
 * @param string $which 'modal' (the Register dialog) or 'success'.
 */
function law_booking_footer_modal( array $event, $which ) {
	if ( 'success' === $which && ! is_user_logged_in() ) {
		return;
	}
	add_action(
		'wp_footer',
		static function () use ( $event, $which ) {
			if ( 'success' === $which ) {
				get_template_part( 'parts/events/booking-success-modal', null, array( 'event' => $event ) );
				return;
			}
			get_template_part( 'parts/events/booking-modal', null, array( 'event' => $event, 'context' => 'modal' ) );
		}
	);
}

/**
 * The single event view's assets: the shared form styles (the modal form uses
 * the --light variant), the modal component, and the booking script. Hooked
 * (not just partial-time) so the stylesheets print in the head.
 */
add_action( 'wp_enqueue_scripts', function () {
	if ( 'cpt' !== law_events_source() ) {
		return;
	}

	$booking_script = function () {
		law_modal_enqueue();
		wp_enqueue_script( 'law-booking-form', get_theme_file_uri( 'assets/js/booking-form.js' ), array( 'law-modal' ), filemtime( get_theme_file_path( 'assets/js/booking-form.js' ) ), true );
	};

	if ( law_booking_is_event_view() ) {
		wp_enqueue_style( 'law-event-form', get_theme_file_uri( 'assets/css/event-form.css' ), array(), filemtime( get_theme_file_path( 'assets/css/event-form.css' ) ) );
		$booking_script();
		return;
	}

	// My events sub-views (event-form.css/js already load on this template via
	// submission-form.php's closure): the manage view and the bookings list
	// need the modal + fetch layer; the list adds the export trio, gated the
	// way export.php gates the dashboard's (pdfmake is ~3MB, footer-loaded,
	// and never served to someone the list itself would refuse).
	if ( ! is_page_template( 'templates/account-events.php' ) ) {
		return;
	}
	if ( ! empty( $_GET['law_booking'] ) ) {
		$booking_script();
	}
	$list_event = absint( $_GET['law_event_bookings'] ?? 0 );
	if ( $list_event && law_user_can_manage_event( get_current_user_id(), $list_event ) ) {
		$booking_script();
		$mtime = function ( $rel ) {
			return filemtime( get_theme_file_path( $rel ) );
		};
		wp_enqueue_script( 'law-pdfmake', get_theme_file_uri( 'assets/js/vendor/pdfmake.min.js' ), array(), $mtime( 'assets/js/vendor/pdfmake.min.js' ), true );
		wp_enqueue_script( 'law-pdfmake-fonts', get_theme_file_uri( 'assets/js/vendor/vfs_fonts.js' ), array( 'law-pdfmake' ), $mtime( 'assets/js/vendor/vfs_fonts.js' ), true );
		wp_enqueue_script( 'law-export-buttons', get_theme_file_uri( 'assets/js/export-buttons.js' ), array( 'law-pdfmake-fonts' ), $mtime( 'assets/js/export-buttons.js' ), true );
	}
} );
