---
name: rich-text-emails-escaping-inversion-2026-09-16
description: Adversarial review of the "escape placeholder values, trust the body" inversion in law_events_email_render_body() — PASS, the WP-core mechanics that make it safe
metadata:
  type: project
---

Reviewed a deliberate escaping-model inversion in the events emails feature
(branch events-4.2, 2026-09-16): email bodies now carry rich text
(`law_rich_text_sanitize()` at save, an allowlist of bold/italic/lists/two
headings/blockquote/links — `functions/events/rich-text.php`), and
`law_events_email_render_body()` (`functions/events/notifications.php:799-809`)
escapes placeholder VALUES individually (`esc_html()`) before substitution
instead of escaping the whole rendered string. Verdict: PASS, sound, 0 P0/P1/P2,
one P3 (stale docblock at notifications.php:819-822 that still describes the
pre-16-September plain-text-body behaviour and contradicts the code two lines
below it).

**The property that makes it safe, worth checking again on any future change
to this file**: `wp_kses()` in `law_rich_text_render()` runs AFTER placeholder
substitution, not before. `law_events_email_render_body()` does
`strtr($body, array_map('esc_html', $placeholders))` THEN
`make_clickable(law_rich_text_render($body))`, and `law_rich_text_render()` is
`wp_kses(wpautop($value), law_rich_text_allowed_html())`. So a committee-authored
`<a href="{invoice_url}">` is safe not because the value can't contain
`javascript:`/quotes, but because ANYTHING that ends up in that href after
substitution gets re-validated by `wp_kses_bad_protocol()` on the very next
call. If a future refactor moves `wp_kses` to run BEFORE substitution (e.g. to
"sanitise once, early"), that specific safety property disappears and the
attribute-breakout/scheme-injection question has to be re-asked from scratch.

Three WP-core mechanics I verified directly (not from memory/training alone)
rather than assumed, worth reusing as a checklist for any similar templating
review in this codebase:
- `esc_html()` uses `ENT_QUOTES` (encodes both `"` and `'`), so it's sufficient
  for attribute-context escaping even though `esc_attr()` would be the more
  idiomatic choice — no functional difference for the five HTML metacharacters
  here since wp_kses guarantees attributes are quoted.
- `make_clickable()` (`wp-includes/formatting.php:3147-3175`) splits the string
  on `<[^<>]+>` and only regex-matches URLs in the NON-tag pieces — it never
  reaches into an existing tag's attribute values, so it can't be used to
  break out of or rewrite an href. New links it builds go through `esc_url()`
  with `wp_allowed_protocols()` (no `javascript:`/`data:`), confirmed at
  `formatting.php:3069` (`_make_web_ftp_clickable_cb`).
- `strtr()` with an array is single-pass and non-recursive: it doesn't rescan
  substituted text, so a value that happens to contain the literal string
  `{other_tag}` can never trigger a second substitution. Rules out a
  ladder/nested-injection vector across placeholders.
- PHPMailer's `secureHeader()` (`wp-includes/PHPMailer/PHPMailer.php:5091-5094`,
  called on Subject at lines 1804/1821/2894) strips `\r`/`\n` from the Subject
  unconditionally. LAW's own subject-building
  (`strtr($definition['subject'], $placeholders)`, notifications.php:1240) is
  deliberately unescaped by design (subjects carry raw `&` etc.) and relies
  entirely on this PHPMailer-layer stripping for CRLF-injection safety — this
  was true before the rich-text change too, not introduced by it.

Also confirmed as part of this pass, not itself new: `{latest_comment}`
(comments.php:35), `{rejection_reason}`/`{cancellation_reason}`
(workflow.php:315,399), and every booking/waitlist placeholder
(bookings.php:3305-3316, waitlist.php:194,510) are all plain
`sanitize_textarea_field()`'d or plain-concatenated text before they ever reach
the placeholders array — none of them carry markup that the uniform
`esc_html()` pass would need to special-case.

See also [[emails-dashboard-review-2026-09-16]] for the front-end Manage
emails screen this sits inside (capability/nonce/rate-limit layer, unchanged
by this inversion) and [[recurring-patterns]] for other reusable safety
mechanics found in this codebase.
