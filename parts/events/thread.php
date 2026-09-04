<?php
/**
 * The event comment thread (EVENTS_4.1_REBUILD.md §3.5 host UX): message
 * bubbles with role badges and an inline reply box. Shared by the host view,
 * the committee detail view and (in list form) the admin screen.
 *
 * Args: event_id (required), context ('host' | 'committee').
 */

$law_thread_event = absint( $args['event_id'] ?? 0 );
$law_thread_post  = $law_thread_event ? get_post( $law_thread_event ) : null;
if ( ! $law_thread_post || LAW_EVENT_CPT !== $law_thread_post->post_type ) {
	return;
}
if ( ! law_user_can_manage_event( get_current_user_id(), $law_thread_event ) ) {
	echo '<p>Sorry, you are not allowed to view this conversation.</p>';
	return;
}

$law_thread_comments = law_event_comments( $law_thread_event );
$law_thread_status   = law_event_status_label( $law_thread_post );
$law_is_committee    = law_user_is_committee();
$law_notice          = sanitize_key( $_GET['law_notice'] ?? '' );
?>
<div class="law-thread-view">
	<header class="law-thread-view__header">
		<h2><?php echo esc_html( $law_thread_post->post_title ); ?></h2>
		<p>Status: <strong><?php echo esc_html( $law_thread_status ); ?></strong>
			· Reference: <code><?php echo esc_html( (string) law_event_meta( $law_thread_event, '_law_reference' ) ); ?></code></p>
	</header>

	<?php if ( 'comment-added' === $law_notice ) : ?>
		<div class="law-form-notice" role="status">Comment sent.</div>
	<?php elseif ( 'rate-limited' === $law_notice ) : ?>
		<div class="law-form-notice is-error" role="alert">Too many comments in a short time; please wait a moment.</div>
	<?php endif; ?>

	<?php if ( ! $law_thread_comments ) : ?>
		<p class="law-thread-empty">No comments yet. This thread is between you and the LAW committee.</p>
	<?php else : ?>
		<ol class="law-thread-list">
			<?php foreach ( $law_thread_comments as $law_comment ) :
				$law_from_committee = law_event_comment_is_committee( $law_comment );
				?>
				<li class="law-bubble <?php echo $law_from_committee ? 'is-committee' : 'is-host'; ?>">
					<div class="law-bubble__meta">
						<strong><?php echo esc_html( $law_comment->comment_author ); ?></strong>
						<span class="law-bubble__badge"><?php echo $law_from_committee ? 'Committee' : 'Host'; ?></span>
						<time datetime="<?php echo esc_attr( $law_comment->comment_date_gmt ); ?>"><?php echo esc_html( mysql2date( 'j F Y, H:i', $law_comment->comment_date ) ); ?></time>
					</div>
					<div class="law-bubble__body"><?php echo wp_kses_post( wpautop( $law_comment->comment_content ) ); ?></div>
				</li>
			<?php endforeach; ?>
		</ol>
	<?php endif; ?>

	<form class="law-thread-reply" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="law_event_comment_reply">
		<input type="hidden" name="event_id" value="<?php echo esc_attr( (string) $law_thread_event ); ?>">
		<?php wp_nonce_field( 'law_event_comment_reply' ); ?>
		<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>
		<label for="law-thread-reply-<?php echo esc_attr( (string) $law_thread_event ); ?>">Reply</label>
		<textarea id="law-thread-reply-<?php echo esc_attr( (string) $law_thread_event ); ?>" name="comment" rows="4" required></textarea>
		<p class="law-thread-reply__actions">
			<button type="submit" class="button orange">
				<?php echo ( ! $law_is_committee && 'Sent back' === $law_thread_status ) ? 'Reply & resubmit to the committee' : 'Send reply'; ?>
			</button>
			<?php if ( ! $law_is_committee && 'Sent back' === $law_thread_status ) : ?>
				<span class="law-thread-reply__note">Replying returns your event to the committee for review.</span>
			<?php endif; ?>
		</p>
	</form>
</div>
