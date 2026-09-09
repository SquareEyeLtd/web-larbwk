# Events module 4.1 rebuild: from Gravity Forms and Make to a custom system

Status: **built and migrated locally** (4 September 2026); see section 10 for
the implementation notes and deliberate deviations. Branch:
`events-4.1-rebuild-custom`.

This is the plan for rebuilding the 4.1 events platform (submission, moderation,
invoicing, payment, publication) as custom, theme-owned code, replacing Gravity
Forms entries as the data store, Gravity Flow as the workflow engine, and Make.com
as the Stripe bridge. It is written so that the Phase 4.2 modules
(EVENTS_4.2_SPECS.md: bookings, basket, waitlists, attendee accounts) can be built
directly on top of it without re-architecting.

EVENTS.md remains the canonical description of the *current* system and stays
accurate until cutover. This file is the plan for the *next* one.

---

## 0. Context for the implementing agent

This document is written to be sufficient on its own. Alongside it, know:

- **Read EVENTS.md first**: the canonical description of the current system,
  its forms, workflow and defects. `EVENTS_4.1_FUNC.md` is a **gitignored**
  working reference that also holds the Stripe test keys and the verified
  test-mode tax rate / rendering template IDs; never copy its contents into a
  tracked file.
- **Verifying against the database**: wp-cli is the phar at
  `/home/gusev/.local/bin/wp-cli.phar`, run from `/srv/http/law`, and it
  **must** be run as `php -d memory_limit=512M …` (the default 128M fatals
  while Pods autoloads). Verify form/field/step facts against the DB, not
  against docs, which can drift.
- **House rule**: in anything you write for Denis (reports, docs, commit
  messages), pair every Gravity Forms form, field, step, view and page ID
  with its name, every time: "field 95 (Event status)", never "field 95".
- **Local environment**: outgoing mail lands in Mailpit
  (http://localhost:8025); the Postmark plugin is neutralised locally by
  `mu-plugins/block-emails.php`. Stripe test keys are wp-config constants
  (section 3.10); the Stripe CLI is installed at `/usr/bin/stripe` for
  webhook forwarding (section 3.7). Playwright browser testing is available
  for the E2E smoke tests (add `.playwright/` and `.playwright-cli/` to
  .gitignore before first use).
- **Conventions**: new admin screens go under the existing LAW menu using the
  AME-compatible pattern (section 5.2); first-party JS goes in `assets/js/`
  (not `assets/js/vendor/`); admin editing UIs are custom meta boxes, not ACF
  (section 3, admin editing note); work happens on the
  `events-4.1-rebuild-custom` branch.
- **Documentation duty**: update EVENTS.md in the same piece of work as any
  change to what it describes, and keep this plan's decisions sections
  accurate as build reality intrudes.

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
| 1 | User registration | 250 users registered | Rebuilt as a custom registration form (phase D) |
| 2 | Event > submit an event | 75 (160 trashed) | Rebuilt as the custom submission form; entries migrate to `law_event` posts |
| 3 | User profile | 17 | Rebuilt as a custom profile form (phase D) |
| 4 | Event > host contact | 51 | Becomes repeatable contact rows in event meta |
| 5 | Comments | 43 (49 trashed) | Becomes WP comments on the event post |
| 6 | Event > co-owner | 54 | Becomes real WP users linked to the event |
| 7 | Contact | (site contact form) | **Stays on Gravity Forms indefinitely** (Denis, September 2026). Its notifications remain GF-managed; it has no feeds |
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

### 2.3 Make.com scenarios (verified from Make screenshots, 4 September 2026)

The account holds **five** scenarios, all identified and dispositioned:

- **"LAW > event approved > Stripe invoice"** (enabled): replaced by
  `Stripe_Service::create_invoice()` on the approval transition. Full verified
  module map in section 2.3.1.
- **"LAW > invoice paid > update entry"** (enabled): replaced by a signed
  Stripe webhook endpoint in WordPress. Verified contents: a Make custom
  webhook ("Stripe (LAW, invoice paid)", so the Stripe dashboard webhook for
  `invoice.paid` points at hook.eu1.make.com), then one Gravity Forms module
  POSTing `entries/{data.object.metadata.gf_entry_id}/workflow-hooks` with
  step 20 (Waiting for payment)'s key/secret and `"payment_status": "Paid"`
  (the value that lands on the nonexistent field 86, EVENTS.md defect 1).
- **"LAW > new user registration > tag in HubSpot"** (disabled): the
  counterpart of form 1 (User registration)'s disabled HubSpot feed 15.
  Disabled on both sides, so nothing to replace, but confirm it stays off.
- **"LAW: log submitted event in HubSpot"** (disabled, 0 runs): never used;
  nothing to replace.
- **"Raindrop to Discovery (law firms)"** (enabled, scheduled): Raindrop
  bookmarks to a WordPress site. Unrelated to the events module; confirmed
  out of scope (Denis, 4 September 2026).

That is the complete list (all five identified, 4 September 2026). No enabled
scenario **reads** Gravity Forms data on a schedule, so the `make-read-only`
REST key's 1 September access was most likely Make verifying the stored
connection rather than a data sync. Low risk; the key simply retires with the
others at cutover.

### 2.3.1 Scenario A verified module map

Webhook `larbwk-event-submit` (receives the whole entry from step 17, Create
Stripe invoice; the payload still carries ~116 keys including deleted legacy
field IDs) → **Stripe Search Customers** by email = field 73 (Invoice contact
email) → **Array aggregator** (Customer ID, Email) → **Upsert customer**: POST
`/v1/customers/{id}` when found, else `/v1/customers` (connection "LAW: live",
`Stripe-Version: 2025-09-30.clover`), body mapping:

- `name` and `business_name` ← entry field **8, which no longer exists on
  form 2 and has zero rows in the database**, so every customer is created or
  updated with an empty name (see the defect note below);
- `individual_name` ← field 75 (Invoice contact name), inputs 75.3 + 75.6;
- `email` ← field 73 (Invoice contact email); `address[*]` ← field 74
  (Address) inputs; `address[country]` ← field 88 (Country ISO);
- `metadata[gf_entry_id]` ← entry ID; `metadata[law_reference]` ← field 70
  (Unique ID).

Then a **router** with three filtered routes:

1. **"VAT number exists"** (field 79 VAT number exists AND field 84
   Calculated fee (pence) > 0): POST `/v1/customers/{customer}/tax_ids` with
   `type = if(substring(field 79; 0; 2) = "GB"; gb_vat; eu_vat)` and `value` =
   field 79 (VAT number), under a Resume error handler, so a failed VAT
   attach is swallowed and the run continues. Note the gap this creates: any
   non-GB, non-EU VAT/tax number is sent as `eu_vat`, which Stripe rejects,
   and the rejection is silently swallowed. The rebuild validates the format
   and logs the failure instead.
2. **"Money is due"** (field 84 > 0): Tools (InvoiceContactName = 75.3 +
   75.6) → **Create invoice** POST `/v1/invoices` with
   `customer={upserted id}`, `collection_method=send_invoice`,
   `days_until_due=5`, `auto_advance=false`, a custom field
   `Attention = {InvoiceContactName}` printed on the invoice,
   `rendering[template] = inrtem_1SSbTmPhJqxRqE2K5Ppdv1Lq` (a branded Stripe
   invoice rendering template), and metadata `gf_entry_id` + `law_reference`
   (field 70, Unique ID) → Tools sets `lineAmount` = field 84 and `lineTax` =
   `if(field 85 VAT = 1; "&tax_rates[0]=txr_1TkIOyPhJqxRqE2KyejShg1c"; "")` →
   **Create line item** POST `/v1/invoiceitems` (`customer`, `invoice`,
   `currency=gbp`, `amount=lineAmount`, `description=Event fee for {field 17
   Event title}`, plus the lineTax suffix) → **Send invoice** POST
   `/v1/invoices/{id}/send` → **Get invoice details** GET → **Log Stripe
   invoice URL**: workflow-hooks POST with step 19 (Log invoice URL)'s
   credentials and `stripe_url = hosted_invoice_url`.
3. **"Zero fee"** (field 84 = 0): **Skip logging invoice** (workflow-hooks
   POST releasing step 19, Log invoice URL) → **Set payment status to Free**
   (workflow-hooks POST with step 20, Waiting for payment's credentials and
   `payment_status: "Free"`, the value that lands on the nonexistent field
   86, like the Paid write).

So the open VAT question is answered: **a fixed Stripe tax rate,
`txr_1TkIOyPhJqxRqE2KyejShg1c`, applied to the line item when field 85 (VAT)
= 1. Not Stripe Tax.** The rebuild stores that tax rate ID in settings and
applies it the same way. (EVENTS_4.2_SPECS.md §7.2 previously said "Stripe Tax
handles the calculation"; that wording has been corrected to the fixed-rate
approach, per Denis, September 2026.)

**Newly found defect (live): Stripe customers have no name.** The upsert maps
`name`/`business_name` from deleted field 8; the database has zero rows for
it, so the values sent are always empty and only `individual_name` (the
invoice contact) is populated. The rebuild maps the customer name explicitly:
`name` from field 75 (Invoice contact name), `business_name` from field 105
(Host organisation(s)).

Note: the screenshots also expose the workflow-hook keys/secrets for steps 19
and 20. They die with the rebuild (the endpoints are removed), and until then
they only release parked steps, but treat the screenshots as sensitive.

### 2.4 Plugins retired at the end of the rebuild

Scope decision (Denis, September 2026): **Gravity Forms core stays
installed**, because form 7 (Contact) remains a GF form with GF-managed
notifications. Only the events module goes custom. What retires, verified
against the plugins directory on 4 September 2026, is the module's add-on
stack: Gravity Flow, Gravity Flow Form Connector, Gravity Flow Incoming
Webhook, Gravity Flow Stripe, the GF User Registration, Advanced Post
Creation, HubSpot, Stripe, Survey and Webhooks add-ons, Gravity Perks Nested
Forms, Advanced Calculations, Advanced Select, Inventory, Populate Anything
and Unique ID, GW Auto Login, GW Word Count, Gravity PDF, GravityView +
Advanced Filter + DataTables + Entry Revisions + Inline Edit, and
wdt-gravity-integration. Forms 1 (User registration), 2 (Event > submit an
event), 3 (User profile), 4, 5, 6, 8 and 9 (the nested children) are marked
inactive after migration, leaving form 7 (Contact) as the only active GF
form; the entry tables stay as the read-only archive either way.

Three of those carry behaviour the rebuild must absorb, beyond the obvious:

- **GP Unique ID** generates field 70 (Unique ID), format `LAW26-00206`
  (`LAW<yy>-<5-digit sequence>`). The rebuild generates new references in the
  same format in code (`_law_reference`), continuing the existing sequence;
  migration preserves existing values verbatim.
- **GW Auto Login** signs the new user in after form 1 (User registration)'s
  confirmation redirect. The custom registration form (phase D) reproduces
  auto-login on successful registration.
- **Gravity PDF** has one feed on form 2 (Event > submit an event): "Invoice",
  template `larbweek-event-invoice`, **inactive**. Nothing to replace; noted
  so nobody reactivates it mid-rebuild.

Also checked: wpDataTables (which stays) has one GF-sourced table, table 1
("Events"), embedded on no page, so retiring `wdt-gravity-integration` breaks
nothing; and Code Snippets holds no active event-related snippets.

Kept: Members (page access), Pods (`organisation` and `person` CPTs), ACF,
SEOPress, If Menu.

**Newly found dead configuration (audited 4 September 2026):** form 1 (User
registration)'s Advanced Post Creation feed 2 (Create sponsor organisation) is
active but **can never fire**: its condition tests field 7 = "other", and
field 7 (the old organisation select) was deleted from the form. The entire
registration-organisation cluster died with it: the feed, the theme's
`gppa_input_choices_1_7` "My organisation is not listed" filter,
`law_set_organisation_user_meta()` (reads field 7) and the `orgid`
prepopulation are all referencing the removed field. Since then, registering
users get no ACF `organisation` user meta, and `organisation` posts are being
created manually. Form 2 field 109 (Organisation), which drives the sponsor
highlight, is unaffected: it has prepopulation off and is manually curated
(7 entries carry values). Phase D therefore does **not** reproduce the feed;
whether to resurrect organisation selection at registration is a small LAW
product decision, and the custom registration form can add it cleanly if
wanted.

### 2.6 Form settings, confirmations and personal data (audited 4 September 2026)

Checked on every form so the custom forms reproduce real behaviour, not
assumed behaviour:

- **Require login**: form 2 (Event > submit an event) only. The custom
  submission form keeps a server-side logged-in check in its handlers, on top
  of the Members page gate.
- **Honeypot**: enabled only on child forms 4 (Event > host contact) and 6
  (Event > co-owner); form 2 itself has none. The section 3.11 spam
  protection exceeds current parity everywhere.
- **Entry limits, scheduling, save-and-continue**: all off on every form.
- **Personal data settings: unconfigured on every form.** No retention
  policy, no IP-capture prevention, no export/erase wiring anywhere, so there
  is no GF privacy behaviour to reproduce. (GF stores submitter IPs on
  entries by default; the new module simply doesn't collect IPs on events.)
- **Confirmations**: form 2 → page 372 (Event submitted,
  `/account/events/submit/done/`), reproduced by the custom form
  (section 3.5). Form 1 (User registration) → redirect to `/account/` with
  `action=registered` (the `[action-message]` panel plus auto-login),
  reproduced in phase D. Form 3 (User profile) and all child forms → plain
  message confirmations.
- **Notifications outside form 2**: form 1 has one **active** notification,
  "Email to admins > user registration" (to the LAW admins), which phase D
  must carry over, plus the inactive "Email to Square Eye > user
  registration". Forms 3, 4, 5, 6, 8 and 9 carry only the inactive default
  admin notification: nothing fires, nothing to migrate (the migrator reports
  them as skipped by design).
- **Webhooks add-on**: zero feeds; the only outbound webhook is Gravity Flow
  step 17 (Create Stripe invoice), as documented.

### 2.5 Theme and mu-plugin code being replaced or re-pointed

- `functions/calendar.php`, `functions/speakers.php`,
  `functions/account-events.php`: keep the templates and presentation, swap the
  data layer from GFAPI to WP_Query (section 3.9).
- `functions/gravity-forms.php`, `functions/gravity-flow.php`,
  `functions/event-workflow.php`, `functions/migrate-speakers.php`: retired;
  their behaviours are absorbed into the new module.
- `mu-plugins/law-gf-country-iso.php`: logic moves into the invoice contact
  handling (country name to ISO stays, as a plain helper).
- `mu-plugins/law-secondary-host-users.php`: already dead code; removed at phase D with the rest of the decommission.
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
  admin/
    fields.php          shared custom field renderers: text, number, select,
                        media (photo), datetime, repeater, relationship picker
    event-screen.php    law_event meta boxes: facts, fee/invoice, workflow
                        actions, relationships, comment thread, audit log
    speaker-screen.php  law_speaker meta boxes: contact fields, photo,
                        related events (read-only reverse relationship)
    session-screen.php  law_session meta boxes: times, speakers, parent event
    columns.php         admin list-table columns and filters per CPT
  stripe/
    client.php          thin Stripe API wrapper (invoices, customers, webhooks)
    service.php         create/send invoice, handle invoice.paid, error states
    webhook.php         REST route, signature verification
  notifications.php     email default templates, placeholder rendering, sending
  admin/emails-screen.php  LAW > Emails: list, edit, send test, reset
  settings.php          LAW > Events settings: programme week/slots, committee
                        recipients, fee tiers, Stripe tax rate/template IDs
  migration/
    page.php            the Migration admin screen
    runner.php          batched, idempotent migration steps
    report.php          per-item logging, summary, CSV export
```

**Admin editing is custom-built, not ACF.** Each CPT gets hand-built meta
boxes on its wp-admin edit screen (per Denis, September 2026: the team has
used ACF for admin fields historically, but the rebuild CPTs get custom forms
so admins can view and manage every item natively). `admin/fields.php` is a
small shared renderer library (text, number, select, datetime, media-library
photo, repeater rows, and a relationship picker with AJAX search for
speaker-to-event and organisation links), so each screen composes the same
components rather than hand-rolling markup per field. Saving goes through the
same `meta.php` sanitisers as the front-end forms, one code path for
validation. ACF stays installed for the rest of the site but is not used by
the events module.

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
| (no equivalent: unsubmitted) | `law-draft`, the save-and-continue state, visible only to its owner (section 3.5) |
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
- ~~`law_event_category`: from field 116 (Event category): LAW event, Hosted
  event, Session-level agendas~~ — built for parity, then removed on
  9 September 2026 as unused (see EVENTS_FUNC.md, `post-types.php`)
- `law_year`: programme year (e.g. `2026`). Every event, speaker link and (4.2)
  booking is year-tagged, which is what makes the 4.2 §3.8 archive requirement
  cheap later.

**Meta schema** (all keys registered via `register_post_meta` with sanitise
callbacks; the custom admin screens in `admin/` provide the wp-admin editing
UI over the same keys, not ACF):

| Meta key | From form 2 field | Notes |
|---|---|---|
| `_law_reference` | 70 (Unique ID) | The LAW reference; preserved verbatim in migration. New events get `LAW<yy>-<5-digit sequence>` generated in code, continuing the GP Unique ID sequence |
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
| `_law_speakers` | 112 (Speakers, form 8 children) | Array of {speaker_id, role, organisation, job_title, photo_id, bio, sort}: the speaker's **appearance** at this event (field 9, field 3, field 4, field 6, field 7 of the child entry). Role is the `speaker` / `host` / `moderator` key, `''` = Speaker, per event not per person (Trevor, 3 September 2026: form 8 gained field 9 (Role), a drop down of those three; built 8 September 2026 — this is the 4.2 §3.5 role, delivered early and per appearance rather than per profile). (Changed 8 September 2026 from {…, organisation_override, …}: details are per event, not per person.) |
| `_law_registration_state` | (new, 4.2) | `open` / `apply` / `free` / `external` / `invitation` / `closed`; unused in 4.1 but registered so nothing re-migrates |
| `_law_gf_entry_id` | (migration) | The source form 2 entry ID; powers URL redirects and Stripe metadata continuity |
| `_law_stripe_error` | (new) | Set when invoice creation fails: the error message and timestamp; cleared on successful retry. Drives the alert and the Retry button (section 3.7) |
| `_law_rejection_reason` | 67 (Reason for rejection) | |
| `_law_sector_jurisdiction` | 61 (Jurisdiction-specific: please specify) | Free-text qualifier for the Jurisdiction-specific sector choice |
| `_law_sector_other` | 62 (Other/sector-neutral: please specify) | Free-text qualifier for the Other / sector-neutral choice |
| `_law_terms_consent` | 69 (Terms & conditions) | Consent value and timestamp; the acceptance record must survive migration |

Submitter details (fields 3 Name, 7 Email, 100 ID, 101 Username) are not
duplicated into meta: `post_author` is the source of truth, as it should have
been all along.

### 3.2 Speakers: CPT `law_speaker`

- `post_title`: name; `post_content`: field 7 (Biography); featured image:
  field 6 (Photo), sideloaded into the media library at migration.
- Meta: `_law_speaker_email` (the dedupe key), `_law_website`, and
  `_law_organisation_ids` reserved for the 4.2 "additional organisations"
  requirement. **Organisation, job title and photo are not speaker meta**
  (decided 8 September 2026): they are per appearance, on the event's
  `_law_speakers` row, because the same person speaks for different firms and
  under different titles at different events and the client wants each event
  (and the profile's per-event list) to show what was submitted for it. The
  featured image is only a fallback photo. The archive card and the profile
  use the speaker's FIRST appearance (the first-submitted confirmed event, by
  creation date, not the earliest event date); an event page shows the photo
  set for that event, else the first one ever provided, else the initials
  placeholder; the
  profile page shows no headline organisation or job title, only the per-event
  lines on its "Speaking at" cards.
- **Speakers are first-class and shared across events**, which the current
  per-event child entries are not. The event stores the relationship (in
  `_law_speakers`), so one person appearing at three events is one post: exactly
  the 4.2 §3.5 speaker directory model, delivered early.
- Dedupe moves from render time (`law_speakers_dedupe()`) to **save time**: when
  a host adds a speaker, we look up by email first, then normalised name, and
  attach the existing post rather than creating a duplicate. The 4.2 "use this
  existing speaker" suggestion UI and the admin merge tool bolt onto this.
  Reality check (scanned 4 September 2026): only 13 of the 186 existing
  speaker entries carry a valid email, so in practice the name fallback does
  most of the matching today. The custom speaker repeater should nudge hosts
  towards providing the email (it is what makes 4.2's dedupe reliable) while
  keeping it optional, as now.
- Visibility stays derived: a speaker renders publicly only while at least one
  `publish` event references them. No status juggling on the speaker itself.
- URLs become `/speakers/<post-slug>/`; the migration writes `_law_gf_entry_id`
  on each speaker so old `/speakers/<entry ID>/` links 301 to the new slug.

### 3.3 Sessions: CPT `law_session`

- `post_parent`: the `law_event`. Meta: `_law_start_time` / `_law_end_time` (HH:MM),
  `_law_speakers` (same row shape as events). Title and description map from
  form 9 (Event > session) fields 4 (Session title) and 5 (Description).
- Not publicly queryable on their own; rendered inside the event page as now.
- This is the 4.2 §3.6 enhanced agenda structure. The "enhanced agenda is
  opt-in per event" switch is simply whether sessions exist. (The
  `law_event_category` term Session-level agendas was the original explicit
  flag; the taxonomy was removed on 9 September 2026 as unused, so if LAW
  wants an explicit switch it needs a new field in 4.2.)

### 3.4 Users, roles and co-owners

- Roles stay as they are (`event_host`, `events_committee`, `sponsor`,
  `attendee`); Members keeps gating pages. We add per-object checks in
  `capabilities.php`: `law_user_can_manage_event( $user_id, $event_id )` is true
  for the author, any co-owner, committee, editor and admin.
- **The `events_committee` role currently has almost no capabilities**
  (verified 4 September 2026): just `read` plus the GravityView cap set, no
  wp-admin content access at all; committee members work entirely through the
  front-end views (Marie also holds `editor`, but that is her account, not
  the role). `capabilities.php` therefore grants `events_committee` the
  mapped caps for `law_event`, `law_speaker` and `law_session` (edit, read,
  upload for photos) so the wp-admin event screens work for the whole
  committee, and the orphaned GravityView caps retire with the plugin.
- **Co-owners become real users on approval** (decided by Denis, September
  2026, matching the 4.2 §4.1 wording; fixes EVENTS.md defect 2). The
  submitted co-owner rows (name, organisation, email) are stored on the event
  at submission; the **approve transition** then walks them: match an existing
  user by email, otherwise `wp_insert_user` with the `event_host` role and
  send the standard new-account email (password set via the branded reset flow
  that already exists in `functions/auth.php`). Store the user IDs in
  `_law_co_owner_ids`. A co-owner added by a host edit after approval is
  processed the same way on save. The host events dashboard queries
  `post_author = me OR _law_co_owner_ids CONTAINS me`; before approval only
  the submitter sees the event, which matches current behaviour anyway.

### 3.5 The submission and edit forms

The single biggest rebuild item. A custom front-end form at
`/account/events/submit/` replacing the form 2 (Event > submit an event) embed:

- Server-rendered PHP form, one section per fieldset (Event details,
  Speakers, Venue, Owners and contacts, Fees, Session agenda, Finish),
  POSTing to `admin-post.php` handlers with nonces. There is no Submitter
  details section (`post_author` is the submitter, §3.1) and no Additional
  information comments field (the thread replaces it).
  Progressive enhancement, no framework: this matches how the rest of the theme
  is built (the calendar filters set the pattern).
- Repeaters (speakers, sessions, co-owners, contacts) are a small vanilla-JS
  row-clone component with server-side validation of every row. This replaces
  GPNF. The speaker repeater includes the lookup-by-email "use existing
  speaker" behaviour (section 3.2).
- Validation errors re-render the form with the user's input intact; per-field
  messages. Files (speaker photos) upload via the standard WP media handling
  with type/size checks.
- Post-submit behaviour matches today's confirmation: redirect to page 372
  (Event submitted, `/account/events/submit/done/`). The form requires a
  logged-in user server-side (form 2 has `requireLogin` today), independent of
  the Members page gate.
- **Draft saving**: submissions this long need it. Save creates the
  `law_event` post in a `law-draft` status visible only to its owner, so
  "save and continue later" is a status, not a separate mechanism.
- **Host editing** reuses the same form with a field whitelist by state:
  everything editable while `law-draft`/`law-sent-back`; after `publish`,
  only description, speakers, venue, agenda (the 4.2 §4.2 list); fee and slot
  fields locked after approval. Edits by a host on a published event fire the
  "event updated" committee email (replacing the Entry Revisions hook), and a
  wp post revision is recorded for audit.
- For reference, **today's** GravityView 386 edit whitelist (dumped
  4 September 2026) is status-blind and looser: hosts can edit field 17
  (Event title), 63 (Event type), 77 (Preferred date & time slots), 23
  (Description), 60/61/62 (Sector and qualifiers), 103/21/55 (venue fields),
  54 (Tickets available), 48 (Speakers (list), the **legacy** list; the
  nested field 112 Speakers is not editable at all today) and 99 (Comments),
  at any status. The rebuild's per-state lock list is a **deliberate
  tightening** to the 4.2 §4.2 rules (title, slots and fees lock after
  approval), and giving hosts a real speaker editor is a straight
  improvement over the legacy-list-only editing they have now.
- The old GravityView entry locking is replaced with `wp_set_post_lock` /
  heartbeat, which is native.

**Host UX is a required improvement, not just parity** (Denis, September
2026: the current edit and comments experience is bad). The two worst surfaces
today are GravityView's edit form (a raw field list with no structure) and the
Gravity Flow inbox detail that "Comments" links to (a dense workflow screen
with the thread buried in it, plus GPNF modal iframe workarounds). The
replacements:

- **Editing** happens in the branded account-pages design (the auth-hero
  pattern from the September account rework): the form is sectioned with a
  sticky in-page section nav, locked fields are shown greyed with a short
  "locked after approval" note instead of silently missing, validation is
  inline per field, and saving returns the host to their event card with a
  confirmation, not to a generic entry screen.
- **Comments** stop being a workflow page. Each event on `/account/events/`
  gets its own thread view: chronological message bubbles with author name,
  a host/committee role badge and timestamp, the event's status shown in
  context, and an inline reply box (no modal, no iframe). Replying from a
  Sent back state is what resubmits the event to the committee, stated
  plainly next to the button. The same thread component renders in the
  committee detail view and the wp-admin event screen, so everyone sees the
  identical conversation.

Registration (form 1, User registration) and profile (form 3, User profile)
forms are smaller versions of the same pattern and land in phase D; the events
flow does not wait for them. Form 7 (Contact) stays on Gravity Forms and is
not rebuilt.

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
  **activity log entry**. This replaces the Gravity Flow timeline and gives
  the committee a visible history.
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

**The activity log** (requirement from Denis, September 2026: log the payment
process and every status change like WooCommerce order notes, with as much
information as possible). Storage: WP comments of type `law_event_log` on the
event, each carrying a human-readable line plus structured comment meta (a
JSON context blob) so nothing is lost to prose. Logged events:

- every status transition: actor (user ID and display name), old and new
  status, the action taken, and the **source** (committee UI, host resubmit,
  Stripe webhook, migration, admin edit);
- every payment-path step: invoice created (Stripe invoice ID, amount,
  currency, VAT applied), invoice sent, `invoice.paid` received (Stripe event
  ID, amount paid, payment timestamp, payment method summary where the event
  carries it), payment failed/voided, refund recorded;
- payment status changes (`_law_payment_status` old → new, with source);
- fee changes: override ticked/unticked, override amount changes, the
  snapshot taken at approval;
- Stripe API failures and retries (error message, who pressed retry);
- every notification email sent (template, recipient, subject);
- committee assignee changes and host edits to a published event;
- **manual notes**: committee and admins can add a free-text note from the
  admin event screen and the committee detail view, like a private
  WooCommerce order note.

The log renders newest-first as a notes panel on the wp-admin event screen
(`admin/event-screen.php`) and in the committee detail view, with system
entries and manual notes visually distinguished, exactly the WooCommerce
order-notes pattern. Log entries are append-only: no edit or delete from the
UI. The same mechanism is reused for 4.2 bookings, where charge-on-approval
and waitlist promotion make this history even more important.

**Committee UI**: the committee dashboard at `/account/dashboard/` is rebuilt
as a theme template (same pattern as the host dashboard rework already on this
branch): a filterable list of all events with status badges, and a detail view
with the facts, the comment thread, fee override controls and the
Approve / Send back / Reject actions. `/inbox/` and Gravity Flow's inbox
shortcode retire. This also pre-builds the muscle for 4.2 §5.2's flagship
application review screens.

**Committee functional parity** (Denis, September 2026: improve the design,
leave the functionality). Every capability the committee has today, and where
it lives in the rebuild:

| Today | In the rebuild |
|---|---|
| Gravity Flow inbox (`/inbox/`): pending list, entry detail, set field 98 (Action on Proceed), Proceed / Reject | Committee dashboard: "Needs review" filter; detail view with explicit Approve / Send back / Reject buttons (field 98's two-step indirection goes away, the outcomes are identical) |
| Send back with a comment; the clarification loop; `{latest_comment}` emails | Send back requires a comment on the thread; same emails, same loop |
| Reject with field 67 (Reason for rejection); rejection email | Reject requires a reason; same email |
| Fee override (fields 87 Override fee + 81 Discounted fee) at review | Fee override controls on the detail view, snapshot at approval unchanged |
| GravityView 419 inline edit: field 68 (Confirmed slot), field 90 (Committee assignee), status and other fields | A committee-only controls panel on the detail view: confirmed slot picker, assignee (still triggers the assignee email), category, organisations; full field editing on the wp-admin event screen |
| Committee programme (`/committee/programme/`): all statuses, badges, admin edit links | Unchanged template, CPT data source, edit links point at the event admin screen |
| wp-admin entry detail, notes, filter by tier | wp-admin event screen (all meta boxes, thread, activity log); list-table filters by status, tier, year (`admin/columns.php`) |
| Gravity Flow timeline on the entry | The activity log, which is a superset (payments, emails, edits, manual notes) |
| Entry Revisions history of host edits | Post revisions plus one-line activity log entries per edit |
| "Proceed" / "Send back" vocabulary | Carried over verbatim |

Nothing the committee can do today is dropped; the only deliberate change is
removing the set-field-98-then-click-Proceed indirection in favour of buttons
that say what they do.

### 3.7 Stripe, direct (replacing Make)

`stripe/` talks to the Stripe API server-side (official `stripe-php` via
Composer, committed vendor dir or bundled, consistent with how the project
handles dependencies; no runtime dependency on any middleware).

**On approval with fee > 0** (replacing scenario A):

1. Upsert the customer: search by `_law_stripe_customer_id`, else by invoice
   email; create or update with `name` (invoice contact), `business_name`
   (host organisation), email, address (ISO country), and the VAT number as a
   customer tax ID when present (attach failure logged, never fatal, matching
   the current scenario's Resume behaviour). This fixes the live empty-name
   defect (section 2.3.1).
2. Create the invoice, matching the verified Make module exactly: one line
   item for the snapshot `_law_fee_pence`, description "Event fee for <event
   title>", the fixed Stripe tax rate (`txr_1TkIOyPhJqxRqE2KyejShg1c`, held
   in settings) applied when `_law_vat` is 1,
   `collection_method = send_invoice`, `days_until_due = 5`,
   `auto_advance = false`, the custom field `Attention = <invoice contact
   name>`, the branded rendering template
   (`inrtem_1SSbTmPhJqxRqE2K5Ppdv1Lq`, held in settings), and metadata
   `law_reference` and `law_event_id` (plus `gf_entry_id` for continuity on
   migrated events).
3. Finalise and send; store `_law_stripe_invoice_id` and the hosted URL; email
   the host the payment-due notification with the link.
4. **On failure**: the event stays `law-approved` with `_law_stripe_error`
   set (message and timestamp), the site admin and committee get an alert
   email, and the committee detail view shows a "Retry invoice" button that
   clears the flag on success. No silent completion (fixing defect 4).

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
REST keys all retire. **Xero**: out of scope (Denis, 4 September 2026); the
existing Stripe-to-Xero integration is outside WordPress and untouched by the
rebuild.

**Local webhook testing** uses the Stripe CLI, no tunnel required:
`stripe listen --forward-to http://law.localhost/wp-json/law/v1/stripe-webhook`
polls Stripe outbound and replays test-mode events against the local endpoint,
printing a `whsec_…` signing secret to set as `LAW_STRIPE_WEBHOOK_SECRET` in
the local wp-config. End-to-end: approve a test event locally (a real
test-mode invoice is created), open its hosted invoice URL, pay with card
4242 4242 4242 4242, and the forwarded `invoice.paid` walks the entire
publish path. `stripe trigger invoice.paid` covers quick unit-level checks,
and automated tests can POST constructed events signed with the known local
secret.

### 3.8 Notifications

All current events-module emails re-implemented in `notifications.php` as
filterable PHP templates, delivered through the existing site-wide Email
Templates wrapper (see "The HTML wrapper" below):

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

**Admin-side email management: LAW → Emails.** Gravity Forms currently gives
admins a notifications screen (edit subject and body with merge tags, toggle
active); the rebuild replaces it with a custom equivalent, the same pattern as
WooCommerce's Settings → Emails. Scope (Denis, September 2026): this screen
manages **events-module emails only**; form 7 (Contact) stays on Gravity
Forms and its notifications are still edited in the GF notifications admin.
The screen provides:

- a **list screen**: one row per notification (name, trigger, recipients,
  active toggle, a "customised" marker when the default has been overridden).
  A trigger can carry **multiple notifications**, each independently
  toggleable, mirroring how GF works today (e.g. the submit trigger currently
  has user, committee and Square Eye notifications, the last one inactive), so
  every existing notification, active or not, has a one-to-one home;
- an **edit screen** per email: subject and body (subject a text input, body
  via `wp_editor`), a recipients field for the committee/admin-facing ones
  (host-facing recipients stay dynamic), an active toggle, and a sidebar
  listing the placeholder tags available to that specific email
  (`{event_title}`, `{law_reference}`, `{host_name}`, `{status}`,
  `{invoice_url}`, `{fee}`, `{latest_comment}`, `{rejection_reason}`,
  `{edit_link}` and so on, replacing GF merge tags);
- **Send test**: emails the current admin the rendered template using a real
  event's data (or sample data when none exists), so wording changes can be
  checked without walking the workflow; locally this lands in Mailpit;
- **Reset to default** per email;
- **Test mode** (top of the list screen): an "Enable test mode" checkbox plus
  a "Deliver all to email below" address, saved together. The address is
  validated as you type over admin-ajax (`law_events_check_email`): syntax
  first, then a DNS lookup on the domain (MX, falling back to A/AAAA). DNS can
  only prove the domain accepts mail, so the field reports "deliverable
  domain", not "real mailbox" — nothing short of sending can prove the mailbox
  exists. Ticking the box without a valid address is refused rather than saved,
  so the setting can never look on while doing nothing. While it is on,
  `law_events_test_mode_redirect()` on the `wp_mail` filter (priority 99, i.e.
  just before the Email Templates wrapper at 100) sends **every** email the
  site produces to that one address: subject prefixed `[TEST MODE]`, the
  intended recipients named in a note at the top of the body and in an
  `X-LAW-Test-Original-To` header, and any Cc/Bcc stripped so nothing leaks to
  the people the redirect is meant to protect. `law_events_is_test_mode()` is
  the single check; `law_events_send()` also substitutes the test address when
  an audience resolves empty (an unconfigured committee list, say), because in
  test mode a missing recipient should still show you the email. The activity
  log records the redirect on every send.

Storage: the defaults are the PHP templates in `notifications.php`
(version-controlled, always present); admin overrides are stored per email in
a single option and take precedence, so a broken edit is always one reset away
from a known-good state. Each send is written to the event's activity log
(template, recipient, subject). Delivery is untouched: `wp_mail`, which live
routes through the installed Postmark plugin, local through Mailpit, with the
`block-emails.php` environment guard still applying.

**The HTML wrapper.** Gravity Forms itself has no email template: an HTML
notification is just the message content with merge tags replaced, passed to
`wp_mail` as `text/html` (`gravityforms/common.php`, `send_email()`). The
branded look of today's emails comes from the **Email Templates plugin
(active)**, which filters every `wp_mail` at priority 100 and injects the
message into a Customiser-designed wrapper. The module therefore sends clean
body content only and rides that same site-wide wrapper, like every other
email the site sends, so the new emails look identical to today's (confirmed
by Denis, September 2026); wrapping our own markup as well would double-wrap. If
that plugin is ever retired, `notifications.php` gains a module-owned wrapper
behind a single toggle, and nothing else changes.

The comment thread itself: form 5 (Comments) child entries become WP comments
(`comment_type = law_event_comment`) on the event, rendered in both the host
event view and the committee detail view, with email notifications to the other
party on reply. Ordinary WP comment moderation stays off for this type.

A separate CPT for the thread was considered (raised by Denis, September
2026) and WP comments are the recommendation, for these reasons: a comment
natively belongs to a post (no parent meta to maintain), carries author, email
and timestamp columns out of the box, threads for free, and stays out of the
posts table so event/speaker admin lists never mix with chat messages. The
admin manageability the CPT would have bought is provided anyway: the thread
renders as a panel inside the event's custom admin screen (section on
`admin/event-screen.php`), where committee/admins can read, reply and delete,
and the audit log (`law_event_log`) sits beside it. A CPT would mean one post
per chat message, which is the wrong grain for the posts table. If this
recommendation turns out wrong in practice, swapping storage early in phase B
is cheap because everything goes through `comments.php`.

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

One "Events settings" screen, a submenu of the existing LAW admin menu like
every other screen in this module: programme year and week dates, the slot
choices (currently hardcoded in two places), committee recipient emails, fee
tier amounts, the Stripe tax rate and invoice rendering template IDs, and the
toggle for which host-edit fields publish immediately vs route for review (the
4.2 §4.2 "adjustable without a code change" requirement, present from day one
even if 4.1 publishes everything immediately).

Stripe **keys** are not settings: they live in wp-config.php as constants
(`LAW_STRIPE_PUBLISHABLE_KEY`, `LAW_STRIPE_SECRET_KEY`,
`LAW_STRIPE_WEBHOOK_SECRET`), outside the repo and the database. The test-mode
keys are already defined locally (4 September 2026); live values are set per
environment at cutover. The settings screen shows which mode is active,
read-only.

### 3.11 Automated tests, hardening and review gates

Requested by Denis, September 2026. These are build requirements, not
nice-to-haves, and phase B does not ship without them.

**Unit tests (PHPUnit) for the money path**, written alongside each
sub-module, covering at minimum:

- `fees.php`: every tier, the override on/off matrix, the VAT flag from
  fee > 0, pence conversion, zero-fee routing;
- `workflow.php`: transition guards (who may do what, from which status),
  illegal transitions rejected, side effects fired exactly once (Stripe and
  mail calls mocked), co-owner creation on approve;
- `stripe/webhook.php`: signature verification (valid, invalid, missing),
  idempotency (a replayed Stripe event ID is a no-op), unknown event types
  tolerated, entry resolution via both `law_event_id` and the migrated
  `gf_entry_id` map;
- the reference generator (format `LAW<yy>-<5 digits>`, sequence
  continuation) and the migration's merge-tag translation table.

**E2E smoke (Playwright)**: submit → committee approves → pay the test-mode
invoice with 4242 4242 4242 4242 (Stripe CLI forwarding) → event published
and emails in Mailpit; plus the send-back/comment/resubmit loop. Run before
each phase lands and before cutover.

**Spam and abuse protection** on every public-facing write surface (the
submission form, registration, comment replies): honeypot field, nonces,
per-IP and per-user rate limiting, and strict upload validation on speaker
photos (type, size, dimensions). Gravity Forms provided its own hardening;
the custom forms must not ship with less.

**Security review gate**: at the end of phase B, before phase C rehearsals, a
structured security review of the new attack surface: the Stripe webhook
route, the AJAX migration endpoints, capability checks on every admin-post
and AJAX handler, upload handling, and the committee/host permission
boundaries. Findings fixed before cutover.

**Explicit non-goal (Denis, September 2026): overdue invoice chasing.** No
reminder or escalation mechanism for unpaid invoices in 4.1; an Approved
event simply stays unpaid until paid, as today. Revisit in 4.2 if wanted.

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
  by the migrator. GF core stays installed (form 7, Contact, remains live);
  the module's add-ons are deactivated after burn-in and the tables remain as
  the audit archive.
- **Idempotent and resumable.** Every created object stores its source
  (`_law_gf_entry_id` on events, speakers, sessions; `_law_gf_child_entry_id`
  where relevant). Re-running skips already-migrated items and reports them, so
  a failed batch is re-run safely and the whole migration can be rehearsed
  locally, then on staging, then on live.
- **Dry-run first.** The default mode validates and reports what *would* happen,
  creating nothing, exactly like the existing speaker migrator.
- **Checked and empty, nothing to migrate:** GF save-and-continue drafts
  (feature disabled on form 2, zero rows) and manual admin entry notes (zero
  exist; all notes are Gravity Flow or notification records, covered by
  step 6).
- **Everything is reported.** Per-item outcome (created / skipped / error with
  reason), a persistent log, and a downloadable CSV report.

### 5.2 The Migration admin screen

**LAW → Migration**, a submenu of the existing LAW admin menu (the ACF options
page, slug `law-settings`), admin-only capability (per Denis, September 2026:
under the registered LAW menu, not a new top-level item). Because Admin Menu
Editor Pro rewrites the LAW parent file, it registers with the same
options.php-child pattern as `law_register_migrate_speakers_page()` in
`functions/migrate-speakers.php`, which is proven against AME. The same
placement applies to every new admin screen in this module (settings, emails):
submenus of LAW, no new top-level menus. The screen:

- **Step 0, database snapshot** (Denis, September 2026), handled inside
  wp-admin like everything else: a "Create snapshot" card that dumps the full
  database before any real run. Implementation: `mysqldump` via `shell_exec`
  when available (detected, using the wp-config credentials), falling back to
  a batched pure-PHP export (table by table over the same AJAX runner, so no
  timeout) written as gzipped SQL to a protected directory
  (`wp-content/uploads/law-migration/`, guarded by an `.htaccess` deny, an
  `index.php`, and a random filename suffix). The card shows file size, table
  count, checksum and a download link. **The real (non-dry) Migrate buttons
  are disabled unless a snapshot from the last 60 minutes exists**, with an
  explicit "I have a server-level backup" override checkbox for environments
  where the hosting snapshot is preferred.
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
- **Automated preflight checks**, run before the Migrate buttons enable.
  Everything in this plan was verified against the *local* database; the
  preflight re-verifies the same assumptions on whatever environment the
  migrator is actually running against, so live drift is caught by the tool,
  not by an incident: form structure (the expected field IDs and types exist
  on forms 2, 4, 5, 6, 8, 9), source counts, the orphaned-children scan, a
  slot-parse test over every field 68 (Confirmed slot) value, photo file
  existence for form 8 field 6 (Photo) uploads, the `wp_gpui_sequence` value,
  target CPTs registered, the `law_events_source` flag still on GF, and
  Stripe key constants present. Failures list the exact items; warnings
  (e.g. missing photo files) do not block but are carried into the report.

### 5.3 Migration steps, in dependency order

Mechanics common to the child-entry steps: nested children (contacts,
comments, co-owners, speakers, sessions) live in the same `wp_gf_entry` table
as their parents, and each child records its parent form 2 entry ID in its
`gpnf_entry_parent` entry meta. That meta key is how every step below resolves
"which event does this row belong to". (Most children have lower entry IDs
than their parent; speakers migrated from List field 48 are the exception.
The migrator never relies on ID ordering, only on `gpnf_entry_parent`.)

1. **Users for co-owners.** Walk the form 6 (Event > co-owner) children (54
   rows) whose parent event is **Approved or Confirmed** (per the on-approval
   decision, section 3.4): match by email to existing users, create the rest
   as `event_host`. Report matched vs created vs deferred. Co-owners of
   Proposed/Sent back events migrate as stored rows only; their accounts are
   created by the approve transition after cutover. No welcome emails are sent
   during migration.
2. **Speakers.** Form 8 (Event > speaker) children (186 rows) plus any
   remaining form 2 field 48 (Speakers (list)) rows on unmigrated entries:
   dedupe by email then name (the same rules as today's render-time dedupe),
   create `law_speaker` posts (identity only), sideload each child's photo to
   the media library and record it per child entry. Every source entry ID maps
   to its post (many-to-one) for URL redirects. Step 3 then writes each event's
   appearance rows (that child's organisation, job title and photo), step 4
   copies them onto session rows, and step 4b refreshes them from the source
   entries idempotently.
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
6. **Workflow history.** The GF entry notes table holds the full historical
   timeline for the active form 2 (Event > submit an event) entries: 424
   Gravity Flow notes (approvals, status steps, releases) and 560
   notification-send notes (zero manual notes exist). All of them migrate
   into each event's activity log as `law_event_log` entries with their
   original timestamps, authors and types, so the committee keeps the full
   who-did-what history of every live event. GravityView **entry revisions**
   (1,405 meta rows of host-edit snapshots) migrate as one-line log entries
   (editor, date) only; the full field-level snapshots stay in the retained
   GF archive tables rather than being converted, since the diff format is
   GravityView-specific and the archive keeps them inspectable. Finally, one
   log entry per event records the migration itself and any derived statuses.
7. **Counters and settings seed.** The LAW reference generator is seeded
   from GP Unique ID's `wp_gpui_sequence` row (form 2, field 70, currently
   207) so numbering continues without collision; the settings screen is
   seeded with the slot choices from field 68 (Confirmed slot) and the
   committee recipient list lifted from the current notification configs (the
   programme week dates ship as settings defaults with the correct 2026
   values).
8. **Redirects.** Persist the entry-ID → post map (an option or small table)
   powering 301s for `?event=<entry ID>` and `/speakers/<entry ID>/`.
9. **Notifications.** Migrate every form 2 (Event > submit an event)
   notification, **active and inactive**, plus the inline notifications stored
   in Gravity Flow step settings (step 5 Committee review's rejection email,
   step 8 Clarification needed's assignee and completion emails, step 14 Email
   to committee > payment received), into the LAW → Emails store: name,
   trigger mapping, recipients, subject, body and the **original
   active/inactive state**, each landing as its own toggleable row. Bodies
   pass through a GF-merge-tag → placeholder translation table (`{Email:7}` →
   `{host_email}`, `{Event title:17}` → `{event_title}`, `{latest_comment}` →
   `{latest_comment}` and so on); any tag with no mapping is left in place,
   flagged in the migration report, and the affected email is listed for
   manual review on the Emails screen rather than silently mangled. Form 1
   (User registration) and form 3 (User profile) notifications migrate the
   same way in phase D when those forms are rebuilt.

### 5.4 Cutover sequence

1. Code deployed dark: CPTs registered, migration screen available, templates
   still reading GF.
2. Run the migration on live **immediately after deploying** (per Denis,
   September 2026: deploy, then launch the migration right away; hosts and the
   committee shouldn't notice anything beyond the improvements). Step 0's
   database snapshot runs first, from the same screen, and gates the real
   run. With 75 events the run takes minutes, so no announced freeze is
   needed; the only practical care is not deploying while the committee is
   mid-approval.
3. Review the report and verification panel.
4. Flip the template data source (a single option/constant,
   `law_events_source`), so rollback is flipping it back.
5. Point Stripe's webhook at the new endpoint; disable Make scenarios A and B
   (in that order, so no event is double-handled). In-flight entries sitting at
   "Approved, awaiting payment" keep working because the new webhook resolves
   `gf_entry_id` metadata through the migration map.
6. Burn-in period with the module's GF add-ons still active but unused; then
   deactivate and remove the add-ons only. GF core stays for form 7 (Contact),
   and the entry tables stay as the archive.

### 5.5 In-flight entries

Entries mid-workflow at migration time need explicit handling, all covered by
the status derivation in step 3: Proposed/Sent back events simply appear in the
new committee dashboard for action; Approved-unpaid events keep their live
Stripe invoice (the URL and ID migrate; the webhook map covers payment arriving
after cutover). Nothing needs to be re-invoiced.

### 5.6 Client-visible continuity guarantees

The requirement (Denis, September 2026): once the migration runs, hosts and
committee notice **no loss**, only the improved screens. These are the
invariants the verification panel asserts before the source flip, each
checkable side by side because the flip is a single switch:

1. The public and committee programmes list the **same events** with the same
   titles, days, time slots, venues, host organisations, sectors, types,
   descriptions, Sponsored tags and ticket numbers.
2. Every event listing shows the same **speakers** (names, photos, roles,
   organisations) and the same **sessions** accordion.
3. The speakers archive shows the same deduplicated people, and every old
   `/speakers/<entry ID>/` and `?event=<entry ID>` URL 301s to its new page.
4. Each host's `/account/events/` shows the same events with the same status
   badges and the same actions (Edit, Comments, Pay invoice with the same
   Stripe URL, View listing), plus a payment status line that is now actually
   populated (it was blank before, defect 1).
5. Every comment thread is intact: same messages, same authors, same order,
   same timestamps.
6. The committee sees every event in every status, with the full historical
   timeline (migrated from GF entry notes) attached to each.
7. In-flight work is uninterrupted: an unpaid invoice can still be paid, a
   Sent back event still shows the committee's question, a Proposed event
   still awaits review.
8. Emails keep the same wording (migrated notifications), the same wrapper,
   the same sender.

Where behaviour intentionally differs, it is only ever additive: payment
status now populated, co-owners now get accounts (on approval), the activity
log now exists, and the edit/comments screens look better.

### 5.7 Known data quirks (scanned 4 September 2026)

Facts about the live data the migrator must tolerate, found by scanning all
active entries against the migration assumptions. Each has defined handling:

- **Speaker emails are mostly absent**: 13 of 186 form 8 (Event > speaker)
  entries have a valid field 8 (Email). Dedupe proceeds by email where
  present, normalised name otherwise (the current rules), and the report
  lists every name-based merge for eyeballing.
- **Speaker photo files**: 6 speakers have a field 6 (Photo) value, and on
  this local copy all 6 files are missing from disk (uploads not synced).
  The migrator sideloads what exists and logs what doesn't as a warning, so
  the local rehearsal will show 6 warnings and the live run should show none;
  if live also lacks a file, the speaker migrates without a photo, exactly
  like today's rendering fallback.
- **3 orphaned child entries** (active children whose `gpnf_entry_parent` is
  missing or trashed): skipped and reported, never guessed at.
- **Entry 1144 is Confirmed with an empty field 68 (Confirmed slot)**: the
  calendar already has an unscheduled bucket
  (`law_calendar_unscheduled_events()`), so the mapper keeps `_law_start` /
  `_law_end` empty, the event stays off the day grid as today, and the report
  flags it for the committee to slot.
- **Entries 303 and 774 still carry field 48 (Speakers (list)) rows with no
  nested speakers**: exactly the case migration step 2 exists for; their list
  rows become `law_speaker` posts (without emails or photos, as the list has
  neither).
- **Two dash conventions in slot values**: field 68 (Confirmed slot) choices
  use an en dash in the time range ("08:30–10:00"), field 77 (Preferred
  date & time slots) uses a hyphen ("08:30-10:00"). The slot parser accepts
  both.
- **Field 90 (Committee assignee)** holds committee members' EMAIL ADDRESSES
  on 73 of the 75 active entries (the field's choice list shows "Marie", but
  the stored values are emails). The migrator resolves email first, then
  numeric ID, login and display name, and stores the user ID in
  `_law_assignee`; unmatched values are warned in the report.
- **Inline Gravity Flow notification texts confirmed extractable** from feed
  meta for migration step 9: step 5 (Committee review) carries
  `rejection_notification_*` (enabled) and assignee message keys, step 8
  (Clarification needed) the assignee and completion notification keys, and
  step 14 (Email to committee > payment received) the
  `workflow_notification_*` keys.

---

## 6. Defects resolved by design

| EVENTS.md defect | How the rebuild closes it |
|---|---|
| 1. Payment status never recorded | `_law_payment_status` defaults to `unpaid`, written by the webhook and the free path; migration derives historical values |
| 2. Co-owners not created as users | Section 3.4, plus migration step 1 |
| 3. Create/Publish event steps are no-ops | Whole post-creation story replaced by the CPT itself |
| 4. Make outage silently completes workflow | Direct Stripe with error state, alert and retry (section 3.7) |
| 5. Step 30 fee condition saved but off | Decided (Denis, September 2026): the committee approved-email fires on **every** approval for now; the orphaned fee condition is not carried over, and narrowing to paid-only later is a one-line change |
| `?ec=` category prepopulation dead (EVENTS_4.1_FUNC.md §6) | The custom form reads `?ec=` natively into the category field |
| Fee-waived events get non-sponsor wording | Confirmed-email split on fee = 0, not tier |
| VAT flag matches price literals | `_law_vat` computed from fee > 0 |
| Stripe customers created with an empty name (Make maps deleted field 8, section 2.3.1) | Customer name from field 75 (Invoice contact name), business name from field 105 (Host organisation(s)) |

---

## 7. Build phases

Sized S/M/L relative to each other, not calendar estimates.

**Phase A: foundations (M).** CPTs, statuses, taxonomies, meta schema,
capabilities, settings page, fees, and the custom admin screens
(`admin/fields.php` plus the per-CPT meta boxes). Nothing user-facing changes.

**Phase B: the core flow (L).** Submission/edit form with repeaters and drafts;
workflow engine and audit log; committee dashboard and actions; comments
thread; co-owner user creation; notifications; Stripe service and webhook.
This is the bulk of the work and lands as one reviewable unit per sub-module.
**Phase B exits through the section 3.11 gates**: money-path unit tests green,
spam/abuse protection in place on every public write surface, the E2E smoke
passing, and the security review done with findings fixed.

**Phase B2: re-pointing (M).** Calendar, speakers, host dashboard, single event
permalinks and redirects switched to the CPT source behind the
`law_events_source` flag.

**Phase C: migration (M).** The Migration screen, runner, report, verification;
rehearsals local and staging; live cutover per section 5.4.

**Phase D: decommission (S).** Replace forms 1 (User registration) and 3
(User profile) with custom equivalents, carrying over everything the form 1
stack **actually does** today: user creation with username and email from
field 4 (Email), first/last from field 1 (Name), password field 6, the
`event_host` feed default adjusted by `functions/users.php` from field 11
(Role, limited to attendee/sponsor/event_host); the HubSpot contact type
value (`functions/hubspot.php`); the accessibility/dietary user meta sync
(`law-user-profile-update.php`); auto-login after registration (GW Auto
Login) with the redirect to `/account/?action=registered`; and the active
"Email to admins > user registration" notification. The sponsor-organisation
APC feed and organisation user meta are **not** carried over: they are dead
configuration (section 2.4), and resurrecting organisation selection at
registration is a separate small LAW decision. **Form 7 (Contact) stays on Gravity Forms**, so GF core
remains installed; retire the module add-on stack (the section 2.4 list),
GravityView views, Make scenarios, orphaned pages (`/inbox/`), deactivate the
migrated forms, tidy the Account navigation (role-gate or remove the
committee-only links), and update EVENTS.md to describe the new system.

4.2 then builds on phases A–B primitives (bookings, waitlist, basket, attendee
areas) rather than starting a second architecture.

---

## 8. Decisions taken (challenge these now, not in phase B)

1. **Theme module, not plugin**: the theme is the versioned artefact on this
   project; liftable later.
2. **Custom post statuses + `publish` = Confirmed**: native queries and admin
   filtering beat status-in-meta.
3. **Speakers deduped at save, shared across events**: matches 4.2 §3.5;
   migration performs the first dedupe pass. The shared post is identity only;
   organisation, job title and photo are per appearance on the event row
   (8 September 2026).
4. **Server-rendered forms, vanilla JS repeaters**: no front-end framework;
   consistent with the theme's existing patterns.
5. **Stripe hosted invoices stay** for host fees: identical host experience to
   today; only the plumbing changes. (4.2 attendee payments add Checkout and
   SetupIntents on the same client.)
6. **Comments and audit trail as WP comments** with custom types, not a CPT:
   free threading, authorship and timestamps, and no chat messages in the
   posts table; the thread is fully manageable from the event's custom admin
   screen. Rationale in section 3.8; swapping to a CPT stays cheap while
   everything goes through `comments.php`.
7. **Make removed entirely**, including error paths; nothing new is wired
   through it.
8. **Custom admin meta boxes, not ACF**, for the module's CPTs (Denis,
   September 2026): a shared field-renderer library in `admin/fields.php`,
   saving through the same sanitisers as the front-end forms.
9. **Gravity Forms core stays installed** for form 7 (Contact) and its
   GF-managed notifications (Denis, September 2026); only the module's add-on
   stack retires, and everything events-related is built in the custom module,
   never as a new GF form.

## 9. Open questions: none

Everything is settled (all decisions dated September 2026, from Denis):

1. **Co-owner accounts are created on approval**, not at submission
   (section 3.4).
2. **The committee approved-email fires on every approval** for now; the
   paid-only condition is not carried over (defects table, item 5).
3. **Trashed form 2 entries stay in the archive**; clients don't interact with
   the storage layer, and cutover is deploy-then-migrate in one go
   (section 5.4).
4. Make scenarios A and B fully verified (sections 2.3, 2.3.1); Xero and
   Raindrop out of scope.
5. Stripe test mode fully provisioned: keys in wp-config.php, tax rate
   `txr_1Tex2CPhJqxRqE2K2Bn3XBqH` (VAT, GB, 20%) and rendering template
   `inrtem_1TewtwPhJqxRqE2KQg885Tkf` ("LAW: event hosts") verified via the
   API. Local webhook testing via the Stripe CLI (section 3.7).
6. At cutover, Denis sets the **live-mode restricted key** (customers,
   invoices, webhook endpoints) in production wp-config himself; the live
   webhook endpoint and signing secret are created with it then.

---

## 10. Implementation notes (built 4 September 2026)

The module is implemented on this branch and the migration has been executed
on the local copy. Deliberate deviations from the plan text above, each with
its reason:

1. **No stripe-php SDK.** `stripe/client.php` is a thin wrapper over
   `wp_remote_request` with form-encoded bodies, a pinned
   `Stripe-Version: 2025-09-30.clover` (matching the retired Make scenarios)
   and manual HMAC v1 webhook signature verification. Rationale: no vendor
   tree in the theme repo, three API endpoints in total, and the signature
   scheme is small and well specified. §3.7's "official stripe-php via
   Composer" is superseded.
2. **Plain prefixed functions, not a PHP namespace.** The module uses
   `law_events_*` / `law_event_*` prefixes like every other file in this
   theme; `LAW\Events` namespacing (§3) was dropped for consistency with the
   existing codebase.
3. **File layout deltas** against the §3 tree: `log.php` (the activity log),
   `source.php` (the CPT data source and legacy URL redirects) and
   `speakers.php` (dedupe/upsert and relationship helpers) are their own
   files; the front-end templates are `templates/account-event-form.php`,
   `templates/account-dashboard.php`, `templates/event-single.php` and
   `parts/events/{thread,people-repeater}.php`.
4. **Tests are integration-style PHPUnit** (theme dev dependency, `vendor/`
   gitignored), bootstrapping the local WordPress with Stripe fully mocked
   through a `law_stripe_request_mock` filter and mail blocked. The suite
   covers the §3.11 list plus the review-round additions (retry/resume,
   amount reconciliation, refund-by-charge). Run: `php -d memory_limit=512M
   vendor/bin/phpunit` from the theme directory.
5. **One additive behaviour change found by the parity diff**: a speaker who
   appears only in a *session* of a Confirmed event now shows in the speakers
   archive. The old archive ignored session participation even though the
   event page displayed it; the new rule is consistent (verified example:
   form 8 entry 966, whose own parent is Proposed, speaking in a session of
   a Confirmed event).
6. **Legacy `?event=` resolution is map-first** on public pages: GF entry IDs
   and post IDs overlap numerically, so public legacy URLs consult the
   migration map before treating the value as a post ID. Internal committee
   links use post IDs and resolve post-first.
7. **Template assignment at cutover** is part of
   `functions/setup-account-pages.php` (`/wp-admin/?setup-account-pages`):
   when the source is CPT it assigns `templates/account-event-form.php` to
   /account/events/submit/ and `templates/account-dashboard.php` to
   /account/dashboard/.
8. **Local migration results**: snapshot via mysqldump (61 tables), preflight
   PASS (two expected warnings: 3 orphaned children, 6 photo files missing
   from the unsynced local uploads), 75/75 events, 186 speaker entries → 181
   posts (5 dedupe merges), 2 sessions (2 skipped correctly: they belonged to
   a GravityView revision snapshot of their parent), 43/43 comments, 75
   histories (1,321 activity log entries), 11 notifications imported with
   original active flags (1 warning: "Email to Square Eye > event updated"
   has no module slot, review on LAW → Emails), reference counter seeded at
   207, slots and committee recipients seeded, source flipped to CPT.

### 10.1 Verification round 1 outcomes (4 September 2026)

An independent plan-vs-implementation audit ran after the build; every finding
was fixed or dispositioned:

- **Assignees**: field 90 (Committee assignee) stores emails, not names; the
  migrator resolves email-first and all 73 assigned events were backfilled
  (zero unmatched).
- **Stripe config guards**: an approval with VAT due now refuses loudly when
  no tax rate ID is configured (never a silent net-only invoice); a missing
  rendering template warns. Both IDs are preflight checks and are seeded
  locally with the verified test-mode values.
- **The real-Stripe E2E leg cannot run on this machine**: the local
  environment's `mu-plugins/block-outbound.php` deliberately blocks
  api.stripe.com, and Denis explicitly sanctioned manual state switching in
  its place locally. The CLI-forwarded `invoice.paid` smoke (§3.7) is a
  staging/cutover gate, not a local one. The webhook logic is covered by
  signed synthetic events in the suite.
- **Front-end edit locking** now uses `wp_check_post_lock`/`wp_set_post_lock`:
  a second editor sees a banner and their save is refused while the lock
  holds.
- **The post-approval lock list** matches 4.2 §4.2 exactly: tickets are
  EDITABLE (allocations within the approved band); sectors, host
  organisations, venue capacity and venue-needed lock alongside title, type,
  slots and fees.
- The committee detail view gained the manual private note, event category
  and linked-organisations controls; `?ec=` prepopulates the category on
  first submission; step 8 (Clarification needed)'s completion notification
  and "Email to Square Eye > event updated" now migrate (13 imports);
  refunds send a dedicated committee email; trashed entries and the Stripe
  config are reported by preflight; the verification panel gained contact
  counts, the orphan count and old-vs-new spot-check links; comment replies
  carry a honeypot; the events list has a programme-year filter; Send test
  previews without saving.
- Migration steps run whole-step per AJAX request rather than §5.2's
  10-parent batches: a deliberate simplification at this dataset size (75
  events), noted here so nobody hunts for the batching.
- Local test residue is intentional: event post 1224 ("E2E Curl Host Event")
  and its speaker exercise the full lifecycle, and migrated entry 1167 ("Best
  Event Evar") was advanced Proposed → Confirmed by the browser tests.

### 10.2 Verification round 2 outcomes (4 September 2026)

A second independent audit traced complete flows rather than the checklist.
Everything found was fixed:

- **The status guard** (`wp_insert_post_data`): an existing event's status now
  moves ONLY through the workflow engine. The classic editor's Publish/Save
  Draft buttons (which know nothing of the custom statuses) previously
  published unapproved events with no fee, invoice or log; they now save
  fields without touching status, the submit box is relabelled "Update" with
  the real status shown, and trash still works. A reentrancy guard stops the
  admin save's workflow transition from re-running the handler (which
  doubled thread replies and notes).
- **Sessions without a start time** no longer vanish: the session query
  dropped its meta INNER JOIN and sorts in PHP (empty times last).
- **Co-owner dashboards**: the serialized-array REGEXP matched array KEYS as
  user IDs (user 1 saw 13 foreign events listed). Co-owner IDs now also write
  one flat `_law_co_owner` row each, the dashboard queries those, and the
  migrated events were re-synced.
- **Front-end repeaters** use a monotonic index counter, so remove-then-add
  can no longer collide indexes and silently drop a row.
- **Migrated email texts**: the tag translation table now covers
  `{Confirmed slot:68}`, `{Venue:21}`, `{all_fields}` (rendered as the new
  `{event_summary}` facts block), `{embed_url}`, `{entry_id}`,
  `{entry_revision_diff}` and the rest found live; all 13 imported overrides
  now carry zero unmapped tags, and the Emails list flags any future
  "review tags" row in red.
- The committee dashboard can now CLEAR categories/organisations (hidden
  sentinel); real migration steps additionally require a passing preflight
  from the last 24 hours; the sector qualifier inputs lock visibly with
  sectors; `?ec=` survives a validation error; invoice retry refuses on
  paid/confirmed events; `law_year` is set once at creation and the
  sponsored multi-event rule scopes to the programme year; the migration
  screen warns that re-running steps 7/9 overwrites admin edits; drafts
  require a title; the fees docblock matches the code.
- Suite grown to cover the new seams: the status guard (direct
  publish/draft reverted, workflow still moves, trash allowed) and the
  co-owner value-vs-key query.

### 10.3 Verification round 3 outcomes (4 September 2026): READY

The final audit hunted regressions in the earlier fixes and re-verified a
sample of prior claims. Three blockers found, all fixed and live-verified:

- **Untrash** no longer strands an event in trash (a regression from the
  round 2 status guard): restores pass the guard, and
  `wp_untrash_post_status` returns the pre-trash status instead of core's
  `draft`. Covered by a new test.
- **The legacy `?event=` redirect fires only on the public programme page**
  (`templates/calendar.php`). It had been intercepting the committee
  dashboard's own `?event=<post ID>` detail links, killing the detail view
  for every Confirmed event and, on entry/post ID collisions, opening the
  wrong event entirely.
- **Migrated email wording**: First+Last merge-tag pairs collapse to one
  `{host_name}`, and `{ID:100}` maps to `{law_reference}`; step 9 re-imported
  and verified clean.
- A co-owner row added in wp-admin on an approved event now creates the
  account, matching the host-edit path.

Everything else in rounds 1-3 audited sound. Suite: 35 tests / 125
assertions. Remaining work is staging/cutover-time by design, not local:
the live restricted Stripe key and webhook endpoint + secret (Denis, §9),
the real-Stripe E2E smoke with CLI forwarding on staging (outbound Stripe is
blocked on this local copy), disabling Make scenarios A then B, the live
migration run itself, and a LAW wording review of the migrated emails on
LAW → Emails.

### 10.4 Phase D part 1 built (4 September 2026)

The custom registration and profile forms are implemented
(`functions/events/registration.php`, `templates/register.php`,
`templates/account-profile.php`, `parts/events/profile-fields.php`),
replacing the form 1 (User registration) and form 3 (User profile) embeds,
the User Registration feeds, GW Auto Login and the mu-plugin checkbox sync:

- **Registration** (anonymous): name, email (= username), organisation, job
  title, country (choices read live from form 1 field 10 while GF core
  remains, text fallback), the three self-service role checkboxes
  (administrator/committee can never be self-assigned; whitelisted
  server-side twice), accessibility/dietary choices writing the exact ACF
  fields (law_role, accessibility, dietary) and meta keys (organisation,
  job_title, country, accessibility_other, dietary_other) the old stack
  wrote, the HubSpot contact type tags (year-aware), password + confirm with
  a 10-character minimum, honeypot, per-IP rate limiting (20/hour), error
  state keyed by a random per-visitor cookie (never bare IP), auto-login and
  the /account/?action=registered redirect, and the migrated
  "Email to admins > user registration" notification (form 1's real text,
  imported with a form-1-specific tag map).
- **Profile** (logged in): the same fields plus email change and optional
  password change — BOTH requiring the current password (the email-change
  path was a hijacked-session takeover lever; the old address is notified),
  role sync limited to the self-service roles with attendee as the floor,
  and typed values surviving validation errors.
- `setup-account-pages` (CPT mode) assigns both templates and strips the
  form 1/form 3 Gravity Forms blocks from the page content, plus page 294
  (Submit an event)'s inert form 2 block (void and wrapped block shapes;
  leaving them would render both forms).
- **The source flip (de)activates the module Gravity Forms** (1, 2, 3, 4, 5,
  6, 8, 9; form 7 Contact untouched): with the embeds gone they were still
  anonymously submittable through GF's REST submissions endpoint, which
  would have run the legacy feeds and workflow. Flipping back to GF
  reactivates them, keeping the rollback intact. Inactive forms still serve
  choice lists via GFAPI (the country select).
- Accepted risks, documented: the duplicate-email message is an existence
  oracle, but with open instant registration the oracle is inherent (a probe
  can simply attempt to register), so neutral-message theatre buys nothing.
- **Phase D part 2 remains post-burn-in**: deactivate/remove the GF add-on
  stack (the forms themselves are already deactivated by the source flip),
  delete the orphaned GravityView views and /inbox/, tidy the Account
  navigation, rewrite EVENTS.md.

A dedicated visual QA pass (screenshots of every module template, desktop
and mobile, with computed-style contrast checks) now gates template work:
it caught the committee dashboard's unstyled badges, the speaker single
missing the card stylesheet on CPT slugs, and profile state loss, all fixed.
Pre-existing and out of scope: the keyless Google Maps embed rendering blank
on localhost (identical code on both data sources; the "Open in Google
Maps" link works), Gravity Forms' own gf_vars console error on non-GF admin
screens, and the role-agnostic post-login redirect.
