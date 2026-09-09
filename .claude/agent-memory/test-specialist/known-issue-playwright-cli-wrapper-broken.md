---
name: known-issue-playwright-cli-wrapper-broken
description: The global `playwright-cli` shell wrapper resolves to the wrong npm package (official `playwright` CLI, not the automation tool the SKILL.md documents) — use the resolved @playwright/cli binary directly instead
metadata:
  type: project
---

**Status as of 2026-09-09: confirmed, workaround in place.**

The global `playwright-cli` command (`/home/gusev/.local/bin/playwright-cli`) is a generic `mise`/npx shim that hardcodes `package="playwright"`. That resolves to Microsoft's official `playwright` npm package's CLI (`Usage: npx playwright ...` — `open`, `codegen`, `test`, etc., no `goto`/`click`/`snapshot`/`screenshot <target>` subcommands as described in the SKILL.md quick start). Running `playwright-cli open <url>` under this shim launches an interactive `playwright open` session that hangs/backgrounds and does not accept the SKILL's documented commands — it is silently the wrong tool, not a broken invocation.

The actual tool the SKILL.md commands (`open`, `goto`, `click`, `snapshot`, `screenshot [target]` with CSS-selector targets, `eval`, `resize`, etc.) belong to is the npm package **`@playwright/cli`** (the old `playwright-cli` npm package is deprecated in favour of it, per its own install-time warning). Resolve and call it directly:

```bash
node_root=$(mise where node@latest)
BIN=$("$node_root/bin/npx" --yes --package @playwright/cli -- which playwright-cli)
# BIN is a symlink like .../node_modules/.bin/playwright-cli -> ../@playwright/cli/playwright-cli.js
"$BIN" open http://localhost/law/...
"$BIN" resize 1440 900
"$BIN" screenshot ".css-selector" --filename=.playwright/screenshots/name.png
```

The resolved path is stable across calls within a session (npx caches the package), so export it once (e.g. `export PWCLI=<path>`) and reuse — but remember Bash tool state does NOT persist between tool calls in this harness, so re-resolve or hardcode the path at the start of every command that needs it, or write it to a scratch var each time.

**Why this matters:** following the SKILL.md quick start literally against the bare `playwright-cli` global command produces official-Playwright-CLI help/errors that look like a version mismatch or misuse, not a "wrong package" error — easy to lose time on. `screenshot`'s `[target]` argument accepts a plain CSS selector directly (not just a snapshot ref), which is the fastest way to capture a specific block like `.law-flagship-card` or `.law-timeline-section` without a full-page shot.

**How to apply:** at the start of any test-specialist session, don't trust the bare `playwright-cli` alias. Resolve `@playwright/cli`'s bin path once and call that directly for the whole session.
