<?php
/**
 * Shared page hero: background image, overlay and title.
 *
 * Use with get_template_part( 'parts/layout/hero-title', null, $args ):
 *   title    (string) Heading text. Defaults to the current post title.
 *   is_event (bool)   Render the title as a paragraph instead of an <h1>, for
 *                     pages where the real <h1> lives further down the page
 *                     (the calendar's single event view).
 *   image    (string|false) Background image URL. Defaults to the shared page
 *                     banner; pass false or '' to render without one.
 *   classes  (string) Extra classes on the <section>, e.g. 'register-page'.
 *   solid    (bool)   Render the hero on flat brand navy: no photograph, and
 *                     the overlay at full opacity instead of the translucent
 *                     one. Use it on pages whose hero carries a form (sign in,
 *                     register, profile, submit/edit an event), where the
 *                     photograph showing through costs contrast on the labels
 *                     and inputs and buys nothing. Implies image => false.
 *   content  (bool)   Output the_content() below the title.
 *   text     (string) HTML to output below the title instead of the_content(),
 *                     e.g. an ACF banner text field.
 *   after_title (string) Pre-escaped HTML for a full-width cell below the title.
 *                     The caller owns the escaping, because this is markup, not
 *                     copy: it is NOT run through wp_kses_post(), which would
 *                     strip inline <svg>. The single event view uses it for the
 *                     event details box (parts/calendar-event-details.php).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_hero_title    = array_key_exists( 'title', $args ) ? (string) $args['title'] : get_the_title();
$law_hero_is_event = ! empty( $args['is_event'] );
$law_hero_solid    = ! empty( $args['solid'] );
$law_hero_image    = array_key_exists( 'image', $args ) ? (string) $args['image'] : law_hero_default_image_url();
$law_hero_classes  = trim( 'hero ' . ( $law_hero_solid ? 'hero-solid ' : '' ) . (string) ( $args['classes'] ?? '' ) );
$law_hero_content  = ! empty( $args['content'] );
$law_hero_text     = isset( $args['text'] ) ? (string) $args['text'] : '';
$law_hero_after    = isset( $args['after_title'] ) ? (string) $args['after_title'] : '';

// A fully opaque overlay hides the photograph, so don't make the browser
// fetch it at all.
if ( $law_hero_solid ) {
	$law_hero_image = '';
}
?>
<section class="<?php echo esc_attr( $law_hero_classes ); ?>"<?php if ( $law_hero_image ) : ?> style="background-image: url('<?php echo esc_url( $law_hero_image ); ?>');"<?php endif; ?>>
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
			<?php elseif ( $law_hero_text ) : ?>
				<div class="large-9 cell wow fadeIn">
					<?php echo wp_kses_post( $law_hero_text ); ?>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $law_hero_after ) : ?>
				<div class="large-12 cell">
					<?php echo $law_hero_after; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-escaped by the caller, see the after_title note above. ?>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>
