<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Duplicate book warning

- **Plan**: context/changes/duplicate-book-warning/plan.md
- **Scope**: Full plan (3 of 3 phases)
- **Reviewed phases**: 1, 2, 3
- **Date**: 2026-11-14
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

Success criteria re-run in this review: IsbnFormsTest 8 OK, BookRepositoryTest 28 OK, DuplicateFinderTest 6 OK, BookControllerTest 34 OK, full `bin/phpunit` 266 tests OK (own DB `app_testdupwarn`), UI-contract scan prints nothing, Progress has 0 open rows. `npm run e2e` was not re-run in this review (36/36 at the end of phase 3).

## Findings

### F1 — Reason label can be empty when PHP and DB title comparison disagree

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/DuplicateFinder.php:71 (sameTitleAndAuthor), templates/book/_duplicate_warning.html.twig:14
- **Detail**: The SQL matches titles with the `utf8mb4_uca1400_as_ci` collation, while `sameTitleAndAuthor` re-compares them in PHP with `mb_strtolower(trim())`. They can disagree (for example "ß" and "ss"). A book returned by the query through the title rule then gets both flags false and the warning renders an empty reason "()".
- **Fix**: A book that did not match by ISBN can only have come from the title rule, so set `sameTitleAndAuthor = !$sameIsbn || <existing PHP check>`; the PHP check then only decides the second label for books that match both ways.
- **Decision**: FIXED via the proposed fix (`sameTitleAndAuthor = !$sameIsbn || ...`). Review note: the example in the finding (ß vs ss) is not equal under this collation; the real disagreement is Greek sigma (`ΟΔΟΣ` vs `οδοσ`) and full-width letters, and the regression test uses sigma.

### F2 — Add anyway after a concurrent-conflict error needs a second confirmation

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Controller/BookController.php:99-105
- **Detail**: If "Dodaj mimo to" was clicked and `BookConflictException` is thrown, the form is rendered without `duplicates`, so the confirm button is hidden. The user presses the normal button, the check re-runs and the warning appears again. Safe (no data risk, EntityManager untouched), only small friction on a rare race.
- **Fix**: Leave as is.
- **Decision**: SKIPPED (rare race, safe behaviour)
