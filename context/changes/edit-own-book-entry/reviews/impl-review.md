<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Edit own book entry

- **Plan**: context/changes/edit-own-book-entry/plan.md
- **Scope**: Full plan
- **Reviewed phases**: 1, 2, 3
- **Date**: 2026-11-17
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | WARNING |
| Success Criteria | PASS |

## Findings

### F1 — No controller-level test for the edit conflict branch

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Controller/BookEditControllerTest.php (missing); sibling: tests/Controller/BookControllerTest.php:134
- **Detail**: `BookController::edit` handles `BookConflictException` (400, "Spróbuj ponownie" form error, input kept), but only the service is covered (`BookManagerTest::testLostRaceDuringUpdateBecomesABookConflictException`). The add flow has the equivalent controller test `testLostCreationRaceShowsATryAgainErrorInsteadOfA500`; edit does not. It is also the only guard for F2.
- **Fix**: Add `testLostUpdateRaceShowsATryAgainErrorInsteadOfA500` to BookEditControllerTest, mirroring BookControllerTest.php:134 (stub EntityManager whose `wrapInTransaction` throws `UniqueConstraintViolationException`; assert 400, error text, input kept).
- **Decision**: FIXED — added testLostUpdateRaceShowsATryAgainErrorInsteadOfA500; setUp builds BookManager by hand so the container service can be replaced

### F2 — Managed Book is partly mutated when the edit conflicts

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Controller/BookController.php:144-148, src/Service/BookManager.php:51-60
- **Detail**: `update()` applies the new fields to the managed `$book` before the flush. On `BookConflictException` the DB rolls back and the EntityManager is closed, but the in-memory `$book` stays mutated and is passed to `edit.html.twig`. Today the template reads only `book.id` (back link) and the heading is static, so nothing wrong is shown. Putting `book.title` into the heading or `<title>` later would show unsaved values or lazy-load on a closed EM.
- **Fix**: Add a short comment at the controller's conflict branch (and keep F1's test) stating that `$book` must only be used for its id there.
- **Decision**: SKIPPED

### F3 — Orphaned authors and tags after edit

- **Severity**: 👁 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/Service/BookManager.php:96-102
- **Detail**: Removing an author or tag from a book leaves the `Author`/`Tag` row. This is an explicit "NOT doing" in the plan, but tag pages (`/tags/{slug}`) or author facets could now list entries with zero books. `ReadBook` is untouched and covered by tests.
- **Fix**: Check that tag and author listings handle zero-book entries (hide or show empty state); if they do not, queue a follow-up in `follow-ups/review-fixes.md` rather than changing this change.
  - Strength: Keeps this change within its approved scope.
  - Tradeoff: Leaves a possible empty tag page until the follow-up lands.
  - Confidence: MED — listing queries were not inspected by the review.
  - Blind spot: How TagController and the tag list handle tags with no books.
- **Decision**: FIXED differently — recorded in context/foundation/roadmap.md under Parked as "cleanup-orphan-authors-tags" (outside milestone M-1), to be picked up as a separate slice after S-07

### F4 — Migration abortIf checks platform, not version

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: migrations/Version20261117201022.php:25-28
- **Detail**: The message and comment say MariaDB 10.3+, but only `MariaDBPlatform` is checked. Same style as the sibling migration, and a nullable ADD COLUMN is trivially safe, so practical risk is nil.
- **Fix**: None needed; optionally reword the message to "MariaDB" without the version.
- **Decision**: FIXED — abortIf message no longer claims an unchecked version (migrations/Version20261117201022.php)

## Notes

- Verified commands (all PASS): `phpunit` full (346 tests, 1305 assertions), `phpunit tests/Service tests/Security`, `BookControllerTest`, `BookEditControllerTest` + `PrivacyMatrixTest`, `doctrine:schema:validate` (test env), `lint:container`, `lint:twig`, `lint:yaml`, `phpstan`.
- All Progress rows 1.1–3.3 are `[x]` with SHAs; manual steps 2.5–2.9 and 3.3 were confirmed by the user.
- Accepted deviations (not findings): `confirm_label` option on BookType, `hint` variable in `_duplicate_warning`, extra translation keys, `Clock::get()->now()`, unit tests added in existing files instead of `BookManagerUpdateTest.php`, migration `isTransactional(): false` and `abortIf`.
- Roadmap S-07 is `in-progress` until `/10x-archive` closes it as `done`.
