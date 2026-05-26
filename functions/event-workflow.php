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

}, 10, 4 );