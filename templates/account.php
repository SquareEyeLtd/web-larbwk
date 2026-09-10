<?php
/**
 * Template Name: Account
 *
 * The account landing page, styled like the other account pages (login,
 * register): the page content renders inside the full-height purple hero.
 * The [action-message] shortcode in the content shows a status panel for
 * states such as /account/?action=registered.
 *
 * @package LAW
 */

get_header();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
<section class="hero auth-hero hero-solid">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<h1><?php the_title(); ?></h1>
			</div>
			<div class="large-9 cell auth-intro wow fadeIn">
				<?php
				// Never render an empty body. The copy is editor content built from
				// role-gated [user-content] blocks, so a signed-in audience that no
				// block covers gets a heading and nothing else — which is how a
				// sponsor-only user reached a blank /account/. Buffering the content
				// and asking whether anything visible came out lets one generic
				// fallback catch that case; writing the per-audience wording into the
				// template instead would put a second copy of it next to the
				// editor's, and the two would drift.
				ob_start();
				the_content();
				$law_account_body = (string) ob_get_clean();

				if ( '' !== trim( wp_strip_all_tags( $law_account_body ) ) ) {
					echo $law_account_body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content() output, already filtered.
				} else {
					?>
					<p>Welcome to London Arbitration Week. Your account is ready.</p>
					<p><?php if ( function_exists( 'law_account_user_is_host_like' ) && law_account_user_is_host_like() ) : ?>
						<a href="<?php echo esc_url( law_account_url( 'submit' ) ); ?>">Submit an event</a>,
						<a href="<?php echo esc_url( law_account_url( 'events' ) ); ?>">view your events</a>,
						<a href="<?php echo esc_url( law_account_url( 'my_bookings' ) ); ?>">view your bookings</a>
						or <a href="<?php echo esc_url( law_account_url( 'profile' ) ); ?>">update your profile</a>.
					<?php else : ?>
						<a href="<?php echo esc_url( law_account_url( 'my_bookings' ) ); ?>">View your bookings</a>
						or <a href="<?php echo esc_url( law_account_url( 'profile' ) ); ?>">update your profile</a>.
					<?php endif; ?></p>
					<?php
				}
				?>
			</div>
		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
