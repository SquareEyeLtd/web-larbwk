<?php

add_filter( 'gform_pre_submission_1', function( $form ) {
    $role_field_id    = 11;  // Role checkbox field
    $hubspot_field_id = 22;  // Contact type field

    $selected_roles = array();
    foreach ( $form['fields'] as $field ) {
        if ( (int) $field->id === $role_field_id ) {
            foreach ( $field->inputs as $input ) {
                $key = 'input_' . str_replace( '.', '_', $input['id'] );
                if ( ! empty( $_POST[ $key ] ) ) {
                    $selected_roles[] = $_POST[ $key ];
                }
            }
        }
    }

    $tags = array( '2026 Registered user' );
    if ( in_array( 'sponsor', $selected_roles, true ) ) {
        $tags[] = '2026 Sponsor';
    }
    if ( in_array( 'event_host', $selected_roles, true ) ) {
        $tags[] = '2026 Event Host';
    }

    $_POST[ 'input_' . $hubspot_field_id ] = implode( ';', $tags );

    return $form;
} );