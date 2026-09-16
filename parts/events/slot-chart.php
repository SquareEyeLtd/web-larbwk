<?php
/**
 * The committee's timeline view: the day sections for
 * templates/account-dashboard.php, and the markup returned on its own by the
 * &law_partial=1 AJAX endpoint (functions/events/committee.php), so filtering
 * swaps the chart in place exactly as it swaps the table.
 *
 * The geometry is functions/events/slot-chart.php. This file only draws it.
 *
 * Two contracts hold this together and neither is obvious from the markup:
 *
 * 1. One <section class="law-cal-day-section" id="day-YYYY-MM-DD" data-count>
 *    per day is the WHOLE of the day-tabs reuse. assets/js/calendar-tabs.js
 *    selects exactly that, reads data-count for the tab labels, keeps
 *    #day-unscheduled out of the tabs and under every one of them, and
 *    re-enhances itself on the law:partial-rendered event. Matching the
 *    programme's contract is why that file needs no change at all.
 *
 * 2. Position is a CSS custom property in minutes, not a percentage and not a
 *    grid column. The ruler ticks every 30 minutes but real events do not start
 *    on the half hour -- 17:25 and 19:45 are both live data -- so the bar
 *    carries --at and --len in minutes and the stylesheet multiplies by the
 *    per-minute scale. Change the zoom by changing one number in the CSS.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_grouped     = law_slotchart_days( law_slotchart_items() );
$law_days        = $law_grouped['days'];
$law_unscheduled = $law_grouped['unscheduled'];
$law_week        = law_calendar_week_days();

// Search hits on the bars, the same mark the table below and the programme's
// cards use (law_calendar_highlight(), assets/css/app.css). The bar's title is
// the one searched field it prints; the tooltip and the aria-label are built
// elsewhere and stay plain text, because a <mark> inside an attribute would be
// read out as its own characters.
$law_hl = sanitize_text_field( wp_unslash( $_GET['law_kw'] ?? '' ) );

$law_total = count( $law_unscheduled );
foreach ( $law_days as $law_day_items ) {
	$law_total += count( $law_day_items );
}
?>

<?php if ( ! $law_total ) : ?>
	<p class="law-cal__empty"><?php esc_html_e( 'No events match. Try clearing a filter.', 'law' ); ?></p>
<?php endif; ?>

<?php foreach ( $law_days as $law_date => $law_items ) : ?>
	<?php
	$law_axis  = law_slotchart_axis( $law_items );
	$law_lanes = law_slotchart_lanes( $law_items );
	// How tall a bar has to be is how many detail lines the day's fullest item
	// carries. The pixel arithmetic is in the stylesheet, where the type metrics
	// it depends on live; this only supplies the count. Per day rather than once
	// for the chart, because a day whose events have no waiting list and no
	// session agenda is a line shorter throughout, and padding it out to the
	// worst day's height puts dead space under every bar on it.
	$law_fact_lines = 0;
	foreach ( $law_items as $law_day_item ) {
		$law_fact_lines = max( $law_fact_lines, count( law_slotchart_item_facts( $law_day_item ) ) );
	}
	// A day outside the configured programme week has no heading in
	// law_calendar_week_days(); law_calendar_day_heading() formats the date
	// itself, so the section is labelled rather than left with a bare key.
	$law_heading = $law_week[ $law_date ] ?? law_calendar_day_heading( $law_date );
	?>
	<section
		class="law-cal-day-section law-slotchart-day"
		id="day-<?php echo esc_attr( $law_date ); ?>"
		data-count="<?php echo (int) count( $law_items ); ?>"
		aria-label="<?php echo esc_attr( $law_heading ); ?>"
	>
		<h2 class="law-cal-day-bar"><?php echo esc_html( $law_heading ); ?></h2>

		<?php if ( ! $law_items ) : ?>
			<?php // Not an error: an empty day on a planning view is free capacity, and the committee is looking for it. ?>
			<p class="law-cal__empty"><?php esc_html_e( 'Nothing scheduled on this day.', 'law' ); ?></p>
		<?php else : ?>
			<div
				class="law-slotchart"
				style="--law-sc-from:<?php echo (int) $law_axis['from']; ?>;--law-sc-span:<?php echo (int) $law_axis['span']; ?>;--law-sc-facts:<?php echo (int) $law_fact_lines; ?>"
			>
				<div class="law-slotchart__track">
					<?php
					// From the SECOND step, not the first. A label is centred on
					// the moment it names, so the opening one would be sliced in
					// half by the scroller's left edge; law_slotchart_axis()
					// opens half an hour early so that this one can be dropped
					// and the first real time still reads in full.
					?>
					<div class="law-slotchart__ruler" aria-hidden="true">
						<?php for ( $law_at = $law_axis['from'] + LAW_SLOTCHART_STEP; $law_at <= $law_axis['to']; $law_at += LAW_SLOTCHART_STEP ) : ?>
							<span
								class="law-slotchart__tick<?php echo 0 === $law_at % 60 ? ' is-hour' : ''; ?>"
								style="--at:<?php echo (int) ( $law_at - $law_axis['from'] ); ?>"
							><?php echo esc_html( law_slotchart_time_label( $law_at ) ); ?></span>
						<?php endfor; ?>
					</div>

					<ol class="law-slotchart__lanes">
						<?php foreach ( $law_lanes as $law_lane ) : ?>
							<li class="law-slotchart__lane">
								<?php foreach ( $law_lane as $law_item ) : ?>
									<?php
									$law_item_label = law_slotchart_item_label( $law_item );
									$law_item_facts = law_slotchart_item_facts( $law_item );
									?>
									<?php
									// aria-hidden on everything inside, with the whole
									// bar named by aria-label. The visible lines are the
									// same facts in the same order, so announcing both
									// would read every event twice; and the label is the
									// only complete version when the bar is too narrow
									// to draw the lower lines.
									?>
									<a
										class="law-slotchart__bar law-slotchart__bar--<?php echo esc_attr( $law_item['status_slug'] ); ?> law-slotchart__bar--kind-<?php echo esc_attr( $law_item['kind'] ); ?><?php echo $law_item['open_ended'] ? ' is-open-ended' : ''; ?>"
										style="--at:<?php echo (int) ( $law_item['start'] - $law_axis['from'] ); ?>;--len:<?php echo (int) max( 1, $law_item['end'] - $law_item['start'] ); ?>"
										href="<?php echo esc_url( $law_item['url'] ); ?>"
										title="<?php echo esc_attr( $law_item_label ); ?>"
										aria-label="<?php echo esc_attr( $law_item_label ); ?>"
									>
										<?php
										// No time on the bar. Its position and its
										// length are the time, the ruler above names it,
										// and on a 48-bar day the repeated "08:30–10:00"
										// was a line of type per bar saying what the
										// chart already said (Denis, 15 September 2026).
										// The tooltip and the label still carry it.
										?>
										<span class="law-slotchart__bar-title" aria-hidden="true"><?php echo law_calendar_highlight( $law_item['title'], $law_hl ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
										<?php if ( $law_item_facts ) : ?>
											<span class="law-slotchart__bar-facts" aria-hidden="true">
												<?php foreach ( $law_item_facts as $law_fact ) : ?>
													<span class="law-slotchart__bar-fact law-slotchart__bar-fact--<?php echo esc_attr( $law_fact['key'] ); ?>"><?php echo esc_html( $law_fact['text'] ); ?></span>
												<?php endforeach; ?>
											</span>
										<?php endif; ?>
									</a>
								<?php endforeach; ?>
							</li>
						<?php endforeach; ?>
					</ol>
				</div>
			</div>
		<?php endif; ?>
	</section>
<?php endforeach; ?>

<?php if ( ! empty( $law_unscheduled ) ) : ?>
	<?php
	// No start time, so no place on any day's axis. Under every day tab, exactly
	// as the programme handles the same case (parts/calendar-events.php).
	// Dropping these would quietly hide an event from the one view whose job is
	// to account for all of them.
	//
	// It is the list view's own table, not a list written for this section
	// (Denis, 15 September 2026). These events have no geometry to draw, so the
	// chart has nothing to offer them that the table does not already do better,
	// and a second layout for the same rows was one more thing to keep in step.
	// Review is kept here although the bars do without it: there is no bar to
	// click, so without the button the row's title link would be the only way in
	// and would not look like one. Bookings stays off, as everywhere on this
	// view. See the args' documentation in parts/events/dashboard-list.php.
	$law_unscheduled_posts = array_filter( array_map( 'get_post', wp_list_pluck( $law_unscheduled, 'id' ) ) );
	?>
	<section class="law-cal-day-section law-slotchart-unscheduled" id="day-unscheduled" aria-label="<?php esc_attr_e( 'No confirmed slot', 'law' ); ?>">
		<h2 class="law-cal-day-bar"><?php esc_html_e( 'No confirmed slot', 'law' ); ?></h2>
		<?php
		get_template_part(
			'parts/events/dashboard-list',
			null,
			array(
				'events'        => $law_unscheduled_posts,
				'show_count'    => false,
				'show_bookings' => false,
				'link_base'     => law_slotchart_url(),
			)
		);
		?>
	</section>
<?php endif; ?>
