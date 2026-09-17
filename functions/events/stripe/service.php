<?php
/**
 * The invoice flow, replacing Make scenario A module for module
 * (EVENTS_4.1_REBUILD.md §2.3.1, §3.7): upsert customer → tax ID →
 * invoice (send_invoice, 5-day due, Attention field, branded template)
 * → line item with the fixed tax rate → send → store the hosted URL.
 * Failures hold the event at Approved with an alert, never silent.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create and send the Stripe invoice for an approved event.
 *
 * @param int $event_id law_event post ID.
 * @return array|WP_Error The invoice object on success.
 */
function law_stripe_create_and_send_invoice( $event_id ) {
	$event_id = (int) $event_id;
	$post     = get_post( $event_id );
	if ( ! $post || LAW_EVENT_CPT !== $post->post_type ) {
		return new WP_Error( 'law_event_missing', 'Event not found.' );
	}

	$fee = (int) law_event_meta( $event_id, '_law_fee_pence' );
	if ( $fee <= 0 ) {
		return new WP_Error( 'law_no_fee', 'This event has no fee; nothing to invoice.' );
	}

	// A legacy invoice with no ID beside it: the events migrated from form 2
	// (Event > submit an event) carry the hosted URL that the retired Make
	// scenario logged into field 83 (Stripe invoice URL) and nothing else, so
	// the resume-before-create guard below cannot see the invoice the host is
	// already holding. Creating a second one would bill them twice, so this
	// refuses until LAW > Migration's "legacy Stripe invoices with no invoice
	// ID" panel has recorded it. Deliberately NOT recorded as a Stripe failure:
	// nothing failed, the request was refused, and an alert email would say
	// otherwise.
	if ( '' === (string) law_event_meta( $event_id, '_law_stripe_invoice_id' )
		&& '' !== trim( (string) law_event_meta( $event_id, '_law_stripe_invoice_url' ) ) ) {
		// Logged as well as returned: the approve transition discards this
		// error (it only acts on success), and a refusal nobody can see is how
		// an event ends up looking approved with no invoice and no explanation.
		law_event_log(
			$event_id,
			'Invoice NOT raised: this event already holds an invoice from before the rebuild, recorded as a web address only, with no ID to check it by. Run "Repair: legacy Stripe invoices with no invoice ID" on LAW → Migration, then try again.',
			array( 'action' => 'invoice_refused_legacy', 'source' => 'stripe' )
		);
		return new WP_Error(
			'law_legacy_invoice',
			'This event already has an invoice raised before the rebuild, and only its web address was recorded, not its ID. Run "Repair: legacy Stripe invoices with no invoice ID" on LAW → Migration first, so this invoice can be reused instead of a second one being raised.'
		);
	}

	// Configuration guards: a VAT-liable invoice without the tax rate ID
	// would silently bill net-only, so it fails loudly instead. A missing
	// rendering template only costs branding: warn and continue.
	if ( law_event_meta( $event_id, '_law_vat' ) && '' === (string) law_events_setting( 'tax_rate_id', '' ) ) {
		$error = new WP_Error( 'law_no_tax_rate', 'VAT applies to this fee but no Stripe tax rate ID is configured in Events → Settings. Invoice not created.' );
		law_stripe_record_failure( $event_id, $error );
		return $error;
	}
	if ( '' === (string) law_events_setting( 'rendering_template_id', '' ) ) {
		law_event_log(
			$event_id,
			'No Stripe invoice rendering template configured: the invoice will use Stripe\'s default look.',
			array( 'action' => 'invoice_template_missing', 'source' => 'stripe' )
		);
	}

	$result = law_stripe_invoice_steps( $event_id, $post, $fee );
	if ( is_wp_error( $result ) ) {
		law_stripe_record_failure( $event_id, $result );
		return $result;
	}

	// Clear any previous failure.
	delete_post_meta( $event_id, '_law_stripe_error' );
	return $result;
}

/** The happy-path steps; any WP_Error aborts and is recorded by the caller. */
function law_stripe_invoice_steps( $event_id, WP_Post $post, $fee ) {
	$customer = law_stripe_upsert_customer( $event_id, $post );
	if ( is_wp_error( $customer ) ) {
		return $customer;
	}
	$customer_id = (string) $customer['id'];
	update_post_meta( $event_id, '_law_stripe_customer_id', $customer_id );

	law_stripe_maybe_attach_vat_number( $event_id, $customer_id );

	// RESUME before create: if a previous attempt already produced an
	// invoice, never create a second one. A finalised/sent invoice is reused
	// (double-billing guard); a leftover draft is deleted first.
	$existing_id = (string) law_event_meta( $event_id, '_law_stripe_invoice_id' );
	if ( '' !== $existing_id ) {
		$existing = law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $existing_id ), array() );
		if ( ! is_wp_error( $existing ) ) {
			$status = (string) ( $existing['status'] ?? '' );
			if ( in_array( $status, array( 'open', 'paid', 'uncollectible' ), true ) ) {
				law_event_update_meta( $event_id, '_law_stripe_invoice_url', (string) ( $existing['hosted_invoice_url'] ?? '' ) );
				law_event_log(
					$event_id,
					sprintf( 'Existing Stripe invoice %s (%s) resumed; no new invoice created.', $existing_id, $status ),
					array( 'action' => 'invoice_resumed', 'invoice_id' => $existing_id, 'status' => $status, 'source' => 'stripe' )
				);
				return $existing;
			}
			if ( 'draft' === $status ) {
				$deleted = law_stripe_request( 'DELETE', '/v1/invoices/' . rawurlencode( $existing_id ), array() );
				law_event_log(
					$event_id,
					sprintf(
						'Leftover draft invoice %s from a failed attempt %s.',
						$existing_id,
						is_wp_error( $deleted ) ? 'could not be deleted (check Stripe): ' . $deleted->get_error_message() : 'deleted'
					),
					array( 'action' => 'invoice_draft_cleanup', 'invoice_id' => $existing_id, 'source' => 'stripe' )
				);
			}
			// void → fall through and create a fresh invoice.
		}
		delete_post_meta( $event_id, '_law_stripe_invoice_id' );
		delete_post_meta( $event_id, '_law_stripe_invoice_url' );
	}

	// One attempt number per NEW invoice creation; the Idempotency-Keys below
	// make a network-level retry of the same attempt return the same objects.
	$attempt = (int) get_post_meta( $event_id, '_law_stripe_attempt', true ) + 1;
	update_post_meta( $event_id, '_law_stripe_attempt', $attempt );
	$idem = fn( $step ) => sprintf( 'law-%s-%d-a%d', $step, $event_id, $attempt );

	$invoice_body = array(
		'customer'          => $customer_id,
		'collection_method' => 'send_invoice',
		'days_until_due'    => 5,
		'auto_advance'      => 'false',
		'custom_fields'     => array(
			array(
				'name'  => 'Attention',
				'value' => mb_substr( (string) law_event_meta( $event_id, '_law_invoice_name' ), 0, 140 ),
			),
		),
		'metadata'          => law_stripe_event_metadata( $event_id ),
	);
	$template = (string) law_events_setting( 'rendering_template_id', '' );
	if ( '' !== $template ) {
		$invoice_body['rendering'] = array( 'template' => $template );
	}

	$invoice = law_stripe_request( 'POST', '/v1/invoices', $invoice_body, $idem( 'inv' ) );
	if ( is_wp_error( $invoice ) ) {
		return $invoice;
	}
	$invoice_id = (string) $invoice['id'];
	// Persisted IMMEDIATELY, before the line item and send: a failure anywhere
	// after this point resumes the same invoice instead of creating another.
	update_post_meta( $event_id, '_law_stripe_invoice_id', $invoice_id );

	$line_body = array(
		'customer'    => $customer_id,
		'invoice'     => $invoice_id,
		'currency'    => 'gbp',
		'amount'      => $fee,
		'description' => 'Event fee for ' . $post->post_title,
	);
	$tax_rate = (string) law_events_setting( 'tax_rate_id', '' );
	if ( law_event_meta( $event_id, '_law_vat' ) && '' !== $tax_rate ) {
		$line_body['tax_rates'] = array( $tax_rate );
	}
	$line = law_stripe_request( 'POST', '/v1/invoiceitems', $line_body, $idem( 'line' ) );
	if ( is_wp_error( $line ) ) {
		return $line;
	}

	$sent = law_stripe_request( 'POST', '/v1/invoices/' . rawurlencode( $invoice_id ) . '/send', array(), $idem( 'send' ) );
	if ( is_wp_error( $sent ) ) {
		return $sent;
	}

	$details = law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $invoice_id ), array() );
	if ( is_wp_error( $details ) ) {
		return $details;
	}

	law_event_update_meta( $event_id, '_law_stripe_invoice_url', (string) ( $details['hosted_invoice_url'] ?? '' ) );

	law_event_log(
		$event_id,
		sprintf(
			'Stripe invoice %s created and sent for %s%s.',
			$invoice_id,
			law_events_format_pence( $fee ),
			law_event_meta( $event_id, '_law_vat' ) ? ' plus VAT' : ''
		),
		array(
			'action'     => 'invoice_sent',
			'invoice_id' => $invoice_id,
			'customer'   => $customer_id,
			'amount'     => $fee,
			'vat'        => (int) law_event_meta( $event_id, '_law_vat' ),
			'url'        => (string) ( $details['hosted_invoice_url'] ?? '' ),
			'source'     => 'stripe',
		)
	);

	return $details;
}

/**
 * The metadata block attached to both the Stripe customer and invoice, so the
 * two objects always carry the same identifiers back to us on webhooks.
 *
 * @param int $event_id law_event post ID.
 * @return array<string,string>
 */
function law_stripe_event_metadata( $event_id ) {
	return array_filter(
		array(
			'law_reference' => (string) law_event_meta( $event_id, '_law_reference' ),
			'law_event_id'  => (string) $event_id,
			'gf_entry_id'   => (string) law_event_meta( $event_id, '_law_gf_entry_id' ),
		)
	);
}

/**
 * The customer payload for an event. Names are mapped properly (fixing the
 * live empty-name defect): name = invoice contact, business_name = host
 * organisation(s).
 *
 * @param int $event_id law_event post ID.
 * @return array
 */
function law_stripe_customer_body( $event_id ) {
	$address = law_event_meta( $event_id, '_law_invoice_address' );
	$iso     = (string) law_event_meta( $event_id, '_law_country_iso' );
	$name    = (string) law_event_meta( $event_id, '_law_invoice_name' );
	$org     = (string) law_event_meta( $event_id, '_law_host_organisations' );

	return array(
		// Lowercased: Stripe's ?email= filter is an exact, case-sensitive match,
		// and a casing mismatch would create a duplicate customer.
		'email'           => mb_strtolower( (string) law_event_meta( $event_id, '_law_invoice_email' ) ),
		'name'            => $name,
		'business_name'   => $org,
		'individual_name' => $name,
		'address'         => array_filter(
			array(
				'line1'       => (string) ( $address['line1'] ?? '' ),
				'line2'       => (string) ( $address['line2'] ?? '' ),
				'city'        => (string) ( $address['city'] ?? '' ),
				'state'       => (string) ( $address['state'] ?? '' ),
				'postal_code' => (string) ( $address['postal_code'] ?? '' ),
				'country'     => $iso,
			)
		),
		'metadata'        => law_stripe_event_metadata( $event_id ),
	);
}

/**
 * Whose event a Stripe customer belongs to, read from the metadata this module
 * (and the retired Make scenario) stamps on every customer it touches.
 *
 * @param array $customer Stripe customer object.
 * @param int   $event_id law_event post ID we are invoicing for.
 * @return string 'mine' | 'other' | 'unclaimed'
 */
function law_stripe_customer_ownership( array $customer, $event_id ) {
	$meta        = (array) ( $customer['metadata'] ?? array() );
	$their_event = trim( (string) ( $meta['law_event_id'] ?? '' ) );
	$their_entry = trim( (string) ( $meta['gf_entry_id'] ?? '' ) );
	$our_entry   = trim( (string) law_event_meta( $event_id, '_law_gf_entry_id' ) );

	if ( '' !== $their_event ) {
		return (int) $their_event === (int) $event_id ? 'mine' : 'other';
	}
	if ( '' !== $their_entry ) {
		return ( '' !== $our_entry && (int) $their_entry === (int) $our_entry ) ? 'mine' : 'other';
	}
	return 'unclaimed';
}

/**
 * A conservative patch for a customer that merely shares the invoice email:
 * our metadata, plus only the fields the customer does not already have. An
 * address is all-or-nothing because Stripe replaces the whole address hash on
 * update, so a partial patch would blank the parts we left out.
 *
 * @param array $customer Existing Stripe customer object.
 * @param array $body     law_stripe_customer_body() payload.
 * @return array
 */
function law_stripe_customer_fill_blanks( array $customer, array $body ) {
	$patch = array( 'metadata' => $body['metadata'] );

	foreach ( array( 'name', 'business_name', 'individual_name' ) as $key ) {
		if ( '' === trim( (string) ( $customer[ $key ] ?? '' ) ) && '' !== trim( (string) ( $body[ $key ] ?? '' ) ) ) {
			$patch[ $key ] = $body[ $key ];
		}
	}

	$existing = array_filter( array_map( 'strval', (array) ( $customer['address'] ?? array() ) ), 'strlen' );
	if ( ! $existing && ! empty( $body['address'] ) ) {
		$patch['address'] = $body['address'];
	}

	return $patch;
}

/**
 * Upsert the Stripe customer: the ID stored on this event first, then an exact
 * email search, else create.
 *
 * The invoice email is host-supplied and never verified, so an email match is
 * NOT proof the customer is ours. Blindly POSTing our payload over a match
 * would let a host point their invoice email at another organisation's billing
 * address and overwrite that customer's name, address, VAT ID and metadata —
 * and then send them our invoice. A match is therefore adopted only when its
 * metadata binds it to this event (full update) or to no event at all
 * (blank-filling update); a customer already bound to a DIFFERENT event is left
 * untouched and a fresh customer is created instead.
 *
 * @param int     $event_id law_event post ID.
 * @param WP_Post $post     The event (kept for signature parity with callers).
 * @return array|WP_Error Customer object.
 */
function law_stripe_upsert_customer( $event_id, WP_Post $post ) {
	$body  = law_stripe_customer_body( $event_id );
	$email = (string) $body['email'];
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'law_no_invoice_email', 'The event has no valid invoice contact email.' );
	}

	// A customer ID stored on THIS event was created for this event: ours to
	// overwrite in full.
	$customer_id = (string) law_event_meta( $event_id, '_law_stripe_customer_id' );
	if ( '' !== $customer_id ) {
		$updated = law_stripe_request( 'POST', '/v1/customers/' . rawurlencode( $customer_id ), $body );
		if ( ! is_wp_error( $updated ) ) {
			return $updated;
		}
		// The stored ID may be stale (deleted customer / other mode): fall through.
	}

	$found = law_stripe_request( 'GET', '/v1/customers', array( 'email' => $email, 'limit' => 1 ) );
	$match = ( ! is_wp_error( $found ) && ! empty( $found['data'][0]['id'] ) ) ? (array) $found['data'][0] : array();

	if ( $match ) {
		$ownership = law_stripe_customer_ownership( $match, $event_id );

		if ( 'other' === $ownership ) {
			law_event_log(
				$event_id,
				sprintf(
					'Stripe customer %s already shares this invoice email but belongs to another event; a separate customer was created rather than overwriting it.',
					(string) $match['id']
				),
				array( 'action' => 'customer_not_reused', 'customer' => (string) $match['id'], 'source' => 'stripe' )
			);
		} else {
			$patch   = 'mine' === $ownership ? $body : law_stripe_customer_fill_blanks( $match, $body );
			$updated = law_stripe_request( 'POST', '/v1/customers/' . rawurlencode( (string) $match['id'] ), $patch );
			if ( ! is_wp_error( $updated ) ) {
				if ( 'unclaimed' === $ownership ) {
					law_event_log(
						$event_id,
						sprintf( 'Existing Stripe customer %s adopted by invoice email; only empty fields were filled.', (string) $match['id'] ),
						array( 'action' => 'customer_adopted', 'customer' => (string) $match['id'], 'source' => 'stripe' )
					);
				}
				return $updated;
			}
		}
	}

	return law_stripe_request( 'POST', '/v1/customers', $body );
}

/**
 * Attach the VAT number as a customer tax ID when present. Like the Make
 * scenario's Resume handler this is never fatal, but unlike Make the failure
 * is validated first and logged (EVENTS_4.1_REBUILD.md §2.3.1 route 1).
 */
function law_stripe_maybe_attach_vat_number( $event_id, $customer_id ) {
	$vat = strtoupper( preg_replace( '/\s+/', '', (string) law_event_meta( $event_id, '_law_vat_number' ) ) );
	if ( '' === $vat ) {
		return;
	}

	$eu = array( 'AT','BE','BG','HR','CY','CZ','DK','EE','FI','FR','DE','EL','GR','HU','IE','IT','LV','LT','LU','MT','NL','PL','PT','RO','SK','SI','ES','SE','XI' );
	$prefix = substr( $vat, 0, 2 );
	if ( 'GB' === $prefix ) {
		$type = 'gb_vat';
	} elseif ( in_array( $prefix, $eu, true ) ) {
		$type = 'eu_vat';
	} else {
		law_event_log(
			$event_id,
			sprintf( 'VAT number "%s" not attached: not a GB or EU VAT format Stripe accepts.', $vat ),
			array( 'action' => 'vat_number_skipped', 'source' => 'stripe' )
		);
		return;
	}

	$result = law_stripe_request(
		'POST',
		'/v1/customers/' . rawurlencode( $customer_id ) . '/tax_ids',
		array( 'type' => $type, 'value' => $vat )
	);

	if ( is_wp_error( $result ) ) {
		law_event_log(
			$event_id,
			sprintf( 'VAT number attach failed (non-fatal): %s', $result->get_error_message() ),
			array( 'action' => 'vat_number_failed', 'source' => 'stripe' )
		);
	} else {
		law_event_log(
			$event_id,
			sprintf( 'VAT number %s attached to the Stripe customer as %s.', $vat, $type ),
			array( 'action' => 'vat_number_attached', 'source' => 'stripe' )
		);
	}
}

/**
 * Record an invoice failure: error meta, activity log, admin alert.
 * The event stays at Approved; the committee sees a Retry button.
 */
function law_stripe_record_failure( $event_id, WP_Error $error ) {
	law_event_update_meta(
		$event_id,
		'_law_stripe_error',
		array( 'message' => $error->get_error_message(), 'at' => current_time( 'mysql' ) )
	);
	law_event_log(
		$event_id,
		'Stripe invoice creation FAILED: ' . $error->get_error_message(),
		array( 'action' => 'invoice_failed', 'error' => $error->get_error_message(), 'source' => 'stripe' )
	);
	law_events_send( 'admin_stripe_error', $event_id );
	law_events_send( 'admin_stripe_error', $event_id, array( 'to' => law_events_committee_emails() ) );
}

/**
 * Stop a live invoice when its event is cancelled: delete a draft, void an
 * open (or uncollectible) one, leave a paid one strictly alone — refunds are
 * a manual committee decision, alerted separately by the cancel side effects.
 *
 * NEVER fatal: cancellation must complete whatever Stripe says, so every
 * failure here is logged and alerted (admins + committee, the invoice-failure
 * precedent) rather than returned up as a blocker. The invoice ID/URL meta is
 * kept for the audit trail; nothing can re-enter the invoice path from
 * law-cancelled (the retry guard requires Approved, and approve's from-list
 * excludes cancelled).
 *
 * Also the first half of a post-approval host fee change
 * (law_event_apply_fee_change()): the invoice raised from the old snapshot has
 * to stop being payable before its replacement goes out, or the host holds two
 * live invoices for the same event. $context only changes the WORDING — a log
 * line reading "voided after cancellation" on an event that was never
 * cancelled is worse than no log line, because somebody will believe it.
 *
 * @param int    $event_id law_event post ID.
 * @param int    $actor    Acting user ID (0 = system), for the log lines.
 * @param string $context  'cancellation' | 'fee_change'.
 * @return string 'none' | 'deleted' | 'voided' | 'already_void' | 'left_paid' | 'failed'
 */
function law_stripe_void_invoice( $event_id, $actor = 0, $context = 'cancellation' ) {
	$event_id   = (int) $event_id;
	$invoice_id = (string) law_event_meta( $event_id, '_law_stripe_invoice_id' );
	$log_extra  = array( 'user_id' => (int) $actor );

	// One wording per reason to void, so every sentence below reads as the
	// thing that actually happened.
	$fee_change = 'fee_change' === $context;
	$because    = $fee_change ? 'after the host fee was changed' : 'after cancellation';

	if ( '' === $invoice_id ) {
		// A migrated event may hold a LIVE invoice with only its web address on
		// record (field 83 (Stripe invoice URL) on form 2 (Event > submit an
		// event) was all the retired Make scenario logged). Saying nothing here
		// would leave that invoice open and payable behind a cancellation email
		// telling the host no payment is due, so it is called out and alerted
		// exactly like a failed void.
		$legacy_url = trim( (string) law_event_meta( $event_id, '_law_stripe_invoice_url' ) );
		if ( '' !== $legacy_url && 'paid' !== (string) law_event_meta( $event_id, '_law_payment_status' ) ) {
			law_event_log(
				$event_id,
				sprintf(
					'%s while holding a pre-rebuild Stripe invoice whose ID was never recorded (%s). It could NOT be voided automatically and may still be payable: void it by hand in Stripe, or run "Repair: legacy Stripe invoices with no invoice ID" on LAW → Migration and try again.',
					$fee_change ? 'The host fee was changed' : 'Cancelled',
					$legacy_url
				),
				array( 'action' => 'invoice_void_unknown', 'invoice_url' => $legacy_url, 'source' => 'stripe' ),
				$log_extra
			);
			$alert = array(
				'placeholders' => array(
					'stripe_error' => sprintf(
						'Event #%d %s while holding a pre-rebuild invoice with no ID on record (%s). It was not voided automatically; void it by hand in Stripe.',
						$event_id,
						$fee_change ? 'had its host fee changed' : 'was cancelled',
						$legacy_url
					),
				),
			);
			law_events_send( 'admin_stripe_error', $event_id, $alert );
			law_events_send( 'admin_stripe_error', $event_id, $alert + array( 'to' => law_events_committee_emails() ) );
			return 'failed';
		}

		law_event_log(
			$event_id,
			sprintf(
				'%s with no Stripe invoice on record (payment status: %s).',
				$fee_change ? 'Host fee changed' : 'Cancelled',
				(string) law_event_meta( $event_id, '_law_payment_status' ) ?: '(none)'
			),
			array( 'action' => 'invoice_void_skipped', 'source' => 'stripe' ),
			$log_extra
		);
		return 'none';
	}

	$fail = function ( $step, WP_Error $error ) use ( $event_id, $invoice_id, $log_extra, $because, $fee_change ) {
		law_event_log(
			$event_id,
			sprintf( 'Stripe invoice %s could not be %s %s: %s. Void it manually in Stripe.', $invoice_id, $step, $because, $error->get_error_message() ),
			array( 'action' => 'invoice_void_failed', 'invoice_id' => $invoice_id, 'error' => $error->get_error_message(), 'source' => 'stripe' ),
			$log_extra
		);
		$alert = array(
			'placeholders' => array(
				'stripe_error' => sprintf(
					'Voiding invoice %s %s failed: %s. %s',
					$invoice_id,
					$because,
					$error->get_error_message(),
					$fee_change
						? 'The fee has NOT been changed and no replacement invoice was raised, so the original invoice is still the one to pay.'
						: 'The event is cancelled; void the invoice manually in Stripe.'
				),
			),
		);
		law_events_send( 'admin_stripe_error', $event_id, $alert );
		law_events_send( 'admin_stripe_error', $event_id, $alert + array( 'to' => law_events_committee_emails() ) );
		return 'failed';
	};

	$invoice = law_stripe_request( 'GET', '/v1/invoices/' . rawurlencode( $invoice_id ), array() );
	if ( is_wp_error( $invoice ) ) {
		return $fail( 'read', $invoice );
	}

	$status = (string) ( $invoice['status'] ?? '' );
	switch ( $status ) {
		case 'draft':
			$deleted = law_stripe_request( 'DELETE', '/v1/invoices/' . rawurlencode( $invoice_id ), array() );
			if ( is_wp_error( $deleted ) ) {
				return $fail( 'deleted', $deleted );
			}
			law_event_log(
				$event_id,
				sprintf( 'Draft Stripe invoice %s deleted %s.', $invoice_id, $because ),
				array( 'action' => 'invoice_deleted', 'invoice_id' => $invoice_id, 'source' => 'stripe' ),
				$log_extra
			);
			return 'deleted';

		case 'open':
		case 'uncollectible':
			$voided = law_stripe_request(
				'POST',
				'/v1/invoices/' . rawurlencode( $invoice_id ) . '/void',
				array(),
				sprintf( 'law-void-%d-%s', $event_id, $invoice_id )
			);
			if ( is_wp_error( $voided ) ) {
				return $fail( 'voided', $voided );
			}
			law_event_log(
				$event_id,
				sprintf(
					'Stripe invoice %s (%s) voided %s; %s',
					$invoice_id,
					$status,
					$because,
					$fee_change ? 'it can no longer be paid.' : 'no payment is due.'
				),
				array( 'action' => 'invoice_voided', 'invoice_id' => $invoice_id, 'old_status' => $status, 'source' => 'stripe' ),
				$log_extra
			);
			return 'voided';

		case 'void':
			law_event_log(
				$event_id,
				sprintf( 'Stripe invoice %s is already void; nothing to do.', $invoice_id ),
				array( 'action' => 'invoice_void_skipped', 'invoice_id' => $invoice_id, 'source' => 'stripe' ),
				$log_extra
			);
			return 'already_void';

		case 'paid':
		default:
			law_event_log(
				$event_id,
				sprintf(
					'Stripe invoice %s is %s and was left untouched. %s',
					$invoice_id,
					$status ?: 'in an unknown state',
					$fee_change
						? 'The host fee was therefore NOT changed: settle the difference in Stripe (a credit note or a refund) instead.'
						: 'Refunds are a manual committee decision.'
				),
				array( 'action' => 'invoice_left_paid', 'invoice_id' => $invoice_id, 'status' => $status, 'source' => 'stripe' ),
				$log_extra
			);
			return 'left_paid';
	}
}

/* Post-approval fee change: void, re-snapshot, reissue ______________________ */

/**
 * Apply a host fee change to an event whose fee was already snapshotted and
 * invoiced: void the invoice raised from the old snapshot, re-freeze the
 * snapshot, and raise a fresh invoice for the host to pay.
 *
 * The committee kept the post-approval override on the dashboard (Denis,
 * 17 September 2026). Before that the control went read-only at approval,
 * because a change reached neither the snapshot nor the invoice and so
 * reported a success that changed nothing that mattered. The answer is not to
 * hide the control but to make the change land everywhere the old figure went,
 * which is the three steps below plus the "payment due" email that tells the
 * host which invoice to pay.
 *
 * ORDER MATTERS, and the order is void first. The alternative — raise the new
 * invoice, then void the old — leaves the host holding two payable invoices
 * for the same event for as long as the second call takes, and if that call
 * fails, permanently. Voiding first can only ever leave them holding none,
 * which is a state the committee can see (Unpaid, no invoice URL) and fix with
 * the existing invoice retry button.
 *
 * Refused, with nothing written, when:
 * - the fee is settled or the event is not live (law_event_fee_edit_mode());
 * - the event holds a pre-rebuild invoice recorded as a web address only, so
 *   there is no ID to void it by (LAW → Migration has the repair);
 * - the void itself failed, because the old invoice is then still payable and
 *   a second one must not join it.
 *
 * A fee changed to zero raises nothing: the event becomes Free, and an
 * Approved event is confirmed in the same breath, exactly as approving it at
 * zero would have done. Never route anyone through a payment step for £0.
 *
 * @param int    $event_id law_event post ID.
 * @param int    $actor    Acting user ID.
 * @param string $source   Where the change came from ('ui' / 'admin_edit'), for the log.
 * @return array{outcome:string,was:int,fee_pence:int,vat:int,void:string}|WP_Error
 *         outcome: unchanged | reissued | waived
 */
function law_event_apply_fee_change( $event_id, $actor = 0, $source = 'ui' ) {
	$event_id = (int) $event_id;
	$mode     = law_event_fee_edit_mode( $event_id );
	if ( 'reissue' !== $mode ) {
		return new WP_Error(
			'law_fee_not_reissuable',
			'locked' === $mode
				? 'This event\'s fee can no longer be changed: it is either already paid or refunded, or the event is no longer live. Settle the difference in Stripe (a credit note or a refund) and set the payment status to match.'
				: 'This event has not been approved yet, so there is no snapshot or invoice to reissue; the fee is simply saved.'
		);
	}

	$was = (int) law_event_meta( $event_id, '_law_fee_pence' );
	$now = law_event_calculate_fee_pence( $event_id );
	if ( $was === $now ) {
		// The override flag moved but the money did not (ticking "override" at
		// exactly the tier price, say). Nothing is voided for a figure that has
		// not moved: the host would get a new invoice for the same amount and
		// wonder which one to pay.
		return array(
			'outcome'   => 'unchanged',
			'was'       => $was,
			'fee_pence' => $now,
			'vat'       => (int) law_event_meta( $event_id, '_law_vat' ),
			'void'      => 'none',
		);
	}

	// A pre-rebuild invoice with no ID beside it cannot be voided, and raising
	// a second one would bill the host twice. Same refusal, and the same
	// repair, as law_stripe_create_and_send_invoice()'s own legacy guard.
	$invoice_id = (string) law_event_meta( $event_id, '_law_stripe_invoice_id' );
	if ( '' === $invoice_id && '' !== trim( (string) law_event_meta( $event_id, '_law_stripe_invoice_url' ) ) ) {
		law_event_log(
			$event_id,
			'Host fee change NOT applied: this event holds an invoice from before the rebuild, recorded as a web address only, with no ID to void it by. Run "Repair: legacy Stripe invoices with no invoice ID" on LAW → Migration, then try again.',
			array( 'action' => 'fee_change_refused', 'reason' => 'legacy_invoice', 'source' => $source ),
			array( 'user_id' => (int) $actor )
		);
		return new WP_Error(
			'law_legacy_invoice',
			'This event holds an invoice raised before the rebuild, and only its web address was recorded, not its ID, so it cannot be voided automatically. Run "Repair: legacy Stripe invoices with no invoice ID" on LAW → Migration first, then change the fee.'
		);
	}

	law_event_log(
		$event_id,
		sprintf(
			'Host fee change requested: %s → %s. Voiding the open invoice and raising a replacement.',
			law_events_format_pence( $was ),
			law_events_format_pence( $now )
		),
		array( 'action' => 'fee_change', 'old' => $was, 'new' => $now, 'source' => $source ),
		array( 'user_id' => (int) $actor )
	);

	// 1. Stop the old invoice. law_stripe_void_invoice() logs and alerts on
	//    every outcome itself, so only the decision is taken here.
	$void = '' !== $invoice_id ? law_stripe_void_invoice( $event_id, $actor, 'fee_change' ) : 'none';
	if ( 'failed' === $void ) {
		return new WP_Error(
			'law_void_failed',
			'The fee was NOT changed: the open Stripe invoice could not be voided, and raising a second one would leave the host holding two. Void it by hand in Stripe, then change the fee again. The activity log has the error.'
		);
	}
	if ( 'left_paid' === $void ) {
		// Paid between law_event_fee_edit_mode() above and this call, or the
		// local payment status is behind Stripe. Either way the money has moved.
		return new WP_Error(
			'law_fee_settled',
			'The fee was NOT changed: Stripe reports this invoice as already settled. Settle the difference in Stripe (a credit note or a refund) and set the payment status here to match.'
		);
	}

	// 2. Re-freeze the snapshot the new invoice, the exports and the {fee}
	//    merge tag are all read from.
	$snapshot = law_event_resnapshot_fee( $event_id, $actor, $source );
	if ( is_wp_error( $snapshot ) ) {
		return $snapshot;
	}

	// 3a. A waived fee raises nothing at all.
	if ( $snapshot['fee_pence'] < 1 ) {
		law_event_set_payment_status( $event_id, 'free', $source, $actor );
		law_event_log(
			$event_id,
			'Host fee waived after approval: the previous invoice is void and no new one was raised.',
			array( 'action' => 'fee_waived', 'old' => $was, 'source' => $source ),
			array( 'user_id' => (int) $actor )
		);
		// An Approved event waiting on a payment that is no longer coming would
		// wait for ever, so it is confirmed now, the way approving it at zero
		// would have confirmed it. A Confirmed event is already there.
		if ( 'law-approved' === get_post_status( $event_id ) ) {
			law_event_workflow_transition( $event_id, 'confirm', array( 'source' => 'system', 'actor_id' => (int) $actor ) );
		}
		return array(
			'outcome'   => 'waived',
			'was'       => $was,
			'fee_pence' => 0,
			'vat'       => 0,
			'void'      => $void,
		);
	}

	// 3b. A fee where there was none (a waiver reversed, or a Free event that
	//     now costs): the payment status has to go back to Unpaid, or the
	//     dashboard, the exports and the webhook reconciliation would all still
	//     read Free against a live invoice.
	if ( 'unpaid' !== (string) law_event_meta( $event_id, '_law_payment_status' ) ) {
		law_event_set_payment_status( $event_id, 'unpaid', $source, $actor );
	}

	$invoice = law_stripe_create_and_send_invoice( $event_id );
	if ( is_wp_error( $invoice ) ) {
		// The old invoice is already void, so the event is now Unpaid with
		// nothing to pay. Said plainly, because the fix is the existing retry
		// button rather than another fee edit.
		return new WP_Error(
			'law_reissue_failed',
			sprintf(
				'The fee is now %s and the previous invoice has been voided, but the replacement invoice could not be raised: %s. Use "Retry invoice" to raise it.',
				law_events_format_pence( $snapshot['fee_pence'] ),
				$invoice->get_error_message()
			)
		);
	}

	// Both figures, and led by "Important:". The body this lands in is whatever
	// the Emails screen holds, and the LAW body stored there names no amount at
	// all — it only links to the Stripe invoice — so a note saying only which
	// invoice died would leave the host with no idea what the new fee is. It
	// also cannot rely on its position: the provisioning helper appends the tag
	// to the end of a stored body rather than guessing a place inside somebody
	// else's wording, so it has to read as a warning wherever it lands.
	law_events_send(
		'user_payment_due',
		$event_id,
		array(
			'placeholders' => array(
				'fee_change_note' => sprintf(
					'Important: the fee for this event has changed to %s. This replaces the earlier invoice for %s, which has been cancelled and can no longer be paid.',
					law_events_format_pence( $snapshot['fee_pence'] ),
					law_events_format_pence( $was )
				),
			),
		)
	);

	return array(
		'outcome'   => 'reissued',
		'was'       => $was,
		'fee_pence' => $snapshot['fee_pence'],
		'vat'       => $snapshot['vat'],
		'void'      => $void,
	);
}

/* Retry (committee detail view + admin event screen) ________________________ */

add_action( 'admin_post_law_event_retry_invoice', 'law_event_handle_retry_invoice' );
function law_event_handle_retry_invoice() {
	if ( ! law_user_is_committee() ) {
		wp_die( 'Sorry, you are not allowed to retry invoices.' );
	}
	check_admin_referer( 'law_event_retry_invoice' );

	$event_id = absint( $_REQUEST['event_id'] ?? 0 );

	// Only a live, still-UNPAID event has an invoice worth retrying: the unpaid
	// test is what stops a settled event getting a fresh "payment due" email,
	// and it does that on its own. Confirmed joined Approved on 17 September
	// 2026, because a post-approval fee change can leave a Confirmed event
	// unpaid with its old invoice voided and the replacement not raised (the
	// Stripe call failed), and that is precisely the state this button exists
	// to recover. A Confirmed event that is unpaid genuinely owes the money.
	$post = get_post( $event_id );
	if ( ! $post || ! in_array( $post->post_status, array( 'law-approved', 'publish' ), true )
		|| 'unpaid' !== (string) law_event_meta( $event_id, '_law_payment_status' ) ) {
		law_events_redirect_back( array( 'law_notice' => 'invoice-not-retryable' ) );
	}

	law_event_log(
		$event_id,
		'Invoice retry requested.',
		array( 'action' => 'invoice_retry', 'source' => 'ui' )
	);

	$result = law_stripe_create_and_send_invoice( $event_id );
	if ( ! is_wp_error( $result ) ) {
		law_events_send( 'user_payment_due', $event_id );
	}

	law_events_redirect_back(
		array( 'law_notice' => is_wp_error( $result ) ? 'invoice-failed' : 'invoice-sent' )
	);
}
