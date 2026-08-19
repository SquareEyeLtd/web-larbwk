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

/**
 * GravityView Advanced Filter 4.7 calls crypto.randomUUID(), which browsers
 * only expose in a secure context (HTTPS or localhost). Live is HTTPS;
 * Local is http://larbwk.local, so the query builder never mounts.
 */
add_action(
	'admin_head',
	function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'gravityview' !== $screen->post_type ) {
			return;
		}
		?>
<script>
(function () {
	var c = window.crypto;
	if ( ! c || typeof c.randomUUID === 'function' ) {
		return;
	}
	c.randomUUID = function () {
		var bytes = new Uint8Array( 16 );
		c.getRandomValues( bytes );
		bytes[6] = ( bytes[6] & 0x0f ) | 0x40;
		bytes[8] = ( bytes[8] & 0x3f ) | 0x80;
		var hex = Array.prototype.map.call( bytes, function ( b ) {
			return ( '0' + b.toString( 16 ) ).slice( -2 );
		} ).join( '' );
		return hex.slice( 0, 8 ) + '-' + hex.slice( 8, 12 ) + '-' + hex.slice( 12, 16 ) + '-' + hex.slice( 16, 20 ) + '-' + hex.slice( 20 );
	};
})();
</script>
		<?php
	},
	1
);
