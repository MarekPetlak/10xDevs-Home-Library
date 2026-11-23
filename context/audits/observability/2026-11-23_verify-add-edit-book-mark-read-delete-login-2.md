---
type: observability-audit
date: 2026-11-23 20:43
mode: verify
commit: 407f6b0
branch: main
dirty_tree: false
areas: [add-edit-book, mark-read-and-delete, login-and-access]
area_source: arguments
runtime_proof: partial   # PHPUnit + production GET of /healthz and /readyz; no real outage was induced
error_tracker: none in repo (Monolog -> php://stderr JSON -> Fly logs)
previous_report: context/audits/observability/2026-11-23_add-edit-book-mark-read-delete-login.md
findings: { critical: 0, high: 0, medium: 4, low: 3 }
---

# Observability audit, verify run 2 (2026-11-23)

Verifies the original audit report on `main` after PR #40 (`/readyz`) and PR #41 (sign-in failure logging) were merged.

## 1. TL;DR
- **2 of 9 findings are fixed on `main`:** finding 7 (readiness check) and finding 4 (failed and throttled sign-ins).
- **7 findings are still open and unchanged:** 4 medium (1, 3, 5, 6) and 3 low (2, 8, 9).
- Finding 7 is now confirmed on production: the deploy of `e947241` succeeded and `/readyz` answers 200 there. The failing path (503) was only exercised in PHPUnit, not against a real outage.
- Finding 4 is verified by tests and by a prod-kernel log probe, not by a real sign-in on production. The deploy of `407f6b0` was still running when this report was written.
- The earlier verify report (`..._verify-add-edit-book-mark-read-delete-login.md`) predates the finding 4 fix; this one supersedes it.

## 2. Capture model
Changes since the original report:
- New `auth` Monolog channel with its own `warning`-level stderr JSON handler, excluded from the buffered `main` handler (`config/packages/monolog.yaml:4,41,48-53`).
- `App\Security\LoginFailureLogger` listens to `LoginFailureEvent` and logs a keyed hash of the username, client IP, `throttled`, exception class and firewall.
- `GET /readyz` runs `SELECT 1`; the Fly check uses it (`fly.toml:30`).

Assumptions are unchanged and still unconfirmed: Fly log collection works and is read, an alert rule exists for `critical` lines, the container runs the repo's `www.conf`. A new one: someone watches the `auth` channel (`channel":"auth"` lines), since no alert rule exists in the repo for it.

## 3. What reaches the tracker
| Failure shape | Response | Log (stderr JSON) | Verdict |
|---|---|---|---|
| Wrong password / throttled login | login page | `warning` on channel `auth`, always written | **now visible** |
| Database down, platform check | `/readyz` 503 | `error` with exception | **now visible** |
| `BookConflictException` | 400 + form error | nothing | invisible |
| `AlreadyReadException` | redirect + flash | nothing | invisible |
| Invalid CSRF token | 400 plain text | nothing | invisible |
| Foreign edit/delete (Voter) | 403 | `error`, no user or book id | visible, undiagnosable |

## 4. Systemic root causes
1. `fingers_crossed` hides non-error events: **mitigated** for sign-ins through the `auth` channel; the buffer still hides every other `info`/`warning` event.
2. Domain failures handled without logging: still open.
3. No correlation context on log lines: still open (0 Monolog processors under `config/`; the new `auth` lines carry their own fields only).
4. Liveness check ignored dependencies: **fixed** (`/readyz`).

## 5. Findings by area
Status per finding is in section 7.

## 6. Recommended fix order
Done: 7, 4. Next: findings 3, 5, 1 through one shared helper that logs `warning` on a dedicated channel with user and book ids (the `auth` channel is the pattern to copy: own always-on handler, not behind `fingers_crossed`), then 6 (request id, user id, release processors), then 2, 8, 9. Privacy constraint unchanged: ids and keyed hashes, never e-mail or password.

## 7. Changes since last audit
| Previous id | Status | Evidence |
|---|---|---|
| 1 `BookConflictException` swallowed | still open | `BookController.php:102-106` catch without logging |
| 2 `AlreadyReadException` swallowed | still open | `ReadBookController.php:40-42` flash only |
| 3 CSRF 400 plain text, no log | still open | `BookController.php:169-170`, `ReadBookController.php:65-66` return a bare `Response` |
| 4 failed logins at `info`, dropped | **fixed** | `src/Security/LoginFailureLogger.php`, `monolog.yaml` `auth` handler; `LoginFailureLoggerTest` (4) and `LoginFailureLoggingTest` (real firewall) pass; a prod-kernel probe wrote `{"channel":"auth","level_name":"WARNING"}` to stderr immediately. Not verified: a real sign-in on a prod-env server (local prod boot returned 500 on the DB connection, unrelated) and on production |
| 5 403 with no user or book id | still open | `BookController.php:125,167` use `denyAccessUnlessGranted`; `BookVoter` has no logging |
| 6 no request id, user id or release | still open | 0 `processor` entries under `config/` |
| 7 `/healthz` always `ok` | **fixed, confirmed on production** | `ReadinessController`, `fly.toml:30`; deploy `e947241` success; `GET https://10xdevs-home-library.fly.dev/readyz` returned 200 and `/healthz` 200. The 503 path is covered only by PHPUnit |
| 8 `log_limit = 8192` truncation | still open | `docker/php/www.conf` unchanged; limit from the base image |
| 9 CLI drops `previous` | still open | `CreateUserCommand.php:45`, `ChangePasswordCommand.php:45` print `getMessage()` only |

Before/after for the two fixed findings:

| Probe | Before | After |
|---|---|---|
| Wrong password on `/login` | `info`, dropped by `fingers_crossed`, no trace | `warning` on `auth`, written immediately, hashed username + IP |
| Throttled sign-in (6th attempt) | same, invisible | same line with `throttled: true` (unit-tested, not reproduced against a server) |
| DB unreachable, platform check | `/healthz` 200, check green | `/readyz` 503 with `error` log (PHPUnit test double) |

## 8. Method and limits
- Re-read each cited line on `main` at `407f6b0`, re-ran the counts (Monolog processors, `LoggerInterface` users: only `ReadinessController` and `LoginFailureLogger`), ran `php bin/phpunit tests/Security tests/Controller/ReadinessControllerTest.php` (17 tests OK), and requested `/healthz` and `/readyz` on production (GET only).
- Not verified: the `407f6b0` deploy (in progress), real brute-force or database outage on production, Fly log rendering and alert rules.
- No probe harness exists, so before/after evidence is test-based plus the two production GETs.
