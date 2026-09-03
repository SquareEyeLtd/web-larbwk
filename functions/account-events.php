<?php
/**
 * The host events dashboard, /account/events/ (templates/account-events.php).
 *
 * The listing is theme-owned: entries come straight from GFAPI (form 2,
 * created_by = current user, all statuses) and render as event cards.
 * GravityView 386 "Events (hosts)" stays as the edit engine only: Edit links
 * are generated with GravityView_Edit_Entry::get_edit_link(), and when the
 * page is visited in GravityView's entry/edit context the template renders
 * the page content (the [gravityview] shortcode) instead of the dashboard,
 * so field whitelisting, entry locking and Entry Revisions keep working.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function law_account_events_is_template() {
	return is_page_template( 'templates/account-events.php' );
}

/**
 * True when GravityView's entry endpoint is in the URL (single entry view or
 * the edit form). The template then renders the GV shortcode from the page
 * content instead of the dashboard.
 */
function law_account_events_in_entry_context() {
	$endpoint = class_exists( '\GV\Entry' ) ? \GV\Entry::get_endpoint_name() : 'entry';
	return '' !== (string) get_query_var( $endpoint );
}

/**
 * The current user's form 2 entries, mapped like calendar events and sorted
 * by slot date. Every status is included.
 *
 * @return array<int, array{event: array, entry: array}>
 */
function law_account_events() {
	static $items = null;
	if ( null !== $items ) {
		return $items;
	}

	$items   = array();
	$user_id = get_current_user_id();
	if ( $user_id < 1 || ! class_exists( 'GFAPI' ) ) {
		return $items;
	}

	$entries = GFAPI::get_entries(
		LAW_CALENDAR_FORM_ID,
		array(
			'status'        => 'active',
			'field_filters' => array(
				array(
					'key'      => 'created_by',
					'operator' => 'is',
					'value'    => $user_id,
				),
			),
		),
		array(),
		array( 'offset' => 0, 'page_size' => 200 )
	);

	if ( is_wp_error( $entries ) || ! is_array( $entries ) ) {
		return $items;
	}

	foreach ( $entries as $entry ) {
		$event = law_calendar_map_entry( $entry, array() ); // Empty array = all statuses.
		if ( $event ) {
			$items[] = array(
				'event' => $event,
				'entry' => $entry,
			);
		}
	}

	usort(
		$items,
		function ( $a, $b ) {
			return strcmp( $a['event']['sort'], $b['event']['sort'] );
		}
	);

	return $items;
}

/**
 * The GravityView that powers editing, parsed from the page's own
 * [gravityview id="…"] shortcode so it survives ID differences between
 * environments. Falls back to view 386 (Events (hosts)).
 *
 * @return int
 */
function law_account_events_view_id() {
	static $view_id = null;
	if ( null !== $view_id ) {
		return $view_id;
	}

	$view_id = 386;
	$page    = get_post( get_queried_object_id() );
	if ( $page && preg_match( '/\[gravityview[^\]]*\bid=["\']?(\d+)/', (string) $page->post_content, $m ) ) {
		$view_id = (int) $m[1];
	}

	return $view_id;
}

/**
 * GravityView Edit Entry URL for one of the user's entries, or '' when the
 * extension is unavailable.
 *
 * @param array $entry Form 2 entry.
 */
function law_account_event_edit_url( $entry ) {
	if ( ! class_exists( 'GravityView_Edit_Entry' ) || ! is_array( $entry ) ) {
		return '';
	}
	return (string) GravityView_Edit_Entry::get_edit_link(
		$entry,
		law_account_events_view_id(),
		get_queried_object_id()
	);
}

/**
 * Gravity Flow inbox detail URL for an entry: the Comments thread and
 * workflow timeline. Same destination as GravityView's old Comments column.
 *
 * @param int $entry_id Form 2 entry ID.
 */
function law_account_event_comments_url( $entry_id ) {
	if ( ! function_exists( 'gravity_flow' ) || ! class_exists( 'Gravity_Flow_Common' ) ) {
		return '';
	}
	return (string) Gravity_Flow_Common::get_workflow_url(
		array(
			'page' => 'gravityflow-inbox',
			'view' => 'entry',
			'id'   => LAW_CALENDAR_FORM_ID,
			'lid'  => (int) $entry_id,
		),
		gravity_flow()->get_app_setting( 'inbox_page' )
	);
}

/**
 * Permalink of the submission page, /account/events/submit/.
 */
function law_account_events_submit_url() {
	$page = get_page_by_path( 'account/events/submit' );
	return $page instanceof WP_Post ? get_permalink( $page ) : '';
}

/**
 * Card actions for a host's event: Edit, Comments, then View listing when
 * the event is public, or Pay invoice while payment is awaited.
 *
 * @param array $event Mapped calendar event.
 * @param array $entry Raw form 2 entry.
 * @return array<int, array{label:string,url:string,arrow?:bool,external?:bool}>
 */
function law_account_event_actions( $event, $entry ) {
	$actions = array();

	$edit_url = law_account_event_edit_url( $entry );
	if ( $edit_url ) {
		$actions[] = array(
			'label' => __( 'Edit', 'law' ),
			'url'   => $edit_url,
		);
	}

	$comments_url = law_account_event_comments_url( $event['id'] );
	if ( $comments_url ) {
		$actions[] = array(
			'label' => __( 'Comments', 'law' ),
			'url'   => $comments_url,
		);
	}

	// Awaiting payment: Approved, with the Stripe invoice URL written back.
	$invoice_url = trim( (string) rgar( $entry, '83' ) );
	if ( 'Approved' === $event['status'] && '' !== $invoice_url ) {
		$actions[] = array(
			'label'    => __( 'Pay invoice', 'law' ),
			'url'      => $invoice_url,
			'arrow'    => true,
			'external' => true,
		);
	}

	if ( 'Confirmed' === $event['status'] && function_exists( 'law_speaker_event_link' ) ) {
		$actions[] = array(
			'label' => __( 'View listing', 'law' ),
			'url'   => law_speaker_event_link( $event['id'] ),
			'arrow' => true,
		);
	}

	return $actions;
}

/**
 * Extra meta lines for a host's event card (payment status when recorded).
 *
 * @param array $entry Raw form 2 entry.
 * @return string[]
 */
function law_account_event_meta_lines( $entry ) {
	$lines = array();

	$payment = trim( (string) rgar( $entry, '96' ) );
	if ( '' !== $payment ) {
		$lines[] = sprintf(
			/* translators: %s: payment status (Unpaid / Paid / Refunded / Free) */
			__( 'Payment status: %s', 'law' ),
			$payment
		);
	}

	return $lines;
}
