<?php
/**
 * The committee's create/edit form for ONE external event
 * (/account/dashboard/?law_external=<id|new>, functions/events/external-events.php).
 *
 * Rendered as a branch of Manage events rather than on a dashboard of its own,
 * because an external event IS one of the events that dashboard lists: the
 * committee finds it in the same table, filters for it with the same Organiser
 * select, and exports it in the same spreadsheet.
 *
 * The committee edit form's shape (parts/events/committee-event-form.php): the
 * --light variant on the white dashboard, never the dark auth hero the host
 * form uses, because this screen is reached from a white table and a navy form
 * in the middle of it reads as a different site.
 *
 * The Speakers and Session agenda sections are the SAME partials the host and
 * committee event forms render, and the same savers write them. Two of the four
 * events migrated from form 10 (Event > external events) carry a full published
 * running order — eight sessions and thirteen — so this is the section that
 * does the most work on the screen, and a second implementation of it would
 * have been the most expensive thing in this feature to keep in step.
 *
 * What is deliberately NOT here, decided from the four migrated entries, every
 * one of which left all of it empty: the fee and invoice block (nobody is
 * invoiced), tickets available and venue capacity (nobody books here), venue
 * needed (that asks LAW to find a venue, and an external organiser has one),
 * co-owners and event contacts (the committee owns the record), the preferred
 * slots (an external organiser picks its own hours) and the terms consent
 * (nobody is agreeing to anything).
 *
 * Args: event_id (0 for a new external event).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_xm_id     = absint( $args['event_id'] ?? 0 );
$law_xm_post   = $law_xm_id ? get_post( $law_xm_id ) : null;
$law_xm_state  = law_external_event_state();
$law_xm_errors = (array) $law_xm_state['errors'];
$law_xm_values = law_external_event_values( $law_xm_post, $law_xm_state );
$law_xm_new    = ! $law_xm_id;
$law_xm_back   = law_external_event_url();

// The address it WILL have. get_permalink() on a draft hands back
// ?post_type=law_event&p=995, which is neither the address it will get nor
// anything worth showing anybody (law_events_public_url(), source.php).
$law_xm_public = $law_xm_id ? (string) law_events_public_url( $law_xm_id ) : '';

get_template_part(
	'parts/layout/back-link',
	null,
	array( 'url' => $law_xm_back, 'label' => __( 'Back to all events', 'law' ) )
);
?>

<h1 class="law-dashboard__title">
	<?php echo esc_html( $law_xm_new ? __( 'Create an external event', 'law' ) : $law_xm_post->post_title ); ?>
	<?php if ( ! $law_xm_new ) : ?>
		<span class="law-cal-card__badge law-cal-card__badge--external"><?php esc_html_e( 'External', 'law' ); ?></span>
	<?php endif; ?>
</h1>
<p class="law-dashboard__lede">
	<?php esc_html_e( 'An event another organisation runs during the week and registers people for on its own website. It goes on the programme with the rest, and its Register button links out to the organiser.', 'law' ); ?>
</p>

<?php if ( $law_xm_errors ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php esc_html_e( 'Please fix the highlighted fields below.', 'law' ); ?></div>
<?php endif; ?>

<?php if ( ! $law_xm_new && 'publish' !== $law_xm_post->post_status ) : ?>
	<div class="law-form-notice" role="status">
		<p><?php esc_html_e( 'This event is a draft: it is not on the programme and its page is not public. Publishing it puts it straight on the programme at:', 'law' ); ?></p>
		<?php // Its own line: a URL inline in a sentence wraps mid-address. ?>
		<p><code><?php echo esc_html( $law_xm_public ); ?></code></p>
	</div>
<?php endif; ?>

<div class="grid-x grid-padding-x">
	<div class="large-9 cell">
		<form class="law-event-form law-event-form--light" method="post" enctype="multipart/form-data"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_external_event">
			<input type="hidden" name="law_external_id" value="<?php echo esc_attr( (string) $law_xm_id ); ?>">
			<?php wp_nonce_field( 'law_external_event' ); ?>
			<?php law_events_honeypot_field(); ?>

			<fieldset id="law-section-details">
				<legend><?php esc_html_e( 'Event details', 'law' ); ?></legend>

				<p class="law-form-field">
					<label for="law-xm-title"><?php esc_html_e( 'Event title *', 'law' ); ?></label>
					<input type="text" id="law-xm-title" name="event_title" required
						value="<?php echo esc_attr( (string) ( $law_xm_values['event_title'] ?? '' ) ); ?>">
					<?php law_external_event_error( $law_xm_errors, 'event_title' ); ?>
				</p>

				<div class="law-row-grid">
					<p class="law-form-field">
						<label for="law-xm-type"><?php esc_html_e( 'Event type *', 'law' ); ?></label>
						<?php // required, with formnovalidate on Save as draft below: the
						// browser then reports every missing field at once rather than
						// letting the server hand them back one wave at a time, and a
						// draft is still allowed to be incomplete. law-rich-text.js
						// honours formnovalidate for the description too. ?>
						<select id="law-xm-type" name="event_type" required>
							<option value=""><?php esc_html_e( 'Choose…', 'law' ); ?></option>
							<?php foreach ( law_events_cpt_field_choices( '63' ) as $law_xm_choice ) : ?>
								<option value="<?php echo esc_attr( $law_xm_choice ); ?>" <?php selected( (string) ( $law_xm_values['event_type'] ?? '' ), $law_xm_choice ); ?>><?php echo esc_html( $law_xm_choice ); ?></option>
							<?php endforeach; ?>
						</select>
						<?php law_external_event_error( $law_xm_errors, 'event_type' ); ?>
					</p>

					<p class="law-form-field">
						<label for="law-xm-hosts"><?php esc_html_e( 'Host organisation(s)', 'law' ); ?></label>
						<input type="text" id="law-xm-hosts" name="host_organisations"
							value="<?php echo esc_attr( (string) ( $law_xm_values['host_organisations'] ?? '' ) ); ?>">
						<span class="law-form-hint"><?php esc_html_e( 'Who is running it. Separate several with semi-colons.', 'law' ); ?></span>
					</p>
				</div>

				<?php
				// The date and the two times, typed rather than chosen from the
				// programme's slots: an external organiser sets its own hours,
				// and the day grid groups by whatever times it finds, so an
				// event at 11:15 simply gets its own bar (functions/calendar.php).
				?>
				<div class="law-row-grid law-row-grid--three">
					<p class="law-form-field">
						<label for="law-xm-date"><?php esc_html_e( 'Date *', 'law' ); ?></label>
						<input type="date" id="law-xm-date" name="external_date" required
							value="<?php echo esc_attr( (string) ( $law_xm_values['external_date'] ?? '' ) ); ?>">
						<span class="law-form-hint"><?php esc_html_e( 'It must fall inside the programme week to sit under a day.', 'law' ); ?></span>
						<?php law_external_event_error( $law_xm_errors, 'external_date' ); ?>
					</p>
					<p class="law-form-field">
						<label for="law-xm-start"><?php esc_html_e( 'Starts', 'law' ); ?></label>
						<input type="time" id="law-xm-start" name="external_start"
							value="<?php echo esc_attr( (string) ( $law_xm_values['external_start'] ?? '' ) ); ?>">
						<?php law_external_event_error( $law_xm_errors, 'external_start' ); ?>
					</p>
					<p class="law-form-field">
						<label for="law-xm-end"><?php esc_html_e( 'Ends', 'law' ); ?></label>
						<input type="time" id="law-xm-end" name="external_end"
							value="<?php echo esc_attr( (string) ( $law_xm_values['external_end'] ?? '' ) ); ?>">
						<span class="law-form-hint"><?php esc_html_e( 'Leave empty for an event that runs into the evening; the programme then reads "onwards".', 'law' ); ?></span>
					</p>
				</div>

				<div class="law-form-field">
					<label for="law-xm-description"><?php esc_html_e( 'Description *', 'law' ); ?></label>
					<?php
					law_rich_text_field(
						array(
							'name'     => 'description',
							'id'       => 'law-xm-description',
							'value'    => (string) ( $law_xm_values['description'] ?? '' ),
							'rows'     => 8,
							'required' => __( 'Please describe the event.', 'law' ),
						)
					);
					?>
					<?php law_external_event_error( $law_xm_errors, 'description' ); ?>
				</div>

				<?php
				$law_xm_sectors  = (array) ( $law_xm_values['sectors'] ?? array() );
				$law_xm_show_jur = in_array( 'Jurisdiction-specific', $law_xm_sectors, true ) || '' !== (string) ( $law_xm_values['sector_jurisdiction'] ?? '' ) || isset( $law_xm_errors['sector_jurisdiction'] );
				$law_xm_show_oth = in_array( 'Other / sector-neutral', $law_xm_sectors, true ) || '' !== (string) ( $law_xm_values['sector_other'] ?? '' ) || isset( $law_xm_errors['sector_other'] );
				?>
				<div class="law-form-field">
					<span class="law-form-label"><?php esc_html_e( 'Sector', 'law' ); ?></span>
					<div class="law-choices law-choices--cols-2">
						<?php
						foreach ( law_events_cpt_field_choices( '60' ) as $law_xm_choice ) :
							$law_xm_toggle = 'Jurisdiction-specific' === $law_xm_choice ? 'law-xm-sector-j-field'
								: ( 'Other / sector-neutral' === $law_xm_choice ? 'law-xm-sector-o-field' : '' );
							?>
							<label><input type="checkbox" name="sectors[]" value="<?php echo esc_attr( $law_xm_choice ); ?>"
								<?php echo $law_xm_toggle ? 'data-law-toggles="' . esc_attr( $law_xm_toggle ) . '"' : ''; ?>
								<?php checked( in_array( $law_xm_choice, $law_xm_sectors, true ) ); ?>>
								<?php echo esc_html( $law_xm_choice ); ?></label>
						<?php endforeach; ?>
					</div>
				</div>

				<div class="law-row-grid">
					<p class="law-form-field" id="law-xm-sector-j-field" <?php echo $law_xm_show_jur ? '' : 'hidden'; ?>>
						<label for="law-xm-sector-j"><?php esc_html_e( 'Jurisdiction-specific: please specify *', 'law' ); ?></label>
						<input type="text" id="law-xm-sector-j" name="sector_jurisdiction"
							value="<?php echo esc_attr( (string) ( $law_xm_values['sector_jurisdiction'] ?? '' ) ); ?>">
						<?php law_external_event_error( $law_xm_errors, 'sector_jurisdiction' ); ?>
					</p>
					<p class="law-form-field" id="law-xm-sector-o-field" <?php echo $law_xm_show_oth ? '' : 'hidden'; ?>>
						<label for="law-xm-sector-o"><?php esc_html_e( 'Other / sector-neutral: please specify *', 'law' ); ?></label>
						<input type="text" id="law-xm-sector-o" name="sector_other"
							value="<?php echo esc_attr( (string) ( $law_xm_values['sector_other'] ?? '' ) ); ?>">
						<?php law_external_event_error( $law_xm_errors, 'sector_other' ); ?>
					</p>
				</div>

				<p class="law-form-field">
					<label for="law-xm-url"><?php esc_html_e( 'External booking URL', 'law' ); ?></label>
					<input type="url" id="law-xm-url" name="external_url" placeholder="https://"
						value="<?php echo esc_attr( (string) ( $law_xm_values['external_url'] ?? '' ) ); ?>">
					<span class="law-form-hint"><?php esc_html_e( 'Where the Register button sends people. It opens in a new tab. Leave it empty until the organiser opens registration and the listing shows a disabled "Registration opening soon" button instead.', 'law' ); ?></span>
					<?php law_external_event_error( $law_xm_errors, 'external_url' ); ?>
				</p>

				<p class="law-form-field">
					<label for="law-xm-venue"><?php esc_html_e( 'Venue', 'law' ); ?></label>
					<input type="text" id="law-xm-venue" name="venue"
						value="<?php echo esc_attr( (string) ( $law_xm_values['venue'] ?? '' ) ); ?>">
				</p>
			</fieldset>

			<?php
			// The shared repeaters. $law_xm_errors is the law_events_form_state()
			// shape (field => array of messages), which is what these expect.
			$law_xm_shared = array(
				'post'    => $law_xm_post,
				'values'  => $law_xm_values,
				'errors'  => $law_xm_errors,
				'locked'  => array(),
				'context' => 'external',
			);
			get_template_part( 'parts/events/event-form-speakers', null, $law_xm_shared );
			get_template_part( 'parts/events/event-form-agenda', null, $law_xm_shared );
			?>

			<fieldset id="law-section-finish">
				<legend><?php esc_html_e( 'Finish', 'law' ); ?></legend>
				<p class="law-form-hint">
					<?php esc_html_e( 'Publishing puts the event straight on the programme. There is no approval step: the committee is recording an event that already exists, not reviewing a proposal.', 'law' ); ?>
				</p>
				<p class="law-form-buttons">
					<?php
					// Draft first, publish second: the destructive-to-the-public
					// one is the one that needs the deliberate press, and a
					// committee member part-way through transcribing a running
					// order reaches for Save as draft far more often.
					?>
					<button type="submit" name="law_form_action" value="draft" class="button second" formnovalidate><?php esc_html_e( 'Save as draft', 'law' ); ?></button>
					<button type="submit" name="law_form_action" value="publish" class="button orange" data-law-modal-busy="<?php esc_attr_e( 'Saving…', 'law' ); ?>">
						<?php echo esc_html( $law_xm_new || 'publish' !== $law_xm_post->post_status ? __( 'Publish to the programme', 'law' ) : __( 'Save changes', 'law' ) ); ?>
					</button>
					<a class="button hollow" href="<?php echo esc_url( $law_xm_back ); ?>"><?php esc_html_e( 'Cancel', 'law' ); ?></a>
				</p>
			</fieldset>
		</form>
	</div>
</div>
