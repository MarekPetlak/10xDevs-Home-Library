<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Home Recommendations

- **Plan**: context/changes/home-recommendations/plan.md
- **Scope**: Full plan (Phases 1-3 of 3)
- **Reviewed phases**: 1, 2, 3
- **Date**: 2026-11-12
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warnings, 5 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

Automated criteria re-run during review (commits 78b0352, 661a4fa, ca5c073, 64e3ff8): BookRepositoryTest 11 OK, ReadBookRepositoryTest 6 OK, RecommendationServiceTest 16 OK, HomeControllerTest 7 OK, full suite 183 tests / 562 assertions OK, `doctrine:schema:validate`, `lint:container`, `lint:twig templates/home`, `lint:yaml translations` all exit 0. Manual rows 3.5-3.9 were confirmed by the user before the phase 3 commit.

## Findings

### F1 — A single-rating card can reveal one person's rating

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/Repository/BookRepository.php:59, templates/home/index.html.twig:27
- **Detail**: The PRD rule has no minimum rating count (prd.md:109) and the household has 2-4 users. A card "Średnia ocena: 4,0 (liczba ocen: 1)" therefore shows the exact rating of one other household member. The PRD guardrail says ratings are personal (prd.md:46), with an exception only for a rating shown next to its author's comment. The rule itself says to average all users' ratings, so this is accepted by the PRD text, but the conflict between the two statements is not recorded anywhere.
- **Fix A ⭐ Recommended**: Keep the rule as decided and record the decision in the PRD (a one-line Socrates note under FR-010 or Guardrails)
  - Strength: Matches the explicit planning decision (no minimum count); no code change; the trade-off becomes visible to future readers.
  - Tradeoff: Individual ratings stay inferable when exactly one person rated a book.
  - Confidence: HIGH — the PRD already allows averages from all users; the owner chose "bez minimalnej liczby ocen".
  - Blind spot: Whether the owner considers the exposure acceptable among 2-4 household members.
- **Fix B**: Require at least 2 ratings (`HAVING COUNT(r.id) >= 2` in `findRecommendationCandidates`)
  - Strength: Closes the single-rating exposure.
  - Tradeoff: Changes the PRD rule; with 2-4 users many books would never qualify and the empty state would appear more often.
  - Confidence: MEDIUM — depends on how many books have two or more ratings in practice.
  - Blind spot: Real rating distribution is unknown.
- **Decision**: FIXED via Fix A (note added under FR-010 in prd.md; rule unchanged)

### F2 — HomeControllerTest layout differs from neighbouring tests

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: tests/Controller/HomeControllerTest.php:122-124, :175
- **Detail**: The `$bookIds` property (with an orphaned `@var` docblock describing seed data) sits between test methods and `tearDown()` is at the end of the file. BookRepositoryTest and ReadBookRepositoryTest put properties first and set-up/tear-down at the top.
- **Fix**: Move the property to the top, place `tearDown()` after the tests' setup, and give the seed docblock its own method.
- **Decision**: FIXED (property and tearDown moved to the top of HomeControllerTest)

### F3 — 12-month window on 29 February is one day short

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/RecommendationService.php:34
- **Detail**: `modify('-12 months')` overflows: verified that 2028-02-29 becomes 2027-03-01, so on that one day the window loses a day. Harmless for a "last year" heuristic and not covered by a test.
- **Fix**: Accept it and add one test that pins the 2028-02-29 behaviour (or a short comment), so the overflow is documented rather than accidental.
- **Decision**: FIXED (added testWindowOnLeapDayStartsOnTheFirstOfMarchOfThePreviousYear)

### F4 — Soft-deleted books and the top-category count are not documented

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Architecture
- **Location**: src/Repository/ReadBookRepository.php:82
- **Detail**: `countByCategorySince` joins `r.book` for the category, so once S-08 adds soft delete, a deleted book would still count toward the user's top category. That is arguably right (the user read it) but the seam note in `BookRepository` only covers the candidate query.
- **Fix**: Add one docblock sentence to `countByCategorySince` stating the choice, so S-08 does not have to rediscover it.
- **Decision**: DEFERRED to S-08 (user: do nothing now; queued in follow-ups/review-fixes.md)

### F5 — Category comparator is hard to read

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: src/Service/RecommendationService.php:37-38
- **Detail**: The sort is correct (verified: count desc, latest read day desc, enum declaration order asc), but it is one dense array-spaceship expression using `array_search` over `Category::cases()`.
- **Fix**: Extract a small private static compare method with a named category rank.
- **Decision**: FIXED (extracted compareCategoryRows and enumRank in RecommendationService)

### F6 — Two test helpers/assertions are weaker than they look

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: tests/Repository/BookRepositoryTest.php:132, :206; tests/Controller/HomeControllerTest.php:106-117
- **Detail**: `$this->ids([$this->view($book)])` builds a throwaway view only to extract an id (it could be `[(int) $book->getId()]`). The e-mail leak test would also pass if the feature never read e-mails, so it is a weak guard (cheap, still worth keeping).
- **Fix**: Assert the id directly and drop the `view()` helper; leave the leak test as a smoke check.
- **Decision**: FIXED (assert [(int) $book->getId()] directly; view() helper removed)
