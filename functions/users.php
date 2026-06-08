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



/* Custom login form ________________________________________________________ */

add_shortcode( 'law_login', function () {
	if ( is_user_logged_in() ) {
		$inbox = home_url( '/inbox/' );
		return '<p>You are signed in. <a href="' . esc_url( $inbox ) . '">Go to your inbox</a> · '
			. '<a href="' . esc_url( wp_logout_url( home_url( '/login/' ) ) ) . '">Log out</a></p>';
	}

	$form = wp_login_form( array(
		'echo'           => false,
		'redirect'       => home_url( '/account/events/' ), // where hosts land after login
		'label_username' => 'Email or username',
		'remember'       => true,
	) );

	$lost = '<p class="law-lost-password"><a href="' . esc_url( wp_lostpassword_url( home_url( '/login/' ) ) ) . '">Lost your password?</a></p>';

	return $form . $lost;
} );