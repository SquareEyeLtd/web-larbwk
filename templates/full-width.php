<?php
/**
 * Template Name: Full width
 *
 */
get_header();
?>


<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero" style="">
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
            <div class="cell">
                <div class="content wow fadeIn">
                    <?php the_content(); ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
