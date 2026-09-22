<?php
/**
 * The committee's Event submitters screen (/account/dashboard/submitters/,
 * templates/account-dashboard-submitters.php).
 *
 * Submitting a new event needs the `event_submitter` role (22 September 2026,
 * see functions/events/capabilities.php). Handing that role out had no home but
 * the wp-admin Users screen, which the committee is deliberately kept out of —
 * a member holding only `events_committee` should never have to learn wp-admin,
 * which is why the events queue, Bookings, Manage speakers, Manage flagship and
 * Discount codes are all front-end screens. This is the sixth.
 *
 * What it does, and pointedly nothing else: grant `event_submitter` to an
 * existing account, and take it away again. It never creates accounts, never
 * touches any other role, and never edits a person's details. `add_role()` and
 * `remove_role()`, never `set_role()`, so a subscriber stays a subscriber
 * throughout and somebody who also holds another role keeps it.
 *
 * Module convention, as discounts-dashboard.php: this file owns the screen's
 * data, its two handlers, its search endpoint and its asset gating. The role
 * itself is defined in capabilities.php, which knows nothing about this screen.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template the screen renders through. */
const LAW_SUBMITTERS_DASHBOARD_TEMPLATE = 'templates/account-dashboard-submitters.php';

/** When the role was granted, and by whom. */
const LAW_SUBMITTER_GRANTED_META = '_law_submitter_granted';
const LAW_SUBMITTER_GRANTED_BY_META = '_law_submitter_granted_by';

/** How many people one search may name. */
const LAW_SUBMITTERS_SEARCH_LIMIT = 10;

/** The screen's URL. */
function law_submitters_dashboard_url() {
	return function_exists( 'law_account_url' )
		? law_account_url( 'submitters' )
		: home_url( '/account/dashboard/submitters/' );
}

/* Reading the list ___________________________________________________________ */

/**
 * Everybody who holds the role, newest grant first.
 *
 * Deliberately a ROLE query rather than a capability one. Committee members,
 * editors and administrators can submit too, through their own roles, and
 * listing them here would be misleading in both directions: they are not on
 * this screen's list, and the Remove button beside them would do nothing to
 * their ability to submit. The screen is about the role it hands out.
 *
 * @return array<int, array{id:int,name:string,email:string,organisation:string,granted:int,granted_by:string,roles:string}>
 */
function law_submitters_rows() {
	$users = get_users(
		array(
			'role__in' => array( law_events_submitter_role() ),
			'orderby'  => 'display_name',
			'order'    => 'ASC',
			'number'   => 500,
		)
	);

	$rows = array();
	foreach ( $users as $user ) {
		$granted    = (int) get_user_meta( $user->ID, LAW_SUBMITTER_GRANTED_META, true );
		$granted_by = (int) get_user_meta( $user->ID, LAW_SUBMITTER_GRANTED_BY_META, true );
		$actor      = $granted_by ? get_user_by( 'id', $granted_by ) : null;

		$rows[] = array(
			'id'           => (int) $user->ID,
			'name'         => law_submitters_display_name( $user ),
			'email'        => (string) $user->user_email,
			'organisation' => (string) get_user_meta( $user->ID, 'organisation', true ),
			'granted'      => $granted,
			// An empty actor is not a bug: the role can be added on the
			// wp-admin Users screen, and an account that arrived that way
			// simply has no grant recorded. Say so rather than inventing one.
			'granted_by'   => $actor ? $actor->display_name : '',
			'roles'        => implode( ', ', array_map( 'law_submitters_role_label', (array) $user->roles ) ),
		);
	}

	// Newest grant first, then alphabetically for everyone with no grant
	// recorded — the committee's question is "who did we just add", and a name
	// sort buries it.
	usort(
		$rows,
		function ( $a, $b ) {
			if ( $a['granted'] !== $b['granted'] ) {
				return $b['granted'] <=> $a['granted'];
			}
			return strcasecmp( $a['name'], $b['name'] );
		}
	);

	return $rows;
}

/** A person's name for this screen, falling back to the part before the @. */
function law_submitters_display_name( $user ) {
	$name = trim( (string) $user->display_name );
	if ( '' !== $name ) {
		return $name;
	}
	$name = trim( trim( (string) $user->first_name ) . ' ' . trim( (string) $user->last_name ) );

	return '' !== $name ? $name : (string) strstr( (string) $user->user_email . '@', '@', true );
}

/** A role slug as the site's own name for it ("Event submitter"). */
function law_submitters_role_label( $slug ) {
	$roles = wp_roles();
	return isset( $roles->role_names[ $slug ] ) ? translate_user_role( $roles->role_names[ $slug ] ) : (string) $slug;
}

/* Judging one candidate ______________________________________________________ */

/**
 * Whether this account can be promoted, and what to say about it if not.
 *
 * Returned for every search result rather than used to filter them, because a
 * result that silently disappears is indistinguishable from a person who has no
 * account: the committee types a colleague's name, sees nothing, and has no way
 * to tell which. So every match is listed, and the ones that cannot be picked
 * carry the reason where the Add button would be.
 *
 * @param WP_User $user
 * @return array{can:bool,reason:string}
 */
function law_submitters_candidate_state( $user ) {
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return array( 'can' => false, 'reason' => 'No account' );
	}
	if ( in_array( law_events_submitter_role(), (array) $user->roles, true ) ) {
		return array( 'can' => false, 'reason' => 'Already a submitter' );
	}
	if ( law_user_is_committee( (int) $user->ID ) ) {
		// Committee, editor or administrator. They can already submit through
		// their own role, so granting this one would change nothing while
		// looking as though it had.
		return array( 'can' => false, 'reason' => 'Can already submit' );
	}

	return array( 'can' => true, 'reason' => '' );
}

/* The search behind the picker _______________________________________________ */

/**
 * Accounts matching a name or email fragment, committee-only.
 *
 * Two queries merged, because WP_User_Query's `search` covers the user table
 * (email, login, nicename, display name) and nothing else, while the module
 * stores the two halves of a person's name as `first_name` / `last_name` user
 * meta. A committee member typing a surname that never made it into the display
 * name would otherwise find nobody, and "search by first or last name" is half
 * the ask.
 *
 * @param string $term Raw search text.
 * @return array<int, array{id:int,name:string,email:string,organisation:string,can:bool,reason:string}>
 */
function law_submitters_search( $term ) {
	$term = trim( (string) $term );
	if ( strlen( $term ) < 2 ) {
		return array();
	}

	// One more than the display limit, so the caller can say "and more" without
	// a second count query.
	$number = LAW_SUBMITTERS_SEARCH_LIMIT + 1;

	$by_user = get_users(
		array(
			'search'         => '*' . $term . '*',
			'search_columns' => array( 'user_email', 'display_name', 'user_login', 'user_nicename' ),
			'number'         => $number,
			'orderby'        => 'display_name',
			'order'          => 'ASC',
		)
	);

	$by_meta = get_users(
		array(
			'meta_query' => array(
				'relation' => 'OR',
				array( 'key' => 'first_name', 'value' => $term, 'compare' => 'LIKE' ),
				array( 'key' => 'last_name', 'value' => $term, 'compare' => 'LIKE' ),
			),
			'number'     => $number,
			'orderby'    => 'display_name',
			'order'      => 'ASC',
		)
	);

	$seen    = array();
	$results = array();
	foreach ( array_merge( $by_user, $by_meta ) as $user ) {
		if ( isset( $seen[ $user->ID ] ) ) {
			continue;
		}
		$seen[ $user->ID ] = true;
		$state             = law_submitters_candidate_state( $user );
		$results[]         = array(
			'id'           => (int) $user->ID,
			'name'         => law_submitters_display_name( $user ),
			'email'        => (string) $user->user_email,
			'organisation' => (string) get_user_meta( $user->ID, 'organisation', true ),
			'can'          => $state['can'],
			'reason'       => $state['reason'],
		);
	}

	// An exact email match is never a guess, so it goes to the top whatever the
	// alphabet says; after that the pickable people come before the ones who
	// are only being shown so the committee knows they were found.
	$lower = strtolower( $term );
	usort(
		$results,
		function ( $a, $b ) use ( $lower ) {
			$a_exact = strtolower( $a['email'] ) === $lower ? 1 : 0;
			$b_exact = strtolower( $b['email'] ) === $lower ? 1 : 0;
			if ( $a_exact !== $b_exact ) {
				return $b_exact <=> $a_exact;
			}
			if ( $a['can'] !== $b['can'] ) {
				return $a['can'] ? -1 : 1;
			}
			return strcasecmp( $a['name'], $b['name'] );
		}
	);

	return $results;
}

add_action( 'wp_ajax_law_submitters_search', 'law_submitters_search_endpoint' );
add_action( 'wp_ajax_nopriv_law_submitters_search', function () {
	wp_send_json_error( array( 'message' => 'You have been signed out. Please reload the page and sign in again.' ), 401 );
} );

/**
 * The picker's search endpoint.
 *
 * Committee-only, because it answers with other people's email addresses. The
 * rate limit is generous on purpose: this fires as somebody types, and a
 * committee member working through a list of twenty colleagues must not be cut
 * off halfway.
 */
function law_submitters_search_endpoint() {
	check_ajax_referer( 'law_submitters_search' );

	if ( ! law_user_is_committee() ) {
		wp_send_json_error( array( 'message' => 'Sorry, this search is for the committee.' ), 403 );
	}
	if ( ! law_events_rate_limit_ok( 'submitters_search', get_current_user_id(), 240, 600 ) ) {
		wp_send_json_error( array( 'message' => 'Too many searches in a short time; please wait a moment.' ), 429 );
	}

	$term    = sanitize_text_field( wp_unslash( (string) ( $_GET['q'] ?? '' ) ) );
	$results = law_submitters_search( $term );
	$more    = count( $results ) > LAW_SUBMITTERS_SEARCH_LIMIT;

	wp_send_json_success(
		array(
			'term'    => $term,
			'results' => array_slice( $results, 0, LAW_SUBMITTERS_SEARCH_LIMIT ),
			'more'    => $more,
		)
	);
}

/* Granting and revoking ______________________________________________________ */

/**
 * Resolve what the Add form posted to one account.
 *
 * Two ways in, because the form has to work without JavaScript. The picker
 * fills a hidden user ID; the plain field takes an email address and is what a
 * no-JS committee member types. The ID is preferred when both arrive, and the
 * script clears it the moment the text changes, so a stale pick can never
 * outlive what is on screen.
 *
 * @return WP_User|WP_Error
 */
function law_submitters_resolve_posted() {
	$user_id = absint( $_POST['law_submitter_id'] ?? 0 );
	if ( $user_id ) {
		$user = get_user_by( 'id', $user_id );
		if ( $user instanceof WP_User && $user->exists() ) {
			return $user;
		}
		return new WP_Error( 'law_submitter_missing', 'That account could not be found. Please search again.' );
	}

	$typed = sanitize_text_field( wp_unslash( (string) ( $_POST['law_submitter_email'] ?? '' ) ) );
	if ( '' === trim( $typed ) ) {
		return new WP_Error( 'law_submitter_empty', 'Please search for somebody by name or email address.' );
	}
	if ( ! is_email( $typed ) ) {
		// Reached only without JavaScript, or when somebody typed a name and
		// pressed the button without choosing anybody. Saying what the field
		// will accept is more use than "invalid".
		return new WP_Error( 'law_submitter_unresolved', 'Please choose somebody from the list of matches, or type their full email address.' );
	}

	$user = get_user_by( 'email', $typed );
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'law_submitter_no_account', 'Nobody with that email address has an account yet. They need to register before they can be made a submitter.' );
	}

	return $user;
}

/**
 * Give somebody the role.
 *
 * add_role(), never set_role(): almost everybody here is a subscriber, and a
 * set_role() would quietly strip that, taking their account pages with it
 * (the Members rows on /account/ name subscriber).
 *
 * @return true|WP_Error
 */
function law_submitter_grant( $user_id, $actor = 0 ) {
	$user = get_user_by( 'id', (int) $user_id );
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'law_submitter_missing', 'That account could not be found.' );
	}

	$state = law_submitters_candidate_state( $user );
	if ( ! $state['can'] ) {
		return new WP_Error(
			'law_submitter_not_eligible',
			'Already a submitter' === $state['reason']
				? sprintf( '%s can already submit events.', law_submitters_display_name( $user ) )
				: sprintf( '%s can already submit events through their committee role, so there is nothing to add.', law_submitters_display_name( $user ) )
		);
	}

	$user->add_role( law_events_submitter_role() );

	// Who and when, so the list can answer "where did this person come from"
	// without a plugin or an audit trail the committee cannot read. Stored
	// rather than derived: nothing else in WordPress records when a role was
	// added.
	update_user_meta( $user->ID, LAW_SUBMITTER_GRANTED_META, time() );
	update_user_meta( $user->ID, LAW_SUBMITTER_GRANTED_BY_META, (int) ( $actor ?: get_current_user_id() ) );

	return true;
}

/**
 * Take the role away again.
 *
 * Only the role goes. The person keeps their account, their bookings and every
 * event they already own, and can still edit those: creating is what the role
 * gates, and editing was deliberately never tied to it.
 *
 * @return true|WP_Error
 */
function law_submitter_revoke( $user_id ) {
	$user = get_user_by( 'id', (int) $user_id );
	if ( ! $user instanceof WP_User || ! $user->exists() ) {
		return new WP_Error( 'law_submitter_missing', 'That account could not be found.' );
	}
	if ( ! in_array( law_events_submitter_role(), (array) $user->roles, true ) ) {
		return new WP_Error( 'law_submitter_not_held', 'That person is not an event submitter.' );
	}

	// Refuse to leave somebody with no role at all. It would cost them their
	// own account pages, because the Members rows on /account/ name subscriber,
	// and this screen must never be able to lock a person out of the site as a
	// side effect of a permission change.
	if ( array( law_events_submitter_role() ) === array_values( (array) $user->roles ) ) {
		$user->add_role( 'subscriber' );
	}

	$user->remove_role( law_events_submitter_role() );
	delete_user_meta( $user->ID, LAW_SUBMITTER_GRANTED_META );
	delete_user_meta( $user->ID, LAW_SUBMITTER_GRANTED_BY_META );

	return true;
}

/* The handlers _______________________________________________________________ */

add_action( 'admin_post_law_submitter_add', 'law_submitter_add_handler' );
add_action( 'admin_post_nopriv_law_submitter_add', 'law_events_nopriv_json' );

function law_submitter_add_handler() {
	$is_ajax = law_events_guard_post(
		'law_submitter_add',
		array(
			'rate'            => array( 'submitter_manage', 60, 600, 300 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'submitter-added',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, managing event submitters is for the committee.', 'status' => 403 ), 'submitter-denied' );
	}

	$user = law_submitters_resolve_posted();
	if ( is_wp_error( $user ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $user->get_error_message(), 'status' => 400 ), 'submitter-failed' );
	}

	$granted = law_submitter_grant( (int) $user->ID, get_current_user_id() );
	if ( is_wp_error( $granted ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $granted->get_error_message(), 'status' => 400 ), 'submitter-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Submitter added',
			'message'  => sprintf( '%s can now submit events.', law_submitters_display_name( $user ) ),
			'redirect' => law_submitters_dashboard_url(),
		),
		'submitter-added'
	);
}

add_action( 'admin_post_law_submitter_remove', 'law_submitter_remove_handler' );
add_action( 'admin_post_nopriv_law_submitter_remove', 'law_events_nopriv_json' );

function law_submitter_remove_handler() {
	$is_ajax = law_events_guard_post(
		'law_submitter_remove',
		array(
			'rate'            => array( 'submitter_manage', 60, 600, 300 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'submitter-removed',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond( $is_ajax, false, array( 'message' => 'Sorry, managing event submitters is for the committee.', 'status' => 403 ), 'submitter-denied' );
	}

	$user_id = absint( $_POST['law_submitter_id'] ?? 0 );
	$user    = $user_id ? get_user_by( 'id', $user_id ) : null;
	$name    = $user instanceof WP_User ? law_submitters_display_name( $user ) : '';

	$revoked = law_submitter_revoke( $user_id );
	if ( is_wp_error( $revoked ) ) {
		law_events_respond( $is_ajax, false, array( 'message' => $revoked->get_error_message(), 'status' => 400 ), 'submitter-failed' );
	}

	law_events_respond(
		$is_ajax,
		true,
		array(
			'title'    => 'Submitter removed',
			'message'  => sprintf( '%s can no longer start new events. Everything they already run is untouched.', $name ),
			'redirect' => law_submitters_dashboard_url(),
		),
		'submitter-removed'
	);
}

/* Assets _____________________________________________________________________ */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! is_page_template( LAW_SUBMITTERS_DASHBOARD_TEMPLATE ) || ! law_user_is_committee() ) {
			return;
		}
		$mtime = function ( $rel ) {
			return filemtime( get_theme_file_path( $rel ) );
		};
		// The shared fetch layer, as on the discounts catalogue: the Add form
		// and the per-row Remove buttons are .law-booking-form, so they submit
		// in the background and fall back to a plain POST without it.
		law_modal_enqueue();
		wp_enqueue_script( 'law-booking-form', get_theme_file_uri( 'assets/js/booking-form.js' ), array( 'law-modal' ), $mtime( 'assets/js/booking-form.js' ), true );
		wp_enqueue_script( 'law-submitter-search', get_theme_file_uri( 'assets/js/submitter-search.js' ), array( 'law-modal' ), $mtime( 'assets/js/submitter-search.js' ), true );
		wp_localize_script(
			'law-submitter-search',
			'lawSubmitterSearch',
			array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'law_submitters_search' ),
			)
		);
	}
);
