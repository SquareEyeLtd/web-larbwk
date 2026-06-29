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


/* Allow drag and drop on Advanced Select fields  ________________________________________________________ */

add_action( 'gform_enqueue_scripts', function() {
	wp_enqueue_script( 'jquery-ui-sortable' );
} );


/* Insert latest comment field data into notifications ________________________________________________________ */

add_filter( 'gform_replace_merge_tags', function ( $text, $form, $entry ) {
	if ( false === strpos( (string) $text, '{latest_comment}' ) || ! function_exists( 'gp_nested_forms' ) ) {
		return $text;
	}

	$parent_field_id = 99;   // Comments
	$comment_field   = 1;    // "Your comment" child field
	// Name is a composite field: 3.3 first, 3.6 last

	$children = gp_nested_forms()->get_entries( rgar( $entry, $parent_field_id ) );
	if ( empty( $children ) ) {
		return str_replace( '{latest_comment}', '', $text );
	}

	// Child entries come oldest-first; take the last as the latest.
	$latest  = end( $children );
	$name    = trim( rgar( $latest, '3.3' ) . ' ' . rgar( $latest, '3.6' ) );
	$comment = rgar( $latest, $comment_field );

	$out = '<strong>' . esc_html( $name ) . '</strong>: ' . $comment;

	return str_replace( '{latest_comment}', $out, $text );
}, 10, 3 );


/* Notify committee assignee when field 90 changes on entry edit ____________________________________________ */

/**
 * GF form 2 notification ID for the committee "entry edited" alert.
 * Form → Settings → Notifications → open the notification → ID is in the admin URL.
 */
const LAW_GF_EVENT_EDITED_NOTIFICATION_ID = '6a400d916e3fd';

/**
 * Notify the new Committee assignee when field 90 changes on an entry edit.
 * Field 90 stores the assignee's email (Populate Anything → user email as value).
 */
add_action( 'gform_post_update_entry_2', function ( $entry, $original_entry ) {

	$old = (string) rgar( $original_entry, '90' );
	$new = (string) rgar( $entry, '90' );

	// Only when it actually changed, and a new assignee is set (not cleared).
	if ( $old === $new || ! is_email( $new ) ) {
		return;
	}

	$title = rgar( $entry, '17' ); // Event title
	$ref   = rgar( $entry, '70' ); // LAW reference
	$link  = 'https://londonarbitrationweek.co.uk/account/dashboard/'; // committee single-entry URL

	$subject = sprintf( 'You have been assigned an event: %s (%s)', $title, $ref );
	$body    = '<p>You have been assigned as the committee contact for '
		. '<strong>' . esc_html( $title ) . '</strong> (' . esc_html( $ref ) . ').</p>'
		. '<p><a href="' . esc_url( $link ) . '">View it in the committee dashboard</a></p>';

	wp_mail( $new, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );

}, 10, 2 );


/* Only notify committee of entry edits when an event host made the change ________________________________ */

/**
 * Only send the "event edited" committee notification when the editor
 * is NOT committee/admin — i.e. a host edited their own event.
 */
add_filter( 'gform_disable_notification_2', function ( $is_disabled, $notification, $form, $entry ) {

	// Target only the edit-alert notification.
	if ( rgar( $notification, 'id' ) !== LAW_GF_EVENT_EDITED_NOTIFICATION_ID ) {
		return $is_disabled;
	}

	// If the editor can edit others' entries, they're committee/admin — suppress.
	if ( current_user_can( 'gravityview_edit_others_entries' ) ) {
		return true;
	}

	return $is_disabled;
}, 10, 4 );

