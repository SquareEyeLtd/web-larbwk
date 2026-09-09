<?php
/**
 * The flagship conference's page (no Template Name header: swapped in by
 * template_include in functions/events/source.php, so it never appears in the
 * page-template dropdown and cannot be applied to anything else).
 *
 * It renders the same body as every other single event
 * (parts/calendar-body.php), with two differences the caller variables below
 * carry: the details box shows only the date, time and location, because the
 * flagship has no host organisation, event type or sector to state; and no
 * booking control renders, because the flagship is approval-gated through its
 * own application flow (EVENTS_4.2_SPECS.md §5), which is not built yet.
 *
 * The back links are deliberately left to the body's own defaults:
 * law_calendar_url() already sends a single law_event back to the programme,
 * and passing a $law_cal_back label would apply it to BOTH ways back, so the
 * chevron at the top and the button at the foot could no longer read
 * differently ("Back to programme" / "Back to events calendar").
 */

// Still set, because the SEO title filters resolve the event from it
// (law_calendar_current_event_for_title() goes through
// law_calendar_requested_event_id()).
$_GET['event'] = (string) get_the_ID();

// The event is passed in already resolved rather than left to the body's own
// lookup, which applies the PUBLIC status filter anywhere but the committee
// calendar. A flagship that is still a draft came back null from that lookup,
// and the body then fell back to rendering the whole programme list at
// /events/flagship/ — a 200 showing the wrong page, which is what the
// committee saw while filling the screen in. Same mechanism as the committee's
// ?preview-event= view.
//
// The all-statuses branch is gated on being able to edit the post, even though
// WordPress has already 404'd this URL for everyone else (verified: an
// anonymous request for an unpublished event gets 404.php, so the template
// never runs). Two locks, because the cost of being wrong here is publishing a
// draft programme, and the public branch below is the one that would notice if
// the first lock ever changed.
$law_cal_event = null;
if ( function_exists( 'law_events_map_post' ) ) {
	$law_flagship_editable = 'publish' === get_post_status( get_the_ID() )
		|| current_user_can( 'edit_post', get_the_ID() );
	// array( '*' ), not array(): an empty allow-list means "any status EXCEPT
	// law-draft" in law_events_map_post(), and the flagship starts as a draft,
	// which is precisely the case this branch exists to render.
	$law_cal_event         = law_events_map_post( get_the_ID(), $law_flagship_editable ? array( '*' ) : null );
	if ( $law_cal_event ) {
		$law_cal_event = law_events_cpt_hydrate( $law_cal_event );
	}
}

$law_cal_show_status  = false;
$law_cal_hero_title   = 'Calendar of Events';
$law_cal_details_rows = array( 'date', 'time', 'venue' );
$law_cal_no_booking   = true;
// A day-long agenda, not two or three optional sessions: every session renders
// open, on a timeline, because the running order is what the reader came for.
$law_cal_sessions_style = 'timeline';
require get_theme_file_path( 'parts/calendar-body.php' );
