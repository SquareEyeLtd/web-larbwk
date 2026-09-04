---
name: known-issue-public-pages-members-gated
description: /programme/ and /speakers/ (and by extension every single event/speaker page) are restricted to administrator/editor/events_committee via the Members plugin, not public as the site map says
metadata:
  type: project
---

**Status as of 2026-09-04: confirmed, unfixed (or possibly deliberate pre-launch lock — unclear, flag to Denis/PM).**

The Members plugin's per-page Content Permissions restrict page 622 (`/programme/`) and page 658 (`/speakers/`) to roles `administrator`, `editor`, `events_committee` only (checked via `get_post_meta($id, '_members_access_role', true)`). Logged-out visitors and `event_host`/`attendee` roles get "Sorry, but you do not have permission to view this content." (200 status, not a redirect).

`functions/events/source.php` (~line 358-383) deliberately mirrors this same restriction onto individual `/events/<slug>/` and `/speakers/<slug>/` pages via `members_can_current_user_view_post( $gate_page )`, with the comment "the pages are only as public as /programme/" — that code is working exactly as designed. So this is ONE root cause (the two pages' Members restriction), not two separate bugs, even though it manifests differently (inline error vs. login redirect for singles).

**Why this matters:** per the theme's own documented Site Map, `/calendar/` (=`/programme/`) and `/speakers/` are supposed to be fully public. Right now the entire public-facing events/speakers experience — the actual point of the site for external visitors — is locked to internal roles. Always check this when testing "public" pages: a bare `curl -o /dev/null -w '%{http_code}'` status check is NOT enough, since Members returns 200 with an error message body rather than a 403/redirect. Must check response body content, not just status code.

**How to apply:** when doing a role-gating pass on any page claimed "public" in the site map, always fetch and grep the body (not just status code) for "Sorry, but you do not have permission" as a logged-out curl request. If found, check `get_post_meta($page_id, '_members_access_role', true)` to confirm/deny it's a Members Content Permissions restriction.
