---
name: known-issue-admin-post-blocks-non-admins
description: Critical, reproducible bug (found 2026-09-04) — every custom events-module form that posts to /wp-admin/admin-post.php silently fails for any non-administrator role
metadata:
  type: project
---

**Status as of 2026-09-04 (events-4.1-rebuild-custom branch): confirmed, unfixed.**

Every front-end form in the rebuilt events module (host submission at `/account/events/submit/`, committee Approve/Send back/Reject at `/account/dashboard/`, presumably comment replies too) posts to `/wp-admin/admin-post.php`. For a user whose only role is `event_host` or `events_committee` (i.e. NOT `administrator`), that POST returns a clean 302 to bare `home_url()` with **zero** PHP warnings/errors logged, no event created/updated, no email sent. The same action succeeds instantly when performed by a true `administrator` account.

**Why this matters for future test passes:** if you test only with an account that happens to be a WP administrator (as the "committee-equivalent" test account in the brief was), you will NOT see this bug — everything will appear to work. You must test with a genuinely non-admin `event_host`/`events_committee` account to catch it. Confirmed via WP "User Switching" plugin, switching into a real `events_committee`-only user (e.g. `trevor-committee`) and repeating the same Send back action that worked for the admin account — it failed identically (redirect to home, no state change).

**What was ruled out** (verified via direct `wp-load.php` CLI calls with `memory_limit=512M`): `law_events_form_save()` and `law_event_workflow_transition()` both work correctly in isolation — the bug is NOT in the save/workflow logic. No PHP notices/warnings/fatals appear in `/var/log/httpd/error_log` at the time of the failing request. The "Members - Admin Access" addon exists in the codebase but is NOT active (`members_active_addons` option is empty) — ruled out as the cause. Root cause not yet found; most likely something hooked very early on `admin_init` (fires before `admin_post_{action}`) that isn't excluding admin-post.php requests the way it excludes AJAX (`wp_doing_ajax()`).

**How to apply:** always re-test any admin-post.php-based front-end form with a real non-admin role. Don't trust a PASS from an administrator account for these flows. When this is eventually fixed, verify with `trevor-committee` (or similar) and a real `event_host` account.
