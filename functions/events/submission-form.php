<?php
/**
 * The custom front-end submission and edit form (EVENTS_4.1_REBUILD.md §3.5),
 * replacing form 2 (Event > submit an event). Server-rendered sections,
 * vanilla-JS repeaters, draft saving as the law-draft status, a per-state
 * field whitelist, and the page 372 confirmation redirect.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Roles allowed to submit events ("hosts and above", as the Members gate). */
function law_events_user_can_submit( $user_id = 0 ) {
	$user = $user_id ? get_user_by( 'id', $user_id ) : wp_get_current_user();
	if ( ! $user || ! $user->exists() ) {
		return false;
	}
	if ( user_can( $user, 'edit_others_law_events' ) ) {
		return true;
	}
	return (bool) array_intersect( array( 'event_host', 'sponsor' ), (array) $user->roles );
}

/** The event being edited on the form page, 0 for a new submission. */
function law_events_form_event_id() {
	return absint( $_GET['law_event'] ?? 0 );
}

/**
 * Fields locked once the event is approved/published, per the 4.2 §4.2
 * rules: for hosts, title, date/slots, fees, the approved capacity band and
 * programme-grid facts (type, sectors, host organisations) freeze;
 * description, speakers, venue, agenda, TICKET ALLOCATIONS (within the
 * approved band), contacts and co-owners stay editable. Committee members
 * bypass every lock EXCEPT fees/invoice (Denis, 7 September 2026): fee
 * changes stay in the dashboard override control and wp-admin, so the
 * snapshot machinery has one front-end door fewer. Pre-approval statuses
 * are fully unlocked for everyone — the fee is only snapshotted at
 * approval, and locking the tier earlier would break validation for a
 * committee member submitting their own event.
 */
function law_events_locked_fields( $post, $user_id = 0 ) {
	if ( ! $post || in_array( $post->post_status, array( 'law-draft', 'law-proposed', 'law-sent-back' ), true ) ) {
		return array();
	}
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( law_user_is_committee( $user_id ) ) {
		return array( 'fee_tier', 'invoice' );
	}
	return array( 'title', 'type', 'preferred_slots', 'fee_tier', 'invoice', 'sectors', 'host_organisations', 'venue_capacity', 'venue_needed' );
}


/**
 * Whether the Venue detail fields (venue name/address, capacity band, places
 * available) are on this submitter's form.
 *
 * A deliberate divergence from form 2 (Event > submit an event), where only
 * field 21 (Venue) was conditional on field 103 (Venue needed) and field 55
 * (Venue capacity) / field 54 (Tickets available) carried no conditional logic
 * at all: a host who has just asked LAW to find them a venue cannot answer any
 * of the three, and any number they give is a guess, so they are asked none of
 * them (Denis, 9 September 2026). The committee always sees the block, because
 * setting the venue and its capacity band once the event has been placed is
 * their job. Keyed on the capability rather than on the template's 'context'
 * arg, which is copy/voice only, so a committee member submitting their own
 * event through the host form gets the same block.
 *
 * @param string $venue_needed The Venue needed answer to judge (stored value
 *                             when the field is locked, else the posted one).
 * @param int    $user_id      Defaults to the current user.
 */
function law_events_venue_details_visible( $venue_needed, $user_id = 0 ) {
	$user_id = $user_id ? (int) $user_id : get_current_user_id();
	if ( law_user_is_committee( $user_id ) ) {
		return true;
	}
	return 0 === strpos( (string) $venue_needed, 'No,' );
}


/**
 * The Venue needed answer to judge visibility by.
 *
 * The field is in the host lock list, and a disabled radio posts nothing, so
 * post-approval a host's posted value is always ''. Reading the stored value
 * in that case is what keeps the venue name editable for a host whose event
 * has a venue -- the same fallback the ticket/band check uses.
 *
 * @param WP_Post|null $post   Event being saved (null on a new submission).
 * @param array        $locked law_events_locked_fields() for this save.
 * @param array        $input  Unslashed POST data.
 */
function law_events_venue_needed_value( $post, array $locked, array $input ) {
	if ( in_array( 'venue_needed', $locked, true ) && $post ) {
		return (string) law_event_meta( $post->ID, '_law_venue_needed' );
	}
	return (string) ( $input['venue_needed'] ?? '' );
}

/**
 * Whether the Session agenda section is available on this event's form.
 *
 * The committee's _law_session_agenda switch is the opt-in (4.2 §3.6 calls the
 * enhanced agenda "opt-in per event and configured by LAW admin"), replacing
 * the earlier arrangement where the section was always on the form and the
 * opt-in was merely whether any sessions had been typed into it.
 *
 * An event that already HAS sessions always keeps the section, whatever the
 * switch says. Two reasons: hiding it would strand an existing agenda with no
 * way to edit or remove it, and law_events_form_save_sessions() deletes the
 * rows the form did not post, so a hidden section on an opted-out event is
 * exactly the shape that silently destroys a host's agenda. To take an agenda
 * away, the committee deletes the sessions and then unticks the box.
 *
 * A brand-new event returns false: it does not exist yet, so there is no
 * switch to read and no committee member has seen it.
 *
 * Deliberately NOT memoised. The gate is consulted a few times per render, so
 * a static cache looked worth having, but any caller that writes the switch and
 * then asks again in the same request (the committee handler deciding which
 * save notice to show, the backfill, a test) would get the stale answer. Two
 * small reads are cheaper than that class of bug, and post meta is already
 * served from the object cache after the first read.
 *
 * @param WP_Post|int|null $post Event post or ID.
 */
function law_event_has_session_agenda( $post ) {
	$event_id = $post instanceof WP_Post ? (int) $post->ID : (int) $post;
	if ( ! $event_id || LAW_EVENT_CPT !== get_post_type( $event_id ) ) {
		return false;
	}
	return (bool) law_event_meta( $event_id, '_law_session_agenda' )
		|| (bool) law_event_session_ids( $event_id );
}

/**
 * The form's sections, in order: key => nav label.
 *
 * One list for both consumers (templates/account-event-form.php and
 * parts/events/committee-event-form.php), which each hard-coded their own copy
 * until the Session agenda section became conditional and the two would have
 * had to drift apart. Mirrors the fieldset ids in
 * parts/events/event-form-fields.php plus each template's own
 * law-section-finish, which remain a separate list.
 *
 * @param WP_Post|int|null $post Event being edited, or null on a new one.
 */
function law_events_form_sections( $post = null ) {
	$sections = array(
		'details'  => 'Event details',
		'speakers' => 'Speakers',
		'venue'    => 'Venue',
		'owners'   => 'Owners & contacts',
		'fees'     => 'Fees',
		'agenda'   => 'Session agenda',
		'finish'   => 'Finish',
	);
	if ( ! law_event_has_session_agenda( $post ) ) {
		unset( $sections['agenda'] );
	}
	return $sections;
}

/**
 * Validation + persistence for one form post.
 *
 * @param array   $input   Unslashed POST data.
 * @param array   $files   $_FILES.
 * @param WP_Post $post    Existing event or null.
 * @param int     $user_id Acting host.
 * @return int|WP_Error Saved post ID.
 */
function law_events_form_save( array $input, array $files, $post, $user_id ) {
	$is_new = ! $post;
	$locked = $post ? law_events_locked_fields( $post, $user_id ) : array();
	// Read once, here, so the save guard below and anything else that asks
	// cannot disagree with the form that was rendered.
	$has_agenda = law_event_has_session_agenda( $post );
	$errors = new WP_Error();
	// Read before the writes: raising the places is what offers them to the
	// waitlist (see the call after the writes loop).
	$before_tickets = $post ? (int) law_event_meta( $post->ID, '_law_tickets_available' ) : 0;

	$title       = sanitize_text_field( $input['event_title'] ?? '' );
	// The narrower events allowlist, not wp_kses_post(): a host may emphasise
	// and structure a description, not embed media or layout (rich-text.php).
	$description = law_rich_text_sanitize( $input['description'] ?? '' );
	$is_draft    = 'draft' === ( $input['law_form_action'] ?? '' );

	// The repeater rows' rich-text fields, normalised before anything else reads
	// them. An emptied editor posts "<p>&nbsp;</p>" rather than an empty string,
	// which would otherwise make a cleared row look filled and sail through the
	// "each session needs a description" check below.
	foreach ( array( 'speakers' => 'bio', 'sessions' => 'description' ) as $law_group => $law_rich_field ) {
		foreach ( (array) ( $input[ $law_group ] ?? array() ) as $law_row_index => $law_row ) {
			if ( is_array( $law_row ) && isset( $law_row[ $law_rich_field ] ) ) {
				$input[ $law_group ][ $law_row_index ][ $law_rich_field ] = law_rich_text_sanitize( $law_row[ $law_rich_field ] );
			}
		}
	}

	// Preferred slots are checked against the configured list before they are
	// validated, so a tampered POST cannot smuggle in a label that is not a
	// slot (or re-select a retired one the event never held), and validation
	// sees exactly what will be written.
	$preferred_slots = law_events_sanitise_preferred_slots(
		(array) ( $input['preferred_slots'] ?? array() ),
		$post ? (array) law_event_meta( $post->ID, '_law_preferred_slots' ) : array()
	);

	// Even a draft needs a title: an untitled post would be invisible on the
	// host dashboard (and core refuses fully empty posts with a raw error).
	if ( '' === $title && ! in_array( 'title', $locked, true ) ) {
		$errors->add( 'event_title', $is_draft ? 'Please give the event a title before saving a draft.' : 'Please give the event a title.' );
	}
	if ( ! $is_draft ) {
		if ( law_rich_text_is_empty( $description ) ) {
			$errors->add( 'description', 'Please describe the event.' );
		}
		// The required set below mirrors form 2 (Event > submit an event) field
		// for field, so the custom form refuses exactly what Gravity Forms
		// refused: 63 Event type, 105 Host organisation(s), 77 Preferred date &
		// time slots, 103 Venue needed, 21 Venue (conditional), 61/62 the two
		// sector "please specify" inputs (conditional), and 74 Address.
		// A locked field is never re-validated: an approved event's host cannot
		// change it, so a blank one is the committee's to fix, not theirs.
		if ( ! in_array( 'type', $locked, true ) && '' === trim( (string) ( $input['event_type'] ?? '' ) ) ) {
			$errors->add( 'event_type', 'Please choose the event type.' );
		}
		if ( ! in_array( 'host_organisations', $locked, true ) && '' === trim( (string) ( $input['host_organisations'] ?? '' ) ) ) {
			$errors->add( 'host_organisations', 'Please give the host organisation(s).' );
		}
		if ( ! in_array( 'preferred_slots', $locked, true ) && ! $preferred_slots ) {
			$errors->add( 'preferred_slots', 'Please choose at least one preferred date and time slot.' );
		}
		if ( ! in_array( 'sectors', $locked, true ) ) {
			$sectors = (array) ( $input['sectors'] ?? array() );
			if ( in_array( 'Jurisdiction-specific', $sectors, true ) && '' === trim( (string) ( $input['sector_jurisdiction'] ?? '' ) ) ) {
				$errors->add( 'sector_jurisdiction', 'Please specify the jurisdiction.' );
			}
			if ( in_array( 'Other / sector-neutral', $sectors, true ) && '' === trim( (string) ( $input['sector_other'] ?? '' ) ) ) {
				$errors->add( 'sector_other', 'Please specify the other sector.' );
			}
		}
		if ( ! in_array( 'venue_needed', $locked, true ) ) {
			$venue_needed = (string) ( $input['venue_needed'] ?? '' );
			if ( '' === $venue_needed ) {
				$errors->add( 'venue_needed', 'Please tell us whether you need a venue.' );
			} elseif ( 0 === strpos( $venue_needed, 'No,' ) && '' === trim( (string) ( $input['venue'] ?? '' ) ) ) {
				$errors->add( 'venue', 'Please give the venue name and/or address.' );
			}
		}
		// Tickets can never exceed the approved venue capacity band. The band
		// itself is locked after approval (and a disabled <select> posts nothing),
		// so on an approved event the stored value is the one to check against —
		// hosts keep editing ticket allocations WITHIN that band. A submitter who
		// was never asked for places is not judged on them either: their posted
		// value is ignored on save, so refusing it here would block the rest of
		// their form over a field they cannot see.
		$tickets = law_events_venue_details_visible( law_events_venue_needed_value( $post, $locked, $input ), $user_id )
			? trim( (string) ( $input['tickets_available'] ?? '' ) )
			: '';
		if ( '' !== $tickets ) {
			$capacity = in_array( 'venue_capacity', $locked, true ) && $post
				? (string) law_event_meta( $post->ID, '_law_venue_capacity' )
				: (string) ( $input['venue_capacity'] ?? '' );
			$bands = law_events_venue_capacity_bands();
			$limit = $bands[ $capacity ] ?? null;
			if ( (int) $tickets < 1 ) {
				$errors->add( 'tickets_available', 'Tickets available must be at least 1.' );
			} elseif ( null !== $limit && (int) $tickets > $limit ) {
				$errors->add(
					'tickets_available',
					sprintf(
						'Tickets available cannot exceed the venue capacity you chose (%1$s allows at most %2$d).',
						$capacity,
						$limit
					)
				);
			}
		}
		$tier = sanitize_key( $input['fee_tier'] ?? '' );
		if ( ! in_array( 'fee_tier', $locked, true ) ) {
			if ( '' === $tier ) {
				$errors->add( 'fee_tier', 'Please choose your fee tier.' );
			} elseif ( 'sponsor' !== $tier && ! in_array( 'invoice', $locked, true ) ) {
				if ( ! is_email( sanitize_email( $input['invoice_email'] ?? '' ) ) ) {
					$errors->add( 'invoice_email', 'Please give a valid invoice contact email.' );
				}
				if ( '' === trim( (string) ( $input['invoice_name'] ?? '' ) ) ) {
					$errors->add( 'invoice_name', 'Please give the invoice contact name.' );
				}
				// A required Gravity Forms address (field 74) requires street,
				// city, postcode and country; line 2 and county/state stay optional.
				if ( '' === trim( (string) ( $input['invoice_line1'] ?? '' ) ) ) {
					$errors->add( 'invoice_line1', 'Please give the first line of the billing address.' );
				}
				if ( '' === trim( (string) ( $input['invoice_city'] ?? '' ) ) ) {
					$errors->add( 'invoice_city', 'Please give the billing city.' );
				}
				if ( '' === trim( (string) ( $input['invoice_postal_code'] ?? '' ) ) ) {
					$errors->add( 'invoice_postal_code', 'Please give the billing postcode.' );
				}
				// Same list as the registration and profile forms, with one exception:
				// whatever the event already has stored stays acceptable, so a
				// migrated country spelled differently doesn't trap the host on a
				// field they never touched.
				$invoice_country = trim( (string) ( $input['invoice_country'] ?? '' ) );
				$stored_country  = $post ? (string) ( law_event_meta( $post->ID, '_law_invoice_address' )['country'] ?? '' ) : '';
				if ( '' === $invoice_country ) {
					$errors->add( 'invoice_country', 'Please give the billing country.' );
				} elseif ( $invoice_country !== $stored_country ) {
					$country_error = law_registration_validate_country( $invoice_country, true );
					if ( $country_error ) {
						$errors->add( 'invoice_country', 'Please choose a country from the list.' );
					}
				}
			}
		}
		// Repeater rows. The groups themselves are optional (their Gravity Forms
		// nested-form fields 112, 106, 94 and 115 are not required), but a row
		// that has been started must be complete, exactly as the nested forms 8
		// (Event > speaker), 6 (Event > co-owner), 4 (Event > host contact) and
		// 9 (Event > session) required their own fields.
		$row_rules = array(
			'speakers'  => array( 'label' => 'speaker', 'fields' => array( 'first_name' => 'a first name', 'last_name' => 'a last name', 'email' => 'an email address', 'organisation' => 'an organisation', 'job_title' => 'a job title' ) ),
			'co_owners' => array( 'label' => 'additional event owner', 'fields' => array( 'name' => 'a name', 'organisation' => 'an organisation', 'email' => 'an email address' ) ),
			'contacts'  => array( 'label' => 'event contact', 'fields' => array( 'name' => 'a name', 'organisation' => 'an organisation', 'email' => 'an email address' ) ),
			'sessions'  => array( 'label' => 'session', 'fields' => array( 'title' => 'a title', 'start' => 'a start time', 'description' => 'a description' ) ),
		);
		foreach ( $row_rules as $group => $rule ) {
			foreach ( (array) ( $input[ $group ] ?? array() ) as $i => $row ) {
				// Machinery, not host input: photo_id is a display-only echo of the
				// stored photo, speaker_id is the speaker post being edited and id is
				// the session's post ID. None of them makes an emptied row count as
				// started, or clearing a row to delete it would fail validation
				// instead.
				$machinery = array( 'photo_id', 'speaker_id', 'id' );
				$filled    = is_array( $row ) ? array_filter( $row, function ( $v, $k ) use ( $machinery ) { return ! in_array( $k, $machinery, true ) && is_scalar( $v ) && '' !== trim( (string) $v ); }, ARRAY_FILTER_USE_BOTH ) : array();
				if ( ! $filled ) {
					continue; // Untouched row: dropped on save, so nothing to require.
				}
				$missing = array();
				foreach ( $rule['fields'] as $key => $description_of ) {
					if ( '' === trim( (string) ( $row[ $key ] ?? '' ) ) ) {
						$missing[] = $description_of;
					}
				}
				if ( $missing ) {
					$errors->add(
						$group,
						sprintf(
							'Each %s needs %s. Please complete row %d, or clear it.',
							$rule['label'],
							law_events_list_words( $missing ),
							(int) $i + 1
						)
					);
				}
			}
		}
		if ( $is_new || 'law-draft' === ( $post->post_status ?? '' ) ) {
			if ( empty( $input['terms'] ) ) {
				$errors->add( 'terms', 'Please accept the terms and conditions.' );
			}
		}
	}

	// Speaker photo uploads validated up front so a bad file blocks nothing else.
	$photo_errors = law_events_validate_photos( $files );
	foreach ( $photo_errors as $code => $message ) {
		$errors->add( $code, $message );
	}

	if ( $errors->has_errors() ) {
		return $errors;
	}

	// Create or update the post itself.
	$postarr = array(
		'post_type'   => LAW_EVENT_CPT,
		'post_status' => $post ? $post->post_status : 'law-draft',
	);
	if ( $post ) {
		$postarr['ID'] = $post->ID;
	} else {
		$postarr['post_author'] = $user_id;
	}
	if ( ! in_array( 'title', $locked, true ) && '' !== $title ) {
		$postarr['post_title'] = $title;
	}
	$postarr['post_content'] = $description;

	// An update MUST go through wp_update_post(), which reads the existing row
	// and merges these keys over it. wp_insert_post() fills every OMITTED key
	// from its own defaults before it even works out that it is an update —
	// post_author from get_current_user_id(), post_date from "now", post_title
	// from '' — and never restores the stored values. Passing this partial
	// array straight to wp_insert_post() therefore handed the event to whoever
	// saved it (a committee member editing from the dashboard silently became
	// the host, and law_user_can_manage_event() locked the real host out), reset
	// the publish date, and blanked a locked title — which made the event vanish
	// everywhere, since law_events_map_post() treats an empty title as absent.
	// Do not "simplify" this back to a single wp_insert_post() call.
	$event_id = $post
		? wp_update_post( wp_slash( $postarr ), true )
		: wp_insert_post( wp_slash( $postarr ), true );
	if ( is_wp_error( $event_id ) ) {
		return $event_id;
	}

	// Taxonomies.
	if ( ! in_array( 'type', $locked, true ) ) {
		law_events_set_terms_by_name( $event_id, 'law_event_type', array( (string) ( $input['event_type'] ?? '' ) ) );
	}
	if ( ! in_array( 'sectors', $locked, true ) ) {
		law_events_set_terms_by_name( $event_id, 'law_sector', (array) ( $input['sectors'] ?? array() ) );
	}
	// Year-tagged once at creation: editing a 2026 event after the settings
	// roll to 2027 must not re-file it into the new programme year.
	if ( $is_new ) {
		wp_set_object_terms( $event_id, (string) law_events_setting( 'year', 2026 ), 'law_year', false );
	}

	// Plain meta.
	$writes = array();
	if ( ! in_array( 'venue_needed', $locked, true ) ) {
		$writes['_law_venue_needed'] = $input['venue_needed'] ?? '';
	}
	// A hidden field posts nothing, and an absent value must never be read as a
	// cleared one: on an event LAW has placed, the venue, its capacity band and
	// the places available belong to the committee, so a host's save has to
	// leave all three exactly as they are. Judged with the same predicate the
	// form template renders by, so the two cannot disagree about what was asked.
	if ( law_events_venue_details_visible( law_events_venue_needed_value( $post, $locked, $input ), $user_id ) ) {
		$writes['_law_venue']             = $input['venue'] ?? '';
		$writes['_law_tickets_available'] = $input['tickets_available'] ?? '';
		if ( ! in_array( 'venue_capacity', $locked, true ) ) {
			$writes['_law_venue_capacity'] = $input['venue_capacity'] ?? '';
		}
	}
	if ( ! in_array( 'host_organisations', $locked, true ) ) {
		$writes['_law_host_organisations'] = $input['host_organisations'] ?? '';
	}
	if ( ! in_array( 'sectors', $locked, true ) ) {
		$writes['_law_sector_jurisdiction'] = $input['sector_jurisdiction'] ?? '';
		$writes['_law_sector_other']        = $input['sector_other'] ?? '';
	}
	if ( ! in_array( 'preferred_slots', $locked, true ) ) {
		$writes['_law_preferred_slots'] = $preferred_slots;
	}
	if ( ! in_array( 'fee_tier', $locked, true ) ) {
		$writes['_law_fee_tier'] = $input['fee_tier'] ?? '';
	}
	if ( ! in_array( 'invoice', $locked, true ) ) {
		$writes['_law_invoice_name']  = $input['invoice_name'] ?? '';
		$writes['_law_invoice_email'] = $input['invoice_email'] ?? '';
		$writes['_law_vat_number']    = $input['vat_number'] ?? '';
		$writes['_law_invoice_address'] = array(
			'line1'       => $input['invoice_line1'] ?? '',
			'line2'       => $input['invoice_line2'] ?? '',
			'city'        => $input['invoice_city'] ?? '',
			'state'       => $input['invoice_state'] ?? '',
			'postal_code' => $input['invoice_postal_code'] ?? '',
			'country'     => $input['invoice_country'] ?? '',
		);
	}
	foreach ( $writes as $key => $value ) {
		law_event_update_meta( $event_id, $key, $value );
	}

	// Raising the places is the one way capacity opens without a cancellation,
	// so the waitlist is offered them. Not on a brand-new event: nobody can be
	// waiting for one that did not exist a moment ago.
	if ( ! $is_new && function_exists( 'law_event_tickets_changed' ) ) {
		law_event_tickets_changed(
			$event_id,
			$before_tickets,
			(int) law_event_meta( $event_id, '_law_tickets_available' ),
			$user_id,
			law_user_is_committee( $user_id ) ? 'committee_form' : 'host_form'
		);
	}

	// Country → ISO, for Stripe's address[country].
	$country = (string) ( $input['invoice_country'] ?? '' );
	if ( '' !== $country ) {
		law_event_update_meta( $event_id, '_law_country_iso', law_events_country_to_iso( $country ) );
	}

	// Terms consent (recorded once, kept forever).
	if ( ! empty( $input['terms'] ) && empty( law_event_meta( $event_id, '_law_terms_consent' )['accepted'] ) ) {
		law_event_update_meta( $event_id, '_law_terms_consent', array( 'accepted' => 1, 'at' => current_time( 'mysql' ) ) );
	}

	// Repeaters.
	// Raw rows go straight to the schema: the 'people_rows' sanitiser in
	// law_event_update_meta() cleans name/organisation/email and drops empty
	// rows, so a separate pre-parser here only risked sanitising differently.
	law_event_update_meta( $event_id, '_law_co_owner_rows', $input['co_owners'] ?? array() );
	law_event_update_meta( $event_id, '_law_contacts', $input['contacts'] ?? array() );
	// The speakers the event held BEFORE this save. law_events_form_save_speakers()
	// is about to replace them wholesale, and the session saver needs the
	// difference: a speaker who WAS on the event and is not any more was
	// deliberately removed by the host, so they must leave the sessions too,
	// whereas one who was NEVER on the event (attached to a session on its own,
	// from the wp-admin session screen) was never offered in the host's picker
	// and must not be destroyed by a host who could not see it.
	$speakers_before = array_map(
		fn( $row ) => (int) $row['speaker_id'],
		law_event_meta( $event_id, '_law_speakers' )
	);
	$speaker_ids = law_events_form_save_speakers( $event_id, (array) ( $input['speakers'] ?? array() ), $files );
	// Sessions are only reconciled when the Session agenda section was actually
	// on the form. Both conditions are load bearing and for different reasons:
	// $has_agenda is the authorisation check (without it a forged sentinel would
	// write sessions onto an event the committee never opted in), and the
	// sentinel distinguishes "the section was not rendered" from "the host
	// cleared every row" — law_events_form_save_sessions() deletes whatever the
	// posted rows do not claim, so an absent section would otherwise wipe the
	// agenda on the next save of any other field.
	if ( $has_agenda && ! empty( $input['law_sessions_present'] ) ) {
		law_events_form_save_sessions( $event_id, (array) ( $input['sessions'] ?? array() ), $speaker_ids, $speakers_before );
	} elseif ( ! empty( $input['law_sessions_present'] ) ) {
		// The section was on the form when it was opened but the gate has closed
		// since (the committee unticked the box while this form was open; the
		// committee handler does not take the edit lock). Never silent: the saver
		// itself logs failures rather than reporting a success it did not achieve.
		$discarded = 0;
		foreach ( (array) ( $input['sessions'] ?? array() ) as $row ) {
			if ( is_array( $row ) && ( '' !== trim( (string) ( $row['title'] ?? '' ) ) || '' !== trim( (string) ( $row['description'] ?? '' ) ) ) ) {
				$discarded++;
			}
		}
		if ( $discarded ) {
			law_event_log(
				$event_id,
				sprintf(
					_n(
						'%d session row was discarded on save: the session agenda has been switched off for this event.',
						'%d session rows were discarded on save: the session agenda has been switched off for this event.',
						$discarded,
						'law'
					),
					$discarded
				),
				array( 'action' => 'sessions_discarded', 'source' => 'form', 'rows' => $discarded ),
				array( 'user_id' => (int) $user_id )
			);
		}
	}

	// If the event is already approved/published and this saver is a host,
	// the committee hears about the edit (host-only alert: staff edits are
	// quiet, but they do get a log line — the activity log records everything).
	if ( $post && law_user_is_committee( $user_id ) ) {
		law_event_log(
			$event_id,
			'Event details updated by the committee.',
			array( 'action' => 'committee_edit', 'source' => 'committee_form' ),
			array( 'user_id' => $user_id )
		);
		if ( in_array( $post->post_status, array( 'law-approved', 'publish' ), true ) ) {
			// Newly added co-owners on an approved event get accounts straight away.
			law_event_ensure_co_owner_users( $event_id, $user_id );
		}
	} elseif ( $post && in_array( $post->post_status, array( 'law-approved', 'publish' ), true ) ) {
		law_event_log(
			$event_id,
			'Event updated by the host after approval.',
			array( 'action' => 'host_edit', 'source' => 'host_form' ),
			array( 'user_id' => $user_id )
		);
		law_events_send( 'committee_event_updated', $event_id );
		law_events_send( 'squareeye_event_updated', $event_id );
		// Newly added co-owners on an approved event get accounts straight away.
		law_event_ensure_co_owner_users( $event_id, $user_id );
	}

	return (int) $event_id;
}

/** "a, b and c" for a validation message listing what a row is missing. */
function law_events_list_words( array $words ) {
	if ( count( $words ) < 2 ) {
		return (string) reset( $words );
	}
	$last = array_pop( $words );
	return implode( ', ', $words ) . ' and ' . $last;
}

/* Photo uploads: one source of truth for the limits ________________________
 *
 * These three numbers and the format list are quoted back to the host in the
 * help text under every upload control, and enforced in three more places:
 * this validator, the wp_handle_upload() override in
 * law_events_sideload_upload(), and the accept attribute on the two file
 * inputs. Written out by hand in each, the help text drifts away from what is
 * actually enforced the first time one of them changes, which is how the
 * pixel bounds came to be enforced but never mentioned. Everything below
 * derives from these.
 */
const LAW_PHOTO_MAX_BYTES = 5 * MB_IN_BYTES;
const LAW_PHOTO_MIN_PX    = 50;
const LAW_PHOTO_MAX_PX    = 6000;

/** The MIME types a speaker photo may really be, as sniffed from its content. */
function law_events_photo_mimes() {
	return array( 'image/jpeg', 'image/png', 'image/webp' );
}

/** The same list in wp_handle_upload()'s extension => MIME override shape. */
function law_events_photo_upload_mimes() {
	return array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' );
}

/**
 * The accept attribute for a photo input. Both the extensions and the MIME
 * types: an extension-only list is unreliable in some mobile file pickers,
 * which offer nothing at all rather than falling back to everything.
 */
function law_events_photo_accept() {
	return '.jpg,.jpeg,.png,.webp,' . implode( ',', law_events_photo_mimes() );
}

/**
 * The one sentence of guidance shown under every photo upload control
 * (Denis, 11 September 2026). Built from the constants above, so the help
 * text cannot promise a limit the validator does not enforce.
 */
function law_events_photo_hint() {
	return sprintf(
		'JPG, PNG or WebP, up to %1$d MB, between %2$d×%2$d and %3$d×%3$d pixels.',
		LAW_PHOTO_MAX_BYTES / MB_IN_BYTES,
		LAW_PHOTO_MIN_PX,
		LAW_PHOTO_MAX_PX
	);
}

/** Photo upload validation: images only, 5 MB cap. */
function law_events_validate_photos( array $files ) {
	$errors  = array();
	$allowed = law_events_photo_mimes();
	$batch   = $files['speaker_photo'] ?? null;
	if ( ! $batch || ! is_array( $batch['name'] ?? null ) ) {
		return $errors;
	}
	$too_large = sprintf( 'is too large (%d MB maximum).', LAW_PHOTO_MAX_BYTES / MB_IN_BYTES );
	foreach ( $batch['name'] as $i => $name ) {
		if ( '' === (string) $name ) {
			continue;
		}
		// PHP rejected the file before we ever saw it, because the server's own
		// upload_max_filesize/MAX_FILE_SIZE is tighter than our cap. The size
		// arrives as 0 and the tmp path empty, so without this branch the size
		// check passes, the MIME sniff fails on nothing, and the host is told
		// their photo "must be a JPG, PNG or WebP image" for what is really a
		// server limit.
		$error = (int) ( $batch['error'][ $i ] ?? UPLOAD_ERR_OK );
		if ( UPLOAD_ERR_INI_SIZE === $error || UPLOAD_ERR_FORM_SIZE === $error ) {
			$errors[ 'speaker_photo_' . $i ] = sprintf( 'Speaker photo "%s" %s', $name, $too_large );
			continue;
		}
		if ( ( $batch['size'][ $i ] ?? 0 ) > LAW_PHOTO_MAX_BYTES ) {
			$errors[ 'speaker_photo_' . $i ] = sprintf( 'Speaker photo "%s" %s', $name, $too_large );
			continue;
		}
		$check = wp_check_filetype_and_ext( $batch['tmp_name'][ $i ] ?? '', (string) $name );
		if ( empty( $check['type'] ) || ! in_array( $check['type'], $allowed, true ) ) {
			$errors[ 'speaker_photo_' . $i ] = sprintf( 'Speaker photo "%s" must be a JPG, PNG or WebP image.', $name );
			continue;
		}
		// Dimension bounds: rejects pixel-flood/decompression-bomb images
		// before any thumbnail generation runs (§3.11 upload validation).
		$dimensions = @getimagesize( (string) ( $batch['tmp_name'][ $i ] ?? '' ) );
		if ( ! $dimensions
			|| $dimensions[0] < LAW_PHOTO_MIN_PX || $dimensions[1] < LAW_PHOTO_MIN_PX
			|| $dimensions[0] > LAW_PHOTO_MAX_PX || $dimensions[1] > LAW_PHOTO_MAX_PX ) {
			$errors[ 'speaker_photo_' . $i ] = sprintf(
				'Speaker photo "%s" must be between %d×%d and %d×%d pixels.',
				$name,
				LAW_PHOTO_MIN_PX,
				LAW_PHOTO_MIN_PX,
				LAW_PHOTO_MAX_PX,
				LAW_PHOTO_MAX_PX
			);
		}
	}
	return $errors;
}

/**
 * Speaker rows: match-or-create law_speaker posts (dedupe by email then
 * name, §3.2) and store the appearance rows on the event. The organisation,
 * job title, photo and biography the host typed are THIS event's values and go
 * on the row; the speaker post keeps only identity, plus a fallback photo and
 * biography for rows that carry none. A re-save without a fresh upload keeps
 * the photo already on this event's row for the same speaker.
 *
 * @return array<int,int> Posted row index => speaker post ID, so the session
 *                        saver can resolve a session's ticks to real speakers
 *                        without going through their names.
 */
function law_events_form_save_speakers( $event_id, array $rows, array $files ) {
	$resolved = array(); // Posted row index => speaker post ID.
	$previous = array(); // speaker_id => photo_id already on this event.
	$owned    = array(); // speaker_id => true, the records this event already holds.
	foreach ( law_event_meta( $event_id, '_law_speakers' ) as $old ) {
		$owned[ (int) $old['speaker_id'] ] = true;
		if ( ! empty( $old['photo_id'] ) ) {
			$previous[ (int) $old['speaker_id'] ] = (int) $old['photo_id'];
		}
	}

	$relationships = array();
	$sort          = 0;
	foreach ( $rows as $i => $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		// First and last name are separate inputs (Denis, 9 September 2026); a
		// payload carrying the old single 'name' is split rather than dropped.
		$name = law_speaker_row_name_parts( $row );
		if ( '' === law_speaker_full_name( $name['first'], $name['last'] ) ) {
			continue;
		}

		$photo_id = 0;
		$batch    = $files['speaker_photo'] ?? null;
		if ( $batch && ! empty( $batch['name'][ $i ] ) ) {
			$photo_id = law_events_sideload_upload(
				array(
					'name'     => $batch['name'][ $i ],
					'type'     => $batch['type'][ $i ],
					'tmp_name' => $batch['tmp_name'][ $i ],
					'error'    => $batch['error'][ $i ],
					'size'     => $batch['size'][ $i ],
				)
			);
		}

		// The form round-trips the speaker post ID of every row already on this
		// event, and a posted ID is honoured only when this event genuinely holds
		// it — the same rule the session rows follow, so a forged ID cannot reach
		// an unrelated speaker. Given one, the row EDITS that record: name, email
		// and website are written outright instead of gap-filled, because the
		// form shows them as editable required fields and dropping the change was
		// silent data loss (Denis, 9 September 2026). Every change is logged on
		// the event, since the profile is shared across events. A brand-new row
		// carries no ID and keeps the old match-or-create, backfill-only rule.
		$posted_speaker = (int) ( $row['speaker_id'] ?? 0 );
		$speaker_id     = law_speaker_upsert(
			array(
				'first_name' => $name['first'],
				'last_name'  => $name['last'],
				'email'      => (string) ( $row['email'] ?? '' ),
				'website'    => (string) ( $row['website'] ?? '' ),
				'bio'        => (string) ( $row['bio'] ?? '' ),
				'photo_id'   => $photo_id, // Fallback featured image only, set once.
			),
			array( 'event_id' => (int) $event_id, 'actor' => get_current_user_id() ),
			isset( $owned[ $posted_speaker ] )
				? array( 'speaker_id' => $posted_speaker, 'overwrite_identity' => true )
				: array()
		);
		if ( $speaker_id ) {
			$relationships[] = array(
				'speaker_id'   => $speaker_id,
				'role'         => (string) ( $row['role'] ?? '' ), // Canonicalised by the schema sanitiser.
				'organisation' => (string) ( $row['organisation'] ?? '' ),
				'job_title'    => (string) ( $row['job_title'] ?? '' ),
				'photo_id'     => $photo_id ?: (int) ( $previous[ $speaker_id ] ?? 0 ),
				'bio'          => (string) ( $row['bio'] ?? '' ),
				'sort'         => $sort++,
			);
			$resolved[ (int) $i ] = (int) $speaker_id;
		}
	}
	law_event_update_meta( $event_id, '_law_speakers', $relationships );
	return $resolved;
}

/** One validated file → attachment ID (hosts have no upload_files cap; the module validates instead). */
function law_events_sideload_upload( array $file ) {
	if ( ! empty( $file['error'] ) || empty( $file['tmp_name'] ) ) {
		return 0;
	}
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$overrides = array(
		'test_form' => false,
		'mimes'     => law_events_photo_upload_mimes(),
	);
	$moved = wp_handle_upload( $file, $overrides );
	if ( ! is_array( $moved ) || ! empty( $moved['error'] ) ) {
		return 0;
	}

	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $moved['type'],
			'post_title'     => sanitize_file_name( $file['name'] ),
			'post_status'    => 'inherit',
		),
		$moved['file']
	);
	if ( $attachment_id ) {
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $moved['file'] ) );
	}
	return (int) $attachment_id;
}

/**
 * Session rows: reconcile the event's law_session children with the submitted
 * set.
 *
 * An UPSERT, not a wipe-and-rebuild: the form round-trips each session's post
 * ID, so an edited session keeps its ID and its meta (`_law_gf_entry_id`
 * included, which is how the migrator knows a session has already been
 * migrated), and only rows the host actually removed are deleted. A posted ID
 * is honoured only when this event already owns it, so a forged ID cannot
 * re-parent another event's session; anything else inserts a new one.
 *
 * @param int        $speaker_ids     Posted speaker row index => speaker post
 *                                    ID, from law_events_form_save_speakers().
 *                                    The picker posts "row:<index>", so a tick
 *                                    resolves through this rather than through
 *                                    a name (see the speaker block below).
 * @param int[]      $speakers_before Speaker IDs the event held before this
 *                                    save, which is what distinguishes a
 *                                    speaker the host just removed from one the
 *                                    host was never shown.
 */
function law_events_form_save_sessions( $event_id, array $rows, array $speaker_ids = array(), array $speakers_before = array() ) {
	$event_id = (int) $event_id;
	$owned    = array_map( 'intval', law_event_session_ids( $event_id ) );
	$kept     = array();
	// menu_order carries the host's row order, which is the tie-break when two
	// sessions start at the same time (parallel tracks). Post IDs cannot do that
	// job any more now that an edited session keeps its ID.
	$position = 0;

	$event_speakers = law_event_meta( $event_id, '_law_speakers' );

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$title = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
		$desc  = law_rich_text_sanitize( $row['description'] ?? '' );
		if ( '' === $title && '' === $desc ) {
			continue;
		}

		$posted_id  = absint( $row['id'] ?? 0 );
		$session_id = ( $posted_id && in_array( $posted_id, $owned, true ) && ! in_array( $posted_id, $kept, true ) )
			? $posted_id
			: 0;

		if ( $session_id ) {
			// Claimed BEFORE the write, not after it succeeds: a row that named an
			// existing session is never a removal, so a failed update must cost the
			// host their edit and not the session itself to the sweep below.
			$kept[]  = $session_id;
			$updated = wp_update_post(
				array(
					'ID'           => $session_id,
					'post_title'   => $title,
					'post_content' => $desc,
					'menu_order'   => $position++,
				),
				true
			);
			if ( is_wp_error( $updated ) ) {
				// Never silent: the host is redirected as though the save worked.
				law_event_log(
					$event_id,
					sprintf( 'Could not save the changes to session "%s". The session is unchanged.', $title ),
					array(
						'action'     => 'session_save_failed',
						'source'     => 'form',
						'session_id' => $session_id,
						'error'      => $updated->get_error_message(),
					)
				);
				continue;
			}
		} else {
			$session_id = wp_insert_post(
				array(
					'post_type'    => LAW_SESSION_CPT,
					'post_status'  => 'publish',
					'post_parent'  => $event_id,
					'post_title'   => $title,
					'post_content' => $desc,
					'menu_order'   => $position++,
				)
			);
			if ( ! $session_id || is_wp_error( $session_id ) ) {
				continue;
			}
			$session_id = (int) $session_id;
			$kept[]     = $session_id;
		}
		law_event_update_meta( $session_id, '_law_start_time', $row['start'] ?? '' );
		law_event_update_meta( $session_id, '_law_end_time', $row['end'] ?? '' );

		// The session's chosen speakers. The picker posts "row:<index>", naming a
		// SPEAKER ROW of this same submission rather than a name, because the name
		// is the one thing about a speaker a host can change in the very save that
		// is being resolved. A plain string is still accepted (a legacy
		// comma-separated value, or a hand-made POST) and matched by name.
		$submitted = $row['speakers'] ?? '';
		$wanted    = is_array( $submitted )
			? array_filter( array_map( 'trim', array_map( 'strval', $submitted ) ) )
			: array_filter( array_map( 'trim', explode( ',', (string) $submitted ) ) );

		// speaker_id => the event's appearance row, so a resolved tick can copy
		// the event's role/organisation/job title/photo/biography onto the session.
		$by_id = array();
		foreach ( $event_speakers as $relationship ) {
			$by_id[ (int) $relationship['speaker_id'] ] = $relationship;
		}

		$linked = array();
		$seen   = array();
		foreach ( $wanted as $tick ) {
			$speaker_id = 0;
			if ( preg_match( '/^row:(\d+)$/', $tick, $match ) ) {
				$speaker_id = (int) ( $speaker_ids[ (int) $match[1] ] ?? 0 );
			} else {
				foreach ( $event_speakers as $relationship ) {
					if ( law_speaker_normalise_name( law_speaker_raw_name( $relationship['speaker_id'] ) ) === law_speaker_normalise_name( $tick ) ) {
						$speaker_id = (int) $relationship['speaker_id'];
						break;
					}
				}
			}
			// Only a speaker this event actually holds, and only once: a forged
			// index or a stale name reaches nothing.
			if ( ! $speaker_id || ! isset( $by_id[ $speaker_id ] ) || isset( $seen[ $speaker_id ] ) ) {
				continue;
			}
			$seen[ $speaker_id ] = true;
			$relationship        = $by_id[ $speaker_id ];
			// The session row carries the event's appearance details too, the
			// role included: the host form has no per-session role control.
			$linked[] = array(
				'speaker_id'   => $speaker_id,
				'role'         => (string) ( $relationship['role'] ?? '' ),
				'organisation' => (string) ( $relationship['organisation'] ?? '' ),
				'job_title'    => (string) ( $relationship['job_title'] ?? '' ),
				'photo_id'     => (int) ( $relationship['photo_id'] ?? 0 ),
				'bio'          => (string) ( $relationship['bio'] ?? '' ),
				'sort'         => count( $linked ),
			);
		}

		// Anything already on the session whose speaker this event has NEVER held
		// is carried over untouched. The wp-admin session screen can attach any
		// speaker in the site, with no requirement that they be on the parent
		// event, so those links are invisible in the host's picker — and wiping
		// what a host was never shown is not a save, it is data loss. A speaker
		// the host genuinely removed from the event IS in $speakers_before, so
		// they still drop out of every session, which is the behaviour that keeps
		// the agenda honest.
		foreach ( law_event_meta( $session_id, '_law_speakers' ) as $existing ) {
			$existing_id = (int) ( $existing['speaker_id'] ?? 0 );
			if ( ! $existing_id || isset( $seen[ $existing_id ] ) || isset( $by_id[ $existing_id ] )
				|| in_array( $existing_id, $speakers_before, true ) ) {
				continue;
			}
			$seen[ $existing_id ] = true;
			$existing['sort']     = count( $linked );
			$linked[]             = $existing;
		}

		law_event_update_meta( $session_id, '_law_speakers', $linked );
	}

	// Whatever the host removed from the repeater. $kept holds every session a
	// row claimed, successful write or not, so only genuine removals reach here.
	foreach ( array_diff( $owned, $kept ) as $orphan ) {
		wp_delete_post( $orphan, true );
	}
}

/* The admin-post handler ____________________________________________________ */

add_action( 'admin_post_law_event_form', 'law_events_form_handler' );
add_action( 'admin_post_nopriv_law_event_form', function () {
	wp_safe_redirect( wp_login_url( home_url( '/account/events/submit/' ) ) );
	exit;
} );

function law_events_form_handler() {
	check_admin_referer( 'law_event_form' );

	$user_id = get_current_user_id();
	if ( ! law_events_user_can_submit( $user_id ) ) {
		wp_die( 'Sorry, you are not allowed to submit events.' );
	}

	// Honeypot: bots fill it, people never see it.
	if ( '' !== trim( (string) ( $_POST['law_website_url'] ?? '' ) ) ) {
		wp_safe_redirect( home_url( '/account/events/' ) );
		exit;
	}
	if ( ! law_events_rate_limit_ok( 'submit', $user_id, 15, 600 ) ) {
		wp_die( 'Too many submissions in a short time. Please wait a few minutes and try again.' );
	}

	// The committee edit view posts law_form_context=committee so failures and
	// the success redirect return to the dashboard, not the host template. A
	// host spoofing the field fails the capability check and stays on the host
	// routes; both targets are home_url()-built, so this is never an open redirect.
	$is_committee_ctx = 'committee' === sanitize_key( $_POST['law_form_context'] ?? '' ) && law_user_is_committee( $user_id );

	$event_id = absint( $_POST['law_event_id'] ?? 0 );
	$post     = null;
	if ( $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post || LAW_EVENT_CPT !== $post->post_type || ! law_user_can_manage_event( $user_id, $event_id ) ) {
			wp_die( 'Sorry, you are not allowed to edit this event.' );
		}
		// Cancelled and rejected events are read-only: the form renders no save
		// buttons on them, so a POST here is hand-made or stale. The message
		// thread stays open; only edits are refused.
		if ( in_array( $post->post_status, array( 'law-cancelled', 'law-rejected' ), true ) ) {
			law_events_redirect_back( array( 'law_notice' => 'event-not-editable' ) );
		}
		// Edit locking (replacing GravityView entry locking): refuse the save
		// when someone else holds the lock, then take it.
		$locked_by = law_event_lock_holder( $event_id );
		if ( $locked_by ) {
			$editor = get_user_by( 'id', (int) $locked_by );
			set_transient(
				'law_form_state_' . $user_id,
				array( 'errors' => array( 'locked' => array( sprintf( 'This event is currently being edited by %s. Your changes were not saved; try again shortly.', $editor ? $editor->display_name : 'another user' ) ) ), 'input' => array() ),
				10 * MINUTE_IN_SECONDS
			);
			wp_safe_redirect( $is_committee_ctx
				? add_query_arg( array( 'event' => $event_id, 'law_edit' => 1, 'law_form_error' => 1 ), home_url( '/account/dashboard/' ) )
				: add_query_arg( array( 'law_event' => $event_id, 'law_form_error' => 1 ), law_account_events_submit_url() ) );
			exit;
		}
		law_event_lock_take( $event_id );
	}

	$input  = wp_unslash( $_POST );
	$result = law_events_form_save( $input, $_FILES, $post, $user_id );

	if ( is_wp_error( $result ) ) {
		set_transient(
			'law_form_state_' . $user_id,
			array( 'errors' => $result->errors, 'input' => law_events_form_reusable_input( $input ) ),
			10 * MINUTE_IN_SECONDS
		);
		if ( $is_committee_ctx && $event_id ) {
			$back = add_query_arg( array( 'event' => $event_id, 'law_edit' => 1 ), home_url( '/account/dashboard/' ) );
		} else {
			$back = $event_id
				? add_query_arg( 'law_event', $event_id, law_account_events_submit_url() )
				: law_account_events_submit_url();
		}
		wp_safe_redirect( add_query_arg( 'law_form_error', 1, $back ) );
		exit;
	}

	$saved_id = (int) $result;
	$action   = sanitize_key( $input['law_form_action'] ?? 'submit' );

	// The save is done and the saver is being redirected away, so drop the lock
	// rather than making the next person wait out the window. A validation
	// failure above deliberately does NOT release it: that path returns to the
	// form, which re-renders and takes the lock again.
	if ( $event_id ) {
		law_event_lock_release( $event_id );
	}

	wp_safe_redirect( law_events_form_result_redirect( $saved_id, $action, $user_id, $is_committee_ctx ) );
	exit;
}

/**
 * Post-save transitions and the redirect target for one successful save.
 *
 * The resubmit transition fires ONLY on the host form's explicit
 * "Save & resubmit" button (law_form_action=submit) — never on a plain
 * update. Before this gate, ANY save of a law-sent-back event resubmitted
 * it to the committee, so a committee member editing details would have
 * resubmitted as though the host had acted. The committee edit form posts
 * 'update' and additionally passes $committee_context, so it can never
 * resubmit even if its action value drifts.
 *
 * @param bool $committee_context True only when law_form_context=committee
 *                                was posted AND the saver is committee
 *                                (validated by the handler).
 */
function law_events_form_result_redirect( $saved_id, $action, $user_id, $committee_context = false ) {
	$status = get_post_status( $saved_id );

	if ( 'draft' === $action ) {
		return add_query_arg( array( 'law_event' => $saved_id, 'law_notice' => 'draft-saved' ), law_account_events_submit_url() );
	}

	if ( 'law-draft' === $status ) {
		law_event_workflow_transition( $saved_id, 'submit', array( 'actor_id' => $user_id ) );
		// The page 372 confirmation, exactly like the old form 2 confirmation.
		$done = get_page_by_path( 'account/events/submit/done' );
		return $done ? get_permalink( $done ) : home_url( '/account/events/' );
	}

	if ( 'law-sent-back' === $status && 'submit' === $action && ! $committee_context ) {
		law_event_workflow_transition( $saved_id, 'resubmit', array( 'actor_id' => $user_id ) );
	}

	if ( $committee_context ) {
		return add_query_arg( array( 'event' => $saved_id, 'law_notice' => 'saved' ), home_url( '/account/dashboard/' ) );
	}

	return add_query_arg( array( 'law_notice' => 'event-updated' ), home_url( '/account/events/' ) );
}

/** Strip files/nonces so the re-render transient stays small and safe. */
function law_events_form_reusable_input( array $input ) {
	unset( $input['_wpnonce'], $input['_wp_http_referer'], $input['action'], $input['law_website_url'] );
	return $input;
}

/** Errors + previous input after a failed validation, once. */
function law_events_form_state() {
	$state = get_transient( 'law_form_state_' . get_current_user_id() );
	if ( $state ) {
		delete_transient( 'law_form_state_' . get_current_user_id() );
	}
	return is_array( $state ) ? $state : array( 'errors' => array(), 'input' => array() );
}

/**
 * Current values for the form template: previous input on error, else the
 * stored event, else blanks.
 *
 * @param WP_Post|null $post  Event being edited.
 * @param array        $state law_events_form_state().
 */
function law_events_form_values( $post, array $state ) {
	if ( ! empty( $state['input'] ) ) {
		return $state['input'];
	}
	if ( ! $post ) {
		return array();
	}

	$address  = law_event_meta( $post->ID, '_law_invoice_address' );
	$speakers = array();
	foreach ( law_event_meta( $post->ID, '_law_speakers' ) as $row ) {
		$speaker_id = (int) $row['speaker_id'];
		// Role, organisation, job title, photo and biography are this event's own
		// (the appearance row); name, email and website are the person's. The
		// biography falls back to the speaker post's editor content for rows saved
		// before biographies became per appearance.
		$row_bio      = trim( (string) ( $row['bio'] ?? '' ) );
		$speaker_name = law_speaker_name_parts( $speaker_id );
		$speakers[]   = array(
			// Round-tripped so a re-save edits THIS record rather than re-matching
			// on the very fields the host may have just corrected.
			'speaker_id'   => $speaker_id,
			// 'name' is what the previews and the session picker print; the parts
			// are what the form's own First/Last name inputs are prefilled from.
			'name'         => law_speaker_raw_name( $speaker_id ),
			'first_name'   => $speaker_name['first'],
			'last_name'    => $speaker_name['last'],
			'role'         => law_speaker_role_key( $row['role'] ?? '' ),
			'email'        => (string) law_event_meta( $speaker_id, '_law_speaker_email' ),
			'organisation' => (string) ( $row['organisation'] ?? '' ),
			'job_title'    => (string) ( $row['job_title'] ?? '' ),
			'website'      => (string) law_event_meta( $speaker_id, '_law_website' ),
			'bio'          => '' !== $row_bio ? $row_bio : (string) get_post_field( 'post_content', $speaker_id ),
			'photo_id'     => (int) ( $row['photo_id'] ?? 0 ),
		);
	}
	$sessions = array();
	foreach ( law_event_session_rows( $post->ID ) as $session ) {
		$sessions[] = array(
			'id'          => (int) $session['id'],
			'title'       => $session['title'],
			'start'       => $session['start'],
			'end'         => $session['end'],
			'description' => $session['description'],
			'speakers'    => implode( ', ', wp_list_pluck( $session['speakers'], 'name' ) ),
		);
	}

	return array(
		'event_title'         => $post->post_title,
		'event_type'          => law_events_post_term_name( $post->ID, 'law_event_type' ),
		'host_organisations'  => law_event_meta( $post->ID, '_law_host_organisations' ),
		'preferred_slots'     => law_event_meta( $post->ID, '_law_preferred_slots' ),
		'description'         => $post->post_content,
		'sectors'             => law_events_post_term_names( $post->ID, 'law_sector' ),
		'sector_jurisdiction' => law_event_meta( $post->ID, '_law_sector_jurisdiction' ),
		'sector_other'        => law_event_meta( $post->ID, '_law_sector_other' ),
		'venue_needed'        => law_event_meta( $post->ID, '_law_venue_needed' ),
		'venue'               => law_event_meta( $post->ID, '_law_venue' ),
		'venue_capacity'      => law_event_meta( $post->ID, '_law_venue_capacity' ),
		'tickets_available'   => law_event_meta( $post->ID, '_law_tickets_available' ),
		'fee_tier'            => law_event_meta( $post->ID, '_law_fee_tier' ),
		'invoice_name'        => law_event_meta( $post->ID, '_law_invoice_name' ),
		'invoice_email'       => law_event_meta( $post->ID, '_law_invoice_email' ),
		'vat_number'          => law_event_meta( $post->ID, '_law_vat_number' ),
		'invoice_line1'       => $address['line1'] ?? '',
		'invoice_line2'       => $address['line2'] ?? '',
		'invoice_city'        => $address['city'] ?? '',
		'invoice_state'       => $address['state'] ?? '',
		'invoice_postal_code' => $address['postal_code'] ?? '',
		'invoice_country'     => $address['country'] ?? '',
		'terms'               => ! empty( law_event_meta( $post->ID, '_law_terms_consent' )['accepted'] ),
		'co_owners'           => law_event_meta( $post->ID, '_law_co_owner_rows' ),
		'contacts'            => law_event_meta( $post->ID, '_law_contacts' ),
		'speakers'            => $speakers,
		'sessions'            => $sessions,
	);
}

/** Enqueue the form assets only on the form template. */
add_action( 'wp_enqueue_scripts', function () {
	if ( is_page_template( 'templates/account-event-form.php' )
		|| is_page_template( 'templates/account-dashboard.php' )
		|| is_page_template( 'templates/account-bookings-dashboard.php' )
		|| is_page_template( 'templates/account-speakers-dashboard.php' )
		|| is_page_template( 'templates/account-dashboard-flagship.php' )
		|| is_page_template( 'templates/account-dashboard-flagship-bookings.php' )
		|| is_page_template( 'templates/account-dashboard-discounts.php' )
		|| is_page_template( 'templates/account-events.php' )
		|| is_page_template( 'templates/account-bookings.php' )
		|| is_page_template( 'templates/account-profile.php' )
		|| is_page_template( 'templates/register.php' ) ) {
		// filemtime, not a hand-bumped string: the version was going stale on
		// every edit and serving cached CSS.
		wp_enqueue_style( 'law-event-form', get_theme_file_uri( 'assets/css/event-form.css' ), array(), filemtime( get_theme_file_path( 'assets/css/event-form.css' ) ) );
		// Core's zxcvbn-based strength meter powers the WordPress-style
		// password indicator on the register and profile forms.
		$deps = array();
		if ( is_page_template( 'templates/register.php' ) || is_page_template( 'templates/account-profile.php' ) ) {
			$deps[] = 'password-strength-meter';
		}
		// The edit-lock refresh rides core's heartbeat (edit-lock.php). Only a
		// page actually rendering an edit form needs it: the host form, and the
		// committee dashboard's ?event=<id>&law_edit=1 view. Asking for it on
		// the whole dashboard would set every committee member's browser
		// polling admin-ajax for a lock that page never shows.
		if ( is_page_template( 'templates/account-event-form.php' )
			|| ( is_page_template( 'templates/account-dashboard.php' ) && ! empty( $_GET['law_edit'] ) ) ) {
			$deps[] = 'heartbeat';
		}
		wp_enqueue_script( 'law-event-form', get_theme_file_uri( 'assets/js/event-form.js' ), $deps, filemtime( get_theme_file_path( 'assets/js/event-form.js' ) ), true );
		// The limits the browser-side pre-check enforces, from the same helpers
		// as the validator and the help text, so the three can never disagree.
		// wp_add_inline_script rather than wp_localize_script: the latter casts
		// every scalar to a string, and maxBytes is compared with a number.
		wp_add_inline_script(
			'law-event-form',
			'window.lawPhotoLimits = ' . wp_json_encode(
				array(
					'maxBytes' => LAW_PHOTO_MAX_BYTES,
					'mimes'    => law_events_photo_mimes(),
					'tooLarge' => sprintf( 'That photo is too large (%d MB maximum). Choose a smaller file.', LAW_PHOTO_MAX_BYTES / MB_IN_BYTES ),
					'badType'  => 'That file must be a JPG, PNG or WebP image.',
				)
			) . ';',
			'before'
		);

		// The WYSIWYG editor behind the descriptive fields
		// (functions/events/rich-text.php). Only the screens that actually render
		// one: TinyMCE is a couple of hundred kilobytes, and asking for it on the
		// bookings dashboard or the register form would be pure weight.
		if ( is_page_template( 'templates/account-event-form.php' )
			|| ( is_page_template( 'templates/account-dashboard.php' ) && ! empty( $_GET['law_edit'] ) )
			|| ( is_page_template( 'templates/account-speakers-dashboard.php' ) && ! empty( $_GET['law_speaker'] ) ) ) {
			law_rich_text_enqueue();
		}
	}
	// The committee dashboard's confirmation dialogs (parts/layout/modal.php).
	// The partial enqueues these itself, but by then the head is already out,
	// so ask for them here and the stylesheet prints with the rest.
	if ( is_page_template( 'templates/account-dashboard.php' ) ) {
		law_modal_enqueue();
		// The fetch layer over the workflow-action modals: submits the action in
		// the background, shows the busy label, then the success dialog and a
		// delayed reload. Depends on law-modal for the window.lawModal API.
		wp_enqueue_script( 'law-committee-actions', get_theme_file_uri( 'assets/js/committee-actions.js' ), array( 'law-modal' ), filemtime( get_theme_file_path( 'assets/js/committee-actions.js' ) ), true );
	}
} );

/** Set terms by name, creating none: unknown names are dropped, not invented. */
function law_events_set_terms_by_name( $post_id, $taxonomy, array $names ) {
	$ids = array();
	foreach ( $names as $name ) {
		$name = sanitize_text_field( (string) $name );
		if ( '' === $name ) {
			continue;
		}
		$term = get_term_by( 'name', $name, $taxonomy );
		if ( $term ) {
			$ids[] = (int) $term->term_id;
		}
	}
	wp_set_object_terms( $post_id, $ids, $taxonomy, false );
}
