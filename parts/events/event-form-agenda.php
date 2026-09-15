<?php
/**
 * The Session agenda repeater, shared by every form that collects sessions.
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

<fieldset id="law-section-agenda">
	<legend>Session agenda</legend>
	<input type="hidden" name="law_sessions_present" value="1">
	<?php if ( 'committee' === $law_context ) : ?>
		<p class="law-form-hint">Break the running order into sessions, then tick which of the event's speakers appear in each one. The list follows the Speakers section above. The host can edit this too.</p>
	<?php else : ?>
		<p class="law-form-hint">The committee has enabled a session agenda for your event. Break the running order into sessions below, then tick which of your speakers appear in each one. The list follows the Speakers section above.</p>
	<?php endif; ?>
	<?php
	// The speaker names this event currently has, from the same values
	// the Speakers repeater renders. Sessions choose from THIS list
	// (form 9 field 6 Speakers was a multiselect populated from the
	// form 8 entries, never free text), and event-form.js keeps the
	// list in step as speaker rows are typed, added or removed.
	// Keyed by the speaker row's own index, not by the name: the name is what
	// gets posted and matched, but it is also what a host may be editing, so a
	// tick has to survive a rename. event-form.js rebuilds this list on every
	// keystroke and re-ticks by the same key.
	$law_session_speaker_names = array();
	foreach ( array_values( (array) $law_value( 'speakers', array() ) ) as $law_sp_i => $law_sp_row ) {
		// The full name, however the row spells it: the picker posts names and
		// the saver matches them against the event's speaker rows by name.
		$law_sp_parts = law_speaker_row_name_parts( (array) $law_sp_row );
		$law_sp_name  = law_speaker_full_name( $law_sp_parts['first'], $law_sp_parts['last'] );
		if ( '' !== $law_sp_name && ! in_array( $law_sp_name, $law_session_speaker_names, true ) ) {
			$law_session_speaker_names[ (string) $law_sp_i ] = $law_sp_name;
		}
	}
	?>
	<div class="law-rows" data-law-rows-group="sessions">
		<?php
		$law_session_rows   = array_values( (array) $law_value( 'sessions', array() ) );
		$law_session_rows[] = array();
		foreach ( $law_session_rows as $law_i => $law_row ) :
			$law_is_template = $law_i === count( $law_session_rows ) - 1;
			?>
			<div class="law-row" <?php echo $law_is_template ? 'data-law-row-template hidden' : ''; ?>>
				<button type="button" class="law-row-remove" aria-label="Remove session">×</button>
				<?php
				// The session's post ID, so a save updates the existing law_session
				// in place. Blank on a new row, and the clone blanks it again on the
				// template row; the saver only honours an ID this event already owns.
				$law_session_id = (int) ( $law_row['id'] ?? 0 );
				?>
				<input type="hidden" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][id]" value="<?php echo esc_attr( $law_session_id ?: '' ); ?>">
				<div class="law-row-grid">
					<label class="law-row-wide">Session title *<input type="text" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][title]" value="<?php echo esc_attr( (string) ( $law_row['title'] ?? '' ) ); ?>"></label>
					<label>Start time *<input type="time" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][start]" value="<?php echo esc_attr( (string) ( $law_row['start'] ?? '' ) ); ?>"></label>
					<label>End time<input type="time" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][end]" value="<?php echo esc_attr( (string) ( $law_row['end'] ?? '' ) ); ?>"></label>
					<div class="law-row-wide"><span class="law-form-label">Description *</span>
						<?php
						law_rich_text_field(
							array(
								'name'     => 'sessions[' . ( $law_is_template ? '__i__' : $law_i ) . '][description]',
								'value'    => (string) ( $law_row['description'] ?? '' ),
								'rows'     => 3,
								'template' => $law_is_template,
								'label'    => 'Session description',
							)
						);
						?>
					</div>
					<?php
					// Two shapes reach here. A stored session exposes its speakers as a
					// comma-separated string of names, which is matched on a normalised
					// name (case, spacing and entity spelling all differ between the
					// stored name and the picker's, and a tick that fails to render is
					// a link the next save silently drops). A re-render after a failed
					// save instead carries the raw POST, which is an array of
					// "row:<index>" values matched on the key itself.
					$law_row_speakers = array();
					$law_row_keys     = array();
					if ( is_array( $law_row['speakers'] ?? null ) ) {
						foreach ( $law_row['speakers'] as $law_tick ) {
							if ( preg_match( '/^row:(\d+)$/', trim( (string) $law_tick ), $law_tick_match ) ) {
								$law_row_keys[] = $law_tick_match[1];
							} else {
								$law_row_speakers[] = law_speaker_normalise_name( $law_tick );
							}
						}
					} else {
						$law_row_speakers = array_map(
							'law_speaker_normalise_name',
							array_filter( array_map( 'trim', explode( ',', (string) ( $law_row['speakers'] ?? '' ) ) ) )
						);
					}
					?>
					<div class="law-row-wide law-session-speakers" data-law-session-speakers>
						<span class="law-form-label">Speakers</span>
						<div class="law-choices" data-law-session-speaker-list>
							<?php foreach ( $law_session_speaker_names as $law_sp_key => $law_sp_name ) : ?>
								<label><input type="checkbox"
									<?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][speakers][]"
									data-law-speaker-key="<?php echo esc_attr( (string) $law_sp_key ); ?>"
									value="row:<?php echo esc_attr( (string) $law_sp_key ); ?>"
									<?php checked( in_array( (string) $law_sp_key, $law_row_keys, true ) || in_array( law_speaker_normalise_name( $law_sp_name ), $law_row_speakers, true ) ); ?>>
									<?php echo esc_html( $law_sp_name ); ?></label>
							<?php endforeach; ?>
						</div>
						<p class="law-form-hint" data-law-session-speakers-empty <?php echo $law_session_speaker_names ? 'hidden' : ''; ?>>Add speakers in the Speakers section above and they will appear here.</p>
					</div>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php $law_error_message( 'sessions' ); ?>
	<button type="button" class="button law-row-add" data-law-add="sessions">Add a session</button>
</fieldset>
