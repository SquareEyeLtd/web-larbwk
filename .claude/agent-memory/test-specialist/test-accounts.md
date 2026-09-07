---
name: test-accounts
description: Local test account credentials and roles for the LAW events module, plus how to get a real non-admin committee test session
metadata:
  type: reference
---

Local-only test accounts (as of 2026-09-04, updated later same day):
- `claude-test-admin` / `CltestAdm2026!x` — role: **administrator** (full wp-admin access). Despite being called "admin/committee-equivalent" in test briefs, this is a true admin, NOT a genuine `events_committee` account.
- `claude-test-host` / `CltestHost2026!x` — role: `event_host`, user ID 389. No longer owns zero events: by 2026-09-04 it already owned event 1224 ("E2E Curl Host Event", Confirmed/Paid, used as the persistent "Confirmed event" fixture for edit/locked-field testing) and has since accumulated more via UX testing. Log in with the username `claude-test-host`, not the email, on `/login/`.
- `claude-test-committee` / `CltestCom2026!x` — role: **genuine `events_committee`** (not admin). This account now exists and should be preferred over the old User Switching workaround below for committee testing — it reproduces real non-admin behaviour directly. On 2026-09-07 this password did not work (login failed) — reset it with `wp user update claude-test-committee --user_pass='CltestCom2026!x'` (as `claude-test-admin` or via wp-cli) and it worked immediately after. If it fails again, just reset it rather than assuming the account is broken.

Real non-admin `events_committee`-only users also exist in the DB (found via `get_users(array('role'=>'events_committee'))`): `trevor-committee` (user ID 6), `hasan` (29), `james` (114), `roo` (28), `sarra` (30). No passwords known, but the "User Switching" plugin is active — log in as `claude-test-admin`, go to Users list, use "Switch To" next to one of these users to get a real committee session without a password. "Switch back" link returns to the admin account. Prefer `claude-test-committee` directly now that it exists.

wp-cli is not on PATH in this environment. Use the phar at `/srv/http/yesoft/wp-cli.phar` (belongs to a different site but works against any `--path`) with a raised memory limit, e.g. `php -d memory_limit=512M /srv/http/yesoft/wp-cli.phar --path=/srv/http/law user get claude-test-host --field=roles`. Default 128M is NOT enough — Pods plugin's init blows past it and you get a fatal. For querying custom post statuses (`law-proposed`, `law-sent-back`, etc.), `wp post list --post_status=any` silently EXCLUDES them (WP_Query's `any` skips statuses not flagged public/internal-safe) — use `wp db query "SELECT ID, post_title, post_status, post_author FROM wp_posts WHERE post_type='law_event' ..."` instead for reliable results.

Mailpit: `http://localhost:8025`, API `http://localhost:8025/api/v1/messages`, `DELETE` same URL to clear.
