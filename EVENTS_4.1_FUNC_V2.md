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
the additional-host email round the same day, and updated 6 September 2026
for the pre-launch-gate host bypass, the new `404.php` and the time-boxed,
batched migration history step. The companion EVENTS_4.1_REBUILD.md remains the design contract;
this document maps that design onto the code as built.

Unlike EVENTS_4.1_FUNC.md, this file carries no secrets, so it is safe to
track. Stripe keys live only in `wp-config.php` (`LAW_STRIPE_*` constants).

Where a Gravity Forms form or field is named, it is paired with its name per
house convention, e.g. form 2 (Event > submit an event), field 95 (Event
status). The rebuild retires those forms; they appear here only where the
migrator reads them or where the legacy source path still branches on them.

---

## 1. Where the feature lives

1. **The events module** (`functions/events/`): a self-contained package of 30
   files (18 top level, 6 `admin/`, 3 `stripe/`, 3 `migration/`) loaded by one
   loader, `functions/events/_load.php`, which is required from
   `functions.php:30`. Everything new lives here: the custom post types, the
   workflow engine, direct Stripe invoicing, the migration tooling, the custom
   registration/profile/submission forms, the committee dashboard and the
   site-wide email test mode.
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
capabilities → fees → log → workflow → comments → unread → co-owners →
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
- `law_events_next_reference()`: the LAW reference counter (e.g.
  `LAW26-00212`), seeded from the legacy max at migration. It is a plain
  `get_option` → increment → `update_option`, **not atomic** — two genuinely
  simultaneous submissions could read the same counter and mint the same
  reference. Known, accepted at current volumes; if that changes, move the
  counter to a locked/`ON DUPLICATE KEY UPDATE` write.

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
- `law_event_ui_actions()`: the four actions the two committee UIs offer, the
  shared list both the dashboard handler and the wp-admin event screen check a
  posted action against.
- `law_event_available_ui_actions( $event )`: the subset of those four that is
  legal for the event's *current* status, read off the same from-lists the
  transition guard enforces. Both committee UIs (the dashboard detail view and
  the wp-admin Workflow box) render only these buttons/radios and their modals,
  so nobody is offered an action that would only bounce with "Cannot … an event
  that is Approved". Proposed offers Approve / Send back / Reject; Sent back
  offers Approve / Reject; Approved offers Mark paid & confirm; Confirmed and
  Rejected offer none (the dashboard still shows Save changes).
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
- `law_events_redirect_back()`, `law_events_rate_limit_ok()`: the shared
  redirect-with-notice helper and the per-IP/per-user rate limiter (transient
  keyed on surface + IP or user; keys on `REMOTE_ADDR`, so `X-Forwarded-For`
  spoofing does nothing).

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
  `user_co_owner_created`) so the copy is editable on LAW > Emails and every
  send lands in the event's activity log. An *existing* account is told it has
  been linked, so access is never granted silently; a *new* account gets the
  welcome with a branded `/login/?action=reset` set-password link minted here
  via `get_password_reset_key()`, plus `{forgot_link}` because the key expires
  after 24 hours.
- `law_event_set_co_owner_ids()`: the single write path for `_law_co_owner_ids`
  (the array the module reads) plus one flat `_law_co_owner` meta row per ID
  (what the dashboard's owned-events query matches).
- `law_events_create_host_user()`: creates the account (username = email,
  random password, `event_host` role) and sends **nothing** — the welcome is
  the caller's job so it can carry the event's context. It deliberately does
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
  admin notice); `law_events_test_mode_admin_notice()` puts a
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

- `law_events_email_registry()`: all 22 module emails as definitions (slug →
  recipients, subject, body with `{placeholders}`, trigger, active flag).
  - **Host**: `user_submitted`, `user_sent_back`, `user_payment_due`,
    `user_confirmed_paid`, `user_confirmed_free`, `user_rejected`,
    `user_new_comment`.
  - **Additional hosts** (co-owners, sent on approval, recipient passed in via
    the send call's `to`): `user_co_owner_created` (new account, carries
    `{set_password_link}` and `{username}`) and `user_co_owner_linked`
    (existing account, points at the dashboard and `{forgot_link}`).
  - **Committee**: `committee_submitted`, `committee_resubmitted`,
    `committee_approved`, `committee_payment_received`,
    `committee_event_updated`, `committee_assignee`, `committee_new_comment`,
    `committee_refund`.
  - **Admin / Square Eye**: `admins_user_registered`, `admin_stripe_error`, and
    the three inactive-by-default Square Eye copies `squareeye_submitted`,
    `squareeye_event_updated`, `squareeye_user_registered`.
- `law_events_email()`: the registry entry with any admin override merged in
  (overrides live in one option, editable on the LAW > Emails screen).
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
  `law_events_locked_fields()`: submission eligibility (committee, or the
  `event_host`/`sponsor` roles), the edited event ID, and the per-status lock
  list — title, type, preferred slots, fee tier, invoice block, sectors, host
  organisations, venue capacity and `venue_needed` all freeze once the event
  leaves draft/proposed/sent-back; description, speakers, venue, agenda, ticket
  allocations, contacts and co-owners stay editable.
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
  speakers/sessions and store the relationship rows.
- `law_events_form_handler()` (on `admin_post_law_event_form`): nonce,
  honeypot, rate limit (15 per 10 minutes), `law_events_user_can_submit()`,
  then `law_user_can_manage_event()` on an edit. Edit locking replaces
  GravityView entry locking: `wp_check_post_lock()` refuses the save with a
  named-editor message when someone else holds the lock, then
  `wp_set_post_lock()` takes it. Saves, transitions (`submit` from draft,
  `resubmit` from sent-back) and redirects with a notice (`event-updated`,
  `draft-saved`) or to the page 372 confirmation. The `nopriv` variant
  redirects to login.
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

- `law_committee_events()`: the review queue query (status filter + search).
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
  assign / set-slot / mark-paid actions, each routed through the workflow
  engine and logged, plus the fee override, event categories, linked
  organisations and private notes. `law_event_apply_slot_label()` writes the
  confirmed slot. A `law_terms_present` sentinel distinguishes "cleared" from
  "not on the form" for the checkbox and multi-select controls.
  **The action is whitelisted** against `law_event_ui_actions()` (`approve`,
  `send_back`, `reject`, `mark_paid`); anything else is logged as a refused
  action and bounced with a dashboard error. An empty action is the plain
  "Save changes" path and still falls through. The wp-admin event screen
  applies the same list to its `law_workflow_action` radio, so the two
  committee UIs cannot drift.

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
  refused unless the event is Approved and still unpaid).
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
  `law_migration_spot_checks()` drive and verify a run. **The history step is
  time-boxed**: a real run stops between events after ~20 seconds and returns
  `done => false`, and the page JS keeps requesting batches until `done` — one
  request per batch, so a proxy upstream timeout (hit on Kinsta staging, where
  the ~1,000 timeline inserts exceeded it in a single request) can no longer
  kill the step. The guard never fires mid-event, so the per-event
  `_law_history_migrated` flag still guarantees an event's timeline is written
  whole or not at all.
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
  `event-single.php` (single event), `calendar.php` / `calendar-committee.php`
  (programme). The committee calendar template enforces
  `law_user_is_committee()` in code, not only via the Members plugin.
- **Layout parts** (`parts/layout/`): `back-link.php`, `hero-title.php` and
  `modal.php`, the reusable confirmation dialog. Pass it an id, a title, copy
  paragraphs, an optional note field and the confirm button, and it renders the
  markup `law-modal.css` and `law-modal.js` expect and enqueues both itself.
- **Parts** (`parts/events/`): `profile-fields.php` (the shared
  registration/profile field block, with conditional "Other: please specify"
  inputs), `people-repeater.php` (the co-owner/contact repeater on the front
  end), `thread.php` (the comment thread, host and committee contexts, which
  re-checks `law_user_can_manage_event()` itself rather than trusting its
  caller), `thread-bubble.php` (one message bubble, shared by the thread loop
  and the AJAX reply response so the two markups cannot drift),
  `dashboard-list.php` (the committee event table, rendered both
  inline and as the `law_partial` AJAX response).
- **Front-end assets**: `assets/js/event-form.js` (repeaters, conditional
  toggles, the WordPress-core `wp.passwordStrength` meter — score 5 = mismatch,
  and the `data-law-toggle-for` show/hide used by the committee panel's
  override amount) and
  `assets/css/event-form.css` (the form, the committee dashboard and the
  thread; includes the light-section colour resets the dark-hero theme needs,
  the status-badge fix and the mobile bottom
  clearance so the submit buttons clear the fixed header).
  `assets/css/law-modal.css` and `assets/js/law-modal.js` are the standalone
  confirmation-modal component (with `functions/modal.php` and
  `parts/layout/modal.php`): the JS hides and disables every
  `[data-law-modal-fallback]` block, turns each `[data-law-modal-open]` button
  into an opener, enables only the `[data-law-modal-field]` in the open dialog
  and traps Tab inside it. The committee dashboard uses it for the four
  actions, Approve, Send back, Reject and Mark paid & confirm, though only the
  ones legal for the event's current status are rendered
  (`law_event_available_ui_actions()`); every button stays a plain `law_action`
  submit without JavaScript.
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
migration DB-password handling and the rate-limit response).

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
