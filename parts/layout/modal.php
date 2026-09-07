<?php
/**
 * Reusable confirmation modal: a small dialog that confirms an action before it
 * fires, optionally taking a note first. Pair with functions/modal.php,
 * assets/css/law-modal.css and assets/js/law-modal.js.
 *
 * Render it INSIDE the form whose button it confirms: the dialog carries the
 * submit button that actually posts the action, so everything else on that form
 * posts with it. The opener button needs data-law-modal-open="<the id>".
 *
 * get_template_part( 'parts/layout/modal', null, array(
 *   'id'      => 'law-modal-approve',      // Required. Unique on the page.
 *   'title'   => 'Approve this event',     // Required.
 *   'copy'    => 'One paragraph.',         // String, or an array of strings,
 *                                          // one paragraph each. Optional.
 *   'field'   => array(                    // Optional; omit for a plain
 *     'name'     => 'law_note',            // confirmation with no input.
 *     'label'    => 'What needs changing?',
 *     'help'     => 'Small print under the label.',
 *     'rows'     => 4,                     // Default 4.
 *     'required' => true,                  // Default false.
 *     'error'    => 'Please tell the host why.',
 *     'value'    => '',                    // Prefilled text. Optional.
 *   ),
 *   'confirm' => array(                    // The submit button in the dialog,
 *     'label' => 'Approve',                // Default 'Confirm'.       or false
 *     'name'  => 'law_action',             // Optional; omitted if empty.
 *     'value' => 'approve',
 *     'class' => 'button orange',          // Default 'button'.
 *     'busy'  => 'Approving…',             // Optional in-flight label, rendered
 *   ),                                     // as data-law-modal-busy for a
 *                                          // script that submits over fetch.
 *   'close'   => 'Close',                  // Secondary button. Default 'Cancel'.
 * ) );
 *
 * 'confirm' => false renders an informational dialog with no submit button at
 * all (just the copy and the close controls), for a script-opened result
 * message.
 *
 * The modal starts hidden and stays hidden without JavaScript, so the opener
 * button must work on its own as a plain submit as well.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$args = isset( $args ) && is_array( $args ) ? $args : array();

$law_modal_id    = isset( $args['id'] ) ? trim( (string) $args['id'] ) : '';
$law_modal_title = isset( $args['title'] ) ? trim( (string) $args['title'] ) : '';

// Nothing to label the dialog with, or nothing for the opener to find: better
// to render nothing at all than a dialog no one can reach or close.
if ( '' === $law_modal_id || '' === $law_modal_title ) {
	return;
}

// The component's own CSS and JS, so a caller anywhere in the theme gets them
// without a second thought. Harmless to call more than once.
law_modal_enqueue();

$law_modal_copy = $args['copy'] ?? array();
$law_modal_copy = is_array( $law_modal_copy ) ? $law_modal_copy : array( $law_modal_copy );

$law_modal_close = isset( $args['close'] ) && '' !== trim( (string) $args['close'] ) ? trim( (string) $args['close'] ) : 'Cancel';

// false, exactly, means no submit button: an informational dialog.
$law_modal_no_confirm = isset( $args['confirm'] ) && false === $args['confirm'];

$law_modal_confirm = isset( $args['confirm'] ) && is_array( $args['confirm'] ) ? $args['confirm'] : array();
$law_modal_confirm = array(
	'label' => isset( $law_modal_confirm['label'] ) && '' !== trim( (string) $law_modal_confirm['label'] ) ? trim( (string) $law_modal_confirm['label'] ) : 'Confirm',
	'name'  => isset( $law_modal_confirm['name'] ) ? trim( (string) $law_modal_confirm['name'] ) : '',
	'value' => isset( $law_modal_confirm['value'] ) ? (string) $law_modal_confirm['value'] : '',
	'class' => isset( $law_modal_confirm['class'] ) && '' !== trim( (string) $law_modal_confirm['class'] ) ? trim( (string) $law_modal_confirm['class'] ) : 'button',
	'busy'  => isset( $law_modal_confirm['busy'] ) ? trim( (string) $law_modal_confirm['busy'] ) : '',
);

$law_modal_field = isset( $args['field'] ) && is_array( $args['field'] ) ? $args['field'] : array();
if ( $law_modal_field ) {
	$law_modal_field = array(
		'name'     => isset( $law_modal_field['name'] ) ? trim( (string) $law_modal_field['name'] ) : '',
		'label'    => isset( $law_modal_field['label'] ) ? trim( (string) $law_modal_field['label'] ) : '',
		'help'     => isset( $law_modal_field['help'] ) ? trim( (string) $law_modal_field['help'] ) : '',
		'rows'     => isset( $law_modal_field['rows'] ) ? max( 1, (int) $law_modal_field['rows'] ) : 4,
		'required' => ! empty( $law_modal_field['required'] ),
		'error'    => isset( $law_modal_field['error'] ) && '' !== trim( (string) $law_modal_field['error'] ) ? trim( (string) $law_modal_field['error'] ) : 'Please complete this field.',
		'value'    => isset( $law_modal_field['value'] ) ? (string) $law_modal_field['value'] : '',
	);
	// A field with no name would post nothing, so treat it as no field at all.
	if ( '' === $law_modal_field['name'] ) {
		$law_modal_field = array();
	}
}

$law_modal_field_id = $law_modal_id . '-field';

// The label, built here so the markup below stays on one line: an optional
// line of small print under the label text, the way the rest of the account
// forms do it.
$law_modal_field_label = '';
if ( $law_modal_field ) {
	$law_modal_field_label = esc_html( $law_modal_field['label'] );
	if ( '' !== $law_modal_field['help'] ) {
		$law_modal_field_label .= '<br><small>' . esc_html( $law_modal_field['help'] ) . '</small>';
	}
}

// tabindex="-1" on the dialog so law-modal.js can put focus inside it when
// there is no field to focus (a plain confirmation).
?>
<div class="law-modal" id="<?php echo esc_attr( $law_modal_id ); ?>" hidden>
	<div class="law-modal__overlay" data-law-modal-close></div>
	<div class="law-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="<?php echo esc_attr( $law_modal_id ); ?>-title" tabindex="-1">
		<button type="button" class="law-modal__close" data-law-modal-close aria-label="Close">&times;</button>
		<h2 class="law-modal__title" id="<?php echo esc_attr( $law_modal_id ); ?>-title"><?php echo esc_html( $law_modal_title ); ?></h2>
		<?php
		// wp_kses_post() rather than esc_html() so a paragraph can carry an
		// inline link (a policy page, an invoice) without a second argument.
		foreach ( $law_modal_copy as $law_modal_paragraph ) {
			if ( '' === trim( (string) $law_modal_paragraph ) ) {
				continue;
			}
			echo "\t\t" . '<p class="law-modal__copy">' . wp_kses_post( (string) $law_modal_paragraph ) . "</p>\n";
		}
		?>
		<?php if ( $law_modal_field ) : ?>
			<?php
			// The textarea ships disabled and law-modal.js enables it only while
			// this modal is open, so a page carrying several modals still posts
			// one value for the field name. aria-required, never the native
			// required attribute: a required control inside a hidden ancestor
			// makes the whole form unsubmittable in Chrome. The JS does the
			// check itself and shows data-law-modal-error under the field.
			?>
			<p class="law-form-field"><label for="<?php echo esc_attr( $law_modal_field_id ); ?>"><?php echo $law_modal_field_label; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></label>
				<textarea id="<?php echo esc_attr( $law_modal_field_id ); ?>" name="<?php echo esc_attr( $law_modal_field['name'] ); ?>" rows="<?php echo esc_attr( (string) $law_modal_field['rows'] ); ?>" data-law-modal-field<?php if ( $law_modal_field['required'] ) : ?> aria-required="true" data-law-modal-error="<?php echo esc_attr( $law_modal_field['error'] ); ?>"<?php endif; ?> disabled><?php echo esc_textarea( $law_modal_field['value'] ); ?></textarea></p>
		<?php endif; ?>
		<p class="law-modal__actions">
			<button type="button" class="button second" data-law-modal-close><?php echo esc_html( $law_modal_close ); ?></button>
			<?php if ( ! $law_modal_no_confirm ) : ?>
				<button type="submit"<?php if ( '' !== $law_modal_confirm['name'] ) : ?> name="<?php echo esc_attr( $law_modal_confirm['name'] ); ?>" value="<?php echo esc_attr( $law_modal_confirm['value'] ); ?>"<?php endif; ?><?php if ( '' !== $law_modal_confirm['busy'] ) : ?> data-law-modal-busy="<?php echo esc_attr( $law_modal_confirm['busy'] ); ?>"<?php endif; ?> class="<?php echo esc_attr( $law_modal_confirm['class'] ); ?>"><?php echo esc_html( $law_modal_confirm['label'] ); ?></button>
			<?php endif; ?>
		</p>
	</div>
</div>
