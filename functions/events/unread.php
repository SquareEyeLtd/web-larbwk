<?php
/**
 * Unread-message toasts for hosts: tracks when a user last opened an event's
 * messages page (/account/events/?law_thread=<id>) and, while committee
 * messages remain unread, shows a bottom-right toast on every front-end page
 * linking to that thread. One toast per event, stacked. Hosts only —
 * committee members work from the dashboard and get no toasts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Comments older than this never raise a toast: stale threads (and the
 *  migration backlog, which imports years of comments nobody has "read" in
 *  the new sense) should not nag every host on day one. */
const LAW_THREAD_UNREAD_MAX_DAYS = 30;

/** User-meta key holding the unix time the user last opened an event's thread. */
function law_event_thread_read_key( $event_id ) {
	return 'law_thread_read_' . (int) $event_id;
}

/** Record that a user has opened an event's messages page. */
function law_event_thread_mark_read( $event_id, $user_id = 0 ) {
	$event_id = (int) $event_id;
	$user_id  = $user_id ? (int) $user_id : get_current_user_id();
	if ( $event_id < 1 || $user_id < 1 ) {
		return;
	}
	update_user_meta( $user_id, law_event_thread_read_key( $event_id ), time() );
}

/**
 * Opening the messages page is what marks a thread read. template_redirect
 * runs before wp_enqueue_scripts and wp_footer, so the unread computation
 * below already sees the marker on the same page view (no toast for the
 * thread you are looking at).
 */
add_action( 'template_redirect', function () {
	if ( 'cpt' !== law_events_source() || ! is_page_template( 'templates/account-events.php' ) ) {
		return;
	}
	$event_id = absint( $_GET['law_thread'] ?? 0 );
	if ( $event_id && law_user_can_manage_event( get_current_user_id(), $event_id ) ) {
		law_event_thread_mark_read( $event_id );
	}
} );

/**
 * Events with committee messages the current user has not read.
 *
 * Only committee-authored comments count — a host's own (or a co-owner's)
 * replies never raise a toast — and only comments newer than both the read
 * marker and the LAW_THREAD_UNREAD_MAX_DAYS floor.
 *
 * @param int $user_id User to check (defaults to the current user).
 * @return array<int, array{count:int, latest_id:int, title:string, url:string}>
 *               Keyed by event ID, newest thread first.
 */
function law_event_unread_threads( $user_id = 0 ) {
	static $cache = array();

	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( isset( $cache[ $user_id ] ) ) {
		return $cache[ $user_id ];
	}
	$cache[ $user_id ] = array();

	if ( $user_id < 1 || 'cpt' !== law_events_source() || law_user_is_committee( $user_id ) ) {
		return $cache[ $user_id ];
	}

	$event_ids = law_events_owned_event_ids( $user_id );
	if ( ! $event_ids ) {
		return $cache[ $user_id ];
	}

	$comments = get_comments(
		array(
			'post__in' => array_map( 'intval', $event_ids ),
			'type'     => LAW_EVENT_COMMENT_TYPE,
			'status'   => 'approve',
			'orderby'  => 'comment_date_gmt',
			'order'    => 'DESC',
		)
	);

	$floor_default = time() - LAW_THREAD_UNREAD_MAX_DAYS * DAY_IN_SECONDS;

	foreach ( $comments as $comment ) {
		$event_id = (int) $comment->comment_post_ID;
		if ( (int) $comment->user_id === $user_id || ! law_event_comment_is_committee( $comment ) ) {
			continue;
		}

		$read_at = (int) get_user_meta( $user_id, law_event_thread_read_key( $event_id ), true );
		if ( strtotime( $comment->comment_date_gmt . ' +0000' ) <= max( $read_at, $floor_default ) ) {
			continue;
		}

		if ( ! isset( $cache[ $user_id ][ $event_id ] ) ) {
			$post = get_post( $event_id );
			$url  = function_exists( 'law_account_event_comments_url' )
				? law_account_event_comments_url( $event_id )
				: '';
			if ( ! $post || '' === $url ) {
				continue;
			}
			$cache[ $user_id ][ $event_id ] = array(
				'count'     => 0,
				'latest_id' => (int) $comment->comment_ID, // Comments arrive newest first.
				'title'     => $post->post_title,
				'url'       => $url,
			);
		}
		$cache[ $user_id ][ $event_id ]['count']++;
	}

	return $cache[ $user_id ];
}

/** Enqueue the toast assets only when there is something to show. */
add_action( 'wp_enqueue_scripts', function () {
	if ( ! law_event_unread_threads() ) {
		return;
	}
	wp_enqueue_style( 'law-toasts', get_theme_file_uri( 'assets/css/law-toasts.css' ), array(), filemtime( get_theme_file_path( 'assets/css/law-toasts.css' ) ) );
	wp_enqueue_script( 'law-toasts', get_theme_file_uri( 'assets/js/law-toasts.js' ), array(), filemtime( get_theme_file_path( 'assets/js/law-toasts.js' ) ), true );
} );

/** The toast stack, one toast per event with unread committee messages. */
add_action( 'wp_footer', function () {
	$threads = law_event_unread_threads();
	if ( ! $threads ) {
		return;
	}
	?>
	<div class="law-toasts">
		<?php foreach ( $threads as $event_id => $thread ) : ?>
			<div class="law-toast" role="status"
				data-key="<?php echo esc_attr( $event_id . ':' . $thread['latest_id'] ); ?>">
				<a class="law-toast__link" href="<?php echo esc_url( $thread['url'] ); ?>">
					<span class="law-toast__label">
						<?php
						echo esc_html(
							1 === $thread['count']
								? __( 'New message from the committee', 'law' )
								/* translators: %d: number of unread committee messages. */
								: sprintf( __( '%d new messages from the committee', 'law' ), $thread['count'] )
						);
						?>
					</span>
					<span class="law-toast__event"><?php echo esc_html( $thread['title'] ); ?></span>
					<span class="law-toast__cta"><?php esc_html_e( 'Read and reply', 'law' ); ?></span>
				</a>
				<button type="button" class="law-toast__dismiss" aria-label="<?php esc_attr_e( 'Dismiss notification', 'law' ); ?>">&times;</button>
			</div>
		<?php endforeach; ?>
	</div>
	<?php
} );
