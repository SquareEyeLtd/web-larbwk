<?php
/**
 * One-time setup for the account pages rework (September 2026).
 *
 * Assigns the hero page templates to the account pages and removes the
 * [law_login] shortcode block from the Login page content (the login
 * template renders the forms itself now).
 *
 * Trigger it as an administrator by visiting:
 *
 *   /wp-admin/?setup-account-pages
 *
 * Idempotent: safe to run more than once; it reports what it changed and
 * what was already correct. Pages are found by path, not by ID, so it works
 * even if IDs differ between environments.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply the template assignments and content change.
 *
 * @return string[] Report lines.
 */
function law_setup_account_pages() {
	$setup = array(
		// path => template
		'login'                 => 'templates/login.php',
		'register'              => 'templates/register.php',
		'account'               => 'templates/account.php',
		'account/profile'       => 'templates/account.php',
		// The host dashboard: theme-owned listing; the page keeps its
		// [gravityview] shortcode in the content because the template renders
		// it for GravityView's entry/edit context.
		'account/events'        => 'templates/account-events.php',
		'account/events/submit' => 'templates/account.php',
		'account/events/submit/done' => 'templates/account.php',
	);

	$report = array();

	foreach ( $setup as $path => $template ) {
		$page = get_page_by_path( $path );

		if ( ! $page instanceof WP_Post ) {
			$report[] = "MISSING  /{$path}/ — page not found, nothing changed";
			continue;
		}

		$current = get_post_meta( $page->ID, '_wp_page_template', true );
		if ( $current === $template ) {
			$report[] = "OK       /{$path}/ (ID {$page->ID}) already uses {$template}";
		} else {
			update_post_meta( $page->ID, '_wp_page_template', $template );
			$from     = $current ? $current : 'default';
			$report[] = "UPDATED  /{$path}/ (ID {$page->ID}) template: {$from} -> {$template}";
		}
	}

	// The Login page: drop the [law_login] shortcode block. The template
	// renders the sign-in / forgot / reset forms itself; the shortcode would
	// only render an empty string there, but removing it keeps the editor
	// content honest.
	$login_page = get_page_by_path( 'login' );
	if ( $login_page instanceof WP_Post ) {
		$block   = "<!-- wp:shortcode -->\n[law_login]\n<!-- /wp:shortcode -->";
		$content = $login_page->post_content;

		if ( false !== strpos( $content, '[law_login]' ) ) {
			$content = str_replace( $block, '', $content );
			// Fallback for a shortcode outside the exact block markup.
			$content = str_replace( '[law_login]', '', $content );
			wp_update_post(
				array(
					'ID'           => $login_page->ID,
					'post_content' => trim( $content ),
				)
			);
			$report[] = 'UPDATED  /login/ content: [law_login] shortcode block removed';
		} else {
			$report[] = 'OK       /login/ content: no [law_login] shortcode present';
		}
	}

	return $report;
}

/**
 * URL trigger: /wp-admin/?setup-account-pages (administrators only).
 */
add_action( 'admin_init', function () {
	if ( ! isset( $_GET['setup-account-pages'] ) ) {
		return;
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'You need to be an administrator to run this.', 'Setup account pages', 403 );
	}

	$report = law_setup_account_pages();

	$back = esc_url( admin_url() );
	wp_die(
		'<h1>Setup account pages</h1><pre>' . esc_html( implode( "\n", $report ) ) . "\nDone.</pre>"
		. '<p><a href="' . $back . '">Back to the dashboard</a></p>',
		'Setup account pages',
		array( 'response' => 200 )
	);
} );
