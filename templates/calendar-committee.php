<?php
/**
 * Template Name: Programme calendar (committee)
 *
 * All event statuses, with labels. Restrict with Members to the events
 * committee — and enforce it in code too, so a mis-set or cleared Members
 * rule can't expose every submitted event's detail to any visitor.
 */

if ( ! function_exists( 'law_user_is_committee' ) || ! law_user_is_committee() ) {
	get_header();
	echo '<section class="hero auth-hero hero-solid"><div class="grid-container"><div class="grid-x grid-padding-x"><div class="large-9 cell auth-intro"><p>This programme view is for the events committee. Please <a href="' . esc_url( wp_login_url( home_url( add_query_arg( array() ) ) ) ) . '">sign in</a> with a committee account.</p></div></div></div></section>';
	get_footer();
	return;
}

$law_cal_show_status = true;
require get_theme_file_path( 'parts/calendar-body.php' );
