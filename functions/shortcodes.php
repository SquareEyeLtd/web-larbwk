<?php


/* Action messages ________________________________________________________ */

function sqe_action_messages(): array {
    return [
        'registered' => [
            'type'    => 'success',
            'heading' => 'Registration successful',
            'body'    => 'You are now registered as a user on the website.',
        ],
        'password-reset' => [
            'type'    => 'info',
            'heading' => 'Password reset',
            'body'    => 'We\'ve sent you a password reset link. Please check your email.',
        ],
        // Add more actions here...
    ];
}

function sqe_action_message_shortcode(): string {
    $action   = sanitize_key( $_GET['action'] ?? '' );
    $messages = sqe_action_messages();

    if ( ! $action || ! isset( $messages[ $action ] ) ) {
        return '';
    }

    $message = $messages[ $action ];
    $type    = esc_attr( $message['type'] );   // success | info | warning | error
    $heading = esc_html( $message['heading'] );
    $body    = esc_html( $message['body'] );

    return sprintf(
        '<div class="callout %s" role="alert">
            <h2>%s</h2>
            <p>%s</p>
        </div>',
        $type,
        $heading,
        $body
    );
}
add_shortcode( 'action-message', 'sqe_action_message_shortcode' );


/* Role-gated content ________________________________________________________ */

/**
 * Show enclosed content only when the current user has one of the given roles.
 *
 * Roles are matched with OR. A user who is both attendee and sponsor still
 * sees attendee copy. Hyphens are treated as underscores (event-host = event_host).
 *
 * Special values: logged-in (any signed-in user), guest (logged out).
 *
 * [user-content role="attendee"]Attendee copy.[/user-content]
 * [user-content role="event_host,sponsor"]Host or sponsor copy.[/user-content]
 */
function law_user_content_shortcode( $atts, $content = null ): string {
    $atts = shortcode_atts(
        [
            'role' => '',
        ],
        $atts,
        'user-content'
    );

    if ( $content === null || $content === '' ) {
        return '';
    }

    $roles = law_user_content_parse_roles( $atts['role'] );
    if ( empty( $roles ) || ! law_user_content_matches( $roles ) ) {
        return '';
    }

    // Classic Editor wrapping often leaves a stray </p> / <p> around enclosing shortcodes.
    $content = preg_replace( '/^<\/p>/i', '', (string) $content );
    $content = preg_replace( '/<p>$/i', '', $content );

    return do_shortcode( shortcode_unautop( trim( $content ) ) );
}
add_shortcode( 'user-content', 'law_user_content_shortcode' );

function law_user_content_parse_roles( string $raw ): array {
    $parts = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
    $roles = [];

    foreach ( $parts as $part ) {
        $role = str_replace( '-', '_', sanitize_key( $part ) );
        if ( $role !== '' ) {
            $roles[] = $role;
        }
    }

    return array_values( array_unique( $roles ) );
}

function law_user_content_matches( array $roles ): bool {
    $logged_in  = is_user_logged_in();
    $user_roles = $logged_in ? (array) wp_get_current_user()->roles : [];

    foreach ( $roles as $role ) {
        if ( in_array( $role, [ 'guest', 'logged_out' ], true ) ) {
            if ( ! $logged_in ) {
                return true;
            }
            continue;
        }

        if ( ! $logged_in ) {
            continue;
        }

        if ( $role === 'logged_in' || in_array( $role, $user_roles, true ) ) {
            return true;
        }
    }

    return false;
}