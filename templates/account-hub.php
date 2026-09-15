<?php
/**
 * Template Name: Account hub
 *
 * /account/: the signed-in landing page, and where a sign-in lands unless a
 * redirect_to said otherwise (law_auth_default_redirect()). A grid of linked
 * boxes, in the manner of WooCommerce's My account, replacing the page of
 * role-gated editor copy that used to live here.
 *
 * It arrived with the retirement of the event_host, sponsor and attendee roles
 * on 14 September 2026 (ROLES_AND_ACCOUNT_HUB.md). Once everybody is a plain
 * subscriber and everybody may submit an event and book a place, one landing
 * page serves the lot, and what differs between two people is which errands
 * they happen to have, not what they are allowed to do.
 *
 * The tiles come from law_header_nav(), the same list the top bar renders, so
 * the two can never disagree about what somebody has. This template builds no
 * list of its own; see the note in functions/header-nav.php.
 *
 * A NEW template rather than a rewrite of templates/account.php, which still
 * serves the "Event submitted" confirmation page at
 * /account/events/submit/done/ and its real editor content.
 */

// Per-user content: never let a proxy or the browser hand one person's account
// to the next, as the bookings page and the committee dashboards guard theirs.
nocache_headers();

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<?php get_template_part( 'parts/layout/hero-title' ); ?>

<section class="page-section">
	<div class="grid-container law-account-hub">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">

				<?php
				/*
				 * The signed-out branch is load-bearing, not defensive. The
				 * Members plugin's content permissions filter the_content(),
				 * and this template never calls it, so the plugin's restriction
				 * on /account/ cannot reach the markup below. Every page that
				 * renders its own content this way carries its own check (the
				 * committee dashboards ask law_user_is_committee()).
				 */
				if ( ! is_user_logged_in() ) :
					?>
					<p>Please <a href="<?php echo esc_url( function_exists( 'law_auth_login_url' ) ? law_auth_login_url( array( 'redirect_to' => rawurlencode( (string) get_permalink() ) ) ) : wp_login_url( (string) get_permalink() ) ); ?>">sign in</a> to see your account.</p>
					<?php
				else :
					/*
					 * [action-message] is why the page still has editor content
					 * at all: law_registration_handler() sends a new account to
					 * /account/?action=registered, and this is what renders the
					 * "Registration successful" callout. Rendered directly
					 * rather than through the_content(), so a page body someone
					 * has emptied by hand cannot lose the confirmation.
					 */
					echo do_shortcode( '[action-message]' );

					$law_hub_nav   = function_exists( 'law_header_nav' ) ? law_header_nav() : array();
					$law_hub_items = (array) ( $law_hub_nav['account']['items'] ?? array() );
					$law_hub_name  = (string) ( $law_hub_nav['account']['name'] ?? '' );
					?>
					<?php if ( '' !== $law_hub_name ) : ?>
						<p class="law-account-hub__lead">Signed in as <strong><?php echo esc_html( $law_hub_name ); ?></strong>.</p>
					<?php endif; ?>

					<?php
					// Above the tiles, below the "Signed in as" line: a free
					// place somebody has not claimed is exactly what a landing
					// page should be telling them. The same helper My bookings
					// calls, so the two can never word it differently
					// (RECEPTIONS.md §7.3).
					//
					// The assets it needs are all wired for this template:
					// calendar.css for .law-strip (enqueue.php), and
					// law-modal.css + booking-form.js for the dialog and its
					// fetch submit (account-bookings.php, gated on
					// law_reception_banner_state()). event-form.css is NOT
					// loaded here and does not need to be: the dialog is
					// law-modal.css throughout.
					if ( function_exists( 'law_reception_banner' ) ) {
						echo law_reception_banner( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built escaped.
					}
					?>

					<?php get_template_part( 'parts/layout/account-tiles', null, array( 'items' => $law_hub_items ) ); ?>
					<?php
				endif;
				?>

			</div>
		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
