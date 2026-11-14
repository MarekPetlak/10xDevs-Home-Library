# Filter books by tag Implementation Plan

## Overview

Add a public page `/tags/<slug>` that lists the books carrying one tag. The user reaches it by clicking a tag on a book's detail page. To get stable, URL-safe addresses, every tag gets a unique `slug` column (schema change with backfill).

## Current State Analysis

- The book list `GET /books` filters by title, author and category through `BookSearch` (`src/Dto/BookSearch.php`) and `BookRepository::search()` (`src/Repository/BookRepository.php:35`). Tags are not searchable; `browse-and-search-books` explicitly left "tag search" out of scope.
- `Tag` (`src/Entity/Tag.php`) has only `id` and `name` (max 30 chars, collation `utf8mb4_uca1400_as_ci`: case-insensitive, accent-sensitive). `Book` ↔ `Tag` is ManyToMany through `book_tag` (`src/Entity/Book.php:53`).
- Tags are created only in `BookManager::findOrCreateTag()` (`src/Service/BookManager.php:86`), looked up by exact name via `TagRepository::findOneByName()`. A lost race on a unique constraint already becomes `BookConflictException`.
- Tags render only on the detail page as plain badges (`templates/book/show.html.twig:21-25`). The list table shows no tags.
- The list table and pager live inside `templates/book/index.html.twig`; the pager hardcodes the route `book_index` (`templates/book/_pager.html.twig`).
- Because accents are significant, `zolw` and `żółw` are two different tags, yet both reduce to the ASCII slug `zolw`.

## Desired End State

- Every `Tag` has a unique, non-empty, lowercase ASCII `slug`, filled for existing tags by the migration and for new tags by `BookManager`.
- On a book's detail page each tag is a link to `/tags/<slug>`.
- `GET /tags/<slug>` is public (anonymous visitors included). It shows a heading with the tag name, the same books table and pager as `/books` (newest first, 20 per page, read badge only for signed-in users), and a link back to `/books`. An unknown slug returns 404. A page number beyond the last page behaves as on `/books`.
- Verified by `bin/phpunit`, `bin/console doctrine:schema:validate` and a manual click-through.

### Key Discoveries:

- Search is one `QueryBuilder` with an `EXISTS` subquery per author filter (`src/Repository/BookRepository.php:50`); a tag filter fits the same shape, so a matching book still lists all of its authors and tags.
- `BookSearch::filters()` feeds every pager link (`src/Dto/BookSearch.php:51`); the tag page has no query filters, so only the route and its parameters differ.
- `lessons.md` applies twice: pin collation and test case/accent variants on the unique slug column; state engine version and lock behaviour in the migration and `abortIf` on other platforms; put shared behaviour in an injectable service, not a trait.
- `testListQueryCountDoesNotGrowWithBookCount` (in `BookListControllerTest`) guards against N+1; the tag page must not break it.

## What We're NOT Doing

- No tag field or control in the `/books` search form, no `?tag=` query parameter.
- No multi-tag filtering (AND/OR) and no partial-name matching.
- No tags column or badges in the books table; tags stay visible only on the detail page.
- No tag index page (`/tags`), no tag renaming/editing, no slug editing by users.
- No change to how tags are entered when adding a book (still up to five, comma-separated).

## Implementation Approach

Three vertical steps. First give tags a slug (data layer, safe on its own). Then the tag page, which reads by slug and reuses the list table and pager via partials. Last, turn the detail-page badges into links and refresh the demo fixtures. Slug rule: `AsciiSlugger` with locale `pl`, lowercase; if the result is empty (e.g. a name of only symbols) use `tag`; if the slug is taken, append `-2`, `-3`, … until free. Existing slugs are never rewritten when other tags change.

## Phase 1: Tag slug

### Overview

Add the unique `slug` column, backfill existing tags, and generate slugs for new tags.

### Changes Required:

#### 1. Entity and schema

**File**: `src/Entity/Tag.php`, new migration in `migrations/`

**Intent**: Store a stable URL identifier per tag, unique and collation-pinned.

**Contract**: `Tag::$slug` (string, length 40 to fit 30 chars plus a `-NN` suffix, collation `utf8mb4_uca1400_as_ci`, unique constraint `UNIQ_TAG_SLUG`), with `getSlug()` / `setSlug()`. Migration: add the column nullable, backfill every existing tag in `postUp()` using the slug rule above (the rule is inlined in the migration as a frozen copy, with a comment, so later changes to app code cannot alter an old migration), then make it NOT NULL and add the unique index. The migration comment states: needs MariaDB 10.10+ (collation), rebuilds the `tag` table and blocks writes briefly; `abortIf` the platform is not MariaDB. `down()` drops the index and column.

#### 2. Slug generation service

**File**: `src/Service/TagSlugger.php` (new), `src/Repository/TagRepository.php`

**Intent**: One small injectable service that turns a tag name into a free slug, with its own unit test (no trait).

**Contract**: `TagSlugger::slugFor(string $name): string` — base slug by the rule above, then the first of `base`, `base-2`, `base-3`… for which `TagRepository::slugExists(string $slug): bool` is false. `TagRepository::findOneBySlug(string $slug): ?Tag` is added for phase 2.

#### 3. Set the slug on creation

**File**: `src/Service/BookManager.php`

**Intent**: Give every newly created tag its slug; keep the lost-race behaviour.

**Contract**: `findOrCreateTag()` calls `TagSlugger::slugFor()` before persisting. A unique violation on name or slug still surfaces as `BookConflictException` (user retries; the retry finds the slug taken and picks a suffix).

#### 4. Tests

**File**: `tests/Service/TagSluggerTest.php` (new), `tests/Service/BookManagerTest.php`, `tests/Entity/` mapping test if one covers `Tag`

**Intent**: Pin the slug rule and the accent/case behaviour required by the lesson.

**Contract**: Cases: `Science Fiction` → `science-fiction`; `Żółw` → `zolw`; `Zolw` and `żółw` stay two tags with slugs `zolw` and `zolw-2`; `Klasyka` / `klasyka` reuse one tag (one slug); name of only symbols → `tag`, then `tag-2`; adding a book creates tags with slugs.

### Success Criteria:

#### Automated Verification:

- Slug service and book manager tests pass: `bin/phpunit tests/Service`
- Migration applies cleanly and the schema is in sync: `bin/console doctrine:migrations:migrate --no-interaction` then `bin/console doctrine:schema:validate`
- Migration rolls back and re-applies: `bin/console doctrine:migrations:migrate prev --no-interaction` then `bin/console doctrine:migrations:migrate --no-interaction`
- Whole suite passes: `bin/phpunit`

#### Manual Verification:

- On a database that already contains tags (e.g. with `zolw` and `żółw`), after migrating every tag has a distinct, non-empty slug

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: Tag page

### Overview

Serve `/tags/<slug>` with the tag's books, reusing the list table and pager.

### Changes Required:

#### 1. Repository filter

**File**: `src/Repository/BookRepository.php`

**Intent**: List one page of books that have a given tag, with the same ordering, paging and eager loading as `search()`.

**Contract**: `BookRepository::findByTag(Tag $tag, int $page): Paginator<Book>` — newest first (`createdAt` DESC, `id` DESC), `PER_PAGE`, authors joined, tag condition as an `EXISTS` subquery so each listed book still carries all its authors. The docblock notes this is also a place for the soft-delete filter (S-08), like `search()`.

#### 2. Controller and route

**File**: `src/Controller/BookController.php` (or a new `TagController`, following the file's size)

**Intent**: Public route that resolves the slug and renders the list.

**Contract**: `GET /tags/{slug}`, name `tag_show`, requirement `[a-z0-9-]+`, public like `book_index`. Unknown slug → 404 via `createNotFoundException()`. Page from `?page=` normalised like `BookSearch` (below 1 or invalid → 1). Passes books, total, page, pageCount, the tag and `readBooks` (signed-in users only) to the template.

#### 3. Templates

**File**: `templates/book/_table.html.twig` (new, extracted from `index.html.twig`), `templates/book/index.html.twig`, `templates/book/_pager.html.twig`, `templates/tag/show.html.twig` (new)

**Intent**: Share the books table and pager between the two pages without duplicating markup.

**Contract**: `_table` takes `books` and `readBooks` and renders exactly today's table (markup and `data-` hooks unchanged). `_pager` gains inputs `route` and `routeParams` (default `book_index` and `search.filters` so `/books` links stay identical). `tag/show` has the heading `Książki z tagiem: <tag name>` (`<h1>`), the table, the pager, and a "Wszystkie książki" link to `/books`; the empty case cannot occur for a tag in use, but if a page beyond the last one is requested the existing "no results" pattern is reused. Page `<title>` includes the tag name.

#### 4. Translations

**File**: `translations/messages.pl.yaml`

**Intent**: Polish copy for the new page.

**Contract**: keys under a new `tag.show` block: heading with `%name%`, back link, title.

#### 5. Tests

**File**: `tests/Repository/BookRepositoryTest.php`, `tests/Controller/TagControllerTest.php` (new)

**Intent**: Cover filtering, access and paging.

**Contract**: Repository: only books with the tag are returned; a book with two tags appears once and keeps both tags' authors; paging and ordering; query count does not grow with book count. Controller: anonymous 200 with title, authors, category and no read badge/rating/comment text; signed-in user sees the read badge; unknown slug 404; slug with uppercase or invalid characters 404; `?page=abc` and `?page=0` give page 1; page past the end behaves like `/books`; 21 books paginate and the pager links stay on `/tags/<slug>?page=N`; existing `/books` pager links unchanged.

### Success Criteria:

#### Automated Verification:

- Repository and controller tests pass: `bin/phpunit tests/Repository tests/Controller`
- Whole suite passes: `bin/phpunit`
- `testListQueryCountDoesNotGrowWithBookCount` still passes, and an equivalent query-count test passes for the tag page
- Schema in sync: `bin/console doctrine:schema:validate`

#### Manual Verification:

- Opening `/tags/klasyka` in a private window (no login) lists only books tagged `klasyka`, with no personal data
- `/tags/does-not-exist` shows the normal 404 page
- `/books` list and pager look and behave exactly as before
- At desktop and one mobile width the tag page has no overflow; the back link is reachable by keyboard with a visible focus

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: Links and fixtures

### Overview

Make the detail-page tags clickable and keep the demo data consistent.

### Changes Required:

#### 1. Tag links on the detail page

**File**: `templates/book/show.html.twig`

**Intent**: Turn each tag badge into a link to its tag page without changing how it looks.

**Contract**: each badge becomes `<a class="badge text-bg-secondary ..." href="{{ path('tag_show', {slug: tag.slug}) }}">name</a>`; tag names are still auto-escaped. Styling follows `assets/styles/app.css` tokens (no new colours); the link has a visible focus.

#### 2. Fixtures

**File**: `src/DataFixtures/UiCatalogueFixtures.php`

**Intent**: Fixture tags need slugs now that the column is NOT NULL.

**Contract**: set slugs `klasyka` and `science-fiction` (or use `TagSlugger` if the fixture class can take it without a circular setup).

#### 3. Tests and docs

**File**: `tests/Controller/BookListControllerTest.php` (detail page), `AGENTS.md`

**Intent**: Pin the link and record the new route for future agents.

**Contract**: detail page of a tagged book contains one link per tag with the right `href` and a tag name containing `<` is escaped; a book without tags shows no tags row. `AGENTS.md` layout paragraph mentions `GET /tags/{slug}` (`tag_show`) and the `slug` on `Tag`.

### Success Criteria:

#### Automated Verification:

- Controller tests pass: `bin/phpunit tests/Controller`
- Whole suite passes: `bin/phpunit`
- Fixtures load without error: `bin/console doctrine:fixtures:load --env=dev --no-interaction` (only if the repo's UI fixtures are loaded this way; otherwise the existing fixture test)

#### Manual Verification:

- On a book page, clicking a tag opens its tag page; the browser Back button returns to the book
- A tag with a space or accent in its name (e.g. `science fiction`, `żółw`) opens its page from the link
- Tag links look like the previous badges and show a focus ring when tabbed to

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding.

---

## Testing Strategy

### Unit Tests:

- `TagSlugger`: transliteration, lowercasing, empty fallback, `-2`/`-3` collision suffixes, case and accent variants.

### Integration Tests:

- Repository: tag filter, no duplicates, paging, query count.
- Controller: public access, 404s, paging, anonymous privacy, pager links on both routes.
- Migration: apply, roll back, re-apply, schema validate.

### Manual Testing Steps:

1. Migrate a database with existing tags; check each has a distinct slug.
2. Click tags from a book page, signed in and in a private window.
3. Try an unknown slug and an out-of-range `?page=`.

## Performance Considerations

The tag filter is an `EXISTS` over `book_tag` (indexed through its primary key and foreign keys) plus the unique `slug` index for the lookup. At home-library scale no further optimisation is needed; the query-count test guards against N+1.

## Migration Notes

The migration adds the column nullable, backfills, then tightens it, so it works on a populated `tag` table. It needs MariaDB 10.10+ (same as the earlier collation migrations; production and CI run 10.11) and rebuilds the small `tag` table, blocking writes for moments. Take a dump first. Rolling back drops the column and index; links to `/tags/<slug>` then 404, which is harmless.

## References

- Roadmap item: `context/foundation/roadmap.md` (Change ID `filter-books-by-tag`, if tracked)
- Earlier decision to defer tag search: `context/archive/2026-11-12-browse-and-search-books/plan.md:33`
- Search pattern: `src/Repository/BookRepository.php:35`
- Tag creation: `src/Service/BookManager.php:86`
- Lessons applied: `context/foundation/lessons.md` (collation pinning, migration engine/lock notes, service over trait)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Tag slug

#### Automated

- [x] 1.1 Slug service and book manager tests pass: `bin/phpunit tests/Service` — 527bbf1
- [x] 1.2 Migration applies cleanly and the schema is in sync — 527bbf1
- [x] 1.3 Migration rolls back and re-applies — 527bbf1
- [x] 1.4 Whole suite passes: `bin/phpunit` — 527bbf1

#### Manual

- [x] 1.5 After migrating a database with existing tags (including `zolw` and `żółw`), every tag has a distinct, non-empty slug — 527bbf1

### Phase 2: Tag page

#### Automated

- [x] 2.1 Repository and controller tests pass: `bin/phpunit tests/Repository tests/Controller`
- [x] 2.2 Whole suite passes: `bin/phpunit`
- [x] 2.3 Query-count tests pass for `/books` and the tag page
- [x] 2.4 Schema in sync: `bin/console doctrine:schema:validate`

#### Manual

- [x] 2.5 `/tags/klasyka` in a private window lists only books tagged `klasyka`, with no personal data
- [x] 2.6 `/tags/does-not-exist` shows the normal 404 page
- [x] 2.7 `/books` list and pager behave exactly as before
- [x] 2.8 Tag page has no overflow at desktop and one mobile width; the back link is keyboard-reachable with a visible focus

### Phase 3: Links and fixtures

#### Automated

- [ ] 3.1 Controller tests pass: `bin/phpunit tests/Controller`
- [ ] 3.2 Whole suite passes: `bin/phpunit`
- [ ] 3.3 Fixtures load without error

#### Manual

- [ ] 3.4 Clicking a tag on a book page opens its tag page; Back returns to the book
- [ ] 3.5 Tags with a space or accent in the name (`science fiction`, `żółw`) open their pages from the link
- [ ] 3.6 Tag links look like the previous badges and show a focus ring when tabbed to
