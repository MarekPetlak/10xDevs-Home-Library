# Browse and search books — Plan Brief

> Full plan: `context/changes/browse-and-search-books/plan.md`
> Research: `context/changes/browse-and-search-books/research.md`

## What & Why

Anyone, logged in or not, should be able to browse and search the shared book collection by title, author and category (FR-003, roadmap S-05). Today the list and detail pages are login-only, so a guest sees nothing.

## Starting Point

`/books` and `/books/{id}` require login and always show the user's read state, ratings and comments. The list query returns every book with no filter and no paging; the PRD expects up to 10,000 books.

## Desired End State

A logged-out visitor can search by title and author in separate fields, pick a category, page through 20 books at a time and open any book, and never sees a read badge, rating or comment. The "Dodaj książkę" button is visible to them too and leads to the login page; every login lands on `/books`. A logged-in user sees the same pages with their personal parts, as today.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Where it lives | Same `/books` list and detail, personal parts only for logged-in | One set of pages, no duplicated templates | Plan |
| Search fields | Separate title and author fields | Precise searches without a combined box | Plan |
| Match rule | Contains, case-insensitive, accents significant | Consistent with how author names are stored (José ≠ Jose) | Plan |
| Category filter | Single-select dropdown from the fixed enum, combined with text | Matches the fixed 11-category list | Plan |
| Paging | Page numbers, 20 per page, filters kept in the URL | Bounded page size for up to 10,000 books | Plan |
| Main page | `/books` is the main page; `/` redirects to it (route `home` kept for the brand link) | Old bookmarks keep working | Plan |
| Login/logout target | Straight to `book_index`, not through `/` | Avoids an extra redirect hop on every login and logout | Plan review |
| Title collation | Migration pins `book.title` to `utf8mb4_uca1400_as_ci` | Title and author search then follow the same accent rule | Plan review |
| Recommendations | Shown above the list for logged-in users, on every page | Same cards and rule as the old home page, moved | Plan |
| Add-book button | Visible to anonymous visitors, `/books/new` stays login-only | Invites contribution; login always lands on the main page | Plan |
| Paging tool | Doctrine's built-in `Paginator`, no bundle | No new dependency; one list does not justify KnpPaginator or Pagerfanta | Research |
| Privacy tests | Functional tests as anonymous and logged-in | Guards the roadmap's main risk | Plan |

## Scope

**In scope:** public list and detail; privacy gating in controllers and templates; title/author/category search; paging; filter form, pager and empty states; tests; AGENTS.md route note.

**Out of scope:** accent-insensitive search, combined search box, multi-category filter, tag search, sorting, schema changes other than the title collation, restyling, changes to adding/editing/deleting books.

## Architecture / Approach

Phase 1 only changes who can see what, and is verified before any search code exists. Phase 2 adds a `BookSearch` DTO and a paginated repository query (author filter by subquery so all authors still load). Phase 3 adds a GET form and pager in Twig with Bootstrap classes already in use.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Public access and gating | List and detail open to anonymous; personal parts hidden | A personal block leaks to anonymous visitors |
| 2. Search, filter, paging | Title collation migration, repository query and request mapping, tested | Paging with joined authors; LIKE wildcards; production ALTER (dump first) |
| 3. Form, pager, empty states | Visible search UI, translations, AGENTS.md update | Filters lost when paging; keyboard and mobile use |
| 4. Main page with recommendations | `/` redirects to `/books`; login and logout land there; recommendations above the list for logged-in users | Recommendation queries must not break the flat query count; home and security tests must move |

**Prerequisites:** S-02 is done (books exist); local MariaDB for `bin/phpunit`.
**Estimated effort:** ~3 sessions across 4 phases.

## Open Risks & Assumptions

- The `book.title` collation migration is the only schema change; migrations do not roll back, so take a dump before deploying.
- Two existing tests assert anonymous redirects on `/books` and must be rewritten, not just extended.
- The roadmap's wording ("without read status") is satisfied for anonymous visitors; logged-in users keep their personal parts by the user's decision.

## Success Criteria (Summary)

- A logged-out visitor can find and open a book by title, author or category, with no personal data shown
- Opening the site lands on `/books`; logged-in users see recommendations above the list
- A logged-in user keeps read badges, ratings and comments on the same pages
- Results page through 20 at a time with filters preserved, and a full test suite passes
