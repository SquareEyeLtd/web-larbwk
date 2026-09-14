<?php
/**
 * The account hub's tiles: one linked box per item, grouped.
 *
 * Args: items (array) — law_header_nav()['account']['items'], already gated,
 * URL-resolved and ordered. This part renders that list and decides nothing
 * about who sees what; asking a second set of questions here is how the hub
 * and the top bar would start disagreeing.
 *
 * Groups render in a fixed order (personal, committee, then sign out) and an
 * empty group renders nothing at all, so a person with no committee tools sees
 * no empty heading.
 *
 * @see functions/header-nav.php  Where the items, their icons and their
 *                                descriptions are decided.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_tiles_items = (array) ( $args['items'] ?? array() );
if ( ! $law_tiles_items ) {
	return;
}

$law_tiles_groups = array(
	'personal'  => __( 'Your account', 'law' ),
	'committee' => __( 'Committee tools', 'law' ),
	'signout'   => '', // No heading: one tile, and its label says what it is.
);

// An item with no group is personal. Nothing produces one today; the default
// keeps a future item from vanishing because somebody forgot the key.
$law_tiles_by_group = array_fill_keys( array_keys( $law_tiles_groups ), array() );
foreach ( $law_tiles_items as $law_tile ) {
	$law_group = (string) ( $law_tile['group'] ?? 'personal' );
	if ( ! isset( $law_tiles_by_group[ $law_group ] ) ) {
		$law_group = 'personal';
	}
	$law_tiles_by_group[ $law_group ][] = $law_tile;
}
?>

<?php foreach ( $law_tiles_groups as $law_group_key => $law_group_label ) : ?>
	<?php
	$law_group_items = $law_tiles_by_group[ $law_group_key ];
	if ( ! $law_group_items ) {
		continue;
	}
	$law_group_id = 'law-hub-' . $law_group_key;
	?>
	<section class="law-account-hub__group"<?php echo '' !== $law_group_label ? ' aria-labelledby="' . esc_attr( $law_group_id ) . '"' : ''; ?>>
		<?php if ( '' !== $law_group_label ) : ?>
			<h2 id="<?php echo esc_attr( $law_group_id ); ?>" class="law-account-hub__heading"><?php echo esc_html( $law_group_label ); ?></h2>
		<?php endif; ?>
		<?php // role="list": the CSS removes the bullets, and Safari then drops the list semantics with them. ?>
		<ul class="law-account-hub__tiles" role="list">
			<?php foreach ( $law_group_items as $law_tile ) : ?>
				<li>
					<a class="law-account-hub__tile law-account-hub__tile--<?php echo esc_attr( (string) $law_tile['key'] ); ?>" href="<?php echo esc_url( (string) $law_tile['url'] ); ?>">
						<?php
						// Pre-escaped markup from the theme's own icon table,
						// not user input; law_icon() returns '' for an unknown
						// key, so an item with no icon simply has none.
						echo law_icon( (string) ( $law_tile['icon'] ?? '' ), 'law-account-hub__icon', 32 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						?>
						<span class="law-account-hub__label"><?php echo esc_html( (string) $law_tile['label'] ); ?></span>
						<?php if ( '' !== (string) ( $law_tile['description'] ?? '' ) ) : ?>
							<span class="law-account-hub__desc"><?php echo esc_html( (string) $law_tile['description'] ); ?></span>
						<?php endif; ?>
					</a>
				</li>
			<?php endforeach; ?>
		</ul>
	</section>
<?php endforeach; ?>
