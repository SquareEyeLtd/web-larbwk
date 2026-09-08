---
name: waitlist-e2e-pass-2026-09-08
description: Full Playwright E2E pass over the per-attendee-booking + waitlist rebuild (WAITLIST.md Parts A/B) - 10/10 flows pass; 1 real CSS bug found (modal text clips inside dashboard tables); shared-dev-environment hazards encountered
metadata:
  type: project
---

Ran the full 10-flow smoke from the "one booking per attendee + waitlist" brief on 2026-09-08
against `http://localhost/law` (per [[environment-url]], NOT law.localhost). 138 PHPUnit tests
were green before this pass started. Screenshots at `.playwright/screenshots/01`-`31-*.png`.

**Overall verdict: all 10 flows PASS.** Register-with-colleagues (consecutive booking numbers,
correct per-person emails, exactly one host/committee email per submission), My bookings +
manage view (cancel one colleague, add a colleague, cancel-all), booker self-cancel keeping
colleagues (and re-registering back into the same party), host bookings list (flat table,
"Invited by {name}" tag, reject-with-reason, CSV export with an Invited-by column), the
waitlist (join, auto-promotion in position order, host reorder top/up/down with correct
promotion-follows-the-move semantics, Promote now with the over-book warning and the red
"over-booked by N" header), and the no-JS fallback (`?law_book=1` / `?law_waitlist=1` render
real forms and submit via plain POST, a plain-POST cancel action works) all functioned
correctly end to end, verified against Mailpit (subjects, bodies, .ics attachments) not just
UI state.

## Real finding: confirm-dialog text clips instead of wrapping inside dashboard tables

`parts/layout/modal.php`'s reusable dialog (`.law-modal__copy` paragraphs) renders correctly
everywhere EXCEPT when the modal's opener form lives inside a `.law-dashboard__table` row
(host/committee bookings list, waitlist section: the Cancel/Reject-with-reason dialog and the
Promote-now dialog). Root cause: `event-form.css:357` sets
`.law-dashboard__table th, .law-dashboard__table td { white-space: nowrap; }`; `white-space` is
inherited, and `.law-modal__copy` never resets it back to `normal`, so the dialog — despite
being `position: fixed` and visually escaping the table — still inherits `nowrap` from its DOM
ancestor `<td>` and overflows its own 32rem width instead of wrapping. Confirmed by direct
screenshot comparison: the identical component wraps perfectly inside `booking-manage.php`
(not a table; screenshot `08-cancel-colleague-confirm.png`, `11-cancel-all-confirm.png`) but
clips mid-sentence inside `booking-list.php`'s table (screenshots `17-reject-with-reason-confirm.png`
and `27-promote-now-overbook-confirm.png` both cut off text at the dialog's right edge, hiding
the second half of the copy). Fix would be a one-line `white-space: normal;` on `.law-modal__copy`
(and probably `.law-modal__title`/`.law-modal .law-form-field label` for safety) in
`law-modal.css`. Not fixed — reporting only, per role.

## Shared-dev-environment hazards (process notes, not product bugs)

- **This is a genuinely shared working tree**, not an isolated sandbox: during this pass,
  another live session was concurrently running PHPUnit AND making uncommitted edits to
  `functions/events/bookings.php`, `booking-list.php`, `bookings-dashboard.php`, etc. (`git
  status` went from clean to 17 modified files mid-pass without any action of mine). Confirmed
  via `ps aux` showing multiple independently-invoked `phpunit` processes I did not start, with
  `sed`-driven live copy edits in their command lines. **Before trusting a "PASS" from this
  agent as a durable signal, check whether the tree was clean when the pass ran** — if another
  session is mutating the exact files under test in real time, treat results as a snapshot and
  ask for a repeat pass once that work settles/commits.
- **Killing a `phpunit` mid-run leaves orphaned fixture posts behind** (`LAW_Test_Case`'s
  `tearDown()` never runs): I killed 3 duplicate background `phpunit` invocations of my own
  early in this session (a `playwright-cli` timeout artifact, see below) and it left 4 stray
  "Test event N" posts + 3 stray `law_booking` rows sitting in the live DB with `post_author=0`.
  Traced and hard-deleted the 3 with attached bookings once confirmed genuinely orphaned
  (unchanged across many *other* sessions' completed test runs in between) — left two bare
  "Test event" posts with zero bookings alone since a phpunit run was live at the time and I
  couldn't be certain they weren't its in-flight fixtures. **Never background more than one
  `phpunit` invocation at once against this shared DB**, and don't `kill -9` one mid-run unless
  you're prepared to hand-clean its fixtures afterward.
- **`law_bookings_counter` collided with a concurrent PHPUnit run.** `WaitlistTest`'s `tearDown()`
  explicitly does `update_option('law_bookings_counter', $this->counter_before, false)`, and
  when that ran in real time against my in-progress browser session, my next booking reused an
  already-issued number (observed "Booking #305" on three unrelated posts). The atomic bump
  function itself (`law_events_bump_counter()` in `meta.php`, `LAST_INSERT_ID` pattern) is
  correct — this was pure cross-session interference, not a numbering defect. Don't flag
  duplicate booking-number sightings as a product bug without first checking for concurrent
  phpunit activity via `ps aux`.
- **`playwright-cli cli click` and `cli run-code` can hang indefinitely** in this environment
  under DB/server load (several genuine ~1-2 minute hangs, one `run-code` with
  `newCDPSession`/`Emulation.setScriptExecutionDisabled` that never returned at all — killed
  it). The underlying action had usually already succeeded server-side (confirmed via a fresh
  `goto` immediately after killing the hung command) — a hang is not evidence of a real
  failure; re-check page/DB state before concluding the click didn't work.
- **No supported way found to disable JavaScript in a `playwright-cli` browser context**
  (no `--no-js`/context-option flag on `cli open`, and the CDP `Emulation.setScriptExecutionDisabled`
  workaround via `run-code` hung and was abandoned). For "no-JS fallback" flows, drove the
  `?law_book=1` / `?law_waitlist=1` inline forms and plain-POST management actions directly
  with `curl` + a cookie jar (`-c`/`-b`) instead — curl categorically cannot execute JS, so this
  is at least as rigorous as a browser context flag, and it's the pattern to reuse next time
  this brief comes up. Gotcha: the repeater's "Add a colleague" row template uses
  `data-name="law_attendees[__i__][name]"` (JS-populated), not a real `name=`, so a genuine
  no-JS submission has no way to add a colleague at all — submit with zero `law_attendees[...]`
  fields for a bare self-registration; adding one manually with your own logged-in email
  collides with the implicit booker row and gets refused as "listed more than once."
- Members-gate workaround for attendee-role browser testing: rather than widening the
  programme page's `_members_access_role` list (the pre-launch gate — see
  [[known-issue-public-pages-members-gated]]), added each test attendee to the fixture event's
  `_law_co_owner_ids` meta. `law_user_can_manage_event()` bypasses the gate for co-owners, so
  this reaches the single-event page as a genuine `attendee`-role user without touching any
  site-wide config. Cleaner than the old gate-widening approach and needs no revert beyond
  deleting the fixture event.
- A "SPONSORED" badge unexpectedly appeared on a fixture event's My-bookings card — investigated
  before reporting and confirmed correct, not a bug: `law_events_post_is_sponsored()` also
  flags any host with more than one Approved/Confirmed event this year, and the fixture host
  (`claude-test-host`) legitimately owned several by the time of this pass. Don't reuse a
  single "prolific" host account across many fixture events if that badge's absence matters to
  a future check.

## Fixture pattern used (reusable next time)

Built 4 disposable `law_event` posts + ~9 disposable users directly via `wp_insert_post()` /
`wp_insert_user()` / `law_booking_create()` / `law_waitlist_join()` in a one-off PHP script run
via `php -d memory_limit=512M script.php` (128M OOMs on `wp-load.php`, matches
[[test-accounts]]). Set `_law_reference` on every fixture event to avoid the empty-parens email
placeholder bug documented in [[bookings-phase8-e2e-pass-2026-09-07]]. Used one shared fake
email domain (`@qawltest.invalid`) and a `QAWL ` event-title prefix throughout so cleanup was a
single unambiguous `LIKE` query at the end (hard-deleted 5 events, 20 bookings, 17 users;
confirmed booking count back to the pre-existing baseline, not literally 0, because of the
orphaned-fixture caveat above).
