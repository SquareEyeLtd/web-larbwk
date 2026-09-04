<?php
/**
 * The workflow engine (EVENTS_4.1_REBUILD.md §3.6): an explicit state machine.
 * law_event_workflow_transition() is the ONLY way an event's status changes.
 *
 *   law-draft → law-proposed → law-approved → publish (Confirmed)
 *                    ↕ law-sent-back    ↘ law-rejected
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * action => [from (statuses), to, cap ('committee' | 'owner' | 'system')].
 */
function law_event_workflow_actions() {
	return array(
		'submit'    => array( 'from' => array( 'law-draft', 'auto-draft', 'draft' ), 'to' => 'law-proposed', 'who' => 'owner' ),
		'resubmit'  => array( 'from' => array( 'law-sent-back' ), 'to' => 'law-proposed', 'who' => 'owner' ),
		'approve'   => array( 'from' => array( 'law-proposed', 'law-sent-back' ), 'to' => 'law-approved', 'who' => 'committee' ),
		'send_back' => array( 'from' => array( 'law-proposed' ), 'to' => 'law-sent-back', 'who' => 'committee' ),
		'reject'    => array( 'from' => array( 'law-proposed', 'law-sent-back' ), 'to' => 'law-rejected', 'who' => 'committee' ),
		'confirm'   => array( 'from' => array( 'law-approved' ), 'to' => 'publish', 'who' => 'system' ),
		'mark_paid' => array( 'from' => array( 'law-approved' ), 'to' => 'publish', 'who' => 'committee' ),
	);
}

/**
 * Run one workflow transition, with guards, side effects and logging.
 *
 * @param int    $event_id law_event post ID.
 * @param string $action   Action key from law_event_workflow_actions().
 * @param array  $args     Optional: comment (send_back), reason (reject),
 *                         source (default 'ui'), actor_id.
 * @return true|WP_Error
 */
function law_event_workflow_transition( $event_id, $action, array $args = array() ) {
	$event_id = (int) $event_id;
	$post     = get_post( $event_id );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return new WP_Error( 'law_event_missing', 'Event not found.' );
	}

	$actions = law_event_workflow_actions();
	if ( ! isset( $actions[ $action ] ) ) {
		return new WP_Error( 'law_bad_action', 'Unknown workflow action.' );
	}
	$config = $actions[ $action ];
	$source = (string) ( $args['source'] ?? 'ui' );
	$actor  = isset( $args['actor_id'] ) ? (int) $args['actor_id'] : get_current_user_id();

	// Guard: from-status.
	if ( ! in_array( $post->post_status, $config['from'], true ) ) {
		return new WP_Error(
			'law_bad_transition',
			sprintf( 'Cannot %s an event that is %s.', str_replace( '_', ' ', $action ), law_event_status_label( $post ) )
		);
	}

	// Guard: who. 'system' bypasses when the source is a trusted machine path.
	$is_machine = in_array( $source, array( 'stripe_webhook', 'migration', 'system' ), true );
	if ( ! $is_machine ) {
		if ( 'committee' === $config['who'] && ! law_user_is_committee( $actor ) ) {
			return new WP_Error( 'law_not_allowed', 'Only the committee can do that.' );
		}
		if ( 'owner' === $config['who'] && ! law_user_can_manage_event( $actor, $event_id ) ) {
			return new WP_Error( 'law_not_allowed', 'Only the event owner can do that.' );
		}
		if ( 'system' === $config['who'] && ! law_user_is_committee( $actor ) ) {
			return new WP_Error( 'law_not_allowed', 'That transition is automatic.' );
		}
	}

	// Guard: required inputs.
	if ( 'send_back' === $action && '' === trim( (string) ( $args['comment'] ?? '' ) ) ) {
		return new WP_Error( 'law_comment_required', 'Send back needs a comment for the host.' );
	}
	if ( 'reject' === $action && '' === trim( (string) ( $args['reason'] ?? '' ) ) ) {
		return new WP_Error( 'law_reason_required', 'Reject needs a reason.' );
	}

	$old_status = $post->post_status;
	$new_status = $config['to'];

	$updated = wp_update_post(
		array( 'ID' => $event_id, 'post_status' => $new_status ),
		true
	);
	if ( is_wp_error( $updated ) ) {
		return $updated;
	}

	law_event_log(
		$event_id,
		sprintf(
			'Status changed from %s to %s (%s).',
			law_event_status_label( $old_status ),
			law_event_status_label( $new_status ),
			str_replace( '_', ' ', $action )
		),
		array(
			'action' => $action,
			'old'    => $old_status,
			'new'    => $new_status,
			'source' => $source,
		),
		array( 'user_id' => $actor )
	);

	law_event_workflow_side_effects( $event_id, $action, $args, $actor, $source );

	return true;
}

/**
 * Side effects per action. Runs after the status write and the log entry.
 */
function law_event_workflow_side_effects( $event_id, $action, array $args, $actor, $source ) {
	switch ( $action ) {
		case 'submit':
			if ( ! law_event_meta( $event_id, '_law_reference' ) ) {
				update_post_meta( $event_id, '_law_reference', law_events_next_reference() );
			}
			if ( ! law_event_meta( $event_id, '_law_payment_status' ) ) {
				update_post_meta( $event_id, '_law_payment_status', 'unpaid' );
			}
			law_events_send( 'user_submitted', $event_id );
			law_events_send( 'committee_submitted', $event_id );
			law_events_send( 'squareeye_submitted', $event_id );
			break;

		case 'resubmit':
			law_events_send( 'committee_resubmitted', $event_id );
			break;

		case 'send_back':
			$comment = trim( (string) ( $args['comment'] ?? '' ) );
			if ( '' !== $comment ) {
				law_event_add_comment( $event_id, $comment, $actor );
			}
			law_events_send( 'user_sent_back', $event_id );
			break;

		case 'reject':
			$reason = trim( (string) ( $args['reason'] ?? '' ) );
			update_post_meta( $event_id, '_law_rejection_reason', sanitize_textarea_field( $reason ) );
			law_events_send( 'user_rejected', $event_id );
			break;

		case 'approve':
			$snapshot = law_event_snapshot_fee( $event_id );
			update_post_meta( $event_id, '_law_approved_at', current_time( 'Y-m-d' ) );
			law_event_log(
				$event_id,
				sprintf(
					'Fee snapshot at approval: %s, VAT %s.',
					law_events_format_pence( $snapshot['fee_pence'] ),
					$snapshot['vat'] ? 'applies' : 'not applied'
				),
				array( 'action' => 'fee_snapshot', 'fee_pence' => $snapshot['fee_pence'], 'vat' => $snapshot['vat'], 'source' => $source ),
				array( 'user_id' => $actor )
			);

			// Co-owner accounts are created ON APPROVAL (settled decision).
			law_event_ensure_co_owner_users( $event_id, $actor );

			law_events_send( 'committee_approved', $event_id );

			if ( $snapshot['fee_pence'] > 0 ) {
				$result = law_stripe_create_and_send_invoice( $event_id );
				if ( ! is_wp_error( $result ) ) {
					law_events_send( 'user_payment_due', $event_id );
				}
				// Failure path handled inside the service: error meta, alert email.
			} else {
				update_post_meta( $event_id, '_law_payment_status', 'free' );
				law_event_log(
					$event_id,
					'Zero fee: payment status set to Free, no invoice raised.',
					array( 'action' => 'payment_status', 'old' => 'unpaid', 'new' => 'free', 'source' => $source ),
					array( 'user_id' => $actor )
				);
				law_event_workflow_transition( $event_id, 'confirm', array( 'source' => 'system', 'actor_id' => $actor ) );
			}
			break;

		case 'mark_paid':
			law_event_set_payment_status( $event_id, 'paid', 'manual', $actor );
			law_event_confirm_side_effects( $event_id );
			break;

		case 'confirm':
			law_event_confirm_side_effects( $event_id );
			break;
	}
}

/** Shared post-confirmation side effects (emails). */
function law_event_confirm_side_effects( $event_id ) {
	$fee = (int) law_event_meta( $event_id, '_law_fee_pence' );
	if ( $fee > 0 ) {
		law_events_send( 'committee_payment_received', $event_id );
	}
	// Confirmed-email split on fee = 0, not tier (fixes the fee-waived wording defect).
	law_events_send( 0 === $fee ? 'user_confirmed_free' : 'user_confirmed_paid', $event_id );
}

/**
 * Change the payment status with a log entry. Never silent.
 *
 * @param int    $event_id law_event post ID.
 * @param string $status   unpaid / paid / refunded / free.
 * @param string $source   Where the change came from.
 * @param int    $actor    Actor user ID (0 = system).
 */
function law_event_set_payment_status( $event_id, $status, $source = 'system', $actor = 0 ) {
	$old = (string) law_event_meta( $event_id, '_law_payment_status' );
	if ( $old === $status ) {
		return;
	}
	update_post_meta( $event_id, '_law_payment_status', $status );
	law_event_log(
		$event_id,
		sprintf( 'Payment status changed from %s to %s.', $old ? ucfirst( $old ) : '(none)', ucfirst( $status ) ),
		array( 'action' => 'payment_status', 'old' => $old, 'new' => $status, 'source' => $source ),
		array( 'user_id' => (int) $actor )
	);
}

/**
 * Log fee override changes when the committee edits them (called from the
 * admin/committee save paths with before/after values).
 */
function law_event_log_fee_change( $event_id, $before_override, $before_amount, $actor ) {
	$after_override = (int) law_event_meta( $event_id, '_law_fee_override' );
	$after_amount   = (float) law_event_meta( $event_id, '_law_fee_override_amount' );
	if ( (int) $before_override === $after_override && (float) $before_amount === $after_amount ) {
		return;
	}
	law_event_log(
		$event_id,
		sprintf(
			'Fee override %s (amount £%s → £%s).',
			$after_override ? 'enabled' : 'disabled',
			number_format( (float) $before_amount, 2 ),
			number_format( $after_amount, 2 )
		),
		array(
			'action' => 'fee_override',
			'old'    => array( 'override' => (int) $before_override, 'amount' => (float) $before_amount ),
			'new'    => array( 'override' => $after_override, 'amount' => $after_amount ),
			'source' => 'ui',
		),
		array( 'user_id' => (int) $actor )
	);
}

/**
 * Committee assignee change: log + notify the newly assigned member
 * (replaces law_maybe_notify_committee_assignee()).
 */
function law_event_maybe_notify_assignee( $event_id, $old_assignee, $actor ) {
	$new_assignee = (int) law_event_meta( $event_id, '_law_assignee' );
	if ( (int) $old_assignee === $new_assignee ) {
		return;
	}
	$user = $new_assignee ? get_user_by( 'id', $new_assignee ) : null;
	law_event_log(
		$event_id,
		sprintf( 'Committee assignee changed to %s.', $user ? $user->display_name : '(none)' ),
		array( 'action' => 'assignee', 'old' => (int) $old_assignee, 'new' => $new_assignee, 'source' => 'ui' ),
		array( 'user_id' => (int) $actor )
	);
	if ( $user && is_email( $user->user_email ) ) {
		law_events_send( 'committee_assignee', $event_id, array( 'to' => $user->user_email ) );
	}
}
