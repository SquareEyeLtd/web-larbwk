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
	$errors = new WP_Error();

	$title       = sanitize_text_field( $input['event_title'] ?? '' );
	$description = wp_kses_post( $input['description'] ?? '' );
	$is_draft    = 'draft' === ( $input['law_form_action'] ?? '' );

	// Even a draft needs a title: an untitled post would be invisible on the
	// host dashboard (and core refuses fully empty posts with a raw error).
	if ( '' === $title && ! in_array( 'title', $locked, true ) ) {
		$errors->add( 'event_title', $is_draft ? 'Please give the event a title before saving a draft.' : 'Please give the event a title.' );
	}
	if ( ! $is_draft ) {
		if ( '' === trim( wp_strip_all_tags( $description ) ) ) {
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
		if ( ! in_array( 'preferred_slots', $locked, true ) && ! array_filter( (array) ( $input['preferred_slots'] ?? array() ) ) ) {
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
		// hosts keep editing ticket allocations WITHIN that band.
		$tickets = trim( (string) ( $input['tickets_available'] ?? '' ) );
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
			'speakers'  => array( 'label' => 'speaker', 'fields' => array( 'name' => 'a name', 'email' => 'an email address', 'organisation' => 'an organisation', 'job_title' => 'a job title' ) ),
			'co_owners' => array( 'label' => 'additional event owner', 'fields' => array( 'name' => 'a name', 'organisation' => 'an organisation', 'email' => 'an email address' ) ),
			'contacts'  => array( 'label' => 'event contact', 'fields' => array( 'name' => 'a name', 'organisation' => 'an organisation', 'email' => 'an email address' ) ),
			'sessions'  => array( 'label' => 'session', 'fields' => array( 'title' => 'a title', 'start' => 'a start time', 'description' => 'a description' ) ),
		);
		foreach ( $row_rules as $group => $rule ) {
			foreach ( (array) ( $input[ $group ] ?? array() ) as $i => $row ) {
				// photo_id is a display-only echo of the stored photo, not host input.
				$filled = is_array( $row ) ? array_filter( $row, function ( $v, $k ) { return 'photo_id' !== $k && is_scalar( $v ) && '' !== trim( (string) $v ); }, ARRAY_FILTER_USE_BOTH ) : array();
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
	} elseif ( $post ) {
		// On update always carry the existing title forward. wp_insert_post()
		// fills any OMITTED key from its defaults (post_title => ''), so leaving
		// the key out when the title is locked silently wipes the title of an
		// approved/confirmed event — and law_events_map_post() treats an empty
		// title as "doesn't exist", making the event vanish from the host and
		// committee dashboards, the programme and its single page at once.
		$postarr['post_title'] = $post->post_title;
	}
	$postarr['post_content'] = $description;

	$event_id = wp_insert_post( wp_slash( $postarr ), true );
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

	// ?ec= category prepopulation (the old dead field 113/116 mechanism,
	// rebuilt): a matching law_event_category term carried in the hidden
	// field is applied on first save only. Committee-managed thereafter.
	$ec = sanitize_text_field( (string) ( $input['law_ec'] ?? '' ) );
	if ( $is_new && '' !== $ec ) {
		$term = get_term_by( 'name', $ec, 'law_event_category' ) ?: get_term_by( 'slug', $ec, 'law_event_category' );
		if ( $term ) {
			wp_set_object_terms( $event_id, array( (int) $term->term_id ), 'law_event_category', false );
		}
	}

	// Plain meta.
	$writes = array(
		'_law_venue'              => $input['venue'] ?? '',
		'_law_tickets_available'  => $input['tickets_available'] ?? '',
	);
	if ( ! in_array( 'venue_needed', $locked, true ) ) {
		$writes['_law_venue_needed'] = $input['venue_needed'] ?? '';
	}
	if ( ! in_array( 'venue_capacity', $locked, true ) ) {
		$writes['_law_venue_capacity'] = $input['venue_capacity'] ?? '';
	}
	if ( ! in_array( 'host_organisations', $locked, true ) ) {
		$writes['_law_host_organisations'] = $input['host_organisations'] ?? '';
	}
	if ( ! in_array( 'sectors', $locked, true ) ) {
		$writes['_law_sector_jurisdiction'] = $input['sector_jurisdiction'] ?? '';
		$writes['_law_sector_other']        = $input['sector_other'] ?? '';
	}
	if ( ! in_array( 'preferred_slots', $locked, true ) ) {
		$writes['_law_preferred_slots'] = (array) ( $input['preferred_slots'] ?? array() );
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
	law_events_form_save_speakers( $event_id, (array) ( $input['speakers'] ?? array() ), $files );
	law_events_form_save_sessions( $event_id, (array) ( $input['sessions'] ?? array() ) );

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

/** Photo upload validation: images only, 5 MB cap. */
function law_events_validate_photos( array $files ) {
	$errors  = array();
	$allowed = array( 'image/jpeg', 'image/png', 'image/webp' );
	$batch   = $files['speaker_photo'] ?? null;
	if ( ! $batch || ! is_array( $batch['name'] ?? null ) ) {
		return $errors;
	}
	foreach ( $batch['name'] as $i => $name ) {
		if ( '' === (string) $name ) {
			continue;
		}
		if ( ( $batch['size'][ $i ] ?? 0 ) > 5 * MB_IN_BYTES ) {
			$errors[ 'speaker_photo_' . $i ] = sprintf( 'Speaker photo "%s" is too large (5 MB maximum).', $name );
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
		if ( ! $dimensions || $dimensions[0] < 50 || $dimensions[1] < 50 || $dimensions[0] > 6000 || $dimensions[1] > 6000 ) {
			$errors[ 'speaker_photo_' . $i ] = sprintf( 'Speaker photo "%s" must be between 50×50 and 6000×6000 pixels.', $name );
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
 */
function law_events_form_save_speakers( $event_id, array $rows, array $files ) {
	$previous = array(); // speaker_id => photo_id already on this event.
	foreach ( law_event_meta( $event_id, '_law_speakers' ) as $old ) {
		if ( ! empty( $old['photo_id'] ) ) {
			$previous[ (int) $old['speaker_id'] ] = (int) $old['photo_id'];
		}
	}

	$relationships = array();
	$sort          = 0;
	foreach ( $rows as $i => $row ) {
		if ( ! is_array( $row ) || '' === trim( (string) ( $row['name'] ?? '' ) ) ) {
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

		$speaker_id = law_speaker_upsert(
			array(
				'name'     => (string) $row['name'],
				'email'    => (string) ( $row['email'] ?? '' ),
				'website'  => (string) ( $row['website'] ?? '' ),
				'bio'      => (string) ( $row['bio'] ?? '' ),
				'photo_id' => $photo_id, // Fallback featured image only, set once.
			),
			array( 'event_id' => (int) $event_id, 'actor' => get_current_user_id() )
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
		}
	}
	law_event_update_meta( $event_id, '_law_speakers', $relationships );
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
		'mimes'     => array( 'jpg|jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp' ),
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
 * Session rows: replace the event's law_session children with the submitted
 * set. Session speakers are matched by name against the event's speakers.
 */
function law_events_form_save_sessions( $event_id, array $rows ) {
	$existing = law_event_session_ids( $event_id );
	foreach ( $existing as $session_id ) {
		wp_delete_post( $session_id, true );
	}

	$event_speakers = law_event_meta( $event_id, '_law_speakers' );

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$title = sanitize_text_field( (string) ( $row['title'] ?? '' ) );
		$desc  = sanitize_textarea_field( (string) ( $row['description'] ?? '' ) );
		if ( '' === $title && '' === $desc ) {
			continue;
		}

		$session_id = wp_insert_post(
			array(
				'post_type'    => LAW_SESSION_CPT,
				'post_status'  => 'publish',
				'post_parent'  => $event_id,
				'post_title'   => $title,
				'post_content' => $desc,
			)
		);
		if ( ! $session_id || is_wp_error( $session_id ) ) {
			continue;
		}
		law_event_update_meta( $session_id, '_law_start_time', $row['start'] ?? '' );
		law_event_update_meta( $session_id, '_law_end_time', $row['end'] ?? '' );

		// The session's chosen speakers, matched to this event's speaker rows by
		// name. The picker posts an array of names taken from those same rows, so
		// a match is guaranteed; the comma-separated string is still accepted for
		// anything saved before the picker replaced the free-text field.
		$submitted = $row['speakers'] ?? '';
		$wanted    = is_array( $submitted )
			? array_filter( array_map( 'trim', array_map( 'strval', $submitted ) ) )
			: array_filter( array_map( 'trim', explode( ',', (string) $submitted ) ) );
		$linked = array();
		foreach ( $wanted as $name ) {
			foreach ( $event_speakers as $relationship ) {
				if ( law_speaker_normalise_name( get_the_title( $relationship['speaker_id'] ) ) === law_speaker_normalise_name( $name ) ) {
					// The session row carries the event's appearance details too, the
					// role included: the host form has no per-session role control.
					$linked[] = array(
						'speaker_id'   => (int) $relationship['speaker_id'],
						'role'         => (string) ( $relationship['role'] ?? '' ),
						'organisation' => (string) ( $relationship['organisation'] ?? '' ),
						'job_title'    => (string) ( $relationship['job_title'] ?? '' ),
						'photo_id'     => (int) ( $relationship['photo_id'] ?? 0 ),
						'bio'          => (string) ( $relationship['bio'] ?? '' ),
						'sort'         => count( $linked ),
					);
					break;
				}
			}
		}
		law_event_update_meta( $session_id, '_law_speakers', $linked );
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
		require_once ABSPATH . 'wp-admin/includes/post.php';
		$locked_by = wp_check_post_lock( $event_id );
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
		wp_set_post_lock( $event_id );
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
		$row_bio    = trim( (string) ( $row['bio'] ?? '' ) );
		$speakers[] = array(
			'name'         => get_the_title( $speaker_id ),
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
		|| is_page_template( 'templates/account-events.php' )
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
		wp_enqueue_script( 'law-event-form', get_theme_file_uri( 'assets/js/event-form.js' ), $deps, filemtime( get_theme_file_path( 'assets/js/event-form.js' ) ), true );
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
