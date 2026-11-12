# Home Recommendations Implementation Plan

## Overview

Show a logged-in user up to three recommended books on the home page (FR-010, US-01, roadmap S-04), computed by the PRD rule with our own DQL and a small `RecommendationService`. No new dependency, no schema change, no cache. Anonymous visitors never see the block.

## Current State Analysis

- Nothing recommendation-related exists in `src`, `templates`, `tests` or `config` (research.md, Summary).
- `src/Controller/HomeController.php:13-17` is an invokable public controller (`/`, route `home`) that renders `home/index.html.twig` with no variables. `templates/home/index.html.twig:9-11` already branches on `app.user` and is where the block goes.
- Data already suffices: `ReadBook` has `user`, `book`, `rating` (smallint), `readOn` (`DATE_IMMUTABLE`), unique `(user_id, book_id)` and an index on `book_id` (`src/Entity/ReadBook.php:14-15,31-35`). `Book.category` is the `App\Enum\Category` enum (`src/Entity/Book.php:36-37`). There are no aggregate (AVG / GROUP BY) queries yet.
- `ReadBookRepository::findCommentsForBook` (`src/Repository/ReadBookRepository.php:55-72`) is the precedent for `SELECT NEW App\Dto\...` projections; `src/Dto/CommentView.php` is the precedent for a `final readonly` view DTO.
- `Book` has no soft-delete field; S-08 will add one and is meant to extend `BookRepository` (`BookRepository.php:94-97,114`). Candidate selection must therefore live in exactly one `BookRepository` method.
- `MarkRead::today()` (`src/Dto/MarkRead.php:30`) is the single owner of "today" in `Europe/Warsaw`, built on `Symfony\Component\Clock\Clock`.
- Services are `final`, constructor-injected, in `src/Service` (`src/Service/ReadingManager.php:19-25`). Lesson "prefer a service over a trait" applies: the rule lives in a service, not a trait.
- Tests: PHPUnit with `dama/doctrine-test-bundle` (transaction rollback), MariaDB, data built inline (`tests/Repository/ReadBookRepositoryTest.php:26-47`); `loginUser()` is used in controller tests (`tests/Controller/BookCommentsTest.php:47`). `HomeControllerTest` only covers the anonymous page.
- Translations are in `translations/messages.pl.yaml` (`home:` key at line 3); category labels come from `book.category.translationKey` as in `templates/book/show.html.twig:19`.

## Desired End State

A logged-in user on `/` sees up to three recommendation cards. Each shows the book title linking to `book_show`, its category and its average rating with the rating count (no authors). When no recommendation can be generated, the block shows a message such as "Przeczytaj i oceń kilka książek, aby dostać rekomendacje". Anonymous visitors see neither the block nor the message. Verified by the automated tests below and by the manual steps in Phase 3.

### Key Discoveries:

- PRD rule (`context/foundation/prd.md:95,105-113`) is a deterministic ranking by average rating, not collaborative filtering; Non-Goals exclude taste similarity. Libraries found by research solve a different problem, so no dependency is added.
- A book has exactly one category, so the first and second category never yield the same book; the second-category fill needs no de-duplication.
- Books the user read are excluded by definition, so the user's own rating never enters an average.
- The home page budget is 2 s (`prd.md:100`); with 2-4 household members, 2-3 qps (up to 10 later) and at most 10,000 books, the plan needs at most three small queries and no cache (reasoning, not a measurement).

## What We're NOT Doing

- No collaborative filtering or recommender library (PRD Non-Goal).
- No Bayesian or damped average, and no minimum rating count (PRD: "bez minimalnej liczby ocen").
- Books nobody rated are never recommended; no "fill with unrated" fallback.
- No third category: the PRD stops at the second most-read category.
- No authors on the card, and no justification text per recommendation (PRD Secondary criteria, later).
- No cache, no new index (e.g. `(user_id, read_on)`), no migration, no new entity.
- No soft-delete filter: S-08 does not exist yet; this plan only keeps candidate selection in one method for S-08 to extend.
- No changes to the book list, detail page or read/rate flow.

## Implementation Approach

Three thin layers, bottom-up, each independently testable:

1. Two repository queries and one view DTO: candidate books for one category, and the user's read counts per category inside a window.
2. `RecommendationService` owns the PRD rule: window, ordering of categories, limit, fill.
3. `HomeController` and the template only present the result.

Decisions fixed during planning:

- **Ranking**: inside the user's top category; if fewer than 3, continue with the second category.
- **Candidate order**: average rating desc, then rating count desc, then title asc, then book id asc (determinism only). No minimum count.
- **Eligible books**: at least one rating from any user, not read by the current user. Books added by the current user but not read are eligible.
- **Window**: `read_on >= today - 12 months`, with today in `Europe/Warsaw` via `MarkRead::today()`; the boundary day is inclusive.
- **Category order**: most books read in the window, then most recent `read_on` in that category, then `Category` enum declaration order.

## Phase 1: Queries and DTO

### Overview

Add the data access the rule needs, with no behavior visible to users yet.

### Changes Required:

#### 1. Recommendation view DTO

**File**: `src/Dto/RecommendationView.php`

**Intent**: Carry exactly what a card shows, so no `Book` entity is hydrated and no N+1 appears when rendering.

**Contract**: `final readonly class RecommendationView` with public `int $bookId`, `string $title`, `Category $category`, `float $averageRating`, `int $ratingCount`. Follows `src/Dto/CommentView.php`. Implementer to confirm how Doctrine passes the enum and the `AVG` result into `NEW`; if the enum arrives as a string, convert with `Category::from()` in the constructor.

#### 2. Candidate query (single seam for S-08)

**File**: `src/Repository/BookRepository.php`

**Intent**: One method returns the recommendable books of a category, ranked, so S-08 later adds its soft-delete filter in one place.

**Contract**: `findRecommendationCandidates(User $user, Category $category, int $limit): list<RecommendationView>`. DQL over `Book` joined to `ReadBook` (all users' ratings): `WHERE category = :category AND book NOT IN (the user's read books)`, `GROUP BY` book, `ORDER BY AVG(rating) DESC, COUNT(rating) DESC, title ASC, id ASC`, `setMaxResults($limit)`. The inner join means books without ratings never appear. DQL or QueryBuilder only, no native SQL (project convention).

#### 3. Categories read in a window

**File**: `src/Repository/ReadBookRepository.php`

**Intent**: Give the service the user's per-category activity for the "last year" window in one query.

**Contract**: `countByCategorySince(User $user, \DateTimeImmutable $since): list<array{category: Category, count: int, lastReadOn: \DateTimeImmutable}>` — the user's read records with `readOn >= :since`, grouped by `book.category`, returning count and `MAX(readOn)`. Ordering is NOT done here; the service owns it (one place for the tie-break rule).

### Success Criteria:

#### Automated Verification:

- Candidate query tests pass (ranking, tie order, unread-only, unrated excluded, own-added-unread included, category scope, limit): `php bin/phpunit tests/Repository/BookRepositoryTest.php`
- Category count tests pass (window boundary inclusive, other users' records ignored, grouping): `php bin/phpunit tests/Repository/ReadBookRepositoryTest.php`
- Whole suite stays green: `php bin/phpunit`
- Mapping is valid and no schema change is needed: `php bin/console doctrine:schema:validate`
- Container compiles: `php bin/console lint:container`

#### Manual Verification:

- None; the layer has no user-visible behavior yet.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 2: RecommendationService

### Overview

Implement the PRD rule on top of the two queries.

### Changes Required:

#### 1. The service

**File**: `src/Service/RecommendationService.php`

**Intent**: Turn a user into up to three `RecommendationView`s following PRD steps 1-3 and the decisions above, with no framework or HTTP knowledge.

**Contract**: `final class RecommendationService`, constructor-injected `BookRepository` and `ReadBookRepository`. `forUser(User $user): list<RecommendationView>`. Steps: `since = MarkRead::today()` minus 12 months; fetch per-category counts; order them (count desc, `lastReadOn` desc, enum declaration order asc); for the first category fetch up to 3 candidates; if fewer than 3, fetch `3 - n` from the second category; return the concatenation. A user with no reads in the window gets `[]` with no candidate query at all; a user with one category issues one candidate query. Maximum three queries per call.

### Success Criteria:

#### Automated Verification:

- Service tests pass with the clock pinned by `Clock::set(new MockClock(...))` and restored in `tearDown`: `php bin/phpunit tests/Service/RecommendationServiceTest.php`
- Named cases covered: ranking inside the top category even when another category has higher averages; fill from the second category up to 3; single category yields fewer than 3; no reads in window yields `[]`; a read exactly 12 months ago counts and one 12 months plus a day ago does not; category tie broken by most recent read, then enum order; equal averages ordered by rating count then title; user's read books and unrated books never returned
- Whole suite stays green: `php bin/phpunit`

#### Manual Verification:

- None; presentation arrives in Phase 3.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 3: Home page

### Overview

Show the result to logged-in users only, with the empty-state message, and verify the end-to-end flow.

### Changes Required:

#### 1. Controller

**File**: `src/Controller/HomeController.php`

**Intent**: Pass recommendations to the template only for a logged-in `User`; keep the route public and the anonymous path free of any recommendation query.

**Contract**: `__invoke(RecommendationService $recommendations): Response` (service injected as an action argument, as `BookController` does with repositories). Template variable `recommendations`: `list<RecommendationView>` for a logged-in `User`, `null` otherwise. Route stays `GET /`, no `#[IsGranted]` (`prd.md:119`).

#### 2. Template and translations

**File**: `templates/home/index.html.twig`, `translations/messages.pl.yaml`

**Intent**: Inside the existing `{% if app.user %}` branch add a heading and up to three cards, or the empty-state message when the list is empty.

**Contract**: Each card: title linking to `path('book_show', {id: ...})`, `category.translationKey|trans`, and average rating (one decimal) with rating count. New keys under `home.recommendations.*` (heading, empty message, rating line). Polish copy; no plural-sensitive wording (use "liczba ocen: N"). Output escaped by Twig defaults.

#### 3. Controller tests

**File**: `tests/Controller/HomeControllerTest.php`

**Intent**: Pin who sees what.

**Contract**: Add cases: anonymous page has no recommendation block and no empty message; logged-in user with no reads sees the empty-state message; logged-in user with data sees at most three cards with correct links, category and rating text, and sees none of the books they already read. Existing anonymous tests keep passing (including "no `@` in body").

### Success Criteria:

#### Automated Verification:

- Controller tests pass: `php bin/phpunit tests/Controller/HomeControllerTest.php`
- Whole suite stays green: `php bin/phpunit`
- Twig and translations lint: `php bin/console lint:twig templates/home` and `php bin/console lint:yaml translations`
- Container compiles: `php bin/console lint:container`

#### Manual Verification:

- Logged in with at least two categories of rated reads: up to three cards appear, titles open the book detail page, category and "average (liczba ocen: N)" look right
- A fresh account with no reads sees the encouraging empty-state message
- Logged out, the home page shows no recommendations and no message
- After marking a recommended book as read, reloading `/` no longer lists it
- Home page loads in under 2 s with realistic data

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Testing Strategy

### Unit Tests:

- Rule logic in `RecommendationService` with real repositories on the test database (project style, data built inline, rollback by `dama/doctrine-test-bundle`) and a pinned clock.
- Repository queries tested separately so a rule failure and a query failure are distinguishable.

### Integration Tests:

- `HomeControllerTest`: anonymous, logged in with empty state, logged in with recommendations (`loginUser()` as in `BookCommentsTest`).

### Manual Testing Steps:

1. Create two users; each reads and rates books across two categories.
2. Log in as the first user, open `/`, compare the cards with the expected ranking.
3. Mark one recommended book as read; reload; it is gone and the next candidate fills in.
4. Log out; confirm nothing personal appears on `/`.

## Performance Considerations

Stated load (from the owner, 2026-11-12): 2-3 requests per second in the MVP, possibly up to 10 later; at most 10,000 books. Worst case is therefore about 30 recommendation queries per second (10 page loads x 3 queries), each over at most 10,000 books and their ratings. That is a small workload for MariaDB, so the plan keeps: no cache, because a per-user cache would show stale results right after marking a book read, and no new index (`book.category` is unindexed, but a scan of 10,000 rows is cheap; the existing `IDX_READ_BOOK_BOOK` covers the rating join). The number of ratings is not stated; it is bounded by users x books (2-4 users x 10,000). Revisit only if the 2 s budget is missed in the Phase 3 check (3.9) or traffic grows beyond 10 qps. These figures are not yet recorded in the PRD or the roadmap (Open Questions 7-8).

## Migration Notes

None: read-only feature on existing tables. S-08 (soft delete) must add its filter to `BookRepository::findRecommendationCandidates`; if S-08 ships first, add that filter here during implementation.

## References

- Related research: `context/changes/home-recommendations/research.md`
- PRD: `context/foundation/prd.md:95,100,105-113`
- Roadmap: `context/foundation/roadmap.md` (S-04)
- Projection precedent: `src/Repository/ReadBookRepository.php:55-72`, `src/Dto/CommentView.php`
- Service pattern: `src/Service/ReadingManager.php:19-25`
- "Today" helper: `src/Dto/MarkRead.php:30`
- Controller pattern: `src/Controller/BookController.php:26-37`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Queries and DTO

#### Automated

- [x] 1.1 Candidate query tests pass: `php bin/phpunit tests/Repository/BookRepositoryTest.php`
- [x] 1.2 Category count tests pass: `php bin/phpunit tests/Repository/ReadBookRepositoryTest.php`
- [x] 1.3 Whole suite stays green: `php bin/phpunit`
- [x] 1.4 Mapping valid, no schema change needed: `php bin/console doctrine:schema:validate`
- [x] 1.5 Container compiles: `php bin/console lint:container`

### Phase 2: RecommendationService

#### Automated

- [ ] 2.1 Service tests pass with pinned clock: `php bin/phpunit tests/Service/RecommendationServiceTest.php`
- [ ] 2.2 Named rule cases covered (ranking inside category, second-category fill, single category, empty window, 12-month boundary, category tie-break, equal-average order, read and unrated excluded)
- [ ] 2.3 Whole suite stays green: `php bin/phpunit`

### Phase 3: Home page

#### Automated

- [ ] 3.1 Controller tests pass: `php bin/phpunit tests/Controller/HomeControllerTest.php`
- [ ] 3.2 Whole suite stays green: `php bin/phpunit`
- [ ] 3.3 Twig and translations lint: `php bin/console lint:twig templates/home` and `php bin/console lint:yaml translations`
- [ ] 3.4 Container compiles: `php bin/console lint:container`

#### Manual

- [ ] 3.5 Logged in with two categories of rated reads: up to three cards with working links, category and average with rating count
- [ ] 3.6 Fresh account with no reads sees the empty-state message
- [ ] 3.7 Logged out, home page shows no recommendations and no message
- [ ] 3.8 After marking a recommended book as read, it disappears from `/`
- [ ] 3.9 Home page loads in under 2 s with realistic data
