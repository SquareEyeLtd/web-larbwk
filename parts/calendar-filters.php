<?php
/**
 * Programme controls: the keyword / sector / type / organiser filters. The day
 * tabs are parts/calendar-daynav.php, which parts/calendar-body.php renders
 * after this and outside it (they are sticky, and have to share a parent with
 * the results).
 *
 * Desktop: an inline filter row applied instantly over AJAX. Mobile: the
 * filters live behind a "Filters" button that opens a modal with an Apply
 * button (assets/js/calendar-filters.js). Without JavaScript the form falls
 * back to a plain GET submit.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_filters   = law_calendar_filters();
$law_page_url  = get_permalink( get_queried_object_id() );
$law_sectors   = law_calendar_field_choices( 60 );
$law_types     = law_calendar_field_choices( 63 );
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
							<option value="external" <?php selected( $law_filters['run_by'], 'external' ); ?>><?php esc_html_e( 'External events', 'law' ); ?></option>
							<option value="host" <?php selected( $law_filters['run_by'], 'host' ); ?>><?php esc_html_e( 'Hosted events', 'law' ); ?></option>
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

</div>
