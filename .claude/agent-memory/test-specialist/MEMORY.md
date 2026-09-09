# Memory index

- [Environment URL](environment_url.md) — law.localhost is stale (dir index only); real local site is at http://localhost/law/
- [Test accounts](test-accounts.md) — claude-test-admin/host/committee creds incl. genuine events_committee account; wp-cli phar path and gotchas
- [KNOWN ISSUE (FIXED): admin-post.php blocked non-admins](known-issue-admin-post-blocks-non-admins.md) — was: every custom form silently failed for event_host/events_committee; confirmed fixed 2026-09-04
- [KNOWN ISSUE: /programme/ and /speakers/ are Members-gated](known-issue-public-pages-members-gated.md) — not public despite site map; check response body not just status code
- [UX persona pass 2026-09-04](ux-persona-pass-2026-09-04.md) — 3-persona walkthrough results: 1 BLOCKER (committee has no findable route to review queue), several FRICTION items, test artifacts left in place
- [Security pass 2026-09-04 (breaker)](security-pass-2026-09-04-breaker.md) — 1 real Medium finding (event title/status IDOR via ?law_event=), long list of correctly-repelled attacks (XSS/SQLi/CSRF/role escalation/webhook forgery all held)
- [Committee dashboard filter bar pass 2026-09-05](committee-dashboard-filter-bar-pass-2026-09-05.md) — all AJAX filter/skeleton/mobile-modal/hover-colour checks passed; 1 pre-existing sitewide fixed-nav overlap on mobile (not a regression)
- [Committee detail view sections pass 2026-09-05](committee-detail-view-sections-pass-2026-09-05.md) — new Speakers/Sessions/Owners/Invoice sections all correct; REAL mobile overflow bug in `.law-dashboard__facts` grid (fixed 180px col + no min-width:0)
- [Thread reply AJAX pass 2026-09-05](thread-reply-ajax-pass-2026-09-05.md) — found form.action/named-input root cause of reply failures, confirmed fix; found remaining gap in full-logout stale-session error path
- [Bookings phase 8 E2E pass 2026-09-07](bookings-phase8-e2e-pass-2026-09-07.md) — 9/9 flows pass overall; 4 real cosmetic bugs (missing AJAX notices, broken "Browse the programme" link, 2 wording/pluralisation nits); 1 gate-vs-spec conflict flagged
- [Waitlist E2E pass 2026-09-08](waitlist-e2e-pass-2026-09-08.md) — 10/10 flows pass; 1 real CSS bug (modal text clips in dashboard tables); shared-dev-env hazards (concurrent phpunit/edits, counter collisions, playwright-cli hangs, no-JS via curl)
- [KNOWN ISSUE: playwright-cli global wrapper broken](known-issue-playwright-cli-wrapper-broken.md) — resolves to wrong npm package; resolve @playwright/cli's bin directly instead
- [Timeline 12hr time + column widen pass 2026-09-09](timeline-12hr-column-widen-pass-2026-09-09.md) — programme flagship card + single-event agenda both correct at desktop/mobile; marker still centred on rail after 7.5rem->9.75rem widen
- [Gotcha: fixed nav swallows naive scrollTo](gotcha-fixed-nav-scroll-offset.md) — subtract fixed nav height (~142px desktop/~99px mobile) before scrolling to an element for a screenshot, else it lands hidden behind the nav with no error
- [Flagship dashboard E2E pass 2026-09-09](flagship-dashboard-e2e-pass-2026-09-09.md) — nav link/TinyMCE/speaker AJAX/no-op save/access-control/mobile all pass; 1 real bug: front-end WP media modal renders white-on-white (unreadable but functional)
- [Flagship dashboard CSS fixes verified 2026-09-09](flagship-dashboard-css-fixes-verified-2026-09-09.md) — media modal contrast, banner spacing, doubled Sessions heading, speaker cursor/bullets all confirmed fixed; media grid "filenames" are a `::after` pseudo-element, not real DOM text
- [Event-form CSS token refactor pass 2026-09-09](event-form-css-token-refactor-pass-2026-09-09.md) — white-on-white Biography/Description label fix confirmed working everywhere; 1 real (likely pre-existing) bug: TinyMCE invalid border computes 1px not 2px on both palettes
