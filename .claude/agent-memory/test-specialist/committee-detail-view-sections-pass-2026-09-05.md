---
name: committee-detail-view-sections-pass-2026-09-05
description: Test pass on new committee /account/dashboard/?event=<id> detail-view sections (Speakers, Session agenda, Owners & contacts, Invoice details, extra facts rows) — content correct, one real mobile CSS overflow bug found
metadata:
  type: project
---

Tested 2026-09-05 (event detail view rework, part of the events 4.1 rebuild), `templates/account-dashboard.php`, styled by `.law-dashboard__section`/`__people`/`__sessions`/`__facts` in `assets/css/event-form.css`, on event 2399 ("Best event ever", status Sent back) as `claude-test-committee`.

All requested content checks passed on desktop (1280px), verified via accessibility snapshot and a full-page screenshot:
- Extra facts rows all present and correct: Event type "Seminar / talk", Sector "Jurisdiction-specific: Some jurisdiction specific", Venue needed? "Yes, please share our details with venue hosts", Venue capacity "Under 50", Tickets available "20", Terms accepted "5 Sep 2026, 14:01".
- Speakers section: Jeremy Speaker, Director, Jeremy Org, mailto link, Profile link, biography paragraph. No photo on this speaker — layout stays tidy with no broken-image icon or empty gap.
- Session agenda: 2 sessions, titles/times/descriptions all present, first session also lists "Speakers: Jeremy Speaker".
- Owners & contacts: "Additional event owners" (Jeremy Hueston, Jeremy Co.) and "Event contacts" (Mikhael Gusev, Gusev Co.), both with mailto links.
- Invoice details: contact Denis Gusev with mailto link, address "Sremskog fronta, Becici, Budva, 85316, Montenegro", VAT number 123456.
- Visual/contrast: all new sections sit on the white content area below the dark-purple hero, no bleed, text/background contrast fine throughout (dark navy text on white, orange "Sent back" badge, peach/blue message-thread bubbles all readable). No console errors (only the standard JQMIGRATE log line).

FAIL — real mobile horizontal-overflow bug, not pre-existing: at 390px viewport, `document.documentElement.scrollWidth` is 422 vs `clientWidth` 390 (32px of overflow), caused by `.law-dashboard__facts { display: grid; grid-template-columns: 180px 1fr; ... }` in `assets/css/event-form.css` (~line 226). Grid items default to `min-width: auto`, so an unbreakable long string in any `dd` (an email address, or "International hosts (no UK office): £600 + VAT") forces the whole `1fr` column wider than the viewport, and every `dd` in that same grid track inherits the same overflowed width (confirmed even short values like the VAT number "123456" report `left: 223, width: 199` at a 390px viewport). This hits both the original facts list (line 56) and the new Invoice details section (line 197), since both reuse `dl.law-dashboard__facts`. Fix needs both: a mobile breakpoint collapsing to `grid-template-columns: 1fr` (existing pattern already used for `.law-row-grid` at `max-width: 39.9375em`), and `min-width: 0` (or `overflow-wrap: anywhere`) on `.law-dashboard__facts dd` so long unbreakable strings don't blow out the column even in the single-column mobile layout.

Other overflow contributors found at 390px were pre-existing/unrelated to this change: the wp-admin toolbar's `.display-name` span (logged-in-user bar, only visible with admin bar showing) and a `menu-item` list item in the front-end nav overflowing by ~8px (same fixed-nav-on-mobile family of issues as [[committee-dashboard-filter-bar-pass-2026-09-05]]).

See [[environment_url]] for the working local URL and [[test-accounts]] for the `claude-test-committee` login used for this pass.
