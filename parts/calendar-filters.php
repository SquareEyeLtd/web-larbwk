<?php
/**
 * Programme controls: day jump links and the keyword / sector / type filters.
 *
 * Desktop: an inline filter row applied instantly over AJAX. Mobile: the day
 * links wrap to two columns and the filters live behind a "Filters" button
 * that opens a modal with an Apply button (assets/js/calendar-filters.js).
 * Without JavaScript the form falls back to a plain GET submit.
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
			<?php $law_day_empty = empty( $law_by_date[ $law_date ] ); ?>
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

				<div class="law-cal-filter-form__actions">
					<button type="submit" class="button law-cal-filter-form__apply"><?php esc_html_e( 'Apply', 'law' ); ?></button>
					<a class="button second law-cal-filter-form__clear" href="<?php echo esc_url( $law_page_url ); ?>"><?php esc_html_e( 'Clear all', 'law' ); ?></a>
				</div>
			</form>
		</div>
	</div>

</div>
