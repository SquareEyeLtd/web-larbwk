<?php
	
add_theme_support('title-tag');
	
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