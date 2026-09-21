<?php
/**
 * Stripe payment health: is the signal getting through, and what catches it
 * when it does not.
 *
 * Two things decide whether a host fee ever becomes a confirmed, bookable
 * event, and until now neither of them could be seen from inside WordPress:
 *
 * 1. THE ENDPOINT. Everything the module knows about money arrives as a
 *    webhook. An endpoint that is missing, disabled, pointed at the wrong
 *    address, or subscribed to the wrong set of event types fails in complete
 *    silence -- Stripe delivers nothing, the site logs nothing, and the first
 *    symptom is a host asking why their paid event still says "Open soon".
 *    Nothing in Stripe's dashboard will tell you either, because from its side
 *    nothing is wrong. law_stripe_webhook_health() asks Stripe what it
 *    actually has and compares it against law_stripe_webhook_event_types().
 *
 * 2. THE SWEEP. Even a correct endpoint misses deliveries: the site is down
 *    for the retry window, or the money lands before the module goes live.
 *    functions/events/stripe/reconcile.php compares events against Stripe
 *    daily and heals that. This screen is where its switch and its last run
 *    live.
 *
 * Why this is a SCREEN and not a preflight check: the endpoint can be correct
 * on Monday and wrong on Thursday, because a person changed it in the Stripe
 * dashboard. A migration preflight answers a question once; this answers it
 * whenever somebody asks.
 *
 * One caution built into the wording below. `stripe listen` (the CLI
 * forwarding used for local development) creates NO webhook endpoint object,
 * so on a local site "no endpoint registered" is the expected, healthy answer
 * and the panel says so rather than crying wolf. The check that matters is the
 * one run against the LIVE key on production.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * What Stripe has registered against this site, and what is wrong with it.
 *
 * Read-only: one GET. Endpoints are per mode, so this necessarily reports on
 * whichever key the site is configured with -- which is the right behaviour,
 * since a live site checking its test endpoints would be worthless.
 *
 * @return array<string,mixed>
 */
function law_stripe_webhook_health() {
	$expected = law_stripe_webhook_url();
	$wanted   = law_stripe_webhook_event_types();

	$health = array(
		'mode'            => law_events_stripe_mode(),
		'url'             => $expected,
		'secret_set'      => '' !== law_stripe_webhook_secret(),
		'wanted'          => $wanted,
		'endpoints'       => array(),
		'ours'            => null,
		'missing'         => array(),
		'near_misses'     => array(),
		'verdict'         => 'unreadable',
		'detail'          => '',
		'listen_command'  => sprintf(
			'stripe listen --forward-to %s --events %s',
			$expected,
			implode( ',', $wanted )
		),
	);

	if ( '' === law_stripe_secret_key() ) {
		$health['verdict'] = 'unconfigured';
		$health['detail']  = 'Stripe is not configured on this site (LAW_STRIPE_SECRET_KEY is not set in wp-config), so no payment can be received or checked.';
		return $health;
	}

	$response = law_stripe_request( 'GET', '/v1/webhook_endpoints', array( 'limit' => 100 ) );
	if ( is_wp_error( $response ) ) {
		$health['detail'] = 'Stripe would not list the webhook endpoints: ' . $response->get_error_message()
			. ' (a restricted key may simply lack permission to read them, which does not mean the endpoint is wrong.)';
		return $health;
	}

	$expected_path = (string) wp_parse_url( $expected, PHP_URL_PATH );
	foreach ( (array) ( $response['data'] ?? array() ) as $endpoint ) {
		$url    = (string) ( $endpoint['url'] ?? '' );
		$events = array_map( 'strval', (array) ( $endpoint['enabled_events'] ?? array() ) );
		sort( $events );

		$row = array(
			'id'      => (string) ( $endpoint['id'] ?? '' ),
			'url'     => $url,
			'status'  => (string) ( $endpoint['status'] ?? '' ),
			'events'  => $events,
			'is_ours' => $url === $expected,
		);
		$health['endpoints'][] = $row;

		if ( $row['is_ours'] ) {
			$health['ours'] = $row;
			continue;
		}
		// The commonest real-world misconfiguration by a distance: the right
		// path on the wrong host (staging's endpoint left pointing at
		// production, or www vs bare domain). Worth naming rather than
		// reporting a bare "no endpoint found".
		if ( '' !== $expected_path && (string) wp_parse_url( $url, PHP_URL_PATH ) === $expected_path ) {
			$health['near_misses'][] = $row;
		}
	}

	if ( ! $health['ours'] ) {
		$health['verdict'] = 'no_endpoint';
		$health['detail']  = sprintf(
			'Stripe has no %s-mode endpoint pointing at %s. In local development that is expected, because `stripe listen` forwards without registering one; on a live site it means no payment can ever reach this module.',
			$health['mode'],
			$expected
		);
		return $health;
	}

	// '*' is Stripe's "every event type", which subscribes to everything the
	// module wants and a great deal it does not. Noisy, not broken.
	$subscribed        = in_array( '*', $health['ours']['events'], true ) ? $wanted : $health['ours']['events'];
	$health['missing'] = array_values( array_diff( $wanted, $subscribed ) );

	if ( 'enabled' !== $health['ours']['status'] ) {
		$health['verdict'] = 'disabled';
		$health['detail']  = sprintf( 'The endpoint exists but Stripe has it as "%s", so nothing is being delivered.', $health['ours']['status'] );
		return $health;
	}

	if ( $health['missing'] ) {
		$health['verdict'] = 'missing_events';
		$health['detail']  = sprintf(
			'The endpoint is live but is not subscribed to %d event type(s) this module acts on: %s. Anything they carry is silently lost.',
			count( $health['missing'] ),
			implode( ', ', $health['missing'] )
		);
		return $health;
	}

	if ( ! $health['secret_set'] ) {
		$health['verdict'] = 'no_secret';
		$health['detail']  = 'The endpoint is correct, but LAW_STRIPE_WEBHOOK_SECRET is not set in wp-config, so every delivery fails signature verification and is rejected.';
		return $health;
	}

	$health['verdict'] = 'ok';
	$health['detail']  = sprintf( 'The endpoint is live and subscribed to all %d event types this module acts on.', count( $wanted ) );
	return $health;
}

/* The Events → Settings panel ________________________________________________ */

/**
 * "Stripe payment health": the endpoint check, and the reconciliation sweep's
 * switch and last run.
 *
 * Rendered on the events settings screen rather than on Migration, because
 * neither half is a migration task: both stay true for as long as the site
 * takes money. The endpoint check is behind a button because it costs a Stripe
 * call, which has no business running every time somebody opens Settings.
 */
function law_events_payment_health_panel() {
	$health = null;
	$notice = '';

	if ( isset( $_POST['law_payment_health_nonce'] ) ) {
		check_admin_referer( 'law_payment_health', 'law_payment_health_nonce' );

		if ( isset( $_POST['law_reconcile_toggle'] ) ) {
			$on = ! empty( $_POST['law_reconcile_enabled'] );
			update_option( LAW_RECONCILE_ENABLED_OPTION, $on ? 1 : 0, false );
			$notice = '<div class="notice notice-success"><p>The daily payment reconciliation is now <strong>'
				. ( $on ? 'on' : 'off' ) . '</strong>.</p></div>';
		} elseif ( isset( $_POST['law_reconcile_run'] ) ) {
			$summary = law_stripe_reconcile_run_sweep();
			$notice  = sprintf(
				'<div class="notice notice-%s"><p><strong>Reconciliation run:</strong> %d event(s) checked, %d payment(s) recorded and confirmed, %d discrepancy(ies) raised, %d unreadable.</p>%s</div>',
				$summary['flagged'] ? 'warning' : 'success',
				$summary['checked'],
				$summary['settled'],
				$summary['flagged'],
				$summary['unreadable'],
				$summary['lines']
					? '<ul style="margin-left:1.5em;list-style:disc"><li>' . implode( '</li><li>', array_map( 'esc_html', $summary['lines'] ) ) . '</li></ul>'
					: ''
			);
		} else {
			$health = law_stripe_webhook_health();
		}
	}

	$colours = array(
		'ok'             => '#00a32a',
		'unconfigured'   => '#b32d2e',
		'no_endpoint'    => '#dba617',
		'disabled'       => '#b32d2e',
		'missing_events' => '#b32d2e',
		'no_secret'      => '#b32d2e',
		'unreadable'     => '#dba617',
	);

	$last      = get_option( LAW_RECONCILE_LAST_RUN_OPTION );
	$next      = wp_next_scheduled( 'law_events_payment_reconcile' );
	$enabled   = law_stripe_reconcile_enabled();

	echo $notice; // phpcs:ignore WordPress.Security.EscapeOutput -- built above with esc_html().
	?>
	<h2>Stripe payment health</h2>
	<p class="description" style="max-width:900px">
		Paying is what confirms and publishes a hosted event, and the only thing that tells this site a fee was paid
		is a Stripe webhook. If that signal stops, nothing raises its voice: the event stays Approved, shows
		&ldquo;Open soon&rdquo; on the programme, refuses every booking, and the host is chased for money they have
		already sent. These two checks are what stand between that and a silent failure.
	</p>

	<h3>Webhook endpoint</h3>
	<form method="post">
		<?php wp_nonce_field( 'law_payment_health', 'law_payment_health_nonce' ); ?>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
			<tr><td style="width:220px">Stripe mode</td><td><strong><?php echo esc_html( law_events_stripe_mode() ); ?></strong></td></tr>
			<tr><td>This site&rsquo;s endpoint</td><td><code><?php echo esc_html( law_stripe_webhook_url() ); ?></code></td></tr>
			<tr><td>Signing secret</td>
				<td><?php echo '' !== law_stripe_webhook_secret()
					? 'set in wp-config'
					: '<strong style="color:#b32d2e">not set</strong> (every delivery would fail signature verification)'; ?></td></tr>
			<tr><td>Event types acted on</td>
				<td><?php echo esc_html( implode( ', ', law_stripe_webhook_event_types() ) ); ?></td></tr>
			</tbody>
		</table>
		<p><button type="submit" class="button">Check the endpoint in Stripe</button></p>
	</form>

	<?php if ( $health ) : ?>
		<p style="max-width:900px">
			<strong style="color:<?php echo esc_attr( $colours[ $health['verdict'] ] ?? '#1d2327' ); ?>">
				<?php echo esc_html( strtoupper( str_replace( '_', ' ', $health['verdict'] ) ) ); ?>
			</strong><br>
			<?php echo esc_html( $health['detail'] ); ?>
		</p>

		<?php if ( 'no_endpoint' === $health['verdict'] ) : ?>
			<p class="description" style="max-width:900px">
				To forward deliveries to this site from the Stripe CLI, which registers no endpoint and is the normal
				way to work locally:
			</p>
			<p><code><?php echo esc_html( $health['listen_command'] ); ?></code></p>
		<?php endif; ?>

		<?php if ( $health['near_misses'] ) : ?>
			<p style="max-width:900px"><strong>Endpoints with this path on a different host</strong>, which is usually a
				staging or production endpoint left pointing the wrong way:</p>
			<ul style="margin-left:1.5em;list-style:disc">
				<?php foreach ( $health['near_misses'] as $row ) : ?>
					<li><code><?php echo esc_html( $row['url'] ); ?></code> (<?php echo esc_html( $row['status'] ); ?>)</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<?php if ( $health['endpoints'] ) : ?>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th>Endpoint</th><th style="width:90px">Status</th><th style="width:120px">Event types</th></tr></thead>
				<tbody>
				<?php foreach ( $health['endpoints'] as $row ) : ?>
					<tr>
						<td><code><?php echo esc_html( $row['url'] ); ?></code>
							<?php if ( $row['is_ours'] ) : ?><br><span class="description">this site</span><?php endif; ?></td>
						<td><?php echo esc_html( $row['status'] ); ?></td>
						<td><?php echo esc_html( in_array( '*', $row['events'], true ) ? 'all' : (string) count( $row['events'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	<?php endif; ?>

	<h3>Daily reconciliation</h3>
	<p class="description" style="max-width:900px">
		Once a day, every event holding a Stripe invoice ID is compared against Stripe. Where Stripe has been paid and
		this site has not recorded it, the payment is recorded and the event confirmed and published exactly as a live
		payment would have done, with the same emails. Every other disagreement (this site says paid and the invoice is
		open, voided, or paid on a cancelled event) is reported to the administrators and changed by nobody but a human.
		Legacy events still holding only an invoice web address are not swept: they belong to the repair panel on
		LAW &rarr; Migration until it has given them an invoice ID.
	</p>
	<?php // Two forms, not one: a single form would have to carry the toggle as a
	// hidden field, which the "Reconcile now" button would then trip on its way
	// past. ?>
	<form method="post">
		<?php wp_nonce_field( 'law_payment_health', 'law_payment_health_nonce' ); ?>
		<input type="hidden" name="law_reconcile_toggle" value="1">
		<p><label><input type="checkbox" name="law_reconcile_enabled" value="1" <?php checked( $enabled ); ?>
			onchange="this.form.submit()"> Run the reconciliation daily</label></p>
	</form>
	<form method="post">
		<?php wp_nonce_field( 'law_payment_health', 'law_payment_health_nonce' ); ?>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
			<tr><td style="width:220px">Status</td>
				<td><?php echo $enabled ? 'running daily' : '<strong>off</strong>'; ?></td></tr>
			<tr><td>Next run</td>
				<td><?php echo $next ? esc_html( gmdate( 'j M Y H:i', $next ) . ' UTC' ) : 'not scheduled'; ?></td></tr>
			<tr><td>Last run</td>
				<td><?php
					if ( is_array( $last ) ) {
						printf(
							'%s UTC — %d checked, %d recorded and confirmed, %d raised for review, %d unreadable',
							esc_html( gmdate( 'j M Y H:i', (int) $last['at'] ) ),
							(int) $last['checked'],
							(int) $last['settled'],
							(int) $last['flagged'],
							(int) $last['unreadable']
						);
					} else {
						echo 'never';
					}
				?></td></tr>
			</tbody>
		</table>
		<p><button type="submit" name="law_reconcile_run" value="1" class="button">Reconcile now</button></p>
	</form>
	<?php
}
