<?php
/**
 * The Speakers repeater, shared by every form that collects speakers.
 *
 * Extracted from parts/events/event-form-fields.php on 15 September 2026 so the
 * committee's external-event form (parts/events/external-manage.php) renders
 * the same repeater as the host and committee event forms rather than a second
 * copy of it. The args are that file's args, unchanged, and the two closures
 * below are the same two it declares — the house idiom for a form partial.
 *
 * Args:
 * - post    WP_Post|null  The event being edited (null on a new one).
 * - values  array         law_events_form_values() output.
 * - errors  array         law_events_form_state()['errors'].
 * - locked  array         law_events_locked_fields( $post, user ).
 * - context string        'host' (default), 'committee' or 'external' — copy only.
 */

$law_post    = $args['post'] ?? null;
$law_values  = (array) ( $args['values'] ?? array() );
$law_errors  = (array) ( $args['errors'] ?? array() );
$law_locked  = (array) ( $args['locked'] ?? array() );
$law_context = (string) ( $args['context'] ?? 'host' );

$law_error_message = function ( $field ) use ( $law_errors ) {
	if ( isset( $law_errors[ $field ][0] ) ) {
		echo '<span class="law-form-error" role="alert">' . esc_html( $law_errors[ $field ][0] ) . '</span>';
	}
};
$law_value = function ( $key, $default = '' ) use ( $law_values ) {
	return $law_values[ $key ] ?? $default;
};
?>

<fieldset id="law-section-speakers">
	<legend>Speakers</legend>
	<p class="law-form-hint">Add each speaker once. If they have spoken at LAW before, the email address links them to their existing profile automatically.</p>
		<?php foreach ( $law_errors as $law_ekey => $law_evals ) : ?>
			<?php if ( 0 === strpos( (string) $law_ekey, 'speaker_photo_' ) && isset( $law_evals[0] ) ) : ?>
				<p class="law-form-error" role="alert"><?php echo esc_html( $law_evals[0] ); ?></p>
			<?php endif; ?>
		<?php endforeach; ?>
	<div class="law-rows" data-law-rows-group="speakers">
		<?php
		// array_values first: a re-render after a failed save carries the POSTED
		// row indexes, which are not contiguous once a row has been removed, and
		// the "last row is the template" test below counts rather than reads keys.
		$law_speaker_rows   = array_values( (array) $law_value( 'speakers', array() ) );
		$law_speaker_rows[] = array(); // Blank template row.
		foreach ( $law_speaker_rows as $law_i => $law_row ) :
			$law_is_template = $law_i === count( $law_speaker_rows ) - 1;
			?>
			<div class="law-row" <?php echo $law_is_template ? 'data-law-row-template hidden' : ''; ?>>
				<button type="button" class="law-row-remove" aria-label="Remove speaker">×</button>
				<?php
				// The speaker post this row edits. Round-tripped so a corrected name
				// or email updates the right record instead of matching a new one;
				// the save honours it only for a speaker this event already holds,
				// so a forged ID reaches nothing. Blank on the template row: a newly
				// added speaker is matched or created, never edited.
				if ( ! $law_is_template && ! empty( $law_row['speaker_id'] ) ) :
					?>
					<input type="hidden" name="speakers[<?php echo esc_attr( $law_i ); ?>][speaker_id]" value="<?php echo esc_attr( (string) (int) $law_row['speaker_id'] ); ?>">
				<?php endif; ?>
				<div class="law-row-grid">
					<?php
					// First and last name, separately (Denis, 9 September 2026), the
					// shape form 8 (Event > speaker) always had: field 1.3 (Name, First)
					// and field 1.6 (Name, Last). A row saved before the split falls back
					// to the stored full name split on its last word.
					$law_row_name = law_speaker_row_name_parts( (array) $law_row );
					?>
					<label>First name *<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][first_name]" value="<?php echo esc_attr( $law_row_name['first'] ); ?>"></label>
					<label>Last name *<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][last_name]" value="<?php echo esc_attr( $law_row_name['last'] ); ?>"></label>
					<?php
					// The role at THIS event (Speaker / Host / Moderator), a per-appearance
					// detail like the organisation. There is no default (Denis,
					// 9 September 2026): a row with no role selects the blank "Select role"
					// placeholder rather than implying Speaker. The placeholder is also what
					// a cloned template row lands on, since event-form.js resets a cloned
					// select to its first option rather than to ''.
					$law_row_role = law_speaker_role_key( $law_row['role'] ?? '' );
					?>
					<label>Role<select <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][role]">
						<option value="" <?php selected( $law_row_role, '' ); ?>>Select role</option>
						<?php foreach ( law_speaker_roles() as $law_role_key => $law_role_label ) : ?>
							<option value="<?php echo esc_attr( $law_role_key ); ?>" <?php selected( $law_row_role, $law_role_key ); ?>><?php echo esc_html( $law_role_label ); ?></option>
						<?php endforeach; ?>
					</select></label>
					<label>Email *<input type="email" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][email]" value="<?php echo esc_attr( (string) ( $law_row['email'] ?? '' ) ); ?>"></label>
					<label>Organisation / firm / chambers<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][organisation]" value="<?php echo esc_attr( (string) ( $law_row['organisation'] ?? '' ) ); ?>"></label>
					<label>Job title<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][job_title]" value="<?php echo esc_attr( (string) ( $law_row['job_title'] ?? '' ) ); ?>"></label>
					<label>Website profile URL<input type="url" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][website]" value="<?php echo esc_attr( (string) ( $law_row['website'] ?? '' ) ); ?>"></label>
					<?php
					// The photo already on this event's row (display only: the save
					// carries it forward by speaker, it never trusts a posted ID).
					$law_row_photo = ! $law_is_template && ! empty( $law_row['photo_id'] ) ? wp_get_attachment_image_url( (int) $law_row['photo_id'], 'thumbnail' ) : '';
					?>
					<label>Photo<input type="file" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speaker_photo[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>]" accept="<?php echo esc_attr( law_events_photo_accept() ); ?>"><button type="button" class="law-file-clear" hidden>Clear photo</button>
						<?php if ( $law_row_photo ) : ?>
							<input type="hidden" name="speakers[<?php echo esc_attr( $law_i ); ?>][photo_id]" value="<?php echo esc_attr( (string) (int) $law_row['photo_id'] ); ?>">
							<span class="law-current-photo"><img src="<?php echo esc_url( $law_row_photo ); ?>" alt="" width="40" height="40"> Current photo for this event (upload a new file to replace it)</span>
						<?php endif; ?>
						<?php
						/* The limits, spelled out under the whole control rather than
						   crammed into the label: the pixel bounds were enforced but
						   never mentioned, so a big photo failed on a rule nobody had
						   been shown. */
						?>
						<span class="law-form-hint"><?php echo esc_html( law_events_photo_hint() ); ?></span>
					</label>
					<div class="law-row-wide"><span class="law-form-label">Biography</span>
						<?php
						law_rich_text_field(
							array(
								'name'     => 'speakers[' . ( $law_is_template ? '__i__' : $law_i ) . '][bio]',
								'value'    => (string) ( $law_row['bio'] ?? '' ),
								'rows'     => 3,
								'template' => $law_is_template,
								'label'    => 'Biography',
							)
						);
						?>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php $law_error_message( 'speakers' ); ?>
	<button type="button" class="button law-row-add" data-law-add="speakers">Add a speaker</button>
</fieldset>
