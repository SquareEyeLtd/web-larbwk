<?php
/**
 * The sync step: desired state in, HubSpot contacts out (_docs/HUBSPOT_SYNC.md
 * §6.3 worker, §8 backfill).
 *
 * One code path for everything. law_hubspot_plan_batch() computes the desired
 * state for a set of addresses, reads what HubSpot holds, and merges the two
 * into the exact properties to write, with before/after for the shared ones.
 * law_hubspot_sync_batch() runs that plan and writes it; law_hubspot_preview()
 * runs the same plan and writes a CSV instead. So a dry run, the preview and
 * the live push cannot disagree about what a person should get.
 *
 * Writes go by HubSpot record ID once one is known (batch/update), and by
 * email only for a first contact (batch/upsert). Per-item failures are
 * attributed to the person; a whole-batch 4xx is retried one person at a
 * time so one bad value cannot block ninety-nine good ones (§14.3).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** The shared properties the plan reads back before merging. */
function law_hubspot_read_properties() {
	$config = law_hubspot_config();
	return array( 'email', $config['contact_type_property'], $config['legal_basis_property'] );
}

/* -------------------------------------------------------------------------
 * The plan
 * ---------------------------------------------------------------------- */

/**
 * What would be written for each address, and why.
 *
 * @param string[] $emails
 * @return array{plans: array<string,array>, error: WP_Error|null}
 *   Each plan:
 *   array(
 *     'state'      => law_hubspot_desired_state() result (null when unknown),
 *     'exists'     => bool,            // in HubSpot already
 *     'hubspot_id' => string,          // known record ID ('' for a new contact)
 *     'current'    => array,           // the read-back shared properties
 *     'properties' => array,           // exactly what will be written
 *     'before'     => array( 'contact_type' => '', 'legal_basis' => '' ),
 *     'after'      => array( 'contact_type' => '', 'legal_basis' => '' ),
 *     'added_tags' => string[],        // Contact type values this push adds
 *   )
 */
function law_hubspot_plan_batch( array $emails ) {
	$config  = law_hubspot_config();
	$ct_prop = $config['contact_type_property'];
	$lb_prop = $config['legal_basis_property'];
	$plans   = array();

	if ( function_exists( 'law_speakers_flush_maps' ) ) {
		law_speakers_flush_maps();
	}

	$states = array();
	foreach ( $emails as $email ) {
		$email = law_hubspot_normalise_email( $email );
		if ( '' === $email || isset( $plans[ $email ] ) ) {
			continue;
		}
		$state = law_hubspot_desired_state( $email );
		if ( null === $state ) {
			$plans[ $email ] = array( 'state' => null );
			continue;
		}
		$states[ $email ] = $state;
	}

	// Read back what HubSpot holds: by stored ID where there is one, so an
	// email change on the site still finds the same contact; by email for the
	// rest. A stored ID HubSpot no longer knows falls back to email.
	$current = array();
	$by_id   = array();
	$by_mail = array();
	foreach ( $states as $email => $state ) {
		if ( '' !== $state['hubspot_id'] ) {
			$by_id[ $state['hubspot_id'] ] = $email;
		} else {
			$by_mail[] = $email;
		}
	}

	foreach ( law_hubspot_chunk( array_keys( $by_id ) ) as $chunk ) {
		$read = law_hubspot_batch_read( $chunk, law_hubspot_read_properties(), '' );
		if ( is_wp_error( $read ) ) {
			return array( 'plans' => array(), 'error' => $read );
		}
		foreach ( (array) ( $read['results'] ?? array() ) as $row ) {
			$id = (string) ( $row['id'] ?? '' );
			if ( isset( $by_id[ $id ] ) ) {
				$current[ $by_id[ $id ] ] = array( 'id' => $id, 'properties' => (array) ( $row['properties'] ?? array() ) );
			}
		}
		foreach ( $chunk as $id ) {
			if ( ! isset( $current[ $by_id[ $id ] ] ) ) {
				law_hubspot_forget_id( $states[ $by_id[ $id ] ] );
				$states[ $by_id[ $id ] ]['hubspot_id'] = '';
				$by_mail[]                             = $by_id[ $id ];
			}
		}
	}

	foreach ( law_hubspot_chunk( $by_mail ) as $chunk ) {
		$read = law_hubspot_batch_read( $chunk, law_hubspot_read_properties(), 'email' );
		if ( is_wp_error( $read ) ) {
			return array( 'plans' => array(), 'error' => $read );
		}
		foreach ( (array) ( $read['results'] ?? array() ) as $row ) {
			$email = law_hubspot_normalise_email( $row['properties']['email'] ?? '' );
			if ( '' !== $email && isset( $states[ $email ] ) ) {
				$current[ $email ] = array( 'id' => (string) ( $row['id'] ?? '' ), 'properties' => (array) ( $row['properties'] ?? array() ) );
			}
		}
	}

	foreach ( $states as $email => $state ) {
		$found    = $current[ $email ] ?? null;
		$existing = $found ? $found['properties'] : array();
		$ct_old   = (string) ( $existing[ $ct_prop ] ?? '' );
		$lb_old   = (string) ( $existing[ $lb_prop ] ?? '' );

		$properties = $state['properties'];
		unset( $properties['email'] ); // identity, not a property to write

		$ct_new = law_hubspot_merge_contact_type( $ct_old, $state['contact_type_add'] );
		if ( $ct_new !== $ct_old ) {
			$properties[ $ct_prop ] = $ct_new;
		}
		$lb_new = law_hubspot_merge_legal_basis( $lb_old, $state['legal_basis_min'] );
		if ( null !== $lb_new ) {
			$properties[ $lb_prop ] = $lb_new;
		}

		$plans[ $email ] = array(
			'state'      => $state,
			'exists'     => (bool) $found,
			'hubspot_id' => $found ? $found['id'] : '',
			'current'    => $existing,
			'properties' => $properties,
			'before'     => array( 'contact_type' => $ct_old, 'legal_basis' => $lb_old ),
			'after'      => array( 'contact_type' => $ct_new, 'legal_basis' => null !== $lb_new ? $lb_new : $lb_old ),
			'added_tags' => array_values( array_diff( law_hubspot_split_multi( $ct_new ), law_hubspot_split_multi( $ct_old ) ) ),
		);
	}

	return array( 'plans' => $plans, 'error' => null );
}

/* -------------------------------------------------------------------------
 * The push
 * ---------------------------------------------------------------------- */

/**
 * The worker's sync step (law_hubspot_worker_sync() finds this by name).
 *
 * @param string[] $emails
 * @return array<string,array|WP_Error>|WP_Error Per-address results, or one
 *         error for the whole batch when nothing could even be read.
 */
function law_hubspot_sync_batch( array $emails ) {
	$planned = law_hubspot_plan_batch( $emails );
	if ( $planned['error'] ) {
		return $planned['error'];
	}

	$results = array();
	$updates = array();
	$upserts = array();
	$now_ms  = (string) ( time() * 1000 );
	$stamp   = law_hubspot_property_name( 'sync_updated' );

	foreach ( $planned['plans'] as $email => $plan ) {
		if ( null === $plan['state'] ) {
			// Nothing on the site for this address (account deleted since it
			// was queued, say). Not an error: the row has nothing to do.
			law_hubspot_log( $email, 'sync', 'Unknown to the site; nothing to push.', 'skipped' );
			$results[ $email ] = array( 'skipped' => 'unknown' );
			continue;
		}
		$properties = $plan['properties'];
		if ( '' !== $stamp ) {
			$properties[ $stamp ] = $now_ms;
		}
		if ( '' !== $plan['hubspot_id'] ) {
			$updates[] = array( 'id' => $plan['hubspot_id'], 'email' => $email, 'properties' => $properties );
		} else {
			// email in the properties too, so the response echoes it and the
			// result can be matched back to the person.
			$upserts[] = array( 'email' => $email, 'properties' => array( 'email' => $email ) + $properties );
		}
	}

	foreach ( law_hubspot_chunk( $updates ) as $chunk ) {
		$results += law_hubspot_write_chunk( 'update', $chunk, $planned['plans'] );
	}
	foreach ( law_hubspot_chunk( $upserts ) as $chunk ) {
		$results += law_hubspot_write_chunk( 'upsert', $chunk, $planned['plans'] );
	}

	// Anything planned that no write answered for is an error, not a silent
	// success: the queue must keep it.
	foreach ( $planned['plans'] as $email => $plan ) {
		if ( ! isset( $results[ $email ] ) ) {
			$results[ $email ] = new WP_Error( 'law_hubspot_no_result', 'HubSpot returned no result for this contact.' );
		}
	}

	return $results;
}

/**
 * Write one chunk (≤100) and attribute the outcome to each person.
 *
 * @param string  $kind   'update' (by ID) or 'upsert' (by email).
 * @param array[] $inputs Each with email, properties, and id for updates.
 * @param array   $plans  From law_hubspot_plan_batch(), for the log.
 * @return array<string,array|WP_Error> email => result.
 */
function law_hubspot_write_chunk( $kind, array $inputs, array $plans ) {
	$results  = array();
	$response = 'update' === $kind ? law_hubspot_batch_update( $inputs ) : law_hubspot_batch_upsert( $inputs );

	if ( law_hubspot_result_is_dry( $response ) ) {
		foreach ( $inputs as $input ) {
			law_hubspot_log_push( $input['email'], $plans[ $input['email'] ], $input['properties'], 'dry', $input['id'] ?? '' );
			$results[ $input['email'] ] = array( 'dry_run' => true );
		}
		return $results;
	}

	if ( is_wp_error( $response ) ) {
		$status = (int) ( $response->get_error_data()['status'] ?? 0 );
		// A validation error fails the whole batch for one bad value. Retry
		// one at a time so the error lands on the person it belongs to.
		if ( count( $inputs ) > 1 && $status >= 400 && $status < 500 && 429 !== $status ) {
			foreach ( $inputs as $input ) {
				$results += law_hubspot_write_chunk( $kind, array( $input ), $plans );
			}
			return $results;
		}
		foreach ( $inputs as $input ) {
			$results[ $input['email'] ] = $response;
		}
		return $results;
	}

	// Map HubSpot's results back to people: by ID for updates, by the email
	// property for upserts. 207 responses carry errors[] with context ids.
	$by_id = array();
	foreach ( $inputs as $input ) {
		if ( ! empty( $input['id'] ) ) {
			$by_id[ (string) $input['id'] ] = $input['email'];
		}
	}
	$ok = array();
	foreach ( (array) ( $response['results'] ?? array() ) as $row ) {
		$id    = (string) ( $row['id'] ?? '' );
		$email = isset( $by_id[ $id ] ) ? $by_id[ $id ] : law_hubspot_normalise_email( $row['properties']['email'] ?? '' );
		if ( '' !== $email ) {
			$ok[ $email ] = $id;
		}
	}
	// errors[].context names the inputs it concerns, as ids or emails under a
	// key that varies by endpoint, so every array in it is tried.
	$failed = array();
	foreach ( (array) ( $response['errors'] ?? array() ) as $error ) {
		$message = (string) ( $error['message'] ?? 'HubSpot rejected this contact.' );
		foreach ( (array) ( $error['context'] ?? array() ) as $refs ) {
			foreach ( (array) $refs as $ref ) {
				$ref   = (string) $ref;
				$email = $by_id[ $ref ] ?? law_hubspot_normalise_email( $ref );
				if ( '' !== $email ) {
					$failed[ $email ] = $message;
				}
			}
		}
	}

	foreach ( $inputs as $input ) {
		$email = $input['email'];
		if ( isset( $ok[ $email ] ) ) {
			$id = $ok[ $email ];
			// Remember the record ID whenever the site does not already hold
			// this one (first sync, or a merge in HubSpot changed it).
			if ( '' !== $id && $plans[ $email ]['state']['hubspot_id'] !== $id ) {
				law_hubspot_store_id( $plans[ $email ]['state'], $id );
			}
			law_hubspot_log_push( $email, $plans[ $email ], $input['properties'], 'ok', $id );
			$results[ $email ] = array( 'id' => $id, 'new' => ! $plans[ $email ]['exists'] );
		} elseif ( isset( $failed[ $email ] ) ) {
			$results[ $email ] = new WP_Error( 'law_hubspot_item_error', 'HubSpot: ' . $failed[ $email ] );
		} elseif ( 1 === count( $inputs ) && ! empty( $response['id'] ) ) {
			// Not a batch shape (defensive): a single-object response.
			$id = (string) $response['id'];
			law_hubspot_store_id( $plans[ $email ]['state'], $id );
			law_hubspot_log_push( $email, $plans[ $email ], $input['properties'], 'ok', $id );
			$results[ $email ] = array( 'id' => $id, 'new' => ! $plans[ $email ]['exists'] );
		}
	}

	return $results;
}

/** One log line per pushed person: what changed and where it went. */
function law_hubspot_log_push( $email, array $plan, array $properties, $result, $hubspot_id = '' ) {
	law_hubspot_log(
		$email,
		'sync',
		array(
			'hubspot_id'   => (string) $hubspot_id,
			'new'          => ! $plan['exists'],
			'properties'   => $properties,
			'contact_type' => array( 'before' => $plan['before']['contact_type'], 'after' => $plan['after']['contact_type'] ),
			'legal_basis'  => array( 'before' => $plan['before']['legal_basis'], 'after' => $plan['after']['legal_basis'] ),
			'sources'      => $plan['state']['sources'],
		),
		$result
	);
}

/* -------------------------------------------------------------------------
 * The preview (read-only)
 * ---------------------------------------------------------------------- */

/** Column order for the preview CSV. */
function law_hubspot_preview_columns() {
	return array(
		'email',
		'in_wordpress',
		'exists_in_hubspot',
		'hubspot_id',
		'contact_type_before',
		'contact_type_after',
		'tags_added',
		'legal_basis_before',
		'legal_basis_after',
		'firstname',
		'lastname',
		'company',
		'jobtitle',
		'country',
		'sources',
	);
}

/**
 * The backfill preview (HUBSPOT_SYNC.md §8 step 3): one row per person, what
 * HubSpot has and what the push would make it. Reads only.
 *
 * @param string[]|null $emails Null = everyone (law_hubspot_people()).
 * @return array{rows: array[], totals: array<string,int>, error: WP_Error|null}
 */
function law_hubspot_preview( ?array $emails = null ) {
	$emails = null === $emails ? law_hubspot_people() : $emails;
	$rows   = array();
	$totals = array( 'people' => 0, 'in_hubspot' => 0, 'new_contacts' => 0, 'unknown' => 0 );
	$tags   = array();

	foreach ( law_hubspot_chunk( $emails ) as $chunk ) {
		$planned = law_hubspot_plan_batch( $chunk );
		if ( $planned['error'] ) {
			return array( 'rows' => $rows, 'totals' => $totals, 'error' => $planned['error'] );
		}
		foreach ( $planned['plans'] as $email => $plan ) {
			$totals['people']++;
			if ( null === $plan['state'] ) {
				$totals['unknown']++;
				$rows[] = array_merge( array_fill_keys( law_hubspot_preview_columns(), '' ), array( 'email' => $email, 'in_wordpress' => 'no' ) );
				continue;
			}
			$totals[ $plan['exists'] ? 'in_hubspot' : 'new_contacts' ]++;
			foreach ( $plan['added_tags'] as $tag ) {
				$tags[ $tag ] = ( $tags[ $tag ] ?? 0 ) + 1;
			}
			foreach ( $plan['state']['contact_type_add'] as $tag ) {
				$tags[ 'total ' . $tag ] = ( $tags[ 'total ' . $tag ] ?? 0 ) + 1;
			}
			$sources = $plan['state']['sources'];
			$rows[]  = array(
				'email'               => $email,
				'in_wordpress'        => implode( ' ', array_filter( array( $sources['user'] ? 'user' : '', $sources['speaker'] ? 'speaker' : '' ) ) ),
				'exists_in_hubspot'   => $plan['exists'] ? 'yes' : 'no',
				'hubspot_id'          => $plan['hubspot_id'],
				'contact_type_before' => $plan['before']['contact_type'],
				'contact_type_after'  => $plan['after']['contact_type'],
				'tags_added'          => implode( ';', $plan['added_tags'] ),
				'legal_basis_before'  => $plan['before']['legal_basis'],
				'legal_basis_after'   => $plan['after']['legal_basis'],
				'firstname'           => (string) ( $plan['state']['properties']['firstname'] ?? '' ),
				'lastname'            => (string) ( $plan['state']['properties']['lastname'] ?? '' ),
				'company'             => (string) ( $plan['state']['properties']['company'] ?? '' ),
				'jobtitle'            => (string) ( $plan['state']['properties']['jobtitle'] ?? '' ),
				'country'             => (string) ( $plan['state']['properties']['country'] ?? '' ),
				'sources'             => sprintf(
					'bookings:%d hosted:%d co-owned:%d speaking:%d%s',
					count( $sources['bookings'] ),
					count( $sources['hosted'] ),
					count( $sources['co_owned'] ),
					count( $sources['speaking'] ),
					$sources['press'] ? ' press' : ''
				),
			);
		}
	}

	ksort( $tags );
	return array( 'rows' => $rows, 'totals' => $totals + $tags, 'error' => null );
}

/**
 * Write preview rows as CSV to a stream.
 *
 * @param resource $handle fopen()ed for writing.
 * @param array[]  $rows   law_hubspot_preview()['rows'].
 */
function law_hubspot_preview_to_csv( $handle, array $rows ) {
	fputcsv( $handle, law_hubspot_preview_columns(), ',', '"', '\\' );
	foreach ( $rows as $row ) {
		$line = array();
		foreach ( law_hubspot_preview_columns() as $column ) {
			$value = (string) ( $row[ $column ] ?? '' );
			// Keep spreadsheets from interpreting a leading =, +, - or @ as a formula.
			if ( '' !== $value && str_contains( '=+-@', $value[0] ) ) {
				$value = "'" . $value;
			}
			$line[] = $value;
		}
		fputcsv( $handle, $line, ',', '"', '\\' );
	}
}
