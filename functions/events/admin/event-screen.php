<?php
/**
 * The law_event wp-admin edit screen: custom meta boxes for facts, fee and
 * invoice, workflow actions, people, speakers, the comment thread and the
 * activity log (EVENTS_4.1_REBUILD.md §3, admin editing note).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'add_meta_boxes_' . LAW_EVENT_CPT, function () {
	add_meta_box( 'law-event-workflow', 'Workflow', 'law_event_box_workflow', LAW_EVENT_CPT, 'side', 'high' );
	add_meta_box( 'law-event-fee', 'Fee & invoice', 'law_event_box_fee', LAW_EVENT_CPT, 'side' );
	add_meta_box( 'law-event-flags', 'Classification', 'law_event_box_flags', LAW_EVENT_CPT, 'side' );
	add_meta_box( 'law-event-facts', 'Event details', 'law_event_box_facts', LAW_EVENT_CPT, 'normal', 'high' );
	add_meta_box( 'law-event-invoice-contact', 'Invoice contact', 'law_event_box_invoice_contact', LAW_EVENT_CPT, 'normal' );
	add_meta_box( 'law-event-people', 'Owners & contacts', 'law_event_box_people', LAW_EVENT_CPT, 'normal' );
	add_meta_box( 'law-event-speakers', 'Speakers', 'law_event_box_speakers', LAW_EVENT_CPT, 'normal' );
	add_meta_box( 'law-event-sessions', 'Sessions', 'law_event_box_sessions', LAW_EVENT_CPT, 'normal' );
	add_meta_box( 'law-event-thread', 'Comments thread', 'law_event_box_thread', LAW_EVENT_CPT, 'normal' );
	add_meta_box( 'law-event-log', 'Activity log', 'law_event_box_log', LAW_EVENT_CPT, 'normal' );
} );

function law_event_box_workflow( $post ) {
	wp_nonce_field( 'law_event_admin_save', 'law_event_admin_nonce' );
	$status = law_event_status_label( $post );
	echo '<p>Status: <strong>' . esc_html( $status ) . '</strong></p>';
	echo '<p>Reference: <code>' . esc_html( (string) law_event_meta( $post->ID, '_law_reference' ) ) . '</code></p>';
	$approved = (string) law_event_meta( $post->ID, '_law_approved_at' );
	if ( $approved ) {
		echo '<p>Approved: ' . esc_html( $approved ) . '</p>';
	}

	$assignees = array( '' => '(none)' );
	foreach ( law_events_committee_users() as $user ) {
		$assignees[ $user->ID ] = $user->display_name;
	}
	law_field_select( 'law_assignee', 'Committee assignee', (string) law_event_meta( $post->ID, '_law_assignee' ), $assignees );

	// Only the actions legal for the current status are offered, from the same
	// from-lists the workflow engine enforces (law_event_available_ui_actions()).
	$labels = array(
		'approve'   => 'Approve',
		'send_back' => 'Send back',
		'reject'    => 'Reject',
		'mark_paid' => 'Mark paid & confirm',
		'cancel'    => 'Cancel event',
	);
	$available = law_event_available_ui_actions( $post );
	if ( ! $available ) {
		echo '<hr><p class="description">No workflow actions apply to this status.</p>';
		return;
	}
	echo '<hr><p><strong>Actions</strong></p>';
	echo '<p class="law-workflow-actions">';
	foreach ( $available as $action ) {
		printf(
			'<label style="display:block;margin-bottom:4px"><input type="radio" name="law_workflow_action" value="%s"> %s</label>',
			esc_attr( $action ),
			esc_html( $labels[ $action ] )
		);
	}
	echo '</p>';
	law_field_textarea( 'law_workflow_note', 'Comment / reason (required for Send back, Reject and Cancel)', '', 3 );
	echo '<p class="description">Choose an action and Update. Guards apply: an action illegal for the current status is refused with a notice.</p>';
}

/**
 * The committee's two classification switches, mirroring the dashboard
 * sidebar so wp-admin ("Full editing in wp-admin") is not a dead end.
 *
 * Its own box rather than an addition to the Workflow box: that one returns
 * early when no workflow actions apply, so anything appended to it would
 * silently vanish on cancelled and rejected events. And a benign config flag
 * does not belong beside the irreversible Approve / Reject / Cancel radios.
 */
function law_event_box_flags( $post ) {
	law_field_checkbox( 'law_is_law_event', 'Run by LAW, not an external host', (bool) law_event_meta( $post->ID, '_law_is_law_event' ) );
	law_field_checkbox( 'law_session_agenda', 'This event has a session agenda', (bool) law_event_meta( $post->ID, '_law_session_agenda' ) );

	$sessions = count( law_event_session_ids( $post->ID ) );
	if ( $sessions && ! law_event_meta( $post->ID, '_law_session_agenda' ) ) {
		// law_event_has_session_agenda() keeps the form section while sessions
		// exist, so the unticked box is not the whole story.
		printf(
			'<p class="description">%s</p>',
			esc_html(
				sprintf(
					_n(
						'This event still has %d session, so the Session agenda section stays on its form until it is deleted.',
						'This event still has %d sessions, so the Session agenda section stays on its form until they are deleted.',
						$sessions,
						'law'
					),
					$sessions
				)
			)
		);
	}
}

function law_event_box_fee( $post ) {
	$tiers = array();
	foreach ( (array) law_events_setting( 'fee_tiers', array() ) as $key => $tier ) {
		$tiers[ $key ] = $tier['label'] . ' (£' . $tier['amount'] . ')';
	}
	law_field_select( 'law_fee_tier', 'Fee tier', (string) law_event_meta( $post->ID, '_law_fee_tier' ), $tiers, array( 'placeholder' => '(none)' ) );
	law_field_checkbox( 'law_fee_override', 'Override the host fee', (bool) law_event_meta( $post->ID, '_law_fee_override' ) );
	law_field_number( 'law_fee_override_amount', 'New host fee (£)', (string) law_event_meta( $post->ID, '_law_fee_override_amount' ), array( 'attrs' => 'step="0.01" min="0"' ) );
	echo '<p class="description">Enter the agreed fee in pounds, without the symbol. Type 0 to waive the fee entirely. A ticked box with an empty amount is refused rather than read as £0.00, so a blank can never waive a fee by accident.</p>';

	$fee = (int) law_event_meta( $post->ID, '_law_fee_pence' );
	echo '<p>Snapshot at approval: <strong>' . esc_html( law_events_format_pence( $fee ) ) . '</strong>'
		. ( law_event_meta( $post->ID, '_law_vat' ) ? ' + VAT' : '' ) . '</p>';

	// This screen is the only route left for a post-approval fee change: the
	// committee dashboard's control goes read-only at approval, because nothing
	// there re-freezes the snapshot. Saving a changed fee here does re-freeze it
	// (law_event_resnapshot_fee()), but Stripe is never touched automatically,
	// so say what the second half of the job is.
	if ( law_event_fee_override_locked( $post->ID ) ) {
		echo '<div class="notice notice-warning inline"><p>This event is approved, so its fee is already snapshotted and invoiced. '
			. 'Changing the tier or the override here re-takes the snapshot and logs it, but the Stripe invoice is <strong>not</strong> reissued: '
			. 'void the open invoice in Stripe, then raise a new one with the invoice button below (offered while the event is Approved and unpaid).</p></div>';
	}

	law_field_select(
		'law_payment_status',
		'Payment status',
		(string) law_event_meta( $post->ID, '_law_payment_status' ),
		array( 'unpaid' => 'Unpaid', 'paid' => 'Paid', 'refunded' => 'Refunded', 'free' => 'Free' )
	);

	$invoice_id  = (string) law_event_meta( $post->ID, '_law_stripe_invoice_id' );
	$invoice_url = (string) law_event_meta( $post->ID, '_law_stripe_invoice_url' );
	if ( $invoice_id ) {
		echo '<p>Invoice: <code>' . esc_html( $invoice_id ) . '</code></p>';
	}
	if ( $invoice_url ) {
		echo '<p><a href="' . esc_url( $invoice_url ) . '" target="_blank" rel="noopener">Hosted invoice page ↗</a></p>';
	}

	$error = law_event_meta( $post->ID, '_law_stripe_error' );
	if ( is_array( $error ) && ! empty( $error['message'] ) ) {
		echo '<div class="notice notice-error inline"><p><strong>Stripe error:</strong> '
			. esc_html( $error['message'] ) . '<br><em>' . esc_html( $error['at'] ?? '' ) . '</em></p></div>';
	}
	if ( 'law-approved' === $post->post_status && $fee > 0 ) {
		$retry = wp_nonce_url(
			admin_url( 'admin-post.php?action=law_event_retry_invoice&event_id=' . (int) $post->ID ),
			'law_event_retry_invoice'
		);
		echo '<p><a class="button" href="' . esc_url( $retry ) . '">' . ( $invoice_id ? 'Re-send / retry invoice' : 'Create invoice' ) . '</a></p>';
	}
}

function law_event_box_facts( $post ) {
	$slots = array();
	foreach ( law_events_slots( true ) as $slot ) {
		$slots[ $slot['label'] ] = $slot['label'] . ( $slot['retired'] ? ' (retired)' : '' );
	}
	// Normalised, so a slot stored with different dash punctuation still shows
	// as the selected option rather than reading as "not confirmed".
	$slot_current = law_events_normalise_slot_label( law_event_meta( $post->ID, '_law_slot_label' ) );
	law_field_select( 'law_slot_label', 'Confirmed slot', $slot_current, $slots, array( 'placeholder' => 'Slot not confirmed' ) );
	law_field_datetime( 'law_start', 'Start (set from the slot when chosen)', (string) law_event_meta( $post->ID, '_law_start' ) );
	law_field_datetime( 'law_end', 'End', (string) law_event_meta( $post->ID, '_law_end' ) );

	$preferred = law_event_meta( $post->ID, '_law_preferred_slots' );
	if ( $preferred ) {
		echo '<p><strong>Host\'s preferred slots</strong><br>' . esc_html( implode( '; ', $preferred ) ) . '</p>';
	}

	law_field_text( 'law_venue', 'Venue', (string) law_event_meta( $post->ID, '_law_venue' ) );
	law_field_select(
		'law_venue_needed',
		'Venue needed',
		(string) law_event_meta( $post->ID, '_law_venue_needed' ),
		array(
			'Yes, please share our details with venue hosts' => 'Yes, please share our details with venue hosts',
			'No, we already have a venue planned'            => 'No, we already have a venue planned',
		),
		array( 'placeholder' => '(not set)' )
	);
	law_field_select(
		'law_venue_capacity',
		'Venue capacity',
		(string) law_event_meta( $post->ID, '_law_venue_capacity' ),
		array_combine( array_keys( law_events_venue_capacity_bands() ), array_keys( law_events_venue_capacity_bands() ) ),
		array( 'placeholder' => '(not set)' )
	);
	law_field_number( 'law_tickets_available', 'Tickets available', (string) law_event_meta( $post->ID, '_law_tickets_available' ) );
	law_field_text( 'law_host_organisations', 'Host organisation(s)', (string) law_event_meta( $post->ID, '_law_host_organisations' ) );
	law_field_relationship( 'law_organisation_ids', 'Linked organisations (sponsor highlighting)', law_event_meta( $post->ID, '_law_organisation_ids' ), 'organisation', true );
	law_field_text( 'law_sector_jurisdiction', 'Jurisdiction-specific: please specify', (string) law_event_meta( $post->ID, '_law_sector_jurisdiction' ) );
	law_field_text( 'law_sector_other', 'Other / sector-neutral: please specify', (string) law_event_meta( $post->ID, '_law_sector_other' ) );
}

function law_event_box_invoice_contact( $post ) {
	law_field_text( 'law_invoice_name', 'Invoice contact name', (string) law_event_meta( $post->ID, '_law_invoice_name' ) );
	law_field_text( 'law_invoice_email', 'Invoice contact email', (string) law_event_meta( $post->ID, '_law_invoice_email' ), array( 'type' => 'email' ) );
	$address = law_event_meta( $post->ID, '_law_invoice_address' );
	foreach ( array( 'line1' => 'Address line 1', 'line2' => 'Address line 2', 'city' => 'City', 'state' => 'County / state', 'postal_code' => 'Postcode' ) as $part => $label ) {
		law_field_text( 'law_invoice_address_' . $part, $label, (string) ( $address[ $part ] ?? '' ) );
	}
	// Country matches the front-end forms: the registration country list with a
	// "Select country" placeholder, so a typo here can never break the ISO
	// derivation. A stored value that is not on the list is kept as its own
	// option (migrated events), and with no list available (Gravity Forms gone)
	// the field falls back to free text.
	$country   = (string) ( $address['country'] ?? '' );
	$countries = law_registration_country_choices();
	if ( $countries && '' !== $country && ! in_array( $country, $countries, true ) ) {
		$countries[] = $country;
	}
	if ( $countries ) {
		law_field_select(
			'law_invoice_address_country',
			'Country',
			$country,
			array_combine( $countries, $countries ),
			array( 'placeholder' => 'Select country' )
		);
	} else {
		law_field_text( 'law_invoice_address_country', 'Country', $country );
	}
	law_field_text( 'law_country_iso', 'Country ISO (derived from the country name on save; only used when the name cannot be matched)', (string) law_event_meta( $post->ID, '_law_country_iso' ), array( 'class' => 'small-text' ) );
	law_field_text( 'law_vat_number', 'VAT number', (string) law_event_meta( $post->ID, '_law_vat_number' ) );
}

function law_event_box_people( $post ) {
	$columns = array(
		'name'         => array( 'label' => 'Name' ),
		'organisation' => array( 'label' => 'Organisation' ),
		'email'        => array( 'label' => 'Email', 'type' => 'email' ),
	);
	law_field_repeater( 'law_co_owner_rows', 'Additional event owners (accounts are created on approval)', law_event_meta( $post->ID, '_law_co_owner_rows' ), $columns, 'Add co-owner' );

	$co_owner_ids = law_event_meta( $post->ID, '_law_co_owner_ids' );
	if ( $co_owner_ids ) {
		echo '<p><strong>Linked co-owner accounts:</strong> ';
		$links = array();
		foreach ( $co_owner_ids as $user_id ) {
			$user = get_user_by( 'id', (int) $user_id );
			if ( $user ) {
				$links[] = '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">' . esc_html( $user->display_name ) . '</a>';
			}
		}
		echo wp_kses_post( implode( ', ', $links ) ) . '</p>';
	}

	law_field_repeater( 'law_contacts', 'Event contacts', law_event_meta( $post->ID, '_law_contacts' ), $columns, 'Add contact' );
}

function law_event_box_speakers( $post ) {
	law_field_relationship( 'law_speakers', 'Speakers (searched by name; add new people under Events → Speakers)', law_event_meta( $post->ID, '_law_speakers' ), LAW_SPEAKER_CPT );
}

function law_event_box_sessions( $post ) {
	$sessions = law_event_session_ids( $post->ID );
	if ( ! $sessions ) {
		echo '<p>No sessions. Add them under Events → Sessions, choosing this event as the parent.</p>';
		return;
	}
	echo '<ul>';
	foreach ( $sessions as $session_id ) {
		printf(
			'<li><a href="%s">%s</a> (%s–%s)</li>',
			esc_url( get_edit_post_link( $session_id ) ),
			esc_html( get_the_title( $session_id ) ),
			esc_html( (string) law_event_meta( $session_id, '_law_start_time' ) ),
			esc_html( (string) law_event_meta( $session_id, '_law_end_time' ) )
		);
	}
	echo '</ul>';
}

function law_event_box_thread( $post ) {
	$comments = law_event_comments( $post->ID );
	if ( ! $comments ) {
		echo '<p>No comments yet.</p>';
	} else {
		echo '<ul class="law-thread">';
		foreach ( $comments as $comment ) {
			printf(
				'<li class="law-thread__item %s"><strong>%s</strong> <span class="law-thread__badge">%s</span> <em>%s</em><br>%s</li>',
				law_event_comment_is_committee( $comment ) ? 'is-committee' : 'is-host',
				esc_html( $comment->comment_author ),
				law_event_comment_is_committee( $comment ) ? 'Committee' : 'Host',
				esc_html( mysql2date( 'j M Y, H:i', $comment->comment_date ) ),
				wp_kses_post( wpautop( $comment->comment_content ) )
			);
		}
		echo '</ul>';
	}
	law_field_textarea( 'law_thread_reply', 'Reply (sends the usual notification)', '', 3 );
}

function law_event_box_log( $post ) {
	$entries = law_event_log_entries( $post->ID );
	law_field_textarea( 'law_log_note', 'Add a private note', '', 2 );
	if ( ! $entries ) {
		echo '<p>No activity yet.</p>';
		return;
	}
	echo '<ul class="law-log">';
	foreach ( $entries as $entry ) {
		$context = law_event_log_context( $entry->comment_ID );
		printf(
			'<li class="law-log__item %s"><span class="law-log__date">%s</span> <strong>%s</strong><br>%s%s</li>',
			! empty( $context['manual'] ) ? 'is-manual' : 'is-system',
			esc_html( mysql2date( 'j M Y, H:i', $entry->comment_date ) ),
			esc_html( $entry->comment_author ?: 'System' ),
			wp_kses_post( wpautop( $entry->comment_content ) ),
			! empty( $context['source'] ) ? '<span class="law-log__source">' . esc_html( $context['source'] ) . '</span>' : ''
		);
	}
	echo '</ul>';
}

/* Save ______________________________________________________________________ */

add_action( 'save_post_' . LAW_EVENT_CPT, 'law_event_admin_save', 10, 2 );
function law_event_admin_save( $post_id, $post ) {
	// Reentrancy guard: a workflow transition inside this handler calls
	// wp_update_post, which re-fires save_post with the same $_POST; without
	// the guard, thread replies and notes would double up.
	static $running = false;
	if ( $running ) {
		return;
	}
	if ( ! isset( $_POST['law_event_admin_nonce'] )
		|| ! wp_verify_nonce( sanitize_key( $_POST['law_event_admin_nonce'] ), 'law_event_admin_save' )
		|| defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE
		|| wp_is_post_revision( $post_id )
		|| ! current_user_can( 'edit_law_events' )
	) {
		return;
	}
	$running = true;

	$actor = get_current_user_id();

	$before_override = (int) law_event_meta( $post_id, '_law_fee_override' );
	$before_amount   = (float) law_event_meta( $post_id, '_law_fee_override_amount' );
	$before_assignee = (int) law_event_meta( $post_id, '_law_assignee' );
	$before_payment  = (string) law_event_meta( $post_id, '_law_payment_status' );
	$before_tickets  = (int) law_event_meta( $post_id, '_law_tickets_available' );
	$before_tier     = (string) law_event_meta( $post_id, '_law_fee_tier' );
	$before_orgs     = (array) law_event_meta( $post_id, '_law_organisation_ids' );
	$before_flags    = array(
		'_law_is_law_event'   => (int) law_event_meta( $post_id, '_law_is_law_event' ),
		'_law_session_agenda' => (int) law_event_meta( $post_id, '_law_session_agenda' ),
	);

	// The flagship conference derives its start, end and speakers from its
	// sessions (law_flagship_recompute()), and holds no slot, so this screen
	// must not write those keys for it: the slot select would blank its
	// datetimes on the first Update. Everything else on the screen still saves,
	// and the derived values are refreshed at the end of this handler.
	$is_flagship = function_exists( 'law_flagship_is' ) && law_flagship_is( $post_id );

	$plain = array(
		'law_fee_tier'            => '_law_fee_tier',
		'law_slot_label'          => '_law_slot_label',
		'law_start'               => '_law_start',
		'law_end'                 => '_law_end',
		'law_venue'               => '_law_venue',
		'law_venue_needed'        => '_law_venue_needed',
		'law_venue_capacity'      => '_law_venue_capacity',
		'law_tickets_available'   => '_law_tickets_available',
		'law_host_organisations'  => '_law_host_organisations',
		'law_sector_jurisdiction' => '_law_sector_jurisdiction',
		'law_sector_other'        => '_law_sector_other',
		'law_invoice_name'        => '_law_invoice_name',
		'law_invoice_email'       => '_law_invoice_email',
		'law_vat_number'          => '_law_vat_number',
	);
	if ( $is_flagship ) {
		unset( $plain['law_slot_label'], $plain['law_start'], $plain['law_end'] );
	}
	foreach ( $plain as $field => $key ) {
		if ( isset( $_POST[ $field ] ) ) {
			law_event_update_meta( $post_id, $key, wp_unslash( $_POST[ $field ] ) );
		}
	}
	if ( isset( $_POST['law_assignee'] ) ) {
		law_event_update_meta( $post_id, '_law_assignee', law_events_sanitize_assignee( wp_unslash( $_POST['law_assignee'] ) ) );
	}

	// The override flag and its amount are written as a pair, and only when the
	// fee box was actually on the form: a ticked box with an empty amount would
	// sanitise to £0.00 and waive the fee, so it is refused rather than obeyed
	// (a deliberately typed 0 still waives it).
	if ( isset( $_POST['law_fee_override_amount'] ) ) {
		$override_on = ! empty( $_POST['law_fee_override'] );
		$amount_raw  = trim( (string) wp_unslash( $_POST['law_fee_override_amount'] ) );
		if ( $override_on && '' === $amount_raw ) {
			law_event_admin_notice(
				$actor,
				'Host fee override NOT saved: enter the new fee in pounds, or type 0 to waive the fee entirely.'
			);
		} else {
			law_event_update_meta( $post_id, '_law_fee_override_amount', $amount_raw );
			law_event_update_meta( $post_id, '_law_fee_override', $override_on );
		}
	}

	// A chosen slot fills the start/end datetimes; an emptied slot clears them
	// (shared helper, so this matches the committee dashboard save path).
	if ( ! $is_flagship ) {
		law_event_apply_slot_label( $post_id, sanitize_text_field( wp_unslash( $_POST['law_slot_label'] ?? '' ) ) );
	}

	// Invoice address parts + derived ISO.
	$address = array();
	foreach ( law_events_address_parts() as $part ) {
		$address[ $part ] = sanitize_text_field( wp_unslash( $_POST[ 'law_invoice_address_' . $part ] ?? '' ) );
	}
	law_event_update_meta( $post_id, '_law_invoice_address', $address );
	// The country name is the source of truth: a mapped name always wins, so a
	// stale value left in the ISO box cannot outlive a changed country. The box
	// is only a manual fallback for a name the map does not know.
	$iso     = sanitize_text_field( wp_unslash( $_POST['law_country_iso'] ?? '' ) );
	$derived = law_events_country_to_iso( $address['country'] );
	law_event_update_meta( $post_id, '_law_country_iso', '' !== $derived ? $derived : $iso );

	// Repeaters and relationships.
	law_event_update_meta( $post_id, '_law_co_owner_rows', law_events_rows_from_post( 'law_co_owner_rows' ) );
	// A co-owner added on an already-approved event gets their account now,
	// matching the host-edit path.
	if ( in_array( $post->post_status, array( 'law-approved', 'publish' ), true ) ) {
		law_event_ensure_co_owner_users( $post_id, $actor );
	}
	law_event_update_meta( $post_id, '_law_contacts', law_events_rows_from_post( 'law_contacts' ) );
	law_event_update_meta( $post_id, '_law_speakers', law_events_rows_from_post( 'law_speakers' ) );
	law_event_update_meta( $post_id, '_law_organisation_ids', array_map( 'absint', (array) ( $_POST['law_organisation_ids'] ?? array() ) ) );
	law_event_log_organisation_change( $post_id, $before_orgs, $actor );

	// Written unconditionally, NOT through the $plain map above: that loop is
	// guarded by isset( $_POST[ $field ] ) and an unticked checkbox posts
	// nothing, so a flag could be switched on here and then never off. The
	// nonce gate at the top of this handler already guarantees the box was on
	// the form (quick edit and bulk edit never carry law_event_admin_nonce).
	law_event_update_meta( $post_id, '_law_is_law_event', ! empty( $_POST['law_is_law_event'] ) );
	law_event_update_meta( $post_id, '_law_session_agenda', ! empty( $_POST['law_session_agenda'] ) );
	law_event_log_flag_change( $post_id, $before_flags, $actor );

	// Payment status change is an explicit, logged act.
	$new_payment = sanitize_key( $_POST['law_payment_status'] ?? '' );
	if ( $new_payment && $new_payment !== $before_payment ) {
		law_event_set_payment_status( $post_id, $new_payment, 'admin_edit', $actor );
	}

	law_event_log_fee_change( $post_id, $before_override, $before_amount, $actor );

	// An approved event's fee was frozen by law_event_snapshot_fee() and
	// invoiced from that snapshot, and nothing else recalculates it: re-freeze
	// it here so this screen stays a working route for a post-approval fee
	// change (the dashboard control is read-only from approval onwards).
	$fee_inputs_changed = $before_tier !== (string) law_event_meta( $post_id, '_law_fee_tier' )
		|| $before_override !== (int) law_event_meta( $post_id, '_law_fee_override' )
		|| abs( $before_amount - (float) law_event_meta( $post_id, '_law_fee_override_amount' ) ) >= 0.005;
	if ( $fee_inputs_changed && law_event_fee_override_locked( $post_id ) ) {
		$resnapshot = law_event_resnapshot_fee( $post_id, $actor );
		if ( is_wp_error( $resnapshot ) ) {
			law_event_admin_notice( $actor, $resnapshot->get_error_message() );
		} elseif ( $resnapshot['fee_pence'] !== $resnapshot['was'] ) {
			law_event_admin_notice(
				$actor,
				sprintf(
					'Fee snapshot updated from %s to %s. The Stripe invoice is NOT reissued automatically: void the open invoice in Stripe, then raise a new one with the invoice button on this screen (offered while the event is Approved and unpaid).',
					law_events_format_pence( $resnapshot['was'] ),
					law_events_format_pence( $resnapshot['fee_pence'] )
				)
			);
		}
	}

	law_event_maybe_notify_assignee( $post_id, $before_assignee, $actor );

	// Raising the places is the one way capacity opens without a cancellation,
	// so the waitlist is offered them here. Deliberately after every other
	// field is written: a save that moves the date AND raises the places must
	// not send an invitation carrying the old date.
	law_event_tickets_changed( $post_id, $before_tickets, (int) law_event_meta( $post_id, '_law_tickets_available' ), $actor, 'admin_edit' );

	// Thread reply and manual note.
	$reply = trim( (string) wp_unslash( $_POST['law_thread_reply'] ?? '' ) );
	if ( '' !== $reply ) {
		law_event_add_comment( $post_id, $reply, $actor );
		law_events_send( 'user_new_comment', $post_id );
	}
	$note = trim( (string) wp_unslash( $_POST['law_log_note'] ?? '' ) );
	if ( '' !== $note ) {
		law_event_log( $post_id, $note, array( 'action' => 'note', 'source' => 'ui' ), array( 'manual' => true, 'user_id' => $actor ) );
	}

	// A workflow action chosen on the box runs after the field writes, so an
	// approval uses the freshly saved override values for its fee snapshot.
	// Whitelisted the same way as the front-end dashboard handler: only an
	// action this box actually offers may reach the workflow engine, so a
	// hand-made Update cannot run a transition (such as 'confirm') that no
	// button exposes. law_event_ui_actions() is the shared list.
	$action = sanitize_key( $_POST['law_workflow_action'] ?? '' );
	if ( '' !== $action && ! in_array( $action, law_event_ui_actions(), true ) ) {
		law_event_log(
			$post_id,
			sprintf( 'Refused workflow action "%s": not offered by the event screen.', $action ),
			array( 'action' => 'refused', 'attempted' => $action, 'source' => 'ui' ),
			array( 'user_id' => $actor )
		);
		law_event_admin_notice( $actor, 'That action is not available from this screen.' );
		$action = '';
	}
	if ( '' !== $action ) {
		$note   = trim( (string) wp_unslash( $_POST['law_workflow_note'] ?? '' ) );
		$result = law_event_workflow_transition(
			$post_id,
			$action,
			array( 'comment' => $note, 'reason' => $note, 'actor_id' => $actor )
		);
		if ( is_wp_error( $result ) ) {
			law_event_admin_notice( $actor, $result->get_error_message() );
		}
	}

	// Last: the flagship's derived start, end and speakers union, in case this
	// save changed its date meta or one of its sessions was edited alongside it.
	if ( $is_flagship ) {
		law_flagship_recompute( $post_id );
	}

	$running = false;
}

/**
 * The flagship is in the Events list like any other event, so it can be opened
 * on this screen; its own fields (description, date, location, banner image and
 * the whole session agenda) live on the Flagship screen, which is where an edit
 * belongs.
 */
add_action(
	'admin_notices',
	function () {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'post' !== $screen->base || LAW_EVENT_CPT !== ( $screen->post_type ?? '' ) ) {
			return;
		}
		if ( ! function_exists( 'law_flagship_is' ) || ! law_flagship_is( get_the_ID() ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info"><p>This is the flagship conference. Its description, date, location, banner image and session agenda are edited on the <a href="%s">Flagship screen</a>; its start and end times are worked out from its sessions, so the slot and time fields here do not apply.</p></div>',
			esc_url( law_flagship_admin_url() )
		);
	}
);

/**
 * The classic editor's submit box misleads on this CPT (core knows nothing
 * of the custom statuses, and the status guard makes its status controls
 * inert): relabel the button to "Update", show the real status, and hide the
 * core status/visibility rows. The Workflow box is the way status moves.
 */
add_action( 'admin_footer-post.php', 'law_event_submitbox_script' );
add_action( 'admin_footer-post-new.php', 'law_event_submitbox_script' );
function law_event_submitbox_script() {
	$screen = get_current_screen();
	if ( ! $screen || LAW_EVENT_CPT !== $screen->post_type ) {
		return;
	}
	$post   = get_post();
	$status = $post ? law_event_status_label( $post ) : 'Draft';
	?>
	<style>
		#misc-publishing-actions .misc-pub-post-status,
		#misc-publishing-actions .misc-pub-visibility,
		#minor-publishing-actions { display: none; }
	</style>
	<script>
	(function () {
		var publish = document.getElementById('publish');
		if (publish) { publish.value = 'Update'; publish.name = 'save'; }
		var actions = document.getElementById('misc-publishing-actions');
		if (actions) {
			var note = document.createElement('div');
			note.className = 'misc-pub-section';
			note.innerHTML = 'Status: <strong></strong> — changed via the Workflow box, never here.';
			note.querySelector('strong').textContent = <?php echo wp_json_encode( $status ); ?>;
			actions.prepend(note);
		}
	})();
	</script>
	<?php
}

/** Read a repeater's rows out of $_POST. */
function law_events_rows_from_post( $field ) {
	$rows = array();
	foreach ( (array) ( $_POST[ $field ] ?? array() ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$clean = array();
		foreach ( $row as $key => $value ) {
			$value = wp_unslash( is_scalar( $value ) ? (string) $value : '' );
			// A speaker's biography is rich text, and sanitize_text_field() would
			// collapse its markup and line breaks — before the meta schema's own
			// sanitiser ever sees the value. Everything else here is single-line.
			$clean[ $key ] = 'bio' === $key ? law_rich_text_sanitize( $value ) : sanitize_text_field( $value );
		}
		$rows[] = $clean;
	}
	return $rows;
}

/**
 * Queue a one-shot notice for this editor. It appends rather than overwrites,
 * because one Update can produce more than one thing worth saying (a refused
 * host fee override and a re-taken fee snapshot in the same save, say).
 *
 * @param int    $actor   User ID the notice is for.
 * @param string $message What to tell them.
 */
function law_event_admin_notice( $actor, $message ) {
	$key      = 'law_event_notice_' . (int) $actor;
	$existing = get_transient( $key );
	$queued   = is_array( $existing ) ? $existing : ( $existing ? array( (string) $existing ) : array() );
	$queued[] = (string) $message;
	set_transient( $key, $queued, 60 );
}

/** Surface refused transitions and fee-save outcomes as admin notices. */
add_action( 'admin_notices', function () {
	$key    = 'law_event_notice_' . get_current_user_id();
	$notice = get_transient( $key );
	if ( ! $notice ) {
		return;
	}
	delete_transient( $key );
	foreach ( (array) $notice as $message ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $message ) . '</p></div>';
	}
} );
