<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_CALENDAR_FORM_ID = 2;
const LAW_CALENDAR_YEAR    = 2026;

/**
 * Programme week, Monday–Friday. Empty days stay visible in list and day views.
 */
function law_calendar_week_days() {
	return array(
		'2026-11-30' => 'Monday 30 November',
		'2026-12-01' => 'Tuesday 1 December',
		'2026-12-02' => 'Wednesday 2 December',
		'2026-12-03' => 'Thursday 3 December',
		'2026-12-04' => 'Friday 4 December',
	);
}

/**
 * Statuses shown on the public programme.
 */
function law_calendar_public_statuses() {
	return array( 'Confirmed' );
}

function law_calendar_is_committee() {
	return is_page_template( 'templates/calendar-committee.php' );
}

function law_calendar_is_calendar_page() {
	return is_page_template( 'templates/calendar.php' ) || law_calendar_is_committee();
}

function law_calendar_context() {
	return law_calendar_is_committee() ? 'committee' : 'public';
}

function law_calendar_status_slug( $status ) {
	$slug = strtolower( (string) $status );
	$slug = str_replace( array( ' ', '_' ), '-', $slug );
	return sanitize_html_class( $slug );
}

/**
 * Status pill for committee views. Public calendar does not call this.
 *
 * @param array $event Mapped calendar event.
 */
function law_calendar_status_badge( $event ) {
	$status = (string) ( $event['status'] ?? '' );
	if ( '' === $status ) {
		return;
	}
	printf(
		'<span class="law-cal-card__badge law-cal-card__badge--%s">%s</span>',
		esc_attr( law_calendar_status_slug( $status ) ),
		esc_html( $status )
	);
}

/**
 * Programme filters from the query string. Fully theme-owned: the calendar
 * queries entries with GFAPI and filters mapped events in PHP, with no
 * GravityView involvement.
 *
 * @return array{kw:string,sector:string,type:string}
 */
function law_calendar_filters() {
	static $filters = null;
	if ( null !== $filters ) {
		return $filters;
	}

	$filters = array();
	foreach ( array( 'kw' => 'law_kw', 'sector' => 'law_sector', 'type' => 'law_type' ) as $key => $param ) {
		$filters[ $key ] = isset( $_GET[ $param ] )
			? trim( sanitize_text_field( wp_unslash( $_GET[ $param ] ) ) )
			: '';
	}

	return $filters;
}

/**
 * Active filters as query args, so links (e.g. back from an event) keep them.
 */
function law_calendar_search_query_args() {
	$args = array();
	$map  = array( 'kw' => 'law_kw', 'sector' => 'law_sector', 'type' => 'law_type' );
	foreach ( law_calendar_filters() as $key => $value ) {
		if ( '' !== $value ) {
			$args[ $map[ $key ] ] = $value;
		}
	}
	return $args;
}

function law_calendar_is_searching() {
	return ! empty( law_calendar_search_query_args() );
}

/**
 * Case-insensitive, entity-insensitive comparison key for choice values.
 * Form choices store "&amp;" while typed filters arrive as "&".
 */
function law_calendar_normalise_choice( $value ) {
	$value = html_entity_decode( (string) $value, ENT_QUOTES, 'UTF-8' );
	$value = preg_replace( '/\s+/u', ' ', $value );
	return function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $value ) ) : strtolower( trim( $value ) );
}

/**
 * True when a mapped event passes the active keyword / sector / type filters.
 *
 * @param array $event   Mapped calendar event.
 * @param array $filters law_calendar_filters() result.
 */
function law_calendar_event_matches_filters( $event, $filters ) {
	if ( '' !== $filters['type'] && law_calendar_normalise_choice( $event['type'] ) !== law_calendar_normalise_choice( $filters['type'] ) ) {
		return false;
	}

	if ( '' !== $filters['sector'] ) {
		$wanted = law_calendar_normalise_choice( $filters['sector'] );
		$found  = false;
		foreach ( (array) $event['sectors'] as $sector ) {
			if ( law_calendar_normalise_choice( $sector ) === $wanted ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return false;
		}
	}

	if ( '' !== $filters['kw'] ) {
		$haystack = law_calendar_normalise_choice(
			implode(
				' ',
				array(
					$event['title'],
					$event['host'],
					$event['venue'],
					$event['type'],
					implode( ' ', (array) $event['sectors'] ),
					wp_strip_all_tags( (string) $event['description'] ),
				)
			)
		);
		$needle   = law_calendar_normalise_choice( $filters['kw'] );
		if ( '' !== $needle && false === strpos( $haystack, $needle ) ) {
			return false;
		}
	}

	return true;
}

/**
 * Choice labels for a Form 2 field, for the filter dropdowns.
 *
 * @param int $field_id Field ID (60 Sector, 63 Event type).
 * @return string[]
 */
function law_calendar_field_choices( $field_id ) {
	static $cache = array();
	$field_id     = (int) $field_id;
	if ( isset( $cache[ $field_id ] ) ) {
		return $cache[ $field_id ];
	}

	$cache[ $field_id ] = array();
	if ( ! class_exists( 'GFAPI' ) || ! class_exists( 'GFFormsModel' ) ) {
		return $cache[ $field_id ];
	}

	$form  = GFAPI::get_form( LAW_CALENDAR_FORM_ID );
	$field = $form ? GFFormsModel::get_field( $form, $field_id ) : null;
	if ( ! $field || empty( $field->choices ) || ! is_array( $field->choices ) ) {
		return $cache[ $field_id ];
	}

	foreach ( $field->choices as $choice ) {
		$label = html_entity_decode( (string) ( $choice['text'] ?? '' ), ENT_QUOTES, 'UTF-8' );
		$label = trim( $label );
		if ( '' !== $label ) {
			$cache[ $field_id ][] = $label;
		}
	}

	return $cache[ $field_id ];
}

function law_calendar_empty_message() {
	if ( law_calendar_is_searching() ) {
		return 'No events match this search.';
	}
	return law_calendar_is_committee()
		? 'No events submitted yet.'
		: 'No events in the programme yet.';
}

/**
 * AJAX partial: same page URL with &law_partial=1 returns only the events
 * markup (parts/calendar-events.php), so the filter UI can swap it in place.
 * Members page restrictions apply exactly as they do to the full page.
 */
add_action( 'template_redirect', 'law_calendar_maybe_render_partial' );
function law_calendar_maybe_render_partial() {
	if ( empty( $_GET['law_partial'] ) || ! law_calendar_is_calendar_page() ) {
		return;
	}

	$page_id = get_queried_object_id();
	if ( function_exists( 'members_can_current_user_view_post' ) && $page_id && ! members_can_current_user_view_post( $page_id ) ) {
		status_header( 403 );
		exit;
	}

	status_header( 200 );
	header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
	nocache_headers();
	get_template_part( 'parts/calendar-events', null, array( 'show_status' => law_calendar_is_committee() ) );
	exit;
}

/**
 * `?day=` is a reserved WP date-archive query var. A value like 2026-12-01
 * turns this page into a failed date query (have_posts() is false, blank page).
 * Keep /calendar/ as a page even when an old ?day= URL is used.
 */
add_filter( 'request', 'law_calendar_keep_page_query' );
function law_calendar_keep_page_query( $vars ) {
	$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH ) : '';
	$path = untrailingslashit( $path );

	$pages = array(
		'/calendar-committee' => 'calendar-committee',
		'/calendar'           => 'calendar',
	);

	foreach ( $pages as $suffix => $pagename ) {
		if ( $path === $suffix || substr( $path, -strlen( $suffix ) ) === $suffix ) {
			unset( $vars['year'], $vars['monthnum'], $vars['day'], $vars['name'], $vars['error'] );
			$vars['pagename']  = $pagename;
			$vars['post_type'] = 'page';
			return $vars;
		}
	}

	return $vars;
}

function law_calendar_requested_event_id() {
	return absint( wp_unslash( $_GET['event'] ?? 0 ) );
}

function law_calendar_url( $args = array(), $include_search = true ) {
	$page_id = get_queried_object_id();
	$base    = $page_id ? get_permalink( $page_id ) : home_url( law_calendar_is_committee() ? '/calendar-committee/' : '/calendar/' );
	if ( $include_search ) {
		$args = array_merge( law_calendar_search_query_args(), $args );
	}
	$clean = array();
	foreach ( $args as $key => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}
		$clean[ $key ] = $value;
	}
	return add_query_arg( $clean, $base );
}

/**
 * Parse Form 2 field 68 ("Tue 1st Dec: 08:30–10:00") into a date and times.
 */
function law_calendar_parse_slot( $raw ) {
	$raw = html_entity_decode( (string) $raw, ENT_QUOTES, 'UTF-8' );
	$raw = str_replace( array( '–', '—' ), '-', $raw );

	if ( ! preg_match(
		'/(\d{1,2})(?:st|nd|rd|th)?\s+(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*:\s*(\d{1,2}:\d{2})(?:\s*-\s*(\d{1,2}:\d{2})|\s+onwards)?/i',
		$raw,
		$m
	) ) {
		return null;
	}

	$month_num = date_parse( $m[2] );
	$month     = isset( $month_num['month'] ) ? (int) $month_num['month'] : 0;
	if ( $month < 1 ) {
		return null;
	}

	$start = law_calendar_normalise_time( $m[3] );
	$end   = ! empty( $m[4] ) ? law_calendar_normalise_time( $m[4] ) : '';

	return array(
		'date'       => sprintf( '%04d-%02d-%02d', LAW_CALENDAR_YEAR, $month, (int) $m[1] ),
		'start'      => $start,
		'end'        => $end,
		'onwards'    => empty( $m[4] ),
		'time_label' => $end ? $start . '-' . $end : $start . ' onwards',
	);
}

function law_calendar_normalise_time( $time ) {
	$parts = array_map( 'intval', explode( ':', $time ) );
	$hour  = isset( $parts[0] ) ? $parts[0] : 0;
	$min   = isset( $parts[1] ) ? $parts[1] : 0;
	return sprintf( '%02d:%02d', $hour, $min );
}

function law_calendar_excerpt( $html, $words = 18 ) {
	$text = wp_strip_all_tags( (string) $html );
	$text = preg_replace( '/\s+/', ' ', $text );
	return wp_trim_words( trim( $text ), $words, '…' );
}

/**
 * Form 2 entries for the programme calendars, filtered and sorted.
 *
 * Public calendar: Confirmed only. Committee calendar: all statuses. The
 * keyword / sector / type filters from the query string are applied here.
 *
 * @return array<int, array>
 */
function law_calendar_events() {
	static $cache = array();
	$key          = law_calendar_context();
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$allowed = law_calendar_is_committee() ? array() : law_calendar_public_statuses();
	$filters = law_calendar_filters();
	$events  = array();
	foreach ( law_calendar_raw_entries() as $entry ) {
		$mapped = law_calendar_map_entry( $entry, $allowed );
		if ( $mapped && law_calendar_event_matches_filters( $mapped, $filters ) ) {
			$events[] = $mapped;
		}
	}

	usort(
		$events,
		function ( $a, $b ) {
			return strcmp( $a['sort'], $b['sort'] );
		}
	);

	$cache[ $key ] = $events;
	return $events;
}

/**
 * @return array<int, array> Raw Gravity Forms entries.
 */
function law_calendar_raw_entries() {
	if ( ! class_exists( 'GFAPI' ) ) {
		return array();
	}

	$criteria = array( 'status' => 'active' );
	if ( ! law_calendar_is_committee() ) {
		$criteria['field_filters'] = array(
			array(
				'key'      => '95',
				'operator' => 'in',
				'value'    => law_calendar_public_statuses(),
			),
		);
	}

	$entries = GFAPI::get_entries(
		LAW_CALENDAR_FORM_ID,
		$criteria,
		array(),
		array( 'offset' => 0, 'page_size' => 500 )
	);

	if ( is_wp_error( $entries ) ) {
		return array();
	}

	return is_array( $entries ) ? $entries : array();
}

/**
 * Organisation category slugs that count as a sponsor organisation.
 *
 * @return string[]
 */
function law_calendar_sponsor_category_slugs() {
	return array( 'sponsors', 'bronze', 'silver', 'gold', 'platinum' );
}

/**
 * Published organisation post IDs in a sponsor category. Cached per request.
 *
 * @return int[]
 */
function law_calendar_sponsor_organisation_ids() {
	static $ids = null;
	if ( null !== $ids ) {
		return $ids;
	}

	$ids = get_posts(
		array(
			'post_type'      => 'organisation',
			'post_status'    => array( 'publish', 'private' ),
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'tax_query'      => array(
				array(
					'taxonomy' => 'organisation_category',
					'field'    => 'slug',
					'terms'    => law_calendar_sponsor_category_slugs(),
				),
			),
		)
	);

	$ids = array_values( array_filter( array_map( 'intval', (array) $ids ) ) );
	return $ids;
}

/**
 * Organisation post IDs stored on Form 2 field 109 (hidden multi-select, JSON).
 *
 * @param array $entry Gravity Forms entry.
 * @return int[]
 */
function law_calendar_entry_organisation_ids( $entry ) {
	$ids = array();
	$raw = rgar( $entry, '109' );

	if ( is_array( $raw ) ) {
		$ids = $raw;
	} else {
		$raw = trim( (string) $raw );
		if ( '' !== $raw ) {
			if ( '[' === $raw[0] ) {
				$decoded = json_decode( $raw, true );
				$ids     = is_array( $decoded ) ? $decoded : array();
			} else {
				$un = maybe_unserialize( $raw );
				$ids = is_array( $un ) ? $un : preg_split( '/[,;]/', $raw );
			}
		}
	}

	foreach ( $entry as $key => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}
		if ( strpos( (string) $key, '109.' ) === 0 ) {
			$ids[] = $value;
		}
	}

	return array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
}

/**
 * True when field 53 is the Sponsor choice, or its stored price is 0.
 *
 * Product radios are saved as "value|price", e.g. "Sponsor|0".
 *
 * @param array $entry Gravity Forms entry.
 * @return bool
 */
function law_calendar_fee_is_sponsor( $entry ) {
	$raw = rgar( $entry, '53' );
	if ( is_array( $raw ) ) {
		$name = trim( (string) ( $raw['value'] ?? $raw['name'] ?? '' ) );
		if ( 0 === strcasecmp( $name, 'Sponsor' ) ) {
			return true;
		}
		$price_raw = $raw['price'] ?? null;
		if ( null === $price_raw || '' === $price_raw ) {
			return false;
		}
		return 0.0 === law_calendar_fee_price_number( $price_raw );
	}

	$raw = html_entity_decode( trim( (string) $raw ), ENT_QUOTES, 'UTF-8' );
	if ( '' === $raw ) {
		return false;
	}

	$parts = explode( '|', $raw );
	if ( 0 === strcasecmp( trim( $parts[0] ), 'Sponsor' ) ) {
		return true;
	}

	if ( ! isset( $parts[1] ) || '' === $parts[1] ) {
		return false;
	}

	return 0.0 === law_calendar_fee_price_number( $parts[1] );
}

/**
 * @param mixed $price Raw price from a product field.
 * @return float
 */
function law_calendar_fee_price_number( $price ) {
	if ( class_exists( 'GFCommon' ) ) {
		return (float) GFCommon::to_number( (string) $price );
	}
	return (float) preg_replace( '/[^0-9.\-]/', '', (string) $price );
}

/**
 * True when field 109 includes at least one sponsor-category organisation.
 *
 * @param array $entry Gravity Forms entry.
 * @return bool
 */
function law_calendar_entry_has_sponsor_organisation( $entry ) {
	$org_ids = law_calendar_entry_organisation_ids( $entry );
	if ( ! $org_ids ) {
		return false;
	}
	$sponsor_ids = law_calendar_sponsor_organisation_ids();
	if ( ! $sponsor_ids ) {
		return false;
	}
	return (bool) array_intersect( $org_ids, $sponsor_ids );
}

/**
 * Approved / Confirmed Form 2 entries per submitter in the programme year.
 *
 * "Approved" here means committee-accepted: field 95 is Approved or Confirmed.
 * Cached per request.
 *
 * @return array<int, int> User ID => count.
 */
function law_calendar_approved_event_counts_by_user() {
	static $counts = null;
	if ( null !== $counts ) {
		return $counts;
	}

	$counts = array();
	if ( ! class_exists( 'GFAPI' ) ) {
		return $counts;
	}

	$year      = (int) LAW_CALENDAR_YEAR;
	$page_size = 200;
	$offset    = 0;
	$search    = array(
		'status'        => 'active',
		'start_date'    => $year . '-01-01 00:00:00',
		'end_date'      => $year . '-12-31 23:59:59',
		'field_filters' => array(
			array(
				'key'      => '95',
				'operator' => 'in',
				'value'    => array( 'Approved', 'Confirmed' ),
			),
		),
	);

	do {
		$entries = GFAPI::get_entries(
			LAW_CALENDAR_FORM_ID,
			$search,
			null,
			array(
				'offset'    => $offset,
				'page_size' => $page_size,
			)
		);
		if ( is_wp_error( $entries ) || ! is_array( $entries ) ) {
			break;
		}

		foreach ( $entries as $entry ) {
			$uid = (int) rgar( $entry, 'created_by' );
			if ( $uid < 1 ) {
				continue;
			}
			if ( ! isset( $counts[ $uid ] ) ) {
				$counts[ $uid ] = 0;
			}
			++$counts[ $uid ];
		}

		$fetched = count( $entries );
		$offset += $page_size;
	} while ( $fetched === $page_size );

	return $counts;
}

/**
 * Sponsor-event highlight on programme listings.
 *
 * True if any of these hold:
 * - Field 53 is "Sponsor" (or stored price is 0).
 * - Field 109 includes an organisation in a sponsor category.
 * - The submitter has more than one Approved/Confirmed event this programme year.
 *
 * @param array $entry Gravity Forms entry.
 * @return bool
 */
function law_calendar_is_sponsored_event( $entry ) {
	if ( ! is_array( $entry ) ) {
		return false;
	}
	if ( law_calendar_fee_is_sponsor( $entry ) ) {
		return true;
	}
	if ( law_calendar_entry_has_sponsor_organisation( $entry ) ) {
		return true;
	}

	$uid = (int) rgar( $entry, 'created_by' );
	if ( $uid < 1 ) {
		return false;
	}

	$counts = law_calendar_approved_event_counts_by_user();
	return ( $counts[ $uid ] ?? 0 ) > 1;
}

/**
 * Listing card classes, including the sponsored modifier.
 *
 * @param array  $event Mapped calendar event.
 * @param string $base  Base class (law-cal-card or law-cal-day__item).
 */
function law_calendar_card_classes( $event, $base = 'law-cal-card' ) {
	$classes = array( $base );
	if ( ! empty( $event['is_sponsored'] ) ) {
		$classes[] = $base . '--sponsored';
	}
	return implode( ' ', $classes );
}

/**
 * "Sponsored" tag for listing cards. Public and committee.
 *
 * @param array $event Mapped calendar event.
 */
function law_calendar_sponsored_label( $event ) {
	if ( empty( $event['is_sponsored'] ) ) {
		return;
	}
	printf(
		'<span class="law-cal-card__sponsored">%s</span>',
		esc_html__( 'Sponsored', 'law' )
	);
}

/**
 * Map a Form 2 entry to a calendar event, or null if it should not appear.
 *
 * @param array      $entry   Gravity Forms entry.
 * @param array|null $allowed Statuses to include. Null = public set. Empty array = all statuses (committee).
 * @return array|null
 */
function law_calendar_map_entry( $entry, $allowed = null ) {
	if ( ! is_array( $entry ) ) {
		return null;
	}

	if ( null === $allowed ) {
		$allowed = law_calendar_public_statuses();
	}

	$status = (string) rgar( $entry, '95' );
	$all    = is_array( $allowed ) && empty( $allowed );
	if ( ! $all && ! in_array( $status, $allowed, true ) ) {
		return null;
	}

	$slot = law_calendar_parse_slot( rgar( $entry, '68' ) );
	if ( ! $slot ) {
		if ( ! $all ) {
			return null;
		}
		$slot = array(
			'date'       => '',
			'start'      => '',
			'end'        => '',
			'onwards'    => false,
			'time_label' => 'Slot not confirmed',
		);
	}

	$title = trim( (string) rgar( $entry, '17' ) );
	if ( '' === $title ) {
		return null;
	}

	$type = (string) rgar( $entry, '63' );

	$tickets_raw = trim( (string) rgar( $entry, '54' ) );
	$tickets     = ( is_numeric( $tickets_raw ) && (float) $tickets_raw > 0 )
		? (int) $tickets_raw
		: 0;

	return array(
		'id'          => (int) rgar( $entry, 'id' ),
		'title'       => $title,
		'status'      => $status,
		'host'        => trim( (string) rgar( $entry, '105' ) ),
		'venue'       => trim( (string) rgar( $entry, '21' ) ),
		'tickets'     => $tickets,
		'type'        => $type,
		'sectors'     => law_calendar_sectors( $entry ),
		'speakers'    => array(), // Hydrated on the event listing only.
		'sessions'    => array(), // Hydrated on the event listing only.
		'excerpt'     => law_calendar_excerpt( rgar( $entry, '23' ) ),
		'description' => (string) rgar( $entry, '23' ),
		'url'         => law_calendar_url( array( 'event' => rgar( $entry, 'id' ) ), false ),
		'is_evening'  => ( 'Social event' === $type || ( $slot['start'] && $slot['start'] >= '18:00' ) ),
		'date'        => $slot['date'],
		'start'       => $slot['start'],
		'end'         => $slot['end'],
		'time_label'   => $slot['time_label'],
		'unscheduled'  => '' === $slot['date'],
		'is_sponsored' => law_calendar_is_sponsored_event( $entry ),
		'sort'         => ( $slot['date'] ? $slot['date'] : '9999-99-99' ) . ' ' . ( $slot['start'] ? $slot['start'] : '99:99' ) . ' ' . strtolower( $title ),
	);
}

function law_calendar_event_by_id( $entry_id ) {
	static $cache = array();

	$entry_id = (int) $entry_id;
	if ( $entry_id < 1 ) {
		return null;
	}

	if ( array_key_exists( $entry_id, $cache ) ) {
		return $cache[ $entry_id ];
	}

	$event = null;
	foreach ( law_calendar_events() as $candidate ) {
		if ( (int) $candidate['id'] === $entry_id ) {
			$event = $candidate;
			break;
		}
	}

	$entry = null;
	if ( ! $event ) {
		if ( ! class_exists( 'GFAPI' ) ) {
			$cache[ $entry_id ] = null;
			return null;
		}
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			$cache[ $entry_id ] = null;
			return null;
		}
		$event = law_calendar_map_entry( $entry, law_calendar_is_committee() ? array() : null );
	}

	if ( $event ) {
		if ( ! is_array( $entry ) && class_exists( 'GFAPI' ) ) {
			$entry = GFAPI::get_entry( $entry_id );
		}
		if ( is_array( $entry ) && ! is_wp_error( $entry ) ) {
			$event['speakers'] = law_calendar_speakers( $entry );
			$event['sessions'] = law_calendar_sessions( $entry );
		} else {
			$event['speakers'] = array();
			$event['sessions'] = array();
		}
	}

	$cache[ $entry_id ] = $event;
	return $event;
}

/**
 * "08:30" → "8:30am". Anything unparsable is returned unchanged.
 *
 * @param string $time 24-hour HH:MM.
 */
function law_calendar_time_12h( $time ) {
	if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', trim( (string) $time ), $m ) ) {
		return (string) $time;
	}
	$hour   = (int) $m[1];
	$suffix = $hour >= 12 ? 'pm' : 'am';
	$hour   = $hour % 12;
	if ( 0 === $hour ) {
		$hour = 12;
	}
	return $hour . ':' . $m[2] . $suffix;
}

/**
 * Display time for a mapped event, e.g. "8:30am - 10:00am" or "6:30pm onwards".
 *
 * @param array $event Mapped calendar event.
 */
function law_calendar_event_time_label( $event ) {
	$start = (string) ( $event['start'] ?? '' );
	if ( '' === $start ) {
		return (string) ( $event['time_label'] ?? '' );
	}
	$end = (string) ( $event['end'] ?? '' );
	if ( '' !== $end ) {
		return law_calendar_time_12h( $start ) . ' - ' . law_calendar_time_12h( $end );
	}
	return law_calendar_time_12h( $start ) . ' onwards';
}

/**
 * Section heading for a programme day, e.g. "Monday, 30 November 2026".
 *
 * @param string $date Y-m-d key from law_calendar_week_days().
 */
function law_calendar_day_heading( $date ) {
	$ts = strtotime( (string) $date );
	if ( ! $ts ) {
		return (string) $date;
	}
	return date_i18n( 'l, j F Y', $ts );
}

/**
 * Initials from a full name, for the speaker photo placeholder ("Ali Malek
 * KC" → "AM"). Mirrors law_speaker_initials(), which needs split name parts.
 *
 * @param string $name Full name.
 */
function law_calendar_name_initials( $name ) {
	$parts = preg_split( '/\s+/u', trim( (string) $name ) );
	$parts = array_values( array_filter( (array) $parts ) );
	if ( ! $parts ) {
		return '';
	}
	$initials = mb_strtoupper( mb_substr( $parts[0], 0, 1 ) );
	if ( count( $parts ) > 1 ) {
		$initials .= mb_strtoupper( mb_substr( $parts[1], 0, 1 ) );
	}
	return $initials;
}

/**
 * Day navigation label, e.g. "Monday, 30".
 *
 * @param string $date Y-m-d key from law_calendar_week_days().
 */
function law_calendar_day_nav_label( $date ) {
	$ts = strtotime( (string) $date );
	if ( ! $ts ) {
		return (string) $date;
	}
	return date_i18n( 'l, j', $ts );
}

function law_calendar_events_by_date() {
	$grouped = array( '_unscheduled' => array() );
	foreach ( array_keys( law_calendar_week_days() ) as $date ) {
		$grouped[ $date ] = array();
	}
	foreach ( law_calendar_events() as $event ) {
		if ( ! empty( $event['date'] ) && isset( $grouped[ $event['date'] ] ) ) {
			$grouped[ $event['date'] ][] = $event;
			continue;
		}
		$grouped['_unscheduled'][] = $event;
	}
	return $grouped;
}

function law_calendar_unscheduled_events() {
	$grouped = law_calendar_events_by_date();
	return $grouped['_unscheduled'] ?? array();
}

function law_calendar_meta_line( $event ) {
	$parts = array( $event['time_label'] );
	if ( ! empty( $event['venue'] ) ) {
		$parts[] = $event['venue'];
	}
	return implode( ' · ', array_filter( $parts ) );
}

/**
 * Host line for list/day cards. Empty string if there is no host.
 */
function law_calendar_hosted_by( $event ) {
	$host = trim( (string) ( $event['host'] ?? '' ) );
	if ( '' === $host ) {
		return '';
	}
	$hosts = array_filter( array_map( 'trim', preg_split( '/;/', $host ) ) );
	if ( ! $hosts ) {
		return '';
	}
	return sprintf(
		/* translators: %s: host organisation name(s) */
		__( 'Hosted by: %s', 'law' ),
		implode( ', ', $hosts )
	);
}

function law_calendar_today_key() {
	$today = wp_date( 'Y-m-d' );
	return isset( law_calendar_week_days()[ $today ] ) ? $today : '';
}

function law_calendar_sectors( $entry ) {
	$sectors = array();
	foreach ( $entry as $key => $value ) {
		if ( '' === $value || null === $value ) {
			continue;
		}
		if ( strpos( (string) $key, '60.' ) === 0 ) {
			$sectors[] = (string) $value;
		}
	}
	return $sectors;
}

/**
 * Speakers Nested Form field on Form 2 (110 local, 112 live).
 *
 * Cached per request so list mapping does not re-scan the form.
 *
 * @return int
 */
function law_calendar_speakers_nested_field_id() {
	static $field_id = null;
	if ( null !== $field_id ) {
		return $field_id;
	}

	$field_id = 0;
	if ( ! class_exists( 'GFAPI' ) ) {
		return 0;
	}

	$form = GFAPI::get_form( LAW_CALENDAR_FORM_ID );
	if ( ! $form || empty( $form['fields'] ) ) {
		return 0;
	}

	foreach ( $form['fields'] as $field ) {
		if ( 'form' !== $field->type ) {
			continue;
		}
		$label = (string) $field->label;
		if ( false !== stripos( $label, 'speaker' ) && false === stripos( $label, 'list' ) ) {
			$field_id = (int) $field->id;
			return $field_id;
		}
		$child_id = (int) $field->gpnfForm;
		$child    = $child_id ? GFAPI::get_form( $child_id ) : null;
		if ( $child && false !== stripos( (string) $child['title'], 'speaker' ) ) {
			$field_id = (int) $field->id;
			return $field_id;
		}
	}

	return $field_id;
}

/**
 * Sessions Nested Form field on Form 2 (115 locally).
 *
 * @return int
 */
function law_calendar_sessions_nested_field_id() {
	static $field_id = null;
	if ( null !== $field_id ) {
		return $field_id;
	}

	$field_id = 0;
	if ( ! class_exists( 'GFAPI' ) ) {
		return 0;
	}

	$form = GFAPI::get_form( LAW_CALENDAR_FORM_ID );
	if ( ! $form || empty( $form['fields'] ) ) {
		return 0;
	}

	foreach ( $form['fields'] as $field ) {
		if ( 'form' !== $field->type ) {
			continue;
		}
		$label = (string) $field->label;
		if ( false !== stripos( $label, 'session' ) ) {
			$field_id = (int) $field->id;
			return $field_id;
		}
		$child_id = (int) $field->gpnfForm;
		$child    = $child_id ? GFAPI::get_form( $child_id ) : null;
		if ( $child && false !== stripos( (string) $child['title'], 'session' ) ) {
			$field_id = (int) $field->id;
			return $field_id;
		}
	}

	return $field_id;
}

/**
 * Child entries for a Nested Form field, in the order stored on the parent.
 *
 * @param array $entry    Parent form 2 entry.
 * @param int   $field_id Nested Form field ID.
 * @return array<int,array>
 */
function law_calendar_nested_children( $entry, $field_id ) {
	$field_id = (int) $field_id;
	if ( ! $field_id || ! is_array( $entry ) ) {
		return array();
	}

	$ids = rgar( $entry, (string) $field_id );
	if ( '' === $ids || null === $ids ) {
		return array();
	}

	if ( function_exists( 'gp_nested_forms' ) ) {
		$children = gp_nested_forms()->get_entries( $ids );
		return is_array( $children ) ? $children : array();
	}

	if ( ! class_exists( 'GFAPI' ) ) {
		return array();
	}

	$children = array();
	foreach ( array_filter( array_map( 'absint', explode( ',', (string) $ids ) ) ) as $child_id ) {
		$child = GFAPI::get_entry( $child_id );
		if ( ! is_wp_error( $child ) ) {
			$children[] = $child;
		}
	}
	return $children;
}

/**
 * Display name from a form 8 speaker entry.
 *
 * @param array $entry Form 8 entry.
 * @return string
 */
function law_calendar_speaker_display_name( $entry ) {
	$name = trim( rgar( $entry, '1.3' ) . ' ' . rgar( $entry, '1.6' ) );
	if ( '' === $name ) {
		$name = trim( (string) rgar( $entry, '1' ) );
	}
	return $name;
}

/**
 * Profile URL for a form 8 speaker entry, or empty when speakers.php is not loaded.
 *
 * @param int $entry_id Form 8 entry ID.
 * @return string
 */
function law_calendar_speaker_profile_url( $entry_id ) {
	$entry_id = (int) $entry_id;
	if ( $entry_id < 1 || ! function_exists( 'law_speaker_url' ) ) {
		return '';
	}
	return law_speaker_url( $entry_id );
}

/**
 * Form 8 entry IDs from a GP Populate Anything multiselect (JSON array, CSV, or array).
 *
 * @param mixed $raw Field value.
 * @return array<int,int>
 */
function law_calendar_entry_ids_from_value( $raw ) {
	if ( is_array( $raw ) ) {
		return array_values( array_filter( array_map( 'absint', $raw ) ) );
	}

	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return array();
	}

	if ( '[' === $raw[0] ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return array_values( array_filter( array_map( 'absint', $decoded ) ) );
		}
	}

	return array_values( array_filter( array_map( 'absint', explode( ',', $raw ) ) ) );
}

/**
 * Speakers referenced by form 8 entry IDs (Sessions field 6).
 *
 * @param mixed $raw Multiselect value.
 * @return array<int,array{id:int,name:string,organisation:string,job_title:string,url:string,photo:string}>
 */
function law_calendar_speakers_from_ids( $raw ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		return array();
	}

	$speakers = array();
	foreach ( law_calendar_entry_ids_from_value( $raw ) as $entry_id ) {
		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) ) {
			continue;
		}
		$name = law_calendar_speaker_display_name( $entry );
		if ( '' === $name ) {
			continue;
		}
		$speakers[] = array(
			'id'           => $entry_id,
			'name'         => $name,
			'organisation' => trim( (string) rgar( $entry, '3' ) ),
			'job_title'    => trim( (string) rgar( $entry, '4' ) ),
			'url'          => law_calendar_speaker_profile_url( $entry_id ),
			'photo'        => law_calendar_speaker_photo_url( rgar( $entry, '6' ) ),
		);
	}

	return $speakers;
}

/**
 * Sessions for an event listing, from nested child entries (form 9).
 *
 * Child fields: Start time 1, End time 3, Title 4, Description 5,
 * Speakers 6 (multiselect of form 8 entry IDs).
 *
 * @param array $entry Form 2 entry.
 * @return array<int,array{title:string,start:string,end:string,time_label:string,description:string,speakers:array}>
 */
function law_calendar_sessions( $entry ) {
	if ( ! is_array( $entry ) ) {
		return array();
	}

	$children = law_calendar_nested_children( $entry, law_calendar_sessions_nested_field_id() );
	if ( empty( $children ) ) {
		return array();
	}

	$sessions = array();
	foreach ( $children as $child ) {
		$title       = trim( (string) rgar( $child, '4' ) );
		$start       = trim( (string) rgar( $child, '1' ) );
		$end         = trim( (string) rgar( $child, '3' ) );
		$description = trim( (string) rgar( $child, '5' ) );
		$speakers    = law_calendar_speakers_from_ids( rgar( $child, '6' ) );
		$time_label  = law_calendar_session_time_label( $start, $end );

		if ( '' === $title && '' === $time_label && '' === $description && empty( $speakers ) ) {
			continue;
		}

		$sessions[] = array(
			'title'       => $title,
			'start'       => $start,
			'end'         => $end,
			'time_label'  => $time_label,
			'description' => $description,
			'speakers'    => $speakers,
		);
	}

	return $sessions;
}

/**
 * @param string $start Field 1 value, e.g. "09:00".
 * @param string $end   Field 3 value.
 * @return string
 */
function law_calendar_session_time_label( $start, $end ) {
	$start = trim( (string) $start );
	$end   = trim( (string) $end );
	if ( $start && $end ) {
		return $start . '–' . $end;
	}
	return $start;
}

/**
 * First image URL from a Gravity Forms file-upload value.
 *
 * @param mixed $raw JSON array of URLs, a single URL, or a pipe-separated string.
 * @return string
 */
function law_calendar_speaker_photo_url( $raw ) {
	if ( empty( $raw ) ) {
		return '';
	}

	if ( is_array( $raw ) ) {
		$first = reset( $raw );
		if ( is_string( $first ) ) {
			return esc_url_raw( $first );
		}
		if ( is_array( $first ) ) {
			return esc_url_raw( (string) ( $first['url'] ?? $first['tmp_url'] ?? '' ) );
		}
		return '';
	}

	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return '';
	}

	$start = $raw[0];
	if ( '[' === $start || '{' === $start ) {
		$decoded = json_decode( $raw, true );
		if ( is_array( $decoded ) ) {
			return law_calendar_speaker_photo_url( $decoded );
		}
	}

	if ( false !== strpos( $raw, '|' ) ) {
		$parts = explode( '|', $raw );
		return esc_url_raw( $parts[0] );
	}

	return esc_url_raw( $raw );
}

/**
 * Speakers for an event listing, from nested child entries.
 *
 * Falls back to List field 48 when the nested field is empty (unmigrated
 * live entries). Nested children: Name 1.3/1.6, Organisation 3, Job title 4,
 * Website 5, Photo 6.
 *
 * @param array $entry Form 2 entry.
 * @return array<int,array{name:string,organisation:string,job_title:string,url:string,photo:string}>
 */
function law_calendar_speakers( $entry ) {
	if ( ! is_array( $entry ) ) {
		return array();
	}

	$speakers = law_calendar_speakers_from_nested( $entry );
	if ( $speakers ) {
		return $speakers;
	}

	return law_calendar_speakers_from_list( rgar( $entry, '48' ) );
}

/**
 * @param array $entry Form 2 entry.
 * @return array<int,array{id:int,name:string,organisation:string,job_title:string,url:string,photo:string}>
 */
function law_calendar_speakers_from_nested( $entry ) {
	$children = law_calendar_nested_children( $entry, law_calendar_speakers_nested_field_id() );
	if ( empty( $children ) ) {
		return array();
	}

	$speakers = array();
	foreach ( $children as $child ) {
		$name = law_calendar_speaker_display_name( $child );
		if ( '' === $name ) {
			continue;
		}
		$entry_id   = (int) rgar( $child, 'id' );
		$speakers[] = array(
			'id'           => $entry_id,
			'name'         => $name,
			'organisation' => trim( (string) rgar( $child, '3' ) ),
			'job_title'    => trim( (string) rgar( $child, '4' ) ),
			'url'          => law_calendar_speaker_profile_url( $entry_id ),
			'photo'        => law_calendar_speaker_photo_url( rgar( $child, '6' ) ),
		);
	}

	return $speakers;
}

/**
 * @param mixed $raw Serialized List field 48 value.
 * @return array<int,array{name:string,organisation:string,job_title:string,url:string,photo:string}>
 */
function law_calendar_speakers_from_list( $raw ) {
	$rows = maybe_unserialize( $raw );
	if ( ! is_array( $rows ) ) {
		return array();
	}
	$speakers = array();
	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$name = trim( (string) ( $row['Name'] ?? '' ) );
		if ( '' === $name ) {
			continue;
		}
		$speakers[] = array(
			'name'         => $name,
			'organisation' => trim( (string) ( $row['Organisation'] ?? '' ) ),
			'job_title'    => trim( (string) ( $row['Job title'] ?? '' ) ),
			'url'          => esc_url_raw( (string) ( $row['URL'] ?? '' ) ),
			'photo'        => '',
		);
	}
	return $speakers;
}

add_filter( 'document_title_parts', 'law_calendar_document_title' );
add_filter( 'seopress_titles_title', 'law_calendar_seopress_title' );
add_filter( 'pre_get_document_title', 'law_calendar_pre_document_title', PHP_INT_MAX );

function law_calendar_current_event_for_title() {
	if ( ! law_calendar_is_calendar_page() ) {
		return null;
	}
	if ( function_exists( 'members_can_current_user_view_post' ) ) {
		$page_id = get_queried_object_id();
		if ( $page_id && ! members_can_current_user_view_post( $page_id ) ) {
			return null;
		}
	}
	$event_id = law_calendar_requested_event_id();
	if ( ! $event_id ) {
		return null;
	}
	return law_calendar_event_by_id( $event_id );
}

function law_calendar_document_title( $parts ) {
	$event = law_calendar_current_event_for_title();
	if ( ! $event ) {
		return $parts;
	}
	$parts['title'] = $event['title'];
	return $parts;
}

function law_calendar_seopress_title( $title ) {
	$event = law_calendar_current_event_for_title();
	if ( ! $event ) {
		return $title;
	}
	return $event['title'] . ' – ' . get_bloginfo( 'name' );
}

function law_calendar_pre_document_title( $title ) {
	$event = law_calendar_current_event_for_title();
	if ( ! $event ) {
		return $title;
	}
	return $event['title'] . ' – ' . get_bloginfo( 'name' );
}

/**
 * Search query for Google Maps. Appends London when the venue string does not
 * already mention it, so firm names like "IDRC" resolve to the local office.
 *
 * @param string $venue Field 21 value.
 */
function law_calendar_maps_query( $venue ) {
	$venue = trim( (string) $venue );
	if ( '' === $venue ) {
		return '';
	}
	if ( ! preg_match( '/\blondon\b/i', $venue ) ) {
		$venue .= ', London, UK';
	}
	return $venue;
}

/**
 * True when the venue string is worth sending to Maps (not TBC / empty).
 *
 * @param string $venue Field 21 value.
 */
function law_calendar_venue_is_mappable( $venue ) {
	$venue = trim( (string) $venue );
	if ( '' === $venue ) {
		return false;
	}
	$normalized = strtolower( (string) preg_replace( '/[^a-z0-9]+/i', '', $venue ) );
	$skip       = array( 'tbc', 'tbd', 'tba', 'tobeconfirmed', 'tobedecided', 'tobeannounced', 'unknown', 'na', 'n/a', 'none' );
	return ! in_array( $normalized, $skip, true );
}

function law_calendar_maps_url( $venue ) {
	$query = law_calendar_maps_query( $venue );
	if ( '' === $query ) {
		return '';
	}
	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query );
}

/**
 * Google Maps embed URL for an iframe. Uses the keyless Maps embed endpoint
 * (origin=mfe) with the same search query as law_calendar_maps_url().
 *
 * @param string $venue Field 21 value.
 */
function law_calendar_maps_embed_url( $venue ) {
	$query = law_calendar_maps_query( $venue );
	if ( '' === $query ) {
		return '';
	}
	return 'https://www.google.com/maps/embed?origin=mfe&pb=!1m2!2m1!1s' . rawurlencode( $query );
}

/**
 * Dashboard entry URL. Administrators and editors only; others get an empty string.
 *
 * @param int $entry_id Gravity Forms entry ID.
 */
function law_calendar_entry_admin_url( $entry_id ) {
	$entry_id = (int) $entry_id;
	if ( $entry_id < 1 ) {
		return '';
	}
	if ( ! function_exists( 'law_user_may_use_wp_admin' ) || ! law_user_may_use_wp_admin() ) {
		return '';
	}
	return admin_url(
		sprintf(
			'admin.php?page=gf_entries&view=entry&id=%d&lid=%d',
			LAW_CALENDAR_FORM_ID,
			$entry_id
		)
	);
}

/**
 * Discreet “Edit” link to the Form 2 entry in wp-admin.
 *
 * @param array|int $event Mapped calendar event or entry ID.
 */
function law_calendar_edit_link( $event ) {
	$id  = is_array( $event ) ? (int) ( $event['id'] ?? 0 ) : (int) $event;
	$url = law_calendar_entry_admin_url( $id );
	if ( ! $url ) {
		return;
	}
	printf(
		'<a class="law-cal-edit" href="%s" aria-label="%s">%s</a>',
		esc_url( $url ),
		esc_attr__( 'Edit this event in the dashboard', 'law' ),
		esc_html__( 'Edit', 'law' )
	);
}
