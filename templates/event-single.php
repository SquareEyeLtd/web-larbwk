<?php
/**
 * Single law_event page (no Template Name header: swapped in by
 * template_include in functions/events/source.php). Renders the same
 * programme body the ?event= view used, so the listing looks identical
 * at its new permalink.
 */

// The calendar body resolves the requested event from ?event=; feed it the
// current post so the single view renders without duplicating the markup.
$_GET['event'] = (string) get_the_ID();

// An APPROVED event is on the programme and has a real page, but it is not
// paid for yet: it can still be cancelled, and an indexed page that then 404s
// is worse than a late listing. Sent as a header rather than a robots meta
// tag, which is the idiom used elsewhere in this theme and avoids two
// conflicting tags on a page where SEOPress prints its own. Nothing is needed
// for the sitemap: SEOPress builds it from post_status publish only.
if ( 'publish' !== get_post_status() ) {
	header( 'X-Robots-Tag: noindex' );
}

$law_cal_show_status = false;
$law_cal_hero_title  = law_calendar_hero_title();
require get_theme_file_path( 'parts/calendar-body.php' );
