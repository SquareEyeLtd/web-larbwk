<?php
/**
 * An event's venue: the address, and the Google map when the address is a real
 * place (parts/calendar-body.php).
 *
 * Extracted 9 September 2026 so the section could render in either of two
 * positions without the markup existing twice, which is still what it is for.
 * On a hosted event it sits at the foot of the reading column, below the
 * sessions: the running order is what the reader came for and the address is a
 * detail they need once. On the flagship it moves to the sidebar, beside a
 * description that would otherwise have half a row to itself, that page's
 * agenda being a panel below both columns (parts/calendar-body.php, 15
 * September 2026).
 *
 * get_template_part( 'parts/events/event-venue', null, array(
 *   'venue' => $event['venue'], // required; nothing renders when empty
 * ) );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_venue = trim( (string) ( $args['venue'] ?? '' ) );
if ( '' === $law_venue ) {
	return;
}

// "TBC" and its variants are deliberately not places: law_calendar_venue_is_mappable()
// keeps the address line but drops the map, rather than mapping the middle of
// London and pretending that is the venue.
$law_venue_maps_url  = law_calendar_maps_url( $law_venue );
$law_venue_embed_url = law_calendar_maps_embed_url( $law_venue );
$law_venue_show_map  = law_calendar_venue_is_mappable( $law_venue ) && $law_venue_embed_url;
?>
<section class="law-cal-venue" aria-labelledby="law-cal-venue-heading">
	<h2 id="law-cal-venue-heading" class="law-cal-acc__heading"><?php esc_html_e( 'Venue', 'law' ); ?></h2>
	<p class="law-cal-venue__address"><?php echo esc_html( $law_venue ); ?></p>
	<?php if ( $law_venue_show_map ) : ?>
		<div class="law-cal-venue__map">
			<iframe
				title="<?php echo esc_attr( sprintf( __( 'Map of %s', 'law' ), $law_venue ) ); ?>"
				src="<?php echo esc_url( $law_venue_embed_url ); ?>"
				loading="lazy"
				referrerpolicy="no-referrer-when-downgrade"
				allowfullscreen
			></iframe>
		</div>
		<?php if ( $law_venue_maps_url ) : ?>
			<p class="law-cal-venue__open">
				<a href="<?php echo esc_url( $law_venue_maps_url ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Open in Google Maps', 'law' ); ?></a>
			</p>
		<?php endif; ?>
	<?php endif; ?>
</section>
