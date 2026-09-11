<?php
/**
 * The shared submission/edit form fieldsets (Event details, Speakers, Venue,
 * Owners & contacts, Fees, Session agenda), consumed by both the host form
 * (templates/account-event-form.php) and the committee edit view
 * (parts/events/committee-event-form.php) so the two cannot drift. The
 * Finish fieldset (terms + buttons) stays in each template — it is the part
 * that genuinely differs between the two.
 *
 * Args:
 * - post    WP_Post|null  The event being edited (null on a new submission).
 * - values  array         law_events_form_values() output.
 * - errors  array         law_events_form_state()['errors'].
 * - locked  array         law_events_locked_fields( $post, user ).
 * - context string        'host' (default) or 'committee' — copy/voice only;
 *                         the lock list itself already encodes who may edit what.
 */

$law_post    = $args['post'] ?? null;
$law_values  = (array) ( $args['values'] ?? array() );
$law_errors  = (array) ( $args['errors'] ?? array() );
$law_locked  = (array) ( $args['locked'] ?? array() );
$law_context = ( $args['context'] ?? 'host' ) === 'committee' ? 'committee' : 'host';

$law_error_message = function ( $field ) use ( $law_errors ) {
	if ( isset( $law_errors[ $field ][0] ) ) {
		// A <span> (not <p>): these render inside p.law-form-field, and a nested
		// <p> would be auto-closed by the parser, orphaning the message from
		// its field and breaking the invalid-field highlight.
		echo '<span class="law-form-error" role="alert">' . esc_html( $law_errors[ $field ][0] ) . '</span>';
	}
};
$law_value = function ( $key, $default = '' ) use ( $law_values ) {
	return $law_values[ $key ] ?? $default;
};
?>

<fieldset id="law-section-details">
	<legend>Event details</legend>

	<p class="law-form-field <?php echo in_array( 'title', $law_locked, true ) ? 'is-locked' : ''; ?>">
		<label for="law-title">Event title *</label>
		<input type="text" id="law-title" name="event_title" value="<?php echo esc_attr( $law_value( 'event_title' ) ); ?>"
			<?php echo in_array( 'title', $law_locked, true ) ? 'readonly title="Locked after approval"' : 'required'; ?>>
		<?php $law_error_message( 'event_title' ); ?>
		<?php if ( in_array( 'title', $law_locked, true ) ) : ?><span class="law-locked-note">Locked after approval</span><?php endif; ?>
	</p>

	<div class="law-row-grid">
		<p class="law-form-field <?php echo in_array( 'type', $law_locked, true ) ? 'is-locked' : ''; ?>">
			<label for="law-type">Event type *</label>
			<select id="law-type" name="event_type" <?php echo in_array( 'type', $law_locked, true ) ? 'disabled' : 'required'; ?>>
				<option value="">Choose…</option>
				<?php foreach ( law_events_cpt_field_choices( '63' ) as $law_choice ) : ?>
					<option value="<?php echo esc_attr( $law_choice ); ?>" <?php selected( $law_value( 'event_type' ), $law_choice ); ?>><?php echo esc_html( $law_choice ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php $law_error_message( 'event_type' ); ?>
		</p>

		<p class="law-form-field <?php echo in_array( 'host_organisations', $law_locked, true ) ? 'is-locked' : ''; ?>">
			<label for="law-hosts">Host organisation(s) *</label>
			<input type="text" id="law-hosts" name="host_organisations" value="<?php echo esc_attr( $law_value( 'host_organisations' ) ); ?>" <?php echo in_array( 'host_organisations', $law_locked, true ) ? 'readonly' : 'required'; ?>>
			<?php $law_error_message( 'host_organisations' ); ?>
			<?php if ( in_array( 'host_organisations', $law_locked, true ) ) : ?><span class="law-locked-note">Locked after approval</span><?php endif; ?>
		</p>
	</div>

	<div class="law-form-field <?php echo in_array( 'preferred_slots', $law_locked, true ) ? 'is-locked' : ''; ?>">
		<span class="law-form-label">Preferred date &amp; time slots *</span>
		<?php if ( in_array( 'preferred_slots', $law_locked, true ) ) : ?><span class="law-locked-note">Locked after approval</span><?php endif; ?>
		<div class="law-choices law-choices--cols-3">
			<?php
			// law_events_slot_choices() adds back any retired slot this event
			// already holds, and the keyed comparison tolerates a label stored
			// with different dash punctuation (see law_events_slot_label_key()).
			// Between them, a host's own choice can never silently disappear
			// from the form and be dropped on the next save.
			$law_chosen_slots = (array) $law_value( 'preferred_slots', array() );
			$law_chosen_keys  = array_map( 'law_events_slot_label_key', $law_chosen_slots );
			foreach ( law_events_slot_choices( $law_chosen_slots ) as $law_slot ) :
				$law_slot_checked = in_array( law_events_slot_label_key( $law_slot['label'] ), $law_chosen_keys, true );
				$law_slot_note    = 'committee' === $law_context ? ' (retired)' : ' (no longer offered)';
				?>
				<label><input type="checkbox" name="preferred_slots[]" value="<?php echo esc_attr( $law_slot['label'] ); ?>"
					<?php checked( $law_slot_checked ); ?>
					<?php disabled( in_array( 'preferred_slots', $law_locked, true ) ); ?>>
					<?php echo esc_html( $law_slot['label'] . ( $law_slot['retired'] ? $law_slot_note : '' ) ); ?></label>
			<?php endforeach; ?>
		</div>
		<?php $law_error_message( 'preferred_slots' ); ?>
	</div>

	<?php
	// Rich text (functions/events/rich-text.php). No `required` attribute: the
	// editor hides the textarea, and a browser refuses to submit a form holding
	// an invalid control it cannot focus. law-rich-text.js prints the same
	// message inline instead, and law_events_form_save() checks it regardless.
	?>
	<div class="law-form-field">
		<label for="law-description">Description *</label>
		<?php
		law_rich_text_field(
			array(
				'name'     => 'description',
				'id'       => 'law-description',
				'value'    => (string) $law_value( 'description' ),
				'rows'     => 8,
				'required' => 'Please describe the event.',
			)
		);
		?>
		<?php $law_error_message( 'description' ); ?>
	</div>

	<?php
	$law_chosen_sectors = (array) $law_value( 'sectors', array() );
	// The two "please specify" inputs mirror form 2 (Event > submit an
	// event) fields 61 and 62: shown only when their sector is chosen
	// (field 60 Sector = "Jurisdiction-specific" / "Other / sector-neutral").
	$law_show_jur = in_array( 'Jurisdiction-specific', $law_chosen_sectors, true ) || '' !== (string) $law_value( 'sector_jurisdiction' ) || isset( $law_errors['sector_jurisdiction'] );
	$law_show_oth = in_array( 'Other / sector-neutral', $law_chosen_sectors, true ) || '' !== (string) $law_value( 'sector_other' ) || isset( $law_errors['sector_other'] );
	?>
	<div class="law-form-field <?php echo in_array( 'sectors', $law_locked, true ) ? 'is-locked' : ''; ?>">
		<span class="law-form-label">Sector</span>
		<?php if ( in_array( 'sectors', $law_locked, true ) ) : ?><span class="law-locked-note">Locked after approval</span><?php endif; ?>
		<div class="law-choices law-choices--cols-2">
			<?php
			foreach ( law_events_cpt_field_choices( '60' ) as $law_choice ) :
				$law_sector_toggle = 'Jurisdiction-specific' === $law_choice ? 'law-sector-j-field'
					: ( 'Other / sector-neutral' === $law_choice ? 'law-sector-o-field' : '' );
				?>
				<label><input type="checkbox" name="sectors[]" value="<?php echo esc_attr( $law_choice ); ?>"
					<?php echo $law_sector_toggle ? 'data-law-toggles="' . esc_attr( $law_sector_toggle ) . '"' : ''; ?>
					<?php checked( in_array( $law_choice, $law_chosen_sectors, true ) ); ?>
					<?php disabled( in_array( 'sectors', $law_locked, true ) ); ?>>
					<?php echo esc_html( $law_choice ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>

	<div class="law-row-grid law-row-grid--three">
		<p class="law-form-field <?php echo in_array( 'sectors', $law_locked, true ) ? 'is-locked' : ''; ?>" id="law-sector-j-field" <?php echo $law_show_jur ? '' : 'hidden'; ?>><label for="law-sector-j">Jurisdiction-specific: please specify *</label>
			<input type="text" id="law-sector-j" name="sector_jurisdiction" value="<?php echo esc_attr( $law_value( 'sector_jurisdiction' ) ); ?>" <?php echo in_array( 'sectors', $law_locked, true ) ? 'readonly' : ''; ?>>
			<?php $law_error_message( 'sector_jurisdiction' ); ?></p>
		<p class="law-form-field <?php echo in_array( 'sectors', $law_locked, true ) ? 'is-locked' : ''; ?>" id="law-sector-o-field" <?php echo $law_show_oth ? '' : 'hidden'; ?>><label for="law-sector-o">Other / sector-neutral: please specify *</label>
			<input type="text" id="law-sector-o" name="sector_other" value="<?php echo esc_attr( $law_value( 'sector_other' ) ); ?>" <?php echo in_array( 'sectors', $law_locked, true ) ? 'readonly' : ''; ?>>
			<?php $law_error_message( 'sector_other' ); ?></p>
	</div>
</fieldset>

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
					<label>Organisation / firm / chambers *<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][organisation]" value="<?php echo esc_attr( (string) ( $law_row['organisation'] ?? '' ) ); ?>"></label>
					<label>Job title *<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][job_title]" value="<?php echo esc_attr( (string) ( $law_row['job_title'] ?? '' ) ); ?>"></label>
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

<?php
// Which Venue needed answer this form is rendering. The field is in the host
// lock list and a disabled radio posts nothing, so on an error re-render of a
// post-approval host edit law_events_form_values() hands back an empty answer;
// reading the stored value in that case keeps both the radios checked and the
// venue details on screen.
$law_venue_needed = in_array( 'venue_needed', $law_locked, true ) && $law_post
	? (string) law_event_meta( $law_post->ID, '_law_venue_needed' )
	: (string) $law_value( 'venue_needed' );
// The venue name, capacity band and places available are only asked of a host
// who already has a venue; the committee always sees them, because on an event
// LAW places they are the ones who know. See
// law_events_venue_details_visible().
$law_show_venue = law_events_venue_details_visible( $law_venue_needed );
$law_venue_toggle = law_user_is_committee() ? '' : ' data-law-toggles="law-venue-details" data-law-toggles-keep="1"';
?>
<fieldset id="law-section-venue">
	<legend>Venue</legend>
	<div class="law-form-field <?php echo in_array( 'venue_needed', $law_locked, true ) ? 'is-locked' : ''; ?>">
		<span class="law-form-label">Venue needed? *</span>
		<label><input type="radio" name="venue_needed" value="Yes, please share our details with venue hosts" <?php checked( $law_venue_needed, 'Yes, please share our details with venue hosts' ); ?> <?php disabled( in_array( 'venue_needed', $law_locked, true ) ); ?>> Yes, please share our details with venue hosts</label>
		<label><input type="radio" name="venue_needed" value="No, we already have a venue planned"<?php echo $law_venue_toggle; ?> <?php checked( $law_venue_needed, 'No, we already have a venue planned' ); ?> <?php disabled( in_array( 'venue_needed', $law_locked, true ) ); ?>> No, we already have a venue planned</label>
		<?php $law_error_message( 'venue_needed' ); ?>
	</div>
	<!-- A plain wrapper, not the grid itself: .law-row-grid's display:grid is
	authored after Foundation's [hidden] { display: none } and would win. -->
	<div id="law-venue-details" <?php echo $law_show_venue ? '' : 'hidden'; ?>>
	<?php if ( 'committee' === $law_context && 0 !== strpos( $law_venue_needed, 'No,' ) ) : ?>
		<p class="law-form-hint">The host asked LAW to find a venue, so these three are not on their form. Set them here once the event has been placed.</p>
	<?php endif; ?>
	<div class="law-row-grid law-row-grid--three">
		<p class="law-form-field"><label for="law-venue">Venue (name and/or address) *</label>
			<input type="text" id="law-venue" name="venue" value="<?php echo esc_attr( $law_value( 'venue' ) ); ?>">
			<?php $law_error_message( 'venue' ); ?></p>
		<p class="law-form-field <?php echo in_array( 'venue_capacity', $law_locked, true ) ? 'is-locked' : ''; ?>"><label for="law-capacity">Venue capacity<?php echo in_array( 'venue_capacity', $law_locked, true ) ? ' (locked)' : ''; ?></label>
			<select id="law-capacity" name="venue_capacity" data-law-capacity <?php disabled( in_array( 'venue_capacity', $law_locked, true ) ); ?>>
				<option value="">Choose…</option>
				<?php foreach ( law_events_venue_capacity_bands() as $law_choice => $law_band_max ) : ?>
					<option value="<?php echo esc_attr( $law_choice ); ?>" data-law-max="<?php echo esc_attr( null === $law_band_max ? '' : (string) $law_band_max ); ?>" <?php selected( $law_value( 'venue_capacity' ), $law_choice ); ?>><?php echo esc_html( $law_choice ); ?></option>
				<?php endforeach; ?>
			</select></p>
		<p class="law-form-field">
			<label for="law-tickets">Places available</label>
			<?php
			// max comes from the chosen capacity band and is kept in step by
			// event-form.js; min is 1, as on form 2 field 54 (Tickets available).
			$law_capacity_max = law_events_venue_capacity_bands()[ (string) $law_value( 'venue_capacity' ) ] ?? null;
			?>
			<input type="number" id="law-tickets" name="tickets_available" min="1"
				<?php echo null === $law_capacity_max ? '' : 'max="' . esc_attr( (string) $law_capacity_max ) . '"'; ?>
				data-law-tickets value="<?php echo esc_attr( $law_value( 'tickets_available' ) ); ?>">
			<?php $law_error_message( 'tickets_available' ); ?>
		</p>
	</div>
	</div>
</fieldset>

<fieldset id="law-section-owners">
	<legend>Owners &amp; contacts</legend>
	<p class="law-form-hint">Additional owners can manage this event with you. Their accounts are created when the event is approved.</p>
	<?php
	get_template_part( 'parts/events/people-repeater', null, array(
		'group'     => 'co_owners',
		'label'     => 'Additional event owners',
		'rows'      => (array) $law_value( 'co_owners', array() ),
		'error'     => $law_errors['co_owners'][0] ?? '',
		'add_label' => 'Add co-owner',
	) );
	get_template_part( 'parts/events/people-repeater', null, array(
		'group'     => 'contacts',
		'label'     => 'Event contacts',
		'rows'      => (array) $law_value( 'contacts', array() ),
		'error'     => $law_errors['contacts'][0] ?? '',
		'add_label' => 'Add contact',
	) );
	?>
</fieldset>

<fieldset id="law-section-fees" class="<?php echo in_array( 'fee_tier', $law_locked, true ) ? 'is-locked' : ''; ?>">
	<legend>Fees</legend>
	<?php if ( in_array( 'fee_tier', $law_locked, true ) ) : ?>
		<p class="law-locked-note"><?php echo 'committee' === $law_context
			? 'Fees and invoicing are managed from the dashboard controls and wp-admin.'
			: 'The fee and invoice details are locked after approval.'; ?></p>
	<?php endif; ?>
	<div class="law-form-field">
		<span class="law-form-label">Event fee *</span>
		<?php foreach ( (array) law_events_setting( 'fee_tiers', array() ) as $law_tier => $law_config ) : ?>
			<label><input type="radio" name="fee_tier" value="<?php echo esc_attr( $law_tier ); ?>"
				<?php checked( $law_value( 'fee_tier' ), $law_tier ); ?>
				<?php disabled( in_array( 'fee_tier', $law_locked, true ) ); ?>>
				<?php echo esc_html( $law_config['label'] ); ?></label>
		<?php endforeach; ?>
		<?php $law_error_message( 'fee_tier' ); ?>
	</div>

	<div class="law-invoice-fields" data-law-invoice <?php echo 'sponsor' === $law_value( 'fee_tier' ) ? 'hidden' : ''; ?>>
		<div class="law-row-grid">
			<p class="law-form-field"><label for="law-inv-name">Invoice contact name *</label>
				<input type="text" id="law-inv-name" name="invoice_name" autocomplete="section-invoice billing name" value="<?php echo esc_attr( $law_value( 'invoice_name' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
				<?php $law_error_message( 'invoice_name' ); ?></p>
			<p class="law-form-field"><label for="law-inv-email">Invoice contact email *</label>
				<input type="email" id="law-inv-email" name="invoice_email" autocomplete="section-invoice billing email" value="<?php echo esc_attr( $law_value( 'invoice_email' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
				<?php $law_error_message( 'invoice_email' ); ?></p>
		</div>
		<div class="law-row-grid">
			<p class="law-form-field"><label for="law-inv-line1">Address line 1 *</label>
				<input type="text" id="law-inv-line1" name="invoice_line1" autocomplete="section-invoice billing address-line1" value="<?php echo esc_attr( $law_value( 'invoice_line1' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
				<?php $law_error_message( 'invoice_line1' ); ?></p>
			<p class="law-form-field"><label for="law-inv-line2">Address line 2</label>
				<input type="text" id="law-inv-line2" name="invoice_line2" autocomplete="section-invoice billing address-line2" value="<?php echo esc_attr( $law_value( 'invoice_line2' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
		</div>
		<div class="law-row-grid">
			<p class="law-form-field"><label for="law-inv-city">City *</label>
				<input type="text" id="law-inv-city" name="invoice_city" autocomplete="section-invoice billing address-level2" value="<?php echo esc_attr( $law_value( 'invoice_city' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
				<?php $law_error_message( 'invoice_city' ); ?></p>
			<p class="law-form-field"><label for="law-inv-state">County / state</label>
				<input type="text" id="law-inv-state" name="invoice_state" autocomplete="section-invoice billing address-level1" value="<?php echo esc_attr( $law_value( 'invoice_state' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
		</div>
		<div class="law-row-grid">
			<p class="law-form-field"><label for="law-inv-postcode">Postcode *</label>
				<input type="text" id="law-inv-postcode" name="invoice_postal_code" autocomplete="section-invoice billing postal-code" value="<?php echo esc_attr( $law_value( 'invoice_postal_code' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
				<?php $law_error_message( 'invoice_postal_code' ); ?></p>
			<p class="law-form-field"><label for="law-inv-country">Country *</label>
				<?php
				// Same country list as the registration and profile forms
				// (form 1 field 10 Country of residence). A stored value that
				// is not on the list is kept as its own option so an older
				// event cannot lose its country just by being re-saved.
				$law_inv_country   = (string) $law_value( 'invoice_country' );
				$law_inv_countries = law_registration_country_choices();
				if ( $law_inv_countries && '' !== $law_inv_country && ! in_array( $law_inv_country, $law_inv_countries, true ) ) {
					$law_inv_countries[] = $law_inv_country;
				}
				?>
				<?php if ( $law_inv_countries ) : ?>
					<select id="law-inv-country" name="invoice_country" autocomplete="section-invoice billing country-name" <?php disabled( in_array( 'invoice', $law_locked, true ) ); ?>>
						<option value="">Select country</option>
						<?php foreach ( $law_inv_countries as $law_inv_choice ) : ?>
							<option value="<?php echo esc_attr( $law_inv_choice ); ?>" <?php selected( $law_inv_country, $law_inv_choice ); ?>><?php echo esc_html( $law_inv_choice ); ?></option>
						<?php endforeach; ?>
					</select>
				<?php else : ?>
					<input type="text" id="law-inv-country" name="invoice_country" autocomplete="section-invoice billing country-name" value="<?php echo esc_attr( $law_inv_country ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
				<?php endif; ?>
				<?php $law_error_message( 'invoice_country' ); ?></p>
		</div>
		<p class="law-form-field"><label for="law-inv-vat">VAT number (if applicable)</label>
			<input type="text" id="law-inv-vat" name="vat_number" autocomplete="off" value="<?php echo esc_attr( $law_value( 'vat_number' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
	</div>
</fieldset>

<?php
// The Session agenda section is committee-gated: it renders only once the
// committee has ticked "This event has a session agenda" on the dashboard, or
// while the event still has sessions from an earlier opt-in
// (law_event_has_session_agenda() in submission-form.php). The hidden sentinel
// below is what tells the saver the section was really on the form, so an
// absent section is never mistaken for "the host deleted every session".
if ( law_event_has_session_agenda( $law_post ) ) :
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
<?php endif; // law_event_has_session_agenda ?>
