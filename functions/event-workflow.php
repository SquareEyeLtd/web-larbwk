<?php
	
	/**
 * Populate field 78 (Approval date) when the committee review
 * approval step is approved. Used by the GravityPDF invoice template
 * to calculate the 5-day payment due date.
 */
add_action( 'gravityflow_step_complete', function( $step_id, $entry, $form, $next_step_id ) {

    // Only act on the event submission form (ID 2)
    if ( (int) $form['id'] !== 2 ) {
        return;
    }

    // Confirm this is an approval-type step
    $step = gravity_flow()->get_step( $step_id, $entry );
    if ( ! $step || $step->get_type() !== 'approval' ) {
        return;
    }

    // Only on approval, not rejection or revert
    if ( $step->get_status() !== 'approved' ) {
        return;
    }

    // Write today in Y-m-d format — reliable for PHP date arithmetic
    GFAPI::update_entry_field( $entry['id'], 78, date( 'Y-m-d' ) );

    // Baseline payment status: Free for £0 events, Unpaid otherwise.
    // Step 20 (Waiting for payment) overwrites it with Paid when Stripe's
    // invoice.paid webhook releases the entry via Make. The empty-check
    // stops a re-approval after the send-back loop from clobbering a
    // status that is already set.
    if ( '' === rgar( $entry, '96' ) ) {
        $fee_pence = (float) rgar( $entry, '84' );
        GFAPI::update_entry_field( $entry['id'], 96, $fee_pence > 0 ? 'Unpaid' : 'Free' );
    }

}, 10, 4 );