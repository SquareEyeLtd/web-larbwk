<?php
/**
 * The single event view's "Event details" box, rendered inside the hero below
 * the title (parts/layout/hero-title.php's after_title arg, captured in
 * parts/calendar-body.php).
 *
 * Why a box: the hero's seven facts used to sit as loose white text directly on
 * the background photograph, where body-size text measured roughly 1.7-3:1
 * against the 4.5:1 WCAG minimum. A solid panel supplies its own background, so
 * the contrast holds whatever pixel is behind it, without touching the photo or
 * the overlay. That surface belongs to the FACTS LIST
 * (.law-event-details__grid carries the pale ground), not to this section: the
 * availability panel below is its own filled block, the same width as that
 * list, carrying the orange rule on its top edge (Denis, 11 September 2026).
 *
 * The .law-cal class is load-bearing, not decoration: it carries the navy text
 * colour (calendar.css), the .button hover and disabled treatments the booking
 * control relies on, and the light-surface .law-form-notice colours
 * (event-form.css). The booking control
 * lives here now rather than inside the page's own .law-cal wrapper, so without
 * this class the notices would render white on a light box.
 *
 * Args:
 *   rows    (array) Each array( 'key' => 'date', 'label' => 'Date', 'value' => '…' ).
 *                   Keys with an icon: date, time, venue, host, type, sector,
 *                   price, places.
 *                   Empty values are skipped, so an event with no venue drops the
 *                   item rather than rendering a blank one.
 *                   Two optional extras:
 *                     'items' (array of array( 'label', 'url' )) renders the
 *                       value as a list of linked pills instead of one string.
 *                       Sector uses it: a comma-joined run of seven terms wrapped
 *                       to four lines and stretched the whole grid row, leaving
 *                       the facts beside it floating in a void.
 *                     'tone' ('low'|'full') marks the value as scarce, for the
 *                       flagship's Places row, which states its count here
 *                       rather than in the panel below.
 *   places  (array) array( 'label' => …, 'value' => … ). Appended as a final
 *                   grid item only when the booking control renders nothing.
 *   event   (array) The calendar-mapped event, for the booking control.
 *   preview (bool)  Committee preview: the booking control renders for an
 *                   event of any status, with its button inert.
 *   booking (bool)  Default true. False renders neither the booking control
 *                   nor the places fallback row: the flagship page
 *                   (templates/flagship-event.php) must not offer to book an
 *                   event whose application flow is a separate, approval-gated
 *                   journey (EVENTS_4.2_SPECS.md §5). Passed as an arg rather
 *                   than by withholding 'event', which would also lose the
 *                   venue row's link to the map below.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_ed_rows    = isset( $args['rows'] ) && is_array( $args['rows'] ) ? $args['rows'] : array();
$law_ed_places  = isset( $args['places'] ) && is_array( $args['places'] ) ? $args['places'] : array();
$law_ed_event   = isset( $args['event'] ) && is_array( $args['event'] ) ? $args['event'] : array();
$law_ed_preview = ! empty( $args['preview'] );
$law_ed_booking = ! array_key_exists( 'booking', $args ) || ! empty( $args['booking'] );

if ( ! $law_ed_rows ) {
	return;
}

/**
 * The icon set. The theme has no icon library, so these are hand-drawn to match
 * the one existing inline icon (law-event-card__arrow, parts/loop/event.php):
 * a 24-unit box, no fill, currentColor stroke, round caps and joins. Stroke
 * weight is 1.75 rather than that icon's 2.5, which is too heavy at 18px.
 */
$law_ed_icons = array(
	'date'   => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/>',
	'time'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
	'venue'  => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
	'host'   => '<path d="M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16"/><path d="M15 9h4a1 1 0 0 1 1 1v11"/><path d="M2 21h20"/><path d="M8 8h3M8 12h3M8 16h3"/>',
	'type'   => '<path d="M3 3h8l10 10-8 8L3 11V3Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
	'sector' => '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="M2 12l10 5 10-5"/><path d="M2 17l10 5 10-5"/>',
	'places' => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13A4 4 0 0 1 16 11"/>',
	'price'  => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
);

/**
 * One icon. aria-hidden because the adjacent <dt> already names the fact, and
 * focusable="false" because IE/Edge legacy put SVGs in the tab order.
 */
$law_ed_icon = static function ( $key ) use ( $law_ed_icons ) {
	if ( empty( $law_ed_icons[ $key ] ) ) {
		return '';
	}
	return '<svg class="law-event-details__icon" viewBox="0 0 24 24" width="18" height="18" fill="none"'
		. ' stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"'
		. ' aria-hidden="true" focusable="false">' . $law_ed_icons[ $key ] . '</svg>';
};

// The booking control (five states, functions/account-bookings.php). Buffered so
// the footer row is skipped entirely when it renders nothing, which now means
// only the legacy source: in preview mode the control renders for an event of
// any status, with an inert button, so the committee sees the row an attendee
// will see rather than a page missing it.
$law_ed_cta = '';
if ( $law_ed_booking && $law_ed_event && function_exists( 'law_booking_render_action' ) ) {
	ob_start();
	law_booking_render_action( $law_ed_event, $law_ed_preview );
	$law_ed_cta = trim( (string) ob_get_clean() );
}

// Availability: the booking control owns it whenever it renders, because it
// states the position in words in every state ("N places left", "fully booked",
// "Bookings open soon", "You're booked on this event", "This event has taken
// place"). A separate places fact alongside it would duplicate the count in the
// bookable state and, worse, advertise "100 places remaining" on an event that
// has already happened. So the fact is shown only as a fallback, when the
// control renders nothing at all, which is now just the legacy source.
$law_ed_venue = trim( (string) ( $law_ed_event['venue'] ?? '' ) );
$law_ed_venue_linked = '' !== $law_ed_venue
	&& function_exists( 'law_calendar_venue_is_mappable' )
	&& law_calendar_venue_is_mappable( $law_ed_venue );

$law_ed_places_value = trim( (string) ( $law_ed_places['value'] ?? '' ) );
if ( $law_ed_booking && '' === $law_ed_cta && '' !== $law_ed_places_value ) {
	$law_ed_rows[] = array(
		'key'   => 'places',
		'label' => (string) ( $law_ed_places['label'] ?? '' ),
		'value' => $law_ed_places_value,
	);
}
?>
<section class="law-event-details law-cal" aria-labelledby="law-event-details-heading">
	<h2 id="law-event-details-heading" class="screen-reader-text"><?php esc_html_e( 'Event details', 'law' ); ?></h2>
	<dl class="law-event-details__grid">
		<?php foreach ( $law_ed_rows as $law_ed_row ) : ?>
			<?php
			$law_ed_key   = trim( (string) ( $law_ed_row['key'] ?? '' ) );
			$law_ed_label = trim( (string) ( $law_ed_row['label'] ?? '' ) );
			$law_ed_value = trim( (string) ( $law_ed_row['value'] ?? '' ) );
			$law_ed_items = isset( $law_ed_row['items'] ) && is_array( $law_ed_row['items'] ) ? $law_ed_row['items'] : array();
			$law_ed_tone  = trim( (string) ( $law_ed_row['tone'] ?? '' ) );
			// Keyed off the joined value, not the items, so a row is empty or not
			// however it happens to render.
			if ( '' === $law_ed_value ) {
				continue;
			}
			$law_ed_classes = 'law-event-details__item';
			if ( $law_ed_key ) {
				$law_ed_classes .= ' law-event-details__item--' . $law_ed_key;
			}
			if ( '' !== $law_ed_tone ) {
				$law_ed_classes .= ' law-event-details__item--tone-' . $law_ed_tone;
			}
			?>
			<div class="<?php echo esc_attr( $law_ed_classes ); ?>">
				<dt>
					<?php
					// The icon sits inside the <dt>, not beside it: HTML5 allows a
					// <div> inside a <dl> only when its children are dt/dd, so an
					// <svg> sibling would make the list invalid.
					echo $law_ed_icon( $law_ed_key ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from the map above.
					?>
					<?php echo esc_html( $law_ed_label ); ?>
				</dt>
				<dd>
					<?php if ( $law_ed_items ) : ?>
						<?php
						// The list goes INSIDE the <dd>, not beside it, for the same
						// reason the icon sits inside the <dt>: a <div> in a <dl> may
						// only contain dt/dd.
						?>
						<ul class="law-event-details__pills">
							<?php foreach ( $law_ed_items as $law_ed_item ) : ?>
								<?php
								$law_ed_item_label = trim( (string) ( $law_ed_item['label'] ?? '' ) );
								$law_ed_item_url   = trim( (string) ( $law_ed_item['url'] ?? '' ) );
								if ( '' === $law_ed_item_label ) {
									continue;
								}
								?>
								<li>
									<?php if ( '' !== $law_ed_item_url ) : ?>
										<a class="law-event-details__pill" href="<?php echo esc_url( $law_ed_item_url ); ?>"><?php echo esc_html( $law_ed_item_label ); ?></a>
									<?php else : ?>
										<span class="law-event-details__pill"><?php echo esc_html( $law_ed_item_label ); ?></span>
									<?php endif; ?>
								</li>
							<?php endforeach; ?>
						</ul>
					<?php elseif ( 'venue' === $law_ed_key && $law_ed_venue_linked ) : ?>
						<?php
						// The Venue section further down the page repeats this address
						// with a map, so link to it and the repetition reads as
						// navigation. Only when the address is mappable: linking a
						// "TBC" to a section that also just says "TBC" is noise.
						?>
						<a class="law-event-details__link" href="#law-cal-venue-heading"><?php echo esc_html( $law_ed_value ); ?></a>
					<?php else : ?>
						<?php echo esc_html( $law_ed_value ); ?>
					<?php endif; ?>
				</dd>
			</div>
		<?php endforeach; ?>
	</dl>
	<?php if ( '' !== $law_ed_cta ) : ?>
		<div class="law-event-details__footer law-cal-detail__actions">
			<?php echo $law_ed_cta; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built and escaped by law_booking_render_action(). ?>
		</div>
	<?php endif; ?>
</section>
