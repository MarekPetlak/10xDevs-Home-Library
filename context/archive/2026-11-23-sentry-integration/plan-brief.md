# Sentry integration — Plan Brief

> Full plan: `context/changes/sentry-integration/plan.md`

## What & Why

Add Sentry error tracking so production errors become issues instead of lines in `fly logs`. The observability audit found no tracker in the repo and several failures that never reach any log; this change adds the tracker and makes the two most important of those failures visible to it.

## Starting Point

Only Monolog exists: JSON on stderr behind a `fingers_crossed` handler, plus an always-on `auth` channel for sign-in failures. Uncaught exceptions are logged on the `request` channel. A lost add/edit race and an invalid CSRF token are handled without any log (audit findings 1 and 3). Deploys go through GitHub Actions to Fly, and the image has no `.git`.

## Desired End State

With a `SENTRY_DSN` secret set, an uncaught exception or a logged `error` in production opens a Sentry issue tagged `prod`, with the commit SHA as release and the numeric user id. No e-mail, IP, cookies or bodies are sent, and 4xx responses never open issues. Without a DSN (local, CI, tests) the SDK is off.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) |
| --- | --- | --- |
| What is reported | Exceptions + Monolog records ≥ error | Covers 5xx and explicit `logger->error()` without flooding. |
| Sign-in failures | Stay in stderr logs | Wrong passwords would open issues constantly. |
| Personal data | Numeric user id only | Ties an error to a household member without storing PII. |
| HTTP errors | Ignore all 4xx | Only real server faults become issues (403s remain in logs). |
| Release tag | Git SHA via Docker build arg | Every issue names the exact deploy; image has no `.git`. |
| Tracing | Off | Not what the audit asked for; saves quota and CPU. |
| Audit gaps | Also log findings 1 and 3 at `error` | Otherwise those failures never reach the tracker. |
| Verification | PHPUnit spy transport + one `sentry:test` event | Offline CI proof plus one real end-to-end check. |

## Scope

**In scope:** SDK and config, Monolog handler, user id listener, logging of findings 1 and 3, release tag via CI, rollout notes, audit verify run.

**Out of scope:** tracing/profiling, alert rules, Sentry project setup, sign-in failure reporting, audit findings 2, 5, 6, 8, 9.

## Architecture / Approach

`sentry-symfony` registers an error listener for uncaught exceptions and a `before_send` filter drops HTTP exceptions below 500. A `Sentry\Monolog\Handler` at `error` (skipping the `request` channel to avoid duplicates, outside `fingers_crossed`) forwards explicit error logs. A small listener sets the user id on the scope. A `DomainFailureLogger` service logs the lost race and bad CSRF token. Tests swap the transport for an in-memory spy.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| --- | --- | --- |
| 1. SDK and configuration | Installed SDK, privacy-first config, Monolog handler, spy-transport tests | Duplicate reporting of one exception; 4xx filter wrong |
| 2. User id on events | Id-only user context | Accidentally attaching e-mail or IP |
| 3. Domain failures reach the error stream | Findings 1 and 3 logged at `error` with ids | A lost race may be noisy as an `error` issue |
| 4. Release, CI and rollout | `SENTRY_RELEASE` from SHA, secret, smoke test, audit verify | Needs the owner's Sentry project and DSN |

**Prerequisites:** a Sentry account/project and its DSN (owner), `fly secrets` access.
**Estimated effort:** ~3-4 sessions across 4 phases.

## Open Risks & Assumptions

- Treating a lost add/edit race as an `error` may produce noisy issues; grouping or a lower level can be revisited after real traffic.
- The SDK sends synchronously; short cURL timeouts bound the cost when Sentry is unreachable.
- Assumption: the free Sentry quota is enough for a household app.
- Assumption: a fake DSN plus a custom transport builds a working client in the test environment (verified in Phase 1).

## Success Criteria (Summary)

- A deliberate production error shows up in Sentry with `prod`, the commit SHA and the user id, and nothing personal.
- A 404 or a foreign edit (403) opens no issue; a lost race and a forged CSRF token do.
- CI stays green with no DSN and no network calls.
