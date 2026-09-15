---
name: external-events-e2e-pass-2026-09-15
description: Full E2E pass of the external-events feature (committee-managed third-party events) — 4 real bugs found, most flows pass
metadata:
  type: project
---

Full 11-item E2E pass of the "external events" feature (committee-managed `law_event` posts for
third-party-run events; Register links out, no booking on our side). Ran on http://localhost/law
as `law-e2e-committee`. Backend smoke (php -l, homepage/critical-error check) was clean first.

**Real bugs found (all reproducible, not flagged as fixed — report was "report only"):**

1. **Client-side validation gates the server-side one on the external-event create/edit form**
   (`parts/events/external-manage.php` / `functions/events/external-events.php`). Only Event
   title (native `required`) and Description (JS-driven check for the TinyMCE field) are
   checked client-side; Event type and Date have no client-side check at all. Pressing "Publish
   to the programme" on a fully empty form shows ONLY "Please describe the event." (confirmed via
   `playwright-cli requests` — zero network request fires). Fill Description too and the *next*
   attempt reaches the server, which then correctly returns Event type + Date errors together with
   the "Please fix the highlighted fields below" banner (verified `law_external_event_validate()`
   directly via `wp eval` returns all 3 codes in one call). So a user sees errors in two waves, not
   all fields at once as the design intends.
2. **No success confirmation after Save as draft / Publish** on this same form. The handler
   (`law_external_event_handler()` in external-events.php) redirects to
   `law_notice=external-saved` / `external-published`, but the `$law_external` branch of
   `templates/account-dashboard.php` (~line 87) has zero notice-rendering for those keys — unlike
   the sibling `$law_detail` branch just below it, which has a full if/elseif chain for other
   notices. The form just silently resets to blank with the notice inert in the URL. The save
   itself works correctly (verified via DB); only the on-screen confirmation is missing.
3. **Disabled "Registration opening soon" card button is the wrong colour on the programme
   listing.** Root cause pinned to `functions/account-bookings.php` ~line 1296-1300: the
   `'external'` state's no-URL branch returns `array('label' => ..., 'disabled' => true)` with NO
   `class` key, so `parts/loop/event.php` line 229 falls back to bare `.button` (theme default
   blue, `rgb(23,121,186)`). The sibling live-URL branch two lines below correctly sets
   `'class' => 'orange law-event-card__button--book'`. On the single event page the same disabled
   button IS orange (opacity 0.55 over the real orange), so this is a listing-card-only
   inconsistency, easy one-line fix (add the same class key to the disabled branch).
4. **"Preview event" is a dead link for a draft external event.** The committee detail view
   intentionally keeps both Edit and Preview live for an external event's `law-draft` (
   `templates/account-dashboard.php` line 152, `$law_detail_editable` explicitly ORs in
   `$law_is_external_event`, comment: "An external event's draft is the committee's own... keeps
   both"). But the `?preview-event=<id>` renderer at the top of the same template (~line 45)
   builds `$law_preview_statuses` as every status except `law-draft`, with no exception for
   external — so clicking Preview on a draft external event silently falls through to the full
   105-row events-dashboard list instead of a preview, no error shown.

**Everything else passed cleanly**, including some fiddly things worth remembering:
- Public programme cards, the Organiser filter (`law_run_by=external`/`host`, NOT `hosted`),
  single-event pages (LCIA 8 sessions, Law Rocks "7:45pm onwards" reads correctly, not backwards),
  Register `target=_blank rel="noopener noreferrer"` with no booking dialog ever opening.
- Committee dashboard: Create button, External badges, Run-by filter, "Draft (external)" status
  filter all correct.
- Create form: every field incl. both sector "please specify" reveals, speakers/sessions repeaters
  add/remove, all round-trip correctly on save-as-draft → reopen → publish.
- Editing the real migrated LCIA event (post ID **6003** — the dashboard table's visible "1588" is
  a *different*, legacy GF-entry-ID reference shown for display only, not the real post ID; don't
  navigate `?law_external=<that number>`, it silently falls through to the plain dashboard list
  with no error) pre-filled every field including all 8 sessions; changing venue and saving kept
  everything else intact. Restored the venue back afterwards since this is real migrated data.
- `?law_book=1` / `?law_dialog=1` on an external event correctly refuse a booking form
  (`law_booking_guard_form_open()` shared with the real submit-time guard): dialog fragment is a
  clean empty 404, inline path renders zero `<form>` tags.
- Mobile (375px) create form and dashboard table both reflow cleanly, no overflow.

**Method notes for next time:**
- `wp post list --post_status=any` does NOT surface the custom `law-draft` status (WP_Query "any"
  only spans registered/queryable statuses) — a post genuinely saved as a draft can look "missing"
  this way. Query `wp_posts` directly (`wp db query "SELECT ... WHERE ID=..."`) to check.
- To tell whether a form submission really reached the server vs. was blocked client-side, don't
  trust the `_wpnonce` field value staying the same (WP nonces are stable within a tick window,
  so an unchanged nonce does NOT prove no round trip happened) — use
  `playwright-cli requests --static | grep admin-post` instead, or check for the `law_notice=`
  query param actually changing.
- `wp-cli.phar eval` needs `php -d memory_limit=512M` on this box or it OOMs loading Pods.
