---
name: thread-reply-ajax-pass-2026-09-05
description: E2E pass on the comment-thread reply AJAX conversion (assets/js/event-form.js + functions/events/comments.php) — found and confirmed the form.action/named-input root cause, confirmed the fix, and found one remaining gap in the stale-session error path
metadata:
  type: project
---

Tested the newly-AJAXified `.law-thread-reply` form (host view `/account/events/?law_thread=<id>` and committee view `/account/dashboard/?event=<id>`) against event 2388.

**Root cause of the reported failure (confirmed, not the suspected stale-nonce theory):** `event-form.js` read `form.action` to get the fetch URL, but every admin-post form carries `<input type="hidden" name="action" value="...">`, and a named form control shadows the form element's own `.action` DOM property — so `form.action` returned the `HTMLInputElement` itself, `fetch()` coerced it to `"[object HTMLInputElement]"`, and the POST went to e.g. `/account/events/[object%20HTMLInputElement]` and 404'd. `response.json()` then threw on the 404 HTML body, landing in `.catch()` and showing the generic "Sorry, your message could not be sent..." message — exactly the symptom reported, on the very first attempt, with no session games needed. Fixed by switching to `form.getAttribute('action')` (event-form.js ~line 296). Confirmed fixed: host and committee replies both now return `200 OK` JSON, append the bubble, clear the textarea, show "Comment sent." with zero console errors.

**Remaining gap found while testing the "stale nonce" scenario (not yet fixed, worth a follow-up):** the friendly 403 JSON message ("Your session has changed since this page was opened...") in `functions/events/comments.php::law_event_handle_comment_reply()` only fires when `is_user_logged_in()` is still true for *some* user when the stale nonce is checked. If the browser fully logs out (rather than switching to a different logged-in user) while the thread page is open, `admin-post.php` dispatches to `admin_post_nopriv_law_event_comment_reply` instead, which unconditionally does `wp_safe_redirect( wp_login_url() ); exit;` — a 302 to `/login/` regardless of the `law_ajax=1` flag. The JS's `fetch(...).then(r => r.json())` can't parse that HTML redirect body, so it falls into the same generic catch-all message rather than a "you've been logged out, please log in again" message. Confirmed via network: POST → `302 Found` → `location: /login/` → JS shows "Sorry, your message could not be sent. Please reload the page and try again." Only the "different user logged in in the other tab" variant (auth cookie replaced, not cleared) reaches the intended `wp_verify_nonce` 403 branch and produces the exact "Your session has changed..." message. Denis should decide whether the nopriv branch is worth special-casing for `law_ajax=1` too (return JSON 401 instead of a redirect) — low severity since the generic message is still honest advice ("reload the page"), but it's an inconsistency between the two "your session changed" causes.

**How to apply:** if a future pass touches this reply flow again, retest both stale-session variants (full logout vs different-user login in a second tab of the same context) since they hit different code paths (`admin_post_nopriv_*` vs the AJAX nonce-check branch) and produce different error messages.

Screenshots (all under `.playwright/screenshots/`, prefixed by test order):
- `01`-`05`: pre-fix reproduction (host + committee, both hit the `[object HTMLInputElement]` 404)
- `03`: confirms native `required` blocks empty-textarea submission client-side, no request sent
- `06`-`09`: post-fix happy path, host and committee, both 200 OK with bubble/clear/notice
- `10`: full-logout stale-session variant → generic catch-all message (the gap above)
- `11`: different-user-login stale-session variant → correct 403 JSON + "Your session has changed..." message
