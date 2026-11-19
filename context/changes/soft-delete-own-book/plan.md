# Soft-delete own book Implementation Plan

## Overview

The author of a book entry can delete it (FR-007, roadmap S-08). Deletion is a soft delete: a nullable `deleted_at` on `Book`. A deleted book disappears from the list, tag pages, recommendations, the duplicate check and the detail/edit/mark-read routes. Other users' read records and comments stay in the database untouched.

## Current State Analysis

- No soft-delete mechanism exists. `ReadBook.book` and `Book.addedBy` are `onDelete: 'RESTRICT'` (`src/Entity/ReadBook.php:27-29`, `src/Entity/Book.php:53-55`), so a hard delete of a book with reads is impossible by design.
- `Book` has a nullable `updatedAt` precedent (`src/Entity/Book.php:44-46`) and the migration precedent `migrations/Version20261117201022.php`.
- Five `BookRepository` methods already carry "S-08 filter goes here" docblocks: `search` `:39`, `findDuplicates` `:81`, `findByTag` `:124`, `findRecommendationCandidates` `:153`, `findForDetail` `:180`.
- Two controllers load a book with a plain `find($id)`: `BookController::edit` `:124` and `ReadBookController::remove` `:61`. `ReadBookController::read` `:27` uses `findForDetail`.
- `ReadBookRepository::countByCategorySince` (`:89`) joins `Book` and would keep counting reads of a deleted book toward "recently read" categories.
- `BookVoter` supports only `EDIT` (`src/Security/BookVoter.php`); the owner rule is `addedBy` id equals user id.
- `book_read_remove` (`ReadBookController.php:56-67`) is the POST + CSRF + flash + redirect pattern; `show.html.twig:63-66` is the `confirm()` form precedent.

## Desired End State

An owner sees a "Usuń" button on their book's detail page. After confirming, the book is soft-deleted and the user lands on the book list with a success flash. Anyone else gets 403 (signed in) or a login redirect (anonymous) on the delete route. The deleted book is absent from the list, tag pages, recommendations and duplicate warnings, and its detail, edit and read routes return 404. Other users' read records and comments for it still exist in the database.

Verified by: PHPUnit (repository, service, controller, voter, mapping tests), PHPStan, `lint:container`, `lint:twig`, `lint:yaml`, `doctrine:schema:validate`, plus a manual walk-through.

### Key Discoveries:

- Filter lives in repositories by design (docblocks at `BookRepository.php:35,73,120,148,178`); there is no Doctrine SQL filter in `config/packages/doctrine.yaml`.
- `book_read` already 404s once `findForDetail` filters; `book_read_remove` and `book_edit` need a new filtered finder.
- No unique constraint on ISBN or title (`Book.php:14-17`), so ignoring deleted books in `findDuplicates` allows re-adding the same book.
- `csrf.yaml` lists only `submit, authenticate, logout` as stateless; a new `book_delete` token is a normal session token and needs no config.
- Lesson "State engine requirements and table rebuilds" (`context/foundation/lessons.md`): the migration comment must state the minimum engine version and lock behaviour, with `abortIf`.

## What We're NOT Doing

- Undelete / restore, admin purge or hard delete of deleted rows.
- A Doctrine SQL filter (explicit per-query conditions chosen instead).
- A separate confirmation page (JS `confirm()` chosen).
- Deciding what happens to books when their author's account is deleted (roadmap non-blocking unknown).
- Orphan author/tag cleanup (parked as `cleanup-orphan-authors-tags`, `roadmap.md:244`); soft delete keeps the author/tag links.
- Changing `ReadingManager` internals; the controller 404 guard is the protection.
- New e2e scenarios or screenshot baseline updates (test-plan §7: e2e is local, not a gate).

## Implementation Approach

Three phases, each leaving the app green. Phase 1 adds the column, the domain method and the authorization rule with no user-visible route. Phase 2 makes every read path ignore deleted books and adds the integration proofs (so the delete button is never reachable before the filter exists). Phase 3 adds the route, the button, translations and the controller-level tests.

Decisions from planning: deleted books are ignored by the duplicate check; reads of deleted books are hidden everywhere (not counted for recommendations, read-remove returns 404); explicit per-query filtering; delete is POST with CSRF and JS `confirm()`, redirecting to `book_index` with a flash.

## Phase 1: Schema and delete domain

### Overview

Add the `deleted_at` column, the entity accessors, `BookManager::delete` and the voter rule. No route or UI yet.

### Changes Required:

#### 1. Book entity

**File**: `src/Entity/Book.php`

**Intent**: Record when a book was soft-deleted, mirroring `updatedAt`.

**Contract**: `#[ORM\Column(nullable: true)] private ?\DateTimeImmutable $deletedAt = null;` with a comment "Null means the entry is active." Accessors `getDeletedAt(): ?\DateTimeImmutable`, `setDeletedAt(?\DateTimeImmutable): static`, and `isDeleted(): bool`. Class-level `#[ORM\Index(name: 'IDX_BOOK_DELETED_AT', columns: ['deleted_at'])]` next to the existing two indexes (`Book.php:16-17`); it must match the migration exactly.

#### 2. Migration

**File**: `migrations/Version20261119100000.php` (version greater than `Version20261117201022`)

**Intent**: Add the nullable column and its index, following `Version20261117201022` exactly.

**Contract**: `ALTER TABLE book ADD deleted_at DATETIME DEFAULT NULL` and `CREATE INDEX IDX_BOOK_DELETED_AT ON book (deleted_at)`; `isTransactional(): false`; `abortIf` on non-MariaDB; comments state MariaDB 10.3+ instant ADD COLUMN (no rebuild, no long lock, existing rows stay NULL = active), that the index build is an in-place secondary-index build (no table copy; writes allowed, briefly heavier) on MariaDB 10.11, and that `down()` drops the index then the column, which rebuilds the table (`ALGORITHM=COPY`) and blocks writes briefly.

#### 3. Delete service method

**File**: `src/Service/BookManager.php`

**Intent**: One place that soft-deletes a book.

**Contract**: `delete(Book $book): void` sets `deletedAt` to `Clock::get()->now()` inside `wrapInTransaction` and flushes. No unique-violation handling needed. Does not touch authors, tags or reads.

#### 4. Voter

**File**: `src/Security/BookVoter.php`

**Intent**: Same owner rule for delete as for edit.

**Contract**: add `public const DELETE = 'BOOK_DELETE';`; `supports()` accepts `EDIT` or `DELETE` for a `Book`; `voteOnAttribute` unchanged (owner check). Update the class docblock to say "change or delete".

#### 5. Tests

**Files**: `tests/Entity/BookMappingTest.php`, `tests/Security/BookVoterTest.php`, `tests/Service/BookManagerTest.php`

**Intent**: Prove mapping, authorization and delete behaviour.

**Contract**: mapping test covers `deletedAt` (nullable, null by default, persisted); voter test: owner granted, other user denied, anonymous denied for `DELETE`; manager test (fixed MockClock): `delete()` stamps `deletedAt` with the clock time, leaves other fields, authors, tags and other users' read records unchanged.

### Success Criteria:

#### Automated Verification:

- Migration applies and schema matches: `php bin/console doctrine:migrations:migrate --no-interaction` then `php bin/console doctrine:schema:validate`
- Phase 1 tests pass: `php bin/phpunit tests/Entity/BookMappingTest.php tests/Security/BookVoterTest.php tests/Service/BookManagerTest.php`
- Static analysis passes: `php bin/console cache:warmup --env=dev` then `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- Container lint passes: `php bin/console lint:container`
- Migration `down()` then `up()` runs cleanly and the schema stays valid: `php bin/console doctrine:migrations:execute --down "DoctrineMigrations\Version20261119100000" --no-interaction`, then `php bin/console doctrine:migrations:execute --up "DoctrineMigrations\Version20261119100000" --no-interaction`, then `php bin/console doctrine:schema:validate`

**Implementation Note**: After completing this phase and all automated verification passes, pause here for confirmation from the human before proceeding to the next phase.

---

## Phase 2: Hide deleted books everywhere

### Overview

Every path that reads a book ignores deleted ones. This covers lists, tag pages, recommendations, duplicates, detail, edit, mark-read and read-remove.

### Changes Required:

#### 1. BookRepository filters

**File**: `src/Repository/BookRepository.php`

**Intent**: Exclude deleted books in the five methods flagged for S-08 and provide a filtered single-book finder.

**Contract**: add `b.deletedAt IS NULL` as an `andWhere` on the outer `b` in `search`, `findByTag`, `findRecommendationCandidates` and `findForDetail`. In `findDuplicates`, add it as a separate `andWhere` outside the `orX` at `:108` (independent of `excludeId`). New `findActive(int $id): ?Book` returning the book only when not deleted. Replace the "S-08 goes here" docblock notes with a short statement of the rule.

#### 2. Controllers

**Files**: `src/Controller/BookController.php` (`edit`, `:124`), `src/Controller/ReadBookController.php` (`remove`, `:61`)

**Intent**: A deleted book is a 404 on edit and read-remove.

**Contract**: replace `$bookRepository->find($id)` with `$bookRepository->findActive($id)`; the `?? throw createNotFoundException()` stays. `ReadBookController::read` and `BookController::show` are covered by the `findForDetail` filter.

#### 3. Recommendation category counting

**File**: `src/Repository/ReadBookRepository.php` (`countByCategorySince`, `:89`)

**Intent**: Reads of deleted books must not influence which categories are recommended.

**Contract**: add `b.deletedAt IS NULL` to the query that already joins `r.book b`. `findCommentsForBook` and `findByUserIndexedByBookId` need no change (reachable only through an already resolved, non-deleted book).

#### 4. Tests

**Files**: `tests/Controller/DeletedBookVisibilityTest.php` (new), `tests/Repository/BookRepositoryTest.php`, `tests/Repository/ReadBookRepositoryTest.php`, `tests/Service/DuplicateFinderTest.php`, `tests/Service/RecommendationServiceTest.php`, `tests/Controller/BookEditControllerTest.php`, `tests/Controller/ReadBookControllerTest.php`, `tests/Controller/BookControllerTest.php` (or the tag/list test files where they fit)

**Intent**: Prove, against the real database with no mocked repositories, that a deleted book is invisible and that others' data survives (test-plan risks #4 and #5).

**Contract**: for each of `search`, `findByTag`, `findRecommendationCandidates`, `findDuplicates` (ISBN match and title+author match), `findForDetail`, `findActive` and `countByCategorySince`: one case where an identical active book is returned and the deleted one is not. Recommendation test: a deleted, well-rated book is never recommended and its reads do not make its category "recent". Duplicate test: re-adding a deleted book's ISBN gives no warning. Controllers: GET show, GET/POST edit, POST read and POST read-remove on a deleted book return 404. `DeletedBookVisibilityTest` end-to-end: soft-delete a book with a raw SQL `UPDATE` (so the test does not depend on `BookManager`), then assert it is absent from `/books`, its tag page and the recommendations and that its detail URL returns 404. Survival: after the book is soft-deleted, other users' `ReadBook` rows (rating, comment) still exist unchanged. Test-protects check: temporarily remove a filter and confirm a test fails, then restore with `git checkout --`.

### Success Criteria:

#### Automated Verification:

- Phase 2 tests pass: `php bin/phpunit tests/Repository tests/Service tests/Controller`
- Whole suite passes: `php bin/phpunit`
- Static analysis passes: `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- A book soft-deleted with a raw SQL `UPDATE book SET deleted_at = ...` (not through `BookManager`) is absent from `/books`, its tag page and the recommendations, and its `/books/{id}` URL returns 404: `php bin/phpunit tests/Controller/DeletedBookVisibilityTest.php`

#### Manual Verification:

- Removing a temporary filter makes at least one new test fail (test protects the behaviour)

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: Delete route and UI

### Overview

The owner can delete from the detail page: POST with CSRF, confirmation dialog, flash and redirect to the list.

### Changes Required:

#### 1. Delete route

**File**: `src/Controller/BookController.php`

**Intent**: Authorised, CSRF-checked soft delete.

**Contract**: `#[Route('/books/{id}/delete', name: 'book_delete', requirements: ['id' => '\d+'], methods: ['POST'])]` with `#[IsGranted('ROLE_USER')]`. Order: `findActive` (404) → `denyAccessUnlessGranted(BookVoter::DELETE, $book)` → CSRF `isCsrfTokenValid('book_delete', ...)`; an invalid or missing token returns a bare 400 response with the translated `book.error.invalid_token` (as in `ReadBookController.php:65-67`). Success: `BookManager::delete`, `addFlash('success', book.flash.deleted)`, `redirectToRoute('book_index')`.

#### 2. Detail template

**File**: `templates/book/show.html.twig`

**Intent**: Owner-only delete button next to the edit button, using the existing `confirm()` form pattern (`:63-66`).

**Contract**: inside the header row (`:8-13`), group the actions in one wrapper; show a `<form method="post" action="{{ path('book_delete', {id: book.id}) }}">` with hidden `_token` = `csrf_token('book_delete')`, `onsubmit` confirm using `book.delete.confirm`, and a `btn btn-outline-danger btn-sm` submit, all under `{% if is_granted('BOOK_DELETE', book) %}`. Bootstrap classes only (UI contract: the token-scan grep must print nothing for `templates/book/*`).

#### 3. Translations

**File**: `translations/messages.pl.yaml`

**Intent**: Polish strings for the new UI.

**Contract**: `book.show.delete` (button), `book.delete.confirm` (states that the book disappears from all lists while other users' ratings and comments are kept), `book.flash.deleted`, `book.error.invalid_token` (page outdated, refresh and retry).

#### 4. Controller tests

**Files**: `tests/Controller/BookDeleteControllerTest.php` (new, modelled on `BookEditControllerTest.php`), `tests/Controller/PrivacyMatrixTest.php`

**Intent**: Prove ownership, CSRF and data survival through the real route (test-plan risks #1 and #4).

**Contract**: owner POST with a token taken from the rendered button deletes (302 to `/books`, flash shown, `deletedAt` set), and the book is absent from the list and 404 on its URL; other users' read records and comments are unchanged after `$em->clear()`; signed-in non-owner gets 403 and the book is untouched; anonymous is redirected to `/login` and the book is untouched; missing and invalid CSRF token give 400 and the book is untouched; unknown id and an already deleted id give 404; a swapped foreign book id from a valid owner session is refused. Privacy matrix: the delete button is shown only to the owner. Do not use `ReadBookControllerTest::tokenFromSession()` for two-user cases (it wipes all read records).

### Success Criteria:

#### Automated Verification:

- Controller tests pass: `php bin/phpunit tests/Controller`
- Whole suite passes: `php bin/phpunit`
- Static analysis passes: `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G`
- Twig lint passes: `php bin/console lint:twig templates`
- YAML lint passes: `php bin/console lint:yaml translations config`
- Container lint passes: `php bin/console lint:container`
- Schema valid: `php bin/console doctrine:schema:validate`
- UI token-scan grep from AGENTS.md prints nothing for `templates/book/*`

#### Manual Verification:

- As the owner: the "Usuń" button appears on the detail page; cancelling the dialog does nothing; confirming returns to the list with the success flash and the book is gone
- As another user: no delete button on someone else's book; a second user's rating and comment on the deleted book still exist in the database
- Anonymous visitor sees no delete button
- Optional: `npm run e2e` locally; if owner-visible `detail-*` baselines changed, do not update them without explaining the visual change

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful.

---

## Testing Strategy

### Unit Tests:

- `BookVoter` DELETE for owner, other user, anonymous
- `BookManager::delete` with a fixed clock; nothing else mutated
- `Book` mapping of `deletedAt`

### Integration Tests:

- Each filtered query returns an active book and hides the identical deleted one (real DB, no mocked repository)
- Others' `ReadBook` rows survive a delete; recommendations and the duplicate check ignore the deleted book
- Controller: owner, non-owner 403, anonymous redirect, bad CSRF 400, 404 cases, DB state asserted after `$em->clear()`

### Manual Testing Steps:

1. Sign in as the owner, open the book, delete it, confirm the flash and the list
2. Open the old URL: 404 page
3. Sign in as another user: the deleted book is not listed or recommended, adding the same ISBN gives no duplicate warning
4. Check the database: the book row has `deleted_at`, other users' read records remain

## Performance Considerations

An extra `deleted_at IS NULL` condition on household-scale tables, backed by the `IDX_BOOK_DELETED_AT` index (requested; the optimizer may still prefer other indexes on small tables).

## Migration Notes

Instant nullable ADD COLUMN on MariaDB 10.3+ plus an in-place index build (production and CI run 10.11); existing rows become active. `down()` rebuilds the `book` table briefly. Deploy order: migration, then code (the CI deploy workflow already runs migrations).

## References

- Related research: `context/changes/soft-delete-own-book/research.md`
- PRD FR-007: `context/foundation/prd.md:83`; roadmap S-08: `context/foundation/roadmap.md:164-175`
- Test plan risks #1, #4, #5: `context/foundation/test-plan.md:51,54,55`
- Delete route pattern: `src/Controller/ReadBookController.php:56-67`; edit route: `src/Controller/BookController.php:120-125`
- Migration pattern: `migrations/Version20261117201022.php`
- Prior change: `context/archive/2026-11-17-edit-own-book-entry/`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Schema and delete domain

#### Automated

- [x] 1.1 Migration applies and schema matches: `php bin/console doctrine:migrations:migrate --no-interaction` then `php bin/console doctrine:schema:validate` — 8920638
- [x] 1.2 Phase 1 tests pass: `php bin/phpunit tests/Entity/BookMappingTest.php tests/Security/BookVoterTest.php tests/Service/BookManagerTest.php` — 8920638
- [x] 1.3 Static analysis passes: `php bin/console cache:warmup --env=dev` then `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — 8920638
- [x] 1.4 Container lint passes: `php bin/console lint:container` — 8920638
- [x] 1.5 Migration `down()` then `up()` runs cleanly and the schema stays valid: `php bin/console doctrine:migrations:execute --down "DoctrineMigrations\Version20261119100000" --no-interaction`, then `php bin/console doctrine:migrations:execute --up "DoctrineMigrations\Version20261119100000" --no-interaction`, then `php bin/console doctrine:schema:validate` — 8920638

### Phase 2: Hide deleted books everywhere

#### Automated

- [x] 2.1 Phase 2 tests pass: `php bin/phpunit tests/Repository tests/Service tests/Controller` — 0674aa3
- [x] 2.2 Whole suite passes: `php bin/phpunit` — 0674aa3
- [x] 2.3 Static analysis passes: `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — 0674aa3
- [x] 2.4 A book soft-deleted with a raw SQL `UPDATE book SET deleted_at = ...` (not through `BookManager`) is absent from `/books`, its tag page and the recommendations, and its `/books/{id}` URL returns 404: `php bin/phpunit tests/Controller/DeletedBookVisibilityTest.php` — 0674aa3

#### Manual

- [x] 2.5 Removing a temporary filter makes at least one new test fail (test protects the behaviour) — 0674aa3

### Phase 3: Delete route and UI

#### Automated

- [x] 3.1 Controller tests pass: `php bin/phpunit tests/Controller` — 26c7c3f
- [x] 3.2 Whole suite passes: `php bin/phpunit` — 26c7c3f
- [x] 3.3 Static analysis passes: `php vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — 26c7c3f
- [x] 3.4 Twig lint passes: `php bin/console lint:twig templates` — 26c7c3f
- [x] 3.5 YAML lint passes: `php bin/console lint:yaml translations config` — 26c7c3f
- [x] 3.6 Container lint passes: `php bin/console lint:container` — 26c7c3f
- [x] 3.7 Schema valid: `php bin/console doctrine:schema:validate` — 26c7c3f
- [x] 3.8 UI token-scan grep from AGENTS.md prints nothing for `templates/book/*` — 26c7c3f

#### Manual

- [x] 3.9 As the owner: the "Usuń" button appears, cancelling the dialog does nothing, confirming returns to the list with the success flash and the book is gone — 26c7c3f
- [x] 3.10 As another user: no delete button on someone else's book; a second user's rating and comment on the deleted book still exist in the database — 26c7c3f
- [x] 3.11 Anonymous visitor sees no delete button — 26c7c3f
- [x] 3.12 Optional: `npm run e2e` locally; owner-visible `detail-*` baselines are not updated without explaining the visual change — 26c7c3f
