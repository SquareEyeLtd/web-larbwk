<?php
/**
 * Template Name: Programme calendar
 *
 * Public programme. Restrict the page with Members if needed.
 */

$law_cal_show_status = false;
$law_cal_hero_title  = law_calendar_hero_title();
require get_theme_file_path( 'parts/calendar-body.php' );
