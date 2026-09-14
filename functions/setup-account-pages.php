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
		// The account hub. Note the neighbours below that still use
		// templates/account.php: it renders editor content in the hero, which
		// is exactly what the submission confirmation needs and exactly what
		// the hub does not.
		'account'               => 'templates/account-hub.php',
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
		// The flagship's applications and payments, its own page rather than
		// a section of Manage bookings
		// (functions/events/flagship-bookings-dashboard.php).
		$setup['account/dashboard/flagship-bookings'] = 'templates/account-dashboard-flagship-bookings.php';
		// The committee's discount-code catalogue
		// (functions/events/discounts-dashboard.php).
		$setup['account/dashboard/discounts'] = 'templates/account-dashboard-discounts.php';
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
	$report[] = 'CONTENT  /account/ body: ' . law_setup_account_page_content();
	$report[] = 'ACCESS   /account/bookings/ role rows: ' . law_setup_my_bookings_access();
	// After my_bookings on purpose: that helper copies /account/'s rows onto a
	// page that has none, so the subscriber pass must see the copied rows.
	$report[] = 'ACCESS   account page roles: ' . law_setup_account_page_roles();
	$report[] = 'EMAILS   registration emails, Roles line: ' . law_setup_strip_user_roles_from_emails();
	$report[] = 'ACCESS   /account/dashboard/bookings/ committee restriction: ' . law_setup_bookings_dashboard_access();
	$report[] = 'ACCESS   /account/dashboard/speakers/ committee restriction: ' . law_setup_speakers_dashboard_access();
	$report[] = 'ACCESS   /account/dashboard/flagship/ committee restriction: ' . law_setup_flagship_dashboard_access();
	$report[] = 'ACCESS   /account/dashboard/flagship-bookings/ committee restriction: ' . law_setup_flagship_bookings_access();
	$report[] = 'ACCESS   /account/dashboard/discounts/ committee restriction: ' . law_setup_discounts_dashboard_access();

	// The flagship conference. A law_event post rather than a page, so a git
	// deploy carries the code but not the record; this and the migration's step
	// 10 both create it, and the Flagship screen creates it on first open, so no
	// environment needs a manual step after a push. function_exists() because
	// functions.php requires this file before the events module.
	if ( function_exists( 'law_flagship_ensure_post' ) ) {
		$flagship = law_flagship_ensure_post();
		$report[] = ( $flagship['created'] ? 'CREATED  ' : 'OK       ' ) . '/events/flagship/ ' . $flagship['message'];
	}

	// The per-booking host and committee emails, retired 10 September 2026. The
	// registry default is now inactive, but a stored override from the Emails
	// screen would beat it, so the stored 'active' key has to go too.
	if ( function_exists( 'law_setup_retire_booking_received_emails' ) ) {
		$report[] = str_pad( strtoupper( law_setup_retire_booking_received_emails() ), 9 )
			. 'Emails: per-booking host/committee notifications inactive';
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
 * Every account page a signed-in person needs must admit every role a
 * signed-in person can hold.
 *
 * This is the load-bearing half of the 14 September 2026 role retirement. These
 * restrictions are DATABASE state a git deploy cannot carry, and the refusal
 * they produce comes from the Members plugin rather than from anything in this
 * theme, so nothing in the code can compensate for getting them wrong.
 *
 * Two directions, both needed:
 *
 *  - **subscriber**, because from the moment the code ships every new
 *    registrant is one. An environment whose rows still name only event_host,
 *    sponsor and attendee locks every new user out of their own account.
 *  - **the three retired roles**, because of the window between the deploy and
 *    migration step 11, during which everybody who has not yet been converted
 *    still holds one. Page 294 (Submit an event) is the real case: it never
 *    admitted `attendee`, since attendees could not submit, so an un-migrated
 *    attendee would be told by the code that they may submit and refused the
 *    page by the plugin. Nothing in the theme could see that happening.
 *
 * After step 11 the legacy rows are inert. They are left in place deliberately,
 * as the roles themselves are: it keeps a rollback to a code revert, and an
 * inert row costs nothing.
 *
 * Pages with NO restriction rows are left alone: the plugin reads none as
 * public, and adding a row would restrict a page that was open.
 *
 * Replaced law_setup_account_events_attendee_access(), which added the attendee
 * row to /account/events/ for the same reason in the other direction.
 *
 * @return string 'ok', or the named lists, e.g.
 *                'updated: /account/events/submit/ (attendee)'.
 */
function law_setup_account_page_roles() {
	$paths   = array( 'account', 'account/events', 'account/bookings', 'account/events/submit' );
	$needed  = array_merge( array( 'subscriber' ), function_exists( 'law_registration_legacy_roles' ) ? law_registration_legacy_roles() : array( 'event_host', 'sponsor', 'attendee' ) );
	$updated = array();
	$missing = array();
	$open    = array();

	foreach ( $paths as $path ) {
		$page = get_page_by_path( $path );
		if ( ! $page instanceof WP_Post ) {
			$missing[] = '/' . $path . '/';
			continue;
		}
		$roles = get_post_meta( $page->ID, '_members_access_role' );
		if ( ! $roles ) {
			$open[] = '/' . $path . '/'; // No Members restriction on this environment.
			continue;
		}
		$added = array();
		foreach ( $needed as $role ) {
			if ( in_array( $role, $roles, true ) ) {
				continue;
			}
			add_post_meta( $page->ID, '_members_access_role', $role );
			$added[] = $role;
		}
		if ( $added ) {
			$updated[] = '/' . $path . '/ (' . implode( ', ', $added ) . ')';
		}
	}

	$parts = array();
	if ( $updated ) {
		$parts[] = 'updated: ' . implode( ', ', $updated );
	}
	if ( $open ) {
		$parts[] = 'unrestricted: ' . implode( ', ', $open );
	}
	if ( $missing ) {
		$parts[] = 'missing: ' . implode( ', ', $missing );
	}
	return $parts ? implode( '; ', $parts ) : 'ok';
}

/**
 * The /account/ page's body, reduced to the one thing the Account hub template
 * cannot render for itself.
 *
 * /account/ is the hub now (templates/account-hub.php, 14 September 2026): the
 * tiles are built in code from law_header_nav(), so the audience-gated editor
 * copy that used to be the whole page is dead weight. Worse than dead: the
 * [user-content role="attendee"] block names a retired role, so it matches
 * NOBODY once migration step 11 has run, and the role="host" block would show
 * its "submit an event or view your events" links to every signed-in person
 * immediately above tiles that say the same thing.
 *
 * What must survive is [action-message]: law_registration_handler() sends a new
 * account to /account/?action=registered, and that shortcode is what renders
 * the "Registration successful" callout. So the rule is: strip the
 * [user-content] blocks, tidy the empty paragraphs they leave behind, and make
 * sure [action-message] is there.
 *
 * The page body is editor content, so it is database state a git deploy cannot
 * carry; both provisioning routes call this.
 *
 * Idempotent by construction: a second pass finds no [user-content] to remove
 * and the action message already present. Deliberately narrow, like the
 * audience rewrite it replaces: anything else an editor has added to the page
 * is left exactly as it is.
 *
 * @return string ok | updated | missing.
 */
function law_setup_account_page_content() {
	$page = get_page_by_path( 'account' );
	if ( ! $page instanceof WP_Post ) {
		return 'missing';
	}

	$content = (string) $page->post_content;

	// Whole block first (comment delimiters and all), then any bare shortcode
	// pair left outside a block.
	$content = (string) preg_replace(
		'/<!--\s*wp:shortcode\s*-->\s*\[user-content\b.*?\[\/user-content\]\s*<!--\s*\/wp:shortcode\s*-->/s',
		'',
		$content
	);
	$content = (string) preg_replace( '/\[user-content\b[^\]]*\].*?\[\/user-content\]/s', '', $content );

	// The two empty paragraph blocks the page shipped with, plus any the
	// removals above have stranded.
	$content = (string) preg_replace(
		'/<!--\s*wp:paragraph\s*-->\s*<p>\s*<\/p>\s*<!--\s*\/wp:paragraph\s*-->/',
		'',
		$content
	);

	if ( false === strpos( $content, '[action-message]' ) ) {
		$content = "<!-- wp:paragraph -->\n<p>[action-message]</p>\n<!-- /wp:paragraph -->\n\n" . $content;
	}

	$content = trim( (string) preg_replace( "/\n{3,}/", "\n\n", $content ) );

	if ( $content === trim( (string) $page->post_content ) ) {
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

/** The discount-code catalogue's Members restriction. */
function law_setup_discounts_dashboard_access() {
	return law_setup_child_page_access( 'account/dashboard/discounts' );
}

/**
 * Strip the "Roles: {user_roles}" line from the two user-registration emails.
 *
 * The registry defaults lost it with the role retirement (14 September 2026),
 * but the defaults are not what production sends: migration step 9 imported the
 * legacy Gravity Forms notifications as STORED OVERRIDES, and an override beats
 * the default. Both registration emails are overridden on every environment
 * that has run that step, so editing the code alone would leave the committee
 * still reading "Roles: None ticked" on every new account, which is what Denis
 * saw. This is the other half of the change, and it has to run on every
 * environment, which is why both provisioning routes call it.
 *
 * Removes the whole LINE rather than the token: blanking the placeholder would
 * leave a bare "Roles:" behind. Idempotent, and deliberately narrow: only lines
 * carrying {user_roles} go, and only in these two templates, so an override
 * somebody has rewritten by hand keeps everything else it says.
 *
 * @return string ok | updated.
 */
function law_setup_strip_user_roles_from_emails() {
	if ( ! defined( 'LAW_EVENTS_EMAIL_OVERRIDES_OPTION' ) ) {
		return 'ok'; // The events module is not loaded on this environment.
	}
	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	if ( ! is_array( $overrides ) ) {
		return 'ok';
	}

	$changed = false;
	foreach ( array( 'admins_user_registered', 'squareeye_user_registered' ) as $slug ) {
		if ( empty( $overrides[ $slug ]['body'] ) || ! is_string( $overrides[ $slug ]['body'] ) ) {
			continue;
		}
		$body = $overrides[ $slug ]['body'];
		// Split on either line ending: the imported bodies mix \n and \r\n.
		$kept = array_filter(
			preg_split( '/\r\n|\r|\n/', $body ),
			static function ( $line ) {
				return false === strpos( $line, '{user_roles}' );
			}
		);
		$clean = implode( "\n", $kept );
		if ( $clean === $body ) {
			continue;
		}
		$overrides[ $slug ]['body'] = $clean;
		$changed                    = true;
	}

	if ( ! $changed ) {
		return 'ok';
	}
	update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $overrides, false );
	return 'updated';
}

/**
 * Retire the per-booking host and committee emails (Denis, 10 September 2026:
 * a busy event mailed both audiences on every submission, and Manage bookings
 * already lists every booking).
 *
 * Turning them off in law_events_email_registry() is not enough on its own.
 * law_events_email() merges the law_events_email_overrides option over the
 * registry, 'active' included, so on any environment where someone has pressed
 * Save on either email from the Emails screen the stored true would beat the
 * new default and the emails would keep sending after a deploy. This drops the
 * stored 'active' key only, leaving any subject or body edits in place, so
 * ticking the box again brings the site's own wording back.
 *
 * Database state, so it runs from BOTH the ?setup-account-pages trigger and
 * migration step 10: a git push alone has to be enough.
 *
 * @return string ok | updated.
 */
function law_setup_retire_booking_received_emails() {
	if ( ! defined( 'LAW_EVENTS_EMAIL_OVERRIDES_OPTION' ) ) {
		return 'ok'; // The events module is not loaded on this environment.
	}
	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	if ( ! is_array( $overrides ) ) {
		return 'ok';
	}
	$changed = false;
	foreach ( array( 'host_booking_received', 'committee_booking_received' ) as $slug ) {
		if ( isset( $overrides[ $slug ] ) && is_array( $overrides[ $slug ] ) && array_key_exists( 'active', $overrides[ $slug ] ) ) {
			unset( $overrides[ $slug ]['active'] );
			// An override with nothing left in it is just noise on the screen.
			if ( ! $overrides[ $slug ] ) {
				unset( $overrides[ $slug ] );
			}
			$changed = true;
		}
	}
	if ( ! $changed ) {
		return 'ok';
	}
	update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $overrides, false );
	return 'updated';
}

/** The Flagship bookings dashboard's Members restriction. */
function law_setup_flagship_bookings_access() {
	return law_setup_child_page_access( 'account/dashboard/flagship-bookings' );
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
