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

$law_cal_show_status = false;
$law_cal_hero_title  = 'Calendar of Events';
require get_theme_file_path( 'parts/calendar-body.php' );
