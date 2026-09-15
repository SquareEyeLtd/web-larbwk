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
	$law_axis    = law_slotchart_axis( $law_items );
	$law_lanes   = law_slotchart_lanes( $law_items );
	$law_density = law_slotchart_density( $law_items, $law_axis );
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
				style="--law-sc-from:<?php echo (int) $law_axis['from']; ?>;--law-sc-span:<?php echo (int) $law_axis['span']; ?>"
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

					<?php
					// The running total the client asked for, and the only place
					// on this view that states a number: the bars show WHERE the
					// clashes are without ever saying how many. One figure per
					// half hour, above the bars it counts. Nothing opens.
					//
					// The day's own busiest half hour is marked, and nothing
					// else is. A fixed threshold would be a judgement invented
					// here -- during this week two events at once is ordinary,
					// so "more than one" would light up almost every step and
					// mean nothing -- while the peak is a fact about the day.
					$law_peak = $law_density ? max( $law_density ) : 0;
					?>
					<p class="show-for-sr"><?php esc_html_e( 'Events running in each half hour:', 'law' ); ?></p>
					<div class="law-slotchart__density">
						<?php foreach ( $law_density as $law_at => $law_count ) : ?>
							<?php
							// The opening step is the ruler's unlabelled lead-in,
							// so its figure would float with no time above it.
							if ( $law_at === $law_axis['from'] ) {
								continue;
							}
							?>
							<span
								class="law-slotchart__count<?php echo ( $law_count > 0 && $law_count === $law_peak ) ? ' is-peak' : ''; ?>"
								style="--at:<?php echo (int) ( $law_at - $law_axis['from'] ); ?>"
							><abbr title="<?php
								/* translators: 1: number of events, 2: start of the half hour, e.g. 08:30 */
								echo esc_attr( sprintf( _n( '%1$d event running at %2$s', '%1$d events running at %2$s', $law_count, 'law' ), $law_count, law_slotchart_time_label( $law_at ) ) );
							?>"><?php echo esc_html( (string) $law_count ); ?></abbr></span>
						<?php endforeach; ?>
					</div>

					<ol class="law-slotchart__lanes">
						<?php foreach ( $law_lanes as $law_lane ) : ?>
							<li class="law-slotchart__lane">
								<?php foreach ( $law_lane as $law_item ) : ?>
									<?php $law_item_label = law_slotchart_item_label( $law_item ); ?>
									<a
										class="law-slotchart__bar law-slotchart__bar--<?php echo esc_attr( $law_item['status_slug'] ); ?> law-slotchart__bar--kind-<?php echo esc_attr( $law_item['kind'] ); ?><?php echo $law_item['open_ended'] ? ' is-open-ended' : ''; ?>"
										style="--at:<?php echo (int) ( $law_item['start'] - $law_axis['from'] ); ?>;--len:<?php echo (int) max( 1, $law_item['end'] - $law_item['start'] ); ?>"
										href="<?php echo esc_url( $law_item['url'] ); ?>"
										title="<?php echo esc_attr( $law_item_label ); ?>"
										aria-label="<?php echo esc_attr( $law_item_label ); ?>"
									>
										<span class="law-slotchart__bar-time" aria-hidden="true"><?php echo esc_html( $law_item['start_label'] ); ?></span>
										<span class="law-slotchart__bar-title"><?php echo esc_html( $law_item['title'] ); ?></span>
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
	// No start time, so no place on any day's axis. A list rather than a chart,
	// under every day tab, exactly as the programme handles the same case
	// (parts/calendar-events.php). Dropping these would quietly hide an event
	// from the one view whose job is to account for all of them.
	?>
	<section class="law-cal-day-section law-slotchart-unscheduled" id="day-unscheduled" aria-label="<?php esc_attr_e( 'No confirmed slot', 'law' ); ?>">
		<h2 class="law-cal-day-bar"><?php esc_html_e( 'No confirmed slot', 'law' ); ?></h2>
		<ul class="law-slotchart__unscheduled-list">
			<?php foreach ( $law_unscheduled as $law_item ) : ?>
				<li>
					<a class="law-slotchart__unscheduled-link" href="<?php echo esc_url( $law_item['url'] ); ?>"><?php echo esc_html( $law_item['title'] ); ?></a>
					<span class="law-cal-card__badge law-cal-card__badge--<?php echo esc_attr( $law_item['status_slug'] ); ?>"><?php echo esc_html( $law_item['status_label'] ); ?></span>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endif; ?>
