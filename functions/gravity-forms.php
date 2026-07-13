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


/* Auto-attach Nested Forms child entries when editing via Gravity Flow ____________________________________ */

/**
 * By default, child entries (e.g. comments) added while editing a parent entry on a Gravity Flow
 * user input step are only attached to the parent entry when the workflow Submit button is clicked.
 * Until then they're orphaned: they don't survive a page refresh and are trashed after a week.
 *
 * This attaches the child entry to the parent as soon as the child form (modal) is submitted.
 *
 * @see https://gravitywiz.com/snippet-library/gpnf-gflow-auto-attach-child-entries/
 */
add_filter( 'gpnf_set_parent_entry_id', function ( $parent_entry_id ) {
	if ( ! $parent_entry_id && is_callable( 'gravity_flow' ) && gravity_flow()->is_workflow_detail_page() ) {
		$parent_entry_id = rgget( 'lid' ) ? rgget( 'lid' ) : $parent_entry_id;
	}
	return $parent_entry_id;
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

/** Form 2 field ID for the committee assignee (Populate Anything; stores user email as value). */
const LAW_GF_COMMITTEE_ASSIGNEE_FIELD_ID = 90;

/**
 * Normalise field 90 to an email address.
 *
 * @param mixed $value Raw field value.
 * @return string Email address, or empty string if not resolvable.
 */
function law_get_committee_assignee_email_from_field( $value ) {
	$value = trim( (string) $value );

	if ( '' === $value ) {
		return '';
	}

	if ( is_email( $value ) ) {
		return $value;
	}

	if ( ctype_digit( $value ) ) {
		$user = get_user_by( 'id', absint( $value ) );
		if ( $user && is_email( $user->user_email ) ) {
			return $user->user_email;
		}
	}

	return '';
}

/**
 * Send assignee notification when field 90 changes.
 *
 * @param array $entry          Updated entry.
 * @param array $original_entry Entry before the update.
 */
function law_maybe_notify_committee_assignee( $entry, $original_entry ) {
	static $sent = array();

	$entry_id = absint( rgar( $entry, 'id' ) );
	if ( ! $entry_id ) {
		return;
	}

	$old = law_get_committee_assignee_email_from_field( rgar( $original_entry, LAW_GF_COMMITTEE_ASSIGNEE_FIELD_ID ) );
	$new = law_get_committee_assignee_email_from_field( rgar( $entry, LAW_GF_COMMITTEE_ASSIGNEE_FIELD_ID ) );

	if ( $old === $new || ! is_email( $new ) ) {
		return;
	}

	$dedupe_key = $entry_id . '|' . $new;
	if ( isset( $sent[ $dedupe_key ] ) ) {
		return;
	}
	$sent[ $dedupe_key ] = true;

	$title = rgar( $entry, '17' ); // Event title
	$ref   = rgar( $entry, '70' ); // LAW reference
	$link  = 'https://londonarbitrationweek.co.uk/account/dashboard/';

	$subject = sprintf( 'You have been assigned an event: %s (%s)', $title, $ref );
	$body    = '<p>You have been assigned as the committee contact for '
		. '<strong>' . esc_html( $title ) . '</strong> (' . esc_html( $ref ) . ').</p>'
		. '<p><a href="' . esc_url( $link ) . '">View it in the committee dashboard</a></p>';

	wp_mail( $new, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
}

/**
 * GFAPI::update_entry() — some programmatic updates.
 */
add_action( 'gform_post_update_entry_2', function ( $entry, $original_entry ) {
	law_maybe_notify_committee_assignee( $entry, $original_entry );
}, 10, 2 );

/**
 * GravityView Edit Entry and wp-admin entry detail — uses save_lead(), not GFAPI::update_entry().
 */
add_action( 'gform_after_update_entry_2', function ( $form, $entry_id, $original_entry ) {
	$entry = GFAPI::get_entry( $entry_id );
	if ( is_wp_error( $entry ) ) {
		return;
	}

	law_maybe_notify_committee_assignee( $entry, $original_entry );
}, 10, 3 );

/**
 * GravityEdit inline edit — standard GF update hooks are removed by the plugin.
 *
 * @see https://www.gravitykit.com/docs/gravityedit/inline-edit-filters/
 */
add_filter( 'gravityview-inline-edit/entry-updated', function ( $update_result, $entry, $form_id, $gf_field, $original_entry ) {
	if ( 2 !== (int) $form_id || empty( $update_result ) || is_wp_error( $update_result ) ) {
		return $update_result;
	}

	if ( $gf_field && (int) $gf_field->id !== LAW_GF_COMMITTEE_ASSIGNEE_FIELD_ID ) {
		return $update_result;
	}

	law_maybe_notify_committee_assignee( $entry, $original_entry );

	return $update_result;
}, 10, 5 );


/* Only notify committee of entry edits when an event host made the change ________________________________ */

/**
 * True when the current user is committee/admin (not an event host editing their own entry).
 */
function law_gf_editor_is_staff_reviewer() {
	return current_user_can( 'gravityview_edit_others_entries' );
}

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
	if ( law_gf_editor_is_staff_reviewer() ) {
		return true;
	}

	return $is_disabled;
}, 10, 4 );

/**
 * Same host-only rule for GravityRevisions "entry updated, revision is saved" emails.
 * Those are sent via gravityview/entry-revisions/send-notifications, not only gform_disable_notification.
 *
 * @see https://www.gravitykit.com/docs/gravityrevisions/entry-revisions-hooks/
 */
add_filter( 'gravityview/entry-revisions/send-notifications', function ( $send_notification, $revision_to_add, $current_entry, $changed_fields ) {

	if ( law_gf_editor_is_staff_reviewer() ) {
		return false;
	}

	return $send_notification;
}, 10, 4 );

