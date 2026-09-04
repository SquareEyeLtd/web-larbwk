<?php
/**
 * Fee calculation: the replacement for the GPAC calculated fields 84
 * (Calculated fee (pence)) and 85 (VAT).
 *
 * The VAT flag is computed from fee > 0 (killing the old price-literal
 * fragility) and, like field 85, follows the host's ORIGINAL tier: a
 * committee discount or waiver does not remove VAT from a paid tier,
 * but the fee-is-zero case is always VAT 0.
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

/** "£1,200.00" for a pence amount. */
function law_events_format_pence( $pence ) {
	return '£' . number_format( ( (int) $pence ) / 100, 2 );
}
