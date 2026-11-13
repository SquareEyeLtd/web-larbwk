<?php
/**
 * Private events (Denis, 13 November 2026).
 *
 * "Make the event private, so it'll remove it from the events calendar
 * archive. Also it'll make it available only by special link with query
 * ?c=[short code here]." A hosted event (the Committee controls panel) or a
 * reception (its editor) can be ticked private. A private event:
 *
 * - is off every public LIST of events: the programme and its day tabs, the
 *   speakers archive and profiles, the SEOPress sitemap and any post-type
 *   archive or feed WordPress would build for law_event;
 * - keeps its own page, which opens only with ?c=<its code>. Anyone without
 *   it is sent to the programme, except the people who can act on the event
 *   (host, co-owners, committee), who keep the page as they do for a disabled
 *   one;
 * - still takes bookings exactly as before. It is about who can FIND the
 *   event, not who may book it: whoever was sent the link registers as usual.
 *
 * The code goes onto every link WordPress builds for the event
 * (post_type_link below), so everything that already links to an event with
 * get_permalink() carries it with no change: the confirmation email's
 * {event_link}, My bookings, the Register dialog's own URLs, the Stripe return
 * address, the waitlist emails, the .ics description, the committee's View
 * links. That is also why every public list must leave a private event out:
 * a list that showed one would hand its code to everybody.
 *
 * Not a status, and independent of Disable this event: a disabled event is off
 * every surface including its own page, whatever this says. Not carried by the
 * content transfer bundle, like _law_disabled: a local committee decision.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The query parameter the code travels in: /events/<slug>/?c=<code>. */
const LAW_PRIVATE_CODE_PARAM = 'c';

/**
 * Has the committee made this event private?
 *
 * @param int|WP_Post $event Event ID or post.
 */
function law_event_is_private( $event ) {
	$post = get_post( $event );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return false;
	}
	return (bool) law_event_meta( $post->ID, '_law_private' );
}

/** The event's stored code, or '' when it has never been made private. */
function law_event_private_code( $event_id ) {
	return (string) law_event_meta( (int) $event_id, '_law_private_code' );
}

/**
 * A fresh code: ten lower-case letters and digits, without the look-alikes
 * (0/o, 1/l/i), so it survives being read aloud or retyped from a printout.
 * 31^10 is about 8 x 10^14, which no one is going to guess one at a time
 * through a page that redirects.
 */
function law_event_private_code_generate() {
	$alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
	$max      = strlen( $alphabet ) - 1;
	$code     = '';
	for ( $i = 0; $i < 10; $i++ ) {
		$code .= $alphabet[ random_int( 0, $max ) ];
	}
	return $code;
}

/**
 * Switch an event private or back. THE single write path, shared by the
 * Committee controls panel, the receptions editor and the wp-admin Event flags
 * box. Logging is the caller's, through law_event_log_flag_change(), so the
 * switch lands in the same one-line entry as the other flags saved with it.
 *
 * The code is made on the first tick and then KEPT, also when the box is
 * unticked: links already sent out keep working if the event goes private
 * again, rather than every invitation going dead on a re-tick.
 *
 * @param int  $event_id law_event post ID.
 * @param bool $on       Private or not.
 */
function law_event_set_private( $event_id, $on ) {
	$event_id = (int) $event_id;
	law_event_update_meta( $event_id, '_law_private', (bool) $on );
	if ( $on && '' === law_event_private_code( $event_id ) ) {
		law_event_update_meta( $event_id, '_law_private_code', law_event_private_code_generate() );
	}
}

/**
 * Does a code someone typed or followed open this event? Compared in constant
 * time, case-insensitively (a code read off paper may come back in capitals).
 *
 * @param int    $event_id law_event post ID.
 * @param string $given    The ?c= value as received.
 */
function law_event_private_code_matches( $event_id, $given ) {
	$code = law_event_private_code( $event_id );
	if ( '' === $code || ! is_string( $given ) ) {
		return false;
	}
	return hash_equals( $code, strtolower( trim( $given ) ) );
}

/**
 * The private link to hand out: the address the event has, or WILL have once
 * it is live, with its code. law_events_public_url() rather than
 * get_permalink(), which on a draft answers ?post_type=law_event&p=995.
 *
 * @param int|WP_Post $event Event ID or post.
 * @return string '' when the event has no code yet.
 */
function law_event_private_url( $event ) {
	$post = get_post( $event );
	if ( ! $post ) {
		return '';
	}
	$code = law_event_private_code( $post->ID );
	if ( '' === $code ) {
		return '';
	}
	return add_query_arg( LAW_PRIVATE_CODE_PARAM, $code, law_events_public_url( $post ) );
}

/**
 * The code rides on every link WordPress builds for a private event, so every
 * existing get_permalink() caller (emails, My bookings, the booking dialog,
 * Stripe's return URL, the committee's View buttons) keeps working unchanged.
 */
add_filter(
	'post_type_link',
	function ( $link, $post ) {
		if ( ! $post instanceof WP_Post || LAW_EVENT_CPT !== $post->post_type || ! law_event_is_private( $post ) ) {
			return $link;
		}
		$code = law_event_private_code( $post->ID );
		return '' === $code ? $link : add_query_arg( LAW_PRIVATE_CODE_PARAM, $code, $link );
	},
	10,
	2
);

/** The public programme page, where a visitor without the code is sent. */
function law_events_programme_url() {
	$page_id = law_events_programme_page_id();
	return $page_id ? (string) get_permalink( $page_id ) : home_url( '/programme/' );
}

/**
 * May this viewer open this private event's page? The rule, separated from the
 * redirect so it can be tested without exiting.
 *
 * @param int    $event_id law_event post ID.
 * @param int    $user_id  Viewer, 0 when signed out.
 * @param string $given    The ?c= value, '' when absent.
 */
function law_event_private_page_allowed( $event_id, $user_id, $given ) {
	if ( ! law_event_is_private( $event_id ) ) {
		return true;
	}
	if ( $user_id && law_user_can_manage_event( (int) $user_id, (int) $event_id ) ) {
		return true;
	}
	return law_event_private_code_matches( $event_id, (string) $given );
}

/**
 * Without the right code a private event's page sends the visitor to the
 * programme (Denis, 13 November 2026), rather than a 404: the visitor is
 * probably somebody who heard of the event and should land somewhere useful.
 *
 * Priority 3, after the disabled gate at the same priority (source.php loads
 * first): a disabled event has already been made a 404, which clears
 * is_singular(), so this never redirects one. Uncached either way: the
 * redirect must not be stored against the bare address for somebody who later
 * arrives with the code, and the page itself must not be served from a cache
 * to somebody who arrives without one.
 */
function law_events_gate_private_event_page() {
	if ( 'cpt' !== law_events_source() || ! is_singular( LAW_EVENT_CPT ) ) {
		return;
	}
	$event_id = get_queried_object_id();
	if ( ! law_event_is_private( $event_id ) ) {
		return;
	}

	nocache_headers();
	if ( ! defined( 'DONOTCACHEPAGE' ) ) {
		define( 'DONOTCACHEPAGE', true );
	}

	$given = isset( $_GET[ LAW_PRIVATE_CODE_PARAM ] ) ? sanitize_text_field( wp_unslash( $_GET[ LAW_PRIVATE_CODE_PARAM ] ) ) : '';
	if ( law_event_private_page_allowed( $event_id, get_current_user_id(), $given ) ) {
		// A shared link must not end up in a search index.
		header( 'X-Robots-Tag: noindex' );
		return;
	}

	wp_safe_redirect( law_events_programme_url(), 302 );
	exit;
}
add_action( 'template_redirect', 'law_events_gate_private_event_page', 3 );

/**
 * WordPress answers /wp-json/oembed/1.0/embed?url=<the BARE address> with the
 * event's title and its permalink, which carries the code: anyone who guessed
 * the slug would get the private link back. No embed for a private event; the
 * endpoint then answers 404, as for an unpublished post.
 */
add_filter(
	'oembed_response_data',
	function ( $data, $post ) {
		return $post instanceof WP_Post && law_event_is_private( $post ) ? false : $data;
	},
	// Last: SEOPress filters the same data later and turns a false back into
	// an array carrying the title.
	PHP_INT_MAX,
	2
);

/**
 * The comments feed <link> in the page head is the permalink plus "feed/",
 * which on a private event lands after the query string (?c=…/feed/). Events
 * take no comments, so a private one simply does not advertise the feed.
 */
add_filter(
	'feed_links_extra_show_post_comments_feed',
	function ( $show ) {
		return is_singular( LAW_EVENT_CPT ) && law_event_is_private( get_queried_object_id() ) ? false : $show;
	}
);

/* Off every public list ______________________________________________________ */

/**
 * IDs of every private event, for the list queries below that need them as a
 * post__not_in. Memoised per request; tests pass $reset.
 *
 * @param bool $reset Rebuild.
 * @return int[]
 */
function law_private_event_ids( $reset = false ) {
	static $ids = null;
	if ( null === $ids || $reset ) {
		$ids = array_map(
			'intval',
			get_posts(
				array(
					'post_type'        => LAW_EVENT_CPT,
					'post_status'      => 'any',
					'fields'           => 'ids',
					'posts_per_page'   => 500,
					'meta_key'         => '_law_private', // phpcs:ignore WordPress.DB.SlowDBQuery
					'meta_value'       => 1, // phpcs:ignore WordPress.DB.SlowDBQuery
					'suppress_filters' => true,
					'no_found_rows'    => true,
				)
			)
		);
	}
	return $ids;
}

/**
 * The SEOPress sitemap builds its URLs with get_permalink(), which would print
 * each private event's code into a public file.
 */
add_filter(
	'seopress_sitemaps_single_query',
	function ( $args, $path = '' ) {
		if ( LAW_EVENT_CPT !== $path ) {
			return $args;
		}
		$private = law_private_event_ids();
		if ( $private ) {
			$args['post__not_in'] = array_merge( isset( $args['post__not_in'] ) ? (array) $args['post__not_in'] : array(), $private );
		}
		return $args;
	},
	10,
	2
);

/**
 * law_event is publicly_queryable, so /?post_type=law_event and its feed list
 * every published event with its permalink. Nothing links there, but anyone
 * can type it, and a private event's link carries its code.
 */
add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}
		if ( ! in_array( LAW_EVENT_CPT, (array) $query->get( 'post_type' ), true ) ) {
			return;
		}
		$private = law_private_event_ids();
		if ( $private ) {
			$query->set( 'post__not_in', array_merge( (array) $query->get( 'post__not_in' ), $private ) );
		}
	}
);
