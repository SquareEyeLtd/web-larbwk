<?php
/**
 * The host events dashboard, /account/events/ (templates/account-events.php).
 *
 * The listing is theme-owned and branches on law_events_source(): on the
 * custom CPT path it reads law_event posts owned or co-owned by the current
 * user and links to the custom edit form (templates/account-event-form.php).
 * The legacy path (entries from GFAPI form 2, edited via the GravityView 386
 * "Events (hosts)" view) is retained only until the pre-cutover source is
 * decommissioned.
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
	if ( $user_id < 1 ) {
		return $items;
	}

	// CPT mode: the user's own and co-owned events, drafts included, with a
	// faux minimal entry so the card helpers keep one code path.
	if ( 'cpt' === law_events_source() ) {
		foreach ( law_events_owned_event_ids( $user_id ) as $post_id ) {
			$event = law_events_map_post( $post_id, array( '*' ) );
			if ( ! $event ) {
				continue;
			}
			$items[] = array(
				'event' => $event,
				'entry' => array(
					'id' => $post_id,
					'83' => (string) law_event_meta( $post_id, '_law_stripe_invoice_url' ),
					'96' => ucfirst( (string) law_event_meta( $post_id, '_law_payment_status' ) ),
				),
			);
		}
		usort( $items, fn( $a, $b ) => strcmp( $a['event']['sort'], $b['event']['sort'] ) );
		return $items;
	}

	if ( ! class_exists( 'GFAPI' ) ) {
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
	if ( 'cpt' === law_events_source() ) {
		return is_array( $entry )
			? add_query_arg( 'law_event', (int) ( $entry['id'] ?? 0 ), law_account_events_submit_url() )
			: '';
	}
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
	if ( 'cpt' === law_events_source() ) {
		$page = get_page_by_path( 'account/events' );
		return $page ? add_query_arg( 'law_thread', (int) $entry_id, get_permalink( $page ) ) : '';
	}
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

	// Bookings (n): the per-event attendee list, n = total attendees across
	// active bookings, not the booking count (EVENTS_BOOKINGS.md §7.4).
	// Confirmed events only — nothing else can hold a booking.
	if ( 'cpt' === law_events_source() && 'Confirmed' === $event['status'] && function_exists( 'law_booking_list_url' ) ) {
		$actions[] = array(
			'label' => sprintf( __( 'Bookings (%s)', 'law' ), number_format_i18n( law_event_attendee_total( (int) $event['id'] ) ) ),
			'url'   => law_booking_list_url( (int) $event['id'] ),
		);
	}

	// Pre-approval events can be withdrawn by their host (CPT mode only): a
	// form-shaped action the card renders as a nonce'd POST behind a confirm
	// modal. Approved/Confirmed events go through the committee's Cancel
	// instead, because money and programme slots are involved by then.
	if ( 'cpt' === law_events_source()
		&& in_array( $event['status'], array( 'Draft', 'Proposed', 'Sent back' ), true ) ) {
		$event_id  = (int) $event['id'];
		$actions[] = array(
			'label' => __( 'Withdraw', 'law' ),
			'form'  => array(
				'action'   => 'law_event_withdraw',
				'nonce'    => 'law_event_withdraw',
				'event_id' => $event_id,
				'modal'    => array(
					'id'      => 'law-modal-withdraw-' . $event_id,
					'title'   => __( 'Withdraw this event', 'law' ),
					'copy'    => array(
						__( 'The event is cancelled and comes out of the committee\'s review. It cannot be resubmitted: if you change your mind later, you will need to submit a new event.', 'law' ),
						__( 'The committee is notified of the withdrawal.', 'law' ),
					),
					'field'   => array(
						'name'     => 'law_withdraw_reason',
						'label'    => __( 'Why are you withdrawing? (optional)', 'law' ),
						'help'     => __( 'Shared with the committee and posted to the event thread.', 'law' ),
						'rows'     => 3,
						'required' => false,
					),
					'confirm' => array( 'label' => __( 'Withdraw event', 'law' ), 'class' => 'button alert', 'busy' => __( 'Withdrawing…', 'law' ) ),
					'close'   => __( 'Keep the event', 'law' ),
				),
			),
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
