# Events module: code report (4.1 custom rebuild)

Working reference for the custom, CPT-backed events module that replaces the
Gravity Forms / Gravity Flow / GravityView / Make stack described in
EVENTS_4.1_FUNC.md. Verified against the codebase and the local database on
5 September 2026. The companion EVENTS_4.1_REBUILD.md remains the design
contract; this document maps that design onto the code as built.

Unlike EVENTS_4.1_FUNC.md, this file carries no secrets, so it is safe to
track. Stripe keys live only in `wp-config.php` (`LAW_STRIPE_*` constants).

Where a Gravity Forms form or field is named, it is paired with its name per
house convention, e.g. form 2 (Event > submit an event), field 95 (Event
status). The rebuild retires those forms; they appear here only where the
migrator reads them or where the legacy source path still branches on them.

---

## 1. Where the feature lives

1. **The events module** (`functions/events/`): a self-contained package of ~28
   files loaded by one loader, `functions/events/_load.php`, which is required
   from `functions.php:30`. Everything new lives here: the custom post types,
   the workflow engine, direct Stripe invoicing, the migration tooling, the
   custom registration/profile/submission forms and the committee dashboard.
2. **Shared front-end files** (`functions/`): `calendar.php`, `speakers.php`,
   `account-events.php` and `auth.php` predate the rebuild and now branch on
   `law_events_source()` so they read either the legacy Gravity Forms entries
   (`'gf'`) or the migrated `law_event` posts (`'cpt'`).
3. **Templates, parts and assets** (`templates/`, `parts/events/`, `assets/`):
   the front-end views and the one shared form stylesheet/script.
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
capabilities → fees → log → workflow → comments → co-owners → notifications →
speakers → source → submission-form → registration → committee → Stripe (client,
service, webhook) → admin (fields, event/speaker/session screens, columns,
emails) → migration (report, runner, page).

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
- `law_events_settings_page()`, `law_events_settings_save()`: the read-only
  reference screen (keys are shown, edited in `wp-config.php`).

### `post-types.php`: the three custom post types

- Constants `LAW_EVENT_CPT = 'law_event'`, `LAW_SPEAKER_CPT = 'law_speaker'`,
  `LAW_SESSION_CPT = 'law_session'`.
- `law_events_register_post_types()` (on `init` priority 5): registers the
  three CPTs with a custom `capability_type` (`law_event`/`law_events`), no
  front-end archive of their own, `show_in_rest => false` (deliberate — nothing
  about a submitted event should reach the public REST API), and sessions
  hierarchical-by-`post_parent` under their event.
- `law_events_register_taxonomies()`: `law_event_type`, `law_sector` and
  `law_event_category` on events, plus `law_year` on events and speakers (the
  programme-year filter that keeps a 2026 event from re-filing into 2027).
- `law_events_maybe_flush_rewrites()`, `law_events_seed_terms()`: one-time
  rewrite flush and the default term seed (event types, sectors, categories,
  the current year).

### `statuses.php`: the custom workflow statuses

- `law_event_statuses()`: the six statuses and their labels — `law-draft`
  (Draft), `law-proposed` (Proposed), `law-sent-back` (Sent back),
  `law-approved` (Approved), `publish` (**Confirmed** — the only public one)
  and `law-rejected` (Rejected).
- `law_events_register_statuses()` (on `init` priority 6): `register_post_status`
  for each, with count labels for the admin list.
- `law_event_status_label()`: label for a status key or a post.
- `law_event_status_from_legacy()`: maps a legacy field 95 (Event status) value
  (Proposed/Sent back/Approved/Confirmed/Rejected) to a CPT status, used by the
  migrator.
- A `display_post_states` filter labels the statuses in the admin list.

### `meta.php`: the meta schema and the single read/write path

- `law_event_meta_schema()`, `law_speaker_meta_schema()`,
  `law_session_meta_schema()`: the ~45 meta keys and their types (text,
  `text_array`, `int_array`, `address`, `people_rows`, `speaker_rows`,
  `consent`, `stripe_error`, etc.). Every key is registered via
  `register_post_meta` in `law_events_register_meta()` (on `init` priority 7)
  with a per-type sanitiser and an auth callback.
- `law_events_sanitize_value()`: the one sanitiser, switched on type. The row
  types (`people_rows` for co-owners/contacts, `speaker_rows` for the
  event→speaker relationship) clean each subfield and drop empty rows — so the
  forms hand raw POST arrays straight in and the schema is the single cleaning
  path.
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
- `law_events_next_reference()`: the atomic LAW reference counter (e.g.
  `LAW26-00212`), seeded from the legacy max at migration.

### `capabilities.php`: roles and per-event access

- `law_events_capability_names()`, `law_events_grant_capabilities()` (on `init`
  priority 20): grants the `law_event`/`law_events` meta-caps to the roles —
  hosts get their own events, the committee cap `edit_others_law_events` gets
  all.
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

### `workflow.php`: the state machine

- `law_event_workflow_actions()`: the transition table — `submit`
  (draft → proposed, owner), `resubmit` (sent-back → proposed, owner),
  `approve` (proposed/sent-back → approved, committee), `send_back`
  (proposed → sent-back, committee), `reject` (proposed/sent-back → rejected,
  committee), `confirm` (approved → publish, system) and `mark_paid`
  (approved → publish, committee).
- **The status guard** (a `wp_insert_post_data` filter): an *existing*
  `law_event`'s status can only change through
  `law_event_workflow_transition()`. This is what stops the classic editor's
  Publish / Save Draft from confirming an unapproved event or parking it in a
  core status no dashboard shows. New inserts (form, migration, tests) pass
  through untouched. A second filter keeps the custom statuses out of the
  quick-edit dropdown.
- `law_event_workflow_transition()`: the single entry point. Validates the
  from-state and the actor's authority (`who`), flips the status, logs it, and
  fires side effects. Guarded against re-entrancy so a transition that calls
  `wp_update_post` doesn't recurse.
- `law_event_workflow_side_effects()`: on approve — snapshot the fee, create
  co-owner accounts, then either raise the Stripe invoice (paid) or confirm
  immediately (free); email the committee. On confirm — publish and email host
  + committee.
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
  to the committee. The `nopriv` variant redirects to login.
- `law_events_redirect_back()`, `law_events_rate_limit_ok()`: the shared
  redirect-with-notice helper and the per-IP/per-user rate limiter (transient
  keyed on surface + IP or user; keys on `REMOTE_ADDR`, so `X-Forwarded-For`
  spoofing does nothing).

### `co-owners.php`: additional owners as real accounts

- `law_event_ensure_co_owner_users()`: on approval (and on host/admin edits of
  an already-approved event), turns each co-owner row into access — creating an
  `event_host` account for a new email, or linking an existing account. Every
  action is logged.
- `law_event_notify_co_owner_linked()`: emails an *existing* account when it is
  linked as a co-owner, so access is never granted silently.
- `law_event_set_co_owner_ids()`: the single write path for `_law_co_owner_ids`
  (the array the module reads) plus one flat `_law_co_owner` meta row per ID
  (what the dashboard's owned-events query matches).
- `law_events_create_host_user()`: creates the account (username = email,
  random password, `event_host` role) and sends WordPress's new-user
  notification, which the auth module rebrands onto `/login/?action=reset`.
- `law_events_owned_event_ids()`: events a user owns or co-owns, for the host
  dashboard.

### `notifications.php`: the email registry

- `law_events_email_registry()`: every module email as a definition (slug →
  recipients, subject, body with `{placeholders}`) — `user_submitted`,
  `committee_submitted`, `user_sent_back`, `committee_resubmitted`,
  `committee_approved`, `user_payment_due`, `committee_payment_received`,
  `user_confirmed_paid`, `user_confirmed_free`, `user_rejected`,
  `committee_event_updated`, `committee_assignee`, and the Stripe-failure and
  refund alerts.
- `law_events_email()`: the registry entry with any admin override merged in
  (overrides live in one option, editable on the LAW > Emails screen).
- `law_events_email_placeholders()`: builds the merge values for an event
  (title, reference, host, fee, dashboard/committee/invoice links, etc.).
- `law_events_email_recipients()`, `law_events_send()`: resolve recipients and
  send, logging the send (with recipients) to the activity log. A £0 event
  splits to the "confirmed, free" template.

### `speakers.php`: speaker records and the archive (CPT mode)

- `law_speaker_find_existing()`, `law_speaker_normalise_name()`: dedupe by email
  first, then normalised name.
- `law_speaker_upsert()`: match-or-create a `law_speaker` from a submitted row;
  gap-fills empty fields only (never blanks an existing value), and logs to the
  event when it backfills a pre-existing shared record.
- `law_speaker_post_profile()`, `law_event_speaker_cards()`,
  `law_speaker_card()`: the profile and card shapes the archive/single views
  render.
- `law_speakers_confirmed_event_map()`: the archive source — one profile per
  speaker referenced by a Confirmed event (visibility derived from events, not
  status).
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

### `submission-form.php`: the custom submission/edit form (replaces form 2)

- `law_events_user_can_submit()`, `law_events_form_event_id()`,
  `law_events_locked_fields()`: submission eligibility, the edited event ID, and
  the per-status lock list (title, type, slots, fee, invoice, sectors, host
  orgs, capacity freeze after approval; description, speakers, venue, agenda,
  contacts and co-owners stay editable).
- `law_events_form_save()`: validation + persistence. **On update it always
  carries the existing title forward** — `wp_insert_post` fills any omitted key
  from its defaults, so leaving the (locked) title out would blank it and make
  the event vanish everywhere (`law_events_map_post()` treats an empty title as
  absent). Co-owner and contact rows go straight to the schema sanitiser.
- `law_events_validate_photos()`, `law_events_sideload_upload()`: server-side
  speaker-photo validation (real MIME sniff, 5 MB cap, pixel bounds) and the
  media sideload.
- `law_events_form_save_speakers()`, `law_events_form_save_sessions()`: upsert
  speakers/sessions and store the relationship rows.
- `law_events_form_handler()` (on `admin_post_law_event_form`): nonce,
  honeypot, rate limit, `law_user_can_manage_event()`, edit-lock respect, then
  save and redirect with a notice (`event-updated` etc.). The `nopriv` variant
  redirects to login.
- `law_events_form_state()`, `law_events_form_values()`,
  `law_events_form_reusable_input()`: transient-backed re-population of a failed
  submission (typed values win over stored values).
- A `wp_enqueue_scripts` closure loads `assets/css/event-form.css` and
  `assets/js/event-form.js`, adding WordPress's `password-strength-meter`
  dependency on the register/profile templates.

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

- `law_committee_events()`: the review queue query (status filter + search).
- `law_committee_status_counts()`: the filter-chip counts, via **one**
  `wp_count_posts()` call (not a capped per-status query).
- `law_committee_requested_event()`: the event opened in the detail panel.
- `law_committee_action_handler()` (on `admin_post_law_committee_action`):
  nonce + `law_user_is_committee()`, then the approve / send-back / reject /
  assign / set-slot / mark-paid actions, each routed through the workflow
  engine and logged. `law_event_apply_slot_label()` writes the confirmed slot.

### Stripe (`stripe/client.php`, `stripe/service.php`, `stripe/webhook.php`)

- **`client.php`** — `law_stripe_request()`: a thin `wp_remote_request` client
  (form-encoded, idempotency-key header on POSTs). `law_stripe_verify_signature()`:
  the webhook HMAC v1 check with `hash_equals` and a 300-second replay
  tolerance. Keys come from the `LAW_STRIPE_*` wp-config constants.
- **`service.php`** — `law_stripe_create_and_send_invoice()` and
  `law_stripe_invoice_steps()`: upsert the customer, create/finalise/send the
  invoice for the snapshot fee with the configured tax rate, resuming cleanly
  on a retry. `law_stripe_event_metadata()`: the shared metadata block
  (`law_reference`, `law_event_id`, `gf_entry_id`) attached to **both** the
  customer and the invoice so webhooks resolve. `law_stripe_upsert_customer()`,
  `law_stripe_maybe_attach_vat_number()`, `law_stripe_record_failure()`, and
  `law_event_handle_retry_invoice()` (a committee "retry invoice" admin-post).
- **`webhook.php`** — a REST route (`rest_api_init`) whose permission callback
  is `__return_true` (auth is the signature, verified on the raw body before
  decode). `law_stripe_webhook_handler()`: signature check → idempotency
  (processed-event list plus an atomic per-event lock against concurrent
  double-delivery) → dispatch. `law_stripe_handle_invoice_paid()` marks paid,
  reconciles the amount against the snapshot (via `law_events_vat_rate()`) and
  confirms the event; `invoice.payment_failed`/`voided`/refund are logged and
  alerted (never auto-unpublished — a human decision).
  `law_stripe_resolve_event_id()` resolves the event via metadata, with the
  `_law_gf_entry_id` meta fallback.

### Admin UI (`admin/`)

- **`fields.php`** — `law_field_text/number/textarea/select/checkbox/datetime/`
  `media/repeater/relationship()`: the reusable meta-box field renderers, plus
  a `wp_ajax_law_events_search_posts` endpoint and `law-admin.js` enqueue that
  power the speaker/organisation relationship pickers.
- **`event-screen.php`** — the custom event edit screen: meta boxes for
  workflow actions, fee (with override), programme facts, invoice contact,
  people (co-owners/contacts), speakers, sessions, the comment thread and the
  activity log. `law_event_admin_save()` (on `save_post_law_event`, nonce +
  cap + reentrancy guard) writes the meta, applies the slot via the shared
  helper, and routes committee actions through the workflow engine.
  `law_events_rows_from_post()` reads the repeater rows.
- **`speaker-screen.php`, `session-screen.php`**: the speaker and session edit
  meta boxes and their saves.
- **`columns.php`**: admin list columns (status, host, slot, payment), a status
  filter dropdown, and the `pre_get_posts` wiring for it.
- **`emails-screen.php`**: the LAW > Emails screen — list, edit, send-test and
  reset for the notification registry, overrides stored in one option.

### Migration (`migration/report.php`, `migration/runner.php`, `migration/page.php`)

- **`report.php`** — a custom log table (`law_migration_log`), `law_migration_log()`,
  per-step summaries and a tail for the admin panel, plus the snapshot-download
  and CSV-export admin-post handlers.
- **`runner.php`** — the engine. `law_migration_steps()` defines the ordered
  steps: snapshot → preflight → co-owners → speakers → events → sessions →
  comments → history → counters → redirects → notifications.
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
  `law_migration_spot_checks()` drive and verify a run.
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
- **`account-events.php`** (~259 lines): the host "My events" listing. In
  `'cpt'` mode it lists the user's owned/co-owned `law_event` posts, hosts the
  comment thread (`?law_thread=`), links to the custom edit form, shows a
  "Review queue" link to committee members and renders the save-confirmation
  notice.
- **`auth.php`** (~475 lines): the branded `/login/` (sign-in / forgot /
  reset) flow. `law_auth_redirect_to()` and a `login_redirect` filter send
  committee members to `/account/dashboard/` (not the host "My events" page).

---

## 4. Templates, parts and assets

- **Templates**: `register.php` (custom registration), `account-profile.php`
  (profile), `account-event-form.php` (submit/edit), `account-events.php`
  (My events + thread), `account-dashboard.php` (committee review queue),
  `event-single.php` (single event), `calendar.php` / `calendar-committee.php`
  (programme). The committee calendar template enforces
  `law_user_is_committee()` in code, not only via the Members plugin.
- **Parts** (`parts/events/`): `profile-fields.php` (the shared
  registration/profile field block, with conditional "Other: please specify"
  inputs), `people-repeater.php` (the co-owner/contact repeater on the front
  end), `thread.php` (the comment thread, host and committee contexts).
- **Assets**: `assets/js/event-form.js` (repeaters, conditional toggles, the
  WordPress-core `wp.passwordStrength` meter — score 5 = mismatch) and
  `assets/css/event-form.css` (the form, the committee dashboard and the thread;
  includes the light-section colour resets the dark-hero theme needs, the
  status-badge fix, and the mobile bottom clearance so the submit buttons clear
  the fixed header).

---

## 5. The source switch and cutover

- `law_events_source` (option): `'gf'` before cutover, `'cpt'` after. Every
  shared front-end file branches on `law_events_source()`; the module's own
  handlers assume CPT.
- The migration page flip (`migration/page.php`) also (de)activates the module
  Gravity Forms — forms 1–6, 8, 9 — via `GFFormsModel::update_form_active`,
  leaving form 7 (Contact) active. An inactive form is refused by the GF REST
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
migration DB-password handling and the rate-limit response). The following are
open **product decisions**, not bugs, left for Denis:

1. **Co-owner consent.** Linking an *existing* account as co-owner now emails
   that account (was silent). A stronger accept-before-link flow would change
   the settled "co-owner accounts on approval" behaviour and was not applied.
2. **No welcome email to new registrants** — only the admin new-user notice
   fires today.
3. **`/programme/` is Members-gated** to admin/editor/committee, which blocks
   logged-out visitors and hosts. Deliberate pre-launch lock, or open it up?
4. **Profile `roles[]` demotion** — unticking your current role silently drops
   you to `attendee`; no confirmation step.
5. Minor polish: a map-embed fallback state, the auto "Sponsored" badge on
   repeat *paying* hosts, and "Fee snapshot £0.00" showing on proposed events
   before approval.

**Reserved for 4.2 (present but intentionally unused):** the event meta key
`_law_registration_state` and its sanitiser (event-registration vocabulary),
the speaker `_law_organisation_ids`, and the `host_edit_review` setting (its UI
control is disabled). Leave until 4.2; do not cut.

**Post-cutover cleanup ticket:** once the source flip is permanent, the `'gf'`
branches in `calendar.php`, `speakers.php` and `account-events.php`, the
legacy-entry-ID resolvers, and the migration tooling can be deleted wholesale —
the single largest line-count reduction available, deliberately deferred while
rollback must stay alive.
