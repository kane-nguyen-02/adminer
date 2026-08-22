# Adminer UI Refactor — Task Breakdown

Requirement ids (`R…`) refer to `SPEC.md` §2. Check ids (`A…`/`M…`) refer to `TEST-PLAN.md`.
Order matters: **P0 must complete before any file changes**, otherwise the snapshot baseline is worthless.

Legend: `[ ]` todo · `[~]` in progress · `[x]` done · `[!]` blocked

---

## P0 — Baseline (no production file changes)

- [ ] **T0.1** Write `scripts/ui-snapshot.sh` (capture / diff modes, env-var credentials, noise normalization per TEST-PLAN Layer A). Mark it executable.
- [ ] **T0.2** Decide and record the snapshot credentials for this machine: which DB on `localhost` (`pg18-restore` on 5432 is up), which user, which table has ≥50 rows. Export as `AD_*` env vars — **never commit them.**
- [ ] **T0.3** Run `scripts/ui-snapshot.sh before`. Confirm all nine pages captured with HTTP 200 and no `SKIPPED`.
- [ ] **T0.4** Branch (`ui/login-redesign`) — current branch is `main`, branch before editing. Commit `docs/`, `scripts/`, `snapshots/before/` so the baseline is recoverable.
- [ ] **T0.5** Deal with the pre-existing dirty tree (8 modified/untracked files per git status) — commit or stash first, so later diffs cannot be misattributed to this refactor. **Do not start with a dirty tree.**

> **Exit:** `snapshots/before/` holds nine normalized files; `git status` clean apart from the new docs.

---

## P1 — Tokens (R3)

- [ ] **T1.1** Add `--shadow`, `--lift-3`, `--mono-stack` to the `html{}` block in `adminer/adminer.css`, with a comment stating why `--shadow` is `#000`-derived (works across all 12 palettes with no per-theme override).
- [ ] **T1.2** Fix `var(--color, inherit)` → `var(--fg)` in `select-shortcuts.php` (2 occurrences — the help panel and the FAB block). **R3.1**
- [ ] **T1.3** Verify **A8** (no undefined vars) passes.

> **Exit:** A8 green. No visual change yet — reload and confirm nothing moved.

---

## P2 — Unified dock (R1)

- [ ] **T2.1** Write `adminer/plugins-enabled/ui-dock.php`:
  - `head()` — restore `data-dock` from cookie `adminer_dock` pre-paint (mirror `theme-switcher.php`'s pattern exactly)
  - `navigation()` — emit `<div id="ui-dock">` + `<button id="ui-dock-toggle" aria-expanded>` + the adoption script
  - adoption on `DOMContentLoaded`, sort by `data-ui-dock` ascending, `appendChild` (**R1.2**)
  - opportunistic ☀ adoption with the mandatory no-op fallback (**R1.4**)
  - `$translations` for `en` + `vi`, matching the house style of the other plugins
- [ ] **T2.2** Add `#ui-dock` styles to `adminer.css`: flex layout, wrap-reverse, collapsed state via `html[data-dock="closed"]`, `z-index:10001` (**R1.6**, **R1.10**)
- [ ] **T2.3** Mount `ui-dock.php` read-only in `docker-compose.yml`
- [ ] **T2.4** Migrate `theme-switcher.php` → slot 20, strip inline fixed positioning. Confirm its `getElementById` binding survives reparenting (**K4**)
- [ ] **T2.5** Migrate `table-style-switcher.php` → slots 30/40, drop the `$selCss` offsets, add `data-ui-dock-login="hide"` to both (**R1.8**)
- [ ] **T2.6** Migrate `row-highlight.php` → slot 50
- [ ] **T2.7** Migrate `select-row-inspector.php` → slot 60. **Only `navigation()` (~L35–60). Do not touch the other ~1500 lines.**
- [ ] **T2.8** Migrate `select-shortcuts.php`: FAB → slot 70; help panel keeps its own `position:fixed` but re-anchors to `bottom:3em` (**R1.9**). Verify the FAB↔panel open/close pairing survives the split (**K7**, check **M7**)
- [ ] **T2.9** Run **A6**, **A7**, **A9**, **A10**
- [ ] **T2.10** Run **M5** and **M6** in full

> **Exit:** A6/A7/A9/A10 green; M5 + M6 fully ticked; `grep -nE "right: *[0-9.]+em" adminer/plugins-enabled/*.php` silent.

---

## P3 — Login redesign (R2)

All CSS, appended as one clearly-commented section in `adminer/adminer.css`. **No PHP, no new HTML.**

- [ ] **T3.1** Page frame — `body:has(#username)` centering grid + layered background (**R2.2**)
- [ ] **T3.2** Card — `#content` sizing, gradient, border, radius, `--lift-3` shadow, `isolation` (**R2.3**)
- [ ] **T3.3** Plinth — the two `::before`/`::after` offset sheets (**R2.4**)
- [ ] **T3.4** Form — `table.layout` → block, monospace `th` labels, full-width controls (**R2.5**)
- [ ] **T3.5** Submit button + permanent-login label (**R2.6**)
- [ ] **T3.6** Footer — `#menu` static flex row, `#lang`, hide `#menuopen`, dock inline (**R2.7**)
- [ ] **T3.7** Error/message margins (**R2.8**)
- [ ] **T3.8** `max-width:800px` — hide plinth, flatten card (**R2.9**)
- [ ] **T3.9** `prefers-reduced-motion` block (**R2.10**)
- [ ] **T3.10** Run **A2**, **A3**, **A4**, **A5**
- [ ] **T3.11** Run **M1**, **M2**, **M3**

> **Exit:** A2–A5 green; M1 + M2 + M3 fully ticked. The driver select must still preselect PostgreSQL and empty-password localhost login must still work — the two easiest things to break and the least obvious.

---

## P4 — Full verification

- [ ] **T4.1** `scripts/ui-snapshot.sh after` && `diff`
- [ ] **T4.2** **A1** — walk the diff line by line. Every line must be an expected dock delta. Anything else → open a defect, do not rationalize it.
- [ ] **T4.3** **M4** — the 12-palette sweep (11 dark + light). Longest task; do not shortcut it. Palette-specific contrast failures are the most likely surviving defect class.
- [ ] **T4.4** **M7** — walk every select-page plugin
- [ ] **T4.5** **M8** — accessibility
- [ ] **T4.6** Update `STATE.md`: mark phases done, record what was discovered
- [ ] **T4.7** Commit per phase with conventional messages (`feat:` / `refactor:` / `fix:`), then merge to `main`

> **Exit:** every A green, every M ticked, `snapshots/after/` committed as the new baseline.

---

## Deferred (agreed out of scope — do not start)

- Mount `ref/select.css|js` + `ref/datepicker.css|js` (harvested from the reference repo's `theme/`, currently unused)
- Rename tokens to the reference's `--panel/--raise/--edge` scheme
- Split `select-row-inspector.php` (1564 L) and `select-smart-filter.php` (1291 L) — both far past the 800-line guideline, but touching them is its own project
- Playwright E2E (offered, declined this round in favour of snapshot + manual)

---

## Sequencing note

P1 → P2 → P3 is deliberate. P2 changes the footer/dock; P3 restyles the footer on the login page. Doing P3 first would mean styling a layout P2 then replaces, and any bug would be ambiguous between the two. If time runs short, **stopping after P2 is a coherent shippable state** — the dock cleanup stands alone without the login redesign.
