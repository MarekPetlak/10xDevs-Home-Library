<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Add book to shared collection

- **Plan**: context/changes/add-book-to-shared-collection-review/plan.md (mirror of the archived S-02 plan)
- **Scope**: Full plan (Phases 1-3 of 3)
- **Reviewed phases**: 1, 2, 3
- **Date**: 2026-11-10
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

## Success criteria run (current tree)

Automated: migrations apply on the test DB, `doctrine:schema:validate` in sync, `doctrine:migrations:diff` reports no changes, `php bin/phpunit` 132 tests / 441 assertions green, `lint:container` OK, `debug:router book_new` shows `/books/new` (GET|POST), `AGENTS.md` mentions `App\Enum\Category`, `docker build` succeeds.
Manual rows are all ticked with SHAs; the production items (1.6, 3.4-3.6) and the dev-database checks cannot be re-observed here.

Not findings (checked and dismissed): the ISBN index is intentionally non-unique (plan, "What We're NOT Doing"); title whitespace is already trimmed because `TextType` trims by default.

## Findings

### F1 — Concurrent creation of the same author or tag ends in a 500

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/Service/BookManager.php:56-84 (also :30-53)
- **Detail**: Two concurrent requests can both miss the author or tag lookup and then insert the same name. The second hits `UNIQ_AUTHOR_NAME` or `UNIQ_TAG_NAME`; the `UniqueConstraintViolationException` propagates as a 500 and Doctrine closes the EntityManager. `ReadingManager::markRead` already handles the same exception. No test covers the race. In a household of 2-4 users the probability is very low.
- **Fix A ⭐ Recommended**: Catch the exception around `wrapInTransaction` in the controller path and answer with a friendly "try again" message (400 with the form re-rendered and entered values kept)
  - Strength: no retry logic and no EntityManager juggling; matches how `ReadingManager` turns the error into a domain exception.
  - Tradeoff: the user has to submit again.
  - Confidence: HIGH — same pattern as `AlreadyReadException` and `UserManagementException`.
  - Blind spot: the exact message and where it is shown in the form.
- **Fix B**: Catch the exception and retry the whole `add()` once with a fresh EntityManager (`ManagerRegistry::resetManager()`)
  - Strength: the user never sees the failure.
  - Tradeoff: more moving parts, and retrying with a reset manager has its own pitfalls (detached `addedBy` user).
  - Confidence: MED — not tried in this codebase.
  - Blind spot: behaviour of the session user entity after the reset.
- **Decision**: FIXED via Fix A (BookManager throws BookConflictException; the controller re-renders the form with a Polish try-again error, 400; tests added)

### F2 — No upper bound on the number of authors or on the author and tag text length

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Dto/NewBook.php:28, :41 (callbacks at :46-105), src/Service/BookManager.php:59-67
- **Detail**: Only the tag count is capped (5). `authors` has no count limit and neither `authors` nor `tags` has an overall length limit. A signed-in user can post thousands of comma-separated authors; each new author does its own SELECT, INSERT and flush inside one transaction, and creates junk author rows.
- **Fix**: Add a maximum author count (for example 10, with a Polish message) and `Length(max: 1000)` on `authors` and `tags`, with tests.
- **Decision**: FIXED via Fix now (max 10 authors, 1000-character limit on authors and tags, Polish messages, tests added)

### F3 — Case-insensitive collation also merges accents; table collation is not pinned

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Entity/Author.php:21,25, src/Entity/Tag.php:20, migrations/Version20261103191639.php:19-22
- **Detail**: `utf8mb4_unicode_ci` is accent-insensitive, so "José" and "Jose", or "żółw" and "zolw", become one author or tag. The tables are created with `DEFAULT CHARACTER SET utf8mb4` and no table collation, so other columns depend on the server default. The migration is already applied in production.
- **Fix**: Accept and document the accent merging in AGENTS.md; pin collations only in a future migration if it matters.
- **Decision**: FIXED differently + ACCEPTED-AS-RULE: Pin both case and accent behaviour on uniquely indexed text columns (new migration to utf8mb4_uca1400_as_ci, entity collations, AGENTS.md, accent test)

### F4 — Validation attributes on Author are never evaluated

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: src/Entity/Author.php:22,26
- **Detail**: `Assert\NotBlank` on the entity is dead because the DTO owns validation; `Book` and `Tag` have no such attributes.
- **Fix**: Remove the two attributes.
- **Decision**: FIXED via Fix now (dead NotBlank attributes and unused import removed from Author)

### F5 — Two small deviations from the plan text

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: tests/Service/BookManagerTest.php:79-90, src/Service/BookManager.php:31,38
- **Detail**: The plan says the service test checks that the ISBN is stored in normalised form, but `testIsbnIsStored` feeds an already normalised value; normalisation is covered only at form level (`BookControllerTest::testValidIsbn10IsAcceptedAndUpperCased`). Description trimming and empty-to-null live in `BookManager::add` rather than in the DTO as the plan said; the behaviour is identical.
- **Fix**: Accept; both are covered by tests at another level.
- **Decision**: FIXED differently (description trimming moved into NewBook with a property hook; DTO test added; the service ISBN test gap was left to the form-level test)
