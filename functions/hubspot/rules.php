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
 * basis. Phase 3 (12 November 2026) adds the site-owned properties (§5.2):
 * the badging set for Event Connections and the segmentation counts.
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
 *     'site_properties'  => law_hubspot_site_properties() result (config key => value),
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
	$site       = array();
	$customer   = false;

	if ( $user ) {
		$tags[] = $config['tags']['registered'];

		$properties['firstname'] = trim( (string) $user->first_name );
		$properties['lastname']  = trim( (string) $user->last_name );
		$properties['jobtitle']  = trim( (string) get_user_meta( $user->ID, 'job_title', true ) );
		$properties['company']   = trim( (string) get_user_meta( $user->ID, 'organisation', true ) );
		$properties['country']   = trim( (string) get_user_meta( $user->ID, 'country', true ) );

		$bookings = law_hubspot_user_bookings( $user->ID );
		$site     = law_hubspot_site_properties( $bookings );

		foreach ( $bookings as $booking ) {
			$event_id              = (int) $booking->post_parent;
			$sources['bookings'][] = (int) $booking->ID;
			$customer              = true;
			$tags[]                = $config['tags']['attendee'];
			// Press is the committee-issued flag OR the Press ticket type; the
			// two are kept in step on write, but either alone is enough here.
			if ( law_event_meta( $booking->ID, '_law_is_press' ) || 'press' === (string) law_event_meta( $booking->ID, '_law_ticket_type' ) ) {
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
		'site_properties'  => $site,
		'sources'          => $sources,
	);
}

/* -------------------------------------------------------------------------
 * The site-owned properties (phase 3, HUBSPOT_SYNC.md §5.2)
 * ---------------------------------------------------------------------- */

/** Is this a confirmed place at an event LAW runs itself (flagship or reception)? */
function law_hubspot_booking_is_law_run( $booking ) {
	return in_array( law_booking_kind( $booking ), array( 'flagship', 'reception' ), true );
}

/** The LAW year an event belongs to (its law_year term), or 0 when it has none. */
function law_hubspot_event_year( $event_id ) {
	$terms = get_the_terms( (int) $event_id, 'law_year' );
	if ( ! is_array( $terms ) || ! $terms ) {
		return 0;
	}
	return (int) $terms[0]->name;
}

/**
 * Every law_events_attending option for this year, keyed by 'flagship' or the
 * reception's slug: the config's fixed labels plus "{year} {title}" for any
 * other reception the year has in a bookable status (approved or confirmed).
 * The config label wins where both exist, so the three seeded receptions keep
 * the names already in the portal whatever their titles become.
 *
 * A hard-coded list left production's fourth reception (Evensong at St
 * Paul's, 16 November 2026) off the property and the badging segment while
 * its attendees' access needs were still sent: the two halves of the rule
 * disagreed about what a LAW-run event was. Deriving the options from the
 * receptions themselves keeps them in step, and check-properties --create
 * adds a new option to the portal as it adds any other.
 *
 * The reception lookup is cached for the request; the config is merged fresh
 * each call so a law_hubspot_config filter still applies. Pass true to drop
 * the cache (tests that create receptions mid-run).
 *
 * @return array<string,string> 'flagship' | slug => option value.
 */
function law_hubspot_attending_options( $reset = false ) {
	static $derived = null;
	static $for     = 0;

	$year = law_hubspot_year();
	if ( $reset || null === $derived || $for !== $year ) {
		$derived = array();
		$for     = $year;
		if ( function_exists( 'law_reception_ids' ) ) {
			foreach ( law_reception_ids( $year ) as $reception_id ) {
				$post = get_post( $reception_id );
				if ( ! $post || ! in_array( $post->post_status, array( 'publish', 'law-approved' ), true ) ) {
					continue;
				}
				$title = trim( html_entity_decode( get_the_title( $post ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
				if ( '' !== $title ) {
					$derived[ $post->post_name ] = $year . ' ' . $title;
				}
			}
		}
	}

	// Config first so its label wins; '+' keeps the left operand's keys.
	return (array) law_hubspot_setting( 'attending_options', array() ) + $derived;
}

/**
 * The law_events_attending option a confirmed place at this event earns, or
 * '' when the event is neither the flagship nor one of the year's receptions
 * (law_hubspot_attending_options()).
 */
function law_hubspot_attending_option_for_event( $event_id ) {
	$event_id = (int) $event_id;
	$options  = law_hubspot_attending_options();
	if ( function_exists( 'law_flagship_is' ) && law_flagship_is( $event_id ) ) {
		return (string) ( $options['flagship'] ?? '' );
	}
	$post = get_post( $event_id );
	if ( ! $post || ! law_event_meta( $event_id, '_law_is_reception' ) ) {
		return '';
	}
	return (string) ( $options[ $post->post_name ] ?? '' );
}

/**
 * The badge's pass type from a set of ticket-type slugs: the first of the
 * config's `passtype_precedence` that is present, as its label. '' when none
 * is (an unclassified Flagship booking sends nothing, never a blank: a value
 * LAW typed by hand must survive a sync).
 *
 * @param string[] $slugs Ticket types held (law_booking_ticket_types() keys).
 */
function law_hubspot_passtype_from_types( array $slugs ) {
	$slugs  = array_filter( array_map( 'strval', $slugs ) );
	$labels = (array) law_hubspot_setting( 'passtype_values', array() );
	foreach ( (array) law_hubspot_setting( 'passtype_precedence', array() ) as $slug ) {
		if ( in_array( $slug, $slugs, true ) && isset( $labels[ $slug ] ) ) {
			return (string) $labels[ $slug ];
		}
	}
	return '';
}

/**
 * The badge pass type for this year's confirmed LAW-run bookings.
 *
 * Flagship guests: the committee's ticket types win under
 * `passtype_precedence`. Evening only is stripped — a conference place is
 * never an evening badge (Milly, 18 November 2026: Flagship stays Delegate
 * or whatever role was set). Unclassified Flagship still sends nothing.
 *
 * Reception-only guests (no Flagship place this year): a named role
 * (Press, Sponsor, Committee, Speaker, Patron) still wins; otherwise the
 * badge is Evening only — including when the reception booking is blank or
 * still says Delegate, because Delegate is reserved for the conference.
 *
 * @param WP_Post[] $law_run This year's confirmed LAW-run bookings.
 */
function law_hubspot_passtype_from_law_run( array $law_run ) {
	$types         = array();
	$has_flagship  = false;
	$has_reception = false;

	foreach ( $law_run as $booking ) {
		$kind = law_booking_kind( $booking );
		if ( 'flagship' === $kind ) {
			$has_flagship = true;
		} elseif ( 'reception' === $kind ) {
			$has_reception = true;
		}
		$type = (string) law_event_meta( $booking->ID, '_law_ticket_type' );
		if ( '' !== $type ) {
			$types[] = $type;
		}
		if ( law_event_meta( $booking->ID, '_law_is_press' ) ) {
			$types[] = 'press';
		}
	}

	if ( $has_flagship ) {
		$types = array_values( array_filter( $types, static fn( $slug ) => 'evening' !== $slug ) );
		return law_hubspot_passtype_from_types( $types );
	}

	if ( ! $has_reception ) {
		return '';
	}

	// Named roles keep their badge colour; Delegate / Evening only / blank
	// collapse to Evening only for reception-only guests.
	$roles = array_values(
		array_filter(
			$types,
			static fn( $slug ) => ! in_array( $slug, array( 'delegate', 'evening' ), true )
		)
	);
	if ( $roles ) {
		return law_hubspot_passtype_from_types( $roles );
	}

	$labels = (array) law_hubspot_setting( 'passtype_values', array() );
	return (string) ( $labels['evening'] ?? 'Evening only' );
}

/**
 * The one dietary icon for a set of the site's dietary choices: each choice
 * folded through config `dietary_icon_map`, and when more than one icon
 * results, the first in `dietary_icon_precedence` (an allergy beats a
 * preference). '' when no choice earns an icon.
 *
 * @param string[] $choices law_registration_dietary_choices() values.
 */
function law_hubspot_dietary_icon( array $choices ) {
	$map   = (array) law_hubspot_setting( 'dietary_icon_map', array() );
	$icons = array();
	foreach ( $choices as $choice ) {
		$choice = (string) $choice;
		if ( isset( $map[ $choice ] ) && '' !== (string) $map[ $choice ] ) {
			$icons[ (string) $map[ $choice ] ] = true;
		}
	}
	if ( ! $icons ) {
		return '';
	}
	foreach ( (array) law_hubspot_setting( 'dietary_icon_precedence', array() ) as $icon ) {
		if ( isset( $icons[ (string) $icon ] ) ) {
			return (string) $icon;
		}
	}
	// Every icon found is one the precedence list does not know: take the
	// first, so a map edit without a matching precedence edit still sends
	// something sensible rather than nothing.
	return (string) array_key_first( $icons );
}

/**
 * The site-owned property values for one account, from its confirmed
 * bookings (law_hubspot_user_bookings()). Keyed by CONFIG key, not internal
 * name; the sync step maps and diffs them (sync.php). Values are strings as
 * HubSpot takes them: multi-checkbox values `;`-joined.
 *
 * The badging values are facts about THIS YEAR's LAW-run places only
 * (§5.2, §7.3): a booking on another year's flagship, or on a hosted event,
 * contributes nothing to the pass type, icon, dietary or access properties.
 *
 *  - `events_attending`: the options this year's confirmed LAW-run places
 *    earn (the sync merges them with other years' options, which it keeps).
 *  - `passtype`: the winning ticket type's label (law_hubspot_passtype_from_law_run()),
 *    or '' = SEND NOTHING (unclassified Flagship only).
 *  - `dietary_icon`, `dietary`, `dietary_other`, `access`, `access_other`:
 *    only for someone with a LAW-run place this year, the union across those
 *    places of the account's answers with the booking's as fallback
 *    (law_booking_profile_values(), BOOK_ON_BEHALF.md A3). A person with a
 *    place and nothing ticked gets '' = CLEAR, so a requirement removed on
 *    the site goes from the badge too. A person with no LAW-run place this
 *    year gets '' for all five as well: the disclosure that justified
 *    sending them has ended.
 *  - `events_registered_count`, `events_registered`: this year's confirmed
 *    places on hosted events (everything that is not LAW-run), count and
 *    titles one per line. `event_sectors`: the sector terms of every event
 *    with a confirmed place, any year, `;`-joined.
 *
 * @param WP_Post[] $bookings Confirmed bookings, attendee = author.
 * @return array<string,mixed> Config key => value; `events_attending` is a string[].
 */
function law_hubspot_site_properties( array $bookings ) {
	$year      = law_hubspot_year();
	$attending = array();
	$law_run   = array();
	$hosted    = array();
	$sectors   = array();

	foreach ( $bookings as $booking ) {
		$event_id = (int) $booking->post_parent;
		if ( $event_id < 1 ) {
			continue;
		}
		foreach ( law_events_post_term_names( $event_id, 'law_sector' ) as $sector ) {
			// Decoded as law_hubspot_sector_values() decodes the option values.
			$sectors[ html_entity_decode( (string) $sector, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ] = true;
		}
		if ( law_hubspot_event_year( $event_id ) !== $year ) {
			continue;
		}
		if ( law_hubspot_booking_is_law_run( $booking ) ) {
			$law_run[] = $booking;
			$option    = law_hubspot_attending_option_for_event( $event_id );
			if ( '' !== $option ) {
				$attending[ $option ] = true;
			}
		} else {
			$hosted[ $event_id ] = html_entity_decode( get_the_title( $event_id ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		}
	}

	$dietary = array();
	$access  = array();
	$d_other = array();
	$a_other = array();
	foreach ( $law_run as $booking ) {
		$profile = law_booking_profile_values( $booking );
		foreach ( (array) ( $profile['dietary'] ?? array() ) as $choice ) {
			$dietary[ (string) $choice ] = true;
		}
		foreach ( (array) ( $profile['accessibility'] ?? array() ) as $choice ) {
			$access[ (string) $choice ] = true;
		}
		if ( '' !== trim( (string) ( $profile['dietary_other'] ?? '' ) ) ) {
			$d_other[ trim( (string) $profile['dietary_other'] ) ] = true;
		}
		if ( '' !== trim( (string) ( $profile['accessibility_other'] ?? '' ) ) ) {
			$a_other[ trim( (string) $profile['accessibility_other'] ) ] = true;
		}
	}
	// "Other" is a tick that says "see the text"; the text goes on its own.
	unset( $dietary['Other'], $access['Other'] );

	$sector_values = array_keys( $sectors );
	sort( $sector_values, SORT_NATURAL | SORT_FLAG_CASE );

	return array(
		'events_attending'        => array_keys( $attending ),
		'passtype'                => law_hubspot_passtype_from_law_run( $law_run ),
		'dietary_icon'            => law_hubspot_dietary_icon( array_keys( $dietary ) ),
		'dietary'                 => implode( ';', array_keys( $dietary ) ),
		'dietary_other'           => implode( ' / ', array_keys( $d_other ) ),
		'access'                  => implode( ';', array_keys( $access ) ),
		'access_other'            => implode( ' / ', array_keys( $a_other ) ),
		'events_registered_count' => (string) count( $hosted ),
		'events_registered'       => implode( "\n", array_values( $hosted ) ),
		'event_sectors'           => implode( ';', $sector_values ),
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
