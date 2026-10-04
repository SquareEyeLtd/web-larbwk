<?php
/**
 * Thin HubSpot API client over wp_remote_request (_docs/HUBSPOT_SYNC.md §6.1).
 *
 * JSON in, JSON out, bearer token, retries. This file knows nothing about
 * events, bookings or users: it reads its token and mode from config.php and
 * nothing else from the theme, so it could be lifted into a plugin unchanged.
 *
 * Modes (law_hubspot_mode()):
 *  - off:  every request returns a WP_Error and nothing leaves the server.
 *  - dry:  reads run for real, so a dry run can diff against the portal;
 *          writes fire `law_hubspot_dry_write` (the log table listens) and
 *          return a fake success carrying `dry_run => true`.
 *  - live: everything runs.
 *
 * Retries: 429 honours Retry-After; 5xx and transport errors back off
 * 1s, 2s; both give up after three attempts. Other 4xx responses are not
 * retried and come back as a WP_Error carrying HubSpot's own message, because
 * a bad option value or a missing property is a bug to fix, not a blip.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_HUBSPOT_MAX_ATTEMPTS = 3;

/**
 * One HubSpot API call.
 *
 * @param string     $method  GET / POST / PATCH / PUT / DELETE.
 * @param string     $path    e.g. '/crm/v3/objects/contacts/batch/read'.
 * @param array|null $body    JSON body for writes, query args for GET.
 * @param array      $options timeout (seconds, default 15).
 * @return array|WP_Error Decoded response body (array() for an empty 2xx).
 */
function law_hubspot_request( $method, $path, $body = null, array $options = array() ) {
	$method = strtoupper( (string) $method );
	$path   = '/' . ltrim( (string) $path, '/' );

	// Test seam, the same shape as law_stripe_request_mock: a unit test can
	// answer a call without touching the HTTP layer at all.
	$mocked = apply_filters( 'law_hubspot_request_mock', null, $method, $path, $body );
	if ( null !== $mocked ) {
		return $mocked;
	}

	$mode = law_hubspot_mode();
	if ( 'off' === $mode ) {
		return new WP_Error( 'law_hubspot_off', 'HubSpot sync is off (LAW_HUBSPOT_MODE is unset or off).' );
	}

	// Before the token check on purpose: a dry run with no token still records
	// what it would have sent, which is the point of a dry run.
	if ( 'dry' === $mode && law_hubspot_request_is_write( $method, $path ) ) {
		do_action( 'law_hubspot_dry_write', $method, $path, $body );
		return array(
			'dry_run' => true,
			'method'  => $method,
			'path'    => $path,
			'body'    => $body,
		);
	}

	$token = law_hubspot_token();
	if ( '' === $token ) {
		return new WP_Error( 'law_hubspot_unconfigured', 'HubSpot token is not configured (LAW_HUBSPOT_TOKEN).' );
	}

	$url  = LAW_HUBSPOT_BASE_URL . $path;
	$args = array(
		'method'  => $method,
		'timeout' => (int) ( $options['timeout'] ?? 15 ),
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
			'Accept'        => 'application/json',
		),
	);
	if ( 'GET' === $method ) {
		if ( is_array( $body ) && $body ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . http_build_query( $body );
		}
	} elseif ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}

	$last_error = null;
	for ( $attempt = 1; $attempt <= LAW_HUBSPOT_MAX_ATTEMPTS; $attempt++ ) {
		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// Transport failure (timeout, DNS, TLS): worth another go.
			$last_error = new WP_Error( 'law_hubspot_transport', $response->get_error_message(), array( 'attempt' => $attempt ) );
			if ( $attempt < LAW_HUBSPOT_MAX_ATTEMPTS ) {
				law_hubspot_sleep( law_hubspot_backoff_seconds( $attempt ) );
			}
			continue;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$raw  = (string) wp_remote_retrieve_body( $response );

		if ( 429 === $code ) {
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$last_error  = new WP_Error( 'law_hubspot_rate_limited', 'HubSpot rate limit reached.', array( 'status' => 429, 'attempt' => $attempt ) );
			if ( $attempt < LAW_HUBSPOT_MAX_ATTEMPTS ) {
				law_hubspot_sleep( $retry_after > 0 ? min( $retry_after, 30 ) : law_hubspot_backoff_seconds( $attempt ) );
			}
			continue;
		}

		if ( $code >= 500 ) {
			$last_error = new WP_Error( 'law_hubspot_server_error', 'HubSpot returned HTTP ' . $code . '.', array( 'status' => $code, 'attempt' => $attempt ) );
			if ( $attempt < LAW_HUBSPOT_MAX_ATTEMPTS ) {
				law_hubspot_sleep( law_hubspot_backoff_seconds( $attempt ) );
			}
			continue;
		}

		$decoded = '' === trim( $raw ) ? array() : json_decode( $raw, true );

		if ( $code >= 400 ) {
			return law_hubspot_api_error( $code, is_array( $decoded ) ? $decoded : array(), $raw );
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'law_hubspot_bad_response', 'HubSpot returned an unreadable response (HTTP ' . $code . ').', array( 'status' => $code ) );
		}

		return $decoded;
	}

	return $last_error instanceof WP_Error
		? $last_error
		: new WP_Error( 'law_hubspot_failed', 'HubSpot request failed.' );
}

/**
 * Is this request a write, for the dry-mode gate? GET never is; the two POST
 * endpoints HubSpot uses for reading (batch/read and search) are not either.
 */
function law_hubspot_request_is_write( $method, $path ) {
	$method = strtoupper( (string) $method );
	if ( 'GET' === $method || 'HEAD' === $method ) {
		return false;
	}
	if ( 'POST' === $method && preg_match( '#/(batch/read|search)$#', (string) $path ) ) {
		return false;
	}
	return true;
}

/** 1s, 2s, 4s ... between attempts. */
function law_hubspot_backoff_seconds( $attempt ) {
	return (int) pow( 2, max( 0, (int) $attempt - 1 ) );
}

/**
 * Wait between attempts. Filterable to zero so tests can assert on the delay
 * the client asked for without actually waiting it out.
 */
function law_hubspot_sleep( $seconds ) {
	$seconds = (int) apply_filters( 'law_hubspot_sleep_seconds', (int) $seconds );
	if ( $seconds > 0 ) {
		sleep( $seconds );
	}
}

/**
 * A 4xx response as a WP_Error that says what HubSpot said. HubSpot's error
 * bodies carry `message`, `category` and sometimes `errors[]` with per-field
 * detail (the one that names a bad enumeration value); all of it is kept in
 * the error data for the log and the admin screen.
 */
function law_hubspot_api_error( $code, array $decoded, $raw = '' ) {
	$message = (string) ( $decoded['message'] ?? '' );
	if ( '' === $message ) {
		$message = '' !== trim( (string) $raw ) ? substr( trim( (string) $raw ), 0, 300 ) : 'Unknown HubSpot error';
	}
	$details = array();
	foreach ( (array) ( $decoded['errors'] ?? array() ) as $error ) {
		if ( is_array( $error ) && ! empty( $error['message'] ) ) {
			$details[] = (string) $error['message'];
		}
	}
	if ( $details ) {
		$message .= ' (' . implode( '; ', $details ) . ')';
	}
	return new WP_Error(
		'law_hubspot_api_error',
		'HubSpot: ' . $message,
		array(
			'status'   => (int) $code,
			'category' => (string) ( $decoded['category'] ?? '' ),
			'hubspot'  => $decoded,
		)
	);
}

/**
 * Was this result a dry-mode fake success?
 *
 * @param mixed $result A law_hubspot_request() return value.
 */
function law_hubspot_result_is_dry( $result ) {
	return is_array( $result ) && ! empty( $result['dry_run'] );
}

/** Up to 100 per batch, HubSpot's limit on the contacts batch endpoints. */
function law_hubspot_chunk( array $items, $size = 100 ) {
	return $items ? array_chunk( array_values( $items ), max( 1, (int) $size ) ) : array();
}

/**
 * Read contacts by email (default) or by record ID.
 *
 * @param string[] $ids         Emails or HubSpot IDs, up to 100.
 * @param string[] $properties  Property names to return.
 * @param string   $id_property 'email' or '' for record IDs.
 * @return array|WP_Error HubSpot's batch response (results[], and errors[] for ids not found).
 */
function law_hubspot_batch_read( array $ids, array $properties, $id_property = 'email' ) {
	$body = array(
		'inputs'     => array_map( fn( $id ) => array( 'id' => (string) $id ), array_values( $ids ) ),
		'properties' => array_values( $properties ),
	);
	if ( '' !== (string) $id_property ) {
		$body['idProperty'] = (string) $id_property;
	}
	return law_hubspot_request( 'POST', '/crm/v3/objects/contacts/batch/read', $body );
}

/**
 * Create-or-update contacts by email.
 *
 * @param array[] $inputs Each: array( 'email' => ..., 'properties' => array(...) ).
 */
function law_hubspot_batch_upsert( array $inputs ) {
	$body = array(
		'inputs' => array_map(
			fn( $input ) => array(
				'idProperty' => 'email',
				'id'         => (string) $input['email'],
				'properties' => (array) ( $input['properties'] ?? array() ),
			),
			array_values( $inputs )
		),
	);
	return law_hubspot_request( 'POST', '/crm/v3/objects/contacts/batch/upsert', $body );
}

/**
 * Update contacts by HubSpot record ID.
 *
 * @param array[] $inputs Each: array( 'id' => ..., 'properties' => array(...) ).
 */
function law_hubspot_batch_update( array $inputs ) {
	$body = array(
		'inputs' => array_map(
			fn( $input ) => array(
				'id'         => (string) $input['id'],
				'properties' => (array) ( $input['properties'] ?? array() ),
			),
			array_values( $inputs )
		),
	);
	return law_hubspot_request( 'POST', '/crm/v3/objects/contacts/batch/update', $body );
}

/**
 * The cheapest authenticated call there is: one property definition, read
 * with a scope the app must hold anyway. Used by the admin screen and the
 * CLI to answer "does the token work" without touching a contact.
 *
 * @return true|WP_Error
 */
function law_hubspot_token_check() {
	$result = law_hubspot_request( 'GET', '/crm/v3/properties/contacts/email' );
	return is_wp_error( $result ) ? $result : true;
}
