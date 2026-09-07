import { expect, test } from '@playwright/test';

test.describe('application shell', () => {
  test('renders the home page', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveTitle(/Orbito/);
    await expect(page.getByRole('heading', { name: 'Orbito LMS' })).toBeVisible();
  });

  test('navigates to system status', async ({ page }) => {
    await page.goto('/system');

    await expect(page.getByRole('heading', { name: 'System status' })).toBeVisible();
  });

  test('switches between light and dark mode', async ({ page }) => {
    await page.goto('/');

    const html = page.locator('html');
    await page
      .getByRole('button', { name: /colour scheme/i })
      .first()
      .click();
    await page.getByRole('menuitem', { name: 'Dark' }).click();
    await expect(html).toHaveAttribute('data-mantine-color-scheme', 'dark');

    await page
      .getByRole('button', { name: /colour scheme/i })
      .first()
      .click();
    await page.getByRole('menuitem', { name: 'Light' }).click();
    await expect(html).toHaveAttribute('data-mantine-color-scheme', 'light');
  });

  test('shows a not-found state for an unknown route', async ({ page }) => {
    await page.goto('/definitely-not-a-page');

    await expect(page.getByText(/page not found|does not exist/i).first()).toBeVisible();
  });
});
