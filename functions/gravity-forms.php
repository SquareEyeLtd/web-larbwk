<?php
	
/**
 * Gravity Perks // Populate Anything // Add a Static Choice
 * https://gravitywiz.com/documentation/gravity-forms-populate-anything/
 *
 * Instruction Video: https://www.loom.com/share/e425398f584148f58fdfea3d6b6f969b
 *
 */
 
add_filter( 'gppa_input_choices_1_7', function( $choices, $field, $objects ) {

	array_unshift( $choices, array(
		'text'       => 'My organisation is not listed',
		'value'      => 'other',
		'isSelected' => false,
	) );

	return $choices;
}, 10, 3 );