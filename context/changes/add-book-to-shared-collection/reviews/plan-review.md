<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Add book to shared collection

- **Plan**: context/changes/add-book-to-shared-collection/plan.md
- **Mode**: Deep (verified inline, no sub-agent)
- **Date**: 2026-11-03
- **Verdict**: REVISE (light) -> SOUND after triage fixes
- **Findings**: 0 critical, 3 warnings, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | WARNING |
| Blind Spots | WARNING |
| Plan Completeness | WARNING |

## Grounding

6/6 paths ok, 3/3 symbols ok (`EnumType`, `bootstrap_5_layout`, `AbstractController` 422 behaviour), brief and plan consistent, Progress and Phase 1:1. One stale line reference corrected (`SecurityControllerTest.php:20` -> `:25`).

## Findings

### F1 — "Ignoring case" relies on an unverified, accent-insensitive collation

- **Severity**: WARNING
- **Impact**: MEDIUM
- **Dimension**: Blind Spots
- **Location**: Critical Implementation Details; Phase 1 (migration, `Author`, `Tag`)
- **Detail**: Author and tag reuse depends on the column collation, proven only on local MariaDB 10.11. The cyberfolks default is unknown; `_ci` collations are also accent-insensitive.
- **Fix A (Recommended)**: Explicit `utf8mb4_unicode_ci` on author and tag name columns; document accent-insensitivity.
- **Fix B**: Lower-cased `name_key` columns compared in PHP.
- **Decision**: FIXED via Fix A (plan: Critical Details, Author/Tag contract, migration contract, Phase 3 manual production check)

### F2 — Invalid form will return 422, not the requested 400

- **Severity**: WARNING
- **Impact**: LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — Controller
- **Detail**: `AbstractController::render()` sets 422 itself when an invalid form is passed.
- **Fix**: Pass an explicit `Response` with status 400 to `render()`.
- **Decision**: FIXED (controller contract)

### F3 — Every new table with a user foreign key breaks the existing tests

- **Severity**: WARNING
- **Impact**: MEDIUM
- **Dimension**: Architectural Fitness
- **Location**: Critical Implementation Details; Phase 2 tests
- **Detail**: Four existing test classes run `DELETE FROM user`; each later slice with a foreign key to `user` repeats the trap.
- **Fix A (Recommended)**: One shared cleanup helper.
- **Fix B**: Keep per-test cleanup.
- **Decision**: FIXED differently — user chose `dama/doctrine-test-bundle` (transaction rollback per test), added as Phase 1 change #4; per-test cleanup removed from the plan.

### F4 — ISBN normalisation mechanism is left open

- **Severity**: OBSERVATION
- **Impact**: LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 2 — Input DTO
- **Detail**: Public DTO fields have no setter, yet the plan says "in its setter or by a form data transformer".
- **Fix**: Use a model data transformer on the ISBN field.
- **Decision**: ACCEPTED

### F5 — The PRD rewrite for notes goes beyond S-02

- **Severity**: OBSERVATION
- **Impact**: MEDIUM
- **Dimension**: Lean Execution
- **Location**: Phase 3 — PRD and roadmap decisions
- **Detail**: Rewriting FR-009, Access Control and Guardrails belongs to S-03.
- **Fix A (Recommended)**: Record only S-02 decisions in the PRD; park the notes decision in the roadmap.
- **Fix B**: Keep the full PRD rewrite in Phase 3.
- **Decision**: FIXED via Fix A

### F6 — Phase 3 doc check is not a runnable command

- **Severity**: OBSERVATION
- **Impact**: LOW
- **Dimension**: Plan Completeness
- **Location**: Phase 3 — Success Criteria (Automated)
- **Detail**: No command for "AGENTS.md mentions App\Enum\Category". The stale line reference was corrected separately.
- **Fix**: `grep -F 'App\Enum\Category' AGENTS.md`.
- **Decision**: SKIPPED
