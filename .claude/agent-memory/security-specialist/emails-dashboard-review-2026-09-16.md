---
name: emails-dashboard-review-2026-09-16
description: Front-end "Manage emails" committee screen (account/dashboard/emails/) reviewed 2026-09-16 — clean PASS, one P3
metadata:
  type: project
---

Reviewed the new front-end Manage emails screen (mirrors the wp-admin Emails
screen) on branch events-4.2: `functions/events/emails-dashboard.php`,
`templates/account-dashboard-emails.php`, `parts/events/emails-list.php`,
`parts/events/emails-manage.php`, plus the shared helpers appended to
`functions/events/notifications.php` (law_events_email_editable_keys,
_is_customised, _override_from_input, _recipients_survived, _save_override,
_reset_override, _has_unresolved_tags, _recipients_label, _send_test) and the
refactor of `functions/events/admin/emails-screen.php` onto those helpers.

Result: PASS, 0 P0/P1/P2, one cosmetic P3 (GET `law_email` param cast to
string before an `is_scalar()` check at emails-dashboard.php:76 — the POST
side already guards this correctly with the `$scalar` closure pattern in
notifications.php:773-775; GET side doesn't).

Confirmed clean, worth knowing for future review of this same feature area:
- Triple-layered committee gate (page template, list part, manage part each
  independently call law_user_is_committee()) — the pattern this codebase
  wants for every front-end dashboard reachable via get_template_part().
- Test-mode privilege boundary holds: the front-end handler
  (law_email_manage_handler) has zero code path touching
  LAW_EVENTS_TEST_MODE_OPTION; only the wp-admin handler, still gated
  manage_options, can flip it. This was the single most important thing to
  verify given the org checklist's explicit "must not let committee reach
  test mode" requirement — it's designed correctly.
- "Send a test to me" is not recipient-injectable: law_events_email_send_test()
  is always called with no $user_id override from this handler, so it can only
  ever mail the currently-authenticated user's own address. Good pattern to
  check for on any future "send test"/"preview as"/"send to self" feature —
  the vulnerability shape to watch for is a request-supplied user_id or email
  reaching the recipient argument.
- Subject sanitised with sanitize_text_field() (strips line breaks → no
  wp_mail header injection route), body with sanitize_textarea_field() +
  esc_html()+wpautop() at send time (pre-existing law_events_send(),
  notifications.php:1140) — this is the standing pattern for
  committee-authored content that becomes outbound HTML email in this module.
- Slug handling: sanitize_key() then validated against the registry before
  any indexing/storage, on both the read and write paths, and
  law_events_email_save_override() independently re-checks the registry and
  whitelists editable keys via array_intersect_key() — belt-and-braces
  against an unknown/forged slug polluting the law_events_email_overrides
  option.
- The one-shot draft transient is keyed by get_current_user_id() (server-side,
  not request-derived) — not poisonable or cross-readable.

See also [[phase-b-hostile-review-2026-09-04]] for the module-wide sweep this
builds on, and [[recurring-patterns]] for the anonymous-nonce false-positive
note (WP nonces don't encode capability — the capability check after the
nonce check is the real boundary, not the nonce itself).
