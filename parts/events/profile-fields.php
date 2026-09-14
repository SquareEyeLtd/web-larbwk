<?php
/**
 * Shared registration/profile fields (name, email, organisation, job title,
 * country, accessibility, dietary), rendered inside the auth-hero.
 *
 * Args: values (array), errors (array of field => [messages]), registration
 * (bool).
 *
 * The Role checkboxes went on 14 September 2026, and with them the locked_role
 * arg the booking modal used to pass: there are no self-service roles left to
 * choose or to lock. The optional "I plan to host an event" tick that briefly
 * replaced them came off the same day (Denis): neither form asks anything
 * about what somebody intends to do, because nothing about the site depends on
 * the answer any more.
 *
 * The law_intent storage behind that tick is intact and still holds what
 * migration step 11 read out of the old roles; see
 * functions/events/registration.php. Nothing writes it while no form offers
 * it, and a save through these fields deliberately leaves it alone.
 */

$law_values = (array) ( $args['values'] ?? array() );
$law_errors = (array) ( $args['errors'] ?? array() );
// Registration mirrors form 1 (User registration)'s required set (organisation,
// job title, country); the profile requires only country, like form 3 (User
// profile). The intent ticks are optional on both.
$law_registration_mode = ! empty( $args['registration'] );

$law_pf_error = function ( $field ) use ( $law_errors ) {
	if ( isset( $law_errors[ $field ][0] ) ) {
		// A <span> (not <p>): see the same note in account-event-form.php — a
		// nested <p> is auto-closed and would orphan the message from its field.
		echo '<span class="law-form-error" role="alert">' . esc_html( $law_errors[ $field ][0] ) . '</span>';
	}
};
$law_pf_value = function ( $key, $default = '' ) use ( $law_values ) {
	return $law_values[ $key ] ?? $default;
};
// Marks the whole field so the input itself is visibly highlighted on error.
$law_pf_class = function ( $field ) use ( $law_errors ) {
	return isset( $law_errors[ $field ][0] ) ? ' is-invalid' : '';
};
$law_countries = law_registration_country_choices();
?>

<div class="law-row-grid law-row-grid--three">
	<p class="law-form-field<?php echo esc_attr( $law_pf_class( 'name' ) ); ?>"><label for="law-reg-first">First name *</label>
		<input type="text" id="law-reg-first" name="first_name" required autocomplete="given-name" value="<?php echo esc_attr( $law_pf_value( 'first_name' ) ); ?>"></p>
	<p class="law-form-field<?php echo esc_attr( $law_pf_class( 'name' ) ); ?>"><label for="law-reg-last">Last name *</label>
		<input type="text" id="law-reg-last" name="last_name" required autocomplete="family-name" value="<?php echo esc_attr( $law_pf_value( 'last_name' ) ); ?>"></p>
	<p class="law-form-field<?php echo esc_attr( $law_pf_class( 'email' ) ); ?>"><label for="law-reg-email">Email (this is your username) *</label>
		<input type="email" id="law-reg-email" name="email" required autocomplete="email" value="<?php echo esc_attr( $law_pf_value( 'email' ) ); ?>">
		<?php $law_pf_error( 'email' ); ?></p>
</div>
<?php $law_pf_error( 'name' ); ?>

<div class="law-row-grid law-row-grid--three">
	<p class="law-form-field<?php echo esc_attr( $law_pf_class( 'organisation' ) ); ?>"><label for="law-reg-org">Organisation / firm name<?php echo $law_registration_mode ? ' *' : ''; ?></label>
		<input type="text" id="law-reg-org" name="organisation" autocomplete="organization" <?php echo $law_registration_mode ? 'required' : ''; ?> value="<?php echo esc_attr( $law_pf_value( 'organisation' ) ); ?>">
		<?php $law_pf_error( 'organisation' ); ?></p>

	<p class="law-form-field<?php echo esc_attr( $law_pf_class( 'job_title' ) ); ?>"><label for="law-reg-job">Job title<?php echo $law_registration_mode ? ' *' : ''; ?></label>
		<input type="text" id="law-reg-job" name="job_title" autocomplete="organization-title" <?php echo $law_registration_mode ? 'required' : ''; ?> value="<?php echo esc_attr( $law_pf_value( 'job_title' ) ); ?>">
		<?php $law_pf_error( 'job_title' ); ?></p>

	<p class="law-form-field<?php echo esc_attr( $law_pf_class( 'country' ) ); ?>"><label for="law-reg-country">Country of residence *</label>
		<?php if ( $law_countries ) : ?>
			<select id="law-reg-country" name="country" autocomplete="country-name" required>
				<option value="">Select country</option>
				<?php foreach ( $law_countries as $law_country ) : ?>
					<option value="<?php echo esc_attr( $law_country ); ?>" <?php selected( $law_pf_value( 'country' ), $law_country ); ?>><?php echo esc_html( $law_country ); ?></option>
				<?php endforeach; ?>
			</select>
		<?php else : ?>
			<input type="text" id="law-reg-country" name="country" autocomplete="country-name" required value="<?php echo esc_attr( $law_pf_value( 'country' ) ); ?>">
		<?php endif; ?>
		<?php $law_pf_error( 'country' ); ?></p>
</div>

<fieldset>
	<legend>Requirements</legend>
	<div class="law-form-field">
		<span class="law-form-label">Accessibility</span>
		<div class="law-choices">
			<?php
			$law_chosen_access = (array) $law_pf_value( 'accessibility', array() );
			foreach ( law_registration_accessibility_choices() as $law_choice_value => $law_choice_label ) :
				?>
				<label><input type="checkbox" name="accessibility[]" value="<?php echo esc_attr( $law_choice_value ); ?>"
					<?php echo 'Other' === $law_choice_value ? 'data-law-toggles="law-access-other-field"' : ''; ?>
					<?php checked( in_array( $law_choice_value, $law_chosen_access, true ) ); ?>>
					<?php echo esc_html( $law_choice_label ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="law-form-field<?php echo esc_attr( $law_pf_class( 'accessibility_other' ) ); ?>" id="law-access-other-field"
		<?php echo ( in_array( 'Other', $law_chosen_access, true ) || '' !== (string) $law_pf_value( 'accessibility_other' ) ) ? '' : 'hidden'; ?>>
		<label for="law-reg-access-other">Other: please specify *</label>
		<input type="text" id="law-reg-access-other" name="accessibility_other" value="<?php echo esc_attr( $law_pf_value( 'accessibility_other' ) ); ?>">
		<?php $law_pf_error( 'accessibility_other' ); ?></p>

	<div class="law-form-field">
		<span class="law-form-label">Dietary</span>
		<div class="law-choices">
			<?php
			$law_chosen_diet = (array) $law_pf_value( 'dietary', array() );
			foreach ( law_registration_dietary_choices() as $law_choice ) :
				?>
				<label><input type="checkbox" name="dietary[]" value="<?php echo esc_attr( $law_choice ); ?>"
					<?php echo 'Other' === $law_choice ? 'data-law-toggles="law-diet-other-field"' : ''; ?>
					<?php checked( in_array( $law_choice, $law_chosen_diet, true ) ); ?>>
					<?php echo esc_html( $law_choice ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="law-form-field<?php echo esc_attr( $law_pf_class( 'dietary_other' ) ); ?>" id="law-diet-other-field"
		<?php echo ( in_array( 'Other', $law_chosen_diet, true ) || '' !== (string) $law_pf_value( 'dietary_other' ) ) ? '' : 'hidden'; ?>>
		<label for="law-reg-diet-other">Other: please specify *</label>
		<input type="text" id="law-reg-diet-other" name="dietary_other" value="<?php echo esc_attr( $law_pf_value( 'dietary_other' ) ); ?>">
		<?php $law_pf_error( 'dietary_other' ); ?></p>
</fieldset>
