# Design-system pass: new layout for all views Implementation Plan

## Overview

Bring `/books` (route `book_index`) onto an explicit design-system contract: Bootstrap 5.3.8 roles are the tokens, three Twig partials are the shared components (read status, pager, empty state), the view gets proper landmarks, and a Playwright screenshot gate plus a rule in `AGENTS.md` keep the next agent on the contract. Every charge C1-C5 from `research.md` maps to a phase. One view changes; `show.html.twig` changes only to reuse the read-status partial.

Scope extension (participant, 2026-11-13): **every view must comply with the new layout**, not only `/books`. The views are `base.html.twig` (navbar and flash alerts), `book/index`, `book/show` (including the mark-read form with rating radios and the comments block), `book/new` (Symfony form through `bootstrap_5_layout.html.twig`, `config/packages/twig.yaml:3`), `security/login`, and the error pages (no template override exists today, so Symfony's default error pages are used). `/books` stays the audited view with the 7-state matrix; the others get the same palette, fonts, icons and shared partials plus a default and a validation-error screenshot. `/` only redirects (`src/Controller/HomeController.php:16-19`) and has no view.

Scope addendum (participant request, 2026-11-13): the change also adds an icon library (Symfony UX Icons with the Bootstrap Icons set) used in the layout, replaces Bootstrap's default colours with the supplied palette, and sets the supplied fonts (DM Serif Display for headings, DM Sans for text and interface, tabular figures for numbers). Palette, fonts and the navbar icons are global by nature (`base.html.twig`, `app.css`), and the other views are brought onto them explicitly in phase 3. Raw values and the contrast analysis are recorded in `design-tokens.md`.

## Current State Analysis

- Stack is Symfony + Twig + AssetMapper + Bootstrap 5.3.8 (`importmap.php:30-31`); `assets/styles/app.css:1` is a comment only, so the repo has no theme layer of its own.
- The hard-coded scan (hex/rgb/hsl/oklch, `style=`, `<style>`) finds 0 matches in `templates/book/index.html.twig` and `templates/base.html.twig`.
- Dead class `recommendation-card` at `index.html.twig:20`; deprecated `text-muted` 4x in `index.html.twig` (lines 15, 23, 61, 63) and 3x in `show.html.twig` (lines 9, 68, 75). Verified against Bootstrap v5.3.8 docs: deprecated since 5.3.0, value already equals `--bs-secondary-color`, removed in v6.0.0.
- The read badge and rating text are duplicated (`index.html.twig:85-86`, `show.html.twig:39, 43, 70`); the 40-line pager (`index.html.twig:98-137`) and both empty-state paragraphs (`:61, :63`) are inline.
- No `role="search"` on the form (`:35`), no `<caption>` on the table (`:66`).
- No icon library and no font definition: the navbar (`templates/base.html.twig:24-44`) is text-only and type is Bootstrap's default system stack; `assets/` has no `fonts/` or `icons/` directory.
- No fixtures, no Node, no Playwright (`AGENTS.md:21` says no Node or bundler). `.gitignore:26-32` already ignores Playwright output. `composer.json:101-110` `require-dev` has no fixtures bundle.
- `tests/Controller/*` fix selectors the markup must keep (see Key Discoveries). Other views carry the same legacy markup: `text-muted` 3x in `show.html.twig`, `badge text-bg-success/secondary` in `show.html.twig:24, 39, 70`, `alert-*` flashes in `base.html.twig:52`, forms via `bootstrap_5_layout`, and a plain login form (`security/login.html.twig:14-25`).

## Desired End State

`/books` renders with no deprecated roles, no dead classes, one source for the read badge, a reusable pager and empty-state partial, a labelled search landmark and table, and distinct empty-library and no-results states. `npm run e2e` (local) renders the view in every state of the 7-state matrix at desktop and one mobile width and compares against committed baselines. `AGENTS.md` carries a UI rule outside the CLI-managed block. `php bin/phpunit` passes unchanged. The app uses the supplied palette and fonts through Bootstrap variables, shows inline SVG icons in the navbar and the list, and every text/background pair meets WCAG AA (4.5:1).

### Key Discoveries:

- Tests pin these selectors and texts: `main a.btn[href="/books/new"]`, `nav a[href="/books/new"]` (`BookListControllerTest.php:57-63`), `table`, `tbody tr` (`:43, :261`), `form[method="get"][action="/books"] input[name="title"]`, `label[for="search-title"]`, `select[name="category"] option` count 12 (`:273-290`), `ul.pagination a.page-link` and `a[aria-current="page"]` (`:314-335`), texts 'Dodaj pierwszą książkę' and 'Żadna książka nie pasuje do wyszukiwania.' (`:369-392`). Changes must be additive or move markup verbatim.
- Other tests pin more markup: `.badge.text-bg-success` for the read status (`ReadBookControllerTest.php:230`), `.alert-success` / `.alert-warning` / `.alert-danger` flashes (`BookControllerTest.php:62`, `ReadBookControllerTest.php:68, 153, 167`), `.invalid-feedback` (`BookControllerTest.php:92, 169`), `form[name="book"]`, `form[name="mark_read"]`, 5 radio inputs `mark_read[rating]`, `section#comments`, `h1` on the detail page (`BookCommentsTest.php:144`), and `nav a[href="/books/new"]` text 'Dodaj książkę' (`BookControllerTest.php:52`). So Bootstrap's semantic classes stay; the new palette arrives by remapping `--bs-success`, `--bs-danger` etc., never by renaming classes.
- The "Nowa książka" button for anonymous visitors, `null` recommendations for visitors and login landing on `/books` are intentional (`BookListControllerTest.php:57-77`, `BookController.php:42-43`): not charges.
- `doctrine:fixtures:load` purges the target database. The spec must only ever run against the test database.
- Palette contrast (`design-tokens.md`): the accent `#68775B` is 4.29:1 on the page background (fails AA for normal text) and 4.80:1 on white (passes); the add-on `#BD7658` is 3.56:1 on white and 3.18:1 on the background (fails for text). So page-background links use a derived darker accent text (4.73:1), and the add-on is limited to icons, rules and large text.
- Bootstrap 5.3 components (`.btn-primary`, pagination, links, focus ring) read their own component variables (`--bs-btn-bg`, `--bs-pagination-active-bg`, `--bs-link-color`, `--bs-focus-ring-color`), so setting `--bs-primary` alone does not restyle them; the token block must set these too (confirm names in the Bootstrap 5.3.8 docs while implementing).
- Polish diacritics are in the `latin-ext` subset of both fonts; a `latin`-only copy would silently fall back to a system font for ą, ć, ę, ł, ń, ó, ś, ź, ż.
- Error state is N/A (`BookSearch::fromQuery` never errors, `src/Dto/BookSearch.php:12-14`); loading state is N/A (server-rendered, `BookController.php:29-47`).

## What We're NOT Doing

- No dark mode, no `data-bs-theme`, no theme toggle (app stays light-only; the token layer is kept ready for it).
- No new features or behaviour changes on any view; only presentation (palette, fonts, icons, shared partials, landmarks).
- No Sass build, no `shadcn init`, no second palette next to Bootstrap's: the supplied palette replaces Bootstrap's default values through the existing variables. No icon font and no CDN fonts or icons at runtime.
- No text in the add-on colour `#BD7658` and no accent-coloured body text on the page background (contrast, see Key Discoveries).
- No CI job for Playwright and no Node in the Dockerfile or deploy; screenshots run locally.
- No change to controllers, entities, translations keys' meaning, or the search/paging behaviour.
- No change to the anonymous "add book" button or login redirect rules.

## Implementation Approach

Follow the `/10x-ui` order: libraries/environment, then token values, then the one view, then states and the gate. Bootstrap CSS variables are the single source of values, overrides (if any) live in `assets/styles/app.css` with a comment pointing at their origin. Shared markup becomes Twig partials under `templates/book/` with underscore names. The Playwright spec doubles as the "kitchen sink": it drives `/books` into each state against deterministic fixture data and screenshots it.

## Critical Implementation Details

- **Fixture safety.** `doctrine:fixtures:load` purges the database. The Playwright config's `webServer` and the fixture load must both use `APP_ENV=test` and the test database (CI/test `DATABASE_URL` conventions in `.github/workflows/deploy.yml`), and the e2e npm script must fail before loading if `APP_ENV` is not `test`. Never point it at the Compose dev database.

## Phase 1: Environment and libraries

### Overview

Add the two approved dev-only dependencies and the deterministic data and runner they need, before any visible change.

### Changes Required:

#### 1. Fixtures bundle and data

**File**: `composer.json`, `config/bundles.php`, new `src/DataFixtures/`

**Intent**: Add `doctrine/doctrine-fixtures-bundle` as a dev dependency (enabled for dev and test only) and create fixtures that produce known books: a catalogue group (21+ books across categories and authors, one demo user with one read-and-rated book) and an empty group (no books).

**Contract**: Fixture groups `books-ui-catalogue` and `books-ui-empty`; load with `php bin/console doctrine:fixtures:load --env=test --group=<group> --no-interaction`. Demo user credentials are fixture constants for the spec only.

#### 2. Playwright runner

**File**: new `package.json`, `package-lock.json`, `playwright.config.ts`, `tests/e2e/books.spec.ts` (skeleton)

**Intent**: Add `@playwright/test` as the only (dev) dependency, a config that starts the PHP app in test env on a fixed port, desktop and one mobile project (390 px wide), and an `e2e` npm script that refuses to run unless `APP_ENV=test`.

**Contract**: `npm run e2e` runs the spec; snapshots live next to the spec and are committed; no runtime Node dependency is introduced.

#### 3. Icon library

**File**: `composer.json`, `composer.lock`, `config/bundles.php`, new `assets/icons/`

**Intent**: Add `symfony/ux-icons` (runtime dependency, approved by the participant) and import the Bootstrap Icons the layout needs one by one, so they are committed as inline-SVG sources: book, plus-circle, box-arrow-in-right, box-arrow-right, search, x-circle (clear filters), check-circle, chevron-left, chevron-right, star (rating).

**Contract**: Icons render with `{{ ux_icon('bi:<name>') }}`; the bundle adds `aria-hidden="true"` automatically when no label is given, which is the intended use (every icon sits next to a text label). `ux:icons:import` needs network only at import time; no runtime requests.

#### 4. Font files

**File**: new `assets/fonts/` (DM Sans variable `latin` and `latin-ext` woff2, DM Serif Display 400 `latin` and `latin-ext` woff2, licence text)

**Intent**: Self-host the supplied fonts so there are no external font requests; take the files from Fontsource (`@fontsource-variable/dm-sans`, `@fontsource/dm-serif-display`, SIL OFL 1.1) and keep the licence next to them.

**Contract**: Four woff2 files (about 90 KB in total at download time) referenced only from `assets/styles/app.css` by relative `url()`, which AssetMapper rewrites to versioned paths.

#### 5. Token record

**File**: `context/changes/ui-books-list/design-tokens.md` (already created)

**Intent**: Keeps the raw palette, fonts, derived values and contrast numbers outside the chat so the next session does not reinvent them.

#### 6. Document the exception

**File**: `AGENTS.md`

**Intent**: Replace the "no Node" claim in the layout line with the accurate statement: the app has no Node or bundler; Node exists only for the local Playwright screenshot tests. Edit outside the `BEGIN/END @przeprogramowani/10x-cli` block.

### Success Criteria:

#### Automated Verification:

- Fixtures bundle installed and catalogue group loads in test env: `php bin/console doctrine:fixtures:load --env=test --group=books-ui-catalogue --no-interaction`
- Playwright lists the spec: `npx playwright test --list`
- Existing PHP suite still passes: `php bin/phpunit`
- UX Icons installed and a Bootstrap icon imports: `php bin/console ux:icons:import bi:book` then `ls assets/icons`
- Font files present: `ls assets/fonts` lists four woff2 files and the licence

#### Manual Verification:

- `package.json` has only `devDependencies`; no Node artefact is referenced by the Dockerfile or deploy workflow
- The fixture load hit the test database; the dev (Compose) database content is unchanged
- The font licence file is kept and the production `composer install --no-dev` still resolves `symfony/ux-icons`

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Token values

### Overview

Make the role layer explicit and remove the legacy names from the view (charge C1).

### Changes Required:

#### 1. Muted role and dead class

**File**: `templates/book/index.html.twig`, `templates/book/show.html.twig`

**Intent**: Replace the 4 `text-muted` uses in the list and the 3 in the detail view with `text-body-secondary` and remove the dead `recommendation-card` class from the recommendation card (no CSS targets it).

**Contract**: No other markup change in this phase; selectors listed in Key Discoveries stay intact.

#### 2. Theme layer

**File**: `assets/styles/app.css`

**Intent**: Replace the placeholder comment with a small, documented block that states the contract (roles come from Bootstrap `--bs-*` variables; overrides go here) and holds only the spacing needed by the empty-state partial later. No colour literals.

**Contract**: Any value in a view references a Bootstrap variable or a named custom property; the hard-coded scan stays at 0 on views. Raw hex values appear only in the token block of `app.css` (and in `design-tokens.md`).

#### 3. Palette mapped onto Bootstrap roles

**File**: `assets/styles/app.css`

**Intent**: Map the supplied palette onto Bootstrap's variables in `:root`: page background `#F5F2EA`, text `#282D29`, surfaces `#FFFFFF` (cards, table, navbar, form controls), primary and the "read"/success role `#68775B`, plus the component-level variables of buttons, pagination, links and the focus ring so they follow the accent. Define a derived accent-text value (`color-mix` of the accent 90% with the text colour) for links on the page background, and a named custom property for the add-on `#BD7658`, used only for icons, rules and large text.

**Contract**: Values and rules as in `design-tokens.md`; light mode only; a comment at the block points to that file. `btn-primary` label is white on `#68775B` (4.80:1).

#### 4. Fonts and numeric figures

**File**: `assets/styles/app.css`

**Intent**: Declare `@font-face` for DM Sans (variable) and DM Serif Display (`latin` plus `latin-ext` with `unicode-range`), set DM Sans as the body/interface font and DM Serif Display on headings (`h1`-`h6`, `.navbar-brand`), and add one small utility (for example `.num`) with `font-variant-numeric: tabular-nums` for numeric data: ratings, counts and page numbers.

**Contract**: `font-display: swap`; Bootstrap's `--bs-body-font-family` and `--bs-heading-font-family` hold the font stacks with a system-ui fallback. If DM Sans turns out not to ship tabular figures, record it in `design-tokens.md` and fall back to a monospaced-digit alternative decided with the participant rather than silently accepting proportional digits.

### Success Criteria:

#### Automated Verification:

- No legacy role left in any view: `grep -rn "text-muted" templates` returns nothing
- Dead class gone: `grep -rn "recommendation-card" templates assets` returns nothing
- Hard-coded scan on `templates/book/index.html.twig` and `assets/styles/app.css` returns 0 matches
- PHP suite passes: `php bin/phpunit`
- Raw palette hex values appear only in the token block: `grep -rniE "#(F5F2EA|68775B|BD7658|282D29)" templates` returns nothing
- Font assets resolve through AssetMapper: `php bin/console debug:asset-map` lists the four font files

#### Manual Verification:

- Page text, links, buttons and the pager are readable on the new background and none of them uses the add-on colour for small text
- Polish diacritics (ąćęłńóśźż) render in DM Sans body text and DM Serif Display headings, not a fallback font
- Digits in the rating and pager line up (tabular figures)

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: The view

### Overview

Extract the shared components and fix landmarks (charges C2, C4, the structural half of C3).

### Changes Required:

#### 1. Read-status partial

**File**: new `templates/components/read_status.html.twig`; `templates/book/index.html.twig`; `templates/book/show.html.twig`

**Intent**: One partial renders the "Przeczytana" badge and rating from a `readBook`; both views include it instead of repeating the badge/rating markup.

**Contract**: Input variable `readBook` (entity or null); output identical text to today in the list cell; the show page keeps its `<dd>` rating row layout and only replaces the badge/rating pieces that are truly duplicates.

#### 2. Pager partial

**File**: new `templates/components/pager.html.twig`; `templates/book/index.html.twig`

**Intent**: Move the pager block verbatim into a partial and include it.

**Contract**: Inputs `page`, `pageCount`, `search`; output markup byte-compatible with today (`ul.pagination`, `a.page-link`, `aria-current`, `rel="prev|next"`).

#### 3. Empty-state partial

**File**: new `templates/components/empty_state.html.twig`; `templates/book/index.html.twig`

**Intent**: One partial with two variants (empty library: heading plus `btn-primary` "add first book"; no results: heading plus clear-filters action) using Bootstrap utilities only.

**Contract**: The texts 'Dodaj pierwszą książkę' and 'Żadna książka nie pasuje do wyszukiwania.' remain verbatim and visible in `main`; the table is not rendered in either state.

#### 4. Landmarks

**File**: `templates/book/index.html.twig`

**Intent**: Give the search form `role="search"` with an accessible name and the table a `<caption>` (visually hidden is fine), using existing or new translation keys in `translations/messages.pl.yaml`.

**Contract**: Additive only; `form[method="get"][action="/books"]`, labels and `tbody tr` unchanged.

#### 5. All other views

**File**: `templates/book/show.html.twig`, `templates/book/new.html.twig`, `templates/security/login.html.twig`, `templates/base.html.twig` (flash alerts), new `templates/bundles/TwigBundle/Exception/error.html.twig`, `assets/styles/app.css`

**Intent**: Bring every remaining view onto the same contract. Detail page: heading and back link in the new type and accent, tag and comment-rating badges on the secondary role, read status through `_read_status`, comments block on the surface colour. New-book and mark-read forms: the Bootstrap 5 form theme picks up the palette through the variables (inputs, focus ring, validation feedback, rating radios) with any needed `app.css` rules, and the submit buttons get icons. Login: same surface treatment, icon on the submit button. Flash alerts: palette-mapped success, warning and danger with an icon each. Error pages: a branded error template extending `base.html.twig` with a heading, the status text and a link back to the list, so 404 and 500 no longer show Symfony's default page.

**Contract**: Classes pinned by tests (`.badge.text-bg-success`, `.alert-*`, `.invalid-feedback`, form names, rating radios, `section#comments`, `h1`) stay; the error template must render without a signed-in user and without throwing when `app.user` is null (the navbar already guards it); no behaviour change.

#### 6. Icons in the layout

**File**: `templates/base.html.twig`, `templates/book/index.html.twig`, the three partials

**Intent**: Use the imported icons next to existing text labels: brand book icon and nav links in the navbar (add book, log in, log out), search and clear icons on the filter buttons, the primary "new book" button, a check icon in the read badge, chevrons in the pager's previous/next, a star beside the rating and the add-on colour on decorative icons, and a large book icon in the empty states.

**Contract**: Every icon is decorative (adjacent text keeps the accessible name), so no icon is the only content of a link or button; tests that select links by `href` or buttons by text (`nav a[href="/books/new"]`, `selectButton('Zaloguj')`) keep passing; icon size follows the font size (`1em`) via a small class in `app.css`.

### Success Criteria:

#### Automated Verification:

- Existing list tests pass unchanged: `php bin/phpunit tests/Controller/BookListControllerTest.php`
- New assertions for `form[role="search"]` and `table caption` pass: `php bin/phpunit tests/Controller/BookListControllerTest.php`
- Twig templates lint: `php bin/console lint:twig templates`
- Read badge defined once: `grep -rn "badge text-bg-success" templates` matches only `_read_status.html.twig`
- Pager markup no longer inline: `grep -c "pagination" templates/book/index.html.twig` returns 0
- All controller tests pass unchanged: `php bin/phpunit tests/Controller`
- Error template renders for 404 and 500 without a user: a new test renders `bundles/TwigBundle/Exception/error.html.twig` with status 404 and 500 and asserts the status text and the back link
- Icons render as hidden inline SVG in the navbar: a new assertion `nav svg[aria-hidden="true"]` exists and passes in `php bin/phpunit tests/Controller/BookListControllerTest.php`

#### Manual Verification:

- Empty library and no-results pages look clearly different (heading and action differ)
- `/books/{id}` still shows the read badge and rating for a read book
- Keyboard tab order through search fields, buttons, table links and pager shows a visible focus ring everywhere
- Icons follow the text colour, line up with their labels at desktop and 390 px, and the add-on colour is used only on decorative icons
- Detail page, new-book form (with a validation error), mark-read form, login (with a wrong password) and flash alerts all use the new palette, fonts and icons, with no leftover default Bootstrap blue or green
- `/_error/404` and `/_error/500` (dev) show the branded error page

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 4: States and visual gate

### Overview

Cover the 7-state matrix with screenshots and leave the rule that keeps the contract (charge C5).

### Changes Required:

#### 1. Screenshot spec

**File**: `tests/e2e/books.spec.ts` and committed baselines

**Intent**: Load the right fixture group, then screenshot `/books` per state at desktop and 390 px: default (catalogue), hover (row), focus-visible (search input and a pager link), disabled (previous on page 1), empty (empty group), plus the no-results state. Mask volatile regions. Because the new layout applies to every view, also screenshot, at desktop and 390 px, each remaining view in its default state and in its validation-error state where it has one: `/login` (default and wrong password), `/books/{id}` (anonymous, signed in unread with the mark-read form, signed in read with comments), `/books/new` (default and with validation errors) and the error page (rendered at `/_error/404` in the dev environment or through the test route). Error and loading are recorded as N/A with reasons in the spec header.

**Contract**: `toHaveScreenshot({ maxDiffPixels: 100 })`; baselines never updated to turn a run green without an explained visual delta.

#### 2. UI rule and check

**File**: `AGENTS.md`

**Intent**: Add a short UI block (outside the CLI-managed block): tokens are Bootstrap variables with overrides in `assets/styles/app.css` (palette and fonts recorded in the change's `design-tokens.md`; add-on colour never on text; links on the page background use the derived accent text); icons come from `ux_icon('bi:<name>')` after `php bin/console ux:icons:import bi:<name>`, never an icon font or CDN; shared markup lives in `templates/book/_*.html.twig` partials, check them before writing new markup; no colour literals, `style=` or arbitrary values in views; use `text-body-secondary`, not `text-muted`; screenshots via `npm run e2e`. Include the hard-coded scan command scoped to `templates/book/index.html.twig` and the partials.

**Contract**: No existing rule encourages one-off styles (none found); the block references the Playwright spec as the visual gate.

### Success Criteria:

#### Automated Verification:

- Screenshot spec passes against committed baselines: `npm run e2e`
- Baseline files exist and are tracked: `git ls-files tests/e2e | grep -c png` is greater than 0
- Spec covers every view: `npx playwright test --list` includes the list states plus `/login`, `/books/{id}`, `/books/new` and the error page
- Hard-coded scan on `templates/book/index.html.twig`, the three partials and `assets/styles/app.css` returns 0 matches
- PHP suite passes: `php bin/phpunit`

#### Manual Verification:

- Each matrix cell (default, hover, focus-visible, disabled, empty, no-results) is visible in the screenshots; error and loading are marked N/A with reasons
- The `AGENTS.md` UI block reads correctly and sits outside the `BEGIN/END @przeprogramowani/10x-cli` markers
- Any baseline delta is explained, not just accepted
- Every view's screenshots look right under the new palette, fonts and icons (no unreadable text, no leftover default Bootstrap blue)

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Testing Strategy

### Unit Tests:

- No new unit logic; Twig partials covered by controller assertions.

### Integration Tests:

- `BookListControllerTest`: existing cases unchanged; add assertions for `role="search"`, table caption, and that the two empty states render different headings and actions.

### Manual Testing Steps:

1. Open `/books` anonymous with the catalogue fixtures: list, search, pager.
2. Open `/books` with the empty fixtures and with `?title=zzz`: compare the two states.
3. Log in as the fixture user: read badge and rating in the list and on the detail page.
4. Tab through the page at 390 px and desktop.

## Performance Considerations

None: markup-only change, no new queries (the query-count tests at `BookListControllerTest.php:133, 393` keep guarding this).

## Migration Notes

No data migration. Phase 1 adds dev-only dependencies; production `composer install --no-dev` and the Docker build are unaffected. Fixtures must never be loaded outside the test environment.

## References

- Related research: `context/changes/ui-books-list/research.md`
- Tests fixing the markup: `tests/Controller/BookListControllerTest.php:43-392`
- Prior change that built the list: `context/archive/2026-11-12-browse-and-search-books/`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Environment and libraries

#### Automated

- [x] 1.1 Fixtures bundle installed and catalogue group loads in test env — e943f49
- [x] 1.2 Playwright lists the spec — e943f49
- [x] 1.3 Existing PHP suite still passes — e943f49
- [x] 1.6 UX Icons installed and a Bootstrap icon imports — e943f49
- [x] 1.7 Font files present — e943f49

#### Manual

- [x] 1.4 package.json has only devDependencies and no Node artefact is referenced by the Dockerfile or deploy workflow — e943f49
- [x] 1.5 The fixture load hit the test database and the dev database content is unchanged — e943f49
- [x] 1.8 The font licence file is kept and production install still resolves symfony/ux-icons — e943f49

### Phase 2: Token values

#### Automated

- [x] 2.1 No legacy role left in any view — b96592f
- [x] 2.2 Dead class gone — b96592f
- [x] 2.3 Hard-coded scan on index view and app.css returns 0 matches — b96592f
- [x] 2.4 PHP suite passes — b96592f
- [x] 2.6 Raw palette hex values appear only in the token block — b96592f
- [x] 2.7 Font assets resolve through AssetMapper — b96592f

#### Manual

- [x] 2.5 Text, links, buttons and the pager are readable on the new background and none uses the add-on colour for small text — b96592f
- [x] 2.8 Polish diacritics render in DM Sans and DM Serif Display, not a fallback font — b96592f
- [x] 2.9 Digits in the rating and pager line up — b96592f

### Phase 3: The view

#### Automated

- [x] 3.1 Existing list tests pass unchanged
- [x] 3.2 New assertions for form role search and table caption pass
- [x] 3.3 Twig templates lint
- [x] 3.4 Read badge defined once
- [x] 3.5 Pager markup no longer inline
- [x] 3.9 Icons render as hidden inline SVG in the navbar
- [x] 3.11 All controller tests pass unchanged
- [x] 3.12 Error template renders for 404 and 500 without a user

#### Manual

- [x] 3.6 Empty library and no-results pages look clearly different — a8738d1
- [x] 3.7 Detail page still shows the read badge and rating for a read book — a8738d1
- [x] 3.8 Keyboard tab order shows a visible focus ring everywhere — a8738d1
- [x] 3.10 Icons follow the text colour and line up with their labels at desktop and 390 px — a8738d1
- [x] 3.13 Detail page, forms, login and flash alerts all use the new palette, fonts and icons — a8738d1
- [x] 3.14 Branded error page shows for 404 and 500 — a8738d1

### Phase 4: States and visual gate

#### Automated

- [x] 4.1 Screenshot spec passes against committed baselines
- [x] 4.2 Baseline files exist and are tracked
- [x] 4.3 Hard-coded scan on the view, partials and app.css returns 0 matches
- [x] 4.4 PHP suite passes
- [x] 4.8 Spec covers every view

#### Manual

- [x] 4.5 Each matrix cell is visible in the screenshots and error and loading are marked N/A with reasons
- [x] 4.6 The AGENTS.md UI block reads correctly and sits outside the CLI-managed block
- [x] 4.7 Any baseline delta is explained, not just accepted
- [x] 4.9 Every view's screenshots look right under the new palette, fonts and icons
