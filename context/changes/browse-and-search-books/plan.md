# Browse and search books Implementation Plan

## Overview

Make the book list (`/books`) and the book detail (`/books/{id}`) public (FR-003, roadmap S-05). Anonymous visitors see only catalogue data. The read badge, ratings, comments and the read form stay for logged-in users on the same pages. Then add separate title and author search fields, a single-select category dropdown and 20-per-page paging to the list.

## Current State Analysis

- `/books` and `/books/{id}` are login-only: `#[IsGranted('ROLE_USER')]` in `src/Controller/BookController.php:27` and `:40`. `access_control` in `config/packages/security.yaml:41` is empty, so the attribute is the only guard.
- Both controllers assume a user (`\assert($user instanceof User)`) and always query `ReadBookRepository` (read state, comments). The list template always renders the "Twój status" column (`templates/book/index.html.twig:21,30-36`); the detail template always renders the read state and comments blocks (`templates/book/show.html.twig:34-77`).
- `BookRepository::findAllForList()` (`src/Repository/BookRepository.php:31`) loads every book with its authors, with no filter or paging. The PRD sizes the library at up to 10,000 books.
- The navbar shows "Książki" and "Dodaj książkę" only to logged-in users (`templates/base.html.twig:33-37`). The empty-list message links to `/books/new`.
- Existing tests assert the old behaviour: `BookListControllerTest::testAnonymousIsRedirectedToLoginOnBothPages` (`tests/Controller/BookListControllerTest.php:33`) and an anonymous redirect in `tests/Controller/BookCommentsTest.php:137` must change. `testListQueryCountDoesNotGrowWithBookCount` (`:89`) must keep passing with paging.
- Author names use the explicit `utf8mb4_uca1400_as_ci` collation (`src/Entity/Author.php:20`): case-insensitive, accent-sensitive. AGENTS.md states the rule "matched ignoring case but not accents".

## Desired End State

- A logged-out visitor opens `/books`, filters by title, author and category, pages through results and opens any book. No page shows a read badge, rating, comment or comment form to them. The "Dodaj książkę" button and link are visible to them too; following it sends them to the login page, and signing in always lands on `/books` (the saved add-book target is ignored).
- A logged-in user sees the same pages with their read badge, the read form or their read record, and the comments, as today, plus the same search.
- Search and filter state is in the URL (GET), so results are shareable and survive paging.
- `/books` is the main page: opening `/` redirects to it. Logged-in users see their recommendations (up to three, as on the old home page) above the book list; logged-out visitors see only the list.

### Key Discoveries:

- `BookRepository::findAllForList()` is documented as the single place for the future soft-delete filter (S-08); the new search query must keep that role and keep the same ordering (newest first, then id).
- Searching authors through a plain join would restrict each book's loaded authors to the matching ones; the author filter must use a subquery (EXISTS) so every book still shows all its authors.
- The list query must paginate on books, not on book-author rows. Use Doctrine's `Paginator` with fetch-join collection, or paginate book ids first, and keep the SELECT count flat (the query-count test).
- Only the comment text and its author's rating are visible to logged-in users (AGENTS.md "Hard rules"); anonymous visitors get none of it.

## What We're NOT Doing

- No accent-insensitive search (the user chose contains, case-insensitive, accents significant).
- No single combined search box, no multi-category filter, no tag search, no sorting options.
- No new public route or duplicated templates: the existing list and detail are reused.
- No change to who may add, edit or delete books, and no change to `/books/new` or the read POST routes. The recommendation rule and `RecommendationService` are unchanged; only where the block is shown changes (Phase 4).
- No removal of the `home` route name: `/` keeps it as a redirect, and the navbar brand keeps using it. Login success, logout and the already-signed-in redirect move to `book_index` directly (Phase 4).
- No full-text index and no schema change other than pinning the `book.title` collation (Phase 2 §2a).
- No design-system or restyle work (that is `/10x-ui`).

## Implementation Approach

Ship the privacy boundary first and verify it, then build the query behind it, then the UI on top. Phase 1 changes only who can see what. Phase 2 is a repository method plus a small filter DTO and paging, covered by repository tests. Phase 3 adds the form, pager and states in Twig using Bootstrap classes already in use.

## Phase 1: Public access and personal-data gating

### Overview

Remove the login requirement from the list and detail pages and make everything personal conditional on a logged-in user.

### Changes Required:

#### 1. Controllers

**File**: `src/Controller/BookController.php`

**Intent**: Let anonymous users reach `index` and `show`. Query read state, read form and comments only when a `User` is logged in. Keep `new` behind `ROLE_USER`.

**Contract**: `index` and `show` have no `IsGranted`. `$this->getUser()` may be null; when it is, `readBooks` is an empty array, `readBook` is null, `comments` is an empty list and `readForm` is null. No `ReadBookRepository` call is made for anonymous visitors.

#### 2. Templates

**File**: `templates/book/index.html.twig`, `templates/book/show.html.twig`

**Intent**: Render the "Twój status" column, the read-state block and the comments block only when `app.user`. Keep the "Add book" button and the empty-state link to `/books/new` visible to everyone, anonymous included; `/books/new` stays behind `ROLE_USER`, so an anonymous click lands on the login page.

**Contract**: Condition on `app.user`, not on data being empty, so an anonymous request cannot render these blocks even if a variable were passed. The `read_state` and `comments` Twig blocks keep their names (other templates may override them).

#### 3. Navbar

**File**: `templates/base.html.twig`

**Intent**: Anonymous visitors need a way to reach the list: show the "Książki" and "Dodaj książkę" links for everyone, for consistency with the list page button.

**Contract**: `nav.book_index` and `nav.book_new` links move out of the `{% if app.user %}` branch; the user identifier and logout stay logged-in only.

#### 4. Tests

**File**: `tests/Controller/BookListControllerTest.php`, `tests/Controller/BookCommentsTest.php`

**Intent**: Replace the anonymous-redirect tests with anonymous-allowed tests that assert no personal markup, and keep logged-in assertions.

**Contract**: Anonymous GET `/books` and `/books/{id}` return 200. Another user's rating and comment text, the read badge, and the read form are absent for anonymous. They are present for the logged-in user as before. The "Add book" button (`a[href="/books/new"]`) is present for anonymous and for the logged-in user. `/books/new` still redirects anonymous to `/login`, and logging in afterwards returns to `/books/new` (assert the redirect target after login).

### Success Criteria:

#### Automated Verification:

- Controller tests pass: `bin/phpunit tests/Controller`
- Whole suite passes: `bin/phpunit`
- Anonymous `/books/{id}` response contains none of: another user's comment text, `N/5`, the read form, the read badge (asserted in `BookListControllerTest` and `BookCommentsTest`)

#### Manual Verification:

- In a private window, `/books` and a book page open without login and show only title, authors, category, ISBN, year, tags, description
- Logging in shows the same pages with the read badge, rating and comments
- "Dodaj książkę" is visible when logged out; clicking it opens the login page, and signing in lands on the add-book form

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 2: Search, category filter and paging

### Overview

A repository query that filters by title, author and category and returns one page of books, plus the request-to-filter mapping in the controller.

### Changes Required:

#### 1. Filter value object

**File**: `src/Dto/BookSearch.php` (new)

**Intent**: Carry the three filters and the page number, normalised from the query string: trimmed text, empty string treated as "no filter", unknown category value treated as "no filter", page below 1 treated as 1.

**Contract**: Immutable DTO with `?string $title`, `?string $author`, `?Category $category`, `int $page`. Built from `Request->query`; an invalid category or page never causes an error response.

#### 2. Repository query

**File**: `src/Repository/BookRepository.php`

**Intent**: Replace `findAllForList()` with a search that applies the filters and paginates 20 books per page, ordered newest first then id (as today). It stays the single place for the soft-delete filter.

**Contract**: `search(BookSearch $search): Paginator<Book>`, built as `new Paginator($query, fetchJoinCollection: true)` from `Doctrine\ORM\Tools\Pagination\Paginator` (decision in `research.md`); the controller reads items, `count()` and the page count from it. Title: `LIKE %q%` on `b.title`, case-insensitive. Author: books that have an author whose first name, last name or full name contains the text (EXISTS subquery, so all of a matching book's authors still load). Category: equality on `b.category`. Filters combine with AND. `%` and `_` in user input are escaped so they match literally. Page beyond the last page returns an empty page, not an error.

Accents stay significant: do not add an accent-insensitive collation to the title or author comparison.

#### 2a. Pin the title collation

**File**: `migrations/` (new), `src/Entity/Book.php`

**Intent**: `book.title` was created without an explicit collation (`migrations/Version20261103191639.php:20`), so it uses the server default, which is probably accent-insensitive. Pin it to the same rule as author and tag names, as the collation lesson in `context/foundation/lessons.md` requires.

**Contract**: A migration (generated with `bin/console make:migration --no-interaction`, never `doctrine:schema:update`) changes `title` to `VARCHAR(255) NOT NULL COLLATE utf8mb4_uca1400_as_ci`, with a `down()` back to the previous column definition. `Book::$title` gets `options: ['collation' => 'utf8mb4_uca1400_as_ci']` so the schema stays in sync (as `Author.php:20`). Follows `migrations/Version20261110161533.php`.

#### 3. Controller

**File**: `src/Controller/BookController.php`

**Intent**: Read the filters from the query string, call the repository and pass results, total pages, current page and the filter values to the template. `readBooks` is looked up only for the books on the page.

**Contract**: `GET /books?title=&author=&category=&page=`. An out-of-range page shows the "no results" state with a working link back to page 1.

#### 4. Tests

**File**: `tests/Repository/BookRepositoryTest.php`, `tests/Controller/BookListControllerTest.php`

**Intent**: Pin the matching rules and paging, and keep the query count flat.

**Contract**: Cases: title contains, case-insensitive, and "jose" does not match a title "José" (proves the pinned collation, not the server default); author matches by first name, last name and full name; "jose" does not match "José"; `%` and `_` are literal; category filter alone and combined with text; a matching book with two authors still lists both; 21 books give 20 then 1 on page 2; page 0, `page=abc` and unknown category fall back safely; query count identical for few and many books.

### Success Criteria:

#### Automated Verification:

- Repository and controller tests pass: `bin/phpunit tests/Repository tests/Controller`
- Whole suite passes: `bin/phpunit`
- `testListQueryCountDoesNotGrowWithBookCount` still passes with paging in place
- Migration applies cleanly and the schema is in sync: `bin/console doctrine:migrations:migrate --no-interaction` then `bin/console doctrine:schema:validate`

#### Manual Verification:

- `/books?title=sol` finds "Solaris"; `/books?author=lem` finds Lem's books; `/books?category=poetry` lists only poetry
- `/books?title=zzz` returns 200 with an empty result (no error)
- A hand-edited URL (`page=-3`, `category=bogus`) does not break the page

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 3: Search form, pager and empty states

### Overview

The visible search UI: a filter form, a pager that keeps the filters, and two distinct empty states.

### Changes Required:

#### 1. List template

**File**: `templates/book/index.html.twig`

**Intent**: Add a GET form above the table with a title field, an author field, a category `<select>` (an "all categories" option plus the `Category` enum cases, labelled with `book.category.<value>`), a submit button and a "clear" link to `/books`. Add a pager under the table that preserves the active filters. Distinguish "no books yet" from "no books match your search".

**Contract**: The form uses `method="get"` and `action="{{ path('book_index') }}"`, with fields named `title`, `author`, `category`. Every input has a visible `<label>` and the form's values are re-filled from the current filters (escaped by Twig). The pager renders links for pages 1..N via `path('book_index', filters|merge({page: n}))`, marks the current page with `aria-current="page"`, and is hidden when there is one page or none. Reuse Bootstrap's form and pagination classes already used elsewhere; no custom CSS.

#### 2. Translations

**File**: `translations/messages.pl.yaml`

**Intent**: Add Polish strings for the field labels, the submit and clear actions, "all categories", "no results" and pager labels, under the existing `book.index` group.

**Contract**: New keys under `book.index.*` only; no change to existing keys.

#### 3. Tests

**File**: `tests/Controller/BookListControllerTest.php`

**Intent**: Cover the form and pager markup.

**Contract**: The form exists for anonymous and logged-in users; submitted values are re-filled and escaped; the pager links keep `title`, `author` and `category`; a no-match search shows the "no results" message and not the "add the first book" message; the pager is absent on a single page.

### Success Criteria:

#### Automated Verification:

- Controller tests pass: `bin/phpunit tests/Controller`
- Whole suite passes: `bin/phpunit`

#### Manual Verification:

- At desktop width and one mobile width the form and pager are usable and nothing overflows
- Keyboard: Tab reaches every field, the dropdown, the buttons and each pager link, with a visible focus
- Searching, then going to page 2, keeps the filters; the "clear" link resets them
- Logged out and logged in both work; nothing personal appears logged out
- AGENTS.md is updated: the book list and detail routes are public, with personal parts shown only when logged in

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 4: `/books` as the main page with recommendations

### Overview

Turn `/books` into the landing page. `/` redirects to it, and the recommendation block moves from the old home page to the top of the list for logged-in users.

### Changes Required:

#### 1. Home route

**File**: `src/Controller/HomeController.php`, `templates/home/index.html.twig`

**Intent**: `/` no longer renders a page of its own; it redirects to `/books`. The welcome hero and greeting are dropped. The `home` route name stays for the navbar brand and old bookmarks.

**Contract**: `GET /` (route `home`) responds with a temporary redirect (302) to route `book_index`. The home template is deleted once unused; `home.recommendations.*` translation keys are reused by the list template, and unused `home.title`, `home.welcome.*` and `home.greeting` keys are removed.

#### 1a. Login and logout targets

**File**: `config/packages/security.yaml`, `src/Controller/SecurityController.php`

**Intent**: Send users straight to the main page after login and logout, so those flows do not take an extra redirect through `/`.

**Contract**: `form_login.default_target_path` and `logout.target` become `book_index`; the already-signed-in redirect at `SecurityController.php:19` uses `book_index`. `form_login.always_use_default_target_path` is `true`, so a target path saved earlier in the session (for example `/books/new` after an anonymous click on "Dodaj książkę") is ignored and every login lands on `/books`. Decided during Phase 4 verification; it supersedes the Phase 1 wording that login returns to `/books/new` (Progress 1.6 title kept as is).

#### 2. Book list controller and template

**File**: `src/Controller/BookController.php`, `templates/book/index.html.twig`

**Intent**: For a logged-in user, call `RecommendationService::forUser()` and render the existing recommendation cards (`#recommendations`, `.recommendation-card`, empty-state message) above the filter form and list. Anonymous requests make no recommendation call and render no block and no empty message.

**Contract**: `recommendations` is `null` for anonymous visitors and the service result for a `User`. The block is shown on every page of the list, filtered or not, and is not affected by the search filters. Markup ids and classes stay as they are today.

#### 3. Navbar

**File**: `templates/base.html.twig`

**Intent**: The brand link and the "Książki" link both lead to the same page; keep the brand pointing at `home` (it redirects) and leave "Książki" as is.

**Contract**: No new links; no duplicate-looking nav entries are added.

#### 3a. AGENTS.md

**File**: `AGENTS.md`

**Intent**: Record the new main-page behaviour for future agents.

**Contract**: Project Structure describes `/` as a redirect to `/books` (`book_index`) and the recommendations block as part of the list page; the home controller line is updated; the hard rule on anonymous access still reads "browse and search only".

#### 4. Tests

**File**: `tests/Controller/HomeControllerTest.php`, `tests/Controller/BookListControllerTest.php`, `tests/Controller/SecurityControllerTest.php`

**Intent**: Move the recommendation assertions to `/books`, keep a small test for the redirect, and update the login and logout tests for the new targets.

**Contract**: `GET /` redirects to `/books` for anonymous and logged-in users. The existing recommendation cases (three cards in order with link, category and rating; empty-state message; no block or message for anonymous; no other users' e-mails) run against `/books`. The Polish layout and Bootstrap checks run against `/books`. In `SecurityControllerTest`, successful login, logout and the already-signed-in redirect (lines 32-40, 81-94, 96-103) expect `/books` instead of `/`; the tests that only request `/` and read the navbar (lines 67 and 77) follow the redirect or request `/books`. The list query-count test still passes for a logged-in user (recommendation queries must not scale with the number of books).

### Success Criteria:

#### Automated Verification:

- Controller tests pass: `bin/phpunit tests/Controller`
- Whole suite passes: `bin/phpunit`
- `GET /` returns a redirect to `/books`

#### Manual Verification:

- Logging in lands on `/books` with up to three recommendations above the list
- A logged-in user with no reads sees the "rate a few books" message above the list
- A logged-out visitor sees the list only, with no recommendation block or message
- Searching or paging keeps the recommendation block at the top
- AGENTS.md describes `/` as a redirect to `/books` and the recommendations as part of the list page

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Testing Strategy

### Unit Tests:

- `BookSearch` normalisation: blank text, unknown category, bad page numbers
- Repository matching rules: contains, case-insensitive, accent-sensitive, LIKE wildcards escaped, multi-author books stay whole

### Integration Tests:

- Anonymous and logged-in requests to `/books` and `/books/{id}`: personal data appears only for the logged-in user
- Search, category filter and paging through the HTTP layer, including the query count staying flat

### Manual Testing Steps:

1. In a private window, open `/books`, search by title, by author and by category, then open a book
2. Log in and confirm read badges, ratings and comments are back
3. Add more than 20 books (or use test data) and page through while filtered

## Performance Considerations

At up to 10,000 books, `LIKE '%q%'` cannot use an index but is a bounded scan; paging caps each response at 20 books. Authors for the page are loaded without N+1 queries (fetch-joined, count of SELECTs stays constant). The home page budget (under 2 seconds) is not touched.

## Migration Notes

One migration, in Phase 2: it pins `book.title` to `utf8mb4_uca1400_as_ci` (a small ALTER on at most ~10,000 rows; it deploys through the `release_command` in `fly.toml`, and since migrations don't roll back, take a dump first per AGENTS.md). Existing logged-in bookmarks to `/books` keep working. Update the AGENTS.md "Project Structure" line about the list and detail routes.

## References

- Related research: `context/changes/browse-and-search-books/research.md` (pagination tooling; decision: keep Doctrine's built-in `Paginator`, no bundle)
- Roadmap: `context/foundation/roadmap.md` item S-05; PRD FR-003 in `context/foundation/prd.md`
- Lessons: `context/foundation/lessons.md` (collation, no branching on message text)
- Similar implementation: `src/Repository/BookRepository.php:31` (list query), `tests/Controller/BookListControllerTest.php:89` (query-count test)

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Public access and personal-data gating

#### Automated

- [x] 1.1 Controller tests pass: `bin/phpunit tests/Controller` — 0a34d55
- [x] 1.2 Whole suite passes: `bin/phpunit` — 0a34d55
- [x] 1.3 Anonymous book pages contain no other user's comment, rating, read form or read badge — 0a34d55

#### Manual

- [x] 1.4 Private window opens list and detail with catalogue data only — 0a34d55
- [x] 1.5 Logging in shows read badge, rating and comments again — 0a34d55
- [x] 1.6 "Dodaj książkę" is visible when logged out and leads through login to the add-book form — 0a34d55

### Phase 2: Search, category filter and paging

#### Automated

- [x] 2.1 Repository and controller tests pass: `bin/phpunit tests/Repository tests/Controller` — 89621d0
- [x] 2.2 Whole suite passes: `bin/phpunit` — 89621d0
- [x] 2.3 Query-count test still passes with paging — 89621d0
- [x] 2.7 Migration applies cleanly and the schema is in sync: `bin/console doctrine:migrations:migrate --no-interaction` then `bin/console doctrine:schema:validate` — 89621d0

#### Manual

- [x] 2.4 Title, author and category searches return the expected books — 89621d0
- [x] 2.5 A search with no matches returns 200 with an empty result — 89621d0
- [x] 2.6 Hand-edited URLs (`page=-3`, `category=bogus`) do not break the page — 89621d0

### Phase 3: Search form, pager and empty states

#### Automated

- [x] 3.1 Controller tests pass: `bin/phpunit tests/Controller` — a33311b
- [x] 3.2 Whole suite passes: `bin/phpunit` — a33311b

#### Manual

- [x] 3.3 Form and pager usable at desktop and one mobile width — a33311b
- [x] 3.4 Keyboard reaches every control with a visible focus — a33311b
- [x] 3.5 Filters survive paging and "clear" resets them — a33311b
- [x] 3.6 Logged-out and logged-in both work, nothing personal logged out — a33311b
- [x] 3.7 AGENTS.md describes the public list and detail routes — a33311b

### Phase 4: `/books` as the main page with recommendations

#### Automated

- [x] 4.1 Controller tests pass: `bin/phpunit tests/Controller`
- [x] 4.2 Whole suite passes: `bin/phpunit`
- [x] 4.3 `GET /` redirects to `/books`

#### Manual

- [x] 4.4 Logging in lands on `/books` with up to three recommendations above the list
- [x] 4.5 A logged-in user with no reads sees the empty recommendation message above the list
- [x] 4.6 A logged-out visitor sees no recommendation block or message
- [x] 4.7 Searching or paging keeps the recommendation block at the top
- [x] 4.8 AGENTS.md describes `/` as a redirect to `/books` and the recommendations as part of the list page
