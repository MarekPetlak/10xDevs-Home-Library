# Sign-in for household accounts Implementation Plan

## Overview

Roadmap item S-01 (`sign-in-household-accounts`): a household member signs in with e-mail and password and sees a logged-in home page. Accounts are created by the owner from the console (no self-registration, no admin screen). Sessions live in the database so logins survive Fly deploys. The UI is Polish, built on Bootstrap CSS with a shared base layout that later slices reuse.

## Current State Analysis

- `config/packages/security.yaml` has only the `users_in_memory` placeholder provider and a `main` firewall with no authenticator; `access_control` is empty. `AGENTS.md` requires replacing the provider with an entity provider before any login feature.
- `src/Entity/`, `src/Repository/` and `migrations/` are empty (only `.gitignore`). The only controller is `src/Controller/HealthController.php` (`/healthz`, `HealthControllerTest` covers it).
- `config/packages/framework.yaml` has `session: true` (file storage, lost on every Fly deploy because machine disks are ephemeral); tests use `mock_file`.
- `templates/base.html.twig` is the bare Symfony template (`<html>` without `lang`, no layout). `config/packages/translation.yaml` sets `default_locale: en`; `translations/` is empty. Bootstrap is not installed; AssetMapper is (`importmap.php`, Dockerfile already runs `importmap:install` and `asset-map:compile`).
- CI (`.github/workflows/deploy.yml`) runs `php bin/phpunit` against a MariaDB 10.11 service with the non-root `app` user and does not create or migrate a database. The test env appends `_test` to the DB name (`config/packages/doctrine.yaml`), so the DB would be `app_test`, which `app` cannot create.
- Production runs migrations through `release_command` in `fly.toml`, against the cyberfolks MariaDB.
- `symfony/rate-limiter` is not in `composer.json` (needed for login throttling).

## Desired End State

The owner runs `php bin/console app:user:create <email>` and gets a hashed-password account. That user opens `/login`, signs in (CSRF-protected, throttled after repeated failures) and lands on `/`, which greets them by e-mail and offers logout. Anonymous visitors see a public welcome page at `/`. Sessions are stored in a `sessions` table, so a redeploy does not log anyone out. CI creates and migrates a test database and runs the login tests green before any deploy.

Verify: `php bin/phpunit` green locally and in CI; login/logout works on `https://10xdevs-home-library.fly.dev`; the session survives a redeploy.

### Key Discoveries:

- `config/packages/doctrine.yaml:22-23` — test env adds `dbname_suffix: _test`, so CI needs a create-database step with a privileged user.
- `config/packages/framework.yaml:19-20` — tests already use `mock_file` sessions, so DB sessions apply only to non-test envs and are not covered by functional tests.
- `fly.toml` `release_command` runs `doctrine:migrations:migrate --no-interaction --allow-no-migration`, so new migrations reach production on deploy.
- `AGENTS.md` hard rules: `declare(strict_types=1)`, migrations only (never `schema:update`), makers with `--no-interaction`, tests only on the local MariaDB, never the cyberfolks DB, `main` protected, deploy only by PR merge.
- `context/foundation/infrastructure.md` risk register: sessions on the ephemeral disk are lost on restart; store them in the database.

## What We're NOT Doing

- Self-registration, password reset (FR-002), admin screen, owner role or any role beyond `ROLE_USER`.
- Remember-me cookies, display names or other profile fields (only e-mail + password hash + roles).
- Books, ratings, browsing, recommendations (later slices); the home page is placeholder content until S-04.
- E-mail sending, external error tracking, custom domain, database backups (tracked in Open Roadmap Questions 9 and 10).
- Locale switching or an English translation.
- Changing the Fly or Dockerfile configuration beyond verifying that the existing build compiles Bootstrap assets.

## Implementation Approach

Build bottom-up in four verifiable phases, each ending in a committable state: (1) identity data, account commands and a CI that can run DB tests; (2) the shared UI shell and public home page; (3) the authentication flow, throttling and DB sessions on top of it; (4) ship to production and document. Account logic lives in one small service so both console commands and tests exercise the same code. Security configuration follows framework defaults (form login, CSRF, built-in login throttling) with no custom authenticators.

## Critical Implementation Details

- **Doctrine must ignore the sessions table.** The `sessions` table is created by a hand-reviewed migration, not by an entity. Without a `dbal.schema_filter` that excludes it, `doctrine:migrations:diff` and `schema:validate` will try to drop it. Add the filter in the same phase as the migration (Phase 3).
- **Test DB privileges.** CI uses the service's root user only inside the throwaway MariaDB container. Locally the `app` user cannot create `app_test`, so the committed `.env.test` carries a `DATABASE_URL` for the local Docker container's root user (port 3307, the compose default placeholder password, which is already in `.env`); the CI job's own `DATABASE_URL` env var overrides it. Never point any of this at the cyberfolks DB.
- **Throttling state is per machine and in cache.** Login throttling counters live in the default cache (filesystem on a single Fly machine); they reset on deploy. Acceptable for 2-4 users; tests must clear the cache pool between cases.

## Phase 1: User, account commands and CI test database

### Overview

Introduce the first entity and migration, switch the security provider to it, add the owner's console commands, and teach CI to create and migrate a test database so DB-backed tests can run.

### Changes Required:

#### 1. User entity and repository

**File**: `src/Entity/User.php`, `src/Repository/UserRepository.php`

**Intent**: Persist household accounts; the e-mail is the login identifier, the password is stored only as a hash.

**Contract**: `App\Entity\User` implements `UserInterface` and `PasswordAuthenticatedUserInterface`; columns `id` (int, auto), `email` (string 180, unique), `roles` (JSON array, always includes `ROLE_USER` when read), `password` (string, hash). E-mail is normalised to lower case and trimmed on write. `UserRepository` implements `PasswordUpgraderInterface`. Use `make:entity`/`make:user` with `--no-interaction` and review the output.

#### 2. First migration

**File**: `migrations/Version<timestamp>.php`

**Intent**: Create the `user` table (quoted, since `user` can be reserved) via `make:migration --no-interaction`; review it by hand.

**Contract**: Reversible `down()` that drops the table. Unique index on `email`. No sessions table yet (Phase 3).

#### 3. Security provider

**File**: `config/packages/security.yaml`

**Intent**: Replace `users_in_memory` with an entity provider so later phases can add the authenticator.

**Contract**: provider `app_user_provider` with `entity: { class: App\Entity\User, property: email }`; firewall `main` uses it. No `access_control` entries (anonymous browsing is public by PRD).

#### 4. Account service and commands

**File**: `src/Service/UserManager.php`, `src/Command/CreateUserCommand.php`, `src/Command/ChangePasswordCommand.php`

**Intent**: Let the owner create accounts and change passwords from the console without any password on the command line or in shell history.

**Contract**: `UserManager::create(string $email, string $plainPassword): User` and `changePassword(string $email, string $plainPassword): User`; both hash via `UserPasswordHasherInterface`, validate e-mail format and a minimum password length of 8, and throw a domain exception on duplicate e-mail (create) or unknown e-mail (change). `app:user:create <email>` and `app:user:change-password <email>` take the e-mail as an argument and ask for the password through a hidden interactive prompt (asked twice for confirmation); there is no password argument or option. Non-zero exit and a clear message on failure.

#### 5. CI test database

**File**: `.github/workflows/deploy.yml`

**Intent**: Make the `test` job able to run DB-backed tests on the real migrations, so a broken migration fails CI before deploy.

**Contract**: In the `test` job, `DATABASE_URL` uses the service's `root` user (`root`/`root`, container-only) and two steps run before `php bin/phpunit`: `php bin/console doctrine:database:create --if-not-exists` and `php bin/console doctrine:migrations:migrate --no-interaction` (both under the job's `APP_ENV=test`). Production and `deploy` job unchanged.

#### 6. Local test database

**File**: `.env.test`, `AGENTS.md`

**Intent**: Let the local test run create `app_test` without extra setup, using the existing committed `.env.test` instead of an untracked file.

**Contract**: `.env.test` gets a `DATABASE_URL` for the local compose MariaDB root user (`mysql://root:<compose default root password>@127.0.0.1:3307/app?serverVersion=10.11.0-MariaDB&charset=utf8mb4`; the `_test` suffix is added by Doctrine). Real environment variables win over `.env.test`, so CI's job-level `DATABASE_URL` is unaffected. `AGENTS.md` Commands gets one line saying tests need `docker compose up -d database` and use this URL; the production/cyberfolks DB is never used for tests.

#### 7. Tests

**File**: `tests/Service/UserManagerTest.php`, `tests/Command/CreateUserCommandTest.php`, `tests/Command/ChangePasswordCommandTest.php`

**Intent**: Cover creation, hashing, duplicate and short-password rejection, e-mail normalisation, and password change including the unknown-user failure.

**Contract**: Kernel/command tests on the `_test` database; each test clears the `user` table in `setUp`. Assert the stored password is not the plain text and verifies with the hasher.

### Success Criteria:

#### Automated Verification:

- Migration applies to an empty test DB and mapping is in sync: `php bin/console doctrine:database:create --env=test --if-not-exists && php bin/console doctrine:migrations:migrate --env=test -n && php bin/console doctrine:schema:validate --env=test`
- Tests pass: `php bin/phpunit`
- Container lints and the placeholder provider is gone: `php bin/console lint:container` and no `users_in_memory` in `config/packages/security.yaml`

#### Manual Verification:

- `php bin/console app:user:create you@example.com` on the local dev DB asks for a hidden password, rejects one shorter than 8 characters, and stores a hash (the `password` column does not contain the typed text)
- `app:user:change-password` on the same e-mail works, and on an unknown e-mail fails with a clear message
- After this phase's commits, open a draft PR into `main` (CI only runs on `pull_request` and pushes to `main`); the CI `test` job passes on it and shows the create-database and migrate steps running. Later phases are pushed to the same PR

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 2: Layout, Polish locale and public home page

### Overview

Install Bootstrap through AssetMapper, replace the bare template with a shared Polish layout, and add the public home page that later slices will build on.

### Changes Required:

#### 1. Bootstrap assets

**File**: `importmap.php`, `assets/app.js`

**Intent**: Self-host Bootstrap CSS and JS through the existing AssetMapper pipeline (no CDN, no Node).

**Contract**: `php bin/console importmap:require bootstrap` plus its CSS entry (`bootstrap/dist/css/bootstrap.min.css`, type css) imported from `assets/app.js`. Vendor files stay gitignored; the Dockerfile's existing `importmap:install` fetches them in CI builds.

#### 2. Base layout

**File**: `templates/base.html.twig`

**Intent**: One layout for every page: Polish `<html lang>`, title block, Bootstrap navbar (brand now; account links in Phase 3), flash-message area and a content block.

**Contract**: Blocks `title`, `body_content`; navbar defined as an overridable block. Keep the existing `importmap('app')` call.

#### 3. Polish locale

**File**: `config/packages/translation.yaml`, `translations/messages.pl.yaml`

**Intent**: Make Polish the single UI language; framework validator/security messages already ship Polish translations.

**Contract**: `default_locale: pl`; app strings live in the `messages` domain under stable keys.

#### 4. Home page

**File**: `src/Controller/HomeController.php`, `templates/home/index.html.twig`

**Intent**: Public `/` that welcomes visitors; it becomes the recommendations page in S-04 and must stay publicly reachable (FR-003).

**Contract**: Route `home` at `/` (GET), open to everyone. Phase 2 shows only the anonymous welcome text, with no login link until the login route exists.

#### 5. Tests

**File**: `tests/Controller/HomeControllerTest.php`

**Intent**: Lock in the public access rule and the layout basics.

**Contract**: `GET /` returns 200 without authentication, response has `<html lang="pl">`, and the page links the Bootstrap stylesheet.

### Success Criteria:

#### Automated Verification:

- Tests pass: `php bin/phpunit`
- Assets install and compile: `php bin/console importmap:install && php bin/console asset-map:compile --env=prod` (with `APP_SECRET` and `DATABASE_URL` set as in the Dockerfile build)
- Bootstrap is in the asset map: `php bin/console debug:asset-map | grep -i bootstrap`

#### Manual Verification:

- `/` renders styled with Bootstrap at desktop and narrow widths, text in Polish, no console errors
- `/healthz` still returns `ok`

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 3: Login, logout, throttling and database sessions

### Overview

Add the authentication flow on top of the entity provider and layout, protect it against password guessing, store sessions in the database, and make `/` show the logged-in state.

### Changes Required:

#### 1. Rate limiter dependency

**File**: `composer.json`, `composer.lock`

**Intent**: Enable the security component's built-in login throttling.

**Contract**: `composer require symfony/rate-limiter`; keep recipe-generated config only if it is needed.

#### 2. Firewall and login routes

**File**: `config/packages/security.yaml`, `src/Controller/SecurityController.php`, `templates/security/login.html.twig`

**Intent**: Form login with CSRF protection, logout back to `/`, throttling, and a Bootstrap login form with translated error messages.

**Contract**: Firewall `main`: `form_login` (`login_path`/`check_path` = route `app_login`, `enable_csrf: true`, default target `home`), `logout` (path `app_logout`, target `home`), `login_throttling` (`max_attempts: 5`). Route `app_login` at `/login`; an already authenticated visitor is redirected to `home`. The form shows the last e-mail and a generic "invalid credentials" message (no user-existence leak). No `remember_me`.

#### 3. Layout and home for signed-in users

**File**: `templates/base.html.twig`, `templates/home/index.html.twig`

**Intent**: Anonymous: welcome plus a login link. Signed in: navbar shows the e-mail and a logout control; the home page greets the user.

**Contract**: Use `logout_path()` so the CSRF-protected logout token is included. Translation keys added to `translations/messages.pl.yaml`.

#### 4. Database sessions

**File**: `config/packages/framework.yaml`, `config/services.yaml`, `config/packages/doctrine.yaml`, `migrations/Version<timestamp>.php`

**Intent**: Keep logins alive across deploys by storing sessions in MariaDB.

**Contract**: `framework.session.handler_id` points at a `PdoSessionHandler` service (non-test envs; the test env keeps `mock_file`) whose first argument is the shared native PDO obtained from `@doctrine.dbal.default_connection` via `getNativeConnection`, so sessions reuse the app's single DB connection (cyberfolks limits outside connections per user), with option `db_table: sessions`. A hand-written migration creates `sessions` (`sess_id` VARBINARY(128) primary key, `sess_data` BLOB, `sess_lifetime` INT UNSIGNED, `sess_time` INT UNSIGNED, index on `sess_lifetime`, InnoDB) with a reversible `down()`. `doctrine.dbal.schema_filter` excludes `sessions` so diff/validate stay clean.
#### 5. Tests

**File**: `tests/Controller/SecurityControllerTest.php`, extend `tests/Controller/HomeControllerTest.php`

**Intent**: Cover the access rules and failure paths of the login flow.

**Contract**: Cases: successful login redirects to `/` and shows the e-mail; wrong password stays on `/login` with the error; missing/invalid CSRF token is rejected; logout returns to anonymous `/`; `/login` redirects when already signed in; sixth failed attempt within the window is throttled; anonymous `/` shows no personal data. `setUp` creates a user through `UserManager` and clears the rate-limiter cache pool.

### Success Criteria:

#### Automated Verification:

- Tests pass: `php bin/phpunit`
- Both migrations apply to an empty DB: `php bin/console doctrine:migrations:migrate --env=test -n`
- Schema stays clean with the sessions table ignored: `php bin/console doctrine:schema:validate --env=test` and `php bin/console doctrine:migrations:diff --env=test` reports no changes
- Container lints: `php bin/console lint:container`

#### Manual Verification:

- In the browser on the local dev server, sign in with a created account, see the greeting and logout, and confirm a `sessions` row exists
- Restart the dev server: the browser is still signed in
- Six wrong passwords in a row show the throttling message; a wrong password never reveals whether the e-mail exists

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Phase 4: Production verification and documentation

### Overview

Ship through the normal PR-to-deploy path, prove the feature on Fly, and bring repository docs in line.

### Changes Required:

#### 1. Build and release check

**File**: `Dockerfile`, `fly.toml` (verify only; edit only if a problem is found)

**Intent**: Confirm that the image compiles Bootstrap assets and that `release_command` applies the two new migrations.

**Contract**: No planned change. If `docker build` or the release fails, fix the cause within this phase.

#### 2. Repository docs

**File**: `AGENTS.md`, `context/deployment/deploy-plan.md`

**Intent**: Remove the now-obsolete in-memory provider warning, document the account commands and the local test-DB setup, and close the open login smoke test.

**Contract**: `AGENTS.md`: delete the `users_in_memory` rule, add `app:user:create` / `app:user:change-password` usage, mention the `sessions` table and the schema filter (the test-database note was already added in Phase 1). `deploy-plan.md` Phase 7: mark the login-page smoke test done with the date.

### Success Criteria:

#### Automated Verification:

- Full test suite passes on the final tree: `php bin/phpunit`
- Production image builds from a clean checkout: `docker build -t home-library-check .`
- Obsolete rule removed: `AGENTS.md` no longer contains `users_in_memory`

#### Manual Verification:

- The draft PR opened after Phase 1 is marked ready, CI `test` job is green, PR merged by the human, CI deploy finishes and `fly releases` shows a new complete release
- Human creates the first account in production with a real terminal so the password prompt stays hidden: `fly ssh console -a 10xdevs-home-library --pty -C "php bin/console app:user:create <email>"` (or open `fly ssh console -a 10xdevs-home-library` and run the command inside), then types the password at the prompt
- On `https://10xdevs-home-library.fly.dev/login` the account signs in and sees the greeting; `/healthz` still returns 200 and http redirects to https
- After a redeploy or machine restart the browser stays signed in (database sessions work on production)

**Implementation Note**: After completing this phase and all automated verification passes, pause here for manual confirmation from the human that the manual testing was successful before proceeding to the next phase. Phase blocks use plain bullets — the corresponding `- [ ]` checkboxes for these items live in the `## Progress` section at the bottom of the plan.

---

## Testing Strategy

### Unit Tests:

- `UserManager`: create, duplicate e-mail, case/whitespace normalisation, minimum password length, hashing, change password, unknown user.

### Integration Tests:

- Console commands via `CommandTester` with hidden-prompt inputs.
- Functional login flow: success, wrong password, invalid CSRF, logout, authenticated redirect from `/login`, throttling, public vs signed-in `/`.
- CI runs the whole suite on a freshly created and migrated `app_test` database.

### Manual Testing Steps:

1. Create a user locally, sign in, check the greeting, logout.
2. Trigger throttling with repeated wrong passwords.
3. Restart the server and confirm the session persists.
4. Repeat sign-in on production after merge and survive a redeploy.

## Performance Considerations

Database sessions add one read and one write per request against the remote cyberfolks DB (about 30 ms per query from `fra`). This is acceptable for 2-4 users; the 2-second budget applies to the recommendations page (S-04), where it must be re-measured.

## Migration Notes

Two migrations reach production through `release_command`: `user`, then `sessions`. Both only create tables and have reversible `down()`; migrations do not roll back automatically on Fly, so a failed release is fixed forward or the previous image is redeployed with the tables left in place. The production database has no application tables yet, so no data is at risk, but take a `mysqldump` first per `AGENTS.md` if backups have been set up by then.

## References

- Roadmap item: `context/foundation/roadmap.md` (S-01, Open Roadmap Question 4 resolved here as a console command)
- Requirements: `context/foundation/prd.md` (FR-001, US-01, Access Control, NFR on persistence)
- Stack and infra: `context/foundation/tech-stack.md`, `context/foundation/infrastructure.md` (session risk), `context/deployment/deploy-plan.md`
- Existing pattern: `src/Controller/HealthController.php`, `tests/Controller/HealthControllerTest.php:1`

## Progress

> Convention: `- [ ]` pending, `- [x]` done. Append ` — <commit sha>` when a step lands. Do not rename step titles. See `references/progress-format.md`.

### Phase 1: User, account commands and CI test database

#### Automated

- [x] 1.1 Migration applies to an empty test DB and mapping is in sync (`doctrine:schema:validate`) — b35dea0
- [x] 1.2 Tests pass: `php bin/phpunit` — b35dea0
- [x] 1.3 Container lints and the `users_in_memory` provider is gone from `security.yaml` — b35dea0

#### Manual

- [x] 1.4 `app:user:create` asks for a hidden password, rejects short ones and stores a hash — b35dea0
- [x] 1.5 `app:user:change-password` works for an existing e-mail and fails clearly for an unknown one — b35dea0
- [x] 1.6 CI `test` job passes on the PR with the create-database and migrate steps — b35dea0

### Phase 2: Layout, Polish locale and public home page

#### Automated

- [x] 2.1 Tests pass: `php bin/phpunit` — 64035a0
- [x] 2.2 Assets install and compile (`importmap:install`, `asset-map:compile --env=prod`) — 64035a0
- [x] 2.3 Bootstrap appears in `debug:asset-map` — 64035a0

#### Manual

- [x] 2.4 `/` renders styled in Polish at desktop and narrow widths with no console errors — 64035a0
- [x] 2.5 `/healthz` still returns `ok` — 64035a0

### Phase 3: Login, logout, throttling and database sessions

#### Automated

- [x] 3.1 Tests pass: `php bin/phpunit` — c4e785c
- [x] 3.2 Both migrations apply to an empty DB — c4e785c
- [x] 3.3 Schema validate and migrations diff are clean with the sessions table ignored — c4e785c
- [x] 3.4 Container lints: `php bin/console lint:container` — c4e785c

#### Manual

- [x] 3.5 Local sign-in shows the greeting and logout, and a `sessions` row exists — c4e785c
- [x] 3.6 Restarting the dev server keeps the browser signed in — c4e785c
- [x] 3.7 Six wrong passwords trigger throttling and never reveal whether the e-mail exists — c4e785c

### Phase 4: Production verification and documentation

#### Automated

- [x] 4.1 Full test suite passes on the final tree — ba797cb
- [x] 4.2 `docker build` succeeds from the clean tree — ba797cb
- [x] 4.3 `AGENTS.md` no longer contains `users_in_memory` — ba797cb

#### Manual

- [x] 4.4 PR green, merged by the human, deploy finished, `fly releases` shows a new complete release — ba797cb
- [x] 4.5 First production account created via `fly ssh console` — ba797cb
- [x] 4.6 Production sign-in works, `/healthz` is 200 and http redirects to https — ba797cb
- [x] 4.7 The browser stays signed in after a redeploy or restart — ba797cb
