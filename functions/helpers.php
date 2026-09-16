<?php
	
// Helper: theme asset URL
function law_asset( $path ) {
    return get_template_directory_uri() . '/' . ltrim( $path, '/' );
}

/**
 * The site's default hero photograph.
 *
 * One definition, because two things now need the same picture: the hero
 * partial's own default, and the flagship's programme block, which falls back
 * to it when no banner image has been chosen. The two must not drift, or the
 * block and the page it links to would show different photographs.
 */
function law_hero_default_image_url() {
	return law_asset( 'assets/images/patrons-and-committee-bg.jpg' );
}

/**
 * The theme's inline icon set: key => the SVG's inner markup.
 *
 * The theme has no icon library. Font Awesome's kit is loaded in header.php but
 * nothing uses it, and it is not used here either: a kit script is a network
 * request and a font for glyphs that are three paths each. These are hand-drawn
 * to one convention, matching the arrow in parts/loop/event.php: a 24-unit box,
 * no fill, currentColor stroke, round caps and joins.
 *
 * The first eight were local to parts/calendar-event-details.php until the
 * account hub needed icons too (14 September 2026). One table rather than two,
 * so a glyph cannot end up drawn twice and differently.
 */
function law_icon_paths() {
	return array(
		// The event details box.
		'date'       => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 2v4M16 2v4M3 10h18"/>',
		'time'       => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>',
		'venue'      => '<path d="M20 10c0 6-8 12-8 12S4 16 4 10a8 8 0 1 1 16 0Z"/><circle cx="12" cy="10" r="3"/>',
		'host'       => '<path d="M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16"/><path d="M15 9h4a1 1 0 0 1 1 1v11"/><path d="M2 21h20"/><path d="M8 8h3M8 12h3M8 16h3"/>',
		'type'       => '<path d="M3 3h8l10 10-8 8L3 11V3Z"/><circle cx="7.5" cy="7.5" r="1.5"/>',
		'sector'     => '<path d="M12 2 2 7l10 5 10-5-10-5Z"/><path d="M2 12l10 5 10-5"/><path d="M2 17l10 5 10-5"/>',
		'places'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13A4 4 0 0 1 16 11"/>',
		'price'      => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6 12h.01M18 12h.01"/>',
		// The account hub's tiles (law_header_nav()).
		'user'       => '<circle cx="12" cy="8" r="4"/><path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"/>',
		'ticket'     => '<path d="M3 8a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a2 2 0 0 0 0 4v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a2 2 0 0 0 0-4V8Z"/><path d="M14 7v1M14 11.5v1M14 16v1"/>',
		'plus'       => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 8v8M8 12h8"/>',
		'clipboard'  => '<rect x="5" y="4" width="14" height="17" rx="2"/><path d="M9 4V2h6v2"/><path d="m9 13 2 2 4-4"/>',
		'microphone' => '<rect x="9" y="2" width="6" height="12" rx="3"/><path d="M5 11a7 7 0 0 0 14 0"/><path d="M12 18v3M8 21h8"/>',
		'flag'       => '<path d="M5 21V4"/><path d="M5 4h11l-1.5 3.5L16 11H5"/>',
		// A raised glass: the drinks receptions (RECEPTIONS.md §0.4).
		'receptions' => '<path d="M4 4h16l-8 9-8-9Z"/><path d="M12 13v7"/><path d="M8 21h8"/>',
		// An envelope: the committee's Manage emails screen.
		'email'      => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
		'signout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5"/><path d="M21 12H9"/>',
		// The north-east arrow that says "this link leaves the site". It was
		// inline in parts/loop/event.php until external events needed the same
		// glyph on the single-event panel button (15 September 2026); this table
		// exists precisely so it is not drawn twice and differently.
		'external'   => '<path d="M14 3h7v7"/><path d="M10 14 21 3"/>',
		// The committee's inline "edit this" affordance, first used by the
		// Ticket type cell on the flagship bookings table (15 September 2026).
		'pencil'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
	);
}

/**
 * One icon, as a complete <svg> string. Returns '' for an unknown key, so a
 * caller can pass an item's icon field straight through without guarding.
 *
 * aria-hidden because every caller puts a real label beside the glyph, and
 * focusable="false" because legacy Edge put SVGs in the tab order.
 *
 * @param string $key    A law_icon_paths() key.
 * @param string $class  Class attribute for the <svg>.
 * @param int    $size   Width and height in pixels.
 * @param float  $stroke Stroke width. 1.75 reads well from about 18px up; the
 *                       heavier 2.5 of the card arrow is too dense at that size.
 */
function law_icon( $key, $class = '', $size = 24, $stroke = 1.75 ) {
	$paths = law_icon_paths();
	if ( empty( $paths[ $key ] ) ) {
		return '';
	}
	return sprintf(
		'<svg%s viewBox="0 0 24 24" width="%d" height="%d" fill="none" stroke="currentColor"'
			. ' stroke-width="%s" stroke-linecap="round" stroke-linejoin="round"'
			. ' aria-hidden="true" focusable="false">%s</svg>',
		$class ? ' class="' . esc_attr( $class ) . '"' : '',
		(int) $size,
		(int) $size,
		esc_attr( (string) $stroke ),
		$paths[ $key ]
	);
}
