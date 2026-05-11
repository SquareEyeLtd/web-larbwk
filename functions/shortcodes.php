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