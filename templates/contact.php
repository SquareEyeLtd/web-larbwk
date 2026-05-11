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
<section class="hero register-page" style="background-image: url('<?php echo law_asset( 'assets/images/patrons-and-committee-bg.jpg' ); ?>');">
    <div class="overlay"></div>
    <div class="grid-container">
          <div class="grid-x grid-padding-x">
            <div class="large-12 cell">
                 <h1><?php the_title(); ?></h1>
            </div>
<?php $banner_text = get_field( 'banner_text' ); if ( $banner_text ) : ?>
            <div class="large-9 cell wow fadeIn">
              <?php echo wp_kses_post( $banner_text ); ?>
            </div>
<?php endif; ?>
          </div>
        </div>
</section>

<?php law_render_banner_account_status(); ?>

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
