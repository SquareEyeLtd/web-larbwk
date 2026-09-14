<?php
/**
 * Template Name: Receptions dashboard (committee)
 *
 * The committee's "Manage receptions" view (/account/dashboard/receptions/,
 * functions/events/receptions-dashboard.php): the three drinks receptions in
 * one table with CSV / Excel / PDF export, and an editor at
 * ?law_reception=<id> or ?law_reception=new. The same fields as the wp-admin
 * Reception box, editing the same posts through the same code. Restrict the
 * page with Members, as the other committee dashboards are (the setup helper
 * copies the parent's restriction).
 */

// Committee-only in every branch, so nothing in front of PHP may key a copy of
// the HTML on the URL alone. Called before any output, as on the sibling
// dashboards.
nocache_headers();

get_header();

$law_rd_can    = law_user_is_committee();
$law_rd_asked  = $law_rd_can ? law_receptions_dashboard_requested() : 0;
$law_rd_notice = isset( $_GET['law_notice'] ) ? sanitize_key( wp_unslash( $_GET['law_notice'] ) ) : '';
$law_rd_page   = get_permalink();

// The three seed records, so a freshly deployed environment shows the
// receptions rather than an empty table with nothing to press. Idempotent, and
// here as well as in migration step 10 and the ?setup-account-pages trigger,
// because a deployed environment must work without either being run by hand.
if ( $law_rd_can && function_exists( 'law_reception_ensure_posts' ) ) {
	law_reception_ensure_posts();
}
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard law-flagship-dashboard law-receptions-dashboard">

	<?php if ( ! $law_rd_can ) : ?>
		<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>

	<?php else : ?>

		<?php $law_rd_notices = law_receptions_dashboard_notices(); ?>
		<?php if ( isset( $law_rd_notices[ $law_rd_notice ] ) ) : ?>
			<p class="law-form-notice <?php echo esc_attr( $law_rd_notices[ $law_rd_notice ][0] ); ?>" role="alert">
				<?php echo esc_html( $law_rd_notices[ $law_rd_notice ][1] ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $law_rd_asked ) : ?>

			<?php
			get_template_part(
				'parts/layout/back-link',
				null,
				array(
					'url'   => law_receptions_dashboard_url(),
					'label' => __( 'Back to the receptions', 'law' ),
				)
			);
			get_template_part(
				'parts/events/reception-manage',
				null,
				array( 'event_id' => 'new' === $law_rd_asked ? 0 : (int) $law_rd_asked )
			);
			?>

		<?php else : ?>

			<h1 class="law-dashboard__title"><?php esc_html_e( 'Receptions', 'law' ); ?></h1>
			<p class="law-dashboard__lead">
				<?php esc_html_e( 'The drinks receptions LAW runs during the week. Each one is an event in the programme, so its page, its calendar entry and its bookings all work the way any other event does. What you set here is when and where it is, how many places there are, what a place costs, whether a confirmed flagship delegate gets one free, and whether the site takes bookings at all.', 'law' ); ?>
			</p>

			<div class="law-cal-controls" data-law-cal-controls data-page-url="<?php echo esc_url( $law_rd_page ); ?>">
				<p class="law-dashboard__actions">
					<a class="button orange" href="<?php echo esc_url( law_receptions_dashboard_url( 'new' ) ); ?>"><?php esc_html_e( 'Add a reception', 'law' ); ?></a>
				</p>

				<?php
				// The export trio, as on every other dashboard
				// (functions/events/export.php). No filters to bake in: there
				// are three receptions and the table is read whole.
				$law_rd_export = wp_nonce_url( admin_url( 'admin-post.php?action=law_receptions_dashboard_export' ), 'law_receptions_dashboard_export' );
				?>
				<div class="law-cal-export" data-law-export data-export-url="<?php echo esc_url( $law_rd_export ); ?>">
					<span class="law-cal-export__label"><?php esc_html_e( 'Export:', 'law' ); ?></span>
					<a class="button second" data-format="csv" href="<?php echo esc_url( add_query_arg( 'format', 'csv', $law_rd_export ) ); ?>">CSV</a>
					<a class="button second" data-format="xlsx" href="<?php echo esc_url( add_query_arg( 'format', 'xlsx', $law_rd_export ) ); ?>">Excel</a>
					<button type="button" class="button second" data-format="pdf" hidden>PDF</button>
				</div>
			</div>

			<?php get_template_part( 'parts/events/receptions-list' ); ?>

		<?php endif; ?>
	<?php endif; ?>

	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
