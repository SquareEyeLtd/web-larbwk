# Booking a paid place for someone else

Spec for the LAW events module, written 26 October 2026 for a builder who has not
seen the planning conversation. It has been through UX, design-reuse and
backend/payments reviews, whose findings are folded in. Line numbers are pointers
into the tree as it stood that day, not contracts: confirm every one before editing.

---

## 0. Context

### 0.1 The ask (Denis, 26 October 2026)

A secretary or PA books and pays for a paid place for their boss, and keeps managing
it. Today the two paid kinds of event are strictly self-service: the flagship
conference ("always themselves", `functions/events/flagship-bookings.php:199-200`)
and the receptions ("one place per checkout, self only", RECEPTIONS.md §0.2). Denis is
reopening both, for paid events only.

### 0.2 Settled decisions (do not reopen)

| # | Decision |
|---|---|
| D1 | Scope: the flagship application, and the reception checkout and paid waitlist. Not hosted events (they already take colleagues), not invitation-only receptions, not the committee's own "Register an attendee" / "Add an attendee" routes. |
| D2 | A "Myself / Someone else" radio. Nothing else (no "both"). |
| D3 | Someone can book only for others and hold no place themselves. |
| D4 | The attendee gets an account, matched by email or created as a subscriber exactly as hosted colleagues are, and is emailed an invitation with a set-password link. Signing in is optional. Email required. |
| D5 | The attendee is the booking's `post_author` (as for every booking today); the booker is `_law_booked_by`, pays, and manages the place. |
| D6 | One place per checkout or application still holds. Two bosses means two bookings. |
| D7 (21 Sep) | A flagship substitute receives the standard `user_flagship_approved`, with any set-password link inside it. Money never moves on substitution. |
| D8 (17 Sep) | The committee cancelling a confirmed flagship place sends the delegate no email. |
| D9 (26 Oct) | Free receptions take "Someone else" too (Denis: "It should be for free receptions as well!"), widening D1. One booker holds at most three places for others at a free reception, the hosted colleague cap. |

### 0.3 Decisions Denis confirmed on 26 October 2026 (do not reopen)

| # | Decision | Why |
|---|---|---|
| A1 | Attendee details: full name, email, organisation, job title, country (required); accessibility and dietary with "Other" boxes (optional). "Full name", as on every on-behalf form in the theme. | House rule: on-behalf forms collect almost what registration collects, because dashboards and exports read country, accessibility and dietary per attendee. |
| A2 | **No** permission tick (Denis declined it). | |
| A3 | The details typed for someone else are **kept on the booking**, and written to the attendee's account **only if this booking created it**. An existing account is never written to. Dashboards read the account's own answers, falling back to the booking's. | Otherwise any member could write health-adjacent answers into a stranger's profile by typing their email (backend review). |
| A4 | Payment actions (continue to payment, update card, retry) are the booker's only. The attendee sees them **disabled**, labelled "John Doe manages payment for this place". | The card and the VAT invoice are the booker's; house rule: disable, don't hide. |
| A5 | Withdrawing a pending flagship application and leaving a reception waitlist are open to **both** booker and attendee. Cancelling a paid place stays "contact us" for both, as today. | It is the attendee's place; the money rules are unchanged. |
| A6 | Buying a reception for someone whose confirmed flagship place already includes it is refused, pointing at the free route. | Otherwise the booker pays for something the attendee already has. |
| A7 | If a booker's account is deleted, their **unpaid** on-behalf bookings (applied, waitlisted, pending payment) are cancelled and the attendee told; paid places are untouched (Denis: "only unpaid bookings"). | Otherwise approval would charge a deleted person's saved card. |
| A8 | The refusal for an attendee who already has a place is worded generically: "We can't register this person for this event. If they already have a place, they'll find it under My bookings." | The specific wording lets any member probe who has applied to the flagship. |

---

## 1. House rules for the builder

RECEPTIONS.md §0.5 and ROLES_AND_ACCOUNT_HUB.md §3 apply in full. The ones that bite:

- UK English, sentence case, no em dashes in any copy.
- Never `git stash`, `checkout <file>`, `reset`, `clean` or `add -A`: the tree is shared.
- Every mutation logs to the event's activity log with a `source`. Never mention the
  activity log in UI copy.
- AJAX modal pattern: `admin_post_*` + `nopriv` twin, `law_events_guard_post()`,
  `law_ajax=1` JSON, and a working no-JS path. A failed save shows in the modal and
  inline. Disable controls rather than hide them on read-only surfaces.
- One generic message for an unusable discount code. No payment step when the amount
  is zero. Every LAW event action button says "Register".
- One email template per message, never near-duplicates. Client-edited templates are
  stored whole (`law_events_email_overrides`, `notifications.php:970-985`), so
  anything every recipient must see is added by the sender, not by a template
  placeholder.
- Stripe: no new webhook event types are expected; if that changes, tell Denis.
- Local email: Mailpit. Browser QA: the test-specialist agent, only when Denis asks.
- Tests: `php -d memory_limit=512M vendor/bin/phpunit`, one run at a time. Nine
  `FlagshipPaymentsTest` failures exist before this work (the flagship price is read
  from "now" and the late price took over); they are not regressions.
- Update EVENTS_FUNC.md in the same piece of work.

---

## 2. What the code does today

### 2.1 The booking model

- `law_booking` post: event = `post_parent`, **attendee = `post_author`**, snapshot
  `_law_attendee_name/_email/_organisation/_job_title`, and `_law_booked_by`
  (`bookings.php:1-21`, schema in `meta.php`).
- `law_booking_insert( $event_id, $user_id, $status, $person, $meta )`
  (`bookings.php:2073-2101`) always writes `_law_booked_by = $user_id`.
- `_law_booked_by` equals the attendee on every flagship and reception booking today,
  **except a substituted flagship place**, where substitution overwrites it with the
  new holder (`flagship-bookings.php:2243`, pinned by `FlagshipSubstituteTest:369`) and
  the real payer is `_law_substituted_from` (`:2203-2217`).
- My bookings already lists bookings a user made for others
  (`law_user_bookings_made_ids()`, `bookings.php:433`), and `booking-manage.php:26-32`
  admits the author or the `_law_booked_by` user. But My bookings shows **one card per
  event with one Manage link** (`account-bookings.php:181-193`), and the reception
  manage view derives "paid", payment facts and Continue from one booking for the
  whole party (`booking-manage.php:53-57, 192`).

### 2.2 Reusable machinery

| Helper | Where | Note |
|---|---|---|
| `law_booking_resolve_attendee_user( $row, $event_id, $actor )` | `bookings.php:1842-1883` | Match by email or create a subscriber via `law_events_create_host_user()` (`co-owners.php:222-258`, which already splits a full name into first and last). No role check on a match. |
| `law_booking_clean_additional_rows()` | `bookings.php:1768-1808` | Cleans and requires name, email, organisation, job title; errors carry `row` and `field`. |
| `law_booking_delete_created_users()` | `bookings.php` (~2734) | **Deletes unconditionally**; the `deleted_user` hook (`:4150-4160`) then cancels that user's bookings under `account_deleted`, which skips the paid guard. See §3.3. |
| `law_booking_write_attendee()` | `bookings.php:708-720` | Single writer of the snapshot and `_law_booked_by`. |
| `law_booking_person_from_profile( $id )` | `bookings.php:2038` | Builds the snapshot from a profile. |
| `law_booking_lock()` | `bookings.php:1816-1824` | `GET_LOCK`, does not nest. Create accounts before it. |
| Profile clean / validate / apply | `registration.php:260-363` | Apply fills blanks only and skips accounts above subscriber. |
| `law_events_password_setup_link()`, `law_booking_account_note()` | `co-owners.php:272-289`, `bookings.php:1901-1916` | Set-password link and the `{account_note}` paragraph. |
| `law_booking_invited_by_label()` | `bookings.php:606-613` | Already feeds an "Invited by" column in the per-event and Manage bookings exports and row tags (`bookings.php:4013, 4036`; `bookings-dashboard.php:390`; `booking-list.php:207, 256`). "Invited by %s" is built in six places. |
| `user_booking_registered` | `notifications.php:292-299` | The "booked for you" template: confirmed place, `{invited_by}`, `{account_note}`, calendar invite attached. |
| Substitution's payment-tag blanking | `flagship-bookings.php:2390` | The list of payment placeholders blanked for a holder who did not pay. |
| Payer note copy | `booking-payment-facts.php:203-223` and `flagship-manage-application.php:282-297` | Two verbatim copies of the same text. |

The attendee identity fields (name, email, organisation, job title) are hand-written
in five places: `booking-list.php:107-125`, `flagship-add-attendee.php:58-87`,
`flagship-substitute.php:90-119`, `booking-manage.php:252-258`,
`attendee-repeater.php:26-31`. The "Whatever they told you" note appears three times.

### 2.3 Where the paid flows assume the attendee is the payer

**Stripe** (`stripe/attendees.php`): customer from `post_author` in the setup session
(`:153`), the checkout session (`:277`, with `customer_update` name and address
`auto`), and the charge (`:811-815`, which also overwrites the booking's customer);
metadata `law_user_id = post_author` (`:87-101`, read by nothing); the invoice line
description names only the event and date (`law_stripe_booking_line_description()`,
`:953`).

**Permissions, author-only today**: `law_booking_require_own_priced_booking()`
(`bookings.php:903-918`), `law_flagship_require_own_booking()`
(`flagship-bookings.php:2645-2660`), `law_reception_require_own_booking()`
(`receptions.php:2475-2490`), `flagship-manage-application.php:24-29`, return handlers
(`receptions.php:2774`, `flagship-bookings.php:3312, 3335`). Meanwhile
`law_booking_cancel_handler()` and the cancel-all handler (`bookings.php:3681-3760`)
already accept the booker for **any** booking, flagship included, which would bypass
`law_flagship_withdraw()` (no invoice void, hosted email).

**Information exposure**: `flagship-manage-application.php:60-67, 350-365` prints the
holder's live organisation, job title, country, dietary and access needs.

**Emails**: every paid template greets `{attendee_name}` and goes to the attendee
snapshot via `law_booking_email_extra()['to']` (`bookings.php:2322`); committee emails
reuse those placeholders (`flagship-bookings.php:665`, `receptions.php:1277`). Other
paths compute recipients themselves: `law_booking_cancel()` (`bookings.php:3246-3268`,
including waitlist leave/remove and the sweep's payment-expiry cancellation,
`receptions.php:2959-2966`), `law_waitlist_mark_blocked()` (`waitlist.php:560-578`,
asks the attendee for a payment method), substitution (`flagship-bookings.php:2381`).
The body renderer `law_events_email_render_body()` (`notifications.php:1156-1171`)
appends the sign-off, swaps placeholders in one escaped pass, autop-and-allowlists,
`make_clickable`s, and sends HTML.

**Engines**: `law_flagship_apply()` (`flagship-bookings.php:204-459`),
`law_reception_checkout()` (`receptions.php:749-957`) and
`law_reception_waitlist_join()` (`:2022-2224`) take the gap check, snapshot and insert
from the signed-in user. On a Stripe failure the flagship application survives and is
closed silently by the 48-hour sweep (`:452-456`, `:3404-3425`); the reception hold is
released (`receptions.php:948-952`).

**Dialogs**: `flagship-apply-modal.php` and `reception-checkout-modal.php`, fetched
with `?law_dialog=1`, with inline no-JS twins (`?law_flagship_apply=1`,
`?law_reception_checkout=1`, `?law_reception_waitlist=1`). The inline twins render on
the navy `.law-booking-panel` (`calendar.css:2623-2640`), whose override
(`:2694-2702`) recolours only copy and notes, so labels and legends are probably navy
on navy (read from the CSS, not rendered). The event panel shows a place holder only
Manage (`account-bookings.php:904-918`); the flagship's action state reads only the
viewer's own application (`account-flagship.php:208`).

**Front-end bugs this work depends on**
- `[data-law-toggles]` (event-form.js:157-182) is bound once at page load, clears only
  the first input, looks for radio siblings across the whole document, and event-form.js
  is not loaded on the event page, programme or flagship page. The "Other" boxes do not
  toggle in fetched dialogs today.
- `markFlatField()` (booking-form.js:434-440) matches by exact `name`, so server
  errors `law_consent`, `law_terms`, `law_discount_code` never mark their inputs,
  although every one already carries `data-law-field`.

**Separate bug, fix alongside in its own commit**: the committee's "Add an attendee
without payment" promises a set-password link `user_flagship_complimentary` never
carries (`notifications.php:588-595`, `flagship-bookings.php:1712-1716`).

---

## 3. Design

### 3.1 Booker, payer, attendee

Three helpers in `bookings.php`, beside the party helpers:

```php
/** Who booked and manages this place: _law_booked_by, else the attendee. */
function law_booking_booker_id( $booking ): int
/** Who paid: _law_substituted_from (first holder of a substituted place), else the booker. */
function law_booking_payer_id( $booking ): int
/** The viewer's relation: 'attendee', 'booker' (booked for someone else), or ''. */
function law_booking_user_role( $booking, $user_id ): string
```

- `law_booking_is_on_behalf()` = booker differs from attendee.
- **Management** follows the booker and the attendee (§3.6). **Money** (Stripe
  customer, payment facts, receipt notes) follows the payer.
- Existing data needs no migration: on every paid booking today the booker is the
  attendee, and a substituted place keeps its `_law_substituted_from` payer.
- **Substitution change** (§3.9): it writes `_law_substituted_from =
  law_booking_payer_id()` read **before** the swap (not the outgoing holder), and on an
  on-behalf place it leaves `_law_booked_by` as the booker.

No paid flow compares `post_author` with `get_current_user_id()` after this work.

### 3.2 The form

**One shared part for the attendee details.** Extract
`parts/events/attendee-details-fields.php`: the identity grid (full name, email,
organisation, job title, labels wrapping inputs) plus the existing
`attendee-profile-fields.php` call. Args: `name_pattern` (default
`law_attendees[0][%s]`), `org_required`, `values`, `invalid`, `id_prefix`,
`show_other`, `note`. Replace the hand-written copies in `booking-list.php`,
`flagship-add-attendee.php` and `flagship-substitute.php` with it (one copy of the
"Whatever they told you" note too). Keeping `law_attendees[0][…]` lets the server use
`law_booking_clean_additional_rows()` and lets booking-form.js `markField()` mark
row-keyed errors with no new code.

**The new part** `parts/events/booking-for-someone.php`, used by both dialogs, sits
**directly after the event summary**:

```
fieldset  Who is this place for?
  (•) Myself
  ( ) Someone else
fieldset  Their details                     [revealed for Someone else]
  attendee-details-fields (+ country, accessibility, dietary)
  We'll email them an invitation to see their place.
```

- Two sibling fieldsets (`.law-booking-fieldset` with `.law-choices`), not one inside
  the other, so no field is announced under two legends. Details follow the radio in
  tab order; focus does not move when they appear.
- **When the viewer already holds a place** on the event: "Myself" is rendered
  **disabled** with "You already have a place", and "Someone else" is preselected.
- POST: `law_for` = `self|other`, `law_attendees[0][name|email|organisation|job_title]`,
  and the profile partial's flat names.
- No JavaScript: the details render visible with "Only needed if you chose Someone
  else"; the server ignores them for `self`. A refused no-JS submission keeps the
  radio and everything typed (form state for both dialogs, mirroring
  `law_booking_store_form_state()`; today the reception form keeps only the message,
  `receptions.php:3122`).
- **Copy is neutral, not doubled.** Rewrite the first-person lines in
  `flagship-apply-modal.php:165, 190, 291, 300, 349` and
  `reception-checkout-modal.php:220, 283` so they read right for either choice ("if
  the registration is approved", "the place"). The submit labels do not change with
  the radio (they already change with the discount quote). The consent stays "my
  payment details": the booker pays either way.
- Accessibility choice labels written in the first person ("I require wheelchair
  access", `registration.php:113-116`) are relabelled neutrally ("Wheelchair access");
  stored values unchanged. Check against the legacy Gravity Forms labels first
  (house rule: GF parity), and ask Denis if they differ.
- Flagship "Included receptions": the disabled-and-ticked "already held" states
  describe the viewer; JavaScript clears them when Someone else is chosen, and the
  server's grant skips any reception the attendee already holds.
- The flagship "incomplete profile" gate still applies to the booker.
- Signed-out copy: "to register, for yourself or someone else".

### 3.3 One seam for every paid booking

Instead of threading new parameters through three engines, the **handler** resolves
one record and the engines take it:

```php
/** Read, validate and resolve who a paid booking is for. */
function law_booking_paid_party_from_request( int $booker_id, int $event_id, array $post ): array|WP_Error
// [ 'booker_id', 'attendee_id', 'person' (snapshot), 'profile' (typed answers),
//   'for' => 'self'|'other', 'created' => bool ]
```

- `self`: attendee = booker, person = `law_booking_person_from_profile( $booker )`.
  Behaviour byte-identical to today.
- `other`: validate with `law_booking_clean_additional_rows()` (one row) and the
  profile clean/validate (country required). Errors come back one at a time,
  each with `field` (and `row` 0), like every other form here (see §8). Refuse
  the booker's own email.
- **Before creating any account**, run the duplicate guard **by email** on the event
  (as register-by-manager already does, `bookings.php:2858-2866`), with the generic
  wording of A8 re-wrapped to carry `field => email`.
- Then `law_booking_resolve_attendee_user()`; new accounts get their names from the
  full name through `law_events_create_host_user()`.
- **Rollback rule**: one wrapper around the engine call deletes accounts this request
  created **only if that user authors no booking at all**. Never call
  `law_booking_delete_created_users()` on a user who has one (the race in §2.2 would
  otherwise cancel a live booking another request made).
- After a booking exists: store the typed answers on it as `_law_attendee_profile`
  (new meta, `answer_map`-style: country, accessibility, accessibility_other,
  dietary, dietary_other), and apply them to the account **only if `created`** (A3).
- Mark a created account `_law_invitation_pending` (user meta), cleared when the
  person sets their password (`after_password_reset` / the theme's set-password
  handler). `{account_note}` carries the set-password link while it is pending,
  whichever request sends the email (§3.7).

### 3.4 Engines

`law_flagship_apply()`, `law_reception_checkout()` and `law_reception_waitlist_join()`
take the party record instead of a bare user ID:

- `law_booking_insert()` gains `$booked_by` (default: the attendee) so the snapshot and
  `_law_booked_by` are written once.
- Guards run on the attendee (the duplicate guard already matches author ID and
  email). The flagship's one application per person becomes "per attendee", so a
  booker can hold their own application and register any number of others.
- Reception only: refuse when the attendee's confirmed flagship place includes this
  reception (A6), with "This person's flagship place already includes this reception.
  They can add it free from their bookings."
- Quote, price-shown guard, discount claim and lock are unchanged (codes are not per
  user).
- Log `booked_on_behalf` on the event: "Jane Smith's place booked by John Doe.",
  context `booking`, `booker`, `attendee`, `source`.
- Stripe page failure: a reception releases the hold (as today) and the wrapper rolls
  back an account created for it (§3.3 rule). A flagship application survives as
  today; the attendee has not been emailed yet (§3.7), so the silent 48-hour close
  stays silent for them.

### 3.5 Stripe

- Setup session, checkout session and charge resolve the customer from
  `law_booking_payer_id()` (`attendees.php:153, 277, 811`). For a new on-behalf
  booking that is the booker, so the card, the default payment method on the customer
  and the VAT invoice are all the booker's.
- `law_stripe_booking_metadata()`: `law_user_id` = payer; add `law_attendee_user_id`.
- `law_stripe_booking_line_description()` adds "for Jane Smith" when on-behalf, so a PA
  paying for two partners gets two distinguishable invoices and a last chance on
  Stripe's page to notice the wrong person.
- Return handlers accept the booker (`law_booking_user_role() === 'booker'` or the
  attendee on a self booking).
- No new webhook event types. Risk to test with the Stripe CLI (inference): if one
  payment method were ever shared by two bookings, detaching it on one cancellation
  would break the other; a Checkout session per booking should prevent that.

### 3.6 Permissions

| Action | Booker (on-behalf) | Attendee (on-behalf) | Self booking |
|---|---|---|---|
| See the place (My bookings, manage views) | yes, **snapshot only** | yes, live profile | yes |
| Continue to payment, update card, retry | yes | shown **disabled**: "John Doe manages payment for this place" | yes |
| Withdraw a pending flagship application (through `law_flagship_withdraw()`) | yes | yes | yes |
| Leave a reception waitlist | yes | yes | yes |
| Cancel a paid place | "contact us" | "contact us" | "contact us" |
| See card, invoice, discount code | yes (payer) | no: "Booked and paid for by John Doe" | yes |

- Replace the author-only checks listed in §2.3 with `law_booking_user_role()`.
- `law_booking_cancel_handler()` and the cancel-all handler refuse flagship bookings
  (they must go through withdraw).
- The booker never sees the attendee's live profile: `flagship-manage-application.php`
  shows the booker the snapshot they typed; the live panel and "Update my profile" are
  the attendee's.
- One `law_booking_payer_note( $booking, $viewer )` replaces the two copies of the
  payer note (§2.2) and covers both substituted and on-behalf places. Fix
  "Replaced X, who holds the receipt" (`flagship-bookings-list.php:384`) to name the
  payer.

### 3.7 Emails

One send helper for every paid user email, `law_booking_send_user_email( $slug,
$booking, $tags, $args )`, used by the flagship, reception, waitlist, cancel, blocked
and substitution paths. It decides recipients:

**Self booking**: exactly as today.

**On-behalf booking, the booker's copy**: every email the booker would get today as
the person who paid. `{attendee_name}` is the **booker's** name (they are addressed),
no calendar attachment, and a **lead line added by the sender** (after the placeholder
swap, escaped, so a typed name such as `{invoice_link}` cannot expand):
"You booked this place for Jane Smith (jane@firm.com). We've sent them their own
confirmation." Where the email reports something the attendee did (withdrew, left the
waitlist), the lead says so: "Jane Smith withdrew this registration." The renderer
gains an optional `lead` argument for this.

**On-behalf booking, the attendee's emails** (no new template for confirmed places):

| Moment | Attendee receives | .ics | `{account_note}` |
|---|---|---|---|
| Flagship registration ready (card saved, or free) | new `user_booking_requested`: "{invited_by} has registered you for {event_title}. The LAW committee reviews every registration and we'll email you when it's decided." | no | if pending |
| Reception waitlist ready (card saved, or free) | `user_booking_requested`, waitlist wording via a sender-filled `{status_note}` | no | if pending |
| Reception confirmed (paid, free by code, promoted) | `user_booking_registered` (existing) | yes | if pending |
| Flagship approved | `user_flagship_approved` with payment tags blanked by the shared helper, `{payment_note}` = "Booked and paid for by John Doe.", as a substitute gets it (D7) | yes | if pending |
| Declined / rejected | `user_booking_rejected` | no | no |
| Withdrawn or cancelled by the booker | `user_booking_cancelled_by_booker` | no | no |
| Withdrawn by the attendee themselves | `user_booking_cancelled_self` | no | no |
| Included reception revoked | `user_reception_included_revoked` (already attendee-addressed) | no | no |
| Confirmed flagship place cancelled by the committee | nothing (D8) | | |

- `user_booking_requested` is the only new template: it covers the two "not yet
  confirmed" moments with one body and a `{status_note}`, registered on the Emails
  screen like every other. The builder reads every reused body and adjusts default
  wording where it would mislead, never forking a template.
- The attendee is emailed only once the booking is real (card saved or free), so an
  abandoned Stripe page never sends them anything.
- `law_waitlist_mark_blocked()` (asks for a payment method) goes to the booker.
- Committee emails name the booker when different ("booked by John Doe"); their
  placeholders are not touched.

### 3.8 Management surfaces

- **My bookings: one line per place.** An event with several of the viewer's places
  (their own and ones booked for others) lists each with name, booking number,
  status and its own Manage link; one card per on-behalf paid booking is acceptable
  if a list inside the card is not. Hosted-event colleague wording ("+1 colleague
  booked by you") is unchanged.
- **Reception manage view per booking**: paid flag, payment facts and Continue come
  from the addressed booking, not the party.
- **Event panel on paid events**: a place holder keeps a "Register" button beside
  Manage (it opens the dialog with Myself disabled, §3.2). A booker with no place of
  their own sees "You've booked a place for Jane Smith" with Manage, reusing the hosted
  line (`account-bookings.php:926-941`); the flagship action state learns to read
  bookings the viewer made for others.
- **Labels**: one helper decides the wording, "Invited by X" on hosted events and
  "Booked and paid for by X" on paid ones; the six hand-built "Invited by %s" strings
  call it. Keep the existing export header unless Denis wants it renamed
  (`BookingsTest:575` pins it).
- **Committee**: the per-event list, Manage bookings and their exports already carry
  "Invited by" and will show on-behalf reception bookings with no new code. Add a
  "Booked by" column to the flagship bookings dashboard export
  (`flagship-bookings-dashboard.php:215-237`, CSV/Excel/PDF through
  `functions/events/export.php`) and a sub-line under the name in its table, as
  "Replaced X" does (`flagship-bookings-list.php:364-393`).
- **Profile values on dashboards and exports** read through one helper,
  `law_booking_profile_values( $booking )`: the account's live answers, blanks filled
  from `_law_attendee_profile` (A3). Self bookings have no stored answers, so nothing
  changes for them.
- **Known limits**: the included-receptions banner stays keyed to the viewer's own
  flagship place, so a booker does not see it for their boss; the receptions granted
  to the boss sit under the boss's account and are not visible to the booker.

### 3.9 Substitution

- A self-booked place: unchanged. The first hop writes `_law_substituted_from*`
  (the outgoing holder, who paid) and `_law_booked_by` moves to the new holder
  (`FlagshipSubstituteTest:369` stays green).
- A place booked for someone else (as built): `_law_booked_by` stays the booker
  and **none** of the `_law_substituted_from*` keys are written, because the
  payer is already recorded (the booker), and writing the outgoing attendee
  there would name somebody who never paid.
- Emails follow D7 and §3.7: the new holder gets `user_flagship_approved` with
  payment tags blanked (pinned to them with `to_attendee`); the receipt note
  goes only to whoever `law_booking_payer_id()` names; on a place booked for
  someone else the booker also gets the transfer note, with the receipt.

### 3.10 Front-end utilities (consolidate, do not duplicate)

- **`assets/js/law-toggles.js`**, delegated on `document`, replacing the binding in
  event-form.js, the sponsor/invoice toggle (`event-form.js:147-155`) and the inline
  script in `account-profile.php:90-99`. It exposes `lawToggles.sync( root )`, called
  by booking-form.js after a fetched dialog is injected (`:202`, beside
  `initRepeaters`) and on form `reset` (fixes `flagship-substitute.js:56` leaving an
  "Other" box open). It **disables every control** in a hidden block (the server
  already treats missing fields as blank, `registration.php:267`), looks for radio
  siblings inside the form only, and keeps `data-law-toggle-for` hide-only
  (`account-dashboard.php:755` must keep posting). Leave `law-admin.js:245` alone.
- Register `law-toggles` in `law_modal_register_assets()` (`functions/modal.php:24`) as
  a dependency of `law-event-form` and `law-booking-form`. booking-form.js is enqueued
  by hand in eight places: add `law_booking_form_enqueue()` first and use it.
- **`markFlatField()`**: look up `[data-law-field="…"]` first, then `name`; set
  `aria-invalid`, and remove it in `clearErrors()`. `data-law-field` is already on the
  consent, terms and code inputs.
- **No-JS panels**: give `.law-booking-panel .law-booking-inline` a white card and drop
  the partial colour override, so the inline forms render like the dialog.
- The radio group is the first radio on a light surface on the front end: screenshot
  it on the event page, the programme and the flagship page (house rule: check every
  surface; form CSS is authored dark-first). The orange accent on white is about
  2.8:1, below 3:1; existing tick boxes share it, so raise it with the accessibility
  review rather than fixing it here.

---

## 4. Build order

Each phase ends with its tests green and a note in EVENTS_FUNC.md.

1. **Helpers and permissions, no UI.** §3.1, §3.6 (checks, cancel-handler refusal,
   payer note), §3.5. Run the whole existing suite: self bookings must be unchanged.
2. **The party seam and engines.** §3.3, §3.4: flagship, then checkout, then waitlist.
3. **Emails.** §3.7, including the renderer's `lead` and the new template.
4. **Front-end utilities.** §3.10 (toggles, enqueue helper, markFlatField, panel card).
5. **Dialogs.** The shared details part (and its three existing callers), then §3.2 in
   both dialogs and both contexts.
6. **Management surfaces.** §3.8.
7. **Substitution.** §3.9.
8. **A7** (deleted booker) and the complimentary set-password bug, each its own commit.

Gates (house rule): three conformance passes against this document; a security review
(account matching by a typed email, the A3 write rule, the permission changes, the
Stripe customer switch, the lead line's escaping); an accessibility review of both
dialogs; a ten-flow E2E pass by the test-specialist; then three parallel review agents.

---

## 5. Tests

New `tests/BookOnBehalfTest.php` on `LAW_Test_Case`, using the existing Stripe queue
helpers (`queue_setup_session()`, `queue_successful_charge()`,
`queue_checkout_session()`):

- **Self unchanged** for all three engines: author, `_law_booked_by`, customer, emails.
- **New attendee**: subscriber created with first and last name; profile written;
  `_law_attendee_profile` stored; author = attendee; `_law_booked_by` = booker;
  `_law_invitation_pending` set; `booked_on_behalf` logged.
- **Existing attendee**: matched; **no** profile write; answers on the booking only;
  dashboards show the account's answers with blanks from the booking.
- **Refusals**: booker's own email; duplicate (generic wording, `field` email); every
  required field at once; A6.
- **Rollback**: a refused booking deletes the account it created; an account that
  meanwhile authors a booking from another request is **not** deleted.
- **Flagship**: attendee already has an application → refused; booker can still apply
  for themselves; booker can register two different people.
- **Stripe**: setup, checkout and charge use the payer's customer; metadata; line
  description names the attendee.
- **Payer helper**: self, on-behalf, substituted self, substituted on-behalf.
- **Permissions** table §3.6 row by row for booker, attendee and a stranger; cancel
  handlers refuse flagship bookings.
- **Information**: the booker's flagship manage view shows the snapshot, never the live
  profile; the attendee never sees card, invoice or code.
- **Emails**: booker copy greeted by name, no .ics, lead line present including on a
  template overridden in `law_events_email_overrides`, and a name like
  `{invoice_link}` in the lead not expanded; attendee emails per the §3.7 table; the
  set-password link present while `_law_invitation_pending`, on a later request too.
- **Included receptions** chosen on an on-behalf application are granted to the
  attendee at confirmation.
- **Substitution**: payer recorded correctly; booker kept on on-behalf; receipt note to
  the payer.
- **A7**: deleting a booker cancels their unpaid on-behalf bookings and tells the
  attendee; paid ones untouched.
- **Dialog render**: the two fieldsets, Myself disabled for a holder, unique ids in
  modal and inline contexts, form state kept after a no-JS refusal.

Update rather than delete: `FlagshipPaymentsTest::test_the_application_form_collects_
no_personal_details` (true for Myself; rename and assert the Someone else block posts
under `law_attendees[0]`), `ReceptionCheckoutStripeTest:80` (still `cus_test` on a self
booking), `FlagshipSubstituteTest:369` (self case unchanged).

---

## 6. Manual acceptance (local, Mailpit, Stripe test mode with CLI forwarding)

1. Flagship, Myself: unchanged end to end.
2. Flagship, Someone else, new email: the booker's card is saved; the booker gets
   "registration received" addressed to them with the lead line; the attendee gets
   `user_booking_requested` with a set-password link.
3. Approve: booker charged, gets the invoice email; attendee gets the confirmation with
   the calendar invite and "Booked and paid for by John Doe"; chosen receptions appear
   on the attendee's bookings.
4. Booker's My bookings: a line "For Jane Smith" with its own Manage; manage view shows
   the typed details, not Jane's profile; update card works. Attendee signs in through
   the link: sees the place, payment button disabled with the booker's name, can
   withdraw while pending; the booker is told who withdrew.
5. The booker, already registered themselves, registers a second person from the
   event page (Register beside Manage; Myself disabled).
6. Reception checkout for someone else: Stripe shows the booker's customer and "for
   Jane Smith"; paying confirms; attendee gets one email with the calendar invite.
7. Reception waitlist for someone else: booker saves a card; attendee gets the
   waitlist note; on promotion the booker is charged and the attendee confirmed.
8. Free by 100% code, Someone else: no payment step.
9. Refusals each mark their fields and show in the modal; no-JS keeps everything typed.
10. Committee: flagship dashboard shows "Booked by", and all three export formats.

---

## 7. Risks and default resolutions

| Risk | Default |
|---|---|
| A booker types a stranger's existing email. | Allowed: they pay, and the stranger is emailed. Nothing is written to the stranger's account (A3). Security review confirms. |
| The duplicate refusal reveals who has applied. | Generic wording (A8) and the existing rate limit (10 per 10 minutes). Residual: a refusal itself still signals something; accepted. |
| Client-edited templates lack on-behalf wording. | The sender's lead line covers the booker; reused attendee templates are already attendee-addressed. |
| Existing bookings change behaviour. | They cannot: booker = attendee on every paid booking, and substituted places keep their recorded payer. Phase 1 proves it with the existing suites. |
| The navy panel diagnosis (§2.3) is wrong. | It was read from CSS, not rendered: screenshot before changing. |
| An abandoned reception checkout leaves an account the attendee never heard of. | Rolled back if it authors no booking; a cancelled hold is a booking, so check the rule handles it (delete only if every booking it authors is the released hold). |

---

## 8. Build notes (26 October 2026)

What was built differs from the text above in these deliberate ways:

- **No `required` attributes.** The house pattern is `aria-required` so that a
  refusal is announced in the modal (failed-save rule); a native browser
  bubble would bypass it. Errors therefore come back one at a time.
- **A6's refusal is conditional** ("If their flagship place includes it…"), like
  A8, so it never confirms that a named person holds a flagship place.
- **Substitution of an on-behalf place** writes no `_law_substituted_from*` keys
  (§3.9 as amended).
- **Toggles**: `data-law-toggles` keeps clearing the first input on change for
  existing uses; blocks marked `data-law-toggle-disable` disable their controls
  instead and keep what was typed.
- **Known limits**: a delegate whose confirmed flagship includes a reception sees
  only "Add" on that reception until they hold the place, then "Register" for
  someone else; the included-receptions banner stays the viewer's own.
- The full record is EVENTS_FUNC.md, "Booking a paid place for someone else".

### The review round (26 October 2026)

Folded in after three conformance passes and a security review; the full list
is in EVENTS_FUNC.md. The ones that change the design above:

- One unfinished booking for someone else per booker at a time.
- A reception that costs nothing was booked for oneself only (no choice
  shown). Superseded the same day by D9: see "Free receptions too" below.
  A 100% discount code still allows booking for someone else: its uses are
  limited by the code. Denis to confirm.
- An account the site created and nobody claimed is deleted when everything it
  holds was booked by this booker and has been cancelled (refusal, lapsed
  hold, abandoned registration).
- A6 answers with the A8 refusal, word for word.
- The attendee's emails use ONE template, `user_booking_on_behalf`, with a
  code-built opening paragraph (`{status_note}`) and an editable subject; the
  flagship approval is the exception (D7). This replaces §3.7's reuse of the
  hosted cancellation templates for the attendee, which addressed the wrong
  person and offered actions they could not take.
- Routing is done once inside `law_events_send()` (`law_booking_route_paid_email()`)
  rather than through a send helper at every call site.
- A7 tells the attendee only if they had been told about the booking at all.
- Substitution of an on-behalf place records the outgoing attendee's name and
  email but never the payer ID.
- The sponsor/invoice toggle in event-form.js was left where it is: it hides on
  a radio VALUE, which data-law-toggles does not express.
- Neutral accessibility labels apply to every on-behalf form, the three
  committee dialogs included; the registration form keeps its first-person
  wording (legacy Gravity Forms parity).

### The second review round (26 October 2026)

A designer, a DRY and scalability review, and a WCAG 2.2 AA audit; the full
list is in EVENTS_FUNC.md. The ones that change the design above:

- The label is "Booked by X" until money has been taken, then "Booked and paid
  for by X" (`law_booking_booked_by_label()`, `law_booking_is_paid_for()`), and
  the payer note likewise. §3.8's single wording is replaced.
- The "For X." line is the booker's only; the attendee learns who pays from the
  payment facts.
- The attendee of a place somebody else is paying for sees an inert "Continue
  to payment" with who is paying, and the flagship's own link reads "View my
  registration" for them.
- A refusal sits beside its field, tied to it with `aria-describedby`; light
  forms use #b35f00 for the accent and focus ring.
- A bug fixed: the no-JS choice state was stored under one shared key, so one
  person's typed details could refill another's form.

### Free receptions too (26 October 2026)

D9 reverses the review round's "free receptions are self-only":

- The dialog asks "Who is this place for?" on a free reception, and the event
  panel offers Register beside Manage there as on a priced one.
- `law_reception_on_behalf_free_cap()` replaces the refusal: at most
  `law_booking_max_additional()` (three) places for others per booker at a
  free reception, counting every holding state, checked before the seam
  creates an account and again in both engines. A priced reception has no cap.
- Wording for a place with nothing to pay: "Booked by X. There is nothing to
  pay." in the payment facts, and the committee's lead line says "booked by"
  rather than "booked and paid for by" until money has been taken.
- A7: an entry with nothing to pay (`no_charge`) is not unpaid, so it stays
  when the booker's account is deleted. This also covers a 100% code.
- The cap shows BEFORE anything is typed (Denis): once the booker has booked
  three others, the dialog shows Someone else disabled with "You've booked
  places for 3 other people here, which is the most allowed." (the `others_full`
  argument of booking-for-someone.php) and keeps the details closed. With their
  own place held as well, both choices and the submit are disabled, and the
  event panel shows Register disabled beside Manage, with the same sentence.
  The engines' refusal stays as the guard that holds.
