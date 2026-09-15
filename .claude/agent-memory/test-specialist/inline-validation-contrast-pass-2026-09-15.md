---
name: inline-validation-contrast-pass-2026-09-15
description: Verification pass for the .law-form-error dark-hero-token fix (2026-09-15) — confirms it works everywhere tested, and surfaces a related but NOT-fixed white-on-white gap in the sibling .law-form-notice class inside bare .law-modal
metadata:
  type: project
---

Scope: confirm assets/css/event-form.css's fix (new block right after
`.law-dashboard .law-form-notice.is-error`, setting `--law-form-error-bg`,
`--law-form-error-accent`, `--law-form-error-text`, `--law-form-input-border-cleared`
on `.law-dashboard, .law-thread-view, .law-cal, .law-modal`) actually resolves
the reported white-on-white `.law-form-error` bug on the committee dashboard's
"Committee controls" panel, and sweep every other light surface that class can
render on. Ran on branch events-4.2 at commit b8c30d2 (HEAD at start of pass).

**Result: the fix works everywhere checked.** Committee controls panel
(Venue capacity/Places available band errors, both directions), the nested
`.law-event-form--light` committee edit form, speaker-manage, external-manage,
flagship-manage, the booking "Add a colleague" flow (both the inline
/account/bookings/ page and a genuine AJAX `.law-modal`), the flagship apply
modal (both inline no-JS and AJAX `.law-modal`), and the committee panel's
own server-side `.law-form-notice.is-error` banner (`law_notice=action-failed`)
all render dark navy text `#1a1a3d` on pale pink `#fcf0f1` with a `#b32d2e`
accent border — never the old hero white-on-translucent or the old bright
`#ff5c4d` red. The dark `.auth-hero` profile page (wrong-current-password) is
correctly UNCHANGED (white on translucent is right there, confirmed by design,
not a bug).

**A `.law-form-field.is-touched` control inside `.law-dashboard` no longer goes
invisible either**: once touched it settles to `1px solid #c8c8d4` (visible
grey), not the hero's `2px solid transparent`. While still FOCUSED, a
`:focus`-scoped rule (`.law-form-field:has(> .law-form-error) input:focus`,
higher specificity via the `:focus` pseudo) keeps the border red — that's
intentional (comment at event-form.css:133-136), not a bug; it settles to grey
on blur. Do not mistake the focused-red state for the fix failing — check the
blurred state for the real "is-touched" appearance.

**Real, NOT-fixed, related gap found (code-verified, not freshly live-reproduced
this session):** `.law-form-notice` / `.law-form-notice.is-error` (the BANNER
class, sibling of `.law-form-error`) is a SEPARATE, hardcoded-colour rule
(event-form.css:270, `color:#fff` on `background:rgba(255,255,255,.12)`,
`.law-form-notice.is-error { border-left-color: #ff5c4d }` at line 298) with
its own light-surface override list at lines 443-447 and 699-700 —
`.law-dashboard, .law-thread-view, .law-cal` only. **`.law-modal` is missing
from that list**, even though the sibling `.law-form-notice code` sub-rule two
lines above it (293-294) DOES include `.law-modal` — strong sign of an
oversight, not a deliberate omission. Today's fix only touched the
`--law-form-error-*` custom properties consumed by `.law-form-error`; it does
not touch this separately-hardcoded `.law-form-notice` rule at all (confirmed
via `git diff -- assets/css/event-form.css`, which shows only the one new
token block).

This is the exact same bug pattern already found and logged as still-open on
2026-09-14 ([receptions-e2e-pass-2026-09-14.md](receptions-e2e-pass-2026-09-14.md)
finding #4: the reception checkout modal's discount-code error, rendered as
`.law-form-notice.is-error` inside `.law-modal`, is invisible). That prior
finding was a LIVE browser repro. I tried to force a fresh live repro of the
same class of bug via `parts/events/booking-modal.php`'s transient-backed
`law_booking_form_state()` (which is read on ANY render of that template,
inline or modal, and populates a bare `.law-form-notice.is-error` inside
`.law-modal` at booking-modal.php:112-114) using a native
`HTMLFormElement.prototype.submit.call(form)` to bypass booking-form.js's
fetch interceptor and force a true plain-POST/redirect round trip. The
transient didn't end up populated in my attempt (worth retrying with more care
if this needs a fresh screenshot) — so treat this specific instance as
code-verified only, not re-screenshotted this session. The 2026-09-14 live
screenshot plus this session's source-line confirmation together are strong
enough to call it a real, still-open bug.

**Recommendation for whoever picks this up:** add `.law-modal` to the two
selector lists at event-form.css:443-447 and (for parity, though lower
priority) :699-700, matching the `code` sub-rule two lines above. Do NOT
touch this file myself — out of scope for a verification pass, and the tree is
shared.

## Fixtures touched this session
- `law-e2e-committee` (ID 6170) and `law-e2e-host` (ID 28589): passwords reset
  to `LawE2eCom2026!x` / `LawE2eHost2026!x` (wp-cli `user update --user_pass`)
  since neither worked with any password I had on record. Both are genuine
  `events_committee` / `event_host` accounts, reusable for future passes.
- Event 17079 ("Test Event", law-proposed, author 8771) used for the Committee
  controls panel band-error checks. All my venue-capacity/places submissions
  were either refused server-side (bad pairs) or never actually written —
  confirmed via `wp post meta list 17079` afterwards showing no
  `_law_venue_capacity`/`_law_tickets_available` meta at all. Left as found.
- Event 695 (Flagship conference): submitted an empty-title save, correctly
  refused (`law_notice=flagship-invalid`), confirmed `wp post get 695
  --field=post_title` still reads "Flagship conference" afterwards. Left as
  found.
- Booking 57314 (event 5973 "TEST EVENT", owner `law-e2e-host`): created via
  `wp eval 'law_booking_create(5973, 28589, array())'` as a fixture to reach
  the "Add a colleague" manage view. Left in place, legitimate and reusable.
- Booking 57315 (event 5925, owner `law-e2e-committee`): created
  UNINTENTIONALLY by submitting the real "Book your place" AJAX modal with no
  colleague rows (an empty additional-attendee set is valid — the booking just
  succeeds for the primary registrant, it does not error). Deleted with
  `wp post delete 57315 --force` once noticed. If you see a stray "Booking #N"
  for the committee test account on an event you didn't expect, that's the
  same trap — check before assuming it's a real fixture.

## Gotcha for next time
Submitting the booking-repeater's "empty" state (zero additional-attendee
rows, or one row that's entirely blank) via the real JS-driven `.law-modal`
does NOT error — a fully blank added row is silently dropped by
`law_booking_clean_additional_rows()`, and zero colleagues is a valid booking
of just the primary registrant. To reach a genuine validation error in that
modal you need a PARTIALLY filled row (e.g. name given, email blank) —
that's what produces the row-keyed "Please give a valid email address for X."
refusal, rendered via `.law-modal__error` (a third, separate, ALREADY-safe
hardcoded-`#b32d2e`-on-transparent class — not `.law-form-error` and not
`.law-form-notice`). Three different error-rendering mechanisms exist in this
codebase for what looks like the same "red text under a field" concept:
`.law-form-error` (fixed today), `.law-form-notice`/`.law-form-notice.is-error`
(NOT fixed, see above), and `.law-modal__error` (JS-inserted, always safe,
hardcoded colour, unrelated to the token system). Check which one you're
actually looking at before concluding a surface is fine or broken.
