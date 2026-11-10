# Mark book as read and rate it — Plan Brief

> Full plan: `context/changes/rate-read-book/plan.md`

## What & Why

A signed-in household member marks a book as read in one form: rating 1-5, read date and an optional comment (roadmap S-03, FR-008, with FR-009 comments pulled forward). Ratings and read dates are the inputs of the recommendation rule, so this slice has to land before the north star S-04.

## Starting Point

Books can be added (`/books/new`) but nothing lists or shows them, and nothing links a user to a book except the user who added it. S-05 (browse and search) is not built, so this slice brings a minimal book list and detail page.

## Desired End State

A signed-in user sees every book on `/books` with their own read state, and on `/books/{id}` can mark the book read (rating 1-5, read date not in the future, optional comment), see their own record, and remove the mark. All signed-in users see readers' comments with an obfuscated e-mail (`a***@example.com`). A rating of another user is shown only next to that user's comment; anonymous visitors are sent to login.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| --- | --- | --- | --- |
| Where to act | Minimal `/books` list and `/books/{id}` detail, login only | There is no book view yet and S-03 needs a place to be tested end to end. | Plan |
| Rating and read | Rating required to mark read; one form | Every read book then has a rating, so S-04 never meets unrated reads. | Plan |
| Read date | User-picked, defaults to today, not in the future | Lets people log old reading so the "last year" rule is accurate. | Plan |
| Comment | Optional field of the same mark-as-read form, one per read record, max 2000 chars | Owner: comments are an integral part of the form; optional keeps marking quick. | Plan |
| Editing | Rating, date and comment cannot be edited; the record can be removed (hard delete, no soft delete) and created again | Owner's decision: fix a mistake by removing and re-adding. | Plan |
| Removing a read mark | Deletes rating, date and comment together | The comment is a column of the record, so no orphan comments and no extra code. | Plan |
| Comments visibility | Visible to all signed-in users, author e-mail masked; per-comment privacy later | Owner: ship visible, add privacy in a separate task. | Plan |
| Roadmap | New slice S-09 `reader-comments-privacy`; question 11 resolved for S-03 | Owner asked to record the follow-up in the roadmap. | Plan |
| Data model | One `ReadBook` table (unique user+book) with a nullable `comment` column | One source of truth for "user read book"; no separate comments entity needed. | Plan |

## Scope

**In scope:** `ReadBook` entity and migration; `/books` and `/books/{id}`; mark-read form with comment, remove; shared comments block with e-mail masking; Polish strings; tests; AGENTS.md, PRD and roadmap sync; production check.

**Out of scope:** editing rating, date or comment; soft delete; separate or multiple comments per user and book; comment privacy flag; search, filters, pagination; recommendations and average ratings; edit and delete of books; duplicate warning; true e-mail anonymity.

## Architecture / Approach

`ReadBook` is the single record that says "user X read book Y" (rating, read date, optional comment). `ReadingManager` holds the rules for creating and removing it, thin controllers wire form, service and flash as in S-02, and an `EmailMasker` Twig filter is the only way e-mails reach a template. The detail page lists comments from one query over read records with a comment. Tests are written first with `WebTestCase` in rolled-back transactions.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Data model | `ReadBook`, migration, mapping tests | Unique and FK constraints behave as designed |
| 2. List and detail | `/books`, `/books/{id}`, navbar link | `{id}` route must not swallow `/books/new`; no N+1 queries |
| 3. Mark read with comment | Form with rating, date, comment; `ReadingManager`; remove with confirmation | Double submit race; ratings leaking outside the comments block |
| 4. Shared comments | Comments block with masked e-mail for all signed-in users | Raw e-mail or rating leaking into the block; XSS-safe output |
| 5. Docs and production | AGENTS.md, PRD v3, roadmap check, deploy check | Rewriting the PRD comments rule without contradictions |

**Prerequisites:** S-02 shipped (done); local MariaDB via `docker compose up -d database`; work on branch `feat/rate-read-book`.
**Estimated effort:** ~3 sessions across 5 phases.

## Open Risks & Assumptions

- Masked e-mail still reveals the first letter and the domain; it reduces exposure but does not anonymise comments.
- The PRD says comments are personal; this change relaxes that for comments only (a rating shows only next to its comment; other ratings and read status stay private) until S-09 ships.
- Hard delete means a removed rating and comment are gone for good, which differs from the PRD guardrail wording on data not being lost; the owner chose it explicitly.
- Read dates are user-chosen, so a user can influence "last year" counts in S-04.
- With one comment per read record a reader cannot add a later thought without removing the whole mark.
- Production has no database backup yet (roadmap question 10); take a dump before the merge.

## Success Criteria (Summary)

- A signed-in user can mark a book read with rating, date and an optional comment, see their own rating (others' only next to their comments), and remove the mark to start again.
- Other signed-in users see the comment with a masked e-mail; anonymous users see nothing.
- The suite is green in CI and the flow works on production after the migration deploys.
