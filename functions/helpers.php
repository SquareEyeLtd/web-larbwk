<?php
	
// Helper: theme asset URL
function law_asset( $path ) {
    return get_template_directory_uri() . '/' . ltrim( $path, '/' );
}

/**
 * The site's default hero photograph.
 *
 * One definition, because two things now need the same picture: the hero
 * partial's own default, and the flagship's programme block, which falls back
 * to it when no banner image has been chosen. The two must not drift, or the
 * block and the page it links to would show different photographs.
 */
function law_hero_default_image_url() {
	return law_asset( 'assets/images/patrons-and-committee-bg.jpg' );
}
