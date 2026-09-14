---
name: checkout-status-vs-payment-status
description: Stripe Checkout Session `status` (open/complete/expired) is NOT the same signal as `payment_status` (paid/unpaid/no_payment_required) — status=='complete' can mean an async payment is still settling.
metadata:
  type: reference
---

Stripe's own docs for the Checkout Session object are explicit: `status: complete` means
"The checkout session is complete. **Payment processing may still be in progress**." A
session can be `status=complete` with `payment_status=unpaid` when the delegate paid with
an asynchronous method (bank debit, etc.) that hasn't settled yet.

The only correct way to tell "the money has actually landed" is `payment_status === 'paid'`
(or `'no_payment_required'` for a $0 total), never `status === 'complete'` alone.

**How to apply**: whenever reviewing code that inspects a Checkout Session object to decide
whether to confirm/publish something, check whether it reads `payment_status` (correct) or
only `status` (incomplete/risky for async methods). See [[receptions-stripe-review-2026-09-14]]
for a concrete instance found in this codebase (`law_stripe_expire_checkout_session()`'s
fallback GET, consumed by `law_reception_release_hold()` / `law_reception_continue()`, tests
only `status === 'complete'` and calls `mark_paid()` without checking `payment_status`) versus
the correct pattern already used elsewhere in the same codebase
(`stripe/webhook.php`'s `checkout.session.completed` branch, and
`law_reception_handle_checkout_return()`), both of which correctly branch on `payment_status`.
