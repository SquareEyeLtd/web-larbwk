<?php
/**
 * The reusable confirmation modal component.
 *
 * Three files make up the component:
 *   parts/layout/modal.php   the markup, rendered with get_template_part()
 *   assets/css/law-modal.css the dialog, scrim and mobile layout
 *   assets/js/law-modal.js   open/close, the focus trap and the note field
 *
 * The partial calls law_modal_enqueue() itself, so dropping a modal anywhere in
 * the theme brings its own assets. Callers that already know a page needs one
 * can call law_modal_enqueue() from their own wp_enqueue_scripts hook as well,
 * so the stylesheet prints in the head rather than late in the page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register (not enqueue) the modal assets, so law_modal_enqueue() can bring
 * them in from anywhere, including mid-template.
 */
function law_modal_register_assets() {
	// filemtime, not a hand-bumped string: a hand-bumped version was going
	// stale on every edit and serving cached CSS elsewhere in the theme.
	wp_register_style( 'law-modal', get_theme_file_uri( 'assets/css/law-modal.css' ), array(), filemtime( get_theme_file_path( 'assets/css/law-modal.css' ) ) );
	wp_register_script( 'law-modal', get_theme_file_uri( 'assets/js/law-modal.js' ), array(), filemtime( get_theme_file_path( 'assets/js/law-modal.js' ) ), true );
}
add_action( 'wp_enqueue_scripts', 'law_modal_register_assets' );

/**
 * Enqueue the modal assets. Safe to call as often as you like: WordPress
 * ignores a second enqueue of a handle it already has.
 */
function law_modal_enqueue() {
	wp_enqueue_style( 'law-modal' );
	wp_enqueue_script( 'law-modal' );
}
