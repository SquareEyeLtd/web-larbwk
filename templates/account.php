<?php
/**
 * Template Name: Account
 *
 * An account page whose body is editor content, rendered inside the
 * full-height purple hero. Since 14 September 2026 that means the submission
 * confirmation at /account/events/submit/done/ ("Event submitted"), which is
 * the one such page left: /account/ itself is the tile-based Account hub now
 * (templates/account-hub.php), built in code.
 *
 * Kept rather than merged into the hub precisely because of that confirmation
 * page, whose wording is the editor's to change.
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
				// fallback catch that case.
				//
				// The fallback is one sentence now, pointing at the hub, rather
				// than the per-audience link list it used to be: the hub IS that
				// list, and keeping a second copy here is how the two would
				// drift apart.
				ob_start();
				the_content();
				$law_account_body = (string) ob_get_clean();

				if ( '' !== trim( wp_strip_all_tags( $law_account_body ) ) ) {
					echo $law_account_body; // phpcs:ignore WordPress.Security.EscapeOutput -- the_content() output, already filtered.
				} else {
					?>
					<p>Welcome to London Arbitration Week.
						<a href="<?php echo esc_url( law_account_url( 'account' ) ); ?>">Go to your account</a>.</p>
					<?php
				}
				?>
			</div>
		</div>
	</div>
</section>
<?php endwhile; endif; ?>

<?php get_footer(); ?>
