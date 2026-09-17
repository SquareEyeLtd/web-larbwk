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
		// The committee's Manage receptions screen
		// (functions/events/receptions-dashboard.php).
		$setup['account/dashboard/receptions'] = 'templates/account-dashboard-receptions.php';
		// The flagship's applications and payments, its own page rather than
		// a section of Manage bookings
		// (functions/events/flagship-bookings-dashboard.php).
		$setup['account/dashboard/flagship-bookings'] = 'templates/account-dashboard-flagship-bookings.php';
		// The committee's discount-code catalogue
		// (functions/events/discounts-dashboard.php).
		$setup['account/dashboard/discounts'] = 'templates/account-dashboard-discounts.php';
		// The committee's front-end copy of the wp-admin Emails screen
		// (functions/events/emails-dashboard.php).
		$setup['account/dashboard/emails'] = 'templates/account-dashboard-emails.php';
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
	$report[] = 'ACCESS   /account/dashboard/receptions/ committee restriction: ' . law_setup_receptions_dashboard_access();
	$report[] = 'ACCESS   /account/dashboard/flagship-bookings/ committee restriction: ' . law_setup_flagship_bookings_access();
	$report[] = 'ACCESS   /account/dashboard/discounts/ committee restriction: ' . law_setup_discounts_dashboard_access();
	$report[] = 'ACCESS   /account/dashboard/emails/ committee restriction: ' . law_setup_emails_dashboard_access();

	// The flagship conference. A law_event post rather than a page, so a git
	// deploy carries the code but not the record; this and the migration's step
	// 10 both create it, and the Flagship screen creates it on first open, so no
	// environment needs a manual step after a push. function_exists() because
	// functions.php requires this file before the events module.
	if ( function_exists( 'law_flagship_ensure_post' ) ) {
		$flagship = law_flagship_ensure_post();
		$report[] = ( $flagship['created'] ? 'CREATED  ' : 'OK       ' ) . '/events/flagship/ ' . $flagship['message'];
	}

	// The three drinks receptions, for the same reason and in the same way:
	// law_event posts rather than pages, so a git deploy carries the code but
	// not the records. Seeded as drafts with no price, because the prices are
	// LAW's to confirm (RECEPTIONS.md §8.1).
	if ( function_exists( 'law_reception_ensure_posts' ) ) {
		$receptions = law_reception_ensure_posts();
		foreach ( $receptions['messages'] as $reception_message ) {
			$report[] = ( 0 === strpos( $reception_message, 'Created' ) ? 'CREATED  ' : 'OK       ' ) . 'reception: ' . $reception_message;
		}
	}

	// The per-booking host and committee emails, retired 10 September 2026. The
	// registry default is now inactive, but a stored override from the Emails
	// screen would beat it, so the stored 'active' key has to go too.
	if ( function_exists( 'law_setup_retire_booking_received_emails' ) ) {
		$report[] = str_pad( strtoupper( law_setup_retire_booking_received_emails() ), 9 )
			. 'Emails: per-booking host/committee notifications inactive';
	}

	// The "payment due" email carries a line naming the invoice a post-approval
	// fee change has just cancelled. A stored body beats the registry default,
	// so it needs the placeholder appending per environment.
	if ( function_exists( 'law_setup_add_fee_change_note_to_payment_due' ) ) {
		$report[] = str_pad( strtoupper( law_setup_add_fee_change_note_to_payment_due() ), 9 )
			. 'Emails: payment-due body carries {fee_change_note}';
	}

	// The flagship started accepting discount codes on 15 September 2026, and
	// an unscoped code means "anywhere there is a price", so every code the
	// committee wrote for a £45 reception became valid against a £550
	// conference ticket at the moment of deploy. Pin those codes to what they
	// were actually written for.
	if ( function_exists( 'law_setup_scope_existing_discounts' ) ) {
		$report[] = str_pad( strtoupper( law_setup_scope_existing_discounts() ), 9 )
			. 'Discounts: pre-flagship unscoped codes limited to the paid receptions';
	}

	// Events migrated before 16 September 2026 hold the Gravity Forms choice
	// value of field 103 (Venue needed) rather than its label, so the answer
	// reads as unset on the event form.
	if ( function_exists( 'law_setup_normalise_venue_needed' ) ) {
		$report[] = str_pad( strtoupper( law_setup_normalise_venue_needed() ), 9 )
			. 'Events: migrated "Venue needed" answers mapped onto their labels';
	}

	// Half the migrated events hold a bare ISO code as their billing country,
	// where the Country select offers names.
	if ( function_exists( 'law_setup_normalise_invoice_countries' ) ) {
		$report[] = str_pad( strtoupper( law_setup_normalise_invoice_countries() ), 9 )
			. 'Events: migrated billing countries mapped onto their names';
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

/** Manage emails' Members restriction. */
function law_setup_emails_dashboard_access() {
	return law_setup_child_page_access( 'account/dashboard/emails' );
}

/**
 * Manage receptions' Members restriction.
 *
 * A committee child page, so its rows come from /account/dashboard/ and it is
 * deliberately NOT in law_setup_account_subscriber_access()'s list: that list
 * is the four pages a plain subscriber needs (RECEPTIONS.md §0.4).
 */
function law_setup_receptions_dashboard_access() {
	return law_setup_child_page_access( 'account/dashboard/receptions' );
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

/**
 * Give a stored "payment due" body the {fee_change_note} slot the registry
 * default gained on 17 September 2026.
 *
 * The committee can now change a host fee after approval: the open invoice is
 * voided and a replacement raised, and the host is sent the same
 * `user_payment_due` email with a line saying which invoice was cancelled.
 * That line is a placeholder in the body, and a body EDITED on the Emails
 * screen (or imported by migration step 9) beats the registry default, so on
 * those environments the host would get a second "payment due" email with no
 * hint that the first invoice is dead. Appending the tag is the other half of
 * the change, which is why both provisioning routes call it.
 *
 * Deliberately narrow and idempotent: only the one slug, only when a stored
 * body exists and does not already carry the tag, and the tag goes on the END
 * of the body rather than into the middle of a sentence somebody has written
 * themselves. It renders as empty on every ordinary approval.
 *
 * @return string ok | updated.
 */
function law_setup_add_fee_change_note_to_payment_due() {
	if ( ! defined( 'LAW_EVENTS_EMAIL_OVERRIDES_OPTION' ) ) {
		return 'ok'; // The events module is not loaded on this environment.
	}
	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	if ( ! is_array( $overrides ) ) {
		return 'ok';
	}
	$body = $overrides['user_payment_due']['body'] ?? '';
	if ( ! is_string( $body ) || '' === trim( $body ) || false !== strpos( $body, '{fee_change_note}' ) ) {
		return 'ok';
	}

	$overrides['user_payment_due']['body'] = rtrim( $body ) . "\n\n{fee_change_note}";
	update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $overrides, false );
	return 'updated';
}

/**
 * The moment the flagship started accepting discount codes, and with it the
 * moment an empty "Applies to" changed meaning from "every paid reception" to
 * "every paid event". Only codes written before it are backfilled.
 */
if ( ! defined( 'LAW_DISCOUNT_FLAGSHIP_CUTOVER' ) ) {
	define( 'LAW_DISCOUNT_FLAGSHIP_CUTOVER', strtotime( '2026-09-15 00:00:00 UTC' ) );
}

/**
 * Pin every code that predates the flagship to the receptions it was written
 * for.
 *
 * An empty "Applies to" means "wherever a place is charged for" (Denis,
 * 15 September 2026). That is the right rule going forward and the wrong one
 * applied backwards: a code created when the receptions were the only priced
 * thing would quietly have become valid against a conference ticket more than
 * ten times the price. So, once, the priced receptions are ticked onto every
 * code that has no scope at all.
 *
 * Idempotent twice over: a one-shot option flag, and it only ever touches
 * codes whose scope is empty. Runs from BOTH the ?setup-account-pages trigger
 * and migration step 10, because a git push alone has to be enough.
 *
 * @return string ok | skipped | updated
 */
function law_setup_scope_existing_discounts() {
	if ( ! function_exists( 'law_discounts_all' ) || ! defined( 'LAW_DISCOUNT_CPT' ) ) {
		return 'ok'; // The events module is not loaded on this environment.
	}
	if ( get_option( 'law_discounts_scoped_before_flagship' ) ) {
		return 'ok';
	}

	// Whatever the receptions offer as a scope right now. If none of them is
	// priced there is nothing to pin a code to, and the flag is NOT set, so
	// this runs again once one goes on sale.
	$receptions = array();
	if ( function_exists( 'law_reception_ids' ) && function_exists( 'law_event_is_priced' ) ) {
		foreach ( law_reception_ids() as $reception_id ) {
			if ( law_event_is_priced( $reception_id ) ) {
				$receptions[] = (int) $reception_id;
			}
		}
	}
	if ( ! $receptions ) {
		return 'skipped';
	}

	$changed = 0;
	foreach ( law_discounts_all() as $discount ) {
		$data = law_discount_data( $discount );
		if ( ! empty( $data['events'] ) ) {
			continue;
		}
		// Only codes that PREDATE the reversal. The flag alone is not enough:
		// this returns 'skipped' without setting it while nothing is priced,
		// so on a site where the receptions go on sale later, a code written
		// after 15 September with a deliberately empty scope — which now means
		// "every paid event, the flagship included" — would be narrowed to the
		// receptions it was never meant to be pinned to. (Security review,
		// 15 September 2026.)
		$created = get_post_field( 'post_date_gmt', (int) $data['id'] );
		if ( $created && strtotime( $created . ' UTC' ) >= LAW_DISCOUNT_FLAGSHIP_CUTOVER ) {
			continue;
		}
		law_event_update_meta( (int) $data['id'], '_law_discount_events', $receptions );
		$changed++;
		law_event_log(
			(int) $data['id'],
			sprintf(
				'Discount code %s limited to the paid receptions. It had no "Applies to" set, and an empty scope now means every paid event including the flagship conference, which this code was written before.',
				$data['code']
			),
			array( 'source' => 'discounts', 'action' => 'discount_scoped_on_upgrade', 'discount' => (int) $data['id'], 'events' => $receptions )
		);
	}

	update_option( 'law_discounts_scoped_before_flagship', 1, false );

	return $changed ? 'updated' : 'ok';
}

/**
 * Rewrite every migrated "Venue needed" answer as the label the form uses.
 *
 * Field 103 (Venue needed) on form 2 (Event > submit an event) is a radio
 * whose choice values are the bare "Yes" and "No", and a Gravity Forms entry
 * stores the value, so every event the migration brought across before
 * 16 September 2026 holds "Yes" or "No" where the custom form's radios carry
 * the full sentences. law_events_venue_needed_label() makes the form and the
 * venue detail logic read those correctly either way, but the stored answer is
 * also what the committee's event panel prints and what any later reader will
 * meet, so it is normalised in place as well.
 *
 * Idempotent: only rows whose value is not already the canonical label are
 * touched, and the write goes through law_event_update_meta() so the meta
 * sanitiser does the mapping. Deliberately NOT logged on each event -- this
 * corrects how an answer was recorded, it does not change anyone's answer.
 * Runs from BOTH the ?setup-account-pages trigger and migration step 10,
 * because a git push alone has to be enough.
 *
 * @return string ok | updated
 */
function law_setup_normalise_venue_needed() {
	global $wpdb;

	if ( ! function_exists( 'law_events_venue_needed_label' ) ) {
		return 'ok'; // The events module is not loaded on this environment.
	}

	$rows = $wpdb->get_results(
		"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_law_venue_needed' AND meta_value <> ''"
	);

	$changed = 0;
	foreach ( (array) $rows as $row ) {
		$label = law_events_venue_needed_label( $row->meta_value );
		if ( '' === $label || $label === $row->meta_value ) {
			continue;
		}
		law_event_update_meta( (int) $row->post_id, '_law_venue_needed', $label );
		$changed++;
	}

	return $changed ? 'updated' : 'ok';
}

/**
 * Rewrite every migrated billing country that is a bare ISO code as the
 * country name the forms offer.
 *
 * Form 2 (Event > submit an event) field 74 (Address) input 74.6 (Country)
 * holds both spellings across the production entries ("GB" on 231, "United
 * Kingdom" on 198), because two different front ends filled it in over the
 * form's life. law_events_country_display_name() makes every surface read the
 * code correctly, but the stored row is what an export and any later reader
 * meet, so it is normalised in place too.
 *
 * Idempotent, and it only ever touches a country that is exactly two letters
 * and maps to a name; anything else is left alone. The write goes through
 * law_event_update_meta(), so the address sanitiser does the mapping. Not
 * logged per event: this corrects a spelling, not the host's answer. Runs from
 * BOTH the ?setup-account-pages trigger and migration step 10, because a git
 * push alone has to be enough.
 *
 * @return string ok | updated
 */
function law_setup_normalise_invoice_countries() {
	global $wpdb;

	if ( ! function_exists( 'law_events_country_display_name' ) ) {
		return 'ok'; // The events module is not loaded on this environment.
	}

	$post_ids = $wpdb->get_col(
		"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_law_invoice_address' AND meta_value <> ''"
	);

	$changed = 0;
	foreach ( array_map( 'intval', (array) $post_ids ) as $post_id ) {
		$address = law_event_meta( $post_id, '_law_invoice_address' );
		$country = (string) ( $address['country'] ?? '' );
		$name    = law_events_country_display_name( $country );
		if ( '' === $country || $name === $country ) {
			continue;
		}
		$address['country'] = $name;
		law_event_update_meta( $post_id, '_law_invoice_address', $address );
		$changed++;
	}

	return $changed ? 'updated' : 'ok';
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
