---
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
---

## Why this stack

Solo PHP developer building a small household web app in a five-week, after-hours MVP, with login as the only technology-forcing feature. The registry's recommended PHP default is Laravel, but the user chose Symfony 8.1 with Twig templates for the front end. Symfony has no vetted card and was assessed directly: convention-based, well documented and well represented in PHP training data, with typing depending on strict types and typed properties, which the user will enforce. Symfony 8.x requires a newer PHP (8.4+). Symfony's security component covers login, and a reset-password extension fits the nice-to-have password reset. Scaffolding is best-effort: bootstrapper will use the official Symfony generator and confirm the command first. Deployment is fly (a plain PHP container), with GitHub Actions auto-deploying on merge to main.
