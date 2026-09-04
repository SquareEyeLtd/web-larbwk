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
		'metadata'          => array_filter(
			array(
				'law_reference' => (string) law_event_meta( $event_id, '_law_reference' ),
				'law_event_id'  => (string) $event_id,
				'gf_entry_id'   => (string) law_event_meta( $event_id, '_law_gf_entry_id' ),
			)
		),
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
 * Upsert the Stripe customer: stored ID first, then exact email search,
 * else create. Names are mapped properly (fixing the live empty-name defect):
 * name = invoice contact, business_name = host organisation(s).
 *
 * @return array|WP_Error Customer object.
 */
function law_stripe_upsert_customer( $event_id, WP_Post $post ) {
	// Lowercased: Stripe's ?email= filter is an exact, case-sensitive match,
	// and a casing mismatch would create a duplicate customer.
	$email = mb_strtolower( (string) law_event_meta( $event_id, '_law_invoice_email' ) );
	if ( ! is_email( $email ) ) {
		return new WP_Error( 'law_no_invoice_email', 'The event has no valid invoice contact email.' );
	}

	$address = law_event_meta( $event_id, '_law_invoice_address' );
	$iso     = (string) law_event_meta( $event_id, '_law_country_iso' );
	$name    = (string) law_event_meta( $event_id, '_law_invoice_name' );
	$org     = (string) law_event_meta( $event_id, '_law_host_organisations' );

	$body = array(
		'name'            => $name,
		'email'           => $email,
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
		'metadata'        => array_filter(
			array(
				'law_reference' => (string) law_event_meta( $event_id, '_law_reference' ),
				'law_event_id'  => (string) $event_id,
				'gf_entry_id'   => (string) law_event_meta( $event_id, '_law_gf_entry_id' ),
			)
		),
	);

	$customer_id = (string) law_event_meta( $event_id, '_law_stripe_customer_id' );
	if ( '' === $customer_id ) {
		$found = law_stripe_request( 'GET', '/v1/customers', array( 'email' => $email, 'limit' => 1 ) );
		if ( ! is_wp_error( $found ) && ! empty( $found['data'][0]['id'] ) ) {
			$customer_id = (string) $found['data'][0]['id'];
		}
	}

	if ( '' !== $customer_id ) {
		$updated = law_stripe_request( 'POST', '/v1/customers/' . rawurlencode( $customer_id ), $body );
		if ( ! is_wp_error( $updated ) ) {
			return $updated;
		}
		// The stored ID may be stale (deleted customer / other mode): fall through to create.
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

/* Retry (committee detail view + admin event screen) ________________________ */

add_action( 'admin_post_law_event_retry_invoice', 'law_event_handle_retry_invoice' );
function law_event_handle_retry_invoice() {
	if ( ! law_user_is_committee() ) {
		wp_die( 'Sorry, you are not allowed to retry invoices.' );
	}
	check_admin_referer( 'law_event_retry_invoice' );

	$event_id = absint( $_REQUEST['event_id'] ?? 0 );

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
