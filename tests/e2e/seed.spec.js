// Seed for /10x-e2e. Risk: anonymous access to a protected page (test-plan §2 ownership/privacy, auth gate).
// Origin: browser-level auth-gate round trip; /books/new must send a signed-out visitor to /login.
import { test, expect } from '@playwright/test';

test.describe("signed out", () => {
  // This risk IS the signed-out path, so opt out of the saved session.
  test.use({ storageState: { cookies: [], origins: [] } });

  test('anonymous visitor opening /books/new is sent to the login form', async ({ page }) => {
    // No data is created, so there is nothing to clean up. Do not sign the shared user out: it would revoke the saved session.
    await page.goto('/books/new');

    await page.waitForURL(/\/login$/);
    await expect(page.getByRole('heading', { name: 'Zaloguj się' })).toBeVisible();
    await expect(page.getByLabel('Adres e-mail')).toBeVisible();
    await expect(page.getByRole('link', { name: 'Wyloguj' })).toBeHidden();
  });
});
