<?php
/**
 * functions/hubspot/properties.php: the report compares values not labels,
 * creation only adds, and the shared Contact type property is never
 * rewritten. The portal is a scripted law_hubspot_request_mock.
 */

class HubSpotPropertiesTest extends LAW_Test_Case {

	/** @var array<string,array> Contact properties the fake portal holds, by name. */
	private array $portal = array();
	/** @var string[] Property groups the fake portal does NOT have, by internal name. */
	private array $missing_groups = array();
	/** @var array[] Writes the module attempted: method, path, body. */
	private array $writes = array();
	private string $mode = 'live';

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		law_hubspot_install_tables();
	}

	protected function setUp(): void {
		parent::setUp();
		$this->writes       = array();
		$this->mode         = 'live';
		$this->missing_groups = array();
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'year' => 2026 ) );
		law_hubspot_attending_options( true );

		// A portal with everything in place, which each test then breaks.
		$this->portal = array();
		foreach ( law_hubspot_property_definitions() as $name => $definition ) {
			$this->portal[ $name ] = array_merge( $definition, array( 'name' => $name ) );
		}
		$this->portal['contact_type']   = $this->enumeration(
			'contact_type',
			'Contact type',
			array_merge( array( 'Mailchimp', 'Ex-committee' ), array_values( law_hubspot_setting( 'tags' ) ), array_values( law_hubspot_setting( 'reception_tags' ) ) )
		);
		// As the real portal holds them (4 October 2026): en dash on "existing
		// customer", plain hyphen on "other".
		$this->portal['hs_legal_basis'] = $this->enumeration(
			'hs_legal_basis',
			'Legal basis for processing contact\'s data',
			array( 'Freely given consent from contact', 'Legitimate interest – existing customer', 'Legitimate interest - other', 'Not applicable' )
		);

		add_filter( 'law_hubspot_mode', array( $this, 'mode' ) );
		add_filter( 'law_hubspot_request_mock', array( $this, 'portal' ), 10, 4 );
	}

	protected function tearDown(): void {
		remove_filter( 'law_hubspot_mode', array( $this, 'mode' ) );
		remove_filter( 'law_hubspot_request_mock', array( $this, 'portal' ), 10 );
		parent::tearDown();
	}

	public function mode() {
		return $this->mode;
	}

	/** Every Contact type value the rules write: the role tags and the reception tags. */
	private function all_tags(): array {
		return array_merge( law_hubspot_setting( 'tags' ), law_hubspot_setting( 'reception_tags' ) );
	}

	private function enumeration( $name, $label, array $values, $type = 'enumeration' ) {
		return array(
			'name'      => $name,
			'label'     => $label,
			'type'      => $type,
			'fieldType' => 'checkbox',
			'options'   => law_hubspot_options_from_values( $values ),
		);
	}

	/** The fake portal: answers the reads the module makes, records its writes. */
	public function portal( $mocked, $method, $path, $body ) {
		if ( 'GET' === $method && '/crm/v3/properties/contacts' === $path ) {
			return array( 'results' => array_values( $this->portal ) );
		}
		if ( 'GET' === $method && str_starts_with( $path, '/crm/v3/properties/contacts/groups/' ) ) {
			$group = rawurldecode( substr( $path, strlen( '/crm/v3/properties/contacts/groups/' ) ) );
			return in_array( $group, $this->missing_groups, true )
				? new WP_Error( 'law_hubspot_api_error', 'HubSpot: group not found', array( 'status' => 404 ) )
				: array( 'name' => $group );
		}
		if ( 'dry' === $this->mode && law_hubspot_request_is_write( $method, $path ) ) {
			// Let the client's own dry gate handle it, so the test proves the
			// gate and not the mock.
			return null;
		}
		$this->writes[] = compact( 'method', 'path', 'body' );
		return array( 'name' => 'created' );
	}

	/* ---- the report ----------------------------------------------------- */

	public function test_a_complete_portal_passes(): void {
		$report = law_hubspot_properties_check();

		$this->assertSame( '', $report['error'] );
		$this->assertTrue( $report['ok'] );
		$this->assertSame( array( 'site', 'year' ), array_keys( $report['groups'] ) );
		$this->assertTrue( $report['groups']['site']['exists'] );
		$this->assertTrue( $report['groups']['year']['exists'] );
		foreach ( $report['properties'] as $name => $row ) {
			$this->assertTrue( $row['ok'], $name );
		}
		$this->assertTrue( $report['contact_type']['ok'] );
		$this->assertSame( array(), $report['contact_type']['missing_values'] );
		$this->assertTrue( $report['legal_basis']['ok'] );
		$this->assertSame( array(), $this->writes, 'A check is read-only.' );
	}

	public function test_missing_property_wrong_type_and_missing_option_are_each_reported(): void {
		unset( $this->portal['law26_dietary'] );
		$this->portal['law_events_registered_count']['type'] = 'string';
		$this->portal['law_events_attending']['options']     = law_hubspot_options_from_values( array( '2026 Flagship', '2025 Flagship' ) );
		$this->missing_groups                                = array( 'law_site' );

		$report = law_hubspot_properties_check();

		$this->assertFalse( $report['ok'] );
		$this->assertFalse( $report['groups']['site']['exists'] );
		$this->assertTrue( $report['groups']['year']['exists'] );
		$this->assertFalse( $report['properties']['law26_dietary']['exists'] );
		$this->assertFalse( $report['properties']['law_events_registered_count']['type_ok'] );
		// Every option but the flagship is missing: the three seeded receptions
		// and whatever other receptions the year has (derived from the events).
		$expected_missing = array_values( array_diff( law_hubspot_attending_options(), array( '2026 Flagship' ) ) );
		$this->assertGreaterThanOrEqual( 3, count( $expected_missing ) );
		$this->assertSame( $expected_missing, $report['properties']['law_events_attending']['missing_options'] );
		$this->assertTrue( $report['properties']['law_sync_updated']['ok'] );
	}

	public function test_contact_type_is_compared_by_value_and_a_label_only_match_is_called_out(): void {
		// The historic trap: an option whose LABEL is "2026 Sponsor" but whose
		// stored value is "Sponsor". Writing "2026 Sponsor" would fail.
		$options = array();
		foreach ( $this->all_tags() as $key => $tag ) {
			if ( 'sponsor' === $key ) {
				$options[] = array( 'label' => $tag, 'value' => 'Sponsor', 'displayOrder' => 0, 'hidden' => false );
			} elseif ( 'press' !== $key ) {
				$options[] = array( 'label' => $tag, 'value' => $tag, 'displayOrder' => 0, 'hidden' => false );
			}
		}
		$this->portal['contact_type']['options'] = $options;

		$report = law_hubspot_properties_check();
		$shared = $report['contact_type'];

		$this->assertFalse( $report['ok'] );
		$this->assertFalse( $shared['ok'] );
		$this->assertSame( array( '2026 Sponsor', '2026 Press' ), $shared['missing_values'] );
		$this->assertSame( array( '2026 Sponsor' => array( 'Sponsor' ) ), $shared['label_mismatches'] );
		$this->assertSame( 'Contact type', $shared['label'] );
	}

	public function test_a_missing_contact_type_property_is_reported_not_created(): void {
		unset( $this->portal['contact_type'] );

		$report = law_hubspot_properties_check();
		$this->assertFalse( $report['contact_type']['exists'] );
		$this->assertCount( 10, $report['contact_type']['missing_values'], 'Seven role tags plus three reception tags.' );

		$actions = law_hubspot_properties_create( $report );
		$this->assertSame( array(), $this->writes, 'Create never touches the shared property.' );
		$this->assertSame( 'skipped', $actions[0]['result'] );

		$actions = law_hubspot_contact_type_add_options( $report );
		$this->assertSame( 'error', $actions[0]['result'] );
		$this->assertStringContainsString( 'contact_type', $actions[0]['message'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_legal_basis_values_are_checked_against_the_portal_and_dash_differences_are_near_misses(): void {
		// A portal that spelt both with an en dash: "other" no longer matches.
		$this->portal['hs_legal_basis']['options'] = law_hubspot_options_from_values( array( 'Legitimate interest – other', 'Legitimate interest – existing customer' ) );

		$report = law_hubspot_properties_check();
		$this->assertFalse( $report['legal_basis']['ok'] );
		$this->assertSame( array( 'Legitimate interest - other' ), $report['legal_basis']['missing_values'] );
		$this->assertSame(
			array( 'Legitimate interest - other' => array( 'Legitimate interest – other' ) ),
			$report['legal_basis']['near_misses'],
			'A hyphen/en-dash difference is flagged as a probable config typo.'
		);
	}

	public function test_a_case_only_difference_is_a_near_miss_and_is_not_added_as_a_duplicate(): void {
		// The real portal has "2026 Event contact"; had config said "Contact"
		// the check must say so rather than let a near-duplicate be created.
		$options = array();
		foreach ( $this->all_tags() as $key => $tag ) {
			$options[] = array( 'label' => $tag, 'value' => 'event_contact' === $key ? '2026 Event Contact' : $tag, 'displayOrder' => 0, 'hidden' => false );
		}
		$this->portal['contact_type']['options'] = $options;

		$report = law_hubspot_properties_check();
		$this->assertSame( array( '2026 Event contact' ), $report['contact_type']['missing_values'] );
		$this->assertSame( array( '2026 Event contact' => array( '2026 Event Contact' ) ), $report['contact_type']['near_misses'] );

		$actions = law_hubspot_contact_type_add_options( $report );
		$this->assertSame( 'error', $actions[0]['result'] );
		$this->assertStringContainsString( 'case or dashes', $actions[0]['message'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_loose_key(): void {
		$this->assertSame( law_hubspot_loose_key( 'Legitimate interest – other' ), law_hubspot_loose_key( 'Legitimate interest - other' ) );
		$this->assertSame( law_hubspot_loose_key( '2026 Event Contact' ), law_hubspot_loose_key( '2026  event contact' ) );
		$this->assertNotSame( law_hubspot_loose_key( '2026 Event contact' ), law_hubspot_loose_key( '2026 Event Host' ) );
	}

	public function test_config_matches_the_portal_as_read_on_4_october_2026(): void {
		$config = law_hubspot_config();
		$this->assertSame( 'contact_type', $config['contact_type_property'] );
		$this->assertSame( 'Legitimate interest - other', $config['legal_basis_other'], 'Plain hyphen, as the portal stores it.' );
		$this->assertSame( 'Legitimate interest – existing customer', $config['legal_basis_customer'], 'En dash, as the portal stores it.' );
		$this->assertSame( '2026 Event contact', $config['tags']['event_contact'], 'Lower-case c, as the portal stores it.' );
	}

	public function test_a_failed_read_is_an_error_not_a_pass(): void {
		remove_filter( 'law_hubspot_request_mock', array( $this, 'portal' ), 10 );
		$fail = fn() => new WP_Error( 'law_hubspot_api_error', 'HubSpot: Authentication credentials not found.' );
		add_filter( 'law_hubspot_request_mock', $fail );

		$report = law_hubspot_properties_check();
		remove_filter( 'law_hubspot_request_mock', $fail );

		$this->assertFalse( $report['ok'] );
		$this->assertStringContainsString( 'Authentication', $report['error'] );
		$this->assertSame( array(), $report['properties'] );
	}

	/* ---- creating ------------------------------------------------------- */

	public function test_the_year_group_is_created_and_holds_the_year_s_properties(): void {
		// The portal as it stands before the first phase 3 create: the site
		// group and the un-prefixed properties exist, nothing law26 does.
		$this->missing_groups = array( 'law26' );
		foreach ( array_keys( $this->portal ) as $name ) {
			if ( str_starts_with( $name, 'law26_' ) ) {
				unset( $this->portal[ $name ] );
			}
		}

		$actions = law_hubspot_properties_create();

		$groups = array_filter( $this->writes, fn( $w ) => '/crm/v3/properties/contacts/groups' === $w['path'] );
		$this->assertCount( 1, $groups );
		$group = array_values( $groups )[0]['body'];
		$this->assertSame( array( 'name' => 'law26', 'label' => 'LAW2026', 'displayOrder' => -1 ), $group, 'Named by the prefix, labelled the way LAW label the year.' );

		$created = array();
		foreach ( $this->writes as $write ) {
			if ( 'POST' === $write['method'] && '/crm/v3/properties/contacts' === $write['path'] ) {
				$created[ $write['body']['name'] ] = $write['body']['groupName'];
			}
		}
		$this->assertSame(
			array(
				'law26_ec_passtype'    => 'law26',
				'law26_dietary_icon'   => 'law26',
				'law26_dietary'        => 'law26',
				'law26_dietary_other'  => 'law26',
				'law26_access'         => 'law26',
				'law26_access_other'   => 'law26',
				'law26_badge_manual'   => 'law26',
			),
			$created,
			'All seven, every one in the year group; nothing un-prefixed recreated.'
		);
		$this->assertSame( array(), array_filter( $actions, fn( $a ) => 'error' === $a['result'] ) );

		// And an un-prefixed property, when missing, still goes in the site group.
		$this->writes = array();
		unset( $this->portal['law_event_sectors'] );
		foreach ( array_keys( law_hubspot_property_definitions() ) as $name ) {
			if ( str_starts_with( $name, 'law26_' ) ) {
				$this->portal[ $name ] = array_merge( law_hubspot_property_definitions()[ $name ], array( 'name' => $name ) );
			}
		}
		$this->missing_groups = array();
		law_hubspot_properties_create();
		$this->assertCount( 1, $this->writes );
		$this->assertSame( 'law_site', $this->writes[0]['body']['groupName'] );
	}

	public function test_create_adds_only_what_is_missing_and_keeps_existing_options(): void {
		$this->missing_groups = array( 'law_site' );
		unset( $this->portal['law26_dietary_other'] );
		$this->portal['law_events_attending']['options'] = array(
			array( 'label' => 'Flagship 2026', 'value' => '2026 Flagship', 'displayOrder' => 3, 'hidden' => false, 'description' => 'kept' ),
			array( 'label' => '2025 Flagship', 'value' => '2025 Flagship', 'displayOrder' => 0, 'hidden' => true ),
		);

		$actions = law_hubspot_properties_create();

		$by_action = array();
		foreach ( $actions as $row ) {
			$by_action[ $row['action'] ][] = $row;
		}
		$this->assertCount( 1, $by_action['create_group'], 'Only the missing group is created; the year group was there.' );
		$this->assertSame( 'done', $by_action['create_group'][0]['result'] );
		$this->assertCount( 1, $by_action['create_property'] );
		$this->assertSame( 'law26_dietary_other', $by_action['create_property'][0]['target'] );
		$this->assertCount( 1, $by_action['add_options'] );

		$this->assertCount( 3, $this->writes );
		$this->assertSame( array( 'POST', '/crm/v3/properties/contacts/groups' ), array( $this->writes[0]['method'], $this->writes[0]['path'] ), 'The group comes first: the properties are created inside it.' );
		$this->assertSame( 'law_site', $this->writes[0]['body']['name'] );
		$this->assertSame( 'LAW site', $this->writes[0]['body']['label'] );

		$by_path = array();
		foreach ( array_slice( $this->writes, 1 ) as $write ) {
			$by_path[ $write['method'] . ' ' . $write['path'] ] = $write;
		}
		$create = $by_path['POST /crm/v3/properties/contacts'];
		$this->assertSame( 'law26_dietary_other', $create['body']['name'] );
		$this->assertSame( 'law26', $create['body']['groupName'], 'A year-prefixed property goes in the year group.' );
		$this->assertArrayNotHasKey( 'group', $create['body'], 'The module\'s own group key is not sent to HubSpot.' );
		$this->assertSame( 'string', $create['body']['type'] );

		$patch = $by_path['PATCH /crm/v3/properties/contacts/law_events_attending'];
		$values = array_column( $patch['body']['options'], 'value' );
		$this->assertSame(
			array_merge( array( '2026 Flagship', '2025 Flagship' ), array_values( array_diff( law_hubspot_attending_options(), array( '2026 Flagship' ) ) ) ),
			$values,
			'Existing options first and untouched, including the one the module does not know; new ones appended.'
		);
		$this->assertSame( 'Flagship 2026', $patch['body']['options'][0]['label'], 'An existing label is not "corrected".' );
		$this->assertSame( 'kept', $patch['body']['options'][0]['description'] );
		$this->assertTrue( $patch['body']['options'][1]['hidden'], 'A hidden option stays hidden.' );
		$this->assertArrayNotHasKey( 'type', $patch['body'], 'The PATCH carries options only.' );

		$log = law_hubspot_log_recent( 1 );
		$this->assertSame( 'properties', $log[0]->action );
		$this->assertSame( 'ok', $log[0]->result );
	}

	public function test_create_refuses_to_change_a_type(): void {
		$this->portal['law_events_registered_count']['type'] = 'string';

		$actions = law_hubspot_properties_create();
		$this->assertCount( 1, $actions );
		$this->assertSame( 'error', $actions[0]['result'] );
		$this->assertStringContainsString( 'cannot change', $actions[0]['message'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_create_in_dry_mode_sends_nothing_and_says_what_it_would_do(): void {
		$this->mode = 'dry';
		unset( $this->portal['law26_access_other'], $this->portal['law_sync_updated'] );
		// The local site's own dry-run rows would otherwise be counted below
		// (rolled back with the test's transaction).
		$GLOBALS['wpdb']->query( 'DELETE FROM ' . law_hubspot_log_table() );

		$actions = law_hubspot_properties_create();

		$this->assertSame( array(), $this->writes );
		$this->assertCount( 2, $actions );
		foreach ( $actions as $row ) {
			$this->assertSame( 'dry', $row['result'] );
			$this->assertSame( 'create_property', $row['action'] );
		}
		$this->assertSame( array( 'law26_access_other', 'law_sync_updated' ), array_column( $actions, 'target' ) );

		$dry = array_filter( law_hubspot_log_recent( 10 ), fn( $r ) => 'dry-write' === $r->action );
		$this->assertCount( 2, $dry );
	}

	public function test_adding_contact_type_options_appends_and_refuses_a_label_clash(): void {
		$options = array();
		foreach ( $this->all_tags() as $key => $tag ) {
			if ( 'press' !== $key && 'event_contact' !== $key ) {
				$options[] = array( 'label' => $tag, 'value' => $tag, 'displayOrder' => 0, 'hidden' => false );
			}
		}
		$options[]                               = array( 'label' => 'Mailchimp', 'value' => 'mailchimp', 'displayOrder' => 0, 'hidden' => false );
		$this->portal['contact_type']['options'] = $options;

		$actions = law_hubspot_contact_type_add_options();
		$this->assertSame( 'done', $actions[0]['result'] );
		$this->assertCount( 1, $this->writes );
		$patch = $this->writes[0];
		$this->assertSame( 'PATCH', $patch['method'] );
		$this->assertSame( '/crm/v3/properties/contacts/contact_type', $patch['path'] );
		$values = array_column( $patch['body']['options'], 'value' );
		$this->assertContains( 'mailchimp', $values, 'The staff-made option survives.' );
		$this->assertSame( array( '2026 Event contact', '2026 Press' ), array_slice( $values, -2 ) );
		$this->assertCount( count( $options ) + 2, $values );

		// A label clash is refused outright.
		$this->writes                            = array();
		$options[]                               = array( 'label' => '2026 Press', 'value' => 'Press', 'displayOrder' => 0, 'hidden' => false );
		$this->portal['contact_type']['options'] = $options;
		$actions                                 = law_hubspot_contact_type_add_options();
		$this->assertSame( 'error', $actions[0]['result'] );
		$this->assertStringContainsString( 'merge', $actions[0]['message'] );
		$this->assertSame( array(), $this->writes );
	}

	public function test_nothing_to_add_is_a_skip_not_a_write(): void {
		$actions = law_hubspot_contact_type_add_options();
		$this->assertSame( 'skipped', $actions[0]['result'] );
		$this->assertSame( array(), $this->writes );
	}

	/* ---- definitions ---------------------------------------------------- */

	public function test_definitions_follow_the_plan(): void {
		$definitions = law_hubspot_property_definitions();
		$this->assertSame(
			array(
				'law_events_attending',
				'law26_ec_passtype',
				'law26_dietary_icon',
				'law26_dietary',
				'law26_dietary_other',
				'law26_access',
				'law26_access_other',
				'law26_badge_manual',
				'law_events_registered_count',
				'law_events_registered',
				'law_event_sectors',
				'law_sync_updated',
			),
			array_keys( $definitions )
		);
		$this->assertSame( 'checkbox', $definitions['law_events_attending']['fieldType'] );
		$this->assertSame( 'select', $definitions['law26_ec_passtype']['fieldType'] );
		$this->assertSame( array( 'Delegate', 'Sponsor', 'Speaker', 'Committee', 'Press', 'Patron', 'Evening only' ), array_column( $definitions['law26_ec_passtype']['options'], 'value' ), 'Exhibitor retired; Press/Patron (Emily 4 Nov); Evening only (Milly 18 Nov).' );
		$this->assertSame( 'select', $definitions['law26_dietary_icon']['fieldType'] );
		$this->assertSame( array( 'Allergy or intolerance', 'Vegetarian or vegan', 'Pescatarian' ), array_column( $definitions['law26_dietary_icon']['options'], 'value' ) );
		$this->assertSame( 'checkbox', $definitions['law26_dietary']['fieldType'] );
		$this->assertSame( array( 'Vegetarian', 'Vegan', 'Pescatarian', 'Gluten free', 'Dairy free', 'Coeliac', 'Lactose free', 'Nut allergy' ), array_column( $definitions['law26_dietary']['options'], 'value' ), 'The site\'s own choices, minus Other (its text has its own property).' );
		$this->assertSame( 'checkbox', $definitions['law26_access']['fieldType'] );
		$this->assertSame( array( 'Captions', 'Sign language', 'Animal / PCA', 'Wheelchair' ), array_column( $definitions['law26_access']['options'], 'value' ) );
		$this->assertSame( 'booleancheckbox', $definitions['law26_badge_manual']['fieldType'] );
		$this->assertSame( array( 'true', 'false' ), array_column( $definitions['law26_badge_manual']['options'], 'value' ) );
		$this->assertSame( 'number', $definitions['law_events_registered_count']['type'] );
		$this->assertSame( 'datetime', $definitions['law_sync_updated']['type'] );
		foreach ( $definitions as $name => $definition ) {
			$this->assertMatchesRegularExpression( '/^law(\d\d)?_/', $name );
			if ( 'law26_badge_manual' === $name ) {
				continue; // HubSpot's own Yes/No pair: label and value differ by design.
			}
			foreach ( (array) ( $definition['options'] ?? array() ) as $option ) {
				$this->assertSame( $option['label'], $option['value'], $name . ': the module never lets label and value differ.' );
			}
		}
	}

	public function test_the_badging_set_is_named_for_the_events_year(): void {
		$this->isolate_option( LAW_EVENTS_SETTINGS_OPTION, array( 'year' => 2027 ) );
		$this->assertSame( 'law27', law_hubspot_year_prefix() );
		$this->assertSame( 'law27_ec_passtype', law_hubspot_property_name( 'passtype' ) );
		$this->assertSame( 'law27_badge_manual', law_hubspot_property_name( 'badge_manual' ) );
		$this->assertSame( 'law_events_attending', law_hubspot_property_name( 'events_attending' ), 'The un-prefixed ones carry the year in their values instead.' );
		$definitions = law_hubspot_property_definitions();
		$this->assertArrayHasKey( 'law27_dietary_icon', $definitions );
		$this->assertStringStartsWith( '2027 ', $definitions['law27_dietary_icon']['label'] );
	}

	public function test_superseded_properties_are_reported_and_archived_only_on_request(): void {
		$this->portal['law_delegate_type'] = $this->enumeration( 'law_delegate_type', 'Delegate type (current year)', array( 'Delegate' ) );
		$this->portal['law_dietary']       = array( 'name' => 'law_dietary', 'label' => 'Dietary requirements', 'type' => 'string', 'fieldType' => 'text' );

		$report = law_hubspot_properties_check();
		$this->assertTrue( $report['ok'], 'A leftover property is not a failure.' );
		$this->assertSame( array( 'law_delegate_type', 'law_dietary' ), array_keys( $report['superseded'] ) );
		$this->assertSame( 'Dietary requirements', $report['superseded']['law_dietary']['label'] );
		$this->assertSame( array(), $this->writes, 'The check never archives.' );

		law_hubspot_properties_create( $report );
		$this->assertSame( array(), $this->writes, 'Nor does create.' );

		$actions = law_hubspot_properties_archive( $report );
		$this->assertSame( array( 'archive_property', 'archive_property' ), array_column( $actions, 'action' ) );
		$this->assertSame( array( 'done', 'done' ), array_column( $actions, 'result' ) );
		$this->assertSame(
			array( 'DELETE /crm/v3/properties/contacts/law_delegate_type', 'DELETE /crm/v3/properties/contacts/law_dietary' ),
			array_map( fn( $w ) => $w['method'] . ' ' . $w['path'], $this->writes )
		);
	}

	public function test_archive_in_dry_mode_sends_nothing(): void {
		$this->mode                        = 'dry';
		$this->portal['law_accessibility'] = array( 'name' => 'law_accessibility', 'label' => 'Access requirements', 'type' => 'string', 'fieldType' => 'text' );

		$actions = law_hubspot_properties_archive();
		$this->assertSame( array(), $this->writes );
		$this->assertCount( 1, $actions );
		$this->assertSame( 'dry', $actions[0]['result'] );
		$this->assertSame( 'law_accessibility', $actions[0]['target'] );
	}

	public function test_archive_never_touches_a_property_the_module_still_writes(): void {
		// A config that (wrongly) lists a live property as superseded.
		$filter = function ( $config ) {
			$config['superseded_properties'][] = 'law_events_attending';
			return $config;
		};
		add_filter( 'law_hubspot_config', $filter );
		try {
			$actions = law_hubspot_properties_archive();
		} finally {
			remove_filter( 'law_hubspot_config', $filter );
		}
		$this->assertSame( array(), $this->writes );
		$this->assertSame( 'skipped', $actions[0]['result'] );
		$this->assertSame( 'law_events_attending', $actions[0]['target'] );
	}

	public function test_a_check_remembers_which_site_properties_the_portal_has(): void {
		unset( $this->portal['law26_ec_passtype'], $this->portal['law26_badge_manual'] );
		$this->isolate_option( 'law_hubspot_portal_properties' );

		law_hubspot_properties_check();

		$this->assertFalse( law_hubspot_portal_has_property( 'passtype' ), 'Not created yet (Kitty has to confirm the name), so the plan must not read or write it.' );
		$this->assertFalse( law_hubspot_portal_has_property( 'badge_manual' ) );
		$this->assertTrue( law_hubspot_portal_has_property( 'dietary_icon' ) );
		$this->assertTrue( law_hubspot_portal_has_property( 'events_attending' ) );
		$this->assertNotContains( 'law26_ec_passtype', law_hubspot_read_properties() );
		$this->assertContains( 'law26_dietary_icon', law_hubspot_read_properties() );
		$this->assertNotContains( 'law_sync_updated', law_hubspot_read_properties(), 'Written on every push; never worth reading back.' );
	}

	public function test_sector_options_are_decoded_term_names(): void {
		$term = wp_insert_term( 'Test &amp; Sector ' . wp_generate_password( 4, false ), 'law_sector' );
		$this->assertIsArray( $term );
		$values = law_hubspot_sector_values();
		$match  = array_values( array_filter( $values, fn( $v ) => str_starts_with( $v, 'Test & Sector' ) ) );
		$this->assertCount( 1, $match, 'The ampersand is a character, not an entity.' );
		wp_delete_term( $term['term_id'], 'law_sector' );
	}
}
