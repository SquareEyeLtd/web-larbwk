<?php
/**
 * Front-end authentication: branded login, forgot password and reset password.
 *
 * The /login/ page (templates/login.php) renders one of three forms based on
 * the ?action= query parameter:
 *   (none)  sign-in form, posting to wp-login.php so core wp_signon() runs
 *   forgot  email form that calls core retrieve_password()
 *   reset   new-password form from the emailed link (?action=reset&key=&login=)
 *
 * Registration stays on /register/ as Gravity Forms form 1 (User registration).
 *
 * All credential handling is delegated to core (wp_signon via wp-login.php,
 * retrieve_password(), check_password_reset_key(), reset_password()). This
 * file only owns the markup, the redirects and the messages. wp-login.php's
 * own GET screens are redirected to the branded pages so visitors never see
 * the default WordPress screens.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Helpers ________________________________________________________ */

function law_auth_login_url( $args = array() ) {
	$url = home_url( '/login/' );
	return $args ? add_query_arg( $args, $url ) : $url;
}

/**
 * Which form the login page is showing: 'login', 'forgot' or 'reset'.
 */
function law_auth_mode() {
	$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
	return in_array( $action, array( 'forgot', 'reset' ), true ) ? $action : 'login';
}

/**
 * Where to send users after a successful sign-in. A redirect_to passed to the
 * page (e.g. by auth_redirect() from a protected page) wins over the default.
 */
function law_auth_redirect_to() {
	$redirect = isset( $_REQUEST['redirect_to'] ) ? wp_unslash( $_REQUEST['redirect_to'] ) : '';
	$redirect = wp_validate_redirect( $redirect, '' );
	return $redirect ? $redirect : home_url( '/account/events/' );
}

/* Notices ________________________________________________________ */

/**
 * Map status query args to user-facing notices. Only fixed keys are read, so
 * no request text is ever reflected back into the page.
 *
 * @return array[] Each: array( 'type' => 'error'|'success', 'text' => string ).
 */
function law_auth_notices() {
	$notices = array();

	$login = isset( $_GET['login'] ) ? sanitize_key( wp_unslash( $_GET['login'] ) ) : '';
	if ( 'failed' === $login ) {
		$notices[] = array(
			'type' => 'error',
			'text' => 'The email address or password is incorrect. Please try again, or reset your password below.',
		);
	} elseif ( 'empty' === $login ) {
		$notices[] = array(
			'type' => 'error',
			'text' => 'Please enter both your email address and your password.',
		);
	}

	if ( isset( $_GET['loggedout'] ) ) {
		$notices[] = array(
			'type' => 'success',
			'text' => 'You have been signed out.',
		);
	}

	if ( isset( $_GET['password-reset'] ) ) {
		$notices[] = array(
			'type' => 'success',
			'text' => 'Your password has been changed. Please sign in with your new password.',
		);
	}

	$forgot = isset( $_GET['forgot'] ) ? sanitize_key( wp_unslash( $_GET['forgot'] ) ) : '';
	if ( 'sent' === $forgot ) {
		$notices[] = array(
			'type' => 'success',
			'text' => 'If that email address has an account, a password reset link is on its way. Please also check your spam folder.',
		);
	} elseif ( 'empty' === $forgot ) {
		$notices[] = array(
			'type' => 'error',
			'text' => 'Please enter your email address.',
		);
	} elseif ( 'error' === $forgot ) {
		$notices[] = array(
			'type' => 'error',
			'text' => 'The reset email could not be sent. Please try again, or contact us if the problem continues.',
		);
	}

	$reset = isset( $_GET['reset'] ) ? sanitize_key( wp_unslash( $_GET['reset'] ) ) : '';
	if ( 'mismatch' === $reset ) {
		$notices[] = array(
			'type' => 'error',
			'text' => 'The two passwords do not match. Please try again.',
		);
	} elseif ( 'empty' === $reset ) {
		$notices[] = array(
			'type' => 'error',
			'text' => 'Please choose a password and confirm it.',
		);
	}

	return $notices;
}

/* Form rendering ________________________________________________________ */

/**
 * Render the form for the current mode. Echoes markup; used by
 * templates/login.php and the [law_login] shortcode.
 */
function law_auth_render_form( $mode = null ) {
	$mode = $mode ? $mode : law_auth_mode();

	if ( 'forgot' === $mode ) {
		law_auth_render_forgot_form();
		return;
	}
	if ( 'reset' === $mode ) {
		law_auth_render_reset_form();
		return;
	}
	law_auth_render_login_form();
}

function law_auth_render_login_form() {
	?>
	<form class="law-auth-form" method="post" action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>">
		<p class="law-auth-field">
			<label for="law-user-login">Email address</label>
			<input type="text" name="log" id="law-user-login" autocomplete="username" required>
		</p>
		<p class="law-auth-field">
			<label for="law-user-pass">Password</label>
			<input type="password" name="pwd" id="law-user-pass" autocomplete="current-password" required>
		</p>
		<p class="law-auth-remember">
			<label><input type="checkbox" name="rememberme" value="forever"> Remember me</label>
		</p>
		<div class="law-auth-actions">
			<a class="law-auth-forgot" href="<?php echo esc_url( law_auth_login_url( array( 'action' => 'forgot' ) ) ); ?>">Forgot password?</a>
			<button type="submit" class="law-auth-button">Sign in</button>
		</div>
		<input type="hidden" name="redirect_to" value="<?php echo esc_attr( law_auth_redirect_to() ); ?>">
		<input type="hidden" name="law_auth" value="1">
	</form>
	<?php
}

function law_auth_render_forgot_form() {
	?>
	<form class="law-auth-form" method="post" action="<?php echo esc_url( law_auth_login_url( array( 'action' => 'forgot' ) ) ); ?>">
		<p class="law-auth-field">
			<label for="law-forgot-email">Email address</label>
			<input type="email" name="law_forgot_email" id="law-forgot-email" autocomplete="username" required>
		</p>
		<div class="law-auth-actions">
			<a class="law-auth-forgot" href="<?php echo esc_url( law_auth_login_url() ); ?>">Back to sign in</a>
			<button type="submit" class="law-auth-button">Send reset link</button>
		</div>
		<?php wp_nonce_field( 'law_forgot', 'law_forgot_nonce' ); ?>
	</form>
	<?php
}

function law_auth_render_reset_form() {
	$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

	$user = check_password_reset_key( $key, $login );
	if ( is_wp_error( $user ) ) {
		?>
		<div class="law-auth-notice law-auth-notice--error">
			<p>This password reset link is invalid or has expired.</p>
		</div>
		<p class="law-auth-alt"><a href="<?php echo esc_url( law_auth_login_url( array( 'action' => 'forgot' ) ) ); ?>">Request a new reset link</a></p>
		<?php
		return;
	}

	$action = law_auth_login_url(
		array(
			'action' => 'reset',
			'key'    => rawurlencode( $key ),
			'login'  => rawurlencode( $login ),
		)
	);
	?>
	<form class="law-auth-form" method="post" action="<?php echo esc_url( $action ); ?>">
		<p class="law-auth-field">
			<label for="law-reset-pass1">New password</label>
			<input type="password" name="law_pass1" id="law-reset-pass1" autocomplete="new-password" required>
		</p>
		<p class="law-auth-field">
			<label for="law-reset-pass2">Confirm new password</label>
			<input type="password" name="law_pass2" id="law-reset-pass2" autocomplete="new-password" required>
		</p>
		<div class="law-auth-actions">
			<button type="submit" class="law-auth-button">Set new password</button>
		</div>
		<?php wp_nonce_field( 'law_reset', 'law_reset_nonce' ); ?>
	</form>
	<?php
}

/**
 * Notices for the current request, as markup. Echoes nothing when there are none.
 */
function law_auth_render_notices() {
	foreach ( law_auth_notices() as $notice ) {
		printf(
			'<div class="law-auth-notice law-auth-notice--%1$s"><p>%2$s</p></div>',
			esc_attr( $notice['type'] ),
			esc_html( $notice['text'] )
		);
	}
}

/* Shortcode (kept for backwards compatibility) ______________________________ */

add_shortcode( 'law_login', function () {
	if ( is_page_template( 'templates/login.php' ) ) {
		return ''; // The template renders the form itself.
	}
	ob_start();
	law_auth_render_notices();
	if ( is_user_logged_in() ) {
		?>
		<p>You are signed in.
			<a href="<?php echo esc_url( home_url( '/account/' ) ); ?>">Go to your account</a> &middot;
			<a href="<?php echo esc_url( wp_logout_url( law_auth_login_url() ) ); ?>">Log out</a></p>
		<?php
	} else {
		law_auth_render_form();
	}
	return ob_get_clean();
} );

/* POST handlers: forgot + reset ______________________________________________ */

/**
 * Handle the forgot-password form. Posts back to /login/?action=forgot.
 * Uses core retrieve_password(), which builds the key and sends the email.
 * The response is deliberately the same whether or not the account exists.
 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_POST['law_forgot_nonce'] ) ) {
		return;
	}
	if ( ! wp_verify_nonce( wp_unslash( $_POST['law_forgot_nonce'] ), 'law_forgot' ) ) {
		wp_safe_redirect( law_auth_login_url( array( 'action' => 'forgot', 'forgot' => 'error' ) ) );
		exit;
	}

	$email = isset( $_POST['law_forgot_email'] ) ? sanitize_text_field( wp_unslash( $_POST['law_forgot_email'] ) ) : '';
	if ( '' === trim( $email ) ) {
		wp_safe_redirect( law_auth_login_url( array( 'action' => 'forgot', 'forgot' => 'empty' ) ) );
		exit;
	}

	$result = retrieve_password( $email );

	// Unknown accounts get the same neutral confirmation as real ones, so the
	// form can't be used to test which email addresses are registered. Only a
	// genuine send failure surfaces as an error.
	if ( is_wp_error( $result ) && 'retrieve_password_email_failure' === $result->get_error_code() ) {
		wp_safe_redirect( law_auth_login_url( array( 'action' => 'forgot', 'forgot' => 'error' ) ) );
		exit;
	}

	wp_safe_redirect( law_auth_login_url( array( 'action' => 'forgot', 'forgot' => 'sent' ) ) );
	exit;
} );

/**
 * Handle the reset-password form. Posts back to /login/?action=reset&key=&login=.
 * The key is re-validated on POST; passwords must match and be non-empty.
 */
add_action( 'template_redirect', function () {
	if ( ! isset( $_POST['law_reset_nonce'] ) ) {
		return;
	}

	$key   = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
	$login = isset( $_GET['login'] ) ? sanitize_text_field( wp_unslash( $_GET['login'] ) ) : '';

	$back = array(
		'action' => 'reset',
		'key'    => rawurlencode( $key ),
		'login'  => rawurlencode( $login ),
	);

	if ( ! wp_verify_nonce( wp_unslash( $_POST['law_reset_nonce'] ), 'law_reset' ) ) {
		wp_safe_redirect( law_auth_login_url( $back ) );
		exit;
	}

	$user = check_password_reset_key( $key, $login );
	if ( is_wp_error( $user ) ) {
		// The render function shows the invalid/expired state.
		wp_safe_redirect( law_auth_login_url( $back ) );
		exit;
	}

	$pass1 = isset( $_POST['law_pass1'] ) ? (string) wp_unslash( $_POST['law_pass1'] ) : '';
	$pass2 = isset( $_POST['law_pass2'] ) ? (string) wp_unslash( $_POST['law_pass2'] ) : '';

	if ( '' === $pass1 || '' === $pass2 ) {
		wp_safe_redirect( law_auth_login_url( $back + array( 'reset' => 'empty' ) ) );
		exit;
	}
	if ( $pass1 !== $pass2 ) {
		wp_safe_redirect( law_auth_login_url( $back + array( 'reset' => 'mismatch' ) ) );
		exit;
	}

	reset_password( $user, $pass1 );

	wp_safe_redirect( law_auth_login_url( array( 'password-reset' => '1' ) ) );
	exit;
} );

/* Failed sign-in: back to /login/ instead of the wp-login.php screen ________ */

add_action( 'wp_login_failed', function () {
	if ( empty( $_POST['law_auth'] ) ) {
		return; // Not our form (e.g. XML-RPC, a plugin, wp-login.php direct).
	}
	$args = array( 'login' => 'failed' );
	$redirect = isset( $_POST['redirect_to'] ) ? wp_validate_redirect( wp_unslash( $_POST['redirect_to'] ), '' ) : '';
	if ( $redirect ) {
		$args['redirect_to'] = rawurlencode( $redirect );
	}
	wp_safe_redirect( law_auth_login_url( $args ) );
	exit;
} );

/**
 * Core doesn't fire wp_login_failed for empty credentials, so catch those
 * separately. The required attributes handle this client-side; this is the
 * server-side fallback.
 */
add_filter( 'authenticate', function ( $user ) {
	if ( empty( $_POST['law_auth'] ) ) {
		return $user;
	}
	if ( is_wp_error( $user ) && in_array( $user->get_error_code(), array( 'empty_username', 'empty_password' ), true ) ) {
		wp_safe_redirect( law_auth_login_url( array( 'login' => 'empty' ) ) );
		exit;
	}
	return $user;
}, 100 );

/* Point WordPress at the branded pages _______________________________________ */

add_filter( 'login_url', function ( $login_url, $redirect ) {
	$url = law_auth_login_url();
	if ( $redirect ) {
		$url = add_query_arg( 'redirect_to', urlencode( $redirect ), $url );
	}
	return $url;
}, 10, 2 );

add_filter( 'lostpassword_url', function () {
	return law_auth_login_url( array( 'action' => 'forgot' ) );
} );

add_filter( 'register_url', function () {
	return home_url( '/register/' );
} );

/**
 * Rewrite the reset link in the password reset email so it lands on
 * /login/?action=reset instead of wp-login.php?action=rp.
 *
 * Matched by regex rather than exact string: core has changed the query
 * parameter order between versions (and appends wp_lang), so any
 * wp-login.php URL carrying action=rp is replaced.
 */
add_filter( 'retrieve_password_message', function ( $message, $key, $user_login ) {
	$law_url = law_auth_login_url(
		array(
			'action' => 'reset',
			'key'    => rawurlencode( $key ),
			'login'  => rawurlencode( $user_login ),
		)
	);
	$pattern = '#' . preg_quote( network_site_url( 'wp-login.php', 'login' ), '#' ) . '\?[^\s<>"]*action=rp[^\s<>"]*#';
	return preg_replace( $pattern, $law_url, $message );
}, 10, 3 );

/**
 * Redirect wp-login.php's GET screens to the branded pages so no visitor sees
 * the default WordPress screens. POST requests still go to core (the sign-in
 * form posts there), and logout, post passwords and privacy confirmations are
 * left alone.
 */
add_action( 'login_init', function () {
	if ( 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' ) ) {
		return;
	}
	if ( isset( $_GET['interim-login'] ) ) {
		return; // The wp-admin session-expired modal can't live on a page.
	}

	$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : 'login';

	switch ( $action ) {
		case 'login':
			$args = array();
			if ( isset( $_GET['loggedout'] ) ) {
				$args['loggedout'] = 'true';
			}
			if ( ! empty( $_GET['redirect_to'] ) ) {
				$redirect = wp_validate_redirect( wp_unslash( $_GET['redirect_to'] ), '' );
				if ( $redirect ) {
					$args['redirect_to'] = rawurlencode( $redirect );
				}
			}
			wp_safe_redirect( law_auth_login_url( $args ) );
			exit;

		case 'lostpassword':
		case 'retrievepassword':
			wp_safe_redirect( law_auth_login_url( array( 'action' => 'forgot' ) ) );
			exit;

		case 'rp':
		case 'resetpass':
			$args = array( 'action' => 'reset' );
			if ( isset( $_GET['key'], $_GET['login'] ) ) {
				$args['key']   = rawurlencode( sanitize_text_field( wp_unslash( $_GET['key'] ) ) );
				$args['login'] = rawurlencode( sanitize_text_field( wp_unslash( $_GET['login'] ) ) );
			}
			wp_safe_redirect( law_auth_login_url( $args ) );
			exit;

		case 'register':
			wp_safe_redirect( home_url( '/register/' ) );
			exit;
	}
	// Other actions (logout, postpass, confirm_action, …) run as normal.
} );
