<?php
/**
 * Phase D: custom registration and profile forms, replacing form 1 (User
 * registration) and form 3 (User profile) plus their User Registration
 * feeds, the GW Auto Login step and the mu-plugin checkbox sync
 * (EVENTS_4.1_REBUILD.md §7 phase D).
 *
 * Carried behaviours: username = email, display name "First Last", the role
 * checkboxes limited to the three self-service roles, user meta keys
 * organisation / job_title / country / accessibility_other / dietary_other,
 * the ACF fields law_role / accessibility / dietary, the HubSpot contact
 * type tags, auto-login with the /account/?action=registered redirect, and
 * the active "Email to admins > user registration" notification.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Self-service roles (mirrors law_self_service_roles()): slug => label. */
function law_registration_roles() {
	return array(
		'sponsor'    => 'LAW sponsor',
		'event_host' => 'Event host',
		'attendee'   => 'Event attendee',
	);
}

/**
 * Accessibility choices (form 1 field 17 / form 3 field 13): stored VALUE =>
 * displayed label. The short values are the canonical stored format — the GF
 * fields, ACF field 435 (Accessibility) and every existing user's meta all
 * use them; storing labels would orphan existing selections.
 */
function law_registration_accessibility_choices() {
	return array(
		'Captions'      => 'I require captions',
		'Sign language' => 'I require a sign language interpreter',
		'Animal / PCA'  => 'I will be accompanied by a service animal or Personal Care Assistant',
		'Wheelchair'    => 'I require wheelchair access',
		'Other'         => 'Other',
	);
}

/** Dietary choices (form 1 field 19 / form 3 field 15). */
function law_registration_dietary_choices() {
	return array(
		'Vegetarian', 'Vegan', 'Pescatarian', 'Gluten free', 'Dairy free',
		'Coeliac', 'Lactose free', 'Nut allergy', 'Other',
	);
}

/**
 * Country choices: read from form 1 field 10 (Country of residence) while
 * Gravity Forms core is installed (it stays for form 7, Contact), falling
 * back to a free-text input when unavailable.
 *
 * @return string[] Empty array = render a text input instead.
 */
function law_registration_country_choices() {
	static $choices = null;
	if ( null !== $choices ) {
		return $choices;
	}
	$choices = array();
	if ( class_exists( 'GFAPI' ) ) {
		$form = GFAPI::get_form( 1 );
		foreach ( (array) ( $form['fields'] ?? array() ) as $field ) {
			if ( 10 === (int) $field->id && is_array( $field->choices ) ) {
				foreach ( $field->choices as $choice ) {
					$label = trim( html_entity_decode( (string) $choice['text'], ENT_QUOTES ) );
					if ( '' !== $label ) {
						$choices[] = $label;
					}
				}
			}
		}
	}
	return $choices;
}

/**
 * Country validation: when the select rendered its choice list, only a listed
 * country is accepted (matching the old GF select); with no list available
 * (GF gone) any non-empty text passes.
 *
 * @param string $country  Submitted value.
 * @param bool   $required Whether an empty value is an error.
 * @return string Error message, or '' when valid.
 */
function law_registration_validate_country( $country, $required ) {
	$country = trim( $country );
	if ( '' === $country ) {
		return $required ? 'Please choose your country of residence.' : '';
	}
	$choices = law_registration_country_choices();
	if ( $choices && ! in_array( $country, $choices, true ) ) {
		return 'Please choose a country from the list.';
	}
	return '';
}

/** The HubSpot contact type tags (mirrors functions/hubspot.php). */
function law_registration_hubspot_tags( array $roles ) {
	$year = (int) law_events_setting( 'year', (int) gmdate( 'Y' ) );
	$tags = array( $year . ' Registered user' );
	if ( in_array( 'sponsor', $roles, true ) ) {
		$tags[] = $year . ' Sponsor';
	}
	if ( in_array( 'event_host', $roles, true ) ) {
		$tags[] = $year . ' Event Host';
	}
	return implode( ';', $tags );
}

/** Shared profile-field writes for register and profile saves. */
function law_registration_write_profile_meta( $user_id, array $input ) {
	$meta = array(
		'organisation'        => sanitize_text_field( (string) ( $input['organisation'] ?? '' ) ),
		'job_title'           => sanitize_text_field( (string) ( $input['job_title'] ?? '' ) ),
		'country'             => sanitize_text_field( (string) ( $input['country'] ?? '' ) ),
		'accessibility_other' => sanitize_text_field( (string) ( $input['accessibility_other'] ?? '' ) ),
		'dietary_other'       => sanitize_text_field( (string) ( $input['dietary_other'] ?? '' ) ),
	);
	foreach ( $meta as $key => $value ) {
		update_user_meta( $user_id, $key, $value );
	}

	$roles         = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['roles'] ?? array() ) ), array_keys( law_registration_roles() ) ) );
	$accessibility = array_values( array_intersect( array_map( 'sanitize_text_field', (array) ( $input['accessibility'] ?? array() ) ), array_keys( law_registration_accessibility_choices() ) ) );
	$dietary       = array_values( array_intersect( array_map( 'sanitize_text_field', (array) ( $input['dietary'] ?? array() ) ), law_registration_dietary_choices() ) );

	// The ACF user fields the old mu-plugin synced (law_role, accessibility,
	// dietary); update_field keeps ACF's storage format.
	if ( function_exists( 'update_field' ) ) {
		update_field( 'law_role', $roles, 'user_' . $user_id );
		update_field( 'accessibility', $accessibility, 'user_' . $user_id );
		update_field( 'dietary', $dietary, 'user_' . $user_id );
	}

	return $roles;
}

/**
 * Apply self-service roles: selected ones added, unselected self-service
 * ones removed; administrator/events_committee and everything else are never
 * touched; a user can never end up role-less (attendee is the floor).
 */
function law_registration_sync_roles( $user_id, array $selected ) {
	$user = new WP_User( $user_id );
	$self = array_keys( law_registration_roles() );
	foreach ( $self as $role ) {
		if ( in_array( $role, $selected, true ) ) {
			$user->add_role( $role );
		} else {
			$user->remove_role( $role );
		}
	}
	if ( empty( $user->roles ) ) {
		$user->add_role( 'attendee' );
	}
}

/* Registration ______________________________________________________________ */

add_action( 'admin_post_nopriv_law_register', 'law_registration_handler' );
add_action( 'admin_post_law_register', function () {
	wp_safe_redirect( home_url( '/account/' ) ); // Already signed in.
	exit;
} );

function law_registration_handler() {
	check_admin_referer( 'law_register' );

	// Honeypot: pretend success.
	if ( '' !== trim( (string) ( $_POST['law_website_url'] ?? '' ) ) ) {
		wp_safe_redirect( home_url( '/account/?action=registered' ) );
		exit;
	}
	// Anonymous surface: per-IP limit. 20/hour absorbs a law-firm office or
	// conference venue behind one NAT while still capping scripted abuse
	// (which the nonce and honeypot already blunt).
	if ( ! law_events_rate_limit_ok( 'register', 0, 20, HOUR_IN_SECONDS ) ) {
		// 429 (not the wp_die default 500) with a title and a way back, so a
		// shared-office/NAT user who hits the cap isn't left on a bare error.
		wp_die(
			esc_html__( 'Too many registration attempts from this connection. Please wait a little while and try again.', 'law' ),
			esc_html__( 'Please try again shortly', 'law' ),
			array( 'response' => 429, 'back_link' => true )
		);
	}

	$input  = wp_unslash( $_POST );
	$errors = new WP_Error();

	$first    = sanitize_text_field( (string) ( $input['first_name'] ?? '' ) );
	$last     = sanitize_text_field( (string) ( $input['last_name'] ?? '' ) );
	$email    = sanitize_email( (string) ( $input['email'] ?? '' ) );
	$password = (string) ( $input['password'] ?? '' );
	$confirm  = (string) ( $input['password_confirm'] ?? '' );

	if ( '' === $first || '' === $last ) {
		$errors->add( 'name', 'Please give your first and last name.' );
	}
	if ( ! is_email( $email ) ) {
		$errors->add( 'email', 'Please give a valid email address.' );
	} elseif ( email_exists( $email ) || username_exists( $email ) ) {
		$errors->add( 'email', 'An account already exists for this email address. You can sign in, or reset your password from the login page.' );
	}
	if ( strlen( $password ) < 10 ) {
		$errors->add( 'password', 'Please choose a password of at least 10 characters.' );
	} elseif ( $password !== $confirm ) {
		$errors->add( 'password_confirm', 'The two passwords do not match.' );
	}

	// Required-field parity with form 1 (organisation, job title, country and
	// role were all required there) plus the country whitelist.
	if ( '' === trim( (string) ( $input['organisation'] ?? '' ) ) ) {
		$errors->add( 'organisation', 'Please give your organisation or firm name.' );
	}
	if ( '' === trim( (string) ( $input['job_title'] ?? '' ) ) ) {
		$errors->add( 'job_title', 'Please give your job title.' );
	}
	$country_error = law_registration_validate_country( (string) ( $input['country'] ?? '' ), true );
	if ( $country_error ) {
		$errors->add( 'country', $country_error );
	}
	if ( ! array_intersect( array_map( 'sanitize_key', (array) ( $input['roles'] ?? array() ) ), array_keys( law_registration_roles() ) ) ) {
		$errors->add( 'roles', 'Please choose at least one role.' );
	}

	if ( $errors->has_errors() ) {
		$safe_input = law_events_form_reusable_input( $input );
		unset( $safe_input['password'], $safe_input['password_confirm'] );
		law_registration_store_state( array( 'errors' => $errors->errors, 'input' => $safe_input ) );
		wp_safe_redirect( add_query_arg( 'law_form_error', 1, home_url( '/register/' ) ) );
		exit;
	}

	$roles   = array_values( array_intersect( array_map( 'sanitize_key', (array) ( $input['roles'] ?? array() ) ), array_keys( law_registration_roles() ) ) );
	$user_id = wp_insert_user(
		array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first,
			'last_name'    => $last,
			'nickname'     => $first,
			'display_name' => trim( $first . ' ' . $last ),
			'role'         => $roles ? $roles[0] : 'attendee',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		$safe_input = law_events_form_reusable_input( $input );
		unset( $safe_input['password'], $safe_input['password_confirm'] );
		law_registration_store_state( array( 'errors' => array( 'email' => array( $user_id->get_error_message() ) ), 'input' => $safe_input ) );
		wp_safe_redirect( add_query_arg( 'law_form_error', 1, home_url( '/register/' ) ) );
		exit;
	}

	foreach ( array_slice( $roles, 1 ) as $extra_role ) {
		( new WP_User( $user_id ) )->add_role( $extra_role );
	}
	$stored_roles = law_registration_write_profile_meta( $user_id, $input );
	update_user_meta( $user_id, 'law_hubspot_contact_type', law_registration_hubspot_tags( $stored_roles ) );

	// The active admins notification (and the inactive Square Eye one).
	$user = get_user_by( 'id', $user_id );
	$placeholders = array(
		'user_name'  => $user->display_name,
		'user_email' => $user->user_email,
		'user_roles' => implode( ', ', array_map( fn( $r ) => law_registration_roles()[ $r ] ?? $r, $stored_roles ?: array( 'attendee' ) ) ),
	);
	law_events_send( 'admins_user_registered', 0, array( 'placeholders' => $placeholders ) );
	law_events_send( 'squareeye_user_registered', 0, array( 'placeholders' => $placeholders ) );

	// Auto-login (replacing GW Auto Login) + the form 1 confirmation redirect.
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );
	do_action( 'wp_login', $user->user_login, $user );

	wp_safe_redirect( home_url( '/account/?action=registered' ) );
	exit;
}

/**
 * One-shot error/input state for the (anonymous) registration form, keyed by
 * a random per-visitor cookie rather than the IP: visitors behind a shared
 * IP (office NAT, VPN, CGNAT) must never read each other's submitted PII.
 */
function law_registration_store_state( array $state ) {
	$token = wp_generate_password( 20, false );
	setcookie( 'law_reg_state', $token, time() + 10 * MINUTE_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	set_transient( 'law_register_state_' . $token, $state, 10 * MINUTE_IN_SECONDS );
}

function law_registration_state() {
	$token = preg_replace( '/[^A-Za-z0-9]/', '', (string) ( $_COOKIE['law_reg_state'] ?? '' ) );
	if ( '' === $token ) {
		return array( 'errors' => array(), 'input' => array() );
	}
	$key   = 'law_register_state_' . $token;
	$state = get_transient( $key );
	if ( $state ) {
		delete_transient( $key );
	}
	return is_array( $state ) ? $state : array( 'errors' => array(), 'input' => array() );
}

/* Profile ___________________________________________________________________ */

add_action( 'admin_post_law_profile', 'law_profile_handler' );
add_action( 'admin_post_nopriv_law_profile', function () {
	wp_safe_redirect( wp_login_url( home_url( '/account/profile/' ) ) );
	exit;
} );

function law_profile_handler() {
	check_admin_referer( 'law_profile' );

	$user_id = get_current_user_id();
	$user    = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		wp_die( 'Please sign in to edit your profile.' );
	}
	if ( '' !== trim( (string) ( $_POST['law_website_url'] ?? '' ) ) ) {
		wp_safe_redirect( home_url( '/account/profile/' ) );
		exit;
	}
	if ( ! law_events_rate_limit_ok( 'profile', $user_id, 10, 600 ) ) {
		wp_die(
			esc_html__( 'Too many profile updates in a short time. Please wait a few minutes.', 'law' ),
			esc_html__( 'Please try again shortly', 'law' ),
			array( 'response' => 429, 'back_link' => true )
		);
	}

	$input  = wp_unslash( $_POST );
	$errors = new WP_Error();

	$first = sanitize_text_field( (string) ( $input['first_name'] ?? '' ) );
	$last  = sanitize_text_field( (string) ( $input['last_name'] ?? '' ) );
	$email = sanitize_email( (string) ( $input['email'] ?? '' ) );

	if ( '' === $first || '' === $last ) {
		$errors->add( 'name', 'Please give your first and last name.' );
	}
	$email_changing = strtolower( $email ) !== strtolower( $user->user_email );
	if ( ! is_email( $email ) ) {
		$errors->add( 'email', 'Please give a valid email address.' );
	} elseif ( $email_changing ) {
		$existing = email_exists( $email );
		if ( $existing && (int) $existing !== $user_id ) {
			$errors->add( 'email', 'That email address belongs to another account.' );
		}
		// Changing the account email is an account-takeover lever from a
		// hijacked session, so it re-authenticates like a password change.
		if ( ! wp_check_password( (string) ( $input['current_password'] ?? '' ), $user->user_pass, $user_id ) ) {
			$errors->add( 'current_password', 'Changing your email address requires your current password.' );
		}
	}

	// Country stays required (and list-checked), as on form 3.
	$country_error = law_registration_validate_country( (string) ( $input['country'] ?? '' ), true );
	if ( $country_error ) {
		$errors->add( 'country', $country_error );
	}

	// Form 3 parity: "Other: please specify" is required when Other is ticked.
	if ( in_array( 'Other', (array) ( $input['accessibility'] ?? array() ), true ) && '' === trim( (string) ( $input['accessibility_other'] ?? '' ) ) ) {
		$errors->add( 'accessibility_other', 'Please specify your other accessibility requirement.' );
	}
	if ( in_array( 'Other', (array) ( $input['dietary'] ?? array() ), true ) && '' === trim( (string) ( $input['dietary_other'] ?? '' ) ) ) {
		$errors->add( 'dietary_other', 'Please specify your other dietary requirement.' );
	}

	$change_password = ! empty( $input['change_password'] );
	$new_password    = (string) ( $input['password'] ?? '' );
	if ( $change_password ) {
		// Changing the password requires knowing the current one.
		if ( ! wp_check_password( (string) ( $input['current_password'] ?? '' ), $user->user_pass, $user_id ) ) {
			$errors->add( 'current_password', 'Your current password is not correct.' );
		}
		if ( strlen( $new_password ) < 10 ) {
			$errors->add( 'password', 'Please choose a new password of at least 10 characters.' );
		} elseif ( $new_password !== (string) ( $input['password_confirm'] ?? '' ) ) {
			$errors->add( 'password_confirm', 'The two passwords do not match.' );
		}
	}

	if ( $errors->has_errors() ) {
		// Keep the typed values (never the passwords) so a failed save does
		// not throw away in-progress edits or collapse the password section.
		// Checkbox groups normalise to arrays: a fully CLEARED group is
		// absent from POST, and without the key the template would fall back
		// to the stored values, silently re-ticking what the user cleared.
		$safe_input = law_events_form_reusable_input( $input );
		unset( $safe_input['password'], $safe_input['password_confirm'], $safe_input['current_password'] );
		foreach ( array( 'roles', 'accessibility', 'dietary' ) as $group ) {
			$safe_input[ $group ] = (array) ( $input[ $group ] ?? array() );
		}
		set_transient( 'law_profile_state_' . $user_id, array( 'errors' => $errors->errors, 'input' => $safe_input ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'law_form_error', 1, home_url( '/account/profile/' ) ) );
		exit;
	}

	$update = array(
		'ID'           => $user_id,
		'first_name'   => $first,
		'last_name'    => $last,
		'nickname'     => $first,
		'display_name' => trim( $first . ' ' . $last ),
		'user_email'   => $email,
	);
	if ( $change_password ) {
		$update['user_pass'] = $new_password;
	}
	// Core sends its own email-change notice INSIDE wp_update_user, so the
	// suppression must be in place BEFORE the call; the module's more
	// informative notice below replaces it.
	if ( $email_changing ) {
		add_filter( 'send_email_change_email', '__return_false' );
	}
	$result = wp_update_user( $update );
	if ( is_wp_error( $result ) ) {
		$safe_input = law_events_form_reusable_input( $input );
		unset( $safe_input['password'], $safe_input['password_confirm'], $safe_input['current_password'] );
		foreach ( array( 'roles', 'accessibility', 'dietary' ) as $group ) {
			$safe_input[ $group ] = (array) ( $input[ $group ] ?? array() );
		}
		set_transient( 'law_profile_state_' . $user_id, array( 'errors' => array( 'email' => array( $result->get_error_message() ) ), 'input' => $safe_input ), 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( add_query_arg( 'law_form_error', 1, home_url( '/account/profile/' ) ) );
		exit;
	}

	if ( $email_changing ) {
		wp_mail(
			$user->user_email,
			'Your London Arbitration Week account email has changed',
			sprintf(
				"The email address on your account was changed to %s just now.\n\nIf this was not you, contact the LAW team immediately and reset your password from %s",
				$email,
				home_url( '/login/?action=forgot' )
			)
		);
	}

	$roles = law_registration_write_profile_meta( $user_id, $input );
	law_registration_sync_roles( $user_id, $roles );
	update_user_meta( $user_id, 'law_hubspot_contact_type', law_registration_hubspot_tags( $roles ) );

	// A password change logs other sessions out; keep this one alive.
	if ( $change_password ) {
		wp_set_auth_cookie( $user_id, true );
	}

	wp_safe_redirect( add_query_arg( 'law_notice', 'profile-saved', home_url( '/account/profile/' ) ) );
	exit;
}

/** One-shot error/input state for the profile form. */
function law_profile_state() {
	$key   = 'law_profile_state_' . get_current_user_id();
	$state = get_transient( $key );
	if ( $state ) {
		delete_transient( $key );
	}
	return is_array( $state ) ? $state : array( 'errors' => array(), 'input' => array() );
}

/** Current profile values for the form. */
function law_profile_values( $user_id ) {
	$user = get_user_by( 'id', $user_id );
	if ( ! $user ) {
		return array();
	}
	$acf = function_exists( 'get_field' );
	return array(
		'first_name'          => $user->first_name,
		'last_name'           => $user->last_name,
		'email'               => $user->user_email,
		'organisation'        => (string) get_user_meta( $user_id, 'organisation', true ),
		'job_title'           => (string) get_user_meta( $user_id, 'job_title', true ),
		'country'             => (string) get_user_meta( $user_id, 'country', true ),
		'roles'               => array_values( array_intersect( (array) $user->roles, array_keys( law_registration_roles() ) ) ),
		'accessibility'       => $acf ? (array) get_field( 'accessibility', 'user_' . $user_id ) : array(),
		'dietary'             => $acf ? (array) get_field( 'dietary', 'user_' . $user_id ) : array(),
		'accessibility_other' => (string) get_user_meta( $user_id, 'accessibility_other', true ),
		'dietary_other'       => (string) get_user_meta( $user_id, 'dietary_other', true ),
	);
}
