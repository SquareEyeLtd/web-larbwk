<?php
/**
 * Phase D: custom registration and profile forms, replacing form 1 (User
 * registration) and form 3 (User profile) plus their User Registration
 * feeds, the GW Auto Login step and the mu-plugin checkbox sync
 * (EVENTS_4.1_REBUILD.md §7 phase D).
 *
 * Carried behaviours: username = email, display name "First Last", user meta
 * keys organisation / job_title / country / accessibility_other /
 * dietary_other, the ACF fields accessibility / dietary, the HubSpot contact
 * type tags, auto-login with the /account/?action=registered redirect, and
 * the active "Email to admins > user registration" notification.
 *
 * The role checkboxes went on 14 September 2026 (Denis): the three
 * self-service roles event_host, sponsor and attendee are retired, every
 * self-service account is a plain subscriber, and any signed-in person may
 * submit an event or book a place. What the checkboxes used to say about
 * somebody survives as OPTIONAL user meta, law_intent (see
 * law_registration_intents()), which now feeds only the HubSpot tags and gates
 * nothing at all. ROLES_AND_ACCOUNT_HUB.md is the contract; migration step 11
 * converts the existing accounts.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The law_intent vocabulary: key => the label a form would use.
 *
 * **No form renders these today.** They replaced the role checkboxes on
 * 14 September 2026 and came off the forms the same day (Denis), because
 * nothing about the site depends on the answer: anyone signed in may submit an
 * event and book a place whatever is stored here, and nothing in this key may
 * ever become a gate.
 *
 * The vocabulary and its storage stay for ONE reason, now that the welcome
 * email no longer branches on it (16 September 2026): migration step 11 seeded
 * this key for every account it converted, so it holds the only translation of
 * what the retired roles said about 302 people, and it is what
 * law_registration_hubspot_tags() reads to produce the "<year> Event Host" and
 * "<year> Sponsor" tags the client segments on. Deleting it would lose both.
 *
 * What follows from nothing rendering it: new registrations store an empty
 * array (asked nothing, so nothing ticked) and carry no Host or Sponsor tag.
 * Put a tick back on a form and the tags come back with it; the second welcome
 * template would have to be restored from the same commit that removed it.
 */
function law_registration_intents() {
	return array(
		'host'    => 'I plan to host an event',
		'sponsor' => 'I represent a LAW sponsor',
	);
}

/**
 * The three retired self-service roles.
 *
 * Kept for exactly two jobs: migration step 11 (law_migration_run_retire_roles()),
 * which strips them, and the guard in law_registration_apply_attendee_profile(),
 * which must keep recognising a not-yet-migrated account during the window
 * between the deploy and the step. Nothing else may read this: the roles are
 * dead as an access signal.
 *
 * They stay DEFINED in wp_user_roles deliberately (the mu-plugin
 * law-secondary-host-users.php re-registers event_host on every init anyway),
 * so a rollback is a code revert rather than a data rebuild.
 */
function law_registration_legacy_roles() {
	return array( 'event_host', 'sponsor', 'attendee' );
}

/**
 * The stored intents for a user, whitelisted against the current choice list.
 *
 * An empty array means "asked, nothing ticked". NO row at all means "never
 * asked": the accounts the engines create for co-owners and colleagues, and
 * every account from before migration step 11 ran. Step 11 relies on that
 * distinction (metadata_exists()) so it never overwrites somebody who edited
 * their profile between two runs.
 *
 * @return string[]
 */
function law_registration_read_intent( $user_id ) {
	return array_values( array_intersect(
		(array) get_user_meta( (int) $user_id, 'law_intent', true ),
		array_keys( law_registration_intents() )
	) );
}

/**
 * Store the intents for a user, whitelisted. The single write path.
 *
 * @return string[] What was actually stored.
 */
function law_registration_write_intent( $user_id, array $intents ) {
	$intents = array_values( array_intersect(
		array_map( 'sanitize_key', $intents ),
		array_keys( law_registration_intents() )
	) );
	update_user_meta( (int) $user_id, 'law_intent', $intents );
	return $intents;
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

/**
 * The HubSpot contact type tags (mirrors functions/hubspot.php).
 *
 * Driven by the optional intent ticks since 14 September 2026, not by roles.
 * The tag STRINGS are deliberately unchanged, so the values already stored in
 * law_hubspot_contact_type stay comparable and whatever reads them downstream
 * (Make, outside this codebase) sees the same vocabulary it always has.
 *
 * @param string[] $intents The stored law_intent values.
 */
function law_registration_hubspot_tags( array $intents ) {
	$year = (int) law_events_setting( 'year', (int) gmdate( 'Y' ) );
	$tags = array( $year . ' Registered user' );
	if ( in_array( 'sponsor', $intents, true ) ) {
		$tags[] = $year . ' Sponsor';
	}
	if ( in_array( 'host', $intents, true ) ) {
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

	// law_intent is written ONLY when a form actually offered it. No form does
	// today (the tick came off on 14 September 2026), and this is the line that
	// decides whether that is a tidy-up or a data loss: migration step 11 seeded
	// this key for every account from the roles it was retiring, so blanking it
	// on an unrelated profile save would quietly destroy the only translation of
	// those roles, and with it the HubSpot Sponsor and Event Host tags. A form
	// governs what it shows; it does not get to clear what it never asked about.
	$intents = array_key_exists( 'law_intent', $input )
		? law_registration_write_intent( $user_id, (array) $input['law_intent'] )
		: law_registration_read_intent( $user_id );

	$accessibility = array_values( array_intersect( array_map( 'sanitize_text_field', (array) ( $input['accessibility'] ?? array() ) ), array_keys( law_registration_accessibility_choices() ) ) );
	$dietary       = array_values( array_intersect( array_map( 'sanitize_text_field', (array) ( $input['dietary'] ?? array() ) ), law_registration_dietary_choices() ) );

	// The ACF user fields the old mu-plugin synced (accessibility, dietary);
	// update_field keeps ACF's storage format.
	//
	// The third one, law_role, is no longer written (14 September 2026): its
	// choices are the retired role slugs, so an intent key would be an
	// off-list value, and writing role slugs would keep a dead vocabulary
	// alive. Existing values are left exactly as they are; removing the ACF
	// field and its rows belongs to the post-cutover cleanup ticket.
	if ( function_exists( 'update_field' ) ) {
		update_field( 'accessibility', $accessibility, 'user_' . $user_id );
		update_field( 'dietary', $dietary, 'user_' . $user_id );
	}

	return $intents;
}

/* Profiles filled in on somebody else's behalf _______________________________ */

/**
 * The profile facts a host or committee member can give when they register
 * somebody on their behalf: country, accessibility and dietary, with the two
 * "Other" free-text boxes. The POST names match the registration form's, so
 * $_POST goes straight in (Denis, 11 September 2026: the register-an-attendee
 * dialogs must collect almost what registration collects, because the bookings
 * tables and the exports read these three columns live from the profile and a
 * person booked by phone has nobody to fill them in).
 *
 * The "Other" text is dropped unless "Other" is actually ticked, so a stale box
 * cannot attach free text to a list that has no Other in it.
 *
 * @return array{country:string,accessibility:string[],accessibility_other:string,dietary:string[],dietary_other:string}
 */
function law_registration_clean_attendee_profile( array $input ) {
	$accessibility = array_values( array_intersect( array_map( 'sanitize_text_field', (array) ( $input['accessibility'] ?? array() ) ), array_keys( law_registration_accessibility_choices() ) ) );
	$dietary       = array_values( array_intersect( array_map( 'sanitize_text_field', (array) ( $input['dietary'] ?? array() ) ), law_registration_dietary_choices() ) );

	return array(
		'country'             => sanitize_text_field( trim( (string) ( $input['country'] ?? '' ) ) ),
		'accessibility'       => $accessibility,
		'accessibility_other' => in_array( 'Other', $accessibility, true ) ? sanitize_text_field( trim( (string) ( $input['accessibility_other'] ?? '' ) ) ) : '',
		'dietary'             => $dietary,
		'dietary_other'       => in_array( 'Other', $dietary, true ) ? sanitize_text_field( trim( (string) ( $input['dietary_other'] ?? '' ) ) ) : '',
	);
}

/**
 * Validate a cleaned set. The rules are the registration form's, minus the
 * required-ness of country, which each calling surface decides for itself: the
 * per-event bookings list asks for it (its other four fields are required too,
 * mirroring registration), the flagship's complimentary-place dialog does not
 * (only a name and an email are required there).
 *
 * The refusal carries the field name so the fetch layer can mark the control.
 *
 * @return WP_Error|true
 */
function law_registration_validate_attendee_profile( array $clean, $country_required = false ) {
	$country = law_registration_validate_country( (string) $clean['country'], (bool) $country_required );
	if ( '' !== $country ) {
		return new WP_Error( 'law_profile_country', $country, array( 'field' => 'country' ) );
	}
	if ( in_array( 'Other', (array) $clean['accessibility'], true ) && '' === (string) $clean['accessibility_other'] ) {
		return new WP_Error( 'law_profile_accessibility_other', 'Please specify their other accessibility requirement.', array( 'field' => 'accessibility_other' ) );
	}
	if ( in_array( 'Other', (array) $clean['dietary'], true ) && '' === (string) $clean['dietary_other'] ) {
		return new WP_Error( 'law_profile_dietary_other', 'Please specify their other dietary requirement.', array( 'field' => 'dietary_other' ) );
	}
	return true;
}

/**
 * Write a cleaned set onto the attendee's account.
 *
 * A BRAND NEW account (one this registration just created) takes everything
 * given. An account that already existed only has its BLANKS filled: the person
 * stated those requirements themselves, from their own profile, and a host
 * typing what they remember of a phone call must never overwrite it. The
 * accessibility and dietary lists are written with their "Other" text or not at
 * all, so free text can never end up attached to a list that was left alone.
 *
 * An account that already existed and holds anything beyond subscriber — an
 * administrator, a committee member — is not written to at all (security
 * review, 11 September 2026). Whoever fills this form chooses
 * the email address, and the account it lands on is found by that address
 * alone, so a host could otherwise put accessibility or dietary details, which
 * are health-adjacent data, onto a committee member's account simply by knowing
 * their address. Those accounts have their own profile form; this surface is
 * for the people being booked in.
 *
 * @param bool $is_new_account Whether this registration created the account.
 * @return string[] One readable line per thing written, for the activity log.
 */
function law_registration_apply_attendee_profile( $user_id, array $clean, $is_new_account ) {
	$user_id = (int) $user_id;
	$written = array();

	if ( ! $is_new_account ) {
		$user = get_user_by( 'id', $user_id );
		// Subscriber is the self-service role now; the three retired ones are
		// named as well so an account that has not yet been through migration
		// step 11 is still recognised as an ordinary person during the window
		// between the deploy and the step. Get this wrong and the function
		// returns early for EVERY account, silently, with nothing logged.
		if ( ! $user || array_diff( (array) $user->roles, array_merge( array( 'subscriber' ), law_registration_legacy_roles() ) ) ) {
			return $written;
		}
	}

	if ( '' !== (string) $clean['country']
		&& ( $is_new_account || '' === (string) get_user_meta( $user_id, 'country', true ) ) ) {
		update_user_meta( $user_id, 'country', (string) $clean['country'] );
		$written[] = 'country: ' . $clean['country'];
	}

	// The lists live in ACF (the fields the old mu-plugin synced), so without
	// ACF there is nowhere to put them and nothing reads them either.
	if ( ! function_exists( 'update_field' ) || ! function_exists( 'get_field' ) ) {
		return $written;
	}

	foreach ( array( 'accessibility', 'dietary' ) as $key ) {
		$values = (array) $clean[ $key ];
		if ( ! $values ) {
			continue;
		}
		if ( ! $is_new_account && array_filter( (array) get_field( $key, 'user_' . $user_id ) ) ) {
			continue; // Theirs already; leave it alone.
		}
		update_field( $key, $values, 'user_' . $user_id );
		$other = (string) $clean[ $key . '_other' ];
		update_user_meta( $user_id, $key . '_other', $other );
		$written[] = $key . ': ' . implode( ', ', $values ) . ( '' !== $other ? ' (other: ' . $other . ')' : '' );
	}

	return $written;
}

/* Registration ______________________________________________________________ */

add_action( 'admin_post_nopriv_law_register', 'law_registration_handler' );
add_action( 'admin_post_law_register', function () {
	wp_safe_redirect( home_url( '/account/' ) ); // Already signed in.
	exit;
} );

function law_registration_handler() {
	// The booking modal's register link arrives with a return destination,
	// which survives the whole round trip, error paths included, so the new
	// attendee lands back on the event they were booking. It used to carry a
	// locked role too; roles went on 14 September 2026 and there is nothing to
	// lock any more, because everyone signed in may book and submit.
	$redirect_to = wp_validate_redirect( wp_unslash( (string) ( $_POST['redirect_to'] ?? '' ) ), '' );

	// Anonymous surface: per-IP limit. 20/hour absorbs a law-firm office or
	// conference venue behind one NAT while still capping scripted abuse
	// (which the honeypot and the nonce already blunt).
	//
	// It runs BEFORE the nonce now, because the nonce below no longer ends the
	// request: a stale one stores a transient and bounces back to the form, so
	// without a budget in front of it that path would be a way to fill the
	// options table with junk. Nothing is lost by the swap — for a logged-out
	// visitor the nonce is a value shared by every visitor (see the note on
	// the forgot-password rate limit in EVENTS_FUNC.md), so it never gated
	// this counter in any meaningful way.
	if ( ! law_events_rate_limit_ok( 'register', 0, 20, HOUR_IN_SECONDS ) ) {
		// 429 (not the wp_die default 500) with a title and a way back, so a
		// shared-office/NAT user who hits the cap isn't left on a bare error.
		wp_die(
			esc_html__( 'Too many registration attempts from this connection. Please wait a little while and try again.', 'law' ),
			esc_html__( 'Please try again shortly', 'law' ),
			array( 'response' => 429, 'back_link' => true )
		);
	}

	// An expired or missing nonce is answered with the form and a readable
	// sentence, NOT with core's wp_nonce_ays() screen (22 September 2026).
	//
	// The reason is a real report: /register/ was being held in the Kinsta edge
	// cache for up to 24 hours (`s-maxage=86400`) while a logged-out visitor's
	// nonce only lives between 12 and 24 hours, so anyone served a cached copy
	// from the back half of its life posted a nonce that was already dead and
	// got "The link you followed has expired." on a bare wp-admin/admin-post.php
	// URL, with their typed details gone. law_no_store_nonce_pages() in
	// functions/wordpress.php is the actual fix — the page is no longer
	// cacheable at all — and this is the safety net for the case that fix
	// cannot reach: a form left open in a tab overnight.
	//
	// The typed values come back with it (never the passwords, as everywhere
	// else here), so "try again" means pressing the button, not retyping a
	// page of profile fields.
	if ( ! wp_verify_nonce( (string) ( $_POST['_wpnonce'] ?? '' ), 'law_register' ) ) {
		$safe_input = law_events_form_reusable_input( wp_unslash( $_POST ) );
		unset( $safe_input['password'], $safe_input['password_confirm'] );
		law_registration_store_state( array(
			'errors' => array( 'expired' => array( 'Your details were not submitted: this page had been open long enough for its security check to expire. Everything you typed is still here, so please press "Create account" again.' ) ),
			'input'  => $safe_input,
		) );
		$back = add_query_arg( 'law_form_error', 1, home_url( '/register/' ) );
		if ( '' !== $redirect_to ) {
			$back = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	// Honeypot: pretend success.
	if ( '' !== trim( (string) ( $_POST['law_website_url'] ?? '' ) ) ) {
		wp_safe_redirect( '' !== $redirect_to ? $redirect_to : home_url( '/account/?action=registered' ) );
		exit;
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
	if ( strlen( $password ) < LAW_AUTH_MIN_PASSWORD_LENGTH ) {
		$errors->add( 'password', sprintf( 'Please choose a password of at least %d characters.', LAW_AUTH_MIN_PASSWORD_LENGTH ) );
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
	// Deliberate divergence from form 1 (User registration), which left fields 18
	// and 20 ("Other: please specify") optional: ticking Other and saying nothing
	// records a requirement nobody can act on. Form 3 (User profile) already
	// required its equivalents (fields 14 and 16), so both forms now behave alike.
	if ( in_array( 'Other', (array) ( $input['accessibility'] ?? array() ), true ) && '' === trim( (string) ( $input['accessibility_other'] ?? '' ) ) ) {
		$errors->add( 'accessibility_other', 'Please specify your other accessibility requirement.' );
	}
	if ( in_array( 'Other', (array) ( $input['dietary'] ?? array() ), true ) && '' === trim( (string) ( $input['dietary_other'] ?? '' ) ) ) {
		$errors->add( 'dietary_other', 'Please specify your other dietary requirement.' );
	}

	if ( $errors->has_errors() ) {
		$safe_input = law_events_form_reusable_input( $input );
		unset( $safe_input['password'], $safe_input['password_confirm'] );
		law_registration_store_state( array( 'errors' => $errors->errors, 'input' => $safe_input ) );
		$back = add_query_arg( 'law_form_error', 1, home_url( '/register/' ) );
		if ( '' !== $redirect_to ) {
			$back = add_query_arg( 'redirect_to', rawurlencode( $redirect_to ), $back );
		}
		wp_safe_redirect( $back );
		exit;
	}

	// Every self-service account is a plain subscriber (14 September 2026).
	// There is nothing for a tampered request to escalate to any more: the
	// form posts intents, which are meta, and the role is not taken from
	// input at all.
	$user_id = wp_insert_user(
		array(
			'user_login'   => $email,
			'user_email'   => $email,
			'user_pass'    => $password,
			'first_name'   => $first,
			'last_name'    => $last,
			'nickname'     => $first,
			'display_name' => trim( $first . ' ' . $last ),
			'role'         => 'subscriber',
		)
	);
	if ( is_wp_error( $user_id ) ) {
		$safe_input = law_events_form_reusable_input( $input );
		unset( $safe_input['password'], $safe_input['password_confirm'] );
		law_registration_store_state( array( 'errors' => array( 'email' => array( $user_id->get_error_message() ) ), 'input' => $safe_input ) );
		wp_safe_redirect( add_query_arg( 'law_form_error', 1, home_url( '/register/' ) ) );
		exit;
	}

	$stored_intents = law_registration_write_profile_meta( $user_id, $input );
	update_user_meta( $user_id, 'law_hubspot_contact_type', law_registration_hubspot_tags( $stored_intents ) );

	// The active admins notification (and the inactive Square Eye one).
	$user = get_user_by( 'id', $user_id );
	$placeholders = array(
		'user_name'  => $user->display_name,
		'user_email' => $user->user_email,
	);
	law_events_send( 'admins_user_registered', 0, array( 'placeholders' => $placeholders ) );
	law_events_send( 'squareeye_user_registered', 0, array( 'placeholders' => $placeholders ) );
	// The welcome to the new user (settled, EVENTS_BOOKINGS.md). Like the two
	// admin notices above it has no event, so it is the one send the activity
	// log cannot record (law_event_log() needs an event to attach to).
	//
	// One template, named directly. Until 16 September 2026 a
	// law_registration_welcome_slug() helper chose between this and a
	// hosting-side copy on the stored intents, but no form has collected an
	// intent since 14 September 2026, so the choice only ever had one answer;
	// both the helper and the second template are gone (Denis).
	law_events_send( 'user_welcome_registered', 0, array( 'to' => array( $user->user_email ), 'placeholders' => $placeholders ) );

	// Auto-login (replacing GW Auto Login) + the form 1 confirmation redirect.
	wp_set_current_user( $user_id );
	wp_set_auth_cookie( $user_id, true );
	do_action( 'wp_login', $user->user_login, $user );

	wp_safe_redirect( '' !== $redirect_to ? $redirect_to : home_url( '/account/?action=registered' ) );
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

/**
 * Persist a failed profile submission and bounce back to the form. Keeps the
 * typed values (never the passwords), and always re-arrays the checkbox groups
 * so a fully CLEARED group stays cleared rather than falling back to the stored
 * value. Shared by the validation-fail and wp_update_user-fail paths.
 *
 * @param int   $user_id Acting user.
 * @param array $errors  Errors in WP_Error->errors shape (code => messages[]).
 * @param array $input   Unslashed POST.
 */
function law_profile_store_error_state( $user_id, array $errors, array $input ) {
	$safe_input = law_events_form_reusable_input( $input );
	unset( $safe_input['password'], $safe_input['password_confirm'], $safe_input['current_password'] );
	foreach ( array( 'accessibility', 'dietary' ) as $group ) {
		$safe_input[ $group ] = (array) ( $input[ $group ] ?? array() );
	}
	set_transient( 'law_profile_state_' . $user_id, array( 'errors' => $errors, 'input' => $safe_input ), 10 * MINUTE_IN_SECONDS );
	wp_safe_redirect( add_query_arg( 'law_form_error', 1, home_url( '/account/profile/' ) ) );
	exit;
}

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
		if ( strlen( $new_password ) < LAW_AUTH_MIN_PASSWORD_LENGTH ) {
			$errors->add( 'password', sprintf( 'Please choose a new password of at least %d characters.', LAW_AUTH_MIN_PASSWORD_LENGTH ) );
		} elseif ( $new_password !== (string) ( $input['password_confirm'] ?? '' ) ) {
			$errors->add( 'password_confirm', 'The two passwords do not match.' );
		}
	}

	if ( $errors->has_errors() ) {
		law_profile_store_error_state( $user_id, $errors->errors, $input );
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
		law_profile_store_error_state( $user_id, array( 'email' => array( $result->get_error_message() ) ), $input );
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

	$intents = law_registration_write_profile_meta( $user_id, $input );
	update_user_meta( $user_id, 'law_hubspot_contact_type', law_registration_hubspot_tags( $intents ) );

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
		'law_intent'          => law_registration_read_intent( $user_id ),
		'accessibility'       => $acf ? (array) get_field( 'accessibility', 'user_' . $user_id ) : array(),
		'dietary'             => $acf ? (array) get_field( 'dietary', 'user_' . $user_id ) : array(),
		'accessibility_other' => (string) get_user_meta( $user_id, 'accessibility_other', true ),
		'dietary_other'       => (string) get_user_meta( $user_id, 'dietary_other', true ),
	);
}
