<?php
/**
 * The committee's front-end edit view (Denis, 7 September 2026), restoring
 * the full-edit parity the legacy GravityView 419 (Events (committee - all))
 * edit form provided. Rendered by templates/account-dashboard.php at
 * /account/dashboard/?event=<id>&law_edit=1 — white background, dashboard
 * styling, never the dark auth-hero the host form uses. Saves post to the
 * same law_event_form handler as the host form; law_form_context=committee
 * routes failures and the success redirect back here, and the handler never
 * fires a workflow transition from this form.
 *
 * Args:
 * - post WP_Post The event being edited (already committee-gated by the template).
 */

$law_post = $args['post'] ?? null;
if ( ! $law_post instanceof WP_Post ) {
	return;
}

$law_back_url = add_query_arg( 'event', $law_post->ID, get_permalink() );

get_template_part( 'parts/layout/back-link', null, array(
	'url'   => $law_back_url,
	'label' => __( 'Back to event', 'law' ),
) );

// Drafts belong to their host: the dashboard list excludes them, but the URL
// is reachable by hand, so refuse politely rather than render a form whose
// save would be the host's draft flow.
if ( 'law-draft' === $law_post->post_status ) {
	echo '<p>Drafts are edited by their host.</p>';
	return;
}

$law_state  = law_events_form_state();
$law_values = law_events_form_values( $law_post, $law_state );
$law_errors = (array) ( $law_state['errors'] ?? array() );
$law_locked = law_events_locked_fields( $law_post );
$law_status = law_event_status_label( $law_post );

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

<h1 class="law-dashboard__title"><?php echo esc_html( $law_post->post_title ); ?>
	<span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( law_calendar_status_slug( $law_status ) ); ?>"><?php echo esc_html( $law_status ); ?></span></h1>
<p class="law-dashboard__lede">Edit event details. Changes are saved straight to the event; the host is not notified.</p>

<?php if ( isset( $law_errors['locked'][0] ) ) : ?>
	<div class="law-form-notice is-error" role="alert"><?php echo esc_html( $law_errors['locked'][0] ); ?></div>
<?php elseif ( $law_errors ) : ?>
	<div class="law-form-notice is-error" role="alert">Please fix the highlighted fields below.</div>
<?php endif; ?>
<?php
// Edit locking: warn when someone else is in this event, and take the lock
// while this form is open. The read-only detail view never takes the lock;
// only this edit view does.
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
?>

<div class="grid-x grid-padding-x">
	<nav class="large-3 cell law-form-nav" aria-label="Form sections">
		<ol>
			<?php foreach ( $law_sections as $law_key => $law_label ) : ?>
				<li><a href="#law-section-<?php echo esc_attr( $law_key ); ?>"><?php echo esc_html( $law_label ); ?></a></li>
			<?php endforeach; ?>
		</ol>
	</nav>

	<div class="large-9 cell">
		<form class="law-event-form law-event-form--light" method="post" enctype="multipart/form-data"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="law_event_form">
			<input type="hidden" name="law_event_id" value="<?php echo esc_attr( (string) $law_post->ID ); ?>">
			<input type="hidden" name="law_form_context" value="committee">
			<?php wp_nonce_field( 'law_event_form' ); ?>
			<?php law_events_honeypot_field(); ?>

			<?php
			get_template_part( 'parts/events/event-form-fields', null, array(
				'post'    => $law_post,
				'values'  => $law_values,
				'errors'  => $law_errors,
				'locked'  => $law_locked,
				'context' => 'committee',
			) );
			?>

			<fieldset id="law-section-finish">
				<legend>Finish</legend>
				<?php $law_consent = law_event_meta( $law_post->ID, '_law_terms_consent' ); ?>
				<?php if ( ! empty( $law_consent['accepted'] ) ) : ?>
					<p class="law-form-status">
						The host accepted the
						<a href="<?php echo esc_url( law_events_terms_url() ); ?>" target="_blank" rel="noopener">terms &amp; conditions for event hosts</a>
						<?php if ( ! empty( $law_consent['at'] ) ) : ?>
							on <strong><?php echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( (string) $law_consent['at'] ) ) ); ?></strong>
						<?php endif; ?>
						when this event was submitted.
					</p>
				<?php endif; ?>

				<p class="law-form-buttons">
					<button type="submit" name="law_form_action" value="update" class="button orange">Save changes</button>
					<a class="button hollow" href="<?php echo esc_url( $law_back_url ); ?>">Cancel</a>
				</p>
			</fieldset>
		</form>
	</div>
</div>
