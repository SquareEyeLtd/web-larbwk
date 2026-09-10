<?php
/**
 * The migration runner (EVENTS_4.1_REBUILD.md §5): batched, idempotent,
 * dry-run-first steps from Gravity Forms entries to the module CPTs.
 * Source data is never modified. Every created object stores its source
 * entry ID; re-runs skip already-migrated items and report them.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_MIGRATION_MAP_OPTION = 'law_events_entry_map';

/** Ordered step definitions. */
function law_migration_steps() {
	return array(
		'snapshot'      => array( 'label' => 'Step 0: database snapshot', 'gated' => false ),
		'preflight'     => array( 'label' => 'Preflight checks', 'gated' => false ),
		'co_owners'     => array( 'label' => 'Step 1: co-owner users (approved events)', 'gated' => true ),
		'speakers'      => array( 'label' => 'Step 2: speakers', 'gated' => true ),
		'events'        => array( 'label' => 'Step 3: events', 'gated' => true ),
		'sessions'      => array( 'label' => 'Step 4: sessions', 'gated' => true ),
		'speaker_appearances' => array( 'label' => 'Step 4b: speaker appearance details (role, organisation, job title, photo, biography per event, refreshed from the source entries)', 'gated' => true ),
		'comments'      => array( 'label' => 'Step 5: comment threads', 'gated' => true ),
		'history'       => array( 'label' => 'Step 6: workflow history', 'gated' => true ),
		'counters'      => array( 'label' => 'Step 7: counters and settings seed', 'gated' => true ),
		'redirects'     => array( 'label' => 'Step 8: redirect map', 'gated' => true ),
		'notifications' => array( 'label' => 'Step 9: notifications', 'gated' => true ),
		'pages'         => array( 'label' => 'Step 10: account page templates and the flagship event', 'gated' => true ),
	);
}

function law_migration_map() {
	$map = get_option( LAW_MIGRATION_MAP_OPTION, array() );
	return is_array( $map ) ? array_merge( array( 'events' => array(), 'speakers' => array() ), $map ) : array( 'events' => array(), 'speakers' => array() );
}

function law_migration_map_set( $kind, $entry_id, $post_id ) {
	$map = law_migration_map();
	$map[ $kind ][ (int) $entry_id ] = (int) $post_id;
	update_option( LAW_MIGRATION_MAP_OPTION, $map, false );
}

/** Active GF entries for a form, ordered by id. */
function law_migration_entries( $form_id, $offset = 0, $page_size = 200 ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		return array();
	}
	$entries = GFAPI::get_entries(
		$form_id,
		array( 'status' => 'active' ),
		array( 'key' => 'id', 'direction' => 'ASC' ),
		array( 'offset' => (int) $offset, 'page_size' => (int) $page_size )
	);
	return is_wp_error( $entries ) ? array() : (array) $entries;
}

/** Child entries of a parent, by gpnf_entry_parent, ordered by id. */
function law_migration_children( $form_id, $parent_id ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		return array();
	}
	$entries = GFAPI::get_entries(
		$form_id,
		array(
			'status'        => 'active',
			'field_filters' => array(
				array( 'key' => 'gpnf_entry_parent', 'value' => (string) $parent_id ),
			),
		),
		array( 'key' => 'id', 'direction' => 'ASC' ),
		array( 'offset' => 0, 'page_size' => 100 )
	);
	return is_wp_error( $entries ) ? array() : (array) $entries;
}

/* Step 0: snapshot __________________________________________________________ */

function law_migration_snapshot_dir() {
	$uploads = wp_upload_dir();
	$dir     = trailingslashit( $uploads['basedir'] ) . 'law-migration';
	if ( ! is_dir( $dir ) ) {
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/.htaccess', "Require all denied\n" );
		file_put_contents( $dir . '/index.php', "<?php // Silence.\n" );
	}
	return $dir;
}

/**
 * Full database snapshot: mysqldump when exec is available, else a batched
 * pure-PHP export. Gzipped SQL in a protected uploads directory.
 *
 * @return array|WP_Error { file, size, tables, sha256 }
 */
function law_migration_create_snapshot() {
	global $wpdb;
	$dir = law_migration_snapshot_dir();
	// Secret-strength filename: the .htaccess deny only protects Apache, so
	// the name itself must not be guessable, and the exposure self-test below
	// fails closed when the web server serves the file anyway.
	$file = $dir . '/snapshot-' . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 32, false ) . '.sql.gz';

	$tables = $wpdb->get_col( 'SHOW TABLES' );

	$used_mysqldump = false;
	if ( function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) ), true ) ) {
		$binary = trim( (string) shell_exec( 'command -v mysqldump 2>/dev/null' ) );
		if ( '' !== $binary ) {
			// Pass the password via MYSQL_PWD, not -p<pw>: a CLI password argument
			// is visible to any local user in `ps aux` for the life of the dump,
			// whereas the env var is only in the process environment.
			$command = sprintf(
				'MYSQL_PWD=%s %s --single-transaction --no-tablespaces -h%s -u%s %s 2>/dev/null | gzip > %s',
				escapeshellarg( DB_PASSWORD ),
				escapeshellcmd( $binary ),
				escapeshellarg( DB_HOST ),
				escapeshellarg( DB_USER ),
				escapeshellarg( DB_NAME ),
				escapeshellarg( $file )
			);
			shell_exec( $command );
			$used_mysqldump = file_exists( $file ) && filesize( $file ) > 1024;
		}
	}

	if ( ! $used_mysqldump ) {
		$result = law_migration_php_dump( $tables, $file );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
	}

	// Exposure self-test: request the snapshot's public URL WITHOUT
	// credentials. If the web server serves it (nginx ignores .htaccess),
	// delete the dump and refuse: a full-database export must never be one
	// unauthenticated GET away.
	$uploads    = wp_upload_dir();
	$public_url = str_replace( $uploads['basedir'], $uploads['baseurl'], $file );
	$probe      = wp_remote_get( $public_url, array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => false ) );
	if ( ! is_wp_error( $probe ) && 200 === (int) wp_remote_retrieve_response_code( $probe ) ) {
		unlink( $file );
		law_migration_log( 'snapshot', 'error', basename( $file ), 'EXPOSURE: the snapshot was publicly downloadable, so it was deleted. Add server-level protection for wp-content/uploads/law-migration/ (e.g. an nginx deny block) before migrating.' );
		return new WP_Error(
			'law_snapshot_exposed',
			'The snapshot file was publicly downloadable (the server ignores .htaccess). It has been deleted. Add a server-level deny rule for wp-content/uploads/law-migration/ and try again.'
		);
	}

	$snapshot = array(
		'file'   => $file,
		'size'   => filesize( $file ),
		'tables' => count( $tables ),
		'sha256' => hash_file( 'sha256', $file ),
		'at'     => time(),
		'method' => $used_mysqldump ? 'mysqldump' : 'php',
	);
	update_option( 'law_migration_snapshot', $snapshot, false );
	law_migration_log( 'snapshot', 'created', basename( $file ), sprintf(
		'Snapshot via %s: %d tables, %s, sha256 %s.',
		$snapshot['method'],
		$snapshot['tables'],
		size_format( $snapshot['size'] ),
		substr( $snapshot['sha256'], 0, 16 ) . '…'
	) );
	return $snapshot;
}

/** Pure-PHP dump: schema + batched inserts, gzipped. */
function law_migration_php_dump( array $tables, $file ) {
	global $wpdb;
	$gz = gzopen( $file, 'wb6' );
	if ( ! $gz ) {
		return new WP_Error( 'law_snapshot_failed', 'Could not open the snapshot file for writing.' );
	}
	gzwrite( $gz, "SET FOREIGN_KEY_CHECKS=0;\n" );
	foreach ( $tables as $table ) {
		$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . str_replace( '`', '', $table ) . '`', ARRAY_N );
		gzwrite( $gz, "DROP TABLE IF EXISTS `{$table}`;\n" . $create[1] . ";\n" );
		$offset = 0;
		while ( true ) {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . str_replace( '`', '', $table ) . '` LIMIT %d OFFSET %d', 500, $offset ), ARRAY_A );
			if ( ! $rows ) {
				break;
			}
			foreach ( $rows as $row ) {
				$values = array();
				foreach ( $row as $value ) {
					$values[] = null === $value ? 'NULL' : "'" . esc_sql( (string) $value ) . "'";
				}
				gzwrite( $gz, "INSERT INTO `{$table}` VALUES (" . implode( ',', $values ) . ");\n" );
			}
			$offset += 500;
		}
	}
	gzwrite( $gz, "SET FOREIGN_KEY_CHECKS=1;\n" );
	gzclose( $gz );
	return true;
}

/** Whether a fresh-enough snapshot (or the override) unlocks the real run. */
function law_migration_snapshot_ok() {
	if ( get_option( 'law_migration_snapshot_override' ) ) {
		return true;
	}
	$snapshot = get_option( 'law_migration_snapshot' );
	return is_array( $snapshot ) && ( time() - (int) $snapshot['at'] ) < HOUR_IN_SECONDS && file_exists( $snapshot['file'] );
}

/* Preflight _________________________________________________________________ */

/**
 * Re-verify every assumption on the environment this actually runs against
 * (EVENTS_4.1_REBUILD.md §5.2). Failures block; warnings carry into the report.
 *
 * @return array{pass:bool,checks:array<int,array{label:string,status:string,detail:string}>}
 */
function law_migration_preflight() {
	global $wpdb;
	$checks = array();
	$check  = function ( $label, $ok, $detail, $warn_only = false ) use ( &$checks ) {
		$status   = $ok ? 'pass' : ( $warn_only ? 'warning' : 'fail' );
		$checks[] = compact( 'label', 'status', 'detail' );
		law_migration_log( 'preflight', $ok ? 'info' : ( $warn_only ? 'warning' : 'error' ), $label, $detail );
	};

	$check( 'Gravity Forms available', class_exists( 'GFAPI' ), class_exists( 'GFAPI' ) ? 'GFAPI loaded' : 'GFAPI missing: cannot read the source data' );
	$check( 'Target CPTs registered', post_type_exists( LAW_EVENT_CPT ) && post_type_exists( LAW_SPEAKER_CPT ) && post_type_exists( LAW_SESSION_CPT ), 'law_event / law_speaker / law_session' );
	$check( 'Source flag still on GF', 'gf' === law_events_source(), 'law_events_source = ' . law_events_source(), true );
	$check( 'Stripe key constants present', defined( 'LAW_STRIPE_SECRET_KEY' ) && '' !== LAW_STRIPE_SECRET_KEY, 'Mode: ' . law_events_stripe_mode(), true );

	if ( class_exists( 'GFAPI' ) ) {
		// Form structure: the field IDs and types the mapper reads.
		$expected = array(
			2 => array( '17' => 'text', '21' => 'text', '23' => 'textarea', '53' => 'product', '68' => 'select', '70' => 'uid', '95' => 'select', '96' => 'select', '105' => 'text', '112' => 'form', '115' => 'form', '99' => 'form', '106' => 'form', '94' => 'form' ),
			4 => array( '1' => 'name', '3' => 'text', '4' => 'email' ),
			5 => array( '1' => 'textarea', '3' => 'name', '4' => 'email' ),
			6 => array( '1' => 'name', '3' => 'text', '4' => 'email' ),
			8 => array( '1' => 'name', '3' => 'text', '4' => 'text', '5' => 'website', '6' => 'fileupload', '7' => 'textarea', '8' => 'email' ),
			9 => array( '1' => 'time', '3' => 'time', '4' => 'text', '5' => 'textarea', '6' => 'multiselect' ),
		);
		foreach ( $expected as $form_id => $fields ) {
			$form    = GFAPI::get_form( $form_id );
			$missing = array();
			if ( is_array( $form ) ) {
				$actual = array();
				foreach ( $form['fields'] as $field ) {
					$actual[ (string) $field->id ] = $field->type;
				}
				foreach ( $fields as $field_id => $type ) {
					if ( ( $actual[ $field_id ] ?? '' ) !== $type ) {
						$missing[] = "field {$field_id} ({$type})";
					}
				}
			} else {
				$missing[] = 'form missing';
			}
			$check(
				sprintf( 'Form %d structure', $form_id ),
				empty( $missing ),
				empty( $missing ) ? 'All expected fields present' : 'Mismatch: ' . implode( ', ', $missing )
			);
		}

		// Form 8 field 9 (Role): added to the live form on 3 September 2026, so a
		// database pulled before then lacks it. Warn-only: without it every
		// migrated row keeps the default role (Speaker) rather than failing the run.
		$role_form  = GFAPI::get_form( 8 );
		$role_field = '';
		foreach ( is_array( $role_form ) ? $role_form['fields'] : array() as $field ) {
			if ( '9' === (string) $field->id ) {
				$role_field = (string) $field->type;
				break;
			}
		}
		$check(
			'Form 8 field 9 (Role)',
			'select' === $role_field,
			'select' === $role_field ? 'Present: speaker roles will migrate' : ( $role_field ? "Field 9 is a {$role_field}, not a select: roles will not migrate" : 'Absent: migrated rows keep the default role (Speaker)' ),
			true
		);

		// Source counts.
		$counts = array();
		foreach ( array( 2, 4, 5, 6, 8, 9 ) as $form_id ) {
			$counts[ $form_id ] = (int) GFAPI::count_entries( $form_id, array( 'status' => 'active' ) );
		}
		$check( 'Source counts', $counts[2] > 0, wp_json_encode( $counts ) );

		// Orphaned children.
		$orphans = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}gf_entry e
			 JOIN {$wpdb->prefix}gf_entry_meta em ON em.entry_id = e.id AND em.meta_key = 'gpnf_entry_parent'
			 LEFT JOIN {$wpdb->prefix}gf_entry p ON p.id = em.meta_value
			 WHERE e.form_id IN (4,5,6,8,9) AND e.status = 'active' AND (p.id IS NULL OR p.status != 'active')"
		);
		$check( 'Orphaned child entries', 0 === $orphans, $orphans . ' active children have a missing/trashed parent (they will be skipped and reported)', true );

		// Slot parseability across every field 68 value.
		$bad_slots = array();
		foreach ( law_migration_entries( 2, 0, 500 ) as $entry ) {
			$raw = trim( (string) rgar( $entry, '68' ) );
			if ( '' !== $raw && ! law_calendar_parse_slot( $raw ) ) {
				$bad_slots[] = 'entry ' . $entry['id'];
			}
		}
		$check( 'Slot values parse', empty( $bad_slots ), $bad_slots ? 'Unparseable: ' . implode( ', ', $bad_slots ) : 'Every confirmed slot value parses' );

		// Preferred slots (field 77) are punctuated differently from the
		// confirmed-slot choices (field 68) the settings list is seeded from, so
		// each one is mapped onto its canonical label at migration time. Report
		// any value that maps onto nothing: it will be carried across verbatim
		// and will not tick a checkbox on the host form.
		$slot_keys = array_map( 'law_events_slot_label_key', law_migration_slot_labels() );
		$unmatched = array();
		foreach ( law_migration_entries( 2, 0, 500 ) as $entry ) {
			$preferred = json_decode( (string) rgar( $entry, '77' ), true );
			foreach ( (array) ( is_array( $preferred ) ? $preferred : array() ) as $raw ) {
				$raw = trim( (string) $raw );
				if ( '' !== $raw && ! in_array( law_events_slot_label_key( $raw ), $slot_keys, true ) ) {
					$unmatched[ $raw ] = true;
				}
			}
		}
		$check(
			'Preferred slot values match a slot choice',
			empty( $unmatched ),
			$unmatched ? 'No matching choice for: ' . implode( '; ', array_keys( $unmatched ) ) : 'Every preferred slot value maps onto a confirmed-slot choice'
		);

		// Speaker photo files on disk.
		$missing_photos = 0;
		$with_photos    = 0;
		$uploads        = wp_upload_dir();
		foreach ( law_migration_entries( 8, 0, 500 ) as $entry ) {
			$url = law_calendar_speaker_photo_url( rgar( $entry, '6' ) );
			if ( '' === $url ) {
				continue;
			}
			$with_photos++;
			$path = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );
			if ( ! str_starts_with( $url, $uploads['baseurl'] ) || ! file_exists( $path ) ) {
				$missing_photos++;
			}
		}
		$check( 'Speaker photo files', 0 === $missing_photos, sprintf( '%d photos referenced, %d files missing on disk (missing files migrate without a photo)', $with_photos, $missing_photos ), true );

		// The GP Unique ID sequence for the reference counter seed.
		$sequence = $wpdb->get_var( $wpdb->prepare( "SELECT current FROM {$wpdb->prefix}gpui_sequence WHERE form_id = 2 AND field_id = 70", ) );
		$check( 'Reference sequence readable', null !== $sequence, 'wp_gpui_sequence current = ' . var_export( $sequence, true ) );

		// Trashed entries: reported so nothing disappears unnoticed (§2.1).
		$trashed = (int) GFAPI::count_entries( 2, array( 'status' => 'trash' ) );
		$check( 'Trashed form 2 entries', true, $trashed . ' trashed entries stay in the GF archive and are NOT migrated (settled decision)', true );

		// Stripe invoice config: without a tax rate ID a VAT-liable approval
		// would raise a net-only invoice.
		$check( 'Stripe tax rate ID configured', '' !== (string) law_events_setting( 'tax_rate_id', '' ), (string) law_events_setting( 'tax_rate_id', '(empty — set it in LAW → Events settings before approving paid events)' ), true );
		$check( 'Stripe rendering template configured', '' !== (string) law_events_setting( 'rendering_template_id', '' ), (string) law_events_setting( 'rendering_template_id', '(empty — invoices will use Stripe\'s default look)' ), true );
	}

	$pass = ! in_array( 'fail', wp_list_pluck( $checks, 'status' ), true );
	update_option( 'law_migration_preflight', array( 'pass' => $pass, 'at' => time(), 'checks' => $checks ), false );
	return array( 'pass' => $pass, 'checks' => $checks );
}

/* Step 1: co-owner users ____________________________________________________ */

function law_migration_run_co_owners( $dry ) {
	$created = 0;
	$matched = 0;
	$deferred = 0;
	foreach ( law_migration_entries( 6, 0, 500 ) as $child ) {
		$ref       = 'form 6 entry ' . $child['id'];
		$parent_id = (int) rgar( $child, 'gpnf_entry_parent' );
		$parent    = $parent_id && class_exists( 'GFAPI' ) ? GFAPI::get_entry( $parent_id ) : null;
		if ( ! is_array( $parent ) || is_wp_error( $parent ) ) {
			law_migration_log( 'co_owners', 'skipped', $ref, 'Orphaned: parent entry missing or trashed.' );
			continue;
		}
		$status = trim( (string) rgar( $parent, '95' ) );
		if ( ! in_array( $status, array( 'Approved', 'Confirmed' ), true ) ) {
			$deferred++;
			law_migration_log( 'co_owners', 'skipped', $ref, sprintf( 'Deferred: parent event is %s; the account is created when it is approved.', $status ?: 'unknown' ) );
			continue;
		}

		$email = sanitize_email( (string) rgar( $child, '4' ) );
		$name  = trim( rgar( $child, '1.3' ) . ' ' . rgar( $child, '1.6' ) );
		if ( ! is_email( $email ) ) {
			law_migration_log( 'co_owners', 'warning', $ref, sprintf( 'No valid email for "%s": no account possible.', $name ) );
			continue;
		}

		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$matched++;
			law_migration_log( 'co_owners', $dry ? 'dry-run' : 'skipped', $ref, sprintf( 'Matched existing user %s (%s).', $user->display_name, $email ) );
			continue;
		}

		if ( $dry ) {
			$created++;
			law_migration_log( 'co_owners', 'dry-run', $ref, sprintf( 'Would create event_host account for %s (%s).', $name, $email ) );
			continue;
		}

		$user_id = law_events_create_host_user( $email, $name, (string) rgar( $child, '3' ) );
		if ( is_wp_error( $user_id ) ) {
			law_migration_log( 'co_owners', 'error', $ref, 'Account creation failed: ' . $user_id->get_error_message() );
			continue;
		}
		$created++;
		law_migration_log( 'co_owners', 'created', $ref, sprintf( 'Created event_host account %d for %s (%s). No welcome email sent.', $user_id, $name, $email ) );
	}
	return array( 'done' => true, 'summary' => sprintf( '%d created, %d matched, %d deferred.', $created, $matched, $deferred ) );
}

/* Step 2: speakers __________________________________________________________ */

/**
 * Give an already-migrated speaker post the first/last name it was created
 * without (see the skip branch in step 2). Gap-fill only: a name corrected on
 * the WordPress side since the migration is never overwritten from the source.
 *
 * @param int   $post_id Speaker post ID.
 * @param array $child   Its source form 8 (Event > speaker) entry.
 * @param bool  $dry     Report only.
 * @return bool Whether anything was (or would be) written.
 */
function law_migration_backfill_speaker_name( $post_id, array $child, $dry ) {
	if ( '' !== trim( (string) law_event_meta( $post_id, '_law_speaker_first_name' ) )
		|| '' !== trim( (string) law_event_meta( $post_id, '_law_speaker_last_name' ) ) ) {
		return false;
	}
	$first = trim( (string) rgar( $child, '1.3' ) );
	$last  = trim( (string) rgar( $child, '1.6' ) );
	if ( '' === law_speaker_full_name( $first, $last ) ) {
		return false;
	}
	if ( ! $dry ) {
		law_event_update_meta( $post_id, '_law_speaker_first_name', $first );
		law_event_update_meta( $post_id, '_law_speaker_last_name', $last );
	}
	return true;
}

function law_migration_run_speakers( $dry ) {
	$map     = law_migration_map();
	$created = 0;
	$merged  = 0;

	foreach ( law_migration_entries( 8, 0, 500 ) as $child ) {
		$entry_id = (int) $child['id'];
		$ref      = 'form 8 entry ' . $entry_id;

		if ( ! empty( $map['speakers'][ $entry_id ] ) && get_post( $map['speakers'][ $entry_id ] ) ) {
			// Already migrated, but a database migrated BEFORE 9 September 2026 has
			// no first/last name meta on its speaker posts, only the joined title.
			// Reading one falls back to splitting that title, which gets a
			// multi-word surname wrong, so a re-run repairs it from the source
			// fields (1.3 First / 1.6 Last) that always had the two apart.
			$backfilled = law_migration_backfill_speaker_name( (int) $map['speakers'][ $entry_id ], $child, $dry );
			law_migration_log(
				'speakers',
				$backfilled ? ( $dry ? 'dry-run' : 'info' ) : 'skipped',
				$ref,
				$backfilled
					? sprintf( '%s the first/last name on already-migrated post %d from the source entry.', $dry ? 'Would set' : 'Set', $map['speakers'][ $entry_id ] )
					: 'Already migrated to post ' . $map['speakers'][ $entry_id ] . '.'
			);
			continue;
		}
		// Revision entries are not speakers.
		if ( rgar( $child, 'gv_revision_parent_id' ) ) {
			continue;
		}

		$parent_id = (int) rgar( $child, 'gpnf_entry_parent' );
		if ( ! $parent_id || ! is_array( GFAPI::get_entry( $parent_id ) ) ) {
			law_migration_log( 'speakers', 'skipped', $ref, 'Orphaned: parent entry missing or trashed.' );
			continue;
		}

		// Form 8 field 1 (Name) is a Gravity Forms name field: 1.3 is First and
		// 1.6 is Last. They migrate into the speaker post's own first/last name
		// meta, so nothing has to be re-split on the other side.
		$first = trim( (string) rgar( $child, '1.3' ) );
		$last  = trim( (string) rgar( $child, '1.6' ) );
		$name  = law_speaker_full_name( $first, $last );
		$email = sanitize_email( (string) rgar( $child, '8' ) );
		if ( '' === $name ) {
			law_migration_log( 'speakers', 'warning', $ref, 'No name; skipped.' );
			continue;
		}

		$existing = law_speaker_find_existing( $email, $name );
		if ( $dry ) {
			law_migration_log( 'speakers', 'dry-run', $ref, $existing
				? sprintf( 'Would merge "%s" into existing speaker post %d.', $name, $existing )
				: sprintf( 'Would create speaker "%s"%s.', $name, is_email( $email ) ? " ({$email})" : ' (no email, matched by name)' ) );
			continue;
		}

		// Organisation (field 3), job title (field 4) and the photo (field 6)
		// are per appearance: step 3 puts them on the event's speaker row. The
		// photo is imported once here and recorded in the map for step 3; the
		// upsert only uses it as the post's fallback featured image.
		$photo_id  = law_migration_import_photo( rgar( $child, '6' ), 'speakers', $ref );
		$speaker_id = law_speaker_upsert(
			array(
				'first_name' => $first,
				'last_name'  => $last,
				'email'      => $email,
				'website'    => (string) rgar( $child, '5' ),
				'bio'        => (string) rgar( $child, '7' ),
				'photo_id'   => $photo_id,
			)
		);
		if ( ! $speaker_id ) {
			law_migration_log( 'speakers', 'error', $ref, sprintf( 'Could not create a speaker post for "%s".', $name ) );
			continue;
		}

		if ( $existing && $existing === $speaker_id ) {
			$merged++;
			law_migration_log( 'speakers', 'created', $ref, sprintf( 'Merged "%s" into existing speaker post %d%s.', $name, $speaker_id, is_email( $email ) ? '' : ' (name match)' ) );
		} else {
			$created++;
			law_migration_log( 'speakers', 'created', $ref, sprintf( 'Created speaker post %d for "%s".', $speaker_id, $name ) );
		}

		// Source linkage: primary + the merged list, and the redirect map.
		if ( ! law_event_meta( $speaker_id, '_law_gf_entry_id' ) ) {
			law_event_update_meta( $speaker_id, '_law_gf_entry_id', $entry_id );
		}
		$all = law_event_meta( $speaker_id, '_law_gf_entry_ids' );
		$all[] = $entry_id;
		law_event_update_meta( $speaker_id, '_law_gf_entry_ids', $all );
		law_migration_map_set( 'speakers', $entry_id, $speaker_id );
		if ( $photo_id ) {
			law_migration_map_set( 'speaker_photos', $entry_id, $photo_id );
		}
		wp_set_object_terms( $speaker_id, (string) law_events_setting( 'year', 2026 ), 'law_year', false );
	}

	return array( 'done' => true, 'summary' => sprintf( '%d created, %d merged into existing.', $created, $merged ) );
}

/** Copy a GF upload into the media library; warn-and-continue when missing. */
function law_migration_import_photo( $raw, $step, $ref ) {
	$url = law_calendar_speaker_photo_url( $raw );
	if ( '' === $url ) {
		return 0;
	}
	$uploads = wp_upload_dir();
	$path    = str_replace( $uploads['baseurl'], $uploads['basedir'], $url );
	if ( ! str_starts_with( $url, (string) $uploads['baseurl'] ) || ! file_exists( $path ) ) {
		law_migration_log( $step, 'warning', $ref, 'Photo file missing on disk (' . basename( $path ) . '): migrated without a photo.' );
		return 0;
	}

	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$filetype      = wp_check_filetype( $path );
	$attachment_id = wp_insert_attachment(
		array(
			'post_mime_type' => $filetype['type'] ?: 'image/jpeg',
			'post_title'     => sanitize_file_name( basename( $path ) ),
			'post_status'    => 'inherit',
		),
		$path
	);
	if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $path ) );
		return (int) $attachment_id;
	}
	return 0;
}

/* Step 3: events ____________________________________________________________ */

function law_migration_run_events( $dry ) {
	$map     = law_migration_map();
	$created = 0;

	foreach ( law_migration_entries( 2, 0, 500 ) as $entry ) {
		$entry_id = (int) $entry['id'];
		$ref      = 'form 2 entry ' . $entry_id;

		if ( ! empty( $map['events'][ $entry_id ] ) && get_post( $map['events'][ $entry_id ] ) ) {
			law_migration_log( 'events', 'skipped', $ref, 'Already migrated to post ' . $map['events'][ $entry_id ] . '.' );
			continue;
		}

		$title = trim( (string) rgar( $entry, '17' ) );
		if ( '' === $title ) {
			law_migration_log( 'events', 'warning', $ref, 'No event title; skipped.' );
			continue;
		}

		$legacy_status  = trim( (string) rgar( $entry, '95' ) );
		$status         = law_event_status_from_legacy( $legacy_status );
		$fee_pence      = (int) round( (float) rgar( $entry, '84' ) );
		$invoice_url    = trim( (string) rgar( $entry, '83' ) );
		$payment_status = law_migration_derive_payment( $legacy_status, $fee_pence, $invoice_url );

		if ( $dry ) {
			law_migration_log( 'events', 'dry-run', $ref, sprintf(
				'Would create "%s" as %s, payment derived %s (was blank, defect 1).',
				$title,
				$status,
				$payment_status
			) );
			continue;
		}

		$author = (int) rgar( $entry, 'created_by' );
		$post_id = wp_insert_post(
			array(
				'post_type'    => LAW_EVENT_CPT,
				'post_status'  => $status,
				'post_title'   => $title,
				'post_content' => wp_kses_post( (string) rgar( $entry, '23' ) ),
				'post_author'  => $author ?: 0,
				'post_date'    => (string) rgar( $entry, 'date_created' ),
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			law_migration_log( 'events', 'error', $ref, 'wp_insert_post failed: ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'unknown' ) );
			continue;
		}

		law_migration_map_set( 'events', $entry_id, $post_id );
		law_migration_populate_event( $post_id, $entry, $payment_status );
		$created++;
		law_migration_log( 'events', 'created', $ref, sprintf(
			'Created event post %d "%s" (%s; payment %s%s).',
			$post_id,
			$title,
			law_event_status_label( $status ),
			$payment_status,
			'' === trim( (string) rgar( $entry, '96' ) ) ? ', derived' : ''
		) );
	}

	return array( 'done' => true, 'summary' => $created . ' events created.' );
}

/**
 * Payment status derivation for the blank field 96 (EVENTS_4.1_REBUILD.md
 * §5.3.3). Field 96 was empty on every active entry (defect 1), so the value
 * is derived purely from the legacy status: a Confirmed event is paid (or free
 * with no fee); anything else is unpaid. ($invoice_url is retained in the
 * signature for the caller; it no longer affects the outcome.)
 */
function law_migration_derive_payment( $legacy_status, $fee_pence, $invoice_url = '' ) {
	if ( 'Confirmed' === $legacy_status ) {
		return $fee_pence > 0 ? 'paid' : 'free';
	}
	return 'unpaid';
}

/**
 * The canonical slot labels: the choices on form 2 (Event > submit an event)
 * field 68 (Confirmed slot), which is also exactly what step 7 seeds into the
 * settings slot list.
 *
 * Read from the form rather than from the settings because step 3 (events)
 * runs before step 7 (counters and settings seed), so during the event pass
 * the settings list is usually still empty.
 *
 * @return string[] Labels, in form order.
 */
function law_migration_slot_labels() {
	static $labels = null;
	if ( null !== $labels ) {
		return $labels;
	}

	$labels = array();
	if ( ! class_exists( 'GFAPI' ) ) {
		return $labels;
	}

	$form = GFAPI::get_form( 2 );
	foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
		if ( 68 !== (int) $field->id ) {
			continue;
		}
		foreach ( (array) $field->choices as $choice ) {
			$labels[] = html_entity_decode( (string) $choice['text'], ENT_QUOTES );
		}
	}
	return $labels;
}

/**
 * Map a legacy slot value onto its canonical label.
 *
 * Field 68 (Confirmed slot) and field 77 (Preferred date & time slots) hold the
 * same twelve slots with different punctuation: en dashes on 68, plain hyphens
 * on 77. Left alone, a migrated event's preferred slots would match no
 * configured slot and the host form would render them all unchecked. Anything
 * that matches no choice is kept verbatim rather than dropped.
 *
 * @param string $label Raw entry value.
 * @return string Canonical label, or the trimmed input when unrecognised.
 */
function law_migration_normalise_slot_label( $label ) {
	$label = trim( (string) $label );
	if ( '' === $label ) {
		return '';
	}

	$key = law_events_slot_label_key( $label );
	foreach ( law_migration_slot_labels() as $canonical ) {
		if ( law_events_slot_label_key( $canonical ) === $key ) {
			return $canonical;
		}
	}
	return $label;
}

/**
 * Whether a slot was retired on form 2 (Event > submit an event) field 77
 * (Preferred date & time slots) by LAW_GF_RETIRED_PREFERRED_SLOTS, the constant
 * that hid withdrawn choices from hosts while Gravity Forms was still the
 * submission route. Those retirements are real programme decisions, so they
 * have to survive the cutover as the settings list's own retired flag.
 *
 * @param string $label Slot label.
 * @return bool
 */
function law_migration_slot_was_retired( $label ) {
	if ( ! defined( 'LAW_GF_RETIRED_PREFERRED_SLOTS' ) ) {
		return false;
	}

	$key = law_events_slot_label_key( $label );
	foreach ( (array) LAW_GF_RETIRED_PREFERRED_SLOTS as $retired ) {
		if ( law_events_slot_label_key( $retired ) === $key ) {
			return true;
		}
	}
	return false;
}

/** All the meta, taxonomy and relationship writes for one migrated event. */
function law_migration_populate_event( $post_id, array $entry, $payment_status ) {
	$entry_id = (int) $entry['id'];
	$map      = law_migration_map();

	// Slot: parse the label into real datetimes (handles the en dash).
	$slot_label = law_migration_normalise_slot_label( rgar( $entry, '68' ) );
	$slot       = $slot_label ? law_calendar_parse_slot( $slot_label ) : null;
	law_event_update_meta( $post_id, '_law_slot_label', $slot_label );
	if ( $slot ) {
		law_event_update_meta( $post_id, '_law_start', $slot['date'] . ' ' . $slot['start'] );
		law_event_update_meta( $post_id, '_law_end', $slot['end'] ? $slot['date'] . ' ' . $slot['end'] : '' );
	}

	// Preferred slots: JSON multiselect. Field 77 (Preferred date & time slots)
	// stores hyphenated labels where the settings list is seeded from field 68
	// (Confirmed slot) with en dashes, so every value is mapped onto its
	// canonical label or the host form would show them all unchecked.
	$preferred = json_decode( (string) rgar( $entry, '77' ), true );
	$preferred = array_values( array_filter( array_map(
		'law_migration_normalise_slot_label',
		is_array( $preferred ) ? $preferred : array()
	) ) );
	law_event_update_meta( $post_id, '_law_preferred_slots', $preferred );

	// Fee tier from the product value "UK office|1200".
	$tier_value = strtolower( trim( explode( '|', (string) rgar( $entry, '53' ) )[0] ) );
	$tier       = str_contains( $tier_value, 'uk' ) ? 'uk' : ( str_contains( $tier_value, 'inter' ) ? 'international' : ( str_contains( $tier_value, 'sponsor' ) ? 'sponsor' : '' ) );
	law_event_update_meta( $post_id, '_law_fee_tier', $tier );

	$writes = array(
		'_law_reference'           => rgar( $entry, '70' ),
		'_law_venue'               => rgar( $entry, '21' ),
		'_law_venue_needed'        => rgar( $entry, '103' ),
		'_law_venue_capacity'      => rgar( $entry, '55' ),
		'_law_tickets_available'   => rgar( $entry, '54' ),
		'_law_host_organisations'  => rgar( $entry, '105' ),
		'_law_fee_pence'           => rgar( $entry, '84' ),
		'_law_vat'                 => rgar( $entry, '85' ),
		'_law_fee_override'        => '' !== trim( (string) rgar( $entry, '87.1' ) ),
		'_law_fee_override_amount' => rgar( $entry, '81' ),
		'_law_invoice_name'        => trim( rgar( $entry, '75.3' ) . ' ' . rgar( $entry, '75.6' ) ),
		'_law_invoice_email'       => rgar( $entry, '73' ),
		'_law_country_iso'         => rgar( $entry, '88' ),
		'_law_vat_number'          => rgar( $entry, '79' ),
		'_law_stripe_invoice_url'  => rgar( $entry, '83' ),
		'_law_approved_at'         => rgar( $entry, '78' ),
		'_law_rejection_reason'    => rgar( $entry, '67' ),
		'_law_sector_jurisdiction' => rgar( $entry, '61' ),
		'_law_sector_other'        => rgar( $entry, '62' ),
		'_law_gf_entry_id'         => $entry_id,
		'_law_payment_status'      => $payment_status,
	);
	foreach ( $writes as $key => $value ) {
		law_event_update_meta( $post_id, $key, $value );
	}

	law_event_update_meta(
		$post_id,
		'_law_invoice_address',
		array(
			'line1'       => rgar( $entry, '74.1' ),
			'line2'       => rgar( $entry, '74.2' ),
			'city'        => rgar( $entry, '74.3' ),
			'state'       => rgar( $entry, '74.4' ),
			'postal_code' => rgar( $entry, '74.5' ),
			'country'     => rgar( $entry, '74.6' ),
		)
	);

	// Consent (field 69): value + entry date as the acceptance time.
	if ( '' !== trim( (string) rgar( $entry, '69.1' ) ) ) {
		law_event_update_meta( $post_id, '_law_terms_consent', array( 'accepted' => 1, 'at' => (string) rgar( $entry, 'date_created' ) ) );
	}

	// Assignee: field 90 (Committee assignee) stores EMAIL ADDRESSES on the
	// live data (73 of 75 entries), with names/IDs as older variants.
	$assignee = law_migration_resolve_assignee( rgar( $entry, '90' ) );
	if ( $assignee ) {
		law_event_update_meta( $post_id, '_law_assignee', $assignee->ID );
	} elseif ( '' !== trim( (string) rgar( $entry, '90' ) ) ) {
		law_migration_log( 'events', 'warning', 'form 2 entry ' . $entry_id, sprintf( 'Assignee value "%s" matched no user.', rgar( $entry, '90' ) ) );
	}

	// Organisation links (field 109: multiselect of organisation post IDs).
	$org_ids = law_calendar_entry_ids_from_value( rgar( $entry, '109' ) );
	law_event_update_meta( $post_id, '_law_organisation_ids', $org_ids );

	// Taxonomies: sectors (60.x checkbox inputs), type (63), year.
	$sectors = array();
	foreach ( $entry as $key => $value ) {
		if ( 0 === strpos( (string) $key, '60.' ) && '' !== $value ) {
			$sectors[] = law_events_sector_term_name( $value );
		}
	}
	law_events_set_terms_by_name( $post_id, 'law_sector', $sectors );
	law_events_set_terms_by_name( $post_id, 'law_event_type', array( (string) rgar( $entry, '63' ) ) );
	wp_set_object_terms( $post_id, (string) law_events_setting( 'year', 2026 ), 'law_year', false );

	// Contacts (form 4 children) and co-owner rows (form 6 children).
	$contacts = array();
	foreach ( law_migration_children( 4, $entry_id ) as $child ) {
		$contacts[] = array(
			'name'         => trim( rgar( $child, '1.3' ) . ' ' . rgar( $child, '1.6' ) ),
			'organisation' => (string) rgar( $child, '3' ),
			'email'        => (string) rgar( $child, '4' ),
		);
	}
	law_event_update_meta( $post_id, '_law_contacts', $contacts );

	$co_owners = array();
	$co_ids    = array();
	foreach ( law_migration_children( 6, $entry_id ) as $child ) {
		$email       = sanitize_email( (string) rgar( $child, '4' ) );
		$co_owners[] = array(
			'name'         => trim( rgar( $child, '1.3' ) . ' ' . rgar( $child, '1.6' ) ),
			'organisation' => (string) rgar( $child, '3' ),
			'email'        => $email,
		);
		// Approved/Confirmed events link the accounts step 1 created.
		if ( in_array( get_post_status( $post_id ), array( 'law-approved', 'publish' ), true ) && is_email( $email ) ) {
			$user = get_user_by( 'email', $email );
			if ( $user ) {
				$co_ids[] = (int) $user->ID;
			}
		}
	}
	law_event_update_meta( $post_id, '_law_co_owner_rows', $co_owners );
	law_event_set_co_owner_ids( $post_id, $co_ids );

	// Speakers: one appearance row per nested child (form 8) in entry order,
	// carrying THAT entry's organisation, job title and photo (step 4b refreshes
	// the same rows later). Legacy field 48 list rows are the fallback.
	law_event_update_meta( $post_id, '_law_speakers', law_migration_speaker_rows( $entry, law_migration_map() ) );
}

/**
 * The appearance rows for a form 2 (Event > submit an event) entry: nested
 * form 8 (Event > speaker) children via the step 2 map, deduped within the
 * event (first child wins), else legacy field 48 (Speakers (list)) rows
 * matched to posts by name. Each row carries the role (field 9 Role, a
 * Speaker / Host / Moderator drop down added on 3 September 2026, mapped by
 * label to the law_speaker_roles() key and '' where the field or value is
 * absent), organisation (field 3 Organisation / firm / chambers), job title
 * (field 4 Job title / role), photo (field 6 Photo) and biography (field 7
 * Biography) the speaker had at THIS event: the values the shared speaker post
 * can no longer hold, since one person speaks for different firms, and writes
 * a different biography, at different events.
 *
 * @param array $entry Form 2 entry.
 * @param array $map   law_migration_map().
 * @param bool  $dry   Dry run: never imports a photo file.
 */
function law_migration_speaker_rows( array $entry, array $map, $dry = false ) {
	$rows = array();
	$sort = 0;
	foreach ( law_migration_children( 8, (int) $entry['id'] ) as $child ) {
		$speaker_post = (int) ( $map['speakers'][ (int) $child['id'] ] ?? 0 );
		if ( ! $speaker_post || in_array( $speaker_post, wp_list_pluck( $rows, 'speaker_id' ), true ) ) {
			continue;
		}
		$rows[] = array(
			'speaker_id'   => $speaker_post,
			'role'         => law_speaker_role_key( rgar( $child, '9' ) ),
			'organisation' => trim( (string) rgar( $child, '3' ) ),
			'job_title'    => trim( (string) rgar( $child, '4' ) ),
			'photo_id'     => law_migration_child_photo_id( $child, $speaker_post, $map, $dry ),
			'bio'          => trim( (string) rgar( $child, '7' ) ),
			'sort'         => $sort++,
		);
	}
	if ( ! $rows ) {
		// Legacy list rows (entries 303/774 pattern): match created posts by name.
		foreach ( law_calendar_speakers_from_list( rgar( $entry, '48' ) ) as $row ) {
			$speaker_post = law_speaker_find_existing( '', $row['name'] );
			if ( $speaker_post && ! in_array( $speaker_post, wp_list_pluck( $rows, 'speaker_id' ), true ) ) {
				$rows[] = array(
					'speaker_id'   => $speaker_post,
					'role'         => '', // The list field has no role column either.
					'organisation' => (string) $row['organisation'],
					'job_title'    => (string) $row['job_title'],
					'photo_id'     => 0,
					'bio'          => '', // The list field has no biography column.
					'sort'         => $sort++,
				);
			}
		}
	}
	return $rows;
}

/**
 * The attachment for one child entry's photo (field 6): the step 2 map first;
 * when step 2 ran before photos were per appearance, the speaker post's
 * featured image if this child is that post's primary source entry (the file
 * was sideloaded from it); otherwise import the file now. Whatever is found is
 * recorded in the map so the next run is a lookup.
 */
function law_migration_child_photo_id( array $child, $speaker_post, array $map, $dry = false ) {
	$child_id = (int) $child['id'];
	$mapped   = (int) ( $map['speaker_photos'][ $child_id ] ?? 0 );
	if ( $mapped && get_post( $mapped ) ) {
		return $mapped;
	}
	if ( '' === law_calendar_speaker_photo_url( rgar( $child, '6' ) ) ) {
		return 0;
	}
	$photo_id = 0;
	if ( (int) law_event_meta( $speaker_post, '_law_gf_entry_id' ) === $child_id && has_post_thumbnail( $speaker_post ) ) {
		$photo_id = (int) get_post_thumbnail_id( $speaker_post );
	} elseif ( ! $dry ) {
		$photo_id = law_migration_import_photo( rgar( $child, '6' ), 'speaker_appearances', 'form 8 entry ' . $child_id );
	}
	if ( $photo_id && ! $dry ) {
		law_migration_map_set( 'speaker_photos', $child_id, $photo_id );
	}
	return $photo_id;
}

/**
 * Session speaker rows: the referenced speakers (form 9 field 6), each carrying
 * the parent event's appearance details for the same speaker.
 */
function law_migration_session_speaker_rows( array $child, $parent_post, array $map ) {
	$event_rows = array();
	foreach ( law_event_meta( $parent_post, '_law_speakers' ) as $row ) {
		$event_rows[ (int) $row['speaker_id'] ] = $row;
	}
	$rows = array();
	$sort = 0;
	foreach ( law_calendar_entry_ids_from_value( rgar( $child, '6' ) ) as $speaker_entry ) {
		$speaker_post = (int) ( $map['speakers'][ $speaker_entry ] ?? 0 );
		if ( ! $speaker_post || in_array( $speaker_post, wp_list_pluck( $rows, 'speaker_id' ), true ) ) {
			continue;
		}
		$event_row = $event_rows[ $speaker_post ] ?? array();
		$rows[]    = array(
			'speaker_id'   => $speaker_post,
			'role'         => (string) ( $event_row['role'] ?? '' ),
			'organisation' => (string) ( $event_row['organisation'] ?? '' ),
			'job_title'    => (string) ( $event_row['job_title'] ?? '' ),
			'photo_id'     => (int) ( $event_row['photo_id'] ?? 0 ),
			'bio'          => (string) ( $event_row['bio'] ?? '' ),
			'sort'         => $sort++,
		);
	}
	return $rows;
}

/**
 * Resolve a field 90 (Committee assignee) value to a user: email first (the
 * live data), then numeric ID, login, display name.
 *
 * @param mixed $raw Stored field value.
 * @return WP_User|null
 */
function law_migration_resolve_assignee( $raw ) {
	$raw = trim( (string) $raw );
	if ( '' === $raw ) {
		return null;
	}
	if ( is_email( $raw ) ) {
		$user = get_user_by( 'email', $raw );
		if ( $user ) {
			return $user;
		}
	}
	if ( is_numeric( $raw ) ) {
		$user = get_user_by( 'id', (int) $raw );
		if ( $user ) {
			return $user;
		}
	}
	return get_user_by( 'login', strtolower( $raw ) ) ?: law_migration_user_by_display_name( $raw );
}

function law_migration_user_by_display_name( $name ) {
	$users = get_users( array( 'search' => '*' . $name . '*', 'number' => 2 ) );
	foreach ( $users as $user ) {
		if ( 0 === strcasecmp( $user->display_name, $name ) || 0 === strcasecmp( $user->first_name, $name ) ) {
			return $user;
		}
	}
	return null;
}

/* Step 2b (inside speakers): legacy list rows on unmigrated entries _________ */

function law_migration_run_legacy_lists( $dry ) {
	$created = 0;
	foreach ( law_migration_entries( 2, 0, 500 ) as $entry ) {
		if ( law_migration_children( 8, (int) $entry['id'] ) ) {
			continue; // Nested speakers exist; the list is already superseded.
		}
		foreach ( law_calendar_speakers_from_list( rgar( $entry, '48' ) ) as $row ) {
			$ref = 'form 2 entry ' . $entry['id'] . ' list row "' . $row['name'] . '"';
			if ( law_speaker_find_existing( '', $row['name'] ) ) {
				law_migration_log( 'speakers', 'skipped', $ref, 'Speaker already exists.' );
				continue;
			}
			if ( $dry ) {
				law_migration_log( 'speakers', 'dry-run', $ref, 'Would create a speaker from the legacy list (no email, no photo).' );
				continue;
			}
			// The legacy List field 48 has ONE name column, so the upsert splits it
			// on the last word (law_speaker_split_name(), honorifics kept on the
			// last name). Nested form 8 rows above carry the two parts properly.
			$speaker_id = law_speaker_upsert(
				array(
					'name'         => $row['name'],
					'organisation' => $row['organisation'],
					'job_title'    => $row['job_title'],
					'website'      => $row['url'],
				)
			);
			if ( $speaker_id ) {
				$created++;
				wp_set_object_terms( $speaker_id, (string) law_events_setting( 'year', 2026 ), 'law_year', false );
				law_migration_log( 'speakers', 'created', $ref, 'Created speaker post ' . $speaker_id . ' from the legacy list field 48.' );
			}
		}
	}
	return $created;
}

/* Step 4: sessions __________________________________________________________ */

function law_migration_run_sessions( $dry ) {
	$map     = law_migration_map();
	$created = 0;

	foreach ( law_migration_entries( 9, 0, 500 ) as $child ) {
		$entry_id = (int) $child['id'];
		$ref      = 'form 9 entry ' . $entry_id;

		$existing = get_posts( array( 'post_type' => LAW_SESSION_CPT, 'meta_key' => '_law_gf_entry_id', 'meta_value' => $entry_id, 'fields' => 'ids', 'posts_per_page' => 1, 'post_status' => 'any' ) );
		if ( $existing ) {
			law_migration_log( 'sessions', 'skipped', $ref, 'Already migrated to post ' . $existing[0] . '.' );
			continue;
		}

		$parent_entry = (int) rgar( $child, 'gpnf_entry_parent' );
		$parent_post  = (int) ( $map['events'][ $parent_entry ] ?? 0 );
		if ( ! $parent_post ) {
			law_migration_log( 'sessions', 'skipped', $ref, 'Parent event not migrated (orphan or trashed parent).' );
			continue;
		}

		if ( $dry ) {
			law_migration_log( 'sessions', 'dry-run', $ref, sprintf( 'Would create session "%s" under event post %d.', rgar( $child, '4' ), $parent_post ) );
			continue;
		}

		$session_id = wp_insert_post(
			array(
				'post_type'    => LAW_SESSION_CPT,
				'post_status'  => 'publish',
				'post_parent'  => $parent_post,
				'post_title'   => sanitize_text_field( (string) rgar( $child, '4' ) ),
				'post_content' => sanitize_textarea_field( (string) rgar( $child, '5' ) ),
			)
		);
		if ( ! $session_id || is_wp_error( $session_id ) ) {
			law_migration_log( 'sessions', 'error', $ref, 'wp_insert_post failed.' );
			continue;
		}
		law_event_update_meta( $session_id, '_law_start_time', rgar( $child, '1' ) );
		law_event_update_meta( $session_id, '_law_end_time', rgar( $child, '3' ) );
		law_event_update_meta( $session_id, '_law_gf_entry_id', $entry_id );

		law_event_update_meta( $session_id, '_law_speakers', law_migration_session_speaker_rows( $child, $parent_post, $map ) );

		$created++;
		law_migration_log( 'sessions', 'created', $ref, sprintf( 'Created session post %d under event post %d.', $session_id, $parent_post ) );
	}

	return array( 'done' => true, 'summary' => $created . ' sessions created.' );
}

/* Step 4b: speaker appearance details _____________________________________ */

/**
 * Refresh every migrated event's speaker rows with the organisation, job title,
 * photo and biography from their source form 8 (Event > speaker) child
 * entries, and
 * fill blank session rows from the event. Idempotent: rows already carrying
 * the source values are reported as current, rows the committee added in
 * wp-admin (no source child) are left alone, and a source value that is empty
 * never blanks a stored one. Safe to run on a database migrated before speaker
 * details became per event, and as a post-migration check on production.
 */
function law_migration_run_speaker_appearances( $dry ) {
	$map       = law_migration_map();
	$changed   = 0;
	$unchanged = 0;

	foreach ( $map['events'] as $entry_id => $post_id ) {
		$post_id = (int) $post_id;
		$ref     = 'form 2 entry ' . (int) $entry_id . ' → event post ' . $post_id;
		if ( ! $post_id || LAW_EVENT_CPT !== get_post_type( $post_id ) ) {
			continue;
		}
		$entry = class_exists( 'GFAPI' ) ? GFAPI::get_entry( (int) $entry_id ) : null;
		if ( ! is_array( $entry ) ) {
			law_migration_log( 'speaker_appearances', 'skipped', $ref, 'Source entry missing; nothing to refresh from.' );
			continue;
		}

		$source = array();
		foreach ( law_migration_speaker_rows( $entry, $map, $dry ) as $row ) {
			$source[ (int) $row['speaker_id'] ] = $row;
		}

		$rows  = array();
		$diffs = array();
		foreach ( law_event_meta( $post_id, '_law_speakers' ) as $row ) {
			$from = $source[ (int) $row['speaker_id'] ] ?? null;
			if ( $from ) {
				foreach ( array( 'role', 'organisation', 'job_title', 'photo_id', 'bio' ) as $field ) {
					// Compare what the schema would store (whitespace collapsed), or a
					// double space in the source would re-flag the row on every run.
					// The biography keeps its line breaks, so it takes the textarea
					// sanitiser rather than the single-line one. The role is already a
					// key; an empty source (field 9 absent, or left on "Select role")
					// never blanks a role the committee set, by the ! empty rule below.
					if ( 'photo_id' === $field ) {
						$new = (int) $from[ $field ];
					} elseif ( 'role' === $field ) {
						$new = law_speaker_role_key( $from[ $field ] );
					} elseif ( 'bio' === $field ) {
						$new = sanitize_textarea_field( (string) $from[ $field ] );
					} else {
						$new = sanitize_text_field( (string) $from[ $field ] );
					}
					$old = $row[ $field ] ?? ( 'photo_id' === $field ? 0 : '' );
					if ( ! empty( $new ) && (string) $new !== (string) $old ) {
						// Biographies are paragraphs: trimmed in the log line so one
						// speaker cannot drown the step's report.
						$diffs[]       = sprintf(
							'%s %s "%s" → "%s"',
							get_the_title( (int) $row['speaker_id'] ),
							str_replace( '_', ' ', $field ),
							wp_html_excerpt( (string) $old, 60, '…' ),
							wp_html_excerpt( (string) $new, 60, '…' )
						);
						$row[ $field ] = $new;
					}
				}
			}
			$rows[] = $row;
		}

		if ( ! $diffs ) {
			$unchanged++;
			law_migration_log( 'speaker_appearances', 'skipped', $ref, 'Speaker rows already carry the source details.' );
			continue;
		}
		if ( $dry ) {
			// Counted, not just logged: a dry run that found work used to report
			// "0 events refreshed, N already current", which reads as nothing to do.
			$changed++;
			law_migration_log( 'speaker_appearances', 'dry-run', $ref, 'Would set ' . implode( '; ', $diffs ) . '.' );
			continue;
		}

		law_event_update_meta( $post_id, '_law_speakers', $rows );

		// Sessions inherit the event's details where their own rows are blank.
		$by_speaker = array();
		foreach ( $rows as $row ) {
			$by_speaker[ (int) $row['speaker_id'] ] = $row;
		}
		foreach ( law_event_session_ids( $post_id ) as $session_id ) {
			$session_rows = law_event_meta( $session_id, '_law_speakers' );
			$touched      = false;
			foreach ( $session_rows as &$session_row ) {
				$from = $by_speaker[ (int) $session_row['speaker_id'] ] ?? null;
				if ( ! $from ) {
					continue;
				}
				foreach ( array( 'role', 'organisation', 'job_title', 'photo_id', 'bio' ) as $field ) {
					if ( empty( $session_row[ $field ] ) && ! empty( $from[ $field ] ) ) {
						$session_row[ $field ] = $from[ $field ];
						$touched               = true;
					}
				}
			}
			unset( $session_row );
			if ( $touched ) {
				law_event_update_meta( $session_id, '_law_speakers', $session_rows );
			}
		}

		$changed++;
		law_migration_log( 'speaker_appearances', 'created', $ref, 'Set ' . implode( '; ', $diffs ) . '.' );
	}

	return array(
		'done'    => true,
		'summary' => sprintf( '%d events %s, %d already current.', $changed, $dry ? 'to refresh' : 'refreshed', $unchanged ),
	);
}

/* Step 5: comments __________________________________________________________ */

function law_migration_run_comments( $dry ) {
	$map     = law_migration_map();
	$created = 0;

	foreach ( law_migration_entries( 5, 0, 500 ) as $child ) {
		$entry_id = (int) $child['id'];
		$ref      = 'form 5 entry ' . $entry_id;

		$parent_entry = (int) rgar( $child, 'gpnf_entry_parent' );
		$parent_post  = (int) ( $map['events'][ $parent_entry ] ?? 0 );
		if ( ! $parent_post ) {
			law_migration_log( 'comments', 'skipped', $ref, 'Parent event not migrated (orphan or trashed parent).' );
			continue;
		}

		// Idempotency: one comment per source entry, tracked in comment meta.
		$existing = get_comments( array( 'post_id' => $parent_post, 'type' => LAW_EVENT_COMMENT_TYPE, 'meta_key' => '_law_gf_entry_id', 'meta_value' => $entry_id, 'count' => true ) );
		if ( $existing ) {
			law_migration_log( 'comments', 'skipped', $ref, 'Already migrated.' );
			continue;
		}

		if ( $dry ) {
			law_migration_log( 'comments', 'dry-run', $ref, 'Would migrate the comment to event post ' . $parent_post . '.' );
			continue;
		}

		$email = sanitize_email( (string) rgar( $child, '4' ) );
		$user  = is_email( $email ) ? get_user_by( 'email', $email ) : null;
		$comment_id = law_event_add_comment(
			$parent_post,
			(string) rgar( $child, '1' ),
			$user ? $user->ID : 0,
			array(
				'author_name'  => trim( rgar( $child, '3.3' ) . ' ' . rgar( $child, '3.6' ) ),
				'author_email' => $email,
				'date'         => (string) rgar( $child, 'date_created' ),
			)
		);
		if ( $comment_id ) {
			add_comment_meta( $comment_id, '_law_gf_entry_id', $entry_id );
			$created++;
			law_migration_log( 'comments', 'created', $ref, 'Migrated to event post ' . $parent_post . '.' );
		} else {
			law_migration_log( 'comments', 'error', $ref, 'wp_insert_comment failed (empty content?).' );
		}
	}

	return array( 'done' => true, 'summary' => $created . ' comments migrated.' );
}

/* Step 6: workflow history ___________________________________________________ */

function law_migration_run_history( $dry ) {
	global $wpdb;
	$map      = law_migration_map();
	$migrated = 0;

	// One pass over every revision entry, keyed by parent, instead of a
	// grouped scan per event.
	$all_revisions = $wpdb->get_results(
		"SELECT e.id,
			MAX(CASE WHEN em.meta_key = 'gv_revision_parent_id' THEN em.meta_value END) AS parent_id,
			MAX(CASE WHEN em.meta_key = 'gv_revision_date' THEN em.meta_value END) AS revision_date,
			MAX(CASE WHEN em.meta_key = 'gv_revision_user_id' THEN em.meta_value END) AS revision_user
		 FROM {$wpdb->prefix}gf_entry e
		 JOIN {$wpdb->prefix}gf_entry_meta em ON em.entry_id = e.id
			AND em.meta_key IN ('gv_revision_parent_id','gv_revision_date','gv_revision_user_id')
		 GROUP BY e.id"
	);
	$revisions_by_parent = array();
	foreach ( (array) $all_revisions as $row ) {
		$revisions_by_parent[ (int) $row->parent_id ][] = $row;
	}

	$started = time();

	foreach ( $map['events'] as $entry_id => $post_id ) {
		// Time-box a real run: writing every timeline (~1,000 comment inserts)
		// in one request exceeds a proxy upstream timeout (seen on Kinsta), so
		// stop BETWEEN events after ~20s and let the page JS request the next
		// batch. The guard never fires mid-event, so an event is always either
		// fully written and flagged or untouched — re-runs cannot duplicate.
		if ( ! $dry && $migrated > 0 && time() - $started >= 20 ) {
			return array( 'done' => false, 'summary' => $migrated . ' event histories migrated this batch; more remain.' );
		}
		if ( ! get_post( $post_id ) ) {
			continue;
		}
		$ref = 'form 2 entry ' . $entry_id;

		if ( get_post_meta( $post_id, '_law_history_migrated', true ) ) {
			law_migration_log( 'history', 'skipped', $ref, 'History already migrated.' );
			continue;
		}

		$notes = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_name, note_type, value, date_created FROM {$wpdb->prefix}gf_entry_notes WHERE entry_id = %d ORDER BY id ASC",
				(int) $entry_id
			)
		);
		$revisions = $revisions_by_parent[ (int) $entry_id ] ?? array();

		if ( $dry ) {
			law_migration_log( 'history', 'dry-run', $ref, sprintf( 'Would migrate %d timeline notes and %d edit revisions.', count( $notes ), count( $revisions ) ) );
			continue;
		}

		foreach ( (array) $notes as $note ) {
			law_event_log(
				$post_id,
				'[migrated ' . ( $note->note_type ?: 'note' ) . '] ' . $note->value,
				array( 'action' => 'migrated_note', 'note_type' => (string) $note->note_type, 'source' => 'migration' ),
				array( 'user_id' => 0, 'date' => $note->date_created )
			);
		}
		foreach ( (array) $revisions as $revision ) {
			$editor = $revision->revision_user ? get_user_by( 'id', (int) $revision->revision_user ) : null;
			law_event_log(
				$post_id,
				sprintf( '[migrated] Entry edited by %s (full snapshot retained in the GF archive, revision entry %d).', $editor ? $editor->display_name : 'unknown user', (int) $revision->id ),
				array( 'action' => 'migrated_revision', 'source' => 'migration' ),
				array( 'user_id' => 0, 'date' => $revision->revision_date ?: current_time( 'mysql' ) )
			);
		}

		law_event_log(
			$post_id,
			sprintf(
				'Migrated from Gravity Forms entry %d. Status %s carried over; payment status "%s" was derived (field 96 was blank, defect 1).',
				(int) $entry_id,
				law_event_status_label( get_post_status( $post_id ) ),
				(string) law_event_meta( $post_id, '_law_payment_status' )
			),
			array( 'action' => 'migrated', 'gf_entry_id' => (int) $entry_id, 'source' => 'migration' ),
			array( 'user_id' => 0 )
		);

		update_post_meta( $post_id, '_law_history_migrated', 1 );
		$migrated++;
		law_migration_log( 'history', 'created', $ref, sprintf( 'Migrated %d notes and %d revision lines.', count( $notes ), count( $revisions ) ) );
	}

	return array( 'done' => true, 'summary' => $migrated . ' event histories migrated.' );
}

/* Step 7: counters and settings seed _________________________________________ */

function law_migration_run_counters( $dry ) {
	global $wpdb;

	$sequence = (int) $wpdb->get_var( "SELECT current FROM {$wpdb->prefix}gpui_sequence WHERE form_id = 2 AND field_id = 70" );

	// Slot choices from form 2 (Event > submit an event) field 68 (Confirmed
	// slot). A slot counts as retired if LAW_GF_RETIRED_PREFERRED_SLOTS hid it
	// on field 77 (Preferred date & time slots), or if it is already flagged
	// retired in the settings - this step rewrites the whole slot list, so
	// without the second test a re-run would quietly un-retire a slot the
	// committee had withdrawn by hand.
	$retired_keys = array();
	foreach ( law_events_slots( true ) as $existing_label => $existing_slot ) {
		if ( ! empty( $existing_slot['retired'] ) ) {
			$retired_keys[ law_events_slot_label_key( $existing_label ) ] = true;
		}
	}

	$slots   = array();
	$retired = 0;
	foreach ( law_migration_slot_labels() as $label ) {
		$parsed     = law_calendar_parse_slot( $label );
		$is_retired = law_migration_slot_was_retired( $label ) || isset( $retired_keys[ law_events_slot_label_key( $label ) ] );
		$retired   += $is_retired ? 1 : 0;
		$slots[]    = array(
			'label'   => $label,
			'date'    => $parsed['date'] ?? '',
			'start'   => $parsed['start'] ?? '',
			'end'     => $parsed['end'] ?? '',
			'retired' => $is_retired,
		);
	}

	// Committee recipients from the current form 2 notifications.
	$committee = array();
	if ( class_exists( 'GFAPI' ) ) {
		$form = GFAPI::get_form( 2 );
		foreach ( (array) ( $form['notifications'] ?? array() ) as $notification ) {
			if ( false !== stripos( (string) ( $notification['name'] ?? '' ), 'committee' ) ) {
				foreach ( explode( ',', (string) ( $notification['to'] ?? '' ) ) as $email ) {
					$email = sanitize_email( trim( $email ) );
					if ( is_email( $email ) ) {
						$committee[] = $email;
					}
				}
			}
		}
		$committee = array_values( array_unique( $committee ) );
	}

	if ( $dry ) {
		law_migration_log( 'counters', 'dry-run', 'settings', sprintf(
			'Would seed: reference counter %d, %d slot choices (%d retired), %d committee recipients.',
			$sequence,
			count( $slots ),
			$retired,
			count( $committee )
		) );
		return array( 'done' => true, 'summary' => 'Dry run.' );
	}

	if ( $sequence > (int) get_option( 'law_events_reference_counter', 0 ) ) {
		update_option( 'law_events_reference_counter', $sequence, false );
	}

	// User-meta cleanup: early co-owner accounts stored their organisation
	// under law_organisation_name; the site-wide key is 'organisation'.
	// Idempotent: moves only where the target is empty, then drops the old key.
	$stranded = $wpdb->get_results( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = 'law_organisation_name'" );
	$moved    = 0;
	foreach ( (array) $stranded as $row ) {
		if ( '' === (string) get_user_meta( $row->user_id, 'organisation', true ) ) {
			update_user_meta( $row->user_id, 'organisation', $row->meta_value );
			$moved++;
		}
		delete_user_meta( $row->user_id, 'law_organisation_name' );
	}
	if ( $stranded ) {
		law_migration_log( 'counters', 'created', 'user meta', sprintf( 'Moved %d stranded law_organisation_name values to the organisation key (%d rows cleaned).', $moved, count( $stranded ) ) );
	}
	$changes = array( 'slots' => $slots );
	if ( $committee ) {
		$changes['committee_emails'] = $committee;
	}
	law_events_update_settings( $changes );
	law_events_seed_terms();

	law_migration_log( 'counters', 'created', 'settings', sprintf(
		'Seeded: reference counter %d, %d slot choices (%d retired), %d committee recipients, taxonomy terms.',
		$sequence,
		count( $slots ),
		$retired,
		count( $committee )
	) );
	return array( 'done' => true, 'summary' => 'Settings seeded.' );
}

/* Step 8: redirects (the map is built by steps 2/3; verify and report) _______ */

function law_migration_run_redirects( $dry ) {
	$map = law_migration_map();
	law_migration_log( 'redirects', $dry ? 'dry-run' : 'created', 'map', sprintf(
		'Entry map holds %d events and %d speakers; ?event=<entry ID> and /speakers/<entry ID>/ requests 301 through it after the source flip.',
		count( $map['events'] ),
		count( $map['speakers'] )
	) );
	return array( 'done' => true, 'summary' => 'Redirect map verified.' );
}

/* Step 9: notifications ______________________________________________________ */

/** GF merge tag → module placeholder translation. */
function law_migration_translate_tags( $text ) {
	$translations = array(
		'{Email:7}'                  => '{host_email}',
		// First+Last pairs collapse into one {host_name}: First carries it,
		// Last drops (mapping both would print the name twice).
		'{Name (First):3.3}'         => '{host_name}',
		'{Name (Last):3.6}'          => '',
		'{Name:3}'                   => '{host_name}',
		'{Event title:17}'           => '{event_title}',
		'{Unique ID:70}'             => '{law_reference}',
		'{Event status:95}'          => '{status}',
		'{Confirmed slot:68}'        => '{slot}',
		'{Venue:21}'                 => '{venue}',
		'{Stripe invoice URL:83}'    => '{invoice_url}',
		'{Reason for rejection:67}'  => '{rejection_reason}',
		// GF's submission table becomes the module's rendered facts block.
		'{all_fields}'               => '{event_summary}',
		'{embed_url}'                => '{committee_link}',
		'{entry_id}'                 => '{law_reference}',
		'{entry_url}'                => '{committee_link}',
		'{entry_revision_diff}'      => '(see the event\'s activity log for the change history)',
		'{ID:100}'                   => '{law_reference}',
		'{admin_email}'              => get_option( 'admin_email' ),
		'{site_title}'               => '{site_name}',
	);
	return strtr( (string) $text, $translations );
}

/** Slugs the current GF notifications map onto. */
function law_migration_notification_slug_map() {
	return array(
		'Email to user > event submitted'                   => 'user_submitted',
		'Email to committee > event submitted'              => 'committee_submitted',
		'Email to Square Eye > event submitted'             => 'squareeye_submitted',
		'Email to user > event approved, pending payment'   => 'user_payment_due',
		'Email to committee > event approved'               => 'committee_approved',
		'Email to user (sponsor) > event confirmed'         => 'user_confirmed_free',
		'Email to user (non-sponsor) > event confirmed'     => 'user_confirmed_paid',
		'Email to committee > event updated'                => 'committee_event_updated',
		'Email to Square Eye > event updated'               => 'squareeye_event_updated',
	);
}

function law_migration_run_notifications( $dry ) {
	if ( ! class_exists( 'GFAPI' ) ) {
		return array( 'done' => true, 'summary' => 'GFAPI unavailable.' );
	}
	$form      = GFAPI::get_form( 2 );
	$slug_map  = law_migration_notification_slug_map();
	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	if ( ! is_array( $overrides ) ) {
		$overrides = array();
	}
	$migrated = 0;

	foreach ( (array) ( $form['notifications'] ?? array() ) as $notification ) {
		$name = (string) ( $notification['name'] ?? '' );
		$ref  = 'notification "' . $name . '"';
		$slug = $slug_map[ $name ] ?? '';
		if ( '' === $slug ) {
			// The two "Email to Square Eye > event updated" style extras have no
			// module trigger of their own; report rather than silently drop.
			law_migration_log( 'notifications', 'warning', $ref, 'No module slot for this notification; review manually on the Emails admin screen.' );
			continue;
		}

		$subject  = law_migration_translate_tags( (string) ( $notification['subject'] ?? '' ) );
		$body     = law_migration_translate_tags( (string) ( $notification['message'] ?? '' ) );
		$unmapped = array();
		if ( preg_match_all( '/\{[^}]+:[0-9.]+[^}]*\}/', $subject . ' ' . $body, $m ) ) {
			$unmapped = array_unique( $m[0] );
		}

		if ( $dry ) {
			law_migration_log( 'notifications', 'dry-run', $ref, sprintf(
				'Would import into %s (active=%s)%s.',
				$slug,
				! empty( $notification['isActive'] ) ? 'yes' : 'no',
				$unmapped ? '; UNMAPPED TAGS: ' . implode( ' ', $unmapped ) : ''
			) );
			continue;
		}

		// Gravity Flow sent step-claimed notifications REGARDLESS of their
		// form-level active flag (marking them inactive was a readability
		// convention, EVENTS.md §4). In the new module the active flag is
		// functional, so step-fired emails import as active.
		$step_fired = array(
			'user_payment_due',
			'user_confirmed_free',
			'user_confirmed_paid',
			'committee_approved',
			'committee_event_updated',
		);
		$override = array(
			'subject' => sanitize_text_field( $subject ),
			'body'    => sanitize_textarea_field( wp_strip_all_tags( $body ) ),
			'active'  => in_array( $slug, $step_fired, true ) ? true : ! empty( $notification['isActive'] ),
		);
		$to = (string) ( $notification['to'] ?? '' );
		if ( is_array( law_events_email_registry()[ $slug ]['to'] ) && str_contains( $to, '@' ) ) {
			$override['to'] = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $to ) ) ), 'is_email' );
		}
		$overrides[ $slug ] = $override;
		$migrated++;
		law_migration_log( 'notifications', 'created', $ref, sprintf(
			'Imported into %s with original active state (%s)%s.',
			$slug,
			$override['active'] ? 'active' : 'inactive',
			$unmapped ? '; UNMAPPED TAGS left in place for manual review: ' . implode( ' ', $unmapped ) : ''
		) );
	}

	// Form 1 (User registration) notifications: phase D's custom registration
	// form takes over their sending, so their texts and active states import
	// the same way as form 2's.
	$form1_map = array(
		'Email to admins > user registration'     => 'admins_user_registered',
		'Email to Square Eye > user registration' => 'squareeye_user_registered',
	);
	// Form 1's fields are user fields, so its merge tags translate to the
	// user placeholders, and {all_fields} becomes a user summary.
	$form1_tags = function ( $text ) {
		return strtr(
			(string) $text,
			array(
				'{all_fields}'         => "Name: {user_name}\nEmail: {user_email}\nRoles: {user_roles}",
				'{Name (First):1.3}'   => '{user_name}',
				'{Name (Last):1.6}'    => '',
				'{Name:1}'             => '{user_name}',
				'{Email:4}'            => '{user_email}',
				'{embed_url}'          => '{site_name}',
				'{entry_url}'          => '',
				'{site_title}'         => '{site_name}',
			)
		);
	};
	$form1 = GFAPI::get_form( 1 );
	foreach ( (array) ( $form1['notifications'] ?? array() ) as $notification ) {
		$name = (string) ( $notification['name'] ?? '' );
		$slug = $form1_map[ $name ] ?? '';
		if ( '' === $slug ) {
			continue;
		}
		$ref = 'form 1 notification "' . $name . '"';
		if ( $dry ) {
			law_migration_log( 'notifications', 'dry-run', $ref, 'Would import into ' . $slug . '.' );
			continue;
		}
		$override = array(
			'subject' => sanitize_text_field( $form1_tags( (string) ( $notification['subject'] ?? '' ) ) ),
			'body'    => sanitize_textarea_field( wp_strip_all_tags( $form1_tags( (string) ( $notification['message'] ?? '' ) ) ) ),
			'active'  => ! empty( $notification['isActive'] ),
		);
		$to = (string) ( $notification['to'] ?? '' );
		if ( str_contains( $to, '@' ) ) {
			$override['to'] = array_filter( array_map( 'sanitize_email', array_map( 'trim', explode( ',', $to ) ) ), 'is_email' );
		}
		$overrides[ $slug ] = $override;
		$migrated++;
		law_migration_log( 'notifications', 'created', $ref, 'Imported into ' . $slug . ' (' . ( $override['active'] ? 'active' : 'inactive' ) . ').' );
	}

	// Inline Gravity Flow step notifications (steps 5, 8, 14).
	global $wpdb;
	$inline_map = array(
		'5'  => array( 'key' => 'rejection_notification', 'slug' => 'user_rejected', 'enabled_key' => 'rejection_notification_enabled' ),
		'8'  => array( 'key' => 'assignee_notification', 'slug' => 'user_sent_back', 'enabled_key' => 'assignee_notification_enabled' ),
		// Step 8 (Clarification needed)'s COMPLETION notification: the email
		// back to the committee when the host replies.
		'8c' => array( 'step' => 8, 'key' => 'complete_notification', 'slug' => 'committee_resubmitted', 'enabled_key' => 'complete_notification_enabled' ),
		'14' => array( 'key' => 'workflow_notification', 'slug' => 'committee_payment_received', 'enabled_key' => 'workflow_notification_enabled' ),
	);
	foreach ( $inline_map as $step_key => $config ) {
		$step_id = (int) ( $config['step'] ?? $step_key );
		$meta = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta FROM {$wpdb->prefix}gf_addon_feed WHERE id = %d", $step_id ) ), true );
		if ( ! is_array( $meta ) ) {
			continue;
		}
		$ref     = sprintf( 'step %d (%s) inline notification', $step_id, $meta['step_name'] ?? '?' );
		$subject = law_migration_translate_tags( (string) ( $meta[ $config['key'] . '_subject' ] ?? '' ) );
		$body    = law_migration_translate_tags( (string) ( $meta[ $config['key'] . '_message' ] ?? '' ) );
		if ( '' === trim( $body ) ) {
			law_migration_log( 'notifications', 'skipped', $ref, 'No inline message stored.' );
			continue;
		}
		if ( $dry ) {
			law_migration_log( 'notifications', 'dry-run', $ref, 'Would import into ' . $config['slug'] . '.' );
			continue;
		}
		$overrides[ $config['slug'] ] = array(
			'subject' => sanitize_text_field( $subject ?: law_events_email_registry()[ $config['slug'] ]['subject'] ),
			'body'    => sanitize_textarea_field( wp_strip_all_tags( $body ) ),
			'active'  => ! empty( $meta[ $config['enabled_key'] ] ),
		);
		$migrated++;
		law_migration_log( 'notifications', 'created', $ref, 'Imported into ' . $config['slug'] . '.' );
	}

	if ( ! $dry ) {
		update_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, $overrides, false );
	}

	return array( 'done' => true, 'summary' => $migrated . ' notifications imported.' );
}

/* Step 10: account page templates and the flagship event ______________________ */

/**
 * The pages the rebuild re-templated (or created) locally, keyed by path.
 * Every module code path resolves these BY PATH (get_page_by_path), never by
 * ID, so creating a missing page on another environment is safe. Ordered
 * shallow-first so a created parent exists before its children.
 *
 * @return array<string,array{title:string,template:string}>
 */
function law_migration_page_map() {
	return array(
		'account'                    => array( 'title' => 'Account', 'template' => 'templates/account.php' ),
		'register'                   => array( 'title' => 'Register for an Account', 'template' => 'templates/register.php' ),
		'account/bookings'           => array( 'title' => 'My bookings', 'template' => 'templates/account-bookings.php' ),
		'account/dashboard'          => array( 'title' => 'Events dashboard', 'template' => 'templates/account-dashboard.php' ),
		'account/dashboard/bookings' => array( 'title' => 'Bookings dashboard', 'template' => 'templates/account-bookings-dashboard.php' ),
		'account/dashboard/speakers' => array( 'title' => 'Speakers dashboard', 'template' => 'templates/account-speakers-dashboard.php' ),
		'account/dashboard/flagship' => array( 'title' => 'Flagship dashboard', 'template' => 'templates/account-dashboard-flagship.php' ),
		'account/events'             => array( 'title' => 'My events', 'template' => 'templates/account-events.php' ),
		'account/profile'            => array( 'title' => 'Profile', 'template' => 'templates/account-profile.php' ),
		'account/events/submit'      => array( 'title' => 'Submit an event', 'template' => 'templates/account-event-form.php' ),
		'account/events/submit/done' => array( 'title' => 'Event submitted', 'template' => 'templates/account.php' ),
	);
}

/**
 * Reconcile the account pages with the templates the module expects: assign
 * the template where a page exists with the wrong one, create the page where
 * it is missing. Existing page content is never touched (the templates render
 * their own markup and ignore it). Idempotent: a correct page is reported and
 * skipped.
 *
 * The flagship conference is provisioned at the end of the same step. It is
 * not in the page map because it is not a page: it is one law_event post whose
 * slug gives it /events/flagship/ (functions/events/flagship.php).
 */
function law_migration_run_pages( $dry ) {
	$updated = 0;
	$created = 0;

	foreach ( law_migration_page_map() as $path => $config ) {
		$ref  = '/' . $path . '/';
		$page = get_page_by_path( $path );

		if ( $page ) {
			$current = (string) get_post_meta( $page->ID, '_wp_page_template', true );
			if ( $current === $config['template'] ) {
				law_migration_log( 'pages', 'skipped', $ref, sprintf( 'Page %d already uses %s.', $page->ID, $config['template'] ) );
				continue;
			}
			if ( $dry ) {
				law_migration_log( 'pages', 'dry-run', $ref, sprintf( 'Would change page %d template from "%s" to %s.', $page->ID, $current ?: 'default', $config['template'] ) );
				continue;
			}
			update_post_meta( $page->ID, '_wp_page_template', $config['template'] );
			$updated++;
			law_migration_log( 'pages', 'created', $ref, sprintf( 'Page %d template changed from "%s" to %s (content untouched).', $page->ID, $current ?: 'default', $config['template'] ) );
			continue;
		}

		// Missing page: create it under its parent path. The map is ordered
		// shallow-first, so a parent created in this run already exists.
		$parent_path = dirname( $path );
		$parent      = '.' === $parent_path ? null : get_page_by_path( $parent_path );
		if ( '.' !== $parent_path && ! $parent ) {
			law_migration_log( 'pages', 'warning', $ref, sprintf( 'Parent page /%s/ is missing; create it first.', $parent_path ) );
			continue;
		}

		if ( $dry ) {
			law_migration_log( 'pages', 'dry-run', $ref, sprintf( 'Would create page "%s" with template %s.', $config['title'], $config['template'] ) );
			continue;
		}

		$page_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $config['title'],
				'post_name'   => basename( $path ),
				'post_parent' => $parent ? $parent->ID : 0,
			),
			true
		);
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			law_migration_log( 'pages', 'error', $ref, 'wp_insert_post failed: ' . ( is_wp_error( $page_id ) ? $page_id->get_error_message() : 'unknown' ) );
			continue;
		}
		update_post_meta( $page_id, '_wp_page_template', $config['template'] );
		$created++;
		law_migration_log( 'pages', 'created', $ref, sprintf( 'Created page %d "%s" with template %s.', $page_id, $config['title'], $config['template'] ) );
	}

	// The /account/ page's audience blocks: its host block named the
	// event_host role outright, so a sponsor-only user matched nothing and got
	// an empty page body. Shared helper with the setup-account-pages trigger.
	if ( ! $dry && function_exists( 'law_setup_account_page_audience' ) ) {
		law_migration_log( 'pages', 'created', '/account/', 'Audience blocks: ' . law_setup_account_page_audience() . '.' );
	}
	// The bookings build: attendees keep access to /account/events/ so the
	// links in already-sent emails still resolve, and account-events.php
	// redirects them on to /account/bookings/. Shared helper with the
	// setup-account-pages trigger, so the two cannot drift.
	if ( ! $dry && function_exists( 'law_setup_account_events_attendee_access' ) ) {
		law_migration_log( 'pages', 'created', '/account/events/', 'Attendee role access: ' . law_setup_account_events_attendee_access() . '.' );
	}
	// The personal My bookings page is a child of /account/, not of the events
	// dashboard, and takes its parent's role rows. A page this step creates
	// carries no Members restriction at all, which the plugin reads as public.
	if ( ! $dry && function_exists( 'law_setup_my_bookings_access' ) ) {
		law_migration_log( 'pages', 'created', '/account/bookings/', 'Members restriction: ' . law_setup_my_bookings_access() . '.' );
	}
	// The Bookings dashboard is a child of the events dashboard and inherits
	// its committee-only Members restriction (a freshly created page has none).
	if ( ! $dry && function_exists( 'law_setup_bookings_dashboard_access' ) ) {
		law_migration_log( 'pages', 'created', '/account/dashboard/bookings/', 'Committee restriction: ' . law_setup_bookings_dashboard_access() . '.' );
	}
	// Manage Speakers, the other child of the events dashboard, the same way.
	if ( ! $dry && function_exists( 'law_setup_speakers_dashboard_access' ) ) {
		law_migration_log( 'pages', 'created', '/account/dashboard/speakers/', 'Committee restriction: ' . law_setup_speakers_dashboard_access() . '.' );
	}
	// And Manage flagship, the fourth committee dashboard.
	if ( ! $dry && function_exists( 'law_setup_flagship_dashboard_access' ) ) {
		law_migration_log( 'pages', 'created', '/account/dashboard/flagship/', 'Committee restriction: ' . law_setup_flagship_dashboard_access() . '.' );
	}

	// The flagship conference. Not a page: it is a law_event post whose slug
	// gives it /events/flagship/, so there is no template to assign, only the
	// record to create. Here as well as in the ?setup-account-pages trigger,
	// because a deployed environment must work without either being run by
	// hand (FLAGSHIP_UI.md §4.9), and idempotent, so a re-run reports "exists".
	$flagship_summary = 'not available';
	if ( function_exists( 'law_flagship_ensure_post' ) ) {
		$flagship = law_flagship_ensure_post( $dry );
		law_migration_log(
			'pages',
			$dry ? 'dry-run' : ( $flagship['created'] ? 'created' : 'skipped' ),
			'/events/flagship/',
			$flagship['message']
		);
		if ( $flagship['created'] ) {
			$created++;
			$flagship_summary = 'created';
		} else {
			$flagship_summary = $dry ? 'checked' : 'already present';
		}
	}

	return array(
		'done'    => true,
		'summary' => sprintf( '%d templates assigned, %d pages created, flagship event %s.', $updated, $created, $flagship_summary ),
	);
}

/* Step dispatcher ____________________________________________________________ */

/**
 * Run one migration step (one AJAX request each; the steps are internally
 * batched over the small local dataset).
 *
 * @param string $step Step key.
 * @param bool   $dry  Dry run.
 * @return array|WP_Error
 */
function law_migration_run_step( $step, $dry ) {
	if ( ! current_user_can( 'manage_options' ) ) {
		return new WP_Error( 'law_forbidden', 'Administrators only.' );
	}
	if ( ! $dry && ! law_migration_snapshot_ok() && 'snapshot' !== $step && 'preflight' !== $step ) {
		return new WP_Error( 'law_no_snapshot', 'A database snapshot from the last hour is required before a real run (or tick the server-backup override).' );
	}
	// A real run also requires a PASSING preflight from the last 24 hours.
	if ( ! $dry && ! in_array( $step, array( 'snapshot', 'preflight' ), true ) ) {
		$preflight = get_option( 'law_migration_preflight' );
		if ( ! is_array( $preflight ) || empty( $preflight['pass'] ) || ( time() - (int) $preflight['at'] ) > DAY_IN_SECONDS ) {
			return new WP_Error( 'law_no_preflight', 'Run the preflight checks (and get a PASS) before a real migration step.' );
		}
	}

	// Refresh the run marker on the first gated step of a real run.
	if ( ! get_option( 'law_migration_run_id' ) ) {
		update_option( 'law_migration_run_id', gmdate( 'YmdHis' ), false );
	}

	law_events_seed_terms();

	switch ( $step ) {
		case 'snapshot':
			return law_migration_create_snapshot();
		case 'preflight':
			return law_migration_preflight();
		case 'co_owners':
			return law_migration_run_co_owners( $dry );
		case 'speakers':
			$result = law_migration_run_speakers( $dry );
			$legacy = law_migration_run_legacy_lists( $dry );
			$result['summary'] .= $legacy ? " {$legacy} created from legacy lists." : '';
			return $result;
		case 'events':
			return law_migration_run_events( $dry );
		case 'sessions':
			return law_migration_run_sessions( $dry );
		case 'speaker_appearances':
			return law_migration_run_speaker_appearances( $dry );
		case 'comments':
			return law_migration_run_comments( $dry );
		case 'history':
			return law_migration_run_history( $dry );
		case 'counters':
			return law_migration_run_counters( $dry );
		case 'redirects':
			return law_migration_run_redirects( $dry );
		case 'notifications':
			return law_migration_run_notifications( $dry );
		case 'pages':
			return law_migration_run_pages( $dry );
	}
	return new WP_Error( 'law_bad_step', 'Unknown migration step.' );
}

/* Verification panel _________________________________________________________ */

/** Post-run count comparison (EVENTS_4.1_REBUILD.md §5.2). */
function law_migration_verification() {
	global $wpdb;
	$map = law_migration_map();

	// Contacts: form 4 children on active parents vs migrated contact rows.
	$contact_rows = 0;
	$event_ids    = get_posts( array( 'post_type' => LAW_EVENT_CPT, 'post_status' => law_event_all_status_keys(), 'fields' => 'ids', 'posts_per_page' => 1000 ) );
	foreach ( $event_ids as $event_id ) {
		$contact_rows += count( law_event_meta( $event_id, '_law_contacts' ) );
	}

	$orphans = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$wpdb->prefix}gf_entry e
		 JOIN {$wpdb->prefix}gf_entry_meta em ON em.entry_id = e.id AND em.meta_key = 'gpnf_entry_parent'
		 LEFT JOIN {$wpdb->prefix}gf_entry p ON p.id = em.meta_value
		 WHERE e.form_id IN (4,5,6,8,9) AND e.status = 'active' AND (p.id IS NULL OR p.status != 'active')"
	);

	return array(
		'form 2 active entries'  => class_exists( 'GFAPI' ) ? (int) GFAPI::count_entries( 2, array( 'status' => 'active' ) ) : 0,
		'law_event posts'        => count( $event_ids ),
		'mapped events'          => count( $map['events'] ),
		'law_speaker posts'      => count( get_posts( array( 'post_type' => LAW_SPEAKER_CPT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 2000 ) ) ),
		'mapped speaker entries (more than posts = dedupe merges)' => count( $map['speakers'] ),
		'law_session posts'      => count( get_posts( array( 'post_type' => LAW_SESSION_CPT, 'post_status' => 'any', 'fields' => 'ids', 'posts_per_page' => 1000 ) ) ),
		'migrated comments'      => (int) get_comments( array( 'type' => LAW_EVENT_COMMENT_TYPE, 'count' => true, 'meta_key' => '_law_gf_entry_id' ) ),
		'form 4 contact children (active parents excluded from count when orphaned)' => class_exists( 'GFAPI' ) ? (int) GFAPI::count_entries( 4, array( 'status' => 'active' ) ) : 0,
		'migrated contact rows'  => $contact_rows,
		'orphaned children (skipped by design)' => $orphans,
		'trashed form 2 entries (kept in the GF archive)' => class_exists( 'GFAPI' ) ? (int) GFAPI::count_entries( 2, array( 'status' => 'trash' ) ) : 0,
	);
}

/**
 * Spot-check pairs for the verification panel: old URL → new URL, side by
 * side (§5.2).
 *
 * @return array<int,array{old:string,new:string,label:string}>
 */
function law_migration_spot_checks() {
	$map    = law_migration_map();
	$checks = array();
	$programme = function_exists( 'law_events_programme_page_id' ) && law_events_programme_page_id()
		? get_permalink( law_events_programme_page_id() )
		: home_url( '/programme/' );

	$events = array_slice( $map['events'], 0, 3, true );
	foreach ( $events as $entry_id => $post_id ) {
		if ( 'publish' !== get_post_status( $post_id ) ) {
			continue;
		}
		$checks[] = array(
			'label' => get_the_title( $post_id ),
			'old'   => add_query_arg( 'event', $entry_id, $programme ),
			'new'   => get_permalink( $post_id ),
		);
	}
	$speakers = array_slice( $map['speakers'], 0, 2, true );
	foreach ( $speakers as $entry_id => $post_id ) {
		$checks[] = array(
			'label' => get_the_title( $post_id ),
			'old'   => home_url( '/speakers/' . $entry_id . '/' ),
			'new'   => get_permalink( $post_id ),
		);
	}
	return $checks;
}
