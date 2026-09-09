<?php
/**
 * Notifications (EVENTS_4.1_REBUILD.md §3.8). Code templates are the
 * always-present defaults; admin overrides (edited on the Emails screen, or
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
		'user_co_owner_created' => array(
			'name'    => 'Email to additional host > account created',
			'trigger' => 'approve (additional host with no account)',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Your London Arbitration Week host account: {event_title}',
			'body'    => "Dear {co_owner_name},\n\n{host_name} has added you as an additional host of {event_title} ({law_reference}), part of London Arbitration Week.\n\nWe have created an account for you so that you can view and manage the event. Set your password to get started:\n\n{set_password_link}\n\nYou sign in with this email address.\n\nOnce you are signed in, the event will be waiting on your events dashboard: {dashboard_link}\n\nIf that link has expired, you can request a new one here: {forgot_link}\n\nIf you were not expecting this, please contact the events committee.",
		),
		'user_co_owner_linked' => array(
			'name'    => 'Email to additional host > added to an event',
			'trigger' => 'approve (additional host with an existing account)',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You have been added as a host of {event_title}',
			'body'    => "Dear {co_owner_name},\n\n{host_name} has added you as an additional host of {event_title} ({law_reference}), part of London Arbitration Week.\n\nYou already have an account on {site_name}, so sign in with your usual details and the event will be on your events dashboard:\n\n{dashboard_link}\n\nIf you have forgotten your password, you can set a new one here: {forgot_link}\n\nIf you were not expecting this, please contact the events committee.",
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
		'user_cancelled' => array(
			'name'    => 'Email to user > event cancelled',
			'trigger' => 'cancel',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Your event has been cancelled: {event_title}',
			'body'    => "Dear {host_name},\n\n{event_title} ({law_reference}) has been cancelled by the London Arbitration Week committee.\n\n{cancellation_reason}\n\nIf an invoice for this event was outstanding, it has been cancelled and no payment is due. If you have already paid, the committee will contact you about a refund.\n\nYou can reply from your events dashboard: {comments_link}",
		),
		'committee_withdrawn' => array(
			'name'    => 'Email to committee > event withdrawn by host',
			'trigger' => 'withdraw',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'Event withdrawn: {event_title} ({law_reference})',
			'body'    => "The host has withdrawn {event_title} ({law_reference}). The event is now Cancelled and needs no further review.\n\n{cancellation_reason}\n\nView it on the committee dashboard: {committee_link}",
		),
		'committee_cancelled_paid' => array(
			'name'    => 'Email to committee > cancelled event had been paid',
			'trigger' => 'cancel of a paid event / payment on a cancelled event',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'ACTION NEEDED: cancelled event was paid: {event_title} ({law_reference})',
			'body'    => "{event_title} ({law_reference}) is cancelled, but its fee of {fee} has been paid. Refunds are never automatic: review the payment in Stripe and refund manually if appropriate.\n\n{committee_link}",
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
		'admins_user_registered' => array(
			'name'    => 'Email to admins > user registration',
			'trigger' => 'user registration',
			'to'      => array( 'emily.ocallaghan@lbresearch.com', 'marie@londonarbitrationweek.co.uk' ),
			'active'  => true,
			'subject' => 'New LAW account registered: {user_name}',
			'body'    => "A new account has been registered on {site_name}.\n\nName: {user_name}\nEmail: {user_email}\nRoles: {user_roles}",
		),
		'squareeye_user_registered' => array(
			'name'    => 'Email to Square Eye > user registration',
			'trigger' => 'user registration',
			'to'      => array( 'trevor@squareeye.com' ),
			'active'  => false,
			'subject' => 'LAW account registered: {user_name}',
			'body'    => "New account: {user_name} ({user_email}), roles: {user_roles}.",
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

		/* Bookings (WAITLIST.md §A4; EVENTS_BOOKINGS.md §9) ________________ */

		'user_booking_confirmed' => array(
			'name'    => 'Email to attendee > booking confirmed',
			'trigger' => 'booking created',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Your booking is confirmed: {event_title}',
			'body'    => "Dear {attendee_name},\n\nYour booking (Booking #{booking_number}) for {event_title} is confirmed.\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nWho is coming:\n{attendee_list}\n\n{party_note}\n\nA calendar invitation is attached. You can view and manage these bookings under My bookings: {bookings_link}",
		),
		'host_booking_received' => array(
			'name'    => 'Email to host > new booking',
			'trigger' => 'booking created',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'New booking for your event: {event_title}',
			'body'    => "Dear {host_name},\n\nA new booking has been made for {event_title} ({booking_numbers}).\n\nAttendees:\n{attendee_list}\n\nPlaces remaining: {tickets_remaining} of {tickets_available}.\n\nYou can see all bookings for your event from your events dashboard: {dashboard_link}",
		),
		'committee_booking_received' => array(
			'name'    => 'Email to committee > new booking',
			'trigger' => 'booking created (sent to the event assignee when one is set)',
			'to'      => 'committee',
			'active'  => true,
			'subject' => 'New booking: {event_title} ({law_reference})',
			'body'    => "A new booking has been made for {event_title} ({law_reference}): {booking_numbers}.\n\nAttendees:\n{attendee_list}\n\nPlaces remaining: {tickets_remaining} of {tickets_available}.\n\nView the event on the committee dashboard: {committee_link}",
		),
		'user_booking_registered' => array(
			'name'    => 'Email to attendee > registered by the organisers (existing account)',
			'trigger' => 'a host or committee member registers someone onto an event',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You are registered for {event_title}',
			'body'    => "Dear {attendee_name},\n\n{registered_by} has registered a place for you at {event_title}, part of London Arbitration Week (Booking #{booking_number}).\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nYou already have an account on {site_name}, so sign in with your usual details and the event will be listed under My bookings, where you can also cancel if you cannot attend: {bookings_link}\n\nPlease make sure any dietary or accessibility requirements are up to date on your profile: {profile_link}\n\nA calendar invitation is attached. If you were not expecting this, please contact the events committee.",
		),
		'user_booking_registered_invited' => array(
			'name'    => 'Email to attendee > registered by the organisers (new account)',
			'trigger' => 'a host or committee member registers someone onto an event and an account is created',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You are registered for {event_title}',
			'body'    => "Dear {attendee_name},\n\n{registered_by} has registered a place for you at {event_title}, part of London Arbitration Week (Booking #{booking_number}).\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nWe have created an account for you. Set your password to get started:\n\n{set_password_link}\n\nYou sign in with this email address. If that link has expired, you can request a new one here: {forgot_link}\n\nOnce signed in, please add any dietary or accessibility requirements to your profile, so the organisers can look after you on the day: {profile_link}\n\nThe events you are booked onto are listed under My bookings, where you can also cancel if you cannot attend: {bookings_link}\n\nA calendar invitation is attached. If you were not expecting this, please contact the events committee.",
		),
		'user_attendee_invited' => array(
			'name'    => 'Email to attendee > invited to an event (new account)',
			'trigger' => 'booking attendee account created',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You have been booked onto {event_title}',
			'body'    => "Dear {attendee_name},\n\n{invited_by} has booked a place for you at {event_title}, part of London Arbitration Week. Your booking number is #{booking_number}.\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nWe have created an account for you. Set your password to get started:\n\n{set_password_link}\n\nYou sign in with this email address. If that link has expired, you can request a new one here: {forgot_link}\n\nOnce signed in, please add any dietary or accessibility requirements to your profile, so the organisers can look after you on the day: {profile_link}\n\nThe booking is yours: it is listed under My bookings, where you can cancel it if you cannot attend: {bookings_link}\n\nA calendar invitation is attached. If you were not expecting this, please contact the events committee.",
		),
		'user_attendee_added' => array(
			'name'    => 'Email to attendee > added to a booking (existing account)',
			'trigger' => 'booking attendee linked to an existing account',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You have been booked onto {event_title}',
			'body'    => "Dear {attendee_name},\n\n{invited_by} has booked a place for you at {event_title}, part of London Arbitration Week. Your booking number is #{booking_number}.\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nYou already have an account on {site_name}, so sign in with your usual details and the booking will be listed under My bookings, where you can cancel it if you cannot attend: {bookings_link}\n\nPlease make sure any dietary or accessibility requirements are up to date on your profile: {profile_link}\n\nA calendar invitation is attached. If you were not expecting this, please contact the events committee.",
		),
		'user_booking_rejected' => array(
			'name'    => 'Email to attendee > booking cancelled by the host',
			'trigger' => 'host or committee rejects an attendee',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Your place at {event_title} has been cancelled',
			'body'    => "Dear {attendee_name},\n\nThe event host has cancelled your booking (Booking #{booking_number}) for {event_title} {event_when}.\n\n{removal_reason}\n\nIf you think this is a mistake, please contact the host at {host_email}.",
		),
		'user_booking_cancelled_by_booker' => array(
			'name'    => 'Email to attendee > booking cancelled by whoever booked it',
			'trigger' => 'the person who booked a colleague cancels their booking',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Your place at {event_title} has been cancelled',
			'body'    => "Dear {attendee_name},\n\n{invited_by}, who booked your place at {event_title} {event_when}, has cancelled your booking (Booking #{booking_number}), so you are no longer registered for this event.\n\nIf you think this is a mistake, please speak to the colleague who booked for you, or contact the host at {host_email}.",
		),
		'user_booking_cancelled_self' => array(
			'name'    => 'Email to attendee > you cancelled your booking',
			'trigger' => 'attendee cancels their own booking',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You have cancelled your place at {event_title}',
			'body'    => "Dear {attendee_name},\n\nThis confirms that you have cancelled your booking (Booking #{booking_number}) for {event_title} {event_when}. Your place has been freed for someone else.\n\nIf you change your mind and places are still available, you can book again from the event page.",
		),
		'user_booking_event_cancelled' => array(
			'name'    => 'Email to attendee > event cancelled',
			'trigger' => 'event cancelled with active bookings',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Event cancelled: {event_title}',
			'body'    => "Dear {attendee_name},\n\nWe are sorry to let you know that {event_title}, which you were booked onto {event_when}, has been cancelled by the organisers. Your booking has been cancelled with it, and there is nothing you need to do.\n\nWe hope to see you at other London Arbitration Week events.",
		),
		/* The waitlist (WAITLIST.md §B4) ___________________________________ */

		'user_waitlist_joined' => array(
			'name'    => 'Email to attendee > joined the waitlist',
			'trigger' => 'someone joins the waitlist for a full event',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => "You're on the waitlist for {event_title}",
			'body'    => "Dear {attendee_name},\n\n{event_title} is fully booked, so you are on the waitlist.\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nOn the waitlist:\n{party_list}\n\nAs soon as a place opens up we book it for whoever is next in line and email them a confirmation with a calendar invitation. There is nothing else you need to do. {waitlist_note}\n\nYou can leave the waitlist at any time under My bookings: {bookings_link}",
		),
		'user_waitlist_attendee_invited' => array(
			'name'    => 'Email to attendee > put on the waitlist (new account)',
			'trigger' => 'a colleague is added to a waitlist entry and an account is created',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => "You're on the waitlist for {event_title}",
			'body'    => "Dear {attendee_name},\n\n{invited_by} has put you on the waitlist for {event_title}, part of London Arbitration Week, because the event is fully booked. Your booking number is #{booking_number}.\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nWe have created an account for you. Set your password to get started:\n\n{set_password_link}\n\nYou sign in with this email address. If that link has expired, you can request a new one here: {forgot_link}\n\nIf a place opens up it is booked for you automatically and you are emailed a confirmation. Please add any dietary or accessibility requirements to your profile in the meantime: {profile_link}\n\nYou can leave the waitlist at any time under My bookings: {bookings_link}",
		),
		'user_waitlist_attendee_added' => array(
			'name'    => 'Email to attendee > put on the waitlist (existing account)',
			'trigger' => 'a colleague with an account is added to a waitlist entry',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => "You're on the waitlist for {event_title}",
			'body'    => "Dear {attendee_name},\n\n{invited_by} has put you on the waitlist for {event_title}, part of London Arbitration Week, because the event is fully booked. Your booking number is #{booking_number}.\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nIf a place opens up it is booked for you automatically and you are emailed a confirmation with a calendar invitation. There is nothing you need to do.\n\nYou can leave the waitlist at any time under My bookings: {bookings_link}",
		),
		'host_waitlist_activated' => array(
			'name'    => 'Email to host > a waitlist has opened on your event',
			'trigger' => 'the first person joins the waitlist for an event',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'A waitlist has opened for {event_title}',
			'body'    => "Dear {host_name},\n\n{event_title} is fully booked, and people have started joining the waitlist ({waitlist_count} so far).\n\nAs places open up they are offered automatically to whoever is next in line. You do not need to do anything, but from your bookings list you can change the order of the waitlist, or promote someone straight away if you want to.\n\nIf your approved capacity allows more places, you can raise the number of places on your event and everyone who fits is booked automatically.\n\nYour events dashboard: {dashboard_link}",
		),
		'user_waitlist_promoted' => array(
			'name'    => 'Email to attendee > promoted from the waitlist',
			'trigger' => 'a place opens up, or a host promotes an entry',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'A place has opened up: your booking for {event_title} is confirmed',
			'body'    => "Dear {attendee_name},\n\nGood news: a place has opened up at {event_title} and it is yours. Your booking (Booking #{booking_number}) is confirmed.\n\nDate: {event_date}\nTime: {event_time}\nVenue: {venue}\n\nA calendar invitation is attached. Please add any dietary or accessibility requirements to your profile so the organisers can look after you: {profile_link}\n\nIf you can no longer attend, please cancel under My bookings so the place can go to someone else: {bookings_link}",
		),
		'host_waitlist_promoted' => array(
			'name'    => 'Email to host > people promoted from the waitlist',
			'trigger' => 'one or more entries are promoted',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Places filled from the waitlist: {event_title}',
			'body'    => "Dear {host_name},\n\nPlaces at {event_title} have been filled from the waitlist:\n\n{promoted_list}\n\nEach of them has been emailed a confirmation with a calendar invitation.\n\nPlaces remaining: {tickets_remaining} of {tickets_available}. Still on the waitlist: {waitlist_count}.\n\nYour events dashboard: {dashboard_link}",
		),
		'user_waitlist_left' => array(
			'name'    => 'Email to attendee > you left the waitlist',
			'trigger' => 'an attendee leaves the waitlist',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You have left the waitlist for {event_title}',
			'body'    => "Dear {attendee_name},\n\nThis confirms that you have come off the waitlist for {event_title} {event_when}, so we will not offer you a place if one opens up.\n\nIf you change your mind you can join the waitlist again from the event page, though you would start at the back of the queue.",
		),
		'user_waitlist_removed_by_booker' => array(
			'name'    => 'Email to attendee > taken off the waitlist by whoever added you',
			'trigger' => 'the person who added a colleague to the waitlist removes them',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You have been taken off the waitlist for {event_title}',
			'body'    => "Dear {attendee_name},\n\n{invited_by}, who put you on the waitlist for {event_title} {event_when}, has taken you off it, so you will not be offered a place.\n\nIf you think this is a mistake, please speak to the colleague who added you, or contact the host at {host_email}.",
		),
		'user_waitlist_rejected' => array(
			'name'    => 'Email to attendee > taken off the waitlist by the host',
			'trigger' => 'host or committee rejects a waitlist entry',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'You have been taken off the waitlist for {event_title}',
			'body'    => "Dear {attendee_name},\n\nThe event host has taken you off the waitlist for {event_title} {event_when}, so you will not be offered a place.\n\n{removal_reason}\n\nIf you think this is a mistake, please contact the host at {host_email}.",
		),
		'user_waitlist_event_cancelled' => array(
			'name'    => 'Email to attendee > event cancelled while you were waiting',
			'trigger' => 'an event with a waitlist is cancelled',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Event cancelled: {event_title}',
			'body'    => "Dear {attendee_name},\n\nWe are sorry to let you know that {event_title}, which you were waiting for a place at {event_when}, has been cancelled by the organisers. Your waitlist entry has been cancelled with it, and there is nothing you need to do.\n\nWe hope to see you at other London Arbitration Week events.",
		),
		'user_waitlist_blocked' => array(
			'name'    => 'Email to attendee > your waitlist place could not be taken up',
			'trigger' => 'a place opened but the entry could not be promoted (a clash, usually)',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'We could not confirm your place at {event_title}',
			'body'    => "Dear {attendee_name},\n\nA place opened up at {event_title} {event_when} and we tried to book it for you, but could not:\n\n{blocked_reason}\n\nYou are still on the waitlist and keep your place in the queue. If you sort this out, for example by cancelling the booking that overlaps, we will offer you a place as soon as your turn comes round again.\n\nMy bookings: {bookings_link}",
		),

		// Two welcome templates, picked by the roles ticked at registration
		// (law_registration_handler()): host or sponsor wins over attendee. The
		// attendee copy asks for dietary and accessibility requirements, which
		// reads oddly to someone registering only to submit an event, so the
		// convention here is one slug per audience (as with
		// user_attendee_invited / user_attendee_added) rather than a
		// conditional inside one body.
		'user_welcome_registered' => array(
			'name'    => 'Email to attendee > welcome after registration',
			'trigger' => 'user registration (attendee only)',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Welcome to {site_name}',
			'body'    => "Dear {user_name},\n\nWelcome to London Arbitration Week. Your account has been created and you are signed in.\n\nFrom your account you can browse the programme, book places at events and manage your details. Please add any dietary or accessibility requirements to your profile, so event organisers can look after you: {profile_link}\n\nYour events and bookings live here: {bookings_link}",
		),
		'user_welcome_registered_host' => array(
			'name'    => 'Email to host or sponsor > welcome after registration',
			'trigger' => 'user registration (event host or sponsor)',
			'to'      => 'dynamic',
			'active'  => true,
			'subject' => 'Welcome to {site_name}',
			'body'    => "Dear {user_name},\n\nWelcome to London Arbitration Week. Your account has been created and you are signed in.\n\nFrom your account you can submit an event for the programme, then follow it through review, payment and publication: {submit_link}\n\nYour events live here, along with the bookings people make for them: {bookings_link}\n\nYou can also book places at other events in the programme. If you do, please add any dietary or accessibility requirements to your profile so the organisers can look after you: {profile_link}",
		),
		'host_capacity_warning' => array(
			'name'    => 'Email to host > event nearly full',
			'trigger' => '5 or fewer places remaining',
			'to'      => 'host',
			'active'  => true,
			'subject' => 'Your event is nearly full: {event_title}',
			'body'    => "Dear {host_name},\n\n{event_title} is nearly fully booked: {tickets_remaining} of {tickets_available} places remain.\n\nIf your approved capacity band allows it, you can raise the number of places on your event from your events dashboard: {dashboard_link}\n\nIf LAW arranged your venue, the places are set by the committee, so please reply to this email and we will raise them for you.\n\nOnce the last place is taken, further visitors will see the event as fully booked.",
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
		$summary .= "\n" . wp_trim_words( law_rich_text_plain( $post->post_content ), 60, '…' );
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
		'{cancellation_reason}' => (string) law_event_meta( $event_id, '_law_cancellation_reason' ),
		'{event_link}'       => $post && 'publish' === $post->post_status ? get_permalink( $post ) : '',
		'{edit_link}'        => admin_url( 'post.php?post=' . (int) $event_id . '&action=edit' ),
		'{dashboard_link}'   => $dashboard,
		'{comments_link}'    => $dashboard . '?law_thread=' . (int) $event_id,
		'{committee_link}'   => $committee,
		'{site_name}'        => get_bloginfo( 'name' ),
		'{stripe_error}'     => '',
		'{latest_comment}'   => '',
		'{forgot_link}'      => law_auth_login_url( array( 'action' => 'forgot' ) ),
		// User-registration emails (filled via the send call's placeholders).
		'{user_name}'        => '',
		'{user_email}'       => '',
		'{user_roles}'       => '',
		// Additional-host emails (filled via the send call's placeholders).
		'{co_owner_name}'    => '',
		'{username}'         => '',
		'{set_password_link}' => '',
		// Event date/time, from the confirmed slot (empty until one is set).
		'{event_date}'       => '',
		'{event_time}'       => '',
		// The date and time as one phrase, or '' when no slot is confirmed —
		// the inline "({event_date}, {event_time})" pattern rendered "(, )".
		'{event_when}'       => '',
		// Bookings emails (filled via the send call's placeholders; the two
		// links are real defaults so the welcome email works with no event).
		'{attendee_name}'     => '',
		'{attendee_list}'     => '',
		'{booking_number}'    => '',
		'{booking_numbers}'   => '',
		'{invited_by}'        => '',
		'{party_note}'        => '',
		'{waitlist_note}'     => '',
		'{tickets_available}' => '',
		'{tickets_remaining}' => '',
		'{removal_reason}'    => '',
		'{registered_by}'     => '',
		// The waitlist (WAITLIST.md §B4).
		'{party_list}'        => '',
		'{promoted_list}'     => '',
		'{blocked_reason}'    => '',
		'{waitlist_count}'    => '',
		'{bookings_link}'     => home_url( '/account/events/' ),
		'{profile_link}'      => home_url( '/account/profile/' ),
		// law_account_url() resolves the real permalink and falls back to the
		// literal path, so a renamed page gives an honest 404 rather than a
		// link to the home page.
		'{submit_link}'       => function_exists( 'law_account_url' ) ? law_account_url( 'submit' ) : home_url( '/account/events/submit/' ),
	);

	$start = (string) law_event_meta( $event_id, '_law_start' );
	if ( '' !== $start ) {
		$end                          = (string) law_event_meta( $event_id, '_law_end' );
		$placeholders['{event_date}'] = date_i18n( 'l j F Y', strtotime( $start ) );
		$placeholders['{event_time}'] = substr( $start, 11, 5 ) . ( '' !== $end ? ' to ' . substr( $end, 11, 5 ) : '' );
		$placeholders['{event_when}'] = sprintf( 'on %s at %s', $placeholders['{event_date}'], $placeholders['{event_time}'] );
	}

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
	$test_mode  = law_events_test_mode_address();
	if ( ! $recipients ) {
		// In test mode an unconfigured audience (an empty committee list, say)
		// must not silently swallow the email: the whole point is to see it.
		if ( '' === $test_mode ) {
			// Never a silent drop (the module's exhaustive-logging rule): a
			// deleted host account or an unconfigured committee list must
			// leave a trace on the event.
			law_event_log(
				$event_id,
				sprintf( 'Email NOT sent (no recipients): %s.', $definition['name'] ),
				array( 'action' => 'email', 'slug' => $slug, 'to' => array(), 'sent' => false, 'source' => 'notifications' )
			);
			return false;
		}
		$recipients = array( $test_mode );
	}

	$placeholders = law_events_email_placeholders( $event_id, (array) ( $extra['placeholders'] ?? array() ) );
	$subject      = strtr( (string) $definition['subject'], $placeholders );
	$body         = wpautop( esc_html( strtr( (string) $definition['body'], $placeholders ) ) );
	// Re-linkify escaped URLs so invoice/dashboard links stay clickable.
	$body = make_clickable( $body );

	// Optional file attachments (the bookings .ics calendar invites). wp_mail
	// is synchronous, so a caller may delete its temp file right after this
	// returns. The test-mode redirect (wp_mail filter, 99) and the Email
	// Templates wrapper (100) touch recipients and body only.
	$attachments = array_filter( array_map( 'strval', (array) ( $extra['attachments'] ?? array() ) ), 'file_exists' );

	$sent = wp_mail(
		$recipients,
		$subject,
		$body,
		array( 'Content-Type: text/html; charset=UTF-8' ),
		$attachments
	);

	$context = array(
		'action'    => 'email',
		'slug'      => $slug,
		'to'        => $recipients,
		'subject'   => $subject,
		'sent'      => (bool) $sent,
		'source'    => 'notifications',
		'test_mode' => '' !== $test_mode ? $test_mode : false,
	);
	if ( $attachments ) {
		$context['attachments'] = array_map( 'basename', $attachments );
	}
	law_event_log(
		$event_id,
		sprintf(
			'%s: %s → %s.%s',
			$sent ? 'Email sent' : 'Email send FAILED',
			$definition['name'],
			implode( ', ', $recipients ),
			'' !== $test_mode ? ' TEST MODE: delivered to ' . $test_mode . ' instead.' : ''
		),
		$context
	);

	return (bool) $sent;
}
