import { test as setup, expect } from '@playwright/test';
import { loadFixtures, prepareDatabase, READER } from '../../e2e/support/fixtures.js';

const authFile = 'playwright/.auth/user.json';

setup('sign in once and save the session', async ({ page }) => {
  // Local-only database (app_teste2e, pinned in fixtures.js): the fixture load purges it, so the reader exists afterwards.
  prepareDatabase();
  loadFixtures('books-ui-catalogue');

  await page.goto('/login');
  await page.getByLabel('Adres e-mail').fill(READER.email);
  await page.getByLabel('Hasło', { exact: true }).fill(READER.password);
  await page.getByRole('button', { name: 'Zaloguj' }).click();

  // Only a signed-in user sees the logout link; the form is server-rendered, so there is no hydration race.
  await expect(page.getByRole('link', { name: 'Wyloguj' })).toBeVisible();

  await page.context().storageState({ path: authFile });
});
