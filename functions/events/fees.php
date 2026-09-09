<?php
/**
 * Fee calculation: the replacement for the GPAC calculated fields 84
 * (Calculated fee (pence)) and 85 (VAT).
 *
 * The VAT flag is computed purely from fee > 0 (killing the old
 * price-literal fragility): any positive amount, tier or override, carries
 * VAT; a zero fee never does.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tier price in pounds from settings.
 *
 * @param string $tier Tier key (uk / international / sponsor).
 * @return float
 */
function law_event_tier_amount( $tier ) {
	$tiers = (array) law_events_setting( 'fee_tiers', array() );
	return isset( $tiers[ $tier ]['amount'] ) ? (float) $tiers[ $tier ]['amount'] : 0.0;
}

/** Tier label from settings. */
function law_event_tier_label( $tier ) {
	$tiers = (array) law_events_setting( 'fee_tiers', array() );
	return isset( $tiers[ $tier ]['label'] ) ? (string) $tiers[ $tier ]['label'] : (string) $tier;
}

/**
 * The fee in PENCE for an event, honouring the committee override.
 * Mirrors field 84 (Calculated fee (pence)): override amount when the
 * override box is ticked, otherwise the tier price.
 *
 * @param int $event_id law_event post ID.
 * @return int Pence.
 */
function law_event_calculate_fee_pence( $event_id ) {
	if ( law_event_meta( $event_id, '_law_fee_override' ) ) {
		$pounds = (float) law_event_meta( $event_id, '_law_fee_override_amount' );
	} else {
		$pounds = law_event_tier_amount( (string) law_event_meta( $event_id, '_law_fee_tier' ) );
	}
	return (int) round( max( 0, $pounds ) * 100 );
}

/**
 * The VAT flag (1/0) for a fee: VAT applies to any positive amount.
 * Mirrors field 85 (VAT), minus the price-literal matching.
 *
 * @param int $fee_pence Fee in pence.
 */
function law_event_calculate_vat( $fee_pence ) {
	return (int) $fee_pence > 0 ? 1 : 0;
}

/**
 * The VAT rate (0.20 = 20%). Stripe's own tax rate object (settings
 * tax_rate_id) is authoritative for charging; this mirror is used only to
 * reconcile the amount Stripe reports as paid. Kept here (filterable) so a rate
 * change lives in one place rather than as a bare literal in the webhook.
 */
function law_events_vat_rate() {
	return (float) apply_filters( 'law_events_vat_rate', 0.20 );
}

/**
 * Snapshot the fee onto the event at approval. Returns the snapshot.
 *
 * @param int $event_id law_event post ID.
 * @return array{fee_pence:int,vat:int}
 */
function law_event_snapshot_fee( $event_id ) {
	$fee = law_event_calculate_fee_pence( $event_id );
	$vat = law_event_calculate_vat( $fee );
	update_post_meta( $event_id, '_law_fee_pence', $fee );
	update_post_meta( $event_id, '_law_vat', $vat );
	return array( 'fee_pence' => $fee, 'vat' => $vat );
}

/**
 * Whether the host-fee override is settled and must no longer be edited on the
 * committee dashboard.
 *
 * The fee is calculated once, at approval: law_event_snapshot_fee() freezes
 * _law_fee_pence and law_stripe_create_and_send_invoice() raises the invoice
 * from that snapshot in the same breath. Nothing recalculates it afterwards,
 * so a later change to the override box would move the exports and the admin
 * Fee column while the snapshot, the invoice and the {fee} emails all kept the
 * old figure: a save that reports success and changes nothing that matters.
 * The dashboard control therefore goes read-only from approval onwards.
 * wp-admin stays the deliberate escape hatch for a post-approval fee change
 * (the settled decision recorded in EVENTS_FUNC.md), because that screen can
 * re-freeze the snapshot through law_event_resnapshot_fee().
 *
 * @param int $event_id law_event post ID.
 * @return bool
 */
function law_event_fee_override_locked( $event_id ) {
	return '' !== (string) law_event_meta( $event_id, '_law_approved_at' );
}

/**
 * Re-freeze the fee after a wp-admin fee edit on an already-approved event,
 * so wp-admin remains a working route rather than a form that saves values
 * nothing reads. Logged, because the snapshot is the number the invoice and
 * the reconciliation are argued from.
 *
 * Refused once money has moved: a paid or refunded fee is a bookkeeping
 * record, and rewriting the snapshot under it would only make the
 * invoice.paid reconciliation in the Stripe webhook lie.
 *
 * @param int $event_id law_event post ID.
 * @param int $actor    Actor user ID.
 * @return array{fee_pence:int,vat:int,was:int}|WP_Error
 */
function law_event_resnapshot_fee( $event_id, $actor = 0 ) {
	$status = (string) law_event_meta( $event_id, '_law_payment_status' );
	if ( in_array( $status, array( 'paid', 'refunded' ), true ) ) {
		return new WP_Error(
			'law_fee_settled',
			sprintf(
				'The host fee override was saved, but the fee snapshot was NOT changed: this event is already marked %s. Settle the difference in Stripe (a credit note or a refund) and set the payment status here to match.',
				$status
			)
		);
	}

	$was      = (int) law_event_meta( $event_id, '_law_fee_pence' );
	$snapshot = law_event_snapshot_fee( $event_id );
	if ( $snapshot['fee_pence'] !== $was ) {
		law_event_log(
			$event_id,
			sprintf(
				'Fee snapshot re-taken after a wp-admin host fee edit: %s → %s, VAT %s. The Stripe invoice is not reissued automatically.',
				law_events_format_pence( $was ),
				law_events_format_pence( $snapshot['fee_pence'] ),
				$snapshot['vat'] ? 'applies' : 'not applied'
			),
			array( 'action' => 'fee_resnapshot', 'old' => $was, 'new' => $snapshot['fee_pence'], 'vat' => $snapshot['vat'], 'source' => 'admin_edit' ),
			array( 'user_id' => (int) $actor )
		);
	}

	return array_merge( $snapshot, array( 'was' => $was ) );
}

/** "£1,200.00" for a pence amount. */
function law_events_format_pence( $pence ) {
	return '£' . number_format( ( (int) $pence ) / 100, 2 );
}
