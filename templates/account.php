<?php
/**
 * Template Name: Account
 *
 * The account landing page, styled like the other account pages (login,
 * register): the page content renders inside the full-height purple hero.
 * The [action-message] shortcode in the content shows a status panel for
 * states such as /account/?action=registered.
 *
 * @package LAW
 */

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero auth-hero hero-solid">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<h1><?php the_title(); ?></h1>
			</div>
			<div class="large-9 cell auth-intro wow fadeIn">
				<?php the_content(); ?>
			</div>
		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
