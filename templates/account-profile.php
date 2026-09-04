<?php
/**
 * Template Name: Profile (custom)
 *
 * Phase D: the custom self-service profile form replacing form 3 (User
 * profile). Renders in the account-pages hero; role changes are limited to
 * the three self-service roles.
 */

get_header();

$law_profile_user   = get_current_user_id();
$law_profile_state  = function_exists( 'law_profile_state' ) ? law_profile_state() : array( 'errors' => array() );
$law_profile_errors = (array) $law_profile_state['errors'];
$law_profile_values = $law_profile_user ? law_profile_values( $law_profile_user ) : array();
$law_profile_notice = sanitize_key( $_GET['law_notice'] ?? '' );
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero auth-hero law-event-form-hero" style="background-image: url('<?php echo law_asset( 'assets/images/patrons-and-committee-bg.jpg' ); ?>');">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<h1><?php the_title(); ?></h1>
			</div>
			<div class="large-9 cell auth-intro wow fadeIn">

				<?php if ( ! is_user_logged_in() ) : ?>

					<p>Please <a href="<?php echo esc_url( wp_login_url( get_permalink() ) ); ?>">sign in</a> to edit your profile.</p>

				<?php else : ?>

					<?php if ( 'profile-saved' === $law_profile_notice ) : ?>
						<div class="law-form-notice" role="status">Profile saved.</div>
					<?php endif; ?>
					<?php if ( $law_profile_errors ) : ?>
						<div class="law-form-notice is-error" role="alert">Please fix the highlighted fields below.</div>
					<?php endif; ?>

					<form class="law-event-form law-auth-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="law_profile">
						<?php wp_nonce_field( 'law_profile' ); ?>
						<p class="law-hp" aria-hidden="true"><label>Leave this field empty<input type="text" name="law_website_url" tabindex="-1" autocomplete="off"></label></p>

						<?php get_template_part( 'parts/events/profile-fields', null, array( 'values' => $law_profile_values, 'errors' => $law_profile_errors ) ); ?>

						<fieldset>
							<legend>Login details</legend>
							<p class="law-form-field law-choices">
								<label><input type="checkbox" name="change_password" value="1" data-law-toggle="password-fields"> Change password?</label>
							</p>
							<div class="law-password-fields" hidden>
								<p class="law-form-field"><label for="law-prof-current">Current password</label>
									<input type="password" id="law-prof-current" name="current_password" autocomplete="current-password">
									<?php if ( isset( $law_profile_errors['current_password'][0] ) ) : ?>
										<span class="law-form-error" role="alert"><?php echo esc_html( $law_profile_errors['current_password'][0] ); ?></span>
									<?php endif; ?></p>
								<div class="law-row-grid">
									<p class="law-form-field"><label for="law-prof-pass">New password (10 characters minimum)</label>
										<input type="password" id="law-prof-pass" name="password" minlength="10" autocomplete="new-password"></p>
									<p class="law-form-field"><label for="law-prof-pass2">Confirm new password</label>
										<input type="password" id="law-prof-pass2" name="password_confirm" autocomplete="new-password"></p>
								</div>
								<?php
								foreach ( array( 'password', 'password_confirm' ) as $law_pw_field ) {
									if ( isset( $law_profile_errors[ $law_pw_field ][0] ) ) {
										echo '<p class="law-form-error" role="alert">' . esc_html( $law_profile_errors[ $law_pw_field ][0] ) . '</p>';
									}
								}
								?>
							</div>
						</fieldset>

						<p class="law-form-buttons">
							<button type="submit" class="button orange">Save profile</button>
						</p>
					</form>

					<script>
					(function () {
						var toggle = document.querySelector('[data-law-toggle="password-fields"]');
						var fields = document.querySelector('.law-password-fields');
						if (toggle && fields) {
							fields.hidden = !toggle.checked;
							toggle.addEventListener('change', function () { fields.hidden = !toggle.checked; });
						}
					})();
					</script>

				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
