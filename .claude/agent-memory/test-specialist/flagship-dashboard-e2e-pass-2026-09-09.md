---
name: flagship-dashboard-e2e-pass-2026-09-09
description: E2E pass of the new /account/dashboard/flagship/ committee screen (nav link, TinyMCE, speaker AJAX search/add, session add/remove, media modal, no-op save, access control, mobile) — 1 real bug (white-on-white front-end media modal), rest passes
metadata:
  type: project
---

Tested as `claude-test-admin` against the live working DB (flagship event with a real 8-session programme). Every destructive-looking action (Add session, Add new speaker, Choose photo) was undone with its own × / close-without-selecting before the single no-op save. Confirmed byte-identical session titles/times before and after save, plus title/date/location/"Show on the programme" checkbox unchanged. **No content was altered.**

**Real bug found (Medium): the WP media modal opened by "Choose photo" on the front-end flagship dashboard renders white text on a white background** — unreadable (tab labels "Upload files"/"Media Library", the "Drop files to upload" heading, etc. all computed `color: rgb(255,255,255)` on a white modal background). Confirmed via `getComputedStyle`, not a screenshot artifact — `media-views.min.css` does load (200, ~49KB) but something in the theme's front-end CSS (likely a global dark-nav/hero white-text rule) bleeds into `.media-modal`/`.media-frame` and isn't scoped to wp-admin only. The modal is functionally fine (DOM/buttons/JS all work, closes cleanly, no console errors) — purely a visual/readability bug. Same pattern likely affects any other front-end "Choose photo" trigger reusing wp.media (e.g. new-speaker rows, event submission banner field) since it's a site-wide CSS scoping issue, not specific to the flagship screen — worth a broader check next time media-picker CSS is touched.

Also noted, lower severity: existing-speaker inline "Choose photo" buttons (class `button-link law-rel-photo-choose`) have `cursor: auto` instead of `pointer` — look non-interactive on hover even though they work — versus the new-speaker row's "Choose photo" which is a proper styled button with a pointer cursor. And the speaker AJAX search results list renders with raw bullet markers (•) next to each result, an unstyled-`<li>` leak.

Everything else passed cleanly:
- Header dropdown ("Logged in as ...") correctly lists Manage flagship alongside Manage events/bookings/speakers, links to `/account/dashboard/flagship/`.
- Page renders correctly at 1440x900: Back to all events, Manage flagship heading, Title/Description(TinyMCE)/Date/Location/Banner/"Show on the programme" fields, Sessions block with all 8 sessions + per-session Speakers picker. No unstyled/cramped/overlapping wp-admin leakage on the page chrome itself (only the media modal, above).
- TinyMCE editors genuinely initialise on the front end (toolbar present, works) for both the event description and every session description, confirmed by adding a fresh "Add session" row and seeing its own working toolbar.
- "Add session" adds a real empty row (own TinyMCE + own speaker search); removed cleanly via × back to 8.
- Speaker AJAX search (typing "tb") returns real matching speaker records (6 results) from the DB.
- "Add new speaker" produces a full row (First/Last name, Email, Website, Role, Organisation, Job title, Choose photo, biography TinyMCE, dedupe-by-email hint text); removed cleanly via × with nothing persisted.
- No-op save: redirects to `?law_notice=flagship-saved` with a green "Flagship event saved." notice; all 8 sessions' titles/times identical before/after (diffed programmatically).
- Access control: signed-out visit to `/account/dashboard/flagship/` shows only "This dashboard is for the LAW committee." (no form), and the header shows Sign in/Create an account only (no Manage flagship link).
- Mobile at 390x844: no horizontal scroll (`scrollWidth === clientWidth`), session/speaker rows stack full-width and readably.
- Console clean throughout except one unrelated pre-existing homepage sponsor-logo 404 (`FORIS_Logo_...webp`) encountered only after logout when the browser landed on `/`, not part of this feature.

Session's 8 titles/times for reference (all times 24h in the DOM, displayed 12h in UI): Registration & Coffee 09:00-09:30, Welcome 09:30-09:35, Keynote Speech 09:35-10:05, Session 1 (Drafting for the Unpredictable...) 10:10-11:10, Coffee Break 11:10-11:40, Session 2 (What Would AI Do?...) 11:40-12:40, Lunch Break 12:40-13:40, Session 3 (The Client Speaks...) 13:45-14:45.
