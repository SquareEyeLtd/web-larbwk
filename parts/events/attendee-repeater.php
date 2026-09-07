<?php
/**
 * Repeatable additional-attendee rows for the booking form (full name, email,
 * organisation, job title). Starts with NO visible rows: most bookers bring
 * nobody, and three open rows of four fields would swamp the dialog.
 *
 * Same template-row contract as people-repeater.php but with its own JS hooks
 * (data-law-booking-*), because event-form.js also drives data-law-rows-group
 * repeaters and both scripts load together on the account pages.
 *
 * Args: group (POST array name, default law_attendees), label, rows
 * (repopulation), max (visible-row cap), invalid (['row' => i, 'field' => name]
 * from a refused no-JS submission).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$law_group   = (string) ( $args['group'] ?? 'law_attendees' );
$law_label   = (string) ( $args['label'] ?? __( 'Additional attendees', 'law' ) );
$law_rows    = array_values( array_filter( (array) ( $args['rows'] ?? array() ), 'is_array' ) );
$law_max     = max( 0, (int) ( $args['max'] ?? law_booking_max_additional() ) );
$law_invalid = (array) ( $args['invalid'] ?? array() );

$law_fields = array(
	'name'         => array( __( 'Full name *', 'law' ), 'text' ),
	'email'        => array( __( 'Email *', 'law' ), 'email' ),
	'organisation' => array( __( 'Organisation', 'law' ), 'text' ),
	'job_title'    => array( __( 'Job title', 'law' ), 'text' ),
);

$law_rows   = array_slice( $law_rows, 0, $law_max );
$law_rows[] = array(); // The hidden template row, always last.
?>
<div class="law-form-field law-people law-booking-people">
	<span class="law-form-label"><?php echo esc_html( $law_label ); ?></span>
	<div class="law-rows" data-law-booking-rows="<?php echo esc_attr( $law_group ); ?>" data-law-max="<?php echo esc_attr( (string) $law_max ); ?>">
		<?php foreach ( $law_rows as $law_i => $law_row ) :
			$law_is_template = $law_i === count( $law_rows ) - 1;
			$law_attr        = $law_is_template ? 'data-name' : 'name';
			$law_index       = $law_is_template ? '__i__' : $law_i;
			?>
			<div class="law-row" <?php echo $law_is_template ? 'data-law-booking-row-template hidden' : ''; ?>>
				<button type="button" class="law-row-remove" data-law-booking-remove aria-label="<?php esc_attr_e( 'Remove attendee', 'law' ); ?>">&times;</button>
				<div class="law-row-grid law-row-grid--attendee">
					<?php foreach ( $law_fields as $law_key => $law_field ) :
						$law_is_invalid = ! $law_is_template
							&& isset( $law_invalid['row'], $law_invalid['field'] )
							&& (int) $law_invalid['row'] === $law_i
							&& $law_invalid['field'] === $law_key;
						?>
						<?php
					// aria-required, never native required: a required control
					// inside the hidden modal blocks the whole form in Chrome
					// (the modal.php house rule). The server enforces it with
					// row-keyed errors either way.
					$law_required = in_array( $law_key, array( 'name', 'email' ), true ) ? ' aria-required="true"' : '';
					?>
					<label<?php echo $law_is_invalid ? ' class="is-invalid"' : ''; ?>><?php echo esc_html( $law_field[0] ); ?><input type="<?php echo esc_attr( $law_field[1] ); ?>" autocomplete="off"<?php echo $law_required; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- literal attribute. ?> <?php echo esc_attr( $law_attr ); ?>="<?php echo esc_attr( "{$law_group}[{$law_index}][{$law_key}]" ); ?>" value="<?php echo esc_attr( (string) ( $law_row[ $law_key ] ?? '' ) ); ?>"></label>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endforeach; ?>
	</div>
	<?php if ( $law_max > 0 ) : ?>
		<button type="button" class="button law-row-add" data-law-booking-add="<?php echo esc_attr( $law_group ); ?>"><?php esc_html_e( 'Add a colleague', 'law' ); ?></button>
	<?php else : ?>
		<span class="law-form-error" role="alert"><?php esc_html_e( 'No places are left for additional attendees.', 'law' ); ?></span>
	<?php endif; ?>
</div>
