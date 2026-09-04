---
name: test-accounts
description: Local test account credentials and roles for the LAW events module, plus how to get a real non-admin committee test session
metadata:
  type: reference
---

Local-only test accounts (as of 2026-09-04):
- `claude-test-admin` / `CltestAdm2026!x` — role: **administrator** (full wp-admin access). Despite being called "admin/committee-equivalent" in test briefs, this is a true admin, NOT a genuine `events_committee` account — see [[known-issue-admin-post-blocks-non-admins]] for why that distinction matters.
- `claude-test-host` / `CltestHost2026!x` — role: `event_host`, owns zero events by default.

Real non-admin `events_committee`-only users exist in the DB for more faithful committee testing (found via `get_users(array('role'=>'events_committee'))`): `trevor-committee` (user ID 6), `hasan` (29), `james` (114), `roo` (28), `sarra` (30). No passwords known, but the "User Switching" plugin is active — log in as `claude-test-admin`, go to Users list, use "Switch To" next to one of these users to get a real committee session without a password. "Switch back" link returns to the admin account.

Mailpit: `http://localhost:8025`, API `http://localhost:8025/api/v1/messages`, `DELETE` same URL to clear.
