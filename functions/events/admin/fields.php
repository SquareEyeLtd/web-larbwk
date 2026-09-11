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
 * @param string $add_label Label for the add-row button.
 */
function law_field_repeater( $name, $label, array $rows, array $columns, $add_label = 'Add row' ) {
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

	echo '</tbody></table><p><button type="button" class="button law-row-add">' . esc_html( $add_label ) . '</button></p></div>';
}

/**
 * Relationship picker with AJAX search: stores rows of {speaker_id, role,
 * organisation, job_title, photo_id, bio} (or plain IDs when $simple). The
 * role (a Speaker / Host / Moderator select, law_speaker_roles()),
 * organisation, job title, photo and biography are the speaker's appearance at
 * THIS event/session, not properties of the speaker post.
 *
 * @param string $name      Base field name.
 * @param string $label     Group label.
 * @param array  $rows      Existing speaker rows (or int IDs when $simple).
 * @param string $post_type Searched post type.
 * @param bool   $simple    IDs only (no role/org columns).
 * @param array  $args      Optional:
 *                          'render_row' callable( $name, $i, array $row ) that
 *                          replaces law_field_relationship_row() for every row.
 *                          The Flagship screen uses it to render its own
 *                          "new speaker" rows, which carry identity fields and
 *                          no speaker_id yet, alongside ordinary picked rows.
 *                          'after_list' a callable that PRINTS whatever should
 *                          follow the chosen list, e.g. its "Add new speaker"
 *                          button. A callable rather than a string of markup
 *                          on purpose: a string would have to be echoed
 *                          unescaped, and "the caller escaped it" is a
 *                          convention the next caller can break by
 *                          interpolating a title or a search term into it.
 *                          Owning its own output means each caller escapes at
 *                          the point it prints.
 */
function law_field_relationship( $name, $label, array $rows, $post_type, $simple = false, array $args = array() ) {
	$render_row = isset( $args['render_row'] ) && is_callable( $args['render_row'] ) ? $args['render_row'] : null;
	$after_list = isset( $args['after_list'] ) && is_callable( $args['after_list'] ) ? $args['after_list'] : null;

	echo '<div class="law-field law-rel" data-law-rel data-law-rel-type="' . esc_attr( $post_type ) . '" data-law-rel-name="' . esc_attr( $name ) . '" data-law-rel-simple="' . ( $simple ? '1' : '0' ) . '">';
	echo '<strong>' . esc_html( $label ) . '</strong>';
	echo '<p><input type="search" class="regular-text law-rel-search" placeholder="Search…" autocomplete="off"><span class="spinner"></span></p>';
	echo '<ul class="law-rel-results" hidden></ul>';
	echo '<ol class="law-rel-chosen" data-law-rel-chosen>';
	foreach ( $rows as $i => $row ) {
		// The empty-ID skip belongs to the DEFAULT renderer only: a custom one
		// may legitimately render a row that has no post behind it yet, and
		// dropping it here would lose what the user typed when a save comes
		// back with a validation error.
		if ( $render_row ) {
			call_user_func( $render_row, $name, $i, $simple ? array( 'speaker_id' => (int) $row ) : (array) $row );
			continue;
		}
		$id = $simple ? (int) $row : (int) ( $row['speaker_id'] ?? 0 );
		if ( ! $id ) {
			continue;
		}
		law_field_relationship_row( $name, $i, $id, $simple ? array() : (array) $row, $simple );
	}
	echo '</ol>';
	// The photo guidance for every row, once on the group rather than once per
	// row: the per-row control is a 32px thumbnail and two link buttons, and a
	// line of help text under each would be noise. Once here also keeps it out
	// of law-admin.js, which rebuilds the row markup for a row added via the
	// search and would otherwise need its own copy of the sentence.
	// $simple rows are plain IDs with no photo control, so they get nothing.
	if ( ! $simple ) {
		echo '<p class="description law-form-hint">JPG, PNG or WebP. Square photos at least 600 pixels across work best.</p>';
	}
	if ( $after_list ) {
		call_user_func( $after_list );
	}
	echo '</div>';
}

function law_field_relationship_row( $name, $i, $id, array $row, $simple ) {
	$title = get_the_title( $id ) ?: ( '#' . $id );
	echo '<li class="law-rel-item">';
	printf( '<span class="law-rel-title">%s</span>', esc_html( $title ) );
	if ( $simple ) {
		printf( '<input type="hidden" name="%s[]" value="%d">', esc_attr( $name ), (int) $id );
	} else {
		printf( '<input type="hidden" name="%s[%d][speaker_id]" value="%d">', esc_attr( $name ), (int) $i, (int) $id );
		// The role at this event. There is no default (Denis, 9 September 2026): a
		// stored '' (rows saved before roles existed, or simply left unset) selects
		// the blank "Select role" placeholder. On a session row '' still means
		// "inherit the event's" at read time, which an explicit choice overrides.
		$role    = law_speaker_role_key( $row['role'] ?? '' );
		$options = sprintf( '<option value=""%s>Select role</option>', selected( $role, '', false ) );
		foreach ( law_speaker_roles() as $key => $label ) {
			$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $role, $key, false ), esc_html( $label ) );
		}
		printf(
			'<select name="%s[%d][role]" class="law-rel-role" aria-label="Role at this event">%s</select>',
			esc_attr( $name ),
			(int) $i,
			$options
		);
		printf(
			'<input type="text" name="%s[%d][organisation]" value="%s" placeholder="Organisation at this event" class="law-rel-org">',
			esc_attr( $name ),
			(int) $i,
			esc_attr( (string) ( $row['organisation'] ?? '' ) )
		);
		printf(
			'<input type="text" name="%s[%d][job_title]" value="%s" placeholder="Job title at this event" class="law-rel-job">',
			esc_attr( $name ),
			(int) $i,
			esc_attr( (string) ( $row['job_title'] ?? '' ) )
		);
		law_field_relationship_photo( $name, $i, (int) ( $row['photo_id'] ?? 0 ) );
		// Last, and on its own full-width line (law-admin.css): the biography
		// this speaker gave for this event. Empty here means the single event
		// view falls back to the speaker post's editor content.
		// Rich text, the same editor the host's own form and the committee's
		// Manage speakers screen give this field (functions/events/rich-text.php).
		// law-admin.js builds the same markup for a row added via the search.
		law_rich_text_field(
			array(
				'name'  => sprintf( '%s[%d][bio]', $name, (int) $i ),
				'id'    => sprintf( 'law-rel-bio-%s-%d', sanitize_html_class( $name ), (int) $i ),
				'value' => (string) ( $row['bio'] ?? '' ),
				'rows'  => 3,
				'class' => 'law-rel-bio',
				'label' => 'Biography for this event',
			)
		);
	}
	echo '<button type="button" class="button-link-delete law-rel-remove" aria-label="Remove">×</button></li>';
}

/**
 * The per-appearance photo control inside a relationship row: hidden
 * attachment ID, thumbnail, Choose (wp.media, wired in law-admin.js) and
 * Remove. law-admin.js builds the same markup for rows added via search.
 */
function law_field_relationship_photo( $name, $i, $photo_id, $field_name = '' ) {
	// $i is an int for a real row and a placeholder string ("__j__") inside a
	// clone template, so it is escaped as a string, never cast to int, which
	// would silently turn every template row into row 0.
	//
	// $field_name overrides the composed name for a caller whose rows are not
	// keyed $name[$i] (the Flagship screen's "new speaker" rows sit under a
	// session index as well, so they compose their own). It exists so that
	// screen can call this rather than keep its own copy of the markup, which
	// had already drifted out of step with the JS that rebuilds it.
	$thumb = $photo_id ? (string) wp_get_attachment_image_url( $photo_id, 'thumbnail' ) : '';
	$field = '' !== (string) $field_name ? (string) $field_name : sprintf( '%s[%s][photo_id]', $name, (string) $i );
	echo '<span class="law-rel-photo" data-law-rel-photo>';
	printf( '<input type="hidden" name="%s" value="%d" class="law-rel-photo-id">', esc_attr( $field ), (int) $photo_id );
	printf( '<img class="law-rel-photo-thumb" src="%s" alt="" width="32" height="32"%s>', esc_url( $thumb ), $thumb ? '' : ' hidden' );
	echo '<button type="button" class="button-link law-rel-photo-choose">' . ( $thumb ? 'Change photo' : 'Choose photo' ) . '</button>';
	echo '<button type="button" class="button-link law-rel-photo-clear"' . ( $thumb ? '' : ' hidden' ) . '>Remove photo</button>';
	echo '</span>';
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
	// The per-appearance biography is rich text here too, so a committee edit in
	// wp-admin does not flatten what a host wrote. Only the two screens that
	// render a relationship row (law_field_relationship()) need the editor: the
	// event screen and the session screen.
	if ( $screen && in_array( $screen->post_type ?? '', array( LAW_EVENT_CPT, LAW_SESSION_CPT ), true ) ) {
		law_rich_text_enqueue();
	}
	wp_enqueue_style( 'law-events-admin', get_theme_file_uri( 'assets/css/law-admin.css' ), array(), '1.6' );
	wp_enqueue_script( 'law-events-admin', get_theme_file_uri( 'assets/js/law-admin.js' ), array(), '1.6', true );
	wp_localize_script(
		'law-events-admin',
		'lawEventsAdmin',
		array(
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'law_events_admin' ),
			// The relationship row's Role select choices, so a row added via the
			// search carries the same options as law_field_relationship_row().
			'roleChoices' => law_speaker_roles(),
		)
	);
} );
