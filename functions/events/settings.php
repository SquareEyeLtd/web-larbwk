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
		// Host terms page. Empty = resolve the page by its path (see
		// law_events_terms_url()); set to a page ID or an absolute URL to override.
		'terms_page'            => '',
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
 * The host terms & conditions page the submission form links to.
 *
 * Form 2 (Event > submit an event) field 69 (Terms & conditions) linked to the
 * page at /policies/event-host-terms-conditions/ from its description, so that
 * page stays the target. The 'terms_page' setting (a page ID or an absolute
 * URL) overrides it; if neither resolves, the Policies index is the fallback,
 * which is at least a real page.
 *
 * @return string Absolute URL.
 */
function law_events_terms_url() {
	$setting = law_events_setting( 'terms_page', '' );
	if ( is_numeric( $setting ) && get_post_status( (int) $setting ) ) {
		return (string) get_permalink( (int) $setting );
	}
	if ( is_string( $setting ) && 0 === strpos( $setting, 'http' ) ) {
		return $setting;
	}
	foreach ( array( 'policies/event-host-terms-conditions', 'policies' ) as $path ) {
		$page = get_page_by_path( $path );
		if ( $page && 'publish' === $page->post_status ) {
			return (string) get_permalink( $page );
		}
	}
	return home_url( '/policies/' );
}

/**
 * Venue capacity bands (form 2 field 55 Venue capacity), each mapped to the
 * largest audience it allows. Shared by the host form, the wp-admin event
 * screen and the ticket-allocation check, so the three cannot drift.
 *
 * Every band is inclusive of its number: tickets may equal the band ceiling,
 * never exceed it. "Under 50" therefore allows 50 — the legacy band list runs
 * "Under 50" then "51-100", so a strict 49 would leave exactly 50 with no band
 * that accepts it. "251+" and "TBC" have no ceiling to check against (null).
 *
 * @return array<string,int|null> Band label => maximum tickets, or null when uncapped.
 */
function law_events_venue_capacity_bands() {
	return array(
		'Under 50' => 50,
		'51-100'   => 100,
		'101-150'  => 150,
		'151-250'  => 250,
		'251+'     => null,
		'TBC'      => null,
	);
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

/**
 * Comparison key for a slot label: entities decoded, en/em dashes folded to a
 * plain hyphen, the spaces around that hyphen dropped, whitespace collapsed,
 * lower-cased.
 *
 * Legacy form 2 (Event > submit an event) held the same twelve slots in two
 * fields with different punctuation: field 68 (Confirmed slot) used en dashes
 * ("Tue 1st Dec: 08:30-10:00" with an en dash) and field 77 (Preferred date &
 * time slots) plain hyphens. The settings slot list is seeded from field 68, so
 * a stored preferred slot has to be compared loosely or it matches no
 * configured slot at all - which silently emptied the host form's checkboxes.
 *
 * @param string $label Raw label.
 * @return string Comparison key ('' when the label is empty).
 */
function law_events_slot_label_key( $label ) {
	$label = html_entity_decode( (string) $label, ENT_QUOTES, 'UTF-8' );
	$label = str_replace( array( "\xe2\x80\x93", "\xe2\x80\x94", "\xe2\x88\x92" ), '-', $label );
	$label = preg_replace( '/\s*-\s*/', '-', $label );
	$label = preg_replace( '/\s+/', ' ', $label );
	return strtolower( trim( (string) $label ) );
}

/**
 * Map a stored or submitted slot label onto the configured slot label.
 *
 * @param string $label Raw label.
 * @return string The configured label, or '' when it matches no configured slot.
 */
function law_events_normalise_slot_label( $label ) {
	$key = law_events_slot_label_key( $label );
	if ( '' === $key ) {
		return '';
	}
	foreach ( law_events_slots( true ) as $slot_label => $slot ) {
		if ( law_events_slot_label_key( $slot_label ) === $key ) {
			return (string) $slot_label;
		}
	}
	return '';
}

/**
 * Slot choices to offer on the submission/edit form: every active slot, plus
 * any retired slot the event already holds.
 *
 * A retired slot is hidden from new choices but must stay visible (and
 * checked) on an event that already chose it, exactly as
 * law_gf_hide_retired_preferred_slots() did on form 2 (Event > submit an
 * event) field 77 (Preferred date & time slots). Omitting the checkbox
 * altogether meant the host's own choice vanished from the form and was
 * dropped on the next save.
 *
 * @param array $selected Labels already stored on the event.
 * @return array<string,array{label:string,date:string,start:string,end:string,retired:bool}>
 */
function law_events_slot_choices( array $selected = array() ) {
	$selected_keys = array();
	foreach ( $selected as $label ) {
		$selected_keys[ law_events_slot_label_key( $label ) ] = true;
	}

	$out = array();
	foreach ( law_events_slots( true ) as $slot_label => $slot ) {
		if ( empty( $slot['retired'] ) || isset( $selected_keys[ law_events_slot_label_key( $slot_label ) ] ) ) {
			$out[ $slot_label ] = $slot;
		}
	}
	return $out;
}

/**
 * Sanitise submitted preferred slots against the configured list.
 *
 * Returns configured labels, in settings order, keeping a retired slot only
 * when the event already held it - the equivalent of
 * law_gf_strip_retired_on_new_submissions(), which stopped a tampered POST
 * from re-selecting a withdrawn slot on form 2 (Event > submit an event).
 * Falls back to plain sanitisation when no slots are configured yet, so a
 * site whose settings have not been seeded cannot be locked out of the form.
 *
 * @param array $submitted Raw submitted labels.
 * @param array $existing  Labels already stored on the event.
 * @return string[]
 */
function law_events_sanitise_preferred_slots( array $submitted, array $existing = array() ) {
	if ( ! law_events_slots( true ) ) {
		return array_values( array_filter( array_map( 'sanitize_text_field', $submitted ) ) );
	}

	$submitted_keys = array();
	foreach ( $submitted as $label ) {
		$submitted_keys[ law_events_slot_label_key( $label ) ] = true;
	}

	$out = array();
	foreach ( law_events_slot_choices( $existing ) as $slot_label => $slot ) {
		if ( isset( $submitted_keys[ law_events_slot_label_key( $slot_label ) ] ) ) {
			$out[] = (string) $slot_label;
		}
	}
	return $out;
}

/**
 * Apply a confirmed slot label to an event: write _law_start/_law_end from the
 * slot's date/time, or clear them when the label is emptied. Shared by the
 * committee dashboard and the wp-admin event screen so the two save paths can't
 * drift (the admin path previously left stale datetimes when a slot was
 * cleared).
 *
 * @param int    $event_id   law_event post ID.
 * @param string $slot_label Chosen slot label (empty to clear).
 */
function law_event_apply_slot_label( $event_id, $slot_label ) {
	$slot_label = (string) $slot_label;
	// A label stored before the punctuation was normalised (or posted from an
	// older cached page) still has to resolve to its configured slot, or the
	// event would keep stale datetimes.
	$slot_label = law_events_normalise_slot_label( $slot_label ) ?: $slot_label;
	$slots      = law_events_slots( true );
	if ( isset( $slots[ $slot_label ] ) && $slots[ $slot_label ]['date'] ) {
		$slot = $slots[ $slot_label ];
		law_event_update_meta( $event_id, '_law_start', $slot['date'] . ' ' . ( $slot['start'] ?: '00:00' ) );
		law_event_update_meta( $event_id, '_law_end', $slot['end'] ? $slot['date'] . ' ' . $slot['end'] : '' );
	} elseif ( '' === $slot_label ) {
		law_event_update_meta( $event_id, '_law_start', '' );
		law_event_update_meta( $event_id, '_law_end', '' );
	}
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
