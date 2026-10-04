<?php
/**
 * The HubSpot contact properties the module relies on (_docs/HUBSPOT_SYNC.md
 * §5.2, §8 step 2): what they should look like, what the portal actually has,
 * and the difference.
 *
 * Two kinds:
 *
 *  - SITE-OWNED (law_* internal names). The module creates them in their own
 *    "LAW site" group and adds options as the year or the sector list grows.
 *    It never removes an option: a 2026 value stays an option in 2027.
 *
 *  - SHARED (Contact type, legal basis). LAW staff built these and use them.
 *    The module only ever REPORTS on them -- does each tag exist as an option
 *    VALUE, not merely as a label -- except that it can add a missing Contact
 *    type option on request, because adding an option touches no contact.
 *    Writing a value that is not an option fails the whole batch item
 *    (HUBSPOT_SYNC.md §14.2), which is why the check runs before any push.
 *
 * Property checks compare internal VALUES. The label/value mismatch
 * ("Sponsor" labelled, "2026 Sponsor" stored, or the other way round) has
 * bitten before, and a report that compared labels would call it fine.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The site-owned properties as HubSpot expects them (minus groupName, added
 * on create). Keyed by internal name.
 *
 * @return array<string,array>
 */
function law_hubspot_property_definitions() {
	$config = law_hubspot_config();
	$names  = $config['properties'];

	$definitions = array(
		$names['events_attending']        => array(
			'label'       => 'LAW-run events',
			'description' => 'Confirmed bookings on the conference and receptions, by year. Set by the LAW website; overwritten on every sync.',
			'type'        => 'enumeration',
			'fieldType'   => 'checkbox',
			'options'     => law_hubspot_options_from_values( array_values( $config['attending_options'] ) ),
		),
		$names['delegate_type']           => array(
			'label'       => 'Delegate type (current year)',
			'description' => 'The committee\'s ticket type on the flagship booking. Set by the LAW website.',
			'type'        => 'enumeration',
			'fieldType'   => 'select',
			'options'     => law_hubspot_options_from_values( $config['delegate_types'] ),
		),
		$names['dietary']                 => array(
			'label'       => 'Dietary requirements',
			'description' => 'From the LAW website profile, for people with a booking on an LAW-run event. Overwritten on every sync.',
			'type'        => 'string',
			'fieldType'   => 'text',
		),
		$names['accessibility']           => array(
			'label'       => 'Access requirements',
			'description' => 'From the LAW website profile, for people with a booking on an LAW-run event. Overwritten on every sync.',
			'type'        => 'string',
			'fieldType'   => 'text',
		),
		$names['events_registered_count'] => array(
			'label'       => 'Hosted events booked (count, current year)',
			'description' => 'Number of confirmed bookings on hosted events this year. Set by the LAW website.',
			'type'        => 'number',
			'fieldType'   => 'number',
		),
		$names['events_registered']       => array(
			'label'       => 'Hosted events booked (titles, current year)',
			'description' => 'Titles of the hosted events booked this year, one per line. Set by the LAW website.',
			'type'        => 'string',
			'fieldType'   => 'textarea',
		),
		$names['event_sectors']           => array(
			'label'       => 'Event sectors of interest',
			'description' => 'Sectors of the events this person has booked, from the LAW website\'s sector list.',
			'type'        => 'enumeration',
			'fieldType'   => 'checkbox',
			'options'     => law_hubspot_options_from_values( law_hubspot_sector_values() ),
		),
		$names['sync_updated']            => array(
			'label'       => 'LAW site last synced',
			'description' => 'When the LAW website last pushed this contact.',
			'type'        => 'datetime',
			'fieldType'   => 'date',
		),
	);

	return apply_filters( 'law_hubspot_property_definitions', $definitions );
}

/** The event sector names, as option values. */
function law_hubspot_sector_values() {
	$terms = get_terms( array( 'taxonomy' => 'law_sector', 'hide_empty' => false ) );
	if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
		return array();
	}
	// Term names are stored entity-encoded ("Banking &amp; Financial Services");
	// the option value should be the words people read.
	$values = array_map( fn( $term ) => html_entity_decode( (string) $term->name, ENT_QUOTES | ENT_HTML5, 'UTF-8' ), $terms );
	sort( $values, SORT_NATURAL | SORT_FLAG_CASE );
	return array_values( array_unique( array_filter( $values ) ) );
}

/**
 * Plain strings to HubSpot option rows. Label and value are the same string:
 * the module never wants the two to differ.
 *
 * @param string[] $values
 */
function law_hubspot_options_from_values( array $values ) {
	$options = array();
	foreach ( array_values( $values ) as $i => $value ) {
		$value = (string) $value;
		if ( '' === $value ) {
			continue;
		}
		$options[] = array(
			'label'        => $value,
			'value'        => $value,
			'displayOrder' => $i,
			'hidden'       => false,
		);
	}
	return $options;
}

/* -------------------------------------------------------------------------
 * Reading the portal
 * ---------------------------------------------------------------------- */

/**
 * Every contact property in the portal, keyed by internal name.
 *
 * @return array<string,array>|WP_Error
 */
function law_hubspot_fetch_properties() {
	$result = law_hubspot_request( 'GET', '/crm/v3/properties/contacts', array( 'archived' => 'false' ) );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$properties = array();
	foreach ( (array) ( $result['results'] ?? array() ) as $property ) {
		if ( is_array( $property ) && ! empty( $property['name'] ) ) {
			$properties[ (string) $property['name'] ] = $property;
		}
	}
	return $properties;
}

/** Does the module's property group exist? true / false / WP_Error. */
function law_hubspot_fetch_group_exists() {
	$group  = law_hubspot_setting( 'property_group' );
	$result = law_hubspot_request( 'GET', '/crm/v3/properties/contacts/groups/' . rawurlencode( $group['name'] ) );
	if ( is_wp_error( $result ) ) {
		return 404 === (int) ( $result->get_error_data()['status'] ?? 0 ) ? false : $result;
	}
	return true;
}

/** value => label for a property's options; '' => '' for non-enumerations. */
function law_hubspot_property_option_map( array $property ) {
	$map = array();
	foreach ( (array) ( $property['options'] ?? array() ) as $option ) {
		if ( is_array( $option ) && isset( $option['value'] ) ) {
			$map[ (string) $option['value'] ] = (string) ( $option['label'] ?? '' );
		}
	}
	return $map;
}

/* -------------------------------------------------------------------------
 * The report
 * ---------------------------------------------------------------------- */

/**
 * Compare what the module needs with what the portal has.
 *
 * @return array{
 *   ok: bool,
 *   checked_at: int,
 *   error: string,
 *   group: array{name:string,exists:bool},
 *   properties: array<string,array>,
 *   contact_type: array,
 *   legal_basis: array
 * }
 */
function law_hubspot_properties_check() {
	$config = law_hubspot_config();
	$report = array(
		'ok'           => false,
		'checked_at'   => time(),
		'mode'         => law_hubspot_mode(),
		'error'        => '',
		'group'        => array( 'name' => $config['property_group']['name'], 'exists' => false ),
		'properties'   => array(),
		'contact_type' => array(),
		'legal_basis'  => array(),
	);

	$portal = law_hubspot_fetch_properties();
	if ( is_wp_error( $portal ) ) {
		$report['error'] = $portal->get_error_message();
		return $report;
	}

	$group_exists = law_hubspot_fetch_group_exists();
	if ( is_wp_error( $group_exists ) ) {
		$report['error'] = $group_exists->get_error_message();
		return $report;
	}
	$report['group']['exists'] = (bool) $group_exists;

	$all_ok = $report['group']['exists'];

	// Site-owned properties: exist, right type, every option present.
	foreach ( law_hubspot_property_definitions() as $name => $definition ) {
		$existing = $portal[ $name ] ?? null;
		$row      = array(
			'label'           => $definition['label'],
			'type'            => $definition['type'],
			'field_type'      => $definition['fieldType'],
			'exists'          => null !== $existing,
			'type_ok'         => true,
			'missing_options' => array(),
			'ok'              => false,
		);
		if ( $existing ) {
			$row['type_ok'] = (string) ( $existing['type'] ?? '' ) === $definition['type'];
			if ( ! empty( $definition['options'] ) ) {
				$have = law_hubspot_property_option_map( $existing );
				foreach ( $definition['options'] as $option ) {
					if ( ! array_key_exists( $option['value'], $have ) ) {
						$row['missing_options'][] = $option['value'];
					}
				}
			}
		}
		$row['ok'] = $row['exists'] && $row['type_ok'] && ! $row['missing_options'];
		$all_ok    = $all_ok && $row['ok'];

		$report['properties'][ $name ] = $row;
	}

	// Contact type: shared, so report only. Compare VALUES; also say when a
	// tag exists as a label on a different value, which is the historic trap.
	$report['contact_type'] = law_hubspot_check_shared_property(
		$portal,
		$config['contact_type_property'],
		array_values( array_merge( $config['tags'], $config['reception_tags'] ) )
	);
	$all_ok = $all_ok && $report['contact_type']['ok'];

	$report['legal_basis'] = law_hubspot_check_shared_property(
		$portal,
		$config['legal_basis_property'],
		array( $config['legal_basis_other'], $config['legal_basis_customer'] )
	);
	$all_ok = $all_ok && $report['legal_basis']['ok'];

	$report['ok'] = $all_ok;
	return $report;
}

/**
 * One shared enumeration against the values the module will write.
 *
 * @param array<string,array> $portal  law_hubspot_fetch_properties() result.
 * @param string              $name    Internal name.
 * @param string[]            $needed  Values the rules write.
 */
function law_hubspot_check_shared_property( array $portal, $name, array $needed ) {
	$existing = $portal[ $name ] ?? null;
	$row      = array(
		'name'             => $name,
		'label'            => (string) ( $existing['label'] ?? '' ),
		'exists'           => null !== $existing,
		'type'             => (string) ( $existing['type'] ?? '' ),
		'options'          => array(),
		'needed'           => array_values( $needed ),
		'missing_values'   => array(),
		'label_mismatches' => array(),
		// needed value => existing value that differs only in case or in its
		// dash/space characters. Values are matched exactly, so these are the
		// near-misses most likely to be a typo in config rather than a missing
		// option ("Event Contact" vs "Event contact", en dash vs hyphen).
		'near_misses'      => array(),
		'ok'               => false,
	);
	if ( ! $existing ) {
		$row['missing_values'] = array_values( $needed );
		return $row;
	}

	$row['options'] = law_hubspot_property_option_map( $existing );
	$by_label       = array();
	$by_loose       = array();
	foreach ( $row['options'] as $value => $label ) {
		$by_label[ $label ][]                          = $value;
		$by_loose[ law_hubspot_loose_key( $value ) ][] = $value;
	}

	foreach ( $needed as $value ) {
		if ( array_key_exists( $value, $row['options'] ) ) {
			continue;
		}
		$row['missing_values'][] = $value;
		if ( isset( $by_label[ $value ] ) ) {
			$row['label_mismatches'][ $value ] = $by_label[ $value ];
		}
		if ( isset( $by_loose[ law_hubspot_loose_key( $value ) ] ) ) {
			$row['near_misses'][ $value ] = $by_loose[ law_hubspot_loose_key( $value ) ];
		}
	}

	$row['ok'] = 'enumeration' === $row['type'] && ! $row['missing_values'];
	return $row;
}

/**
 * A comparison key that ignores case and treats every dash and run of
 * whitespace alike, for spotting near-misses. Never used for matching proper:
 * HubSpot matches values exactly and so does the module.
 */
function law_hubspot_loose_key( $value ) {
	$key = mb_strtolower( (string) $value );
	$key = preg_replace( '/[\x{2010}-\x{2015}\-]+/u', '-', $key );
	$key = preg_replace( '/\s+/u', ' ', $key );
	return trim( $key );
}

/* -------------------------------------------------------------------------
 * Fixing what the module owns
 * ---------------------------------------------------------------------- */

/**
 * Create the group and the missing site-owned properties, and add missing
 * options to the ones that exist. Existing options are kept, whatever they
 * are: the PATCH sends the union, never the module's list alone.
 *
 * In dry mode each step is logged and reported as 'dry'; nothing changes.
 *
 * @param array|null $report A fresh law_hubspot_properties_check(); fetched when null.
 * @return array[] One row per action: action, target, result (done|dry|skipped|error), message.
 */
function law_hubspot_properties_create( ?array $report = null ) {
	$report = $report ?? law_hubspot_properties_check();
	if ( '' !== $report['error'] ) {
		return array( array( 'action' => 'check', 'target' => '', 'result' => 'error', 'message' => $report['error'] ) );
	}

	$config  = law_hubspot_config();
	$group   = $config['property_group'];
	$actions = array();

	if ( ! $report['group']['exists'] ) {
		$result    = law_hubspot_request(
			'POST',
			'/crm/v3/properties/contacts/groups',
			array( 'name' => $group['name'], 'label' => $group['label'], 'displayOrder' => -1 )
		);
		$actions[] = law_hubspot_action_row( 'create_group', $group['name'], $result );
		if ( is_wp_error( $result ) ) {
			return $actions;
		}
	}

	$portal = null;
	foreach ( law_hubspot_property_definitions() as $name => $definition ) {
		$row = $report['properties'][ $name ] ?? null;
		if ( ! $row ) {
			continue;
		}

		if ( ! $row['exists'] ) {
			$payload   = array_merge( $definition, array( 'name' => $name, 'groupName' => $group['name'] ) );
			$result    = law_hubspot_request( 'POST', '/crm/v3/properties/contacts', $payload );
			$actions[] = law_hubspot_action_row( 'create_property', $name, $result );
			continue;
		}

		if ( ! $row['type_ok'] ) {
			$actions[] = array(
				'action'  => 'create_property',
				'target'  => $name,
				'result'  => 'error',
				'message' => sprintf( 'Exists with type "%s", expected "%s". HubSpot cannot change a property\'s type; rename the old one in the portal first.', $row['type'] ?? '', $definition['type'] ),
			);
			continue;
		}

		if ( $row['missing_options'] ) {
			if ( null === $portal ) {
				$portal = law_hubspot_fetch_properties();
				if ( is_wp_error( $portal ) ) {
					$actions[] = law_hubspot_action_row( 'add_options', $name, $portal );
					return $actions;
				}
			}
			$merged    = law_hubspot_merge_options( (array) ( $portal[ $name ]['options'] ?? array() ), $row['missing_options'] );
			$result    = law_hubspot_request( 'PATCH', '/crm/v3/properties/contacts/' . rawurlencode( $name ), array( 'options' => $merged ) );
			$actions[] = law_hubspot_action_row( 'add_options', $name . ': ' . implode( ', ', $row['missing_options'] ), $result );
		}
	}

	if ( ! $actions ) {
		$actions[] = array( 'action' => 'none', 'target' => '', 'result' => 'skipped', 'message' => 'Every site-owned property is already in place.' );
	}

	law_hubspot_log( '', 'properties', $actions, 'dry' === law_hubspot_mode() ? 'dry' : 'ok' );
	return $actions;
}

/**
 * Add the Contact type values the rules need and the portal lacks. Separate
 * from law_hubspot_properties_create() because the property is LAW's: this
 * is offered as its own, explicit action and does nothing to existing options.
 *
 * Refuses when a needed value already exists as a LABEL on another value:
 * adding a second option with that label would make the portal's dropdowns
 * ambiguous, and the fix for that is HubSpot's enumeration merge tool.
 *
 * @param array|null $report A fresh check, or null to fetch one.
 * @return array[] Action rows as law_hubspot_properties_create().
 */
function law_hubspot_contact_type_add_options( ?array $report = null ) {
	$report = $report ?? law_hubspot_properties_check();
	if ( '' !== $report['error'] ) {
		return array( array( 'action' => 'check', 'target' => '', 'result' => 'error', 'message' => $report['error'] ) );
	}

	$shared = $report['contact_type'];
	if ( ! $shared['exists'] ) {
		return array( array( 'action' => 'add_options', 'target' => $shared['name'], 'result' => 'error', 'message' => 'The Contact type property was not found under internal name "' . $shared['name'] . '". Confirm the name in the portal and set contact_type_property in law_hubspot_config().' ) );
	}
	if ( $shared['label_mismatches'] || $shared['near_misses'] ) {
		$list = array();
		foreach ( $shared['label_mismatches'] as $label => $values ) {
			$list[] = sprintf( '"%s" is the label of value %s', $label, implode( ', ', array_map( fn( $v ) => '"' . $v . '"', $values ) ) );
		}
		foreach ( $shared['near_misses'] as $wanted => $values ) {
			$list[] = sprintf( '"%s" differs only in case or dashes from existing value %s', $wanted, implode( ', ', array_map( fn( $v ) => '"' . $v . '"', $values ) ) );
		}
		return array( array( 'action' => 'add_options', 'target' => $shared['name'], 'result' => 'error', 'message' => 'Not added: ' . implode( '; ', $list ) . '. Change the value in law_hubspot_config() to match the portal, or fix the portal with HubSpot\'s merge-options tool, rather than adding a near-duplicate option.' ) );
	}
	if ( ! $shared['missing_values'] ) {
		return array( array( 'action' => 'none', 'target' => $shared['name'], 'result' => 'skipped', 'message' => 'Every tag already exists as an option.' ) );
	}

	$portal = law_hubspot_fetch_properties();
	if ( is_wp_error( $portal ) ) {
		return array( law_hubspot_action_row( 'add_options', $shared['name'], $portal ) );
	}
	$merged = law_hubspot_merge_options( (array) ( $portal[ $shared['name'] ]['options'] ?? array() ), $shared['missing_values'] );
	$result = law_hubspot_request( 'PATCH', '/crm/v3/properties/contacts/' . rawurlencode( $shared['name'] ), array( 'options' => $merged ) );
	$action = law_hubspot_action_row( 'add_options', $shared['name'] . ': ' . implode( ', ', $shared['missing_values'] ), $result );
	law_hubspot_log( '', 'properties', array( $action ), 'dry' === law_hubspot_mode() ? 'dry' : 'ok' );
	return array( $action );
}

/**
 * Existing options, as the portal holds them, plus new values appended. The
 * existing rows are passed through untouched (label, hidden, description),
 * so a PATCH built from this can only add.
 *
 * @param array[]  $existing Option rows from the portal.
 * @param string[] $add      Values to append.
 */
function law_hubspot_merge_options( array $existing, array $add ) {
	$merged = array();
	$seen   = array();
	foreach ( $existing as $option ) {
		if ( ! is_array( $option ) || ! isset( $option['value'] ) ) {
			continue;
		}
		$row = array(
			'label'  => (string) ( $option['label'] ?? $option['value'] ),
			'value'  => (string) $option['value'],
			'hidden' => ! empty( $option['hidden'] ),
		);
		if ( isset( $option['displayOrder'] ) ) {
			$row['displayOrder'] = (int) $option['displayOrder'];
		}
		if ( ! empty( $option['description'] ) ) {
			$row['description'] = (string) $option['description'];
		}
		$merged[]                     = $row;
		$seen[ (string) $option['value'] ] = true;
	}
	$order = count( $merged );
	foreach ( $add as $value ) {
		$value = (string) $value;
		if ( '' === $value || isset( $seen[ $value ] ) ) {
			continue;
		}
		$merged[]       = array( 'label' => $value, 'value' => $value, 'displayOrder' => $order++, 'hidden' => false );
		$seen[ $value ] = true;
	}
	return $merged;
}

/** One action row from a client result. */
function law_hubspot_action_row( $action, $target, $result ) {
	if ( is_wp_error( $result ) ) {
		return array( 'action' => $action, 'target' => $target, 'result' => 'error', 'message' => $result->get_error_message() );
	}
	if ( law_hubspot_result_is_dry( $result ) ) {
		return array( 'action' => $action, 'target' => $target, 'result' => 'dry', 'message' => 'Dry run: not sent.' );
	}
	return array( 'action' => $action, 'target' => $target, 'result' => 'done', 'message' => '' );
}
