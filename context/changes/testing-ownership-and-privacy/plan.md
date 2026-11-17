# Ownership and Privacy Tests Implementation Plan

## Overview

Test-plan §3 Phase 1 ("Własność i prywatność"): add the missing integration tests for risks #2 (foreign user deletes or changes another's read record or comment) and #3 (personal data leaks per role), and fill cookbook §6.3 and §6.4. No production code changes. Risk #1 (foreign user edits or deletes a book) is deferred to S-07/S-08 because the routes do not exist yet.

## Current State Analysis

- Only read-record routes mutate user-owned data: `POST /books/{id}/read` and `/read/remove` (`src/Controller/ReadBookController.php:23,56`). Neither takes a record id; the record is resolved as `(current user, book)` (`src/Service/ReadingManager.php:32,60`), so a foreign record cannot be addressed by id.
- Edit and delete of books are roadmap S-07/S-08, status `proposed` (`context/foundation/roadmap.md:50-51`); S-09 (public/private comments) too. `Book::addedBy` exists but is unused for authorization (`src/Entity/Book.php:44-46`).
- Existing tests cover many single paths (see `research.md`, Test inventory). Scattered across `ReadBookControllerTest`, `BookCommentsTest`, `BookListControllerTest`, `TagControllerTest`, `HomeControllerTest`; no single role × page matrix.
- Follow-up reading closed two research gaps: the anonymous tag page already asserts no rating and no comment (`tests/Controller/TagControllerTest.php:38-60`), and the comment page already asserts no raw e-mail (`tests/Controller/BookCommentsTest.php:44-59`). `HomeControllerTest` already asserts that other users' e-mails are absent from the recommendation block (around `:152`).
- The recommendation average and count of others' ratings is a conscious, kept PRD decision (`context/foundation/prd.md:97`), so tests accept it.
- CI already runs `php bin/phpunit` (`.github/workflows/deploy.yml:53`), so new tests under `tests/` are part of the required gate with no workflow change.

## Desired End State

- A foreign user's `remove` can never delete another user's read record, including when the foreign user has no record of their own.
- One test class, `tests/Controller/PrivacyMatrixTest.php`, states for each role (anonymous, other signed-in user, record author) exactly which personal data each page may contain.
- `test-plan.md` §6.3 and §6.4 name the reference tests, locations, naming and run command; §6.6 records what is pending for S-07/S-08/S-09; §3 Phase 1 is `complete`.
- Verified by `bin/phpunit` green, and by temporarily breaking the guarded behaviour (see Phase 2 manual check) turning a test red.

### Key Discoveries:

- Ownership is enforced by construction, not by a voter; the "swap the id" attack does not apply to current routes (`ReadingManager.php:32,60`).
- Privacy is enforced in the query (`comment IS NOT NULL`, viewer excluded, DTO projection, `src/Repository/ReadBookRepository.php:135-152`) and in the template (`app.user`, `mask_email`, `templates/book/show.html.twig:34,68`). Tests must assert on response content, not on templates.
- Test conventions: `WebTestCase`, `loginUser`, `UserManager::create` for a second user, per-test rollback (dama bundle), `$em->clear()` before DB assertions (`ReadBookControllerTest.php:341-346`).
- Lesson: `context/foundation/lessons.md` — do not branch on framework message text; assert status codes and DB state, not translated messages.

## What We're NOT Doing

- No tests for book edit/delete ownership (#1): deferred to the S-07/S-08 plans; recorded in §6.3 and §6.6.
- No tests for public/private comments (S-09).
- No route-absence guard test.
- No production code, migration, voter or CI workflow changes.
- No mutation testing, static analysis or CI gate wiring (test-plan §3 Phase 4).
- No forbidding of single-rating disclosure through recommendation averages (accepted by PRD).
- No e2e or visual tests (test-plan §7).

## Implementation Approach

Fill only real gaps. Phase 1 adds the one missing ownership case next to the existing ownership tests. Phase 2 consolidates the privacy checks into one role × page matrix test class, using a shared seeded dataset with a distinctive marker in the comment text and a distinctive e-mail, so a leak is detected by string search of the full response. Expected values come from PRD/test-plan, not from the implementation (oracle rule). Phase 3 documents the patterns.

## Phase 1: Missing ownership test

### Overview

Pin that a valid-token `remove` by a user who has no record cannot affect another user's record.

### Changes Required:

#### 1. Foreign remove without own record

**File**: `tests/Controller/ReadBookControllerTest.php`

**Intent**: Add a test next to `testRemoveOnlyAffectsTheCurrentUsersOwnRecord`: user A has a record (rating, comment) on the book; user B has none and posts `remove` with a valid CSRF token. Assert redirect, and that A's row still exists with unchanged rating, comment and read date.

**Contract**: New test method; reuses the existing `removeWithToken()`/`entityManager()` helpers. The DB state assertion reads the row by user and book after `clear()`. The status assertion is a redirect, not a message text.

### Success Criteria:

#### Automated Verification:

- New test passes: `php bin/phpunit tests/Controller/ReadBookControllerTest.php`
- Full suite passes: `php bin/phpunit`
- Mutation check: with `src/Service/ReadingManager.php` clean in git, temporarily change `remove` to look the record up by book only (`findOneBy(['book' => $book])`), run `php bin/phpunit --filter <new test name> tests/Controller/ReadBookControllerTest.php` and expect a non-zero exit; then restore with `git checkout -- src/Service/ReadingManager.php` and confirm `git diff --quiet -- src/Service/ReadingManager.php`

**Implementation Note**: After this phase and all automated verification pass, proceed to Phase 2; no manual step is required.

---

## Phase 2: Privacy matrix

### Overview

One class that states, per role and page, which personal data may appear.

### Changes Required:

#### 1. Matrix test class

**File**: `tests/Controller/PrivacyMatrixTest.php` (new)

**Intent**: Seed one book, an author-user (Anna) with a rating and a comment containing a unique marker, a second user (Ola) with a rating and no comment, and a third viewer. Request each page as each role and assert on the full response body for the presence or absence of the marker, the raw e-mails, the masked e-mail and the rating text.

**Contract**: Roles: anonymous, other signed-in user, record author. Pages: `/books`, `/books/{id}`, `/tags/{slug}`, plus the recommendation block on `/books` for a signed-in user with reads. Expected matrix (source: PRD guardrails, test-plan §2 #3, roadmap S-03):
- anonymous: no marker, no rating, no e-mail (raw or masked), no read form, no recommendation block, on all pages;
- other signed-in user: comment marker, rating and masked e-mail only inside `section#comments` on the detail page; never the raw e-mail; never a rating from a record without a comment; nothing of the others on `/books` and tag pages beyond catalogue data;
- author: own record in the read-state block, own comment not repeated in the comments block, other users' raw e-mails absent;
- recommendation block: shows the average and count, and contains no e-mail (raw or masked) and no comment text.
Use a data provider for role × page so each combination is a named case. Failure messages name the role and page.

### Success Criteria:

#### Automated Verification:

- Matrix test passes: `php bin/phpunit tests/Controller/PrivacyMatrixTest.php`
- Full suite passes: `php bin/phpunit`
- Mutation check 1: with `src/Repository/ReadBookRepository.php` clean in git, temporarily remove the `->andWhere('r.comment IS NOT NULL')` line in `findCommentsForBook`, run `php bin/phpunit tests/Controller/PrivacyMatrixTest.php` and expect a non-zero exit; restore with `git checkout -- src/Repository/ReadBookRepository.php` and confirm `git diff --quiet` for that file
- Mutation check 2: with `templates/book/show.html.twig` clean in git, temporarily replace `entry.email|mask_email` with `entry.email`, run `php bin/phpunit tests/Controller/PrivacyMatrixTest.php` and expect a non-zero exit; restore with `git checkout -- templates/book/show.html.twig` and confirm `git diff --quiet` for that file

**Implementation Note**: After this phase and all automated verification pass, proceed to Phase 3; no manual step is required.

---

## Phase 3: Cookbook and gate

### Overview

Turn the two reference tests into the documented pattern and close the phase in the test plan.

### Changes Required:

#### 1. Cookbook entries

**File**: `context/foundation/test-plan.md`

**Intent**: Replace the TBD in §6.3 with the ownership pattern (two users, DB-state assertion, no message-text assertions; reference test from Phase 1; run command) and state that book edit/delete ownership tests are added by S-07/S-08. Replace the TBD in §6.4 with the role × page matrix pattern (reference: `PrivacyMatrixTest`; the unique-marker technique; location `tests/Controller/`; naming `*Test`; run command) and state that S-09 must extend the matrix with public/private comments. Add 2–3 lines to §6.6 about surprises found.

**Contract**: §3 Phase 1 row status set to `complete`. §5 row "ownership / privacy integration" stays `required`; add a note that it is satisfied by the existing `php bin/phpunit` CI step (`.github/workflows/deploy.yml:53`). Update "Last updated".

### Success Criteria:

#### Automated Verification:

- No TBD left in §6.3 and §6.4: `grep -n "TBD" context/foundation/test-plan.md` shows only §6.1, §6.2, §6.5 entries
- Suite still green: `php bin/phpunit`

#### Manual Verification:

- A reader unfamiliar with the project can add an ownership test and a privacy test following only §6.3 and §6.4

---

## Testing Strategy

### Unit Tests:

- None; the guarded behaviour crosses controller, repository and template, so integration is the cheapest layer with real signal.

### Integration Tests:

- Phase 1 ownership case; Phase 2 role × page matrix, both with the real database in rolled-back transactions.

### Manual Testing Steps:

1. Run the two "break it on purpose" checks from Phases 1 and 2 and revert each.
2. Run `php bin/phpunit` once on the final tree.

## Performance Considerations

The matrix uses one seeded dataset per test; keep the case count to role × page (about a dozen) to avoid slowing the suite.

## Migration Notes

None. New or changed files are tests and one documentation file.

## References

- Related research: `context/changes/testing-ownership-and-privacy/research.md`
- Test plan: `context/foundation/test-plan.md` (§2 risks #1–#3, §3 Phase 1, §6.3, §6.4)
- Similar tests: `tests/Controller/ReadBookControllerTest.php:251`, `tests/Controller/BookCommentsTest.php:137`, `tests/Controller/TagControllerTest.php:38`
- Accepted aggregate exposure: `context/foundation/prd.md:97`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Missing ownership test

#### Automated

- [x] 1.1 New test passes: `php bin/phpunit tests/Controller/ReadBookControllerTest.php` — 1f897b6
- [x] 1.2 Full suite passes: `php bin/phpunit` — 1f897b6
- [x] 1.3 Mutation check: breaking `ReadingManager::remove` on purpose turns the new test red; file restored via git — 1f897b6

### Phase 2: Privacy matrix

#### Automated

- [x] 2.1 Matrix test passes: `php bin/phpunit tests/Controller/PrivacyMatrixTest.php` — 4821691
- [x] 2.2 Full suite passes: `php bin/phpunit` — 4821691
- [x] 2.3 Mutation check: removing `r.comment IS NOT NULL` on purpose turns a matrix case red; file restored via git — 4821691
- [x] 2.4 Mutation check: removing `mask_email` on purpose turns a matrix case red; file restored via git — 4821691

### Phase 3: Cookbook and gate

#### Automated

- [x] 3.1 No TBD left in §6.3 and §6.4 of `test-plan.md`
- [x] 3.2 Suite still green: `php bin/phpunit`

#### Manual

- [x] 3.3 §6.3 and §6.4 are enough to add an ownership test and a privacy test without other help
