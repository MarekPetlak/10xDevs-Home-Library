# Home Recommendations — Plan Brief

> Full plan: `context/changes/home-recommendations/plan.md`
> Research: `context/changes/home-recommendations/research.md`

## What & Why

A logged-in user sees up to three recommended next books on the home page, chosen by the PRD rule: unread books with the highest average rating, taken from the categories the user read most in the last year. This is the roadmap's north star (S-04, FR-010): it proves the product recommends from ratings. When nothing can be recommended, the page says so.

## Starting Point

The home page (`HomeController`, `home/index.html.twig`) is public and shows only a greeting. Ratings (`read_book.rating`), the read date (`read_on`) and the category enum already exist from S-02/S-03, so no schema change is needed. There are no aggregate queries and no recommendation code yet.

## Desired End State

On `/`, a logged-in user sees up to three cards: title (linking to the book page), category, and average rating with the rating count. A user with no recommendable books sees an encouraging message. Anonymous visitors see nothing of this.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Build vs library | Own DQL + `RecommendationService`, no dependency | Found libraries do collaborative filtering, a PRD Non-Goal | Research |
| Ranking shape | Rank inside the user's top category, then 2nd category to reach 3 | Always yields results when the category has candidates | Plan |
| Ordering | Average desc, rating count desc, title asc, id asc | Deterministic ties, no minimum count per PRD | Plan |
| Unrated books | Excluded | The rule's input is an average rating | Plan |
| "Last year" | Rolling 12 months from today (Europe/Warsaw), inclusive boundary | No reset on 1 January; reuses `MarkRead::today()` | Plan |
| Category tie | Most recently read category, then enum order | Deterministic and testable | Plan |
| Card content | Title link, category, average with rating count; no authors | Enough to decide, no extra joins | Plan |
| Own added, unread books | Eligible | PRD only says "nieprzeczytane" | Plan |
| Caching / index / migration | None | Tiny data, stale cache risk, read-only feature | Research |
| S-08 interaction | One candidate query method in `BookRepository` | S-08 adds its soft-delete filter in one place | Research |

## Scope

**In scope:** view DTO, two repository queries, `RecommendationService`, controller and template change, Polish translations, repository/service/controller tests.

**Out of scope:** collaborative filtering or libraries, Bayesian average, minimum ratings, unrated fill, third category, authors on cards, justification text, caching, new indexes, soft-delete filter (S-08).

## Architecture / Approach

`HomeController` calls `RecommendationService::forUser()` only for a logged-in user. The service gets the user's per-category read counts for the last 12 months (`ReadBookRepository`), orders categories, then asks `BookRepository` for ranked unread, rated candidates of the first category and, if fewer than 3, the second. A `RecommendationView` DTO carries only what a card shows. At most three queries per page view.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Queries and DTO | `RecommendationView`, candidate query, categories-in-window query, repository tests | Doctrine `NEW` with enum and `AVG` typing |
| 2. RecommendationService | PRD rule with window, tie-breaks, limit 3, fill; edge-case tests | Pinning the clock and covering the boundary cases |
| 3. Home page | Controller, template, empty state, translations, controller tests, manual check | Keeping anonymous pages free of personal data |

**Prerequisites:** S-03 done (it is). Local test DB (MariaDB) reachable for `php bin/phpunit`.
**Estimated effort:** ~2-3 sessions across 3 phases.

## Open Risks & Assumptions

- If S-08 (soft delete) ships before this, its filter must be added to the candidate query during implementation.
- Stated load: 2-3 qps in the MVP, up to 10 later, at most 10,000 books. No measurement backs the "under 2 s without cache" claim yet (checked manually in Phase 3); revisit caching or an index if traffic exceeds 10 qps.
- `read_on` is a date chosen by the user (can be backdated), so "last year" reflects the stated read date, not the day it was recorded.

## Success Criteria (Summary)

- A user who read and rated books in a category sees up to three unread, rated books from it, best average first; fewer if fewer exist.
- A user with no recommendable books sees the encouraging message; anonymous visitors see neither block nor message.
- Test suite green, no schema change, home page under 2 s.
