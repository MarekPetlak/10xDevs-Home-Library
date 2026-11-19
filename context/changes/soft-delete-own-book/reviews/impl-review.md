<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Soft-delete own book

- **Plan**: context/changes/soft-delete-own-book/plan.md
- **Scope**: Full plan (3 of 3 phases)
- **Reviewed phases**: 1, 2, 3
- **Date**: 2026-11-19
- **Verdict**: APPROVED
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

Success criteria re-run on 2026-11-19: migrate + schema:validate OK, migration down/up round trip OK, 381 tests / 1493 assertions OK, PHPStan no errors, lint:container / lint:twig / lint:yaml OK, UI token scan prints nothing. All 22 Progress rows are `[x]` with SHAs; manual rows 2.5 and 3.9-3.12 were confirmed by the user.

## Findings

### F1 — No test pins a non-owner's 404 on a deleted book

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Controller/BookDeleteControllerTest.php (whole file), tests/Controller/BookEditControllerTest.php, tests/Controller/DeletedBookVisibilityTest.php
- **Detail**: `findActive` runs before the voter, so a signed-in non-owner POSTing delete, or GETting show/edit, on someone else's deleted book gets 404, the same as an unknown id. Only the owner and anonymous cases are tested. If the order of `findActive` and `denyAccessUnlessGranted` is swapped, or `find()` is used, no test fails, and a non-owner would get 403 and learn that the book existed (test-plan risk #1).
- **Fix**: Add a test where a signed-in non-owner (with a valid token from their own book) POSTs delete and GETs show and edit on another user's deleted book, asserts 404 on all three and the same as for an unknown id.
- **Decision**: FIXED — added BookDeleteControllerTest::testSignedInNonOwnerGets404ForAnotherUsersDeletedBookJustLikeForAnUnknownId (non-owner POST delete, GET show, GET edit on a deleted book = 404, same as an unknown id); break-check: using find() instead of findActive turned 2 tests red

### F2 — Concurrent edit or mark-read can succeed on a book deleted a moment earlier

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/Service/ReadingManager.php:30, src/Service/BookManager.php (update)
- **Detail**: Controllers load the book through filtered finders, but `ReadingManager::markRead` and `BookManager::update` do not re-check `deletedAt`. A delete committed between the load and the flush leaves a ReadBook row (or an edit) on a deleted book. The row is invisible (detail 404, recommendations and `countByCategorySince` exclude the book) and there is no restore, so nothing resurfaces it. Single-request paths are protected by the controller 404.
- **Fix A ⭐ Recommended**: Accept the race and record it in the plan's risks.
  - Strength: Household scale, invisible and harmless outcome; no extra code.
  - Tradeoff: A stray ReadBook row can exist on a deleted book.
  - Confidence: HIGH — outcome verified invisible on all read paths.
  - Blind spot: A future restore feature would resurface such rows.
- **Fix B**: Add an `isDeleted()` guard inside the `wrapInTransaction` of `markRead` and `update` (or a pessimistic lock).
  - Strength: Closes the window.
  - Tradeoff: New behaviour and error path in two services, beyond the plan scope (plan says ReadingManager is left unchanged).
  - Confidence: MED — needs a decision on what the guard throws.
  - Blind spot: Lock behaviour under the test DAMA transaction not checked.
- **Decision**: ACCEPTED — Fixed via Fix A: race accepted and recorded in plan.md (What We are NOT Doing) and plan-brief.md (Open Risks & Assumptions); no code change

### F3 — `BookManager::delete` is not idempotent

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/BookManager.php:69-75
- **Detail**: Calling `delete()` on an already deleted entity overwrites `deletedAt`. The controller prevents it for one request (404 from `findActive`); two truly concurrent POSTs could both pass and the last timestamp wins. Harmless.
- **Fix**: Return early when `$book->isDeleted()`, and add a service test for the double delete.
- **Decision**: FIXED — BookManager::delete returns early for an already deleted book; added BookManagerTest::testDeletingAnAlreadyDeletedBookKeepsTheFirstDeletionTime; break-check: removing the guard turned it red

### F4 — Migration is not re-runnable after a partial failure

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: migrations/Version20261119100000.php:24-34
- **Detail**: With `isTransactional(): false`, a failure on `CREATE INDEX` leaves the column added and the migration unrecorded, so a re-run fails with a duplicate column. The `abortIf` checks the platform, not the server version, which lessons.md rule 4 asks for when altering columns; for an instant ADD COLUMN this is minor. Same trade-off as the neighbouring migrations.
- **Fix**: Optionally use `ADD COLUMN IF NOT EXISTS` and `CREATE INDEX IF NOT EXISTS` (MariaDB supports both).
- **Decision**: FIXED — migration up()/down() now use ADD COLUMN IF NOT EXISTS, CREATE INDEX IF NOT EXISTS, DROP INDEX IF EXISTS, DROP COLUMN IF EXISTS (MariaDB 10.1.4+), commented; verified down+down and up round trip, schema:validate in sync, full suite 383 tests OK

### F5 — Single-column index on a mostly-NULL column has little read benefit

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architecture
- **Location**: src/Entity/Book.php:18, migrations/Version20261119100000.php:34
- **Detail**: Nearly all rows have `deleted_at` NULL, so the optimizer is unlikely to use `IDX_BOOK_DELETED_AT` for `IS NULL`; list queries are served by the existing `(created_at, id)` index. The index was added at the user's request, so it adds a small write cost for little read gain at household scale.
- **Fix**: Keep as requested; revisit (for example a composite `(deleted_at, created_at, id)`) only if list filtering ever needs it.
- **Decision**: SKIPPED — index kept as requested by the user
