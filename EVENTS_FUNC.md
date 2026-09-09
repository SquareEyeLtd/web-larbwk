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
(submission, moderation, invoicing) and the 4.2 attendee side (bookings and
the waitlist). What the code does is described in the sections below; when
each part arrived is in **Change history** at the end.

The design contracts are EVENTS_4.1_REBUILD.md (the rebuild), EVENTS_BOOKINGS.md
(the attendee bookings slice) and WAITLIST.md (one booking per attendee, and the
waitlist); this document maps them onto the code as built.

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
co-owners → **ics** → **bookings** →
**test-mode** → notifications → speakers → **speakers-dashboard** →
**flagship** (+ **flagship-form**, **flagship-dashboard**) → source → edit-lock → submission-form → registration → committee
→ export → Stripe (client, service, webhook) → admin (fields,
event/booking/speaker/session/**flagship** screens, columns, emails) →
migration (report, runner, page, repair-owners, backfill-session-agenda).

### `settings.php`: the settings store and the LAW submenu host

- `law_events_settings_defaults()`, `law_events_settings()`,
  `law_events_setting()`, `law_events_update_settings()`: one option,
  `law_events_settings`, holds the fee tiers, the programme year, the date/time
  slots, the committee recipient emails, the Stripe `tax_rate_id` and
  `rendering_template_id`, and the reserved `host_edit_review` mode.
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
  `law_events_register_law_subpage()`: register the "LAW > Events settings"
  screen. **Priority 999 matters** — ACF creates the top-level LAW menu late,
  so the module's submenus must register after it or they never attach. This
  helper is the shared, AME-proof registrar used by all three module screens
  (settings, emails, migration).
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
  select is only rendered when `law_events_venue_details_visible()` says so, so
  on the host form it appears only for a host who already has a venue.
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
    `?law_run_by=` filter and the "Run by LAW" export column.
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
    `repair-owners.php`. Without it those events would have an open agenda
    section (the gate's "or has sessions" limb) and still be missing from the
    "With an agenda" filter, so the switch and the filter would mean different
    things. `_law_is_law_event` needs no backfill: its filter's
    `NOT EXISTS OR != '1'` pair is correct unconditionally.


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

### `meta.php`: the meta schema and the single read/write path

- `law_event_meta_schema()`, `law_speaker_meta_schema()`,
  `law_session_meta_schema()`, `law_booking_meta_schema()`: the ~50 meta keys
  and their types (text, `text_array`, `int_array`, `address`, `people_rows`,
  `speaker_rows`, `consent`, `stripe_error`, etc.). Every key
  is registered via `register_post_meta` in `law_events_register_meta()` (on
  `init` priority 7) with a per-type sanitiser and an auth callback. Bookings
  phase 1 added `_law_tickets_sold` (the recalculated seat counter) and
  `_law_capacity_warned` (the one-shot host warning latch) to the event
  schema. Bookings carry `_law_booking_number`, `_law_booked_by`, the four
  `_law_attendee_*` snapshot keys, `_law_is_press` and the four
  `_law_waitlist_*` keys (WAITLIST.md §A7, §B1). The old `_law_attendee_rows`
  array, its `attendee_rows` sanitiser and the flat `_law_booking_attendee`
  index were removed with the per-attendee rebuild.
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
- `law_events_bump_counter( $option )` and `law_events_next_reference()`: the
  shared counter helper (get → increment → `update_option`, **not atomic** —
  two genuinely simultaneous callers could mint the same number; known,
  accepted at current volumes) and the LAW reference built on it (e.g.
  `LAW26-00212`, seeded from the legacy max at migration).
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
  dashboard and the wp-admin repeater. It drops `<script>`/`<style>` blocks
  **contents and all** first — `wp_kses()` removes only the tags and would
  leave the code behind as visible text — and returns `''` for an emptied
  editor, which posts `<p>&nbsp;</p>` rather than an empty string.
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
  `upload_files` to the committee for speaker photos. **Host-side roles get
  nothing** — `event_host`, `sponsor` and `attendee` hold only `read`, so hosts
  never reach the wp-admin event screens; all host access runs through
  `law_user_can_manage_event()` instead, and the front-end form writes posts
  and meta through functions that do not check caps.
- `law_user_can_manage_event()`: the per-event gate — the author, any co-owner
  (`_law_co_owner_ids`), or a committee user. Used by every host-facing handler
  and the edit form.
- `law_user_is_committee()`: wraps the `edit_others_law_events` check; the
  canonical "who is committee" test.
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
- `law_event_fee_override_locked()` (9 September 2026): true once
  `_law_approved_at` is set, i.e. once the fee has been snapshotted and the
  invoice raised from that snapshot. Nothing recalculated the fee afterwards,
  so the committee dashboard's override control used to accept a
  post-approval change, report success and leave the snapshot, the invoice and
  the `{fee}` emails on the old figure. The control now renders read-only from
  approval onwards (with the current override stated) and
  `law_committee_action_handler()` refuses and logs the write, so wp-admin is
  the single post-approval fee route.
- `law_event_resnapshot_fee()` (9 September 2026): re-freezes the snapshot
  after a wp-admin fee edit on an approved event, logged as
  `fee_resnapshot`, so that route actually works. It returns a `WP_Error`
  (`law_fee_settled`) when the payment status is already `paid` or `refunded`:
  a settled fee is a bookkeeping record, and rewriting the snapshot under it
  would only make the webhook's `invoice.paid` reconciliation lie. Stripe is
  never touched automatically; the screen tells the admin to void the open
  invoice and raise a new one.
- `law_events_format_pence()`: "£1,200.00" formatting.

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
  an already-approved event), turns each co-owner row into access — creating an
  `event_host` account for a new email, or linking an existing account. Every
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
  `role`, default `event_host`, and an optional `job_title` — the bookings
  engine passes `attendee`) and sends **nothing** — the welcome is
  the caller's job so it can carry the event's context.
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
"self-booked" means. Statuses are `publish`, `law-waitlisted` and
`law-cancelled`.

A booker's **party** on an event is derived, never stored:
`law_booking_party()` unions the bookings they author with the ones they
booked for other people. That is what the manage view, the colleague cap and
"cancel everything I booked" all read. (Before 8 September 2026 a party was
ONE booking carrying an attendee rows array; that model, its flat
`_law_booking_attendee` index and the `attendee_rows` sanitiser are gone.)

- **Front-end surfaces**: `functions/account-bookings.php` (the six-state
  control, `law_account_bookings()` grouped per event, the shared notice map
  `law_booking_notice_text()` / `law_booking_notice_render()`, the counts label,
  the form-state transients, the enqueues), `parts/events/booking-modal.php` /
  `attendee-repeater.php` (a `mode` arg switches the same form between booking
  and joining the waitlist) / `booking-manage.php` (`?law_booking=`; the
  addressed booking resolves the event, and the view then shows the viewer's
  whole party there, with per-row cancel, add-a-colleague and cancel-all behind
  confirm modals) / `booking-list.php` (`?law_event_bookings=`,
  `law_user_can_manage_event()`; a FLAT table, one row per booking, which is one
  row per attendee — no grouping, just an "Invited by {name}" tag on a
  colleague's row (Denis, 8 September 2026) — with live country, dietary and
  accessibility from `law_profile_values()`, per-attendee Reject, the waitlist
  section, and the CSV/Excel/PDF export trio). The waitlist section is the one
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
  view, and the host/committee "Register an attendee" form on the bookings
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
  email they get and, for a waitlisted booking, the waitlist wording. It
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
  (`law_user_can_manage_event()` on `post_parent`) and
  `law_booking_register_attendee`. `law_booking_error_payload()` is the shared
  row/field refusal shape booking-form.js marks in place.
- **The event-cancel sweep** `law_bookings_cancel_all_for_event()` cancels the
  waitlist FIRST and suspends promotion for its duration, because it also runs
  from `wp_trash_post` while the event is still published; otherwise a freed
  place could promote somebody onto an event being deleted seconds later.
- Tests: `tests/BookingsTest.php` (26), `tests/BookingEmailsTest.php` (10),
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

- `law_events_email_registry()`: all 50 module emails as definitions (slug →
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
    `committee`, assignee-first via the send call), both once per submission;
    `user_attendee_invited` / `user_attendee_added` (.ics attached; each names
    who booked the place and carries that person's own number);
    `user_booking_registered` / `_invited` (registered on their behalf); the
    per-context cancellation family `user_booking_rejected` /
    `user_booking_cancelled_by_booker` / `user_booking_cancelled_self` /
    `user_booking_event_cancelled`; the registration welcome pair
    `user_welcome_registered` (attendee copy) / `user_welcome_registered_host`
    (event host or sponsor: leads with `{submit_link}` and asks for dietary and
    accessibility requirements only if they also book a place), chosen by
    `law_registration_welcome_slug()` and both event-less, so unlogged; and
    `host_capacity_warning` (to `host`, one-shot latch).
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
- `law_events_email()`: the registry entry with any admin override merged in
  (overrides live in one option, editable on the Emails screen).
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
    people.
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
  featured image at read time.
- **Speaker roles are per appearance too** (Trevor, 3 September 2026; built
  8 September 2026): the row's `role` slot, reserved since 4.1, now holds what
  the person was at THIS event. `law_speaker_roles()` is the vocabulary
  (`speaker` / `host` / `moderator`, in the order the selects offer),
  `law_speaker_role_key()` normalises a key or a label in any case to the key
  (`''` for anything unknown), `law_speaker_role_label()` is the label, and
  `law_speaker_role_display()` is what a listing prints after the name — `''`
  reads as Speaker, the default, and prints as such on every card (Denis,
  8 September 2026), so it is the one line to change should the default ever
  be suppressed. `law_speaker_appearances()`,
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
- `law_speaker_post_profile()`, `law_event_speaker_cards()`,
  `law_speaker_card()`: the profile and card shapes the archive/single views
  render. The profile carries `appearances`; a card takes its values from the
  row, and a session row falls through to the parent event's row for the same
  speaker, then to the speaker post for the photo and biography. The card's
  photo is the `medium` size, since the single event view renders it at 5.5rem.
  `templates/speaker.php` shows no headline organisation/job title **and no
  biography**: each "Speaking at" card (`parts/loop/event.php`, `speaker` arg)
  renders a divider and "[name]'s role: …" / "[name]'s organisation: …" /
  "[name]'s position: …", and the biography is on the event page's speaker
  cards.
- `law_speaker_bio_excerpt()` (24 words, an explicit `…` because
  `wp_trim_words()` otherwise appends the `&hellip;` entity, and
  `strip_shortcodes()` because an appearance biography never passes through
  `the_content`) and `law_speaker_bio_summary()` (the full plain text, the
  excerpt, and whether the excerpt trimmed anything — the card offers "Read
  full bio" only when it did). `law_speaker_seo_description()` reuses the
  excerpt.
- `law_speaker_dialog_register()` / `law_speaker_dialogs()`: the request-scoped
  registry behind the single event view's biography dialogs. A speaker card
  registers and gets an element id back; `parts/calendar-body.php` prints the
  registered dialogs once, after the speaker and session sections. A registry
  rather than an index threaded through the templates because the cards render
  in two places (the event's Speakers list and each session panel) and every
  dialog must land **outside** the sessions accordion: a closed `<details>`
  renders nothing, so a dialog inside one could never open. One dialog per
  card, not per speaker, since two session rows for the same person can
  legitimately carry different biographies.
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
  **generalised** `law_setup_dashboard_child_access( $path )` — the bookings
  helper hardcoded one path and there are now two children, so the body moved
  and `law_setup_bookings_dashboard_access()` became the other wrapper.
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
  `assets/css/event-form.css`. pdfmake loads footer-side for committee only.
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
- `law_events_post_is_sponsored()`: the "Sponsored" flag (sponsor tier, a
  sponsor-category organisation, or a repeat approved/confirmed host this year)
  — parity with the legacy calendar logic.
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
  (committee, or the `event_host`/`sponsor` roles), the edited event ID, and
  the per-status, per-user lock list. For hosts — title, type, preferred
  slots, fee tier, invoice block, sectors, host organisations, venue capacity
  and `venue_needed` all freeze once the event leaves
  draft/proposed/sent-back; description, speakers, venue, agenda, ticket
  allocations, contacts and co-owners stay editable. **Committee members
  bypass every post-approval lock except `fee_tier` and `invoice`** (Denis,
  7 September 2026): fee and invoice changes stay in the dashboard override
  control and wp-admin — and, from 9 September 2026, the dashboard control is
  read-only once the event is approved (see `fees.php`), which leaves wp-admin
  as the only post-approval fee route. Pre-approval statuses are unlocked for everyone. The
  user defaults to the current user, so the template render and the save-side
  enforcement always agree.
- `law_events_form_save()`: validation + persistence, with the required set
  mirroring form 2 (Event > submit an event) field for field. **The update path
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
  Co-owner and contact rows go straight to the schema sanitiser. Two behaviours worth knowing: ticket allocations are validated
  against `law_events_venue_capacity_bands()` (on an approved event against the
  *stored* band, since the locked select posts nothing). Preferred slots go
  through `law_events_sanitise_preferred_slots()` **before** validation, so the
  "choose at least one" check and the write see the same whitelisted set and a
  tampered label can neither be stored nor satisfy the requirement.
- `law_events_set_terms_by_name()`: sets taxonomy terms by name and **creates
  none** — an unknown name is dropped, never invented.
- `law_events_validate_photos()`, `law_events_sideload_upload()`: server-side
  speaker-photo validation (real MIME sniff, 5 MB cap, pixel bounds) and the
  media sideload.
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
  (`parts/events/event-form-fields.php`) opens with **First name** and **Last
  name** (both required on a started row, since 9 September 2026), then a Role
  select, then Email — the live form 8 layout. The Role select has **no
  default**: its first option is a blank `Select role` placeholder (Denis,
  9 September 2026), the field is not required, and an unset role still reads as
  Speaker on the public cards. The neighbouring label is "Job title" (it read
  "Job title / role", which beside a Role select said the same thing twice).
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
- A `wp_enqueue_scripts` closure loads `assets/css/event-form.css` and
  `assets/js/event-form.js` on the five account templates that use them
  (`account-event-form`, `account-dashboard`, `account-events`,
  `account-profile`, `register`), versioned by `filemtime` so an edit can never
  serve stale CSS, and adds WordPress's `password-strength-meter` dependency on
  the register/profile templates.

### `registration.php`: the custom registration and profile forms (forms 1 & 3)

- `law_registration_roles()`, `law_registration_accessibility_choices()`,
  `law_registration_dietary_choices()`, `law_registration_country_choices()`,
  `law_registration_validate_country()`: the self-service role whitelist
  (attendee/sponsor/event_host — never admin/committee) and the choice/country
  lists.
- `law_registration_hubspot_tags()`, `law_registration_write_profile_meta()`,
  `law_registration_sync_roles()`: HubSpot role tags and the ACF user meta
  (role/accessibility/dietary) both forms share.
- `law_registration_handler()` (on `admin_post_nopriv_law_register`): honeypot,
  per-IP rate limit (20/hour), role-whitelisted account creation, auto-login;
  a tripped rate limit now returns a titled 429 with a back link. Logged-in
  users are bounced.
- `law_profile_handler()` (on `admin_post_law_profile`): the self-service
  profile edit — role changes limited to the three self-service roles, and an
  **email or password change requires the current password**
  (re-authentication); the core email-change notice is suppressed before
  `wp_update_user` and replaced by the module's own.
- `law_profile_store_error_state()`: the shared fail path (keep typed values,
  never passwords; re-array cleared checkbox groups; transient + redirect) used
  by both the validation-fail and update-fail branches.
- `law_registration_state()`, `law_profile_state()`, `law_profile_values()`:
  transient-backed form state and the stored profile values.

### `committee.php`: the committee dashboard back end

- `law_committee_events( $overrides = array() )`: the review queue query
  (status filter + search). The `$overrides` array is merged over the query
  args; the dashboard export passes `posts_per_page => -1` through it to
  escape the 300-row screen cap without duplicating the filter logic.
  An explicit `?law_status=law-draft` is refused (falls back to the default
  non-draft set): drafts are owner-only unsubmitted host data, and before
  this guard a committee member could list — and once the export existed,
  bulk-download — every host's drafts by editing the query string
  (2026-09-07 security review finding).
- `law_committee_status_counts()`: the filter-chip counts, via **one**
  `wp_count_posts()` call (not a capped per-status query).
- `law_committee_requested_event()`: the event opened in the detail panel.
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
  rather than read as £0.00, and a change is refused outright once
  `law_event_fee_override_locked()` is true (only reachable from a hand-made
  request, since the control renders read-only then) and logged as a refusal.
  Because the check runs first, a refusal leaves the event untouched and the
  workflow action further down never runs.
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
  "Sponsored" badge, so a change to it has to leave a trail like slot,
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
  availability states with the same wording — "Bookings open soon", "Bookings
  for this event have closed.", "This event is fully booked." and "N places
  left". There is deliberately **one** state machine: the preview reuses those
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
lookup beats a second LEFT JOIN on every dashboard query.

### `export.php`: the dashboard exports (CSV / Excel / PDF)

Replaces the legacy GravityView view 419 (Events (committee - all))
DataTables export buttons. Three buttons ("Export: CSV | Excel | PDF") sit
under the dashboard filter bar (list view only) and export the full filtered
event list — the legacy column set minus "Workflow step" (folded into the
event status by the rebuild) plus "Reference".

- **One row builder, three formats.** `law_committee_export_rows()` returns
  `{columns, rows}` for every format, so they cannot drift. It calls
  `law_committee_events( array( 'posts_per_page' => -1 ) )` — same
  `?law_status=` / `?law_kw=` filters as the screen, but uncapped, because a
  silently truncated download is worse than a truncated screen — and primes
  the users cache once (`cache_users()`) for the host + assignee lookups.
  Columns: ID, Reference, Title, Name, Email, Committee assignee, Preferred
  date & time slots, Confirmed slot, Event status, Payment status, Submitted
  (`Y-m-d H:i`, sortable), Sector (`law_event_sector_summary()`), Linked
  organisations (`law_event_organisation_names()`, `; `-joined), Sponsored
  (`law_events_post_is_sponsored()`, `Yes` or blank), Event fee,
  Discounted fee, Venue capacity, Tickets available, Venue. The fee column
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

### Stripe (`stripe/client.php`, `stripe/service.php`, `stripe/webhook.php`)

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
  refused unless the event is Approved and still unpaid — which also keeps a
  cancelled event out of the invoice path).
- `law_stripe_void_invoice( $event_id, $actor )`: called by the cancel side
  effects to stop a live invoice — a `draft` is deleted (the same rule the
  resume path applies), an `open`/`uncollectible` invoice is voided (with an
  idempotency key), a `paid` one is left strictly alone (refunds are a manual
  committee decision; the cancel side effects send the alert), and `void`
  logs "already void". **Never fatal**: every failure is logged
  (`invoice_void_failed`) and alerted to admins + committee via
  `admin_stripe_error` with a "void it manually" message, and the
  cancellation completes regardless. The invoice ID/URL meta is kept for the
  audit trail.
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
- **`event-screen.php`** — the custom event edit screen: meta boxes for
  workflow actions, fee (with override), programme facts, invoice contact,
  people (co-owners/contacts), speakers, sessions, the comment thread and the
  activity log. `law_event_admin_save()` (on `save_post_law_event`, nonce +
  cap + reentrancy guard) writes the meta, applies the slot via the shared
  helper, and routes committee actions through the workflow engine.
  The fee override flag and its amount are written **as a pair, and only when
  the fee box was on the form** (9 September 2026): the blank-means-£0.00 trap
  is refused with a notice, and a fee box hidden by Screen Options can no
  longer clear the override flag by omission. When the fee inputs change on an
  already-approved event the save calls `law_event_resnapshot_fee()` and
  reports the new snapshot plus the void-and-reissue step, so this screen is a
  working post-approval fee route rather than a form whose values nothing
  reads. `law_event_admin_notice()` queues these one-shot notices instead of
  overwriting, since one Update can have two things to say.
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
  stay front-end-only so the engine's guards always run.
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
  the live address check and the live-site confirmation tick.

### Migration (`migration/report.php`, `migration/runner.php`, `migration/page.php`, `migration/repair-owners.php`)

- **`report.php`** — a custom log table (`law_migration_log`), `law_migration_log()`,
  per-step summaries and a tail for the admin panel, plus the snapshot-download
  and CSV-export admin-post handlers.
- **`runner.php`** — the engine. `law_migration_steps()` defines the ordered
  steps: snapshot → preflight → co-owners → speakers → events → sessions →
  speaker appearances (4b) → comments → history → counters → redirects →
  notifications → pages. Step 2 imports each form 8 (Event > speaker) child's
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
  health. The `law_migration_run_*()` functions import each entity from GFAPI;
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
  whole or not at all. **Step 10 (account page templates)**,
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
- **`page.php`** — the LAW > Migration screen and the
  `wp_ajax_law_migration_run` batched-step AJAX. All migration handlers are
  `manage_options` + nonce gated with a running-step lock.

---

## 3. Shared front-end files (`functions/`)

These predate the rebuild and now branch on `law_events_source()`.

- **`calendar.php`** (~1525 lines): the programme calendar. In `'cpt'` mode its
  data functions read `law_events_cpt_mapped_events()` /
  `law_calendar_event_by_id()` (which hydrates the single view) instead of GFAPI
  entries; the presentation helpers (day tabs, status badge, sponsored label,
  maps embed, SEO titles) are unchanged. `templates/calendar.php` (public) and
  `templates/calendar-committee.php` both `require parts/calendar-body.php`.
  **Flagship additions (9 September 2026):** `law_calendar_flagship_event()`
  (the hydrated flagship for the programme, memoised per calendar context;
  resolved through `law_calendar_event_by_id()`, which applies the public
  status filter anywhere but the committee calendar, so a draft flagship comes
  back null publicly and renders with its badge for the committee — there is
  deliberately no explicit `publish` test), `law_calendar_day_is_empty()` (ONE
  rule with two consumers: `parts/calendar-events.php` skips a day section and
  `parts/calendar-filters.php` greys out its tab, and "empty" now means more
  than "no cards") and a `continue` in `law_calendar_events()` for
  `is_flagship`, so the flagship can never appear as an ordinary card, in a
  slot bar or in the unscheduled bucket. The block is pinned to its own day
  **whatever the filters say** — it is the main event of the week, and a
  delegate searching for something else should still see it — which is why it
  sits outside the filtered list rather than inside it. If its date falls
  outside the configured programme week the block renders above the days rather
  than vanishing, so a mis-set week is visible instead of silently costing the
  site its main event. `law_calendar_events()`, `law_calendar_filters()`,
  `law_calendar_event_by_id()` and `law_calendar_flagship_event()` each took a
  `$reset` parameter, and `law_calendar_reset_caches()` flips them together:
  production renders one template per request, but the tests create events
  mid-request.
- **`speakers.php`** (~532 lines): the speakers archive/profile routing and SEO.
  In `'cpt'` mode it reads the `law_speaker` posts via `source.php`; the
  `/speakers/<id>/` rewrite and single-profile rendering are shared.
- **`account-events.php`** (~300 lines): the host "My events" listing. In
  `'cpt'` mode it lists the user's owned/co-owned `law_event` posts, hosts the
  comment thread (`?law_thread=`), links to the custom edit form, shows a
  "Review queue" link to committee members and renders the save-confirmation
  notice. `law_account_event_actions()` also appends a **Withdraw** action on
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
  `reset_password()`). `law_auth_redirect_to()` and a `login_redirect` filter
  send committee members to `/account/dashboard/` (not the host "My events"
  page). Two security properties to preserve:
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
  and Manage speakers. The per-role table in `tests/HeaderNavTest.php` pins the
  exact item list for every role, so adding an item means updating that
  fixture: it is what proves a host or attendee never sees a committee link.
- **`modal.php`**: the reusable confirmation modal's asset registrar.
  `law_modal_register_assets()` registers the `law-modal` style and script
  handles on `wp_enqueue_scripts`; `law_modal_enqueue()` enqueues them and is
  safe to call repeatedly. Not events-specific: any template in the theme can
  drop a modal in.

---

## 4. Templates, parts and assets

- **Root templates**: `404.php` — the shared hero banner ("Page not found")
  and a short message. Deliberately never loops the queried post: it is also
  what the pre-launch Members gate renders after `set_404()`, which leaves the
  gated event in the query (see `source.php` above).
- **Templates**: `register.php` (custom registration), `account-profile.php`
  (profile), `account-event-form.php` (submit/edit), `account-events.php`
  (My events + thread), `account-dashboard.php` (committee review queue, the detail view, the
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
  website link and "Speaking at" cards, with the organisation, job title and
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
  what the dashboard's script-opened success dialog uses.
- **Calendar parts** (`parts/`): `calendar-body.php` (the shared list/single
  view; its optional caller variables, set before the `require` —
  `$law_cal_show_status` for committee mode, `$law_cal_hero_title` for the
  list hero, and, added 9 September 2026 for the committee preview,
  `$law_cal_event` for a pre-resolved, already-hydrated event that bypasses
  the public-status resolver and `$law_cal_back` for a back destination,
  applied to **both** ways back off the single event view — the chevron link
  at the top and the button at the foot of the article — so the two cannot
  drift; and, added 9 September 2026 for the flagship, `$law_cal_details_rows`
  (an allow-list of details-box row keys, so the flagship shows only date, time
  and location), `$law_cal_no_booking` (no booking control and no places
  fallback) and `$law_cal_sessions_style` ('accordion' or 'timeline'). The
  venue markup lives in its own part, `parts/events/event-venue.php`, from
  when the flagship briefly rendered it in a different position; the
  flagship's own hero image (`_law_hero_image_id`) is read here too, and an
  event without one keeps `hero-title.php`'s default photograph),
  `calendar-events.php`, `calendar-filters.php` and
  `calendar-event-details.php` — the single event view's facts box, rendered
  inside the hero below the title via `hero-title.php`'s `after_title` arg. It
  holds its own hand-drawn line-icon set (the theme has no icon library) and
  lays six facts out in a three-column grid. It exists because those facts used
  to sit as loose white text on the hero photograph, at roughly 1.7-3:1 against
  the 4.5:1 WCAG minimum for body text; a solid panel supplies its own
  background whatever is behind it, which is the only fix that does not depend
  on the image.
- **The single event view's section order is fixed: description, sessions,
  speakers, venue** (Denis, 9 September 2026). `calendar-body.php` renders
  those four in exactly that sequence for every event, ordinary or flagship:
  the description sets the scene, the running order and the people are what
  the reader came for, and the address is a detail they need once, so it goes
  last. The venue used to sit directly under the description, with a
  `$law_cal_venue_last` caller variable the flagship page set to push it below
  the sessions instead; that variable is gone, because two orders for the same
  four sections was a difference with no reason behind it. Nothing is lost by
  putting the venue at the foot: the facts box in the hero states the location
  at the top and links down to the section
  (`href="#law-cal-venue-heading"`, `calendar-event-details.php`) whenever the
  address is mappable. Note that the event-level **Speakers** section still
  renders only when the event has no sessions, because a session's speakers
  are already listed inside its own panel.
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
  because they are decoration, and a lighter, hollow marker for a **break**,
  which the part infers from a session having neither a description nor a
  speaker rather than from a field nobody would maintain) and
  `parts/events/event-venue.php` (the address and its keyless Google map,
  extracted from `parts/calendar-body.php` so it can render above or below the
  sessions) and `parts/events/flagship-manage.php` (the committee's editor: the
  theme's own front-end form markup for the top-level fields, and the shared
  agenda block from `flagship-form.php` below them). The flagship renders every session open, because on a day-long
  conference the running order IS the content and an accordion hides the whole
  programme behind eight summaries; the accordion stays for ordinary events,
  where two or three sessions are something a reader chooses to open.
- **Parts** (`parts/events/`): `speaker-card.php` (one speaker card on the
  single event view — photo or initials, the name with the role at this event
  in brackets after it (`.law-cal-speakers__tag`, outside the profile link so
  the link text stays the name), "job title, organisation", a
  24-word biography excerpt and the "Read full bio" pair: the
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
  inline and as the `law_partial` AJAX response),
  `event-form-fields.php` (the six shared submission-form fieldsets — Event
  details, Speakers, Venue, Owners & contacts, Fees, Session agenda —
  consumed by both the host form template and the committee edit view so the
  two cannot drift; the Finish fieldset stays in each consumer, being the
  part that differs; the Venue fieldset's three detail fields are conditional,
  see `law_events_venue_details_visible()`) and `committee-event-form.php` (the committee edit
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
  fetch form with no dialog at all.
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
  programme calendar and the committee dashboard.
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
4. **Profile `roles[]` demotion** — unticking your current role silently drops
   you to `attendee`; no confirmation step.
5. Minor polish: a map-embed fallback state, the auto "Sponsored" badge on
   repeat *paying* hosts, and "Fee snapshot £0.00" showing on proposed events
   before approval.
6. **`law-cancelled` is terminal** (7 September 2026): there is no un-cancel
   transition, so a mistaken cancel or withdraw can only be corrected in the
   database. Accepted for now; a committee "reinstate" action would be a 4.2
   candidate. Related accepted behaviour: a cancelled formerly-Confirmed
   event's permalink 404s (no tombstone or redirect).

Open findings from the forms/payments security review, none of them blocking:

1. **The `sponsor` fee tier is self-asserted.** Anyone can self-register with
   the `sponsor` role and then choose the sponsor tier, which is £0 — so the
   event skips invoicing and auto-confirms on approval. The only control is the
   committee seeing the tier on the dashboard, where it is displayed. Consider
   a warning badge at approval, or gating the option on a verified sponsor flag.
2. **Partial refunds mark an event fully refunded.** The `charge.refunded`
   branch of `stripe/webhook.php` ignores `amount_refunded` versus `amount`, so
   a goodwill part-refund flips a paid event to Refunded.
3. **`invoice.paid` reconciles the amount but not the currency**, and host
   descriptions run through `the_content`, so shortcodes in them execute on the
   single-event page. (The front-end edit lock being taken and never released
   was the third item here; `edit-lock.php` fixed it on 9 September 2026.)

**Flagship conference, open items (9 September 2026).** The approval-gated
application flow (EVENTS_4.2_SPECS.md §5) is not built: the flagship page
renders no booking control and `law_booking_guard_open()` refuses it outright,
so nothing can be booked onto it yet. Two consequences worth knowing while it
stays that way: the flagship has no `_law_approved_at` and no payment meta, so
anything that prints those for a published event shows blanks for it; and a
published flagship with no sessions has no start time, which is why its
programme block says "Programme to be announced" and its Time fact reads
"Times to be announced" rather than the event disappearing.

**Reserved for 4.2 (present but intentionally unused):** the event meta key
`_law_registration_state` and its sanitiser (event-registration vocabulary),
the speaker `_law_organisation_ids`, and the `host_edit_review` setting (its UI
control is disabled). Leave until 4.2; do not cut.

**Post-cutover cleanup ticket:** once the source flip is permanent, the `'gf'`
branches in `calendar.php`, `speakers.php` and `account-events.php`, the
legacy-entry-ID resolvers, and the migration tooling can be deleted wholesale —
the single largest line-count reduction available, deliberately deferred while
rollback must stay alive.

---

## Change history

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
the events dashboard has two Members-restricted children. The build also fixed
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
schema plus the `_law_tickets_sold` / `_law_capacity_warned` event keys, the
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
programme card's placeholder Register action removed, the hero's
"Places remaining" fact, and the registration form's locked-role +
`redirect_to` round trip for the modal's register link. Phases 4–6 (same
day): the account area (the "Your bookings" section and audience split on
My events, the `?law_booking=` manage view, the page 292 (My events)
attendee-access fix scripted in `law_setup_account_events_attendee_access()`
+ migration step 10), the host/committee `?law_event_bookings=` list with
per-attendee Reject and the CSV/Excel/PDF export trio
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
latter retargeting both ways back off that view, so "Back to programme" and
"Back to events calendar" become "Back to event" together); the detail view's
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
host who already has a venue**. On the Venue section, the venue name/address,
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
override for that slug keeps its own text, so check the `law_events_emails`
option before assuming the registry default is what sends.

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
safe). A blank Places available means no limit, which is what
`law_events_bookings_remaining()` already reads a 0 or unset value as.

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
blank means no limit and that raising it offers the places to the waitlist:
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

The companion EVENTS_4.1_REBUILD.md remains the design contract;
this document maps that design onto the code as built.
