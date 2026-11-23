---
type: observability-audit
date: 2026-11-23 20:13
mode: audit
commit: 2808ae9
branch: main
dirty_tree: true   # only CLAUDE.md (10x-cli block), not audited code
areas: [add-edit-book, mark-read-and-delete, login-and-access]
area_source: user
runtime_proof: not-run
error_tracker: none in repo (Monolog -> php://stderr JSON -> Fly logs)
previous_report: null
findings: { critical: 0, high: 1, medium: 5, low: 3 }
---

# Observability audit — add/edit book, mark read + delete, login and access (2026-11-23)

## 1. TL;DR
- The app has no error tracker SDK, only Monolog (JSON on stderr). The audit assumes the platform collects those logs and judges what the code puts into them.
- **Root cause 1:** Monolog `fingers_crossed` at `error` drops every `info`/`warning` event unless an `error` happens in the same request. Login failures and throttling leave no trace.
- **Root cause 2:** domain failures are turned into ordinary 400/flash responses without an exception reaching the kernel (CSRF, `BookConflictException`, `AlreadyReadException`), so nothing is logged.
- **Root cause 3:** log lines carry no request id, user id or route, and nothing identifies the deployed release.
- **Root cause 4:** `/healthz` always answers `ok`, so a dead database never fails the platform check.
- No finding is `critical`: genuine 5xx exceptions (including a DB outage) do reach the log at `critical` with the exception object.

## 2. Capture model
- **Runtime:** nginx + php-fpm 8.4 under supervisord in one Fly machine (`Dockerfile:6`, `docker/supervisord.conf`, `fly.toml`). `APP_ENV=prod` (`fly.toml:8`).
- **Tracker:** none. `composer.json` has only `symfony/monolog-bundle` (line 28); no Sentry/Datadog/OTel package.
- **Logging path:** `config/packages/monolog.yaml:32-60` (`when@prod`): `main` is `fingers_crossed`, `action_level: error`, `excluded_http_codes: [404, 405]`, `buffer_size: 50`, flushing to `nested` (`php://stderr`, JSON formatter). No processors configured (`grep processor config/` is empty).
- **Worker output:** `docker/php/www.conf:10-11` sets `catch_workers_output = yes` and `decorate_workers_output = no`, so JSON lines reach Fly intact. The base image sets `log_limit = 8192` (`php:8.4-fpm-alpine`, `php-fpm.d/docker.conf:5`, read from the image), so very long stack traces can still be cut.
- **Exception logging:** Symfony's `ErrorListener` logs every uncaught exception, level `critical` for 5xx and non-HTTP exceptions, `error` for 4xx (`vendor/symfony/http-kernel/EventListener/ErrorListener.php:177-190`), with `['exception' => $e]`.
- **Auth failures:** logged by Symfony at `info` only (`vendor/symfony/security-http/Authentication/AuthenticatorManager.php:249`).
- **Deploy identity:** no release/environment tag in code; only `APP_ENV=prod`.

**Assumptions (outside the repo, not findings), all unconfirmed:**
- Fly log collection works and someone reads `fly logs`.
- Some alert or log-shipping rule exists for `critical` lines. If none does, even real outages are only visible by reading logs.
- The deployed container runs the repo's `docker/php/www.conf`.

## 3. What reaches the tracker
Static only (no runtime probes were run). There is no tracker, so "Log" is the Monolog stderr stream.

| Failure shape | Response | Log (stderr JSON) | Verdict |
|---|---|---|---|
| Uncaught exception / DB down in a controller | 500 error page | `critical` with exception, buffered lines flushed | visible |
| Foreign edit/delete attempt (Voter) | 403 | `error` with `AccessDeniedException`, no user or book id | visible, undiagnosable |
| Wrong password / throttled login | login page with message | `info` only, dropped by `fingers_crossed` | invisible |
| `BookConflictException` (add/edit) | 400 + form error | nothing | invisible |
| `AlreadyReadException` | redirect + flash | nothing | invisible |
| Invalid CSRF token (delete, un-read) | 400 plain text | nothing | invisible |
| Database down, Fly health check | `/healthz` 200 `ok` | nothing | check never fails |

## 4. Systemic root causes
1. **`fingers_crossed` buffer hides non-error events.** `config/packages/monolog.yaml:36-39`. A sensible noise reduction, but every `info`/`warning` signal becomes conditional on an unrelated error in the same request. Explains finding 4.
2. **Domain failures are handled as control flow without logging.** `BookController.php:102`, `:144`, `ReadBookController.php:40`, CSRF checks at `BookController.php:169` and `ReadBookController.php:65`. Each is a reasonable local decision, but the catch discards the exception (and its `previous`) and nothing is logged. Explains findings 1, 2, 3.
3. **No correlation context on any log line.** No processors in `monolog.yaml`; nginx `access_log /dev/stdout` uses the default format with no request id (`docker/nginx/default.conf:8`). Explains findings 5, 6.
4. **Liveness check ignores dependencies.** `src/Controller/HealthController.php:12-20` returns a constant while sessions live in the database (`config/packages/framework.yaml`, `session.handler.pdo` in prod). Explains finding 7.

## 5. Findings by area

### Add / edit book
| # | Location | Category | Severity | What happens in production | Fix direction |
|---|---|---|---|---|---|
| 1 | `BookController.php:102,144`, `BookManager.php:39,60` | swallowed | medium | Any `UniqueConstraintViolationException` (author name, tag name, tag slug) becomes "concurrent request, try again". A non-race cause (a slug/collation mismatch) would repeat on every attempt: the user is told to retry, nothing is logged, the responder sees no event. | Log the `BookConflictException` (with `previous`) at `warning` with book and user ids; alert on repeats, not on one. |

### Mark read and delete
| # | Location | Category | Severity | What happens in production | Fix direction |
|---|---|---|---|---|---|
| 2 | `ReadBookController.php:40` | swallowed | low | `AlreadyReadException` becomes a flash `warning`. An expected race, but it also hides a double-submit pattern. | Log at `info` with user and book ids. |
| 3 | `BookController.php:169`, `ReadBookController.php:65` | flattened-response | medium | An invalid CSRF token returns a bare 400 text body, with no exception and no log. An expired session and a forged request look the same, and the user sees an unstyled text page. | Throw `InvalidCsrfTokenException`/`BadRequestHttpException` (logged by the kernel) or log `warning` with route and user id; render the app error page. |

### Login and access
| # | Location | Category | Severity | What happens in production | Fix direction |
|---|---|---|---|---|---|
| 4 | `monolog.yaml:36-39`; `AuthenticatorManager.php:249` | logged-not-captured | medium | Failed logins and throttled attempts are `info` and dropped by `fingers_crossed`. A password-guessing attack against `security.yaml:33-34` (5 attempts) is invisible to responders. | Add a `security` channel handler or a login-failure listener that logs `warning` with a hashed e-mail and client IP. |
| 5 | `BookVoter.php`, `BookController.php:123,165` | missing-context | medium | A foreign edit/delete attempt is logged `error` (403), but the line has no user id or book id, so "who tried to touch whose book" cannot be answered. Each 403 also flushes the 50-line buffer. | Log the denial with user id and book id at `warning`; add `AccessDeniedHttpException` to `excluded_http_codes` once it is logged explicitly. |

### Platform / plumbing
| # | Location | Category | Severity | What happens in production | Fix direction |
|---|---|---|---|---|---|
| 6 | `config/packages/monolog.yaml:32-60` | missing-context | medium | No request id, route, user id or release on any JSON line; nginx and PHP lines cannot be joined to one request. | Add `UidProcessor`/`WebProcessor` and a processor adding the user id (id, not e-mail) and release. |
| 7 | `src/Controller/HealthController.php:12-20` | flattened-response | high | With the database down every page is a 500 (sessions are in PDO), but `/healthz` returns 200 `ok`, so Fly neither restarts nor flags the machine. | Add a readiness route that runs `SELECT 1`; keep a separate liveness route. |
| 8 | `docker/php/www.conf` / base image | identity-lost | low | `log_limit = 8192` truncates a JSON line whose stack trace is longer; the exception detail is cut. | Keep traces short or raise `log_limit` in the global `php-fpm.conf`. |
| 9 | `ChangePasswordCommand.php:44`, `CreateUserCommand.php:44` | identity-lost | low | The CLI prints `$e->getMessage()` and drops `previous`, so the DB error behind a failed user creation is lost. | Print the previous message at `-v`. |

## 6. Recommended fix order
1. Finding 7 (health check): one small route, removes the only "everything is broken but green" state.
2. Findings 4, 5, 3, 1: log the domain and security events explicitly at `warning` with ids, through one shared helper. Privacy: log user id and a hashed e-mail, never the e-mail or password.
3. Finding 6: processors for request id, user id and release.
4. Findings 2, 8, 9: polish.

## 7. Changes since last audit
First run, nothing to compare.

## 8. Method and limits
- Audit run inline by one agent, not fanned out to subagents: the code is small (9 `catch` blocks, 6 controllers, 3 services).
- Hand-checked: Symfony log levels in `ErrorListener` and `AuthenticatorManager`, the php-fpm image config (pulled `php:8.4-fpm-alpine` locally to read it), `www.conf`, the Monolog prod handler.
- Not run: runtime probes. Findings are static and none was reproduced.
- Not verifiable from the repo: Fly log collection, alert rules, how `fly logs` renders the JSON lines.
- Sweep: 9 `catch (` in `src/`, 0 uses of `LoggerInterface`, 0 Monolog processors.
