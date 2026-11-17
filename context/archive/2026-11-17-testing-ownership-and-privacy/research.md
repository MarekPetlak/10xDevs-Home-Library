---
date: 2026-11-17T19:19:03+01:00
researcher: Claude (Sonnet 5.5)
git_commit: 06e679f2e4f59c7a336cccafbf6bbffb27effecd
branch: main
repository: 10xdevs
topic: "Test-plan Phase 1 (risks #1, #2, #3): ownership and privacy — current code state and test gaps"
tags: [research, codebase, ownership, privacy, authorization, read-book, comments]
status: complete
last_updated: 2026-11-17
last_updated_by: Claude (Sonnet 5.5)
---

# Research: Ownership and privacy (test-plan §3 Phase 1, risks #1–#3)

**Date**: 2026-11-17T19:19:03+01:00
**Git Commit**: 06e679f2e4f59c7a336cccafbf6bbffb27effecd
**Branch**: main

## Research Question

Ground risks #1 (foreign user edits/deletes a book), #2 (foreign user deletes/changes another's comment or read record) and #3 (personal-data leak per role) from `context/foundation/test-plan.md` §2 against the current code. Which entry points exist today, what is already tested, and what must wait for S-07/S-08/S-09?

## Summary

- **Risk #1 has no code surface yet.** The inspected set is every route attribute in `src/Controller/` (6 controllers). `BookController` exposes only `book_index` (GET), `book_show` (GET) and `book_new` (GET/POST) (`src/Controller/BookController.php:105,125,152`). No route edits or deletes a `Book`. The `Book::addedBy` field exists (`src/Entity/Book.php:44-46`) but nothing reads it for authorization: `addedBy` is set once in `BookManager::add` (`src/Service/BookManager.php:53`) and appears in no template or controller (grep over `templates/` and `src/` excluding entities/fixtures). Edit/delete are roadmap S-07/S-08, status `proposed` (`context/foundation/roadmap.md:50-51`). A test for #1 today can only assert the absence of a route; the real ownership test must be written with S-07/S-08.
- **Risk #2 is partly real today, and structurally constrained.** The only mutating routes on read records are `POST /books/{id}/read` and `POST /books/{id}/read/remove` (`src/Controller/ReadBookController.php:23,56`). Neither takes a read-record id: the record is always resolved as `(current user, book)` via `findOneBy(['user' => $user, 'book' => $book])` (`src/Service/ReadingManager.php:32,60`). So a foreign record cannot be addressed by id; the "swap the id" attack from §2 does not apply to this code. Comments have no separate route; they are a field of the read record, not editable (roadmap `S-03` note, `roadmap.md:110`).
- **Risk #3 is real and already partly guarded by tests.** Third-party data reaches pages via two paths: the comment block (`ReadBookRepository::findCommentsForBook`, `src/Repository/ReadBookRepository.php:135-152`) and the recommendation aggregates (average rating and count, `src/Repository/BookRepository.php:155`). Privacy of comments per author choice (S-09) does not exist yet.
- **Existing coverage is good on single paths but thin on the multi-role matrix** (see Test inventory). The biggest gaps: no test asserts DB state after a foreign-user attempt to hit `remove` with a valid CSRF token against a book the *other* user read (the existing one covers it indirectly), no test for anonymous on `/books/new`-style POST with data, and no test of the recommendation aggregate for leaks of individual ratings.

## Detailed Findings

### Authorization shape

- `access_control` in `config/packages/security.yaml:43-45` has only commented-out examples (inspected lines 36-46); no URL-level rules. Authorization is per route via `#[IsGranted('ROLE_USER')]`.
- `ReadBookController::read` and `::remove` carry `#[IsGranted('ROLE_USER')]` (`ReadBookController.php:24,57`); `BookController::new` too (`BookController.php:153`). `index` and `show` are public by design (comment at `BookController.php:104`).
- Anonymous POST to both read routes redirects to `/login` — tested (`tests/Controller/ReadBookControllerTest.php:39-47`). Anonymous access to `book_new` is tested (`tests/Controller/BookControllerTest.php:37-42`).
- `remove` checks CSRF token `read_remove` before acting (`ReadBookController.php:65`); invalid/missing token returns 400 with DB unchanged — tested (`ReadBookControllerTest.php:188-202`). `read` relies on the form's CSRF; missing-token test exists (`ReadBookControllerTest.php:204-210`).

### Read records and comments (risk #2)

- `ReadingManager::remove` deletes only the record found for `(user, book)` and returns quietly if none exists (`ReadingManager.php:58-70`). The class docblock states "never touches another user's" (`ReadingManager.php:16-18`).
- Tested at service level (`tests/Service/ReadingManagerTest.php:87-100`, "RemoveDeletesOnlyTheGivenUsersRecord") and at controller level with two real users, asserting the other row remains with rating 5 (`ReadBookControllerTest.php:251-262`). This is the exact Phase-1 pattern (two users, DB-state assertion) and can serve as the reference test for cookbook §6.3.
- Removing one's own record removes the comment from others' view — tested (`tests/Controller/BookCommentsTest.php:109-122`).
- DB constraint: unique `(user_id, book_id)` (`src/Entity/ReadBook.php:14`); FKs `onDelete: RESTRICT` (`ReadBook.php:24,28`).

### Privacy per role (risk #3)

Observed behaviour per role on `GET /books/{id}` (`BookController.php:125-150`, `templates/book/show.html.twig:34-82`):

| Role | Own record | Others' comments | Others' ratings without comment | Author e-mail |
|------|-----------|------------------|--------------------------------|---------------|
| Anonymous | not rendered (`{% if app.user %}`, `show.html.twig:34`) | not rendered | not rendered | not rendered |
| Signed-in, not the author | n/a | shown with rating | not shown (`r.comment IS NOT NULL`, `ReadBookRepository.php:142`) | masked via `mask_email` (`show.html.twig:68`) |
| Signed-in author | shown in read-state block | own record excluded from the block (`r.user != :viewer`, `ReadBookRepository.php:143`) | n/a | n/a |

- Comment view query selects only e-mail, rating and text through a DTO; no `User` entity is hydrated (`ReadBookRepository.php:139`, docblock `:129-132`). The e-mail reaches the template raw but the only rendering path is the `mask_email` filter (`show.html.twig:68`); the Twig extension docblock states it is "the only way an e-mail address reaches a template next to shared content" (`src/Twig/EmailMaskExtension.php`, class docblock). This is a convention, not an enforced rule — **unverified by any test beyond the one comment scenario**.
- `EmailMasker` keeps first letter and domain, so it reduces but does not hide identity (docblock, `src/Service/EmailMasker.php:7-10`). Unit-tested (`tests/Service/EmailMaskerTest.php`, 35 lines; contents not read in this pass).
- Existing privacy tests: anonymous sees no comments/ratings/e-mail/form on detail page (`BookCommentsTest.php:137-153`); anonymous list/detail has catalogue data only (`BookListControllerTest.php:34-55`); anonymous home has no `@` in body and no recommendation block (`HomeControllerTest.php:49-56,68-77`); other user's rating never on my list/detail (`ReadBookControllerTest.php:221-249`). Tag page anonymous test exists (`TagControllerTest.php:38`; contents not read).
- Aggregates: `findRecommendationCandidates` returns `AVG(r.rating)` and `COUNT(r.id)` across all users' records for books the viewer has not read (`BookRepository.php:149-170`). This exposes an aggregate, not an individual rating; with one other reader the average equals that reader's rating. Whether this is acceptable under the PRD privacy guardrail is a **product question not settled in the inspected files** (roadmap S-03 Risk only says isolation of ratings "poza oceną pokazywaną obok komentarza" `roadmap.md:111`).
- Recommendations are `null` for anonymous (`BookController.php:121`), template guards with `app.user and recommendations is not null` (`templates/book/index.html.twig:11`) — tested.

### Pending on S-07 / S-08 / S-09

- S-07 (edit own book) and S-08 (soft delete) are `proposed`; no controller, voter, or template exists (verified: no `Voter` class in `src/`, file list of `src/`). Phase 1 cannot produce the "user B edits user A's book → denied, DB unchanged" test until they ship. `Book::addedBy` is the data it will rely on.
- S-09 (comment public/private) is `proposed`; `ReadBook` has no visibility column in the inspected mapping (`ReadBook.php` grep: user, book, rating, readOn, comment). Today every comment is visible to every signed-in user.
- `findForDetail` carries a comment saying the soft-delete filter goes there (`BookRepository.php:173-175`); `ReadBookController::remove` uses plain `find()` (`ReadBookController.php:61`) — S-08 will need to touch both.

## Test inventory (relevant, current state)

| Area | File | Relevant tests |
|------|------|----------------|
| Foreign remove, DB state | `tests/Controller/ReadBookControllerTest.php:251` | two users, only own row removed |
| Anonymous redirect | `ReadBookControllerTest.php:39`, `BookControllerTest.php:37` | read, remove, new |
| CSRF | `ReadBookControllerTest.php:188,204` | remove, read |
| Rating isolation | `ReadBookControllerTest.php:221,240` | list + detail |
| Comment visibility | `tests/Controller/BookCommentsTest.php` (9 tests) | masked e-mail, escaping, own excluded, anonymous |
| Query level | `tests/Repository/ReadBookRepositoryTest.php:49-73,123` | views, own/blank excluded, per-user read state |

## Gaps relative to test-plan §2 (Risk Response Guidance)

1. **#1**: no test possible yet; nothing exists to deny. Mark as waiting for S-07/S-08.
2. **#2**: foreign-delete is covered by id-less design plus one controller test; no test that a second user's `remove` with a *valid* token cannot delete the first user's record **when the second user has no record** (the "quiet return" branch, `ReadingManager.php:62-64`, is tested only for the same-user case at `ReadBookControllerTest.php:172`). Cheap to add; asserts DB unchanged.
3. **#3**: the privacy matrix is covered per role but spread across five files; missing: (a) anonymous check of `/tags/{slug}` for rating/e-mail leakage beyond catalogue data (contents of `TagControllerTest.php:38` not inspected), (b) assertion that the recommendation block shows only aggregate values and no e-mail or comment text, (c) a signed-in user's `/books` list never containing another user's comment or e-mail (only `5/5` and badge are asserted, `ReadBookControllerTest.php:229-231`).
4. The `mask_email`-only convention is not enforced by a test that a raw e-mail never appears in any signed-in page that lists comments (only `a***@example.com` form asserted in `BookCommentsTest.php:44-59`; assertion text not fully read).

## Code References

- `src/Controller/ReadBookController.php:23-73` — read/remove routes, `IsGranted`, CSRF check
- `src/Controller/BookController.php:105-191` — index/show/new; public vs. `IsGranted`
- `src/Service/ReadingManager.php:30-70` — `(user, book)` scoping of create/remove
- `src/Repository/ReadBookRepository.php:135-152` — comment view query (`comment IS NOT NULL`, viewer excluded)
- `src/Repository/BookRepository.php:149-186` — recommendation aggregates; `findForDetail` soft-delete placeholder
- `src/Entity/Book.php:44-46` — `addedBy` (ownership data, unused for authorization)
- `src/Entity/ReadBook.php:14-28` — unique `(user, book)`, RESTRICT FKs
- `templates/book/show.html.twig:34-82` — role gating and `mask_email`
- `src/Twig/EmailMaskExtension.php`, `src/Service/EmailMasker.php` — masking
- `config/packages/security.yaml:43-45` — no URL access rules

## Architecture Insights

- Ownership is enforced **by construction** (resolve by `(currentUser, book)`), not by a voter or check after load. Good for current routes; S-07/S-08 introduce id-addressed resources (a book by id), where a voter or explicit `addedBy` check will be required — the test pattern changes from "no id to swap" to "swap the id".
- Privacy is enforced in the query (DTO projection, `comment IS NOT NULL`, viewer exclusion) and in the template (`app.user`, `mask_email`). Two layers: tests should assert on response content, not on the template alone (matches §2 #3 "must challenge").
- Test base: functional tests with `loginUser`, `UserManager::create` for a second user, and per-test transaction rollback (dama bundle, §4 of test plan).

## Historical Context (from prior changes)

- `context/archive/2026-11-10-rate-read-book/` and `…-rate-read-book-review/` — S-03 introduced read records and comments; roadmap states comment is one per read record, no edit, removed with the record (`roadmap.md:110`). Not re-read in this pass beyond the roadmap entry.
- `context/foundation/lessons.md` — no entry about authorization or privacy; none constrains this phase.

## Related Research

None under `context/changes/` (only `bootstrap-verification`, not related).

## Open Questions

1. Are recommendation aggregates (average and count of others' ratings) acceptable under the PRD privacy guardrail, given that one reader makes the average equal their rating? Owner: user. Affects whether a Phase-1 test asserts or forbids this.
2. Do S-07/S-08/S-09 ship before Phase 1 is planned? If not, Phase 1 should cover #2/#3 now and leave #1 as a documented pending test (test-plan §3 note).
3. Contents of `TagControllerTest.php`, `EmailMaskerTest.php` and `BookCommentsTest.php:44-59` were not read in full; gap 3(a) and 4 should be re-checked in `/10x-plan`.
