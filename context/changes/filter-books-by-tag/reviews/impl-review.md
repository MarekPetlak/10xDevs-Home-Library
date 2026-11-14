<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Filter books by tag

- **Plan**: context/changes/filter-books-by-tag/plan.md
- **Scope**: Full plan (3 of 3 phases)
- **Reviewed phases**: 1, 2, 3
- **Date**: 2026-11-14
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 2 warnings, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

Automated criteria re-run: `bin/phpunit tests/Service` 66 OK; `tests/Repository tests/Controller` 164 OK; whole suite 269 OK; `doctrine:schema:validate` in sync (dev and test). Manual rows 1.5, 2.5-2.8, 3.4-3.6 were confirmed by the user in-session.

## Findings

### F1 — Slug can exceed the 40-character column

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/Service/TagSlugger.php:23, src/Entity/Tag.php:28, migrations/Version20261114100000.php:35
- **Detail**: Tag names are capped at 30 characters (`NewBook::MAX_TAG_LENGTH`) but transliteration can lengthen them. Verified here: 30 × `ß` gives a 60-character slug, 10 × `中` gives 59. The dev DB runs `STRICT_TRANS_TABLES`, so inserting such a tag raises a data-too-long error that `BookManager` does not catch (HTTP 500). The migration backfill would fail half-way on such an existing tag.
- **Fix**: Truncate the base slug (in `TagSlugger` and in the migration's frozen copy) so base plus `-N` fits 40 characters, trimming a trailing `-`; add tests with a 30-character `ß` name and a CJK name.
  - Strength: Small, local change; keeps the column and URLs short.
  - Tradeoff: Two long names sharing a 36-character prefix rely on the suffix to stay unique.
  - Confidence: HIGH — overflow reproduced with AsciiSlugger.
  - Blind spot: None significant.
- **Decision**: FIXED — Fix now: base slug cut to 36 chars in TagSlugger and the migration copy; two tests added

### F2 — Migration is not restartable

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: migrations/Version20261114100000.php:20
- **Detail**: `ADD slug` is queued with `addSql`, while the backfill, `NOT NULL` and the unique index run immediately in `postUp()`. MariaDB DDL cannot be rolled back, so a failure in `postUp()` (F1, deadlock, lock timeout) leaves the `slug` column behind with the migration unrecorded; the next `migrate` then fails with a duplicate column. The reviewer also suspects `postUp()` still runs under `--dry-run`; this was not verified.
- **Fix**: Make each step idempotent (skip `ADD slug` and `CREATE UNIQUE INDEX` when they already exist) and wrap the backfill loop in a transaction.
  - Strength: A failed run can simply be re-run; matches the migration safety lesson.
  - Tradeoff: A few more lines of schema introspection in the migration.
  - Confidence: MED — failure mode is standard for MariaDB DDL, dry-run behaviour unchecked.
  - Blind spot: Behaviour of `postUp()` under `--dry-run`.
- **Decision**: FIXED — Fix now: ADD slug and the unique index are skipped when present, backfill runs in one transaction, isTransactional() is false (MariaDB DDL commits implicitly). Verified on the test DB: fresh apply with data, and a re-run with column, index and data already in place

### F3 — Slug-only race and deadlocks are not tested or mapped

- **Severity**: 💬 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/BookManager.php:38, tests/Service/BookManagerTest.php
- **Detail**: A slug collision between two different names maps to `BookConflictException` through `UniqueConstraintViolationException`, but only the name race is tested. InnoDB deadlocks (`RetryableException`) were already uncaught for authors and tags.
- **Fix**: Add a slug-only lost-race test; leave deadlock handling as is.
- **Decision**: FIXED — Fix now: slug-only lost-race test added to BookManagerTest; deadlock handling left as is

### F4 — Migration guard checks the vendor, not the server version

- **Severity**: 💬 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: migrations/Version20261114100000.php:20
- **Detail**: `instanceof MariaDBPlatform` lets MariaDB below 10.10 through to a cryptic SQL error. The sibling migration Version20261112120000 behaves the same, so this is consistent with existing code.
- **Fix**: Optionally add a `version_compare` check on `SELECT VERSION()` here and in the sibling.
- **Decision**: SKIPPED — consistent with the sibling migration; production and CI run MariaDB 10.11

### F5 — Tag page empty state wording and duplicated list query

- **Severity**: 💬 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: templates/tag/show.html.twig:12, src/Repository/BookRepository.php:67
- **Detail**: A page past the end of a tag shows "no search results / clear search" copy, as the plan prescribed. `findByTag()` repeats the ordering and paging of `search()`.
- **Fix**: Optionally add a tag-specific empty variant and extract a private base query builder before S-08 (soft delete) lands.
- **Decision**: SKIPPED — matches the plan and /books; the duplication is small
