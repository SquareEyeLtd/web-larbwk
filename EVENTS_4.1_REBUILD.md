# Events module 4.1 rebuild: from Gravity Forms and Make to a custom system

Status: **draft for review**. Verified against the local database and codebase on
4 September 2026. Branch: `events-4.1-rebuild-custom`.

This is the plan for rebuilding the 4.1 events platform (submission, moderation,
invoicing, payment, publication) as custom, theme-owned code, replacing Gravity
Forms entries as the data store, Gravity Flow as the workflow engine, and Make.com
as the Stripe bridge. It is written so that the Phase 4.2 modules
(EVENTS_4.2_SPECS.md: bookings, basket, waitlists, attendee accounts) can be built
directly on top of it without re-architecting.

EVENTS.md remains the canonical description of the *current* system and stays
accurate until cutover. This file is the plan for the *next* one.

---

## 1. Why rebuild

The current build works, but it has structural costs we keep paying:

1. **The data lives in Gravity Forms entries.** Everything downstream (calendar,
   speakers, dashboards, SEO, future bookings) has to go through GFAPI and nested
   entry lookups instead of WP_Query. The theme already had to build a full
   entry-to-array mapping layer (`functions/calendar.php`, ~1,470 lines) just to
   render a listing. Field IDs are load-bearing and renumbering one silently
   breaks the module (EVENTS.md defect 1 is exactly this).
2. **The workflow is configuration, not code.** The Gravity Flow steps, routing
   rules and notification conditions live in the database, invisible to version
   control and code review. Two of the current five known defects are
   misconfigured steps that nothing could have caught.
3. **Make.com is a black box in the middle of the money path.** The two scenarios
   are outside version control, a Make outage silently completes the workflow
   (defect 4), and every structural form change requires manually re-checking
   scenarios nobody on the team can diff.
4. **Phase 4.2 multiplies all of this.** Bookings, waitlists, capacity, baskets
   and per-attendee records built on nested form entries and GravityView would
   deepen the dependency exactly when the data model needs to be at its most
   queryable. Building 4.2 on CPTs and custom code is cheaper than building it on
   the Gravity stack and migrating later.

What we gain: real WordPress objects (posts, users, comments, taxonomies),
version-controlled business logic, a direct Stripe integration with proper error
handling, and a data model that 4.2 plugs into.

What we lose, and must rebuild: the form rendering and validation Gravity Forms
gave us for free, Gravity Flow's inbox/timeline UI, GravityView's edit form with
entry locking and revisions, and GPNF's repeaters. Section 3 covers each
replacement. This is a large build; section 7 breaks it into phases so value
lands incrementally.

---

## 2. Inventory: everything being replaced or retired

Verified against the database on 4 September 2026.

### 2.1 Forms and their entry counts

| Form | Title | Active entries | Fate |
|---|---|---|---|
| 1 | User registration | 250 users registered | Rebuilt as a custom registration form (phase C) |
| 2 | Event > submit an event | 75 (160 trashed) | Rebuilt as the custom submission form; entries migrate to `law_event` posts |
| 3 | User profile | 17 | Rebuilt as a custom profile form (phase C) |
| 4 | Event > host contact | 51 | Becomes repeatable contact rows in event meta |
| 5 | Comments | 43 (49 trashed) | Becomes WP comments on the event post |
| 6 | Event > co-owner | 54 | Becomes real WP users linked to the event |
| 7 | Contact | (site contact form) | Out of events scope, but must be replaced before GF can be uninstalled (phase D) |
| 8 | Event > speaker | 186 | Becomes `law_speaker` posts |
| 9 | Event > session | 4 | Becomes `law_session` posts |

Trashed entries are **not** migrated, but the migrator reports them so nothing
disappears unnoticed.

### 2.2 The Gravity Flow workflow (form 2, Event > submit an event)

All 19 steps are replaced by the custom workflow engine (section 3.6):

- Step 5 (Committee review), step 8 (Clarification needed): the human steps,
  replaced by the committee moderation UI.
- Steps 22, 23, 24, 26, 27 (Set status to Approved / Confirmed / Rejected /
  Sent back / Proposed): replaced by explicit status transitions in code.
- Step 17 (Create Stripe invoice), step 19 (Log invoice URL), step 20 (Waiting
  for payment): replaced by the direct Stripe integration (section 3.7).
- Steps 12, 14, 21, 29, 30 (the notification steps): replaced by the
  notification layer (section 3.8).
- Step 7 (Create event listing) and step 13 (Publish event): already no-ops
  (EVENTS.md defect 3), nothing to replace.

### 2.3 Make.com scenarios

- Scenario A, "LAW > event approved > Stripe invoice": replaced by
  `Stripe_Service::create_invoice()` on the approval transition.
- Scenario B, "LAW > invoice paid > update entry": replaced by a signed Stripe
  webhook endpoint in WordPress.
- **Suspected scenario C**: form 1 (User registration)'s HubSpot feed 15 is
  disabled with the note "use Make instead", and the `make-read-only` REST key
  was last used 1 September 2026, so Make appears to also poll or sync user/
  entry data (probably to HubSpot). **Unconfirmed; needs checking in Make before
  cutover** (open question 3, section 9).

### 2.4 Plugins retired at the end of the rebuild

Gravity Forms, Gravity Flow, Gravity Flow Form Connector, Gravity Flow Incoming
Webhook, Gravity Perks Nested Forms, Gravity Perks Advanced Calculations,
GravityView + Advanced Filter + Inline Edit + Entry Revisions, and the GF User
Registration and Advanced Post Creation add-ons.

Kept: Members (page access), Pods (`organisation` and `person` CPTs), ACF,
SEOPress, If Menu. Note that form 1 (User registration) currently has an active
Advanced Post Creation feed 2 (Create sponsor organisation) creating
`organisation` posts on sponsor registration; the custom registration form must
reproduce that behaviour before APC goes.

### 2.5 Theme and mu-plugin code being replaced or re-pointed

- `functions/calendar.php`, `functions/speakers.php`,
  `functions/account-events.php`: keep the templates and presentation, swap the
  data layer from GFAPI to WP_Query (section 3.9).
- `functions/gravity-forms.php`, `functions/gravity-flow.php`,
  `functions/event-workflow.php`, `functions/migrate-speakers.php`: retired;
  their behaviours are absorbed into the new module.
- `mu-plugins/law-gf-country-iso.php`: logic moves into the invoice contact
  handling (country name to ISO stays, as a plain helper).
- `mu-plugins/law-secondary-host-users.php`: already dead code, deleted.
- `mu-plugins/law-user-profile-update.php`: replaced by the custom profile form
  writing user meta directly.
- GravityView views 386 (Events (hosts)), 419 (Events (committee - all)),
  443 (Events (committee - proposed)), 626 (Programme), 627 (Programme
  (committee)): all retired. 386 is the only one still doing work (the host
  edit form); its replacement is the custom host edit form (section 3.5).

---

## 3. Target architecture

Everything lives in the theme under `functions/events/`, namespaced
`LAW\Events`, loaded from `functions.php`. (A standalone plugin was considered;
the theme is the versioned repo on this project and the module is already
theme-centric, so staying in the theme keeps one history. If the module should
survive a theme swap later, it can be lifted into a plugin mechanically.)

```
functions/events/
  post-types.php        law_event, law_speaker, law_session registration
  statuses.php          custom post statuses + payment status meta
  meta.php              the meta schema, getters/setters, sanitisation
  capabilities.php      roles/caps mapping, per-event ownership checks
  fees.php              fee calculation, VAT flag, override handling
  workflow.php          the state machine: transitions, guards, audit log
  submission-form.php   front-end submit + edit form (render, validate, save)
  committee.php         committee dashboard actions (approve/send back/reject)
  comments.php          the clarification thread as WP comments
  co-owners.php         co-owner user creation and linking
  speakers-admin.php    speaker dedupe on save, admin columns, (4.2: merge tool)
  stripe/
    client.php          thin Stripe API wrapper (invoices, customers, webhooks)
    service.php         create/send invoice, handle invoice.paid, error states
    webhook.php         REST route, signature verification
  notifications.php     email templates + recipient settings
  settings.php          LAW settings page: programme week/slots, committee
                        recipients, Stripe keys, fee tiers
  migration/
    page.php            the Migration admin screen
    runner.php          batched, idempotent migration steps
    report.php          per-item logging, summary, CSV export
```

### 3.1 Content model

**CPT `law_event`** (rewrite slug `events`, not public until launch; the
Members-gated pages keep working as they do now).

- `post_title`: field 17 (Event title)
- `post_content`: field 23 (Description)
- `post_author`: the submitting host's user ID (currently `created_by`)
- Featured image: none in 4.1, reserved for 4.2 featured content

**Post statuses.** Core `publish` means what "Confirmed" means today: publicly
listable. The moderation states are registered custom statuses:

| Current field 95 (Event status) value | New post status |
|---|---|
| Proposed | `law-proposed` |
| Sent back | `law-sent-back` |
| Approved | `law-approved` |
| Confirmed | `publish` |
| Rejected | `law-rejected` |

Using real post statuses (rather than a status meta) keeps queries indexed and
lets the admin list table filter by state natively. `publish` for Confirmed
means sitemaps, feeds and third-party SEO tooling behave correctly with zero
special-casing.

**Payment status** stays separate, as meta `_law_payment_status`:
`unpaid` / `paid` / `refunded` / `free`, defaulting to `unpaid` at submission
(fixing EVENTS.md defect 1 by design).

**Taxonomies** (replacing choice lists, so LAW admin can edit terms without a
developer):

- `law_event_type`: from field 63 (Event type): Seminar / talk, Social event, Other
- `law_sector`: from field 60 (Sector), 11 terms
- `law_event_category`: from field 116 (Event category): LAW event, Hosted
  event, Session-level agendas
- `law_year`: programme year (e.g. `2026`). Every event, speaker link and (4.2)
  booking is year-tagged, which is what makes the 4.2 §3.8 archive requirement
  cheap later.

**Meta schema** (all keys registered via `register_post_meta` with sanitise
callbacks; ACF field groups provide the wp-admin editing UI over the same keys):

| Meta key | From form 2 field | Notes |
|---|---|---|
| `_law_reference` | 70 (Unique ID) | The LAW reference; preserved verbatim in migration |
| `_law_start` / `_law_end` | 68 (Confirmed slot), parsed | Real datetimes, replacing the hardcoded slot-label parsing. Slot *choices* move to the settings page |
| `_law_preferred_slots` | 77 (Preferred date & time slots) | Array of slot keys |
| `_law_venue` | 21 (Venue) | Free text, as now |
| `_law_venue_needed` | 103 (Venue needed) | |
| `_law_venue_capacity` | 55 (Venue capacity) | |
| `_law_tickets_available` | 54 (Tickets available) | Becomes the 4.2 capacity number |
| `_law_host_organisations` | 105 (Host organisation(s)) | Display text |
| `_law_organisation_ids` | 109 (Organisation) | `organisation` post IDs, keeps the sponsor-highlight logic |
| `_law_fee_tier` | 53 (Event fee) | `uk` / `international` / `sponsor` |
| `_law_fee_pence` | 84 (Calculated fee (pence)) | Snapshot written at approval by `fees.php`, not user input |
| `_law_vat` | 85 (VAT) | Computed as `fee > 0`, killing the price-literal fragility |
| `_law_fee_override` / `_law_fee_override_amount` | 87 (Override fee) / 81 (Discounted fee) | Committee-only |
| `_law_invoice_name` / `_law_invoice_email` | 75 / 73 | |
| `_law_invoice_address` | 74 (Address) | Array incl. country name |
| `_law_country_iso` | 88 (Country ISO) | Derived on save, as the mu-plugin does now |
| `_law_vat_number` | 79 (VAT number) | |
| `_law_stripe_customer_id` | (new) | Stored so 4.2 reuses the customer |
| `_law_stripe_invoice_id` / `_law_stripe_invoice_url` | (new) / 83 | ID as the primary key, URL for display |
| `_law_approved_at` | 78 (Approval date) | Set by the workflow |
| `_law_assignee` | 90 (Committee assignee) | User ID; change still triggers the assignee email |
| `_law_contacts` | 94 (Event contacts, form 4 children) | Array of {name, organisation, email} rows |
| `_law_co_owner_ids` | 106 (Additional event owners, form 6 children) | User IDs (section 3.4) |
| `_law_speakers` | 112 (Speakers, form 8 children) | Array of {speaker_id, role, organisation_override, sort}; role and override empty in 4.1, defined now for 4.2 §3.5 |
| `_law_registration_state` | (new, 4.2) | `open` / `apply` / `free` / `external` / `invitation` / `closed`; unused in 4.1 but registered so nothing re-migrates |
| `_law_gf_entry_id` | (migration) | The source form 2 entry ID; powers URL redirects and Stripe metadata continuity |
| `_law_rejection_reason` | 67 (Reason for rejection) | |

Submitter details (fields 3 Name, 7 Email, 100 ID, 101 Username) are not
duplicated into meta: `post_author` is the source of truth, as it should have
been all along.

### 3.2 Speakers: CPT `law_speaker`

- `post_title`: name; `post_content`: field 7 (Biography); featured image:
  field 6 (Photo), sideloaded into the media library at migration.
- Meta: `_law_speaker_email` (the dedupe key), `_law_organisation`,
  `_law_job_title`, `_law_website`, and `_law_organisation_ids` reserved for the
  4.2 "additional organisations" requirement.
- **Speakers are first-class and shared across events**, which the current
  per-event child entries are not. The event stores the relationship (in
  `_law_speakers`), so one person appearing at three events is one post: exactly
  the 4.2 §3.5 speaker directory model, delivered early.
- Dedupe moves from render time (`law_speakers_dedupe()`) to **save time**: when
  a host adds a speaker, we look up by email first, then normalised name, and
  attach the existing post rather than creating a duplicate. The 4.2 "use this
  existing speaker" suggestion UI and the admin merge tool bolt onto this.
- Visibility stays derived: a speaker renders publicly only while at least one
  `publish` event references them. No status juggling on the speaker itself.
- URLs become `/speakers/<post-slug>/`; the migration writes `_law_gf_entry_id`
  on each speaker so old `/speakers/<entry ID>/` links 301 to the new slug.

### 3.3 Sessions: CPT `law_session`

- `post_parent`: the `law_event`. Meta: `_law_start` / `_law_end` (times),
  `_law_speakers` (same row shape as events). Title and description map from
  form 9 (Event > session) fields 4 (Session title) and 5 (Description).
- Not publicly queryable on their own; rendered inside the event page as now.
- This is the 4.2 §3.6 enhanced agenda structure. The "enhanced agenda is
  opt-in per event" switch is simply whether sessions exist, plus the
  `law_event_category` term Session-level agendas where LAW wants an explicit
  flag.

### 3.4 Users, roles and co-owners

- Roles stay as they are (`event_host`, `events_committee`, `sponsor`,
  `attendee`); Members keeps gating pages. We add per-object checks in
  `capabilities.php`: `law_user_can_manage_event( $user_id, $event_id )` is true
  for the author, any co-owner, committee, editor and admin.
- **Co-owners become real users at submission** (fixing EVENTS.md defect 2 and
  meeting the 4.2 §4.1 requirement). For each row in the co-owners repeater:
  match an existing user by email, otherwise `wp_insert_user` with the
  `event_host` role and send the standard new-account email (password set via
  the branded reset flow that already exists in `functions/auth.php`). Store the
  user ID in `_law_co_owner_ids`. The host events dashboard queries
  `post_author = me OR _law_co_owner_ids CONTAINS me`.
- Timing: creation happens at submission rather than approval. The 4.2 spec
  says "on approval"; creating at submission is simpler (no deferred queue) and
  harmless (an account with access to one proposed event). Flagged as a
  decision to confirm with LAW (section 9).

### 3.5 The submission and edit forms

The single biggest rebuild item. A custom front-end form at
`/account/events/submit/` replacing the form 2 (Event > submit an event) embed:

- Server-rendered PHP form, one template part per section (Submitter details,
  Event details, Speakers, Venue, Owners and contacts, Fees, Session agenda,
  Additional information), POSTing to `admin-post.php` handlers with nonces.
  Progressive enhancement, no framework: this matches how the rest of the theme
  is built (the calendar filters set the pattern).
- Repeaters (speakers, sessions, co-owners, contacts) are a small vanilla-JS
  row-clone component with server-side validation of every row. This replaces
  GPNF. The speaker repeater includes the lookup-by-email "use existing
  speaker" behaviour (section 3.2).
- Validation errors re-render the form with the user's input intact; per-field
  messages. Files (speaker photos) upload via the standard WP media handling
  with type/size checks.
- **Draft saving**: submissions this long need it. Save creates the
  `law_event` post in a `law-draft` status visible only to its owner, so
  "save and continue later" is a status, not a separate mechanism.
- **Host editing** reuses the same form with a field whitelist by state:
  everything editable while `law-draft`/`law-sent-back`; after `publish`,
  only description, speakers, venue, agenda (the 4.2 §4.2 list); fee and slot
  fields locked after approval. Edits by a host on a published event fire the
  "event updated" committee email (replacing the Entry Revisions hook), and a
  wp post revision is recorded for audit.
- The old GravityView entry locking is replaced with `wp_set_post_lock` /
  heartbeat, which is native.

Registration (form 1), profile (form 3) and contact (form 7) forms are smaller
versions of the same pattern and land in a later phase (section 7); the events
flow does not wait for them.

### 3.6 The workflow engine

A small explicit state machine in `workflow.php`, not a plugin:

```
law-draft ──submit──▶ law-proposed ──approve──▶ law-approved ─┐
                        ▲      │                              │ invoice paid,
                        │      ├─send back─▶ law-sent-back    │ or fee = 0
                        │      │                 │            ▼
                        │      └─reject──▶ law-rejected    publish
                        └──────host resubmits────┘        (Confirmed)
```

- `LAW\Events\Workflow::transition( $event_id, $action, $args )` is the only
  way status changes. Each transition has guards (who may do it, from which
  status), side effects (fee snapshot, invoice creation, emails) and writes an
  **audit log entry**: a WP comment of type `law_event_log` on the event,
  recording actor, action, old/new status and any note. This replaces the
  Gravity Flow timeline and gives the committee a visible history.
- Approve computes and snapshots `_law_fee_pence` and `_law_vat` (honouring the
  committee override), writes `_law_approved_at`, then either raises the Stripe
  invoice (fee > 0) or goes straight to `publish` with `_law_payment_status =
  free` (fee 0), replacing Make scenario A's route 3.
- Send back requires a comment (the clarification thread, section 3.8-adjacent)
  and emails the host; the host's resubmit returns it to `law-proposed`.
  Rejection stores field 67 (Reason for rejection)'s replacement meta and emails
  the host. The whole 26/8/27 clarification loop becomes ~30 lines of code.
- The relabelled Gravity Flow vocabulary ("Proceed", "Send back") carries over
  into the new UI so the committee sees nothing unfamiliar.

**Committee UI**: the committee dashboard at `/account/dashboard/` is rebuilt
as a theme template (same pattern as the host dashboard rework already on this
branch): a filterable list of all events with status badges, and a detail view
with the facts, the comment thread, fee override controls and the
Approve / Send back / Reject actions. `/inbox/` and Gravity Flow's inbox
shortcode retire. This also pre-builds the muscle for 4.2 §5.2's flagship
application review screens.

### 3.7 Stripe, direct (replacing Make)

`stripe/` talks to the Stripe API server-side (official `stripe-php` via
Composer, committed vendor dir or bundled, consistent with how the project
handles dependencies; no runtime dependency on any middleware).

**On approval with fee > 0** (replacing scenario A):

1. Upsert the customer: search by `_law_stripe_customer_id`, else by invoice
   email; create with name, email, address (ISO country), and VAT number as a
   `eu_vat`/`gb_vat` customer tax ID when present.
2. Create the invoice: one line item for the snapshot `_law_fee_pence`,
   description "LAW <year> event hosting fee: <event title>", VAT applied per
   `_law_vat` (mechanism confirmed against the current scenario, open question
   1: fixed tax rate ID vs Stripe Tax), `days_until_due = 5`, metadata
   `law_reference` and `law_event_id` (plus `gf_entry_id` for continuity on
   migrated events).
3. Finalise and send; store `_law_stripe_invoice_id` and the hosted URL; email
   the host the payment-due notification with the link.
4. **On failure**: the event stays `law-approved` with an error flag meta, the
   site admin and committee get an alert email, and the committee detail view
   shows a "Retry invoice" button. No silent completion (fixing defect 4).

**On payment** (replacing scenario B): a REST route
`POST /wp-json/law/v1/stripe-webhook` receives `invoice.paid` (and
`invoice.payment_failed`, `invoice.voided` for the record), verifies the Stripe
signature with the endpoint secret, resolves the event by `law_event_id` (or
`gf_entry_id` via the migration map), sets `_law_payment_status = paid`,
transitions to `publish`, and sends the confirmed emails. Idempotent by Stripe
event ID (processed IDs stored, replays ignored). Refund events set
`refunded` and alert the committee but do not auto-unpublish; that stays a
human decision.

The two Gravity Flow incoming-webhook park steps, their credentials and the GF
REST keys all retire. **Xero**: the existing Stripe-to-Xero integration is
outside WordPress and should be unaffected, to be confirmed (open question 4).

### 3.8 Notifications

All current emails re-implemented in `notifications.php` as filterable PHP
templates with a shared branded wrapper:

| Current notification / step | New trigger |
|---|---|
| Email to user > event submitted; Email to committee > event submitted | submit transition |
| Step 30, Email to committee > event approved; step 21, Email to user > payment due | approve transition (payment-due only when fee > 0) |
| Clarification emails using `{latest_comment}` | send-back transition + host comment reply |
| Step 14, Email to committee > payment received; steps 12/29, confirmed emails | webhook paid transition (free events on approve) |
| Rejection email | reject transition |
| Email to committee > event updated (Entry Revisions) | host edit of a published event |
| Committee assignee change email | `_law_assignee` change |

Committee recipient list moves from hardcoded addresses inside notification
configs to a settings-page option. The sponsor/non-sponsor confirmed-email split
switches from tier = Sponsor to **fee = 0**, fixing the fee-waived wording
defect (EVENTS.md section 12.6). Mailpit remains the local test target.

The comment thread itself: form 5 (Comments) child entries become WP comments
(`comment_type = law_event_comment`) on the event, rendered in both the host
event view and the committee detail view, with email notifications to the other
party on reply. Ordinary WP comment moderation stays off for this type.

### 3.9 Front-end re-pointing

The public templates keep their markup and CSS; only the data layer changes:

- `functions/calendar.php`: `law_calendar_raw_entries()` and
  `law_calendar_map_entry()` are replaced by a WP_Query over `law_event`
  (status `publish` for public, all statuses for committee) and a mapper from
  post to the same event-array shape the templates already consume. Filters
  (keyword, sector, type) become `tax_query`/`meta_query` + search. The AJAX
  partial endpoint, day sections, filter UI and cards are untouched.
- Slot parsing goes away: `_law_start`/`_law_end` are real datetimes, and
  `law_calendar_week_days()` reads the programme week from settings instead of
  hardcoding it.
- `functions/speakers.php`: archive and profile read `law_speaker` posts;
  the confirmed-parents gate becomes "referenced by at least one publish
  event"; render-time dedupe is deleted (dedupe now happens at save).
- `functions/account-events.php`: host dashboard queries own + co-owned
  events; Edit points at the custom edit form; Comments points at the event's
  thread; Pay invoice reads `_law_stripe_invoice_url`.
- Single event pages move from `?event=<entry ID>` to `/events/<slug>/` (real
  permalinks, better SEO); the old query-string form 301s via
  `_law_gf_entry_id`.

### 3.10 Settings

One "LAW events" settings screen: programme year and week dates, the slot
choices (currently hardcoded in two places), committee recipient emails, fee
tier amounts, Stripe keys (secret, publishable, webhook secret; live and test),
and the toggle for which host-edit fields publish immediately vs route for
review (the 4.2 §4.2 "adjustable without a code change" requirement, present
from day one even if 4.1 publishes everything immediately).

---

## 4. How this sets up Phase 4.2

Checked against EVENTS_4.2_SPECS.md. The 4.2 spec's implementation notes assume
the Gravity stack (GravityView tables, Nested Forms baskets, eCommerce Fields);
those notes are superseded by this rebuild, but every *behavioural* requirement
maps cleanly, and mostly more naturally:

| 4.2 requirement | Where the rebuild leaves it |
|---|---|
| §3 programme views, event pages | Already CPT-backed; calendar/grid variants are template work over WP_Query |
| §3.4 registration states and booking controls | `_law_registration_state` meta exists from day one |
| §3.5 speaker directory, roles, additional orgs, merge tool | `law_speaker` CPT with email dedupe at save; role and org-override slots already in the relationship rows; merge tool = reassign relationships + delete |
| §3.6 enhanced agendas | `law_session` CPT, built in 4.1 |
| §3.8 archives | `law_year` taxonomy on everything |
| §4 host management, attendee lists, CSV | Bookings become a custom table or CPT keyed to `law_event`; host scoping reuses `law_user_can_manage_event()` |
| §4.4/§5.4 capacity and waitlists | `_law_tickets_available` is the capacity; bookings count against it; waitlist is a booking status + order column |
| §5 flagship apply/approve/charge | Reuses the workflow engine pattern (a second state machine over bookings) and the Stripe client; SetupIntent/off-session PaymentIntent are additions to `stripe/client.php`, not a new integration |
| §6 basket, 3-attendee cap, discount codes | Bespoke either way per the spec; a custom booking engine writing real rows beats nested-entry maths |
| §7 Stripe Checkout, VAT, receipts | Same Stripe account, customers already carry `_law_stripe_customer_id` |
| §8 attendee account area | Same account-page patterns already rebuilt on this branch |

The one 4.2 assumption genuinely invalidated is licensing-driven: "GravityView
does most of the heavy lifting" stops being true. The trade is deliberate:
custom tables/views we own, over configuration in plugins we work around.

---

## 5. Migration

### 5.1 Principles

- **Read-only on the source.** Gravity Forms data is never modified or deleted
  by the migrator. GF stays installed (deactivated after burn-in) and the
  tables remain as the audit archive.
- **Idempotent and resumable.** Every created object stores its source
  (`_law_gf_entry_id` on events, speakers, sessions; `_law_gf_child_entry_id`
  where relevant). Re-running skips already-migrated items and reports them, so
  a failed batch is re-run safely and the whole migration can be rehearsed
  locally, then on staging, then on live.
- **Dry-run first.** The default mode validates and reports what *would* happen,
  creating nothing, exactly like the existing speaker migrator.
- **Everything is reported.** Per-item outcome (created / skipped / error with
  reason), a persistent log, and a downloadable CSV report.

### 5.2 The Migration admin screen

A top-level wp-admin menu item, **LAW Migration** (admin-only capability):

- One card per migration step (5.3), each showing source count, migrated count,
  remaining, and last-run summary.
- Controls: Dry run / Migrate buttons per step, plus "Run all" in order. A
  global "Dry run" toggle guards the destructive path; the real run asks for
  confirmation.
- **Batched over AJAX**: each request processes a small batch (e.g. 10 parents)
  and returns progress, so the browser shows a live progress bar and log tail,
  and nothing hits PHP time limits. A `processing` lock prevents double-runs.
- Error handling: an item that throws is logged with entry ID, title and the
  exception message, the batch continues, and the step ends in a "completed
  with N errors" state with the failing items listed and individually
  re-runnable.
- Post-run **verification panel**: counts compared (entries vs posts, child
  entries vs speakers/sessions/comments/contacts), orphan checks, spot-check
  links (old URL → new URL side by side).

### 5.3 Migration steps, in dependency order

1. **Users for co-owners.** Walk form 6 (Event > co-owner) children (54 rows):
   match by email to existing users, create the rest as `event_host`. Report
   matched vs created. (No welcome email during migration; accounts are
   announced at cutover, decision for LAW.)
2. **Speakers.** Form 8 (Event > speaker) children (186 rows) plus any
   remaining form 2 field 48 (Speakers (list)) rows on unmigrated entries:
   dedupe by email then name (the same rules as today's render-time dedupe),
   create `law_speaker` posts, sideload photos to the media library. Every
   source entry ID maps to its post (many-to-one) for URL redirects.
3. **Events.** The 75 active form 2 (Event > submit an event) entries: create
   `law_event` posts with the full field mapping (section 3.1), map field 95
   (Event status) to post status, set `post_author` from `created_by`, attach
   taxonomies, link co-owner users and speaker posts, migrate field 94 (Event
   contacts, form 4 children) into `_law_contacts`. Payment status: since
   field 96 (Payment status) is blank on every active entry (defect 1), derive
   it: Confirmed + fee 0 → `free`; Confirmed + fee > 0 → `paid`; Approved with
   an invoice URL → `unpaid`; and report each derivation for committee
   sign-off.
4. **Sessions.** Form 9 (Event > session) children (4 rows) → `law_session`
   posts under their events, speaker multiselect values resolved through the
   step 2 map.
5. **Comments.** Form 5 (Comments) children (43 rows) → `law_event_comment`
   comments on the right event, authored to the matching user where the email
   resolves, timestamped from the entry date.
6. **Workflow audit seed.** One `law_event_log` comment per event recording
   the migration itself and the derived statuses.
7. **Redirects.** Persist the entry-ID → post map (an option or small table)
   powering 301s for `?event=<entry ID>` and `/speakers/<entry ID>/`.

### 5.4 Cutover sequence

1. Code deployed dark: CPTs registered, migration screen available, templates
   still reading GF.
2. Announce a short submission freeze to LAW (there are 75 events; minutes, not
   hours, of migration).
3. Run the migration on live, review the report and verification panel.
4. Flip the template data source (a single option/constant,
   `law_events_source`), so rollback is flipping it back.
5. Point Stripe's webhook at the new endpoint; disable Make scenarios A and B
   (in that order, so no event is double-handled). In-flight entries sitting at
   "Approved, awaiting payment" keep working because the new webhook resolves
   `gf_entry_id` metadata through the migration map.
6. Burn-in period with GF plugins still active but unused; then deactivate,
   then remove.

### 5.5 In-flight entries

Entries mid-workflow at migration time need explicit handling, all covered by
the status derivation in step 3: Proposed/Sent back events simply appear in the
new committee dashboard for action; Approved-unpaid events keep their live
Stripe invoice (the URL and ID migrate; the webhook map covers payment arriving
after cutover). Nothing needs to be re-invoiced.

---

## 6. Defects resolved by design

| EVENTS.md defect | How the rebuild closes it |
|---|---|
| 1. Payment status never recorded | `_law_payment_status` defaults to `unpaid`, written by the webhook and the free path; migration derives historical values |
| 2. Co-owners not created as users | Section 3.4, plus migration step 1 |
| 3. Create/Publish event steps are no-ops | Whole post-creation story replaced by the CPT itself |
| 4. Make outage silently completes workflow | Direct Stripe with error state, alert and retry (section 3.7) |
| 5. Step 30 fee condition saved but off | Notification conditions are code; the approved-email audience is decided explicitly (asking LAW: every approval, or paid only) |
| `?ec=` category prepopulation dead (EVENTS_4.1_FUNC.md §6) | The custom form reads `?ec=` natively into the category field |
| Fee-waived events get non-sponsor wording | Confirmed-email split on fee = 0, not tier |
| VAT flag matches price literals | `_law_vat` computed from fee > 0 |

---

## 7. Build phases

Sized S/M/L relative to each other, not calendar estimates.

**Phase A: foundations (M).** CPTs, statuses, taxonomies, meta schema,
capabilities, settings page, fees. Admin-side event editing via ACF groups.
Nothing user-facing changes.

**Phase B: the core flow (L).** Submission/edit form with repeaters and drafts;
workflow engine and audit log; committee dashboard and actions; comments
thread; co-owner user creation; notifications; Stripe service and webhook.
This is the bulk of the work and lands as one reviewable unit per sub-module.

**Phase B2: re-pointing (M).** Calendar, speakers, host dashboard, single event
permalinks and redirects switched to the CPT source behind the
`law_events_source` flag.

**Phase C: migration (M).** The Migration screen, runner, report, verification;
rehearsals local and staging; live cutover per section 5.4.

**Phase D: decommission (S).** Replace forms 1 (User registration), 3 (User
profile) and 7 (Contact) with custom equivalents (including the sponsor
organisation creation from form 1's feed), retire the GF plugin family,
GravityView views, Make scenarios, orphaned pages (`/inbox/`), and update
EVENTS.md to describe the new system.

4.2 then builds on phases A–B primitives (bookings, waitlist, basket, attendee
areas) rather than starting a second architecture.

---

## 8. Decisions taken (challenge these now, not in phase B)

1. **Theme module, not plugin**: the theme is the versioned artefact on this
   project; liftable later.
2. **Custom post statuses + `publish` = Confirmed**: native queries and admin
   filtering beat status-in-meta.
3. **Speakers deduped at save, shared across events**: matches 4.2 §3.5;
   migration performs the first dedupe pass.
4. **Server-rendered forms, vanilla JS repeaters**: no front-end framework;
   consistent with the theme's existing patterns.
5. **Stripe hosted invoices stay** for host fees: identical host experience to
   today; only the plumbing changes. (4.2 attendee payments add Checkout and
   SetupIntents on the same client.)
6. **Comments and audit trail as WP comments** with custom types: free
   threading, authorship and timestamps; no custom tables in 4.1. (4.2
   bookings likely justify a custom table; decided then.)
7. **Make removed entirely**, including error paths; nothing new is wired
   through it.

## 9. Open questions

**For LAW (via Denis):**

1. Co-owner accounts created at submission (recommended) or only on approval
   (as the 4.2 spec words it)?
2. Committee approved-event email: every approval, or paid events only?
   (Defect 5 needs the intent settled either way.)
3. Migration of the 160 trashed form 2 entries: confirm leave-behind.

**For Denis, about Make (screenshots wanted, see the message accompanying this
plan):**

1. Scenario A module-by-module: the exact Stripe calls, the customer upsert
   matching rule, how VAT is applied (a fixed tax rate ID? Stripe Tax?), the
   invoice description/footer fields, due date, and the metadata keys written.
2. Scenario B: the trigger config and every module after it.
3. Any other scenarios touching this site: the disabled HubSpot feed on form 1
   (User registration) says "use Make instead", and the `make-read-only` REST
   key was used on 1 September 2026, so something is reading regularly. What,
   and does it need replacing or re-pointing at the new data?
4. Stripe/Xero: is the Xero link a native Stripe app (unaffected by us) or
   routed through Make?
5. Stripe account access: can we get restricted API keys (invoices + customers
   + webhooks) and confirm test mode is usable for the rebuild?
