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

<?php
// The Speakers repeater lives in its own partial so the committee's
// external-event form renders the same one (parts/events/external-manage.php).
get_template_part(
	'parts/events/event-form-speakers',
	null,
	array( 'post' => $law_post, 'values' => $law_values, 'errors' => $law_errors, 'locked' => $law_locked, 'context' => $law_context )
);
?>

<?php
// The "Venue needed?" radios are gone (Denis, 17 September 2026). Every
// submitter is now recorded as already having a venue -- the saver writes that
// answer itself, see law_events_form_save() -- so the three venue details are
// on every form, host and committee alike, and all three are required. The
// predicates are still called rather than the block being unconditional
// markup, so the template, the validator and the saver keep agreeing about
// what was asked from one place.
$law_venue_star = law_events_venue_details_required() ? ' *' : '';
// A disabled control posts nothing, so on an error re-render
// law_events_form_values() hands back an empty value for every locked field.
// The stored one stands in, so a locked band or places count still renders
// the number the committee set rather than an empty box.
$law_locked_value = function ( $field, $meta_key ) use ( $law_locked, $law_post, $law_value ) {
	return in_array( $field, $law_locked, true ) && $law_post
		? (string) law_event_meta( $law_post->ID, $meta_key )
		: (string) $law_value( $field );
};
$law_capacity_locked = in_array( 'venue_capacity', $law_locked, true );
$law_tickets_locked  = in_array( 'tickets_available', $law_locked, true );
$law_capacity_value  = $law_locked_value( 'venue_capacity', '_law_venue_capacity' );
$law_tickets_value   = $law_locked_value( 'tickets_available', '_law_tickets_available' );
?>
<fieldset id="law-section-venue">
	<legend>Venue</legend>
	<div class="law-row-grid law-row-grid--three">
		<p class="law-form-field"><label for="law-venue">Venue (name and/or address)<?php echo esc_html( $law_venue_star ); ?></label>
			<input type="text" id="law-venue" name="venue" value="<?php echo esc_attr( $law_value( 'venue' ) ); ?>">
			<?php $law_error_message( 'venue' ); ?></p>
		<p class="law-form-field <?php echo $law_capacity_locked ? 'is-locked' : ''; ?>"><label for="law-capacity">Venue capacity<?php echo $law_capacity_locked ? ' (locked)' : esc_html( $law_venue_star ); ?></label>
			<select id="law-capacity" name="venue_capacity" data-law-capacity <?php disabled( $law_capacity_locked ); ?>>
				<option value="">Choose…</option>
				<?php
				foreach ( law_events_venue_capacity_bands() as $law_choice => $law_band_max ) :
					$law_band_min = law_events_venue_capacity_band_floor( $law_choice );
					?>
					<option value="<?php echo esc_attr( $law_choice ); ?>" data-law-max="<?php echo esc_attr( null === $law_band_max ? '' : (string) $law_band_max ); ?>" data-law-min="<?php echo esc_attr( null === $law_band_min ? '' : (string) $law_band_min ); ?>" <?php selected( $law_capacity_value, $law_choice ); ?>><?php echo esc_html( $law_choice ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php if ( $law_capacity_locked ) : ?><span class="law-locked-note">Locked after approval</span><?php endif; ?>
			<?php $law_error_message( 'venue_capacity' ); ?></p>
		<p class="law-form-field <?php echo $law_tickets_locked ? 'is-locked' : ''; ?>">
			<label for="law-tickets">Places available<?php echo $law_tickets_locked ? ' (locked)' : esc_html( $law_venue_star ); ?></label>
			<?php
			// Both bounds come from the chosen capacity band and are kept in step
			// by event-form.js. min was a flat 1 (form 2 field 54, Tickets
			// available) until the band floor landed on 15 September 2026; it
			// falls back to 1 for "TBC" and for no band chosen, which bound
			// nothing. A disabled input is exempt from constraint validation, so
			// a locked field carrying a stale stored value can never block the
			// form — the same reason the server skips the pair check there.
			$law_capacity_max = law_events_venue_capacity_bands()[ $law_capacity_value ] ?? null;
			$law_capacity_min = law_events_venue_capacity_band_floor( $law_capacity_value );
			?>
			<input type="number" id="law-tickets" name="tickets_available" min="<?php echo esc_attr( (string) ( $law_capacity_min ?? 1 ) ); ?>"
				<?php echo null === $law_capacity_max ? '' : 'max="' . esc_attr( (string) $law_capacity_max ) . '"'; ?>
				data-law-tickets data-law-strict value="<?php echo esc_attr( $law_tickets_value ); ?>" <?php disabled( $law_tickets_locked ); ?>>
			<?php if ( $law_tickets_locked ) : ?><span class="law-locked-note">Set by the committee once your event is submitted. Reply to any email from us if the number needs to change.</span><?php endif; ?>
			<?php $law_error_message( 'tickets_available' ); ?>
		</p>
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
if ( law_event_has_session_agenda( $law_post ) ) {
	// Same partial the external-event form renders. The committee gate stays
	// HERE rather than moving into it: an external event always has an agenda
	// section, because the committee is transcribing a published running order,
	// while a host only gets one once the committee has asked for it.
	get_template_part(
		'parts/events/event-form-agenda',
		null,
		array( 'post' => $law_post, 'values' => $law_values, 'errors' => $law_errors, 'locked' => $law_locked, 'context' => $law_context )
	);
}

