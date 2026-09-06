<?php
/**
 * One thread message bubble. Shared by the thread loop (parts/events/thread.php)
 * and the AJAX reply response (law_event_handle_comment_reply()), so the
 * server-rendered and script-inserted markup cannot drift.
 *
 * Args: comment (WP_Comment, required).
 */

$law_bubble_comment = $args['comment'] ?? null;
if ( ! $law_bubble_comment instanceof WP_Comment ) {
	return;
}
$law_bubble_from_committee = law_event_comment_is_committee( $law_bubble_comment );
?>
<li class="law-bubble <?php echo $law_bubble_from_committee ? 'is-committee' : 'is-host'; ?>">
	<div class="law-bubble__meta">
		<strong><?php echo esc_html( $law_bubble_comment->comment_author ); ?></strong>
		<span class="law-bubble__badge"><?php echo $law_bubble_from_committee ? 'Committee' : 'Host'; ?></span>
		<time datetime="<?php echo esc_attr( $law_bubble_comment->comment_date_gmt ); ?>"><?php echo esc_html( mysql2date( 'j F Y, H:i', $law_bubble_comment->comment_date ) ); ?></time>
	</div>
	<div class="law-bubble__body"><?php echo wp_kses_post( wpautop( $law_bubble_comment->comment_content ) ); ?></div>
</li>
