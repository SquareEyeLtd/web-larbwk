# Events module: code report (custom rebuild, 4.1 and 4.2)

> **Keep this file current.** This document is the working reference for the
> events module: any agent or developer who makes a material change to the
> module (a new file or function, a changed workflow, security or payment
> behaviour, a settings or UI change that alters how the module works) must
> update the relevant section here in the same piece of work, and refresh the
> "verified against" date below. Small refactors that change nothing about
> behaviour do not need an entry; anything a future reader would be misled by
> does.

Working reference for the custom, CPT-backed events module that replaces the
Gravity Forms / Gravity Flow / GravityView / Make stack: the 4.1 host side
(submission, moderation, invoicing) and the 4.2 attendee side (bookings, the
waitlist, and the flagship conference's registration and payment, which the
code still calls the application flow). What the
code does is described in the sections below; when each part arrived is in
**Change history** at the end.

The design contracts are EVENTS_4.1_REBUILD.md (the rebuild), EVENTS_BOOKINGS.md
(the attendee bookings slice), WAITLIST.md (one booking per attendee, and the
waitlist), FLAGSHIP_UI.md (the flagship event itself) and
FLAGSHIP_PAYMENTS.md (its approval-gated application and payment); this
document maps them onto the code as built.

Note the near-identical name: `EVENTS_4.1_FUNC.md` is a different, gitignored
document describing the **legacy** Gravity Forms stack. Unlike that one, this
file carries no secrets, so it is safe to track. Stripe keys live only in
`wp-config.php` (`LAW_STRIPE_*` constants).

Where a Gravity Forms form or field is named, it is paired with its name per
house convention, e.g. form 2 (Event > submit an event), field 95 (Event
status). The rebuild retires those forms; they appear here only where the
migrator reads them or where the legacy source path still branches on them.

---

## 1. Where the feature lives

1. **The events module** (`functions/events/`): a self-contained package of 48
   files (32 top level including the loader, 8 `admin/`, 3 `stripe/`,
   5 `migration/`) loaded by one
   loader, `functions/events/_load.php`, which is required from
   `functions.php:30`. Everything new lives here: the custom post types, the
   workflow engine, the bookings engine, direct Stripe invoicing, the migration
   tooling, the custom registration/profile/submission forms, the committee
   dashboard and the site-wide email test mode.
2. **Shared front-end files** (`functions/`): `calendar.php`, `speakers.php`,
   `account-events.php` and `auth.php` predate the rebuild and now branch on
   `law_events_source()` so they read either the legacy Gravity Forms entries
   (`'gf'`) or the migrated `law_event` posts (`'cpt'`).
3. **Templates, parts and assets** (`templates/`, `parts/events/`, `assets/`):
   the front-end views, the shared form stylesheet/script, and the admin
   stylesheet/script the module's wp-admin screens use.
4. **Not code**: the Stripe account (customers, invoices, the tax rate and
   invoice-rendering template, referenced by ID in the settings) and the
   inbound Stripe webhook. There are no Make scenarios and no Gravity Flow
   steps in the rebuilt path.

The whole module is gated by a single option, `law_events_source`, so it can
be deployed dormant, migrated, then flipped live — and flipped back to roll
back. `law_events_source()` is defined at the bottom of `_load.php`.

---

## 2. The events module (`functions/events/`)

Load order is set in `_load.php`: settings → post types → statuses → meta →
countries → capabilities → fees → log → **request** → workflow → comments → unread →
co-owners → **ics** → **discounts** → **bookings** → **waitlist** →
**bookings-dashboard** → **discounts-dashboard** →
**test-mode** → notifications → **emails-dashboard** → speakers →
**speakers-dashboard** →
**flagship** (+ **flagship-form**, **flagship-dashboard**, **flagship-bookings**,
**flagship-bookings-dashboard**) → source → edit-lock → submission-form →
registration → committee → export → Stripe (client, service, **attendees**,
webhook) → admin (fields, event/booking/speaker/session/**flagship** screens,
columns, emails) → migration (report, runner, page, repair-owners,
repair-references, backfill-session-agenda).

### `settings.php`: the settings store and the LAW submenu host

- `law_events_settings_defaults()`, `law_events_settings()`,
  `law_events_setting()`, `law_events_update_settings()`: one option,
  `law_events_settings`, holds the fee tiers, the programme year, the date/time
  slots, the committee recipient emails, the Stripe `tax_rate_id` and
  `rendering_template_id`, the `speakers_archive_public` switch and the
  reserved `host_edit_review` mode.
- `speakers_archive_public` (Denis, 17 September 2026): whether the Speakers
  archive is public. **Off by default.** The archive is assembled from Confirmed
  events as soon as they exist, which is long before LAW wants the line-up
  announced, so the checkbox on Events → Settings ("Speakers archive") holds it
  back. While it is off, three things hold together: `/speakers/` answers
  **404** to the public, the page is dropped from the **XML sitemap**, and
  `templates/speaker.php` omits its "Back to speakers" link. Committee members,
  editors and administrators read the archive normally throughout, and so does
  everyone for a **single profile**, which every event page links straight to.
  Switching it on restores all three at once. See `speakers.php` below.
- `law_events_slots()`: the canonical slot list (label → date/start/end),
  retired slots excluded unless asked for. **Retired** means "no longer offered
  to hosts": the slot keeps its row in the settings textarea (fifth column, the
  literal word `retired`), so an event that already holds it still resolves its
  dates and still shows it, but it is dropped from the host form's choices. The
  committee dashboard, the wp-admin event screen and
  `law_event_apply_slot_label()` all pass `true` and label the slot
  "(retired)", because a confirmed slot that vanished from the select would
  fall back to "Slot not confirmed" and blank the event's datetimes on the next
  save. Deleting the line instead of retiring it breaks both.
- `law_events_slot_label_key()`, `law_events_normalise_slot_label()`: a loose
  comparison key (entities decoded, en/em dashes folded to a hyphen, spaces
  around it dropped, whitespace collapsed, lower-cased) and the mapping of any
  stored or submitted label onto its configured one. Needed because legacy
  form 2 (Event > submit an event) held the same twelve slots in two fields
  with **different punctuation** — field 68 (Confirmed slot) with en dashes,
  field 77 (Preferred date & time slots) with plain hyphens. The settings list
  is seeded from field 68, so the field 77 values migrated into
  `_law_preferred_slots` matched no configured slot and rendered as twelve
  unticked boxes until they were normalised (112 values across 45 events
  locally, 9 September 2026).
- `law_events_split_slot_label()`: the label split into `date` and `time` on
  the first colon followed by whitespace (the colon in "08:30" has none, so it
  survives). For surfaces that stack the two halves rather than let one long
  "Tue 1st Dec: 08:30-10:00" string set a table column's width; the committee
  dashboard's Slot column uses it. Any label in another shape comes back whole
  as the date with an empty time, so nothing is lost.
- `law_events_slot_choices( $selected )`: the choices the submission/edit form
  renders — every active slot, **plus any retired slot the event already
  holds**, matched by key. This is the parity replacement for
  `law_gf_hide_retired_preferred_slots()`, which deliberately kept a retired
  choice visible when the entry being edited had it. Omitting the checkbox
  altogether made the host's own choice disappear from the form and dropped it
  on the next save of a draft/proposed/sent-back event (the statuses where
  `preferred_slots` is not locked).
- `law_events_sanitise_preferred_slots( $submitted, $existing )`: keeps only
  configured labels, in settings order, letting a retired one through only when
  the event already held it — the equivalent of
  `law_gf_strip_retired_on_new_submissions()`, so a tampered POST cannot
  re-select a withdrawn slot or invent a label. Falls back to plain
  sanitisation when no slots are configured, so an unseeded site cannot lock
  hosts out of the form.
- `law_event_apply_slot_label()`: writes `_law_start`/`_law_end` from a chosen
  slot, or clears them when the label is emptied; the incoming label is
  normalised first, so a legacy-punctuated value still resolves. **Shared** by
  the committee
  dashboard and the wp-admin event screen so the two save paths cannot drift.
- `law_events_committee_emails()`: the committee notification recipients,
  filtered to valid addresses.
- `law_events_stripe_mode()`: 'live' or 'test' from the secret key prefix.
- `law_events_register_settings_page()` (on `admin_menu` priority 999) and
  `law_events_register_law_subpage()`: register the settings screen. It hangs
  off the Events CPT menu as "Events > Settings" (moved there from the LAW
  menu on 15 September 2026; the slug stays `law-events-settings`, so existing
  links and bookmarks still resolve). **Priority 999 matters** for the screens
  that still hang off the LAW parent — ACF creates the top-level LAW menu late,
  so those submenus must register after it or they never attach. The registrar
  takes a `$parent` and an optional `$menu_title`, and is the shared, AME-proof
  helper behind settings and migration; it seeds `$_registered_pages` for the
  parent it was given as well as the LAW ones.
- `law_events_terms_url()`: the host terms & conditions page the submission
  form links to — the `terms_page` setting (page ID or absolute URL) if set,
  else `/policies/event-host-terms-conditions/`, else the Policies index.
- `law_events_venue_capacity_bands()`: band label → maximum tickets. **Shared**
  by the host form's ticket-allocation check, the wp-admin event screen and the
  form's capacity select, so the three cannot drift. Every ceiling is
  **inclusive** — tickets may equal the band's number, never exceed it — so
  "Under 50" allows 50, not 49. The legacy band list from form 2 (Event > submit
  an event) field 55 (Venue capacity) runs "Under 50" then "51-100", so a strict
  49 left exactly 50 with no band that would accept it. The form's capacity
  select is on every form since 17 September 2026, and required on it — see
  "The Venue needed question comes off the form" below.
- `law_events_settings_page()`, `law_events_settings_save()`: the editable
  settings screen — programme year, week start/end, the slot list, committee
  recipients, the fee tiers, the Stripe tax rate and rendering template IDs and
  the reserved `host_edit_review` radio. Stripe **keys are not shown or
  editable here**; only the derived mode ('live' / 'test' / 'unconfigured') is
  displayed, and the keys stay `wp-config.php` constants.

### `post-types.php`: the four custom post types

- Constants `LAW_EVENT_CPT = 'law_event'`, `LAW_SPEAKER_CPT = 'law_speaker'`,
  `LAW_SESSION_CPT = 'law_session'`, `LAW_BOOKING_CPT = 'law_booking'`.
- `law_events_register_post_types()` (on `init` priority 5): registers the
  four CPTs with a custom `capability_type` (`law_event`/`law_events`), no
  front-end archive of their own, `show_in_rest => false` (deliberate — nothing
  about a submitted event should reach the public REST API), and sessions
  hierarchical-by-`post_parent` under their event.
- **Why sessions are posts and not a repeater meta field on the event.** A
  session holds only a title, description, start and end time and its own
  speaker rows, and it is never queried outside its parent event today, so a
  repeater would cover current needs. The CPT is there for two reasons: the
  4.2 calendar view (EVENTS_4.2_SPECS.md) is a day-and-time grid of
  overlapping and parallel sessions **across** events, which is one WP_Query
  over a CPT but a full scan of every event's serialised meta otherwise; and
  `_law_speakers` is itself a repeater of rows, so a repeater field would mean
  a repeater nested inside a serialised blob, which the speaker appearance
  index in speakers.php would then have to walk. The CPT also gets the wp-admin
  Sessions screen and list columns for free, and gives the migrator a
  per-session `_law_gf_entry_id` to make step 4 re-runnable.
- `law_booking` (bookings phase 1, now WAITLIST.md Part A): private, no rewrite,
  `supports => title` only, a submenu of Events like speakers/sessions, and
  `create_posts => do_not_allow` on top of the shared capability set — bookings
  are only ever created by the engine (`law_booking_create()`), so the
  capacity/duplicate/clash guards and the seat recount cannot be bypassed from
  wp-admin. Event = `post_parent`, **the attendee** = `post_author` (one
  booking per attendee since 8 September 2026), status `publish` (active),
  `law-waitlisted` or `law-cancelled`.
- `law_events_register_taxonomies()`: `law_event_type` and `law_sector` on
  events, plus `law_year` on events and speakers (the programme-year filter
  that keeps a 2026 event from re-filing into 2027).
- `law_events_maybe_flush_rewrites()`, `law_events_seed_terms()`: one-time
  rewrite flush and the default term seed (event types, sectors, the current
  year).
- **Replaced 9 September 2026: the `law_event_category` taxonomy, now two
  committee switches.** The taxonomy was a parity rebuild of field 116 (Event
  category) on form 2 (Event > submit an event), an administrative checkbox
  field with the choices LAW event, Hosted event and Session-level agendas. It
  was removed earlier the same day as dead code (nothing branched on a term, so
  the committee-dashboard checkboxes were a control with no effect, and none of
  the 497 entries of form 2 in ANY status carried a field 116 value), and its
  three terms were deleted from the database. Denis then asked for the
  capability back with the behaviour it was always meant to have, modelled as
  booleans rather than a vocabulary: "let's make it as a feature switch
  separately ... Let not use categories at all". So there is no event-category
  taxonomy, and there is no "Hosted" term — hosted is simply the absence of the
  LAW switch, which is the default for every host submission.
  - `_law_is_law_event`: LAW runs this event itself rather than an external
    host. Drives the outline `LAW` tag in the committee list (`--law` is the
    only OUTLINE badge variant, deliberately: a filled navy pill would be
    pixel-identical to `--confirmed`, and an identity tag that reads as a
    status tag is worse than none), the "Run by" row on the detail view, the
    `?law_run_by=` filter, the "Run by LAW" export column and — since 11
    September 2026 — the **Organiser filter on the public programme**.
  - **The Organiser filter (11 September 2026).** `/programme/` offers a fourth
    select, "Organiser", with the values **LAW events** and **Hosted events**.
    "Hosted" is the 4.2 spec's own word for an event run by an external host
    (§2, §6); there is no hosted switch in the data, only the absence of the
    LAW one, so the filter is one boolean read two ways. The query parameter is
    `law_run_by` with the values `law` / `host`, deliberately identical to the
    committee dashboard's filter — one vocabulary in the URL wherever the
    switch is filtered on, even though the two are queried completely
    differently (the dashboard builds a `meta_query`; the programme filters
    mapped arrays in PHP).
    - `law_events_map_post()` carries `is_law`, a plain `(bool)` cast of the
      meta. That single cast replaces the dashboard's `NOT EXISTS OR != '1'`
      pair: both "off" states — no meta row at all, and the literal `0` that
      `law_event_update_meta()` stores for an unticked box — are falsy anyway.
      `law_calendar_map_entry()` (the legacy Gravity Forms map) returns
      `'is_law' => false` so both maps keep the same shape, and the matcher
      uses `empty()` so that path degrades to "every event is hosted" rather
      than matching nothing.
    - `law_calendar_filters()` drops any value that is not `law` or `host`,
      rather than passing typed text through into the link-preserving query
      args: a mistyped URL then shows the whole programme instead of emptying
      it. `law_calendar_filter_params()` is the one key => parameter list
      behind both `law_calendar_filters()` and
      `law_calendar_search_query_args()`, which previously held duplicate
      literals and had to agree or a filter would apply without surviving a
      link back from an event page.
    - The select is drawn whether or not any event carries the switch yet. A
      guard that hid it until something was flagged was built and then removed
      the same day on Denis's instruction. The original justification — it can
      never produce an empty page, because the flagship block is pinned to its
      day outside the filtered list — died on 16 September 2026 when the
      flagship started answering the filters. The reason that replaced it is
      narrower and better suited to a committee-only control: a planning view
      has to be able to ask a question that currently has no answer, and an
      empty result on the committee's own screen reads as information rather
      than as a broken page.
    - A select, not a tick box, and not by taste: `calendar-filters.js` reads
      `field.value` for every named field with no `checked` test, so a checkbox
      would contribute `law_run_by=1` permanently from the moment it rendered,
      and "Clear all" blanks values rather than unchecking. The same reasoning
      is already written down beside the committee dashboard's filter.
    - No JavaScript change was needed: the filter fields are read generically
      and every `select` is bound on `change`, so the control joined the AJAX
      partial fetch and the mobile modal for free. `parts/calendar-filters.php`
      is shared with the committee programme view, which therefore gained the
      same control.
    - Note the committee help text under the switch used to promise "It changes
      nothing on the public programme" and no longer does.
    - **Committee only since 16 September 2026 (Denis).** The public programme
      is down to three controls: keyword, sector and type. The committee's
      programme view keeps Organiser, and the committee events dashboard's own
      "Run by" filter (`templates/account-dashboard.php`) is a different control
      on a different screen and is untouched.
      - `law_calendar_organiser_filter_enabled()` is the one predicate behind
        both halves: `parts/calendar-filters.php` asks it before drawing the
        select, and `law_calendar_filters()` asks it before honouring
        `?law_run_by=`. They share a predicate because the halves must never
        drift — a parameter that still filters with no control on screen is the
        worse half of the pair, since the visitor sees a shortened programme
        and has nothing to press to lengthen it again. A bookmark from before
        the change therefore shows the whole programme rather than half of it.
      - **The gate is the page template, not the viewer's capability**, and
        that was a considered choice. The one thing a capability test would buy
        is keeping a committee member's filter alive on a round trip out to an
        event page and back — and that round trip does not preserve filters
        anyway, because `law_calendar_url()` short-circuits on a single
        `law_event` permalink before it reaches the
        `law_calendar_search_query_args()` merge. What a capability test *would*
        do is leave the stale-bookmark bug in place for the only people who have
        ever seen the control, and therefore the only people who could have
        bookmarked it. The legacy in-page detail view
        (`/calendar-committee/?event=123`) is still on the committee page
        template, so the template test preserves the filter exactly where it can
        be preserved.
      - Folding the `'cpt' === law_events_source()` test into the same predicate
        closed an older hole in passing: in legacy Gravity Forms mode every event
        maps `is_external => false`, so `?law_run_by=external` emptied the
        programme with no control anywhere to clear it.
      - The flagship block **is** subject to it now, exactly as it is subject to
        keyword, sector and type. It used to be the exception.
  - `_law_session_agenda`: this event has a session-level agenda. This is the
    4.2 §3.6 "opt-in per event, configured by LAW admin" switch, replacing the
    earlier arrangement where the opt-in was merely whether any sessions had
    been typed into an always-visible form section.
  - Both are committee-only, written from the dashboard sidebar (guarded by its
    own `law_flags_present` sentinel, since an unticked box posts nothing and an
    absent input has to mean "off") and from the `law-event-flags`
    Classification meta box in wp-admin. The wp-admin write is unconditional
    rather than going through the `$plain` map, which is guarded by
    `isset( $_POST[ $field ] )` and would let a flag be switched on and never
    off. Changes are logged by `law_event_log_flag_change()` in plain language
    ("Marked as run by LAW.", "Session agenda turned on."), as ONE entry
    covering both flags because `law_event_log_entries()` orders by
    `comment_date_gmt` with no tie-break.
  - `law_event_has_session_agenda()` (in `submission-form.php`, with the other
    form predicates) is the gate. True when the switch is on OR the event
    already has `law_session` children: hiding the section from an event that
    has sessions would strand the agenda with no way to edit or remove it. So
    unticking the box does not delete anything, and the section stays while
    sessions exist — the detail view, the sidebar hint and the save notice all
    say so, because a control that looks inert is exactly what the old category
    checkboxes were. Not memoised: any caller that writes the switch and then
    asks again in the same request would get a stale answer.
  - **The save guard matters more than the gate.**
    `law_events_form_save_sessions()` deletes every owned session the posted
    rows did not claim, so a gated section that simply stops rendering would
    wipe the agenda on the next save of any other field. `law_events_form_save()`
    therefore requires BOTH `law_event_has_session_agenda()` (the authorisation
    check — a forged sentinel must not write sessions onto an opted-out event)
    and the section's hidden `law_sessions_present` input (which distinguishes
    "the section was not on this form" from "the host cleared every row"). Rows
    posted into a gate that has closed since the form was opened (the committee
    handler does not take the edit lock) are logged as discarded rather than
    dropped silently.
  - `law_events_form_sections()` is the one section list for both the host
    template and the committee edit view, which each hard-coded their own copy
    until the agenda entry became conditional. It omits `agenda` when the gate
    is closed.
  - A brand-new submission never shows the section: the event does not exist
    yet, so there is no switch to read. Content-heavy events are therefore
    submit, get opted in, come back — a deliberate consequence of "available
    only if the event is added by a committee to the session category", not an
    oversight.
  - `migration/backfill-session-agenda.php`: the one-off, idempotent step that
    switches `_law_session_agenda` on for every event that already has
    sessions, with a scan/apply panel on the migration screen like
    `repair-owners.php`. Without it the switch would read "off" on events that
    plainly have an agenda, and only the gate's "or has sessions" limb would
    keep their section open, so the switch and the admin column would contradict
    the event. (It also fed a "With an agenda" dashboard filter until
    15 September 2026, when that select was dropped as noise: the committee list
    already prints each event's session count.) `_law_is_law_event` needs no
    backfill: its filter's `NOT EXISTS OR != '1'` pair is correct
    unconditionally.


### `statuses.php`: the custom workflow statuses

- `law_event_statuses()`: the seven statuses and their labels — `law-draft`
  (Draft), `law-proposed` (Proposed), `law-sent-back` (Sent back),
  `law-approved` (Approved), `publish` (**Confirmed** — the only public one),
  `law-rejected` (Rejected) and `law-cancelled` (Cancelled — the shared
  terminal state of the committee's `cancel` and the host's `withdraw`; there
  is no un-cancel).
- `law_events_register_statuses()` (on `init` priority 6): `register_post_status`
  for each, with count labels for the admin list.
- `law_event_status_label()`: label for a status key or a post.
- `law_booking_statuses()` and `law_booking_status_label()`: the BOOKING
  vocabulary (`publish` = Active, `law-waitlisted`, `law-cancelled`), kept
  deliberately out of `law_event_statuses()`, which drives the committee's
  event filters and the events admin list. `law-waitlisted` is registered
  alongside the event statuses on the same `init` priority 6 hook: WP_Query
  silently drops an unregistered `post_status`, leaving no status clause at
  all, which would return every booking of every status.
- `law_event_status_from_legacy()`: maps a legacy field 95 (Event status) value
  (Proposed/Sent back/Approved/Confirmed/Rejected) to a CPT status, used by the
  migrator.
- A `display_post_states` filter labels the statuses in the admin list.

**Booking statuses grew on 10 September 2026** (FLAGSHIP_PAYMENTS.md §2.1).
`law_booking_statuses()` is now `publish` (Confirmed), `law-applied`
(Pending approval), `law-waitlisted`, `law-payment-failed`, `law-declined` and
`law-cancelled`. The last three belong to the flagship's application flow and
are kept out of the hosted-event vocabulary by
`law_flagship_application_statuses()`, so a host's bookings list never offers
a state it cannot reach. `law_booking_custom_statuses()` is the single list
`law_events_register_statuses()` registers from and the untrash whitelist
reads, so the two cannot drift — and registration is not optional, because
WP_Query silently drops an unknown `post_status` and then returns every
booking of every status.

**A confirmed flagship place is plain `publish`.** There is deliberately no
"paid" status: `publish` already means Confirmed on a booking, and a second
word for the same state would have to be kept in step everywhere the first one
is read.

### `meta.php`: the meta schema and the single read/write path

- `law_event_meta_schema()`, `law_speaker_meta_schema()`,
  `law_session_meta_schema()`, `law_booking_meta_schema()`: the ~50 meta keys
  and their types (text, `text_array`, `int_array`, `address`, `people_rows`,
  `speaker_rows`, `consent`, `stripe_error`, etc.). Every key
  is registered via `register_post_meta` in `law_events_register_meta()` (on
  `init` priority 7) with a per-type sanitiser and an auth callback. Bookings
  phase 1 added `_law_tickets_sold` (the recalculated seat counter) and
  `_law_capacity_warned` (the nearly-full latch) to the event schema, joined on
  10 September 2026 by `_law_capacity_full_warned` (the sold-out latch). Bookings carry `_law_booking_number`, `_law_booked_by`, the four
  `_law_attendee_*` snapshot keys, `_law_is_press` and the four
  `_law_waitlist_*` keys (WAITLIST.md §A7, §B1). The old `_law_attendee_rows`
  array, its `attendee_rows` sanitiser and the flat `_law_booking_attendee`
  index were removed with the per-attendee rebuild. **`_law_ticket_type`**
  (15 September 2026) is the committee's own classification of a flagship
  registration, typed `ticket_type` and validated against
  `law_booking_ticket_types()` (`statuses.php`) the way `fee_tier` is validated
  against the settings: anything else sanitises to `''`, which deletes the key,
  so "not set" is the absence of a value rather than a sixth vocabulary item
  and a defaulted "Delegate" can never make the column look filled in.
- `law_events_sanitize_value()`: the one sanitiser, switched on type. The row
  types (`people_rows` for co-owners/contacts, `speaker_rows` for the
  event→speaker relationship) clean each subfield and drop empty rows — so the
  forms hand raw POST arrays straight in and the schema is the single cleaning
  path. A speaker row's `role` goes through `law_speaker_role_key()`
  (speakers.php): only `speaker` / `host` / `moderator` are ever stored, a
  label or any case is normalised to the key, and anything else becomes `''`
  (unset, which reads as Speaker).
- `law_events_address_parts()`: the ordered invoice-address keys (line1, line2,
  city, state, postal_code, country) — one source shared by the sanitiser, the
  admin screen and the migrator.
- `law_events_all_meta_schemas()`: the three schemas merged once per request
  (memoised — this is the hot path; `law_event_meta()` reads run into the
  hundreds on a programme render).
- `law_event_update_meta()` / `law_event_meta()`: **the single write/read
  path** shared by the forms, the admin screens and the migrator. Writing an
  empty value deletes the meta; reads return schema-shaped fallbacks for array
  types.
- `law_events_event_reference()` and `law_events_ensure_reference()`: the
  event reference. Since **14 September 2026** it is a plain number, the one
  the committee already works from: a **migrated** event's reference is the
  **Gravity Forms entry ID** it came from (entry 190 on form 2, Event > submit
  an event, for "Coming Soon to an Arbitration Near You…"), and an event
  **created here** takes its own **post ID**. `law_events_ensure_reference()`
  writes it on the first save of any `law_event`
  (`save_post_law_event`, priority 20) and never overwrites an existing value,
  so a migrated entry ID survives every later save; the workflow's `submit`
  side effect calls it again so a submission email can never print an empty
  `{law_reference}`. Before that decision references came from GP Unique ID
  (field 70, Unique ID) in the `LAW26-00212` format, generated in code by
  `law_events_next_reference()` off a counter seeded from `wp_gpui_sequence`
  at migration; that function and its seeding are **retained but no longer
  called**, so the old sequence could be resumed. The events migrated under
  the old rule are brought into line by the Migration screen's
  `repair-references.php` panel.
- `law_events_bump_counter( $option )`: the shared counter helper (get →
  increment → `update_option`, **not atomic** — two genuinely simultaneous
  callers could mint the same number; known, accepted at current volumes).
  `law_bookings_next_number()` (bookings.php) uses the same helper for the
  `law_bookings_counter` option behind "Booking #N", and
  `law_bookings_next_numbers( $n )` passes `$by = $n` to claim a consecutive
  block for one party in a single atomic step.

### `rich-text.php`: the WYSIWYG editor behind the descriptive fields (9 September 2026)

The three descriptive fields hosts write — the event description, each
speaker's biography and each session's description — are edited in WordPress
core's own TinyMCE and stored as HTML. The committee edits the same values in
two more places (Manage Speakers, and the speaker rows on the wp-admin event
screen), which get the same editor so a committee correction cannot flatten a
host's formatting.

- `law_rich_text_allowed_html()`: the allowlist — `p`, `br`, `strong`/`b`,
  `em`/`i`, `ul`/`ol`/`li`, `h3`, `h4`, `blockquote` and `a[href|title|target|rel]`.
  Deliberately narrower than `wp_kses_post()`: a host may emphasise and
  structure a description, not embed media, tables or layout that would break
  the event page.
- `law_rich_text_sanitize()`: the single write path, used by the front-end
  form saver, the meta schema's `speaker_rows` sanitiser, the speakers
  dashboard and the wp-admin repeater. It normalises non-breaking spaces
  first (below), then drops `<script>`/`<style>` blocks **contents and all**
  — `wp_kses()` removes only the tags and would leave the code behind as
  visible text — and returns `''` for an emptied editor, which posts
  `<p>&nbsp;</p>` rather than an empty string.
- `law_rich_text_normalise_spaces()` (17 September 2026): every non-breaking
  space becomes an ordinary one. A host pasting from Word, Google Docs or a
  PDF brings the source's spaces with them, and those applications emit U+00A0
  where a reader sees a space; a browser never breaks a line at one, so a
  sentence whose spaces are all non-breaking is a single unbreakable word and
  runs out of its column instead of wrapping. That is what happened to the
  event "Hot Topics in Energy and Mining Arbitration" (Denis, 17 September
  2026), whose second paragraph arrived with twenty-one of them and printed
  off the right-hand edge of the page. **All of them go, not just the runs**:
  the deliberate use (holding "10 am" together across a line break) is real
  typography, but no LAW description has wanted it, neither editor offers a
  way to type one on purpose, and telling intent from paste damage is
  guesswork — losing a soft join is the smaller harm. Both spellings are
  covered, the raw bytes a paste carries and the entity TinyMCE writes back
  (decimal and hexadecimal), and the match runs in byte mode so a value that
  is not valid UTF-8 is still processed. Because it sits in the single write
  path it covers event descriptions, session descriptions, speaker biographies
  and the per-event email bodies alike. Values stored before it existed are
  swept by `migration/repair-nbsp.php`. `.law-cal-detail__body` (and the
  dashboard's prose panels) also carry `overflow-wrap: break-word` as a
  backstop, so anything that ever slips past wraps inside the reading measure
  rather than off the page. Covered by `tests/RichTextTest.php`.
- `law_rich_text_is_empty()`, `law_rich_text_plain()`, `law_rich_text_render()`:
  the read side. `law_rich_text_plain()` is what the places that cannot take
  markup use (the calendar excerpt and keyword index, the `.ics` description,
  the notification summaries, the speakers export); it turns block boundaries
  into line breaks before stripping tags, because `wp_strip_all_tags()` alone
  runs `<li>One</li><li>Two</li>` together as `OneTwo`.
- `law_rich_text_field()`: prints the textarea the editor attaches to.
- `law_rich_text_enqueue()`, `law_rich_text_settings()`: `wp_enqueue_editor()`
  plus `assets/js/law-rich-text.js`, `assets/css/rich-text.css` and the
  in-iframe `assets/css/rich-text-content.css`. Loaded only on the screens that
  render a field: the host form, the committee dashboard's `?law_edit=1` view,
  `?law_speaker=<id>` on the speakers dashboard, and the wp-admin module
  screens.

Three decisions worth knowing:

- **`wp.editor.initialize()`, not `wp_editor()`.** The speaker and session rows
  are cloned in the browser, so an editor has to be attachable to a textarea
  that did not exist when the page rendered; `event-form.js` and `law-admin.js`
  call `window.lawRichText.init()` on a new row and `.remove()` before deleting
  one. A textarea no JavaScript reaches stays a working plain textarea, which
  is the no-JS fallback the rest of the module already assumes.
- **wpautop stays ON.** The editor runs `wp.editor.autop()` over the stored
  value on load and `wp.editor.removep()` over it on save, so the plain-text
  descriptions already in the database keep their line breaks and new content
  is stored the way the classic editor has always stored it — blank lines
  between paragraphs, tags only where the author added formatting. Every render
  path already ran `wpautop()`, so nothing downstream had to change.
- **No `required` attribute.** TinyMCE hides the textarea, and a browser
  refuses to submit a form holding an invalid control it cannot focus (it fails
  silently with "not focusable" in the console). `law-rich-text.js` prints the
  same `.law-form-error` message inline instead, honouring `formnovalidate` on
  "Save draft" the way the server's own draft path does, and
  `law_events_form_save()` validates the field regardless.

**Added 9 September 2026 for the flagship conference** (`flagship.php` below):
`_law_is_flagship` (flag), `_law_flagship_date` (the new `date` type: `Y-m-d`
validated with `checkdate()`, else `''`) and `_law_hero_image_id` (int, the
banner/preview photograph). The `time` sanitiser now **zero-pads** on the way
in, so `9:30` is stored as `09:30`: the flagship's derived start and end and
`law_event_session_ids()` both compare these strings, and `9:30` sorts after
`14:00`. It also refuses an out-of-range hour or minute, which the old regex
accepted.

### `countries.php`: country name → ISO 3166-1 alpha-2

- `law_events_country_to_iso()`, `law_events_country_map()`,
  `law_events_norm_country()`: the ~460-name map (official names, common names,
  colloquial spellings — "UK", "England", "Holland") and the converter. Stripe
  requires `address[country]` as an alpha-2 code, while the forms collect a
  country name; the result is stored as `_law_country_iso` and sent in the
  customer payload (`stripe/service.php`). An unmapped name returns '' (and is
  error-logged), never a guess — an empty ISO is filtered out of the Stripe
  payload, so the invoice just carries no country line.
- Written on both save paths: the host form always derives it from the posted
  billing country (a select constrained to the registration country list), and
  the wp-admin event screen re-derives it from the country name on every save —
  a mapped name **always wins over the Country ISO box**, so a stale manual
  value cannot outlive a changed country; the box is only a fallback for a name
  the map does not know.
- Every country field in the module is the same control: a select over
  `law_registration_country_choices()` opening on a **"Select country"**
  placeholder (registration, profile edit, the host/committee billing country
  and the wp-admin invoice contact box, 9 September 2026), with a free-text
  input as the fallback when the choice list is unavailable. The admin box
  keeps a stored off-list country as its own option, so a migrated event
  cannot lose its country by being re-saved.
- History: this map is a copy of the one in the mu-plugin
  `law-gf-country-iso.php`, which fills form 2 (Event > submit an event)
  field 88 (Country ISO) from the country part of field 74 (Address) for the
  legacy Make → Stripe path. The module no longer calls that mu-plugin
  (deliberately left untouched), so the map is duplicated until cutover: an
  unmapped spelling gets added **here**, and the mu-plugin is deleted wholesale
  with the rest of the legacy stack, without breaking the module's ISO
  derivation.

### `capabilities.php`: roles and per-event access

- `law_events_capability_names()`, `law_events_grant_capabilities()` (on `init`
  priority 20, once per caps version): grants the full `law_event`/`law_events`
  capability set to `administrator`, `editor` and `events_committee` only, plus
  `upload_files` to the committee for speaker photos. **There are no host-side
  roles** since 14 September 2026: every self-service account is a plain
  `subscriber`, so nobody but the committee, editors and administrators reaches
  the wp-admin event screens. All host access runs through
  `law_user_can_manage_event()` (authorship and the co-owner meta), and the
  front-end form writes posts and meta through functions that do not check
  caps. What the old role checkboxes said about somebody survives as `law_intent`
  user meta, which chooses a welcome email and a HubSpot tag and gates nothing
  (`registration.php`); the retired role definitions stay in `wp_user_roles`
  unassigned, so a rollback is a code revert. See ROLES_AND_ACCOUNT_HUB.md.
- `law_user_can_manage_event()`: the per-event gate — the author, any co-owner
  (`_law_co_owner_ids`), or a committee user. Used by every host-facing handler
  and the edit form. It is a flat OR, so a caller cannot tell host from
  committee; where that distinction matters the handler checks a second,
  narrower predicate on top (see `law_booking_user_can_reject()` below and the
  workflow's `who` key).
- `law_user_is_committee()`: wraps the `edit_others_law_events` check; the
  canonical "who is committee" test.
- `law_booking_user_can_reject()` (`bookings.php`): the one booking capability
  the committee has and a host does not — cancelling somebody else's booking,
  which includes removing a waitlist entry. It is
  `law_user_is_committee() && law_user_can_manage_event()`, so the per-event
  gate still applies; a committee user is not special-cased past it.
- `law_events_sanitize_assignee()`: only a genuinely committee-capable user ID
  may be stored as an assignee (a bad ID would otherwise receive committee
  emails).
- `law_events_committee_users()`: the assignee dropdown source.

### `fees.php`: pricing

- `law_event_tier_amount()`, `law_event_tier_label()`: the configured fee tiers
  (UK office / international / sponsor), read from settings — not price
  literals, so a price change can't silently break VAT the way the legacy GPAC
  field 85 (VAT) literal match did.
- `law_event_calculate_fee_pence()`: the fee for an event, honouring a
  committee override.
- `law_event_calculate_vat()`: the 1/0 VAT flag (VAT applies to any positive
  fee).
- `law_events_vat_rate()`: the 20% rate, filterable — the single source the
  webhook amount-reconciliation uses instead of a `* 1.2` literal.
- `law_event_snapshot_fee()`: freezes `_law_fee_pence` and `_law_vat` onto the
  event at approval (the number the invoice is raised for; never host-writable
  afterwards).
- `law_event_fee_settled()`: true once the payment status is `paid` or
  `refunded`. The money has moved, so the snapshot is a bookkeeping record and
  the webhook's `invoice.paid` reconciliation is argued from it.
- `law_event_fee_edit_mode()` (17 September 2026, replacing
  `law_event_fee_override_locked()`): the ONE answer to "what does the host fee
  override do on this event right now", read by the committee dashboard panel,
  the wp-admin fee box and both save handlers, so a control and the handler
  behind it can never disagree. Three answers:
  - `open` — before approval. Nothing is snapshotted and no invoice exists, so
    the override is an ordinary field.
  - `reissue` — `law-approved` or `publish`, and not settled. The fee WAS
    snapshotted at approval and an invoice raised from it, so a change goes
    through `law_event_apply_fee_change()` rather than being a field write.
  - `locked` — settled (`paid` / `refunded`), or the event is no longer live
    (cancelled, rejected). Nothing to reissue and nothing to change.

  It reads the STATUS, not the `_law_approved_at` timestamp its predecessor
  read: field 78 (Approval date) on form 2 (Event > submit an event) is empty
  on every production entry, so the lock was off across the whole migrated
  programme (audit, 16 September 2026).
- `law_event_resnapshot_fee()` (9 September 2026): re-freezes the snapshot on
  an approved event, logged as `fee_resnapshot`. It returns a `WP_Error`
  (`law_fee_settled`) when the payment status is already `paid` or `refunded`.
  It re-freezes the NUMBER and nothing else; voiding and reissuing are
  `law_event_apply_fee_change()`'s job, and that is now its only caller on a
  live event.
- `law_events_format_pence()`: "£1,200.00" formatting.

`law_event_apply_fee_change()` (`stripe/service.php`, 17 September 2026) is
the post-approval fee change itself, shared by the committee dashboard panel
and the wp-admin fee box so the two screens do the same thing. It voids the
invoice raised from the old snapshot, re-freezes the snapshot, and raises and
sends a replacement invoice, then emails the host `user_payment_due` with
`{fee_change_note}` naming the invoice that was cancelled. **Void first, create
second**: the other order leaves the host holding two payable invoices for the
same event, permanently if the second call fails, whereas voiding first can
only ever leave them holding none — a visible state (Unpaid, no invoice URL)
that the existing "Retry invoice" button recovers. It refuses, writing nothing,
when the mode is not `reissue`, when the event holds a pre-rebuild invoice
recorded as a web address only (no ID to void it by; LAW → Migration has the
repair), and when the void itself fails. A fee changed to **zero** raises
nothing: the event goes Free, and an Approved event is confirmed in the same
breath, exactly as approving it at zero would have done. A fee put back onto a
Free event takes the payment status out of Free, or the dashboard and the
exports would read Free against a live invoice.

**The override is the HOST fee**, what a host firm pays LAW to hold an event,
never an attendee price: attendee places are free and un-ticketed
(EVENTS_BOOKINGS.md §1). The control is labelled "Override the host fee" and
its amount "New host fee (£)" on both the dashboard and wp-admin. A ticked box
with an **empty** amount used to sanitise to £0.00, which waives the fee, skips
the invoice and auto-confirms the event; both save paths now refuse it (a
deliberately typed 0 still waives the fee, and the help text says so).

### `log.php`: the WooCommerce-order-notes-style activity log

- `law_event_log()`: appends one immutable log line to an event as a WordPress
  comment of type `law_event_log`, with a structured context array (action,
  source, actor, and per-event extras). Every status change, email sent (with
  recipients), payment change, slot change, assignee change, fee-override
  change, linked-organisation change (`action => organisations`, with the old
  and new ID arrays in the context and the names in the message), private note
  and co-owner action is logged.
- `law_event_log_entries()`, `law_event_log_context()`: read the log; the
  context is stored as comment meta.
- Two filters exclude the `law_event_log` (and the `law_event_comment` thread
  type) from public comment queries and feeds, so log lines and the private
  committee thread never surface anywhere public.

### `request.php`: shared handler plumbing (bookings phase 1)

- `law_events_redirect_back()` and `law_events_rate_limit_ok()` moved here
  from comments.php (a pure move — they were always module-wide helpers).
- `law_events_guard_post( $nonce_action, $args )`: the guard sequence every
  NEW admin-post handler starts with — the AJAX-nonce-JSON-403 before
  `check_admin_referer`, the `law_website_url` honeypot (pretend success, the
  payload supplied per handler), and the rate limit — returning `$is_ajax`.
- `law_events_respond( $is_ajax, $ok, $payload, $notice )`: the JSON-versus-
  redirect-with-notice tail; `law_events_nopriv_json()`: the named signed-out
  answer for `admin_post_nopriv_*` registrations.
- The six older handlers (comments, withdraw, committee, export, submission,
  registration) predate these and still carry the sequence inline; they
  migrate opportunistically.

### `workflow.php`: the state machine

- `law_event_workflow_actions()`: the transition table — `submit`
  (draft → proposed, owner), `resubmit` (sent-back → proposed, owner),
  `approve` (proposed/sent-back → approved, committee), `send_back`
  (proposed → sent-back, committee), `reject` (proposed/sent-back → rejected,
  committee), `confirm` (approved → publish, system), `mark_paid`
  (approved → publish, committee), `cancel` (approved/confirmed → cancelled,
  committee, reason required) and `withdraw` (draft/proposed/sent-back →
  cancelled, owner, reason optional — approved+ events go through the
  committee's cancel instead, because money and programme slots are involved
  by then).
- **The status guard** (a `wp_insert_post_data` filter): an *existing*
  `law_event`'s status can only change through
  `law_event_workflow_transition()`. This is what stops the classic editor's
  Publish / Save Draft from confirming an unapproved event or parking it in a
  core status no dashboard shows. New inserts (form, migration, tests) pass
  through untouched. A second filter keeps the custom statuses out of the
  quick-edit dropdown. **The guard also covers `law_booking`** (its own flag,
  `law_booking_transitioning`, raised only by `law_booking_set_status()`), so
  quick edit can neither resurrect a cancelled booking nor seat a waitlisted
  one; the `wp_untrash_post_status` filter likewise restores a booking to its
  pre-trash status, constrained to the keys of `law_booking_statuses()`
  (anything else restores as cancelled, the safe side).
- `law_event_ui_actions()`: the five actions the two committee UIs offer
  (approve, send_back, reject, mark_paid, cancel), the shared list both the
  dashboard handler and the wp-admin event screen check a posted action
  against. `withdraw` is deliberately NOT in this list: it is a host action
  with its own handler, and this list is what renders committee buttons.
- `law_event_available_ui_actions( $event )`: the subset of those five that is
  legal for the event's *current* status, read off the same from-lists the
  transition guard enforces. Both committee UIs (the dashboard detail view and
  the wp-admin Workflow box) render only these buttons/radios and their modals,
  so nobody is offered an action that would only bounce with "Cannot … an event
  that is Approved". Proposed offers Approve / Send back / Reject; Sent back
  offers Approve / Reject; Approved offers Mark paid & confirm / Cancel;
  Confirmed offers Cancel; Rejected and Cancelled offer none (the dashboard
  still shows Save changes, and offers Delete — not a workflow action, see
  `committee.php` below).
- `law_event_workflow_transition()`: the single entry point. Validates the
  from-state and the actor's authority (`who`), flips the status, logs it, and
  fires side effects. **A `who => 'system'` action (`confirm`) is machine-only**:
  it completes only when `source` is `stripe_webhook`, `migration` or `system`,
  never for a logged-in human, committee or not. Guarded against re-entrancy so a transition that calls
  `wp_update_post` doesn't recurse.
- `law_event_workflow_side_effects()`: on approve — snapshot the fee, create
  co-owner accounts, then either raise the Stripe invoice (paid) or confirm
  immediately (free); email the committee. On confirm — publish and email host
  + committee. On send back and on reject — post the committee's message to the
  event thread via `law_event_add_comment()`, then send `user_sent_back` /
  `user_rejected`. (Reject used only to store `_law_rejection_reason` and email
  it, so the host saw the reason in their inbox but not on their dashboard.)
  Order matters on both: the comment and the reason meta are written *before*
  the send, because `{latest_comment}` and `{rejection_reason}` are built from
  them.
  On cancel — the same reason-first ordering (`_law_cancellation_reason` +
  thread comment), then `law_stripe_void_invoice()` **before** the
  `user_cancelled` email so its "no payment is due" wording is true when read
  (a failed void alerts and never blocks), and a `committee_cancelled_paid`
  manual-refund alert when the payment status is already `paid` — a paid fee
  is never refunded automatically. On withdraw — optional reason to the same
  meta key and the thread, then `committee_withdrawn`, skipped when the event
  was withdrawn from `law-draft` (the committee never saw it; the old status
  is passed into the side effects for exactly this check).
- `law_event_handle_withdraw()` (on `admin_post_law_event_withdraw`): the host
  Withdraw handler, mirroring the comment-reply handler — nonce (verified with
  `wp_verify_nonce` first on the AJAX path so a stale nonce gets JSON, not an
  HTML die), honeypot, `law_user_can_manage_event()`, rate limit
  (`'withdraw'` surface), then the `withdraw` transition. With `law_ajax=1` it
  answers JSON on every path (event-form.js posts it); without, the classic
  redirect-with-notice flow (`event-withdrawn` / `withdraw-failed`).
- `law_event_confirm_side_effects()`, `law_event_set_payment_status()`,
  `law_event_log_fee_change()`, `law_event_log_organisation_change()`,
  `law_event_maybe_notify_assignee()`: the confirm/publish path, the logged
  payment-status setter, fee-override and linked-organisation logging, and the
  assignee-change email. Both loggers take the value read immediately **before**
  the write and return early when nothing changed — the wp-admin screen writes
  those metas on every save, so the before/after guard is the only thing
  keeping the log free of no-op lines.

### `comments.php`: the host/committee thread

- `law_event_add_comment()`, `law_event_comments()`, `law_event_latest_comment()`,
  `law_event_comment_is_committee()`: the thread is WordPress comments of type
  `law_event_comment` on the event, distinct from the `law_event_log` lines.
- `law_event_handle_comment_reply()` (on `admin_post_law_event_comment_reply`):
  the host reply/resubmit handler — nonce, honeypot, per-user rate limit, and
  `law_user_can_manage_event()` before it posts and transitions the event back
  to the committee. The `nopriv` variant redirects to login. The front-end
  form posts it via fetch with `law_ajax=1` (event-form.js), and the handler
  then answers JSON — the new bubble rendered through
  `parts/events/thread-bubble.php`, the (possibly resubmit-changed) status
  label and the matching button label — instead of redirecting; without the
  flag the classic redirect-with-notice flow is unchanged, so no-JS
  submissions still work.
- `law_events_redirect_back()` and `law_events_rate_limit_ok()` (the shared
  redirect-with-notice helper and the per-IP/per-user rate limiter, keyed on
  `REMOTE_ADDR` so `X-Forwarded-For` spoofing does nothing) **moved to
  `request.php`** in bookings phase 1.

### `unread.php`: unread-message toasts for hosts

- `law_event_thread_mark_read()` / `law_event_thread_read_key()`: a per-user,
  per-event user-meta unix timestamp, written on `template_redirect` when the
  host opens the messages page (`/account/events/?law_thread=<id>`, permission
  re-checked). Marking runs before `wp_footer`, so the thread being viewed
  never toasts on the same page view.
- `law_event_unread_threads()` (memoised): events with committee-authored
  thread comments newer than both the read marker and a 30-day floor
  (`LAW_THREAD_UNREAD_MAX_DAYS`, so the migration's imported comment history
  does not nag every host on day one). Hosts only — empty for committee users;
  a host's own or a co-owner's replies never count.
- A `wp_footer` renderer prints one toast per event (bottom right, stacked,
  the whole toast links to that event's thread) and `wp_enqueue_scripts` loads
  `assets/css/law-toasts.css` / `assets/js/law-toasts.js` only when there is
  something to show. Dismissal is sessionStorage-keyed on event ID + newest
  comment ID, so a genuinely new message resurfaces a dismissed toast.

### `co-owners.php`: additional owners as real accounts

- `law_event_ensure_co_owner_users()`: on approval (and on host/admin edits of
  an already-approved event), turns each co-owner row into access — creating a
  subscriber account for a new email, or linking an existing account. Access
  comes from the `_law_co_owner` meta row, never from the account's role. Every
  action is logged.
- `law_event_notify_co_owner_linked()` and
  `law_event_notify_co_owner_created()`: the two additional-host emails, both
  sent through the module's registry (`user_co_owner_linked` /
  `user_co_owner_created`) so the copy is editable on the Emails screen and every
  send lands in the event's activity log. An *existing* account is told it has
  been linked, so access is never granted silently; a *new* account gets the
  welcome with a branded `/login/?action=reset` set-password link minted here
  via `get_password_reset_key()`, plus `{forgot_link}` because the key expires
  after 24 hours.
- `law_event_set_co_owner_ids()`: the single write path for `_law_co_owner_ids`
  (the array the module reads) plus one flat `_law_co_owner` meta row per ID
  (what the dashboard's owned-events query matches).
- `law_events_create_host_user( $email, $name, $organisation, $args )`:
  creates the account (username = email, random password; `$args` carries
  `role`, **default `subscriber` since 14 September 2026**, and an optional
  `job_title`) and sends **nothing** — the welcome is the caller's job so it
  can carry the event's context. Every account the module creates is a plain
  subscriber now, co-owners and booked-in colleagues alike; the name is a
  misnomer kept for its three callers. The `role` arg is **whitelisted against
  `law_events_creatable_roles()`** (a list of one) and anything else falls back
  to subscriber: this function mints accounts from an email address somebody
  typed into a form, so a future caller passing user input must not be able to
  turn it into an escalation path (security review, 14 September 2026). No
  caller passes a role today.
  `law_events_password_setup_link( $user, $event_id, $log_action )` mints the
  branded set-password link (with the forgot-password fallback and failure
  log), shared by the co-owner welcome and the bookings invite. It deliberately does
  not use core's new-user notification: BNFW (Better Notifications for WP)
  overrides the pluggable `wp_new_user_notification()` and its user branch
  never applies the `wp_new_user_notification_email` filter, so the branded
  host welcome in `mu-plugins/law-secondary-host-users.php` never fires and
  BNFW's unbranded "[site] Your username and password info" fallback goes out
  instead. That mu-plugin filter is dead code for as long as BNFW is active.
  Note that `auth.php` only rebrands the *forgot-password* email
  (`retrieve_password_message`); a `wp-login.php?action=rp` link is rescued at
  click time by the `login_init` redirect, not rewritten in the message.
- `law_events_owned_event_ids()`: events a user owns or co-owns, for the host
  dashboard.

### `bookings.php`: the bookings engine (WAITLIST.md Part A is the current contract; EVENTS_BOOKINGS.md is the original)

**One booking per attendee.** A booking is a `law_booking` post whose
**author is the attendee**, with `post_parent` the event, its own
`_law_booking_number`, and the person's details as scalar snapshot meta
(`_law_attendee_name` / `_email` / `_organisation` / `_job_title`, plus the
committee-only `_law_is_press`). `_law_booked_by` is always set: on a
colleague's booking it is whoever brought them, and on a self-booking (or a
registration made on someone's behalf) it equals the author, which is what
"self-booked" means. Statuses are `publish`, `law-waitlisted`,
`law-pending-payment` (a place held while somebody pays, 14 September 2026) and
`law-cancelled`, plus the flagship's `law-applied`, `law-payment-failed` and
`law-declined`.

**Since 14 September 2026 this file also holds everything a PRICED booking
needs**, extracted out of `flagship-bookings.php` when the receptions became
the second priced flow (RECEPTIONS.md §1.5), with the old names left as
one-line wrappers so that file still reads in its own vocabulary and its
suites did not move:

- `law_booking_kind()` — `flagship | reception | hosted`, from the parent
  event. The ONE place the question is answered, so the webhook's handler
  table, the manage view and the payment columns cannot come to three
  different conclusions about the same post.
- `law_event_price_pence()`, `law_event_is_priced()`,
  `law_event_is_invitation_only()`. The first delegates to the flagship for
  the conference, whose price is time-switched between two stored figures
  rather than a single int.
- `law_booking_guard_open()` **split in two.** It stays the event-live test —
  published, CPT source, capacity set, not started, not the flagship — which
  is what the waitlist's internals and the untrash hook ask, because a queue
  must keep promoting on a priced reception exactly as it does on a free
  event. The new `law_booking_guard_form_open()` adds the two refusals that
  are about how a place is OBTAINED: invitation only, and priced. Every form,
  "add a colleague", register-on-behalf and the dialog server call that one.
  Hiding a button is not a control; this is. The committee's own
  register-on-behalf passes `allow_priced` and writes a complimentary place.
- `law_booking_profile_gaps()`, `law_booking_person_from_profile()`,
  `law_booking_insert()` (three drifting copies before this),
  `law_booking_claim_latch()`, `law_booking_claim_charge()` / `_release_()`,
  `law_booking_guard_price_shown()`, `law_booking_log_amount_mismatch()`,
  `law_booking_is_configuration_error()`, `law_booking_payment_deadline_ts()`,
  `law_booking_email_extra()`, `law_booking_payment_states()` and
  `law_event_ensure_managed_post()`.
- `law_booking_payment_handlers()` / `law_booking_dispatch_payment()`: the
  handler table `stripe/webhook.php` routes through.
- The **clash guard exempts** the receptions and the flagship on both sides of
  the pair: the week is meant to be a session and then a drink, and the
  exemption has to live in the guard because the waitlist's promotion check
  calls it.
- `law_event_recount_attendees()` counts `law-pending-payment` **only when the
  event is priced**, so a hosted event's and the flagship's counts stay
  byte-identical while a reception cannot sell its last place twice.
- `law_booking_cancel()` refuses the `self` and `booker` contexts on a PAID
  place (refunds are manual, and self-service cancellation would free the
  place while leaving the money with LAW and nobody told), gives a discount
  code's use back unless it was paid for, detaches a saved payment method on
  the way out, and alerts the committee when it cancels a paid place itself.

A booker's **party** on an event is derived, never stored:
`law_booking_party()` unions the bookings they author with the ones they
booked for other people. That is what the manage view, the colleague cap and
"cancel everything I booked" all read. (Before 8 September 2026 a party was
ONE booking carrying an attendee rows array; that model, its flat
`_law_booking_attendee` index and the `attendee_rows` sanitiser are gone.)

- **Front-end surfaces**: `functions/account-bookings.php` (`law_booking_state()`,
  the booking control, `law_account_bookings()` grouped per event, the shared notice map
  `law_booking_notice_text()` / `law_booking_notice_render()`, the counts label,
  the form-state transients, the enqueues). The control was six states until
  14 September 2026; the receptions added six more, resolved in the same one
  place so the event page, the programme card and the foot-of-page buttons
  cannot come to different conclusions about the same event: **`invitation`**
  (outranks everything but the flagship — nothing about the viewer changes what
  this event is, so even somebody who already holds a place sees it),
  **`pending-payment`**, **`payment-failed`**, **`waitlist-needs-card`**,
  **`included`** (offered AHEAD of the paid route, because paying for something
  you have already been given is the worst outcome the page could produce) and
  **`buy` / `buy-full`**. The notice map gained a
  `law_booking_notice_text` filter, so a flow with outcomes of its own
  registers them rather than stacking a third notice renderer on My bookings. Since 10 September 2026 the two
  account sub-views sit on **different pages**: the attendee's `?law_booking=`
  manage view on `/account/bookings/`, the host's `?law_event_bookings=` list on
  `/account/events/`, and the enqueue closure branches on the template
  accordingly so pdfmake (~3MB) is still only ever served with the host list.
  **Every booking dialog on the site is fetched on the press since
  11 September 2026** (EVENTS_BOOKINGS.md §7.1a). Event cards gained a booking
  button, and the single event view's own opener moved onto the same flow, so
  neither page server-renders `law-booking-modal` / `law-booking-success` any
  more: `law_booking_state()` is the one state decision
  the control and the cards both read, `law_booking_card_action()` turns it into
  a card action — with `law_booking_card_inert_action()` supplying the disabled
  one for the states that offer nothing to press, so every card carries two
  buttons (see the change-history entry of 15 September 2026) — and
  `law_booking_maybe_render_dialog()` serves one event's
  dialog as an HTML fragment at `{permalink}?law_dialog=1` — the cards carry no
  dialog markup at all, because a programme page renders the whole week and the
  dialog's wrapper id is fixed. `law_booking_render_loading_modal()` puts one
  placeholder dialog (`parts/events/booking-loading-modal.php`) in the footer,
  which the button opens at the moment of the press so the round trip is never
  silent. The flagship's block on the programme
  (`parts/events/flagship-card.php`) carries the same treatment through
  `law_flagship_action_state()` / `law_flagship_action_link()` /
  `law_flagship_card_action()` in `functions/account-flagship.php`: Register, or
  whatever the viewer's own registration offers instead, with the registration dialog
  served by `law_booking_render_flagship_dialog()` behind
  `law_flagship_guard_open()`. Since 15 September 2026 the ordinary card
  partial routes the flagship to that same action, so the conference can be
  registered for from every list it appears in as a plain row — a speaker
  profile's "Speaking at", My bookings, My events — and the action takes the
  same `$scope` argument as the hosted one. `law_booking_render_action_buttons()` repeats the
  button (and only the button) at the foot of the single event page, after
  "Back to programme", routing the flagship to
  `law_flagship_render_action_buttons()`. `law_booking_user_bookings_by_event()`
  (`bookings.php`) answers "does this viewer hold a place here" for every card
  on the page in one pair of queries.
  `parts/events/booking-modal.php` /
  `attendee-repeater.php` (a `mode` arg switches the same form between booking
  and joining the waitlist; the dialog opens on the shared `.law-event-summary`
  block (the event's title and date/time, then the places-left and colleague
  allowance sentence on a ruled row of its own), which the flagship application
  dialog uses for its title, date/time and price, one block for both so they
  cannot drift, Denis, 11 September 2026) / `booking-manage.php` (`?law_booking=` on
  **My bookings**; the addressed booking resolves the event, and the view then
  shows the viewer's whole party there, with per-row cancel, add-a-colleague and
  cancel-all behind confirm modals) / `booking-list.php` (`?law_event_bookings=`
  on **My events**,
  `law_user_can_manage_event()`; a FLAT table, one row per booking, which is one
  row per attendee — no grouping, just an "Invited by {name}" tag on a
  colleague's row (Denis, 8 September 2026) — with live country, dietary and
  accessibility from `law_profile_values()`, the committee-only per-attendee
  Cancel control, the waitlist
  section, and the CSV/Excel/PDF export trio). **"Register an attendee" is a
  button above the table opening a dialog** (Denis, 11 September 2026), not a
  form sitting open at the foot of the page, the way "Add an attendee without
  payment" works on the flagship bookings dashboard; one partial serves both
  audiences, so a host and the committee get the same dialog. The opener ships
  `hidden` with `data-law-modal-enhanced` and law-modal.js reveals it, so a
  browser without JavaScript is never shown a button that opens nothing: it
  gets a `<noscript>` disclosure holding exactly the same form, opened already
  when the last submission was refused. The per-attendee cancel button in the
  table reads just **Cancel** (the dialog it opens says "Cancel booking"). The
  dialog collects **almost what the registration form collects** (Denis,
  11 September 2026): the four attendee fields plus country, accessibility and
  dietary, from the shared `parts/events/attendee-profile-fields.php`, because
  the list's own Country / Accessibility / Dietary columns and the exports read
  those live from the attendee's profile and somebody booked in by phone has
  nobody else to fill them in. Country is required here, like the other four.
  The answers are cleaned by `law_registration_clean_attendee_profile()`,
  checked by `law_registration_validate_attendee_profile()` (the registration
  form's rules, with "Other" demanding its free text) and written by
  `law_registration_apply_attendee_profile()` via
  `law_booking_apply_attendee_profile()`, which also logs what it wrote. **A
  new account takes everything given; an account that already existed only has
  its blanks filled**, so a host repeating what they remember of a phone call
  can never overwrite what the person stated themselves. An account that
  already existed and holds anything beyond `subscriber` (an administrator, a
  committee member) is **not written to at all** (security review, 11 September
  2026; the guard also still names the three retired roles, so an account that
  has not yet been through migration step 11 is recognised as an ordinary
  person): the account is found by the email address
  whoever fills the form typed, so without that guard a host could put
  health-adjacent details onto a committee member's account just by knowing
  their address. **Cancelling an attendee's
  booking is committee only since 11 September 2026** (`law_booking_user_can_reject()`;
  Denis, closing the divergence from spec §4.3 that WAITLIST.md recorded as
  settled). A host keeps the waitlist arrows, Promote now, Register an attendee
  and the exports, and simply does not see the Cancel / Remove control:
  it is hidden with nothing in its place, so a host's active table loses its
  actions column altogether and the waitlist table keeps one holding Promote
  now. The consequence is deliberate — a host can seat a waitlisted person, and
  even over-book, but only the committee (or the attendee themselves, from My
  bookings) can take a place back. The waitlist section is the one
  surface that does not reload after its action: the three move arrows post as
  usual, and `booking-form.js` reorders the `<tr>` nodes from the answer's
  `order`, rewriting each row's displayed position, its three
  `expected_position` inputs and the disabled state of its edge arrows, then
  writing the outcome to one shared `[data-law-waitlist-status]` line above the
  table (refusals go there too — a notice inside one of those table-cell forms
  would wreck the row). It falls back to the redirect whenever the payload
  reports a promotion or no longer matches the rows on the page, because those
  change the active and cancelled tables and the counts in their headings.
  The arrows also carry `data-law-booking-busy-quiet`: a single-glyph button in
  a table cell shows its busy state with `aria-busy` and dimming rather than
  swapping in a word, which would stretch the button and shift the row.
- **The availability panel** (11 September 2026). The control's output on the
  single event view is wrapped in a **filled** panel, `law_booking_panel()`,
  with the count at 1.6rem as `.law-booking-panel__count` and its call to
  action stepped up from the theme's 0.8rem base button. The client asked for the
  places count to be "in a box, to create urgency"; the box is always there,
  but only its **colour** escalates, because a bold "80 places left" creates no
  urgency at all, it advertises an empty room. The tone comes from
  `law_booking_tone()`, which `law_booking_state()` adds to every resolution as
  `$state['tone']`: `open` | `low` | `full` | `mine` | `closed`. `low` is
  reached at **`law_event_capacity_warning_at()`**, which until now only drove
  the host's nearly-full email, so the public page and that email cannot
  disagree about what "nearly full" means. `mine` is deliberately neutral: a
  viewer who already holds a place has nothing left to hurry for. The panel is
  **filled, not washed** (Denis, 11 September 2026): a pale tint on the box's
  own `#ececf1` read as a slightly paler patch rather than as the box the
  client asked for, so the neutral state takes the brand navy `#292459` with
  white text and each tone escalates the **fill** rather than the ink, using
  the badge set's own amber and red (`#a35300`, `#a12622`) as backgrounds, with
  `#5a5a7a` for a closed event. No new colours enter the theme, and there is no
  green tone because the theme has none. White on all four is AA
  (12.7:1, 5.6:1, 6.5:1, 5.6:1), and the orange Register button keeps its own
  rectangle against each.
  `law_booking_panel_status()` prints a small uppercase pill ("Booking open" /
  "Almost full") only in the **bookable** state: every other state already opens
  with a `.law-booking-state` sentence saying the same thing, and bookable is
  the one state with no heading, which is why its count read as an afterthought
  before. In that state the count also gains the word "Only" on the `low` tone.
  The pill goes **inside the left slot**, directly above the count (Denis, 11
  September 2026) — `law_booking_panel()` prepends it to the text rather than
  printing it as a child of the panel — because "Almost full" and "Only 3 places
  left" are one statement about availability, and a pill spanning the whole
  panel read as a banner over the button as well.
  The resolution itself moved to `law_booking_resolve_state()` and the panel
  body to `law_booking_render_action_body()`, so the wrapper can buffer the
  whole control while the branches still return early as they always did.
  The flagship takes the same panel through `law_flagship_render_action()`
  (tone from `law_flagship_places_tone()`) and states its count **in the panel**
  too, as the same `.law-booking-panel__count`: it was a Places row up in the
  facts box until 11 September 2026, which left the panel holding nothing but an
  Apply button floating at its left edge (Denis).
  **The panel is built in two slots** (Denis, 11 September 2026, for both
  controls): `.law-booking-panel__main` holds every word, `.law-booking-panel__action`
  holds the thing to press, and the panel's existing `align-items: center` lines
  the one up against the other. Flat, as direct flex items, the words came
  apart: a `.law-booking-state` heading is `flex-basis: 100%` and took a row of
  its own, so "You're attending" and "You're booked on this event." floated above
  the row holding their own explanatory line and the button, and on a full event
  the heading and the line explaining it sat at opposite ends of one row.
  `margin-left: auto` on the action slot pins the button right even in the state
  with nothing beside it, which is the bug that started this: a lone flex item
  under `justify-content: space-between` sits at the **start**.
  `law_booking_panel( $tone, $text, $action, $form, $status )` is the one place
  the slots are built, so the two controls cannot drift into two layouts either;
  both `law_booking_render_action_body()` and `law_flagship_render_action_body()`
  **print** their words and **return** `law_booking_action_parts()`, which is
  what lets a state keep its heading with the line under it while the button
  goes somewhere else in the markup. The action slot is itself a flex row,
  because the colleagues-only state offers **two** buttons ("Manage bookings"
  and "Register"), which now stay together at the right instead of straddling
  the panel with the count between them. The paragraphs' `color: inherit` had to
  stop being a direct-child selector when the wrapper arrived, or every heading
  would go back to near-black on navy — worth knowing before wrapping anything
  else in there. The no-JS form (`?law_book=1`, `?law_waitlist=1`,
  `?law_flagship_apply=1`) goes in **neither** slot: it is a whole form, not a
  button, so it takes its own full-width row below both, routed by
  `law_booking_opener_is_form()` / `law_flagship_opener_is_form()` — the same
  test the openers themselves branch on, so the opener and the panel that places
  its output cannot disagree about which of the two it produced.
  Redirect notices are printed **outside** the panel, above it:
  `.law-form-notice` carries its own light-surface colours and would be
  unreadable on a filled one. `.button.second` (the colleagues-only "Manage
  bookings") is navy on white, so inside the panel it becomes a white outline. The dedicated count class also retired
  `.law-event-details__footer > .law-booking-substate`, a direct-child selector
  that existed only to keep the no-JS inline form's own substate paragraph from
  being sized up with it.
- **Places**: `_law_tickets_sold` is one `COUNT(*)` of the event's published
  bookings (`law_event_recount_attendees()`), run after every mutation;
  `law_event_tickets_remaining()` returns null for "not open" and clamps at 0,
  so a deliberate over-booking shows as 0 remaining and a red count, never a
  negative. Two wp-admin backstop hooks (`transition_post_status`,
  `deleted_post`) recount after a trash, untrash or delete that never touched
  the engine, and now also offer the freed place to the waitlist.
- **Numbers**: `law_bookings_next_numbers( $n )` claims a consecutive block in
  one atomic `law_events_bump_counter( $option, $by )`, so a party booked
  together reads as a block even while another event is booking.
- **Create** `law_booking_create( $event_id, $booker_id, $rows, $args )` returns
  an **array** of booking IDs, the booker's first. It fast-fails on the cheap
  refusals before creating any account, creates the colleagues' accounts (the
  author needs a real user, so this cannot wait until after the lock), then
  under the event lock re-runs every guard, claims the numbers and inserts the
  posts. Anything that refuses mid-way takes the whole submission back: the
  posts created so far are hard-deleted and so are the accounts this request
  created, logged as `booking_create_rolled_back`. Nothing is emailed until the
  lock is released. `$args['status']` is how the waitlist reuses all of it.
- **Attendee rows**: `law_booking_clean_additional_rows()` caps a submission at
  three colleagues and requires **all four** fields on every row it keeps —
  full name, a valid email, organisation and job title (Denis, 9 September
  2026; before that the last two were optional). An account is created from
  the row, so it asks for the same details the registration form does, and the
  bookings list, exports and wp-admin booking screen all print the
  organisation and job title. A completely empty repeater row is still skipped.
  Refusals carry `['row' => index, 'field' => name]` so `booking-form.js` can
  mark the control in place. Every surface that posts `law_attendees` goes
  through it: the booking and waitlist modals, "Add a colleague" on the manage
  view, and the host/committee "Register an attendee" dialog on the bookings
  list, all of which mark the four labels with an asterisk and `aria-required`.
- **Guards**: `law_booking_guard_open()` (Confirmed + CPT source + a ticket
  number + not started), `law_booking_guard_duplicates( $event_id, $people,
  $statuses )` (one place per person per event, matched by account AND by
  email, across active and waitlisted bookings; promotion passes `publish`
  only), the colleague cap (`law_booking_colleague_count()` — three colleagues
  per booker per event, their own booking not counted, so a self-cancel neither
  frees nor consumes a slot), `law_booking_guard_clash()` and
  `law_booking_guard_seats()` (capacity for a booking, "the event must be full"
  for a waitlist entry).
- **Cancel** `law_booking_cancel( $booking_id, $actor, $context, $args )`
  replaces the old remove-attendee: one person's booking, idempotent, with the
  context (`self` / `booker` / `host_reject` / `event_cancelled`) choosing the
  email they get and, for a waitlisted booking, the waitlist wording. The
  `host_reject` token is now a misnomer kept on purpose: it is the identifier
  the email map, the activity-log filters and the tests all key on, so only its
  human wording moved to "cancelled by the committee". It
  releases any queue position, recounts, and offers the freed place to the
  waitlist unless the event itself is going away.
  `law_bookings_cancel_party()` is the booker's "cancel everything I booked".
- **Status writes** go through `law_booking_set_status()`, which saves and
  restores `$GLOBALS['law_booking_transitioning']` rather than clearing it, so
  a nested transition (a promotion inside a sweep) cannot strand its caller.
- **Register on behalf**: `law_booking_register_by_manager()` (unchanged in
  shape) gives the person a booking of their own with `_law_booked_by` set to
  themselves, so a host's own "My bookings" never fills with people they
  registered; who acted is in the activity log and the confirmation email.
- **Emails**: the booker's confirmation lists the whole party with each
  person's number; every colleague gets their own confirmation naming who
  booked them; the host and committee get **one** copy per submission.
  Cancellation has one template per context
  (`user_booking_rejected` / `_cancelled_by_booker` / `_cancelled_self` /
  `user_booking_event_cancelled`).
- **Handlers**, all on `law_events_guard_post()`: `law_booking_create`,
  `law_booking_add_attendee` (posts the EVENT; the engine re-checks the actor
  has a party there), `law_booking_cancel` (the attendee or the person who
  booked them), `law_booking_cancel_party`, `law_booking_reject_attendee`
  (`law_user_can_manage_event()` on `post_parent`, then
  `law_booking_user_can_reject()` — a host gets a 403 saying only the LAW
  committee can cancel a booking) and
  `law_booking_register_attendee` (`law_user_can_manage_event()`; hosts keep
  this one). `law_booking_error_payload()` is the shared
  row/field refusal shape booking-form.js marks in place.
- **The event-cancel sweep** `law_bookings_cancel_all_for_event()` cancels the
  waitlist FIRST and suspends promotion for its duration, because it also runs
  from `wp_trash_post` while the event is still published; otherwise a freed
  place could promote somebody onto an event being deleted seconds later.
- Tests: `tests/BookingsTest.php` (35), `tests/BookingEmailsTest.php` (16),
  `tests/BookingsDashboardTest.php` (6) and `tests/WaitlistTest.php` (18).

### `waitlist.php`: the waitlist (WAITLIST.md Part B)

A waitlist entry is a booking with status `law-waitlisted` and a 1-based
`_law_waitlist_position`. Because a booking is one attendee, an entry is
exactly one place, so promotion is plain first-in-first-out and no party can
block the queue. Joining is refused while places are free.

- `law_waitlist_join()` is `law_booking_create()` with the waitlisted status:
  the same guards, accounts, numbering and all-or-nothing rollback, plus
  consecutive positions. It emails the joiner the whole party, each colleague
  their own entry, and the host once, the first time a queue forms
  (`host_waitlist_activated`, spec §4.4).
- `law_waitlist_process( $event_id, $source )` walks the queue in order while
  places remain, seating each entry that still passes the duplicate and clash
  guards. An entry the guards refuse is **skipped in place**: it keeps its
  position, the log records why every time, and its owner is emailed once
  (`_law_waitlist_blocked` is the latch), so one stuck entry can never freeze
  the queue behind it. The pass is capped at `LAW_WAITLIST_PASS_CAP` (10) and
  `LAW_WAITLIST_PASS_SECONDS` (15) and hands the rest to the
  `law_waitlist_resume` cron event, checking `wp_next_scheduled()` first
  because WordPress silently drops a duplicate schedule. Every email is sent
  after the lock is released; a static per-event flag and
  `$GLOBALS['law_waitlist_suspended']` keep it out of its own re-entry and out
  of the cancel sweep. It is deliberately NOT called from the recount, which
  runs inside other bookers' locks.
- Called from: `law_booking_cancel()` (any context but the sweep), both
  wp-admin backstops, `law_event_tickets_changed()`, reorder and manual
  promote.
- `law_waitlist_promote()` is the host's "Promote now": it bypasses the
  capacity guard only, never the duplicate or clash guards, and logs an
  over-booking loudly (`waitlist_overbooked`).
  `law_waitlist_reorder( $id, $direction, $actor, $expected_position )` moves an
  entry top/up/down; the client posts only a direction enum, positions are
  recomputed server-side, and a stale `expected_position` is a no-op rather
  than a wrong move. It returns `{ moved, promoted }` — the IDs its trailing
  `law_waitlist_process()` seated, because a move can put a smaller wait at the
  front of a queue whose places have since been freed, and the front end has to
  reload rather than reorder in place when that happens. `law_waitlist_renumber()` closes the gaps after any
  promotion, cancellation or move, so the stored position always matches the
  one the host is looking at.
- An entry restored from the trash goes to the BACK of the queue
  (`untrashed_post`): its old position is meaningless once the people behind it
  have moved up.
- `law_event_tickets_changed()` (in waitlist.php) is called at the END of both
  ticket write paths — `admin/event-screen.php` and
  `law_events_form_save()` — after every other field is written, so a save that
  moves the date and raises the places cannot email an invitation carrying the
  old date. Raising the places offers them to the queue; lowering them only
  logs.
- Handlers: `law_waitlist_join` (signed in, on the shared `booking` rate
  surface), `law_waitlist_reorder` and `law_waitlist_promote` (both
  `law_user_can_manage_event()` on `post_parent`, on a new `waitlist_manage`
  surface at 60/600s per user and 300 per IP, because a host tidying a long
  queue would trip the edit budget). The reorder answer carries the queue as it
  now stands (`order` as `[{id, position}]`, plus `promoted` and `moved`) so the
  arrows reorder the host's table in place; its `redirect` is still the no-JS
  path and the client's fallback.

### `bookings-dashboard.php`: the committee's cross-event Bookings dashboard (8 September 2026)

- **Why**: until this round the committee could only see bookings one event
  at a time (the per-event list) or as a read-only wp-admin list searchable by
  title only, so "which events is jane@firm.com booked on?" and "everyone
  attending this year, for badging" had no answer. EVENTS_BOOKINGS.md §7.6.
- **Page**: `/account/dashboard/bookings/`, a child of the events dashboard,
  template `templates/account-bookings-dashboard.php` ("Bookings dashboard
  (committee)"), in `law_migration_page_map()` and `law_setup_account_pages()`
  (CPT mode); `law_setup_bookings_dashboard_access()` (setup-account-pages.php,
  also called by migration step 10) copies the parent's `_members_access_role`
  rows onto the child when it has none, because a page the pages step creates
  carries no restriction and Members reads that as public. The template also
  checks `law_user_is_committee()` in code. Linked as "Manage Bookings" in
  the header account dropdown for committee (`law_account_paths()` key
  `bookings`), after "Manage Events".
- **Filters** (`law_bookings_dashboard_filters()`, from `$_GET` or an explicit
  array): `law_kw` keyword over attendee name, email, organisation, job title,
  the booking number ("#12" or "12") and the event title; `law_event` (the
  picker lists only events holding a booking, `law_bookings_dashboard_events()`,
  ordered by start); `law_bstatus` (active by default / `cancelled` / `all`);
  `law_press` (press only); `law_year` (a `law_year` select, rendered only when
  more than one term exists). The bar is the events dashboard's markup driven
  by calendar-filters.js over `&law_partial=1`
  (`law_bookings_dashboard_maybe_render_partial()`, Members + committee
  re-checked); no checkbox controls, because that script reads a field's value
  regardless of its checked state.
- **Rows** (`law_bookings_dashboard_rows()`): one flat row per attendee of
  every matching booking — booking number (linked to the per-event list),
  event + start, attendee (booker flag, Press badge), email, organisation, job
  title, live country, Active/Cancelled badge, booked date — plus a summary
  line ("N attendees across N bookings on N events"). Bookings are fetched with
  a screen cap (`LAW_BOOKINGS_DASHBOARD_SCREEN_CAP`, 2,000; a notice says so
  when hit) and the keyword is applied in PHP over the fetched set, never as a
  LIKE over serialised meta; `cache_users()` primes the profile reads. The
  view is deliberately read-only: Reject and Register stay on the per-event
  list, which every row links to (one place for mutations).
- **Export**: `law_bookings_dashboard_export` (GET, `format=csv|xlsx|json`,
  nonce of the same name, `law_user_is_committee()`, the committee-export
  handler's shape), uncapped, columns Booking ID, Event, Event date,
  Reference, First name, Second name, Email, Organisation, Job title, Country,
  Press, Status, Booked on, Accessibility, Dietary; the title line records the
  filters. The CSV/Excel hrefs bake in the server-rendered filters and
  export-buttons.js refreshes them from the live filter form; pdfmake loads
  footer-side for committee only.
- **Assets**: calendar.css + calendar-filters.js (enqueue.php) and
  event-form.css (submission-form.php) gate on the template alongside the
  events dashboard; the table styles are the per-event list's
  (`.law-booking-table`) plus a few `.law-bookings-dashboard__*` rules.
- Tests: `tests/BookingsDashboardTest.php` (rows and live country, keyword
  by email/name/number, event/status/press/year filters, filter
  normalisation, export columns and the press marker, the event picker).

### `ics.php`: calendar invites (bookings phase 2)

- `law_event_ics()` / `law_event_ics_tempfile()`: the VCALENDAR text and the
  temp file `law_booking_send_with_ics()` attaches. Naive site-local
  `_law_start`/`_law_end` strings are converted from `wp_timezone()` to UTC
  `DTSTART:…Z` (no VTIMEZONE; correct across the BST/GMT boundary); a missing
  end defaults to start + 2 hours; no start means no invite ('' returned).
- `law_events_ics_escape()` / `law_events_ics_fold()` / `law_events_ics_utc()`:
  RFC 5545 escaping, 75-octet folding (multibyte-safe) and the UTC conversion.

### `test-mode.php`: site-wide email test mode

- `law_events_test_mode()`, `law_events_is_test_mode()`,
  `law_events_test_mode_address()`: the stored setting (enabled flag, address,
  `enabled_at`) and the single check every mail path uses. A ticked box with no
  valid address is inert, so a half-finished setting can never silently swallow
  mail.
- `law_events_test_mode_redirect()` (on `wp_mail` priority 99, i.e. just before
  the Email Templates plugin wraps the body at 100): rewrites `to` to the test
  address, prefixes the subject `[TEST MODE]`, prepends a note naming the real
  recipients, and adds `X-LAW-Test-Mode` headers.
  `law_events_test_mode_headers()` normalises the headers and **drops Cc/Bcc**,
  which would otherwise leak the message to the very people the redirect
  protects.
- **Scope warning**: this is a `wp_mail` filter, so it captures *every* email
  the site sends — events notifications, WordPress password resets, new-account
  notifications and form 7 (Contact) alike. A reset link for any account,
  including an administrator's, is delivered to the test mailbox while it is on.
  Three guards keep that from being left running:
  `LAW_EVENTS_TEST_MODE_MAX_HOURS` (2) expires the setting, with
  `law_events_test_mode_expire()` on `admin_init` writing the switch-off so the
  UI and the mail path agree (a rehearsal is a sitting-at-the-desk activity, so
  the window is deliberately short; re-saving the card restarts the two hours,
  and `law_events_test_mode_duration_label()` /
  `law_events_test_mode_expiry_label()` are the single source for the "2 hours"
  and "today at 14:30" wording in the card, the save notice and the standing
  admin notice). A **"keep on until I switch it off" checkbox** on the card
  (`no_expiry` in the stored setting) deliberately disables that auto-expiry,
  for a testing environment that should sit in test mode for days — the other
  two guards still apply, so what remains is a human one.
  `law_events_test_mode_admin_notice()` puts a
  standing warning on **every** admin screen while it is on; and
  `law_events_is_production()` (`wp_get_environment_type()`, filterable) makes
  enabling it on the live site require a second confirmation tick. Set
  `WP_ENVIRONMENT_TYPE` to `local`/`staging` in `wp-config.php` off production
  so that tick is not asked for there.
- `law_events_test_mode_check_email()` and the
  `wp_ajax_law_events_check_email` endpoint (`manage_options` + nonce): syntax
  check then an MX/A DNS lookup, reported as "deliverable domain", never as
  "real mailbox". `assets/js/law-admin.js` drives it live behind the address
  field.

### `notifications.php`: the email registry

- `law_events_email_registry()`: all 78 module emails as definitions (slug →
  recipients, subject, body with `{placeholders}`, trigger, active flag).
  - **Host**: `user_submitted`, `user_sent_back`, `user_payment_due`,
    `user_confirmed_paid`, `user_confirmed_free`, `user_rejected`,
    `user_cancelled` (carries `{cancellation_reason}`; deliberately no
    `{event_link}`, which resolves empty on an unpublished event),
    `user_new_comment`.
  - **Additional hosts** (co-owners, sent on approval, recipient passed in via
    the send call's `to`): `user_co_owner_created` (new account, carries
    `{set_password_link}` and `{username}`) and `user_co_owner_linked`
    (existing account, points at the dashboard and `{forgot_link}`).
  - **Committee**: `committee_submitted`, `committee_resubmitted`,
    `committee_approved`, `committee_payment_received`,
    `committee_event_updated`, `committee_assignee`, `committee_new_comment`,
    `committee_refund`, `committee_withdrawn` (host withdrew a submitted
    event; skipped for drafts) and `committee_cancelled_paid` (the "ACTION
    NEEDED" manual-refund alert — sent by cancel when the fee is already
    paid, and by the webhook when a payment lands on a cancelled event).
  - **Admin / Square Eye**: `admins_user_registered`, `admin_stripe_error`, and
    the three inactive-by-default Square Eye copies `squareeye_submitted`,
    `squareeye_event_updated`, `squareeye_user_registered`.
  - **Bookings** (all `dynamic` unless noted): `user_booking_confirmed`
    (.ics attached; lists the whole party with each person's own number),
    `host_booking_received` (to `host`) and `committee_booking_received` (to
    `committee`, assignee-first via the send call), both once per submission but
    **inactive by default** since 10 September 2026 (Denis: a busy event mailed
    both audiences on every submission, and Manage bookings lists the same
    thing). Their entries and send calls are still in place, so ticking "Send
    this notification" on the Emails screen brings either back;
    the single `user_booking_registered` (.ics attached; names who booked the
    place and carries that person's own number), which since 17 September 2026
    covers every "someone else booked this for you" case: a colleague bringing
    a party, and a host or committee member registering someone on their
    behalf, with or without an account being created. It replaced four
    near-identical entries (`user_attendee_invited` / `user_attendee_added` and
    `user_booking_registered` / `_invited`) that differed by a sentence apiece
    (Denis: four rows on the Emails screen for one message is too much).
    `{invited_by}` names whoever did it, falling back to "the organisers" when
    a manager acted, because an on-behalf registration writes `_law_booked_by`
    equal to the author and so reads as self-booked; `{account_note}` carries
    either the set-password block or the sign-in-as-usual line, built already
    resolved by `law_booking_account_note()` because the renderer substitutes
    in one `strtr()` pass. `{registered_by}` retired with them, still mapped to
    `''` so a stored override carrying the tag renders empty. Overrides are
    keyed by slug and merged only when the registry still has the key, so any
    customisation of the three retired slugs is inert rather than broken; the
    per-context cancellation family `user_booking_rejected` /
    `user_booking_cancelled_by_booker` / `user_booking_cancelled_self` /
    `user_booking_event_cancelled`; the single registration welcome
    `user_welcome_registered`, event-less and so unlogged (there was a second,
    hosting-side copy until 16 September 2026, with a
    `law_registration_welcome_slug()` helper choosing between the two on the
    stored `law_intent`; both went when it became clear the branch had only one
    reachable answer); and the two capacity stages, each with its own one-shot
    latch:
    `host_capacity_warning` (to `host`, at `law_event_capacity_warning_at()` —
    fewer than 10% of the places left, floored at 5) and, when the last place
    goes, `host_event_full` (to `host`) plus `committee_event_full` (to
    `committee`, assignee-first via the send call).
  - **Waitlist** (WAITLIST.md §B4): `user_waitlist_joined` (the joiner, with
    the party), `user_waitlist_attendee_invited` / `_added` (a colleague put on
    the queue; no .ics, since they hold no place yet),
    `host_waitlist_activated` (to `host`, the first time a queue forms),
    `user_waitlist_promoted` (**.ics attached** — the one waitlist email that
    carries an invitation, because by then they have a place) and
    `host_waitlist_promoted` (to `host`, one summary per pass),
    `user_waitlist_left` / `user_waitlist_removed_by_booker` /
    `user_waitlist_rejected` / `user_waitlist_event_cancelled`, and
    `user_waitlist_blocked` (a place came up but a clash stopped it being
    taken; sent once per reason).
- `law_events_email( $slug, $event_id = 0 )`: the registry entry with any admin
  override merged in (overrides live in one option, editable on the Emails
  screen), and since 17 September 2026 with the PER-EVENT booking confirmation
  merged on top of that when an event is passed. Every screen that edits or
  lists templates calls it with one argument and so keeps the site-wide view;
  only `law_events_send()` passes an event, which is why not one send site had
  to change.

### The shared sign-off

Every module email ends with the same closing lines, stored once rather than
written into each body (Denis, 17 September 2026: "add this ending to each
email text template we have, even if it's customly modified").

- `law_events_email_signoff_default()` is the shipped wording, "Best regards, /
  London Arbitration Week". `law_events_email_signoff()` returns the stored
  value, falling back to the default only when the option has never been
  written: `get_option()` is called with a `null` default precisely so that
  "never edited" stays distinguishable from "deliberately emptied", since
  clearing the field is how the sign-off is switched off.
- `law_events_email_with_signoff()` appends it after a blank line, and the ONE
  place that calls it is `law_events_email_render_body()` — before the
  placeholder substitution, so a sign-off may carry `{site_name}` exactly as a
  body does. That seam is the whole design: the renderer is what the shipped
  default, a site-wide override and a per-event override all pass through, so
  the awkward half of the request ("even if it's customly modified") is
  satisfied by construction rather than by remembering to. Pasting the lines
  into the 78 registry bodies would have reached neither the 15 bodies already
  reworded on the Emails screen nor the post-meta confirmations, and the next
  person to edit any body could have deleted them without noticing.
- The editing UI is a panel at the FOOT of the notifications list on both
  screens, not a field on each email's editor, because it is not one email's
  wording: `law_events_emails_signoff_card()` in `admin/emails-screen.php` and
  `parts/events/emails-signoff.php` behind `admin_post_law_email_signoff` in
  `emails-dashboard.php`. Both write through `law_events_email_signoff_save()`,
  which sanitises on the bodies' allowlist, so the two screens cannot drift
  here either. An empty save is accepted rather than refused, unlike an empty
  body: an email with no message is a bug, an email with no sign-off is a
  decision.
- **The migrated wording already signed off.** Six of the fifteen stored
  overrides carried over from Gravity Forms (`user_submitted`, `user_sent_back`,
  `user_payment_due`, `user_confirmed_paid`, `user_confirmed_free`,
  `user_rejected`) end with their own "Best, / London Arbitration Week", so they
  would now sign off twice. `functions/events/migration/repair-signoff.php`
  strips those closing lines out of the stored bodies, as a dry-run panel on
  LAW → Migration. Deliberately a one-off repair and not a render-time check:
  a renderer that decided for itself which closing lines to swallow would have
  to keep being right about every wording anyone writes in future, and a
  committee member who wanted a different closing line on one email would find
  it silently eaten. `law_events_signoff_strip()` only ever takes a short (≤ 60
  character) trailing line that is nothing but a valediction or the
  organisation's name, at most four of them, and only when a valediction is
  among them — so the worst case is a sign-off left in place, never a message
  truncated.

### The per-event booking confirmation (`email-override.php`)

One event can say its own thing in its confirmation without touching the
wording every other event on the programme sends (Denis, 17 September 2026).
A committee member ticks **Override booking confirmation** on the event, saves,
then follows the link that appears and writes that event's confirmation.

- `law_event_override_slug_map( $event_id )`: which confirmation templates this
  event's override displaces, and which of the two stored bodies replaces each.
  A hosted event maps **both** `user_booking_confirmed` and
  `user_booking_registered` to one body, which is the point of the feature: the
  person who booked and the colleagues they brought read the same words. A
  reception maps `user_reception_confirmed` to one body and
  `user_reception_confirmed_free` to a second, because its confirmation is
  genuinely two templates and flattening them is what produced "You paid £0.00"
  above an empty invoice link, fixed 16 September 2026. The flagship returns an
  empty map: it has its own dashboard and `law_committee_requested_event()`
  refuses it, so there is no screen on which its tick could be set or cleared,
  and refusing it here means the send path can never honour a flag nothing can
  reach.
- `law_event_override_active()`, `law_event_override_wording()`,
  `law_event_override_url()`, `law_event_override_fields()`,
  `law_event_override_written()`. The editor seeds each field from
  `law_events_email( <slug> )`, so the committee starts from what the event
  would really send, including a site-wide override already in force, rather
  than from the shipped text underneath it.
- Storage is five post meta keys on the `law_event` (`_law_email_override` plus
  a subject and body pair, and a second `_free` pair for receptions), not the
  `law_events_email_overrides` option: meta travels with the event through the
  content transfer bundle, is deleted when the event is so no orphan rows
  accumulate, and one event can only ever have one override by construction.
  The bodies use a **new `rich` sanitiser type** in
  `law_events_sanitize_value()`; `multiline` is `sanitize_textarea_field()` and
  would strip the markup the committee just wrote.
- The override replaces **subject and body only**, never `active` and never
  `to`. An event may not switch on an email the site has switched off, and
  storing an `active` per event would repeat the trap
  `law_setup_retire_booking_received_emails()` exists to undo. Each field swaps
  only when the event actually has one, so a half-written override falls back to
  the site wording rather than sending a blank subject line.
- Two entry points, because `law_committee_event_url()` routes a reception to
  the receptions dashboard and so a reception is never edited at `?event=<id>`:
  the committee controls form in `templates/account-dashboard.php` for hosted
  events, and `parts/events/reception-manage.php` for receptions. Both follow
  the session-agenda pattern, rendering the link from the stored flag and a
  "save first" hint from `data-law-toggle-for` until then, because the editor
  reads the flag and a link offered before the save would bounce straight back.
- The editor is a sub-mode on the committee dashboard,
  `?event=<id>&law_email=1` (`parts/events/committee-email-override.php`), not a
  page of its own: a new page would be database state to provision on every
  environment for a screen that is already there, the same argument the template
  makes for `law_edit`. `law_committee_requested_event()` already accepts a
  reception, so one branch serves both kinds, and the back-link goes through
  `law_committee_event_url()` so each kind returns to its own home.
- Unlike the site-wide Emails screen, which deliberately logs nothing because
  wording belongs to no event, **every save here is logged on the event**. This
  wording belongs to exactly one.
- `law_events_email_placeholders()`: builds the merge values for an event
  (title, reference, host, fee, dashboard/committee/invoice links, etc.). The
  account links `{bookings_link}`, `{profile_link}` and `{submit_link}` (via
  `law_account_url( 'submit' )`) are set unconditionally, so the event-less
  registration emails resolve them.
- `law_events_email_recipients()`, `law_events_send()`: resolve recipients and
  send, logging the send (with recipients, and the test-mode address when one
  is in force) to the activity log. A £0 event splits to the "confirmed, free"
  template. When an audience resolves to nothing (an empty committee list, say)
  the email is normally dropped — but **in test mode it is routed to the test
  address instead**, since the point of a rehearsal is to see it.

### `speakers.php`: speaker records and the archive (CPT mode)

- **The archive switch** (Denis, 17 September 2026).
  `law_speakers_archive_is_public()` reads the `speakers_archive_public`
  setting (default off, see `settings.php` above);
  `law_speakers_archive_is_hidden()` is the decision and
  `law_speakers_archive_gate()`, on `template_redirect` at priority 5, acts on
  it. The two are separate so the rule can be asserted without running the 404
  (`tests/SpeakersArchiveVisibilityTest.php`).
- **Why a 404 and not a redirect.** The first build sent `/speakers/` to the
  home page with a 302. Denis replaced it with a 404 the same day, on SEO
  grounds, and he was right: Google treats a redirect to an **irrelevant**
  destination as a soft 404 anyway, so the redirect bought none of the
  protection a redirect usually does, while also dumping a person who followed a
  real link somewhere they never asked to go. A 404 states the plain fact — not
  here yet — and the page is re-indexed from the sitemap when the switch goes
  on. A **410** would be wrong in the other direction: it means gone for good,
  and this page is coming back. `nocache_headers()` goes with the status,
  because a 404 cached in a browser or at an edge would outlive the flip.
- **The sitemap is the other half, and the half that decides how this reads to
  Google.** `law_speakers_archive_sitemap_exclusion()`, on SEOPress's
  `seopress_sitemaps_single_query`, drops the Speakers page from the pages
  sitemap while the archive is hidden. Without it the sitemap advertises a URL
  that answers 404, which Search Console reports as "Submitted URL not found
  (404)" — an **error against the property**, materially worse than the status
  code choice on its own. The page returns to the sitemap by itself when the
  switch goes on; nothing has to be remembered.
- **The gate is scoped to the archive VIEW.** A single profile lands on the same
  page with `law_speaker` set, and in CPT mode on its own permalink, and must
  keep resolving for everyone, since the event pages link to it.
  `law_user_is_committee()` is the bypass test, and it is the right one for all
  three privileged roles: it asks for `edit_others_law_events`, which committee,
  editor and administrator hold and no other role does.
  `templates/speakers.php` prints a one-line notice for them
  (`.law-speakers__hidden`, styled in `assets/css/speakers.css` for the light
  page rather than borrowed from the dark-surface `.law-form-notice`) so the
  preview does not read as live.
- `law_speakers_archive_url()`: the archive URL, resolved from the page holding
  `templates/speakers.php` (falling back to `/speakers/`). Shared by the
  settings screen's description and the profile's back link, which had the
  resolution inline.

- `law_speaker_find_existing()`, `law_speaker_normalise_name()`: dedupe by email
  first, then normalised name (the full name, i.e. the post title).
- **The name is stored in two parts** (Denis, 9 September 2026). Every form that
  collects a speaker now asks for a **First name** and a **Last name**
  separately, which is the shape the legacy source always had (form 8,
  Event > speaker, field 1 Name: `1.3` First and `1.6` Last). The parts live on
  the speaker post as `_law_speaker_first_name` / `_law_speaker_last_name`;
  **`post_title` remains the display name** every listing prints, every lookup
  matches on and every session row is linked by, and it is rebuilt from the two
  parts whenever they are written. The helpers are all in `speakers.php`:
  `law_speaker_full_name( $first, $last )` joins them,
  `law_speaker_name_parts( $post_id )` reads a post's parts and **falls back to
  splitting the title** for any record saved before the change,
  `law_speaker_row_name_parts( $row )` does the same for a submitted row that
  still carries one `name` key, and `law_speaker_split_name()` is the splitter
  itself: the last word is the last name, with honorific suffixes
  (`LAW_SPEAKER_NAME_SUFFIXES`: KC, QC, PhD, …) kept on it, so "Ali Malek KC"
  splits to "Ali" / "Malek KC" and a one-word name is all first name. Because of
  the fallback, **nothing had to be backfilled to deploy this**; a legacy record
  gains real parts the next time it is submitted or edited.
- **`get_the_title()` displays a name; `law_speaker_raw_name()` matches one.**
  `get_the_title()` runs `wptexturize`, which rewrites an apostrophe as
  `&#8217;`. That is right for rendering and wrong everywhere else: all three
  name-editing screens (the event form, Manage Speakers, the wp-admin speaker
  box) prefill from `law_speaker_name_parts()`, and all three rebuild
  `post_title` from what comes back, so a filtered title meant "Crystal
  O'Donnell" was offered as `Crystal O&#8217;Donnell` and written back on the
  next save, compounding each time. `law_speaker_name_parts()`,
  `law_speaker_find_existing()`, the upsert's rename check, the session matcher
  and `law_events_form_values()` all use the raw title now.
  `law_speaker_normalise_name()` additionally decodes entities and folds smart
  quotes and dashes, so the two spellings still compare equal wherever one
  slips through.
- **An unrecognised email means a new person.** `law_speaker_find_existing()`
  falls back to a name match **only** when no usable email was given. An email
  is an identity claim, so a row carrying one that matches nothing is somebody
  new, even if a speaker of the same name is already on file: two different
  solicitors called "John Smith" must not be collapsed into one shared, publicly
  displayed profile carrying the wrong address. The name fallback remains for
  the sources that have no email at all, which is the legacy List field 48 rows
  the migration reads. A duplicate is a nuisance the committee can merge; a
  wrong merge silently rewrites a real person's record.
- `law_speaker_upsert()`: match-or-create a `law_speaker` from a submitted row.
  **Identity only** (first name, last name, email, website); it no longer writes
  an organisation or job title, and the featured image and the biography it sets
  once are only fallbacks. It has **two modes**, chosen by the caller:
  - **Gap-fill (the default)**, used by a brand-new row and by the migration: it
    fills empty fields only, never blanks an existing value, and logs to the
    event when it backfills a pre-existing shared record. The name parts are
    gap-filled too and deliberately **not** logged as a backfill (the displayed
    name does not change, only its stored shape).
  - **Overwrite**, used when the event form re-saves a speaker the event already
    holds (`overwrite_identity` + `speaker_id` in the third argument): first
    name, last name, email and website are written **outright**, `post_title` is
    rebuilt from the parts, and every change is logged on the event as
    `speaker_updated` with the old and new values. This exists because the form
    shows those fields as editable and required, so silently discarding an edit
    was data loss (Denis, 9 September 2026); the log is the safety net, since the
    profile is shared with every other event that speaker appears at. `post_name`
    is left alone, so an existing `/speakers/<slug>/` link keeps resolving.
    The email is the dedupe key, so a new address that already belongs to another
    record is skipped (the rest of the row still saves) rather than merging two
    people, and **a blank email is skipped too** (15 September 2026): it means
    "not given", never "delete the one on file". `law_event_update_meta()` turns
    `''` into a `delete_post_meta()`, so without that guard an empty box took the
    dedupe key off a record shared with every event the speaker appears at, and
    every future match for them silently fell back to name matching. The host
    event form cannot post a blank (the field is required there), but the
    committee's external-event form validates no speaker rows at all
    (`law_external_event_validate()`) and shares this saver. Clearing an address
    outright is a Manage Speakers action, where a committee member means it:
    `law_speakers_dashboard_write_identity()` writes identity directly rather
    than through the upsert, exactly so that it can. The website has no such
    rule — it is nobody's identity, and an emptied box there is an ordinary
    correction. `tests/SpeakerNamesTest.php` covers both halves.
- **The event form round-trips each speaker row's post ID** in a hidden
  `speakers[i][speaker_id]` input (`parts/events/event-form-fields.php`), which
  is what lets a re-save edit the right record instead of re-matching on the very
  fields the host just corrected. `law_events_form_save_speakers()` honours a
  posted ID **only when the event already holds that speaker**, exactly as the
  session rows honour a posted session ID, so a forged value reaches nothing; a
  row with no ID (a newly added speaker) falls back to match-or-create and the
  gap-fill rule. The ID counts as machinery in the "a started row must be
  complete" check, so clearing a row to delete it still works.
- **Speaker details are per appearance** (Denis, 8 September 2026, extended to
  the biography on 8 September 2026): the organisation, job title, photo **and
  biography** a speaker had at a given event live on that event's
  `_law_speakers` row (`{speaker_id, role, organisation, job_title, photo_id,
  bio, sort}`), because one person speaks for different firms — and writes a
  different biography — at different events, and each event must show what was
  submitted for it. The old `_law_organisation`/`_law_job_title` speaker meta is
  off the schema (stale rows are inert); the sanitiser reads a pre-change
  `organisation_override` as `organisation`, and `bio` takes the textarea
  sanitiser so a biography keeps its line breaks. A row that leaves the
  biography or photo blank falls back to the speaker post's editor content and
  featured image at read time. Both are optional on the host form, and since
  15 September 2026 so are the organisation and the job title — see the Speakers
  fieldset under `law_events_form_save()`. The committee event detail
  (`templates/account-dashboard.php`) prints each of the three lines only when it
  has something in it: its `mailto:` used to be emitted unguarded, so a speaker
  with no email (the 173 the migration brought in from the legacy List field 48
  never had one) got an empty link and an orphaned `·` before the profile link.
- **Speaker roles are per appearance too** (Trevor, 3 September 2026; built
  8 September 2026): the row's `role` slot, reserved since 4.1, now holds what
  the person was at THIS event. `law_speaker_roles()` is the vocabulary
  (`speaker` / `host` / `moderator`, in the order the selects offer),
  `law_speaker_role_key()` normalises a key or a label in any case to the key
  (`''` for anything unknown), `law_speaker_role_label()` is the label, and
  `law_speaker_role_display()` is what a listing prints after the name — `''`
  reads as Speaker, the default, and prints as such on every card (Denis,
  8 September 2026), so it is the one line to change should the default ever
  be suppressed. `law_speaker_role_headings()` / `law_speaker_role_heading()`
  are the same vocabulary phrased as the headings the speaker profile groups
  its events under ("Speaking at:", "Hosting:", "Moderating at:"), with the
  same fall back to Speaker. `law_speaker_appearances()`,
  `law_speaker_appearance_for_event()` and `law_speaker_card()` expose `role`;
  a session row's blank role inherits the parent event row's, like its other
  fields, and an explicit Speaker on a session row overrides an event-level
  Host. `law_speaker_first_appearance()` deliberately carries no role: the
  archive headline is per person, the role is per event. There is no free-text
  "other" and the legacy `'gf'` render branch in `functions/calendar.php` was
  left without it (it is deleted post-cutover); the role shows once the source
  is `cpt`, with the migration carrying the values entered on the live form.
- `law_speakers_event_maps( $statuses, $limit, $reset )` (9 September 2026):
  the one pass over a set of events' speaker rows, memoised per status set —
  'events' (speaker_id => event IDs) plus 'appearances' (speaker_id =>
  event_id => row). `law_speakers_confirmed_maps()` is now a thin wrapper on it
  for `publish` and the 500-event cap, unchanged in signature and shape for
  every existing caller; `law_speakers_confirmed_event_map()` still wraps that.
  The extraction exists because the Manage Speakers dashboard needs **every**
  status (a speaker record exists from the first draft save), and a second
  near-identical pass was the alternative. `$reset` clears the whole memo, not
  just the key asked for — whatever invalidated one status set invalidated the
  rest — and `law_speakers_flush_maps()` is the same flush by name, called by
  the dashboard's save handler and by tests that create events mid-request.
  `law_speaker_appearances()` and `law_speaker_first_appearance()` each take an
  optional `$statuses`, defaulting to publish-only, which is what the archive
  and the profile mean by an appearance.
- The published-events pass is still the archive
  source (one profile per speaker referenced by a Confirmed event; visibility
  derived from events, not status) plus each speaker's appearance row per event.
- `law_speaker_appearances()` (first-submitted event first, by creation date
  then ID, never by event date), `law_speaker_display_photo_id()` (on an event page,
  speakers section and sessions alike, the photo set for THAT event wins, else
  the first photo ever provided for the speaker, else the initials
  placeholder), `law_speaker_first_appearance()`
  (the archive/profile rule: organisation, job title, photo and biography from
  the FIRST appearance, each field falling through to a later one only when
  empty),
  `law_speaker_appearance_for_event()` (the exact row for one event, event row
  first then its sessions), `law_speaker_photo_url()` (row attachment, else the
  featured image).
- `law_speaker_latest_appearance()` and `law_speaker_row_prefill()`
  (11 September 2026): the mirror of `law_speaker_first_appearance()`, for
  EDITING rather than for the archive. The first-appearance rule is what a
  speaker's card shows (what they were the first time LAW hosted them);
  prefilling a **new** appearance from the stalest values on record would be
  wrong, so the picker starts from the latest event on record and falls through
  to the appearance before it field by field. `law_speaker_latest_appearance()`
  also carries `role`, which the first-appearance helper deliberately does not:
  on a card the role is a fact about one event, on a form it is the value the
  committee most likely wants again. `law_speaker_row_prefill()` wraps it for
  the picker: **every** status (a speaker record exists from the first draft
  save, and the appearance worth copying forward is often on an event that is
  not confirmed yet), with the speaker post's editor content and featured image
  as the last resort, and the photo's thumbnail URL alongside the ID so the row
  can show it without a second request. There is no organisation or job title
  on the speaker post to fall back to: those have been per appearance since 4.1
  and the old `_law_organisation` / `_law_job_title` meta rows are inert.
- `law_speaker_post_profile()`, `law_event_speaker_cards()`,
  `law_speaker_card()`: the profile and card shapes the archive/single views
  render. The profile carries `appearances`; a card takes its values from the
  row, and a session row falls through to the parent event's row for the same
  speaker, then to the speaker post for the photo and biography. The card's
  photo is the `medium` size, since the single event view renders it at 5.5rem.
  `templates/speaker.php` shows the headline job title and organisation under
  the name, the same two lines the archive card shows and from the same merged
  profile values (Denis, 11 September 2026), then the event list **grouped by
  the role the person held** (Denis, 11 September 2026):
  `law_speaker_events_by_role()` splits the programme-ordered list into one
  group per role and `law_speaker_role_heading()` heads each one — "Speaking
  at:", "Hosting:" (you host an event, you do not host *at* one) or
  "Moderating at:". Groups come back in `law_speaker_roles()` order, empty ones
  are dropped, and an appearance with no role recorded falls under Speaker,
  the same default `law_speaker_role_display()` applies. The cards themselves
  carry **no per-appearance lines any more** (Denis, 11 September 2026): the
  "[name]'s role / organisation / position" block `parts/loop/event.php` used
  to render from a `speaker` arg is gone, since the heading now says the role
  and the headline job title and organisation are already above the list. It
  still shows **no biography** either: that is on the event page's speaker
  cards. It also passes
  `stacked` (Denis, 11 September 2026), which keeps the card's phone layout at
  every width (buttons on their own line under the text,
  `.law-event-card--stacked`), because the profile column is narrow and the
  right-hand button column left the title wrapping in half the row.
- **The flagship in a card list wears the flagship's colours** (Denis, 14
  September 2026). A speaker who appears in a flagship session already reaches
  the profile through the ordinary path — `law_flagship_recompute()` writes the
  sessions' deduped speaker union onto the flagship event's `_law_speakers`, so
  `law_speakers_confirmed_event_map()` sees it like any other event — and the
  conference therefore turns up as one more row under "Speaking at:" /
  "Moderating at:". `parts/loop/event.php` now reads `is_flagship` on the
  mapped event and adds `.law-event-card--flagship`, which repaints the row in
  the programme block's brand navy with white text, orange accents, inverted
  buttons and an orange outline "Flagship event" pill
  (`.law-event-card__flagship-badge`), so the conference reads as the
  conference wherever it is listed — the profile, My bookings, My events. The
  programme's own day lists never take this path: the flagship is lifted out of
  them and rendered as `parts/events/flagship-card.php` instead.
- **And it carries the same Register button** (Denis, 15 September 2026). The
  row used to have Event details alone: `law_booking_card_action()` returns
  null for the flagship (it is applied for, not booked, and its own programme
  block asks `law_flagship_card_action()` instead), so a speaker profile, My
  bookings and My events were the only places on the site listing the
  conference with no way into it. `parts/loop/event.php` now branches on the
  same `is_flagship` flag it repaints the row with, and asks
  `law_flagship_card_action()` for the button: Register with the
  `data-law-book` fetch hook and the `?law_flagship_apply=1` no-JS link behind
  it, or whatever the viewer's own registration offers instead (View my
  booking, Sort out my payment, Add my payment details, View my registration),
  and nothing at all before the conference is published or on sale. The
  function took a `$scope` argument to match `law_booking_card_action()`'s:
  under `'action'` — what My bookings and My events pass, because their own
  actions already link to the viewer's registration — only Register survives.
  The dialog itself needed no work: `law_booking_maybe_render_dialog()` already
  branches the flagship to `law_booking_render_flagship_dialog()`, and
  `law_booking_is_card_view()` already names the speaker profile (twice: the
  `law_speaker` single and the routed legacy page), so the fetch layer, the
  modal component and the placeholder dialog were on the page waiting for a
  button to answer.
- `law_speaker_bio_excerpt()` (24 words, an explicit `…` because
  `wp_trim_words()` otherwise appends the `&hellip;` entity, and
  `strip_shortcodes()` because an appearance biography never passes through
  `the_content`) and `law_speaker_bio_summary()` (the full plain text, the
  excerpt, and whether the excerpt trimmed anything — the card offers "Read
  full bio" only when it did). The summary trims to **14** words, about two
  lines in the third-width card column beside a session's description, because
  24 ran to four or five lines and the card competed with the session it
  belongs to (Denis, 11 September 2026); the excerpt helper keeps its own 24
  for `law_speaker_seo_description()`, which reuses it as a meta description
  where a longer summary is the point.
- `law_speaker_dialog_register()` / `law_speaker_dialogs()`: the request-scoped
  registry behind the single event view's biography dialogs. A speaker card
  registers and gets an element id back; `parts/calendar-body.php` prints the
  registered dialogs once, after the speaker and session sections. A registry
  rather than an index threaded through the templates because the cards render
  in two places (the event's Speakers list and each timeline item) and every
  dialog must land **outside** them; this mattered most under the old sessions
  accordion, where a closed `<details>` renders nothing and a dialog inside one
  could never open. One dialog per
  card, not per speaker, since two session rows for the same person can
  legitimately carry different biographies.
- `law_speakers_sort_cards()`, `law_speaker_role_rank()`,
  `law_speaker_sort_key()`,
  `law_speaker_sort_token()`: the order the front end lists speakers in, by
  **role first** — hosts, then moderators, then speakers and any row naming no
  role at all — and alphabetical by surname then first name inside each of
  those groups (Denis, 14 September 2026; the alphabetical part 11 September
  2026). The role leads because it is the reader's way into a list of faces:
  who is hosting and who is chairing are what they are looking for, and
  alphabetical order alone buried both wherever their surname happened to
  fall. A row with no role reads as "Speaker" everywhere it is printed
  (`law_speaker_role_display()`), so it ranks with the speakers rather than
  forming a fourth group; a legacy Gravity Forms card carries no role at all
  and therefore keeps its old alphabetical order exactly. Applied
  by `law_event_speaker_cards()`, by the session rows below and by the legacy
  Gravity Forms readers in `functions/calendar.php`, so the sidebar list, each
  session panel and the flagship programme all agree. The stored `sort` on a
  `_law_speakers` row is only the order whoever filled the form typed the rows
  in, which tells a reader nothing and puts the same person in a different
  place on every event. The key takes the surname from the speaker post when
  the card names one (the two name parts are more reliable than splitting a
  display name), else splits the display name, which is all a legacy card
  carries; it folds accents and drops punctuation, so "O'Donnell" files under
  "odonnell" and "Ödegaard" under "ode" instead of after Z, and a one-word name
  files under itself rather than sorting above everybody on an empty surname.
- `law_event_session_ids()`, `law_event_session_rows()`: an event's sessions
  (child `law_session` posts) and their rows.

### `speakers-dashboard.php`: the committee's Manage Speakers dashboard (9 September 2026)

- **Why**: speaker details are stored per appearance (see `speakers.php`
  above), which is right for the listings and was wrong for maintenance.
  Correcting a name, a photo or a biography meant opening every event that
  referenced the person, one at a time, in the host form or in wp-admin, and
  nothing anywhere answered "where does this speaker appear, and is their data
  right on each one?". Denis asked for the screen on 9 September 2026.
- **Page**: `/account/dashboard/speakers/`, the second child of the events
  dashboard, template `templates/account-speakers-dashboard.php` ("Speakers
  dashboard (committee)"), in `law_migration_page_map()` and
  `law_setup_account_pages()` (CPT mode). Its Members restriction is copied
  from the parent by `law_setup_speakers_dashboard_access()`, a wrapper over the
  **generalised** `law_setup_child_page_access( $path, $parent_path )` — the
  bookings helper hardcoded one path and there are now four children, so the
  body moved and `law_setup_bookings_dashboard_access()` became the other
  wrapper. (It was `law_setup_dashboard_child_access( $path )` until
  10 September 2026, when My bookings — a child of `/account/`, not of the
  events dashboard — made the parent a parameter.)
- **Deploying it needs no migration run.** `/wp-admin/?setup-account-pages`
  now **creates** a page the module owns instead of only reporting it MISSING
  (`law_setup_create_account_page()`, 9 September 2026), then assigns the
  template and copies the restriction in the same pass — so a page added to the
  module after cutover takes one URL visit. The allow-list is
  `law_migration_page_map()`, which is also where the title comes from, so the
  trigger and migration step 10 cannot disagree; a path that map does not name
  (the Login page, say) is still only reported, because a page deliberately
  absent from an environment must not be conjured up by a setup trigger, and a
  path whose parent is missing refuses rather than orphaning a page. This
  existed because step 10 was otherwise the only route, and a real step refuses
  without a fresh snapshot AND a preflight that passed in the last 24 hours —
  a heavy gate for creating one page, and the preflight reads legacy Gravity
  Forms data that may be gone by then.
  Committee only: the nav item, the template, the AJAX partial, the save
  handler and the export each check `law_user_is_committee()`. Linked as
  "Manage Speakers" in the header account dropdown after "Manage Bookings"
  (`law_account_paths()` key `speakers`; HeaderNavTest pins the order).
- **Two views on one page**, the module's established idiom: the list by
  default, the editor at `?law_speaker=<id>`
  (`law_speakers_dashboard_requested_speaker()` resolves it and refuses an ID
  that is not a `law_speaker`). `law_speakers_dashboard_url( $speaker_id = 0 )`
  builds both.
- **Every status is an appearance here.** A `law_speaker` post exists from the
  first *draft* save of an event — `law_speaker_upsert()` runs inside
  `law_events_form_save_speakers()` on every save, not on approval — and an
  appearance is most worth correcting before the event is confirmed. So the
  dashboard reads `law_speakers_dashboard_statuses()`
  (= `law_event_all_status_keys()`), where the public archive reads `publish`
  only. That is what drove the `speakers.php` extraction described above
  (`law_speakers_event_maps()`), rather than a second near-identical pass.
- **Filters** (`law_speakers_dashboard_filters()`, from `$_GET` or an explicit
  array): `law_kw` over the name, email, website and every appearance's
  organisation, job title and event title; `law_event` (the picker lists only
  events carrying a speaker row, `law_speakers_dashboard_events()`, ordered by
  start, with the status appended for anything but a Confirmed event since
  drafts show up here); `law_year` (rendered only when more than one term
  exists, and matched against the **event's** term, not the speaker's). The bar
  is the bookings dashboard's markup driven by calendar-filters.js over
  `&law_partial=1` (`law_speakers_dashboard_maybe_render_partial()`, Members +
  committee re-checked); no checkbox controls, because that script reads a
  field's value regardless of its checked state.
- **Rows** (`law_speakers_dashboard_rows()`): one row per `law_speaker` post —
  photo, name, email, website, the headline organisation and job title from
  `law_speaker_first_appearance()` (the archive card's own fall-through, so the
  list reads like the thing it maintains), the appearance count and the
  appearance list — plus a summary line ("N speakers, N event appearances").
  Screen cap `LAW_SPEAKERS_DASHBOARD_SCREEN_CAP` (1,000; a notice says so when
  hit) and the keyword applied in PHP over the fetched set, never as a LIKE over
  serialised meta. `law_speakers_dashboard_appearances()` is the one decorated
  appearance list the list, the editor and the export all read, so the three
  cannot disagree about where a speaker appears.
- **The editor** (`parts/events/speaker-manage.php`): the identity fields once
  at the top (first name and last name side by side since 9 September 2026, then
  email and website), then one fieldset per appearance with
  the event's status badge, Preview and Edit event links, and Role,
  Organisation, Job title, Photo and Biography — then **one** "Save changes"
  button for the lot (Denis chose a single button over per-event ones). The
  split is the point and the copy says so: identity is on the speaker post, so
  it changes every event at once; everything under an event heading changes
  that event only. A draft is offered no Preview or Edit link, because the
  committee dashboard neither lists nor previews one; its details are still
  editable here.
- **The save handler** `law_speakers_dashboard_save_handler()` (on
  `admin_post_law_speaker_manage`, `nopriv` → `law_events_nopriv_json`), on
  `law_events_guard_post()` with its own `speaker_manage` rate surface (30 per
  10 minutes), so a committee member tidying a speaker who appears a dozen
  times does not spend the event-submission budget. In order: committee check,
  resolve the speaker, validate (both a first and a last name are required, and
  the display name written to `post_title` is the two joined; an email must parse and
  must not already belong to a **different** `law_speaker` —
  `law_speaker_find_existing()` — because silently merging two records is not
  something a save button should be able to do; photos through the shared
  `law_events_validate_photos()`, whose error keys become
  `speaker_photo_<event_id>`), then write. Identity is written **directly**
  (`wp_update_post` + `law_event_update_meta`), deliberately not through
  `law_speaker_upsert()`, which only fills gaps and so could never clear a wrong
  website. This is also the one screen that must be able to change a name, so it
  writes `_law_speaker_first_name` / `_law_speaker_last_name` outright rather
  than gap-filling them; `post_name` is left alone so an existing
  `/speakers/<slug>/` link keeps resolving after a name correction. That write
  lives in `law_speakers_dashboard_write_identity( $speaker_id, $input )`, split
  out of the handler so it can be tested: the handler itself ends in a redirect
  and an `exit`. Each appearance block is written only
  to an event the speaker genuinely appears at (anything else is dropped, so a
  forged `event_id` writes nothing) via
  `law_speakers_dashboard_write_row( $post_id, $speaker_id, $values )`, which
  rewrites one row inside `_law_speakers` and leaves every other row and every
  `sort` position alone, returning the fields that actually changed. The photo
  rule matches the host form: a fresh upload wins, then the "Remove photo" tick,
  then whatever the row holds — the posted `photo_id` is never trusted. Session
  rows are **mirrored**, because `law_events_form_save_sessions()` stamps them
  from the event row and writing only the event row would leave the session
  panels stale; the known cost is that a per-session role override set in
  wp-admin is overwritten, exactly as a host save already overwrites it. Every
  event whose row changed gets a `speaker_appearance_updated` activity-log line
  naming the fields. A refusal goes to the transient state
  (`law_speakers_dashboard_state()`, read once and deleted) and back with
  `law_speaker_error=1`; success redirects with `law_notice=speaker-saved`. Both
  paths run through `law_events_respond()`, so a `law_ajax=1` JSON branch exists
  on every path — but the primary flow is the plain POST-redirect-notice one the
  two sibling edit forms use, because this is a multipart form save, not a
  one-click action.
- **Export**: `law_speakers_dashboard_export` (GET, `format=csv|xlsx|json`,
  nonce of the same name, `law_user_is_committee()`, the committee-export
  handler's shape), uncapped but rate limited on its own `speakers_export`
  surface at 40 per 600s (the security review's note: the export is the one
  place that asks for an unbounded pass over every speaker, so a stolen
  committee session should not be able to loop it; the budget is deliberately
  generous, since one visit legitimately spends three requests and the
  committee re-exports as they narrow the filters). It is **one row per
  appearance** — Speaker ID, First name, Last name,
  Email, Website, Event, Event date, Reference, Event status, Role,
  Organisation, Job title, Biography (the name is two columns since
  9 September 2026, so a badge or a mail merge does not have to re-split it) — because a row per speaker would have to
  pick one event's answer for fields that are per event. A speaker with no
  appearance yet still gets a row with the event columns blank. The title line
  records the filters.
- **Assets**: calendar.css + calendar-filters.js (enqueue.php) and
  event-form.css/js (submission-form.php) gate on the template alongside the
  bookings dashboard; the table and editor styles are a
  `.law-speakers-table` / `.law-speaker-appearance` block at the foot of
  `assets/css/event-form.css`. Column widths come from the shared
  `.law-dashboard__table` rule, which since 11 September 2026 caps every cell
  at 12rem and lets it wrap (it began life on the flagship applications table
  on 10 September 2026 and moved when Manage Speakers hit the same problem:
  one long organisation or job title pushed the right-hand columns off the
  screen). Cells that must stay on one line — `.law-dashboard__row-actions`,
  the waitlist position, the flagship tick — opt out there. pdfmake loads
  footer-side for committee only.
- **Out of scope, deliberately**: creating a speaker here, deleting one, and
  merging duplicates. Merging needs its own rules for the appearance rows on
  both sides, and duplicates are the predictable next request now that the
  whole list is visible.
- **Security review** (9 September 2026, security-specialist): no critical or
  high findings in the new code. The two notes acted on are the export rate
  limit above and an explicit `is_array()` check on the `_FILES` batch shape in
  the save loop, matching the guard already in `law_events_validate_photos()`.
  One reported "XLSX formula injection" finding was **rejected on inspection**:
  `law_events_xlsx_sheet_xml()` writes every non-numeric cell as
  `t="inlineStr"`, a typed string, and a formula in XLSX lives in a `<f>`
  element the writer never emits — so an `=`-leading value renders as literal
  text. That is why the CSV path needs `law_events_csv_guard()` (a CSV carries
  no types, so the reader infers a formula) and the XLSX path does not; the
  function's own comment says so. Prefixing XLSX cells with an apostrophe would
  corrupt legitimate data rather than harden anything.
- Tests: `tests/SpeakersDashboardTest.php` (11) — rows and the
  first-appearance headline, the keyword / event / year filters, filter
  normalisation, the one-row-per-appearance export and the no-appearance row,
  the event picker, the single-row writer (order preserved, other rows
  untouched, a forged event ID refused) and the `?law_speaker=` resolver.

### `flagship.php`: the flagship conference (9 September 2026)

The flagship is **one `law_event` post**, flagged `_law_is_flagship`, whose
`post_name` is `flagship`, so the CPT's own rewrite (slug `events`) gives it
the permalink `/events/flagship/`. Its sessions are child `law_session` posts,
exactly like any other event with an agenda, and it is edited on one wp-admin
screen (`admin/flagship-screen.php`) rather than through the host form.

**Why a post, and not a settings option plus a page template**, which is how it
was first specified: a WordPress page at `/events/flagship` would be swallowed
by the `law_event` rewrite rule (there is no `/events` page — the programme is
`/programme/`, and the only page whose slug is `events` is the host dashboard
under `/account/`), and sessions held in an option would be invisible to the
speaker directory, the speaker cards, the `.ics` feed and the bookings engine,
which the approval-gated application flow (EVENTS_4.2_SPECS.md §5) will need to
book against. As a post it reuses the whole read side for nothing.

- `law_flagship_event_id( $reset )`: the post ID, 0 before it exists.
  Memoised, and the result passes through a `law_flagship_event_id` filter,
  which is the seam the tests use to point every helper at a fixture instead of
  the site's real flagship.
- `law_flagship_is()`, `law_flagship_default_date()` (2 December of the
  programme year), `law_flagship_date()` (its meta, else the date half of a
  derived start, else the default — never empty, so the programme always has a
  day to pin the block under), `law_flagship_admin_url()`.
- `law_flagship_public_url()`: `/events/flagship/`. Not `get_permalink()` on
  its own, which returns the unpretty `?post_type=law_event&p=<id>` form for a
  post that is not published — that is what the Flagship screen was showing
  before the box was ticked. For a draft the address is built from the post
  type's own rewrite base and the slug, since it is knowable in advance.
- `law_flagship_ensure_post( $dry )`: create-if-missing, idempotent, with
  dry-run support and a slug-clash warning. Called from **three** places, so a
  git deploy alone is enough on any environment (Denis, 9 September 2026:
  "once the code is pushed everything should work instantly"): the migration's
  step 10, the `?setup-account-pages` trigger, and the Flagship screen's own
  first open.
- **Three values are derived, here and nowhere else**
  (`law_flagship_recompute()`): `_law_start` and `_law_end` from the fixed date
  plus the sessions' earliest start and latest end (`law_flagship_compute_range()`,
  both `''` when no session has a start, and a session with no end never
  shortens the event); and the event-level `_law_speakers` as the deduped union
  of the sessions' rows (`law_flagship_union_speakers()`, first appearance
  wins). The union is what makes flagship speakers appear on the speakers
  archive and their own profiles with **no read-side change at all**, because
  `law_speakers_event_maps()` and `law_speaker_appearance_for_event()` already
  read the event row. `_law_slot_label` is written empty every time: the
  flagship holds no slot, and a stale label would blank its datetimes the next
  time the slot helper ran over it. `law_event_apply_slot_label()` is never
  called for it.
- `law_event_hero_image_url( $post_id, $size )`: the banner photograph, `''`
  when unset or the attachment has since been deleted. Event-shaped rather than
  flagship-shaped, so the feature can be widened without a second code path.
- **Recompute hooks** on `save_post_law_session`, `deleted_post`,
  `trashed_post` and `untrashed_post`: the wp-admin Sessions screen and the
  Sessions list can still edit or bin a flagship session, and the derived
  values have to survive that.
- **Deliberately absent**: fee, invoice, workflow transition, `_law_approved_at`,
  payment meta, and any booking control. `law_booking_guard_open()`
  (`bookings.php`) refuses a flagship outright with `law_booking_flagship`,
  because hiding a button is not a control and every booking and waitlist path
  comes through that guard.
- **The status guard needed a narrow exemption.** `wp_insert_post_data` in
  `workflow.php` reverts any status change to an existing `law_event` that does
  not come from the workflow engine, which is what stops the classic editor's
  Publish button confirming an unapproved event. The flagship has no workflow —
  its two statuses mean only "on the programme" (`publish`) and "not yet"
  (`law-draft`), which is the tick box on its screen — so its saver raises a
  `law_flagship_saving` global around its own `wp_update_post` and the guard
  honours it **only** for the flagship and **only** for those two statuses.
  Deliberately a separate flag from `law_workflow_transitioning`, so nothing
  pretends a transition ran. Without this the tick box silently did nothing.

### `flagship-form.php` and `flagship-dashboard.php`: the committee's own screen (9 September 2026)

The flagship is edited from **two** places, and they are one feature rather
than two implementations: the wp-admin Flagship screen and the committee's
front-end **Manage flagship** dashboard at `/account/dashboard/flagship/`. The
committee works from the site (a member holding only `events_committee` should
not have to learn wp-admin), which is why the events review queue, Bookings,
Manage speakers and now the flagship all have front-end screens.

What makes them one feature is that neither screen owns any of the substance:

- **`flagship.php`** holds the data layer both call — `law_flagship_input_from_post()`
  (and its `_sessions_from_post()` / `_speaker_rows_from_post()` helpers),
  `law_flagship_validate()`, `law_flagship_save()`,
  `law_flagship_save_sessions()`, `law_flagship_resolve_speaker_rows()`,
  `law_flagship_form_values()`, `law_flagship_snapshot()` and
  `law_flagship_log_save()`. Each screen collects a POST, hands it to
  `law_flagship_save()` and renders what comes back.
- **`flagship-form.php`** holds the session agenda's fields —
  `law_flagship_render_sessions()`, `_render_session()`,
  `_render_speaker_picker()`, `_render_new_speaker_row()` and
  `_render_new_speaker_template()`. That repeater is the complicated half of
  the form (a monotonic clone counter, a rich-text description per row, and a
  speaker picker with an "add new speaker" sub-form inside each row), so it
  exists once and both screens print it. Its markup is class-neutral
  (`law-row`, `law-field`, `law-rel-*`) and each screen supplies the
  surrounding styles.
- `FlagshipDashboardTest::test_both_screens_share_one_write_path()` pins that
  split with reflection: if either screen grows its own saver, or a shared
  renderer moves into a screen file, the test fails. Without it, "the dashboard
  updates the same data as the admin screen" would be a promise nothing keeps.

`flagship-dashboard.php` itself is only route, gate, handler and assets:

- `LAW_FLAGSHIP_DASHBOARD_TEMPLATE` / `_PATH`, `law_flagship_dashboard_url()`
  (by path, never by ID, so a page created with a different ID on staging still
  works) and `law_flagship_dashboard_is_template()`.
- `law_flagship_dashboard_save_handler()` on `admin_post_law_flagship_manage`
  (with the `nopriv` twin answering JSON): `law_events_guard_post()` for the
  nonce, the honeypot and its own `flagship_manage` rate surface, then
  `law_user_is_committee()`, then the shared saver, then a redirect carrying
  `law_notice`. A refused save stashes the errors and the typed values in a
  one-shot transient (`law_flagship_dashboard_state()`), the same mechanism the
  speakers dashboard uses, because a redirect would otherwise throw away a
  half-typed agenda.
- The assets, and the one decision worth knowing: the page **enqueues two
  wp-admin scripts on the front end**, `law-admin.js` (the speaker search, its
  photo control, and `window.lawAdminFields.initAll()` for rows added later)
  and `law-flagship-admin.js` (the sessions repeater and the "add new speaker"
  template), plus `wp_enqueue_media()` for the banner picker. Reusing them is
  the point: the alternative is a second implementation of the same behaviour
  drifting from the first. It is safe because the AJAX endpoint
  (`wp_ajax_law_events_search_posts`) re-checks `edit_law_events` itself, so
  reaching it from the front end grants nothing, and the committee role is
  granted `upload_files` for exactly this (`capabilities.php`).
  `assets/css/flagship-dashboard.css` is the thin layer that makes those
  admin-shaped controls sit inside the light front-end form; it is scoped to
  `.law-flagship-dashboard` so it cannot reach wp-admin.
- **`assets/css/wp-media-frontend.css`** exists because of a bug this screen
  surfaced: the theme is dark-hero-first (`body { color: #ffffff }` in app.css,
  with white `h1`/`h4` and a yellow `h3`), and wp.media appends its modal to
  `<body>` outside every page section, so the media library opened white on
  white — the tabs, the "Drop files to upload" heading and the field labels
  were invisible, though the modal worked. Its selectors are deliberately NOT
  page-scoped (the modal is a sibling of the content, so an ancestor selector
  could never reach it), which is why it is a separate stylesheet loaded only
  by screens that call `wp_enqueue_media()` on the front end. Any future
  front-end media picker should enqueue it too. The values are WordPress's own
  greys, so the modal looks like the library the committee already knows.
- **The shared fields use the front-end event forms' markup vocabulary**, not
  the admin field library's: a `.law-row-grid` of `<label>Name<input></label>`
  pairs with `.law-row-wide` for full-width cells, exactly as
  `parts/events/event-form-fields.php` does. Two reasons, both from Denis on
  9 September 2026: the admin shape (`<p class="law-field"><strong>…</strong><br>`)
  stacked a block label, a `<br>` and a margin into a gap twice the size it
  should be, and the committee already knows the event forms, so a session row
  and a speaker row should look the way they do there. `law-admin.css` defines
  the same grid for wp-admin, where `event-form.css` is not loaded, so the two
  screens lay a session out identically. The "add new speaker" row is the
  event form's speaker row field for field, including the photo as its own
  labelled cell rather than a bare link beside the job title.
- **"Show on the programme" is the first control on both screens**, above the
  title: it is the decision the rest of the form hangs off.
- `law_flagship_render_sessions()` takes a `$heading` argument: the wp-admin
  screen wants its own `<h2>`, the dashboard passes `''` because its fieldset
  legend already names the block, and printing both gave the dashboard two
  "Sessions" headings in a row.
- Access is checked in **three** independent places, because each is reachable
  on its own: the header link (`header-nav.php`), the template, and the save
  handler. `parts/events/flagship-manage.php` re-checks for itself too rather
  than trusting its caller, as `parts/events/thread.php` does.

### `discounts.php` and `discounts-dashboard.php`: the discount catalogue (10 September 2026)

**Honoured by the paid receptions (14 September 2026, RECEPTIONS.md §8.4) and
by the flagship conference (15 September 2026, FLAGSHIP_PAYMENTS.md §13).**
The catalogue was built on 10 September and left deliberately unwired, because
Denis had settled that codes were not wanted on the flagship, whose price is
the committee's decision at approval rather than the delegate's at checkout. He
reversed that on 15 September once the receptions had proved the machinery. In
both flows the code is typed in the dialog, the total recalculates in place
through the shared `law_booking_quote()`, and the committee can scope a code to
either. Hosted events are free to attend, so nothing there has a price to
discount.

- A fifth CPT, `law_discount`, engine-write-only like bookings. The post title
  is the code as the committee typed it (hyphens and all); the slug is
  `law_discount_match_key()`, letters and digits only, which is what every
  lookup compares. That asymmetry is deliberate: a code printed
  "LAW-WEEK-25" gets typed "law week 25", and treating those as different
  codes would lose people their discount. The consequence, two codes
  differing only by punctuation colliding, is right — a human could not tell
  them apart either — and the duplicate check refuses the second.
- `law_discount_validate()` answers "usable, here, now, by this person, at
  this price" and changes nothing; `law_discount_apply()` does the
  arithmetic, clamped so a fixed code larger than the price makes a booking
  free rather than a credit. **Every** refusal gives the same message, "That
  discount code is not valid" (Denis, 15 September 2026, reversing the
  receptions' deliberate decision to keep "expired", "not yet" and "used up"
  apart): distinct wording told a guesser that a string they tried is a real
  code. The `WP_Error` codes still differ and `law_booking_log_refusal()`
  writes them into the activity log, so the committee keeps the diagnosis
  without the delegate being told. Losing the race for a LAST use at claim
  time is the one deliberate exception — the code has already validated, so
  there is nothing left to leak.
- `law_discount_claim()` / `_release()` move the usage counter with a
  conditional `UPDATE`, not a read-then-write. A code can be shared across
  events, so two callers holding two different event locks could otherwise
  both read "9 of 10 used" and both take the tenth. The release is floored at
  zero in SQL, or a double release would wrap the UNSIGNED cast into an
  effectively unlimited code.
- The catalogue is at `/account/dashboard/discounts/`, committee-only, gated
  in three independent places, with the CSV/Excel/PDF trio. The screen says
  where a code bites — "Codes are accepted when registering for the flagship
  conference and when booking a paid reception" — because that is the first
  question anybody creating one has. An empty "Applies to" means every paid
  event; `law_setup_scope_existing_discounts()` ran once at the flagship
  cutover to pin the codes that predate it to the receptions they were written
  for, so none of them silently gained £550 of reach.
- **How a flow opts in**: call `law_discount_validate()`, then
  `law_discount_apply()` and `law_discount_claim()` under its own lock,
  releasing on any refusal or cancellation, and add its event to the
  `law_discount_scope_events` filter. `discounts.php` knows nothing about the
  flagship or the receptions, so wiring in each of them needed no changes here:
  `receptions.php` registers every PRICED reception through that filter and
  `flagship-bookings.php` registers the flagship while it has a price (a free
  event has nothing to discount, and offering it would let the committee build
  a code that can never apply). The only guard added was in
  `law_discount_input_from_post()`, which now keeps a posted scope to what is
  on offer PLUS what the code already carries — the first half stops a forged
  checkbox pinning a code to an arbitrary post, the second stops an edit
  silently dropping a scope whose event has since come off sale.
- The claim belongs to the flow; the release is one shared function. Both
  flows claim before the booking exists, so a lost race refuses with nothing
  written. `law_booking_release_discount()` (`bookings.php`) holds the release
  rule in one place — it deletes `_law_discount_id` and keeps the code and the
  amount, which is what makes a double release a no-op rather than a theft of
  somebody else's live claim, and it refuses on a PAID place, because the code
  really was spent. It is called from `law_booking_cancel()` and from the
  flagship's decline, withdraw and abandonment paths, which change status
  directly and would otherwise each have grown a copy.
- **Nothing to pay means no payment step** (Denis, 15 September 2026), on every
  surface. A flagship registration a code covers in full skips Stripe entirely
  and carries the `no_charge` payment state: not `pending_setup` (the
  abandonment sweep closes that) and not `complimentary` (that is the
  committee's gift, and drives a "with our compliments" email). So does a
  reception waitlist entry: it joins as `no_charge`,
  `law_waitlist_check_promotable()` accepts that alongside `ready`,
  `law_waitlist_seat()` takes no charge claim for it, and
  `law_reception_charge_promoted()` confirms it with no Stripe call. The test
  is the BOOKING's amount, never `law_event_is_priced()`, which is true of a
  place a code has taken to nothing. See FLAGSHIP_PAYMENTS.md §13.3 and
  RECEPTIONS.md §6.1.
- Fixed on the way (15 September 2026): a promoted waitlist delegate was
  getting TWO confirmations and two calendar invitations, because
  `law_reception_mark_paid()` ends by sending the generic
  `user_reception_confirmed` and the promotion sends its own on top.
  `law_reception_claim_promotion_email()` takes the `_law_confirmation_sent`
  latch first, so the promotion's email is the single one; the committee is not
  left out, because a pass sends them one summary rather than a message per
  entry.
- Tests: `tests/DiscountsTest.php` for the catalogue's own rules and the scope
  list, `tests/QuoteTest.php` for the shared quote and its guard, and the
  claim/release lifecycle in each flow's own suite.

### `flagship-bookings.php`: the flagship's application flow (10 September 2026)

The approval-gated flow from EVENTS_4.2_SPECS.md §5, as settled in
FLAGSHIP_PAYMENTS.md. A delegate applies and saves a payment method; the
committee approves or declines; approval charges it off-session and confirms
the place.

**Discount codes, since 15 September 2026** (FLAGSHIP_PAYMENTS.md §13,
reversing the 10 September decision that this flow would never take one). The
registration dialog carries the same code field and four-line receipt block as
the reception checkout, driven by the shared `law_booking_quote()`;
`law_flagship_quote()` adds the one flagship rule, that a LIST price under 1p
means "not on sale" rather than "free", so only a code can make a registration
free. The code is claimed inside the event lock before the booking is
inserted, the DISCOUNTED net is snapshotted into `_law_price_pence` so
`law_stripe_charge_booking()` needed no change, and decline, withdrawal and
the 48-hour abandonment sweep each give the use back through
`law_booking_release_discount()`.

A code covering the whole price skips Stripe entirely: no setup session, no
payment method, `_law_payment_status = 'no_charge'`, and
`law_flagship_mark_ready()` — extracted from `law_flagship_on_card_saved()` for
exactly this — puts it in the committee's queue and sends the acknowledgements.
Without that extraction a free registration would have sat there with nobody
told it existed, because the only thing that ever announced a registration was
the card arriving. `price_shown` changed from the net to the GROSS at the same
time: one hidden field can hold one figure, and the total is what the delegate
is looking at.

**Not necessarily a card** (Denis, 10 September 2026, and spec §7.1).
`payment_method_types` is left unset on the Checkout session, so Stripe offers
whatever the LAW account has enabled that can be saved and re-charged
off-session — card, Link and Revolut Pay on staging; production is LAW's to
configure. Nothing downstream assumes a card:
`law_stripe_method_label()` describes whatever Stripe returned and falls back
to a humanised type name for a method nobody has seen yet, and
`law_booking_payment_method_label()` is what every surface reads
(`law_booking_card_label()` remains as an alias). The corollary is that a
charge may not settle inside the approval request, which §4.3 of
FLAGSHIP_PAYMENTS.md handles as a distinct `processing` state rather than as a
failure.

**Why it is not the bookings engine with an extra status.** Nothing here is
automatic, which is the whole point of an approval gate. `waitlist.php`
promotes and charges the moment a place frees — exactly the behaviour that
must not happen — so `law_waitlist_process()` refuses the flagship outright,
and `law_booking_guard_open()` keeps its flagship refusal so the hosted
booking form, "add a colleague", register-on-behalf and the waitlist can never
reach it. What IS shared is reused: the booking post type and its numbering,
the duplicate guard, the event lock, the places recount, the account
resolve-or-create, the log, the email registry and the `.ics` generator.

- `law_flagship_apply( $user_id, $input )` → `{booking, redirect}`. Guards,
  then **snapshots the price** onto the booking and returns the Stripe
  Checkout URL. The snapshot is the point: the delegate consented to a figure,
  so that figure is charged even if the list price has risen by the time the
  committee gets to them (`law_event_snapshot_fee()` sets the precedent).
  **No clash guard** — the flagship runs all day and every other event that
  day would collide — and **no capacity guard**, because a full conference
  still takes applications and queues them (Denis).
- `law_flagship_approve()` charges and confirms. Over-booking is allowed but
  never accidental: past `_law_tickets_available` it returns
  `law_flagship_full` until the caller passes `confirm_overbook`, then logs
  `flagship_overbooked` loudly with the counts and the actor.
- `law_flagship_mark_paid()` is the **single** path to a confirmed place, is
  idempotent, and is called by both the synchronous charge and the
  `invoice.paid` webhook, in whichever order they arrive.
- `law_flagship_decline()` and `law_flagship_withdraw()` both **detach the
  saved payment method**: consent was to hold it while the application was
  live, so the consent ending has to actually remove it, not merely stop
  using it. Neither works on a paid place — that is a refund, and a refund is
  a conversation — and `law_flagship_withdraw()` also refuses while a charge
  is still settling (`law_flagship_payment_in_flight`), since voiding an
  invoice under a live payment would leave money moving with nothing to book
  it against.
- `law_flagship_cancel_confirmed()` is the other end of the same decision
  (17 September 2026, FLAGSHIP_PAYMENTS.md §4.6): the committee releasing a
  place that IS confirmed, for a delegate who has paid and then dropped out.
  Deliberately narrow and deliberately silent (Denis, 17 September 2026): it
  moves the booking to `law-cancelled` under the event lock, releases the
  discount claim by the usual rule, recounts, takes back any included reception
  places and logs what was paid. **No Stripe call and no email to the delegate**
  — the refund and the conversation are the committee's by hand, which the
  confirm dialog on the row says before it is pressed. It refuses anything that
  is not `publish` (`law_flagship_not_confirmed`) and points at Decline, which is
  the path that clears the saved card and voids the invoice. Without it a paid
  delegate who pulled out held a place nobody could release, and a Stripe refund
  does not free one: `law_flagship_mark_refunded()` says so in the log.
- `law_flagship_add_complimentary()` is the committee's "add without payment"
  for speakers, press, sponsors and VIPs: straight to `publish`, no payment
  method, no invoice, marked `_law_is_complimentary` so counts and exports can
  tell them apart. Its dialog collects country, accessibility and dietary as
  well (Denis, 11 September 2026), through the shared
  `parts/events/attendee-profile-fields.php`.
- `law_flagship_substitute()` hands a CONFIRMED place to somebody else
  (21 September 2026, the client's ask). A firm bought a ticket for a partner
  who cannot come and is sending a colleague; before this the only route was to
  cancel the confirmed place and add the replacement as a complimentary one,
  which threw the payment trail away and filed a paying delegate as a freebie.
  **The money does not move**: no Stripe call is made at all, and every
  `_law_stripe_*` key still describes the original payer, because the receipt
  records who paid and re-addressing it to somebody who paid nothing would
  mislead whoever handles a refund. `post_author` is what moves, since that is
  the canonical attendee everywhere here. Confirmed places only: an application
  under review carries a payment method its owner consented to, so it is
  declined and the replacement registers afresh. Deliberately does **not** call
  `law_flagship_guard_open()`, which the apply and comp paths do — that refuses
  once `_law_start` has passed, and a substitution routinely happens on the
  morning. See the change-history entry below for the rest.
- `law_flagship_mark_payment_processing()` is the third outcome of a charge,
  beside paid and failed: Stripe accepted the payment but it has not settled.
  The application stays where it is holding its place, nothing is emailed, and
  `invoice.paid` confirms it when the money lands. Only reachable for methods
  that do not settle instantly, which is why it exists at all.
- `law_flagship_mark_payment_failed()` records a decline or an SCA request,
  and emails once per failure rather than once per webhook, because Stripe can
  report the same decline through more than one event type.
- **Bulk review is time-boxed and cron-resumable** (`law_flagship_review_bulk()`,
  `law_flagship_resume_review`): approving forty applications means forty
  round trips to Stripe, which would time out half-done and leave delegates
  charged but unconfirmed.
- A daily cron (`law_flagship_daily`) closes applications whose card never
  arrived after 48 hours, and alerts the committee about a payment still
  unpaid past its window. Neither auto-declines anybody: the module's standing
  posture is that a human decides.
- Handlers, all on `law_events_guard_post()`: `law_flagship_apply`,
  `law_flagship_update_card`, `law_flagship_withdraw` (the delegate's own),
  and `law_flagship_review`, `law_flagship_retry_charge`,
  `law_flagship_resend_payment`, `law_flagship_add_attendee`,
  `law_flagship_cancel` (committee only, on their own `flagship_review` rate
  surface). The return from Stripe is a
  `template_redirect`, not an admin-post: Stripe composes that GET itself and
  carries no nonce of ours, which is safe because the session is looked up in
  Stripe and refused unless its metadata names that booking.

### `flagship-bookings-dashboard.php`: the committee's Flagship bookings page (10 September 2026)

`/account/dashboard/flagship-bookings/`, committee-only, gated in three
places. **A separate page from Manage bookings on purpose** (Denis, 10
September 2026): a hosted booking is free, instant and reversible, a flagship
application is a priced request that is reviewed, charged, declined by a bank
and chased. One table for both would be a dozen columns blank on most rows
and two sets of actions that do not apply to each other.

- Manage bookings therefore EXCLUDES the flagship, through the
  `law_bookings_dashboard_exclude_events` filter, so that file stays about
  every OTHER kind of booking and this one owns every "the flagship is
  different" rule. Receptions are deliberately NOT excluded (RECEPTIONS.md
  §8.3): a reception place is a booking at an event, and it belongs with the
  rest. That is why the nav item went back to **"Manage bookings"** on
  14 September 2026 — "Hosted bookings" named one of the two kinds it holds,
  which is worse than the generic name it had replaced.
- A flat table with the payment facts a refund has to be traced by, including
  the Stripe invoice link (spec §7.5). Filters: keyword, status, payment
  state, attendee type (complimentary places) and, since 15 September 2026,
  ticket type. There is no country filter: it was removed outright rather than
  left reachable by URL only. Per-row Approve and Decline, per-row Cancel on a
  confirmed place (`law_flagship_cancel_confirmed()`, above), the two
  failed-payment actions, select-all bulk decisions, and the "add without
  payment" dialog, opened from the actions row above the table. Cancel has no
  bulk form on purpose: every one of them is a refund and a conversation the
  committee then has to have with a named person.
- **Ticket type** (Denis, 15 September 2026, from the client: "Back end use
  only — Delegate, Sponsor, Speaker, Exhibitor, Committee"). A column whose
  cell is one inline control: "Add type" with a pencil until somebody
  classifies the delegate, then the type with the same pencil. The vocabulary
  is `law_booking_ticket_types()` (`statuses.php`) and the value is
  `_law_ticket_type` on the booking; `law_flagship_set_ticket_type()`
  (`flagship-bookings.php`) is the only writer, and it logs the change against
  the booking so the wp-admin Activity box picks it up. It classifies and
  nothing more: no price, capacity, status, email or guard reads it, and the
  delegate is never shown it.
  - **One dialog for the whole table**, `parts/events/flagship-ticket-type.php`,
    rendered outside `#law-cal-events` beside the add-attendee dialog so a
    filter swapping the table cannot destroy it. Every other confirm dialog
    here is per row because Approve and Decline say different things about
    different people and money; this one says the same thing about everybody
    and the row is a hidden field. That distinction earns its keep: Approve and
    Decline only render on rows a decision can still be made on, but a ticket
    type can be set on ANY row, so a dialog per row would mean one on every row
    of a list that runs to hundreds. `assets/js/flagship-ticket-type.js` (~60
    lines, delegated at the document) fills in the booking, the delegate's name
    and the current value from the pencil that was pressed. No fetch, so the
    dialog opens in the same frame as the press and needs no skeleton.
  - **The edit does not reload the page.** `law_flagship_ticket_type_handler()`
    answers with the cell's own markup under a new generic `cell` key, and
    booking-form.js swaps that node, closes the dialog and hands focus to the
    replacement (`[data-law-refocus]`). One renderer,
    `law_flagship_ticket_type_cell()`, draws the cell for the table and for
    that response, so the two can never disagree. It is the thread-bubble
    pattern from `comments.php` — return the rendered markup, swap the node —
    rather than the waitlist reorder, which rebuilds state in JavaScript and is
    the hardest code in that file to keep right. A cell no longer on the page
    (the filter moved) falls through to the ordinary reload.
  - **`parts/layout/modal.php` gained a select** for this (`'type' =>
    'select'` with `options` and `placeholder`), rather than a second
    hand-written dialog skeleton. Same name, same ships-disabled behaviour,
    same `data-law-modal-field` hook, so `law-modal.js` needed no change at
    all. It is also a ninth deliberate **Apply** button, on top of the eight
    listed under the applicant/delegate rename below.
  - **Without JavaScript** every pencil stays `hidden` (`data-law-modal-enhanced`)
    and each cell's `<noscript>` select posts the same action instead, so the
    column is never read-only for somebody with scripts off.
- Exports CSV / Excel / PDF. The three formats share
  `law_flagship_bookings_export_rows()` in THIS file, which owns the columns,
  the rows and the title; only the CSV and XLSX writers
  (`law_events_send_csv()` / `law_events_send_xlsx()`) come from
  `functions/events/export.php`, and the PDF is built client-side from the
  handler's `format=json` branch. Ticket type sits with the other
  classification columns, after Complimentary.
- Tests: `tests/FlagshipBookingsDashboardTest.php` (20).

### `receptions.php`: the drinks receptions (14 September 2026)

RECEPTIONS.md, in full. LAW runs three receptions during the week: Monday and
Wednesday are **paid and pay-now**, with no committee review, and both are
also free with a confirmed flagship place; Friday is **invitation only** and
takes no bookings at all. A reception is an ordinary `law_event` carrying
`_law_is_reception`, so the programme, the event page, the calendar, capacity,
the per-event bookings list and the exports all work with no change.

**The shape, and why it is a third one.** A hosted place is free and instant.
A flagship place is applied for, reviewed, then charged. A reception place is
BOUGHT: the delegate goes to Stripe's hosted page, pays, and comes back with a
confirmed place and a VAT invoice. That is a different promise, so it has its
own guards, its own meaning for the statuses and its own handlers. Everything
genuinely shared is reused rather than copied — the booking post type and its
numbering, the duplicate guard, the event lock, the recount, the account
resolve, the activity log, the email registry, the `.ics` generator, the whole
of `stripe/attendees.php`, and the waitlist.

- **Meta.** `_law_is_reception`, `_law_attendee_price_pence` (NET of VAT, 0 =
  not on sale) and `_law_flagship_included` on the event; on the booking,
  `_law_discount_id` / `_code` / `_pence`, `_law_included_with`,
  `_law_reception_choices` (on a flagship application), the live
  `_law_stripe_checkout_session_id`, `_law_checkout_expires_at` and
  `_law_paid_at`. Invitation-only is NOT a flag of its own: it is the reserved
  `_law_registration_state` vocabulary, which this is the first reader of.
- **`law-pending-payment`**, a new booking status: a place HELD while somebody
  stands on Stripe's page. It is in `law_booking_holding_statuses()` and, **on
  a priced event only**, in the recount — without that the last place would
  sell twice in the forty minutes a Checkout session lives. A hosted event's
  and the flagship's recount queries are left literally unchanged rather than
  merely equivalent, because that query is what every surface trusts.
- **`law_reception_quote()`** is pure: it reads, writes nothing and claims
  nothing. That is what lets the same function render the dialog, answer the
  live Apply endpoint and be re-run inside the checkout handler — which is
  the point, because the client can never send its own price. Its body moved
  to `law_booking_quote()` (`bookings.php`) on 15 September 2026 when the
  flagship started taking codes, and this name is now a one-line wrapper: the
  sum turned out to be the same sum, because `law_event_price_pence()` already
  routed the flagship's time-switched price.
- **`law_reception_checkout()`**, in this order: the cheap refusals first; the
  discount code CLAIMED before the booking exists (one conditional `UPDATE`
  decides a last use, with nothing to roll back if we lose); the hold inserted
  under the event lock; Stripe called AFTER the unlock, because a 30-second
  network call must never queue the next buyer behind it. A Stripe failure
  releases the hold and the code. A 100% code confirms with no Stripe call at
  all.
- **`law_reception_mark_paid()`** is the single idempotent path to a confirmed
  place, reached from the browser return, `checkout.session.completed`,
  `checkout.session.async_payment_succeeded` and `invoice.paid`, in any order
  and concurrently. Its early return tests BOTH `publish` and `paid`: a
  waitlist entry is seated as `publish` / `processing` while its charge runs,
  and returning on the status alone would leave a place given away with the
  money unrecorded. Money landing on a cancelled booking never seats anybody
  and emails `committee_reception_paid_cancelled`.
- **The confirmation waits for the invoice URL**, behind a one-shot latch
  (`law_booking_claim_latch()`), because a receipt is what a firm reclaims VAT
  against and a card receipt is not. Whichever of the session and the invoice
  arrives second sends; if `invoice.paid` never comes, the sweep sends without
  a link fifteen minutes later.
- **Releasing a hold** asks Stripe to expire the session FIRST, so a page
  somebody still has open cannot be paid for a place that no longer exists. If
  Stripe answers that the session is already `complete`, the money landed in
  the race and it confirms instead of cancelling. No email: they left the
  page.
- **Included places.** A confirmed flagship ticket (`paid` or
  `complimentary` — a committee comp place is a real ticket) grants the
  receptions flagged for it, with **no capacity guard**: the ticket promised
  the place and the committee sizes the room, so an over-booking is logged
  loudly and emailed rather than refused. A place already held is reported,
  never refused. A full refund on the ticket takes them back.
- **The banner** offering them is ONE helper, `law_reception_banner()`,
  rendered on both My bookings and the Account hub, because the hub is where a
  sign-in now lands and two copies of the copy would drift. Two surfaces means
  two sets of assets: `.law-strip__cta` and `.law-hp` were both scoped to
  stylesheets or wrappers only My bookings had, which is why the hub drew a
  small button and a visible honeypot until 15 September 2026.
- **The paid waitlist.** Joining saves a payment method (Checkout in setup
  mode) and snapshots the price, so the delegate is charged the figure they
  agreed to. `waitlist.php` is modified IN PLACE rather than forked, because
  the wp-admin backstops call `law_waitlist_process()` by name and a parallel
  pass would never run for a reception queue. A priced entry without a saved
  method is skipped in place and told once; a `ready` one is seated as
  `processing` with its charge claim taken under the same lock, and charged on
  the new `law_waitlist_seated_after_unlock` hook. A decline frees the place
  and SCHEDULES a resume rather than recursing — a queue of dead cards would
  nest without bound in one request.
- **The hourly sweep** (`law_reception_sweep()`) releases holds past their
  expiry plus a ten-minute margin (skipping anything `processing`, whose money
  is in flight), sends delayed confirmations, reconciles orphaned charges,
  closes queue entries that never saved a method after 48 hours, expires the
  retry window and alerts on a payment that has been settling for days. It
  also runs opportunistically after every webhook, bounded to five rows,
  because this site runs on pseudo-cron and **a money path must not wait for
  somebody to load a page — production needs a real cron hitting
  `wp-cron.php`.**
- The delegate cannot cancel a **paid** place: refunds are manual, and
  self-service cancellation would free the place while leaving the money with
  LAW and nobody told. Enforced in `law_booking_cancel()`, not only in the UI.
- Tests: `tests/ReceptionsTest.php` (19), `tests/ReceptionCheckoutStripeTest.php`
  (7), `tests/ReceptionWaitlistTest.php` (12), `tests/ReceptionInclusionTest.php`
  (9).

### `receptions-dashboard.php`: the committee's Manage receptions screen (14 September 2026)

`/account/dashboard/receptions/`, committee-only, gated in three places. List
plus edit on the Manage speakers pattern (`?law_reception=<id|new>`).

**TWO SHAPES, on purpose** (Denis, 14 September 2026). The LIST is a dashboard
table like every other committee list: hero title, then a white page section,
because a table of facts belongs on white. The EDITOR is the SUBMIT-AN-EVENT
form's shape — the filled navy hero, `.auth-hero`, `.law-event-form`, the same
markup contract as `templates/account-event-form.php` — because a reception IS
an event and editing one should look like it. It prints the reception's name
and no status: the submission form carries one because a host's event moves
through a queue, and a reception's two statuses mean "on the programme" or "not
yet", which is the tick box below.

**No export trio, unlike every sibling dashboard** (Denis, 14 September 2026):
there are three receptions and every figure is on the screen already, so a
CSV/Excel/PDF row over a three-line table is furniture — and it kept ~3MB of
pdfmake in the page to produce it. The BOOKINGS at a reception do export, from
Manage bookings and from the per-event list, which is where somebody wanting a
spreadsheet of people actually goes.

- It edits the SAME posts through the SAME code as the wp-admin **Reception**
  meta box: `law_reception_input_from_post()`, `_validate()`, `_save()`,
  `_form_values()`, `_snapshot()` and `_log_save()` all live in
  `receptions.php`, and `ReceptionsDashboardTest` pins them there by
  reflection. The box saves with `partial => true`, which writes only the keys
  it rendered.
- The edit form: show on the programme (first, because everything else hangs
  off it), title, description, date and the two times (written straight into
  `_law_start` / `_law_end` with `_law_slot_label` left empty, as the flagship
  does), venue, places, price in pounds, "included with the flagship place"
  and "invitation only". **Invitation only DISABLES the price and places
  rather than hiding them, and keeps the stored values**, so a reception that
  goes by invitation does not silently lose the price it charged.
- A checkbox sentinel rides with the first fields: PHP truncates a long POST
  from the END, and a truncated save must not read as "untick everything" and
  take a reception off the programme.
- **A save returns to the reception it saved** (Denis, 17 September 2026), at
  `?law_reception=<id>` with `law_notice=reception-saved` above the form,
  rather than bouncing to the list. A save is rarely the last thing done to a
  reception, and the list meant pressing back into the editor to carry on. A
  NEW reception lands on its own editor, so the ID comes from the saver's
  return value; the no-JS path redirects explicitly rather than through
  `law_events_respond()`'s redirect-back, which would send a new reception back
  to the empty "Add a reception" form.
- The places column is three figures, not one — confirmed, awaiting payment,
  left — because on a priced event a hold counts towards the total sold.
- Its stylesheets are the ones every account screen needs, and the page has to
  be named in THREE separate gates to get them: `calendar.css`
  (`functions/enqueue.php`, which supplies `.law-dashboard`'s text colour and
  the tables), `event-form.css` (`submission-form.php`, the form vocabulary —
  `.law-row-grid`, `.law-form-field`, `.law-form-hint`, `.law-form-buttons`)
  and `auth.css` (`enqueue.php` again, the navy hero the editor renders in).
  Missing from any of them the screen renders white on white, which is exactly
  what happened on 14 September 2026 until Denis saw it. The same trap caught
  the account hub, which renders the receptions banner and was not on the
  calendar list either.
- `law_flagship_saving` generalised to **`law_event_managed_saving`**, honoured
  for any event LAW runs itself (`law_event_is_managed_by_law()`, in
  `workflow.php`) and still only for `publish` and `law-draft`. The old flag
  stays honoured, so a half-deployed tree cannot lose the exemption.
- **Provisioning goes in both routes**, as the house rule requires: migration
  step 10 (inside it — `retire_roles` must stay last) and the
  `?setup-account-pages` trigger, plus the dashboard's own first open. Three
  `law-draft` records seeded by slug with **no price**, because the prices are
  LAW's to confirm.
- Tests: `tests/ReceptionsDashboardTest.php` (11).

### `external-events.php`: events LAW neither runs nor books (15 September 2026)

The third kind of `law_event` the committee owns outright, after the flagship
and the receptions. LCIA's Tylney Symposium, GAR Live, the CIArb Alexander
Lecture and Law Rocks all run during the programme week, register people on
their own websites, and belong on the LAW programme because a delegate planning
their week needs to see the whole week. A colleague captured them on Gravity
Forms **form 10 (Event > external events)** in September 2026; this module
replaced that form and migration step 3b brought the four entries across.

**The data.** Three keys, all in `law_event_meta_schema()`:

- **`_law_is_external`** (`flag`) — **renamed from `_law_is_law_event`, with the
  opposite meaning**. The old key meant "LAW runs this itself", was written by
  the committee's Classification tick and by the three receptions, and was read
  by nothing except a badge, an export column and the Organiser filter:
  `law_event_is_managed_by_law()` has always read the flagship and reception
  flags, never this one. The identity the committee actually needed to tag was
  the external one (Denis, 15 September 2026), so the key was repurposed rather
  than a second one added. The polarity is inverted, so the receptions no longer
  write it — LAW runs and books those. No data migration was needed: the CPT
  module was not live anywhere when the rename landed.
- **`_law_external_url`** (`url`) — where Register goes. **Empty is a
  legitimate state**, not a defect: the committee lists an event before its
  organiser opens registration, and the listing then shows a disabled
  "Registration opening soon" button rather than nothing at all.
- **`_law_registration_state`** = `'external'`, the value the vocabulary has
  reserved since 4.1 and nothing wrote until now.

**What makes one different**, and where each difference is enforced:

| | Where |
|---|---|
| No workflow: created published or draft, no approval | `wp_insert_post()` on a NEW post passes straight through the status guard (`workflow.php`); a later status change needs `law_event_is_managed_by_law()`, which now answers yes for `_law_is_external` |
| No slot: a typed date and two times | `law_external_event_apply_when()` writes `_law_start` / `_law_end` and leaves `_law_slot_label` empty, exactly as `law_reception_save()` does |
| No booking here | `law_event_is_external()` and the refusal in `law_booking_guard_form_open()` — the create handler, add-attendee, register-on-behalf and the `?law_dialog=1` fragment server all run through it |
| Register links out | `law_booking_resolve_state()` resolves `'external'` immediately after `'invitation'`; `law_booking_external_button()` builds the link; `law_booking_card_action()` returns `arrow` + `external` and **no `dialog` key**, which is what stops `booking-form.js` binding its fetch to a link that leaves the site |
| No fee, invoice, tickets, co-owners or contacts | Simply not on the form. All four entries migrated from form 10 left every one of those fields empty |

**The screen.** `/account/dashboard/?law_external=<id|new>`: a branch of Manage
events, not a dashboard of its own. An external event IS one of the events that
dashboard lists — same table, same Organiser filter, same export — and a page of
its own would be database state to provision on every environment for a screen
already there. "Create an external event" sits above the filter bar in the
`.law-dashboard__actions` / `.button.orange` shape the receptions and discounts
dashboards use.

A **full page rather than a dialog**, against the site's usual add-in-a-dialog
rule and deliberately (Denis, 15 September 2026): this form carries a repeating
speakers table and a whole session agenda, and two of the four migrated events
have eight sessions and thirteen.

**Shared, not copied.** The Speakers and Session agenda repeaters were lifted
out of `parts/events/event-form-fields.php` into
`parts/events/event-form-speakers.php` and `parts/events/event-form-agenda.php`,
which the host form, the committee edit form and this one all render; the savers
are `law_events_form_save_speakers()` and `law_events_form_save_sessions()`
unchanged. The agenda's committee gate stayed with the *caller*, because an
external event always has the section (the committee is transcribing a published
running order) while a host only gets one once the committee asks.

**Drafts are visible to the committee**, which needed a change to
`law_committee_events()`. That function excludes `law-draft` because a host's
draft is their own private, unsubmitted data; an external draft is the
committee's own and means only "not on the programme yet". A second query, on
`law-draft` plus `_law_is_external`, is merged into the first and re-sorted; an
explicit `?law_status=law-draft` returns that set alone, and the filter option
says **"Draft (external)"** so it is clear which drafts it means.

**An end before its start is dropped, not stored.** Form 10 entry 1559, Law
Rocks! LONDON 2026, was captured as 19:45 to 11:30 — a night running past
midnight, which this model cannot express and must not guess a second date for.
Both the saver and the migration step write the start, leave the end empty so
the programme reads "7:45pm onwards", and log the fact. Silently dropping it
would be the committee never finding out.

**No way into a bookings list.** The committee table suppresses both the
bookings count and the Bookings button on an external row
(`parts/events/dashboard-list.php`). Neither can ever lead anywhere, and a
hollow "0" reads as "nobody has booked" rather than "bookings do not happen
here" (Denis, 15 September 2026). The event page's panel is one line for the
same reason: the Register button beside it already says where it goes.

**Four things the browser pass caught (15 September 2026)**, all in the shape
of "the server was right and the screen was not":

1. Only Title and Description were checked in the browser, so the committee
   fixed those, resubmitted, and only then heard about Event type and Date. The
   fields now carry `required` and "Save as draft" carries `formnovalidate`, the
   arrangement the host form already uses and which `law-rich-text.js` honours.
2. A successful save landed back on a blank create form with no word that
   anything had happened. `law_events_respond()`'s no-JS path is
   `law_events_redirect_back()`, which returns to the referer and ignores the
   `redirect` key that only the AJAX path reads, so the handler now redirects to
   the list itself; the form branch renders the notices too, for a refused save.
3. The disabled "Registration opening soon" button carried no class on a card,
   so it painted the theme's default blue while the same button on the event
   page was a muted orange.
4. Preview was offered on an external draft but `?preview-event=` refused every
   `law-draft`, and `law_events_map_post()` drops one unless asked with
   `array( '*' )`. Both now carve out the external case.

- Tests: `tests/ExternalEventsTest.php` (21).

### `source.php`: the CPT ↔ front-end bridge and legacy continuity

- `law_events_cpt_mapped_events()`, `law_events_map_post()`: turn `law_event`
  posts into the calendar-event array the shared templates expect. The list
  shape carries **raw** content; the excerpt strips tags and the keyword filter
  strips tags, so `the_content` (wpautop/shortcodes/embeds) runs only once, in
  the hydrate step for the single view — not for every event on the programme.
- `law_events_event_url()`, `law_events_post_term_name(s)()`: permalink and
  taxonomy helpers.
- `law_event_sector_summary()`: the "Sector: note; Sector" one-liner (the
  "please specify" answers inline after their sector, mirroring the form's
  conditional fields). Used by the dashboard detail view and the exports.
- `law_events_organisation_titles()`, `law_event_organisation_names()`: the
  ID => title map of every `organisation` post (publish **and** private, matching
  `law_calendar_sponsor_organisation_ids()`), and an event's linked
  organisation names in the stored order. One query for the whole map rather
  than a `get_post()` per ID, so the export stays free of N+1 lookups; an ID
  with no matching post renders as `#<id>` so a stale link stays visible.
  Both caches are keyed on `wp_cache_get_last_changed( 'posts' )` rather than a
  plain static, so a request that creates or renames an organisation does not
  read a stale map. Used by the dashboard detail view, the export and the
  activity log.
- `law_events_post_is_sponsored()`: the sponsored flag (sponsor tier, a
  sponsor-category organisation, or a repeat approved/confirmed host this year)
  — parity with the legacy calendar logic. On the front end it now shows as the
  orange fill and border of a sponsored programme card
  (`.law-event-card--sponsored`); the old "Sponsored" pill was removed on
  11 September 2026 in favour of the stronger surface treatment.
- `law_events_cpt_author_counts()`: per-host counts for the sponsored rule.
- `law_events_cpt_hydrate()`: adds speakers, sessions and the **rendered**
  description onto a mapped event for the single view.
- `law_events_cpt_field_choices()`, `law_events_cpt_speakers()`,
  `law_events_cpt_speaker_profile()`: filter dropdowns and the speaker archive
  in CPT mode.
- `law_events_post_by_legacy_entry()`, `law_events_resolve_event_post_id()`,
  `law_events_resolve_speaker_id()`: resolve a reference that may be a post ID
  or a legacy GF entry ID — via the `law_events_entry_map` option, **falling
  back to the durable `_law_gf_entry_id` meta** if that option is stale, so
  legacy URLs and Stripe webhooks keyed by entry ID keep resolving.
- A `template_include` filter routes single `law_event` posts to
  `templates/event-single.php`; two `template_redirect` filters 301 legacy
  `?event=<entry id>` and `/speakers/<entry id>/` URLs to the new permalinks.
  **Both are scoped to the public page the legacy links pointed at** — the
  programme (`templates/calendar.php`) and the Speakers page
  (`templates/speakers.php`) — because entry IDs and post IDs overlap
  numerically, so an unscoped redirect hijacks an internal link to a different
  record. The `?event=` branch was scoped from the start; the speaker branch was
  not, and `law_speaker` is a **registered public query var**, so it fired on
  any page carrying it. Found 9 September 2026 when Manage Speakers began
  addressing a speaker as `?law_speaker=<post ID>`: every Edit link 301'd to
  some other speaker's public profile. Scoped the same way as the events
  branch.
- **The pre-launch Members gate** (a `template_redirect` action, priority 4):
  single event and speaker pages are only as public as the Programme page
  (page 622, Programme, Members-restricted to admin/editor/committee until
  launch). Logged-out visitors are redirected to login; a logged-in visitor
  who fails the check gets `set_404()`. Two properties added 6 September 2026:
  an event's author and co-owners bypass the gate for **their own event** via
  `law_user_can_manage_event()` (so the dashboard's "View listing" button
  works pre-launch — committee passes the same check), and the 404 path now
  renders the theme's `404.php`. Before that template existed the fallthrough
  was `index.php`, which looped the still-queried post and leaked the gated
  event's title and description to any logged-in attendee.

**Flagship additions (9 September 2026).** The `template_include` filter now
has two branches: the flagship returns `templates/flagship-event.php`, every
other event `templates/event-single.php`. `law_events_map_post()` gained
`is_flagship`, and for the flagship only: `date` falls back to
`law_flagship_date()` when `_law_start` is empty, and the no-start
`time_label` reads "Times to be announced" rather than "Slot not confirmed".
Both matter because a flagship nobody has written an agenda for yet has no
start at all, and the "public calendar hides unscheduled events" rule would
otherwise drop the week's main event off the programme. `law_calendar_map_entry()`
(the legacy shape) carries `is_flagship => false` purely so the two maps stay
identical in shape; every consumer reads `! empty( $event['is_flagship'] )`.

### `edit-lock.php`: the front-end edit lock

Core's post lock (`_edit_lock`, `"<unix time>:<user id>"`, live for 150
seconds) applied to the front-end event form, replacing GravityView entry
locking. **Added 9 September 2026 because the theme had only a third of the
mechanism**: it took the lock on render and then neither refreshed nor released
it, which made the lock simultaneously too sticky and too weak. Merely opening
an edit view — a committee member glancing at an event — blocked everyone else
for the full 150 seconds with "X is editing this event right now", while
somebody genuinely typing lost their lock after 150 seconds and could then be
saved over. Denis hit the sticky half in practice, seeing the notice name
`admin` on an event no one was editing.

- `law_event_lock_holder()`, `law_event_lock_take()`: thin wrappers over core's
  `wp_check_post_lock()` / `wp_set_post_lock()` (which live in wp-admin, hence
  the shared `law_event_lock_bootstrap()` require), so every lock read and
  write in the module goes through one place.
- `law_event_lock_release()`: back-dates the lock rather than deleting the meta,
  exactly as core's `wp_ajax_wp_remove_post_lock()` does — the row stays as a
  record of who was last in the event, and reads as expired. It uses
  `update_post_meta()`'s `$prev_value` argument so a release arriving late (a
  tab's unload beacon overtaken by the same user's next page load) can never
  clear a newer lock, and it refuses to touch a lock belonging to anyone else.
  Note core's arithmetic leaves a deliberate **5-second grace** rather than
  dropping the lock on the instant; 5 seconds instead of 150 is the point.
- `law_event_lock_window()`: the window, read through core's own
  `wp_check_post_lock_window` filter so a site-wide change applies here too.
- `law_event_lock_field( $post )`: the notice plus the state the browser needs,
  shared by the host form (`templates/account-event-form.php`) and the
  committee's edit view (`parts/events/committee-event-form.php`), which had
  identical copies of this block. When the event is free it takes the lock and
  publishes it in `data-law-lock-*` attributes; when somebody else holds it, it
  takes **no** lock and publishes an empty value, so a bystander's page can
  never release the holder's lock. `law_event_lock_message()` is the single
  wording, so the notice the browser writes on a takeover reads identically to
  the one PHP rendered on load.
- The **heartbeat refresh** (`heartbeat_received`, key
  `law-refresh-event-lock`) mirrors core's `wp_refresh_post_lock()` with the
  module's permission check: core's handlers gate on `edit_post`, which every
  host fails, since host-side roles hold no `law_event` capabilities at all
  (`capabilities.php`) — so this gates on `law_user_can_manage_event()`.
  Sending no lock is meaningful: a page showing the notice keeps asking, and
  the moment the other lock expires this hands it over and the browser clears
  the notice, instead of the notice sitting there until the reader thinks to
  reload.
- The **unload release** (`wp_ajax_law_event_release_lock`, nonce
  `law_event_lock_<id>`) is sent as a `navigator.sendBeacon` on `pagehide`
  (not `beforeunload`, which does not fire on mobile tab switches or
  back/forward-cache navigations).
- Client side is in `assets/js/event-form.js` — not a new file, since the form's
  behaviours already live there. It binds core's jQuery `heartbeat-send` /
  `heartbeat-tick` events and sets `wp.heartbeat.interval( 15 )`, matching
  core's post editor, so a takeover surfaces quickly and each refresh sits well
  inside the window. `heartbeat` is added as a script dependency only on a page
  actually rendering an edit form (the host form template, and the dashboard
  with `law_edit`), so the rest of the dashboard does not poll admin-ajax for a
  lock it never shows.

### `submission-form.php`: the custom submission/edit form (replaces form 2)

- `law_events_user_can_submit()`, `law_events_form_event_id()`,
  `law_events_locked_fields( $post, $user_id = 0 )`: submission eligibility
  (**anybody signed in**, since 14 September 2026; kept as a function because it
  is the single seam the POST handler, the header bar and the form template all
  ask), the edited event ID, and
  the per-status, per-user lock list. For hosts — title, type, preferred
  slots, fee tier, invoice block, sectors, host organisations and venue
  capacity all freeze once the event leaves
  draft/proposed/sent-back; description, speakers, venue, agenda,
  contacts and co-owners stay editable. (`venue_needed` was in that list until
  17 September 2026, when the question came off the form altogether and there
  was no longer a control for a lock to disable.) **Places available freezes earlier
  than any of them**: it is read-only for a host from submission onwards
  (Denis, 14 September 2026), so `law-draft` is the only status at which they
  set it — see the change history entry below. **Committee members
  bypass every post-approval lock except `fee_tier` and `invoice`** (Denis,
  7 September 2026): fee and invoice changes stay in the dashboard override
  control and wp-admin — and, from 9 September 2026, the dashboard control is
  read-only once the event is approved (see `fees.php`), which leaves wp-admin
  as the only post-approval fee route. Pre-approval statuses are otherwise
  unlocked for everyone, the committee included. The
  user defaults to the current user, so the template render and the save-side
  enforcement always agree.
- `law_events_form_save()`: validation + persistence, with the required set
  mirroring form 2 (Event > submit an event) field for field, the speaker rows
  excepted (see the Speakers fieldset below: organisation and job title were
  relaxed on 15 September 2026). **The update path
  goes through `wp_update_post()`, never `wp_insert_post()`** — the latter fills
  every OMITTED key from its own defaults *before* it works out that it is an
  update, and never restores the stored row. Until 9 September 2026 the partial
  array went straight to `wp_insert_post()`, so every save rewrote
  `post_author` to whoever pressed save and reset `post_date` to that moment: a
  committee member editing from the dashboard silently became the host, which
  locked the real host out (`law_user_can_manage_event()` gives access to the
  author, a co-owner or the committee, and nothing else) and sent the
  host-facing notifications — new booking, capacity warning, the wp-admin Host
  column, the CSV export — to the committee member instead. The same default
  would blank a locked title, which `law_events_map_post()` reads as absent,
  making the event vanish from every dashboard, the programme and its single
  page; that one key had been hand-patched, the other two had not.
  `wp_update_post()` merges the existing row first and closes all three at
  once. `migration/repair-owners.php` repaired the events already damaged.
  Co-owner and contact rows go straight to the schema sanitiser. Two behaviours worth knowing: the places are validated
  against `law_events_venue_capacity_bands()`, and either half of the pair may
  be the stored one, because a disabled control posts nothing: on an approved
  event the band is the stored one, and from submission onwards the places
  are, checked against the band the host is posting. Preferred slots go
  through `law_events_sanitise_preferred_slots()` **before** validation, so the
  "choose at least one" check and the write see the same whitelisted set and a
  tampered label can neither be stored nor satisfy the requirement.
- `law_events_set_terms_by_name()`: sets taxonomy terms by name and **creates
  none** — an unknown name is dropped, never invented.
- `law_events_validate_photos()`, `law_events_sideload_upload()`: server-side
  speaker-photo validation (real MIME sniff, 5 MB cap, pixel bounds) and the
  media sideload. The limits are `LAW_PHOTO_MAX_BYTES`, `LAW_PHOTO_MIN_PX` and
  `LAW_PHOTO_MAX_PX` with `law_events_photo_mimes()`, and four derived helpers
  read them so nothing restates a number: `law_events_photo_upload_mimes()`
  (the `wp_handle_upload()` override), `law_events_photo_accept()` (the accept
  attribute) and `law_events_photo_hint()` (the sentence of help text under
  every upload control). `law_events_validate_photos()` also handles
  `UPLOAD_ERR_INI_SIZE`/`UPLOAD_ERR_FORM_SIZE` explicitly: a file PHP itself
  refused arrives with size 0 and an empty tmp path, and without that branch
  the size check passed and the host was told their photo "must be a JPG, PNG
  or WebP image" for what was really a server limit. `tests/SpeakerPhotoTest.php`
  covers the lot.
- `law_events_form_save_speakers()`, `law_events_form_save_sessions()`: upsert
  speakers (identity — `first_name` and `last_name`, a legacy single `name`
  split rather than dropped) and store the appearance rows with this event's
  role, organisation, job title and photo; a re-save without a new upload keeps the
  photo already on the row for the same speaker (the posted `photo_id` is a
  display echo, never trusted). Session rows copy the matched event row's
  details, role included (the host form's session picker is a list of names,
  so a per-session role is a wp-admin-only override).
  `law_events_form_save_sessions()` **upserts** its `law_session` children
  rather than wiping and rebuilding them: the sessions repeater carries each
  row's post ID in a hidden `sessions[i][id]`, an edited row is updated in
  place and only rows the host actually removed are deleted. A posted ID is
  honoured only when this event already owns it and no earlier row has claimed
  it, so a forged ID cannot re-parent or hijack another event's session;
  anything else inserts. A row that named an existing session is added to the
  keep list **before** the write, not after it succeeds, so a failed
  `wp_update_post()` costs the host their edit and never the session to the
  reconcile sweep; the failure is written to the activity log
  (`session_save_failed`) rather than swallowed. The saver also writes
  `menu_order` from the host's row order, which `law_event_session_ids()` uses
  as the tie-break after start time: post IDs used to carry that order for free
  because every save re-created them, and cannot now that a session keeps its
  ID. Sessions saved before this all hold `menu_order` 0 and fall through to
  the ID order they had. Until 9 September 2026 the saver deleted every session
  post and re-inserted the lot on each save, which churned session IDs and
  destroyed `_law_gf_entry_id` on migrated sessions — after one host edit, a
  re-run of migration step 4 (sessions) would have lost its "already migrated"
  check and duplicated them. Both `id` and `photo_id` are excluded from the
  repeater's "has this row been started?" test, or clearing a row's fields to
  delete it would fail validation instead. The Speakers fieldset
  (`parts/events/event-form-speakers.php`, extracted from
  `event-form-fields.php` on 15 September 2026 so the committee's
  external-event form renders the same repeater) opens with **First name** and
  **Last name** (both required on a started row, since 9 September 2026), then a
  Role select, then Email — the live form 8 layout. The Role select has **no
  default**: its first option is a blank `Select role` placeholder (Denis,
  9 September 2026), the field is not required, and an unset role still reads as
  Speaker on the public cards. The neighbouring label is "Job title" (it read
  "Job title / role", which beside a Role select said the same thing twice).
- **Only the two names and the email are required of a speaker row** (Denis,
  15 September 2026). The organisation and the job title were required until
  then, mirroring form 8 (Event > speaker) field 3 (Organisation / firm /
  chambers) and field 4 (Job title / role), which really were required on the
  live form; they are now optional, as field 9 (Role) always was, because a host
  frequently knows who is speaking long before they know which hat that person
  will wear. Nothing had to change to absorb the blanks: every surface that
  prints the pair already builds the line with `array_filter`
  (`parts/events/speaker-card.php`, `speaker-bio-modal.php`,
  `admin/speaker-screen.php`) or guards each field separately
  (`templates/speaker.php`, `parts/loop/speaker.php`), the Manage Speakers
  export writes empty cells, and 14 of the 248 appearance rows already carried
  no organisation. The asterisks came off the two labels in the shared
  repeater, which also ends a small lie: the committee's external-event form
  renders the same partial and `law_external_event_validate()` never enforced
  those fields at all.
  **The email stays required and should not be relaxed casually.** It is the
  speaker dedupe key: with it blank `law_speaker_find_existing()` falls back to
  matching on the name, and a second "John Smith" would be silently attached to
  the first one's shared, publicly displayed profile. The reasoning is set out
  at `functions/events/speakers.php:110-121`, and the merge tool that comment
  offers as the remedy for a duplicate (EVENTS_4.2_SPECS.md §3.5) is not built
  yet. Both names stay required for a different reason:
  `law_events_form_save_speakers()` drops a row whose joined name is empty, so
  a nameless row would vanish rather than be reported back to the host.
  Before 8 September 2026 this save hard-coded `role => ''`, so a role the
  committee set in wp-admin vanished on the host's next edit. `event-form.js`
  resets a cloned `<select>` to its first option, which on the Role select is
  now that placeholder. `law_events_form_values()` prefills role/organisation/
  job title from the row, not the speaker post, and exposes each speaker's
  `first_name` / `last_name` beside the joined `name` the previews and the
  session picker print.
- **Sessions link to speakers by ROW KEY, not by name.** The session picker's
  checkboxes post `row:<index>`, naming a speaker row of the same submission;
  `law_events_form_save_speakers()` returns an `index => speaker_id` map and
  `law_events_form_save_sessions()` resolves the tick through it. A plain string
  is still accepted and matched on the normalised name, for a legacy
  comma-separated value or a hand-made POST. This exists because the name is the
  one thing about a speaker that the very save being resolved can change: while
  linking was name-based, renaming a speaker dropped them from every session,
  and two speakers sharing a display name could never be put on different
  sessions. A tick is likewise remembered across a live rebuild by the row's
  index (`data-law-speaker-key`), never by the name, or editing a name would
  untick the session as the host typed.
- **A host save never wipes a session speaker it did not offer.** The wp-admin
  session screen attaches any speaker in the site to a session, with no
  requirement that they be on the parent event, so such a link cannot appear in
  the host's picker. `law_events_form_save()` therefore captures the event's
  speaker IDs **before** `law_events_form_save_speakers()` replaces them and
  passes them to the session saver, which keeps any existing session row whose
  speaker the event has never held. A speaker the host genuinely removed from
  the event *is* in that "before" set, so they still leave every session — the
  agenda stays honest, and only invisible links are protected.
- **Repeater rows are re-indexed with `array_values()`** before the blank
  template row is appended (`event-form-fields.php`, `people-repeater.php`). A
  re-render after a failed save carries the posted indexes, which stop being
  contiguous as soon as a row has been removed, and "the last row is the
  template" is a count-based test — so without this a real row was hidden as the
  template and the template rendered as a live row.
- `law_events_form_handler()` (on `admin_post_law_event_form`): nonce,
  honeypot, rate limit (15 per 10 minutes), `law_events_user_can_submit()`,
  then `law_user_can_manage_event()` on an edit. **Cancelled and Rejected
  events are read-only**: the template shows a "can no longer be edited" note
  instead of save buttons, and the handler refuses a hand-made or stale POST
  with the `event-not-editable` notice (the message thread stays open — only
  edits are refused). Edit locking replaces
  GravityView entry locking, and lives in `edit-lock.php` (below): the handler
  refuses the save with a named-editor message when someone else holds the
  lock, takes it otherwise, and **releases it after a successful save** — the
  saver is being redirected away, so holding it would make the next person wait
  out the window. A validation failure deliberately does not release it,
  because that path returns to the form, which re-renders and takes it again. A posted `law_form_context=committee` field
  (honoured only for `law_user_is_committee()` users) marks a save from the
  committee edit view: its failure and success redirects go back to
  `/account/dashboard/?event=<id>&law_edit=1` / the dashboard detail view
  instead of the host template. Saving then goes through
  `law_events_form_result_redirect()`: the `submit` transition from draft and
  the page 372 confirmation as before, but **`resubmit` from sent-back now
  fires only on the host form's explicit "Save & resubmit" button
  (`law_form_action=submit`) and never from the committee context** — before
  7 September 2026 ANY save of a sent-back event resubmitted it, so a
  committee detail edit would have resubmitted as though the host had acted.
  Committee saves are logged ("Event details updated by the committee"), send
  no `committee_event_updated`/`squareeye_event_updated` email (those remain
  host-edit alerts), and still create accounts for newly added co-owners on
  approved events. The `nopriv` variant redirects to login.
- `law_events_form_state()`, `law_events_form_values()`,
  `law_events_form_reusable_input()`: transient-backed re-population of a failed
  submission (typed values win over stored values).
- `law_events_form_error_modal()` and `law_events_form_error_selector()` (21
  September 2026): the failed save says so in a dialog as well as in the
  notice. Both the host form (`templates/account-event-form.php`) and the
  committee edit view (`parts/events/committee-event-form.php`) call the first
  right after their `law-form-notice is-error` block, which is unchanged and
  still reads "Please fix the highlighted fields below." — the dialog is the
  same information, said up front, because a save fired from the Finish section
  at the foot of a long form put that notice several screens above the fold and
  read as a save that had worked. It renders `parts/layout/modal.php` with
  `'confirm' => false` and the new `list` and `autoopen` args: one line per
  failed field, carrying **the message that field itself prints** (the first
  message of each error code, so the dialog cannot drift from the form), each
  line a button that closes the dialog and jumps to its control. The title is
  the caller's: "Your event was not saved" for a draft or a first submission,
  "Your changes were not saved" everywhere else. The edit-lock refusal
  (`errors['locked']`) is the exception — nothing is highlighted below on that
  path, so the dialog is that one sentence with no lines and no count.
  `law_events_form_error_selector()` turns an error code into the CSS selector
  `law-modal.js` resolves: the code is the posted field name, so it is
  `[name="<code>"], [name="<code>[]"]` (the second half is what matches the
  preferred-slots and sectors checkbox groups), with `speaker_photo_<n>` mapped
  to that row's `[name="speaker_photo[<n>]"]` and an unrecognised code
  returning nothing, which the dialog prints as plain text. `FormErrorModalTest`
  pins all of it, including that every code `law_events_form_save()` can raise
  has a control on the rendered form to point at. Without JavaScript the dialog
  stays hidden and the inline notice does the whole job, exactly as before.
- A `wp_enqueue_scripts` closure loads `assets/css/event-form.css` and
  `assets/js/event-form.js` on the five account templates that use them
  (`account-event-form`, `account-dashboard`, `account-events`,
  `account-profile`, `register`), versioned by `filemtime` so an edit can never
  serve stale CSS, and adds WordPress's `password-strength-meter` dependency on
  the register/profile templates. It also calls `law_modal_enqueue()` on the
  host form template (as it already did on the dashboard): the modal partial
  enqueues the component itself, but mid-template, by which time the head is
  out and the stylesheet would print after the failed-save dialog it styles.

### `registration.php`: the custom registration and profile forms (forms 1 & 3)

- **Neither form asks anything about roles or intent since 14 September 2026.**
  The role checkbox group first became an optional two-tick group stored as
  `law_intent` user meta, and then came off the forms entirely the same day
  (Denis): nothing about the site depends on the answer, because anybody signed
  in may submit an event and book a place. The register link's `?role=` /
  `locked_role` path went with it (there is nothing to lock), and the ACF
  `law_role` user field is no longer written, since its choices are the retired
  role slugs. See ROLES_AND_ACCOUNT_HUB.md.
- **`law_intent` survives as storage with no collector.** It still holds what
  migration step 11 read out of the retired roles for 302 accounts, and it is
  what `law_registration_hubspot_tags()` derives the `<year> Event Host` and
  `<year> Sponsor` tags from. Two consequences to hold on to: a save through
  `law_registration_write_profile_meta()` **leaves the key alone unless the
  input actually carries it**, so a form that does not ask cannot wipe what the
  migration seeded; and with nothing setting an intent, every new registration
  takes the general welcome email. Put a tick back on a form and both come back.
  Worth knowing when deciding its future: **nothing in this codebase talks to
  HubSpot.** The tags are written to `law_hubspot_contact_type` user meta and
  read by nothing here; the Gravity Forms HubSpot feed (feed 15 on form 1, User
  registration) is authenticated but **inactive**, named "use Make instead", and
  there are no webhook feeds. Whatever consumes the tags lives outside this
  repository.
- `law_registration_intents()`, `law_registration_read_intent()`,
  `law_registration_write_intent()`: the tick list and its single read/write
  path, whitelisted. **An empty array means "asked, ticked nothing"; no row at
  all means "never asked"** — the accounts the engines create, and everybody
  from before migration step 11. The step reads that distinction
  (`metadata_exists()`) so it never overwrites a choice somebody has made.
- `law_registration_legacy_roles()`: the three retired slugs, for exactly two
  callers — migration step 11, which strips them, and the on-behalf profile
  guard, which must keep recognising a not-yet-migrated account.
- `law_registration_accessibility_choices()`, `law_registration_dietary_choices()`,
  `law_registration_country_choices()`, `law_registration_validate_country()`:
  the choice and country lists.
- `law_registration_hubspot_tags()`, `law_registration_write_profile_meta()`:
  the HubSpot tags (now derived from the intents; **the tag strings are
  deliberately unchanged**, so `law_hubspot_contact_type` stays comparable for
  whatever reads it downstream) and the ACF user meta
  (accessibility/dietary) both forms share.
- `law_registration_handler()` (on `admin_post_nopriv_law_register`): honeypot,
  per-IP rate limit (20/hour), account creation **hardcoded to `subscriber`**
  (no role is taken from input at all now, so there is nothing to escalate),
  auto-login; a tripped rate limit returns a titled 429 with a back link.
  Logged-in users are bounced.
- `law_profile_handler()` (on `admin_post_law_profile`): the self-service
  profile edit — no role changes at all, and an
  **email or password change requires the current password**
  (re-authentication); the core email-change notice is suppressed before
  `wp_update_user` and replaced by the module's own.
- `law_profile_store_error_state()`: the shared fail path (keep typed values,
  never passwords; re-array cleared checkbox groups; transient + redirect) used
  by both the validation-fail and update-fail branches.
- `law_registration_state()`, `law_profile_state()`, `law_profile_values()`:
  transient-backed form state and the stored profile values.
- `law_registration_clean_attendee_profile()`,
  `law_registration_validate_attendee_profile()`,
  `law_registration_apply_attendee_profile()`: the country / accessibility /
  dietary set collected when somebody is registered **on their behalf** (the
  bookings list's "Register an attendee" and the flagship's "Add an attendee
  without payment"), using the same POST names, choice lists and rules as the
  registration form so `$_POST` goes straight in. The apply step fills a brand
  new account completely but only fills the BLANKS of an account that already
  existed: what a person stated in their own profile always wins over what a
  host remembers of a phone call. `law_booking_apply_attendee_profile()`
  (bookings.php) is the shared caller and writes the activity-log line.

### `committee.php`: the committee dashboard back end

- `law_committee_events( $overrides = array() )`: the review queue query
  (status filter + "run by" filter + assignee filter + keyword). The
  `$overrides` array is merged over the query
  args; the dashboard export passes `posts_per_page => -1` through it to
  escape the 300-row screen cap without duplicating the filter logic.
- `law_committee_keyword_event_ids()`: **what the keyword box actually
  searches** (15 September 2026). It was a bare `WP_Query` `s` until then, so
  it matched the event post's title, excerpt and content and nothing else —
  and the firm that runs an event is meta, not content, so typing a firm name
  returned nothing at all. Measured on the local database at the time: "Mayer
  Brown" returned 0 rows against 2 events it hosts, "Norton Rose" 0 against 2,
  "Hill Dickinson" 0 against 1. 104 of 108 events carried a firm, because
  `_law_host_organisations` is required at submission. The committee's own list
  was the last one in the module that did not match on organisation — bookings,
  flagship bookings, speakers and even the **public** programme filter
  (`law_calendar_event_matches_filters()`, which reads the same meta through
  `source.php`'s `host` key) all already did. Reported by the client
  (Emily O'Callaghan, 15 September 2026).
  The helper ORs four limbs, each a `fields => ids` query over **every**
  status, `law-draft` included, so the status rules stay in
  `law_committee_events()` alone and are not a second thing to keep in step:
  core `s` (delegated, not reimplemented, so the existing behaviour cannot
  quietly change); a `LIKE` on `_law_host_organisations` (free text in a plain
  meta row, so this is an ordinary column comparison, not the
  LIKE-over-serialised-meta the module refuses); and linked organisations
  matched **by name** — `_law_organisation_ids` is an `int_array` stored as one
  serialised row, so no `meta_query` can reach a member of it, and the limb
  instead fetches the events carrying the key at all (a handful; the field is
  committee-only and quiet) and resolves them in PHP through the memoised
  `law_events_organisation_titles()` map.
  **Two `WP_Query` behaviours dictate the shape of the calling block**, and
  both fail silently if got wrong. `post__in` and `post__not_in` are an
  `if`/`elseif` in `WP_Query::get_posts()`, not two `AND` clauses, so setting
  `post__in` would disable the flagship exclusion and put the conference back
  on the review queue — the flagship is therefore **subtracted from the matched
  ID set** rather than left to `post__not_in`. And an **empty** `post__in` is
  skipped rather than matching nothing, so a keyword no event answers has to
  return early; left to `WP_Query` the dashboard would answer a typo with every
  event on the site. `tests/CommitteeSearchTest.php` (18) covers both, plus the
  four limbs, the draft rules and the interaction with the "run by" filter.
  The fourth limb is the **host's own name**, added 16 September 2026 (Denis):
  typing "Emma" returned nothing while the Host column of three rows read
  Emma Higgins. `law_committee_host_name_user_ids()` builds the candidate set
  from the **distinct `post_author` column of the event CPT**, not from the
  user table: a host is by definition someone who has submitted an event, and
  the site's users are overwhelmingly delegates who never will, so a `LIKE`
  over `wp_users` would scan thousands of rows to find the sixty-odd hosts and
  would need a cap (and so silently drop matches) to stay affordable. One
  `DISTINCT` read, `cache_users()` to prime those rows and their
  `first_name`/`last_name` meta, then the comparison in PHP. **Every word of
  the keyword must appear** somewhere in the name, in any order, so
  "higgins emma" finds Emma Higgins and "emma h" narrows rather than widens; a
  plain substring would answer only one of those, and an OR over the words
  would answer "Emma Higgins" with every Emma on the programme. `display_name`
  is what the Host column prints and is matched first, with the
  `first_name`/`last_name` meta read alongside it for accounts whose
  `display_name` was left as a login. **Co-owners are not matched** (Denis,
  16 September 2026): the row names the submitting host only, and a hit on a
  name that is nowhere on screen reads as a broken filter in exactly the way
  the missing firm did. Host email and the LAW reference remain deliberately
  **not** searched.
- **The Assignee filter and the assignee line on the row** (Denis,
  21 September 2026). `_law_assignee` had been committee-only data until then:
  it was set on the detail view's Committee controls panel and in the wp-admin
  event screen, it named the recipient of the assignment notification
  (`law_event_maybe_notify_assignee()`) and it filled a column in the export,
  but the dashboard the committee actually works from printed it nowhere and
  could not filter on it. The Event cell now carries an `Assignee: <name>` line
  in the same `.law-dashboard__row-note` span as the reference, the agenda
  summary and the Host column's firm, so it needed no CSS, and **nothing is
  printed when nothing is assigned** — most rows carry no assignee, and an
  "Assignee: none" on each of them would be a column of noise in the one cell
  that already holds three other things.
  The filter is the **last select on the bar**, after "Run by", and its options
  come from `law_committee_assignees()`: the distinct `_law_assignee` values in
  use on the event CPT, resolved to display names and sorted with
  `natcasesort()`. Deliberately not `law_events_committee_users()`, which is
  what the assignee *picker* offers: that helper lists the `events_committee`
  role only, while `law_events_sanitize_assignee()` accepts anyone with
  `edit_others_law_events`, so an event assigned to an administrator or an
  editor would be unreachable from a select built off the role. Building the
  list from the meta in use fixes both halves — every name offered can return a
  row, and every name on a row can be filtered for. The select is **not
  rendered at all** when nothing is assigned anywhere, rather than an
  "All assignees" control with no assignees under it. The `?law_assignee=` value
  is checked against that same list before it becomes a `meta_query` clause, so
  a hand-typed ID cannot filter on a value no event carries. The literal `'0'`
  that `law_event_update_meta()` writes for "(none)" (it deletes only on `''`,
  and the `int` sanitiser returns `0`) is filtered out of the options in PHP
  rather than left to a numeric comparison on a varchar column.
  It needed no JavaScript: `assets/js/calendar-filters.js` binds every `select`
  in the form and builds both the replaced URL and the `&law_partial=1` fetch
  from the named inputs, so the filter works over AJAX, as a no-JS GET, in the
  timeline view (`law_slotchart_items()` goes through the same query) and in the
  exports, whose hrefs gained `law_assignee` alongside `law_kw`, `law_status`
  and `law_run_by`.
- The list's **Host column carries the firm under the person's name**
  (`parts/events/dashboard-list.php`), in the same `.law-dashboard__row-note`
  span the Event and Slot cells use, so it needed no CSS. Without it a
  "Mayer Brown" search would return rows whose visible text never said Mayer
  Brown, which reads as a broken filter rather than a working one.
  Since 16 September 2026 that column, the event title and the timeline's bar
  titles also **mark the keyword** with the same `mark.law-hit` the programme
  uses, through `law_calendar_highlight()`. The part reads `?law_kw=` itself
  rather than taking an arg (unlike `parts/loop/event.php`, which is shared with
  four surfaces and must never touch the query var): this one is rendered only
  by the committee dashboard and its `&law_partial=1` endpoint, where `law_kw`
  is always that dashboard's own keyword. It marks the phrase as typed, matched
  whole and case-insensitively, which is the rule on every surface. A row whose
  hit is in the **description** — searched by core `s`, printed in no column —
  carries a `law_calendar_search_snippet()` extract on **a second `<tr>`
  spanning the table** instead, at 260 characters rather than the programme's
  170. That makes an event two rows, so the zebra stripe is counted in the
  template (`is-alt` on both rows of a pair) and Foundation's
  `tbody tr:nth-child(even)` is disarmed under
  `.law-dashboard__table--striped` — a modifier, because eight other dashboard
  tables share the base class, have one row per thing, and must keep striping
  as they are.
  An explicit `?law_status=law-draft` is refused (falls back to the default
  non-draft set): drafts are owner-only unsubmitted host data, and before
  this guard a committee member could list — and once the export existed,
  bulk-download — every host's drafts by editing the query string
  (2026-09-07 security review finding).
- `law_committee_status_counts()`: the filter-chip counts, via **one**
  `wp_count_posts()` call (not a capped per-status query).
- `law_committee_requested_event()`: the event opened in the detail panel.
- `law_committee_event_url( $event_id )`: the dashboard screen that manages
  one event, resolved from what the event is — the flagship dashboard for the
  flagship (which `law_committee_requested_event()` refuses), Manage receptions
  for a reception, the external-event form (`?law_external=<id>`) for an
  external event, and the detail panel (`?event=<id>`) for a host event. One
  home for the four cases so every "Edit" affordance the committee sees lands
  on a front-end screen rather than `post.php`; `law_calendar_edit_link()` on
  the programme and the `{edit_link}` email placeholder both go through it
  (Denis, 15 September 2026). **Every affordance on a row means every
  affordance**, which took a second pass on 17 September 2026: the event
  table's Edit button routed a reception to Manage receptions while the row's
  TITLE still linked to `?event=<id>`, so clicking a reception's name landed on
  the generic detail view — the one screen a reception is never edited on. The
  table now resolves ONE url per row (`$law_row_review_url`, computed at the
  top of the loop in `parts/events/dashboard-list.php`) and the title and the
  button both print it, and `law_slotchart_item()` routes its bar the same way.
  The flagship was fixed with it: it reaches both surfaces through the
  timeline's unscheduled section, which asks for it explicitly, and
  `law_committee_requested_event()` refuses `?event=<flagship id>`, so that row
  and that bar were linking to a refusal. Both cases drop the filters
  `$law_link_base` / `law_slotchart_url()` carry, which they have to: neither
  Manage receptions nor the flagship dashboard reads them.
  `ReceptionsDashboardTest::test_the_event_list_sends_a_receptions_name_and_button_to_the_same_screen()`
  pins the pair together.
- `law_committee_maybe_render_partial()` (on `template_redirect`): the
  dashboard URL with `&law_partial=1` returns just the event-list markup
  (`parts/events/dashboard-list.php`) so the filter bar can swap it in place
  without a reload. Mirrors `law_calendar_maybe_render_partial()`; both the
  page's Members restriction and `law_user_is_committee()` are re-checked in
  code, and a failure is a bare 403.
- `law_committee_action_handler()` (on `admin_post_law_committee_action`):
  nonce + `law_user_is_committee()`, then the approve / send-back / reject /
  cancel / assign / set-slot / mark-paid actions, each routed through the
  workflow engine and logged, plus the fee override, the venue capacity band
  and places available (`law_venue_present`), the two classification
  switches (`law_flags_present`), linked
  organisations and private notes. **The host fee override is validated before
  any write** (9 September 2026): a ticked box with an empty amount is refused
  rather than read as £0.00. The CHANGE is judged, not the event, so re-posting
  the stored values with the rest of the panel is never a fee change. A changed
  fee is refused outright when `law_event_fee_edit_mode()` is `locked` (only
  reachable from a hand-made request, since the control renders read-only then)
  and logged as a refusal; when it is `reissue` the write is followed by
  `law_event_apply_fee_change()`, which voids the open invoice and raises a
  replacement, and the page reports `fee-reissued` or `fee-waived`. A changed
  fee in `reissue` mode is also refused when a workflow ACTION was submitted in
  the same POST (17 September 2026): the two actions an approved event still
  offers are Mark paid & confirm, which would settle the invoice the change is
  about to void, and Cancel, which voids it anyway. Because every check runs
  first, a refusal leaves the event untouched and the workflow action further
  down never runs.
  `law_committee_refuse()` is the shared refusal tail all three of the
  handler's dead ends use: JSON for the modal callers, transient +
  `law_committee_take_error()` for a plain submit.
  `law_event_apply_slot_label()` writes the confirmed slot. Each group of
  checkbox and multi-select controls carries its own hidden sentinel
  (`law_flags_present`, `law_orgs_present`, `law_venue_present`) so an absent
  input reads as
  "cleared" rather than "not on the form" — the groups have to be
  independently absent-safe, which is why there is a sentinel per group rather
  than one for the form.
  **The classification switches** (`_law_is_law_event`, `_law_session_agenda`)
  are logged via `law_event_log_flag_change()` from this handler, the wp-admin
  screen and the agenda backfill; turning the agenda on or off also picks the
  redirect's `law_notice` (`agenda-on`, `agenda-off`, `agenda-off-kept`), since
  "Changes saved." would leave the committee hunting for a section that lives
  on the edit form, and unticking the box with sessions present does not remove
  it at all.
  **Linked organisations** (`_law_organisation_ids`, guarded by the
  `law_orgs_present` sentinel) are logged via
  `law_event_log_organisation_change()` from both this handler and the
  wp-admin screen (9 September 2026): the field is quiet, but a
  sponsor-category organisation is one of the three routes to the public
  sponsored highlight, so a change to it has to leave a trail like slot,
  assignee and fee changes already do. The detail view also renders them
  read-only as a "Linked organisations" row under "Host organisation(s)",
  because the only previous way to see them was to scroll the multi-select.
  **The action is whitelisted** against `law_event_ui_actions()` (`approve`,
  `send_back`, `reject`, `mark_paid`, `cancel`); anything else is logged as a
  refused action and bounced with a dashboard error. An empty action is the
  plain "Save changes" path and still falls through. The wp-admin event screen
  applies the same list to its `law_workflow_action` radio, so the two
  committee UIs cannot drift.
  **Delete is the one action handled here that is not a workflow transition**:
  intercepted after the action is read but before the whitelist (or it would
  be logged as refused), accepted only on a Cancelled or Rejected event —
  trashing a live event would orphan an open Stripe invoice with no void and
  no host email, so cancel/reject must come first — and then: activity-log
  line first (the log lives in comments, so it survives trash and restore),
  `wp_trash_post()`, redirect to the **list** view (the detail panel cannot
  load a trashed post) with the `event-deleted` notice. Restore and permanent
  deletion stay wp-admin jobs; untrash puts the status back via the
  `wp_untrash_post_status` filter. Like the comment-reply handler, it answers
  **JSON when `law_ajax=1` is posted** (committee-actions.js submits the
  modal actions this way): the nonce is verified manually before
  `check_admin_referer` so a stale session gets a parseable 403 instead of an
  HTML die page, every failure path (`wp_die`, the refused action, a
  `WP_Error` from the transition) has a `wp_send_json_error` twin, and AJAX
  errors deliberately **skip the `law_dashboard_error_` transient** — the
  message travels in the response, so a later page load is not owed one. An
  empty action over AJAX is refused with a 400 rather than falling through to
  the Save path, because for the fetch caller a missing `law_action` means the
  submitter's value was lost, and "saved" would mask an action that never ran.
  Success returns a per-action title, a "Reloading the page…" message and a
  redirect URL **without `law_notice`** (the success dialog already confirmed
  the action, so the reloaded page must not banner it again). The no-JS path
  is byte-for-byte the old redirect flow.
- **The committee edit view** (`?event=<id>&law_edit=1` on the dashboard
  page, added 7 September 2026): full front-end editing of an event's
  details, restoring the parity the legacy GravityView 419 (Events
  (committee - all)) edit form provided. The detail view's "Edit event
  details" button (directly under the event title since 9 September 2026,
  paired with "Preview event"/"View event" in a `.law-dashboard__event-actions`
  flex row;
  it used to sit at the foot of the cell, after the Invoice details block and
  above the thread, where it read as part of the thread and rendered full
  width, because `.button` is `width: 100%` in `app.css` and `style.css` only
  resets that inside a `<form>`; not offered on drafts, which their host
  edits) swaps the detail panel for
  `parts/events/committee-event-form.php` — white background, a "< Back to
  the event" link, the shared fieldsets with committee locks (everything
  editable except fees/invoice, see `submission-form.php` above), a single
  "Save changes" button posting the same `law_event_form` handler with
  `law_form_context=committee`, and no `law_ec` field (first-save-only
  mechanism). It calls the same `law_event_lock_field()` as the host form; the
  read-only detail view never does. The sidebar's "Full editing in
  wp-admin" link stays — wp-admin remains the fee/invoice edit route.
- **The committee preview** (`?preview-event=<id>` on the dashboard page,
  added 9 September 2026): the detail view's "Preview event" button renders
  the attendee-facing single event view for an event that is not published
  yet, so the committee can see what it will look like before confirming it.
  **On a Confirmed (`publish`) event the button is "View event" instead and
  links straight to the real permalink, in a new tab** (9 September 2026): the
  event has a public page by then, so previewing it would be a second route to
  the same render, and the public page carries no back link to the dashboard.
  The same rule now applies to the "Preview event"/"View event" link on each
  appearance in `parts/events/speaker-manage.php`, which was already pointing
  at the permalink for Confirmed events (`law_events_event_url()`) under the
  "Preview event" label.
  It reuses `parts/calendar-body.php` — the same renderer the programme and
  the single event permalink use — and the branch sits **before**
  `get_header()` in `templates/account-dashboard.php`, because that partial
  brings its own header and footer (exactly as
  `templates/calendar-committee.php` does). Two things differ from the public
  render, and they are the two caller variables the partial gained for this:
  the event is resolved here as `law_events_cpt_hydrate(
  law_events_map_post( $id, array() ) )` and handed in as
  `$law_cal_event`, because the partial's own resolver
  (`law_calendar_event_by_id()`) applies `law_calendar_public_statuses()`
  anywhere but the committee calendar template and an unconfirmed event would
  come back `null`; and `$law_cal_back` points both ways back off the view at
  `?event=<id>` labelled "Back to event", instead of the programme.
  `$law_cal_show_status` is true, so the status pill stays on the preview and
  a Proposed event cannot be mistaken for a live one. Gated on
  `law_user_is_committee()` in code as well as by the page's Members
  restriction, and on `'cpt' === law_events_source()`, since it renders
  `law_event` posts; anything that fails falls through to the normal dashboard,
  which already refuses non-committee users. `array()` is deliberately not
  `array( '*' )`: it allows every status **except** `law-draft`, because a
  draft is owner-only unsubmitted host data — the same reasoning behind
  `law_committee_events()` refusing an explicit `law_status=law-draft` — so a
  draft id falls through, and the detail view offers no Preview button on a
  draft, exactly as it offers no Edit button. It needs no new assets:
  `calendar.css`, `event-form.css` and `law_modal_enqueue()` (for the speaker
  "Read full bio" dialogs) already gate on this template.
  Note that `/calendar-committee/?event=<id>` has always rendered unpublished
  events for the committee through the same partial; the dashboard route
  exists for the back link that returns to the detail view.
- **The preview's booking control is rendered inert, not omitted** (Denis,
  9 September 2026). `law_booking_render_action()` returns early for any event
  that is not `publish`, so the first cut of the preview showed no availability
  row and no Register button at all — the facts box fell back to a "Places
  remaining" grid cell and the footer row an attendee will see was simply
  missing, which made the preview misrepresent the finished page's layout. The
  control now takes a `$preview` flag (threaded
  `templates/account-dashboard.php` → `$law_cal_preview` in
  `parts/calendar-body.php` → the `preview` arg of
  `parts/calendar-event-details.php`): it skips the `publish` gate, the
  redirect notices and the viewer's own booking states (a preview answers
  "what will an attendee see", not "what do I see"), and renders the same four
  availability states with the same wording — "Places for this event have not
  been released yet.", "Bookings for this event have closed.", "This event is
  fully booked." and "N places left". There is deliberately **one** state machine: the preview reuses those
  branches rather than restating them. `law_booking_render_opener()`'s
  `$preview` branch prints
  `<button type="button" class="button orange" disabled aria-disabled="true">`
  instead of the anchor: a disabled `<button>` is inert by pointer, keyboard,
  assistive tech and form submission, where an `<a>` carrying only
  `aria-disabled` would still follow its `href` on Enter. It carries no `href`
  and no `data-law-modal-open`, and neither dialog is deferred to `wp_footer`,
  so the preview also loads no `booking-form.js` — which is why
  `law_booking_is_event_view()` returns false for it. `event-form.css`'s
  `.law-cal .button[disabled]` rule gained `pointer-events: none` so the dead
  orange button cannot repaint on hover whichever way the cascade race with
  `calendar.css`'s `.law-cal .button[aria-disabled="true"]` falls (the two tie
  on specificity and their load order is not fixed).
- **`law_booking_is_event_view()` widened** (same round): it now also names
  `templates/calendar-committee.php` with `?event=`. It used to name only the
  `law_event` permalink and `templates/calendar.php`, so the committee
  programme's single event view rendered the Register control with neither
  `law-modal` nor `booking-form.js` behind it and without `event-form.css` for
  the modal form. Its one caller is the enqueue closure in the same file, so
  the change affects asset loading and nothing else.

**The flagship is excluded from the committee's queue** (9 September 2026):
`law_committee_events()` adds `post__not_in` for `law_flagship_event_id()`,
`law_committee_status_counts()` subtracts it from the chip for its status
(`wp_count_posts()` counts every event, including the one the list hides), and
`law_committee_requested_event()` refuses it so a stale `?event=<id>` cannot
open a detail view offering workflow actions the flagship does not have. By ID,
not a `NOT EXISTS` meta clause, because a caller-supplied `meta_query` replaces
the filters wholesale (see the note in that function) and one memoised ID
lookup beats a second LEFT JOIN on every dashboard query. The timeline view
(`slot-chart.php`, 15 September 2026) is the one caller that asks for it back,
with the non-query key `$overrides['law_include_flagship']`, consumed before the
merge into `WP_Query`'s arguments: it draws a day at a time to show clashes, and
a day-long flagship is the biggest clash on the programme. Note the flagship
carries no `_law_is_external` meta and so answers the "Hosted events" filter —
the wrong word for it, but the same answer `law_event_is_external()` gives
everywhere else, and a special case would make the chart disagree with the table
it switches from.

### `slot-chart.php`: the committee's timeline view (15 September 2026)

A second view of the same dashboard, at `?law_view=slots`, reached by an
outlined button beside "Create an external event". One day at a time, time
running left to right, every event drawn as a bar at its real start and its
real length, and the rows packed so that two events running at once are never
on the same row. A vertical column of bars is a clash, and there is nothing to
click to find that out.

- **Why**: the client asked for it in those words (emily O'Callaghan,
  11 September 2026: "it won't help us to identify clashes - is there any way we
  can have a running total of events confirmed at a particular time slot
  somewhere?"), and EVENTS_4.2_SPECS.md §3.1 had promised "a day-and-time grid,
  showing overlapping and parallel sessions at a glance" since the spec was
  written. The table is sorted by last modified and says nothing about two
  events colliding; the programme groups events under text slot headings, which
  is a list, not a grid.
- **It detects nothing.** There is still no event-to-event clash rule anywhere
  in the module. `law_booking_guard_clash()` is a per-attendee guard about one
  person being in two places, and it exempts the flagship and the receptions.
  This view makes the overlaps visible and leaves the judgement to the
  committee.
- **Not slots, datetimes.** Every kind of event reduces to `_law_start` /
  `_law_end`: `law_event_apply_slot_label()` writes them from a chosen slot, and
  `law_flagship_recompute()`, `law_reception_save()` and
  `law_external_event_apply_when()` write them directly. So the chart needs no
  per-kind branching, and it can place an event whose times match no configured
  slot, which is most of the custom ones.
- `law_slotchart_is_active()`, `law_slotchart_url( $view )`: the view arg is
  `law_view`, joining `law_status` / `law_kw` / `law_run_by` / `law_external` /
  `law_edit` / `law_partial` rather than a bare `view`, because it has to double
  as a form field name. The switch carries the current filters, so pressing it
  changes how the events are drawn and never which ones.
- `law_slotchart_items()`: goes through `law_committee_events()`, so the filters
  apply here exactly as they do to the table with no second implementation to
  drift, and asks for the flagship back with the new
  `$overrides['law_include_flagship']`. Memoised, because the day tabs need the
  per-day counts before the chart renders the days.
- `law_slotchart_lanes()`: first fit, with `law_slotchart_band_order()`
  (approved, confirmed, sent back, proposed, rejected, then cancelled and draft,
  which were not named) deciding the **order events are placed in** and not
  giving each status a block of rows. Packing each status separately was the
  first attempt and it left a hole the width of the morning at the top of a busy
  day: Tuesday's nine confirmed 08:30 events sat in rows 7 to 15 while rows 1 to
  6 held approved events starting at 10:30. Every event now falls into the first
  row with space for it, so the chart is exactly as tall as the day's worst
  clash (Tuesday: 48 events, peak concurrency 9, nine rows) while an approved
  event keeps first refusal on the top rows (Denis, 15 September 2026).
  **Touching is not overlapping**: an event ending at 10:00 and one starting at
  10:00 share a row, because they do not clash. A status the array does not name
  is placed last rather than dropped.
- `law_slotchart_axis()`: floored and ceiled to the half hour, computed per day
  from that day's own events and widened to a three-hour minimum. Only one day
  is on screen, so a varying scale is not confusing, and a Wednesday carrying
  one evening reception renders 18:00 to 22:30 rather than fourteen empty hours.
  It then opens **one step earlier still**, and the part draws no label for that
  opening step: a ruler label is centred on the moment it names, so the first one
  was sliced in half by the scroller's own left edge. The lead-in is what puts
  the first real time on screen in full (Denis, 15 September 2026).
- `law_slotchart_item()`: an empty `_law_end` is drawn at **two hours**, which
  is already `law_event_ics()`'s answer to the same question — a different
  number would give the site two answers to "how long is this event". The bar
  gets `is-open-ended` and a **dashed** right edge, and its label says "onwards"
  rather than stating an end that was never recorded. That edge was a gradient
  fade first, and it read as a rendering artefact at the right-hand end of the
  chart rather than as a fact about the event. An end on a later date or
  at/before its start is the same case (form 10 entry 1559, Law Rocks! LONDON
  2026, captured as 19:45 to 11:30).
- **A bar carries what a table row carries** (Denis, 15 September 2026:
  "display all information, but we just don't need buttons"). The first cut drew
  a bar as a time and a title, which made the timeline a picture of the week and
  left the committee switching back to the table to learn anything about an
  event. `law_slotchart_item()` now reads the same facts
  `parts/events/dashboard-list.php` prints, and `law_slotchart_item_facts()`
  turns them into the ordered detail lines the bar and the accessible label
  share, so the two cannot drift. Under the title, in order: **status ·
  reference · kind** on one bold line, host and organisation, attendees booked
  and places left of the capacity, then anyone waiting and the session-agenda
  summary. Each line carries a `key` as well as its text, because the first is
  bold and the part must not decide which by counting rows. The extra cost is
  `law_event_meta()` reads plus one `get_userdata()` per event — the same
  per-row cost the table already pays, and still no query per bar.
  - **What a bar deliberately does not print.** The **time** went in the same
    round it arrived: the bar's position and length *are* the time, the ruler
    above names it, and "08:30–10:00" on each of Tuesday's 48 bars was a line of
    type per bar restating the chart. The **payment status** went with it —
    bookkeeping, and silent about when an event runs or whether it clashes
    ("don't care if paid or unpaid"). Both are still on the table and the detail
    view, and the time is still in the bar's tooltip and `aria-label`, which is
    now the only place it appears on this view.
  - **Status, reference and kind share one bold line.** The status sat top-right
    beside the time; with the time gone that row held one word, so it joined the
    lines below, and the reference then had a row of its own that it did not
    fill either — merging the two bought a row back off the lane height, which
    is why `--law-sc-lane` is `6.25rem` and not `7rem` (Denis, 15 September
    2026, "status and ref could be in the same line divided by fat dot"). The
    order is also the truncation order: on a bar too narrow for the line the
    kind goes first and the status survives. The status is on the bar at all
    because the fill colour says the same thing and colour is not a fact anyone
    can quote back; seven fills are closer in value than seven words. The
    reference is prefixed "Ref" because it is a bare Gravity Forms entry ID
    ("1503") on a card with several other numbers on it. The line is **not**
    uppercased — it was while it held the status alone, and "REF 301 · EXTERNAL
    EVENT" reads as shouting rather than as a label.
  - **No gap between the title and the details.** They were held apart by a
    `margin-top: auto` so the slack in a fixed-height lane sat somewhere
    deliberate; it read as a gap where something had failed to render. The slack
    now falls below everything, as it does in any under-filled card.
  - **No buttons, and specifically no Bookings button.** The bar *is* the Review
    link, so a second anchor inside it could not be clicked anyway; and the
    attendee list is not what a planning view is for. The booking numbers are
    printed as text instead.
  - **A missing fact is omitted, not dashed.** A dash is right in a table, where
    the column exists whether or not the row fills it; inside a bar it is a line
    of punctuation standing where a line of text would be. So an unconfirmed
    event states no booking numbers at all, an external one says "booked on the
    organiser's site" rather than a hollow "0 booked", and an approved event
    with no capacity yet says "no capacity set" rather than "0 left" — not open
    for booking is not the same fact as full.
  - **A bar is as tall as its own text; the LANE is the fixed thing.**
    `height: auto` with `max-height: var(--law-sc-lane)`, so the details end at
    the bar's own bottom border and a bar that is not the day's fullest is
    simply shorter (Denis, 15 September 2026: "remove the bottom space, so
    details are more stuck to the bottom border"). The lane keeps its height, so
    the rows are still a grid.
    The lane height itself is **computed per day**, not fixed:
    `parts/events/slot-chart.php` counts the detail lines of the day's fullest
    item and emits `--law-sc-facts`, and the stylesheet does the arithmetic —
    `calc(2.9375rem + var(--law-sc-facts, 4) * 0.875rem)`. A day whose events
    have no waiting list and no session agenda is one line shorter throughout
    (Tuesday renders at 89px a lane, Monday at 103px), where one figure for the
    whole chart would pad every other day out to the worst one.
    **Every number in that sum is a whole pixel on purpose**: 6+1 padding and
    border twice, two 15px title lines, a 3px gap, and 14px a detail line. The
    title's two-line clamp had been letting a sliver of a third line through,
    because `-webkit-line-clamp` alone was leaving the box a fraction taller
    than two lines of an 11.52px font on a 14.4px line. It now has a `max-height`
    of `2.5em` as well — exactly two of its own line boxes — and integer metrics
    so that boundary is not left to sub-pixel rounding. The busiest day of the
    2026 week packs into nine lanes, so the chart runs to roughly one and a half
    screens; that lane height is the one number to shrink if it is too much.
    Horizontally nothing was bought: `--law-sc-min` stays at 2px a minute,
    because widening the scale to fit longer lines would cost the one thing the
    view exists for, which is seeing a whole day's clashes without scrolling
    sideways. Instead the bar is a `container-type: inline-size` container and
    drops its detail lines below a 6rem content width — a 30-minute event loses
    them, an hour keeps them — while the `title` and `aria-label` carry every
    line whatever the width, which is why `law_slotchart_item_label()` repeats
    them.
  - **The title stopped being painted in the status colour.** On an Approved bar
    that meant `#ef7d05` on white, 2.9:1 and under AA. It was one short line
    then and it is a paragraph now, so the bar has four tokens instead of one:
    `--law-sc-bg` / `--law-sc-border` carry the status, `--law-sc-ink` the
    title, `--law-sc-accent` the status word (`#a35300` on the light fills, the
    darker orange `calendar.css` already uses for sent-back type), and
    `--law-sc-soft` the detail lines. Each of the seven status variants needs
    looking at on the page rather than reasoned about, because the fills are
    close in value.
- **The per-half-hour running total was built and then removed** (both on
  15 September 2026). `law_slotchart_density()` put one figure per half hour in
  a row under the ruler — 9, 9, 9, 0, 9 across Tuesday — with the day's own peak
  picked out in orange, and it was the literal answer to the client's "a running
  total of events confirmed at a particular time slot". Denis had it taken out:
  "we don't need those numbers … that are basically showing amount of events in
  a gap". A column of bars already *is* the count, and the figures sat in the
  gaps between bars where they read as belonging to the whitespace. The function,
  its markup, its styles and its two tests all went with it. **Worth knowing
  before re-adding it**, because the client asked for it in writing and may ask
  again.
- **Days**: the configured programme week *plus any other date carrying an
  event*. `law_calendar_events_by_date()` drops an out-of-week event into its
  unscheduled bucket, which is right for a five-tab public programme and wrong
  here; the flagship screen already warns that a date can fall outside the week,
  so the committee can produce one, and a planning view has to account for every
  event. Events with no start at all get the `day-unscheduled` section, shown
  under every tab, and it is **the list view's own table** (Denis,
  15 September 2026: "the section with 'No confirmed slots' just should repeat
  the list view"). They have no geometry to draw, so the chart has nothing to
  offer them that the table does not already do better, and a list written for
  that one section was a second layout for the same rows to keep in step.
  `parts/events/dashboard-list.php` gained four optional `$args` for it —
  `events` (skip its own `law_committee_events()` call), `show_count`,
  `show_actions` and `link_base` — following the same pattern
  `parts/calendar-daynav.php` already uses (`show_bookings` replaced a blunter
  `show_actions`, which is still there for a caller that wants no actions column
  at all). The timeline turns the count line off — it would count the
  unscheduled handful against every event on the site — and keeps **Review**
  while dropping **Bookings**. Review is here although the bars do without it
  (Denis, 15 September 2026): there is no bar to click, so without the button
  the row's title link would be the only way in and would not look like one.
  Bookings stays off, as it is everywhere on this view. The `link_base` is
  `law_slotchart_url()`, so those rows carry the view and the filters exactly as
  the bars do.
- **A sticky ruler was tried and reverted** (both 15 September 2026). Pinning
  the time axis to the top of the chart keeps the times readable nine lanes
  down, but it forces the vertical scrolling off the page and onto
  `.law-slotchart`: `position: sticky` pins to the nearest scrollport, and the
  ruler has to stay *inside* the horizontal scroller to keep step sideways with
  the bars it measures, so that element has to become the thing that scrolls.
  Denis judged a scroll box inside the page the worse trade. **Before trying
  again**, know there is no third option in CSS — `overflow-y: visible` computes
  to `auto` the moment `overflow-x` is not visible, so a horizontal-only
  scroller whose children can stick to the viewport needs JavaScript.
- **Page**: `parts/events/slot-chart.php`, rendered inside `#law-cal-events` and
  returned on its own by `&law_partial=1`, so filtering swaps the chart in place
  exactly as it swaps the table.
- **The day tabs are reused whole, with no JavaScript change.**
  `assets/js/calendar-tabs.js` selects `.law-cal-day-section[id^="day-"]`, reads
  `data-count` for the tab labels, keeps `#day-unscheduled` out of the tabs and
  under every one of them, and re-enhances itself on `law:partial-rendered`.
  Emitting the programme's markup contract is the whole of the support.
  `parts/calendar-daynav.php` gained optional `days` / `counts` /
  `flagship_date` / `label` args, because its own counts are the public
  programme's — a differently filtered set.
- **The one trap.** `filterParams()` in `assets/js/calendar-filters.js` builds
  both the replaced URL and the `&law_partial=1` fetch URL from the named inputs
  inside `#law-cal-filter-form` and **silently drops every other query
  argument**. The view therefore renders a hidden `law_view` field inside that
  form; without it the first keystroke in the keyword box fetches the table
  partial, swaps the chart for it and strips the view from the address bar.
  Anyone adding a query argument to one of these pages needs the same field.
- **Assets**: `assets/css/slot-chart.css` (its own file rather than more of the
  2,900-line `calendar.css`, loaded only on this view), and `calendar-tabs.js`
  now also enqueued for it. Bars carry `--at` and `--len` in **minutes** as
  custom properties, not percentages and not grid columns: the ruler ticks every
  30 minutes but real events do not start on the half hour (17:25 and 19:45 are
  both live data), and the zoom is one number in the stylesheet. Bar colours are
  the status-badge palette from `calendar.css`, not a second set for the
  committee to learn. A bar has a `min-width` so a very short event still shows
  some of its title — the **left** edge stays exact, only the right can overrun,
  and the `title` attribute always carries the true times.
- **Exports are kept** on this view: they export the filtered event list, the
  filters work here, and leaving them costs no code.
- **Hover moves nothing.** The bar used to lift on `transform: translateY(-1px)`.
  That was fine on a bar holding one line and wrong on one holding five lines of
  clamped 10px type: the shift is sub-pixel, so every line re-rounds to a
  different device pixel and the ellipsis, the bold line and the detail lines all
  settle at slightly different moments through the transition, which reads as the
  contents jumping about independently rather than as the card rising (Denis,
  15 September 2026). It is now a ring and a shadow, both drawn as `box-shadow`
  so the box itself never changes — an `outline` or a wider border would have
  brought the same problem back.
- **Two CSS traps it walked into**, both the same shape: a bar is an `<a>`, and
  the theme has opinions about those.
  `.law-dashboard a:not(.button)` in `event-form.css` paints every dashboard
  link navy and underlined, outranking both
  `.law-cal-daynav__link[aria-selected="true"]` and the bar colours, so the
  selected day tab and every Confirmed bar rendered navy-on-navy and went blank.
  Fixed by excluding those two from that rule rather than out-specifying it
  locally, so the exception sits with the rule that needs it. Excluding them
  then exposed the global `a:hover { color: #fff }` in `app.css`, which had been
  masked by it: an Approved bar is orange type on white, so it went
  white-on-white under the pointer. Fixed with a `--law-sc-ink` token set once
  per status and re-asserted on `:hover`, `:focus`, `:active` and `:visited`,
  rather than seven more hover rules. The same global rule also underlines an
  anchor on hover, which drew a rule under the day name and count of whichever
  tab the pointer was over; `.law-cal-daynav__link` now sets
  `text-decoration: none` in all three of its states. **That one fixes the
  programme too** — nothing had ever set it, so the public day tabs had
  underlined on hover since they were built; it only became visible here
  because the dashboard's blanket rule used to underline them in every state.
- Tests: `tests/SlotChartTest.php` (42, after the running total's two went with the feature).

### `export.php`: the dashboard exports (CSV / Excel / PDF)

Replaces the legacy GravityView view 419 (Events (committee - all))
DataTables export buttons. Three buttons ("Export: CSV | Excel | PDF") sit
under the dashboard filter bar (list view only) and export the full filtered
event list — the legacy column set minus "Workflow step" (folded into the
event status by the rebuild) plus "Reference".

**"Host organisation(s)" was added on 15 September 2026**, alongside the
dashboard search change above. The field is required at submission and so is
the most complete firm data the module holds, yet every export had gone out
without it while the quieter, committee-set "Linked organisations" column was
already there. It sits immediately before that column, in
`law_committee_export_columns()` and `law_committee_export_row()` alike — the
two are one ordered pair, and `tests/CommitteeSearchTest.php`,
`tests/LinkedOrganisationsTest.php` and `tests/EventFlagsTest.php` all assert
they stay the same length.

- **One row builder, three formats.** `law_committee_export_rows()` returns
  `{columns, rows}` for every format, so they cannot drift. It calls
  `law_committee_events( array( 'posts_per_page' => -1 ) )` — same
  `?law_status=` / `?law_kw=` filters as the screen, but uncapped, because a
  silently truncated download is worse than a truncated screen — and primes
  the users cache once (`cache_users()`) for the host + assignee lookups.
  Columns: ID, Reference, Title, Name, Email, Committee assignee, Preferred
  date & time slots, Confirmed slot, Event status, Payment status, Submitted
  (`Y-m-d H:i`, sortable), Sector (`law_event_sector_summary()`), Host
  organisation(s), Linked organisations (`law_event_organisation_names()`,
  `; `-joined), Sponsored (`law_events_post_is_sponsored()`, `Yes` or blank),
  External, External booking URL, Session agenda, Event fee,
  Discounted fee, Venue capacity, Tickets available, Bookings, Awaiting
  payment, Places left, Venue. (This list had drifted: it still named a "Run by
  LAW" column that the external-events work replaced with External and External
  booking URL, and it predated Host organisation(s) and Awaiting payment.
  Corrected 15 September 2026.) Bookings and Places left are the same two figures the screen table
  shows (`law_event_attendee_total()` and `law_event_tickets_remaining()`),
  with Places left blank when no capacity is set, i.e. not open for booking.
  The fee column
  reads the `_law_fee_pence` approval snapshot but falls back to
  `law_event_calculate_fee_pence()` when it is not set yet, so pre-approval
  events export their live fee rather than £0.00.
- **Endpoint**: GET `admin_post_law_committee_export` with
  `format=csv|xlsx|json`, nonce `law_committee_export` in the URL
  (`wp_nonce_url()`, like the migration report export) +
  `law_user_is_committee()` (committee members lack `manage_options`, so the
  migration gate could not be copied). `format=json` verifies the nonce
  manually first and answers failures with `wp_send_json_error( …, 403 )`
  (the `law_ajax=1` reasoning from the action handler); no `_nopriv` twin —
  logged-out hits get core's default 400. Filenames:
  `events-dashboard-Ymd-His.csv|.xlsx`.
- **CSV** (`law_events_send_csv()`): the migration-report pattern plus a
  UTF-8 BOM (Excel renders £ as Â£ without one) and
  `law_events_csv_guard()`, the OWASP formula-injection prefix (a leading
  `'` on cells starting `=`, `+`, `-`, `@`, tab or CR, tested past leading
  whitespace since some imports trim the cell first).
- **XLSX** (`law_events_send_xlsx()`): a hand-rolled minimal .xlsx via
  ZipArchive — five parts (`[Content_Types].xml`, `_rels/.rels`,
  `xl/workbook.xml` + its rels, `xl/worksheets/sheet1.xml`) — so the theme
  ships no spreadsheet library (`vendor/` is dev-only and gitignored).
  Inline strings (`t="inlineStr"`, typed text is inert in Excel, which also
  settles XLSX injection), ints as `t="n"` so ID/tickets sort numerically,
  explicit `r=` cell refs, `xml:space="preserve"`, and
  `law_events_xml_text()` strips XML-1.0-forbidden C0 controls (Excel shows
  a repair prompt otherwise). Written to `wp_tempnam()` (ZipArchive cannot
  write to `php://memory`), streamed, unlinked.
- **PDF**: client-side pdfmake (the legacy DataTables mechanism), built by
  `assets/js/export-buttons.js` from the endpoint's `format=json` branch
  (`{title, filename, columns, rows}`; the filename keeps the legacy
  "Events dashboard London Arbitration Week.pdf"). A3 landscape, 7pt,
  repeating header row. pdfmake 0.2.12 + vfs_fonts (Roboto) live in
  `assets/js/vendor/`, enqueued footer-side on the dashboard list view only
  and only for committee users (~3MB; other logged-in visitors of the page
  just get its "committee only" notice and no export UI).
- **No-JS**: the CSV/Excel buttons are plain links whose hrefs bake in the
  server-rendered filters; export-buttons.js refreshes them with the live
  filter values on `mousedown`/`keydown` (mirroring
  `filterParams()` in calendar-filters.js) so exports follow AJAX filter
  changes without a reload. The PDF button renders `hidden` and is only
  unhidden by JS when `fetch` and `pdfMake` exist.

### Stripe (`stripe/client.php`, `stripe/service.php`, `stripe/attendees.php`, `stripe/webhook.php`)

- **`client.php`** — `law_stripe_request()`: a thin `wp_remote_request` client
  (form-encoded, idempotency-key header on POSTs). `law_stripe_verify_signature()`:
  the webhook HMAC v1 check with `hash_equals` and a 300-second replay
  tolerance. Keys come from the `LAW_STRIPE_*` wp-config constants.
- **`service.php`** — `law_stripe_create_and_send_invoice()` and
  `law_stripe_invoice_steps()`: upsert the customer, create/finalise/send the
  invoice for the snapshot fee with the configured tax rate, resuming cleanly
  on a retry (a finalised invoice is reused, a leftover draft deleted, and the
  invoice ID is persisted before the line item so nothing double-bills).
  `law_stripe_event_metadata()`: the shared metadata block (`law_reference`,
  `law_event_id`, `gf_entry_id`) attached to **both** the customer and the
  invoice so webhooks resolve.
- **Customer identity is guarded, because the invoice email is host-supplied
  and never verified.** `law_stripe_customer_body()` builds the payload;
  `law_stripe_upsert_customer()` then decides what it is allowed to write:
  - a customer ID **stored on this event** was created for this event, so it is
    overwritten in full;
  - otherwise an exact email search runs, and
    `law_stripe_customer_ownership()` reads the match's metadata — `mine`
    (`law_event_id`/`gf_entry_id` matches this event) gets the full update,
    `unclaimed` (no binding metadata) is adopted via
    `law_stripe_customer_fill_blanks()`, which writes our metadata plus only
    the fields the customer does not already have (address all-or-nothing,
    since Stripe replaces the whole address hash), and `other` (bound to a
    different event) is **left untouched** and a separate customer is created.
  Without this, a host could point their invoice email at another
  organisation's billing address and overwrite that customer's name, address,
  VAT ID and metadata, then have our invoice sent to them. Both the "not
  reused" and "adopted" outcomes are written to the activity log.
- `law_stripe_maybe_attach_vat_number()`, `law_stripe_record_failure()`, and
  `law_event_handle_retry_invoice()` (a committee "retry invoice" admin-post,
  refused unless the event is Approved **or Confirmed** and still unpaid —
  which also keeps a cancelled event out of the invoice path). Confirmed joined
  Approved on 17 September 2026: the `unpaid` test is what stops a settled
  event getting a fresh "payment due" email, and a post-approval fee change can
  leave a Confirmed event unpaid with its old invoice voided and the
  replacement not raised, which is exactly the state this button recovers.
- `law_stripe_void_invoice( $event_id, $actor, $context )`: stops a live
  invoice — a `draft` is deleted (the same rule the resume path applies), an
  `open`/`uncollectible` invoice is voided (with an idempotency key), a `paid`
  one is left strictly alone (refunds are a manual committee decision; the
  cancel side effects send the alert), and `void` logs "already void".
  **Never fatal**: every failure is logged (`invoice_void_failed`) and alerted
  to admins + committee via `admin_stripe_error` with a "void it manually"
  message, and the caller completes regardless. The invoice ID/URL meta is kept
  for the audit trail. Two callers: the cancel side effects
  (`$context = 'cancellation'`) and `law_event_apply_fee_change()`
  (`'fee_change'`). The context changes the WORDING only — a log line reading
  "voided after cancellation" on an event that was never cancelled is worse
  than no log line, because somebody will believe it.
- **`attendees.php`** (10 September 2026) — attendee payments, as opposed to
  the host fees the two files above bill. Three things are shaped differently
  here: the customer belongs to a **user** rather than to an event, the card
  is saved before anyone decides whether to charge it, and the charge then
  runs off-session with nobody at the keyboard.
  - `law_stripe_user_customer_id()` is deliberately NOT
    `law_stripe_upsert_customer()`, which will adopt an unclaimed customer
    that merely shares an email address. That is right for a host, whose
    invoice email may legitimately predate us in Stripe from the legacy
    system; it is wrong for an attendee, whose WordPress email is verified and
    who has no such history, because adopting would attach a delegate's card
    to somebody else's record. This searches only customers we ourselves bound
    to a user.
  - `law_stripe_create_setup_session()` opens Stripe **Checkout in `setup`
    mode**: the hosted page saves a card without charging it, with 3DS handled
    there and PCI scope never reaching LAW. One function serves all four
    reasons a method is saved (`apply`, `replace`, `retry` and, since
    14 September 2026, `waitlist`), so those journeys cannot drift; a fresh
    attempt number per session stops Stripe replaying the first,
    already-used session.
  - `law_stripe_create_checkout_session()` (14 September 2026) is the other
    half: Checkout in **`payment` mode**, where the delegate sees "Pay £54.00"
    and pays there and then. `invoice_creation` is on, so they still get a VAT
    invoice and a PDF — an invoice is what a firm reclaims VAT against, and a
    card receipt is not — and `customer_update[address|name]=auto` is what
    lets the address Stripe collects reach the Customer that invoice is
    addressed to. The line item carries the **net** with the tax rate
    attached, never the gross, or VAT would be charged on VAT; a vatable place
    with no tax rate configured refuses `law_no_tax_rate` rather than quietly
    billing the net and leaving LAW owing the difference. `expires_at` is
    `now + 1800 + 60`: 1800 is Stripe's minimum and exactly 1800 is refused
    under clock skew. `law_stripe_expire_checkout_session()` closes a session
    when a hold is released, and hands back a `complete` one so the caller can
    confirm instead of cancelling.
  - `law_stripe_attach_setup_result()` **refuses a session whose metadata
    names a different booking**, so pasting somebody else's session id onto a
    return URL adopts nothing. `law_stripe_store_payment_method()` is
    idempotent, because the delegate's return and two webhook types all reach
    it in any order.
  - `law_stripe_charge_booking()` raises and pays an **invoice**, not a bare
    PaymentIntent (Denis, 10 September 2026): it carries the VAT line and
    LAW's VAT number and leaves the delegate a hosted invoice and a PDF, which
    a card receipt does not, and it reuses the tax rate object and the webhook
    branches 4.1 already had. It copies `law_stripe_invoice_steps()`'s resume
    discipline step for step — persist the invoice ID before the line item,
    reuse a finalised invoice, delete a leftover draft, key every write — so a
    retry after a timeout can never bill anybody twice.
  - `law_stripe_detach_payment_method()` is **never fatal**: a card Stripe
    will not detach is logged and the local reference cleared anyway, because
    refusing to decline somebody over it would be worse.
- **`webhook.php`** — a REST route (`rest_api_init`) whose permission callback
  is `__return_true` (auth is the signature, verified on the raw body before
  decode). `law_stripe_webhook_handler()`: signature check → idempotency
  (processed-event list plus an atomic per-event lock against concurrent
  double-delivery) → dispatch. `law_stripe_handle_invoice_paid()` marks paid,
  reconciles the amount against the snapshot (via `law_events_vat_rate()`) and
  confirms the event — and when the event is already **`law-cancelled`** (a
  failed void, or the host paid in the race before the void landed) it logs
  loudly and sends `committee_cancelled_paid` instead of confirming, because
  money arriving for a cancelled event must never be silent;
  `invoice.payment_failed`/`voided`/refund are logged and
  alerted (never auto-unpublished — a human decision).
  `law_stripe_resolve_event_id()` resolves the event via metadata, with the
  `_law_gf_entry_id` meta fallback.
  **Since 10 September 2026 one endpoint serves two subjects**: a host fee
  raised against an event, and an attendee place raised against a booking.
  They are told apart by the metadata Stripe hands back
  (`law_stripe_resolve_booking_id()`), never by the event type, so nothing had
  to fork. `checkout.session.completed` (setup mode), `setup_intent.succeeded`
  and `setup_intent.setup_failed` handle the card being saved (or not — without
  the last one an application sits in `pending_setup`, invisible, until the
  48-hour sweep); `invoice.payment_action_required` is handled as a distinct
  state from a decline, because the card is fine and the bank simply wants the
  delegate present, so offering a new card there would be the wrong advice.

  **Since 14 September 2026 the dispatch names no flow at all** (RECEPTIONS.md
  §3.2). An attendee booking now has three KINDS — a flagship application, a
  reception place, a hosted booking that never reaches Stripe — each meaning
  something different by "paid" and "failed". So `webhook.php` resolves an
  OUTCOME (`card_saved`, `setup_failed`, `paid`, `processing`,
  `payment_failed`, `action_required`, `refunded`, `session_expired`) and
  hands it to `law_booking_dispatch_payment()`, which looks
  `law_booking_kind()` up in a **handler table** each flow registers for
  itself through the `law_booking_payment_handlers` filter — the same pattern
  that keeps "the flagship is different" out of `bookings-dashboard.php`.
  "No handler for this kind" logs and changes nothing, which is exactly what
  the three verbatim fail-closed blocks inside the flagship functions used to
  do by hand. Four new event types come with it: `checkout.session.completed`
  in payment mode (→ `paid` when `payment_status` is `paid`, else
  `processing`), `async_payment_succeeded`, `async_payment_failed` and
  `expired`. **Every `checkout.session.*` handler ignores a session whose id
  is not the one the booking is waiting on**, so a superseded session's late
  expiry can never release a live hold. The handler also fires
  `law_stripe_webhook_dispatched`, which the receptions' sweep hangs a cheap
  bounded pass off.
  **A partial refund no longer marks anything fully refunded**: the
  `charge.refunded` branch now compares `amount_refunded` against `amount`, and
  a goodwill part-refund is logged and alerted without moving the payment
  status (this was an open finding in §6).

### Admin UI (`admin/`)

- **`fields.php`** — `law_field_text/number/textarea/select/checkbox/datetime/`
  `media/repeater/relationship()`: the reusable meta-box field renderers, plus
  a `wp_ajax_law_events_search_posts` endpoint and `law-admin.js` enqueue that
  power the speaker/organisation relationship pickers. A speaker row carries
  a Role select (a blank `Select role` placeholder first, then Speaker / Host /
  Moderator from `law_speaker_roles()`; a free-text input until 8 September 2026
  and defaulted to Speaker until 9 September 2026), organisation, job title, a
  per-appearance photo control
  (`law_field_relationship_photo()`, `wp.media` in `law-admin.js`) and a
  per-appearance biography textarea on its own full-width line. `law-admin.js`
  builds the identical markup for rows added via the AJAX search, so the two
  cannot drift; the role choices reach it as `lawEventsAdmin.roleChoices`
  through the existing `wp_localize_script()` call. The same picker renders
  on the session screen, which is where a per-session role override lives.
  **Choosing an existing speaker prefills the row** (Denis, 11 September 2026):
  the search endpoint returns `law_speaker_row_prefill()` alongside each
  speaker result — role, organisation, job title, photo (ID and thumbnail URL)
  and biography from that person's latest appearance, falling back to their
  profile — and `addItem()` writes them into the new row before TinyMCE
  attaches, so the committee edits values instead of retyping them. They are
  defaults, not facts about the person: every field stays editable and what is
  saved is still the appearance at THIS event or session. The values are
  assigned as element properties after the row markup is built, never
  interpolated into the HTML string, since the biography is rich text and an
  organisation may contain a quote or an ampersand. Each **search suggestion**
  shows the speaker's name with their job title and organisation under it in
  small type (`.law-rel-result-name` / `.law-rel-result-meta`, styled in
  `law-admin.css` and `flagship-dashboard.css`). It used to read
  `Name (#15188)`; the post ID told a committee member nothing about which of
  two similar names they were picking (Denis, 11 September 2026). The meta line
  is omitted for a speaker with no appearance on record.
- **`event-screen.php`** — the custom event edit screen: meta boxes for
  workflow actions, fee (with override), programme facts, invoice contact,
  people (co-owners/contacts), speakers, sessions, the comment thread and the
  activity log. `law_event_admin_save()` (on `save_post_law_event`, nonce +
  cap + reentrancy guard) writes the meta, applies the slot via the shared
  helper, and routes committee actions through the workflow engine.
  The fee override flag and its amount are written **as a pair, and only when
  the fee box was on the form** (9 September 2026): the blank-means-£0.00 trap
  is refused with a notice, and a fee box hidden by Screen Options can no
  longer clear the override flag by omission. When the fee inputs change the
  save asks `law_event_fee_edit_mode()`: on `reissue` it calls
  `law_event_apply_fee_change()`, the same orchestrator the committee dashboard
  calls, so this screen voids and reissues rather than telling the admin to
  finish the job by hand in Stripe (17 September 2026); on `locked` it puts the
  tier, the flag and the amount back as they were, logs the refusal and says
  why, because the inputs are left enabled (`law_field_checkbox()` takes no
  attributes, and a disabled input posts nothing, which this handler would read
  as "not on the form" rather than as "unticked").
  `law_event_admin_notice()` queues these one-shot notices instead of
  overwriting, since one Update can have two things to say.
  A **pre-existing bug** was found here while making that change and fixed:
  `$before_override` held the fee override and was then overwritten by
  `law_event_booking_override()` four lines later, so both readers of the fee
  value ran on the booking override's string. Compared strictly against an int,
  "have the fee inputs changed?" answered yes on every single Update and
  `law_event_log_fee_change()` wrote a spurious "override enabled" line each
  time. Cosmetic until that reader started voiding Stripe invoices. The booking
  one is now `$before_booking_override`.
  `law_events_rows_from_post()` reads the repeater rows, sanitising per key
  rather than mapping one function over the row: a speaker's `bio` takes
  `sanitize_textarea_field()`, since `sanitize_text_field()` would collapse its
  line breaks before the meta schema's own textarea sanitiser ever saw them.
- **`speaker-screen.php`, `session-screen.php`**: the speaker and session edit
  meta boxes and their saves. The speaker screen's details box carries **First
  name** and **Last name** (prefilled by splitting the title on a record that
  predates them) above the email and website, and the save rebuilds the post
  title from the two, guarded by a static flag against re-entering its own
  `save_post` hook. The read-only "Appears at" box lists each confirmed event
  with "role, job title, organisation".
- **`columns.php`**: admin list columns (status, host, slot, payment), a status
  filter dropdown, and the `pre_get_posts` wiring for it.
- **`booking-screen.php`**: the read-only `law_booking` screen — Booking facts
  (number, status via `law_booking_status_label()`, the attendee and who
  invited them, both linked to their user screens, the parent event linked both
  ways, and the waitlist position/joined/promoted stamps where they apply) and
  Activity (the parent event's log filtered to this booking's context). There
  is no separate Attendees box: one booking is one attendee. The list columns
  are Booking, Event, Attendee, Invited by, Status, Date, plus the events
  list's "Booked" column (`sold / available`, red when sold exceeds available,
  which now also means a deliberate over-booking from the waitlist). Mutations
  stay front-end-only so the engine's guards always run, with **exactly one
  exception since 15 September 2026**: a side box offering **Ticket type** on a
  flagship booking, and with it the theme's only `save_post_law_booking`
  handler. It is safe here precisely because it is not booking machinery — no
  guard, no capacity recount, no email, no status and no price reads the value,
  and it is never shown to the delegate. The box is registered only when
  `law_booking_kind( $post )` is `flagship`, it saves behind
  `edit_law_events` (not `manage_options`, which would lock out the committee
  the field is for), and it writes through the same
  `law_flagship_set_ticket_type()` the dashboard uses, so both routes validate
  identically and leave the same activity-log line. Nothing else belongs
  there.
- **`flagship-screen.php`** (9 September 2026) — the Flagship screen, a submenu
  of Events at `edit.php?post_type=law_event&page=law-flagship`, capability
  `edit_law_events` (the committee holds the whole `law_event` cap set, and the
  speaker-search AJAX this screen leans on already checks that cap;
  `manage_options` would lock them out for no security gain). One form: title,
  description (rich text), date, location, banner image (`law_field_media()`),
  a "Show on the programme" tick box (`publish` / `law-draft`), and a sessions
  repeater. Same-page POST behind `check_admin_referer( 'law_flagship_save' )`,
  then **redirect on success** (unlike the settings screen, which echoes
  inline) because this save inserts posts and a refresh must not re-post it; a
  validation failure re-renders the posted values instead of losing them.
  - `law_flagship_input_from_post()` / `_sessions_from_post()` /
    `_speaker_rows_from_post()`: one recursive `wp_unslash`, then per-key
    sanitisation. A dedicated reader, because `law_events_rows_from_post()` is
    flat and the sessions are nested three deep. Speaker rows are **not** put
    through the `speaker_rows` sanitiser at read time — a "new speaker" row has
    no `speaker_id` yet, so that sanitiser would drop it and its identity
    fields; it runs inside `law_event_update_meta()` once the new speakers have
    been upserted to IDs.
  - `law_flagship_validate()`: title, date, per-session title and start, end
    not before start, and a new speaker's first and last name. The times are
    compared **as the schema will store them** (zero-padded), because `10:30`
    is lexicographically less than `9:30` and an unpadded pair was refused as
    ending before it starts. A new speaker's email is optional here but
    validated when given (the committee typing a conference programme often
    will not have one; the host form requires it because a host knows their own
    speakers). An existing `speaker_id` that is no longer a `law_speaker` is
    refused, not silently dropped.
  - `law_flagship_save_sessions()` mirrors `law_events_form_save_sessions()`,
    including its ownership rule: a posted session ID is honoured only when
    that session is already a child of this event, so a forged value creates a
    new session instead of seizing someone else's.
  - `law_flagship_resolve_speaker_rows()` sends each "new speaker" row through
    `law_speaker_upsert()` (gap-fill mode, so it links to an existing profile
    by email or name rather than duplicating a person) and uses the returned ID
    as the row's `speaker_id`. After a successful save the row re-renders as an
    ordinary picked speaker, so `is_new` never survives a round trip.
  - `law_flagship_snapshot()` / `law_flagship_log_save()`: one activity-log
    line per save that changed something ("Flagship event saved: shown on the
    programme; date … → …; 2 session(s) added (…); speaker(s) added …"), and
    **no line when nothing changed**, so the log stays a record of decisions
    rather than of clicks. Titles come from `get_post_field()`, not
    `get_the_title()`, because `the_title` runs `wptexturize` and a log line is
    read as text.
  - The sessions repeater is **not** `law_field_repeater()`. That helper clones
    `input[data-law-name]` only, so a select, a rich-text field or a nested
    picker in its template row would never be renamed, and its index is a row
    count, so removing a middle row and adding another posts two rows into one
    slot. This screen follows the front-end pattern in `event-form.js`: a
    monotonic counter on the wrapper, `data-name` on template fields, and the
    template row marked **`data-law-row-template`**, which is the attribute
    `law-rich-text.js` checks before it attaches an editor.
  - Each session's speakers use `law_field_relationship()` with its new
    `$args` (below) plus an "Add new speaker" button, whose row carries first
    name, last name, email and website alongside the ordinary per-appearance
    controls. `law_flagship_render_new_speaker_template()` prints that markup
    once in a `<template>` for the JS to clone; content inside `<template>` is
    inert, so TinyMCE never attaches to it.
- **`fields.php` additions**: `law_field_relationship()` takes an optional
  sixth `$args` — `render_row`, a callable replacing
  `law_field_relationship_row()` per row (the Flagship screen dispatches its
  "new speaker" rows to its own renderer), and `after_list`, a callable that
  prints whatever follows the chosen list. `after_list` is a callable and not a
  string of markup deliberately: a string would have to be echoed unescaped,
  and "the caller escaped it" is a convention the next caller can break by
  interpolating a title or a search term (a security review flagged exactly
  that, 9 September 2026). The empty-ID skip moved into the default branch, so
  a new-speaker row survives a failed-validation re-render.
  `law_field_relationship_photo()` no longer casts its index to int, so a
  template row's `__j__` placeholder survives. Asset versions bumped to 1.5.
- **`event-screen.php` additions**: for the flagship the save skips the
  `_law_slot_label`, `_law_start` and `_law_end` keys and the
  `law_event_apply_slot_label()` call, and runs `law_flagship_recompute()` at
  the end instead — the slot select is empty for the flagship, and an empty
  slot clears the datetimes for an ordinary event. An `admin_notices` line on
  its edit screen points at the Flagship screen and explains that the slot and
  time fields do not apply.
- **`emails-screen.php`**: the Emails screen (its own top-level menu at
  position 7, directly under the Events menu at 6; formerly LAW > Emails, same
  `law-events-emails` slug and URL) — list, edit, send-test and
  reset for the notification registry, overrides stored in one option, plus a
  "review tags" flag on any migrated body still carrying unresolvable Gravity
  Forms merge tags. It also hosts the **Enable test mode** card
  (`law_events_emails_test_mode_card()` /
  `law_events_emails_handle_test_mode_post()`, see `test-mode.php`), including
  the live address check and the live-site confirmation tick. Since 16 September 2026 it
  owns none of the editing logic: the registry, the sanitising and the writer
  live in `notifications.php` and are shared with the committee's front-end
  Manage emails screen (`emails-dashboard.php`).

### Migration (`migration/report.php`, `migration/runner.php`, `migration/page.php`, `migration/repair-owners.php`, `migration/repair-references.php`, `migration/repair-stripe-invoice-ids.php`, `migration/repair-nbsp.php`, `migration/content-transfer.php`)

- **`report.php`** — a custom log table (`law_migration_log`), `law_migration_log()`,
  per-step summaries and a tail for the admin panel, plus the snapshot-download
  and CSV-export admin-post handlers.
- **`runner.php`** — the engine. `law_migration_steps()` defines the ordered
  steps: snapshot → preflight → co-owners → speakers → events → external
  events (3b) → sessions →
  speaker appearances (4b) → comments → history → counters → redirects →
  notifications → pages.

  **Step 3b (`law_migration_run_external_events()`, 15 September 2026)** reads
  form 10 (Event > external events) and creates published `law_event` posts
  flagged `_law_is_external`. Its PLACEMENT is load bearing twice over: 21 of
  the 31 active form 9 (Event > session) entries are children of a form 10
  entry, and step 4 resolves a session's parent through `$map['events']`, so
  putting the external events in that map first means step 4 picks their
  agendas up with no change to it at all — run it afterwards and those 21
  sessions are skipped and lost. And it goes BEFORE step 11 (`retire_roles`),
  which must stay last. The events arrive `publish` rather than `law-draft`
  because form 10 has no status field and every active entry is already on the
  programme; migrating them as drafts would take four events off it at cutover.
  Form 10 also joined `law_migration_module_form_ids()` (so the source flip
  deactivates it) and the preflight's structure map.

  Step 2 imports each form 8 (Event > speaker) child's
  photo once and records it in the map (`speaker_photos`); step 3 builds the
  event's rows with `law_migration_speaker_rows()` (field 9 Role — the
  Speaker / Host / Moderator drop down Trevor added to the live form on
  3 September 2026, mapped by label through `law_speaker_role_key()`; field 3
  Organisation / firm / chambers, field 4 Job title / role, field 7 Biography,
  the mapped photo; legacy field 48 Speakers (list) rows as the fallback,
  which has no biography or role column); step 4 copies the event row onto
  session rows (`law_migration_session_speaker_rows()`); step 4b
  (`law_migration_run_speaker_appearances()`) refreshes existing rows' role,
  organisation, job title, photo and biography from the source entries,
  idempotently, so a database migrated before details were per appearance is
  fixed without a fresh run, and production gets a check. Its comparison
  sanitises each field the way the schema would (the biography with the
  textarea sanitiser, so line breaks are not a permanent diff) and truncates
  biographies in the log line, which would otherwise be paragraphs long. An
  empty source value never blanks a stored one, so a database without field 9
  (local, or staging pulled before 3 September 2026) leaves a committee-set
  role alone; the preflight adds a **warn-only** "Form 8 field 9 (Role)" line
  rather than putting the field in the structure map, so such a database
  still passes.
  `law_migration_create_snapshot()` runs `mysqldump` (password via `MYSQL_PWD`,
  not a `-p` CLI arg) with a random filename in a protected dir, then
  `law_migration_snapshot_ok()` fetches the snapshot's own public URL
  unauthenticated and deletes it if the server actually serves it (the
  nginx-ignores-.htaccess guard). `law_migration_preflight()` gates on data
  health, including two checks added on 15 September 2026 when the production
  database was pulled down for the cutover rehearsal. **"Entry counts within
  the read limits"** is blocking: neither `law_migration_entries()` nor
  `law_migration_children()` pages, so `LAW_MIGRATION_ENTRY_PAGE_SIZE` (500) and
  `LAW_MIGRATION_CHILD_PAGE_SIZE` (100) are the points past which rows are
  simply never read, and a run would report success with data missing. The
  check names every form and parent that has reached a cap, resolving each
  parent's own form rather than assuming form 2 (Event > submit an event),
  because nested children hang off form 10 (Event > external events) too.
  **"Unknown active forms"** is warn-only: it lists any active form outside
  `law_migration_known_form_ids()` by ID and title. Such a form is neither
  migrated nor deactivated by the source flip, so it stays submittable through
  GF's REST endpoint — which is correct for form 7 (Contact) and, for now, for
  form 10 (Event > external events), and would be wrong for anything else. The
  form-ID lists live in `law_migration_module_form_ids()`,
  `law_migration_known_form_ids()`, `law_migration_paged_form_ids()` and
  `law_migration_child_form_ids()`, which the source flip in
  `migration/page.php` now reads too, so the list exists once rather than in
  four places. The `law_migration_run_*()` functions import each entity from GFAPI;
  `law_migration_derive_payment()` sets the payment status from the legacy
  status (field 96 Payment status was blank on every entry — defect 1);
  `law_migration_slot_labels()` reads the canonical slot labels straight from
  form 2 (Event > submit an event) field 68 (Confirmed slot) — from the form,
  not the settings, because step 3 (events) runs before step 7 seeds them — and
  `law_migration_normalise_slot_label()` maps both field 68 and the
  hyphen-punctuated field 77 (Preferred date & time slots) values onto them,
  keeping anything unrecognised verbatim; the preflight warns (non-blocking) about
  field 77 values that match no choice. `law_migration_slot_was_retired()`
  carries the retirements across: the four slots
  `LAW_GF_RETIRED_PREFERRED_SLOTS` hid on the live form (Tue 1st Dec
  08:30-10:00, Tue 1st Dec 16:30-18:00, Tue 1st Dec 18:30 onwards, Thu 3rd Dec
  18:30 onwards) are seeded with the settings list's own `retired` flag, so the
  custom form offers the same eight slots Gravity Forms did. Step 7 rewrites
  the whole slot list, so it also preserves any flag the committee set by hand
  — otherwise a re-run would quietly un-retire a withdrawn slot;
  `law_migration_translate_tags()` rewrites GF merge tags into the module's
  placeholders. `law_migration_run_step()`, `law_migration_verification()` and
  `law_migration_spot_checks()` drive and verify a run. **The history step is
  time-boxed**: a real run stops between events after ~20 seconds and returns
  `done => false`, and the page JS keeps requesting batches until `done` — one
  request per batch, so a proxy upstream timeout (hit on Kinsta staging, where
  the ~1,000 timeline inserts exceeded it in a single request) can no longer
  kill the step. The guard never fires mid-event, so the per-event
  `_law_history_migrated` flag still guarantees an event's timeline is written
  whole or not at all.

  **Made ~2.5x faster on 15 September 2026**, after the staging rehearsal
  against production data sat on this step for 25 minutes and visibly slowed
  down as it went (3 events per batch, then 2). Two causes, both measured:

  - `wp_insert_comment()` finishes with `wp_update_comment_count()` — a
    `COUNT(*)` over `wp_comments` for the post, an `UPDATE` on `wp_posts` and a
    `clean_post_cache()`, **per comment**. A timeline is hundreds of comments on
    ONE post, so that recount ran hundreds of times to reach a number only the
    last one needed, and got dearer as the post's comments accumulated. The loop
    now runs inside `wp_defer_comment_counting( true )`, released in a `finally`
    so the time-box's early `return` cannot leave it on. This is the larger half
    of the win, and it is largest on a remote database where every query is a
    round trip.
  - A real run re-walked the WHOLE map every batch and wrote a
    `law_migration_log()` row — an `INSERT` — for each event it then skipped, so
    batch N paid for everything batches 1..N-1 had done. Quadratic over a run,
    and the reason for the visible tail-off.
    `law_migration_history_pending()` now returns just the outstanding events
    from one query, and `law_migration_history_revisions()` scopes the revision
    scan to them. **A dry run still walks everything and still reports the
    already-migrated ones**, because a preview describes the whole picture and
    runs in a single request, so none of the above applies to it.

  Measured locally over 103 events with synthetic notes, rolled back after:
  12,141 timeline comments went from 18.1s to 7.7s; 32,741 went from 53.2s over
  three batches to 21.2s over two, with log rows down to exactly one per event.
  Verified alongside: `comment_count` correct on every post both mid-run and
  after (deferring changes when the recount happens, not its result), a second
  full run writing nothing, the dry run still writing nothing and still logging
  one row per event, and the deferral flag off on both exit paths. **Step 10 (account page templates)**,
  `law_migration_run_pages()`, reconciles the account pages with the templates
  the rebuild expects (`law_migration_page_map()`, keyed by page path):
  assigns the right template where a page exists with the wrong one, creates a
  missing page under its parent, and never touches existing page content. The
  module resolves all of these pages by path, never by ID, so created pages
  get new IDs safely. This exists because page templates are database state:
  the git deploy cannot carry the local re-templating to another environment,
  which left staging rendering the legacy GravityView dashboards after the
  source flip.
  The page map gained **`account/dashboard/flagship`** (Flagship dashboard,
  `templates/account-dashboard-flagship.php`) with the committee screen, and
  the step copies the parent dashboard's Members restriction onto it the way it
  does for Bookings and Manage Speakers
  (`law_setup_flagship_dashboard_access()`).
  **Step 10 also provisions the flagship conference** (9 September 2026):
  `law_flagship_ensure_post( $dry )` at the end of `law_migration_run_pages()`,
  with its own log line and a `flagship event …` clause on the step summary.
  It is not in `law_migration_page_map()` because it is not a page: it is a
  `law_event` post whose slug gives it `/events/flagship/`, so there is no
  template to assign, only the record to create. The step's label is now
  "account page templates and the flagship event". The same helper runs from
  the `?setup-account-pages` trigger and from the Flagship screen's first
  open, so no environment needs a manual step after a push.
  **Step 10 also provisions the three receptions** (14 September 2026), the
  same way and for the same reason: `law_reception_ensure_posts( $dry )` seeds
  `opening-drinks`, `wednesday-reception` and `friday-reception` as `law-draft`
  records with **no price**, because the prices are LAW's to confirm and a
  figure invented here would go live the moment somebody ticked "Show on the
  programme". The page map gained
  **`account/dashboard/receptions`** with `law_setup_receptions_dashboard_access()`
  beside it. Deliberately INSIDE step 10 rather than a step of its own, because
  step 11 (`retire_roles`) must stay last in `law_migration_steps()`.
  **Step 10 also strips the "Roles: {user_roles}" line** from the two
  user-registration emails (`law_setup_strip_user_roles_from_emails()`, shared
  with the trigger). Editing the registry default was not enough and is the
  lesson worth keeping: step 9 imported those notifications from Gravity Forms
  as stored OVERRIDES, and an override beats the default, so the committee kept
  reading "Roles: None ticked" on every new account until the store itself was
  cleaned. The whole line goes rather than the token, since blanking the
  placeholder would leave a bare "Roles:" behind. Step 9's own translation of
  `{all_fields}` no longer emits the line either, so a fresh migration cannot
  reintroduce it.
  **Step 10 also carries two pieces of the role retirement** (14 September
  2026), both shared with the `?setup-account-pages` trigger:
  `law_setup_account_page_content()` (which replaced
  `law_setup_account_page_audience()`) strips the `[user-content]` blocks from
  the `/account/` body, leaving the `[action-message]` paragraph the hub
  template needs; and `law_setup_account_page_roles()` (which replaced
  `law_setup_account_events_attendee_access()`) makes `/account/`,
  `/account/events/`, `/account/bookings/` and `/account/events/submit/` admit
  every role a signed-in person can hold. That second one is load-bearing, in
  both directions. **`subscriber`** is why step 11 runs after step 10: strip
  somebody's `event_host` before that row exists and the Members plugin locks
  them out of their own account, with a refusal that comes from the plugin
  rather than from anything in this theme. **The three retired roles** cover the
  window before step 11 runs, and page 294 (Submit an event) is the case that
  proves it was needed: it never admitted `attendee`, because attendees could
  not submit, so from the deploy until the step ran an un-migrated attendee was
  told by the code that they could submit an event and refused the page by the
  plugin. Nothing in the theme could have detected that.
  Pages with no restriction rows are left alone (the plugin reads none as
  public) and no role row is ever removed, so a rollback stays a code revert.
- **Step 11 (`law_migration_run_retire_roles()`, 14 September 2026)** — the
  role retirement itself: every account holding `event_host`, `sponsor` or
  `attendee` becomes a plain `subscriber`, with its hosting/sponsor intent
  preserved as `law_intent` user meta. **Last in `law_migration_steps()` on
  purpose**, because "Run all" walks that array in order and step 10 must have
  written the subscriber Members rows first. The judgement lives in a pure
  per-user helper, `law_migration_retire_user_roles( WP_User $user, $dry )`,
  which is what the tests drive (the step itself writes to the log table, and
  creating that table is DDL, which would commit the transaction the suite rolls
  each test back with). Three invariants it exists to hold:
  **never `set_role()`** (two live accounts hold administrator alongside a
  retired role, and a sweep would demote them); **never role-less** (subscriber
  goes on before anything comes off); and **never overwrite an intent somebody
  has already chosen** (only the ABSENCE of a `law_intent` row means "never
  asked", so a re-run after a profile edit is safe). The seed is the honest
  translation: `event_host` → the hosting tick, `sponsor` → the sponsor tick,
  `attendee` → nothing, because attendee was what everybody was. Idempotent by
  construction rather than by a flag — the query asks for accounts that still
  hold one of the three, so a processed account cannot come back and a re-run
  reports "Nothing to do" — which is also what makes the 20-second time-box
  safe. One log line per account, naming exactly which roles came off which
  account: that is the only record a rollback would have to work from.
  `law_migration_verification()` counts accounts still holding a retired role,
  which must read 0 afterwards.
- **`repair-owners.php`** — a one-off repair for the events whose
  `post_author` and `post_date` were overwritten by the pre-9-September-2026
  `wp_insert_post()` bug in `law_events_form_save()` (above), rendered as a
  dry-run-first panel on the LAW > Migration screen via
  `law_events_repair_owner_panel()`. `law_events_repair_owner_origin()` reads
  the true owner and submission time out of the append-only activity log,
  preferring the entry whose context `action` is `submit` (written by the
  status engine with the acting user, and only a host can fire it) and falling
  back to the earliest entry by a real user.
  `law_events_repair_owner_form_save_by()` is the safety rail: an author is
  only ever moved when the log ALSO shows the current owner saving that event
  through the front-end form (`committee_edit` / `host_edit`), because a
  deliberate reassignment through the wp-admin author dropdown leaves no such
  line and must be left alone. Everything else lands in a "needs a human"
  table. The scan queries every registered post status rather than `'any'`
  (which drops both the trash and the module's custom statuses) and reads the
  log with comment status `'any'` rather than reusing
  `law_event_log_entries()`, because `wp_trash_post_comments()` rewrites a
  trashed event's comments to `post-trashed` and `'all'` in
  `WP_Comment_Query` still means approved-or-held. Every repair writes its own
  activity-log line with the old and new values.
- **`repair-references.php`** — the panel that reassigns event references to
  the Gravity Forms entry IDs after the 14 September 2026 decision, rendered on
  the LAW > Migration screen by `law_events_reference_panel()` above the
  owner repair. `law_events_reference_expected()` is the rule in one place
  (`_law_gf_entry_id` when the post has one, else the post ID) and is what
  `law_migration_populate_event()` now agrees with, so a fresh migration needs
  no repair at all. The proposed ID always comes from the stored entry link,
  **never from a title match**; the entry is then read back from Gravity Forms
  (`law_events_reference_entry_check()`) so the panel can show its own field 17
  (Event title) and field 70 (Unique ID) beside the post's title, flag a real
  difference with `law_events_reference_titles_differ()` (compared loosely, so
  punctuation and entities are not a mismatch) and say plainly when an entry is
  no longer readable. The scan refuses to create a duplicate: if two events
  would end up holding the same number they both land in the "needs a human"
  table and neither is offered, which cannot arise with the live data (entry
  IDs run to 1,171, new post IDs are past 260,000) but is not left to trust.
  Every registered post status is scanned, trash included, for the same reason
  `repair-owners.php` does it, and each change writes an activity-log line with
  the old and new reference.
  It also **re-stamps Stripe**. Every customer and invoice raised for an event
  (by this module, or by the retired Make scenario before it) carries a
  `law_reference` metadata key written once at creation, so a reassignment
  leaves the Stripe dashboard quoting a reference the site no longer uses.
  Nothing in the code resolves an event by that key —
  `law_stripe_resolve_event_id()` uses `law_event_id`, then `gf_entry_id` —
  so the payment path is never at risk, but a human reconciling a payment reads
  it. `law_events_reference_sync_stripe()` patches the stored customer and
  invoice with the whole `law_stripe_event_metadata()` block (which also gives a
  Make-era invoice the `law_event_id` our webhook prefers); it is a
  metadata-only patch, which Stripe permits on a finalised or paid invoice
  (only monetary values and `collection_method` become uneditable at
  finalisation) and which merges, so Make's own keys survive. It is ticked by
  default on the apply and can be declined; a failure is logged on the event and
  reported in the notice but never holds up the reference, because the site's
  own record is the one that matters.
  `law_events_reference_stripe_rows()` / `law_events_reference_stripe_restamp()`
  are the same patch as a **standalone action**, listing every event that has a
  Stripe object whatever its reference, because the local site's references were
  reassigned on 14 September 2026 before the Stripe patch existed and the table
  above then offers no row to fix them from. The patch is idempotent.
- **`repair-stripe-invoice-ids.php`** (17 September 2026) — the panel that
  backfills `_law_stripe_invoice_id` on the events the retired Make scenario
  invoiced. Step 19 (Log invoice URL) wrote only `hosted_invoice_url` into
  field 83 (Stripe invoice URL) on form 2 (Event > submit an event), and there
  was no field anywhere holding the invoice's own `in_...` ID, so
  `law_migration_populate_event()` fills `_law_stripe_invoice_url` and leaves
  the ID empty. On the production copy scanned on 17 September 2026 that is
  every invoiced event: 33 Approved and 21 Confirmed, none with an ID.
  **The payment path was never affected** — a Make invoice carries
  `metadata[gf_entry_id]` and `law_stripe_resolve_event_id()` resolves an
  incoming `invoice.paid` through it, so a host paying a legacy invoice after
  cutover is confirmed and published normally. What the missing ID breaks is
  everything that has to *act on* the invoice: the resume-before-create guard
  in `law_stripe_invoice_steps()` cannot see it, so the wp-admin "Create
  invoice" button would raise a host's second invoice;
  `law_stripe_void_invoice()` cancels nothing, so a cancelled event leaves a
  payable invoice behind an email saying nothing is due; and the Stripe
  re-stamp above only visits events that already hold an object ID.
  `law_events_invoice_id_lookup()` asks Stripe twice over: the invoice search
  (`metadata["gf_entry_id"]:"<entry>"`, the key Make stamped), then, if that
  yields no usable match, the customer behind field 73 (Invoice contact email)
  and their invoice list, which is also the fallback when search is
  unavailable or eventually-consistent. A candidate is accepted only when its
  `hosted_invoice_url` is exactly the URL the event holds
  (`law_events_invoice_id_match()` reports that as the stronger signal and
  prefers it) or its `gf_entry_id` is exactly this event's entry; several
  matches, no match, or an invoice `law_events_invoice_id_claimed_by()` finds
  on another event are all reported and left alone rather than guessed at.
  The lookup is its own button (a handful of API calls per event has no
  business running on every page load), every call in it is a GET, and
  `law_events_invoice_id_apply()` re-runs the lookup rather than trusting the
  rendered page. The customer ID is taken from the matched invoice at the same
  time, since the legacy workflow never recorded that either, but never over
  one the module has since written.
  Two stopgaps protect the same events until the panel has run:
  `law_stripe_create_and_send_invoice()` refuses outright while a URL is
  present with no ID (logged on the event, but deliberately **not** recorded
  as a Stripe failure, because nothing failed and the alert email would say
  otherwise), and `law_stripe_void_invoice()` returns `failed` with an
  admin-and-committee alert naming the URL instead of the silent "no invoice
  on record" line. The admin event screen renders the button disabled with the
  reason rather than hiding it. Covered by `tests/LegacyInvoiceIdRepairTest.php`.
- **`repair-nbsp.php`** (17 September 2026) — the one-off sweep for rich text
  stored before `law_rich_text_normalise_spaces()` existed. It reads all four
  places rich text is kept: `post_content` on `law_event`, on `law_session`
  and on `law_speaker` (the fallback biography), and the per-appearance
  biographies in an event's `_law_speakers` rows. Every affected value is
  listed with the offending text shown in context, each row can be unticked,
  and a change to an event or one of its sessions lands in that event's
  activity log. **Rows are pre-ticked only where the damage is visible** —
  two or more non-breaking spaces in a row, which is three words joined and
  enough to widen a column; a lone stray one is listed unticked, because on
  its own it breaks nothing and may even have been meant. The apply re-runs
  the scan rather than trusting the rendered page, so a description re-saved
  while the panel sat open is never written back over. Only spaces change, no
  words and no markup, and re-running it is harmless. On the copy scanned on
  17 September 2026: 47 values held one, three of them in runs — the worst
  being the 21 in "Hot Topics in Energy and Mining Arbitration" that started
  this.
- **`page.php`** — the LAW > Migration screen and the
  `wp_ajax_law_migration_run` batched-step AJAX. All migration handlers are
  `manage_options` + nonce gated with a running-step lock.

### `migration/content-transfer.php`: between environments, as one zip (15–16 September 2026)

The Gravity Forms migration above moves data between two *shapes* on one site.
This is the other move nobody had a tool for: the same shape, between two
*environments*. The client fills the real receptions and flagship details in on
staging, and production has to end up holding them.

A git deploy already carries the code, and the theme already provisions the
empty records on any environment (`law_flagship_ensure_post()`,
`law_reception_ensure_posts()` — one flagship post and three `law-draft`
receptions, idempotent by slug). What a deploy cannot carry is what was typed
into them: dates, venue, places, prices, the price-switch datetime, the banner
image, the publish ticks, the whole flagship session agenda with its speakers,
and the discount catalogue. The panel sits on the Migration screen, between the
repair panels and the cutover form, and does exactly that and no more.

**Scope, as settled on 15 September 2026**: the receptions, the flagship with
its sessions and speakers, the discount codes, and the committee's customised
email wording. External events were out, for one day — see the widening below.
**Bookings are out and always will be** — they carry Stripe customer, invoice,
charge and payment-method IDs from whatever Stripe account the source site
points at, plus one-shot idempotency latches (`_law_confirmation_sent`,
`_law_charge_claim`) that would suppress a genuine confirmation email or charge
on the far side. So are `law_events_test_mode`, the Stripe `tax_rate_id` and
`rendering_template_id` (account-specific), an email's RECIPIENTS, and every
one-shot version latch. `ContentTransferTest` pins the bundle's top-level keys,
so widening that list is a decision somebody has to make on purpose — and the
`emails` key is what that gate looks like when it fires: the exclusion was
reversed the same day, on purpose, with the test updated to match.

**Email wording (`emails`, added 15 September 2026).** The registry in
`notifications.php` is code and travels with a deploy; what does not is the
polishing somebody did on the Emails screen, which lives in the
`law_events_email_overrides` option. `law_content_transfer_emails()` exports one
row per overridden slug — `{slug, name, subject, body, active}` — and only for
slugs this site's registry still defines, so a retired email cannot be
resurrected (`law_setup_retire_booking_received_emails()` is the precedent). 78
emails ship in the registry and only the customised ones travel, so a deploy's
own wording changes never look like an incoming edit.

**An email's `to` is never exported**, even though the Emails screen can edit it
on the four registry entries whose recipients are a typed address list rather
than an audience key. Those addresses are a routing decision belonging to the
environment, and the failure modes are not symmetrical: dropping them costs
somebody re-typing four addresses, while carrying them can point a production
notification at a staging test mailbox silently, and nobody finds out until the
email that mattered went to the wrong place. The importer preserves whatever
`to` the far site already had.

`law_content_transfer_run_email()` is the one importer that is not a caller of
an existing saver, because the Emails screen writes the option inline rather
than through one; it mirrors `law_events_emails_handle_post()`'s three keys and
sanitisers instead of inventing a second shape. It diffs against the EFFECTIVE
email (`law_events_email()`, registry plus any stored override) so "No change"
means "this site already sends these words", however they got there. The body
gets `law_content_transfer_body_change()` rather than the shared diff, which
truncates at 80 characters: two rewrites of one email routinely share their
first 80 characters, so the generic `old → new` would print the same string
twice on the one field the operator most needs to be sure about. It names the
length change and quotes the first line that differs.

**Emails are applied last in `law_content_transfer_run()`**, after the
receptions, flagship and discounts. Every one of those can fire an email, and
writing the wording first would mean a run sent its own notifications in words
the operator had not yet seen applied.

**Ordering against the migration matters more.** Migration step 9
(`notifications`) writes this same option, from form 2 (Event > submit an
event)'s Gravity Forms notifications. So on a production → staging pull the
content-transfer import must run AFTER the migration, or step 9 overwrites the
wording it just restored. Measured on 15 September 2026 against the local
site's 15 real overrides: export, wipe the option, write two legacy values as
step 9 would, run `?setup-account-pages`, then import — 15 of 15 restored with
no mismatch, the legacy values overwritten, and no recipient list carried.

#### The 16 September 2026 widening: the whole programme travels (format 3)

Denis reversed the external-events exclusion and widened the bundle a long way
past it, because the client had gone on editing the programme itself on staging:
venues, descriptions, speakers, running orders, the new **Override booking
availability** switch, and a set of external listings created there from
scratch. None of that had a route to production. The brief was "make sure we
migrate all possible data about events", so the new `events` key carries **every
`law_event` that is not a reception and not the flagship** — those two already
have keys of their own, and savers that do things a generic event row knows
nothing about.

**The import is an overlay, never a mirror** (Denis, 16 September 2026): "It
needs to override existing events data after the general migration process, but
don't touch any new events that are added and wasn't existing on the staging."
So it runs **after** the Gravity Forms migration, overwrites the events the
bundle names, creates the ones this site has never seen, and leaves everything
else exactly where it was. Nothing is ever deleted, and an event that exists here
but not on the source is not touched. There is deliberately no
delete-what-is-missing mode.

**Events are keyed by Gravity Forms entry ID first, slug second**
(`law_content_transfer_find_event()`). Both environments build their programme by
migrating the same Gravity Forms entries, so entry 190 on form 2 (Event > submit
an event) is the same event on both whatever either site did to the title
afterwards; the slug is the fallback for an event created in the module since,
which has no entry behind it. `ContentTransferTest` exempts `gf_entry_id` from
the no-local-IDs rule for exactly that reason, and names the exemption rather
than widening the pattern.

**A hosted event's workflow status does not travel, and that is the exclusion
worth reading twice.** Production's status comes from the migration, which reads
field 95 (Event status) on form 2 (Event > submit an event) — the live workflow
record. And an approval is an act, not a value:
`law_event_workflow_side_effects()` snapshots the fee, creates the co-owner
accounts, raises the Stripe invoice and emails the host. Writing `law-approved`
onto a post would produce an approved event with no invoice and no host email;
calling the real transition from an import could email dozens of hosts and raise
dozens of live invoices from one button press. So the bundle **carries** the
status, `law_content_transfer_event_status_notes()` **reports** the
disagreement in the preview so a committee member can go and approve it
properly, and the import **writes** it only where there is no workflow behind
it: an external event's `publish` / `law-draft` tick (which means "on the
programme" and "not yet", exactly as a reception's does, and goes through the
same `law_event_managed_saving` exemption), and a hosted event being **created**,
where the status belongs to a new post and no transition has been skipped
because none has happened anywhere. A created event that lands Approved or
Confirmed says so in the row, along with the fact that no invoice was raised.

**Descriptive data only** (Denis, 17 September 2026), and this narrowed the
scope after the first cut shipped. His question was the right one: by the time a
bundle is imported, production's events have been approved, invoiced, paid and
confirmed for real, while on staging the same events are test data. So the rule
is that what an event **is** travels, and what it has **been through** stays on
the site it happened on.

**What an event row carries** is an allow list
(`law_content_transfer_event_meta_keys()`), not "every key in the schema", so a
new key has to be added on purpose and gets read against that rule when it is.
In: when and where (`_law_start`, `_law_end`, `_law_slot_label`,
`_law_preferred_slots`, `_law_venue`, `_law_venue_needed`,
`_law_venue_capacity`, `_law_tickets_available`), who is putting it on
(`_law_host_organisations`, `_law_contacts`, `_law_co_owner_rows`), the
committee's operational switches (`_law_booking_override`, `_law_is_external`,
`_law_external_url`, `_law_session_agenda`, `_law_registration_state`) and the
sector "please specify" inputs. Out, each for its own reason:

- `_law_stripe_*` and `_law_payment_status` — objects in whichever Stripe
  account the source site points at. Importing "paid" onto production would mark
  an unpaid invoice settled, which is the one mistake here nobody would spot
  until a reconciliation.
- `_law_fee_pence` and `_law_vat`, the snapshot `law_event_snapshot_fee()` froze
  at approval and the invoice was raised from — and, since 17 September 2026, the
  **inputs** to it as well: `_law_fee_tier`, `_law_fee_override` and
  `_law_fee_override_amount`. Those three did travel at first, on the argument
  that they are the committee's decision about the event rather than a payment
  record. That is true and beside the point: nothing recalculates the snapshot
  after approval, so importing a different tier onto a **paid** event moves the
  admin Fee column and the exports while the invoice, the `{fee}` emails and the
  reconciliation all keep the old figure. A number that disagrees with the money
  is worse than a number that is merely out of date.
- `_law_invoice_name`, `_law_invoice_email`, `_law_invoice_address`,
  `_law_country_iso`, `_law_vat_number` — who was billed. On an event whose
  invoice has been raised and paid these are the record of that transaction, not
  an editable detail, and a staging test address must never overwrite one.
- `_law_approved_at`, `_law_rejection_reason`, `_law_cancellation_reason`,
  `_law_terms_consent` — the record of decisions that happened somewhere, which
  belongs to the site where they happened. `law_event_has_been_approved()` still
  falls back to `_law_approved_at`, so importing one would also tell this site
  that an approval it never made had happened.
- `_law_tickets_sold` and the two capacity-warning latches — a recount and two
  one-shot flags about the far site's own bookings.
- `_law_co_owner_ids` — user IDs minted on the far site at approval. The **rows**
  travel; the IDs are production's own.
- `_law_reference` and `_law_gf_entry_id` — identity. Both sites derive them from
  the same entry, so they already agree, and rewriting the entry ID from the file
  would let a bad bundle re-point an event at a different record.
- `_law_assignee` and `_law_organisation_ids` — IDs of things on the other site.
  They travel beside the meta instead, as an **email address** and as
  **organisation slugs**.

**People travel as email addresses, and an import never creates an account**
(Denis, 16 September 2026). The owner and the assignee are resolved with
`get_user_by( 'email' )`; an address with no account here is reported in the row
and the event keeps whoever it had, or falls back to the administrator running
the import on a create. Creating logins for people who have never heard of the
new site is a decision for a human.

**A hosted event has no single saver to call**, so this is the one place the
"caller, never a second write path" rule bends — and only by one layer.
`law_events_form_save()` is the HOST form's saver, complete with locked-field
rules that depend on who is posting, so `law_content_transfer_write_event()`
writes through the layer below it instead: `law_event_update_meta()` for every
key (the one sanitiser the admin screens, the front-end forms and the migrator
all share), plus `law_events_set_terms_by_name()`,
`law_flagship_resolve_speaker_rows()` and `law_flagship_save_sessions()` — the
same shared repeater savers the host form and the external-events screen call.
No sanitiser is reimplemented. The two `law_flagship_*` names are historical:
both functions take an `$event_id` and have always been generic.

**Both repeaters honour a sentinel.** Our exporter always writes `speakers` and
`sessions`, even when empty, so an agenda emptied on staging really is cleared
here; a bundle that is *silent* about either (hand-edited, or older than the key)
leaves it alone. Same distinction a truncated POST needs. The agenda is
**replaced** rather than edited in place, because every incoming row carries
`id => 0` — a session ID from the other site means nothing here — so
`law_flagship_save_sessions()` deletes what the posted rows do not claim.

**Three things a key-by-key audit of a real 105-event programme turned up**, each
of which an import would otherwise have got quietly wrong.

- **Co-owner account links.** Every other path that writes `_law_co_owner_rows`
  reconciles them straight afterwards through
  `law_event_ensure_co_owner_users()` — the approve transition, the host and
  committee forms, the wp-admin screen — because the module keeps the rows, the
  `_law_co_owner_ids` array and one flat `_law_co_owner` row per ID in step. An
  import cannot call that one: it **creates** an account for a row that has none.
  So `law_content_transfer_link_co_owners()` links the addresses that already
  have an account here, through the same `law_event_set_co_owner_ids()` write
  path, and reports the rest. Without it a co-owner added on staging would arrive
  in the rows, appear on the event, and be unable to open it.
- **A recreated session's Gravity Forms entry ID.** `law_migration_run_sessions()`
  dedupes with a meta query on `_law_gf_entry_id`, and an import **replaces** the
  agenda. Lose the key and re-running the sessions migration step after an import
  duplicates every agenda it carried. `law_content_transfer_restamp_sessions()`
  puts it back, pairing by position because that is the order
  `law_flagship_save_sessions()` returns — and refusing to pair at all, with a
  note, if any row failed to save, since a mis-paired entry ID would be worse
  than none.
- **A created event's date.** `post_date_gmt` is what `speakers.php` orders
  "first appearance" on, which is what picks the photo and organisation a
  speaker's archive card and profile show. An event that exists on both sites
  already agrees, because both took the date from the same entry; one created in
  the module on staging would otherwise land here dated today and quietly outrank
  an older record.

**What the same audit confirmed is complete.** All three registered taxonomies
travel. `post_excerpt`, `post_password`, `menu_order`, `post_parent` and a
featured image are unused on every event on the site. The only per-event user
meta is the thread "read at" marker (`unread.php`), a per-viewer convenience. No
option and no custom table holds event data — the one custom table is the
migration log. The only event data deliberately left behind is **comments**:
1,923 activity-log entries and 65 host/committee thread messages on the local
copy. Production builds both from its own migration run (`law_migration_run_comments()`
dedupes on the form 11 entry ID, `law_migration_run_history()` latches on
`_law_history_migrated`), and a module-era comment written on staging has no
stable cross-site key to dedupe on — so carrying them would duplicate or assert
activity that never happened here. Each applied event does get one log line of
its own naming the source site and the fields that moved.

**Two things a security review stopped from shipping**, both worth reading as
patterns rather than as bugs.

- **A two-run status-guard bypass, through the classification flag.** The status
  exemption was computed from the bundle's own `external` field. The guard in
  `workflow.php` correctly checks the STORED `_law_is_external` through
  `law_event_is_managed_by_law()`, so a bundle claiming `external` against a
  hosted event had its status write refused — and then `_law_is_external` was
  written anyway by the ordinary meta loop, so the **next** run found a
  genuinely external event and the write went through. A submission nobody
  approved would have landed on the public programme. Closed twice over, either
  half sufficient: the exemption is now judged on the event's stored
  classification read **before** any of this run's writes, and an existing
  event's `_law_is_external` is never written at all. Reclassifying an event is a
  committee act with its own log line (`law_event_log_flag_change()`), so it
  belongs on the dashboard beside the status, for exactly the same reason — the
  bundle carries it, the preview reports the disagreement, the import does not
  act on it. A CREATE may use it, because a new post has no workflow to bypass.
  The general lesson: **when an uploaded value decides whether a guard applies,
  read the guard's own source of truth, not the upload's copy of it** — and
  check whether the same upload can write that source of truth on an earlier
  pass.
- **Ownership could be reassigned.** `post_author` was set on every run, so a
  bundle naming somebody else's address handed them the event —
  `law_user_can_manage_event()` treats the author as a full manager, and the
  same run writes the invoicing contact, address and VAT number the new owner
  could then read. Ownership is now set on a CREATE only; a difference on an
  existing event is reported, like the status. **Places available are the
  exception**, and Denis said so directly: I had made the number conditional on
  nobody having booked, on the reasoning that capacity is the booking and
  waitlist limit rather than a description, and he overrode it the same day.
  They travel unconditionally. What that makes important is the consequence
  rather than the number, so the import calls `law_event_tickets_changed()`
  after every other field — the module's own "the places moved" path, the one
  the committee panel and both form savers call — which writes the log line and,
  on a raise, runs the waitlist. An event whose places double therefore seats the
  people queuing for them, exactly as a committee member typing the number would,
  instead of quietly holding a queue the module promises to clear. The preview
  names it, because seating somebody emails them and an import is the one place
  an operator might not expect an outward-facing effect; it also names a drop that
  puts an event below the bookings already on it. **Co-owner links follow the same
  rule**, after an argument the review won: a co-owner has exactly the author's
  rights (`law_user_can_manage_event()`), so linking somebody because a bundle
  named their address is the same unconsented grant. The defence that persuaded
  me otherwise — "approving the event here would link them anyway" — is wrong
  precisely where it matters: `law_event_ensure_co_owner_users()` runs on the
  approve transition and on a save of an already-approved event, so on the LIVE
  programme, which is every event the client is actually editing, the import
  would have been the only grant there ever was. The rows still travel; the
  access does not, and the preview says so. The route back is a committee member
  opening the event and saving it, which grants access properly, creates the
  missing accounts and emails them.

Also from that review, and deliberately **not** changed here because both
pre-date this widening: the extracted-images working directory is protected by
`.htaccess` plus `index.php` without the runtime self-test the database-snapshot
gate has (weaker on an nginx host, for ~24 hours, for speaker photographs); and
`law_speaker_upsert()`'s email-then-name backfill onto a shared speaker profile
is a module-wide pattern that this file now reaches from an uploaded file rather
than only from a signed-in host's form.

Two caps join the byte and image ones, for the reason those exist: a run is
synchronous inside one `admin-post.php` request, unlike the module's own batched
migration runner. `LAW_CONTENT_TRANSFER_MAX_ROWS` (2,000) bounds each top-level
list, because an event row costs a lookup, a thirty-key snapshot, several writes
and a log entry. `LAW_CONTENT_TRANSFER_MAX_NESTED_ROWS` (20,000) bounds the
sessions and speaker appearances across the whole file, because the first cap
alone does not: 500 events, comfortably inside it, each carrying tens of
thousands of minimal session rows is cheap in JSON bytes and still an enormous
run. Neither is filterable, matching `MAX_IMAGES` and `MAX_BYTES`; only
`MAX_UNZIPPED` is, and that is for test fixtures rather than a precedent. LAW's
whole programme is 105 events with 142 speaker appearances and 31 sessions
between them.

**The preview compares like with like.** Both sides of an event diff go through
the same schema sanitiser, because several keys have a non-empty default:
`_law_booking_override` becomes `auto`, an int becomes `0`, a float becomes
`0.00`. Comparing a raw stored `''` against a sanitised incoming `auto` would
report "Override booking availability: not set → auto" on every event nobody had
touched — on a hundred-row programme, enough noise to stop an operator reading
the preview at all. Measured on the local site (105 events): 79 No change, 26
Update, and all 26 are the description alone. The description gets
`law_content_transfer_body_change()` rather than the generic `old → new`, for the
same reason the email bodies do — two versions of one paragraph share their first
80 characters, so the generic form prints the same truncation twice. Its
character count is also what makes the common case legible: 23 of the 99 legacy
form 2 descriptions carry a stray `class` that `law_rich_text_sanitize()` drops,
so importing one **cleans** it. That is a real write, it is what every other
write path for that field does, and the preview reports it as `1,682 characters
→ 1,377` rather than pretending nothing happened.

Every applied event also gets a line in its **own** activity log naming the
source site and the fields that moved, so a committee member reading one event's
history does not have to know a migration screen exists.

**Three format rules, all of them consequences of the one fact that makes a
cross-site move hard: post IDs do not survive it.**

- **Receptions and discount scope are keyed by SLUG.** The slug is what
  `law_event_ensure_managed_post()` provisions by, so it means the same thing on
  both sites. A discount scoped to an event that is not one of the exported
  receptions is dropped from its scope and the run says so. The importer creates
  a missing reception through `law_event_ensure_managed_post()` rather than
  letting `law_reception_save()` create it, because that saver derives the slug
  from the TITLE — and the slug is the key the whole format turns on.
- **Speakers are keyed by IDENTITY.** Each row carries the first name, last
  name, email and website inline, is marked `is_new`, and is handed to
  `law_flagship_resolve_speaker_rows()` → `law_speaker_upsert()`, which already
  dedupes by email first and normalised name second. So there is **no speaker
  matching logic in this file at all**, and the existing split holds: the shared
  profile is gap-filled, never overwritten, while the per-appearance role,
  organisation, job title, biography and photo are written onto the event row,
  which is where they belong.
- **Derived values are never exported.** `_law_start`, `_law_end` and the
  event-level `_law_speakers` union are recomputed by `law_flagship_recompute()`
  from the sessions; exporting them would only give the importer a chance to
  write something stale. Same for `_law_tickets_sold`, the capacity-warning
  latches, `_law_reference`, `_law_assignee` and `_law_co_owner_ids`.

**The import is a CALLER, never a second write path.** Every record goes
through `law_reception_save()`, `law_flagship_save()` or `law_discount_save()`,
which is what keeps the validation, the `law_event_managed_saving` status-guard
exemption, the recompute and the per-event activity log identical to a
committee member typing the same values in by hand. Nothing in this file writes
a meta key directly.

**Two conversions that are easy to get backwards**, both because the savers take
what was TYPED rather than what is stored. Prices live in pence and are edited
in pounds, so the export divides by 100 and the import lets
`law_events_pounds_to_pence()` multiply back. And a discount's `value` is an int
from `law_discount_data()` — a percentage for `percent`, **pence** for `fixed` —
while `law_discount_save()` wants pounds for a fixed one. `_law_discount_used`
is never exported: a code spent on staging must not arrive on production
already spent.

An unwritten flagship price travels as `''`, not as the default.
`law_flagship_price_pounds_field()` substitutes `LAW_FLAGSHIP_PRICE_DEFAULT`
when the key has never been written, which is right for a form field and wrong
for an export: it would turn "not set yet" into "set to £550" and stamp that on
the far site.

**Dry run, then apply.** The preview parses the upload, stashes it in a one-hour
per-user transient (`law_ct_bundle_<uid>`, the mechanism the speakers and
flagship dashboards already use for a refused save) and renders a table of
Create / Update / No change / Skipped / Failed with the changed fields as
`old → new`. The Apply button reads the transient, so the file is not uploaded
twice. There is deliberately **no second code path for the preview**: it takes
the same route and diffs the stored record against what the bundle *would* make
of it, where the apply diffs it against what the saver *did* make of it, using
the same `law_reception_snapshot()` and `law_flagship_snapshot()` the activity
log already compares. A preview can therefore not promise something the apply
does not do. The one place it has to be careful is a discount scoped to a
reception the same run will create: there is no ID to compare yet, so that
field is left OUT of the preview's diff and reported in words instead, rather
than reported wrongly.

**Images travel INSIDE the file** (format version 2, Denis, 15 September 2026).
The bundle is a zip: `bundle.json` plus an `images/` folder.

Version 1, shipped earlier the same day, was plain JSON whose images the far
site fetched from the source site's URLs at import. Denis reversed that within
hours, and the reason is worth keeping because the original reasoning was sound
and only one fact changed: **LAW staging sits behind HTTP basic auth.** Every
fetch returned 401, and because a failed fetch is deliberately warn-and-continue,
the import would have reported success while leaving every `photo_id` at 0 — the
worst shape a failure can take. The general rule that came out of it: a
cross-environment bundle has to be self-contained, because no environment here
can be assumed reachable from another, or from itself.

- **The URL still travels, and is still the identity.** `url` is what
  `_law_transfer_source_url` records, so a re-import reuses the attachment
  rather than filling the media library with copies; `archive` is only a path
  within the zip. `law_content_transfer_attachment()` sets `archive` from the
  attachment ID (`images/<id>-<name>`), which also lets the exporter find the
  file again without the bundle ever carrying a server path —
  `law_content_transfer_archive_source()` derives it back. No absolute path
  enters the file even to be stripped out again.
- **A zip brings two attack classes JSON did not**, and both are handled before
  anything is written. Entry names are attacker-chosen, so nothing calls
  `ZipArchive::extractTo()` on the whole archive; `law_content_transfer_safe_entry()`
  is an allowlist (exactly `images/<basename>`, and `.` / `..` named explicitly
  because `basename('..')` is `'..'` and would otherwise pass a self-comparison).
  And compression ratio is attacker-chosen, so the declared uncompressed total is
  read from the archive's own directory and refused against
  `law_content_transfer_max_unzipped()` before a byte lands.
- **The bytes are still what decide.** `wp_check_filetype_and_ext()` over the
  file is unchanged and now lives in `law_content_transfer_install()`, which both
  routes share precisely so they cannot drift apart.
- **The version 1 route is kept**, for bundles written before the archive and for
  an image whose file had gone from the source site's disk at export. An image
  with no `archive` key falls back to `download_url()` with the same
  `site.uploads_baseurl` prefix check and `wp_safe_remote_get()` gates as before.
- **The identity check and the fetch check are now different checks**, and this
  is the subtle part. `law_content_transfer_media_base()` runs
  `wp_http_validate_url()`, which resolves the host and refuses private and
  loopback addresses — right before a request, and fatal if it also gated
  identity, because a source site on a private network is exactly the case the
  archive exists to serve. So `law_content_transfer_identity_base()` does the
  syntactic half only. What it still enforces is the part carrying the security
  weight: every image in one bundle shares one declared prefix, so a bundle
  cannot claim an attachment imported from somewhere else.
- **Extracted images live as long as the decision they belong to.** The preview
  unpacks into a per-user directory under `wp-content/uploads/law-migration/`
  (the directory the snapshot step already creates and protects) and puts the
  path in the existing transient; the apply reads from there and
  `law_content_transfer_clear_workdir()` removes it afterwards, as does an
  expired preview and any directory older than a day.
- A failure is still never fatal — a missing headshot is not a reason to abandon
  an agenda import — so it warns and carries on, and a banner that could not be
  read leaves the one already on the site alone rather than blanking it.

**Round-trip fidelity, measured 15 September 2026.** Exporting the local site,
importing it back over itself and exporting again produces a byte-identical
bundle. Two storage-level normalisations happen on the first apply and never
again (`_law_tickets_available` and `_law_flagship_included` go from absent to
`0`), which every reader treats identically — `bookings.php` documents 0 and
unset as the same "no capacity limit". One thing genuinely churns: the flagship's
sessions are deleted and recreated with new post IDs on every import, because
`law_flagship_save_sessions()` matches on a posted session ID and the bundle
carries none by design. Nothing references a session by ID, so the effect is
confined to the post IDs themselves, but a same-site re-import is not idempotent
in that one respect.

Everything is logged through the existing `law_migration_log()` under the step
`content_transfer`, so the transfer appears in the screen's log tail and in the
existing "Download CSV report" with no new plumbing.

The panel says on screen what it does not do, because whoever runs it will not
have read this: no bookings or payments, the Stripe tax rate and rendering
template set by hand, the email overrides checked separately, and production
still needing `?setup-account-pages`, the `law_events_source` flip and a real
system cron on `wp-cron.php`.

- Tests: `tests/ContentTransferTest.php` (80: 22 cover the archive, 8 the email
  wording, 28 the events key added on 16 September 2026, 6 of those the security
  review's findings and 5 the descriptive-only narrowing of 17 September). It is the first test class to
  reach `law_migration_log()`, whose table is created with DDL — and DDL
  implicitly commits in MariaDB, which would end the transaction
  `LAW_Test_Case` rolls each test back with. So it installs the table once in
  `setUpBeforeClass()`, outside any test's transaction, rather than paying the
  delete-based teardown. Its fixtures also use generated slugs: the local
  database holds the three real receptions, and a fixture called
  `opening-drinks` would silently test against the site's own record. The
  archive tests add a third fixture rule: they redirect the whole uploads layer
  to a scratch directory through the `upload_dir` filter, which both lets the
  runner write (the site's own uploads belong to the web server) and keeps test
  files out of the client's media library and out of `law-migration/`. The
  round-trip test blocks `pre_http_request` outright, because "the photograph
  arrives with no network at all" is the actual requirement and a test that
  could quietly be passing via a fetch would not prove it.

- The panel's own copy now says images travel inside the archive, and the file
  input accepts `.zip` and `.json` both. Since 16 September 2026 it also says
  what the events half does and does not do: run it after the migration, it
  updates and creates but never deletes, a hosted event's status is not imported,
  the host fee snapshot does not travel, and no user account is ever created.

- Two top-level gates fired on purpose when the events key went in, which is what
  they are for. `test_bookings_are_never_part_of_a_bundle` pins the bundle's
  top-level keys AND refuses any key containing "booking", "stripe" or "payment"
  anywhere in the structure; `_law_booking_override` is now a **named** exception
  beside the pattern rather than a loosened pattern, because it is an event-level
  committee decision with no Stripe object and no booking behind it. And
  `test_the_bundle_carries_no_local_ids` exempts `gf_entry_id` alongside
  `entry_id`, for the reason the keying rule gives: a Gravity Forms entry ID is
  not a local ID.

---

## 3. Shared front-end files (`functions/`)

These predate the rebuild and now branch on `law_events_source()`.

- **`calendar.php`** (~1525 lines): the programme calendar. In `'cpt'` mode its
  data functions read `law_events_cpt_mapped_events()` /
  `law_calendar_event_by_id()` (which hydrates the single view) instead of GFAPI
  entries; the presentation helpers (day tabs, status badge, card classes,
  maps embed, SEO titles) are unchanged. `templates/calendar.php` (public) and
  `templates/calendar-committee.php` both `require parts/calendar-body.php`.
  **Flagship additions (9 September 2026):** `law_calendar_flagship_event()`
  (the hydrated flagship for the programme, memoised per calendar context;
  resolved through `law_calendar_event_by_id()`, which applies the public
  status filter anywhere but the committee calendar, so a draft flagship comes
  back null publicly and renders with its badge for the committee — there is
  deliberately no explicit `publish` test), `law_calendar_day_is_empty()` (ONE
  rule with two consumers: `parts/calendar-events.php` skips a day section and
  `parts/calendar-daynav.php` greys out its tab, and "empty" now means more
  than "no cards") and a `continue` in `law_calendar_events()` for
  `is_flagship`, so the flagship can never appear as an ordinary card, in a
  slot bar or in the unscheduled bucket. The block is pinned to its own day,
  above that day's slot bars. **Since 16 September 2026 it answers the filters**
  (see the change-history entry): `law_calendar_visible_flagship_event()` is the
  resolver the programme renders from, and it returns the conference only while
  it passes `law_calendar_event_matches_filters()`. Between 11 and 16 September
  it was pinned whatever the filters said, on the reasoning that a delegate
  searching for something else should still see the main event of the week;
  Denis reversed that, because an event nobody can filter away is an event
  nobody can get out of the way. If its date falls
  outside the configured programme week the block renders above the days rather
  than vanishing, so a mis-set week is visible instead of silently costing the
  site its main event. `law_calendar_events()`, `law_calendar_filters()`,
  `law_calendar_event_by_id()`, `law_calendar_flagship_event()` and
  `law_calendar_visible_flagship_event()` each take a
  `$reset` parameter, and `law_calendar_reset_caches()` flips them together:
  production renders one template per request, but the tests create events
  mid-request.
  **The day-tabs layout (11 September 2026).** The programme shows **one day
  at a time**. The old page stacked every card under navy day bars and orange
  slot bars: with 52 events it ran to 10,650px, did not read as a timeline,
  and buried the flagship block mid-scroll (Denis). Two layouts were built
  behind `?variant=1|2` for comparison (day tabs; a week-long timeline with
  sticky date markers), Denis chose the tabs, and they became the default;
  the timeline was deleted. What the layout is, and where it lives:
  - `parts/calendar-daynav.php` (new): the five day links as a **sticky tab
    bar** with a count per day ("32 events", via
    `law_calendar_day_count_text()`; the flagship's day also carries a
    "Flagship" pill) rendered by
    `parts/calendar-body.php` *between* the filters and the results as a
    direct child of `.law-cal`, not inside `.law-cal-controls`: a sticky
    element only sticks within its parent's box, so the bar has to share a
    parent with the results it scrolls over. `parts/calendar-filters.php` is
    now the filter bar alone.
  - `assets/js/calendar-tabs.js` (new): turns the links into a WAI-ARIA
    tablist (automatic activation, Left/Right/Home/End, roving tabindex) and
    shows one `.law-cal-day-section` at a time; the active day is the URL
    hash (`#day-YYYY-MM-DD`, the form the links always had, so deep links
    still land), defaulting to today during the week, else the first day with
    something on. After `law:partial-rendered` it re-enhances the new panels,
    refreshes the counts from each section's `data-count` and keeps the same
    day unless it emptied. Announcements go through the `#law-cal-status`
    element `calendar-body.php` renders, not an `aria-live` region around the
    results, which would read a whole day aloud on every switch. On a phone
    the row scrolls sideways (thin scrollbar) and the script brings the active
    day into view with `scrollBy` on the nav. **Header offset:** nothing here
    computes it — a scroll to the top of the panel goes through
    `scrollIntoView`, so `html`'s `scroll-padding-top` (app.css, measured by
    app.js) handles the header and `.law-cal-events`' `scroll-margin-top`
    the bar; adding the header again lands a header's height too low, which
    is exactly the bug the first cut had. `calendar-filters.js` looks the day
    links up document-wide now and its own scroll-to-day click handler stands
    down when the nav carries `data-law-daynav`, which is how the old layout
    below keeps scrolling. Without JavaScript every day renders stacked.
  - **The flagship counts as one of its day's events** (Denis, 15 September
    2026). It was excluded at first, on the reasoning that the count is of
    cards and the block is not a card, so a Wednesday holding the conference
    and one reception read "1 event" while two things were plainly on it.
    `law_calendar_events()` still excludes the flagship — that exclusion is
    what keeps it out of the card list, the slot bars and the day grouping —
    so the 1 is added in the two places that produce a count:
    `parts/calendar-daynav.php` when it builds its own counts, and each day
    section's `data-count` in `parts/calendar-events.php`, which
    `calendar-tabs.js` re-reads after a filter fetch. Both must agree or the
    tab label would change the moment a filter was touched. The dashboard's
    timeline passes its own counts and needs no such addition: its
    `law_slotchart_items()` asks for the flagship explicitly. The blank
    count that `law_calendar_day_count_text()` returns for a flagship day is
    therefore unreachable from the programme now, and is kept only for a
    caller that supplies a 0 of its own. Since 16 September 2026 the 1 rides on
    the **visible** flagship, so a conference the filters have excluded stops
    being counted at the same moment it stops being drawn, and its tab falls
    through to "No events" rather than to that blank.
  - **The keyword is marked in the results** (16 September 2026).
    `law_calendar_highlight( $text, $keyword )` returns escaped HTML with only
    its `<mark class="law-hit">` tags raw, and is applied to the card's title,
    venue and host, and to the flagship block's and strip's title and venue.
    Read it beside `law_calendar_event_matches_filters()`, never apart from it:
    the matcher normalises through `law_calendar_normalise_choice()`, which
    decodes entities and collapses whitespace, and both change the string's
    length, so the highlighter has to apply the same two transforms to the text
    it prints before it can locate anything. `parts/calendar-events.php` is the
    only caller that supplies the keyword, as an explicit `highlight` arg;
    `parts/loop/event.php` is shared with three other surfaces and must never
    read `law_kw` itself. The `mark.law-hit` rule lives in `app.css` and is
    shared with the speakers archive. See the change-history entry for the
    offset trap, the accepted limitation and the contrast reasoning.
    The committee's dashboard calls the same helper (16 September 2026) and
    the rule is the same everywhere: **the whole phrase, case-insensitively**.
    A word-by-word variant was built for that surface and reversed the same
    day; see the change-history entry.
  - **`law_calendar_search_snippet( $description, $keyword, $chars = 170 )`**
    (16 September 2026) is the other half of the same fix: both keyword boxes
    search the description and neither surface printed a word of it, so a hit
    that lived only in the body text explained nothing. It returns a marked
    keyword-in-context extract, or **`''` when the keyword is not in the
    description** — the caller prints the line only on a non-empty return, so a
    card matched on its title alone grows nothing. ~170 characters with ~55
    before the hit, both edges pulled back to a word boundary, an ellipsis on
    each edge that is not the real start or end of the text. The description is
    flattened through `law_rich_text_plain()` first (rich text, so tags,
    shortcodes and entities have to go before characters can be counted, and
    block boundaries have to become spaces or a bulleted list runs into one
    word), and the marking is delegated to `law_calendar_highlight()`, so only
    the ellipses are added raw. The committee's table passes 260, and prints it
    on **a row of its own spanning every column** rather than inside the Event
    cell, whose width is capped at 12rem (Denis, 16 September 2026).
    `$chars` is a **floor, not a cap**: the window is
    `max( $chars, 55 + strlen( needle ) + 40 )`, because the lead-in and the
    phrase cannot be spent out of the same budget. Written as
    `max( $chars, needle + 40 )` for its first hour, it cropped a 62-character
    keyword mid-phrase in the table's then-110 window, and
    `law_calendar_highlight()` — which matches the phrase whole — found nothing
    to mark, so the snippet appeared with no highlight in it on the one surface
    whose window was tight. Pinned by a test.
  - `parts/events/flagship-strip.php` (new): one navy line above the days —
    "Flagship event · LAW Flagship Conference · Wednesday 2 December ·
    9:00am - 2:45pm · London · Event details" — linking to the flagship's
    day, so the main event of the week is in the first screenful whatever
    day is showing. A signpost, not a second copy: the block still renders
    under its day exactly once, the strip shares no class name with it
    (`FlagshipRenderTest` counts `class="law-flagship-card"`), and the script
    hides it while the flagship's own day is on screen. Stacks tag → title →
    meta → link on a phone.
  - **The card is a row now** (`parts/loop/event.php` unchanged; calendar.css
    restyled): hairline dividers instead of the 2px navy box, a 4px left edge
    on every row so a sponsored row's orange edge does not shift its title,
    venue and host on one line, smaller buttons. Both meta lines are labelled
    with the value bold — "Venue: **…**" and "Hosted by: **…**" (Denis,
    11 September 2026) — since a bare place name next to a bare organisation
    name gave no clue which was which. The label/value split is why
    `law_calendar_host_names()` exists alongside `law_calendar_hosted_by()`:
    the card needs the names without the label. The weight is 600 via
    `.law-event-card__meta strong`. The same card serves the
    speaker profile's role-grouped lists and the account pages, so they
    got the density too, by Denis's request for the speakers; only the time
    line is contextual — `.law-cal-day-section` hides it (the slot heading
    says it) and everywhere else, where `show_date` is passed, it stays as
    the row's date.
  - **`programme-old/` — the original layout, for reference only** at
    `/programme/?variant=old`: its own page template (`template_include`),
    verbatim copies of the two partials as they were, the old CSS rules
    scoped to `.law-cal--old`, its own `&law_partial=1` endpoint at priority 9,
    a hidden `variant` field the filters carry (`data-law-keep` exempts it
    from "Clear all"), and one `require_once` in `functions.php`. No tests, by
    Denis's decision. Delete the directory and that line to remove it. The
    layout research (London Tech Week, Web Summit, Sched, Fintech Week
    London; NN/g on scrolling and tabs; the ARIA tabs pattern; WCAG 2.2
    SC 2.4.11) is summarised in its README's predecessor, the plan file, and
    need not be repeated here.
- **`speakers.php`** (~532 lines): the speakers archive/profile routing and SEO.
  In `'cpt'` mode it reads the `law_speaker` posts via `source.php`; the
  `/speakers/<id>/` rewrite and single-profile rendering are shared.
- **`account-events.php`** (~370 lines): the host "My events" listing. In
  `'cpt'` mode it lists the user's owned/co-owned `law_event` posts, hosts the
  comment thread (`?law_thread=`) and the per-event attendee list
  (`?law_event_bookings=`), links to the custom edit form, shows a
  "Review queue" link to committee members and renders the save-confirmation
  notice. Since **10 September 2026 it is the HOST side only** — a person's own
  bookings live on `/account/bookings/` — and the file carries the
  `template_redirect` hook that makes the split survive contact with links
  already sent: it forwards `?law_booking=` (with any `law_notice`) to the same
  argument on the bookings page, because every confirmation email and every
  "Manage booking" button in someone's history points here, and the no-JS
  booking handlers redirect to the *referer* rather than to a URL the module
  controls. It no-ops unless `law_account_page_id( 'my_bookings' )` resolves, so
  an unprovisioned environment cannot send everyone to a 404.
  There was a **second redirect**, sending a signed-in visitor with no events to
  their bookings; it went on **14 September 2026** with the roles. While only
  hosts and sponsors could submit, this page genuinely had nothing for anybody
  else; now that anybody signed in can submit, the page always has a job and its
  empty state is the invitation to do it (a filled navy panel with a Submit
  button, `.law-account-events__empty`). A redirect would take that away from
  exactly the people it is for.
  `law_account_user_has_events( $user_id = 0, $reset = false )` lives here too:
  owns-or-co-owns, the flagship excluded, memoised per user id (the header
  renders twice a page and the hub asks a third time). It is what decides the
  "My events" item in both the header bar and the hub, replacing the old
  role test — with the roles gone, "is this person a host" can only mean "does
  this person have an event". It reads `law_events_owned_event_ids()` rather
  than `law_account_events()`, which hydrates every event and is wasted on a
  yes/no. `law_account_events()` is keyed by user id now as well, and
  `law_account_events_reset_cache()` clears both (the tests switch users
  mid-request; `law_calendar_reset_caches()` is the precedent).
  `law_account_event_actions()` also appends a **Withdraw** action on
  Draft/Proposed/Sent back events (CPT mode only): a *form-shaped* action
  (`parts/loop/event.php` renders it as a nonce'd POST to
  `law_event_handle_withdraw` with a honeypot, behind a
  `parts/layout/modal.php` confirm dialog carrying an optional
  `law_withdraw_reason`). Approved/Confirmed events have no Withdraw — the
  committee's Cancel is the route once money and slots are involved. The
  template renders one shared `law-modal-withdraw-success` dialog for the
  AJAX flow, and its notice map carries `event-withdrawn`, `withdraw-failed`,
  `event-not-editable` and `rate-limited`.
- **`auth.php`** (~500 lines): the branded `/login/` (sign-in / forgot /
  reset) flow, delegating all credential handling to core (`wp_signon` via
  `wp-login.php`, `retrieve_password()`, `check_password_reset_key()`,
  `reset_password()`). **`law_auth_default_redirect()` is where a sign-in lands
  with nothing better asked for: `/account/`, the Account hub** (it was
  `/account/events/` until 14 September 2026, which made sense while only hosts
  had anything to do). `law_auth_redirect_to()` and the `login_redirect` filter
  both call it, and they must: the filter, which sends committee members to
  `/account/dashboard/` instead, only overrides a destination that EQUALS the
  default, so writing the default in two places would silently disable the
  committee shortcut. `tests/AuthRedirectTest.php` pins both.
  Two security properties to preserve:
  `LAW_AUTH_MIN_PASSWORD_LENGTH` (10) is the **single source** for the password
  floor, applied by the reset form, the registration handler, the profile
  handler and the three templates' `minlength` attributes — the reset form used
  to accept any non-empty password, which was a way to set one the other two
  forms would have refused; and the forgot-password handler is rate limited via
  `law_events_rate_limit_ok( 'forgot', 0, 10, HOUR_IN_SECONDS )`, because for a
  logged-out visitor the nonce is effectively a shared constant and the form
  would otherwise be an unauthenticated way to bomb a known address with reset
  mail. The "if that address has an account" response stays identical either
  way, so neither guard leaks which addresses are registered.
- **`header-nav.php`**: the top bar's items. `law_account_paths()` gained a
  `flagship` key (`account/dashboard/flagship`) and `law_header_nav()` a
  committee-only **Manage flagship** item beside Manage events, Manage bookings
  and Manage speakers. The table in `tests/HeaderNavTest.php` pins the
  exact item list for every situation, so adding an item means updating that
  fixture: it is what proves an ordinary account never sees a committee link.
  On 10 September 2026 it gained a `my_bookings` key (`account/bookings`) and
  `law_header_nav()` stopped renaming one item for two audiences. **Mind the two
  keys** — `bookings` is the committee's cross-event dashboard, `my_bookings` is
  the personal page. They are one word apart and point at different pages, which
  `HeaderNavTest` asserts outright. The `my_bookings` item is CPT-gated, because
  `law_account_bookings()` returns nothing on the legacy source.
  **Rewritten on 14 September 2026** with the role retirement:
  - The order is **My profile, My bookings, My events, Submit an event, the six
    committee tools, Sign out** (Denis). Personal first and the committee tools
    after, because the account hub renders this same list as boxes and somebody
    opening their account wants their own errands before the queue they also
    happen to run. The committee items keep their order among themselves.
  - **My events is gated on ownership** (`law_account_user_has_events()`),
    committee included, not on a role. Submit an event is offered to everybody
    signed in, so it is also the way in for a first-time host with nothing yet.
  - Every item carries **`group`** (`personal` / `committee` / `signout`),
    **`icon`** (a `law_icon()` key) and **`description`**. The top bar ignores
    all three; `parts/layout/account-tiles.php` renders the hub's boxes from
    them. **The hub is built from this function's items, never from a second
    list**: one list is the point of the file, and a parallel one would drift
    exactly as the old If Menu rules did.
- **`shortcodes.php`**: `[action-message]` and `[user-content]`, the role-gated
  content wrapper page copy can be built from. Since
  10 September 2026 `law_user_content_audiences()` adds two capability-backed
  audiences beside the role names, `host` (`law_account_user_is_host_like()`,
  which since 14 September 2026 simply means "signed in") and `committee`
  (`law_user_is_committee()`), and page copy should use those. **`/account/`
  itself no longer uses any of this**: it is the Account hub, whose tiles are
  built in code, and `law_setup_account_page_content()` strips the
  `[user-content]` blocks from its body, leaving only `[action-message]`.
  A block that names a role drifts the moment a role is added, and that is not
  hypothetical: `/account/` shipped with `[user-content role="attendee"]` and
  `[user-content role="event_host"]`, so a user who registered as "LAW sponsor"
  and nothing else matched neither block and was served a heading with an empty
  body. Sponsors hold the same front-end access as hosts everywhere else
  (`law_events_user_can_submit()`, `law_account_user_is_host_like()` and the
  since-removed `law_registration_welcome_slug()` all named both roles), which
  is what made the gap easy to miss. Both helpers are called through `function_exists()`,
  because this file loads before the events module.

- **`modal.php`**: the reusable confirmation modal's asset registrar.
  `law_modal_register_assets()` registers the `law-modal` style and script
  handles on `wp_enqueue_scripts`; `law_modal_enqueue()` enqueues them and is
  safe to call repeatedly. Not events-specific: any template in the theme can
  drop a modal in.

- **`helpers.php`**: `law_asset()`, `law_hero_default_image_url()`, and since
  14 September 2026 the theme's icon set. `law_icon_paths()` is one table of SVG
  inner markup (a 24-unit box, no fill, `currentColor` stroke, round caps and
  joins, matching the card arrow in `parts/loop/event.php`) and
  `law_icon( $key, $class, $size, $stroke )` returns the complete `<svg>`,
  `aria-hidden` and `focusable="false"`, or `''` for an unknown key. The first
  eight glyphs were local to `parts/calendar-event-details.php` until the
  account hub needed icons too; they moved rather than being copied, so a glyph
  cannot end up drawn twice and differently. **Font Awesome's kit is loaded in
  `header.php` and used by nothing** — do not reach for it.

---

## 4. Templates, parts and assets

- **Root templates**: `404.php` — the shared hero banner ("Page not found")
  and a short message. Deliberately never loops the queried post: it is also
  what the pre-launch Members gate renders after `set_404()`, which leaves the
  gated event in the query (see `source.php` above).
- **Templates**: `account-hub.php` (**the Account hub at `/account/`, 14
  September 2026**: a grid of linked boxes in the manner of WooCommerce's My
  account, and where a sign-in lands. It renders
  `parts/layout/account-tiles.php` from `law_header_nav()`'s items, calls
  `nocache_headers()` because the page is per-user, and carries **its own
  `is_user_logged_in()` check** — the Members plugin's content permissions
  filter `the_content()`, which this template never calls, so the plugin's
  restriction cannot reach its markup. `[action-message]` is rendered directly,
  because a new registration lands on `/account/?action=registered` and needs
  its callout. Styles in `assets/css/account-hub.css`, enqueued by template
  name),
  `account.php` (now the **editor-content** account page only: the "Event
  submitted" confirmation at `account/events/submit/done`. It kept its name and
  its buffered-content fallback, which is one sentence pointing at the hub
  rather than the per-audience link list it used to be — the hub IS that list),
  `register.php` (custom registration), `account-profile.php`
  (profile), `account-event-form.php` (submit/edit), `account-events.php`
  (My events + thread + the per-event attendee list), `account-bookings.php`
  ("My bookings", page path `account/bookings`: a person's own bookings and the
  `?law_booking=` manage view, split off My events on 10 September 2026 — it
  calls `nocache_headers()` and keeps the `.law-cal .law-account-events`
  wrapper, because the event-card CSS is scoped under `.law-cal`),
  `account-dashboard.php` (committee review queue, the detail view, the
  `?event=<id>&law_edit=1` edit form and the `?preview-event=<id>` preview),
  `account-bookings-dashboard.php` (the committee's cross-event bookings
  table, see `bookings-dashboard.php` above),
  `account-speakers-dashboard.php` (the committee's Manage Speakers list and
  its `?law_speaker=<id>` editor, see `speakers-dashboard.php` above),
  `account-dashboard-flagship.php` (the committee's Manage flagship screen,
  page path `account/dashboard/flagship`, see `flagship-dashboard.php` above),
  `event-single.php` (single event), `flagship-event.php` (the flagship
  conference — like `event-single.php` it carries no "Template Name" and is
  swapped in by `template_include`, so it can never be picked in the editor for
  another page; it sets four caller variables and requires the same body),
  `calendar.php` / `calendar-committee.php`
  (programme), `speakers.php` (the speakers archive, page template "Speakers",
  page 658 Speakers) and `speaker.php` (a single profile — no "Template Name",
  routed in by `law_speakers_single_template()`; it shows the photo, name,
  website link and the event cards grouped under "Speaking at:" / "Hosting:" /
  "Moderating at:", with the organisation, job title and
  biography now per appearance and shown on the event pages). The committee
  calendar template enforces `law_user_is_committee()` in code, not only via
  the Members plugin. The archive's loop card is `parts/loop/speaker.php` and
  its assets are `assets/js/speaker-search.js` and `assets/css/speakers.css`,
  enqueued only on those two templates — which is why the single event view's
  speaker cards are styled in `calendar.css` with `.law-cal-*` classes.
- **Layout parts** (`parts/layout/`): `back-link.php`, `hero-title.php` (whose
  `after_title` arg replaced the old event-specific `meta` arg: it takes
  pre-escaped markup for a full-width cell below the title and is deliberately
  not passed through `wp_kses_post()`, which would strip inline `<svg>`; its
  `solid` arg adds the `hero-solid` class and drops the background image, for
  the form heroes described below) and
  `modal.php`, the reusable confirmation dialog. Pass it an id, a title, copy
  paragraphs, an optional note field and the confirm button, and it renders the
  markup `law-modal.css` and `law-modal.js` expect and enqueues both itself.
  The confirm array takes an optional `busy` label (rendered as
  `data-law-modal-busy`, the in-flight text a fetch layer swaps in), and
  `'confirm' => false` renders an informational dialog with no submit button —
  what the dashboard's script-opened success dialog uses. Two args were added
  on 21 September 2026 for the event form's failed-save dialog: `list`, a
  bulleted list under the copy whose items are plain strings or
  `array( 'text' => …, 'goto' => '<css selector>' )` (a `goto` makes the line a
  button that closes the dialog and scrolls to the control that selector
  names), and `autoopen`, which marks the dialog `data-law-modal-autoopen` so
  `law-modal.js` opens it as the page loads — for a dialog that reports what
  just happened rather than confirming what is about to.
- **Calendar parts** (`parts/`): `calendar-body.php` (the shared list/single
  view; its optional caller variables, set before the `require` —
  `$law_cal_show_status` for committee mode, `$law_cal_hero_title` for the
  list hero (every caller passes `law_calendar_hero_title()`, "LAW 2026
  Programme", the year from the Programme year setting), and, added 9 September 2026 for the committee preview,
  `$law_cal_event` for a pre-resolved, already-hydrated event that bypasses
  the public-status resolver and `$law_cal_back` for a back destination,
  applied to **both** ways back off the single event view — the chevron link
  at the top and the button at the foot of the article — so the two cannot
  drift. Both default to `law_calendar_url()` labelled **"Back to programme"**;
  the foot button read "Back to events calendar" until 11 September 2026, when
  the client asked for one term across the site (the site calls it the
  programme everywhere else — the page, the nav and the chevron link); and, added 9 September 2026 for the flagship, `$law_cal_details_rows`
  (an allow-list of details-box row keys, so the flagship shows only date, time
  and location), `$law_cal_no_booking` (no booking control and no places
  fallback) and `$law_cal_sessions_heading` (the heading over the session
  timeline, "Sessions" by default and "Agenda" on the flagship; it replaced
  `$law_cal_sessions_style` on 11 September 2026, when Denis had every event
  move to the timeline and the accordion was deleted) and
  `$law_cal_sessions_panel` (added 11 September 2026: renders that timeline
  reversed inside a filled navy panel, which only the flagship page sets, and
  since 15 September 2026 also places it at the full width of the article below
  both columns rather than inside the reading one). The venue markup lives in
  its own part, `parts/events/event-venue.php`, which now renders in the
  reference sidebar; the
  flagship's own hero image (`_law_hero_image_id`) is read here too, and an
  event without one keeps `hero-title.php`'s default photograph),
  `calendar-events.php`, `calendar-filters.php` and
  `calendar-event-details.php` — the single event view's facts box, rendered
  inside the hero below the title via `hero-title.php`'s `after_title` arg. It
  holds its own hand-drawn line-icon set (the theme has no icon library) and
  lays six facts out in a **four-column grid** at desktop, two at tablet, one on
  a phone. It exists because those facts used
  to sit as loose white text on the hero photograph, at roughly 1.7-3:1 against
  the 4.5:1 WCAG minimum for body text; a solid panel supplies its own
  background whatever is behind it, which is the only fix that does not depend
  on the image. That surface (the pale `#ececf1` ground) sits on
  `.law-event-details__grid`, not on the section, so it encloses the facts and
  nothing else; the availability panel is its own filled block below it, the
  same width. There is no divider between them, because the edge of the facts
  surface already is one. The 4px orange rule moved from the top of the facts
  list to the **top of the panel**, where it marks the seam between the two
  blocks; `.law-event-details__grid:last-child` takes it back onto the foot of
  the facts in the one case where no panel is printed at all, the legacy source
  (Denis, 11 September 2026).
  The row order is date, time, location, type, hosted by, sector (then the
  flagship's price), so the first desktop row reads what / when / where and the
  second who / which sectors. The desktop grid is **three columns for a hosted
  event and four for the flagship** (Denis, 11 September 2026), switched by
  `.law-event-details--flagship`, which `parts/calendar-event-details.php` adds
  from `law_flagship_is()` rather than from a caller arg: the two lists are
  different shapes, the flagship's four facts being one clean row of four that
  three columns would break into 3 + 1. At three columns the six hosted facts
  make two tidy rows, date / time / location then Type / Hosted by / **Sector**,
  which is a cell like any other rather than a row-spanning one (Denis, 11
  September 2026): giving it the whole row would leave a hole beside Hosted by.
  Sector is still the one fact with no ceiling on its length — as a comma-joined
  string in a part-width cell, a seven-term event wrapped to four lines,
  stretched the whole grid row and left Hosted by and Type floating in a void
  (the client raised it, 11 September 2026) — which is why it renders as
  **wrapping pills, each linking to the programme filtered by that sector**
  (`law_sector`, which the programme already filters on and whose dropdown is
  populated from the same term names, so a pill selects an option exactly). A
  A row may carry one optional key for this: `items`
  (`array( 'label', 'url' )`) renders pills instead of a string.
  **Location reads at 1rem**, not the 1.1rem every other value takes (Denis, 11
  September 2026): it is the one value with no practical ceiling on its length,
  a full street address sometimes with a note from the host appended, and at the
  larger size, underlined, it wrapped to three lines and shouted over every fact
  beside it.
  **`law_calendar_url()` now encodes its query-arg values**
  (`law_calendar_url_args()`, `functions/calendar.php`). `add_query_arg()` does
  not encode, and `esc_url()` only turns an ampersand into the entity `&#038;`,
  which a browser still sends as a plain `&`, so a sector like "Banking &
  Financial Services" arrived as two query args and the filter saw "Banking ".
  The sector pills exposed it; "Back to programme" had carried the same broken
  value for any filtered term with an ampersand in it.
- **The single event view is a reading column and a reference column** (Denis,
  15 September 2026). The `large-8` **reading** column on the left is the
  thread the reader follows: the description, then the running order under it.
  The `large-4` **reference** column on the right holds what they look up rather
  than read through (`.law-cal-detail__sidebar` > `.law-cal-detail__aside`): the
  **Speakers** list on a hosted event, and the **Venue** with its map on the
  flagship. Speakers sit beside the reader instead of pushing the running order
  down the page, which is where they went before 9 September 2026. The reading
  column falls back to `large-12` when there is nothing for the sidebar
  (`$law_cal_has_aside`) — which is every hosted event that has sessions, since
  those list their speakers on the timeline and keep the venue in the reading
  column. The back / register row stays outside both columns, under them, at the
  full width of the article.

  **The venue is the one section that reads differently on the two pages.** On
  a hosted event it sits at the foot of the reading column, below the sessions,
  where it has been since 9 September 2026: the running order is what the
  reader came for, and the address is a detail they need once. On the flagship
  it moves to the sidebar, because that page's reading column holds the
  description and nothing else (its agenda being a panel below both columns),
  so an address under it would sit in a half-empty column with the sidebar
  beside it. `$law_cal_venue_in_main` is simply `! $law_cal_sessions_panel`
  (Denis, 15 September 2026), so **one flag carries the whole flagship layout**
  — the panel, its placement below the columns and the venue beside the
  description — and no part of it can disagree with another. Both positions
  render from **one closure**, so there is one venue markup and one
  `event-venue.php` call site.

  Two details of that arrangement predate it and still hold. The event-level
  **Speakers** list renders only when the event has **no** sessions
  (`$law_cal_speakers_aside`), because a session's speakers are already listed
  under its own description on the timeline and a sidebar copy would print
  every card, and its bio dialog, twice; so an event with an agenda — the
  flagship always, an ordinary event often — gets a sidebar holding the venue
  alone. And the facts box in the hero still states the location at the top and
  links down to the section (`href="#law-cal-venue-heading"`,
  `calendar-event-details.php`) whenever the address is mappable. The
  `$law_cal_venue_last` caller variable that once let the flagship page reorder
  these sections is long gone, and there is still exactly one venue position.

  **The flagship is the one exception, and the panel flag carries it.** Its
  agenda is a filled navy box the width of the article, which has no business
  inside two thirds of a row, so `$law_cal_sessions_panel` also moves the
  timeline **out** of the reading column to the full width of the article below
  both of them (`$law_cal_sessions_full`, `parts/calendar-body.php`). That page
  therefore reads: description, with the venue in the sidebar beside it, then
  the day. Both placements call **one closure**, so the two cannot drift into
  rendering different agendas, and `EventSectionOrderTest` pins the whole
  arrangement against the source of the partial.
- **Flagship parts** (9 September 2026): `parts/events/flagship-card.php` (the
  highlighted block on the programme: the banner photograph left, and right the
  "Flagship event" label, the title, the session list with times and an "Event
  details" button; it reuses `.law-event-card__actions` and
  `.law-event-card__button` so the button cannot drift from every other card,
  and prints the committee status badge and edit link in `show_status` mode),
  `parts/events/session-timeline.php` (the agenda as a vertical timeline: an
  `<ol>`, real `<time datetime>` elements with 12-hour labels, ONE left-aligned
  rail rather than cards alternating either side of a centre line — that
  variant breaks down on a phone and costs the reader the single line their eye
  follows down a schedule — the line and markers as CSS pseudo-elements
  because they are decoration, and every session on the same marker at the same
  weight — the part used to infer a **break** from a session having neither a
  description nor a speaker and grey it out, which Denis had removed on 11
  September 2026 because a real session whose blurb was not written yet then
  read as a coffee break; and a `panel` arg, added 11 September 2026, which
  wraps the whole section in a filled brand-navy box and reverses everything
  inside it) and
  `parts/events/event-venue.php` (the address and its keyless Google map,
  extracted from `parts/calendar-body.php` when it could render in either of two
  positions; since 15 September 2026 it has one, the reference sidebar under the
  Speakers list) and `parts/events/flagship-manage.php` (the committee's editor: the
  theme's own front-end form markup for the top-level fields, and the shared
  agenda block from `flagship-form.php` below them). **Every** single event
  view renders its sessions open on this timeline, the flagship and an ordinary
  hosted event alike (Denis, 11 September 2026): the running order is what a
  reader opens a programme page for, and the accordion that used to carry it on
  ordinary events collapsed exactly that. The accordion markup, its
  `.law-cal-session*` styles and the style switch that chose between the two are
  gone; only the heading differs between the two pages. Each item reads top to
  bottom at the **full width** of the column: the title, then the description,
  then that session's speakers below it, two cards abreast from 64em and one
  per row under that (Denis, 14 September 2026). The speakers used to sit in a
  right-hand column beside the prose from 64em
  (`.law-timeline__content--split`), which narrowed both — the description ran
  to about 60% of the page and the cards to a single file down a third of it —
  and the description carried a 65ch measure besides; the split modifier, that
  measure and the one-card-per-row override are all gone. The single file
  below 64em is deliberate and overrides the two-per-row
  `.law-cal-speakers--cards` gets from 48em, because a card at half a tablet's
  width cannot fit the photo and the name side by side. Note the space above
  the cards (`margin-top`) has to be declared in that same late block, not up
  with the rest of the timeline rules: `.law-cal-speakers`'s `margin: 0`
  shorthand sits between the two at the same specificity and silently wins over
  any earlier `margin-top`, which is why two attempts at widening that gap
  changed nothing on the page. The
  **flagship alone** renders the timeline inside a filled navy panel
  (`.law-timeline-section--panel`, set from `templates/flagship-event.php` via
  `$law_cal_sessions_panel`, Denis 11 September 2026): its agenda is the
  substance of that page, so it reads as a block of the page rather than a list
  inside it. The gap above the panel is **3.5rem**, declared as
  `.law-timeline-section.law-timeline-section--panel` with both of its classes
  on purpose: `.law-cal-sessions` sits further down `calendar.css` and sets
  `margin-top: 0.5rem` at the same specificity, so at one class it won on source
  order and the gap stayed at 8px however large a number was written in the
  panel rule. (The same trap as `.law-cal-speakers`' `margin` shorthand and the
  space above a session's speaker cards — twice now, in the late half of this
  stylesheet one class is not enough.)
  Only the timeline is in the box — the flagship's description
  sits **above** it and its venue beside that description in the sidebar — and everything
  inside reverses, the heading, the times, the titles, the descriptions and the
  session's speaker cards. The CSS is colour only (one block in `calendar.css`,
  no geometry repeated, so the light and reversed layouts cannot drift): the
  orange marker and the orange rule and name on each speaker card carry over
  unchanged, the marker's white ring becomes navy because the ring is the
  surface it sits on, the connector becomes `rgba(255,255,255,.3)`, secondary
  text becomes translucent white, and every hover and focus state that resolved
  to navy on the white page resolves to white here.
- **Parts** (`parts/events/`): `speaker-card.php` (one speaker card on the
  single event view — photo or initials, the role at this event as a small
  uppercase label on its own line above the name (`.law-cal-speakers__tag`,
  first in the source and outside the profile link so the link text stays the
  name alone), then the name, which links to the speaker's profile in a **new
  tab** (`target="_blank" rel="noopener"` plus a `.show-for-sr` "(opens in a new
  tab)" hint, matching the dialog's "View speaker profile" link) so a reader
  following a speaker does not lose the event they were reading (Denis,
  15 September 2026), then "job title, organisation", a
  14-word biography excerpt (about two lines) and the "Read full bio" pair: the
  `[data-law-modal-enhanced]` button and the `[data-law-modal-fallback]`
  `<details>` holding the full text for the no-JS path, which also keeps it
  indexable now that the profile page carries no biography. Used by both the
  event's Speakers list and every session panel, so the two cannot drift) and
  `speaker-bio-modal.php` (that dialog: photo, name with the role in
  brackets inside the heading so the dialog's accessible name carries it,
  "job title, organisation" and the full biography.
  Like `booking-modal.php` it renders the shared `.law-modal` skeleton itself
  rather than going through `parts/layout/modal.php`, whose `copy` args would
  nest `<p>` inside `<p>` once the biography goes through `wpautop()` and which
  has no slot for the leading photo. The biography is the dialog's only
  scrolling region: `.law-modal__dialog`'s own `overflow-y` would carry the
  absolutely positioned close button off the top of a long bio, so the
  speaker dialog is a flex column that does not scroll and the bio carries
  `tabindex="0"` so it can be scrolled from the keyboard);
  `booking-success-modal.php` (the post-booking
  confirmation dialog, extracted from `booking-modal.php` so it can render on
  `wp_footer`), `profile-fields.php` (the shared
  registration/profile field block, with conditional "Other: please specify"
  inputs), `people-repeater.php` (the co-owner/contact repeater on the front
  end), `thread.php` (the comment thread, host and committee contexts, which
  re-checks `law_user_can_manage_event()` itself rather than trusting its
  caller), `thread-bubble.php` (one message bubble, shared by the thread loop
  and the AJAX reply response so the two markups cannot drift),
  `dashboard-list.php` (the committee event table, rendered both
  inline and as the `law_partial` AJAX response; columns Event, Host, Slot
  (the date with the time under it, via `law_events_split_slot_label()`),
  Status, Payment, Bookings, Places left, actions — the last two read
  `_law_tickets_sold` and `_law_tickets_available` off the event rather than
  counting bookings per row, so a 300-row list costs no extra queries;
  Bookings links to the per-event booking list and carries the waiting count
  underneath, and shows a dash on anything not yet Confirmed),
  the four
  **reception** parts (14 September 2026, RECEPTIONS.md):
  `reception-checkout-modal.php` (one partial, two modes — buying a place and
  joining the paid waitlist — because everything but the consent tick, the
  action and three labels is identical, and the price block is the same
  arithmetic either way. Nothing in it computes money: every figure comes from
  the shared `law_booking_quote()` on the server, the Apply button re-asks it,
  and the submit posts back the gross it was SHOWING so the server can refuse
  rather than reprice under somebody — judged against the LIST gross when the
  submission is not AJAX, because a form with no Apply button was rendered at
  the list price and comparing it against the discounted total refused every
  no-JS redemption (fixed 15 September 2026). No colleague repeater: one place per checkout,
  self only. A reception priced at 0 is FREE, not closed (Denis, 16 September
  2026): the dialog then shows no price block, no discount code field and no
  Stripe line, and its summary says "Free to attend." beside the places left —
  a £0.00 receipt and a code field on nothing were the two things that read as
  a bug. Both engine guards stopped testing `law_event_is_priced()` at the same
  time, because until then a free reception rendered a Register button whose
  submit answered "Places at this reception are not on sale". Headed
  "Register", like every other booking dialog on the site), `reception-include-modal.php` (the receptions a confirmed
  flagship place includes, rendered INSIDE the form it confirms; a reception
  already held is checked and DISABLED with a tag saying why, never hidden,
  because a list that silently omitted Monday would read as though Monday were
  not included), `receptions-list.php` and `reception-manage.php` (the
  committee's table and edit form) and `booking-payment-facts.php` (the money
  on one priced booking — price, code, invoice and PDF, "Included with your
  flagship place (Booking #N)", or the failure with the one button that fixes
  it — extracted from the flagship's own panel so the two cannot describe one
  payment differently);
  `event-form-fields.php` (the six shared submission-form fieldsets — Event
  details, Speakers, Venue, Owners & contacts, Fees, Session agenda —
  consumed by both the host form template and the committee edit view so the
  two cannot drift; the Finish fieldset stays in each consumer, being the
  part that differs; the Venue fieldset's three detail fields are unconditional
  and required since 17 September 2026, see `law_events_venue_details_visible()`)
  and `committee-event-form.php` (the committee edit
  view, see `committee.php` above).
- **Front-end assets**: `assets/js/event-form.js` (repeaters, conditional
  toggles, the WordPress-core `wp.passwordStrength` meter — score 5 = mismatch,
  the `data-law-toggle-for` show/hide used by the committee panel's
  override amount, and the **withdraw fetch layer** — the same
  intercept-modal-confirms pattern as committee-actions.js, posting the card's
  withdraw form with `law_ajax=1`, errors in the open dialog, success via the
  shared `law-modal-withdraw-success` dialog then a reload) and
  `assets/css/event-form.css` (the form, the committee dashboard and the
  thread; includes the light-section colour resets the dark-hero theme needs,
  the status-badge fix, the mobile bottom
  clearance so the submit buttons clear the fixed header, and the
  `.law-event-form--light` variant the committee edit view uses — dark text
  and bordered inputs, because the base form styles are dark-hero-first and
  would be invisible on the dashboard's white background).
  `assets/css/law-modal.css` and `assets/js/law-modal.js` are the standalone
  confirmation-modal component (with `functions/modal.php` and
  `parts/layout/modal.php`): the JS hides and disables every
  `[data-law-modal-fallback]` block, turns each `[data-law-modal-open]` button
  into an opener, reveals an opener that also carries
  `[data-law-modal-enhanced]` (a control with no job without JS, revealed only
  once its dialog has been found, so a dialog that failed to render leaves no
  dead button), enables only the `[data-law-modal-field]` in the open dialog
  and traps Tab inside it. The committee dashboard uses it for the six
  actions — Approve, Send back, Reject, Mark paid & confirm, Cancel event
  (whose close button is relabelled "Keep the event", because the default
  "Cancel" close label would read as the destructive action there) and Delete
  event — though only the ones legal for the event's current status are
  rendered (`law_event_available_ui_actions()`, plus the Cancelled/Rejected
  guard for Delete); every button stays a plain `law_action`
  submit without JavaScript. Three hooks exist for scripts: `window.lawModal`
  (`open(id)` / `close()`, the programmatic surface), `window.lawModal.redirect(
  url )`, and the `law-modal--busy` class, which marks a dialog mid-request so
  Escape and the close controls are ignored until it is removed; `closeModal`
  also clears any `.law-modal__error` a fetch layer injected. `redirect()` is
  the one every fetch layer in the theme now goes through instead of
  `location.replace()`: these handlers answer with the list the form was posted
  from, and `location.replace()` on a URL that differs from the current one only
  by its fragment is a same-document navigation — the browser scrolls to the
  anchor and never reloads, so the second waitlist move, promotion or rejection
  in a row answered successfully and then left the button stuck on its busy
  label. It forces a reload when the target is the page we are already on
  (keeping the anchor) and replaces otherwise. The API object is assigned before
  the file's "no openers on this page" early return, because a page can carry a
  fetch form with no dialog at all. Two more hooks, added 21 September 2026 for
  the event form's failed-save dialog: the first
  `.law-modal[data-law-modal-autoopen]` on the page is opened at load, and a
  click on a `[data-law-modal-goto="<css selector>"]` control closes the dialog
  (first, so the body scroll lock is off) and then centres the target's
  `.law-form-field`, focusing the control itself unless it is unfocusable —
  the rich-text description's textarea belongs to TinyMCE, so that line
  scrolls and stops there. A `goto` whose selector matches nothing on the page
  is `disabled` at load, which takes it out of the tab order and (in
  `law-modal.css`) paints it as the plain sentence it is, rather than leaving a
  line that looks clickable and goes nowhere.
  `assets/js/committee-actions.js` is that fetch layer for the dashboard's
  workflow actions: it intercepts only submits whose submitter sits inside a
  `.law-modal` (so Save changes keeps the classic POST), appends the
  submitter's `law_action` name/value to the FormData (which omits it by
  default — losing it would silently run the Save path) plus `law_ajax=1`,
  swaps the confirm label to its `data-law-modal-busy` text with all the
  dialog's buttons disabled, and on success fills and opens the
  `law-modal-success` dialog then `location.replace`s to the clean
  `?event=<id>` URL after 3 seconds. Errors (JSON or not) re-enable the dialog
  and show `.law-modal__error` in place; without JS or fetch nothing is
  intercepted and the classic redirect flow runs.
  `assets/js/calendar-filters.js` and
  `assets/css/calendar.css` drive the shared filter bar used by both the
  programme calendar and the committee dashboard. The programme's own bar is
  keyword / sector / type (`parts/calendar-filters.php`), plus organiser on the
  committee's programme view only.
- **Rich-text assets**: `assets/js/law-rich-text.js` (the TinyMCE layer — see
  `rich-text.php` above), `assets/css/rich-text.css` (the editor as a form
  field — a 1px `#c8c8d4` border round the whole container so the toolbar and
  the writing area do not bleed into the white page, thickening to the 2px
  invalid red the other controls take, plus the Foundation button rules it has
  to undo in the toolbar) and
  `assets/css/rich-text-content.css` (loaded as TinyMCE's `content_css`, so the
  inside of the editor previews the front end's typography). Enqueued by
  `law_rich_text_enqueue()`, on the front end from `submission-form.php` and in
  wp-admin from `admin/fields.php`, only on screens that render a field.
- **Flagship assets**: `assets/js/law-flagship-admin.js` (the sessions repeater
  and its "Add new speaker" rows, on `event-form.js`'s pattern; loaded by BOTH
  the wp-admin screen and the committee dashboard),
  `assets/css/flagship-dashboard.css` (the layer that makes the shared
  admin-shaped agenda fields sit inside the light front-end form, scoped to
  `.law-flagship-dashboard`) and, in `assets/css/calendar.css`, the
  `.law-flagship-card` and `.law-timeline` blocks. `law-admin.js` now exposes
  `window.lawAdminFields.initAll( root )`: the relationship and media pickers
  were bound once at DOM ready with no delegation, so a picker inside a session
  row added after load had a dead search box. Both binders are idempotent and
  skip anything inside `[data-law-row-template]`, since a clone would otherwise
  inherit the "already bound" marker and never get handlers.
- **Admin assets**: `assets/js/law-admin.js` (the relationship pickers behind
  `wp_ajax_law_events_search_posts`, the media picker, the meta-box repeaters
  and the live test-mode address check) and `assets/css/law-admin.css`.

---

## 5. The source switch and cutover

- `law_events_source` (option): `'gf'` before cutover, `'cpt'` after. Every
  shared front-end file branches on `law_events_source()`; the module's own
  handlers assume CPT.
- The migration page flip (`migration/page.php`) also (de)activates the module
  Gravity Forms via `GFFormsModel::update_form_active` — form 1 (User
  registration), form 2 (Event > submit an event), form 3 (User profile),
  form 4 (Event > host contact), form 5 (Comments), form 6 (Event > co-owner),
  form 8 (Event > speaker) and form 9 (Event > session) — leaving form 7
  (Contact) active. An inactive form is refused by the GF REST
  submissions endpoint too, closing the "old form still accepts posts" bypass.
- Rollback is flipping the option back to `'gf'`; the legacy entries and the GF
  forms are untouched by the migration (it only reads them), so the old stack
  resumes. This is why the `'gf'` branches stay until cutover is signed off.

---

## 6. Open items and product decisions (as of 8 September 2026)

The rebuild passed three adversarial-persona tests (designer, UX, security), a
full end-to-end lifecycle test and a plan-conformance audit; the confirmed
defects from those were fixed (title-wipe on edit, invisible committee status
badges, an IDOR title/status leak, the committee-dashboard navigation gap, a
mobile submit blocker, plus security hardening on the calendar capability
check, the co-owner notification, the money-path resolver fallback, the
migration DB-password handling and the rate-limit response). One further
defect was found and fixed on 7 September 2026 while building the committee
edit view: **any save of a Sent back event fired the `resubmit` transition**,
whatever button (or hand-made request) produced it, so an edit that was not
the host's "Save & resubmit" — including a committee detail edit — resubmitted
the event to the committee. `resubmit` now fires only on the host form's
explicit `law_form_action=submit`, and never from the committee edit context
(see `law_events_form_result_redirect()`).

A second review, of the forms and payment path specifically (5 September 2026),
found no injection, XSS, missing-nonce or broken-ownership-gate issues. Five
findings have been fixed since:

1. **Stripe customer overwrite via the host-supplied invoice email** — an
   email-matched customer was overwritten in full, so a host could corrupt
   another organisation's Stripe record and have our invoice sent to them. Now
   guarded by `law_stripe_customer_ownership()` (see `stripe/service.php`).
2. **No password floor on the branded reset form** — it accepted any non-empty
   password, undercutting the 10-character rule the registration and profile
   forms enforce. `LAW_AUTH_MIN_PASSWORD_LENGTH` is now the shared source.
3. **No rate limit on the forgot-password handler** — an unauthenticated
   reset-mail bomb vector. Now 10 per hour per IP.
4. **Email test mode had no expiry, no standing warning and no live-site
   guard** — leaving it on diverts real password resets. Now a 2-hour expiry
   (originally 7 days, shortened on 5 September 2026), an admin notice on every
   screen, and a production confirmation tick.
5. **`confirm` was reachable from both committee UIs** — the front-end handler
   passed `$_POST['law_action']` and the wp-admin event screen passed
   `$_POST['law_workflow_action']` straight to the workflow engine, so a
   hand-made request could publish an approved event with its invoice still
   unpaid. The root cause was the transition guard itself: a `who => 'system'`
   action was refused only for *non-committee* users, so any committee member
   satisfied it. `law_event_workflow_transition()` now refuses every `'system'`
   transition that does not come from a trusted machine source
   (`stripe_webhook`, `migration`, `system`), and both screens additionally
   whitelist their posted action against `law_event_ui_actions()`.

Three lower-severity findings from that review are **still open**, listed after
the product decisions below.

The following are open **product decisions**, not bugs, left for Denis:

1. **Co-owner consent.** Linking an *existing* account as co-owner now emails
   that account (was silent). A stronger accept-before-link flow would change
   the settled "co-owner accounts on approval" behaviour and was not applied.
2. **No welcome email to new registrants** — only the admin new-user notice
   fires today.
3. **`/programme/` is Members-gated** to admin/editor/committee, which blocks
   logged-out visitors and hosts. Deliberate pre-launch lock, or open it up?
   (Softened 6 September 2026: an event's own host/co-owners can now view
   their own listing through the gate; the programme itself stays locked.)
4. ~~**Profile `roles[]` demotion**~~ — **resolved 14 September 2026** by
   removal: the profile form has no role controls, because there are no
   self-service roles.
5. Minor polish: a map-embed fallback state, the auto sponsored highlight on
   repeat *paying* hosts, and "Fee snapshot £0.00" showing on proposed events
   before approval.
6. **`law-cancelled` is terminal** (7 September 2026): there is no un-cancel
   transition, so a mistaken cancel or withdraw can only be corrected in the
   database. Accepted for now; a committee "reinstate" action would be a 4.2
   candidate. Related accepted behaviour: a cancelled formerly-Confirmed
   event's permalink 404s (no tombstone or redirect).

Open findings from the forms/payments security review, none of them blocking:

1. **The `sponsor` fee tier is self-asserted, and now more so.** Anyone can
   choose the sponsor tier, which is £0 — so the event skips invoicing and
   auto-confirms on approval. The only control is the committee seeing the tier
   on the dashboard, where it is displayed. This got **wider on 14 September
   2026**: it used to need the `sponsor` role, which at least took a deliberate
   tick at registration; now any account can submit at all, and the sponsor tick
   that replaced the role is unchecked meta that gates nothing. The warning
   badge at approval is worth scheduling rather than noting.
2. **Partial refunds mark an event fully refunded.** The `charge.refunded`
   branch of `stripe/webhook.php` ignores `amount_refunded` versus `amount`, so
   a goodwill part-refund flips a paid event to Refunded.
3. **`invoice.paid` reconciles the amount but not the currency**, and host
   descriptions run through `the_content`, so shortcodes in them execute on the
   single-event page. (The front-end edit lock being taken and never released
   was the third item here; `edit-lock.php` fixed it on 9 September 2026.)

**Sponsor access parity (10 September 2026). SUPERSEDED on 14 September 2026**,
when the three self-service roles were retired altogether: there is no sponsor
role to have parity with, and every signed-in account holds the same front-end
access. Kept for the history, and because the two divergences it records were
database state on the legacy stack. A full sweep of every role name
and role-gated surface confirmed that the `sponsor` role had the same
front-end access as `event_host` in all of the theme's own code:
`law_events_user_can_submit()` (submission-form.php),
`law_account_user_is_host_like()` (account-bookings.php),
`law_registration_welcome_slug()` (removed 16 September 2026) and the HubSpot
tags (registration.php), and
`law_self_service_roles()` (users.php) all name both. Neither role gets any
wp-admin capability (`capabilities.php` grants the `law_event` set to
administrator, editor and `events_committee` only) or the admin bar
(`law_user_may_use_wp_admin()`), so parity holds on the excluded side too. The
Members role rows on the pages that matter carry sponsor as well:
page 290 (Account), page 292 (My events) and page 294 (Submit an event). Two
divergences remain, both database state on the legacy stack rather than module
code:

1. **Page 279 (Inbox)**, the Gravity Flow inbox for form 2 (Event > submit an
   event), is restricted to administrator, editor, `event_host` and
   `events_committee` — no sponsor, so a sponsor on the legacy flow could not
   open their own inbox. Nothing in the new header bar links to it and it goes
   at cutover, so it is recorded rather than fixed.
2. **The "Top menu" (menu 19) If Menu rules** still name `event_host` without
   sponsor: item 407 (→ page 290, Account) shows for administrator, editor,
   `events_committee`, `event_host` and `attendee`, and items 408
   (→ page 292, My events) and 409 (→ page 294, Submit an event) for
   `event_host` alone. This is the exact drift `header-nav.php` was written to
   end. It is dead code: the theme registers only `main-menu` and
   `footer-menu`, and `parts/layout/top-nav.php` renders the bar now, so no
   `wp_nav_menu()` call reaches menu 19.

**The blank /account/ page (10 September 2026, fixed; the page replaced on
14 September 2026).** The page's editor copy gated its two blocks on role names,
so a sponsor-only user matched neither and got a heading with no body. Three
changes at the time: `[user-content]` gained the capability-backed `host` and
`committee` audiences (see §3, `shortcodes.php`); a setup helper rewrote the
page's `role="event_host"` block to `role="host"` from both provisioning
routes; and `templates/account.php` buffered `the_content()` and rendered a
generic signed-in fallback when nothing visible came out.

The whole class of bug left `/account/` on 14 September 2026, when it became
the Account hub: its tiles are built in code from `law_header_nav()`, and
`law_setup_account_page_content()` (the renamed helper) strips the
`[user-content]` blocks from the body, leaving only `[action-message]`. The
audiences still matter for page copy elsewhere, and `host` now means "signed
in", which `tests/AccountAudienceTest.php` pins along with the emptied body.

**Flagship conference (updated 10 September 2026).** The approval-gated
application flow is now built (`flagship-bookings.php`, `stripe/attendees.php`,
FLAGSHIP_PAYMENTS.md). `law_booking_guard_open()` still refuses the flagship,
and that refusal is now load-bearing rather than a placeholder: it is what
keeps the hosted booking form, "add a colleague", register-on-behalf and the
whole waitlist off an event where a place is a committee decision and a
charge. Still true: the flagship has no `_law_approved_at` and no HOST payment
meta, so anything printing those for a published event shows blanks for it
(its attendee payment meta lives on the bookings, not the event); and a
published flagship with no sessions has no start time, which is why its
programme block says "Programme to be announced" and its Time fact reads
"Times to be announced" rather than the event disappearing.

**Open, and deliberately so:**

1. **£550 / £600 is not in any LAW-signed document.** `EVENTS_4.2_SPECS.md` §2
   says £500 + VAT flat with no early-bird tier. The figures came from Denis on
   10 September 2026 and are worth confirming with Emily before go-live; they
   are settings, so changing them is a form, not a deploy.
2. **Reception pricing** (spec §2, §6.1) is still blocked on LAW: the Wednesday
   reception checkbox on flagship approval, the £25 flagship-only Monday price
   and the priority sales window are not built. The price snapshot and the
   discount catalogue are both generic so receptions reuse them.
3. **Salutation** is a new profile field the application form writes; the
   choice list has not been confirmed with LAW, so it is free text.
4. **No refund flow** (spec §2, by design). The Stripe invoice link on every
   paid booking is the deliverable, and a full refund arriving by webhook
   marks the booking refunded without cancelling the place, because whether
   the person still attends is a human decision.

**Reserved (present but intentionally unused):** the speaker
`_law_organisation_ids`, and the `host_edit_review` setting (its UI control is
disabled). Do not cut.

Two entries LEFT this list on 14 September 2026, both to the receptions
(RECEPTIONS.md): `_law_registration_state` now has its first reader — a
reception writes `invitation`, `open` or `free`, and the booking control reads
it — and the **discount catalogue** has its first consumer, the paid
receptions' checkout.

**Production needs a real cron** (14 September 2026, RECEPTIONS.md §9).
WAITLIST.md §91 already recorded that the site relies on WordPress's
pseudo-cron, which only fires when somebody happens to load a page. That was
tolerable while every booking was free. It is not now: the receptions' hourly
sweep releases abandoned holds, sends confirmations whose invoice never
arrived, reconciles orphaned charges and closes queue entries with no payment
method. The sweep also runs opportunistically after every Stripe webhook,
bounded to five rows, which covers the common cases — but a quiet site with no
webhooks and no visitors would still leave a held place held. **The deploy
notes must include a real cron hitting `wp-cron.php`.**

**Post-cutover cleanup ticket:** once the source flip is permanent, the `'gf'`
branches in `calendar.php`, `speakers.php` and `account-events.php`, the
legacy-entry-ID resolvers, and the migration tooling can be deleted wholesale —
the single largest line-count reduction available, deliberately deferred while
rollback must stay alive.

---

## Change history

Updated 16 September 2026 for **the programme's search results**: the keyword is
now marked in the cards, the flagship conference answers the filters like every
other event, and the Organiser filter became committee-only. Three asks in one
message from Denis, all about `/programme/`.

- **The keyword is marked** (`law_calendar_highlight()`). Filtering told a
  visitor which cards survived but never why, so a search for "gar live"
  returned a page of cards with nothing on any of them pointing at the words
  that matched. The helper wraps hits in `<mark class="law-hit">` in the title,
  the venue and the host — every field the card prints that the filter also
  searches.
  - **Server-side, and that is the whole design.** The programme renders through
    `parts/calendar-events.php` on the first load, on every filter fetch (the
    `&law_partial=1` endpoint re-renders the same part) and on the no-JS GET
    fallback, so one implementation covers all three and the committee's
    programme view for free, with no change to `calendar-filters.js`.
  - **The offset trap, which is why this is not three lines of `str_ireplace`.**
    `law_calendar_event_matches_filters()` searches a string that has been
    through `law_calendar_normalise_choice()`: entities decoded, whitespace runs
    collapsed, lower-cased. The first two *change the length* of the string
    — `&amp;` is five characters before decoding and one after — so an offset
    found in the normalised string does not point at the same character in the
    raw one. The helper therefore puts the text it is about to *print* through
    the same two transforms and searches that, with `mb_stripos()` doing the
    case-insensitive part so no second folded copy is needed to map offsets back
    from. A naive `strpos()` on the raw title passes every easy case and puts
    the mark in the wrong place on "Banking &amp; Finance".
  - Every branch that marks nothing returns `esc_html()` of the **raw** string,
    so a field with no hit is byte for byte what it was before the function
    existed. That matters because `parts/loop/event.php` is shared with the
    speaker profile, My events and My bookings.
  - **The card never reads `$_GET`.** `parts/calendar-events.php` reads the
    keyword once and passes it down as a documented `highlight` arg. `law_kw` is
    also the query var for four unrelated dashboards, so a card that reached for
    it would highlight itself on pages nobody asked about. There is a test whose
    only job is to fail if that ever changes.
  - **Accepted limitation**: the filter searches six things and the card prints
    three, so a card matched only on its type, a sector or its description shows
    with nothing marked. Narrowing the matcher to the printed fields would
    silently drop results visitors get today, which is a bigger change than the
    one asked for. Pinned by a test so it stays a decision rather than becoming
    an oversight.
  - **One class, site-wide.** `mark.law-hit` is declared once in `app.css` and
    shared with the speakers archive, whose `speaker-search.js` previously
    carried its own `.law-speakers__hit`. The two *matchers* stay deliberately
    different — the speakers archive filters as you type, client-side and
    per-token; the programme matches one contiguous phrase, server-side — but
    what the reader sees is the same thing and is now declared in one place.
  - **The fill alone, no underline.** A darkened `#a85400` `border-bottom` sat
    under the mark for the first few hours of 16 September 2026, because the
    pale orange fill is only 1.27:1 against white and 1.12:1 against the
    sponsored card's `#fdeedd` — a soft signal on one surface and close to none
    on the other. Denis removed it the same day, on both the programme and the
    committee dashboard: on a table where several rows carry a hit the
    underlines read as clutter. Two consequences are worth knowing before anyone
    reinstates it: **a hit on a sponsored programme card is nearly invisible**,
    and `forced-colors` mode is now the only place the mark has a hard edge
    (that block repaints it with the system's own `Mark`/`MarkText` pair).
    Deepening the fill, not restoring the border, is the way to strengthen it.
    The text colour is **pinned** rather than inherited: the flagship's three
    navy surfaces print white text, and white on this fill is 1.27:1, so one
    declaration settles every surface and a direct rule on the mark also
    outranks the colour it would otherwise inherit from the theme's global
    `a:hover { color: #fff }`.

- **The flagship answers the filters** (`law_calendar_visible_flagship_event()`).
  It had been pinned to its day whatever the filters said since 11 September
  2026. Denis reversed it: an event nobody can filter away is an event nobody can
  get out of the way, and a search for a term the conference does not carry
  should not return it. With no filters set it is pinned to its day exactly as
  before.
  - A second resolver rather than filtering inside
    `law_calendar_flagship_event()`, because the unfiltered answer is still the
    right one for "is there a flagship at all" — the conference's own page and
    the committee's screens resolve through it and must not inherit a visitor's
    search box. Memoised per context, because `law_calendar_day_is_empty()` asks
    once per day of the week and the matcher runs `law_rich_text_plain()` over
    the conference's whole rendered description.
  - The strip, the block, the day's `+1`, the day nav's "Flagship" pill and
    `law_calendar_day_is_empty()` all move together onto it.
  - **A bug the change created and the fix for it.** The flagship is not one of
    the day's *cards*, so `$law_has_events` in `parts/calendar-events.php` never
    counted it. Harmless while it was pinned; once it could match a search that
    no card matched, the page would print "No events match this search." directly
    above a block that plainly matched. `$law_has_events` now includes it.
  - **A JavaScript trap worth knowing before touching this again.**
    `calendar-tabs.js` cached the flagship's day from the nav's
    `data-flagship-day` at init, and the day nav lives *outside*
    `#law-cal-events` and is never swapped by a filter fetch. So the cached value
    goes stale the moment a filter hides the conference: the tab would keep its
    pill and its blank count where it should read "No events". The truth now
    comes from the swapped markup, where `parts/calendar-events.php` emits a
    marker on **every** render, empty value and all. The empty value is not
    laziness: the same script runs on the committee's timeline view, whose
    swapped markup (`parts/events/slot-chart.php`) knows nothing about the
    flagship, so "no marker found" has to stay distinguishable from "the marker
    says there is no visible flagship" — otherwise filtering the dashboard would
    strip that view's pill. Absent means "not the programme's markup, leave the
    server's value alone".
  - **A UI state nobody had seen before.** With the flagship no longer keeping
    one day alive, a keyword matching nothing now leaves every day tab greyed and
    no panel shown. `activate(null)` already handles it (only
    `.law-cal-day-section` elements are hidden, so the empty message survives),
    but it is newly reachable.
  - **Two things a delegate can see on the block are still not searchable**: its
    session titles and its speakers. `law_calendar_event_matches_filters()`
    searches title, host, venue, type, sectors and description, and `sessions` is
    a separate key on the mapped event. So searching for a session printed on the
    block now makes the whole conference disappear, where before it stayed
    pinned. Left alone deliberately — one matcher, one rule — but it is the most
    likely thing to be reported as a bug, and widening the haystack for the
    flagship alone would be the wrong fix.
  - `programme-old/parts/events.php` had to move onto the same resolver even
    though it is a frozen snapshot, because it shares `law_calendar_day_is_empty()`
    with the live layout: leaving it alone would have rendered the block through
    the out-of-week branch and never through the day one, a third behaviour
    neither layout has. That directory has now diverged twice from the copy it
    exists to be compared against, and its own README says it exists to be looked
    at and then deleted. **Worth asking whether it should go.**

- **Organiser is committee-only.** See the `_law_is_external` section above for
  the full reasoning, including why the gate is the page template rather than the
  viewer's capability.

New: `tests/ProgrammeHighlightTest.php`. Changed: `functions/calendar.php`
(`law_calendar_highlight()`, `law_calendar_visible_flagship_event()`,
`law_calendar_organiser_filter_enabled()`), `parts/calendar-events.php`,
`parts/calendar-daynav.php`, `parts/calendar-filters.php`,
`parts/loop/event.php`, `parts/events/flagship-card.php`,
`parts/events/flagship-strip.php`, `assets/js/calendar-tabs.js`,
`assets/js/speaker-search.js`, `assets/css/app.css`, `assets/css/speakers.css`,
`programme-old/parts/events.php`, `tests/FlagshipRenderTest.php`,
`tests/EventFlagsTest.php`. **Deliberately not built:** a "Host organisation"
filter, asked for in the same message and dropped once the live data was
queried — 57 published events carry 60 distinct values, 55 of them appearing
exactly once, and the free-text field holds combined firms ("Three Crowns LLP
and Burford Capital"), a typo ("Evershed Sutherland") and a person's name. A
dropdown built from that is a 60-item list where almost every choice narrows the
programme to one event, and "Burford Capital" returns one of its two. The same
conclusion is already recorded for the committee's search. Making it reliable
would mean linking each event to an `organisation` post at submission, which is
separate, larger work.

Updated 15 September 2026 for the **committee's timeline view**, a second view
of `/account/dashboard/` at `?law_view=slots` that draws a day at a time as a
time axis with every event as a bar, rows packed so that concurrent events never
share one. It answers the client's "it won't help us to identify clashes"
(emily O'Callaghan, 11 September 2026) and builds the day-and-time grid
EVENTS_4.2_SPECS.md §3.1 has promised since the spec was written. New:
`functions/events/slot-chart.php`, `parts/events/slot-chart.php`,
`assets/css/slot-chart.css`, `tests/SlotChartTest.php`. Changed:
`assets/css/event-form.css` (the dashboard's blanket link colour excludes the
day tabs and the timeline's bars, which choose their text colour against their
own background and were being painted navy-on-navy),
`committee.php` (the `law_include_flagship` override, and the AJAX partial now
returns whichever view the filters were applied from), `parts/calendar-daynav.php`
(optional days/counts/flagship_date/label, so the dashboard can supply its own
rather than the programme's), `templates/account-dashboard.php` (the outlined
view switch beside "Create an external event", the hidden `law_view` field
inside the filter form, the day nav, the branch), `assets/js/calendar-filters.js`
(a chart-shaped loading skeleton — the only JavaScript change in the piece of
work; `calendar-tabs.js` needed none) and `functions/enqueue.php`. Deliberately
**not** built: any event-to-event clash detection — the view makes overlaps
visible and leaves the judgement to the committee — and any drill-down, because
everything is on the chart (Denis, 15 September 2026).

Updated 14 September 2026 for the **drinks receptions** (RECEPTIONS.md, in
full). LAW runs three: Monday and Wednesday paid and pay-now and also free
with a confirmed flagship place, Friday invitation only. New:
`functions/events/receptions.php`, `functions/events/receptions-dashboard.php`,
`templates/account-dashboard-receptions.php`, `parts/events/receptions-list.php`,
`parts/events/reception-manage.php`, `parts/events/reception-checkout-modal.php`,
`parts/events/reception-include-modal.php`,
`parts/events/booking-payment-facts.php`, and
`law_stripe_create_checkout_session()` (Checkout in payment mode, with
`invoice_creation` on). Changed: `bookings.php` (the kind and price helpers,
the `guard_open` split, the shared payment plumbing extracted out of
`flagship-bookings.php`, the handler table, the recount gate, the clash
exemption, the paid-place cancel refusal), `statuses.php` and `meta.php` (a new
booking status and eleven meta keys), `waitlist.php` (priced entries seated as
`processing` with their charge claim, charged on a new after-unlock hook),
`stripe/webhook.php` (an outcome router that names no flow), `workflow.php`
(`law_event_managed_saving`), `account-bookings.php` (six control states and a
notice filter), `notifications.php` (twelve emails, two placeholders),
`discounts.php` and friends (the catalogue's first consumer), the bookings
lists and the three export builders, plus the provisioning in both routes.
58 new tests across five files; suite green at 601.

Three things in it are worth reading even if the receptions are not what you
are here for. **The recount gate**: `law-pending-payment` counts towards
capacity only on a priced event, so the last place cannot sell twice while
somebody is on Stripe's page, and a free event's count query is left literally
unchanged rather than merely equivalent. **The handler table**: the webhook
stopped naming flows, so adding a fourth needs no change there. **The
`guard_open` split**: "is this event live" and "may this form take a booking"
were one predicate and are now two, because the waitlist needs the first and
every form needs the second.

Updated 10 September 2026 for the **booking notification changes** (Denis):
the per-booking host and committee emails retired, and the capacity warning
split into two stages. `functions/events/notifications.php`,
`functions/events/bookings.php`, `functions/events/meta.php`,
`functions/setup-account-pages.php`,
`functions/events/migration/runner.php`; six new tests, suite green.

**`host_booking_received` and `committee_booking_received` now ship
`'active' => false`.** A busy event mailed the host and the committee (or the
assignee) on every submission, and Manage bookings already lists every booking.
Both registry entries and all four `law_events_send()` calls stay exactly where
they were, so the Emails screen's "Send this notification" box brings either
back with the site's own subject and body;
`law_events_send()` returns before recipient resolution while an email is
inactive, which is also why an inactive email leaves **no** line in the event's
activity log.

Turning them off in the registry is not enough on its own, and this is the trap
to remember for any future retirement: `law_events_email()` merges the
`law_events_email_overrides` option over the registry, `active` included, so on
an environment where anyone has pressed Save on either email the stored `true`
would beat the new default and the emails would keep sending after a deploy.
`law_setup_retire_booking_received_emails()` drops the stored `active` key for
the two slugs (leaving subject and body edits alone) and runs from **both**
`?setup-account-pages` and migration step 10, so a git push alone is enough.

**The capacity warning became two stages, each with its own one-shot latch.**
The old rule was a flat "5 or fewer places remaining", which warns far too late
on a 200-place event. The rule now lives in one place,
`law_event_capacity_warning_at( $available )`: fewer than 10% of the approved
places left, floored at 5. Integer arithmetic only
(`max( 5, ceil( $available / 10 ) - 1 )`), so 90 places warns at 8 left and 91
at 9, with nothing to round at the boundary. **The floor means the percentage
only bites from 61 places upwards** — a 40-place event still warns at 5 left,
exactly as before, which is the trade Denis chose when asked.

When the last place goes, `host_event_full` and `committee_event_full` (the
latter assignee-first) go out instead. `law_booking_maybe_capacity_warning()`
checks the full stage first and **consumes the nearly-full latch as well**, so a
pass that jumps from above the line straight to zero sends the sold-out email
alone rather than two in the same second. `law_event_recount_attendees()` clears
both latches together, at the nearly-full threshold rather than at the first
freed place, so cancelling one place on a full event and selling it again does
not send a second sold-out email. The one bare `5` that used to live at both the
fire site and the re-arm site, free to drift, is gone.

Two things left deliberately alone: `law_event_tickets_changed()` still does not
call the warning, so a committee member who *lowers* capacity below the
threshold triggers no email (unchanged behaviour); and the manual-promote guard
in `waitlist.php` still skips an over-booked event entirely, so neither stage
fires there. `tests/BookingEmailsTest.php` pins the threshold as a pure
function, both stages through the engine, the jump-to-zero case and the retired
emails being silent by default; `tests/BookingsTest.php` pins the two latches
re-arming together. The two tests that cover the retired emails tick them back
on first (isolated in memory), which also proves the Emails-screen toggle works
— and one of them had a host assertion that never ran, because the fixture left
`post_author` at 0 and `get_userdata( 0 )` is false.

Updated 10 September 2026 for the **flagship conference's application and
payment flow** (FLAGSHIP_PAYMENTS.md): `functions/events/flagship-bookings.php`,
`functions/events/flagship-bookings-dashboard.php`,
`functions/events/stripe/attendees.php`, `functions/account-flagship.php`,
`functions/events/discounts.php`, `functions/events/discounts-dashboard.php`,
`templates/account-dashboard-flagship-bookings.php`,
`templates/account-dashboard-discounts.php`, five new parts
(`flagship-apply-modal`, `flagship-success-modal`,
`flagship-manage-application`, `flagship-card-form`, `flagship-add-attendee`,
plus `flagship-bookings-list`, `discounts-list`, `discounts-manage`) and three
test suites (`FlagshipPaymentsTest`, `FlagshipBookingsDashboardTest`,
`DiscountsTest`); 352 tests green, up from 303.

A delegate applies from `/events/flagship/`, gives their card on Stripe's
hosted page without being charged, and waits; the committee approves or
declines on `/account/dashboard/flagship-bookings/`; approval raises a VAT
invoice and pays it off-session, and confirms the place. Three booking
statuses were added (`law-applied`, `law-declined`, `law-payment-failed`),
the flagship gained a places count and two prices with a cutover datetime
edited on both flagship screens, and the Stripe webhook learned to route by
`law_booking_id` so one endpoint serves host fees and attendee places alike.
It also fixed an open finding: a partial refund no longer marks a payment
fully refunded.

**Three review gates ran on the same day** (three conformance passes, then
design and security) and their fixes are part of the same work. Four of the
findings were money defects worth knowing about, because the shapes recur:
the committee's Decline button posted `approve`, because the table depended on
JavaScript hooks nobody had written; a declined applicant could still pay from
the hosted invoice link already in their inbox and be confirmed by the
webhook; a transient Stripe error on the resume step deleted the invoice
reference and raised a second invoice; and two simultaneous approvals both
passed the capacity check because neither the status read nor the count was
under the lock. All four are fixed and each is pinned by a named regression
test. `assets/js/booking-form.js` gained two general fixes in the process: it
now appends the submitter's name and value to its fetch body (a native submit
sends them; `FormData(form)` does not), and its submit handler is delegated at
the document, so a table swapped in by an AJAX filter keeps its behaviour
instead of silently falling back to a plain POST.

Two decisions from that round are worth remembering because the code looks odd
without them. **`law_booking_guard_open()` still refuses the flagship**, which
is not an oversight but the control keeping the hosted machinery away from a
priced, reviewed event. And the **discount catalogue is built and wired to
nothing**: Denis wanted codes kept for future use and explicitly not used on
the flagship.

Updated 9 September 2026 for the **flagship conference**
(`functions/events/flagship.php`, `functions/events/admin/flagship-screen.php`,
`templates/flagship-event.php`, `parts/events/flagship-card.php`,
`parts/events/session-timeline.php`, `parts/events/event-venue.php`,
`assets/js/law-flagship-admin.js`, `tests/FlagshipTest.php`,
`tests/FlagshipRenderTest.php`): one `law_event` post at `/events/flagship/`,
edited on a new Flagship screen under the Events menu, rendered as a single
event page with a vertical agenda timeline, and pinned to 2 December on the
programme as a highlighted block. The full specification is `FLAGSHIP_UI.md`.

The committee's own **Manage flagship** screen
(`/account/dashboard/flagship/`) landed the same day, from the top bar beside
Manage events, Manage bookings and Manage speakers: the same fields, editing
the same post, through the same code. What made that true rather than merely
intended was splitting the data layer into `flagship.php` and the agenda fields
into `flagship-form.php`, so neither screen owns any of the substance, and
pinning that split with a reflection test.

Four things worth knowing beyond the sections above. **It is a post, not a
settings option plus a page**, as originally specified: `/events/flagship` as a
WordPress page would be swallowed by the `law_event` rewrite, and option-stored
sessions would be invisible to the speaker directory, the `.ics` feed and the
bookings engine. **Two bugs the build found in existing code**: the
`wp_insert_post_data` status guard in `workflow.php` silently reverted the
"Show on the programme" tick, since the flagship has no workflow to move its
status (now a narrow, flagship-only exemption behind its own
`law_flagship_saving` flag); and the `time` sanitiser did not zero-pad, so
`9:30` sorted after `14:00` and a session validated as ending before it
started. **The venue moved below the agenda** on this page only (Denis,
9 September 2026), which is what took the venue markup out into its own part.
And **the whole feature self-provisions**: the migration's step 10, the
`?setup-account-pages` trigger and the screen's first open each create the post
if it is missing, so a git push is enough on any environment.

Updated 9 September 2026 for the **WYSIWYG editor on the descriptive fields**
(`functions/events/rich-text.php`, `assets/js/law-rich-text.js`,
`assets/css/rich-text.css`, `assets/css/rich-text-content.css`): the event
description, each speaker biography and each session description are now
edited in WordPress core's TinyMCE and stored as HTML, on the host form, the
committee's `?law_edit=1` view, Manage Speakers and the wp-admin event screen's
speaker rows. See `rich-text.php` above for the allowlist and the three
decisions behind it. The change was mostly on the write side: every render path
already ran `wpautop()`, but the savers ran `sanitize_textarea_field()` and
stripped the markup straight back out. Four read paths did have to change —
`parts/events/speaker-bio-modal.php` and the speaker card's no-JS `<details>`
block were calling `wp_strip_all_tags()` on the full biography, and the host
dashboard's preview printed the biography and the session descriptions with
`esc_html()`. `law_speaker_bio_summary()` now keeps the markup in `full` while
the excerpt and its word count work off the plain text, and the excerpt, the
keyword index, the `.ics` description, the notification summaries and the
speakers export all moved to `law_rich_text_plain()` so a bulleted list does
not collapse into one word.

Updated 9 September 2026 for **Manage Speakers**
(`/account/dashboard/speakers/`, `functions/events/speakers-dashboard.php`,
`templates/account-speakers-dashboard.php`,
`parts/events/speakers-dashboard-list.php`,
`parts/events/speaker-manage.php`): the committee's third front-end dashboard —
every speaker record in one filterable, exportable table, and per speaker an
editor listing each event they appear at with that event's role, organisation,
job title, photo and biography, saved by one "Save changes" button. Two shared
mechanisms were generalised rather than copied: `law_speakers_event_maps()` came
out of `law_speakers_confirmed_maps()` so the same pass can walk every event
status (a `law_speaker` post exists from the first draft save, not from
approval — the brief assumed otherwise), and
`law_setup_bookings_dashboard_access()` became
`law_setup_dashboard_child_access( $path )` with two named wrappers, now that
the events dashboard has two Members-restricted children (renamed again to
`law_setup_child_page_access( $path, $parent_path )` on 10 September 2026). The build also fixed
a live bug it walked straight into: the legacy `/speakers/<entry ID>/` redirect
in `source.php` was not scoped to the Speakers page, and `law_speaker` is a
registered public query var, so it 301'd every `?law_speaker=<post ID>` link on
any page to a different speaker's profile.

Updated 8 September 2026, second round, for the **review gates** on the
per-attendee rebuild: a security review (one medium, three low, all fixed), a
Playwright pass, three cold conformance audits and three specialist reviews
(design, DRY and scalability, copy). What changed as a result: the "is this
event still open" guard now re-runs INSIDE the event lock, so a committee
cancel completing while a booking waits for the lock can no longer seat someone
on a dead event; deleting a WordPress account now releases that person's places
(`deleted_user`), which core would not do because the booking CPT has no
`author` support; the wp-admin backstops offer the place they free to the
queue; `law_waitlist_for_event()` sorts in PHP rather than joining on the
position meta, which had been silently dropping any entry that lost its
position; `law_booking_attendee_email()` replaced five drifted copies of the
same address lookup, one of which had lost its validity check; the waitlist's
promotion walk builds its duplicate index once instead of reloading every
booking on the event per candidate; and booking-form.js now resolves the actual
submitter, so an error on a modal-confirmed action is shown in the dialog
rather than behind it. Copy: the confirmation no longer talks about colleagues
to people who booked alone, the cancellation emails no longer render "(, )" on
an event with no confirmed slot, and the waitlist's "we could not confirm your
place" email no longer refers to the reader in the third person.

Updated 8 September 2026 for the **per-attendee bookings rebuild and the
waitlist** (WAITLIST.md is the contract). A colleague a booker brings now gets
their own `law_booking` post with its own booking number instead of a row on
the booker's booking, so the whole surface changed shape: the engine returns an
array of bookings from one submission and rolls the whole submission back if
anything refuses, `law_booking_remove_attendee()` is gone in favour of
`law_booking_cancel()` with a context plus `law_bookings_cancel_party()`, the
host/committee/admin lists are flat with an "Invited by {name}" tag, the emails
name the booker and carry each person's own number, and the recount is one
`COUNT(*)`. On top of that, `functions/events/waitlist.php` adds the
booking-only `law-waitlisted` status, automatic first-in-first-out promotion
(with skip-in-place for an entry the guards refuse), host reorder and
"Promote now", and `law_event_tickets_changed()`, which offers newly released
places to the queue — nothing fired on a ticket-number change before. The four
notice maps were consolidated into `law_booking_notice_text()`. 138 tests green
(was 118), including the new `tests/WaitlistTest.php`. This file was renamed
from EVENTS_4.1_FUNC_V2.md in the same round, and its dated changelog moved
here.

Working reference for the custom, CPT-backed events module that replaces the
Gravity Forms / Gravity Flow / GravityView / Make stack described in
EVENTS_4.1_FUNC.md. Verified against the codebase and the local database on
5 September 2026, re-verified after the forms/payments security round and
the additional-host email round the same day, updated 6 September 2026
for the pre-launch-gate host bypass, the new `404.php` and the time-boxed,
batched migration history step, and updated 7 September 2026 for the
module-owned country → ISO mapper (`countries.php`), the committee
front-end edit view (with the resubmit-on-any-save fix that came with it),
the AJAX layer on the committee dashboard's workflow actions, and the
cancel/withdraw/delete round: the seventh status `law-cancelled`, the
committee Cancel and host Withdraw actions, the dashboard Delete-to-trash,
the Stripe invoice-void helper (`law_stripe_void_invoice()`) and three new
emails (25 total). Updated again 7 September 2026 for **bookings phase 1**
(EVENTS_BOOKINGS.md is that feature's design contract): the fourth CPT
`law_booking`, the bookings engine (`bookings.php`), the shared request
plumbing (`request.php`, where `law_events_redirect_back()` and
`law_events_rate_limit_ok()` moved from comments.php), the booking meta
schema plus the `_law_tickets_sold` / `_law_capacity_warned` event keys (a
`_law_capacity_full_warned` latch joined them on 10 September 2026), the
status guard and untrash filter extended to bookings, and the parameterised
`law_events_create_host_user()` + extracted
`law_events_password_setup_link()` in co-owners.php. Bookings phase 2 landed
the same day: the twelve bookings/welcome emails (37 total), the
`$extra['attachments']` extension to `law_events_send()`, the `.ics`
generator (`ics.php`), the `{event_date}`/`{event_time}` placeholders and
the registration welcome email. Bookings phase 3 (same day): the booking
surface — the five admin-post handlers on the shared request guard, the
five-state booking control (`functions/account-bookings.php`), the booking
modal and attendee repeater partials, `assets/js/booking-form.js`, the
programme card's placeholder Register action removed (a live one arrived on
11 September 2026, EVENTS_BOOKINGS.md §7.1a), the hero's
"Places remaining" fact, and the registration form's locked-role +
`redirect_to` round trip for the modal's register link. Phases 4–6 (same
day): the account area (the "Your bookings" section and audience split on
My events, the `?law_booking=` manage view, the page 292 (My events)
attendee-access fix scripted in `law_setup_account_events_attendee_access()`
+ migration step 10), the host/committee `?law_event_bookings=` list with
per-attendee Reject (committee only since 11 September 2026) and the
CSV/Excel/PDF export trio
(`law_booking_export`, reusing export.php with a new optional title line),
the read-only wp-admin booking screen (`admin/booking-screen.php`) and the
events list's Booked column, and the event-cancel sweep
(`law_bookings_cancel_all_for_event()`, called from the workflow's cancel
side effects). The phase 7 security review (no critical/high findings) led
to: all booking guards moved inside the event lock and the lock extended to
remove/cancel; a row/owner cap inside the `attendee_rows` sanitiser; the
.ics temp file deleted in a `finally`; and a locked registration role
enforced server-side. Accepted info-level items (wp-admin trash of bookings
bypassing the engine, unescaped email subjects) are recorded in
EVENTS_BOOKINGS.md §14. A further round of three independent reviews
(plan-conformance, adversarial correctness, performance) then landed:
account creation and attendee emails moved OUTSIDE the lock (seats written
under it, IDs backfilled after); the last-row auto-cancel re-checks rows
under its own lock; events trashed/hard-deleted outside the workflow
sweep-cancel their bookings (`wp_trash_post`/`before_delete_post`), and the
sweep is time-boxed with a `law_bookings_resume_cancel_sweep` cron
continuation; `law_events_bump_counter()` is atomic
(`ON DUPLICATE KEY UPDATE` + `LAST_INSERT_ID()`), which also hardens the
LAW reference counter; `law_events_rate_limit_ok()` gained an optional
larger per-IP budget (booking surfaces pass 100/150 against shared-NAT
offices); self-removal matches the row's linked user ID; an inverted
`_law_end` falls back like a missing one in the clash guard
(`law_booking_clash_end()`) and the .ics; recount/export fetch all bookings
(-1); a no-recipient send logs "Email NOT sent"; `cache_users()` primes the
bookings list and export. EVENTS_BOOKINGS.md §14 carries the full list.
Updated 8 September 2026 for the **per-appearance speaker role** (Trevor,
3 September 2026: a person is a Speaker, Host or Moderator *at each event*):
`law_speaker_roles()` and its key/label/display helpers, the canonical-key
`speaker_rows` sanitiser, the Role select on the host form and both wp-admin
relationship pickers (the host form save used to blank a wp-admin role on
every edit), the role in brackets after the name on event and session
speaker cards, the bio dialog and the committee dashboard, the "[name]'s
role" line on the profile's Speaking-at cards, and the migration's form 8
field 9 (Role) mapping (step 3, the step 4b refresh, a warn-only preflight
line).
Updated 8 September 2026 for the **committee bookings round** (EVENTS_BOOKINGS.md
§7.6 and §2): the cross-event **Bookings dashboard** page
(`/account/dashboard/bookings/`, `templates/account-bookings-dashboard.php`,
`functions/events/bookings-dashboard.php`, "Manage Bookings" in the header
account dropdown for committee), **registering an attendee on their behalf**
from the per-event bookings list (`law_booking_register_by_manager()`, handler
`law_booking_register_attendee`, a committee-only **press pass** flag on the
row, two new emails — 39 total), the **Country** column on the per-event list
and export, and the booking control's button renamed **Register** (was "Book
now", per 4.2 spec §3.4's vocabulary for free events).
Updated 9 September 2026 for the **committee event preview**: a new
`?preview-event=<id>` route on the dashboard page renders the attendee-facing
single event view for an unpublished event, reusing `parts/calendar-body.php`
via its two new caller variables `$law_cal_event` and `$law_cal_back` (the
latter retargeting both ways back off that view, so both "Back to programme"
links become "Back to event" together); the detail view's
"Edit event details" button moved from the foot of the cell to directly under
the event title, paired with the new "Preview event" button in a
`.law-dashboard__event-actions` flex row that opts out of `app.css`'s
full-width `.button` (that button reads **"View event"** and links to the
permalink in a new tab once the event is Confirmed); a `$preview` mode on `law_booking_render_action()` /
`law_booking_render_opener()` so the preview shows the availability row and a
**disabled** Register button rather than omitting the control; `nocache_headers()`
on the whole committee dashboard render; and `law_booking_is_event_view()`
widened to cover the committee programme's single event view, which had been
rendering the booking control without its modal assets.
Updated 9 September 2026 for **test-database isolation**, after unit-test
placeholders reached the live local site. `tests/class-law-test-case.php` used
to write its Stripe fixture IDs into the real `law_events_settings` option and
restore the previous value in `tearDown()`; an interrupted run skipped the
restore, and because the next run then snapshotted the polluted value as the
real one, `txr_test_unit` / `inrtem_test_unit` became permanent. That stayed
invisible while `mu-plugins/block-outbound.php` blocked `api.stripe.com`
outright, and surfaced the moment the block started allowing test-mode
requests: every committee approval of a paid event failed at the Stripe API
with "No such invoice rendering template: 'inrtem_test_unit'". The harness now
has `isolate_option()`, which serves an option from memory through
`pre_option_<name>` and cancels writes through `pre_update_option_<name>` (by
returning the old value, so `update_option()` short-circuits), leaving reads
and writes normal inside a test but incapable of reaching the database. It is
applied to `law_events_settings` and to `law_stripe_processed_events`, the
webhook's idempotency ledger, which the suite had filled with 499 synthetic
`evt_` IDs against its 500-entry cap -- evicting every real Stripe event ID and
leaving the duplicate-delivery guard dead locally.

Updated 9 September 2026 for the waitlist reorder round. The host's move
arrows answered successfully and then did nothing: every one of these handlers
redirects back to the list the form was posted from, ending in
`#law-waitlist`, and `location.replace()` on a URL that differs from the
current one only by its fragment is a same-document navigation, so the browser
scrolled to the anchor and never reloaded. The first move worked (the landing
URL had no `law_notice` yet, so the URL genuinely changed) and every move after
it left the arrow stuck on "Moving…" -- the same trap under repeat Promote now
and repeat Reject of a waitlisted entry, which sat on "Reloading the page…"
forever. `window.lawModal.redirect()` now owns that decision for all four fetch
layers, and the reorder no longer reloads at all: the handler answers with the
queue's new order and `booking-form.js` applies it in place.

Updated 9 September 2026: the form heroes are now solid navy. Every hero that
carries a form -- sign in, forgot password and reset password
(`templates/login.php`), register (`register.php`), profile
(`account-profile.php`) and submit/edit an event (`account-event-form.php`),
plus the account landing (`account.php`) and the committee calendar's
signed-out gate (`calendar-committee.php`) for consistency -- renders on flat
`#292459` with no photograph, which is what the account pages already did below
1024px. The switch is one class, `hero-solid` (`assets/css/app.css`), exposed on
the shared banner as `parts/layout/hero-title.php`'s `solid` arg; the partial
drops the background image when it is set, so the photograph is never fetched.
`auth.css`'s translucent `rgba(38, 30, 91, .8)` overlay is now scoped
`:not(.hero-solid)`, because that file loads after `app.css` and would otherwise
win the specificity tie. The same round added the profile and event-form
templates to auth.css's full-height flex-column rule, which had only listed
login, register and account: short states on those pages (the profile page's
"please sign in") left the browser's white canvas showing below the footer.

Updated 9 September 2026 again for **speaker names in two parts and the Role
select's placeholder**. Every surface that collects a speaker now asks for a
**First name** and a **Last name** separately (Denis), stored on the speaker
post as `_law_speaker_first_name` / `_law_speaker_last_name` beside the
`post_title` that stays the display name: the host and committee event form
(`parts/events/event-form-fields.php`, both required on a started row), Manage
Speakers (`parts/events/speaker-manage.php`), the wp-admin speaker screen
(which rebuilds the title from the two on save) and migration step 2, which
maps form 8 (Event > speaker) field 1.3 (Name, First) and field 1.6 (Name,
Last) straight across instead of joining them. `law_speaker_split_name()` is
the fallback for every record saved before the change and for the legacy List
field 48 path, which has only one name column, so **no backfill is needed to
deploy this**. A database migrated *before* the change is repaired by re-running
step 2: the already-migrated branch now calls
`law_migration_backfill_speaker_name()`, which gap-fills the two fields from the
source entry (never overwriting a name corrected on the WordPress side since)
and reports each one. The Manage Speakers export splits its Name column in two. In the
same round the speaker **Role** select lost its default: its first option is now
a blank `Select role` placeholder on all four surfaces that render it (the host
form, Manage Speakers, the wp-admin relationship row and the row `law-admin.js`
builds for an AJAX-added speaker). An unset role still reads as Speaker on the
public cards, which `law_speaker_role_display()` remains the one line to change.

Updated 9 September 2026 again for **the venue details being asked only of a
host who already has a venue**. (**Superseded on 17 September 2026**: the
question came off the form and the three details are now on every form and
required of everyone. The paragraphs below describe the arrangement as it stood
between those dates.) On the Venue section, the venue name/address,
**Venue capacity** and **Places available** are now hidden from a host who
answers "Yes, please share our details with venue hosts", and stay hidden even
after the committee has filled them in; a host only ever sees the three by
answering "No, we already have a venue planned" (Denis). This is a
**deliberate divergence from form 2 (Event > submit an event) parity**, and the
first place the rebuild knowingly asks less than the legacy form did: field 21
(Venue) was conditional there on field 103 (Venue needed), but field 55 (Venue
capacity) and field 54 (Tickets available) carried **no conditional logic at
all** and always showed. Asking a host who has just asked LAW to find them a
room for that room's capacity and its ticket allocation only ever collected a
guess, and the "TBC" band existed to absorb it.

`law_events_venue_details_visible()` is the single predicate, sitting beside
`law_events_locked_fields()` and keyed the same way, on
`law_user_is_committee()` rather than on the form template's `context` arg,
which stays copy/voice only: a **committee member always sees the whole block**,
whichever way the host answered, because on an event LAW places they are the
ones who know the venue and its band. Their other two doors are unchanged: the
front-end committee edit form (no lock on `venue_capacity` at any status) and
the wp-admin **Event facts** box, which the `events_committee` role reaches
through the ordinary `edit_law_events` cap. The dashboard panel itself gained
the two fields the same day, see below. When the block is visible to the
committee on an event whose host asked for a venue, it carries a hint saying so.

Two save-path consequences, both in `law_events_form_save()`:

- **A hidden field posts nothing, and an absent value is never read as a
  cleared one.** `_law_venue`, `_law_venue_capacity` and
  `_law_tickets_available` are written only when the same predicate says the
  fields were on this submitter's form, so a host's save on a placed event
  leaves the committee's venue, band and places exactly as they are (Denis
  chose keeping stored values over clearing them, accepting a possibly stale
  venue name over silent data loss). Without that guard the three keys were
  written unconditionally, so every host save of a LAW-placed event would have
  blanked them, and blanking `_law_tickets_available` takes the booking and
  waitlist capacity with it.
- **The ticket/band ceiling is not judged on a submitter who was never asked.**
  The check now reads the posted places only when the block was visible;
  otherwise a crafted post is ignored on save rather than blocking the rest of
  an otherwise valid form over a field the host cannot see.

`law_events_venue_needed_value()` is the shared "which answer are we judging"
helper: `venue_needed` is in the host lock list and a disabled radio posts
nothing, so post-approval a host's posted answer is always empty and the stored
one has to stand in. The form part uses it for the two `checked()` calls as
well, which also fixes an error re-render of a post-approval host edit showing
neither radio selected.

`host_capacity_warning` ("Email to host > event nearly full") was reworded in
the same round, because its "you can raise the number of places from your
events dashboard" advice is only true for a host who has their own venue. It now
adds "If LAW arranged your venue, the places are set by the committee, so please
reply to this email and we will raise them for you." A site with a stored
override for that slug keeps its own text, so check the
**`law_events_email_overrides`** option (`LAW_EVENTS_EMAIL_OVERRIDES_OPTION`,
notifications.php) before assuming the registry default is what sends. Its
threshold changed the next day: see the change history entry for the two-stage
capacity warning.

Updated the same day with **Venue capacity and Places available as committee
controls on the dashboard panel** (Denis, asked for after the rule above made
the committee their sole owner for a placed event). They were read-only rows in
the detail facts list, editable only behind "Edit event details" or in
wp-admin; both are now fields in the **Committee controls** panel itself
(`templates/account-dashboard.php`, after Confirmed slot), rendered **always**,
not only for a placed event, because the committee owns the band at every
status. They reuse the form's `data-law-capacity` / `data-law-tickets` hooks, so
the sync in `assets/js/event-form.js` keeps the number field's max in step with
the band here too (the script is already enqueued on the dashboard, and the
panel and the edit form never render together, so its single-element lookup is
safe). A blank Places available was described here as meaning "no limit",
citing `law_events_bookings_remaining()`. **Both halves of that were wrong**, and
the correction is in the 15 September 2026 entry below: no such function exists,
and `law_event_tickets_remaining()`, which does, reads 0 or unset as **not open
for booking**.

`law_committee_venue_input_error()` holds the rules, deliberately outside the
handler so they can be tested and reused rather than only exercised through a
request that exits: an unrecognised band is refused (it would be read as "no
ceiling" everywhere it is checked, silently uncapping the allocation), places
must be a whole number of 1 or more or blank, and the **inclusive** band
ceiling is checked against the band **being saved in the same post**, not the
stored one. It uses `array_key_exists()`, not `isset()`, because "251+" and
"TBC" map to `null` and `isset()` reads a null value as an absent key, which
would refuse the two uncapped bands. Like the fee override, the check runs
**before any write**, so a refusal leaves the event untouched. This is the
first door with band validation: **wp-admin still has none**, so a value typed
there can still disable the ceiling the front end enforces.

Logging follows the WooCommerce-notes rule: the new
`law_event_log_capacity_change()` writes "Venue capacity changed: (not set) →
101-150.", and `law_event_tickets_changed()` supplies the places line and
offers any newly opened places to the waitlist, with `committee_panel` as the
source. An unchanged save logs neither. The panel's own help text says only
that places cannot exceed the band, that "251+" and "TBC" set no ceiling, that
blank means no limit and that raising it offers the places to the waitlist (the
first three of those were reworded on 15 September 2026, when the band gained a
floor and the "no limit" claim turned out to be false):
**the activity log is not mentioned in UI copy** (Denis), it is machinery the
committee can see for themselves in the log.

Markup and JS notes: the three fields share **one plain wrapper**
(`#law-venue-details`) rather than the `.law-row-grid` element itself, because
`.law-row-grid { display: grid }` is authored after Foundation's
`[hidden] { display: none }` and would win the specificity tie -- the same trap
`.law-pass-strength[hidden]` and `.law-file-clear[hidden]` already work around
with `!important`. The shared `data-law-toggles` handler in
`assets/js/event-form.js` gained **`data-law-toggles-keep`**, which hides
without clearing: the sector "please specify" inputs still clear on hide, but a
host toggling No -> Yes -> No must not lose the venue they typed, and the save
guard above already makes the posted value moot. `tests/VenueDetailsTest.php`
covers the predicate, both save paths, the band ceiling and the crafted-post
case.

Updated 10 September 2026 for the **My bookings / My events split**. The one
`/account/events/` page served two audiences: the host's own events, and, since
bookings phases 4-6, a "My bookings" section stacked above them, with the header
renaming the single link for whoever was looking. They are two pages now.
`templates/account-bookings.php` ("My bookings", `/account/bookings/`, a child
of `/account/`) carries the listing and the `?law_booking=` manage view;
`templates/account-events.php` keeps the host listing, the `?law_thread=` thread
and the `?law_event_bookings=` attendee list, which stays because it is about a
host's own event rather than the viewer's bookings.

The audience rule is that the new page is for **every signed-in role**, not
attendees only: hosts, sponsors and committee members book places at other
firms' events like anyone else (Denis, 10 September 2026, reversing his own
first answer once reminded of that). So `law_setup_my_bookings_access()` copies
the role rows from page 290 (Account) rather than the committee rows the
dashboard children take, and the header offers a host-like user **both** items.

Three things carry the weight, and are the parts to preserve:

- **`/account/events/` keeps its attendee Members row** and redirects instead of
  refusing. Every confirmation email already sent links there, and a redirect
  can only run for a visitor the Members plugin lets through the door.
- **`?law_booking=` is forwarded**, not dropped, for the same reason, and
  because the no-JS booking handlers return to the *referer* via
  `law_events_redirect_back()` rather than to any URL the module controls.
- **The audience redirect tests events as well as role.** See
  `account-events.php` in §3: an existing attendee account linked as a co-owner
  keeps its role, so a role-only test would take that person's own event away.

`{bookings_link}` (eleven attendee emails) now resolves to the new page and
`{dashboard_link}` stays on My events; they resolved to the same URL until now,
so nothing would have caught a swap, and `tests/MyBookingsPageTest.php` pins
both. Only `user_welcome_registered_host` needed its body edited, because it
used `{bookings_link}` to mean "your events". That template has since been
removed altogether (16 September 2026), so the hand-check this paragraph asked
for no longer applies. `law_setup_dashboard_child_access( $path )` became
`law_setup_child_page_access( $path, $parent_path )` to serve a child of
`/account/`; both provisioning routes (`?setup-account-pages` and migration step
10) create the page, and both were run from scratch to prove it.

### The event details box: urgency, and a layout that survives many sectors (11 September 2026)

The client reviewed a single event page and raised two things about the facts
box in the hero. Both are in §"Templates, parts and assets" and §"Bookings"
above; the decisions behind them are here.

**"N places left" was invisible**, and they asked for it "in a box, to create
urgency". It is now `law_booking_panel()`, a filled navy block with the count at 1.6rem
in white and a larger Register button, sitting outside the facts surface at the
same width. What was pushed back on, and Denis agreed: a bold "80 places left"
creates no urgency at all, it advertises an empty room. So the box is
structurally prominent on every event, but the amber treatment and the word
"Only" fire only at `law_event_capacity_warning_at()`, the threshold that
already decides when the host gets a nearly-full email. One threshold, two
consumers, no way for the page and the email to disagree.

**Seven sectors broke the grid.** A comma-joined list in a third-width cell
wrapped to four lines and dragged Hosted by and Type along with it. The grid is
now three columns at desktop (four on the flagship, which has four facts and no
sector), and the sectors render as pills linking to the programme filtered by
that term, which is what lets Sector sit in a cell the same width as every
other. That
link exposed a latent bug worth knowing about: `law_calendar_url()` never
encoded its values, and `esc_url()` only entity-escapes an ampersand, which a
browser still sends as a separator. "Banking & Financial Services" therefore
arrived as two query args and filtered on "Banking ". `law_calendar_url_args()`
now encodes, which also fixes "Back to programme" under any filtered term with
an ampersand in it.

Deliberately **not** done: a time-based countdown ("closes in 12 days"), which
Denis ruled out; and an availability flag on the programme listing cards, which
would probably do more for urgency than anything on the detail page but is a
separate decision.

**The panel's two slots, later the same day.** The flagship had been given the
same panel but not the same contents: its count was a Places row up in the facts
box, so the panel held nothing but an Apply button, which a lone flex item under
`justify-content: space-between` parks at the panel's LEFT edge. Denis asked for
the count on the left of the banner and the button always on the right. The
count moved into the panel as the same `.law-booking-panel__count`, and the
Places row (with `law_flagship_details_places_tone()`, the grid's scarcity
colour, and the `tone` plumbing in `parts/calendar-event-details.php` that
existed only for it) went, because the panel's own fill already escalates.

Moving it exposed the same fault in the states that DID have words, on hosted
events as much as on the flagship: the heading is `flex-basis: 100%`, so
"You're attending" took a row of its own above the row holding "Your place is
confirmed…" and the button, and on a full conference "Fully booked" and the line
explaining it sat at opposite ends of one row. The
panel is therefore built in two slots now, `.law-booking-panel__main` and
`.law-booking-panel__action`, and Denis asked immediately afterwards that the
hosted events follow the same logic, so `law_booking_panel()` builds them for
**both** controls and the colleagues-only state's two buttons share the action
slot; see the **availability panel** entry in §"Bookings" for how they are
assembled.
Worth knowing if you touch either: the paragraphs' `color: inherit` was a
direct-child selector of the panel, which the wrapper silently broke — every
heading went back to near-black on navy — so check the ink rules, not only the
layout ones, before wrapping anything else inside that panel. The direct-child
layout rules themselves went, since nothing is a direct child any more but the
pill, the two slots and the no-JS form. The availability pill then moved INTO
the left slot, above the count, for the same reason the words were gathered
there: "Almost full" and "Only 3 places left" are one statement, and spanning the
whole panel made it a banner over the button too.

**Three columns, four on the flagship (same day).** The facts grid had gone to
four columns for everyone when the sector pills landed. Denis asked for three on
hosted events and four kept on the flagship, which is the right split: the
flagship states four facts, one clean row of four that three columns would break
into 3 + 1, while a hosted event states six. `.law-event-details--flagship` is
added by `parts/calendar-event-details.php` from `law_flagship_is()`, not passed
in by a caller, so the two surfaces that render the box cannot disagree about
which kind they are showing. Sector's row span went entirely: at three columns the six
facts make two tidy rows on their own, and a full-width Sector would leave a
hole beside Hosted by. Location dropped to 1rem in the same round — a long
street address at 1.1rem and underlined wrapped to three lines and shouted over
the facts beside it.

### Upload guidelines under every photo control (11 September 2026)

Denis asked for the photo rules to be spelled out in small text directly
below each upload module. They were not: the two file inputs carried
"Photo (JPG/PNG/WebP, 5 MB max)" in the label, and the pixel bounds appeared
nowhere at all, so a host uploading a 7000px photo failed on a rule they had
never been shown. The media-library pickers said nothing about formats.

**Checked against Gravity Forms first**, since the rebuild's brief was not to
ship weaker than the old stack. The legacy setup had exactly one upload
field, form 8 (Event > speaker), field 6 (Photo), reached as a GP Nested
Forms child of form 2 (Event > submit an event), field 112 (Speakers). It set
`allowedExtensions` to `jpg,png,webp` and nothing else: no size cap (so the
effective limit was PHP's `upload_max_filesize`), no dimension check, no
content sniff, and an empty `description`. The current limits are far
stricter and stay as they are (Denis): JPG/PNG/WebP by content sniff, 5 MB,
50×50 to 6000×6000. `EVENTS_4.1_REBUILD.md` has been corrected, since its
"Gravity Forms provided its own hardening" line was being read as a benchmark
and flattered the old setup.

**The limits now have one home.** They were written out in four places (the
validator's MIME list, the `wp_handle_upload()` override, and the `accept`
attribute in two templates), and adding six strings of help text quoting the
same numbers would have guaranteed drift. Everything derives from the
constants and helpers listed under `submission-form.php` above, so the
sentence a host reads cannot promise a limit the validator does not enforce,
and `tests/SpeakerPhotoTest.php` asserts the three stay in step.

**Where the text went.** `law_events_photo_hint()` renders under both real
file inputs: the speaker row on the event form
(`parts/events/event-form-fields.php`, inside the label, after the
"current photo" note, with a CSS rule in `event-form.css` resetting the
row-label weight it would otherwise inherit) and the committee's Manage
speaker screen (`parts/events/speaker-manage.php`, appended to the existing
hint so the cell keeps one line of help text rather than two). Both labels
shorten to plain "Photo", because the constraints now sit below the control
rather than in its name.

The media-library pickers get their own wording, not
`law_events_photo_hint()`, and this is deliberate: they post an attachment ID
and never reach `law_events_validate_photos()`, so quoting our 5 MB cap and
pixel bounds there would state a rule nothing enforces. The flagship banner
(both `parts/events/flagship-manage.php` and the wp-admin screen) says "JPG,
PNG or WebP. A wide image at least 1600 pixels across works best." The
speaker rows say "JPG, PNG or WebP. Square photos at least 600 pixels across
work best.", printed **once** by `law_field_relationship()` under the whole
repeater rather than once per row: the per-row control is a 32px thumbnail
and two link buttons, and a hint under each would be noise. Once on the group
also keeps it out of `law-admin.js`, which rebuilds the row markup for a row
added via the search and would otherwise need its own copy of the sentence.

**A client-side pre-check** now runs before the upload
(`assets/js/event-form.js`, extending the delegated file-input handler that
already toggled "Clear photo"). Size and type only; dimensions stay
server-side. The numbers come from PHP as `window.lawPhotoLimits`, via
`wp_add_inline_script` rather than `wp_localize_script`, which casts every
scalar to a string and would have made `maxBytes` a string comparison. The
duplication with the server is on purpose: a server-side rejection costs the
whole upload and then a form re-render, and a re-render cannot repopulate a
file input, so a host on a phone lost every other photo on the form to one
oversized file. The message renders next to the input rather than at the top
of the fieldset where the server's photo errors print, which is why the two
placements differ; moving the server-side ones to match was left alone.

**Consolidated on the way past.** `functions/events/flagship-form.php` had
hand-duplicated `law_field_relationship_photo()`'s markup for its "new
speaker" rows. It now calls the helper, which takes an optional fourth
argument for callers whose rows are not keyed `$name[$i]`.

### All three venue details required of a host who has a venue (11 September 2026)

> **Superseded on 17 September 2026**, and only in its scope: the answer this
> rule keyed on is no longer asked, so all three are required of *every*
> submitter. The rule itself, and both of its exemptions, are unchanged. See
> "The Venue needed question comes off the form" at the foot of this document.

On the Venue section, answering **"No, we already have a venue planned"** now
makes **Venue (name and/or address)**, **Venue capacity** and **Places
available** all required, on the host form (create and manage alike) and on the
committee edit form (Denis). Only the venue name was required before; the band
and the places were optional, which let a host who has their own room leave
both blank, and a blank band removes the ticket ceiling
(`law_events_venue_capacity_bands()` maps an unrecognised band to "no limit"
everywhere it is checked) while blank places leave the booking capacity
unlimited. "TBC" is one of the bands, so a submitter who does not know the
numbers yet still has an answer to give.

`law_events_venue_details_required()` (submission-form.php, beside
`law_events_venue_details_visible()`) is the single predicate: the fields are on
this submitter's form **and** the judged answer starts with "No,". Two
deliberate exemptions inside it:

- **The other answer requires nothing.** A host who asked LAW to find a venue
  is not shown the three at all, and the committee, who always sees them, is
  filling them in once the event is placed -- holding up their save of any
  other field until they have would be wrong.
- **A locked band is never re-validated.** `venue_capacity` is in the host lock
  list and a disabled `<select>` posts nothing, so post-approval a host's
  posted band is always empty; requiring it would make every post-approval host
  edit impossible. The stored band stands, as it already did on save.

The answer is judged with `law_events_venue_needed_value()`, the same helper the
form part renders by, so the two cannot disagree about what was asked.

Markup (`parts/events/event-form-fields.php`): the three labels carry the `*`,
and the capacity select gained the `law_error_message( 'venue_capacity' )` call
it never had. **No HTML `required` attributes**, matching the sector "please
specify" inputs: a `hidden` field is still constraint-validated by the browser,
which would block the form with an unfocusable control, and the draft save must
stay possible. The host's stars are unconditional, because their block is on
screen only on that answer; the committee's follow the stored answer, like the
hint above them. `tests/VenueDetailsTest.php` covers the new rule and both
exemptions.

### The account hub, and the end of the self-service roles (14 September 2026)

The client asked for the role checkboxes to come off the registration form.
That one sentence retires `event_host`, `sponsor` and `attendee` altogether:
any signed-in person may submit an event and book a place, every self-service
account becomes a plain `subscriber`, and with everybody landing in the same
place `/account/` becomes an Account hub. ROLES_AND_ACCOUNT_HUB.md is the
contract; this is what was built.

**How little actually depended on the roles.** They held only the `read`
capability, so nothing capability-based touched them: the committee gate, the
per-event gate, the wp-admin screens and the workflow engine were all untouched.
Every dependency was a string comparison, and only two of those gated anything —
`law_events_user_can_submit()` and `law_account_user_is_host_like()`, which both
now mean "signed in". The rest was form fields, email selection, HubSpot tags,
account-creation defaults, Members rows, tests and documentation.

**Five settled decisions (Denis).**

1. **Committee keep their shortcut.** Ordinary users land on the hub; committee
   members still go straight to `/account/dashboard/`. They sign in to work the
   queue, and one extra click every time is a real cost against a page they do
   not need.
2. **My events appears only for people who have events**, committee included.
   With no roles, "is this a host" can only mean "does this person have an
   event", and linking everybody to a page that would be empty for most of them
   is noise. Submit an event is offered to everybody, so a first-time host still
   has a way in.
3. **The host/sponsor signal survives as optional meta, not a role.** Two ticks,
   "I plan to host an event" and "I represent a LAW sponsor", stored as
   `law_intent`. They choose the welcome email and the HubSpot contact type and
   gate nothing. Two rather than one because the HubSpot tags are distinct, and
   migration step 11 seeds them from the roles so nothing is lost.
4. **Minimal cleanup.** The role definitions stay in `wp_user_roles` unassigned
   (the mu-plugin re-registers `event_host` on every `init` regardless, and
   mu-plugins are off limits), which keeps a rollback to a code revert. The
   legacy Gravity Forms hooks in `functions/users.php` and `functions/hubspot.php`
   and the User Registration feed on form 1 (User registration) are left for the
   post-cutover cleanup ticket; they only fire on the retired forms.
5. **One order for both surfaces:** My profile, My bookings, My events, Submit
   an event, the committee tools, Sign out.

**The hub.** `templates/account-hub.php` with `parts/layout/account-tiles.php`
and `assets/css/account-hub.css`: filled navy boxes, every one the same weight,
built from `law_header_nav()`'s items, which gained `group`, `icon` and
`description` for the purpose. A second list would have been the drift the
header rework existed to end. `law_icon()` in `functions/helpers.php` is the
theme's first shared icon set; the eight glyphs from the event details box moved
into it rather than being copied.

**Three things that would have broken silently**, and are the parts to preserve:

- **The guard in `law_registration_apply_attendee_profile()`** reads "this
  account holds nothing beyond a self-service role, so a host may fill its
  blanks". Emptying the role map without rewriting it makes `array_diff()`
  truthy for every account, so on-behalf profile fills stop working for
  everybody, with no error and nothing logged. It names `subscriber` and the
  three retired roles now, the latter for the window before step 11 runs.
- **The default sign-in destination is written in one place**
  (`law_auth_default_redirect()`), because the committee's shortcut is expressed
  as a comparison against it. Two copies, and the shortcut stops firing with
  nothing visibly wrong.
- **The account-page Members rows** (`law_setup_account_page_roles()`,
  run by both provisioning routes, and by step 10 before step 11). Without them
  the retirement locks every user out of their own account, and the refusal
  comes from the Members plugin rather than from any code here. On production:
  deploy, run `?setup-account-pages` or step 10, and only then step 11.

**The hub template carries its own `is_user_logged_in()` check** for a related
reason: the Members plugin's content permissions filter `the_content()`, and the
hub never calls it, so the page's restriction cannot reach its markup.

Locally the step moved 302 accounts, seeding 149 hosting ticks and 40 sponsor
ticks, and both administrator accounts kept administrator. New tests:
`RoleRetirementTest`, `AuthRedirectTest`, `AccountHubTest`; `HeaderNavTest`'s
fixture is a table of situations rather than roles, which is the change in one
line. **Still open and now wider:** the self-asserted £0 sponsor fee tier (§6).
**For the client:** the HubSpot Sponsor and Event Host tags come from the
optional ticks now, and the tag strings are unchanged.

### Places available belongs to the committee from submission (14 September 2026)

On the event edit form, **Places available is read-only for a host**, while
**Venue capacity stays theirs while the event is under review** (Denis). This
**reverses 4.2 §4.2**, which had the ticket allocation as the one venue number a
host kept editing "within the approved capacity band"; EVENTS_4.2_SPECS.md is
left as written, since it is the record of what was asked, and this entry is the
divergence. The reasoning is that the places are not a fact about the room, they
are the booking and waitlist capacity: lowering them strands confirmed bookings,
raising them offers seats to the waitlist, and both are the committee's call.
The band is a fact about the room, which the host is the one who knows, so they
can still correct it up to approval.

**Where the lock starts.** `law_events_locked_fields()` no longer returns an
empty list for every pre-approval status. `tickets_available` is locked for a
host at `law-proposed`, `law-sent-back` and everything after, and **exempt at
`law-draft`**, which is simply the create form reopened and where the field is
required as before. The committee is unlocked throughout pre-approval, as they
always were, and `venue_capacity` is untouched: still the host's through draft,
review and sent-back, still locked at approval.

**The save path is the dangerous half.** A disabled input posts nothing, so
`_law_tickets_available` is now written only when the field was not locked. The
guard matters more than the band's: writing the absent value would blank the
places, and a blank there takes the booking and waitlist capacity with it (the
same trap the 9 September venue-visibility rule already worked around, for the
same reason).

**The band ceiling is checked from whichever side is stored.** Places can never
exceed the band, and now either half of the pair can be the locked one. Post
approval the band is stored and the posted places are judged against it; from
submission onwards the *places* are stored and are judged against **the band
being posted**, because a host under review can still lower it. That refusal is
attached to `venue_capacity`, not to `tickets_available`, with its own wording
("101-150 is below the 120 places already released…"), because an error on a
field the submitter cannot reach is a dead end. The minimum-of-1 check only ever
runs on a posted value: a stored 0 is the committee's to fix and would otherwise
trap a host on their own form. Requiring the places (the 11 September rule) is
skipped when they are locked, exactly as the band already was.

**The form.** The number input renders disabled with a "(locked)" label and a
note saying the committee sets it once the event is submitted and to reply to
any email from us to change it — the other locked fields say "Locked after
approval", which would be wrong here, and the band gained that note now that the
two sit side by side with different reasons. `assets/js/event-form.js` no longer
clamps the places to a newly chosen band when the input is disabled: the field
posts nothing, so clamping only showed the host a number the event does not
have. Both fields also fall back to the stored value on an error re-render
(`$law_locked_value` in `parts/events/event-form-fields.php`), since
`law_events_form_values()` returns the posted input, which for a disabled
control is empty. (`venue_needed` used the same fallback until the question
came off the form on 17 September 2026.)

**Three host emails were wrong the moment this landed.** `host_capacity_warning`,
`host_event_full` and `host_waitlist_activated` all told hosts they could raise the
places themselves from the dashboard. They now say the committee sets the number
and to reply to the email, keeping the dashboard link for the bookings. A site
with a stored override for any of the three keeps its own text, so check
`law_events_email_overrides` before assuming the registry default is what sends.

**Not changed:** the committee's doors. The Committee controls panel on the
dashboard (band and places, at every status), the front-end committee edit form
and the wp-admin **Event facts** box all behave exactly as they did, and remain
the only ways the number moves. `tests/VenueDetailsTest.php` gained five cases
for the new rule and `tests/SubmissionFormLockTest.php`'s matrix now separates
`law-draft` from the two review statuses.

---

### "Register" and "ticket" replace "apply" and "application" on screen (15 September 2026)

**What the client asked for.** The client wants the words **"Ticket"** and
**"Register"** wherever the site had said "application" or "apply", the
flagship conference included. This reverses the 10 September decision recorded
in FLAGSHIP_PAYMENTS.md and EVENTS_4.2_SPECS.md §3.4, where "Register" was
reserved for free hosted events on the reasoning that an approval-gated place is
applied for rather than booked. The client's vocabulary wins; the reasoning
behind the old decision was never wrong about the mechanism, only about the
words, and the mechanism has not changed.

**The scheme, and why it is three words rather than two.** A flagship place is
still submitted, reviewed, then charged, so there is a real window in which the
delegate has asked for a place and may yet be declined. Calling that a "ticket"
would be a false statement in an email, and a declined delegate who was told
they had a ticket has a grievance. So:

- **Register** is the verb and every call to action. "Register to attend",
  "Register for %s", the button on the conference page, the button on the
  programme card, and the heading of the dialog and its no-JS inline twin.
- **Registration** is the noun for the record while it is undecided. "Your
  registration is being reviewed", "Registration #1042", "Search registrations",
  and the committee table's first column.
- **Ticket** is the confirmed, paid place and nothing else. "Your ticket is
  confirmed", "Your ticket is confirmed and £660.00 has been paid", the subject
  line of `user_flagship_approved` and `user_flagship_complimentary`, and
  "Ticket #1042" on the delegate's own view once the booking is `publish`.

`parts/events/flagship-manage-application.php` renders both a pending
registration and a confirmed ticket, so its reference line switches on
`post_status`: `Ticket #%d` at `publish`, `Registration #%d` otherwise.

**Two vocabularies, deliberately.** This was copy only. Every identifier keeps
the application vocabulary: the `law-applied` post status, `law_flagship_apply()`
and its `admin_post_law_flagship_apply` handler, the `?law_flagship_apply=1`
no-JS URL and the `law_flagship_apply[...]` field names, the `_law_application_at`
/ `_law_application_answers` / `_law_application_ready` meta keys,
`law_flagship_applications()`, `law_flagship_application_statuses()`,
`law_flagship_application_for_user()`, the `flagship-applied` notice key, the
`user_flagship_applied` and `committee_flagship_application` email slugs, the
`flagship_applied` log action, the `parts/events/flagship-apply-modal.php` and
`flagship-manage-application.php` filenames and the `.law-flagship-apply` /
`.law-cal-card__badge--applied` classes. They are stored on live bookings and
mirrored into Stripe metadata, so renaming them would buy a data migration for
nothing anybody can see. The file header of `functions/account-flagship.php`
states the split so a future reader does not "tidy" one into the other.
Nothing in the status vocabulary needed changing either: `law-applied` already
displays as a state ("Pending approval"), not as a noun.

**The "Apply" buttons that stayed.** Eight of them, all a different sense of the
word and all deliberately untouched: the filter-form submit on the programme
filters, the Manage bookings, Manage speakers, Discounts and Flagship bookings
dashboards; the discount-code Apply in the reception checkout dialog; and the
"Applies to" column and label in the discount catalogue. A ninth joined them on
15 September 2026: the confirm button on the Ticket type dialog, which the
client asked for in those words. So is "the price that
applied when they saved their payment details" on the flagship settings screen.

**Files touched.** `functions/account-flagship.php` (18 strings),
`functions/events/notifications.php` (all ten flagship email templates, their
Emails-screen names and triggers),
`functions/events/flagship-bookings.php` (27 handler messages and WP_Error
strings, plus 14 activity-log lines the committee reads on the booking),
`functions/events/flagship-bookings-dashboard.php` (the export's "Registration"
and "Registered" columns, its title and its `flagship-registrations-*`
filename), `functions/events/settings.php`,
`functions/events/admin/flagship-screen.php`, `functions/account-bookings.php`,
`functions/header-nav.php`, `parts/events/flagship-apply-modal.php`,
`flagship-manage-application.php`, `flagship-bookings-list.php`
(including "Applicant" → "Delegate"), `flagship-manage.php`,
`flagship-success-modal.php`, `flagship-card.php`, `booking-loading-modal.php`,
`reception-manage.php`, `templates/account-dashboard-flagship-bookings.php` and
`assets/js/booking-form.js`.

**Check the email overrides before assuming this shipped.** The registry is
code, but `law_events_email()` merges the `law_events_email_overrides` option
over it, so any flagship email somebody has pressed Save on from the Emails
screen keeps its stored subject and body and will still say "application" after
this deploy. The local database holds no overrides at all; staging and
production need checking. If one is found and its text was never actually
rewritten, `law_setup_retire_booking_received_emails()` in
`functions/setup-account-pages.php` is the precedent for dropping a stale key
from both the `?setup-account-pages` trigger and a migration step.

**Not changed.** The receptions keep "Your place at X is confirmed": the
client's ask was about the application vocabulary, and a pay-now reception place
was never applied for. "My bookings" keeps its name as the container for all
three kinds of place, so the delegate's ticket still lives under My bookings
rather than under a second, parallel noun. The receptions also kept "Book now"
on their button at the time; that part was reversed on 16 September 2026, below.

### Two buttons on every event card (15 September 2026)

An event card carried its booking button only when there was one to carry.
Everything else — places not released yet, invitation only, the event has been
and gone, an event the committee has not published — dropped to Event details
alone, and a row with one lonely button reads as a card that has forgotten its
button rather than as an event nobody can book yet. The single event view has
said so in its panel since the booking states were built
(`law_booking_render_action_body()`); the programme said nothing, so the only
way to find out was to open every event on the page (Denis, 15 September 2026).

**Every card now carries two buttons, the second one disabled with the reason
on it when there is nothing to press.** `law_booking_card_inert_action()`
(`functions/account-bookings.php`) maps the actionless states to their words —
`not-open` → "Open soon" (shortened from "Bookings open soon" later the same
day, see below), `closed` → "Bookings closed", `invitation` → "Invitation
only", and an event with no resolvable state at all (an unpublished one, which
only the committee's own programme lists) → "Bookings not open".
`law_flagship_card_inert_action()` (`functions/account-flagship.php`) is its
counterpart for the conference: "Open soon" as well, then "Registration closed"
and "Registration not open" before it is published. The two longer labels stay
in the conference's own vocabulary, because a place there is registered for
rather than booked; the shortened one is shared, because it names neither act.

Three details worth keeping:

- **It is a real disabled `<button>`, not a greyed link.** An anchor with an
  `href` is still followed on click and on Enter whatever `aria-disabled` says,
  and this control has nowhere to send anybody. `parts/loop/event.php` already
  drew the `disabled` action entry that way for an external event whose
  organiser has not opened registration; that branch is now the common path,
  and `law_booking_card_inert()` is the one place the entry is built (the
  external no-URL case calls it too). The dimming and the dead pointer come
  from `.law-cal .button[aria-disabled="true"]` in calendar.css, which already
  existed for exactly this.
- **Only when the row is a button short.** The fallback sits in
  `parts/loop/event.php` behind `count( $law_actions ) < 2`, so the callers
  that pass their own actions — My events (Edit, Comments, Withdraw…), My
  bookings (View event, Manage) — are untouched. A dead button beside four live
  ones is noise, not information. `law_booking_card_action()` and
  `law_flagship_card_action()` keep their existing contracts and still return
  null: what a caller's `'action'` scope filtered out is not an actionless
  state, and inventing a reason for it would be a lie.
- **Not on the legacy source.** `law_booking_card_inert_action()` returns null
  unless `law_events_source()` is `'cpt'`: the GF programme has no booking
  system at all, and a dead "Bookings…" button on every card there would be
  explaining a feature the page has not got. Legacy cards keep their single
  Event details button until cutover.

`parts/events/flagship-card.php` gained the same treatment, so the programme's
conference block is never a one-button block either; it renders the inert entry
as a disabled `<button>` and its live entry as the anchor it always was.
Pinned by `BookingCardActionTest::test_the_states_with_nothing_to_press_each_name_their_reason`,
`::test_the_card_partial_renders_the_second_button_disabled` (two buttons on the
row, and the second inert), `::test_the_legacy_source_gets_no_inert_button` and
`FlagshipCardActionTest::test_the_conference_names_its_reason_when_there_is_nothing_to_press`.

### The dashboard keyword box learns about firms (15 September 2026)

A client comment on staging — "Can't Search by firm name. Can we change that?"
(Emily O'Callaghan) — turned out to be exactly right, and to reproduce on the
first try. The Events dashboard's keyword box was a plain WordPress core
search, so it read the event post's title, excerpt and content and never
touched `_law_host_organisations`, where the firm actually lives. Typing
"Mayer Brown" returned nothing while two of its events sat a few rows down the
same list. The Host column showed the submitting person's `display_name` and no
firm, so the failure was doubly invisible: nothing matched, and nothing on
screen named a firm to suggest what had gone wrong.

Three things changed together, because any one of them alone would have left
the surface dishonest:

1. The keyword now resolves to an explicit ID set across core search, the host
   organisation text and the linked organisation records
   (`law_committee_keyword_event_ids()`, see `committee.php` above for the two
   `WP_Query` traps that decide its shape).
2. The Host column prints the firm under the person, so a firm search returns
   rows that visibly contain the firm.
3. The exports gained the "Host organisation(s)" column they had never had.

The timeline view and all three export formats inherited the search for free:
`slot-chart.php` and `export.php` both go through `law_committee_events()`.

**The limitation worth remembering.** `_law_host_organisations` is free text a
host types, and the live data is already inconsistent — "36 Stone," and
"36 Stone" are two separate events, and "Evershed Sutherland" appears where
"Eversheds Sutherland" was meant. A substring search copes with most of that
("Evershed" finds both spellings) but it cannot merge them, and there is no
trustworthy "all events by this firm" count to be had from the field. Making
that reliable would mean linking each event to an `organisation` post at
submission, which is a larger, separate piece of work. Do not describe this
search to the committee as exact.


### ...and then about host names (16 September 2026)

The same box, the same complaint one step further in: searching "Emma" on
`/account/dashboard/` returned nothing although three rows on the list printed
"Emma Higgins" in the Host column (Denis, 16 September 2026). The firm fix the
day before had added the organisation limbs but left the person out on the
explicit ground that the scope was the firm; the person is the other thing the
column prints, so the box is now honest about both.

The limb is described under `committee.php` above. The two decisions worth
carrying forward: the candidate set is the CPT's authors rather than the user
table (hosts are a tiny subset of the accounts, and searching users would need
a row cap to be affordable, which would drop matches without saying so), and
every word of the keyword has to answer, so a two-word name narrows.

The timeline view and the three exports inherited it for free again, both
going through `law_committee_events()`. Covered by six new tests in
`tests/CommitteeSearchTest.php` (18).


### The committee's dashboard marks its search hits too (16 September 2026)

The programme's cards had learned that morning to show *why* a row survived the
filter; the committee's Events dashboard was asked for the same thing hours
later (Denis). Same helper, same `mark.law-hit`, no second implementation: the
table marks the **event title, the host's name and the firm** — the three
printed fields its keyword box actually searches — and the timeline view marks
the **bar titles**, which is the one searched field a bar prints.

**The word-by-word detour, and why it was reversed within the hour.** This box's
own search splits on whitespace (core `s` splits its terms, and the host-name
limb requires every word in any order), so the first build marked the phrase
*and each of its words*, on the reasoning that a row matched word by word would
otherwise explain nothing. In use that was noise, not explanation: the committee
searches with long phrases lifted straight off a title, and
"Collaboration with Arbitral Institutions in" came back with every "in", every
"with" and every "in" *inside* a word marked down two columns (Denis, with a
screenshot). The rule is now the same as everywhere else — the whole phrase,
case-insensitively, one contiguous run — and `law_calendar_highlight()` went
back to a single needle rather than keeping an array form nothing calls.

The price is explicit and tested: a row the search matched word by word, or on
a field the table does not print (the description, a linked organisation
record), shows with **nothing marked**. That is the same accepted limitation the
programme carries for a card matched on its description, and the lesson from the
reversal is that an unmarked row is cheaper than a stippled one.

Unlike the programme's cards, this part **reads `?law_kw=` itself**. The
argument against that on `parts/loop/event.php` was that four unrelated surfaces
render it and `law_kw` means something different on each; `dashboard-list.php`
is rendered by the committee dashboard and its `&law_partial=1` endpoint and
nothing else, so the query var is unambiguous there. It still accepts a
`highlight` arg for a caller that wants to override it.

`tests/CommitteeHighlightTest.php` (8) covers the exact-phrase rule, the
reversal itself, the three marked columns and the accepted silence.


### A search-result snippet for a hit in the description (16 September 2026)

The third piece of the same afternoon's work, and the one that closes the hole
the other two left open. Both keyword boxes search the **description**; neither
the programme card nor the committee's table prints a word of it. So a search
for a term that lives only in the body text — which on this site is most of
them, the descriptions being where the subject matter actually is — returned a
screen of rows naming nothing the searcher had typed, and the new highlight had
nothing to mark. Denis asked for the standard answer: show the slice of the
description the hit is in, cropped sensibly, with the word marked.

`law_calendar_search_snippet()` (`functions/calendar.php`) does it, and the
shape of the crop is the whole of the design:

- **~170 characters, ~55 of them before the hit.** About two lines on a card,
  and the length search results have converged on; the lead-in matters because a
  snippet that starts *at* the keyword gives it no context to be read in. The
  length is a **floor**: a keyword longer than what is left after the lead-in
  widens the window, because a crop that lands inside the phrase leaves
  `law_calendar_highlight()` with nothing to match and the reader with an
  unmarked snippet. That shipped for an hour and Denis caught it with a
  62-character search that highlighted on the programme and not on the table.
- **The committee's table prints it on a row of its own**, spanning every
  column, at 260 characters. Inside the Event cell it was prose in a 12rem
  column, which is five lines of two words (Denis, 16 September 2026). An event
  is therefore two `<tr>`s when it has a snippet, which is why that table now
  counts its own stripe: `is-alt` on both rows of a pair, Foundation's
  `nth-child(even)` disarmed under a `--striped` modifier so the eight other
  tables on the base class keep theirs.
- **Both edges move to a word boundary**, and neither is allowed to move across
  the hit itself: the start only travels forward while it stays left of the
  match, the end only back while it stays right of it. A long phrase gets a
  window big enough to hold it, so a search for six words is never cut off
  inside its own `<mark>`.
- **An ellipsis marks each edge that is not the real start or end.** A hit in
  the first sentence therefore has no leading ellipsis, and a description
  shorter than the window is printed whole with none at all — the reader can
  tell a crop from a complete description.
- **`''` when the keyword is not in the description**, which is what makes this
  an explanation rather than a truncated description on every card. The caller
  prints nothing on an empty return, so a card matched on its title grows no
  line. Pinned by a test.
- The description is **flattened through `law_rich_text_plain()`** before
  anything is counted: it is rich text, and `wp_strip_all_tags()` alone would
  run a bulleted list into a single word. Marking goes through
  `law_calendar_highlight()`, so the snippet obeys the same exact-phrase rule as
  every other surface and markup in a description cannot reach the page.

Styling: `.law-event-card__snippet` is a block under the card's meta line
(the meta lines are `display: inline` and joined with " · ", so prose spliced
into that chain would read as one more fact), quieter than the meta at #666,
and white on the flagship's navy card where #666 would be 2.2:1. On the table,
`.law-dashboard__snippet-cell` lifts the 12rem column cap (the point of the
full-width row) and the event row above drops its bottom border, so the pair is
enclosed by one line rather than divided by one.

**Not on the flagship block** (`parts/events/flagship-card.php`), which prints a
session list rather than prose and would need its own layout decision;
`.law-event-card--flagship` — the conference as an ordinary loop card — does get
it. `tests/SearchSnippetTest.php` (17).


### The venue capacity band gained a floor (15 September 2026)

Places available had to sit **inside** the chosen venue capacity band, not
merely under its ceiling (Denis): with the band set to "51-100", places of 50
and of 101 are both refused. Only the ceiling had ever been checked, so a
"101-150" room releasing 20 places saved without complaint.

**The floors are derived, not restated.**
`law_events_venue_capacity_band_floor()` (settings.php, beside the band list)
reads `law_events_venue_capacity_bands()` and returns the previous band's
ceiling plus one, so the two halves of a band cannot drift: 1, 51, 101, 151,
251, and no floor for "TBC", which follows an uncapped band. **The band list
must therefore stay in ascending order**, which its doc block now says. Note
that **"251+" is bounded below (251)** where it used to constrain nothing at
all — it is uncapped above, not unbounded — and "TBC" is now the only band that
bounds nothing, which is the point of it.

**The rule moved into one function.** It had been implemented twice, in
`law_committee_venue_input_error()` and inline in `law_events_form_save()`, and
only one of them would have grown the floor.
**`law_events_venue_pair_error( $band, $places, $check_floor )`** now holds it.
It names which **half** of the pair is at fault (`'band'` or `'places'`) rather
than a form field, because the two callers name their fields differently and the
event form has the further job of deciding which half the submitter can reach.
`law_committee_venue_input_error()` survives as a thin wrapper returning only
the message: the panel shows one message and has no field to key an error to.

**The two bounds are not symmetrical, and this is the important part.** The
ceiling is checked whichever half was posted, because releasing more places than
the room holds oversells the event. The floor is checked **only when the places
are the half being set**, which is what `$check_floor` is for. A band merely
bigger than the places released harms nothing, and enforcing it both ways would
have destroyed the 14 September rule directly above: a host under review may
still correct the room's size, and the common correction is *upwards* — they
moved somewhere bigger. Judging a posted "151-250" against the committee's
stored 120 places would refuse exactly that.
`test_the_floor_never_blocks_a_host_moving_to_a_bigger_room()` is that case, and
`test_a_host_under_review_may_still_change_the_band()` was the passing test the
naive version broke.

**A pair nobody can reach is no longer judged at all.** Both halves are locked
for a host from approval, so they post neither and the check ran
stored-against-stored, refusing **every unrelated edit** — description,
speakers, agenda, contacts — with the message on a disabled control. That was
already live for the ceiling: any approved event whose stored pair breaks it
locked its host out of their own form entirely, and 14 migrated events are in
that shape. `law_events_form_save()` now skips the pair check when the submitter
can move neither half. This is not grandfathering, which Denis ruled out: the
committee, who can always move both, is still refused on any save, through the
panel and the shared form alike.

**The form learned the band check the panel already had.** An unrecognised band
posted to `law_events_form_save()` used to be stored and then read as "no
ceiling" everywhere, quietly uncapping the allocation; the panel has refused one
since 9 September. The form now refuses it too, on `venue_capacity`.

**The script stopped rewriting the number.** `assets/js/event-form.js` silently
clamped an over-band value down to the ceiling, so a host who typed 900 watched
it become 50 with no explanation — and a clamp means the server rule can only
ever fire on a tampered post. It now leaves the value alone and renders an
inline `.law-form-error` beside the field, built the way `photoError()` builds
its own. It **never writes to the field**: on the committee panel, quietly
*raising* the places would run `law_event_tickets_changed()` on save, which
offers the new places to the waitlist and emails people.

The `min`/`max` attributes are maintained only where a stale value cannot do
harm, which is what the new **`data-law-strict`** attribute marks. The host
form opts in: the field is the one being edited, and a locked one is `disabled`
and so exempt from constraint validation. **The committee panel deliberately
opts out**, because its Approve, Send back, Reject, Mark paid, Cancel and
Delete buttons all submit the same `<form>`, so an attribute the stored value
violates would block every one of them behind a validation bubble.

**Two things this entry does not fix, both raised with Denis and deferred.**

- **The panel is already blocked on a breaching event.** The PHP-rendered `max`
  on `law-dash-places` makes the whole controls form invalid when the stored
  places exceed the stored band, and none of the action buttons carry
  `formnovalidate`. On the 14 above-ceiling events the committee cannot
  approve, send back, reject, mark paid, cancel **or delete** from the
  dashboard. `law_venue_present` also rides along on every AJAX action
  (`committee-actions.js` posts the whole form), so the server refuses those
  actions too, and the message surfaces inside whichever modal is open. A
  capacity rule should not be able to block a delete. Denis chose to raise this
  separately rather than widen the change; this entry is the record of it.
  **Fixed on 16 September 2026** — see "The band a committee member could not
  widen" at the end of this document.
- **"Blank means no limit" is wrong wherever it is written.**
  `law_event_tickets_remaining()` (bookings.php) returns `null` for 0 or unset
  and its own doc block says so plainly — the event is **not open for booking**,
  and "neither can mean unlimited". `law_booking_guard_seats()` then refuses
  with "Booking for this event has not opened yet." So a committee member who
  clears Places available expecting to lift the cap silently closes bookings.
  The panel's help text and the refusal copy were corrected here, and so were
  the two earlier passages in this document that repeated the claim — one of
  which cited `law_events_bookings_remaining()`, **a function that does not
  exist**. The behaviour itself is untouched: whether a cleared Places field
  *should* mean "no limit" or "bookings closed" is a product question nobody has
  put to Denis.

**17 live events breached a bound when this was written** — 14 above the
ceiling, 3 below a floor, migrated data that never passed through the current
check. Several below-floor ones look deliberate rather than wrong ("FTI
Consulting London Arbitration Week Quiz", 101-150 with 20 places), which is the
case against a floor: Places available is the booking capacity, not a fact about
the room. Denis chose the hard rule anyway. Each is fixable in one save from the
committee panel, where both halves post together.

### "Open soon", and the panel that had no button (15 September 2026)

Two follow-ups to the two-button cards above, from the same day.

**The label is now "Open soon".** `law_booking_card_inert_action()` said
"Bookings open soon" and `law_flagship_card_inert_action()` said "Registration
opens soon"; both now say "Open soon" (Denis, 15 September 2026). A button is a
label, not a sentence, and the card's title and date have already said what it
is that opens soon. Sharing the shortened words across the hosted cards and the
conference block does not undo the vocabulary decision recorded above: "Open
soon" names neither booking nor registering, so the objection it answered (two
cards on one programme naming the same wait two ways) is answered rather than
reintroduced. The conference's other two labels, and the sentence its own page
prints ("Registration opens soon. Registration for this conference has not
opened yet."), are unchanged — there is room for the vocabulary where there is
room for a sentence.

Deliberately **not** changed: the external events' "Registration opening soon",
which is a different fact (a third party takes the booking and has not opened
it yet), is specified in EVENTS_4.2_SPECS.md ("External events") and is quoted
back at the committee in the Manage external events help text. Shortening it
would mean editing the spec and that copy too.

**The not-open panel gained the button it had been describing.** The single
event view printed "Bookings open soon" as a heading and handed
`law_booking_panel()` an empty right-hand slot, so the one state an attendee
meets before anybody has opened bookings was also the only panel with nothing
in its action slot — the opposite of the card, which had carried a disabled
button for that state since the morning. It now returns
`law_booking_inert_button( 'Open soon' )`, and the heading is gone rather than
kept above it: "Bookings open soon" over a button reading "Open soon" is the
same sentence twice. The words are the explanation alone, split across the
panel's two paragraph styles the way every other state splits its own —
"Places for this event have not been released yet." / "Check back nearer the
date." The colleagues-only state still gets its Manage bookings button first,
so that row reads Manage bookings + Open soon.

**One helper, four call sites.** The disabled-button markup existed in four
places (the hosted preview opener, the flagship preview opener, the external
event with no URL, and now this panel), each with its own copy of the reasoning
for why it must be a `<button>` and not an `<a aria-disabled>`. It is now
`law_booking_inert_button()` in `functions/account-bookings.php`, which all four
call. `law_booking_card_inert()` stays separate: it builds an actions *entry*
for `parts/loop/event.php`, not markup.

Pinned by `BookingCardActionTest::test_the_not_open_panel_carries_the_disabled_open_soon_button`
(the words, the real disabled `<button>` in the action slot, and that the state
is not said twice), alongside the card assertions listed above, which now expect
the shortened labels.

### Moving the client's receptions and flagship from staging to production (15 September 2026)

The client entered the real reception and flagship details on staging. There
was no way to get them onto production except by retyping them, and the
flagship's session agenda — sessions, times, descriptions and per-appearance
speaker rows with photographs and biographies — is far too long to retype
safely. So the Migration screen gained a **Content transfer** panel: export
this site's receptions, flagship and discount codes as one file, upload it on
the other site, preview every change it would make, then apply.

Worth recording that the receptions alone did NOT justify this. They are about
thirty fields and would have taken ten minutes by hand, with no risk at all.
The agenda and the discount catalogue are what earned the tool, and that is the
test to apply if anyone proposes widening it.

The decisions behind the shape, all taken the same day: scope is the
receptions, the flagship with its sessions and speakers, the discount codes
and the customised email wording, with external events left out (reversed the
next day — see the widening below); and the flow is always dry run first,
with apply overwriting the matched records. Bookings and payments are never in
the bundle and never will be.

**And one reversal within hours of shipping.** Images were first fetched from
the source site's URLs at import rather than packed into the file; then the
production → staging database pull was planned and LAW staging turned out to
sit behind HTTP basic auth, which makes that fetch return 401 for every image —
silently, because a failed fetch is warn-and-continue by design. So format
version 2 made the bundle a zip carrying the bytes. The URL still travels as the
identity key, so nothing about re-import idempotency changed, and a version 1
`.json` bundle still imports by the old route.

The full reasoning, the format, and the traps (pence versus pounds, slugs versus
IDs, derived values, the media allowlist, the zip's own path-traversal and
decompression-bomb gates) are written up under `migration/content-transfer.php`
in §2.

**And a second widening the next day, which superseded the scope above.** The
client had gone on editing the programme itself on staging — venues,
descriptions, speakers, running orders, the new Override booking availability
switch, and a set of external listings created from scratch — so format version 3
added an `events` key carrying every hosted and external event. Three decisions
came with it and are the ones to carry forward: the import is an **overlay**
(it runs after the Gravity Forms migration, updates and creates, never deletes,
and never touches an event the file does not name); a **hosted event's workflow
status does not travel**, because approving is an act that raises a Stripe
invoice and emails the host rather than a value a file can set, though an
external event's on-the-programme tick does; and an import **never creates a
user account**, so people travel as email addresses and an unresolvable one is
reported rather than invented. Which also settles the test in the paragraph
above: the receptions alone did not justify the tool, and neither would a
handful of external listings — a hundred hosted events with their agendas is
what earned this second round.

### Ticket type on the flagship bookings dashboard (15 September 2026)

The client asked for one thing, in one sentence: "Can we add in a ticket type
to this page? Back end use only — Delegate, Sponsor, Speaker, Exhibitor,
Committee." Nothing in the theme held any such idea; a grep of `.php`, `.js`,
`.css` and `.md` for `ticket_type` found only boilerplate in
`templates/privacy.php`.

So it is a label the committee keeps for themselves. It is stored as
`_law_ticket_type` on the booking, it appears in the table and in all three
exports, it can be filtered on, and it is set from a dialog behind an inline
pencil. It touches nothing else: no price, capacity, status, email or guard
reads it, and the delegate is never shown it. That "nothing else" is what makes
the rest of the decisions defensible, so it is stated first.

**The shape, and the three things worth knowing.**

*One dialog, not one per row.* Approve and Decline each render their own modal
inside their own row form, because each one says something different about a
different person and a different sum of money. Ticket type says the same thing
about everybody, so the row is a hidden field and one dialog serves the table.
That is not only tidier: Approve and Decline render only on rows a decision can
still be made on, whereas a ticket type can be set on ANY row, so copying their
pattern would have put a dialog on every row of a list that runs to hundreds.
The dialog lives outside `#law-cal-events`, beside the add-attendee one, because
a filter change replaces that container wholesale.

*The edit does not reload the page.* This is the first action on any committee
dashboard that does not. The handler answers with the cell's own markup under a
new generic `cell` key and booking-form.js swaps that node, closes the dialog
and hands focus to the replacement. Classifying twenty delegates is twenty
presses rather than twenty page loads, which is the whole point of the request.
It follows the thread-bubble pattern from `comments.php` — the server renders
the markup, the script swaps the node — rather than the waitlist reorder, which
rebuilds state in JavaScript and is the hardest code in that file to keep right.
`law_flagship_ticket_type_cell()` is the ONE renderer, called by the table and
by the response, so the cell drawn on load and the cell drawn after an edit are
the same markup from the same place. A cell no longer on the page falls through
to the ordinary reload, the same bail-out `applyWaitlistOrder()` takes.

*It is the module's first `save_post_law_booking` handler.* The wp-admin
booking screen has been read-only on purpose since v1, and the theme had no
booking save handler at all, because every mutation is meant to run through the
front-end engine so the guards, the recount and the emails always fire. The
client asked for an admin control as well, and one is safe here for the same
reason the rest of this is small: nothing in the engine reads the value. The
box is registered only on a flagship booking, saves behind `edit_law_events`
(not `manage_options`, which would lock out the committee the field is for),
and writes through the same `law_flagship_set_ticket_type()` the dashboard
uses, so both routes validate identically and log the same line. The file
header now says so, because the next reader will otherwise find the exception
before they find the reason for it.

**Reuse rather than a second copy.** `parts/layout/modal.php` gained an
optional `'type' => 'select'` field instead of a hand-written dialog skeleton:
same name, same ships-disabled behaviour, same `data-law-modal-field` hook, and
`law-modal.js` needed no change at all. Two things it did need, both found on
first sight of the rendered dialog: a select is not just a textarea to repaint,
because Foundation draws its own caret as a background image with
`background-origin: content-box`, so recolouring the background alone left a
clipped arrow jammed against the right edge — `law-modal.css` now drops the
native appearance and supplies the chevron the way
`.law-cal-filter-form select` already does; and the field gained an optional
`label_hidden`, because a dialog whose heading reads "Ticket type for Ada
Lovelace" does not need "Ticket type" again three lines lower. The label is
still there as the control's accessible name, just not drawn. The cell itself
opts out of the shared 12rem wrap the same way the actions column does: "Add
type" was breaking across two lines and pushing the pencil away from the words
it belongs to. The delegate's name reaches the heading
through a printf template on the form (`data-law-ticket-title`) rather than the
dialog's `copy`, which is passed through `wp_kses_post()` and would strip a
`data-*` hook out of it — so the wording stays in PHP and no sentence is
assembled in JavaScript. `law_booking_ticket_types()` in `statuses.php` is the
single vocabulary, read by the cell, the dialog, the filter, the exports, the
wp-admin box and the meta sanitiser.

**No default, and no de-emphasis.** An unclassified registration holds nothing
and the cell reads "Add type". Defaulting to Delegate would make the column
look complete when nobody had actually classified anyone, and greying the empty
state would read as "this row matters less" rather than "this is still to do",
so "Add type" is the same colour and weight as a set type.

**Files.** `functions/events/statuses.php` (the vocabulary),
`meta.php` (the key and its sanitiser), `flagship-bookings.php` (the model
function, the handler and the query filter), `flagship-bookings-dashboard.php`
(the row keys, the filter, the export column, the cell renderer, the enqueue),
`admin/booking-screen.php`, `helpers.php` (a `pencil` icon),
`parts/layout/modal.php`, `parts/events/flagship-bookings-list.php`, new
`parts/events/flagship-ticket-type.php`,
`templates/account-dashboard-flagship-bookings.php`, new
`assets/js/flagship-ticket-type.js`, `assets/js/booking-form.js`,
`assets/css/law-modal.css` (the select on a white dialog) and
`assets/css/event-form.css`. Seven new tests in
`tests/FlagshipBookingsDashboardTest.php`, 20 in that file now, and the
suite green.

**Two documentation errors corrected in the same pass**, both found while
reading and neither caused by this change: the flagship dashboard section
claimed its exports run "through `functions/events/export.php`" when only the
CSV and XLSX writers do, and the events-dashboard export column list still
named a "Run by LAW" column that the external-events work replaced.

**One pre-existing test flake, left alone and recorded here.**
`BookingsDashboardTest::test_keyword_matches_email_name_and_booking_number`
fails about three runs in four. It searches for `#N`, the needle is stripped to
the bare digits, and the fixture's `unique_email()` builds addresses from
`wp_generate_password( 8, false )`, which frequently contains that digit — so
an unrelated booking matches and the per-row assertion fails. It is random, not
order-dependent, and nothing here touches that path. The fix is to assert the
target row is among the results rather than that every result matches, but that
is somebody's decision to take, not a silent edit inside this change.

### "Register" on a reception too (16 September 2026)

A reception's action button read "Book now" while every other event on the site
read "Register". Denis asked for the one word everywhere: "for their action
buttons we use wording 'Book now'. Instead it should be 'Register' as all other
buttons."

This reverses EVENTS_4.2_SPECS.md §3.4, which chose "Book now" deliberately so
the control would say that money was about to change hands. That job now falls
to what sits beside the button, which is where a price belongs anyway: the
programme card carries "Price: £45.00 + VAT" next to the control, the event's
availability panel carries the same line, and the dialog the button opens is a
priced checkout with the total, the VAT and a terms tick before anything is
charged. Nobody reaches Stripe without reading a price first, so the button does
not have to carry it.

**Two label sites, both in `functions/account-bookings.php`**, and nothing else.
`law_booking_card_action()` (the second button on every event loop card) and
`law_booking_render_opener()` (the control in the event's own details box) each
branched on `$reception` for their wording; both branches are gone and the
label is now `Join waitlist` or `Register`, the same expression the free events
already used. The card's screen-reader name changed with it, from "Book a place
at X" to "Register for X" — a visible label and an accessible name that
disagree is a WCAG 2.5.3 (Label in Name) failure and would break voice control,
so the two had to move together.

**What did NOT change is the part that matters.** `$reception` is still read in
both functions, because it picks the query var through
`law_booking_opener_param()`: a reception's control still points at
`?law_reception_checkout=1` (or `?law_reception_waitlist=1`), never at
`?law_book=1`. The word on the button and the dialog behind it were always
separate decisions; only the word moved. "Join waitlist" was already shared by
both kinds of event and is untouched, as are "Continue to payment" on a hold
awaiting payment, "Manage booking", and the reception confirmation copy.

`BookingCardActionTest::test_a_priced_reception_offers_book_now` is now
`…_offers_register` and asserts the new label, keeping its assertion that the
URL still carries `law_reception_checkout=1` — that pairing is the whole point
of the test. The related suites (Reception*, Booking*, Discounts, Quote,
AccountHub, Flagship*) pass, 398 tests.

### The migrated "Venue needed" answer read as no answer at all (16 September 2026)

Every event the migration brought across opened its form with **neither Venue
needed radio picked**, on staging and locally alike, and the answer had to be
given again before the form would save. New events were fine. The cause is a
Gravity Forms detail: field 103 (Venue needed) on form 2 (Event > submit an
event) is a radio whose choice **texts** are the two sentences but whose choice
**values** are the bare `Yes` and `No`, and an entry stores the value. Migration
step 1 copied `rgar( $entry, '103' )` straight into `_law_venue_needed`, so 99
of the 100 migrated events held `Yes` or `No` where the custom form's radios
carry the sentences, and `checked()` matched nothing. The same mismatch had a
quieter second effect: `law_events_venue_details_visible()` tests the answer for
a `"No,"` prefix, which `No` fails, so **every migrated host who already had a
venue was treated as having asked LAW to find one** — the committee's form
showed them the "The host asked LAW to find a venue" hint, and the host's own
form hid the venue name, band and places they had filled in themselves.

`law_migration_normalise_slot_label()` is the exact precedent: field 77
(Preferred date & time slots) needed the same treatment for the same reason.

**The fix is in three places, deliberately.** `law_events_venue_needed_label()`
(submission-form.php, beside the two venue predicates) maps an answer in either
vocabulary onto the canonical label, and returns `''` for anything that is
neither — a two-choice radio has no third answer, so a forged post no longer
lands in the meta as free text. `law_events_venue_needed_choices()` beside it is
now the single source of the two strings, which the form template, the wp-admin
select and the predicates all read rather than repeating the literals.

1. **On the way in.** `_law_venue_needed` changed sanitiser from `text` to a new
   `venue_needed` case in `law_events_sanitize_value()`. Because the key is
   registered through `register_post_meta()` with that sanitiser, even a plain
   `update_post_meta()` now normalises, so a legacy value cannot be stored again
   by any path — migration included, which is why the runner needed only a
   comment.
2. **On the way out.** The form template, `law_events_form_values()`,
   `law_events_venue_needed_value()`, both venue predicates, the dashboard
   panel's "Venue needed?" row and the wp-admin select all read through the
   label function, so **an unrepaired database behaves correctly on deploy**.
3. **The stored rows.** `law_setup_normalise_venue_needed()` rewrites each row
   that is not already canonical, following the
   `law_setup_retire_booking_received_emails()` pattern: idempotent, and run
   from **both** the `?setup-account-pages` trigger and migration step 10, so a
   git push plus the usual trigger is enough on staging. It is not logged per
   event — it corrects how an answer was recorded, it does not change anyone's
   answer.

`VenueDetailsTest` gained two cases, one for the migrated answer end to end
(raw `$wpdb` write, because the sanitiser now blocks every other route to a
legacy row) and one for the closed vocabulary. 35 tests there, 845 in the full
suite.

### Auditing the rest of the migrated data (16 September 2026)

The Venue needed bug above was the visible one, so every field the migration
maps was then checked the same way: the Gravity Forms definitions of forms 1,
2, 4, 6, 8, 9 and 10 against the vocabulary the custom code expects, and both
against the values actually in the database. Three more findings, and a list of
things that turned out to be right so they are not re-audited.

**1. No event could be stored as `free`.** `_law_payment_status` is declared
twice in the meta schema, once on the event (`payment_status`:
unpaid/paid/refunded/free) and once on the booking (`booking_payment_status`:
the longer pending_setup/ready/processing list). `law_events_all_meta_schemas()`
merges the five schemas with `array_merge()`, so the BOOKING vocabulary won for
both post types, and `law_event_update_meta()` read the merged map. Writing an
event's `free` sanitised it to `pending_setup`, which is not an event state, and
the per-post-type callback registered by `law_events_register_meta()` then read
THAT as unknown and stored `unpaid`. `unpaid` survived the same round trip by
accident (`unpaid` → `pending_setup` → `unpaid`), which is why only the free
events were damaged and why the defect went unexplained for so long.

`law_migration_derive_payment()` calls a Confirmed £0 event `free` and always
has, so **this is the root cause of the symptom
`migration/repair-payment-status.php` was written to mop up** — that file
previously recorded the cause as unestablished. It was not a historical
accident: every migration run reproduced it, cutover included, which would have
made that panel a permanent chore rather than a one-off. Locally, 36 published
zero-fee events read Unpaid and not one `free` row existed anywhere.

The fix is `law_events_meta_type( $post_id, $key )`, which resolves the
sanitiser from **the post's own post type** and falls back to the merged map
only for a post that is none of the five types (or no longer exists, where a key
has one possible meaning anyway). `law_event_update_meta()` and
`law_event_meta()` both go through it, and `law_events_post_type_meta_schemas()`
is now the single list that `register_post_meta()` reads too, so the registered
sanitiser and the helper's cannot drift. `_law_payment_status` is the only key
in the whole schema whose two declarations disagree; the other seven shared keys
(`_law_vat`, `_law_speakers`, `_law_gf_entry_id` and the Stripe trio) name the
same sanitiser on both types and were never at risk.

**2. The post-approval fee lock was off across the whole migrated programme.**
`law_event_fee_override_locked()` tested `'' !== _law_approved_at`. Form 2
(Event > submit an event) field 78 (Approval date) is **empty on all 500
production entries**, so the key is absent on every event the migration created:
90 approved and Confirmed events locally, every one of them with the fee
override control still editable on the committee dashboard, in wp-admin and on
the account dashboard. That control is meant to go read-only at approval because
the fee is snapshotted once and nothing recalculates it: a later change moves the
dashboard and the exports while the snapshot, the raised invoice and the `{fee}`
emails keep the old figure.

There is no source date to backfill, so the lock stopped keying on a timestamp
at all. `law_event_has_been_approved()` (statuses.php) reads the STATUS:
`law-approved` and `publish` say it themselves. `law-cancelled` is the one
status that cannot, because `law_event_transitions()` reaches it from both
`cancel` (from approved or Confirmed) and `withdraw` (from draft, proposed or
sent back); the timestamp separates those two and is reliable there, since a
cancellation can only have happened on this site after the workflow started
writing the key, and migration never produces a cancelled event at all. No data
repair is needed, and `_law_approved_at` stays as the displayed approval date.

**3. Half the migrated events held an ISO code as their billing country.**
Field 74 (Address) input 74.6 (Country) was filled in by two different front
ends over the form's life and the entries show both: "GB" on 231 and "United
Kingdom" on 198, with the same split through China, Portugal and Singapore. The
custom form's Country select offers names, so those events showed an option
reading "GB" (the form appends an unrecognised stored value as its own option,
which is what stopped it being lost). `law_events_country_display_name()` maps a
bare code onto the name the list offers, built by reversing
`law_events_country_map()` through `law_registration_country_choices()` so the
two can never disagree; nothing is ever dropped, because a billing address is
the host's own words. It runs in the `address` meta sanitiser (so the migration
is fixed by writing through `law_event_update_meta()`), on the three surfaces
that render the country, and as
`law_setup_normalise_invoice_countries()` on the `?setup-account-pages` trigger
and migration step 10. Stripe was never affected: it is sent `_law_country_iso`,
a separate key that has always held a clean alpha-2 code, and
`law_events_country_to_iso()` accepts a bare code as well as a name, so
re-saving one of these events could not have blanked it.

**Checked and clean**, recorded so nobody audits them twice: sector terms (the
`&amp;` in the term names is standard WordPress, `esc_html()` does not
double-encode an existing entity, and both sides of the checkbox read term
names, so a migrated sector ticks); slot labels (the en dash / hyphen split
between fields 68 and 77 is already normalised, and retired slots stay
selectable on an event that holds one); accessibility, dietary and country user
meta (the stored-value-versus-displayed-label mapping is explicit in
`law_registration_accessibility_choices()` and every stored value is a valid
choice); session times (24-hour in the entries, so the `time` sanitiser accepts
them); speaker roles through `law_speaker_role_key()`; the event status and
payment status label maps; the venue capacity bands (the `array_key_exists`
versus `isset` trap for the two null-ceiling bands is already avoided, with a
comment saying so); the fee fields (84 is pence, 81 is pounds, and the one
negative in the data is clamped to 0); and the organisation links, which all
resolve to real `organisation` posts.

One documentation correction: the comment in
`law_migration_populate_external_event()` says all four form 10 (Event >
external events) entries left the corresponding form 2 fields empty. Field 103
(Venue needed) reads "No" on all four. Nothing follows from it, because an
external event has its own edit form with no venue block, but the comment should
not be relied on.

The payment repair panel's scan gained the same predicate while it was open.
Its docblock says every qualifying event is Confirmed, which was true when it
was written; on the current data six **Proposed** sponsor events also had a £0
fee and an Unpaid status, and calling those Free would answer a question the
committee has not reached. The fee is snapshotted at approval, and the approve
transition already sets Free itself when that snapshot comes out at zero, so the
scan now requires `law_event_has_been_approved()` too: 30 events rather than 36.

**Is Free safe now that events can hold it?** Traced end to end afterwards,
because the fix means 30 Confirmed events change state when the repair runs and
every future zero-fee approval keeps a value it previously lost. **An event's
payment status gates nothing.** The payment gate is the POST STATUS:
`law_booking_guard_open()` refuses anything that is not `publish` (with the
committee's "Enable booking" override as the one documented lift), and
`law_event_booking_hold_reason()` names only the three holds (booking disabled,
no places, no venue), none of which reads payment. Approving a zero-fee event
sets Free, raises no invoice, and calls `confirm` in the same breath, so a free
event is published and bookable immediately. Nothing anywhere queries by
`_law_payment_status`: every other reader is display (the dashboard table and
event panel, the export column, the wp-admin column, the `{payment_status}`
merge tag, and the GF-shaped field 96 map, whose legacy vocabulary is the same
four words capitalised). The two behavioural readers both do the right thing
with Free: `law_event_resnapshot_fee()` refuses only `paid` and `refunded`, so a
free fee stays correctable, and the invoice retry handler requires
`law-approved` AND `unpaid`, so a free event offers no retry of an invoice that
was never raised. Cancelling one voids nothing and sends no "cancelled paid"
alert.

The one thing that was wrong for free events was **the cancel dialog's copy**,
and it had been wrong since the dialog was written. Its third line had two
branches, unpaid-with-a-fee and everything else, so a zero-fee event was told
"a fee that has already been paid is never refunded automatically: the committee
is alerted to review the payment in Stripe", which is false twice over. It now
has three branches, and a zero-fee event reads "No host fee was ever due on this
event, so there is no invoice to cancel and nothing to refund."

`MetaSchemaTest` is new (the per-post-type sanitiser, both vocabularies, the
lookup edges, and the country mapping and its repair), `FeesTest` gained the
status-keyed lock across every status including the two routes into
`law-cancelled`, and `WorkflowTest` gained the free event as a first-class state
(bookable, snapshot still correctable, lock on) beside the zero-fee approval
case it already covered. 894 tests in the suite.

### Approved events on the programme, and the four things that open booking (16 September 2026)

The client settled the whole of the programme's visibility rule in one thread,
and it moved a boundary that had been fixed since the rebuild: **paying no
longer decides whether an event is SEEN, only whether it can be BOOKED.**

The rule as built:

- An event the committee has **approved** goes straight onto the programme,
  carrying a disabled "Open soon" button. Anything earlier in the workflow
  (Proposed, Sent back, Rejected, Draft) stays off it, as before.
- Booking opens once the event is **paid for** and, on top of that, its
  **places are released** and a **venue is recorded**. The venue is required
  whichever way the host answered field 103 (Venue needed) on form 2 (Event >
  submit an event): an attendee needs to know where to turn up, and whether LAW
  found the room or the host already had one makes no difference to that. It is
  required of **host submissions only** -- see the receptions below.
- **Override booking availability** (Automatic / Disable booking / Enable
  booking) lets the committee close an event that would otherwise be open, or
  force one open that is missing its venue or its payment. The client asked for
  the second of those by name: "we may have a high level sponsor that we need to
  promote immediately, even if they don't have the venue address, but that is the
  exception not the rule."

**Why this was work rather than a tweak.** Paying is what publishes an event —
`approve` goes to `law-approved` and raises the invoice, and only `mark_paid` or
the `invoice.paid` webhook then runs `confirm`, which goes to `publish`. So
"approved but unpaid" and "on the programme" had been mutually exclusive by
construction, and 33 events were sitting invisible with a £600 or £1,200 fee
outstanding, 31 of them with places already released. The programme showed 54
cards where 85 events existed.

**The payment gate is a post status, so it is enforced in one line.**
`law_booking_guard_open()` (bookings.php) has always refused anything that is
not `publish`, and that refusal is what keeps the booking form, add-a-colleague,
register-on-behalf, the waitlist's own promotion, the untrash hook and the
`?law_dialog=1` fragment server off an unpaid event. Nothing had to be added for
the money; what had to be added was Enable booking lifting it, which it does
only for an event the public can actually see
(`law_event_is_publicly_listed()`), so the answer can never sell places at a
rejected, cancelled or unsubmitted one — those are not waiting for money, they
are not happening.

**The other three conditions are one predicate.**
`law_event_booking_hold_reason()` returns `''`, `'disabled'`, `'places'` or
`'venue'`, and BOTH sides ask it: the guard that refuses a submission and
`law_booking_resolve_state()`, which decides what the card and the event page
say. Two lists would be two lists to drift apart, and the drift would show as a
Register button that is refused when pressed. Order matters twice inside it:
Disable booking is judged first, because it is meant to hold an otherwise
complete event shut; and **places are judged before Enable booking, so that
answer cannot override them** — a place is allocated out of the capacity, so the
booking system has nothing to give away without one. That combination is the
only thing the committee can ask for and not get, so the panel says so as an
error (`law_event_booking_hold_note()`, which is also where the panel's wording
lives, shared with the wp-admin box so the two screens cannot describe the same
event differently). That note stays silent before approval and on an external
event: what keeps booking shut on a Proposed event is the workflow, not its
venue, and naming one would answer a question nobody has asked. Disable booking
is reported whatever the status, because it is a decision rather than a fact.

**One public wording for all four reasons, deliberately.** Card and panel both
say "Open soon" over "Places for this event have not been released yet.",
whether what is missing is the money, the places, the venue or the committee's
own decision. Naming the reason on a public page would tell an attendee that a
host has not paid their invoice or that their venue is unknown. The committee
reads the actual reason on its own panel.

**An external event is the exception to the derived holds, and not to the
override.** Its Register button leaves the site for the organiser's own page, so
its places and its venue are not facts about it;
`law_booking_resolve_state()` answers `'external'` before it reads any hold, and
Disable booking is checked before that branch so it still closes one. The hold
predicate itself judges an external event like any other, which is deliberate:
that is the reading `law_booking_guard_open()` takes, and it is what keeps the
local waitlist and booking form off an event whose places are somebody else's to
sell (`tests/ExternalEventsTest.php` asserts exactly that, and its docblock
warned about this case before the case existed).

**Making Approved a status WordPress will render took three args, not one.**
`law_event_statuses()` has always carried a `public` flag that nothing read and
`law_events_register_statuses()` ignored; it is now honoured, and Approved sets
it. `public => true` is what stops `WP_Query::get_posts()` emptying a singular
result for a logged-out visitor (wp-includes/class-wp-query.php, the
`! $post_status_obj->public` branch), while `publicly_queryable => true` with
`protected => false` is what `is_post_status_viewable()` wants, which is what
`wp_force_plain_post_permalink()` asks before `get_permalink()` will return a
pretty URL — without the pair an approved event's permalink was
`?post_type=law_event&p=5990`. `exclude_from_search` stays true on every status
and the CPT is registered `exclude_from_search` anyway, so none of this puts an
unconfirmed event into site search, and SEOPress builds its sitemap from
`post_status publish` only. `templates/event-single.php` sends
`X-Robots-Tag: noindex` for anything that is not published: an approved event can
still be cancelled, and an indexed page that then 404s is worse than a late
listing.

**Three other things had to learn the wider rule.**
`law_calendar_public_statuses()` returns Confirmed **and** Approved — but only
on the CPT source, because the legacy Gravity Forms programme has no booking
system at all and an Approved entry there would look bookable with nothing on
the row to say otherwise. `law_events_cpt_mapped_events()` derives its
`post_status` list from those labels through the new
`law_event_status_keys_for_labels()` rather than hardcoding `publish`.
`law_events_event_url()` returns the permalink for a publicly listed event
instead of the committee's `?event=` view, which is behind the Members
restriction and would be a dead end for a visitor; the same predicate flipped
the committee dashboard's and `speaker-manage.php`'s "Preview event" button to
"View event" on an approved event.

**The panel gained the Venue field it had been judging.** Committee controls
now opens with the booking select and then, above the capacity band and the
places it belongs with, **Venue (name and/or address)** — not required, because
an event can sit on the programme before its room is settled, but booking does
not open until it is filled in. Before this the field existed only on the event
form and in wp-admin, so the one screen that decides whether booking opens could
not set the one thing it now waits for. The select's value, and the venue, are
both logged: `law_event_log_booking_override_change()` is its own entry rather
than a limb of `law_event_log_flag_change()`, because that one carries 0/1 flags
and this answer has three values.

`law_event_log_flag_change()` was rewritten to derive its key set from what the
CALLER read before its write, instead of a fixed list. The panel now writes the
booking select and the two classification switches under separate sentinels, and
the fixed list would have compared a key the call never touched against a
default of 0 — an external event would have reported being marked external every
time somebody changed the booking select.

**The receptions are exempt from the venue, and only from the venue** (Denis,
16 September 2026): the client manages them on Manage receptions, and "once
they are set to be visible on the programme and have capacity and a price, they
should be bookable right away -- we don't care about the venue for those". The
predicate is `law_event_venue_gates_booking()`, keyed on
`law_event_is_managed_by_law()` rather than on a reception check, so "which
events LAW runs itself" keeps being answered in the one place it is answered
everywhere else. The other two kinds it names change nothing by coming along:
the flagship is applied for rather than booked and `law_booking_guard_open()`
refuses it earlier, and an external event's room is the organiser's to know and
is still held shut by the places limb, which is the limb
`tests/ExternalEventsTest.php` depends on. A reception's **capacity** still
decides, because a place is allocated out of it, and Disable booking still
closes its checkout through `law_reception_guard_open()`.

Worth recording what this exemption does NOT add: a reception's **price** is
format-validated by `law_reception_validate()` but never required, so a
reception published with capacity and no price is bookable as a FREE event
through the ordinary booking form rather than through Checkout. That is
pre-existing behaviour, it was not part of this change, and whether a £0
reception should be refused has not been put to anyone.

**A reception's row on the event list says Edit, and goes to its own editor**
(Denis, 16 September 2026): `law_receptions_dashboard_url( $id )` — the
`?law_reception=<id>` view on Manage receptions — rather than `?event=<id>` on
the committee detail page. A reception has no workflow to review: no host
submitted it, nobody approves it, no invoice is raised, and every field it does
have (date, times, venue, places, price, the included and invitation switches)
lives on that screen behind the one saver `law_reception_save()`. Sending the
committee through a read-only detail view to reach its Edit button was a hop
with nothing on it. Ordinary events, external events and the flagship keep
Review and the detail view; only the label and the href move, and the timeline
view inherits both because it renders the same partial.

**The audit pass, and the five things it moved** (16 September 2026, after the
above shipped). Read as one list, because each is the same mistake in a
different place: treating `publish` as "on the programme" when it now means
"paid for".

1. **A place somebody already holds outranks every hold.**
   `law_booking_resolve_state()` returned "Open soon" the moment an event was
   closed, and it returned it to the attendee holding a confirmed place on it.
   Their booking was untouched, but the card and the event page -- the only two
   surfaces carrying the link to it -- told them the event was not open yet, so
   the route to manage or cancel their own place was gone. Worse on a reception,
   where a delegate could be mid-payment. The two holds that are read before the
   viewer is (the fee outstanding, and Disable booking) are now recorded in a
   `$held` flag and applied AFTER the viewer's own booking resolves, so the
   holder keeps `booked` / `waitlisted` / `pending-payment` and everybody else
   gets "Open soon". The sequence is reachable rather than theoretical: force a
   sponsor's unpaid event open, take bookings, set it back to Automatic.
   Invitation-only and external keep their existing precedence over the viewer's
   own state -- that is a settled decision (and nobody holds a local booking on
   an external event anyway) -- so Disable booking is still applied inside the
   external branch, which returns before any hold is read.
2. **The committee's own bookings columns hid a forced-open event's bookings.**
   `$law_row_bookable` (dashboard-list.php) and `$bookable`
   (slot-chart.php) were `'publish' === $post_status`, so a forced-open Approved
   event with real places taken printed a dash in the Bookings column and
   offered no way into the list. Both now read "publicly listed AND places
   taken, or published". Deliberately not "places taken" alone: a Proposed
   event can hold no booking at all, so a stray `_law_tickets_sold` on one is
   impossible data rather than a booking to link to -- which is what
   `SlotChartTest::test_an_unconfirmed_event_states_no_booking_numbers` catches,
   and it caught it.
3. **The approval email said the wrong thing.** `user_payment_due` told the host
   "Your event will be published in the programme once payment is received",
   which stopped being true the moment approval put it there. It now says the
   event is listed showing "Open soon" and that registration opens on payment.
   NOTE: `law_events_email()` merges the `law_events_email_overrides` option
   over the registry, so any copy somebody has pressed Save on from the Emails
   screen keeps its stored wording and will still make the old promise. The
   local database holds no overrides; staging and production need checking.
4. **`{event_link}` and the legacy `?event=` redirect** both keyed on `publish`,
   so an Approved event's email placeholder came out empty and an old
   `?event=<entry id>` link rendered inline on the programme instead of 301ing
   to the permalink the event now has. Both ask
   `law_event_is_publicly_listed()`. The same for the `.ics` invite's
   description link (`law_event_ics()`), which an attendee of a forced-open
   event would otherwise receive without one.
5. **An empty derived status list.** `law_events_cpt_mapped_events()` derives
   its `post_status` from the allowed labels; on a label list that matches no
   status (the `array( '*' )` sentinel) that produced `post_status => array()`,
   which WP_Query reads as "no status clause" and answers with the public
   statuses -- a silent widening on exactly the input that meant something else.
   It falls back to every status now. No caller passes that today; the single
   caller is `law_calendar_events()`.

Checked and deliberately NOT changed: `law_reception_guard_open()` still
requires literal `publish` and honours only the `disable` half of the override,
so a reception cannot be bought before it is shown on the programme;
`speakers-dashboard.php` still appends the status label to an unpublished
event's name, which is a committee-facing select and should say Approved; and
the `not-open` panel still says "Places for this event have not been released
yet." on an Approved event whose places ARE set, because one public wording for
every hold is the point (see above) and the alternative leaks the host's
invoice.

A security review of the whole change (the `security-specialist` agent: the
status registration, both write paths for the override, the `_law_venue` write,
the repair panel, and every booking-creation entry point) returned no findings
at any severity. The two things it confirmed that matter most: `edit_law_events`
is held only by `administrator`, `editor` and `events_committee` on the live
site, so no host can force their own unpaid event open; and every creation and
promotion path re-asks `law_booking_guard_open()` itself rather than trusting a
caller, so raising capacity on an unpaid Approved event promotes nobody unless
the answer is genuinely Enable booking.

**Two things to know before this deploys.**

- **The venue rule closes booking on live events.** On the current data eight
  publicly listed events have no venue recorded and places released: six hosted
  events and both receptions -- **but the receptions are exempt** (above), so
  what closes is the six hosted events. They drop to "Open soon" until somebody
  types an address, which is the intended behaviour and exactly what Enable
  booking is for.
- `LAW_Test_Case::make_event()` now seeds `_law_venue`, or every suite that
  books anything would have been testing the venue hold by accident.

Covered by `tests/ProgrammeVisibilityTest.php` (35 tests): the two statuses the
programme lists and the one the legacy source lists, the status registration and
the permalink, the payment gate and the one thing that lifts it, each hold in
isolation, the venue rule asserted on all three answers to Venue needed, the
external event's two halves, the receptions' exemption and the two things it does
not exempt them from, the reception row's Edit button, a held place surviving
both Disable booking and a forced-open event going back to Automatic, the
committee's Bookings column on a forced-open event, and that the public wording
never names the reason.

### The receptions take the navy surface, and their days say so (16 September 2026)

A drinks reception on `/programme` rendered as an ordinary compact row carrying
one extra "Price: £100.00 + VAT" line, and on the conference's own day it sat
directly under the flagship's navy photo block and disappeared. Denis: "we have
receptions on /programme, we need to make them pop more... the reception on
Wednesday is lost under the big flashy flagship."

**The pale peach surface they were wearing was a bug, not a treatment.**
`law_events_post_is_sponsored()` has three clauses, and the third is a
repeat-submitter heuristic: an author with more than one Approved or Confirmed
event in the programme year is behaving like a sponsor. LAW's own events have no
host at all -- `law_event_ensure_managed_post()` writes whichever committee
account created them into `post_author` -- so that account trips the clause on
its second post and every LAW-run event starts wearing `.law-event-card--sponsored`
(`#fdeedd` fill, orange left edge). On the live local data both seeded receptions
came back `sponsored = true`. The programme was telling visitors that LAW's own
drinks reception was a sponsored event.

The fix skips **only the third clause** for `law_event_is_managed_by_law()`,
which already knows the flagship, the receptions and the external listings. The
first two stand: a firm really can sponsor a reception, and a sponsor fee tier or
a linked sponsor organisation still says so.
`law_events_cpt_author_counts()` gained the module's usual `$reset` parameter,
because it is a static memo and a test that creates events mid-request cannot
otherwise see them.

**The surface.** `.law-event-card--reception` is not a second recipe: it was
folded into the selector lists that already painted `.law-event-card--flagship`,
so the two share one set of declarations -- brand navy `#292459` fill, orange
left edge, white title and meta, the inverted button pair, the white repaint of
the committee's status badge. Splitting them would have produced two copies of
the same twelve rules. What tells a reader which is which is the identity pill
(`.law-event-card__reception-badge`, "Reception", added to the shared
five-selector pill shape rather than copied) and, on the programme, the fact
that the conference is a photo block rather than a row at all. The block is
declared **after** `.law-event-card--sponsored`, which is the whole mechanism by
which a genuinely sponsor-backed reception still comes out navy: the two
modifiers tie on specificity, so source order decides. There is a test whose only
job is to fail if that order changes.

The modifier lands wherever `parts/loop/event.php` is rendered -- the programme,
My bookings, My events and the single speaker profile's role lists -- which is
the same reach the flagship's has.

**The day tabs.** A "Reception" pill beside the existing "Flagship" one
(`law_calendar_reception_dates()`, next to `law_calendar_visible_flagship_event()`).
It runs on **presence, not booking state**: a reception whose places have not
been released still marks its day, exactly as the conference's pill does not wait
for registration to open. It reads the already-filtered day buckets, so a keyword
search that hides the reception takes its pill with it.

Three details that are not obvious from the diff:

- **Both pills live in a `.law-cal-daynav__flags` wrapper**, rendered on every
  tab whether or not it has anything in it. Stacking them would make Wednesday's
  tab taller than the rest, and `--law-cal-daynav-h` is the sticky scroll offset
  every in-page anchor on the programme is measured against -- a fixed value, not
  one the script measures. The wrapper is always present because
  `calendar-tabs.js` adds and removes pills inside it after a filter fetch and a
  day that GAINS one needs somewhere to put it.
- **The reception days ride on the flagship's marker**, as a second attribute
  (`data-law-reception-days`) on the same `<span class="law-cal-flagship-marker">`
  rather than a second element. The day nav lives outside `#law-cal-events` and
  is never swapped by a filter fetch, so the script has to be told; and keeping
  one marker keeps "absent" meaning the one thing it means today -- markup that is
  not the programme's, whose pills the script must leave alone (the committee's
  timeline view swaps in `parts/events/slot-chart.php`). That view supplies its
  own `reception_dates` from `law_slotchart_item()`'s already-resolved `kind`, as
  it already supplies its own counts and flagship date.
- **`FlagshipRenderTest` needed tightening, not fixing.** It asserted on the bare
  string `law-cal-daynav__flag`, which the new `__flags` wrapper matches on every
  tab of the week. It now asserts the full class attribute, which is the
  conference's pill alone.

**What was deliberately not built.** A receptions strip above the day sections
(mirroring `parts/events/flagship-strip.php`, reusing the `.law-strip` utility
the account banner already paints) and pinning receptions above the flagship on
their day were both put to Denis and both declined: the page's structure stays
as it is. Worth knowing that colour is the weaker half of the fix -- a one-line
navy row beneath a large navy photo block can read as one continuous dark mass --
so if Wednesday still reads wrong, more vertical padding on the reception row,
then the strip, are the next moves rather than a different colour.

New: `tests/ReceptionProgrammeTest.php` (11). Changed: `functions/events/source.php`,
`functions/calendar.php`, `parts/loop/event.php`, `parts/calendar-daynav.php`,
`parts/calendar-events.php`, `templates/account-dashboard.php`,
`assets/css/calendar.css`, `assets/js/calendar-tabs.js`,
`tests/EventFlagsTest.php` (four sponsored cases), `tests/FlagshipRenderTest.php`.
### The band a committee member could not widen (16 September 2026)

An event stored at "51-100" with 120 places could not be corrected from the
committee dashboard. Choosing the wider "101-150" and pressing Save changes
produced the browser's own bubble, "Please select a value that is no more than
100", and nothing was saved. Both halves of the defect the 15 September entry
above deferred were live at once, and this is the fix for both.

**An attribute that was printed but never maintained.** `law-dash-places`
carried a `max` rendered from the STORED band, while `event-form.js` only syncs
`min`/`max` on a field marked `data-law-strict` — which the panel deliberately
is not, because its Save changes, Approve, Send back, Reject, Mark paid, Cancel
and Delete buttons all submit one `<form>` and a stale attribute would block
every one of them. So the panel had the worst of both readings: the constraint
was enforced, and it was enforced against the band the member was in the middle
of replacing. Because the two halves post together there was no order in which
the correction could be made in two saves either — the pair was simply stuck.

**The panel now renders no `min` or `max` at all.** Every native constraint on
this form is a validation bubble in front of six action buttons, and
`committee-actions.js` hangs off the form's `submit` event, which never fires
while the form is invalid, so a bubble silently disables the modals too. The
band is enforced by the inline `.law-form-error` that `event-form.js` already
renders here and, for real, by `law_committee_venue_input_error()` on the post.
`check()` gained the "whole number of 1 or more" case in the same wording the
server uses, since the dropped `min="1"` was the only thing that had flagged a
typed zero.

**The server now judges the change, not the event.** Both halves ride along on
every panel action, because `committee-actions.js` posts the whole form, so
`law_committee_action_handler()` was reading a Delete as a submission of the
stored pair and refusing it. **`law_committee_venue_input_unchanged()`**
(committee.php, beside the error wrapper) answers whether the posted pair is the
rendered one handed straight back; the handler skips the check when it is. An
event that already breaches its band can therefore be approved, sent back,
rejected, marked paid, cancelled and deleted, which is the point: a capacity
rule must not be able to block a delete. This is the same reading
`law_events_form_save()` takes of a pair whose halves the submitter cannot move,
and it is not grandfathering — moving either half is judged in full, so the
breaching pair still cannot be saved, and the correction (widening the band)
goes through in one save.

"Unchanged" means the pair **exactly as rendered**. Stored places of 0 render as
a blank field, so blank is the untouched value there and a typed `0` is a change
like any other; anything that is not a plain positive number is a change for the
same reason, or "lots" against a stored 0 would read as untouched and skip the
check that refuses it. A migrated free-text band ("101 to 150") is covered by
the same rule: posting it back is not choosing it, so it no longer blocks the
delete that is probably what such an event needs.

Touched: `templates/account-dashboard.php`, `assets/js/event-form.js`,
`functions/events/committee.php`, `tests/VenueDetailsTest.php` (five cases, from
the one-save correction to the non-numeric places).

The 17 live events that breach a bound are left as they are — each is now
fixable in one save from the panel, and a bulk repair was not part of this.
Whether a cleared Places available should mean "no limit" or "bookings closed"
is still the open product question in the entry above.

---

## Manage emails on the front end (16 September 2026)

The wp-admin Emails screen now has a committee-facing twin at
`/account/dashboard/emails/`, last in the committee group of the top bar and of
the account hub. Same reason as every other management screen
(`functions/events/emails-dashboard.php`, and the same decision as Manage
bookings, Manage speakers and the discount catalogue): a member holding only
`events_committee` should never have to learn wp-admin to fix a typo in a
notification.

**One feature, not two.** Neither screen owns the data any more. Everything
that reads or writes an override moved into `notifications.php` and both call
it: `law_events_email_override_from_input()` (build), `_save_override()`,
`_reset_override()`, `_is_customised()`, `_has_unresolved_tags()`,
`_recipients_label()`, `_recipients_survived()` and `_send_test()`.
`tests/EmailsDashboardTest.php` asserts by reflection that each of those still
lives in `notifications.php`, and that neither screen writes
`LAW_EVENTS_EMAIL_OVERRIDES_OPTION` itself — the same guard
`ReceptionsDashboardTest` puts on the receptions' shared saver, and for the same
reason: a second writer is how two screens quietly come to mean different
things.

**Two deliberate differences from wp-admin.**

1. **Test mode is not offered.** The card at the top of the wp-admin list is not
   an email-wording control: it diverts *every* email the site sends, password
   resets for real accounts included, and it is gated on `manage_options`. It
   stays gated there. What the committee screen does is announce it — a warning
   strip on both views, but only while it is actually on, because somebody
   pressing "Send a test to me" has to know why the message landed elsewhere.
   The test-send confirmation says so too, naming the diverted address instead
   of claiming the test went to them.
2. **The body uses the module's own editor, not wp-admin's.**
   `law_rich_text_field()` — the same TinyMCE the event description, speaker
   biography and session description use — on the same allowlist, falling back
   to a plain textarea without JavaScript. See the entry below for why that
   needed the send path changed first.

**Recipients are never saved empty (both screens).** `'to'` is editable only
where the registry names a fixed address list; the dynamic audiences (`host`,
`committee`, `admin`, `invoice_contact`) are resolved per event at send time, so
a posted value for one is dropped rather than stored somewhere nothing reads it.
And a list where nothing survives `is_email()` no longer overwrites the stored
addresses with `array()`: `law_events_send()` treats "no recipients" as a
failure it logs and drops, so one typo in the only address would have silently
switched the notification off while both screens went on reporting it as
active. The builder keeps the code default;
`law_events_email_recipients_survived()` lets each screen refuse the save and
say why. To stop an email being sent, untick "Send this notification" — that is
the control for it.

**Not logged, on either screen.** The module's activity log is per-event
(`law_event_log()` writes a comment on a `law_event` post and returns early
without one) and a notification's wording belongs to no event. Recording "who
reworded what, when" would need a site-wide stream the module does not have, so
nothing pretends to keep one. Worth revisiting now that more than one person can
edit these.

**The rest.** The list is the full registry, inactive rows included — "which
emails are switched off" is a question this screen has to be able to answer —
with an "Edited" badge on the customised ones (15 of 78 in production today) and
a red "Check the tags" badge on any body still carrying a Gravity Forms merge
tag the renderer cannot resolve. The editor posts through
`admin_post_law_email_manage` on the module's standard guard (nonce, honeypot,
its own `email_manage` rate surface, with a tighter `email_test` budget on
outbound test sends), answers JSON for `assets/js/booking-form.js` and falls
back to a plain POST without it. Reset sits behind the shared
`parts/layout/modal.php` confirm dialog. A test send and a refused save both
come back through a one-shot transient keyed by slug, so the draft survives the
redirect and does not leak onto the next notification's form.

Touched: `functions/events/emails-dashboard.php` (new),
`templates/account-dashboard-emails.php` (new), `parts/events/emails-list.php`
and `parts/events/emails-manage.php` (new), `functions/events/notifications.php`
(the shared helpers), `functions/events/admin/emails-screen.php` (refactored
onto them), `functions/events/_load.php`, `functions/header-nav.php` (the
`emails` path and nav item), `functions/helpers.php` (the envelope glyph),
`functions/setup-account-pages.php` and `functions/events/migration/runner.php`
(page provisioning, both routes), `functions/enqueue.php` and
`functions/events/submission-form.php` (stylesheets), `assets/css/calendar.css`
and `assets/css/event-form.css` (the screen, and an `is-warning` notice
variant), `tests/EmailsDashboardTest.php`, `tests/HeaderNavTest.php` and
`tests/AccountHubTest.php`.

---

## Email bodies carry formatting (16 September 2026)

**The wp-admin toolbar had been decorative since the module was built.** The
Emails screen has rendered a `wp_editor()` over the body field from the start,
but the save ran `sanitize_textarea_field()` (which strips every tag) and
`law_events_send()` rendered with `esc_html()`, so anything anybody bolded there
was discarded on save and would have arrived as visible angle brackets if it had
not been. All 78 registry defaults and all 15 stored overrides in production are
plain text, so nothing was broken in the database — the feature had simply never
worked. Denis asked for real formatting on the committee's screen, reusing the
event description's tooling, and that turned out to need the send path changed
before any editor could be honest.

**Storage** is now `law_events_email_body_sanitize()`, a named wrapper on
`law_rich_text_sanitize()` — the same allowlist as the descriptive fields (bold,
italic, both list types, `h3`/`h4`, blockquote, links; `<script>` and `<style>`
blocks dropped contents and all). Deliberately not `wp_kses_post()`: an email
body should be able to emphasise and structure a message, not embed media or
layout that every client renders differently.

**The escaping rule, which is the whole of the security argument.**
`law_events_email_render_body()` escapes the placeholder VALUES and leaves the
BODY alone:

```php
$body = strtr( $body, array_map( 'esc_html', $placeholders ) );
return make_clickable( law_rich_text_render( $body ) );   // wpautop + the allowlist
```

That is the exact inverse of the old code, and the inversion is the point. The
body is trusted — only the committee and administrators can write one, and it
has already been through the allowlist. The values are not: `{event_title}`,
`{host_name}`, `{latest_comment}`, `{rejection_reason}` and `{attendee_list}`
are all typed by hosts and delegates. Escaping the whole string after
substitution (the old way) was safe but made formatting impossible; escaping the
values instead is what lets the body carry markup without opening an injection
route through somebody's own event title. Every placeholder value is plain text
or a URL — `{event_summary}` goes through `law_rich_text_plain()`,
`{attendee_list}` is a newline-joined list — so escaping all of them is right
and nothing is double-encoded.

Three consequences worth knowing:

- A **plain-text body renders exactly as it always did.** `wpautop()` still
  turns blank lines into paragraphs and single newlines into `<br>`, so the 78
  defaults and the 15 overrides are unchanged on the wire. Pinned by
  `test_a_plain_text_body_renders_exactly_as_it_always_did()`.
- A **placeholder inside an attribute resolves**: a body carrying
  `<a href="{invoice_url}">Pay now</a>` works, because substitution happens
  before the allowlist is re-applied at render.
- The **subject is still not escaped**, on purpose. It is a mail header, and
  `esc_html()` there would put a literal `&amp;` in front of the reader in their
  inbox list.

**One sanitiser, three writers.** The two screens and the content-transfer
importer all call `law_events_email_body_sanitize()`. The importer used to have
its own inline `sanitize_textarea_field()`, which would have flattened every
bundle on the one path built specifically to carry email wording between sites.

**An emptied editor is refused, not stored.** TinyMCE's idea of empty is
`<p>&nbsp;</p>`, which the sanitiser correctly reduces to `''`, so clearing the
field and pressing Save would have left the notification sending a subject line
over a blank page. `law_events_email_body_survived()` is the floor and both
screens act on it, with the same "untick Send this notification instead"
wording as the empty-recipients refusal. The front-end field also carries
`data-law-rich-required`, so the browser catches it before the round trip.

**Both screens offer the same buttons.** The wp-admin `wp_editor()` dropped
`teeny` (whose fixed row has no headings, though the allowlist does) and now
takes its `toolbar1`, `block_formats` and `valid_elements` from
`law_rich_text_settings()` — the same policy object the front-end editor and the
event description run on, so the two cannot drift into offering different
formatting against one shared allowlist.

**"Send a test to me" renders through `law_events_email_render_body()` too**, so
a test is a preview of the real thing rather than a second opinion about it.

Verified end to end through Mailpit: a formatted body arrives with its
`<strong>`, `<ul>`, `<h3>` and `<a href>` intact inside the Email Templates
plugin's branded wrapper.

**Worth doing next:** migration step 9 imported the legacy Gravity Forms
notifications through `wp_strip_all_tags()`, so several stored bodies still
carry the wreckage of stripped lists (tab-indented lines where `<li>` used to
be). Those can now be repaired by hand on either screen.

Touched: `functions/events/notifications.php`, `functions/events/rich-text.php`
(read only — reused as is), `functions/events/admin/emails-screen.php`,
`functions/events/emails-dashboard.php`, `parts/events/emails-manage.php`,
`templates/account-dashboard-emails.php`,
`functions/events/migration/content-transfer.php`, `assets/css/calendar.css`,
`tests/EmailsDashboardTest.php`.

## One welcome email, not two (16 September 2026)

**A template the Emails screen offered but nothing could send.** The registry
carried two welcome emails, `user_welcome_registered` and
`user_welcome_registered_host`, and `law_registration_welcome_slug()` chose
between them on the stored `law_intent`:

```php
return $intents ? 'user_welcome_registered_host' : 'user_welcome_registered';
```

The split began as a role test, became an optional "I plan to host an event"
tick, and then lost the tick on 14 September 2026 when the self-service roles
were retired. From that day no form collected an intent, so `$intents` was
always empty on a new registration and the hosting copy could not be reached by
any route except a hand-crafted POST carrying `law_intent[]` (whitelisted and
stored, so it would have worked, though the only consequence was a different
welcome body).

Denis asked what the second template was for while looking at the front-end
Manage emails list, which is where the cost showed. Both rows read **ON**, and
the trigger column described states that no longer exist: "user registration (no
hosting or sponsor tick)" and "user registration (ticked host or sponsor)". A
committee member could have spent an afternoon wording an email nobody would
ever receive, with nothing on the screen to warn them. That is a worse outcome
than losing a template we were not using, so the template, the helper and the
qualifier on the surviving trigger were all removed. Restoring the split means
reviving this commit, not flipping a flag.

**`law_intent` itself stays**, and the reasoning is worth keeping separate from
the email. Migration step 11 seeded that key for every account it converted, so
it holds the only translation of what the retired roles said about 302 people,
and `law_registration_hubspot_tags()` reads it for the "<year> Event Host" and
"<year> Sponsor" tags the client segments on in HubSpot. Deleting the storage
would lose both. `law_registration_intents()` therefore survives with a docblock
that now gives one reason for its existence rather than two.

**Nothing needed a data migration.** Every read path is registry-gated:
`law_events_email()` returns `null` for an unknown slug, both editing screens
iterate `law_events_email_registry()`, and `law_content_transfer_emails()`
skips a stored override whose slug the registry does not know. An environment
that had customised the hosting template keeps an inert row in
`law_events_email_overrides` that nothing will ever read.

**The surviving copy has to cover the whole job** — browsing, booking and
submitting an event — because anybody signed in may do all three, and it may
not describe what the reader is *allowed* to do. It already did; that was
settled on 14 September and is pinned by
`test_the_welcome_email_offers_booking_and_submitting()`.

`BookingEmailsTest` lost `test_host_welcome_email_leads_with_submitting_an_event()`,
whose real value was proving `{submit_link}` resolves with no event to resolve
against. That assertion moved into `test_welcome_email_resolves_with_no_event()`
rather than being dropped. `RegistrationTest` swapped its slug-matrix test for
`test_only_one_welcome_template_is_registered()`, which pins the absence: the
registry key is gone, `law_events_email()` returns `null`, the helper function
no longer exists, and the surviving trigger reads plainly "user registration".

Touched: `functions/events/notifications.php`,
`functions/events/registration.php`, `tests/RegistrationTest.php`,
`tests/BookingEmailsTest.php`.

## One sign-off, not seventy-eight (17 September 2026)

**The request and the trap in it.** "Add this ending to each email text
template we have (even if it's customly modified)", with a blank line before it
(Denis, 17 September 2026). The obvious reading — paste the two lines into all
78 bodies in `law_events_email_registry()` — satisfies the first half and fails
the second. The wording that is actually sent is not always the registry's: 15
bodies are overridden site-wide on the Emails screen and stored in
`law_events_email_overrides`, and since earlier the same day an event can carry
its own booking confirmation in post meta (`email-override.php`). Editing the
code defaults would reach none of those, and the next person to reword any body
could drop the sign-off without noticing.

So it is **stored once and appended by the renderer**, in
`law_events_email_render_body()`, which is the one path the shipped default,
the site-wide override and the per-event override all pass through. Nothing at
any of the 78 send sites changed. It goes on before the placeholder
substitution, so a sign-off can carry `{site_name}` exactly as a body can.

**The setting, at the foot of both lists.** Denis asked for it on the same turn:
a textarea and a save button under the notifications table, on the wp-admin
Emails screen and on the committee's front-end Manage emails page. It belongs
under the table rather than on each email's editor because it is not one
email's wording — it is the last thing all of them say, and changing it changes
every notification at once. `law_events_emails_signoff_card()` renders the
wp-admin half; `parts/events/emails-signoff.php` and
`admin_post_law_email_signoff` render and save the front-end half, on the same
guard/respond pattern as every other dashboard form. Both write through
`law_events_email_signoff_save()`, so the two screens cannot drift, exactly as
they cannot for a body.

Clearing the field is how the sign-off is switched off, which is why
`law_events_email_signoff()` reads the option with a `null` default: an empty
string has to mean "deliberately none" rather than falling back to the shipped
wording. An empty save is accepted here, though an empty BODY is still refused
on both screens — an email with no message is a bug, an email with no sign-off
is a decision.

**What the request did not anticipate: the migrated wording already signs off.**
Six of the fifteen stored overrides came out of the old Gravity Forms
notifications ending with their own "Best, / London Arbitration Week", so
appending the new sign-off gave them two, one under the other. Fixed once in the
data rather than guessed at per send:
`functions/events/migration/repair-signoff.php` is a dry-run panel on
LAW → Migration that strips the closing lines out of the stored bodies. A
render-time "does this already have a sign-off?" check was considered and
rejected — it would have to keep being right about every wording anyone writes
in future, and would silently eat a different closing line somebody wanted on
one email. The stripper only takes a short (≤ 60 character) trailing line that
is nothing but a valediction or the organisation's name, at most four of them,
and only when a valediction is among them, so the worst case is a sign-off left
in place rather than a message truncated. It reads a body written as markup
(trailing `<p>` blocks) as well as one written as plain lines.

`EmailsDashboardTest` had to isolate the new option in `setUp()` — a filter
rather than `isolate_option()`, which overlays an array and would destroy the
unset/empty distinction — because three existing tests count paragraphs and
line breaks in rendered output and the sign-off adds one of each. Ten new tests
pin the behaviour, including the four bodies the stripper must leave alone.

Touched: `functions/events/notifications.php`,
`functions/events/admin/emails-screen.php`,
`functions/events/emails-dashboard.php`,
`functions/events/migration/repair-signoff.php` (new),
`functions/events/migration/page.php`, `functions/events/_load.php`,
`parts/events/emails-signoff.php` (new),
`templates/account-dashboard-emails.php`, `assets/css/calendar.css`,
`tests/EmailsDashboardTest.php`.

## The Venue needed question comes off the form (17 September 2026)

**The request.** "On event creation form and event edit form we have radio
boxes for picking Venue 'Yes..' or 'No...'. We actually don't need those radio
boxes anymore. Let's hide those, but still write in data as user picks 'No' all
the time and as a result we display bottom inputs related to venue all the time:
Venue name, Capacity and Places available. All those 3 fields should be
required" (Denis, 17 September 2026).

So the premise of the 9 September rule is withdrawn. LAW no longer finds rooms
for hosts, and the two-choice radio that asked whether they needed one (field
103, Venue needed, on form 2 — Event > submit an event) has nothing left to
decide. Everything that keyed on it collapses to the branch that said "No, we
already have a venue planned".

**Hidden, not removed, and the answer is still recorded.** Denis asked for the
answer to keep being written, so `law_events_form_save()` writes
`law_events_venue_needed_choices()['no']` into `_law_venue_needed` on **every**
save, rather than reading a posted value. Nothing renders a control for it, so
there is nothing to forge and nothing to lock: `venue_needed` came out of
`law_events_locked_fields()`' host list and out of `law_events_form_values()`,
because a lock exists to disable a control and a form value exists to seed one.
Writing it on every save rather than only on a new event also quietly repairs
the events migrated or submitted before today — an event whose stored answer was
"Yes" stops describing its host to the committee as having asked LAW for a
venue the next time anyone saves it.

**The two predicates survive as constants.** `law_events_venue_details_visible()`
and `law_events_venue_details_required()` now take no arguments and return
`true`. They were not inlined, for the same reason they existed in the first
place: the form template, the validator and the saver must agree about what was
asked, because an absent field must never be read as a cleared one, and one
predicate is how that agreement is kept. `law_events_venue_needed_value()`, the
"which answer are we judging" helper, had no callers left and is gone.
`law_events_venue_needed_label()` and `law_events_venue_needed_choices()` stay:
the stored answer is still printed on the committee's event panel and still
settable on the wp-admin Event facts box, and legacy rows still arrive holding
the bare "Yes"/"No" choice values.

**What changes for each audience.**

- **A host** now sees Venue (name and/or address), Venue capacity and Places
  available on every form, create and manage alike, all three starred. The
  locks are untouched, so on an event under review the places are still
  disabled (locked from submission, 14 September) and after approval the band
  is too — and a locked field is still never re-validated, so neither can block
  an edit.
- **The committee** is now held to the same three fields. This is the one
  behavioural loss worth naming: until today they could save an event that had
  no venue yet, because on "Yes" the three were theirs to fill in later. That
  exemption was a consequence of the question, so it goes with it. If a
  committee member needs to save an event before its venue is known, "TBC" is
  still a capacity band and the places may be anything inside it.
- **A host post-approval can now change the venue name on an event LAW placed.**
  Before today the field was off their form on that answer and a crafted post
  was ignored; now it is on their form, and the venue name has always been
  host-editable at every status (the band and the places are not). Worth
  knowing rather than worth guarding: the venue is what an attendee turns up
  to, and every change is in the activity log.

**Markup and script.** The radios, the `#law-venue-details` wrapper and the
committee-only hint that explained the other answer all come out of
`parts/events/event-form-fields.php`; the three fields sit directly in the
fieldset's three-column grid. The wrapper was the only user of
`data-law-toggles-keep` in `assets/js/event-form.js` — the opt-out that stopped
a hidden block's values being cleared — so that branch went too. The generic
`data-law-toggles` mechanism stays; the sector "please specify" inputs and the
"Other" accessibility and dietary boxes still use it.

**Left alone, deliberately.** The committee's event panel still prints a "Venue
needed?" row and the wp-admin Event facts box still offers the select. Both
read `_law_venue_needed`, which is now the same answer on every event that has
been saved since today, so the row is on its way to being noise — but removing
a committee-facing display is a separate decision from taking a question off a
form, and legacy events still carry a meaningful answer.

**Tests.** `tests/VenueDetailsTest.php` keeps its 40 tests: the visibility
predicate, the required-ness rule and the crafted-post cases were rewritten
around the new rule rather than deleted, including one that pins the committee
being held to the three fields and one that pins the old exemption being gone
on an event whose stored answer still says LAW placed it. The `valid_input()`
helpers in `SubmissionFormLockTest`, `EventFlagsTest`, `FlagshipTest`,
`SessionsTest` and `SpeakerNamesTest` gained the three venue fields, since a
form save without them is now refused.

Touched: `functions/events/submission-form.php`,
`parts/events/event-form-fields.php`, `assets/js/event-form.js`,
`functions/events/committee.php`, `templates/account-dashboard.php` (stale
comments only), `tests/VenueDetailsTest.php`, `tests/SubmissionFormLockTest.php`,
`tests/EventFlagsTest.php`, `tests/FlagshipTest.php`, `tests/SessionsTest.php`,
`tests/SpeakerNamesTest.php`.

## The flagship acknowledgement splits at the price cutover (17 September 2026)

The flagship is sold at two prices, one either side of a cutover the committee
sets on the Flagship screen (`_law_flagship_price_switch`, 17 October by
default). Until today the registration acknowledgement did not know that: one
template answered everybody. Denis asked for a template per side, so that LAW
can write to late registrants differently, and for the Emails screen to say
which is which.

**Two templates became four.** `user_flagship_applied` and
`user_flagship_applied_free` keep their slugs and now name the early side;
`user_flagship_applied_late` and `user_flagship_applied_free_late` join them for
the late one. Keeping the existing slugs rather than renaming the pair matters:
overrides are stored in `law_events_email_overrides` keyed by slug, so any
wording the committee has already saved for the acknowledgement still applies,
to the early template. The two new rows ship with the registry defaults and have
to be edited to say anything different.

**The names carry the date, and derive it.** Each name ends "before 17 October"
or "from 17 October", built from `law_flagship_price_switch_day()`
(`flagship.php`) rather than typed into the registry, so moving the cutover
relabels all four rows instead of leaving last year's date in front of the
committee. It is "from", not "after", because the switch is at 00:00: a
registration made on the 17th itself is already at the later price.
`law_events_flagship_switch_day()` in `notifications.php` is the guarded wrapper
the registry calls, since `notifications.php` loads before `flagship.php`.

**The side is read from the registration, not from the clock.**
`law_flagship_applied_email()` (`flagship-bookings.php`) takes the early-side
slug and returns its `_late` twin when `law_flagship_price_is_late()` says the
booking's own creation time is on or after the cutover;
`law_flagship_mark_ready()` now sends through it, so both call sites (the saved
card and the fully discounted registration) are covered by one rule. Keying off
`post_date_gmt` rather than "now" is deliberate: that is the moment
`law_booking_quote()` priced the place, so the email and the price agree by
construction even when the card setup returns from Stripe after midnight, or a
free registration is marked ready by a later path. It falls back to the early
slug when no `_late` twin is registered, so an unpaired caller still sends
something.

**The free pair ships with identical bodies**, which is a knowing exception to
the "one template, not a family of near-duplicates" rule stated for the booking
emails above. The only sentence the cutover changes is about the price, and a
registration a code covered in full has none, so the second row exists purely so
that late registrants can be told something different without telling everybody.
If that never happens, folding the two back into one is a two-line change.

**Not touched.** Per-event email overrides do not reach the flagship at all
(`law_event_override_slug_map()` refuses it), so nothing there needed a new
entry. The approval, decline, payment-failure and withdrawal emails are still
one template each: the cutover is about what was charged, and by approval time
the amount is in the booking's own snapshot.

**Tests.** Three in `tests/FlagshipPaymentsTest.php`, alongside the existing
cutover tests: the choice on both sides for both pairs, a registration made
before the cutover still acknowledged at the early rate long after it has
passed, and the names carrying whatever day the screen is set to.

Touched: `functions/events/notifications.php`, `functions/events/flagship.php`,
`functions/events/flagship-bookings.php`, `tests/FlagshipPaymentsTest.php`,
`FLAGSHIP_PAYMENTS.md`.

### The host fee can change after approval, and the invoice follows it (17 September 2026)

The committee asked to keep the host fee override after an event is approved,
with a warning box saying the previous invoice will be voided (Denis,
17 September 2026). Until now the control went read-only at approval and the
panel told them to finish the job by hand: use wp-admin, then void the open
invoice in Stripe and raise a new one there.

The read-only lock was the right answer to the wrong question. It was added on
9 September 2026 because a post-approval change reached neither the snapshot
nor the invoice, so the control reported a success that changed nothing that
mattered. Hiding the control fixed the lie but left the committee with a
three-step manual job across two systems for something as ordinary as agreeing
a discount. The answer is to make the change land everywhere the old figure
went.

**What a changed fee now does**, in `law_event_apply_fee_change()`
(`stripe/service.php`), shared by the committee dashboard panel and the
wp-admin fee box so the two screens cannot behave differently:

1. Void the Stripe invoice raised from the old snapshot, so it can no longer be
   paid.
2. Re-freeze the snapshot (`law_event_resnapshot_fee()`), which is what the
   exports, the admin Fee column and the `{fee}` merge tag read.
3. Raise and send a new invoice for the new amount, and email the host
   `user_payment_due`.

**Void first, create second.** The other order leaves the host holding two
payable invoices for the same event for as long as the second call takes, and
permanently if it fails. Voiding first can only ever leave them holding none,
which is a state the committee can see (Unpaid, no invoice URL) and fix with
the "Retry invoice" button that already exists.

**Zero is not a payment step.** A fee changed to zero raises nothing: the event
goes Free, and an Approved event is confirmed in the same breath, exactly as
approving it at zero would have done. Putting a fee back onto a Free event
takes the payment status out of Free, or the dashboard and the exports would
read Free against a live invoice.

**Where it refuses, writing nothing.** When the fee is settled (`paid` /
`refunded`) or the event is no longer live — settle the difference in Stripe
with a credit note or a refund instead. When the event holds a pre-rebuild
invoice recorded as a web address only, with no ID to void it by (LAW →
Migration has the repair, and the same guard already stops a second invoice
being created there). When the void itself fails, because the old invoice is
then still payable and a second one must not join it. And, on the committee
dashboard only, when a workflow ACTION was submitted in the same POST: the two
actions an approved event still offers are Mark paid & confirm, which would
settle the invoice the change is about to void, and Cancel, which voids it
anyway.

**The three answers replaced the boolean lock.** `law_event_fee_edit_mode()`
returns `open` / `reissue` / `locked` and is read by the panel, the wp-admin
fee box and both save handlers, so the control somebody sees and the write the
handler accepts can never disagree. `law_event_fee_override_locked()` is gone.

**The warning box** is `.law-form-notice.is-warning` on the committee panel,
sitting between the "Override the host fee" tick box and the "New host fee (£)"
field, so it is read on the way to typing the figure it is about. It names the
amount of the invoice that will be voided, and says what setting the fee to 0
does instead.

It is hidden until the tick box is ticked (17 September 2026), carrying the
same `data-law-toggle-for="law-dash-override"` wiring as the amount field: with
the box unticked nothing on the panel can change the fee, because the fee tier
lives in wp-admin and not here, so the warning would otherwise be shouting
about an action the form is not offering. The one exception is an event whose
override is ALREADY applied: there the box starts ticked and the warning is
rendered without the toggle, because unticking it reverts the fee to the tier
price and reissues too, and a plain toggle would hide the warning at exactly
the moment it applies. wp-admin carries the same two sentences as a `notice
notice-warning inline`, always visible and below the fee fields, because that
screen also holds the fee tier select and so has a second way to change the fee.

**One email, not two.** The host gets the existing `user_payment_due`, with a
new `{fee_change_note}` placeholder filled in with "This replaces the earlier
invoice for £1,200.00, which has been cancelled and can no longer be paid." It
is empty on an ordinary approval. A body EDITED on the Emails screen beats the
registry default, and migration step 9 imported the Gravity Forms notifications
as stored overrides, so `law_setup_add_fee_change_note_to_payment_due()`
appends the tag to a stored body that lacks it, from both the
`?setup-account-pages` trigger and migration step 10 — a git push alone has to
be enough.

**A pre-existing bug fixed on the way.** In `law_event_admin_save()`,
`$before_override` held the fee override and was then overwritten by
`law_event_booking_override()` four lines later, so both readers of the fee
value ran on the booking override's string. Compared strictly against an int,
"have the fee inputs changed?" answered yes on every single Update, and
`law_event_log_fee_change()` wrote a spurious "override enabled" line each
time. Cosmetic until that same reader started voiding Stripe invoices. The
booking one is now `$before_booking_override`.

**Also widened**: `law_event_handle_retry_invoice()` accepted only Approved and
unpaid. A fee change can leave a **Confirmed** event unpaid with its old
invoice voided and the replacement not raised, which is precisely what the
retry button is for, and the `unpaid` test is what keeps a settled event from
getting a fresh "payment due" email. Confirmed now qualifies too.

**Two defects the live end-to-end run found, both fixed.**

1. *The host was never told the new amount.* The note first read only "This
   replaces the earlier invoice for £1,200.00, which has been cancelled". That
   is enough against the registry default, which quotes `{fee}` — but the body
   actually stored on the Emails screen **names no amount at all**, linking
   only to the Stripe invoice. So on the real site the host would have learned
   that an invoice had died and nothing about what replaced it. The note now
   leads with the new figure: "Important: the fee for this event has changed to
   £600.00. This replaces the earlier invoice for £1,200.00, which has been
   cancelled and can no longer be paid." It also cannot rely on its position,
   because the provisioning helper appends the tag to the END of a stored body
   rather than guessing a place inside somebody else's wording, so it has to
   read as a warning wherever it lands. **The committee may want to move
   `{fee_change_note}` higher up that body on the Emails screen**; it currently
   sits below their sign-off.
2. *The committee's "payment received" email quoted the wrong fee.* The
   `{event_summary}` block named the fee TIER and nothing else, and a tier
   label carries a price in its own words ("UK hosts: £1200 + VAT"). On the run
   above, an event whose fee had been changed to £600 and which had just paid
   £720 told the committee "Fee tier: UK hosts: £1200 + VAT" and no other
   figure. The summary now carries a `Fee:` row with the snapshot beside the
   tier row, added rather than replacing it because which tier an event sits in
   is a separate fact the committee reads for. The row is omitted before
   approval, where the snapshot is 0 for everyone and "Fee: £0.00" on a
   submission acknowledgement would be a promise nobody made. This affects
   **every** email carrying `{event_summary}`, not only the fee-change path.

**Verified live** (17 September 2026), not only in the unit suite: real Stripe
test-mode objects, the real webhook over `stripe listen` forwarding to
`/wp-json/law/v1/stripe-webhook`, and real mail into Mailpit. Two runs, 33/33
and 21/21 assertions after the two fixes above:

- *Paid path.* Approve → invoice #1 open at Stripe for £1,440.00 → fee changed
  to £600 → #1 `void` with a `voided_at` and #2 open for £720.00 → host gets
  one "payment due" email naming both figures and linking to #2 → pay #2 →
  `invoice.paid` webhook → Paid, Confirmed, published, fee override `locked`,
  **no amount mismatch** (the £720 reconciles against the re-frozen £600 + VAT
  snapshot, which is the whole reason step 2 re-freezes it) → host gets the
  paid confirmation, committee gets "payment received" → a further fee change
  is refused.
- *Waiver path.* Approve at the international tier → invoice open for £600 →
  fee waived to 0 → invoice `void`, **no replacement raised, no "payment due"
  email**, event Free and Confirmed in the same breath, host gets the *no fee*
  confirmation template and the committee gets no "payment received".
- Five webhook deliveries, all `200`. `invoice.voided` only logs, so the now
  routine fee-change void leaves the payment status and the event status
  alone — checked explicitly, since before this change a void only ever
  followed a cancellation.

One thing the run pinned that is worth knowing: **Stripe leaves
`amount_remaining` at the full original amount on a voided invoice** (144000 on
the £1,440 invoice). `status` and `status_transitions.voided_at` are what make
it unpayable, so never assert a void by reading the amount fields.

**Tests.** Seven in `tests/ServiceTest.php` (void-then-reissue with the order
asserted, the waiver confirming a Free event, a failed void abandoning the
change with the snapshot untouched, a settled fee refused with Stripe never
called, an unchanged figure touching nothing, the legacy-invoice refusal, and a
Free event given a fee again), the edit-mode matrix rewritten in
`tests/FeesTest.php`, and five in `tests/EmailsDashboardTest.php` (two for the
stored-override repair, three for the `Fee:` row appearing after approval,
being omitted before it, and stating a waiver as £0.00 with no VAT). 1046 tests
in the suite.

Touched: `functions/events/fees.php`, `functions/events/stripe/service.php`,
`functions/events/committee.php`, `functions/events/admin/event-screen.php`,
`functions/events/notifications.php`, `functions/events/statuses.php`,
`functions/setup-account-pages.php`, `functions/events/migration/runner.php`,
`templates/account-dashboard.php`, `tests/ServiceTest.php`,
`tests/FeesTest.php`, `tests/WorkflowTest.php`,
`tests/EmailsDashboardTest.php`.

## An event can be switched off altogether (17 September 2026)

**The request.** "In committee controls we need a checkbox at the top (should be
just small checkbox without any long description) that will disable the event at
all, so it's hidden from programme no matter what. On events archive list
dashboard such event should be tagged as a disabled with badge" (Denis,
17 September 2026).

**Not a status, and not Cancelled.** The event keeps the status it had, keeps its
fee snapshot, its Stripe invoice, its bookings and its rows in every committee
list; unticking the box puts it back exactly where it was. Cancelled stays the
status for an event that is not happening — it voids the invoice, emails the
host and cancels every attendee booking, none of which this does. This is for an
event that must not be *seen*, now or at all, while the record behind it stays
intact. It is stored as `_law_disabled`, a plain flag in the meta schema.

**"No matter what" is one predicate, not a list of surfaces.**
`law_event_is_disabled()` is read by `law_event_is_publicly_listed()` and
answers `false` before the status is even looked at. That predicate already
decides whether an event has a public page, whether its card links to the
permalink or to the committee's `?event=` view, whether it is in the `.ics`
feed, what `{event_link}` resolves to in an email, and whether Enable booking
may force booking open — so all of them were covered by the one tick, and there
is no second list of surfaces to keep in step with the first.

Three places needed their own limb:

- **The programme.** `law_events_map_post()` drops a disabled event whenever the
  caller asked for the public status set. One place rather than at each day
  grouping, slot bar and per-day count, all of which derive from that map. The
  committee's own programme (`$allowed = array()`) and the host dashboard
  (`array( '*' )`) still see it, because a switch that makes an event vanish
  from the screen you flipped it on is a switch nobody can find again.
- **Its own page.** Hiding it from the programme is not enough: the permalink is
  a published URL that anyone holding it, or arriving from a search engine, can
  open. `law_events_gate_disabled_event_page()` (a `template_redirect` at
  priority 3, its own hook and not the Members gate at 4, because the two
  refusals are unrelated and this one applies whether or not that plugin is
  installed) 404s it for everyone except the people who can act on it —
  `law_user_can_manage_event()`, so the host, the co-owners and the committee
  keep the preview.
- **Booking.** `law_event_booking_hold_reason()` answers `'event_disabled'`
  first, ahead of Disable booking, so it cannot be lifted by Enable booking and
  it reaches an **external** event too — `law_booking_resolve_state()` returns
  early on those, so the flag is recorded as a hold before that branch and the
  link out to the organiser goes dead with everything else.

**What the committee sees.** A single checkbox, "Disable this event (hide it from
the programme)", at the very top of the Committee controls panel above Override
booking availability, with a rule under it and no help text — one short label is
the whole of it, as asked. It posts under the existing `law_flags_present`
sentinel, so an unticked box still switches the flag off. The wp-admin Event
flags box carries the same control, first in the box for the same reason, so
"Full editing in wp-admin" is not a dead end. On the dashboard events table the
row carries a **Disabled** badge beside the reference — the one filled red pill
in the set, deliberately not the outline treatment `--external` uses, because
that one is an identity tag and this is the thing about a row that must not be
skimmed past when the status beside it still reads Confirmed. The same badge
sits beside the status pill on committee event cards. The booking line under the
select reads "This event is disabled, so it is off the programme and takes no
bookings", and the change is logged in plain words by
`law_event_log_flag_change()` ("Event disabled: it is hidden from the programme
and its own page, and takes no bookings" / "Event enabled again: …"). The
committee export gained a **Disabled** column, Yes/blank, next to Event status,
because a Confirmed row that is on none of the public surfaces would otherwise
export as an ordinary Confirmed row.

**Not carried by the content transfer bundle.** `_law_disabled` is deliberately
absent from `law_content_transfer_event_meta_keys()`. That list is a whitelist,
and the writer only touches the keys on it, so an import can neither switch an
event off nor switch one back on: this is a local committee decision about the
live site, like the status itself.

**Tests.** `tests/EventDisabledTest.php`, 15 cases: the flag defaults to off and
ignores non-events; a Confirmed, slotted, venued, bookable event drops off the
public programme while the committee and host maps keep it; it stops being
publicly listed and its link falls back to the committee view; the page 404s for
a visitor, renders for the host and the committee, and comes back on untick;
booking closes, Enable booking does not lift it, and an external event's link
out closes too; the panel note, the badge, the export column, the set/clear
round trip through the flags sentinel and the two log sentences. One further
case greps both handlers and the panel for the field, so the simulated write the
other cases use cannot drift from the real ones. 1068 tests in the suite.

Touched: `functions/events/meta.php`, `functions/events/statuses.php`,
`functions/events/source.php`, `functions/events/bookings.php`,
`functions/events/committee.php`, `functions/events/workflow.php`,
`functions/events/export.php`, `functions/events/admin/event-screen.php`,
`functions/account-bookings.php`, `functions/calendar.php`,
`templates/account-dashboard.php`, `parts/events/dashboard-list.php`,
`parts/loop/event.php`, `assets/css/calendar.css`, `assets/css/event-form.css`,
`tests/EventDisabledTest.php`.

---

## The ticket outlives the delegate named on it (21 September 2026)

The client asked for a way to swap the person holding a flagship place. The
case is ordinary and had no answer at all: a firm buys a ticket for a named
partner, the partner cannot come, and a colleague goes instead. The committee's
only route was `law_flagship_cancel_confirmed()` followed by
`law_flagship_add_complimentary()`, which released and re-sold the seat, threw
away the payment trail and recorded a paying delegate as a freebie.

**Four decisions settled it** (Denis, 21 September 2026). The money does not
move. Confirmed places only. Both people are emailed. The included reception
places move with the ticket.

**The money not moving is the decision everything else follows from.** No Stripe
call is made — no refund, no credit note, no re-invoice, and no patch to the
invoice metadata. `_law_stripe_customer_id`, `_law_stripe_payment_method_id`,
the invoice IDs and the charge ID all stay pointing at the original payer,
because that is what they record. `law_stripe_booking_metadata()` mirrors
`post_author` into `law_user_id`, so the invoice's copy is now stale, and it is
left that way on purpose: repointing it at somebody who paid nothing would
mislead whoever handles a refund. Nothing breaks, because the webhook resolves
by `law_booking_id` and refunds fall back to the stored charge ID. This is a
deliberate exception to the standing rule that a mirrored identifier is kept in
sync, so it is said out loud in the code rather than left looking like an
oversight.

**Two guards make that invariant real rather than incidental.** Three Stripe
functions resolve `law_stripe_user_customer_id( $booking->post_author )` and
then OVERWRITE `_law_stripe_customer_id` with the result. Running any of them on
a substituted booking would destroy the record of who paid and could raise a
charge against a person who never consented to one. None was reachable — the
card form is hidden on a confirmed place and `law_flagship_retry_charge()`
requires a failed payment — but that was an accident of two status checks, so
`law_flagship_retry_charge()` and `law_stripe_create_setup_session()` now refuse
a substituted paid booking outright.

**`post_author` is what moves.** It is the canonical attendee throughout the
module: `law_user_booking_ids()` queries by it, `law_booking_attendee()` reads
it, the duplicate and clash guards index it, and
`law_reception_grant_included()` checks it. Rewriting only the
`_law_attendee_*` snapshot would have left the ticket in the wrong person's
account while claiming to be somebody else's. `wp_update_post()` is passed those
two keys and nothing else, so `post_status` is unchanged, `workflow.php`'s
status guard is a no-op and `transition_post_status` does not fire — and
`$GLOBALS['law_booking_transitioning']` is deliberately not raised, since
disarming the status guard for a change that does not touch the status would
only widen the window. The one thing the event lock is held for is a re-read:
between the guards and the write, another committee member's Cancel could have
moved the same place. There is no recount, because one seat out is one seat in.

**`law_booking_write_attendee()`'s fourth argument matters here.** It DELETES
`_law_is_press` unless a press flag is passed, so a press pass would have
vanished silently. It is a property of the seat, so the dialog carries it over
and lets the committee untick it.

**The receptions move in place, and that is not the obvious implementation.**
`law_reception_revoke_included()` followed by `law_reception_grant_choices()` is
wrong three ways: it emails the original "the flagship place it came with is no
longer confirmed", which is untrue here; it silently drops any reception that
has already happened, because the grant refuses anything outside
`law_reception_included_ids()`; and the revoke calls `law_booking_cancel()`,
which ends in `law_waitlist_process()`, so somebody queued can be seated into
the place between the revoke and the grant — and the grant has no capacity guard
by design, so the room over-books. `law_flagship_move_included_receptions()`
re-authors each place instead, which changes no headcount and involves no
waitlist at all. The one case that does release a place is a substitute who
already bought their own ticket to that reception: theirs is kept, the included
one is cancelled so nobody holds two, and the committee is told, because nobody
has been refunded for the one they bought.

**The new delegate must not see the payer's facts.**
`parts/events/flagship-manage-application.php` gates on
`post_author === get_current_user_id()` and renders the payment-method label and
the Stripe invoice URL; `parts/events/booking-payment-facts.php` renders those
plus the PDF. Moving `post_author` would therefore have put the original payer's
hosted invoice — carrying their name, billing address and card last four — one
click from the substitute's My bookings. `law_booking_payment_facts_visible()`
(`bookings.php`) answers on the PAYER rather than the holder, both partials
blank the three values through it and print one line instead, and the
committee's own surfaces are untouched, because tracing a refund is what they
exist for.

**One new template, not two** (Denis, 21 September 2026, correcting the first
cut, which had written one for each end of the transfer). The test is what state
the recipient ends up in, not which feature put them there: somebody substituted
onto a confirmed ticket is simply a confirmed delegate, so they get
`user_flagship_approved`, the same email everybody else with a confirmed place
gets. Only the person LOSING a place lands somewhere the registry had no wording
for — "you no longer have a ticket, and here is where your receipt went" — so
`user_flagship_place_transferred` is the single entry the feature adds.

**What lets one template serve both is `{payment_note}`.** The approval email had
the money hard-coded into its body — "We have taken {price_total} from your saved
payment method ({payment_method})… Your VAT invoice is here: {invoice_link}" —
and every clause of that is false for somebody a place was transferred to: they
paid nothing, and that invoice names a different person. All of it moved into one
resolved paragraph (`law_flagship_payment_note()`), which says what was charged
on an approval and "this has already been paid for, and the receipt stays with
whoever bought it" on a transfer. It is a whole paragraph rather than four tags
because `law_events_email_render_body()` substitutes in a single `strtr()` pass,
so a `{invoice_link}` nested inside another tag's value would reach the reader
printed literally — the `{account_note}` idiom exactly. The opening line lost
"has been approved" in the same change and that sentence moved into the note, and
`{account_note}` joined the body so a transfer to somebody who has never signed
in still carries their set-password link in the one email.

**The payment tags are blanked on a transfer as well as unused.**
`law_flagship_email_extra()` supplies `{invoice_link}`, `{price_total}`,
`{payment_method}`, `{card_label}`, `{discount_note}` and
`{update_payment_link}` for every flagship email, and each describes the payer
rather than the reader. The template not naming them is not enough on its own: an
environment whose stored override still names one by hand would hand the payer's
hosted Stripe invoice — their name, billing address and card last four — to a
person who paid nothing. So the note carries the right sentence and the blanking
makes the wrong one impossible.

**`{receipt_note}`, because a promise of a receipt is not always keepable.** The
transfer email first shipped with a bare `{invoice_link}`, and on the first real
transfer Denis tried it — a ticket a discount code had covered in full — it
rendered "you can download it here at any time:" followed by nothing, because a
code-covered ticket raises no Stripe invoice at all. `law_flagship_receipt_note()`
resolves three ways instead: the link when there is one, an offer to send a
receipt by hand when money was taken but no invoice exists, and "there was
nothing to pay on this place" when there was not. A sentence that cannot be
completed is a different sentence, not a broken one.

**A pre-existing bug fell out of this.** `law_booking_delete_created_users()`
iterates `array_keys( $created_ids )` and every caller in `bookings.php` passes a
map keyed by user ID — but `law_flagship_add_complimentary()` passed a bare
list, so `array_keys( array( 0 => 123 ) )` is `array( 0 )`, the call was
`wp_delete_user( 0 )`, and the account survived every refusal. Adding a
complimentary place for an email that already held one left an orphan behind
every time. Both call sites are fixed, and the function now refuses a list
outright rather than quietly doing nothing, because the failure it replaces was
invisible.

**The UI.** The actions column already existed, so Substitute joins Approve,
Decline, Retry charge, Resend request and Cancel rather than getting a column of
its own. ONE dialog serves the whole table
(`parts/events/flagship-substitute.php`, rendered outside `#law-cal-events` so a
filter swap cannot destroy it), because the form carries fifteen fields and a
copy per row on a list that runs to hundreds would be most of the page.
`assets/js/flagship-substitute.js` fills it from the pressed row on the capture
phase, and does two things the ticket-type script does not: it resets the form
BEFORE writing the booking id (the other order clears the id it just set), and
it leaves the new delegate's fields empty, because pre-filling them from the
current holder would make the quickest path through the dialog substitute a seat
to the person already in it. Without JavaScript there is no dialog to open and a
form asking for a post ID would be dishonest — the committee knows registration
numbers — so each row's `<noscript>` links to `?law_substitute={id}` and the
template renders the same form inline and pre-filled above the table. The
delegate's name cell gains a "Substituted for X, who paid" sub-line, the exports
gain a Substituted from column, and the wp-admin facts box gains a row naming
the payer, because that screen is where a receipt query is answered.

**Audit meta**, all five in `law_booking_meta_schema()`:
`_law_substituted_from`, `_law_substituted_from_name`,
`_law_substituted_from_email`, `_law_substituted_at`, `_law_substituted_by`. The
name and the address are frozen rather than looked up — the name because the
account may be deleted and leave a bare integer nothing can render (the lesson
`law_booking_invited_by_label()` already pays for), the address because it is
what finds the Stripe customer months later without reading the log. They hold
the most recent hop only; the chain lives in the activity log under
`flagship_substituted`.

**What three specialist reviews found, and what changed** (21 September 2026,
an implementation, a UX and a security pass run in parallel). Every finding
below was reproduced before it was fixed.

- **A second transfer named the wrong payer.** The five `_law_substituted_*`
  keys were rewritten on every substitution, so a ticket handed on twice
  recorded the FIRST substitute — who paid nothing — as the payer. That
  inverted `law_booking_payment_facts_visible()` outright: the person who
  bought the place lost sight of their own receipt, the person who had paid
  nothing gained it, the wp-admin facts box named the wrong person in writing,
  and the middle holder's transfer email handed them a link to the payer's
  hosted Stripe invoice. The three keys that describe the MONEY are now written
  once and never again; `_law_substituted_at` and `_law_substituted_by`, which
  describe the most recent transfer, still move.
- **They are written BEFORE `post_author` moves.** Writing them afterwards left
  a window in which a fatal inside `wp_update_post()`'s hooks stranded the
  booking owned by the new delegate with no marker, and every payment fact on
  their own page for good. Written first, the same crash hides a receipt from
  the person who paid until somebody notices. Wrong in the harmless direction.
- **A substitute merely QUEUED for an included reception lost the free place and
  was charged for it.** `law_reception_holds_place()` counts a waitlist entry
  and an unfinished checkout as holding a place, so the included place was
  cancelled; that fed `law_waitlist_process()`, which promoted the substitute
  off the very queue they were on and charged them for a reception their
  transferred ticket already included. `law_flagship_holds_reception_place()`
  is the narrower test: a place someone really has is a confirmed one.
- **The "is this a transfer" branch failed open.** It tested whether the
  previous delegate's name was non-empty, so a booking whose snapshot name and
  account address were both blank sent the new delegate the APPROVAL paragraph
  — invoice URL, card, price and all. It now takes an explicit flag, and the
  nameless case has wording of its own.
- **The charge guard moved to the choke point.** It was three status checks in
  three files; it is now a refusal inside `law_stripe_charge_booking()` itself,
  so every future caller is safe by construction. The setup-session guard lost
  its `'paid'` conjunct, which bought nothing.
- **A refunded place is refused outright.** It stays on `publish`, so it
  reached the transfer looking transferable, and every sentence the emails would
  have said about the receipt was false.
- **The payer's discount code was still on screen.** Both delegate-facing
  partials hid the invoice and the card and neither hid the code, which is the
  payer's negotiated commercial term and reusable by whoever reads it — while
  the substitution email already withheld it. `law_booking_payment_facts_mask()`
  is now the one list both partials read, so they cannot drift again.
- **The duplicate guard is repeated inside the lock**, and the lock is now
  refused rather than skipped when `GET_LOCK` is not granted, since the re-read
  is the only thing it protects.
- **The log is written before the best-effort work**, not after it: a fatal
  inside the reception move used to leave the seat transferred, the meta
  written, nobody emailed and nothing at all in the one surface the committee
  reads.
- **Copy.** The receptions sentence was written in the delegate's second person
  and pasted straight into the committee's confirmation and the log, telling a
  committee member a reception was in *their* bookings; it now takes a
  third-person form. "Substituted for X, who paid" asserted payment about
  complimentary and code-covered places and could be read in either direction,
  and is now "Replaced X" with the receipt clause only where money moved. "Both
  of them have been emailed" is no longer asserted when the previous delegate
  has no usable address. `{payment_note}` moved to the top of the confirmation,
  because a lawyer who never registered was reading four paragraphs of date and
  venue before anything explained why the email existed. Two em-dashes went.

**Tests**: `tests/FlagshipSubstituteTest.php`, 47 cases. The ones that earn
their keep are the whole `_law_stripe_*` set asserted unchanged in one go
alongside an empty `$GLOBALS['law_test_stripe_calls']`; the waitlist case, where
a queued person must NOT be promoted by a reception place changing hands; the
orphan-account case, which asserts the account is gone rather than that an error
came back, and is what would have caught the rollback bug above; the two access
cases on `law_booking_payment_facts_visible()`; and the concurrency case, which
writes the row behind WordPress's back and fails without the
`clean_post_cache()` that makes the re-check under the lock a real read rather
than a comparison of this request's cached copy against itself; and the pair on
the duplicate guard, which walk every status that holds a place and both of the
two that do not; and the two on the receipt sentence, one of which reproduces the
code-covered ticket whose email ended on a colon. 1153 tests in the suite.

Touched: `functions/events/flagship-bookings.php`,
`functions/events/flagship-bookings-dashboard.php`,
`functions/events/bookings.php`, `functions/events/meta.php`,
`functions/events/notifications.php`, `functions/events/stripe/attendees.php`,
`functions/events/admin/booking-screen.php`,
`parts/events/flagship-substitute.php`,
`parts/events/flagship-bookings-list.php`,
`parts/events/flagship-manage-application.php`,
`parts/events/booking-payment-facts.php`,
`templates/account-dashboard-flagship-bookings.php`,
`assets/js/flagship-substitute.js`, `assets/css/calendar.css`,
`tests/FlagshipSubstituteTest.php`.

---

## A paid event that nobody knew was paid (21 September 2026)

Denis found event 5979 (Geopolitical Tensions -- Impact on International
Arbitration and Energy Security) reading Unpaid on the committee dashboard
while its Stripe invoice was plainly paid, and asked where that could come
from.

**The migration did not get it wrong. It copied a record that was already
wrong.** Event 5979 came from form 2 (Event > submit an event) entry 1222,
where field 95 (Event status) reads "Approved" and field 96 (Payment status) is
empty. `law_migration_derive_payment()` (`migration/runner.php`) derives payment
purely from field 95 -- Confirmed means paid, anything else means unpaid --
because field 96 was blank on every active entry and no legacy workflow step
ever wrote it (EVENTS.md, "Known defects", item 1). The event's own activity log
says so: *"Migrated from Gravity Forms entry 1222. Status Approved carried over;
payment status \"unpaid\" was derived (field 96 was blank, defect 1)."*

**Why field 95 was stale.** It only reached "Confirmed" when Gravity Flow step
23 (Set status to Confirmed) ran, and step 23 only ran when step 20 (Waiting for
payment), an incoming-webhook step, was released by Make scenario B calling in
on `invoice.paid`. Where that call was lost the entry sat at step 20 for ever.
The entry meta proves it: entry 1222 has `workflow_step = 20`,
`workflow_step_status_20 = pending`, `workflow_final_status = pending`. Across
the whole local copy, **all 33 events now reading Approved and unpaid are parked
at step 20, and all 21 reading paid are complete.** So the divide between "paid"
and "unpaid" in the migrated data records whether Make succeeded, not whether
anybody paid, and the only place the truth exists is Stripe.

**What it costs is not a wrong label.** Paying is what confirms and publishes an
event, and `law_booking_guard_open()` gates booking on `post_status ===
'publish'`. A host who has paid has an event sitting on the programme reading
"Open soon" that cannot take a single booking, while the Payment column invites
somebody to chase them for money they have already sent.

Four things were built for it, on Denis's instruction to implement all of them.

**1. The comparison engine, `functions/events/stripe/reconcile.php`.**
`law_stripe_reconcile_check()` reads the invoice behind an event -- one GET when
it holds its `in_…` ID, otherwise falling back to
`law_events_invoice_id_lookup()` in `repair-stripe-invoice-ids.php`, so the two
files cannot disagree about what counts as proof that an invoice is this
event's -- and returns one of six verdicts: `agreed`, `paid_not_recorded`,
`recorded_paid_not_settled`, `recorded_paid_written_off`,
`paid_after_cancellation`, `no_invoice`.

**Exactly one verdict is settled automatically, and the asymmetry is the
design.** `paid_not_recorded` is healed; everything else is reported and left
alone. Unpublishing a live event because its invoice reads `void`, or reversing
a payment status, is a decision with attendees and money on the other side of
it. A `refunded` event counts as settled against a `paid` invoice, because a
refund goes out through the charge and leaves the invoice `paid` -- without that
the sweep would re-confirm and re-publish every event the committee had
refunded.

**2. Settling goes all the way, not just to the meta.**
`law_stripe_reconcile_settle()` walks the same path
`law_stripe_handle_invoice_paid()` walks: record the invoice and customer IDs if
they are missing, reconcile the amount against the approval snapshot (logged
loudly, never blocking, because the money genuinely arrived), capture the charge
for later refunds, set the payment status, and run the `confirm` transition,
which publishes and opens booking. A repair that corrected the Payment column
and left the event unpublished would have fixed the symptom nobody was hurt by.

**3. The emails are the one thing that differs between the two callers, and it
needed a new mechanism.** `law_events_without_emails( $reason, $callback )` in
`notifications.php` mutes the module's own registered sends for the duration of
one operation -- deliberately not a `wp_mail` filter, so a password reset in the
same request still goes out -- with try/finally so a fatal cannot leave the site
silently unable to email anybody. A muted send still writes its activity-log
line saying it was suppressed and why, which is both the module's
exhaustive-logging rule and the committee's list of hosts to contact by hand.
The daily sweep emails (a delivery missed hours ago owes the host their
confirmation); the migration panel does not by default (a payment banked in
August does not want "thank you for your payment" arriving today).

**4. The two callers.** `migration/reconcile-payments.php` is the one-off panel
on LAW → Migration, over the legacy events, batched ten at a time, dry until the
Settle button, re-reading Stripe on apply rather than trusting the rendered
page. The daily sweep in `reconcile.php` (`law_events_payment_reconcile`, WP
Cron, switchable on Events → Settings) watches only events holding an invoice
ID, because a legacy lookup costs a search plus a customer's whole invoice list
and can come back ambiguous. **So the order is: run "Repair: legacy Stripe
invoices with no invoice ID" first, then the reconciliation panel, after which
every one of those events is inside the sweep.** A discrepancy needing a human
is alerted ONCE per event per verdict (`_law_reconcile_flagged`), because an
alert that arrives every morning until somebody has time is an alert everybody
learns to delete.

**The endpoint check, `functions/events/stripe/health.php`, is the fifth thing
and arguably the most valuable.** Everything the module knows about money
arrives as a webhook, and an endpoint that is missing, disabled, pointed at the
wrong address or subscribed to the wrong set of types fails in complete silence
-- Stripe's own dashboard shows nothing wrong, because from its side nothing is.
`law_stripe_webhook_health()` asks Stripe what it actually has and compares it
against `law_stripe_webhook_event_types()`, the new canonical list of the
thirteen types `law_stripe_webhook_dispatch()` and
`law_stripe_webhook_booking_outcome()` act on. Adding a case to either means
adding it to that list and nowhere else; the panel prints the matching
`stripe listen --forward-to … --events …` command, so the local forwarding
Denis runs cannot silently drift from what the code handles either. It names
the right-path-wrong-host case explicitly (a staging endpoint left pointing at
production is the commonest way this breaks), and it reports "no endpoint" on a
test key as expected rather than alarming, because `stripe listen` registers
none.

**The ambiguous case got a picker (21 September 2026).** Denis asked how to
resolve the events where the lookup finds more than one invoice. The honest
answer was that the panel's own advice, "pick the right one in the Stripe
dashboard and set it by hand", was a dead end: `_law_stripe_invoice_id` is
display-only on the wp-admin event screen, so the only route was a database
write.

Worth naming precisely when the case arises, because it is narrower than it
looks. Two invoices cannot share a `hosted_invoice_url`, so `$by_url` can never
hold more than one and a URL hit always wins outright. **Ambiguity is therefore
always the metadata route: two or more invoices carrying the same
`gf_entry_id`, none of them carrying the address stored on the event** --
normally because Make raised the invoice twice and field 83 (Stripe invoice URL)
holds the address of one since deleted or superseded.

So `law_events_invoice_id_lookup()` now returns the candidates it refused to
choose between (`candidates`, paid first then newest, through
`law_events_invoice_id_candidate()`), the panel renders them as radio buttons
with status, amount, date, contact and a link into Stripe, and
`law_events_invoice_id_apply()` takes an `event_id => invoice_id` map.
**Choosing resolves an ambiguity; it does not waive the evidence.**
`law_events_invoice_id_verify_choice()` re-fetches the chosen invoice, puts it
through the same `law_events_invoice_id_match()` test the automatic route uses,
and refuses it if it carries neither this event's URL nor its entry ID or if
another event already holds it. Nothing is preselected, because a default here
would be a guess wearing a tick. The log line says "chosen by hand from
several", so the record distinguishes a decision from a match.

The reconciliation panel names the candidates in its notes and points at the
invoice ID panel rather than reporting `no_invoice` as a dead end.
`tests/LegacyInvoiceIdRepairTest.php` gained four cases (the refusal returning a
choice, a chosen invoice recorded in one fetch, one belonging to nobody, one
another event already holds); 14 there, 1157 in the suite.

**The panel could not advance (21 September 2026).** Denis found it looping:
check ten, settle, and the same ten came back. A settled event leaves the scan
because its payment status changes, but a row that comes back `agreed` -- an
open invoice against an unpaid event, which is most of them and is the correct
answer -- stays in the scan for ever, and the batch always took the first ten.
The invoice ID panel has no such problem because a repaired event drops out.
`law_events_reconcile_scan()` now stamps `_law_reconcile_checked_at` on every
event it reads and orders never-checked first then oldest first, so each press
takes the next ten and pressing repeatedly walks the whole list. The list shows
the last-checked time, and the button's description says that events agreeing
with Stripe stay listed because there is nothing to do about them.

**Scope deliberately not widened.** Attendee bookings (flagship applications,
reception places) hold their own `_law_stripe_invoice_id` and would reconcile
the same way, but their payment states are a longer vocabulary with their own
handler table, so that is a separate job rather than something half-done here.

**Tests.** `tests/PaymentReconcileTest.php` (21) walks every verdict, both
settle paths, the mute being lifted after a throw, the amount mismatch that
reports without blocking, the legacy event gaining its IDs as it settles, the
sweep's scope, and the alert that fires once and not twice.
`tests/WebhookHealthTest.php` (8) covers ok, wildcard, a missing type, disabled,
no endpoint, the wrong host, and a Stripe error not being misreported as a
broken endpoint.

**Still outstanding for Denis.** The endpoint check has only been run here
against the local test key. The check that matters is the one run on production
against the live key, and it should be run before the reconciliation panel,
because an endpoint not subscribed to `invoice.paid` would go on producing this
defect for the remaining events whatever the panel repairs today.

Touched: `functions/events/stripe/reconcile.php` (new),
`functions/events/stripe/health.php` (new),
`functions/events/migration/reconcile-payments.php` (new),
`functions/events/stripe/webhook.php`, `functions/events/notifications.php`,
`functions/events/settings.php`, `functions/events/migration/page.php`,
`functions/events/_load.php`,
`functions/events/migration/repair-stripe-invoice-ids.php`,
`tests/PaymentReconcileTest.php` (new), `tests/WebhookHealthTest.php` (new),
`tests/LegacyInvoiceIdRepairTest.php`.

---

## A test the committee can read in its own inbox (22 September 2026)

**"Send a test to committee", beside "Send a test to me", on every notification
whose audience is the committee.** A committee notification is almost always
being reworded on behalf of the people who receive it, and a preview that only
ever lands in the editor's own inbox cannot show them how it reads there
(Denis, 22 September 2026). The new button sends the wording AS TYPED, saving
nothing, to the address list in Events → Settings: one `wp_mail()` addressed
to the whole list, exactly the way `law_events_send()` addresses the real
thing, so the test is a true preview of that too.

**Where it is offered.** On the registry AUDIENCE, `'committee' === $email['to']`,
and nowhere else: on a host or attendee notification the committee list is not
an audience the email ever reaches, so a test to it would be a preview of
nothing. That is the rule on both screens, and
`EmailsDashboardTest::test_the_committee_test_is_offered_on_both_screens_and_only_where_it_means_something()`
holds them to it. The front-end handler re-checks the audience rather than
trusting the posted button, and both screens refuse the send with an
explanation when the address list is empty, pointedly, because an empty list
means the real notification is reaching nobody either.

**One helper, both screens, as with everything else here.**
`law_events_email_send_test()` now delegates to a new
`law_events_email_send_test_to( $override, $emails )` in `notifications.php`,
and `law_events_email_send_test_committee()` is that function over
`law_events_committee_emails()`. The single-recipient return still carries
`'email'`, which is the key both screens' confirmations have always read, with
the full list alongside it as `'emails'`. Both new functions are in the
reflection list `test_both_screens_share_one_write_path()` walks, so neither can
drift into a screen. The committee send spends the same tighter `email_test`
rate budget as the test to oneself: it is the more expensive of the two, so it
must not have a budget of its own to spend.

**"Reset to the standard wording" is now a small text link under the buttons**
rather than a fourth filled button in the row (Denis, 22 September 2026). It is
the rarest action on the screen and the only one that throws work away, so it
should not compete with Save for attention. The same treatment on the
per-event confirmation override (`parts/events/committee-email-override.php`),
which carries the same control. The new `.law-text-button` utility in
`event-form.css` is the shape `.law-file-clear` already used (plain underlined
text on `--law-form-destructive-soft`), as a class of its own because
`.law-file-clear` carries the file control's margins and its `[hidden]` rule.
Both palettes define that token, so the light dashboard gets the darker red
without a `--light` override; the hover state thickens the underline rather
than changing a colour that only the dark palette would show. Behind the same
shared confirm dialog as before, and still a plain submit without JavaScript.

Touched: `functions/events/notifications.php`,
`functions/events/emails-dashboard.php`,
`functions/events/admin/emails-screen.php`,
`parts/events/emails-manage.php`, `parts/events/committee-email-override.php`,
`assets/css/event-form.css`, `tests/EmailsDashboardTest.php`.

---

The companion EVENTS_4.1_REBUILD.md remains the design contract;
this document maps that design onto the code as built.
