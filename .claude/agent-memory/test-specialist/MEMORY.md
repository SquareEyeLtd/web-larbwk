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
