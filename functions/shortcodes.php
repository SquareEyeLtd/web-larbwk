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
 * Show enclosed content only when the current user is in one of the given
 * audiences.
 *
 * Audiences are matched with OR. A user who is both attendee and sponsor still
 * sees attendee copy. Hyphens are treated as underscores (event-host = event_host).
 *
 * Prefer the capability-backed audiences to a role name (see
 * law_user_content_audiences()): editor content that lists role names drifts
 * the moment a role is added, which is exactly how a sponsor-only user ended
 * up with a blank /account/ page. Role names still work, for copy that really
 * is aimed at one role.
 *
 * Special values: host (anyone who can run events), committee, logged-in
 * (any signed-in user), guest (logged out).
 *
 * [user-content role="attendee"]Attendee copy.[/user-content]
 * [user-content role="host"]Copy for hosts, sponsors and the committee.[/user-content]
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

/**
 * Audience name => the capability helper that answers it.
 *
 * The same helpers the header bar asks (functions/header-nav.php), for the
 * same reason: a page that names roles cannot keep up with the roles that
 * exist. 'host' is every audience that can submit and manage events, which is
 * event hosts, sponsors, and the committee, editors and administrators who run
 * events of their own; 'committee' is the events committee and above.
 *
 * @return array<string,string> audience => callable name.
 */
function law_user_content_audiences(): array {
    return [
        'host'      => 'law_account_user_is_host_like',
        'committee' => 'law_user_is_committee',
    ];
}

function law_user_content_matches( array $roles ): bool {
    $logged_in  = is_user_logged_in();
    $user_roles = $logged_in ? (array) wp_get_current_user()->roles : [];
    $audiences  = law_user_content_audiences();

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

        // function_exists() because this file loads before the events module,
        // and a helper that is not there must hide the block rather than fatal.
        if ( isset( $audiences[ $role ] ) ) {
            if ( function_exists( $audiences[ $role ] ) && call_user_func( $audiences[ $role ] ) ) {
                return true;
            }
            continue;
        }

        if ( $role === 'logged_in' || in_array( $role, $user_roles, true ) ) {
            return true;
        }
    }

    return false;
}