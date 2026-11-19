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

/**
 * The properties the plan reads back before merging: the shared pair, and
 * every site-owned property the portal is known to have (a batch read that
 * names a property the portal lacks fails whole, and the badging set is not
 * created until Event Connections confirm the names; properties.php).
 */
function law_hubspot_read_properties() {
	$config = law_hubspot_config();
	$names  = array( 'email', $config['contact_type_property'], $config['legal_basis_property'] );
	if ( null === law_hubspot_known_portal_properties() ) {
		// First run since deploy and no property check yet: one GET, so the
		// read below cannot name a property the portal does not have.
		law_hubspot_fetch_properties();
	}
	foreach ( array_keys( $config['properties'] ) as $key ) {
		if ( 'sync_updated' !== $key && law_hubspot_portal_has_property( $key ) ) {
			$names[] = $config['properties'][ $key ];
		}
	}
	return array_values( array_unique( $names ) );
}

/**
 * The site-owned property writes for one person: desired values from the
 * rules against what the contact holds, only the differences
 * (HUBSPOT_SYNC.md §5.2).
 *
 *  - A property the portal does not have yet is never written.
 *  - `events_attending` is a merge: the contact's options for OTHER years
 *    stay, this year's are replaced by the desired set.
 *  - `passtype` is never blanked: '' from the rules means "send nothing".
 *  - While the contact's `badge_manual` is Yes, `passtype` and `dietary_icon`
 *    are left alone: a human decided this badge (config `owned_by_law`).
 *  - Everything in `owned_by_law` is never written at all.
 *  - `sync_updated` is stamped on every push that writes anything.
 *
 * @param array<string,mixed>  $desired  law_hubspot_site_properties() result.
 * @param array<string,string> $existing The read-back properties, by internal name.
 * @return array<string,string> Internal name => value to write.
 */
function law_hubspot_site_property_writes( array $desired, array $existing ) {
	$config = law_hubspot_config();
	$names  = $config['properties'];
	$year   = (string) $config['year'];
	$writes = array();

	$manual_name = $names['badge_manual'] ?? '';
	$manual      = '' !== $manual_name && 'true' === strtolower( (string) ( $existing[ $manual_name ] ?? '' ) );

	foreach ( $desired as $key => $value ) {
		if ( ! isset( $names[ $key ] ) || in_array( $key, (array) ( $config['owned_by_law'] ?? array() ), true ) ) {
			continue;
		}
		if ( ! law_hubspot_portal_has_property( $key ) ) {
			continue;
		}
		$name    = $names[ $key ];
		$current = (string) ( $existing[ $name ] ?? '' );

		if ( 'events_attending' === $key ) {
			$kept = array_filter( law_hubspot_split_multi( $current ), fn( $option ) => ! str_starts_with( $option, $year . ' ' ) );
			$new  = array_values( array_unique( array_merge( $kept, array_map( 'strval', (array) $value ) ) ) );
			$old  = law_hubspot_split_multi( $current );
			sort( $new );
			sort( $old );
			if ( $new !== $old ) {
				$writes[ $name ] = implode( ';', $new );
			}
			continue;
		}

		$value = (string) $value;
		if ( 'passtype' === $key && '' === $value ) {
			continue;
		}
		if ( $manual && in_array( $key, array( 'passtype', 'dietary_icon' ), true ) ) {
			continue;
		}
		// A count of nought and no value at all say the same thing; writing
		// "0" to every contact who never booked a hosted event would be the
		// backfill's biggest write for no information.
		$same = 'events_registered_count' === $key
			? law_hubspot_zero_or_blank( $value ) === law_hubspot_zero_or_blank( $current )
			: law_hubspot_multi_equal( $value, $current, in_array( $key, array( 'dietary', 'access', 'event_sectors' ), true ) );
		if ( $same ) {
			continue;
		}
		$writes[ $name ] = $value;
	}

	return $writes;
}

/** '' for nought or blank, the number otherwise. */
function law_hubspot_zero_or_blank( $value ) {
	$value = trim( (string) $value );
	return ( '' === $value || 0 === (int) $value ) ? '' : (string) (int) $value;
}

/** Are two values the same, ignoring option order when $multi? */
function law_hubspot_multi_equal( $a, $b, $multi ) {
	if ( ! $multi ) {
		return (string) $a === (string) $b;
	}
	$a = law_hubspot_split_multi( $a );
	$b = law_hubspot_split_multi( $b );
	sort( $a );
	sort( $b );
	return $a === $b;
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

		// The site-owned set (phase 3): only the differences, with the
		// merge and the hands-off rules in law_hubspot_site_property_writes().
		$site_writes = law_hubspot_site_property_writes( (array) ( $state['site_properties'] ?? array() ), $existing );
		$properties  = $properties + $site_writes;

		$plans[ $email ] = array(
			'state'       => $state,
			'exists'      => (bool) $found,
			'hubspot_id'  => $found ? $found['id'] : '',
			'current'     => $existing,
			'properties'  => $properties,
			'before'      => array( 'contact_type' => $ct_old, 'legal_basis' => $lb_old ),
			'after'       => array( 'contact_type' => $ct_new, 'legal_basis' => null !== $lb_new ? $lb_new : $lb_old ),
			'added_tags'  => array_values( array_diff( law_hubspot_split_multi( $ct_new ), law_hubspot_split_multi( $ct_old ) ) ),
			'site_writes' => $site_writes,
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
		// The site-owned set (phase 3): what the rules want, whether or not
		// the portal has the property yet, then what this push would write.
		'events_attending',
		'passtype',
		'dietary_icon',
		'dietary',
		'dietary_other',
		'access',
		'access_other',
		'badge_manual',
		'ec_arrived',
		'hosted_count',
		'hosted_titles',
		'event_sectors',
		'site_writes',
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
			$site    = (array) ( $plan['state']['site_properties'] ?? array() );
			$manual  = law_hubspot_property_name( 'badge_manual' );
			$arrived = law_hubspot_property_name( 'ec_arrived' );
			$rows[]  = array(
				'events_attending'    => implode( ';', (array) ( $site['events_attending'] ?? array() ) ),
				'passtype'            => (string) ( $site['passtype'] ?? '' ),
				'dietary_icon'        => (string) ( $site['dietary_icon'] ?? '' ),
				'dietary'             => (string) ( $site['dietary'] ?? '' ),
				'dietary_other'       => (string) ( $site['dietary_other'] ?? '' ),
				'access'              => (string) ( $site['access'] ?? '' ),
				'access_other'        => (string) ( $site['access_other'] ?? '' ),
				'badge_manual'        => '' !== $manual ? (string) ( $plan['current'][ $manual ] ?? '' ) : '',
				'ec_arrived'          => '' !== $arrived ? (string) ( $plan['current'][ $arrived ] ?? '' ) : '',
				'hosted_count'        => (string) ( $site['events_registered_count'] ?? '' ),
				'hosted_titles'       => str_replace( "\n", ' | ', (string) ( $site['events_registered'] ?? '' ) ),
				'event_sectors'       => (string) ( $site['event_sectors'] ?? '' ),
				'site_writes'         => implode( ' | ', array_map( fn( $k, $v ) => $k . '=' . str_replace( "\n", ' / ', (string) $v ), array_keys( (array) ( $plan['site_writes'] ?? array() ) ), (array) ( $plan['site_writes'] ?? array() ) ) ),
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

/* -------------------------------------------------------------------------
 * One-off clean-up: take one value out of a multi-checkbox property
 * ---------------------------------------------------------------------- */

/**
 * Every contact holding $value in the multi-checkbox $property, read through
 * the search API, with what the property would hold once the value is gone.
 *
 * Built for the "2026 Attendee" correction before the backfill (Emily, 13
 * October 2026): the tag had been handed out in September to everyone who
 * ticked the attendee box, and should mean "has a confirmed booking". The
 * backfill re-adds it for everyone who qualifies, so the clean-up only has to
 * take it away; with $unless_booked, only from people the site holds no
 * confirmed booking for, so nobody who has booked loses it for even a minute.
 *
 * Reads only. HubSpot's search answers 100 at a time and at most 10,000 in
 * total, which is plenty for one tag's holders. The search filter is HubSpot's
 * own idea of "includes this option"; the exact token is checked again here,
 * so a near-miss match can never be edited.
 *
 * @param string $property      Internal property name (e.g. contact_type).
 * @param string $value         The option's internal value (e.g. "2026 Attendee").
 * @param bool   $unless_booked Skip contacts whose account has a confirmed booking.
 * @return array{rows:array[],skipped:array[],error:WP_Error|null} Each row:
 *         hubspot_id, email, before, after, booked (bool), in_wordpress (bool).
 *         `skipped` holds the same shape for contacts kept because of a booking.
 */
function law_hubspot_remove_option_plan( $property, $value, $unless_booked = true ) {
	$property = sanitize_key( $property );
	$value    = trim( (string) $value );
	if ( '' === $property || '' === $value ) {
		return array( 'rows' => array(), 'skipped' => array(), 'error' => new WP_Error( 'law_hubspot_bad_args', 'Give a property name and a value.' ) );
	}

	$rows    = array();
	$skipped = array();
	$after   = null;
	$pages   = 0;
	do {
		$body = array(
			'filterGroups' => array(
				array(
					'filters' => array(
						array( 'propertyName' => $property, 'operator' => 'IN', 'values' => array( $value ) ),
					),
				),
			),
			'properties'   => array( 'email', $property ),
			'sorts'        => array( array( 'propertyName' => 'hs_object_id', 'direction' => 'ASCENDING' ) ),
			'limit'        => 100,
		);
		if ( null !== $after ) {
			$body['after'] = (string) $after;
		}
		$result = law_hubspot_request( 'POST', '/crm/v3/objects/contacts/search', $body );
		if ( is_wp_error( $result ) ) {
			return array( 'rows' => $rows, 'skipped' => $skipped, 'error' => $result );
		}

		foreach ( (array) ( $result['results'] ?? array() ) as $contact ) {
			$props  = (array) ( $contact['properties'] ?? array() );
			$before = law_hubspot_split_multi( $props[ $property ] ?? '' );
			if ( ! in_array( $value, $before, true ) ) {
				continue; // The search was generous; the token is not there.
			}
			$email  = law_hubspot_normalise_email( (string) ( $props['email'] ?? '' ) );
			$user   = '' !== $email ? law_hubspot_user_for_email( $email ) : null;
			$booked = $user ? (bool) law_hubspot_user_bookings( $user->ID ) : false;
			$row    = array(
				'hubspot_id'   => (string) ( $contact['id'] ?? '' ),
				'email'        => $email,
				'before'       => implode( ';', $before ),
				'after'        => implode( ';', array_values( array_diff( $before, array( $value ) ) ) ),
				'booked'       => $booked,
				'in_wordpress' => (bool) $user,
			);
			if ( $unless_booked && $booked ) {
				$skipped[] = $row;
			} else {
				$rows[] = $row;
			}
		}

		$after = $result['paging']['next']['after'] ?? null;
		$pages++;
	} while ( null !== $after && '' !== (string) $after && $pages < 100 );

	return array( 'rows' => $rows, 'skipped' => $skipped, 'error' => null );
}

/**
 * Write a remove-option plan: each contact's property becomes its `after`.
 *
 * Updates by record ID in batches of 100, through the same client as the
 * sync, so dry mode logs the payloads without sending them and live mode
 * writes to the portal. One log line per contact, action `remove_option`,
 * with before and after, result `dry`, `ok` or `error`.
 *
 * @param string  $property Internal property name.
 * @param array[] $rows     law_hubspot_remove_option_plan()['rows'].
 * @return array{written:int,dry:int,failed:int,errors:string[]}
 */
function law_hubspot_remove_option_apply( $property, array $rows ) {
	$property = sanitize_key( $property );
	$totals   = array( 'written' => 0, 'dry' => 0, 'failed' => 0, 'errors' => array() );

	foreach ( law_hubspot_chunk( $rows ) as $chunk ) {
		$inputs = array();
		foreach ( $chunk as $row ) {
			$inputs[] = array( 'id' => $row['hubspot_id'], 'properties' => array( $property => $row['after'] ) );
		}
		$result = law_hubspot_batch_update( $inputs );

		if ( is_wp_error( $result ) ) {
			// A whole-batch failure: try each contact on its own, as the sync
			// does, so one bad record cannot hold the other 99 back.
			if ( count( $chunk ) > 1 ) {
				foreach ( $chunk as $row ) {
					$single = law_hubspot_batch_update( array( array( 'id' => $row['hubspot_id'], 'properties' => array( $property => $row['after'] ) ) ) );
					law_hubspot_remove_option_log( $property, $row, $single, $totals );
				}
			} else {
				law_hubspot_remove_option_log( $property, $chunk[0], $result, $totals );
			}
			continue;
		}

		// A 207 names the records it rejected in errors[].context.
		$failed = array();
		foreach ( (array) ( $result['errors'] ?? array() ) as $error ) {
			$message = (string) ( $error['message'] ?? 'HubSpot rejected this contact.' );
			foreach ( (array) ( $error['context'] ?? array() ) as $refs ) {
				foreach ( (array) $refs as $ref ) {
					$failed[ (string) $ref ] = $message;
				}
			}
		}
		foreach ( $chunk as $row ) {
			$outcome = isset( $failed[ $row['hubspot_id'] ] ) ? new WP_Error( 'law_hubspot_item_error', 'HubSpot: ' . $failed[ $row['hubspot_id'] ] ) : $result;
			law_hubspot_remove_option_log( $property, $row, $outcome, $totals );
		}
	}

	return $totals;
}

/**
 * One-off clean-up: contacts whose HubSpot pass type is still "Evening only"
 * after Emily declined Evensong as a badged event (16–19 November 2026). The
 * sync never blanks pass type, so those stuck values would otherwise survive.
 *
 * Searches the portal for `Evening only`, then keeps the value only when the
 * site's rules still want that badge (a real Monday/Wednesday/Friday
 * reception-only guest). Everyone else is planned for a clear.
 *
 * @return array{rows:array[],kept:array[],error:WP_Error|null} Each row:
 *         hubspot_id, email, before, after (''), in_wordpress, reason.
 */
function law_hubspot_clear_stale_evening_only_plan() {
	$property = law_hubspot_property_name( 'passtype' );
	$labels   = (array) law_hubspot_setting( 'passtype_values', array() );
	$label    = (string) ( $labels['evening'] ?? 'Evening only' );
	if ( '' === $property ) {
		return array( 'rows' => array(), 'kept' => array(), 'error' => new WP_Error( 'law_hubspot_no_passtype', 'Pass type property is not configured.' ) );
	}

	$rows  = array();
	$kept  = array();
	$after = null;
	$pages = 0;
	do {
		$body = array(
			'filterGroups' => array(
				array(
					'filters' => array(
						// IN (not EQ) matches the remove-option search shape the
						// test portal mock already understands for selects.
						array( 'propertyName' => $property, 'operator' => 'IN', 'values' => array( $label ) ),
					),
				),
			),
			'properties'   => array( 'email', $property ),
			'sorts'        => array( array( 'propertyName' => 'hs_object_id', 'direction' => 'ASCENDING' ) ),
			'limit'        => 100,
		);
		if ( null !== $after ) {
			$body['after'] = (string) $after;
		}
		$result = law_hubspot_request( 'POST', '/crm/v3/objects/contacts/search', $body );
		if ( is_wp_error( $result ) ) {
			return array( 'rows' => $rows, 'kept' => $kept, 'error' => $result );
		}

		foreach ( (array) ( $result['results'] ?? array() ) as $contact ) {
			$props  = (array) ( $contact['properties'] ?? array() );
			$before = (string) ( $props[ $property ] ?? '' );
			if ( $label !== $before ) {
				continue;
			}
			$email = law_hubspot_normalise_email( (string) ( $props['email'] ?? '' ) );
			$state = '' !== $email ? law_hubspot_desired_state( $email ) : null;
			$want  = is_array( $state ) ? (string) ( $state['site_properties']['passtype'] ?? '' ) : '';
			$row   = array(
				'hubspot_id'   => (string) ( $contact['id'] ?? '' ),
				'email'        => $email,
				'before'       => $before,
				'after'        => '',
				'booked'       => false,
				'in_wordpress' => is_array( $state ),
				'reason'       => '' === $want ? 'no badged LAW-run place' : 'site wants ' . $want,
			);
			if ( $label === $want ) {
				$row['reason'] = 'still Evening only on the site';
				$kept[]        = $row;
				continue;
			}
			$rows[] = $row;
		}

		$after = $result['paging']['next']['after'] ?? null;
		$pages++;
	} while ( null !== $after && '' !== (string) $after && $pages < 100 );

	return array( 'rows' => $rows, 'kept' => $kept, 'error' => null );
}

/** One log line for one contact in a remove-option write, and the running totals. */
function law_hubspot_remove_option_log( $property, array $row, $result, array &$totals ) {
	$detail = array(
		'hubspot_id' => $row['hubspot_id'],
		'property'   => $property,
		'before'     => $row['before'],
		'after'      => $row['after'],
		'booked'     => $row['booked'],
	);
	if ( is_wp_error( $result ) ) {
		$detail['error']   = $result->get_error_message();
		$totals['failed']++;
		$totals['errors'][] = $row['email'] . ': ' . $result->get_error_message();
		law_hubspot_log( $row['email'], 'remove_option', $detail, 'error' );
		return;
	}
	if ( law_hubspot_result_is_dry( $result ) ) {
		$totals['dry']++;
		law_hubspot_log( $row['email'], 'remove_option', $detail, 'dry' );
		return;
	}
	$totals['written']++;
	law_hubspot_log( $row['email'], 'remove_option', $detail, 'ok' );
}
