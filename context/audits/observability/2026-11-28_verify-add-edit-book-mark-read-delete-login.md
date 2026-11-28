---
type: observability-audit
date: 2026-11-28 13:45
mode: verify
commit: ea1bd69
branch: main
dirty_tree: true   # only context/changes/sentry-integration/plan.md (progress ticks), not audited code
areas: [add-edit-book, mark-read-and-delete, login-and-access]
area_source: arguments
runtime_proof: partial   # production Sentry checks done by the owner; no probe harness
error_tracker: Sentry (sentry/sentry-symfony) plus Monolog -> php://stderr JSON -> Fly logs
previous_report: context/audits/observability/2026-11-23_verify-add-edit-book-mark-read-delete-login-2.md
findings: { critical: 0, high: 0, medium: 1, low: 5 }
---

# Observability audit, verify run 3 (2026-11-28)

Verifies the findings of `2026-11-23_add-edit-book-mark-read-delete-login.md` on `main` after PR #43 (Sentry integration).

## 1. TL;DR
- Production now has an error tracker. Uncaught 5xx exceptions, readiness failures and the two domain failures (book write conflict, invalid CSRF token) reach Sentry with release (commit SHA), environment `prod` and a numeric user id.
- Finding 1 (book conflict) is fixed. Finding 6 (no release or user id) is fixed for Sentry events; the stderr JSON lines still have no request id.
- Still open: 403 on a foreign edit/delete has no user or book id (5, medium), and four low items (2, 3 UX part, 8, 9).
- Privacy confirmed on production: user id only, no e-mail, IP, cookies, auth headers or form data; 404/403 open no issue.

## 2. Capture model (changes)
- **Tracker:** `config/packages/sentry.yaml`; DSN from `SENTRY_DSN` (empty locally, in CI and tests, which disables the SDK); `send_default_pii: false`; tracing off; `before_send: App\Sentry\DropClientErrors` drops HTTP errors below 500 (`src/Sentry/DropClientErrors.php`).
- **Monolog to Sentry:** `config/packages/monolog.yaml` `sentry` handler (service `Sentry\Monolog\Handler`, level `error`, `config/services.yaml:27-30`), outside `fingers_crossed`, channels `!request`, `!event` so uncaught exceptions are not reported twice.
- **User:** `src/Security/SentryUserContext.php` sets the numeric id on `kernel.controller`; anonymous requests are untouched.
- **Release:** `Dockerfile` build arg `SENTRY_RELEASE`, passed by `.github/workflows/deploy.yml`; `sentry.yaml` `release: '%env(default::SENTRY_RELEASE)%'`.
- **Domain failures:** `src/Service/DomainFailureLogger.php` logs `error` with ids and the original exception, no e-mail, password or token.
- Unchanged: stderr path (`fingers_crossed` + `nested` JSON), `auth` handler for sign-in failures (`LoginFailureLogger`), `/readyz` (`ReadinessController`).

**Assumptions (outside the repo):** Fly log collection works (unconfirmed); Sentry alert rules for new issues exist (unconfirmed, the owner saw issues in the UI but alert delivery was not tested).

## 7. Changes since last audit
| Previous id | Status | Evidence |
|---|---|---|
| 1 `BookConflictException` swallowed | **fixed** | `BookController.php:103-104` and `:146-148` call `DomainFailureLogger::bookConflict` (`error`, user id, book id, exception with `previous`); `DomainFailureLoggingTest`, `DomainFailureLoggerTest`. Not reproduced on production (a real conflict needs a race) |
| 2 `AlreadyReadException` swallowed | still open | `ReadBookController.php:41-43` flash only, no log |
| 3 CSRF 400 plain text, no log | **changed** | Logging fixed: `BookController.php:173-175`, `ReadBookController.php:66-67` call `invalidCsrfToken` (`error`, route, user id, book id). Confirmed on production: the forged delete produced a Sentry issue with user id only. Remaining: the response is still a bare unstyled `Response` 400 (`BookController.php:177`, `ReadBookController.php:69`), now low |
| 4 failed logins dropped | fixed (unchanged) | `LoginFailureLogger`, `auth` handler; not re-checked on production |
| 5 403 with no user or book id | still open | `BookController.php:127,171` use `denyAccessUnlessGranted`; `BookVoter.php` does not log. `DropClientErrors` now drops the 403 from Sentry (intended, no issue opened, confirmed for 404 on production), so the only trace is the kernel's `error` line on stderr without user or book id |
| 6 no request id, user id or release | **changed** | Sentry events have release (SHA matched `git rev-parse HEAD`), environment `prod` and user id (confirmed on production). Still 0 Monolog processors under `config/`, so stderr JSON has no request or user id; severity medium to low |
| 7 `/healthz` always `ok` | fixed (confirmed on production in verify 2) | `ReadinessController`, `fly.toml` check |
| 8 `log_limit = 8192` truncation | still open | `docker/php/www.conf` has no `log_limit`; limit from the base image. Less important now that exceptions also go to Sentry |
| 9 CLI drops `previous` | still open | `CreateUserCommand.php:43-47`, `ChangePasswordCommand.php:42-46` print `getMessage()` only |

Probes confirmed by the owner on production (2026-11-28):
| Probe | Result |
|---|---|
| `sentry:test` on the Fly machine | event in Sentry, environment `prod`, release equals deployed SHA |
| Forged CSRF token on a book delete, signed in | issue with user id only; no e-mail, no IP, no Cookie/Authorization/forwarded-IP headers, no form data |
| `/books/999999` (404) | no issue |
| Foreign book edit (403) | not tested (no second account); covered by `tests/Sentry/SentryReportingTest.php` (403 and 404 yield no event) |

## 8. Method and limits
- Re-read each cited line on `main` at `ea1bd69`, re-checked `config/packages/{sentry,monolog}.yaml`, `config/services.yaml`, `src/Sentry`, `src/Security`, `src/Service/DomainFailureLogger.php`, both controllers and both CLI commands. Counted Monolog processors under `config/` (0).
- Did not re-run PHPUnit or PHPStan in this run (both passed at phase 4 of the change, `plan.md` 4.1-4.2).
- Not verified: alert rule delivery, Fly log rendering, a real database outage on production, the 403 path on production, a real uncaught 500 (the CSRF probe goes through the Monolog handler, not the kernel exception listener).
- No probe harness; evidence is code, tests and the owner's manual production checks.
