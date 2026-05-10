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
           <div class="header-top">
              <a href="https://www.linkedin.com/company/londonarbitrationweek/" target="_blank" rel="noopener">
              <img src="<?php echo law_asset( 'assets/images/linkedin-square.svg' ); ?>" class="social-icon">
               </a>
            </div>
          <div class="main_list">
            <?php
            wp_nav_menu(
                array(
                    'theme_location' => 'main-menu',
                    'container'      => false,
                    'menu_class'     => 'navlinks dropdown menu',
                    'fallback_cb'    => false,
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
      <div id="mainListDiv" class="main_list">
        <?php
        wp_nav_menu(
            array(
                'theme_location' => 'main-menu',
                'container'      => false,
                'menu_class'     => 'sub-accordion navlinks',
                'fallback_cb'    => false,
                'items_wrap'     => '<ul id="%1$s" class="%2$s">%3$s</ul>',
            )
        );
        ?>
      </div>
    </div>
  </nav>
