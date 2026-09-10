<?php
/**
 * The create/edit form for one discount code, for
 * templates/account-dashboard-discounts.php.
 *
 * Args: discount_id (int, 0 to create).
 *
 * Re-checks the committee gate for itself rather than trusting its caller, as
 * parts/events/flagship-manage.php and thread.php do: the part is reachable
 * on its own the moment anything else calls get_template_part().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'law_user_is_committee' ) || ! law_user_is_committee() ) {
	echo '<p>' . esc_html__( 'This dashboard is for the LAW committee.', 'law' ) . '</p>';
	return;
}

$law_dm_id   = (int) ( $args['discount_id'] ?? 0 );
$law_dm_data = $law_dm_id ? law_discount_data( $law_dm_id ) : null;

if ( $law_dm_id && ! $law_dm_data ) {
	echo '<p class="law-form-notice is-error" role="alert">' . esc_html__( 'That discount code could not be found.', 'law' ) . '</p>';
	return;
}

// A refused save comes back through a one-shot transient rather than a
// redirect, so nothing typed is lost. It wins over the stored values.
$law_dm_state  = law_discounts_dashboard_state();
$law_dm_errors = $law_dm_state['errors'];
$law_dm_input  = $law_dm_state['input'];

$law_dm_values = array(
	'code'     => $law_dm_data['code'] ?? '',
	'type'     => $law_dm_data['type'] ?? 'percent',
	// Percent is a plain number; a fixed amount is shown in pounds, because
	// that is what the committee thinks in and types.
	'value'    => '',
	'starts'   => $law_dm_data['starts'] ?? '',
	'expires'  => $law_dm_data['expires'] ?? '',
	'max_uses' => $law_dm_data['max_uses'] ?? 0,
	'events'   => $law_dm_data['events'] ?? array(),
	'note'     => $law_dm_data['note'] ?? '',
	'active'   => $law_dm_data ? $law_dm_data['active'] : true,
);
/**
 * A stored datetime, in the form <input type="datetime-local"> insists on.
 *
 * Meta keeps "2026-09-01 00:00"; the control accepts only
 * "2026-09-01T00:00" and silently renders EMPTY for anything else, which
 * would look like the saved date had been lost. A repopulated form after a
 * validation error already carries the T form, so both are accepted.
 */
$law_dm_local = static function ( $stamp ) {
	$stamp = trim( (string) $stamp );
	if ( '' === $stamp ) {
		return '';
	}
	$ts = strtotime( $stamp );

	return $ts ? gmdate( 'Y-m-d\TH:i', $ts ) : '';
};

if ( $law_dm_data ) {
	$law_dm_values['value'] = 'percent' === $law_dm_data['type']
		? (string) $law_dm_data['value']
		: number_format( $law_dm_data['value'] / 100, 2, '.', '' );
}
if ( $law_dm_input ) {
	$law_dm_values = array_merge( $law_dm_values, array_intersect_key( $law_dm_input, $law_dm_values ) );
}

$law_dm_scope = law_discount_scope_events();
$law_dm_used  = (int) ( $law_dm_data['used'] ?? 0 );
?>

<?php
get_template_part(
	'parts/layout/back-link',
	null,
	array(
		'url'   => law_discounts_dashboard_url(),
		'label' => __( 'Back to discount codes', 'law' ),
	)
);
?>

<h1 class="law-dashboard__title">
	<?php
	echo $law_dm_id
		? esc_html( sprintf( __( 'Discount code %s', 'law' ), $law_dm_values['code'] ) )
		: esc_html__( 'Add a discount code', 'law' );
	?>
</h1>

<?php if ( $law_dm_errors ) : ?>
	<div class="law-form-notice is-error" role="alert">
		<p><?php esc_html_e( 'The discount code was not saved:', 'law' ); ?></p>
		<ul>
			<?php foreach ( $law_dm_errors as $law_dm_error ) : ?>
				<li><?php echo esc_html( $law_dm_error ); ?></li>
			<?php endforeach; ?>
		</ul>
	</div>
<?php endif; ?>

<?php if ( $law_dm_used > 0 ) : ?>
	<p class="law-form-notice" role="status">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: number of times used. */
				_n(
					'This code has been used %s time. Changing its value does not change what anyone has already been charged.',
					'This code has been used %s times. Changing its value does not change what anyone has already been charged.',
					$law_dm_used,
					'law'
				),
				number_format_i18n( $law_dm_used )
			)
		);
		?>
	</p>
<?php endif; ?>

<div class="grid-x grid-padding-x">
	<div class="large-12 cell">
		<form class="law-event-form law-event-form--light law-booking-form law-discounts-form" method="post"
			action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-law-booking-rows>
			<input type="hidden" name="action" value="law_discount_manage">
			<input type="hidden" name="law_discount[id]" value="<?php echo esc_attr( (string) $law_dm_id ); ?>">
			<?php wp_nonce_field( 'law_discount_manage' ); ?>
			<?php law_events_honeypot_field(); ?>

			<fieldset>
				<legend><?php esc_html_e( 'The code', 'law' ); ?></legend>

				<p class="law-form-field">
					<label>
						<input type="checkbox" name="law_discount[active]" value="1"
							<?php checked( ! empty( $law_dm_values['active'] ) ); ?>>
						<?php esc_html_e( 'Active', 'law' ); ?>
					</label>
					<span class="law-form-hint"><?php esc_html_e( 'Untick to stop the code being accepted. Bookings that already used it are unaffected.', 'law' ); ?></span>
				</p>

				<p class="law-form-field">
					<label for="law-dm-code"><?php esc_html_e( 'Code *', 'law' ); ?></label>
					<input type="text" id="law-dm-code" name="law_discount[code]" data-law-field="code"
						value="<?php echo esc_attr( (string) $law_dm_values['code'] ); ?>"
						autocapitalize="characters" autocomplete="off" aria-required="true">
					<span class="law-form-hint"><?php esc_html_e( 'Letters, numbers and hyphens. It can be typed in any case, with or without spaces.', 'law' ); ?></span>
				</p>

				<div class="law-row-grid">
					<p class="law-form-field">
						<label for="law-dm-type"><?php esc_html_e( 'Type', 'law' ); ?></label>
						<select id="law-dm-type" name="law_discount[type]">
							<?php foreach ( law_discount_types() as $law_dm_key => $law_dm_label ) : ?>
								<option value="<?php echo esc_attr( $law_dm_key ); ?>" <?php selected( $law_dm_values['type'], $law_dm_key ); ?>>
									<?php echo esc_html( $law_dm_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
					</p>

					<p class="law-form-field">
						<label for="law-dm-value"><?php esc_html_e( 'Amount *', 'law' ); ?></label>
						<?php
						// step 0.01, not 1: a percentage is whole but a fixed
						// discount is pounds and pence. min 0 stops the
						// spinner going negative, which would be a surcharge.
						?>
						<input type="number" min="0" step="0.01" id="law-dm-value" name="law_discount[value]" data-law-field="value"
							value="<?php echo esc_attr( (string) $law_dm_values['value'] ); ?>" aria-required="true">
						<span class="law-form-hint"><?php esc_html_e( 'A percentage (1 to 100), or an amount in pounds for a fixed discount.', 'law' ); ?></span>
					</p>
				</div>
			</fieldset>

			<fieldset>
				<legend><?php esc_html_e( 'When and where it works', 'law' ); ?></legend>

				<div class="law-row-grid">
					<p class="law-form-field">
						<label for="law-dm-starts"><?php esc_html_e( 'Valid from', 'law' ); ?></label>
						<input type="datetime-local" id="law-dm-starts" name="law_discount[starts]"
							value="<?php echo esc_attr( $law_dm_local( $law_dm_values['starts'] ) ); ?>">
					</p>

					<p class="law-form-field">
						<label for="law-dm-expires"><?php esc_html_e( 'Valid until', 'law' ); ?></label>
						<input type="datetime-local" id="law-dm-expires" name="law_discount[expires]"
							value="<?php echo esc_attr( $law_dm_local( $law_dm_values['expires'] ) ); ?>">
					</p>
				</div>
				<p class="law-form-hint"><?php esc_html_e( 'UK time. Leave both empty for a code with no time limit.', 'law' ); ?></p>

				<p class="law-form-field">
					<label for="law-dm-max"><?php esc_html_e( 'Usage limit', 'law' ); ?></label>
					<input type="number" id="law-dm-max" name="law_discount[max_uses]" min="0" step="1"
						value="<?php echo esc_attr( (string) (int) $law_dm_values['max_uses'] ); ?>">
					<span class="law-form-hint"><?php esc_html_e( 'How many times the code can be used in total. 0 means no limit.', 'law' ); ?></span>
				</p>

				<?php if ( $law_dm_scope ) : ?>
					<div class="law-form-field">
						<span class="law-form-label"><?php esc_html_e( 'Applies to', 'law' ); ?></span>
						<?php foreach ( $law_dm_scope as $law_dm_event_id => $law_dm_event_label ) : ?>
							<label>
								<input type="checkbox" name="law_discount[events][]"
									value="<?php echo esc_attr( (string) $law_dm_event_id ); ?>"
									<?php checked( in_array( (int) $law_dm_event_id, array_map( 'intval', (array) $law_dm_values['events'] ), true ) ); ?>>
								<?php echo esc_html( $law_dm_event_label ); ?>
							</label>
						<?php endforeach; ?>
						<span class="law-form-hint"><?php esc_html_e( 'Tick nothing to let the code work against any booking that charges.', 'law' ); ?></span>
					</div>
				<?php else : ?>
					<p class="law-form-hint">
						<?php esc_html_e( 'There is nothing priced to limit this code to yet, so it will work against any booking that starts charging.', 'law' ); ?>
					</p>
				<?php endif; ?>

				<p class="law-form-field">
					<label for="law-dm-note"><?php esc_html_e( 'Note', 'law' ); ?></label>
					<input type="text" id="law-dm-note" name="law_discount[note]"
						value="<?php echo esc_attr( (string) $law_dm_values['note'] ); ?>">
					<span class="law-form-hint"><?php esc_html_e( 'For your own records: who the code is for, or why it exists. It is never shown to anyone else.', 'law' ); ?></span>
				</p>
			</fieldset>

			<p class="law-form-buttons">
				<button type="submit" class="button orange" data-law-busy="<?php esc_attr_e( 'Saving…', 'law' ); ?>">
					<?php echo $law_dm_id ? esc_html__( 'Save changes', 'law' ) : esc_html__( 'Create the code', 'law' ); ?>
				</button>
				<a class="button second" href="<?php echo esc_url( law_discounts_dashboard_url() ); ?>"><?php esc_html_e( 'Cancel', 'law' ); ?></a>
			</p>
		</form>
	</div>
</div>
