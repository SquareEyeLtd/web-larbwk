<?php
/**
 * Thin account strip rendered directly below hero banners when the user is logged in.
 *
 * Reads ACF field `organisation` on the user (`user_{ID}`): array/post object of `organisation` CPT IDs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Human-readable organisation name(s) for the current user.
 *
 * @return string Comma-separated post titles; empty if none.
 */
function law_get_logged_in_user_organisation_label() {
	if ( ! is_user_logged_in() || ! function_exists( 'get_field' ) ) {
		return '';
	}

	$orgs = get_field( 'organisation', 'user_' . get_current_user_id() );

	if ( empty( $orgs ) ) {
		return '';
	}

	$titles = array();

	foreach ( (array) $orgs as $org ) {
		$id = is_object( $org ) ? (int) $org->ID : absint( $org );
		if ( ! $id || get_post_type( $id ) !== 'organisation' ) {
			continue;
		}

		$title = get_the_title( $id );
		if ( $title !== '' ) {
			$titles[] = $title;
		}
	}

	return implode( ', ', array_unique( array_filter( $titles ) ) );
}

/**
 * Echoes the banner status markup (no-op when logged out).
 */
function law_render_banner_account_status() {
	if ( ! is_user_logged_in() ) {
		return;
	}

	$user         = wp_get_current_user();
	$display_name = $user->display_name;
	$url          = trailingslashit( home_url( '/account' ) );
	$org_label    = law_get_logged_in_user_organisation_label();

	?>
	<div class="banner-account-status" role="status" aria-live="polite">
		<div class="banner-account-status__inner grid-container">
			<p class="banner-account-status__text">
				<span class="banner-account-status__prefix"><?php esc_html_e( 'Logged in as ', 'law' ); ?></span>
				<span class="banner-account-status__name">
					<a href="<?php echo esc_url( $url ); ?>"><?php echo esc_html( $display_name ); ?></a>
				</span>
				<?php if ( $org_label !== '' ) : ?>
					<span class="banner-account-status__sep" aria-hidden="true"> | </span>
					<span class="banner-account-status__org"><?php echo esc_html( $org_label ); ?></span>
				<?php endif; ?>
			</p>
		</div>
	</div>
	<?php
}
