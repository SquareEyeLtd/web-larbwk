---
name: timeline-12hr-column-widen-pass-2026-09-09
description: Verified 24h->12h session time format and 7.5rem->9.75rem time-column widen on the programme flagship card and single flagship event agenda timeline, desktop + mobile
metadata:
  type: project
---

Read-only visual check requested by Denis, not a full E2E build pass. Both changes live in `assets/css/calendar.css` (`.law-timeline` block, `--law-tl-time` custom property, comment explicitly notes "at 7.5rem every time wrapped onto two").

**Result: all checks passed, no regressions found.**

- `/programme/` flagship card (`.law-flagship-card`) at 1440x900 and 390x844: every session time is 12-hour ("11:40am – 12:40pm" etc.), no wrapping, titles align in a tidy second column on desktop, stack with time-above-title on mobile (list items, not the `.law-timeline` grid — different markup from the single-event agenda).
- `/events/flagship/` agenda (`.law-timeline-section` / `.law-timeline__item`) at 1440x900: all time ranges on one line, right-aligned. Marker (`::before`) and rail (`::after`) stay centred on each other after the width change — confirmed by measuring `getComputedStyle(item, '::before'/'::after')` against the item's bounding rect (both landed at the same absolute x to sub-pixel rounding), because both are derived from the same `--law-tl-time`/`--law-tl-gap` custom properties (this is the documented, deliberate design in the CSS comment, not a coincidence — verify via computed pseudo-element styles rather than eyeballing screenshots if this ever needs re-checking).
- Mobile (390x844) timeline is single-column and unaffected, since `--law-tl-time` only changes at the `min-width: 48em` breakpoint (mobile stays 0rem).
- No horizontal scroll at 390 width on either page (`scrollWidth === clientWidth`, checked via eval).
- No console errors beyond the pre-existing benign `JQMIGRATE` notice.
- One cosmetic note, not a regression: the mobile flagship-card screenshot captures the sitewide fixed top nav overlapping the top of the card, because `page.locator(...).screenshot()` captures on-screen pixels at that clip region — same pre-existing fixed-nav-overlap issue logged in [[committee-dashboard-filter-bar-pass-2026-09-05]].

Screenshots: `.playwright/screenshots/programme-flagship-card-desktop-v2.png`, `-mobile-v2.png`, `flagship-timeline-desktop-v2.png`, `-mobile-v2.png`.
