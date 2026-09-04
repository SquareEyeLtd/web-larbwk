<?php
/**
 * law_speaker admin screen: contact fields (custom meta box), photo via the
 * core featured image box, biography via the editor, related events read-only.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'add_meta_boxes_' . LAW_SPEAKER_CPT, function () {
	add_meta_box( 'law-speaker-details', 'Speaker details', 'law_speaker_box_details', LAW_SPEAKER_CPT, 'normal', 'high' );
	add_meta_box( 'law-speaker-events', 'Appears at', 'law_speaker_box_events', LAW_SPEAKER_CPT, 'side' );
} );

function law_speaker_box_details( $post ) {
	wp_nonce_field( 'law_speaker_admin_save', 'law_speaker_admin_nonce' );
	law_field_text( 'law_speaker_email', 'Email (the dedupe key)', (string) law_event_meta( $post->ID, '_law_speaker_email' ), array( 'type' => 'email' ) );
	law_field_text( 'law_organisation', 'Organisation / firm / chambers', (string) law_event_meta( $post->ID, '_law_organisation' ) );
	law_field_text( 'law_job_title', 'Job title / role', (string) law_event_meta( $post->ID, '_law_job_title' ) );
	law_field_text( 'law_website', 'Website profile URL', (string) law_event_meta( $post->ID, '_law_website' ), array( 'type' => 'url' ) );
	echo '<p class="description">The photo is the featured image; the biography is the main editor content.</p>';
}

function law_speaker_box_events( $post ) {
	$map    = law_speakers_confirmed_event_map();
	$events = $map[ (int) $post->ID ] ?? array();
	if ( ! $events ) {
		echo '<p>Not on any confirmed event. The speaker only appears publicly while a confirmed event references them.</p>';
		return;
	}
	echo '<ul>';
	foreach ( $events as $event_id ) {
		printf(
			'<li><a href="%s">%s</a></li>',
			esc_url( get_edit_post_link( $event_id ) ),
			esc_html( get_the_title( $event_id ) )
		);
	}
	echo '</ul>';
}

add_action( 'save_post_' . LAW_SPEAKER_CPT, function ( $post_id ) {
	if ( ! isset( $_POST['law_speaker_admin_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['law_speaker_admin_nonce'] ), 'law_speaker_admin_save' )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| ! current_user_can( 'edit_law_events' )
	) {
		return;
	}

	foreach ( array(
		'law_speaker_email' => '_law_speaker_email',
		'law_organisation'  => '_law_organisation',
		'law_job_title'     => '_law_job_title',
		'law_website'       => '_law_website',
	) as $field => $key ) {
		if ( isset( $_POST[ $field ] ) ) {
			law_event_update_meta( $post_id, $key, wp_unslash( $_POST[ $field ] ) );
		}
	}
} );
