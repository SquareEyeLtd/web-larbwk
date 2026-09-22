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
	if ( array_intersect( array( 'administrator', 'editor' ), (array) $user->roles ) ) {
		return true;
	}
	// The events committee works the module's wp-admin screens
	// (EVENTS_4.1_REBUILD.md §3.4); their capability set is scoped to it.
	return user_can( $user, 'edit_others_law_events' );
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
		// admin-post.php is the front end's form handler, not a wp-admin
		// screen: hosts submit events and reply to comment threads through
		// it. Each handler enforces its own nonce and capability checks.
		if ( 'admin-post.php' === ( $GLOBALS['pagenow'] ?? '' ) ) {
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
 * The page templates that print a WordPress nonce for a LOGGED-OUT visitor.
 *
 * Every other nonce on the site is behind the login cookie, which the host's
 * page cache already treats as a bypass. These are the ones a shared cache can
 * reach, and a shared cache must never hold them: see
 * law_no_store_nonce_pages() below for what went wrong when one did.
 *
 * Adding an anonymous form to the theme means adding its template here. The
 * test in tests/RegistrationTest.php reads this list and fails if a template
 * named in it has stopped calling wp_nonce_field(), so a form that moves does
 * not quietly leave a stale entry behind.
 *
 * @return string[]
 */
function law_nonce_bearing_public_templates() {
	return array(
		// The custom registration form (templates/register.php, phase D).
		'templates/register.php',
		// The login template also carries the forgot-password and
		// password-reset forms (functions/auth.php), which have nonces of
		// their own. Both only appear on an ?action= URL, which the host
		// bypasses the cache for anyway, so this entry is belt and braces
		// rather than a fix for anything observed.
		'templates/login.php',
	);
}

/**
 * Keep those pages out of every shared cache.
 *
 * WordPress builds a nonce for a logged-out visitor from the nonce tick alone,
 * so it is the same string for everybody and it dies between 12 and 24 hours
 * after it was made. Live is on Kinsta, whose edge cache was holding
 * /register/ with `Cache-Control: public, max-age=0, s-maxage=86400` — a full
 * 24 hours. Anyone served a copy from the back half of that window posted a
 * nonce that had already expired, and check_admin_referer() answered with
 * core's bare "The link you followed has expired." page on a
 * wp-admin/admin-post.php URL. A user reported exactly that on 22 September
 * 2026: three attempts, three identical dead ends, because the "Please try
 * again" link went back to the same cached page holding the same dead nonce.
 *
 * nocache_headers() sends `no-cache, must-revalidate, max-age=0, no-store,
 * private`, and `private` is the part that matters: it tells the edge the
 * response belongs to one visitor and must not be reused for the next one.
 * The browser stops re-showing the form from its own back/forward cache too,
 * which is the same bug one step smaller.
 *
 * This costs the site nothing worth counting: these are two low-traffic pages
 * that were only ever cacheable because nothing had told the edge otherwise.
 * Belt and braces, ask Kinsta for a cache bypass rule on /register/ as well,
 * so the fix does not depend on the edge honouring an origin header.
 */
add_action(
	'template_redirect',
	function () {
		if ( is_user_logged_in() ) {
			return; // Already a bypass everywhere; nothing to protect.
		}
		foreach ( law_nonce_bearing_public_templates() as $template ) {
			if ( is_page_template( $template ) ) {
				nocache_headers();
				return;
			}
		}
	}
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
