<?php
/**
 * 404 template: the shared hero banner and a short message. Also what the
 * pre-launch Members gate on single event/speaker pages renders (see
 * functions/events/source.php), so it must never touch the queried post —
 * set_404() leaves the post in the query, and looping it here would leak a
 * gated event's title and content.
 */

get_header();

get_template_part(
	'parts/layout/hero-title',
	null,
	array(
		'title' => __( 'Page not found', 'law' ),
	)
);
?>

<section class="page-section">
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-9 cell">
				<div class="section-heading-text">
					<p><?php esc_html_e( 'Sorry, we can\'t find that page. It may have been moved or removed, or the address may have been typed incorrectly.', 'law' ); ?></p>
					<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Go to the homepage', 'law' ); ?></a></p>
				</div>
			</div>
		</div>
	</div>
</section>

<?php get_footer();
