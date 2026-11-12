<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Browse and search books

- **Plan**: context/changes/browse-and-search-books/plan.md
- **Mode**: Deep (checked inline, no sub-agent)
- **Date**: 2026-11-12
- **Verdict**: REVISE (all findings resolved by triage; verdict after fixes: SOUND)
- **Findings**: 1 critical, 2 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | FAIL |
| Plan Completeness | WARNING |

## Grounding
12/12 paths ✓, 5/5 symbols ✓, brief↔plan ✓, Progress↔Phase ✓

## Findings

### F1 — Title search can't be accent-sensitive without a migration

- **Severity**: ❌ CRITICAL
- **Impact**: 🔎 MEDIUM
- **Dimension**: Blind Spots
- **Location**: Phase 2 §2, "What We're NOT Doing", Migration Notes
- **Detail**: `book.title` has no explicit collation (`migrations/Version20261103191639.php:20`, `src/Entity/Book.php:24`), so it uses the server default (likely accent-insensitive), contradicting the accent-significant rule and the "no migration" lines.
- **Fix A ⭐ Recommended**: Migration pinning `book.title` to `utf8mb4_uca1400_as_ci` plus entity options and a test.
- **Fix B**: Force the collation in the query (custom DQL function or native SQL).
- **Decision**: FIXED (Fix A) — Phase 2 §2a, success criteria, Progress 2.7, Migration Notes

### F2 — Phase 4 breaks SecurityControllerTest and doesn't list it

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM
- **Dimension**: Plan Completeness
- **Location**: Phase 4 §1 and §4
- **Detail**: `/` becoming a redirect breaks the single `followRedirect()` in `tests/Controller/SecurityControllerTest.php` (lines 32-40, 81-94).
- **Fix A ⭐ Recommended**: Retarget login success, logout and the signed-in redirect to `book_index`; update the tests.
- **Fix B**: Keep `home` as target and add a second `followRedirect()`.
- **Decision**: FIXED (Fix A) — Phase 4 §1a and §4

### F3 — AGENTS.md update is in the wrong phase

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 3 item 3.7; Phase 4
- **Detail**: 3.7 referenced Phase 4 behaviour before it exists.
- **Fix**: Keep 3.7 for public list/detail routes; add Phase 4 step 4.8.
- **Decision**: FIXED — Phase 4 §3a, Progress 4.8

### F4 — Paging approach left open

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW
- **Dimension**: Lean Execution
- **Location**: Phase 2 §2
- **Detail**: Plan offered several paging options; research chose Doctrine's `Paginator`.
- **Fix**: Name `new Paginator($query, fetchJoinCollection: true)`.
- **Decision**: FIXED — Phase 2 §2
