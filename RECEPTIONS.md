# Receptions: paid, invitation-only and included-with-flagship reception events

> **Status: specified and BUILT, 14 September 2026.** Everything below is in
> the working tree and the suite is green (601 tests). Read §16, "Deviations
> from this specification", before trusting a line reference or a name here:
> the code is the record now, `EVENTS_FUNC.md` is the living description of it,
> and this document is what was asked for. This document is the
> self-contained build contract, in the manner of `FLAGSHIP_PAYMENTS.md`,
> `FLAGSHIP_UI.md` and `WAITLIST.md`. An agent starting cold should be able to
> build everything below from this document plus the code. `EVENTS_FUNC.md` is
> the living record of the code as it stands and must be kept current as this
> lands (house rule).
>
> Produced from Denis's brief of 14 September 2026, `EVENTS_4.2_SPECS.md` §2.3,
> §3.4, §5.4, §6.1 and §7, three exploration passes over the working tree
> (flagship application and payment flow, booking control and My bookings,
> discounts and Stripe webhook plumbing) and three specialist review passes
> (UX, reuse and design, backend and payments) whose must-fix and should-fix
> findings are folded in. Reconciled on 14 September 2026 with
> `ROLES_AND_ACCOUNT_HUB.md`, which another agent is executing in this same
> tree and which lands first: **read §0.4 before anything else.** File and line
> references were taken before that work and will have drifted; treat them as
> pointers, not gospel.

## 0. Context and ground rules

### 0.1 What is being built, and why

LAW runs three drinks receptions during the week: Monday, Wednesday and
Friday. Monday and Wednesday are **paid, pay-now** events with no committee
review, and both are also **included free with a confirmed flagship place**.
Friday is **invitation-only**: LAW invites people itself, and the site shows it
for information and in the calendar with a notice where the booking button
would be. The discount catalogue built on 10 September 2026
(`functions/events/discounts.php`, deliberately unwired) is meant to work
exactly here: the code is typed in the checkout dialog, the total recalculates
in place, and the committee can scope a code to one reception. The committee
also needs a screen to manage the receptions' date, time, venue, price, places,
"included with the flagship place" and "invitation only".

The brief supersedes `EVENTS_4.2_SPECS.md` §2.3 and §6.1 where they differ:
there is **no** £25 flagship-only Monday price and **no** priority sales
window; both included receptions are simply free to a confirmed flagship
delegate. Prices themselves are still LAW's to confirm; they are fields the
committee edits, defaulting to 0 ("not on sale").

### 0.2 Decisions settled by Denis on 14 September 2026 (do not reopen)

| Question | Decision |
|---|---|
| How a paid reception takes money | **Stripe Checkout in `payment` mode** with `invoice_creation` on, so the delegate sees "Pay £X" on Stripe's page and still receives a VAT invoice URL and PDF. New code: the theme has only `setup` mode today (`functions/events/stripe/attendees.php:146`). Chosen over reusing the flagship's save-card-then-charge-off-session flow. |
| Colleagues in one checkout | **One place per checkout**, self only. Colleagues buy their own. Chosen over the spec's 3-colleague cap for receptions. |
| When a paid reception is full | **Waitlist with a saved payment method** (spec §5.4): joining saves a method (Checkout `setup` mode, as the flagship does); automatic FIFO promotion charges it off-session and confirms. Chosen over a plain "Sold out" state. |
| Where the committee sees reception bookings | **Hosted Manage bookings and the per-event bookings list**, which gain payment columns when the event is priced. Not the Flagship bookings page, which is review-shaped. |

### 0.3 Defaults this specification takes

Not put to Denis; built unless he says otherwise.

- **Included place on a full reception**: a confirmed flagship delegate still
  gets it. The engine over-books, logs `reception_overbooked` and emails
  `committee_reception_overbooked`, exactly as `law_flagship_approve()`
  over-books. The ticket promised the place; the committee sizes the room.
- **Cancelling.** A delegate cannot cancel a **paid** reception place, and
  this is enforced in the engine, not only the UI: `law_booking_cancel()`
  refuses the `self` and `booker` contexts when `_law_payment_status ===
  'paid'` (`law_booking_paid_place`), and the manage view says "Contact LAW to
  change a paid booking." The committee can cancel it (`host_reject` context),
  which sends a new `committee_reception_paid_cancelled` alert (no such alert
  exists for bookings today; `committee_cancelled_paid` is the host-fee one)
  and refunds nothing; the discount code's use is kept. A **free included**
  place and a **waitlist entry** can be self-cancelled as any hosted booking
  can, and cancelling an included place puts that reception back into the My
  bookings banner.
- **Flagship place later refunded in full**: included reception places granted
  from it are cancelled (`user_reception_included_revoked`) and logged. Only
  reachable through a committee refund, since a paid flagship place cannot be
  withdrawn.
- **Clash guard**: `law_booking_guard_clash()` (`functions/events/bookings.php:732`)
  skips any pair where either side is a reception or the flagship. Nothing
  exempts the flagship today; it has never needed it because
  `law_flagship_apply()` does not call the guard. The exemption goes **in the
  guard** because `law_waitlist_check_promotable()` calls it.
- **Discount refusal wording**: keep the distinct "expired / not yet / used up"
  messages (they help a real delegate) and put the quote endpoint on its own
  rate-limit surface. This is the decision `FLAGSHIP_PAYMENTS.md` §12 left for
  whoever wired the first priced flow; record it there when done.
- **Flagship "add without payment"** (committee comp places) gets the same
  "Included receptions" checkboxes as the application form.

### 0.4 Dependency: the roles retirement and the Account hub

`ROLES_AND_ACCOUNT_HUB.md` is being executed by another agent in this same tree
and **lands before this work**. Part A (retiring `event_host`, `sponsor` and
`attendee` in favour of plain `subscriber`, plus optional `law_intent` meta) is
already in the working tree at the time of writing; Part B (the Account hub at
`/account/`, shared `law_icon()`, the reordered navigation) is not yet started.
Read that document before starting this one. Everything below assumes both
parts are complete.

**Line numbers in this document predate that work.** `bookings.php`,
`registration.php`, `flagship-bookings.php`, `setup-account-pages.php`,
`migration/runner.php`, `account-bookings.php`, `header-nav.php` and
`class-law-test-case.php` all shift. Re-check every reference before editing.

What changes for receptions:

1. **No role is granted by booking.** `law_booking_grant_attendee_role()` is
   deleted, so the checkout (§2.3), the included grant (§2.6) and the waitlist
   join (§6.1) grant nothing. Accounts the committee's "Register an attendee"
   creates on a reception are plain `subscriber`, through the existing
   `law_booking_resolve_attendee_user()` default.
2. **The complimentary-place profile fill depends on the rewritten guard.**
   `law_registration_apply_attendee_profile()` now admits an account holding
   nothing beyond `subscriber` and the three legacy roles. The committee's
   complimentary reception place writes country, accessibility and dietary
   through it (§2.1), so if that guard regresses the fill stops silently.
3. **Signed-out dialog branches carry no `role` argument** on their "Create an
   account" link: `templates/register.php` no longer parses `?role=`. The
   reception checkout modal (§5.1) copies `parts/events/booking-modal.php` as
   it now stands, not as this document's line references describe it.
4. **`law_account_user_is_host_like()` means "signed in"** and survives only as
   the `[user-content role="host"]` audience seam. Do not gate any reception
   surface on it; gate on `is_user_logged_in()`.
5. **Migration step 11 (`retire_roles`) must stay last.** "Run all" executes
   gated steps in array order, and step 11 depends on step 10 having written
   the subscriber Members rows. Reception seeding belongs **inside step 10**
   (§8.1), beside `law_flagship_ensure_post()`. Do not append a step 12.
6. **Do not add the receptions dashboard to `law_setup_account_subscriber_access()`.**
   Its `$paths` list is the four pages a plain subscriber needs;
   `/account/dashboard/receptions/` is a committee child page whose Members
   rows are copied from `/account/dashboard/` by
   `law_setup_receptions_dashboard_access()` (§8.1).
7. **The navigation item needs the hub's three extra keys.** Part B gives every
   `law_header_nav()` item `icon`, `description` and `group`, read by
   `parts/layout/account-tiles.php` and ignored by the top bar. "Manage
   receptions" is therefore:

   | key | group | icon | description |
   |---|---|---|---|
   | `receptions` | committee | `receptions` | Dates, prices and places for the receptions |

   It slots **after `flagship`** in the committee order (`dashboard`,
   `speakers`, `flagship`, **`receptions`**, `bookings`, `flagship_bookings`,
   `discounts`), because it is a configuration screen like Manage flagship
   rather than one of the two bookings views, whose comment in `header-nav.php`
   says they sit together.
8. **Icons come from `law_icon()`**, not inline SVG. Add one glyph to
   `law_icon_paths()` in `functions/helpers.php`, in the same 24-unit box,
   stroke only, round caps and joins:
   `'receptions' => '<path d="M4 4h16l-8 9-8-9Z"/><path d="M12 13v7"/><path d="M8 21h8"/>'`
   (a raised glass). The `price` and `places` glyphs the reception facts row
   and list use have moved from `parts/calendar-event-details.php` into that
   same table.
9. **The hub is where people land after signing in**, so the included-receptions
   banner (§7.3) renders on **both** My bookings and the Account hub. Build it
   as one helper, `law_reception_banner( $user_id )`, returning markup or `''`,
   called from `templates/account-bookings.php` (listing view, above the cards)
   and `templates/account-hub.php` (above the tiles, below the "Signed in as"
   line). One helper, two surfaces; never two copies of the copy.
10. **Consolidate the filled panel.** Part B adds `.law-account-events__empty`
    (navy fill, orange top rule, words left, button right, stacking on a phone)
    for the My events empty state, which is the same component as the
    `.law-strip` this document generalises from `.law-flagship-strip` (§7.3).
    When this work lands, check what Part B actually built and fold both into
    the one `.law-strip` utility rather than adding a third near-copy.
11. **Tests.** `make_user()` now defaults to `subscriber`, so every new
    reception fixture calls it with no argument. Two of Part B's tests need a
    line each: `HeaderNavTest::role_expectations()` gains `receptions` in the
    committee expectations (all of them), and `AccountHubTest`'s "six committee
    hrefs" assertion becomes seven.

### 0.5 House rules that apply throughout

- UK English, sentence case for headings and labels, no em dashes. Wording is
  **"places"**, never "tickets".
- Every action follows the **AJAX modal pattern**: `admin_post_<action>` plus
  `admin_post_nopriv_<action> => law_events_nopriv_json`;
  `law_events_guard_post()` (nonce, honeypot `law_website_url` with a
  pretend-success payload, rate limit) then `law_events_respond()`. Confirm
  dialogs via `parts/layout/modal.php` rendered **inside** the form they
  confirm. No native `required` inside hidden dialogs (aria-required plus
  server validation). Plain POST must keep working without JavaScript. A
  dialog fetched over AJAX opens **immediately** with the skeleton placeholder
  (`law_booking_render_loading_modal()`), never a silent press.
- Preview and read-only surfaces **disable** controls; they never hide them
  (the include dialog's already-held receptions, the invitation-only price
  fields on Manage receptions).
- A signed-out visitor pressing a gated button gets the same dialog with the
  sign-in copy the hosted modal already has; never a navigation to a page that
  explains it.
- Filled call-to-action panels are brand navy with white text, every word in
  one block on the left, the button on the right, vertically centred.
- **Every mutation and refusal is logged** to the event's activity log via
  `law_event_log()`, WooCommerce-order-notes style, with `source` and
  `booking => <id>`. Doubly binding: this is a money path.
- **Every email is a registry entry** in `law_events_email_registry()`, sent
  through `law_events_send()`. Never interpolate delegate-typed text into a
  subject.
- **Concurrency**: every shared-state read and write runs inside
  `law_booking_lock( $event_id )`. Slow work (Stripe calls, emails) runs after
  unlock. Status writes go through `law_booking_set_status()`.
- **Exports always offer CSV, Excel and PDF**, reusing `functions/events/export.php`.
- Anything that depends on database state (pages, seed posts) is provisioned
  by **both** migration step 10 and the `?setup-account-pages` trigger, so a
  git push alone makes it work.
- Admin tools live under the existing LAW menu; the events CPT menu holds
  Bookings and Flagship. **Do not touch `wp-content/mu-plugins/`.** First-party
  JavaScript lives in `assets/js/`, never `assets/js/vendor/`.
- Never run `git stash`, `git checkout <file>`, `git reset` or anything that
  relocates or discards uncommitted work: the tree is shared with other agents.
- Tests: `vendor/bin/phpunit` from the theme root, **one invocation at a
  time, never killed mid-run** (they run in a transaction against the real
  local database; a killed run leaves fixtures behind).
- Browser QA goes to the **test-specialist** agent (Playwright) only when
  Denis asks; add `.playwright/` and `.playwright-cli/` to `.gitignore` before
  any run. Emails are checked in **Mailpit**.
- Whenever a webhook branch is added, tell Denis the full replacement
  `stripe listen --events` list (§3.3).

### 0.6 Read these first

- `ROLES_AND_ACCOUNT_HUB.md`: the whole document. §0.4 above lists what it
  changes for this work, but its sections 2 (Denis's decisions), 5 (the hub)
  and 11 (risks) matter beyond that list.
- `EVENTS_FUNC.md`: `bookings.php`, `waitlist.php`, `flagship.php`,
  `flagship-form.php` / `flagship-dashboard.php`, `discounts.php`,
  `flagship-bookings.php`, `flagship-bookings-dashboard.php`, Stripe sections.
- `FLAGSHIP_PAYMENTS.md` §0.2, §0.5, §2, §4 (Stripe), §5 (application flow),
  §13 (the discount catalogue and how a flow opts in).
- `WAITLIST.md` Part B (the promotion pass and its latches).
- Code: `functions/events/bookings.php` (guards, `law_booking_create()`,
  `law_booking_price()` :664, `law_booking_holding_statuses()` :690,
  `law_booking_cancel()` :1644, handlers :2041-2500),
  `functions/events/waitlist.php` (`law_waitlist_process()` :232,
  `law_waitlist_check_promotable()` :367, `law_waitlist_seat()` :395),
  `functions/events/flagship-bookings.php` (`law_flagship_apply()` :207,
  `law_flagship_on_card_saved()` :458, `law_flagship_approve()` :553,
  `law_flagship_claim_charge()` :801, `law_flagship_mark_paid()` :827,
  `law_flagship_confirm()` :921, `law_flagship_add_complimentary()` :1303,
  `law_flagship_handle_setup_return()` :1942),
  `functions/events/stripe/attendees.php`, `functions/events/stripe/webhook.php`
  (dispatch :79, `law_stripe_resolve_booking_id()` :324),
  `functions/events/discounts.php`, `functions/account-bookings.php`
  (`law_booking_resolve_state()` :400, `law_booking_render_action_body()` :629,
  `law_booking_panel()` :568, `law_booking_card_action()` :887,
  `law_booking_maybe_render_dialog()` :1099), `functions/account-flagship.php`,
  `parts/events/flagship-apply-modal.php`, `parts/events/booking-modal.php`,
  `parts/events/booking-manage.php`, `parts/events/flagship-manage.php`,
  `assets/js/booking-form.js` (dialog fetch :112-294, submit :616-718).

## 1. Data model

Receptions are ordinary `law_event` posts (programme, event page, `.ics`,
bookings, capacity, per-event list and exports for free), configured through
event meta.

### 1.1 Event meta (`law_event_meta_schema()`, `functions/events/meta.php`)

| Key | Type | Meaning |
|---|---|---|
| `_law_is_reception` | flag | Listed on Manage receptions; edited there and on its wp-admin box only. |
| `_law_attendee_price_pence` | int | Attendee price, **net of VAT**, in pence. 0 = free / not on sale. Named to sit beside `_law_fee_pence` (the HOST fee) and `_law_flagship_price_pence` without reusing the booking snapshot key `_law_price_pence`. |
| `_law_flagship_included` | flag | Free with a confirmed flagship place. |

Plus the existing reserved `_law_registration_state` (`meta.php:53`,
sanitiser at `:314`, vocabulary `'' | open | apply | free | external |
invitation | closed`): a reception writes `invitation`, `open` (priced) or
`free`. This is its first reader; `law_flagship_save()` (`flagship.php:941`)
is its first writer. Receptions are also `_law_is_law_event = 1`.

### 1.2 Booking meta (`law_booking_meta_schema()`)

| Key | Type | Meaning |
|---|---|---|
| `_law_discount_id` | int | The claimed code. **Deleted on release**, so release is idempotent per booking. |
| `_law_discount_code` / `_law_discount_pence` | text / int | Kept for the record after release. |
| `_law_included_with` | int | Flagship booking this free place was granted from. |
| `_law_reception_choices` | int_array | On a flagship application: receptions ticked at apply time. |
| `_law_stripe_checkout_session_id` | text | The live payment-mode session. Webhook handlers ignore any other session id (§3.2). |
| `_law_checkout_expires_at` | datetime | Mirrors the session's `expires_at`; cleared when payment goes to `processing`. |
| `_law_charge_claimed_at` | datetime | The charge latch (§6.3), generalised from `law_flagship_claim_charge()`. |

The `_law_price_pence` / `_law_vat` snapshot on the booking stays the contract
`law_booking_price()` and `stripe/attendees.php` read. A reception writes the
**discounted** net there plus `_law_discount_pence` for the record, so Stripe
never needs to know about codes.

### 1.3 Statuses and counts

- `booking_payment_status` sanitiser (`meta.php:302`) gains `included` (free
  via flagship); `complimentary` stays for committee-added places.
- New booking status `law-pending-payment` ("Awaiting payment") in
  `law_booking_custom_statuses()` (`functions/events/statuses.php`): a place
  held while the delegate is on Stripe's page. Added to
  `law_booking_holding_statuses()` (one place per person) and to the
  `wp_insert_post_data` / untrash whitelists in `workflow.php`.
- `law_event_recount_attendees()` (`bookings.php:105`) counts
  `law-pending-payment` **only when `law_event_is_priced()`**, so hosted and
  flagship counts stay byte-identical. Surfaces that print "Bookings (N)" from
  `_law_tickets_sold` (`account-bookings.php:292`, `booking-list.php:73`,
  `bookings-dashboard-list.php:81`, `export.php:80`, `source.php:116`) show the
  pending count as a separate "N awaiting payment" where non-zero, because
  `law_bookings_for_event()` defaults to `publish` and the table would
  otherwise show N-1 rows under "Bookings (N)".

### 1.4 Helpers

In `bookings.php` (kind-agnostic):

- `law_booking_kind( $booking )` → `flagship | reception | hosted`, by
  `post_parent`. `law_flagship_booking_is()` (`flagship-bookings.php:50`)
  becomes `'flagship' === law_booking_kind()`.
- `law_event_price_pence( $event_id )`: delegates to
  `law_flagship_price_pence()` for the flagship (its price is time-switched,
  not a stored int), else `_law_attendee_price_pence`.
  `law_event_is_priced()`, `law_event_is_invitation_only()`.

In a new `functions/events/receptions.php` (required from `_load.php` after
`flagship-bookings.php`, before the dashboards): `law_reception_is()`,
`law_reception_ids( $year = 0 )` (all statuses, ordered by `_law_start`),
`law_reception_included_ids()` (published, `_law_flagship_included`, not
started).

### 1.5 Extracted from `flagship-bookings.php` into `bookings.php`

Old names stay as one-line wrappers so the flagship suites stay green.

| New helper | From |
|---|---|
| `law_booking_profile_gaps( $user_id )` | `law_flagship_profile_gaps()` :415 |
| `law_booking_person_from_profile( $user_id, array $answers )` | `law_flagship_person_from_input()` :433 |
| `law_booking_insert( $event_id, $user_id, $status, array $person, array $meta )`: caller holds the lock; `law_bookings_next_numbers(1)`, `wp_insert_post`, `_law_booking_number`, `law_booking_write_attendee()`, the `$meta` map through `law_event_update_meta()` | the block at :299-323 and :1345-1372 (and a third copy inside `law_booking_create()`) |
| `law_booking_claim_latch( $booking_id, $key )`: lock + `add_post_meta( …, true )` | :502-509 |
| `law_booking_claim_charge()` / `law_booking_release_charge()` | :801-820 |
| `law_booking_guard_price_shown( $shown, $actual, $code )` | :272-281 |
| `law_booking_log_amount_mismatch( $booking_id, $paid, $expected, $context )` | :857-871 |
| `law_booking_email_extra( $booking_id )` | `law_flagship_email_extra()` :1413 |
| `law_booking_payment_states()` (+ `included`) | `law_flagship_payment_states()` (`flagship-bookings-dashboard.php:70`) |
| `law_event_ensure_managed_post( $slug, $title, array $meta, $dry )` | `law_flagship_ensure_post()` (`flagship.php:338`) |
| `law_booking_update_card` handler, dispatching by kind | `law_flagship_update_card` :1581 |

## 2. Engine: `functions/events/receptions.php`

Same shape as `flagship-bookings.php`: own guards, own meaning for statuses,
own handlers. Everything else reused: booking post type and numbering,
duplicate guard, event lock, recount, account resolve, log, email registry,
`.ics`, and the whole of `stripe/attendees.php`.

### 2.1 Guards

- `law_reception_guard_open( $event_id )`: published `law_event`, CPT source,
  `_law_is_reception`, not invitation-only, not started.
- `law_booking_guard_open()` (`bookings.php:480`) is **split**. It stays the
  event-live test (published, CPT, capacity set, not started, not flagship)
  used by the waitlist internals (`waitlist.php:251`, `:278`, `:533`) and the
  untrash hook. A new `law_booking_guard_form_open()` adds two refusals,
  `law_booking_invitation_only` ("Places at this reception are by invitation
  from LAW.") and `law_booking_priced` ("This reception is booked through
  checkout."), and is what `law_booking_create()`, `law_waitlist_join()`,
  add-a-colleague, the dialog server (`account-bookings.php:1124`) and
  register-on-behalf call. Hiding a button is not a control; this is.
- `law_booking_register_by_manager()` (the committee's "Register an
  attendee") passes `$args['allow_priced'] = true` and writes a complimentary
  place: `_law_price_pence 0`, `_law_is_complimentary 1`, `_law_payment_status
  complimentary`.

### 2.2 Quote

`law_reception_quote( $event_id, $code, $user_id )` →
`{ list_net, net, discount, vat, gross, code, discount_id, free }`.
`law_event_price_pence()`, then when a code is given
`law_discount_validate( $code, [ 'event_id', 'user_id', 'price_pence' ] )` and
`law_discount_apply()`; VAT via `law_events_vat_pence()` /
`law_events_gross_pence()` (`fees.php:159-170`). Pure, no writes. Used to
render the dialog, by the AJAX quote endpoint, and re-run inside the checkout
handler so the client can never send its own price.

### 2.3 Checkout

`law_reception_checkout( $user_id, array $input )` → `{ booking, redirect } |
WP_Error`. Input: `event_id`, `code`, `applied_code` (the code the last
successful quote used), `terms`, `price_shown` (gross pence the dialog
displayed).

1. `law_reception_guard_open()`; terms (`field => law_terms`).
2. If `code !== applied_code`: refuse with `field => law_discount_code`,
   "Press Apply to check your code before continuing." (Otherwise a typed but
   unapplied code would be applied server-side and then refused as a price
   change, which is untrue.)
3. `law_reception_quote()`; `law_booking_guard_price_shown()` refuses
   `law_reception_price_changed` when the gross differs.
4. `law_booking_profile_gaps()` (first and last name only, as the flagship).
5. `law_booking_guard_duplicates()` fast-fail before the lock.
6. **Lock**: recount; re-run guards; `law_booking_guard_capacity( $event_id, 1 )`;
   duplicate; **claim the code first** (`law_discount_claim( $discount_id )`;
   a false claim refuses `law_discount_used_up` with nothing to roll back);
   `law_booking_insert()` as `law-pending-payment` with `_law_price_pence` =
   discounted net, `_law_vat` = net > 0, discount meta, `_law_payment_status
   pending_setup`; recount. **Unlock.**
7. Log `reception_checkout_started` (with the code and the amounts). No role
   is granted: `law_booking_grant_attendee_role()` is gone (§0.4).
8. `free` (a 100% code): `law_reception_mark_paid()` at once, no Stripe call;
   redirect to My bookings with `reception-free-confirmed`.
9. Else `law_stripe_create_checkout_session( $booking_id )` (§3.1) and return
   its URL. A Stripe error calls `law_reception_release_hold( $booking_id,
   'stripe_error' )` and surfaces the message, so a failed attempt never holds
   a place.

### 2.4 Confirm

`law_reception_mark_paid( $booking_id, array $object, $stripe_event_id = '',
$actor_id = 0 )`: the single idempotent path, called from the browser return,
`checkout.session.completed`, `checkout.session.async_payment_succeeded` and
`invoice.paid`, in any order and concurrently.

- Branches on `$object['object']`. A **session** gives `amount_total` and
  `payment_intent`: the handler GETs the PaymentIntent and stores
  `latest_charge` as `_law_stripe_charge_id` (a session carries no charge; the
  return fetch can `expand[]=payment_intent`). An **invoice** gives
  `amount_paid`, `id`, `hosted_invoice_url`, `invoice_pdf`.
- Invoice and charge fields are written **before** the "already `publish`"
  early return, as `law_flagship_mark_paid()` does (`:853-881`).
- Under the lock: `law_booking_set_status( 'publish' )`, `_law_payment_status
  paid`, clear `_law_checkout_*`, recount. Unlock. Log, with
  `law_booking_log_amount_mismatch()` against `law_booking_price()['gross']`
  (a mismatch never blocks the place).
- **The confirmation email waits for the invoice URL.**
  `user_reception_confirmed` (`.ics`, `{invoice_link}`, `{discount_note}`) and
  `committee_reception_booking` are sent by whichever of session/invoice
  arrives **second** (one-shot latch `_law_confirmation_sent` via
  `law_booking_claim_latch()`), or by the sweep (§9) 15 minutes after
  confirmation if `invoice.paid` never came, then without a link.
- A payment landing on a `law-cancelled` booking logs "PAYMENT ON A CANCELLED
  BOOKING", emails `committee_reception_paid_cancelled`, and does **not** seat
  anyone.

### 2.5 Processing, failed, expired

- `law_reception_mark_processing( $booking_id )`: an async method was
  accepted but has not settled. Status stays `law-pending-payment` holding
  the place; `_law_payment_status processing`; **clears
  `_law_checkout_expires_at`** and stamps `_law_payment_processing_at`, so the
  sweep never releases a hold whose money is in flight (a Bacs-style payment
  settles days later). A `processing` older than
  `LAW_FLAGSHIP_PROCESSING_ALERT_DAYS` alerts the committee, as
  `law_flagship_run_daily()` does.
- `law_reception_mark_payment_failed( $booking_id, $message )`: async
  failure. Keeps the hold until expiry so the delegate can retry inside
  Stripe's page; emails `user_reception_payment_failed` once
  (`_law_payment_failed_at` as the latch).
- `law_reception_release_hold( $booking_id, $why )`: **first** `POST
  /v1/checkout/sessions/{id}/expire`. If Stripe answers that the session is
  already `complete`, GET it and call `mark_paid` instead. Otherwise status
  `law-cancelled`, release the code (§2.7), recount, `law_waitlist_process()`,
  log. No email (they left the page). Called from `checkout.session.expired`,
  the cancel return, a Stripe error at creation, and the sweep for holds past
  `_law_checkout_expires_at` **plus a 10-minute margin**, so the webhook
  normally wins.
- "Continue to payment" on a stale hold (`law_reception_continue`, §2.8)
  expires the old session via the API before creating a new one, so a late
  `checkout.session.expired` for the old id can never release a live hold.

### 2.6 Included places

- `law_reception_grant_included( $reception_id, $user_id, $flagship_booking_id,
  $actor_id, $source )`: requires the user's **own** confirmed flagship
  booking (`publish`, payment `paid` or `complimentary`) and a reception in
  `law_reception_included_ids()`. A person already holding a live place there
  is **skipped** (reported, not refused). No capacity guard: over-booking is
  logged `reception_overbooked` and `committee_reception_overbooked` is sent.
  Under the lock `law_booking_insert()` straight to `publish`, price 0,
  `_law_payment_status included`, `_law_included_with`; recount; unlock; log;
  `user_reception_included` with `.ics`.
- `law_reception_grant_choices( $flagship_booking_id, array $reception_ids,
  $actor_id, $source )` loops it (one lock per reception, never nested) and
  returns `{ granted: [id => title], skipped: [id => title] }`, so notices and
  the flagship email can say "Wednesday reception added. You already had a
  place at Opening drinks."
- `law_reception_revoke_included( $flagship_booking_id, $actor_id )` cancels
  every booking with that `_law_included_with` (context `event_cancelled`
  wording adapted: "your flagship place is no longer confirmed"), emails
  `user_reception_included_revoked`.

### 2.7 Discount claim and release lifecycle

- Claim happens **before** the insert, under the event lock, through
  `law_discount_claim()` (its conditional `UPDATE` is what makes two people
  redeeming the last use across two events safe).
- Release happens under the event lock **only if `_law_discount_id` is still
  set**, then the key is deleted (`_law_discount_code` / `_pence` stay), so a
  double release (webhook expiry plus sweep) is a no-op and never takes a use
  from somebody else's live claim.
- Release points: `law_reception_release_hold()`; `law_booking_cancel()`
  (`bookings.php:1644`) for **any** booking carrying `_law_discount_id` whose
  payment status is not `paid` (waitlist withdrawal, failed-payment expiry,
  included revocation are all this path); the retry-window sweep.
- A paid place cancelled by the committee **keeps** the use (refund is
  manual). A promotion charge uses the snapshot taken at join even if the code
  has since expired: the delegate consented to that figure.

### 2.8 Handlers

All `admin_post_*` with `admin_post_nopriv_* => law_events_nopriv_json`,
`law_events_guard_post()` then `law_events_respond()`; a JSON `redirect`
carries `?law_notice=`.

| Action | Who | Rate surface | Notes |
|---|---|---|---|
| `law_reception_quote` | signed in | new `discount_quote` 20/600 per user, 60 per IP | JSON only: `{ net, discount, vat, gross, label, free, code }` or `{ message, field: 'law_discount_code' }`. Never redirects. |
| `law_reception_checkout` | signed in | `booking` (10/600/100) | posts `event_id`; refuses a non-reception, invitation-only or unpriced event |
| `law_reception_continue` | signed in, own hold | `booking_edit` | **POST form, never a GET link** (hover-prefetch must not open sessions); returns the stored Checkout URL if unexpired, else expires it and opens a new session |
| `law_reception_waitlist_join` | signed in | `booking` | §6.1 |
| `law_reception_add_included` | signed in | `booking_edit` | the flagship booking is resolved **from the current user**, never from a posted id |
| `law_reception_manage` | committee | new `reception_manage` 30/600 | refuses a posted `event_id` that is not `_law_is_reception` (blank = create) |

### 2.9 Return from Stripe

`law_reception_handle_checkout_return()` on `template_redirect`, on the
existing `law_booking_manage_url( $booking_id )` (`/account/bookings/?law_booking=<id>`)
with `law_checkout_session={CHECKOUT_SESSION_ID}` on **both** the success and
the cancel URL (cancel adds `law_checkout=cancelled`). Same posture as
`law_flagship_handle_setup_return()` (`flagship-bookings.php:1936-1995`):
no nonce, because nothing is decided from the URL; signed in; the booking is
the viewer's own reception booking; the session is fetched from Stripe and
refused unless `metadata.law_booking_id` names this booking (so a guessable
booking id on a cancel URL cannot release somebody else's hold). Then:
`payment_status === 'paid'` → `mark_paid`; `unpaid` with a `processing`
intent → `mark_processing`; cancel → `release_hold` (which itself detects a
completed session). Redirects: paid / processing to the manage view;
cancelled / expired to the **listing** (the booking is now cancelled, so its
manage view would alarm).

### 2.10 Notices

Add a `law_booking_notice_text` **filter** in `law_booking_notice_text()`
(`account-bookings.php:208`) and register the reception map through it, rather
than stacking a third `_notice_render()` under the two at
`templates/account-bookings.php:63-70`.

| Key | Type | Copy |
|---|---|---|
| `reception-paid` | ok | Thank you. Your payment has gone through and your place is confirmed. A confirmation with a calendar invitation and your VAT invoice is on its way. |
| `reception-processing` | ok | Your payment is on its way. Your place is held and we will email you as soon as it clears. |
| `reception-free-confirmed` | ok | Your place is confirmed. Your discount code covered the full price, so nothing was charged. |
| `reception-cancelled` | error | No payment was taken and the place has been released. You can book again while places remain. |
| `reception-expired` | error | Your booking was not completed in time and the place has been released. You can book again while places remain. |
| `reception-return-failed` | error | We could not confirm your payment with Stripe. If money has left your account, contact LAW and we will sort it out. |
| `reception-card` | ok | Your payment details are saved and you're on the waitlist. If a place opens up we will charge {price_total} and confirm your place automatically. |
| `reception-card-cancelled` | error | Your payment details were not saved, so your place in the queue is not yet secured. Add them from your booking. |
| `reception-included-added` | ok | {Wednesday reception} added to your bookings. / …added. You already had a place at {Opening drinks}. |
| `reception-included-none` | error | Tick at least one reception to add. |
| `reception-included-denied` | error | Receptions are included only once your flagship place is confirmed. |
| `reception-checkout-failed` | error | (the engine's message) |

Every mutation and refusal logs to the reception event with `source` and
`booking => id`.

## 3. Stripe

### 3.1 The payment-mode session (`stripe/attendees.php`)

`law_stripe_create_checkout_session( $booking_id )` → URL | WP_Error. The
file's header already says a priced reception reuses it.

```
mode=payment
customer={law_stripe_user_customer_id()}
customer_update[address]=auto
customer_update[name]=auto            so the address collected at Checkout reaches the Customer and the generated VAT invoice
billing_address_collection=required
line_items[0][price_data][currency]=gbp
line_items[0][price_data][unit_amount]={law_booking_price()['net']}
line_items[0][price_data][product_data][name]={law_stripe_booking_line_description()}
line_items[0][quantity]=1
line_items[0][tax_rates][0]={law_events_setting('tax_rate_id')}    when vatable; refuse law_no_tax_rate when empty and vatable (as law_stripe_charge_booking() :556-562)
invoice_creation[enabled]=true
invoice_creation[invoice_data][metadata][…]={law_stripe_booking_metadata()}
payment_intent_data[metadata][…]={same}        a Charge inherits it, so charge.refunded resolves via webhook.php:198
metadata[…]={same}
expires_at={now + 1800 + 60}                   exactly 1800 is refused under clock skew
success_url={law_booking_manage_url()}&law_checkout_session={CHECKOUT_SESSION_ID}
cancel_url={law_booking_manage_url()}&law_checkout=cancelled&law_checkout_session={CHECKOUT_SESSION_ID}
```

No `payment_method_types` (Denis's standing rule: whatever LAW enables in the
Stripe Dashboard is the supported set). Idempotency key
`law-co-{booking}-a{attempt}` with `_law_stripe_attempt` incremented per
session. Stores `_law_stripe_checkout_session_id`, `_law_checkout_expires_at`,
logs `checkout_session_opened`.

**Two things marked unsure by the payments review, to verify with the Stripe
CLI before step 3 relies on them:**

1. Whether `invoice_creation[invoice_data][rendering_options]` accepts the
   invoice template id (`service.php:126` uses invoice-level
   `rendering[template]`, a different object). Drop it if not; the invoice
   still renders with the account default.
2. That `invoice.paid` fires for the Checkout-created invoice and that it
   carries `invoice_data.metadata`, so `law_stripe_resolve_booking_id()`
   resolves it. §2.4's email trigger depends on it; if it does not fire, the
   sweep's 15-minute fallback is the only trigger and the invoice URL must be
   fetched from the session's `invoice` field instead.

`law_stripe_create_setup_session()` gains `waitlist` in its `$reason`
whitelist (`:133`). `law_stripe_charge_booking()` is reused unchanged for
promotion (§6.3).

### 3.2 Webhook (`stripe/webhook.php`)

Replace the per-branch `law_flagship_*` calls with a **handler table**:
`law_booking_payment_handlers( $kind )` returns callables for `card_saved`,
`setup_failed`, `paid`, `processing`, `payment_failed`, `action_required`,
`refunded`, `session_expired`, filterable (`law_booking_payment_handlers`) so
`receptions.php` registers its row and `webhook.php` never names a reception
(the pattern `flagship-bookings-dashboard.php:296-306` uses to keep "the
flagship is different" out of `bookings-dashboard.php`). The dispatch resolves
`$kind = law_booking_kind( $booking_id )` once after `:87`; "no handler for
this kind" logs and returns false, which is what the three verbatim
fail-closed blocks in the flagship functions (`:466-480`, `:835-849`,
`:1161-1175`) did by hand, so they collapse into the resolver.

New event types: `checkout.session.completed` in `payment` mode → `paid`
(when `payment_status === 'paid'`) or `processing`;
`checkout.session.async_payment_succeeded` → `paid`;
`checkout.session.async_payment_failed` → `payment_failed`;
`checkout.session.expired` → `session_expired`. The existing `setup` branch of
`checkout.session.completed` (`:90-99`) now dispatches by kind too, so a
reception waitlist join reaches `law_reception_on_card_saved()`.

**Every `checkout.session.*` handler ignores a session whose `id` differs
from the booking's `_law_stripe_checkout_session_id`.**

The webhook handler also runs the reception sweep (§9) opportunistically after
dispatch (cheap, bounded to a handful of rows), so the sweep does not depend
on cron alone.

### 3.3 Stripe CLI

Denis forwards webhooks locally with `stripe listen` and wants the full
replacement list whenever it changes. It becomes:

```
stripe listen \
  --events invoice.paid,invoice.payment_failed,invoice.voided,invoice.marked_uncollectible,charge.refunded,checkout.session.completed,checkout.session.async_payment_succeeded,checkout.session.async_payment_failed,checkout.session.expired,setup_intent.succeeded,setup_intent.setup_failed,invoice.payment_action_required,payment_intent.payment_failed \
  --forward-to http://localhost/law/wp-json/law/v1/stripe-webhook
```

The four `checkout.session.*` entries are new for this work. The
`setup_intent.*`, `invoice.payment_action_required` and
`payment_intent.payment_failed` entries are already required by the flagship
and were missing from the 10 September 2026 baseline. The staging and
production dashboard endpoints need the same four added.
`LAW_STRIPE_WEBHOOK_SECRET` must hold the CLI's own `whsec_` while forwarding.

## 4. The booking control (event page and cards)

### 4.1 States

`law_booking_resolve_state()` (`functions/account-bookings.php:400`) resolves
in this order: flagship → **`invitation`** → the viewer's own booking
(**`pending-payment`**, **`payment-failed`**, **`waitlist-needs-card`**,
`waitlisted`, `booked`) → `not-open` → `closed` → **`included`** →
**`buy-full`** → **`buy`** → hosted `full` / `bookable`. An own booking
outranks availability, as today; `invitation` outranks everything but the
flagship because nothing about the viewer changes it.

| state | condition | tone | left slot (`law_booking_render_action_body()`) | right slot |
|---|---|---|---|---|
| `invitation` | `_law_registration_state === 'invitation'` | `closed` | heading "Invitation only"; sub "Places at this reception are by invitation from LAW. If you have been invited, LAW will be in touch directly." | none |
| `pending-payment` | own `law-pending-payment` | `mine` | `processing`: "Your payment is being processed." sub "Your place is held. We will email you as soon as it clears." / `pending_setup`: "Finish paying for your place." | **Continue to payment** (POST form, `data-law-modal-busy="Taking you to Stripe…"`) or **View booking** when processing |
| `payment-failed` | own `law-payment-failed` | `full` colour | "Your payment needs attention" + the stored reason | **Sort out my payment** (the flagship's label, `law_flagship_action_link()`) → manage view |
| `waitlist-needs-card` | own `law-waitlisted` with payment `pending_setup`, or blocked `law_waitlist_no_payment_method` | `mine` | "Your place in the queue needs your payment details." sub "Add them to keep your place on the waitlist." | **Add payment details** (POST form → setup session) |
| `included` | priced, `_law_flagship_included`, viewer holds a confirmed flagship place, no live place here | `open` | "Included with your flagship place" / "Add this reception to your bookings at no cost." | **Add to my bookings** → include dialog (§7.4) |
| `buy` | priced, places remain | `open` / `low` | the count line only ("12 places left", "Only 3 places left"), pill "Booking open" / "Almost full"; when the viewer holds a live **unconfirmed** flagship application add "If your flagship application is approved, this reception is included at no cost." | **Book now** (spec §3.4's word for a paid place) → `?law_reception_checkout=1`, `data-law-book` |
| `buy-full` | priced, 0 remaining | `full` | "This reception is fully booked." sub "Join the waitlist and save a payment method. If a place opens up we will charge it and confirm your place automatically. You can leave the waitlist at any time before then." | **Join waitlist** → `?law_reception_waitlist=1` |

Existing `booked` / `waitlisted` / `closed` apply unchanged (a paid or
included place reads "You're booked on this event"). `law_booking_tone()`
learns the new keys. The panel is the shared `law_booking_panel()`.

**Price is not in the panel.** The recorded decision at
`account-flagship.php:22-26` stands: the facts box states the net, the dialog
does the arithmetic. `law_flagship_details_price()` (`:28`) reads
`law_event_price_pence()` so the "Price" fact ("£75.00 + VAT") appears on any
priced event with no caller change (`parts/calendar-body.php:148-157`). Cards
print the same net figure, or "Invitation only", as a meta line in
`parts/loop/event.php`, from new `law_events_map_post()` keys `price_pence`,
`registration_state`, `flagship_included`.

### 4.2 Cards and dialogs

- `law_booking_card_action()` (`:887`): `buy` → "Book now", `buy-full` →
  "Join waitlist", `included` → "Add to my bookings", each with `dialog`;
  `invitation` → `null`; the own-booking states → the manage link as today.
- `law_booking_maybe_render_dialog()` (`:1099`) branches on
  `law_reception_is()` to `law_booking_render_reception_dialog( $event_id,
  $which )` with `$which` in `checkout | waitlist | include`, guarded by
  `law_reception_guard_open()` (404 otherwise, and always 404 for
  invitation-only), printing the dialog and its success partial as the
  hosted and flagship servers do.
- `parts/events/booking-loading-modal.php` gains `data-law-loading-include`
  ("Add to my bookings"); `bookKind()` in `booking-form.js:208-215` learns
  `law_reception_checkout` (→ "Book your place"), `law_reception_waitlist` (→
  the waitlist heading) and the include link.
- Signed-out visitors pressing Book now or Join waitlist get the dialog's
  sign-in branch. `included` cannot be reached signed out.

## 5. The checkout dialog and the live discount quote

### 5.1 `parts/events/reception-checkout-modal.php`

Args `event`, `context` (`modal | inline`), `mode` (`checkout | waitlist`).
Skeleton of `parts/events/flagship-apply-modal.php`: the signed-out branch;
the profile-gaps branch (`law_booking_profile_gaps()`, links to
`/account/profile/?redirect_to=`); then the form:

- `form.law-event-form.law-event-form--light.law-booking-form.law-reception-checkout`
  posting to `admin-post.php`; hidden `action`
  (`law_reception_checkout` / `law_reception_waitlist_join`), `event_id`,
  nonce, honeypot, `law_reception[price_shown]` (gross pence),
  `law_reception[applied_code]`.
- The shared `.law-booking-summary.law-event-summary` block (title, when,
  ruled row with places left; on `waitlist` the row says the event is full).
- A **price block** `.law-reception-price`: Price (net), Discount (hidden
  until applied), VAT, **Total** (gross), each line with `data-law-price=…`.
- The **Discount code** field `law_reception[code]`,
  `data-law-field="law_discount_code"`, no native `required`; an **Apply**
  button (`type="button"`, `data-law-quote`); a `[data-law-quote-status]` line
  with `role="status"`.
- `waitlist` mode only: consent checkbox `law_reception[consent]`
  (`data-law-field="law_consent"`) "Save my payment method and charge
  {price_total} when a place opens up. You can leave the waitlist at any time
  before then."
- Terms checkbox `law_reception[terms]` (`data-law-field="law_terms"`),
  linking `law_events_attendee_terms_url()`.
- A short paragraph "You will be taken to Stripe to pay." (hidden when the
  quote is free).
- Submit "Continue to payment" (`data-law-modal-busy="Taking you to Stripe…"`)
  or "Join waitlist" (`data-law-modal-busy="Joining…"`).

Modal wrapper id `law-reception-modal`; no `data-law-booking-success`, so the
JS success path rewrites the dialog and redirects after 2.5 s, as the flagship
apply dialog does (`booking-form.js:692-702`).

### 5.2 `assets/js/booking-form.js`: the quote section

- Apply, and Enter inside the code field (`preventDefault`), post
  `action=law_reception_quote&event_id&code&_wpnonce&law_ajax=1` with `fetch`.
- While in flight: Apply reads "Checking…" and is disabled; **the submit is
  disabled too** (a submit mid-quote would trip the `price_shown` guard and
  tell the delegate the price changed, which is untrue). A request token
  discards late responses.
- Success: swap the four price lines, show the Discount line, set
  `price_shown` and `applied_code`, and print "Code LAW25 applied: £15.00
  off. Remove" in the status line; **Remove** clears the field and re-quotes
  the list price. A `free` quote relabels the submit "Confirm my free place",
  its busy label "Confirming…", and hides the Stripe paragraph; a later
  non-free quote restores them.
- A field error uses the existing `markFlatField()` (`:425`) on `field`. A
  network failure prints "We could not check that code. Please try again."
  and leaves the standing price.
- On submit, if the field differs from `applied_code` (typed but not
  applied), run the quote first, then submit.
- Nothing in the client computes money. The server quote is the only source.

No-JS: the code goes with the form and the server applies it (the
`applied_code` check is skipped when the request is not AJAX and the field is
empty; when a code is typed without JS the server quotes and, if the gross
differs from `price_shown`, refuses once with the recalculated total shown, so
the delegate re-submits knowingly). The inline `?law_reception_checkout=1`
form repopulates from a one-shot transient like `law_flagship_form_state()`.

Styles: a `.law-reception-price` block added to `assets/css/calendar.css`
beside the `.law-event-summary` rules; no new stylesheet.

## 6. The paid waitlist

### 6.1 Join

`law_reception_waitlist_join( $user_id, array $input )`:
`law_reception_guard_open()`; the event must be full
(`law_booking_guard_seats( $event_id, 1, 'law-waitlisted' )`); consent and
terms; quote with an optional code (claimed now under the lock, released if
the entry leaves); `law_booking_profile_gaps()`; duplicate guard; under the
lock `law_booking_insert()` as `law-waitlisted` with the next position (the
`law_waitlist_join()` position logic at `waitlist.php:121-230`, extracted to
`law_waitlist_next_position()`), `_law_payment_status pending_setup`,
`_law_waitlist_joined`; recount; unlock; `law_stripe_create_setup_session(
$booking_id, 'waitlist' )`; redirect to Stripe. Emails wait for the card.

The setup return handler (`law_flagship_handle_setup_return()`,
`flagship-bookings.php:1942`) dispatches by `law_booking_kind()`: a reception
emits `reception-card` or `reception-card-cancelled` (§2.10) instead of the
flagship's `flagship-card`. `law_reception_on_card_saved()` claims a one-shot
latch via `law_booking_claim_latch( $id, '_law_waitlist_ready' )` and emails
`user_reception_waitlist_joined` and `host_waitlist_activated` (first time a
queue forms, as today). An entry still `pending_setup` after
`LAW_FLAGSHIP_SETUP_GRACE_HOURS` (48) is cancelled by the sweep with
`user_reception_waitlist_no_card`; until then the delegate sees
`waitlist-needs-card` (§4.1) and the manage view shows the reason with an
"Add payment details" button.

### 6.2 Promotion pass

`law_waitlist_process()` (`waitlist.php:232`) is modified **in place**. A
parallel `law_reception_waitlist_process()` would copy ~110 lines of cap,
latch, resume and renumber logic, and the wp-admin backstops
(`bookings.php:2547-2576`) call `law_waitlist_process` by name, so admin
trash/delete would never promote a reception queue. Two edits:

1. `law_waitlist_check_promotable()` (`:367`) additionally refuses a priced
   entry whose `_law_payment_status` is not `ready`, with code
   `law_waitlist_no_payment_method`. It is **skipped in place** through the
   existing blocked-entry latch (`_law_waitlist_blocked`) and emailed once,
   `user_reception_waitlist_blocked_no_card`: "A place opened up but we could
   not offer it to you because no payment method is saved. Add one to keep
   your place in the queue."
2. `law_waitlist_seat()` (`:395`), for a priced event, sets the entry to
   `publish` with `_law_payment_status processing` and
   `_law_payment_processing_at`, and takes `law_booking_claim_charge()`
   **under the lock**, so the place and the charge are both held before
   anything slow runs. It does not email. After the `finally` unlock
   (`:319-322`) the pass fires
   `do_action( 'law_waitlist_seated_after_unlock', $event_id, $promoted, $source )`;
   `law_waitlist_notify_promoted()` is skipped for priced entries (the
   reception hook sends its own).

`law_waitlist_process()`'s "Never the flagship" refusal stays. Its
`law_booking_guard_open()` calls keep working because that function is the
event-live test (§2.1), not the form guard.

### 6.3 The charge (hook in `receptions.php`)

`law_reception_charge_promoted( $event_id, array $promoted, $source )` on
`law_waitlist_seated_after_unlock`, for each entry that is a reception booking
in `processing` with the claim held, inside `try/finally` releasing the claim:

- `law_stripe_charge_booking( $booking_id )` (invoice + off-session pay,
  unchanged; it uses the join-time price snapshot).
- `paid` → `law_reception_mark_paid()` (emails `user_reception_promoted_paid`
  with `.ics` and invoice link, then `host_waitlist_promoted` as today).
- intent `processing` → stays `publish` / `processing` until `invoice.paid`.
- a decline → `law_reception_mark_payment_failed()` sets `law-payment-failed`
  (**no longer holds the place**), records the reason and
  `_law_payment_failed_at`, recounts, emails `user_reception_payment_failed`
  ("We could not take your payment, so the place went to the next person on
  the waitlist. Update your payment method by {payment_deadline} and we will
  book you in if a place is free, or put you at the front of the waitlist.")
  and `committee_reception_payment_failed`, then **`law_waitlist_schedule_resume(
  $event_id )`**. Never re-enter `law_waitlist_process()` from inside this
  loop: a queue of declining cards would nest without bound in one request.
- a configuration error (our tax rate, our key: `law_flagship_is_configuration_error()`
  generalised to `law_booking_is_configuration_error()`) → log ACTION NEEDED,
  email `admin_stripe_error`, revert the entry to `law-waitlisted` at the
  **front** as `ready`, and do not blame the delegate.

"Promote now" (`law_waitlist_promote()`, `:526`) goes through the same seat
path and may over-book as today.

### 6.4 Orphan reconciliation and retry

- The sweep (§9) finds reception bookings `publish` + `processing` with
  `_law_payment_processing_at` older than 15 minutes. **No**
  `_law_stripe_invoice_id` (PHP died between unlock and charge): take the
  claim and charge; if the claim is held and stale (older than the 5-minute
  takeover `law_flagship_claim_charge()` already uses), revert the entry to
  `law-waitlisted` at the front as `ready`. **With** an invoice: GET it;
  `paid` → `mark_paid`; `void` / `uncollectible` → `mark_payment_failed`;
  intent still `processing` → leave, alert after
  `LAW_FLAGSHIP_PROCESSING_ALERT_DAYS`.
- A `law-payment-failed` reception booking whose delegate saves a new method
  (`law_booking_update_card`, reason `retry`) within
  `LAW_FLAGSHIP_PAYMENT_WINDOW_DAYS` gets `law_reception_retry_charge()` only
  if a place is free; otherwise it returns to the **front** of the waitlist
  as `ready` and the delegate is told (`reception-requeued`: "Your payment
  details are updated. The reception is full again, so you are at the front
  of the waitlist."). Past the window the sweep cancels it, releases the code
  and detaches the method (`law_stripe_detach_payment_method()`), as
  `law_flagship_decline()` does.
- Withdrawing from the waitlist (existing `law_booking_cancel` `self`
  context) detaches the method and releases the code (§2.7).

## 7. Flagship inclusion

### 7.1 Application form and comp places

`parts/events/flagship-apply-modal.php` (consent step) and
`parts/events/flagship-add-attendee.php` gain a fieldset **Included
receptions** listing `law_reception_included_ids()` as checkboxes
`law_flagship_apply[receptions][]` (title, day and time, "Included at no
cost"), unticked, with the line "Tick the receptions you would like to attend.
You can add them later from My bookings." Hidden when none exist.
`law_flagship_input_from_request()` (`:1535`) passes `receptions` (ints);
`law_flagship_apply()` stores `_law_reception_choices` and logs them;
`law_flagship_add_complimentary()` takes `$row['receptions']` and grants at
once.

### 7.2 Confirm and refund hooks

After the approval email in `law_flagship_confirm()` (`:960`), call
`law_reception_grant_choices( $booking_id, $choices, 0, 'flagship_confirm' )`.
The approval email gains `{included_receptions}` built from `{ granted,
skipped }` ("Wednesday reception added to your bookings. You already had a
place at Opening drinks."). `law_flagship_mark_refunded()` (`:1238`) full-refund
branch calls `law_reception_revoke_included()`.

### 7.3 The banner on My bookings

`templates/account-bookings.php`, listing view, above the cards, after the
notices. Shown when the viewer holds a confirmed flagship place and
`law_reception_banner_state( $user_id )` lists included receptions they hold
no live place at (a `law-pending-payment` hold counts as held).

Component: **generalise `.law-flagship-strip`** (`assets/css/calendar.css:641-720`,
already filled navy, words left, orange button right, stacks on phones) to
`.law-strip` and reuse it, keeping `.law-flagship-strip` as an alias. Do
**not** un-scope `.law-booking-panel`: every one of its rules is under
`.law-event-details` (`:2161-2371`) and its padding and seam rules belong to
the facts grid. Fold `.law-account-events__empty` (the My events empty state
Part B of `ROLES_AND_ACCOUNT_HUB.md` adds, the same component again) into
`.law-strip` at the same time, per §0.4 item 10.

**Two surfaces, one helper.** `law_reception_banner( $user_id )` returns the
markup or `''`, and is called from `templates/account-bookings.php` (listing
view, above the cards, after the notices) and from `templates/account-hub.php`
(above the tiles, below the "Signed in as" line), because the hub is where a
signed-in delegate now lands (§0.4 item 9).

Copy built from the missing receptions' titles and days:
"Your flagship place includes {Opening drinks on Monday and the Wednesday
reception}. Add the ones you would like to attend at no cost." Button **Add
receptions** opens `law-reception-include`. The banner disappears once every
included reception is held.

### 7.4 The include dialog

`parts/events/reception-include-modal.php`: rendered inside the banner's form
on My bookings (the confirm-dialog-inside-its-form pattern), and served by
`?law_dialog=1` for the `included` control state on a reception page (that
reception pre-ticked). One checkbox per included reception with title, day
and time. One already held is **checked and disabled** with the tag "Already
booked" (or "Payment in progress" for a live hold), never hidden. Submit
**Add to my bookings** (`data-law-modal-busy="Adding…"`) →
`law_reception_add_included` → `law_reception_grant_choices()` → redirect to
the listing with `reception-included-added`. Refusals
`reception-included-denied`, `reception-included-none`. `<noscript>` holds
the same form open, as `flagship-add-attendee.php:156-168` does.

### 7.5 The manage view

`parts/events/booking-manage.php`: a reception booking shows a payment block
extracted from `parts/events/flagship-manage-application.php` into a shared
`parts/events/booking-payment-facts.php` (price paid, discount code, invoice
link and PDF, "Included with your flagship place (Booking #N)",
"Complimentary", or the blocked / failed reason with its button). Cancel is
hidden for a paid place with the line "Contact LAW to change a paid booking."
(the engine refuses anyway, §0.3); shown for included, waitlisted and failed.
The waitlisted line reads "We'll charge {price_total} and email you as soon as
a place opens up." on a priced event. "Add a colleague" never renders on a
reception (`law_booking_guard_form_open()` refuses).

## 8. Committee surfaces

### 8.1 Manage receptions

`/account/dashboard/receptions/`, committee-only, gated in three places
(header link, template, handler; partials re-check for themselves). **List
plus edit**, following the speakers dashboard (`?law_speaker=` at
`speakers-dashboard.php:348`, list partial `speakers-dashboard-list.php`),
with `?law_reception={id|new}`.

Files: `functions/events/receptions-dashboard.php` (route constants
`LAW_RECEPTIONS_DASHBOARD_TEMPLATE` / `_PATH`, `law_receptions_dashboard_url()`
by path, gate, `law_reception_manage` handler, one-shot state transient,
notices `reception-saved` / `reception-invalid` / `reception-denied` /
`rate-limited`, assets: `law_rich_text_enqueue()`, `law-admin.css`,
`flagship-dashboard.css` scoped class reused as `.law-flagship-dashboard
.law-receptions-dashboard`), `templates/account-dashboard-receptions.php`
("Receptions dashboard (committee)", `nocache_headers()` before
`get_header()`), `parts/events/receptions-list.php`,
`parts/events/reception-manage.php`. Data layer in `receptions.php`:
`law_reception_input_from_post()`, `law_reception_validate()`,
`law_reception_save( array $input, $actor, array $args = [] )`,
`law_reception_form_values( $event_id )`, `law_reception_snapshot()`,
`law_reception_log_save()` (field-by-field plain-English lines, nothing when
nothing changed, money spelled out).

List columns (flat table, `.law-booking-table`): Reception, Day and time,
Venue, Places (confirmed / awaiting payment / available), Price, Included
with flagship, Invitation only, On programme, Edit. **Add a reception** is a
button above the table opening the empty edit form (`?law_reception=new`); a
new reception is a `law-draft` created by the form's first save. Exports
CSV / Excel / PDF of the list through `functions/events/export.php`.

Edit form, `.law-row-grid` vocabulary from `parts/events/flagship-manage.php:123-238`:
Show on the programme (first control); Title; Description
(`law_rich_text_field`); Date; Start and end time (`_law_start` / `_law_end`
written directly, `_law_slot_label` written empty as the flagship does; end
after start); Venue name and address (`_law_venue`, the address parts);
Places available (`_law_tickets_available`, refused below
`law_event_attendee_total()`); Price excluding VAT (pounds via
`law_events_pounds_to_pence()`, blank = leave the stored value, 0 = free,
non-numeric refused; preview line "Attendees pay £90.00 including VAT" via
`law_events_price_label()`); Included with the flagship place; Invitation
only (when ticked the price and places controls are **disabled**, not
hidden, with the note "Invitation-only receptions take no bookings"; stored
values are kept, never cleared by the hidden-field rule). Every field posts
under `law_reception[...]` with a `law_reception_present` sentinel for the
checkboxes.

Status: the `law_flagship_saving` exemption in `workflow.php:107` becomes
`law_event_managed_saving`, honoured for the flagship **or** a
`_law_is_reception` post, and only for `publish` / `law-draft`. No workflow
transition, fee, invoice or notification runs for a reception save.

Provisioning: `law_reception_ensure_posts( $dry )` via the generalised
`law_event_ensure_managed_post()` seeds three `law-draft` receptions,
idempotent by slug: `opening-drinks` ("Opening drinks", Monday of the
programme week, included), `wednesday-reception` ("Wednesday reception",
Wednesday, included), `friday-reception` ("Friday reception", Friday,
invitation-only); prices 0; `_law_is_law_event`, `law_year` term. Called from
migration step 10 (`runner.php:1874` beside the flagship), the
`?setup-account-pages` trigger (`setup-account-pages.php:142`) and the
dashboard's first open. Page: `law_migration_page_map()` (`runner.php:1736`)
entry `'account/dashboard/receptions' => [ 'title' => 'Receptions dashboard',
'template' => 'templates/account-dashboard-receptions.php' ]`,
`law_setup_account_pages()` (`setup-account-pages.php:55`),
`law_setup_receptions_dashboard_access()` wrapper of
`law_setup_child_page_access()`, reported at `:128-135` and in step 10. It is a
committee child page, so it is **not** added to
`law_setup_account_subscriber_access()`'s `$paths` (§0.4 item 6), and the
seeding goes in step 10, never a new step after `retire_roles` (§0.4 item 5).
Header: `law_account_paths()` key `receptions`, item "Manage receptions"
**after** "Manage flagship" in the committee list, carrying the hub's `icon`,
`description` and `group` keys and the new `receptions` glyph in
`law_icon_paths()` (§0.4 items 7 and 8); `HeaderNavTest::role_expectations()`
and `AccountHubTest`'s committee-hrefs assertion both updated (§0.4 item 11).

`ReceptionsDashboardTest::test_screens_share_one_write_path()` pins the split
by reflection as `FlagshipDashboardTest.php:34-62` does, covering the wp-admin
box too.

### 8.2 wp-admin

A "Reception" meta box on `functions/events/admin/event-screen.php` (side
context, under Classification) with the same four controls (Is a reception,
Price excluding VAT, Included with the flagship place, Invitation only). It
posts through `law_reception_save( $input, $actor, [ 'partial' => true ] )`,
which validates and writes only the keys present, so there is **one saver**.
Publishing stays a dashboard act ("Show on the programme"); the event screen's
Publish button keeps refusing status changes outside the managed-saving path.

### 8.3 Bookings lists and exports

Manage bookings (`bookings-dashboard.php`) and the per-event list
(`parts/events/booking-list.php`) gain, **only when the event is priced**,
columns Payment (labels from `law_booking_payment_states()`: Paid £90.00,
Included, Complimentary, Awaiting payment, Payment failed, Refunded), Code,
Invoice (link). `law_booking_card_badge()` (`account-bookings.php:237`) learns
`law-pending-payment` → "Awaiting payment". All three export builders,
`law_booking_export_rows()` (`bookings.php:2428`),
`law_bookings_dashboard_export_rows()` (`bookings-dashboard.php:257`) and
`export.php:80`, add Payment status, Amount paid, Discount code, Invoice URL.
`law_bookings_dashboard_filters()` gains a Payment select rendered when any
priced event exists. "Register an attendee" on a reception is the committee's
complimentary place. Committee cancel of a paid place sends
`committee_reception_paid_cancelled`; nothing refunds. A reception on
`?law_event_bookings=` does **not** get the flagship's redirect
(`law_flagship_redirect_hosted_list()`): it belongs in the hosted list by
Denis's decision.

### 8.4 Discount catalogue

`receptions.php` adds every priced reception to the `law_discount_scope_events`
filter (label "Opening drinks, Mon 30 Nov"), so the checkboxes in
`parts/events/discounts-manage.php:209-226` appear and the list's "Applies to"
prints the names. Copy updates: `templates/account-dashboard-discounts.php:68`
→ "Codes are accepted when booking a paid reception. Leave 'Applies to' empty
for a code that works at every paid reception."; the empty-scope hint at
`parts/events/discounts-manage.php:224`; the nav comment at
`header-nav.php:306-308`; the file headers at `discounts.php:1-17`,
`discounts-dashboard.php:10-11`, `post-types.php:134-147`.
`tests/DiscountsTest::test_nothing_applies_a_code_yet()` (`:251-257`) is
rewritten **deliberately**: keep the source grep that fails if
`flagship-bookings.php` ever calls `law_discount_validate` / `_claim`;
replace `assertSame( array(), law_discount_scope_events() )` with "the
flagship event is never in `law_discount_scope_events()`" and "a priced
reception fixture is".

## 9. Cron and sweeps

One hourly `law_reception_hourly` event (and an opportunistic bounded run from
the webhook handler after dispatch) does, in order:

1. release `law-pending-payment` holds past `_law_checkout_expires_at` + 10
   minutes, skipping `processing` (§2.5);
2. send delayed confirmations for paid places whose invoice never arrived
   within 15 minutes (§2.4);
3. orphan reconciliation of `publish` + `processing` reception bookings
   (§6.4);
4. cancel `pending_setup` waitlist entries past 48 hours (§6.1);
5. expire the `law-payment-failed` retry window: cancel, release code, detach
   method (§6.4);
6. alert the committee about `processing` older than
   `LAW_FLAGSHIP_PROCESSING_ALERT_DAYS`.

`WAITLIST.md:91` records that the site relies on pseudo-cron. This sweep is
load-bearing for money paths, so **production needs a real cron hitting
`wp-cron.php`**; say so in the deploy notes and in `EVENTS_FUNC.md` §6.

## 10. Emails

Registry entries in `functions/events/notifications.php`, placeholders through
`law_booking_email_extra()` plus two new tags declared in
`law_events_email_placeholders()`: `{discount_note}` ("Discount code LAW25:
£15.00 off", empty when none) and `{included_receptions}`.

| Slug | To | When |
|---|---|---|
| `user_reception_confirmed` | delegate, `.ics` | paid place confirmed and invoice known (§2.4) |
| `committee_reception_booking` | committee | with the above |
| `user_reception_included` | delegate, `.ics` | included place granted |
| `user_reception_included_revoked` | delegate | flagship refunded |
| `user_reception_payment_failed` | delegate | async failure, or promotion decline |
| `committee_reception_payment_failed` | committee | promotion decline |
| `user_reception_waitlist_joined` | delegate | payment method saved on a waitlist entry |
| `user_reception_waitlist_no_card` | delegate | entry cancelled after 48 h without a method |
| `user_reception_waitlist_blocked_no_card` | delegate | skipped in place at promotion |
| `user_reception_promoted_paid` | delegate, `.ics` | promotion charged and confirmed |
| `committee_reception_overbooked` | committee | included place granted on a full reception |
| `committee_reception_paid_cancelled` | committee | paid place cancelled by the committee, or payment on a cancelled booking |

Cancellation reuses the existing context templates. `host_waitlist_activated`
and `host_waitlist_promoted` fire as today. Subjects never carry delegate-typed
text.

## 11. Tests

`vendor/bin/phpunit` from the theme root, one invocation at a time. Stripe is
stubbed via `law_stripe_request_mock` and `$GLOBALS['law_test_stripe_queue']`
(an unqueued call fails loudly); fixtures point `law_flagship_event_id` at a
test flagship as the flagship suites do (`FlagshipPaymentsTest.php:37-61`).

- `tests/ReceptionsTest.php`: helpers and `law_booking_kind()`;
  `guard_form_open` refusals and the manager exception; clash exemption;
  quote arithmetic (percent, fixed, 100%, wrong-event code); `applied_code`
  and `price_shown` refusals; a hold counts toward capacity on a priced event
  and not on a hosted one; release restores place and code once only; release
  detects a completed session and confirms instead; stale session ids
  ignored; `mark_paid` idempotent across session/invoice order; the
  confirmation email waits for the invoice; payment on a cancelled booking
  never seats; a free code confirms with no Stripe call; the engine refuses
  self-cancel of a paid place; committee cancel keeps the code's use.
- `tests/ReceptionCheckoutStripeTest.php`: the session body (mode, line item,
  tax rate, `invoice_creation`, `customer_update`, no
  `payment_method_types`, `expires_at`); handler-table routing for all four
  `checkout.session.*` events and `invoice.paid`; the flagship branches
  unchanged (`WebhookTest` stays green).
- `tests/ReceptionWaitlistTest.php`: join claims the code and opens a setup
  session; `pending_setup` skipped in place with one email; `ready` seated as
  `processing` under the lock and charged after unlock; decline frees the
  place and schedules a resume rather than recursing; processing holds;
  orphan reconciliation both branches; retry only when a place is free, else
  front of the queue; 48 h no-card sweep; withdraw detaches and releases; the
  hosted free waitlist unchanged (`WaitlistTest` green).
- `tests/ReceptionInclusionTest.php`: choices stored; confirm grants and
  reports skipped; full reception over-booked and alerted; banner state; the
  add-included handler refuses without a confirmed flagship place and never
  trusts a posted booking id; refund revokes.
- `tests/ReceptionsDashboardTest.php`: provisioning idempotent; one write
  path by reflection including the wp-admin box; validation (places floor,
  price parsing, invitation disables price, end after start); the status
  exemption only for `publish` / `law-draft`. The nav item is covered by
  editing Part B's own tests rather than a new one: `receptions` joins every
  committee expectation in `HeaderNavTest::role_expectations()`, its `icon`,
  `description` and `group` keys are covered by that file's existing
  per-item assertions, and `AccountHubTest`'s six-committee-hrefs case becomes
  seven (§0.4 item 11).
- `tests/ReceptionInclusionTest.php` also asserts `law_reception_banner()`
  returns markup for a confirmed flagship delegate missing a reception and
  `''` otherwise, since two templates render it (§7.3).
- `BookingStateTest` / `BookingCardActionTest` additions for the new states
  and labels; `DiscountsTest` rewrite (§8.4).

## 12. Documents to update in the same piece of work

- **`EVENTS_FUNC.md`**: sections for `receptions.php`,
  `receptions-dashboard.php`, the handler table in `webhook.php`, the control
  states, the discount wiring, the sweep; the extracted helpers under
  `bookings.php`; drop `_law_registration_state` and the discount catalogue
  from the "Reserved (present but intentionally unused)" list in §6; §6 open
  items; a change-history entry.
- `FLAGSHIP_PAYMENTS.md` §12 (receptions built, refusal-wording decision)
  and §13 (the catalogue now has a caller).
- The `header-nav.php` and hub sections `ROLES_AND_ACCOUNT_HUB.md` Part D
  writes: the seventh committee item, the `receptions` glyph, and the banner
  rendering on the hub as well as My bookings. Add to those sections rather
  than opening new ones, so the navigation is described in one place.
- This document's status line, and a "Deviations from this specification"
  section at the end listing anything built differently and why.

## 13. Build order and gates

**Before step 1**, confirm `ROLES_AND_ACCOUNT_HUB.md` has landed in full: the
Account hub template, `parts/layout/account-tiles.php`, `law_icon_paths()` and
the reordered `law_header_nav()` items must exist, because §8.1's nav item and
§7.3's banner attach to them. If only Part A has landed, build steps 1, 3, 5
and 6's engine work, and hold §8.1's nav item and the hub half of the banner
until the hub exists rather than inventing a parallel list.

1. §1: meta, statuses, `law_booking_kind()`, the extractions (wrappers keep
   the flagship suites green), the `guard_form_open` split, the clash
   exemption, the recount gate. Run the whole suite before and after.
2. §8.1–8.2: Manage receptions dashboard, wp-admin box, provisioning, header
   link, managed-saving exemption. Tests.
3. §2–3: quote, checkout engine, payment-mode session, return handler,
   handler table and webhook events, sweep, emails. **Verify the two unsure
   Stripe items (§3.1) with the CLI first.** Give Denis the new `stripe
   listen` list. Tests.
4. §4–5: control states, dialogs, the `booking-form.js` quote section, cards
   and facts price, manage-view payment block, the notice filter. Browser QA
   via test-specialist only if Denis asks.
5. §6: paid waitlist. Tests.
6. §7: flagship inclusion, `.law-strip` banner, include dialog, revoke on
   refund. Tests.
7. §8.3–8.4: committee columns, the three export builders, payment filter;
   discount scope and copy; `DiscountsTest` rewrite.
8. §12 documents; then the closing gates: three conformance passes against
   this document; `security-specialist` review (new handlers, return URLs,
   IDOR on posted ids, the quote oracle); `stripe-specialist` review
   (`attendees.php`, `webhook.php`, the sweep); a 10-flow E2E with the
   test-specialist agent (buy with code, buy without, 100% code, cancel on
   Stripe's page, expiry, waitlist join and promotion with charge, declined
   promotion, flagship apply with receptions, banner add, invitation-only
   page); then three parallel review agents (UX, design/reuse, backend).

Commit per step with a message naming the section; do not push or merge
unless Denis asks (his release order is commit and push the working branch,
merge into staging, push staging, switch back).

## 14. Manual verification

- `?setup-account-pages` creates the page and three drafts; Manage receptions
  publishes Monday with a price; `/programme/` shows "£75.00 + VAT" on the
  card and "Invitation only" on Friday; the Friday page shows the invitation
  panel and no button; Book now opens the skeleton then the dialog; Apply
  recalculates the total in place and Remove restores it; a Stripe test
  payment returns to the manage view with `reception-paid`; Mailpit holds
  **one** confirmation with `.ics` and invoice link whichever of
  `checkout.session.completed` / `invoice.paid` the CLI delivers first;
  cancelling on Stripe's page releases the hold; `stripe trigger
  checkout.session.expired` releases a hold; a delayed-notification test
  method leaves the hold in `processing` and the sweep does not release it.
- Fill Monday, join the waitlist, save a card, cancel a place: the entry is
  charged and confirmed. Decline with `4000000000000341`: the entry goes to
  "Payment needed", the place frees, the resume cron promotes the next entry.
- Apply to the flagship ticking Wednesday, approve as committee: Wednesday
  appears as Included; the banner offers Monday only **on both `/account/` and
  `/account/bookings/`**; adding it from either clears it from both; a full
  refund on the flagship revokes Wednesday.
- Sign in as committee: the Account hub shows a "Manage receptions" tile with
  its glyph under Committee tools, between Manage flagship and Hosted
  bookings, and the header dropdown shows the same item in the same place.
  Sign in as an ordinary subscriber: no committee tools, and buying a
  reception place grants no role (check `wp_capabilities` before and after).
- Discount catalogue shows both receptions under "Applies to"; a code scoped
  to Monday is refused on Wednesday with the field marked in place.
- Every touched view screenshotted with a colour-contrast check (white on the
  four panel fills is AA; the `.law-strip` navy with orange button is the
  existing flagship strip).

## 15. Risks and follow-ups recorded, not built here

- Prices are unconfirmed by LAW; they default to 0 (not on sale) until the
  committee types them.
- A hold dips "places left" for up to ~40 minutes while somebody is on
  Stripe's page; the count recovers on expiry.
- Refunds remain manual; committee cancel of a paid place alerts but does not
  refund.
- `law_discount_validate()` has no per-user limit; `max_uses` is a global
  counter. Not built here.
- The flagship's `LAW_FLAGSHIP_SETUP_GRACE_HOURS`, `_PAYMENT_WINDOW_DAYS` and
  `_PROCESSING_ALERT_DAYS` are reused for receptions; rename to `LAW_BOOKING_*`
  when convenient.
- Two Stripe details marked unsure in §3.1 must be verified with the CLI
  before step 3 relies on them.
- **`ROLES_AND_ACCOUNT_HUB.md` risk 8 widens slightly here.** Any signed-in
  account may now submit an event and choose the £0 sponsor fee tier. Nothing
  in receptions depends on that (reception prices are the committee's), but the
  same posture means any signed-in account can buy a reception place, which is
  intended.
- Two agents worked this tree at once. If Part B of the roles work shipped a
  filled navy panel under a different class name, consolidate rather than
  leaving two (§0.4 item 10), and reconcile this document's line references,
  which predate all of it.
- A colleague-booking (quantity > 1) checkout was ruled out for now (§0.2);
  if it returns, it needs N holds created under one lock and released
  together, and the quote applied across the party.

---

## 16. Deviations from this specification

Everything in §1-§14 was built. These are the places where the code differs
from the letter of the document, and why.

1. **`law_booking_payment_states()` is shared, with a flagship overlay.** §1.5
   said the flagship's map moves wholesale into `bookings.php` with `included`
   added, leaving `law_flagship_payment_states()` a one-line wrapper. Doing
   that would have changed three words on the Flagship bookings screen:
   "Payment method saved, awaiting review" → "Payment details saved",
   "Awaiting the delegate's bank" → "Bank confirmation needed", and "No charge"
   → "Complimentary". Those three carry the flagship's REVIEW semantics, which
   a reception has none of. So the shared map holds the generic wording and the
   flagship's wrapper lays its three over it: one map of states, two sets of
   words for three of them, rather than two maps that could come to hold
   different states.

2. **The charge latch is `_law_charge_claim`, not `_law_charge_claimed_at`.**
   §1.2 named a new key. The flagship's existing latch already works, is
   already claimed atomically, and renaming it would have stranded any claim
   held across the deploy — a five-minute window in which a booking could have
   been charged twice. The key stays, and stays outside
   `law_booking_meta_schema()` with the other one-shot latches, because a
   sanitiser between the claim and the row it depends on is one more thing able
   to turn a winning INSERT into a losing one. `_law_paid_at` was added to the
   schema instead, which is what the sweep reads to decide when a confirmation
   has waited long enough for its invoice (`post_modified` moves for any edit,
   so it could not be that).

3. **`law_booking_create()`'s insert loop was left alone.** §1.5 listed it as a
   third copy of `law_booking_insert()`. It is not quite: it claims N
   consecutive booking numbers in ONE atomic step
   (`law_bookings_next_numbers( $count )`), so a party booked together reads as
   a block in the committee's list. `law_booking_insert()` claims one. Rewriting
   the loop around it would have broken that contract for no gain, since no
   priced flow books a party.

4. **The venue is one field, not "the address parts".** §8.1 read as though a
   reception took a structured address. The `law_event` schema has no venue
   address: `_law_venue` is a single free-text "Venue (name and/or address)",
   which is what the host form, the flagship screen and the event page all use.
   The reception form matches them. `law_events_address_parts()` is the INVOICE
   address and belongs to the host fee.

5. **Invitation-only disables the price and places server-side, with no live
   toggle.** §8.1 asks for the controls to be disabled rather than hidden, and
   they are — from the STORED value, so ticking the box and saving disables
   them. There is no JavaScript that disables them the moment the box is
   ticked: the theme's existing conditional-field helper hides rather than
   disables, and it is not enqueued on this screen. The rendered state is
   correct and the saver leaves a key it was not sent alone, so the stored price
   survives either way.

6. **The `included` card and dialog use `?law_reception_include=1`.** §4.2 has
   the include control on the checkout query var. Its own var is what gives the
   skeleton dialog the right heading ("Add to my bookings", not "Book your
   place") and sends the no-JS fallback to the include control rather than to a
   checkout form the handler would refuse.

7. **The events export gained "Awaiting payment", not the four booking
   columns.** §8.3 lists `export.php:80` alongside the two booking export
   builders. That line is the EVENTS export, one row per event: "Discount code"
   and "Invoice URL" have no meaning on it. It gained the column that does — the
   count of places held while somebody pays, beside "Bookings", so the two
   numbers cannot silently disagree. The two BOOKING exports gained all four.

8. **The two unsure Stripe items in §3.1 were settled from the documentation,
   not the CLI**, by the `stripe-specialist` review on 14 September 2026:
   - `invoice_creation[invoice_data][rendering_options][template]` **is** a
     real field, added in API version `2025-07-30.basil`, which this client's
     pinned `2025-09-30.clover` postdates, and it takes the same
     `inrtem_…` Invoice Rendering Template id the host-fee invoices already
     use. It **is now sent**, so a reception invoice carries LAW's branding
     like every other one — but only when the setting holds a template id,
     because an unrecognised one would refuse the whole session.
   - `invoice.paid` **does** fire for a Checkout-generated invoice and **does**
     carry `invoice_data.metadata`, so `law_stripe_resolve_booking_id()`
     resolves it. The code still does not depend on it:
     `law_reception_mark_paid()` reads the `invoice` id off the Checkout
     session and GETs it, the `invoice.paid` branch stays as an idempotent
     second path, and the sweep is the third.

   **Denis should still run `stripe listen` against a real test payment before
   go-live**, with the replacement list in §3.3, and confirm that one
   confirmation email arrives with the invoice link in it.

10. **`complete` is not `paid`** (found by the same review, and fixed). Two
    paths — `law_reception_release_hold()` and `law_reception_continue()` —
    read a Checkout session Stripe refused to expire and treated
    `status === 'complete'` as money in the bank. Stripe's own words are "the
    checkout session is complete; payment processing may still be in
    progress", and a bank debit comes back complete and `unpaid`. Confirming
    there would have published a place nobody had paid for AND locked out the
    correction, because a confirmed paid booking is exactly what
    `law_reception_mark_payment_failed()` refuses to touch. Both now go through
    `law_reception_settle_completed_session()`, which branches on
    `payment_status` the way the webhook router and the browser return already
    did. Pinned by
    `ReceptionsTest::test_a_completed_but_unsettled_session_holds_rather_than_confirms()`.

11. **A configuration error is not shown to the delegate.** The
    `security-specialist` review found that a refused checkout quoted the
    engine's message verbatim, including "no Stripe tax rate ID is configured
    in LAW → Events settings" — which tells somebody trying to buy a drink
    where our admin menu is and that our payment setup is broken.
    `law_reception_refusal_payload()` now substitutes a generic sentence for
    anything `law_booking_is_configuration_error()` claims as ours; every other
    refusal is still quoted verbatim, because "that code has expired" is the
    whole point. The detail stays in the activity log.

12. **The Stripe-calling return handler is throttled.** It carries no nonce by
    design (nothing is decided from the URL; the session's own metadata is the
    guard), but it called Stripe on every request. It now shares the module's
    rate-limit posture: 30 per user per ten minutes, and past that it simply
    shows the booking without the round trip, which is the page they were
    going to get anyway. The flagship's `law_flagship_handle_setup_return()`
    has the same gap and was left alone as out of scope; worth closing next
    time that file is open.

13. **The discount-code oracle is a recorded trade-off, not an oversight.**
    `law_discount_validate()` gives one message for "no such code" and "that
    code is disabled", so the field cannot be used to enumerate the catalogue,
    but `wrong event`, `expired`, `not yet` and `used up` are distinct — which
    tells a guesser that a code they tried exists somewhere. That is §0.3's
    decision and the quote endpoint's own rate surface is the answer to it.
    **It holds only while codes are hard to guess**: `LAW-01` … `LAW-99` would
    be walkable. Worth a word to Denis before the committee starts inventing
    codes.

9. **`law_booking_cancel()` gained an `included_revoked` context.** §2.6 said to
   reuse `event_cancelled` with adapted wording. That context sends
   `user_booking_event_cancelled`, so the delegate would have had two emails —
   "the event was cancelled" (untrue) and the revocation notice. The new context
   logs in its own words and sends nothing, leaving
   `law_reception_revoke_included()` to send the one email that says why.

14. **Manage receptions has no export.** §8.1 asked for the CSV/Excel/PDF trio.
    Denis cut it on sight (14 September 2026): there are three receptions and
    every figure is on the screen, so the trio was furniture — and it kept
    ~3MB of pdfmake in the page to produce it. The BOOKINGS at a reception do
    export, from Manage bookings and from the per-event list, which is where
    somebody wanting a spreadsheet of people actually goes.

15. **The screen needed naming in three stylesheet gates, and was not.** It
    rendered white on white on first sight, because `calendar.css` (which
    carries `.law-dashboard`'s text colour, the tables and the controls
    layout) is gated on a template list in `functions/enqueue.php`,
    `event-form.css` on another in `submission-form.php`, and `auth.css` on a
    third. All three now name `templates/account-dashboard-receptions.php`, and
    `enqueue.php`'s calendar list also gained `templates/account-hub.php`,
    which renders the receptions banner (`.law-strip`) and could not paint it.
    Worth knowing for the next account screen: adding a template is three
    edits, and none of them fails loudly.

16. **Manage receptions' EDITOR is the submission form, not a dashboard**
    (Denis, 14 September 2026). §8.1 described a committee screen in the
    flagship dashboard's shape — a white page section with
    `.law-event-form--light`. Denis asked for the shape of editing an event:
    the filled navy hero, `.auth-hero`, `.law-event-form`, matching
    `templates/account-event-form.php`. A reception IS an event, so editing one
    should look like it. The LIST stays a white dashboard table, because a
    table of facts belongs on white. The screen therefore borrows nothing from
    `law-admin.css` or `flagship-dashboard.css` any more.

17. **Denis's pass over the screens, same day.** Each of these was a change
    on sight, and each is smaller than the reason for it:
    - the editor's "Opening drinks — status: Confirmed" line went. The
      submission form prints a status because a host's event moves through a
      queue; a reception's two statuses mean "on the programme" or "not yet",
      which is the tick box below it.
    - Date, Starts and Ends became one row of three (`.law-row-grid--three`,
      which already existed). They are one decision, and two-up put Ends on a
      line of its own under a hint belonging to Date.
    - the list lost its explanatory paragraph, and the **On programme**
      column. Live is the ordinary case, so badging it made the exception
      harder to spot; a DRAFT now says so beside the name, which is the rule
      `law_booking_card_badge()` already follows.
    - **Included with flagship** became **Free with flagship**: shorter,
      no abbreviation, and it says what it means.
    - the **Price** column shows the bare net the committee typed. The VAT
      arithmetic belongs in the checkout dialog next to the consent to pay it;
      in a table cell it made the column three lines tall and told the
      committee nothing it had not just entered.
    - the include dialog's day moved to its own line under the reception's
      name, in a colour pitched for a WHITE dialog. It was using
      `.law-form-hint`, which is tuned for light text on the navy form and
      washed out to almost nothing.
    - the banner gained a rule under it (`.law-strip-divider`), because on
      both surfaces the next thing is a list it is not part of.

18. **Three bugs the browser pass found, all fixed.** A `test-specialist` run
    through the whole UX on 14 September 2026 — viewing, managing, paying,
    and the committee's view of the result — passed all five groups and turned
    up three real defects, every one of them in a path the unit tests did not
    reach:
    - **wp-admin's ordinary Update button un-scheduled a reception.** The event
      screen writes the slot keys on every save, and
      `law_event_apply_slot_label()` reads an empty label as "clear the dates",
      so a reception — which holds no programme slot — lost `_law_start` and
      `_law_end` on any Update at all, dropped off its calendar day and stopped
      resolving. The flagship already had the guard, with a comment describing
      this exact failure; the receptions joined that screen and inherited the
      hazard without it. The predicate is now
      `law_event_is_managed_by_law()`, and it is deliberately NOT the same
      variable as the flagship's session-recompute, which must still run for
      the flagship alone — running it on a reception would blank the dates from
      the other end. Pinned by
      `ReceptionsDashboardTest::test_a_bare_wp_admin_update_keeps_a_receptions_dates()`.
    - **A rejected discount code refused silently.** The message was rendered,
      in `.law-form-notice`, which `event-form.css` draws as white text for the
      purple hero — invisible inside the white checkout dialog. The script now
      scopes the error to the dialog (`.law-modal__error`, already coloured for
      a light surface), and `law-modal.css` gained the light-mode override for
      `.law-form-notice` that its sibling `.law-form-error` has had since the
      same problem was found there.
    - **A place a 100% code made free sent no confirmation at all.** The
      confirmation waits for an invoice URL, and a free place has no invoice
      and never will, so the delegate got a confirmed place and silence: no
      calendar invitation, and nobody on the committee told. "Nothing to
      invoice" now counts as "nothing to wait for". The same booking was also
      refusing self-cancellation as a "paid place"; the refusal now tests the
      GROSS rather than the status, because there is no refund to protect.

19. **The price block reads like a receipt.** The Price line showed the
    DISCOUNTED net with the reduction under it, so the discount appeared to
    have been taken twice. Price is the list price now, Discount is a signed
    deduction, and price − discount + VAT equals the total.
