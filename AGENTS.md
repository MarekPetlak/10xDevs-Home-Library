# Repository Guidelines

"My home library": shared household book collection with per-user ratings and recommendations. Symfony 8.1 on PHP 8.4+, Doctrine ORM on MariaDB 10.11 (production: cyberfolks), Twig + AssetMapper + Stimulus/Turbo. Requirements: @context/foundation/prd.md. Stack decisions: @context/foundation/tech-stack.md.

## Hard rules

- Ratings, read status and notes are private per user. Only a book's author may edit or delete it (FR-006, FR-007). Deleting hides the book from every list but keeps other users' ratings and notes (soft delete, no cascade).
- Anonymous users may only browse and search books (FR-003). Everything else requires login. There is no self-registration; the owner creates accounts.
- The duplicate check (FR-005) compares title + author (case and surrounding whitespace ignored) and ISBN. It only warns; the adding user decides.
- Recommendations follow "Business Logic" in @context/foundation/prd.md: at most three, never user-taste similarity (Non-Goal).
- Auth is form login (e-mail + password, CSRF, throttled at 5 attempts) on the `app_user_provider` entity provider (`App\Entity\User`); logout is CSRF-protected. No remember-me.
- Sessions live in the `sessions` table (`PdoSessionHandler` on the shared DBAL connection, dev and prod; tests use `mock_file`). The table is created by a migration and hidden from Doctrine by `dbal.schema_filter`; never model it as an entity.
- Use `declare(strict_types=1);` and typed properties in all PHP files under `src/` and `tests/`.
- Never edit `context/archive/` or `context/foundation/archive/`.

## Project Structure

- `src/Controller`, `src/Entity`, `src/Repository`, `src/Service`, `src/Command` (namespace `App\`): so far `User`, `UserManager`, the account commands and the home, login and health controllers.
- `templates/` (Twig), `assets/` + `importmap.php` (AssetMapper; no Node or bundler, add JS packages with `bin/console importmap:require`).
- `migrations/`: schema changes go through migrations only.
- `context/`: planning artifacts for the 10x workflow.

## Commands

- `docker compose up -d database mailer`: MariaDB 10.11 on host port 3307 (matches `DATABASE_URL` in `.env`) and Mailpit.
- `symfony serve -d`: dev server.
- `bin/console make:migration`, then `bin/console doctrine:migrations:migrate`: never `doctrine:schema:update`.
- `bin/phpunit`: tests; need `docker compose up -d database` and use the root `DATABASE_URL` from `.env.test` (creates `app_test`); never the production/cyberfolks DB (`phpunit.dist.xml` fails on deprecations, notices and warnings).
- `bin/console app:user:create <email>` and `bin/console app:user:change-password <email>`: the owner's account tools. The password is asked through a hidden prompt (twice, minimum 8 characters); there is no password argument. On Fly use a real terminal: `fly ssh console -a 10xdevs-home-library --pty -C "php bin/console app:user:create <email>"`.
- `composer audit`: the only dependency audit; none runs automatically.

## Conventions

- Use `bin/console make:* --no-interaction` with all arguments given; makers hang a non-interactive shell otherwise.
- `php` may not be on PATH on this Windows machine; PHP 8.5 lives in `C:\php85`.

## Deployment (Fly.io, app `10xdevs-home-library`, region `fra`)

- Deploy only by merging a PR to `main`: `test` must pass, then CI deploys. `main` is protected (admins too); never push to it. Full plan and log: @context/deployment/deploy-plan.md.
- Production is CLI only (`fly`, `gh`); no `fly mcp`. Always pass `--ha=false` and `-a 10xdevs-home-library`.
- Agent may run unattended: `fly status`, `fly logs --no-tail`, `fly releases --image`, `fly secrets list`, `fly machines list`.
- Human only (never run these, even if asked to suggest them): `fly secrets set/unset`, `fly tokens create/revoke`, `fly apps destroy`, `fly ips release-egress`, `fly certs remove`, DB drops, DNS changes, cyberfolks whitelist edits.
- Rollback: `fly releases --image`, then `fly deploy --image <ref> --ha=false`. Migrations do not roll back; take a dump first.
- CI builds from a clean checkout, so `assets/vendor/` (gitignored) is rebuilt by `importmap:install` in the Dockerfile, and `bin/console` needs mode 100755 (Git on Windows records 100644). A local `fly deploy` hides both problems.
- Tests must use the local MariaDB, never the cyberfolks database; its whitelist only allows the app's egress IPs.
