<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Sentry integration

- **Plan**: context/changes/sentry-integration/plan.md
- **Scope**: Full plan
- **Reviewed phases**: 1, 2, 3, 4
- **Date**: 2026-11-28
- **Verdict**: APPROVED
- **Findings**: 0 critical, 1 warning, 3 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | PASS |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

Success criteria run on `main` at `5302750`: `php bin/phpunit` OK (412 tests, 1658 assertions; needed the local `database` compose service running), `vendor/bin/phpstan analyse` OK (no errors). After triage (F1, F2, F4 fixed): 413 tests, 1660 assertions, PHPStan OK, `lint:container --env=prod` OK. Manual items 4.5-4.8 are ticked with evidence from the owner's production checks and `context/audits/observability/2026-11-28_verify-add-edit-book-mark-read-delete-login.md`. Extras beyond the plan are test-only (`ProbeController`, `config/routes/test/sentry_probe.yaml`, `config/services_test.yaml`, `RecordingLogger`, scope test) and env-scoped.

## Findings

### F1 — Request body and query string may still be sent to Sentry

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: config/packages/sentry.yaml:13
- **Detail**: `send_default_pii: false` does not control body collection. With no `data_collection` option the SDK runs in legacy mode, where the body depends on `max_request_body_size`, default `medium` (`vendor/sentry/sentry/src/Options.php:1579`, `DataCollectionPolicy.php:126`), and `RequestIntegration.php:133-136` attaches the parsed POST body to every event; `LegacyRequestBodyCollector` does no key filtering. A 5xx on `POST /login` could ship `_username`, `_password`, `_csrf_token`. The comment in `sentry.yaml` and the plan ("no request bodies") are therefore only half true. At review time this was read from the SDK source only, and the owner's production check of the forged-delete event showed no form data. During triage a probe POST that threw a 500 reproduced the leak locally: the event carried `_username`, `_password` and `_csrf_token` under `request.data`. Not tested on production. Query strings are also collected in legacy mode.
- **Fix**: Set `max_request_body_size: none` under `options`, and add a test in `tests/Sentry/SentryReportingTest.php` that a POST which throws a 5xx yields an event with no `request.data`.
  - Strength: One line closes the password-leak path and makes the plan's own claim true.
  - Tradeoff: No body context when debugging a failing form post.
  - Confidence: MEDIUM — read in vendor source, not reproduced end to end.
  - Blind spot: Query-string handling in legacy mode (may need a `before_send` strip).
- **Decision**: FIXED via Fix now. Red first: `SentryReportingTest::testRequestBodiesAreNeverCollected` failed on `medium`; `max_request_body_size: none` added in `config/packages/sentry.yaml`. Reproduced the leak with a probe POST: the event carried `_username`, `_password`, `_csrf_token` under `request.data`. The query string is still collected (not changed). Side finding: the SDK attaches request data only for the first kernel booted in a process, so in a full run `testEventsCarryNoPersonalData` inspects an empty `request` and its cookie/IP assertions are vacuous; `#[RunInSeparateProcess]` did not help, so the new test asserts the option instead. Not fixed.

### F2 — Console and PHP error channels may report twice

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: config/packages/monolog.yaml:47
- **Detail**: The handler excludes `request` and `event` only. A CLI error may be reported by the SDK console listener and again through the `console` channel; PHP fatals the same via the `php` channel. Reported by the review subagent from reading the code; not reproduced.
- **Fix**: Add `!console` and `!php` to the `sentry` handler channels, or confirm duplicates collapse by fingerprint.
- **Decision**: FIXED via Fix now. Added `!console` and `!php` to the `sentry` handler channels in both `when@test` and `when@prod` of `config/packages/monolog.yaml`. Not reproduced before or after (no test); Sentry/domain-logging/service tests (118) and `lint:container --env=prod` pass.

### F3 — Invalid CSRF token logs at `error`, one Sentry event per request

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/DomainFailureLogger.php:36
- **Detail**: An expired session or stale form produces a Sentry event each time. The plan chose `error` on purpose so the failure reaches Sentry, and household scale keeps volume low, so this is a tradeoff to watch, not a defect.
- **Fix**: If it gets noisy, lower to `warning` plus a dedicated channel, or rate-limit in Sentry.
- **Decision**: SKIPPED. Keeps `error` as the plan intends; watch the volume in Sentry.

### F4 — Stale docs: HUMAN checkboxes and a misplaced comment

- **Severity**: 💡 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: context/deployment/deploy-plan.md:361,364; config/services.yaml:24-30
- **Detail**: The two Sentry HUMAN steps in `deploy-plan.md` are still `[ ]` although they are done. In `services.yaml` the Sentry handler block sits between the "Sessions live in MariaDB…" comment and `session.handler.pdo`, so that comment now describes the wrong service.
- **Fix**: Tick both boxes, and move the Sentry handler block above the sessions comment.
- **Decision**: FIXED via Fix now. Ticked both HUMAN boxes in `context/deployment/deploy-plan.md`; moved the Sentry handler block above the sessions comment in `config/services.yaml` (no behaviour change, `lint:container --env=prod` OK).
