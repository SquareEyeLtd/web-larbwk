<?php
/**
 * Template Name: Register
 *
 * The branded registration page. Phase D of the events rebuild: the custom
 * registration form replaces the form 1 (User registration) Gravity Forms
 * block. The page content still renders above the form as the intro.
 *
 * @package LAW
 */

// Read BEFORE get_header(), for two reasons. law_registration_state() is a
// one-shot read (it deletes the transient), so it can only be called once in
// the page and this is the only place that may call it. And knowing here
// whether the last attempt failed is what lets the failure dialog's stylesheet
// go out in the head with everything else, instead of arriving after the
// dialog it is supposed to dress.
$law_reg_state  = function_exists( 'law_registration_state' ) ? law_registration_state() : array( 'errors' => array(), 'input' => array() );
$law_reg_errors = (array) $law_reg_state['errors'];
$law_reg_values = (array) $law_reg_state['input'];

if ( $law_reg_errors && function_exists( 'law_modal_enqueue' ) ) {
	law_modal_enqueue();
}

get_header();

// The booking modal's register link: ?redirect_to= returns the new user to the
// event page they came from, and survives an error round trip (the handler
// carries it back onto this URL). It used to carry ?role=attendee as well;
// roles went on 14 September 2026 and any signed-in person may book.
$law_reg_redirect = wp_validate_redirect( wp_unslash( (string) ( $_GET['redirect_to'] ?? '' ) ), '' );
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero auth-hero law-event-form-hero hero-solid">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<h1><?php the_title(); ?></h1>
			</div>
			<div class="large-9 cell auth-intro auth-register-content wow fadeIn">
				<?php the_content(); ?>

				<?php if ( is_user_logged_in() ) : ?>

					<p>You are already signed in. Manage your details from your
						<a href="<?php echo esc_url( home_url( '/account/profile/' ) ); ?>">profile</a>.<?php if ( '' !== $law_reg_redirect ) : ?>
						You can <a href="<?php echo esc_url( $law_reg_redirect ); ?>">return to the page you came from</a>.<?php endif; ?></p>

				<?php else : ?>

					<?php
					// A whole-form refusal (today only `expired`: the nonce on
					// a page that sat open too long) speaks for itself and
					// highlights nothing below, so it replaces the standard
					// line rather than sitting above it and contradicting it.
					$law_reg_whole_form = (string) ( $law_reg_errors['expired'][0] ?? '' );
					if ( $law_reg_errors ) :
						?>
						<div class="law-form-notice is-error" role="alert"><?php echo esc_html( '' !== $law_reg_whole_form ? $law_reg_whole_form : 'Please fix the highlighted fields below.' ); ?></div>
						<?php
					endif;
					?>

					<form class="law-event-form law-auth-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="law_register">
						<?php wp_nonce_field( 'law_register' ); ?>
						<?php law_events_honeypot_field(); ?>
						<?php if ( '' !== $law_reg_redirect ) : ?>
							<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $law_reg_redirect ); ?>">
						<?php endif; ?>

						<?php get_template_part( 'parts/events/profile-fields', null, array( 'values' => $law_reg_values, 'errors' => $law_reg_errors, 'registration' => true ) ); ?>

						<fieldset>
							<legend>Login details</legend>
							<div class="law-row-grid">
								<p class="law-form-field<?php echo isset( $law_reg_errors['password'][0] ) ? ' is-invalid' : ''; ?>"><label for="law-reg-pass">Password (<?php echo esc_html( (string) LAW_AUTH_MIN_PASSWORD_LENGTH ); ?> characters minimum) *</label>
									<input type="password" id="law-reg-pass" name="password" required minlength="<?php echo esc_attr( (string) LAW_AUTH_MIN_PASSWORD_LENGTH ); ?>" autocomplete="new-password" data-law-strength></p>
								<p class="law-form-field<?php echo isset( $law_reg_errors['password_confirm'][0] ) ? ' is-invalid' : ''; ?>"><label for="law-reg-pass2">Confirm password *</label>
									<input type="password" id="law-reg-pass2" name="password_confirm" required autocomplete="new-password" data-law-strength-confirm></p>
							</div>
							<p class="law-pass-strength" data-law-strength-output hidden></p>
							<?php
							if ( isset( $law_reg_errors['password'][0] ) ) {
								echo '<p class="law-form-error" role="alert">' . esc_html( $law_reg_errors['password'][0] ) . '</p>';
							}
							if ( isset( $law_reg_errors['password_confirm'][0] ) ) {
								echo '<p class="law-form-error" role="alert">' . esc_html( $law_reg_errors['password_confirm'][0] ) . '</p>';
							}
							?>
						</fieldset>

						<p class="law-form-buttons">
							<button type="submit" class="button orange">Create account</button>
						</p>
					</form>

					<p class="law-auth-alt">Already have an account? <a href="<?php echo esc_url( home_url( '/login/' ) ); ?>">Sign in</a></p>

				<?php endif; ?>
			</div>
		</div>
	</div>
</section>

<?php
// The same failure again as a dialog opened on load (law-modal.js), the house
// pattern for a failed save (law_events_form_error_modal(), 21 September
// 2026): the notice above is off screen by the time somebody has filled in a
// page of profile fields and pressed the button at the foot, and an expired
// session that announces itself quietly is how this form came to be reported
// as simply broken.
//
// OUTSIDE the section on purpose, which is where this page differs from the
// event form. The column the notice sits in carries `wow fadeIn`, and WOW.js
// holds a `.wow` element at `visibility: hidden` until it scrolls into view;
// visibility inherits, so a dialog rendered inside that column would be
// invisible however correctly it opened. Nothing wraps it out here.
if ( ! is_user_logged_in() && $law_reg_errors ) {
	law_events_form_error_modal( $law_reg_errors, 'Your account was not created' );
}
?>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
