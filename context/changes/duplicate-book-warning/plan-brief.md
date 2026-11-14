# Duplicate book warning — Plan Brief

> Full plan: `context/changes/duplicate-book-warning/plan.md`

## What & Why

Before a book is saved, the app warns when it already exists in the shared collection, so a household member does not buy a second copy (Vision pain, FR-005, roadmap S-06). The warning only informs; the adding user decides.

## Starting Point

`/books/new` validates the form and saves immediately (`BookController::new`). Titles are case-insensitive, authors are entities, ISBN is stored normalised with an index. Nothing checks for existing books yet, and soft delete (S-08) does not exist.

## Desired End State

Submitting a book that matches an existing one shows the form again with a warning: up to three matches, each with title, authors, year, a link and the reason. "Add anyway" saves the book. A book without a match saves as today.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Author rule | Same title (case/edge whitespace ignored) and at least one shared author | Catches the same book entered with an extra or missing co-author | Plan |
| Combining rules | ISBN match OR title+author match, reason shown | FR-005 compares title, author and ISBN; covers retyped entries and other editions | Plan |
| ISBN forms | ISBN-10 and ISBN-13 of one book match | The same physical book is caught however the ISBN was typed | Plan |
| Confirm mechanics | Re-render form (HTTP 400) with an "Add anyway" submit button | Server-side, no JS or session state, fits existing controller and tests | Plan |
| Warning content | Up to 3 matches, newest first, link (new tab) and reason | User can inspect the existing entry and understand edition cases | Plan |
| Data model | No migration, read-only query on existing columns | ISBN index, `_as_ci` title and `book_author` join suffice | Plan |
| Accents / inner spaces | Not normalised | Matches AGENTS.md ("José" ≠ "Jose", surrounding whitespace only) | Plan |

## Scope

**In scope:** ISBN form conversion, duplicate query and service, controller flow with confirm button, Polish translations, warning partial, tests, e2e baseline, AGENTS.md note.

**Out of scope:** schema changes or unique indexes, fuzzy matching, edit (S-07) and soft delete (S-08) behaviour, JS or modal, blocking duplicates.

## Architecture / Approach

`DuplicateFinder` resolves typed authors to entities, expands the ISBN into its two forms and calls `BookRepository::findDuplicates`, returning `DuplicateMatch` DTOs with typed reason flags. The controller runs it after validation unless the "Add anyway" button was clicked, and renders `_duplicate_warning.html.twig` on a hit.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Matching core | `IsbnForms`, `findDuplicates`, `DuplicateFinder` with tests | ISBN check-digit edge cases |
| 2. Controller and form flow | Warn-instead-of-save, "Add anyway" button, translations, controller tests | Second submit button must not skip validation |
| 3. Warning UI and docs | Warning partial per UI contract, Playwright baseline, AGENTS.md | Visual gate and 390 px layout |

**Prerequisites:** S-02 (add book) is done; local MariaDB via `docker compose up -d database`.
**Estimated effort:** ~2-3 sessions across 3 phases.

## Open Risks & Assumptions

- S-08 (soft delete) will need the same filter added to `findDuplicates`; noted in code and AGENTS.md.
- A common title with a shared author may warn on a different book; accepted, because the user can still add it.
- Assumes stored ISBNs are normalised (true since S-02 via the form transformer).

## References

- Full plan: `context/changes/duplicate-book-warning/plan.md`
- Requirement: `context/foundation/prd.md` FR-005; roadmap S-06

## Success Criteria (Summary)

- Existing books are flagged by ISBN (either form) or by title plus shared author, and the reason is visible.
- The user can always add the book anyway; non-duplicates behave exactly as before.
- `bin/phpunit` and the screenshot gate pass.
