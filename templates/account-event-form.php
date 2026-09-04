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
		echo '<p class="law-form-error" role="alert">' . esc_html( $law_errors[ $field ][0] ) . '</p>';
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
				<h1><?php echo $law_post ? 'Edit your event' : 'Submit an event'; ?></h1>
				<?php if ( $law_post ) : ?>
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

						<p class="law-form-field <?php echo in_array( 'type', $law_locked, true ) ? 'is-locked' : ''; ?>">
							<label for="law-type">Event type</label>
							<select id="law-type" name="event_type" <?php echo in_array( 'type', $law_locked, true ) ? 'disabled' : ''; ?>>
								<option value="">Choose…</option>
								<?php foreach ( law_events_cpt_field_choices( '63' ) as $law_choice ) : ?>
									<option value="<?php echo esc_attr( $law_choice ); ?>" <?php selected( $law_value( 'event_type' ), $law_choice ); ?>><?php echo esc_html( $law_choice ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="law-form-field <?php echo in_array( 'host_organisations', $law_locked, true ) ? 'is-locked' : ''; ?>">
							<label for="law-hosts">Host organisation(s)</label>
							<input type="text" id="law-hosts" name="host_organisations" value="<?php echo esc_attr( $law_value( 'host_organisations' ) ); ?>" <?php echo in_array( 'host_organisations', $law_locked, true ) ? 'readonly' : ''; ?>>
							<?php if ( in_array( 'host_organisations', $law_locked, true ) ) : ?><span class="law-locked-note">Locked after approval</span><?php endif; ?>
						</p>

						<div class="law-form-field <?php echo in_array( 'preferred_slots', $law_locked, true ) ? 'is-locked' : ''; ?>">
							<span class="law-form-label">Preferred date &amp; time slots</span>
							<?php if ( in_array( 'preferred_slots', $law_locked, true ) ) : ?><span class="law-locked-note">Locked after approval</span><?php endif; ?>
							<div class="law-choices">
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
						</div>

						<p class="law-form-field">
							<label for="law-description">Description *</label>
							<textarea id="law-description" name="description" rows="8"><?php echo esc_textarea( $law_value( 'description' ) ); ?></textarea>
							<?php $law_error_message( 'description' ); ?>
						</p>

						<div class="law-form-field <?php echo in_array( 'sectors', $law_locked, true ) ? 'is-locked' : ''; ?>">
							<span class="law-form-label">Sector</span>
							<?php if ( in_array( 'sectors', $law_locked, true ) ) : ?><span class="law-locked-note">Locked after approval</span><?php endif; ?>
							<div class="law-choices">
								<?php
								$law_chosen_sectors = (array) $law_value( 'sectors', array() );
								foreach ( law_events_cpt_field_choices( '60' ) as $law_choice ) :
									?>
									<label><input type="checkbox" name="sectors[]" value="<?php echo esc_attr( $law_choice ); ?>"
										<?php checked( in_array( $law_choice, $law_chosen_sectors, true ) ); ?>
										<?php disabled( in_array( 'sectors', $law_locked, true ) ); ?>>
										<?php echo esc_html( $law_choice ); ?></label>
								<?php endforeach; ?>
							</div>
						</div>

						<p class="law-form-field <?php echo in_array( 'sectors', $law_locked, true ) ? 'is-locked' : ''; ?>"><label for="law-sector-j">Jurisdiction-specific: please specify</label>
							<input type="text" id="law-sector-j" name="sector_jurisdiction" value="<?php echo esc_attr( $law_value( 'sector_jurisdiction' ) ); ?>" <?php echo in_array( 'sectors', $law_locked, true ) ? 'readonly' : ''; ?>></p>
						<p class="law-form-field <?php echo in_array( 'sectors', $law_locked, true ) ? 'is-locked' : ''; ?>"><label for="law-sector-o">Other / sector-neutral: please specify</label>
							<input type="text" id="law-sector-o" name="sector_other" value="<?php echo esc_attr( $law_value( 'sector_other' ) ); ?>" <?php echo in_array( 'sectors', $law_locked, true ) ? 'readonly' : ''; ?>></p>
					</fieldset>

					<fieldset id="law-section-speakers">
						<legend>Speakers</legend>
						<p class="law-form-hint">Add each speaker once. If they have spoken at LAW before, the email address links them to their existing profile automatically.</p>
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
										<label>Name<input type="text" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][name]" value="<?php echo esc_attr( (string) ( $law_row['name'] ?? '' ) ); ?>"></label>
										<label>Email<input type="email" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][email]" value="<?php echo esc_attr( (string) ( $law_row['email'] ?? '' ) ); ?>"></label>
										<label>Organisation / firm / chambers<input type="text" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][organisation]" value="<?php echo esc_attr( (string) ( $law_row['organisation'] ?? '' ) ); ?>"></label>
										<label>Job title / role<input type="text" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][job_title]" value="<?php echo esc_attr( (string) ( $law_row['job_title'] ?? '' ) ); ?>"></label>
										<label>Website profile URL<input type="url" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][website]" value="<?php echo esc_attr( (string) ( $law_row['website'] ?? '' ) ); ?>"></label>
										<label>Photo (JPG/PNG/WebP, 5 MB max)<input type="file" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speaker_photo[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>]" accept=".jpg,.jpeg,.png,.webp"></label>
										<label class="law-row-wide">Biography<textarea rows="3" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="speakers[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][bio]"><?php echo esc_textarea( (string) ( $law_row['bio'] ?? '' ) ); ?></textarea></label>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button law-row-add" data-law-add="speakers">Add a speaker</button>
					</fieldset>

					<fieldset id="law-section-venue">
						<legend>Venue</legend>
						<div class="law-form-field <?php echo in_array( 'venue_needed', $law_locked, true ) ? 'is-locked' : ''; ?>">
							<span class="law-form-label">Venue needed?</span>
							<label><input type="radio" name="venue_needed" value="Yes, please share our details with venue hosts" <?php checked( $law_value( 'venue_needed' ), 'Yes, please share our details with venue hosts' ); ?> <?php disabled( in_array( 'venue_needed', $law_locked, true ) ); ?>> Yes, please share our details with venue hosts</label>
							<label><input type="radio" name="venue_needed" value="No, we already have a venue planned" <?php checked( $law_value( 'venue_needed' ), 'No, we already have a venue planned' ); ?> <?php disabled( in_array( 'venue_needed', $law_locked, true ) ); ?>> No, we already have a venue planned</label>
						</div>
						<p class="law-form-field"><label for="law-venue">Venue (name and/or address)</label>
							<input type="text" id="law-venue" name="venue" value="<?php echo esc_attr( $law_value( 'venue' ) ); ?>"></p>
						<p class="law-form-field <?php echo in_array( 'venue_capacity', $law_locked, true ) ? 'is-locked' : ''; ?>"><label for="law-capacity">Venue capacity<?php echo in_array( 'venue_capacity', $law_locked, true ) ? ' (locked after approval)' : ''; ?></label>
							<select id="law-capacity" name="venue_capacity" <?php disabled( in_array( 'venue_capacity', $law_locked, true ) ); ?>>
								<option value="">Choose…</option>
								<?php foreach ( array( 'Under 50', '51-100', '101-150', '151-250', '251+', 'TBC' ) as $law_choice ) : ?>
									<option value="<?php echo esc_attr( $law_choice ); ?>" <?php selected( $law_value( 'venue_capacity' ), $law_choice ); ?>><?php echo esc_html( $law_choice ); ?></option>
								<?php endforeach; ?>
							</select></p>
						<p class="law-form-field">
							<label for="law-tickets">Tickets available</label>
							<input type="number" id="law-tickets" name="tickets_available" min="0" value="<?php echo esc_attr( $law_value( 'tickets_available' ) ); ?>">
						</p>
					</fieldset>

					<fieldset id="law-section-owners">
						<legend>Owners &amp; contacts</legend>
						<p class="law-form-hint">Additional owners can manage this event with you. Their accounts are created when the event is approved.</p>
						<?php
						get_template_part( 'parts/events/people-repeater', null, array(
							'group'  => 'co_owners',
							'label'  => 'Additional event owners',
							'rows'   => (array) $law_value( 'co_owners', array() ),
						) );
						get_template_part( 'parts/events/people-repeater', null, array(
							'group'  => 'contacts',
							'label'  => 'Event contacts',
							'rows'   => (array) $law_value( 'contacts', array() ),
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
							<p class="law-form-field"><label for="law-inv-name">Invoice contact name *</label>
								<input type="text" id="law-inv-name" name="invoice_name" value="<?php echo esc_attr( $law_value( 'invoice_name' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
								<?php $law_error_message( 'invoice_name' ); ?></p>
							<p class="law-form-field"><label for="law-inv-email">Invoice contact email *</label>
								<input type="email" id="law-inv-email" name="invoice_email" value="<?php echo esc_attr( $law_value( 'invoice_email' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
								<?php $law_error_message( 'invoice_email' ); ?></p>
							<p class="law-form-field"><label for="law-inv-line1">Address line 1</label>
								<input type="text" id="law-inv-line1" name="invoice_line1" value="<?php echo esc_attr( $law_value( 'invoice_line1' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
							<p class="law-form-field"><label for="law-inv-line2">Address line 2</label>
								<input type="text" id="law-inv-line2" name="invoice_line2" value="<?php echo esc_attr( $law_value( 'invoice_line2' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
							<p class="law-form-field"><label for="law-inv-city">City</label>
								<input type="text" id="law-inv-city" name="invoice_city" value="<?php echo esc_attr( $law_value( 'invoice_city' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
							<p class="law-form-field"><label for="law-inv-state">County / state</label>
								<input type="text" id="law-inv-state" name="invoice_state" value="<?php echo esc_attr( $law_value( 'invoice_state' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
							<p class="law-form-field"><label for="law-inv-postcode">Postcode</label>
								<input type="text" id="law-inv-postcode" name="invoice_postal_code" value="<?php echo esc_attr( $law_value( 'invoice_postal_code' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
							<p class="law-form-field"><label for="law-inv-country">Country *</label>
								<input type="text" id="law-inv-country" name="invoice_country" value="<?php echo esc_attr( $law_value( 'invoice_country' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>>
								<?php $law_error_message( 'invoice_country' ); ?></p>
							<p class="law-form-field"><label for="law-inv-vat">VAT number (if applicable)</label>
								<input type="text" id="law-inv-vat" name="vat_number" value="<?php echo esc_attr( $law_value( 'vat_number' ) ); ?>" <?php echo in_array( 'invoice', $law_locked, true ) ? 'readonly' : ''; ?>></p>
						</div>
					</fieldset>

					<fieldset id="law-section-agenda">
						<legend>Session agenda (optional)</legend>
						<p class="law-form-hint">For content-heavy events, break the running order into sessions. List each session's speakers by name, separated by commas; they must also appear in the Speakers section above.</p>
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
										<label>Session title<input type="text" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][title]" value="<?php echo esc_attr( (string) ( $law_row['title'] ?? '' ) ); ?>"></label>
										<label>Start time<input type="time" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][start]" value="<?php echo esc_attr( (string) ( $law_row['start'] ?? '' ) ); ?>"></label>
										<label>End time<input type="time" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][end]" value="<?php echo esc_attr( (string) ( $law_row['end'] ?? '' ) ); ?>"></label>
										<label class="law-row-wide">Description<textarea rows="3" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][description]"><?php echo esc_textarea( (string) ( $law_row['description'] ?? '' ) ); ?></textarea></label>
										<label class="law-row-wide">Speakers (names, comma separated)<input type="text" <?php echo $law_is_template ? 'data-name' : 'name'; ?>="sessions[<?php echo esc_attr( $law_is_template ? '__i__' : $law_i ); ?>][speakers]" value="<?php echo esc_attr( (string) ( $law_row['speakers'] ?? '' ) ); ?>"></label>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<button type="button" class="button law-row-add" data-law-add="sessions">Add a session</button>
					</fieldset>

					<fieldset id="law-section-finish">
						<legend>Finish</legend>
						<?php if ( ! $law_post || 'law-draft' === $law_post->post_status ) : ?>
							<p class="law-form-field law-terms">
								<label><input type="checkbox" name="terms" value="1" <?php checked( (bool) $law_value( 'terms' ) ); ?>>
									I accept the <a href="<?php echo esc_url( home_url( '/terms-conditions/' ) ); ?>" target="_blank" rel="noopener">terms &amp; conditions</a> *</label>
								<?php $law_error_message( 'terms' ); ?>
							</p>
						<?php endif; ?>

						<p class="law-form-buttons">
							<?php if ( ! $law_post || in_array( $law_post->post_status, array( 'law-draft' ), true ) ) : ?>
								<button type="submit" name="law_form_action" value="draft" class="button">Save draft</button>
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
