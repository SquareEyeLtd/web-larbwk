<?php
/**
 * Template Name: Sponsors & supporting organisations
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
            <div class="large-9 cell wow fadeIn">
              <?php the_content(); ?>
            </div>
          </div>
        </div>
</section>

<?php law_render_banner_account_status(); ?>

<section class="page-section">
  <div class="grid-container">
    <div class="grid-x grid-padding-x grid-padding-y">
      <div class="large-9 cell">
        <div class="section-heading wow fadeIn">
          <h2>Our 2026 Sponsors</h2>
        </div>
      </div>

      <?php get_template_part( 'parts/sponsors-grid' ); ?>

<?php
$extra_sections = array( 'media-partners', 'supporting-organisations' );

foreach ( $extra_sections as $slug ) :
    $term = get_term_by( 'slug', $slug, 'organisation_category' );

    if ( ! $term ) {
        continue;
    }

    $orgs = get_posts( array(
        'post_type'      => 'organisation',
        'posts_per_page' => -1,
        'orderby'        => 'title',
        'order'          => 'ASC',
        'tax_query'      => array( array(
            'taxonomy' => 'organisation_category',
            'field'    => 'slug',
            'terms'    => $slug,
        ) ),
    ) );

    if ( empty( $orgs ) ) {
        continue;
    }
?>
      <div class="large-12 cell">
        <div class="section-heading sponsors-title">
          <h2><?php echo esc_html( $term->name ); ?></h2>
        </div>
        <div class="grid-x grid-padding-x">
<?php
    foreach ( $orgs as $org ) :
        $logo    = get_field( 'logo', $org->ID );
        $website = get_field( 'website', $org->ID );
        $name    = get_the_title( $org->ID );

        if ( ! $logo ) {
            continue;
        }

        $logo_url = is_array( $logo ) ? $logo['url'] : $logo;
        $logo_alt = is_array( $logo ) && ! empty( $logo['alt'] ) ? $logo['alt'] : $name;
?>
          <div class="large-3 medium-4 small-6 cell wow fadeIn">
            <div class="sponsor-logo">
<?php   if ( $website ) : ?>
              <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener">
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $logo_alt ); ?>" title="<?php echo esc_attr( $name ); ?>">
              </a>
<?php   else : ?>
              <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $logo_alt ); ?>" title="<?php echo esc_attr( $name ); ?>">
<?php   endif; ?>
            </div>
          </div>
<?php endforeach; ?>
        </div>
      </div>
<?php endforeach; ?>

    </div>
  </div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
