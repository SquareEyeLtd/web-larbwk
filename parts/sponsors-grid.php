<?php
/**
 * Sponsors grid partial — dynamic version.
 *
 * Pulls from the 'organisation' CPT using the 'organisation_category' taxonomy.
 * Expects child terms of "Sponsors": platinum, gold, silver, bronze.
 *
 * ACF fields used:
 *   - logo    (image — returns array)
 *   - website (URL)
 */

$tiers = array(
    'platinum' => array(
        'cell_class'  => 'large-3 medium-4 small-6 cell wow fadeIn',
        'wrap_inner'  => true,
        'title_extra' => ' first',
    ),
    'gold' => array(
        'cell_class'  => 'large-3 medium-4 small-6 cell wow fadeIn',
        'wrap_inner'  => true,
        'title_extra' => '',
    ),
    'silver' => array(
        'cell_class'  => 'large-3 medium-4 small-6 cell wow fadeIn',
        'wrap_inner'  => true,
        'title_extra' => '',
    ),
    'bronze' => array(
        'cell_class'  => 'large-3 medium-4 small-6 cell wow fadeIn',
        'wrap_inner'  => true,
        'title_extra' => '',
    ),
);

foreach ( $tiers as $slug => $tier ) :

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

    $term = get_term_by( 'slug', $slug, 'organisation_category' );
    $label = $term ? $term->name : ucfirst( $slug );
?>

      <div class="large-12 cell">
        <div class="sponsors-title<?php echo esc_attr( $tier['title_extra'] ); ?>">
          <h3><?php echo esc_html( $label ); ?></h3>
        </div>
<?php if ( $tier['wrap_inner'] ) : ?>
        <div class="grid-x grid-padding-x">
<?php endif; ?>

<?php if ( ! $tier['wrap_inner'] ) : ?>
      </div>
<?php endif;

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

<?php if ( ! $tier['wrap_inner'] ) : ?>
      <div class="<?php echo esc_attr( $tier['cell_class'] ); ?>">
        <div class="sponsor-logo" id="<?php echo esc_attr( $slug ); ?>">
<?php else : ?>
          <div class="<?php echo esc_attr( $tier['cell_class'] ); ?>">
            <div class="sponsor-logo" id="<?php echo esc_attr( $slug ); ?>">
<?php endif;

        if ( $website ) : ?>
              <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener">
                <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $logo_alt ); ?>" title="<?php echo esc_attr( $name ); ?>">
              </a>
<?php   else : ?>
              <img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( $logo_alt ); ?>" title="<?php echo esc_attr( $name ); ?>">
<?php   endif;

        if ( ! $tier['wrap_inner'] ) : ?>
        </div>
      </div>
<?php   else : ?>
            </div>
          </div>
<?php   endif;

    endforeach;

    if ( $tier['wrap_inner'] ) : ?>
        </div>
      </div>
<?php endif;

endforeach; ?>

      <div class="small-12 cell show-for-small-only margin-top">
        <a class="button primary" href="<?php echo law_asset( 'assets/images/LAW_SponsorshipPackages_2025.pdf' ); ?>" target="_blank" rel="noopener">BECOME A SPONSOR</a>
      </div>
