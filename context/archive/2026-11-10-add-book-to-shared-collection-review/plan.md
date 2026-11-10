# Add book to shared collection Implementation Plan

> Review change for the already shipped S-02 `add-book-to-shared-collection` (archived at `context/archive/2026-11-03-add-book-to-shared-collection/`). This plan mirrors the archived plan with its completion state and commit SHAs kept as the record of what was built, so `/10x-impl-review` can compare the code with it; the phases are not to be implemented again.

## Overview

Roadmap item S-02 (`add-book-to-shared-collection`): a signed-in household member adds a book to the shared collection through a validated Polish form. A book has a title, one or more authors, an ISBN, a publication year, a category from a fixed list, up to five tags and an optional description. Authors and tags are their own entities, reused across books. This slice introduces the `Book` data model that S-03 to S-08 and the recommendation rule build on, so the schema is the main thing to get right.

## Current State Analysis

- `src/Entity/` holds only `User`; `migrations/` holds two migrations (`user` table, `sessions` table). `config/packages/doctrine.yaml` already hides `sessions` through `schema_filter`, so `doctrine:migrations:diff` stays clean.
- `symfony/form`, `symfony/validator` and `symfony/intl` are installed, but no form type, validator constraint class or `validators` translation exists yet. `config/packages/twig.yaml` has no `form_themes`, so forms would render unstyled.
- `config/packages/security.yaml` has `form_login` on the `main` firewall and an empty `access_control` list (anonymous browsing is public by PRD). A protected route must therefore declare its own requirement.
- `templates/base.html.twig` already has a Bootstrap navbar with an overridable `navbar_items` block, a flash area that maps flash type `error` to `danger` (other types become `alert-<type>`) and a `body_content` block. `translations/messages.pl.yaml` holds all UI strings under stable keys; `default_locale` is `pl`.
- Test pattern: `WebTestCase` plus `DELETE FROM` in `setUp` (see `tests/Controller/SecurityControllerTest.php:25`). Existing tests run `DELETE FROM \`user\``, which will fail once `book.added_by` references `user` and a book row is left behind.
- The roadmap marks S-02 `blocked` on three product questions; they are decided in this plan (see Implementation Approach).

## Desired End State

A signed-in user opens `/books/new`, fills in title, authors (comma-separated), ISBN, publication year, category, tags (comma-separated) and an optional description, submits, and returns to the empty form with a success message; the book, its authors and tags exist in the database, linked to the user who added it. Anonymous visitors requesting `/books/new` are sent to `/login`. Invalid input (missing title, authors or year, bad ISBN, year out of range, more than five tags, over-long description) shows Polish error messages next to the fields and saves nothing. Migrations reach production through `release_command`.

Verify: `php bin/phpunit` green locally and in CI; adding a book works on `https://10xdevs-home-library.fly.dev/books/new`.

### Key Discoveries:

- `templates/base.html.twig:46-54` — flash messages already render for any type, so `success` works with no layout change.
- `tests/Controller/SecurityControllerTest.php:25` — tests clear tables with raw `DELETE FROM` in `setUp`; the new foreign key from `book` to `user` would break them, which Phase 1 solves with transaction-per-test isolation (see Critical Implementation Details).
- `config/packages/csrf.yaml` — forms use stateless CSRF with token id `submit`; no extra setup is needed for the form.
- `AGENTS.md` hard rules: `declare(strict_types=1)`, migrations only (never `schema:update`), makers with `--no-interaction`, tests only on the local MariaDB, `main` protected, deploy only by PR merge.

## What We're NOT Doing

- Notes. FR-004's single "krótka notatka" on the book is dropped from S-02: notes are reader notes (many per book, added by readers, each marked public or private by its author) and belong with rating in S-03 / FR-009. They are separate from the description, which stays a plain field on the book. Phase 3 records the S-02 decisions in the PRD and parks the notes design as a roadmap note for S-03; the PRD wording on notes is rewritten when S-03 is planned.
- Duplicate warning (S-06), browse/search/list page (S-05), edit (S-07), delete and `deleted_at` (S-08), ratings and read status (S-03), recommendations (S-04). After a successful add the user returns to the form; nothing in the UI lists the saved books until S-05.
- A book detail page, cover images, e-mail or external lookups by ISBN (PRD Non-Goal).
- Category administration screen or database-managed categories; the category list lives in code.
- Per-author pages, tag autocomplete or tag filtering; authors and tags are plain reusable names.
- A unique constraint on the ISBN: the same ISBN may legitimately be added twice (S-06 only warns).

## Implementation Approach

Three phases, each ending in a committable state: (1) schema and entities, (2) the form, service and route on top of it, (3) docs and the production check. Product decisions made during planning:

- **Category:** a string-backed PHP enum (`App\Enum\Category`) with a fixed list of English-named cases (Polish labels via translations), stored as a plain string column and shown as a select.
- **"Rok":** publication year, required.
- **Description:** an optional free-text description of the book, a field of the entry filled in by the user adding it and visible to everyone (anonymous visitors included, once S-05 lists books). It is separate from notes.
- **Authors and tags:** `Author` and `Tag` entities, each many-to-many with `Book`, entered as comma-separated text and matched to existing rows ignoring case. An author has a first name and a last name; each comma-separated entry is typed "Imię Nazwisko" and split at the last space (last word = last name, the rest = first name). Both parts are required: an entry with a single word such as "Homer" is rejected with a validation message asking for first name and last name. Surnames containing a space ("Le Guin") therefore split wrongly ("Ursula K. Le" / "Guin"); this is accepted for now.
- **ISBN:** required, normalised and validated as ISBN-10 or ISBN-13; indexed but not unique.
- **Ownership:** `added_by` (required) and `created_at` on the book now; `deleted_at` arrives with S-08.
- **Placement of logic:** an input DTO carries the form data and validation; one `BookManager` service turns it into entities, so the form and the tests exercise the same code.

## Critical Implementation Details

- **Test isolation.** `book.added_by` is a foreign key to `user`, and the existing tests run `DELETE FROM \`user\``, which would fail if any test left book rows behind. Phase 1 adds `dama/doctrine-test-bundle` so every test runs inside a transaction that is rolled back afterwards; no test (existing or new) then needs its own cleanup, and later slices with their own foreign keys stay safe. Keep the existing `DELETE FROM` lines as they are (they now roll back too). Tests must not run DDL or commit explicitly, because MariaDB commits implicitly on DDL.
- **Case-insensitive reuse of authors and tags.** Uniqueness of the author (`first_name` + `last_name`) and `tag.name` relies on a case-insensitive collation, so the migration declares `utf8mb4_unicode_ci` explicitly on those columns instead of trusting the database default (the cyberfolks default is unknown). This collation is also accent-insensitive: names or tags that differ only in diacritics (for example "a" and "ą") are treated as the same row and keep the first spelling. `BookManager` must look names up through the database rather than in PHP, and a test must prove that "Stanisław Lem" and "stanisław lem" resolve to one author row.
- **Normalise before validating.** The ISBN is stripped of spaces and hyphens (and upper-cased) before the `Isbn` constraint runs, and the stored value is the normalised one. Tag and author lists are trimmed, whitespace-collapsed and de-duplicated ignoring case before counting, so "a, A , a" counts as one name.

## Phase 1: Book data model

### Overview

Add the entities, the category enum and one reviewed migration so the schema exists before any UI uses it.

### Changes Required:

#### 1. Category enum

**File**: `src/Enum/Category.php`

**Intent**: One fixed list of categories that the recommendation rule (S-04) can count reliably.

**Contract**: `App\Enum\Category` string-backed enum with English cases and values: `Novel` (`novel`), `Crime` (`crime`), `Fantasy` (`fantasy`), `NonFiction` (`non-fiction`), `Biography` (`biography`), `History` (`history`), `Science` (`science`), `SelfHelp` (`self-help`), `Children` (`children`), `Poetry` (`poetry`), `Other` (`other`), and a method returning the translation key `book.category.<value>` (for example `book.category.fantasy`) for the label. The Polish labels go into `translations/messages.pl.yaml` in Phase 2.

#### 2. Entities and repositories

**File**: `src/Entity/Book.php`, `src/Entity/Author.php`, `src/Entity/Tag.php`, `src/Repository/BookRepository.php`, `src/Repository/AuthorRepository.php`, `src/Repository/TagRepository.php`

**Intent**: Persist books with their authors, tags and the user who added them. Use `make:entity --no-interaction` where it helps and review the output; follow `User.php` for attribute style and `declare(strict_types=1)`.

**Contract**:
- `Book`: `id`; `title` (string 255, required); `isbn` (string 13, required, plain index `IDX_BOOK_ISBN`); `publicationYear` (smallint, required); `description` (text, nullable); `category` (`Category` enum column, required); `createdAt` (`DateTimeImmutable`, set in the constructor); `addedBy` (`ManyToOne` to `User`, required, `onDelete: RESTRICT`); `authors` and `tags` (`ManyToMany` owning side to `Author` / `Tag`, join tables `book_author` and `book_tag`, both with cascade-free defaults). Helpers to add authors/tags without duplicates.
- `Author`: `id`, `firstName` (string 100, required, not blank), `lastName` (string 150, required, not blank), both columns with explicit collation `utf8mb4_unicode_ci`, unique composite index `UNIQ_AUTHOR_NAME` on (`first_name`, `last_name`); a `getFullName()` helper returns "firstName lastName" without a stray space. `Tag`: `id`, `name` (string 30, collation `utf8mb4_unicode_ci`, unique index `UNIQ_TAG_NAME`).
- `AuthorRepository` exposes `findOneByFullName(string $firstName, string $lastName)` and `TagRepository` `findOneByName(string $name)` (both relying on the collation for case-insensitivity). `BookRepository` is the plain generated repository.

#### 3. Migration

**File**: `migrations/Version<timestamp>.php`

**Intent**: Create `book`, `author`, `tag`, `book_author` and `book_tag` through `make:migration --no-interaction`, then review by hand.

**Contract**: Reversible `down()` that drops the join tables first, then `book`, `author`, `tag`. Foreign keys from `book_author` / `book_tag` cascade on delete of the join row's parent only as Doctrine generates them; `book.added_by` is `RESTRICT`. `utf8mb4` tables, InnoDB; the author name and tag name columns carry the explicit `utf8mb4_unicode_ci` collation. `schema:validate` and `migrations:diff` report no changes afterwards (check that the explicit collation does not cause drift).

#### 4. Test isolation

**File**: `composer.json`, `composer.lock`, `config/bundles.php`, `phpunit.dist.xml`

**Intent**: Roll back every test's database changes automatically so tables with foreign keys to `user` never break unrelated tests.

**Contract**: `composer require --dev dama/doctrine-test-bundle` (resolves to `^8.7` here); the bundle is enabled for the `test` environment only and its PHPUnit extension (`DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension`) is registered in `phpunit.dist.xml`. Existing tests stay unchanged and must still pass; run the whole suite before adding the book tests.

#### 5. Tests

**File**: `tests/Entity/BookMappingTest.php`

**Intent**: Prove the mapping works against the real migrated database, not just in memory.

**Contract**: Kernel test on the `_test` database that persists a `Book` with two authors and two tags added by a `User`, clears the entity manager, reloads it and asserts the associations, enum value and `createdAt`. Another case asserts that a second `Author` with the same first and last name differing only in case violates the unique index. No manual cleanup is needed (transaction rollback per test).

### Success Criteria:

#### Automated Verification:

- Migration applies to an empty test DB and mapping is in sync: `php bin/console doctrine:database:create --env=test --if-not-exists && php bin/console doctrine:migrations:migrate --env=test -n && php bin/console doctrine:schema:validate --env=test`
- Migration diff is clean: `php bin/console doctrine:migrations:diff --env=test` reports no changes
- Tests pass: `php bin/phpunit`
- Container lints: `php bin/console lint:container`

#### Manual Verification:

- On the local dev DB, `php bin/console doctrine:migrations:migrate` creates the five tables and `down` (`doctrine:migrations:execute --down`) removes them again
- After this phase's commit, open a draft PR from `feat/add-book-to-shared-collection` into `main`; the CI `test` job passes with the new migration. Later phases are pushed to the same PR

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 2: Add-book form and flow

### Overview

Add the login-protected `/books/new` page, its form and the service that turns validated input into entities, with Polish messages and a navbar link.

### Changes Required:

#### 1. Input DTO and name-list parsing

**File**: `src/Dto/NewBook.php`, `src/Service/NameList.php`

**Intent**: Keep form data and validation out of the entities and make the comma-separated parsing one reusable, testable unit.

**Contract**: `NewBook` is a plain object with public typed fields `title`, `authors` (string), `isbn`, `publicationYear` (`?int`, so an empty form field reaches validation), `category` (`?Category`), `tags` (string), `description` (`?string`), validated with attributes: title not blank and at most 255; authors yield at least one entry, and every entry has both a first name and a last name after splitting (a one-word entry is an error), with a first name of at most 100 and a last name of at most 150 characters; ISBN not blank, normalised in its setter or by a form data transformer, and valid via `Assert\Isbn` (ISBN-10 or 13); year required and between 1000 and current year + 1 (use a callback for the upper bound because attribute arguments are constant); category required; tags yield at most five names, each at most 30 characters; description optional, trimmed (empty becomes null) and at most 2000 characters. `NameList::parse(string): list<string>` splits on commas, trims, collapses inner whitespace, drops empties and de-duplicates ignoring case, keeping the first spelling. `NameList::splitAuthor(string): array{firstName: string, lastName: string}` splits one entry at its last space ("Jan Maria Kowalski" gives "Jan Maria" / "Kowalski"; "Homer" gives "" / "Homer", which the DTO validation then rejects because the first name is empty).

#### 2. Add-book service

**File**: `src/Service/BookManager.php`

**Intent**: The one place that creates a `Book` from validated input, shared by the controller and tests.

**Contract**: `BookManager::add(NewBook $input, User $user): Book` parses authors and tags with `NameList`, splits each author with `NameList::splitAuthor`, finds or creates each `Author`/`Tag` through their repositories, builds the `Book` with `addedBy` set to `$user`, persists and flushes in one transaction, and returns it. It does no validation of its own (the DTO owns that).

#### 3. Form type and form theme

**File**: `src/Form/BookType.php`, `config/packages/twig.yaml`

**Intent**: Render the DTO as a Bootstrap form.

**Contract**: `BookType` (data class `NewBook`) with `title` (text), `authors` (text, help text "imię i nazwisko, oddziel autorów przecinkami"), `isbn` (text), `publicationYear` (integer, required), `category` (`EnumType` for `Category`, choice labels from the enum's translation keys, placeholder), `tags` (text, optional, help text "do pięciu, oddzielone przecinkami") and `description` (textarea, optional), plus a submit button. `twig.yaml` gains `form_themes: ['bootstrap_5_layout.html.twig']`. CSRF stays on (default token id `submit`).

#### 4. Controller, template and navigation

**File**: `src/Controller/BookController.php`, `templates/book/new.html.twig`, `templates/base.html.twig`

**Intent**: Expose the form to signed-in users only and link to it from the navbar.

**Contract**: Route `book_new` at `/books/new` (GET, POST), restricted with `#[IsGranted('ROLE_USER')]` so anonymous visitors are redirected to `app_login`. On a valid submit call `BookManager::add`, add a `success` flash, and redirect to `book_new` (POST/redirect/GET); an invalid submit re-renders with HTTP 400 and field errors. `AbstractController::render()` sets 422 on its own when an invalid form is passed to it, so the controller passes an explicit `Response` with status 400 to `render()` to keep the requested code. The template extends `base.html.twig`, sets the page title and renders the form. In `base.html.twig` the signed-in branch of `navbar_items` gains a link to `book_new`.

#### 5. Polish strings

**File**: `translations/messages.pl.yaml`, `translations/validators.pl.yaml`

**Intent**: All visible text in Polish under stable keys.

**Contract**: `messages.pl.yaml` gets `book.new.title`, `book.new.heading`, field labels, help texts, the submit label, `book.flash.added`, the nav label and `book.category.<value>` for every enum case. `validators.pl.yaml` holds the custom constraint messages (authors required, too many tags, tag too long, invalid year); built-in constraints (not blank, ISBN, length) already ship Polish translations.

#### 6. Session handler lock mode (found during manual verification)

**File**: `config/services.yaml`

**Intent**: Let Doctrine transactions work in dev and prod. The `PdoSessionHandler` shares the native PDO connection with Doctrine, and its default transactional lock opens a PDO transaction on it, so any `wrapInTransaction` in a request failed with "There is already an active transaction". The tests could not catch this because they use `mock_file` sessions.

**Contract**: The `session.handler.pdo` options get `lock_mode: LOCK_NONE`. Tradeoff: two simultaneous requests from one browser can overwrite each other's session writes, which is acceptable for 2-4 users.

#### 7. Tests

**File**: `tests/Service/NameListTest.php`, `tests/Service/BookManagerTest.php`, `tests/Controller/BookControllerTest.php`

**Intent**: Cover parsing, persistence and every access and failure path of the form.

**Contract**:
- `NameListTest`: trims, collapses spaces, drops empties, de-duplicates ignoring case, keeps first spelling.
- `BookManagerTest` (DB): stores authors, tags and `addedBy`; "Stanisław Lem" then "stanisław lem" on a second book reuses one author row stored as first name "Stanisław" and last name "Lem"; both name parts are stored non-empty; ISBN stored in normalised form; no tags is allowed; description stored when given and null when blank.
- `BookControllerTest`: anonymous `GET /books/new` redirects to `/login`; signed-in `GET` returns 200 with the form and navbar link; valid `POST` redirects back to `/books/new`, shows the success message and persists the row with the user as `addedBy`; each invalid case (empty title, no authors, an author given as a single word, invalid ISBN, missing year, year out of range, six tags, tag over 30 characters, description over 2000 characters) returns 400, shows an error and persists nothing; a missing or invalid CSRF token is rejected. `setUp` creates a user through `UserManager` and logs in with `loginUser`; no manual cleanup is needed (transaction rollback per test).

### Success Criteria:

#### Automated Verification:

- Tests pass: `php bin/phpunit`
- Container lints: `php bin/console lint:container`
- Route registered: `php bin/console debug:router book_new`
- Schema still clean: `php bin/console doctrine:schema:validate --env=test`

#### Manual Verification:

- In the browser on the local dev server, sign in, open "Dodaj książkę", add a book with two authors, three tags and a description, and see the success message with the form cleared; check the `book`, `author`, `tag`, `book_author` and `book_tag` rows in the dev DB
- Submit the form empty, without a year, and with a bad ISBN: Polish error messages appear next to the fields, nothing is saved, and entered values are kept
- Add a second book with an author spelled in a different case: no duplicate author row appears
- Signed out, `/books/new` redirects to the login page; the navbar link shows only when signed in
- The form is usable at narrow width and styled by Bootstrap

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 3: Docs sync and production check

### Overview

Bring the repository docs in line with what was built and decided, then ship through the normal PR-to-deploy path and prove it on Fly.

### Changes Required:

#### 1. Repository docs

**File**: `AGENTS.md`

**Intent**: Keep the agent guide current for the next slices.

**Contract**: Update the `src/` structure line to mention `Book`, `Author`, `Tag`, `Category`, `BookManager` and the add-book controller; note that the session handler runs with `LOCK_NONE` because it shares the Doctrine connection; add a short rule that categories live in `App\Enum\Category` (fixed list, change by code and deploy) and that authors and tags are reusable entities matched ignoring case.

#### 2. PRD and roadmap decisions

**File**: `context/foundation/prd.md`, `context/foundation/roadmap.md`

**Intent**: Record the product decisions so later slices start from them and the "blocked" state of S-02 is explained as resolved.

**Contract**:
- PRD: FR-004 drops the book note, adds an optional description (visible to everyone, separate from notes) and states authors and tags as entities; "rok" is the publication year and is required; the category is chosen from a fixed list; ISBN is required. Open Questions 1, 2, 3 and 6 are marked resolved. FR-009, Access Control and Guardrails are NOT rewritten here: they still say notes are personal until S-03 is planned. Keep the edit minimal and mark the version bump.
- Roadmap: S-02 `Unknowns` marked resolved, Open Roadmap Questions 1, 2, 3 and 6 marked resolved, a new Open Roadmap Question records the owner's notes decision (reader notes ride with rating in S-03: many per book, each public or private by its author, which contradicts the PRD's 'notes are personal' rule until the PRD is updated), S-03's entry points to it, and the S-02 Backlog Handoff row is set to ready. The Status flips to `done` later through `/10x-archive`.

#### 3. Build and release check

**File**: `Dockerfile`, `fly.toml` (verify only; edit only if a problem is found)

**Intent**: Confirm the image builds and that `release_command` applies the new migration.

**Contract**: No planned change. If `docker build` or the release fails, fix the cause within this phase.

### Success Criteria:

#### Automated Verification:

- Full test suite passes on the final tree: `php bin/phpunit`
- Production image builds from a clean checkout: `docker build -t home-library-check .`
- Docs reference the new model: `AGENTS.md` mentions `App\Enum\Category`

#### Manual Verification:

- The draft PR is marked ready, CI `test` job is green, PR merged by the human, CI deploy finishes and `fly releases --image` shows a new complete release
- `fly ssh console -a 10xdevs-home-library -C "php bin/console doctrine:migrations:status"` shows the book migration as executed
- On `https://10xdevs-home-library.fly.dev/books/new` a signed-in user adds a book and sees the success message, and adding a second book with the same author in a different case reuses one author row (proves the production collation); signed out the page redirects to login; `/healthz` still returns 200

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Testing Strategy

### Unit Tests:

- `NameList`: trimming, whitespace collapsing, empty entries, case-insensitive de-duplication, and the author split (two words, three words, single word).
- `BookManager`: persistence of authors, tags and owner, reuse of existing names ignoring case, ISBN normalisation.
- Entity mapping on the real migrated database.

### Integration Tests:

- Functional add-book flow: access control, success redirect and flash, each validation failure (title, authors, ISBN, year, tag count and length), CSRF.
- CI runs the whole suite on a freshly created and migrated `app_test` database, now including the book migration.

### Manual Testing Steps:

1. Sign in locally, add a book with several authors and tags, confirm the rows and the flash.
2. Trigger each validation error and confirm nothing is saved.
3. Add a book with an existing author in different case and confirm one author row.
4. Repeat the add on production after merge.

## Performance Considerations

An add is one request with a handful of indexed lookups and inserts against the remote cyberfolks DB (about 30 ms per query from `fra`); with authors and tags this is roughly ten queries, well under a second for 2-4 users. The 2-second budget applies to the recommendations page (S-04).

## Migration Notes

One migration reaches production through `release_command`; it only creates tables and has a reversible `down()`. Migrations do not roll back automatically on Fly, so a failed release is fixed forward or the previous image is redeployed with the tables left in place. The production database has no book data yet. Take a `mysqldump` first per `AGENTS.md` if backups exist by then (roadmap Open Question 10).

## References

- Roadmap item: `context/foundation/roadmap.md` (S-02; Open Roadmap Questions 1, 2, 3 and 6 resolved here)
- Requirements: `context/foundation/prd.md` (FR-004, FR-005, FR-009, US-01, Access Control)
- Stack and deploy: `context/foundation/tech-stack.md`, `context/deployment/deploy-plan.md`
- Existing patterns: `src/Entity/User.php`, `migrations/Version20261102125426.php`, `src/Controller/SecurityController.php`, `tests/Controller/SecurityControllerTest.php:25`, `templates/base.html.twig:46`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Book data model

#### Automated

- [x] 1.1 Migration applies to an empty test DB and mapping is in sync (`doctrine:schema:validate`) — 26d9612
- [x] 1.2 Migration diff is clean (`doctrine:migrations:diff` reports no changes) — 26d9612
- [x] 1.3 Tests pass: `php bin/phpunit` — 26d9612
- [x] 1.4 Container lints: `php bin/console lint:container` — 26d9612

#### Manual

- [x] 1.5 Local dev DB migrate creates the five tables and `down` removes them — 26d9612
- [x] 1.6 Draft PR opened into `main` and the CI `test` job passes with the new migration — 26dcf8c

### Phase 2: Add-book form and flow

#### Automated

- [x] 2.1 Tests pass: `php bin/phpunit` — 26dcf8c
- [x] 2.2 Container lints: `php bin/console lint:container` — 26dcf8c
- [x] 2.3 Route `book_new` is registered (`debug:router book_new`) — 26dcf8c
- [x] 2.4 Schema stays clean (`doctrine:schema:validate --env=test`) — 26dcf8c

#### Manual

- [x] 2.5 Signed-in user adds a book with two authors, three tags and a description; success message shown and rows exist in the dev DB — 26dcf8c
- [x] 2.6 Empty submit, missing year and bad ISBN show Polish errors, save nothing and keep entered values — 26dcf8c
- [x] 2.7 An author spelled in a different case reuses the existing author row — 26dcf8c
- [x] 2.8 Signed out `/books/new` redirects to login and the navbar link shows only when signed in — 26dcf8c
- [x] 2.9 The form is usable and Bootstrap-styled at narrow width — 26dcf8c

### Phase 3: Docs sync and production check

#### Automated

- [x] 3.1 Full test suite passes on the final tree — ba1cee1
- [x] 3.2 `docker build` succeeds from the clean tree — ba1cee1
- [x] 3.3 `AGENTS.md` mentions `App\Enum\Category` — ba1cee1

#### Manual

- [x] 3.4 PR green, merged by the human, deploy finished, `fly releases --image` shows a new complete release — ba1cee1
- [x] 3.5 `doctrine:migrations:status` on Fly shows the book migration executed — ba1cee1
- [x] 3.6 A book is added on production, a same author in different case reuses one author row, signed-out access redirects to login and `/healthz` is 200 — ba1cee1
