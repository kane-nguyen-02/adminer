# Adminer UI Refactor — Spec

**Status:** approved, not yet implemented
**Date:** 2026-08-22
**Runtime:** Adminer 6.0.1 (pinned in `adminer/Dockerfile`), PHP 8.4.24, `php -S [::]:6868`, `network_mode: host`
**Scope decided:** login page redesign **+** unified control dock
**Design direction:** isometric plinth (harvested from `tnthangvn/adminer-docker` `theme/core.css`)
**Test standard:** normalized HTML snapshot diff + manual matrix checklist (see `TEST-PLAN.md`)

---

## 1. Analysis of what already exists

### 1.1 Correction on the referenced file

The linked file — `tnthangvn/adminer-docker/src/plugins/login-table.php` — is **Adminer's stock upstream plugin** (author: Jakub Vrana, Apache-2.0/GPL-2.0). It authenticates against a `login` table and contains **zero** HTML/CSS/JS. It is not a UI customization and has nothing to harvest for a redesign. The same file already ships inside our own image at `/var/www/html/plugins/login-table.php`.

The actual design work in that repo lives in:

| File | What it holds | Relevance |
|---|---|---|
| `theme/core.css` (562 L) | structure layer, colour-free; login card at L455–532 | **primary source of ideas** |
| `theme/tokens-light.css` / `tokens-dark.css` | 2 palettes ("Instrument Day/Night") | reference only — we already have 11 |
| `src/index.php` | bootstrap: `css()` hook, per-page asset loader, `LoginDefaults` | pattern reference |
| `theme/select.css/js`, `datepicker.css/js` | already copied into our `ref/`, **not mounted** | out of scope this round |

### 1.2 What our repo already does (must not regress)

18 plugin files, 5708 lines of CSS+PHP. Confirmed hook usage:

| Plugin | Hooks | Notes |
|---|---|---|
| `brand-name.php` | `name()` | returns non-null → core skips default title. Renders `#h1` + `#logo`. |
| `default-pgsql.php` | `loginFormField()` | **rewrites the driver `<select>` to preselect pgsql** by regex on `value=(['"])pgsql\1`. Login-page-critical. |
| `login-ip.php` | (constructor only) | empty passwords allowed from `127.`/`::1`/RFC1918 + `::ffff:` mapped. |
| `menu-links.php` | — | `AdminerMenuLinks('select')`. |
| `mongo.php`, `redis.php` | driver includes | — |
| `theme-switcher.php` | `head()`, `navigation()` | sets `data-theme` pre-paint from `adminer_theme` cookie; renders `<select>` **on every page incl. login**. |
| `table-style-switcher.php` | `head()`, `navigation()` | sets `data-table-border-style` / `-color`; 2 `<select>`s **on every page incl. login**. |
| `row-highlight.php` | `head()`, `navigation()` | gated on `$_GET['select']`. |
| `select-row-inspector.php` | `head()`, `navigation()` | gated on `$_GET['select']`; changing it does `location.reload()`. |
| `select-shortcuts.php` | `head()` | gated on `select`+`edit`; fixed FAB + help panel. |
| `select-searchable.php`, `select-smart-filter.php`, `select-check-ui.php`, `cell-filter.php`, `row-num.php`, `select-limit-persist.php` | `head()` / `headers()` | select-page features; own `position:fixed` overlays at z-index 10040–10060. |

### 1.3 The core problem this refactor fixes

The bottom-right edge is **hand-packed with magic `em` offsets across 5 independent plugins**, none of which knows about the others:

| `right:` | Control | Pages | Owner | Width cap |
|---|---|---|---|---|
| `.5em` | ☀ dark toggle | all | Adminer core (`dark-switcher`) | — |
| `3.25em` | Shortcuts FAB / help panel | select, edit | `select-shortcuts.php` | 16–26em when open |
| `9.75em` | Dark theme | **all, incl. login** | `theme-switcher.php` | 9em |
| `19.5em` | Border style | **all, incl. login** | `table-style-switcher.php` | 6.5em |
| `26.5em` | Border color | **all, incl. login** | `table-style-switcher.php` | 6.5em |
| `33.5em` | Row highlight | select | `row-highlight.php` | 7.5em |
| `41em` | Row panel | select | `select-row-inspector.php` | 6.5em |

Consequences, all verified against the live page at `http://localhost:6868/`:

1. **~48em of hardcoded horizontal packing.** Below ~800px viewport the controls overlap each other and the content.
2. **Slot `3.25em` is empty on the login page** (shortcuts is select/edit-only) → a visible gap in the row.
3. **Three controls leak onto the login page** (theme + 2× border) where border styling is meaningless.
4. **Adding any control requires manually recomputing every neighbour's offset** — the single highest-probability source of future UI bugs.
5. `select-shortcuts.php` uses `var(--color, inherit)` — **`--color` is not defined anywhere** in `adminer.css`. Our variable is `--fg`. Silent fallback to `inherit`; works by accident.

### 1.4 CSS variable inventory

Defined in `adminer.css`: `--bg --fg --dim --lit --border --accent --accent-fg --accent-hover --muted --warn-bg --warn-fg --radius --radius-sm --table-border-style --table-border-color` (+ `--bg-color --border-color --text-color` in narrow spots).

Consumed by plugins: all of the above **plus** the undefined `--color`.

`adminer-dark.css` `@import`s `adminer.css` and overrides only the variable block, 11 palettes under `:root[data-theme="…"]`. **Rule to preserve: structure/selectors go in `adminer.css`, colour values only in `adminer-dark.css`.**

### 1.5 Live login DOM (Adminer 6.0.1) — the contract we style against

```
body.adminer
├── #help.jush-sql.jsonly.hidden
├── #content
│   ├── span#menuopen.jsonly > button.icon.icon-move
│   ├── h2                     "Login"
│   ├── #ajaxstatus.jsonly
│   └── form[method=post]
│       ├── div > input[hidden name=token]
│       ├── table.layout       (5 rows: System / Server / Username / Password / Database)
│       │                       th = label, td = control; #username has autofocus
│       └── p > input[type=submit] + label > input[type=checkbox name="auth[permanent]"]
└── #foot.foot
    └── #menu
        ├── big[data-onclick="adminerDarkSwitch()"]      ☀   (core, inline position:fixed)
        ├── select#adminer-table-border-style            (inline position:fixed)
        ├── select#adminer-table-border-color            (inline position:fixed)
        ├── select#adminer-theme-switcher                (inline position:fixed)
        ├── h1 > a#h1 > img#logo + "Kane" + span.version
        └── form > #lang > label > select[name=lang]
```

Stylesheet load order on every page:
`?file=default.css` → `?file=dark.css` (media toggled by JS) → `adminer.css?v=<mtime>` → `adminer-dark.css?v=<mtime>` (media `(prefers-color-scheme: dark)`, flipped by `adminerDarkSet()` because the href contains `dark.css`).

---

## 2. Requirements

### R1 — Unified control dock (`ui-dock.php`)

**R1.1** A new plugin `adminer/plugins-enabled/ui-dock.php` renders exactly one container:
`<div id="ui-dock">` in `navigation()`.

**R1.2** Adoption is **client-side and order-independent.** On `DOMContentLoaded` the dock JS collects `document.querySelectorAll('[data-ui-dock]')`, sorts by the integer value of that attribute ascending, and `appendChild`s each into `#ui-dock`. Plugin load order therefore does not matter and no plugin needs a PHP reference to any other.

**R1.3** Each migrated control plugin:
- drops `position:fixed`, `right:`, `bottom:`, `z-index` from its inline style,
- adds `data-ui-dock="<n>"` with the slot number from R1.7,
- keeps its own `id`, `title`, cookie name, and change handler **byte-for-byte unchanged**.

**R1.4** The core ☀ toggle is adopted opportunistically via `[data-onclick="adminerDarkSwitch()"]`. If the selector finds nothing (upstream change), the dock leaves it alone and the button keeps working from its own inline `position:fixed`. **This fallback is mandatory** — the dock must never be able to hide the dark toggle.

**R1.5** Collapse: a `<button id="ui-dock-toggle" aria-expanded>` sits at the right end. Collapsed → only the toggle and ☀ are visible. State persists in cookie `adminer_dock` (`open`/`closed`, 30 days), restored in `head()` before paint by setting `data-dock` on `<html>`, same mechanism as `theme-switcher.php`. **Default: `closed`.**

**R1.6** Layout: `#ui-dock` is `position:fixed; right:.5em; bottom:.5em; display:flex; flex-flow:row-reverse wrap-reverse; gap:.35em; align-items:center; max-width:calc(100vw - 1em)`. Wrapping upward is what removes the overlap failure mode — no offsets anywhere.

**R1.7** Slot order (right → left; ascending `data-ui-dock` sits nearest the corner):

| slot | control | element id |
|---|---|---|
| 10 | ☀ dark toggle | (adopted, no id) |
| 20 | Dark theme | `#adminer-theme-switcher` |
| 30 | Border style | `#adminer-table-border-style` |
| 40 | Border color | `#adminer-table-border-color` |
| 50 | Row highlight | `#adminer-row-highlight` |
| 60 | Row panel | `#adminer-row-inspector` |
| 70 | Shortcuts FAB | `#select-shortcuts-fab` |

**R1.8** `data-ui-dock-login="hide"` on the two border selects hides them on the login page via CSS. The theme select stays (you must be able to pick a palette before logging in).

**R1.9** The shortcuts **help panel** (`#select-shortcuts-help`, 16–26em) keeps its own `position:fixed` and anchors **above** the dock: `bottom: 3em`. Only its FAB is docked.

**R1.10** `#ui-dock` z-index is `10001` — unchanged from today's controls, and below the select-page overlays (10040–10060) so panels still cover it.

**R1.11** No-JS degradation: without JS, `[data-ui-dock]` controls render inline inside `#menu`. Acceptable — Adminer's own UI is JS-dependent and `<html>` already carries `nojs`/`js` classes.

### R2 — Login page redesign

**R2.1 Selector gate.** Every login rule is scoped under `body:has(#username)`. `#username` exists only on the login form. No new HTML, no new PHP hook — **CSS only**, so `default-pgsql.php`'s `loginFormField()` regex, `brand-name.php`'s `name()`, and `login-ip.php` are untouched by construction.

**R2.2 Page frame.** `body:has(#username)` becomes a centering grid: `display:grid; align-content:center; justify-items:center; gap:20px; min-height:100vh; padding:24px; box-sizing:border-box`, with a layered background: a radial accent wash top-centre (8% `--accent`), a radial `--dim` wash bottom-right, over `--bg`.

**R2.3 The card.** `#content` → `width:min(360px,100%)`, `padding:28px 28px 24px`, `border:1px solid var(--border)`, `border-radius:10px`, vertical gradient from `--lit`-tinted top to `--dim`, `box-shadow: var(--lift-3)`, `isolation:isolate`, `position:relative`.

**R2.4 The plinth.** Two `::before`/`::after` sheets at `z-index:-1`, `inset:0`, same radius+border:
- `::before` — `translate(7px,9px)`, background `color-mix(in srgb, var(--lit) 60%, var(--dim))`
- `::after` — `translate(14px,18px)`, background `color-mix(in srgb, var(--accent) 12%, var(--dim))`, border `color-mix(in srgb, var(--accent) 30%, var(--border))`

**R2.5 Form.** `table.layout` and its `tbody/tr/th/td` → `display:block; width:100%; border:0; background:none`. `th` becomes a small monospace label (`font:500 11px/1.4 var(--mono-stack)`, `color:var(--muted)`, `text-align:left`, `padding:0 0 5px`). `td` `padding:0 0 13px`. Inputs and selects `width:100%; padding:8px 10px`.

**R2.6 Submit.** `#content p input[type=submit]` → full width, `padding:9px 12px`, `font-weight:600`, vertical gradient `--accent-hover`→`--accent`, inner top highlight, accent glow. The "Permanent login" `label` becomes a flex row with 7px gap, `12px`, `--muted`.

**R2.7 Footer.** `#menu` on the login page → `position:static; width:auto; margin:0; display:flex; flex-flow:row wrap; justify-content:center; gap:16px; background:none; border:0`. `#lang` → `position:static; font-size:12px; color:var(--muted)`; its select `width:auto`. `#menuopen` (hamburger) → `display:none`. `#ui-dock` also goes `position:static` on login and joins that footer row, so nothing floats over a centred card.

**R2.8 Errors.** `.error` / `.message` inside the card get `margin:0 0 16px` so a failed login does not shift the card.

**R2.9 Mobile.** Under `800px`: plinth pseudo-elements `display:none` (they would clip against the viewport edge); card goes flat.

**R2.10 Reduced motion.** `@media (prefers-reduced-motion: reduce)` kills transitions on login elements.

### R3 — Minimal token additions

Only what R2 strictly needs, added to `adminer.css`'s `html{}` block:

```css
--shadow: color-mix(in srgb, #000 28%, transparent);
--lift-3: inset 0 1px 0 color-mix(in srgb, var(--fg) 7%, transparent),
          0 10px 30px -12px var(--shadow),
          0 30px 60px -30px var(--shadow);
--mono-stack: ui-monospace, "JetBrains Mono", SFMono-Regular, Menlo, Consolas, monospace;
```

Because `--shadow` is built from `#000`, it reads correctly under all 11 dark palettes and the light one with **no per-theme override**. Verify anyway (TEST-PLAN M4).

**R3.1 Fix `var(--color, inherit)` → `var(--fg)`** in `select-shortcuts.php` (2 occurrences). Pure correctness; the current value works only by fallback.

### R4 — Non-goals (explicitly out of scope this round)

- Mounting `ref/select.css|js`, `ref/datepicker.css|js`.
- Full token-system overhaul (`--panel/--raise/--edge` naming).
- Any change to `adminer-dark.css` palettes.
- Any change to select-page features (search, smart filter, row inspector internals, cell filter, row-num, check UI).
- Any new PHP hook on the login path.

---

## 3. Files touched

| File | Change | Risk |
|---|---|---|
| `adminer/plugins-enabled/ui-dock.php` | **new**, ~150 L | low — additive |
| `adminer/adminer.css` | +3 vars, `#ui-dock` block, `body:has(#username)` block (~120 L) | medium — shared file |
| `adminer/plugins-enabled/theme-switcher.php` | strip inline fixed → `data-ui-dock="20"` | low |
| `adminer/plugins-enabled/table-style-switcher.php` | strip `$selCss` offsets → slots 30/40 + `data-ui-dock-login="hide"` | low |
| `adminer/plugins-enabled/row-highlight.php` | strip offsets → slot 50 | low |
| `adminer/plugins-enabled/select-row-inspector.php` | strip offsets → slot 60 (`navigation()` only, L35–60) | low — do **not** touch the other 1500 L |
| `adminer/plugins-enabled/select-shortcuts.php` | FAB → slot 70; help panel `bottom:3em`; `--color`→`--fg` | medium — two coupled fixed elements |
| `docker-compose.yml` | mount `ui-dock.php` read-only | low |
| `scripts/ui-snapshot.sh` | **new** test harness | none |

Files **not** to touch: `brand-name.php`, `default-pgsql.php`, `login-ip.php`, `menu-links.php`, `mongo.php`, `redis.php`, `adminer-dark.css`, `Dockerfile`, `cell-filter.php`, `row-num.php`, `select-check-ui.php`, `select-searchable.php`, `select-smart-filter.php`, `select-limit-persist.php`.

---

## 4. Known risks

| # | Risk | Mitigation |
|---|---|---|
| K1 | `[data-onclick="adminerDarkSwitch()"]` is an undocumented coupling to core markup | R1.4 fallback; Adminer pinned to 6.0.1 in the Dockerfile |
| K2 | `body:has(#username)` also matching some *other* page carrying `#username` | Verified: only the login form emits `id="username"` in 6.0.1. Re-check after any Adminer bump (TEST-PLAN A3) |
| K3 | `:has()` + `color-mix()` browser support | Chrome 111+/Firefox 128+/Safari 16.4+. Stated assumption; a browser without them degrades to today's flat login, not a broken one |
| K4 | Dock adoption reparents nodes → a plugin's JS holding a stale reference | All migrated plugins bind by `getElementById` at load and mutate cookies only; reparenting preserves listeners. Verified per plugin in TASKS T2.x |
| K5 | `#foot` / `#menu` collapse rules in `default.css` under 800px | R2.9 + TEST-PLAN M3 (test at 375/768/1024/1920) |
| K6 | Login-page CSS bleeding into normal pages | Every rule gated on `body:has(#username)`; snapshot diff (TEST-PLAN A1) proves DOM parity elsewhere |
| K7 | Splitting the shortcuts FAB from its help panel breaks their open/close pairing | They share `right:3.25em` today; R1.9 separates them deliberately. Manual check M7 |
