<?php
/**
 * Shared page hero: background image, overlay and title.
 *
 * Use with get_template_part( 'parts/layout/hero-title', null, $args ):
 *   title    (string) Heading text. Defaults to the current post title.
 *   is_event (bool)   Render the title as a paragraph instead of an <h1>, for
 *                     pages where the real <h1> lives further down the page
 *                     (the calendar's single event view).
 *   image    (string) Background image URL. Defaults to the shared page banner.
 *   classes  (string) Extra classes on the <section>, e.g. 'register-page'.
 *   content  (bool)   Output the_content() below the title.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_hero_title    = array_key_exists( 'title', $args ) ? (string) $args['title'] : get_the_title();
$law_hero_is_event = ! empty( $args['is_event'] );
$law_hero_image    = ! empty( $args['image'] ) ? (string) $args['image'] : law_asset( 'assets/images/patrons-and-committee-bg.jpg' );
$law_hero_classes  = trim( 'hero ' . (string) ( $args['classes'] ?? '' ) );
$law_hero_content  = ! empty( $args['content'] );
?>
<section class="<?php echo esc_attr( $law_hero_classes ); ?>" style="background-image: url('<?php echo esc_url( $law_hero_image ); ?>');">
	<div class="overlay"></div>
	<div class="grid-container">
		<div class="grid-x grid-padding-x">
			<div class="large-12 cell">
				<?php if ( $law_hero_is_event ) : ?>
					<p class="law-cal-banner-title"><?php echo esc_html( $law_hero_title ); ?></p>
				<?php else : ?>
					<h1><?php echo esc_html( $law_hero_title ); ?></h1>
				<?php endif; ?>
			</div>
			<?php if ( $law_hero_content ) : ?>
				<div class="large-9 cell wow fadeIn">
					<?php the_content(); ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
