<?php
/**
 * Pull the speaker photo files a copied database references but this server
 * does not have, from the site the database came from.
 *
 * Why this exists (15 September 2026). The production → staging sync copies the
 * database and deliberately NOT wp-content/uploads, because the client's own
 * speaker images live in the media library folders on staging and a sync would
 * destroy them. The cost is that form 8 (Event > speaker) field 6 (Photo) still
 * names files that only exist on the source site, and
 * law_migration_import_photo() reads from DISK rather than over HTTP — so
 * migration step 2 logs "Photo file missing on disk" and every one of those
 * speakers migrates without a photograph. The preflight's "Speaker photo files"
 * check is what reports it.
 *
 * This fetches exactly those files and puts them where the stored URL says they
 * should be. It writes no posts and no meta: the import is still migration step
 * 4b's job (law_migration_run_speaker_appearances()), which calls
 * law_migration_child_photo_id() and, finding no attachment mapped, imports the
 * file it now finds on disk. So the order is: run this, re-run the preflight to
 * confirm nothing is missing, then re-run step 4b.
 *
 * Step 2 is NOT the step to re-run: it skips speakers already in the entry map,
 * so it would never retry their photos.
 *
 * SOURCE. The stored URL is this site's, because wp search-replace rewrote it
 * during the pull, so the file is asked of the source site at the same path
 * below uploads/. The source is typed into the form and checked against
 * wp_http_validate_url() before anything is requested; the fetch goes through
 * download_url() (i.e. wp_safe_remote_get(), which refuses loopback and private
 * addresses), and the bytes are checked with wp_check_filetype_and_ext() and
 * refused unless they really are an image. Nothing is written outside the
 * uploads directory: the destination is derived from the stored URL's path
 * below uploads/, never from anything the source site returns.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** How many files one press may fetch, so a mistyped source cannot run away. */
const LAW_PHOTO_FETCH_MAX = 500;

/**
 * Every referenced speaker photo, with where it should be and whether it is.
 *
 * Reads the same field the preflight's photo check reads, so the two can never
 * disagree about what is missing.
 *
 * @return array[] One row per referenced photo: entry_id, url, rel, present.
 */
function law_events_photo_fetch_scan() {
	if ( ! class_exists( 'GFAPI' ) ) {
		return array();
	}

	$uploads = wp_upload_dir();
	$baseurl = trailingslashit( (string) $uploads['baseurl'] );
	$basedir = trailingslashit( (string) $uploads['basedir'] );

	$rows = array();
	foreach ( law_migration_entries( 8, 0, LAW_MIGRATION_ENTRY_PAGE_SIZE ) as $entry ) {
		$url = law_calendar_speaker_photo_url( rgar( $entry, '6' ) );
		if ( '' === $url ) {
			continue;
		}
		// A URL outside this site's uploads folder has no path here to write to
		// and no path there to ask for, so it is reported rather than guessed at.
		if ( ! str_starts_with( $url, $baseurl ) ) {
			$rows[] = array( 'entry_id' => (int) $entry['id'], 'url' => $url, 'rel' => '', 'present' => false );
			continue;
		}
		$rel      = substr( $url, strlen( $baseurl ) );
		$rows[]   = array(
			'entry_id' => (int) $entry['id'],
			'url'      => $url,
			'rel'      => $rel,
			'present'  => file_exists( $basedir . $rel ),
		);
	}

	return $rows;
}

/**
 * Fetch the missing files from $source.
 *
 * @param string $source The source site's uploads base URL.
 * @param bool   $dry    Report only.
 * @return array{fetched:int,present:int,failed:int,lines:string[]}
 */
function law_events_photo_fetch_apply( $source, $dry = true ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';

	$result = array( 'fetched' => 0, 'present' => 0, 'failed' => 0, 'lines' => array() );

	$source = trailingslashit( trim( (string) $source ) );
	if ( ! wp_http_validate_url( $source ) ) {
		$result['lines'][] = 'That is not a URL this server will fetch from.';
		return $result;
	}

	$uploads = wp_upload_dir();
	$basedir = trailingslashit( (string) $uploads['basedir'] );

	foreach ( law_events_photo_fetch_scan() as $row ) {
		if ( $row['present'] ) {
			$result['present']++;
			continue;
		}
		if ( '' === $row['rel'] ) {
			$result['failed']++;
			$result['lines'][] = sprintf( 'Entry %d: %s is not under this site\'s uploads folder.', $row['entry_id'], $row['url'] );
			continue;
		}
		if ( $result['fetched'] >= LAW_PHOTO_FETCH_MAX ) {
			$result['lines'][] = sprintf( 'Stopped at the limit of %d files in one run.', LAW_PHOTO_FETCH_MAX );
			break;
		}

		$dest = $basedir . $row['rel'];
		if ( $dry ) {
			$result['fetched']++;
			$result['lines'][] = sprintf( 'Entry %d: would fetch %s', $row['entry_id'], $row['rel'] );
			continue;
		}

		if ( ! wp_mkdir_p( dirname( $dest ) ) ) {
			$result['failed']++;
			$result['lines'][] = sprintf( 'Entry %d: cannot create %s', $row['entry_id'], dirname( $row['rel'] ) );
			continue;
		}

		$tmp = download_url( $source . $row['rel'], 60 );
		if ( is_wp_error( $tmp ) ) {
			$result['failed']++;
			$result['lines'][] = sprintf( 'Entry %d: %s — %s', $row['entry_id'], $row['rel'], $tmp->get_error_message() );
			law_migration_log( 'photo_fetch', 'warning', 'form 8 entry ' . $row['entry_id'], 'Could not fetch ' . $row['rel'] . ': ' . $tmp->get_error_message() );
			continue;
		}

		// Trust the bytes, not the name: this came off the network.
		$check = wp_check_filetype_and_ext( $tmp, basename( $row['rel'] ) );
		if ( empty( $check['type'] ) || 0 !== strpos( (string) $check['type'], 'image/' ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$result['failed']++;
			$result['lines'][] = sprintf( 'Entry %d: %s is not an image.', $row['entry_id'], $row['rel'] );
			law_migration_log( 'photo_fetch', 'warning', 'form 8 entry ' . $row['entry_id'], $row['rel'] . ' is not an image; not written.' );
			continue;
		}

		if ( ! @rename( $tmp, $dest ) && ! @copy( $tmp, $dest ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
			$result['failed']++;
			$result['lines'][] = sprintf( 'Entry %d: could not write %s', $row['entry_id'], $row['rel'] );
			continue;
		}
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors

		$result['fetched']++;
		$result['lines'][] = sprintf( 'Entry %d: %s (%s)', $row['entry_id'], $row['rel'], size_format( (int) filesize( $dest ) ) );
		law_migration_log( 'photo_fetch', 'created', 'form 8 entry ' . $row['entry_id'], 'Fetched ' . $row['rel'] . ' from ' . $source );
	}

	return $result;
}

/** The panel, rendered on the Migration screen beside the other repairs. */
function law_events_photo_fetch_panel() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$notice = '';
	$source = (string) get_option( 'law_photo_fetch_source', '' );

	if ( isset( $_POST['law_photo_fetch_nonce'] ) ) {
		check_admin_referer( 'law_photo_fetch', 'law_photo_fetch_nonce' );
		$source = esc_url_raw( wp_unslash( (string) ( $_POST['law_photo_fetch_source'] ?? '' ) ) );
		update_option( 'law_photo_fetch_source', $source, false );

		$dry    = ! isset( $_POST['law_photo_fetch_apply'] );
		$result = law_events_photo_fetch_apply( $source, $dry );

		$notice = sprintf(
			'<div class="notice notice-%s"><p><strong>%s</strong> %d already present, %d failed.</p>%s</div>',
			$result['failed'] ? 'warning' : 'success',
			$dry
				? sprintf( '%d file(s) would be fetched.', $result['fetched'] )
				: sprintf( '%d file(s) fetched.', $result['fetched'] ),
			$result['present'],
			$result['failed'],
			$result['lines']
				? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', array_slice( $result['lines'], 0, 60 ) ) ) . '</li></ul>'
				: ''
		);
	}

	$rows    = law_events_photo_fetch_scan();
	$missing = count( array_filter( $rows, fn( $r ) => ! $r['present'] ) );

	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Repair: speaker photos missing on disk</h2>
	<p class="description" style="max-width:900px">
		For a site whose database was copied from another environment without <code>wp-content/uploads</code>.
		Form 8 (Event &gt; speaker) field 6 (Photo) still names files that only exist on the source site, and the
		migration reads photos from disk rather than over HTTP, so those speakers migrate without a photograph.
		This fetches exactly the missing files and puts them where the stored URL says they belong.
		<strong>It writes no posts and no meta</strong> — afterwards, re-run the preflight to confirm nothing is
		missing, then re-run <strong>step 4b (speaker appearance details)</strong>, which imports the files it now
		finds. Step 2 will not do it: it skips speakers already migrated.
	</p>

	<?php if ( ! $rows ) : ?>
		<p><strong>No speaker photos are referenced at all</strong>, so there is nothing to fetch.</p>
	<?php elseif ( ! $missing ) : ?>
		<p><strong>Nothing missing.</strong> All <?php echo esc_html( (string) count( $rows ) ); ?> referenced speaker photos are on disk.</p>
	<?php else : ?>
		<p><strong><?php echo esc_html( (string) $missing ); ?></strong> of <?php echo esc_html( (string) count( $rows ) ); ?> referenced photos are missing on disk.</p>
		<form method="post">
			<?php wp_nonce_field( 'law_photo_fetch', 'law_photo_fetch_nonce' ); ?>
			<p>
				<label for="law_photo_fetch_source"><strong>Source uploads URL</strong></label><br>
				<input type="url" class="regular-text" id="law_photo_fetch_source" name="law_photo_fetch_source"
					value="<?php echo esc_attr( $source ); ?>"
					placeholder="https://example.com/wp-content/uploads" required style="width:32em">
				<br><span class="description">The uploads folder of the site this database came from. Each missing file is asked for at the same path below it.</span>
			</p>
			<p>
				<button type="submit" class="button button-secondary">Dry run</button>
				<button type="submit" class="button button-primary" name="law_photo_fetch_apply" value="1">Fetch the missing files</button>
			</p>
		</form>
	<?php endif; ?>
	<?php
}
