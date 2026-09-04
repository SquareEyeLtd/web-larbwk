<?php
/**
 * Notifications (EVENTS_4.1_REBUILD.md §3.8). Code templates are the
 * always-present defaults; admin overrides (edited on LAW → Emails, or
 * imported by the migration) are stored per email in one option and take
 * precedence. Bodies are content only: the site-wide Email Templates plugin
 * wrapper provides the branding, exactly as it does for every other email.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const LAW_EVENTS_EMAIL_OVERRIDES_OPTION = 'law_events_email_overrides';

/**
 * The email registry: slug => definition.
 *
 * 'to' is either the dynamic audience 'host' / 'invoice_contact' / 'committee'
 * / 'admin', or a fixed address list (editable on the Emails screen).
 */
function law_events_email_registry() {
	return array(
		'user_submitted' => array(
			'name'    => 'Email to user > event submitted',
			'trigger' => 'submit',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Your event has been submitted: {event_title}',
			'body'    => "Dear {host_name},\n\nThank you for submitting your event, {event_title} ({law_reference}), to London Arbitration Week.\n\nThe organising committee will review your submission and be in touch. You can follow progress from your events dashboard: {dashboard_link}",
		),
		'committee_submitted' => array(
			'name'    => 'Email to committee > event submitted',
			'trigger' => 'submit',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'New event submitted: {event_title} ({law_reference})',
			'body'    => "A new event has been submitted to London Arbitration Week.\n\nEvent: {event_title}\nReference: {law_reference}\nHost: {host_name} ({host_email})\n\nReview it on the committee dashboard: {committee_link}",
		),
		'squareeye_submitted' => array(
			'name'    => 'Email to Square Eye > event submitted',
			'trigger' => 'submit',
			'to'      => array( 'trevor@squareeye.net' ),
			'active'  => false,
			'subject' => 'LAW event submitted: {event_title}',
			'body'    => "Event {event_title} ({law_reference}) submitted by {host_name} ({host_email}).",
		),
		'user_sent_back' => array(
			'name'    => 'Email to user > clarification needed',
			'trigger' => 'send_back',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Your event needs clarification: {event_title}',
			'body'    => "Dear {host_name},\n\nThe committee has a question about your event {event_title} ({law_reference}):\n\n{latest_comment}\n\nPlease reply from your events dashboard, which will return the event to the committee: {comments_link}",
		),
		'committee_resubmitted' => array(
			'name'    => 'Email to committee > host replied',
			'trigger' => 'resubmit',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'Host reply on {event_title} ({law_reference})',
			'body'    => "The host has replied on {event_title} ({law_reference}) and the event has returned to the review queue.\n\nLatest comment:\n{latest_comment}\n\nReview it on the committee dashboard: {committee_link}",
		),
		'committee_approved' => array(
			'name'    => 'Email to committee > event approved',
			'trigger' => 'approve',
			'to'      => 'committee',
			'active'  => true, // Fires on EVERY approval for now (settled decision).
			'subject' => 'Event approved: {event_title} ({law_reference})',
			'body'    => "{event_title} ({law_reference}) has been approved.\n\nFee: {fee}\nHost: {host_name} ({host_email})",
		),
		'user_payment_due' => array(
			'name'    => 'Email to user > event approved, pending payment',
			'trigger' => 'approve (paid events)',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Your event is approved, payment due: {event_title}',
			'body'    => "Dear {host_name},\n\nGood news: {event_title} ({law_reference}) has been approved by the committee.\n\nThe event fee of {fee} is now due. Please pay within 5 days using the secure Stripe invoice:\n{invoice_url}\n\nYour event will be published in the programme once payment is received.",
		),
		'committee_payment_received' => array(
			'name'    => 'Email to committee > payment received',
			'trigger' => 'payment received',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'Payment received: {event_title} ({law_reference})',
			'body'    => "Payment of {fee} has been received for {event_title} ({law_reference}). The event is now confirmed and published in the programme.",
		),
		'user_confirmed_paid' => array(
			'name'    => 'Email to user (paid) > event confirmed',
			'trigger' => 'confirmed (fee > 0)',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Your event is confirmed: {event_title}',
			'body'    => "Dear {host_name},\n\nPayment received, thank you. {event_title} ({law_reference}) is confirmed and published in the London Arbitration Week programme.\n\nView your listing: {event_link}",
		),
		'user_confirmed_free' => array(
			'name'    => 'Email to user (no fee) > event confirmed',
			'trigger' => 'confirmed (fee = 0)',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Your event is confirmed: {event_title}',
			'body'    => "Dear {host_name},\n\n{event_title} ({law_reference}) is confirmed and published in the London Arbitration Week programme. No fee applies to your event.\n\nView your listing: {event_link}",
		),
		'user_rejected' => array(
			'name'    => 'Email to user > event not accepted',
			'trigger' => 'reject',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Your event submission: {event_title}',
			'body'    => "Dear {host_name},\n\nThank you for submitting {event_title} ({law_reference}) to London Arbitration Week. Unfortunately the committee is unable to accept it this year.\n\n{rejection_reason}",
		),
		'committee_event_updated' => array(
			'name'    => 'Email to committee > event updated',
			'trigger' => 'host edit of a published event',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'Event updated by host: {event_title} ({law_reference})',
			'body'    => "The host has updated the published event {event_title} ({law_reference}).\n\nReview the change on the committee dashboard: {committee_link}",
		),
		'committee_assignee' => array(
			'name'    => 'Email to committee member > assigned to you',
			'trigger' => 'assignee change',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Assigned to you: {event_title} ({law_reference})',
			'body'    => "You have been assigned the event {event_title} ({law_reference}).\n\nReview it on the committee dashboard: {committee_link}",
		),
		'user_new_comment' => array(
			'name'    => 'Email to user > new comment from the committee',
			'trigger' => 'committee comment',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'New comment on your event: {event_title}',
			'body'    => "Dear {host_name},\n\nThe committee has commented on {event_title} ({law_reference}):\n\n{latest_comment}\n\nReply from your events dashboard: {comments_link}",
		),
		'committee_new_comment' => array(
			'name'    => 'Email to committee > new comment from the host',
			'trigger' => 'host comment',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'New host comment: {event_title} ({law_reference})',
			'body'    => "The host has commented on {event_title} ({law_reference}):\n\n{latest_comment}\n\nView the thread: {committee_link}",
		),
		'committee_refund' => array(
			'name'    => 'Email to committee > payment refunded',
			'trigger' => 'Stripe refund',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'Payment refunded: {event_title} ({law_reference})',
			'body'    => "Stripe has recorded a REFUND on {event_title} ({law_reference}).\n\nThe event stays published; unpublishing is a committee decision. Review the payment in Stripe and the event here: {committee_link}",
		),
		'squareeye_event_updated' => array(
			'name'    => 'Email to Square Eye > event updated',
			'trigger' => 'host edit of a published event',
			'to'      => array( 'trevor@squareeye.com' ),
			'active'  => false,
			'subject' => 'LAW event updated: {event_title} ({law_reference})',
			'body'    => "The host has updated the published event {event_title} ({law_reference}).",
		),
		'admin_stripe_error' => array(
			'name'    => 'Email to admins > Stripe invoice failed',
			'trigger' => 'invoice creation failure',
			'to'      => 'admin',
			'active'  => true,
			'subject' => 'ACTION NEEDED: Stripe invoice failed for {event_title}',
			'body'    => "Creating the Stripe invoice for {event_title} ({law_reference}) failed:\n\n{stripe_error}\n\nThe event is held at Approved. Retry from the event screen in wp-admin; the committee dashboard also shows a Retry button.",
		),
	);
}

/** One email definition with any stored override merged in. */
function law_events_email( $slug ) {
	$registry = law_events_email_registry();
	if ( ! isset( $registry[ $slug ] ) ) {
		return null;
	}
	$overrides = get_option( LAW_EVENTS_EMAIL_OVERRIDES_OPTION, array() );
	$override  = is_array( $overrides ) && isset( $overrides[ $slug ] ) && is_array( $overrides[ $slug ] )
		? $overrides[ $slug ]
		: array();
	return array_merge( $registry[ $slug ], array_intersect_key( $override, array_flip( array( 'subject', 'body', 'to', 'active', 'name' ) ) ) );
}

/** Placeholder tags available to every event email. */
function law_events_email_placeholders( $event_id, array $extra = array() ) {
	$post   = get_post( $event_id );
	$author = $post ? get_user_by( 'id', (int) $post->post_author ) : null;

	$dashboard = home_url( '/account/events/' );
	$committee = home_url( '/account/dashboard/?event=' . (int) $event_id );

	$summary = '';
	if ( $post ) {
		$summary_rows = array(
			'Event'        => $post->post_title,
			'Reference'    => (string) law_event_meta( $event_id, '_law_reference' ),
			'Host'         => $author ? $author->display_name . ' (' . $author->user_email . ')' : '',
			'Organisation' => (string) law_event_meta( $event_id, '_law_host_organisations' ),
			'Slot'         => (string) law_event_meta( $event_id, '_law_slot_label' ),
			'Venue'        => (string) law_event_meta( $event_id, '_law_venue' ),
			'Fee tier'     => law_event_tier_label( (string) law_event_meta( $event_id, '_law_fee_tier' ) ),
			'Status'       => law_event_status_label( $post ),
		);
		foreach ( $summary_rows as $summary_label => $summary_value ) {
			if ( '' !== trim( (string) $summary_value ) ) {
				$summary .= $summary_label . ': ' . $summary_value . "\n";
			}
		}
		$summary .= "\n" . wp_trim_words( wp_strip_all_tags( $post->post_content ), 60, '…' );
	}

	$placeholders = array(
		'{event_summary}'    => trim( $summary ),
		'{event_title}'      => $post ? $post->post_title : '',
		'{law_reference}'    => (string) law_event_meta( $event_id, '_law_reference' ),
		'{host_name}'        => $author ? $author->display_name : '',
		'{host_email}'       => $author ? $author->user_email : '',
		'{status}'           => $post ? law_event_status_label( $post ) : '',
		'{payment_status}'   => ucfirst( (string) law_event_meta( $event_id, '_law_payment_status' ) ),
		'{invoice_url}'      => (string) law_event_meta( $event_id, '_law_stripe_invoice_url' ),
		'{fee}'              => law_events_format_pence( (int) law_event_meta( $event_id, '_law_fee_pence' ) ),
		'{venue}'            => (string) law_event_meta( $event_id, '_law_venue' ),
		'{slot}'             => (string) law_event_meta( $event_id, '_law_slot_label' ),
		'{rejection_reason}' => (string) law_event_meta( $event_id, '_law_rejection_reason' ),
		'{event_link}'       => $post && 'publish' === $post->post_status ? get_permalink( $post ) : '',
		'{edit_link}'        => admin_url( 'post.php?post=' . (int) $event_id . '&action=edit' ),
		'{dashboard_link}'   => $dashboard,
		'{comments_link}'    => $dashboard . '?law_thread=' . (int) $event_id,
		'{committee_link}'   => $committee,
		'{site_name}'        => get_bloginfo( 'name' ),
		'{stripe_error}'     => '',
		'{latest_comment}'   => '',
	);

	$latest = law_event_latest_comment( $event_id );
	if ( $latest ) {
		$placeholders['{latest_comment}'] = sprintf(
			"%s (%s):\n%s",
			$latest->comment_author,
			mysql2date( 'j F Y, H:i', $latest->comment_date ),
			$latest->comment_content
		);
	}

	$error = law_event_meta( $event_id, '_law_stripe_error' );
	if ( is_array( $error ) && ! empty( $error['message'] ) ) {
		$placeholders['{stripe_error}'] = $error['message'] . ' (' . ( $error['at'] ?? '' ) . ')';
	}

	foreach ( $extra as $tag => $value ) {
		$placeholders[ '{' . trim( (string) $tag, '{}' ) . '}' ] = (string) $value;
	}

	return $placeholders;
}

/** Resolve an email's recipients for one event. */
function law_events_email_recipients( $definition, $event_id, array $extra = array() ) {
	if ( ! empty( $extra['to'] ) ) {
		return array_filter( (array) $extra['to'], 'is_email' );
	}
	$to = $definition['to'];
	if ( is_array( $to ) ) {
		return array_filter( array_map( 'sanitize_email', $to ), 'is_email' );
	}
	switch ( $to ) {
		case 'host':
			$post   = get_post( $event_id );
			$author = $post ? get_user_by( 'id', (int) $post->post_author ) : null;
			return $author && is_email( $author->user_email ) ? array( $author->user_email ) : array();
		case 'committee':
			return law_events_committee_emails();
		case 'admin':
			return array( get_option( 'admin_email' ) );
	}
	return array();
}

/**
 * Send one registered email for an event. Rendering: placeholders replaced,
 * plain-text body autop'd to HTML content; the Email Templates plugin wrapper
 * and Postmark/Mailpit delivery are untouched. Every send is logged to the
 * event's activity log.
 *
 * @param string $slug     Registry slug.
 * @param int    $event_id law_event post ID.
 * @param array  $extra    Optional: to (override recipients), placeholders.
 * @return bool Whether wp_mail() accepted the send.
 */
function law_events_send( $slug, $event_id, array $extra = array() ) {
	$definition = law_events_email( $slug );
	if ( ! $definition || empty( $definition['active'] ) ) {
		return false;
	}

	$recipients = law_events_email_recipients( $definition, $event_id, $extra );
	if ( ! $recipients ) {
		return false;
	}

	$placeholders = law_events_email_placeholders( $event_id, (array) ( $extra['placeholders'] ?? array() ) );
	$subject      = strtr( (string) $definition['subject'], $placeholders );
	$body         = wpautop( esc_html( strtr( (string) $definition['body'], $placeholders ) ) );
	// Re-linkify escaped URLs so invoice/dashboard links stay clickable.
	$body = make_clickable( $body );

	$sent = wp_mail(
		$recipients,
		$subject,
		$body,
		array( 'Content-Type: text/html; charset=UTF-8' )
	);

	law_event_log(
		$event_id,
		sprintf( '%s: %s → %s.', $sent ? 'Email sent' : 'Email send FAILED', $definition['name'], implode( ', ', $recipients ) ),
		array(
			'action'  => 'email',
			'slug'    => $slug,
			'to'      => $recipients,
			'subject' => $subject,
			'sent'    => (bool) $sent,
			'source'  => 'notifications',
		)
	);

	return (bool) $sent;
}
