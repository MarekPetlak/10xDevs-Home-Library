---
date: 2026-11-19
researcher: Claude (Sonnet 5.5)
git_commit: 2269f83daaeb3aa35e4ab5edc79d06e9132f1807
branch: main
repository: 10xdevs
topic: "Soft-delete own book (S-08, FR-007): what must change so an author can delete their book while others' reads survive"
tags: [research, codebase, book, soft-delete, BookRepository, BookVoter, ReadBook]
status: complete
last_updated: 2026-11-19
last_updated_by: Claude (Sonnet 5.5)
---

# Research: Soft-delete own book

**Date**: 2026-11-19
**Git Commit**: 2269f83 (working tree clean at start, only `change.md` added)
**Branch**: main
**Repository**: 10xdevs

## Research Question

`/10x-research soft-delete-own-book` (no free-form intent in `change.md`). Interpreted from roadmap S-08 / PRD FR-007: what in the codebase must change so the author of a book can delete it, the book disappears from all lists, and other users' ratings and comments are preserved?

## Summary

- **Requirement** (`context/foundation/prd.md:83`, `roadmap.md:164-175`): only the author deletes; the deleted book is invisible on every list; others' reads and comments stay; deletion does not remove data. Soft delete is stated in `shape-notes.md:114`.
- **Hard delete is not possible on this schema**: `ReadBook.book` is `onDelete: 'RESTRICT'` (`src/Entity/ReadBook.php:27-29`), and `Book.addedBy` is RESTRICT (`src/Entity/Book.php:53-55`). `book_author` / `book_tag` FKs are ON DELETE CASCADE (`migrations/Version20261103191639.php:25-28`).
- **No soft-delete mechanism exists yet** and no Doctrine filter is configured (`config/packages/doctrine.yaml` has only `schema_filter`). `Book` has a nullable `updatedAt` precedent for a new nullable `deletedAt` (`src/Entity/Book.php:44-46`).
- **Filter points** (this inspected set, `src/` only): five `BookRepository` methods already carry "S-08 filter goes here" docblocks, plus two controller `find($id)` calls and one `ReadBookRepository` query that joins Book. See table below.
- **Pattern to copy**: the `book_read_remove` route (POST, `IsGranted('ROLE_USER')`, CSRF via `isCsrfTokenValid`, flash, redirect) combined with the edit route's `denyAccessUnlessGranted(BookVoter::EDIT, $book)`. `BookVoter` currently supports only `EDIT`.
- **Product decisions not settled by docs**: see Open Questions (duplicate warning vs deleted book, behaviour of read-remove on a deleted book, redirect target, account-deletion).

## Detailed Findings

### Schema and entity

- `Book` indexes: non-unique `IDX_BOOK_ISBN`, `IDX_BOOK_CREATED_AT_ID`; no unique constraint on ISBN or title (reported by the query-mapping agent from `src/Entity/Book.php:14-17`; duplicates are warn-only), so a deleted book's ISBN cannot block a new insert.
- `ReadBook` has `UNIQ_READ_BOOK_USER_BOOK(user_id, book_id)`; reads of a soft-deleted book simply stay in the table.
- Timestamps precedent: `updatedAt` nullable `\DateTimeImmutable` set via `Clock::get()->now()` in `BookManager::update()`.
- Migration precedent: `migrations/Version20261117201022.php` (`isTransactional(): false`, `abortIf` non-MariaDB, comment on ALGORITHM=INSTANT, nullable, no backfill). Lesson `context/foundation/lessons.md` ("State engine requirements and table rebuilds") requires the engine version and lock behaviour in the comment. Next version must be > 20261117201022.
- Schema is verified with `doctrine:schema:validate`, so mapping and migration must match exactly (including any index).

### Read paths that must exclude deleted books (verified by reading source)

| Location | Today | Needs |
|---|---|---|
| `BookRepository::search` `:39` | no deleted condition | filter on `b` |
| `BookRepository::findDuplicates` `:81-115` | `andWhere(orX(...))` at `:108`; `excludeId` at `:110` | decision (see Open Questions); if excluded, separate `andWhere` |
| `BookRepository::findByTag` `:124-138` | no condition on `b` beyond tag EXISTS | filter on `b` |
| `BookRepository::findRecommendationCandidates` `:153-175` | joins `ReadBook r` on `b`; no deleted condition | filter on `b` |
| `BookRepository::findForDetail` `:180-190` | `b.id = :id` only | filter, so show returns 404 (`BookController.php:55`) and mark-read returns 404 (`ReadBookController.php:27`) |
| `BookController::edit` `:124` | `find($id)` | filtered finder or deleted check, otherwise a deleted book is editable by URL |
| `ReadBookController::remove` `:61` | `find($id)` ("only the existence of the book matters") | policy choice |
| `ReadBookRepository::countByCategorySince` `:89-111` | `join('r.book','b')` grouped by category, no deleted condition | policy choice: a deleted book would still make a category "recently read" for recommendations |
| `ReadBookRepository::findCommentsForBook` `:64` | by `r.book = :book`, no Book join | none, once the book is resolved through a filtered path |

Other inspected paths with no Book query: `BookManager`, `DuplicateFinder` (delegates to `findDuplicates`), `RecommendationService` (delegates to the two repository methods above), `HomeController`, `TagController::show` (resolves Tag by slug only; a tag whose books are all deleted renders an empty list via `findByTag`).

Controllers resolve books by `int $id` through repositories (no ParamConverter/MapEntity found in the inspected controllers), so route resolution adds no separate leak path. `findByUserIndexedByBookId` (`ReadBookRepository.php:34`) takes already-loaded books.

Correction recorded: one sub-report gave `countByCategorySince` at `:280-302` and `findCommentsForBook` at `:255-272`; the file is 112 lines and the real anchors are `:89` and `:64`.

### Authorization, CSRF, UI

- `BookVoter` (`src/Security/BookVoter.php`): `EDIT = 'BOOK_EDIT'`, supports only that attribute; owner rule is `getAddedBy()->getId() === user id`. Add a `DELETE` attribute or reuse the rule.
- Edit route (`BookController.php:120-125`): `IsGranted('ROLE_USER')`, `find` → 404, `denyAccessUnlessGranted`. Read-remove route (`ReadBookController.php:56-67`): POST only, `isCsrfTokenValid('read_remove', ...)`, bad token returns a bare 400. `csrf.yaml` lists only `submit, authenticate, logout` as stateless, so a new token id such as `book_delete` is a normal session token needing no config.
- `security.yaml` has `access_control` commented out; authorization is attributes + voters.
- Template: `templates/book/show.html.twig:8-13` header with edit button under `is_granted('BOOK_EDIT', book)`; delete form precedent at `:63-66` (raw `<form method="post">`, hidden `_token`, `onsubmit="return confirm(...)"`). Translations are Polish only in `translations/messages.pl.yaml`; `reading.error.invalid_token` is read-specific, so a book-specific key is needed.
- AGENTS.md UI contract: token-scan grep must print nothing for `templates/book/*`; reuse `templates/book/_*.html.twig` partials.

### Service layer

- `BookManager` is `final` with `readonly` deps; `update()` uses `wrapInTransaction` and `Clock::get()->now()`. A `delete(Book)` method fits here (lesson: service over trait). `ReadingManager::remove` (`:58-70`) is the transaction precedent.

### Tests and gates

- Test plan risks #1, #4, #5 (`test-plan.md:39,42,43,51,54,55`); Phase 2 "not started" (`:66`). Required proofs: non-owner refused with DB unchanged; anonymous redirected; after delete, others' reads/comments exist and the book is gone from lists, recommendations and duplicate check; integration with real DB, no mocked repository.
- Reusable patterns: `tests/Controller/BookEditControllerTest.php` (MockClock, `UserManager::create`, hand-built `BookManager`, CSRF 400 cases, "another user's read record unchanged" `:212`); `PrivacyMatrixTest.php` (three users, `actAs`, 403 tests at `:177-202`); `BookRepositoryTest.php` (extend `search`, `findDuplicates`, `findByTag`, `findRecommendationCandidates` groups); `DuplicateFinderTest.php`, `RecommendationServiceTest.php`, `BookVoterTest.php`, `BookMappingTest.php`.
- Pitfall (`test-plan.md:120`): `tokenFromSession()` in `ReadBookControllerTest` wipes all read records; do not use it for two-user tests.
- DB reset: DAMA rollback per test; tests need MariaDB (`docker compose up -d database`).
- Gates: PHPUnit (fails on deprecations/notices), PHPStan (`.githooks/pre-push`, CI), `lint:container`, `lint:twig`, `lint:yaml`, `doctrine:schema:validate`. Claude hooks lint per edit and run PHPStan/PHPUnit at stop (`.claude/settings.json`).
- E2E (`e2e/books.spec.js`) is local only and has no edit coverage; a delete button visible to the owner may change existing `detail-*` baselines if the fixture reader owns the book (unverified which fixture book belongs to the reader).

## Code References

- `src/Entity/Book.php:41-46,53-55` - timestamps, nullable `updatedAt`, `addedBy` RESTRICT
- `src/Entity/ReadBook.php:14-15,27-29` - unique per user+book, book FK RESTRICT
- `src/Repository/BookRepository.php:39,81,124,153,180` - query methods needing the filter
- `src/Repository/ReadBookRepository.php:64,89` - comments query, category count joining Book
- `src/Controller/BookController.php:55,120-125,151-153` - show, edit route, flash+redirect
- `src/Controller/ReadBookController.php:27,56-67` - read, remove (CSRF pattern)
- `src/Security/BookVoter.php` - EDIT only
- `src/Service/BookManager.php` - `update()` transaction/clock pattern
- `templates/book/show.html.twig:8-13,63-66` - edit button, delete-form precedent
- `migrations/Version20261117201022.php` - latest migration pattern

## Architecture Insights

- The repo intentionally centralises the future filter in `BookRepository` (docblocks name S-08); no Doctrine SQL filter precedent exists. Per-query conditions are explicit but must cover every path (nine listed above); a Doctrine filter would cover all queries at once but is a new mechanism with its own risk (it would also hide deleted books from `find()`, which matters for the policy decisions below). The plan should choose one.
- Authorization is voter-based, CSRF is per-route session tokens, destructive actions use POST + `confirm()`.

## Historical Context (from prior changes)

- `context/archive/2026-11-17-edit-own-book-entry/` - plan "NOT doing" list defers deletion to S-08 and orphan Author/Tag cleanup; impl-review F1 (controller conflict test) set the pattern of controller tests per branch; F3 parked `cleanup-orphan-authors-tags` (`roadmap.md:244`). With soft delete, book-author/book-tag links remain, so orphaning is not triggered by delete itself (inference from the join-table mapping, not tested).
- `roadmap.md:164-175` - S-08 risk: deleted book must not affect recommendations; unknown on account deletion (non-blocking).

## Related Research

- None found under `context/changes/**/research.md` for this topic in the inspected set (only `bootstrap-verification` is active).

## Open Questions

1. Should a deleted book still trigger the duplicate warning, or may its ISBN/title be re-added? AGENTS.md and `test-plan.md:55` say the filter must be in `findDuplicates`; confirm that means "deleted books do not warn".
2. Should `book_read_remove` on a deleted book 404 or still let a user remove their own read record? And should reads of deleted books count in `countByCategorySince`?
3. Redirect target after delete (`book_index` is the natural choice; show page would 404) and whether a confirmation beyond JS `confirm()` is wanted (FR-006 requires confirmation for edit; FR-007 is silent).
4. Per-query conditions vs a Doctrine SQL filter.
5. Index on `deleted_at`: optional at household scale.
6. Account deletion and book fate (roadmap non-blocking unknown).
7. Whether the owner-visible delete button changes e2e `detail-*` baselines (not verified).
