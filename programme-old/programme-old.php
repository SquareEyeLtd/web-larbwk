<?php
/**
 * The original programme layout, kept for reference: /programme/?variant=old
 * renders the list as it was before the day-tabs layout became the default
 * (11 September 2026) -- every day stacked, navy day bars, orange slot bars,
 * full cards, the flagship block somewhere in the middle.
 *
 * Self-contained and disposable. This directory carries its own page template
 * (swapped in by template_include), verbatim copies of the two partials as
 * they were, the old rules for the pieces the new layout restyled, and its own
 * AJAX partial endpoint. The only thing outside the directory that knows about
 * it is the require_once in functions.php. To remove it: delete this directory
 * and that one line. No tests, by Denis's decision: it exists to be looked at.
 *
 * It reuses, unchanged: the data layer in functions/calendar.php, the card
 * partial parts/loop/event.php, the flagship block parts/events/flagship-card.php,
 * the hero, and assets/js/calendar-filters.js (whose day-link scroll handler
 * still applies here, because this nav is not a tablist).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LAW_PO_DIR', __DIR__ );

/**
 * True while the old layout is being viewed (?variant=old). Not one of
 * law_calendar_filter_params(), so it is never mistaken for a search.
 */
function law_po_active() {
	return isset( $_GET['variant'] ) && 'old' === trim( sanitize_text_field( wp_unslash( $_GET['variant'] ) ) );
}

/**
 * True on the public or committee programme page template.
 */
function law_po_is_programme_template() {
	return is_page_template( 'templates/calendar.php' ) || is_page_template( 'templates/calendar-committee.php' );
}

/**
 * Render one of this module's partials, get_template_part() style.
 *
 * @param string $name File name under parts/, without .php.
 * @param array  $args
 */
function law_po_part( $name, array $args = array() ) {
	$file = LAW_PO_DIR . '/parts/' . $name . '.php';
	if ( file_exists( $file ) ) {
		include $file;
	}
}

/**
 * Swap in the old page template. Everything that is not the list stays with
 * the default template: the legacy single view (?event=), a visitor the
 * Members gate refuses, and a non-committee visitor on the committee
 * programme, whose sign-in message templates/calendar-committee.php renders.
 *
 * @param string $template
 * @return string
 */
add_filter( 'template_include', 'law_po_template_include', 99 );
function law_po_template_include( $template ) {
	if ( ! law_po_active() || ! law_po_is_programme_template() || law_calendar_requested_event_id() ) {
		return $template;
	}
	$page_id = get_queried_object_id();
	if ( function_exists( 'members_can_current_user_view_post' ) && $page_id && ! members_can_current_user_view_post( $page_id ) ) {
		return $template;
	}
	if ( is_page_template( 'templates/calendar-committee.php' ) && ! ( function_exists( 'law_user_is_committee' ) && law_user_is_committee() ) ) {
		return $template;
	}
	return LAW_PO_DIR . '/template.php';
}

/**
 * The AJAX partial for the old layout: &law_partial=1&variant=old returns the
 * old events partial. Priority 9, ahead of law_calendar_maybe_render_partial()
 * at 10, which would otherwise answer with the default markup.
 */
add_action( 'template_redirect', 'law_po_maybe_render_partial', 9 );
function law_po_maybe_render_partial() {
	if ( empty( $_GET['law_partial'] ) || ! law_po_active() || ! law_po_is_programme_template() ) {
		return;
	}
	$page_id = get_queried_object_id();
	if ( function_exists( 'members_can_current_user_view_post' ) && $page_id && ! members_can_current_user_view_post( $page_id ) ) {
		status_header( 403 );
		exit;
	}
	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	nocache_headers();
	law_po_part( 'events', array( 'show_status' => law_calendar_is_committee() ) );
	exit;
}

/**
 * The old rules for what the default layout restyled, after calendar.css so
 * they win. Only while the old layout is being viewed.
 */
add_action( 'wp_enqueue_scripts', 'law_po_enqueue', 30 );
function law_po_enqueue() {
	if ( ! law_po_active() || ! law_po_is_programme_template() ) {
		return;
	}
	wp_enqueue_style(
		'law-programme-old',
		get_theme_file_uri( '/programme-old/assets/programme-old.css' ),
		array( 'law-calendar' ),
		filemtime( LAW_PO_DIR . '/assets/programme-old.css' )
	);
}
