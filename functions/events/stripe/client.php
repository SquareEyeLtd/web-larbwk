<?php
/**
 * Thin Stripe API client over wp_remote_request (no SDK, no vendor tree).
 * Form-encoded requests, pinned API version matching the retired Make
 * scenarios, and webhook signature verification.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_STRIPE_API_VERSION = '2025-09-30.clover';

function law_stripe_secret_key() {
	return defined( 'LAW_STRIPE_SECRET_KEY' ) ? (string) LAW_STRIPE_SECRET_KEY : '';
}

function law_stripe_webhook_secret() {
	return defined( 'LAW_STRIPE_WEBHOOK_SECRET' ) ? (string) LAW_STRIPE_WEBHOOK_SECRET : '';
}

/**
 * One Stripe API call.
 *
 * @param string $method          GET / POST / DELETE.
 * @param string $path            e.g. '/v1/invoices'.
 * @param array  $body            Request params (form-encoded; nested arrays allowed).
 * @param string $idempotency_key Optional Idempotency-Key for mutating calls,
 *                                so a timed-out request retried with the same
 *                                key returns the original object instead of
 *                                creating a duplicate.
 * @return array|WP_Error Decoded response body.
 */
function law_stripe_request( $method, $path, array $body = array(), $idempotency_key = '' ) {
	// Test seam: unit tests short-circuit the network with this filter.
	$mocked = apply_filters( 'law_stripe_request_mock', null, $method, $path, $body );
	if ( null !== $mocked ) {
		return $mocked;
	}

	$key = law_stripe_secret_key();
	if ( '' === $key ) {
		return new WP_Error( 'law_stripe_unconfigured', 'Stripe secret key is not configured (LAW_STRIPE_SECRET_KEY).' );
	}

	$args = array(
		'method'  => strtoupper( $method ),
		'timeout' => 30,
		'headers' => array(
			'Authorization'  => 'Bearer ' . $key,
			'Stripe-Version' => LAW_STRIPE_API_VERSION,
			'Content-Type'   => 'application/x-www-form-urlencoded',
		),
	);
	if ( '' !== $idempotency_key && 'POST' === $args['method'] ) {
		$args['headers']['Idempotency-Key'] = substr( $idempotency_key, 0, 255 );
	}

	$url = 'https://api.stripe.com' . $path;
	if ( 'GET' === $args['method'] ) {
		if ( $body ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $body );
		}
	} else {
		$args['body'] = http_build_query( $body );
	}

	$response = wp_remote_request( $url, $args );
	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code    = (int) wp_remote_retrieve_response_code( $response );
	$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
	if ( ! is_array( $decoded ) ) {
		return new WP_Error( 'law_stripe_bad_response', 'Stripe returned an unreadable response (HTTP ' . $code . ').' );
	}

	if ( $code >= 400 ) {
		$message = (string) ( $decoded['error']['message'] ?? 'Unknown Stripe error' );
		return new WP_Error( 'law_stripe_api_error', $message, array( 'status' => $code, 'stripe' => $decoded['error'] ?? array() ) );
	}

	return $decoded;
}

/**
 * Verify a Stripe webhook signature (Stripe-Signature header, scheme v1:
 * HMAC-SHA256 over "{timestamp}.{payload}").
 *
 * @param string $payload   Raw request body.
 * @param string $header    Stripe-Signature header value.
 * @param int    $tolerance Max age in seconds (replay window).
 * @return true|WP_Error
 */
function law_stripe_verify_signature( $payload, $header, $tolerance = 300 ) {
	$secret = law_stripe_webhook_secret();
	if ( '' === $secret ) {
		return new WP_Error( 'law_stripe_no_webhook_secret', 'Webhook secret is not configured (LAW_STRIPE_WEBHOOK_SECRET).' );
	}

	$timestamp  = 0;
	$signatures = array();
	foreach ( explode( ',', (string) $header ) as $pair ) {
		$parts = explode( '=', trim( $pair ), 2 );
		if ( 2 !== count( $parts ) ) {
			continue;
		}
		if ( 't' === $parts[0] ) {
			$timestamp = (int) $parts[1];
		} elseif ( 'v1' === $parts[0] ) {
			$signatures[] = $parts[1];
		}
	}

	if ( ! $timestamp || ! $signatures ) {
		return new WP_Error( 'law_stripe_bad_signature', 'Malformed Stripe-Signature header.' );
	}
	if ( abs( time() - $timestamp ) > $tolerance ) {
		return new WP_Error( 'law_stripe_stale_signature', 'Stripe signature timestamp outside tolerance.' );
	}

	$expected = hash_hmac( 'sha256', $timestamp . '.' . $payload, $secret );
	foreach ( $signatures as $signature ) {
		if ( hash_equals( $expected, $signature ) ) {
			return true;
		}
	}

	return new WP_Error( 'law_stripe_bad_signature', 'Stripe signature verification failed.' );
}
