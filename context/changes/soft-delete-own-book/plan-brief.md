# Soft-delete own book — Plan Brief

> Full plan: `context/changes/soft-delete-own-book/plan.md`
> Research: `context/changes/soft-delete-own-book/research.md`

## What & Why

The author of a book entry can delete it (PRD FR-007, roadmap S-08). The book disappears from every list, recommendation and duplicate check, while other users' ratings and comments are preserved. Deletion is soft because the schema forbids hard delete (RESTRICT foreign keys) and the PRD says deletion must not destroy others' data.

## Starting Point

There is no soft-delete mechanism. `Book` has a nullable `updatedAt` and `BookVoter` allows only `EDIT`. Five `BookRepository` queries already carry "S-08 filter goes here" notes. `book_read_remove` provides the POST + CSRF + flash pattern, and `show.html.twig` has a `confirm()` form precedent.

## Desired End State

The owner sees a "Usuń" button on their book, confirms, and lands on the book list with a flash. The book is gone from lists, tag pages, recommendations and duplicate warnings, and its URLs return 404. Non-owners get 403, anonymous users a login redirect. Other users' reads and comments remain in the database.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Delete mechanism | Nullable `deleted_at` on `Book` | Hard delete is blocked by RESTRICT FKs and the PRD requires data to survive | Research |
| Filter mechanism | Explicit `deletedAt IS NULL` per query | Matches the repo's S-08 docblocks; no Doctrine filter precedent | Plan |
| Duplicate check | Ignore deleted books | AGENTS.md and test-plan #5 put the filter in `findDuplicates`; no unique ISBN constraint blocks re-adding | Plan |
| Reads of a deleted book | Hidden everywhere: not counted for recommendations, read-remove returns 404 | One simple rule; roadmap warns deleted books must not affect recommendations | Plan |
| Authorization | `BookVoter` `DELETE` with the same owner rule as `EDIT` | Single rule for who may change a book | Research |
| Route and confirmation | POST `book_delete`, CSRF, JS `confirm()`, redirect to `book_index` with flash | Reuses the `book_read_remove` pattern; the detail page would 404 after delete | Plan |
| Index | `IDX_BOOK_DELETED_AT` on `deleted_at`, in mapping and migration | Requested by the user; supports the active-only filter | Plan |
| Out of scope | Restore, account deletion, orphan cleanup | Not required by FR-007 or parked elsewhere | Plan |

## Scope

**In scope:** column, index and migration, `BookManager::delete`, voter rule, filtering of all nine read paths, delete route, button, translations, tests.

**Out of scope:** undelete or purge, Doctrine SQL filter, separate confirmation page, account deletion, orphan author/tag cleanup, new e2e scenarios.

## Architecture / Approach

`Book.deletedAt` is set by `BookManager::delete` (clock, transaction). `BookRepository` gains the active-only condition in its five flagged methods plus `findActive(int $id)`, used by edit and read-remove. `ReadBookRepository::countByCategorySince` also excludes deleted books. The delete route checks 404, then the voter, then CSRF, and redirects to the list.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Schema and delete domain | Column, migration, `BookManager::delete`, voter `DELETE`, unit tests | Migration must match the mapping exactly (`schema:validate`) |
| 2. Hide deleted books everywhere | Filters in repositories and controllers, integration tests | Missing one of the nine read paths |
| 3. Delete route and UI | `book_delete` route, owner-only button, translations, controller and privacy tests | CSRF and ownership ordering; button changes the owner's detail view |

**Prerequisites:** local MariaDB (`docker compose up -d database`) for PHPUnit; S-02 and S-03 already done.
**Estimated effort:** ~2-3 sessions across 3 phases.

## Open Risks & Assumptions

- A future query that reads books could forget the filter (accepted with per-query filtering).
- A user cannot clear their own read mark on a deleted book; the data stays in the database.
- Re-adding a deleted book creates a new row; reads stay on the old hidden row.
- Owner-visible `detail-*` e2e baselines may change; e2e is local-only and not a gate.

## Success Criteria (Summary)

- An owner can delete their book and it vanishes from lists, recommendations and duplicate warnings.
- Another user's ratings and comments on the deleted book still exist; a non-owner cannot delete.
- Full PHPUnit suite, PHPStan, lints and `doctrine:schema:validate` pass.
