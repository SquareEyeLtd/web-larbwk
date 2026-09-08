# Events bookings: build plan

> Status: **all eight phases landed on 7 September 2026.** Security gate: no
> critical/high findings, both medium concurrency findings fixed (§14). E2E
> gate (Playwright, 9 flows): pass, no blockers; its four cosmetic findings
> are fixed (AJAX manage actions now carry the success notice on their reload
> redirect, the empty-state "Browse the programme" link resolves the Programme
> page by path, "colleague(s)" wording in the capacity refusal, and the
> bookings-list summary uses one plural selector per noun). One E2E note for
> launch: logged-out visitors cannot reach single event pages while the
> pre-launch gate stands, so the modal's logged-out state is only reachable
> after the programme opens (by design; see "Build behind the pre-launch
> gate"). Post-build corrections by Denis: the bookings list is a flat table
> (booking number as a repeated column), and its back link is audience-aware
> (committee: "Back to all events" → the dashboard). Finally, three
> independent completion-verification passes (original brief; this document
> section by section; every decision, review fix and gate fix) were run on
> 7 September 2026: passes 1 and 3 found no gaps, and pass 2's findings are
> closed (the manage view's add-attendee form now hides on a full event, the
> repeater's required fields carry aria-required, two clash edge-case tests
> were added, and this document's stale signatures/wording were corrected).
> 84 PHPUnit tests green. **Committee round, 8 September 2026** (Denis's
> review answers): the cross-event Bookings dashboard (§7.6), registering an
> attendee on their behalf with a committee-only press flag (§7.4), the
> Country column on the list and exports (§7.4, §7.5), and the booking
> control's button renamed Register (§7.1). Receptions/external events, the
> flagship flow and an event-changed email stay deferred (§14).
> **Revision 2, 8 September 2026: one booking per attendee.** Denis decided
> that a colleague a booker brings should get their OWN booking with its own
> unique booking number, rather than a row on the booker's booking, and that
> the host, committee and admin lists should be flat, one row per person, with
> only an "Invited by {name}" tag to show who brought whom. That supersedes the
> party model described below wherever the two disagree, and it is what made
> the waitlist simple enough to build in the same round. **WAITLIST.md is the
> live contract for the booking data model and the waitlist**; the passages
> here marked "Superseded" are kept because this document is a dated record of
> what was agreed and built at the time, not a description of today's code.
>
> This is the design contract for the
> attendee bookings slice of the events platform. It was produced on 7 September 2026
> from Denis's brief, a scan of EVENTS_4.2_SPECS.md, seven rounds of scope decisions,
> and three specialist-persona reviews (UX, design reuse, backend DRY) whose accepted
> findings are folded in below. EVENTS_FUNC.md documents the code as built,
> phase by phase, per its own header rule.

## 1. What this is

Phase 4.1 rebuilt the host side (submission, moderation, invoicing) as a custom, CPT-backed
module in `functions/events/`. This document plans the attendee side: booking a place on a
published (Confirmed) event, bringing colleagues, and the host and committee views of who is
coming.

The major change from EVENTS_4.2_SPECS.md: **there is no cart and no pricing for hosted
events.** Bookings are free, so the basket (spec section 6.5), Stripe checkout for attendees
(section 7), discount codes, and eCommerce fields are all out. The build is custom code in
the 4.1 module style, not the Gravity stack the spec assumed (sections 4.5, 6.3, 6.5 name
Nested Forms, GravityView and eCommerce Fields; that stack is retired on this path).

## 2. Settled decisions

All confirmed by Denis on 7 September 2026.

### Scope

- **Booking control vocabulary: Register and Waitlist only.** Every Confirmed event is
  bookable on this site. No external-link, apply, invitation-only or admin-set registration
  states. The reserved event meta `_law_registration_state` stays unused (do not cut it, do
  not wire it). The button read "Book now" until 8 September 2026; Denis chose the spec's
  free-event word, "Register" (§3.4), for the control and the modal's submit. Receptions and
  the three externally booked hosts (GAR, LCIA, CIARB) are a later decision.
- **Registering on someone's behalf** (8 September 2026): a host, co-owner or committee
  member can register a person by name and email from the per-event bookings list (phone
  and email requests, VIPs, press). The person gets a booking of their own (§7.4).
- **Press passes** (spec §6.4) are a committee-only flag on that form: the row carries
  `is_press`, shown as a Press badge and a Press column in the exports, and filterable on
  the Bookings dashboard. Hosts registering someone cannot set it.
- **Country** is shown on the per-event list and in every export, read live from the
  profile like dietary and accessibility (spec §4.3 names it; it was missed in v1).
- **A cross-event Bookings dashboard** for the committee at `/account/dashboard/bookings/`
  (§7.6), linked from the header account dropdown as "Bookings dashboard".
- **Max 3 additional attendees per booking** (the spec's cap, section 6.3). With the
  duplicate guard this means one active booking per person per event, so a party is at most
  4 places: the booker plus 3 colleagues. The owner can add and remove attendees on their
  existing booking but can never open a second booking for the same event.
  *Superseded in part (8 September 2026): the cap survives as three COLLEAGUE
  bookings per booker per event, counted separately from their own, so a booker
  who cancels their own place still holds up to three and may book themselves
  again. See WAITLIST.md §A2.*
- **Dietary and accessibility are profile-only.** The booking form collects name, email,
  organisation and job title per attendee. Dietary and accessibility requirements live on
  each attendee's own profile (the invite email points new attendees there), and the host
  views and exports read them live from profiles. Attendees who never fill in their profile
  show blanks.
- **Booking closes at event start.** Once `_law_start` passes, the booking control shows
  "This event has taken place" and the server refuses new bookings and attendee additions.
- **No ticket number set means not open.** An event whose `_law_tickets_available` is 0 or
  unset shows "Bookings open soon" and is not bookable until the committee or host sets a
  ticket number. (At the time of writing, 7 of 63 approved or confirmed events have no
  ticket number.)
- **Superseded (8 September 2026): the waitlist is now built — see WAITLIST.md Part B.**
  Sold out kept a disabled "Join waitlist" button while it was deferred,
  mitigated: adjacent visible text saying the waitlist is not open yet, the button removed
  from the tab order, and a new `[aria-disabled="true"]` style. The server hard-stop is what
  actually protects capacity.
- **Any logged-in user can book; the attendee role is auto-granted.** The engine runs
  `add_role( 'attendee' )` on the booking owner when missing (logged to the event's activity
  log), exactly as it does for invited guests. This removes the "only attendee users can
  book" notice from the original brief; the booking modal has two states, logged out and the
  booking form.
- **Guards, all in:** duplicate prevention (an email cannot hold a place on the same event
  twice), clash detection (no booking onto an event that overlaps one you already attend,
  with the conflicting event named), and a capacity warning email to the host when the event
  is within 5 places of capacity.
- **Owner self-service:** the owner can remove themselves (the booking stays alive for
  their colleagues), remove a colleague, add a colleague, or cancel the whole booking.
- **Removal emails on every removal**, context-specific: host or committee reject, owner
  removing a colleague, self-removal, whole-booking cancel and event-cancel each have their
  own template (section 9).
- **Event cancelled by the committee:** all its active bookings are cancelled and every
  attendee is emailed.
- **New-booking committee email goes to the event's assignee** (`_law_assignee`), falling
  back to the committee recipients setting when unassigned.
- **Exports are CSV, Excel and PDF**, the same trio as the committee dashboard, reusing
  `functions/events/export.php` wholesale.
- **Extras:** .ics calendar attachments on the confirmation and invite emails, and a welcome
  email for new self-registered users (closing open decision 2 in EVENTS_FUNC.md).
- **Build behind the pre-launch gate.** The Programme page (page 622, Programme) and single
  event pages stay Members-gated to admin, editor and committee; QA runs with those accounts
  plus a temporary gate bypass for test attendees. Opening the programme to the public is a
  separate launch decision, not part of this build.
- **Close the build with two gates, in order:** a security-specialist review of the whole
  bookings surface, then a full test-specialist (Playwright) pass over every flow.

### Divergences from EVENTS_4.2_SPECS.md (the brief wins)

- Hosts get a per-attendee Reject action. Spec section 4.3 reserved registration removal for
  LAW admin; Denis's brief explicitly gives it to hosts and the committee.
- The spec's booking machinery assumptions (Gravity Forms, Nested Forms, GravityView) are
  obsolete on this path; the build follows the 4.1 custom module architecture.

## 3. Data model

### 3.1 The `law_booking` CPT

Registered in `functions/events/post-types.php` alongside the other three
(`LAW_BOOKING_CPT = 'law_booking'`), modelled on `law_session`: not public, `rewrite =>
false`, `supports => array( 'title' )`, the shared `law_events_capability_args()`
(`query_var => false`, `show_in_rest => false`), shown as a submenu of the Events CPT menu.
On top of the shared capability set: `create_posts => 'do_not_allow'`, because bookings are
only ever created by the engine, so the guards, recounts and emails can never be bypassed
from wp-admin. The admin screen is read-only in v1 (section 10).

Structural fields, no meta needed:

- **Event link = `post_parent`** (native, indexed, queryable; the admin screen links
  `get_edit_post_link( $post->post_parent )`).
- **Owner = `post_author`**, matching how events model their host.
- **Status = real post statuses:** active is `publish`, cancelled is `law-cancelled`
  (post statuses are not per-post-type, so the one registered by
  `law_events_register_statuses()` is reused at no cost). The only transition is cancel,
  guarded inside `law_booking_cancel()`; there is no un-cancel and no entry in
  `law_event_workflow_actions()` (that machine is for events).

Status protection: the `wp_insert_post_data` status guard and the `wp_untrash_post_status`
filter in `workflow.php` are event-only today. Both are extended to `LAW_BOOKING_CPT`: the
guard gated on a `$GLOBALS['law_booking_transitioning']` flag set inside
`law_booking_cancel()` (mirroring the events flag), and untrash restoring
`_wp_trash_meta_status` constrained to `publish` or `law-cancelled`. Without this, quick
edit could resurrect a cancelled booking (silently re-consuming places) and untrash would
park a booking in core `draft`, a status no query reads.

### 3.2 Attendee storage: one ordered rows array, owner included

> **Superseded (8 September 2026).** One booking per attendee: the rows array,
> the flat `_law_booking_attendee` index and the `attendee_rows` sanitiser are
> gone, replaced by scalar snapshot meta plus `_law_booked_by`. See
> WAITLIST.md §A1.

`_law_attendee_rows`: ordered rows of
`{ user_id, name, email, organisation, job_title, is_owner }`, where **row 0 is the owner**,
snapshotted from `law_profile_values()` at creation. Rows 1 to 3 are additional attendees as
entered on the form.

Why one unified array: every downstream requirement becomes uniform. Seat counting is
`count( $rows )`, the duplicate guard is one email scan, host Reject works identically on any
attendee including the booker, "removing the last attendee cancels the booking" needs no
special case, and the owner can remove themselves without cancelling (the booking stays
alive; management rights ride `post_author`, never a seat).

Name, email, organisation and job title on the row are the display snapshot for the host
list and exports (as entered at booking time); dietary and accessibility are always read
live from the profile via `law_profile_values( $row['user_id'] )`.

Plus flat index meta, exactly the `_law_co_owner` pattern: one `_law_booking_attendee`
postmeta row per attendee user ID, rewritten by the single write path
`law_booking_set_attendee_rows( $booking_id, $rows )`. This is what "Your bookings" and the
clash guard query; the serialised array is never string-matched. **The flat key stays out of
the meta schema** (registering it would force `single => true` onto a deliberately multi-row
key), exactly as `_law_co_owner` does today.

### 3.3 Meta schema additions

New `law_booking_meta_schema()` in `functions/events/meta.php` with the other three schemas,
registered in `law_events_register_meta()` and merged into the memoised
`law_events_all_meta_schemas()`:

| Key | Sanitiser |
|---|---|
| `_law_booking_number` | `int` |
| `_law_attendee_rows` | `attendee_rows` (new type, modelled on `people_rows`, adds `user_id` and `is_owner`) |

Additions to `law_event_meta_schema()` (on events):

| Key | Sanitiser | Purpose |
|---|---|---|
| `_law_tickets_sold` | `int` | stored places-sold counter (3.5) |
| `_law_capacity_warned` | `flag` | one-shot capacity warning latch (9.4) |

The `_law_attendee_rows` fallback shape joins the array-types list in `law_event_meta()`.
Note for the build: the schema `auth_callback` (`edit_law_events`) is inert on the engine's
plain `update_post_meta` path and REST is off, so attendees lacking the capability is fine.

### 3.4 Booking numbers

`law_bookings_next_number()` via a new shared helper `law_events_bump_counter( $option )`
(get, increment, update; `law_events_next_reference()` switches to it too, so the counter
logic exists once). Option `law_bookings_counter`, starting at 1, `autoload => false`.
Non-atomic, accepted at current volumes exactly like the LAW reference counter. The post
title is set to "Booking #N"; the number is never derived from the title.

### 3.5 Places sold and remaining

`law_event_recount_attendees( $event_id )` sums `count( _law_attendee_rows )` across the
event's `publish`-status bookings, writes `_law_tickets_sold`, and returns the int. It runs
after **every** mutation: create, add, remove, reject, cancel, and the event-cancel sweep.

Stored rather than computed live because the programme render is the module's hot path
(hundreds of `law_event_meta()` reads per render, all riding the postmeta cache); a live
count would add a query per event. Recalculation is cheap and self-healing.

Recount backstop: wp-admin trash, untrash and permanent delete bypass the engine, so two
small hooks keep the counter honest: a `transition_post_status` callback filtered to
`LAW_BOOKING_CPT`, plus `deleted_post` (its second argument still carries `post_parent`
after a hard delete), both calling the recount. They double as a backstop for the status
guard.

`law_event_tickets_remaining( $event_id )` returns available minus sold, clamped at 0, and
**null when `_law_tickets_available` is 0 or unset**, which means "not open for booking"
(the "Bookings open soon" state). `law_events_map_post()` gains `tickets_sold` and
`tickets_remaining` in the mapped shape; the front end reads `tickets_remaining` for the
booking control, never the legacy `tickets` key (whose 0 means "unset").

## 4. File layout

```
functions/events/request.php               NEW shared request plumbing: law_events_guard_post(),
                                           law_events_respond(), law_events_nopriv_json(), plus
                                           law_events_redirect_back() and law_events_rate_limit_ok()
                                           moved here from comments.php (a pure move; callers grepped)
functions/events/bookings.php              the engine, guards, queries, recount, email triggers,
                                           admin-post handlers and the export endpoint (module
                                           convention: a domain file owns its handlers)
functions/events/ics.php                   .ics generation
functions/events/bookings-dashboard.php    the committee's cross-event Bookings dashboard (§7.6):
                                           filters, row builder, partial endpoint, export, assets
functions/events/admin/booking-screen.php  booking meta boxes and admin list columns
functions/account-bookings.php             front-end render helpers (mirrors account-events.php)
templates/account-bookings-dashboard.php   the Bookings dashboard page template (§7.6)
parts/events/bookings-dashboard-list.php   its table partial (also the &law_partial=1 response)
```

Events-module files are required from `functions/events/_load.php` (request.php early,
before comments.php); `account-bookings.php` from `functions.php` after `account-events.php`.

**The shared request guard is built first.** The nonce-then-JSON, `check_admin_referer`,
honeypot, rate-limit, JSON-versus-redirect sequence already exists in six handlers
(comments.php, workflow.php, committee.php, export.php, submission-form.php,
registration.php); the bookings surface would have been the sixth copy. Every new handler is
written against:

- `law_events_guard_post( $nonce_action, $args )`, returning `$is_ajax`; `$args` carries the
  rate surface, max and window, and the honeypot pretend-success payload (the only part that
  varies between handlers);
- `law_events_respond( $is_ajax, $ok, $payload, $notice )` for the JSON-versus-
  `law_events_redirect_back()` tail;
- a named `law_events_nopriv_json()` for the `admin_post_nopriv_*` registrations (replacing
  the closure currently copied verbatim in three files).

Existing handlers are left untouched and migrated opportunistically later.

## 5. The booking engine (`functions/events/bookings.php`)

> **Superseded (8 September 2026): see WAITLIST.md §A2.** This section describes
> the party model: one booking carrying an attendee rows array, with
> `law_booking_remove_attendee()` and a last-row auto-cancel. There is now one
> booking per attendee, and removal is `law_booking_cancel()` on that person's
> own booking.

All engine functions return `WP_Error` on refusal and log to the parent event's activity
log (section 11).

- `law_booking_create( $event_id, $owner_id, $additional_rows )`: open guard (the post is a
  `law_event`, status `publish`, source is cpt, tickets set, not started) → build the row
  set (owner row 0 from `law_profile_values()`, max 3 additional rows) → duplicate guard →
  capacity guard → clash guard for every attendee → auto-grant the owner's attendee role if
  missing → ensure attendee users → `wp_insert_post` (status `publish`, `post_parent`,
  `post_author`) → number, rows, flat index → recount → emails → capacity-warning check.
- `law_booking_add_attendee( $booking_id, $row, $actor_id )`: the same guards scoped to one
  place, then ensure user, append row, recount, emails, capacity check.
- `law_booking_remove_attendee( $booking_id, $email, $actor_id, $context )` (rows are
  addressed by email, unique per event): `$context` is
  `owner`, `self` or `host_reject`; removes the row (never the WordPress user), recounts,
  sends the context's removal email, logs with context. **When the last row goes, it calls
  `law_booking_cancel()`** and the log line says why.
- `law_booking_cancel( $booking_id, $actor_id, $context )`: status to `law-cancelled`
  (behind the transitioning flag), recount, emails every seated attendee, logs. Idempotent.
- Reject is remove with the `host_reject` context and an optional reason.
- `law_booking_register_by_manager( $event_id, $row, $actor_id, $args )` (8 September
  2026): clean the row → fast-fail the open, duplicate and capacity guards → resolve or
  create the attendee account → `law_booking_create( $event_id, $user_id, array(), $args )`
  with `actor`, `on_behalf`, `new_account`, `press` and `owner_row` (organisation and job
  title fallbacks for a blank profile). The person owns the booking, so every guard and
  side effect is the self-service one; only the log line ("created by X on behalf of Y")
  and the confirmation template differ. A refused booking deletes the account it just
  created, so no orphan accounts are left behind.
- `law_booking_ensure_attendee_user( array $row, $event_id, $booking_id, $actor )`:
  existing email → link the account, `add_role( 'attendee' )` if missing, send
  `user_attendee_added`; new email → create the account via the **parameterised**
  `law_events_create_host_user()` (which gains a fourth
  `$args = array( 'role' => 'event_host', 'job_title' => '' )` parameter; defaults keep its
  two existing callers untouched) with role `attendee` and job title meta, then send
  `user_attendee_invited` with the set-password link. The link is minted by a new extracted
  helper `law_events_password_setup_link( $user, $event_id, $log_action )`, pulled from the
  identical block in co-owners.php (including the expired-key `{forgot_link}` fallback and
  the mint-failure log); `law_event_notify_co_owner_created()` switches to it too.
- Guards:
  - `law_booking_guard_open()`: post type, `publish`, cpt source, tickets set, not started.
  - `law_booking_guard_duplicates()`: lowercased email compared against every row of every
    **active** booking on the event, and against the other rows in the same submission.
  - `law_booking_guard_capacity()`: places requested versus `law_event_tickets_remaining()`.
  - `law_booking_guard_clash()`: events the user already attends
    (`law_user_booked_event_ids()`), overlap test
    `other_start < this_end AND other_end > this_start` on `_law_start`/`_law_end`. A
    missing end is treated as 23:59 of the start date (conservative: an "18:00 onwards"
    event clashes with a 19:00 one). The error names the conflicting event.
- Queries, with explicit caps per house style: `law_bookings_for_event()` (cap 500),
  `law_user_booking_ids()` (author OR flat index, union, cap 200),
  `law_user_booked_event_ids()`, `law_event_attendee_total()` (reads `_law_tickets_sold`).
- **Concurrency:** a MySQL advisory lock, `GET_LOCK( 'law_booking_event_{id}', 3 )`, taken
  inside the engine around the recount, capacity re-check and write on create and add,
  released on every path including `WP_Error` returns (single exit point). This closes the
  two-tabs-book-the-last-place race.
- **Event-cancel sweep:** `law_bookings_cancel_all_for_event( $event_id, $actor, $source )`,
  called directly from `case 'cancel'` in `law_event_workflow_side_effects()` after the host
  email (the module wires side effects as direct calls; there are no do_action hooks).
  Cancels the event's active bookings, logs each, and sends `user_booking_event_cancelled`
  to every attendee. Withdraw needs no sweep: it only transitions from draft, proposed or
  sent-back, which can never hold bookings because the open guard requires `publish`.

## 6. Handlers and security

> **Superseded in part (8 September 2026): see WAITLIST.md §A3, §B3.** The
> contract (shared guard, nopriv JSON, IDOR rules, rate limits) still holds;
> the handler table does not. `law_booking_remove_attendee` is gone,
> `law_booking_cancel_party` and the three `law_waitlist_*` actions are new,
> and add-attendee posts an event rather than a booking.

Every handler is built on the shared guard (section 4) and follows the established contract
(`law_event_handle_withdraw()` is the model): nopriv variant answering JSON 401 when
`law_ajax` is posted; `wp_verify_nonce` JSON branch before `check_admin_referer`;
relationship gate; honeypot `law_website_url` (pretend success); rate limit;
`wp_send_json_success( { title, message, redirect } )` or `wp_send_json_error( { message },
code )` versus `law_events_redirect_back()`. Nonce string equals the action name.

| Action = nonce | Gate | Rate limit |
|---|---|---|
| `law_booking_create` | logged in + cpt source (attendee role auto-granted) | `booking`, 10 per 600s |
| `law_booking_add_attendee` | booking owner | `booking_edit`, 15 per 600s |
| `law_booking_remove_attendee` | owner, or the row's own user (self-removal) | `booking_edit` |
| `law_booking_cancel` | booking owner | `booking_edit` |
| `law_booking_reject_attendee` | `law_user_can_manage_event( post_parent )` | `booking_edit` |
| `law_booking_register_attendee` | `law_user_can_manage_event( event_id )` — the event is the subject, no booking exists yet; `law_press` honoured only for `law_user_is_committee()` | `booking_edit` |
| `law_booking_export` (GET, `format=csv\|xlsx\|json`) | `law_user_can_manage_event` | none (read-only) |
| `law_bookings_dashboard_export` (GET, `format=csv\|xlsx\|json`, filter params) | `law_user_is_committee()` | none (read-only) |

Rate limits pass explicit max and window arguments (the helper defaults to 300 seconds).

IDOR rules: every handler loads the booking, verifies `post_type === LAW_BOOKING_CPT`,
derives the event from `post_parent` (never from POST), and only then checks the actor
relationship. Attendee input is capped at 3 additional rows server-side, every email passes
`is_email`, and all row fields go through the schema sanitiser. Export cells pass the
existing `law_events_csv_guard()` formula-injection guard. wp-admin stays blocked for
attendees and hosts (functions/wordpress.php); every surface here is front end plus
admin-post.php, which that block excludes.

## 7. Front end

### 7.1 The booking control on the single event page

`law_booking_render_action( $event )` (in `functions/account-bookings.php`) renders in the
footer row of the hero's event details box (`parts/calendar-event-details.php`), next to the
facts, rather than at the bottom of the article where the placeholder Register anchor used to
be (the "Back to events calendar" button stays there). Because that box carries the
`law-cal` class, the control keeps the `.law-cal`-gated styling it depends on: the
`aria-disabled` treatment that keeps the sold-out placeholder inert, the button hover, and
the light-surface `.law-form-notice` colours.

Both of the control's dialogs are `position: fixed`, and the hero's `.grid-container` is a
stacking context (`z-index: 4`), which would clamp them and paint them under the fixed
header. So `law_booking_footer_modal()` defers them to `wp_footer`, at body level:
`parts/events/booking-modal.php` (`modal` context) and the extracted
`parts/events/booking-success-modal.php`. The `?law_book=1` inline form is not fixed and
stays in the flow, inside the box.

Five states:

1. **Bookings open soon**, when no ticket number is set: a paragraph at the
   `.law-cal-acc__heading` scale (no bespoke "big text" style exists in the theme) with the
   sub-line "Booking for this event hasn't opened yet. Check back soon."
2. **Register** (`button orange`, "Book now" until 8 September 2026; no arrow icon: the arrow SVG is the theme's
   "leaves this page" affordance and this opens a modal) when places remain, with
   "N places left" beside it.
3. **Sold out**: the disabled "Join waitlist" button (`aria-disabled`, out of the tab
   order, styled by a new `[aria-disabled="true"]` rule that also fixes the existing
   unstyled Register placeholder) with adjacent text "This event is fully booked. The
   waitlist isn't open yet."
4. **This event has taken place**, once the event has started or ended.
5. **You're booked on this event**, when `law_user_booked_event_ids()` contains the event:
   a "Manage booking" link for the owner, "View booking" for an additional attendee. Without
   this state, a booked user is funnelled into the duplicate-guard error, including
   immediately after their own booking's page reload.

Wording is **places, never tickets**, everywhere (control, modal, refusal copy, hero fact):
the events are free and un-ticketed. The hero "Available tickets" fact becomes
"Places remaining".

The archive/programme card loses its disabled Register default action entirely
(`parts/loop/event.php`); the default card action is Event details only.

### 7.2 The booking modal

A bespoke partial, `parts/events/booking-modal.php`, reusing the `.law-modal` skeleton and
classes so `law-modal.js` open, close and focus-trap behaviour comes free. It is not built
through `parts/layout/modal.php`'s args (that component is confirm-dialog shaped, with a
single-field assumption). No native `required` attributes inside the hidden dialog (house
rule): the name and email inputs carry aria-required, required-ness is marked with * in the
labels, and enforcement is server-side with row-keyed inline errors.

Two server-rendered states:

- **Logged out**: copy explaining an account is needed, a login link carrying
  `redirect_to` = the event permalink, and a register link
  `?role=attendee&redirect_to={permalink}`.
- **Logged in**: the event summary (title, date, time), the note that accounts will be
  created for additional attendees, the attendee repeater, and the Register submit with a
  busy label.

Form styling: the form is wrapped `law-event-form law-event-form--light`. The base form and
repeater styles are dark-hero-first (borderless white inputs, translucent rows) and are
invisible on the white dialog; the `--light` variant already exists for exactly this (the
committee edit view uses it). `event-form.css` and a head-time `law_modal_enqueue()` are
enqueued on the programme/single-event view, where neither currently loads. New CSS is
minimal and lives in the existing files (calendar.css for the control states and the
`[aria-disabled]` rule, law-modal.css for a `.law-modal a` link rule, since `.law-cal a`
strips link affordance, and possibly a dialog max-width bump); **no new CSS file**.

Repeater (`parts/events/attendee-repeater.php`, a four-field variant of
`people-repeater.php` on the same `data-law-rows-group`/`__i__` contract): starts with
**zero** additional rows and an "Add a colleague" button; the client caps rows at
`min( 3, places_remaining − 1 )` and prints the remaining count in the modal. Focus
behaviour: fields are never disabled-while-hidden, initial focus goes to the dialog or first
field, a new row focuses its first input, removing a row returns focus to "Add a colleague".
Inside the dialog the three-up row grid likely collapses to one column (verify in phase 3
along with law-modal.js's handling of a multi-field dialog).

Submission (`assets/js/booking-form.js`, dependency `law-modal`, filemtime-versioned; one
script drives every `.law-booking-form` on the site): fetch with `law_ajax=1` and a busy
state. **Validation errors are row-level**: the server returns field/row-keyed errors,
the JS marks the offending control inline and focuses the first invalid field, and every
refusal names the person and the fix, for example:

- duplicate: "jane.smith@firm.com already has a place at this event, so please remove that
  row";
- clash: "Jane Smith is already booked on '{other event}', which overlaps with this one";
- capacity: "Only {n} places are left. Please remove {x} colleague(s) and try again."

The **booking-created success dialog has no auto-reload timer** (the 3-second withdraw
pattern suits one-liners only): its copy covers the confirmation email and calendar invite,
colleagues being emailed to set up accounts and add dietary or accessibility requirements,
and where to manage the booking; buttons are "View my bookings" and "Close" (Close reloads).
Quick row-level actions on the manage view keep the auto-reload pattern.

No-JS path: the Register opener degrades to a link to `?law_book=1` on the event permalink,
which server-renders the same form inline in the detail body; JS upgrades the control to
open the modal instead. Failed no-JS submissions re-render with typed values via the
transient state pattern (`law_registration_state()` precedent). Every other action here is a
form-shaped modal action with the house plain-submit fallback.

### 7.3 Account area: Your bookings and the manage view

> **Superseded (8 September 2026): see WAITLIST.md §A5.** The manage view now
> shows the viewer's whole party on the event, one row per person with their
> own booking number, and the section is headed "My bookings".

Routing is GET-param sub-views on `/account/events/` (page 292, My events), the house
pattern (`?law_thread=` precedent); no new pages, so no Members, `law_setup_account_pages()`
or `law_migration_page_map()` churn. `?law_booking={id}` is the manage view;
`?law_event_bookings={event_id}` is the per-event bookings list (deliberately not
`law_bookings`, one letter from its sibling).

**Access fix, scripted:** the page's Members restriction (`_members_access_role` on page
292, My events) currently lacks `attendee`, which would block the whole section for pure
attendees. The attendee role is added to that page's restriction via migration step 10 /
`law_setup_account_pages()`, so staging and production get it too; it must not be a local
click.

**Your bookings** renders above the host list: `law_account_bookings()` returns
booking-plus-event items for the user's active bookings, rendered with the existing
`parts/loop/event` cards. Owner cards: meta lines for host and "You + N guests", actions
"View event" (`arrow => true, external => true`, new tab) and "Manage". Additional-attendee
cards are guest-framed: "Booked by {owner name}" and a "View booking" action. The page now
serves two audiences: paired sentence-case h2 headings ("Your bookings" / "Your events"),
and the host list, its empty state and the Submit toolbar are suppressed for users without a
host-type role, so a pure attendee never sees "You have not submitted any events yet".
An attendee with no bookings gets a gentle empty state pointing at the programme
(`.law-cal__empty` styling).

**The manage view** (`parts/events/booking-manage.php`; access: owner or any row's user):
event summary, then per row a Remove button (owner) or "Remove me" (that row's own user),
each behind a `parts/layout/modal.php` confirm; an add-attendee block (one repeater row, cap
3 minus current, hidden when the event is full), and for the owner a Cancel booking button
behind a confirm modal. Specified confirm copy:

- Remove me: "Your place is freed for someone else, but your colleagues keep theirs and you
  can still manage this booking."
- Cancel booking: "This cancels the place of everyone on this booking ({names}). Each person
  is emailed to let them know."
- Last-row removal: "This is the last person on the booking, so the whole booking will be
  cancelled." (The template knows when a row is the last one.)

Close-button relabels per house rule: Cancel booking → "Keep the booking"; Remove / Remove
me → "Keep them on the booking" / "Stay on the booking"; Reject → "Keep this attendee".
Destructive confirm buttons use `button alert` via the modal `confirm` class arg.

Notices: the `$law_notice_text` map in account-events.php gains `booking-created`,
`booking-cancelled`, `attendee-added`, `attendee-removed` and `booking-failed`.

### 7.4 Host and committee bookings list

> **Superseded (8 September 2026): see WAITLIST.md §A5, §B5.** The table is
> flat, one row per booking (which is one row per attendee), with no grouping
> and no repeated booking number: a colleague's row carries an "Invited by
> {name}" tag and nothing else. It also gained a waitlist section.

Host cards gain a "Bookings (n)" action (plain `button`; n is
`law_event_attendee_total()`, total attendees, not booking count) linking to
`?law_event_bookings={event_id}`. The committee dashboard list links the same URL from its
rows: one view, one gate (`law_user_can_manage_event()` passes for committee).

The list (`parts/events/booking-list.php`): a flat table in the committee-dashboard idiom
(Denis, 7 September 2026: less height, "more tablish" — this superseded the design review's
grouped-blocks recommendation). Columns: Booking (the number repeats per attendee row, the
CSV's shape, with the booker's name as a sub-line on each booking's first row and a subtle
rule between bookings), attendee name (with a Press badge on press-pass rows), email,
organisation, job title, country (live from the profile, added 8 September 2026), and
accessibility and dietary read live from `law_profile_values()`, comma-joined including the
"Other" free text (these two columns wrap; the rest keep the table's nowrap and the wrap
scrolls sideways on mobile).

**Register an attendee** (8 September 2026), below the table while the event is open with
places left: full name, email, organisation, job title and, for committee members only, a
"Press pass" checkbox; submit "Register attendee" (busy "Registering…"). Same
`.law-booking-form` fetch layer as everything else, so refusals mark the offending field
inline (duplicate, clash, capacity, invalid email); the no-JS fallback re-renders the typed
row via the form-state transient. Success reloads the list with the `attendee-registered`
notice. The person is emailed `user_booking_registered` (existing account) or
`user_booking_registered_invited` (new account, set-password link), both naming who
registered them; the host and committee copies go out as for any booking. The container carries the `.law-dashboard` class so the existing light-section
colour resets apply (account-events.php wraps in `.law-cal` only). Per attendee, a Reject button
behind a confirm modal with an optional reason field, submitted over fetch with the plain
POST fallback. Cancelled bookings show collapsed at the bottom with the existing
`law-cal-card__badge--cancelled` badge (grey is the rejected colour; no invented grey
treatment). A booking whose owner row was rejected renders "by {owner} (not attending)" in the
Booking column's sub-line; the owner keeps manage rights.

### 7.5 Exports

> **Superseded in part (8 September 2026).** One row per booking, and the
> column set gained "Invited by" and "Country".

Buttons "Export: CSV | Excel | PDF" (`button second`, the `.law-cal-export` markup) at the
top of the bookings list. One shared row builder, `law_booking_export_rows( $event_id )`:
active bookings only; columns Booking ID (repeated per attendee), First name, Second name,
Email, Organisation, Job title, Country (live from the profile), Press ("Yes" or blank),
Accessibility (comma-joined), Dietary (comma-joined); the
name split prefers the linked user's first and last name, falling back to splitting the
snapshot on the first space. A title line, "Attendees for {event title}, {date} {time}", rides the CSV
title row, the XLSX first row and the JSON `title`.

One GET endpoint, `law_booking_export` with `format=csv|xlsx|json`, modelled on
`law_committee_export_handler()` (manual `wp_verify_nonce` JSON 403 before
`check_admin_referer` on the json branch) but gated on `law_user_can_manage_event()`. CSV
via `law_events_send_csv()` (UTF-8 BOM plus the formula-injection guard), Excel via
`law_events_send_xlsx()`, PDF client-side via the `format=json` branch and
`assets/js/export-buttons.js` with vendor pdfmake, enqueued on the bookings-list view only
(mirroring the gated enqueue in export.php; pdfmake is ~3MB). export-buttons.js may need a
light generalisation, since it currently assumes the dashboard endpoint; verify in phase 5.

### 7.6 The committee's Bookings dashboard (8 September 2026)

Why: the per-event list and the read-only wp-admin list (searchable by title only) left the
committee unable to answer "which events is jane@firm.com booked on?" or to pull every
attendee of the year in one file for badging (the third-party badging solution in spec §1
needs exactly that export). Spec §7.5's "visible to LAW for customer service" is this view.

- **Page**: `/account/dashboard/bookings/`, a child of the events dashboard, template
  `templates/account-bookings-dashboard.php`, in `law_migration_page_map()` and
  `law_setup_account_pages()`. `law_setup_bookings_dashboard_access()` copies the parent's
  Members roles onto the child when it has none (a page the migration creates carries no
  restriction, which Members reads as public); the template also checks
  `law_user_is_committee()`. Header dropdown item "Bookings dashboard", committee only,
  after "Events dashboard" (`law_account_paths()` key `bookings`; HeaderNavTest pins it).
- **Filters**: keyword (name, email, organisation, job title, booking number as `#12` or
  `12`, event title; case-insensitive, applied in PHP over the fetched set, never a LIKE over
  serialised meta), event (only events holding a booking, ordered by start), status (active
  by default, cancelled, all), attendee type (all, press only), and programme year when more
  than one `law_year` term exists. The bar is the events dashboard's markup driven by
  calendar-filters.js over `&law_partial=1`; selects only, since that script reads a
  checkbox's value regardless of its checked state.
- **Table** (`parts/events/bookings-dashboard-list.php`): one flat row per attendee, the
  per-event list's shape plus the event: Booking (number linked to the per-event list,
  booker sub-line), Event (title linked, start), Attendee (booker flag, Press badge), Email,
  Organisation, Job title, Country, Status badge, Booked. A summary line ("N attendees across
  N bookings on N events"). Screen cap 2,000 bookings with a notice when hit; exports are
  uncapped. Read-only by design: Reject and Register live on the per-event list, so
  mutations have one home.
- **Export**: `law_bookings_dashboard_export` (committee only), columns Booking ID, Event,
  Event date, Reference, First name, Second name, Email, Organisation, Job title, Country,
  Press, Status, Booked on, Accessibility, Dietary; the title line records the filters.
  export-buttons.js refreshes the CSV/Excel hrefs from the live filter form; PDF via pdfmake
  as elsewhere (A3 landscape, since the column count exceeds ten).
- Code: `functions/events/bookings-dashboard.php` (filters, event picker, row builder,
  export rows, the partial endpoint, the export handler, the committee-only asset gate).
  Tests: `tests/BookingsDashboardTest.php`.

## 8. Registration and login changes

- `templates/register.php` reads `?role=` (whitelisted against `law_registration_roles()`
  keys) and `?redirect_to=` (`wp_validate_redirect`); when a locked role is present the Role
  section is hidden (a `locked_role` arg on `parts/events/profile-fields.php`) and hidden
  `roles[]` and `redirect_to` inputs are emitted. Both params survive the already-signed-in
  bounce and the error-state redirects, so the locked state and destination hold across a
  failed validation round-trip.
- `law_registration_handler()` uses the validated `redirect_to` on the success and honeypot
  redirects, falling back to `/account/?action=registered`, and fires the new
  `user_welcome_registered` email after the admin notifications.
- Login needs no change: `law_auth_redirect_to()` already round-trips `redirect_to`, and the
  committee override only applies when no explicit target is set.

## 9. Emails and .ics

> **Superseded in part (8 September 2026): see WAITLIST.md §A4, §B4.** The
> removal family was renamed to a cancellation family, the confirmations carry
> each person's own booking number, and eleven waitlist templates were added.

### 9.1 New registry entries

All in `law_events_email_registry()`, editable on the Emails screen, sent through
`law_events_send()` (every send logged; test mode applies as everywhere).

| Slug | To | Trigger |
|---|---|---|
| `user_booking_confirmed` | dynamic (the booker) | booking created; event details, attendee list, accounts-created note; **.ics attached** |
| `host_booking_received` | host | booking created; full attendee list including the booker, places remaining |
| `committee_booking_received` | registry `to => 'committee'`; the caller passes `$extra['to']` = the assignee's email when `_law_assignee` resolves (the `committee_assignee` pattern) | booking created |
| `user_attendee_invited` | dynamic (new account) | account created; set-password link, profile link for dietary and accessibility, prominent link to their events; **.ics attached** |
| `user_attendee_added` | dynamic (existing account) | linked to a booking; **.ics attached** |
| `user_attendee_rejected` | dynamic | host or committee reject; carries `{removal_reason}` and a host contact line ("If you think this is a mistake, please contact the host at {host_email}") |
| `user_attendee_removed` | dynamic | the owner removed a colleague |
| `user_attendee_removed_self` | dynamic | self-removal confirmation |
| `user_booking_cancelled_attendee` | dynamic (each seated attendee) | the owner cancelled the whole booking |
| `user_booking_event_cancelled` | dynamic (each attendee) | the event was cancelled (sweep) |
| `user_welcome_registered` | dynamic | self-registration |
| `host_capacity_warning` | host | remaining places at 5 or fewer with a positive limit, one-shot |
| `user_booking_registered` | dynamic (the registered person) | a host or committee member registered them (existing account); names `{registered_by}`; **.ics attached** |
| `user_booking_registered_invited` | dynamic (new account) | as above when an account was created: carries the set-password link in the same email, so one email not two; **.ics attached** |

One removal-family template per context, because a single "you have been removed" email
mis-describes three of the four contexts and is the removed person's only explanation.

Two recorded behaviours: when an assignee exists, the `$extra['to']` override silently beats
the Emails screen's To field (same as `committee_assignee` today); and
`user_welcome_registered` is **unlogged**, since `law_event_log()` needs an event and
registration has none (`admins_user_registered` already behaves the same).

### 9.2 Placeholders

`law_events_email_placeholders()` gains generic `{event_date}` and `{event_time}` (from
`_law_start`/`_law_end`), plus per-send values via `$extra`: `{attendee_name}`,
`{attendee_list}`, `{booking_number}`, `{tickets_remaining}`, `{tickets_available}`,
`{profile_link}`, `{bookings_link}`, `{removal_reason}`, `{host_email}`, and (8 September
2026) `{registered_by}` for the registered-on-behalf pair.

### 9.3 Attachments and the .ics generator

`law_events_send()` gains `$extra['attachments']` (absolute file paths), passed as
`wp_mail()`'s fifth argument. Verified compatible: the test-mode filter (priority 99) and
the Email Templates wrapper (priority 100) touch recipients and body only. The attachment is
noted in the activity-log context; the caller deletes the temp file after the synchronous
send.

`functions/events/ics.php`: `law_event_ics( $event_id )` and
`law_event_ics_tempfile( $event_id )` (its own `.ics`-named file in `get_temp_dir()` —
mail clients pick the calendar handler off the extension, which `wp_tempnam`'s `.tmp`
would defeat). VCALENDAR/VEVENT with UID
`law-event-{id}@{host}`, DTSTAMP, SUMMARY, LOCATION (venue), DESCRIPTION (excerpt plus
permalink), CRLF line endings, 75-octet folding, comma and semicolon escaping. Times:
convert the stored naive `Y-m-d H:i` strings from `wp_timezone()` (Europe/London) to UTC and
emit `DTSTART:…Z`; no VTIMEZONE block to maintain, correct across the BST/GMT boundary. A
missing end defaults to start plus 2 hours. Attached only when the event has a start.

### 9.4 Capacity warning

At the end of the recount inside the create and add paths: when a positive limit exists,
remaining is 5 or fewer and `_law_capacity_warned` is unset, send `host_capacity_warning`
and set the flag. The recount clears the flag whenever remaining rises above 5 (removals),
so a second approach warns again.

## 10. Admin UI (`functions/events/admin/booking-screen.php`)

> **Superseded (8 September 2026): see WAITLIST.md §A6.** One booking is one
> attendee, so there is no Attendees box; the facts box carries the attendee
> and who invited them.

Read-only in v1: every mutation goes through the engine so guards, recounts and emails
always run; wp-admin's job is inspection. (If the committee later needs an admin cancel, a
button posting the existing handler is the route.)

- Meta boxes on `add_meta_boxes_law_booking`, hand-rolled like the event screen: **Booking**
  (number, status, created date, the parent event linked to its edit screen and to the
  front-end bookings list); **Attendees** (each row's name, email, organisation, job title,
  the user linked via `get_edit_user_link()`); **Activity** (the parent event's log filtered
  to entries whose context carries this booking's ID).
- Admin list columns: Booking # (title), Event (linked), Owner, Attendees (count), Status
  label, Date.
- The events list gains a "Booked" column, `sold / available`, shown red when sold exceeds
  available (the committee lowering the ticket number below sold is surfaced, and
  `law_event_tickets_remaining()` clamps at 0).

## 11. Activity logging

> **Superseded in part (8 September 2026).** The action names changed with the
> model, and the waitlist adds its own under `source => 'waitlist'`.

Everything logs to the parent **event's** stream via `law_event_log()`, with context
`source => 'bookings'` and `booking => {id}` (no separate booking log: one stream per event
keeps the host and committee audit in one place, and the admin booking screen filters that
stream by the booking context key). Logged actions: `booking_created`,
`booking_attendee_added`, `booking_attendee_linked`, `booking_attendee_account_created`,
`booking_attendee_removed` (with by-whom context), `booking_attendee_rejected`,
`booking_cancelled`, `booking_role_granted` (the auto-granted attendee role),
`booking_capacity_warning`, `booking_attendee_error` (account-creation failure),
`booking_event_cancel_sweep`, `booking_attendee_account_rolled_back` (a registration on
someone's behalf refused after its account was created), and `booking_guard_refused` for
capacity, duplicate and clash refusals (refusals are logged too, per the module's
WooCommerce-notes standard). A booking created on someone's behalf logs "created by X on
behalf of Y" with `on_behalf` and `press` in the context. Recount
results are logged when the number changes, and every email send is logged by
`law_events_send()` as usual.

## 12. Build phases

1. **Data model and engine** (~2 days): request.php extraction, CPT, statuses protection,
   schemas and sanitiser, bookings.php (counter helper, guards, create/add/remove/cancel/
   reject, ensure-user, recount plus backstop hooks, queries, GET_LOCK), `_load.php` wiring.
   PHPUnit alongside (section 13).
2. **Emails and .ics** (~1 day): registry entries, placeholders, the attachments extension,
   ics.php, the welcome email hook, the capacity latch.
3. **Booking surface** (~2 days): handlers, booking modal and attendee repeater partials,
   booking-form.js, the five-state control and enqueues, archive default-action removal, the
   registration role-preselect and redirect changes, the `?law_book=1` no-JS path.
4. **Account** (~1.5 days): Your bookings section, the manage view, notices, headings and
   audience split, and the page 292 (My events) Members-restriction fix scripted via
   migration step 10 / `law_setup_account_pages()`.
5. **Host and committee** (~1 day): Bookings (n) actions, the bookings list, reject
   modals, the CSV/Excel/PDF export endpoint and buttons.
6. **Admin, hardening, docs** (~1 day): booking-screen.php, the events Booked column, the
   event-cancel sweep, EVENTS_FUNC.md updates.
7. **Security review gate**: the security-specialist agent over the whole bookings surface
   (handlers, guards, exports, registration changes, emails); fix its findings.
8. **Full E2E UX pass**: after security sign-off, the test-specialist agent (Playwright)
   walks every flow: both modal states and the "You're booked" state, register and login
   round-trips, booking with colleagues, capacity/duplicate/clash refusals, the Waitlist
   swap, Your bookings and the manage view as owner, additional attendee and stranger, the
   host and committee bookings list, reject flows, all three export downloads, and the
   emails (with .ics) in Mailpit.

## 13. Tests and verification

> **Superseded (8 September 2026): see WAITLIST.md §A8, §B6.** The suites were
> rewritten for the per-attendee model and `tests/WaitlistTest.php` added.

`tests/BookingsTest.php` on `LAW_Test_Case`, house style: call the engine functions
directly, assert status, meta and log text. A `make_booking()` helper is added, and
**engine-created users and bookings must be pushed into `$this->users` / `$this->posts`**
(the teardown only deletes tracked entities; the engine creates accounts and auto-cancelled
bookings the fixtures didn't).

Coverage: create (numbering #1 then #2, owner row snapshot, account create versus link, the
attendee role grant, flat index rows, recount); guards (duplicate including cancelled
bookings ignored and the same email twice in one submission; capacity exact-fill and
plus-one; not open when tickets unset; refused after start; clash overlap, adjacent times
allowed, cancelled ignored, missing end conservative); mutations (add at capacity refused,
last-row auto-cancel, reject by a non-manager refused, self-removal keeps the booking
manageable, cancel frees places, the event-cancel sweep); the status-guard and untrash
extensions and the wp-admin recount backstops; emails (slugs resolve, the capacity latch
fires once and re-arms, attachments reach `wp_mail`, the welcome email, the right removal
template per context); ics (correct UTC on a BST date and a GMT date, folding, escaping);
registration role whitelist; (8 September 2026) registering on someone's behalf (an existing
account owns the booking, the press flag survives the sanitiser, the typed organisation fills
a blank profile, a duplicate is refused by name; a new account is created with the role and
meta, and deleted again when the booking is refused), the registered-on-behalf emails (one
email per person, the invited variant with a minted set-password link), and
`tests/BookingsDashboardTest.php` for §7.6. The handler-level gates (nonces, the reject manager gate,
`redirect_to` validation via `wp_validate_redirect`) live in admin-post handlers the house
test style does not exercise over HTTP; they were verified by the phase 7 security review
and the phase 8 Playwright pass. Note: the suite needs a raised PHP memory limit to boot
(`php -d memory_limit=512M vendor/bin/phpunit`) — an unrelated GravityView allocation during
wp-load OOMs at the 128M default, which can be mistaken for a red suite.

Manual QA (behind the pre-launch gate, with Mailpit): both modal states; the
register-from-modal round trip landing back on the event; no-JS fallbacks (including
`?law_book=1`); the five control states; the manage view as owner, additional attendee and
stranger; the bookings list and rejects as host, co-owner and committee; the three exports
opening in Excel/a PDF reader; the Emails screen listing and overriding the new templates;
the .ics rendering in a real calendar client; the wp-admin booking screen links; template
screenshots with a rendering and colour-contrast check.

## 14. Risks, edge cases and recorded follow-ups

Edge cases handled in the design: the oversell race (GET_LOCK); a mistyped attendee email
mails a stranger a set-password link (no verification step in v1; the booker's confirmation
lists every attendee email verbatim so typos surface, reset keys expire in 24 hours, and the
owner can remove and re-add the row); an existing non-attendee account added as a guest
gains the attendee role; a host booking their own event is allowed and consumes a place; the
committee lowering available below sold (clamped, surfaced red in admin); a missing event
end (conservative clash, 2-hour .ics default); `law-modal.js` behaviour with a multi-field
dialog (verify in phase 3); an admin trashing a published event skips the cancel sweep and
leaves active bookings attached to a trashed event (accepted; the committee cancel is the
proper route).

Security review outcomes (phase 7, 7 September 2026; the security-specialist
found no critical or high issues):

- Fixed: every shared-state guard (duplicate, own-booking, clash, capacity,
  the additional-attendee cap) now runs inside the event GET_LOCK on create
  and add, and remove/reject/cancel take the same lock around their
  read-modify-write, closing the double-submit races the review flagged as
  must-fix. The `attendee_rows` schema sanitiser caps rows at owner + 3 and
  allows one owner row (defence in depth); the .ics temp file is deleted in a
  `finally`; and a locked registration role is enforced server-side, not just
  hidden (a tampered `roles[]` cannot add event_host through the attendee-only
  entry point).
- Accepted (info-level, documented): wp-admin's shared capability set lets
  administrator/editor/committee trash or rename bookings outside the engine
  (no attendee email; the recount backstops keep the counter honest) — the
  same accepted posture as trashing events; and email SUBJECTS are not
  escaped, so new registry entries must never interpolate attendee-supplied
  placeholders into a subject (none do today).

Three additional independent reviews (7 September 2026, after the completion passes): a cold
plan-conformance audit ("the code faithfully implements this plan"; its findings were doc
drift, now corrected), an adversarial correctness hunt and a performance/maintainability
review. Fixes applied from them:

- **Account creation and attendee emails moved outside the event lock** on create and add:
  seats are written (with existing accounts resolved) under the lock, the slow work
  (password hashing, synchronous sends) runs after, and new account IDs are backfilled — so
  parallel bookers on a hot event are never queued behind another booking's emails.
- **The last-row auto-cancel re-checks the rows under its own lock** and aborts if a
  concurrent add seated someone in the gap.
- **Events trashed or hard-deleted outside the workflow now sweep-cancel their bookings**
  (`wp_trash_post` / `before_delete_post` hooks) instead of silently orphaning attendees;
  and the event-cancel sweep is **time-boxed and resumes via a cron event** so a big event's
  cancellation cannot half-complete on a PHP timeout.
- **`law_events_bump_counter()` is now atomic** (one `ON DUPLICATE KEY UPDATE` with the
  `LAST_INSERT_ID()` trick), so booking numbers (and LAW references) can no longer duplicate
  under concurrency; the recount and export fetch all bookings (-1), never the 500 screen cap.
- **Booking rate limits gained a separate, larger per-IP budget** (create 10/user but
  100/IP per 10 minutes; edits 15/150) so a law firm's shared NAT cannot lock colleagues out
  on launch day — the registration handler's precedent.
- **Self-removal matches on the row's linked user ID** (snapshot email as fallback), so an
  attendee who changed their account email can still remove themselves.
- **An end at or before the start is treated as missing** by both the clash guard (23:59
  fallback) and the .ics generator (+2 hours), so a fat-fingered end date can neither hide
  double-bookings nor emit an RFC-invalid invite.
- **A send with no resolvable recipients now logs "Email NOT sent (no recipients)"** instead
  of dropping silently; the manage view's notice map gained `booking-cancelled`; the i18n
  convention is settled (partials translated with the `law` domain, engine `WP_Error`
  strings bare like the rest of the module, JS fallbacks bare); `cache_users()` primes the
  bookings list and export; dead grouped-list CSS removed; the modal-opener preventDefault
  is scoped to the booking modal. 87 PHPUnit tests green after the round.

Four review questions put to Denis after the independent-review round, all settled
(7 September 2026) as accepted with no code change:

- **No rollback to the legacy source once cutover is done.** The half-state a `gf` flip
  would leave bookings in (hidden UI, handlers still live) is moot: the source will not be
  flipped back after the custom module goes live, so no freeze is built.
- **Host-user deletion behaviour stays as WordPress default** (`delete_with_user` on events
  unchanged); the trash/delete sweep already cancels and emails any bookings caught by it.
- **A colleague whose account creation fails stays seated with no email** — accepted; the
  booker's confirmation lists every attendee email, so the owner can spot and re-add.
- **Untrashing a trashed active booking restores it active** without a capacity/duplicate
  re-check (admin-only sequence) — accepted.

Recorded follow-ups (defaults chosen, no build now):

- **Event detail changes after booking** (date, venue): attendees are not notified and their
  .ics goes stale. Recommended fast follow: a `user_booking_event_updated` email.
- **Host emails go to the post author only** (consistent with every existing host email);
  co-owners are not copied. Revisit if hosts ask.
- **A rejected attendee can be re-added or book again** (no blocklist). Accepted.
- **Full-page caching** on staging/production can serve a stale "N places left" to
  logged-out visitors; booking itself is server-guarded. An ops note, not code.
- **Auto-created attendee accounts get no HubSpot contact tags** (same as co-owner
  accounts); revisit with the deferred HubSpot sync.
- ~~**The waitlist itself** (signup capture, ordering, promotion) is deferred wholesale; the
  disabled button and the server hard-stop are the v1 surface.~~ **Built on
  8 September 2026; see WAITLIST.md Part B.**

Deferred by Denis on 8 September 2026, after the spec-conformance review of the committee
tooling: **receptions and externally booked events** (spec §2 names GAR, LCIA and CIARB as
external redirects and the Friday reception as invitation-only; today every Confirmed event
shows the on-site control, and `_law_registration_state` stays reserved for this), the
**flagship approval flow** (spec §5, a later build), and an **event-changed email** to
attendees when a host edits the date or venue after bookings exist ("we don't care").
