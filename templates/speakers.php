<?php
/**
 * Template Name: Speakers
 *
 * Unique speakers across the public programme, read from the Speakers nested
 * form child entries (form 8). See functions/speakers.php.
 */

get_header();

$law_speakers = law_speakers();
?>

<?php if ( have_posts() ) : while ( have_posts() ) : the_post(); ?>
	<?php get_template_part( 'parts/layout/hero-title', null, array( 'classes' => 'register-page', 'content' => true ) ); ?>
<?php endwhile; endif; wp_reset_postdata(); ?>

<section class="page-section">
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<div class="law-speakers" data-speaker-search>

					<?php if ( $law_speakers ) : ?>

						<form class="law-speakers__search" role="search" onsubmit="return false;">
							<label class="show-for-sr" for="law-speakers-search"><?php esc_html_e( 'Search speakers', 'law' ); ?></label>
							<input
								type="search"
								id="law-speakers-search"
								data-speaker-search-input
								placeholder="<?php esc_attr_e( 'Search by name, role or organisation', 'law' ); ?>"
								autocomplete="off"
							>
						</form>

						<p class="law-speakers__count" data-speaker-search-count aria-live="polite"></p>

						<div class="grid-x grid-padding-x grid-padding-y law-speakers__grid wow fadeIn">
							<?php foreach ( $law_speakers as $law_speaker ) : ?>
								<?php get_template_part( 'parts/loop/speaker', null, array( 'speaker' => $law_speaker ) ); ?>
							<?php endforeach; ?>
						</div>

						<p class="law-speakers__empty" data-speaker-search-empty hidden><?php esc_html_e( 'No speakers match your search.', 'law' ); ?></p>

					<?php else : ?>
						<p class="law-speakers__empty"><?php esc_html_e( 'Speakers will be announced soon.', 'law' ); ?></p>
					<?php endif; ?>

				</div>
			</div>
		</div>
	</div>
</section>

<?php get_footer(); ?>
