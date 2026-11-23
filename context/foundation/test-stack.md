# Test stack

## E2E

<!-- Written by /10x-e2e-setup. Re-run it to change this section; other skills only read it. -->

- runner: Playwright Test, @playwright/test 1.65.0
- config: playwright.config.js
- single-spec command: npx playwright test tests/e2e/<name>.spec.js --project=chromium
- full-suite command: npx playwright test
- base URL: http://127.0.0.1:8081
- port: 8081 (detected from playwright.config.js; detected default 8081, override with E2E_PORT)
- web server command: php -d variables_order=EGPCS -S 127.0.0.1:8081 -t public public/index.php (APP_ENV=test, TEST_TOKEN=e2e; no build step, PHP built-in server); reuseExistingServer false (existing choice, not outside-CI only)
- auth setup project: setup (tests/e2e/auth.setup.js), signs in as the READER fixture user from e2e/support/fixtures.js after loading fixtures into the local app_teste2e database — no E2E_USERNAME / E2E_PASSWORD
- storageState: playwright/.auth/user.json (gitignored)
- seed: tests/e2e/seed.spec.js — protects auth gate: signed-out visitor to /books/new lands on /login
- browser CLI: playwright-cli, command skill at .claude/skills/playwright-cli/SKILL.md
- updated: 2026-11-23
