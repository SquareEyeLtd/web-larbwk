<?php
/**
 * Events settings: programme year/week, slot choices, committee recipients,
 * fee tiers, Stripe tax rate / rendering template IDs, host-edit review mode.
 *
 * Stripe KEYS are wp-config constants (LAW_STRIPE_PUBLISHABLE_KEY,
 * LAW_STRIPE_SECRET_KEY, LAW_STRIPE_WEBHOOK_SECRET), never options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_EVENTS_SETTINGS_OPTION = 'law_events_settings';

function law_events_settings_defaults() {
	return array(
		'year'                  => 2026,
		'week_start'            => '2026-11-30',
		'week_end'              => '2026-12-04',
		// Each slot: label (as shown to hosts/committee), date (Y-m-d),
		// start/end (HH:MM, end '' = onwards), retired (bool).
		'slots'                 => array(),
		'committee_emails'      => array(),
		// Tier key => [label, amount] in POUNDS. Mirrors form 2 field 53 (Event fee).
		'fee_tiers'             => array(
			'uk'            => array( 'label' => 'UK hosts: £1200 + VAT', 'amount' => 1200 ),
			'international' => array( 'label' => 'International hosts (no UK office): £600 + VAT', 'amount' => 600 ),
			'sponsor'       => array( 'label' => 'Platinum, Gold, Silver Sponsors: free', 'amount' => 0 ),
		),
		'tax_rate_id'           => '',
		'rendering_template_id' => '',
		'host_edit_review'      => 'immediate', // or 'review' (4.2 §4.2 toggle).
	);
}

function law_events_settings() {
	$saved = get_option( LAW_EVENTS_SETTINGS_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return array_merge( law_events_settings_defaults(), $saved );
}

/**
 * @param string $key     Settings key.
 * @param mixed  $default Fallback when unset/empty.
 */
function law_events_setting( $key, $default = null ) {
	$settings = law_events_settings();
	if ( isset( $settings[ $key ] ) && '' !== $settings[ $key ] && array() !== $settings[ $key ] ) {
		return $settings[ $key ];
	}
	return $default;
}

function law_events_update_settings( array $changes ) {
	$settings = array_merge( law_events_settings(), $changes );
	update_option( LAW_EVENTS_SETTINGS_OPTION, $settings, false );
	return $settings;
}

/**
 * Active (non-retired) slot choices for the submission form and slot picker.
 *
 * @param bool $include_retired Include retired slots (for rendering stored values).
 * @return array<string,array{label:string,date:string,start:string,end:string,retired:bool}> keyed by label.
 */
function law_events_slots( $include_retired = false ) {
	$slots = law_events_setting( 'slots', array() );
	$out   = array();
	foreach ( (array) $slots as $slot ) {
		if ( ! is_array( $slot ) || empty( $slot['label'] ) ) {
			continue;
		}
		if ( ! $include_retired && ! empty( $slot['retired'] ) ) {
			continue;
		}
		$out[ (string) $slot['label'] ] = array(
			'label'   => (string) $slot['label'],
			'date'    => (string) ( $slot['date'] ?? '' ),
			'start'   => (string) ( $slot['start'] ?? '' ),
			'end'     => (string) ( $slot['end'] ?? '' ),
			'retired' => ! empty( $slot['retired'] ),
		);
	}
	return $out;
}

/** Committee recipient emails, filtered to valid addresses. */
function law_events_committee_emails() {
	$emails = law_events_setting( 'committee_emails', array() );
	return array_values( array_filter( array_map( 'sanitize_email', (array) $emails ), 'is_email' ) );
}

function law_events_stripe_mode() {
	$key = defined( 'LAW_STRIPE_SECRET_KEY' ) ? LAW_STRIPE_SECRET_KEY : '';
	if ( '' === $key ) {
		return 'unconfigured';
	}
	return str_starts_with( $key, 'sk_live_' ) || str_starts_with( $key, 'rk_live_' ) ? 'live' : 'test';
}

/* Admin screen: LAW → Events settings ______________________________________ */

function law_events_register_settings_page() {
	law_events_register_law_subpage( 'law-events-settings', 'Events settings', 'law_events_settings_page' );
}
add_action( 'admin_menu', 'law_events_register_settings_page', 999 );

/**
 * Register a page as a hidden options.php child so the admin_page_{slug} hook
 * exists, then seed $_registered_pages for the possible Admin Menu Editor
 * parents. Same AME-proof pattern as law_register_migrate_speakers_page().
 *
 * @param string   $slug     Page slug.
 * @param string   $title    Page title.
 * @param callable $callback Render callback.
 * @param string   $cap      Required capability.
 */
function law_events_register_law_subpage( $slug, $title, $callback, $cap = 'manage_options' ) {
	// The VISIBLE menu item, under the LAW parent.
	add_submenu_page( 'law-settings', $title, $title, $cap, $slug, $callback );

	// Access resilience: Admin Menu Editor rewrites the LAW parent file, so
	// the page must also exist as a hidden options.php child and be seeded in
	// $_registered_pages for every parent AME might use, or WordPress's
	// access check refuses the URL (the migrate-speakers workaround).
	add_submenu_page( 'options.php', $title, $title, $cap, $slug, $callback );

	global $_registered_pages;
	foreach ( array( 'law-settings', 'admin.php?page=law-settings', 'admin.php' ) as $parent ) {
		$hook = get_plugin_page_hookname( $slug, $parent );
		if ( $hook && empty( $_registered_pages[ $hook ] ) ) {
			$_registered_pages[ $hook ] = true;
			add_action( $hook, $callback );
		}
	}
}

function law_events_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sorry, you are not allowed to access this page.' );
	}

	if ( isset( $_POST['law_events_settings_nonce'] ) ) {
		check_admin_referer( 'law_events_settings', 'law_events_settings_nonce' );
		law_events_settings_save();
		echo '<div class="notice notice-success"><p>Settings saved.</p></div>';
	}

	$s = law_events_settings();
	?>
	<div class="wrap law-events-settings">
		<h1>Events settings</h1>
		<p>Stripe mode: <strong><?php echo esc_html( law_events_stripe_mode() ); ?></strong>
			(keys are wp-config constants and cannot be edited here).</p>
		<form method="post">
			<?php wp_nonce_field( 'law_events_settings', 'law_events_settings_nonce' ); ?>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><label for="law-year">Programme year</label></th>
					<td><input name="year" id="law-year" type="number" value="<?php echo esc_attr( $s['year'] ); ?>" class="small-text"></td></tr>
				<tr><th scope="row"><label for="law-week-start">Week start</label></th>
					<td><input name="week_start" id="law-week-start" type="date" value="<?php echo esc_attr( $s['week_start'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="law-week-end">Week end</label></th>
					<td><input name="week_end" id="law-week-end" type="date" value="<?php echo esc_attr( $s['week_end'] ); ?>"></td></tr>
				<tr><th scope="row">Slots</th>
					<td>
						<p class="description">One per line: <code>Label | date (Y-m-d) | start (HH:MM) | end (HH:MM, empty = onwards) | retired?</code></p>
						<textarea name="slots" rows="14" class="large-text code"><?php
							foreach ( (array) $s['slots'] as $slot ) {
								echo esc_textarea( implode( ' | ', array(
									$slot['label'] ?? '',
									$slot['date'] ?? '',
									$slot['start'] ?? '',
									$slot['end'] ?? '',
									! empty( $slot['retired'] ) ? 'retired' : '',
								) ) ) . "\n";
							}
						?></textarea>
					</td></tr>
				<tr><th scope="row"><label for="law-committee">Committee recipients</label></th>
					<td><textarea name="committee_emails" id="law-committee" rows="4" class="large-text code"><?php echo esc_textarea( implode( "\n", (array) $s['committee_emails'] ) ); ?></textarea>
					<p class="description">One email per line. Used by the committee notifications.</p></td></tr>
				<?php foreach ( $s['fee_tiers'] as $tier => $config ) : ?>
				<tr><th scope="row">Fee tier: <?php echo esc_html( $tier ); ?></th>
					<td>
						<input name="fee_label_<?php echo esc_attr( $tier ); ?>" type="text" class="regular-text" value="<?php echo esc_attr( $config['label'] ); ?>">
						£<input name="fee_amount_<?php echo esc_attr( $tier ); ?>" type="number" class="small-text" min="0" value="<?php echo esc_attr( $config['amount'] ); ?>">
					</td></tr>
				<?php endforeach; ?>
				<tr><th scope="row"><label for="law-taxrate">Stripe tax rate ID</label></th>
					<td><input name="tax_rate_id" id="law-taxrate" type="text" class="regular-text code" value="<?php echo esc_attr( $s['tax_rate_id'] ); ?>" placeholder="txr_…"></td></tr>
				<tr><th scope="row"><label for="law-template">Stripe invoice rendering template ID</label></th>
					<td><input name="rendering_template_id" id="law-template" type="text" class="regular-text code" value="<?php echo esc_attr( $s['rendering_template_id'] ); ?>" placeholder="inrtem_…"></td></tr>
				<tr><th scope="row">Host edits to published events</th>
					<td><label><input type="radio" name="host_edit_review" value="immediate" <?php checked( $s['host_edit_review'], 'immediate' ); ?>> Publish immediately</label><br>
					<label><input type="radio" name="host_edit_review" value="review" disabled> Route to LAW for review <em>(arrives with phase 4.2; 4.1 publishes immediately and emails the committee)</em></label></td></tr>
			</table>
			<?php submit_button( 'Save settings' ); ?>
		</form>
	</div>
	<?php
}

function law_events_settings_save() {
	$changes = array(
		'year'                  => absint( $_POST['year'] ?? 2026 ),
		'week_start'            => sanitize_text_field( wp_unslash( $_POST['week_start'] ?? '' ) ),
		'week_end'              => sanitize_text_field( wp_unslash( $_POST['week_end'] ?? '' ) ),
		'tax_rate_id'           => sanitize_text_field( wp_unslash( $_POST['tax_rate_id'] ?? '' ) ),
		'rendering_template_id' => sanitize_text_field( wp_unslash( $_POST['rendering_template_id'] ?? '' ) ),
		'host_edit_review'      => ( 'review' === ( $_POST['host_edit_review'] ?? '' ) ) ? 'review' : 'immediate',
	);

	$slots = array();
	foreach ( explode( "\n", (string) wp_unslash( $_POST['slots'] ?? '' ) ) as $line ) {
		$parts = array_map( 'trim', explode( '|', $line ) );
		if ( '' === ( $parts[0] ?? '' ) ) {
			continue;
		}
		$slots[] = array(
			'label'   => sanitize_text_field( $parts[0] ),
			'date'    => sanitize_text_field( $parts[1] ?? '' ),
			'start'   => sanitize_text_field( $parts[2] ?? '' ),
			'end'     => sanitize_text_field( $parts[3] ?? '' ),
			'retired' => 'retired' === strtolower( $parts[4] ?? '' ),
		);
	}
	$changes['slots'] = $slots;

	$emails = array();
	foreach ( explode( "\n", (string) wp_unslash( $_POST['committee_emails'] ?? '' ) ) as $line ) {
		$email = sanitize_email( trim( $line ) );
		if ( is_email( $email ) ) {
			$emails[] = $email;
		}
	}
	$changes['committee_emails'] = $emails;

	$tiers = law_events_setting( 'fee_tiers' );
	foreach ( $tiers as $tier => $config ) {
		$tiers[ $tier ]['label']  = sanitize_text_field( wp_unslash( $_POST[ 'fee_label_' . $tier ] ?? $config['label'] ) );
		$tiers[ $tier ]['amount'] = max( 0, absint( $_POST[ 'fee_amount_' . $tier ] ?? $config['amount'] ) );
	}
	$changes['fee_tiers'] = $tiers;

	law_events_update_settings( $changes );
}
