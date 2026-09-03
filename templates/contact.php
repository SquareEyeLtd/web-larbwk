<?php
/**
 * Template Name: Contact & FAQs
 *
 * ACF fields: banner_text
 * Main body content from the block editor.
 */
get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title', null, array(
    'classes' => 'register-page',
    'text'    => (string) get_field( 'banner_text' ),
) ); ?>
<section class="page-section contact-page">
    <div class="grid-container">
        <div class="grid-x grid-padding-x">
            <div class="large-12 cell">
                <?php the_content(); ?>
            </div>
        </div>
    </div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
