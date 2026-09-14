---
name: receptions-stripe-review-2026-09-14
description: Findings from the stripe-specialist review of the drinks-receptions Stripe work (RECEPTIONS.md), 14 September 2026 — status vs payment_status bug, unverified rendering template field, shared attempt counter.
metadata:
  type: project
---

RECEPTIONS.md is the build contract for LAW's drinks-receptions feature (Monday/Wednesday
paid pay-now, Friday invitation-only), built by another agent on 2026-09-14 on top of
`ROLES_AND_ACCOUNT_HUB.md`. It adds Stripe Checkout in **payment mode** (new — the theme
previously only had `setup` mode for the flagship's save-card-then-charge-later flow) plus a
paid waitlist that reuses the flagship's off-session invoice charge
(`law_stripe_charge_booking()`). §16 is its own "deviations" section, self-aware and mostly
well-reasoned.

Review scope: `functions/events/stripe/attendees.php` (new `law_stripe_create_checkout_session()`,
`law_stripe_expire_checkout_session()`, `law_stripe_get_checkout_session()`),
`functions/events/stripe/webhook.php` (`law_stripe_webhook_booking_outcome()` router),
`functions/events/receptions.php` (mark_paid/processing/payment_failed, release_hold,
continue, charge_promoted, sweep, reconcile_orphan), `functions/events/bookings.php`
(dispatch_payment, claim_charge).

**Findings, ranked:**

1. **Real bug (money-path, narrow race window)**: `law_stripe_expire_checkout_session()`
   (`stripe/attendees.php:383-398`) falls back to a plain GET on the session when `/expire`
   errors, and its two callers — `law_reception_release_hold()`
   (`receptions.php:1384-1394`) and `law_reception_continue()` (`receptions.php:955-959`) —
   both test only `'complete' === $status` before calling `law_reception_mark_paid()`, never
   checking `payment_status`. Per [[checkout-status-vs-payment-status]], `status=complete`
   does not mean the money landed for an async payment method. The correct pattern already
   exists twice in this same codebase — `stripe/webhook.php:192-198`
   (`'paid' === payment_status ? 'paid' : 'processing'`) and
   `law_reception_handle_checkout_return()` (`receptions.php:2614-2627`, which reads
   `$session['payment_status']` correctly) — but was not carried into the expire-fallback
   path. Should call `mark_processing()` when `payment_status !== 'paid'`.

2. **Should-fix, not a "can't know" gap**: RECEPTIONS.md §3.1/§16 item 8 marked
   `invoice_creation[invoice_data][rendering_options][template]` "unsure" and shipped without
   it. Per [[invoice-rendering-template-on-checkout]] it is real and stable on the pinned API
   version. Omitting it means reception invoices render with the Stripe account default
   rather than LAW's branded template, unlike host-fee invoices.

3. Confirmed correct via Stripe docs, no action needed: the payment-mode session body
   (`stripe/attendees.php:291-314`) — every field is valid and current for
   `2025-09-30.clover`, `customer_update[address|name]=auto` is exactly the documented shape,
   `line_items[].tax_rates` is not deprecated, `invoice_creation[enabled] => 'true'` (string)
   is the correct form-encoding idiom Stripe expects (matches the codebase's existing
   `auto_advance => 'false'` / `off_session => 'true'` convention elsewhere in the same file).
   `invoice.paid` does fire for a Checkout-generated invoice and does carry
   `invoice_data.metadata` onto the resulting Invoice's own `metadata`, so
   `law_stripe_resolve_booking_id()` would in fact resolve it — though the code correctly
   doesn't depend on that webhook arriving.

4. Confirmed correct: `law_stripe_invoice_intent_status()`
   (`stripe/attendees.php:973-982`) reading `payments.data.payment.payment_intent.status`
   matches the current Invoice Payments API shape (the old `invoice.payment_intent` was
   removed in `2025-03-31.basil`); `law_stripe_charge_booking()`'s resume-before-create
   discipline is correctly reused unchanged by `law_reception_charge_promoted()`, gated by the
   generalised `_law_charge_claim` latch (`law_booking_claim_charge()` /
   `law_booking_release_charge()`) taken under the event lock and released in `finally`.

5. Low-risk, judged safe: `_law_stripe_attempt` (`stripe/attendees.php:319-320` for the
   Checkout session, `:792-793` for the off-session charge) is one counter shared by two
   otherwise-unrelated flows on the same booking post. Safe because each flow's idempotency
   key has its own prefix (`law-co-` vs `law-bk-`), so a shared, monotonically-increasing
   counter can never cause an idempotency key to be reused with different parameters; the two
   flows are also mutually exclusive per booking in the current call graph. No test pins this
   invariant (`_law_stripe_attempt` doesn't appear anywhere under `tests/`) — worth a one-line
   test if the call graph ever changes.

6. The `stripe listen --events` list in RECEPTIONS.md §3.3 (13 event types) is complete: it
   matches exactly the set of event types actually branched on in
   `law_stripe_webhook_dispatch()` / `law_stripe_webhook_booking_outcome()`. No drift.
