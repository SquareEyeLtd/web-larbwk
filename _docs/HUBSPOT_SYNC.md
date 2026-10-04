# HubSpot sync: native connector, tagging and backfill

Planning handoff, written 4 October 2026 for whichever agent builds it (Cursor). It replaces the
Make scenario and the Gravity Forms hook in `functions/hubspot.php` with a native module in the
theme that creates and updates HubSpot contacts and applies tags from what WordPress knows, plus
a repeatable backfill.

Read the whole document before starting. Section 10 lists things to confirm in the code before
writing anything, because this plan was written from the Basecamp thread, the spec and a reading
of a few files, not from a full pass over the tree.

---

## 1. Sources

- Basecamp > LAW > message "HubSpot" (May to September 2026), especially Emily's 12 August
  list, Trevor's 1 September update and Emily's 1 September badging note.
- Basecamp > LAW > to-do list "HubSpot" (13 items), especially "Re-look at HubSpot tagging"
  (Emily, 21 September: event hosts segment and the badging master list).
- Spec 4.2.6 (`Event calendar, management, booking (4.2)`), §1 out of scope item 8 and §10:
  HubSpot sync for bookings and attendees **deferred to after year one**. The client has since
  asked for it (badging). See section 12 on scope.
- `ROLES_AND_ACCOUNT_HUB.md` (self-service roles retired 14 September 2026, `law_intent` meta).
- `functions/events/registration.php` (`law_registration_hubspot_tags()`, the
  `law_hubspot_contact_type` user meta) and `functions/hubspot.php` (form 1 hook).

---

## 2. Why this is needed now

**Confirmed by Trevor (4 October 2026):** the Make sync stopped running when the site switched
to Denis's new events system. The custom registration and profile forms that replaced form 1
(User registration) and form 3 (User profile) still write the tag string to user meta
`law_hubspot_contact_type`, but nothing sends it anywhere: `ROLES_AND_ACCOUNT_HUB.md` §11.9
records that the GF HubSpot feed 15 on form 1 is inactive and no webhook feeds exist. So no one
who registered, hosted or booked since the cutover has reached HubSpot from the site.

That is the main reason for Emily's 21 September report that many event hosts were missing, but
probably not the only one: co-owners and additional hosts named on an event never registered
themselves, so even the old Make route would not have caught them. This plan covers both (co-owners
are synced on event approval, section 5.3). Before telling the client how many were missed,
compare WordPress users and event co-owners created since the cutover against HubSpot contacts.

The site has no working path to HubSpot today, and tagging also needs to follow bookings and
event approvals, which Make never saw.

---

## 3. Decisions taken in this plan

1. **Native PHP, no Make.** "WordPress decides, Make passes through" has been the rule; this
   takes it to its end and removes the pass-through. The Make sync already stopped at the switch to
   the new events system, it was disconnected by HubSpot once before that, it cannot see the
   custom forms, and every attempt to put logic in Make formulas has failed. Rebuilding it would
   only restore the registration tags; it would still miss bookings and approvals.
2. **A theme module, not a separate plugin.** `functions/hubspot/`, loaded like
   `functions/events/stripe/`. Every rule depends on `law_*` functions (bookings, events,
   co-owners, speakers), the theme carries the test harness and the LAW > Migration screen, and
   mu-plugins are off limits. Keep the API client free of `law_*` calls so it could be lifted
   into a plugin later if wanted.
3. **The unit of sync is a person (an email address), not a WordPress user.** Speakers and some
   co-owners may have no account. The rules compute the desired HubSpot state for one email from
   everything WordPress knows about it.
4. **Queue, never inline.** Hooks only mark an email as dirty. A cron worker computes and
   pushes. A registration, booking or approval never waits on HubSpot or fails because of it, and
   several changes to one person in a minute coalesce into one API call.
5. **Two kinds of property: shared and site-owned.**
   - *Shared* (Contact type, legal basis): people at LAW also edit these in HubSpot. The site
     only ever **adds** to Contact type and never removes an option, and only sets legal basis
     when it is empty or when upgrading it. This answers Emily's 1 September concern on the
     "Removing / changing tags" to-do: nobody drops out of a segment because the site changed
     its mind.
   - *Site-owned* (the badging and event-activity properties in section 5.2): the site is the
     source of truth and **overwrites** them in full on every sync, because a cancelled reception
     place must disappear from the badging list.
6. **Lifecycle stage and lead status are not touched.**
7. **Three modes**, set by constant: `off`, `dry` (compute and log the payload, send nothing) and
   `live`. Local and staging default to `dry`. LAW is on HubSpot Professional, which has no
   standard sandbox, so a local test run must never reach the live portal.

---

## 4. Authentication and configuration

- A HubSpot **private app** in LAW's portal (148143869, EU data centre). Depending on the portal
  version HubSpot may list this under legacy apps; either works for a server-to-server token.
  Trevor creates it (super admin).
- Scopes: `crm.objects.contacts.read`, `crm.objects.contacts.write`,
  `crm.schemas.contacts.read`, `crm.schemas.contacts.write` (to check and create properties), and
  the communication preferences scopes only if section 7.2 goes ahead.
- Token in `wp-config.php` as `LAW_HUBSPOT_TOKEN`, never in the database or git.
  `LAW_HUBSPOT_MODE` likewise (`off` | `dry` | `live`); unset means `off`.
- Base URL `https://api.hubapi.com` (serves EU portals).
- Year from the existing `law_events_setting( 'year' )`.
- One config function, `law_hubspot_config()`, holds every tag string and property internal name
  used below, so a client answer to section 11 is a one-line change.

---

## 5. What gets written to HubSpot

### 5.1 Standard properties (create or update)

| HubSpot | From | Rule |
|---|---|---|
| `email` | user email / speaker email | identity on first sync |
| `firstname`, `lastname` | user first/last name | always |
| `jobtitle` | user meta `job_title` | always |
| `company` | user meta `organisation` | always |
| `country` | user meta `country` | always (check the portal's property is free text, not a select) |

After the first successful sync, store the HubSpot record ID (`law_hubspot_id` on the user, or on
the speaker post). Later updates go **by ID**, so a profile email change updates the same contact
instead of creating a new one. Matching by email is only for first contact.

### 5.2 Site-owned properties (new, created by the module)

These serve the badging integration (Emily, 1 and 21 September) and event-level segmentation
(Emily, 1 September purposes 3 and 4) without the bloat of one option per hosted event, which
Trevor ruled out on 1 September because custom objects need Enterprise.

| Internal name | Label | Type | Content |
|---|---|---|---|
| `law_events_attending` | LAW-run events | multi-checkbox | Year-prefixed options: `2026 Flagship`, `2026 Monday reception`, `2026 Wednesday reception`, `2026 Friday reception`. One option per **confirmed** booking (paid where paid). Removed on cancel. |
| `law_delegate_type` | Delegate type (current year) | dropdown | Speaker, Sponsor, Delegate, Committee, Exhibitor (Emily, 4 September). From the flagship booking; see 11.4 for reception-only people. |
| `law_dietary` | Dietary requirements | single-line text | ACF `dietary` plus `dietary_other`, joined. Only for people with an LAW-run booking (7.3). |
| `law_accessibility` | Access requirements | single-line text | ACF `accessibility` plus `accessibility_other`. Same limit. |
| `law_events_registered_count` | Hosted events booked (current year) | number | Count of confirmed hosted-event bookings this year (Emily, purpose 4). |
| `law_events_registered` | Hosted events booked (current year) | multi-line text | Titles, one per line. HubSpot segment filters can use "contains". |
| `law_event_sectors` | Event sectors of interest | multi-checkbox | Union of the sector taxonomy terms of events booked (Emily's own suggestion, 1 September). Options mirror the sector list; the module adds a missing option. |
| `law_sync_updated` | LAW site last synced | datetime | Set on every successful push. Useful for checking the backfill. |

Year handling: the multi-checkbox options carry the year (matching the existing Contact type
pattern), so 2027 adds options rather than overwriting 2026. The single-value properties
(`law_delegate_type`, counts, dietary, access) are **current year** and are overwritten when the
year setting changes. That is right for badging. If the client wants history for those, add a
year to the internal name later.

### 5.3 Contact type (shared, add only)

The existing multi-checkbox property (confirm its internal name; it is not necessarily
`contact_type`). The module reads the current value, unions in the site's tags and writes the
result. It never removes an option.

| Tag | Who | When |
|---|---|---|
| `2026 Registered user` | anyone with a WordPress account | account created (self-registered or created by the engines; see 11.1) |
| `2026 Event Host` | primary host of an event | the event reaches Approved (Emily, 21 September) |
| `2026 Event Contact` | co-owners / additional hosts | the event reaches Approved, or a co-owner is added to an approved event |
| `2026 Sponsor` | primary host and co-owners of an approved **sponsor-tier** event | as above (see 11.2) |
| `2026 Attendee` | anyone with a confirmed booking on any event | booking confirmed |
| `2026 Speaker` | a speaker linked to an event that is approved or published | link created, or the event is approved |
| `2026 Press` | holder of an admin-issued press booking | booking issued |

The retired attendee checkbox settles the old question on the "Tagging event attendees" to-do:
there is no checkbox any more, so Attendee can only mean "has a confirmed booking".

`Sponsor attendee` and `Supporting organisation attendee` (Emily, 12 August) are **not built**:
neither has a definition the site can compute (see the two to-dos). For badging, Sponsor is
already a delegate type.

---

## 6. Architecture

```
functions/hubspot/
  _load.php        require the rest; bail out early when mode is off and no admin screen is open
  config.php       law_hubspot_config(), mode, token accessors
  client.php       thin HTTP client: no law_* calls
  properties.php   check/create the site-owned properties and missing options
  rules.php        pure: email in, desired HubSpot state out
  queue.php        table, enqueue, worker, retry
  hooks.php        WordPress events that mark people dirty
  admin.php        LAW > HubSpot screen
  cli.php          wp law hubspot ... (when WP_CLI)
```

### 6.1 Client (`client.php`)

- `law_hubspot_request( $method, $path, $body = null )` over `wp_remote_request`, 15 s timeout,
  JSON in and out, returns array or `WP_Error`.
- 429: honour `Retry-After`, then retry up to three times. 5xx and timeouts: exponential backoff,
  three tries. 4xx other than 429: no retry; return the error with HubSpot's message.
- In `dry` mode, GET requests still run (so dry runs can diff against reality); writes are logged
  and return a fake success.
- Endpoints used:
  - `POST /crm/v3/objects/contacts/batch/read` with `idProperty: email` (and by ID) to fetch
    current Contact type and legal basis before merging.
  - `POST /crm/v3/objects/contacts/batch/upsert` with `idProperty: email` for first sync.
  - `POST /crm/v3/objects/contacts/batch/update` by ID thereafter.
  - `GET/POST/PATCH /crm/v3/properties/contacts[/{name}]` for property checks.
  - Batches of up to 100.

### 6.2 Rules (`rules.php`)

`law_hubspot_desired_state( string $email ): array` returns:

```php
array(
	'email'              => 'x@y.com',
	'hubspot_id'         => '123' | '',
	'properties'         => array( ... ),   // standard + site-owned, full values
	'contact_type_add'   => array( '2026 Attendee', ... ),
	'legal_basis_min'    => 'customer' | 'other',
	'sources'            => array( 'user' => 12, 'speaker' => 0, 'bookings' => array( ... ) ),
)
```

Pure: reads WordPress, writes nothing, no HTTP. This is where every rule in section 5 lives and
where the tests concentrate. `law_hubspot_people()` returns every email the site knows about
(users plus speakers with an email) for the backfill.

### 6.3 Queue (`queue.php`)

- Table `{prefix}law_hubspot_queue`: `email` (unique), `reason` (last trigger, for the log),
  `attempts`, `next_attempt_at`, `last_error`, `queued_at`. Created by `dbDelta` on version bump,
  and by the provisioning routes if that is the house pattern (confirm).
- `law_hubspot_enqueue( $email, $reason )`: upsert the row, reset `next_attempt_at` to now. Cheap
  enough to call from any hook.
- Worker on a 5-minute cron event: take up to 100 due rows, batch-read the matching contacts,
  merge (union Contact type, legal basis upgrade-only), batch-write, delete rows that succeeded,
  bump `attempts` and `next_attempt_at` on failures (5 min, 30 min, 2 h, 12 h, then park and show
  on the admin screen). Lock with a transient so two runs never overlap.
- Confirm how cron runs on production. If WP-Cron depends on traffic, ask the host for a real
  cron, because the badging list should be minutes behind, not hours.

### 6.4 Hooks (`hooks.php`)

Hook as generically as possible so new code paths cannot bypass the sync:

- `user_register` and `profile_update` (covers the custom forms and engine-created accounts).
  Profile updates also catch an email change: enqueue the new address and keep the stored ID.
- `transition_post_status` on the booking CPT (`LAW_BOOKING_CPT`): any change, enqueue the
  attendee's email (post author) and, for colleague bookings, nothing else.
- `transition_post_status` on the event CPT, into or out of the approved/published statuses:
  enqueue the primary host and every co-owner.
- Co-owner changes on an event (whatever `law_event_set_co_owner_ids()` writes): enqueue added
  and removed people.
- Speaker save and speaker-to-event linking: enqueue the speaker's email.
- ACF saves of `dietary` / `accessibility` on a user are covered by `profile_update` only if the
  profile handler triggers it; the on-behalf writes in
  `law_registration_apply_attendee_profile()` may not. Confirm and add an explicit enqueue.
- `delete_user`: do nothing in HubSpot. Deleting contacts is for LAW to do by hand.

### 6.5 Admin screen (`admin.php`), LAW > HubSpot

Committee and administrators only. Shows: mode, token check (a cheap authenticated GET), property
check result, queue length, parked failures with their errors, last successful run.

Buttons: **Check properties** (report, then create missing with a second click), **Preview
backfill** (dry-run CSV, section 8), **Queue everyone**, **Process queue now**, **Retry parked**.

### 6.6 WP-CLI (`cli.php`)

`wp law hubspot status | check-properties [--create] | preview [--out=file.csv] | backfill [--limit=N] | process | person <email>`.
`person` prints the desired state and the current HubSpot state for one email, which is the
quickest way to answer "why is X not in the list".

### 6.7 Logging

Each push logs one line per person to `{prefix}law_hubspot_log` (email, action, properties
changed, result), pruned to 90 days. Do not reuse `law_event_log()`: most syncs have no single
event to attach to.

---

## 7. Consent and personal data

### 7.1 Legal basis (`hs_legal_basis`)

Peter's answer (relayed by Emily, 10 September): Legitimate interest, customer for people who
registered for an event; Legitimate interest, other for people who only created an account. So:

- Account only: `Legitimate interest – other`.
- Any booking, hosting role or speaker link: `Legitimate interest – existing customer`.
- Set when empty; upgrade other to customer; never downgrade or overwrite anything else LAW set.
- Fetch the exact option values from the portal; the labels contain an en dash and the stored
  values may differ from the labels.

Emily asked whether "customer" requires payment. That is a question for Fred, not for the code;
the rule above is configurable either way.

### 7.2 Subscription status

Trevor set the 257 registered users to subscribed by hand on 1 September. Doing that
automatically for every new account is the riskiest thing in this plan. **Recommendation:** add
an unticked "Keep me informed about London Arbitration Week" box to the registration form, next
to the privacy notice link Emily approved on 9 September, and only subscribe people who tick it
(communication preferences API). Without that box, leave subscription status alone and let LAW
manage it in HubSpot. Fred to confirm. Build this as phase 4 so it does not hold up the rest.

Also check the portal setting that decides whether contacts created through integrations become
marketing contacts. The Mailchimp import landed as non-marketing on 4 June.

### 7.3 Dietary and access data

Dietary and access requirements can reveal health information. Sending them to HubSpot, and on
to the badging vendor, is a new disclosure. Limit it to people with an LAW-run booking (the
badging need), mention HubSpot and the badging tool in the privacy notice, and consider clearing
the two properties after the week. Fred to confirm before go-live of phase 3.

---

## 8. Backfill

Uses the same rules, queue and worker as live sync, so there is one code path.

1. Deploy with `LAW_HUBSPOT_MODE` = `dry`.
2. **Check properties.** Create the site-owned properties. Report every Contact type option the
   rules need and whether it exists, comparing **internal values**, not labels (the "Sponsor" /
   "2026 Sponsor" label/value mismatch has bitten before; fix mismatches with HubSpot's
   enumeration merge tool, not by editing values that contacts already hold).
3. **Preview.** For every person, compute the desired state, batch-read the current HubSpot
   state, and write a CSV: email, exists in HubSpot (y/n), Contact type before, Contact type
   after, each site-owned property before/after, legal basis before/after. Read-only.
4. Review the CSV with Emily. Recompute the headline counts independently (users, people with a
   confirmed flagship booking, Monday, Wednesday, approved hosts) straight from the database and
   compare them with the CSV's own totals before anyone acts on it.
5. Set mode to `live`, **Queue everyone**, let the worker run (a few hundred people is a handful
   of batch calls).
6. Verify in HubSpot: every pushed contact has `law_sync_updated` set; segment counts match the
   step 4 figures.
7. The backfill is repeatable at any time. Re-running it is how a rule change reaches existing
   contacts.

Existing contacts are safe: upsert by email merges into the 3,548 Mailchimp records, Contact type
keeps "Mailchimp" and the September manual tags because it is add-only, and legal basis is only
filled or upgraded.

**One consequence of add-only to raise with Emily:** the 1 September manual import tagged people
`2026 Attendee` because they had ticked the attendee checkbox. Under the new rule some of them
have no booking. The site will not remove those tags. If Emily wants Attendee to mean "booked"
for 2026, someone clears the option in HubSpot once, before the backfill, and the backfill
re-adds it for the people who qualify.

---

## 9. Build order

Each phase ends with its tests green and a dry-run check.

1. **Phase 1, plumbing.** `config.php`, `client.php`, `properties.php`, queue table and worker,
   admin screen shell, CLI `status` / `check-properties`. No rules yet.
2. **Phase 2, people and Contact type.** Rules for standard properties and the Contact type tags
   in 5.3, legal basis, user and event hooks, `preview`. Delete `functions/hubspot.php` (its hook
   only fires on the retired form 1) and switch off or delete the dormant Make scenario so it
   cannot be revived by accident alongside the new sync. Backfill. This alone fixes Emily's
   missing event hosts.
3. **Phase 3, badging and activity.** `law_events_attending`, `law_delegate_type`, dietary and
   access, the hosted-event count, titles and sectors. Booking hooks. Backfill again. Hand Emily
   the property names for the badging vendor.
4. **Phase 4, subscription**, only if 7.2 is agreed.

Tests (PHPUnit, real local DB, existing harness):

- `HubSpotRulesTest`: desired state for fixtures covering each row of 5.2 and 5.3; a cancelled
  reception booking drops its option; applied, waitlisted and payment-failed flagship bookings
  do not count; a co-owner of an unapproved event gets nothing; a speaker with no account gets
  Speaker; legal basis other vs customer.
- `HubSpotMergeTest`: union never drops an existing option, including options the site does not
  know; legal basis upgrade-only.
- `HubSpotQueueTest`: enqueue coalesces; failures back off and park; the lock prevents overlap.
- `HubSpotClientTest`: with `pre_http_request` filtered, 429 retries honour `Retry-After`; `dry`
  sends no writes.
- No test talks to HubSpot.

---

## 10. Confirm in the code before building

This plan names these from reading part of the tree; check each one.

1. The booking statuses that mean "confirmed" for each kind, and `law_booking_kind()`'s values
   for flagship, receptions (which reception?) and hosted events. Bookings use `publish` for
   active and `law-cancelled`; flagship adds `law-applied` and `law-payment-failed`.
2. Where the flagship **delegate type** is stored (Emily: "we have the delegate type in
   flagship"), and its exact option values.
3. How press bookings are marked.
4. The event workflow statuses that count as approved (Approved, Confirmed, published) and the
   meta that marks the sponsor fee tier.
5. Co-owner storage and `law_event_set_co_owner_ids()`, and whether it fires an action.
6. The speaker post type, its email meta and how speakers link to events.
7. The event sector taxonomy name.
8. How the Members/provisioning routes create tables, if the queue and log tables should follow
   that pattern (step 10 / `?setup-account-pages`).
9. Whether `profile_update` fires on every path that writes user meta the rules read.
10. The internal name of the Contact type property and its current options (from the portal).

---

## 11. Open decisions for LAW (each has a default the code ships with)

1. **Registered user for engine-created accounts** (co-owners, booked colleagues). Default:
   yes, everyone with an account. Alternative: only people who registered themselves.
2. **Sponsor tag source.** The sponsor tick is gone and was self-asserted anyway. Default: hosts
   and co-owners of approved sponsor-tier events. Existing `2026 Sponsor` tags stay (add-only).
   Alternative: a committee-maintained list.
3. **Attendee meaning for 2026** and whether to clear the September manual tags first (section 8).
4. **Delegate type for people with only a reception booking.** Default: `Delegate` until the
   receptions carry their own delegate type (Emily asked for that on 21 September; it is a booking
   form change, not part of this connector).
5. **Subscription status** (7.2) and **dietary/access data** (7.3): Fred.
6. **Sponsor attendee, Supporting organisation attendee, survey responses**: not built until
   defined.
7. **Lifecycle stage and lead status**: default, hide them in HubSpot (Trevor's 1 September
   suggestion); the module never writes them.

---

## 12. Scope note

Spec 4.2.6 §1 (out of scope, item 8) and §10 deferred HubSpot sync for bookings and attendees
to after year one; "the existing sponsor/host HubSpot sync from earlier phases is unaffected".
§1 item 2 also puts event badging out of scope as a third-party solution.

- **Phase 2 is in scope.** Contact type tagging was agreed as outstanding from Phase 1 in the
  "Phase 4.2: prioritisation" thread (Trevor 12 August, Emily 14 August: "Agree we need to do
  some of the hubspot tagging now"), and it restores the sync the custom forms broke.
- **Phase 3 is a change request** under Appendix A §2 of the Phase 4.1/4.2 proposal ("will
  normally be treated as a change request", estimate before work proceeds). It is the booking
  and attendee sync the spec deferred, requested now to feed the third-party badging tool.
  Estimate and agree it before building Phase 3.

---

## 13. Build log

### Phase 1 (built 4 October 2026)

`functions/hubspot/` with `_load.php`, `config.php`, `client.php`, `log.php`, `properties.php`,
`queue.php`, `admin.php` (LAW > HubSpot, `edit_others_law_events`) and `cli.php`
(`wp law hubspot status | check-properties [--create] [--contact-type] | process | queue <email> |
retry-parked | log`). Loaded from `functions.php` after the events module. Tests:
`HubSpotClientTest`, `HubSpotQueueTest`, `HubSpotPropertiesTest`; `tests/bootstrap.php` refuses any
unmocked request to `api.hubapi.com`.

Decisions made while building, beyond the plan:

- **Environment rail.** `live` in wp-config.php on a `local` or `development`
  `WP_ENVIRONMENT_TYPE` runs as `dry` (`law_hubspot_mode_for()`); the admin screen says so.
  Staging is not downgraded, because its config is written on purpose and it is where the dry-run
  review happens.
- **Dry mode logs writes before checking the token**, so a dry run with no token still records
  what it would have sent. Reads still need the token.
- **Tables** follow the migration log pattern (lazy `dbDelta`, `SHOW TABLES` guard, version
  option `law_hubspot_db_version`), not the provisioning routes.
- **Worker lock** is `add_option()` on `law_hubspot_worker_lock` (atomic via the unique key),
  broken after 10 minutes. Re-queuing a parked row un-parks it for one more try without
  resetting attempts.
- **The sync step is a seam**: `law_hubspot_worker_sync()` calls `law_hubspot_sync_batch()` when
  phase 2 defines it (filter `law_hubspot_sync_batch` for tests). Until then the worker fails
  rows with "rules are not installed" and "Queue everyone" is disabled.
- **Property labels.** The two hosted-event properties are labelled "(count, current year)" and
  "(titles, current year)" so they can be told apart in HubSpot's property picker.
- **Contact type options** can be added from the screen as a separate, explicit action; it refuses
  when a needed value already exists as the label of a different value (fix with the merge tool).
- **Sector option values** are entity-decoded term names ("Banking & Financial Services").

Section 10 answers (from the code, 4 October 2026):

1. Confirmed = `publish` for every kind; `law_booking_kind()` → `flagship | reception | hosted`
   from the parent event; the reception is the booking's `post_parent`, matched to
   `law_reception_seed_map()` slugs (`opening-drinks`, `wednesday-reception`,
   `friday-reception`).
2. Delegate type: booking meta `_law_ticket_type`, values `law_booking_ticket_types()`
   (`delegate`, `sponsor`, `speaker`, `exhibitor`, `committee`); no default, unset = unclassified.
3. Press: booking meta `_law_is_press`.
4. Approved: `law-approved` and `publish` (`law_event_has_been_approved()`); sponsor tier is
   event meta `_law_fee_tier === 'sponsor'`.
5. Co-owners: `_law_co_owner_ids` plus flat `_law_co_owner` rows, written only by
   `law_event_set_co_owner_ids()`, which fires no action (phase 2 adds one).
6. Speakers: `LAW_SPEAKER_CPT`, email `_law_speaker_email`; links are `_law_speakers` rows on the
   event/session; reverse lookup `law_speaker_appearances()`.
7. Sector taxonomy: `law_sector`.
8. Tables: see above.
9. `law_registration_write_profile_meta()` writes with `update_user_meta()` / ACF
   `update_field()`, which do not fire `profile_update`; phase 2 adds explicit enqueues in the
   register, profile and `law_registration_apply_attendee_profile()` paths.
10. Portal, read with the token on 4 October 2026:
    - Contact type is `contact_type` (group `contactinformation`, checkbox), 17 options. All
      seven tags exist as **values** already, including `2026 Event contact` (lower-case c;
      config follows the portal) and `2026 Press`. The old `Sponsor` value is now labelled
      "Sponsor generic". The portal also holds `2026 Monday reception`, `2026 Wednesday
      reception`, `2026 Friday reception`, `2026 Supporting Org`, `2026 Sponsor attendee`,
      `2026 Supporting org attendee` and `2025 …` options that the plan does not write; see the
      note below.
    - `hs_legal_basis` values are inconsistent in the portal: `Legitimate interest – existing
      customer` (en dash) but `Legitimate interest - other` (hyphen). Config copies both exactly.
      The property's fieldType is `checkbox`, so phase 2 must read it as a possibly
      multi-valued string before deciding "empty" or "upgrade".
    - `country`, `jobtitle`, `company` are free-text strings (the §5.1 question).
    - The `law_site` group and all eight site-owned properties do not exist yet.

The property check now also reports **near-misses** (a needed value that exists in the portal
differing only in case or dash characters) and refuses to add a Contact type option in that
case, because the fix is almost always the config string.

`wp law hubspot check-properties --create --live` writes property definitions for real from a
site whose mode is `dry` (including local, where `live` is otherwise refused). It asks for
confirmation and is the only override of the environment rail: definitions carry no contact
data, and creating them from local avoids a staging deploy just to run one command.

**For Emily (phase 2 scope):** the portal already has reception tags in Contact type. The plan
puts reception attendance in `law_events_attending` instead. Decide whether the rules should
also add the Contact type reception tags (add-only, so harmless) or whether those options are
retired. *Phase 2 writes them for now (Trevor, 4 October 2026); see below.*

Still open before a dry run: `LAW_HUBSPOT_MODE=dry` in the staging wp-config.php.

### Phase 2 (built 4 October 2026)

Added `rules.php` (desired state for one address), `sync.php` (plan, push, preview) and
`hooks.php` (what enqueues people); `functions/hubspot.php` (the Make-era form 1 hook) and its
`require` are gone. Admin: "Queue everyone" is live, "Download backfill preview (CSV)" and a
person lookup (what the rules would write, what HubSpot holds, a "Queue … for sync" button).
CLI: `person <email>`, `preview [--out=file.csv] [--email=a,b]`, `backfill [--limit=N] [--yes]`.
Tests: `HubSpotRulesTest` (rules and merges), `HubSpotSyncTest`, `HubSpotHooksTest`;
121 HubSpot tests in all. Verified against the real portal from local in dry mode: `wp law
hubspot person` and the admin lookup read a live contact and show the merge; a dry `process`
logs the exact batch/update body and sends nothing.

Decisions made while building:

- **One code path.** `law_hubspot_plan_batch()` computes desired state, reads the contact (by
  stored ID first, then by email), merges and returns the exact properties to write with
  before/after. The worker, the preview CSV, the person lookup and the dry run all go through
  it, so they cannot disagree.
- **Reception Contact type tags are written** (`2026 Monday/Wednesday/Friday reception`), keyed
  by the reception's `law_reception_seed_map()` slug (config `reception_tags`). Add-only, so
  retiring them later means deleting three config lines; Emily to confirm. The property check
  includes them in the Contact type values it needs.
- **Legal basis as a possibly multi-valued string.** Set when empty; upgrade only when the
  existing value is exactly `[other]`; any list of two or more, or any value LAW chose, is left
  alone. An address with no account and no appearances (an idle speaker record) gets no legal
  basis at all: there is nothing to base it on.
- **Empty standard values are never sent**, so a blank profile field cannot erase what LAW typed
  into HubSpot. A speaker with no account gets name, and organisation / job title from their
  latest appearance; an account's own profile wins when both exist.
- **Identity.** The HubSpot record ID is stored on the user (`law_hubspot_id` user meta) and/or
  the speaker post after a successful push, or when the read finds the contact. A stored ID
  HubSpot no longer knows is forgotten and the email read takes over (merged contacts). Dry runs
  store nothing.
- **Upserts carry `email` in properties** so HubSpot's response echoes it and the result maps
  back to the person; updates by ID do not send email.
- **Per-item errors.** A 207 `errors[]` entry is attributed to the person it names (any array in
  `context`); a whole-batch 4xx (one bad value fails all 100) is retried one person at a time so
  the error lands on the right row; a 5xx fails the batch once and the queue backs off.
- **Unknown address** in the queue (account deleted since it was queued) is logged `skipped`
  and removed, not failed.
- **Hooks** are generic rather than per-form: `user_register`, `profile_update`,
  `added/updated_user_meta` for `first_name`, `last_name`, `organisation`, `job_title`,
  `country` (the registration and profile forms write these directly, §10.9);
  `transition_post_status` for bookings (attendee email, any change) and for events entering or
  leaving `law-approved` / `publish` (author, co-owners, speakers on the event and its
  sessions); the new `law_event_co_owners_set` action in `law_event_set_co_owner_ids()` (added
  and removed); `added/updated_post_meta` for `_law_speakers`, `_law_speaker_email` (written
  after the post insert, so `save_post` alone misses a new speaker) and `_law_fee_tier`;
  `save_post_law_speaker`. `delete_user` does nothing. Every callback checks the mode, so the
  hooks file is always loaded and `off` needs no cache clear.
- **The event status guard** (`wp_insert_post_data`) means a status only changes through the
  engine, which is also the only path the hook needs to see; the hooks test raises the engine's
  flag rather than running an approval through Stripe.
- **Tests install the queue tables in `tests/bootstrap.php`**: the hooks now fire in every
  suite (mode is `dry` locally), and a lazy `dbDelta` inside a test transaction would commit it.
- **CSV** guards formula injection (leading `=+-@` prefixed with `'`) and starts with a BOM for
  Excel.

Outside the code, still to do before go-live: switch off the dormant Make scenario (Trevor);
`LAW_HUBSPOT_MODE=dry` on staging, review the preview CSV with Emily, then `live`.

---

## 14. Risks

1. **Live portal from local.** The default mode must be `off`, and local must be `dry`. One
   stray backfill from a local database full of test users would pollute LAW's CRM.
2. **Contact type label/value mismatch.** Writing a value that is not an existing option fails
   the whole batch item. The property check must run before any live push, and the worker must
   report per-item errors rather than failing the batch silently.
3. **Batch partial failures.** HubSpot batch endpoints can fail individual items. Parse per-item
   results and requeue only those.
4. **Email changes.** Without the stored HubSpot ID, a changed email creates a duplicate
   contact. Store the ID on first sync.
5. **Cron.** If WP-Cron only runs on traffic, the badging list lags. Ask for a real cron.
6. **Two writers on Contact type.** LAW staff editing a contact while the worker writes can lose
   one side's change (read, union, write is not atomic). Low risk at this volume; the next sync
   re-adds site tags, and staff edits that remove a site tag will come back. Tell Emily: to stop a
   person getting a site tag, change it in WordPress, not HubSpot.
7. **Health-adjacent data** leaving the site (7.3).
