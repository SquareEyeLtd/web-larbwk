<?php
/**
 * The flagship conference's one-line strip at the top of the programme list.
 *
 * Above the day sections, so the main event of the week is in the first
 * screenful whatever day the tabs are showing. A signpost to the block, not a
 * second copy of it: the main link jumps to the flagship's day (calendar-tabs.js
 * switches to that tab; without JavaScript it is a plain anchor) and the block
 * itself (parts/events/flagship-card.php) still renders under that day, exactly
 * once. Hence no class name in common with the block: FlagshipRenderTest counts
 * `class="law-flagship-card"`. On a phone the line becomes a short stack, the
 * tag above the title.
 *
 * get_template_part( 'parts/events/flagship-strip', null, array(
 *   'event' => <hydrated calendar event array>, // required
 * ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_fs_event = isset( $args['event'] ) && is_array( $args['event'] ) ? $args['event'] : array();
if ( empty( $law_fs_event['id'] ) || '' === trim( (string) ( $law_fs_event['title'] ?? '' ) ) ) {
	return;
}

$law_fs_date  = (string) ( $law_fs_event['date'] ?? '' );
$law_fs_url   = (string) ( $law_fs_event['url'] ?? '' );
$law_fs_venue = trim( (string) ( $law_fs_event['venue'] ?? '' ) );
$law_fs_time  = law_calendar_event_time_label( $law_fs_event );

// "Wednesday 2 December", the week's own day label, rather than the section
// heading's "Wednesday, 2 December 2026": the year is noise in a one-liner.
$law_fs_days     = law_calendar_week_days();
$law_fs_day_text = '' !== $law_fs_date
	? (string) ( $law_fs_days[ $law_fs_date ] ?? law_calendar_day_heading( $law_fs_date ) )
	: '';

$law_fs_meta = array_filter( array( $law_fs_day_text, $law_fs_time, $law_fs_venue ), 'strlen' );

// The jump target is the day section. With no date (it cannot happen:
// law_flagship_date() never returns empty) the link falls back to the event
// page rather than being a dead anchor.
$law_fs_jump = '' !== $law_fs_date ? '#day-' . $law_fs_date : $law_fs_url;
?>
<div class="law-flagship-strip" data-day="<?php echo esc_attr( $law_fs_date ); ?>">
	<span class="law-flagship-strip__badge"><?php esc_html_e( 'Flagship event', 'law' ); ?></span>
	<a class="law-flagship-strip__link" href="<?php echo esc_url( $law_fs_jump ); ?>" data-day="<?php echo esc_attr( $law_fs_date ); ?>">
		<span class="law-flagship-strip__title"><?php echo esc_html( $law_fs_event['title'] ); ?></span>
		<?php if ( $law_fs_meta ) : ?>
			<span class="law-flagship-strip__meta"><?php echo esc_html( implode( ' · ', $law_fs_meta ) ); ?></span>
		<?php endif; ?>
	</a>
	<?php if ( '' !== $law_fs_url ) : ?>
		<a class="law-flagship-strip__cta" href="<?php echo esc_url( $law_fs_url ); ?>">
			<?php esc_html_e( 'Event details', 'law' ); ?>
			<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M5 12h14"/><path d="m13 6 6 6-6 6"/></svg>
		</a>
	<?php endif; ?>
</div>
