# Bug Diagnosis — 2026-08-22

Reproduced live against `http://192.168.5.78:6868/?pgsql=192.168.5.78:5440&username=postgres&db=f3s&ns=public&select=daily_menu_item_sales` (Adminer 6.0.1, PHP 8.4.24, Chrome DevTools). Every root cause below is **measured**, not inferred.

---

## D1 — CRITICAL, not reported: PHP fatal on any unauthenticated select URL

**Symptom:** white page with a PHP stack trace. Happens on an expired session, a bookmarked select URL, or a fresh browser.

**Reproduced:**
```
Warning: Attempt to read property "server_info" on null in /var/www/html/adminer.php on line 38
Fatal error: Uncaught Error: Call to a member function tableOid() on null in /var/www/html/adminer.php:2831
Stack trace:
#0 /var/www/html/plugins-enabled/select-smart-filter.php(39): Adminer\fields('daily_menu_item...')
#1 [internal function]: AdminerSelectSmartFilter->head(NULL)
#2 /var/www/html/adminer.php(3647): call_user_func_array(Array, Array)
#3 /var/www/html/adminer.php(3922): Adminer\page_header('Login', '', NULL)
#4 /var/www/html/adminer.php(3924): Adminer\auth_error('', Array)
```

**Root cause:** `select-smart-filter.php:33-39` — `head()` gates only on `isset($_GET['select'])` and then calls `Adminer\fields($table)`. On the login page reached via a select URL, `$_GET['select']` is still set but **there is no DB connection**, so `fields()` dereferences a null driver. No connection check, no `try/catch`.

The same file has **five** unguarded DB calls: `Adminer\fields()` at L25, L39, L1250 and `Adminer\connection()->query()` at L1195, L1222, L1266.

**Fix:** gate on an established connection and wrap in `try/catch(\Throwable)`, returning empty on failure — the pattern the reference repo uses in `PageAssets::tableFields()`. A broken plugin should cost one feature, not the whole console.

---

## D2 — Bug 1: Search silently does nothing

**Symptom:** type a value in the Search box, press Enter -> page reloads, no filter applied, the typed value is wiped.

**Reproduced:** typed `ITEM-TWOU`, pressed Enter ->
- URL stayed `...&select=daily_menu_item_sales` with **no `where[...]` params**
- row count stayed 51 (unfiltered)
- `where[0][val]` was back to `""`

Driving the same query programmatically **works** (`SELECT * FROM "daily_menu_item_sales" WHERE "posItemId" = 'ITEM-TWOU' LIMIT 50`, 31 rows) — so the SQL path is fine and the defect is purely in the DOM wiring.

**Root cause.** Adminer 6.0.1's own search handlers, read out of the live page:

```js
function selectFirstChange(){ fire(this.parentNode.firstChild, 'change'); }
function selectSearchSearch(){ if(!this.value){ this.parentNode.firstChild.selectedIndex = 0; } }
```

Both assume the value input's **`parentNode.firstChild` is the sibling `where[n][col]` select** — i.e. that `[col]`, `[op]`, `[val]` are direct children of the same row `<div>`.

`select-smart-filter.php` wraps the value input in `<span class="ssf-val-cell">`, breaking that contract. Measured:

```json
{ "parent": "SPAN.ssf-val-cell",
  "firstChildName": "where[0][val]",
  "isColumnSelect": false,
  "hasSelectedIndex": false,
  "rowChildren": ["SELECT|where[0][col]", "SELECT|where[0][op]", "SPAN.ssf-val-cell|"] }
```

`firstChildName` should be `where[0][col]`. `hasSelectedIndex: false` means `selectSearchSearch()` is a silent no-op on an `<input>`.

Consequences: `selectFirstChange()` fires `change` on the input itself instead of the column select, so Adminer never registers the field as changed, so the field stays "at its `data-default`" and is **excluded from the GET submission**. Hence no `where` param and the wiped value.

Confirmed by unwrapping the input live — `parentNode.firstChild` became `where[0][col]` again.

Note: `select-searchable.php:9` documents this exact constraint in its own header comment — *"firstChild / cloneNode assumptions stay intact"*. `select-smart-filter.php` violates it.

**Fix:** keep the value input a **direct child** of the row `<div>` and place the assist button as a following sibling instead of wrapping. The row already has `display:flex` from smart-filter's own CSS, so layout is unaffected. Audit every other `.ssf-*` wrapper (date/time inputs) for the same violation.

---

## D3 — Bug 4: Panel Edit and Delete do nothing

**Symptom:** row-inspector panel's Save/Delete/Clone buttons are dead.

**Root cause:** `select-row-inspector.php:627` — `const form = document.getElementById('form')`. On Adminer 6.0.1's select page that is the **wrong form**. Measured, all forms on the page:

| # | id | method | `check[]` boxes | submit buttons |
|---|---|---|---|---|
| 0 | **`form`** | get | **0** | `Select` |
| 1 | *(no id)* | **post** | **50** | `Save`, `edit`, `clone`, `delete`, `export`, `import` |
| 2 | — | post | 0 | `Use` (lang) |
| 3 | — | get | 0 | `Use` |
| 4 | — | post | 0 | `logout` |

`#form` is the GET **query** form. The checkboxes and every row action live in form #1, which has no id.

So inside the inspector:
- `form.querySelectorAll('input[name="check[]"]')` -> **0** -> `resolveChecks()` returns `[]` -> `hasChecks = false` -> `refreshActionButtons()` sets `delBtn.disabled = true` and `cloneBtn.disabled = true` **permanently**
- `findActionButton('delete')` -> **null**
- `form.submit()` would submit the GET query form, not the POST action form

**Why the keyboard shortcuts still work:** `select-shortcuts.php:115` searches document-wide — `document.querySelector('input[type="submit"][name="delete"]')` — and finds the real button. Only the inspector scopes to `#form`.

**Fix:** resolve the owning form from the checkbox itself rather than by id:
```js
const form = document.querySelector('input[name="check[]"]')?.form
  || document.getElementById('form');
```
`HTMLInputElement.form` returns the owning form regardless of id.

---

## D4 — Bug 3b: Cell-filter input clips the value

**Symptom:** right-click a UUID cell -> the value in the menu's input is cut off.

**Measured** on `organizationId = b69031ee-57fb-4fcd-a053-c21337943037`:

| metric | value |
|---|---|
| value length | 36 chars |
| text pixel width | 266 px |
| input client width | 248 px |
| **clipped** | **18 px** |
| menu width | 405 px (`max-width: 28em`) |

**Root cause:** `cell-filter.php` puts the operator `<select>`, the value input (`flex: 1 1 8em`) and the `Filter` button on **one** `.cell-filter-row`, inside a menu capped at `max-width: 28em`. A 36-char UUID cannot fit in the residual space.

**Fix:** give the value input its own full-width row beneath the operator select, and raise the menu's `max-width` (~34em). Also set a monospace font so UUIDs are readable and width is predictable.

---

## D5 — Bug 3a: "cannot paste with keyboard shortcut" — NOT reproducible as described

Tested on the open cell-filter input; nothing blocks the keystroke:

| test | result |
|---|---|
| `Ctrl+V` keydown `defaultPrevented` | `false` |
| `Ctrl+A` / `Ctrl+C` keydown `defaultPrevented` | `false` |
| `paste` event `defaultPrevented` | `false` |
| input `readOnly` / `disabled` | `false` / `false` |
| menu input holds focus after open | `true` |

`select-shortcuts.php`'s global keydown is correctly guarded (only `Alt+Shift` chords and `Ctrl+S`) and never touches `Ctrl+V`.

**Two real findings that likely explain the experience:**

1. **The origin is not a secure context.** Measured: `location.origin = "http://192.168.5.78:6868"`, `isSecureContext = false`, `navigator.clipboard = undefined`. Over the LAN IP the async Clipboard API does not exist at all — it works only on `localhost`, which is why this behaves differently depending on how you reach Adminer.
2. **The copy fallback steals focus and never restores it.** `select-row-inspector.php:782-797` catches the `navigator.clipboard` failure and falls back to `execCommand('copy')` via a temporary `<textarea>` that it `select()`s and then removes — leaving focus on `<body>`. A subsequent `Ctrl+V` then goes nowhere. The temp textarea is also not positioned off-screen, so it can cause a scroll jump.

So the copy *does* work via the fallback, but focus is lost afterwards. **Needs your exact repro steps** to confirm this is what you hit.

---

## D6 — Bug 2: "search/filter not smooth" — needs specifics

The datepicker and the id dropdown are built by `select-smart-filter.php` / `select-searchable.php`, whose value-input wiring is broken by **D2**. Fixing D2 will change this behaviour materially, so this should be re-assessed after D2 rather than diagnosed against the current broken state.

---

## Confirmed working (do not touch)

Theme switching, light/dark toggle, table border style/color, panel on/off — all verified functional. No console errors on the select page when authenticated.

---

## Recommended fix order

| order | item | why |
|---|---|---|
| 1 | **D1** fatal | console-down severity; smallest, most isolated fix |
| 2 | **D2** search wiring | the headline complaint; unblocks re-assessing D6 |
| 3 | **D3** panel form | precise, small fix, high user value |
| 4 | **D4** input clipping | pure CSS |
| 5 | D5 focus restore | do alongside D4, then confirm repro |
| 6 | D6 | re-assess after D2 lands |

All are independent of the login redesign and dock refactor in `SPEC.md` — these are correctness bugs and should ship first.

---

# Fix log — 2026-08-22

| id | status | files changed |
|---|---|---|
| **D1** fatal on unauthenticated select URL | **FIXED, verified** | `select-smart-filter.php` |
| **D2** search silently does nothing | **FIXED, verified** | `select-smart-filter.php` |
| **D3** panel Edit/Delete dead | **FIXED, verified** | `select-row-inspector.php` |
| **D4** cell-filter clips the value | **FIXED, verified (0px clipped)** | `cell-filter.php`, `adminer.css` |
| **D5** "paste doesn't work" | **FIXED, verified** | `cell-filter.php`, `select-row-inspector.php` |
| D6 "not smooth" | open — awaiting specifics now D2 is fixed | — |

## What changed

**D1** — added `connected()`, `safeFields()`, `safeQuery()` to `AdminerSelectSmartFilter`. All five previously unguarded DB calls now route through them; `head()` returns early when there is no connection. Verified: unauthenticated select URL returns the login page (HTTP 403 + form) with zero PHP errors, and `default-pgsql.php`'s PostgreSQL preselect still applies.

**D2** — `syncRow()` no longer moves the value input into `span.ssf-val-cell`. The span is now inserted *after* the input as a sibling and holds only the assist button and bool chips; a repair branch un-wraps markup left by the older build. CSS moved the input's flex sizing from `.ssf-val-cell input[...]` to `#fieldset-search input[...]`.

Verified: `parentNode.firstChild` is `where[0][col]` again; row children are now `[select|col, select|op, input|val, span.ssf-val-cell]`; submitting produces `where[0][col]=posItemId&where[0][val]=ITEM-TWOU` → `SELECT * FROM "daily_menu_item_sales" WHERE "posItemId" = 'ITEM-TWOU' LIMIT 50`, 31 rows; the search box retains column and value after reload and the Reset button correctly enables. The assist button still renders.

**D3** — the inspector now resolves the row-action form from a checkbox (`document.querySelector('input[name="check[]"]')?.form`) instead of `getElementById('form')`, with `#form` kept as fallback.

Verified: Save is visible and enabled, Delete and Clone are no longer disabled, and clicking Delete reaches its confirm dialog ("Delete 1 row(s)? This cannot be undone."). Cancelled — all 51 rows intact, nothing was deleted.

**D4** — `cell-filter.php` menu `max-width` 28em → 34em, `min-width` 14em → 18em, and `.cell-filter-row` became an explicit 2×2 grid: operator + Filter on the top row, the value input spanning full width beneath. The input is monospace (`--mono-stack`, newly defined in `adminer.css`) with `min-width: min(40ch, 74vw)` — sizing off the content is what actually widens the menu, since `max-width` is only a cap.

Verified: 36-char UUID now **0px clipped** (input 377px vs text 312px), menu 402px, fits the viewport. Checked on `organizationId`, `id`, and `itemName`.

**D5** — two independent fixes:
1. `cell-filter.php`'s `window.addEventListener('scroll', …, true)` now ignores scroll events originating inside the menu. Capture on `window` was catching the input's own horizontal scroll, so pasting anything longer than the box closed the menu mid-paste. Verified: menu survives the input scrolling, and a genuine page scroll still closes it.
2. `select-row-inspector.php`'s `copyText()` now uses `navigator.clipboard?.writeText` (guarded), positions the `execCommand` fallback textarea off-screen, and **restores focus and selection afterwards**. On a non-secure origin — which plain http on a LAN IP is — that fallback is the normal path, so it was stealing focus on every copy and the next Ctrl+V went to `<body>`.

## Regression sweep — all clean

Server-side, authenticated, zero PHP errors on: login, database list, table list, select, structure, edit, SQL. Zero PHP errors in container logs. Zero browser console errors on the select page.

Still present and working: theme switcher, border style, border color, row highlight, row-panel toggle, dark toggle, `#select-reset`, row numbers, smart-filter assist button, searchable column dropdown, shortcuts help panel. `data-theme` / `data-table-border-*` attributes still applied.

## Operational gotcha discovered

Docker **single-file** bind mounts pin the inode. An editor that writes atomically (temp file + rename) creates a new inode, so the container keeps serving the old content — the D1 fix appeared not to work until this was spotted (container had 1291 lines, host had 1347). Two consequences:
- edit these plugin files **in place** (truncate + write), which preserves the inode and goes live immediately; or
- `docker compose restart adminer` to re-resolve the mounts.

Consider mounting the whole `plugins-enabled/` **directory** instead of 17 individual files to remove this class of confusion entirely.

## Open question for the user

Pressing **Enter** in the search box: `defaultPrevented` is `false`, the input's form is `#form`, and `#form` contains the `Select` submit button — so native implicit submission is intact and unaffected by these changes. Automated key injection could not reliably drive it (the searchable-dropdown portal interferes when the column select is changed programmatically). **Needs a manual confirmation** that Enter now applies the filter.

---

# Round 2 — UX batch (2026-08-22, approved)

Decisions: auto-apply = **commit actions + 600ms debounce while typing**; toolbar = **icons, compact, nothing hidden**; column type = **dim monospace subline under the column name**.

| id | item | source |
|---|---|---|
| **E0** | Column select swaps on its own (`createdAt` → `itemName`) — correctness, investigate first | screenshots 2→3 + the supplied URL |
| **E1** | Search value inputs are 193px; a 36-char uuid needs ~266px → id clipped | measured |
| **E2** | Suggestion dropdown finds nothing on a near-match → needs substring/fuzzy, not prefix | reported |
| **E3** | Auto-apply on commit + debounced typing, **with focus/caret restore** across the reload | approved |
| **E4** | Panel opens only on checkbox click; default **off** | reported |
| **E5** | Column data type as a dim monospace subline in `thead` | approved |
| **E6** | Limit / Text length / Action → icon buttons, compact layout, more room for Search | approved |

Risk accepted on E3: every apply is a real page reload + SQL query. Mitigated by (a) commit actions only for selects/pickers, (b) 600ms debounce for typing, (c) suppress while a suggestion dropdown is open, (d) skip when the value is unchanged, (e) restore focus + caret after reload.

## Round 2 — fix log

| id | item | status |
|---|---|---|
| **E0** | column select swapped on its own | **FIXED** — `select-searchable.php` |
| **E1** | search value clipped / doesn't grow | **FIXED** — `adminer.css` (`field-sizing: content`) |
| **E2** | suggestions miss near-matches | **FIXED, verified** — filtering pushed into SQL |
| **E3** | auto-apply on commit + debounce | **FIXED, verified** — new `select-autoapply.php` |
| **E4** | panel opens on any cell; default on | **FIXED** — `select-row-inspector.php` |
| **E5** | column data type in header | **FIXED, verified** — `adminer.css`, pure `attr()` |
| **E6** | toolbar icons + layout | **PARTIAL** — icons added *alongside* labels, not replacing them |
| **E7** | sidebar table Filter dead | **FIXED, verified** — new `menu-filter-fix.php` |
| **E8** | autocomplete on every column | **FIXED, verified** |
| **E9** | datepicker opens on focus | **FIXED, verified** |

### E7 — sidebar Filter (this was the original "filter-table" complaint)

Upstream `tables-filter` does `a = qsa('a', table)[1]; text = a.innerHTML.trim()` — it assumes the
default **two-link** sidebar. `menu-links.php` runs in `'select'` mode, which renders **one** link
per row, so `[1]` is `undefined`, the function throws on the first table, and the filter silently
does nothing. Worse, it assigns `tablesFilterValue = value` *before* the throwing line, so after one
failed run it early-returns until the text changes — permanently wedged.

Fixed without giving up the one-link sidebar by using upstream's own escape hatch: seed
`data-table-name` on each `<li>` and `data-link="main"` on the table-name anchor, which routes it
down a branch that never touches index `[1]`. Verified through the real delegated typing path:
`payroll` → 3 rows, `hrm` → 12, cleared → 100, with `<strong>` highlighting.

### E0 — the column that rewrote itself

`pick()` ended with `sel.focus()`, leaving focus on the **column select** after a choice. The next
keystrokes — intended for the value — re-opened the column portal (a printable key opens it with an
`initialQuery`) and Enter picked `items[activeIndex] || items[0]`, overwriting the column. That is
how `createdAt` became `itemName >= '2026-07-16'`. Focus now moves forward to the row's value input.

### E3 — two real bugs found while verifying

1. **No listeners at all.** `head()` emits its script into `<head>`, where `#form` does not exist
   yet, so the initial `if (!form) return;` bailed silently. Everything now runs inside a
   `DOMContentLoaded` boot. Worth remembering for any future plugin using this hook.
2. **Suppression was too broad.** Suppressing while *any* portal was open included the value
   suggestion list, which opens on focus and stays open the whole time you type — so debounced
   auto-apply could never fire for text columns, the main case. Narrowed to the column chooser,
   the date picker and the cell menu.

Also added a 2 s safety valve that clears the `submitting` flag, so a blocked submit can no longer
wedge auto-apply off for the rest of the page's life.

### E6 — partial, and why

Icons are drawn with `mask` + `background: currentColor` so they re-colour with all 12 palettes
instead of being baked to one colour, and Search now flex-grows (1590px) while Limit (67px) and
Text length (99px) shrink to content. But the icons sit **next to** the "Limit" / "Text length" /
"Action" labels rather than replacing them: removing the text in pure CSS means `font-size: 0` or
`clip-path`, both of which strip the control's only accessible name. Replacing the labels properly
needs a small JS plugin that moves the text into `aria-label`/`title` first — offered, not assumed.

### Regression sweep — clean

Zero PHP errors on login / database list / select / structure / edit / SQL, zero on the
unauthenticated select URL, zero browser console errors.

---

# Round 3 — corrections (2026-08-22)

Two items in round 2 were **my regressions**, reported back as "nhiều bug quá". Recorded as such.

| id | item | status |
|---|---|---|
| **F1** | remove the value/id suggestion list — plain input only | **DONE** |
| **F2** | date picker did not close after picking a day | **FIXED, verified** |
| **F3** | search input absurdly long, page pushed out of the viewport (**my regression**) | **FIXED, verified** |
| **F4** | search layout tidy | **DONE** (bounded widths; no rebuild needed) |
| **F5** | Action buttons still text, not icons | **FIXED, verified** |

## F3 — the regression, and what actually caused it

Round 2 used `field-sizing: content` so the input would grow with its text. On a wide table that
was wrong: a big result grid stretches `<body>` beyond the window — measured `body.clientWidth`
2259px against a 1920px viewport on `cashflow_transaction` — and every block inside inherits that
width. The content-sized input followed it and dragged the whole toolbar off-screen.

`max-width: calc(100vw - 2.5rem)` was also wrong: `#content` starts *after* the sidebar
(`margin-left: 302px`), so capping at `100vw` still overshot the right edge by 262px.

Now: the value input is a fixed `38ch` (fits a 36-char uuid, cannot grow), and the toolbar is
capped with `calc(100vw - var(--toolbar-inset))` where the inset accounts for the sidebar, reset to
`2.5rem` under Adminer's own 800px breakpoint. Verified: toolbar right edge 1902px inside a 1920px
viewport, on the widest table in the schema.

Trade-off accepted: the input no longer grows with long text. It shows a full uuid, and longer
values scroll inside it. That is the cost of never overflowing the page, which was the worse bug.

## F2 — picking a day left the picker open

The day handler called `applyValue()` then `renderCalendar()` — it re-rendered and stayed open,
sitting on top of the results it had just filtered. It now closes on pick.

## F5 — why this needed JavaScript

The visible text of an `<input type="submit">` *is* its accessible name, so hiding it in CSS
(`font-size: 0`, `clip-path`) leaves the button with no name for a screen reader or tooltip. The new
`select-toolbar-icons.php` moves the label into `title` + `aria-label` first, then blanks the value
and sets `data-tb-icon`; `adminer.css` draws the glyph. Only unnamed controls are touched, so no
submitted value changes — `edit`/`clone`/`delete`/`export`, whose values Adminer reads, are left
alone. Verified: `run:30px`, `reset:30px`, both keeping `aria-label`.

## One more bug found in my own auto-apply while verifying F2

Picking a date wrote the value but no filter was applied. Cause: `applyValue()` fires `input`
(arming the 600 ms debounce), and Adminer's `selectFirstChange()` turns that same event into a
`change` that reaches my handler — which called `apply()`, whose first statement was `cancel()`.
So the pending debounce was destroyed while the picker was still open, and the apply was silently
dropped. `apply()` now re-arms (300 ms, max 12 tries) instead of discarding the request.

## Regression sweep — clean

Zero PHP errors on login / db list / select / **wide table** / structure / edit / SQL, zero on the
unauthenticated select URL, zero browser console errors. Still working: theme switcher, border
controls, row highlight, panel default-off, type subline, sidebar filter seeding.

Known and accepted: the page still scrolls horizontally when the grid itself is wider than the
window. That is the grid, not the toolbar — fixing it properly means wrapping the table in its own
scroll container, which needs JS and is not in scope here.

---

# Round 4 — corrections (2026-08-22)

| id | item | status |
|---|---|---|
| **G1** | typing must NOT run the query (lag) — "auto complete" meant the dropdown's own filtering, not auto-apply | **FIXED, verified** |
| **G2** | uuid clipped in the search box | **FIXED, verified (0px clipped)** |
| **G3** | Action icons ugly / both identical blue | **FIXED** — primary vs ghost |

## G1 — removing typing-apply took two changes, not one

Deleting the 600 ms debounce was not enough: typing *still* applied the query. Adminer's value input
carries `data-oninput="selectFirstChange()"`, and that handler does
`fire(this.parentNode.firstChild, 'change')` — so **every keystroke dispatches a `change` on the
column `<select>`**, which my commit handler read as "the user picked a column".

Fixed by tracking the last value of every control and ignoring a `change` whose own value did not
actually move. That also keeps the searchable-dropdown portal committing correctly, because there
the value genuinely changes. The value box additionally never commits on `change`/blur now — only
Enter — so leaving a half-typed field cannot fire a query.

Verified: typing a 36-character uuid one character at a time produced **no navigation**; then a real
column choice committed immediately and kept both column and value.

Note for the record: this is the lag that was predicted when "commit + debounce while typing" was
chosen over "commit only". Now commit-only.

## G2 — 38ch was not enough

38ch fit a bare 36-char uuid but clipped anything with a prefix/suffix. Now 44ch: measured 287px of
text in a 349px input, 0px clipped. Deliberately still a **fixed** width — growing with content is
what pushed the toolbar off-screen in round 3.

## G3 — why both buttons came out the same blue

The shared icon rule was written at high specificity
(`#content #form > fieldset > div > input[data-tb-icon]`, c=3) while the Reset override was written
low (`#content #form [data-tb-icon="reset"]`, c=0), so the primary accent fill won and Reset never
got its ghost styling. All icon rules are now at one consistent specificity. Select = filled accent
with a play glyph; Reset = transparent, muted glyph, bordered, dimmed when disabled.

---

# Round 5 — toolbar density (2026-08-22)

| id | item | status |
|---|---|---|
| **H1** | unused Select inputs hog space — shrink them | **DONE, verified** |
| **H2** | unused Sort occupies space | **DONE, verified** |
| **H3** | toolbar must not wrap to a second row | **DONE, verified** |

## Measured before / after

| fieldset | before | after |
|---|---|---|
| Select | 313px | **127px** |
| Search | 642px | 613px |
| Sort | 218px | **125px** |
| Limit | 67px | 65px |
| Text length | 99px | 93px |
| Action | 92px (**on row 2**) | 84px (row 1) |
| **total incl. gaps** | **1481px** | **1137px** (-23%) |

Toolbar lines: **2 → 1**. Fits one row for any form width ≥ 1137px (≈ 1457px viewport with the
sidebar). Below that it still wraps — kept as the deliberate last-resort fallback.

## How the shrinking works

`select:has(option:checked[value=""])` collapses a select to a 41px stub while its selected option
is the empty placeholder, and it expands back the moment a real value is chosen — no JS, no state.

Two details that mattered:

- Adminer renders the *function* select's placeholder as a bare `<option>` with **no value
  attribute at all**, so `[value=""]` never matched it and the Select fieldset stayed 219px.
  Both forms are now matched: `option:checked[value=""], option:checked:not([value])`.
- Scoped to `#fieldset-select` and `#fieldset-sort` only. The Search column select also defaults to
  an empty value but renders the visible label "(anywhere)" — shrinking that would clip real text,
  so it keeps its width.

Verified the stubs are still fully usable: the searchable dropdown portal opens from a 41px trigger
with all 13 column options, in the Select, Sort **and** Search fieldsets.

Other savings: fieldset padding `10px 12px 8px` → `4px 8px 6px`, form gap 10px → 6px, search column
select cap 14em → 11em (the portal sizes itself, so the closed trigger never needs the longest name),
operator select capped at 7.5em ("IS NOT NULL").

Also added `margin-top: 8px` on `#form` — the fieldset row was colliding with the
"Select data / Show structure / Alter table" links above it.

## Not regressed (re-verified this round)

Typing still applies nothing; uuid still 0px clipped; Action icons still primary+ghost; column type
sublines intact; panel still default-off; toolbar inside the viewport; console clean.

---

# Round 6 — Lucide icons, view-first panel, login page (2026-08-22)

| id | item | status |
|---|---|---|
| **I1** | use the Lucide icon set (user supplied exact SVGs) | **DONE** |
| **I2** | panel close icon too small; unify all icons | **FIXED, verified (42x42, 18px glyphs)** |
| **I3** | checkbox opens panel in **view** mode; Edit is a separate click | **FIXED, verified** |
| **I4** | redesign the login page | **DONE, verified** |

## I1 / I2 — one icon family

Replaced the hand-drawn glyph mix in `select-row-inspector.php` with Lucide paths: `copy`,
`save`, `square-pen` (edit), `x` (cancel + close), `copy-plus` (clone), `trash` (delete). The
toolbar Reset button now uses Lucide `list-restart`.

The header close was a text `×` in a 20px font while every footer control was a real icon button —
that mismatch is what read as "too small". It is now a `.sri-icon-btn` like the rest. Measured: all
visible panel buttons **42x42 with 18px glyphs**. Icon size dropped 24px → 18px, which is Lucide's
intended density at stroke-width 2; at 24px the glyphs were touching the button edge.

## I3 — the panel opened straight into a form

`paint()` had:

```js
if (shown.length === 1 && shown[0].editForm && shown[0].canEdit) { paintPanelEditor(...) }
```

so any single selected row rendered as live inputs. Merely looking at a record put every column one
keystroke from being modified. Added an explicit `editing` flag (default `false`):

- view mode → read-only field list, **Edit** button shown
- Edit → editor, **Save + Cancel** shown, Edit hidden
- Cancel → back to read-only, edits discarded
- changing selection or closing resets to view mode

Verified the full round trip: open → no live inputs, Edit visible; Edit → live inputs, Save+Cancel
visible; Cancel → back to read-only.

## I4 — login page

Followed the supplied reference screenshot rather than SPEC.md R2.4's isometric plinth: brand above
the card, one calm card, fields two-up with the label above the input, one full-width primary
button. The flat treatment also survives 12 palettes more predictably than stacked offset slabs.

Still CSS-only and gated on `body:has(#username)`, exactly as SPEC.md R2.1 requires, so the three
login-critical plugins are untouched by construction. Verified after the change: `#h1` present,
`#logo` present, `value="pgsql" selected` still applied by `default-pgsql.php`, `#username` still
autofocused, server still prefilled from `ADMINER_DEFAULT_SERVER`, and a real login succeeds.

Implementation notes worth keeping:
- Adminer prints the brand inside `#foot`, i.e. *after* the form. `#foot { order: -1 }` lifts it
  above the card without touching markup.
- The two-up field grid is `table.layout { display: grid }` + `tbody { display: contents }` +
  `tr { display: block }`, with `tr:last-child { grid-column: 1 / -1 }` so Database spans the row.
  Collapses to one column under 520px.
- The subtitle is a CSS `::after` on `#menu h1` — no markup added.
- Table-border controls are hidden on login (no table on screen).

## Sweep

Zero PHP errors on login / db list / select / structure / edit. Zero console errors.

---

# Round 7 — login polish + icon-and-text buttons (2026-08-22)

| id | item | status |
|---|---|---|
| **J1** | brand (logo + name + version) on ONE horizontal row | **DONE, verified** |
| **J2** | input styling inconsistent — radius, background, border | **FIXED, verified** |
| **J3** | Logout button: Lucide icon + text | **DONE, verified** |
| **J4** | Login button: Lucide icon + text | **DONE, verified** |
| **J5** | Action buttons bigger, icon + text | **DONE, verified** |
| **J6** | dead space beside the Text length input | **FIXED (7px = padding)** |

## J2 — the cause was not autofill

First guess was Chrome's autofill overlay; measuring disproved it (`:autofill` was `false` on every
field). The real cause: **Adminer emits the login fields with no `type` attribute** —
`<input name="auth[server]" value="...">`. CSS attribute selectors require the attribute to be
present, so `input[type="text"]` never matched Server / Username / Database, and those three fell
back to the browser default: square corners, 2px grey border, grey fill. Password (`type="password"`)
and the driver `<select>` did match, which is why only *some* boxes looked wrong.

Fixed by adding `input:not([type])` to the control, consistency and focus rules. Measured after:
all five controls report one background `rgb(26,27,38)`, one radius `8px`, one border
`1px rgb(59,66,97)`.

Heights also differed by 2px because a `<select>` and an `<input>` resolve default padding
differently — pinned to an explicit `height: 36px` on the login card.

The autofill override was kept anyway: it is correct on its own merits (Chrome does repaint
autofilled fields, just not on this page today).

## J5 — icon AND text, so the plugin stopped blanking labels

Round 6 blanked each button's `value` to leave an icon-only square. Reverted: the label stays and
the glyph rides as a `background-image` with left padding, because an `<input type="submit">` is a
replaced element and cannot host a `::before`. Buttons went from 30x28 icon-only to
**81x30 "Select"** and **78x30 "Reset"**, both with a Lucide glyph.

Found while verifying: Reset had `color: var(--accent-fg)` inherited from the base button rule —
a dark colour meant for the accent fill — on a now-transparent ghost button, so **the label was
invisible in every dark palette**. Set to `var(--fg)`.

Logout and Login needed no JS at all: both already carry a text label, so the glyph is pure CSS
(lucide `log-out` / `log-in`).

## J6 — the gap beside Text length

The legend "Text length" is wider than a 5.5ch input, so the fieldset was sized by the label and
left the remainder empty. The numeric inputs now `flex: 1 1 auto` and fill whatever width the legend
already forced. Remaining 7px is the fieldset's own padding.

## Sweep

Zero PHP errors on login / db list / select / edit; brand and `value="pgsql" selected` intact; real
login and logout both work; toolbar still one line and inside the viewport; uuid input still 349px.

---

# Round 8 — dynamic icon colour + enum types on structure (2026-08-22)

| id | item | status |
|---|---|---|
| **K1** | Select icon → lucide `send` | **DONE** |
| **K2** | icon colours must be dynamic, contrast, and react to hover | **FIXED, verified across 4 palettes** |
| **K3** | Show structure must list the enum values a table's types allow | **DONE, verified** |

## K2 — why the colours were frozen, and the fix

The glyphs were `background-image: url("data:image/svg+xml,...")` with the colour written into the
SVG (`stroke="white"`, `stroke="%23999999"`). **A data-URI SVG cannot see `currentColor`** - it is a
separate document - so those colours were permanently baked: they could not follow the palette and
could not react to `:hover` or `:disabled`.

Fixed by rendering a **real inline `<svg stroke="currentColor">`** as a child of the button, which
inherits the button's own `color`. That required the control to be able to *have* children, and an
`<input type="submit">` is a replaced element - no child nodes, no `::before`. So
`select-toolbar-icons.php` now swaps the Select input for a `<button type="submit">`.

Safe because that control carries **no `name`**, so it was never part of the submitted query string.
Verified after the swap: clicking Select still submits and produces
`where[0][col]=posItemId&where[0][val]=ITEM-TWOU`.

One coupling this exposed: `select-shortcuts.php:284` located the Select button with
`querySelector('input[type="submit"]')` to insert Reset after it — that would have silently stopped
injecting Reset. Widened to `input[type="submit"], button[type="submit"]`. Verified Reset is still
injected.

Measured across palettes — the SVG stroke tracks the computed text colour every time, and the
accent differs per theme, which is what "dynamic" required:

| theme | button colour | svg stroke | follows? |
|---|---|---|---|
| Tokyo Night | rgb(26,27,38) | rgb(26,27,38) | yes |
| Gruvbox Dark | rgb(34,34,39) | rgb(34,34,39) | yes |
| Solarized Dark | rgb(14,40,48) | rgb(14,40,48) | yes |
| light mode | rgb(14,40,48) | rgb(14,40,48) | yes |

Hover/disabled now work for free: Reset's hover rule sets `color: var(--accent)` and the glyph
follows, and disabled drops both to `var(--muted)`.

## K3 — enum values on the structure page

New `structure-enum.php`. Adminer printed a named enum column as just `"HRM_LEAVE_TYPE"`, so the
allowed values were invisible without looking the type up separately. The plugin reads
`pg_type`/`pg_enum`/`pg_namespace` for the current schema (catalog only, no user data) and appends
the labels as chips after the type name.

Verified on `hrm_leave_request.leaveType`:
`"HRM_LEAVE_TYPE"  ANNUAL  SICK  UNPAID  OTHER  [ANNUAL]`, with the full list also in the
element's `title`. Long enums cap at 8 chips plus a `+N` marker so a wide type cannot blow the
column out.

Guarded the same way as select-smart-filter: `head()` also fires on the login page (where
`$_GET['table']` survives but no connection exists), so the query sits behind a connection check
plus `try/catch`. Verified: the unauthenticated structure URL returns 403 with **zero** PHP errors.

## Sweep

Zero PHP errors on login / db list / select / structure / edit / SQL, zero on the unauthenticated
structure URL, zero console errors.

---

# Round 9 — pretty-print JSON in the inline cell editor (2026-08-22)

| id | item | status |
|---|---|---|
| **L1** | Ctrl+click a json/jsonb cell → auto-format the JSON | **DONE, verified** |

New plugin `select-json-edit.php`. Ctrl+click makes Adminer replace the cell with
`<textarea rows="1">` holding the raw value; for jsonb that is one unbroken line in a one-row box.
It is now indented, monospace, and the box grows to fit (capped at 26 rows / 60vh).

No DB access: the column types come from the grid's own header cells (`th > span[title]`), the same
source the type-subline uses. Nothing to query, nothing to guard.

### Round-trip safety

The exact original string is kept in `dataset.sjeOriginal`, and on submit, if the edited text parses
to the *same* value, the original is restored byte-for-byte. This matters for `json` (not `jsonb`),
where PostgreSQL preserves the text it is given — so opening a row merely to read it must never
silently rewrite how it is stored. Verified all three paths:

- cosmetic-only edit → original restored (`noopSubmitRestoresOriginal: true`)
- real edit → kept and sent (`realEditKept: true`)
- unparseable text → flagged with a red outline and sent as typed (`invalidFlagged: true`)
- non-JSON column → editor untouched (`nonJsonUntouched: true`)

### Two bugs found while verifying

1. **The `<head>` timing trap again.** `head()` runs before `<body>`, so `document.getElementById('table')`
   returned null and the whole plugin bailed silently — the same mistake as `select-autoapply.php`.
   Wrapped in a `DOMContentLoaded` boot. **This is now the third plugin to hit it; any future
   `head()` plugin that touches the DOM must start from a DOM-ready boot.**
2. **Adminer inserts the editor EMPTY.** The `<textarea>` is added to the DOM first and `.value` is
   assigned a moment later, so the MutationObserver saw an empty box and `format()` returned on its
   own "nothing to format" guard. Now retries every 50ms for ~600ms until a value appears, then
   formats — and still treats a genuinely empty cell as NULL.

Measured result on `audit_log.metadata`: 1 line → **7 lines, 8 rows, 153px tall**, monospace,
original preserved.

---

# Round 10 — button radius + matching auth icons (2026-08-22)

| id | item | status |
|---|---|---|
| **M1** | default button radius slightly too round | **DONE** (`--radius` 8→6, `--radius-sm` 6→5) |
| **M2** | Login/Logout icons must match the label colour with sane contrast | **FIXED, verified across 3 palettes** |

## M1

Reduced the two shared radius tokens rather than adding a button-only value, so inputs and buttons
stay visually consistent. Measured afterwards: every visible control in the toolbar reports 5px or
6px; the only 0px are hidden inputs and the native `desc[0]` checkbox, both correct.

## M2 — the same frozen-colour bug, in the last two places it survived

Logout and Login still drew their glyph with
`background-image: url("data:image/svg+xml,...stroke='white'...")`. A data-URI SVG is a separate
document and cannot read `currentColor`, so the white was permanent. On any palette whose
`--accent-fg` is dark (Gruvbox `rgb(40,40,40)`, Solarized `rgb(0,43,54)`) that put a **white icon
next to dark text on the same button** — the mismatch reported.

New `auth-buttons.php` swaps both for `<button type="submit">` holding a real inline
`<svg stroke="currentColor">`, so the glyph is *by construction* the exact colour of the label and
inherits its contrast. Measured:

| theme | button colour | svg stroke | match |
|---|---|---|---|
| Tokyo Night | rgb(26,27,38) | rgb(26,27,38) | yes |
| Gruvbox Dark | rgb(40,40,40) | rgb(40,40,40) | yes |
| Solarized Dark | rgb(0,43,54) | rgb(0,43,54) | yes |

### Why converting these two was safe

Logout is `<input type="submit" name="logout" value="Logout">` — its name *and* value are read
server-side, so both are copied onto the button verbatim (`name="logout" value="Logout"`), which
submits identically. Login carries no name and was never part of the payload.

Verified end-to-end rather than by inspection: clicking the converted **Login** logs in, clicking
the converted **Logout** logs out ("Logout successful…"), and logging back in works.

One follow-on caught while doing it: the login card's sizing rule was
`#content p input[type="submit"]`, which stopped matching once the element became a `<button>` —
the full-width primary button would have collapsed to auto width. Selector now matches both.

## Standing note

Three plugins now convert an `<input type="submit">` to a `<button>` purely so an icon can inherit
`currentColor`. If a fourth is ever needed, factor the swap into one shared helper instead of a
fourth copy.

## Sweep

Zero PHP errors on login / db list / select / structure / edit / SQL; zero console errors; Select
still submits; Reset still injected.

---

# Round 11 — open the IP gate, style native dropdowns (2026-08-22)

| id | item | status |
|---|---|---|
| **N1** | allow login from any IP so any DB can be reached publicly | **DONE, verified** |
| **N2** | native `<select>` needs radius, background, text colour, modern look | **DONE, verified** |

## N1 — there were TWO gates, and the second was the real blocker

`AdminerLoginIp::login()` is what Adminer consults when the password box is **empty**: return true
and the empty password is accepted, otherwise Adminer refuses it outright.

Our config listed localhost + RFC1918 prefixes, so remote clients were refused. But upstream has a
second condition that is easy to miss: when the `$forwarded_for` list is **empty**, it additionally
requires the request to have NOT been proxied (`$forwarded_for == ""`). This box runs `cloudflared`,
so every public request carries `X-Forwarded-For` — meaning an empty password would have been
refused *no matter which IPs were listed*. Widening only the IP list would not have fixed it.

Now `new AdminerLoginIp(array(''), array(''))`: an empty prefix matches everything because
`strncasecmp($addr, '', 0)` is 0, so it is "any address" without enumerating ranges, and the same
for any forwarded chain.

Verified: empty-password POST returns 302 (accepted) **both** without and with
`X-Forwarded-For: 203.0.113.9`. The follow-up then fails with
`SQLSTATE[08006] ... fe_sendauth: no password supplied` — i.e. Adminer's gate is open and
**PostgreSQL** is refusing, which is the correct division of responsibility. Normal password logins
still work direct and proxied (200, 50 rows).

**Security, stated plainly and accepted at the operator's request:** anyone who can reach this
Adminer can now submit a blank password to any host and port it can route to. The database still
authenticates, so this is not a bypass — but any passwordless account on any reachable host is now
reachable through here, and Adminer can be used to probe its own network. Access control belongs in
front of it (the tunnel's auth), not in this file.

## N2 — dropdowns

`appearance: none` plus themed background/colour/radius, and options/optgroups coloured so the popup
stops rendering with the OS white-list / blue-highlight look.

The caret is drawn with **two `linear-gradient`s**, not an SVG data URI: gradient colour stops accept
`var()`, so the arrow re-colours itself per palette. A data URI cannot read variables or
`currentColor` — the same trap that froze the button and auth icons. Verified the caret colour
actually changes: `rgb(86,95,137)` on Tokyo Night vs `rgb(146,131,116)` on Gruvbox.

Details handled: `select[multiple]` / `select[size]` drop the caret (it would paint over the last
row); the collapsed Select/Sort stubs get a tighter caret offset so it stays centred in a 41px box.

Radii are internally consistent per context — every toolbar control 5px, every login-card control
6px and 36px tall.

## Sweep

Zero PHP errors on login / db list / select / structure / edit / SQL; zero console errors.
