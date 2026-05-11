<?php
/**
 * Homepage template.
 * WordPress automatically uses front-page.php for the static front page.
 */
get_header();
?>

<?php
$hero_bg  = get_field( 'hero_bg_img' );
$hero_url = is_array( $hero_bg ) ? $hero_bg['url'] : ( $hero_bg ?: law_asset( 'assets/images/hero-bg.jpg' ) );
?>
<section class="hero" style="background-image: url('<?php echo esc_url( $hero_url ); ?>');">
    <div class="overlay"></div>
    <div class="grid-container">
      <div class="grid-x grid-padding-x">
        <div class="large-12 cell">
          <div class="grid-x grid-padding-x hero-text">
            <div class="large-10 medium-11 cell wow fadeIn hero-text__stack">
              <h1><?php echo esc_html( get_field( 'hero_strapline' ) ); ?></h1>
              <p class="hero-dates"><?php echo esc_html( get_field( 'hero_dates' ) ); ?></p>
            </div>
          </div>
        </div>
      </div>
    </div>
</section>

<?php law_render_banner_account_status(); ?>

<section class="featured-section" aria-labelledby="home-sponsor-spotlight-heading">
  <h2 id="home-sponsor-spotlight-heading" class="screen-reader-text">Sponsorship opportunities</h2>
  <div class="fadeIn-tint-right"></div>
  <div class="grid-container" style="background-image: url('<?php echo law_asset( 'assets/images/scroll-bg.jpg' ); ?>');">
    <div class="grid-x">
    <div class="medium-6 cell">
      <div class="featured-block sponsorship">
        <div class="tint"></div>
        <div class="block-details wow fadeIn flex new">
          <img class="icons" src="<?php echo law_asset( 'assets/images/sponsorship-icon.svg' ); ?>" title="sponsorship">
           <h3 class="sponsor-feature__heading"><?php echo esc_html( get_field( 'sponsor_heading' ) ); ?></h3>
        </div>
      </div>
     </div>
     <div class="medium-6 cell">
       <div class="featured-block sponsorship text">
       <div class="tint"></div>
        <div class="block-details wow fadeIn">
           <p class="block-details__lede"><?php echo esc_html( get_field( 'sponsor_text' ) ); ?></p>
          <a class="button second" href="<?php echo esc_url( get_field( 'sponsor_button_link' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( get_field( 'sponsor_button_label' ) ); ?></a>
        </div>
        </div>
     </div>
    </div>
  </div>
</section>

<?php
$corner_bg  = get_field( 'cornerstone_bg_img' );
$corner_url = is_array( $corner_bg ) ? $corner_bg['url'] : ( $corner_bg ?: law_asset( 'assets/images/banner-1.jpg' ) );
?>
<section class="page-section full-banner" style="background-image: url('<?php echo esc_url( $corner_url ); ?>');">
  <div class="grid-container">
    <div class="grid-x grid-padding-x">
      <div class="large-6 cell">
        <div class="section-heading wow fadeIn">
          <h2><?php echo esc_html( get_field( 'cornerstone_text' ) ); ?></h2>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="page-section">
  <div class="fadeIn-tint"></div>
  <div class="grid-container">
    <div class="grid-x grid-padding-x">
<?php
$thought_img = get_field( 'thought_image' );
$thought_url = is_array( $thought_img ) ? $thought_img['url'] : $thought_img;
$thought_alt = is_array( $thought_img ) && ! empty( $thought_img['alt'] ) ? $thought_img['alt'] : '';
?>
      <div class="large-4 cell">
        <div class="img-block wow fadeIn">
          <img src="<?php echo esc_url( $thought_url ); ?>" alt="<?php echo esc_attr( $thought_alt ); ?>">
        </div>
      </div>
      <div class="large-8 cell">
        <div class="text-block wow fadeIn">
          <h2><?php echo esc_html( get_field( 'thought_text' ) ); ?></h2>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="page-section">
  <div class="grid-container">
    <div class="grid-x grid-padding-x grid-padding-y">
      <div class="large-9 cell">
        <div class="section-heading wow fadeIn">
          <h2>Our 2026 Sponsors</h2>
        </div>
      </div>

      <?php get_template_part( 'parts/sponsors-grid' ); ?>

    </div>
  </div>
</section>

<?php get_footer();
