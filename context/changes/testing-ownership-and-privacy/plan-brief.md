# Ownership and Privacy Tests — Plan Brief

> Full plan: `context/changes/testing-ownership-and-privacy/plan.md`
> Research: `context/changes/testing-ownership-and-privacy/research.md`

## What & Why

Test-plan Phase 1 guards the household library against two failures: a user changing another user's read record or comment (#2), and personal data reaching the wrong viewer (#3). We add only the missing tests and document the pattern, with no production code changes.

## Starting Point

Read-record routes resolve the record by (current user, book), so no foreign id can be targeted. Many single-path tests exist across five test files, but there is no role × page matrix. Book edit/delete (S-07/S-08) and comment visibility (S-09) are not built yet.

## Desired End State

A foreign `remove` provably leaves another user's record intact, one `PrivacyMatrixTest` states what anonymous, other and author roles may see on each page, and cookbook §6.3 and §6.4 describe how to add such tests.

## Key Decisions Made

| Decision | Choice | Why | Source |
| --- | --- | --- | --- |
| Risk #1 (book edit/delete) | Deferred to S-07/S-08 | Routes do not exist; no false green | Plan |
| Test layout | New `PrivacyMatrixTest` plus one case in `ReadBookControllerTest` | One reference file for §6.4, easy to extend for S-09 | Plan |
| Recommendation block | Assert aggregates only, no e-mail or comment text | Average of others' ratings is a kept PRD decision | Research / PRD |
| Layer | Integration (functional, multi-role) | Cheapest layer that sees query and template together | Research |
| CI gate | No change | `php bin/phpunit` already runs in CI | Research |

## Scope

**In scope:** one ownership test, the privacy matrix, cookbook §6.3/§6.4/§6.6, §3 status update.

**Out of scope:** #1 tests, S-09 visibility, route-absence guard, mutation testing, CI changes, any production code.

## Architecture / Approach

Seed one book with Anna (rating plus comment with a unique marker), Ola (rating only) and a viewer, request each page per role, and search the full response for the marker, raw and masked e-mails and ratings. Expected values come from the PRD and test plan, not from the implementation.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. Missing ownership test | Foreign remove without own record keeps the other record | Test passes for the wrong reason; checked by breaking the code |
| 2. Privacy matrix | Role × page test class | Overlap with existing assertions; checked by breaking query and template |
| 3. Cookbook and gate | §6.3, §6.4, §6.6 filled; Phase 1 `complete` | Docs too vague to follow |

**Prerequisites:** local MariaDB test database as for the current suite.
**Estimated effort:** ~1–2 sessions across 3 small phases.

## Open Risks & Assumptions

- Assumes S-07/S-08/S-09 stay unbuilt during this phase; if they land first, their plans must add #1 and visibility tests.
- Single-rating disclosure through recommendation averages stays accepted (PRD `prd.md:97`).

## Success Criteria (Summary)

- `php bin/phpunit` is green with the new tests.
- Deliberately breaking the ownership query, the comment filter or e-mail masking turns a test red.
- §6.3 and §6.4 are enough to add the next test without asking.
