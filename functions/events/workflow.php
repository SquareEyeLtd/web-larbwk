<?php
/**
 * The workflow engine (EVENTS_4.1_REBUILD.md §3.6): an explicit state machine.
 * law_event_workflow_transition() is the ONLY way an event's status changes.
 *
 *   law-draft → law-proposed → law-approved → publish (Confirmed)
 *                    ↕ law-sent-back    ↘ law-rejected
 *
 * law-cancelled is the shared terminal state of the committee's `cancel`
 * (approved/confirmed events) and the host's `withdraw` (pre-approval
 * events). There is no un-cancel.
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
		'cancel'    => array( 'from' => array( 'law-approved', 'publish' ), 'to' => 'law-cancelled', 'who' => 'committee' ),
		'withdraw'  => array( 'from' => array( 'law-draft', 'law-proposed', 'law-sent-back' ), 'to' => 'law-cancelled', 'who' => 'owner' ),
	);
}

/**
 * The actions the two committee UIs actually offer. Both the front-end
 * dashboard handler and the wp-admin event screen check a posted action
 * against this list before handing it to the workflow engine, so a hand-made
 * request cannot reach a transition no button exposes. One source, so the two
 * screens cannot drift apart.
 *
 * @return string[]
 */
function law_event_ui_actions() {
	return array( 'approve', 'send_back', 'reject', 'mark_paid', 'cancel' );
}

/**
 * The UI actions legal for an event's CURRENT status, from the same from-lists
 * the transition guard enforces. Both committee screens render only these
 * buttons, so a member is never offered an action the engine would refuse
 * (e.g. Send back on an Approved event).
 *
 * @param int|WP_Post $event Event ID or post.
 * @return string[] Subset of law_event_ui_actions(), in that order.
 */
function law_event_available_ui_actions( $event ) {
	$post = get_post( $event );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return array();
	}
	$actions   = law_event_workflow_actions();
	$available = array();
	foreach ( law_event_ui_actions() as $action ) {
		if ( in_array( $post->post_status, $actions[ $action ]['from'], true ) ) {
			$available[] = $action;
		}
	}
	return $available;
}

/**
 * The status guard: an EXISTING law_event's status can only change through
 * law_event_workflow_transition(). This is what stops the classic editor's
 * Publish / Save Draft buttons (which know nothing of the custom statuses)
 * from silently confirming an unapproved event or parking it in a core
 * status invisible to every dashboard. New inserts (submission form,
 * migration, tests) pass through untouched.
 */
add_filter(
	'wp_insert_post_data',
	function ( $data, $postarr ) {
		$post_type = (string) ( $data['post_type'] ?? '' );
		// Bookings get the same protection: their only transition is cancel,
		// via law_booking_cancel(), behind its own flag. Without this, quick
		// edit could flip a cancelled booking back to publish, silently
		// re-consuming places behind the recount's back.
		if ( ! in_array( $post_type, array( LAW_EVENT_CPT, LAW_BOOKING_CPT ), true ) ) {
			return $data;
		}
		$post_id = (int) ( $postarr['ID'] ?? 0 );
		if ( ! $post_id ) {
			return $data; // New insert: the caller's status stands.
		}
		$flag = LAW_BOOKING_CPT === $post_type ? 'law_booking_transitioning' : 'law_workflow_transitioning';
		if ( ! empty( $GLOBALS[ $flag ] ) ) {
			return $data; // The engine is moving the status.
		}
		// The flagship conference has no workflow: it is never proposed,
		// approved or invoiced, and its two statuses mean only "on the
		// programme" (publish) and "not yet" (law-draft), which is the tick box
		// on the Flagship screen. Its own saver raises this flag around its
		// wp_update_post, so the guard's invariant still holds — a status change
		// only ever comes from a known code path — while the box actually works.
		// Deliberately a separate flag from the engine's, rather than reusing
		// law_workflow_transitioning, so nothing here pretends a transition ran.
		if ( ! empty( $GLOBALS['law_flagship_saving'] ) && LAW_EVENT_CPT === $post_type
			&& function_exists( 'law_flagship_is' ) && law_flagship_is( $post_id )
			&& in_array( (string) ( $data['post_status'] ?? '' ), array( 'publish', 'law-draft' ), true ) ) {
			return $data;
		}
		$current = get_post_field( 'post_status', $post_id );
		if ( 'trash' === $current ) {
			return $data; // Untrash restores must pass (see the filter below).
		}
		if ( $current && $current !== ( $data['post_status'] ?? '' ) && 'trash' !== ( $data['post_status'] ?? '' ) ) {
			$data['post_status'] = $current;
		}
		return $data;
	},
	10,
	2
);

/**
 * Untrash restores the status the post was trashed with (core would restore
 * to `draft`, a status the module never uses). Bookings are constrained to
 * their own three statuses; anything else restores as cancelled, the safe side
 * (an active booking appearing from nowhere would consume places).
 */
add_filter(
	'wp_untrash_post_status',
	function ( $new_status, $post_id ) {
		$post_type = get_post_type( $post_id );
		$previous  = (string) get_post_meta( $post_id, '_wp_trash_meta_status', true );
		if ( LAW_BOOKING_CPT === $post_type ) {
			return in_array( $previous, array_keys( law_booking_statuses() ), true ) ? $previous : 'law-cancelled';
		}
		if ( LAW_EVENT_CPT !== $post_type ) {
			return $new_status;
		}
		return array_key_exists( $previous, law_event_statuses() ) ? $previous : 'law-proposed';
	},
	10,
	2
);

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
		if ( 'system' === $config['who'] ) {
			// Unconditional: 'system' means machine-only (confirm). Being
			// committee only ever needed to satisfy the two branches above;
			// letting a committee user through here too is what made
			// 'confirm' reachable outside the committee dashboard's action
			// whitelist (e.g. via the wp-admin event screen's
			// law_workflow_action field posting 'confirm' directly).
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
	if ( 'cancel' === $action && '' === trim( (string) ( $args['reason'] ?? '' ) ) ) {
		return new WP_Error( 'law_reason_required', 'Cancel needs a reason for the host.' );
	}

	$old_status = $post->post_status;
	$new_status = $config['to'];

	// The wp_insert_post_data status guard above only lets a status change
	// through while this flag is up: the workflow engine is the ONLY way an
	// existing event's status moves.
	$GLOBALS['law_workflow_transitioning'] = true;
	$updated = wp_update_post(
		array( 'ID' => $event_id, 'post_status' => $new_status ),
		true
	);
	$GLOBALS['law_workflow_transitioning'] = false;
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

	law_event_workflow_side_effects( $event_id, $action, $args, $actor, $source, $old_status );

	return true;
}

/**
 * Side effects per action. Runs after the status write and the log entry.
 */
function law_event_workflow_side_effects( $event_id, $action, array $args, $actor, $source, $old_status = '' ) {
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
			// The reason goes to the event thread as well as the email, the way
			// send back does, so the host can see why on their dashboard and not
			// only in their inbox. law_event_add_comment() sends no email of its
			// own, so this cannot double up on user_rejected below.
			if ( '' !== $reason ) {
				law_event_add_comment( $event_id, $reason, $actor );
			}
			law_events_send( 'user_rejected', $event_id );
			break;

		case 'approve':
			$snapshot = law_event_snapshot_fee( $event_id );
			update_post_meta( $event_id, '_law_approved_at', current_time( 'Y-m-d' ) );
			if ( '' === (string) law_event_meta( $event_id, '_law_payment_status' ) ) {
				update_post_meta( $event_id, '_law_payment_status', 'unpaid' );
			}
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

		case 'cancel':
			$reason = trim( (string) ( $args['reason'] ?? '' ) );
			// Reason meta and thread comment BEFORE the sends, the way reject
			// does: {cancellation_reason} and {latest_comment} are built from them.
			update_post_meta( $event_id, '_law_cancellation_reason', sanitize_textarea_field( $reason ) );
			if ( '' !== $reason ) {
				law_event_add_comment( $event_id, $reason, $actor );
			}
			// Void any live invoice BEFORE the host email, so its "no payment is
			// due" wording is true when read. A failed void logs and alerts the
			// admins; it never blocks the cancellation.
			law_stripe_void_invoice( $event_id, $actor );
			law_events_send( 'user_cancelled', $event_id );
			// A paid fee is never refunded automatically: the committee decides.
			if ( 'paid' === (string) law_event_meta( $event_id, '_law_payment_status' ) ) {
				law_events_send( 'committee_cancelled_paid', $event_id );
			}
			// A Confirmed event may hold attendee bookings: cancel them all and
			// email every attendee (EVENTS_BOOKINGS.md; settled decision).
			law_bookings_cancel_all_for_event( $event_id, $actor, $source );
			break;

		case 'withdraw':
			$reason = trim( (string) ( $args['reason'] ?? '' ) );
			if ( '' !== $reason ) {
				update_post_meta( $event_id, '_law_cancellation_reason', sanitize_textarea_field( $reason ) );
				law_event_add_comment( $event_id, $reason, $actor );
			}
			// No Stripe handling: invoices are only raised at approval, and
			// withdraw stops at law-sent-back. The committee is told, except
			// about drafts they never saw (committee_submitted fires at submit).
			if ( 'law-draft' !== $old_status ) {
				law_events_send( 'committee_withdrawn', $event_id );
			}
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
			'Host fee override %s (amount £%s → £%s).',
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
 * Log a venue capacity band change (called from the committee panel with the
 * band read immediately before the write).
 *
 * Worth its own line: the band is the ceiling every ticket allocation is
 * checked against, so changing it silently changes what the host may set, and
 * until now the only trace of a capacity change was the generic "Event details
 * updated" line. Places available are logged separately by
 * law_event_tickets_changed(), which also offers freed places to the waitlist.
 *
 * @param int    $event_id        law_event post ID.
 * @param string $before_capacity The band as it was before the write.
 * @param int    $actor           Who made the change.
 */
function law_event_log_capacity_change( $event_id, $before_capacity, $actor ) {
	$after  = (string) law_event_meta( $event_id, '_law_venue_capacity' );
	$before = (string) $before_capacity;
	if ( $before === $after ) {
		return;
	}
	law_event_log(
		$event_id,
		sprintf(
			'Venue capacity changed: %1$s → %2$s.',
			'' === $before ? '(not set)' : $before,
			'' === $after ? '(not set)' : $after
		),
		array(
			'action' => 'venue_capacity',
			'old'    => $before,
			'new'    => $after,
			'source' => 'ui',
		),
		array( 'user_id' => (int) $actor )
	);
}

/**
 * Log linked-organisation changes (called from the admin/committee save paths
 * with the IDs read immediately before the write).
 *
 * Worth logging even though it is a quiet field: a sponsor-category
 * organisation is one of the three routes to the public "Sponsored" badge, so
 * a change here is visible to the world.
 */
function law_event_log_organisation_change( $event_id, array $before_ids, $actor ) {
	$before_ids = array_values( array_map( 'intval', $before_ids ) );
	$after_ids  = array_values( array_map( 'intval', law_event_meta( $event_id, '_law_organisation_ids' ) ) );
	if ( $before_ids === $after_ids ) {
		return;
	}

	$titles = law_events_organisation_titles();
	$name   = static function ( array $ids ) use ( $titles ) {
		$names = array();
		foreach ( $ids as $org_id ) {
			$names[] = $titles[ $org_id ] ?? '#' . $org_id;
		}
		return implode( ', ', $names ) ?: '(none)';
	};

	law_event_log(
		$event_id,
		sprintf(
			'Linked organisations changed from "%s" to "%s".',
			$name( $before_ids ),
			$name( $after_ids )
		),
		array(
			'action' => 'organisations',
			'old'    => $before_ids,
			'new'    => $after_ids,
			'source' => 'ui',
		),
		array( 'user_id' => (int) $actor )
	);
}

/**
 * Log the committee's classification switches (called from the dashboard
 * handler and the wp-admin screen with the values read immediately before the
 * write, exactly like the fee and organisation loggers above).
 *
 * ONE entry covering both flags, not one per flag: law_event_log_entries()
 * orders by comment_date_gmt with no tie-break, so two entries written in the
 * same second would display in arbitrary order.
 *
 * @param int   $event_id     law_event post ID.
 * @param array $before_flags Meta key => 0|1, read before the write.
 * @param int   $actor        User making the change.
 */
function law_event_log_flag_change( $event_id, array $before_flags, $actor ) {
	$after = array(
		'_law_is_law_event'   => (int) law_event_meta( $event_id, '_law_is_law_event' ),
		'_law_session_agenda' => (int) law_event_meta( $event_id, '_law_session_agenda' ),
	);
	$before = array(
		'_law_is_law_event'   => (int) ( $before_flags['_law_is_law_event'] ?? 0 ),
		'_law_session_agenda' => (int) ( $before_flags['_law_session_agenda'] ?? 0 ),
	);
	if ( $before === $after ) {
		return;
	}

	// Plain language, in the same words as the dashboard controls: the activity
	// log is read by committee members, not developers.
	$sentences = array(
		'_law_is_law_event'   => array( 'No longer marked as run by LAW.', 'Marked as run by LAW.' ),
		'_law_session_agenda' => array( 'Session agenda turned off.', 'Session agenda turned on.' ),
	);
	$changed = array();
	foreach ( $after as $key => $value ) {
		if ( $before[ $key ] !== $value ) {
			$changed[] = $sentences[ $key ][ $value ];
		}
	}

	law_event_log(
		$event_id,
		implode( ' ', $changed ),
		array(
			'action' => 'event_flags',
			'old'    => $before,
			'new'    => $after,
			'source' => 'ui',
		),
		array( 'user_id' => (int) $actor )
	);
}

/* Host withdraw handler ______________________________________________________ */

add_action( 'admin_post_law_event_withdraw', 'law_event_handle_withdraw' );
add_action( 'admin_post_nopriv_law_event_withdraw', function () {
	// An AJAX post from a page whose user has since logged out lands here;
	// a redirect would be unparseable to the script, so answer JSON.
	if ( ! empty( $_POST['law_ajax'] ) ) {
		wp_send_json_error( array( 'message' => 'You have been signed out. Please reload the page and sign in again.' ), 401 );
	}
	wp_safe_redirect( wp_login_url() );
	exit;
} );

/**
 * The host "Withdraw" action on My events: a thin admin-post wrapper around
 * the `withdraw` workflow transition. Mirrors law_event_handle_comment_reply()
 * (nonce, honeypot, ownership, rate limit, AJAX/JSON branch).
 */
function law_event_handle_withdraw() {
	$is_ajax = ! empty( $_POST['law_ajax'] );

	// An AJAX caller must get JSON even on a bad nonce — check_admin_referer
	// would die with an HTML page the script cannot parse.
	if ( $is_ajax && ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'law_event_withdraw' ) ) {
		wp_send_json_error( array( 'message' => 'Your session has changed since this page was opened. Please reload the page and try again.' ), 403 );
	}
	check_admin_referer( 'law_event_withdraw' );

	$event_id = absint( $_POST['event_id'] ?? 0 );
	$user_id  = get_current_user_id();

	if ( ! law_user_can_manage_event( $user_id, $event_id ) ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Sorry, you are not allowed to withdraw this event.' ), 403 );
		}
		wp_die( 'Sorry, you are not allowed to withdraw this event.' );
	}
	if ( '' !== trim( (string) ( $_POST['law_website_url'] ?? '' ) ) ) {
		// Honeypot: pretend success (nothing was changed).
		if ( $is_ajax ) {
			wp_send_json_success( array( 'title' => 'Event withdrawn', 'message' => 'Reloading the page…', 'redirect' => home_url( '/account/events/' ) ) );
		}
		law_events_redirect_back( array( 'law_notice' => 'event-withdrawn' ) );
	}
	if ( ! law_events_rate_limit_ok( 'withdraw', $user_id ) ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => 'Too many actions in a short time; please wait a moment.' ), 429 );
		}
		law_events_redirect_back( array( 'law_notice' => 'rate-limited' ) );
	}

	$result = law_event_workflow_transition(
		$event_id,
		'withdraw',
		array(
			'reason'   => trim( (string) wp_unslash( $_POST['law_withdraw_reason'] ?? '' ) ),
			'actor_id' => $user_id,
		)
	);

	if ( is_wp_error( $result ) ) {
		if ( $is_ajax ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}
		law_events_redirect_back( array( 'law_notice' => 'withdraw-failed' ) );
	}

	if ( $is_ajax ) {
		wp_send_json_success(
			array(
				'title'    => 'Event withdrawn',
				'message'  => 'Reloading the page…',
				'redirect' => home_url( '/account/events/' ),
			)
		);
	}
	law_events_redirect_back( array( 'law_notice' => 'event-withdrawn' ) );
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
