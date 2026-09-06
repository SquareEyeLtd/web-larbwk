<?php
/**
 * Shared custom field renderers for the module's wp-admin meta boxes
 * (custom-built, not ACF — settled decision). Each helper prints one
 * labelled control; saving goes through law_event_update_meta().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function law_field_text( $name, $label, $value, $args = array() ) {
	printf(
		'<p class="law-field"><label for="%1$s"><strong>%2$s</strong></label><br><input type="%4$s" id="%1$s" name="%1$s" value="%3$s" class="%5$s" %6$s></p>',
		esc_attr( $name ),
		esc_html( $label ),
		esc_attr( $value ),
		esc_attr( $args['type'] ?? 'text' ),
		esc_attr( $args['class'] ?? 'regular-text' ),
		! empty( $args['attrs'] ) ? $args['attrs'] : '' // Caller-supplied, static strings only.
	);
}

function law_field_number( $name, $label, $value, $args = array() ) {
	$args['type']  = 'number';
	$args['class'] = $args['class'] ?? 'small-text';
	law_field_text( $name, $label, $value, $args );
}

function law_field_textarea( $name, $label, $value, $rows = 4 ) {
	printf(
		'<p class="law-field"><label for="%1$s"><strong>%2$s</strong></label><br><textarea id="%1$s" name="%1$s" rows="%4$d" class="large-text">%3$s</textarea></p>',
		esc_attr( $name ),
		esc_html( $label ),
		esc_textarea( $value ),
		(int) $rows
	);
}

/**
 * @param array $choices value => label.
 */
function law_field_select( $name, $label, $value, array $choices, $args = array() ) {
	$options = '';
	if ( ! empty( $args['placeholder'] ) ) {
		$options .= '<option value="">' . esc_html( $args['placeholder'] ) . '</option>';
	}
	foreach ( $choices as $choice_value => $choice_label ) {
		$options .= sprintf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $choice_value ),
			selected( (string) $value, (string) $choice_value, false ),
			esc_html( $choice_label )
		);
	}
	printf(
		'<p class="law-field"><label for="%1$s"><strong>%2$s</strong></label><br><select id="%1$s" name="%1$s">%3$s</select></p>',
		esc_attr( $name ),
		esc_html( $label ),
		$options // Escaped above.
	);
}

function law_field_checkbox( $name, $label, $checked ) {
	printf(
		'<p class="law-field"><label><input type="checkbox" name="%1$s" value="1" %3$s> <strong>%2$s</strong></label></p>',
		esc_attr( $name ),
		esc_html( $label ),
		checked( (bool) $checked, true, false )
	);
}

function law_field_datetime( $name, $label, $value ) {
	$formatted = '';
	if ( $value ) {
		$ts        = strtotime( (string) $value );
		$formatted = $ts ? gmdate( 'Y-m-d\TH:i', $ts ) : '';
	}
	law_field_text( $name, $label, $formatted, array( 'type' => 'datetime-local', 'class' => '' ) );
}

/**
 * Media-library photo picker: stores an attachment ID, previews the image.
 */
function law_field_media( $name, $label, $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	$preview       = $attachment_id ? wp_get_attachment_image( $attachment_id, array( 80, 80 ) ) : '';
	printf(
		'<div class="law-field law-field-media" data-law-media>
			<strong>%2$s</strong><br>
			<span class="law-media-preview">%3$s</span>
			<input type="hidden" name="%1$s" value="%4$d">
			<button type="button" class="button law-media-choose">Choose photo</button>
			<button type="button" class="button law-media-remove" %5$s>Remove</button>
		</div>',
		esc_attr( $name ),
		esc_html( $label ),
		$preview, // Core-generated markup.
		$attachment_id,
		$attachment_id ? '' : 'style="display:none"'
	);
}

/**
 * Repeatable rows of simple inputs.
 *
 * @param string $name    Base field name; rows post as name[i][subfield].
 * @param string $label   Group label.
 * @param array  $rows    Existing row values.
 * @param array  $columns subfield => [label, type].
 */
function law_field_repeater( $name, $label, array $rows, array $columns ) {
	echo '<div class="law-field law-repeater" data-law-repeater><strong>' . esc_html( $label ) . '</strong>';
	echo '<table class="widefat striped"><thead><tr>';
	foreach ( $columns as $column ) {
		echo '<th>' . esc_html( $column['label'] ) . '</th>';
	}
	echo '<th></th></tr></thead><tbody data-law-rows>';

	$rows[] = array(); // Blank template row, hidden by JS and cloned.
	foreach ( $rows as $i => $row ) {
		$is_template = $i === count( $rows ) - 1;
		echo '<tr' . ( $is_template ? ' data-law-template style="display:none"' : '' ) . '>';
		foreach ( $columns as $subfield => $column ) {
			printf(
				'<td><input type="%s" name="%s" value="%s" class="widefat" %s></td>',
				esc_attr( $column['type'] ?? 'text' ),
				esc_attr( $is_template ? '' : "{$name}[{$i}][{$subfield}]" ),
				esc_attr( (string) ( $row[ $subfield ] ?? '' ) ),
				$is_template ? 'data-law-name="' . esc_attr( "{$name}[__i__][{$subfield}]" ) . '"' : ''
			);
		}
		echo '<td><button type="button" class="button-link-delete law-row-remove" aria-label="Remove row">×</button></td></tr>';
	}

	echo '</tbody></table><p><button type="button" class="button law-row-add">Add row</button></p></div>';
}

/**
 * Relationship picker with AJAX search: stores rows of {speaker_id, role,
 * organisation_override} (or plain IDs when $simple).
 *
 * @param string $name      Base field name.
 * @param string $label     Group label.
 * @param array  $rows      Existing speaker rows (or int IDs when $simple).
 * @param string $post_type Searched post type.
 * @param bool   $simple    IDs only (no role/org columns).
 */
function law_field_relationship( $name, $label, array $rows, $post_type, $simple = false ) {
	echo '<div class="law-field law-rel" data-law-rel data-law-rel-type="' . esc_attr( $post_type ) . '" data-law-rel-name="' . esc_attr( $name ) . '" data-law-rel-simple="' . ( $simple ? '1' : '0' ) . '">';
	echo '<strong>' . esc_html( $label ) . '</strong>';
	echo '<p><input type="search" class="regular-text law-rel-search" placeholder="Search…" autocomplete="off"><span class="spinner"></span></p>';
	echo '<ul class="law-rel-results" hidden></ul>';
	echo '<ol class="law-rel-chosen" data-law-rel-chosen>';
	foreach ( $rows as $i => $row ) {
		$id = $simple ? (int) $row : (int) ( $row['speaker_id'] ?? 0 );
		if ( ! $id ) {
			continue;
		}
		law_field_relationship_row( $name, $i, $id, $simple ? array() : (array) $row, $simple );
	}
	echo '</ol></div>';
}

function law_field_relationship_row( $name, $i, $id, array $row, $simple ) {
	$title = get_the_title( $id ) ?: ( '#' . $id );
	echo '<li class="law-rel-item">';
	printf( '<span class="law-rel-title">%s</span>', esc_html( $title ) );
	if ( $simple ) {
		printf( '<input type="hidden" name="%s[]" value="%d">', esc_attr( $name ), (int) $id );
	} else {
		printf( '<input type="hidden" name="%s[%d][speaker_id]" value="%d">', esc_attr( $name ), (int) $i, (int) $id );
		printf(
			'<input type="text" name="%s[%d][role]" value="%s" placeholder="Role (Speaker / Moderator / Host)" class="law-rel-role">',
			esc_attr( $name ),
			(int) $i,
			esc_attr( (string) ( $row['role'] ?? '' ) )
		);
		printf(
			'<input type="text" name="%s[%d][organisation_override]" value="%s" placeholder="Organisation override" class="law-rel-org">',
			esc_attr( $name ),
			(int) $i,
			esc_attr( (string) ( $row['organisation_override'] ?? '' ) )
		);
	}
	echo '<button type="button" class="button-link-delete law-rel-remove" aria-label="Remove">×</button></li>';
}

/* AJAX search behind the relationship picker ________________________________ */

add_action( 'wp_ajax_law_events_search_posts', function () {
	check_ajax_referer( 'law_events_admin' );
	if ( ! current_user_can( 'edit_law_events' ) ) {
		wp_send_json_error( 'forbidden', 403 );
	}

	$post_type = sanitize_key( $_GET['post_type'] ?? '' );
	if ( ! in_array( $post_type, array( LAW_SPEAKER_CPT, LAW_EVENT_CPT, 'organisation' ), true ) ) {
		wp_send_json_error( 'bad type', 400 );
	}

	$posts = get_posts(
		array(
			'post_type'      => $post_type,
			'post_status'    => LAW_EVENT_CPT === $post_type ? law_event_all_status_keys() : 'publish',
			's'              => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
			'posts_per_page' => 10,
			'orderby'        => 'title',
			'order'          => 'ASC',
		)
	);

	wp_send_json_success(
		array_map(
			fn( $p ) => array( 'id' => $p->ID, 'title' => $p->post_title ),
			$posts
		)
	);
} );

/* Admin assets ______________________________________________________________ */

add_action( 'admin_enqueue_scripts', function ( $hook ) {
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	$module_types = array( LAW_EVENT_CPT, LAW_SPEAKER_CPT, LAW_SESSION_CPT );
	$is_module    = ( $screen && in_array( $screen->post_type ?? '', $module_types, true ) )
		|| str_contains( (string) $hook, 'law-' );
	if ( ! $is_module ) {
		return;
	}

	wp_enqueue_media();
	wp_enqueue_style( 'law-events-admin', get_theme_file_uri( 'assets/css/law-admin.css' ), array(), '1.1' );
	wp_enqueue_script( 'law-events-admin', get_theme_file_uri( 'assets/js/law-admin.js' ), array(), '1.1', true );
	wp_localize_script(
		'law-events-admin',
		'lawEventsAdmin',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'law_events_admin' ),
		)
	);
} );
