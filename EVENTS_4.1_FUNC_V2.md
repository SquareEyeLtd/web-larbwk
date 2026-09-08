# Events module: code report (4.1 custom rebuild)

> **Keep this file current.** This document is the working reference for the
> events module: any agent or developer who makes a material change to the
> module (a new file or function, a changed workflow, security or payment
> behaviour, a settings or UI change that alters how the module works) must
> update the relevant section here in the same piece of work, and refresh the
> "verified against" date below. Small refactors that change nothing about
> behaviour do not need an entry; anything a future reader would be misled by
> does.

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
`functions/events/bookings-dashboard.php`, "Bookings dashboard" in the header
account dropdown for committee), **registering an attendee on their behalf**
from the per-event bookings list (`law_booking_register_by_manager()`, handler
`law_booking_register_attendee`, a committee-only **press pass** flag on the
row, two new emails — 39 total), the **Country** column on the per-event list
and export, and the booking control's button renamed **Register** (was "Book
now", per 4.2 spec §3.4's vocabulary for free events).
The companion EVENTS_4.1_REBUILD.md remains the design contract;
this document maps that design onto the code as built.

Unlike EVENTS_4.1_FUNC.md, this file carries no secrets, so it is safe to
track. Stripe keys live only in `wp-config.php` (`LAW_STRIPE_*` constants).

Where a Gravity Forms form or field is named, it is paired with its name per
house convention, e.g. form 2 (Event > submit an event), field 95 (Event
status). The rebuild retires those forms; they appear here only where the
migrator reads them or where the legacy source path still branches on them.

---

## 1. Where the feature lives

1. **The events module** (`functions/events/`): a self-contained package of 34
   files (22 top level, 6 `admin/`, 3 `stripe/`, 3 `migration/`) loaded by one
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
**test-mode** → notifications → speakers → source → submission-form → registration → committee
→ Stripe (client, service, webhook) → admin (fields, event/speaker/session
screens, columns, emails) → migration (report, runner, page).

### `settings.php`: the settings store and the LAW submenu host

- `law_events_settings_defaults()`, `law_events_settings()`,
  `law_events_setting()`, `law_events_update_settings()`: one option,
  `law_events_settings`, holds the fee tiers, the programme year, the date/time
  slots, the committee recipient emails, the Stripe `tax_rate_id` and
  `rendering_template_id`, and the reserved `host_edit_review` mode.
- `law_events_slots()`: the canonical slot list (label → date/start/end),
  retired slots excluded unless asked for.
- `law_event_apply_slot_label()`: writes `_law_start`/`_law_end` from a chosen
  slot, or clears them when the label is emptied. **Shared** by the committee
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
  49 left exactly 50 with no band that would accept it.
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
- `law_booking` (bookings phase 1, EVENTS_BOOKINGS.md): private, no rewrite,
  `supports => title` only, a submenu of Events like speakers/sessions, and
  `create_posts => do_not_allow` on top of the shared capability set — bookings
  are only ever created by the engine (`law_booking_create()`), so the
  capacity/duplicate/clash guards and the seat recount cannot be bypassed from
  wp-admin. Event = `post_parent`, owner = `post_author`, status `publish`
  (active) or `law-cancelled`.
- `law_events_register_taxonomies()`: `law_event_type`, `law_sector` and
  `law_event_category` on events, plus `law_year` on events and speakers (the
  programme-year filter that keeps a 2026 event from re-filing into 2027).
- `law_events_maybe_flush_rewrites()`, `law_events_seed_terms()`: one-time
  rewrite flush and the default term seed (event types, sectors, categories,
  the current year).

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
- `law_event_status_from_legacy()`: maps a legacy field 95 (Event status) value
  (Proposed/Sent back/Approved/Confirmed/Rejected) to a CPT status, used by the
  migrator.
- A `display_post_states` filter labels the statuses in the admin list.

### `meta.php`: the meta schema and the single read/write path

- `law_event_meta_schema()`, `law_speaker_meta_schema()`,
  `law_session_meta_schema()`, `law_booking_meta_schema()`: the ~50 meta keys
  and their types (text, `text_array`, `int_array`, `address`, `people_rows`,
  `speaker_rows`, `attendee_rows`, `consent`, `stripe_error`, etc.). Every key
  is registered via `register_post_meta` in `law_events_register_meta()` (on
  `init` priority 7) with a per-type sanitiser and an auth callback. Bookings
  phase 1 added `_law_tickets_sold` (the recalculated seat counter) and
  `_law_capacity_warned` (the one-shot host warning latch) to the event
  schema, and `_law_booking_number` + `_law_attendee_rows` on bookings; the
  flat `_law_booking_attendee` index rows stay OUT of the schema, exactly like
  `_law_co_owner`.
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
  `law_bookings_counter` option behind "Booking #N".

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
- `law_events_format_pence()`: "£1,200.00" formatting.

### `log.php`: the WooCommerce-order-notes-style activity log

- `law_event_log()`: appends one immutable log line to an event as a WordPress
  comment of type `law_event_log`, with a structured context array (action,
  source, actor, and per-event extras). Every status change, email sent (with
  recipients), payment change, private note and co-owner action is logged.
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
  quick-edit dropdown. **Since bookings phase 1 the guard also covers
  `law_booking`** (its own flag, `law_booking_transitioning`, raised only by
  `law_booking_cancel()`), so quick edit cannot resurrect a cancelled booking;
  the `wp_untrash_post_status` filter likewise restores a booking to its
  pre-trash status, constrained to publish/law-cancelled (anything else
  restores as cancelled, the safe side).
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
  `law_event_log_fee_change()`, `law_event_maybe_notify_assignee()`: the
  confirm/publish path, the logged payment-status setter, fee-override logging
  and the assignee-change email.

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

### `bookings.php`: the bookings engine (phases 1–6; EVENTS_BOOKINGS.md is the contract)

- **Front-end surfaces** (phases 4–5): `functions/account-bookings.php`
  (the control, `law_account_bookings()`, the audience split helper, the
  form-state transients, the enqueues), `parts/events/booking-modal.php` /
  `attendee-repeater.php` / `booking-manage.php` (`?law_booking=`, owner or
  seated attendee; remove/add/cancel behind confirm modals with the specified
  copy and close labels) / `booking-list.php` (`?law_event_bookings=`,
  `law_user_can_manage_event()`; a flat dashboard-idiom table whose leading
  Booking column repeats the number per attendee row — Denis, 7 September
  2026, superseding the grouped-blocks design — with live
  dietary/accessibility from `law_profile_values()` in wrapping columns, the
  attendee's **country** (live from the profile too, 8 September 2026), a
  **Press** badge on press-pass rows, per-attendee Reject with optional
  reason, cancelled bookings collapsed with the cancelled badge, the
  CSV/Excel/PDF export trio, and — below the table — **"Register an
  attendee"** (8 September 2026): a host, co-owner or committee member
  registers someone by name and email for phone/email requests, VIPs and
  press. Handler `law_booking_register_attendee` (gate
  `law_user_can_manage_event()` on the posted event, the subject itself since
  no booking exists yet; `booking_edit` rate surface; no-JS refusals
  re-render the typed row via `law_booking_store_form_state()`) calls
  `law_booking_register_by_manager()`, which cleans the row with
  `law_booking_clean_additional_rows()`, fast-fails the open/duplicate/
  capacity guards, resolves or creates the attendee account
  (`law_events_create_host_user()`, role attendee), then calls
  `law_booking_create()` with `$args` (`actor`, `on_behalf`, `new_account`,
  `press`, `owner_row`) so the person OWNS the booking — it sits under their
  "Your bookings", they can manage or cancel it, and every guard, the recount
  and the host/committee emails run exactly as for self-service. A refused
  booking deletes the account it just created (no orphans; logged
  `booking_attendee_account_rolled_back`). The confirmation is
  `user_booking_registered` (existing account) or
  `user_booking_registered_invited` (new account, with the set-password link
  in the same email — one email, not invite + confirmation), both naming
  `{registered_by}`. The **press flag** (`is_press` on the row, kept by the
  `attendee_rows` sanitiser only when set) is honoured by the handler only for
  `law_user_is_committee()` — press passes are LAW-issued (spec §6.4) — and
  surfaces as the badge, a Press column in both exports and the Bookings
  dashboard's "Press only" filter. Hosts get "Bookings (n)" on Confirmed cards
  (`law_account_event_actions()`); the committee dashboard rows link the same
  URL. `law_booking_export` (GET, format=csv|xlsx|json) reuses
  `law_events_send_csv()`/`law_events_send_xlsx()` (both grew an optional
  title line) and export-buttons.js/pdfmake (generalised: the filter form is
  optional, page size follows column count); its columns are Booking ID,
  First name, Second name, Email, Organisation, Job title, Country, Press,
  Accessibility, Dietary.
- **The event-cancel sweep** (phase 6): `law_bookings_cancel_all_for_event()`,
  a direct call from the workflow's `cancel` side effects — every active
  booking cancelled, every attendee sent `user_booking_event_cancelled`,
  one summary log line.

- A booking = a `law_booking` post (event = `post_parent`, owner =
  `post_author`, `publish` active / `law-cancelled`) carrying
  `_law_attendee_rows`, an ordered array where **row 0 is the owner**
  (snapshot: user_id, name, email, organisation, job_title, is_owner), plus
  one flat `_law_booking_attendee` postmeta row per linked user (the
  `_law_co_owner` query pattern), all written by the single path
  `law_booking_set_attendee_rows()`.
- Mutations, all logging to the parent EVENT's activity log with
  `source => 'bookings'` and `booking => <id>` (refusals too,
  `booking_guard_refused`): `law_booking_create()` (guards → attendee-role
  auto-grant → insert → accounts → rows → recount → emails → capacity check),
  `law_booking_add_attendee()`, `law_booking_remove_attendee()` (contexts
  owner / self / host_reject pick the notification template; the last removed
  row auto-cancels), `law_booking_cancel()` (idempotent; context owner /
  event_cancelled / last_attendee_removed picks who is emailed).
- Guards: `law_booking_guard_open()` (Confirmed + CPT source + ticket number
  set + not started — no ticket number means "Bookings open soon", NOT
  unlimited), `law_booking_guard_duplicates()` (an email holds one place per
  event, across active bookings and within a submission),
  `law_booking_guard_capacity()`, `law_booking_guard_clash()` (overlap on
  `_law_start`/`_law_end`, missing end = 23:59 of the start date, message
  names the conflict), `law_booking_clean_additional_rows()` (cap 3, name +
  valid email required, errors carry row/field data).
- Places: `_law_tickets_sold` is stored and recalculated by
  `law_event_recount_attendees()` after every mutation (the programme render
  is the hot path, so no live counting); `law_event_tickets_remaining()`
  returns null for "not open"; a `GET_LOCK('law_booking_event_<id>', 3)`
  serialises the mutations — since the phase 7 security review EVERY
  shared-state read runs inside it (the duplicate, own-booking, clash,
  additional-cap and capacity guards on create/add, and the row
  read-modify-write on remove/reject/cancel; the lock is re-entrant per
  session, so remove's last-row auto-cancel taking it again is fine), so two
  parallel submits can no longer both pass the pre-checks; two backstop
  hooks (`transition_post_status`, `deleted_post`) recount after wp-admin
  trash/untrash/delete, which never touch the engine.
- Accounts: `law_booking_ensure_attendee_user()` links an existing account by
  email (granting the `attendee` role) or creates one via the parameterised
  `law_events_create_host_user()`; an account failure keeps the seat
  (snapshot counts) and logs `booking_attendee_error`.
- `law_booking_maybe_capacity_warning()`: one-shot host warning at ≤ 5 places
  remaining (`_law_capacity_warned`), re-armed by the recount when removals
  lift remaining above 5.
- Emails (phase 2): `user_booking_confirmed`, `host_booking_received`,
  `committee_booking_received` (assignee-first via `$extra['to']`, falling
  back to the committee list), `user_attendee_invited` / `_added` /
  `_rejected` / `_removed` / `_removed_self`,
  `user_booking_cancelled_attendee`, `user_booking_event_cancelled` (the
  phase 6 event-cancel sweep will send it), `host_capacity_warning`, and
  (8 September 2026) `user_booking_registered` / `user_booking_registered_invited`
  (registered on their behalf; the invited variant carries the set-password
  link; both name `{registered_by}`) — all
  in the registry, editable on the Emails screen, every send logged. The
  confirmation, the registered-on-behalf pair and both attendee-added emails attach the event's `.ics`
  invite via `law_booking_send_with_ics()` (tempfile deleted after the
  synchronous send). One removal template per context, because a single
  "you have been removed" would mis-describe most of them.
- Handlers (phase 3), all on `law_events_guard_post()` (request.php):
  `law_booking_create` (any signed-in user; role auto-granted; 10/600s on the
  `booking` surface), `law_booking_add_attendee` / `law_booking_cancel`
  (booking owner), `law_booking_remove_attendee` (owner, or the row's own
  user — the context picks the email), `law_booking_reject_attendee`
  (`law_user_can_manage_event()` on `post_parent`; posted event IDs are never
  trusted), all 15/600s on `booking_edit`. Error payloads carry the engine's
  row/field data so booking-form.js can mark the offending input; the no-JS
  path stores `law_booking_store_form_state()` (account-bookings.php) and the
  inline form re-renders with the typed rows. One rule beyond the guards:
  **one active booking per person per event** — even a seatless owner is
  refused a second booking and manages their existing one instead.
- Front end (phase 3): `functions/account-bookings.php` renders the
  five-state control (`law_booking_render_action()`: You're booked with a
  Manage/View link → Bookings open soon → Book now + "N places left" →
  sold-out disabled "Join waitlist" placeholder → "This event has taken
  place") in the footer row of the hero's event details box
  (`parts/calendar-event-details.php`), next to the facts rather than at the
  bottom of the article; the programme card's disabled Register default action
  is gone (`parts/loop/event.php`). The box carries the `law-cal` class so the
  control keeps the `.law-cal`-gated styling it depends on (the `aria-disabled`
  inert treatment, the button hover, the light-surface `.law-form-notice`
  colours), and both of its `position: fixed` dialogs are deferred to
  `wp_footer` by `law_booking_footer_modal()`, because the hero's
  `.grid-container` is a stacking context (`z-index: 4`) that would otherwise
  paint them under the fixed header. Availability is stated by the control, so
  the old "Places remaining" hero fact appears in the box only as a fallback,
  when the control renders nothing (the legacy source, and committee previews of
  unpublished events). The Book now opener is a
  real link to the inline `?law_book=1` form (the no-JS path);
  `assets/js/booking-form.js` upgrades it to open
  `parts/events/booking-modal.php` (the `.law-modal` skeleton with a `--wide`
  dialog, NOT parts/layout/modal.php, whose args are single-field), wrapped
  `law-event-form--light` so the dark-hero-first form styles do not vanish on
  the white dialog. `parts/events/attendee-repeater.php` starts at zero rows
  ("Add a colleague", cap `min(3, places remaining − 1)`) on its own
  `data-law-booking-*` hooks so event-form.js's repeaters cannot double-fire.
  Fetch errors are row/field-marked in place; success opens the locked
  `law-booking-success` dialog whose two links (Close / View my bookings) are
  the only exits — no 3-second auto-reload. Assets (event-form.css, the modal
  pair, booking-form.js) enqueue at head time on the single event view.
  `law_events_map_post()` now carries `tickets_sold`/`tickets_remaining`; the
  front end never reads the legacy `tickets` key (its 0 means "unset").
- Registration (phase 3): `?role=attendee&redirect_to=…` on `/register/`
  hides the Role section (a hidden input posts the whitelisted role) and both
  values survive the error round trip; the handler redirects a successful
  registration back to the validated `redirect_to`, so the modal's register
  link returns the new attendee to the event they were booking.
- Tests: `tests/BookingsTest.php` (15 tests: creation, guards, mutations, the
  status-guard/untrash extensions, the recount backstops) and
  `tests/BookingEmailsTest.php` (9 tests: .ics UTC/folding/escaping,
  attachments reaching `wp_mail`, per-context removal templates,
  assignee-first committee routing, the capacity warning latch, the welcome
  email); `LAW_Test_Case::make_booking()` tracks engine-created bookings and
  users so teardown stays clean.

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
  checks `law_user_is_committee()` in code. Linked as "Bookings dashboard" in
  the header account dropdown for committee (`law_account_paths()` key
  `bookings`), after "Events dashboard".
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

- `law_events_email_registry()`: all 37 module emails as definitions (slug →
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
  - **Bookings** (phase 2, all `dynamic` unless noted): `user_booking_confirmed`
    (.ics attached), `host_booking_received` (to `host`),
    `committee_booking_received` (to `committee`, assignee-first via the send
    call), `user_attendee_invited` / `user_attendee_added` (.ics attached),
    the per-context removal family `user_attendee_rejected` /
    `user_attendee_removed` / `user_attendee_removed_self` /
    `user_booking_cancelled_attendee` / `user_booking_event_cancelled`,
    `user_welcome_registered` (registration; event-less, so unlogged) and
    `host_capacity_warning` (to `host`, one-shot latch).
- `law_events_email()`: the registry entry with any admin override merged in
  (overrides live in one option, editable on the Emails screen).
- `law_events_email_placeholders()`: builds the merge values for an event
  (title, reference, host, fee, dashboard/committee/invoice links, etc.).
- `law_events_email_recipients()`, `law_events_send()`: resolve recipients and
  send, logging the send (with recipients, and the test-mode address when one
  is in force) to the activity log. A £0 event splits to the "confirmed, free"
  template. When an audience resolves to nothing (an empty committee list, say)
  the email is normally dropped — but **in test mode it is routed to the test
  address instead**, since the point of a rehearsal is to see it.

### `speakers.php`: speaker records and the archive (CPT mode)

- `law_speaker_find_existing()`, `law_speaker_normalise_name()`: dedupe by email
  first, then normalised name.
- `law_speaker_upsert()`: match-or-create a `law_speaker` from a submitted row.
  **Identity only** (name, email, website): it gap-fills empty fields (never
  blanks an existing value) and logs to the event when it backfills a
  pre-existing shared record. It no longer writes an organisation or job title;
  the featured image and the biography it sets once are only fallbacks.
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
- `law_speakers_confirmed_maps()` (and its `law_speakers_confirmed_event_map()`
  wrapper): the one pass over published events' rows, memoised — the archive
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
  `?event=<entry id>` and `/speakers/<entry id>/` URLs to the new permalinks
  (public programme only, so committee/dashboard `?event=<post id>` links are
  never hijacked).
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
  control and wp-admin. Pre-approval statuses are unlocked for everyone. The
  user defaults to the current user, so the template render and the save-side
  enforcement always agree.
- `law_events_form_save()`: validation + persistence, with the required set
  mirroring form 2 (Event > submit an event) field for field. **On update it
  always carries the existing title forward** — `wp_insert_post` fills any
  omitted key from its defaults, so leaving the (locked) title out would blank
  it and make the event vanish everywhere (`law_events_map_post()` treats an
  empty title as absent). Co-owner and contact rows go straight to the schema
  sanitiser. Two behaviours worth knowing: ticket allocations are validated
  against `law_events_venue_capacity_bands()` (on an approved event against the
  *stored* band, since the locked select posts nothing), and a `?ec=` value
  carried in a hidden field applies a matching `law_event_category` term **on
  first save only** — the rebuild of the dead field 113/116 mechanism.
- `law_events_set_terms_by_name()`: sets taxonomy terms by name and **creates
  none** — an unknown name is dropped, never invented.
- `law_events_validate_photos()`, `law_events_sideload_upload()`: server-side
  speaker-photo validation (real MIME sniff, 5 MB cap, pixel bounds) and the
  media sideload.
- `law_events_form_save_speakers()`, `law_events_form_save_sessions()`: upsert
  speakers (identity) and store the appearance rows with this event's role,
  organisation, job title and photo; a re-save without a new upload keeps the
  photo already on the row for the same speaker (the posted `photo_id` is a
  display echo, never trusted). Session rows copy the matched event row's
  details, role included (the host form's session picker is a list of names,
  so a per-session role is a wp-admin-only override). The Speakers fieldset
  (`parts/events/event-form-fields.php`) has a Role select between Name and
  Email, the live form 8 layout; Speaker is preselected, the field is not
  required, and the neighbouring label is now "Job title" (it read "Job title
  / role", which beside a Role select said the same thing twice). Before
  8 September 2026 this save hard-coded `role => ''`, so a role the committee
  set in wp-admin vanished on the host's next edit. `event-form.js` resets a
  cloned `<select>` to its first option rather than to `''`, which on a select
  with no blank option would leave nothing selected and post no role.
  `law_events_form_values()` prefills role/organisation/job title from the
  row, not the speaker post.
- `law_events_form_handler()` (on `admin_post_law_event_form`): nonce,
  honeypot, rate limit (15 per 10 minutes), `law_events_user_can_submit()`,
  then `law_user_can_manage_event()` on an edit. **Cancelled and Rejected
  events are read-only**: the template shows a "can no longer be edited" note
  instead of save buttons, and the handler refuses a hand-made or stale POST
  with the `event-not-editable` notice (the message thread stays open — only
  edits are refused). Edit locking replaces
  GravityView entry locking: `wp_check_post_lock()` refuses the save with a
  named-editor message when someone else holds the lock, then
  `wp_set_post_lock()` takes it. A posted `law_form_context=committee` field
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
  workflow engine and logged, plus the fee override, event categories, linked
  organisations and private notes. `law_event_apply_slot_label()` writes the
  confirmed slot. A `law_terms_present` sentinel distinguishes "cleared" from
  "not on the form" for the checkbox and multi-select controls.
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
  details" button (after the Invoice details block, before the thread; not
  offered on drafts) swaps the detail panel for
  `parts/events/committee-event-form.php` — white background, a "< Back to
  the event" link, the shared fieldsets with committee locks (everything
  editable except fees/invoice, see `submission-form.php` above), a single
  "Save changes" button posting the same `law_event_form` handler with
  `law_form_context=committee`, and no `law_ec` field (first-save-only
  mechanism). It takes the post lock exactly like the host form; the
  read-only detail view never does. The sidebar's "Full editing in
  wp-admin" link stays — wp-admin remains the fee/invoice edit route.

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
  (`Y-m-d H:i`, sortable), Sector (`law_event_sector_summary()`), Event fee,
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
  a Role select (Speaker / Host / Moderator from `law_speaker_roles()`, was a
  free-text input until 8 September 2026), organisation, job title, a
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
  `law_events_rows_from_post()` reads the repeater rows, sanitising per key
  rather than mapping one function over the row: a speaker's `bio` takes
  `sanitize_textarea_field()`, since `sanitize_text_field()` would collapse its
  line breaks before the meta schema's own textarea sanitiser ever saw them.
- **`speaker-screen.php`, `session-screen.php`**: the speaker and session edit
  meta boxes and their saves. The speaker screen's read-only "Appears at" box
  lists each confirmed event with "role, job title, organisation".
- **`columns.php`**: admin list columns (status, host, slot, payment), a status
  filter dropdown, and the `pre_get_posts` wiring for it.
- **`booking-screen.php`** (bookings phase 6): the read-only `law_booking`
  screen — Booking facts (number, status, the parent event linked both ways),
  Attendees (snapshot rows with `get_edit_user_link()`), Activity (the parent
  event's log filtered to this booking's context) — plus the bookings list
  columns and the events list's "Booked" column (`sold / available`, red when
  the committee lowered the ticket number below sold). Mutations stay
  front-end-only so the engine's guards always run.
- **`emails-screen.php`**: the Emails screen (its own top-level menu at
  position 7, directly under the Events menu at 6; formerly LAW > Emails, same
  `law-events-emails` slug and URL) — list, edit, send-test and
  reset for the notification registry, overrides stored in one option, plus a
  "review tags" flag on any migrated body still carrying unresolvable Gravity
  Forms merge tags. It also hosts the **Enable test mode** card
  (`law_events_emails_test_mode_card()` /
  `law_events_emails_handle_test_mode_post()`, see `test-mode.php`), including
  the live address check and the live-site confirmation tick.

### Migration (`migration/report.php`, `migration/runner.php`, `migration/page.php`)

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
  (My events + thread), `account-dashboard.php` (committee review queue),
  `account-bookings-dashboard.php` (the committee's cross-event bookings
  table, see `bookings-dashboard.php` above),
  `event-single.php` (single event), `calendar.php` / `calendar-committee.php`
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
  not passed through `wp_kses_post()`, which would strip inline `<svg>`) and
  `modal.php`, the reusable confirmation dialog. Pass it an id, a title, copy
  paragraphs, an optional note field and the confirm button, and it renders the
  markup `law-modal.css` and `law-modal.js` expect and enqueues both itself.
  The confirm array takes an optional `busy` label (rendered as
  `data-law-modal-busy`, the in-flight text a fetch layer swaps in), and
  `'confirm' => false` renders an informational dialog with no submit button —
  what the dashboard's script-opened success dialog uses.
- **Calendar parts** (`parts/`): `calendar-body.php` (the shared list/single
  view), `calendar-events.php`, `calendar-filters.php` and
  `calendar-event-details.php` — the single event view's facts box, rendered
  inside the hero below the title via `hero-title.php`'s `after_title` arg. It
  holds its own hand-drawn line-icon set (the theme has no icon library) and
  lays six facts out in a three-column grid. It exists because those facts used
  to sit as loose white text on the hero photograph, at roughly 1.7-3:1 against
  the 4.5:1 WCAG minimum for body text; a solid panel supplies its own
  background whatever is behind it, which is the only fix that does not depend
  on the image.
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
  part that differs) and `committee-event-form.php` (the committee edit
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
  submit without JavaScript. Two hooks exist for scripts: `window.lawModal`
  (`open(id)` / `close()`, the programmatic surface) and the `law-modal--busy`
  class, which marks a dialog mid-request so Escape and the close controls are
  ignored until it is removed; `closeModal` also clears any `.law-modal__error`
  a fetch layer injected.
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

## 6. Open items and product decisions (as of 5 September 2026)

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
3. **`invoice.paid` reconciles the amount but not the currency**, the front-end
   edit lock is taken and never released (so a host's save blocks a wp-admin
   edit for ~2 minutes), and host descriptions run through `the_content`, so
   shortcodes in them execute on the single-event page.

**Reserved for 4.2 (present but intentionally unused):** the event meta key
`_law_registration_state` and its sanitiser (event-registration vocabulary),
the speaker `_law_organisation_ids`, and the `host_edit_review` setting (its UI
control is disabled). Leave until 4.2; do not cut.

**Post-cutover cleanup ticket:** once the source flip is permanent, the `'gf'`
branches in `calendar.php`, `speakers.php` and `account-events.php`, the
legacy-entry-ID resolvers, and the migration tooling can be deleted wholesale —
the single largest line-count reduction available, deliberately deferred while
rollback must stay alive.
