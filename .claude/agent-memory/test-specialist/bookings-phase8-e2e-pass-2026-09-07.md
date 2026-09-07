---
name: bookings-phase8-e2e-pass-2026-09-07
description: Full Playwright E2E pass over the attendee bookings feature (EVENTS_BOOKINGS.md phase 8) - outcomes, 4 real findings, 1 environment conflict to flag
metadata:
  type: project
---

Ran the full 9-flow smoke from EVENTS_BOOKINGS.md §12 phase 8 on 2026-09-07 against
`http://localhost/law` (law_events_source=cpt). 83 PHPUnit tests were already green and the
security gate had passed before this pass started. Screenshots at
`.playwright/screenshots/01`–`30-*.png` in the theme repo (left in place for review, not
committed — `.playwright/` is gitignored per [[playwright-output-gitignore]]).

**Overall verdict: substantially working, no blockers.** All core money-free flows (book,
add/remove colleague, cancel, clash/duplicate/capacity guards, control states, host/committee
lists, exports, wp-admin read-only screens) function correctly end to end including emails
(with .ics) in Mailpit. Found 4 real, reproducible bugs (all cosmetic/UX, none data-corrupting)
plus 1 environment/spec conflict worth flagging to Denis.

## Real findings (reproducible, not fixed — test-specialist does not fix code)

1. **AJAX booking-manage actions never show their success notice.** Every JS-driven success
   path in `functions/events/bookings.php` (`law_booking_add_attendee_handler`,
   `law_booking_remove_attendee_handler`, `law_booking_cancel_handler`, and the host/committee
   reject handler) returns `redirect => law_booking_manage_url($booking_id)` or
   `home_url('/account/events/')` etc. **without** the `law_notice` query arg that
   `law_events_redirect_back()` adds on the non-JS fallback path. `booking-form.js` does
   `window.location.replace(payload.redirect)` on success with no toast first, so a JS-enabled
   user who removes/adds a colleague, cancels a booking, or gets rejected never sees any
   on-screen confirmation — the row/booking just changes with zero feedback. No-JS submissions
   (which go through `law_events_redirect_back`) DO get the notice banner correctly. Root
   cause: the `redirect` value built inside `law_events_respond()`'s payload for the AJAX
   branch is never given the `$notice` slug the 4th argument already carries. Fix would be
   trivial (add `law_notice` to those redirect URLs) but wasn't made — reporting only.
2. **"Browse the programme" link on the empty "Your bookings" state points at itself.**
   `templates/account-events.php:140` builds the link with `law_calendar_url()`
   (`functions/calendar.php:286`). That function's generic branch does
   `$page_id = get_queried_object_id(); $base = get_permalink($page_id);` — on `/account/events/`
   (My events, page 292) `get_queried_object_id()` returns page 292 itself, so the "browse the
   programme" link resolves to `/account/events/`, not `/programme/`. `law_calendar_url()` only
   has a correct special-case for `is_singular(LAW_EVENT_CPT)`; it was never adapted for being
   called from an unrelated page. Repro: log in as an attendee with zero bookings, visit
   `/account/events/`, hover/click "Browse the programme".
3. **Capacity-guard wording says "attendee(s)" not "colleague(s)."** `bookings.php` lines
   281-282 render `'Please remove %2$d attendee(s) and try again.'`; EVENTS_BOOKINGS.md's own
   example copy (§7.2) says `"...remove {x} colleague(s) and try again."`. Functionally
   correct, just off-spec wording. Low priority.
4. **Wrong count feeds the `_n()` plural selector on the bookings-list substate line.**
   `parts/events/booking-list.php:178`: `_n('%1$s attendee across %2$d active booking.', ...,
   count($law_bl_active), 'law')` — the plural/singular decision uses the **booking** count,
   but the `%1$s` placeholder that gets pluralised in the English string is the **attendee**
   total (`$law_bl_total`). Repro: 2 attendees in exactly 1 active booking renders "2 attendee
   across 1 active booking." (should be "2 attendees"). Only shows when attendee-total and
   booking-count diverge (i.e. any booking with >1 seat), which is the common case once anyone
   brings a colleague.

## Non-bug things that looked like bugs at first (verify before reporting)

- Committee/host email subject showing "New booking: X ()" with empty parens was **not** a
  code bug — it was because my directly-`wp_insert_post`-created test fixtures had no
  `_law_reference` meta (the `{law_reference}` placeholder in `committee_booking_received`).
  Every event created through the real submission flow gets `_law_reference` from
  `law_events_next_reference()`; only my raw fixtures lacked it. Set it manually
  (`law_event_update_meta($id, '_law_reference', 'X')`) on any hand-built test event before
  triggering committee/host emails, or the subject/body will show trailing `()`.
- The host/committee bookings list renders as a real `<table>` with column headers, not the
  "grouped `.law-dashboard__people`-style blocks" EVENTS_BOOKINGS.md §7.4 describes. This is
  **intentional** — `parts/events/booking-list.php` has an explicit code comment: "A real table
  in the committee-dashboard idiom (Denis, 7 September 2026: 'more table view')" — i.e. Denis
  changed his mind after the design doc was written and the doc was never updated to match.
  Not a discrepancy to flag as a bug.
- The table (and the whole per-event bookings list) overflows even a 1280px desktop viewport
  once there are 7-8 columns, hiding the Reject button off-screen unless you scroll the
  `.law-dashboard__table-wrap` horizontally. This matches the code comment ("scrolls sideways
  on mobile **like every other dashboard table**") — it's the established sitewide idiom for
  every dashboard table in this app, not new/booking-specific. Confirmed reachable via
  `scrollLeft`, not a real blocker.
- "You + 2 guests" on an owner's booking card still says "You" even after the owner has used
  "Remove me" and is no longer a seated attendee (guests count = all non-owner-flagged rows,
  computed independently of whether an owner row still exists). Arguably fine ("this is your
  booking"), but flagged in case Denis wants it to read differently once the owner has left
  their own booking. Very low severity, did not report as a hard bug.

## Environment/spec conflict worth flagging to Denis (not a code bug)

Flow 1 of the task brief asked to verify the **logged-out** visitor sees the "Book now"
control (with sign-in/register links in the modal) on a single event page. That's not
currently reachable under the pre-launch gate: `functions/events/source.php:469-471`
unconditionally 302-redirects any **not-logged-in** visitor straight to `/login/` on any
`law_event`/`law_speaker` singular, regardless of the page's `_members_access_role` list —
confirmed via `wp_set_current_user(0); members_can_current_user_view_post(622)` returning
`false` even with `attendee`/`event_host` added to the role list (anonymous users have no
role to match). So the sanctioned gate-widening (adding `attendee`/`event_host` rows) only
ever helps **logged-in** users of those roles; it structurally cannot produce a "logged out,
can see the Book now control" state. Worked around it by testing the register-from-modal
destination URL (`/register/?role=attendee&redirect_to=...`) directly rather than by
clicking through from an unreachable anonymous view of the event page — that part passed
(role section correctly hidden, round-trip back to the event page signed-in works). If a
literal logged-out "Book now" experience needs sign-off before cutover, the pre-launch gate
itself (not just role widening) would need a temporary, larger bypass — flag this trade-off
to Denis/PM rather than assuming the current behaviour is a bug.

## Things that worked exactly as designed (no notes needed beyond the pass)

Registration with locked role + dietary "Vegan" saving to profile; welcome email; booking
with 2 colleagues (1 new, 1 existing) — correct booker/host/committee/new-account/
existing-account emails all firing with correct copy and .ics attachments; "You're booked on
this event" replacing Book now on reload; clash guard naming the other event inline in the
modal; capacity guard (server-enforced even when the client-side row cap is bypassed via DOM
tampering — tested deliberately to reach the owner+2-on-a-2-ticket scenario the brief asked
for, since the honest client-side cap math never allows constructing it through the UI alone
on a fresh page load); all 5 control states (open soon / book now / sold out+waitlist
disabled+out of tab order / event passed / already booked); `?law_book=1` no-JS inline form;
attendee self-service (remove colleague, add back, remove self keeps booking alive, cancel
booking with correct confirm copy and close-button relabels throughout); host bookings list
with Reject + reason + rejection email including the reason and host contact line; CSV/XLSX/
PDF exports (byte-inspected CSV and XLSX content, confirmed valid PDF); committee dashboard
"Bookings (n)" linking to the identical URL/view as the host's; wp-admin Bookings list columns
and the Booking/Attendees/Activity read-only meta boxes (activity correctly filtered to that
booking's log entries); the Events list "Booked" column (plain `sold` when available is unset,
`sold / available` otherwise, red+bold specifically when `sold > available && available > 0`
per code, verified both branches).

## Process notes for next time

- Building fixture events directly via `wp_insert_post` + `law_event_update_meta()` (the
  `LAW_Test_Case::make_event()` pattern from `tests/BookingsTest.php`) works fine for browser
  QA too, but always set `_law_reference` too or email placeholders render empty parens (see
  above).
- To exercise a server-side guard that the honest client-side JS cap prevents you from
  reaching through normal clicks (e.g. capacity overflow with the row-add cap already
  matching remaining places), bump the `data-law-max` attribute via `playwright-cli cli eval`
  before clicking "Add a colleague" the extra times, then submit through the real form. This
  tests the authoritative server guard + real error rendering without needing raw fetch().
- Unrelated, pre-existing noise to expect in Mailpit and wp-admin during any live/shared dev
  session: other concurrent test activity (saw "Best event ever ever" booking emails from a
  different session mid-pass — not mine, left alone) and a Gravity Forms plugin console error
  (`gf_vars is not defined`, `scripts-admin.min.js`) on every wp-admin edit screen, unrelated
  to the bookings module.
