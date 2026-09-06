---
name: committee-dashboard-filter-bar-pass-2026-09-05
description: Test pass on the reworked committee /account/dashboard/ event-list filter bar (shared calendar-filters.js with /programme/) — all steps passed, one pre-existing site-wide issue noted (not a regression)
metadata:
  type: project
---

Tested 2026-09-05 (committee events dashboard rework, part of the events 4.1 rebuild): `/account/dashboard/` list now shares `assets/js/calendar-filters.js` with `/programme/`, filtering over AJAX via `?law_partial=1`, backed by `functions/events/committee.php` (`law_committee_maybe_render_partial`), rendered via `parts/events/dashboard-list.php`, templated by `templates/account-dashboard.php`.

All test-plan steps passed:
- Keyword search and status select both filter over AJAX with no page reload (confirmed via network log showing only `?...&law_partial=1` requests, no navigation), URL updates via history state (`?law_kw=`, `?law_status=`).
- Skeleton loading state (`.law-cal-skeleton` bars) renders correctly — only visible by artificially delaying the partial response with a Playwright route handler (a plain busy-wait loop in the routed callback works; `setTimeout` is not defined in that route-handler sandbox, use `while (Date.now() - start < ms) {}` instead).
- "No events match." empty state renders correctly for a gibberish keyword.
- "Clear all" resets both filters and returns the full list, no reload.
- Review button hover is confirmed orange (`rgb(239, 125, 5)` / `#ef7d05`), not blue. Filter inputs (keyword box, status select) have orange (`#ef7d05`) borders by default, matching `/programme/`.
- Mobile (390px): full-width "Filters" button opens a bottom-sheet modal with keyword/status/Apply/Clear all; Apply filters over AJAX and closes the modal. Table wrapper `.law-dashboard__table-wrap` has `overflow-x: auto` and scrolls independently (confirmed scrollWidth 896 vs clientWidth 330) while `document.documentElement` never exceeds viewport width (no page-level horizontal scroll).
- No JS console errors throughout (desktop or mobile), on dashboard or `/programme/`.
- Regression check on `/programme/` desktop: shared JS generalisation didn't break its own keyword filter, still AJAX, still no reload, still no console errors.

One issue found, but it's pre-existing and site-wide, not caused by this rework: the logged-in-user nav bar (`nav.nav.affix`, `position: fixed; top: 46px; z-index: 9999`) stays pinned over page content for the *entire* scroll on mobile (not just at the top), so on any long mobile page (confirmed same behaviour on `/programme/`) the topmost 1-2 rows of whatever list is scrolled to the top get visually clipped behind it. Not something to fix as part of the dashboard filter-bar work; flag separately if raising with Denis since it affects all long mobile listings, not just this one.

See [[environment_url]] for the working local URL and [[test-accounts]] for the `claude-test-committee` login used for this pass.
