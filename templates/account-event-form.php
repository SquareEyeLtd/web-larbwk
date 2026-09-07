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
						<?php if ( $law_locked && ! law_user_is_committee() ) : // Committee bypasses these locks, so the host-voiced note would mislead them. ?>
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

					<?php
					get_template_part( 'parts/events/event-form-fields', null, array(
						'post'    => $law_post,
						'values'  => $law_values,
						'errors'  => $law_errors,
						'locked'  => $law_locked,
						'context' => 'host',
					) );
					?>

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
							<?php if ( $law_post && in_array( $law_post->post_status, array( 'law-cancelled', 'law-rejected' ), true ) ) : ?>
								<?php // Read-only: the handler refuses a save on these statuses too. ?>
								<span class="law-form-status">This event has been <?php echo esc_html( strtolower( law_event_status_label( $law_post ) ) ); ?> and can no longer be edited. You can still read and reply to the committee's messages from <a href="<?php echo esc_url( home_url( '/account/events/' ) ); ?>">My events</a>.</span>
							<?php elseif ( ! $law_post || in_array( $law_post->post_status, array( 'law-draft' ), true ) ) : ?>
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
