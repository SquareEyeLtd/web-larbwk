<?php
/**
 * Template Name: Login
 *
 * The branded sign-in page. The whole page lives inside the full-height hero,
 * per the account-pages design. Three states, driven by ?action= (see
 * functions/auth.php): sign in (default), forgot password, reset password.
 * The page content (intro text) is editable in wp-admin and shows on the
 * default state only.
 *
 * @package LAW
 */

get_header();

$law_auth_mode = law_auth_mode();

$law_auth_titles = array(
	'forgot' => 'Forgot your password?',
	'reset'  => 'Set a new password',
);
$law_auth_title = isset( $law_auth_titles[ $law_auth_mode ] ) ? $law_auth_titles[ $law_auth_mode ] : get_the_title();

$law_auth_intros = array(
	'forgot' => '<p>Enter the email address you registered with and we will send you a link to set a new password.</p>',
	'reset'  => '<p>Choose a new password for your account.</p>',
);
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero auth-hero" style="background-image: url('<?php echo law_asset( 'assets/images/patrons-and-committee-bg.jpg' ); ?>');">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<h1><?php echo esc_html( $law_auth_title ); ?></h1>
			</div>
			<div class="large-9 cell auth-intro wow fadeIn">
				<?php
				if ( 'login' === $law_auth_mode ) {
					the_content();
				} else {
					echo wp_kses_post( $law_auth_intros[ $law_auth_mode ] );
				}
				?>
			</div>
			<div class="large-4 medium-7 cell auth-form-cell">
				<?php law_auth_render_notices(); ?>
				<?php if ( is_user_logged_in() && 'login' === $law_auth_mode ) : ?>
					<div class="law-auth-signed-in">
						<p>You are signed in.</p>
						<p>
							<a href="<?php echo esc_url( home_url( '/account/' ) ); ?>">Go to your account</a><br>
							<a href="<?php echo esc_url( wp_logout_url( law_auth_login_url() ) ); ?>">Log out</a>
						</p>
					</div>
				<?php else : ?>
					<?php law_auth_render_form( $law_auth_mode ); ?>
				<?php endif; ?>
				<?php if ( 'login' === $law_auth_mode && ! is_user_logged_in() ) : ?>
					<p class="law-auth-alt">Don't have an account? <a href="<?php echo esc_url( home_url( '/register/' ) ); ?>">Create one</a></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
