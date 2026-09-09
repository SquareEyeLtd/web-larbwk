<?php
/**
 * The committee's speaker editor, rendered by
 * templates/account-speakers-dashboard.php at ?law_speaker=<id>.
 *
 * Layout follows the brief: the identity fields once at the top, then one block
 * per event the speaker appears at, then a single "Save changes" button. That
 * split is not cosmetic — full name, email and website live on the law_speaker
 * post, so editing them changes every event at once, while role, organisation,
 * job title, photo and biography are stored on the event's own _law_speakers
 * row and change only that event. The copy on the page says so, because a
 * committee member cannot be expected to know the data model.
 *
 * Args:
 * - speaker_id int The law_speaker post ID (already committee-gated).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_sm_id      = (int) ( $args['speaker_id'] ?? 0 );
$law_sm_speaker = $law_sm_id ? get_post( $law_sm_id ) : null;
if ( ! $law_sm_speaker instanceof WP_Post || LAW_SPEAKER_CPT !== $law_sm_speaker->post_type ) {
	echo '<p>' . esc_html__( 'That speaker could not be found.', 'law' ) . '</p>';
	return;
}

$law_sm_state       = law_speakers_dashboard_state();
$law_sm_errors      = (array) $law_sm_state['errors'];
$law_sm_input       = (array) $law_sm_state['input'];
$law_sm_appearances = law_speakers_dashboard_appearances( $law_sm_id );
$law_sm_notice      = sanitize_key( $_GET['law_notice'] ?? '' );
$law_sm_profile     = function_exists( 'law_speaker_url' ) ? law_speaker_url( $law_sm_id ) : '';

// Typed values win over stored ones, the same rule law_events_form_values()
// applies, so a refused save does not throw away what was typed.
$law_sm_value = function ( $key, $stored ) use ( $law_sm_input ) {
	return array_key_exists( $key, $law_sm_input ) ? (string) $law_sm_input[ $key ] : (string) $stored;
};
$law_sm_row_value = function ( $event_id, $field, $stored ) use ( $law_sm_input ) {
	$posted = $law_sm_input['appearances'][ $event_id ][ $field ] ?? null;
	return null === $posted ? (string) $stored : (string) $posted;
};
$law_sm_error = function ( $field ) use ( $law_sm_errors ) {
	if ( isset( $law_sm_errors[ $field ] ) ) {
		$message = is_array( $law_sm_errors[ $field ] ) ? ( $law_sm_errors[ $field ][0] ?? '' ) : $law_sm_errors[ $field ];
		// A <span>, not a <p>: these render inside p.law-form-field and a nested
		// <p> would be auto-closed, orphaning the message from its field.
		echo '<span class="law-form-error" role="alert">' . esc_html( (string) $message ) . '</span>';
	}
};

get_template_part( 'parts/layout/back-link', null, array(
	'url'   => law_speakers_dashboard_url(),
	'label' => __( 'Back to all speakers', 'law' ),
) );
?>

<h1 class="law-dashboard__title"><?php echo esc_html( $law_sm_speaker->post_title ); ?></h1>
<p class="law-dashboard__lede">
	<?php esc_html_e( 'The name, email and website belong to the speaker, so a change here applies to every event. Everything under an event heading is what that event shows for them.', 'law' ); ?>
	<?php if ( '' !== $law_sm_profile ) : ?>
		<a href="<?php echo esc_url( $law_sm_profile ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View public profile', 'law' ); ?></a>
	<?php endif; ?>
</p>

<?php if ( 'speaker-saved' === $law_sm_notice ) : ?>
	<div class="law-form-notice is-success" role="status"><?php esc_html_e( 'Speaker updated.', 'law' ); ?></div>
<?php elseif ( 'speaker-denied' === $law_sm_notice ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php esc_html_e( 'Sorry, managing speakers is for the committee.', 'law' ); ?></div>
<?php elseif ( 'rate-limited' === $law_sm_notice ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php esc_html_e( 'Too many changes in a short time; please wait a moment and try again.', 'law' ); ?></div>
<?php endif; ?>

<?php if ( $law_sm_errors ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php esc_html_e( 'Please fix the highlighted fields below.', 'law' ); ?></div>
<?php endif; ?>

<div class="grid-x grid-padding-x">
	<div class="large-12 cell">
		<form class="law-event-form law-event-form--light law-speaker-form" method="post" enctype="multipart/form-data"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_speaker_manage">
			<input type="hidden" name="law_speaker_id" value="<?php echo esc_attr( (string) $law_sm_id ); ?>">
			<?php wp_nonce_field( 'law_speaker_manage' ); ?>
			<?php law_events_honeypot_field(); ?>

			<fieldset id="law-section-speaker">
				<legend><?php esc_html_e( 'The speaker', 'law' ); ?></legend>
				<p class="law-form-hint"><?php esc_html_e( 'Shared across every event this person appears at.', 'law' ); ?></p>

				<?php
				// First and last name separately (Denis, 9 September 2026). A record
				// created before the split has no stored parts, so its post title is
				// split for the prefill; saving here writes both and rebuilds the
				// title from them, which is how a legacy record gains real parts.
				$law_sm_name = law_speaker_name_parts( $law_sm_id );
				?>
				<div class="law-row-grid">
					<p class="law-form-field">
						<label for="law-sm-first-name"><?php esc_html_e( 'First name *', 'law' ); ?></label>
						<input type="text" id="law-sm-first-name" name="first_name" required
							value="<?php echo esc_attr( $law_sm_value( 'first_name', $law_sm_name['first'] ) ); ?>">
						<?php $law_sm_error( 'first_name' ); ?>
					</p>

					<p class="law-form-field">
						<label for="law-sm-last-name"><?php esc_html_e( 'Last name *', 'law' ); ?></label>
						<input type="text" id="law-sm-last-name" name="last_name" required
							value="<?php echo esc_attr( $law_sm_value( 'last_name', $law_sm_name['last'] ) ); ?>">
						<?php $law_sm_error( 'last_name' ); ?>
					</p>
				</div>

				<div class="law-row-grid">
					<p class="law-form-field">
						<label for="law-sm-email"><?php esc_html_e( 'Email', 'law' ); ?></label>
						<input type="email" id="law-sm-email" name="email"
							value="<?php echo esc_attr( $law_sm_value( 'email', law_event_meta( $law_sm_id, '_law_speaker_email' ) ) ); ?>">
						<?php $law_sm_error( 'email' ); ?>
						<span class="law-form-hint"><?php esc_html_e( 'How a returning speaker is matched to this record, so it must be theirs alone.', 'law' ); ?></span>
					</p>

					<p class="law-form-field">
						<label for="law-sm-website"><?php esc_html_e( 'Website profile URL', 'law' ); ?></label>
						<input type="url" id="law-sm-website" name="website"
							value="<?php echo esc_attr( $law_sm_value( 'website', law_event_meta( $law_sm_id, '_law_website' ) ) ); ?>">
						<?php $law_sm_error( 'website' ); ?>
					</p>
				</div>
			</fieldset>

			<?php if ( ! $law_sm_appearances ) : ?>
				<p class="law-form-status"><?php esc_html_e( 'This speaker is not on any event yet, so there is nothing per-event to edit. Add them to an event and its details will appear here.', 'law' ); ?></p>
			<?php endif; ?>

			<?php
			foreach ( $law_sm_appearances as $law_sm_appearance ) :
				$law_sm_event  = (int) $law_sm_appearance['event_id'];
				$law_sm_status = law_event_status_label( $law_sm_appearance['event_status'] );
				$law_sm_photo  = (int) $law_sm_appearance['photo_id'];
				$law_sm_role   = law_speaker_role_key( $law_sm_row_value( $law_sm_event, 'role', $law_sm_appearance['role'] ) );
				// A draft belongs to its host: the committee dashboard neither
				// lists nor previews one (templates/account-dashboard.php), so
				// this offers no links to it either. Its details are still
				// editable here, which is the whole point of the screen.
				$law_sm_is_draft = 'law-draft' === $law_sm_appearance['event_status'];
				$law_sm_preview  = 'publish' === $law_sm_appearance['event_status']
					? law_events_event_url( $law_sm_event )
					: add_query_arg( 'preview-event', $law_sm_event, law_account_url( 'dashboard' ) );
				$law_sm_edit     = add_query_arg( array( 'event' => $law_sm_event, 'law_edit' => 1 ), law_account_url( 'dashboard' ) );
				?>
				<fieldset id="law-section-event-<?php echo esc_attr( (string) $law_sm_event ); ?>" class="law-speaker-appearance">
					<legend>
						<?php echo esc_html( $law_sm_appearance['event_title'] ); ?>
						<span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( law_calendar_status_slug( $law_sm_status ) ); ?>"><?php echo esc_html( $law_sm_status ); ?></span>
					</legend>

					<p class="law-speaker-appearance__meta">
						<?php if ( '' !== $law_sm_appearance['event_start'] ) : ?>
							<span class="law-speaker-appearance__when"><?php echo esc_html( date_i18n( 'D j M Y, H:i', strtotime( $law_sm_appearance['event_start'] ) ) ); ?></span>
						<?php endif; ?>
						<?php if ( ! $law_sm_is_draft ) : ?>
							<?php // Confirmed events link to their real permalink, so the link says View event there, as on the committee dashboard. ?>
							<a href="<?php echo esc_url( $law_sm_preview ); ?>" target="_blank" rel="noopener"><?php
								echo esc_html( 'publish' === $law_sm_appearance['event_status'] ? __( 'View event', 'law' ) : __( 'Preview event', 'law' ) );
							?></a>
							<a href="<?php echo esc_url( $law_sm_edit ); ?>"><?php esc_html_e( 'Edit event', 'law' ); ?></a>
						<?php endif; ?>
					</p>

					<div class="law-row-grid">
						<p class="law-form-field">
							<label for="law-sm-role-<?php echo esc_attr( (string) $law_sm_event ); ?>"><?php esc_html_e( 'Role', 'law' ); ?></label>
							<select id="law-sm-role-<?php echo esc_attr( (string) $law_sm_event ); ?>" name="appearances[<?php echo esc_attr( (string) $law_sm_event ); ?>][role]">
								<option value="" <?php selected( $law_sm_role, '' ); ?>><?php esc_html_e( 'Select role', 'law' ); ?></option>
								<?php foreach ( law_speaker_roles() as $law_sm_key => $law_sm_label ) : ?>
									<option value="<?php echo esc_attr( $law_sm_key ); ?>" <?php selected( $law_sm_role, $law_sm_key ); ?>><?php echo esc_html( $law_sm_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="law-form-field">
							<label for="law-sm-org-<?php echo esc_attr( (string) $law_sm_event ); ?>"><?php esc_html_e( 'Organisation / firm / chambers', 'law' ); ?></label>
							<input type="text" id="law-sm-org-<?php echo esc_attr( (string) $law_sm_event ); ?>"
								name="appearances[<?php echo esc_attr( (string) $law_sm_event ); ?>][organisation]"
								value="<?php echo esc_attr( $law_sm_row_value( $law_sm_event, 'organisation', $law_sm_appearance['organisation'] ) ); ?>">
						</p>

						<p class="law-form-field">
							<label for="law-sm-job-<?php echo esc_attr( (string) $law_sm_event ); ?>"><?php esc_html_e( 'Job title', 'law' ); ?></label>
							<input type="text" id="law-sm-job-<?php echo esc_attr( (string) $law_sm_event ); ?>"
								name="appearances[<?php echo esc_attr( (string) $law_sm_event ); ?>][job_title]"
								value="<?php echo esc_attr( $law_sm_row_value( $law_sm_event, 'job_title', $law_sm_appearance['job_title'] ) ); ?>">
						</p>

						<p class="law-form-field law-speaker-appearance__photo">
							<label for="law-sm-photo-<?php echo esc_attr( (string) $law_sm_event ); ?>"><?php esc_html_e( 'Photo (JPG/PNG/WebP, 5 MB max)', 'law' ); ?></label>
							<?php if ( $law_sm_photo ) : ?>
								<img src="<?php echo esc_url( (string) wp_get_attachment_image_url( $law_sm_photo, 'thumbnail' ) ); ?>" alt="" width="72" height="72">
							<?php endif; ?>
							<input type="file" id="law-sm-photo-<?php echo esc_attr( (string) $law_sm_event ); ?>"
								name="speaker_photo[<?php echo esc_attr( (string) $law_sm_event ); ?>]"
								accept=".jpg,.jpeg,.png,.webp">
							<?php $law_sm_error( 'speaker_photo_' . $law_sm_event ); ?>
							<?php if ( $law_sm_photo ) : ?>
								<label class="law-speaker-appearance__remove">
									<input type="checkbox" name="appearances[<?php echo esc_attr( (string) $law_sm_event ); ?>][remove_photo]" value="1">
									<?php esc_html_e( 'Remove the photo for this event', 'law' ); ?>
								</label>
							<?php endif; ?>
							<span class="law-form-hint"><?php esc_html_e( 'Leave empty to keep the current photo. With none, the event shows the first photo ever supplied for this speaker.', 'law' ); ?></span>
						</p>
					</div>

					<?php
					// The same stored value the host's own form edits, so it gets the
					// same WYSIWYG editor (functions/events/rich-text.php). A plain
					// textarea here would flatten a host's formatting the first time
					// the committee corrected a job title on the same screen.
					?>
					<div class="law-form-field">
						<label for="law-sm-bio-<?php echo esc_attr( (string) $law_sm_event ); ?>"><?php esc_html_e( 'Biography', 'law' ); ?></label>
						<?php
						law_rich_text_field(
							array(
								'name'  => 'appearances[' . $law_sm_event . '][bio]',
								'id'    => 'law-sm-bio-' . $law_sm_event,
								'value' => (string) $law_sm_row_value( $law_sm_event, 'bio', $law_sm_appearance['bio'] ),
								'rows'  => 5,
							)
						);
						?>
					</div>
				</fieldset>
			<?php endforeach; ?>

			<p class="law-form-buttons">
				<button type="submit" class="button orange"><?php esc_html_e( 'Save changes', 'law' ); ?></button>
				<a class="button hollow" href="<?php echo esc_url( law_speakers_dashboard_url() ); ?>"><?php esc_html_e( 'Cancel', 'law' ); ?></a>
			</p>
		</form>
	</div>
</div>
