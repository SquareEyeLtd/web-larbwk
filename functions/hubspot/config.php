<?php
/**
 * HubSpot module configuration (_docs/HUBSPOT_SYNC.md §4).
 *
 * Everything the module needs to know that is not a rule lives here: the mode
 * switch, the token, the portal, and every tag string and property name the
 * rules and the property check use. A client answer to one of the open
 * decisions in HUBSPOT_SYNC.md §11 should be a one-line change in
 * law_hubspot_config(), not a search through the module.
 *
 * Secrets and the mode come from wp-config.php constants, never the database:
 *
 *   define( 'LAW_HUBSPOT_TOKEN', 'pat-eu1-...' );   // private app token
 *   define( 'LAW_HUBSPOT_MODE', 'dry' );            // off | dry | live
 *
 * Unset mode means `off` (HUBSPOT_SYNC.md §14.1): a checkout with no config
 * does nothing at all. `dry` computes and logs every write without sending
 * it, and is what local and staging should run. `live` is production only;
 * see law_hubspot_mode_for() for the rail that enforces that.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_HUBSPOT_BASE_URL  = 'https://api.hubapi.com';
const LAW_HUBSPOT_PORTAL_ID = 148143869;

/** The three modes, in order of how much they are allowed to do. */
function law_hubspot_modes() {
	return array( 'off', 'dry', 'live' );
}

/**
 * The mode wp-config.php asks for, before any safety rail. Unknown or unset
 * means `off`.
 */
function law_hubspot_configured_mode() {
	$mode = defined( 'LAW_HUBSPOT_MODE' ) ? strtolower( trim( (string) LAW_HUBSPOT_MODE ) ) : 'off';
	return in_array( $mode, law_hubspot_modes(), true ) ? $mode : 'off';
}

/**
 * The mode the module actually runs in, given what was configured and where
 * it is running. Pure, so the rail can be unit-tested without defining
 * constants.
 *
 * The one rule: `live` on a local or development environment becomes `dry`.
 * LAW's portal is on HubSpot Professional, which has no sandbox, so a local
 * database full of test accounts must never be able to reach it -- however
 * the wp-config.php got there (copied from production, say). Staging is left
 * alone: it has real-looking data and is where the dry-run review happens,
 * and its wp-config.php is written on purpose.
 *
 * @param string $configured  A law_hubspot_modes() value.
 * @param string $environment wp_get_environment_type() value.
 * @return string Effective mode.
 */
function law_hubspot_mode_for( $configured, $environment ) {
	if ( 'live' === $configured && in_array( $environment, array( 'local', 'development' ), true ) ) {
		return 'dry';
	}
	return in_array( $configured, law_hubspot_modes(), true ) ? $configured : 'off';
}

/** Effective mode: off | dry | live. Filterable so tests can run the client in any mode. */
function law_hubspot_mode() {
	$mode = law_hubspot_mode_for( law_hubspot_configured_mode(), wp_get_environment_type() );
	$mode = apply_filters( 'law_hubspot_mode', $mode );
	return in_array( $mode, law_hubspot_modes(), true ) ? $mode : 'off';
}

/** Was `live` asked for and refused by the environment rail? The admin screen says so. */
function law_hubspot_mode_is_downgraded() {
	return 'live' === law_hubspot_configured_mode()
		&& 'live' !== law_hubspot_mode_for( law_hubspot_configured_mode(), wp_get_environment_type() );
}

/** Anything other than `off`: hooks, cron and the client are allowed to run. */
function law_hubspot_enabled() {
	return 'off' !== law_hubspot_mode();
}

/**
 * The private app token, or '' when wp-config.php has none. The filter exists
 * for the test bootstrap, which replaces whatever the constant holds with a
 * dummy so no test ever handles the real token.
 */
function law_hubspot_token() {
	$token = defined( 'LAW_HUBSPOT_TOKEN' ) ? trim( (string) LAW_HUBSPOT_TOKEN ) : '';
	return (string) apply_filters( 'law_hubspot_token', $token );
}

/** The LAW year the tags and year-prefixed options carry. */
function law_hubspot_year() {
	return (int) law_events_setting( 'year', (int) gmdate( 'Y' ) );
}

/**
 * Every string the module writes to or reads from HubSpot, in one place.
 *
 * Tag strings keep the vocabulary functions/hubspot.php and
 * law_registration_hubspot_tags() have always used, so the September manual
 * tags and the new ones are the same options.
 *
 * `contact_type_property` and the two legal-basis values are the module's best
 * reading of the portal and are CONFIRMED, not assumed: the property check
 * (properties.php) compares them against what the portal actually has and the
 * admin screen reports any mismatch before a live push is possible
 * (HUBSPOT_SYNC.md §10.10, §7.1).
 *
 * @return array<string,mixed>
 */
function law_hubspot_config() {
	$year = law_hubspot_year();

	$config = array(
		'year'                  => $year,
		'portal_id'             => LAW_HUBSPOT_PORTAL_ID,

		// Shared properties: LAW staff edit these too. Add-only / upgrade-only.
		// Values confirmed against portal 148143869 on 4 October 2026. Note the
		// legal-basis pair is NOT consistent: "existing customer" carries an en
		// dash and "other" a plain hyphen. Both are copied exactly from the portal.
		'contact_type_property' => 'contact_type',
		'legal_basis_property'  => 'hs_legal_basis',
		'legal_basis_other'     => 'Legitimate interest - other',
		'legal_basis_customer'  => 'Legitimate interest – existing customer',

		// Contact type tags (HUBSPOT_SYNC.md §5.3). Keys are what the rules
		// speak; values are the option values HubSpot must hold. The casing
		// follows the portal's existing options ("Event Host" but "Event
		// contact"), because a value is matched exactly.
		'tags'                  => array(
			'registered'    => $year . ' Registered user',
			'event_host'    => $year . ' Event Host',
			'event_contact' => $year . ' Event contact',
			'sponsor'       => $year . ' Sponsor',
			'attendee'      => $year . ' Attendee',
			'speaker'       => $year . ' Speaker',
			'press'         => $year . ' Press',
		),

		// Contact type tags for a confirmed reception place, keyed by the
		// reception post's slug (law_reception_seed_map()). The portal already
		// held these three options when the module arrived (4 October 2026);
		// Trevor asked for them to be set alongside law_events_attending,
		// pending Emily's confirmation. A reception with another slug gets no
		// Contact type tag (it still counts as Attendee).
		'reception_tags'        => array(
			'opening-drinks'      => $year . ' Monday reception',
			'wednesday-reception' => $year . ' Wednesday reception',
			'friday-reception'    => $year . ' Friday reception',
		),

		// Event statuses that mean "approved" for the host, co-owner, sponsor
		// and speaker tags (law_event_statuses()).
		'approved_statuses'     => array( 'law-approved', 'publish' ),

		// Site-owned properties (HUBSPOT_SYNC.md §5.2): internal names, and the
		// group they are created in so they sit together on the contact record.
		'property_group'        => array( 'name' => 'law_site', 'label' => 'LAW site' ),
		'properties'            => array(
			'events_attending'        => 'law_events_attending',
			'delegate_type'           => 'law_delegate_type',
			'dietary'                 => 'law_dietary',
			'accessibility'           => 'law_accessibility',
			'events_registered_count' => 'law_events_registered_count',
			'events_registered'       => 'law_events_registered',
			'event_sectors'           => 'law_event_sectors',
			'sync_updated'            => 'law_sync_updated',
		),

		// law_events_attending options, keyed by the reception seed slug
		// (law_reception_seed_map()) or 'flagship'. Year-prefixed so 2027 adds
		// options rather than overwriting 2026.
		'attending_options'     => array(
			'flagship'            => $year . ' Flagship',
			'opening-drinks'      => $year . ' Monday reception',
			'wednesday-reception' => $year . ' Wednesday reception',
			'friday-reception'    => $year . ' Friday reception',
		),

		// law_delegate_type options: the committee's own ticket types
		// (law_booking_ticket_types()), label as value so the badging vendor
		// reads "Speaker", not "speaker".
		'delegate_types'        => array_values( law_booking_ticket_types() ),

		// Where the HubSpot record ID is remembered after the first sync, on
		// the user (user meta) or the speaker (post meta).
		'id_meta_key'           => 'law_hubspot_id',

		// Queue worker: attempt n that fails waits this long before attempt n+1;
		// a failure after the last entry parks the row (HUBSPOT_SYNC.md §6.3).
		'backoff'               => array( 5 * MINUTE_IN_SECONDS, 30 * MINUTE_IN_SECONDS, 2 * HOUR_IN_SECONDS, 12 * HOUR_IN_SECONDS ),
		'batch_size'            => 100,
		'log_retention_days'    => 90,
	);

	return apply_filters( 'law_hubspot_config', $config );
}

/** One config value, with a default when the key is absent. */
function law_hubspot_setting( $key, $default = null ) {
	$config = law_hubspot_config();
	return array_key_exists( $key, $config ) ? $config[ $key ] : $default;
}

/** A site-owned property's internal name, from its config key. */
function law_hubspot_property_name( $key ) {
	$names = law_hubspot_setting( 'properties', array() );
	return (string) ( $names[ $key ] ?? '' );
}

/** A Contact type tag string, from its config key. */
function law_hubspot_tag( $key ) {
	$tags = law_hubspot_setting( 'tags', array() );
	return (string) ( $tags[ $key ] ?? '' );
}
