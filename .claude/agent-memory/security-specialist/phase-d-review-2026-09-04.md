---
name: phase-d-review-2026-09-04
description: Status of the phase D (custom registration/profile) security review — what was checked and the one open P1
metadata:
  type: project
---

On 2026-09-04 reviewed the phase D surfaces added on branch `events-4.1-rebuild-custom` (commits after 27a312b): `functions/events/registration.php` (anonymous `law_register` + logged-in `law_profile` admin-post handlers), `templates/register.php`, `templates/account-profile.php`, `parts/events/profile-fields.php`, the migration runner's form 1 notification import block, and the two new `notifications.php` registry emails (`admins_user_registered`, `squareeye_user_registered`).

**Open finding (P1, unresolved as of this review)**: `law_profile_handler()` (`functions/events/registration.php:307-318`) changes `user_email` via `wp_update_user()` with no current-password check and no confirmation loop (WP core's own wp-admin profile page requires clicking a link sent to the new address before an email change takes effect; this bypasses that). Chained with `retrieve_password()`, a momentarily hijacked session (XSS elsewhere, shared device, stolen cookie) lets an attacker silently repoint the account's email to one they control, then take the account over permanently via the ordinary forgot-password flow — no CSRF needed since the nonce blocks that vector, but session compromise is enough.

**Passed cleanly (don't re-litigate unless the code changes)**:
- Role whitelist is double-guarded: `law_registration_write_profile_meta()` intersects input against `law_registration_roles()` (sponsor/event_host/attendee only) AND `law_registration_sync_roles()` only ever iterates that same fixed list — administrator/events_committee can never be reached through either registration or profile.
- Password fields are never run through `sanitize_text_field()` (would corrupt special characters) — correctly handled as raw strings.
- The IP-keyed error-state transient (`law_register_state_{md5(REMOTE_ADDR)}`) explicitly unsets password/password_confirm before storing, and the wp_insert_user-failure path stores an empty input array — no password ever lands in the transient.
- Auto-login after registration (`wp_set_auth_cookie` after `wp_insert_user`) is not session fixation — it mints a fresh session token for a brand-new account, not reusing a pre-existing anonymous session.
- Honeypot + `check_admin_referer` + per-IP rate limit (5/hour via `law_events_rate_limit_ok()` in `comments.php:151`) present and correctly ordered on the anonymous registration surface; the same dual per-IP+per-user limiter is correctly reused for profile updates (10/10 min).
- Email body in `law_events_send()` (`notifications.php:310`) is `esc_html()`'d before `wpautop`/`make_clickable`, and recipients for the two new registry emails are hardcoded arrays validated through `sanitize_email`/`is_email` — no header injection or recipient injection route from the registration form.
- Migration's form-1 notification import (`migration/runner.php:1205-1253`) validates imported `to` addresses the same way and only runs from the existing `manage_options`-gated `wp_ajax_law_migration_run` handler — no regression there.
- Snapshot exposure self-test (`migration/runner.php:130-142`) and the admin-post.php wp-admin-lockout exemption (`functions/wordpress.php:50-73`, scoped to `is_user_logged_in()` then `pagenow === 'admin-post.php'`) both still intact, no regression since the last review round.

**Open verification item (not a confirmed vuln, couldn't check DB state)**: `functions/setup-account-pages.php` only swaps `/account/profile/` to the new `templates/account-profile.php` when `law_events_source() === 'cpt'`; before cutover it stays on `templates/account.php`. `functions/users.php`'s legacy `gform_user_updated` role-sync hook for GF form 3 (User profile) is unconditionally registered with no gating on the source flag. Couldn't confirm from static code whether the old form 3 GF embed is still physically present in that page's post_content post-cutover (no DB/shell access in this review) — worth a one-time manual check that the legacy form-3 embed is actually removed once `/account/profile/` cuts over, so there isn't a second, less-hardened profile-save path left reachable.
