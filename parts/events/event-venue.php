<?php
/**
 * An event's venue: the address, and the Google map when the address is a real
 * place (parts/calendar-body.php).
 *
 * Extracted 9 September 2026 so the section can render in either of two
 * positions without the markup existing twice. The ordinary single event view
 * puts it above the sessions; the flagship page puts it below the agenda,
 * because on a day-long conference the running order is what the reader came
 * for and the venue is a detail they need once.
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
