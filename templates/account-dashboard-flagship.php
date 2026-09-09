<?php
/**
 * Template Name: Flagship dashboard (committee)
 *
 * The committee's "Manage flagship" view (/account/dashboard/flagship/,
 * functions/events/flagship-dashboard.php): the same fields as the wp-admin
 * Flagship screen, editing the same post through the same code. Restrict the
 * page with Members, as the events dashboard is (the setup helper copies the
 * parent's restriction).
 */

// Committee-only in every branch, so nothing in front of PHP may key a copy of
// the HTML on the URL alone. Called before any output, as on the sibling
// dashboards.
nocache_headers();

get_header();

$law_fd_can = law_user_is_committee();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard law-flagship-dashboard">
		<?php if ( ! $law_fd_can ) : ?>
			<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>
		<?php else : ?>
			<?php get_template_part( 'parts/events/flagship-manage' ); ?>
		<?php endif; ?>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
