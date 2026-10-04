<?php
/**
 * The rules: what HubSpot should hold for one person (_docs/HUBSPOT_SYNC.md
 * §5, §6.2).
 *
 * Pure in the sense that matters: these functions read WordPress and return
 * arrays. They write nothing, call no API and know nothing about the queue.
 * Everything in HUBSPOT_SYNC.md §5.1 and §5.3 lives here, which is where the
 * tests concentrate (HubSpotRulesTest).
 *
 * The unit is an EMAIL ADDRESS, not a user: a speaker may have no account, and
 * a person may be both an account and a speaker record. The desired state is
 * computed from everything the site knows about that address.
 *
 * Phase 2 covers the standard properties, the Contact type tags and legal
 * basis. The badging properties (§5.2) are phase 3 and are not computed yet.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* -------------------------------------------------------------------------
 * Who the site knows
 * ---------------------------------------------------------------------- */

/**
 * Every address the site knows about: all users, plus speaker records with an
 * email. For the backfill. Normalised and unique.
 *
 * @return string[]
 */
function law_hubspot_people() {
	global $wpdb;

	$emails = array();
	foreach ( (array) $wpdb->get_col( "SELECT user_email FROM {$wpdb->users}" ) as $email ) {
		$email = law_hubspot_normalise_email( $email );
		if ( '' !== $email ) {
			$emails[ $email ] = true;
		}
	}

	$speaker_emails = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT pm.meta_value FROM {$wpdb->postmeta} pm
			 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			 WHERE pm.meta_key = '_law_speaker_email' AND p.post_type = %s AND p.post_status <> 'trash'",
			LAW_SPEAKER_CPT
		)
	);
	foreach ( (array) $speaker_emails as $email ) {
		$email = law_hubspot_normalise_email( $email );
		if ( '' !== $email ) {
			$emails[ $email ] = true;
		}
	}

	$emails = array_keys( $emails );
	sort( $emails );
	return $emails;
}

/** The account behind an address, or null. */
function law_hubspot_user_for_email( $email ) {
	$email = law_hubspot_normalise_email( $email );
	if ( '' === $email ) {
		return null;
	}
	$user = get_user_by( 'email', $email );
	return $user instanceof WP_User ? $user : null;
}

/** The speaker record behind an address (the dedupe key), or 0. */
function law_hubspot_speaker_for_email( $email ) {
	$email = law_hubspot_normalise_email( $email );
	if ( '' === $email || ! function_exists( 'law_speaker_find_existing' ) ) {
		return 0;
	}
	return (int) law_speaker_find_existing( $email, '' );
}

/* -------------------------------------------------------------------------
 * What the site knows about them
 * ---------------------------------------------------------------------- */

/** Confirmed bookings this person is the attendee of (the booking's author). */
function law_hubspot_user_bookings( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}
	return get_posts(
		array(
			'post_type'      => LAW_BOOKING_CPT,
			'post_status'    => 'publish',
			'author'         => $user_id,
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		)
	);
}

/**
 * Approved events this person submitted (post author), excluding the ones LAW
 * runs itself: the flagship, the receptions and external events are authored
 * by committee accounts, and nobody is an "Event Host" for those.
 *
 * @return int[]
 */
function law_hubspot_user_hosted_events( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}
	$ids = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => law_hubspot_setting( 'approved_statuses', array() ),
			'author'         => $user_id,
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
		)
	);
	return array_values( array_filter( array_map( 'intval', $ids ), fn( $id ) => ! law_event_is_managed_by_law( $id ) ) );
}

/**
 * Approved events this person is a co-owner of (the flat `_law_co_owner`
 * rows law_event_set_co_owner_ids() writes).
 *
 * @return int[]
 */
function law_hubspot_user_co_owned_events( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return array();
	}
	$ids = get_posts(
		array(
			'post_type'      => LAW_EVENT_CPT,
			'post_status'    => law_hubspot_setting( 'approved_statuses', array() ),
			'fields'         => 'ids',
			'posts_per_page' => 200,
			'no_found_rows'  => true,
			'meta_query'     => array(
				array( 'key' => '_law_co_owner', 'value' => $user_id, 'type' => 'NUMERIC' ),
			),
		)
	);
	return array_values( array_filter( array_map( 'intval', $ids ), fn( $id ) => ! law_event_is_managed_by_law( $id ) ) );
}

/** Is this an approved event on the sponsor fee tier? */
function law_hubspot_event_is_sponsor_tier( $event_id ) {
	return 'sponsor' === (string) law_event_meta( (int) $event_id, '_law_fee_tier' );
}

/**
 * The Contact type tag for a confirmed place at this reception, or '' when the
 * event is not a reception the config names.
 */
function law_hubspot_reception_tag_for_event( $event_id ) {
	$post = get_post( (int) $event_id );
	if ( ! $post || ! law_event_meta( $post->ID, '_law_is_reception' ) ) {
		return '';
	}
	$tags = (array) law_hubspot_setting( 'reception_tags', array() );
	return (string) ( $tags[ $post->post_name ] ?? '' );
}

/**
 * Approved or published events this speaker record appears at (event-level or
 * session-level rows). Memoised per request by law_speakers_event_maps(); the
 * sync step flushes once per batch.
 *
 * @return int[]
 */
function law_hubspot_speaker_events( $speaker_id ) {
	$speaker_id = (int) $speaker_id;
	if ( $speaker_id < 1 || ! function_exists( 'law_speakers_event_maps' ) ) {
		return array();
	}
	$maps = law_speakers_event_maps( law_hubspot_setting( 'approved_statuses', array() ) );
	return array_map( 'intval', $maps['events'][ $speaker_id ] ?? array() );
}

/* -------------------------------------------------------------------------
 * The desired state
 * ---------------------------------------------------------------------- */

/**
 * What HubSpot should hold for one address.
 *
 * @param string $email
 * @return array|null Null when the site knows nothing about the address.
 *   array(
 *     'email'            => 'x@y.com',
 *     'hubspot_id'       => '123' | '',
 *     'properties'       => array( 'firstname' => ..., ... ),   // standard, full values
 *     'contact_type_add' => array( '2026 Attendee', ... ),
 *     'legal_basis_min'  => 'customer' | 'other' | '',
 *     'sources'          => array( 'user' => 12, 'speaker' => 0, 'bookings' => array(...), ... ),
 *   )
 */
function law_hubspot_desired_state( $email ) {
	$email = law_hubspot_normalise_email( $email );
	if ( '' === $email ) {
		return null;
	}

	$user       = law_hubspot_user_for_email( $email );
	$speaker_id = law_hubspot_speaker_for_email( $email );
	if ( ! $user && ! $speaker_id ) {
		return null;
	}

	$config  = law_hubspot_config();
	$id_key  = $config['id_meta_key'];
	$tags    = array();
	$sources = array(
		'user'            => $user ? (int) $user->ID : 0,
		'speaker'         => $speaker_id,
		'bookings'        => array(),
		'hosted'          => array(),
		'co_owned'        => array(),
		'speaking'        => array(),
		'press'           => false,
		'reception_tags'  => array(),
	);
	$properties = array( 'email' => $email );
	$customer   = false;

	if ( $user ) {
		$tags[] = $config['tags']['registered'];

		$properties['firstname'] = trim( (string) $user->first_name );
		$properties['lastname']  = trim( (string) $user->last_name );
		$properties['jobtitle']  = trim( (string) get_user_meta( $user->ID, 'job_title', true ) );
		$properties['company']   = trim( (string) get_user_meta( $user->ID, 'organisation', true ) );
		$properties['country']   = trim( (string) get_user_meta( $user->ID, 'country', true ) );

		foreach ( law_hubspot_user_bookings( $user->ID ) as $booking ) {
			$event_id              = (int) $booking->post_parent;
			$sources['bookings'][] = (int) $booking->ID;
			$customer              = true;
			$tags[]                = $config['tags']['attendee'];
			if ( law_event_meta( $booking->ID, '_law_is_press' ) ) {
				$sources['press'] = true;
				$tags[]           = $config['tags']['press'];
			}
			$reception_tag = law_hubspot_reception_tag_for_event( $event_id );
			if ( '' !== $reception_tag ) {
				$sources['reception_tags'][] = $reception_tag;
				$tags[]                      = $reception_tag;
			}
		}

		$sources['hosted'] = law_hubspot_user_hosted_events( $user->ID );
		if ( $sources['hosted'] ) {
			$customer = true;
			$tags[]   = $config['tags']['event_host'];
		}

		$sources['co_owned'] = law_hubspot_user_co_owned_events( $user->ID );
		if ( $sources['co_owned'] ) {
			$customer = true;
			$tags[]   = $config['tags']['event_contact'];
		}

		foreach ( array_merge( $sources['hosted'], $sources['co_owned'] ) as $event_id ) {
			if ( law_hubspot_event_is_sponsor_tier( $event_id ) ) {
				$tags[] = $config['tags']['sponsor'];
				break;
			}
		}
	}

	if ( $speaker_id ) {
		$sources['speaking'] = law_hubspot_speaker_events( $speaker_id );
		if ( $sources['speaking'] ) {
			$customer = true;
			$tags[]   = $config['tags']['speaker'];
		}

		// A speaker with no account still gets a name, and the organisation
		// and job title from their most recent appearance. An account's own
		// profile wins when both exist.
		$parts = function_exists( 'law_speaker_name_parts' ) ? law_speaker_name_parts( $speaker_id ) : array( 'first' => '', 'last' => '' );
		if ( empty( $properties['firstname'] ) && '' !== $parts['first'] ) {
			$properties['firstname'] = $parts['first'];
		}
		if ( empty( $properties['lastname'] ) && '' !== $parts['last'] ) {
			$properties['lastname'] = $parts['last'];
		}
		if ( function_exists( 'law_speaker_latest_appearance' ) && ( empty( $properties['jobtitle'] ) || empty( $properties['company'] ) ) ) {
			$latest = law_speaker_latest_appearance( $speaker_id, law_hubspot_setting( 'approved_statuses', array() ) );
			if ( empty( $properties['jobtitle'] ) && '' !== $latest['job_title'] ) {
				$properties['jobtitle'] = $latest['job_title'];
			}
			if ( empty( $properties['company'] ) && '' !== $latest['organisation'] ) {
				$properties['company'] = $latest['organisation'];
			}
		}
	}

	// Empty standard values are not sent: the site should never blank a name
	// or company that LAW typed into HubSpot because a profile field is empty.
	$properties = array_filter( $properties, fn( $value ) => '' !== (string) $value );

	$hubspot_id = '';
	if ( $user ) {
		$hubspot_id = (string) get_user_meta( $user->ID, $id_key, true );
	}
	if ( '' === $hubspot_id && $speaker_id ) {
		$hubspot_id = (string) get_post_meta( $speaker_id, $id_key, true );
	}

	return array(
		'email'            => $email,
		'hubspot_id'       => $hubspot_id,
		'properties'       => $properties,
		'contact_type_add' => array_values( array_unique( array_filter( $tags ) ) ),
		'legal_basis_min'  => $customer ? 'customer' : ( $user ? 'other' : '' ),
		'sources'          => $sources,
	);
}

/**
 * Remember the HubSpot record ID for a person, on the user and/or the speaker
 * post, so later updates go by ID and an email change cannot fork the contact
 * (HUBSPOT_SYNC.md §5.1, §14.4).
 */
function law_hubspot_store_id( array $state, $hubspot_id ) {
	$hubspot_id = trim( (string) $hubspot_id );
	if ( '' === $hubspot_id ) {
		return;
	}
	$id_key = law_hubspot_setting( 'id_meta_key', 'law_hubspot_id' );
	if ( ! empty( $state['sources']['user'] ) ) {
		update_user_meta( (int) $state['sources']['user'], $id_key, $hubspot_id );
	}
	if ( ! empty( $state['sources']['speaker'] ) ) {
		update_post_meta( (int) $state['sources']['speaker'], $id_key, $hubspot_id );
	}
}

/** Forget a stored ID that HubSpot no longer recognises (merged or deleted contact). */
function law_hubspot_forget_id( array $state ) {
	$id_key = law_hubspot_setting( 'id_meta_key', 'law_hubspot_id' );
	if ( ! empty( $state['sources']['user'] ) ) {
		delete_user_meta( (int) $state['sources']['user'], $id_key );
	}
	if ( ! empty( $state['sources']['speaker'] ) ) {
		delete_post_meta( (int) $state['sources']['speaker'], $id_key );
	}
}

/* -------------------------------------------------------------------------
 * Merging with what HubSpot already holds (shared properties, §3.5)
 * ---------------------------------------------------------------------- */

/** HubSpot's multi-checkbox value ("a;b;c") as a clean list. */
function law_hubspot_split_multi( $value ) {
	$parts = array_map( 'trim', explode( ';', (string) $value ) );
	return array_values( array_unique( array_filter( $parts, fn( $p ) => '' !== $p ) ) );
}

/**
 * Contact type after the site has had its say: everything already there, in
 * its order, plus the site's tags appended. Never removes anything, including
 * options the site has never heard of.
 *
 * @param string   $existing HubSpot's current value.
 * @param string[] $add      Tags the rules want present.
 * @return string New value for the property.
 */
function law_hubspot_merge_contact_type( $existing, array $add ) {
	$values = law_hubspot_split_multi( $existing );
	foreach ( $add as $tag ) {
		$tag = trim( (string) $tag );
		if ( '' !== $tag && ! in_array( $tag, $values, true ) ) {
			$values[] = $tag;
		}
	}
	return implode( ';', $values );
}

/**
 * Legal basis after the site has had its say, or null to leave it alone.
 *
 * Set when empty; upgrade "other" to "existing customer"; never downgrade and
 * never touch a value LAW chose (consent, contract, lead...). The property is
 * a checkbox type in the portal, so an existing value may be a list; a list
 * that already names "existing customer" is left, and a list of exactly one
 * "other" is upgraded.
 *
 * @param string $existing HubSpot's current value.
 * @param string $minimum  'customer' | 'other' | ''.
 * @return string|null
 */
function law_hubspot_merge_legal_basis( $existing, $minimum ) {
	$config   = law_hubspot_config();
	$other    = $config['legal_basis_other'];
	$customer = $config['legal_basis_customer'];
	$current  = law_hubspot_split_multi( $existing );

	if ( '' === (string) $minimum ) {
		return null;
	}
	if ( ! $current ) {
		return 'customer' === $minimum ? $customer : $other;
	}
	if ( 'customer' === $minimum && array( $other ) === $current ) {
		return $customer;
	}
	return null;
}
