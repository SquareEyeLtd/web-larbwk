<?php
/**
 * Content transfer: the committee's events, receptions, flagship and discount
 * configuration, moved between environments as one JSON file.
 *
 * The client fills the real details in on staging. Production gets the same
 * CODE from a git push, and the theme already provisions the empty RECORDS
 * there (law_flagship_ensure_post(), law_reception_ensure_posts() — one
 * flagship post and three law-draft receptions, all idempotent by slug). What
 * a deploy cannot carry is the committee's configuration: dates, venue,
 * places, prices, the price-switch datetime, the banner image, the publish
 * ticks, the whole flagship session agenda with its speakers, and the discount
 * catalogue. This panel exports exactly that, and imports it again.
 *
 * HOSTED AND EXTERNAL EVENTS JOINED THE BUNDLE ON 16 SEPTEMBER 2026 (format
 * version 3, Denis), reversing the 15 September exclusion. The client had gone
 * on editing the programme itself on staging — venues, descriptions, speakers,
 * running orders, the new Override booking availability switch, and a set of
 * external listings created there from scratch — and none of that had a route
 * to production. The brief was "make sure we migrate all possible data about
 * events", so the `events` key carries every law_event that is not a reception
 * and not the flagship, which are the two kinds that already had keys of their
 * own.
 *
 * Four rules hold the format together, the first three of them consequences of
 * the one fact that makes a cross-site move hard: POST IDS DO NOT SURVIVE IT.
 *
 * 1. Receptions and discount scope are keyed by SLUG, never by ID. The slug is
 *    what law_event_ensure_managed_post() provisions by, so it means the same
 *    thing on both sites. An event is keyed by its GRAVITY FORMS ENTRY ID
 *    first and its slug second (law_content_transfer_find_event()): both sites
 *    migrate from the same Gravity Forms data, so the entry ID is the one
 *    identifier that is the same number on both, and the slug is what an event
 *    created in the module since has instead.
 * 2. Speakers are keyed by IDENTITY, not by ID: each row carries the first
 *    name, last name, email and website inline, is marked is_new, and is
 *    handed to law_flagship_resolve_speaker_rows(), which calls
 *    law_speaker_upsert() — that already dedupes by email first and normalised
 *    name second. So there is no speaker matching logic here at all. People —
 *    an event's owner, its assignee — travel as EMAIL ADDRESSES for the same
 *    reason, and are resolved with get_user_by( 'email' ). An import never
 *    creates a user account (Denis, 16 September 2026): an unresolvable owner
 *    leaves the event with the administrator running the import and the row
 *    says so.
 * 3. Derived values are never exported. _law_start, _law_end and the
 *    event-level _law_speakers union on the flagship are recomputed from the
 *    sessions by law_flagship_recompute(); exporting them would only give the
 *    importer a chance to write something stale. The same rule keeps
 *    _law_fee_pence, _law_vat, _law_tickets_sold and _law_co_owner_ids out of
 *    an event row: each is a snapshot or a recount belonging to the site that
 *    took it.
 * 4. AN IMPORT UPDATES AND CREATES, AND NEVER DELETES (Denis, 16 September
 *    2026). It runs AFTER the Gravity Forms migration, overwrites the events
 *    the bundle names, and leaves everything else on the far site exactly
 *    where it was — an event that exists on production and was never on
 *    staging is not touched, not reset and not removed. There is no "make this
 *    site look like that one" mode and there should not be one.
 *
 * IMAGES TRAVEL INSIDE THE FILE (format version 2, Denis, 15 September 2026).
 * The bundle is a zip: bundle.json plus an images/ folder. Version 1 shipped
 * earlier the same day as plain JSON whose images the far site fetched from the
 * source site's URLs, and that was reversed once LAW staging turned out to sit
 * behind HTTP basic auth — every fetch returned 401, and because a failed fetch
 * is deliberately warn-and-continue, the import reported success while leaving
 * every photo_id at 0. A cross-environment bundle has to be self-contained: no
 * environment here can be assumed reachable from another, or from itself.
 *
 * The URL still travels and is still the identity key (see
 * law_content_transfer_attachment()), so re-import idempotency is unchanged and
 * a version 1 bundle still imports by the old route.
 *
 * And the import writes through the EXISTING savers — law_reception_save(),
 * law_flagship_save(), law_discount_save() — so this file is a caller, never a
 * second write path. That is what keeps the validation, the workflow
 * status-guard exemption, the recompute and the per-event activity log
 * identical to a committee member typing the same values in by hand. A hosted
 * event has no single saver to call (law_events_form_save() is the HOST form's,
 * complete with the locked-field rules that depend on who is posting), so
 * law_content_transfer_run_event() writes through the layer below it instead:
 * law_event_update_meta() for every key — the one sanitiser the admin screens,
 * the front-end forms and the migrator all share — plus
 * law_events_set_terms_by_name(), law_flagship_resolve_speaker_rows() and
 * law_flagship_save_sessions(), which are the same shared repeater savers the
 * host form and the external-events screen call. No sanitiser is reimplemented
 * here.
 *
 * NOT transferred, deliberately: bookings and everything hanging off them.
 * They carry Stripe customer, invoice, charge and payment-method IDs from
 * whatever Stripe account staging points at, plus one-shot idempotency latches
 * that would suppress a genuine confirmation email or charge on production.
 * Also out: the test-mode option, the Stripe tax rate and rendering template
 * (account-specific), every one-shot version latch, and an email's RECIPIENTS
 * (see law_content_transfer_emails() — the wording travels, the addresses
 * do not).
 *
 * TWO THINGS AN IMPORT MAY NOT DECIDE, both because they are acts rather than
 * values: an event's WORKFLOW STATUS and who OWNS it. The status is set out
 * below. Ownership (post_author) is written on a CREATE only, because
 * law_user_can_manage_event() treats the author as a full manager and the same
 * run writes the invoicing contact and address they would then be able to read;
 * a difference on an existing event is reported instead (security review,
 * 16 September 2026). An event's CLASSIFICATION — _law_is_external — is the
 * third, for a reason that only shows when the three are read together: it is
 * what law_event_is_managed_by_law() reads, so it is what unlocks the status
 * guard's exemption, and an import that could write it could unlock the guard on
 * one run and use it on the next.
 *
 * A HOSTED EVENT'S WORKFLOW STATUS IS OUT TOO, and that is the one exclusion
 * worth reading twice (Denis, 16 September 2026). Production's status comes
 * from the Gravity Forms migration, which reads field 95 (Event status) on
 * form 2 (Event > submit an event) — the live workflow record — and an
 * approval is not a value, it is an act: law_event_workflow_side_effects()
 * snapshots the fee, creates the co-owner accounts, raises the Stripe invoice
 * and emails the host. Writing `law-approved` onto a post would produce an
 * approved event with no invoice and no host email, and calling the real
 * transition from an import could email dozens of hosts and raise dozens of
 * live invoices from one button. So the bundle CARRIES the status, the preview
 * REPORTS it where it differs, and the import WRITES it only where there is no
 * workflow behind it: an external event's publish / law-draft tick, which
 * means "on the programme" and "not yet" exactly as a reception's does, and a
 * hosted event being CREATED, where the status is the new post's own and no
 * transition has been skipped because none has happened anywhere.
 *
 * Email wording joined the bundle on 15 September 2026, reversing its original
 * exclusion, because the client had spent time polishing it on staging and the
 * registry in notifications.php only carries the code defaults.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_CONTENT_TRANSFER_FORMAT  = 'law-content-transfer';
const LAW_CONTENT_TRANSFER_VERSION = 3;
/**
 * The biggest upload this will read.
 *
 * Was 2MB while the bundle was text. A zip carries the speaker photographs and
 * the banner, so an agenda with thirty headshots is tens of megabytes. PHP's own
 * upload_max_filesize / post_max_size are the real ceiling on most hosts, and an
 * upload killed by those arrives as UPLOAD_ERR_INI_SIZE, which is reported
 * separately.
 */
const LAW_CONTENT_TRANSFER_MAX_BYTES   = 67108864; // 64MB.
const LAW_CONTENT_TRANSFER_SOURCE_META = '_law_transfer_source_url';

/** The bundle's own name inside the archive. */
const LAW_CONTENT_TRANSFER_MANIFEST = 'bundle.json';

/** The only directory an archive may carry images in. */
const LAW_CONTENT_TRANSFER_IMAGE_DIR = 'images/';

/**
 * How many images one import may take.
 *
 * The prefix check below confines a version 1 bundle's fetches to one host, but
 * nothing confined how MANY: a bundle can name thousands of distinct photo URLs,
 * and an administrator talked into uploading a hostile file could otherwise make
 * this server issue a long burst of outbound requests at a host of somebody
 * else's choosing. It caps a zip's entries for the same reason, one step earlier.
 * Well above any real agenda; a run that hits it says so and carries on.
 */
const LAW_CONTENT_TRANSFER_MAX_IMAGES = 400;

/**
 * How many records of one kind a bundle may carry.
 *
 * The 64MB upload cap and the image cap bound the bytes and the outbound
 * requests; nothing bounded the ROW COUNT until events joined the bundle
 * (security review, 16 September 2026). A run is synchronous inside one
 * admin-post.php request — unlike the module's own migration runner, which
 * batches over AJAX — and every event row costs a lookup, a thirty-key
 * snapshot, a handful of writes, term lookups and an activity-log entry. Tens
 * of thousands of minimal rows would therefore exhaust max_execution_time on an
 * ordinary admin request.
 *
 * Well clear of anything real: LAW's whole programme is about 105 events, 3
 * receptions and a few dozen discount codes, and the email registry ships 78
 * entries. A bundle past this is not a LAW bundle.
 */
const LAW_CONTENT_TRANSFER_MAX_ROWS = 2000;

/**
 * How many NESTED rows — sessions, and speaker appearances — one bundle may
 * carry in total.
 *
 * The cap above bounds the four top-level lists, which is not the same thing:
 * 500 events, comfortably inside it, each carrying tens of thousands of minimal
 * session rows is cheap in JSON bytes, fits the upload limit, and still drives a
 * very large synchronous run (security review, 16 September 2026). Counted
 * across the whole file rather than per event, because that is what the work
 * actually scales with — and the work is real: a session row is a post insert,
 * a speaker row an upsert.
 *
 * The local programme's 105 events carry 142 speaker appearances and 31
 * sessions between them.
 */
const LAW_CONTENT_TRANSFER_MAX_NESTED_ROWS = 20000;

/**
 * The most an archive may weigh once unpacked.
 *
 * A zip's compression ratio is attacker-controlled, so the upload cap above says
 * nothing about what extracting it costs. This is read from the archive's own
 * directory BEFORE a single byte is written, so a bomb is refused rather than
 * survived. Generous against real photographs, which barely compress at all.
 */
const LAW_CONTENT_TRANSFER_MAX_UNZIPPED = 268435456; // 256MB.

/**
 * That cap, filterable, so a host with less room to spare can lower it.
 *
 * Also what lets the bomb guard be tested against a small archive instead of a
 * quarter-gigabyte fixture. Raising it past the default is nobody's good idea,
 * but this is an admin-only screen behind manage_options and the filter is code
 * on the server, so it is no wider a door than the constant itself.
 */
function law_content_transfer_max_unzipped() {
	return (int) apply_filters( 'law_content_transfer_max_unzipped', LAW_CONTENT_TRANSFER_MAX_UNZIPPED );
}

/* ===========================================================================
 * Export
 * ======================================================================== */

/**
 * The whole bundle, ready to be JSON-encoded.
 *
 * @return array
 */
function law_content_transfer_bundle() {
	$uploads = wp_upload_dir();

	return array(
		'format'         => LAW_CONTENT_TRANSFER_FORMAT,
		'version'        => LAW_CONTENT_TRANSFER_VERSION,
		'generated_at'   => gmdate( 'c' ),
		'site'           => array(
			'url'             => home_url( '/' ),
			'uploads_baseurl' => trailingslashit( (string) ( $uploads['baseurl'] ?? '' ) ),
		),
		'programme_year' => (string) law_events_setting( 'year', '' ),
		'receptions'     => law_content_transfer_receptions(),
		'flagship'       => law_content_transfer_flagship(),
		'events'         => law_content_transfer_events(),
		'discounts'      => law_content_transfer_discounts(),
		'emails'         => law_content_transfer_emails(),
	);
}

/**
 * The committee's email wording, as overrides on the code registry.
 *
 * Added 15 September 2026, reversing the original exclusion. The registry in
 * notifications.php is code and travels with a deploy; what does not is the
 * polishing somebody did on the Emails screen, which lives in the
 * `law_events_email_overrides` option and is per-site. 78 emails ship in the
 * registry, so only the slugs actually overridden travel — the rest are already
 * identical on both sides by virtue of being code.
 *
 * TWO THINGS ARE DELIBERATELY DROPPED.
 *
 * `to` is never exported, even though the Emails screen can edit it on the four
 * registry entries whose recipients are a literal address list rather than an
 * audience key. Those addresses are a ROUTING decision belonging to the
 * environment, and the failure modes are not symmetrical: dropping them costs
 * somebody re-typing four addresses, while carrying them can silently point a
 * production notification at a staging test mailbox and nobody finds out until
 * the email that mattered went to the wrong place. Same reasoning as
 * `_law_discount_used`.
 *
 * An override for a slug the registry no longer defines is also dropped, rather
 * than travelling as dead weight that could resurrect a retired email —
 * `law_setup_retire_booking_received_emails()` is the precedent.
 *
 * @return array[] One row per overridden email: {slug, subject, body, active}.
 */
function law_content_transfer_emails() {
	$stored = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	if ( ! is_array( $stored ) ) {
		return array();
	}

	$registry = law_events_email_registry();
	$rows     = array();

	foreach ( $stored as $slug => $override ) {
		$slug = (string) $slug;
		if ( ! is_array( $override ) || ! isset( $registry[ $slug ] ) ) {
			continue;
		}
		$rows[] = array(
			'slug'    => $slug,
			// The registry's own name, so the far site's preview can say which
			// email a row is without every reader knowing the slugs by heart.
			'name'    => (string) ( $registry[ $slug ]['name'] ?? $slug ),
			'subject' => (string) ( $override['subject'] ?? '' ),
			'body'    => (string) ( $override['body'] ?? '' ),
			'active'  => ! empty( $override['active'] ),
		);
	}

	usort( $rows, fn( $a, $b ) => strcmp( $a['slug'], $b['slug'] ) );

	return $rows;
}

/**
 * Every reception, in the shape its own saver takes back.
 *
 * law_reception_form_values() is already exactly the committee-editable set,
 * so the export is that plus the slug (the cross-site key) and the
 * description. Note law_reception_ids() passes the explicit status list:
 * post_status => 'any' silently drops the module's custom statuses, which
 * would leave every unpublished reception out of the bundle.
 *
 * @return array[]
 */
function law_content_transfer_receptions() {
	$rows = array();

	foreach ( law_reception_ids() as $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post ) {
			continue;
		}
		$values = law_reception_form_values( $event_id );

		$rows[] = array(
			'slug'        => (string) $post->post_name,
			'title'       => (string) $values['title'],
			'description' => (string) $values['description'],
			'show'        => (bool) $values['show'],
			'date'        => (string) $values['date'],
			'start'       => (string) $values['start'],
			'end'         => (string) $values['end'],
			'venue'       => (string) $values['venue'],
			'places'      => (int) $values['places'],
			'price'       => (string) $values['price'],
			'included'    => (bool) $values['included'],
			'invitation'  => (bool) $values['invitation'],
		);
	}

	return $rows;
}

/**
 * The flagship, its agenda and every speaker appearance on it.
 *
 * The prices are read RAW rather than through law_flagship_price_pounds_field(),
 * which substitutes the agreed default when a key has never been written. That
 * is right for a form field and wrong here: exporting the default would turn
 * "the committee has not set this yet" into "the committee set it to £550",
 * and the import would then stamp it on production. An empty string means
 * leave the stored value alone, which is what every saver already does with one.
 *
 * @return array|null Null when there is no flagship post on this site.
 */
function law_content_transfer_flagship() {
	$event_id = law_flagship_event_id();
	if ( ! $event_id ) {
		return null;
	}

	$values   = law_flagship_form_values( $event_id );
	$sessions = array();

	foreach ( $values['sessions'] as $session ) {
		$speakers = array();
		foreach ( $session['speakers'] as $row ) {
			$speaker_id = absint( $row['speaker_id'] ?? 0 );
			$parts      = $speaker_id ? law_speaker_name_parts( $speaker_id ) : array( 'first' => '', 'last' => '' );

			$speakers[] = array(
				// Identity, carried inline so the importer can match or create
				// the person without ever seeing our post ID.
				'first_name'   => (string) ( $parts['first'] ?? '' ),
				'last_name'    => (string) ( $parts['last'] ?? '' ),
				'email'        => $speaker_id ? (string) law_event_meta( $speaker_id, '_law_speaker_email' ) : '',
				'website'      => $speaker_id ? (string) law_event_meta( $speaker_id, '_law_website' ) : '',
				// Per appearance, and therefore per row rather than per person.
				'role'         => (string) ( $row['role'] ?? '' ),
				'organisation' => (string) ( $row['organisation'] ?? '' ),
				'job_title'    => (string) ( $row['job_title'] ?? '' ),
				'bio'          => (string) ( $row['bio'] ?? '' ),
				'photo'        => law_content_transfer_attachment( absint( $row['photo_id'] ?? 0 ) ),
			);
		}

		$sessions[] = array(
			'title'       => (string) $session['title'],
			'start'       => (string) $session['start'],
			'end'         => (string) $session['end'],
			'description' => (string) $session['description'],
			'speakers'    => $speakers,
		);
	}

	return array(
		'title'          => (string) $values['title'],
		'description'    => (string) $values['description'],
		'date'           => (string) $values['date'],
		'venue'          => (string) $values['venue'],
		'show'           => (bool) $values['show'],
		'places'         => (int) $values['places'],
		'price'          => law_content_transfer_price_field( $event_id, '_law_flagship_price_pence' ),
		'price_late'     => law_content_transfer_price_field( $event_id, '_law_flagship_price_late_pence' ),
		'price_switch'   => (string) law_event_meta( $event_id, '_law_flagship_price_switch' ),
		// A page reference, exported as a PATH so it can be resolved back on a
		// site where that page has a different ID. Already a URL or a path on
		// most sites; law_content_transfer_terms_path() normalises either.
		'attendee_terms' => law_content_transfer_terms_path( (string) $values['attendee_terms'] ),
		'hero_image'     => law_content_transfer_attachment( absint( $values['hero_image_id'] ) ),
		'sessions'       => $sessions,
	);
}

/** A stored price as a pounds string, '' when the key has never been written. */
function law_content_transfer_price_field( $event_id, $key ) {
	$stored = get_post_meta( (int) $event_id, $key, true );
	if ( '' === $stored || null === $stored ) {
		return '';
	}
	return number_format( max( 0, (int) $stored ) / 100, 2, '.', '' );
}

/**
 * The attendee terms setting as a site-relative path.
 *
 * The setting holds whatever the committee typed on the flagship screen: a
 * full URL, a path, or (historically) a page ID. All three are reduced to a
 * path here, which is the only form that means the same thing on two sites.
 *
 * @return string '' when there is nothing to carry.
 */
function law_content_transfer_terms_path( $value ) {
	$value = trim( (string) $value );
	if ( '' === $value ) {
		return '';
	}
	if ( ctype_digit( $value ) ) {
		$permalink = get_permalink( (int) $value );
		$value     = $permalink ? $permalink : '';
	}
	if ( '' === $value ) {
		return '';
	}
	$path = (string) wp_parse_url( $value, PHP_URL_PATH );
	return '' !== $path ? $path : $value;
}

/**
 * An attachment as something another site can read.
 *
 * `url` is IDENTITY and `archive` is BYTES, and the split matters. The URL is
 * what LAW_CONTENT_TRANSFER_SOURCE_META records, so it is what makes a re-import
 * reuse an attachment instead of filling the media library with copies — it has
 * to keep meaning "where this picture came from" even though the file now
 * travels inside the zip. `archive` is only a path within the archive.
 *
 * No local server path is ever put in the bundle, not even to be stripped again
 * before writing: the archive name carries the attachment ID, and
 * law_content_transfer_collect_images() resolves the file from that. A path that
 * is never added cannot leak.
 *
 * @return array|null {url, filename, alt, archive} or null when there is no
 *                    usable file.
 */
function law_content_transfer_attachment( $attachment_id ) {
	$attachment_id = (int) $attachment_id;
	if ( ! $attachment_id ) {
		return null;
	}
	$url = wp_get_attachment_url( $attachment_id );
	if ( ! $url ) {
		return null; // Deleted since it was set: the row simply travels without a photo.
	}

	$name = basename( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	$file = (string) get_attached_file( $attachment_id );

	$image = array(
		'url'      => (string) $url,
		'filename' => $name,
		'alt'      => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
	);

	// A record whose file has gone from disk still travels, with the URL alone.
	// On a version 1 bundle that was the only route anyway, and the importer's
	// fetch fallback may still find it.
	if ( '' !== $file && is_readable( $file ) ) {
		// Prefixed with the attachment ID, which does two jobs: two speakers'
		// "headshot.jpg" cannot collide inside the archive, and the exporter can
		// find the file again without the bundle carrying a server path. Not a
		// hash, because the name wants to stay legible to whoever opens the zip
		// to check their photographs are in it.
		$image['archive'] = LAW_CONTENT_TRANSFER_IMAGE_DIR . $attachment_id . '-' . sanitize_file_name( $name );
	}

	return $image;
}

/**
 * The local file an archive path refers to, on the site that wrote it.
 *
 * The reverse of the naming above, and deliberately a one-way derivation from
 * the attachment ID rather than a path read back out of the bundle: this runs on
 * export, where the bundle is ours, but it is the same function shape that would
 * be dangerous if it ever took a path from a file, so it never does.
 *
 * @return string '' when the id is unreadable or the file has gone.
 */
function law_content_transfer_archive_source( $archive_path ) {
	$leaf = basename( (string) $archive_path );
	if ( ! preg_match( '/^(\d+)-/', $leaf, $match ) ) {
		return '';
	}

	$file = (string) get_attached_file( (int) $match[1] );

	return ( '' !== $file && is_readable( $file ) ) ? $file : '';
}

/* The programme's own events _________________________________________________
 *
 * Hosted submissions and the committee's external listings. Receptions and the
 * flagship are excluded here because they already have keys of their own, and
 * their savers do things (price switches, the derived start and end) that a
 * generic event row knows nothing about.
 */

/**
 * The law_event meta an event row carries, and nothing else.
 *
 * DESCRIPTIVE DATA ONLY (Denis, 17 September 2026). The rule this list follows
 * is his: by the time a bundle is imported, production's events have been
 * approved, invoiced, paid and confirmed for real, and none of that record may
 * be touched by a file made on a site where the same events were test data. So
 * what crosses is what an event IS — its title, description, when and where it
 * happens, who is speaking, its running order, its sectors — plus the
 * committee's operational switches. What an event has BEEN THROUGH stays on the
 * site it happened on.
 *
 * An allow list rather than "every key in the schema", so a new key has to be
 * added here on purpose and gets read against that rule when it is. What is
 * deliberately absent, and why:
 *
 * - `_law_stripe_customer_id`, `_law_stripe_invoice_id`, `_law_stripe_invoice_url`,
 *   `_law_stripe_error`, `_law_payment_status`: objects in whichever Stripe
 *   account the source site points at. Importing "paid" onto production would
 *   mark an unpaid invoice settled, which is the one mistake in this file
 *   nobody would spot until a reconciliation.
 * - `_law_fee_pence` and `_law_vat`, the snapshot law_event_snapshot_fee() froze
 *   at approval and the invoice was raised from — and, since 17 September 2026,
 *   the INPUTS to it as well: `_law_fee_tier`, `_law_fee_override` and
 *   `_law_fee_override_amount`. Those three did travel at first, on the argument
 *   that they are the committee's decision about the event rather than a payment
 *   record. That is true and beside the point: nothing recalculates the snapshot
 *   after approval, so importing a different tier onto a PAID event moves the
 *   admin Fee column and the exports while the invoice, the emails and the
 *   reconciliation all keep the old figure. A number that disagrees with the
 *   money is worse than a number that is merely out of date.
 * - `_law_invoice_name`, `_law_invoice_email`, `_law_invoice_address`,
 *   `_law_country_iso`, `_law_vat_number`: who was billed. On an event whose
 *   invoice has been raised and paid, these are the record of that transaction,
 *   not an editable detail, and a staging test address must never overwrite it.
 * - `_law_approved_at`, `_law_rejection_reason`, `_law_cancellation_reason`,
 *   `_law_terms_consent`: the record of decisions that happened somewhere. They
 *   belong to the site where they happened. `law_event_has_been_approved()`
 *   still falls back to `_law_approved_at`, so importing one would also be
 *   telling this site that an approval it never made had happened.
 * - `_law_tickets_sold`, `_law_capacity_warned`, `_law_capacity_full_warned`:
 *   a recount and two one-shot latches, all three about the far site's own
 *   bookings. Carrying the latches would suppress a real nearly-full warning.
 * - `_law_co_owner_ids`: user IDs minted on the far site when the event was
 *   approved there. The ROWS (`_law_co_owner_rows`) travel, because those are
 *   what the host typed; the IDs are production's own.
 * - `_law_reference`, `_law_gf_entry_id`: identity. Both sites derive them from
 *   the same Gravity Forms entry, so they already agree, and the entry ID is
 *   the key this whole format matches on — rewriting it from the file would
 *   let a bad bundle re-point an event at a different record.
 * - `_law_assignee`, `_law_organisation_ids`: IDs of things on the other site.
 *   They travel beside the meta, as an email address and as organisation slugs.
 * - `_law_is_flagship`, `_law_is_reception`, `_law_hero_image_id`, the flagship
 *   price keys and `_law_flagship_date`: not a hosted or external event's. The
 *   banner is the flagship's alone today, and it is an attachment ID besides,
 *   so it could not travel as a bare meta value even if that changed — it would
 *   need the {url, filename, alt, archive} shape law_content_transfer_attachment()
 *   writes, like every other picture in this file.
 * - `_edit_lock`: core's "somebody has this open" marker, meaningless here.
 * - `_law_history_migrated`: a migration latch belonging to the far site's own
 *   run. Carrying it would tell that site its history had been imported when it
 *   had not.
 *
 * @return string[]
 */
function law_content_transfer_event_meta_keys() {
	return array(
		// When and where.
		'_law_start',
		'_law_end',
		'_law_slot_label',
		'_law_preferred_slots',
		'_law_venue',
		'_law_venue_needed',
		'_law_venue_capacity',
		'_law_tickets_available',
		// Who is putting it on.
		'_law_host_organisations',
		'_law_contacts',
		'_law_co_owner_rows',
		// The committee's switches, including Override booking availability
		// (client, 16 September 2026), which is the key this widening was asked
		// for and which nothing else in the bundle could carry.
		//
		// `_law_is_external` is in this list so it CROSSES, and
		// law_content_transfer_write_event() then refuses to write it on an
		// EXISTING event. It is not squeamishness: the flag is what
		// law_event_is_managed_by_law() reads, and that is what unlocks the
		// status guard's exemption. Let an import flip it and the exemption can
		// be unlocked by the same file that then uses it — one run to turn an
		// event external, the next to write `publish` onto it, and a submission
		// nobody approved is on the public programme (security review,
		// 16 September 2026). Reclassifying an event is a committee act with a
		// log line of its own (law_event_log_flag_change()), so it belongs on the
		// dashboard beside the status, for exactly the same reason. A CREATE may
		// use it, because a new post has no workflow behind it to bypass.
		'_law_booking_override',
		'_law_is_external',
		'_law_external_url',
		'_law_session_agenda',
		'_law_registration_state',
		// Classification.
		'_law_sector_jurisdiction',
		'_law_sector_other',
	);
}

/**
 * Every hosted and external event, with its speakers and its agenda.
 *
 * @return array[]
 */
function law_content_transfer_events() {
	$ids = get_posts(
		array(
			'post_type'        => LAW_EVENT_CPT,
			// The module's own statuses, spelled out. post_status => 'any'
			// silently drops custom statuses, which would leave every event
			// that is not Confirmed out of the bundle — the same trap
			// law_reception_ids() documents.
			'post_status'      => law_event_all_status_keys(),
			'posts_per_page'   => -1,
			'orderby'          => 'ID',
			'order'            => 'ASC',
			'fields'           => 'ids',
			'suppress_filters' => false,
			'no_found_rows'    => true,
		)
	);

	$rows = array();

	foreach ( $ids as $event_id ) {
		$event_id = (int) $event_id;
		if ( law_reception_is( $event_id ) || law_flagship_is( $event_id ) ) {
			continue; // Their own keys, their own savers.
		}
		$post = get_post( $event_id );
		if ( ! $post ) {
			continue;
		}

		$meta = array();
		foreach ( law_content_transfer_event_meta_keys() as $key ) {
			$meta[ $key ] = law_event_meta( $event_id, $key );
		}

		$rows[] = array(
			// The two keys, in the order law_content_transfer_find_event()
			// tries them.
			'gf_entry_id'   => (int) law_event_meta( $event_id, '_law_gf_entry_id' ),
			'slug'          => (string) $post->post_name,
			// Printed in the preview so an operator can find the row in the
			// committee dashboard without counting down the table.
			'reference'     => (string) law_event_meta( $event_id, '_law_reference' ),
			'external'      => (bool) law_event_meta( $event_id, '_law_is_external' ),
			'title'         => (string) $post->post_title,
			'description'   => (string) $post->post_content,
			// Applied on a CREATE only. It decides "first appearance" ordering
			// (law_speakers.php sorts on post_date_gmt), which is what picks the
			// photo and organisation a speaker's archive card and profile show.
			// An event that exists on both sites already agrees, because both
			// took the date from the same Gravity Forms entry; one created in
			// the module on staging would otherwise land here dated today and
			// quietly outrank an older record.
			'created'       => (string) $post->post_date_gmt,
			// Carried for every event; APPLIED only where there is no workflow
			// behind it. See the note in the file header.
			'status'        => (string) $post->post_status,
			'owner_email'   => law_content_transfer_user_email( (int) $post->post_author ),
			'assignee_email' => law_content_transfer_user_email( absint( law_event_meta( $event_id, '_law_assignee' ) ) ),
			'event_type'    => (string) law_events_post_term_name( $event_id, 'law_event_type' ),
			'sectors'       => array_values( law_events_post_term_names( $event_id, 'law_sector' ) ),
			'organisations' => law_content_transfer_organisation_slugs( $event_id ),
			'meta'          => $meta,
			'speakers'      => law_content_transfer_event_speakers( law_event_meta( $event_id, '_law_speakers' ) ),
			'sessions'      => law_content_transfer_event_sessions( $event_id ),
		);
	}

	return $rows;
}

/**
 * Appearance rows in the identity-bearing shape rule 2 describes.
 *
 * The same shape law_content_transfer_flagship() writes, so the importer can
 * hand both to law_flagship_resolve_speaker_rows() without caring which key of
 * the bundle they came out of. `is_new` is not added here: it is what the
 * IMPORT means by the row ("match or create"), and a bundle that already said
 * so would be describing the far site's behaviour rather than this site's data.
 *
 * @param array[] $rows _law_speakers, from the event or from one session.
 * @return array[]
 */
function law_content_transfer_event_speakers( $rows ) {
	$out = array();

	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$speaker_id = absint( $row['speaker_id'] ?? 0 );
		$parts      = $speaker_id ? law_speaker_name_parts( $speaker_id ) : array( 'first' => '', 'last' => '' );

		$out[] = array(
			'first_name'   => (string) ( $parts['first'] ?? '' ),
			'last_name'    => (string) ( $parts['last'] ?? '' ),
			'email'        => $speaker_id ? (string) law_event_meta( $speaker_id, '_law_speaker_email' ) : '',
			'website'      => $speaker_id ? (string) law_event_meta( $speaker_id, '_law_website' ) : '',
			'role'         => (string) ( $row['role'] ?? '' ),
			'organisation' => (string) ( $row['organisation'] ?? '' ),
			'job_title'    => (string) ( $row['job_title'] ?? '' ),
			'bio'          => (string) ( $row['bio'] ?? '' ),
			'photo'        => law_content_transfer_attachment( absint( $row['photo_id'] ?? 0 ) ),
		);
	}

	return $out;
}

/**
 * The session agenda, read as EDITABLE rows rather than through
 * law_event_session_rows(), whose speakers are rendered cards that cannot
 * round-trip. Mirrors law_flagship_form_values() field for field, plus the
 * speaker identity every row needs to cross a site boundary.
 *
 * @return array[]
 */
function law_content_transfer_event_sessions( $event_id ) {
	$sessions = array();

	foreach ( law_event_session_ids( (int) $event_id ) as $session_id ) {
		$session = get_post( $session_id );
		if ( ! $session ) {
			continue;
		}
		$sessions[] = array(
			// Carried for one reason, and it is not identity within this format:
			// the Gravity Forms migration dedupes sessions with a meta query on
			// this key (law_migration_run_sessions()), and an import REPLACES the
			// agenda. Without re-stamping it, re-running the migration after an
			// import would create a second copy of every session it had already
			// made. The rows themselves are still matched by position, never by
			// this.
			'gf_entry_id' => (int) law_event_meta( $session_id, '_law_gf_entry_id' ),
			'title'       => (string) $session->post_title,
			'start'       => (string) law_event_meta( $session_id, '_law_start_time' ),
			'end'         => (string) law_event_meta( $session_id, '_law_end_time' ),
			'description' => (string) $session->post_content,
			'speakers'    => law_content_transfer_event_speakers( law_event_meta( $session_id, '_law_speakers' ) ),
		);
	}

	return $sessions;
}

/**
 * A user as the only thing about them that means anything on another site.
 *
 * @return string '' when there is no such user, or they have no address.
 */
function law_content_transfer_user_email( $user_id ) {
	$user_id = (int) $user_id;
	if ( $user_id < 1 ) {
		return '';
	}
	$user = get_userdata( $user_id );
	return ( $user && is_email( $user->user_email ) ) ? (string) $user->user_email : '';
}

/**
 * The event's linked organisations as SLUGS.
 *
 * `organisation` is a post type of the main theme rather than this module, and
 * `_law_organisation_ids` holds its post IDs, so the same slug rule the
 * receptions follow applies: the ID means nothing on the far site, the slug
 * means the same thing on both. An organisation whose slug is not on the far
 * site is dropped there and the row says so.
 *
 * @return string[]
 */
function law_content_transfer_organisation_slugs( $event_id ) {
	$slugs = array();

	foreach ( array_map( 'intval', law_event_meta( (int) $event_id, '_law_organisation_ids' ) ) as $org_id ) {
		$org = $org_id ? get_post( $org_id ) : null;
		if ( $org instanceof WP_Post && '' !== $org->post_name ) {
			$slugs[] = (string) $org->post_name;
		}
	}

	return $slugs;
}

/**
 * Every discount code.
 *
 * law_discount_data() returns `value` as an INT — a percentage for a percent
 * code, PENCE for a fixed one — while law_discount_save() expects the typed
 * string, which for a fixed code is POUNDS. Converting here rather than on the
 * way in keeps the asymmetry in one place. `used` is never exported:
 * production's usage count is its own, and copying staging's would hand people
 * a code that is already spent.
 *
 * @return array[]
 */
function law_content_transfer_discounts() {
	$rows = array();

	foreach ( law_discounts_all() as $post ) {
		$data = law_discount_data( $post );
		if ( ! $data ) {
			continue;
		}

		// Scope travels as SLUGS. Only an event LAW manages itself can be in
		// scope — the receptions, and since 15 September 2026 the flagship too
		// (FLAGSHIP_PAYMENTS.md §13) — and those are exactly the events whose
		// slug is provisioned rather than typed, so a slug means the same thing
		// on both sites. Deliberately not "is a reception": that would have
		// silently dropped a flagship-scoped code the moment the flagship
		// started accepting them. Anything else cannot be resolved on the far
		// side, so it is dropped here and the import says so.
		$scope = array();
		foreach ( $data['events'] as $event_id ) {
			$scoped = get_post( $event_id );
			if ( $scoped && $scoped->post_name && law_event_is_managed_by_law( $event_id ) ) {
				$scope[] = (string) $scoped->post_name;
			}
		}

		$rows[] = array(
			'code'     => (string) $data['code'],
			'active'   => (bool) $data['active'],
			'type'     => (string) $data['type'],
			'value'    => 'fixed' === $data['type']
				? number_format( max( 0, (int) $data['value'] ) / 100, 2, '.', '' )
				: (string) (int) $data['value'],
			'starts'   => (string) $data['starts'],
			'expires'  => (string) $data['expires'],
			'max_uses' => (int) $data['max_uses'],
			'note'     => (string) $data['note'],
			'events'   => $scope,
		);
	}

	return $rows;
}

/** "51 hosted events, 4 external events, 3 receptions, the flagship …". */
function law_content_transfer_summary( array $bundle ) {
	$bits = array();

	$hosted   = 0;
	$external = 0;
	foreach ( (array) ( $bundle['events'] ?? array() ) as $event ) {
		if ( ! empty( $event['external'] ) ) {
			$external++;
		} else {
			$hosted++;
		}
	}
	$bits[] = sprintf( _n( '%d hosted event', '%d hosted events', $hosted, 'law' ), $hosted );
	$bits[] = sprintf( _n( '%d external event', '%d external events', $external, 'law' ), $external );

	$receptions = count( (array) ( $bundle['receptions'] ?? array() ) );
	$bits[]     = sprintf( _n( '%d reception', '%d receptions', $receptions, 'law' ), $receptions );

	if ( ! empty( $bundle['flagship'] ) ) {
		$sessions = count( (array) ( $bundle['flagship']['sessions'] ?? array() ) );
		$speakers = 0;
		foreach ( (array) ( $bundle['flagship']['sessions'] ?? array() ) as $session ) {
			$speakers += count( (array) ( $session['speakers'] ?? array() ) );
		}
		$bits[] = sprintf(
			/* translators: 1: session count, 2: speaker appearance count. */
			__( 'the flagship with %1$s and %2$s', 'law' ),
			sprintf( _n( '%d session', '%d sessions', $sessions, 'law' ), $sessions ),
			sprintf( _n( '%d speaker appearance', '%d speaker appearances', $speakers, 'law' ), $speakers )
		);
	} else {
		$bits[] = 'no flagship event on this site';
	}

	$discounts = count( (array) ( $bundle['discounts'] ?? array() ) );
	$bits[]    = $discounts
		? sprintf( _n( '%d discount code', '%d discount codes', $discounts, 'law' ), $discounts )
		: 'no discount codes';

	$emails = count( (array) ( $bundle['emails'] ?? array() ) );
	$bits[] = $emails
		? sprintf( _n( '%d customised email', '%d customised emails', $emails, 'law' ), $emails )
		: 'no customised emails';

	return implode( ', ', $bits );
}

/**
 * GET admin-post.php?action=law_content_transfer_export — the download.
 *
 * Nonce in the URL via wp_nonce_url() on the button, exactly as the migration
 * report's CSV export does.
 */
add_action( 'admin_post_law_content_transfer_export', 'law_content_transfer_export_handler' );
function law_content_transfer_export_handler() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to do that.', 403 );
	}
	check_admin_referer( 'law_content_transfer_export' );

	$bundle = law_content_transfer_bundle();
	$host   = strtolower( trim( (string) preg_replace( '/[^a-z0-9]+/i', '-', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ), '-' ) );
	$stem   = 'law-content-' . $host . '-' . gmdate( 'Ymd-His' );

	$zip = law_content_transfer_zip( $bundle, $stem );
	if ( is_wp_error( $zip ) ) {
		wp_die( esc_html( $zip->get_error_message() ) );
	}

	nocache_headers();
	header( 'Content-Type: application/zip' );
	header( 'Content-Disposition: attachment; filename="' . $stem . '.zip"' );
	header( 'Content-Length: ' . filesize( $zip ) );
	readfile( $zip ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- streaming a local temp file to the browser.
	unlink( $zip );
	exit;
}

/**
 * The bundle and its images as one zip, written to a temp file.
 *
 * Hand-rolled with ZipArchive exactly as law_events_send_xlsx() in
 * functions/events/export.php does, so the theme still ships no archive library.
 *
 * Images are added by ATTACHMENT, not by row: one headshot is commonly used on
 * several session rows and on the speaker's own profile, and the archive should
 * carry it once.
 *
 * @param array  $bundle From law_content_transfer_bundle().
 * @param string $stem   Filename stem, for the temp file's name only.
 * @return string|WP_Error Path to the temp file; the caller unlinks it.
 */
function law_content_transfer_zip( array $bundle, $stem ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error(
			'law_ct_no_zip',
			'This server has no ZipArchive support, so the bundle cannot be packaged. Ask the host to enable the PHP zip extension.'
		);
	}

	$files = array();
	law_content_transfer_collect_images( $bundle, $files );

	$file = wp_tempnam( $stem . '.zip' );
	$zip  = new ZipArchive();
	if ( true !== $zip->open( $file, ZipArchive::OVERWRITE ) ) {
		unlink( $file );
		return new WP_Error( 'law_ct_zip_failed', 'Could not create the bundle archive.' );
	}

	$zip->addFromString(
		LAW_CONTENT_TRANSFER_MANIFEST,
		(string) wp_json_encode( $bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
	);
	foreach ( $files as $archive_path => $source ) {
		$zip->addFile( $source, $archive_path );
	}
	$zip->close();

	return $file;
}

/**
 * Every image the bundle names, as archive path => local file.
 *
 * Walks the whole structure rather than naming the two places images live today
 * (the flagship banner, each session speaker's photo), so a later field that uses
 * law_content_transfer_attachment() is packaged with no change here.
 *
 * Keyed by archive path, so one headshot used on several session rows is
 * collected once and the archive carries one copy.
 *
 * @param mixed $value The bundle, or any part of it.
 * @param array $files Collected as archive path => local source path.
 */
function law_content_transfer_collect_images( $value, array &$files ) {
	if ( ! is_array( $value ) ) {
		return;
	}

	$archive = (string) ( $value['archive'] ?? '' );
	if ( '' !== $archive && isset( $value['url'] ) ) {
		$source = law_content_transfer_archive_source( $archive );
		if ( '' !== $source ) {
			$files[ $archive ] = $source;
		}
		return;
	}

	foreach ( $value as $item ) {
		law_content_transfer_collect_images( $item, $files );
	}
}

/* ===========================================================================
 * Import: reading the file
 * ======================================================================== */

/**
 * The uploaded bundle, validated.
 *
 * Read straight out of the upload's temp file rather than through
 * wp_handle_upload(): this file must never land in the media library, and it
 * is never kept on disk at all.
 *
 * @return array|WP_Error
 */
function law_content_transfer_read_upload() {
	if ( empty( $_FILES['law_ct_file'] ) || ! is_array( $_FILES['law_ct_file'] ) ) {
		return new WP_Error( 'law_ct_no_file', 'Choose a bundle file to upload.' );
	}

	$file = $_FILES['law_ct_file']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each member is validated below.

	if ( ! empty( $file['error'] ) ) {
		return new WP_Error(
			'law_ct_upload_error',
			UPLOAD_ERR_INI_SIZE === (int) $file['error'] || UPLOAD_ERR_FORM_SIZE === (int) $file['error']
				? 'That file is larger than this server accepts for an upload.'
				: 'The file did not upload. Try again.'
		);
	}

	// Size before identity: both read only the $_FILES metadata and neither
	// touches the file, and refusing on size first means an oversized upload is
	// told what is actually wrong with it. is_uploaded_file() still stands
	// between here and any read.
	if ( (int) ( $file['size'] ?? 0 ) > LAW_CONTENT_TRANSFER_MAX_BYTES ) {
		return new WP_Error(
			'law_ct_too_big',
			sprintf( 'A transfer bundle should be well under %s. That file is bigger, so it is refused unread.', size_format( LAW_CONTENT_TRANSFER_MAX_BYTES ) )
		);
	}

	$tmp = (string) ( $file['tmp_name'] ?? '' );
	if ( '' === $tmp || ! is_uploaded_file( $tmp ) ) {
		return new WP_Error( 'law_ct_not_an_upload', 'That was not a file upload.' );
	}

	if ( law_content_transfer_is_archive( $tmp ) ) {
		return law_content_transfer_read_archive( $tmp );
	}

	$raw = file_get_contents( $tmp ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local temp file, not a remote fetch.
	if ( false === $raw || '' === trim( (string) $raw ) ) {
		return new WP_Error( 'law_ct_empty', 'That file is empty.' );
	}

	return law_content_transfer_parse( (string) $raw );
}

/**
 * Is this file a zip?
 *
 * By the bytes, not the extension: the two formats have to be told apart by
 * something the uploader does not get to choose, and a bundle renamed .json
 * should still import. Its own function so the branch can be tested — everything
 * upstream of it in law_content_transfer_read_upload() is behind
 * is_uploaded_file(), which nothing outside a real request can satisfy.
 */
function law_content_transfer_is_archive( $path ) {
	if ( ! is_readable( $path ) ) {
		return false; // Checked first so an unreadable path is an answer, not a PHP warning.
	}

	$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- a local file, not a remote fetch.
	if ( ! $handle ) {
		return false;
	}
	$magic = (string) fread( $handle, 4 );
	fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions

	// The local-file-header signature every zip starts with.
	return "PK\x03\x04" === $magic;
}

/**
 * Unpack an uploaded archive into a working directory and return its bundle.
 *
 * Everything an archive brings is hostile until proved otherwise, and a zip has
 * two whole classes of attack the JSON format did not:
 *
 * 1. PATH TRAVERSAL. Entry names are attacker-controlled, so an entry called
 *    "../../wp-config.php" would let an upload write anywhere the web user can.
 *    That is why nothing here uses ZipArchive::extractTo() on the whole archive,
 *    which honours whatever names the file carries. Each name is checked against
 *    an allowlist FIRST and only then used to build a path.
 * 2. ZIP BOMBS. Compression ratio is chosen by whoever made the file, so the
 *    upload cap says nothing about what unpacking costs. The declared
 *    uncompressed total is read from the archive's own directory before a single
 *    byte is written.
 *
 * What survives both is still only a file with a plausible name: the real gate
 * on an image remains wp_check_filetype_and_ext() over the bytes, in
 * law_content_transfer_sideload(), unchanged.
 *
 * @param string $tmp The upload's temp file, already through is_uploaded_file().
 * @return array|WP_Error The bundle, with `archive_dir` naming the working copy.
 */
function law_content_transfer_read_archive( $tmp ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error(
			'law_ct_no_zip',
			'This server has no ZipArchive support, so a .zip bundle cannot be read. Ask the host to enable the PHP zip extension, or export a .json bundle from the other site.'
		);
	}

	$zip = new ZipArchive();
	if ( true !== $zip->open( $tmp ) ) {
		return new WP_Error( 'law_ct_bad_zip', 'That file is not readable as a zip archive.' );
	}

	// Pass one: read the directory and decide. Nothing is written yet.
	$manifest = '';
	$images   = array();
	$declared = 0;

	for ( $i = 0; $i < $zip->numFiles; $i++ ) {
		$entry = $zip->statIndex( $i );
		if ( ! is_array( $entry ) ) {
			continue;
		}

		$name      = (string) ( $entry['name'] ?? '' );
		$declared += (int) ( $entry['size'] ?? 0 );

		if ( $declared > law_content_transfer_max_unzipped() ) {
			$zip->close();
			return new WP_Error(
				'law_ct_zip_bomb',
				sprintf( 'That archive unpacks to more than %s, so it was refused unread.', size_format( law_content_transfer_max_unzipped() ) )
			);
		}

		if ( LAW_CONTENT_TRANSFER_MANIFEST === $name ) {
			$manifest = (string) $zip->getFromIndex( $i );
			continue;
		}

		// The allowlist. An entry is either the manifest above or a plain file
		// directly inside images/ — no traversal, no nesting, no absolute path,
		// no Windows separator, nothing else at all. Anything else is ignored
		// rather than refused: a zip tool's own __MACOSX/ noise is not a reason
		// to reject a good bundle, and an entry that is never read cannot hurt.
		if ( ! law_content_transfer_safe_entry( $name ) ) {
			continue;
		}

		$images[] = $name;

		if ( count( $images ) > LAW_CONTENT_TRANSFER_MAX_IMAGES ) {
			$zip->close();
			return new WP_Error(
				'law_ct_too_many_images',
				sprintf( 'That archive carries more than %d images, so it was refused unread.', LAW_CONTENT_TRANSFER_MAX_IMAGES )
			);
		}
	}

	if ( '' === trim( $manifest ) ) {
		$zip->close();
		return new WP_Error( 'law_ct_no_manifest', sprintf( 'That archive has no %s in it, so it is not a LAW content transfer bundle.', LAW_CONTENT_TRANSFER_MANIFEST ) );
	}

	$bundle = law_content_transfer_parse( $manifest );
	if ( is_wp_error( $bundle ) ) {
		$zip->close();
		return $bundle;
	}

	// Pass two: write, now that the archive has been judged.
	$dir = law_content_transfer_workdir();
	if ( is_wp_error( $dir ) ) {
		$zip->close();
		return $dir;
	}

	foreach ( $images as $name ) {
		$bytes = $zip->getFromName( $name );
		if ( false === $bytes ) {
			continue;
		}
		// basename() again at the point of use, belt to law_content_transfer_safe_entry()'s
		// braces: this is the line that turns a name from the file into a path.
		file_put_contents( $dir . '/' . basename( $name ), $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- writing to our own working directory.
	}

	$zip->close();

	$bundle['archive_dir'] = $dir;

	return $bundle;
}

/**
 * Is this archive entry one we are willing to read?
 *
 * Exactly "images/<name>", where <name> is a plain filename. Written as an
 * allowlist rather than a "reject .." blocklist because the ways to spell a
 * traversal are open-ended and the ways to spell a legitimate entry are not.
 */
function law_content_transfer_safe_entry( $name ) {
	$name = (string) $name;

	if ( 0 !== strpos( $name, LAW_CONTENT_TRANSFER_IMAGE_DIR ) ) {
		return false;
	}
	if ( false !== strpos( $name, '\\' ) || false !== strpos( $name, "\0" ) ) {
		return false;
	}

	$leaf = substr( $name, strlen( LAW_CONTENT_TRANSFER_IMAGE_DIR ) );

	// "." and ".." named explicitly, because basename() does NOT reject them:
	// basename('..') is '..', so the self-comparison below passes them through.
	// "images/.." resolves to the uploads folder itself, which is precisely the
	// escape this function exists to stop.
	if ( '' === $leaf || '.' === $leaf || '..' === $leaf ) {
		return false;
	}

	// A leaf carrying its own separator is a nested path, and one that is not
	// its own basename has something path-shaped in it either way.
	return false === strpos( $leaf, '/' ) && basename( $leaf ) === $leaf;
}

/**
 * A private directory for one import's extracted images.
 *
 * Lives under the migration directory the snapshot step already creates and
 * protects (an .htaccess deny, an index.php, random suffixes), because an
 * extracted speaker photograph deserves the same treatment as anything else this
 * screen writes. Named per user and per request, so two previews cannot mix, and
 * the previous one is cleared first so a second upload cannot accumulate.
 *
 * @return string|WP_Error Absolute path, no trailing slash.
 */
function law_content_transfer_workdir() {
	$uploads = wp_upload_dir();
	$base    = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . 'law-migration';

	if ( ! wp_mkdir_p( $base ) ) {
		return new WP_Error( 'law_ct_no_workdir', 'Could not create a working directory for the archive under wp-content/uploads/law-migration/.' );
	}
	law_content_transfer_protect_dir( $base );

	law_content_transfer_clear_workdir();

	$dir = $base . '/ct-' . get_current_user_id() . '-' . wp_generate_password( 12, false, false );
	if ( ! wp_mkdir_p( $dir ) ) {
		return new WP_Error( 'law_ct_no_workdir', 'Could not create a working directory for the archive.' );
	}

	return $dir;
}

/**
 * The deny files the snapshot step relies on too.
 *
 * nginx ignores .htaccess, which is why the snapshot has its own exposure
 * self-test. These are a speaker's headshot rather than a database dump, so the
 * stakes are lower and the files are written without a probe — but the
 * directory still wants a server-level deny rule on any nginx host.
 */
function law_content_transfer_protect_dir( $dir ) {
	if ( ! file_exists( $dir . '/.htaccess' ) ) {
		file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
	if ( ! file_exists( $dir . '/index.php' ) ) {
		file_put_contents( $dir . '/index.php', "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}
}

/**
 * Delete this user's extracted images.
 *
 * Called when a new preview starts, and again when an apply finishes, so the
 * bytes live exactly as long as the decision they belong to. An abandoned
 * preview is cleared by the next one; anything older than a day goes regardless,
 * so a preview nobody came back to cannot sit in uploads indefinitely.
 */
function law_content_transfer_clear_workdir() {
	$uploads = wp_upload_dir();
	$base    = trailingslashit( (string) ( $uploads['basedir'] ?? '' ) ) . 'law-migration';
	$mine    = 'ct-' . get_current_user_id() . '-';

	foreach ( (array) glob( $base . '/ct-*' ) as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}
		$stale = ( time() - (int) filemtime( $dir ) ) > DAY_IN_SECONDS;
		if ( ! $stale && 0 !== strpos( basename( $dir ), $mine ) ) {
			continue; // Somebody else's live preview.
		}
		law_content_transfer_rmdir( $dir );
	}
}

/** Remove a working directory and the files directly in it. Never recurses: nothing nested is ever written. */
function law_content_transfer_rmdir( $dir ) {
	foreach ( (array) glob( $dir . '/*' ) as $file ) {
		if ( is_file( $file ) ) {
			unlink( $file );
		}
	}
	@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- a leftover subdirectory is not worth an error.
}

/**
 * Parse and sanity-check a bundle.
 *
 * The version is refused UPWARDS only: a file from an older version of this
 * code is still readable, one from a newer version is not, and half-importing
 * it would be worse than saying so.
 *
 * @return array|WP_Error
 */
function law_content_transfer_parse( $raw ) {
	$bundle = json_decode( (string) $raw, true, 64 );

	if ( ! is_array( $bundle ) ) {
		return new WP_Error( 'law_ct_bad_json', 'That file is not readable as JSON. Make sure it is the .json bundle downloaded from the other site, unedited.' );
	}
	if ( LAW_CONTENT_TRANSFER_FORMAT !== ( $bundle['format'] ?? '' ) ) {
		return new WP_Error( 'law_ct_wrong_format', 'That is not a LAW content transfer bundle.' );
	}
	if ( (int) ( $bundle['version'] ?? 0 ) > LAW_CONTENT_TRANSFER_VERSION ) {
		return new WP_Error(
			'law_ct_future_version',
			sprintf( 'That bundle was written by a newer version of the theme (format %d, this site reads %d). Deploy the newer code here first.', (int) $bundle['version'], LAW_CONTENT_TRANSFER_VERSION )
		);
	}

	// The row-count gate, before anything walks these arrays. Refused rather
	// than truncated: a silently shortened import is the shape of failure this
	// whole panel exists to avoid.
	foreach ( array( 'receptions', 'events', 'discounts', 'emails' ) as $law_ct_list ) {
		$law_ct_count = is_array( $bundle[ $law_ct_list ] ?? null ) ? count( $bundle[ $law_ct_list ] ) : 0;
		if ( $law_ct_count > LAW_CONTENT_TRANSFER_MAX_ROWS ) {
			return new WP_Error(
				'law_ct_too_many_rows',
				sprintf(
					'That bundle carries %s %s, more than the %s this screen will read in one go. It was not made by this theme.',
					number_format_i18n( $law_ct_count ),
					$law_ct_list,
					number_format_i18n( LAW_CONTENT_TRANSFER_MAX_ROWS )
				)
			);
		}
	}

	$law_ct_nested = law_content_transfer_count_nested( $bundle );
	if ( $law_ct_nested > LAW_CONTENT_TRANSFER_MAX_NESTED_ROWS ) {
		return new WP_Error(
			'law_ct_too_many_rows',
			sprintf(
				'That bundle carries %s sessions and speaker appearances between its events, more than the %s this screen will read in one go. It was not made by this theme.',
				number_format_i18n( $law_ct_nested ),
				number_format_i18n( LAW_CONTENT_TRANSFER_MAX_NESTED_ROWS )
			)
		);
	}

	$bundle['receptions'] = array_values( array_filter( (array) ( $bundle['receptions'] ?? array() ), 'is_array' ) );
	// Absent in a version 1 or 2 bundle, which is not an error: an older file
	// simply carries no events and the import leaves this site's alone.
	$bundle['events']     = array_values( array_filter( (array) ( $bundle['events'] ?? array() ), 'is_array' ) );
	$bundle['discounts']  = array_values( array_filter( (array) ( $bundle['discounts'] ?? array() ), 'is_array' ) );
	$bundle['emails']     = array_values( array_filter( (array) ( $bundle['emails'] ?? array() ), 'is_array' ) );
	$bundle['flagship']   = is_array( $bundle['flagship'] ?? null ) ? $bundle['flagship'] : null;

	return $bundle;
}

/**
 * Every session and speaker row in a bundle, counted before anything walks them.
 *
 * Counts the flagship's agenda as well as the events', because both go through
 * the same savers and cost the same. Deliberately not recursive over the whole
 * structure: an arbitrary walk of attacker-shaped JSON is the thing being
 * guarded against, so this only looks where rows are actually read from.
 *
 * @return int
 */
function law_content_transfer_count_nested( array $bundle ) {
	$total = 0;

	$containers = (array) ( $bundle['events'] ?? array() );
	if ( is_array( $bundle['flagship'] ?? null ) ) {
		$containers[] = $bundle['flagship'];
	}

	foreach ( $containers as $container ) {
		if ( ! is_array( $container ) ) {
			continue;
		}
		$total += count( (array) ( $container['speakers'] ?? array() ) );
		foreach ( (array) ( $container['sessions'] ?? array() ) as $session ) {
			$total++;
			if ( is_array( $session ) ) {
				$total += count( (array) ( $session['speakers'] ?? array() ) );
			}
		}
	}

	return $total;
}

/* ===========================================================================
 * Import: images
 * ======================================================================== */

/**
 * The source site's uploads base URL, validated, or '' when it is unusable.
 *
 * Every image fetch is restricted to this prefix. Together with the
 * manage_options gate on the panel, that is what stops a bundle, which is after
 * all just an uploaded file, from pointing this server at an arbitrary host.
 * download_url() additionally goes through wp_safe_remote_get(), which refuses
 * loopback, private and link-local addresses on its own.
 *
 * The guarantee is narrower than it looks, and deliberately not engineered
 * around: wp_http_validate_url() resolves the host here, and wp_safe_remote_get()
 * resolves it again at fetch time, so somebody who both wrote the bundle and
 * controls DNS for the host they named in it could answer the two differently.
 * That is a property of every caller of wp_safe_remote_get() in WordPress, it
 * needs an administrator to upload the file first, and the mitigation belongs at
 * the egress firewall rather than here.
 *
 * Memoised per bundle: it is asked once per image, and each miss costs a DNS
 * resolution.
 */
function law_content_transfer_media_base( array $bundle ) {
	static $cache = array();

	$raw = (string) ( $bundle['site']['uploads_baseurl'] ?? '' );
	if ( array_key_exists( $raw, $cache ) ) {
		return $cache[ $raw ];
	}

	$cache[ $raw ] = law_content_transfer_validate_media_base( $raw );
	return $cache[ $raw ];
}

/** The validation half of law_content_transfer_media_base(), so it can be memoised. */
function law_content_transfer_validate_media_base( $base ) {
	$base = trim( (string) $base );
	if ( '' === $base || ! wp_http_validate_url( $base ) ) {
		return '';
	}
	$scheme = strtolower( (string) wp_parse_url( $base, PHP_URL_SCHEME ) );
	if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
		return '';
	}
	return trailingslashit( $base );
}

/**
 * The same base, judged as an IDENTITY prefix rather than as a fetch target.
 *
 * The difference is wp_http_validate_url(), which resolves the host and refuses
 * private and loopback addresses. That is exactly right before this server makes
 * a request, and exactly wrong when it is only deciding whether an image's
 * recorded origin sits under the bundle's own uploads folder: a source site on a
 * private network, or one whose hostname resolves internally, is the case the
 * archive was added to serve, and running its identity claims past DNS would
 * throw away every photograph in the zip.
 *
 * What is still enforced is the part that carries the security weight — that
 * every image in one bundle shares one declared prefix, so a bundle cannot claim
 * an attachment imported from somewhere else.
 */
function law_content_transfer_identity_base( array $bundle ) {
	$base = trim( (string) ( $bundle['site']['uploads_baseurl'] ?? '' ) );
	if ( '' === $base ) {
		return '';
	}

	$parts = wp_parse_url( $base );
	if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
		return '';
	}
	if ( ! in_array( strtolower( (string) ( $parts['scheme'] ?? '' ) ), array( 'http', 'https' ), true ) ) {
		return '';
	}

	return trailingslashit( $base );
}

/**
 * The archive file backing one image, or '' when there is none.
 *
 * The bundle names the entry and the working directory holds the bytes, and the
 * two are joined HERE rather than trusted from either side alone: basename()
 * means a manifest that says "../../wp-config.php" resolves inside the working
 * directory or not at all. law_content_transfer_safe_entry() already refused
 * such a name when the archive was read, so this is the second of two
 * independent checks on the same class of mistake.
 */
function law_content_transfer_archive_file( array $image, array $bundle ) {
	$entry = trim( (string) ( $image['archive'] ?? '' ) );
	$dir   = trim( (string) ( $bundle['archive_dir'] ?? '' ) );

	if ( '' === $entry || '' === $dir || ! law_content_transfer_safe_entry( $entry ) ) {
		return '';
	}

	$file = $dir . '/' . basename( $entry );

	return is_readable( $file ) ? $file : '';
}

/**
 * An image from the source bundle, as a local attachment ID.
 *
 * Two routes in, and the order is the whole point of format version 2. An image
 * carried INSIDE the archive is copied from there, which needs no network at
 * all; that is what makes the tool work between sites that cannot reach each
 * other, and a site behind HTTP basic auth (LAW staging) is exactly that case.
 * An image that names only a URL — a version 1 bundle, or one whose file had
 * gone from the source site's disk — falls back to the fetch, with the
 * allowlist and wp_safe_remote_get() gates that route has always had.
 *
 * Idempotent by SOURCE URL either way: the imported attachment records where it
 * came from, and a re-run reuses it rather than filling the media library with
 * copies. The URL stays the identity even when the bytes came from the zip,
 * which is why the exporter keeps sending it. Memoised within the request too,
 * because one headshot is commonly used on several session rows.
 *
 * A failure is never fatal. A missing headshot is not a reason to abandon an
 * agenda import, so this warns and returns 0.
 *
 * @param array $image  {url, filename, alt, archive?} from the bundle.
 * @param array $bundle The whole bundle, for the allowlist base and archive dir.
 * @param string $ref   Log reference, e.g. "flagship session 3".
 * @param bool  $dry    Look only: never write.
 * @return array{id:int, note:string}
 */
function law_content_transfer_image( $image, array $bundle, $ref, $dry = false ) {
	static $seen    = array();
	static $fetched = 0;

	if ( ! is_array( $image ) || '' === trim( (string) ( $image['url'] ?? '' ) ) ) {
		return array( 'id' => 0, 'note' => '' );
	}

	$url  = trim( (string) $image['url'] );
	$name = (string) ( $image['filename'] ?? basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
	$file = law_content_transfer_archive_file( $image, $bundle );

	if ( isset( $seen[ $url ] ) ) {
		return array( 'id' => (int) $seen[ $url ], 'note' => '' );
	}

	// The allowlist FIRST, before the reuse lookup below, and it applies to a
	// zip-carried image too even though nothing will be fetched. The URL is the
	// IDENTITY key: knowing the exact source URL of an existing photo would
	// otherwise be enough to hang it on a fabricated speaker row, and that is
	// true however the bytes arrived.
	//
	// Note which base: the syntactic one. law_content_transfer_media_base()
	// additionally requires the host to be publicly routable, which is right
	// before a fetch and wrong here — a source site on a private network is the
	// exact case the archive exists to serve, and resolving its identity claims
	// against DNS would refuse every one of its images.
	$base = law_content_transfer_identity_base( $bundle );
	if ( '' === $base || 0 !== strpos( $url, $base ) ) {
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( 'Image "%s" is not under the bundle\'s own uploads folder, so it was not imported.', $name ) );
		return array( 'id' => 0, 'note' => sprintf( '%s cannot be imported (outside the source uploads folder)', $name ) );
	}

	// Already imported on an earlier run, or by an earlier row of this one. This
	// is also what makes a re-import safe for photographs: the same bundle
	// re-applied reuses the attachments and never touches the network, so a
	// blip cannot blank a photo that imported correctly the first time.
	$existing = get_posts(
		array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => 1,
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'meta_key'       => LAW_CONTENT_TRANSFER_SOURCE_META, // phpcs:ignore WordPress.DB.SlowDBQuery
			'meta_value'     => $url, // phpcs:ignore WordPress.DB.SlowDBQuery
		)
	);
	if ( $existing ) {
		$seen[ $url ] = (int) $existing[0];
		return array( 'id' => (int) $existing[0], 'note' => '' );
	}

	if ( $dry ) {
		return array(
			'id'   => 0,
			'note' => '' !== $file
				? sprintf( '%s will be imported from the bundle', $name )
				: sprintf( '%s will be fetched from the source site', $name ),
		);
	}

	$fetched++;
	if ( $fetched > LAW_CONTENT_TRANSFER_MAX_IMAGES ) {
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( 'Image limit of %d reached; "%s" and anything after it were not imported.', LAW_CONTENT_TRANSFER_MAX_IMAGES, $name ) );
		return array( 'id' => 0, 'note' => sprintf( '%s was not imported: this run has already taken %d images', $name, LAW_CONTENT_TRANSFER_MAX_IMAGES ) );
	}

	$id = '' !== $file
		? law_content_transfer_sideload_file( $file, $url, $name, (string) ( $image['alt'] ?? '' ), $ref )
		: law_content_transfer_sideload( $url, $name, (string) ( $image['alt'] ?? '' ), $ref );

	if ( $id ) {
		$seen[ $url ] = $id;
	}

	return array( 'id' => $id, 'note' => $id ? '' : sprintf( '%s could not be imported', $name ) );
}

/**
 * Download one image and put it in the media library.
 *
 * The version 1 route, kept for bundles written before the archive existed and
 * for an image whose file had gone from the source site's disk at export time.
 *
 * @return int 0 on any failure, which is always warn-and-continue.
 */
function law_content_transfer_sideload( $url, $name, $alt, $ref ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$tmp = download_url( $url, 30 );
	if ( is_wp_error( $tmp ) ) {
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( 'Could not fetch "%s": %s', $name, $tmp->get_error_message() ) );
		return 0;
	}

	return law_content_transfer_install( $tmp, $url, $name, $alt, $ref );
}

/**
 * Put one image carried inside the archive into the media library.
 *
 * Copied to a temp file first rather than handed over directly, because
 * media_handle_sideload() MOVES what it is given: passing the working copy would
 * empty the directory as it went, and a second apply of the same preview would
 * then find nothing.
 *
 * @param string $file The extracted file, already inside the working directory.
 * @param string $url  The source URL, recorded as identity.
 * @return int 0 on any failure, which is always warn-and-continue.
 */
function law_content_transfer_sideload_file( $file, $url, $name, $alt, $ref ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$tmp = wp_tempnam( $name );
	if ( ! $tmp || ! copy( $file, $tmp ) ) {
		if ( $tmp ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( 'Could not read "%s" out of the bundle.', $name ) );
		return 0;
	}

	return law_content_transfer_install( $tmp, $url, $name, $alt, $ref );
}

/**
 * The half both routes share: check the bytes, install, record where it came from.
 *
 * Kept as one function on purpose. The checks below are the real gate on what
 * enters the media library, and a zip-carried file has to pass exactly the same
 * ones as a downloaded one — a second copy of this logic would be a second place
 * for them to drift apart.
 *
 * @param string $tmp Temp file this function owns and will consume or delete.
 * @return int 0 on any failure.
 */
function law_content_transfer_install( $tmp, $url, $name, $alt, $ref ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	// Trust the bytes, not the name. An extension the file does not live up to
	// is refused rather than corrected, because this file came out of an upload.
	$checked = wp_check_filetype_and_ext( $tmp, $name );
	$type    = (string) ( $checked['type'] ?: '' );
	if ( '' === $type || 0 !== strpos( $type, 'image/' ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( '"%s" is not an image file, so it was not imported.', $name ) );
		return 0;
	}

	$id = media_handle_sideload(
		array(
			'name'     => sanitize_file_name( $name ),
			'tmp_name' => $tmp,
		),
		0,
		null
	);
	if ( is_wp_error( $id ) ) {
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( 'Could not import "%s": %s', $name, $id->get_error_message() ) );
		return 0;
	}

	$id = (int) $id;
	update_post_meta( $id, LAW_CONTENT_TRANSFER_SOURCE_META, $url );
	if ( '' !== trim( (string) $alt ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $alt ) );
	}

	return $id;
}

/* ===========================================================================
 * Import: the run
 * ======================================================================== */

/**
 * Preview or apply a bundle.
 *
 * Both modes take the same route and reach the same conclusions; the dry run
 * simply diffs the stored record against what the bundle WOULD make of it,
 * and the real run diffs it against what the saver actually made of it. There
 * is deliberately no second code path for the preview, so the preview cannot
 * promise something the apply does not do.
 *
 * @param array $bundle
 * @param bool  $dry
 * @param int   $actor
 * @return array[] One row per record: kind, ref, verdict, changes[].
 */
function law_content_transfer_run( array $bundle, $dry, $actor = 0 ) {
	$rows  = array();
	$slugs = array();

	foreach ( (array) $bundle['receptions'] as $reception ) {
		$row = law_content_transfer_run_reception( $reception, $bundle, $dry, $actor );
		// Recorded even when the ID is 0, which is what a dry run's Create row
		// carries: the discount scope below has to be able to tell "this run
		// will create that reception" from "no such reception anywhere", or a
		// preview would report a scope as dropped that the apply keeps.
		if ( ! in_array( $row['verdict'], array( 'Skipped', 'Failed' ), true ) ) {
			$slugs[ sanitize_title( (string) ( $reception['slug'] ?? '' ) ) ] = (int) $row['event_id'];
		}
		$rows[] = $row;
	}

	if ( ! empty( $bundle['flagship'] ) ) {
		$rows[] = law_content_transfer_run_flagship( (array) $bundle['flagship'], $bundle, $dry, $actor );
	}

	// After the two managed kinds and before the discounts. Nothing here feeds
	// the discount scope — only receptions and the flagship can be scoped — so
	// the position is about reading order rather than dependency: the operator
	// sees LAW's own records settle before the fifty-odd programme rows scroll
	// past.
	foreach ( (array) ( $bundle['events'] ?? array() ) as $event ) {
		$rows[] = law_content_transfer_run_event( $event, $bundle, $dry, $actor );
	}

	foreach ( (array) $bundle['discounts'] as $discount ) {
		$rows[] = law_content_transfer_run_discount( $discount, $slugs, $dry, $actor );
	}

	// Last, and the ordering is not arbitrary. Every email above this line can
	// fire one: a reception that goes on sale, a discount that changes. Writing
	// the wording first would mean a run sent its own notifications in the new
	// words before the operator had seen the preview of them applied.
	foreach ( (array) ( $bundle['emails'] ?? array() ) as $email ) {
		$rows[] = law_content_transfer_run_email( $email, $dry, $actor );
	}

	return $rows;
}

/**
 * Describe a body rewrite in a line the operator can actually act on.
 *
 * Names the length change and quotes the first line that differs, which is
 * where a rewrite almost always shows. Quoting the divergence rather than the
 * opening is the whole point: the opening is usually identical.
 */
function law_content_transfer_body_change( $before, $after, $label = 'Body' ) {
	$was  = (string) $before;
	$now  = (string) $after;
	$size = sprintf( '%s characters → %s', number_format_i18n( mb_strlen( $was ) ), number_format_i18n( mb_strlen( $now ) ) );

	if ( '' === trim( $was ) ) {
		return sprintf( '%s: set for the first time (%s)', $label, $size );
	}

	$old_lines = preg_split( '/\R/', $was );
	$new_lines = preg_split( '/\R/', $now );
	$total     = max( count( $old_lines ), count( $new_lines ) );

	for ( $i = 0; $i < $total; $i++ ) {
		$old_line = $old_lines[ $i ] ?? '';
		$new_line = $new_lines[ $i ] ?? '';
		if ( $old_line === $new_line ) {
			continue;
		}
		return sprintf(
			'%s: %s, first change on line %d: %s → %s',
			$label,
			$size,
			$i + 1,
			law_content_transfer_show( $old_line ),
			law_content_transfer_show( $new_line )
		);
	}

	// Same lines, different string: trailing whitespace or line endings only.
	return sprintf( '%s: %s (whitespace only)', $label, $size );
}

/**
 * One email's wording, applied over the code registry.
 *
 * Unlike the receptions, flagship and discounts, there is no saver to call:
 * the Emails screen writes the option inline. So this mirrors what
 * law_events_emails_handle_post() does — the same three keys, the same
 * sanitisers — rather than inventing a second shape for the same option.
 *
 * Diffed against the EFFECTIVE email (law_events_email(), registry plus any
 * stored override), not against the raw override. "No change" has to mean "this
 * site already sends these words", which is true whether they came from the
 * registry or from an earlier import.
 */
function law_content_transfer_run_email( $email, $dry, $actor ) {
	$slug = sanitize_key( (string) ( $email['slug'] ?? '' ) );
	$ref  = 'email ' . ( $slug ?: '(no slug)' );

	$row = array(
		'kind'    => 'Email',
		'ref'     => (string) ( $email['name'] ?? $slug ),
		'verdict' => 'No change',
		'changes' => array(),
	);

	if ( '' === $slug ) {
		$row['verdict'] = 'Skipped';
		$row['changes'] = array( 'The bundle row has no slug.' );
		law_migration_log( 'content_transfer', 'warning', $ref, 'Skipped: no slug.' );
		return $row;
	}

	// An email this site's code does not define. The bundle came from a site
	// running different code, so this is a deploy problem, not a data problem,
	// and saying so is more use than writing an override nothing will ever read.
	$current = law_events_email( $slug );
	if ( null === $current ) {
		$row['verdict'] = 'Skipped';
		$row['changes'] = array( sprintf( '"%s" is not an email this site defines. Deploy the same code here first.', $slug ) );
		law_migration_log( 'content_transfer', 'warning', $ref, 'Skipped: not in this site\'s registry.' );
		return $row;
	}

	$row['ref'] = (string) ( $current['name'] ?? $slug );

	$after = array(
		'subject' => sanitize_text_field( (string) ( $email['subject'] ?? '' ) ),
		// Through the shared sanitiser, not sanitize_textarea_field(): email
		// bodies carry formatting since 16 September 2026, and stripping it
		// here would have quietly flattened every bundle on the one path built
		// to carry email wording between sites.
		'body'    => law_events_email_body_sanitize( (string) ( $email['body'] ?? '' ) ),
		'active'  => ! empty( $email['active'] ),
	);
	$before = array(
		'subject' => (string) ( $current['subject'] ?? '' ),
		'body'    => (string) ( $current['body'] ?? '' ),
		'active'  => ! empty( $current['active'] ),
	);

	$row['changes'] = law_content_transfer_diff(
		$before,
		$after,
		array( 'subject' => 'Subject', 'active' => 'Active' )
	);

	// The body gets its own line rather than going through the shared diff,
	// which truncates at 80 characters. An email body is paragraphs, and two
	// rewrites of the same email routinely share their first 80 characters — so
	// the generic "old → new" would print the same string twice and read as
	// though nothing had changed, on the one field the operator most needs to
	// be sure about.
	if ( $before['body'] !== $after['body'] ) {
		$row['changes'][] = law_content_transfer_body_change( $before['body'], $after['body'] );
	}

	$stored   = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	$stored   = is_array( $stored ) ? $stored : array();
	$was_here = isset( $stored[ $slug ] );

	if ( ! $row['changes'] ) {
		return $row; // Already sends these words, from the registry or an earlier run.
	}

	$row['verdict'] = $was_here ? 'Update' : 'Create';

	if ( $dry ) {
		return $row;
	}

	// `to` is preserved from whatever this site already had, never taken from
	// the bundle (which does not carry it). An email whose recipients somebody
	// set here keeps them.
	$write = $after;
	if ( $was_here && isset( $stored[ $slug ]['to'] ) ) {
		$write['to'] = $stored[ $slug ]['to'];
	}

	$stored[ $slug ] = $write;
	update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $stored, false );

	law_migration_log(
		'content_transfer',
		'success',
		$ref,
		sprintf( '%s: %s', $row['verdict'], implode( '; ', $row['changes'] ) )
	);

	return $row;
}

/**
 * One reception, matched by slug.
 *
 * The record is created through law_event_ensure_managed_post() rather than by
 * letting law_reception_save() create it, because that saver derives a slug
 * from the title — and the slug is the key this whole format turns on, so it
 * has to come from the bundle.
 */
function law_content_transfer_run_reception( $reception, array $bundle, $dry, $actor ) {
	$slug  = sanitize_title( (string) ( $reception['slug'] ?? '' ) );
	$title = trim( (string) ( $reception['title'] ?? '' ) );
	$ref   = 'reception ' . ( $slug ?: '(no slug)' );

	$row = array(
		'kind'     => 'Reception',
		'ref'      => $slug . ( $title ? ' — ' . $title : '' ),
		'verdict'  => 'No change',
		'changes'  => array(),
		'event_id' => 0,
	);

	if ( '' === $slug || '' === $title ) {
		$row['verdict'] = 'Skipped';
		$row['changes'] = array( 'The bundle row has no slug or no title.' );
		law_migration_log( 'content_transfer', 'warning', $ref, 'Skipped: no slug or no title.' );
		return $row;
	}

	$existing = get_page_by_path( $slug, OBJECT, LAW_EVENT_CPT );
	$event_id = $existing instanceof WP_Post ? (int) $existing->ID : 0;
	$input    = law_content_transfer_reception_input( $reception );

	if ( ! $event_id ) {
		$row['verdict'] = 'Create';
		$row['changes'] = law_content_transfer_describe_reception( $input );
		if ( $dry ) {
			return $row;
		}
		$created = law_event_ensure_managed_post( $slug, $title, array( '_law_is_reception' => 1 ) );
		$event_id = (int) $created['id'];
		if ( ! $event_id ) {
			$row['verdict'] = 'Failed';
			$row['changes'] = array( (string) $created['message'] );
			law_migration_log( 'content_transfer', 'error', $ref, (string) $created['message'] );
			return $row;
		}
	} elseif ( ! law_reception_is( $event_id ) ) {
		$row['verdict'] = 'Skipped';
		$row['changes'] = array( sprintf( 'Post %d already holds the slug "%s" and is not a reception. Nothing was written.', $event_id, $slug ) );
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( 'Slug clash: post %d is not a reception.', $event_id ) );
		return $row;
	}

	$row['event_id']       = $event_id;
	$before                = law_reception_snapshot( $event_id );
	$before['description'] = law_content_transfer_stored_description( $event_id );

	// Only an existing reception reaches here in dry mode: a Create returned above.
	if ( $dry ) {
		$changes        = law_content_transfer_diff( $before, law_content_transfer_reception_after( $input, $event_id ), law_content_transfer_reception_labels() );
		$row['verdict'] = $changes ? 'Update' : 'No change';
		$row['changes'] = $changes;
		return $row;
	}

	$input['event_id'] = $event_id;
	$saved             = law_reception_save( $input, $actor );
	if ( is_wp_error( $saved ) ) {
		$row['verdict'] = 'Failed';
		$row['changes'] = array( $saved->get_error_message() );
		law_migration_log( 'content_transfer', 'error', $ref, $saved->get_error_message() );
		return $row;
	}

	$after                = law_reception_snapshot( $event_id );
	$after['description'] = law_content_transfer_stored_description( $event_id );
	$changes              = law_content_transfer_diff( $before, $after, law_content_transfer_reception_labels() );

	if ( 'Create' !== $row['verdict'] ) {
		$row['verdict'] = $changes ? 'Update' : 'No change';
	}
	$row['changes'] = $changes ?: $row['changes'];
	law_migration_log(
		'content_transfer',
		'Create' === $row['verdict'] ? 'created' : ( $changes ? 'created' : 'skipped' ),
		$ref,
		$changes ? implode( '; ', $changes ) : 'Already matched the bundle.'
	);

	return $row;
}

/** The bundle row as law_reception_save() input. */
function law_content_transfer_reception_input( $reception ) {
	return array(
		'event_id'    => 0,
		'title'       => sanitize_text_field( (string) ( $reception['title'] ?? '' ) ),
		'description' => law_rich_text_sanitize( $reception['description'] ?? '' ),
		'date'        => (string) ( $reception['date'] ?? '' ),
		'start'       => (string) ( $reception['start'] ?? '' ),
		'end'         => (string) ( $reception['end'] ?? '' ),
		'venue'       => sanitize_text_field( (string) ( $reception['venue'] ?? '' ) ),
		'places'      => (string) (int) ( $reception['places'] ?? 0 ),
		'price'       => (string) ( $reception['price'] ?? '' ),
		'show'        => ! empty( $reception['show'] ),
		'included'    => ! empty( $reception['included'] ),
		'invitation'  => ! empty( $reception['invitation'] ),
	);
}

/**
 * What law_reception_save() would leave behind, in snapshot shape.
 *
 * Mirrors the saver field for field, including its two quiet rules: an empty
 * price leaves the stored one alone, and the registration state is derived
 * from the invitation tick and the price rather than stored independently.
 */
function law_content_transfer_reception_after( array $input, $event_id ) {
	$date  = (string) law_events_sanitize_value( $input['date'], 'date' );
	$start = (string) law_events_sanitize_value( $input['start'], 'time' );
	$end   = (string) law_events_sanitize_value( $input['end'], 'time' );

	$price = law_events_pounds_to_pence( $input['price'] );
	if ( null === $price || '' === trim( (string) $input['price'] ) ) {
		$price = (int) ( $event_id ? law_event_meta( $event_id, '_law_attendee_price_pence' ) : 0 );
	}

	return array(
		'title'       => $input['title'],
		'description' => $input['description'],
		'status'      => $input['show'] ? 'publish' : 'law-draft',
		'start'       => '' !== $date ? ( '' !== $start ? $date . ' ' . $start : $date . ' 00:00' ) : (string) ( $event_id ? law_event_meta( $event_id, '_law_start' ) : '' ),
		'end'         => '' !== $date ? ( '' !== $end ? $date . ' ' . $end : '' ) : (string) ( $event_id ? law_event_meta( $event_id, '_law_end' ) : '' ),
		'venue'       => $input['venue'],
		'places'      => (int) $input['places'],
		'price'       => (int) $price,
		'included'    => (int) (bool) $input['included'],
		'state'       => $input['invitation'] ? 'invitation' : ( $price > 0 ? 'open' : 'free' ),
		'reception'   => 1,
	);
}

function law_content_transfer_reception_labels() {
	return array(
		'title'       => 'Title',
		'description' => 'Description',
		'status'      => 'On the programme',
		'start'       => 'Starts',
		'end'         => 'Ends',
		'venue'       => 'Venue',
		'places'      => 'Places',
		'price'       => 'Price (pence, excluding VAT)',
		'included'    => 'Included with a flagship place',
		'state'       => 'How it is booked',
	);
}

/** The whole record spelled out, for a Create row where there is no "before". */
function law_content_transfer_describe_reception( array $input ) {
	return array(
		sprintf( 'Created as a new reception: %s', $input['title'] ),
		sprintf( '%s, %s–%s, %s', $input['date'] ?: 'no date', $input['start'] ?: '—', $input['end'] ?: '—', $input['venue'] ?: 'no venue' ),
		sprintf( '%d places, price %s', (int) $input['places'], '' !== $input['price'] ? '£' . $input['price'] : 'not set' ),
	);
}

/**
 * The flagship, its agenda and its speakers.
 *
 * One call to law_flagship_save() carries the lot: it deletes sessions the
 * payload did not claim, resolves every speaker row through
 * law_speaker_upsert() and calls law_flagship_recompute(). This function's
 * only real work is turning the bundle's inline speaker identities into rows
 * that saver understands, and fetching the images.
 */
function law_content_transfer_run_flagship( array $flagship, array $bundle, $dry, $actor ) {
	$ref = 'flagship';
	$row = array(
		'kind'    => 'Flagship',
		'ref'     => (string) ( $flagship['title'] ?? 'Flagship conference' ),
		'verdict' => 'No change',
		'changes' => array(),
	);

	$event_id = law_flagship_event_id();
	$creating = ! $event_id;

	if ( $creating && $dry ) {
		$row['verdict'] = 'Create';
		$row['changes'] = array( 'There is no flagship event on this site yet; one will be created at /events/flagship/.' );
	}

	if ( ! $event_id && ! $dry ) {
		$created  = law_flagship_ensure_post();
		$event_id = (int) $created['id'];
		if ( ! $event_id ) {
			$row['verdict'] = 'Failed';
			$row['changes'] = array( (string) $created['message'] );
			law_migration_log( 'content_transfer', 'error', $ref, (string) $created['message'] );
			return $row;
		}
	}

	$notes = array();
	$input = law_content_transfer_flagship_input( $flagship, $bundle, $dry, $notes );

	if ( $dry ) {
		$before = $event_id ? law_flagship_snapshot( $event_id ) : law_content_transfer_empty_flagship_snapshot();
		if ( $event_id ) {
			$before['description'] = law_content_transfer_stored_description( $event_id );
		}
		$after = law_content_transfer_flagship_after( $input, $event_id );
		$changes = law_content_transfer_diff( $before, $after, law_content_transfer_flagship_labels() );
		$changes = array_merge( $changes, law_content_transfer_session_diff( $before, $flagship ), $notes );
		if ( ! $creating ) {
			$row['verdict'] = $changes ? 'Update' : 'No change';
		}
		$row['changes'] = array_merge( $row['changes'], $changes );
		return $row;
	}

	$before                = law_flagship_snapshot( $event_id );
	$before['description'] = law_content_transfer_stored_description( $event_id );
	$saved                 = law_flagship_save( $input, $actor );
	if ( is_wp_error( $saved ) ) {
		$row['verdict'] = 'Failed';
		$row['changes'] = array( $saved->get_error_message() );
		law_migration_log( 'content_transfer', 'error', $ref, $saved->get_error_message() );
		return $row;
	}

	$after                = law_flagship_snapshot( (int) $saved );
	$after['description'] = law_content_transfer_stored_description( (int) $saved );
	$changes              = law_content_transfer_diff( $before, $after, law_content_transfer_flagship_labels() );
	$changes = array_merge( $changes, law_content_transfer_session_change( $before, $after ), $notes );

	$row['verdict'] = $creating ? 'Create' : ( $changes ? 'Update' : 'No change' );
	$row['changes'] = $changes;
	law_migration_log(
		'content_transfer',
		$changes ? 'created' : 'skipped',
		$ref,
		$changes ? implode( '; ', $changes ) : 'Already matched the bundle.'
	);

	return $row;
}

/**
 * The bundle's flagship as law_flagship_save() input.
 *
 * Every speaker row goes in marked is_new. That is not a lie about the person:
 * law_speaker_upsert() reads it as "match or create", and it already dedupes
 * by email first and normalised name second. A speaker who already exists on
 * this site is therefore reused, their shared profile is gap-filled rather
 * than overwritten, and the per-appearance details (role, organisation, job
 * title, biography, photo) are written onto the event row, which is where they
 * belong.
 *
 * @param string[] $notes Collected image notes, by reference.
 */
function law_content_transfer_flagship_input( array $flagship, array $bundle, $dry, array &$notes ) {
	$hero = law_content_transfer_image( $flagship['hero_image'] ?? null, $bundle, 'flagship banner', $dry );
	if ( '' !== $hero['note'] ) {
		$notes[] = 'Banner image: ' . $hero['note'];
	}

	// A dry run has not fetched anything, and an apply that could not fetch the
	// banner must not blank the one already on the site.
	$hero_id = $hero['id'];
	if ( ! $hero_id ) {
		$event_id = law_flagship_event_id();
		$hero_id  = $event_id ? absint( law_event_meta( $event_id, '_law_hero_image_id' ) ) : 0;
	}

	$sessions = array();
	foreach ( (array) ( $flagship['sessions'] ?? array() ) as $index => $session ) {
		if ( ! is_array( $session ) ) {
			continue;
		}
		$session_ref = sprintf( 'flagship session %d', (int) $index + 1 );

		$speakers = array();
		foreach ( (array) ( $session['speakers'] ?? array() ) as $speaker ) {
			if ( ! is_array( $speaker ) ) {
				continue;
			}
			$first = sanitize_text_field( (string) ( $speaker['first_name'] ?? '' ) );
			$last  = sanitize_text_field( (string) ( $speaker['last_name'] ?? '' ) );
			if ( '' === trim( $first . $last ) ) {
				continue; // law_speaker_upsert() would refuse it anyway.
			}

			$photo = law_content_transfer_image( $speaker['photo'] ?? null, $bundle, $session_ref, $dry );
			if ( '' !== $photo['note'] ) {
				$notes[] = sprintf( '%s, %s %s: %s', $session_ref, $first, $last, $photo['note'] );
			}

			$speakers[] = array(
				'speaker_id'   => 0,
				'is_new'       => true,
				'first_name'   => $first,
				'last_name'    => $last,
				'email'        => sanitize_email( (string) ( $speaker['email'] ?? '' ) ),
				'website'      => esc_url_raw( (string) ( $speaker['website'] ?? '' ) ),
				'role'         => law_speaker_role_key( $speaker['role'] ?? '' ),
				'organisation' => sanitize_text_field( (string) ( $speaker['organisation'] ?? '' ) ),
				'job_title'    => sanitize_text_field( (string) ( $speaker['job_title'] ?? '' ) ),
				'photo_id'     => (int) $photo['id'],
				'bio'          => law_rich_text_sanitize( $speaker['bio'] ?? '' ),
			);
		}

		$sessions[] = array(
			'id'          => 0,
			'title'       => sanitize_text_field( (string) ( $session['title'] ?? '' ) ),
			'start'       => (string) law_events_sanitize_value( $session['start'] ?? '', 'time' ),
			'end'         => (string) law_events_sanitize_value( $session['end'] ?? '', 'time' ),
			'description' => law_rich_text_sanitize( $session['description'] ?? '' ),
			'speakers'    => $speakers,
		);
	}

	$input = array(
		'title'            => sanitize_text_field( (string) ( $flagship['title'] ?? '' ) ),
		'description'      => law_rich_text_sanitize( $flagship['description'] ?? '' ),
		'date'             => (string) law_events_sanitize_value( $flagship['date'] ?? '', 'date' ),
		'venue'            => sanitize_text_field( (string) ( $flagship['venue'] ?? '' ) ),
		'hero_image_id'    => (int) $hero_id,
		'show'             => ! empty( $flagship['show'] ),
		'places'           => absint( $flagship['places'] ?? 0 ),
		'price'            => trim( (string) ( $flagship['price'] ?? '' ) ),
		'price_late'       => trim( (string) ( $flagship['price_late'] ?? '' ) ),
		'price_switch'     => trim( (string) ( $flagship['price_switch'] ?? '' ) ),
		'sessions_present' => true,
		'sessions'         => $sessions,
	);

	// The terms link travels as a path and is resolved back to a page HERE.
	// A path that matches no page on this site is left out of the input
	// entirely, which the saver reads as "not being edited" — better than
	// writing a link that goes nowhere.
	$terms = trim( (string) ( $flagship['attendee_terms'] ?? '' ) );
	if ( '' !== $terms ) {
		$page = get_page_by_path( trim( (string) wp_parse_url( $terms, PHP_URL_PATH ), '/' ) );
		if ( $page instanceof WP_Post ) {
			$input['attendee_terms'] = (string) get_permalink( $page );
		} else {
			$notes[] = sprintf( 'Registration terms page "%s" does not exist on this site, so the setting was left alone.', $terms );
		}
	}

	return $input;
}

/** What law_flagship_save() would leave behind, in snapshot shape. */
function law_content_transfer_flagship_after( array $input, $event_id ) {
	$price      = law_events_pounds_to_pence( $input['price'] );
	$price_late = law_events_pounds_to_pence( $input['price_late'] );
	$switch     = law_flagship_parse_price_switch( $input['price_switch'] );

	return array(
		'title'        => $input['title'],
		'description'  => $input['description'],
		'status'       => $input['show'] ? 'publish' : 'law-draft',
		'date'         => $input['date'],
		'venue'        => $input['venue'],
		'hero'         => (int) $input['hero_image_id'],
		'places'       => (int) $input['places'],
		'price'        => null !== $price ? $price : (int) ( $event_id ? get_post_meta( $event_id, '_law_flagship_price_pence', true ) : 0 ),
		'price_late'   => null !== $price_late ? $price_late : (int) ( $event_id ? get_post_meta( $event_id, '_law_flagship_price_late_pence', true ) : 0 ),
		'price_switch' => null !== $switch ? $switch : (string) ( $event_id ? law_event_meta( $event_id, '_law_flagship_price_switch' ) : '' ),
		'terms'        => array_key_exists( 'attendee_terms', $input ) ? $input['attendee_terms'] : (string) law_events_setting( 'attendee_terms_page', '' ),
	);
}

function law_content_transfer_flagship_labels() {
	return array(
		'title'        => 'Title',
		'description'  => 'Description',
		'status'       => 'On the programme',
		'date'         => 'Date',
		'venue'        => 'Venue',
		'places'       => 'Places',
		'price'        => 'Early price (pence, excluding VAT)',
		'price_late'   => 'Late price (pence, excluding VAT)',
		'price_switch' => 'Price switches',
		'terms'        => 'Registration terms page',
	);
}

/** An empty "before" for a site with no flagship post at all. */
function law_content_transfer_empty_flagship_snapshot() {
	return array(
		'title' => '', 'description' => '', 'status' => '', 'date' => '', 'venue' => '',
		'hero' => 0, 'places' => 0, 'price' => 0, 'price_late' => 0, 'price_switch' => '',
		'terms' => (string) law_events_setting( 'attendee_terms_page', '' ),
		'sessions' => array(), 'speakers' => array(),
	);
}

/** The agenda, as a dry run can see it: counts and titles. */
function law_content_transfer_session_diff( array $before, array $flagship ) {
	$have = array_values( array_map( 'strval', (array) ( $before['sessions'] ?? array() ) ) );
	$want = array();
	foreach ( (array) ( $flagship['sessions'] ?? array() ) as $session ) {
		$want[] = (string) ( $session['title'] ?? '' );
	}

	if ( $have === $want ) {
		return array();
	}

	$lines   = array( sprintf( 'Session agenda: %d session(s) → %d session(s)', count( $have ), count( $want ) ) );
	$added   = array_values( array_diff( $want, $have ) );
	$removed = array_values( array_diff( $have, $want ) );
	if ( $added ) {
		$lines[] = 'Added: ' . implode( '; ', array_slice( $added, 0, 8 ) ) . ( count( $added ) > 8 ? ' …' : '' );
	}
	if ( $removed ) {
		$lines[] = 'Removed: ' . implode( '; ', array_slice( $removed, 0, 8 ) ) . ( count( $removed ) > 8 ? ' …' : '' );
	}

	return $lines;
}

/** The agenda, as the real run actually left it. */
function law_content_transfer_session_change( array $before, array $after ) {
	$lines = array();
	$was   = array_values( array_map( 'strval', (array) ( $before['sessions'] ?? array() ) ) );
	$now   = array_values( array_map( 'strval', (array) ( $after['sessions'] ?? array() ) ) );
	if ( $was !== $now ) {
		$lines[] = sprintf( 'Session agenda: %d session(s) → %d session(s)', count( $was ), count( $now ) );
	}

	$speakers_was = array_values( array_map( 'strval', (array) ( $before['speakers'] ?? array() ) ) );
	$speakers_now = array_values( array_map( 'strval', (array) ( $after['speakers'] ?? array() ) ) );
	if ( $speakers_was !== $speakers_now ) {
		$lines[] = sprintf( 'Speakers: %d → %d', count( $speakers_was ), count( $speakers_now ) );
	}

	if ( (int) ( $before['hero'] ?? 0 ) !== (int) ( $after['hero'] ?? 0 ) ) {
		$lines[] = 'Banner image replaced';
	}

	return $lines;
}

/* Hosted and external events ________________________________________________ */

/**
 * The local post a bundle row means, or 0.
 *
 * Gravity Forms entry ID first, slug second. The entry ID is the stronger key
 * by a distance: both sites build their programme by running the same migration
 * against the same Gravity Forms entries, so entry 190 on form 2 (Event >
 * submit an event) is the same event here and there whatever either site did to
 * the title afterwards. The slug is the fallback for an event created in the
 * module since, which has no entry behind it — weaker, because a slug moves
 * when a title is edited before the post is first published, but it is the only
 * other thing the two sites can agree on.
 *
 * @return int 0 when this site has no such event.
 */
function law_content_transfer_find_event( array $event ) {
	$entry_id = absint( $event['gf_entry_id'] ?? 0 );
	if ( $entry_id ) {
		$found = get_posts(
			array(
				'post_type'        => LAW_EVENT_CPT,
				'post_status'      => law_event_all_status_keys(),
				'meta_key'         => '_law_gf_entry_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'       => $entry_id,          // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'no_found_rows'    => true,
				'suppress_filters' => false,
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}
	}

	$slug = sanitize_title( (string) ( $event['slug'] ?? '' ) );
	if ( '' === $slug ) {
		return 0;
	}
	$post = get_page_by_path( $slug, OBJECT, LAW_EVENT_CPT );

	return $post instanceof WP_Post ? (int) $post->ID : 0;
}

/**
 * One hosted or external event: update it, or create it if this site has none.
 *
 * Never deletes and never touches an event the bundle does not name (Denis,
 * 16 September 2026). The run is an overlay on top of whatever the Gravity
 * Forms migration has already built, not a mirror of the source site.
 */
function law_content_transfer_run_event( $event, array $bundle, $dry, $actor ) {
	$event    = (array) $event;
	$external = ! empty( $event['external'] );
	$title    = trim( (string) ( $event['title'] ?? '' ) );
	$label    = trim( (string) ( $event['reference'] ?? '' ) );
	// Set from the bundle's claim here and corrected below, once this site's own
	// classification has been read, so the log line and the results table cannot
	// disagree about what kind of event a misclassified row is.
	$ref      = 'event ' . ( $label ?: ( $event['slug'] ?? '?' ) );

	$row = array(
		'kind'     => $external ? 'External event' : 'Hosted event',
		'ref'      => ( $label ? $label . ' — ' : '' ) . ( $title ?: '(untitled)' ),
		'verdict'  => 'No change',
		'changes'  => array(),
		'event_id' => 0,
	);

	if ( '' === $title ) {
		$row['verdict'] = 'Skipped';
		$row['changes'] = array( 'The bundle row has no title.' );
		law_migration_log( 'content_transfer', 'warning', $ref, 'Skipped: no title.' );
		return $row;
	}

	$event_id = law_content_transfer_find_event( $event );

	// A reception or the flagship answering to the same slug. Their own keys
	// own them, and their savers do things this one knows nothing about, so the
	// row is refused rather than written through the generic path.
	if ( $event_id && ( law_reception_is( $event_id ) || law_flagship_is( $event_id ) ) ) {
		$row['verdict'] = 'Skipped';
		$row['changes'] = array( sprintf( 'Post %d is one of LAW\'s own events (a reception or the flagship) and is handled by its own row. Nothing was written.', $event_id ) );
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( 'Skipped: post %d is a reception or the flagship.', $event_id ) );
		return $row;
	}

	$creating = ! $event_id;
	$notes    = array();
	$values   = law_content_transfer_event_values( $event, $bundle, $dry, $notes );

	// WHAT THE EVENT IS HERE, read before this run writes anything, versus what
	// the bundle SAYS it is. Only the first may decide whether the status
	// travels; the second is a claim in an uploaded file. On a create there is
	// nothing stored yet, so the claim is all there is — and safe, because a new
	// post has no workflow behind it to bypass.
	$claimed  = $external;
	$external = $creating ? $external : (bool) get_post_meta( $event_id, '_law_is_external', true );
	$row['kind'] = $external ? 'External event' : 'Hosted event';
	$ref         = ( $external ? 'external event ' : 'event ' ) . ( $label ?: ( $event['slug'] ?? '?' ) );
	if ( ! $creating && $claimed !== $external ) {
		$notes[] = sprintf(
			'Classified as %s here and %s on the other site. An import does not reclassify an event, because that is what decides whether its status is a workflow decision — change it on the committee dashboard if it is wrong.',
			$external ? 'an external event' : 'a hosted event',
			$claimed ? 'an external event' : 'a hosted event'
		);
	}

	// Status, reported either way and written only where it is not a workflow
	// decision. See the file header.
	$status = (string) ( $event['status'] ?? '' );
	if ( ! array_key_exists( $status, law_event_statuses() ) ) {
		$status = '';
	}

	if ( ! $creating && $values['owner_id'] && $event_id
		&& (int) $values['owner_id'] !== (int) get_post_field( 'post_author', $event_id ) ) {
		$notes[] = sprintf(
			'Owner left alone: %s here, %s on the other site. Ownership is who can open the event, so an import only sets it on an event it creates — reassign it in wp-admin if it is wrong.',
			law_content_transfer_show( law_content_transfer_user_email( (int) get_post_field( 'post_author', $event_id ) ) ),
			law_content_transfer_show( law_content_transfer_user_email( (int) $values['owner_id'] ) )
		);
	}

	if ( $dry ) {
		$before  = $event_id ? law_content_transfer_event_snapshot( $event_id ) : law_content_transfer_empty_event_snapshot();
		$after   = law_content_transfer_event_after( $values, $event_id, $external, $creating, $status );
		$changes = law_content_transfer_diff( $before, $after, law_content_transfer_event_labels() );
		$changes = array_merge(
			$changes,
			law_content_transfer_event_description_change( $before, $after ),
			law_content_transfer_event_agenda_diff( $before, $values ),
			law_content_transfer_event_status_notes( $before['status'], $status, $external, $creating ),
			$notes
		);

		$row['event_id'] = $event_id;
		$row['verdict']  = $creating ? 'Create' : ( $changes ? 'Update' : 'No change' );
		$row['changes']  = $changes;
		return $row;
	}

	if ( $creating ) {
		// A NEW post may be inserted at any status: the wp_insert_post_data
		// guard passes new inserts straight through, and nothing fires — no
		// transition, no email, no Stripe call. That is why a created event may
		// carry the source site's status while an existing one may not.
		$insert = array(
			'post_type'    => LAW_EVENT_CPT,
			'post_status'  => $status ?: 'law-draft',
			'post_title'   => $values['title'],
			'post_name'    => sanitize_title( (string) ( $event['slug'] ?? '' ) ),
			'post_content' => $values['description'],
			'post_author'  => $values['owner_id'] ?: (int) $actor,
		);
		$created_at = (string) law_events_sanitize_value( $event['created'] ?? '', 'datetime' );
		if ( '' !== $created_at ) {
			// Both halves, or core derives post_date from the server clock and
			// the two disagree by whatever the site's offset is.
			$insert['post_date_gmt'] = $created_at . ':00';
			$insert['post_date']     = get_date_from_gmt( $created_at . ':00' );
		}
		$event_id = wp_insert_post( wp_slash( $insert ), true );
		if ( is_wp_error( $event_id ) ) {
			$row['verdict'] = 'Failed';
			$row['changes'] = array( $event_id->get_error_message() );
			law_migration_log( 'content_transfer', 'error', $ref, $event_id->get_error_message() );
			return $row;
		}
		$event_id = (int) $event_id;
		// Before anything else: law_event_is_managed_by_law() reads it, and the
		// status guard's exemption for external events depends on it.
		if ( $external ) {
			law_event_update_meta( $event_id, '_law_is_external', 1 );
		}
		// The entry ID is the key every later import matches on, so a created
		// event has to carry the one it came with or the next run would create
		// a second copy.
		$entry_id = absint( $event['gf_entry_id'] ?? 0 );
		if ( $entry_id ) {
			law_event_update_meta( $event_id, '_law_gf_entry_id', $entry_id );
		}
		law_events_ensure_reference( $event_id );

		// The slug is the fallback key, so a slug WordPress had to uniquify
		// ("drinks" already taken, stored as "drinks-2") would make the next
		// import of the same bundle create a second copy rather than matching
		// this one. It cannot happen silently: a law_event already holding the
		// slug would have been found above, so this only fires against a
		// collision outside the post type. Reported rather than worked around,
		// because the fix is a human deciding which record should own the slug.
		$wanted = sanitize_title( (string) ( $event['slug'] ?? '' ) );
		$given  = (string) get_post_field( 'post_name', $event_id );
		if ( '' !== $wanted && $wanted !== $given ) {
			$notes[] = sprintf(
				'The slug "%s" was already in use here, so this event was created as "%s". It carries %s, so re-importing the same file will %s.',
				$wanted,
				$given,
				$entry_id ? 'the Gravity Forms entry ID' : 'no entry ID',
				$entry_id ? 'still match it' : 'create a second copy unless the slug is corrected'
			);
		}
	}

	$row['event_id'] = $event_id;
	$before          = law_content_transfer_event_snapshot( $event_id );

	law_content_transfer_write_event( $event_id, $values, $external, $creating, $status, $actor, $notes );

	$after   = law_content_transfer_event_snapshot( $event_id );
	$changes = law_content_transfer_diff( $before, $after, law_content_transfer_event_labels() );
	$changes = array_merge(
		$changes,
		law_content_transfer_event_description_change( $before, $after ),
		law_content_transfer_event_status_notes( $before['status'], $status, $external, $creating ),
		$notes
	);

	$row['verdict'] = $creating ? 'Create' : ( $changes ? 'Update' : 'No change' );
	$row['changes'] = $changes;

	if ( $changes || $creating ) {
		// The event's own activity log, so a committee member reading the
		// history of one event sees where the values came from without having
		// to know a migration screen exists.
		law_event_log(
			$event_id,
			sprintf(
				'%s from a content transfer bundle made on %s: %s',
				$creating ? 'Created' : 'Updated',
				(string) ( $bundle['site']['url'] ?? 'another site' ),
				$changes ? implode( '; ', $changes ) : 'no field changed'
			),
			array( 'action' => 'content_transfer', 'source' => 'migration' ),
			array( 'user_id' => (int) $actor )
		);
	}

	law_migration_log(
		'content_transfer',
		$creating ? 'created' : ( $changes ? 'created' : 'skipped' ),
		$ref,
		$changes ? implode( '; ', $changes ) : 'Already matched the bundle.'
	);

	return $row;
}

/**
 * The bundle row, sanitised into exactly what would be written.
 *
 * Every meta value goes through law_events_sanitize_value() HERE rather than
 * only inside law_event_update_meta(), which is what lets the dry run promise
 * something real: the value shown in the preview is byte for byte the value the
 * apply will store, because both came out of the same sanitiser.
 *
 * A key the bundle does not carry is left out of the returned `meta` array
 * entirely, and the writer skips it. That is the difference between "the client
 * cleared this field" and "this bundle is older than that field", and getting
 * it wrong would let an old file blank a column nobody had touched.
 *
 * @param string[] $notes Collected notes, by reference.
 */
function law_content_transfer_event_values( array $event, array $bundle, $dry, array &$notes ) {
	// law_event_meta_schema() directly, not law_events_meta_type(), which needs
	// a post ID to read the type from — and a Create has none yet. Its fallback
	// searches every schema at once, where `_law_speakers` means one thing on an
	// event and another on a session; asking the event's own schema cannot be
	// ambiguous.
	$schema = law_event_meta_schema();
	$meta   = array();
	$raw    = (array) ( $event['meta'] ?? array() );
	foreach ( law_content_transfer_event_meta_keys() as $key ) {
		if ( ! array_key_exists( $key, $raw ) || ! isset( $schema[ $key ] ) ) {
			continue;
		}
		$meta[ $key ] = law_events_sanitize_value( $raw[ $key ], $schema[ $key ] );
	}

	// People, resolved by address. A missing account is a note, never a new
	// user: an import creating logins for people who have never heard of the
	// new site is a decision for a human (Denis, 16 September 2026).
	$owner_id = law_content_transfer_resolve_user( $event['owner_email'] ?? '' );
	if ( '' !== trim( (string) ( $event['owner_email'] ?? '' ) ) && ! $owner_id ) {
		$notes[] = sprintf( 'Owner "%s" has no account on this site, so the event keeps the owner it already had.', law_content_transfer_show( $event['owner_email'] ) );
	}
	$assignee_id = law_content_transfer_resolve_user( $event['assignee_email'] ?? '' );
	if ( '' !== trim( (string) ( $event['assignee_email'] ?? '' ) ) && ! $assignee_id ) {
		$notes[] = sprintf( 'Assignee "%s" has no account on this site, so the assignee was left alone.', law_content_transfer_show( $event['assignee_email'] ) );
	}

	// Organisations, resolved from slugs. Dropped rather than invented: this
	// module does not own the `organisation` post type and must not create
	// records in it.
	$organisation_ids = array();
	foreach ( (array) ( $event['organisations'] ?? array() ) as $slug ) {
		$slug = sanitize_title( (string) $slug );
		$org  = $slug ? get_page_by_path( $slug, OBJECT, 'organisation' ) : null;
		if ( $org instanceof WP_Post ) {
			$organisation_ids[] = (int) $org->ID;
		} elseif ( '' !== $slug ) {
			$notes[] = sprintf( 'Linked organisation "%s" does not exist on this site, so it was left off.', $slug );
		}
	}

	// What an image note names the event as, so a run over fifty events says
	// which one could not find its photograph.
	$label = (string) ( $event['reference'] ?? '' );
	if ( '' === $label ) {
		$label = (string) ( $event['slug'] ?? '' );
	}

	return array(
		'title'            => sanitize_text_field( (string) ( $event['title'] ?? '' ) ),
		'description'      => law_rich_text_sanitize( $event['description'] ?? '' ),
		'owner_id'         => $owner_id,
		'assignee_id'      => $assignee_id,
		'event_type'       => sanitize_text_field( (string) ( $event['event_type'] ?? '' ) ),
		'sectors'          => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $event['sectors'] ?? array() ) ), 'strlen' ) ),
		'organisation_ids' => $organisation_ids,
		'meta'             => $meta,
		'speakers'         => law_content_transfer_event_speaker_input( $event['speakers'] ?? array(), $bundle, $dry, $label, $notes ),
		'sessions'         => law_content_transfer_event_session_input( $event['sessions'] ?? array(), $bundle, $dry, $label, $notes ),
		// The two repeater sentinels, and the distinction they protect is the
		// same one a truncated POST needs: "the client deleted every speaker"
		// and "this file says nothing about speakers" must not be the same
		// input. Our own exporter always writes both keys, even when empty, so
		// an emptied agenda on staging really does clear production's; a bundle
		// that is silent — hand-edited, or written before the key existed —
		// leaves both alone.
		'speakers_present' => array_key_exists( 'speakers', $event ),
		'sessions_present' => array_key_exists( 'sessions', $event ),
	);
}

/**
 * Bundle speaker rows as law_flagship_resolve_speaker_rows() input.
 *
 * `is_new` on every row, for the reason rule 2 in the file header gives: it
 * means "match or create", law_speaker_upsert() dedupes by email then by
 * normalised name, and a speaker who already exists here is reused with their
 * shared profile gap-filled rather than overwritten.
 *
 * @param string[] $notes Collected image notes, by reference.
 */
function law_content_transfer_event_speaker_input( $rows, array $bundle, $dry, $ref, array &$notes ) {
	$out = array();

	foreach ( (array) $rows as $speaker ) {
		if ( ! is_array( $speaker ) ) {
			continue;
		}
		$first = sanitize_text_field( (string) ( $speaker['first_name'] ?? '' ) );
		$last  = sanitize_text_field( (string) ( $speaker['last_name'] ?? '' ) );
		if ( '' === trim( $first . $last ) ) {
			continue; // law_speaker_upsert() would refuse it anyway.
		}

		$photo = law_content_transfer_image( $speaker['photo'] ?? null, $bundle, (string) $ref, $dry );
		if ( '' !== $photo['note'] ) {
			$notes[] = sprintf( '%s %s: %s', $first, $last, $photo['note'] );
		}

		$out[] = array(
			'speaker_id'   => 0,
			'is_new'       => true,
			'first_name'   => $first,
			'last_name'    => $last,
			'email'        => sanitize_email( (string) ( $speaker['email'] ?? '' ) ),
			'website'      => esc_url_raw( (string) ( $speaker['website'] ?? '' ) ),
			'role'         => law_speaker_role_key( $speaker['role'] ?? '' ),
			'organisation' => sanitize_text_field( (string) ( $speaker['organisation'] ?? '' ) ),
			'job_title'    => sanitize_text_field( (string) ( $speaker['job_title'] ?? '' ) ),
			'photo_id'     => (int) $photo['id'],
			'bio'          => law_rich_text_sanitize( $speaker['bio'] ?? '' ),
		);
	}

	return $out;
}

/** Bundle session rows as law_flagship_save_sessions() input. */
function law_content_transfer_event_session_input( $rows, array $bundle, $dry, $ref, array &$notes ) {
	$out = array();

	foreach ( (array) $rows as $index => $session ) {
		if ( ! is_array( $session ) ) {
			continue;
		}
		$session_ref = sprintf( '%s, session %d', $ref, (int) $index + 1 );
		$out[]       = array(
			// Always 0: a session ID from the other site means nothing here, and
			// law_flagship_save_sessions() honours a posted ID only when the
			// session is already a child of this event. So an import rewrites
			// the agenda rather than editing it in place, which is the right
			// trade for a reconciler whose rows carry no stable key of their own.
			'id'          => 0,
			// Not part of law_flagship_save_sessions()'s input shape, which
			// ignores it; read back off the row afterwards to re-stamp the
			// session it created. See law_content_transfer_write_event().
			'gf_entry_id' => absint( $session['gf_entry_id'] ?? 0 ),
			'title'       => sanitize_text_field( (string) ( $session['title'] ?? '' ) ),
			'start'       => (string) law_events_sanitize_value( $session['start'] ?? '', 'time' ),
			'end'         => (string) law_events_sanitize_value( $session['end'] ?? '', 'time' ),
			'description' => law_rich_text_sanitize( $session['description'] ?? '' ),
			'speakers'    => law_content_transfer_event_speaker_input( $session['speakers'] ?? array(), $bundle, $dry, $session_ref, $notes ),
		);
	}

	return $out;
}

/** A user ID from an email address, or 0. Never creates one. */
function law_content_transfer_resolve_user( $email ) {
	$email = sanitize_email( (string) $email );
	if ( ! is_email( $email ) ) {
		return 0;
	}
	$user = get_user_by( 'email', $email );

	return $user ? (int) $user->ID : 0;
}

/**
 * Point the co-owner ACCOUNT LINKS at the rows — on an event this run created,
 * and only then.
 *
 * The module keeps three things in step: `_law_co_owner_rows` (what the host
 * typed), `_law_co_owner_ids` (the array the module reads) and one flat
 * `_law_co_owner` row per ID (what the dashboard's query matches, because a
 * REGEXP against the serialised array would confuse array keys with user IDs).
 * law_event_set_co_owner_ids() is the single write path for the last two, so
 * that is what this calls. An account is never created: a row whose address has
 * none here is reported.
 *
 * WHY AN EXISTING EVENT'S LINKS ARE LEFT ALONE, which is the same rule
 * post_author follows and for the same reason (security review, 16 September
 * 2026). law_user_can_manage_event() treats a co-owner exactly as it treats the
 * author: full edit and view rights, including the invoicing contact, address
 * and VAT number this very run writes. So linking somebody because a bundle
 * named their address is an unconsented grant of access to a real person, and
 * the fact that the ROW travels does not make the LINK a detail — the same
 * distinction the owner rule already draws.
 *
 * The argument that talked me into linking anyway was that approving the event
 * here would link the same people regardless. It is wrong exactly where it
 * matters: law_event_ensure_co_owner_users() runs on the approve transition and
 * on a save of an ALREADY-approved event, so on the live programme — every event
 * the client is actually editing — an import would have been the only grant
 * there was, with no human decision behind it. Reporting the difference instead
 * costs a committee member opening the event and saving it, which runs that
 * function properly, creates the missing accounts and sends the emails.
 *
 * @param bool     $creating Whether this run created the event.
 * @param string[] $notes    Collected notes, by reference.
 */
function law_content_transfer_link_co_owners( $event_id, $rows, $creating, array &$notes ) {
	$ids     = array();
	$unknown = array();

	foreach ( (array) $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$email = sanitize_email( (string) ( $row['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			continue; // A row with no address links to nothing on any site.
		}
		$user_id = law_content_transfer_resolve_user( $email );
		if ( $user_id ) {
			$ids[] = $user_id;
			continue;
		}
		$unknown[] = trim( (string) ( $row['name'] ?? '' ) ) ?: $email;
	}

	if ( ! $creating ) {
		$have = array_map( 'intval', law_event_meta( (int) $event_id, '_law_co_owner_ids' ) );
		sort( $have );
		$want = array_values( array_unique( $ids ) );
		sort( $want );
		if ( $have !== $want ) {
			$notes[] = 'Co-owner access left alone: the rows travelled, but who can OPEN the event did not. Open the event and save it on the committee dashboard to grant access properly, which also creates any missing accounts and emails them.';
		}
		return;
	}

	law_event_set_co_owner_ids( (int) $event_id, array_values( array_unique( $ids ) ) );

	if ( $unknown ) {
		$notes[] = sprintf(
			'%s: %s. They are on the event, but cannot open it until the committee approves it here, which is what creates the account.',
			1 === count( $unknown ) ? 'Co-owner with no account on this site' : 'Co-owners with no account on this site',
			implode( ', ', array_slice( $unknown, 0, 5 ) ) . ( count( $unknown ) > 5 ? ' and others' : '' )
		);
	}
}

/**
 * Write one event. The apply half of law_content_transfer_run_event().
 *
 * Every meta write goes through law_event_update_meta(), the single sanitising
 * write path the admin screens, the front-end forms and the migrator share, so
 * nothing here is a second validation rule.
 */
function law_content_transfer_write_event( $event_id, array $values, $external, $creating, $status, $actor, array &$notes ) {
	$post = array(
		'ID'           => (int) $event_id,
		'post_title'   => $values['title'],
		'post_content' => $values['description'],
	);

	// OWNERSHIP IS SET ON A CREATE ONLY (security review, 16 September 2026).
	// post_author is not a detail about the event, it is who can open it:
	// law_user_can_manage_event() gives the author full edit and view rights,
	// which includes the invoicing contact and address this same run writes. A
	// bundle that named somebody else's address would hand them the event, and
	// while the operator is already an administrator, "an administrator was
	// talked into uploading this file" is the threat model this whole file is
	// written against. A difference on an existing event is reported instead,
	// the way the workflow status is.
	if ( $creating && $values['owner_id'] ) {
		$post['post_author'] = (int) $values['owner_id'];
	}

	// An EXISTING event's status is reverted by the guard unless the saver
	// announces itself. An external event has no workflow, so it announces
	// itself exactly as law_external_event_save(), law_reception_save() and
	// law_flagship_save() do, and the guard honours the flag for publish and
	// law-draft only — which are the only two states an external event has. A
	// hosted event's status is never written here at all: the guard would
	// revert it, and it is right that it would.
	//
	// $external is what the event IS on this site, read before any of this run's
	// writes, never what the bundle claims to be. The two are the same on an
	// honest file and the difference is the whole P0: a bundle claiming
	// `external` against a hosted event used to announce the exemption to a
	// guard that (correctly) refused it, and then write `_law_is_external` 1
	// anyway, so the NEXT run found a genuinely external event and the write
	// went through. Judging on the stored value closes half of that; refusing to
	// write the flag on an existing event closes the other half, and either
	// alone would be enough.
	$managed = $external && ! $creating && in_array( $status, array( 'publish', 'law-draft' ), true );
	if ( $managed ) {
		$post['post_status']                 = $status;
		$GLOBALS['law_event_managed_saving'] = true;
	}
	// try/finally, not a bare unset: an exception thrown inside wp_update_post
	// (a filter on save_post, say) would otherwise leave the exemption standing
	// for every later save in the request.
	try {
		wp_update_post( wp_slash( $post ) );
	} finally {
		unset( $GLOBALS['law_event_managed_saving'] );
	}

	$seated = $creating ? 0 : law_event_attendee_total( $event_id );

	foreach ( $values['meta'] as $key => $value ) {
		// The classification stays put on an existing event. See the note on
		// law_content_transfer_event_meta_keys().
		if ( '_law_is_external' === $key && ! $creating ) {
			continue;
		}
		// PLACES AVAILABLE ARE NOT DESCRIPTIVE ONCE ANYBODY HAS BOOKED (Denis,
		// 17 September 2026). They are the booking and waitlist capacity: lower
		// them under confirmed bookings and the event is oversubscribed against
		// its own record, raise them and the waitlist should have been offered
		// the new seats — which an import cannot do, because it writes through
		// law_event_update_meta() rather than law_event_tickets_changed(). So
		// on an event that has seated anybody, the number is reported and left
		// alone. On one that has not, it is ordinary event data and travels;
		// that is every event whose bookings have not opened, which is what the
		// client is editing on staging.
		if ( '_law_tickets_available' === $key && $seated > 0 && (int) $value !== (int) law_event_meta( $event_id, $key ) ) {
			$notes[] = sprintf(
				'Places available left at %d (the file says %d): %s already booked on this site, and changing the capacity under them is a booking decision, not a detail. Change it on the committee dashboard, where raising it offers the new places to the waitlist.',
				(int) law_event_meta( $event_id, $key ),
				(int) $value,
				sprintf( _n( '%d person is', '%d people are', $seated, 'law' ), $seated )
			);
			continue;
		}
		law_event_update_meta( $event_id, $key, $value );
	}
	if ( $values['assignee_id'] ) {
		law_event_update_meta( $event_id, '_law_assignee', $values['assignee_id'] );
	}
	if ( $values['organisation_ids'] ) {
		law_event_update_meta( $event_id, '_law_organisation_ids', $values['organisation_ids'] );
	}

	// Co-owner ACCOUNT LINKS, reconciled from the rows just written. Every other
	// path that changes `_law_co_owner_rows` does this — the approve transition,
	// the host and committee forms, the wp-admin screen — through
	// law_event_ensure_co_owner_users(), which CREATES an account for a row that
	// has none. An import may not (Denis, 16 September 2026), and on an event it
	// did not create it may not grant the access either — a co-owner has the same
	// rights as the owner, so the rule is the owner's rule. See the function.
	if ( array_key_exists( '_law_co_owner_rows', $values['meta'] ) ) {
		law_content_transfer_link_co_owners( $event_id, $values['meta']['_law_co_owner_rows'], $creating, $notes );
	}

	// Terms by NAME and creating none: an unknown type or sector is dropped
	// rather than invented, the same rule every other write path follows.
	law_events_set_terms_by_name( $event_id, 'law_event_type', array( $values['event_type'] ) );
	law_events_set_terms_by_name( $event_id, 'law_sector', $values['sectors'] );
	$year = (string) law_events_setting( 'year', '' );
	if ( '' !== $year ) {
		wp_set_object_terms( $event_id, $year, 'law_year', false );
	}

	if ( $values['speakers_present'] ) {
		law_event_update_meta(
			$event_id,
			'_law_speakers',
			law_flagship_resolve_speaker_rows( $values['speakers'], (int) $event_id, (int) $actor )
		);
	}
	// A reconciler: law_flagship_save_sessions() deletes whatever the posted
	// rows do not claim, and every row here carries id 0 because a session ID
	// from the other site means nothing on this one. So an import REPLACES the
	// agenda rather than editing it in place, which is the right trade for rows
	// with no stable key of their own — and the reason the sentinel above has
	// to be honoured rather than inferred from an empty array.
	if ( $values['sessions_present'] ) {
		$kept = law_flagship_save_sessions( (int) $event_id, $values['sessions'], (int) $actor );
		law_content_transfer_restamp_sessions( $kept, $values['sessions'], $notes );
	}
}

/**
 * Give each recreated session back the Gravity Forms entry ID it came with.
 *
 * law_flagship_save_sessions() returns the surviving session IDs in the order
 * of the rows it was given, so position pairs them — but only while every row
 * survived, which is why an uneven count is reported rather than guessed at. A
 * mis-paired entry ID would be worse than none: the migration would then skip
 * the wrong session and duplicate another.
 *
 * @param int[]    $kept  law_flagship_save_sessions()'s return.
 * @param array[]  $rows  The input rows, in the same order.
 * @param string[] $notes Collected notes, by reference.
 */
function law_content_transfer_restamp_sessions( array $kept, array $rows, array &$notes ) {
	if ( count( $kept ) !== count( $rows ) ) {
		$notes[] = sprintf(
			'%d of %d sessions were saved, so their Gravity Forms entry IDs were not restored. Re-running the sessions migration step would duplicate this agenda; check the sessions on this event by hand.',
			count( $kept ),
			count( $rows )
		);
		return;
	}

	foreach ( $kept as $index => $session_id ) {
		$entry_id = absint( $rows[ $index ]['gf_entry_id'] ?? 0 );
		if ( $entry_id ) {
			law_event_update_meta( (int) $session_id, '_law_gf_entry_id', $entry_id );
		}
	}
}

/**
 * What a run might change, flattened to strings so the shared diff can compare
 * it. Every value is a string: law_content_transfer_diff() casts, and an array
 * reaching that cast would print "Array" and emit a notice.
 */
function law_content_transfer_event_snapshot( $event_id ) {
	$event_id = (int) $event_id;
	$post     = get_post( $event_id );

	$snapshot = array(
		'title'        => $post ? (string) $post->post_title : '',
		'description'  => $post ? (string) $post->post_content : '',
		'status'       => $post ? (string) $post->post_status : '',
		'owner'        => $post ? law_content_transfer_user_email( (int) $post->post_author ) : '',
		'assignee'     => law_content_transfer_user_email( absint( law_event_meta( $event_id, '_law_assignee' ) ) ),
		'event_type'   => (string) law_events_post_term_name( $event_id, 'law_event_type' ),
		'sectors'      => implode( ', ', law_events_post_term_names( $event_id, 'law_sector' ) ),
		'organisations' => implode( ', ', law_content_transfer_organisation_slugs( $event_id ) ),
		'speakers'     => (string) count( law_event_meta( $event_id, '_law_speakers' ) ),
		'sessions'     => (string) count( law_event_session_ids( $event_id ) ),
	);

	// Read through the SAME sanitiser the write goes through. Several keys have
	// a non-empty default — `_law_booking_override` becomes 'auto', an int
	// becomes 0, a float becomes 0.00 — so a key that has never been written
	// stores '' and reads back as its default the moment anything saves it.
	// Comparing the raw stored value against the sanitised incoming one would
	// therefore report "Override booking availability: not set → auto" on every
	// event nobody has touched, which on a hundred-row programme is enough noise
	// to stop an operator reading the preview at all.
	$schema = law_event_meta_schema();
	foreach ( law_content_transfer_event_meta_keys() as $key ) {
		$stored = law_event_meta( $event_id, $key );
		$snapshot[ $key ] = law_content_transfer_flatten(
			isset( $schema[ $key ] ) ? law_events_sanitize_value( $stored, $schema[ $key ] ) : $stored
		);
	}

	return $snapshot;
}

/** The "before" for an event this site does not have yet. */
function law_content_transfer_empty_event_snapshot() {
	$snapshot = array(
		'title' => '', 'description' => '', 'status' => '', 'owner' => '', 'assignee' => '',
		'event_type' => '', 'sectors' => '', 'organisations' => '', 'speakers' => '0', 'sessions' => '0',
	);
	foreach ( law_content_transfer_event_meta_keys() as $key ) {
		$snapshot[ $key ] = '';
	}

	return $snapshot;
}

/**
 * What the write above would leave behind, in snapshot shape.
 *
 * Mirrors law_content_transfer_write_event() decision for decision, including
 * the three places it deliberately does nothing: an unresolved owner or
 * assignee, an empty organisation list, and a hosted event's status.
 */
function law_content_transfer_event_after( array $values, $event_id, $external, $creating, $status ) {
	$event_id = (int) $event_id;

	$after = array(
		'title'       => $values['title'],
		'description' => $values['description'],
		// Set on a create only, so an existing event's "after" is what it
		// already had. The preview must not show a change the apply refuses.
		'owner'       => ( $creating && $values['owner_id'] )
			? law_content_transfer_user_email( $values['owner_id'] )
			: ( $event_id ? law_content_transfer_user_email( (int) get_post_field( 'post_author', $event_id ) ) : '' ),
		'assignee'    => $values['assignee_id']
			? law_content_transfer_user_email( $values['assignee_id'] )
			: ( $event_id ? law_content_transfer_user_email( absint( law_event_meta( $event_id, '_law_assignee' ) ) ) : '' ),
		'event_type'  => $values['event_type'],
		'sectors'     => implode( ', ', $values['sectors'] ),
		'speakers'    => $values['speakers_present']
			? (string) count( $values['speakers'] )
			: ( $event_id ? (string) count( law_event_meta( $event_id, '_law_speakers' ) ) : '0' ),
	);

	// Only written when there is something to write, so the "after" has to fall
	// back to what is stored rather than to nothing.
	$after['organisations'] = $values['organisation_ids']
		? implode( ', ', array_filter( array_map( fn( $id ) => (string) get_post_field( 'post_name', $id ), $values['organisation_ids'] ) ) )
		: ( $event_id ? implode( ', ', law_content_transfer_organisation_slugs( $event_id ) ) : '' );

	$after['sessions'] = $values['sessions_present']
		? (string) count( $values['sessions'] )
		: ( $event_id ? (string) count( law_event_session_ids( $event_id ) ) : '0' );

	// The status rule, in one expression: a created event takes the bundle's,
	// an external event takes the bundle's two-state tick, a hosted event keeps
	// whatever the far site's own workflow put there.
	if ( $creating ) {
		$after['status'] = $status ?: 'law-draft';
	} elseif ( $external && in_array( $status, array( 'publish', 'law-draft' ), true ) ) {
		$after['status'] = $status;
	} else {
		$after['status'] = $event_id ? (string) get_post_status( $event_id ) : '';
	}

	// A key the bundle does not carry is not written, so the "after" is
	// whatever is stored — normalised the same way the snapshot normalises it,
	// or a key the file is silent about would report a change of its own.
	$schema = law_event_meta_schema();
	foreach ( law_content_transfer_event_meta_keys() as $key ) {
		// Same rule as the owner: the classification is not written on an
		// existing event, so it must not appear in the preview as if it were.
		$writes = array_key_exists( $key, $values['meta'] )
			&& ! ( '_law_is_external' === $key && ! $creating );
		if ( $writes ) {
			$after[ $key ] = law_content_transfer_flatten( $values['meta'][ $key ] );
			continue;
		}
		$stored        = $event_id ? law_event_meta( $event_id, $key ) : '';
		$after[ $key ] = law_content_transfer_flatten(
			isset( $schema[ $key ] ) ? law_events_sanitize_value( $stored, $schema[ $key ] ) : $stored
		);
	}

	// Mirrors the places guard in the writer, so the preview cannot promise a
	// capacity change the apply refuses.
	if ( ! $creating && $event_id && law_event_attendee_total( $event_id ) > 0 ) {
		$after['_law_tickets_available'] = law_content_transfer_flatten( law_event_meta( $event_id, '_law_tickets_available' ) );
	}

	return $after;
}

/**
 * The status line the preview prints when the two sites disagree about a hosted
 * event, and the warning a created one earns.
 *
 * This is the only place in the file that reports something it is deliberately
 * NOT doing. It earns the space: a committee member who sees "Approved there,
 * Proposed here" can go and approve it properly, which raises the invoice and
 * emails the host; without the line they would never know the disagreement
 * existed.
 *
 * @return string[]
 */
function law_content_transfer_event_status_notes( $before, $status, $external, $creating ) {
	if ( '' === (string) $status ) {
		return array();
	}

	if ( $creating ) {
		if ( in_array( $status, array( 'law-approved', 'publish' ), true ) ) {
			return array(
				sprintf(
					'Created as %s, which is the status it had on the other site. No Stripe invoice was raised and no host email was sent, because no approval happened here — put it through the committee dashboard if it is meant to be invoiced.',
					law_event_status_label( $status )
				),
			);
		}
		return array();
	}

	if ( $external || (string) $before === (string) $status ) {
		return array();
	}

	return array(
		sprintf(
			'Status left alone: %s here, %s on the other site. A hosted event\'s status is a workflow decision, so it is not imported — make the change on the committee dashboard, where it raises the invoice and emails the host.',
			law_event_status_label( (string) $before ),
			law_event_status_label( (string) $status )
		),
	);
}

/**
 * The description, described rather than quoted twice.
 *
 * Almost always a rewrite on staging, and occasionally something quieter: the
 * allowlist in law_rich_text_sanitize() drops attributes the legacy Gravity
 * Forms descriptions carry (a stray `class` from a pasted editor), so importing
 * one cleans it. That is a real change and belongs in the preview, but it is
 * one an operator should be able to recognise as cosmetic from the character
 * count alone rather than by squinting at two identical-looking truncations.
 *
 * @return string[] Empty when nothing changed.
 */
function law_content_transfer_event_description_change( array $before, array $after ) {
	$was = (string) ( $before['description'] ?? '' );
	$now = (string) ( $after['description'] ?? '' );

	return $was === $now ? array() : array( law_content_transfer_body_change( $was, $now, 'Description' ) );
}

/** The agenda and speakers, as a dry run can see them: counts and titles. */
function law_content_transfer_event_agenda_diff( array $before, array $values ) {
	$lines = array();

	if ( $values['speakers_present'] && (string) ( $before['speakers'] ?? '0' ) !== (string) count( $values['speakers'] ) ) {
		$lines[] = sprintf( 'Speakers: %s → %d', $before['speakers'] ?? '0', count( $values['speakers'] ) );
	}
	if ( $values['sessions_present'] && (string) ( $before['sessions'] ?? '0' ) !== (string) count( $values['sessions'] ) ) {
		$lines[] = sprintf( 'Session agenda: %s session(s) → %d session(s)', $before['sessions'] ?? '0', count( $values['sessions'] ) );
	}

	return $lines;
}

/** The label each snapshot key prints under, and the order they print in. */
function law_content_transfer_event_labels() {
	return array_merge(
		array(
			'title'         => 'Title',
			'status'        => 'Status',
			// `description` is deliberately absent: it goes through
			// law_content_transfer_body_change() instead, for the reason the
			// email bodies do. Two versions of one paragraph routinely share
			// their first 80 characters, so the generic "old → new" prints the
			// same truncated string twice on the one field an operator most
			// needs to be able to judge.
			'owner'         => 'Owner',
			'assignee'      => 'Assignee',
			'event_type'    => 'Event type',
			'sectors'       => 'Sectors',
			'organisations' => 'Linked organisations',
		),
		array(
			'_law_start'               => 'Starts',
			'_law_end'                 => 'Ends',
			'_law_slot_label'          => 'Slot',
			'_law_preferred_slots'     => 'Preferred slots',
			'_law_venue'               => 'Venue',
			'_law_venue_needed'        => 'Venue needed',
			'_law_venue_capacity'      => 'Venue capacity',
			'_law_tickets_available'   => 'Places available',
			'_law_host_organisations'  => 'Host organisation(s)',
			'_law_contacts'            => 'Contacts',
			'_law_co_owner_rows'       => 'Co-owners',
			'_law_booking_override'    => 'Override booking availability',
			'_law_is_external'         => 'External event',
			'_law_external_url'        => 'Booking link',
			'_law_session_agenda'      => 'Has a session agenda',
			'_law_registration_state'  => 'How a place is obtained',
			'_law_sector_jurisdiction' => 'Jurisdiction',
			'_law_sector_other'        => 'Other sector',
		)
	);
}

/**
 * Any stored meta value as one comparable string.
 *
 * law_content_transfer_show() truncates for display; this is the step before
 * it, and its job is only to make an array comparable — people rows, an
 * address, the preferred slots, the consent record. Nothing is dropped that
 * would let two different values flatten to the same string.
 */
function law_content_transfer_flatten( $value ) {
	if ( is_bool( $value ) ) {
		return $value ? '1' : '0';
	}
	if ( ! is_array( $value ) ) {
		return (string) $value;
	}

	$parts = array();
	foreach ( $value as $key => $item ) {
		$flat = law_content_transfer_flatten( $item );
		if ( '' === $flat ) {
			continue;
		}
		$parts[] = is_int( $key ) ? $flat : $key . '=' . $flat;
	}

	return implode( is_array( reset( $value ) ) ? '; ' : ', ', $parts );
}

/**
 * One discount code, matched by its punctuation-free match key.
 *
 * The scope travels as reception slugs and is resolved back to local post IDs
 * from the receptions this same run has just placed, so the two halves cannot
 * disagree about which post a slug means.
 */
function law_content_transfer_run_discount( $discount, array $slugs, $dry, $actor ) {
	$code = law_discount_normalise_code( $discount['code'] ?? '' );
	$ref  = 'discount ' . ( $code ?: '(no code)' );

	$row = array(
		'kind'    => 'Discount code',
		'ref'     => $code,
		'verdict' => 'No change',
		'changes' => array(),
	);

	if ( '' === $code ) {
		$row['verdict'] = 'Skipped';
		$row['changes'] = array( 'The bundle row has no code.' );
		law_migration_log( 'content_transfer', 'warning', $ref, 'Skipped: no code.' );
		return $row;
	}

	$events  = array();
	$missing = array();
	$pending = array();
	foreach ( (array) ( $discount['events'] ?? array() ) as $slug ) {
		$slug = sanitize_title( (string) $slug );
		if ( array_key_exists( $slug, $slugs ) ) {
			if ( $slugs[ $slug ] > 0 ) {
				$events[] = (int) $slugs[ $slug ];
			} else {
				$pending[] = $slug; // A dry run's Create: it will exist by the time this is applied.
			}
			continue;
		}
		if ( LAW_FLAGSHIP_SLUG === $slug && law_flagship_event_id() ) {
			$events[] = law_flagship_event_id();
			continue;
		}
		$post = get_page_by_path( $slug, OBJECT, LAW_EVENT_CPT );
		if ( $post instanceof WP_Post ) {
			$events[] = (int) $post->ID;
		} else {
			$missing[] = $slug;
		}
	}
	if ( $missing ) {
		$row['changes'][] = sprintf( 'Scope dropped for %s: no such reception on this site.', implode( ', ', $missing ) );
		law_migration_log( 'content_transfer', 'warning', $ref, sprintf( 'Scope dropped for %s.', implode( ', ', $missing ) ) );
	}
	if ( $pending ) {
		$row['changes'][] = sprintf( 'Limited to %s, which this import will create.', implode( ', ', $pending ) );
	}

	$existing = law_discount_find( $code );
	$before   = $existing ? law_discount_data( $existing ) : null;

	$input = array(
		'id'       => $existing ? (int) $existing->ID : 0,
		'code'     => $code,
		'type'     => (string) ( $discount['type'] ?? 'percent' ),
		'value'    => (string) ( $discount['value'] ?? '' ),
		'starts'   => trim( (string) ( $discount['starts'] ?? '' ) ),
		'expires'  => trim( (string) ( $discount['expires'] ?? '' ) ),
		'max_uses' => absint( $discount['max_uses'] ?? 0 ),
		'events'   => $events,
		'note'     => sanitize_text_field( (string) ( $discount['note'] ?? '' ) ),
		'active'   => ! empty( $discount['active'] ),
	);

	if ( $dry ) {
		$errors = law_discount_validate_input( $input );
		if ( $errors->has_errors() ) {
			$row['verdict'] = 'Failed';
			$row['changes'] = $errors->get_error_messages();
			return $row;
		}
		// A scope that this run has not placed yet cannot be compared against
		// the stored IDs without inventing one, so that field is left out of
		// the preview rather than reported wrongly.
		$changes        = law_content_transfer_discount_diff( $before, $input, $pending ? array( 'events' ) : array() );
		$row['verdict'] = $existing ? ( $changes ? 'Update' : 'No change' ) : 'Create';
		$row['changes'] = array_merge( $row['changes'], $changes );
		return $row;
	}

	$saved = law_discount_save( $input, $actor );
	if ( is_wp_error( $saved ) ) {
		$row['verdict'] = 'Failed';
		$row['changes'] = $saved->get_error_messages();
		law_migration_log( 'content_transfer', 'error', $ref, implode( '; ', $saved->get_error_messages() ) );
		return $row;
	}

	$changes        = law_content_transfer_discount_diff( $before, law_discount_data( (int) $saved ) );
	$row['verdict'] = $existing ? ( $changes ? 'Update' : 'No change' ) : 'Create';
	$row['changes'] = array_merge( $row['changes'], $changes );
	law_migration_log(
		'content_transfer',
		$existing && ! $changes ? 'skipped' : 'created',
		$ref,
		$changes ? implode( '; ', $changes ) : 'Already matched the bundle.'
	);

	return $row;
}

/**
 * Compare a stored code with the bundle's version of it.
 *
 * `used` is not compared and never written: production's usage count is its
 * own, and a code that has been spent here has been spent.
 */
function law_content_transfer_discount_diff( $before, array $after, array $skip = array() ) {
	if ( ! $before ) {
		return array(
			sprintf( 'New code %s, %s', $after['code'], 'percent' === $after['type'] ? $after['value'] . '% off' : '£' . $after['value'] . ' off' ),
			$after['active'] ? 'Active' : 'Disabled',
		);
	}

	// law_discount_data() gives an int; the input is the typed string. Compare
	// in pence/percent, which is the one form both agree on.
	$before_value = (int) $before['value'];
	$after_value  = 'fixed' === $after['type']
		? (int) law_events_pounds_to_pence( $after['value'] )
		: (int) $after['value'];

	$comparable_before = array(
		'type'     => (string) $before['type'],
		'value'    => $before_value,
		'starts'   => (string) $before['starts'],
		'expires'  => (string) $before['expires'],
		'max_uses' => (int) $before['max_uses'],
		'note'     => (string) $before['note'],
		'active'   => (int) (bool) $before['active'],
		'events'   => implode( ',', array_map( 'intval', (array) $before['events'] ) ),
	);
	$comparable_after = array(
		'type'     => (string) $after['type'],
		'value'    => $after_value,
		'starts'   => (string) $after['starts'],
		'expires'  => (string) $after['expires'],
		'max_uses' => (int) $after['max_uses'],
		'note'     => (string) $after['note'],
		'active'   => (int) (bool) $after['active'],
		'events'   => implode( ',', array_map( 'intval', (array) $after['events'] ) ),
	);

	$labels = array(
		'type'     => 'Type',
		'value'    => 'Value',
		'starts'   => 'Starts',
		'expires'  => 'Expires',
		'max_uses' => 'Usage limit',
		'note'     => 'Note',
		'active'   => 'Active',
		'events'   => 'Limited to',
	);
	foreach ( $skip as $key ) {
		unset( $labels[ $key ] );
	}

	return law_content_transfer_diff( $comparable_before, $comparable_after, $labels );
}

/**
 * An event's stored description, sanitised the way the importer sanitises the
 * bundle's copy of it.
 *
 * Neither snapshot carries the description, so the comparison is added here.
 * Sanitising BOTH sides is the point: comparing the raw stored value against a
 * sanitised incoming one reports a change on every single run for any content
 * the sanitiser touches at all, which is the permanent-diff trap migration step
 * 4b already documents for speaker biographies.
 */
function law_content_transfer_stored_description( $event_id ) {
	return (string) law_rich_text_sanitize( (string) get_post_field( 'post_content', (int) $event_id ) );
}

/**
 * Field-by-field "old → new", for the keys the caller names.
 *
 * A key absent from $after is not being written and is therefore never
 * reported as a change: every saver in this module treats a missing key as
 * "not being edited", and a diff that ignored that would promise changes the
 * apply would not make.
 */
function law_content_transfer_diff( array $before, array $after, array $labels ) {
	$changes = array();

	foreach ( $labels as $key => $label ) {
		if ( ! array_key_exists( $key, $after ) ) {
			continue;
		}
		$old = $before[ $key ] ?? '';
		$new = $after[ $key ];
		if ( (string) $old === (string) $new ) {
			continue;
		}
		$changes[] = sprintf( '%s: %s → %s', $label, law_content_transfer_show( $old ), law_content_transfer_show( $new ) );
	}

	return $changes;
}

/** A value as one short readable string. */
function law_content_transfer_show( $value ) {
	if ( is_bool( $value ) ) {
		return $value ? 'yes' : 'no';
	}
	$value = trim( wp_strip_all_tags( (string) $value ) );
	if ( '' === $value ) {
		return 'not set';
	}
	return mb_strlen( $value ) > 80 ? mb_substr( $value, 0, 77 ) . '…' : $value;
}

/* ===========================================================================
 * The panel
 * ======================================================================== */

/** Where the parsed bundle waits between Preview and Apply. */
function law_content_transfer_transient_key() {
	return 'law_ct_bundle_' . get_current_user_id();
}

/** The panel on the migration screen (functions/events/migration/page.php). */
function law_content_transfer_panel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice  = '';
	$results = array();
	$applied = false;

	if ( isset( $_POST['law_content_transfer_nonce'] ) ) {
		check_admin_referer( 'law_content_transfer', 'law_content_transfer_nonce' );
		$applied = 'apply' === ( $_POST['law_ct_action'] ?? '' );

		if ( $applied ) {
			$bundle = get_transient( law_content_transfer_transient_key() );
			if ( ! is_array( $bundle ) ) {
				$notice = '<div class="notice notice-error"><p>The preview has expired. Upload the bundle again and preview it before applying.</p></div>';
				// The transient is what points at the extracted images, so once it
				// has gone they are unreachable and must not be left behind.
				law_content_transfer_clear_workdir();
			} else {
				delete_transient( law_content_transfer_transient_key() );
				$results = law_content_transfer_run( $bundle, false, get_current_user_id() );
				$notice  = '<div class="notice notice-success"><p><strong>Import applied.</strong> ' . esc_html( law_content_transfer_summary( $bundle ) ) . '</p></div>';
				// The bytes live exactly as long as the decision they belong to.
				// After the run, not before: the run is what reads them.
				law_content_transfer_clear_workdir();
			}
		} else {
			$bundle = law_content_transfer_read_upload();
			if ( is_wp_error( $bundle ) ) {
				$notice = '<div class="notice notice-error"><p>' . esc_html( $bundle->get_error_message() ) . '</p></div>';
			} else {
				set_transient( law_content_transfer_transient_key(), $bundle, HOUR_IN_SECONDS );
				$results = law_content_transfer_run( $bundle, true, get_current_user_id() );
				$notice  = '<div class="notice notice-info"><p><strong>Preview only. Nothing has been written.</strong> The bundle was made on '
					. esc_html( (string) ( $bundle['site']['url'] ?? 'an unknown site' ) ) . ' and holds '
					. esc_html( law_content_transfer_summary( $bundle ) ) . '.</p></div>';
			}
		}
	}

	$bundle_here = law_content_transfer_bundle();

	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- assembled above with esc_html().
	?>
	<h2>Content transfer: staging → production</h2>
	<p class="description" style="max-width:900px">
		Moves the programme between environments: every hosted and external event with its venue, dates,
		speakers, session agenda and committee switches (Override booking availability included), the
		receptions, the flagship conference with its full session agenda and speakers, the discount codes,
		and any email wording customised on the Emails screen. A git deploy already creates the empty records
		on a new site; this carries what was typed into them.
	</p>
	<p class="description" style="max-width:900px">
		<strong>Run it after the Gravity Forms migration, not before.</strong> The import is an overlay: it
		updates the events the file names, creates the ones this site has never seen, and leaves everything
		else exactly where it was. Nothing is ever deleted, and an event that exists here but not on the other
		site is not touched.
	</p>
	<p class="description" style="max-width:900px">
		<strong>A hosted event's status is never imported.</strong> Approving an event raises the Stripe
		invoice and emails the host, so it has to be done on the committee dashboard rather than by a file
		upload; where the two sites disagree the preview says so and leaves the status alone. An external
		event's &ldquo;on the programme&rdquo; tick does travel, because there is no workflow behind it.
		<strong>Bookings, attendees, payments and Stripe records are never transferred</strong>, because they
		belong to the site they were made on, and nor is the host fee snapshot the invoice was raised from.
		The Stripe tax rate and rendering template in Events &rarr; Settings are also out of scope and must be
		set on the far site separately.
		<strong>Email recipients do not travel either</strong> &mdash; the four emails with a typed address list
		keep whichever addresses the far site already has, because pointing a live notification at a test mailbox
		is a mistake nobody would notice.
		<strong>No user account is ever created by an import</strong>: an owner or assignee with no account here
		is reported and the event keeps the one it had.
	</p>

	<div class="law-mig-grid">
		<div class="law-mig-card">
			<h3 style="margin-top:0">Export from this site</h3>
			<p>This site currently holds <strong><?php echo esc_html( law_content_transfer_summary( $bundle_here ) ); ?></strong>.</p>
			<p>
				<a class="button button-primary"
					href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=law_content_transfer_export' ), 'law_content_transfer_export' ) ); ?>">
					Download bundle (.zip)
				</a>
			</p>
			<p class="description">Speaker photographs and the banner image travel inside the archive, so the far
				site needs no access to this one. Open the zip and check the <code>images/</code> folder before you
				rely on it.</p>
		</div>

		<div class="law-mig-card">
			<h3 style="margin-top:0">Import into this site</h3>
			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'law_content_transfer', 'law_content_transfer_nonce' ); ?>
				<input type="hidden" name="law_ct_action" value="preview">
				<p><input type="file" name="law_ct_file" accept=".zip,.json,application/zip,application/json" required></p>
				<p><button type="submit" class="button button-secondary">Preview this bundle</button></p>
			</form>
			<p class="description">Always previews first. Nothing is written until you press Apply under the preview.
				A <code>.json</code> bundle from before the archive format still works; its images are fetched from
				the site that made it.</p>
		</div>
	</div>

	<?php if ( $results ) : ?>
		<h3><?php echo $applied ? 'What the import did' : 'What this import would do'; ?></h3>
		<table class="widefat striped" style="max-width:1100px">
			<thead><tr><th style="width:130px">Record</th><th style="width:260px">Name</th><th style="width:100px">Action</th><th>Changes</th></tr></thead>
			<tbody>
			<?php foreach ( $results as $result ) : ?>
				<tr>
					<td><?php echo esc_html( $result['kind'] ); ?></td>
					<td><?php echo esc_html( $result['ref'] ); ?></td>
					<td>
						<strong style="color:<?php echo esc_attr( law_content_transfer_verdict_colour( $result['verdict'] ) ); ?>">
							<?php echo esc_html( $result['verdict'] ); ?>
						</strong>
					</td>
					<td>
						<?php if ( $result['changes'] ) : ?>
							<ul style="margin:0 0 0 1.2em;list-style:disc">
								<?php foreach ( $result['changes'] as $change ) : ?>
									<li><?php echo esc_html( $change ); ?></li>
								<?php endforeach; ?>
							</ul>
						<?php else : ?>
							—
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( ! $applied ) : ?>
			<form method="post" onsubmit="return confirm('Apply this import? It overwrites the events, receptions, flagship agenda and discount codes listed above with the values in the file. Events this site holds that the file does not name are left alone.');">
				<?php wp_nonce_field( 'law_content_transfer', 'law_content_transfer_nonce' ); ?>
				<input type="hidden" name="law_ct_action" value="apply">
				<p>
					<button type="submit" class="button button-primary">Apply this import</button>
					<span class="description" style="margin-left:8px">Overwrites the records above with the values in the file. Nothing else is touched, nothing is deleted, and bookings and payments are never written.</span>
				</p>
			</form>
		<?php endif; ?>
	<?php endif; ?>
	<?php
}

function law_content_transfer_verdict_colour( $verdict ) {
	switch ( $verdict ) {
		case 'Failed':
			return '#b32d2e';
		case 'Skipped':
			return '#996800';
		case 'Create':
		case 'Update':
			return '#00a32a';
		default:
			return '#50575e';
	}
}
