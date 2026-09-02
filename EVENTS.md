# Events module

> **Agents: keep this file up to date.**
> After any significant change to the events module (form fields, workflow steps,
> notifications, calculated fields, endpoints, Make scenarios, access rules or the
> programme calendar), update the relevant section here in the same piece of work.
> A stale reference is worse than none, because the next agent will act on it.
> When you change something listed in "Known defects", move it out of that section
> rather than leaving both descriptions in place.

Last verified against the database and codebase: 2 September 2026.

---

## 1. What the module does

It takes a London Arbitration Week event from host submission through committee
approval, Stripe invoicing, payment and publication. The committee only has to act
at the approval step. Everything after that is automatic.

Sponsors and any event the committee waives skip invoicing and payment entirely
and go straight to confirmed.

The public programme calendar is rendered from Gravity Forms entries, not from
WordPress posts. See section 8.

### Host journey

**Paying events:** submit → (committee approves) → "payment due" email with a
Stripe link → pay online → "event confirmed" email.

**Free events (sponsor or waived):** submit → (committee approves) → "event
confirmed" email.

**If the committee asks for clarification:** the host gets an email, logs in,
reads the question in the entry's Comments thread, replies and submits. The entry
returns to the committee. This can repeat indefinitely.

### Committee journey

The committee's only required step is approval, actioned from the Gravity Flow
inbox. They can approve, send back with a comment, or reject, and can override or
waive the fee at the same time.

---

## 2. Platform components

| Component | Role |
|---|---|
| Gravity Forms 3.1.0.2 | Submission form (form 2) and child forms |
| Gravity Flow | The approval workflow on form 2 |
| Gravity Flow Form Connector | "Update an Entry" steps used for status changes |
| Gravity Flow Incoming Webhook | Park-and-release steps that wait on Make |
| Gravity Perks Nested Forms (GPNF) | Repeatable co-owners, contacts and comments |
| Gravity Perks Advanced Calculations (GPAC) | Conditional calculated fields |
| GravityView (+ Advanced Filter, Inline Edit, Entry Revisions) | Host and committee dashboards |
| Members | Per-page role access control |
| Stripe | Invoicing and payment, via the Invoices API |
| Make.com (region eu1) | Two scenarios bridging WordPress and Stripe |

### Core design principle

**All conditional logic and calculation happens in WordPress**, in calculated
fields. Make just passes clean values through to Stripe. Do not move logic back
into Make expressions. This is the reason the build is stable and debuggable.

---

## 3. Forms

| Form | Title | Purpose |
|---|---|---|
| 1 | User registration | Creates the WordPress user, assigns role |
| 2 | Event > submit an event | The main submission form and workflow host |
| 3 | User profile | Self-service profile and role editing |
| 4 | Events > add host contact | GPNF child of field 94 |
| 5 | Comments | GPNF child of field 99, the committee/host thread |
| 6 | Events > add co-owner | GPNF child of field 106 |
| 8 | Events > speaker | GPNF child of field 110 locally, field 112 on live |

Child form entries live in the same `wp_gf_entry` table and draw from the same
global auto-increment. GPNF creates a child entry the moment the row is added in
the repeater modal, so **a child always has a lower entry ID than its parent**.
The parent is recorded in the child's `gpnf_entry_parent` entry meta. Speakers
migrated from List field 48 (see section 13) are the exception: those child IDs
are higher than the parent because they were created afterwards.

### Form 2 key fields

| ID | Type | Label / purpose |
|---|---|---|
| 3 | name | Submitter name |
| 7 | email | Submitter email |
| 17 | text | Event title |
| 21 | text | Venue (free text: name, address, or both). Used on the programme listing and as the Google Maps query. |
| 48 | list | Speakers (legacy). Four columns: Name, Organisation, Job title, URL. Being replaced by the Speakers nested field (110 locally, 112 on live). The programme calendar still reads this field. |
| 53 | product (radio) | Event fee tier: `UK office` £1200, `International` £600, `Sponsor` £0 |
| 68 | select | Confirmed slot |
| 70 | uid | Unique ID (LAW reference) |
| 73 | email | Invoice contact email |
| 74 | address | Billing address (`74.6` is the country name) |
| 75 | name | Invoice contact name |
| 78 | date | Approval date, written by the theme on approval |
| 79 | text | Host VAT number |
| 81 | number | Committee discounted / waived fee, in pounds |
| 83 | website | Stripe invoice URL, written back by Make |
| 84 | number (GPAC) | Calculated fee in **pence** |
| 85 | number (GPAC) | VAT flag, 1 or 0 |
| 87 | checkbox | Override fee, gates whether 81 is applied |
| 88 | text | Country ISO 3166-1 alpha-2, derived from `74.6` |
| 90 | select | Committee assignee |
| 94 | form (GPNF → 4) | Event contacts, repeatable |
| 95 | select | **Event status**: Proposed / Sent back / Approved / Confirmed / Rejected |
| 96 | select | **Payment status**: Unpaid / Paid / Refunded / Free |
| 98 | radio | Action on Proceed: `Approve this event` / `Send it back` |
| 99 | form (GPNF → 5) | Comments thread |
| 106 | form (GPNF → 6) | Additional event owners, repeatable |
| 110 / 112 | form (GPNF → 8) | Speakers, repeatable. Child fields: Name (1), Organisation (3), Job title (4), Website (5), Photo (6), Biography (7), Email (8). Field **110** on local, **112** on live. The migrator auto-detects. |
| 67 | textarea | Reason for rejection |

Fields 84, 85, 87, 81, 88, 95, 96 and others are set to **administrative**
visibility: they appear in the admin entry detail and in GravityView, but not on
the public form.

### Calculated fields (GPAC)

**Field 84, fee in pence.** Reads the committee override when the box is ticked,
otherwise the host's selected tier.

```
if( {Apply fee override:87} ):
	{Discounted fee:81} * 100
else:
	{Event fee:53:price} * 100
endif;
```

A result of 0 drives the entry down the free route. Any positive amount raises an
invoice for exactly that amount.

**Field 85, VAT flag.**

```
if( {Event fee:53:price} == 1200 || {Event fee:53:price} == 600 ):
	1
else:
	0
endif;
```

VAT = 1 for both paid tiers, UK office (£1200) and International (£600), and 0
for Sponsor (£0). LAW confirmed in September 2026 that International hosts **are**
charged UK VAT. Earlier handover documentation said otherwise; that documentation
was out of date, not the build.

The flag follows the host's originally selected tier, so it is unaffected by a
committee discount or waiver. That is intentional.

Note the fragility: the formula matches on the tier **price**, not the tier
value. If the £1200 or £600 prices ever change, both branches fall through to 0
and VAT silently stops being applied to everything. Since VAT now applies to
every paid tier, an equivalent and price-proof version would be:

```
if( {Event fee:53:price} > 0 ):
	1
else:
	0
endif;
```

### Event status values

`Proposed` is the default choice on field 95 at submission, and is rewritten by
the workflow at each stage. It is separate from Payment status, which tracks the
invoice only.

---

## 4. The Gravity Flow workflow (form 2)

Step order is stored in the WordPress option `gravityflow_feed_order_2`, not in
the `feed_order` column (all zeros). Current order:

```
3   Start                                     workflow_start
5   Committee review                          approval
26  Set status to Sent back                   update_entry     [98 = Send it back]
8   Clarification needed                      user_input       [98 = Send it back]
27  Set status to Proposed; revert            update_entry     [98 = Send it back] → 5
7   Create event listing                      post_creation    (no-op, see defects)
17  Create Stripe invoice                     webhook          → Make scenario A
19  Log invoice URL                           incoming_webhook (parks)
22  Set status to Approved                    update_entry
30  Email to committee > event approved       notification
21  Email to user > payment due               notification     [84 > 0]
20  Waiting for payment                       incoming_webhook (parks)
14  Email to committee > payment received     notification
13  Publish event                             post_update      (no-op, see defects)
23  Set status to Confirmed                   update_entry
12  Email to user (sponsor) > confirmed       notification     [53 = Sponsor]
29  Email to user (non-sponsor) > confirmed   notification     [53 ≠ Sponsor]
24  Rejected: update status                   update_entry     → complete
4   Complete                                  workflow_complete
```

### Routing

- **Committee review (5)**: approved → next; rejected → step 24; expired → next.
- **The clarification loop** is steps 26, 8 and 27, all three gated on field 98
  being `Send it back`. An entry approved outright skips all three. Step 27 clears
  field 98 and sets status back to `Proposed`, then routes to step 5 so the next
  committee pass starts clean.
- **Create Stripe invoice (17)** routes all three error destinations
  (`destination_error`, `_error_client`, `_error_server`) to `complete`. See
  "Known defects", item 4.
- **Steps 12 and 29** both route to `complete`, so whichever one's condition
  matches ends the workflow.

### Update an Entry steps

These write fixed values into field 95, so they are immune to calculation timing
issues. Steps 22, 23, 24, 26 write only field 95. Step 27 writes field 98 (blank)
and field 95 (`Proposed`).

**No step writes field 96 (Payment status).** See "Known defects", item 1.

### Notifications

Gravity Flow handles the overlap between form notifications and workflow steps
for you. A notification step calls `intercept_submission()`, which registers
`gform_disable_notification_<form_id>`, and `maybe_disable_notification()`
returns true for any notification the step has selected
(`gravityflow/includes/steps/class-step-notification.php:129`). So a notification
claimed by a step is automatically suppressed at submission, even if it is marked
active. Gravity Flow then sends it regardless of its active state when the step
runs.

Marking workflow notifications inactive is therefore a readability convention on
this build, not a functional requirement.

| Notification ID | Name | Fired by |
|---|---|---|
| `6a00bfcc8973d` | Email to user > event submitted | form submission (active, correct) |
| `6a2678a52b374` | Email to committee > event submitted | form submission (active, correct) |
| `6a264ea5b159e` | Email to user > event approved, pending payment | step 21 |
| `6a571ed65f78c` | Email to committee > event approved | step 30 (marked active, but suppressed at submission by the step) |
| `6a0dcd4e6b7ec` | Email to user (sponsor) > event confirmed | step 12 |
| `6a410c8771f1d` | Email to user (non-sponsor) > event confirmed | step 29 |
| `6a400d916e3fd` | Email to committee > event updated | GravityView entry revision |

Step 14 ("payment received") uses an inline workflow notification rather than a
saved form notification.

The approval step also carries its own inline notifications: the rejection email
is enabled, the approval and revert emails are disabled because they were moved
to later steps.

### The `{latest_comment}` merge tag

Custom, implemented in `functions/gravity-forms.php:345`. It renders the most
recent entry from the field 99 nested Comments form. Used in the clarification
step's assignee notification and in its completion notification back to the
committee. It resolves to an empty string if GPNF is not available.

---

## 5. Endpoints

### Inbound, Make → WordPress

**Workflow hook (park and release)**

```
POST /wp-json/gf/v2/entries/<entry_id>/workflow-hooks
```

Registered by Gravity Flow Incoming Webhook. Requires `workflow-api-key` and
`workflow-api-secret` parameters. The same URL serves both parked steps.

`check_permissions()` loads the entry, asks Gravity Flow for the entry's
**current** step, requires it to be of type `incoming_webhook`, then compares the
supplied credentials against that step's stored key and secret. The credentials
do not select a step. Calling with step 20's credentials while the entry is still
parked at step 19 returns 403 and nothing happens, so call order matters.

Payload keys:

- Step 19 expects `stripe_url`, mapped to field 83. Working.
- Step 20 expects `payment_status`, mapped to field **86**. Broken, see defects.

Credentials live in each step's settings at
`admin.php?page=gf_edit_forms&view=settings&subview=gravityflow&id=2&fid=<step>`.
Treat them as secrets; rotate if a database copy has been shared.

**Gravity Forms REST API v2**

```
/wp-json/gf/v2/…
```

Two key pairs in `wp_gf_rest_api_keys`: `make-read-only` (read) and
`make-read-write` (read_write). Which routes Make calls is not visible from
WordPress. Note that the read-write key can set entry fields directly, bypassing
the step mappings.

**Registered but unused**

```
POST /wp-json/gf/v2/workflow/webhooks/<feed_id>/<key>
```

The feed-based variant of the incoming webhook. No `gravityflowincomingwebhook`
feeds exist, so this route is dead. Do not wire anything to it.

### Outbound, WordPress → Make

```
POST https://hook.eu1.make.com/bc6wha119lsmf1m46a2jv94n0h2i49oa
Content-Type: application/json
```

Step 17. `body_type: all_fields`, so the whole entry is posted. No mappings, no
auth headers. This is the only outbound webhook on the site.

### Outside WordPress

- **Stripe → Make**: the `invoice.paid` webhook configured in the Stripe
  dashboard, triggering scenario B.
- **Make → Stripe**: the Invoices API, for customer upsert, invoice create, line
  item, finalise and send.

Neither is verifiable from the WordPress side.

### No custom routes

Neither the theme nor any mu-plugin registers a REST route, rewrite rule,
`admin_post_` or `wp_ajax_` handler. All API surface comes from plugins.

---

## 6. Make scenarios

Held in Square Eye's Make account, region eu1. They can be migrated to an account
in LAW's name on request. **Their contents are not verifiable from this
repository**; the descriptions below come from the handover documentation and
should be re-checked in Make before relying on them.

**Scenario A, "LAW > event approved > Stripe invoice."** Triggered by step 17.
Upserts the Stripe customer using the ISO code from field 88, then a router
splits three ways:

1. VAT number present and fee > 0: attaches the customer's VAT number.
2. Fee > 0: creates the invoice, adds the line item with conditional VAT, sends
   it with a five-day due date, retrieves the hosted URL and posts it back to
   field 83, releasing step 19.
3. Fee = 0: skips Stripe, releases both step 19 and step 20, and sets payment
   status to `Free` so the event publishes immediately.

**Scenario B, "LAW > invoice paid > update entry."** Triggered by Stripe's
`invoice.paid` webhook. Reads the entry ID from the invoice metadata and calls
step 20's workflow hook.

**Matching key.** Each invoice carries `gf_entry_id` and `law_reference` in its
Stripe metadata, linking payments back to the form entry.

---

## 7. Country handling

Gravity Forms stores the address country as a full name. Stripe expects ISO
3166-1 alpha-2. `wp-content/mu-plugins/law-gf-country-iso.php` converts the name
from `74.6` to a code and writes it to **field 88**, on both submission
(`gform_pre_submission_2`) and entry edit.

Unmatched names are logged via `error_log()` and an empty string is stored,
rather than sending an invalid value to Stripe.

Note: the plugin's header comment says it writes field 90. That comment is stale;
the constant `SQE_LAW_ISO_FIELD` is 88, which is correct. Field 90 is Committee
assignee.

---

## 8. The programme calendar

The public programme is **not** built from WordPress posts. It is rendered by the
theme directly from form 2 entries, in `functions/calendar.php` (around 1000
lines) with `templates/calendar.php` and `templates/calendar-committee.php`.

- `law_calendar_public_statuses()` returns `array( 'Confirmed' )`. The public
  calendar shows Confirmed entries only.
- The committee variant (`law_calendar_is_committee()`, true when the
  `calendar-committee.php` template is active) shows **all** statuses and adds a
  colour-coded status badge per card.
- Programme week dates are hardcoded in `law_calendar_week_days()`, currently
  Monday 30 November to Friday 4 December 2026. `LAW_CALENDAR_YEAR` is 2026.
- Search and filtering are driven by GravityView views resolved by slug:
  `programme` (view 626) for the public calendar, `programme-committee` (view
  627) for the committee one. IDs are cached in the options
  `law_calendar_view_id` and `law_calendar_committee_view_id`.

There is an `event` custom post type registered via Pods, and a deactivated
Advanced Post Creation feed that would populate it, but neither is in use. See
"Known defects", item 3.

Individual listings (`?event=<entry_id>`) show venue from field 21 in the main
column, below the intro, with a Google Maps iframe embed. The embed is a search
on that text (London is appended when the string does not already mention it).
Placeholder values such as `TBC` skip the map. No coordinates are stored; an
address field is not required.

### The speakers archive

`templates/speakers.php` (page template "Speakers") lists every speaker across
the public programme, from the form 8 child entries. `functions/speakers.php`:

- Includes a child entry only when its `gpnf_entry_parent` is a form 2 entry
  whose status (field 95) is Confirmed. This is deliberate and hardcoded:
  speakers from Proposed, Sent back or Rejected events must never appear.
- Deduplicates by email address first (field 8; same email = same person even
  if the name was typed differently), falling back to first + last name (case-
  and whitespace-insensitive). The oldest child entry wins; any field it is
  missing (photo, biography, organisation, job title, URL, email) is filled
  from the next duplicate that has it. Cards sort by surname, then first name.
- Cards show the photo (field 6, first file; initials placeholder when absent),
  job title, name and organisation, and link to `/speakers/<child entry ID>/`.

### The single speaker profile

`/speakers/<child entry ID>/` renders `templates/speaker.php` (no Template Name
header: it is not a wp-admin page template). A rewrite rule maps the URL onto
the Speakers page (`pagename=speakers` + `law_speaker` query var, flushed once
via the `law_speakers_rewrite_version` option) and a `template_include` filter
swaps the template in. Unknown or non-public IDs 404; any entry ID of a merged
person resolves to the same profile, whose canonical URL is the kept entry's.

- Layout: photo (or initials) in a 1/3 column, details in 2/3; role above an
  `<h2>` name, organisation, website link, then the biography.
- Related events come from the profile's `event_ids`, mapped by
  `law_calendar_event_by_id()` and rendered like programme list cards, linking
  to the programme page's `?event=` view (`law_speaker_event_link()`, because
  `law_calendar_url()` would build off the Speakers page).
- SEO: `document_title`/SEOPress title, description and canonical are filtered
  per speaker (name-based title; description from the bio, or a generated
  "<name>, <role> at <org>, is speaking at..." line). Open Graph tags inherit
  these.

The search box filters cards in the browser (name, role, organisation) and
highlights matches: `assets/js/speaker-search.js`, dependency-free,
enqueued only on this template along with `assets/css/speakers.css`.

The hero banner shared by this template, the calendars and other pages lives in
`parts/layout/hero-title.php` (get_template_part args: `title`, `is_event`,
`image`, `classes`, `content`).

---

## 9. Front-end pages and access

Permalink structure is `/%postname%/`. Access is controlled per page by the
Members plugin (`_members_access_role` post meta), not in code.

| URL | Page ID | View / shortcode | Roles |
|---|---|---|---|
| `/login/` | 396 | `[law_login]` | everyone |
| `/register/` | 286 | form 1 | everyone |
| `/account/` | 290 | landing | all logged-in |
| `/account/profile/` | 439 | form 3 | all logged-in |
| `/account/events/` | 292 | GravityView 386 "Events (hosts)" | hosts and above |
| `/account/events/submit/` | 294 | form 2 | hosts and above |
| `/account/events/submit/done/` | 372 | confirmation | hosts and above |
| `/account/dashboard/` | 414 | GravityView 419 "Events (committee - all)" | committee, editor, admin |
| `/inbox/` | 279 | `[gravityflow page="inbox" form="2"]` | committee, host, editor, admin |
| `/programme/` | 622 | `templates/calendar.php` | **committee, editor, admin only** |
| `/committee/programme/` | 624 | `templates/calendar-committee.php` | committee, editor, admin |
| `/speakers/` | 658 (local) | `templates/speakers.php` | everyone |
| `/speakers/<entry ID>/` | rewrite onto 658 | `templates/speaker.php` | everyone |

### GravityView views

| ID | Title | Filter |
|---|---|---|
| 386 | Events (hosts) | field 89 = `{user:ID}` **OR** `created_by` = current user |
| 419 | Events (committee - all) | none, shows every entry, inline edit on |
| 443 | Events (committee - proposed) | field 95 = Proposed. **Embedded nowhere, orphaned** |
| 626 | Programme | field 95 = Confirmed |
| 627 | Programme (committee) | all statuses |

View 386's first filter condition references field 89, which no longer exists on
form 2. In practice only the `created_by` branch matches, so a host sees only the
events they personally submitted.

### Roles

`administrator`, `editor`, `author`, `contributor`, `subscriber`, plus the custom
`sponsor`, `events_committee`, `event_host` and `attendee`.

Role assignment on registration is handled in `functions/users.php` from form 1
field 11, limited to `attendee`, `sponsor` and `event_host`. The profile form
(form 3, field 12) can add or remove the same three; `events_committee` and
`administrator` are never touched by self-service.

### Navigation

The Top menu contains Account → Profile, Events dashboard, My events, Submit an
event, plus Register and Login. Neither `/inbox/` nor either programme page is
linked from any menu. The Inbox menu item exists as post 595 but is still a
draft. Members restricts page *content*, not menu items, so hosts currently see
an "Events dashboard" link that will deny them.

---

## 10. Theme code map

```
functions/
  _init.php                  (empty, loader lives in functions.php)
  banner-account-status.php  account status banner
  calendar.php               the whole programme calendar, ~1000 lines
  editor.php                 block editor tweaks
  enqueue.php                assets
  event-workflow.php         writes field 78 (Approval date) on approval
  gravity-flow.php           relabels Approve → Proceed, Revert → Send back;
                             adds role-* body classes
  gravity-forms.php          {latest_comment} merge tag and other GF filters
  helpers.php
  hubspot.php                HubSpot contact type on registration
  menus.php                  Foundation 6 submenu markup
  shortcodes.php             [user-content role="..."] and others
  speakers.php               speakers archive from form 8 child entries
  users.php                  role assignment, [law_login] shortcode
  wordpress.php
templates/
  account.php  calendar.php  calendar-committee.php  contact.php
  full-width.php  patrons.php  privacy.php  speaker.php  speakers.php
  sponsors.php  _blank.php
parts/layout/
  hero-title.php             shared page hero (title, is_event, image args)
```

### Terminology in the UI

`functions/gravity-flow.php` relabels Gravity Flow's buttons for the committee:
"Approve" becomes "Proceed" and "Revert" becomes "Send back", with matching
confirmation prompts. The actual routing decision is made by field 98, not by
which button is pressed, so the committee sets field 98 first and then clicks
Proceed.

---

## 11. Site mu-plugins

| File | Status |
|---|---|
| `law-gf-country-iso.php` | **In use.** Country name → ISO for field 88 |
| `law-user-profile-update.php` | **In use.** Syncs form 1 and 3 checkboxes to ACF user fields |
| `law-secondary-host-users.php` | **Dead code.** See "Known defects", item 2 |
| `block-emails.php` | Environment guard |
| `sqe-admin-dashboard-styling.php` | Admin cosmetics |
| `wp-migrate-db-pro-compatibility.php` | Migration helper |

---

## 12. Known defects

These were verified against the database on 1 September 2026 and are all still
open. Resolve them out of this section as you fix them.

### 1. Payment status is never recorded (high)

Step 20's incoming webhook maps the inbound `payment_status` key to field **86**,
which does not exist on form 2. The field is **96**. Form revisions show it was
renumbered from 86 to 96 during the rework that replaced the old
primary/secondary host fields with nested forms, and the step was never
repointed.

`class-step-incoming-webhook.php` sets `$skip_mapping = true` when
`GFFormsModel::get_field()` returns nothing, logs "Incoming field mapping error"
and returns the entry unchanged. **The step still completes and releases the
workflow**, which is why events keep publishing while the status stays blank.

Two further gaps, independent of the mapping:

- Field 96 has no choice marked `isSelected` and an empty `defaultValue`, so
  `Unpaid` is never set at submission.
- No workflow step writes field 96 at all, so `Free` has no mechanism either.

Evidence: `meta_key = '86'` has zero rows anywhere in the database.
`meta_key = '96'` has 30 rows, all on trashed entries, last written 15 June 2026.
None of the 65 active entries, including 40+ Confirmed and paid, carries a value.
"Payment status" is a displayed column in GravityView 386, 419 and 626, so it
renders blank to hosts and committee.

Fix: repoint step 20's mapping to field 96, set the `Unpaid` default on the
field, and add an Update an Entry step (or a Make route 3 write) for `Free`.

### 2. Additional event owners are not created as WordPress users (medium)

The documented behaviour is that co-owners in field 106 become WordPress users
with access to the events dashboard. Nothing implements this.

- No code anywhere handles field 106 or nested form 6.
- Forms 4, 5 and 6 have no feeds at all, so no User Registration feed.
- `mu-plugins/law-secondary-host-users.php` still targets fields 40, 38, 46 and
  writes to field 89, none of which exist on form 2 any more. It can never fire.
- GravityView 386 still filters on field 89, so only the submitter sees the
  event.

Fix would need: a `gform_after_submission_2` (or post-approval) hook walking the
field 106 child entries, creating or linking users with the `event_host` role,
storing their IDs on the parent entry, and a GravityView filter that matches
against that. Then retire the old plugin.

### 3. "Create event listing" and "Publish event" do nothing (medium)

Step 7 has no feed selected (`no_feeds` in its meta), and the only Advanced Post
Creation feed (id 6, "Create event", post type `event`, status `pending`) is
deactivated. There are zero `event` posts (one in the trash) and no entry carries
a post ID, so step 13 has nothing to publish.

Nothing is broken for users, because the programme calendar reads entries
directly (section 8). But the two steps are dead weight, and any documentation
describing "the listing is created as a draft, then published" is wrong.

Decide: either wire the APC feed up properly and make the calendar read posts, or
delete steps 7 and 13 and drop the post-based story entirely. The second is
simpler and matches what the calendar already does.

### 4. A Make outage silently completes the workflow (medium)

Step 17 routes `destination_error`, `destination_error_client` and
`destination_error_server` all to `complete`. If Make is down or returns a 5xx,
the entry jumps straight to Complete: no invoice, no payment-due email, no
publication, no error state. It would sit at Event status "Approved" indefinitely
with nobody alerted.

Fix: route the error destinations to a holding step or at minimum a notification
to the site administrator.

### 5. Step 30's fee condition is saved but switched off (low)

Step 30 ("Email to committee > event approved") stores a condition restricting it
to entries with a fee:

```
feed_condition_conditional_logic        = "0"
feed_condition_conditional_logic_object = {"conditionalLogic":{"actionType":"show",
    "logicType":"all","rules":[{"fieldId":"84","operator":">","value":"0"}]}}
```

The rule is present, the enable toggle is not. `Gravity_Flow_Step::is_condition_met()`
(`includes/steps/class-step.php:1439`) reads the toggle first:

```php
$is_condition_enabled = rgar( $feed_meta, 'feed_condition_conditional_logic' ) == true;
if ( ! $is_condition_enabled || empty( $logic ) ) {
    $condition_met = true;
}
```

`"0"` is falsy, so the rule is never evaluated and the step runs for every
approval, free events included. Compare step 21, which has the same style of rule
with the toggle set to `"1"` and does gate correctly.

No wrong email has actually been sent yet: step 30 was created around 19 to 20
August 2026 and no sponsor or waived event has been approved since.

The fix depends on intent, so confirm with LAW first. If the committee should
hear about every approval, delete the orphaned rule so the settings match the
behaviour. If it really should be paid-only, tick "Enable condition" on the step.
Leaving it as it is misleads whoever opens the step next.

### 6. Minor

- The public programme at `/programme/` is still restricted to committee, editor
  and administrator. Presumably deliberate pre-launch; confirm before announcing.
- The Inbox menu item (post 595) is a draft, so the committee has no navigation
  link to `/inbox/`.
- The Account submenu shows "Events dashboard" to all logged-in users; hosts get
  an access-denied. The If Menu plugin is installed and can gate it by role.
- GravityView 443 "Events (committee - proposed)" is embedded nowhere.
- The free-versus-paid confirmation email split is on tier `= Sponsor`, not on
  fee `= 0`, so a fee-waived UK event receives the non-sponsor wording, "any
  eligible fees have been received".
- The Comments entry list in wp-admin shows no parent event column, making
  threads hard to follow from that screen. A `gform_entries_column` filter
  reading `gpnf_entry_parent` would fix it.

---

## 13. Working on this module

### Where things live in wp-admin

| What | URL |
|---|---|
| Form 2 entries | `admin.php?page=gf_entries&id=2` |
| One entry | `admin.php?page=gf_entries&view=entry&id=2&lid=<id>` |
| Form editor | `admin.php?page=gf_edit_forms&id=2` |
| Workflow steps | `admin.php?page=gf_edit_forms&view=settings&subview=gravityflow&id=2` |
| One step | add `&fid=<step id>` |
| Notifications | `admin.php?page=gf_edit_forms&view=settings&subview=notification&id=2` |

Entries can be filtered by tier, for example:
`admin.php?page=gf_entries&id=2&field_id=53&operator=contains&s=International`

### Migrate List field 48 → Nested Form Speakers field

One-off copy of existing speaker rows into the Speakers child form. Does not
delete the list data. Entries that already have the nested field populated are
skipped. Dry-run by default.

The nested field is auto-detected (110 on local, **112 on live**). Override with
`--nested-field` if needed.

```
wp law migrate-speakers
wp law migrate-speakers --parent=190
wp law migrate-speakers --commit
```

Also at LAW → Migrate speakers. After this has been run on live and field 48
is retired, switch `law_calendar_speakers()` in `functions/calendar.php` from
field 48 to the nested Speakers field (it currently still reads the list).

### Rules of thumb

- Keep logic in WordPress calculated fields, not in Make.
- Update an Entry steps should write fixed values, never recalculate.
- A notification selected by a notification step is auto-suppressed at
  submission by Gravity Flow, so its active flag does not matter. Marking such
  notifications inactive anyway keeps the Notifications screen readable.
- Field IDs are load-bearing across the form, the workflow steps, the Make
  scenarios, the GravityView configurations and the calendar. **Never renumber a
  field.** Defect 1 is exactly what happens when you do.
- After changing anything structural, re-check the Make scenarios; they are not
  in version control and will not fail loudly.
