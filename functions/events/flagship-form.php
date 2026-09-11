<?php
/**
 * The flagship conference's session agenda fields, shared by both editing
 * screens: the wp-admin Flagship screen (admin/flagship-screen.php) and the
 * committee's front-end Manage flagship dashboard (flagship-dashboard.php).
 *
 * The sessions repeater is the complicated half of the form — a monotonic
 * clone counter, a rich-text description per row, and a speaker picker with an
 * "add new speaker" sub-form inside each row — so it exists once here and both
 * screens print it. `assets/js/law-flagship-admin.js` and the `.law-rel-*`
 * pickers in `assets/js/law-admin.js` drive it in both places, which is why the
 * front-end dashboard enqueues those two admin scripts rather than growing a
 * second implementation of the same behaviour.
 *
 * The markup is deliberately class-neutral (`law-row`, `law-field`,
 * `law-rel-*`): each screen supplies its own surrounding styles.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The sessions repeater.
 *
 * @param array  $sessions Session rows from law_flagship_form_values().
 * @param string $heading  An <h2> above the block, or '' for none. The wp-admin
 *                         screen wants one; the committee dashboard already
 *                         names the block in its fieldset legend, and printing
 *                         both gave that screen two "Sessions" headings in a
 *                         row (spotted on the live screen, 9 September 2026).
 */
function law_flagship_render_sessions( array $sessions, $heading = 'Sessions' ) {
	if ( '' !== (string) $heading ) {
		printf( '<h2>%s</h2>', esc_html( (string) $heading ) );
	}
	echo '<p class="description">Break the day into sessions. Each session lists its own speakers: search for someone who already has a profile, or add a new speaker. The event\'s start and end times are worked out from these sessions.</p>';

	// The counter is monotonic, unlike a row count: removing a middle row and
	// adding another must not reuse an index, or the two rows would post into
	// the same slot and one would be lost.
	$next = count( $sessions );
	printf( '<div class="law-flagship-sessions" data-law-flagship-sessions data-law-counter="%d">', (int) $next );

	foreach ( $sessions as $i => $session ) {
		law_flagship_render_session( (string) $i, $session, false );
	}
	// The clone template, last and hidden. data-law-row-template is the
	// attribute law-rich-text.js checks before attaching an editor, so the
	// template's textarea stays inert until the row is real.
	law_flagship_render_session(
		'__i__',
		array( 'id' => 0, 'title' => '', 'start' => '', 'end' => '', 'description' => '', 'speakers' => array() ),
		true
	);

	echo '</div>';
	echo '<p><button type="button" class="button law-row-add">Add session</button></p>';
}

/**
 * One session row. $i is the row index, or the literal '__i__' in the clone
 * template, where every field carries data-name instead of name so a hidden
 * row posts nothing.
 */
function law_flagship_render_session( $i, array $session, $is_template ) {
	$base = 'law_flagship[sessions][' . $i . ']';
	$attr = $is_template ? 'data-name' : 'name';

	printf(
		'<div class="law-row law-flagship-session" data-law-row%s>',
		$is_template ? ' data-law-row-template hidden' : ''
	);

	echo '<button type="button" class="button-link-delete law-row-remove" aria-label="Remove session">×</button>';

	printf(
		'<input type="hidden" %s="%s" value="%d">',
		esc_attr( $attr ),
		esc_attr( $base . '[id]' ),
		(int) ( $session['id'] ?? 0 )
	);

	// The event forms' own markup vocabulary: a .law-row-grid of <label>Field
	// name<input></label> pairs, with .law-row-wide for anything full width
	// (parts/events/event-form-fields.php). Deliberately not the admin field
	// library's <p class="law-field"><strong>…</strong><br> shape, which stacked
	// a block label, a <br> and a margin into a gap twice the size it should be
	// (Denis, 9 September 2026), and which looks nothing like the form the
	// committee already knows.
	echo '<div class="law-row-grid law-flagship-session__head">';
	printf(
		'<label class="law-row-wide">Session title *<input type="text" %s="%s" value="%s"></label>',
		esc_attr( $attr ),
		esc_attr( $base . '[title]' ),
		esc_attr( (string) ( $session['title'] ?? '' ) )
	);
	printf(
		'<label class="law-field--time">Start time *<input type="time" %s="%s" value="%s"></label>',
		esc_attr( $attr ),
		esc_attr( $base . '[start]' ),
		esc_attr( (string) ( $session['start'] ?? '' ) )
	);
	printf(
		'<label class="law-field--time">End time<input type="time" %s="%s" value="%s"></label>',
		esc_attr( $attr ),
		esc_attr( $base . '[end]' ),
		esc_attr( (string) ( $session['end'] ?? '' ) )
	);
	echo '</div>';

	echo '<div class="law-row-wide law-flagship-session__description"><span class="law-form-label">Description</span>';
	law_rich_text_field(
		array(
			'name'     => $base . '[description]',
			'id'       => $is_template ? '' : 'law-flagship-session-' . sanitize_html_class( (string) $i ) . '-description',
			'value'    => (string) ( $session['description'] ?? '' ),
			'rows'     => 4,
			'template' => (bool) $is_template,
			'label'    => 'Session description',
		)
	);
	echo '</div>';

	law_flagship_render_speaker_picker( $base . '[speakers]', (array) ( $session['speakers'] ?? array() ), $is_template );

	echo '</div>';
}

/**
 * The session's speakers: the module's own relationship picker (search over
 * law_speaker posts via wp_ajax_law_events_search_posts), plus an "Add new
 * speaker" button that inserts a row carrying the identity fields the host
 * form collects.
 */
function law_flagship_render_speaker_picker( $base_name, array $rows, $is_template ) {
	law_field_relationship(
		$base_name,
		'Speakers',
		$rows,
		LAW_SPEAKER_CPT,
		false,
		array(
			'render_row' => function ( $name, $i, array $row ) {
				if ( ! empty( $row['is_new'] ) ) {
					law_flagship_render_new_speaker_row( $name, (string) $i, $row );
					return;
				}
				$id = absint( $row['speaker_id'] ?? 0 );
				if ( ! $id ) {
					return;
				}
				law_field_relationship_row( $name, $i, $id, $row, false );
			},
			'after_list' => function () {
				echo '<p><button type="button" class="button-link law-rel-add-new">'
					. esc_html__( 'Add new speaker', 'law' ) . '</button></p>';
			},
		)
	);
	unset( $is_template );
}

/**
 * A speaker who has no profile yet: identity fields (which
 * law_speaker_upsert() turns into a law_speaker record on save) alongside the
 * same per-appearance controls an ordinary picked row carries.
 *
 * $j may be the literal '__j__', because this same function renders the clone
 * template, so nothing here casts the index to int.
 */
function law_flagship_render_new_speaker_row( $name, $j, array $row ) {
	$field = function ( $key ) use ( $name, $j ) {
		return sprintf( '%s[%s][%s]', $name, $j, $key );
	};

	$photo_id = absint( $row['photo_id'] ?? 0 );

	echo '<li class="law-rel-item is-new">';
	echo '<span class="law-rel-title">New speaker</span>';
	printf( '<input type="hidden" name="%s" value="1">', esc_attr( $field( 'is_new' ) ) );

	// The event forms' layout, field for field: a .law-row-grid of
	// <label>Name<input></label> pairs with .law-row-wide for the full-width
	// ones (parts/events/event-form-fields.php). The photo is a labelled cell of
	// its own rather than a bare link floating beside the job title, which is
	// what looked wrong (Denis, 9 September 2026).
	echo '<div class="law-row-grid law-rel-new-fields">';
	printf(
		'<label>First name *<input type="text" autocomplete="off" name="%s" value="%s"></label>',
		esc_attr( $field( 'first_name' ) ),
		esc_attr( (string) ( $row['first_name'] ?? '' ) )
	);
	printf(
		'<label>Last name *<input type="text" autocomplete="off" name="%s" value="%s"></label>',
		esc_attr( $field( 'last_name' ) ),
		esc_attr( (string) ( $row['last_name'] ?? '' ) )
	);

	$role    = law_speaker_role_key( $row['role'] ?? '' );
	$options = sprintf( '<option value=""%s>Select role</option>', selected( $role, '', false ) );
	foreach ( law_speaker_roles() as $key => $label ) {
		$options .= sprintf( '<option value="%s"%s>%s</option>', esc_attr( $key ), selected( $role, $key, false ), esc_html( $label ) );
	}
	printf(
		'<label>Role at this event<select name="%s" class="law-rel-role">%s</select></label>',
		esc_attr( $field( 'role' ) ),
		$options // Escaped above.
	);
	printf(
		'<label>Email<input type="email" autocomplete="off" name="%s" value="%s"></label>',
		esc_attr( $field( 'email' ) ),
		esc_attr( (string) ( $row['email'] ?? '' ) )
	);
	printf(
		'<label>Organisation at this event<input type="text" autocomplete="off" name="%s" value="%s" class="law-rel-org"></label>',
		esc_attr( $field( 'organisation' ) ),
		esc_attr( (string) ( $row['organisation'] ?? '' ) )
	);
	printf(
		'<label>Job title at this event<input type="text" autocomplete="off" name="%s" value="%s" class="law-rel-job"></label>',
		esc_attr( $field( 'job_title' ) ),
		esc_attr( (string) ( $row['job_title'] ?? '' ) )
	);
	printf(
		'<label>Website profile URL<input type="url" autocomplete="off" name="%s" value="%s"></label>',
		esc_attr( $field( 'website' ) ),
		esc_attr( (string) ( $row['website'] ?? '' ) )
	);

	// The photo, as its own labelled cell. Same media-library control as every
	// other appearance photo on these two screens (law-admin.js binds
	// [data-law-rel-photo]), so a committee member picks an image they have
	// already uploaded rather than re-uploading it.
	echo '<span class="law-field-photo"><span class="law-form-label">Photo for this event</span>';
	law_field_relationship_photo( '', 0, $photo_id, $field( 'photo_id' ) );
	echo '</span>';

	echo '<div class="law-row-wide"><span class="law-form-label">Biography for this event</span>';
	law_rich_text_field(
		array(
			'name'  => $field( 'bio' ),
			'value' => (string) ( $row['bio'] ?? '' ),
			'rows'  => 3,
			'class' => 'law-rel-bio',
			'label' => 'Biography for this event',
		)
	);
	echo '</div>';

	echo '<p class="law-rel-hint law-row-wide">Add the email address if you have it: a row with one links to the existing profile for that address instead of creating a duplicate.</p>';
	echo '</div>';

	echo '<button type="button" class="button-link-delete law-rel-remove" aria-label="Remove">×</button>';
	echo '</li>';
}

/**
 * The markup law-flagship-admin.js clones for an "Add new speaker" click, with
 * __name__ and __j__ standing in for the picker's field name and the row index.
 * Inside a <template> the content is inert, so TinyMCE never attaches to it.
 */
function law_flagship_render_new_speaker_template() {
	echo '<template id="law-flagship-new-speaker">';
	law_flagship_render_new_speaker_row(
		'__name__',
		'__j__',
		array(
			'is_new'       => true,
			'first_name'   => '',
			'last_name'    => '',
			'email'        => '',
			'website'      => '',
			'role'         => '',
			'organisation' => '',
			'job_title'    => '',
			'photo_id'     => 0,
			'bio'          => '',
		)
	);
	echo '</template>';
}
