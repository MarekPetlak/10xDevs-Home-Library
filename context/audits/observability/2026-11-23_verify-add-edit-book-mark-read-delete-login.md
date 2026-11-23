---
type: observability-audit
date: 2026-11-23 20:30
mode: verify
commit: 2808ae9
branch: main
dirty_tree: true   # uncommitted: ReadinessController, its test, fly.toml (the finding 7 fix); CLAUDE.md (10x-cli block)
areas: [add-edit-book, mark-read-and-delete, login-and-access]
area_source: arguments
runtime_proof: partial   # PHPUnit test for finding 7 only; the earlier audit had no probe harness
error_tracker: none in repo (Monolog -> php://stderr JSON -> Fly logs)
previous_report: context/audits/observability/2026-11-23_add-edit-book-mark-read-delete-login.md
findings: { critical: 0, high: 0, medium: 5, low: 3 }
---

# Observability audit, verify run (2026-11-23)

## 1. TL;DR
- 1 of 9 findings is fixed in the working tree: **finding 7** (`/healthz` always `ok`). It is not committed or deployed yet.
- 8 findings are still open and unchanged (5 medium, 3 low). The only code change since the audit is the readiness route and the `fly.toml` check path.
- The previous report had no runtime probe harness, so this run re-read the cited code and ran the PHPUnit test for the fix. No other finding was reproduced at runtime.
- One citation in the previous report was off by two lines (finding 5), corrected below.

## 2. Capture model
Unchanged from the previous report, except for the new readiness route (`src/Controller/ReadinessController.php`, `GET /readyz`) and the Fly check path (`fly.toml:30`, now `/readyz`). The assumptions (Fly log collection, alert rules, `www.conf` deployed as in the repo) are still unconfirmed.

## 3. What reaches the tracker
Only one row changed:

| Failure shape | Response | Log (stderr JSON) | Verdict |
|---|---|---|---|
| Database down, platform check | `/readyz` 503 `unavailable` | `error` "Readiness check failed" with the exception attached | now visible, once deployed |

## 4. Systemic root causes
1. `fingers_crossed` buffer: still open (`config/packages/monolog.yaml:36-39`).
2. Domain failures handled without logging: still open (`BookController.php:102`, `ReadBookController.php:40`, CSRF checks at `BookController.php:169`, `ReadBookController.php:65`).
3. No correlation context: still open (no Monolog processors in `config/`).
4. Liveness check ignores dependencies: **fixed** for the platform check (`/readyz`); `/healthz` deliberately stays dependency-free.

## 5. Findings by area
Status per finding is in section 7.

## 6. Recommended fix order
Finding 7 is done in the working tree. Next: findings 4, 5, 3, 1 through one shared helper that logs `warning` with user and book ids, then finding 6, then 2, 8, 9. Privacy constraint unchanged: ids and a hashed e-mail, never the e-mail or password.

## 7. Changes since last audit
| Previous id | Status | Evidence |
|---|---|---|
| 1 add/edit `BookConflictException` swallowed | still open | `BookController.php:102-106` catches without logging; no `LoggerInterface` use in `src/` other than `ReadinessController` |
| 2 `AlreadyReadException` swallowed | still open | `ReadBookController.php:40-42` flash only |
| 3 CSRF 400 plain text, no log | still open | `BookController.php:169-170`, `ReadBookController.php:65-66` return a bare `Response` |
| 4 failed logins at `info`, dropped | still open | `monolog.yaml:36-39` unchanged; no security-channel handler or listener |
| 5 403 with no user/book id | still open | `BookController.php:125,167` (previous report said 123,165); `BookVoter` has no logging |
| 6 no request id, user id or release | still open | 0 `processor` entries under `config/` |
| 7 `/healthz` always `ok` | **fixed in working tree, not deployed** | `src/Controller/ReadinessController.php` runs `SELECT 1`, returns 503 and logs the exception on failure; `fly.toml:30` points the check at `/readyz`; `ReadinessControllerTest` 3 tests pass, and a deliberate break (removing the query) made the DB-down test fail |
| 8 `log_limit = 8192` truncation | still open | `docker/php/www.conf` unchanged; limit comes from the base image |
| 9 CLI drops `previous` | still open | `CreateUserCommand.php:44-47`, `ChangePasswordCommand.php:44-48` print `getMessage()` only |

Before/after for finding 7:

| Probe | Before | After |
|---|---|---|
| DB unreachable, platform check | `/healthz` 200 `ok`, check green | `/readyz` 503, `error` log with exception |
| DB unreachable, liveness | 200 | `/healthz` 200 (by design, covered by a test) |

## 8. Method and limits
- Re-read each cited line in the working tree, checked repo-wide counts again (Monolog processors, `LoggerInterface` uses), and ran `php bin/phpunit tests/Controller/ReadinessControllerTest.php` (OK, 3 tests, 8 assertions).
- Not verified: the fix on a deployed Fly machine, because it is uncommitted. Fly log rendering and alert rules remain unverifiable from the repo.
- No probe harness exists, so only finding 7 has runtime evidence (a test double for the database, not a real outage).
