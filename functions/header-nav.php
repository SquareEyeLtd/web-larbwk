<?php
/**
 * The header's top bar: sign in / create an account when signed out, and a
 * single account dropdown when signed in.
 *
 * This replaces the old arrangement, where the bar was assembled from a
 * "Top menu" in Appearance > Menus, per-item role rules stored by the If Menu
 * plugin, and a separate "Logged in as ... | Log out" strip printed by the
 * theme. Those three sources drifted: sponsors saw no account link at all,
 * attendees had no route to their bookings, and the mobile bar dropped every
 * child item because it rendered at depth 1.
 *
 * The rules now live here, in one place, expressed through the capability
 * helpers the rest of the module already uses. Two principles matter:
 *
 *   1. No role-name lists. Ask law_user_is_committee(),
 *      law_account_user_has_events() and law_events_user_can_submit()
 *      instead, so this file cannot drift away from the pages it links to.
 *      (That mattered when there were roles to drift between; since they were
 *      retired on 14 September 2026 it matters differently, because the
 *      questions worth asking are now about what somebody HAS, not what they
 *      are.)
 *   2. Access is additive, never either/or. Committee members and
 *      administrators hold bookings and run events of their own, so the
 *      Manage Events link is added to the personal links rather than
 *      replacing them.
 *
 * The markup lives in parts/layout/top-nav.php; this file only decides what
 * appears. Keeping it a plain data function is what makes the visibility
 * rules testable (tests/HeaderNavTest.php).
 *
 * Every item also carries 'group', 'icon' and 'description'. The top bar
 * ignores all three; the account hub (parts/layout/account-tiles.php) renders
 * its boxes from them. The hub is built from THIS function's items, never from
 * a second list of its own: one list is the whole point of the file, and a
 * parallel one would drift from it exactly as the old If Menu rules did.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Page lookup ____________________________________________________ */

/**
 * The account pages, keyed by the identifier the nav uses.
 *
 * Paths, not IDs: IDs differ between environments, and these are the same
 * paths law_setup_account_pages() keys its template assignments by, so the
 * two stay in step and its "MISSING /{path}/" report doubles as a drift
 * alarm for this file.
 *
 * @return array<string,string> key => page path.
 */
function law_account_paths() {
	return array(
		'account'     => 'account',
		'dashboard'   => 'account/dashboard',
		// 'bookings' is the COMMITTEE's cross-event view under the events
		// dashboard; 'my_bookings' is the personal page every signed-in user
		// gets. Two pages, two audiences -- do not collapse the keys.
		'bookings'    => 'account/dashboard/bookings',
		'speakers'    => 'account/dashboard/speakers',
		'flagship'    => 'account/dashboard/flagship',
		'receptions'  => 'account/dashboard/receptions',
		'flagship_bookings' => 'account/dashboard/flagship-bookings',
		'discounts'   => 'account/dashboard/discounts',
		'events'      => 'account/events',
		'my_bookings' => 'account/bookings',
		'submit'      => 'account/events/submit',
		'profile'     => 'account/profile',
		'register'    => 'register',
	);
}

/**
 * Page ID for an account key, memoised for the request.
 *
 * There is no persistent object cache on this install, so core's own
 * get_page_by_path() caching lasts one request only and the header would
 * otherwise repeat every lookup twice (the bar renders for both breakpoints).
 *
 * @param string $key A law_account_paths() key.
 * @return int Page ID, or 0 when the page is missing or the key is unknown.
 */
function law_account_page_id( $key ) {
	static $ids = array();

	if ( array_key_exists( $key, $ids ) ) {
		return $ids[ $key ];
	}

	$paths = law_account_paths();
	if ( ! isset( $paths[ $key ] ) ) {
		$ids[ $key ] = 0;
		return 0;
	}

	$page        = get_page_by_path( $paths[ $key ] );
	$ids[ $key ] = $page instanceof WP_Post ? (int) $page->ID : 0;

	return $ids[ $key ];
}

/**
 * URL for an account key.
 *
 * Falls back to the literal path when the page cannot be found, so a renamed
 * or deleted page produces an honest 404 rather than an empty href that
 * silently resolves to the home page.
 *
 * @param string $key A law_account_paths() key.
 * @return string Absolute URL, or '' for an unknown key.
 */
function law_account_url( $key ) {
	$paths = law_account_paths();
	if ( ! isset( $paths[ $key ] ) ) {
		return '';
	}

	$page_id = law_account_page_id( $key );
	if ( $page_id ) {
		$url = get_permalink( $page_id );
		if ( $url ) {
			return $url;
		}
	}

	return home_url( '/' . $paths[ $key ] . '/' );
}

/** The registration page. The register_url filter (functions/auth.php) already points core at it. */
function law_register_url() {
	return law_account_url( 'register' );
}

/* Visibility _____________________________________________________ */

/**
 * Belt and braces: never link a page the Members plugin would deny.
 *
 * Used only to SUBTRACT, never to grant. members_can_current_user_view_post()
 * returns true for everything when Members' content-permissions setting is
 * off, so treating it as the positive rule would silently expose every link
 * to everyone. Subtractively, that same failure mode is harmless: the theme
 * helpers above remain the actual gate.
 *
 * @param string $key A law_account_paths() key.
 */
function law_header_nav_can_view( $key ) {
	if ( ! function_exists( 'members_can_current_user_view_post' ) ) {
		return true;
	}

	$page_id = law_account_page_id( $key );

	return ! $page_id || members_can_current_user_view_post( $page_id );
}

/**
 * The current front-end URL, for the sign-in link's redirect_to.
 *
 * Returns '' (so the default destination applies: the account hub, or the
 * dashboard for committee) on the login and register pages themselves
 * (bouncing a visitor back to the form they just left is worse than the
 * default), on the home page (nobody signs in to get back to the home page),
 * and anywhere the main query has not resolved a request.
 */
function law_header_nav_current_url() {
	global $wp;

	if ( is_admin() || ! isset( $wp->request ) ) {
		return '';
	}

	$queried = get_queried_object_id();
	if ( $queried && in_array( (int) $queried, array( law_account_page_id( 'register' ), (int) url_to_postid( law_auth_login_url() ) ), true ) ) {
		return '';
	}

	if ( is_front_page() ) {
		return '';
	}

	// add_query_arg() with no changes returns the request URI including its
	// query string. That URI already carries the install's own path (/law/ on
	// a subdirectory install), so it goes onto the site's origin, NOT through
	// home_url(), which would prepend the path a second time (/law/law/). The
	// origin comes from home_url() rather than the Host header so a spoofed
	// header cannot steer the redirect.
	$home   = wp_parse_url( home_url() );
	$origin = $home['scheme'] . '://' . $home['host'] . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );

	return $origin . add_query_arg( array() );
}

/**
 * The name on the desktop trigger, which reads "Logged in as [name]".
 *
 * The full display name, as the old status strip used. Names run to 41
 * characters in this database, so CSS truncates with an ellipsis. Falls
 * back to the first name only if display_name is somehow empty.
 */
function law_header_nav_display_name() {
	$user = wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return '';
	}

	$display = trim( (string) $user->display_name );

	return $display !== '' ? $display : trim( (string) $user->first_name );
}

/**
 * The name on the mobile trigger, a small button beside the burger.
 *
 * The first name; when that is empty, the first word of the display name.
 * "Logged in as" is still there for assistive technology, hidden visually.
 */
function law_header_nav_short_name() {
	$user = wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return '';
	}

	$first = trim( (string) $user->first_name );
	if ( '' !== $first ) {
		return $first;
	}

	$words = preg_split( '/\s+/', law_header_nav_display_name(), 2 );

	return isset( $words[0] ) ? $words[0] : '';
}

/* The menu _______________________________________________________ */

/**
 * Everything the top bar renders, for the current user.
 *
 * Deliberately flat: there is one dropdown and one level, so there is no
 * children key, and items are filtered here rather than carrying a "visible"
 * flag the partial would have to remember to check.
 *
 * @return array{
 *     account: array{name: string, short_name: string, items: array<int,array>}|null,
 *     links:   array<int,array{key: string, label: string, url: string}>
 * }
 */
function law_header_nav() {
	if ( ! is_user_logged_in() ) {
		$redirect = law_header_nav_current_url();
		$login    = $redirect
			? law_auth_login_url( array( 'redirect_to' => rawurlencode( $redirect ) ) )
			: law_auth_login_url();

		return array(
			'account' => null,
			'links'   => array(
				array(
					'key'   => 'signin',
					'label' => __( 'Sign in', 'law' ),
					'url'   => $login,
				),
				array(
					'key'   => 'register',
					'label' => __( 'Create an account', 'law' ),
					'url'   => law_register_url(),
				),
			),
		);
	}

	$items = array();

	// Personal first, committee tools after (Denis, 14 September 2026). The
	// account hub renders the same list as boxes, and somebody arriving there
	// wants their own account before the queue they happen to also run; the
	// hub and this bar must agree, so the order lives here rather than in the
	// hub's markup.
	$items[] = array(
		'key'         => 'profile',
		'label'       => __( 'My profile', 'law' ),
		'group'       => 'personal',
		'icon'        => 'user',
		'description' => __( 'Your details and password', 'law' ),
	);

	// Two pages since 10 September 2026, not one page with two names.
	// /account/events/ is the host's own events; /account/bookings/ is the
	// places anyone has booked. Anyone signed in may hold both.
	//
	// CPT-only: law_account_bookings() returns nothing on the legacy Gravity
	// Forms source, so on a 'gf' environment the page would never have content.
	$has_events = function_exists( 'law_account_user_has_events' ) && law_account_user_has_events();
	if ( function_exists( 'law_events_source' ) && 'cpt' === law_events_source() ) {
		$items[] = array(
			'key'         => 'my_bookings',
			'label'       => __( 'My bookings', 'law' ),
			'group'       => 'personal',
			'icon'        => 'ticket',
			'description' => __( 'Places you have booked', 'law' ),
		);
	} elseif ( ! $has_events ) {
		// Pre-cutover, the old page is still where a booking would be found.
		$items[] = array(
			'key'         => 'events',
			'label'       => __( 'My bookings', 'law' ),
			'group'       => 'personal',
			'icon'        => 'ticket',
			'description' => __( 'Places you have booked', 'law' ),
		);
	}

	// My events is offered to whoever HAS events, committee included (Denis,
	// 14 September 2026). It used to be offered to whoever held a host-side
	// role; with the roles retired, ownership is the only honest test, and
	// linking everybody to a page that would be empty for most of them is
	// noise. A co-owner qualifies through the _law_co_owner meta row, so
	// somebody who runs an event with a colleague keeps their link.
	if ( $has_events ) {
		$items[] = array(
			'key'         => 'events',
			'label'       => __( 'My events', 'law' ),
			'group'       => 'personal',
			'icon'        => 'date',
			'description' => __( 'Events you run or co-own', 'law' ),
		);
	}

	// Straight after My events, not after My bookings (Denis, 10 September
	// 2026): submitting an event is what a host does FROM their events, so
	// the two belong together, and My bookings is a different errand. Offered
	// to everybody signed in now, so it is also the route in for a first-time
	// host who has nothing yet.
	if ( function_exists( 'law_events_user_can_submit' ) && law_events_user_can_submit() ) {
		$items[] = array(
			'key'         => 'submit',
			'label'       => __( 'Submit an event', 'law' ),
			'group'       => 'personal',
			'icon'        => 'plus',
			'description' => __( 'Propose an event for the week', 'law' ),
		);
	}

	// Committee, editors and administrators: the review queue and the rest of
	// the management screens. Added to the personal links above, never instead
	// of them.
	if ( function_exists( 'law_user_is_committee' ) && law_user_is_committee() ) {
		$items[] = array(
			'key'         => 'dashboard',
			'label'       => __( 'Manage events', 'law' ),
			'group'       => 'committee',
			'icon'        => 'clipboard',
			'description' => __( 'Review and manage every submitted event', 'law' ),
		);
		// The speaker records behind the programme, and the per-event details
		// each appearance carries (functions/events/speakers-dashboard.php).
		$items[] = array(
			'key'         => 'speakers',
			'label'       => __( 'Manage speakers', 'law' ),
			'group'       => 'committee',
			'icon'        => 'microphone',
			'description' => __( 'Speaker records and their appearances', 'law' ),
		);
		// The flagship conference is LAW's own event, edited on its own screen
		// rather than through the review queue, which deliberately excludes it
		// (functions/events/flagship-dashboard.php).
		$items[] = array(
			'key'         => 'flagship',
			'label'       => __( 'Manage flagship', 'law' ),
			'group'       => 'committee',
			'icon'        => 'flag',
			'description' => __( 'Edit the flagship conference', 'law' ),
		);
		// The drinks receptions, straight after Manage flagship: like it, this
		// is a CONFIGURATION screen for an event LAW runs itself — dates,
		// prices, places — rather than one of the two bookings views, which
		// the comment below keeps together (RECEPTIONS.md §0.4).
		$items[] = array(
			'key'         => 'receptions',
			'label'       => __( 'Manage receptions', 'law' ),
			'group'       => 'committee',
			'icon'        => 'receptions',
			'description' => __( 'Dates, prices and places for the receptions', 'law' ),
		);
		// The two bookings views sit together, and are named for what they
		// hold rather than for what you do to them (Denis, 10 September
		// 2026). "Manage bookings" said nothing about which bookings, and sat
		// three items away from the other kind.
		//
		// The cross-event view of bookings at HOSTED events (free, instant,
		// no review), EVENTS_BOOKINGS.md §7.6: a child page of the events
		// dashboard with the same Members restriction.
		$items[] = array(
			'key'         => 'bookings',
			'label'       => __( 'Hosted bookings', 'law' ),
			'group'       => 'committee',
			'icon'        => 'places',
			'description' => __( 'Bookings at hosted events', 'law' ),
		);
		// The flagship's applications and payments, deliberately a separate
		// page: a hosted booking is free and instant, a flagship application
		// is a priced request the committee reviews
		// (functions/events/flagship-bookings-dashboard.php).
		$items[] = array(
			'key'         => 'flagship_bookings',
			'label'       => __( 'Flagship bookings', 'law' ),
			'group'       => 'committee',
			'icon'        => 'price',
			'description' => __( 'Applications and payments for the flagship', 'law' ),
		);
		// The discount-code catalogue (functions/events/discounts.php). Codes
		// are accepted when booking a paid reception (RECEPTIONS.md §8.4);
		// the flagship deliberately takes none, because its price is the
		// committee's decision at approval rather than the delegate's at
		// checkout.
		$items[] = array(
			'key'         => 'discounts',
			'label'       => __( 'Discount codes', 'law' ),
			'group'       => 'committee',
			'icon'        => 'type',
			'description' => __( 'Prepare and manage discount codes', 'law' ),
		);
	}

	$queried = (int) get_queried_object_id();
	$built   = array();

	foreach ( $items as $item ) {
		if ( ! law_header_nav_can_view( $item['key'] ) ) {
			continue;
		}

		$page_id = law_account_page_id( $item['key'] );

		$built[] = array(
			'key'         => $item['key'],
			'label'       => $item['label'],
			'url'         => law_account_url( $item['key'] ),
			'current'     => $page_id && $page_id === $queried,
			// The hub reads these three; the top bar ignores them.
			'group'       => $item['group'] ?? 'personal',
			'icon'        => $item['icon'] ?? '',
			'description' => $item['description'] ?? '',
		);
	}

	// Always last, and never subject to the checks above.
	$built[] = array(
		'key'         => 'signout',
		'label'       => __( 'Sign out', 'law' ),
		'url'         => wp_logout_url( home_url( '/' ) ),
		'current'     => false,
		'group'       => 'signout',
		'icon'        => 'signout',
		'description' => '',
	);

	return array(
		'account' => array(
			'name'       => law_header_nav_display_name(),
			'short_name' => law_header_nav_short_name(),
			'items'      => $built,
		),
		'links'   => array(),
	);
}
