<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Design-system pass: new layout for all views

- **Plan**: context/changes/ui-books-list/plan.md
- **Scope**: Full plan
- **Reviewed phases**: 1, 2, 3, 4
- **Date**: 2026-11-13
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 2 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | PASS |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | WARNING |

Automated checks re-run: `php bin/phpunit` 238 tests OK; `npm run e2e` 34 passed (stable on a second run); `lint:twig` OK; read badge defined once; no `pagination` in index; hard-coded scan 0 matches; no raw palette hex in templates; `playwright --list` covers every view. All manual items in Progress are ticked.

## Findings

### F1 — Plan text out of date for the screenshot spec location

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Success Criteria
- **Location**: plan.md:278, plan.md:299
- **Detail**: The plan names `tests/e2e/books.spec.ts` and the check `git ls-files tests/e2e | grep -c png`. The spec is `e2e/books.spec.js` with baselines in `e2e/__screenshots__/`, so the check as written returns 0 although 36 baselines are tracked.
- **Fix**: Update the two plan lines to `e2e/books.spec.js`, `e2e/__screenshots__/` and `git ls-files e2e | grep -c png`.
- **Decision**: FIXED

### F2 — DM Mono added for numbers, recorded only in design-tokens.md

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: assets/fonts/dm-mono-*.woff2, assets/styles/app.css
- **Detail**: The plan names DM Sans and DM Serif Display with tabular figures. DM Mono replaces tabular DM Sans for `.num` (participant decision 2026-11-13, with measurements, in design-tokens.md). The decision is documented, but the plan itself does not mention it.
- **Fix**: Add a one-line scope addendum to plan.md pointing at design-tokens.md.
- **Decision**: FIXED

### F3 — Fixtures live in src/ and are scanned in the production image

- **Severity**: 💡 OBSERVATION
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: src/DataFixtures/, config/services.yaml:17
- **Detail**: `App\: resource: '../src/'` also scans `src/DataFixtures`. The Dockerfile installs with `--no-dev`, so `Doctrine\Bundle\FixturesBundle\Fixture` does not exist in production. Symfony normally skips such classes as unused, so this is probably harmless, but check 1.8 only covered `symfony/ux-icons`, and no production build was run to confirm the container compiles.
- **Fix**: Add `exclude: '../src/DataFixtures/'` for prod (or verify with `composer install --no-dev` followed by `APP_ENV=prod bin/console cache:warmup`).
  - Strength: removes the dependency on Symfony's skip behaviour.
  - Tradeoff: needs a `when@dev`/`when@test` import so fixtures still register in dev and test.
  - Confidence: MED — behaviour known from Symfony, not observed in this repo.
  - Blind spot: No production-mode container build was run.
- **Decision**: FIXED (excluded `src/DataFixtures` in services.yaml, re-registered for dev and test; prod container no longer defines the fixtures)
