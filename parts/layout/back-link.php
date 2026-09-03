<?php
/**
 * Back link, aligned with the page content (same grid cell padding).
 * Used on the single event view and the single speaker profile.
 *
 * get_template_part( 'parts/layout/back-link', null, array(
 *   'url'   => '',       // Destination. Required.
 *   'label' => 'Back',   // Link text.
 * ) );
 *
 * Render it inside a .grid-container; it brings its own grid-x/cell wrapper.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_back_url   = isset( $args['url'] ) ? (string) $args['url'] : '';
$law_back_label = isset( $args['label'] ) && '' !== (string) $args['label'] ? (string) $args['label'] : __( 'Back', 'law' );

if ( '' === $law_back_url ) {
	return;
}
?>
<div class="grid-x grid-padding-x">
	<div class="large-12 cell">
		<a class="law-back-link" href="<?php echo esc_url( $law_back_url ); ?>"><?php echo esc_html( $law_back_label ); ?></a>
	</div>
</div>
