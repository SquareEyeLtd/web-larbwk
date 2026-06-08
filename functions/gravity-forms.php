<?php
	
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
	
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



/* Admin styling for hidden & administrative fields ________________________________________________________ */


add_action( 'admin_head', 'sqe_gf_editor_visibility_tint' );
function sqe_gf_editor_visibility_tint() {
	// Form editor only (page=gf_edit_forms with no view = the editor canvas).
	$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
	$view = isset( $_GET['view'] ) ? sanitize_key( $_GET['view'] ) : '';
	if ( 'gf_edit_forms' !== $page || '' !== $view ) {
		return;
	}
	?>
	<style id="sqe-gf-visibility-tint">
		/* Two class conventions are targeted for version-safety. */
		#gform_fields .gfield.field_visibility_administrative,
		#gform_fields .gfield.gfield_visibility_administrative,
		#gform_fields .gfield.field_visibility_hidden,
		#gform_fields .gfield.gfield_visibility_hidden {
			position: relative;
		}

		/* Administrative — amber */
		#gform_fields .gfield.field_visibility_administrative,
		#gform_fields .gfield.gfield_visibility_administrative {
			background: #fff8e1;
			box-shadow: inset 4px 0 0 #f59e0b;
		}

		/* Hidden — slate */
		#gform_fields .gfield.field_visibility_hidden,
		#gform_fields .gfield.gfield_visibility_hidden {
			background: #eef2f7;
			box-shadow: inset 4px 0 0 #64748b;
		}

		/* Corner badge */
		#gform_fields .gfield.field_visibility_administrative::after,
		#gform_fields .gfield.gfield_visibility_administrative::after,
		#gform_fields .gfield.field_visibility_hidden::after,
		#gform_fields .gfield.gfield_visibility_hidden::after {
			position: absolute;
			top: 6px;
			right: 6px;
			z-index: 2;
			padding: 2px 6px;
			border-radius: 3px;
			font-size: 10px;
			font-weight: 600;
			letter-spacing: .04em;
			text-transform: uppercase;
			color: #fff;
			pointer-events: none;
		}
		#gform_fields .gfield.field_visibility_administrative::after,
		#gform_fields .gfield.gfield_visibility_administrative::after {
			content: "Admin";
			background: #f59e0b;
		}
		#gform_fields .gfield.field_visibility_hidden::after,
		#gform_fields .gfield.gfield_visibility_hidden::after {
			content: "Hidden";
			background: #64748b;
		}
	</style>
	<?php
}

