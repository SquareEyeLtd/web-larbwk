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

Revised 12 November 2026 for the badging decisions (Emily, 4 November; Event Connections'
badging app reads HubSpot directly). The badging set is **year-prefixed**: `law26_…` for 2026,
`law27_…` for 2027, the prefix from `law_events_setting( 'year' )`
(`law_hubspot_year_prefix()`). A pass type is a fact about one year's badge, a property cannot
hold two years' worth, and HubSpot cannot rename a property once created, so each year gets a
fresh set and Event Connections read the current year's. The `_ec_` names are the ones their
app reads. Internal names were settled by Trevor on 16 November 2026 (the `law26_` set below);
changing one is still a single line in `law_hubspot_config()`.

**Property groups** (the section headings on a contact record): the un-prefixed properties sit
in `law_site` ("LAW site"); the year's set sits in its own group named by the prefix and
labelled the way LAW label the year — `law26` / **"LAW2026"** (Trevor, 16 November 2026; not
"badge", since the data serves more than badging). Both groups are created by
`check-properties --create` when missing, and next year's set gets `law27` / "LAW2027" without
further code. Config `property_groups`; each definition in
`law_hubspot_property_definitions()` names its group.

| Internal name | Label | Type | Content |
|---|---|---|---|
| `law_events_attending` | LAW-run events | multi-checkbox | Fixed year-prefixed options only: `2026 Flagship`, `2026 Monday reception`, `2026 Wednesday reception`, `2026 Friday reception` (`law_hubspot_attending_options()` = config). Emily (16 November 2026) declined extending badging to Evensong — those guests are covered by the opening reception. A fourth LAW-run reception can still sync dietary/access; it earns no attending option and no Evening only badge on its own. This year's options are replaced on every sync (so a cancelled place drops off); other years' options are kept. |
| `law26_ec_passtype` | 2026 pass type (badge) | dropdown | Delegate, Speaker, Sponsor, Committee, Press, Patron, **Evening only** (`law_booking_ticket_types()`; Exhibitor retired). From this year's **confirmed LAW-run** bookings via `law_hubspot_passtype_from_law_run()`: a `_law_is_press` flag counts as Press. **Flagship** guests: first in `passtype_precedence` wins — **Delegate, Press > Sponsor > Committee > Speaker > Patron > Evening only** (Emily, 12 November 2026; Evening only last — Milly, 18 November 2026). Unclassified Flagship sends nothing (never blanked), so a HubSpot hand-typed value survives. **Reception-only** (no Flagship place): a named role still wins; otherwise **Evening only** — including when the reception booking is blank or still says Delegate, because Delegate is reserved for the £600 conference (Milly / directors, 18 November 2026). Flagship + evenings stays Delegate (or whichever role wins). |
| `law26_dietary_icon` | 2026 dietary icon (badge) | dropdown | The one icon Event Connections print: `Vegetarian or vegan`, `Pescatarian`, `Allergy or intolerance`. Folded from the site's dietary choices through the config's `dietary_icon_map` (Vegetarian, Vegan → Vegetarian or vegan; Pescatarian → Pescatarian; Gluten free, Dairy free, Coeliac, Lactose free, Nut allergy → Allergy or intolerance; Other → no icon); when several icons result, `dietary_icon_precedence` picks one, **allergy first** (Emily and Milly, 12 November 2026). Both are one-line config edits. |
| `law26_dietary` | 2026 dietary requirements | multi-checkbox | The site's own choices as options (`law_registration_dietary_choices()` minus Other). The detail behind the icon. |
| `law26_dietary_other` | 2026 dietary requirements (other) | single-line text | The free text. |
| `law26_access` | 2026 access requirements | multi-checkbox | The site's stored accessibility values (Captions, Sign language, Animal / PCA, Wheelchair). |
| `law26_access_other` | 2026 access requirements (other) | single-line text | The free text. |
| `law26_badge_manual` | 2026 badge set by hand | yes/no | **LAW-owned: the site creates it and never writes it.** While Yes, the sync leaves `law26_ec_passtype` and `law26_dietary_icon` alone for that contact, so a decision made in HubSpot is not undone by the next booking. The detail properties keep flowing. |
| `law26_ec_arrived` | 2026 badge first printed | datetime | **LAW-owned: the site creates it and never writes it.** Event Connections write the date/time of the first badge print (Kitty, 9 November; Emily asked for it, 16 November 2026). |
| `law_events_registered_count` | Hosted events booked (count, current year) | number | Confirmed bookings on hosted events (not LAW-run) this year (Emily, purpose 4). Nought is not written over a blank. |
| `law_events_registered` | Hosted events booked (titles, current year) | multi-line text | Titles, one per line. HubSpot segment filters can use "contains". |
| `law_event_sectors` | Event sectors of interest | multi-checkbox | Union of the sector taxonomy terms of every event booked, any year (Emily's own suggestion, 1 September). Options mirror the sector list; the module adds a missing option. |
| `law_sync_updated` | LAW site last synced | datetime | Set on every successful push. Useful for checking the backfill. |

The dietary and access properties (icon, detail and other) are computed **only for people with
a confirmed place at a LAW-run event this year** (7.3). Values come from the account's profile
with the booking's stored answers (`_law_attendee_profile`) as the fallback, through
`law_booking_profile_values()`, and are the union across that person's LAW-run places. A person
with a place and nothing ticked gets the properties cleared (a requirement removed on the site
leaves the badge); a person with no LAW-run place this year gets them cleared too, because the
disclosure that justified sending them has ended.

Site-owned properties are written **as differences only**: the plan reads them back with the
shared pair and writes a property only when the desired value differs from what the contact
holds. A property the portal does not have yet (the `law26_` set, until Kitty confirms) is
neither read nor written; the property check records which exist
(`law_hubspot_portal_has_property()`), and the first sync after a deploy fetches the list itself.

**Superseded:** `law_delegate_type`, `law_dietary` and `law_accessibility` (created 4 October)
are replaced by the set above. The property check reports them while they remain;
`wp law hubspot check-properties --archive` (or the admin button) archives them, and only when
asked.

Year handling: the un-prefixed multi-checkbox properties carry the year in their option VALUES
(matching the existing Contact type pattern), so 2027 adds options rather than overwriting 2026.
The badging properties carry the year in their NAMES. The counts are current year and are
overwritten when the year setting changes.

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
- `law_speakers_merged` (fired by `law_events_merge_speakers_apply()` after the second record
  is trashed): enqueue both records' addresses.
- Speaker removal (phase 3): `before_delete_post` at priority 5 on a speaker record (the address
  is read before the meta goes with the post); `delete_post_meta` on `_law_speakers` (the OLD
  rows, read before the last one leaves a post — a delete hands the handler nothing useful).
  Trashing needs nothing extra: it is a post update.
- User meta `dietary`, `dietary_other`, `accessibility`, `accessibility_other` (phase 3): added,
  updated and **deleted** — unticking the last box deletes the key, and the icon must go with it.
  ACF stores the two fields under those plain names, so an ACF save and a custom-form save look
  alike. Not during `wp_delete_user()`, which strips every key first (`delete_user` /
  `deleted_user` bracket it).
- Booking meta `_law_ticket_type`, `_law_is_press`, `_law_attendee_profile` (phase 3): added,
  updated and deleted (a cleared ticket type is a delete) — enqueue the attendee.
- `delete_user`: do nothing in HubSpot. Deleting contacts is for LAW to do by hand.

### 6.5 Admin screen (`admin.php`), LAW > HubSpot

Committee and administrators only. Shows: mode, token check (a cheap authenticated GET), property
check result, queue length, parked failures with their errors, last successful run.

Buttons: **Check properties** (report, then create missing with a second click), **Preview
backfill** (dry-run CSV, section 8), **Queue everyone**, **Process queue now**, **Retry parked**.

### 6.6 WP-CLI (`cli.php`)

`wp law hubspot status | check-properties [--create] [--contact-type] [--archive] [--live] | preview [--out=file.csv] | backfill [--limit=N] | process | person <email>`.
`person` prints the desired state and the current HubSpot state for one email, which is the
quickest way to answer "why is X not in the list". `check-properties --archive` archives the
superseded properties the check lists (§5.2), after a confirmation of its own; dry mode logs
instead.

One-off clean-up: `wp law hubspot remove-option <property> <value> [--unless-booked] [--apply]`
takes one value out of a multi-checkbox property on every contact holding it, leaving every
other value. Dry run by default (lists the contacts, before and after, writes nothing);
`--unless-booked` leaves it on anyone whose account has a confirmed booking. Built for clearing
"2026 Attendee" before the backfill (§8, §13).

`wp law hubspot clear-stale-evening-only [--apply]` clears `law26_ec_passtype` where HubSpot
still holds **Evening only** but the site no longer wants that badge (Evensong-only or no
badged LAW-run place). Real Monday/Wednesday/Friday reception-only guests are kept. Dry run
by default; the sync never blanks pass type itself, so this is the one-shot fix after the
Evensong badging change (19 November 2026).

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
the two properties after the week. **Peter signed off on sending dietary and access data to
HubSpot and Event Connections (November 2026).** The rules enforce the limit: the dietary and
access properties are computed only for people with a confirmed LAW-run place this year, and
are cleared when that stops being true (5.2). Clearing after the week is a one-off for LAW to
decide; the `law26_` names make a later year's set independent of this one.

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
4. **Pass type for people with only a reception booking.** Settled 12 November 2026: the
   reception bookings lists carry the same committee-only Ticket type column as the flagship
   dashboard. **Precedence** settled by Emily, 12 November 2026: Delegate + Press (never the
   same person) > Sponsor > Committee > Speaker > Patron — a paying delegate who also speaks
   is badged Delegate. **Evening only** added 18–19 November 2026 (Milly / directors; Kitty
   can wire it as a seventh pass type): reception-only guests without a named role get
   Evening only automatically; Flagship + evenings stays Delegate (or the winning role).
   **Allergy icon wins** confirmed by Emily and Milly the same day. Kitty (Event Connections,
   12 November) chose a single-value pass type (option A) and one dietary value per person,
   which is what `law26_ec_passtype` and `law26_dietary_icon` are; she matches on HubSpot
   contact ID, not email. **`law26_badge_manual`**: Emily thinks Yes (16 November) but asked
   for a plain-English re-explain. **`law26_ec_arrived`**: Emily asked for it (16 November) —
   LAW-owned datetime in the LAW2026 group for Event Connections' first badge print.
   **Evensong**: not a badged event (Emily, 16 November); no attending option, no Evening only
   from Evensong alone. **Auto Delegate for paid Flagship**: no (Emily — paid sponsor places
   are easy to miss).
5. **Subscription status** (7.2): Fred. **Dietary/access data** (7.3): signed off by Peter.
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
  Estimated at 12–16 hours on 5 October 2026 (Basecamp "HubSpot" thread, "HS3"); **approved by
  Emily on 13 October 2026** (16 hours of top-up development requested). The open decisions it
  still needs are in §13, "Phase 3 to-do".

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

### Catching up with master (12 November 2026)

Master gained three features after the hooks were written (21–26 October 2026): deleting a
speaker record, merging two speaker records, and booking a place for someone else. Merged
master into `hubspot` (no conflicts) and checked each against the hooks:

- **Speaker deletion** is caught. Trashing is a `wp_update_post`, so `save_post_law_speaker`
  queues the trashed speaker; the detach rewrites `_law_speakers`, so the speakers who remain
  are queued through the meta hook. A trashed record is excluded from `law_hubspot_people()`
  and never matched by `law_speaker_find_existing()` (`post_status => any` excludes trash), so
  a speaker with no account resolves to nobody and is skipped as `unknown`; one with an account
  is re-synced. The `2026 Speaker` tag stays, as add-only intends. Not caught: a permanent
  delete (`before_delete_post`, no `save_post`) and the `deleted_post_meta` that fires when the
  last row goes. Neither matters while tags are add-only; see the Phase 3 to-do.
- **Speaker merge** had a real hole, now fixed in `law_events_merge_speakers_apply()`: it moved
  rows, entry IDs, years and old slugs to the kept record but not `_law_speaker_email` or
  `law_hubspot_id`. When the merged record was the one with the address, the kept record was
  left with none, the person dropped out of every email-keyed lookup, and the HubSpot contact
  created from the merged record could never be reached again. Now a blank email on the kept
  record takes the merged record's, and the HubSpot ID follows when the kept record has none
  and the emails match (or the email just moved). The kept record's own address is never
  overwritten. New `do_action( 'law_speakers_merged', $main_id, $merged_id )` after the trash;
  the module queues both addresses (`speaker_merged`). Two records with different addresses are
  two HubSpot contacts; merging those is for LAW to do in HubSpot, and the module copes with
  whichever ID then disappears (`law_hubspot_forget_id()`).
- **Bookings for someone else** are caught cleanly because the attendee is still the booking's
  `post_author` (BOOK_ON_BEHALF.md D5): `law_booking_attendee_email()` and
  `law_hubspot_user_bookings()` find them, the booker (`_law_booked_by`) is correctly not
  queued, and a new attendee's account goes through `wp_insert_user` (`user_register`) plus
  `update_user_meta` for organisation and job title (watched).

Tests added to `HubSpotHooksTest`: trashing a speaker (the trashed and the remaining speaker
both queued), merging two speakers (identity carried over, both queued; and with different
addresses, the kept address untouched and the ID left behind), booking for a new person (account
queued, then the attendee on confirmation, never the booker) and for an existing account
(nothing on match, per A3; the attendee on confirmation). Full suite: the same 37 failures with
and without these changes, all in Stripe/email/webhook classes that depend on the local
environment; every HubSpot and speaker test green.

**`wp law hubspot remove-option`** added (`sync.php`: `law_hubspot_remove_option_plan()` /
`_apply()`; `cli.php`). Emily's 13 October decision: "2026 Attendee" should mean "has booked".
The sync is add-only so cannot take the tag away itself; this command does it once, before the
backfill, and the backfill re-adds it for everyone with a confirmed booking. It searches the
portal for contacts holding the value (search API, operator `IN`, re-checked for the exact
token so a near-miss is never edited), lists them with the property before and after, and
writes nothing unless `--apply`. `--unless-booked` leaves the value on anyone whose account has
a confirmed booking (`law_hubspot_user_bookings()`), so nobody who has booked loses it even
briefly. Writes go by record ID through the same client as the sync (dry mode logs, live
writes), 100 at a time, whole-batch failures retried singly and 207 errors attributed to the
contact named. One log line per contact, action `remove_option`, before and after, result
`dry` / `ok` / `error`. Tests in `HubSpotSyncTest` (plan with and without the flag, apply,
dry mode, partial failure). The planned order for go-live:

```
wp law hubspot remove-option contact_type "2026 Attendee" --unless-booked          # review the list
wp law hubspot remove-option contact_type "2026 Attendee" --unless-booked --apply  # live mode
wp law hubspot backfill
```

### Phase 3 (built 12 November 2026; live on production from 16 November 2026)

The badging decisions (Emily, 4 November; Peter's sign-off on dietary and access data) turned
the §5.2 design into the year-prefixed set and this build. Everything runs and is tested in
dry mode; **no `law26_` property has been created in the live portal**, because Kitty (Event
Connections) has to confirm the internal names first and HubSpot cannot rename a property.

**Ticket types** (`functions/events/statuses.php`): Press and Patron added, Exhibitor retired.
No booking on the local database held Exhibitor (checked before retiring; production to be
checked the same way before deploy: `SELECT COUNT(*) FROM wp_postmeta WHERE
meta_key='_law_ticket_type' AND meta_value='exhibitor'`). A retired type keeps its label
(`law_booking_retired_ticket_types()`), so a booking classified before the change still reads
"Exhibitor" in the lists and exports; the registered sanitiser refuses it on every write path,
so nothing new can take it.

**Press reconciled.** `_law_is_press` (the committee-issued complimentary pass) and the Press
ticket type are kept in step both ways: `law_booking_set_ticket_type()` raises the flag on
Press and lowers it when the type moves off Press; `law_booking_write_attendee()` and the
flagship add-attendee path set the type to `press` when a pass is issued and the type is blank
(an existing type is respected), and clear a `press` type when the pass is withdrawn. The rules
read either as Press, for the pass type and the `2026 Press` tag.

**Receptions classified.** `law_flagship_set_ticket_type()` became `law_booking_set_ticket_type()`
(the old name is a wrapper) and accepts flagship **and reception** bookings; the handler,
`law_flagship_require_committee_booking()` and the wp-admin meta box follow. The reception
bookings list (`parts/events/booking-list.php`) gained a committee-only "Ticket type" column
using the flagship dashboard's own cell, dialog and script, editable on the active table and
read-only on the waitlist and cancelled tables; blank by default. The no-JS form returns to the
list with a saved notice. Hosted bookings are still refused.

**Config** (`config.php`): `law_hubspot_year_prefix()`; the `properties` map with the `law26_`
names; `owned_by_law` (`badge_manual`); `superseded_properties`; `passtype_values`;
`passtype_precedence`; `dietary_icon_map`; `dietary_icon_precedence`; `dietary_values`;
`access_values` (the last two derived from the site's own choice lists).

**Properties** (`properties.php`): definitions for the seven new properties (labels carry the
year); the check reports superseded properties under `superseded` without failing;
`law_hubspot_properties_archive()` (DELETE, gated by dry mode, never run by the check or create);
the property fetch remembers which site-owned names the portal has
(`law_hubspot_portal_properties` option) so the sync's read-back can skip the ones not created
yet — a batch read that names a missing property fails whole.

**Rules** (`rules.php`): `law_hubspot_site_properties()` and the helpers
`law_hubspot_passtype_from_types()`, `law_hubspot_dietary_icon()`,
`law_hubspot_attending_option_for_event()`, `law_hubspot_event_year()`; the desired state
carries `site_properties`. Badging values come from this year's confirmed LAW-run bookings
only; dietary and access through `law_booking_profile_values()` (account first, booking's
stored answers as fallback), union across places.

**Sync** (`sync.php`): `law_hubspot_site_property_writes()` — differences only, this year's
`law_events_attending` options replaced and other years' kept, pass type never blanked,
`badge_manual` = true freezes pass type and icon, LAW-owned never written, nought not written
over a blank count. Plans carry `site_writes`; the preview CSV gained `events_attending`,
`passtype`, `dietary_icon`, `dietary`, `dietary_other`, `access`, `access_other`,
`badge_manual`, `hosted_count`, `hosted_titles`, `event_sectors`, `site_writes`.

**Hooks** (`hooks.php`): user meta `dietary`, `dietary_other`, `accessibility`,
`accessibility_other` (added, updated, deleted; not during `wp_delete_user()`); booking meta
`_law_ticket_type`, `_law_is_press`, `_law_attendee_profile` (added, updated, deleted);
`delete_post_meta` on `_law_speakers` reading the old rows; `before_delete_post` on a speaker
at priority 5. The deletion items from the earlier to-do are done.

**CLI and admin**: `check-properties --archive` (own confirmation, `--live` to send from a dry
site); the admin screen lists superseded properties with an "Archive superseded properties"
button (confirm dialog). Neither has been run against the live portal.

Tests: `HubSpotRulesTest` (precedence, config-edit precedence, press flag and type, flag kept
in step, reception vs hosted setter, icon folding and allergy-first, LAW-run-only disclosure,
booking fallback, attending/hosted/sectors, reception slug), `HubSpotSyncTest` (differences
only, never-blank and `badge_manual`, missing portal properties neither read nor written,
preview columns), `HubSpotPropertiesTest` (definitions, year prefix, superseded report and
archive, dry archive, archive refuses a live name, portal property memory), `HubSpotHooksTest`
(dietary edits including the last box, booking meta, last speaker row, permanent delete),
`FlagshipBookingsDashboardTest` (retired type still reads). The same three environment
failures as before (`BookingEmailsTest`, `BookingsDashboardTest`, `ReceptionWaitlistTest`)
with and without these changes; every HubSpot, flagship, reception and booking test green.

**Dry preview against the real portal, 12 November 2026** (local database, reads only,
`wp law hubspot preview --out=…`): the property check first (group present; the five
un-prefixed properties present; the seven `law26_` ones absent, as intended; the three
superseded ones still present; every Contact type and legal-basis value present). Then 1,720
addresses: 793 in HubSpot, 927 new. The plan read back only the properties the portal has, so
no `law26_` value was in any write; the site writes were `law_events_registered_count` and
`law_events_registered` (871 people), `law_event_sectors` (869) and `law_events_attending`
(142: Monday and Wednesday reception places, no flagship places on the local copy). The rules'
own columns were filled regardless: 32 people with dietary choices, 6 with other text, 4 with
access needs, 1 with other text — all holders of a LAW-run place this year; icons
`Vegetarian or vegan` 22, `Allergy or intolerance` 8, `Pescatarian` 2. Pass type was empty for
everyone, correctly: no local booking carries a ticket type yet, and an unclassified booking
sends nothing.

### Go-live (16 November 2026)

Production had never been configured: `wp law hubspot status` showed mode off, no token, worker
never run, and no contact in the portal carried `law_sync_updated`. In order, on Kinsta:

1. `LAW_HUBSPOT_TOKEN` and `LAW_HUBSPOT_MODE=dry` added to production's `wp-config.php`.
   Status: token check ok, 4,161 people known (the local copy knew 1,720 — a stale copy, which
   is why the §14.1 rail matters). `check-properties` green: both groups, all twelve site-owned
   properties, every Contact type and legal-basis value.
2. Exhibitor check: no booking carried the retired type.
3. `preview --out=/tmp/hubspot-preview.csv`: 4,161 people, 1,241 already in HubSpot, 2,920 new
   contacts; 3,068 would gain 2026 Attendee, 559 Monday reception, 417 Wednesday, 4 Friday, 494
   Speaker, 50 Event Host, 99 Event contact, 29 Sponsor. Trevor reviewed the CSV.
4. Make: the four LAW scenarios (two HubSpot, Stripe A and B) exported as blueprints and trashed;
   the Stripe webhook endpoint that pointed at Make disabled.
5. `LAW_HUBSPOT_MODE=live`. `remove-option contact_type "2026 Attendee" --unless-booked`
   reviewed then `--apply`. `backfill`: 4,161 queued and processed in the same command, 100 a
   batch: **4,154 succeeded, 7 failed**. All seven are `INVALID_EMAIL` from HubSpot — a
   mistyped domain ending on the site (`.con` ×5, `.fom`, `.com.brbr`), so HubSpot will not
   create the contact. They park immediately on `INVALID_EMAIL` from 19 November 2026
   (`law_hubspot_queue_error_is_permanent()`). Emily to correct the addresses on the site
   (user profile); the change requeues the person and the next sync creates the contact.
6. Trevor created the "LAW 2026 badging" active segment (*LAW-run events* is any of the four
   2026 values): 954 people. Spot-check of Sophie Nappert's record: pass type Speaker (the
   committee had already set flagship ticket types on production), attending Flagship +
   Wednesday reception, synced 06:35. Alison Macdonald KC shows the full set (Vegetarian →
   icon, Wheelchair, Speaker).
7. **A fourth reception.** Two contacts with access needs had no *LAW-run events* value. Cause:
   production (and, it turned out, the local copy) has a fourth reception, **Evensong at St
   Paul's Cathedral** (ID 1132, slug `evensong-at-st-pauls-cathedral`), and the attending
   options were a fixed list of three slugs. The badging data treated it as LAW-run (so access
   needs were sent) while the attending property did not — the two halves of "LAW-run"
   disagreed. Fix, same day: `law_hubspot_attending_options()` derives the options from the
   year's receptions (§5.2), the fixed labels kept for the seeded three. On production:
   `check-properties --create` adds the `2026 Evensong at St Paul’s Cathedral` option, then
   the Evensong attendees are re-queued. Whether Evensong is a badging event is Emily's to say;
   if not, the segment filter simply leaves that option out — the property should still carry
   it, and the disclosure of dietary/access for a LAW-run reception stands under Peter's
   sign-off.
8. **Evening only** (19 November 2026). Milly (directors, 18 November) asked for a badge
   colour for guests only attending evening events; Kitty can wire it if HubSpot holds that
   pass type. Added as a seventh ticket / pass type; the sync derives it for reception-only
   guests without a named role. On production after deploy: `check-properties --create` adds
   the option, then `backfill` (differences only) writes Evening only onto the reception-only
   segment.

### Property list for Event Connections (Kitty)

Send as-is. Match on **HubSpot contact ID**. Master list = active segment **"LAW 2026 badging"**.

| Internal name | Label | Type | Values / notes |
|---|---|---|---|
| `law26_ec_passtype` | 2026 pass type (badge) | dropdown | **Delegate, Press, Sponsor, Committee, Speaker, Patron, Evening only.** Single value. Site applies precedence; Reception-only without a named role → Evening only; Flagship + evenings → Delegate (or winning role). |
| `law26_dietary_icon` | 2026 dietary icon (badge) | dropdown | **Allergy or intolerance, Vegetarian or vegan, Pescatarian.** One icon per person (allergy wins). |
| `law_events_attending` | LAW-run events | multi-checkbox | `2026 Flagship`, `2026 Monday reception`, `2026 Wednesday reception`, `2026 Friday reception` only (not Evensong). |
| `law26_dietary` | 2026 dietary requirements | multi-checkbox | Detail behind the icon. |
| `law26_dietary_other` | 2026 dietary other | text | Free text. |
| `law26_access` | 2026 accessibility requirements | multi-checkbox | |
| `law26_access_other` | 2026 accessibility other | text | Free text. |
| `law26_badge_manual` | 2026 badge set by hand | yes/no | LAW-owned; site never writes. While Yes, sync leaves pass type and dietary icon alone. |
| `law26_ec_arrived` | 2026 badge first printed | datetime | LAW-owned; Event Connections write first badge print. |
| `firstname`, `lastname`, `company`, `jobtitle` | standard HubSpot | | Kept up to date by the website. Fix name typos on the **website** — HubSpot edits are overwritten on the next sync when the site has a value. |

### Phase 3 to-do

Approved 13 October 2026 (16 hours). What is left after the 12–19 November build and go-live:

1. ~~Internal names / properties / backfill / Evening only / arrived / Evensong decline~~ done.
   Later: `--archive --live` for the three superseded properties (ask first).
2. **Production after this deploy:** `check-properties --create` (`law26_ec_arrived`) if needed;
   `clear-stale-evening-only` then `--apply` (Evensong / no badged place stuck with Evening only —
   backfill cannot blank pass type).
3. **Hand Kitty the property pack** (table above) and book the Matt authorisation call.
4. Plain-English `badge_manual` note for Emily (she asked for a re-explain).
5. Privacy notice update if Peter wants one (§7.3).
6. Flagship pass types still mostly blank until the committee sets them by hand (no auto-
   Delegate — Emily, 16 November). Evening only does not need the pencil.

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
