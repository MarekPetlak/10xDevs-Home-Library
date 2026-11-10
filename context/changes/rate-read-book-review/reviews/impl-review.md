<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Mark book as read and rate it

- **Plan**: context/changes/rate-read-book-review/plan.md (mirror of the archived S-03 plan)
- **Scope**: Full plan (Phases 1-5 of 5)
- **Reviewed phases**: 1, 2, 3, 4, 5
- **Date**: 2026-11-10
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 2 warnings, 6 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Success criteria run (current tree, branch fix/add-book-to-shared-collection-review)

Automated: migrations apply on the test DB, `doctrine:schema:validate` in sync, `php bin/phpunit` 145 tests / 473 assertions green, `lint:container`, `lint:twig` and `lint:yaml` OK, routes `book_index`, `book_show`, `book_read` and `book_read_remove` registered with the planned paths and methods, `AGENTS.md` mentions `ReadBook`, roadmap lists `reader-comments-privacy`. `docker build` succeeded earlier on this same tree; only archive documents were committed after it.
Manual rows are all ticked with SHAs; the production items (5.5-5.8) cannot be re-observed here.

All five privacy rules hold in code and are covered by tests: another user's rating appears only next to that user's comment, never on the list and never for a record without a comment; no raw e-mail appears next to comments; the viewer's own comment is excluded from the block; others' read dates are never shown; read records are immutable.

Checked and dismissed: the `Types::TEXT` entity mapping against the `LONGTEXT` migration column (schema:validate is clean).

## Findings

### F1 — An invalid POST from a user who already read the book shows the mark-read form

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Controller/ReadBookController.php:47-53
- **Detail**: When the read form is rejected (invalid input or a bad form CSRF token), the 400 re-render always passes `readBook => null`. If the user already marked the book (second tab, stale page), they see the mark-read form again and their own rating and comment are hidden, although the record exists. Rare, but misleading.
- **Fix**: Look up the user's own record; if one exists, redirect to `book_show` with the existing "already marked" warning; otherwise render the form as now. Add a test.
- **Decision**: SKIPPED (very rare: needs an already-marked book plus a stale page that fails validation or CSRF; no data harm, a reload shows the right state)

### F2 — CSRF failure on remove returns a hardcoded English plain-text response

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: src/Controller/ReadBookController.php:64-65
- **Detail**: `new Response('Invalid CSRF token.', 400)` is untranslated, while every other user-facing error uses a Polish translation key. A user with an expired session sees a bare English line.
- **Fix**: Keep the 400 but use a translated message (`reading.error.invalid_token`), and update the existing remove-CSRF tests.
- **Decision**: FIXED via Fix now (translated 400 message reading.error.invalid_token, test extended)

### F3 — Comments query hydrates full User entities and lists are unbounded

- **Severity**: 👁 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/Repository/ReadBookRepository.php:54-71, src/Repository/BookRepository.php:27-39
- **Detail**: `findCommentsForBook` fetch-joins whole `User` rows (including password hashes) only to print a masked e-mail, and neither it nor `/books` has a limit. The plan assigns pagination to S-05 and the household has 2-4 users, so the volume is not a problem today; the hydration of password hashes is unnecessary exposure inside the process.
- **Fix**: Keep as is until S-05; optionally change the comments query to select only the e-mail, rating and comment.
  - Strength: no change now, pagination arrives with S-05.
  - Tradeoff: password hashes keep being loaded into memory on the detail page.
  - Confidence: HIGH — the plan documents the no-pagination decision.
  - Blind spot: whether a partial select breaks the `ReadBook` entity-based template access.
- **Decision**: FIXED differently (findCommentsForBook returns CommentView DTOs selected via DQL NEW, no User entity or password hash is loaded; repository test added; pagination left to S-05)

### F4 — E-mail masking is weak for small groups

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/EmailMasker.php:27
- **Detail**: First letter plus full domain (`m***@gmail.com`) is often identifying in a household. The plan, the PRD and the class docblock already say it only reduces exposure.
- **Fix**: Accept as a known limitation until the privacy slice S-09.
- **Decision**: SKIPPED (known limitation until S-09)

### F5 — ReadBook::createdAt does not use the Clock

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: src/Entity/ReadBook.php:44
- **Detail**: `MarkRead::today()` uses the Symfony Clock, but `createdAt` uses `new \DateTimeImmutable()`. Comment ordering therefore cannot be tested with a frozen clock, and the stored time depends on the PHP time zone.
- **Fix**: Use `Clock::get()->now()` in the constructor, or accept the inconsistency.
- **Decision**: FIXED via Fix now (ReadBook::createdAt uses the Clock, test added)

### F6 — Remove loads authors and tags it does not use; stale docblock

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: src/Controller/ReadBookController.php:60, src/Repository/ReadBookRepository.php:48
- **Detail**: `remove` uses `findForDetail`, which fetch-joins authors and tags, only to check that the book exists. The `findCommentsForBook` docblock says "authors loaded" where it means users.
- **Fix**: Use `$bookRepository->find($id)` in `remove` and correct the docblock.
- **Decision**: FIXED via Fix now (remove uses find(); the docblock was corrected by the F3 rewrite)

### F7 — The mark-read form is built in two controllers

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: src/Controller/ReadBookController.php:31-33, src/Controller/BookController.php:45-52
- **Detail**: Both build `MarkReadType` with the same `action` URL and re-pass the template variables by hand in the 400 render.
- **Fix**: Low priority; a small shared helper or setting the action in the form type would remove the duplication.
- **Decision**: SKIPPED (low value for two call sites)

### F8 — Small deviations from the plan text

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: src/Dto/MarkRead.php:35-41
- **Detail**: The future-date rule is an `Assert\Callback` against `MarkRead::today()` instead of `LessThanOrEqual('today')`; this implements the Europe/Warsaw rule more reliably and is tested. Harmless extras: the `MarkRead::TIME_ZONE` constant and `today()` helper, the no-`@` branch in `EmailMasker`, an "add book" button on the list, and extra tests.
- **Fix**: Accept; the behaviour matches the plan's intent.
- **Decision**: SKIPPED (behaviour matches the plan intent)
