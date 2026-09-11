<?php
/**
 * Country, accessibility and dietary, for the two dialogs where somebody is
 * registered on their behalf: "Register an attendee" on the per-event bookings
 * list (parts/events/booking-list.php) and "Add an attendee without payment" on
 * the flagship bookings dashboard (parts/events/flagship-add-attendee.php).
 *
 * The same three things the registration form asks for, in the same vocabulary
 * and with the same stored values (Denis, 11 September 2026): the bookings
 * tables and the CSV/Excel/PDF exports read those columns LIVE from the
 * attendee's profile, so a person booked in by phone arrives with three empty
 * columns unless whoever books them can pass the answers on.
 *
 * The POST names match the registration form's exactly (country,
 * accessibility[], accessibility_other, dietary[], dietary_other), so the
 * handlers can hand $_POST straight to
 * law_registration_clean_attendee_profile(). They are flat, NOT part of the
 * law_attendees[] row, because law_booking_clean_additional_rows() keeps a row
 * to the four attendee fields and drops everything else.
 *
 * Args:
 *   id_prefix  Required, unique per copy on the page: the two "Other" boxes are
 *              shown and hidden by id (event-form.js, data-law-toggles).
 *   values     Prefill, in law_profile_values() shape (the no-JS refusal path).
 *   show_other Render both "Other" boxes visible rather than waiting for the
 *              script to reveal them: true for the <noscript> copy, where
 *              nothing is ever going to reveal them.
 *   note       One line under the legend explaining who this is for.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_apf_prefix = (string) ( $args['id_prefix'] ?? 'law-attendee-profile' );
$law_apf_values = (array) ( $args['values'] ?? array() );
$law_apf_open   = ! empty( $args['show_other'] );
$law_apf_note   = (string) ( $args['note'] ?? '' );

$law_apf_country   = law_registration_country_choices();
$law_apf_country_v = (string) ( $law_apf_values['country'] ?? '' );
$law_apf_access    = array_map( 'strval', (array) ( $law_apf_values['accessibility'] ?? array() ) );
$law_apf_diet      = array_map( 'strval', (array) ( $law_apf_values['dietary'] ?? array() ) );
$law_apf_access_o  = (string) ( $law_apf_values['accessibility_other'] ?? '' );
$law_apf_diet_o    = (string) ( $law_apf_values['dietary_other'] ?? '' );

$law_apf_access_id = $law_apf_prefix . '-access-other';
$law_apf_diet_id   = $law_apf_prefix . '-diet-other';
?>

<p class="law-form-field">
	<label for="<?php echo esc_attr( $law_apf_prefix . '-country' ); ?>">
		<?php echo esc_html( ! empty( $args['country_required'] ) ? __( 'Country of residence *', 'law' ) : __( 'Country of residence', 'law' ) ); ?>
	</label>
	<?php if ( $law_apf_country ) : ?>
		<select id="<?php echo esc_attr( $law_apf_prefix . '-country' ); ?>" name="country">
			<option value=""><?php esc_html_e( 'Select country', 'law' ); ?></option>
			<?php foreach ( $law_apf_country as $law_apf_choice ) : ?>
				<option value="<?php echo esc_attr( $law_apf_choice ); ?>" <?php selected( $law_apf_country_v, $law_apf_choice ); ?>><?php echo esc_html( $law_apf_choice ); ?></option>
			<?php endforeach; ?>
		</select>
	<?php else : ?>
		<input type="text" id="<?php echo esc_attr( $law_apf_prefix . '-country' ); ?>" name="country" value="<?php echo esc_attr( $law_apf_country_v ); ?>">
	<?php endif; ?>
</p>

<fieldset class="law-attendee-profile">
	<legend><?php esc_html_e( 'Requirements', 'law' ); ?></legend>
	<?php if ( '' !== $law_apf_note ) : ?>
		<p class="law-form-hint"><?php echo esc_html( $law_apf_note ); ?></p>
	<?php endif; ?>

	<div class="law-row-grid">
	<div class="law-attendee-profile__group">
	<div class="law-form-field">
		<span class="law-form-label"><?php esc_html_e( 'Accessibility', 'law' ); ?></span>
		<div class="law-choices">
			<?php foreach ( law_registration_accessibility_choices() as $law_apf_value => $law_apf_label ) : ?>
				<label><input type="checkbox" name="accessibility[]" value="<?php echo esc_attr( $law_apf_value ); ?>"
					<?php echo 'Other' === $law_apf_value ? 'data-law-toggles="' . esc_attr( $law_apf_access_id ) . '"' : ''; ?>
					<?php checked( in_array( $law_apf_value, $law_apf_access, true ) ); ?>>
					<?php echo esc_html( $law_apf_label ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="law-form-field" id="<?php echo esc_attr( $law_apf_access_id ); ?>"
		<?php echo ( $law_apf_open || in_array( 'Other', $law_apf_access, true ) || '' !== $law_apf_access_o ) ? '' : 'hidden'; ?>>
		<label for="<?php echo esc_attr( $law_apf_access_id . '-input' ); ?>"><?php esc_html_e( 'Other: please specify *', 'law' ); ?></label>
		<input type="text" id="<?php echo esc_attr( $law_apf_access_id . '-input' ); ?>" name="accessibility_other" value="<?php echo esc_attr( $law_apf_access_o ); ?>">
	</p>
	</div>

	<div class="law-attendee-profile__group">
	<div class="law-form-field">
		<span class="law-form-label"><?php esc_html_e( 'Dietary', 'law' ); ?></span>
		<div class="law-choices">
			<?php foreach ( law_registration_dietary_choices() as $law_apf_choice ) : ?>
				<label><input type="checkbox" name="dietary[]" value="<?php echo esc_attr( $law_apf_choice ); ?>"
					<?php echo 'Other' === $law_apf_choice ? 'data-law-toggles="' . esc_attr( $law_apf_diet_id ) . '"' : ''; ?>
					<?php checked( in_array( $law_apf_choice, $law_apf_diet, true ) ); ?>>
					<?php echo esc_html( $law_apf_choice ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="law-form-field" id="<?php echo esc_attr( $law_apf_diet_id ); ?>"
		<?php echo ( $law_apf_open || in_array( 'Other', $law_apf_diet, true ) || '' !== $law_apf_diet_o ) ? '' : 'hidden'; ?>>
		<label for="<?php echo esc_attr( $law_apf_diet_id . '-input' ); ?>"><?php esc_html_e( 'Other: please specify *', 'law' ); ?></label>
		<input type="text" id="<?php echo esc_attr( $law_apf_diet_id . '-input' ); ?>" name="dietary_other" value="<?php echo esc_attr( $law_apf_diet_o ); ?>">
	</p>
	</div>
	</div>
</fieldset>
