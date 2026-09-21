<?php
/**
 * The wp-admin booking screen and list columns (EVENTS_BOOKINGS.md §10).
 *
 * Read-only in v1: every mutation runs through the engine (bookings.php) so
 * the guards, seat recount and emails always fire — wp-admin's job here is
 * inspection. The CPT registers create_posts as do_not_allow, and the status
 * guard in workflow.php covers quick edit.
 *
 * ONE exception, added 15 September 2026: Ticket type on a flagship booking.
 * It is the only editable field on this screen and the theme's only
 * save_post_law_booking handler. It is safe here precisely because it is not
 * booking machinery: no guard, no capacity recount, no email, no status and no
 * price reads it. It is a label the committee keeps for their own records and
 * their exports, and the front-end dashboard writes it through the same
 * law_flagship_set_ticket_type() this box does, so both routes log the same
 * line. Nothing else belongs here; anything that moves a place or money still
 * goes through the front end.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Meta boxes _________________________________________________________________ */

add_action( 'add_meta_boxes_' . LAW_BOOKING_CPT, function ( $post ) {
	// The editor and slug boxes are noise on a read-only record.
	remove_meta_box( 'slugdiv', LAW_BOOKING_CPT, 'normal' );
	add_meta_box( 'law-booking-facts', 'Booking', 'law_booking_box_facts', LAW_BOOKING_CPT, 'normal', 'high' );
	// Its own box rather than a field in the facts table, which is a read-only
	// <table> and should stay one. Flagship bookings only: a hosted place and a
	// reception ticket are not classified this way, and an empty select on
	// every booking in the site would just be a question nobody can answer.
	if ( 'flagship' === law_booking_kind( $post ) ) {
		add_meta_box( 'law-booking-ticket-type', 'Ticket type', 'law_booking_box_ticket_type', LAW_BOOKING_CPT, 'side' );
	}
	add_meta_box( 'law-booking-activity', 'Activity', 'law_booking_box_activity', LAW_BOOKING_CPT, 'normal' );
} );

/**
 * The committee's ticket type, the one editable field on this screen.
 *
 * Deliberately not a route to anything else: see the file header.
 */
function law_booking_box_ticket_type( $post ) {
	wp_nonce_field( 'law_booking_admin_save', 'law_booking_admin_nonce' );
	law_field_select(
		'law_ticket_type',
		'Ticket type',
		(string) law_event_meta( $post->ID, '_law_ticket_type' ),
		law_booking_ticket_types(),
		array( 'placeholder' => 'Not set' )
	);
	echo '<p class="description">' . esc_html__( 'For the committee\'s own records and exports. The delegate never sees it, and it changes nothing about their place or their price. The Flagship bookings dashboard sets the same field.', 'law' ) . '</p>';
}

/**
 * Save it.
 *
 * edit_law_events, not manage_options: committee members hold the whole
 * law_event capability set and no administrator rights, so manage_options
 * would lock out exactly the people the field is for, and buy nothing —
 * everyone who can reach this screen can already read the whole delegate list.
 *
 * law_flagship_set_ticket_type() rather than a bare meta write, so this route
 * and the dashboard's validate the same way and write the same activity-log
 * line.
 */
add_action( 'save_post_' . LAW_BOOKING_CPT, function ( $post_id, $post ) {
	if ( ! isset( $_POST['law_booking_admin_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['law_booking_admin_nonce'] ) ), 'law_booking_admin_save' )
		|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
		|| wp_is_post_revision( $post_id )
		|| ! current_user_can( 'edit_law_events' )
		|| 'flagship' !== law_booking_kind( $post )
	) {
		return;
	}

	law_flagship_set_ticket_type(
		(int) $post_id,
		sanitize_key( wp_unslash( (string) ( $_POST['law_ticket_type'] ?? '' ) ) ),
		get_current_user_id()
	);
}, 10, 2 );

/**
 * Number, status, dates, the attendee, who invited them, the parent event
 * (edit screen + front-end list), and — for a booking that was paid for — the
 * money: amount, payment state, card, VAT receipt, consent and who decided.
 * One booking is one attendee, so there is no separate attendee box.
 */
function law_booking_box_facts( $post ) {
	$event    = get_post( (int) $post->post_parent );
	$attendee = get_user_by( 'id', (int) $post->post_author );
	$person   = law_booking_attendee( $post );
	$booker   = get_user_by( 'id', (int) law_event_meta( $post->ID, '_law_booked_by' ) );
	$facts    = array_filter( array( $person['organisation'], $person['job_title'] ) );

	echo '<table class="widefat striped"><tbody>';
	$rows = array(
		'Booking number' => '#' . (int) law_event_meta( $post->ID, '_law_booking_number' ),
		'Status'         => esc_html( law_booking_status_label( $post ) ),
		'Created'        => mysql2date( 'j F Y, H:i', $post->post_date ),
		'Event'          => $event
			? '<a href="' . esc_url( get_edit_post_link( $event->ID ) ) . '">' . esc_html( $event->post_title ) . '</a>'
				. ' · <a href="' . esc_url( law_booking_list_url( $event->ID ) ) . '">' . esc_html__( 'front-end bookings list', 'law' ) . '</a>'
			: '(missing)',
		'Attendee'       => ( $attendee
				? '<a href="' . esc_url( get_edit_user_link( $attendee->ID ) ) . '">' . esc_html( $person['name'] ?: $attendee->display_name ) . '</a>'
				: esc_html( $person['name'] ) . ' <em>(missing account)</em>' )
			. ' (' . esc_html( $person['email'] ) . ')'
			. ( $facts ? '<br><span class="description">' . esc_html( implode( ', ', $facts ) ) . '</span>' : '' )
			. ( law_event_meta( $post->ID, '_law_is_press' ) ? ' <strong>Press pass</strong>' : '' ),
		'Invited by'     => law_booking_is_self_booked( $post )
			? 'Themselves'
			: ( $booker
				? '<a href="' . esc_url( get_edit_user_link( $booker->ID ) ) . '">' . esc_html( $booker->display_name ) . '</a>'
				: 'a deleted account (ID ' . (int) law_event_meta( $post->ID, '_law_booked_by' ) . ')' ),
	);
	if ( 'law-waitlisted' === $post->post_status ) {
		$rows['Waitlist position'] = (int) law_event_meta( $post->ID, '_law_waitlist_position' );
		$rows['Joined the waitlist'] = (string) law_event_meta( $post->ID, '_law_waitlist_joined' ) ?: '—';
	}
	$promoted = (string) law_event_meta( $post->ID, '_law_waitlist_promoted' );
	if ( '' !== $promoted ) {
		$rows['Promoted from the waitlist'] = $promoted;
	}

	// The money, for a booking that has any (the flagship's applications
	// today; any priced booking tomorrow). Without this an administrator
	// opening a paid place in wp-admin could see no price, no payment state
	// and no invoice — the one screen where the whole record is supposed to
	// be inspectable showed everything except what was charged.
	$payment = (string) law_event_meta( $post->ID, '_law_payment_status' );
	if ( '' !== $payment ) {
		$price   = law_booking_price( $post->ID );
		$states  = function_exists( 'law_flagship_payment_states' ) ? law_flagship_payment_states() : array();
		$invoice = (string) law_event_meta( $post->ID, '_law_stripe_invoice_url' );
		$pdf     = (string) law_event_meta( $post->ID, '_law_stripe_invoice_pdf' );
		$method  = function_exists( 'law_booking_payment_method_label' ) ? law_booking_payment_method_label( $post->ID ) : '';
		$error   = (string) law_event_meta( $post->ID, '_law_payment_error' );
		$consent = (string) law_event_meta( $post->ID, '_law_payment_consent_at' );
		$by      = (int) law_event_meta( $post->ID, '_law_reviewed_by' );
		$reviewer = $by ? get_user_by( 'id', $by ) : null;

		$rows['Payment'] = esc_html( $states[ $payment ] ?? ucfirst( str_replace( '_', ' ', $payment ) ) )
			. ( '' !== $error ? '<br><span class="description">' . esc_html( $error ) . '</span>' : '' );

		$rows['Amount'] = law_event_meta( $post->ID, '_law_is_complimentary' )
			? 'No charge (complimentary)'
			: esc_html( law_events_format_pence( $price['gross'] ) )
				. ( $price['vatable']
					? ' <span class="description">(' . esc_html( law_events_format_pence( $price['net'] ) ) . ' plus '
						. esc_html( law_events_format_pence( $price['vat'] ) ) . ' VAT)</span>'
					: '' );

		if ( '' !== $method ) {
			$rows['Payment method on file'] = esc_html( $method );
		}
		if ( '' !== $invoice || '' !== $pdf ) {
			$links = array();
			if ( '' !== $invoice ) {
				$links[] = '<a href="' . esc_url( $invoice ) . '" target="_blank" rel="noopener">Stripe invoice</a>';
			}
			if ( '' !== $pdf ) {
				$links[] = '<a href="' . esc_url( $pdf ) . '" target="_blank" rel="noopener">PDF</a>';
			}
			$rows['VAT receipt'] = implode( ' · ', $links );
		}
		if ( '' !== $consent ) {
			$rows['Consent to charge'] = esc_html( $consent ) . ' <span class="description">(UTC)</span>';
		}
		if ( $reviewer ) {
			$rows['Decided by'] = '<a href="' . esc_url( get_edit_user_link( $reviewer->ID ) ) . '">' . esc_html( $reviewer->display_name ) . '</a>'
				. ( law_event_meta( $post->ID, '_law_reviewed_at' ) ? ' <span class="description">' . esc_html( (string) law_event_meta( $post->ID, '_law_reviewed_at' ) ) . '</span>' : '' );
		}
		if ( '' !== (string) law_event_meta( $post->ID, '_law_decline_reason' ) ) {
			$rows['Reason given'] = esc_html( (string) law_event_meta( $post->ID, '_law_decline_reason' ) );
		}
		// Who held this place before, and therefore who the invoice above
		// actually belongs to. This screen is where the committee looks when a
		// receipt query arrives, and after a substitution the attendee named at
		// the top of it is not the person who paid (21 September 2026).
		$law_bs_from = (int) law_event_meta( $post->ID, '_law_substituted_from' );
		if ( $law_bs_from ) {
			$law_bs_name  = (string) law_event_meta( $post->ID, '_law_substituted_from_name' );
			$law_bs_email = (string) law_event_meta( $post->ID, '_law_substituted_from_email' );
			$law_bs_when  = (string) law_event_meta( $post->ID, '_law_substituted_at' );
			$law_bs_user  = get_user_by( 'id', $law_bs_from );
			$rows['Substituted from'] = ( $law_bs_user
					? '<a href="' . esc_url( get_edit_user_link( $law_bs_user->ID ) ) . '">' . esc_html( $law_bs_name ) . '</a>'
					: esc_html( $law_bs_name ) . ' <span class="description">(account since removed)</span>' )
				. ( '' !== $law_bs_email ? ' <span class="description">' . esc_html( $law_bs_email ) . '</span>' : '' )
				. ( '' !== $law_bs_when ? ' <span class="description">on ' . esc_html( $law_bs_when ) . '</span>' : '' )
				. '<br><span class="description">The payment, the invoice and the VAT receipt above stay with this person. Nothing was refunded.</span>';
		}
	}
	foreach ( $rows as $label => $value ) {
		printf( '<tr><th style="width:12em">%s</th><td>%s</td></tr>', esc_html( $label ), wp_kses_post( $value ) );
	}
	echo '</tbody></table>';
	echo '<p class="description">Bookings are managed from the front end (the attendee\'s manage view, and the host/committee bookings list) so the capacity, duplicate and clash guards always run; this screen is read-only.</p>';
}

/**
 * The parent event's activity log filtered to this booking (context carries
 * booking => <id>; there is no separate booking log by design).
 */
function law_booking_box_activity( $post ) {
	$event_id = (int) $post->post_parent;
	$entries  = $event_id ? law_event_log_entries( $event_id ) : array();
	$shown    = 0;
	echo '<ul class="law-log">';
	foreach ( $entries as $entry ) {
		$context = law_event_log_context( $entry->comment_ID );
		if ( (int) ( $context['booking'] ?? 0 ) !== (int) $post->ID ) {
			continue;
		}
		$shown++;
		printf(
			'<li class="law-log__item is-system"><span class="law-log__date">%s</span> <strong>%s</strong><br>%s</li>',
			esc_html( mysql2date( 'j M Y, H:i', $entry->comment_date ) ),
			esc_html( $entry->comment_author ?: 'System' ),
			wp_kses_post( wpautop( $entry->comment_content ) )
		);
	}
	echo '</ul>';
	if ( ! $shown ) {
		echo '<p>No activity recorded for this booking.</p>';
	}
	if ( $event_id ) {
		echo '<p class="description">The full stream (all bookings, workflow, emails) lives on the <a href="' . esc_url( admin_url( 'post.php?post=' . $event_id . '&action=edit' ) ) . '">event</a>.</p>';
	}
}

/* List columns _______________________________________________________________ */

add_filter( 'manage_' . LAW_BOOKING_CPT . '_posts_columns', function ( $columns ) {
	return array(
		'cb'              => $columns['cb'] ?? '',
		'title'           => 'Booking',
		'law_bk_event'    => 'Event',
		'law_bk_attendee' => 'Attendee',
		'law_bk_owner'    => 'Invited by',
		'law_bk_status'   => 'Status',
		'date'            => 'Date',
	);
} );

add_action( 'manage_' . LAW_BOOKING_CPT . '_posts_custom_column', function ( $column, $post_id ) {
	switch ( $column ) {
		case 'law_bk_event':
			$event = get_post( (int) get_post_field( 'post_parent', $post_id ) );
			echo $event
				? '<a href="' . esc_url( get_edit_post_link( $event->ID ) ) . '">' . esc_html( $event->post_title ) . '</a>'
				: '&mdash;';
			break;
		case 'law_bk_attendee':
			$person   = law_booking_attendee( $post_id );
			$attendee = get_user_by( 'id', (int) get_post_field( 'post_author', $post_id ) );
			echo esc_html( $person['name'] ?: ( $attendee ? $attendee->display_name : '' ) ) ?: '&mdash;';
			if ( law_event_meta( $post_id, '_law_is_press' ) ) {
				echo ' <strong>' . esc_html__( 'Press', 'law' ) . '</strong>';
			}
			break;
		case 'law_bk_owner':
			$label = law_booking_invited_by_label( $post_id );
			echo '' !== $label ? esc_html( $label ) : '&mdash;';
			break;
		case 'law_bk_status':
			echo esc_html( law_booking_status_label( get_post( $post_id ) ) );
			break;
	}
}, 10, 2 );

/* The events list's Booked column ____________________________________________ */

add_filter( 'manage_' . LAW_EVENT_CPT . '_posts_columns', function ( $columns ) {
	// After Payment: sold / available, red when the committee lowered the
	// ticket number below what is already sold.
	$out = array();
	foreach ( $columns as $key => $label ) {
		$out[ $key ] = $label;
		if ( 'law_payment' === $key ) {
			$out['law_booked'] = 'Booked';
		}
	}
	if ( ! isset( $out['law_booked'] ) ) {
		$out['law_booked'] = 'Booked';
	}
	return $out;
}, 20 );

add_action( 'manage_' . LAW_EVENT_CPT . '_posts_custom_column', function ( $column, $post_id ) {
	if ( 'law_booked' !== $column ) {
		return;
	}
	$available = (int) law_event_meta( $post_id, '_law_tickets_available' );
	$sold      = law_event_attendee_total( $post_id );
	if ( $available < 1 && ! $sold ) {
		echo '&mdash;';
		return;
	}
	$label = $available > 0 ? $sold . ' / ' . $available : (string) $sold;
	echo $sold > $available && $available > 0
		? '<span style="color:#b32d2e;font-weight:600">' . esc_html( $label ) . '</span>'
		: esc_html( $label );
}, 10, 2 );
