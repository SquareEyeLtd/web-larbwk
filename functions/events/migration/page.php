<?php
/**
 * LAW → Migration: the admin screen (EVENTS_4.1_REBUILD.md §5.2). Step cards,
 * dry-run-by-default controls, a snapshot gate, AJAX-run steps with a live
 * log tail, the preflight results and the verification panel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	law_events_register_law_subpage( 'law-migration', 'Migration', 'law_migration_admin_page' );
}, 999 );

function law_migration_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to access this page.' );
	}

	if ( isset( $_POST['law_migration_override_nonce'] ) ) {
		check_admin_referer( 'law_migration_override', 'law_migration_override_nonce' );
		update_option( 'law_migration_snapshot_override', ! empty( $_POST['snapshot_override'] ) ? 1 : 0, false );
	}
	if ( isset( $_POST['law_migration_source_nonce'] ) ) {
		check_admin_referer( 'law_migration_source', 'law_migration_source_nonce' );
		$new = 'cpt' === ( $_POST['law_events_source'] ?? '' ) ? 'cpt' : 'gf';
		update_option( 'law_events_source', $new, false );
		// The migrated forms must stop accepting submissions the moment the
		// module takes over: with the embeds gone they remain anonymously
		// submittable through GF's REST API (/gf/v2/forms/<id>/submissions),
		// which would run the LEGACY feeds and workflow. Form 7 (Contact)
		// stays active. Flipping back to GF reactivates them.
		$module_forms = array( 1, 2, 3, 4, 5, 6, 8, 9 );
		if ( class_exists( 'GFAPI' ) && class_exists( 'GFFormsModel' ) ) {
			foreach ( $module_forms as $form_id ) {
				GFFormsModel::update_form_active( $form_id, 'cpt' === $new ? 0 : 1 );
			}
			law_migration_log(
				'redirects',
				'info',
				'forms',
				sprintf( 'Module Gravity Forms (1,2,3,4,5,6,8,9) marked %s with the source flip.', 'cpt' === $new ? 'INACTIVE' : 'active again' )
			);
		}
		echo '<div class="notice notice-success"><p>Front-end data source flipped to <strong>' . esc_html( $new ) . '</strong>; the module Gravity Forms were marked ' . ( 'cpt' === $new ? 'inactive' : 'active' ) . ' (form 7, Contact, untouched).</p></div>';
	}

	$snapshot  = get_option( 'law_migration_snapshot' );
	$preflight = get_option( 'law_migration_preflight' );
	$override  = (bool) get_option( 'law_migration_snapshot_override' );
	?>
	<div class="wrap law-migration">
		<h1>Events migration</h1>
		<p>Gravity Forms → custom events module. Source data is never modified; every step is idempotent and re-runnable.
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=law_migration_export' ), 'law_migration_export' ) ); ?>">Download CSV report</a></p>

		<div class="law-mig-grid">
			<div class="law-mig-card">
				<h2>Step 0: database snapshot</h2>
				<?php if ( is_array( $snapshot ) ) : ?>
					<p><strong><?php echo esc_html( basename( $snapshot['file'] ) ); ?></strong><br>
						<?php echo esc_html( sprintf( '%s · %d tables · %s · sha256 %s…', size_format( $snapshot['size'] ), $snapshot['tables'], gmdate( 'j M Y H:i', $snapshot['at'] ) . ' UTC', substr( $snapshot['sha256'], 0, 12 ) ) ); ?>
						· <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=law_migration_snapshot_download' ), 'law_migration_snapshot_download' ) ); ?>">Download</a></p>
				<?php else : ?>
					<p>No snapshot yet.</p>
				<?php endif; ?>
				<p><button class="button button-secondary law-mig-run" data-step="snapshot" data-dry="0">Create snapshot</button></p>
				<form method="post">
					<?php wp_nonce_field( 'law_migration_override', 'law_migration_override_nonce' ); ?>
					<label><input type="checkbox" name="snapshot_override" value="1" <?php checked( $override ); ?> onchange="this.form.submit()">
						I have a server-level backup (override the snapshot gate)</label>
				</form>
			</div>

			<div class="law-mig-card">
				<h2>Preflight checks</h2>
				<?php if ( is_array( $preflight ) ) : ?>
					<p>Last run <?php echo esc_html( gmdate( 'j M Y H:i', $preflight['at'] ) ); ?> UTC:
						<strong><?php echo $preflight['pass'] ? '<span style="color:#00a32a">PASS</span>' : '<span style="color:#b32d2e">FAIL</span>'; ?></strong></p>
					<ul class="law-mig-checks">
						<?php foreach ( $preflight['checks'] as $check ) : ?>
							<li class="is-<?php echo esc_attr( $check['status'] ); ?>">
								<strong><?php echo esc_html( $check['label'] ); ?></strong>: <?php echo esc_html( $check['detail'] ); ?>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php else : ?>
					<p>Not run yet.</p>
				<?php endif; ?>
				<p><button class="button button-secondary law-mig-run" data-step="preflight" data-dry="0">Run preflight</button></p>
			</div>
		</div>

		<h2>Migration steps</h2>
		<p>Dry run first, always. The real Migrate buttons stay disabled until a snapshot from the last hour exists (or the override is ticked).</p>
		<table class="widefat striped">
			<thead><tr><th>Step</th><th>Last run</th><th style="width:220px">Run</th></tr></thead>
			<tbody>
			<?php foreach ( law_migration_steps() as $step => $config ) :
				if ( in_array( $step, array( 'snapshot', 'preflight' ), true ) ) {
					continue;
				}
				$summary = law_migration_step_summary( $step );
				?>
				<tr>
					<td><strong><?php echo esc_html( $config['label'] ); ?></strong>
						<?php if ( in_array( $step, array( 'counters', 'notifications' ), true ) ) : ?>
							<br><span class="description">Re-running OVERWRITES admin edits made since (slots/recipients or email wording).</span>
						<?php endif; ?></td>
					<td class="law-mig-summary" data-step-summary="<?php echo esc_attr( $step ); ?>">
						<?php
						if ( $summary ) {
							$bits = array();
							foreach ( $summary as $status => $count ) {
								$bits[] = $count . ' ' . $status;
							}
							echo esc_html( implode( ', ', $bits ) );
						} else {
							echo '—';
						}
						?>
					</td>
					<td>
						<button class="button law-mig-run" data-step="<?php echo esc_attr( $step ); ?>" data-dry="1">Dry run</button>
						<button class="button button-primary law-mig-run law-mig-real" data-step="<?php echo esc_attr( $step ); ?>" data-dry="0"
							<?php disabled( ! law_migration_snapshot_ok() ); ?>>Migrate</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button class="button law-mig-run-all" data-dry="1">Dry run all</button>
			<button class="button button-primary law-mig-run-all law-mig-real" data-dry="0" <?php disabled( ! law_migration_snapshot_ok() ); ?>>Run all (real)</button>
			<span class="spinner" style="float:none"></span>
		</p>

		<div class="law-mig-progress" hidden>
			<progress max="100" value="0"></progress> <span class="law-mig-progress-label"></span>
		</div>

		<h2>Log tail</h2>
		<ol class="law-mig-tail" reversed>
			<?php foreach ( law_migration_log_tail() as $line ) : ?>
				<li class="is-<?php echo esc_attr( $line->status ); ?>">
					<code><?php echo esc_html( $line->step ); ?></code>
					[<?php echo esc_html( $line->status ); ?>]
					<?php echo esc_html( $line->item_ref ); ?> — <?php echo esc_html( $line->message ); ?>
				</li>
			<?php endforeach; ?>
		</ol>

		<h2>Verification panel</h2>
		<?php $verification = law_migration_verification(); ?>
		<table class="widefat striped" style="max-width:640px">
			<tbody>
			<?php foreach ( $verification as $label => $count ) : ?>
				<tr><td><?php echo esc_html( $label ); ?></td><td><strong><?php echo esc_html( (string) $count ); ?></strong></td></tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h3>Spot checks: old URL → new URL</h3>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
			<?php foreach ( law_migration_spot_checks() as $check ) : ?>
				<tr>
					<td><?php echo esc_html( $check['label'] ); ?></td>
					<td><a href="<?php echo esc_url( $check['old'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $check['old'] ); ?></a></td>
					<td>→ <a href="<?php echo esc_url( $check['new'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $check['new'] ); ?></a></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Cutover: front-end data source</h2>
		<form method="post">
			<?php wp_nonce_field( 'law_migration_source', 'law_migration_source_nonce' ); ?>
			<p>Currently reading from: <strong><?php echo esc_html( strtoupper( law_events_source() ) ); ?></strong></p>
			<label><input type="radio" name="law_events_source" value="gf" <?php checked( law_events_source(), 'gf' ); ?>> Gravity Forms entries (legacy)</label><br>
			<label><input type="radio" name="law_events_source" value="cpt" <?php checked( law_events_source(), 'cpt' ); ?>> Migrated events module (CPT)</label>
			<p class="description">The rollback is flipping this back. Flip only after the verification panel matches and the report is clean.</p>
			<?php submit_button( 'Flip data source' ); ?>
		</form>
	</div>

	<script>
	(function () {
		const nonce = <?php echo wp_json_encode( wp_create_nonce( 'law_migration_run' ) ); ?>;
		const ajax  = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
		const gated = <?php echo wp_json_encode( array_keys( array_filter( law_migration_steps(), fn( $s ) => $s['gated'] ) ) ); ?>;
		const spinner = document.querySelector('.law-mig-grid ~ p .spinner, p .spinner');
		const progress = document.querySelector('.law-mig-progress');

		async function runStep(step, dry) {
			const body = new URLSearchParams({ action: 'law_migration_run', _ajax_nonce: nonce, step, dry: dry ? '1' : '0' });
			const response = await fetch(ajax, { method: 'POST', body });
			const data = await response.json();
			if (!data.success) { throw new Error(data.data || 'Step failed'); }
			return data.data;
		}

		// A step may return done:false (the time-boxed history step): keep
		// requesting batches until it reports done, surfacing batch progress.
		async function runStepToCompletion(step, dry, label) {
			let result;
			do {
				result = await runStep(step, dry);
				if (label && result && result.summary) {
					label.textContent = step + (dry ? ' (dry run)' : '') + ' — ' + result.summary;
				}
			} while (result && result.done === false);
			return result;
		}

		function busy(on) {
			document.querySelectorAll('.law-mig-run, .law-mig-run-all').forEach(b => b.disabled = on);
			if (spinner) spinner.classList.toggle('is-active', on);
		}

		document.querySelectorAll('.law-mig-run').forEach(button => {
			button.addEventListener('click', async () => {
				if (button.dataset.dry === '0' && !confirm('Run this step for real? (A dry run first is strongly recommended.)')) return;
				busy(true);
				progress.hidden = false;
				try { await runStepToCompletion(button.dataset.step, button.dataset.dry === '1', progress.querySelector('.law-mig-progress-label')); location.reload(); }
				catch (e) { alert(e.message); busy(false); }
			});
		});

		document.querySelectorAll('.law-mig-run-all').forEach(button => {
			button.addEventListener('click', async () => {
				const dry = button.dataset.dry === '1';
				if (!dry && !confirm('Run the FULL migration for real, in order?')) return;
				busy(true);
				progress.hidden = false;
				const bar = progress.querySelector('progress');
				const label = progress.querySelector('.law-mig-progress-label');
				try {
					for (let i = 0; i < gated.length; i++) {
						label.textContent = gated[i] + (dry ? ' (dry run)' : '');
						bar.value = Math.round((i / gated.length) * 100);
						await runStepToCompletion(gated[i], dry, label);
					}
					bar.value = 100;
					location.reload();
				} catch (e) { alert(e.message); busy(false); }
			});
		});
	})();
	</script>
	<style>
		.law-mig-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;max-width:1100px;margin:16px 0}
		.law-mig-card{background:#fff;border:1px solid #c3c4c7;padding:12px 16px}
		.law-mig-checks li{margin:2px 0}
		.law-mig-checks .is-fail{color:#b32d2e}
		.law-mig-checks .is-warning{color:#996800}
		.law-mig-checks .is-pass{color:#00a32a}
		.law-mig-tail{max-height:320px;overflow:auto;background:#fff;border:1px solid #c3c4c7;padding:8px 16px 8px 40px;font-size:12px}
		.law-mig-tail .is-error{color:#b32d2e}
		.law-mig-tail .is-warning{color:#996800}
		.law-mig-progress{margin:12px 0}
	</style>
	<?php
}

/* The AJAX runner ___________________________________________________________ */

add_action( 'wp_ajax_law_migration_run', function () {
	check_ajax_referer( 'law_migration_run' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( 'Administrators only.', 403 );
	}

	// One run at a time.
	if ( get_transient( 'law_migration_lock' ) ) {
		wp_send_json_error( 'A migration step is already running.', 409 );
	}
	set_transient( 'law_migration_lock', 1, 5 * MINUTE_IN_SECONDS );

	$step = sanitize_key( $_POST['step'] ?? '' );
	$dry  = '1' === (string) ( $_POST['dry'] ?? '1' );

	$result = law_migration_run_step( $step, $dry );

	delete_transient( 'law_migration_lock' );

	if ( is_wp_error( $result ) ) {
		wp_send_json_error( $result->get_error_message() );
	}
	wp_send_json_success( $result );
} );
