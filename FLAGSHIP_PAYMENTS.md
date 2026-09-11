# Flagship conference: applications, payment and discount codes

> **Status: in build, started 10 September 2026.** This document is the
> self-contained build contract, in the manner of `FLAGSHIP_UI.md` and
> `WAITLIST.md`. `EVENTS_FUNC.md` is the living record of the code as it
> stands and must be kept current as this lands (house rule). An agent
> starting cold should be able to build everything below from this document
> plus the code.
>
> Produced from Denis's brief of 10 September 2026, a scan of
> `EVENTS_4.2_SPECS.md` §5, §7, §8 and §9, three exploration passes over the
> working tree (bookings engine, Stripe integration, flagship feature) and two
> rounds of scope decisions. Signatures below were verified against the tree on
> 10 September 2026.

## 0. Context and ground rules

### 0.1 What is being built, and why

Phase 4.1 rebuilt the host side as a custom, CPT-backed module in
`functions/events/`. Phase 4.2 has since delivered attendee bookings
(`EVENTS_BOOKINGS.md`), the waitlist (`WAITLIST.md`) and the flagship event
itself (`FLAGSHIP_UI.md`, 9 September 2026: an admin screen, a committee
dashboard, a public page at `/events/flagship/` and a programme block).

What the flagship deliberately does **not** have is any way to attend it.
`templates/flagship-event.php` sets `$law_cal_no_booking = true`, and
`law_booking_guard_open()` (`functions/events/bookings.php:387`) refuses it
outright with `law_booking_flagship`. It carries no price, no places count and
no payment meta. `EVENTS_FUNC.md` §6 records this as the one open flagship
item.

This build closes it. A delegate applies and saves a card through Stripe
Checkout, the committee reviews, and approval charges the card off-session and
confirms the place. It is the first attendee payment on the site and the last
substantial money path in 4.2.

### 0.2 Decisions settled by Denis on 10 September 2026 (do not reopen)

Recorded because `EVENTS_4.2_SPECS.md` still reads the other way in several
places, and the brief wins.

| Spec | This build |
|---|---|
| §2: flat £500 + VAT | **£550 + VAT**, rising to **£600 + VAT** at **00:00 Europe/London on 17 October**. Denis first said UTC; corrected and accepted, because London is on BST until 25 October, so a UTC cutover would run the cheaper price an hour into the 17th locally |
| §4.2, §4.4: approve / keep on waitlist / decline | **Approve / decline only** |
| §2: capacity is a hard registration stop | Applications are **always** accepted. Running out of places changes the copy only, and queues the applicant |
| §4.4: automatic promotion when a place opens | **No automatic promotion.** Approval is a committee judgement, so a freed place is offered by the committee approving the next applicant |
| §4.3: after a failed charge the place goes to the next applicant | The retry window expiring raises a **committee alert**. It never auto-declines and never reassigns, matching the module's standing "a human decides" posture |
| §7.6: Stripe's standard receipt | A Stripe **invoice**: VAT line, LAW's VAT number, hosted URL and PDF |
| §3.4: "Register" | The control reads **"Apply"**. Spec §3.4 itself reserves "Register" for free events, and this button does not book a place |

Also settled:

1. **The price is locked at application**, not at approval. The delegate
   consented to a figure, so that figure is snapshotted onto the booking the
   way `law_event_snapshot_fee()` freezes a host fee.
2. **One application per person.** No colleagues, unlike the hosted-event form
   which allows three, so the committee reviews each person individually and
   each card is charged separately (spec §4.1).
3. **The committee can approve beyond the places count**, deliberately, exactly
   as `law_waitlist_promote()` over-books a hosted event today.
4. **A committee-only "add without payment" action** for speakers, press,
   sponsors and VIPs: confirmed with no card and no invoice, marked
   complimentary so exports and counts can tell them apart.
5. **Paid bookings get their own committee page**, separate from the
   hosted-event "Manage Bookings", which must exclude the flagship.
6. **A saved card is managed only on the booking that needs it**, never in a
   global profile section. A global remove would silently break a pending
   approval.
7. **No discount codes on the flagship.** A discount catalogue is built and
   kept (Denis: "we still will need discount code in the future, so leave
   discount cpt, but we just won't use it for flagship"), but nothing here
   accepts one: the application form has no code field and no booking carries
   discount meta. See §13 for what exists and how a future flow opts in.
8. **`law_booking_guard_open()`'s blanket flagship refusal STAYS.** §8 below
   originally said it comes down. It does not, and the reversal is recorded
   here rather than only in a code comment: that refusal is what keeps the
   hosted booking form, "add a colleague", register-on-behalf and the whole
   waitlist off an event where a place is a committee decision and a charge.
   The application flow has its own guard set, `law_flagship_guard_open()`.

### 0.3 Dependency: the new My bookings page

Another agent is building a unified **My bookings** page that will hold hosted,
flagship and (later) reception bookings in one place. **Every attendee-facing
surface in this document lands on that page**, not on the `/account/events/`
section it replaces. Before building §6, re-read that page as built and adopt
its item shape, its status badges and its notice map rather than introducing a
parallel vocabulary.

### 0.4 Confirmed, for the record

The hosted-event waitlist status is real and stays untouched: `law-waitlisted`,
labelled "Waitlisted" by `law_booking_statuses()`, with
`_law_waitlist_position` and automatic FIFO promotion in `waitlist.php`. **The
flagship must not reuse it.** That machinery promotes and charges the moment a
place frees, which is precisely what an approval gate cannot do, which is why
`law-applied` is a separate status and why `law_waitlist_process()` must never
run against the flagship.

### 0.5 House rules that apply throughout

- UK English, sentence case for headings and labels, no em dashes. Wording is
  **"places"**, never "tickets".
- Every action follows the **AJAX modal pattern**: `admin_post_<action>` plus
  `admin_post_nopriv_<action> => law_events_nopriv_json`;
  `law_events_guard_post()` (nonce, honeypot `law_website_url` with a
  pretend-success payload, rate limit) then `law_events_respond()`. Confirm
  dialogs via `parts/layout/modal.php` rendered **inside** the form they
  confirm; destructive confirms `button alert` with the close button relabelled.
  No native `required` inside hidden dialogs (aria-required plus server
  validation). Plain POST must keep working without JS.
- **Every mutation and refusal is logged** to the flagship event's activity log
  via `law_event_log()`, WooCommerce-order-notes style, with `source` and
  `booking => <id>`. This is doubly binding here: it is a money path.
- **Every email is a registry entry** in `law_events_email_registry()`, editable
  on the Emails screen, sent through `law_events_send()`. Never interpolate
  applicant-supplied text into a subject; subjects are not escaped.
- **Concurrency**: every shared-state read and write runs inside
  `law_booking_lock( $event_id )`. Slow work (password hashing, emails, Stripe
  calls that are not part of the guarded decision) runs after unlock.
- Booking **status writes** go through `law_booking_set_status()`, which saves
  and restores `$GLOBALS['law_booking_transitioning']`.
- **List data is a flat table.** **Exports always offer CSV, Excel and PDF**,
  reusing `functions/events/export.php`.
- Admin tools live under the existing LAW menu; the events CPT menu holds
  Bookings and Flagship. **Do not touch `wp-content/mu-plugins/`.**
- First-party JS lives in `assets/js/`, never `assets/js/vendor/`.
- Browser QA goes to the **test-specialist** agent (Playwright), never
  browser-tester; add `.playwright/` and `.playwright-cli/` to `.gitignore`
  before any run. Emails are checked in **Mailpit**.

---

## 1. Reuse inventory (verified 10 September 2026)

Nothing below is re-implemented. Where a need already has a solution, the
existing one is used; where a behaviour is found duplicated in two places
already, it is consolidated into one helper rather than gaining a third copy.

| Need | Existing solution |
|---|---|
| Handler plumbing | `law_events_guard_post( $nonce_action, $args )`, `law_events_respond( $is_ajax, $ok, $payload, $notice )`, `law_events_nopriv_json()`, `law_events_honeypot_field()`, `law_events_redirect_back()` (`request.php`) |
| Rate limiting | `law_events_rate_limit_ok( $surface, $user_id, $max, $window, $ip_max )` |
| Concurrency | `law_booking_lock( $event_id )` / `law_booking_unlock( $event_id )` |
| Status writes | `law_booking_set_status( $booking_id, $status )` |
| Booking numbers | `law_bookings_next_numbers( $count )`, `law_events_bump_counter( $option, $by )` |
| Account resolve-or-create | `law_booking_resolve_attendee_user( $row, $event_id, $actor )`, `law_booking_grant_attendee_role()`, `law_events_create_host_user()`, `law_events_password_setup_link()` |
| Duplicate guard | `law_booking_guard_duplicates( $event_id, $people, $statuses, $taken )`, `law_booking_taken_index()`, `law_booking_holding_statuses()` |
| Places accounting | `law_event_recount_attendees()`, `law_event_tickets_remaining()`, `law_event_attendee_total()` |
| Refusal shape for inline errors | `law_booking_error_payload( WP_Error $error )`, consumed by `assets/js/booking-form.js` |
| Refusal logging | `law_booking_log_refusal( $event_id, $booking_id, $error, $actor_id, $source )` |
| Stripe HTTP, idempotency, signature | `law_stripe_request( $method, $path, $body, $idempotency_key )`, `law_stripe_verify_signature()`; the resume-safe step sequence in `law_stripe_invoice_steps()`; the `law_stripe_request_mock` filter seam for tests |
| Money and VAT | `law_events_format_pence()`, `law_events_vat_rate()` (0.20, filterable), `law_events_setting( 'tax_rate_id' )` |
| Snapshot-a-price precedent | `law_event_snapshot_fee()` / `law_event_resnapshot_fee()` (`fees.php`) |
| Emails and invites | `law_events_email_registry()`, `law_events_send()`, `law_booking_send_with_ics()`, `law_booking_email_placeholders()`, `ics.php` |
| Logging | `law_event_log( $post_id, $message, $context, $args )` |
| Exports | `law_events_send_csv()`, `law_events_send_xlsx()`, the `format=json` plus pdfmake path in `export.php`, `law_events_csv_guard()` |
| Filter bars and partials | the events dashboard markup driven by `assets/js/calendar-filters.js` over `&law_partial=1` |
| Tables | `.law-booking-table` and the `.law-dashboard` idiom |
| Modals | `parts/layout/modal.php`, `assets/js/law-modal.js`, `window.lawModal.redirect()` |
| Form fields | `parts/events/event-form-fields.php`'s `.law-row-grid` / `.law-row-wide` vocabulary |
| Flagship data layer | `law_flagship_event_id()`, `law_flagship_is()`, `law_flagship_date()`, `law_flagship_form_values()`, `law_flagship_input_from_post()`, `law_flagship_validate()`, `law_flagship_save()`, `law_flagship_snapshot()`, `law_flagship_log_save()` |
| Page provisioning | `law_migration_page_map()`, `law_setup_account_pages()`, the `law_setup_*_dashboard_access()` Members copy |
| Time-boxed batch work | `law_bookings_cancel_all_for_event()` and its `law_bookings_resume_cancel_sweep` cron |
| Committee gates | `law_user_is_committee()`, `law_user_can_manage_event()` |

---

## 2. Data model

### 2.1 Booking statuses (`functions/events/statuses.php`)

`law_booking_statuses()` today is `publish` => **Confirmed**, `law-waitlisted`
=> Waitlisted, `law-cancelled` => Cancelled. **A paid application lands on
`publish`, which already reads as "Confirmed" on every surface** — there is no
new status for the happy path. Three are added:

| Status | Label | Meaning |
|---|---|---|
| `law-applied` | Awaiting review | Submitted; card saved, or free via a 100% code |
| `law-declined` | Declined | Committee declined; saved card detached |
| `law-payment-failed` | Payment failed | Approved, but the off-session charge failed or needs authentication |

All three are registered inside `law_events_register_statuses()` (init, priority
6), beside `law-waitlisted`, using the same args. This is not optional:
`WP_Query` silently drops an unregistered `post_status`, leaving no status
clause at all, which would return every booking of every status.

`law_flagship_application_statuses()` returns just the flagship set, so
hosted-event surfaces keep their three-word vocabulary and only the flagship
views see the new labels.

Two guards extend:

- `law_booking_holding_statuses()` (`bookings.php:554`) counts `law-applied` and
  `law-payment-failed` as holding a place, so a person cannot apply twice or
  book a colleague onto an application.
- The `wp_insert_post_data` status guard and the `wp_untrash_post_status`
  whitelist in `workflow.php` (around :124) admit all three, or quick edit and
  untrash will silently revert or strand them.

### 2.2 Flagship settings, edited by the committee

Denis's ask. These go on the **Manage flagship** form
(`parts/events/flagship-manage.php`) and, through the shared saver
`law_flagship_save()`, on the wp-admin Flagship screen too, so the two cannot
drift. `FlagshipDashboardTest::test_both_screens_share_one_write_path()` already
pins that split and must keep passing.

| Field | Meta key | Sanitiser | Default |
|---|---|---|---|
| Available places | `_law_tickets_available` | `int` | 0, which means "no limit set" rather than "none left" |
| Price before the switch, excluding VAT | `_law_flagship_price_pence` | `int` | 55000 |
| Price after the switch, excluding VAT | `_law_flagship_price_late_pence` | `int` | 60000 |
| Price switches at | `_law_flagship_price_switch` | `datetime` | `{programme year}-10-17 00:00` |

Rendered as a "Bookings and pricing" fieldset in the `.law-row-grid` vocabulary
the rest of the flagship form uses, with a hint that VAT is added at the
standard rate on top and a preview line reading "Delegates pay £660.00
including VAT until 16 October, then £720.00". Prices are typed in pounds and
stored in pence.

Validation, added to `law_flagship_validate()`: each price parseable **or left
blank** (blank means "leave the stored value alone"), the switch date
parseable, and the places count not below the number of already-confirmed
bookings. A price of **0 is deliberate and allowed** — it means "not on sale",
and the control's first state depends on it — so only a non-numeric typo is
refused, because reading one as 0 would put the conference on sale for
nothing.

The two prices live on the event rather than in `law_events_settings` because
the committee edits them where they already work, and because a future
reception will want its own pair rather than a site-wide one.

### 2.3 Pricing helper (`functions/events/flagship.php`)

```php
/** Net pence at a timestamp (site time). */
function law_flagship_price_pence( $at = 0, $event_id = 0 ): int
/** True once the switch datetime has passed. */
function law_flagship_price_is_late( $at = 0, $event_id = 0 ): bool
/** The cutover as a true epoch, 0 when unset or unparseable. */
function law_flagship_price_switch_ts( $event_id = 0 ): int
```

VAT is deliberately not here: `law_events_gross_pence()` and
`law_events_vat_pence()` live in `fees.php`, because VAT is not a flagship
idea and a reception wants the same arithmetic.

`$at` defaults to `current_time( 'timestamp', true )` — the **true** epoch,
not the site-shifted number `current_time( 'timestamp' )` returns, which is
not a real timestamp and would reintroduce the very hour of drift this
section exists to prevent. Every comparison is built
through `wp_timezone()` and `DateTimeImmutable`, never `strtotime()` on a bare
string, so the BST/GMT boundary cannot shift the cutover by an hour. **This is
the theme's first date-based price switch; there is no pattern to copy**, so it
carries its own tests either side of the boundary.

### 2.4 Booking meta (`law_booking_meta_schema()`)

| Key | Sanitiser | Purpose |
|---|---|---|
| `_law_application_at` | `datetime` | when the form was submitted |
| `_law_price_pence` | `int` | list price snapshot, net of VAT, frozen at application |
| `_law_vat` | `flag` | mirrors the event path |
| `_law_payment_consent_at` | `datetime` | explicit consent to save and charge (spec §4.1, §7.4) |
| `_law_stripe_customer_id` | `text` | the **user's** customer, copied onto the booking for the audit trail |
| `_law_stripe_setup_intent_id` | `text` | |
| `_law_stripe_payment_method_id` | `text` | the saved payment method |
| `_law_stripe_method_type` | `text` | `card`, `link`, `revolut_pay`, … — whatever Stripe returned |
| `_law_stripe_method_label` | `text` | what the delegate is shown ("Visa ending 4242, expires 04/2029", "Link (a@b.com)", "Revolut Pay") |
| `_law_stripe_card_brand` / `_law_stripe_card_last4` / `_law_stripe_card_exp` | `text` | card only, and empty for every other method |
| `_law_stripe_invoice_id` | `text` | |
| `_law_stripe_invoice_url` / `_law_stripe_invoice_pdf` | `url` | the VAT receipt, surfaced to delegate and committee (spec §7.5) |
| `_law_stripe_charge_id` | `text` | refund reconciliation, as events do |
| `_law_payment_status` | `text` | `pending_setup` / `ready` / `processing` / `paid` / `failed` / `action_required` / `refunded` / `complimentary` |
| `_law_payment_error` | `text` | last decline message, for the notice and the email |
| `_law_payment_failed_at` | `datetime` | drives the retry window |
| `_law_reviewed_at` | `datetime` | |
| `_law_reviewed_by` | `int` | |
| `_law_decline_reason` | `multiline` | |
| `_law_is_complimentary` | `flag` | committee-added free place, or a 100% code |
| `_law_application_answers` | `text_array` | salutation, dietary, accessibility, organiser-added questions |

`_law_payment_status` takes a new `booking_payment_status` sanitiser rather than
reusing the event `payment_status` one, whose vocabulary is
`unpaid`/`paid`/`refunded`/`free`.

Name, email, organisation and job title reuse the existing `_law_attendee_*`
keys, so the exports, the wp-admin booking screen and the dashboards read a
flagship application for free.

---

## 3. Stripe (`functions/events/stripe/`)

The existing client is a raw `wp_remote_request` wrapper, **not** the Stripe PHP
SDK. `.claude/agents/stripe-specialist.md` claims otherwise and is stale;
correcting it is part of this work. Everything below extends `client.php`
rather than adding a second integration.

### 4.1 A user-level Stripe customer (new `stripe/attendees.php`)

`_law_stripe_customer_id` is currently **per event**, upserted from a
host-supplied invoice email and guarded by `law_stripe_customer_ownership()`.
Attendee payments need a customer per **user**.

```php
law_stripe_user_customer_id( $user_id ): string|WP_Error
```

User meta `_law_stripe_customer_id`, else an exact email search restricted to
customers whose metadata carries `law_user_id`, else create. Metadata
`law_user_id` and `law_site`.

It deliberately **does not** adopt an unclaimed email-matched customer the way
the event path does. The event path adopts because a host's invoice email may
legitimately predate us in Stripe from the legacy system; an attendee account
has a verified WordPress email and no such history, and adopting would let a
delegate attach their card to a customer record built for someone else.

### 4.2 Saving the payment method

Stripe **Checkout in `mode: 'setup'`** (spec §7.1, §7.4: Stripe's hosted page,
3DS handled by Stripe at the point the method is saved, PCI scope stays off
LAW).

**Not necessarily a card.** `payment_method_types` is deliberately left unset,
so Checkout offers whatever the LAW Stripe account has enabled that can be
saved and re-charged off-session. Staging offers card, Link and Revolut Pay
today; production is LAW's to configure and Denis expects it to differ
(10 September 2026). Spec §7.1 says the same: "cards, bank transfer, Apple Pay
and the other methods enabled on the LAW Stripe account (all except Klarna)".

Two rules follow, and everything in this document is written to them:

1. **Nothing assumes a card.** `law_stripe_method_label()` describes whatever
   Stripe returned — the card with its brand and last four, the wallet name
   when a card carries one, Link with its email, a bank debit with its last
   four — and falls back to the humanised type name for anything it has never
   seen, so a method Stripe adds next year reads as "Amazon Pay" rather than
   as a blank. `law_booking_payment_method_label()` is what surfaces read;
   `law_booking_card_label()` survives as an alias.
2. **A charge may not settle inside the request.** See §4.3.

**The one thing the Dashboard must respect** is that every method offered here
supports recurring / merchant-initiated payments, because the charge runs
later with nobody at the keyboard. Klarna is excluded by the spec. This is a
Dashboard setting, not a code setting, so it belongs on the go-live checklist.

```php
law_stripe_create_setup_session( $booking_id, $reason = 'apply' ): string|WP_Error
law_stripe_attach_setup_result( $booking_id, $session_id ): true|WP_Error
```

- `POST /v1/checkout/sessions` with `mode=setup`, `customer`,
  `billing_address_collection=required`, and both `metadata` and
  `setup_intent_data[metadata]` carrying `law_booking_id`, `law_event_id`,
  `law_user_id` and `reason` (`apply` / `replace` / `retry`). Success and cancel
  URLs return to My bookings. Idempotency key
  `law-setup-{booking_id}-a{attempt}`, matching `service.php`'s `$idem` closure.
- **One function serves all three cases**, so applying, changing the method
  and retrying a failed charge share a code path rather than drifting.
- **Three requests report one saved method**, and only one may act on it:
  the `checkout.session.completed` webhook, the `setup_intent.succeeded`
  webhook and the delegate's own browser returning from Checkout, all within
  milliseconds. `law_flagship_on_card_saved()` therefore **claims** its
  one-shot latch — the event lock plus `add_post_meta( …, $unique = true )` —
  rather than reading it and then writing it, and
  `law_stripe_store_payment_method()` runs its "already stored" check under
  the same lock. A read-then-write latch let all three pass and put two of
  every application email in the committee's inbox (10 September 2026).
- `law_stripe_attach_setup_result()` polls the session once on return so the
  delegate sees the right state even when the webhook is slow; the webhook stays
  authoritative and both paths are idempotent. It also stores the method type,
  the rendered label, and — for cards only — the brand, last four and expiry.
- **Skipped entirely when the net payable is 0** (a 100% code): no session, no
  payment method, `_law_payment_status = 'complimentary'`.

### 4.3 Charging on approval

```php
law_stripe_charge_booking( $booking_id ): array|WP_Error
law_flagship_mark_paid( $booking_id, array $invoice, $stripe_event_id = '', $actor_id = 0 ): bool
law_stripe_void_booking_invoice( $booking_id, $actor = 0 ): bool
```

Generic names, not flagship ones: a priced reception reuses all three.

Modelled step for step on `law_stripe_invoice_steps()` so the resume-on-retry
behaviour is identical: a finalised invoice is reused, a leftover draft is
deleted, and the invoice ID is persisted before the line item so nothing
double-bills.

1. Upsert the user customer; set `invoice_settings[default_payment_method]`.
2. `POST /v1/invoiceitems` — `amount` = the **snapshot** `_law_price_pence`
   minus `_law_discount_pence`, `currency=gbp`, and `tax_rates[]` =
   `law_events_setting( 'tax_rate_id' )` when `_law_vat`. The description names
   the event and, when a code was used, the list price and the code:
   "London Arbitration Week flagship conference, 2 December 2026 — £550.00 less
   discount code SPEAKER10". A VAT-liable charge with no tax rate configured
   **hard-fails** rather than billing net-only, exactly as `service.php:35-39`
   does.
3. `POST /v1/invoices` — `collection_method=charge_automatically`,
   `auto_advance=false`, `default_payment_method`, metadata `law_booking_id`,
   `law_event_id`, `law_user_id`.
   Every step's idempotency key carries an **attempt number**, counted once
   per deliberate charge and before the resume branch so the pay step gets a
   fresh one too. A retry after a decline is a genuinely different request —
   usually a different payment method — and Stripe refuses a key replayed
   with changed parameters. Keying `/pay` on the booking and invoice alone
   made the whole retry fail with "Keys for idempotent requests can only be
   used with the same parameters they were first used with", which was then
   stored as the delegate's decline reason and emailed to them. The key was
   never what prevented a double charge: that is the resume GET, which
   returns a paid invoice untouched however many times it is asked.

4. Finalise, then `POST /v1/invoices/{id}/pay` with `off_session=true` and
   `expand[]=payments.data.payment.payment_intent`. The expansion is not
   optional: `Invoice.payment_intent` was **removed** in `2025-03-31.basil`
   and the client pins `2025-09-30.clover`, so the intent's status only exists
   three levels down. `law_stripe_invoice_intent_status()` reads it.
5. Paid → `law_flagship_mark_paid()`. Otherwise, the two non-paid outcomes are
   told apart, because they mean opposite things to the delegate:

   | PaymentIntent | Meaning | What happens |
   |---|---|---|
   | `processing` | The payment was accepted and is settling. Normal for the non-card methods §4.2 allows; a card never lands here. | `law_flagship_mark_payment_processing()`. The booking **stays** `law-applied` holding its place, `_law_payment_status = 'processing'`, no error stamped, **no email**, and `invoice.paid` confirms it when the money lands. The row is not decidable and the delegate cannot withdraw or swap the method under a live charge (`law_flagship_payment_in_flight`). |
   | anything else | SCA: the bank wants the delegate present. | `law_flagship_mark_payment_failed( …, 'action_required' )` → `law-payment-failed`, and §4.4 runs. |

   A genuine decline is a `WP_Error` from the pay call and goes to
   `law-payment-failed` with `_law_payment_error` and `_law_payment_failed_at`.

   **Whose fault is it?** `law_flagship_is_configuration_error()` decides,
   and the answer changes everything that follows: the delegate's problem is
   written onto the booking, shown on the committee's row and emailed to them
   verbatim, while ours stops with the application untouched and alerts an
   admin. Every Stripe API failure arrives as the single code
   `law_stripe_api_error`, so the classifier reads the Stripe error **type**
   carried in the WP_Error data: `idempotency_error`, `invalid_request_error`,
   `authentication_error`, `api_error` and `rate_limit_error` are ours;
   `card_error` and anything unrecognised stay with the delegate, so a real
   decline in an unfamiliar shape is never swallowed into an admin email
   nobody is waiting for.

   Calling a settling payment a failure would be wrong twice over: it would
   flag the committee's row red and email the delegate to go and see their
   bank about a payment that is working.

### Never twice

The two invariants, and what actually enforces each. Audited 10 September 2026
and pinned by tests in `FlagshipPaymentsTest.php`.

**One invoice per booking.** The invoice id is persisted BEFORE its line item,
and every attempt begins by GETting that invoice: `paid` is returned
untouched, `open`/`uncollectible` is PAID rather than replaced, a leftover
`draft` is deleted first, and a GET that FAILS aborts the whole attempt rather
than falling through to create a second one. Two committee members approving
at once are serialised by `law_booking_lock()` for the decision and by
`law_flagship_claim_charge()` — one atomic `add_post_meta( …, $unique )` — for
the charge, so the loser never reaches Stripe. A duplicated id inside one bulk
batch is deduplicated before anything runs.

**One payment per invoice.** Stripe itself refuses to pay an invoice that is
already paid, which is the backstop. Above that: a lost response leaves the
booking unconfirmed, and the next attempt's resume GET sees `paid` and
confirms without paying; a payment still `processing` refuses approval
outright (`law_flagship_payment_in_flight`) rather than resuming onto an
invoice whose payment is in flight; and `law_flagship_mark_paid()` is
idempotent, returning early once the booking is `publish`.

**Deciding under a live payment is refused, both ways.** Approve, retry,
decline and withdraw all return `law_flagship_payment_in_flight` while
`_law_payment_status` is `processing`. Approving would resume onto an invoice
whose payment Stripe is already collecting; declining or withdrawing would
detach the method and void that invoice underneath the money. The dashboard
hides those controls too, but hiding a control has never been the guard: a
stale page or the no-JS form reaches the functions directly.

**Nothing stays stuck silently.** Because every action on a `processing`
booking is refused, a dropped webhook would leave it holding a place with
nobody told. `law_flagship_mark_payment_processing()` stamps
`_law_payment_processing_at`, and the daily sweep alerts the committee once
after `LAW_FLAGSHIP_PROCESSING_ALERT_DAYS` (5 — generous, because a bank debit
legitimately takes several working days). It **alerts and never decides**, the
same posture as the overdue-payment sweep.

**The one residue, accepted.** If `POST /v1/invoices` succeeds at Stripe but
the response is lost, the id was never persisted and the next attempt creates
a fresh invoice under a new attempt key. That leaves an orphaned DRAFT with no
line items, `auto_advance=false`, and therefore no money: it can never be
finalised or paid. Removing it would mean persisting a key before the call,
and pinning the create key across attempts would break the void-then-recharge
path, so the orphan stays. It is Stripe-dashboard clutter, not a charge.

`law_flagship_mark_paid()` is the **single** function that sets status
`publish`, stores the invoice ID, `hosted_invoice_url`, `invoice_pdf` and charge
ID, writes `_law_payment_status = 'paid'`, recounts places and sends the
confirmation with its `.ics`. It is **idempotent** and is called from **both**
the synchronous pay response and the `invoice.paid` webhook, so a booking
reaches Confirmed exactly once whichever arrives first.

Every step logs to the flagship event's activity log.

### 4.4 Webhook (`stripe/webhook.php`)

The signature check, the rolling 500-event processed list and the per-event lock
all stay exactly as they are.

- **Route by metadata, not by type.** `invoice.paid`, `invoice.payment_failed`,
  `invoice.voided` and `charge.refunded` each gain a `law_booking_id` branch
  beside today's event branch. Add
  `law_stripe_resolve_booking_id( array $object )` next to
  `law_stripe_resolve_event_id()`, validating the resolved post type is
  `LAW_BOOKING_CPT`.
- **New**: `checkout.session.completed` (mode `setup`) and
  `setup_intent.succeeded` → store the payment method and the card display
  fields, flip `_law_payment_status` from `pending_setup` to `ready`, send the
  delegate's acknowledgement and notify the committee.
- **New**: `setup_intent.setup_failed` → the card could not be saved (3DS
  abandoned or refused). Without it a `pending_setup` application sits silently
  until the 48-hour sweep.
- **New**: `invoice.payment_action_required` → the bank wants SCA on the
  off-session charge. Handled as a **variant of §4.4**, not as a decline.
- `payment_intent.payment_failed` on a retry → §4.4.
- **Fix while here** (`EVENTS_FUNC.md` §6, open finding 2): the
  `charge.refunded` branch ignores `amount_refunded` versus `amount`, so a
  goodwill part-refund flips the whole thing to Refunded. Fixed for events and
  not inherited by bookings.

**Local forwarding.** Four types are added to what Denis currently forwards, so
the replacement command is:

```
stripe listen \
  --events invoice.paid,invoice.payment_failed,invoice.payment_action_required,invoice.voided,invoice.marked_uncollectible,charge.refunded,checkout.session.completed,setup_intent.succeeded,setup_intent.setup_failed,payment_intent.payment_failed \
  --forward-to http://localhost/law/wp-json/law/v1/stripe-webhook
```

The live Stripe dashboard endpoint needs the same four adding before go-live.
Note that `stripe listen` prints its **own** signing secret, different from a
dashboard endpoint's: while forwarding locally, `LAW_STRIPE_WEBHOOK_SECRET` in
`wp-config.php` must hold the CLI's, or `law_stripe_verify_signature()` rejects
every event and it looks like a broken handler.

---

## 4. The application flow

### 5.1 The control on the flagship page

`templates/flagship-event.php` drops `$law_cal_no_booking = true`, and
`law_booking_guard_open()` loses its blanket flagship refusal, replaced by a
flagship branch routing to the application guards. Hiding a button was never
the control and must not become it: the new guard set is what protects the flow.

`law_flagship_render_action( $event )` in a new `functions/account-flagship.php`
(mirroring `functions/account-bookings.php`), rendered through
`parts/calendar-event-details.php`'s footer row. States, in resolution order:

1. **Not open** — no price or no date set.
2. **You're attending** — the viewer holds a `publish` booking. Links to the
   booking and its VAT receipt.
3. **Payment needs attention** — `law-payment-failed`, see §4.4.
4. **Your application is being reviewed** — `law-applied`.
5. **This event has taken place** — past `_law_start`.
6. **Apply** — places remain. Shows the price including and excluding VAT and,
   before the cutover, "£550 + VAT until 16 October".
7. **Apply (event full)** — places exhausted. Denis's copy: the event is full,
   but you can still apply and you will be in the queue until a place appears.
   Same button, different supporting text. **Applications are never refused for
   capacity.**

The programme block (`parts/events/flagship-card.php`) keeps its "Event details"
button only: one call to action per surface.

### 5.2 The application form

`parts/events/flagship-apply-modal.php`, reusing
`parts/events/booking-modal.php`'s skeleton so `law-modal.js` open, close and
focus-trap behaviour comes free, with a `?law_flagship_apply=1` no-JS inline
fallback (the `?law_book=1` precedent) and the transient form-state round-trip.

Fields per spec §4.1, pre-filled from `law_profile_values()` for returning
delegates: salutation, first name, surname, job title, organisation, work
country, dietary requirements, access requirements, plus any organiser-added
questions. Dietary and accessibility read from the profile as everywhere else
but are **editable here** and written back, because the spec asks for them at
application. Salutation is a new profile field.

**There is no discount code field** (§0.2 item 7). The form does carry a
hidden `price_shown`, the net price it displayed: `law_flagship_apply()`
refuses rather than repricing if the figure has moved since the page was
rendered, so a delegate who had it open across the cutover is never charged
an amount they did not see and did not consent to.

Then two required consents, both timestamped onto the booking and logged:
registration terms and conditions, and explicit consent to store the card and
charge it if the application is approved (§4.1, §7.4).

### 5.3 The engine (`functions/events/flagship-bookings.php`)

```php
law_flagship_apply( $user_id, array $input ): array|WP_Error   // [ booking_id, redirect ]
law_flagship_approve( $booking_id, $actor_id, array $args = array() ): array|WP_Error
law_flagship_decline( $booking_id, $actor_id, $reason = '' ): true|WP_Error
law_flagship_withdraw( $booking_id, $actor_id ): true|WP_Error
law_flagship_add_complimentary( array $row, $actor_id ): int|WP_Error
law_flagship_retry_charge( $booking_id, $actor_id ): array|WP_Error
law_flagship_review_bulk( array $booking_ids, $decision, $actor_id, $reason = '' ): array
law_flagship_applications( array $filters = array() ): array
```

- **`law_flagship_apply()`**: guards (the flagship is published, a price and a
  date are set, it has not started, and the person holds no live application via
  `law_booking_guard_duplicates()` across the holding statuses), validate and
  claim any discount code, snapshot the price, insert one `law_booking` with
  status `law-applied` and `_law_payment_status = 'pending_setup'` (or
  `'complimentary'` when free), claim a number with
  `law_bookings_next_numbers( 1 )`, log, and return the Checkout URL, or the
  success state directly when free. All inside `law_booking_lock( $flagship_id )`;
  the Stripe call happens after unlock.
  - **No clash guard.** The flagship runs all day, and every other event on
    2 December would collide with it. A deliberate, recorded omission.
  - An abandoned Checkout leaves a `pending_setup` application the delegate can
    resume from My bookings. A daily cron
    (`law_flagship_sweep_abandoned_applications`) closes ones older than 48
    hours and releases their discount claim. Only a `ready` (or free)
    application reaches the committee's queue.
- **`law_flagship_approve()`**: re-check under the lock; when approving beyond
  `_law_tickets_available`, require `$args['confirm_overbook']` and log
  `flagship_overbooked` loudly with the counts and the actor; then charge,
  transition, email and recount. The capacity guard is the **only** one
  bypassed, exactly as `law_waitlist_promote()` does.
- **`law_flagship_decline()`**: `law-declined`, detach the payment method
  (`POST /v1/payment_methods/{pm}/detach`), release the discount claim, email,
  log.
- **`law_flagship_withdraw()`**: the delegate self-withdrawing before the charge
  (spec §4.4, §8) — `law-cancelled`, detach the card, release the code, email.
  Refused once `publish`; cancelling a paid place is a committee decision.
- **`law_flagship_add_complimentary()`**: committee-only, straight to `publish`
  with `_law_is_complimentary`, no card and no invoice, reusing
  `law_booking_register_by_manager()`'s account resolve-or-create so a new
  attendee still gets an account and a set-password link. Its `profile` key
  carries the country / accessibility / dietary set the dialog now collects
  (Denis, 11 September 2026), written onto the attendee's account by
  `law_booking_apply_attendee_profile()` once the place is theirs: the delegate
  list and the exports read those columns live from the profile, and somebody
  put on the list here never filled a registration form in. Country is optional
  on this dialog, which asks only for a name and an email.
- **`law_flagship_review_bulk()`** is **time-boxed and cron-resumable**,
  modelled on `law_bookings_cancel_all_for_event()`'s 15-second box and
  `law_bookings_resume_cancel_sweep`. Twenty off-session charges in one request
  would otherwise hit a PHP timeout half-done, leaving some delegates charged
  and unconfirmed.

### 5.4 Failed payments: where they are handled

A booking in `law-payment-failed` is visible and actionable in four places, all
reading the same `_law_payment_error` / `_law_payment_failed_at` pair.

1. **My bookings** — a `.law-form-notice is-error` panel: what happened in plain
   words ("Your card was declined: insufficient funds"), the card on file
   ("Visa ending 4242"), the deadline ("Please update your card by 17 October or
   your place may be released"), and a primary **"Update payment method"**
   button opening a fresh Checkout setup session (`reason=retry`). A secondary
   "Withdraw my application" sits beside it. Returning from Checkout with a new
   card **retries the charge immediately**, so one round trip fixes it.
2. **The flagship page control** — state 3 above: the same message and the same
   button, so a delegate who lands on the event rather than their account is not
   stranded.
3. **Email** — `user_flagship_payment_failed`, carrying `{payment_error}`,
   `{payment_deadline}`, `{card_label}` and `{update_payment_link}`.
4. **Flagship bookings (committee)** — the row carries a Payment failed badge
   with the decline reason, a **"Resend payment request"** action re-sending the
   email, and **"Retry charge"** for when the delegate has already fixed things
   at their bank. The committee never sees or handles card details.

**The SCA variant.** `invoice.payment_action_required` uses the same panel and
the same status with `_law_payment_status = 'action_required'`, different copy
and a different button: "Your bank needs you to confirm this payment" and
**"Confirm payment"** linking to the hosted invoice URL. Offering a new card
there would be wrong; the card is fine, the bank simply wants the delegate
present.

**The retry window**: `law_events_setting( 'flagship_payment_window_days' )`,
default 7, drives the deadline wording. When it expires a daily cron sends
`committee_flagship_payment_failed` and logs. It does **not** auto-decline and
does **not** reassign the place, matching the way `invoice.payment_failed` never
auto-unpublishes an event today.

**Before approval**, a `law-applied` booking shows the card on file with a
**"Change card"** link (`reason=replace`). There is deliberately **no global
payment-methods section on the profile**: a card exists only because of an
application, and a global remove would silently break a pending approval.

### 5.5 Handlers

All built on `law_events_guard_post()` and `law_events_respond()`, with `nopriv`
twins on `law_events_nopriv_json()`, the honeypot, rate limits and the no-JS
plain POST fallback.

| Action = nonce | Gate | Rate surface |
|---|---|---|
| `law_flagship_apply` | signed in | `booking` 10/600 per user, 100/600 per IP (shared with create) |
| `law_flagship_setup_return` (GET) | the booking's author | none |
| `law_flagship_update_card` | author; status `law-applied` or `law-payment-failed` | `booking_edit` 15/600, 150 per IP |
| `law_flagship_withdraw` | author; status not `publish` | `booking_edit` |
| `law_flagship_review` (approve / decline, single and bulk) | `law_user_is_committee()` | `flagship_review` 60/600, 300 per IP |
| `law_flagship_retry_charge`, `law_flagship_resend_payment` | `law_user_is_committee()` | `flagship_review` |
| `law_flagship_add_attendee` | `law_user_is_committee()` | `flagship_review` |
| `law_flagship_export` (GET, `csv|xlsx|json`) | `law_user_is_committee()` | none, read-only |
| `law_discount_save`, `law_discount_disable` | `law_user_is_committee()` | `discount_manage` 60/600 |

IDOR rules as everywhere: load the booking, verify `post_type ===
LAW_BOOKING_CPT`, derive the event from `post_parent`, never from POST, and only
then check the actor relationship.

---

## 5. Committee surfaces

Hosted-event bookings and paid bookings are different things and need different
pages (Denis, 10 September 2026).

### 6.1 Manage Bookings stays hosted-only

`/account/dashboard/bookings/` (`functions/events/bookings-dashboard.php`)
**excludes the flagship** from its query, its event picker and its exports. The
per-event list at `/account/events/?law_event_bookings={flagship id}` redirects
to the new page, so flagship mutations have exactly one home.

### 6.2 New: Flagship bookings

`/account/dashboard/flagship-bookings/`, template
`templates/account-dashboard-flagship-bookings.php`, markup
`parts/events/flagship-bookings-list.php`, backing
`functions/events/flagship-bookings-dashboard.php`. Committee-only, gated in
three places, menu item **"Flagship bookings"** after "Manage Bookings".
Provisioned through `law_migration_page_map()`, `law_setup_account_pages()` and
`law_setup_flagship_bookings_access()`.

- **Flat table**, one row per application, with three details riding as a
  sub-line rather than as columns of their own: the applicant's organisation,
  job title and country sit in small text under their name, the application
  date under its number, and the Stripe invoice link under "Paid". The
  decline reason is the exception: it is free text the committee typed, so it
  hangs off the status badge as a `title` rather than printing under it,
  where one long reason set the height of the row. It stays in full in the
  exports and on the wp-admin booking screen. Those three
  had columns once and pushed the actions off the screen; dropping them lost
  information a reviewer decides on, so they came back this way. No column is
  wider than 12rem and every cell wraps, the two exceptions being the tick and
  the actions, which must stay on one line. Full list: booking number, applicant, email,
  organisation, job title, country, status badge, list price, discount code and
  amount, net payable, payment status, applied date, and a link to the Stripe
  invoice where one exists. That last column is spec §7.5's "easy to find for
  whoever handles a refund".
- **Filters** per spec §4.2: keyword, country, surname, organisation, status,
  payment status and complimentary-only. Built from the events dashboard's
  filter markup driven by `calendar-filters.js` over `&law_partial=1`; selects
  only, since that script reads a field's value regardless of its checked state.
- **Actions**: per-row Approve and Decline behind confirm modals (decline
  carries a reason field), the two failed-payment actions from §4.4, and
  select-all checkboxes. **Bulk approve**, **Bulk decline** and **Add an
  attendee without payment** render as inline text links in one row **above
  the table**, because the list can run to hundreds of rows and an action bar
  at the bottom would mean scrolling past all of them to use it. For the same
  reason **nothing at all renders below the table**: the bulk form and the
  add-attendee section sit above it too, and the over-booking warning that
  used to follow it was dropped, since the page's lede already carries the
  same sentence. The two
  decide links sit outside the bulk form and join it with the HTML `form`
  attribute (as the tick boxes do), and are disabled until something is
  ticked. Each opens a confirm dialog; the add-attendee dialog carries the
  full registration form.
- **Header** shows confirmed / available, red when over-booked, matching the
  waitlist section's treatment.
- **Exports** CSV, Excel and PDF through `functions/events/export.php`.
- The wp-admin booking screen (`admin/booking-screen.php`) facts box gains a
  payment block: status, list price, discount, card on file, invoice link,
  consent timestamp, reviewer and the last decline message.

---

## 6. Attendee account (spec §8)

Everything lands on the **new My bookings page** (§0.3), adopting its item shape
and badges rather than introducing a second vocabulary. A flagship item shows
the application's answers, its status, the price and any discount, the VAT
receipt link once paid, the card on file with "Change card" while pending, the
§4.4 failed-payment panel, and "Withdraw my application" before the charge.
Multi-year history and the `.ics` already work through existing code.

---

## 7. Emails (`functions/events/notifications.php`)

New registry entries, editable on the Emails screen, every send logged.
Pre-declare the new tags in the booking-tags block: `{price}`, `{price_vat}`,
`{price_total}`, `{invoice_link}`, `{flagship_bookings_link}`,
`{decline_reason}`, `{payment_error}`, `{update_payment_link}`,
`{payment_deadline}`, `{card_label}`.

| Slug | To | Trigger | .ics |
|---|---|---|---|
| `user_flagship_applied` | delegate | card saved, or a free code applied; acknowledgement, price, what happens next | no |
| `committee_flagship_application` | committee | a new application is ready for review | no |
| `user_flagship_approved` | delegate | charged and confirmed; invoice link, terms (§4.3) | **yes** |
| `user_flagship_declined` | delegate | declined; card removed (§4.3) | no |
| `user_flagship_payment_failed` | delegate | the decline reason, the deadline and the update link (§4.4) | no |
| `user_flagship_action_required` | delegate | the SCA variant; confirm with your bank | no |
| `user_flagship_withdrawn` | delegate | self-withdrawal confirmation (§9) | no |
| `user_flagship_complimentary` | delegate | committee added them free, or a 100% code | **yes** |
| `committee_flagship_payment_failed` | committee | the retry window expired | no |
| `committee_flagship_paid` | committee | a charge landed on a declined or cancelled application; the `committee_cancelled_paid` precedent | no |

---

## 8. Exclusions to unwind, and guards to keep

- **Down**: `law_booking_guard_open()`'s blanket flagship refusal, replaced by
  the application guard set; `$law_cal_no_booking` on
  `templates/flagship-event.php`; and `_law_registration_state` on the flagship
  is finally set to `'apply'`, its first consumer since it was reserved for 4.2.
- **Stay**: the flagship remains excluded from `law_committee_events()` and its
  status counts, and from hosts' `law_account_events()`. It gains no workflow
  transition and no host fee. Approval here is an **attendee** decision, not the
  event workflow, so nothing in `law_event_workflow_actions()` changes.
- `law_waitlist_process()` must **never** run against the flagship; add the
  guard explicitly rather than relying on there being no waitlisted bookings.
- The host form still cannot set `_law_is_flagship`
  (`law_events_form_save()` writes a fixed key map); keep the test that pins it.

---

## 9. Tests

`tests/FlagshipPaymentsTest.php`, `tests/FlagshipBookingsDashboardTest.php` and
`tests/DiscountsTest.php` (the catalogue's own rules, even though nothing calls
them yet), on `LAW_Test_Case`, with Stripe mocked through the
existing `law_stripe_request_mock` filter seam and `law_flagship_event_id`
pinning a fixture. Run with `php -d memory_limit=512M vendor/bin/phpunit`
(128M OOMs during wp-load, which reads as a red suite).

Cases worth naming, each with a comment saying which bug it guards:

1. The price boundary either side of 17 October, **and across the BST/GMT
   edge**, and the snapshot surviving an approval made after the cutover.
2. Applications accepted with zero places remaining; approval beyond capacity
   refused without `confirm_overbook` and logged as `flagship_overbooked` with
   it.
3. Duplicates refused across all four live statuses, by account and by email.
4. Decline and withdrawal detaching the saved card.
5. A failed charge landing in `law-payment-failed` and never in `publish`; a
   replaced card retrying and confirming.
6. `law_flagship_mark_paid()` idempotent across the synchronous pay response and
   the `invoice.paid` webhook.
7. The webhook routing an invoice by `law_booking_id` rather than
   `law_event_id`, and a partial refund not marking a booking fully refunded.
8. Bulk approve resuming after its time box, charging each delegate once.
9. A limited code not being over-claimed by two simultaneous callers, and a
   fixed code larger than the price making a booking free rather than
   negative — the catalogue's rules, tested even though no flow calls them.
10. The flagship staying out of Manage Bookings, the committee event queue and
    hosts' My events throughout.
11. `law_waitlist_process()` no-oping on the flagship.

---

## 10. Build order

1. Reuse inventory (§1) and this document.
2. Statuses (§2.1), meta schema (§2.4), pricing helper (§2.3), flagship settings
   on both screens (§2.2).
3. `stripe/attendees.php` (§3.1 to §3.3).
4. Webhook routing, `law_flagship_mark_paid()`, the partial-refund fix (§3.4).
5. `flagship-bookings.php` (§4.3).
6. Handlers (§4.5), then the public control (§4.1) and the application
   modal (§4.2).
7. The failed-payment surfaces (§4.4).
8. The Flagship bookings page and the Manage Bookings exclusion (§5).
9. My bookings integration (§6, once that page has landed) and the emails (§7).
10. Tests green (§9), then `EVENTS_FUNC.md` updated.
11. The discount catalogue (§13), which is independent of everything above.

## 11. Verification and gates

- `php -d memory_limit=512M vendor/bin/phpunit` green.
- Stripe test mode is already configured (`sk_test_`, tax rate set). Webhooks
  arrive over `stripe listen` with the extended `--events` list in §3.4, and
  `LAW_STRIPE_WEBHOOK_SECRET` set to the CLI's secret for the duration.
- Full round trips against real Stripe test cards: `4242…` happy path;
  `4000 0000 0000 9995` (insufficient funds) for the failed-payment path,
  including replacing the card and the immediate successful retry;
  `4000 0025 0000 3155` for 3DS at card-save time; and `4000 0027 6000 3184`,
  which saves fine but requires authentication off-session, for the
  `invoice.payment_action_required` variant.
- Emails in **Mailpit**, including the `.ics` attachment.
- Gates, in order:
  1. three independent conformance passes of this document against the code;
  2. the **design specialist** over every new and touched surface (the
     application modal, the failed-payment panel, the pricing fieldset, the
     Flagship bookings table, the discount catalogue). Denis's explicit ask: it
     must reuse the module's existing components and read as one system, not as
     a bolted-on payment feature;
  3. the **security-specialist** (a new money path, new handlers, new statuses,
     consent capture, a code-guessing surface);
  4. the **stripe-specialist** (customer scoping, idempotency, off-session
     semantics, webhook routing);
  5. the **test-specialist** Playwright pass with screenshots and a
     colour-contrast check of every touched view.

## 12. Recorded follow-ups, not built here

- **Receptions** (spec §2, §6.1): the Wednesday reception checkbox on flagship
  approval, the £25 flagship-only Monday price and the priority sales window.
  Deferred on 8 September 2026 and still blocked on LAW confirming prices. The
  price snapshot and the discount catalogue (§13) are both generic, so
  receptions reuse them, and flagship approval is the flag those rules will
  read.
- **Refunds**: no automated flow (spec §2). The invoice link on the booking is
  the deliverable.
- **HubSpot**: deferred for 4.2.
- **Salutation** is a new profile field the application writes, but there is
  no control for it on the profile screen yet, so only an application can set
  it. Confirm the choice list with LAW and add the field.
- **A stalled bulk review is invisible.** When a batch exceeds the ten-at-a-time
  cap the tail goes to WP-Cron, and on a site with `DISABLE_WP_CRON` and no
  system cron it would sit unresumed with only a log line to say so. A
  "N applications still queued" banner reading `wp_next_scheduled()` would
  close it if cron reliability ever bites (security review, P3).
- **Discount refusal messages distinguish expired, not-yet-started and used-up
  from unknown.** Harmless while nothing calls `law_discount_validate()`;
  whoever wires the first priced flow should decide consciously whether code
  secrecy matters enough to collapse them, rather than inheriting today's
  wording (security review, info).
- **£550 / £600 is not in any LAW-signed document.** `EVENTS_4.2_SPECS.md` §2
  says £500 + VAT flat, with no early-bird tier. Worth confirming with Emily
  before go-live.

---

## 13. The discount catalogue (built, and deliberately not used here)

`functions/events/discounts.php` and its committee catalogue at
`/account/dashboard/discounts/` are part of this round's work, but they are
**not part of the flagship flow** and must not be wired into it.

- **What exists**: a `law_discount` post type (code as the title, its
  normalised form as the slug, publish/draft for active/disabled), the meta in
  `law_discount_meta_schema()` (percentage or fixed value, an optional
  validity window, a usage limit and counter, an optional event scope, a
  note), and the engine: `law_discount_validate()`, `law_discount_apply()`,
  `law_discount_claim()` / `_release()` (an atomic conditional UPDATE, so a
  limited code cannot be over-claimed by two people at once) and
  `law_discount_log()`.
- **What does not exist**: any caller. No price on the site is reduced by
  anything in that file. The catalogue screen says so in as many words, rather
  than implying the codes are live.
- **How a future flow opts in**: call `law_discount_validate( $code, [
  'event_id', 'user_id', 'price_pence' ] )`, then `law_discount_apply()` for
  the numbers and `law_discount_claim()` under its own lock, releasing on any
  refusal or cancellation. Add the event to the `law_discount_scope_events`
  filter so the committee can limit a code to it. `discounts.php` deliberately
  knows nothing about the flagship or receptions, so it needs no changes.

---

## 14. The review round (10 September 2026)

Three independent conformance passes ran against this document and the code:
the original brief, this contract section by section, and an adversarial
correctness hunt. They found four defects that could have taken the wrong
money from the wrong person. All are fixed, and each is now pinned by a named
regression test in `tests/FlagshipPaymentsTest.php`.

1. **Decline charged the delegate.** The committee's table was ONE form with a
   hidden `decision` field that three unimplemented JavaScript hooks were
   supposed to flip, so every submit posted `approve` — clicking Decline
   approved the applicant and took £660 off their card. Rebuilt: each row's
   Approve and Decline are their own form carrying their own hidden
   `decision`; the bulk form sits outside the table and its tick boxes join it
   through the HTML `form` attribute; the bulk buttons carry `name="decision"`,
   which a native submit sends. `booking-form.js` now appends the submitter's
   name and value to its fetch body, as a browser would, and its submit
   handler is delegated at the document so a table swapped in by a filter
   keeps its behaviour instead of silently falling back to the plain POST.
2. **A declined or withdrawn applicant could pay anyway and be confirmed.**
   Detaching the card left the finalised invoice payable from its own hosted
   page, whose link was already in their inbox. Decline and withdrawal now
   void the invoice, and `law_flagship_mark_paid()` refuses to confirm a
   terminal application — it logs loudly and sends the new
   `committee_flagship_paid` alert instead, so a human decides the refund.
3. **A transient Stripe error billed twice.** The resume step deleted the
   stored invoice ID on ANY error, a 429 or a timeout included, and then
   created a second invoice. It now aborts and keeps the reference, which is
   the only thing standing between a retry and a double charge.
4. **Two approvals raised two invoices.** Nothing serialised the decision. The
   status and capacity checks now run under the event lock, and the charge is
   claimed with an atomic `add_post_meta( ..., $unique = true )` before the
   lock is released, so the slow Stripe round trip never holds it and the
   loser is told to wait.

Also fixed, from the same round: LAW-side configuration failures (a blank tax
rate, an unconfigured key) no longer tell delegates their card was declined or
start their seven-day clock; the cron tail of a bulk approval carries the
committee's own over-booking answer instead of forcing one; the daily sweep no
longer re-closes and re-logs the same abandoned application every day; the
hosted per-event bookings list redirects here for the flagship, so a paid place
cannot be cancelled from a table that knows nothing about the money; the four
required application fields are validated server-side; a price that moved
mid-form is refused rather than silently applied; the wp-admin booking screen
gained the payment block; and a confirmed delegate keeps their receipt link
after the conference has happened.

**The design and security gates then ran.** Security passed with no critical,
high or medium findings; its one actionable low note is now fixed (the three
webhook-called handlers checked only "is this a booking", not "is this a
FLAGSHIP booking", which would have let a future priced reception silently
inherit this file's statuses and meaning — they now fail closed and log it).

The design pass found two real defects, both fixed:

1. **Attendees were being asked to accept the HOST terms.** The application
   form reused `law_events_terms_url()`, which resolves to
   /policies/event-host-terms-conditions/ — a document about arranging a
   venue and paying a £1,200 host fee, none of which applies to somebody
   buying a place. There is now a separate `law_events_attendee_terms_url()`
   and an `attendee_terms_page` setting, and it deliberately falls back to the
   Policies index rather than to the host terms. **LAW still has to write the
   attendee registration terms**; until they do, both screens that expose the
   setting say so in as many words.

   Since 10 September 2026 it is editable from **Manage flagship** as well as
   LAW → Events settings (Denis: the committee lives on that screen and the
   wp-admin field went unnoticed). One option, two doors, so the two cannot
   disagree, and the field shows where the link currently resolves to.
   Two things about it are deliberate and easy to get wrong:

   - Empty means "fall back to the Policies index", which is a real choice,
     so unlike the price fields an empty box DOES clear it. That makes the
     usual "absent or empty means leave it alone" convention unusable, so
     `law_flagship_input_from_post()` includes the key **only when the POST
     actually carried it**. The wp-admin Flagship screen posts through the
     same function and does not render the field; without that check, every
     save there would have silently wiped the link.
   - Validation uses `filter_var()` plus a scheme check, **not**
     `wp_http_validate_url()`. That function is an SSRF guard for outbound
     requests and refuses any host it cannot resolve or that sits in a
     private range; this link is only ever printed in an `href`, so the
     question is "is this a web address", not "may we call it".
2. A duplicated "Back to my bookings" link, stacked on itself, because both
   the account template and this part rendered one.

One thing the design pass raised that is NOT this build's to fix: the brand
orange `#ef7d05` with white button text computes to about 2.8:1, which fails
WCAG AA at the size the theme uses. It is a site-wide design-system colour,
inherited by every button here as by every button elsewhere, and worth a
separate conversation rather than a local override that would make the
flagship's buttons the odd ones out.
