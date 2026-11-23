# Sentry integration Implementation Plan

## Overview

Production errors reach Sentry as issues instead of living only in `fly logs`. Uncaught exceptions and Monolog records at `error` and above are reported, with no personal data (only the numeric user id), without HTTP 4xx, without performance tracing, and tagged with the deployed commit. The two audit findings that would otherwise stay invisible to the tracker (domain failures handled without any log: audit findings 1 and 3) are logged at `error` so they reach it.

## Current State Analysis

- There is no tracker: `composer.json` has only `symfony/monolog-bundle` (line 28). The roadmap parks "external error tracking" as a user intent without PRD coverage (`context/foundation/roadmap.md:65,236`).
- Logging path in prod: `main` is `fingers_crossed` at `error`, flushing to `php://stderr` as JSON; a separate always-on `auth` handler exists for failed sign-ins (`config/packages/monolog.yaml:32-60`).
- Symfony logs uncaught exceptions on the `request` channel (`vendor/symfony/framework-bundle/Resources/config/web.php:52`) with the exception attached, at `critical` for 5xx and `error` for 4xx (`vendor/symfony/http-kernel/EventListener/ErrorListener.php:177-190`).
- Domain failures are handled as control flow with no log: `BookConflictException` (`src/Controller/BookController.php:102,144`), invalid CSRF tokens returned as a bare 400 (`BookController.php:169`, `ReadBookController.php:65`).
- Deploy: secrets via `fly secrets` (`fly.toml:11`), CI deploys with `flyctl deploy --remote-only` (`.github/workflows/deploy.yml`), and the image build has no `.git` (`Dockerfile:18-24`), so a release SHA has to arrive as a build argument.
- Tests and PHPStan run in CI with no DSN (`.github/workflows/deploy.yml`), and tests must never send real events.
- Library: `sentry/sentry-symfony` 5.17.0 with `sentry/sentry` 4.38.0 resolves against Symfony 8.1 with no conflicts (checked with `composer require --dry-run`, `composer.lock` unchanged). Its default HTTP transport is cURL based.

## Desired End State

With a `SENTRY_DSN` secret set on Fly, an uncaught exception or a logged `error` in production becomes a Sentry issue with the environment `prod`, the git SHA as release and the numeric user id (when signed in). No e-mail, IP, cookies or request bodies are sent. 404/405/403 and other 4xx never open issues. A lost add/edit race and an invalid CSRF token appear as `error` issues with user and book ids. With no `SENTRY_DSN` (local, CI, tests) the SDK is disabled and nothing leaves the machine.

Verified by PHPUnit with a spy transport (no network), PHPStan, `lint:container` in `prod`, then one `bin/console sentry:test` event after deploy and an `/10x-observability-audit --verify` run.

### Key Discoveries:

- Symfony reports an uncaught exception twice by default if both the SDK error listener and a Monolog handler see it; the Monolog handler must skip the `request` channel (`web.php:52`).
- `Sentry\Monolog\Handler` is registered as a service and referenced from `monolog.yaml` with `type: service` (sentry-symfony docs); `bubble: true` keeps the existing stderr logging.
- `ignore_exceptions` matches by class (`is_a`), so it cannot separate 4xx from 5xx (both are `HttpException`); a `before_send` callback that checks the status code does.
- The user id needs a small listener: the bundle's own request listener only sets user data when `send_default_pii` is on.
- `bin/console sentry:test` validates the DSN and sends a test message (exit 0 or 1).
- Lessons from `context/foundation/lessons.md`: put shared code in an injectable service with its own test, never a trait; decide behaviour from exception classes, not message text.

## What We're NOT Doing

- Performance tracing, profiling or session replay (`enable_tracing` stays off).
- Sending e-mail, IP, cookies, headers or request bodies (`send_default_pii` stays false).
- Reporting sign-in failures (`auth` channel, `warning`) to Sentry; they stay in stderr logs.
- Audit findings 2 (`AlreadyReadException`), 5 (403 context), 6 (request id processors), 8, 9. Findings 1 and 3 only.
- Alert rules, notification channels or Sentry project setup (done by the owner in the Sentry UI).
- Source maps or JS error tracking (the app ships no application JavaScript worth tracking).
- Changing the readiness check or `fly.toml` health checks.
- Pinning the existing `composer audit` advisories (13 packages); unrelated to this change.

## Implementation Approach

Four phases, each shippable on its own and in this order: wire the SDK and prove the filtering offline, add the user id, make the two domain failures observable, then thread the release tag through the image and CI and roll out. Each phase starts with a failing test where behaviour changes. The SDK stays disabled without a DSN, so merging phases 1-3 is safe before the Sentry project exists.

## Critical Implementation Details

- **Timing & lifecycle**: the SDK sends synchronously while the request is finishing. Cap `http_connect_timeout` and `http_timeout` in `sentry.yaml` (a few seconds) so an unreachable Sentry cannot hang the error response on a 1-CPU machine.
- **Debug & observability**: the spy transport in tests must replace the transport entirely; use a syntactically valid fake DSN in `when@test` so the client is created, and assert no event reaches the network (verify the SDK builds a client with the custom transport and a fake DSN before relying on it).

## Phase 1: Sentry SDK and configuration

### Overview

Install the SDK, configure it privacy-first and quiet, add the Monolog handler, and prove with an offline test that exceptions are captured and 4xx are not.

### Changes Required:

#### 1. Dependency and bundle

**File**: `composer.json`, `composer.lock`, `config/bundles.php`

**Intent**: Add `sentry/sentry-symfony` and let Symfony Flex/composer register the bundle for all environments.

**Contract**: `sentry/sentry-symfony ^5.17` in `require`; `Sentry\SentryBundle\SentryBundle::class => ['all' => true]`.

#### 2. Bundle configuration

**File**: `config/packages/sentry.yaml`, `.env`

**Intent**: Configure the SDK from the environment, disabled when `SENTRY_DSN` is empty, no PII, no tracing, no HTTP 4xx, bounded timeouts.

**Contract**: `dsn: '%env(SENTRY_DSN)%'`; `.env` gets `SENTRY_DSN=` (empty default). `options`: `environment: '%kernel.environment%'`, `send_default_pii: false`, `enable_tracing: false`, `http_connect_timeout` and `http_timeout` set to a few seconds, and a `before_send` service (named by container id, no `@`) that drops events whose exception is an HTTP exception with a status below 500 (an `ignore_exceptions` entry cannot do this, because `HttpException` also covers 5xx). `register_error_listener: true`.

#### 3. Monolog handler

**File**: `config/services.yaml`, `config/packages/monolog.yaml`

**Intent**: Report explicitly logged errors (readiness failure, domain failures) without double-reporting uncaught exceptions.

**Contract**: service `Sentry\Monolog\Handler` with `$hub: '@Sentry\State\HubInterface'`, `$level: ERROR`, `$bubble: true`; in `when@prod`, a `sentry` handler `type: service` that excludes the `request` channel (uncaught exceptions are already captured by the error listener) and is not wrapped in `fingers_crossed`, so stderr logging via `main` is unchanged.

#### 4. Test transport and tests

**File**: `tests/Support/SpyTransport.php` (new), `config/services.yaml`/`config/packages/sentry.yaml` (`when@test`), `tests/Sentry/SentryReportingTest.php` (new)

**Intent**: Capture events in memory in tests with a fake DSN so nothing touches the network.

**Contract**: `SpyTransport implements Sentry\Transport\TransportInterface` exposing the sent events; `when@test` sets `options.transport` to it and `dsn` to a fake value. Tests: an uncaught exception on a real route (database stubbed as in `ReadinessControllerTest`) yields exactly one event; a 404 and a foreign edit (403) yield none; a captured event carries no `user.email`, `user.ip_address` or request cookies/body; with the DSN empty the client is disabled.

### Success Criteria:

#### Automated Verification:

- New tests fail first, then pass: `php bin/phpunit tests/Sentry`
- Full suite passes: `php bin/phpunit`
- Static analysis passes: `vendor/bin/phpstan analyse --no-progress`
- Prod container compiles: `APP_ENV=prod ... php bin/console lint:container --env=prod`
- YAML is valid: `php bin/console lint:yaml config`

#### Manual Verification:

- With `SENTRY_DSN` empty, `php bin/console sentry:test` reports the missing DSN and the app boots in dev and prod modes.
- `monolog.yaml` shows the `sentry` handler outside `fingers_crossed` and excluding `request`.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 2: User id on events

### Overview

Attach only the numeric id of the signed-in user to Sentry events, so an error can be tied to a household member without personal data.

### Changes Required:

#### 1. User context listener

**File**: `src/Security/SentryUserContext.php` (new), `tests/Sentry/SentryUserContextTest.php` (new)

**Intent**: After the firewall has authenticated the request, set the Sentry scope user to the id only; leave it empty for anonymous requests.

**Contract**: an event listener on the controller (or a request event after the firewall) that reads the current user from the token storage and calls `HubInterface::configureScope` with a `UserDataBag` holding the id as a string, no e-mail, username or IP. Tests (failing first): a signed-in request that throws yields an event whose user id equals the account id and has no e-mail or IP; an anonymous request yields an event without a user.

### Success Criteria:

#### Automated Verification:

- Listener tests pass: `php bin/phpunit tests/Sentry`
- Full suite passes: `php bin/phpunit`
- Static analysis passes: `vendor/bin/phpstan analyse --no-progress`

#### Manual Verification:

- The listener never touches the scope for anonymous requests and does not log or send the e-mail (read the diff).

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 3: Domain failures reach the error stream

### Overview

Audit findings 1 and 3: a lost add/edit race and an invalid CSRF token become `error` log records with user and book ids, so the Monolog handler forwards them to Sentry and stderr.

### Changes Required:

#### 1. Domain failure logger service

**File**: `src/Service/DomainFailureLogger.php` (new), `tests/Service/DomainFailureLoggerTest.php` (new)

**Intent**: One small injectable service that logs these failures consistently (lesson: service over trait), at `error`, with ids and the original exception, never the e-mail.

**Contract**: methods for a book write conflict (action `add` or `edit`, user id, optional book id, the `BookConflictException` whose `previous` is the database error) and for an invalid CSRF token (route name, user id, optional book id). Context keys are ids and names only; the exception is passed under `exception` so its cause is kept.

#### 2. Controllers use it

**File**: `src/Controller/BookController.php`, `src/Controller/ReadBookController.php`, matching controller tests

**Intent**: Call the service in the `BookConflictException` catches (`BookController.php:102,144`) and in the CSRF branches (`BookController.php:169`, `ReadBookController.php:65`) without changing the responses users see.

**Contract**: behaviour and status codes unchanged. Failing-first tests: a conflict on add and on edit, and an invalid token on delete and on un-read, each produce one `error` record with the expected context keys (logger replaced in the test container) and the same response as before.

### Success Criteria:

#### Automated Verification:

- New logging tests fail first, then pass: `php bin/phpunit tests/Service/DomainFailureLoggerTest.php tests/Controller`
- Full suite passes: `php bin/phpunit`
- Static analysis passes: `vendor/bin/phpstan analyse --no-progress`

#### Manual Verification:

- A forged delete (bad token) still shows the same 400 text to the user and now leaves an `error` line on stderr locally.
- No log context contains an e-mail address or password (read the diff).

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Phase 4: Release tag, CI and rollout

### Overview

Tag every event with the deployed commit, document the secrets, deploy, and prove one real event end to end.

### Changes Required:

#### 1. Release from the commit SHA

**File**: `Dockerfile`, `.github/workflows/deploy.yml`, `config/packages/sentry.yaml`

**Intent**: Bake the commit SHA into the image so every event names the exact deploy.

**Contract**: `ARG SENTRY_RELEASE` and `ENV SENTRY_RELEASE=$SENTRY_RELEASE` in the final stage; the deploy step passes `--build-arg SENTRY_RELEASE=${{ github.sha }}` to `flyctl deploy`; `options.release: '%env(default::SENTRY_RELEASE)%'` so a build without the argument simply has no release.

#### 2. Rollout notes

**File**: `context/deployment/deploy-plan.md` (short Sentry section)

**Intent**: Record the secret and the smoke test so the next person can reproduce the setup.

**Contract**: `fly secrets set SENTRY_DSN=... -a 10xdevs-home-library`, the `bin/console sentry:test` command, and the fact that the DSN is empty in local/CI. No DSN value is written to the repository.

### Success Criteria:

#### Automated Verification:

- Full suite passes: `php bin/phpunit`
- Static analysis passes: `vendor/bin/phpstan analyse --no-progress`
- Image still builds the prod cache: `docker build .` (or the CI deploy job on the PR branch is skipped and the `test` job is green)
- Workflow file is valid YAML: `php bin/console lint:yaml .github/workflows`

#### Manual Verification:

- The owner creates the Sentry project and sets the `SENTRY_DSN` Fly secret.
- After deploy, `fly ssh console -C "php bin/console sentry:test"` reports success and the event appears in Sentry with environment `prod` and the commit SHA as release.
- A deliberate error on production shows the user id and no e-mail, IP or cookies; a 404 URL opens no issue.
- Run `/10x-observability-audit --verify` on the original audit report and record the changes.

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase.

---

## Testing Strategy

### Unit Tests:

- `DomainFailureLogger` writes `error` records with ids, the original exception and no e-mail.
- `before_send` filter drops HTTP exceptions below 500 and keeps 5xx and non-HTTP exceptions.

### Integration Tests:

- Spy transport: an uncaught exception yields one event (no duplicate from the Monolog handler); 404 and 403 yield none; events carry the user id only; the client is disabled with an empty DSN.
- Controller tests: conflict and CSRF paths log at `error` and keep their responses.

### Manual Testing Steps:

1. Set `SENTRY_DSN` on Fly and deploy; run `sentry:test` on the machine.
2. Trigger a deliberate server error as a signed-in user and check the issue's user, release and environment.
3. Open a missing URL and a foreign book's edit page; confirm no issues appear.

## Performance Considerations

Tracing is off, so no span work per request. The SDK sends synchronously only when an event occurs; the connect and total timeouts bound the worst case when Sentry is unreachable.

## Migration Notes

No data or schema changes. The SDK is disabled while `SENTRY_DSN` is empty, so phases 1-3 can ship before the Sentry project exists; rollback is removing the secret.

## References

- Audit: `context/audits/observability/2026-11-23_add-edit-book-mark-read-delete-login.md` (findings 1 and 3) and its verify reports
- Logging path: `config/packages/monolog.yaml:32-60`
- Monolog handler wiring and options: sentry-symfony documentation (`/getsentry/sentry-symfony`)
- Similar pattern (own always-on handler): `src/Security/LoginFailureLogger.php`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: Sentry SDK and configuration

#### Automated

- [x] 1.1 New Sentry tests fail first, then pass — 2297ec5
- [x] 1.2 Full PHPUnit suite passes — 2297ec5
- [x] 1.3 PHPStan passes — 2297ec5
- [x] 1.4 Prod container compiles (`lint:container --env=prod`) — 2297ec5
- [x] 1.5 YAML config is valid — 2297ec5

#### Manual

- [x] 1.6 With an empty DSN, `sentry:test` reports the missing DSN and the app boots in dev and prod modes — 2297ec5
- [x] 1.7 `monolog.yaml` shows the `sentry` handler outside `fingers_crossed` and excluding `request` — 2297ec5

### Phase 2: User id on events

#### Automated

- [x] 2.1 Listener tests pass — 038a894
- [x] 2.2 Full PHPUnit suite passes — 038a894
- [x] 2.3 PHPStan passes — 038a894

#### Manual

- [x] 2.4 The listener never touches the scope for anonymous requests and never sends the e-mail — 038a894

### Phase 3: Domain failures reach the error stream

#### Automated

- [x] 3.1 New logging tests fail first, then pass
- [x] 3.2 Full PHPUnit suite passes
- [x] 3.3 PHPStan passes

#### Manual

- [x] 3.4 A forged delete still shows the same 400 text and now leaves an `error` line on stderr locally
- [x] 3.5 No log context contains an e-mail address or password

### Phase 4: Release tag, CI and rollout

#### Automated

- [ ] 4.1 Full PHPUnit suite passes
- [ ] 4.2 PHPStan passes
- [ ] 4.3 Image builds the prod cache with the new build argument
- [ ] 4.4 Workflow file is valid YAML

#### Manual

- [ ] 4.5 Sentry project created and `SENTRY_DSN` Fly secret set by the owner
- [ ] 4.6 After deploy, `sentry:test` succeeds and the event shows environment `prod` and the commit SHA
- [ ] 4.7 A deliberate production error shows the user id and no e-mail, IP or cookies; a 404 opens no issue
- [ ] 4.8 `/10x-observability-audit --verify` run and recorded
