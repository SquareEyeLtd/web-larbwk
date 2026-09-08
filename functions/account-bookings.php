<?php
/**
 * Bookings front end (WAITLIST.md §A5, §B5; EVENTS_BOOKINGS.md §7 is the
 * original contract): the booking control on the single event view, the
 * booking modal/inline form plumbing, the shared notice map and the transient
 * form state the no-JS path repopulates from. Mirrors account-events.php's
 * role for the host dashboard. CPT-mode only.
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
 * This user's own live booking on an event, whatever its state.
 *
 * @return WP_Post|null The booking (publish or law-waitlisted), newest first.
 */
function law_booking_user_booking_for_event( $user_id, $event_id, array $statuses = array( 'publish' ) ) {
	$user_id  = (int) $user_id;
	$event_id = (int) $event_id;
	if ( $user_id < 1 || $event_id < 1 ) {
		return null;
	}
	$found = get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_parent'    => $event_id,
			'post_status'    => $statuses,
			'author'         => $user_id,
			'posts_per_page' => 1,
			'orderby'        => 'ID',
			'order'          => 'DESC',
		)
	);
	return $found ? $found[0] : null;
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
 * The current user's live bookings for the "My bookings" section on My events,
 * grouped by EVENT: their own booking, plus the bookings they made for
 * colleagues. One booking per attendee means a person can hold their own place
 * and have brought others to the same event; that is one card, not several.
 *
 * A booker who cancelled their own place but still has colleagues booked keeps
 * a card, because they can still manage those bookings.
 *
 * @return array[] { event: array, own: WP_Post|null, colleagues: WP_Post[],
 *                   invited_by: string, waitlisted: bool, manage_id: int }
 */
function law_account_bookings() {
	if ( 'cpt' !== law_events_source() || ! is_user_logged_in() ) {
		return array();
	}
	$user_id  = get_current_user_id();
	$statuses = law_booking_holding_statuses();
	$grouped  = array();

	$booking_ids = array_merge( law_user_booking_ids( $user_id ), law_user_bookings_made_ids( $user_id ) );
	// fields => 'ids' does not prime the post cache, so without this each
	// booking, and then each parent event, is an individual query.
	if ( $booking_ids ) {
		_prime_post_caches( $booking_ids, false, true );
	}
	foreach ( $booking_ids as $booking_id ) {
		$booking = get_post( $booking_id );
		if ( ! $booking || ! in_array( $booking->post_status, $statuses, true ) || ! $booking->post_parent ) {
			continue; // Live only: a cancelled booking's trail is email + log.
		}
		$event_id = (int) $booking->post_parent;
		// A waitlist entry for an event that has started will never be
		// promoted, and there is no clean-up job: hide it rather than leave a
		// permanent "Waitlisted" card for something that already happened.
		if ( 'law-waitlisted' === $booking->post_status ) {
			$start = (string) law_event_meta( $event_id, '_law_start' );
			if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
				continue;
			}
		}
		if ( ! isset( $grouped[ $event_id ] ) ) {
			$grouped[ $event_id ] = array( 'own' => null, 'colleagues' => array() );
		}
		if ( (int) $booking->post_author === $user_id ) {
			$grouped[ $event_id ]['own'] = $booking;
		} else {
			$grouped[ $event_id ]['colleagues'][] = $booking;
		}
	}

	if ( $grouped ) {
		_prime_post_caches( array_keys( $grouped ), false, true );
	}
	$items = array();
	foreach ( $grouped as $event_id => $group ) {
		// '*' so the card survives an event that later left the public
		// statuses; the cancel sweep cancels bookings on event cancel anyway.
		$event = law_events_map_post( get_post( $event_id ), array( '*' ) );
		if ( ! $event ) {
			continue;
		}
		$primary = $group['own'] ?: $group['colleagues'][0];
		$items[] = array(
			'event'      => $event,
			'own'        => $group['own'],
			'colleagues' => $group['colleagues'],
			'invited_by' => $group['own'] ? law_booking_invited_by_label( $group['own'] ) : '',
			'waitlisted' => 'law-waitlisted' === $primary->post_status,
			'manage_id'  => (int) $primary->ID,
		);
	}
	usort( $items, fn( $a, $b ) => strcmp( $a['event']['sort'], $b['event']['sort'] ) );
	return $items;
}

/**
 * The one notice map every bookings surface reads: the control, the manage
 * view, the per-event list and My events. They each carried their own copy
 * until the per-attendee rebuild, and the copies had drifted in wording, in
 * markup and (worse) in meaning.
 *
 * @return array{0:string,1:string}|null [ 'ok'|'error', message ].
 */
function law_booking_notice_text( $key ) {
	$map = array(
		'booking-created'   => array( 'ok', __( 'Your booking is confirmed. A confirmation with a calendar invitation is on its way to you.', 'law' ) ),
		'booking-cancelled' => array( 'ok', __( 'The booking has been cancelled and the attendee has been emailed.', 'law' ) ),
		'party-cancelled'   => array( 'ok', __( 'Your place and every booking you made for this event have been cancelled, and everyone has been emailed.', 'law' ) ),
		'attendee-added'    => array( 'ok', __( 'Your colleague has their own booking now and has been emailed their confirmation.', 'law' ) ),
		'booking-rejected'  => array( 'ok', __( 'The booking has been cancelled and the attendee emailed.', 'law' ) ),
		'attendee-registered' => array( 'ok', __( 'The attendee has been registered and emailed their confirmation.', 'law' ) ),
		'booking-failed'    => array( 'error', __( 'Sorry, that change could not be made.', 'law' ) ),
		'rate-limited'      => array( 'error', __( 'Too many actions in a short time. Please wait a moment and try again.', 'law' ) ),
		// The waitlist (WAITLIST.md §B5).
		'waitlist-joined'    => array( 'ok', __( "You're on the waitlist. We'll email you as soon as a place opens up.", 'law' ) ),
		'waitlist-left'      => array( 'ok', __( 'That waitlist entry has been cancelled and the attendee has been emailed.', 'law' ) ),
		'waitlist-reordered' => array( 'ok', __( 'The waitlist order has been updated.', 'law' ) ),
		'waitlist-unchanged' => array( 'ok', __( 'The waitlist had already moved on, so nothing was changed. This is the current order.', 'law' ) ),
		'waitlist-promoted'  => array( 'ok', __( 'The entry has been promoted and the attendee emailed their confirmation.', 'law' ) ),
		'waitlist-failed'    => array( 'error', __( 'Sorry, that waitlist change could not be made.', 'law' ) ),
	);
	return $map[ $key ] ?? null;
}

/** Print the notice for ?law_notice=, if there is one. */
function law_booking_notice_render() {
	$notice = law_booking_notice_text( sanitize_key( (string) ( $_GET['law_notice'] ?? '' ) ) );
	if ( ! $notice ) {
		return;
	}
	printf(
		'<p class="law-form-notice %s" role="alert">%s</p>',
		'ok' === $notice[0] ? 'is-success' : 'is-error',
		esc_html( $notice[1] )
	);
}

/**
 * The bookings button's label for a host card or a committee dashboard row:
 * "Bookings (12)", and "Bookings (12) · Waitlist (3)" once anyone is waiting.
 */
function law_booking_counts_label( $event_id ) {
	$label    = sprintf( __( 'Bookings (%s)', 'law' ), number_format_i18n( law_event_attendee_total( $event_id ) ) );
	$waiting  = function_exists( 'law_waitlist_count' ) ? law_waitlist_count( $event_id ) : 0;
	if ( $waiting ) {
		$label .= ' · ' . sprintf( __( 'Waitlist (%s)', 'law' ), number_format_i18n( $waiting ) );
	}
	return $label;
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
 * replacing the placeholder Register anchor. Six states, in this order:
 * you're booked → you're on the waitlist → bookings open soon → the event has
 * taken place → sold out (join the waitlist) → register. Renders nothing on
 * the legacy source or for a non-Confirmed event (committee previews carry no
 * booking UI).
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
	law_booking_notice_render();

	$user_id = get_current_user_id();
	if ( $user_id ) {
		// State: this person already has a place, or is waiting for one. Their
		// own booking decides; colleagues they brought are managed from the
		// same view but do not make the control say "you're booked".
		$booking = law_booking_user_booking_for_event( $user_id, $event_id, law_booking_holding_statuses() );
		if ( $booking ) {
			$waitlisted = 'law-waitlisted' === $booking->post_status;
			$invited_by = law_booking_invited_by_label( $booking );
			if ( $waitlisted ) {
				printf(
					'<p class="law-booking-state">%s</p><p class="law-booking-substate">%s</p>',
					esc_html__( "You're on the waitlist for this event.", 'law' ),
					esc_html__( "We'll email you as soon as a place opens up.", 'law' )
				);
			} else {
				printf( '<p class="law-booking-state">%s</p>', esc_html__( "You're booked on this event.", 'law' ) );
				if ( '' !== $invited_by ) {
					printf(
						'<p class="law-booking-substate">%s</p>',
						esc_html( sprintf( __( 'Invited by %s.', 'law' ), $invited_by ) )
					);
				}
			}
			$is_mine = '' === $invited_by;
			printf(
				'<a class="button orange" href="%s">%s</a>',
				esc_url( law_booking_manage_url( (int) $booking->ID ) ),
				esc_html(
					$waitlisted
						? ( $is_mine ? __( 'Manage waitlist entry', 'law' ) : __( 'View waitlist entry', 'law' ) )
						: ( $is_mine ? __( 'Manage booking', 'law' ) : __( 'View booking', 'law' ) )
				)
			);
			return;
		}

		// State: no place of their own, but they booked colleagues here. They
		// still need the route to manage those, and may still book themselves.
		// Holding statuses, not just active: someone who left the waitlist
		// themselves still manages the colleagues they put on it.
		$party      = law_booking_party( $event_id, $user_id, law_booking_holding_statuses() );
		$colleagues = 0;
		foreach ( $party as $entry ) {
			if ( (int) $entry->post_author !== $user_id ) {
				$colleagues++;
			}
		}
		if ( $colleagues ) {
			printf(
				'<p class="law-booking-state">%s</p><p class="law-booking-substate">%s</p><a class="button second" href="%s">%s</a> ',
				esc_html( sprintf(
					_n( "You've booked a place for %s colleague.", "You've booked places for %s colleagues.", $colleagues, 'law' ),
					number_format_i18n( $colleagues )
				) ),
				esc_html__( "You don't have a place yourself.", 'law' ),
				esc_url( law_booking_manage_url( (int) $party[0]->ID ) ),
				esc_html__( 'Manage bookings', 'law' )
			);
		}
	}

	// State: no ticket number yet.
	$remaining = law_event_tickets_remaining( $event_id );
	if ( null === $remaining ) {
		echo '<p class="law-booking-state">' . esc_html__( 'Bookings open soon', 'law' ) . '</p>';
		echo '<p class="law-booking-substate">' . esc_html__( 'Places for this event have not been released yet. Check back nearer the date.', 'law' ) . '</p>';
		return;
	}

	// State: the event has started or passed.
	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
		echo '<p class="law-booking-state">' . esc_html__( 'Bookings for this event have closed.', 'law' ) . '</p>';
		return;
	}

	// State: sold out — the waitlist. Every entry is one place, promoted
	// automatically in turn as places open up.
	if ( 0 === $remaining ) {
		echo '<p class="law-booking-state">' . esc_html__( 'This event is fully booked.', 'law' ) . '</p>';
		echo '<p class="law-booking-substate">' . esc_html__( "Join the waitlist and we'll email you as soon as a place opens up.", 'law' ) . '</p>';
		law_booking_render_opener( $event, 'waitlist' );
		return;
	}

	// State: bookable.
	printf(
		'<p class="law-booking-substate">%s</p>',
		esc_html( sprintf( _n( '%s place left', '%s places left', $remaining, 'law' ), number_format_i18n( $remaining ) ) )
	);
	law_booking_render_opener( $event, 'book' );
}

/**
 * The Register / Join waitlist opener plus its two dialogs. The opener is a
 * real link to the inline no-JS form; booking-form.js upgrades it to open the
 * modal instead.
 *
 * @param string $mode 'book' or 'waitlist'.
 */
function law_booking_render_opener( array $event, $mode = 'book' ) {
	$event_id  = (int) $event['id'];
	$waitlist  = 'waitlist' === $mode;
	$param     = $waitlist ? 'law_waitlist' : 'law_book';
	$dialog_id = $waitlist ? 'law-waitlist-modal' : 'law-booking-modal';

	// Both dialogs are position:fixed with z-index 10050 (law-modal.css). This
	// control renders inside the hero's event details box, and the hero's
	// .grid-container is a stacking context (position:relative, z-index 4,
	// app.css), which would clamp them to level 4 and paint them underneath the
	// fixed header (.nav z-index 99, .affix z-index 9999). So they are deferred
	// to wp_footer, at body level, where no stacking context can reach them.
	law_booking_footer_modal( $event, 'success', $mode );

	if ( ! empty( $_GET[ $param ] ) ) {
		get_template_part( 'parts/events/booking-modal', null, array( 'event' => $event, 'context' => 'inline', 'mode' => $mode ) );
		return;
	}
	printf(
		'<a class="button orange" href="%s" data-law-modal-open="%s">%s</a>',
		esc_url( add_query_arg( $param, 1, get_permalink( $event_id ) ) ),
		esc_attr( $dialog_id ),
		esc_html( $waitlist ? __( 'Join waitlist', 'law' ) : __( 'Register', 'law' ) )
	);
	law_booking_footer_modal( $event, 'modal', $mode );
}

/**
 * Defer one of the two booking dialogs to wp_footer, so it renders at body level
 * rather than inside whatever container the booking control sits in. See the
 * comment in law_booking_render_action() for why this matters.
 *
 * Called during template render, well before wp_footer fires.
 *
 * @param array  $event The calendar-mapped event array.
 * @param string $which 'modal' (the Register / Join waitlist dialog) or 'success'.
 * @param string $mode  'book' or 'waitlist'.
 */
function law_booking_footer_modal( array $event, $which, $mode = 'book' ) {
	if ( 'success' === $which && ! is_user_logged_in() ) {
		return;
	}
	add_action(
		'wp_footer',
		static function () use ( $event, $which, $mode ) {
			if ( 'success' === $which ) {
				get_template_part( 'parts/events/booking-success-modal', null, array( 'event' => $event, 'mode' => $mode ) );
				return;
			}
			get_template_part( 'parts/events/booking-modal', null, array( 'event' => $event, 'context' => 'modal', 'mode' => $mode ) );
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
