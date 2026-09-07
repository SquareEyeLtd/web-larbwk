---
name: environment-url
description: The actual working local URL for the LAW site differs from the agent brief's default (law.localhost)
metadata:
  type: project
---

As of 2026-09-04, `http://law.localhost` serves only a bare directory index (Apache `Index of /`), NOT the WordPress app. The real working local install is at `http://localhost/law/` (path-based, not a vhost) — `/law/login/`, `/law/wp-admin/`, etc. all resolve correctly there. Still true as of 2026-09-07 (confirmed again via `get_option('home')`).

**Why:** the local Apache vhost setup changed at some point after the agent instructions were written; `law.localhost` is stale.

**How to apply:** before starting a test pass, verify which base URL actually serves the app (`curl -s -o /dev/null -w '%{http_code}' <url>/login/` — a real login page returns 200, a bare directory index returns 200 with `<title>Index of /</title>`). Use whichever one actually renders the WP site. Don't assume `law.localhost` still works. Check with the user if both are unreachable or ambiguous. WordPress root is `/srv/http/law`, theme at `/srv/http/law/wp-content/themes/larbwk`. Apache error/access logs: `/var/log/httpd/error_log` and `/var/log/httpd/access_log` (system clock is CEST/UTC+2, playwright-cli's own timestamps in filenames are UTC — offset by ~2h when cross-referencing).
