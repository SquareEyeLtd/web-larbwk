<?php
/**
 * London Arbitration Week theme functions
 *
 * @package LAW
 */

add_action( 'after_setup_theme', function () {
    add_theme_support( 'title-tag' );
    add_theme_support( 'post-thumbnails' );
    add_theme_support( 'menus' );
    add_theme_support( 'html5', array( 'search-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );

    register_nav_menus(
        array(
            'main-menu'   => __( 'Main menu', 'law' ),
            'footer-menu' => __( 'Footer menu', 'law' ),
        )
    );
} );

// Enqueue original CSS and JS
add_action( 'wp_enqueue_scripts', function () {
    $uri = get_template_directory_uri();

    // CSS
    wp_enqueue_style( 'law-foundation', $uri . '/assets/css/foundation.css', array(), '1.0' );
    wp_enqueue_style( 'law-animate', $uri . '/assets/css/animate.min.css', array(), '1.0' );
    wp_enqueue_style( 'law-app', $uri . '/assets/css/app.css', array( 'law-foundation' ), '2.3' );
    wp_enqueue_style( 'law-accordions', $uri . '/assets/css/accordions.css', array( 'law-app' ), '1.0' );
    wp_enqueue_style( 'law-wp', get_stylesheet_uri(), array( 'law-app' ), '1.0' );

    // Typekit (Poppins)
    wp_enqueue_style( 'law-typekit-1', 'https://use.typekit.net/vum0moo.css', array(), null );
    wp_enqueue_style( 'law-typekit-2', 'https://use.typekit.net/dje5apx.css', array(), null );

    // JS
    wp_enqueue_script( 'law-what-input', $uri . '/assets/js/vendor/what-input.js', array( 'jquery' ), '1.0', true );
    wp_enqueue_script( 'law-foundation', $uri . '/assets/js/vendor/foundation.js', array( 'jquery' ), '1.0', true );
    wp_enqueue_script( 'law-wow', $uri . '/assets/js/vendor/wow.min.js', array(), '1.0', true );
    wp_enqueue_script( 'law-matchheight', 'https://cdnjs.cloudflare.com/ajax/libs/jquery.matchHeight/0.7.2/jquery.matchHeight-min.js', array( 'jquery' ), '0.7.2', true );
    wp_enqueue_script( 'law-app', $uri . '/assets/js/app.js', array( 'jquery', 'law-foundation', 'law-wow', 'law-matchheight' ), '2.0', true );
} );

// Helper: theme asset URL
function law_asset( $path ) {
    return get_template_directory_uri() . '/' . ltrim( $path, '/' );
}
