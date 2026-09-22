<?php
/**
 * Template Name: Event submitters (committee)
 *
 * The committee's Event submitters screen (/account/dashboard/submitters/,
 * functions/events/submitters-dashboard.php): who holds the `event_submitter`
 * role, an Add dialog with a people picker, and a Remove button per row.
 * Restrict the page with Members, as the other committee dashboards are (the
 * setup helper copies the parent's restriction).
 */

// Committee-only in every branch, so nothing in front of PHP may key a copy of
// the HTML on the URL alone.
nocache_headers();

get_header();

$law_can    = law_user_is_committee();
$law_notice = isset( $_GET['law_notice'] ) ? sanitize_key( wp_unslash( $_GET['law_notice'] ) ) : '';
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard law-submitters-dashboard">

	<?php if ( ! $law_can ) : ?>
		<?php
		// The page's own gate. The Members restriction filters the_content(),
		// which this template never calls, so this branch is the real one —
		// the same reason the account hub and the other committee dashboards
		// each carry their own check.
		?>
		<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>

	<?php else : ?>

		<?php
		$law_notices = array(
			'submitter-added'   => array( 'is-success', __( 'They can now submit events.', 'law' ) ),
			'submitter-removed' => array( 'is-success', __( 'They can no longer start new events. Everything they already run is untouched.', 'law' ) ),
			'submitter-failed'  => array( 'is-error', __( 'That change could not be made. Please try again.', 'law' ) ),
			'submitter-denied'  => array( 'is-error', __( 'Sorry, managing event submitters is for the committee.', 'law' ) ),
			'rate-limited'      => array( 'is-error', __( 'Too many changes in a short time; please wait a moment and try again.', 'law' ) ),
		);
		?>
		<?php if ( isset( $law_notices[ $law_notice ] ) ) : ?>
			<p class="law-form-notice <?php echo esc_attr( $law_notices[ $law_notice ][0] ); ?>" role="alert">
				<?php echo esc_html( $law_notices[ $law_notice ][1] ); ?>
			</p>
		<?php endif; ?>

		<?php
		// One line, said once. The page banner already says "Event submitters",
		// so this is here to answer the only question the name does not: what
		// holding the role actually lets somebody do, and what it does not.
		?>
		<p class="law-form-notice" role="status">
			<?php esc_html_e( 'Anybody listed here can propose a new event. Everybody else can still book places, and can still edit and withdraw events they already run, so removing somebody never affects an event that exists.', 'law' ); ?>
		</p>

		<?php get_template_part( 'parts/events/submitters-add' ); ?>
		<?php get_template_part( 'parts/events/submitters-list' ); ?>

	<?php endif; ?>

	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
