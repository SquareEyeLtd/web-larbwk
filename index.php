<?php
/**
 * Default template fallback.
 */
get_header();
?>

<section class="hero register-page" style="background-image: url('<?php echo law_asset( 'assets/images/patrons-and-committee-bg.jpg' ); ?>');">
    <div class="overlay"></div>
    <div class="grid-container">
          <div class="grid-x grid-padding-x">
            <div class="large-12 cell">
                <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
                 <h1><?php the_title(); ?></h1>
                <?php endwhile; endif; ?>
            </div>
          </div>
        </div>
</section>
<section class="page-section">
    <div class="grid-container">
        <div class="grid-x grid-padding-x">
            <div class="large-9 cell">
                <div class="section-heading-text">
                    <?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
                        <?php the_content(); ?>
                    <?php endwhile; endif; ?>
                </div>
            </div>
        </div>
    </div>
</section>

<?php get_footer();
