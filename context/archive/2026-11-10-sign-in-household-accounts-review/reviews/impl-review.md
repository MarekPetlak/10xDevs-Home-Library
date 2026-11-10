<!-- IMPL-REVIEW-REPORT -->
# Implementation Review: Sign-in for household accounts

- **Plan**: context/changes/sign-in-household-accounts-review/plan.md (mirror of the archived S-01 plan)
- **Scope**: Full plan (Phases 1-4 of 4)
- **Reviewed phases**: 1, 2, 3, 4
- **Date**: 2026-11-10
- **Verdict**: NEEDS ATTENTION
- **Findings**: 0 critical, 3 warnings, 6 observations

## Verdicts

| Dimension | Verdict |
|-----------|---------|
| Plan Adherence | WARNING |
| Scope Discipline | PASS |
| Safety & Quality | WARNING |
| Architecture | PASS |
| Pattern Consistency | PASS |
| Success Criteria | PASS |

## Success criteria run (current tree, branch main)

Automated: migrations apply on the test DB, `doctrine:schema:validate` in sync, `doctrine:migrations:diff` reports no changes, `php bin/phpunit` 123 tests / 420 assertions green, `lint:container` OK, no `users_in_memory` in `security.yaml` or `AGENTS.md`, `debug:asset-map` lists bootstrap, `importmap:install` and `asset-map:compile --env=prod` succeed (with the Dockerfile's build env: `TRUSTED_PROXIES`, `APP_SECRET`, `DATABASE_URL`), `docker build` succeeds.
Manual rows are all ticked; production items 4.4-4.7 cannot be re-observed here (see F1).

## Findings

### F1 — Deploy plan still says the login smoke test is open

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Plan Adherence
- **Location**: context/deployment/deploy-plan.md:314
- **Detail**: Phase 4 plans to mark the production login smoke test done with a date, and Progress 4.4-4.7 are ticked. The line is still `[~]` and says the https login check "is still open … mark this done after the merge, the first account and a sign-in on production".
- **Fix**: Change the line to done with the actual date the production sign-in was verified.
- **Decision**: FIXED via Fix now (deploy-plan.md:314 marked done, dated 2026-11-03)

### F2 — Production DB connection does not verify the server certificate

- **Severity**: ⚠️ WARNING
- **Impact**: 🔎 MEDIUM — real tradeoff; pause to reason through it
- **Dimension**: Safety & Quality
- **Location**: config/packages/doctrine.yaml:37
- **Detail**: `ATTR_SSL_VERIFY_SERVER_CERT: false` in prod. Traffic is encrypted but a man-in-the-middle on the path to the database host is not detected; the connection carries password hashes and session rows. A code comment records that the host offers TLS without a usable CA (decision D7).
- **Fix**: Keep as a documented, accepted risk until the provider's CA is available, then set verification to true and point `ATTR_SSL_CA` at it.
  - Strength: no behaviour change today; the comment already explains the reason.
  - Tradeoff: the risk stays open until the CA is obtained.
  - Confidence: MED — the provider's CA situation was not re-checked.
  - Blind spot: whether the database host is reachable only over a private network.
- **Decision**: SKIPPED (documented risk kept until the provider CA is available)

### F3 — Duplicate e-mail race in UserManager::create is not handled

- **Severity**: ⚠️ WARNING
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/UserManager.php:29-37
- **Detail**: `create()` checks for an existing e-mail and then inserts. Two concurrent runs can both pass the check; the unique index rejects the second, but the `UniqueConstraintViolationException` is not translated, so the CLI shows a stack trace instead of the friendly `UserManagementException`. `ReadingManager` already handles the same case.
- **Fix**: Catch `UniqueConstraintViolationException` around the flush and rethrow `UserManagementException`, test with a mocked failing flush.
- **Decision**: FIXED via Fix now (catch in UserManager::create, test added)

### F4 — Sessions use LOCK_NONE (documented trade-off)

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: config/services.yaml:30
- **Detail**: Not in the plan, but documented in code and AGENTS.md: the default transactional lock breaks Doctrine transactions on the shared connection. Concurrent requests (two tabs) can overwrite each other's session writes, for example a flash message. Login throttling counters also live in the filesystem cache and reset on restart, which is acceptable with one machine.
- **Fix**: Accept; no change needed unless random logouts appear.
- **Decision**: SKIPPED (documented LOCK_NONE trade-off accepted)

### F5 — Session rows contain the full password hash

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Entity/User.php
- **Detail**: `User` has no `__serialize()`, so the password hash is stored in the `sessions` table. Password changes still invalidate sessions.
- **Fix**: Add `__serialize()` that replaces the password with `hash('crc32c', $password)`, as the Symfony documentation advises.
- **Decision**: FIXED via Fix now (User::__serialize with crc32c, tests added)

### F6 — Login throttling message detected by string match

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: templates/security/login.html.twig:11
- **Detail**: The template branches on the message key starting with "Too many"; a wording change in Symfony would silently switch the throttling message to the generic one.
- **Fix**: Decide the message in `SecurityController` from the exception class and pass a flag to the template.
- **Decision**: FIXED + ACCEPTED-AS-RULE: Don't branch on framework message text (controller passes a throttled flag from the exception class)

### F7 — No maximum password length

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Safety & Quality
- **Location**: src/Service/UserManager.php:69
- **Detail**: Only a minimum of 8 characters is enforced. If the configured hasher is bcrypt it silently truncates at 72 bytes. The CLI is the only entry point, so exposure is low.
- **Fix**: Reject passwords longer than a sane maximum (72 bytes) with a clear message.
- **Decision**: FIXED via Fix now (72-byte maximum, tests added)

### F8 — First migration: implicit collation and an unplanned table

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Scope Discipline
- **Location**: migrations/Version20261102125426.php:19-20
- **Detail**: The `user` table has no explicit collation, so the e-mail unique index depends on the server default, while later tables set `utf8mb4_unicode_ci`. The migration also creates `messenger_messages`, which the plan did not ask for. The migration is already applied in production, so editing it is risky.
- **Fix**: Accept; record the extra table in AGENTS.md or fix the collation in a new migration if it ever matters.
- **Decision**: SKIPPED only because the migration is already in production

### F9 — Duplicated password prompt in the two account commands

- **Severity**: 👁 OBSERVATION
- **Impact**: 🏃 LOW — quick decision; fix is obvious and narrowly scoped
- **Dimension**: Pattern Consistency
- **Location**: src/Command/CreateUserCommand.php:53, src/Command/ChangePasswordCommand.php
- **Detail**: `askPassword()` is duplicated almost line for line.
- **Fix**: Extract a small shared trait; low value for two commands, safe to skip.
- **Decision**: FIXED differently (PasswordPrompt service instead of a trait) + ACCEPTED-AS-RULE: Prefer a service over a trait for shared code
