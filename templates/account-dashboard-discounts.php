<?php
/**
 * Template Name: Discount codes (committee)
 *
 * The committee's discount-code catalogue (/account/dashboard/discounts/,
 * functions/events/discounts-dashboard.php): every code in one table with
 * CSV / Excel / PDF export, and an editor at ?law_discount=<id> or
 * ?law_discount=new. Restrict the page with Members, as the other committee
 * dashboards are (the setup helper copies the parent's restriction).
 */

// Committee-only in every branch, so nothing in front of PHP may key a copy of
// the HTML on the URL alone.
nocache_headers();

get_header();

$law_can      = law_user_is_committee();
$law_asked    = $law_can ? law_discounts_dashboard_requested() : 0;
$law_notice   = isset( $_GET['law_notice'] ) ? sanitize_key( wp_unslash( $_GET['law_notice'] ) ) : '';
$law_page_url = get_permalink();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard law-discounts-dashboard">

	<?php if ( ! $law_can ) : ?>
		<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>

	<?php else : ?>

		<?php
		$law_notices = array(
			'discount-saved'    => array( 'is-success', __( 'The discount code has been saved.', 'law' ) ),
			'discount-enabled'  => array( 'is-success', __( 'The discount code can be used again.', 'law' ) ),
			'discount-disabled' => array( 'is-success', __( 'The discount code has been disabled.', 'law' ) ),
			'discount-failed'   => array( 'is-error', __( 'The discount code was not saved. Please check the fields below.', 'law' ) ),
			'discount-denied'   => array( 'is-error', __( 'Sorry, managing discount codes is for the committee.', 'law' ) ),
			'rate-limited'      => array( 'is-error', __( 'Too many changes in a short time; please wait a moment and try again.', 'law' ) ),
		);
		?>
		<?php if ( isset( $law_notices[ $law_notice ] ) ) : ?>
			<p class="law-form-notice <?php echo esc_attr( $law_notices[ $law_notice ][0] ); ?>" role="alert">
				<?php echo esc_html( $law_notices[ $law_notice ][1] ); ?>
			</p>
		<?php endif; ?>

		<?php if ( $law_asked ) : ?>
			<?php
			get_template_part(
				'parts/events/discounts-manage',
				null,
				array( 'discount_id' => 'new' === $law_asked ? 0 : (int) $law_asked )
			);
			?>
		<?php else : ?>

			<h1 class="law-dashboard__title"><?php esc_html_e( 'Discount codes', 'law' ); ?></h1>

			<?php
			// Honest about the state of things: the catalogue is here so codes
			// can be prepared, but nothing on the site takes one yet.
			?>
			<p class="law-form-notice" role="status">
				<?php esc_html_e( 'Nothing on the site accepts a discount code yet. Every event in the programme is free to attend, and the flagship conference does not use codes. You can set codes up here ready for when something does.', 'law' ); ?>
			</p>

			<div class="law-cal-controls" data-law-cal-controls data-page-url="<?php echo esc_url( $law_page_url ); ?>">
				<p class="law-dashboard__actions">
					<a class="button orange" href="<?php echo esc_url( law_discounts_dashboard_url( 'new' ) ); ?>"><?php esc_html_e( 'Add a code', 'law' ); ?></a>
				</p>

				<?php
				// The export trio, as on every other dashboard
				// (functions/events/export.php). No filters to bake in: the
				// catalogue is small enough to read whole.
				$law_export_base = wp_nonce_url( admin_url( 'admin-post.php?action=law_discounts_dashboard_export' ), 'law_discounts_dashboard_export' );
				?>
				<div class="law-cal-export" data-law-export data-export-url="<?php echo esc_url( $law_export_base ); ?>">
					<span class="law-cal-export__label"><?php esc_html_e( 'Export:', 'law' ); ?></span>
					<a class="button second" data-format="csv" href="<?php echo esc_url( add_query_arg( 'format', 'csv', $law_export_base ) ); ?>">CSV</a>
					<a class="button second" data-format="xlsx" href="<?php echo esc_url( add_query_arg( 'format', 'xlsx', $law_export_base ) ); ?>">Excel</a>
					<button type="button" class="button second" data-format="pdf" hidden>PDF</button>
				</div>
			</div>

			<?php get_template_part( 'parts/events/discounts-list' ); ?>

		<?php endif; ?>
	<?php endif; ?>

	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
