<?php
/**
 * Content transfer: the committee's receptions, flagship and discount
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
 * Three rules hold the format together, all of them consequences of the one
 * fact that makes a cross-site move hard: POST IDS DO NOT SURVIVE IT.
 *
 * 1. Receptions and discount scope are keyed by SLUG, never by ID. The slug is
 *    what law_event_ensure_managed_post() provisions by, so it means the same
 *    thing on both sites.
 * 2. Speakers are keyed by IDENTITY, not by ID: each row carries the first
 *    name, last name, email and website inline, is marked is_new, and is
 *    handed to law_flagship_resolve_speaker_rows(), which calls
 *    law_speaker_upsert() — that already dedupes by email first and normalised
 *    name second. So there is no speaker matching logic here at all.
 * 3. Derived values are never exported. _law_start, _law_end and the
 *    event-level _law_speakers union on the flagship are recomputed from the
 *    sessions by law_flagship_recompute(); exporting them would only give the
 *    importer a chance to write something stale.
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
 * And the whole import writes through the EXISTING savers —
 * law_reception_save(), law_flagship_save(), law_discount_save() — so this
 * file is a caller, never a second write path. That is what keeps the
 * validation, the workflow status-guard exemption, the recompute and the
 * per-event activity log identical to a committee member typing the same
 * values in by hand.
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
 * Email wording joined the bundle on 15 September 2026, reversing its original
 * exclusion, because the client had spent time polishing it on staging and the
 * registry in notifications.php only carries the code defaults.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_CONTENT_TRANSFER_FORMAT  = 'law-content-transfer';
const LAW_CONTENT_TRANSFER_VERSION = 2;
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

/** "3 receptions, the flagship and its 14 sessions, 6 discount codes". */
function law_content_transfer_summary( array $bundle ) {
	$bits = array();

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

	$bundle['receptions'] = array_values( array_filter( (array) ( $bundle['receptions'] ?? array() ), 'is_array' ) );
	$bundle['discounts']  = array_values( array_filter( (array) ( $bundle['discounts'] ?? array() ), 'is_array' ) );
	$bundle['emails']     = array_values( array_filter( (array) ( $bundle['emails'] ?? array() ), 'is_array' ) );
	$bundle['flagship']   = is_array( $bundle['flagship'] ?? null ) ? $bundle['flagship'] : null;

	return $bundle;
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
function law_content_transfer_body_change( $before, $after ) {
	$was  = (string) $before;
	$now  = (string) $after;
	$size = sprintf( '%s characters → %s', number_format_i18n( mb_strlen( $was ) ), number_format_i18n( mb_strlen( $now ) ) );

	if ( '' === trim( $was ) ) {
		return sprintf( 'Body: set for the first time (%s)', $size );
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
			'Body: %s, first change on line %d: %s → %s',
			$size,
			$i + 1,
			law_content_transfer_show( $old_line ),
			law_content_transfer_show( $new_line )
		);
	}

	// Same lines, different string: trailing whitespace or line endings only.
	return sprintf( 'Body: %s (whitespace only)', $size );
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
		'body'    => sanitize_textarea_field( (string) ( $email['body'] ?? '' ) ),
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
		Moves the committee's own configuration between environments: the receptions, the flagship conference
		with its full session agenda and speakers, the discount codes, and any email wording customised on the
		Emails screen. A git deploy already creates the empty records on a new site; this carries what was typed
		into them.
		<strong>Bookings, attendees, payments and Stripe records are never transferred</strong>, because they
		belong to the site they were made on. The Stripe tax rate and rendering template in Events &rarr; Settings
		are also out of scope and must be set on the far site separately.
		<strong>Email recipients do not travel either</strong> &mdash; the four emails with a typed address list
		keep whichever addresses the far site already has, because pointing a live notification at a test mailbox
		is a mistake nobody would notice.
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
			<form method="post" onsubmit="return confirm('Apply this import? It overwrites the receptions, the flagship and its agenda, and the discount codes on this site with the values in the file.');">
				<?php wp_nonce_field( 'law_content_transfer', 'law_content_transfer_nonce' ); ?>
				<input type="hidden" name="law_ct_action" value="apply">
				<p>
					<button type="submit" class="button button-primary">Apply this import</button>
					<span class="description" style="margin-left:8px">Overwrites the records above with the values in the file. Bookings and payments are never touched.</span>
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
