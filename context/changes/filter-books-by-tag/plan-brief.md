# Filter books by tag — Plan Brief

> Full plan: `context/changes/filter-books-by-tag/plan.md`

## What & Why

Readers can see a book's tags on its page but cannot find other books with the same tag. We add a public page `/tags/<slug>` listing the books of one tag, reached by clicking a tag on a book's detail page. This closes the "tag search" item deferred by `browse-and-search-books`.

## Starting Point

`/books` filters by title, author and category via `BookSearch` and `BookRepository::search()`. Tags exist as entities (`Tag`: id + name, ManyToMany with `Book`), appear only as plain badges on the detail page, and have no URL-safe identifier.

## Desired End State

Each tag has a unique slug. Tags on the detail page are links to `/tags/<slug>`, which shows the tag's books in the same table and pager as `/books` (public, newest first, 20 per page). Unknown slug → 404. The `/books` page and its search form are unchanged.

## Key Decisions Made

| Decision | Choice | Why | 
| --- | --- | --- | 
| Entry point | Links on the detail page to `/tags/<slug>`; no control in the search form | Requested by the user; keeps `/books` untouched |
| Tags per filter | One tag, exact slug | No AND/OR ambiguity, simplest page |
| URL identifier | New unique `slug` column on `Tag` | Readable and safe for spaces, accents and `/` in names |
| Slug rule | Polish-aware ASCII, lowercase, `-2`/`-3` on collision, `tag` if empty | `zolw` and `żółw` are distinct tags but share an ASCII form |
| Table content | No tags column in the list; tags only on the detail page | Requested by the user |
| Access | Public, unknown slug 404 | Same as `/books` |
| Reuse | Extract table and pager partials; pager gets route parameters | Avoids duplicating markup |

## Scope

**In scope:** slug column + migration with backfill, slug generation for new tags, `/tags/{slug}` page, tag links on the detail page, fixtures, tests, `AGENTS.md` note.

**Out of scope:** tag field in the search form, `?tag=` parameter, multi-tag filtering, partial matching, tags in the list table, `/tags` index, tag editing.

## Architecture / Approach

`TagSlugger` (small service) produces a free slug; `BookManager` uses it when it creates a tag. `BookRepository::findByTag()` returns a paginated list through an `EXISTS` subquery, like the author filter. A public controller action resolves the slug and renders `tag/show.html.twig`, which includes the shared books table and pager.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Tag slug | Unique slug column, backfilled, generated for new tags | Migration on populated table; accent/case collisions |
| 2. Tag page | `/tags/{slug}` with shared table and pager | Pager refactor must not change `/books` links; N+1 |
| 3. Links and fixtures | Clickable tags on the detail page, fixtures with slugs | Link styling and focus |

**Prerequisites:** MariaDB 10.10+ (already the case in dev, CI and production).
**Estimated effort:** ~2 sessions across 3 small phases.

## Open Risks & Assumptions

- The migration inlines a frozen copy of the slug rule; the service must stay consistent with it (unit tests cover both outputs on the same examples).
- A tag name made only of symbols gets slug `tag`; acceptable for a home library.
- Rolling back the migration makes existing `/tags/<slug>` links 404.

## Success Criteria (Summary)

- Clicking a tag on a book page opens a page with exactly the books of that tag.
- Anonymous visitors see it without any personal data; unknown tags give 404.
- `/books` behaves as before; whole PHPUnit suite and `doctrine:schema:validate` pass.
