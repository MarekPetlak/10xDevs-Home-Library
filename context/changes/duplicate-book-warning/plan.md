# Duplicate book warning Implementation Plan

## Overview

Before a new book is saved on `/books/new`, the app checks the shared collection for a duplicate (FR-005, roadmap S-06). A book is a duplicate when its ISBN matches (ISBN-10 and ISBN-13 of one book count as the same) or when its title matches (case and surrounding whitespace ignored) and at least one author is shared. If a duplicate exists, nothing is saved: the filled form is shown again with a warning listing up to three matches (link and reason each) and an extra "Add anyway" button. The adding user decides. No schema change, no migration.

## Current State Analysis

- `BookController::new` (`src/Controller/BookController.php:75`) validates the form and calls `BookManager::add` straight away; there is no step between validation and save.
- `Book::title` uses collation `utf8mb4_uca1400_as_ci` (case-insensitive, accent-sensitive) (`src/Entity/Book.php:25`); ISBN is stored normalised by the `BookType` model transformer (no spaces or hyphens, upper-case) and has index `IDX_BOOK_ISBN` (`src/Entity/Book.php:16`, `src/Form/BookType.php:67`). The stored ISBN may be ISBN-10 or ISBN-13 (`Assert\Isbn` accepts both, `src/Dto/NewBook.php:36`).
- Authors are reusable entities matched by `AuthorRepository::findOneByFullName` (`src/Repository/AuthorRepository.php:24`); `NameList::parse` / `splitAuthor` turn the typed list into first/last names.
- `BookRepository::search` documents itself as the single place for a future soft-delete filter (S-08) (`src/Repository/BookRepository.php:29`). The new duplicate query must be filtered the same way once S-08 lands.
- Invalid and conflict states render with an explicit HTTP 400 so `render()` does not switch to 422 (`BookController.php:96`).
- UI contract (AGENTS.md): Bootstrap token overrides only in `assets/styles/app.css`, shared markup in `templates/book/_*.html.twig`, no colour literals or `style=`, visual gate via `npm run e2e` baselines.

## Desired End State

A signed-in user entering a book that already exists (same ISBN, in either ISBN form, or same title with a shared author) sees the form again with a warning block: each match shows title, authors, year, a link to its detail page and the reason ("same ISBN" / "same title and author"). Pressing "Add anyway" saves the book; pressing the normal submit again re-warns. A book with no duplicate saves exactly as today.

Verify: `bin/phpunit` green, `npm run e2e` baselines updated and reviewed, manual run of the three scenarios below.

### Key Discoveries:

- The duplicate rule can be a pure read query on existing columns: ISBN via `IDX_BOOK_ISBN`, title via the `_as_ci` column, authors via the `book_author` join. No migration, so the lessons about collations and migrations are not triggered.
- ISBN-10 to ISBN-13 conversion is the only new domain logic (prefix `978`, recompute check digit; ISBN-13 with prefix `979` has no ISBN-10 form). Query both forms with `IN (...)` because the stored value is whichever form the adder typed.
- `lessons.md`: shared behaviour goes into a small injectable service with its own test (not a trait), and behaviour is decided from typed values, never message text. Both shape the design below.

## What We're NOT Doing

- No schema change, no unique index on ISBN or title (the user may add a duplicate on purpose).
- No normalisation of whitespace inside a title (only surrounding whitespace, as AGENTS.md states); no accent-insensitive matching ("José" and "Jose" stay different).
- No fuzzy or partial title matching, no matching on year, category or tags.
- No change to edit-book (S-07) or soft delete (S-08); the query is only prepared for the S-08 filter.
- No JavaScript, modal or JSON endpoint; no new route.
- No blocking of duplicates; the warning never prevents saving.

## Implementation Approach

Three layers, bottom up: a matching service backed by one repository query, then the controller/form flow that uses it, then the warning markup. The "confirmed" decision is carried by which submit button was pressed (a second `SubmitType` field on `BookType`), so there is no hidden state or session stash.

## Phase 1: Matching core

### Overview

Pure logic and one query, fully unit/repository tested, not yet wired to any route.

### Changes Required:

#### 1. ISBN form conversion

**File**: `src/Service/IsbnForms.php` (new), `tests/Service/IsbnFormsTest.php` (new)

**Intent**: Given a normalised ISBN string, return every form under which the same book may be stored (the string itself plus its ISBN-10 or ISBN-13 counterpart).

**Contract**: `IsbnForms::of(string $isbn): list<string>`, always contains the input first. ISBN-10 gets its `978`-prefixed ISBN-13 (check digit recomputed); ISBN-13 starting `978` gets its ISBN-10 (check digit `0-9` or `X`); ISBN-13 starting `979` returns only itself. Input is assumed already validated and normalised (spaces/hyphens stripped, upper-case).

#### 2. Duplicate query

**File**: `src/Repository/BookRepository.php`

**Intent**: Find existing books that match by ISBN or by title plus a shared author, newest first, limited, loading authors in the same query.

**Contract**: `findDuplicates(string $title, list<Author> $authors, list<string> $isbns, int $limit = 3): list<Book>`. A book matches when `b.isbn IN (:isbns)` OR (`b.title = :title` AND EXISTS shared author id). `$authors` empty means the title rule cannot match. Title compared as given after `trim()` (collation handles case). Order `createdAt DESC, id DESC`. Carry a comment that the soft-delete filter of S-08 must be added here too.

#### 3. Matching service

**File**: `src/Service/DuplicateFinder.php` (new), `src/Dto/DuplicateMatch.php` (new)

**Intent**: Turn a validated `NewBook` into a list of matches that say why each one matched, so the template needs no logic.

**Contract**: `DuplicateFinder::find(NewBook $input): list<DuplicateMatch>`. Resolves each typed author through `AuthorRepository::findOneByFullName` (skips unknown authors, which cannot be shared), calls `findDuplicates`, then labels each result. `DuplicateMatch` is a readonly DTO `{Book $book, bool $sameIsbn, bool $sameTitleAndAuthor}` (a book can match both ways). Reason is a typed flag, not text.

### Success Criteria:

#### Automated Verification:

- ISBN conversion tests pass (10→13, 13→10 incl. `X` check digit, 979 has no ISBN-10): `bin/phpunit tests/Service/IsbnFormsTest.php`
- Repository tests pass (ISBN same form and cross form, title case-insensitive, shared author with extra/missing co-author, same title but no shared author does not match, different accent does not match, limit and newest-first order): `bin/phpunit tests/Repository/BookRepositoryTest.php`
- Service test passes (flags set correctly, unknown author does not match, both flags together): `bin/phpunit tests/Service/DuplicateFinderTest.php`
- Whole suite passes: `bin/phpunit`

#### Manual Verification:

- Reviewer confirms the query is a read-only EXISTS/IN query and the S-08 comment is present.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 2: Controller and form flow

### Overview

Wire the finder into `/books/new`: warn instead of saving, and allow an explicit confirmed save.

### Changes Required:

#### 1. Confirm button on the form

**File**: `src/Form/BookType.php`

**Intent**: Add a second submit, "Add anyway", that records the user's decision without any hidden field.

**Contract**: extra `SubmitType` field named `confirm_duplicate` with label `book.form.confirm_duplicate`. It is rendered only when the template shows the warning (the template decides by the `duplicates` variable). The existing `submit` field is unchanged.

#### 2. Controller flow

**File**: `src/Controller/BookController.php`

**Intent**: After the form is valid, run the finder unless the confirm button was pressed; when matches exist, re-render the form with them and HTTP 400, and do not call `BookManager::add`.

**Contract**: in `new()`, `$confirmed = $form->get('confirm_duplicate')->isClicked()`. If `!$confirmed` and `DuplicateFinder::find()` is non-empty, render `book/new.html.twig` with `form` and `duplicates`, status 400. Otherwise unchanged path (add, flash, redirect to `book_new`). Invalid forms never reach the finder. The decision comes from the clicked button, not from message text.

#### 3. Translations

**File**: `translations/messages.pl.yaml`

**Intent**: Polish texts for the warning heading, the two reasons, the confirm button and the hint that the decision is the user's, under `book.duplicate.*` and `book.form.confirm_duplicate`.

**Contract**: keys `book.duplicate.heading`, `book.duplicate.reason.isbn`, `book.duplicate.reason.title_author`, `book.duplicate.hint`, `book.form.confirm_duplicate`.

#### 4. Controller tests

**File**: `tests/Controller/BookControllerTest.php`

**Intent**: Pin the flow end to end.

**Contract**: scenarios: duplicate by ISBN warns, nothing saved, status 400, input preserved; duplicate by title and shared author warns; ISBN-10 entered against stored ISBN-13 warns; clicking "Add anyway" saves and redirects with the success flash; non-duplicate saves with no warning; the same duplicate submitted with the normal button warns again; invalid form shows validation errors and no warning; anonymous still redirected.

### Success Criteria:

#### Automated Verification:

- Controller tests pass: `bin/phpunit tests/Controller/BookControllerTest.php`
- Whole suite passes: `bin/phpunit`

#### Manual Verification:

- In the browser, adding an existing book shows the warning with the typed values kept, and "Add anyway" saves it.
- Adding a book with a new ISBN and title shows no warning and behaves as before.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 3: Warning UI and docs

### Overview

Present the warning according to the UI contract and lock it with a screenshot baseline.

### Changes Required:

#### 1. Shared warning partial

**File**: `templates/book/_duplicate_warning.html.twig` (new), `templates/book/new.html.twig`

**Intent**: Render the warning above the form: a Bootstrap warning alert with a list of matches (title, authors, year, link to `book_show` opening in a new tab, reason). The "Add anyway" button sits with the form actions.

**Contract**: partial takes `duplicates` (list of `DuplicateMatch`); reuses the existing icon `bi:exclamation-triangle`; no colour literals, `style=` or `text-muted`; passes the scan in AGENTS.md "UI contract". Included in `new.html.twig` only when `duplicates` is defined and non-empty. Check the form theme `templates/form/theme.html.twig` so the second button matches the first.

#### 2. Playwright baseline

**File**: `e2e/books.spec.js`, `e2e/__screenshots__/`

**Intent**: Add a "new book: duplicate warning" state (desktop and 390 px) using an existing fixture book, and update baselines only after reviewing them.

**Contract**: submit `/books/new` with a catalogue fixture's ISBN, screenshot `new-duplicate.png`; follow the existing `shot` options and states.

#### 3. Project docs

**File**: `AGENTS.md`

**Intent**: Record where duplicate checking lives so the next agent reuses it.

**Contract**: extend the Project Structure list (`DuplicateFinder`, `IsbnForms`, `DuplicateMatch`, `_duplicate_warning`) and keep the existing duplicate-check hard rule; add that ISBN-10/13 are matched across forms and that the S-08 soft-delete filter must also be added to `BookRepository::findDuplicates`.

### Success Criteria:

#### Automated Verification:

- UI-contract scan prints nothing: `grep -nE '#[0-9a-fA-F]{3,8}\b|style=|rgb\(|text-muted|class="[^"]*\[' templates/book/index.html.twig templates/book/_*.html.twig`
- Whole suite passes: `bin/phpunit`
- Screenshot gate passes after reviewed baseline update: `npm run e2e`

#### Manual Verification:

- Warning reads clearly at desktop and 390 px; links open the existing book in a new tab.
- Keyboard: the "Add anyway" button is reachable and shows a visible focus ring.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Testing Strategy

### Unit Tests:

- `IsbnForms`: 10→13, 13→10, check digit `X`, 979 prefix, already-normalised input.
- `DuplicateFinder`: flags per rule, unknown author, both rules at once.

### Integration Tests:

- `BookRepository::findDuplicates` against the test database (rolled-back transactions): case, shared author, extra co-author, accent difference, limit, order.
- `BookControllerTest`: the eight flow scenarios listed in Phase 2.

### Manual Testing Steps:

1. Add "Dziady" with ISBN 978-83-7327-189-0, then add it again: warning, then "Add anyway" saves.
2. Add the same title and one shared author with a different ISBN: warning with reason "same title and author".
3. Enter the ISBN-10 form of an existing ISBN-13: warning with reason "same ISBN".

## Performance Considerations

At most 10 000 books (roadmap): the ISBN rule uses `IDX_BOOK_ISBN`; the title rule runs once per submit of a valid form, which is negligible at this size. No new index.

## Migration Notes

None. No schema change; existing data is read as is.

## References

- Requirement: `context/foundation/prd.md` FR-005 and US-01; roadmap S-06 and resolved question 6 in `context/foundation/roadmap.md`
- Similar patterns: `src/Service/BookManager.php:36` (service around Book creation), `src/Repository/BookRepository.php:37` (query style and S-08 note), `src/Controller/BookController.php:96` (explicit 400 render)
- Lessons: `context/foundation/lessons.md` (service over trait; decide from typed values)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Matching core

#### Automated

- [x] 1.1 ISBN conversion tests pass (10→13, 13→10 incl. X check digit, 979 has no ISBN-10) — 2d8dadb
- [x] 1.2 Repository tests pass (ISBN same and cross form, title case-insensitive, shared author, no shared author, accent, limit, order) — 2d8dadb
- [x] 1.3 DuplicateFinder service test passes (flags, unknown author, both flags) — 2d8dadb
- [x] 1.4 Whole suite passes: bin/phpunit — 2d8dadb

#### Manual

- [x] 1.5 Reviewer confirms the query is read-only and the S-08 comment is present — 2d8dadb

### Phase 2: Controller and form flow

#### Automated

- [x] 2.1 Controller tests pass: bin/phpunit tests/Controller/BookControllerTest.php
- [x] 2.2 Whole suite passes: bin/phpunit

#### Manual

- [x] 2.3 Adding an existing book shows the warning with input kept, and "Add anyway" saves it
- [x] 2.4 Adding a book with a new ISBN and title shows no warning and behaves as before

### Phase 3: Warning UI and docs

#### Automated

- [ ] 3.1 UI-contract scan prints nothing
- [ ] 3.2 Whole suite passes: bin/phpunit
- [ ] 3.3 Screenshot gate passes after reviewed baseline update: npm run e2e

#### Manual

- [ ] 3.4 Warning reads clearly at desktop and 390 px; links open the existing book in a new tab
- [ ] 3.5 "Add anyway" is keyboard reachable with a visible focus ring
