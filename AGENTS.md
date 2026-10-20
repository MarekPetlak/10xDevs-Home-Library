# Repository Guidelines

"My home library": shared household book collection with per-user ratings and recommendations. Symfony 8.1 on PHP 8.4+, Doctrine ORM on MySQL 8, Twig + AssetMapper + Stimulus/Turbo. Requirements: @context/foundation/prd.md. Stack decisions: @context/foundation/tech-stack.md.

## Hard rules

- Ratings, read status and notes are private per user. Only a book's author may edit or delete it (FR-006, FR-007). Deleting hides the book from every list but keeps other users' ratings and notes (soft delete, no cascade).
- Anonymous users may only browse and search books (FR-003). Everything else requires login. There is no self-registration; the owner creates accounts.
- The duplicate check (FR-005) compares title + author (case and surrounding whitespace ignored) and ISBN. It only warns; the adding user decides.
- Recommendations follow "Business Logic" in @context/foundation/prd.md: at most three, never user-taste similarity (Non-Goal).
- `config/packages/security.yaml` still has the `users_in_memory` placeholder provider. Replace it with an entity provider before adding any login feature.
- Use `declare(strict_types=1);` and typed properties in all PHP files under `src/` and `tests/`.
- Never edit `context/archive/` or `context/foundation/archive/`.

## Project Structure

- `src/Controller`, `src/Entity`, `src/Repository`: empty so far (namespace `App\`).
- `templates/` (Twig), `assets/` + `importmap.php` (AssetMapper; no Node or bundler, add JS packages with `bin/console importmap:require`).
- `migrations/`: schema changes go through migrations only.
- `context/`: planning artifacts for the 10x workflow.

## Commands

- `docker compose up -d database mailer`: MySQL on host port 3307 (matches `DATABASE_URL` in `.env`) and Mailpit.
- `symfony serve -d`: dev server.
- `bin/console make:migration`, then `bin/console doctrine:migrations:migrate`: never `doctrine:schema:update`.
- `bin/phpunit`: tests (`phpunit.dist.xml` fails on deprecations, notices and warnings).
- `composer audit`: the only dependency audit; none runs automatically.

## Conventions

- Use `bin/console make:* --no-interaction` with all arguments given; makers hang a non-interactive shell otherwise.
- `php` may not be on PATH on this Windows machine; PHP 8.5 lives in `C:\php85`.
- No CI exists yet. The plan is GitHub Actions auto-deploying to fly on merge to main.
