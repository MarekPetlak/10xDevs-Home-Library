# Sign-in for household accounts — Plan Brief

> Full plan: `context/changes/sign-in-household-accounts/plan.md`

## What & Why

A household member signs in with e-mail and password and sees a logged-in home page. This is roadmap slice S-01 and the root of the path to the north star (recommendations, S-04): every later feature needs a signed-in user. Accounts are created by the owner from the console, and logins must survive deploys.

## Starting Point

The app is deployed on Fly with CI auto-deploy, but has only a `/healthz` controller. Security has a placeholder in-memory provider, there are no entities or migrations, the template is the bare Symfony one, and CI cannot run DB-backed tests (no test database is created).

## Desired End State

`php bin/console app:user:create <email>` makes an account; that user signs in at `/login` (CSRF, throttled) and lands on `/`, which greets them and offers logout. Anonymous visitors see a public welcome page. Sessions are stored in the database, so a redeploy does not log anyone out. CI creates and migrates a test DB and runs the tests green before deploy.

## Key Decisions Made

| Decision | Choice | Why (1 sentence) | Source |
| -------- | ------ | ---------------- | ------ |
| Account creation | Console commands `app:user:create` and `app:user:change-password`, password via hidden prompt | No admin UI or owner role (PRD excludes roles), and it covers the manual password change that replaces parked reset | Plan |
| Session storage | Database table `sessions` | Fly disks are ephemeral, so file sessions log everyone out on each deploy | Plan |
| UI language | Polish only, locale `pl` | Matches the Polish PRD and users | Plan |
| Styling | Bootstrap via AssetMapper plus shared base layout now | First UI slice sets the layout all later slices reuse | Plan |
| Brute-force protection | Built-in login throttling, 5 attempts | One config line covers the secure-accounts guardrail | Plan |
| Home page `/` | Public; greeting for signed-in users | Anonymous browsing (FR-003) and recommendations (S-04) will live on `/` | Plan |
| CI test DB | Root user in the CI service container plus create and migrate steps | Tests run on real migrations; `app` cannot create `app_test` | Plan |
| User fields | E-mail, password hash, roles; minimum password length 8 | Smallest model that satisfies FR-001 | Plan |

## Scope

**In scope:** `User` entity and migrations, entity provider, two console commands, Bootstrap layout, Polish locale, login/logout, throttling, DB sessions, CI test DB, production verification, doc updates.

**Out of scope:** registration, password reset, admin screen, roles, remember-me, display names, books and recommendations, e-mail, error tracking, custom domain, backups.

## Architecture / Approach

A `UserManager` service holds account logic (create, change password, validation, hashing) and is shared by the console commands and tests. Security uses framework defaults: `form_login` with CSRF, built-in `login_throttling`, logout back to `/`. A `PdoSessionHandler` built on the Doctrine connection stores sessions in a hand-migrated table that Doctrine's schema filter ignores. The base layout and home page are introduced before login so the login page is built once.

## Phases at a Glance

| Phase | What it delivers | Key risk |
| ----- | ---------------- | -------- |
| 1. User, commands, CI DB | Entity, first migration, provider, owner commands, CI creates and migrates `app_test` | CI privileges and local test-DB setup |
| 2. Layout, locale, home | Bootstrap, Polish layout, public `/` | Asset pipeline must also work in the production image |
| 3. Login, throttling, sessions | Sign-in flow, throttling, DB sessions, signed-in home | Sessions table vs Doctrine schema diff; session handler wiring |
| 4. Production and docs | Deploy via PR, first account, session survives redeploy, `AGENTS.md` updated | First migrations on the cyberfolks DB; manual steps on Fly |

**Prerequisites:** Local Docker MariaDB running (`docker compose up -d database`); PHP on PATH (PHP 8.5 lives in `C:\php85`); a PR flow into the protected `main`; human access to `fly ssh console` for the first account.
**Estimated effort:** About 4 sessions across 4 phases (no dates or story points).

## Open Risks & Assumptions

- Session handler wiring on the Doctrine PDO connection must be confirmed against current Symfony docs; DB sessions are not covered by functional tests (tests use `mock_file`), so Phase 4 proves them on production.
- Throttling counters live in per-machine cache and reset on deploy; acceptable for 2-4 users.
- Owner-created accounts depend on CLI access to Fly; the owner is a single person (PRD Open Question 4 settled as a console command).
- Production migrations run against the cyberfolks DB via `release_command`; a mistake there is fixed forward, not rolled back.

## Success Criteria (Summary)

- An owner-created user signs in, sees the greeting and logs out, locally and on production.
- Wrong passwords never leak whether an e-mail exists, and repeated failures are throttled.
- A redeploy does not sign users out, and CI is green with DB tests before any deploy.
