<?php
/**
 * Template Name: Flagship bookings (committee)
 *
 * The committee's view of who has applied to the flagship conference, what
 * they are being charged and where each one has got to
 * (functions/events/flagship-bookings-dashboard.php).
 *
 * Separate from Manage bookings on purpose: that page is hosted events, which
 * are free and instant. Restrict this page with Members, as the other
 * committee dashboards are (the setup helper copies the parent's
 * restriction).
 */

// Committee-only, so nothing in front of PHP may key a copy of the HTML on the
// URL alone. Called before any output; the AJAX partial checks for itself.
nocache_headers();

get_header();

$law_fb_can    = law_user_is_committee();
$law_fb_notice = isset( $_GET['law_notice'] ) ? sanitize_key( wp_unslash( $_GET['law_notice'] ) ) : '';
$law_fb_url    = get_permalink();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard law-flagship-bookings">

	<?php if ( ! $law_fb_can ) : ?>
		<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>
	<?php else : ?>

		<?php
		$law_fb_notices = array(
			'flagship-approved'    => array( 'is-success', __( 'The applications have been approved and the payments taken.', 'law' ) ),
			'flagship-declined'    => array( 'is-success', __( 'The applications have been declined and the applicants emailed. Their saved payment details have been removed.', 'law' ) ),
			'flagship-partly-done' => array( 'is-error', __( 'Some applications could not be decided. Check the rows below for the reason.', 'law' ) ),
			'flagship-full'        => array( 'is-error', __( 'The conference is full. Approving anyway over-books it, so please confirm.', 'law' ) ),
			'flagship-retried'     => array( 'is-success', __( 'The payment was attempted again.', 'law' ) ),
			'flagship-resent'      => array( 'is-success', __( 'The delegate has been emailed again.', 'law' ) ),
			'flagship-added'       => array( 'is-success', __( 'The attendee has a confirmed place with no charge.', 'law' ) ),
			'flagship-failed'      => array( 'is-error', __( 'That could not be done. Please check the details and try again.', 'law' ) ),
			'flagship-denied'      => array( 'is-error', __( 'Sorry, reviewing applications is for the committee.', 'law' ) ),
			'rate-limited'         => array( 'is-error', __( 'Too many actions in a short time; please wait a moment and try again.', 'law' ) ),
		);
		?>
		<?php if ( isset( $law_fb_notices[ $law_fb_notice ] ) ) : ?>
			<p class="law-form-notice <?php echo esc_attr( $law_fb_notices[ $law_fb_notice ][0] ); ?>" role="alert">
				<?php echo esc_html( $law_fb_notices[ $law_fb_notice ][1] ); ?>
			</p>
		<?php endif; ?>

		<h1 class="law-dashboard__title"><?php esc_html_e( 'Flagship bookings', 'law' ); ?></h1>

		<?php
		$law_fb_places = law_flagship_places();
		$law_fb_price  = law_flagship_price_pence();
		?>
		<p class="law-dashboard__lede">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: confirmed, 2: available, 3: price. */
					__( '%1$d of %2$s places confirmed. The current price is %3$s.', 'law' ),
					$law_fb_places['confirmed'],
					$law_fb_places['available'] > 0 ? (string) $law_fb_places['available'] : __( 'an unset number of', 'law' ),
					law_events_price_label( $law_fb_price )
				)
			);
			?>
			<?php if ( $law_fb_places['overbooked'] ) : ?>
				<strong class="law-flagship-bookings__over"><?php esc_html_e( 'This is more than the number of places available.', 'law' ); ?></strong>
			<?php endif; ?>
			<a href="<?php echo esc_url( law_flagship_dashboard_url() ); ?>"><?php esc_html_e( 'Change the places or the price', 'law' ); ?></a>.
		</p>

		<?php
		$law_fb_filters   = law_flagship_bookings_filters();
		?>
		<div class="law-cal-controls" data-law-cal-controls data-page-url="<?php echo esc_url( $law_fb_url ); ?>">
			<div class="law-cal-filterbar">
				<button type="button" class="button law-cal-filterbar__toggle" aria-expanded="false" aria-controls="law-cal-filter-panel" hidden>
					<span class="law-cal-filterbar__burger" aria-hidden="true"><span></span><span></span><span></span></span>
					<?php esc_html_e( 'Filters', 'law' ); ?>
				</button>

				<div class="law-cal-filterbar__panel" id="law-cal-filter-panel">
					<div class="law-cal-filterbar__head">
						<p class="law-cal-filterbar__title"><?php esc_html_e( 'Filters', 'law' ); ?></p>
						<button type="button" class="law-cal-filterbar__close" aria-label="<?php esc_attr_e( 'Close filters', 'law' ); ?>">&times;</button>
					</div>

					<form class="law-cal-filter-form" id="law-cal-filter-form" method="get" action="<?php echo esc_url( $law_fb_url ); ?>">
						<p class="law-cal-filter-form__field law-cal-filter-form__field--keyword">
							<label class="show-for-sr" for="law-fb-kw"><?php esc_html_e( 'Search applications', 'law' ); ?></label>
							<input type="search" id="law-fb-kw" name="law_kw" value="<?php echo esc_attr( $law_fb_filters['kw'] ); ?>"
								placeholder="<?php esc_attr_e( 'Name, email, organisation or number', 'law' ); ?>" autocomplete="off">
						</p>

						<p class="law-cal-filter-form__field">
							<label class="show-for-sr" for="law-fb-status"><?php esc_html_e( 'Status', 'law' ); ?></label>
							<select id="law-fb-status" name="law_status">
								<option value=""><?php esc_html_e( 'All statuses', 'law' ); ?></option>
								<?php foreach ( law_flagship_application_statuses() as $law_fb_key => $law_fb_label ) : ?>
									<option value="<?php echo esc_attr( $law_fb_key ); ?>" <?php selected( $law_fb_filters['status'], $law_fb_key ); ?>><?php echo esc_html( $law_fb_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="law-cal-filter-form__field">
							<label class="show-for-sr" for="law-fb-payment"><?php esc_html_e( 'Payment', 'law' ); ?></label>
							<select id="law-fb-payment" name="law_payment">
								<option value=""><?php esc_html_e( 'Any payment state', 'law' ); ?></option>
								<?php foreach ( law_flagship_payment_states() as $law_fb_key => $law_fb_label ) : ?>
									<option value="<?php echo esc_attr( $law_fb_key ); ?>" <?php selected( $law_fb_filters['payment'], $law_fb_key ); ?>><?php echo esc_html( $law_fb_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<p class="law-cal-filter-form__field">
							<label class="show-for-sr" for="law-fb-comp"><?php esc_html_e( 'Attendee type', 'law' ); ?></label>
							<?php
							// A select, not a checkbox: calendar-filters.js reads a
							// field's value regardless of its checked state, so a
							// checkbox here would filter permanently once rendered.
							?>
							<select id="law-fb-comp" name="law_comp">
								<option value=""><?php esc_html_e( 'Everyone', 'law' ); ?></option>
								<option value="1" <?php selected( ! empty( $law_fb_filters['complimentary'] ) ); ?>><?php esc_html_e( 'Complimentary places only', 'law' ); ?></option>
							</select>
						</p>

						<div class="law-cal-filter-form__actions">
							<button type="submit" class="button law-cal-filter-form__apply"><?php esc_html_e( 'Apply', 'law' ); ?></button>
							<a class="button second law-cal-filter-form__clear" href="<?php echo esc_url( $law_fb_url ); ?>"><?php esc_html_e( 'Clear all', 'law' ); ?></a>
						</div>
					</form>
				</div>
			</div>

			<?php
			$law_fb_export = wp_nonce_url( admin_url( 'admin-post.php?action=law_flagship_export' ), 'law_flagship_export' );
			$law_fb_args   = array_filter(
				array(
					'law_kw'      => $law_fb_filters['kw'],
					'law_status'  => $law_fb_filters['status'],
					'law_payment' => $law_fb_filters['payment'],
					'law_comp'    => $law_fb_filters['complimentary'] ? '1' : '',
				)
			);
			?>
			<div class="law-cal-export" data-law-export data-export-url="<?php echo esc_url( $law_fb_export ); ?>">
				<span class="law-cal-export__label"><?php esc_html_e( 'Export:', 'law' ); ?></span>
				<a class="button second" data-format="csv" href="<?php echo esc_url( add_query_arg( $law_fb_args + array( 'format' => 'csv' ), $law_fb_export ) ); ?>">CSV</a>
				<a class="button second" data-format="xlsx" href="<?php echo esc_url( add_query_arg( $law_fb_args + array( 'format' => 'xlsx' ), $law_fb_export ) ); ?>">Excel</a>
				<button type="button" class="button second" data-format="pdf" hidden>PDF</button>
			</div>
		</div>

		<?php
		// Above the list, not below it. With JavaScript this renders nothing
		// visible — the dialog is position:fixed and its opener sits in the
		// list's own actions row — but the <noscript> fallback is a real
		// disclosure, and nothing may sit under a table that can run to
		// hundreds of rows (Denis, 10 September 2026). It stays OUTSIDE
		// #law-cal-events so a filter swapping the table does not destroy
		// the dialog the opener points at.
		?>
		<?php get_template_part( 'parts/events/flagship-add-attendee' ); ?>

		<div class="law-cal-events" id="law-cal-events" aria-live="polite" data-law-skeleton="table">
			<?php get_template_part( 'parts/events/flagship-bookings-list' ); ?>
		</div>

	<?php endif; ?>

	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
