<?php
/**
 * Template Name: Register
 *
 * The branded registration page. The page content, including the Gravity
 * Forms block for form 1 (User registration), renders inside the full-height
 * hero, per the account-pages design.
 *
 * @package LAW
 */

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero auth-hero" style="background-image: url('<?php echo law_asset( 'assets/images/patrons-and-committee-bg.jpg' ); ?>');">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<h1><?php the_title(); ?></h1>
			</div>
			<div class="large-9 cell auth-intro auth-register-content wow fadeIn">
				<?php the_content(); ?>
				<?php if ( ! is_user_logged_in() ) : ?>
					<p class="law-auth-alt">Already have an account? <a href="<?php echo esc_url( home_url( '/login/' ) ); ?>">Sign in</a></p>
				<?php endif; ?>
			</div>
		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
