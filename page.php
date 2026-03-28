<?php
/**
 * Default page template.
 *
 * @package LAW
 */

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero" style="background-image: url('<?php echo law_asset( 'assets/images/patrons-and-committee-bg.jpg' ); ?>');">
    <div class="overlay"></div>
    <div class="grid-container">
        <div class="grid-x grid-padding-x">
            <div class="large-12 cell">
                <h1><?php the_title(); ?></h1>
            </div>
        </div>
    </div>
</section>

<section class="page-section text-page">
    <div class="grid-container">
        <div class="grid-x grid-padding-x align-center">
            <div class="large-9 medium-10 cell">
                <div class="section-title wow fadeIn">
                    <?php the_content(); ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
