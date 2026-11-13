// @ts-check
import { test, expect } from '@playwright/test';
import { loadFixtures, prepareDatabase, READER } from './support/fixtures.js';

/*
 * Visual gate for the UI (change ui-books-list). Run with `npm run e2e`, at 1280 px (desktop) and 390 px (mobile).
 *
 * State matrix for /books:
 *   default, hover, focus-visible, disabled, empty, no-results are screenshotted below.
 *   error   N/A: BookSearch::fromQuery never fails, bad input means "no filter" (src/Dto/BookSearch.php).
 *   loading N/A: the page is rendered on the server, there is no asynchronous fetch.
 * The other views (/login, /books/{id}, /books/new, the error page) get a default shot and, where they have one,
 * a validation-error shot. Baselines are never updated just to turn a run green: explain the visual delta first.
 */

const shot = { fullPage: true };

async function signIn(page) {
  await page.goto('/login');
  await page.fill('#login-email', READER.email);
  await page.fill('#login-password', READER.password);
  await page.click('button[type=submit]');
  await expect(page).toHaveURL(/\/books$/);
}

/** Ids change on every fixture load, so find the book through the title filter (the list is newest first, Solaris is on page 2). */
async function openBook(page, title) {
  await page.goto(`/books?title=${encodeURIComponent(title)}`);
  await page.getByRole('link', { name: title, exact: true }).click();
  await expect(page.locator('h1')).toHaveText(title);
}

/** Submit through the server so the validation messages are Symfony's, not the browser's `required` bubbles. */
async function submitWithoutBrowserValidation(page) {
  await page.locator('form[method=post]:has(button[type=submit])').last().evaluate((f) => (f.noValidate = true));
  await page.locator('form button[type=submit]').last().click();
}

test.beforeAll(() => {
  prepareDatabase();
});

test.describe('catalogue', () => {
  test.beforeAll(() => loadFixtures('books-ui-catalogue'));

  test('list: default', async ({ page }) => {
    await page.goto('/books');
    await expect(page.getByRole('link', { name: 'Ferdydurke' })).toBeVisible();
    await expect(page).toHaveScreenshot('list-default.png', shot);
  });

  test('list: hover on a row', async ({ page }) => {
    await page.goto('/books');
    await page.locator('tbody tr').nth(2).hover();
    await expect(page).toHaveScreenshot('list-hover.png', shot);
  });

  test('list: focus-visible on the search input and a pager link', async ({ page }) => {
    await page.goto('/books');
    await page.locator('#search-title').focus();
    await page.keyboard.press('Shift+Tab');
    await page.keyboard.press('Tab');
    await expect(page).toHaveScreenshot('list-focus-input.png', shot);
    await page.locator('nav[aria-label] a.page-link.num').first().focus();
    await page.keyboard.press('Tab');
    await page.keyboard.press('Shift+Tab');
    await expect(page).toHaveScreenshot('list-focus-pager.png', shot);
  });

  test('list: disabled previous on page 1', async ({ page }) => {
    await page.goto('/books');
    await expect(page.locator('li.page-item.disabled').first()).toBeVisible();
    await expect(page.locator('nav[aria-label]').last()).toHaveScreenshot('list-disabled-pager.png');
  });

  test('list: second page', async ({ page }) => {
    await page.goto('/books?page=2');
    await expect(page).toHaveScreenshot('list-page-2.png', shot);
  });

  test('list: no results', async ({ page }) => {
    await page.goto('/books?title=zzzzzz');
    await expect(page).toHaveScreenshot('list-no-results.png', shot);
  });

  test('list: signed in with recommendations', async ({ page }) => {
    await signIn(page);
    await expect(page).toHaveScreenshot('list-signed-in.png', shot);
  });

  test('login: default', async ({ page }) => {
    await page.goto('/login');
    await expect(page).toHaveScreenshot('login-default.png', shot);
  });

  test('login: wrong password', async ({ page }) => {
    await page.goto('/login');
    await page.fill('#login-email', READER.email);
    await page.fill('#login-password', 'wrong-password');
    await page.click('button[type=submit]');
    await expect(page.locator('.alert-danger')).toBeVisible();
    await expect(page).toHaveScreenshot('login-error.png', shot);
  });

  test('detail: anonymous', async ({ page }) => {
    await openBook(page, 'Solaris');
    await expect(page.locator('h1')).toHaveText('Solaris');
    await expect(page).toHaveScreenshot('detail-anonymous.png', shot);
  });

  test('detail: signed in, unread, mark-read form', async ({ page }) => {
    await signIn(page);
    await openBook(page, 'Cyberiada');
    await expect(page.locator('form input[type=radio]').first()).toBeVisible();
    await expect(page).toHaveScreenshot('detail-unread.png', { ...shot, mask: [page.locator('input[type=date]')] });
  });

  test('detail: signed in, unread, validation error', async ({ page }) => {
    await signIn(page);
    await openBook(page, 'Cyberiada');
    await page.locator('input[type=date]').fill('');
    await submitWithoutBrowserValidation(page);
    await expect(page.locator('.invalid-feedback, .form-error-message').first()).toBeVisible();
    await expect(page).toHaveScreenshot('detail-unread-error.png', { ...shot, mask: [page.locator('input[type=date]')] });
  });

  test('detail: signed in, read, with comments', async ({ page }) => {
    await signIn(page);
    await openBook(page, 'Solaris');
    await expect(page.locator('#comments article').first()).toBeVisible();
    await expect(page).toHaveScreenshot('detail-read.png', shot);
  });

  test('new book: default', async ({ page }) => {
    await signIn(page);
    await page.goto('/books/new');
    await expect(page.locator('form')).toBeVisible();
    await expect(page).toHaveScreenshot('new-default.png', shot);
  });

  test('new book: validation errors', async ({ page }) => {
    await signIn(page);
    await page.goto('/books/new');
    await submitWithoutBrowserValidation(page);
    await expect(page.locator('.invalid-feedback, .form-error-message').first()).toBeVisible();
    await expect(page).toHaveScreenshot('new-error.png', shot);
  });

  test('error page: 404', async ({ page }) => {
    await page.goto('/_error/404');
    await expect(page.locator('h1')).toBeVisible();
    await expect(page).toHaveScreenshot('error-404.png', shot);
  });
});

test.describe('empty library', () => {
  test.beforeAll(() => loadFixtures('books-ui-empty'));

  test('list: empty', async ({ page }) => {
    await page.goto('/books');
    await expect(page).toHaveScreenshot('list-empty.png', shot);
  });
});
