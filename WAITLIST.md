# WAITLIST.md: per-attendee bookings rebuild, then the events waitlist

> **Status: built on 8 September 2026.** Part A (one booking per attendee),
> Part B (the waitlist) and Part C (the rename to EVENTS_FUNC.md and the doc
> updates) all landed; 138 PHPUnit tests are green, up from 118, including the
> new `tests/WaitlistTest.php`. Changes made during the build, over and above
> the plan below: booking positions are renumbered after every promotion,
> cancellation and move (`law_waitlist_renumber()`), because the host's
> displayed position and the stored one must agree for the reorder controls'
> stale-click guard to mean anything; a colleague linked to an existing account
> is logged as such on the create path, which the split of
> `law_booking_ensure_attendee_user()` had dropped; and a registration made on
> someone's behalf takes the name the manager typed when the account has no
> real name of its own. The issues tables in §A9 and §B7 record what was
> decided by default and what still needs Denis's word.
>
> **Execution brief.** This document is self-contained: an agent starting cold should be
> able to build everything below from it plus the code. It was produced on 8 September 2026
> from Denis's decisions, two exploration passes over the working tree, three specialist
> design passes (engine, front end, adversarial review) and a final consumer re-scan.
> Work in the order given (Part A, then Part B, then Part C). Read EVENTS_BOOKINGS.md and
> EVENTS_FUNC.md before touching code;
> they describe the module as built. Keep them current as you go (house rule).

## 0. Context and ground rules

### 0.1 The project

London Arbitration Week (LAW) events platform, a WordPress theme (`larbwk`) for a UK legal
client. Phase 4.1 rebuilt the host side (submission, moderation, invoicing) as a custom,
CPT-backed module in `functions/events/`, replacing Gravity Forms / Gravity Flow /
GravityView. The attendee bookings slice (EVENTS_BOOKINGS.md) landed on 7 September 2026,
with a committee round on 8 September (register on behalf, press passes, Country column,
cross-event Bookings dashboard, the control's button renamed "Register"). Bookings are
**free**: no cart, no Stripe on the attendee side. EVENTS_4.2_SPECS.md is the client spec;
its waitlist requirements are §2 (capacity), §4.3 (host reordering, attendee status), §4.4
(waitlist activation), §5.4 (behaviour), §8 (self-withdrawal), §9 (notifications). Ignore
every Stripe, saved-card and charge-on-promotion passage: bookings are free.

### 0.2 Repository state (verified 8 September 2026, 16:00)

- Branch `events-4.1-rebuild-custom`; HEAD `6311d63`. The 8 September committee round is
  **uncommitted** (38 modified files plus new `functions/events/bookings-dashboard.php`,
  `parts/events/bookings-dashboard-list.php`, `templates/account-bookings-dashboard.php`,
  `tests/BookingsDashboardTest.php`, `tests/SpeakerRolesTest.php`). **Commit it first** so
  the rebuild diff is clean.
- Local DB: 1 active booking (test data), source `cpt`, 52 confirmed events (5 without a
  ticket number, 0 full, 3 within 5 places). Production has no bookings. There is no legacy
  Gravity Forms booking data. **No data migration is needed.**
- `wp` CLI is not on the PATH. Use `php -r 'require "/srv/http/law/wp-load.php"; …'` for
  one-off reads, wp-admin for the purge in A8.
- Tests: `php -d memory_limit=512M vendor/bin/phpunit` (128M OOMs during wp-load).
  Bookings suites today: BookingsTest 24, BookingEmailsTest 10, BookingsDashboardTest 6.
- Cron: `wp-config.php` sets no `DISABLE_WP_CRON`; WordPress pseudo-cron only. The module's
  one scheduled hook is the cancel sweep's `law_bookings_resume_cancel_sweep`.

### 0.3 House rules and standing preferences (from Denis; do not relitigate)

- UK English, sentence case for headings and labels, no em-dashes. Wording is **"places"**,
  never "tickets"; the booking button is **"Register"**.
- Every new action follows the **AJAX modal pattern**: `admin_post_<action>` +
  `admin_post_nopriv_<action> => law_events_nopriv_json`; `law_events_guard_post()` (nonce,
  honeypot `law_website_url` with a pretend-success payload, rate limit) then
  `law_events_respond()` (JSON `{title,message,redirect}` / `{message,row,field,status}`
  versus redirect-with-`law_notice`); confirm dialogs via `parts/layout/modal.php` rendered
  **inside** the form they confirm; destructive confirms `button alert` with the close button
  relabelled so "Cancel" never reads as the destructive action; no native `required` inside
  hidden dialogs (aria-required + server validation). Plain POST must keep working without JS.
- **Every mutation and refusal is logged** to the parent event's activity log
  (`law_event_log()`, WooCommerce-order-notes style) with `source` and `booking => <id>`;
  the admin booking screen's Activity box filters on that key.
- **Every email is a registry entry** in `law_events_email_registry()` (editable on the
  Emails screen, every send logged by `law_events_send()`); `'to' => 'dynamic'` means the
  caller passes `$extra['to']`; per-send `$extra['placeholders']` with bare keys; new tags
  must be pre-declared in the booking-tags block (notifications.php ~:417-427). Never put
  attendee-supplied text in a subject (subjects are not escaped).
- **Concurrency**: every shared-state read/write runs inside `law_booking_lock( $event_id )`
  (MySQL `GET_LOCK('law_booking_event_<id>', 3)`, re-entrant per session); slow work
  (password hashing, emails) runs after unlock.
- Booking **status writes** must be wrapped in `$GLOBALS['law_booking_transitioning']` or
  the `wp_insert_post_data` guard in workflow.php silently reverts them. Save and restore
  the previous value (cancel currently hard-resets to `false`; fix that).
- **List data is a flat table** (Denis prefers proper tables over card/group blocks).
  **Exports always offer CSV, Excel and PDF**, reusing `functions/events/export.php`.
- Admin tools live under the existing LAW menu; the events CPT menu holds Bookings.
  **Do not touch `wp-content/mu-plugins/`.**
- Browser/E2E QA goes to the **test-specialist** agent (Playwright), never browser-tester;
  add `.playwright/` and `.playwright-cli/` to `.gitignore` before any run; screenshot every
  touched view with a rendering and colour-contrast check. Emails are checked in **Mailpit**.
- **Gates**: security-specialist review of every new handler and status flip, then the
  test-specialist pass, then three independent completion checks of this document against
  the code before declaring done.
- Major plans get specialist-persona reviews (UX, design reuse, backend); this document
  already went through them.

### 0.4 Decisions settled by Denis on 8 September 2026 (do not reopen)

1. **One booking per attendee.** A colleague added by a booker gets their own `law_booking`
   post with a unique booking number. The booker sees and manages the bookings they made
   from their account. On the host, committee and admin lists this is a **flat table with
   no grouping**: an additional attendee's row carries a small **"Invited by {main
   attendee}"** tag and nothing more.
2. **Waitlist promotion is automatic** the moment a place opens. No offer/accept step, no
   expiry. **No strict FIFO by party** (dropped because it would have forced refusing
   Register while anyone waits): every entry is one place, promoted in position order.
3. **Colleague accounts are created at join, like a booking.**
4. **Hosts and committee can reorder** (move to top / up / down) **and "Promote now"** any
   entry even when the event is full (deliberate over-booking; bypasses the capacity guard
   only, never duplicate or clash guards).
5. **No queue position is shown to attendees**; hosts see positions.
6. Standing rules kept: one active-or-waitlisted booking per person per event; booking and
   waitlist close at event start; no ticket number means "Bookings open soon" (no waitlist
   either); hosts may reject (a settled divergence from spec §4.3).

---

# Part A: one booking per attendee

## A1. Post shape

| Field | Value | Why |
|---|---|---|
| `post_parent` | event ID | unchanged |
| `post_author` | the **attendee's** user ID | "My bookings" is a plain author query; self-service falls out of core |
| `post_status` | `publish` (active), `law-cancelled`, and `law-waitlisted` from Part B | |
| `post_title` / `_law_booking_number` | "Booking #N", unique per post | unchanged |
| `_law_booked_by` (int, schema) | **always set**: the booker's user ID for a colleague booking; the attendee's own ID when self-booked **or registered on their behalf by a host/committee** (the manager's identity is in the log line and the registered email, not on the post, so a manager's own "My bookings" never lists strangers) | one uniform party query, no null branch |
| `_law_attendee_name` / `_law_attendee_email` / `_law_attendee_organisation` / `_law_attendee_job_title` (text, email, text, text) | snapshot as entered | queryable email for the duplicate guard and the dashboard keyword; the `attendee_rows` sanitiser has no job left |
| `_law_is_press` (flag) | committee-issued press pass | replaces `is_press` on the row (written only when true; the flag sanitiser stores "0") |

- "Self-booked" is derived: `author === booked_by`. "Invited by" tag = display name of
  `_law_booked_by` when it differs from the author (`law_booking_invited_by_label()`; "a
  deleted account" when the user is gone).
- **No group ID.** A booker's party on event E = active bookings on E where `post_author =
  B` or `_law_booked_by = B`. The only "same submission" need is the pair of summary emails,
  which use the ID array `law_booking_create()` returns; nothing is persisted.
- Dietary, accessibility and country stay live from `law_profile_values( post_author )`.
- **Deleted outright**: `_law_attendee_rows`, the flat `_law_booking_attendee` index,
  `law_booking_set_attendee_rows()` (bookings.php:191-206), the `attendee_rows` sanitiser
  (meta.php:184-220) and its array-fallback entry (meta.php:350).

### Every consumer of the rows model (verified by grep on 8 September 2026)

| File | Lines | Change |
|---|---|---|
| functions/events/bookings.php | 76 (recount), 130-189 (user queries), 191-206 (rows writer), 245-262 (dup guard), 525-737 (create), 762-846 (register by manager), 854-936 (add), 950-1035 (remove), 1048-1114 (cancel), 1194-1219 (placeholders), 1264-1565 (handlers), 1601-1662 (export) | A2, A3, A4 |
| functions/events/meta.php | 89-101, 184-220, 350 | A7 |
| functions/account-bookings.php | 25-33, 71-99 (items), 151-165 (control) | A5 |
| parts/events/booking-manage.php | whole file | A5 |
| parts/events/booking-list.php | 37 (prime), 98-140 (rows, "by %s (not attending)", "(booker)", press), 52-56 (notices), 244-270 (register form incl. press checkbox at 266) | A5 |
| functions/events/bookings-dashboard.php | 134-147 (prime), 161-221 (rows, `is_owner`, `owner_seated`, `is_press`), 238-290 (export) | A5 |
| parts/events/bookings-dashboard-list.php | 29-35 (summary), 66-82 (sub-line, booker flag, press) | A5 |
| templates/account-events.php | 23-25, 48-62 (routing), 107-123 (notices), 155-184 (cards) | A5 |
| functions/events/admin/booking-screen.php | 26-73 (boxes), 108-139 (columns) | A6 |
| functions/events/notifications.php | 227-322 (bookings registry), 417-427 (tags) | A4 |
| tests/BookingsTest.php, BookingEmailsTest.php, BookingsDashboardTest.php, class-law-test-case.php | 40 tests + helpers | A8 |
| EVENTS_BOOKINGS.md, EVENTS_FUNC.md | rows-model passages | Part C |

## A2. Engine (`functions/events/bookings.php`)

**Kept unchanged**: `law_booking_max_additional()`, `law_event_tickets_remaining()`,
`law_event_attendee_total()`, `law_bookings_for_event()`, `law_booking_guard_open()`,
`law_booking_guard_capacity()`, `law_booking_guard_clash()` / `law_booking_clash_end()`,
`law_booking_clean_additional_rows()` (drop the `is_owner` key), `law_booking_lock()` /
`unlock()`, `law_bookings_cancel_all_for_event()` and its cron resume, the trash/delete
sweep, `law_booking_send_with_ics()`, `law_booking_maybe_capacity_warning()`,
`law_booking_log_refusal()`, `law_booking_manage_url()` / `law_booking_list_url()`, the
export handler, both wp-admin backstop hooks.

**Recount**: one `COUNT(*)` of `publish` bookings on the event (replace the rows loop at
:72-77); latch re-arm and logging unchanged.

**Queries**: `law_user_booking_ids()` author-only (drop the `$wpdb` join);
`law_user_booked_event_ids()` = parents of active author bookings (`fields =>
'id=>parent'`); new `law_booking_party( $event_id, $user_id, $status = 'publish' )` (author
= U ∪ booked_by = U, de-duped by ID), `law_booking_colleague_count( $event_id, $booker_id )`
(active bookings with booked_by = B and author ≠ B), `law_user_bookings_made_ids()`,
`law_booking_is_self_booked()`, `law_booking_invited_by_label()`.

**Duplicate guard** `law_booking_guard_duplicates( $event_id, array $people )` (people =
`{user_id, email, name}`): within the submission (same user or lowercased email twice → "X
is listed more than once."), then against every active booking on the event by author ID,
the author's current `user_email` (`cache_users()` primed) and `_law_attendee_email`. Self →
"You already have a booking for this event. You can manage it from My bookings."; other →
"%s already has a place at this event." Replaces both the email scan and the
one-booking-per-owner loop (:580-592). Part B extends it to `law-waitlisted`.

**Accounts**: split `law_booking_ensure_attendee_user()` (:432-504) into
`law_booking_resolve_attendee_user( $row, $event_id, $actor )` → `{user_id, created}` |
`WP_Error` (link by email or create via `law_events_create_host_user()` with role
`attendee`; logs linked / account_created / error; no emails, no role grant) and
`law_booking_notify_attendee( $booking_id, $booker_id, $created )` (sends
`user_attendee_invited` or `user_attendee_added` with that booking's own placeholders).

**Single meta write path** `law_booking_write_attendee( $booking_id, array $person,
$booked_by, $press = false )`: the four snapshot keys, `_law_booked_by`, `_law_is_press`
only when true (else `delete_post_meta`).

**Create** `law_booking_create( $event_id, $booker_id, array $additional_rows, array $args
= array() )` → `int[]` (index 0 the booker's own) | `WP_Error`:

1. Open guard; booker exists; clean rows; booker person from `law_profile_values()` with
   `$args['owner_row']` fallbacks (as :548-557).
2. Resolve existing colleague accounts by email (cheap).
3. **Fast-fail, unlocked**: duplicates and capacity for N, so refused submissions never
   create accounts (the register-by-manager precedent at :776-789).
4. **Create missing colleague accounts before the lock** (`law_booking_resolve_attendee_user()`),
   collecting created IDs. On `WP_Error`: delete the accounts created in this submission,
   return `law_booking_account_failed` with `row`/`field => email` data.
5. Lock (`law_booking_busy` after rolling back created accounts). `$refuse` closure =
   unlock, delete created accounts (log `booking_attendee_account_rolled_back`), log refusal.
6. Recount; duplicates by user ID and email; **colleague cap**
   (`law_booking_colleague_count() + count( $additional ) > 3` → `law_booking_too_many`);
   clash for every person; capacity for N.
7. Numbers: `law_events_bump_counter( 'law_bookings_counter', $by = N )` (extend
   meta.php:373-389 with `LAST_INSERT_ID(option_value + %d)`), party numbers `last − N + 1 …
   last`, consecutive even when another event books concurrently.
8. Insert N posts, booker first: `wp_insert_post( publish, post_parent, post_author =
   person )`, `_law_booking_number`, `law_booking_write_attendee()`. On any `WP_Error`:
   `wp_delete_post( $id, true )` for every sibling created in this call, roll back created
   accounts, log `booking_create_rolled_back`, unlock, return the error (nothing has been
   emailed yet, so the rollback is invisible). For `$args['on_behalf']` the single person
   gets `booked_by = themselves` and `press` from `$args`.
9. Recount; unlock.
10. After unlock: grant `attendee` role to the booker and to linked colleagues lacking it
    (log `booking_role_granted`); one `booking_created` log line **per booking** (`booking =>
    id`, `submission => $ids[0]`, `sold`); emails (A4): booker confirmation with the party,
    per colleague `law_booking_notify_attendee()`, host and committee **once** per
    submission, then `law_booking_maybe_capacity_warning()`.

**Register by manager** `law_booking_register_by_manager()` (:762-846): unchanged in shape,
passes `on_behalf` and `press` through `$args`, returns `$ids[0]` (keeps its `int` contract).

**Add** `law_booking_add_attendee( $event_id, $booker_id, array $raw_row, $actor_id = 0 )`
→ int: open → clean → resolve account before the lock (rollback on refusal) → lock →
recount → cap (`colleague_count >= 3` → `law_booking_too_many`) → duplicates → clash →
capacity 1 → one insert (booked_by = booker) → recount → unlock → role grant, log
`booking_attendee_added`, `law_booking_notify_attendee()`, `host_booking_received` and
`committee_booking_received` for the single booking (issue A9.12; default yes), capacity
warning.

**Cap semantics (decided)**: at most **3 active colleague bookings per booker per event**
(author ≠ booked_by), independent of whether the booker holds their own booking. A party is
never more than 4; a self-cancelled booker still holds up to 3 colleagues. Manager
registrations are uncapped.

**The booker needs no booking of their own to manage colleagues**: rights ride
`_law_booked_by`. The form always books the booker (row 0). A later self-cancel leaves
colleagues intact; Register then creates a new self-booking that joins the party (today a
seatless owner is refused a second booking; this is a deliberate behaviour change).

**Cancel** `law_booking_cancel( $booking_id, $actor_id, $context = 'self', array $args =
array() )` replaces `law_booking_remove_attendee()` (deleted) and the old cancel. Idempotent.
Under the lock: status → `law-cancelled` via the new `law_booking_set_status()` (save/set/
restore the transitioning flag), recount; after unlock log and email **that booking's
attendee** (author's current email, snapshot as fallback) by context: `self` →
`user_booking_cancelled_self`, `booker` → `user_booking_cancelled_by_booker`, `host_reject`
→ `user_booking_rejected` (`{removal_reason}` from `$args['reason']`), `event_cancelled` →
`user_booking_event_cancelled`. `last_attendee_removed` disappears.
`law_bookings_cancel_party( $event_id, $booker_id, $actor_id )` → int: every active booking
in the party (`self` for the actor's own, `booker` for the rest) plus one summary log line.

**Sweep**: unchanged in shape (more posts per event; the 15 s time-box and cron resume
already exist).

## A3. Handlers (bookings.php :1264-1565)

| Action = nonce | Posts | Gate | Change |
|---|---|---|---|
| `law_booking_create` | event_id, law_attendees[] | signed in | result `int[]`; redirect unchanged (permalink + `booking-created`) |
| `law_booking_add_attendee` | **event_id**, one row | current user has an active party on the event (engine re-checks) | was booking_id; redirect to the manage view (booker's own booking ID, else first colleague) with `attendee-added` |
| `law_booking_cancel` | booking_id | author = me (context `self`) or booked_by = me (context `booker`) | redirect to the manage view while the party still has active bookings, else My bookings; notice `booking-cancelled` |
| `law_booking_cancel_party` (new) | event_id | party non-empty | notice `party-cancelled` → My bookings |
| `law_booking_reject_attendee` | booking_id (drop `attendee_email`) | `law_user_can_manage_event( post_parent )` | calls cancel with `host_reject`; action name kept so the list's nonce string is unchanged; title "Attendee rejected", message "Their booking has been cancelled and they have been emailed."; notice `booking-rejected` |
| `law_booking_remove_attendee` | | | **removed** (handler, both `add_action` lines, the manage view's forms) |
| `law_booking_register_attendee`, `law_booking_export` | | | unchanged |

Rate limits, honeypot payloads and the IDOR rules (load booking, check post type, derive the
event from `post_parent`) are unchanged.

## A4. Emails (`functions/events/notifications.php`)

Nothing is live, so renames are free. `law_booking_email_placeholders( $booking_id, array
$party_ids = array() )`: per-booking `booking_number`, `attendee_name` (snapshot),
`invited_by` (display name of `_law_booked_by`, '' when self-booked); `attendee_list` over
`$party_ids ?: [ $booking_id ]` as "Name (Booking #N)" lines; `booking_numbers` as "#12,
#13, #14". Pre-declare `{invited_by}` and `{booking_numbers}` at :417-427.

| Slug | Recipient | Change |
|---|---|---|
| `user_booking_confirmed` | booker | `{booking_number}` their own; "Who is coming" lists each colleague with their number; add "Each colleague has their own booking number and has been emailed their own confirmation." |
| `host_booking_received`, `committee_booking_received` | once per submission | "New booking(s) ({booking_numbers}) … Attendees: {attendee_list}" |
| `user_attendee_invited`, `user_attendee_added` | colleague (new / existing account) | "{invited_by} has booked a place for you at {event_title} (Booking #{booking_number})."; .ics kept |
| `user_booking_registered`, `_invited` | registered person | unchanged |
| `user_attendee_rejected` → **`user_booking_rejected`** | attendee | "The event host has cancelled your booking (Booking #{booking_number}) …" + `{removal_reason}` + host contact line |
| `user_attendee_removed` → **`user_booking_cancelled_by_booker`** | attendee | "{invited_by}, who booked your place, has cancelled your booking (Booking #{booking_number}) …" |
| `user_attendee_removed_self` → **`user_booking_cancelled_self`** | attendee | "This confirms you have cancelled your booking (Booking #{booking_number}) …" |
| `user_booking_cancelled_attendee` | | **dropped** (merged into by-booker) |
| `user_booking_event_cancelled` | attendee | unchanged |

## A5. Front end

- **Manage view** `parts/events/booking-manage.php`, URL stays `?law_booking={id}` (every
  existing link is per booking; a colleague's view is per booking; the party is one query
  from the ID). Resolve booking, event = `post_parent`, user; access: author = me or
  booked_by = me; `$party = law_booking_party( $event_id, $me )`. Booker view: heading
  "Your bookings for {event}", event summary, one row per active booking (name, "Booking
  #N", "(you)" on the own row, snapshot organisation and job title; **never** colleagues'
  dietary or accessibility, the current behaviour), per row "Cancel my booking" / "Cancel
  this booking" (form `law_booking_cancel`, booking_id) behind a confirm (own with
  colleagues: "Your place is freed for someone else. Your colleagues keep theirs and you can
  still manage them here."; colleague: "This cancels {name}'s booking (#N) and frees their
  place. They are emailed to let them know."; close "Keep the booking"), "Add a colleague"
  (form `law_booking_add_attendee`, event_id, **wrapped in `[data-law-booking-rows]`** so
  booking-form.js's `markField()` can mark errors; today's row lacks the wrapper and error
  marking silently no-ops) while the colleague count is under 3, the event is open and
  places remain, "Cancel all bookings" (form `law_booking_cancel_party`, event_id) when the
  party has two or more (copy "This cancels every booking you made for this event ({names
  with numbers}). Each person is emailed."). Colleague view: heading "Booking #N", one row,
  line "Invited by {name}", "Cancel my booking" only. A cancelled addressed booking shows
  a badged row above the active party so the `booking-cancelled` redirect lands sensibly.
- **My bookings cards** (`law_account_bookings()` in account-bookings.php:71-99;
  templates/account-events.php:155-184): one item per event `{ event, own, colleagues,
  invited_by, manage_id }` from active author bookings ∪ active bookings made (booked_by =
  me, author ≠ me), grouped by `post_parent`. Meta lines: own → "Booking #N"; own invited →
  "Invited by {name}"; colleagues → "+ N colleagues invited by you". Actions "View event"
  and "Manage" (→ `law_booking_manage_url( own ?: colleagues[0] )`). A booker who cancelled
  themselves still gets a card.
- **Booking control** (account-bookings.php:151-165): own active booking → "You're booked
  on this event." + Manage booking; else colleagues invited by me → "You've booked places
  for N colleagues." + Manage bookings, and Register still renders; else the existing
  states. Modal repeater cap (booking-modal.php:35) becomes `min( 3 −
  law_booking_colleague_count(), remaining − 1 )`.
- **Bookings list** (`parts/events/booking-list.php`): **flat table, one row per booking,
  no grouping**. `cache_users( authors ∪ booked_by )` once. Columns Booking (#N), Attendee
  (snapshot name; an additional attendee's row carries one small tag "Invited by {name}" in
  the `law-cal-card__badge` style, like the Press badge; self-booked rows carry nothing),
  Email, Organisation, Job title, Country, Accessibility, Dietary, actions. Drop the booking
  sub-line, "(booker)", "by %s (not attending)" and the rule between bookings. Reject posts
  booking_id only; modal copy loses the last-attendee sentence. Summary "N attendees. N of
  M places taken." Register-an-attendee block unchanged (its press checkbox writes
  `_law_is_press`).
- **Exports** (per-event `law_booking_export_rows()` :1601-1662 and the dashboard's): one
  row per booking; new **Invited by** column after Press (blank when self-booked).
- **Bookings dashboard** (`functions/events/bookings-dashboard.php`,
  `parts/events/bookings-dashboard-list.php`): prime authors ∪ booked_by; one row per
  booking with the four snapshot keys, `is_press` from `_law_is_press`, `invited_by` string
  replacing `owner_name` / `owner_seated` / `is_owner`; the press filter tests the meta;
  keyword haystack adds the invited-by name; partial shows the "Invited by" tag instead of
  the "by %s" sub-line and "(booker)" flag; summary "N bookings on N events"; export gains
  "Invited by" after "Booking ID" (test column indexes shift by one). Template unchanged.
- **Success dialog** (`booking-success-modal.php`): "You're booked onto {event}. A
  confirmation with your booking number and a calendar invitation is on its way. Each
  colleague you added has their own booking and booking number, and is emailed an invitation
  to set up their account and add any dietary or accessibility requirements."
- **Notices**: introduce `law_booking_notice_text( $key )` / `law_booking_notice_render()`
  in account-bookings.php and make the four drifted maps (control :137-141,
  booking-manage :65-71, booking-list :51-56, templates/account-events.php :107-123 which
  keeps its `event-*` keys) read it, with one markup `<p class="law-form-notice
  is-success|is-error" role="alert">`. Keys: `booking-created`, `booking-cancelled` ("The
  booking has been cancelled and they have been emailed."), `party-cancelled`,
  `attendee-added` ("The colleague has been booked and emailed their confirmation."),
  `booking-rejected` ("The booking has been cancelled and the attendee emailed."),
  `attendee-registered`, `booking-failed`, `rate-limited`; drop `attendee-removed`. Align
  engine messages on "My bookings" (the menu label), not "Your bookings".
- **booking-form.js**: no change.

## A6. Admin screen (`functions/events/admin/booking-screen.php`)

Remove the Attendees box. Facts box: Booking number, Status, Created, Event (as today),
**Attendee** (user link, snapshot email, organisation, job title, Press), **Invited by**
(blank when self-booked, else user link or "a deleted account (ID n)"). No party or sibling
list. List columns Booking, Event, Attendee, Invited by, Status, Date (drop the count
column). Events "Booked" column unchanged.

## A7. Schema (`functions/events/meta.php`)

`law_booking_meta_schema()` → `_law_booking_number` int, `_law_booked_by` int,
`_law_attendee_name` text, `_law_attendee_email` email, `_law_attendee_organisation` text,
`_law_attendee_job_title` text, `_law_is_press` flag (Part B adds four more). Delete the
`attendee_rows` case and its entry in the array-fallback list; rewrite the docblock at
:89-95. `law_events_bump_counter( $option, $by = 1 )`.

## A8. Tests and local data

- `LAW_Test_Case::make_booking()` returns `int[]` | `WP_Error`, tracks every ID and every
  colleague user; add `make_booking_id()` (returns `$ids[0]`) and `make_colleague_booking()`;
  lift `assertWPError()` into the base class.
- **BookingsTest (24)**: rewrite create/numbering (two IDs, consecutive numbers, authors,
  booked_by, snapshot, role, sold 2), duplicates (add "colleague booked by two bookers",
  "colleague later books themselves"), add-attendee cap over the party, booker self-cancel
  keeps colleagues and party and **may re-book**, host reject via cancel, cancel-party
  frees places, capacity latch re-armed by cancelling two colleague bookings, export
  columns (Invited by), register-by-manager × 2 (`_law_is_press`, snapshot keys, booked_by
  = person); drop the last-row auto-cancel pair; keep the rest with `$ids[0]`. New:
  whole-party capacity refusal creates no posts and burns no numbers; partial-insert
  rollback (fail the second insert via `wp_insert_post_empty_content`); account failure
  refuses the whole submission and deletes created accounts; party query union.
- **BookingEmailsTest (10)**: per-colleague own "Booking #N" and invited-by name; the
  booker's confirmation lists both numbers; cancel templates per context; cancel-party
  emails everyone; exactly one host and one committee email for a party of 3.
- **BookingsDashboardTest (6)**: array fixtures, `invited_by === ''` for self-booked, export
  column indexes +1.
- **Local data**: purge the single local booking in wp-admin (Events → Bookings → Trash →
  Delete permanently; the `deleted_post` backstop recounts) **before** deploying the new
  readers. Leave `law_bookings_counter`.

## A9. Issues to solve (Denis asked for this list; defaults are what will be built unless he says otherwise)

| # | Issue | Default resolution | Needs Denis? |
|---|---|---|---|
| 1 | Atomicity of N inserts | All under the lock before any email; a mid-party error hard-deletes the posts and accounts created in the request and logs `booking_create_rolled_back` | No |
| 2 | Colleague accounts must exist before insert (author needs a user) | Create them before the lock, roll back on refusal; only emails stay after unlock | **Yes** (reverses the "accounts after unlock" decision; recommend accept) |
| 3 | A colleague's account creation fails | Refuse the whole submission with a row-keyed error (today: seat kept with no account) | **Yes** (reverses a settled decision; recommend accept) |
| 4 | Booking numbers across concurrent events | One atomic `+N` bump keeps a party consecutive | No |
| 5 | Cap semantics | 3 colleague bookings per booker per event, own booking not counted, party ≤ 4; manager registrations uncapped | Confirm |
| 6, 7 | Colleague booked by two bookers; colleague books themselves later | Duplicate guard refuses the second by user ID and email | No |
| 8 | Booker cancels own booking but keeps management, and may re-book themselves | Rights ride `_law_booked_by`; third control sub-state | **Yes** (confirm re-booking is wanted) |
| 9 | Booker's account deleted | Tag reads "a deleted account"; colleagues untouched; add a `deleted_user` hook cancelling that user's own active bookings with a no-email context so places free | **Yes** (extends the "WordPress default" decision) |
| 10 | Host rejects the booker's vs a colleague's booking | Identical: cancel that one booking, email that person | No |
| 11 | "Cancel all" fan-out | One email per attendee (≤ 4 synchronous sends) | No |
| 12 | Add-a-colleague does not email host/committee today | Send them (every new booking is a booking) | **Yes** (default yes) |
| 13 | Committee lowers places below sold | Unchanged: clamp at 0, red admin cell | No |
| 14 | Summary emails need a submission scope | The returned ID array; not persisted | No |
| 15 | Invited-by resolution cost | `cache_users()` once per render | No |
| 16 | Manage URL | Stays `?law_booking={id}`; the party is derived | No |
| 17 | Recount cost | One `COUNT(*)` | No |
| 18 | `law_user_booking_ids()` cap 200 now per booking | Keep | No |
| 19 | .ics attached N times | Each attendee gets their own email anyway | No |
| 20 | Privacy | Booker sees colleagues' snapshot facts only; a colleague sees their own row and "Invited by"; host list and dashboard keep live requirements | Confirm |
| 21 | Booker not told when a colleague self-cancels | Same as today; optional follow-up `user_booking_colleague_cancelled` | Nice-to-have |
| 22 | Manager registrations | `booked_by` = the person (no tag); the manager is in the log and the registered email | Confirm |
| 23 | Dirty working tree | Commit the 8 September round first | Confirm |
| 24 | Manage view error marking never worked (missing `[data-law-booking-rows]`) | Fixed in the rewrite | No |

---

# Part B: the waitlist on the per-attendee model

## B1. Status and meta

- `functions/events/statuses.php`: new booking-only `law_booking_statuses()` (publish →
  Active, law-waitlisted → Waitlisted, law-cancelled → Cancelled) and
  `law_booking_status_label( $status|WP_Post )`; register `law-waitlisted` inside
  `law_events_register_statuses()` (init 6) with the event statuses' args. **Not** in
  `law_event_statuses()` (it feeds committee.php, co-owners.php, bookings-dashboard.php,
  source.php, session-screen.php, emails-screen.php). WP_Query silently drops an
  unregistered status and returns every booking, hence the same hook.
- `law_booking_meta_schema()`: `_law_waitlist_position` (int, 1-based),
  `_law_waitlist_joined` (datetime), `_law_waitlist_promoted` (datetime),
  `_law_waitlist_blocked` (text: last skip reason `clash`|`duplicate`, the one-shot email
  latch). The int sanitiser stores 0 (does not delete), so clear positions with
  `delete_post_meta()`.
- `workflow.php:124` untrash whitelist → `publish, law-waitlisted, law-cancelled`; new
  `untrashed_post` handler (waitlist.php) moves a restored waitlisted booking to the back
  (position max+1 under the lock, log `waitlist_untrashed_to_back`). The
  `wp_insert_post_data` guard needs no change; fix its comment ("their only transition is
  cancel"). All status writes use `law_booking_set_status()`.
- Admin booking screen ternaries (:33, :136) → `law_booking_status_label()`; facts box
  shows position, joined and promoted stamps; the list status filter shows Waitlisted.

## B2. Engine: new `functions/events/waitlist.php` (require from `_load.php` after bookings.php, before bookings-dashboard.php)

- **Queries**: `law_waitlist_for_event( $event_id, $limit = -1 )` (status law-waitlisted,
  `meta_key _law_waitlist_position`, `orderby meta_value_num ASC, ID ASC`),
  `law_waitlist_count( $event_id )` (status only, no meta join), `law_waitlist_next_position()`
  (max+1, callers hold the lock).
- **Join** `law_waitlist_join( $event_id, $booker_id, array $additional_rows )` → `int[]`:
  mirrors create. Open guard; clean rows; resolve/create colleague accounts before the lock
  (rollback on refusal); lock; recount; **eligibility: remaining === 0**, else refuse
  `law_waitlist_places_available` ("Places are available, so you can register straight
  away."); duplicate guard across `publish` AND `law-waitlisted` (give
  `law_booking_guard_duplicates()` a `$statuses` argument); clash guard for everyone; insert
  N `law-waitlisted` bookings (author = attendee, booked_by = booker, snapshot, own booking
  numbers via one `+N` bump, consecutive positions, joined stamp); note `$was_empty` before
  the inserts; unlock; role grants; log `waitlist_joined` per booking (position, `source =>
  'waitlist'`); emails `user_waitlist_attendee_invited` / `_added` per colleague (no .ics),
  `user_waitlist_joined` to the booker with `{party_list}` (names and numbers),
  `host_waitlist_activated` once when `$was_empty`.
- **Process** `law_waitlist_process( $event_id, $source )` → promoted IDs, plain FIFO:
  no-op with no log when `$GLOBALS['law_waitlist_suspended']` is set, when
  `law_booking_guard_open()` fails, when nothing is waitlisted, or under its own static
  per-event re-entrancy guard. Lock timeout → log `waitlist_process_busy` and schedule
  `law_waitlist_resume` (+60 s, args `[ $event_id ]`, only if `wp_next_scheduled()` says
  none; WordPress drops an identical hook+args within 10 minutes). Under the lock: recount;
  walk entries while remaining > 0; per entry duplicate (against publish) and
  `law_booking_guard_clash( author, event )`; blocked → set `_law_waitlist_blocked` if empty
  and queue a `user_waitlist_blocked` email, log `waitlist_skipped` every time, continue;
  passes → `law_booking_set_status( publish )`, delete position and blocked, stamp promoted,
  recount, collect. Stop at 10 promotions or 15 s and schedule the resume. After unlock:
  `user_waitlist_promoted` with .ics per promoted attendee (`law_booking_send_with_ics()`),
  ONE `host_waitlist_promoted` summary per pass (`{promoted_list}`), the queued blocked
  emails, then `law_booking_maybe_capacity_warning()`. `add_action( 'law_waitlist_resume',
  … )` calls process with source `cron`.
- **Trigger sites** (direct calls per the module convention; **never** from inside
  `law_event_recount_attendees()`, which runs inside other bookers' locks): end of
  `law_booking_cancel()` for every context except `event_cancelled` (host reject is a cancel,
  so it is covered); the `transition_post_status` backstop when not engine-driven and the
  old status was `publish`, and the `deleted_post` backstop; the new
  `law_event_tickets_changed( $event_id, $old, $new, $actor, $source )` (bookings.php; logs
  `tickets_changed` "Places available changed: 10 → 15.", processes only on a raise) called
  at the end of both ticket write paths: `admin/event-screen.php` (capture `$before_tickets`
  alongside the other `$before_*` ~:274-277, call after `law_event_apply_slot_label()`
  ~:309, because a per-meta hook would see stale dates) and `submission-form.php`
  `law_events_form_save()` (capture before the writes ~:283, call after the loop ~:319-321,
  skip on a new submission); reorder; manual promote; leave. Migration needs nothing (source
  is `gf` while it runs).
- **Manual promote** `law_waitlist_promote( $booking_id, $actor )` → `{booking,
  overbooked_by}`: load, type check, status waitlisted; open guard; lock; recount;
  duplicate and clash guards (never bypassed); **no capacity guard**; seat; recount; when
  sold > available a loud `waitlist_overbooked` log line with the counts and the actor, and
  skip the capacity warning; unlock; `user_waitlist_promoted` (+.ics); then process().
- **Reorder** `law_waitlist_reorder( $booking_id, $direction, $actor, $expected_position )`:
  lock; ordered list; if the entry's position ≠ `$expected_position` return `moved => false`
  (stale click, success "already moved"); else move (top/up/down), renumber 1..n, log
  `waitlist_reordered` (old → new); unlock; process().
- **Leave** `law_waitlist_leave( $booking_id, $actor )` = `law_booking_cancel()` with the
  Part A contexts (`self` / `booker`). `law_booking_cancel()` picks the template by (context,
  was the booking waitlisted): `self → user_waitlist_left`, `booker →
  user_waitlist_removed_by_booker`, `host_reject → user_waitlist_rejected`, `event_cancelled →
  user_waitlist_event_cancelled`; it also deletes position and blocked meta. "Leave all" for
  a party loops the booker's waitlisted entries via `law_bookings_cancel_party()`.
- **Sweep** `law_bookings_cancel_all_for_event()`: set `$GLOBALS['law_waitlist_suspended']`
  (save/restore), fetch and cancel `law-waitlisted` entries FIRST, then `publish`, so no
  cancel can promote mid-sweep (it also runs from `wp_trash_post` while the event is still
  publish). The cron resume re-enters the same function, so the flag is set again.
- **Party behaviour (accepted)**: a party of 3 joins; one place opens; only the
  lowest-position entry is promoted and emailed; the other two wait. The booker's manage
  view shows Booked / Waitlisted per row.

## B3. Handlers (waitlist.php)

| Action = nonce | Gate | Rate | Notice / redirect |
|---|---|---|---|
| `law_waitlist_join` | signed in; `event_id` posted | `booking` 10/600/100 (shared with create) | `waitlist-joined` → event permalink; honeypot payload `{title: "You're on the waitlist", message: "We'll email you if a place becomes available.", redirect: permalink}`; no-JS state via `law_booking_store_form_state()` |
| `law_waitlist_leave` | booking waitlisted; author = me or booked_by = me; optional `all=1` | `booking_edit` 15/600/150 | `waitlist-left` → My bookings |
| `law_waitlist_reorder` | `law_user_can_manage_event( post_parent )`; hidden `direction` ∈ top/up/down and `expected_position` | new `waitlist_manage` 60/600/300 | `waitlist-reordered` → `law_booking_list_url()#law-waitlist` |
| `law_waitlist_promote` | same | `waitlist_manage` | `waitlist-promoted` (message adds "The event is now over-booked by N place(s)." when applicable) → list `#law-waitlist` |

Reject of a waitlisted entry reuses `law_booking_reject_attendee` (`host_reject` on a
waitlisted booking). Because booking-form.js posts `new FormData(form)` without the
submitter, reorder is one form per direction with hidden inputs.

## B4. Emails (registry after `host_capacity_warning`; pre-declare `{party_list}`, `{promoted_list}`, `{blocked_reason}`, `{waitlist_count}`)

| Slug | To | Trigger | .ics |
|---|---|---|---|
| `user_waitlist_joined` | booker | join | no |
| `user_waitlist_attendee_invited` / `_added` | colleague (new / existing account) | join | no |
| `host_waitlist_activated` | host | first entry on an empty queue | no |
| `user_waitlist_promoted` | attendee | process or manual promote | **yes** |
| `host_waitlist_promoted` | host | one summary per pass | no |
| `user_waitlist_left` | attendee | self leave | no |
| `user_waitlist_removed_by_booker` | attendee | booker removed their entry | no |
| `user_waitlist_rejected` | attendee | host/committee reject (`{removal_reason}`) | no |
| `user_waitlist_event_cancelled` | attendee | sweep | no |
| `user_waitlist_blocked` | attendee | first skip in place | no |

Subjects interpolate `{event_title}` only. Logging (`source => 'waitlist'`, `booking =>
id`): `waitlist_joined`, `waitlist_skipped`, `waitlist_promoted`, `waitlist_overbooked`,
`waitlist_reordered`, `waitlist_process_busy`, `waitlist_untrashed_to_back`,
`tickets_changed`, plus the cancel actions.

## B5. Front end

- **Control** (`law_booking_render_action()`, account-bookings.php:126-214): order booked →
  waitlisted → not open → passed → sold out (live) → registrable. Waitlisted (the user has a
  `law-waitlisted` booking on the event): "You're on the waitlist for this event." + "We'll
  email you as soon as a place opens up." + `button orange` "Manage waitlist entry" (booker)
  / "View waitlist entry" (colleague), linking to the manage view. Sold out replaces the stub
  at :182-188: "This event is fully booked. Join the waitlist and we'll email you if a place
  opens up." + `<a class="button orange" href="{permalink}?law_waitlist=1"
  data-law-modal-open="law-waitlist-modal">Join waitlist</a>`, both dialogs deferred to
  `wp_footer` by `law_booking_footer_modal( $event, $which, $mode = 'book' )` (stacking
  context reason, see the existing comment). The user-booking lookup, `law_user_booking_ids()`
  and `law_account_bookings()` admit `law-waitlisted`. Remove the `[aria-disabled]` stub
  comments in calendar.css:1108, calendar-event-details.php:15 and calendar-body.php:212.
- **Modal** (`parts/events/booking-modal.php`): a `mode` arg ('book'|'waitlist') switches
  dialog id `law-waitlist-modal`, action/nonce `law_waitlist_join`, heading "Join the
  waitlist", logged-out copy ("You need an account to join the waitlist…"), substate ("This
  event is fully booked. You can add up to 3 colleagues; each person gets their own waitlist
  entry and is emailed individually when a place opens up."), note (accounts created for
  colleagues, who are emailed that they are on the waitlist), submit "Join waitlist" / busy
  "Joining…", `data-law-booking-success="law-waitlist-success"`, repeater max
  `law_booking_max_additional()` (book mode's `min(3, remaining−1)` is 0 on a full event).
  `booking-success-modal.php` gains the same `mode`: title "You're on the waitlist"; copy
  covers the confirmation email, individual promotion emails with .ics, colleagues invited,
  manage or leave from My bookings; same locked two exits. `assets/js/booking-form.js:29`
  opener selector adds `a[data-law-modal-open="law-waitlist-modal"]`.
- **My bookings**: items carry a status; new `badge` arg on `parts/loop/event.php`
  (`array( label, slug )`, rendered after the `show_status` block) shows "Waitlisted"
  (`.law-cal-card__badge--waitlisted`, one rule in calendar.css next to the existing badge
  colours, navy on the light tint). Waitlisted entries whose event has started are hidden.
- **Manage view**: per-row Booked / Waitlisted badge; "Leave the waitlist" per row (self or
  booker) behind a confirm (copy "This takes {name} off the waitlist for this event. They are
  emailed to let them know."; close "Stay on the waitlist") and "Leave the waitlist for
  everyone" for the booker; no "Add a colleague" on a waitlisted party (join again instead).
- **Host and committee list** (`booking-list.php`): a Waitlist section between active and
  cancelled: `<h3 id="law-waitlist">Waitlist (N)</h3>` + substate "Entries are promoted in
  this order, automatically, as places open up. Move an entry to change the order, or promote
  it now to register it regardless of places." + the same flat table with a leading Position
  column and, per row, Top / Up / Down (`button second`, `disabled` at the edges,
  `.screen-reader-text` "Move {name} up"; three tiny forms with hidden `direction` and
  `expected_position`, busy "Moving…"), "Promote now" (`button orange`) behind a confirm
  titled "Promote {name}" with copy "This registers {name} onto the event now, ahead of the
  waitlist order, and emails them their confirmation." plus, when the event is full, "This
  event is full. Promoting this entry registers one more place than the event has, so it
  will be over-booked by N." (confirm "Promote now", close "Keep on the waitlist"), and
  Reject. The header shows sold / available in red when over-booked. The "Invited by" tag
  applies here too. `law_booking_counts_label( $event_id )` → "Bookings (n) · Waitlist (m)"
  replaces the label at functions/account-events.php:242 and
  parts/events/dashboard-list.php:37. Exports stay publish-only; the Bookings dashboard's
  status filter gains "waitlisted".
- **Notices** (shared helper from A5): `waitlist-joined` ("You're on the waitlist. We'll
  email you as soon as a place opens up."), `waitlist-left`, `waitlist-reordered` ("The
  waitlist order has been updated."), `waitlist-promoted` ("The entry has been promoted and
  the attendee emailed their confirmation."), `waitlist-failed`.
- CSS: `.law-dashboard__row-actions .law-booking-manage__action { display: inline-block;
  vertical-align: middle; margin-right: .35rem; }` and a nowrap position cell in
  event-form.css; no new CSS or JS files.

## B6. Tests: `tests/WaitlistTest.php` (LAW_Test_Case, BookingsTest setUp/tearDown pattern, mail captured via the `wp_mail` filter as in BookingEmailsTest)

1. Join refused with free places; allowed at 0 remaining; N entries with consecutive
   positions and own numbers; colleague accounts created; `user_waitlist_joined` and
   `host_waitlist_activated` once (second join: no activation email).
2. Duplicate across waitlisted both ways (register refused while waitlisted; join refused
   while booked).
3. Plain FIFO across a party: 3 waiting, cancel one active → only position 1 promoted, the
   others keep positions; promotion flips status, recounts, sends `user_waitlist_promoted`
   with an .ics attachment and one host summary.
4. Skip in place on clash: one `user_waitlist_blocked` email across two passes,
   `waitlist_skipped` logged twice, later entry promoted past it; cancelling the clashing
   booking then a further place opening promotes it and clears the latch.
5. Promotion after cancel, after host reject, after `law_event_tickets_changed()` raise; not
   after a lower.
6. Manual promote on a full event: seats, `waitlist_overbooked` logged, no
   `host_capacity_warning`, duplicate and clash still refused.
7. Reorder top/up/down, edges no-op, stale `expected_position` returns `moved => false` and
   changes nothing; moving an entry to the top with a place open promotes it.
8. Leave (author, booker), booker leave-all.
9. Untrash a waitlisted entry → position max+1; raw `wp_update_post` to publish on a
   waitlisted booking is reverted by the status guard.
10. Sweep via committee cancel and via `wp_trash_post`: waitlisted cancelled first, no
    `waitlist_promoted` line, `user_waitlist_event_cancelled` sent.
11. `law_user_booked_event_ids()` excludes waitlisted (not a clash source); export and
    dashboard rows exclude waitlisted.
12. Pass cap: 12 waiting, 12 places freed → 10 promoted and exactly one `law_waitlist_resume`
    scheduled; running the hook promotes the rest.
13. Join and process refuse/no-op after `_law_start`, with no ticket number, on the `gf`
    source.

## B7. Issues and open questions (waitlist)

| # | Issue | Default |
|---|---|---|
| 1 | A party can split (one colleague promoted, the rest waiting); each is emailed separately | Accept; the join email says so |
| 2 | Party of 4 with 2 places left is refused outright; no partial register + waitlist offer | Keep all-or-nothing; whole-party join only when full |
| 3 | Waitlisted colleagues get accounts and an invite at join | Decided by Denis; invite copy says "waitlist" |
| 4 | Over-booking visible only on the host list and admin (remaining clamps at 0) | Red sold / available on the list header; loud log |
| 5 | Waitlist entries never cleaned up after the event starts | Hide on the account page; no cron in v1 |
| 6 | The resume relies on pseudo-cron | System cron hitting wp-cron.php before launch (ops note) |
| 7 | Full-page caching can show a stale control to logged-out visitors | Ops note; server guards hold |
| 8 | Spec §4.3 reserves reject to LAW admin; the code lets hosts reject | Hosts may reject waitlist entries too |
| 9 | A blocked waitlisted user who sees a free place cannot Register (their entry is the duplicate) | They leave the waitlist and register |

---

# Part C: documentation and rename

- `git mv EVENTS_4.1_FUNC_V2.md EVENTS_FUNC.md`; retitle "Events module: code report (custom
  rebuild, 4.1 and 4.2)"; keep the "Keep this file current" blockquote verbatim; move the
  65-line dated changelog paragraph into a "Change history" section at the end; add one
  sentence distinguishing it from the gitignored legacy `EVENTS_4.1_FUNC.md`. Update every
  reference: EVENTS_BOOKINGS.md (grep; currently lines 32, 98, 651),
  functions/events/committee.php:237, .claude/agent-memory/security-specialist/recurring-
  patterns.md:18. (EVENTS.md is the legacy Gravity-stack reference and stays untouched.)
- EVENTS_BOOKINGS.md is a dated contract: add a "Revision 2, 8 September 2026: one booking
  per attendee" section and mark the superseded passages (§2 party wording and the sold-out
  decision, §3.2, §3.3, §5, §6 handler table, §7.3 to §7.6, §9 to §11, §13, §14 "waitlist
  deferred") with one-line callouts pointing here; do not delete them.
- Part B's contract lives in this file (WAITLIST.md); keep its decisions and review outcomes
  current as the build proceeds.
- EVENTS_FUNC.md sections to update: header entry; file count; post-types (three booking
  statuses); statuses (`law_booking_statuses()`); meta (new keys, `attendee_rows` gone,
  `bump_counter( $by )`); workflow (untrash whitelist, `law_booking_set_status()`);
  bookings.php (per-attendee model, register-by-manager fold-in, cancel contexts,
  `tickets_changed`, export); new waitlist.php section; bookings-dashboard.php;
  notifications (count and the waitlist family); admin booking screen; front-end section
  (six-state control, `mode`, `badge` arg, shared notices, counts label); tests.

# Build order and gates

1. Commit the uncommitted 8 September round. Add `.playwright/` and `.playwright-cli/` to
   `.gitignore` if missing.
2. **Part A**: A7 schema → A2 engine → A3 handlers → A4 emails → A5/A6 surfaces → A8 tests
   and purge. PHPUnit green. Security-specialist review of the changed handlers, then the
   test-specialist Playwright pass over the booking flows (register with colleagues, manage
   view cancel/add/cancel-all, colleague view, host list with Invited by tags, reject,
   exports, Bookings dashboard, admin screen), emails in Mailpit, screenshots with a
   rendering and contrast check.
3. **Part B**: B1 → B2 → B4 → B3 → B5, `tests/WaitlistTest.php` alongside. Security gate
   over the four handlers and the status flip (checklist: guard_post on every handler; nopriv
   JSON; booking loaded and type-checked, event from post_parent, `law_user_can_manage_event`
   for reorder/promote, author-or-booker for leave; direction enum and server-side position
   recompute; force alters only the capacity guard; every shared read under the lock;
   process() re-entrancy guard, suspend flag, open re-check, cap per pass, no email under
   the lock; duplicate guard scans both statuses on join, publish only at promotion; recount
   and export publish-only; status flips only via `law_booking_set_status`; untrash
   whitelist; no position in attendee-facing output; no attendee text in subjects).
   Test-specialist pass: sold-out join logged out and no-JS `?law_waitlist=1`; join with 0
   and 3 colleagues (duplicate marked inline); waitlisted control states as booker and
   colleague; manage view leave; host list reorder (edges disabled, reload at
   `#law-waitlist`), Promote now warning, reject; a cancellation auto-promotes and the
   emails with .ics land in Mailpit; Register refused while the user is waitlisted; labels
   on the host card and dashboard; screenshots.
4. **Part C** docs and rename.
5. Three independent completion checks of this document against the code (original brief,
   this document section by section, every decision and review fix) before declaring done.
