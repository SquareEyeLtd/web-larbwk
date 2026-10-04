<?php
/**
 * LAW > HubSpot: the module's status and controls (_docs/HUBSPOT_SYNC.md §6.5).
 *
 * What it answers: which mode the site is in and why, whether the token
 * works, whether the portal's properties match what the rules will write, how
 * long the queue is, which addresses are parked and what went wrong with them,
 * and when the worker last ran. Buttons run the property check and creation,
 * the worker, the parked retry and "Queue everyone" (the backfill, §8). The
 * backfill preview downloads as CSV, and a person lookup shows what the rules
 * would write for one address and what HubSpot holds.
 *
 * Committee-level and administrators only (edit_others_law_events, the same
 * bar as the committee screens).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_HUBSPOT_ADMIN_SLUG = 'law-hubspot';
const LAW_HUBSPOT_ADMIN_CAP  = 'edit_others_law_events';

add_action(
	'admin_menu',
	function () {
		law_events_register_law_subpage( LAW_HUBSPOT_ADMIN_SLUG, 'HubSpot', 'law_hubspot_admin_page', LAW_HUBSPOT_ADMIN_CAP );
	},
	999
);

function law_hubspot_admin_url() {
	return admin_url( 'admin.php?page=' . LAW_HUBSPOT_ADMIN_SLUG );
}

/**
 * Handle a button press. Each action has its own nonce; the result is stored
 * for one render in a transient keyed to the user, and the page redirects so
 * a refresh cannot repeat the action.
 */
function law_hubspot_admin_handle_action() {
	if ( empty( $_POST['law_hubspot_action'] ) || LAW_HUBSPOT_ADMIN_SLUG !== ( $_GET['page'] ?? '' ) ) {
		return;
	}
	if ( ! current_user_can( LAW_HUBSPOT_ADMIN_CAP ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	$action = sanitize_key( wp_unslash( $_POST['law_hubspot_action'] ) );
	check_admin_referer( 'law_hubspot_' . $action, 'law_hubspot_nonce' );

	$notice = array( 'type' => 'info', 'text' => '', 'actions' => array() );

	switch ( $action ) {
		case 'check_properties':
			$report = law_hubspot_properties_check();
			set_transient( 'law_hubspot_property_report', $report, HOUR_IN_SECONDS );
			$notice['type'] = '' !== $report['error'] ? 'error' : ( $report['ok'] ? 'success' : 'warning' );
			$notice['text'] = '' !== $report['error']
				? 'Property check failed: ' . $report['error']
				: ( $report['ok'] ? 'Property check passed: everything the rules write exists in the portal.' : 'Property check finished with differences; see below.' );
			break;

		case 'create_properties':
			$actions = law_hubspot_properties_create();
			$report  = law_hubspot_properties_check();
			set_transient( 'law_hubspot_property_report', $report, HOUR_IN_SECONDS );
			$notice['actions'] = $actions;
			$notice['type']    = law_hubspot_admin_actions_type( $actions );
			$notice['text']    = 'dry' === law_hubspot_mode() ? 'Dry run: these are the changes a live run would make.' : 'Site-owned properties updated.';
			break;

		case 'add_contact_type_options':
			$actions = law_hubspot_contact_type_add_options();
			$report  = law_hubspot_properties_check();
			set_transient( 'law_hubspot_property_report', $report, HOUR_IN_SECONDS );
			$notice['actions'] = $actions;
			$notice['type']    = law_hubspot_admin_actions_type( $actions );
			$notice['text']    = 'Contact type options:';
			break;

		case 'process_queue':
			$summary = law_hubspot_process_queue();
			if ( is_wp_error( $summary ) ) {
				$notice['type'] = 'error';
				$notice['text'] = $summary->get_error_message();
			} else {
				$notice['type'] = $summary['failed'] ? 'warning' : 'success';
				$notice['text'] = sprintf(
					'Worker run (%s): %d processed, %d succeeded, %d failed, %d parked.',
					$summary['mode'],
					$summary['processed'],
					$summary['succeeded'],
					$summary['failed'],
					$summary['parked']
				);
			}
			break;

		case 'retry_parked':
			$count          = law_hubspot_queue_retry_parked();
			$notice['type'] = 'success';
			$notice['text'] = sprintf( '%d parked %s put back in the queue.', $count, 1 === $count ? 'address' : 'addresses' );
			break;

		case 'queue_everyone':
			$count = law_hubspot_queue_everyone();
			if ( is_wp_error( $count ) ) {
				$notice['type'] = 'error';
				$notice['text'] = $count->get_error_message();
			} else {
				$notice['type'] = 'success';
				$notice['text'] = sprintf( '%d %s queued. The worker runs every five minutes, or press "Process queue now".', $count, 1 === $count ? 'address' : 'addresses' );
			}
			break;

		case 'queue_one':
			$email = law_hubspot_normalise_email( wp_unslash( $_POST['law_hubspot_email'] ?? '' ) );
			if ( '' === $email || ! is_email( $email ) ) {
				$notice['type'] = 'error';
				$notice['text'] = 'That is not an email address.';
			} elseif ( ! law_hubspot_enabled() ) {
				$notice['type'] = 'error';
				$notice['text'] = 'The HubSpot mode is off.';
			} else {
				law_hubspot_enqueue( $email, 'manual' );
				$notice['type'] = 'success';
				$notice['text'] = $email . ' queued. The worker runs every five minutes, or press "Process queue now".';
			}
			break;

		case 'clear_token_check':
			delete_transient( 'law_hubspot_token_check' );
			$notice['type'] = 'info';
			$notice['text'] = 'Token re-checked.';
			break;

		default:
			return;
	}

	set_transient( 'law_hubspot_admin_notice_' . get_current_user_id(), $notice, MINUTE_IN_SECONDS );
	wp_safe_redirect( law_hubspot_admin_url() );
	exit;
}
add_action( 'admin_init', 'law_hubspot_admin_handle_action' );

/**
 * The backfill preview as a CSV download (HUBSPOT_SYNC.md §8 step 3). Reads
 * only, in any mode but off. Goes through admin-post so the response can be
 * the file itself.
 */
function law_hubspot_admin_preview_download() {
	if ( ! current_user_can( LAW_HUBSPOT_ADMIN_CAP ) ) {
		wp_die( 'Sorry, you are not allowed to do that.' );
	}
	check_admin_referer( 'law_hubspot_preview', 'law_hubspot_nonce' );
	if ( ! law_hubspot_enabled() ) {
		wp_die( 'The HubSpot mode is off.' );
	}

	// The whole list can take a few minutes against the API.
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 0 );
	}

	$preview = law_hubspot_preview();
	if ( $preview['error'] ) {
		wp_die( esc_html( 'Preview failed: ' . $preview['error']->get_error_message() ) );
	}

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="hubspot-backfill-preview-' . gmdate( 'Ymd-His' ) . '.csv"' );
	$out = fopen( 'php://output', 'w' );
	fwrite( $out, "\xEF\xBB\xBF" ); // BOM so Excel reads the UTF-8 names
	law_hubspot_preview_to_csv( $out, $preview['rows'] );
	fclose( $out );
	exit;
}
add_action( 'admin_post_law_hubspot_preview', 'law_hubspot_admin_preview_download' );

/** error if any action errored, warning if any was dry, else success. */
function law_hubspot_admin_actions_type( array $actions ) {
	$results = array_column( $actions, 'result' );
	if ( in_array( 'error', $results, true ) ) {
		return 'error';
	}
	if ( in_array( 'dry', $results, true ) ) {
		return 'warning';
	}
	return 'success';
}

/**
 * The token check, cached for five minutes so the screen does not hit the
 * API on every load. Keyed to the token so a changed wp-config.php is seen.
 *
 * @return array{status:string,message:string}
 */
function law_hubspot_admin_token_status() {
	if ( ! law_hubspot_enabled() ) {
		return array( 'status' => 'off', 'message' => 'Not checked while the mode is off.' );
	}
	if ( '' === law_hubspot_token() ) {
		return array( 'status' => 'missing', 'message' => 'LAW_HUBSPOT_TOKEN is not defined in wp-config.php.' );
	}
	$cached = get_transient( 'law_hubspot_token_check' );
	if ( is_array( $cached ) && ( $cached['key'] ?? '' ) === md5( law_hubspot_token() ) ) {
		return $cached['result'];
	}
	$check  = law_hubspot_token_check();
	$result = is_wp_error( $check )
		? array( 'status' => 'failed', 'message' => $check->get_error_message() )
		: array( 'status' => 'ok', 'message' => 'Authenticated against portal ' . LAW_HUBSPOT_PORTAL_ID . '.' );
	set_transient( 'law_hubspot_token_check', array( 'key' => md5( law_hubspot_token() ), 'result' => $result ), 5 * MINUTE_IN_SECONDS );
	return $result;
}

/** One submit button in its own form, with the action's nonce. */
function law_hubspot_admin_button( $action, $label, $primary = false, $disabled = false, $confirm = '', array $fields = array() ) {
	?>
	<form method="post" style="display:inline-block;margin-right:6px">
		<input type="hidden" name="law_hubspot_action" value="<?php echo esc_attr( $action ); ?>">
		<?php foreach ( $fields as $name => $value ) : ?>
			<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php endforeach; ?>
		<?php wp_nonce_field( 'law_hubspot_' . $action, 'law_hubspot_nonce' ); ?>
		<button type="submit" class="button <?php echo $primary ? 'button-primary' : 'button-secondary'; ?>"
			<?php disabled( $disabled ); ?>
			<?php if ( '' !== $confirm ) : ?>onclick="return confirm('<?php echo esc_js( $confirm ); ?>');"<?php endif; ?>>
			<?php echo esc_html( $label ); ?>
		</button>
	</form>
	<?php
}

function law_hubspot_admin_yes_no( $value ) {
	return $value ? '<span style="color:#008a20">Yes</span>' : '<span style="color:#d63638">No</span>';
}

function law_hubspot_admin_page() {
	if ( ! current_user_can( LAW_HUBSPOT_ADMIN_CAP ) ) {
		wp_die( 'Sorry, you are not allowed to access this page.' );
	}

	$mode       = law_hubspot_mode();
	$configured = law_hubspot_configured_mode();
	$enabled    = law_hubspot_enabled();
	$token      = law_hubspot_admin_token_status();
	$report     = get_transient( 'law_hubspot_property_report' );
	$stats      = law_hubspot_queue_stats();
	$parked     = law_hubspot_queue_parked( 50 );
	$last_run   = law_hubspot_last_run();
	$next_cron  = wp_next_scheduled( LAW_HUBSPOT_CRON_HOOK );
	$log        = law_hubspot_log_recent( 25 );
	$config     = law_hubspot_config();

	// Person lookup: a GET so the result has a URL and a refresh is harmless.
	$lookup_email = isset( $_GET['law_hubspot_email'] ) ? law_hubspot_normalise_email( wp_unslash( $_GET['law_hubspot_email'] ) ) : '';
	$lookup       = null;
	if ( '' !== $lookup_email && $enabled && wp_verify_nonce( (string) ( $_GET['law_hubspot_lookup_nonce'] ?? '' ), 'law_hubspot_lookup' ) ) {
		$planned = law_hubspot_plan_batch( array( $lookup_email ) );
		$lookup  = $planned['error'] ? $planned['error'] : ( $planned['plans'][ $lookup_email ] ?? array( 'state' => null ) );
	}

	$notice_key = 'law_hubspot_admin_notice_' . get_current_user_id();
	$notice     = get_transient( $notice_key );
	delete_transient( $notice_key );
	?>
	<div class="wrap law-hubspot">
		<h1>HubSpot sync</h1>
		<p>The website keeps HubSpot contacts and their Contact type tags up to date from what it knows: accounts, event hosts and co-owners, bookings and speakers. Nothing here changes a contact LAW staff have edited beyond adding the website's tags.</p>

		<?php if ( is_array( $notice ) && '' !== $notice['text'] ) : ?>
			<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?>">
				<p><?php echo esc_html( $notice['text'] ); ?></p>
				<?php if ( ! empty( $notice['actions'] ) ) : ?>
					<ul style="margin-left:1.5em;list-style:disc">
						<?php foreach ( $notice['actions'] as $row ) : ?>
							<li><code><?php echo esc_html( $row['action'] ); ?></code> <?php echo esc_html( $row['target'] ); ?>:
								<strong><?php echo esc_html( $row['result'] ); ?></strong>
								<?php if ( '' !== $row['message'] ) : ?> – <?php echo esc_html( $row['message'] ); ?><?php endif; ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<h2>Status</h2>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
				<tr>
					<th scope="row" style="width:220px">Mode</th>
					<td>
						<strong><?php echo esc_html( $mode ); ?></strong>
						<?php if ( law_hubspot_mode_is_downgraded() ) : ?>
							<span class="description">– wp-config.php asks for <code>live</code>, downgraded to <code>dry</code> because this is a <?php echo esc_html( wp_get_environment_type() ); ?> environment.</span>
						<?php elseif ( 'off' === $configured ) : ?>
							<span class="description">– set <code>LAW_HUBSPOT_MODE</code> to <code>dry</code> or <code>live</code> in wp-config.php to enable.</span>
						<?php elseif ( 'dry' === $mode ) : ?>
							<span class="description">– reads run; every write is logged below instead of sent.</span>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Environment</th>
					<td><?php echo esc_html( wp_get_environment_type() ); ?></td>
				</tr>
				<tr>
					<th scope="row">Token</th>
					<td>
						<?php
						$colours = array( 'ok' => '#008a20', 'failed' => '#d63638', 'missing' => '#d63638', 'off' => '#646970' );
						?>
						<strong style="color:<?php echo esc_attr( $colours[ $token['status'] ] ?? '#646970' ); ?>"><?php echo esc_html( ucfirst( $token['status'] ) ); ?></strong>
						<span class="description"><?php echo esc_html( $token['message'] ); ?></span>
						<?php if ( $enabled && '' !== law_hubspot_token() ) : ?>
							<?php law_hubspot_admin_button( 'clear_token_check', 'Re-check' ); ?>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Year</th>
					<td><?php echo esc_html( $config['year'] ); ?> <span class="description">(Events settings; prefixes every tag and option)</span></td>
				</tr>
				<tr>
					<th scope="row">Worker</th>
					<td>
						<?php if ( $next_cron ) : ?>
							Next run <?php echo esc_html( human_time_diff( time(), $next_cron ) ); ?> <?php echo $next_cron > time() ? 'from now' : 'ago (overdue: WP-Cron waits for a visit)'; ?>.
						<?php else : ?>
							Not scheduled<?php echo $enabled ? ' (will be on the next request)' : ' while the mode is off'; ?>.
						<?php endif; ?>
						<?php if ( $last_run ) : ?>
							Last run <?php echo esc_html( human_time_diff( $last_run['at'], time() ) ); ?> ago:
							<?php echo esc_html( sprintf( '%d processed, %d succeeded, %d failed, %d parked (%s).', $last_run['processed'], $last_run['succeeded'], $last_run['failed'], $last_run['parked'], $last_run['mode'] ) ); ?>
						<?php else : ?>
							Never run.
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">Queue</th>
					<td><?php echo esc_html( sprintf( '%d due now, %d waiting to retry, %d parked.', $stats['due'], $stats['waiting'], $stats['parked'] ) ); ?></td>
				</tr>
				<tr>
					<th scope="row">What the website writes</th>
					<td>
						Standard properties (name, job title, company, country), Contact type tags
						<code><?php echo implode( '</code> <code>', array_map( 'esc_html', array_merge( array_values( $config['tags'] ), array_values( $config['reception_tags'] ) ) ) ); ?></code>
						(added, never removed) and the legal basis (filled when empty, "other" upgraded to "existing customer").
						The badging properties in the <code><?php echo esc_html( $config['property_group']['name'] ); ?></code> group are created but not yet written (phase 3).
					</td>
				</tr>
			</tbody>
		</table>

		<p style="margin-top:12px">
			<?php law_hubspot_admin_button( 'process_queue', 'Process queue now', false, ! $enabled ); ?>
			<?php law_hubspot_admin_button( 'retry_parked', 'Retry parked', false, ! $stats['parked'] ); ?>
			<?php
			law_hubspot_admin_button(
				'queue_everyone',
				'Queue everyone',
				false,
				! $enabled,
				'live' === $mode ? 'This queues every account and speaker for a live push to HubSpot. Continue?' : ''
			);
			?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:6px">
				<input type="hidden" name="action" value="law_hubspot_preview">
				<?php wp_nonce_field( 'law_hubspot_preview', 'law_hubspot_nonce' ); ?>
				<button type="submit" class="button button-secondary" <?php disabled( ! $enabled ); ?>>Download backfill preview (CSV)</button>
			</form>
			<span class="description">The preview reads every account and speaker against HubSpot and writes nothing; it can take a few minutes.</span>
		</p>

		<h2>Look up a person</h2>
		<p>What the rules would write for one address, and what HubSpot holds now. Reads only.</p>
		<form method="get" style="margin-bottom:12px">
			<input type="hidden" name="page" value="<?php echo esc_attr( LAW_HUBSPOT_ADMIN_SLUG ); ?>">
			<?php wp_nonce_field( 'law_hubspot_lookup', 'law_hubspot_lookup_nonce' ); ?>
			<input type="email" name="law_hubspot_email" value="<?php echo esc_attr( $lookup_email ); ?>" placeholder="name@example.com" class="regular-text" <?php disabled( ! $enabled ); ?>>
			<button type="submit" class="button button-secondary" <?php disabled( ! $enabled ); ?>>Look up</button>
		</form>
		<?php if ( is_array( $lookup ) && null !== $lookup['state'] ) : ?>
			<p><?php law_hubspot_admin_button( 'queue_one', 'Queue ' . $lookup_email . ' for sync', false, false, '', array( 'law_hubspot_email' => $lookup_email ) ); ?></p>
		<?php endif; ?>
		<?php if ( is_wp_error( $lookup ) ) : ?>
			<div class="notice notice-error inline"><p>Lookup failed: <?php echo esc_html( $lookup->get_error_message() ); ?></p></div>
		<?php elseif ( is_array( $lookup ) && null === $lookup['state'] ) : ?>
			<p class="description">The website knows nothing about <code><?php echo esc_html( $lookup_email ); ?></code>: no account and no speaker record.</p>
		<?php elseif ( is_array( $lookup ) ) : ?>
			<?php $sources = $lookup['state']['sources']; ?>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
					<tr><th scope="row" style="width:220px">Known as</th><td>
						<?php if ( $sources['user'] ) : ?>User <a href="<?php echo esc_url( get_edit_user_link( $sources['user'] ) ); ?>">#<?php echo (int) $sources['user']; ?></a>. <?php endif; ?>
						<?php if ( $sources['speaker'] ) : ?>Speaker <a href="<?php echo esc_url( get_edit_post_link( $sources['speaker'] ) ); ?>">#<?php echo (int) $sources['speaker']; ?></a>.<?php endif; ?>
					</td></tr>
					<tr><th scope="row">Evidence</th><td><?php echo esc_html( sprintf( '%d confirmed booking(s), %d hosted event(s), %d co-owned event(s), %d speaking appearance(s)%s.', count( $sources['bookings'] ), count( $sources['hosted'] ), count( $sources['co_owned'] ), count( $sources['speaking'] ), $sources['press'] ? ', press' : '' ) ); ?></td></tr>
					<tr><th scope="row">In HubSpot</th><td><?php echo $lookup['exists'] ? 'Yes, record ' . esc_html( $lookup['hubspot_id'] ) : 'No: the push would create the contact'; ?><?php echo '' !== $lookup['state']['hubspot_id'] ? ' (stored ID ' . esc_html( $lookup['state']['hubspot_id'] ) . ')' : ''; ?></td></tr>
					<tr><th scope="row">Contact type</th><td>
						Now: <code><?php echo esc_html( '' !== $lookup['before']['contact_type'] ? $lookup['before']['contact_type'] : '(empty)' ); ?></code><br>
						After: <code><?php echo esc_html( $lookup['after']['contact_type'] ); ?></code>
						<?php if ( $lookup['added_tags'] ) : ?><br><span class="description">Adds: <?php echo esc_html( implode( ', ', $lookup['added_tags'] ) ); ?></span><?php else : ?><br><span class="description">No tags to add.</span><?php endif; ?>
					</td></tr>
					<tr><th scope="row">Legal basis</th><td>
						Now: <code><?php echo esc_html( '' !== $lookup['before']['legal_basis'] ? $lookup['before']['legal_basis'] : '(empty)' ); ?></code><br>
						After: <code><?php echo esc_html( '' !== $lookup['after']['legal_basis'] ? $lookup['after']['legal_basis'] : '(left empty: nothing to base it on)' ); ?></code>
					</td></tr>
					<tr><th scope="row">Properties to write</th><td><pre style="margin:0;white-space:pre-wrap"><?php echo esc_html( law_hubspot_admin_pretty( wp_json_encode( $lookup['properties'] ) ) ); ?></pre></td></tr>
				</tbody>
			</table>
		<?php endif; ?>

		<h2>Properties</h2>
		<p>Checks that every property and option the rules write exists in the portal, comparing internal values rather than labels. Run it before any live push and after any change in HubSpot.</p>
		<p>
			<?php law_hubspot_admin_button( 'check_properties', 'Check properties', true, ! $enabled ); ?>
			<?php law_hubspot_admin_button( 'create_properties', 'Create missing site-owned properties', false, ! $enabled || ! is_array( $report ) || '' !== $report['error'] ); ?>
			<?php law_hubspot_admin_button( 'add_contact_type_options', 'Add missing Contact type options', false, ! $enabled || ! is_array( $report ) || '' !== $report['error'] || empty( $report['contact_type']['missing_values'] ) ); ?>
		</p>

		<?php if ( is_array( $report ) ) : ?>
			<p class="description">Last checked <?php echo esc_html( human_time_diff( $report['checked_at'], time() ) ); ?> ago in <?php echo esc_html( $report['mode'] ); ?> mode.
				<?php if ( '' !== $report['error'] ) : ?><strong style="color:#d63638">Failed: <?php echo esc_html( $report['error'] ); ?></strong><?php endif; ?></p>

			<?php if ( '' === $report['error'] ) : ?>
				<h3>Site-owned (created by the website)</h3>
				<table class="widefat striped" style="max-width:900px">
					<thead><tr><th>Internal name</th><th>Label</th><th>Type</th><th>Exists</th><th>Missing options</th></tr></thead>
					<tbody>
						<tr>
							<td><code><?php echo esc_html( $report['group']['name'] ); ?></code></td>
							<td>Property group</td>
							<td>group</td>
							<td><?php echo law_hubspot_admin_yes_no( $report['group']['exists'] ); ?></td>
							<td></td>
						</tr>
						<?php foreach ( $report['properties'] as $name => $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( $name ); ?></code></td>
								<td><?php echo esc_html( $row['label'] ); ?></td>
								<td><?php echo esc_html( $row['type'] . ' / ' . $row['field_type'] ); ?><?php echo $row['type_ok'] ? '' : ' <strong style="color:#d63638">wrong type in portal</strong>'; ?></td>
								<td><?php echo law_hubspot_admin_yes_no( $row['exists'] ); ?></td>
								<td><?php echo $row['missing_options'] ? esc_html( implode( ', ', $row['missing_options'] ) ) : '–'; ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php foreach ( array( 'contact_type' => 'Contact type (shared: LAW staff edit this too; the website only adds options)', 'legal_basis' => 'Legal basis (shared: the website only fills or upgrades it)' ) as $key => $heading ) : ?>
					<?php $shared = $report[ $key ]; ?>
					<h3><?php echo esc_html( $heading ); ?></h3>
					<table class="widefat striped" style="max-width:900px">
						<tbody>
							<tr><th scope="row" style="width:220px">Internal name</th><td><code><?php echo esc_html( $shared['name'] ); ?></code> <?php echo $shared['exists'] ? '(' . esc_html( $shared['label'] ) . ', ' . esc_html( $shared['type'] ) . ')' : '<strong style="color:#d63638">not found: confirm the internal name in the portal</strong>'; ?></td></tr>
							<tr><th scope="row">Values the website writes</th><td>
								<?php foreach ( $shared['needed'] as $value ) : ?>
									<?php $missing = in_array( $value, $shared['missing_values'], true ); ?>
									<code style="<?php echo $missing ? 'color:#d63638' : 'color:#008a20'; ?>"><?php echo esc_html( $value ); ?></code>
									<?php if ( isset( $shared['label_mismatches'][ $value ] ) ) : ?>
										<span class="description">(exists as the label of value <?php echo esc_html( implode( ', ', $shared['label_mismatches'][ $value ] ) ); ?>; fix with HubSpot's merge tool)</span>
									<?php endif; ?>
									<?php if ( isset( $shared['near_misses'][ $value ] ) ) : ?>
										<span class="description">(portal has <?php echo esc_html( implode( ', ', array_map( fn( $v ) => '"' . $v . '"', $shared['near_misses'][ $value ] ) ) ); ?>, differing only in case or dashes; probably a config typo)</span>
									<?php endif; ?>
									<br>
								<?php endforeach; ?>
							</td></tr>
							<?php if ( $shared['exists'] ) : ?>
								<tr><th scope="row">Portal options (value → label)</th><td style="max-height:200px;overflow:auto;display:block">
									<?php foreach ( $shared['options'] as $value => $label ) : ?>
										<code><?php echo esc_html( $value ); ?></code><?php echo $label !== $value ? ' → ' . esc_html( $label ) : ''; ?><br>
									<?php endforeach; ?>
								</td></tr>
							<?php endif; ?>
						</tbody>
					</table>
				<?php endforeach; ?>
			<?php endif; ?>
		<?php else : ?>
			<p class="description">Not checked yet.</p>
		<?php endif; ?>

		<h2>Parked addresses</h2>
		<?php if ( $parked ) : ?>
			<p>These failed every retry and wait for a person. "Retry parked" above puts them back in the queue; the log below has the detail.</p>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Email</th><th>Reason</th><th>Attempts</th><th>Last error</th><th>Queued</th></tr></thead>
				<tbody>
					<?php foreach ( $parked as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->email ); ?></td>
							<td><?php echo esc_html( $row->reason ); ?></td>
							<td><?php echo (int) $row->attempts; ?></td>
							<td><?php echo esc_html( $row->last_error ); ?></td>
							<td><?php echo esc_html( $row->queued_at ); ?> UTC</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p class="description">None.</p>
		<?php endif; ?>

		<h2>Recent log</h2>
		<?php if ( $log ) : ?>
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr><th style="width:150px">When (UTC)</th><th>Email</th><th>Action</th><th>Result</th><th>Detail</th></tr></thead>
				<tbody>
					<?php foreach ( $log as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row->created_at ); ?></td>
							<td><?php echo esc_html( $row->email ); ?></td>
							<td><code><?php echo esc_html( $row->action ); ?></code></td>
							<td><?php echo esc_html( $row->result ); ?></td>
							<td><details><summary><?php echo esc_html( mb_strimwidth( (string) $row->detail, 0, 120, '…' ) ); ?></summary><pre style="white-space:pre-wrap;max-height:300px;overflow:auto"><?php echo esc_html( law_hubspot_admin_pretty( $row->detail ) ); ?></pre></details></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Kept for <?php echo (int) $config['log_retention_days']; ?> days. <code>wp law hubspot log</code> shows more.</p>
		<?php else : ?>
			<p class="description">Nothing logged yet.</p>
		<?php endif; ?>
	</div>
	<?php
}

/** JSON detail, pretty-printed when it is JSON. */
function law_hubspot_admin_pretty( $detail ) {
	$decoded = json_decode( (string) $detail, true );
	if ( is_array( $decoded ) ) {
		return (string) wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
	return (string) $detail;
}
