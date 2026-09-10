<?php
/**
 * One-time setup for the account pages rework (September 2026).
 *
 * Assigns the hero page templates to the account pages, removes the
 * [law_login] shortcode block from the Login page content (the login
 * template renders the forms itself now), and makes sure the flagship
 * conference post exists (functions/events/flagship.php), which is the
 * flagship's equivalent of a page: it is a law_event post, so its page
 * template is its permalink and there is nothing to assign.
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
		// The committee's Manage Speakers view (functions/events/speakers-dashboard.php).
		$setup['account/dashboard/speakers'] = 'templates/account-speakers-dashboard.php';
		$setup['account/dashboard/flagship'] = 'templates/account-dashboard-flagship.php';
		// The personal bookings page, split off My events (September 2026).
		// CPT-only: law_account_bookings() returns nothing on the legacy
		// source, so on a 'gf' environment this page would never have content.
		$setup['account/bookings']      = 'templates/account-bookings.php';
		// Phase D: the custom profile form replaces the form 3 embed.
		$setup['account/profile']       = 'templates/account-profile.php';
	}

	$report = array();

	foreach ( $setup as $path => $template ) {
		$page = get_page_by_path( $path );

		if ( ! $page instanceof WP_Post ) {
			// The module's own pages are created here rather than only reported.
			// Without this the only route to a page the module adds after
			// cutover is migration step 10, which refuses to run without a
			// fresh snapshot AND a passing preflight — a heavy gate for
			// "create one page", and the preflight reads legacy Gravity Forms
			// data that may no longer be there. Anything outside
			// law_migration_page_map() (the Login page, say) is still only
			// reported: a page absent from an environment on purpose must not
			// be conjured up by a setup trigger.
			$created = law_setup_create_account_page( $path, $template );
			$report[] = $created['message'];
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
	$report[] = 'CONTENT  /account/ audience blocks: ' . law_setup_account_page_audience();
	$report[] = 'ACCESS   /account/events/ attendee role: ' . law_setup_account_events_attendee_access();
	$report[] = 'ACCESS   /account/bookings/ role rows: ' . law_setup_my_bookings_access();
	$report[] = 'ACCESS   /account/dashboard/bookings/ committee restriction: ' . law_setup_bookings_dashboard_access();
	$report[] = 'ACCESS   /account/dashboard/speakers/ committee restriction: ' . law_setup_speakers_dashboard_access();
	$report[] = 'ACCESS   /account/dashboard/flagship/ committee restriction: ' . law_setup_flagship_dashboard_access();

	// The flagship conference. A law_event post rather than a page, so a git
	// deploy carries the code but not the record; this and the migration's step
	// 10 both create it, and the Flagship screen creates it on first open, so no
	// environment needs a manual step after a push. function_exists() because
	// functions.php requires this file before the events module.
	if ( function_exists( 'law_flagship_ensure_post' ) ) {
		$flagship = law_flagship_ensure_post();
		$report[] = ( $flagship['created'] ? 'CREATED  ' : 'OK       ' ) . '/events/flagship/ ' . $flagship['message'];
	}

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
 * The bookings build (EVENTS_BOOKINGS.md): /account/events/ used to host the
 * "Your bookings" section, and its Members restriction predates the attendee
 * audience. The bookings moved to /account/bookings/ on 10 September 2026, but
 * the attendee row stays: every confirmation email already sent links to
 * /account/events/, and account-events.php can only redirect a visitor the
 * Members plugin lets through the door. The restriction is database state, so
 * both this setup helper and migration step 10 apply it on every environment;
 * nothing is scripted only as a local click.
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
 * The /account/ page's role-gated copy: swap the hardcoded role name for the
 * capability-backed audience.
 *
 * The page body is editor content, so it is database state a git deploy cannot
 * carry, and it shipped with two blocks: [user-content role="attendee"] and
 * [user-content role="event_host"]. A user who registered as "LAW sponsor" and
 * nothing else matched neither, and law_user_content_shortcode() renders
 * nothing when no audience matches, so they got a page with a heading and no
 * body at all. Rewriting the block to role="host" hands it to every audience
 * that can run events (law_account_user_is_host_like(): hosts, sponsors, and
 * the committee, editors and administrators who submit events of their own),
 * and means the next role added to the module does not reopen the same hole.
 *
 * Idempotent, and deliberately narrow: only role="event_host" on its own is
 * rewritten. A block someone has already broadened by hand (role="event_host,
 * sponsor", say) is left exactly as it is.
 *
 * @return string ok | updated | missing.
 */
function law_setup_account_page_audience() {
	$page = get_page_by_path( 'account' );
	if ( ! $page instanceof WP_Post ) {
		return 'missing';
	}

	$content = preg_replace(
		'/(\[user-content\b[^\]]*\brole=)([\'"])event_host\2/',
		'$1$2host$2',
		$page->post_content
	);

	if ( null === $content || $content === $page->post_content ) {
		return 'ok';
	}

	wp_update_post( array( 'ID' => $page->ID, 'post_content' => $content ) );
	return 'updated';
}

/**
 * Create one of the module's account pages under its parent, with its template.
 *
 * The title comes from law_migration_page_map(), so the migration and this
 * trigger cannot disagree about what a page is called, and that map is also the
 * allow-list: a path it does not name is reported, never created.
 *
 * @param string $path     Page path.
 * @param string $template Page template to assign.
 * @return array{status:string,page_id:int,message:string}
 */
function law_setup_create_account_page( $path, $template ) {
	$map = function_exists( 'law_migration_page_map' ) ? law_migration_page_map() : array();
	if ( ! isset( $map[ $path ] ) ) {
		return array(
			'status'  => 'missing',
			'page_id' => 0,
			'message' => "MISSING  /{$path}/ — page not found, nothing changed",
		);
	}

	$parent_path = dirname( $path );
	$parent      = '.' === $parent_path ? null : get_page_by_path( $parent_path );
	if ( '.' !== $parent_path && ! $parent instanceof WP_Post ) {
		return array(
			'status'  => 'blocked',
			'page_id' => 0,
			'message' => "MISSING  /{$path}/ — parent /{$parent_path}/ does not exist; create that first",
		);
	}

	$page_id = wp_insert_post(
		array(
			'post_type'   => 'page',
			'post_status' => 'publish',
			'post_title'  => (string) $map[ $path ]['title'],
			'post_name'   => basename( $path ),
			'post_parent' => $parent ? $parent->ID : 0,
		),
		true
	);
	if ( is_wp_error( $page_id ) || ! $page_id ) {
		$why = is_wp_error( $page_id ) ? $page_id->get_error_message() : 'unknown error';
		return array(
			'status'  => 'error',
			'page_id' => 0,
			'message' => "ERROR    /{$path}/ — could not be created: {$why}",
		);
	}

	update_post_meta( $page_id, '_wp_page_template', $template );

	return array(
		'status'  => 'created',
		'page_id' => (int) $page_id,
		'message' => "CREATED  /{$path}/ (ID {$page_id}) with {$template}",
	);
}

/**
 * A child page must carry the same Members restriction as its parent. A page
 * created by the migration's pages step has no restriction at all, which the
 * Members plugin reads as public — so this copies the parent's role rows onto
 * the child whenever the child has none. Idempotent; shared by the setup
 * trigger and migration step 10.
 *
 * One helper, four children. The three committee dashboards take their
 * committee-only rows from /account/dashboard/; My bookings takes the
 * every-signed-in-role set from /account/. The named wrappers below are what
 * the setup report and the migration step call, so neither has to know a path.
 *
 * @param string $path        Child page path.
 * @param string $parent_path Parent page path to copy the rows from.
 * @return string ok | updated | unrestricted | missing.
 */
function law_setup_child_page_access( $path, $parent_path = 'account/dashboard' ) {
	$page   = get_page_by_path( $path );
	$parent = get_page_by_path( $parent_path );
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
 * The personal My bookings page's Members restriction, copied from /account/.
 *
 * Not the committee rows: this page is for EVERY signed-in role, because hosts,
 * sponsors and committee members book places at other firms' events like anyone
 * else (Denis, 10 September 2026). /account/ already carries exactly that set.
 */
function law_setup_my_bookings_access() {
	return law_setup_child_page_access( 'account/bookings', 'account' );
}

/** The Bookings dashboard's Members restriction. */
function law_setup_bookings_dashboard_access() {
	return law_setup_child_page_access( 'account/dashboard/bookings' );
}

/** The Manage Speakers dashboard's Members restriction. */
function law_setup_speakers_dashboard_access() {
	return law_setup_child_page_access( 'account/dashboard/speakers' );
}

/** The Manage flagship dashboard's Members restriction. */
function law_setup_flagship_dashboard_access() {
	return law_setup_child_page_access( 'account/dashboard/flagship' );
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
