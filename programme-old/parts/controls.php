<?php
/**
 * The ORIGINAL programme controls, kept for reference behind ?variant=old
 * (programme-old/README.md): the day links as a grid of jump buttons above
 * the keyword / sector / type / organiser filters, exactly as the programme
 * had them before the day-tabs layout became the default. A copy of
 * parts/calendar-filters.php as it was, plus a hidden `variant` field so the
 * AJAX fetch, the URL it writes and the no-JS submit keep the old layout
 * (data-law-keep exempts it from "Clear all").
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_days      = law_calendar_week_days();
$law_by_date   = law_calendar_events_by_date();
$law_filters   = law_calendar_filters();
$law_page_url  = get_permalink( get_queried_object_id() );
$law_sectors   = law_calendar_field_choices( 60 );
$law_types     = law_calendar_field_choices( 63 );
?>
<div class="law-cal-controls" data-law-cal-controls data-page-url="<?php echo esc_url( $law_page_url ); ?>">

	<nav class="law-cal-daynav" aria-label="<?php esc_attr_e( 'Jump to a day', 'law' ); ?>">
		<?php foreach ( $law_days as $law_date => $law_heading ) : ?>
			<?php
			// Shared with parts/calendar-events.php: the flagship's day is never
			// empty while it is published, however the list is filtered.
			$law_day_empty = function_exists( 'law_calendar_day_is_empty' )
				? law_calendar_day_is_empty( $law_date )
				: empty( $law_by_date[ $law_date ] );
			?>
			<a
				class="law-cal-daynav__link<?php echo $law_day_empty ? ' is-empty' : ''; ?>"
				href="#day-<?php echo esc_attr( $law_date ); ?>"
				data-day="<?php echo esc_attr( $law_date ); ?>"
				<?php echo $law_day_empty ? 'aria-disabled="true" tabindex="-1"' : ''; ?>
			><?php echo esc_html( law_calendar_day_nav_label( $law_date ) ); ?></a>
		<?php endforeach; ?>
	</nav>

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
					<label class="show-for-sr" for="law-cal-kw"><?php esc_html_e( 'Keyword', 'law' ); ?></label>
					<input
						type="search"
						id="law-cal-kw"
						name="law_kw"
						value="<?php echo esc_attr( $law_filters['kw'] ); ?>"
						placeholder="<?php esc_attr_e( 'Enter a keyword', 'law' ); ?>"
						autocomplete="off"
					>
				</p>

				<p class="law-cal-filter-form__field">
					<label class="show-for-sr" for="law-cal-sector"><?php esc_html_e( 'Sector', 'law' ); ?></label>
					<select id="law-cal-sector" name="law_sector">
						<option value=""><?php esc_html_e( 'Sector', 'law' ); ?></option>
						<?php foreach ( $law_sectors as $law_choice ) : ?>
							<option value="<?php echo esc_attr( $law_choice ); ?>" <?php selected( law_calendar_normalise_choice( $law_filters['sector'] ), law_calendar_normalise_choice( $law_choice ) ); ?>><?php echo esc_html( $law_choice ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<p class="law-cal-filter-form__field">
					<label class="show-for-sr" for="law-cal-type"><?php esc_html_e( 'Type', 'law' ); ?></label>
					<select id="law-cal-type" name="law_type">
						<option value=""><?php esc_html_e( 'Type', 'law' ); ?></option>
						<?php foreach ( $law_types as $law_choice ) : ?>
							<option value="<?php echo esc_attr( $law_choice ); ?>" <?php selected( law_calendar_normalise_choice( $law_filters['type'] ), law_calendar_normalise_choice( $law_choice ) ); ?>><?php echo esc_html( $law_choice ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>

				<?php if ( 'cpt' === law_events_source() ) : ?>
					<?php
					// Drawn whether or not anything is flagged yet. "LAW events" can
					// return no cards, but never an empty page: the flagship block is
					// pinned to its day outside the filtered list (Denis, 11 September
					// 2026). Only the legacy Gravity Forms source hides it, where the
					// switch is post meta with no entry field behind it to read.
					//
					// A select, not a tick box, and not by taste: calendar-filters.js
					// reads field.value for every named field with no `checked` test,
					// so a checkbox would contribute law_run_by=1 permanently from the
					// moment it rendered, and Clear all blanks values rather than
					// unchecking. The committee dashboard's filter says the same.
					//
					// "Hosted" is the 4.2 spec's own word for an event run by an
					// external host (§2, §6); there is no "hosted" switch in the data,
					// only the absence of the LAW one.
					?>
					<p class="law-cal-filter-form__field">
						<label class="show-for-sr" for="law-cal-run-by"><?php esc_html_e( 'Organiser', 'law' ); ?></label>
						<select id="law-cal-run-by" name="law_run_by">
							<option value=""><?php esc_html_e( 'Organiser', 'law' ); ?></option>
							<option value="law" <?php selected( $law_filters['run_by'], 'law' ); ?>><?php esc_html_e( 'LAW events', 'law' ); ?></option>
							<option value="host" <?php selected( $law_filters['run_by'], 'host' ); ?>><?php esc_html_e( 'Hosted events', 'law' ); ?></option>
						</select>
					</p>
				<?php endif; ?>

				<input type="hidden" name="variant" value="old" data-law-keep>

				<div class="law-cal-filter-form__actions">
					<button type="submit" class="button law-cal-filter-form__apply"><?php esc_html_e( 'Apply', 'law' ); ?></button>
					<a class="button second law-cal-filter-form__clear" href="<?php echo esc_url( add_query_arg( 'variant', 'old', $law_page_url ) ); ?>"><?php esc_html_e( 'Clear all', 'law' ); ?></a>
				</div>
			</form>
		</div>
	</div>

</div>
