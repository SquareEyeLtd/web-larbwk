<?php
/**
 * law_speaker admin screen: contact fields (custom meta box), a fallback photo
 * via the core featured image box, a fallback biography via the editor,
 * related events read-only. The biography, organisation, job title and photo
 * that actually appear on a listing are per event, on the event's Speakers
 * rows.
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
	// The name in two parts (Denis, 9 September 2026). A record created before
	// the split has none stored, so its title is split for the prefill; saving
	// writes both and rebuilds the title above from them.
	$name = law_speaker_name_parts( (int) $post->ID );
	law_field_text( 'law_speaker_first_name', 'First name', $name['first'] );
	law_field_text( 'law_speaker_last_name', 'Last name', $name['last'] );
	echo '<p class="description">The title above is the display name every listing prints; it is rebuilt from these two fields on save.</p>';
	law_field_text( 'law_speaker_email', 'Email (the dedupe key)', (string) law_event_meta( $post->ID, '_law_speaker_email' ), array( 'type' => 'email' ) );
	law_field_text( 'law_website', 'Website profile URL', (string) law_event_meta( $post->ID, '_law_website' ), array( 'type' => 'url' ) );
	echo '<p class="description">Biography, organisation, job title and photo are per event: edit them on each event (or session) under Speakers. The editor content and featured image here are only fallbacks, used for an event whose Speakers row leaves them blank.</p>';
}

function law_speaker_box_events( $post ) {
	$map    = law_speakers_confirmed_event_map();
	$events = $map[ (int) $post->ID ] ?? array();
	if ( ! $events ) {
		echo '<p>Not on any confirmed event. The speaker only appears publicly while a confirmed event references them.</p>';
		return;
	}
	// Earliest first: the first row is what the speakers archive card shows.
	$ordered = wp_list_pluck( law_speaker_appearances( (int) $post->ID ), 'event_id' );
	echo '<ul class="law-speaker-appearances">';
	foreach ( $ordered as $event_id ) {
		$seen  = law_speaker_appearance_for_event( (int) $post->ID, (int) $event_id ) ?: array( 'role' => '', 'organisation' => '', 'job_title' => '', 'photo_id' => 0 );
		$thumb = $seen['photo_id'] ? (string) wp_get_attachment_image_url( $seen['photo_id'], 'thumbnail' ) : '';
		$what  = implode( ', ', array_filter( array( law_speaker_role_display( $seen['role'] ), $seen['job_title'], $seen['organisation'] ) ) );
		printf(
			'<li>%s<a href="%s">%s</a>%s</li>',
			$thumb ? '<img src="' . esc_url( $thumb ) . '" alt="" width="24" height="24" style="vertical-align:middle;margin-right:6px;border-radius:2px"> ' : '',
			esc_url( get_edit_post_link( $event_id ) ),
			esc_html( get_the_title( $event_id ) ),
			$what ? '<br><span class="description">' . esc_html( $what ) . '</span>' : ''
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
		'law_speaker_first_name' => '_law_speaker_first_name',
		'law_speaker_last_name'  => '_law_speaker_last_name',
		'law_speaker_email'      => '_law_speaker_email',
		'law_website'            => '_law_website',
	) as $field => $key ) {
		if ( isset( $_POST[ $field ] ) ) {
			law_event_update_meta( $post_id, $key, wp_unslash( $_POST[ $field ] ) );
		}
	}

	// Keep the display name in step with the parts just saved. The static guard
	// stops the wp_update_post() below re-entering this same save_post hook; the
	// empty check stops a record that has never had parts losing its title.
	static $renaming = false;
	if ( $renaming || ( ! isset( $_POST['law_speaker_first_name'] ) && ! isset( $_POST['law_speaker_last_name'] ) ) ) {
		return;
	}
	$name = law_speaker_full_name(
		(string) law_event_meta( $post_id, '_law_speaker_first_name' ),
		(string) law_event_meta( $post_id, '_law_speaker_last_name' )
	);
	if ( '' !== $name && $name !== trim( (string) get_post_field( 'post_title', $post_id ) ) ) {
		$renaming = true;
		wp_update_post( array( 'ID' => $post_id, 'post_title' => $name ) );
		$renaming = false;
	}
} );
