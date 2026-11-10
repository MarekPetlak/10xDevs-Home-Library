<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Mark book as read and rate it

- **Plan**: context/changes/rate-read-book/plan.md
- **Mode**: Deep (claims verified inline against the code; no sub-agent)
- **Date**: 2026-11-10
- **Verdict**: REVISE → SOUND after fixes
- **Findings**: 2 critical, 3 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | FAIL |

## Grounding
Paths ✓ (all referenced existing files present), brief↔plan ✓, `docs/reference/contract-surfaces.md` and `lessons.md` absent (skipped)

## Findings

### F1 — Leftover "never show ratings" text contradicts rating-with-comment
- **Severity**: ❌ CRITICAL · **Impact**: 🏃 LOW · **Dimension**: Plan Completeness
- **Location**: What We're NOT Doing; Phase 3 manual check 3.10
- **Fix**: Reword to allow other ratings only next to a comment.
- **Decision**: FIXED

### F2 — Stale test file name BookNotesTest.php
- **Severity**: ❌ CRITICAL · **Impact**: 🏃 LOW · **Dimension**: Plan Completeness
- **Location**: Phase 4 — Tests
- **Fix**: Rename to BookCommentsTest.php.
- **Decision**: FIXED

### F3 — Read-date check ignores Europe/Warsaw vs UTC
- **Severity**: ⚠️ WARNING · **Impact**: 🔎 MEDIUM · **Dimension**: Blind Spots
- **Location**: Critical Implementation Details; Phase 3
- **Fix**: Set app time zone to Europe/Warsaw, frozen-clock boundary test.
- **Decision**: FIXED

### F4 — Unique-constraint race handling underspecified
- **Severity**: ⚠️ WARNING · **Impact**: 🏃 LOW · **Dimension**: Blind Spots
- **Location**: Phase 3 — ReadingManager
- **Detail**: Doctrine closes the EntityManager after the exception; catch must sit outside wrapInTransaction.
- **Decision**: SKIPPED

### F5 — S-09 does not hide the rating with a private comment
- **Severity**: ⚠️ WARNING · **Impact**: 🏃 LOW · **Dimension**: Blind Spots
- **Location**: roadmap.md S-09
- **Fix**: Add to S-09 outcome.
- **Decision**: FIXED

### F6 — Own comment shown twice
- **Severity**: 👁 OBSERVATION · **Impact**: 🏃 LOW · **Dimension**: Lean Execution
- **Location**: Phase 4
- **Fix**: Exclude viewer's own record from the comments block.
- **Decision**: FIXED
