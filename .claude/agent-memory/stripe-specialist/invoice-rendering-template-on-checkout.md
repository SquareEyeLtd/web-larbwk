---
name: invoice-rendering-template-on-checkout
description: invoice_creation[invoice_data][rendering_options][template] on a Checkout Session IS a real, stable Stripe param (since 2025-07-30.basil) that takes an Invoice Rendering Template ID — confirmed via docs, not just the CLI.
metadata:
  type: reference
---

Stripe added `template` under `invoice_creation.invoice_data.rendering_options` on
Create/Update Checkout Session (and Payment Link) in API version `2025-07-30.basil`
(changelog: "Adds support for Invoice Rendering Templates on Checkout and Payment Links").
It takes an Invoice Rendering Template ID (`inrtem_...`), the same object type
`stripe/service.php` already uses via the invoice-level `rendering[template]` field for host
fee invoices.

This project's client (`functions/events/stripe/client.php`) pins
`Stripe-Version: 2025-09-30.clover`, which postdates 2025-07-30.basil, so the field is
available and stable in the pinned version — this did not need `stripe listen`/a live test to
confirm, a plain docs read settles it.

**How to apply**: if reviewing `law_stripe_create_checkout_session()` (or any future
Checkout-session-with-invoice_creation code) in `functions/events/stripe/attendees.php`, and
it omits `invoice_creation[invoice_data][rendering_options][template]`, that is a real gap
(the invoice renders with the Stripe account default instead of the branded LAW template),
not a "can't be verified" unknown. See [[receptions-stripe-review-2026-09-14]] for the
instance found in RECEPTIONS.md §3.1/§16 item 8, which shipped without it.
