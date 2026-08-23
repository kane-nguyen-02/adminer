# Adminer UI Refactor — State

**Last updated:** 2026-08-22
**Phase:** planning complete; **priority switched to bug fixes** (see `DIAGNOSIS.md`)
**Next action:** D1–D5, E0–E9 and F1–F5 all fixed and verified (fix logs at the end of `DIAGNOSIS.md`). Auto-apply is **commit-only**. Toolbar is down to one row (1481px -> 1137px). Open: confirm Enter-in-search by hand; decide whether the grid should scroll in its own container (would end horizontal page scroll on wide tables); confirm the Action icon styling is acceptable. **Login redesign DONE** (round 6, flat/reference style). Dock refactor still paused. Round 13 unified toolbar control height at 31px with 7px radius, paid for out of fieldset padding (grid moved up 8px, fonts untouched). Round 12 added text-length persistence, sort-direction buttons synced to column headers, a Ctrl+B collapsible sidebar, and a zero-query connection switcher. Rounds 7-10 covered login polish, Lucide icons, dynamic icon colour, enum values on structure, JSON pretty-print in the inline editor, and matching auth-button icons.

---

## Documents

| File | Purpose |
|---|---|
| `SPEC.md` | analysis of existing customizations + requirement set (R1–R4) + risk register (K1–K7) |
| `TEST-PLAN.md` | test standard: automated snapshot diff (A1–A10) + manual matrix (M1–M8) |
| `TASKS.md` | phased task breakdown P0–P4 with exit criteria |
| `DIAGNOSIS.md` | **active work** — 6 measured bug root causes from the 2026-08-22 live session |
| `STATE.md` | this file — resume point |

---

## Decisions taken (2026-08-22, confirmed by the user)

| Decision | Choice |
|---|---|
| Scope | Login redesign **+** unified control dock (not the tokens overhaul) |
| Login visual direction | **Isometric plinth** — 360px card on two offset slabs |
| Test rigor | **Normalized HTML snapshot diff + manual checklist** (Playwright declined) |

---

## Environment as observed

| Fact | Value |
|---|---|
| Container | `adminer`, image `cloudbeaver-adminer:6.0.1-mongodb`, up, `network_mode: host` |
| URL | `http://localhost:6868/` |
| Adminer | 6.0.1 (pinned in `adminer/Dockerfile`) |
| PHP | 8.4.24 |
| Serving | `php -S [::]:6868 -t /var/www/html` |
| Files in image | mounted **read-only** from `adminer/` → no rebuild for CSS/plugin edits, just reload |
| DBs up on host | `pg18-restore` (postgres:18), `famtree-postgres-1`, `f3s-postgres`, `postgres_db`, redis ×2 |
| Git branch | `main` |
| Working tree | **dirty — 8 files** (below). Must be resolved before P0. |

Dirty at planning time: `adminer/adminer.css`, `adminer/plugins-enabled/row-highlight.php` (new), `select-check-ui.php` (new, staged), `select-searchable.php`, `docker-compose.yml`, plus untracked `select-row-inspector.php`, `select-smart-filter.php`, `ref/`.

---

## Key findings to carry forward

1. **The linked reference file was a false lead.** `tnthangvn/adminer-docker/src/plugins/login-table.php` is Adminer's stock DB-table auth plugin — no UI at all. The design worth harvesting is that repo's `theme/core.css` (login card at L455–532) and `theme/tokens-*.css`. Reference copies were downloaded to the session scratchpad (`<scratchpad>/tnthang/`); they are **not** in the repo and would need re-downloading in a new session.

2. **The dock is the real bug source.** 7 fixed controls hand-packed across 5 plugins with magic `em` offsets (`.5` / `3.25` / `9.75` / `19.5` / `26.5` / `33.5` / `41`), ~48em total, no plugin aware of the others, 3 of them leaking onto the login page, slot `3.25em` empty there. Every future control addition requires recomputing neighbours. This is why the dock refactor was scoped in alongside the login work.

3. **`select-shortcuts.php` uses `var(--color, inherit)`** — `--color` is undefined in `adminer.css` (our variable is `--fg`). Works only by CSS fallback. Fixed in T1.2.

4. **Three plugins are login-page-critical and must not be touched:** `default-pgsql.php` (`loginFormField()` regex preselecting pgsql), `brand-name.php` (`name()` → `#h1`/`#logo`/"Kane"), `login-ip.php` (empty password from localhost/RFC1918). The redesign is CSS-only specifically so these stay untouched by construction.

5. **`body:has(#username)` is the whole gate.** Verified in Adminer 6.0.1 that only the login form emits `id="username"`. Check A3 exists to re-verify after any Adminer bump — if that ever stops holding, the login styles leak everywhere.

6. **Two plugins are far over the size guideline:** `select-row-inspector.php` 1564 L, `select-smart-filter.php` 1291 L. Deliberately deferred; T2.7 touches only ~25 lines of the inspector's `navigation()`.

---

## Open items

- T0.2 still needs a concrete choice: which DB/user/table the snapshot harness points at. `pg18-restore` on `localhost:5432` is the obvious candidate, but the username and a ≥50-row table are unconfirmed.
- Browser-support assumption unverified against the user's actual browser: the design relies on `:has()` and `color-mix()` (Chrome 111+ / Firefox 128+ / Safari 16.4+). Degrades to a flat login rather than a broken one.

---

## Resume prompt for a fresh session

> Read `docs/ui-refactor/SPEC.md`, `TEST-PLAN.md`, `TASKS.md`, `STATE.md`. Adminer runs in docker compose at `localhost:6868`, files mounted read-only from `adminer/`. Continue from the "Next action" line at the top of `STATE.md`. Do not touch `default-pgsql.php`, `brand-name.php`, or `login-ip.php`.

---

## 2026-08-22 — bug-fix session (live repro on 192.168.5.78)

Test target: `http://192.168.5.78:6868/?pgsql=192.168.5.78:5440&username=postgres&db=f3s&ns=public&select=daily_menu_item_sales`, postgres/postgres. Reachable and working; used Chrome DevTools MCP.

Root causes proven (details in `DIAGNOSIS.md`):

- **D1 (critical, unreported):** `select-smart-filter.php` `head()` calls `Adminer\fields()` with no connection check / try-catch → **PHP fatal on any unauthenticated select URL**. 5 unguarded DB calls in that file.
- **D2 (bug 1):** smart-filter wraps `where[n][val]` in `span.ssf-val-cell`, breaking Adminer's `this.parentNode.firstChild` contract in `selectFirstChange()`/`selectSearchSearch()` → the field is never marked changed → excluded from GET submit → search silently does nothing.
- **D3 (bug 4):** `select-row-inspector.php:627` uses `getElementById('form')`, but `#form` is the GET query form with 0 checkboxes; `check[]` + Edit/Clone/Delete live in a second id-less POST form → buttons permanently disabled.
- **D4 (bug 3b):** cell-filter menu clips a 36-char UUID by 18px (266px text vs 248px input) — op select + input + button crammed on one row under `max-width:28em`.
- **D5 (bug 3a):** not reproducible as a blocked keystroke. Real findings: origin is **not a secure context** (`navigator.clipboard` undefined over the LAN IP), and the `execCommand` copy fallback steals focus without restoring it. Needs user repro steps.
- **D6 (bug 2):** deferred until D2 lands.

Confirmed working, do not touch: theme switch, light/dark, table border style/color, panel on/off.

Useful facts learned:
- Adminer's `data-on*` attributes are bound by a delegated handler; `selectFirstChange`/`selectSearchSearch`/`selectSearchKeydown` are readable live off `window`.
- `select-shortcuts.php` action lookups are document-wide and therefore correct; only the inspector scoped to `#form`.
- Reference copies of the tnthangvn `theme/` files were re-downloaded to the session scratchpad again this session.
