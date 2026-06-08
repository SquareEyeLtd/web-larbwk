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
            'top-menu'    => __( 'Top menu', 'law' ),
            'footer-menu' => __( 'Footer menu', 'law' ),
        )
    );
} );

/**
 * Administrators and editors only: others should not use the admin UI.
 */
function law_user_may_use_wp_admin() {
	if ( ! is_user_logged_in() ) {
		return false;
	}
	$user = wp_get_current_user();
	return (bool) array_intersect( array( 'administrator', 'editor' ), (array) $user->roles );
}

/**
 * Front-end admin bar: hide for everyone except admins and editors.
 */
add_filter(
	'show_admin_bar',
	function ( $show ) {
		return law_user_may_use_wp_admin();
	},
	100
);

/**
 * Block wp-admin (including Dashboard) unless admin or editor. AJAX unchanged.
 */
add_action(
	'admin_init',
	function () {
		if ( ! is_user_logged_in() ) {
			return;
		}
		if ( wp_doing_ajax() ) {
			return;
		}
		if ( law_user_may_use_wp_admin() ) {
			return;
		}

		wp_safe_redirect( home_url( '/' ) );
		exit;
	},
	1
);
