# Memory index

- [Environment URL](environment_url.md) — law.localhost is stale (dir index only); real local site is at http://localhost/law/
- [Test accounts](test-accounts.md) — claude-test-admin/host/committee creds incl. genuine events_committee account; wp-cli phar path and gotchas
- [KNOWN ISSUE (FIXED): admin-post.php blocked non-admins](known-issue-admin-post-blocks-non-admins.md) — was: every custom form silently failed for event_host/events_committee; confirmed fixed 2026-09-04
- [KNOWN ISSUE: /programme/ and /speakers/ are Members-gated](known-issue-public-pages-members-gated.md) — not public despite site map; check response body not just status code
- [UX persona pass 2026-09-04](ux-persona-pass-2026-09-04.md) — 3-persona walkthrough results: 1 BLOCKER (committee has no findable route to review queue), several FRICTION items, test artifacts left in place
- [Security pass 2026-09-04 (breaker)](security-pass-2026-09-04-breaker.md) — 1 real Medium finding (event title/status IDOR via ?law_event=), long list of correctly-repelled attacks (XSS/SQLi/CSRF/role escalation/webhook forgery all held)
