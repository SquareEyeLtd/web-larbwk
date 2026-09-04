---
name: recurring-patterns
description: Recurring vulnerability and false-positive patterns specific to the LAW events module, to check on every future review
metadata:
  type: feedback
---

**Pattern to keep checking: account-detail changes without step-up auth.** `functions/events/registration.php`'s profile handler requires the current password to change the password, but not to change the email address. Any future self-service account-management code (e.g. phase E/F additions) should be checked the same way: does changing a security-relevant field (email, roles, 2FA if ever added) require re-authentication or a confirmation loop, or does the session alone suffice? Why this matters here specifically: `wp_update_user()` called directly (not through wp-admin's `user-edit.php` UI) skips WP core's built-in "confirm new email via link" flow entirely, so custom front-end account code is easy to leave weaker than wp-admin's own equivalent screen.

**Pattern that's solid, use as the reference implementation:** the self-service role whitelist in `registration.php` (`law_registration_roles()` → sponsor/event_host/attendee only) is enforced twice independently — once via `array_intersect()` at the point input is read, and again via `law_registration_sync_roles()` only ever looping over that same fixed list when applying add/remove. Any new role-bearing input in this module should follow this double-guard shape, not a single filter point.

**False positive to avoid re-flagging:** WordPress nonces for logged-out visitors are not unique per visitor (no session token to salt with when `is_user_logged_in()` is false), so the same `law_register` nonce value works for any anonymous visitor in the same ~12-24h tick window. This is standard WP behaviour, not a defect in this codebase — the actual defence on that surface is the honeypot + per-IP `law_events_rate_limit_ok()` limiter, not nonce uniqueness. Don't flag "nonce is guessable/shared across anonymous users" as a finding on its own; only flag if the rate limiter or honeypot is missing/weak.

**False positive to avoid re-flagging:** the `admin_init` hook in `functions/wordpress.php` that redirects non-admin/editor users out of wp-admin is correctly scoped — it returns early for logged-out users (line ~53) and again for `pagenow === 'admin-post.php'` (line ~62), so it never interferes with any `admin_post_nopriv_*` or `admin_post_*` handler in the events module. Don't flag this hook as a lockout risk unless that scoping changes.
