<?php
/**
 * The committee's flagship editor, rendered by
 * templates/account-dashboard-flagship.php.
 *
 * The same fields as the wp-admin Flagship screen, writing the same post
 * through the same code: this part only draws the form. The top-level fields
 * are the theme's own front-end form markup (.law-form-field, the light form
 * variant the other committee screens use); the session agenda comes from
 * functions/events/flagship-form.php, which the wp-admin screen prints too, so
 * the complicated half of the form exists once.
 *
 * A refused save redirects, so the typed values come back through
 * law_flagship_dashboard_state() and win over the stored ones — the same rule
 * law_events_form_values() and the speakers dashboard apply, so a validation
 * error never throws away a half-typed agenda.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Belt and braces: this part is only reached from a committee-gated template
// and its handler re-checks too, but it renders an editing form, so it checks
// for itself rather than trusting its caller (as parts/events/thread.php does).
if ( ! law_user_is_committee() ) {
	echo '<p>' . esc_html__( 'This dashboard is for the LAW committee.', 'law' ) . '</p>';
	return;
}

if ( ! law_flagship_event_id() ) {
	law_flagship_ensure_post();
}
$law_fm_id = law_flagship_event_id();
if ( ! $law_fm_id ) {
	echo '<p>' . esc_html__( 'The flagship event could not be created. Please check with an administrator.', 'law' ) . '</p>';
	return;
}

$law_fm_state  = law_flagship_dashboard_state();
$law_fm_errors = (array) $law_fm_state['errors'];
$law_fm_input  = (array) $law_fm_state['input'];
$law_fm_notice = sanitize_key( $_GET['law_notice'] ?? '' );

// Stored values, then anything a refused save is handing back.
$law_fm_values = law_flagship_form_values( $law_fm_id );
if ( $law_fm_input ) {
	$law_fm_values = array_merge( $law_fm_values, $law_fm_input );
}

$law_fm_public = law_flagship_public_url( $law_fm_id );
$law_fm_live   = 'publish' === get_post_status( $law_fm_id );
$law_fm_week   = function_exists( 'law_calendar_week_days' ) ? law_calendar_week_days() : array();

get_template_part(
	'parts/layout/back-link',
	null,
	array(
		// Committee to the dashboard, the audience-aware rule the other
		// committee views follow.
		'url'   => function_exists( 'law_account_url' ) ? law_account_url( 'dashboard' ) : home_url( '/account/dashboard/' ),
		'label' => __( 'Back to all events', 'law' ),
	)
);
?>

<h1 class="law-dashboard__title"><?php esc_html_e( 'Manage flagship', 'law' ); ?></h1>
<?php if ( '' !== $law_fm_public && $law_fm_live ) : ?>
	<p class="law-dashboard__lede">
		<a href="<?php echo esc_url( $law_fm_public ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'View the flagship page', 'law' ); ?></a>
	</p>
<?php endif; ?>

<?php if ( 'flagship-saved' === $law_fm_notice ) : ?>
	<div class="law-form-notice is-success" role="status"><?php esc_html_e( 'Flagship event saved.', 'law' ); ?></div>
<?php elseif ( 'flagship-denied' === $law_fm_notice ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php esc_html_e( 'Sorry, managing the flagship event is for the committee.', 'law' ); ?></div>
<?php elseif ( 'rate-limited' === $law_fm_notice ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php esc_html_e( 'Too many changes in a short time; please wait a moment and try again.', 'law' ); ?></div>
<?php endif; ?>

<?php if ( $law_fm_errors ) : ?>
	<div class="law-form-notice is-error" role="alert">
		<p><?php esc_html_e( 'The flagship event was not saved:', 'law' ); ?></p>
		<ul>
			<?php foreach ( $law_fm_errors as $law_fm_error ) : ?>
				<li><?php echo esc_html( $law_fm_error ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( ! $law_fm_live ) : ?>
	<div class="law-form-notice" role="status">
		<?php esc_html_e( 'This event is not on the programme yet. Tick "Show on the programme" below and save to publish it at', 'law' ); ?>
		<code><?php echo esc_html( $law_fm_public ); ?></code>.
	</div>
<?php endif; ?>

<div class="grid-x grid-padding-x">
	<div class="large-12 cell">
		<form class="law-event-form law-event-form--light law-flagship-form" method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_flagship_manage">
			<?php wp_nonce_field( 'law_flagship_manage' ); ?>
			<?php law_events_honeypot_field(); ?>

			<fieldset>
				<legend><?php esc_html_e( 'The event', 'law' ); ?></legend>

				<?php
				// The repeater's sentinel rides with the first fields: PHP's
				// max_input_vars truncates a long POST from the END, so anything
				// that must survive comes before the agenda.
				?>
				<input type="hidden" name="law_flagship[sessions_present]" value="1">

				<?php
				// First, because it is the decision the whole screen hangs off:
				// everything below is either on the programme or it is not
				// (Denis, 9 September 2026).
				?>
				<p class="law-form-field">
					<label>
						<input type="checkbox" name="law_flagship[show]" value="1"
							<?php checked( ! empty( $law_fm_values['show'] ) ); ?>>
						<?php esc_html_e( 'Show on the programme', 'law' ); ?>
					</label>
					<span class="law-form-hint"><?php esc_html_e( 'Until this is ticked the flagship is a draft: its page is not public and no block appears on the programme.', 'law' ); ?></span>
				</p>

				<p class="law-form-field">
					<label for="law-fm-title"><?php esc_html_e( 'Title *', 'law' ); ?></label>
					<input type="text" id="law-fm-title" name="law_flagship[title]"
						value="<?php echo esc_attr( (string) $law_fm_values['title'] ); ?>" required>
				</p>

				<div class="law-form-field">
					<label for="law-fm-description"><?php esc_html_e( 'Description', 'law' ); ?></label>
					<?php
					law_rich_text_field(
						array(
							'name'  => 'law_flagship[description]',
							'id'    => 'law-fm-description',
							'value' => (string) $law_fm_values['description'],
							'rows'  => 10,
							'label' => __( 'Flagship event description', 'law' ),
						)
					);
					?>
				</div>

				<div class="law-row-grid">
					<p class="law-form-field">
						<label for="law-fm-date"><?php esc_html_e( 'Date *', 'law' ); ?></label>
						<input type="date" id="law-fm-date" name="law_flagship[date]"
							value="<?php echo esc_attr( (string) $law_fm_values['date'] ); ?>" required>
						<span class="law-form-hint">
							<?php esc_html_e( '2 December by default. It must fall inside the programme week for the block to sit under a day.', 'law' ); ?>
							<?php if ( $law_fm_week && ! isset( $law_fm_week[ (string) $law_fm_values['date'] ] ) ) : ?>
								<strong><?php esc_html_e( 'This date is outside the programme week, so the block will render above the days rather than under one.', 'law' ); ?></strong>
							<?php endif; ?>
						</span>
					</p>

					<p class="law-form-field">
						<label for="law-fm-venue"><?php esc_html_e( 'Location', 'law' ); ?></label>
						<input type="text" id="law-fm-venue" name="law_flagship[venue]"
							value="<?php echo esc_attr( (string) $law_fm_values['venue'] ); ?>">
						<span class="law-form-hint"><?php esc_html_e( 'A real address is mapped on the event page. A placeholder such as "tbc" shows the text without a map.', 'law' ); ?></span>
					</p>
				</div>

				<div class="law-form-field law-flagship-hero">
					<span class="law-form-label"><?php esc_html_e( 'Banner and preview image', 'law' ); ?></span>
					<?php
					// The same media-library control as the wp-admin screen, not a
					// file input: the committee holds upload_files, so it can pick an
					// image it has already uploaded instead of re-uploading it, and
					// there is one image control rather than two. Its own label is
					// the instruction that belongs with the button, so it renders
					// small and tight to it (assets/css/flagship-dashboard.css)
					// rather than as a second field label.
					law_field_media( 'law_flagship[hero_image_id]', __( 'Choose from the media library', 'law' ), (int) $law_fm_values['hero_image_id'] );
					?>
					<span class="law-form-hint"><?php esc_html_e( 'Used for the block on the programme and the banner on the flagship page. Leave it empty to use the default banner photograph.', 'law' ); ?></span>
				</div>

			</fieldset>

			<fieldset>
				<legend><?php esc_html_e( 'Sessions', 'law' ); ?></legend>
				<?php
				// The shared agenda fields, identical to the wp-admin screen's
				// (functions/events/flagship-form.php). No heading of its own:
				// the fieldset legend above already says Sessions.
				law_flagship_render_sessions( (array) $law_fm_values['sessions'], '' );
				?>
			</fieldset>

			<p class="law-form-buttons">
				<button type="submit" class="button orange"><?php esc_html_e( 'Save flagship event', 'law' ); ?></button>
			</p>
		</form>
	</div>
</div>

<?php law_flagship_render_new_speaker_template(); ?>
