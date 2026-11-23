// Risks #1 and #2 from context/foundation/test-plan.md (ownership): another user may not edit/delete a book
// or remove/alter someone else's reading record or comment. Pattern copied from seed.spec.js.
import { test, expect } from '@playwright/test';

// ---------------------------------------------------------------------------------------------------------------
// Fixture facts (e2e/support/fixtures.js, UiCatalogueFixtures): the saved session is anna@example.com.
// Anna added 'Solaris'; ola@example.com added 'Cyberiada'. Ola read 'Solaris' with the comment below, Anna read it too.
// The data is the fixture set loaded by the `setup` project (not created per test), so there are no ids to make unique.
// ---------------------------------------------------------------------------------------------------------------
const OWN_BOOK = 'Solaris';
const FOREIGN_BOOK = 'Cyberiada';
const OLA_COMMENT = 'Świetna, choć trudna.';
const ANNA_COMMENT = 'Klasyka, którą warto czytać wolno.';

/** Ids change on every fixture load: find the book through the title filter and read the id from the detail URL. */
async function openBook(page, title) {
  await page.goto(`/books?title=${encodeURIComponent(title)}`);
  await page.getByRole('link', { name: title, exact: true }).click();
  await expect(page.getByRole('heading', { name: title, level: 1 })).toBeVisible();
  return Number(new URL(page.url()).pathname.split('/').pop());
}

/**
 * The delete form's CSRF token is a hidden input (no accessible role), so read it from the request the real
 * "Usuń" button sends. The request is aborted, so Anna's own book is never deleted.
 */
async function captureDeleteToken(page) {
  const deleteRoute = '**/books/*/delete';
  await page.route(deleteRoute, (route) => route.abort());
  const sent = page.waitForRequest((request) => request.method() === 'POST' && request.url().endsWith('/delete'));
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByRole('button', { name: 'Usuń', exact: true }).click();
  const token = new URLSearchParams((await sent).postData() ?? '').get('_token');
  await page.unroute(deleteRoute);
  expect(token).toBeTruthy();
  return token;
}

// Risk #1: a signed-in household member edits or deletes a book added by someone else.
test('signed-in user cannot edit or delete a book added by another user', async ({ page }) => {
  // Anna's own book yields a valid 'book_delete' token (the token is per session, not per book).
  await openBook(page, OWN_BOOK);
  const token = await captureDeleteToken(page);

  const foreignId = await openBook(page, FOREIGN_BOOK);

  // The UI hides the controls ...
  await expect(page.getByRole('link', { name: 'Edytuj', exact: true })).toBeHidden();
  await expect(page.getByRole('button', { name: 'Usuń', exact: true })).toBeHidden();

  // ... but the server must refuse the request itself.
  const editPage = await page.request.get(`/books/${foreignId}/edit`);
  expect(editPage.status()).toBe(403);
  const deleteAttempt = await page.request.post(`/books/${foreignId}/delete`, { form: { _token: token }, maxRedirects: 0 });
  expect(deleteAttempt.status()).toBe(403);

  // The book is untouched: still opens. Nothing was created, so there is nothing to clean up.
  await page.goto(`/books/${foreignId}`);
  await expect(page.getByRole('heading', { name: FOREIGN_BOOK, level: 1 })).toBeVisible();
});

// Risk #2: removing a reading record or comment must never touch another user's record or comment.
test.describe('reading record removal', () => {
  let ownBookId;

  // Cleanup runs even when the test fails halfway: restore Anna's record if it is missing, and assert it worked.
  test.afterEach(async ({ page }) => {
    await page.goto(`/books/${ownBookId}`);
    await expect(page.getByRole('heading', { name: OWN_BOOK, level: 1 })).toBeVisible();
    if ((await page.getByRole('heading', { name: 'Oznacz jako przeczytaną' }).count()) === 0) return;

    await page.getByRole('radio', { name: '5' }).check();
    await page.getByLabel('Data przeczytania').fill('2026-10-01');
    await page.getByLabel('Komentarz').fill(ANNA_COMMENT);
    await page.getByRole('button', { name: 'Zapisz jako przeczytaną' }).click();
    await expect(page.getByText('Książka została oznaczona jako przeczytana.')).toBeVisible();
    await expect(page.getByText(ANNA_COMMENT)).toBeVisible();
  });

  test("removing my own reading record leaves another user's comment untouched", async ({ page }) => {
    ownBookId = await openBook(page, OWN_BOOK);
    await expect(page.getByText(OLA_COMMENT)).toBeVisible();

    // Action: remove Anna's own record (the page asks for confirmation first).
    page.once('dialog', (dialog) => dialog.accept());
    await page.getByRole('button', { name: 'Usuń moje oznaczenie jako przeczytanej' }).click();
    await expect(page.getByText('Oznaczenie jako przeczytanej zostało usunięte.')).toBeVisible();

    // Anna's record is gone, Ola's comment is still there after a reload.
    await page.reload();
    await expect(page.getByRole('heading', { name: 'Oznacz jako przeczytaną' })).toBeVisible();
    await expect(page.getByText(OLA_COMMENT)).toBeVisible();
  });
});
