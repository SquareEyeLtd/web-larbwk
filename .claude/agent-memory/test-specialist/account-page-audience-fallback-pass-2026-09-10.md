---
name: account-page-audience-fallback-pass-2026-09-10
description: E2E verification of the [user-content role="host"/"committee"] capability audiences fix and the account.php empty-body fallback, for sponsor/event_host/attendee users
metadata:
  type: project
---

Verified the fix for the blank /account/ page bug (sponsor-only users matched no `[user-content role="..."]` block).

**Scope:** functions/shortcodes.php (`law_user_content_audiences()` adds capability-backed `host`/`committee` audiences), functions/setup-account-pages.php (`law_setup_account_page_audience()`, already run against local DB so page 290 reads `role="host"` not `role="event_host"`), templates/account.php (buffers `the_content()`, falls back to generic copy only if body is empty after stripping tags).

**Backend smoke:** `php -l` clean on all 4 changed files; homepage 200, no "critical error" string. New `tests/AccountAudienceTest.php` (11 tests, 19 assertions) passes via `php -d memory_limit=512M vendor/bin/phpunit --filter AccountAudienceTest`.

**Browser results (3 throwaway users, created/deleted via wp-load.php, one role each):**
- sponsor-only: /account/ shows the real host copy ("Welcome to the London Arbitration Week." + Submit an event / view your events links) — this is the *editor content* matching via the new `host` audience, not the generic template fallback (fallback text never appeared in any of the 3 tests, so it remains unexercised by this pass). /account/events/ and /account/events/submit/ both load fully (submit form renders all sections incl. the sponsor-specific "Platinum, Gold, Silver Sponsors: free" fee tier), header dropdown shows My events / Submit an event / My profile / Sign out. No console errors.
- event_host-only: identical host copy on /account/, confirming no regression. No console errors.
- attendee-only: /account/ correctly shows the attendee-specific copy ("...booking platform for event attendees will be available soon"), NOT the host copy. No console errors.
- Contrast/rendering: all three screenshots legible — gold H1, white body paragraph text, orange links, all against the dark purple `.hero.auth-hero.hero-solid` background. Matches the site's existing look elsewhere (login/register), no regression.

**Gotcha hit during this pass:** the Playwright `snapshot`/`goto` combo can return an accessibility-tree yml that's missing content present a split-second later (an apparent "empty body" that a re-`snapshot` or screenshot immediately proves wrong) — always take a screenshot and/or re-run `snapshot` before treating an unexpectedly-empty page as a real bug. Wasted a full investigation cycle on this here before the screenshot proved the fix works.

**Untested / out of scope:** the generic template fallback paragraph in account.php (real "Welcome to London Arbitration Week. Your account is ready." + role-conditional links) never actually fired in this pass, because the DB migration had already rewritten the page's `role="event_host"` block to `role="host"`, so every role tested matched a real editor-content block first. To exercise the true fallback path, a role that matches neither `attendee` nor `host`/`committee` nor any literal role name in the page's content would be needed (there wasn't one available/appropriate to test here without touching real data).
