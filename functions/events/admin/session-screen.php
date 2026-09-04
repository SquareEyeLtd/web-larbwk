<?php
/**
 * law_session admin screen: parent event, start/end times, session speakers.
 * Title = post title, description = editor content.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'add_meta_boxes_' . LAW_SESSION_CPT, function () {
	add_meta_box( 'law-session-details', 'Session details', 'law_session_box_details', LAW_SESSION_CPT, 'normal', 'high' );
} );

function law_session_box_details( $post ) {
	wp_nonce_field( 'law_session_admin_save', 'law_session_admin_nonce' );

	$events = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => law_event_all_status_keys(),
			'posts_per_page' => 300,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);
	$choices = array();
	foreach ( $events as $event ) {
		$choices[ $event->ID ] = $event->post_title . ' (' . law_event_status_label( $event ) . ')';
	}
	law_field_select( 'law_session_parent', 'Event', (string) $post->post_parent, $choices, array( 'placeholder' => 'Choose the event…' ) );
	law_field_text( 'law_start_time', 'Start time (HH:MM)', (string) law_event_meta( $post->ID, '_law_start_time' ), array( 'class' => 'small-text', 'attrs' => 'placeholder="09:00"' ) );
	law_field_text( 'law_end_time', 'End time (HH:MM)', (string) law_event_meta( $post->ID, '_law_end_time' ), array( 'class' => 'small-text', 'attrs' => 'placeholder="10:30"' ) );
	law_field_relationship( 'law_speakers', 'Session speakers', law_event_meta( $post->ID, '_law_speakers' ), LAW_SPEAKER_CPT );
}

add_action( 'save_post_' . LAW_SESSION_CPT, function ( $post_id, $post ) {
	if ( ! isset( $_POST['law_session_admin_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['law_session_admin_nonce'] ), 'law_session_admin_save' )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| wp_is_post_revision( $post_id )
		|| ! current_user_can( 'edit_law_events' )
	) {
		return;
	}

	law_event_update_meta( $post_id, '_law_start_time', wp_unslash( $_POST['law_start_time'] ?? '' ) );
	law_event_update_meta( $post_id, '_law_end_time', wp_unslash( $_POST['law_end_time'] ?? '' ) );
	law_event_update_meta( $post_id, '_law_speakers', law_events_rows_from_post( 'law_speakers' ) );

	$parent = absint( $_POST['law_session_parent'] ?? 0 );
	if ( $parent && $parent !== (int) $post->post_parent && get_post_type( $parent ) === LAW_EVENT_CPT ) {
		// Avoid save_post recursion.
		remove_action( 'save_post_' . LAW_SESSION_CPT, __FUNCTION__ );
		wp_update_post( array( 'ID' => $post_id, 'post_parent' => $parent ) );
	}
}, 10, 2 );
