<?php
/**
 * Repeatable name / organisation / email rows for the event form.
 *
 * Args: group (POST array name), label, rows.
 */

$law_group = (string) ( $args['group'] ?? '' );
$law_label = (string) ( $args['label'] ?? '' );
$law_rows  = (array) ( $args['rows'] ?? array() );
if ( '' === $law_group ) {
	return;
}
$law_rows[] = array(); // Blank template row.
?>
<div class="law-form-field law-people">
	<span class="law-form-label"><?php echo esc_html( $law_label ); ?></span>
	<div class="law-rows" data-law-rows-group="<?php echo esc_attr( $law_group ); ?>">
		<?php foreach ( $law_rows as $law_i => $law_row ) :
			$law_is_template = $law_i === count( $law_rows ) - 1;
			$law_attr        = $law_is_template ? 'data-name' : 'name';
			$law_index       = $law_is_template ? '__i__' : $law_i;
			?>
			<div class="law-row" <?php echo $law_is_template ? 'data-law-row-template hidden' : ''; ?>>
				<button type="button" class="law-row-remove" aria-label="Remove row">×</button>
				<div class="law-row-grid law-row-grid--three">
					<label>Name<input type="text" <?php echo esc_attr( $law_attr ); ?>="<?php echo esc_attr( "{$law_group}[{$law_index}][name]" ); ?>" value="<?php echo esc_attr( (string) ( $law_row['name'] ?? '' ) ); ?>"></label>
					<label>Organisation<input type="text" <?php echo esc_attr( $law_attr ); ?>="<?php echo esc_attr( "{$law_group}[{$law_index}][organisation]" ); ?>" value="<?php echo esc_attr( (string) ( $law_row['organisation'] ?? '' ) ); ?>"></label>
					<label>Email<input type="email" <?php echo esc_attr( $law_attr ); ?>="<?php echo esc_attr( "{$law_group}[{$law_index}][email]" ); ?>" value="<?php echo esc_attr( (string) ( $law_row['email'] ?? '' ) ); ?>"></label>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<button type="button" class="button law-row-add" data-law-add="<?php echo esc_attr( $law_group ); ?>">Add row</button>
</div>
