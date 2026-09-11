<?php
/**
 * The committee's front-end "Manage flagship" screen
 * (/account/dashboard/flagship/).
 *
 * The fourth of the committee's front-end dashboards, after the events review
 * queue, Bookings and Manage Speakers, and it exists for the same reason they
 * do: the committee works from the site, not from wp-admin, and a member who
 * only holds the events_committee role should not have to learn two interfaces.
 *
 * It edits the SAME post, through the SAME code, as the wp-admin Flagship
 * screen. Nothing about the data lives here: reading a submission, validating
 * it and writing it are `functions/events/flagship.php`, and the session
 * agenda's fields are `functions/events/flagship-form.php`, both shared with
 * `admin/flagship-screen.php`. This file is the route, the capability gate, the
 * POST handler and the assets.
 *
 * Access is committee, editor or administrator (`law_user_is_committee()`),
 * checked in three independent places, because each can be reached on its own:
 * the header link (functions/header-nav.php), the template
 * (templates/account-dashboard-flagship.php) and this file's save handler. The
 * page should also carry the Members restriction its sibling dashboards do; the
 * setup helper copies the parent's.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The page template, and the path the header link and the setup helpers use. */
const LAW_FLAGSHIP_DASHBOARD_TEMPLATE = 'templates/account-dashboard-flagship.php';
const LAW_FLAGSHIP_DASHBOARD_PATH     = 'account/dashboard/flagship';

/**
 * The dashboard's URL. Resolved by path, like every other account page, so a
 * page created with a different ID on another environment still works.
 */
function law_flagship_dashboard_url() {
	if ( function_exists( 'law_account_url' ) ) {
		$url = law_account_url( 'flagship' );
		if ( '' !== $url ) {
			return $url;
		}
	}
	return home_url( '/' . LAW_FLAGSHIP_DASHBOARD_PATH . '/' );
}

/** True on the dashboard page. */
function law_flagship_dashboard_is_template() {
	return is_page_template( LAW_FLAGSHIP_DASHBOARD_TEMPLATE );
}

/**
 * The errors and typed values a refused save left behind, once.
 *
 * Same one-shot transient as the speakers dashboard: the handler answers with a
 * redirect, so what the committee typed has to survive the round trip or a
 * validation failure would throw the whole agenda away.
 *
 * @return array{errors:string[], input:array}
 */
function law_flagship_dashboard_state() {
	$key   = 'law_flagship_state_' . get_current_user_id();
	$state = get_transient( $key );
	if ( ! is_array( $state ) ) {
		return array( 'errors' => array(), 'input' => array() );
	}
	delete_transient( $key );

	return array(
		'errors' => (array) ( $state['errors'] ?? array() ),
		'input'  => (array) ( $state['input'] ?? array() ),
	);
}

/* The save handler __________________________________________________________ */

add_action( 'admin_post_law_flagship_manage', 'law_flagship_dashboard_save_handler' );
add_action( 'admin_post_nopriv_law_flagship_manage', 'law_events_nopriv_json' );

/**
 * Read the POST, hand it to the shared saver, redirect with a notice.
 *
 * The only difference from the wp-admin screen's controller is the plumbing
 * around it: this one goes through law_events_guard_post() for the nonce, the
 * honeypot and its own rate surface, and answers with a redirect rather than
 * re-rendering in place.
 */
function law_flagship_dashboard_save_handler() {
	$is_ajax = law_events_guard_post(
		'law_flagship_manage',
		array(
			// Its own surface: editing a long agenda is many saves, and it must
			// not spend the event-submission budget.
			'rate'            => array( 'flagship_manage', 30, 600 ),
			'honeypot_json'   => array( 'message' => 'Saved.' ),
			'honeypot_notice' => 'flagship-saved',
		)
	);

	if ( ! law_user_is_committee() ) {
		law_events_respond(
			$is_ajax,
			false,
			array( 'message' => 'Sorry, managing the flagship event is for the committee.', 'status' => 403 ),
			'flagship-denied'
		);
	}

	$input  = law_flagship_input_from_post();
	$result = law_flagship_save( $input, get_current_user_id() );

	if ( is_wp_error( $result ) ) {
		$errors = $result->get_error_messages();
		set_transient(
			'law_flagship_state_' . get_current_user_id(),
			array( 'errors' => $errors, 'input' => $input ),
			10 * MINUTE_IN_SECONDS
		);
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Please fix the problems listed.', 'errors' => $errors ), 400 );
		}
		wp_safe_redirect( add_query_arg( 'law_notice', 'flagship-invalid', law_flagship_dashboard_url() ) );
		exit;
	}

	if ( $is_ajax ) {
		wp_send_json_success( array( 'message' => 'Flagship event saved.' ) );
	}
	wp_safe_redirect( add_query_arg( 'law_notice', 'flagship-saved', law_flagship_dashboard_url() ) );
	exit;
}

/* Assets ____________________________________________________________________ */

add_action(
	'wp_enqueue_scripts',
	function () {
		if ( ! law_flagship_dashboard_is_template() || ! law_user_is_committee() ) {
			return;
		}

		$mtime = function ( $rel ) {
			$path = get_theme_file_path( $rel );
			return file_exists( $path ) ? filemtime( $path ) : '1.0';
		};

		// The session agenda's fields are the admin field library's
		// (functions/events/flagship-form.php), so their behaviour comes from the
		// admin scripts rather than from a second implementation on the front
		// end: the speaker search and its photo control (law-admin.js, which
		// exposes window.lawAdminFields.initAll for rows added later) and the
		// sessions repeater with its "add new speaker" template
		// (law-flagship-admin.js). wp.media backs the banner-image picker; the
		// committee role is granted upload_files for exactly this kind of thing
		// (functions/events/capabilities.php).
		wp_enqueue_media();
		law_rich_text_enqueue();

		// The theme is dark-hero-first (body colour is white), and wp.media
		// appends its modal at body level, outside every page section, so
		// without this the media library opened white-on-white. Its own
		// stylesheet rather than part of this screen's, because the selectors
		// cannot be page-scoped (the modal is a sibling of the content) and any
		// future front-end screen calling wp_enqueue_media() needs the same fix.
		wp_enqueue_style( 'law-wp-media-frontend', get_theme_file_uri( 'assets/css/wp-media-frontend.css' ), array(), $mtime( 'assets/css/wp-media-frontend.css' ) );
		wp_enqueue_style( 'law-events-admin', get_theme_file_uri( 'assets/css/law-admin.css' ), array(), '1.6' );
		wp_enqueue_style( 'law-flagship-dashboard', get_theme_file_uri( 'assets/css/flagship-dashboard.css' ), array( 'law-events-admin' ), $mtime( 'assets/css/flagship-dashboard.css' ) );

		wp_enqueue_script( 'law-events-admin', get_theme_file_uri( 'assets/js/law-admin.js' ), array(), '1.7', true );
		wp_localize_script(
			'law-events-admin',
			'lawEventsAdmin',
			array(
				'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
				// The same nonce and the same endpoint the wp-admin screen uses;
				// wp_ajax_law_events_search_posts re-checks edit_law_events itself,
				// so reaching it from the front end grants nothing extra.
				'nonce'       => wp_create_nonce( 'law_events_admin' ),
				'roleChoices' => law_speaker_roles(),
			)
		);
		wp_enqueue_script(
			'law-flagship-admin',
			get_theme_file_uri( 'assets/js/law-flagship-admin.js' ),
			array( 'law-events-admin', 'law-rich-text' ),
			$mtime( 'assets/js/law-flagship-admin.js' ),
			true
		);
	}
);
