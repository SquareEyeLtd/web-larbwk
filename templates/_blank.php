<?php
/**
 * Template Name: Blank
 *
 */
get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title', null, array( 'image' => false ) ); ?>

<section class="page-section text-page">
    <div class="grid-container">
        <div class="grid-x grid-padding-x align-center">
            <div class="large-9 medium-10 cell">
                <div class="content wow fadeIn">
                    <?php the_content(); ?>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
