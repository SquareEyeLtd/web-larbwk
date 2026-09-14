<?php
/**
 * Template Name: Receptions dashboard (committee)
 *
 * The committee's "Manage receptions" view (/account/dashboard/receptions/,
 * functions/events/receptions-dashboard.php): the three drinks receptions in
 * one table, and an editor at ?law_reception=<id> or ?law_reception=new. The
 * same fields as the wp-admin Reception box, editing the same posts through
 * the same code. Restrict the page with Members, as the other committee
 * dashboards are (the setup helper copies the parent's restriction).
 *
 * TWO SHAPES, on purpose (Denis, 14 September 2026). The LIST is a dashboard
 * table like every other committee list: hero title, then a white page
 * section, because a table of facts belongs on white. The EDITOR is the
 * SUBMIT-AN-EVENT form's shape -- the filled navy hero, `.auth-hero`,
 * `.law-event-form` -- because that is what editing an event looks like on
 * this site, and a reception is an event. Same markup contract as
 * templates/account-event-form.php, so the two cannot drift into two
 * form styles.
 *
 * NO export trio, unlike its sibling dashboards: there are three receptions
 * and every figure is on screen. The BOOKINGS at a reception do export, from
 * Manage bookings and from the per-event list.
 */

// Committee-only in every branch, so nothing in front of PHP may key a copy of
// the HTML on the URL alone. Called before any output, as on the sibling
// dashboards.
nocache_headers();

get_header();

$law_rd_can    = law_user_is_committee();
$law_rd_asked  = $law_rd_can ? law_receptions_dashboard_requested() : 0;
$law_rd_notice = isset( $_GET['law_notice'] ) ? sanitize_key( wp_unslash( $_GET['law_notice'] ) ) : '';

// The three seed records, so a freshly deployed environment shows the
// receptions rather than an empty table with nothing to press. Idempotent, and
// here as well as in migration step 10 and the ?setup-account-pages trigger,
// because a deployed environment must work without either being run by hand.
if ( $law_rd_can && function_exists( 'law_reception_ensure_posts' ) ) {
	law_reception_ensure_posts();
}

$law_rd_notices = law_receptions_dashboard_notices();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>

<?php if ( $law_rd_can && $law_rd_asked ) : ?>

	<?php
	// The editor: the submission form's own hero, so editing a reception looks
	// like editing any other event.
	$law_rd_id    = 'new' === $law_rd_asked ? 0 : (int) $law_rd_asked;
	$law_rd_title = $law_rd_id ? get_the_title( $law_rd_id ) : '';
	?>
	<section class="hero auth-hero law-event-form-hero hero-solid">
		<div class="overlay"></div>
		<div class="grid-container">
			<div class="grid-x grid-padding-x">
				<div class="large-12 cell">
					<h1><?php echo esc_html( $law_rd_id ? __( 'Edit a reception', 'law' ) : __( 'Add a reception', 'law' ) ); ?></h1>
					<?php
					// The name only. The submission form prints a status beside
					// it because a host's event moves through a queue; a
					// reception does not — it is LAW's own event, and its two
					// statuses mean "on the programme" or "not yet", which is
					// the tick box below (Denis, 14 September 2026).
					?>
					<?php if ( '' !== $law_rd_title ) : ?>
						<p class="law-form-status"><?php echo esc_html( $law_rd_title ); ?></p>
					<?php endif; ?>
				</div>

				<div class="large-9 cell auth-intro">
					<?php
					get_template_part(
						'parts/layout/back-link',
						null,
						array(
							'url'   => law_receptions_dashboard_url(),
							'label' => __( 'Back to the receptions', 'law' ),
						)
					);
					?>
					<?php if ( isset( $law_rd_notices[ $law_rd_notice ] ) ) : ?>
						<div class="law-form-notice <?php echo esc_attr( $law_rd_notices[ $law_rd_notice ][0] ); ?>" role="alert">
							<?php echo esc_html( $law_rd_notices[ $law_rd_notice ][1] ); ?>
						</div>
					<?php endif; ?>

					<?php get_template_part( 'parts/events/reception-manage', null, array( 'event_id' => $law_rd_id ) ); ?>
				</div>
			</div>
		</div>
	</section>

<?php else : ?>

	<?php get_template_part( 'parts/layout/hero-title' ); ?>

	<section class="page-section">
		<div class="grid-container law-dashboard law-receptions-dashboard">

		<?php if ( ! $law_rd_can ) : ?>
			<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>

		<?php else : ?>

			<?php if ( isset( $law_rd_notices[ $law_rd_notice ] ) ) : ?>
				<p class="law-form-notice <?php echo esc_attr( $law_rd_notices[ $law_rd_notice ][0] ); ?>" role="alert">
					<?php echo esc_html( $law_rd_notices[ $law_rd_notice ][1] ); ?>
				</p>
			<?php endif; ?>

			<?php
			// The title and the button, and nothing else: the table says what
			// each reception is, and a paragraph explaining what a reception is
			// to the people who run them is furniture (Denis, 14 September
			// 2026).
			?>
			<h1 class="law-dashboard__title"><?php esc_html_e( 'Receptions', 'law' ); ?></h1>

			<p class="law-dashboard__actions">
				<a class="button orange" href="<?php echo esc_url( law_receptions_dashboard_url( 'new' ) ); ?>"><?php esc_html_e( 'Add a reception', 'law' ); ?></a>
			</p>

			<?php get_template_part( 'parts/events/receptions-list' ); ?>

		<?php endif; ?>

		</div>
	</section>

<?php endif; ?>

<?php endwhile; endif; ?>

<?php get_footer(); ?>
