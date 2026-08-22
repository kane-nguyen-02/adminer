# Adminer UI Refactor — Test Standard

Companion to `SPEC.md`. The goal is narrow and concrete: **prove that nothing outside the intended scope changed.** Every existing feature listed in `SPEC.md` §1.2 gets an explicit check.

Two layers:

- **A — Automated:** normalized HTML snapshot diff via `scripts/ui-snapshot.sh`. Catches accidental DOM changes and plugin breakage on pages nobody thought to look at.
- **M — Manual:** a matrix checklist. Catches what HTML cannot show — visual regressions, overlap, theme mismatch.

**Definition of done:** every A check passes, every M row is ticked, and the M4 theme sweep is done on all 11 dark palettes + light.

---

## Layer A — Automated snapshot diff

### Harness

```bash
scripts/ui-snapshot.sh before
```

Captures each page below into `docs/ui-refactor/snapshots/before/`, normalizing per-request noise so diffs are meaningful:

| Normalized away | Why |
|---|---|
| `nonce="…"` | new CSP nonce per request |
| `name='token' value='…'` / `name="token" value="…"` | new CSRF token per request |
| `?v=<digits>` on `adminer.css` / `adminer-dark.css` | mtime cache-buster changes on every edit |

Then implement, then:

```bash
scripts/ui-snapshot.sh after
scripts/ui-snapshot.sh diff
```

### Credentials

`ui-snapshot.sh` reads `AD_DRIVER` / `AD_SERVER` / `AD_USER` / `AD_PASS` / `AD_DB` / `AD_TABLE` from the environment. Nothing is hardcoded. If `AD_USER` is unset it captures only the unauthenticated pages (A2/A3) and prints an explicit `SKIPPED` line for the rest — **a skip must never read as a pass.**

### Pages captured

| id | Page | URL shape |
|---|---|---|
| `login` | login form | `/` |
| `login-err` | failed login (error banner) | `/` POST with bad password |
| `login-lang` | login after switching language to `vi` | `/` POST `lang=vi` |
| `db` | database list | `/?pgsql=<server>&username=<user>` |
| `tables` | table list of one DB | `…&db=<AD_DB>` |
| `select` | select data | `…&select=<AD_TABLE>` |
| `structure` | table structure | `…&table=<AD_TABLE>` |
| `edit` | edit-row form | `…&edit=<AD_TABLE>` |
| `sql` | SQL command page | `…&sql=` |

### Checks

- **A1 — Non-login DOM parity.** `diff before/after` for `db`, `tables`, `select`, `structure`, `edit`, `sql` must show **only** the expected dock deltas: `[data-ui-dock]` attributes added, `position:fixed/right/bottom/z-index` removed from the migrated inline styles, and one new `<div id="ui-dock">`. **Any other line in the diff is a defect.** This is the most important check — it is what proves the login redesign did not leak and no select-page plugin lost its markup.
- **A2 — Login DOM parity.** `diff` for `login` must show only those same dock deltas (the redesign is CSS-only). If `table.layout`, `#username`, the `token` input, or the driver `<select>`'s `selected` attribute moved, `default-pgsql.php` or `brand-name.php` broke.
- **A3 — `#username` uniqueness.** `grep -c 'id="username"'` on every captured page: exactly `1` on `login`/`login-err`/`login-lang`, `0` everywhere else. Guards risk K2 — the assumption the whole `body:has(#username)` gate rests on.
- **A4 — Driver preselect intact.** `login` snapshot contains `value="pgsql" selected` or `value='pgsql' selected`. Guards `default-pgsql.php`.
- **A5 — Brand intact.** `login` snapshot contains `id='h1'`, `id='logo'`, and `Kane`. Guards `brand-name.php`.
- **A6 — Every dock slot present exactly once.** On the `select` page: one occurrence each of `adminer-theme-switcher`, `adminer-table-border-style`, `adminer-table-border-color`, `adminer-row-highlight`, `adminer-row-inspector`, `select-shortcuts-fab`, `id="ui-dock"`. Catches a plugin silently failing to render.
- **A7 — No magic offsets left.** `grep -nE "right: *[0-9.]+em" adminer/plugins-enabled/*.php` returns nothing. This is the acceptance criterion for the dock refactor itself.
- **A8 — No undefined CSS vars.** Every `var(--x` used in `adminer/plugins-enabled/*.php` and `adminer/adminer.css` is declared in `adminer.css`. In particular `var(--color` must return zero hits (R3.1).
- **A9 — PHP lint.** `docker exec adminer php -l <file>` on every changed plugin → `No syntax errors detected`.
- **A10 — Clean runtime log.** After walking all nine pages, `docker compose logs adminer --since 5m` contains no `PHP Warning`, `PHP Notice`, `PHP Fatal`, or `adminer:` error lines.

---

## Layer M — Manual checklist

Environment: `http://localhost:6868/`, DevTools console open. **Any console error is a failure.**

### M1 — Login page, happy path

- [ ] Card horizontally and vertically centred; two offset plinth sheets visible below-right
- [ ] Card caps at 360px wide and does not exceed the viewport at 375px
- [ ] Labels (System/Server/Username/Password/Database) are small monospace, left-aligned above their control
- [ ] Every input and the driver select is full-card-width
- [ ] **Driver select shows "PostgreSQL" preselected** (`default-pgsql.php`)
- [ ] Server field prefilled `localhost` (`ADMINER_DEFAULT_SERVER`)
- [ ] `#username` still receives autofocus on load
- [ ] Tab order: System → Server → Username → Password → Database → Login → Permanent login
- [ ] Login button full width, gradient, readable contrast
- [ ] "Permanent login" checkbox + label on one aligned row
- [ ] Footer row below the card is centred: Kane brand + version, language select, theme select, dock toggle
- [ ] Hamburger `#menuopen` is not visible
- [ ] No control floats over the card

### M2 — Login page, edge states

- [ ] Wrong password → error banner **inside** the card; card does not jump or change width
- [ ] Empty password from localhost still logs in (`login-ip.php` RFC1918 allowance)
- [ ] Switch to Tiếng Việt → reloads, layout intact, Vietnamese labels do not overflow the card
- [ ] Switch to an RTL language (`ar`/`he`) → card intact, `dir=rtl` honoured
- [ ] Browser password autofill fills username+password without shifting layout
- [ ] Log out → returns to the redesigned login page, fully styled
- [ ] Login with the **MongoDB** driver and with the **Redis** driver still works (guards the two Dockerfile patches)

### M3 — Responsive

Repeat M1's card checks at each width:

- [ ] 375px — plinth hidden, card flat, nothing clipped, no horizontal page scroll
- [ ] 768px — plinth hidden (under the 800px breakpoint), footer row wraps cleanly
- [ ] 1024px — plinth visible
- [ ] 1920px — card stays 360px and centred, not stretched

### M4 — Theme sweep

For **each** of the 11 dark palettes — Tokyo Night, Nord, GitHub Dark Dimmed, One Dark Pro, Dracula, Monokai, Solarized Dark, Gruvbox Dark, Catppuccin Mocha, Night Owl, Ayu Dark — **and** light mode:

- [ ] Card, plinth and shadow all distinguishable from the page background (no invisible card, no black-hole shadow)
- [ ] Login button text readable on its accent gradient
- [ ] Monospace labels legible on the card (`--muted` must not vanish into `--dim`)
- [ ] `#ui-dock` background and borders match the palette
- [ ] Changing palette on the login page applies **without reload and without a flash** of the previous theme

Guards R3 (that `--shadow` needs no per-theme override) and `theme-switcher.php`'s pre-paint restore.

### M5 — Dock behaviour

- [ ] Fresh browser profile → dock starts **collapsed**
- [ ] Expanding shows every control applicable to the current page, in slot order right→left
- [ ] Collapsed/expanded state survives reload (cookie `adminer_dock`)
- [ ] Controls **wrap upward** at 375px instead of overlapping or leaving the screen
- [ ] Dock never covers the last table row, the footer, or a modal
- [ ] ☀ sits in the dock and still toggles light/dark
- [ ] With the dock's inline script blocked, ☀ still works from its own fixed position (R1.4 fallback)
- [ ] On the login page the dock is inline in the footer row, not floating
- [ ] On the login page the two border selects are hidden; the theme select is present

### M6 — Migrated controls still work

- [ ] **Theme switcher** — changes palette live, no reload, cookie `adminer_theme` written
- [ ] **Border style** — solid/dashed/dotted/none applies to a select table immediately, cookie written
- [ ] **Border color** — theme/muted/accent applies immediately, cookie written
- [ ] **Row highlight** — click-highlight vs checkbox behaviour matches the selected mode
- [ ] **Row panel** — toggling reloads and the inspector appears/disappears
- [ ] All five persist across a full reload

### M7 — Select-page features untouched (regression guard)

On a select page with ≥50 rows:

- [ ] `select-shortcuts` — FAB opens the help panel; panel sits **above** the dock without overlapping; every shortcut still fires; Esc closes
- [ ] `select-searchable` — search overlay opens and filters
- [ ] `select-smart-filter` — filter UI opens, applies, clears
- [ ] `select-check-ui` — checkbox selection behaves
- [ ] `select-row-inspector` — panel opens on row click, shows the right row, closes
- [ ] `cell-filter` — cell filter appears correctly positioned (z-index 10050, above the dock)
- [ ] `row-num` — row numbers render in the first column
- [ ] `select-limit-persist` — limit survives navigation
- [ ] `menu-links` — clicking a sidebar table name opens **Select data**, not Structure
- [ ] `tables-filter` — sidebar table filter still filters
- [ ] `edit-foreign`, `edit-textarea`, `enum-option` still render on an edit form
- [ ] `dump-json` still offered as an export format
- [ ] `sql-log` still records

### M8 — Accessibility

- [ ] Each login field keeps an accessible name (Adminer relies on `title`/`placeholder` — do not remove them)
- [ ] `:focus-visible` outline visible on every login control in every palette
- [ ] Dock toggle has a correct `aria-expanded` that flips
- [ ] Full keyboard traversal of login + dock, no trapped focus
- [ ] Login button label contrast ≥ 4.5:1 (spot-check lightest, darkest, Solarized)
- [ ] `prefers-reduced-motion: reduce` → no transitions on login or dock

---

## Rollback criterion

If any A check fails and the cause is not found within one iteration, revert with `git checkout -- adminer/ docker-compose.yml`. Everything here is additive CSS plus one new plugin file — no migrations, no state, no build step. The container mounts these files read-only and `php -S` picks up changes on the next request, so rollback needs a page reload, not a rebuild.
