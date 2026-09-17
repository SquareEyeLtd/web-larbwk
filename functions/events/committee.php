<?php
/**
 * Committee dashboard actions (EVENTS_4.1_REBUILD.md §3.6, committee UI):
 * the front-end review flow replacing the Gravity Flow inbox. The template
 * is templates/account-dashboard.php; this file is the data and the handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Event IDs matching a dashboard keyword, across all four haystacks.
 *
 * The box was a bare WP_Query `s` until 15 September 2026, so it searched the
 * post's title, excerpt and content and nothing else. The firm that runs an
 * event is meta, not content, so "Mayer Brown" returned nothing while two of
 * its events sat on the list (Emily O'Callaghan, 15 September 2026). Every
 * other dashboard in the module already matched on organisation -- bookings,
 * flagship bookings, speakers -- and so did the PUBLIC programme filter
 * (law_calendar_event_matches_filters()), which reads the same
 * _law_host_organisations through source.php's `host` key. The committee's own
 * list was the one place it did not. The host's own NAME followed on
 * 16 September 2026 (Denis), for the same reason one step further in: the
 * Host column prints it, and a box that ignores the words on the row it
 * filters reads as broken.
 *
 * Each limb is its own fields => ids query over EVERY status, law-draft
 * included: the status rules (drafts are owner-only, external drafts are
 * merged back) belong to law_committee_events() and are applied by the outer
 * queries, so widening them here would be two places to keep in step.
 *
 * @param string $keyword Raw keyword, already sanitised by the caller.
 * @return int[] Matching event IDs, unique, unordered.
 */
function law_committee_keyword_event_ids( $keyword ) {
	$keyword = trim( (string) $keyword );
	if ( '' === $keyword ) {
		return array();
	}

	$base = array(
		'post_type'      => LAW_EVENT_CPT,
		'post_status'    => law_event_all_status_keys(),
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'no_found_rows'  => true,
		'orderby'        => 'none',
	);

	// 1. Core search: title, excerpt and content, with core's own term
	// splitting. Delegated rather than reimplemented so the behaviour the
	// committee already has does not quietly change under them.
	$ids = get_posts( array_merge( $base, array( 's' => $keyword ) ) );

	// 2. The host's own words for the firm. Free text in a plain meta row, so a
	// LIKE here is not the LIKE-over-serialised-meta the module refuses
	// elsewhere; it is an ordinary column comparison.
	$ids = array_merge(
		$ids,
		get_posts(
			array_merge(
				$base,
				array(
					'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
						array( 'key' => '_law_host_organisations', 'value' => $keyword, 'compare' => 'LIKE' ),
					),
				)
			)
		)
	);

	// 3. Linked organisations, matched by NAME. _law_organisation_ids is an
	// int_array, which law_event_update_meta() stores as one serialised row, so
	// there is no meta_query that can reach a member of it and a serialised
	// LIKE is out. Fetch the events carrying the key at all -- a handful, the
	// field is committee-only and quiet -- and resolve their names in PHP
	// through the memoised law_events_organisation_titles() map, which costs no
	// query per row.
	$linked = get_posts(
		array_merge(
			$base,
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_law_organisation_ids', 'compare' => 'EXISTS' ),
				),
			)
		)
	);
	foreach ( $linked as $linked_id ) {
		foreach ( law_event_organisation_names( $linked_id ) as $org_name ) {
			if ( false !== stripos( $org_name, $keyword ) ) {
				$ids[] = $linked_id;
				break;
			}
		}
	}

	// 4. The host's own name. The Host column prints the event author's
	// display_name, so the name the committee can actually read on the row was
	// the one thing the box would not match: typing "Emma" returned nothing
	// while three of Emma Higgins' events sat on the list (Denis,
	// 16 September 2026). Matched from the EVENT side -- the distinct authors
	// of the CPT -- rather than by running a LIKE over the user table, which
	// would scan thousands of delegate accounts to find the sixty-odd people
	// who have ever hosted anything, and would need a row cap (so, silently
	// dropped matches) to stay affordable.
	$host_user_ids = law_committee_host_name_user_ids( $keyword );
	if ( $host_user_ids ) {
		$ids = array_merge(
			$ids,
			get_posts( array_merge( $base, array( 'author__in' => $host_user_ids ) ) )
		);
	}

	return array_values( array_unique( array_map( 'intval', $ids ) ) );
}

/**
 * Users who have authored an event and whose name answers a keyword.
 *
 * The candidate set is the distinct post_author column of the event CPT, not
 * the user table: a host is by definition someone who has submitted an event,
 * and the site's users are mostly delegates who never will. One DISTINCT read
 * of an indexed column, then cache_users() to prime those rows and their
 * first/last name meta, and the comparison itself happens in PHP.
 *
 * Every word of the keyword has to appear somewhere in the name, in any order,
 * so "higgins emma" finds Emma Higgins and "emma h" narrows rather than
 * widens. display_name is what the dashboard's Host column prints, and the
 * first_name/last_name meta is read alongside it for accounts whose
 * display_name was left as a login or a nickname.
 *
 * Co-owners are deliberately not matched (Denis, 16 September 2026): the row
 * names the submitting host only, and a hit on an invisible name would read
 * like a broken filter in the same way the missing firm did.
 *
 * @param string $keyword Raw keyword, already sanitised by the caller.
 * @return int[] User IDs, unique. Empty when nothing matches.
 */
function law_committee_host_name_user_ids( $keyword ) {
	$words = preg_split( '/\s+/', trim( (string) $keyword ), -1, PREG_SPLIT_NO_EMPTY );
	if ( ! $words ) {
		return array();
	}

	global $wpdb;
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$authors = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT DISTINCT post_author FROM {$wpdb->posts} WHERE post_type = %s AND post_author > 0",
			LAW_EVENT_CPT
		)
	);
	$authors = array_filter( array_map( 'intval', (array) $authors ) );
	if ( ! $authors ) {
		return array();
	}
	cache_users( $authors );

	$matched = array();
	foreach ( $authors as $author_id ) {
		$user = get_userdata( $author_id );
		if ( ! $user ) {
			continue;
		}
		$name = trim(
			$user->display_name . ' ' .
			(string) get_user_meta( $author_id, 'first_name', true ) . ' ' .
			(string) get_user_meta( $author_id, 'last_name', true )
		);
		if ( '' === $name ) {
			continue;
		}
		foreach ( $words as $word ) {
			if ( false === stripos( $name, $word ) ) {
				continue 2;
			}
		}
		$matched[] = $author_id;
	}

	return $matched;
}

/**
 * Events for the committee list, filtered by ?law_status= and ?law_kw=.
 *
 * @param array $overrides Query overrides, e.g. the export passes
 *                         posts_per_page -1 to escape the 300-row screen cap.
 *                         The non-query key 'law_include_flagship' keeps the
 *                         flagship in the results; see below for why it is
 *                         normally out.
 * @return WP_Post[]
 */
function law_committee_events( array $overrides = array() ) {
	$status = sanitize_key( $_GET['law_status'] ?? '' );
	$known  = law_event_statuses();
	// Drafts are owner-only (unsubmitted host data), so an explicit
	// ?law_status=law-draft must not select them for the committee either.
	$query  = array(
		'post_type'      => LAW_EVENT_CPT,
		'post_status'    => isset( $known[ $status ] ) && 'law-draft' !== $status ? $status : array_diff( law_event_all_status_keys(), array( 'law-draft' ) ),
		'posts_per_page' => 300,
		'orderby'        => 'modified',
		'order'          => 'DESC',
	);
	// Read here, applied further down: the keyword resolves to an explicit
	// post__in ID set, and that set has to know which events the flagship rule
	// has already taken out. See the block below the flagship lookup.
	$keyword = sanitize_text_field( wp_unslash( $_GET['law_kw'] ?? '' ) );

	// The "Run by" filter. Its "hosted" side needs BOTH limbs: an event the
	// committee has never saved has no meta row at all, while one saved with the
	// box unticked carries a literal '0', because law_event_update_meta() only
	// deletes on '' and the 'flag' sanitiser returns integer 0. With only
	// NOT EXISTS, every event the committee has ever opened would drop out of
	// the "Hosted events" filter.
	//
	// There was a second select here, "Session agenda", until 15 September 2026.
	// It was dropped as noise on a dashboard that already prints the agenda's
	// session count in the event cell; the meta it read (_law_session_agenda)
	// is untouched and still drives the form gate and the admin column.
	$meta_query = array();
	$run_by     = sanitize_key( $_GET['law_run_by'] ?? '' );
	// 'law' was this filter's word for the switch until 15 September 2026, when
	// it came to mean "external" instead. Accepted still, so a bookmarked
	// dashboard link filters to something rather than to everything.
	if ( 'law' === $run_by ) {
		$run_by = 'external';
	}
	if ( 'external' === $run_by ) {
		$meta_query[] = array( 'key' => '_law_is_external', 'value' => '1' );
	} elseif ( 'host' === $run_by ) {
		$meta_query[] = array(
			'relation' => 'OR',
			array( 'key' => '_law_is_external', 'compare' => 'NOT EXISTS' ),
			array( 'key' => '_law_is_external', 'value' => '1', 'compare' => '!=' ),
		);
	}
	if ( $meta_query ) {
		// Still AND-wrapped with one clause in it: the external-drafts query
		// below merges its own clause into the same array, and a relation-less
		// list would leave that merge's meaning to WP_Query's default.
		$query['meta_query'] = array_merge( array( 'relation' => 'AND' ), $meta_query ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
	}

	// The flagship conference is not a host submission: it has no workflow, fee,
	// slot or invoice, and it is edited on its own screen (Events > Flagship),
	// so it does not belong in the committee's review queue. Excluded by ID
	// rather than a NOT EXISTS meta clause deliberately: the meta_query above is
	// replaced wholesale by a caller's own, and a second LEFT JOIN on every
	// dashboard query buys nothing over one memoised ID lookup.
	//
	// The timeline view (functions/events/slot-chart.php) is the one caller that
	// asks for it back, with $overrides['law_include_flagship']: it draws a day
	// at a time to show clashes, and the flagship occupies a whole day, so
	// omitting it there would hide the biggest clash on the programme. The key is
	// consumed here rather than passed on, because everything left in $overrides
	// is merged into WP_Query's arguments.
	//
	// NB the flagship carries no _law_is_external meta, so it answers the
	// "Hosted events" filter. That is the wrong word for it, but it is the same
	// answer law_event_is_external() gives everywhere else, and a special case
	// here would make the chart disagree with the table it switches from.
	$include_flagship = ! empty( $overrides['law_include_flagship'] );
	unset( $overrides['law_include_flagship'] );

	$flagship = function_exists( 'law_flagship_event_id' ) ? law_flagship_event_id() : 0;
	$excluded = $flagship && ! $include_flagship ? array( $flagship ) : array();

	// The keyword, as an explicit ID set (law_committee_keyword_event_ids()
	// above says what it matches and why it is no longer a bare `s`). Two
	// WP_Query traps dictate the shape of this block, both verified in
	// WP_Query::get_posts():
	//
	// - post__in and post__not_in are an if/elseif, not two AND clauses, so
	//   setting post__in would silently disable the flagship exclusion. The
	//   flagship is therefore subtracted from the matched set instead.
	// - an EMPTY post__in is skipped rather than matching nothing, so a keyword
	//   no event answers must return early. Left to WP_Query it would hand the
	//   committee every event on the site as the result for a typo.
	if ( '' !== $keyword ) {
		$matched = array_diff( law_committee_keyword_event_ids( $keyword ), $excluded );
		if ( ! $matched ) {
			return array();
		}
		$query['post__in'] = array_values( $matched );
	} elseif ( $excluded ) {
		$query['post__not_in'] = $excluded;
	}

	// NB: a caller passing its own meta_query in $overrides would replace the
	// filters above, not add to them. The export (the only caller that passes
	// anything) only overrides posts_per_page.
	$args = array_merge( $query, $overrides );

	// External drafts. A law-draft is normally a host's private, unsubmitted
	// data, which is why the status list above excludes it; an external event is
	// the opposite, committee-owned from the moment it is created, and its draft
	// state means only "not on the programme yet". Excluding it would leave the
	// committee unable to find something it had just saved, so it is fetched
	// separately and merged rather than the shared rule being relaxed for
	// everyone.
	$draft_args                = $args;
	$draft_args['post_status'] = 'law-draft';
	$draft_args['meta_query']  = array_merge( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		array( 'relation' => 'AND' ),
		$meta_query,
		array( array( 'key' => '_law_is_external', 'value' => '1' ) )
	);

	// An explicit ?law_status=law-draft asks for exactly this set and nothing
	// else: $args still carries the full status list, so running both would
	// return every event.
	if ( 'law-draft' === $status ) {
		return get_posts( $draft_args );
	}

	$rows = array_merge( get_posts( $args ), get_posts( $draft_args ) );

	// Both queries are ordered, the concatenation is not. Re-sort on the same
	// key rather than trusting either half's order.
	usort(
		$rows,
		static function ( $a, $b ) {
			return strcmp( (string) $b->post_modified, (string) $a->post_modified );
		}
	);

	$limit = (int) ( $args['posts_per_page'] ?? 300 );
	return $limit > 0 ? array_slice( $rows, 0, $limit ) : $rows;
}

/** Count per status for the dashboard filter chips. */
function law_committee_status_counts() {
	// One query for every status via wp_count_posts, rather than a capped
	// get_posts per status (which miscounts silently above its limit).
	$totals = wp_count_posts( LAW_EVENT_CPT );
	$counts = array();
	foreach ( array_keys( law_event_statuses() ) as $status ) {
		if ( 'law-draft' === $status ) {
			// Host drafts are not the committee's to see, so the total from
			// wp_count_posts() would be wrong here. The only drafts this
			// dashboard lists are external ones (law_committee_events()), so
			// that is what the chip counts.
			$counts[ $status ] = count(
				get_posts(
					array(
						'post_type'      => LAW_EVENT_CPT,
						'post_status'    => 'law-draft',
						'fields'         => 'ids',
						'posts_per_page' => 300,
						'meta_key'       => '_law_is_external', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
						'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					)
				)
			);
			continue;
		}
		$counts[ $status ] = (int) ( $totals->{$status} ?? 0 );
	}

	// wp_count_posts() counts every law_event, including the flagship, which
	// law_committee_events() excludes. Left alone, its status chip would be one
	// higher than the list it filters.
	$flagship = function_exists( 'law_flagship_event_id' ) ? law_flagship_event_id() : 0;
	if ( $flagship ) {
		$status = (string) get_post_status( $flagship );
		if ( isset( $counts[ $status ] ) ) {
			$counts[ $status ] = max( 0, $counts[ $status ] - 1 );
		}
	}

	return $counts;
}

/**
 * The committee dashboard URL that manages one event.
 *
 * The committee works from the site, not from wp-admin (Denis, 15 September
 * 2026), so every "Edit" affordance the committee sees -- on the programme, on
 * the day list, on the flagship card -- has to land on the dashboard screen
 * that actually edits that event rather than on post.php. Which screen that is
 * depends on what the event is, and the answer lives here once so the callers
 * do not each have to know the four cases:
 *
 *   - the flagship has its own dashboard (law_committee_requested_event()
 *     refuses ?event=<flagship id> outright);
 *   - a reception is edited on Manage receptions, which owns the reception
 *     fields the generic form has no boxes for;
 *   - an external event is edited on the committee's external-event form,
 *     the same branch ?law_external=<id> opens;
 *   - anything else is a host event, whose detail view is ?event=<id>.
 *
 * @param int $event_id law_event post ID.
 * @return string URL, or '' when the ID is not a law_event.
 */
function law_committee_event_url( $event_id ) {
	$event_id = (int) $event_id;
	if ( $event_id < 1 || LAW_EVENT_CPT !== get_post_type( $event_id ) ) {
		return '';
	}

	if ( function_exists( 'law_flagship_is' ) && law_flagship_is( $event_id ) ) {
		return function_exists( 'law_flagship_dashboard_url' ) ? law_flagship_dashboard_url() : '';
	}
	if ( function_exists( 'law_reception_is' ) && law_reception_is( $event_id ) ) {
		return function_exists( 'law_receptions_dashboard_url' ) ? law_receptions_dashboard_url( $event_id ) : '';
	}
	if ( function_exists( 'law_external_event_is' ) && law_external_event_is( $event_id ) ) {
		return function_exists( 'law_external_event_url' ) ? law_external_event_url( $event_id ) : '';
	}

	// The same base every other account screen resolves by path, so a page
	// created with a different ID on another environment still works.
	$base = function_exists( 'law_account_url' ) ? law_account_url( 'dashboard' ) : '';
	if ( '' === $base ) {
		$base = home_url( '/account/dashboard/' );
	}

	return add_query_arg( 'event', $event_id, $base );
}

/** The event opened in the dashboard detail view. */
function law_committee_requested_event() {
	$event_id = absint( $_GET['event'] ?? 0 );
	if ( ! $event_id ) {
		return null;
	}
	// The flagship is never in this list, so an ?event=<flagship id> here is a
	// stale link or a guess. Refused rather than rendered, because the detail
	// view offers workflow actions and a slot the flagship does not have; the
	// dashboard's notice points at the screen that does edit it.
	if ( function_exists( 'law_flagship_is' ) && law_flagship_is( $event_id ) ) {
		return null;
	}
	$post = get_post( $event_id );
	return $post && LAW_EVENT_CPT === $post->post_type ? $post : null;
}

/**
 * AJAX partial: the dashboard URL with &law_partial=1 returns only the event
 * markup -- the table (parts/events/dashboard-list.php), or the timeline
 * (parts/events/slot-chart.php) when the view arg asks for it -- so the filter
 * bar can swap it in place without a reload. Mirrors law_calendar_maybe_render_partial(); the
 * page's Members restriction and the committee check both still apply.
 */
add_action( 'template_redirect', 'law_committee_maybe_render_partial' );
function law_committee_maybe_render_partial() {
	if ( empty( $_GET['law_partial'] ) || ! is_page_template( 'templates/account-dashboard.php' ) ) {
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
	// Whichever view the filters were applied from. The view arg rides along in
	// the fetch because it is a hidden field inside #law-cal-filter-form, which
	// is the only place assets/js/calendar-filters.js looks for parameters.
	get_template_part( law_slotchart_is_active() ? 'parts/events/slot-chart' : 'parts/events/dashboard-list' );
	exit;
}

/* The committee action handler ______________________________________________ */

add_action( 'admin_post_law_committee_action', 'law_committee_action_handler' );
add_action( 'admin_post_nopriv_law_committee_action', function () {
	// An AJAX post from a page whose user has since logged out lands here;
	// a redirect would be unparseable to the script, so answer JSON.
	if ( ! empty( $_POST['law_ajax'] ) ) {
		wp_send_json_error( array( 'message' => 'You have been signed out. Please reload the page and sign in again.' ), 401 );
	}
	wp_safe_redirect( wp_login_url() );
	exit;
} );

/**
 * Refuse a dashboard post and stop. On the AJAX path the message travels in
 * the response, because the transient would only be consumed by this same
 * request's aftermath and lost; a plain submit gets it back on the detail
 * panel through law_committee_take_error(). Never returns.
 *
 * @param int    $event_id law_event post ID (the panel to land back on).
 * @param bool   $is_ajax  Whether the caller posted law_ajax.
 * @param string $message  What to tell the committee member.
 * @param int    $code     HTTP status for the JSON path.
 */
function law_committee_refuse( $event_id, $is_ajax, $message, $code = 400 ) {
	if ( $is_ajax ) {
		wp_send_json_error( array( 'message' => $message ), $code );
	}
	set_transient( 'law_dashboard_error_' . get_current_user_id(), $message, 60 );
	wp_safe_redirect(
		add_query_arg(
			array( 'event' => (int) $event_id, 'law_notice' => 'action-failed' ),
			home_url( '/account/dashboard/' )
		)
	);
	exit;
}

/**
 * Check a posted venue capacity band and places-available pair.
 *
 * The rules themselves live in law_events_venue_pair_error() (settings.php,
 * beside the band list) so this panel and the event form share one copy: they
 * held one each until 15 September 2026, when the band floor was added and
 * only one of them would have grown it. This wrapper survives because the
 * panel shows a single message and has no field to key an error to, and
 * because it is the shape the handler and the tests already call.
 *
 * Both bounds are inclusive. The pair is judged against the band being saved
 * in this very post, not the stored one — the panel always posts both halves
 * together, so there is no locked half to read from meta.
 *
 * The caller decides WHETHER to ask: law_committee_action_handler() skips this
 * when the posted pair matches the stored one exactly, so an event that already
 * breaches its band can still be approved, cancelled or deleted. This function
 * answers only "may this pair be stored".
 *
 * @param string $capacity Posted band, '' for "not set".
 * @param string $tickets  Posted places, '' for none released.
 * @return string An empty string when the pair is acceptable, else the message
 *                to refuse it with.
 */
function law_committee_venue_input_error( $capacity, $tickets ) {
	list( , $message ) = law_events_venue_pair_error( $capacity, $tickets );
	return $message;
}

/**
 * Is this posted pair the stored one, handed straight back?
 *
 * Both halves ride along on every panel action, because committee-actions.js
 * posts the whole controls form, so a member who presses Delete posts the band
 * and the places too. Judging that as a submission refused every action on the
 * 17 migrated events whose stored pair breaches its band — approve, send back,
 * reject, mark paid, cancel and delete alike — and a capacity rule must not be
 * able to block a delete (16 September 2026). An untouched pair is therefore
 * not a submission of it, which is the same reading law_events_form_save()
 * takes of a pair whose halves the submitter cannot move.
 *
 * "Unchanged" means the pair exactly as the panel rendered it, handed back.
 * Stored places of 0 render as a BLANK field, so blank is the untouched value
 * there and a typed 0 is a change like any other — which keeps the refusal
 * honest, since "0" is not a value the field may hold. Anything that is not a
 * plain positive number is a change for the same reason: otherwise "lots"
 * against a stored 0 would read as untouched and skip the check that refuses it.
 *
 * @param int    $event_id Event being acted on.
 * @param string $capacity Posted band, '' for "not set".
 * @param string $tickets  Posted places, '' for none released.
 * @return bool True when neither half differs from what is stored.
 */
function law_committee_venue_input_unchanged( $event_id, $capacity, $tickets ) {
	$capacity_stored = (string) law_event_meta( $event_id, '_law_venue_capacity' );
	$places_stored   = (int) law_event_meta( $event_id, '_law_tickets_available' );
	$tickets         = trim( (string) $tickets );

	if ( (string) $capacity !== $capacity_stored ) {
		return false;
	}
	if ( '' === $tickets ) {
		return 0 === $places_stored;
	}
	return ctype_digit( $tickets ) && (int) $tickets > 0 && (int) $tickets === $places_stored;
}

function law_committee_action_handler() {
	$is_ajax = ! empty( $_POST['law_ajax'] );

	// An AJAX caller must get JSON even on a bad nonce — check_admin_referer
	// would die with an HTML page the script cannot parse. A stale nonce here
	// usually means the session changed under the page (logged out, or
	// switched user in another tab), so "reload" is the honest advice.
	if ( $is_ajax && ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'law_committee_action' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_committee_action' );

	if ( ! law_user_is_committee() ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Sorry, this action is for the committee.' ), 403 );
		}
		wp_die( 'Sorry, this action is for the committee.' );
	}

	$event_id = absint( $_POST['event_id'] ?? 0 );
	$post     = get_post( $event_id );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Event not found. Please reload the page.' ), 404 );
		}
		wp_die( 'Event not found.' );
	}
	$actor = get_current_user_id();

	// Field updates land first so an approval snapshots fresh values.
	$before_override = (int) law_event_meta( $event_id, '_law_fee_override' );
	$before_amount   = (float) law_event_meta( $event_id, '_law_fee_override_amount' );
	$before_assignee = (int) law_event_meta( $event_id, '_law_assignee' );

	// The venue capacity band and places available, validated here rather than
	// at their write below for the same reason the fee override is: a refusal
	// must leave the event exactly as it was, and the writes further down would
	// already have landed. The sentinel says the control was on the form, so a
	// blank field means "cleared" rather than "not asked".
	//
	// The CHANGE is judged, not the event: see
	// law_committee_venue_input_unchanged() above for why. Changing either half
	// is still judged in full.
	$venue_present = ! empty( $_POST['law_venue_present'] );
	$capacity_new  = '';
	$tickets_new   = '';
	if ( $venue_present ) {
		$capacity_new = sanitize_text_field( wp_unslash( $_POST['law_venue_capacity'] ?? '' ) );
		$tickets_new  = trim( (string) wp_unslash( $_POST['law_tickets_available'] ?? '' ) );
		$venue_error  = law_committee_venue_input_unchanged( $event_id, $capacity_new, $tickets_new )
			? ''
			: law_committee_venue_input_error( $capacity_new, $tickets_new );
		if ( '' !== $venue_error ) {
			law_committee_refuse( $event_id, $is_ajax, $venue_error );
		}
	}

	// The host fee override is checked BEFORE any write, so a refusal leaves the
	// event exactly as it was: the other field writes below and the workflow
	// action further down never run.
	$fee_notice = null;
	if ( isset( $_POST['law_fee_override_amount'] ) ) {
		$override_on = ! empty( $_POST['law_fee_override'] );
		$amount_raw  = trim( (string) wp_unslash( $_POST['law_fee_override_amount'] ) );

		// A ticked box with an empty amount used to sanitise to £0.00, which
		// waives the fee, skips the invoice and auto-confirms the event. Far too
		// consequential to be what an empty box means, so it is refused; a
		// deliberately typed 0 still waives the fee.
		if ( $override_on && '' === $amount_raw ) {
			law_committee_refuse(
				$event_id,
				$is_ajax,
				'Enter the new host fee in pounds. Type 0 to waive the fee entirely, or untick "Override the host fee" to charge the tier price.'
			);
		}

		// The CHANGE is judged, not the event: re-posting the stored values with
		// the rest of the panel must never void an invoice, and on a locked
		// event it must not be refused either, because the committee was saving
		// the venue and the fee control simply came along with the form.
		$fee_input_changed = (int) $override_on !== $before_override
			|| abs( (float) $amount_raw - $before_amount ) >= 0.005;
		$fee_mode          = law_event_fee_edit_mode( $event_id );

		// Only reachable from a hand-made request: the control renders read-only
		// when the fee is settled. Logged, as the module logs every refusal.
		if ( $fee_input_changed && 'locked' === $fee_mode ) {
			law_event_log(
				$event_id,
				'Refused a host fee override change: the fee is settled or the event is no longer live, so the change would reach neither the snapshot nor an invoice.',
				array( 'action' => 'refused', 'attempted' => 'fee_override', 'source' => 'ui' ),
				array( 'user_id' => $actor )
			);
			law_committee_refuse(
				$event_id,
				$is_ajax,
				'This event\'s host fee can no longer be changed: it is either already paid or refunded, or the event is no longer live. Settle the difference in Stripe (a credit note or a refund) and set the payment status to match.',
				403
			);
		}

		// A post-approval fee change voids an invoice and raises another, so it
		// must not ride along with a workflow action in the same submit: the
		// two actions an approved event still offers are Mark paid & confirm
		// (which would settle the invoice this is about to void) and Cancel
		// (which voids it anyway). Refused before any write, so neither half
		// of the submit half-happens.
		if ( $fee_input_changed && 'reissue' === $fee_mode && '' !== sanitize_key( (string) ( $_POST['law_action'] ?? '' ) ) ) {
			law_committee_refuse(
				$event_id,
				$is_ajax,
				'Save the fee change on its own first: it voids the open invoice and raises a new one, so it cannot be combined with another action in the same save.'
			);
		}

		law_event_update_meta( $event_id, '_law_fee_override', $override_on );
		law_event_update_meta( $event_id, '_law_fee_override_amount', $amount_raw );
		law_event_log_fee_change( $event_id, $before_override, $before_amount, $actor );

		// Past approval the fee is not a field: the snapshot is re-frozen, the
		// invoice raised from the old one is voided and a replacement is sent
		// to the host. The warning box on the panel says so before they save.
		if ( $fee_input_changed && 'reissue' === $fee_mode ) {
			$applied = law_event_apply_fee_change( $event_id, $actor, 'ui' );
			if ( is_wp_error( $applied ) ) {
				// The red banner on the detail view, which the template prefers
				// over any notice: a voided invoice with no replacement is not
				// something to report as "Changes saved".
				set_transient( 'law_dashboard_error_' . $actor, $applied->get_error_message(), 60 );
			} elseif ( 'waived' === $applied['outcome'] ) {
				$fee_notice = 'fee-waived';
			} elseif ( 'reissued' === $applied['outcome'] ) {
				$fee_notice = 'fee-reissued';
			}
		}
	}
	if ( isset( $_POST['law_assignee'] ) ) {
		law_event_update_meta( $event_id, '_law_assignee', law_events_sanitize_assignee( wp_unslash( $_POST['law_assignee'] ) ) );
		law_event_maybe_notify_assignee( $event_id, $before_assignee, $actor );
	}
	// The slot select is not rendered for an event LAW manages itself, and
	// law_event_apply_slot_label() reads an empty label as "clear the dates",
	// which would delete _law_start and _law_end and drop the event off the
	// programme. wp-admin has carried this guard since the receptions hit the
	// bug on 14 September 2026 (admin/event-screen.php); this path never had
	// one, and external events would have been its third victim.
	if ( isset( $_POST['law_slot_label'] ) && ! law_event_is_managed_by_law( $event_id ) ) {
		$slot_label = sanitize_text_field( wp_unslash( $_POST['law_slot_label'] ) );
		$old_label  = (string) law_event_meta( $event_id, '_law_slot_label' );
		law_event_update_meta( $event_id, '_law_slot_label', $slot_label );
		law_event_apply_slot_label( $event_id, $slot_label );
		if ( $old_label !== $slot_label ) {
			law_event_log(
				$event_id,
				sprintf( 'Confirmed slot changed to "%s".', $slot_label ?: '(none)' ),
				array( 'action' => 'slot', 'old' => $old_label, 'new' => $slot_label, 'source' => 'ui' ),
				array( 'user_id' => $actor )
			);
		}
	}

	// Validated above. The band and the places available are the committee's at
	// every status: the places lock for a host from submission onwards and the
	// band at approval, so once an event is under review this panel and
	// wp-admin are the only places either can be changed.
	if ( $venue_present ) {
		$before_capacity = (string) law_event_meta( $event_id, '_law_venue_capacity' );
		$before_places   = (int) law_event_meta( $event_id, '_law_tickets_available' );
		// The venue itself (client, 16 September 2026). Not required, and not
		// validated: any address is better than none, and booking simply stays
		// shut until there is one. Logged because it is one of the three things
		// that decide whether booking opens.
		if ( isset( $_POST['law_venue'] ) ) {
			$before_venue = (string) law_event_meta( $event_id, '_law_venue' );
			law_event_update_meta( $event_id, '_law_venue', wp_unslash( $_POST['law_venue'] ) );
			$after_venue = (string) law_event_meta( $event_id, '_law_venue' );
			if ( $before_venue !== $after_venue ) {
				law_event_log(
					$event_id,
					sprintf( 'Venue changed to "%s".', $after_venue ?: '(none)' ),
					array( 'action' => 'venue', 'old' => $before_venue, 'new' => $after_venue, 'source' => 'ui' ),
					array( 'user_id' => $actor )
				);
			}
		}
		law_event_update_meta( $event_id, '_law_venue_capacity', $capacity_new );
		law_event_update_meta( $event_id, '_law_tickets_available', $tickets_new );
		law_event_log_capacity_change( $event_id, $before_capacity, $actor );
		// Writes the "Places available changed" line, and raising them is what
		// offers the new places to anyone waiting.
		law_event_tickets_changed(
			$event_id,
			$before_places,
			(int) law_event_meta( $event_id, '_law_tickets_available' ),
			$actor,
			'committee_panel'
		);
	}

	// The sentinel says the control was on the form, so an absent input means
	// "cleared" (an empty multi-select posts nothing).
	if ( ! empty( $_POST['law_orgs_present'] ) ) {
		$before_orgs = law_event_meta( $event_id, '_law_organisation_ids' );
		law_event_update_meta( $event_id, '_law_organisation_ids', array_map( 'absint', (array) ( $_POST['law_organisation_ids'] ?? array() ) ) );
		law_event_log_organisation_change( $event_id, (array) $before_orgs, $actor );
	}

	// The committee's two classification switches. Its own sentinel, not
	// law_orgs_present above: the groups must be independently absent-safe,
	// and an unticked checkbox posts nothing, so without a sentinel a flag
	// could be switched on and then never off.
	// Override booking availability, at the top of the panel. A select always
	// posts, so isset() is sentinel enough. Logged, because opening booking on
	// an event that has not paid, or closing it on a live one, is exactly the
	// kind of decision the committee has to be able to account for later.
	if ( isset( $_POST['law_booking_override'] ) ) {
		$before_override = law_event_booking_override( $event_id );
		law_event_update_meta( $event_id, '_law_booking_override', wp_unslash( $_POST['law_booking_override'] ) );
		law_event_log_booking_override_change( $event_id, $before_override, $actor );
	}

	if ( ! empty( $_POST['law_flags_present'] ) ) {
		$before_flags = array(
			'_law_is_external'   => (int) law_event_meta( $event_id, '_law_is_external' ),
			'_law_session_agenda' => (int) law_event_meta( $event_id, '_law_session_agenda' ),
		);
		law_event_update_meta( $event_id, '_law_is_external', ! empty( $_POST['law_is_external'] ) );
		law_event_update_meta( $event_id, '_law_session_agenda', ! empty( $_POST['law_session_agenda'] ) );

		// The confirmation override, only for an event that can carry one. The
		// panel hides the box otherwise, and writing the key regardless would
		// log a switch-off on an event that never had the switch.
		if ( law_event_override_slug_map( $event_id ) ) {
			$before_flags['_law_email_override'] = (int) law_event_meta( $event_id, '_law_email_override' );
			law_event_update_meta( $event_id, '_law_email_override', ! empty( $_POST['law_email_override'] ) );
		}
		law_event_log_flag_change( $event_id, $before_flags, $actor );

		// Which notice the redirect below should use. Turning the agenda on puts
		// a new section on a DIFFERENT screen (the edit form), so "Changes saved."
		// would leave the committee hunting for fields on this one; turning it
		// off while sessions exist does not remove the section, which reads as a
		// control with no effect unless we say so.
		$agenda_now = (int) law_event_meta( $event_id, '_law_session_agenda' );
		if ( $before_flags['_law_session_agenda'] !== $agenda_now ) {
			$agenda_notice = $agenda_now
				? 'agenda-on'
				: ( law_event_session_ids( $event_id ) ? 'agenda-off-kept' : 'agenda-off' );
		}
	}

	// A private note goes straight to the activity log (§3.6 manual notes).
	$private_note = trim( (string) wp_unslash( $_POST['law_private_note'] ?? '' ) );
	if ( '' !== $private_note ) {
		law_event_log( $event_id, $private_note, array( 'action' => 'note', 'source' => 'ui' ), array( 'manual' => true, 'user_id' => $actor ) );
	}

	// The fee first: voiding an invoice and emailing a replacement is the
	// biggest thing this save can have done, so it is what the page reports.
	$notice = $fee_notice ?? $agenda_notice ?? 'saved';
	$action = sanitize_key( $_POST['law_action'] ?? '' );

	// The AJAX caller is always a modal action, so an empty action means the
	// submitter's name/value never made it into the request body. Falling
	// through to the "Save changes" path would look like success while the
	// approval never happened, so refuse loudly instead. The field writes
	// above have already run, hence the wording.
	if ( $is_ajax && '' === $action ) {
		wp_send_json_error( array( 'message' => 'Your other changes were saved, but the action itself was not received. Please reload the page and try again.' ), 400 );
	}

	// Delete (trash) is not a workflow transition, so it is handled here, before
	// the workflow whitelist below would log it as a refused action. It is only
	// offered — and only accepted — on a Cancelled or Rejected event: trashing a
	// live event would orphan an open Stripe invoice with no void and no host
	// email, so cancel/reject must come first.
	if ( 'delete' === $action ) {
		if ( ! in_array( $post->post_status, array( 'law-cancelled', 'law-rejected' ), true ) ) {
			law_committee_refuse(
				$event_id,
				$is_ajax,
				'Only a cancelled or rejected event can be deleted. Cancel or reject it first.',
				403
			);
		}

		// The log line first: it must exist before the post leaves the dashboard.
		// Trash keeps the log (it lives in comments), so it survives a restore.
		law_event_log(
			$event_id,
			'Event moved to trash from the committee dashboard.',
			array( 'action' => 'trash', 'source' => 'ui' ),
			array( 'user_id' => $actor )
		);
		wp_trash_post( $event_id );

		// Back to the LIST view (no event= arg): the detail panel cannot load a
		// trashed post. Restoring and permanent deletion stay wp-admin jobs.
		if ( $is_ajax ) {
			wp_send_json_success(
				array(
					'title'    => 'Event deleted',
					'message'  => 'It can be restored from the wp-admin Events list. Reloading the page…',
					'redirect' => home_url( '/account/dashboard/' ),
				)
			);
		}
		wp_safe_redirect( add_query_arg( 'law_notice', 'event-deleted', home_url( '/account/dashboard/' ) ) );
		exit;
	}

	// Only the actions this screen actually offers may come from this POST.
	// Without the whitelist a hand-made request could run 'confirm' and publish
	// an approved event whose invoice is still Unpaid (open finding 3 in
	// EVENTS_FUNC.md §6). An empty action is the plain "Save changes".
	$allowed = law_event_ui_actions();
	if ( '' !== $action && ! in_array( $action, $allowed, true ) ) {
		law_event_log(
			$event_id,
			sprintf( 'Refused committee action "%s": not offered by the dashboard.', $action ),
			array( 'action' => 'refused', 'attempted' => $action, 'source' => 'ui' ),
			array( 'user_id' => $actor )
		);
		law_committee_refuse( $event_id, $is_ajax, 'That action is not available from the dashboard.', 403 );
	}
	if ( '' !== $action ) {
		$note   = trim( (string) wp_unslash( $_POST['law_note'] ?? '' ) );
		$result = law_event_workflow_transition(
			$event_id,
			$action,
			array( 'comment' => $note, 'reason' => $note, 'actor_id' => $actor )
		);
		if ( is_wp_error( $result ) ) {
			if ( $is_ajax ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}
			set_transient( 'law_dashboard_error_' . $actor, $result->get_error_message(), 60 );
			$notice = 'action-failed';
		} else {
			$notice = 'action-' . $action;
		}
	}

	if ( $is_ajax ) {
		$titles = array(
			'approve'   => 'Event approved',
			'send_back' => 'Event sent back to the host',
			'reject'    => 'Event rejected',
			'mark_paid' => 'Event marked as paid and confirmed',
			'cancel'    => 'Event cancelled',
		);
		wp_send_json_success(
			array(
				'title'   => $titles[ $action ] ?? 'Done',
				'message' => 'Reloading the page…',
				// No law_notice: the success dialog has already confirmed the
				// action, so the reloaded page should not banner it again.
				'redirect' => add_query_arg( 'event', $event_id, home_url( '/account/dashboard/' ) ),
			)
		);
	}

	wp_safe_redirect(
		add_query_arg(
			array( 'event' => $event_id, 'law_notice' => $notice ),
			home_url( '/account/dashboard/' )
		)
	);
	exit;
}

/** One-shot dashboard error message (refused transitions). */
function law_committee_take_error() {
	$key   = 'law_dashboard_error_' . get_current_user_id();
	$error = get_transient( $key );
	if ( $error ) {
		delete_transient( $key );
	}
	return (string) $error;
}
