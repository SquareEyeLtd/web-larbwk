<?php
/**
 * Template Name: Submit an event (custom)
 *
 * The custom submission and edit form (EVENTS_4.1_REBUILD.md §3.5), replacing
 * the form 2 (Event > submit an event) embed. Renders inside the account-pages
 * hero like the old form did; sectioned, with a sticky in-page section nav,
 * inline validation messages and visible locked fields after approval.
 */

get_header();

$law_event_id = law_events_form_event_id();
$law_post     = $law_event_id ? get_post( $law_event_id ) : null;
$law_can_edit = ! $law_post
	|| ( LAW_EVENT_CPT === $law_post->post_type && law_user_can_manage_event( get_current_user_id(), $law_post->ID ) );
$law_state    = law_events_form_state();
$law_values   = $law_can_edit ? law_events_form_values( $law_post, $law_state ) : array();
$law_errors   = (array) ( $law_state['errors'] ?? array() );
$law_locked   = $law_post ? law_events_locked_fields( $law_post ) : array();
$law_notice   = sanitize_key( $_GET['law_notice'] ?? '' );

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

$law_sections = array(
	'details'  => 'Event details',
	'speakers' => 'Speakers',
	'venue'    => 'Venue',
	'owners'   => 'Owners & contacts',
	'fees'     => 'Fees',
	'agenda'   => 'Session agenda',
	'finish'   => 'Finish',
);
?>

<section class="hero auth-hero law-event-form-hero" style="background-image: url('<?php echo law_asset( 'assets/images/patrons-and-committee-bg.jpg' ); ?>');">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<h1><?php echo ( $law_post && $law_can_edit ) ? 'Edit your event' : 'Submit an event'; ?></h1>
				<?php if ( $law_post && $law_can_edit ) : // Don't render a non-owned event's title/status: before this gate it leaked via ?law_event=<id> enumeration. ?>
					<p class="law-form-status">
						<?php echo esc_html( $law_post->post_title ); ?> — status:
						<strong><?php echo esc_html( law_event_status_label( $law_post ) ); ?></strong>
						<?php if ( $law_locked ) : ?>
							<br><em>Some fields are locked now the event is approved; contact the committee to change them.</em>
						<?php endif; ?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( ! is_user_logged_in() ) : ?>
				<div class="large-9 cell auth-intro"><p>Please <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">log in</a> to submit an event.</p></div>
			<?php elseif ( ! law_events_user_can_submit() ) : ?>
				<div class="large-9 cell auth-intro"><p>Event submission is for registered event hosts. You can add the Event host role from your <a href="<?php echo esc_url( home_url( '/account/profile/' ) ); ?>">profile</a>.</p></div>
			<?php elseif ( ! $law_can_edit ) : ?>
				<div class="large-9 cell auth-intro"><p>Sorry, you are not allowed to edit this event.</p></div>
			<?php else : ?>

			<nav class="large-3 cell law-form-nav" aria-label="Form sections">
				<ol>
					<?php foreach ( $law_sections as $law_key => $law_label ) : ?>
						<li><a href="#law-section-<?php echo esc_attr( $law_key ); ?>"><?php echo esc_html( $law_label ); ?></a></li>
					<?php endforeach; ?>
				</ol>
			</nav>

			<div class="large-9 cell auth-intro">
				<?php if ( 'draft-saved' === $law_notice ) : ?>
					<div class="law-form-notice" role="status">Draft saved. Carry on below, or come back later from My events.</div>
				<?php endif; ?>
				<?php if ( isset( $law_errors['locked'][0] ) ) : ?>
					<div class="law-form-notice is-error" role="alert"><?php echo esc_html( $law_errors['locked'][0] ); ?></div>
				<?php elseif ( $law_errors ) : ?>
					<div class="law-form-notice is-error" role="alert">Please fix the highlighted fields below.</div>
				<?php endif; ?>
				<?php
				// Edit locking: warn when someone else is in this event, and
				// take the lock while this form is open.
				if ( $law_post ) {
					require_once ABSPATH . 'wp-admin/includes/post.php';
					$law_locked_by = wp_check_post_lock( $law_post->ID );
					if ( $law_locked_by ) {
						$law_lock_user = get_user_by( 'id', (int) $law_locked_by );
						printf(
							'<div class="law-form-notice is-error" role="alert">%s is editing this event right now. You can look, but saving will be refused until they finish.</div>',
							esc_html( $law_lock_user ? $law_lock_user->display_name : 'Another user' )
						);
					} else {
						wp_set_post_lock( $law_post->ID );
					}
				}
				?>

				<form class="law-event-form" method="post" enctype="multipart/form-data"
					action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="law_event_form">
					<input type="hidden" name="law_event_id" value="<?php echo esc_attr( (string) $law_event_id ); ?>">
					<?php wp_nonce_field( 'law_event_form' ); ?>
					<input type="hidden" name="law_ec" value="<?php echo esc_attr( sanitize_text_field( (string) ( $law_state['input']['law_ec'] ?? wp_unslash( $_GET['ec'] ?? '' ) ) ) ); ?>">
					<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>

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
								$law_chosen_slots = (array) $law_value( 'preferred_slots', array() );
								foreach ( law_events_slots() as $law_slot ) :
									?>
									<label><input type="checkbox" name="preferred_slots[]" value="<?php echo esc_attr( $law_slot['label'] ); ?>"
										<?php checked( in_array( $law_slot['label'], $law_chosen_slots, true ) ); ?>
										<?php disabled( in_array( 'preferred_slots', $law_locked, true ) ); ?>>
										<?php echo esc_html( $law_slot['label'] ); ?></label>
								<?php endforeach; ?>
							</div>
							<?php $law_error_message( 'preferred_slots' ); ?>
						</div>

						<p class="law-form-field">
							<label for="law-description">Description *</label>
							<textarea id="law-description" name="description" rows="8" required><?php echo esc_textarea( $law_value( 'description' ) ); ?></textarea>
							<?php $law_error_message( 'description' ); ?>
						</p>

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
							$law_speaker_rows   = (array) $law_value( 'speakers', array() );
							$law_speaker_rows[] = array(); // Blank template row.
							foreach ( $law_speaker_rows as $law_i => $law_row ) :
								$law_is_template = $law_i === count( $law_speaker_rows ) - 1;
								?>
								<div class="law-row" <?php echo $law_is_template ? 'data-law-row-template hidden' : ''; ?>>
									<button type="button" class="law-row-remove" aria-label="Remove speaker">×</button>
									<div class="law-row-grid">
										<label>Name *<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][name]" value="<?php echo esc_attr( (string) ( $law_row['name'] ?? '' ) ); ?>"></label>
										<label>Email *<input type="email" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][email]" value="<?php echo esc_attr( (string) ( $law_row['email'] ?? '' ) ); ?>"></label>
										<label>Organisation / firm / chambers *<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][organisation]" value="<?php echo esc_attr( (string) ( $law_row['organisation'] ?? '' ) ); ?>"></label>
										<label>Job title / role *<input type="text" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][job_title]" value="<?php echo esc_attr( (string) ( $law_row['job_title'] ?? '' ) ); ?>"></label>
										<label>Website profile URL<input type="url" autocomplete="off" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][website]" value="<?php echo esc_attr( (string) ( $law_row['website'] ?? '' ) ); ?>"></label>
										<label>Photo (JPG/PNG/WebP, 5 MB max)<input type="file" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speaker_photo[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>]" accept=".jpg,.jpeg,.png,.webp"><button type="button" class="law-file-clear" hidden>Clear photo</button></label>
										<label class="law-row-wide">Biography<textarea rows="3" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][bio]"><?php echo esc_textarea( (string) ( $law_row['bio'] ?? '' ) ); ?></textarea></label>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<?php $law_error_message( 'speakers' ); ?>
						<button type="button" class="button law-row-add" data-law-add="speakers">Add a speaker</button>
					</fieldset>

					<fieldset id="law-section-venue">
						<legend>Venue</legend>
						<div class="law-form-field <?php echo in_array( 'venue_needed', $law_locked, true ) ? 'is-locked' : ''; ?>">
							<span class="law-form-label">Venue needed? *</span>
							<label><input type="radio" name="venue_needed" value="Yes, please share our details with venue hosts" <?php checked( $law_value( 'venue_needed' ), 'Yes, please share our details with venue hosts' ); ?> <?php disabled( in_array( 'venue_needed', $law_locked, true ) ); ?>> Yes, please share our details with venue hosts</label>
							<label><input type="radio" name="venue_needed" value="No, we already have a venue planned" data-law-toggles="law-venue-field" <?php checked( $law_value( 'venue_needed' ), 'No, we already have a venue planned' ); ?> <?php disabled( in_array( 'venue_needed', $law_locked, true ) ); ?>> No, we already have a venue planned</label>
							<?php $law_error_message( 'venue_needed' ); ?>
						</div>
						<?php
						// Venue (name/address) mirrors form 2 field 21: shown only when the
						// host already has a venue (field 103 Venue needed = "No").
						$law_show_venue = 'No, we already have a venue planned' === (string) $law_value( 'venue_needed' ) || '' !== (string) $law_value( 'venue' );
						?>
						<div class="law-row-grid law-row-grid--three">
							<p class="law-form-field" id="law-venue-field" <?php echo $law_show_venue ? '' : 'hidden'; ?>><label for="law-venue">Venue (name and/or address) *</label>
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
								<label for="law-tickets">Tickets available</label>
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
					</fieldset>

					<fieldset id="law-section-owners">
						<legend>Owners &amp; contacts</legend>
						<p class="law-form-hint">Additional owners can manage this event with you. Their accounts are created when the event is approved.</p>
						<?php
						get_template_part( 'parts/events/people-repeater', null, array(
							'group'  => 'co_owners',
							'label'  => 'Additional event owners',
							'rows'   => (array) $law_value( 'co_owners', array() ),
							'error'  => $law_errors['co_owners'][0] ?? '',
						) );
						get_template_part( 'parts/events/people-repeater', null, array(
							'group'  => 'contacts',
							'label'  => 'Event contacts',
							'rows'   => (array) $law_value( 'contacts', array() ),
							'error'  => $law_errors['contacts'][0] ?? '',
						) );
						?>
					</fieldset>

					<fieldset id="law-section-fees" class="<?php echo in_array( 'fee_tier', $law_locked, true ) ? 'is-locked' : ''; ?>">
						<legend>Fees</legend>
						<?php if ( in_array( 'fee_tier', $law_locked, true ) ) : ?>
							<p class="law-locked-note">The fee and invoice details are locked after approval.</p>
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
											<option value="">Choose…</option>
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

					<fieldset id="law-section-agenda">
						<legend>Session agenda (optional)</legend>
						<p class="law-form-hint">For content-heavy events, break the running order into sessions, then tick which of the event's speakers appear in each one. The list follows the Speakers section above.</p>
						<?php
						// The speaker names this event currently has, from the same values
						// the Speakers repeater renders. Sessions choose from THIS list
						// (form 9 field 6 Speakers was a multiselect populated from the
						// form 8 entries, never free text), and event-form.js keeps the
						// list in step as speaker rows are typed, added or removed.
						$law_session_speaker_names = array();
						foreach ( (array) $law_value( 'speakers', array() ) as $law_sp_row ) {
							$law_sp_name = trim( (string) ( $law_sp_row['name'] ?? '' ) );
							if ( '' !== $law_sp_name && ! in_array( $law_sp_name, $law_session_speaker_names, true ) ) {
								$law_session_speaker_names[] = $law_sp_name;
							}
						}
						?>
						<div class="law-rows" data-law-rows-group="sessions">
							<?php
							$law_session_rows   = (array) $law_value( 'sessions', array() );
							$law_session_rows[] = array();
							foreach ( $law_session_rows as $law_i => $law_row ) :
								$law_is_template = $law_i === count( $law_session_rows ) - 1;
								?>
								<div class="law-row" <?php echo $law_is_template ? 'data-law-row-template hidden' : ''; ?>>
									<button type="button" class="law-row-remove" aria-label="Remove session">×</button>
									<div class="law-row-grid">
										<label class="law-row-wide">Session title *<input type="text" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][title]" value="<?php echo esc_attr( (string) ( $law_row['title'] ?? '' ) ); ?>"></label>
										<label>Start time *<input type="time" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][start]" value="<?php echo esc_attr( (string) ( $law_row['start'] ?? '' ) ); ?>"></label>
										<label>End time<input type="time" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][end]" value="<?php echo esc_attr( (string) ( $law_row['end'] ?? '' ) ); ?>"></label>
										<label class="law-row-wide">Description *<textarea rows="3" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][description]"><?php echo esc_textarea( (string) ( $law_row['description'] ?? '' ) ); ?></textarea></label>
										<?php
										// Stored as a comma-separated string (that is what the
										// session row exposes), so split it back into names.
										$law_row_speakers = array_filter( array_map( 'trim', explode( ',', (string) ( $law_row['speakers'] ?? '' ) ) ) );
										?>
										<div class="law-row-wide law-session-speakers" data-law-session-speakers>
											<span class="law-form-label">Speakers</span>
											<div class="law-choices" data-law-session-speaker-list>
												<?php foreach ( $law_session_speaker_names as $law_sp_name ) : ?>
													<label><input type="checkbox"
														<?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][speakers][]"
														value="<?php echo esc_attr( $law_sp_name ); ?>"
														<?php checked( in_array( $law_sp_name, $law_row_speakers, true ) ); ?>>
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

					<fieldset id="law-section-finish">
						<legend>Finish</legend>
						<?php
						// The consent is captured once, at submission: form 2 field 69
						// (Terms & conditions) was not in the host edit view (view 386,
						// "Events (hosts)"), so an approved event's host never re-agreed.
						// On edit we show what was recorded instead of an empty section.
						$law_consent = $law_post ? law_event_meta( $law_post->ID, '_law_terms_consent' ) : array();
						?>
						<?php if ( ! $law_post || 'law-draft' === $law_post->post_status ) : ?>
							<p class="law-form-field law-terms">
								<label><input type="checkbox" name="terms" value="1" <?php checked( (bool) $law_value( 'terms' ) ); ?>>
									I accept the <a href="<?php echo esc_url( law_events_terms_url() ); ?>" target="_blank" rel="noopener">terms &amp; conditions for event hosts</a> *</label>
								<?php $law_error_message( 'terms' ); ?>
							</p>
						<?php elseif ( ! empty( $law_consent['accepted'] ) ) : ?>
							<p class="law-form-status">
								You accepted the
								<a href="<?php echo esc_url( law_events_terms_url() ); ?>" target="_blank" rel="noopener">terms &amp; conditions for event hosts</a>
								<?php if ( ! empty( $law_consent['at'] ) ) : ?>
									on <strong><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $law_consent['at'] ) ) ); ?></strong>
								<?php endif; ?>
								when this event was submitted.
							</p>
						<?php endif; ?>

						<p class="law-form-buttons">
							<?php if ( ! $law_post || in_array( $law_post->post_status, array( 'law-draft' ), true ) ) : ?>
								<button type="submit" name="law_form_action" value="draft" class="button" formnovalidate>Save draft</button>
								<button type="submit" name="law_form_action" value="submit" class="button orange">Submit event</button>
							<?php elseif ( 'law-sent-back' === $law_post->post_status ) : ?>
								<button type="submit" name="law_form_action" value="submit" class="button orange">Save &amp; resubmit to the committee</button>
							<?php else : ?>
								<button type="submit" name="law_form_action" value="update" class="button orange">Save changes</button>
							<?php endif; ?>
						</p>
					</fieldset>
				</form>
			</div>
			<?php endif; ?>
		</div>
	</div>
</section>

<?php get_footer(); ?>
