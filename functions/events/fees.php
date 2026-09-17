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
 * Whether the fee is a bookkeeping record rather than a live figure: the money
 * has moved. A paid or refunded fee is never rewritten, because the snapshot is
 * what the Stripe webhook's invoice.paid reconciliation is argued from.
 *
 * @param int $event_id law_event post ID.
 * @return bool
 */
function law_event_fee_settled( $event_id ) {
	return in_array( (string) law_event_meta( $event_id, '_law_payment_status' ), array( 'paid', 'refunded' ), true );
}

/**
 * How the host fee override behaves on this event right now. One answer, read
 * by the committee dashboard, the wp-admin fee box and both save handlers, so
 * the control the committee sees and the write the handler accepts can never
 * disagree.
 *
 * - `open`    — before approval. Nothing is snapshotted and no invoice exists,
 *               so the override is an ordinary field.
 * - `reissue` — approved or Confirmed, and nothing settled yet. The fee WAS
 *               snapshotted at approval and an invoice raised from it, so a
 *               change here is not a field write: law_event_apply_fee_change()
 *               voids that invoice, re-freezes the snapshot and raises a new
 *               invoice for the host to pay (Denis, 17 September 2026 — the
 *               committee keeps the post-approval override, with a warning
 *               saying the previous invoice will be voided).
 * - `locked`  — the fee is history. Either the money has moved (paid /
 *               refunded) or the event is no longer live (cancelled, rejected),
 *               and in neither case is there an invoice worth reissuing.
 *
 * "Approved" is law_event_has_been_approved(), which reads the STATUS: the
 * `_law_approved_at` timestamp this used to test is absent on every migrated
 * event, because the legacy form never filled field 78 (Approval date) in, and
 * the lock was therefore off across the whole migrated programme (audit,
 * 16 September 2026).
 *
 * @param int $event_id law_event post ID.
 * @return string open | reissue | locked
 */
function law_event_fee_edit_mode( $event_id ) {
	if ( ! law_event_has_been_approved( $event_id ) ) {
		return 'open';
	}
	if ( law_event_fee_settled( $event_id ) ) {
		return 'locked';
	}
	// A cancelled or rejected event holds no live invoice (cancel voids it) and
	// is not going to be paid, so there is nothing for a fee change to reach.
	return in_array( get_post_status( $event_id ), array( 'law-approved', 'publish' ), true ) ? 'reissue' : 'locked';
}

/**
 * Re-freeze the fee on an already-approved event. Logged, because the snapshot
 * is the number the invoice and the reconciliation are argued from.
 *
 * Refused once money has moved: a paid or refunded fee is a bookkeeping
 * record, and rewriting the snapshot under it would only make the
 * invoice.paid reconciliation in the Stripe webhook lie.
 *
 * It re-freezes the number and nothing else. Voiding the invoice that was
 * raised from the old snapshot and raising a replacement is
 * law_event_apply_fee_change()'s job, and that is the only caller on a live
 * event, so this log line says what changed rather than what the caller is
 * about to do about it.
 *
 * @param int    $event_id law_event post ID.
 * @param int    $actor    Actor user ID.
 * @param string $source   Where the edit came from, for the log context.
 * @return array{fee_pence:int,vat:int,was:int}|WP_Error
 */
function law_event_resnapshot_fee( $event_id, $actor = 0, $source = 'admin_edit' ) {
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
				'Fee snapshot re-taken after a host fee change: %s → %s, VAT %s.',
				law_events_format_pence( $was ),
				law_events_format_pence( $snapshot['fee_pence'] ),
				$snapshot['vat'] ? 'applies' : 'not applied'
			),
			array( 'action' => 'fee_resnapshot', 'old' => $was, 'new' => $snapshot['fee_pence'], 'vat' => $snapshot['vat'], 'source' => (string) $source ),
			array( 'user_id' => (int) $actor )
		);
	}

	return array_merge( $snapshot, array( 'was' => $was ) );
}

/** "£1,200.00" for a pence amount. */
/**
 * Net pence plus VAT at the configured rate, rounded to the penny.
 *
 * Generic, not flagship-specific: any priced booking charges VAT the same way
 * (EVENTS_4.2_SPECS.md §7.2, standard rate on every ticket regardless of buyer
 * location), so the receptions and any future paid event use these two rather
 * than growing their own arithmetic.
 */
function law_events_gross_pence( $net_pence ) {
	$net = (int) $net_pence;
	if ( $net < 1 ) {
		return 0;
	}
	return (int) round( $net * ( 1 + law_events_vat_rate() ) );
}

/** The VAT itself, in pence: gross minus net, so the two can never disagree. */
function law_events_vat_pence( $net_pence ) {
	return law_events_gross_pence( $net_pence ) - (int) $net_pence;
}

/**
 * "£660.00 including VAT (£550.00 + VAT)": one wording for a price, so the
 * booking control, the application form and the emails cannot describe the
 * same figure three different ways.
 */
function law_events_price_label( $net_pence ) {
	$net = (int) $net_pence;
	if ( $net < 1 ) {
		return '';
	}
	return sprintf(
		/* translators: 1: gross price, 2: net price */
		'%1$s including VAT (%2$s + VAT)',
		law_events_format_pence( law_events_gross_pence( $net ) ),
		law_events_format_pence( $net )
	);
}

/**
 * A typed pounds amount as pence, or null when it is not a number at all.
 *
 * The inverse of law_events_format_pence(), and it lives beside it so every
 * screen that takes money from a human reads it the same way. The flagship's
 * two price fields are its callers today.
 *
 * Null rather than 0 on purpose. Zero is a real, meaningful answer here ("not
 * on sale"), so a typo has to be distinguishable from it and refused, rather
 * than silently turned into a free conference.
 *
 * @param string $typed What was typed: "550", "£550.00", "1,200.50".
 * @return int|null Pence, or null when unparseable or negative.
 */
function law_events_pounds_to_pence( $typed ) {
	$typed = trim( (string) $typed );
	$typed = str_replace( array( '£', ',', ' ' ), '', $typed );
	if ( '' === $typed || ! is_numeric( $typed ) ) {
		return null;
	}
	$pence = (int) round( ( (float) $typed ) * 100 );
	return $pence < 0 ? null : $pence;
}

function law_events_format_pence( $pence ) {
	return '£' . number_format( ( (int) $pence ) / 100, 2 );
}
