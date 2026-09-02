<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One-off: copy Form 2 List field 48 (Speakers) into the Speakers Nested
 * Form field (110 on local, 112 on live) and its child form (Events > speaker).
 *
 * Does not delete or change the List field values. Safe to dry-run first.
 *
 * WP-CLI (preferred):
 *     wp law migrate-speakers
 *     wp law migrate-speakers --parent=190
 *     wp law migrate-speakers --commit
 *
 * wp-admin: LAW → Migrate speakers.
 */

class LAW_Speaker_List_Migrator {

	const PARENT_FORM = 2;
	const LIST_FIELD  = 48;

	const CHILD_FIRST = '1.3';
	const CHILD_LAST  = '1.6';
	const CHILD_ORG   = '3';
	const CHILD_JOB   = '4';
	const CHILD_URL   = '5';

	/**
	 * Honourific suffixes kept on the last name. Prefix/suffix inputs on the
	 * child Name field are hidden, so anything stripped into those would vanish
	 * from the nested-form table.
	 *
	 * @var string[]
	 */
	const NAME_SUFFIXES = array(
		'KC',
		'QC',
		'SC',
		'JP',
		'OBE',
		'CBE',
		'MBE',
		'KBE',
		'CB',
		'CMG',
		'CVO',
		'GCVO',
		'PHD',
		'ESQ',
		'TBC',
	);

	/** @var bool */
	private $commit;

	/** @var int|null */
	private $parent_id;

	/** @var int|null */
	private $limit;

	/** @var int|null */
	private $nested_field_override;

	/** @var int */
	private $nested_field = 0;

	/** @var int */
	private $child_form = 0;

	/** @var callable */
	private $logger;

	/** @var array */
	private $summary;

	public function __construct( $args = array() ) {
		$this->commit                = ! empty( $args['commit'] );
		$this->parent_id             = isset( $args['parent'] ) ? (int) $args['parent'] : null;
		$this->limit                 = isset( $args['limit'] ) ? (int) $args['limit'] : null;
		$this->nested_field_override = isset( $args['nested_field'] ) ? (int) $args['nested_field'] : null;
		$this->logger    = isset( $args['logger'] ) && is_callable( $args['logger'] )
			? $args['logger']
			: function ( $line ) {
				echo $line . "\n";
			};
		$this->summary = array(
			'parents_scanned'  => 0,
			'parents_skipped' => 0,
			'parents_migrated' => 0,
			'rows_seen'        => 0,
			'rows_skipped'     => 0,
			'children_created' => 0,
			'errors'           => 0,
		);
	}

	public function run() {
		if ( ! class_exists( 'GFAPI' ) ) {
			return new WP_Error( 'no_gf', 'Gravity Forms is not active.' );
		}
		if ( ! class_exists( 'GPNF_Entry' ) ) {
			return new WP_Error( 'no_gpnf', 'Gravity Perks Nested Forms is not active.' );
		}

		$resolved = $this->resolve_targets();
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}

		$mode = $this->commit ? 'COMMIT' : 'DRY RUN';
		$this->log( sprintf( '=== Speakers list → nested form (%s) ===', $mode ) );
		$this->log(
			sprintf(
				'Parent form %d, list field %d → nested field %d (child form %d).',
				self::PARENT_FORM,
				self::LIST_FIELD,
				$this->nested_field,
				$this->child_form
			)
		);

		$parents = $this->get_parents();
		if ( is_wp_error( $parents ) ) {
			return $parents;
		}

		foreach ( $parents as $parent ) {
			$this->process_parent( $parent );
			if ( $this->limit && $this->summary['parents_migrated'] >= $this->limit ) {
				$this->log( sprintf( 'Reached --limit=%d, stopping.', $this->limit ) );
				break;
			}
		}

		$this->log( '' );
		$this->log( sprintf( 'Parents scanned:     %d', $this->summary['parents_scanned'] ) );
		$this->log( sprintf( 'Parents skipped:     %d', $this->summary['parents_skipped'] ) );
		$this->log( sprintf( 'Parents migrated:    %d', $this->summary['parents_migrated'] ) );
		$this->log( sprintf( 'List rows seen:      %d', $this->summary['rows_seen'] ) );
		$this->log( sprintf( 'Rows skipped:        %d', $this->summary['rows_skipped'] ) );
		$this->log( sprintf( 'Children created:    %d', $this->summary['children_created'] ) );
		$this->log( sprintf( 'Errors:              %d', $this->summary['errors'] ) );
		if ( ! $this->commit ) {
			$this->log( 'No data was written. Re-run with --commit (or the admin Commit button) to write.' );
		}

		return $this->summary;
	}

	public function get_summary() {
		return $this->summary;
	}

	/**
	 * Find the Speakers Nested Form field on the event form.
	 *
	 * Local is field 110 → form 8. Live is field 112; the child form ID may
	 * also differ. Detect from the field that nests a form titled with
	 * "speaker", or from an explicit field ID.
	 *
	 * @param int|null $override Nested field ID.
	 * @return array{nested_field:int,child_form:int,label:string,child_title:string}|WP_Error
	 */
	public static function detect_targets( $override = null ) {
		$form = GFAPI::get_form( self::PARENT_FORM );
		if ( ! $form ) {
			return new WP_Error( 'no_parent_form', 'Could not load event form ' . self::PARENT_FORM . '.' );
		}

		$candidates = array();
		foreach ( $form['fields'] as $field ) {
			if ( 'form' !== $field->type ) {
				continue;
			}
			$child_id = (int) $field->gpnfForm;
			$child    = $child_id ? GFAPI::get_form( $child_id ) : null;
			$title    = $child ? (string) $child['title'] : '';
			$label    = (string) $field->label;
			$is_speaker = ( false !== stripos( $title, 'speaker' ) )
				|| ( false !== stripos( $label, 'speaker' ) && false === stripos( $label, 'list' ) );
			$candidates[] = array(
				'nested_field' => (int) $field->id,
				'child_form'  => $child_id,
				'label'        => $label,
				'child_title'  => $title,
				'is_speaker'   => $is_speaker,
			);
		}

		if ( $override ) {
			foreach ( $candidates as $candidate ) {
				if ( $candidate['nested_field'] === (int) $override ) {
					return $candidate;
				}
			}
			return new WP_Error(
				'bad_nested',
				sprintf( 'Form %d has no Nested Form field %d.', self::PARENT_FORM, $override )
			);
		}

		$speakers = array_values(
			array_filter(
				$candidates,
				function ( $candidate ) {
					return $candidate['is_speaker'];
				}
			)
		);

		if ( 1 === count( $speakers ) ) {
			return $speakers[0];
		}

		if ( count( $speakers ) > 1 ) {
			$ids = array_map(
				function ( $candidate ) {
					return $candidate['nested_field'];
				},
				$speakers
			);
			return new WP_Error(
				'ambiguous_nested',
				'Several Speakers nested fields found (' . implode( ', ', $ids ) . '). Pass --nested-field.'
			);
		}

		return new WP_Error(
			'no_nested',
			'Could not find a Speakers Nested Form field on form ' . self::PARENT_FORM . '.'
		);
	}

	private function resolve_targets() {
		$detected = self::detect_targets( $this->nested_field_override );
		if ( is_wp_error( $detected ) ) {
			return $detected;
		}
		$this->nested_field = (int) $detected['nested_field'];
		$this->child_form  = (int) $detected['child_form'];
		return true;
	}

	private function get_parents() {
		if ( $this->parent_id ) {
			$entry = GFAPI::get_entry( $this->parent_id );
			if ( is_wp_error( $entry ) ) {
				return $entry;
			}
			if ( (int) rgar( $entry, 'form_id' ) !== self::PARENT_FORM ) {
				return new WP_Error( 'wrong_form', 'That entry is not on the event submission form.' );
			}
			return array( $entry );
		}

		$collected = array();
		$offset    = 0;
		$page_size = 200;
		$search    = array( 'status' => 'active' );

		do {
			$paging  = array(
				'offset'    => $offset,
				'page_size' => $page_size,
			);
			$entries = GFAPI::get_entries( self::PARENT_FORM, $search, null, $paging, $total );
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			$collected = array_merge( $collected, $entries );
			$offset  += $page_size;
		} while ( $offset < (int) $total );

		return $collected;
	}

	private function process_parent( $parent ) {
		$parent_id = (int) $parent['id'];
		$this->summary['parents_scanned']++;

		$existing_nested = trim( (string) rgar( $parent, (string) $this->nested_field ) );
		if ( $existing_nested !== '' ) {
			$this->summary['parents_skipped']++;
			$this->log(
				sprintf(
					'Parent #%d: skipped, nested field %d already has %s.',
					$parent_id,
					$this->nested_field,
					$existing_nested
				)
			);
			return;
		}

		$rows = $this->parse_list_rows( rgar( $parent, (string) self::LIST_FIELD ) );
		if ( empty( $rows ) ) {
			$this->summary['parents_skipped']++;
			$this->log( sprintf( 'Parent #%d: skipped, no list rows on field %d.', $parent_id, self::LIST_FIELD ) );
			return;
		}

		$to_create = array();
		foreach ( $rows as $index => $row ) {
			$this->summary['rows_seen']++;
			$mapped = $this->map_row( $row );
			if ( empty( $mapped['first'] ) && empty( $mapped['last'] ) ) {
				$this->summary['rows_skipped']++;
				$this->log( sprintf( 'Parent #%d row %d: skipped, empty name.', $parent_id, $index + 1 ) );
				continue;
			}
			$to_create[] = $mapped;
		}

		if ( empty( $to_create ) ) {
			$this->summary['parents_skipped']++;
			$this->log( sprintf( 'Parent #%d: skipped, no usable speaker names.', $parent_id ) );
			return;
		}

		$this->log( sprintf( 'Parent #%d: %d speaker(s).', $parent_id, count( $to_create ) ) );

		$child_ids = array();
		foreach ( $to_create as $mapped ) {
			$this->log(
				sprintf(
					'  %s %s | %s | %s | %s',
					$mapped['first'],
					$mapped['last'],
					$mapped['org'],
					$mapped['job'],
					$mapped['url']
				)
			);

			if ( ! $this->commit ) {
				$this->summary['children_created']++;
				continue;
			}

			$child_id = $this->create_child( $parent, $mapped );
			if ( is_wp_error( $child_id ) ) {
				$this->summary['errors']++;
				$this->log( '  ERROR creating child: ' . $child_id->get_error_message() );
				foreach ( $child_ids as $created_id ) {
					GFAPI::delete_entry( $created_id );
				}
				$this->log( sprintf( '  Rolled back %d child entries for parent #%d.', count( $child_ids ), $parent_id ) );
				return;
			}

			$child_ids[] = $child_id;
			$this->summary['children_created']++;
			$this->log( sprintf( '  Created child #%d.', $child_id ) );
		}

		if ( $this->commit ) {
			$joined = implode( ',', $child_ids );
			$result = GFAPI::update_entry_field( $parent_id, $this->nested_field, $joined );
			if ( is_wp_error( $result ) ) {
				$this->summary['errors']++;
				$this->log( '  ERROR attaching children to parent: ' . $result->get_error_message() );
				return;
			}
			$this->log( sprintf( '  Attached to field %d: %s.', $this->nested_field, $joined ) );
		}

		$this->summary['parents_migrated']++;
	}

	private function parse_list_rows( $raw ) {
		if ( $raw === '' || $raw === null ) {
			return array();
		}

		$value = maybe_unserialize( $raw );
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			}
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$rows = array();
		foreach ( $value as $row ) {
			if ( is_array( $row ) ) {
				$rows[] = $row;
			}
		}
		return $rows;
	}

	private function map_row( $row ) {
		if ( array_is_list( $row ) ) {
			$name = trim( (string) ( $row[0] ?? '' ) );
			$org  = trim( (string) ( $row[1] ?? '' ) );
			$job  = trim( (string) ( $row[2] ?? '' ) );
			$url  = trim( (string) ( $row[3] ?? '' ) );
		} else {
			$name = trim( (string) ( $row['Name'] ?? '' ) );
			$org  = trim( (string) ( $row['Organisation'] ?? '' ) );
			$job  = trim( (string) ( $row['Job title'] ?? '' ) );
			$url  = trim( (string) ( $row['URL'] ?? '' ) );
		}

		$parts = $this->split_name( $name );

		return array(
			'first' => $parts['first'],
			'last'  => $parts['last'],
			'org'   => $org,
			'job'   => $job,
			'url'   => $this->normalise_url( $url ),
		);
	}

	/**
	 * Split a single "Name" column into first / last for the child Name field.
	 *
	 * Hidden prefix/suffix inputs are not used: titles such as Professor stay
	 * in first name, and KC/QC stay on last name, so they still display.
	 *
	 * @return array{first:string,last:string}
	 */
	public static function split_name( $full ) {
		$full = trim( preg_replace( '/\s+/', ' ', (string) $full ) );
		if ( $full === '' ) {
			return array(
				'first' => '',
				'last'  => '',
			);
		}

		$parts   = explode( ' ', $full );
		$suffixes = array();

		while ( $parts ) {
			$last_token = rtrim( end( $parts ), '.,' );
			if ( in_array( strtoupper( $last_token ), self::NAME_SUFFIXES, true ) ) {
				array_unshift( $suffixes, array_pop( $parts ) );
			} else {
				break;
			}
		}

		if ( empty( $parts ) ) {
			return array(
				'first' => $full,
				'last'  => '',
			);
		}

		if ( count( $parts ) === 1 ) {
			$first = $parts[0];
			$last  = implode( ' ', $suffixes );
			return array(
				'first' => $first,
				'last'  => $last,
			);
		}

		$last = rtrim( array_pop( $parts ), '.,' );
		if ( $suffixes ) {
			$last .= ' ' . implode( ' ', $suffixes );
		}

		return array(
			'first' => implode( ' ', $parts ),
			'last'  => $last,
		);
	}

	private function normalise_url( $url ) {
		$url = trim( $url );
		if ( $url === '' ) {
			return '';
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			// Placeholders such as [Industry] or TBC are not URLs.
			if ( ! preg_match( '/\./', $url ) ) {
				return '';
			}
			$url = 'https://' . $url;
		}
		return esc_url_raw( $url );
	}

	private function create_child( $parent, $mapped ) {
		$child = array(
			'form_id'                               => $this->child_form,
			'created_by'                            => rgar( $parent, 'created_by' ),
			'date_created'                           => rgar( $parent, 'date_created' ),
			'ip'                                    => rgar( $parent, 'ip' ),
			'source_url'                            => rgar( $parent, 'source_url' ),
			'status'                                => 'active',
			self::CHILD_FIRST                       => $mapped['first'],
			self::CHILD_LAST                        => $mapped['last'],
			self::CHILD_ORG                         => $mapped['org'],
			self::CHILD_JOB                         => $mapped['job'],
			self::CHILD_URL                         => $mapped['url'],
			GPNF_Entry::ENTRY_PARENT_KEY            => $parent['id'],
			GPNF_Entry::ENTRY_PARENT_FORM_KEY       => self::PARENT_FORM,
			GPNF_Entry::ENTRY_NESTED_FORM_FIELD_KEY => $this->nested_field,
		);

		$child_id = GFAPI::add_entry( $child );
		if ( is_wp_error( $child_id ) ) {
			return $child_id;
		}

		gform_update_meta( $child_id, 'law_migrated_from_list_48', $parent['id'] );

		return (int) $child_id;
	}

	private function log( $line ) {
		call_user_func( $this->logger, $line );
	}
}


/* WP-CLI __________________________________________________________________ */

if ( defined( 'WP_CLI' ) && WP_CLI ) {

	/**
	 * Copy List field 48 speaker rows into the Speakers Nested Form field.
	 *
	 * The nested field is detected (110 locally, 112 on live). Override with
	 * --nested-field if needed.
	 *
	 * ## OPTIONS
	 *
	 * [--commit]
	 * : Write child entries. Default is a dry run.
	 *
	 * [--parent=<id>]
	 * : Limit to one parent entry ID.
	 *
	 * [--nested-field=<id>]
	 * : Nested Form field ID on form 2. Auto-detected if omitted.
	 *
	 * [--limit=<n>]
	 * : Stop after this many parents have been migrated (not skipped).
	 *
	 * ## EXAMPLES
	 *
	 *     wp law migrate-speakers
	 *     wp law migrate-speakers --parent=190 --commit
	 *
	 * @when after_wp_load
	 */
	WP_CLI::add_command(
		'law migrate-speakers',
		function ( $args, $assoc_args ) {
			$migrator = new LAW_Speaker_List_Migrator(
				array(
					'commit'       => (bool) WP_CLI\Utils\get_flag_value( $assoc_args, 'commit', false ),
					'parent'       => isset( $assoc_args['parent'] ) ? (int) $assoc_args['parent'] : null,
					'nested_field' => isset( $assoc_args['nested-field'] ) ? (int) $assoc_args['nested-field'] : null,
					'limit'        => isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : null,
					'logger'       => function ( $line ) {
						WP_CLI::log( $line );
					},
				)
			);

			$result = $migrator->run();
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$summary = $migrator->get_summary();
			if ( $summary['errors'] > 0 ) {
				WP_CLI::warning( 'Finished with errors. Check the log above.' );
			} else {
				WP_CLI::success( 'Done.' );
			}
		}
	);
}


/* wp-admin: LAW → Migrate speakers _________________________________________ */

/**
 * Register as a hidden options.php child so $_registered_pages and the
 * admin_page_{slug} hook exist. Admin Menu Editor rewrites LAW's parent file,
 * so attaching directly to law-settings fails WordPress's access check even
 * when AME itself would allow the item.
 */
function law_register_migrate_speakers_page() {
	add_submenu_page(
		'options.php',
		'Migrate speakers',
		'Migrate speakers',
		'edit_posts',
		'law-migrate-speakers',
		'law_migrate_speakers_admin_page'
	);

	// If AME still uses the raw ACF slug as the parent file, the access check
	// and menu URL rewrite look for law_page_{slug} instead of admin_page_{slug}.
	global $_registered_pages;
	foreach ( array( 'law-settings', 'admin.php?page=law-settings', 'admin.php' ) as $parent ) {
		$hook = get_plugin_page_hookname( 'law-migrate-speakers', $parent );
		if ( $hook && empty( $_registered_pages[ $hook ] ) ) {
			$_registered_pages[ $hook ] = true;
			add_action( $hook, 'law_migrate_speakers_admin_page' );
		}
	}
}
add_action( 'admin_menu', 'law_register_migrate_speakers_page', 20 );

/**
 * After Admin Menu Editor swaps in its snapshot, put a LAW submenu item back
 * whose file is already a working admin.php URL. AME does not run
 * get_plugin_page_hook() on items we inject, so a bare slug becomes
 * /wp-admin/law-migrate-speakers (front-end 404).
 */
add_action( 'admin_menu_editor-menu_replaced', function () {
	global $submenu;

	$slug    = 'law-migrate-speakers';
	$page    = 'admin.php?page=law-migrate-speakers';
	$parents = array( 'law-settings', 'admin.php?page=law-settings' );
	$found   = false;

	foreach ( $parents as $parent ) {
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			continue;
		}
		foreach ( $submenu[ $parent ] as $i => $item ) {
			if ( ! isset( $item[2] ) ) {
				continue;
			}
			if ( $slug === $item[2] || $page === $item[2] ) {
				$submenu[ $parent ][ $i ][2] = $page;
				$found = true;
			}
		}
	}

	if ( $found ) {
		return;
	}

	foreach ( $parents as $parent ) {
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			continue;
		}
		$submenu[ $parent ][] = array(
			'Migrate speakers',
			'edit_posts',
			$page,
			'Migrate speakers',
		);
		return;
	}
} );

/**
 * If the broken relative URL is requested, WordPress never boots as admin
 * (Local/nginx falls through to the front-end theme). Send it to the real page.
 */
add_action( 'template_redirect', function () {
	$path = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( ! is_string( $path ) || ! str_ends_with( untrailingslashit( $path ), '/wp-admin/law-migrate-speakers' ) ) {
		return;
	}
	if ( ! is_user_logged_in() ) {
		auth_redirect();
	}
	wp_safe_redirect( admin_url( 'admin.php?page=law-migrate-speakers' ) );
	exit;
} );

function law_migrate_speakers_admin_page() {
	if ( ! current_user_can( 'edit_posts' ) ) {
		wp_die( esc_html__( 'You do not have permission to run this.', 'law' ) );
	}

	$log     = '';
	$ran     = false;
	$commit  = false;
	$parent  = '';
	$detected = class_exists( 'GFAPI' ) ? LAW_Speaker_List_Migrator::detect_targets() : null;

	if ( isset( $_POST['law_migrate_speakers'] ) ) {
		check_admin_referer( 'law_migrate_speakers' );

		$commit = ( isset( $_POST['law_migrate_mode'] ) && 'commit' === $_POST['law_migrate_mode'] );
		if ( $commit && ( ! isset( $_POST['law_migrate_confirm'] ) || 'MIGRATE' !== $_POST['law_migrate_confirm'] ) ) {
			$log = 'Commit aborted: type MIGRATE in the confirmation box to write data.';
		} else {
			$parent = isset( $_POST['law_migrate_parent'] ) ? sanitize_text_field( wp_unslash( $_POST['law_migrate_parent'] ) ) : '';
			$lines  = array();
			$migrator = new LAW_Speaker_List_Migrator(
				array(
					'commit' => $commit,
					'parent' => $parent !== '' ? (int) $parent : null,
					'logger' => function ( $line ) use ( &$lines ) {
						$lines[] = $line;
					},
				)
			);
			$result = $migrator->run();
			if ( is_wp_error( $result ) ) {
				$lines[] = 'ERROR: ' . $result->get_error_message();
			}
			$log = implode( "\n", $lines );
			$ran = true;
		}
	}

	echo '<div class="wrap">';
	echo '<h1>Migrate speakers</h1>';
	if ( is_wp_error( $detected ) ) {
		echo '<div class="notice notice-error"><p>' . esc_html( $detected->get_error_message() ) . '</p></div>';
	} elseif ( is_array( $detected ) ) {
		echo '<p>Copies existing <strong>List field ' . esc_html( (string) LAW_Speaker_List_Migrator::LIST_FIELD ) . '</strong> rows into nested field <strong>' . esc_html( (string) $detected['nested_field'] ) . '</strong> (' . esc_html( $detected['label'] ) . ' → child form ' . esc_html( (string) $detected['child_form'] ) . ', ' . esc_html( $detected['child_title'] ) . '). The list values are left in place. Entries that already have the nested field populated are skipped.</p>';
	}
	echo '<p>Name is split into first / last on the last word, keeping titles (Professor, Dr.) in first name and suffixes (KC, QC, PhD) on last name. Photo is left empty.</p>';

	echo '<form method="post">';
	wp_nonce_field( 'law_migrate_speakers' );
	echo '<table class="form-table"><tbody>';
	echo '<tr><th scope="row"><label for="law_migrate_parent">Parent entry ID</label></th>';
	echo '<td><input name="law_migrate_parent" id="law_migrate_parent" type="number" class="small-text" value="' . esc_attr( $parent ) . '"> ';
	echo '<p class="description">Leave blank for every active Form 2 entry. Use this to test one event first (e.g. 190).</p></td></tr>';
	echo '<tr><th scope="row">Mode</th><td>';
	echo '<label><input type="radio" name="law_migrate_mode" value="dry" checked> Dry run (no writes)</label><br>';
	echo '<label><input type="radio" name="law_migrate_mode" value="commit"> Commit (write child entries)</label>';
	echo '<p class="description">To commit, also type <code>MIGRATE</code> below.</p>';
	echo '<p><input name="law_migrate_confirm" type="text" class="regular-text" placeholder="MIGRATE" autocomplete="off"></p>';
	echo '</td></tr>';
	echo '</tbody></table>';
	submit_button( 'Run', 'primary', 'law_migrate_speakers' );
	echo '</form>';

	if ( $ran || $log ) {
		echo '<h2>Result</h2>';
		echo '<pre style="background:#fff;border:1px solid #c3c4c7;padding:12px;max-height:32rem;overflow:auto;">' . esc_html( $log ) . '</pre>';
	}

	echo '</div>';
}
