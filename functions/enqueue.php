<?php
	
// Enqueue original CSS and JS
add_action( 'wp_enqueue_scripts', function () {
    $uri = get_template_directory_uri();

    // CSS
    wp_enqueue_style( 'law-foundation', $uri . '/assets/css/foundation.css', array(), '1.0' );
    wp_enqueue_style( 'law-animate', $uri . '/assets/css/animate.min.css', array(), '1.0' );
    wp_enqueue_style( 'law-app', $uri . '/assets/css/app.css', array( 'law-foundation' ), '2.5' );
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
