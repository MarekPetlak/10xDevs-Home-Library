import { execFileSync } from 'node:child_process';

/**
 * Loads one UI fixture group into the e2e database.
 *
 * `doctrine:fixtures:load` PURGES the target database, so the environment is pinned here and
 * never inherited: APP_ENV=test plus TEST_TOKEN=e2e selects `app_teste2e` (config/packages/doctrine.yaml,
 * dbname_suffix). It cannot reach the development database `app` or PHPUnit's `app_test`.
 */
const E2E_ENV = { ...process.env, APP_ENV: 'test', TEST_TOKEN: 'e2e' };

function console_(args) {
  return execFileSync('php', ['bin/console', ...args, '--no-interaction'], { env: E2E_ENV, stdio: 'pipe' }).toString();
}

export function prepareDatabase() {
  console_(['doctrine:database:create', '--if-not-exists']);
  console_(['doctrine:migrations:migrate']);
}

/** @param {'books-ui-catalogue' | 'books-ui-empty'} group */
export function loadFixtures(group) {
  console_(['doctrine:fixtures:load', `--group=${group}`]);
}

export const READER = { email: 'anna@example.com', password: 'secret-pass' };
