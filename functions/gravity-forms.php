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



/* User registration > create & attach organisation ________________________________________________________ */


add_action( 'gform_after_submission_1', 'law_set_organisation_user_meta', 10, 2 );

function law_set_organisation_user_meta( $entry, $form ) {
    $user_id         = rgar( $entry, 'created_by' );
    $org_field_value = rgar( $entry, '7' );

    if ( $org_field_value === 'other' ) {
        $created_posts = gform_get_meta( $entry['id'], 'gravityformsadvancedpostcreation_post_id' );
        if ( ! empty( $created_posts ) ) {
            $post_id = $created_posts[0]['post_id'];
            update_field( 'organisation', [ $post_id ], 'user_' . $user_id );
        }
    } else {
        update_field( 'organisation', [ absint( $org_field_value ) ], 'user_' . $user_id );
    }
}



/* Populate current user organisation ________________________________________________________ */


add_filter( 'gform_field_value_orgid', 'law_prepopulate_orgid' );
function law_prepopulate_orgid( $value ) {
    $user_id = get_current_user_id();
    if ( ! $user_id ) return '';
    
    $orgs = get_field( 'organisation', 'user_' . $user_id );
    
    if ( ! empty( $orgs ) ) {
        $org_value = is_object( $orgs[0] ) ? $orgs[0]->ID : (int) $orgs[0];
        return (string) $org_value;
    }
    
    return '';
}


