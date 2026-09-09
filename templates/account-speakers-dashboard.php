<?php
/**
 * Template Name: Speakers dashboard (committee)
 *
 * The committee's Manage Speakers view (/account/dashboard/speakers/,
 * functions/events/speakers-dashboard.php): every speaker record in one
 * filterable table with CSV / Excel / PDF export, and per speaker an edit view
 * listing each event they appear at with the fields that event carries for
 * them. Restrict the page with Members, as the events dashboard is (the setup
 * helper copies the parent's restriction).
 *
 * Two views on one page, the module's established idiom: the list by default,
 * the editor at ?law_speaker=<id>.
 */

// Committee-only in every branch, so nothing in front of PHP may key a copy of
// the HTML on the URL alone. Called before any output, as on the events
// dashboard; the AJAX partial does the same check for itself.
nocache_headers();

get_header();

$law_can     = law_user_is_committee();
$law_speaker = $law_can ? law_speakers_dashboard_requested_speaker() : 0;
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-dashboard law-speakers-dashboard">

	<?php if ( ! $law_can ) : ?>
		<p><?php esc_html_e( 'This dashboard is for the LAW committee.', 'law' ); ?></p>

	<?php elseif ( $law_speaker ) : ?>
		<?php get_template_part( 'parts/events/speaker-manage', null, array( 'speaker_id' => $law_speaker ) ); ?>

	<?php else : ?>
		<?php
		// The filter bar reuses the programme page's markup, CSS and JS
		// (calendar.css, assets/js/calendar-filters.js), exactly as the events
		// and bookings dashboards do: keyword and selects over AJAX, mobile
		// modal, GET fallback. No checkbox controls: calendar-filters.js reads a
		// field's value regardless of its checked state.
		$law_filters  = law_speakers_dashboard_filters();
		$law_events   = law_speakers_dashboard_events();
		$law_years    = get_terms( array( 'taxonomy' => 'law_year', 'hide_empty' => false ) );
		$law_years    = is_wp_error( $law_years ) ? array() : $law_years;
		$law_page_url = get_permalink();
		?>
		<div class="law-cal-controls" data-law-cal-controls data-page-url="<?php echo esc_url( $law_page_url ); ?>">
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

					<form class="law-cal-filter-form" id="law-cal-filter-form" method="get" action="<?php echo esc_url( $law_page_url ); ?>">
						<p class="law-cal-filter-form__field law-cal-filter-form__field--keyword">
							<label class="show-for-sr" for="law-sd-kw"><?php esc_html_e( 'Search speakers', 'law' ); ?></label>
							<input
								type="search"
								id="law-sd-kw"
								name="law_kw"
								value="<?php echo esc_attr( $law_filters['kw'] ); ?>"
								placeholder="<?php esc_attr_e( 'Name, email or organisation', 'law' ); ?>"
								autocomplete="off"
							>
						</p>

						<p class="law-cal-filter-form__field law-cal-filter-form__field--event">
							<label class="show-for-sr" for="law-sd-event"><?php esc_html_e( 'Event', 'law' ); ?></label>
							<select id="law-sd-event" name="law_event">
								<option value=""><?php esc_html_e( 'All events', 'law' ); ?></option>
								<?php foreach ( $law_events as $law_event_id => $law_event_label ) : ?>
									<option value="<?php echo esc_attr( (string) $law_event_id ); ?>" <?php selected( $law_filters['event'], $law_event_id ); ?>><?php echo esc_html( $law_event_label ); ?></option>
								<?php endforeach; ?>
							</select>
						</p>

						<?php if ( count( $law_years ) > 1 ) : ?>
							<p class="law-cal-filter-form__field">
								<label class="show-for-sr" for="law-sd-year"><?php esc_html_e( 'Programme year', 'law' ); ?></label>
								<select id="law-sd-year" name="law_year">
									<option value=""><?php esc_html_e( 'All years', 'law' ); ?></option>
									<?php foreach ( $law_years as $law_year ) : ?>
										<option value="<?php echo esc_attr( $law_year->slug ); ?>" <?php selected( $law_filters['year'], $law_year->slug ); ?>><?php echo esc_html( $law_year->name ); ?></option>
									<?php endforeach; ?>
								</select>
							</p>
						<?php endif; ?>

						<div class="law-cal-filter-form__actions">
							<button type="submit" class="button law-cal-filter-form__apply"><?php esc_html_e( 'Apply', 'law' ); ?></button>
							<a class="button second law-cal-filter-form__clear" href="<?php echo esc_url( $law_page_url ); ?>"><?php esc_html_e( 'Clear all', 'law' ); ?></a>
						</div>
					</form>
				</div>
			</div>

			<?php
			// Export buttons (functions/events/speakers-dashboard.php). The hrefs
			// bake in the server-rendered filters as the no-JS fallback; JS
			// refreshes them with the live values (assets/js/export-buttons.js).
			$law_export_base = wp_nonce_url( admin_url( 'admin-post.php?action=law_speakers_dashboard_export' ), 'law_speakers_dashboard_export' );
			$law_export_args = array_filter(
				array(
					'law_kw'    => $law_filters['kw'],
					'law_event' => $law_filters['event'],
					'law_year'  => $law_filters['year'],
				)
			);
			?>
			<div class="law-cal-export" data-law-export data-export-url="<?php echo esc_url( $law_export_base ); ?>">
				<span class="law-cal-export__label"><?php esc_html_e( 'Export:', 'law' ); ?></span>
				<a class="button second" data-format="csv" href="<?php echo esc_url( add_query_arg( $law_export_args + array( 'format' => 'csv' ), $law_export_base ) ); ?>">CSV</a>
				<a class="button second" data-format="xlsx" href="<?php echo esc_url( add_query_arg( $law_export_args + array( 'format' => 'xlsx' ), $law_export_base ) ); ?>">Excel</a>
				<button type="button" class="button second" data-format="pdf" hidden>PDF</button>
			</div>
		</div>

		<div class="law-cal-events" id="law-cal-events" aria-live="polite" data-law-skeleton="table">
			<?php get_template_part( 'parts/events/speakers-dashboard-list' ); ?>
		</div>
	<?php endif; ?>

	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
