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
 * GravityView that drives programme search. Created once from Events (committee - all).
 */
function law_calendar_view_id( $context = null ) {
	if ( null === $context ) {
		$context = law_calendar_context();
	}

	$option = ( 'committee' === $context ) ? 'law_calendar_committee_view_id' : 'law_calendar_view_id';
	$slug   = ( 'committee' === $context ) ? 'programme-committee' : 'programme';

	$id = absint( get_option( $option ) );
	if ( $id && 'gravityview' === get_post_type( $id ) ) {
		return $id;
	}

	$by_slug = get_page_by_path( $slug, OBJECT, 'gravityview' );
	if ( $by_slug ) {
		update_option( $option, (int) $by_slug->ID, false );
		return (int) $by_slug->ID;
	}

	return 0;
}

function law_calendar_view( $context = null ) {
	$id = law_calendar_view_id( $context );
	if ( ! $id || ! class_exists( '\GV\View' ) ) {
		return null;
	}
	$view = \GV\View::by_id( $id );
	return $view ?: null;
}

/**
 * Register the Programme View with GravityView so search CSS/JS load on /calendar/.
 */
add_action( 'wp', 'law_calendar_register_gravityview', 12 );
function law_calendar_register_gravityview() {
	if ( ! law_calendar_is_calendar_page() ) {
		return;
	}
	$view_id = law_calendar_view_id();
	if ( ! $view_id || ! class_exists( 'GravityView_View_Data' ) || ! class_exists( 'GravityView_frontend' ) ) {
		return;
	}

	$data = GravityView_View_Data::getInstance();
	$data->add_view( $view_id );
	GravityView_frontend::getInstance()->setGvOutputData( $data );
	GravityView_frontend::getInstance()->setPostId( get_queried_object_id() );

	if ( class_exists( 'GravityView_View' ) ) {
		GravityView_View::getInstance()->setViewId( $view_id );
		GravityView_View::getInstance()->setPostId( get_queried_object_id() );
	}
}

/**
 * GET args that belong to the GravityView search, so List/Day/Week tabs keep the filter.
 */
function law_calendar_search_query_args() {
	$out  = array();
	$skip = array( 'view', 'cal_day', 'day', 'event', 'pagenum', 'page_id' );
	$get  = isset( $_GET ) && is_array( $_GET ) ? wp_unslash( $_GET ) : array();

	foreach ( $get as $key => $value ) {
		if ( in_array( $key, $skip, true ) || '' === $value || null === $value ) {
			continue;
		}
		$is_search = (
			0 === strpos( $key, 'filter_' )
			|| 0 === strpos( $key, 'gv_' )
			|| 'mode' === $key
		);
		if ( ! $is_search ) {
			continue;
		}
		if ( is_array( $value ) ) {
			$out[ $key ] = array_map( 'sanitize_text_field', $value );
			continue;
		}
		$out[ $key ] = sanitize_text_field( $value );
	}

	return $out;
}

function law_calendar_is_searching() {
	return ! empty( law_calendar_search_query_args() );
}

function law_calendar_empty_message() {
	if ( law_calendar_is_searching() ) {
		return 'No events match this search.';
	}
	return law_calendar_is_committee()
		? 'No events submitted yet.'
		: 'No events in the programme yet.';
}

add_filter( 'gravityview/widget/search/form/action', 'law_calendar_search_form_action', 10, 2 );
function law_calendar_search_form_action( $url, $view_id = 0 ) {
	if ( ! law_calendar_is_calendar_page() ) {
		return $url;
	}
	$page_id = get_queried_object_id();
	return $page_id ? get_permalink( $page_id ) : $url;
}

function law_calendar_current_view() {
	$view = sanitize_key( wp_unslash( $_GET['view'] ?? 'list' ) );
	return in_array( $view, array( 'list', 'day' ), true ) ? $view : 'list';
}

function law_calendar_requested_day() {
	$raw = '';
	if ( isset( $_GET['cal_day'] ) ) {
		$raw = sanitize_text_field( wp_unslash( $_GET['cal_day'] ) );
	} elseif ( isset( $_GET['day'] ) ) {
		$raw = sanitize_text_field( wp_unslash( $_GET['day'] ) );
	}
	$days = law_calendar_week_days();
	if ( $raw && isset( $days[ $raw ] ) ) {
		return $raw;
	}
	$today = law_calendar_today_key();
	if ( $today ) {
		return $today;
	}
	return '2026-12-01';
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
 * Form 2 entries for the programme calendars.
 *
 * Status is owned by the GravityView Advanced Filter (Programme = Confirmed,
 * committee View = all). PHP only re-applies a status filter on the GFAPI
 * fallback, when that View is missing.
 *
 * @return array<int, array>
 */
function law_calendar_events() {
	static $cache = array();
	$key          = law_calendar_context();
	if ( isset( $cache[ $key ] ) ) {
		return $cache[ $key ];
	}

	$from_view = law_calendar_view() && function_exists( 'gravityview' );
	$allowed   = ( $from_view || law_calendar_is_committee() )
		? array()
		: law_calendar_public_statuses();
	$events    = array();
	foreach ( law_calendar_raw_entries() as $entry ) {
		$mapped = law_calendar_map_entry( $entry, $allowed );
		if ( $mapped ) {
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
	$view = law_calendar_view();
	if ( $view && function_exists( 'gravityview' ) ) {
		$collection = $view->get_entries( gravityview()->request );
		$entries    = array();
		foreach ( $collection->all() as $gv_entry ) {
			$as = $gv_entry->as_entry();
			if ( is_array( $as ) ) {
				$entries[] = $as;
			}
		}
		return $entries;
	}

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

	return array(
		'id'          => (int) rgar( $entry, 'id' ),
		'title'       => $title,
		'status'      => $status,
		'host'        => trim( (string) rgar( $entry, '105' ) ),
		'venue'       => trim( (string) rgar( $entry, '21' ) ),
		'type'        => $type,
		'sectors'     => law_calendar_sectors( $entry ),
		'speakers'    => law_calendar_speakers( rgar( $entry, '48' ) ),
		'excerpt'     => law_calendar_excerpt( rgar( $entry, '23' ) ),
		'description' => (string) rgar( $entry, '23' ),
		'url'         => law_calendar_url( array( 'event' => rgar( $entry, 'id' ) ), false ),
		'is_evening'  => ( 'Social event' === $type || ( $slot['start'] && $slot['start'] >= '18:00' ) ),
		'date'        => $slot['date'],
		'start'       => $slot['start'],
		'end'         => $slot['end'],
		'time_label'  => $slot['time_label'],
		'unscheduled' => '' === $slot['date'],
		'sort'        => ( $slot['date'] ? $slot['date'] : '9999-99-99' ) . ' ' . ( $slot['start'] ? $slot['start'] : '99:99' ) . ' ' . strtolower( $title ),
	);
}

function law_calendar_event_by_id( $entry_id ) {
	$entry_id = (int) $entry_id;
	if ( $entry_id < 1 ) {
		return null;
	}

	foreach ( law_calendar_events() as $event ) {
		if ( (int) $event['id'] === $entry_id ) {
			return $event;
		}
	}

	if ( ! class_exists( 'GFAPI' ) ) {
		return null;
	}

	$entry = GFAPI::get_entry( $entry_id );
	if ( is_wp_error( $entry ) ) {
		return null;
	}

	return law_calendar_map_entry( $entry, law_calendar_is_committee() ? array() : null );
}

/**
 * Output the Programme View search bar. Does not render the GravityView table.
 */
function law_calendar_render_search() {
	$view = law_calendar_view();
	if ( ! $view ) {
		return;
	}

	$widgets = $view->widgets->by_id( 'search_bar' )->all();
	if ( empty( $widgets ) ) {
		return;
	}

	$widget = $widgets[0];
	$args   = $widget->configuration->all();
	$args['view_id'] = $view->ID;

	echo '<div class="law-cal-search gv-container">';
	$widget->render_frontend( $args, '', '' );
	echo '</div>';
}

/**
 * One-off installer: clone View 419 into a public Programme View.
 *
 * @return int New or existing View ID.
 */
function law_calendar_install_programme_view() {
	$existing = law_calendar_view_id();
	if ( $existing ) {
		return $existing;
	}

	$source_id = 419;
	$source    = get_post( $source_id );
	if ( ! $source || 'gravityview' !== $source->post_type ) {
		return 0;
	}

	$new_id = wp_insert_post(
		array(
			'post_type'    => 'gravityview',
			'post_status'  => 'publish',
			'post_title'   => 'Programme',
			'post_name'    => 'programme',
			'post_content' => $source->post_content,
			'post_author'  => $source->post_author,
		),
		true
	);
	if ( is_wp_error( $new_id ) ) {
		return 0;
	}
	$new_id = (int) $new_id;

	$meta_keys = array(
		'_gravityview_form_id',
		'_gravityview_directory_template',
		'_gravityview_single_template',
		'_gravityview_template_settings',
		'_gravityview_directory_fields',
		'_gravityview_directory_widgets',
		'_gravityview_datatables_settings',
		'_gravityview_row_settings',
	);
	foreach ( $meta_keys as $key ) {
		$value = get_post_meta( $source_id, $key, true );
		if ( '' !== $value && null !== $value && array() !== $value ) {
			update_post_meta( $new_id, $key, $value );
		}
	}

	$settings = get_post_meta( $new_id, '_gravityview_template_settings', true );
	if ( ! is_array( $settings ) ) {
		$settings = array();
	}
	$settings['page_size']              = '500';
	$settings['embed_only']             = '1';
	$settings['is_secure']              = '0';
	$settings['inline_edit']            = '0';
	$settings['lightbox']               = '0';
	$settings['hide_until_searched']    = '0';
	$settings['admin_show_all_statuses'] = '1';
	$settings['user_edit']              = '0';
	$settings['user_delete']            = '0';
	update_post_meta( $new_id, '_gravityview_template_settings', $settings );

	$widgets = get_post_meta( $new_id, '_gravityview_directory_widgets', true );
	if ( is_array( $widgets ) ) {
		$uid = static function () {
			return substr( str_replace( '.', '', uniqid( '', true ) ), -13 );
		};
		$search_widget = array(
			'search_fields_section' => array(
				'search-general_top::100::0ce5cfe5374bf' => array(
					$uid()         => array(
						'show_label'        => '1',
						'custom_label'      => 'Search',
						'input_type'        => 'input_text',
						'placeholder'       => 'Search programme',
						'only_loggedin'     => '0',
						'only_loggedin_cap' => 'read',
						'custom_class'      => '',
						'id'                => 'search_all',
						'label'             => 'Search Everything',
						'form_id'           => '2',
					),
					$uid()         => array(
						'show_label'   => '1',
						'custom_label' => '',
						'input_type'   => 'hidden',
						'mode'         => 'all',
						'custom_class' => '',
						'id'           => 'search_mode',
						'label'        => 'Search Mode',
						'form_id'      => '2',
					),
					$uid()         => array(
						'show_label'        => '1',
						'custom_label'      => 'Time',
						'input_type'        => 'select',
						'sieve_choices'     => '1',
						'only_loggedin'     => '0',
						'only_loggedin_cap' => 'read',
						'custom_class'      => '',
						'id'                => '2::68',
						'label'             => 'Confirmed slot',
						'form_id'           => '2',
					),
					$uid()         => array(
						'show_label'        => '1',
						'custom_label'      => 'Host organisation',
						'input_type'        => 'input_text',
						'placeholder'       => 'Host organisation',
						'only_loggedin'     => '0',
						'only_loggedin_cap' => 'read',
						'custom_class'      => '',
						'id'                => '2::105',
						'label'             => 'Host organisation(s)',
						'form_id'           => '2',
					),
					$uid()         => array(
						'show_label'        => '1',
						'custom_label'      => 'Sector',
						'input_type'        => 'select',
						'sieve_choices'     => '1',
						'only_loggedin'     => '0',
						'only_loggedin_cap' => 'read',
						'custom_class'      => '',
						'id'                => '2::60',
						'label'             => 'Sector',
						'form_id'           => '2',
					),
					$uid()         => array(
						'show_label'        => '1',
						'custom_label'      => 'Type',
						'input_type'        => 'select',
						'sieve_choices'     => '1',
						'only_loggedin'     => '0',
						'only_loggedin_cap' => 'read',
						'custom_class'      => '',
						'id'                => '2::63',
						'label'             => 'Event type',
						'form_id'           => '2',
					),
					$uid()         => array(
						'search_clear' => '1',
						'custom_label' => 'Go',
						'input_type'   => 'submit',
						'tag'          => 'input',
						'custom_class' => '',
						'show_label'   => '1',
						'id'           => 'submit',
						'label'        => 'Submit Button',
						'form_id'      => '2',
					),
					'area_settings' => array(
						'layout'         => 'column',
						'search_columns' => '0',
						'id'             => 'area_settings',
						'label'          => 'Column',
					),
				),
			),
			'id'                    => 'search_bar',
			'label'                 => 'Search Bar',
			'form_id'               => '2',
		);

		$header_key = null;
		foreach ( array_keys( $widgets ) as $zone ) {
			if ( 0 === strpos( $zone, 'header_top::' ) ) {
				$header_key = $zone;
				break;
			}
		}
		if ( ! $header_key ) {
			$header_key = 'header_top::100::' . $uid();
		}

		$widgets = array(
			$header_key => array(
				$uid() => $search_widget,
			),
		);
		update_post_meta( $new_id, '_gravityview_directory_widgets', $widgets );
	}

	update_post_meta( $new_id, '_gravityview_filters', law_calendar_public_view_filters() );

	update_option( 'law_calendar_view_id', $new_id, false );
	return $new_id;
}

/**
 * Advanced Filter for the public Programme View: Confirmed only.
 */
function law_calendar_public_view_filters() {
	return array(
		'_id'        => 'lawCalStat',
		'version'    => 2,
		'mode'       => 'or',
		'conditions' => array(
			array(
				'_id'        => 'lawCalGrp1',
				'mode'       => 'or',
				'conditions' => array(
					array(
						'_id'      => 'lawCalConf',
						'form_id'  => 2,
						'key'      => '95',
						'value'    => 'Confirmed',
						'operator' => 'is',
					),
				),
			),
		),
	);
}

function law_calendar_sync_public_view_filter() {
	$view_id = law_calendar_view_id( 'public' );
	if ( ! $view_id ) {
		return false;
	}
	return (bool) update_post_meta( $view_id, '_gravityview_filters', law_calendar_public_view_filters() );
}

/**
 * Clone the public Programme View into a committee View: all statuses, plus a status search field.
 *
 * @return int New or existing View ID.
 */
function law_calendar_install_committee_view() {
	$existing = law_calendar_view_id( 'committee' );
	if ( $existing ) {
		return $existing;
	}

	$source_id = law_calendar_view_id( 'public' );
	if ( ! $source_id ) {
		$source_id = 623;
	}
	$source = get_post( $source_id );
	if ( ! $source || 'gravityview' !== $source->post_type ) {
		return 0;
	}

	$new_id = wp_insert_post(
		array(
			'post_type'    => 'gravityview',
			'post_status'  => 'publish',
			'post_title'   => 'Programme (committee)',
			'post_name'    => 'programme-committee',
			'post_content' => $source->post_content,
			'post_author'  => $source->post_author,
		),
		true
	);
	if ( is_wp_error( $new_id ) ) {
		return 0;
	}
	$new_id = (int) $new_id;

	$meta_keys = array(
		'_gravityview_form_id',
		'_gravityview_directory_template',
		'_gravityview_single_template',
		'_gravityview_template_settings',
		'_gravityview_directory_fields',
		'_gravityview_directory_widgets',
		'_gravityview_datatables_settings',
		'_gravityview_row_settings',
	);
	foreach ( $meta_keys as $key ) {
		$value = get_post_meta( $source_id, $key, true );
		if ( '' !== $value && null !== $value && array() !== $value ) {
			update_post_meta( $new_id, $key, $value );
		}
	}

	$widgets = get_post_meta( $new_id, '_gravityview_directory_widgets', true );
	if ( is_array( $widgets ) ) {
		$status_field = array(
			'show_label'        => '1',
			'custom_label'      => 'Status',
			'input_type'        => 'select',
			'sieve_choices'     => '1',
			'only_loggedin'     => '0',
			'only_loggedin_cap' => 'read',
			'custom_class'      => '',
			'id'                => '2::95',
			'label'             => 'Event status',
			'form_id'           => '2',
		);
		$uid = substr( str_replace( '.', '', uniqid( '', true ) ), -13 );

		foreach ( $widgets as $zone => $zone_widgets ) {
			foreach ( $zone_widgets as $wid => $widget ) {
				if ( ( $widget['id'] ?? '' ) !== 'search_bar' ) {
					continue;
				}
				foreach ( $widget['search_fields_section'] ?? array() as $area_key => $area ) {
					$rebuilt = array();
					$inserted = false;
					foreach ( $area as $fid => $field ) {
						$rebuilt[ $fid ] = $field;
						if ( ! $inserted && is_array( $field ) && 'search_mode' === ( $field['id'] ?? '' ) ) {
							$rebuilt[ $uid ] = $status_field;
							$inserted        = true;
						}
					}
					if ( ! $inserted ) {
						$rebuilt[ $uid ] = $status_field;
					}
					$widgets[ $zone ][ $wid ]['search_fields_section'][ $area_key ] = $rebuilt;
				}
			}
		}
		update_post_meta( $new_id, '_gravityview_directory_widgets', $widgets );
	}

	delete_post_meta( $new_id, '_gravityview_filters' );
	update_option( 'law_calendar_committee_view_id', $new_id, false );
	return $new_id;
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

function law_calendar_speakers( $raw ) {
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

function law_calendar_maps_url( $venue ) {
	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $venue );
}
