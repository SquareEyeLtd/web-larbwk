<!doctype html>
<html class="no-js" <?php language_attributes(); ?> dir="ltr">

<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta http-equiv="x-ua-compatible" content="ie=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="apple-touch-icon" sizes="180x180" href="<?php echo law_asset( 'favicon_io/apple-touch-icon.png' ); ?>">
    <link rel="icon" type="image/png" sizes="32x32" href="<?php echo law_asset( 'favicon_io/favicon-32x32.png' ); ?>">
    <link rel="icon" type="image/png" sizes="16x16" href="<?php echo law_asset( 'favicon_io/favicon-16x16.png' ); ?>">
    <?php wp_head(); ?>
    <script src="https://kit.fontawesome.com/a87ec65596.js" crossorigin="anonymous"></script>
</head>

<body <?php body_class(); ?> <?php if ( ! is_front_page() ) echo 'id="pages"'; ?>>
<?php wp_body_open(); ?>

  <nav class="nav" id="new-nav-v2">
    <div class="grid-container">
      <div class="grid-x">
        <div class="large-3 medium-4 small-6 cell">
          <div class="logo">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>">
              <img src="<?php echo law_asset( 'assets/images/LAW-bottom-logo.svg' ); ?>" class="logo" title="<?php bloginfo( 'name' ); ?>" alt="<?php bloginfo( 'name' ); ?>">
            </a>
          </div>
        </div>
        <div class="large-9 medium-8 small-6 show-for-large cell">
           <?php if ( is_user_logged_in() || has_nav_menu( 'top-menu' ) ) : ?>
           <div class="header-top">
            <div class="header-top__group">
            <?php law_render_header_member_status(); ?>
            <?php
            if ( has_nav_menu( 'top-menu' ) ) {
            wp_nav_menu(
                array(
                    'theme_location' => 'top-menu',
                    'container'      => false,
                    'menu_class'     => 'top-nav dropdown menu',
                    'menu_id'        => 'top-menu-desktop',
                    'fallback_cb'    => false,
                    'depth'          => 0,
                    'law_menu_mode'  => 'dropdown',
                    'items_wrap'     => '<ul id="%1$s" class="%2$s" data-dropdown-menu>%3$s</ul>',
                )
            );
            }
            ?>
            </div>
            </div>
           <?php endif; ?>
          <div class="main_list">
            <?php
            wp_nav_menu(
                array(
                    'theme_location' => 'main-menu',
                    'container'      => false,
                    'menu_class'     => 'navlinks dropdown menu',
                    'fallback_cb'    => false,
                    'depth'          => 0,
                    'law_menu_mode'  => 'dropdown',
                    'items_wrap'     => '<ul id="%1$s" class="%2$s" data-dropdown-menu>%3$s</ul>',
                )
            );
            ?>
          </div>
        </div>
        <span class="navTrigger">
          <i></i>
          <i></i>
          <i></i>
        </span>
      </div>
    </div>
    <div class="hide-for-large">
      <?php if ( is_user_logged_in() || has_nav_menu( 'top-menu' ) ) : ?>
      <div class="header-top mobile-top-nav">
        <div class="header-top__group">
        <?php law_render_header_member_status(); ?>
        <?php
        if ( has_nav_menu( 'top-menu' ) ) {
        wp_nav_menu(
            array(
                'theme_location' => 'top-menu',
                'container'      => false,
                'menu_class'     => 'top-nav vertical menu accordion-menu',
                'menu_id'        => 'top-menu-mobile',
                'fallback_cb'    => false,
                'depth'          => 0,
                'law_menu_mode'  => 'accordion',
                'items_wrap'     => '<ul id="%1$s" class="%2$s" data-accordion-menu data-multi-open="false">%3$s</ul>',
            )
        );
        }
        ?>
        </div>
      </div>
      <?php endif; ?>
      <div id="mainListDiv" class="main_list">
        <?php
        wp_nav_menu(
            array(
                'theme_location' => 'main-menu',
                'container'      => false,
                'menu_class'     => 'vertical menu accordion-menu navlinks',
                'fallback_cb'    => false,
                'depth'          => 0,
                'law_menu_mode'  => 'accordion',
                'items_wrap'     => '<ul id="%1$s" class="%2$s" data-accordion-menu data-multi-open="false">%3$s</ul>',
            )
        );
        ?>
      </div>
    </div>
  </nav>
