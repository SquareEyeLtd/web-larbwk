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

	// The events module cutover (EVENTS_4.1_REBUILD.md): once the source is
	// the CPT module, the submit page carries the custom form and the
	// committee dashboard replaces the GravityView embed.
	if ( function_exists( 'law_events_source' ) && 'cpt' === law_events_source() ) {
		$setup['account/events/submit'] = 'templates/account-event-form.php';
		$setup['account/dashboard']     = 'templates/account-dashboard.php';
		// The committee's cross-event bookings view (EVENTS_BOOKINGS.md §7.6).
		$setup['account/dashboard/bookings'] = 'templates/account-bookings-dashboard.php';
		// Phase D: the custom profile form replaces the form 3 embed.
		$setup['account/profile']       = 'templates/account-profile.php';
	}

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

	// Phase D (source = cpt): the Register and Profile pages drop their
	// Gravity Forms blocks (forms 1 and 3) — the custom templates render the
	// forms themselves, and leaving the blocks would render BOTH forms.
	if ( function_exists( 'law_events_source' ) && 'cpt' === law_events_source() ) {
		foreach ( array( 'register', 'account/profile', 'account/events/submit' ) as $gf_path ) {
			$gf_page = get_page_by_path( $gf_path );
			if ( ! $gf_page instanceof WP_Post ) {
				continue;
			}
			// Both block shapes: the void block (<!-- wp:gravityforms/form {...} /-->)
			// and a wrapped one, plus the classic shortcode.
			$content = preg_replace( '/<!--\s*wp:gravityforms\/form[^>]*?\/-->/s', '', $gf_page->post_content );
			$content = preg_replace( '/<!--\s*wp:gravityforms\/form.*?\/wp:gravityforms\/form\s*-->/s', '', (string) $content );
			$content = preg_replace( '/\[gravityform[^\]]*\]/', '', (string) $content );
			if ( trim( (string) $content ) !== trim( $gf_page->post_content ) ) {
				wp_update_post( array( 'ID' => $gf_page->ID, 'post_content' => trim( (string) $content ) ) );
				$report[] = "UPDATED  /{$gf_path}/ content: Gravity Forms block removed (custom form renders instead)";
			} else {
				$report[] = "OK       /{$gf_path}/ content carries no Gravity Forms block";
			}
		}
	}

	// The Login page: drop the [law_login] shortcode block. The template
	// renders the sign-in / forgot / reset forms itself; the shortcode would
	// only render an empty string there, but removing it keeps the editor
	// content honest.
	$report[] = 'ACCESS   /account/events/ attendee role: ' . law_setup_account_events_attendee_access();
	$report[] = 'ACCESS   /account/dashboard/bookings/ committee restriction: ' . law_setup_bookings_dashboard_access();

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
 * The bookings build (EVENTS_BOOKINGS.md): /account/events/ hosts the
 * "Your bookings" section, and its Members restriction predates the attendee
 * audience — without this, pure attendees are blocked from their own
 * bookings. The restriction is database state, so both this setup helper and
 * migration step 10 apply it on every environment; nothing is scripted only
 * as a local click.
 *
 * @return string ok | updated | unrestricted | missing.
 */
function law_setup_account_events_attendee_access() {
	$page = get_page_by_path( 'account/events' );
	if ( ! $page instanceof WP_Post ) {
		return 'missing';
	}
	$roles = get_post_meta( $page->ID, '_members_access_role' );
	if ( ! $roles ) {
		return 'unrestricted'; // No Members restriction on this environment.
	}
	if ( in_array( 'attendee', $roles, true ) ) {
		return 'ok';
	}
	add_post_meta( $page->ID, '_members_access_role', 'attendee' );
	return 'updated';
}

/**
 * The Bookings dashboard (EVENTS_BOOKINGS.md §7.6) is a child page of the
 * events dashboard and must carry the same Members restriction (committee,
 * editor, administrator). A page created by the migration's pages step has
 * no restriction at all, which the Members plugin reads as public — so this
 * copies the parent's role rows onto the child whenever the child has none.
 * Idempotent; shared by the setup trigger and migration step 10.
 *
 * @return string ok | updated | unrestricted | missing.
 */
function law_setup_bookings_dashboard_access() {
	$page   = get_page_by_path( 'account/dashboard/bookings' );
	$parent = get_page_by_path( 'account/dashboard' );
	if ( ! $page instanceof WP_Post || ! $parent instanceof WP_Post ) {
		return 'missing';
	}
	if ( get_post_meta( $page->ID, '_members_access_role' ) ) {
		return 'ok';
	}
	$roles = get_post_meta( $parent->ID, '_members_access_role' );
	if ( ! $roles ) {
		return 'unrestricted'; // The parent has no Members restriction on this environment either.
	}
	foreach ( $roles as $role ) {
		add_post_meta( $page->ID, '_members_access_role', $role );
	}
	return 'updated';
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
