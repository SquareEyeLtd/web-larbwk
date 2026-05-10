<?php
/**
 * Template Name: Patrons & committee
 *
 * CPT: person
 * Taxonomy: people_category (terms: patrons, committee)
 * ACF fields: organisation, url, law_role, quote
 * Featured image used for patron photos.
 * Patrons sorted by menu_order; committee sorted by 'surname' meta field.
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
<?php endwhile; endif; wp_reset_postdata(); ?>


<?php
$patrons_query = new WP_Query( array(
    'post_type'      => 'person',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'menu_order',
    'order'          => 'ASC',
    'tax_query'      => array( array(
        'taxonomy' => 'people_category',
        'field'    => 'slug',
        'terms'    => 'patrons',
    ) ),
) );
$patrons = $patrons_query->posts;

if ( $patrons ) : ?>
<section class="page-section">
    <div class="grid-container">
        <div class="grid-x grid-padding-x">
            <div class="large-9 large-offset-3 cell">
                <div class="section-heading wow fadeIn ">
                    <h2>Our Patrons</h2>
                </div>
            </div>
        </div>

<?php foreach ( $patrons as $patron ) :
    $name    = get_the_title( $patron->ID );
    $quote   = get_field( 'quote', $patron->ID );
    $url     = get_field( 'url', $patron->ID );
    $thumb   = get_the_post_thumbnail_url( $patron->ID, 'medium_large' );
?>
        <div class="grid-x grid-padding-x grid-padding-y full-listing">
            <div class="large-3 medium-5 cell wow fadeIn ">
                <div class="people-photo matchbox">
<?php if ( $thumb ) : ?>
                    <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $name ); ?>" title="<?php echo esc_attr( $name ); ?>">
<?php endif; ?>
                </div>
            </div>

            <div class="large-9 medium-7 cell wow fadeIn">
                <div class="people-details matchbox">
                    <h4><?php echo esc_html( $name ); ?></h4>
<?php if ( $quote ) : ?>
                    <blockquote><?php echo esc_html( $quote ); ?></blockquote>
<?php endif; ?>
<?php if ( $url ) : ?>
                    <a class="button primary no-set" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">VIEW PROFILE</a>
<?php endif; ?>
                </div>
            </div>
        </div>
<?php endforeach; ?>

    </div>
</section>
<?php endif; wp_reset_postdata(); ?>


<?php
$committee_query = new WP_Query( array(
    'post_type'      => 'person',
    'post_status'    => 'publish',
    'posts_per_page' => -1,
    'orderby'        => 'title',
    'order'          => 'ASC',
    'tax_query'      => array( array(
        'taxonomy' => 'people_category',
        'field'    => 'slug',
        'terms'    => 'committee',
    ) ),
) );
$committee = $committee_query->posts;

usort( $committee, function ( $a, $b ) {
    $surname_a = get_field( 'surname', $a->ID );
    $surname_b = get_field( 'surname', $b->ID );
    if ( ! $surname_a ) {
        $parts     = explode( ' ', trim( get_the_title( $a->ID ) ) );
        $surname_a = end( $parts );
    }
    if ( ! $surname_b ) {
        $parts     = explode( ' ', trim( get_the_title( $b->ID ) ) );
        $surname_b = end( $parts );
    }
    return strcasecmp( $surname_a, $surname_b );
} );

if ( $committee ) : ?>
<section class="page-section light-purple-background">
     <div class="grid-container">
        <div class="grid-x grid-padding-x">
            <div class="large-9 large-offset-3 cell">
                <div class="section-heading wow fadeIn">
                    <h2>London Arbitration Week Committee</h2>
                </div>

                <div class="grid-x grid-padding-x grid-padding-y">

<?php foreach ( $committee as $member ) :
    $name         = get_the_title( $member->ID );
    $organisation = get_field( 'organisation', $member->ID );
    $law_role     = get_field( 'law_role', $member->ID );
    $url          = get_field( 'url', $member->ID );
?>
                    <div class="medium-6 cell wow fadeIn">
                        <div class="people-details small">
                            <h4><?php echo esc_html( $name ); ?></h4>
<?php if ( $organisation || $law_role ) : ?>
                            <h4 class="company"><?php echo esc_html( $organisation ); ?>
<?php   if ( $law_role && $law_role !== 'Committee Member' ) : ?>
                                <br/><?php echo esc_html( $law_role ); ?>
<?php   endif; ?>
                            </h4>
<?php endif; ?>
<?php if ( $url ) : ?>
                            <a class="normal-link" href="<?php echo esc_url( $url ); ?>" target="_blank" rel="noopener">VIEW PROFILE</a>
<?php endif; ?>
                        </div>
                    </div>
<?php endforeach; ?>

                </div>

            </div>
        </div>
    </div>
</section>
<?php endif; wp_reset_postdata(); ?>

<?php get_footer(); ?>
