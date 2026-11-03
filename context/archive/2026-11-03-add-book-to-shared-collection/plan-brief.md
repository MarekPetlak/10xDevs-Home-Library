# Add book to shared collection — Plan Brief

> Full plan: `context/changes/add-book-to-shared-collection/plan.md`

## What & Why

A signed-in household member adds a book to the shared collection through a validated Polish form (roadmap S-02, FR-004). It is the first slice that creates book data, so it fixes the schema that rating, recommendations, search, edit and delete (S-03 to S-08) all build on.

## Starting Point

Login works and the layout, Polish locale and Bootstrap are in place, but there are no book entities, no forms and no protected feature route; only `User` exists. The roadmap had S-02 blocked on three product questions, which this plan decides.

## Desired End State

A signed-in user opens `/books/new`, enters title, authors, ISBN, year, category, tags and an optional description, and returns to the empty form with a success message. The book, its authors and tags are stored and linked to the user who added it. Anonymous visitors are sent to the login page; invalid input shows Polish errors and saves nothing.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Category | Fixed list in a PHP enum | Recommendations count categories, so spelling must be consistent. | Plan |
| "Rok" | Publication year, required | The date added is recorded automatically, so the year means the edition; required by the owner. | Plan |
| Description | Optional free text, max 2000 chars, visible to everyone | Owner's addition: a field of the entry filled in by the adder, separate from notes. | Plan |
| Authors | `Author` entity with first and last name, many-to-many; typed "Imię Nazwisko", split at the last space | Owner wants separate, both required first and last name (one-word entries are rejected); "Le Guin"-style surnames split wrongly for now. | Plan |
| Tags | `Tag` entity, many-to-many, max 5 | Chosen by the owner; same reuse as authors. | Plan |
| ISBN | Required, normalised, validated, not unique | Clean values for S-06's duplicate warning, which only warns. | Plan |
| After adding | Back to the form with a flash | No list page until S-05; chosen by the owner. | Plan |
| Ownership | `added_by` + `created_at` now, `deleted_at` in S-08 | Enables the author-only rule without unused columns. | Plan |
| Notes | Not in S-02, separate from the description; reader notes go with rating (S-03), many per book, public or private by the author | Owner's decision; contradicts the PRD notes rule, so it is recorded as a roadmap note and PRD wording is deferred to S-03. | Plan |
| Input of authors and tags | Comma-separated text, matched ignoring case | Simple, no JavaScript; default chosen by the planner. | Plan |

## Scope

**In scope:** `Book`, `Author`, `Tag`, category enum, migration; add-book form, `BookManager`, `/books/new`, navbar link, Polish strings; tests; docs, PRD and roadmap sync; deploy check.

**Out of scope:** book note, duplicate warning, list/browse/search, edit, delete, ratings, recommendations, detail page, ISBN lookup, category admin, unique ISBN.

## Architecture / Approach

An input DTO (`NewBook`) carries the form data and validation; `BookManager` turns it into a `Book` with reused `Author`/`Tag` rows in one transaction; the controller only wires form, service and flash. `NameList` parses comma-separated names in one place. Every test runs in a rolled-back transaction (`dama/doctrine-test-bundle`), so the `book.added_by` foreign key to `user` never breaks other tests.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Book data model | Entities, enum, reviewed migration, mapping tests, per-test database rollback (`dama/doctrine-test-bundle`) | Schema shape that later slices depend on |
| 2. Add-book form and flow | `/books/new`, form, service, translations, tests | Case-insensitive reuse of authors and tags; explicit 400 on invalid input |
| 3. Docs sync and production check | AGENTS.md, PRD/roadmap decisions, deploy and Fly check | Keeping the notes decision out of the PRD until S-03 |

**Prerequisites:** S-01 shipped (done); local MariaDB via `docker compose up -d database`; work on branch `feat/add-book-to-shared-collection`.
**Estimated effort:** ~2-3 sessions across 3 phases.

## Open Risks & Assumptions

- Splitting at the last space mishandles multi-word surnames ("Le Guin"); a later change can add explicit first/last inputs.
- Author and tag reuse relies on an explicit `utf8mb4_unicode_ci` collation (case- and accent-insensitive: "a" and "ą" merge); checked locally by a test and on production by a manual step.
- The notes decision (public or private, many per book) contradicts the PRD until S-03 is planned; it is recorded in the roadmap only.
- With no list page, saved books are only visible in the database until S-05.

## Success Criteria (Summary)

- A signed-in user can add a book with authors, tags, ISBN, year and category, and sees a success message.
- Invalid input is rejected with Polish messages and nothing is saved; anonymous users cannot reach the form.
- The suite is green in CI and the feature works on production after the migration deploys.
