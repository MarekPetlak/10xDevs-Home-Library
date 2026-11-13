// @ts-check
import { test, expect } from '@playwright/test';
import { loadFixtures, prepareDatabase } from './support/fixtures.js';

/*
 * Visual gate for /books (change ui-books-list). Phase 1 only provides the runner and the data;
 * the screenshot matrix is added in phase 4.
 *
 * State matrix for /books: error and loading are N/A.
 *  - error: BookSearch::fromQuery never fails, bad input means "no filter" (src/Dto/BookSearch.php).
 *  - loading: the page is rendered on the server, there is no asynchronous fetch.
 */

test.beforeAll(() => {
  prepareDatabase();
});

test('catalogue fixtures render the book list', async ({ page }) => {
  loadFixtures('books-ui-catalogue');
  await page.goto('/books');
  await expect(page.getByRole('link', { name: 'Solaris' })).toBeVisible();
});
