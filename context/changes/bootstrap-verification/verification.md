---
bootstrapped_at: 2026-10-20T20:40:00Z
starter_id: custom
starter_name: Symfony
project_name: my-home-library
language_family: php
package_manager: composer
cwd_strategy: subdir-then-move
bootstrapper_confidence: best-effort
phase_3_status: ok
audit_command: "null"
---

## Hand-off

```yaml
starter_id: custom
custom_starter:
  name: Symfony
  docs_url: https://symfony.com/doc/current/index.html
package_manager: composer
project_name: my-home-library
hints:
  language_family: php
  team_size: solo
  deployment_target: fly
  ci_provider: github-actions
  ci_default_flow: auto-deploy-on-merge
  bootstrapper_confidence: best-effort
  path_taken: custom
  quality_override: false
  self_check_answers:
    typed: true
    from_official_starter: true
    conventions: true
    docs_current: true
    can_judge_agent: true
  has_auth: true
  has_payments: false
  has_realtime: false
  has_ai: false
  has_background_jobs: false
```

Solo PHP developer building a small household web app in a five-week, after-hours MVP, with login as the only technology-forcing feature. The registry's recommended PHP default is Laravel, but the user chose Symfony 8.1 with Twig templates for the front end. Symfony has no vetted card and was assessed directly: convention-based, well documented and well represented in PHP training data, with typing depending on strict types and typed properties, which the user will enforce. Symfony 8.x requires a newer PHP (8.4+). Symfony's security component covers login, and a reset-password extension fits the nice-to-have password reset. Scaffolding is best-effort: bootstrapper will use the official Symfony generator and confirm the command first. Deployment is fly (a plain PHP container), with GitHub Actions auto-deploying on merge to main.

## Pre-scaffold verification

| Signal      | Value   | Severity | Notes                                                |
| ----------- | ------- | -------- | ---------------------------------------------------- |
| npm package | not run | n/a      | PHP stack, no npm create-* generator                 |
| GitHub repo | not run | n/a      | docs_url is symfony.com, no recency signal available |

Environment: PHP 8.5.11 from `C:\php85` (added to PATH for the session only; not on the default PATH). Packagist lists symfony/framework-bundle v8.1.9. Heads-up: Symfony 8.1 is not an LTS release; `bin/console about` reports end of maintenance 01/2027, close to the hard deadline of 2027-01-10.

## Scaffold log

**Resolved invocation**: `composer create-project symfony/skeleton:"8.1.*" .bootstrap-scaffold --no-interaction` then, inside `.bootstrap-scaffold`, `composer require webapp --no-interaction`
**Strategy**: subdir-then-move
**Exit code**: 0 (both commands)
**Files moved**: 23 top-level entries (bin, config, public, src, var, vendor, assets, migrations, templates, tests, translations, .editorconfig, .env, .env.dev, .env.test, AGENTS.md, compose.yaml, compose.override.yaml, composer.json, composer.lock, importmap.php, phpunit.dist.xml, symfony.lock)
**Conflicts (.scaffold siblings)**: none
**.gitignore handling**: append-merged (existing file already contained every scaffold rule, so nothing was appended)
**.bootstrap-scaffold cleanup**: deleted

Notes:
- `AGENTS.md` came from the framework-bundle recipe (generic Symfony guidance). It is not a project-specific onboarding file; regenerate with `/10x-agents-md`.
- `bin/console about` boots: Symfony v8.1.9, PHP 8.5.11, env dev.
- Earlier attempts in this session failed (missing framework-bundle recipe: HTTP 404 while downloading `symfony.framework-bundle.7.2.json`, then a hanging source-clone fallback). Cause: an outdated GitHub token in the global Composer `auth.json` (`Could not authenticate against github.com`). The user had it removed (`github-oauth.github.com` unset globally); the rerun succeeded with complete recipes (`src/Kernel.php`, `public/index.php`, `config/services.yaml`).
- Composer under PHP 8.5 prints deprecation notices (`curl_close`); harmless.

## Post-scaffold audit

**Tool**: skipped — no built-in audit tool for php
**Recommended external tool**: `composer audit` (built into Composer 2.4+), run manually in the project root. Composer printed "No security vulnerability advisories found" during both install steps.

## Hints recorded but not acted on

| Hint                    | Value                |
| ----------------------- | -------------------- |
| bootstrapper_confidence | best-effort          |
| quality_override        | false                |
| path_taken              | custom               |
| self_check_answers      | all true             |
| team_size               | solo                 |
| deployment_target       | fly                  |
| ci_provider             | github-actions       |
| ci_default_flow         | auto-deploy-on-merge |
| has_auth                | true                 |
| has_payments            | false                |
| has_realtime            | false                |
| has_ai                  | false                |
| has_background_jobs     | false                |

## Next steps

Next: a future skill will set up agent context (CLAUDE.md, AGENTS.md). For now, your project is scaffolded and verified — happy hacking.

Useful manual steps in the meantime:
- `git init` (if you have not already) to start your own repo history.
- Review any `.scaffold` siblings the conflict policy created and decide which version of each file to keep (none this run).
- Address audit findings per your project's risk tolerance — the full breakdown is in this log.
