# Phase 4.2 build plan

*Living document, maintained by Claude (planning) for reference in Cursor (implementation). Last updated: 19 Aug 2026.*

This file is the working brief for Phase 4.2 of the LAW events platform. It sits alongside `event-workflow.php`, `gravity-flow.php` and `gravity-forms.php`, which carry the Phase 4.1 implementation this phase builds on. For full commercial and spec background, see `LAW_Phase4.2_context_summary.md` (Claude project) — this file stays focused on what's actionable for build.

---

## Confirmed build order

1. **Self-service host upgrade** *(moved to top of queue, 12–14 Aug)* — an existing attendee can request the host role for their own event without a second account. Uses the existing host submission flow, pre-filled from the attendee's account, approval adds the role to the same account.
2. **HubSpot tagging** *(outstanding from Phase 1, partially needed now per Emily)* — confirm with Emily/Marie exactly which tagging is needed immediately vs. can wait for full 4.2 HubSpot work.
3. **Public programme, event pages and calendar** — list view (default), calendar/grid view, day-by-day view, search/filter (date, time, speaker, host org, category, keyword). GravityView-driven, custom template for the grid.
4. **Speaker directory** — dedup on email as unique identifier, primary + additional organisations, role dropdown, admin merge tool.
5. **Host management of live events** — review-before-publish routing, attendee dashboard + CSV export (GravityView), capacity warnings (email at 5-from-capacity).
6. **Enhanced session-level agendas** — opt-in per event, structured nested sessions with per-session speakers.
7. **Payments and Stripe foundation** — Checkout, VAT (standard rate on all tickets, Stripe Tax), receipts, transaction references surfaced on both attendee account and committee view.
8. **Flagship application workflow** — SetupIntent at application, Gravity Flow committee review (bulk approve/waitlist/decline), off-session PaymentIntent on approval, Wednesday reception checkbox confirmation.
9. **Confirmation and calendar-invite notifications**.
10. **Basket** — multi-event selection, clash detection (overlapping time slots), duplicate-registration prevention (email as unique ID).
11. **Group booking, discount codes, automatic colleague accounts** — Nested Forms + eCommerce Fields, 3-attendee cap per transaction, match-by-email account linking for booked colleagues.
12. **Waitlist** — host-controlled ordering, snapshot-at-promotion (avoids race condition with reordering mid-charge), retry-on-failure UX, notification templates.
13. **Attendee self-service account area** — booking history, VAT receipts, calendar export, print, cancel/withdraw.
14. **Tiered reception pricing and priority sales window** — blocked on reception pricing decision (see Open decisions below).
15. **Press registration** — admin-issued only, not self-service.

*Items 3–13 numbering follows the spec's dependency chain (§1 of the 4.2.6 spec); reordering within 3–9 isn't really available since each depends on the one before. Items 10 onward have more flexibility if priorities shift — check with Trevor before resequencing.*

---

## Architecture principles (carry into every item)

- **"WordPress decides, Make passes through."** All conditional logic belongs in GPAC calculated fields on the WP side. Don't put branching logic in Make expressions — it consistently fails there.
- **Gravity Forms entries are the canonical data store**, not custom post types.
- **GF REST API**: PUT on `entries/{id}` blanks any field not in the body. Use the Gravity Flow Incoming Webhook extension's workflow-hooks endpoint for single-field writes that also need to advance the workflow.
- **Gravity Flow approval steps** have no built-in "Update Fields" option — field writes on step completion need the `gravityflow_step_complete` hook in `functions.php`.
- **Make expressions**: semicolons as separator pills (not typed), one bracket pair per `if()` comparison, string literals typed bare without quotes.
- **No WooCommerce** — booking engine stays inside Gravity Forms/Flow/Perks/View. Rationale: the approval gate (flagship, and any approval-then-pay hosted event) doesn't fit Woo's buy-now model; avoids running two commerce stacks; the actual need (multi-item, multi-attendee single payment) is narrower than Woo's scope.

---

## Open decisions blocking specific items

- **Reception pricing** (blocks item 14): Monday reception previously agreed £25 (flagship) / £75 (general) — Emily's later message mentioned "£50," unclear if that supersedes. Wednesday reception price for non-flagship purchasers undecided between £50/£75. Doesn't affect build effort, only the price fields configured once agreed — but confirm before wiring up item 14's pricing logic.
- **§3.2 vs §3.7 "featured content" contradiction**: §3.2 and the out-of-scope list describe the featured homepage ribbon as optional/separately costed; §3.7 describes admin-side featuring capabilities as if included. Could be two different things (public ribbon vs. committee curation tools) or leftover text — worth a one-line confirmation from whoever owns the spec before touching §3.7's items, likely around item 3 (programme/calendar) or a later polish pass.
- **HubSpot tagging scope** (item 2): Emily confirmed "some" tagging is needed now; get the specific fields/list from her rather than assuming full 4.2 HubSpot sync is meant.

---

## Per-item working notes

*(Add a dated subsection here as each item starts, so Cursor sessions pick up exactly where the last one left off — approach taken, what's tested, what's still open for that item.)*

### 3. Public programme, event pages and calendar
*Design settled 19 Aug 2026. Mockup: `design/calendar-view-mockup.html` (open in a browser, tabs switch between the three states). Brand colours read from `assets/css/app.css`: navy #292459, orange #ef7d05, tints #f4f2fa / #e3e0ec / #fdeee0.*

**Four decisions confirmed for build:**

1. **Public programme shows Approved events only, with no status badge.** Confirmed / Proposed / Sent back stay off this page. Status labels come back on the committee calendar (separate job): that view shows all events, filters by status, and uses `law_calendar_status_badge()`.
2. **Week view shows Mon–Fri including empty days.** Don't collapse to only the days with entries — that reflects the current state of submissions, not the shape of the week, and would need rebuilding once the programme fills out.
3. **Event pages render from the GF entry via GravityView — this is the permanent architecture, not a stub.** Spec §3.9 specifies the programme is built on 4.1's event entries presented through GravityView, not converted to WP posts. Consistent with the project principle that GF entries are the canonical store. Build once, treat as final.
4. **Functional skeleton first, design applied after.** The only design decision that risked a rebuild (the three view states) is now settled, so the skeleton can ship without waiting.

**Day view: no indentation.** An earlier draft of the mockup offset overlapping sessions horizontally, borrowing the Google Calendar convention. Removed — that convention only works alongside a true time axis (proportional block heights, real vertical positioning), which the Day view doesn't have. Without it the indent implies a time relationship the layout doesn't encode, and it breaks down past three or four overlapping sessions. Cards sit flush against the time gutter; the printed times carry the overlap information. This keeps Day view as "List view filtered to one day," consistent with list-as-default for accessibility and mobile (§3.1). The timetable-vs-list question is answered in Week view, and doesn't need answering twice.

**Packed-morning handling (Week view):** parallel sessions share their day column as equal-width slices rather than stacking or overlapping. See the Tuesday 09:00 cell in the mockup.

**List view: day jump via anchor chips, not a filter.** A sticky row of five day chips sits at the top of List view, each an in-page anchor to that day's heading. Deliberately *not* a day filter: filtering List view to one day just duplicates Day view, leaving two routes to the same result. Anchor-jumping does what Day view can't, which is keep the whole week in one continuous scroll while letting someone skip to Wednesday without scrolling past ninety events. Implementation is plain `<a href="#day-wed">` links plus `scroll-margin-top` on the day headings to clear the sticky bar; no JS required, so it degrades gracefully.

Two associated decisions:
- **Chips reflect filter state.** A day with no events under the current search/filter combination renders greyed and non-clickable, rather than jumping to an empty section. Needs handling when the §3.2 search and filter work lands, since the two interact.
- **No auto-scroll to today during LAW week.** The current day's chip is visually marked instead. Auto-scrolling would fight List view's scan-the-week-end-to-end purpose, and would strand someone arriving on Thursday to plan Friday. Marked rather than jumped is discoverable without deciding for the user. This one is a judgement call rather than clear-cut, and Emily may have a view once she sees how delegates use it during the week, so worth revisiting after year one rather than treating as settled.

**Skeleton (19 Aug 2026, Cursor):** private page `/calendar`, template `templates/calendar.php`, data in `functions/calendar.php`. Reads Form 2 active **Approved** entries with a parseable confirmed slot (field 68). List / Day / Week match the mockup; cards link to `?event={id}` on the same template (GF entry, not a WP post). No status badges on the public page. Members restriction is applied in the dashboard, not in the template. Live slot times (08:30, 10:30…) are used rather than the mockup's illustrative 09:00 hours.

**Hybrid search (19 Aug 2026, Cursor):** List / Day / Week stay custom. Search is a GravityView Search Bar from a new **Programme** View (`/wp-admin` → Views → Programme). The calendar template renders that View's filtered entries, not a separate GFAPI query (GFAPI remains a fallback if the View is missing). Search fields: keyword, confirmed slot (68), host org (105), sector (60), type (63). Advanced Filter on the View is **Approved only**. Page size 500 so week view is not truncated. `embed_only` so `/programme/` is not a public table. Event permalinks stay `?event=` until the View single template is wired. Dashboard still needed: Members restriction on `/calendar`.

**Committee calendar (19 Aug 2026, Cursor):** `templates/calendar-committee.php` at `/calendar-committee/`. Same List / Day / Week as public, but every status, status badges, and a Status dropdown on the search bar (View: **Programme (committee)**). Events without a confirmed slot sit in a “No confirmed slot” list section; Day/Week link there. Members roles match the Committee dashboard (`administrator` / `editor` / `events_committee`).

---

### 1. Self-service host upgrade
*Started 19 Aug 2026. Handed to Cursor 19 Aug for build — Claude's role from here is planning/spec only. Cursor has live DB and admin access this plan doesn't.*

**Confirmed so far:**
- Profile form is **Form ID 3** ("User profile"). Field **12** ("Role") is a checkbox with choices `sponsor` / `event_host` / `attendee` — matches Form 1's registration field 11 exactly. No new GF field needed.
- Prefill was already happening, but from ACF `law_role` (mu-plugin), not from WP roles. Screenshot of Trevor's profile showed ACF, not `wp_capabilities`.

**Investigation (19 Aug 2026, Cursor) — done before writing the save handler:**

1. **Prefill mechanism found.** Not Populate Anything. `mu-plugins/law-user-profile-update.php` hooks `gform_pre_render` and pre-checks mapped checkboxes from ACF. Form 3 field 12 ← ACF `law_role`. Same plugin writes those checkboxes back to ACF on `gform_user_registered` / `gform_user_updated`. Nothing in that plugin touches WP roles.
2. **Multiple roles / ACF drift.** ACF can hold multiple values (user 31: sponsor + event_host + attendee, matching WP). It can also be empty while WP has all three (users 17, 22). Trevor's screenshot was ACF (`event_host` in `law_role`) not WP (he's `administrator` only). Prefilling from ACF alone is unsafe for a WP-role save: an empty ACF field would uncheck every box and the save would strip real roles.
3. **No save-side WP role sync existed.** Form 1 registration in `functions/users.php` (`gform_user_registered`) is the only WP-role writer. Form 3 had none.
4. **User Registration feed is safe.** Form 3 feed 25 ("User update") has `role: gfur_preserve_role`. Field 12 is not mapped to User Role, so the add-on will not `set_role()` on profile save.

**Built (19 Aug 2026) in `functions/users.php`:**
- `gform_pre_render_3` — field 12 pre-checked from the logged-in user's actual WP roles (intersection with `sponsor` / `event_host` / `attendee`), after the mu-plugin's ACF prefill. Skips on validation re-render so posted values win.
- `gform_user_updated` (form 3 only) — add/remove those three roles to match field 12. Other roles (administrator, events_committee, etc.) are never touched.

Still worth a front-end check: open the profile as a multi-role user (e.g. 22 / 31), confirm all held roles are checked, toggle Event host, save, confirm `wp_capabilities` and ACF `law_role` both match.

**Decision (unchanged): self-service, not the spec's committee-moderated version.** Matches Marie's original framing well now that the field already exists. Flagged to Emily/Marie as a heads-up, not held up pending their answer.

**Gotcha to design around, whatever the investigation finds:** the User Registration Add-on's native "User Role" field mapping replaces all existing roles (WP's `set_role()` behaviour) rather than adding to them. Whatever ends up handling the save, it needs to add/remove roles individually and scoped **only** to `sponsor` / `event_host` / `attendee`, so it never touches an `administrator` or other role a user might also hold. If save-side logic needs to be written or fixed:
```php
$known_roles = [ 'sponsor', 'event_host', 'attendee' ];
// $selected = values read from entry fields 12.1–12.3
$user = new WP_User( $user_id );
foreach ( $known_roles as $role ) {
    $has     = in_array( $role, $user->roles, true );
    $checked = in_array( $role, $selected, true );
    if ( $checked && ! $has )  $user->add_role( $role );
    if ( ! $checked && $has )  $user->remove_role( $role );
}
```

**What already exists, for reference:**
- Form 1 (registration) sets WP roles additively via checkbox field 11 — `gform_user_registered` in `functions/users.php`. Same value set as field 12, useful reference pattern even though it's a different (registration vs. update) context.
- Role-based `body_class` filter (`functions/gravity-flow.php`) for front-end conditionals, if needed later.

**Still open:**
- Front-end test of the new save/prefill (see Built note above).
- Whether the two originally-affected users need a one-off data fix beyond the role change (a stuck/misattributed event submission from when they had the wrong role) — a role fix alone won't resolve that retroactively.
- Trevor's stopgap (clearer text on the sign-up form, mentioned in the Aug 12 Basecamp reply) is already live and separate from this item.
- Superseded: the Form-2-resubmission-plus-committee-approval approach from the original plan is not being built for this item; kept only as context in case the committee later asks for a moderated version.

---

*Maintained jointly: Claude updates this after planning discussions, Cursor/Trevor updates the per-item notes as build progresses. Keep it in git with the rest of the theme so history is preserved.*
