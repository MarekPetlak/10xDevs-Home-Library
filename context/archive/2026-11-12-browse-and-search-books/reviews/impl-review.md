<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Browse and search books

- **Plan**: context/changes/browse-and-search-books/plan.md
- **Scope**: Full plan (4 of 4 phases)
- **Reviewed phases**: 1, 2, 3, 4
- **Date**: 2026-11-12
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 3 warnings, 5 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Success criteria

- `bin/phpunit tests/Controller` PASS (105 tests); `bin/phpunit tests/Repository` PASS (27); full suite PASS (228 tests, 745 assertions)
- `doctrine:schema:validate` OK; migrations: 6 executed, 0 new; `GET /` returns 302 to `/books`
- Manual rows: all `[x]` with a commit SHA and covered by diff evidence; no pending rows

## Findings

### F1 — List loads every read record of the user, not just the page's

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Adherence
- **Location**: src/Repository/ReadBookRepository.php:31, src/Controller/BookController.php:42
- **Detail**: The plan (Phase 2 §3) said `readBooks` is looked up only for the books on the page. `findByUserIndexedByBookId` still loads all of the user's reads and hydrates every Book (`addSelect('b')`) on each list request, so cost grows with the user's history, not with the 20-row page. Phase 2 noted this deviation but it was never fixed.
- **Fix**: Add a books filter to the method (`r.book IN (:books)`) and pass the page's books; skip the call when the page is empty.
  - Strength: Matches the plan, bounds the work at 20 rows.
  - Tradeoff: Small repository change plus a test for the filtered lookup.
  - Confidence: HIGH — the read state is only used per row on the page.
  - Blind spot: Other callers of the method (none found in src/).
- **Decision**: FIXED (Fix now): findByUserIndexedByBookId now takes the page's books; tests added

### F2 — Page beyond the last shows a misleading "no results" and an unhighlighted pager

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Controller/BookController.php:34-40, templates/book/index.html.twig:60-63
- **Detail**: `?page=N` past the last page (up to 100000) returns 200 with empty books while `total > 0`. The template shows "Żadna książka nie pasuje do wyszukiwania" even with no filter set, and the pager has no active page.
- **Fix**: In the controller, when `total > 0` and `page > pageCount`, redirect to the same URL with `page = pageCount`; add a test.
- **Decision**: SKIPPED

### F3 — Pager renders every page number

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: templates/book/index.html.twig:97-107
- **Detail**: The plan said pages 1..N, but at the PRD's 10,000 books that is 500 links on every page.
- **Fix A ⭐ Recommended**: Window the pager: first, last, current ±2, previous/next, with gaps.
  - Strength: Constant-size pager, usable on mobile; filters stay in every link.
  - Tradeoff: More Twig logic and tests; deviates from the plan's literal "1..N".
  - Confidence: HIGH — standard pattern, data in the template already.
  - Blind spot: Accessibility labels for prev/next and the gaps need Polish strings.
- **Fix B**: Keep 1..N until the library is large.
  - Strength: No work now, matches the plan.
  - Tradeoff: Degrades visibly past a few dozen pages.
  - Confidence: MEDIUM — depends on real library growth (today 6 books).
  - Blind spot: No pager test at large page counts.
- **Decision**: FIXED via Fix A: windowed pager with previous/next and gaps; test added

### F4 — Collation migration has no platform guard and a silent table rebuild

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: migrations/Version20261112120000.php:22
- **Detail**: `COLLATE utf8mb4_uca1400_as_ci` needs MariaDB 10.10+ and the ALTER rebuilds and locks the table. No `abortIf` guard; the existing migration `Version20261110161533` has the same shape, so this is consistent with the repo.
- **Fix**: Add a short comment about the rebuild and lock to `up()`; optionally an `abortIf` on the platform.
- **Decision**: FIXED + ACCEPTED-AS-RULE: State engine requirements and table rebuilds in schema migrations (comment and abortIf added)

### F5 — No index for the list ordering

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Entity/Book.php (no index on created_at), src/Repository/BookRepository.php
- **Detail**: `ORDER BY created_at DESC, id DESC` sorts the whole table; the only index on `book` is `IDX_BOOK_ISBN`. Fine at 10,000 rows, worth noting if the table grows.
- **Fix**: Add an index on `(created_at, id)` through a migration when needed.
- **Decision**: FIXED: index on book (created_at, id), migration Version20261112130000

### F6 — Search text has no length cap and trims ASCII whitespace only

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Dto/BookSearch.php (`text()`)
- **Detail**: An 8 KB `title` runs a full LIKE scan, and a non-breaking space counts as a filter.
- **Fix**: Cap text at ~255 characters (`mb_substr`) and trim Unicode whitespace.
- **Decision**: FIXED: text capped at 255 characters, Unicode whitespace trimmed, tests added

### F7 — Search form is hidden on an empty, unfiltered library (not in the plan)

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: templates/book/index.html.twig:34
- **Detail**: Phase 3 said the form exists for everyone; the template hides it when `total == 0` and no filter is active. Deliberate and tested, but undocumented in the plan.
- **Fix**: Add one line to the Phase 3 template contract in plan.md.
- **Decision**: FIXED: documented in the plan (Phase 3 contract)

### F8 — Naming nits

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: translations/messages.pl.yaml (`home.recommendations.*`), src/Repository/BookRepository.php (`PER_PAGE` after the constructor)
- **Detail**: The `home.*` namespace now holds only recommendation keys used by the book list; the constant is declared after the constructor.
- **Fix**: Optionally rename to `book.index.recommendations.*` and move the constant above the constructor.
- **Decision**: FIXED: recommendation keys moved to book.index.recommendations.*, PER_PAGE moved above the constructor
