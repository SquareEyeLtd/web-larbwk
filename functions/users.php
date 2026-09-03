<?php

	
	
/* User registration > set selected user role(s) ________________________________________________________ */


add_action( 'gform_user_registered', function( $user_id, $feed, $entry, $user_pass ) {

    // Only run for form ID 1
    if ( absint( rgar( $entry, 'form_id' ) ) !== 1 ) {
        return;
    }

    $checkbox_field_id = 11;

    $allowed_roles = [ 'attendee', 'sponsor', 'event_host' ];
    $selected_roles = [];

    foreach ( $entry as $key => $value ) {
        if ( strpos( (string) $key, $checkbox_field_id . '.' ) === 0 && ! empty( $value ) ) {
            $slug = sanitize_key( $value );
            if ( in_array( $slug, $allowed_roles, true ) ) {
                $selected_roles[] = $slug;
            }
        }
    }

    if ( empty( $selected_roles ) ) {
        return;
    }

    $user = new WP_User( $user_id );

    $user->set_role( $selected_roles[0] );

    for ( $i = 1; $i < count( $selected_roles ); $i++ ) {
        $user->add_role( $selected_roles[ $i ] );
    }

}, 10, 4 );



/* Profile form > sync WP roles from field 12 ________________________________________________________ */

/**
 * Roles a user may add or remove via the profile form. Administrator,
 * events_committee and other roles are left untouched.
 */
function law_self_service_roles() {
    return [ 'sponsor', 'event_host', 'attendee' ];
}

/**
 * Pre-check Form 3 field 12 from the logged-in user's actual WP roles.
 *
 * The mu-plugin law-user-profile-update.php prefills this field from ACF
 * law_role, which can drift from wp_capabilities. Prefilling from WP roles
 * means a save cannot accidentally strip a role the user still holds.
 */
add_filter( 'gform_pre_render_3', 'law_prefill_profile_roles_from_wp' );
function law_prefill_profile_roles_from_wp( $form ) {
    if ( ! is_user_logged_in() ) {
        return $form;
    }
    if ( rgpost( 'is_submit_' . $form['id'] ) ) {
        return $form;
    }

    $current_roles = (array) wp_get_current_user()->roles;
    $known_roles   = law_self_service_roles();

    foreach ( $form['fields'] as $field ) {
        if ( (int) $field->id !== 12 || empty( $field->choices ) ) {
            continue;
        }
        $choices = $field->choices;
        foreach ( $choices as &$choice ) {
            $choice['isSelected'] = in_array( $choice['value'], $known_roles, true )
                && in_array( $choice['value'], $current_roles, true );
        }
        unset( $choice );
        $field->choices = $choices;
        break;
    }

    return $form;
}

/**
 * After the User Registration update feed runs, add/remove only the three
 * self-service roles to match field 12. The feed itself is set to "Preserve
 * current role", so this is the only place WP roles change on a profile save.
 */
add_action( 'gform_user_updated', function( $user_id, $feed, $entry ) {

    if ( absint( rgar( $entry, 'form_id' ) ) !== 3 ) {
        return;
    }

    $known_roles = law_self_service_roles();
    $selected    = [];

    foreach ( $entry as $key => $value ) {
        if ( strpos( (string) $key, '12.' ) === 0 && ! empty( $value ) ) {
            $slug = sanitize_key( $value );
            if ( in_array( $slug, $known_roles, true ) ) {
                $selected[] = $slug;
            }
        }
    }

    $user = new WP_User( $user_id );
    if ( ! $user->exists() ) {
        return;
    }

    foreach ( $known_roles as $role ) {
        $has     = in_array( $role, (array) $user->roles, true );
        $checked = in_array( $role, $selected, true );
        if ( $checked && ! $has ) {
            $user->add_role( $role );
        }
        if ( ! $checked && $has ) {
            $user->remove_role( $role );
        }
    }

}, 10, 3 );



/* The [law_login] shortcode and all login/password handling live in auth.php. */