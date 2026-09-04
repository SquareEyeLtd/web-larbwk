<?php
/**
 * Shared registration/profile fields (name, email, organisation, job title,
 * country, roles, accessibility, dietary), rendered inside the auth-hero.
 *
 * Args: values (array), errors (array of field => [messages]).
 */

$law_values = (array) ( $args['values'] ?? array() );
$law_errors = (array) ( $args['errors'] ?? array() );
// Registration mirrors form 1's required set (organisation, job title,
// country, role); the profile requires only country, like form 3.
$law_registration_mode = ! empty( $args['registration'] );

$law_pf_error = function ( $field ) use ( $law_errors ) {
	if ( isset( $law_errors[ $field ][0] ) ) {
		echo '<p class="law-form-error" role="alert">' . esc_html( $law_errors[ $field ][0] ) . '</p>';
	}
};
$law_pf_value = function ( $key, $default = '' ) use ( $law_values ) {
	return $law_values[ $key ] ?? $default;
};
$law_countries = law_registration_country_choices();
?>

<div class="law-row-grid">
	<p class="law-form-field"><label for="law-reg-first">First name *</label>
		<input type="text" id="law-reg-first" name="first_name" required autocomplete="given-name" value="<?php echo esc_attr( $law_pf_value( 'first_name' ) ); ?>"></p>
	<p class="law-form-field"><label for="law-reg-last">Last name *</label>
		<input type="text" id="law-reg-last" name="last_name" required autocomplete="family-name" value="<?php echo esc_attr( $law_pf_value( 'last_name' ) ); ?>"></p>
</div>
<?php $law_pf_error( 'name' ); ?>

<p class="law-form-field"><label for="law-reg-email">Email (this is your username) *</label>
	<input type="email" id="law-reg-email" name="email" required autocomplete="email" value="<?php echo esc_attr( $law_pf_value( 'email' ) ); ?>">
	<?php $law_pf_error( 'email' ); ?></p>

<p class="law-form-field"><label for="law-reg-org">Organisation / firm name<?php echo $law_registration_mode ? ' *' : ''; ?></label>
	<input type="text" id="law-reg-org" name="organisation" autocomplete="organization" <?php echo $law_registration_mode ? 'required' : ''; ?> value="<?php echo esc_attr( $law_pf_value( 'organisation' ) ); ?>">
	<?php $law_pf_error( 'organisation' ); ?></p>

<p class="law-form-field"><label for="law-reg-job">Job title<?php echo $law_registration_mode ? ' *' : ''; ?></label>
	<input type="text" id="law-reg-job" name="job_title" autocomplete="organization-title" <?php echo $law_registration_mode ? 'required' : ''; ?> value="<?php echo esc_attr( $law_pf_value( 'job_title' ) ); ?>">
	<?php $law_pf_error( 'job_title' ); ?></p>

<p class="law-form-field"><label for="law-reg-country">Country of residence *</label>
	<?php if ( $law_countries ) : ?>
		<select id="law-reg-country" name="country" autocomplete="country-name" required>
			<option value="">Choose…</option>
			<?php foreach ( $law_countries as $law_country ) : ?>
				<option value="<?php echo esc_attr( $law_country ); ?>" <?php selected( $law_pf_value( 'country' ), $law_country ); ?>><?php echo esc_html( $law_country ); ?></option>
			<?php endforeach; ?>
		</select>
	<?php else : ?>
		<input type="text" id="law-reg-country" name="country" autocomplete="country-name" required value="<?php echo esc_attr( $law_pf_value( 'country' ) ); ?>">
	<?php endif; ?>
	<?php $law_pf_error( 'country' ); ?></p>

<div class="law-form-field">
	<span class="law-form-label">Role<?php echo $law_registration_mode ? ' *' : ''; ?></span>
	<div class="law-choices">
		<?php
		$law_chosen_roles = (array) $law_pf_value( 'roles', array() );
		foreach ( law_registration_roles() as $law_role_slug => $law_role_label ) :
			?>
			<label><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $law_role_slug ); ?>" <?php checked( in_array( $law_role_slug, $law_chosen_roles, true ) ); ?>>
				<?php echo esc_html( $law_role_label ); ?></label>
		<?php endforeach; ?>
	</div>
	<?php $law_pf_error( 'roles' ); ?>
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
				<label><input type="checkbox" name="accessibility[]" value="<?php echo esc_attr( $law_choice_value ); ?>" <?php checked( in_array( $law_choice_value, $law_chosen_access, true ) ); ?>>
					<?php echo esc_html( $law_choice_label ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="law-form-field"><label for="law-reg-access-other">Other: please specify</label>
		<input type="text" id="law-reg-access-other" name="accessibility_other" value="<?php echo esc_attr( $law_pf_value( 'accessibility_other' ) ); ?>">
		<?php $law_pf_error( 'accessibility_other' ); ?></p>

	<div class="law-form-field">
		<span class="law-form-label">Dietary</span>
		<div class="law-choices">
			<?php
			$law_chosen_diet = (array) $law_pf_value( 'dietary', array() );
			foreach ( law_registration_dietary_choices() as $law_choice ) :
				?>
				<label><input type="checkbox" name="dietary[]" value="<?php echo esc_attr( $law_choice ); ?>" <?php checked( in_array( $law_choice, $law_chosen_diet, true ) ); ?>>
					<?php echo esc_html( $law_choice ); ?></label>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="law-form-field"><label for="law-reg-diet-other">Other: please specify</label>
		<input type="text" id="law-reg-diet-other" name="dietary_other" value="<?php echo esc_attr( $law_pf_value( 'dietary_other' ) ); ?>">
		<?php $law_pf_error( 'dietary_other' ); ?></p>
</fieldset>
