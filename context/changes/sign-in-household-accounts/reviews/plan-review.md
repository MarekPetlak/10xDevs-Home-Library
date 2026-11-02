<!-- PLAN-REVIEW-REPORT -->
# Plan Review: Sign-in for household accounts

- **Plan**: context/changes/sign-in-household-accounts/plan.md
- **Mode**: Deep
- **Date**: 2026-11-02
- **Verdict**: REVISE
- **Findings**: 0 critical, 3 warnings, 1 observation

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| End-State Alignment | PASS |
| Lean Execution | PASS |
| Architectural Fitness | PASS |
| Blind Spots | WARNING |
| Plan Completeness | WARNING |

## Grounding

15/15 paths ✓, 6/6 symbols ✓ (vendor), brief↔plan ✓, Progress↔Phase 4/4 and 25/25 rows ✓. `lessons.md` and `contract-surfaces.md` do not exist. Verification done inline (vendor greps, `fly ssh console --help`), no subagent.

## Findings

### F1 — Step 1.6 needs a PR that Phase 4 opens

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Plan Completeness
- **Location**: Phase 1 (step 1.6) vs Phase 4 (step 4.4)
- **Detail**: Step 1.6 requires "CI `test` job passes on the PR" at the end of Phase 1, but `.github/workflows/deploy.yml` runs only on `pull_request` and `push` to `main`, and step 4.4 treats "PR is open" as a Phase 4 event. The CI database change is the riskiest infra piece and would be proven only at the end.
- **Fix A ⭐ Recommended**: Open a draft PR right after Phase 1 and push later phases to it
  - Strength: CI proves the create-and-migrate step early; `main` is protected so a PR is required anyway.
  - Tradeoff: The PR lives across four phases and every push re-runs CI.
  - Confidence: HIGH — trigger and branch protection are in the repo.
  - Blind spot: The required check name `test` has never run on a PR.
- **Fix B**: Move step 1.6 to Phase 4
  - Strength: Plan matches a single PR at the end.
  - Tradeoff: A broken CI database setup is found after all four phases.
  - Confidence: MEDIUM — depends on CI passing first time.
  - Blind spot: None significant.
- **Decision**: FIXED via Fix A (Phase 1 manual bullet now opens a draft PR; Phase 4 bullet marks it ready)

### F2 — Local test DB isn't set up before Phase 1 verification

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 1 (criterion 1.1) and Critical Implementation Details
- **Detail**: Criterion 1.1 runs `doctrine:database:create --env=test` locally, which needs a privileged user; `.env.test.local` is documented only in Phase 4 (`AGENTS.md`). `/.env.*.local` is gitignored (`.gitignore:5`).
- **Fix**: Add to Phase 1 a change that creates `.env.test.local` (root URL of the local container) and move the `AGENTS.md` note about it into Phase 1.
- **Decision**: FIXED differently (user chose the committed `.env.test` instead of `.env.test.local`; Phase 1 gains change 6 "Local test database", Critical Details and Phase 4 AGENTS.md text updated)

### F3 — The production account step has no terminal for the hidden prompt

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Blind Spots
- **Location**: Phase 4 (step 4.5)
- **Detail**: `fly ssh console -C "..."` gets no pseudo-terminal by default (`--pty` is "default: on when no command is provided"), so the hidden password prompt falls back to visible input or fails.
- **Fix**: Use `fly ssh console -a 10xdevs-home-library --pty -C "php bin/console app:user:create <email>"`, or open an interactive session and run the command inside; update step 4.5 and the Phase 4 manual text.
- **Decision**: FIXED (Phase 4 manual bullet now uses `--pty` or an interactive session)

### F4 — Session handler wiring is left as "confirm against docs"

- **Severity**: 🔹 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Completeness
- **Location**: Phase 3, change 4 (Database sessions)
- **Detail**: Installed code already settles feasibility: `PdoSessionHandler` accepts `\PDO` (`PdoSessionHandler.php:160`), DBAL has `getNativeConnection()` (`Connection.php:1216`), DoctrineBundle has `schema_filter` (`Configuration.php:177`). Sharing the native connection avoids a second connection (cyberfolks limits outside connections per user).
- **Fix**: Make the contract concrete: a `PdoSessionHandler` service whose first argument comes from `@doctrine.dbal.default_connection` via `getNativeConnection`, with `db_table: sessions`.
- **Decision**: FIXED (Phase 3 change 4 now names the concrete wiring)
