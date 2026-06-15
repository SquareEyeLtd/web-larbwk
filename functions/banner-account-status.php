<?php
/**
 * Logged-in member status in the site header.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compact member status for the header top bar (no-op when logged out).
 */
function law_render_header_member_status() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$display_name = wp_get_current_user()->display_name;
	$account_url  = trailingslashit( home_url( '/account' ) );
	$logout_url   = wp_logout_url( home_url( '/' ) );

	?>
	<p class="header-member-status" role="status">
		<span class="header-member-status__label">
			<?php esc_html_e( 'Logged in as', 'law' ); ?>
			<a class="header-member-status__name" href="<?php echo esc_url( $account_url ); ?>"><?php echo esc_html( $display_name ); ?></a>
		</span>
		<span class="header-member-status__sep" aria-hidden="true">·</span>
		<a class="header-member-status__logout" href="<?php echo esc_url( $logout_url ); ?>"><?php esc_html_e( 'Log out', 'law' ); ?></a>
	</p>
	<?php
}
