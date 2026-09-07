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

get_header();

$law_reg_state  = function_exists( 'law_registration_state' ) ? law_registration_state() : array( 'errors' => array(), 'input' => array() );
$law_reg_errors = (array) $law_reg_state['errors'];
$law_reg_values = (array) $law_reg_state['input'];

// The booking modal's register link: ?role=attendee locks the role (the Role
// section is hidden and a hidden input posts it) and ?redirect_to= returns the
// new user to the event page they came from. Both survive an error round trip
// (the handler carries them back onto this URL).
$law_reg_locked_role = sanitize_key( (string) ( $_GET['role'] ?? '' ) );
if ( ! function_exists( 'law_registration_roles' ) || ! isset( law_registration_roles()[ $law_reg_locked_role ] ) ) {
	$law_reg_locked_role = '';
}
$law_reg_redirect = wp_validate_redirect( wp_unslash( (string) ( $_GET['redirect_to'] ?? '' ) ), '' );
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero auth-hero law-event-form-hero" style="background-image: url('<?php echo law_asset( 'assets/images/patrons-and-committee-bg.jpg' ); ?>');">
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

					<?php if ( $law_reg_errors ) : ?>
						<div class="law-form-notice is-error" role="alert">Please fix the highlighted fields below.</div>
					<?php endif; ?>

					<form class="law-event-form law-auth-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="law_register">
						<?php wp_nonce_field( 'law_register' ); ?>
						<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>
						<?php if ( '' !== $law_reg_locked_role ) : ?>
							<input type="hidden" name="roles[]" value="<?php echo esc_attr( $law_reg_locked_role ); ?>">
							<input type="hidden" name="locked_role" value="<?php echo esc_attr( $law_reg_locked_role ); ?>">
						<?php endif; ?>
						<?php if ( '' !== $law_reg_redirect ) : ?>
							<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $law_reg_redirect ); ?>">
						<?php endif; ?>

						<?php get_template_part( 'parts/events/profile-fields', null, array( 'values' => $law_reg_values, 'errors' => $law_reg_errors, 'registration' => true, 'locked_role' => $law_reg_locked_role ) ); ?>

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
<?php endwhile; endif; ?>

<?php get_footer(); ?>
