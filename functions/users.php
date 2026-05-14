<?php
	
	
/* User registration > set selected user role(s) ________________________________________________________ */


add_action( 'gform_user_registered', function( $user_id, $feed, $entry, $user_pass ) {

    // Only run for form ID 1
    if ( absint( rgar( $entry, 'form_id' ) ) !== 1 ) {
        return;
    }

    $checkbox_field_id = 11;

    $allowed_roles = [ 'attendee', 'sponsor' ];
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