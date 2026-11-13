---
date: 2026-11-13T09:31:34+01:00
researcher: Claude (Sonnet 5.5)
git_commit: 1a19d97289e80f66be3da18dd9d7edb65d6a5e9e
branch: main
repository: 10xdevs
topic: "UI audit of /books (book_index): design-system charges"
tags: [research, codebase, ui, books, bootstrap, twig]
status: complete
last_updated: 2026-11-13
last_updated_by: Claude (Sonnet 5.5)
---

# Research: UI audit of /books (book_index)

**Date**: 2026-11-13T09:31:34+01:00
**Git Commit**: 1a19d97289e80f66be3da18dd9d7edb65d6a5e9e
**Branch**: main

## Research Question

Which 3-5 design-system charges (missing tokens, missing shared component, accidental architecture) apply to the `/books` view, each with file, line and user impact? Audit in both directions (source to views, view to source). Scope: `templates/book/index.html.twig`, plus `show.html.twig` and `base.html.twig` only where they share markup with it.

## Summary

The view is already free of hard-coded values: the `grep` scan for hex/rgb/hsl/oklch, `style=` and `<style>` found 0 matches in `templates/book/index.html.twig` and `templates/base.html.twig`. Bootstrap 5.3.8 (`importmap.php:30`) is the design system; `assets/styles/app.css:1` is a single comment line, so the repo has no theme layer of its own. The charges are therefore mostly about missing roles, duplicated markup and missing rules, not about literals. Five charges follow; none needs a new dependency.

## Charges

### C1. Dead class and deprecated muted role (missing tokens)

- `recommendation-card` at `templates/book/index.html.twig:20` has no CSS anywhere: the only occurrence in `assets/` and `templates/` is this line, and `assets/styles/app.css` holds no rules.
- `text-muted` appears 4 times in `index.html.twig` (lines 15, 23, 61, 63) and 3 times in `show.html.twig` (lines 9, 68, 75). Bootstrap 5.3 names this role `text-body-secondary`.
- Impact: a styling hook that does nothing misleads the next agent into thinking cards are themed, and the muted role is spelled the old way in every empty-state message, so a future theme override has to chase two names.
- Evidence level: observed for the class and counts. Verified against Bootstrap v5.3.8 docs (Context7, `site/src/content/docs/utilities/colors.mdx`): `.text-muted` is deprecated since v5.3.0, its value already points at `--bs-secondary-color`, and it is removed in v6.0.0. So the rename changes no pixels today; the gain is v6 readiness and one muted-role name.

### C2. Read badge and rating markup duplicated, no shared partial (missing shared component)

- The "Przeczytana" badge `badge text-bg-success` with the same `book.index.read` key appears at `index.html.twig:85` and `show.html.twig:39`; the rating text `book.index.rating` is repeated at `index.html.twig:86`, `show.html.twig:43` and `show.html.twig:70`.
- `templates/` holds only `base.html.twig`, `book/` and `security/`; there is no partials directory, so the pager (`index.html.twig:98-137`, about 40 lines) and the empty-state paragraphs (`index.html.twig:61, 63`) are inlined in the view.
- Impact: a change to how "read" is shown must be made in 2-3 places, and the two screens can drift (they already differ: one has a trailing rating, the other a `<dd>` row).
- Scope note: extracting a Twig partial is within the repo's stack path (no dependency); the pager is a candidate only if the plan wants it, otherwise defer.

### C3. Empty and no-results states are bare paragraphs (state matrix)

- `index.html.twig:61` (empty library) and `:63` (no search match) render `<p class="text-muted">` with a link; no icon, heading or call-to-action styling, and the "add first book" link is the only action.
- State matrix on this view, by inspected path: default, hover (table-hover at line 66), empty (above), disabled (pager only, `page-item disabled` in lines 100-130) are present; **error** has no UI because `BookSearch::fromQuery` normalises bad input to "no filter" and never errors (`src/Dto/BookSearch.php:12-14, 29-41`), so error is N/A with that reason; **loading** is N/A because the page is rendered server-side with no async fetch (`BookController.php:29-47`); **focus-visible** is the Bootstrap default ring, not a repo token (no override in `app.css`).
- Impact: a first-time visitor on an empty library sees one grey sentence; a user whose search returns nothing sees the same shape, so the two cases look identical apart from the wording.

### C4. Accessibility gaps in the filter form and table (accidental architecture)

- The search form at `index.html.twig:35` has no `role="search"` or accessible name; the `<table>` at line 66 has no `<caption>` or `aria-label`. The pager does have `aria-label` (line 98) and `aria-current="page"` (line 118), so the gap is specific to the form and the table.
- Impact: screen-reader users cannot jump to the search landmark or hear what the table lists.
- Tests constrain the markup: `form[method="get"][action="/books"] input[name="title"]`, `label[for="search-title"]`, `table`, `tbody tr`, `ul.pagination a.page-link` and `main a.btn[href="/books/new"]` are asserted in `tests/Controller/BookListControllerTest.php:43, 57-63, 261, 273-290, 314-335`. Additive attributes do not break them; restructuring would.

### C5. No UI rule in the agent rules file, no theme layer (accidental architecture)

- `AGENTS.md` mentions `templates/` and `assets/` once (line 21) and contains no rule about tokens, Bootstrap utilities or where shared markup lives; `CLAUDE.md` contains none either. (Inspected: both files, matched on `ui|css|style|tailwind|bootstrap`; the only hits were the stack line and the AssetMapper line.)
- `assets/styles/app.css:1` is empty, and no template sets `data-bs-theme` (`grep` over `templates/`, `assets/app.js`, `assets/styles`: 0 matches), so the app is light-only today.
- Impact: nothing stops the next agent from adding inline `style=` or arbitrary classes; "Utrwal to" (rule plus optional check) has nothing to extend yet.

## Detailed Findings

### Source to views

- Token source: Bootstrap CSS variables via `bootstrap/dist/css/bootstrap.min.css` (`importmap.php:31`, imported in `assets/app.js`). Overrides would go in `assets/styles/app.css`, which currently overrides nothing.
- Shared components: none in the repo; Bootstrap component classes (`card`, `badge`, `btn`, `pagination`, `table`) are the only reusable vocabulary, used in `index.html.twig` and `show.html.twig`.

### View to source

- Every colour in the view comes from a Bootstrap role: `btn-primary` (line 8), `text-bg-success` (line 85), `text-muted` (lines 15, 23, 61, 63), `btn-outline-secondary` (line 55), `bg-body-tertiary` in the navbar (`base.html.twig:24`).
- Three decisions that look like charges but are intentional, so they are **not** charges: the "Nowa książka" button is shown to anonymous visitors on purpose (`BookListControllerTest.php:57-72`; `/books/new` redirects them to `/login`), recommendations are `null` for visitors (`BookController.php:42-43`), and login always lands on `/books` (`BookListControllerTest.php:76-77`).

## Code References

- `templates/book/index.html.twig:20` - `recommendation-card`, dead class
- `templates/book/index.html.twig:61, 63` - empty and no-results paragraphs
- `templates/book/index.html.twig:35, 66, 98` - search form, table, pager
- `templates/book/index.html.twig:85-86` and `templates/book/show.html.twig:39, 43, 70` - duplicated read/rating markup
- `assets/styles/app.css:1` - empty theme layer
- `src/Controller/BookController.php:29-47` - the view's data contract
- `tests/Controller/BookListControllerTest.php:43-335` - selectors the markup must keep

## Architecture Insights

- Server-rendered Twig with AssetMapper: no Node, no bundler (`AGENTS.md:21`), so the stack-native way to share markup is a Twig partial or component, and the visual gate is a kitchen-sink route or page rather than Playwright (none in the repo; inspected `composer.json`, `importmap.php`, `tests/` and `.github/workflows/deploy.yml` listing).
- The contract has two halves here: roles = Bootstrap variables, components = Bootstrap classes wrapped in Twig partials.

## Historical Context (from prior changes)

- `context/archive/2026-11-12-browse-and-search-books/` - built the list, search form and pager; its tests fix the selectors listed above.
- `context/foundation/lessons.md` - has no UI lesson; the four entries concern templates only through "don't branch on framework message text" (login), which does not apply to this view.

## Related Research

- `context/archive/2026-11-12-browse-and-search-books/research.md` - data-side research for the same page.

## Open Questions

1. Whether the plan wants a dark-mode pass (`data-bs-theme`): out of scope unless requested; the change notes do not mention it. (Decision for `/10x-plan`.)
2. Whether `text-muted` to `text-body-secondary` should also be applied to `show.html.twig` (3 occurrences, a different view): the "one view per change" rule says defer unless the plan accepts the global token scope.
3. Whether to extract the pager partial (C2): needs a product/size decision, not evidence.
