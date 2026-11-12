<?php
/**
 * Template Name: Manage time slots (committee)
 *
 * The committee's slot list (/account/dashboard/slots/,
 * functions/events/slots-dashboard.php): the dates and times hosts choose
 * from, as an editable table. Restrict the page with Members, as the other
 * committee dashboards are (the setup helper copies the parent's restriction).
 */

// Committee-only in every branch, so nothing in front of PHP may key a copy of
// the HTML on the URL alone.
nocache_headers();

get_header();

$law_ts_can = law_user_is_committee();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard law-slots-dashboard">
		<?php if ( ! $law_ts_can ) : ?>
			<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>
		<?php else : ?>
			<?php get_template_part( 'parts/events/slots-manage' ); ?>
		<?php endif; ?>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
