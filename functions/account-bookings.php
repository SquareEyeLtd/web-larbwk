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

/**
 * Whether this request needs the single event view's booking assets: the modal
 * component, the fetch layer and the shared form styles.
 *
 * Four surfaces render parts/calendar-body.php's single-event body, and three
 * of them offer a live booking control. The fourth, the committee preview
 * (?preview-event= on the events dashboard), renders that control inert -- a
 * disabled button, no dialogs deferred to wp_footer -- so it deliberately
 * needs none of these and is not named here. See law_booking_render_opener().
 */
function law_booking_is_event_view() {
	if ( is_singular( LAW_EVENT_CPT ) ) {
		return true;
	}
	// The public programme and the committee programme both render the single
	// event body from ?event=. Before 9 September 2026 this named only
	// templates/calendar.php, so the committee programme's single view rendered
	// the booking control with neither law-modal nor booking-form.js behind it,
	// and without event-form.css for the modal form.
	return is_page_template( array( 'templates/calendar.php', 'templates/calendar-committee.php' ) )
		&& ! empty( $_GET['event'] );
}

/**
 * Whether this request renders event CARDS (parts/loop/event.php), each of which
 * now carries a booking button whose dialog is fetched on click.
 *
 * Five surfaces: the public programme, the committee programme, a speaker's
 * "Speaking at" list, My bookings and My events. The speaker profile is not a
 * page template -- it is swapped in by a template_include filter in
 * functions/speakers.php -- so is_page_template() would silently never match it.
 */
function law_booking_is_card_view() {
	if ( law_calendar_is_calendar_page() && empty( $_GET['event'] ) ) {
		return true;
	}
	if ( defined( 'LAW_SPEAKER_CPT' ) && is_singular( LAW_SPEAKER_CPT ) ) {
		return true;
	}
	if ( function_exists( 'law_speakers_is_single' ) && law_speakers_is_single() ) {
		return true;
	}
	return is_page_template( array( 'templates/account-bookings.php', 'templates/account-events.php' ) );
}

/**
 * This user's own live booking on an event, whatever its state.
 *
 * Answered from law_booking_user_bookings_by_event() -- one pair of queries for
 * the whole request -- whenever the statuses asked for are live ones, which is
 * every caller today. The programme listing asks this once per card, so a query
 * per call would put hundreds on the theme's heaviest page. Anything outside the
 * live set (a cancelled booking, say) still falls through to its own query,
 * because the map deliberately holds live bookings only.
 *
 * @return WP_Post|null The booking (publish or law-waitlisted), newest first.
 */
function law_booking_user_booking_for_event( $user_id, $event_id, array $statuses = array( 'publish' ) ) {
	$user_id  = (int) $user_id;
	$event_id = (int) $event_id;
	if ( $user_id < 1 || $event_id < 1 ) {
		return null;
	}

	if ( ! array_diff( $statuses, law_booking_holding_statuses() ) ) {
		$group = law_booking_user_bookings_by_event( $user_id )[ $event_id ] ?? null;
		$own   = $group['own'] ?? null;
		return ( $own && in_array( $own->post_status, $statuses, true ) ) ? $own : null;
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
function law_booking_store_form_state( $user_id, WP_Error $error, array $rows, array $profile = array() ) {
	$data = $error->get_error_data();
	set_transient(
		'law_booking_state_' . (int) $user_id,
		array(
			'message' => $error->get_error_message(),
			'row'     => is_array( $data ) && isset( $data['row'] ) ? (int) $data['row'] : null,
			'field'   => is_array( $data ) && isset( $data['field'] ) ? (string) $data['field'] : '',
			'rows'    => array_slice( array_values( array_filter( $rows, 'is_array' ) ), 0, law_booking_max_additional() + 1 ),
			// The country/accessibility/dietary set the register-an-attendee
			// form also collects, so a refusal does not throw away the answers
			// somebody read off a phone call.
			'profile' => $profile,
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
	$empty = array( 'message' => '', 'row' => null, 'field' => '', 'rows' => array(), 'profile' => array() );
	return is_array( $state ) ? array_merge( $empty, $state ) : $empty;
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
 *                   invited_by: string, waitlisted: bool, status: string,
 *                   flagship: bool, manage_id: int }
 */
function law_account_bookings() {
	if ( 'cpt' !== law_events_source() || ! is_user_logged_in() ) {
		return array();
	}
	// The grouping and the live-only rule live in
	// law_booking_user_bookings_by_event() now, because the booking control on
	// every event card needs the same map and must not query per card.
	$grouped = law_booking_user_bookings_by_event( get_current_user_id() );

	// A waitlist entry for an event that has started will never be promoted, and
	// there is no clean-up job: drop the card rather than leave a permanent
	// "Waitlisted" one for something that already happened. A card kept by a
	// colleague's booking stays, since those are still manageable.
	foreach ( $grouped as $event_id => $group ) {
		if ( ! $group['own'] || 'law-waitlisted' !== $group['own']->post_status ) {
			continue;
		}
		$start = (string) law_event_meta( $event_id, '_law_start' );
		if ( '' === $start || strtotime( $start ) > current_time( 'timestamp' ) ) {
			continue;
		}
		$grouped[ $event_id ]['own'] = null;
		if ( ! $grouped[ $event_id ]['colleagues'] ) {
			unset( $grouped[ $event_id ] );
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
			// The status itself, so a surface can badge a flagship
			// application ("Awaiting review", "Payment failed") without every
			// caller learning a new boolean per state.
			'status'     => (string) $primary->post_status,
			'flagship'   => function_exists( 'law_flagship_is' ) && law_flagship_is( $event_id ),
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

/**
 * The badge for a My bookings card, from the booking's status.
 *
 * A confirmed booking carries none: it is the ordinary case, and badging it
 * would make the exceptions harder to spot rather than easier.
 *
 * @return array{label:string,slug:string} Empty when no badge applies.
 */
function law_booking_card_badge( $status ) {
	$badges = array(
		'law-waitlisted'     => array( 'label' => __( 'Waitlisted', 'law' ), 'slug' => 'waitlisted' ),
		'law-applied'        => array( 'label' => __( 'Awaiting review', 'law' ), 'slug' => 'applied' ),
		'law-payment-failed' => array( 'label' => __( 'Payment needed', 'law' ), 'slug' => 'payment-failed' ),
	);

	return $badges[ (string) $status ] ?? array();
}

/**
 * The badge MODIFIER class for a booking status, wherever one is rendered
 * inline (a table cell, a facts row) rather than as a card's corner flag.
 *
 * One mapping, because there were three: each surface picked its own
 * modifier, and a status none of them had thought of fell through to a bare
 * `.law-cal-card__badge` — which, before the base rule gained a default
 * background, rendered as invisible text. The base now has a floor colour, so
 * the worst case is a neutral pill rather than nothing; this makes the common
 * cases deliberate.
 *
 * @return string A class name, or '' for the ordinary confirmed case, which
 *                carries no colour of its own.
 */
function law_booking_status_badge_class( $status ) {
	$map = array(
		'law-applied'        => 'law-cal-card__badge--applied',
		'law-waitlisted'     => 'law-cal-card__badge--waitlisted',
		'law-payment-failed' => 'law-cal-card__badge--payment-failed',
		'law-declined'       => 'law-cal-card__badge--cancelled',
		'law-cancelled'      => 'law-cal-card__badge--cancelled',
		'publish'            => 'law-cal-card__badge--confirmed',
	);

	return $map[ (string) $status ] ?? '';
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
 * Where one viewer stands on one event: the single decision both booking
 * surfaces read.
 *
 * The event page prints two or three paragraphs per state; an event card has
 * room for a button. Sharing the RENDERER would make the card strip output it
 * never wanted, so what is shared is the decision, and each surface words it in
 * its own space. Before this existed the card would have been a second copy of
 * a ten-branch ordering, and the two would have drifted the first time a state
 * was added.
 *
 * The order matters and is the event page's, unchanged: the viewer's own place
 * outranks availability, and the colleagues count is NOT a state of its own --
 * someone who brought colleagues but has no place of their own still gets the
 * availability state underneath it.
 *
 * @param int   $event_id
 * @param array $args {
 *     @type bool $preview Committee preview (?preview-event=): resolve for an
 *                         event of any status, and report availability only,
 *                         never the viewer's own place. Answers "what will an
 *                         attendee see", not "what do I see".
 *     @type int  $user_id The viewer. Defaults to the current user, or 0 under
 *                         preview. 0 skips every per-user state.
 * }
 * @return array|null NULL when there is no control at all: the legacy source,
 *                    no such post, or an unpublished event outside a preview. {
 *     @type string       $state      flagship|booked|waitlisted|not-open|closed|full|bookable
 *     @type int          $event_id
 *     @type int|null     $remaining  Places left; NULL means not released yet
 *     @type WP_Post|null $booking    The viewer's own holding booking
 *     @type string       $manage_url Manage link for $booking, or for the
 *                                    colleagues they brought; '' when neither
 *     @type string       $invited_by Who brought them, '' when self-booked
 *     @type int          $colleagues Colleagues they hold places for here
 *     @type string       $mode       book|waitlist: which opener applies
 *     @type string       $tone       open|low|full|mine|closed: how prominent
 *                                    the details box paints the panel
 * }
 */
function law_booking_state( $event_id, array $args = array() ) {
	$state = law_booking_resolve_state( $event_id, $args );
	if ( $state ) {
		$state['tone'] = law_booking_tone( $state );
	}
	return $state;
}

/**
 * The availability tone the details box paints itself in: one of
 * open | low | full | mine | closed.
 *
 * The scarcity step reuses law_event_capacity_warning_at() rather than setting
 * a second threshold of its own, so the panel turns amber on exactly the event
 * the host has just been emailed about. Two numbers here would be two numbers
 * to drift apart, and the public page disagreeing with the host's warning email
 * is the worst version of that.
 *
 * 'mine' is deliberately not an urgency tone: somebody who already holds a
 * place has nothing left to hurry for, so their panel stays neutral however
 * full the event is.
 *
 * @param array $state law_booking_resolve_state().
 * @return string
 */
function law_booking_tone( array $state ) {
	switch ( $state['state'] ) {
		case 'booked':
		case 'waitlisted':
			return 'mine';
		case 'closed':
			return 'closed';
		case 'full':
			return 'full';
		case 'not-open':
		case 'flagship':
			return 'open';
	}

	$available = (int) law_event_meta( (int) $state['event_id'], '_law_tickets_available' );
	return (int) $state['remaining'] <= law_event_capacity_warning_at( $available ) ? 'low' : 'open';
}

/** law_booking_state() without the tone: the resolution itself. */
function law_booking_resolve_state( $event_id, array $args = array() ) {
	$event_id = (int) $event_id;
	if ( 'cpt' !== law_events_source() || $event_id < 1 ) {
		return null;
	}
	$post = get_post( $event_id );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return null;
	}

	$preview = ! empty( $args['preview'] );
	$user_id = array_key_exists( 'user_id', $args ) ? (int) $args['user_id'] : ( $preview ? 0 : get_current_user_id() );

	$state = array(
		'state'      => 'bookable',
		'event_id'   => $event_id,
		'remaining'  => null,
		'booking'    => null,
		'manage_url' => '',
		'invited_by' => '',
		'colleagues' => 0,
		'mode'       => 'book',
		'tone'       => 'open',
	);

	// The flagship is applied for, not booked. Returned as a state rather than
	// bailed, so the event page can route it to the application control while a
	// card simply renders nothing.
	if ( function_exists( 'law_flagship_is' ) && law_flagship_is( $event_id ) ) {
		$state['state'] = 'flagship';
		return $state;
	}

	// A preview deliberately skips this: the point of a preview is what the
	// finished page looks like for an attendee, even before it is published.
	if ( ! $preview && 'publish' !== $post->post_status ) {
		return null;
	}

	if ( $user_id > 0 ) {
		// One request-wide map, not a query per event: the programme renders
		// this for every card on the page.
		$group      = law_booking_user_bookings_by_event( $user_id )[ $event_id ] ?? array( 'own' => null, 'colleagues' => array() );
		$colleagues = $group['colleagues'];
		// Lowest ID first, matching law_booking_party()'s order, so the manage
		// link for a colleagues-only party points at the same booking it always
		// has.
		usort( $colleagues, fn( $a, $b ) => (int) $a->ID <=> (int) $b->ID );

		$state['booking']    = $group['own'];
		$state['colleagues'] = count( $colleagues );

		if ( $state['booking'] ) {
			$state['state']      = 'law-waitlisted' === $state['booking']->post_status ? 'waitlisted' : 'booked';
			$state['invited_by'] = law_booking_invited_by_label( $state['booking'] );
			$state['manage_url'] = law_booking_manage_url( (int) $state['booking']->ID );
			return $state;
		}
		if ( $colleagues ) {
			$state['manage_url'] = law_booking_manage_url( (int) $colleagues[0]->ID );
		}
	}

	$state['remaining'] = law_event_tickets_remaining( $event_id );
	if ( null === $state['remaining'] ) {
		$state['state'] = 'not-open';
		return $state;
	}

	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' !== $start && strtotime( $start ) <= current_time( 'timestamp' ) ) {
		$state['state'] = 'closed';
		return $state;
	}

	if ( 0 === $state['remaining'] ) {
		$state['state'] = 'full';
		$state['mode']  = 'waitlist';
	}
	return $state;
}

/**
 * The booking control on the single event view (parts/calendar-body.php),
 * replacing the placeholder Register anchor. Six states, in this order:
 * you're booked → you're on the waitlist → bookings open soon → the event has
 * taken place → sold out (join the waitlist) → register. Renders nothing on
 * the legacy source.
 *
 * Which state applies is law_booking_state()'s answer, not this function's, so
 * the event cards on the programme cannot come to a different conclusion about
 * the same event. This is the wordy surface: it has room for the explanatory
 * paragraphs, and a card does not.
 *
 * @param array $event   The calendar-mapped event array (law_events_map_post()).
 * @param bool  $preview Committee preview: render the last four states for an
 *                       event of any status, with the button inert.
 */
function law_booking_render_action( $event, $preview = false ) {
	$state = law_booking_state( (int) ( $event['id'] ?? 0 ), array( 'preview' => (bool) $preview ) );
	if ( ! $state ) {
		return;
	}
	// The flagship is applied for, not booked: it has its own states, its own
	// copy and its own form (functions/account-flagship.php). Routed here
	// rather than in the template so the details box keeps ONE call site and
	// the decision lives in one place.
	if ( 'flagship' === $state['state'] ) {
		law_flagship_render_action( $event, $preview );
		return;
	}

	if ( ! $preview ) {
		// The redirect-with-notice results (no-JS booking, mostly). Printed
		// OUTSIDE the panel: .law-form-notice carries its own light-surface
		// colours, which would be unreadable on the panel's filled ground.
		law_booking_notice_render();
	}

	ob_start();
	$parts = law_booking_render_action_body( $state, $event, $preview );
	law_booking_panel(
		$state['tone'],
		trim( (string) ob_get_clean() ),
		$parts['action'],
		$parts['form'],
		law_booking_panel_status( $state )
	);
}

/**
 * The filled panel the availability states sit in.
 *
 * The client asked for the places count to be "in a box", to create urgency.
 * The box is always there; only its COLOUR escalates, because a bold
 * "80 places left" creates no urgency at all, it advertises an empty room.
 * The tone therefore comes from law_booking_tone(), which only reaches 'low'
 * once the event is genuinely nearly full.
 *
 * It lays out in TWO SLOTS: every word the state has to say on the LEFT, the
 * thing to press on the RIGHT, the two centred against each other (Denis, 11
 * September 2026). Flat, as direct flex items, the words came apart -- a
 * .law-booking-state heading is flex-basis: 100% and took a row of its own, so
 * "You're booked on this event." floated above the row holding the line under
 * it and the button, and a lone button under justify-content: space-between
 * parked at the panel's LEFT edge rather than its right.
 *
 * Shared with the flagship's control (law_flagship_render_action()), so the two
 * pages cannot drift into two panel styles OR two layouts: this is the one
 * place the slots are built, and both controls hand it the same three parts.
 *
 * @param string $tone   open|low|full|mine|closed.
 * @param string $text   The words, already escaped: the left-hand slot.
 * @param string $action The button(s), already escaped: the right-hand slot.
 *                       More than one is laid out as a row inside it, so the
 *                       colleagues-only state's "Manage bookings" and
 *                       "Register" stay together at the right rather than
 *                       straddling the panel.
 * @param string $form   The no-JS booking form, if this is that request. It
 *                       goes in NEITHER slot: it is a whole form, not a button,
 *                       so it takes its own full-width row below both.
 * @param string $status Optional pill label. It goes INSIDE the left-hand slot,
 *                       on its own line above the words (Denis, 11 September
 *                       2026): "Almost full" and "Only 3 places left" are one
 *                       statement about availability, and the pill spanning the
 *                       whole panel above both slots read as a banner over the
 *                       button as well.
 */
function law_booking_panel( $tone, $text, $action = '', $form = '', $status = '' ) {
	$text   = trim( (string) $text );
	$action = trim( (string) $action );
	$form   = trim( (string) $form );
	$status = trim( (string) $status );
	if ( '' === $text && '' === $status && '' === $action && '' === $form ) {
		return;
	}
	if ( '' !== $status ) {
		$text = sprintf( '<span class="law-booking-panel__status">%s</span>', esc_html( $status ) ) . $text;
	}
	printf(
		'<div class="law-booking-panel law-booking-panel--%s">',
		esc_attr( $tone ? $tone : 'open' )
	);
	if ( '' !== $text ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		printf( '<div class="law-booking-panel__main">%s</div>', $text );
	}
	if ( '' !== $action ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
		printf( '<div class="law-booking-panel__action">%s</div>', $action );
	}
	echo $form; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the caller.
	echo '</div>';
}

/**
 * The pill above the panel body, or '' for the states that do not want one.
 *
 * Only the bookable state gets a pill, and deliberately so: every other state
 * already opens with a .law-booking-state heading that says the same thing in
 * a sentence ("This event is fully booked."), and a 10px pill repeating it
 * would be noise. Bookable is the one state with no heading, which is exactly
 * why its count reads as an afterthought today.
 *
 * @param array $state law_booking_state().
 */
function law_booking_panel_status( array $state ) {
	if ( 'bookable' !== $state['state'] ) {
		return '';
	}
	return 'low' === $state['tone'] ? __( 'Almost full', 'law' ) : __( 'Booking open', 'law' );
}

/**
 * The panel's contents: the six states, in the order law_booking_state()
 * resolved them.
 *
 * Each state PRINTS its words, which the caller buffers into the panel's
 * left-hand slot, and HANDS BACK what there is to press, which the panel puts
 * in its right-hand one. The split is what keeps a heading with the line under
 * it (law_booking_panel()); the branches still return early as they always did.
 *
 * @param array $state   law_booking_state().
 * @param array $event   The calendar-mapped event array.
 * @param bool  $preview Committee preview.
 * @return array{action:string,form:string} The right-hand slot's markup, and
 *                                          the no-JS form when this is that
 *                                          request.
 */
function law_booking_render_action_body( array $state, $event, $preview = false ) {
	// A preview reports the event's availability, never the viewer's own place
	// on it, so the resolver was given no user and the per-user states below
	// cannot fire.
	// State: this person already has a place, or is waiting for one. Their own
	// booking decides; colleagues they brought are managed from the same view
	// but do not make the control say "you're booked".
	$booking = $state['booking'];
	if ( $booking ) {
		$waitlisted = 'waitlisted' === $state['state'];
		$invited_by = $state['invited_by'];
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
		return law_booking_action_parts(
			sprintf(
				'<a class="button orange" href="%s">%s</a>',
				esc_url( $state['manage_url'] ),
				esc_html( law_booking_manage_label( $state ) )
			)
		);
	}

	// State: no place of their own, but they booked colleagues here. They
	// still need the route to manage those, and may still book themselves, so
	// this prints and then falls through to the availability states, whose
	// button joins this one in the same slot.
	$law_bk_manage = '';
	if ( $state['colleagues'] ) {
		printf(
			'<p class="law-booking-state">%s</p><p class="law-booking-substate">%s</p>',
			esc_html( sprintf(
				_n( "You've booked a place for %s colleague.", "You've booked places for %s colleagues.", $state['colleagues'], 'law' ),
				number_format_i18n( $state['colleagues'] )
			) ),
			esc_html__( "You don't have a place yourself.", 'law' )
		);
		$law_bk_manage = sprintf(
			'<a class="button second" href="%s">%s</a>',
			esc_url( $state['manage_url'] ),
			esc_html__( 'Manage bookings', 'law' )
		);
	}

	// The four availability states below are shared with the preview, which
	// shows the same wording; only the opener differs.
	// State: no ticket number yet.
	if ( 'not-open' === $state['state'] ) {
		echo '<p class="law-booking-state">' . esc_html__( 'Bookings open soon', 'law' ) . '</p>';
		echo '<p class="law-booking-substate">' . esc_html__( 'Places for this event have not been released yet. Check back nearer the date.', 'law' ) . '</p>';
		return law_booking_action_parts( $law_bk_manage );
	}

	// State: the event has started or passed.
	if ( 'closed' === $state['state'] ) {
		echo '<p class="law-booking-state">' . esc_html__( 'Bookings for this event have closed.', 'law' ) . '</p>';
		return law_booking_action_parts( $law_bk_manage );
	}

	// State: sold out — the waitlist. Every entry is one place, promoted
	// automatically in turn as places open up.
	if ( 'full' === $state['state'] ) {
		echo '<p class="law-booking-state">' . esc_html__( 'This event is fully booked.', 'law' ) . '</p>';
		echo '<p class="law-booking-substate">' . esc_html__( "Join the waitlist and we'll email you as soon as a place opens up.", 'law' ) . '</p>';
		return law_booking_opener_parts( $event, 'waitlist', $preview, $law_bk_manage );
	}

	// State: bookable. The count carries its own class rather than
	// .law-booking-substate, which is a sentence style: this is the one number
	// in the box that changes and the only one that decides anything, so it
	// takes the size step. It also frees the no-JS inline form, which prints
	// its own .law-booking-substate inside this panel, from having to be
	// excluded by a direct-child selector.
	$law_bk_left = (int) $state['remaining'];
	printf(
		'<p class="law-booking-panel__count">%s</p>',
		esc_html(
			'low' === $state['tone']
				/* translators: %s: number of places. */
				? sprintf( _n( 'Only %s place left', 'Only %s places left', $law_bk_left, 'law' ), number_format_i18n( $law_bk_left ) )
				/* translators: %s: number of places. */
				: sprintf( _n( '%s place left', '%s places left', $law_bk_left, 'law' ), number_format_i18n( $law_bk_left ) )
		)
	);

	return law_booking_opener_parts( $event, 'book', $preview, $law_bk_manage );
}

/**
 * The two parts every state hands back, so no branch has to remember the shape.
 *
 * @param string $action The right-hand slot's markup.
 * @param string $form   The no-JS form, which goes in neither slot.
 */
function law_booking_action_parts( $action = '', $form = '' ) {
	return array( 'action' => (string) $action, 'form' => (string) $form );
}

/**
 * law_booking_render_opener()'s output, sorted into the slot it belongs in.
 *
 * The opener is a button on almost every request and the WHOLE no-JS booking
 * form on one (?law_book=1 / ?law_waitlist=1). Those go in different places --
 * the right-hand slot and a full-width row of its own -- so which one it turned
 * out to be is decided by law_booking_opener_is_form(), the same test the
 * opener itself branches on, rather than by inspecting the markup it produced.
 *
 * @param string $before A button to place ahead of the opener's own, for the
 *                       colleagues-only state, which offers both.
 */
function law_booking_opener_parts( array $event, $mode, $preview, $before = '' ) {
	ob_start();
	law_booking_render_opener( $event, $mode, $preview );
	$opener = trim( (string) ob_get_clean() );

	if ( law_booking_opener_is_form( $mode, $preview ) ) {
		return law_booking_action_parts( $before, $opener );
	}
	return law_booking_action_parts( trim( $before . $opener ) );
}

/**
 * Whether law_booking_render_opener() will render the whole no-JS booking form
 * rather than a button.
 *
 * One function, so the opener and the panel that places its output cannot come
 * to different conclusions about which of the two it is.
 */
function law_booking_opener_is_form( $mode = 'book', $preview = false ) {
	$param = 'waitlist' === $mode ? 'law_waitlist' : 'law_book';
	return ! $preview && ! empty( $_GET[ $param ] );
}

/**
 * The same control's BUTTONS, with none of its explanatory paragraphs, for the
 * repeat at the foot of the single event page (parts/calendar-body.php, under
 * "Back to programme").
 *
 * A reader who has just worked down a long event page should not have to scroll
 * back to the hero to act on it (Denis, 11 September 2026). What they need there
 * is the button, not the wording again: the state has already been explained at
 * the top, and repeating three paragraphs under the back link would read as a
 * second, competing control.
 *
 * It routes the flagship the way law_booking_render_action() does, so ONE call
 * site at the foot of the page covers both kinds of event.
 *
 * The buttons carry no ids, so having two of them on a page is free: both are
 * data-law-book links, the delegated handler in booking-form.js serves whichever
 * is pressed, and only one dialog is ever fetched and in the DOM.
 *
 * @param array $event   The calendar-mapped event array.
 * @param bool  $preview Committee preview: inert buttons, as at the top.
 */
function law_booking_render_action_buttons( $event, $preview = false ) {
	$state = law_booking_state( (int) ( $event['id'] ?? 0 ), array( 'preview' => (bool) $preview ) );
	if ( ! $state ) {
		return;
	}
	if ( 'flagship' === $state['state'] ) {
		law_flagship_render_action_buttons( $event, $preview );
		return;
	}

	// Their own place: the one link they need, worded as at the top.
	if ( $state['booking'] ) {
		printf(
			'<a class="button orange" href="%s">%s</a>',
			esc_url( $state['manage_url'] ),
			esc_html( law_booking_manage_label( $state ) )
		);
		return;
	}

	// Colleagues but no place of their own: both buttons, because both apply --
	// manage the people they brought, and still take a place themselves.
	if ( $state['colleagues'] ) {
		printf(
			'<a class="button second" href="%s">%s</a> ',
			esc_url( $state['manage_url'] ),
			esc_html__( 'Manage bookings', 'law' )
		);
	}

	// Nothing to press when places are not released or the event has been; the
	// hero has already said so, and an empty row is the honest answer.
	if ( ! in_array( $state['state'], array( 'bookable', 'full' ), true ) ) {
		return;
	}

	// On the no-JS path the opener IS the form: ?law_book=1 renders it in the
	// page instead of a button. Rendering it a second time down here would put
	// two copies of the same form, with the same field names and a duplicated
	// id, on one page. The form is already open above; there is nothing to
	// repeat.
	if ( ! empty( $_GET['law_book'] ) || ! empty( $_GET['law_waitlist'] ) ) {
		return;
	}
	law_booking_render_opener( $event, $state['mode'], $preview );
}

/**
 * The label on the link to a booking the viewer already holds. Four variants:
 * theirs or somebody else's, a place or a queue position. Shared, because the
 * event card and the event page both offer this link and read differently if
 * they word it differently.
 *
 * @param array $state law_booking_state().
 */
function law_booking_manage_label( array $state ) {
	$is_mine = '' === $state['invited_by'];
	if ( 'waitlisted' === $state['state'] ) {
		return $is_mine ? __( 'Manage waitlist entry', 'law' ) : __( 'View waitlist entry', 'law' );
	}
	return $is_mine ? __( 'Manage booking', 'law' ) : __( 'View booking', 'law' );
}

/**
 * The booking button on an event card (parts/loop/event.php), as one entry for
 * the card's actions array.
 *
 * It renders NO dialog of its own. A programme day listing puts the whole week
 * on one page -- up to 500 cards -- and the dialog is per event: its wrapper id
 * is a fixed law-booking-modal, its summary, its places count and its colleague
 * cap all differ. Fifty copies would collide on that id, add a nonce and a
 * repeater each, and be thrown away every time a filter re-renders the list. So
 * the button is a plain link to the inline form the event page already serves,
 * and booking-form.js upgrades it by fetching that one event's dialog on click
 * (law_booking_maybe_render_dialog()).
 *
 * The dialog is hooked up for SIGNED-OUT viewers too. It was signed-in only at
 * first, on the reasoning that a dialog saying nothing but "you need an account"
 * is less use than landing on the event page -- but that sent them to
 * ?law_book=1, where the same words render inside the availability panel, and
 * the press stopped behaving like a press (Denis, 11 September 2026). The
 * account requirement is exactly the kind of thing a dialog should say: press
 * Register, be told what Register needs.
 *
 * @param array  $event The calendar-mapped event array.
 * @param string $scope 'full'  every state, including the link to a booking the
 *                              viewer already holds;
 *                      'action' only the states that offer something new, for
 *                              callers whose own actions already link to the
 *                              booking (My bookings, My events).
 * @return array|null One actions entry, or NULL when this card offers nothing.
 */
function law_booking_card_action( array $event, $scope = 'full' ) {
	$state = law_booking_state( (int) ( $event['id'] ?? 0 ) );
	if ( ! $state ) {
		return null;
	}

	// The flagship has its own card and its own application flow; a card here
	// would be a second, wrong route into it.
	if ( 'flagship' === $state['state'] ) {
		return null;
	}

	if ( in_array( $state['state'], array( 'booked', 'waitlisted' ), true ) ) {
		if ( 'full' !== $scope || '' === $state['manage_url'] ) {
			return null;
		}
		return array(
			'label' => law_booking_manage_label( $state ),
			'url'   => $state['manage_url'],
		);
	}

	// Nothing to offer: the places are not released, or the event has been and
	// gone. The card says so by having no second button, and Event details
	// still leads to the full explanation.
	if ( ! in_array( $state['state'], array( 'bookable', 'full' ), true ) ) {
		return null;
	}

	$waitlist = 'waitlist' === $state['mode'];
	return array(
		'label'    => $waitlist ? __( 'Join waitlist', 'law' ) : __( 'Register', 'law' ),
		'url'      => add_query_arg( $waitlist ? 'law_waitlist' : 'law_book', 1, get_permalink( (int) $state['event_id'] ) ),
		'class'    => 'orange law-event-card__button--book',
		// The accessible name has to say WHICH event: fifty buttons all called
		// "Register" is a useless list to a screen reader user.
		'sr_label' => sprintf(
			$waitlist
				/* translators: %s: event title. */
				? __( 'Join the waitlist for %s', 'law' )
				/* translators: %s: event title. */
				: __( 'Register for %s', 'law' ),
			(string) ( $event['title'] ?? '' )
		),
		'dialog'   => (int) $state['event_id'],
	);
}

/**
 * The Register / Join waitlist opener plus its two dialogs. The opener is a
 * real link to the inline no-JS form; booking-form.js upgrades it to open the
 * modal instead.
 *
 * @param string $mode    'book' or 'waitlist'.
 * @param bool   $preview Committee preview: render the button inert instead,
 *                        and defer no dialogs.
 */
function law_booking_render_opener( array $event, $mode = 'book', $preview = false ) {
	$event_id = (int) $event['id'];
	$waitlist = 'waitlist' === $mode;
	$param    = $waitlist ? 'law_waitlist' : 'law_book';
	$label    = $waitlist ? __( 'Join waitlist', 'law' ) : __( 'Register', 'law' );

	// The preview shows the button exactly where the attendee will find it, but
	// it must never be actuable from a page whose event may not even be
	// approved. A disabled <button> is inert by every route -- pointer,
	// keyboard, assistive tech and form submission -- where an <a> with only
	// aria-disabled would still follow its href on Enter. It carries no href and
	// no data-law-book, so there is nothing for the script to fetch or open.
	if ( $preview ) {
		printf(
			'<button type="button" class="button orange" disabled aria-disabled="true">%s</button>',
			esc_html( $label )
		);
		return;
	}

	// The no-JS path: ?law_book=1 renders the form in the page. Its submission
	// still goes over fetch, so it needs the success dialog, and that one IS
	// position:fixed with z-index 10050 (law-modal.css) while this control
	// renders inside the hero's event details box, whose .grid-container is a
	// stacking context (position:relative, z-index 4, app.css) that would clamp
	// it to level 4 and paint it under the fixed header (.nav z-index 99,
	// .affix z-index 9999). So it is deferred to wp_footer, at body level.
	if ( law_booking_opener_is_form( $mode, $preview ) ) {
		law_booking_footer_modal( $event, 'success', $mode );
		get_template_part( 'parts/events/booking-modal', null, array( 'event' => $event, 'context' => 'inline', 'mode' => $mode ) );
		return;
	}

	// The button path carries NO dialog of its own, exactly as the programme
	// cards do (law_booking_card_action()): booking-form.js opens the
	// placeholder on the press and fetches this event's dialog into it. One
	// flow, so the two surfaces cannot drift, and the stacking-context problem
	// goes away with the markup -- the fetched dialogs land at body level in the
	// script's own container rather than inside the hero's box.
	//
	// It also means the places count and the colleague cap are read at the
	// moment of the press rather than at page load, which matters on a page
	// somebody has left open.
	printf(
		'<a class="button orange" href="%s" data-law-book="%s">%s</a>',
		esc_url( add_query_arg( $param, 1, get_permalink( $event_id ) ) ),
		esc_attr( (string) $event_id ),
		esc_html( $label )
	);
}

/**
 * Defer one of the two booking dialogs to wp_footer, so it renders at body level
 * rather than inside whatever container the booking control sits in. See the
 * comment in law_booking_render_opener() for why this matters.
 *
 * Only the 'success' dialog is deferred in practice now, and only on the no-JS
 * ?law_book=1 path: every other dialog on the site is fetched on the press and
 * injected at body level by booking-form.js, where no stacking context can
 * reach it either. The 'modal' branch is kept because it is the same one line,
 * and a future surface that wants a server-rendered dialog should use it rather
 * than reinvent the deferral.
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
 * The flagship half of law_booking_maybe_render_dialog(): the apply dialog and
 * its success dialog for the conference, as an HTML fragment.
 *
 * Its own guard, law_flagship_guard_open(), which is what the apply handler
 * applies -- so, as on the hosted events, a dialog can never be served for
 * something the submit would refuse. Note it deliberately has no capacity
 * check: a full conference still takes applications and queues them.
 *
 * Called with the response still unsent; the caller exits.
 */
function law_booking_render_flagship_dialog( $event_id ) {
	$event_id = (int) $event_id;
	if ( is_wp_error( law_flagship_guard_open( $event_id ) ) ) {
		status_header( 404 );
		return;
	}
	$event = law_events_map_post( get_post( $event_id ) );
	if ( ! $event ) {
		status_header( 404 );
		return;
	}

	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	header( 'X-Robots-Tag: noindex' );
	nocache_headers();
	get_template_part( 'parts/events/flagship-apply-modal', null, array( 'event' => $event, 'context' => 'modal' ) );
	get_template_part( 'parts/events/flagship-success-modal', null, array( 'event' => $event ) );
}

/**
 * The placeholder dialog every surface with a fetched booking dialog needs once:
 * the button opens it at the moment of the press, and the fetched dialog
 * replaces it. See parts/events/booking-loading-modal.php.
 *
 * Every surface that emits data-law-book, and for everybody: a signed-out
 * visitor gets the dialog too, which is where they are told an account is
 * needed.
 */
add_action( 'wp_footer', 'law_booking_render_loading_modal' );
function law_booking_render_loading_modal() {
	if ( 'cpt' !== law_events_source() ) {
		return;
	}
	if ( ! law_booking_is_event_view() && ! law_booking_is_card_view() ) {
		return;
	}
	get_template_part( 'parts/events/booking-loading-modal' );
}

/**
 * The booking dialog for ONE event, as an HTML fragment: the event permalink
 * with ?law_dialog=1 returns the dialog and its success dialog and nothing else.
 *
 * This is what makes the Register button on an event card possible without
 * putting a dialog in every card (law_booking_card_action()). booking-form.js
 * fetches it on click, drops it in at body level and opens it, so one dialog
 * exists at a time and its places count is current as of the click rather than
 * as of page load.
 *
 * A distinct query var, deliberately NOT the ?law_partial=1 the dashboards use:
 * law_calendar_is_calendar_page() is true for an event permalink, so law_partial
 * there already means "give me the programme list", and separating the two on
 * hook priority would be an invisible rule for the next person to break.
 *
 * The mode is decided here, not by the caller: a card rendered an hour ago may
 * ask for the booking form on an event that has since sold out, and the answer
 * should be the waitlist form, not a form the handler would refuse.
 */
add_action( 'template_redirect', 'law_booking_maybe_render_dialog' );
function law_booking_maybe_render_dialog() {
	if ( empty( $_GET['law_dialog'] ) || ! is_singular( LAW_EVENT_CPT ) ) {
		return;
	}
	$event_id = (int) get_queried_object_id();

	// The same Members gate the calendar's own partial applies: a fragment must
	// never answer what the page it came from would refuse.
	if ( function_exists( 'members_can_current_user_view_post' ) && ! members_can_current_user_view_post( $event_id ) ) {
		status_header( 403 );
		exit;
	}

	// The flagship is applied for, not booked, and law_booking_guard_open()
	// refuses it outright, so it branches off before that guard and answers
	// with its own dialogs and its own gate.
	if ( function_exists( 'law_flagship_is' ) && law_flagship_is( $event_id ) ) {
		law_booking_render_flagship_dialog( $event_id );
		exit;
	}

	// law_booking_guard_open() rather than a re-derived set of conditions: it is
	// the SAME predicate law_booking_create_handler() applies, covering the
	// source, the post type, publish status, the flagship refusal, places not
	// released and an event that has started. A dialog can therefore never be
	// served for something the submit would then refuse.
	if ( is_wp_error( law_booking_guard_open( $event_id ) ) ) {
		status_header( 404 );
		exit;
	}

	$event = law_events_map_post( get_post( $event_id ) );
	if ( ! $event ) {
		status_header( 404 );
		exit;
	}
	$mode = 0 === (int) law_event_tickets_remaining( $event_id ) ? 'waitlist' : 'book';

	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	header( 'X-Robots-Tag: noindex' );
	nocache_headers();
	get_template_part( 'parts/events/booking-modal', null, array( 'event' => $event, 'context' => 'modal', 'mode' => $mode ) );
	get_template_part(
		'parts/events/booking-success-modal',
		null,
		array(
			'event'     => $event,
			'mode'      => $mode,
			// Back to the listing they booked from, not to the event page.
			// Validated, so a forged Referer cannot send anyone off-site.
			'close_url' => wp_validate_redirect( wp_get_referer(), get_permalink( $event_id ) ),
		)
	);
	exit;
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

	// Event cards carry a booking button (law_booking_card_action()) whose
	// dialog booking-form.js fetches on click, so every surface that renders
	// them needs the modal component and the fetch layer -- for everybody, since
	// a signed-out visitor gets the dialog too, telling them an account is
	// needed.
	//
	// event-form.css (47KB) stays signed-in only. It styles the FORM, and a
	// signed-out visitor never reaches one: their dialog is two paragraphs and
	// two buttons, all of it law-modal.css.
	if ( law_booking_is_card_view() ) {
		$booking_script();
		if ( is_user_logged_in() ) {
			wp_enqueue_style( 'law-event-form', get_theme_file_uri( 'assets/css/event-form.css' ), array(), filemtime( get_theme_file_path( 'assets/css/event-form.css' ) ) );
		}
	}

	// The two account sub-views (event-form.css/js already load on both
	// templates via submission-form.php's closure). They are on different
	// pages since the My bookings split: the manage view is the attendee's,
	// the per-event list is the host's.
	if ( is_page_template( 'templates/account-bookings.php' ) ) {
		if ( ! empty( $_GET['law_booking'] ) ) {
			$booking_script();
		}
		return;
	}
	if ( ! is_page_template( 'templates/account-events.php' ) ) {
		return;
	}
	// The bookings list adds the export trio, gated the way export.php gates
	// the dashboard's (pdfmake is ~3MB, footer-loaded, and never served to
	// someone the list itself would refuse).
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
